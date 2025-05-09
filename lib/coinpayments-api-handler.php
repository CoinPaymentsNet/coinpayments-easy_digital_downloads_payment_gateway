<?php
if (!defined('ABSPATH')) {
    exit;
}

class Coinpayments_API_Handler
{

    const API_URL = 'https://api.coinpayments.net';
    const CHECKOUT_URL = 'https://checkout.coinpayments.net';
    const API_VERSION = '1';

    const API_SIMPLE_INVOICE_ACTION = 'invoices';
    const API_WEBHOOK_ACTION = 'merchant/clients/%s/webhooks';
    const API_MERCHANT_INVOICE_ACTION = 'merchant/invoices';
    const API_CURRENCIES_ACTION = 'currencies';
    const API_CHECKOUT_ACTION = 'checkout';
    const FIAT_TYPE = 'fiat';

    const PAID_EVENT = 'Paid';
    const CANCELLED_EVENT = 'Cancelled';

    /**
     * @var string
     */
    protected $client_id;

    /**
     * @var string
     */
    protected $client_secret;

    /**
     * @var string
     */
    protected $webhooks;

    /**
     * WC_Gateway_Coinpayments_API_Handler constructor.
     * @param $client_id
     * @param bool $webhooks
     * @param bool $client_secret
     */
    public function __construct($client_id, $webhooks = false, $client_secret = false)
    {
        $this->client_id = $client_id;
        $this->client_secret = $client_secret;
        $this->webhooks = $webhooks;
    }

    /**
     * @return bool
     * @throws Exception
     */
    public function check_webhook()
    {
        $exists = false;
        $webhooks_list = $this->get_webhooks_list();
        if (!empty($webhooks_list)) {
            $webhooks_urls_list = array();
            if (!empty($webhooks_list['items'])) {
                $webhooks_urls_list = array_map(function ($webHook) {
                    return $webHook['notificationsUrl'];
                }, $webhooks_list['items']);
            }
            if (!in_array($this->get_notification_url(self::PAID_EVENT), $webhooks_urls_list)) {
                if ($this->create_webhook(self::PAID_EVENT)) {
                    $exists = true;
                }
            }
            if (!in_array($this->get_notification_url(self::CANCELLED_EVENT), $webhooks_urls_list)) {
                if ($this->create_webhook(self::CANCELLED_EVENT)) {
                    $exists = true;
                }
            } else {
                $exists = true;
            }
        }
        return $exists;
    }

    /**
     * @return bool|mixed
     * @throws Exception
     */
    public function create_webhook($event = false)
    {

        $action = sprintf(self::API_WEBHOOK_ACTION, $this->client_id);

        $params = array(
            "notificationsUrl" => $this->get_notification_url($event),
            "notifications" => [
                sprintf("invoice%s", $event),
            ],
        );

        return $this->send_request('POST', $action, $this->client_id, $params, $this->client_secret);
    }

    /**
     * @return bool|mixed
     * @throws Exception
     */
    public function get_webhooks_list()
    {

        $action = sprintf(self::API_WEBHOOK_ACTION, $this->client_id);

        return $this->send_request('GET', $action, $this->client_id, null, $this->client_secret);
    }

    /**
     * @param $name
     * @return mixed
     * @throws Exception
     */
    public function get_coin_currency($name)
    {

        $params = array(
            'types' => self::FIAT_TYPE,
            'q' => $name,
        );
        $items = array();

        $listData = $this->get_coin_currencies($params);
        if (!empty($listData['items'])) {
            $items = $listData['items'];
        }

        return array_shift($items);
    }

    /**
     * @param array $params
     * @return bool|mixed
     * @throws Exception
     */
    public function get_coin_currencies($params = array())
    {
        return $this->send_request('GET', self::API_CURRENCIES_ACTION, false, $params);
    }

    /**
     * @param $signature
     * @param $content
     * @return bool
     */
    public function check_data_signature($signature, $content, $event)
    {

        $request_url = $this->get_notification_url($event);
        $signature_string = sprintf('%s%s', $request_url, $content);
        $encoded_pure = $this->encode_signature_string($signature_string, $this->client_secret);
        return $signature == $encoded_pure;
    }

    /**
     * @param $invoice_id
     * @param $currency_id
     * @param $amount
     * @param $display_value
     * @return bool|mixed
     * @throws Exception
     */
    public function create_invoice($invoice_params)
    {

        if ($this->webhooks) {
            $action = self::API_MERCHANT_INVOICE_ACTION;
        } else {
            $action = self::API_SIMPLE_INVOICE_ACTION;
        }


        $params = array(
            'clientId' => $this->client_id,
            'invoiceId' => $invoice_params['invoice_id'],
            'amount' => [
                'currencyId' => $invoice_params['currency_id'],
                "displayValue" => $invoice_params['display_value'],
                'value' => $invoice_params['amount']
            ],
            'requireBuyerNameAndEmail' => true,
            'notesToRecipient' => $invoice_params['notes_link']
        );

        $params = $this->append_billing_data($params, $invoice_params['billing_data']);
        $params = $this->append_invoice_metadata($params);
        return $this->send_request('POST', $action, $this->client_id, $params, $this->client_secret);
    }

    /**
     * @array $billing_data
     * @return mixed
     */
    protected function append_billing_data($request_params, $billing_data)
    {
        $request_params['buyer'] = array(
            'companyName' => get_bloginfo('name'),
            'name' => array(
                'firstName' => $billing_data['user_info']['first_name'],
                'lastName' => $billing_data['user_info']['last_name']
            ),
            'emailAddress' => $billing_data['user_info']['email'],
        );

        if (!empty($billing_data['user_info']['address']))
        {
            $request_params['buyer']['address'] = array(
                'address1' => $billing_data['user_info']['address']['address1'],
                'provinceOrState' => $billing_data['user_info']['address']['state'],
                'city' => $billing_data['user_info']['address']['city'],
                'countryCode' => $billing_data['user_info']['address']['country'],
                'postalCode' => $billing_data['user_info']['address']['postcode']
            );
        }
        return $request_params;
    }

    /**
     * @param $signature_string
     * @param $client_secret
     * @return string
     */
    public function encode_signature_string($signature_string, $client_secret)
    {
        return base64_encode(hash_hmac('sha256', $signature_string, $client_secret, true));
    }

    /**
     * @param $action
     * @return string
     */
    public function get_api_url($action)
    {
        return sprintf('%s/api/v%s/%s', self::API_URL, self::API_VERSION, $action);
    }

    /**
     * @param $gateway_id
     * @return string
     */
    protected function get_notification_url($event = false)
    {
        $url = trailingslashit(home_url()) . '?edd-listener=coinpayments';
        $url = add_query_arg('clientId', $this->client_id, $url);
        $url = add_query_arg('event', $event, $url);

        return $url;
    }

    /**
     * @param $method
     * @param $api_action
     * @param $client_id
     * @param null $params
     * @param null $client_secret
     * @return bool|mixed
     * @throws Exception
     */
    protected function send_request($method, $api_action, $client_id, $params = null, $client_secret = null)
    {

        $response = false;

        $api_url = $this->get_api_url($api_action);
        $date = new \Datetime();
        try {

            $curl = curl_init();

            $options = array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_SSL_VERIFYPEER => false,
            );

            $headers = array(
                'Content-Type: application/json',
            );

            if ($client_secret) {
                $signature = $this->create_signature($method, $api_url, $client_id, $date, $client_secret, $params);
                $headers[] = 'X-CoinPayments-Client: ' . $client_id;
                $headers[] = 'X-CoinPayments-Timestamp: ' . $date->format('c');
                $headers[] = 'X-CoinPayments-Signature: ' . $signature;

            }

            $options[CURLOPT_HTTPHEADER] = $headers;

            if ($method == 'POST') {
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = json_encode($params);
            } elseif ($method == 'GET' && !empty($params)) {
                $api_url .= '?' . http_build_query($params);
            }

            $options[CURLOPT_URL] = $api_url;

            curl_setopt_array($curl, $options);

            $response = json_decode(curl_exec($curl), true);

            curl_close($curl);

        } catch (Exception $e) {

        }
        return $response;
    }

    /**
     * @param $request_data
     * @return mixed
     */
    protected function append_invoice_metadata($request_data)
    {
        $request_data['metadata'] = array(
            "integration" => sprintf("Easy Digital Downloads v.%s", EDD_VERSION),
            "hostname" => get_site_url(),
        );

        return $request_data;
    }

    /**
     * @param $method
     * @param $api_url
     * @param $client_id
     * @param $date
     * @param $client_secret
     * @param $params
     * @return string
     */
    protected function create_signature($method, $api_url, $client_id, $date, $client_secret, $params)
    {

        if (!empty($params)) {
            $params = json_encode($params);
        }

        $signature_data = array(
            chr(239),
            chr(187),
            chr(191),
            $method,
            $api_url,
            $client_id,
            $date->format('c'),
            $params
        );

        $signature_string = implode('', $signature_data);

        return $this->encode_signature_string($signature_string, $client_secret);
    }

}
