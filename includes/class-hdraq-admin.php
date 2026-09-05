<?php

namespace htrxuan\hdraq;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * handle_respond() is the one place quoted_price/admin_note/status ever get written,
 * and it explicitly checks current_user_can('manage_woocommerce') plus a nonce before
 * touching anything -- not relying on the hub menu simply being hidden from lower-role
 * users, which is exactly the check a competing plugin's vulnerable handler omitted
 * (CVE-2026-84238). See class-hdraq-repository.php's class docblock for the full
 * picture of how this plugin closes that vulnerability class by construction.
 */
class HDRAQ_Admin
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
        require_once HDRAQ_PLUGIN_DIR . 'includes/class-hdraq-hub.php';
        add_filter('hdwebmobile_hub_tabs', array($this, 'register_hub_tabs'));
        add_action('admin_post_hdraq_respond', array($this, 'handle_respond'));
    }

    public function register_hub_tabs($tabs)
    {
        $tabs['request-a-quote'] = array(
            'label'  => __('Request a Quote', 'hdwebmobile-request-a-quote'),
            'order'  => 48,
            'render' => array($this, 'render_page'),
        );
        return $tabs;
    }

    public function render_page()
    {
        // Read-only navigation param selecting list-vs-detail view -- no state change
        // occurs from reading it, so nonce verification doesn't apply here.
        $quote_id = isset($_GET['quote_id']) ? absint($_GET['quote_id']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ($quote_id) {
            $this->render_detail_page($quote_id);
            return;
        }

        $this->render_list_page();
    }

    private function render_list_page()
    {
        require_once HDRAQ_PLUGIN_DIR . 'includes/class-hdraq-admin-list-table.php';

        $table = new HDRAQ_Admin_List_Table();
        $table->prepare_items();
        ?>
        <p><?php esc_html_e('Customers can request a custom price on any product; only your own signed-off response can ever change what they\'re charged.', 'hdwebmobile-request-a-quote'); ?></p>
        <form method="get">
            <input type="hidden" name="page" value="hdwebmobile" />
            <input type="hidden" name="tab" value="request-a-quote" />
            <?php
            $table->search_box(__('Search name or email', 'hdwebmobile-request-a-quote'), 'hdraq-search');
            $table->display();
            ?>
        </form>
        <?php
    }

    private function render_detail_page($quote_id)
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to do this.', 'hdwebmobile-request-a-quote'));
        }

        $quote = HDRAQ_Repository::find_by_id($quote_id);
        $back_url = admin_url('admin.php?page=hdwebmobile&tab=request-a-quote');

        if (!$quote) {
            echo '<p>' . esc_html__('Quote request not found.', 'hdwebmobile-request-a-quote') . '</p>';
            printf('<p><a href="%s">%s</a></p>', esc_url($back_url), esc_html__('&larr; Back to list', 'hdwebmobile-request-a-quote'));
            return;
        }

        $product = wc_get_product($quote->product_id);
        ?>
        <p><a href="<?php echo esc_url($back_url); ?>">&larr; <?php esc_html_e('Back to list', 'hdwebmobile-request-a-quote'); ?></a></p>
        <table class="widefat striped" style="max-width:600px;">
            <tbody>
                <tr><th><?php esc_html_e('Customer', 'hdwebmobile-request-a-quote'); ?></th><td><?php echo esc_html($quote->customer_name); ?> (<?php echo esc_html($quote->customer_email); ?>)</td></tr>
                <tr><th><?php esc_html_e('Product', 'hdwebmobile-request-a-quote'); ?></th><td><?php echo $product ? esc_html($product->get_name()) : esc_html__('(no longer available)', 'hdwebmobile-request-a-quote'); ?></td></tr>
                <tr><th><?php esc_html_e('Quantity', 'hdwebmobile-request-a-quote'); ?></th><td><?php echo esc_html($quote->quantity); ?></td></tr>
                <tr><th><?php esc_html_e('Customer message', 'hdwebmobile-request-a-quote'); ?></th><td><?php echo $quote->message ? nl2br(esc_html($quote->message)) : '&mdash;'; ?></td></tr>
                <tr><th><?php esc_html_e('Status', 'hdwebmobile-request-a-quote'); ?></th><td><?php echo esc_html($quote->status); ?></td></tr>
            </tbody>
        </table>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:1.5em;max-width:500px;">
            <input type="hidden" name="action" value="hdraq_respond" />
            <input type="hidden" name="quote_id" value="<?php echo esc_attr($quote->id); ?>" />
            <?php wp_nonce_field('hdraq_respond_' . $quote->id); ?>
            <p>
                <label for="hdraq-quoted-price"><?php esc_html_e('Quoted price (per unit)', 'hdwebmobile-request-a-quote'); ?></label><br />
                <input type="number" step="0.01" min="0.01" id="hdraq-quoted-price" name="quoted_price" class="regular-text" value="<?php echo esc_attr($quote->quoted_price); ?>" />
            </p>
            <p>
                <label for="hdraq-admin-note"><?php esc_html_e('Note to customer (optional)', 'hdwebmobile-request-a-quote'); ?></label><br />
                <textarea id="hdraq-admin-note" name="admin_note" rows="3" class="large-text"><?php echo esc_textarea($quote->admin_note); ?></textarea>
            </p>
            <p>
                <?php submit_button(__('Send Quote', 'hdwebmobile-request-a-quote'), 'primary', 'hdraq_submit_quoted', false); ?>
                <?php submit_button(__('Reject', 'hdwebmobile-request-a-quote'), 'delete', 'hdraq_submit_rejected', false); ?>
            </p>
        </form>
        <?php
    }

    public function handle_respond()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to do this.', 'hdwebmobile-request-a-quote'));
        }

        $quote_id = isset($_POST['quote_id']) ? absint($_POST['quote_id']) : 0;

        check_admin_referer('hdraq_respond_' . $quote_id);

        $quote = HDRAQ_Repository::find_by_id($quote_id);
        if (!$quote) {
            wp_die(esc_html__('Quote request not found.', 'hdwebmobile-request-a-quote'));
        }

        $status = isset($_POST['hdraq_submit_rejected']) ? HDRAQ_Repository::STATUS_REJECTED : HDRAQ_Repository::STATUS_QUOTED;

        $admin_note = isset($_POST['admin_note']) ? sanitize_textarea_field(wp_unslash($_POST['admin_note'])) : '';

        $quoted_price = null;
        if (HDRAQ_Repository::STATUS_QUOTED === $status) {
            $quoted_price = isset($_POST['quoted_price']) ? wc_format_decimal(sanitize_text_field(wp_unslash($_POST['quoted_price']))) : 0;
            if ($quoted_price <= 0) {
                wp_safe_redirect(add_query_arg(array('page' => 'hdwebmobile', 'tab' => 'request-a-quote', 'quote_id' => $quote_id, 'hdraq_error' => 'price'), admin_url('admin.php')));
                exit;
            }
        }

        HDRAQ_Repository::respond($quote_id, $quoted_price, $admin_note, $status);

        if (HDRAQ_Repository::STATUS_QUOTED === $status) {
            $this->notify_customer($quote);
        }

        wp_safe_redirect(add_query_arg(array('page' => 'hdwebmobile', 'tab' => 'request-a-quote', 'quote_id' => $quote_id, 'hdraq_responded' => 1), admin_url('admin.php')));
        exit;
    }

    /**
     * Plain text (wp_mail() default content type) -- the only dynamic value in this
     * email besides the customer's own already-known name is the view link, which is
     * a plain URL, not HTML -- so there is no output-escaping surface here.
     */
    private function notify_customer($quote)
    {
        $view_url = add_query_arg('hdraq_view', $quote->token, wc_get_page_permalink('myaccount'));

        $subject = __('Your quote is ready', 'hdwebmobile-request-a-quote');
        $body    = sprintf(
            /* translators: 1: customer name, 2: view link */
            __("Hi %1\$s,\n\nYour quote request has been reviewed. View it here:\n%2\$s", 'hdwebmobile-request-a-quote'),
            $quote->customer_name,
            $view_url
        );

        wp_mail($quote->customer_email, $subject, $body);
    }
}
