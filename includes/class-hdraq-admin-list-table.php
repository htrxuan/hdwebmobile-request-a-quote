<?php

namespace htrxuan\hdraq;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class HDRAQ_Admin_List_Table extends \WP_List_Table
{

    public function __construct()
    {
        parent::__construct(array(
            'singular' => 'quote_request',
            'plural'   => 'quote_requests',
            'ajax'     => false,
        ));
    }

    public function get_columns()
    {
        return array(
            'customer_name' => __('Customer', 'hdwebmobile-request-a-quote'),
            'product_id'    => __('Product', 'hdwebmobile-request-a-quote'),
            'quantity'      => __('Qty', 'hdwebmobile-request-a-quote'),
            'quoted_price'  => __('Quoted Price', 'hdwebmobile-request-a-quote'),
            'status'        => __('Status', 'hdwebmobile-request-a-quote'),
            'created_at'    => __('Requested', 'hdwebmobile-request-a-quote'),
        );
    }

    protected function get_sortable_columns()
    {
        return array(
            'customer_name' => array('customer_name', false),
            'quoted_price'  => array('quoted_price', false),
            'status'        => array('status', false),
            'created_at'    => array('created_at', true),
        );
    }

    protected function extra_tablenav($which)
    {
        if ('top' !== $which) {
            return;
        }

        // Read-only filter param, same as core WP_List_Table screens -- no state
        // change occurs from reading it, so nonce verification doesn't apply here.
        $current_status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $statuses        = array(
            'all'      => __('All statuses', 'hdwebmobile-request-a-quote'),
            'pending'  => __('Awaiting review', 'hdwebmobile-request-a-quote'),
            'quoted'   => __('Quoted', 'hdwebmobile-request-a-quote'),
            'rejected' => __('Rejected', 'hdwebmobile-request-a-quote'),
            'accepted' => __('Accepted (in cart)', 'hdwebmobile-request-a-quote'),
            'converted' => __('Ordered', 'hdwebmobile-request-a-quote'),
        );
        ?>
        <div class="alignleft actions">
            <select name="status">
                <?php foreach ($statuses as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($current_status, $value); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <?php submit_button(__('Filter', 'hdwebmobile-request-a-quote'), '', 'filter_action', false); ?>
        </div>
        <?php
    }

    public function column_customer_name($item)
    {
        $detail_url = add_query_arg(
            array('page' => 'hdwebmobile', 'tab' => 'request-a-quote', 'quote_id' => $item->id),
            admin_url('admin.php')
        );

        $out = esc_html($item->customer_name) . '<br /><span class="description">' . esc_html($item->customer_email) . '</span>';
        $out .= $this->row_actions(array(
            'respond' => sprintf('<a href="%s">%s</a>', esc_url($detail_url), esc_html__('View / Respond', 'hdwebmobile-request-a-quote')),
        ));

        return $out;
    }

    public function column_product_id($item)
    {
        $product = wc_get_product($item->product_id);
        return $product ? esc_html($product->get_name()) : esc_html__('(no longer available)', 'hdwebmobile-request-a-quote');
    }

    public function column_quoted_price($item)
    {
        return null === $item->quoted_price ? '&mdash;' : wp_kses_post(wc_price($item->quoted_price));
    }

    public function column_status($item)
    {
        $labels = array(
            'pending'   => __('Awaiting review', 'hdwebmobile-request-a-quote'),
            'quoted'    => __('Quoted', 'hdwebmobile-request-a-quote'),
            'rejected'  => __('Rejected', 'hdwebmobile-request-a-quote'),
            'accepted'  => __('Accepted (in cart)', 'hdwebmobile-request-a-quote'),
            'converted' => __('Ordered', 'hdwebmobile-request-a-quote'),
        );

        $label = isset($labels[$item->status]) ? $labels[$item->status] : $item->status;

        return sprintf('<span class="hdraq-status-%s">%s</span>', esc_attr($item->status), esc_html($label));
    }

    public function column_default($item, $column_name)
    {
        switch ($column_name) {
            case 'quantity':
                return esc_html($item->quantity);
            case 'created_at':
                return esc_html($item->created_at);
            default:
                return '';
        }
    }

    public function prepare_items()
    {
        $per_page = 20;
        $paged    = $this->get_pagenum();

        // Read-only filter/search/sort params for this list table -- same pattern as core
        // WP_List_Table screens; nothing here changes state, so no nonce is needed.
        $status  = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $search  = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $orderby = isset($_GET['orderby']) ? sanitize_text_field(wp_unslash($_GET['orderby'])) : 'created_at'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order   = isset($_GET['order']) ? sanitize_text_field(wp_unslash($_GET['order'])) : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $result = HDRAQ_Repository::get_for_list_table(array(
            'status'   => $status,
            's'        => $search,
            'per_page' => $per_page,
            'paged'    => $paged,
            'orderby'  => $orderby,
            'order'    => $order,
        ));

        $this->items = $result['items'];

        $this->_column_headers = array($this->get_columns(), array(), $this->get_sortable_columns());

        $this->set_pagination_args(array(
            'total_items' => $result['total'],
            'per_page'    => $per_page,
            'total_pages' => ceil($result['total'] / $per_page),
        ));
    }
}
