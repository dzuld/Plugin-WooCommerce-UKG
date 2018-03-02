<?php

require_once( 'class-unikrn-api-client.php' );

function init_unikrn_payment_class() {

	class WC_Gateway_Unikrn extends WC_Payment_Gateway {

		protected $system_name;
		protected $inbound_secret;
		protected $outbound_secret;
		protected $api_base_url;
		protected $currency;
		/** @var Unikrn_API_Client */
		protected $unikrn_api_client;
		protected $debug;

		public function __construct() {

			$this->id                 = 'unikrn_gateway';
			$this->icon               = plugin_dir_url( dirname( __FILE__ ) ) . 'assets/img/unikoin-icon-gold.svg';
			$this->has_fields         = false;
			$this->method_title       = 'UKG Payments';
			$this->method_description = 'Payment method to access UnikoinGold (UKG)';

			$this->init_form_fields();
			$this->init_settings();

			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
				$this,
				'process_admin_options'
			) );
			add_action( 'woocommerce_api_wc_gateway_unikrn', array( $this, 'check_unikrn_response' ) );

			$this->enabled         = $this->get_option( 'enabled' );
			$this->title           = $this->get_option( 'title' );
			$this->description     = $this->get_option( 'description' );
			$this->system_name     = $this->get_option( 'system_name' );
			$this->inbound_secret  = $this->get_option( 'inbound_secret' );
			$this->outbound_secret = $this->get_option( 'outbound_secret' );
			$this->api_base_url    = $this->get_option( 'api_base_url' );
			$this->currency        = $this->get_option( 'currency' );
			$this->debug           = $this->get_option( 'debug' );

			if ( $this->api_base_url && $this->system_name && $this->inbound_secret && $this->outbound_secret ) {

				$this->unikrn_api_client = new Unikrn_API_Client(
					$this->api_base_url,
					$this->system_name,
					$this->inbound_secret,
					$this->outbound_secret,
					$this->debug
				);

				$this->method_description .= ' - Current Exchange Rate is 1 ' . $this->currency . ' = ' . $this->unikrn_api_client->convert_from_fiat( 100, $this->currency ) . ' UKG';
			} else {
				$this->method_description .= ' - <a href="https://github.com/unikoingold/Plugin-WooCommerce-UKG/blob/master/README.md#troubleshoot">PLEASE COMPLETE THE SETUP</a>';
			}
		}

		public function init_form_fields() {
			$this->form_fields = array(
				'enabled'         => array(
					'title'   => __( 'Enable/Disable', 'woocommerce' ),
					'type'    => 'checkbox',
					'label'   => 'Enable Unikrn Wallet Payments',
					'default' => 'yes'
				),
				'title'           => array(
					'title'       => __( 'Title', 'woocommerce' ),
					'type'        => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'unikrn_payment' ),
					'default'     => 'Pay With UnikoinGold (UKG)',
					'desc_tip'    => true,
				),
				'description'     => array(
					'title'   => __( 'Customer Message', 'woocommerce' ),
					'type'    => 'textarea',
					'default' => ''
				),
				'currency'        => array(
					'title'   => __( 'Currency', 'woocommerce' ),
					'type'    => 'text',
					'default' => 'USD'
				),
				'api_base_url'    => array(
					'title'       => 'API BASE URL',
					'type'        => 'text',
					'description' => __( 'Request from support@unikoingold.com. This manages which API Endpoint to use. You get this from the UnikoinGold support. There is a stage and a production API', 'unikrn_payment' ),
					'desc_tip'    => true
				),
				'system_name'     => array(
					'title'   => 'System Name',
					'type'    => 'text',
					'default' => ''
				),
				'inbound_secret'  => array(
					'title'   => 'API Inbound Secret/Key',
					'type'    => 'password',
					'default' => ''
				),
				'outbound_secret' => array(
					'title'   => 'API Outbound Secret/Key',
					'type'    => 'password',
					'default' => ''
				),
				'debug'         => array(
					'title'   => __( 'Debug', 'woocommerce' ),
					'type'    => 'checkbox',
					'label'   => 'Enable DEBUGGING of Unikrn Wallet Payments - show errors',
					'default' => 'no'
				),
			);
		}

		public function get_title() {
			if ( is_checkout() ) {
				return apply_filters( 'woocommerce_gateway_title', $this->title . '<br>Total: ' . $this->unikrn_api_client->convert_from_fiat( $this->get_order_total() * 100, $this->currency ) . ' UKG', $this->id );
			}
			return apply_filters( 'woocommerce_gateway_title', $this->title, $this->id );
		}

		/**
		 * Return the gateway's icon.
		 *
		 * @return string
		 */
		public function get_icon() {
			$icon = $this->icon ? '<img src="' . WC_HTTPS::force_https_url( $this->icon ) . '" style="width: 30px;" alt="' . esc_attr( $this->get_title() ) . '" />' : '';
			return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
		}

		/**
		 * Process the payment and return the result.
		 *
		 * @param  int $order_id
		 *
		 * @return array
		 */
		public function process_payment( $order_id ) {
			$order        = wc_get_order( $order_id );
			$postback_url = home_url( '/wc-api/WC_Gateway_Unikrn/' );
			$redirect_url = $this->unikrn_api_client->start(
				$order_id,
				$this->get_order_total() * 100,
				$this->currency,
				$postback_url,
				$this->get_return_url( $order ),
				$this->get_return_url( $order )
			);
			if ( $redirect_url ) {
				WC()->cart->empty_cart();
				return array(
					'result'   => 'success',
					'redirect' => $redirect_url
				);
			}
			wc_add_notice( __( 'Payment error: ', 'unikrn_payment' ) . 'Please try again later', 'error' );
			return array(
				'result'   => 'failure',
				'redirect' => ''
			);
		}

		public function check_unikrn_response() {
			$data = file_get_contents( 'php://input' );
			if ( ! $this->unikrn_api_client->check_request( $data ) ) {
				http_response_code( 500 );
				echo "Invalid Signature";
				exit();
			}

			$req_data = json_decode( $data, true );
			$order_id = $req_data['order_id'];
			$order    = wc_get_order( $order_id );

			if ( $req_data['state'] == 4000 ) {
				$order->payment_complete( $req_data['uuid'] );
				$order->update_meta_data( 'ukg_amount', $req_data['amount'] );
				$order->save();
			} else if ( $req_data['state'] == 3000 ) {
				$order->set_status( 'on-hold' );
				$order->save();
			} else if ( $req_data['state'] == 100 ) {
				$order->set_status( 'cancelled' );
				$order->save();
			} else if ( $req_data['state'] == 1000
			            || $req_data['state'] == 1500
			            || $req_data['state'] == 2000
			            || $req_data['state'] == 2001
			            || $req_data['state'] == 2210
			            || $req_data['state'] == 2205
			            || $req_data['state'] == 3100
			            || $req_data['state'] == 4100
			            || $req_data['state'] == 5005
			) {
				$order->set_status( 'failed' );
				$order->save();
			}
			http_response_code( 200 );
			exit();
		}
	}
}
add_action( 'plugins_loaded', 'init_unikrn_payment_class' );

function add_unikrn_payment_class( $methods ) {
	$methods[] = 'WC_Gateway_Unikrn';
	return $methods;
}
add_filter( 'woocommerce_payment_gateways', 'add_unikrn_payment_class' );
