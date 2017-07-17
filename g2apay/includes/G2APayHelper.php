<?php

/**
 * @author    G2A Team
 * @copyright Copyright (c) 2016 G2A.COM
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class G2APayHelper
{
    const IPN_PAYMENT_TYPE_NAME               = 'payment';
    const IPN_SUBSCRIPTION_PAYMENT_TYPE_NAME  = 'subscription_payment';
    const IPN_SUBSCRIPTION_CREATED_TYPE_NAME  = 'subscription_created';
    const IPN_SUBSCRIPTION_CANCELED_TYPE_NAME = 'subscription_canceled';

    /**
     * Return price in correct format.
     *
     * @param $amount
     * @return float
     */
    public static function getValidAmount($amount)
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * @param $transaction_id
     * @return int
     */
    public static function getOrderIdByTransactionId($transaction_id)
    {
        $data = self::getIpnByTransactionId($transaction_id);

        return !empty($data[0]->related_order_id) ? $data[0]->related_order_id : 0;
    }

    /**
     * @param $subscription_id
     * @return string
     */
    public static function getOrderIdBySubscriptionId($subscription_id)
    {
        global $wpdb;

        $query = $wpdb->prepare('SELECT related_order_id 
                                 FROM g2apay_subscriptions 
                                 WHERE subscription_id =%s', $subscription_id);

        $result = $wpdb->get_results($query);

        return $result[0]->related_order_id;
    }

    /**
     * @param $transaction_id
     * @return array|null|object
     */
    public static function getIpnByTransactionId($transaction_id)
    {
        global $wpdb;

        $query = $wpdb->prepare('SELECT * 
                                 FROM g2apay_payments 
                                 WHERE transaction_id =%s', $transaction_id);

        return $wpdb->get_results($query);
    }

    /**
     * @param $order_id
     * @return bool
     */
    public static function getTransactionIdByOrderId($order_id)
    {
        global $wpdb;

        $query = $wpdb->prepare('SELECT transaction_id 
                                  FROM g2apay_payments 
                                  WHERE related_order_id =%s', $order_id);

        $result = $wpdb->get_results($query);

        return !empty($result[0]->transaction_id) ? $result[0]->transaction_id : false;
    }

    /**
     * @param $params
     * @param $order_id
     */
    public static function insertNewPayment($params, $order_id)
    {
        global $wpdb;

        $date         = DateTime::createFromFormat(DateTime::ISO8601, $params['orderCompleteAt']);
        $date_created = $date->format('Y-m-d');
        $wpdb->insert('g2apay_payments',
            array(
                'related_order_id' => $order_id,
                'transaction_id'   => $params['transactionId'],
                'subscription_id'  => isset($params['subscriptionId']) ? $params['subscriptionId'] : null,
                'status'           => $params['status'],
                'type'             => isset($params['subscriptionId']) ? self::IPN_SUBSCRIPTION_PAYMENT_TYPE_NAME
                    : self::IPN_PAYMENT_TYPE_NAME,
                'amount'          => $params['amount'],
                'amount_refunded' => $params['refundedAmount'],
                'date_paid'       => $date_created,
            ));
    }

    /**
     * @param $message
     * @param $type
     */
    public static function setSessionMessage($message, $type)
    {
        if (!self::isTypeValid($type)) {
            return;
        }

        $_SESSION['g2apay_' . $type . '_message'] = $message;
    }

    /**
     * @param $type
     * @return string|null
     */
    public static function getSessionMessage($type)
    {
        if (!self::isTypeValid($type)) {
            return;
        }

        return isset($_SESSION['g2apay_' . $type . '_message']) ? $_SESSION['g2apay_' . $type . '_message'] : null;
    }

    /**
     * @param $type
     */
    public static function unsetSessionMessage($type)
    {
        if (!self::isTypeValid($type)) {
            return;
        }

        unset($_SESSION['g2apay_' . $type . '_message']);
    }

    /**
     * @param $type
     * @return bool
     */
    public static function isTypeValid($type)
    {
        $valid_types = array('error', 'success');

        return in_array($type, $valid_types);
    }

    /**
     * Validates if addresses array have values in all required fields.
     *
     * @param $addresses
     * @return bool
     */
    public static function validateAddresses($addresses)
    {
        if(self::validateAddressType($addresses['billing']) === false) {
            $addresses['billing'] = $addresses['shipping'];
        }
        else if(self::validateAddressType($addresses['shipping']) === false) {
            $addresses['shipping'] = $addresses['billing'];
        }

        return (self::validateAddressType($addresses['billing']) === true
            && self::validateAddressType($addresses['shipping']) === true);
    }

    /**
     * Validate address array.
     * 
     * @param array $address
     * @return bool
     */
    private static function validateAddressType(array $address)
    {
        $requiredFields = ['firstname', 'lastname', 'line_1', 'zip_code', 'city', 'county', 'country'];

        foreach ($requiredFields as $requiredField) {
            if (empty($address[$requiredField])) {
                return false;
            }
        }

        return true;
    }
}
