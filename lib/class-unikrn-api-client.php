<?php

class Unikrn_API_Client {

	protected $api_url;
	protected $system_name;
	protected $inbound_secret;
	protected $outbound_secret;
	protected $debug;

	public function __construct( $api_base_url, $system_name, $inbound_secret, $outbound_secret, $debug ) {
		$this->api_url         = rtrim( $api_base_url, '/' ) . '/';
		$this->system_name     = $system_name;
		$this->inbound_secret  = $inbound_secret;
		$this->outbound_secret = $outbound_secret;
		$this->debug           = $debug;
	}

	public function convert_from_fiat( $cents, $currency ) {
		$result = $this->request( 'convert', array( 'amount' => $cents, 'currency' => $currency ), false );

		if ( $result['success'] ) {
			return $result['data']['ukg'];
		}

		return false;
	}

	private function request( $path, $params, $sign = true ) {
		$time = time();
		$url  = $this->api_url . $path;
		$data = json_encode( $params );
		if ( $sign ) {

			$secret    = $this->outbound_secret . $time;
			$sign      = strtoupper( hash_hmac( 'sha256', $data, $secret, false ) );
			$send_data = array(
				'system' => $this->system_name,
				'time'   => $time,
				'data'   => $data,
				'sign'   => $sign
			);
			$data_str  = json_encode( $send_data );

		} else {
			$data_str = $data;
		}

		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "POST" );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $data_str );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array( 'Content-Type: application/json' ) );
		$result      = curl_exec( $ch );
		$result_data = json_decode( $result, true );

		return $result_data;
	}

	public function start( $order_id, $cents, $currency, $postback_url, $success_url, $error_url ) {
		$result = $this->request(
			'start',
			array(
				'postback_url' => $postback_url,
				'success_url'  => $success_url,
				'error_url'    => $error_url,
				'amount'       => $cents,
				'currency'     => $currency,
				'order_id'     => $order_id
			)
		);

		if ( $result['success'] ) {
			return $result['redirect'];
		} else {
			if ($this->debug)
				wc_add_notice( 'DEBUG: '.$result['msg_trans'], 'error' );
		}

		return false;
	}

	public function check_request( $data ) {
		$time         = $_SERVER['HTTP_X_UNIKRN_SIG_V1_TIME'];
		$secret       = $this->inbound_secret . $time;
		$sign         = strtoupper( hash_hmac( 'sha256', $data, $secret, false ) );
		$current_time = time();

		return $time > $current_time - 60 && $time <= $current_time && $sign == $_SERVER['HTTP_X_UNIKRN_SIG_V1'];
	}

}
