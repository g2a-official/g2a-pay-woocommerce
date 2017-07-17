<?php
/**
 * @author    G2A Team
 * @copyright Copyright (c) 2016 G2A.COM
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

// Load WP_List_Table if not loaded
if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}
require_once 'G2APayHelper.php';

class G2APaySubscriptionPaymentsTable extends WP_List_Table
{
    public $per_page = 30;
    public $total_count;

    /**
     * G2APaySubscriptionPaymentsTable constructor.
     */
    public function __construct()
    {
        parent::__construct([
            'singular' => 'g2apay_payment_subscription_payments_link',
            'plural'   => 'g2apay_payment_subscriptions_payments_links',
            'ajax'     => false,
        ]);
    }

    /**
     * @return array
     */
    public function get_columns()
    {
        return array(
            'id'               => __('Id', 'woocommerce'),
            'related_order_id' => __('Related Order Id', 'woocommerce'),
            'subscription_id'  => __('G2A Pay Subscription Id', 'woocommerce'),
            'status'           => __('Status', 'woocommerce'),
            'amount'           => __('Amount Paid', 'woocommerce'),
            'amount_refunded'  => __('Amount Refunded', 'woocommerce'),
            'date_paid'        => __('Date Paid', 'woocommerce'),
            'action'           => __('Action', 'woocommerce'),
        );
    }

    /**
     * @return array|null|object
     */
    public function g2apay_payments_data()
    {
        global $wpdb;

        $per_page = $this->per_page;
        $page     = isset($_GET['paged']) ? (int) $_GET['paged'] : null;
        $order_id = isset($_GET['order_id']) ? $_GET['order_id'] : null;
        $limit    = ($page && $page > 1) ? $per_page * ($page - 1) . ',' . $per_page : $per_page;

        $unprepared_query = 'SELECT * 
                             FROM g2apay_payments 
                             WHERE type=%s';

        if (is_numeric($order_id) && $order_id == (int) $order_id) {
            $unprepared_query .= ' AND related_order_id=' . $order_id;
        }

        $unprepared_query .= ' ORDER BY id DESC';

        $query = $wpdb->prepare($unprepared_query, G2APayHelper::IPN_SUBSCRIPTION_PAYMENT_TYPE_NAME);

        $this->total_count = $wpdb->query($query);

        $query .= ' LIMIT ' . $limit;

        $data = $wpdb->get_results($query);

        return $data;
    }

    /**
     * Prepare G2A Pay payment history table.
     */
    public function prepare_items()
    {
        $columns  = $this->get_columns();
        $hidden   = array(); // No hidden columns
        $sortable = array();
        $data     = $this->g2apay_payments_data();

        $this->_column_headers = array($columns, $hidden, $sortable);

        $this->items = $data;

        $this->set_pagination_args([
                'total_items' => $this->total_count,
                'per_page'    => $this->per_page,
                'total_pages' => ceil($this->total_count / $this->per_page),
            ]
        );
    }

    /**
     * @param object $item
     * @param string $column_name
     * @return string
     */
    public function column_default($item, $column_name)
    {
        switch ($column_name) {
            case 'related_order_id':
                return '<a href="post.php?post=' . $item->$column_name . '&action=edit">#'
                . $item->$column_name . '</a>';
            case 'id':
            case 'subscription_id':
            case 'status':
            case 'date_paid':
                return $item->$column_name;
            case 'amount':
                return G2APayHelper::getValidAmount($item->$column_name);
            case 'amount_refunded':
                return G2APayHelper::getValidAmount($item->$column_name);
            case 'action':
                return $this->render_row(['Refund' => '#'], $item->transaction_id);
            default:
                return;
        }
    }

    /**
     * @param array $actions
     * @param bool $transaction_id
     * @return string|void
     */
    protected function render_row($actions, $transaction_id)
    {
        if (!count($actions)) {
            return;
        }

        $out = '';
        foreach ($actions as $action => $link) {
            $out .= '<form id="refund" action="' . $link . '" method="post">';
            $out .= '<input type="hidden" id="transaction_id" name="transaction_id" value="' . $transaction_id . '"/>';
            $out .= '<input type="submit" value="' . $action . '" />';
            $out .= '</form>';

            return $out;
        }
    }
}
