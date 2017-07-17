<?php

if (!defined('ABSPATH')) {
    exit;
}
require_once 'G2APayHelper.php';

/**
 * Get the G2A Pay IPN.
 */
class WC_Gateway_G2APay_IPN
{
    const G2A_PAY_IPN_SUCCESS_MESSAGE            = 'Success';
    const G2A_PAY_IPN_REFUND_STATUS_NAME         = 'refunded';
    const G2A_PAY_IPN_PARTIAL_REFUND_STATUS_NAME = 'partial_refunded';

    const WOOCOMMERCE_STATUS_COMPLETED = 'completed';
    const WOOCOMMERCE_STATUS_FAILED    = 'failed';
    const WOOCOMMERCE_STATUS_CANCELLED = 'cancelled';
    const WOOCOMMERCE_STATUS_REFUNDED  = 'refunded';
    const WOOCOMMERCE_STATUS_PENDING   = 'pending';

    /**
     * Pointer to gateway making the request.
     * @var WC_Gateway_G2APay_IPN
     */
    protected $gateway;

    /**
     * Pointer to post data from G2APay IPN.
     */
    protected $post_data;

    /**
     * WC_Gateway_G2APay_IPN constructor.
     * @param $gateway
     * @param $post_data
     */
    public function __construct($gateway, $post_data)
    {
        $this->gateway   = $gateway;
        $this->post_data = $post_data;
        $this->order     = !empty($post_data['userOrderId']) ? new WC_Order($post_data['userOrderId'])
                           : new WC_Order(G2APayHelper::getOrderIdBySubscriptionId($post_data['subscriptionId']));
    }

    /**
     * @string
     */
    public function get_g2apay_ipn()
    {
        switch ($this->post_data['type']) {
            case G2APayHelper::IPN_PAYMENT_TYPE_NAME :
                return isset($this->post_data['subscriptionId']) ? $this->processIpnForSubscriptionPayment()
                    : $this->processIpnForPayment();
            case G2APayHelper::IPN_SUBSCRIPTION_CREATED_TYPE_NAME:
                return $this->processIpnForSubscriptionCreation();
            case G2APayHelper::IPN_SUBSCRIPTION_CANCELED_TYPE_NAME:
                return $this->processIpnForSubscriptionCancellation();
            default :
                return 'Unrecognized ipn type';
        }
    }

    /**
     * @return string
     */
    private function processIpnForPayment()
    {
        if (!$this->check_order($this->order)) {
            $message = 'ERROR! Can not verify hash!';
            $this->gateway->log($message);

            return $message;
        }
        switch ($this->post_data['status']) {
                case 'complete':
                    $status = self::WOOCOMMERCE_STATUS_COMPLETED;
                    break;
                case 'rejected':
                    $status = self::WOOCOMMERCE_STATUS_FAILED;
                    break;
                case 'cancelled':
                    $status = self::WOOCOMMERCE_STATUS_CANCELLED;
                    break;
                case 'partial_refunded':
                    $status = self::WOOCOMMERCE_STATUS_REFUNDED;
                    break;
                case 'refunded':
                    $status = self::WOOCOMMERCE_STATUS_REFUNDED;
                    break;
                case 'pending':
                    $status = self::WOOCOMMERCE_STATUS_PENDING;
                    break;
                default:
                    $message = 'Unrecognized IPN status - order #' . $this->order->id . ', IPN status: ' . $this->post_data['status'];
                    $this->gateway->log($message);

                    return $message;
            }

        $this->change_status_order($this->order, $status, $this->post_data);

        $this->processIpnForRefund(false);

        return 'Change order #' . $this->order->id . ' status to: ' . $status;
    }

    /**
     * @return string
     */
    private function processIpnForSubscriptionPayment()
    {
        if (!$this->check_order($this->order)) {
            $message = 'ERROR! Can not verify hash!';
            $this->gateway->log($message);

            return $message;
        }

        if (in_array($this->post_data['status'],
            array(self::G2A_PAY_IPN_REFUND_STATUS_NAME, self::G2A_PAY_IPN_PARTIAL_REFUND_STATUS_NAME))) {
            return $this->processIpnForRefund(true);
        }

        G2APayHelper::insertNewPayment($this->post_data, $this->order->id);

        $this->order->add_order_note(__('New subscription payment created', 'woocommerce'));

        return self::G2A_PAY_IPN_SUCCESS_MESSAGE;
    }

    /**
     * @param bool $isSubscription
     * @return string
     */
    private function processIpnForRefund($isSubscription)
    {
        global $wpdb;

        $ipn = G2APayHelper::getIpnByTransactionId($this->post_data['transactionId']);

        if (empty($ipn[0]->transaction_id)) {
            G2APayHelper::insertNewPayment($this->post_data, $this->order->id);
        } else {
            $wpdb->update('g2apay_payments', array(
                'status'          => $this->post_data['status'],
                'amount_refunded' => $ipn[0]->amount_refunded + $this->post_data['refundedAmount'],
            ), array(
                'transaction_id' => $this->post_data['transactionId'],
            ));
        }
        if ($isSubscription) {
            $this->order->add_order_note(__('Subscription payment refunded for amount: '
        . $this->post_data['refundedAmount'], 'woocommerce'));
        }

        return self::G2A_PAY_IPN_SUCCESS_MESSAGE;
    }

    /**
     * @return string
     */
    private function processIpnForSubscriptionCreation()
    {
        global $wpdb;

        if ($this->post_data['hash'] !== $this->generateSubscriptionHash($this->post_data['subscriptionId'],
                $this->post_data['subscriptionName'], $this->post_data['amount'])) {
            return 'Invalid hash';
        }

        $wpdb->insert('g2apay_subscriptions',
            array(
                'related_order_id'  => G2APayHelper::getOrderIdByTransactionId($this->post_data['transactionId']),
                'transaction_id'    => $this->post_data['transactionId'],
                'subscription_id'   => $this->post_data['subscriptionId'],
                'subscription_name' => $this->post_data['subscriptionName'],
                'status'            => 'active',
                'amount'            => $this->post_data['amount'],
                'created_at'        => $this->post_data['createdAt'],
            ));
        $this->order->add_order_note(__('New subscription created', 'woocommerce'));

        return self::G2A_PAY_IPN_SUCCESS_MESSAGE;
    }

    /**
     * @return string
     */
    private function processIpnForSubscriptionCancellation()
    {
        global $wpdb;

        if ($this->post_data['hash'] !== $this->generateSubscriptionHash($this->post_data['subscriptionId'],
                $this->post_data['subscriptionName'], G2APayHelper::getValidAmount($this->post_data['amount']))) {
            return 'Invalid hash';
        }

        $wpdb->update('g2apay_subscriptions',
            array(
                'status'      => 'canceled',
                'disabled_at' => $this->post_data['disabledAt'],
            ),
            array(
                'subscription_id' => $this->post_data['subscriptionId'],
            ));
        $this->order->add_order_note(__('Subscription based on this order has been canceled', 'woocommerce'));

        return self::G2A_PAY_IPN_SUCCESS_MESSAGE;
    }

    /**
     * @param $order
     * @return bool
     */
    public function check_order($order)
    {
        $transactionId = $this->post_data['transactionId'];
        $userOrderId   = $this->post_data['userOrderId'];
        $amount        = $this->post_data['amount'];
        $secret        = htmlspecialchars_decode($this->gateway->secret);
        $hash_string   = $transactionId . $userOrderId . $amount . $secret;
        $hash          = hash('sha256', $hash_string);
        if ($hash == $this->post_data['hash']) {
            $this->gateway->log('Check Order - hash OK.');

            return true;
        } else {
            $this->gateway->log('Check Order - hash not OK.');
            $this->gateway->log($hash . ' != ' . $this->post_data['hash']);

            return false;
        }
    }

    /**
     * @param WC_Order $order
     * @param $status
     * @param $post_data
     */
    public function change_status_order($order, $status, $post_data)
    {
        $this->gateway->log('transactionId: ' . $post_data['transactionId']);

        if ($status === self::WOOCOMMERCE_STATUS_COMPLETED) {
            $order->payment_complete($post_data['transactionId']);
        }

        $order->update_status($status, '');
        $this->gateway->log('IPN change order #' . $order->id . ' to status ' . $status);
    }

    /**
     * @param $subscription_id
     * @param $subscription_name
     * @param $amount
     * @return string
     */
    public function generateSubscriptionHash($subscription_id, $subscription_name, $amount)
    {
        $hash_string = $subscription_id . $amount . $subscription_name . htmlspecialchars_decode($this->gateway->secret);

        return hash('sha256', $hash_string);
    }
}
