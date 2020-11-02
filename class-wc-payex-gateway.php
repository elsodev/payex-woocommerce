<?php
/**
 * Payex Gateway
 *
 * @package     payex_woocommerce_gateway
 *
 * @wordpress-plugin
 * Plugin Name:       Payex Payment Gateway for Woocommerce
 * Plugin URI:        https://payex.io
 * Description:       Accept FPX and Card payments using Payex
 * Version:           1.0.5
 * Requires at least: 4.7
 * Requires PHP:      7.0
 * Author:            Nedex Solutions
 * Author URI:        https://nedex.io
 * License:           The MIT License (MIT)
 * License URI:       https://opensource.org/licenses/MIT
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const PAYEX_AUTH_CODE_SUCCESS   = '00';
const PAYEX_AUTH_CODE_PENDING   = '09';
const PAYEX_AUTH_CODE_PENDING_2 = '99';

// Registers payment gateway.
add_filter( 'woocommerce_payment_gateways', 'payex_add_gateway_class' );
/**
 * Add Payex Gateway
 *
 * @param  string $gateways Add Gateway.
 * @return mixed
 */
function payex_add_gateway_class( $gateways ) {
	 $gateways[] = 'WC_Payex_Gateway';
	return $gateways;
}
// add plugin load for init payex gateway.
add_action( 'plugins_loaded', 'payex_init_gateway_class' );
/**
 * Payex Init gateway function
 */
function payex_init_gateway_class() {
	/**
	 * Class WC_PAYEX_GATEWAY
	 */
	class WC_PAYEX_GATEWAY extends WC_Payment_Gateway {

		const API_URL            = 'https://api.payex.io/';
		const API_URL_SANDBOX 	 = 'https://sandbox-payexapi.azurewebsites.net/';
		const API_GET_TOKEN_PATH = 'api/Auth/Token';
		const API_PAYMENT_FORM   = 'Payment/Form';
		const HOOK_NAME          = 'payex_hook';

		/**
		 * Class constructor
		 */
		public function __construct() {

			$this->id                 = 'payex'; // payment gateway plugin ID.
			$this->icon               = 'https://payexpublic.blob.core.windows.net/storage/payex_woocommerce.jpg'; // URL of the icon that will be displayed on checkout page near your gateway name.
			$this->has_fields         = true; // in case you need a custom credit card form.
			$this->method_title       = 'Payex Gateway';
			$this->method_description = 'Accept FPX and Card payments using Payex Payment Gateway (payex.io)'; // will be displayed on the options page.
            		$this->order_button_text  = 'Pay with Payex';

			$this->supports = array(
				'products',
			);

			// Method with all the options fields.
			$this->init_form_fields();

			// Load the settings.
			$this->init_settings();
			$this->title = $this->get_option( 'title' );
			$this->description = $this->get_option( 'description' );
			$this->enabled = $this->get_option( 'enabled' );
			$this->testmode = 'yes' === $this->get_option( 'testmode' );

			// This action hook saves the settings.
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action( 'woocommerce_api_wc_payex_gateway', array( &$this, 'webhook' ) );
		}

		/**
		 * Plugin options, we deal with it in Step 3 too
		 */
		public function init_form_fields() {

			$this->form_fields = array(
				'enabled'     => array(
					'title'       => 'Enable/Disable',
					'label'       => 'Enable Payex Payment Gateway',
					'type'        => 'checkbox',
					'description' => '',
					'default'     => 'no',
				),
				'title'       => array(
					'title'       => 'Title',
					'type'        => 'text',
					'description' => 'This controls the title which the user sees during checkout.',
					'default'     => 'Payex',
				),
				'description' => array(
					'title'       => 'Description',
					'type'        => 'textarea',
					'description' => 'This controls the description which the user sees during checkout.',
					'default'     => 'Pay via Payex using FPX, Visa or Mastercard.',
				),
				'testmode'     => array(
					'title'       => 'Sandbox environment',
					'label'       => 'Enable sandbox environment',
					'type'        => 'checkbox',
					'description' => 'Test our payment gateway in the sandbox environment using the sandbox Secret and the same email address.',
					'default'     => 'no',
					'desc_tip'    => true,
				),
				'email'       => array(
					'title'       => 'Payex Email Address',
					'type'        => 'text',
					'description' => 'This email where by you used to sign up and login to Payex Portal',
					'default'     => null,
				),
				'secret_key'  => array(
					'title'       => 'Payex Live Secret Key',
					'type'        => 'password',
					'description' => 'This key should be used when you are ready to go live. Obtain the secret key from Payex.',
				),
			);
		}

		/**
		 * Custom checkout fields
		 */
		public function payment_fields() {
		}

		/**
		 * Custom CSS and JS
		 */
		public function payment_scripts() {
		}

		/**
		 * Fields validation for payment_fields()
		 */
		public function validate_fields() {
			return true;
		}

		/**
		 * Process Payment & generate Payex form link
		 *
		 * @param  string $order_id Woocommerce order id.
		 * @return null|array
		 */
		public function process_payment( $order_id ) {
			global $woocommerce;

			// we need it to get any order details.
			$order      = wc_get_order( $order_id );
			$order_data = $order->get_data();
			$token      = $this->get_payex_token();
			$url = self::API_URL;
				
			if ($this->get_option( 'testmode' ) === 'yes') {
				$url = self::API_URL_SANDBOX;
			}
			
			if ( $token ) {
				// generate payex payment link.
				$payment_link = $this->get_payex_payment_link(
					$url,
					$order_data['id'],
					$order_data['total'],
					'Payment for Order Reference:' . $order_data['order_key'],
					$order_data['billing']['first_name'] . ' ' . $order_data['billing']['last_name'],
					$order_data['billing']['phone'],
					$order_data['billing']['email'],
					$order_data['billing']['address_1'] . ',' . $order_data['billing']['address_2'] . ', ' . $order_data['billing']['city'],
					$order_data['billing']['postcode'],
					$order_data['billing']['state'],
					$order_data['billing']['country'],
					$this->get_return_url( $order ),
					get_site_url( null, '/wc-api/' . self::HOOK_NAME ),
					$token
				);

				// Redirect to checkout page on Payex.
				return array(
					'result'   => 'success',
					'redirect' => $payment_link,
				);

			} else {
				wc_add_notice( 'Payment gateway is temporary down, we are checking on it, please try again later.', 'error' );
				return;
			}
			// get token.
		}

		/**
		 * Webhook
		 */
		public function webhook() {
			$verified = $this->verify_payex_response( $_POST ); // phpcs:ignore

			if ( $verified && isset( $_POST['reference_number'] ) && isset( $_POST['auth_code'] ) ) {  // phpcs:ignore
				$order     = wc_get_order( sanitize_text_field( wp_unslash( $_POST['reference_number'] ) ) );  // phpcs:ignore
				$auth_code = sanitize_text_field( wp_unslash( $_POST['auth_code'] ) );  // phpcs:ignore
				// verify the payment is successful.
				if ( PAYEX_AUTH_CODE_SUCCESS == $auth_code ) {
					if ( ! $order->is_paid() ) { // only mark order as completed if the order was not paid before.
						$order->payment_complete();
						$order->reduce_order_stock();
						$order->add_order_note( 'Payment completed with Payex', true );
					} else {
						$order->add_order_note( 'Payment already made for this order with Auth Code:' . $auth_code, true );
					}
				} else {
					$order->add_order_note( 'Payex Payment failed with Auth Code:' . $auth_code, true );
				}
			}
		}

		/**
		 * Generate Payment form link to allow users to Pay
		 *
		 * @param  string      $ref_no          Transaction record ref no.
		 * @param  float       $amount          Float amount.
		 * @param  string      $description     Describe this payment.
		 * @param  string      $cust_name       Name of Customer.
		 * @param  string      $cust_contact_no Contact Number of Customer.
		 * @param  string      $email           Email Address of Customer.
		 * @param  string      $address         Physical Address of Customer.
		 * @param  string      $postcode        Postcode of Customer Address.
		 * @param  string      $state           State of Customer Address.
		 * @param  string      $country         Country code of Customer Address.
		 * @param  string      $return_url      Return URL when customer completed payment.
		 * @param  string      $callback_url    Return URL when customer completed payment.
		 * @param  string|null $token           Payex token.
		 * @return string
		 */
		private function get_payex_payment_link( $url, $ref_no, $amount, $description, $cust_name, $cust_contact_no, $email, $address, $postcode, $state, $country, $return_url, $callback_url, $token = null ) {
			if ( ! $token ) {
				$token = $this->getToken()['token'];
			}

			if ( $token ) {
				$link = $url . self::API_PAYMENT_FORM
				. '?token=' . $token
				. '&amount=' . $amount
				. '&description=' . rawurlencode( $description )
				. '&customer_name=' . rawurlencode( $cust_name )
				. '&contact_number=' . $cust_contact_no
				. '&address=' . rawurlencode( $address )
				. '&postcode=' . $postcode
				. '&state=' . rawurlencode( $state )
				. '&country=' . $country
				. '&email=' . $email
				. '&reference_number=' . $ref_no
				. '&source=wordpress'
				. '&return_url=' . $return_url
				. '&callback_url=' . WC()->api_request_url( get_class( $this ) );

				return $link;
			}

			return false;
		}

		/**
		 * Get Payex Token
		 *
		 * @return bool|mixed
		 */
		private function get_payex_token() {
			$email  = $this->get_option( 'email' );
			$secret = $this->get_option( 'secret_key' );

			$request = wp_remote_post(
				self::API_URL . self::API_GET_TOKEN_PATH,
				array(
					'method'  => 'POST',
					'timeout' => 45,
					'headers' => array(
						'Content-Type'  => 'application/json',
						'Authorization' => 'Basic ' . base64_encode( $email . ':' . $secret ),
					),
					'cookies' => array(),
				)
			);

			if ( is_wp_error( $request ) || 200 !== wp_remote_retrieve_response_code( $request ) ) {
				error_log( print_r( $request, true ) );
			} else {
				$response = wp_remote_retrieve_body( $request );
				$response = json_decode( $response, true );
				return $response['token'];
			}
			return false;
		}

		/**
		 * Verify Response
		 *
		 * Used to verify response data integrity
		 * Signature: implode all returned data pipe separated then hash with sha512
		 *
		 * @param  array $response  Payex response after checkout.
		 * @return bool
		 */
		public function verify_payex_response( $response ) {
			if ( isset( $response['signature'] ) && isset( $response['txn_id'] ) ) {
				ksort( $response ); // sort array keys ascending order.
				$host_signature = sanitize_text_field( wp_unslash( $response['signature'] ) );
				$signature      = $this->get_option( 'secret_key' ) . '|' . sanitize_text_field( wp_unslash( $response['txn_id'] ) ); // append secret key infront.

				$hash = hash( 'sha512', $signature );

				if ( $hash == $host_signature ) {
					return true;
				}
			}
			return false;
		}
	}
}
