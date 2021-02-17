<?php
/**
 * Plugin Name:     Easy Digital Downloads - CoinPayments Gateway
 * Plugin URI:      http://wordpress.org/plugins/easy-digital-downloads-coinpayments-gateway
 * Description:     Add support for CoinPayments to Easy Digital Downloads. This plugin is almost entirely based on code provided by <a href="https://www.coinpayments.net/merchant-tools-plugins" target="_blank">CoinPayments</a>.
 * Version:         2.0.0
 * Author:          CoinPayments.net
 */

if (!defined('ABSPATH')) exit;


if (!class_exists('EDD_CoinPayments')) {

    class EDD_CoinPayments
    {

        public $gateway_id = 'coinpayments';
        protected $coinpayments;
        protected static $instance;

        public static function get_instance()
        {
            if (!self::$instance)
                self::$instance = new EDD_CoinPayments();

            return self::$instance;
        }

        public function __construct()
        {
            if (!class_exists('Easy_Digital_Downloads')) return;

            $this->register();

            if (!edd_is_gateway_active($this->gateway_id)) {
                return;
            }
            $this->config();
            $this->includes();
            $this->setup();
            $this->filters();
            $this->actions();
        }


        public function register_gateway($gateways)
        {
            $gateways['coinpayments'] = array(
                'admin_label' => 'CoinPayments.NET',
                'checkout_label' => __('CoinPayments - Pay with Bitcoin, Litecoin, or other cryptocurrencies', 'edd-coinpayments-gateway'),
                'supports' => array(),
            );

            return $gateways;
        }

        public function register_gateway_settings($gateway_settings)
        {
            $coinpayments_settings = array(
                array(
                    'id' => 'edd_coinpayments_settings',
                    'name' => '<strong>' . __('CoinPayments Settings', 'edd-coinpayments') . '</strong>',
                    'desc' => __('Configure your CoinPayments settings', 'edd-coinpayments'),
                    'type' => 'header'
                ),
                array(
                    'id' => 'edd_coinpayments_client_id',
                    'name' => __('Client ID', 'edd-coinpayments'),
                    'desc' => __('Enter your CoinPayments Client ID', 'edd-coinpayments'),
                    'type' => 'text'
                ),
                array(
                    'id' => 'edd_coinpayments_webhooks',
                    'name' => __('Webhooks', 'edd-coinpayments'),
                    'desc' => __('Check to receive CoinPayments payments notifications', 'edd-coinpayments'),
                    'type' => 'checkbox'
                ),
                array(
                    'id' => 'edd_coinpayments_client_secret',
                    'name' => __('Client Secret', 'edd-coinpayments'),
                    'desc' => __('Enter your CoinPayments Client Secret', 'edd-coinpayments'),
                    'type' => 'text'
                ),
            );
            $gateway_settings['coinpayments'] = $coinpayments_settings;
            return $gateway_settings;
        }

        public function register_gateway_section($gateway_sections)
        {
            $gateway_sections = array_slice($gateway_sections, 0, 1, true) +
                array('coinpayments' => __('CoinPayments.NET', 'easy-digital-downloads')) +
                array_slice($gateway_sections, 1, count($gateway_sections) - 1, true);

            return $gateway_sections;
        }

        public function gateways_sanitize($input)
        {

            if (!current_user_can('manage_shop_settings') || !isset($input['edd_coinpayments_webhooks'])) {
                return $input;
            }

            try {
                $error_text = 'Settings error.<br/>';
                if ($input['edd_coinpayments_webhooks'] == '-1') {
                    if (empty($input['edd_coinpayments_client_id'])) {
                        add_settings_error('edd-notices', 'empty_credentials', __($error_text . 'CoinPayments.NET Client ID can not be empty.', 'edd-coinpayments'), 'error');
                    }
                } elseif ($input['edd_coinpayments_webhooks'] == '1') {

                    if (!$this->coinpayments->check_webhook()) {
                        add_settings_error('edd-notices', 'invalid_credentials', __($error_text . 'CoinPayments.NET credentials are not valid.', 'edd-coinpayments'), 'error');
                    }
                }

            } catch (Exception $e) {
                add_settings_error('edd-notices', 'unexpected_error', __($e->getMessage(), 'edd-coinpayments'), 'error');
            }
            return $input;
        }

        public function register_payment_icon($payment_icons)
        {
            $payment_icons[plugin_dir_url(__FILE__) . 'icons/coinpayments.png'] = 'Coinpayments';
            return $payment_icons;
        }

        public function process_payment($purchase_data)
        {
            global $edd_options;

            $payment_data = array(
                'price' => $purchase_data['price'],
                'date' => $purchase_data['date'],
                'user_email' => $purchase_data['user_email'],
                'purchase_key' => $purchase_data['purchase_key'],
                'currency' => edd_get_currency(),
                'downloads' => $purchase_data['downloads'],
                'user_info' => $purchase_data['user_info'],
                'cart_details' => $purchase_data['cart_details'],
                'gateway' => 'coinpayments',
                'status' => 'pending'
            );

            $billing_data = array(
                'company' => get_bloginfo('name'),
                'first_name' => $purchase_data['user_info']['first_name'],
                'last_name' => $purchase_data['user_info']['last_name'],
                'email' => $purchase_data['user_info']['email'],
                'address' => $purchase_data['user_info']['address']
            );



            $payment = edd_insert_payment($payment_data);

            if (!$payment) {
                edd_record_gateway_error(__('Payment Error', 'edd-coinpayments'), sprintf(__('Payment creation failed before sending buyer to CoinPayments. Payment data: %s', 'edd-coinpayments'), json_encode($payment_data)), $payment);
                edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
            } else {


                $invoice_id = sprintf('%s|%s', md5(get_site_url()), $payment);

                $success_url = add_query_arg('payment-confirmation', 'coinpayments', get_permalink($edd_options['success_page']));
                $cancel_url = edd_get_failed_transaction_uri();


                try {

                    $currency_code = edd_get_currency();
                    $coin_currency = $this->coinpayments->get_coin_currency($currency_code);

                    $amount = intval(number_format($purchase_data['price'], $coin_currency['decimalPlaces'], '', ''));
                    $display_value = $purchase_data['price'];

                    $notes_link = sprintf(
                        "%s|Store name: %s|Order #%s",
                        admin_url('edit.php?post_type=download&page=edd-payment-history&view=view-order-details&id='. $payment),
                        get_bloginfo('name'),
                        $payment);

                    $invoice_params = array(
                        'invoice_id' => $invoice_id,
                        'currency_id' => $coin_currency['id'],
                        'amount' => $amount,
                        'display_value' => $display_value,
                        'billing_data' => $billing_data,
                        'notes_link' => $notes_link
                    );

                    $invoice = $this->coinpayments->create_invoice($invoice_params);
                    if (edd_get_option('edd_coinpayments_webhooks', '-1') == '1') {
                        $invoice = array_shift($invoice['invoices']);
                    }
                    $coinpayments_args = array(
                        'invoice-id' => $invoice['id'],
                        'success-url' => $success_url,
                        'cancel-url' => $cancel_url,
                    );
                    $coinpayments_args = apply_filters('edd_coinpayments_redirect_args', $coinpayments_args, $purchase_data);
                    $coinpayments_args = http_build_query($coinpayments_args, '', '&');
                    $redirect_url = sprintf('%s/%s/?%s', Coinpayments_API_Handler::CHECKOUT_URL, Coinpayments_API_Handler::API_CHECKOUT_ACTION, $coinpayments_args);
                    edd_empty_cart();
                    wp_redirect($redirect_url);

                } catch (Exception $e) {
                    edd_record_gateway_error(__('Payment Error', 'edd-coinpayments'), __($e->getMessage()));
                    edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
                }
            }
            exit;
        }

        public function edd_listen_for_coinpayments_notifications()
        {
            if (isset($_GET['edd-listener']) && $_GET['edd-listener'] == 'coinpayments') {
                do_action('process_coinpayments_notification');
            }
        }

        public function process_notification()
        {

            $signature = $_SERVER['HTTP_X_COINPAYMENTS_SIGNATURE'];
            $content = file_get_contents('php://input');

            $request_data = json_decode($content, true);

            if ($this->coinpayments->check_data_signature($signature, $content) && isset($request_data['invoice']['invoiceId'])) {
                $invoice_str = $request_data['invoice']['invoiceId'];
                $invoice_str = explode('|', $invoice_str);

                $host_hash = array_shift($invoice_str);
                $invoice_id = array_shift($invoice_str);

                if ($host_hash == md5(get_site_url())) {

                    if ($request_data['invoice']['status'] == 'Pending') {
                        edd_update_payment_status($invoice_id, 'pending');
                    } elseif ($request_data['invoice']['status'] == 'Completed') {
                        edd_update_payment_status($invoice_id, 'publish');
                    } elseif ($request_data['invoice']['status'] == 'Cancelled') {
                        edd_update_payment_status($invoice_id, 'revoked');
                    }
                }
            }
            exit;
        }

        protected function register()
        {
            add_filter('edd_payment_gateways', array($this, 'register_gateway'));
        }

        protected function config()
        {
            if (!defined('EDD_COINPAYMENTS_DIR')) {
                $path = trailingslashit(plugin_dir_path(__FILE__)) . 'lib';
                define('EDD_COINPAYMENTS_DIR', trailingslashit($path));
            }
        }

        protected function includes()
        {
            require_once EDD_COINPAYMENTS_DIR . 'coinpayments-api-handler.php';
        }

        protected function filters()
        {

            add_filter('edd_payment_gateways', array($this, 'register_gateway'));
            add_filter('edd_accepted_payment_icons', array($this, 'register_payment_icon'), 10, 1);

            if (is_admin()) {
                add_filter('edd_settings_sections_gateways', array($this, 'register_gateway_section'), 1, 1);
                add_filter('edd_settings_gateways', array($this, 'register_gateway_settings'), 1, 1);
                add_filter('edd_settings_gateways_sanitize', array($this, 'gateways_sanitize'), 1, 1);
            }

        }

        protected function setup()
        {

            $client_id = edd_get_option('edd_coinpayments_client_id', '');
            $webhooks = edd_get_option('edd_coinpayments_webhooks', '-1');
            $client_secret = edd_get_option('edd_coinpayments_client_secret', '');

            $this->coinpayments = new Coinpayments_API_Handler($client_id, $webhooks, $client_secret);

        }

        protected function actions()
        {

            add_action('init', array($this, 'edd_listen_for_coinpayments_notifications'));

            add_action('edd_coinpayments_cc_form', '__return_false');

            add_action('edd_gateway_coinpayments', array($this, 'process_payment'));
            add_action('process_coinpayments_notification', array($this, 'process_notification'));

            add_action('edd_after_cc_fields', array($this, 'errors_div'), 999);
        }

    }
}

function edd_coinpayments_gateway_load()
{
    return new EDD_CoinPayments();
}

add_action('plugins_loaded', 'edd_coinpayments_gateway_load');
