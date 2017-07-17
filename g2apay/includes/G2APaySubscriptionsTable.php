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

class G2APaySubscriptionsTable extends WP_List_Table
{
    public $per_page = 30;
    public $total_count;

    /**
     * G2APaySubscriptionsTable constructor.
     */
    public function __construct()
    {
        parent::__construct([
            'singular' => 'g2apay_payment_subscriptions_link',
            'plural'   => 'g2apay_payment_subscriptions_links',
            'ajax'     => false,
        ]);
    }

    /**
     * @return array
     */
    public function get_columns()
    {
        return array(
            'id'                => __('Id', 'woocommerce'),
            'subscription_name' => __('Subscription Name', 'woocommerce'),
            'subscription_id'   => __('G2A Pay Subscription id', 'woocommerce'),
            'related_order_id'  => __('Related Order Id', 'woocommerce'),
            'status'            => __('Status', 'woocommerce'),
            'amount'            => __('Subscription Monthly Amount', 'woocommerce'),
            'created_at'        => __('Created At', 'woocommerce'),
            'disabled_at'       => __('Cancelled At', 'woocommerce'),
            'action'            => __('Action', 'woocommerce'),
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
        $limit    = ($page && $page > 1) ? $per_page * ($page - 1) . ',' . $per_page : $per_page;

        $query = 'SELECT * 
                  FROM g2apay_subscriptions 
                  ORDER BY id DESC';

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
            case 'subscription_name':
            case 'status':
            case 'created_at':
            case 'disabled_at':
                return $item->$column_name;
            case 'amount':
                return G2APayHelper::getValidAmount($item->$column_name);
            case 'action':
                return $this->related_payments([
                    'Show Payments' => 'admin.php?page=g2apay_subscription_payments_page&order_id='
                        . $item->related_order_id,
                ]);
            default:
                return;
        }
    }

    /**
     * @param $actions
     * @return string|void
     */
    protected function related_payments($actions)
    {
        if (!count($actions)) {
            return;
        }

        $out = '';
        foreach ($actions as $action => $link) {
            $out .= '<form id="related_payments" action="' . $link . '" method="post">';
            $out .= '<input type="submit" value="' . $action . '" />';
            $out .= '</form>';

            return $out;
        }
    }
}
