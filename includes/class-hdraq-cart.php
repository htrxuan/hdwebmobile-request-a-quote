<?php

namespace htrxuan\hdraq;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * All price truth lives here, re-read fresh from the database on every totals pass --
 * the cart item only ever carries a token, never a price. Even though the token was
 * already verified once in class-hdraq-frontend.php's accept handler before the item
 * was added to the cart, adjust_price() re-verifies the quote's status independently
 * rather than trusting that the cart item's mere presence implies a valid quote --
 * the same "never trust anything the client could have influenced" discipline
 * class-hdpo-cart.php applies to its own option pricing.
 */
class HDRAQ_Cart
{

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
        add_filter('woocommerce_get_item_data', array($this, 'get_item_data'), 10, 2);
        add_action('woocommerce_before_calculate_totals', array($this, 'adjust_price'));
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'add_order_line_item_meta'), 10, 4);
        // The order has no id yet at woocommerce_checkout_create_order_line_item time (it
        // is only assigned once $order->save() runs), so mark_converted() -- which needs a
        // real order id to stamp -- happens from these two hooks instead, once the order is
        // fully persisted: classic checkout and the Store API (block checkout) each fire
        // their own version, since woocommerce_checkout_order_processed alone is classic-only.
        add_action('woocommerce_checkout_order_processed', array($this, 'finalize_classic_order'), 10, 3);
        add_action('woocommerce_store_api_checkout_order_processed', array($this, 'finalize_order'));
    }

    public function get_item_data($item_data, $cart_item)
    {
        if (empty($cart_item['hdraq_token'])) {
            return $item_data;
        }

        $item_data[] = array(
            'key'   => __('Quoted price', 'hdwebmobile-request-a-quote'),
            'value' => __('Yes', 'hdwebmobile-request-a-quote'),
        );

        return $item_data;
    }

    public function adjust_price($cart)
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        foreach ($cart->get_cart() as $cart_item) {
            if (empty($cart_item['hdraq_token'])) {
                continue;
            }

            $quote = HDRAQ_Repository::find_by_token($cart_item['hdraq_token']);

            if (!$quote || null === $quote->quoted_price) {
                continue;
            }

            if (!in_array($quote->status, array(HDRAQ_Repository::STATUS_ACCEPTED, HDRAQ_Repository::STATUS_CONVERTED), true)) {
                continue;
            }

            $cart_item['data']->set_price((float) $quote->quoted_price);
        }
    }

    /**
     * Fires for both classic and block-based checkout (Store API shares the same
     * order-line-item-creation code path). Only stamps line-item meta here -- the order
     * has no id yet at this point, so the actual mark_converted() call is deferred to
     * finalize_order()/finalize_classic_order() below, once the order is saved.
     */
    public function add_order_line_item_meta($item, $cart_item_key, $values, $order)
    {
        if (empty($values['hdraq_token'])) {
            return;
        }

        $quote = HDRAQ_Repository::find_by_token($values['hdraq_token']);
        if (!$quote) {
            return;
        }

        $item->add_meta_data(__('Quote reference', 'hdwebmobile-request-a-quote'), '#' . $quote->id, true);
        // Leading underscore: hidden order-item meta, not shown to the customer -- purely
        // so finalize_order() below can find its way back to the right quote row.
        $item->add_meta_data('_hdraq_quote_id', $quote->id, true);
    }

    public function finalize_classic_order($order_id, $posted_data, $order)
    {
        $this->finalize_order($order);
    }

    public function finalize_order($order)
    {
        foreach ($order->get_items() as $item) {
            $quote_id = $item->get_meta('_hdraq_quote_id');
            if (!$quote_id) {
                continue;
            }
            HDRAQ_Repository::mark_converted((int) $quote_id, $order->get_id());
        }
    }
}
