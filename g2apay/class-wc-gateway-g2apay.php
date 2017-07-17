<?php

if (!defined('ABSPATH')) {
    exit;
}
require_once 'includes/class-wc-gateway-g2apay-ipn.php';
require_once 'includes/class-wc-gateway-g2apay-refund.php';

/**
 * G2A Pay Payment Gateway.
 *
 * Provides a G2A Pay Standard Payment Gateway.
 *
 * @class    WC_G2APay
 * @extends    WC_Gateway_G2APay
 * @version    1.1.2
 * @package    WooCommerce/Classes/Payment
 * @author    G2A Team
 */
class WC_Gateway_G2APay extends WC_Payment_Gateway
{
    /**
     * Constructor for the gateway.
     */
    public function __construct()
    {
        $this->id                 = 'g2apay';
        $this->has_fields         = false;
        $this->order_button_text  = __('Pay via G2A Pay', 'woocommerce');
        $this->method_title       = __('G2A Pay', 'woocommerce');
        $this->method_description = __('All-in-one solution with <strong>100+</strong> global and local payment methods.', 'woocommerce');
        $this->supports           = ['products', 'refunds'];

        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();

        $pluginDir = plugin_dir_url(__FILE__);
        $pluginDIr = str_replace('includes/', '', $pluginDir);

        $this->g2apay_plugin_path = WC_HTTPS::force_https_url($pluginDIr);

        // checkout config
        $this->title        = $this->get_option('title');
        $this->visible_icon = 'yes' === $this->get_option('g2apayicon', 'no');
        $this->icon         = ($this->visible_icon ? $this->g2apay_plugin_path . 'assets/images/g2apay.png' : '');
        $this->description  = $this->get_option('description');
        $this->testmode     = 'yes' === $this->get_option('testmode', 'no');
        $this->checkout_url = ($this->testmode ? 'https://checkout.test.pay.g2a.com' : 'https://checkout.pay.g2a.com');
        $this->rest_url     = ($this->testmode ? 'https://www.test.pay.g2a.com/rest/transactions' : 'https://pay.g2a.com/rest/transactions');

        // API credentials
        $this->api_hash       = $this->get_option('api_hash');
        $this->secret         = $this->get_option('secret');
        $this->merchant_email = $this->get_option('merchant_email');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_api_' . strtolower(get_class($this)), [&$this, 'g2apay_ipn_callback']);
    }

    /**
     * Initialise Gateway Settings Form Fields.
     */
    public function init_form_fields()
    {
        $this->form_fields = include 'includes/settings-g2apay.php';
    }

    /**
     * Callback IPN from G2A Pay.
     *
     * @return string
     */
    public function g2apay_ipn_callback()
    {
        if (!empty($_POST)) {
            $posted = wp_unslash($_POST);
            $this->log('IPN POST data OK!');
        } else {
            $this->log('IPN POST data empty!');
            die('IPN POST data empty!');
        }

        if (isset($posted['userOrderId']) && isset($posted['status'])) {
            $this->log('IPN Call from G2A Pay: order #' . $posted['userOrderId'] . ', status ' . $posted['status']);
            $g2apay_ipn = new WC_Gateway_G2APay_IPN($this, $posted);
            $result_ipn = $g2apay_ipn->get_g2apay_ipn();
        } elseif ((in_array($posted['type'], array(G2APayHelper::IPN_SUBSCRIPTION_CREATED_TYPE_NAME,
            G2APayHelper::IPN_SUBSCRIPTION_CANCELED_TYPE_NAME, )))
            || (!isset($posted['userOrderId']) && isset($posted['subscriptionId']))) {
            $this->log('IPN Call from G2A Pay, action: ' . $posted['type']);
            $g2apay_ipn = new WC_Gateway_G2APay_IPN($this, $posted);
            $result_ipn = $g2apay_ipn->get_g2apay_ipn();
        } else {
            $this->log('Invalid post data from G2A Pay IPN.');
            $result_ipn = 'Invalid post data from G2A Pay IPN.';
        }

        die($result_ipn);
    }

    /**
     * Logging method.
     * @param  string $message
     */
    public function log($message)
    {
        global $woocommerce;

        // Get Logger instance
        if (version_compare($woocommerce->version, '2.1.0', '>=')) {
            $log = new WC_Logger();
        } else {
            $log = $woocommerce->logger();
        }

        // Write message to log
        if (!is_string($message)) {
            $message = var_export($message, true);
        }

        if ($this->testmode) {
            $log->add($this->id, $message);
        }
    }

    /**
     * Process the payment and return the result.
     *
     * @param int $order_id $redirect
     * @return array
     */
    public function process_payment($order_id)
    {
        include_once 'includes/class-wc-gateway-g2apay-checkout.php';

        $g2apay_checkout = new WC_Gateway_G2APay_Checkout($this);
        $this->log('Start process payment. Order #' . $order_id);

        return $g2apay_checkout->create_checkout($order_id);
    }

    /**
     * Process a refund if supported.
     * @param  int $order_id
     * @param  float $amount
     * @param  string $reason
     * @return  bool True or false based on success, or a WP_Error object
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $g2apay_refund = new WC_Gateway_G2APay_Refund($this);
        $result        = $g2apay_refund->refund_order($order_id, $amount, $reason);

        if (is_wp_error($result)) {
            $this->log('Refund Failed: ' . $result->get_error_message());

            return false;
        }

        $this->log('Refund Result: ' . print_r($result, true));

        $result = json_decode($result, true);

        if ($result['status'] == 'ok') {
            $order = new WC_Order($order_id);
            $order->add_order_note(sprintf(__('Refund transaction ID: %s', 'woocommerce'), $result['transactionId']));
            $this->log(sprintf(__('Refund transaction ID: %s', 'woocemmerce'), $result['transactionId']));

            return true;
        }

        return false;
    }
}

/**
 * Add callback wc-api=wc_gateway_g2apay.
 *
 * @param array
 * @return array
 */
function woocommerce_add_g2apay_gateway($methods)
{
    $methods[] = 'WC_Gateway_G2APay';

    return $methods;
}

add_filter('woocommerce_payment_gateways', 'woocommerce_add_g2apay_gateway');
