<?php

namespace htrxuan\hdraq;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The only lookup this class performs is find_for_customer(get_current_user_id()) --
 * always the currently-authenticated user's own id, never a value taken from the
 * request -- so a customer can only ever see quotes they themselves submitted while
 * logged in. Anonymous/guest quote requests are still viewable, just via the emailed
 * token link instead (see class-hdraq-frontend.php's render_quote_view()).
 */
final class HDRAQ_MyAccount
{

    const ENDPOINT = 'quote-requests';

    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', array($this, 'add_endpoint'));
        add_filter('query_vars', array($this, 'add_query_var'));
        add_filter('woocommerce_account_menu_items', array($this, 'add_menu_item'));
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', array($this, 'render_endpoint_content'));
    }

    public function add_endpoint()
    {
        add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);
    }

    public function add_query_var($vars)
    {
        $vars[] = self::ENDPOINT;
        return $vars;
    }

    public function add_menu_item($items)
    {
        $new_items = array();
        foreach ($items as $key => $label) {
            $new_items[$key] = $label;
            if ('orders' === $key) {
                $new_items[self::ENDPOINT] = __('Quote Requests', 'hdwebmobile-request-a-quote');
            }
        }
        if (!isset($new_items[self::ENDPOINT])) {
            $new_items[self::ENDPOINT] = __('Quote Requests', 'hdwebmobile-request-a-quote');
        }
        return $new_items;
    }

    public function render_endpoint_content()
    {
        $quotes = HDRAQ_Repository::find_for_customer(get_current_user_id());

        echo '<h2>' . esc_html__('Quote Requests', 'hdwebmobile-request-a-quote') . '</h2>';

        if (empty($quotes)) {
            echo '<p>' . esc_html__('You haven\'t requested any quotes yet.', 'hdwebmobile-request-a-quote') . '</p>';
            return;
        }

        $labels = array(
            HDRAQ_Repository::STATUS_PENDING   => __('Awaiting review', 'hdwebmobile-request-a-quote'),
            HDRAQ_Repository::STATUS_QUOTED    => __('Quoted -- ready to view', 'hdwebmobile-request-a-quote'),
            HDRAQ_Repository::STATUS_REJECTED  => __('Not approved', 'hdwebmobile-request-a-quote'),
            HDRAQ_Repository::STATUS_ACCEPTED  => __('Added to cart', 'hdwebmobile-request-a-quote'),
            HDRAQ_Repository::STATUS_CONVERTED => __('Ordered', 'hdwebmobile-request-a-quote'),
        );

        $account_url = wc_get_page_permalink('myaccount');

        echo '<table class="woocommerce-table woocommerce-table--quote-requests shop_table shop_table_responsive my_account_quotes">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Product', 'hdwebmobile-request-a-quote') . '</th>';
        echo '<th>' . esc_html__('Quantity', 'hdwebmobile-request-a-quote') . '</th>';
        echo '<th>' . esc_html__('Requested', 'hdwebmobile-request-a-quote') . '</th>';
        echo '<th>' . esc_html__('Status', 'hdwebmobile-request-a-quote') . '</th>';
        echo '<th>&nbsp;</th>';
        echo '</tr></thead><tbody>';

        foreach ($quotes as $quote) {
            $product = wc_get_product($quote->product_id);
            $label   = isset($labels[$quote->status]) ? $labels[$quote->status] : $quote->status;
            $view_url = add_query_arg('hdraq_view', $quote->token, $account_url);

            echo '<tr>';
            printf('<td>%s</td>', $product ? esc_html($product->get_name()) : esc_html__('(no longer available)', 'hdwebmobile-request-a-quote'));
            printf('<td>%s</td>', esc_html($quote->quantity));
            printf('<td>%s</td>', esc_html(date_i18n(get_option('date_format'), strtotime($quote->created_at))));
            printf('<td>%s</td>', esc_html($label));
            printf('<td><a class="woocommerce-button button wp-element-button" href="%s">%s</a></td>', esc_url($view_url), esc_html__('View', 'hdwebmobile-request-a-quote'));
            echo '</tr>';
        }

        echo '</tbody></table>';
    }
}
