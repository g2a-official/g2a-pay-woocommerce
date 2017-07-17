<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';

/**
 * Generates requests to send to G2A Pay.
 */
class WC_Gateway_G2APay_Checkout
{
    /**
     * Pointer to gateway making the request.
     * @var WC_Gateway_G2APay
     */
    protected $gateway;

    /**
     * Constructor.
     * @param WC_Gateway_G2APay $gateway
     */
    public function __construct($gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * Get the G2A Pay request URL for an order.
     * @param $order_id
     * @return array|int|void
     */
    public function create_checkout($order_id)
    {
        $error_message = '';

        $createQuote_url = $this->gateway->checkout_url . '/index/createQuote';
        $order           = new WC_Order($order_id);
        $post_data       = $this->create_post_data($order, $this->gateway);

        if ($order->get_total() == 0) {
            wc_add_notice(__('G2A Pay does not accept payment free.', 'woothemes') . $error_message, 'error');

            return;
        }

        $this->gateway->log('Checkout post data: ' . print_r($post_data, true));

        $params = [
            'body'        => $post_data,
            'sslverify'   => false,
            'timeout'     => 60,
            'httpversion' => '1.1',
            'compress'    => false,
            'decompress'  => false,
            'user-agent'  => 'WooCommerce/' . WC()->version . '/G2A Pay',
        ];

        $wp_response = wp_remote_post($createQuote_url, $params);

        $this->gateway->log('G2APay checkout response: ' . print_r($wp_response, true));

        if (is_array($wp_response)) {
            $response = json_decode($wp_response['body']);

            if (isset($response->token)) {
                $order->update_status('pending', __('Awaiting g2a.pay payment', 'woocommerce'));
                $this->gateway->log('Payment is pending status. Order #' . $order->id);
                $redirect_url = $this->gateway->checkout_url . '/index/gateway?token=' . $response->token;
                $result       = 'success';
            } else {
                wc_add_notice(__('Error G2A Pay service! Try again or select a different payment method. (Not get G2A Pay token!)', 'woothemes') . $error_message, 'error');
                $this->gateway->log('ERROR! Not get G2A Pay token!');

                return 0;
            }
        } else {
            wc_add_notice(__('Error G2A Pay service! Try again or select a different payment method. (Bad response from G2A Pay!)', 'woothemes') . $error_message, 'error');
            $this->gateway->log('ERROR! Bad response from G2A Pay!');

            return;
        }

        $this->gateway->log('Redirect to: ' . $redirect_url);

        return [
            'result'   => $result,
            'redirect' => $redirect_url,
        ];
    }

    /**
     * @param $order
     * @param $gateway
     * @return string
     */
    public function create_post_data($order, $gateway)
    {
        $order_id     = $order->id;
        $amount       = wc_format_decimal($order->get_total(), 2);
        $currency     = $order->get_order_currency();
        $hash         = hash('sha256', $order_id . $amount . $currency . $gateway->secret);
        $order_items  = $this->get_items($order);
        $subscription = $order_items['g2apay_subscription'];
        unset($order_items['g2apay_subscription']);
        $post_data = [
            'email'       => $order->billing_email,
            'api_hash'    => $gateway->api_hash,
            'hash'        => $hash,
            'amount'      => $amount,
            'currency'    => $currency,
            'items'       => $order_items,
            'description' => implode("\n", $order->get_customer_order_notes()),
            'order_id'    => $order_id,
            'url_failure' => $order->get_cancel_order_url(),
            'url_ok'      => $this->gateway->get_return_url($order),
        ];

        $addresses = $this->generate_addresses_array($order);

        if (G2APayHelper::validateAddresses($addresses)) {
            $post_data['addresses'] = $addresses;
        }

        if ($subscription) {
            $post_data['subscription']              = '1';
            $post_data['subscription_product_name'] = __('Monthly subscription based on order #', 'woocommerce')
                                                      . $order_id;
            $post_data['subscription_type']         = 'product';
            $post_data['subscription_period']       = 'monthly';
        }

        return http_build_query($post_data);
    }

    /**
     * @param $order
     * @return array
     */
    public function generate_addresses_array($order)
    {
        $addresses              = [];
        $order_shipping_address = $order->get_address('shipping');
        $order_billing_address  = $order->get_address('billing');

        $addresses['shipping'] = [
            'firstname' => $order_shipping_address['first_name'],
            'lastname'  => $order_shipping_address['last_name'],
            'line_1'    => $order_shipping_address['address_1'],
            'line_2'    => is_null($order_shipping_address['address_2']) ? '' : $order_shipping_address['address_2'],
            'zip_code'  => $order_shipping_address['postcode'],
            'company'   => is_null($order_shipping_address['company']) ? '' : $order_shipping_address['company'],
            'city'      => $order_shipping_address['city'],
            'county'    => $order_shipping_address['state'],
            'country'   => $order_shipping_address['country'],
        ];

        $addresses['billing'] = [
            'firstname' => $order_billing_address['first_name'],
            'lastname'  => $order_billing_address['last_name'],
            'line_1'    => $order_billing_address['address_1'],
            'line_2'    => is_null($order_billing_address['address_2']) ? '' : $order_billing_address['address_2'],
            'zip_code'  => $order_billing_address['postcode'],
            'company'   => is_null($order_billing_address['company']) ? '' : $order_billing_address['company'],
            'city'      => $order_billing_address['city'],
            'county'    => $order_billing_address['state'],
            'country'   => $order_billing_address['country'],
        ];

        return $addresses;
    }

    /**
     * For compatible older versions.
     *
     * @param $order
     * @return int
     */
    public function get_order_subtotal($order)
    {
        $subtotal = 0;
        // subtotal
        foreach ($order->get_items() as $item) {
            $subtotal += (isset($item['line_subtotal'])) ? $item['line_subtotal'] : 0;
        }

        return $subtotal;
    }

    /**
     * @param $order
     * @return array
     */
    public function get_items($order)
    {
        $data               = [];
        $order_total        = 0;
        $check_total_amount = 0;
        $subscription       = false;
        $number_of_products = 0;

        // add product
        foreach ($order->get_items() as $item_id => $item) {
            $product                = $order->get_product_from_item($item);
            $subscription_attribute = $product->get_attribute('g2apay_subscription');
            if (!empty($subscription_attribute)) {
                $subscription = true;
            }
            $amount  = $order->get_line_subtotal($item);

            if (!$product->get_sku()) {
                $sku = preg_replace('/[^a-z]/', '', strtolower($item['name']));
            } else {
                $sku = $product->get_sku();
            }

            // fix to bad round amount
            $order_total += $amount;
            if ($order_total > $this->get_order_subtotal($order)) {
                $amount -= $order_total - $this->get_order_subtotal($order);
            }

            $singlePrice = $product->get_price();
            if ($amount / $item['qty'] !== $product->get_price()) {
                $singlePrice = $amount / $item['qty'];
            }

            $number_of_products += $item['qty'];

            array_push($data, [
                'qty'    => $item['qty'],
                'name'   => $item['name'],
                'sku'    => $sku,
                'amount' => wc_format_decimal($amount, 2),
                'type'   => 'product',
                'id'     => $item['product_id'],
                'price'  => wc_format_decimal($singlePrice, 2),
                'url'    => $product->get_permalink(),
            ]);
            $check_total_amount += wc_format_decimal($amount, 2);
        }

        if ($subscription && $number_of_products > 1) {
            $subscription = false;
        }

        // add shipping
        foreach ($order->get_shipping_methods() as $shipping_item_id => $shipping_item) {
            array_push($data, [
                'qty'    => '1',
                'name'   => $shipping_item['name'],
                'sku'    => $shipping_item['method_id'],
                'amount' => wc_format_decimal($shipping_item['cost'], 2),
                'type'   => 'shipping',
                'id'     => $order->id,
                'price'  => wc_format_decimal($shipping_item['cost'], 2),
                'url'    => $order->get_view_order_url(),
            ]);
            $check_total_amount += wc_format_decimal($shipping_item['cost'], 2);
        }

        // add taxes
        foreach ($order->get_tax_totals() as $tax_code => $tax) {
            array_push($data, [
                'qty'    => '1',
                'name'   => $tax->label,
                'sku'    => $tax_code,
                'amount' => wc_format_decimal($tax->amount, 2),
                'type'   => 'tax',
                'id'     => $order->id,
                'price'  => wc_format_decimal($tax->amount, 2),
                'url'    => $order->get_view_order_url(),
            ]);
            $check_total_amount += wc_format_decimal($tax->amount, 2);
        }

        // add fees
        foreach ($order->get_fees() as $fee_item_id => $fee_item) {
            array_push($data, [
                'qty'    => '1',
                'name'   => $fee_item['name'],
                'sku'    => (!empty($fee_item['tax_class'])) ? $fee_item['tax_class'] : 'sku',
                'amount' => wc_format_decimal($order->get_line_total($fee_item), 2),
                'type'   => 'fee',
                'id'     => $order->id,
                'price'  => wc_format_decimal($order->get_line_total($fee_item), 2),
                'url'    => $order->get_view_order_url(),
            ]);
            $check_total_amount += wc_format_decimal($order->get_line_total($fee_item), 2);
        }

        // add coupons
        foreach ($order->get_items('coupon') as $coupon_item_id => $coupon_item) {
            array_push($data, [
                'qty'    => '1',
                'name'   => $coupon_item['name'],
                'sku'    => 'cpn',
                'amount' => wc_format_decimal($coupon_item['discount_amount'], 2) * -1,
                'type'   => 'coupon',
                'id'     => $order->id,
                'price'  => wc_format_decimal($coupon_item['discount_amount'], 2) * -1,
                'url'    => $order->get_view_order_url(),
            ]);
            $check_total_amount += wc_format_decimal($coupon_item['discount_amount'], 2) * -1;
        }

        // workarround to sum items amount != order amount
        if ((wc_format_decimal($check_total_amount, 2) != wc_format_decimal($order->get_total(), 2))) {
            array_push($data, [
                'qty'    => '1',
                'name'   => 'Default item',
                'sku'    => 'default',
                'amount' => wc_format_decimal($order->get_total(), 2) - wc_format_decimal($check_total_amount, 2),
                'type'   => 'item',
                'id'     => $order->id,
                'price'  => wc_format_decimal($order->get_total(), 2) - wc_format_decimal($check_total_amount, 2),
                'url'    => $order->get_view_order_url(),
            ]);
        }

        $data['g2apay_subscription'] = $subscription;

        return $data;
    }
}
