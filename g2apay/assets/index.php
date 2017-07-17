<?php
/*
Plugin Name: WooCommerce G2A Pay Payment Gateway
Plugin URI: https://pay.g2a.com
Description: G2A Pay Payments for WooCommerce (G2A.PAY API v2).
Version: 1.1.2
Author: G2A Pay (integration@g2a.com - Gabriel Zima)
Author URI: https://pay.g2a.com
*/

function woocommerce_g2apay_init()
{
    if (class_exists('WC_Payment_Gateway')) {
        include_once 'class-wc-gateway-g2apay.php';
    }
}

add_action('plugins_loaded', 'woocommerce_g2apay_init', 0);
