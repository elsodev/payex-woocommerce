<?php
/*
 * Plugin Name: Payex Payment Gateway for Woocommerce
 * Plugin URI: https://payex.io
 * Description: Accept FPX and Card payments using Payex
 * Author: Nedex Solutions
 * Author URI: https://nedex.io
 * Version: 1.0.0
 */

/*
 * Registers payment gateway
 */
add_filter( 'woocommerce_payment_gateways', 'payex_add_gateway_class' );
function payex_add_gateway_class( $gateways ) {
    $gateways[] = 'WC_Payex_Gateway';
    return $gateways;
}

add_action( 'plugins_loaded', 'payex_init_gateway_class' );
function payex_init_gateway_class() {

    class WC_PAYEX_GATEWAY extends WC_Payment_Gateway {

        const API_URL = 'https://api.payex.io/';
        const API_GET_TOKEN_PATH = 'api/Auth/Token';
        const API_PAYMENT_FORM = 'Payment/Details';
        const HOOK_NAME = 'payex_hook';

        /**
         * Class constructor
         */
        public function __construct() {

            $this->id = 'payex'; // payment gateway plugin ID
            $this->icon = ''; // URL of the icon that will be displayed on checkout page near your gateway name
            $this->has_fields = true; // in case you need a custom credit card form
            $this->method_title = 'Payex Gateway';
            $this->method_description = 'Accept FPX and Card payments using Payex Payment Gateway (payex.io)'; // will be displayed on the options page

            // gateways can support subscriptions, refunds, saved payment methods,
            // but in this tutorial we begin with simple payments
            $this->supports = array(
                'products'
            );

            // Method with all the options fields
            $this->init_form_fields();

            // Load the settings.
            $this->init_settings();
            $this->enabled = $this->get_option( 'enabled' );

            // This action hook saves the settings
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

            add_action( 'woocommerce_api_'.self::HOOK_NAME, array( $this, 'webhook' ) );
        }

        /**
         * Plugin options, we deal with it in Step 3 too
         */
        public function init_form_fields() {

            $this->form_fields = array(
                'enabled' => array(
                    'title'       => 'Enable/Disable',
                    'label'       => 'Enable Payex Payment Gateway',
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'no'
                ),
                'title' => array(
                    'title'       => 'Title',
                    'type'        => 'text',
                    'description' => 'This controls the title which the user sees during checkout.',
                    'default'     => 'Payment Checkout',
                ),
                'description' => array(
                    'title'       => 'Description',
                    'type'        => 'textarea',
                    'description' => 'This controls the description which the user sees during checkout.',
                    'default'     => 'Pay with Payex using FPX or Malaysian Credit Cards.',
                ),
                'email' => array(
                    'title'       => 'Payex Email Address',
                    'type'        => 'text',
                    'description' => 'This email where by you used to sign up and login to Payex Portal',
                    'default'     => null,
                ),
                'secret_key' => array(
                    'title'       => 'Payex Live Secret Key',
                    'type'        => 'password',
                    'description' => 'This key should be used when you are ready to go live. Obtain the secret key from Payex.',
                )
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
         * @param $order_id
         */
        public function process_payment( $order_id ) {
            global $woocommerce;

            // we need it to get any order detailes
            $order = wc_get_order( $order_id );
            $order_data= $order->get_data();
            $token = $this->get_payex_token();

            if ($token) {
                // generate payex payment link
                $payment_link = $this->get_payex_payment_link(
                    $order_data['id'],
                    $order_data['total'],
                    'Payment for Order Reference:'.$order_data['order_key'],
                    $order_data['billing']['first_name'].' '.$order_data['billing']['last_name'],
                    $order_data['billing']['phone'],
                    $order_data['billing']['email'],
                    $order_data['billing']['address_1'].','.$order_data['billing']['address_2'].', '.$order_data['billing']['city'],
                    $order_data['billing']['postcode'],
                    $order_data['billing']['state'],
                    $order_data['billing']['country'],
                    $this->get_return_url( $order ),
                    get_site_url(null, '/wc-api/'.self::HOOK_NAME),
                    $token
                );

                // Redirect to checkout page on Payex
                return array(
                    'result' => 'success',
                    'redirect' => $payment_link
                );

            } else {
                wc_add_notice('Payment gateway is temporary down, we are checking on it, please try again later.', 'error' );
                return;
            }
            // get token
        }

        /*
         * When Payment gateway server callback
         */
        public function webhook() {
            $verified = $this->verify_payex_response($_POST);

            if ($verified) {
                $order = wc_get_order( $_POST['reference_number'] );
                $order->payment_complete();
                $order->reduce_order_stock();
                $order->add_order_note( 'Payment completed with Payex', true );
            }
        }

        /**
         * Generate Payment form link to allow users to Pay
         *
         * @param $ref_no           string  Transaction record ref no
         * @param $amount           float   Float amount
         * @param $description      string  Describe this payment
         * @param $cust_name        string  Name of Customer
         * @param $cust_contact_no  string  Contact Number of Customer
         * @param $email            string  Email Address of Customer
         * @param $address          string  Physical Address of Customer
         * @param $postcode         string  Postcode of Customer Address
         * @param $state            string  State of Customer Address
         * @param $country          string  Country code of Customer Address
         * @param $return_url       string  Return URL when customer completed payment
         * @param $callback_url       string  Return URL when customer completed payment
         * @param null $token       string  Payex token
         * @return string
         */
        private function get_payex_payment_link($ref_no, $amount, $description, $cust_name, $cust_contact_no, $email, $address, $postcode, $state, $country, $return_url, $callback_url, $token = null)
        {
            if (!$token) {
                $token = $this->getToken()['token'];
            }

            if ($token) {
                $link = self::API_URL.self::API_PAYMENT_FORM
                    .'?token='.$token
                    .'&amount='.$amount
                    .'&description='.urlencode($description)
                    .'&customer_name='.urlencode($cust_name)
                    .'&contact_number='.$cust_contact_no
                    .'&address='.urlencode($address)
                    .'&postcode='.$postcode
                    .'&state='.urlencode($state)
                    .'&country='.$country
                    .'&email='.$email
                    .'&reference_number='.$ref_no
                    .'&return_url='.$return_url
                    .'&callback_url='.$callback_url;

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
            $email = $this->get_option('email');
            $secret = $this->get_option('secret_key');

            $request = wp_remote_post(self::API_URL.self::API_GET_TOKEN_PATH, [
                'method'      => 'POST',
                'timeout'     => 45,
                'headers'     => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Basic '.base64_encode($email.':'.$secret),
                ],
                'cookies'     => [],
            ]);

            if ( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 200 ) {
                error_log( print_r( $request, true ) );
            } else {
                $response = wp_remote_retrieve_body( $request );
                $response = json_decode($response, true);
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
         * @param   $response array
         * @return  bool
         */
        public function verify_payex_response($response) {
            if (isset($response['signature']) && isset($response['txn_id'])) {
                ksort($response); // sort array keys ascending order
                $host_signature = $response['signature'];
                $signature = $this->get_option('secret_key').'|'.$response['txn_id']; // append secret key infront

                $hash = hash('sha512', $signature);

                if ($hash == $host_signature) {
                    return true;
                }
            }
            return false;
        }
    }
}