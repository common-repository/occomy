<?php

/*
 * Plugin Name: Occomy
 * Description: Accept Occomy payments on your store.
 * Author: Occomy Team
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4.1
 * License: GPL v3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

/*
 * This action hook registers our gateway as a WooCommerce payment gateway
 */
add_filter('woocommerce_payment_gateways', 'wc_occomy_gateway');
function wc_occomy_gateway($gateways)
{
    $gateways[] = 'WC_Occomy_Gateway';
    return $gateways;
}

/*
 * The gateway class, note that it is inside plugins_loaded action hook
 */
add_action('plugins_loaded', 'wc_occomy_gateway_init');
function wc_occomy_gateway_init()
{

    // This gets used for writing debug logs
    if (!function_exists('write_log')) {

        function write_log($log)
        {
            if (true === WP_DEBUG) {
                if (is_array($log) || is_object($log)) {
                    error_log(print_r($log, true));
                } else {
                    error_log($log);
                }
            }
        }
    }

    class WC_Occomy_Gateway extends WC_Payment_Gateway
    {

        /**
         * Class constructor
         *
         * Define class properties
         * Initialize settings
         * Append options
         * Save options
         * Custom JavaScript and CSS
         * 
         */
        public function __construct()
        {

            // Basic details about the gateway
            $this->id = 'occomy'; // ID for the payment gateway
            $this->method_title = 'Occomy'; // Title of the payment gateway
            $this->method_description = 'Redirect users to the Occomy website when they checkout to complete payment.'; // Description of the gateway
            $this->icon = plugin_dir_url(__FILE__) . 'assets/images/icon.png'; // Gateway icon

            // Supported payment methods
            $this->supports = array(
                'products'
            );

            // Initialise the gateway settings form fields -> This loads up any saved settings
            $this->init_form_fields();
            $this->init_settings();
            $this->title = 'Occomy';
            $this->description = 'You will be redirected to the Occomy website to complete payment.';
            $this->enabled = $this->get_option('enabled');
            $this->api_key = $this->get_option('api_key');

            // This action hook saves the settings
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

            // Register webhook for Occomy website here
            add_action('woocommerce_api_wc_occomy_gateway', array($this, 'webhook'));
        }

        /**
         * Form fields constructor
         * 
         * This gets use to create the form fields in the WooCommerce geteway settings
         * 
         */
        public function init_form_fields()
        {

            $this->form_fields = array(
                'enabled' => array(
                    'title'       => 'Enable/Disable',
                    'label'       => 'Enable Occomy Gateway',
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'no'
                ),
                'api_key' => array(
                    'title'       => 'API Key',
                    'type'        => 'text',
                    'description' => 'You can find your API Key by logging in to the Occomy website.',
                ),

            );
        }

        /**
         * Process payment
         * 
         * This gets called when the user clicks on 'place'order
         * 
         */
        public function process_payment($order_id)
        {

            // Get the details we need
            $order = wc_get_order($order_id);
            $amount = $order->get_total();
            $description = 'WooCommerce Order: ' . $order_id;
            $apikey = $this->get_option('api_key');
            $orderid = $order_id;
            $callbackURL = WC()->api_request_url('WC_Occomy_Gateway');

            $occomy_payment_url = 'https://www.occomy.com/payment/createpayment?amount=' . $amount . '&description=' . $description . '&apikey=' . $apikey . '&orderid=' . $orderid . '&callback=' . $callbackURL;

            // Redirect the user to the Occomy website for payment
            return array(
                'result'   => 'success',
                'redirect' => $occomy_payment_url,
            );
        }

        /**
         * Webhook
         * 
         * This is used to control bahaviour when we return from the redirect
         * 
         */
        public function webhook()
        {

            function verifyTransaction($transactionID)
            {
                $request = wp_remote_post('https://www.occomy.com/api/services/verifytransaction', array(
                    'method' => 'POST',
                    'body' => array(
                        'transactionid' => $transactionID,
                    )
                ));

                $response = wp_remote_retrieve_body($request);
                $data = json_decode($response);

                // Sometimes the data just doesn't show up, keep trying
                while (empty($data)) {
                    $request = wp_remote_post('https://www.occomy.com/api/services/verifytransaction', array(
                        'method' => 'POST',
                        'body' => array(
                            'transactionid' => $transactionID,
                        )
                    ));

                    $response = wp_remote_retrieve_body($request);
                    $data = json_decode($response);

                    write_log("Retried API call");
                }

                if (is_wp_error($request)) {
                    exit;
                } else {
                    return $data->status;
                }
            }

            // Get the necesary details from the query parameters
            $status = $_GET['status'];
            $transactionReference = $_GET['reference'];
            $order_id = $_GET['orderid'];
            $order = wc_get_order($order_id);
            $verificationStatus = verifyTransaction($transactionReference);

            if ($status == 'approved') {

                if ($verificationStatus == 'approved') {
                    // Process order
                    $order->update_status('processing');
                    $order->add_order_note('Successful Occomy payment (Transaction Reference: ' . $transactionReference . ')');
                    wc_reduce_stock_levels($order_id);
                    WC()->cart->empty_cart();

                    // Redirect the user to the success page
                    wp_redirect($order->get_checkout_order_received_url());
                    exit;
                } else {
                    // Process order
                    $order->update_status('cancelled');
                    $order->add_order_note('Occomy payment declined (Transaction Reference: ' . $transactionReference . ')');

                    // Redirect the user to the failure page
                    wp_redirect($order->get_cancel_order_url());
                    wc_add_notice('Payment was declined', 'error');
                    exit;
                }
            } else {
                // Process order
                $order->update_status('cancelled');
                $order->add_order_note('Occomy payment declined (Transaction Reference: ' . $transactionReference . ')');

                // Redirect the user to the failure page
                wp_redirect($order->get_cancel_order_url());
                wc_add_notice('Payment was declined', 'error');

                exit;
            }

            update_option('webhook_debug', $_GET);
        }
    }
}
