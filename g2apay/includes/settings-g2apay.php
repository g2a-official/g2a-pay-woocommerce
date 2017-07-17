<?php

if (!defined('ABSPATH')) {
    exit;
}

$pluginDir = plugin_dir_url(__FILE__);
$pluginDIr = str_replace('includes/', '', $pluginDir);

$plugin_path = WC_HTTPS::force_https_url($pluginDIr);
$icon        = $plugin_path . 'assets/images/g2apay.png';

/*
 * Settings for G2A Pay Gateway
 */
return array(
    'enabled' => array(
        'title'   => __('Enable/Disable', 'woocommerce'),
        'type'    => 'checkbox',
        'label'   => __('Enable G2A Pay', 'woocommerce'),
        'default' => 'yes',
    ),
    'title' => array(
        'title'       => __('Title', 'woocommerce'),
        'type'        => 'text',
        'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
        'default'     => __('G2A Pay', 'woocommerce'),
        'desc_tip'    => true,
    ),
    'g2apayicon' => array(
        'title'   => __('G2A Pay icon', 'woocommerce'),
        'type'    => 'checkbox',
        'label'   => sprintf(__('Visible %s in payment methods at checkout.', 'woocommerce'), '<img src="' . $icon . '">'),
        'default' => 'yes',
    ),
    'description' => array(
        'title'       => __('Description', 'woocommerce'),
        'type'        => 'textarea',
        'desc_tip'    => true,
        'description' => __('This controls the description which the user sees during checkout.', 'woocommerce'),
        'default'     => __('All-in-one solution with 100+ global and local payment methods.', 'woocommerce'),
    ),
    'testmode' => array(
        'title' => __('G2A Pay Sandbox', 'woocommerce'),
        'type'  => 'checkbox',

        'label'       => __('Enable G2A Pay sandbox', 'woocommerce'),
        'default'     => 'no',
        'description' => sprintf(__('G2A Pay sandbox can be used to test payments. Sign up for a developer account <a href="%s" target="_blank">here</a>.', 'woocommerce'), 'https://www.test.pay.g2a.com/auth/register'),
    ),
    'debug' => array(
        'title'       => __('Debug Log', 'woocommerce'),
        'type'        => 'checkbox',
        'label'       => __('Enable logging', 'woocommerce'),
        'default'     => 'no', //,
        'description' => sprintf(__('Log G2A Pay events, inside <code>%s</code>', 'woocommerce'), trailingslashit(WC_LOG_DIR) . 'g2apay-' . sanitize_file_name(wp_hash('g2apay')) . '.log'),
    ),
    'api_details' => array(
        'title'       => __('API Credentials', 'woocommerce'),
        'type'        => 'title',
        'description' => sprintf(__('Enter your G2A Pay API credentials to process payment via G2A Pay. Learn how to access your G2A Pay API Credentials %shere%s.', 'woocommerce'), '<a href="https://pay.g2a.com/documentation#integration-credentials" target="_blank">', '</a>'),
    ),
    'api_hash' => array(
        'title'       => __('Your API Hash', 'woocommerce'),
        'type'        => 'text',
        'description' => __('Get your API key from https://pay.g2a.com.', 'woocommerce'),
        'default'     => '',
        'desc_tip'    => true,
        'placeholder' => __('Obligatory', 'woocommerce'),
    ),
    'merchant_email' => array(
        'title'       => __('Your e-mail in G2A Pay', 'woocommerce'),
        'type'        => 'text',
        'description' => __('Your e-mail in G2A Pay', 'woocommerce'),
        'default'     => '',
        'desc_tip'    => true,
        'placeholder' => __('Obligatory', 'woocommerce'),
    ),
    'secret' => array(
        'title'       => __('Your API Secret', 'woocommerce'),
        'type'        => 'text',
        'description' => __('Get your API secret from https://pay.g2a.com.', 'woocommerce'),
        'default'     => '',
        'desc_tip'    => true,
        'placeholder' => __('Obligatory', 'woocommerce'),
    ),
    'ipn' => array(
        'title'       => __('IPN', 'woocommerce'),
        'type'        => 'title',
        'description' => sprintf(__('Copy this URL %s and paste to section settings in G2A Pay merchant panel.', 'woocommerce'), '<code>' . get_site_url() . '/?wc-api=wc_gateway_g2apay</code>'),
    ),
);
