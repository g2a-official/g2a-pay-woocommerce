<?php

if (!defined('ABSPATH')) {
    exit;
}
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'class-wc-gateway-g2apay.php';

/**
 * Get the G2A Pay Refund.
 */
class WC_Gateway_G2APay_Refund
{
    protected $gateway;

    /**
     * WC_Gateway_G2APay_Refund constructor.
     * @param $gateway
     */
    public function __construct($gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * Refund an order via G2APay.
     * @param  $order_id
     * @param  float $amount
     * @param  string $reason
     * @param $subscription_transaction_id
     * @return array|wp_error The parsed response from g2apay, or a WP_Error object
     */
    public function refund_order($order_id, $amount = null, $reason = '', $subscription_transaction_id = null)
    {
        $g2apayGateway = new WC_Gateway_G2APay;
        $order         = new WC_Order($order_id);

        $transaction_id = empty($subscription_transaction_id) ? G2APayHelper::getTransactionIdByOrderId($order_id)
                          : $subscription_transaction_id;

        if (empty($transaction_id)) {
            $g2apayGateway->log('Refund Failed: No transaction ID');

            return new WP_Error('g2apay-refunds', 'Refund Failed: No transaction ID');
        }

        $g2apayGateway->log('process_refund transaction ID: ' . $transaction_id);

        $order_id     = empty($subscription_transaction_id) ? $order->id : null;
        $order_amount = $order->get_total();
        $secret       = htmlspecialchars_decode($this->gateway->secret);
        $hash         = hash('sha256', $transaction_id . $order_id . G2APayHelper::getValidAmount($order_amount)
                        . G2APayHelper::getValidAmount($amount) . $secret);

        $body = array(
            'action' => 'refund',
            'amount' => $amount,
            'hash'   => $hash,
        );

        $api_hash       = $this->gateway->api_hash;
        $merchant_email = $this->gateway->merchant_email;
        $header_hash    = hash('sha256', $api_hash . $merchant_email . $secret);
        $rest_url       = $this->gateway->rest_url . '/' . $transaction_id;

        $response = wp_remote_request(
            $rest_url,
            [
                'method'      => 'PUT',
                'body'        => $body,
                'timeout'     => 70,
                'sslverify'   => false,
                'user-agent'  => 'WooCommerce/' . WC()->version . '/G2A Pay',
                'httpversion' => '1.1',
                'headers'     => ['authorization' => $api_hash . ';' . $header_hash],
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        if (empty($response['body'])) {
            return new WP_Error('g2apay-refunds', 'Empty Response');
        }

        if ($response['response']['code'] != 200) {
            return new WP_Error('g2apay-refunds', $response['response']['message']);
        }

        return $response['body'];
    }
}
