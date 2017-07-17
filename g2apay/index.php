<?php
/*
Plugin Name: WooCommerce G2A Pay Payment Gateway
Plugin URI: https://pay.g2a.com
Description: G2A Pay Payments for WooCommerce.
Version: 1.5
Author: G2A Team
Author URI: https://pay.g2a.com
*/
session_start();

require_once 'includes/G2APaySubscriptionsTable.php';
require_once 'includes/G2APaySubscriptionPaymentsTable.php';
require_once 'includes/G2APayException.php';
require_once 'includes/G2APayHelper.php';

function woocommerce_g2apay_init()
{
    if (class_exists('WC_Payment_Gateway')) {
        add_action('admin_menu', 'add_g2apay_subscription_submenu', 10);
        add_action('admin_menu', 'add_g2apay_subscription_payments_submenu', 20);
        include_once 'class-wc-gateway-g2apay.php';
    }
}

add_action('plugins_loaded', 'woocommerce_g2apay_init', 0);

function woocommerce_g2apay_install()
{
    install_g2apay_subscription_attribute();
    install_g2apay_db_table();
}

register_activation_hook(__FILE__, 'woocommerce_g2apay_install');

function install_g2apay_subscription_attribute()
{
    global $wpdb;

    $prefix = $wpdb->prefix;

    $result = $wpdb->get_results('SELECT count("attribute_id") as subscription_exists 
                                                   FROM ' . $prefix . 'woocommerce_attribute_taxonomies 
                                                   WHERE attribute_name="g2apay_subscription"');
    if ($result[0]->subscription_exists == 0) {
        $wpdb->insert($prefix . 'woocommerce_attribute_taxonomies',
            array(
                'attribute_name'    => 'g2apay_subscription',
                'attribute_label'   => 'G2A Pay Subscription',
                'attribute_type'    => 'text',
                'attribute_orderby' => 'menu_order',
                'attribute_public'  => 0,
            ));
    }
}

function install_g2apay_db_table()
{
    global $wpdb;

    $wpdb->query('CREATE TABLE IF NOT EXISTS g2apay_subscriptions
                  (`id` INT NOT NULL AUTO_INCREMENT , `related_order_id` INT NOT NULL , 
                  `subscription_name` VARCHAR(255) NOT NULL,
                  `transaction_id` VARCHAR(70) NOT NULL , `subscription_id` VARCHAR(70) NOT NULL , 
                  `status` VARCHAR(30) NOT NULL , `amount` FLOAT NOT NULL DEFAULT \'0\' , 
                  `created_at` DATETIME NOT NULL, `disabled_at` DATETIME DEFAULT NULL , 
                  PRIMARY KEY (`id`))');

    $wpdb->query('CREATE TABLE IF NOT EXISTS g2apay_payments
                  (`id` INT NOT NULL AUTO_INCREMENT , `related_order_id` INT DEFAULT 0, 
                  `transaction_id` VARCHAR(70) NOT NULL , `subscription_id` VARCHAR(70) , 
                  `status` VARCHAR(30) NOT NULL , `type` VARCHAR(30) NOT NULL , 
                  `amount` FLOAT NOT NULL DEFAULT \'0\' , `amount_refunded` FLOAT NOT NULL DEFAULT \'0\' , 
                  `date_paid` DATETIME NOT NULL , 
                  PRIMARY KEY (`id`))');
}

function add_g2apay_subscription_submenu()
{
    add_submenu_page('woocommerce',
        __('G2A Pay Subscriptions', 'woocommerce'),
        __('G2A Pay Subscriptions', 'woocommerce'),
        'manage_woocommerce',
        'g2apay_subscriptions_page',
        'g2apay_subscriptions_page');
}

function g2apay_subscriptions_page()
{
    echo '<div style="margin-right: 30px" class="g2apay_subscriptions">
              <h1>G2A Pay Subscriptions</h1>';
    $table = new G2APaySubscriptionsTable();
    $table->prepare_items();
    $table->display();
    echo '</div>';
}

function add_g2apay_subscription_payments_submenu()
{
    add_submenu_page('woocommerce',
        __('G2A Pay Subscription Payments', 'woocommerce'),
        __('G2A Pay Subscription Payments', 'woocommerce'),
        'manage_woocommerce',
        'g2apay_subscription_payments_page',
        'g2apay_subscription_payments_page');
}

function g2apay_subscription_payments_page()
{
    if ($message = G2APayHelper::getSessionMessage('error')) {
        g2apay_admin_notice($message, false);
        G2APayHelper::unsetSessionMessage('error');
    }

    if ($message = G2APayHelper::getSessionMessage('success')) {
        g2apay_admin_notice($message);
        G2APayHelper::unsetSessionMessage('success');
    }

    if (!isset($_POST['transaction_id'])) {
        echo '<div style="margin-right: 30px" class="g2apay_subscription_payments">
              <h1>G2A Pay Subscription Payments</h1>';
        $table = new G2APaySubscriptionPaymentsTable();
        $table->prepare_items();
        $table->display();
        echo '</div>';

        return;
    }

    $transaction_id = html_entity_decode($_POST['transaction_id']);
    if (isset($_POST['refund_amount'])) {
        g2apay_proceed_subscription_refund($_POST['refund_amount'], $transaction_id);
    }
    $g2apay_ipn       = G2APayHelper::getIpnByTransactionId($transaction_id);
    $max_refund_value = $g2apay_ipn[0]->amount - $g2apay_ipn[0]->amount_refunded;

    echo '<div id="refund_div">
        <h1>Refund for subscription id: ' . $g2apay_ipn[0]->subscription_id . '</h1><br />
        <form id="refund_form" action="#" method="post">
            <input type="hidden" id="transaction_id" name="transaction_id" value="' . $transaction_id . '">
            <input type="hidden" id="order_id" name="order_id" value="' . $g2apay_ipn[0]->related_order_id . '">
            <label for="refund_amount" id="refund_amount_label">Refund Amount (max: ' . $max_refund_value . ')</label>
            <input type="text" id="refund_amount" name="refund_amount" required /><br />
            <input style="margin-top: 10px" onclick="this.form.submit(); this.disabled=true;" type="submit" 
            id="proceed_refund" value="Refund">
        </form>
    </div>';
}

/**
 * @param $refund_amount
 * @param $transaction_id
 */
function g2apay_proceed_subscription_refund($refund_amount, $transaction_id)
{
    try {
        $refund_amount = str_replace(',', '.', $refund_amount);
        $g2apay_ipn    = G2APayHelper::getIpnByTransactionId($transaction_id);
        if ($message = g2apay_validate_subscription_refund($refund_amount, $g2apay_ipn)) {
            throw new G2APayException($message);
        }
        $order_id = $g2apay_ipn[0]->related_order_id;

        $g2apay_refund = new WC_Gateway_G2APay_Refund(new WC_Gateway_G2APay);

        $success = $g2apay_refund->refund_order($order_id, $refund_amount, '', $transaction_id);

        if (!$success || is_wp_error($success)) {
            throw new G2APayException(print_r($success, true));
        }
        G2APayHelper::setSessionMessage(__('Refund successfully for amount: ') . $refund_amount, 'success');
        refreshPage();
    } catch (G2APayException $e) {
        G2APayHelper::setSessionMessage($e->getMessage(), 'error');
        refreshPage();
    }
}

/**
 * @param $refund_amount
 * @param $g2apay_ipn
 * @return bool
 */
function g2apay_validate_subscription_refund($refund_amount, $g2apay_ipn)
{
    try {
        $max_refund_value = $g2apay_ipn[0]->amount - $g2apay_ipn[0]->amount_refunded;
        if (!is_numeric($refund_amount) || $refund_amount <= 0) {
            throw new G2APayException('You must specify a positive numeric amount to refund.');
        }
        if ($refund_amount > $max_refund_value) {
            throw new G2APayException('You cannot refund more than it was paid.');
        }

        return false;
    } catch (G2APayException $e) {
        return $e->getMessage();
    }
}

/**
 * Reloads current page.
 */
function refreshPage()
{
    echo '<script>location.reload();</script>';
}

/**
 * @param $message
 * @param bool $success
 */
function g2apay_admin_notice($message, $success = true)
{
    $class   = $success ? 'notice notice-success is-dismissible' : 'notice notice-error';
    $message = __($message, 'easy-digital-downloads');

    printf('<div class="%1$s"><p>%2$s</p></div>', $class, $message);
}
