<?php

namespace htrxuan\hdraq;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Every customer-facing screen this class renders is reached one of two ways, and
 * both are safe by construction:
 *   - "My Quote Requests" (see class-hdraq-myaccount.php) is scoped by
 *     get_current_user_id() at the database layer, so a customer can only ever list
 *     rows they submitted while logged in.
 *   - render_quote_view()/handle_accept_submission() below require the quote's own
 *     unguessable token (HDRAQ_Repository::find_by_token()) to be presented in the
 *     request -- there is no view or accept path here that accepts a plain numeric
 *     quote id from the customer side. This is the direct fix for CVE-2026-84238
 *     (CWE-862, Missing Authorization) in a competing "Request a Quote" plugin.
 */
final class HDRAQ_Frontend
{

    const NONCE_REQUEST = 'hdraq_request';
    const NONCE_ACCEPT  = 'hdraq_accept';

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
        add_action('woocommerce_single_product_summary', array($this, 'render_form'), 36);
        add_action('template_redirect', array($this, 'handle_request_submission'));
        add_action('template_redirect', array($this, 'handle_accept_submission'), 5);
        add_action('template_redirect', array($this, 'render_quote_view'), 20);
        add_action('wp_enqueue_scripts', array($this, 'maybe_enqueue_assets'));
    }

    public function maybe_enqueue_assets()
    {
        if (is_product() || is_account_page()) {
            wp_enqueue_style('hdraq-frontend', HDRAQ_PLUGIN_URL . 'assets/css/hdraq-frontend.css', array(), HDRAQ_VERSION);
        }
    }

    public function render_form()
    {
        global $product;
        if (!$product) {
            return;
        }

        if (isset($_GET['hdraq_sent'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag from a post/redirect/get after the actual submission already passed nonce verification in handle_request_submission().
            echo '<div class="hdraq-notice hdraq-success">' . esc_html__('Thanks! Your quote request has been sent -- we\'ll email you once it\'s ready.', 'hdwebmobile-request-a-quote') . '</div>';
            return;
        }

        $current_user = wp_get_current_user();
        $default_name  = $current_user->exists() ? $current_user->display_name : '';
        $default_email = $current_user->exists() ? $current_user->user_email : '';

        echo '<div class="hdraq-quote-box">';
        echo '<h3>' . esc_html__('Request a custom quote', 'hdwebmobile-request-a-quote') . '</h3>';
        echo '<form method="post" class="hdraq-request-form">';
        wp_nonce_field(self::NONCE_REQUEST, 'hdraq_nonce');
        echo '<input type="hidden" name="hdraq_action" value="request" />';
        printf('<input type="hidden" name="hdraq_product_id" value="%d" />', esc_attr($product->get_id()));

        // Honeypot: a field real visitors never see or fill, hidden purely with CSS
        // (not type="hidden", since some bots skip those) -- any non-empty value here
        // means the submission is automated, so it is silently dropped in
        // handle_request_submission() without ever touching the database.
        echo '<div class="hdraq-honeypot" aria-hidden="true"><label>' . esc_html__('Leave this field empty', 'hdwebmobile-request-a-quote') . '<input type="text" name="hdraq_website" tabindex="-1" autocomplete="off" /></label></div>';

        printf(
            '<p><label for="hdraq_quantity">%s</label><input type="number" id="hdraq_quantity" name="hdraq_quantity" min="1" step="1" value="1" required /></p>',
            esc_html__('Quantity', 'hdwebmobile-request-a-quote')
        );
        printf(
            '<p><label for="hdraq_name">%s</label><input type="text" id="hdraq_name" name="hdraq_name" value="%s" required /></p>',
            esc_html__('Your name', 'hdwebmobile-request-a-quote'),
            esc_attr($default_name)
        );
        printf(
            '<p><label for="hdraq_email">%s</label><input type="email" id="hdraq_email" name="hdraq_email" value="%s" required /></p>',
            esc_html__('Your email', 'hdwebmobile-request-a-quote'),
            esc_attr($default_email)
        );
        printf(
            '<p><label for="hdraq_message">%s</label><textarea id="hdraq_message" name="hdraq_message" rows="3"></textarea></p>',
            esc_html__('Anything we should know? (optional)', 'hdwebmobile-request-a-quote')
        );
        echo '<button type="submit" class="woocommerce-button button wp-element-button">' . esc_html__('Request a quote', 'hdwebmobile-request-a-quote') . '</button>';
        echo '</form>';
        echo '</div>';
    }

    public function handle_request_submission()
    {
        $action = isset($_POST['hdraq_action']) ? sanitize_text_field(wp_unslash($_POST['hdraq_action'])) : '';
        if (!is_product() || 'request' !== $action) {
            return;
        }

        if (!isset($_POST['hdraq_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hdraq_nonce'])), self::NONCE_REQUEST)) {
            return;
        }

        if (!empty($_POST['hdraq_website'])) {
            return; // Honeypot tripped -- treat as spam, no error shown to avoid tipping off the bot.
        }

        $product_id = isset($_POST['hdraq_product_id']) ? absint($_POST['hdraq_product_id']) : 0;
        $product    = $product_id ? wc_get_product($product_id) : null;
        if (!$product) {
            return;
        }

        $quantity = isset($_POST['hdraq_quantity']) ? absint($_POST['hdraq_quantity']) : 1;
        $quantity = max(1, $quantity);

        $name    = isset($_POST['hdraq_name']) ? sanitize_text_field(wp_unslash($_POST['hdraq_name'])) : '';
        $email   = isset($_POST['hdraq_email']) ? sanitize_email(wp_unslash($_POST['hdraq_email'])) : '';
        $message = isset($_POST['hdraq_message']) ? sanitize_textarea_field(wp_unslash($_POST['hdraq_message'])) : '';

        if ('' === $name || !is_email($email)) {
            return;
        }

        $user_id = get_current_user_id();
        HDRAQ_Repository::create($product_id, $quantity, $user_id, $name, $email, $message);

        $this->notify_admin($product, $quantity, $name, $email, $message);

        wp_safe_redirect(add_query_arg('hdraq_sent', 1, get_permalink($product_id)));
        exit;
    }

    /**
     * Accept must run BEFORE render_quote_view() so a successful accept redirects
     * straight to the cart instead of re-rendering the (now stale) quote view.
     */
    public function handle_accept_submission()
    {
        if (!is_account_page() || !isset($_POST['hdraq_action']) || 'accept' !== $_POST['hdraq_action']) {
            return;
        }

        $account_url = wc_get_page_permalink('myaccount');
        $token       = isset($_POST['hdraq_token']) ? sanitize_text_field(wp_unslash($_POST['hdraq_token'])) : '';

        if (!isset($_POST['hdraq_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hdraq_nonce'])), self::NONCE_ACCEPT . '_' . $token)) {
            return;
        }

        $quote = HDRAQ_Repository::find_by_token($token);

        if (!$quote || HDRAQ_Repository::STATUS_QUOTED !== $quote->status || null === $quote->quoted_price) {
            wc_add_notice(__('That quote is no longer available.', 'hdwebmobile-request-a-quote'), 'error');
            wp_safe_redirect(add_query_arg('hdraq_view', $token, $account_url));
            exit;
        }

        $product = wc_get_product($quote->product_id);
        if (!$product) {
            wc_add_notice(__('The quoted product is no longer available.', 'hdwebmobile-request-a-quote'), 'error');
            wp_safe_redirect(add_query_arg('hdraq_view', $token, $account_url));
            exit;
        }

        // The quoted price is looked up fresh from the DB above and passed only as
        // cart-item DATA (a token to re-verify against on every totals recalculation
        // in class-hdraq-cart.php) -- never as a directly-trusted price argument.
        $added = WC()->cart->add_to_cart($quote->product_id, (int) $quote->quantity, 0, array(), array('hdraq_token' => $token));

        if (!$added) {
            wp_safe_redirect(add_query_arg('hdraq_view', $token, $account_url));
            exit;
        }

        HDRAQ_Repository::mark_accepted($quote->id);

        wp_safe_redirect(wc_get_cart_url());
        exit;
    }

    /**
     * Short-circuits the entire My Account page render when a quote token is present
     * in the URL, so this works identically whether the visitor is logged in or not --
     * WooCommerce's own login-form template is never involved, sidestepping the
     * nested-<form> HTML pitfall entirely rather than working around it.
     */
    public function render_quote_view()
    {
        if (!is_account_page() || !isset($_GET['hdraq_view'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only token-lookup selector; the token itself is the credential (see class header), not something a nonce would add security to.
            return;
        }

        $token = sanitize_text_field(wp_unslash($_GET['hdraq_view'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
        $quote = HDRAQ_Repository::find_by_token($token);

        get_header();
        echo '<div class="entry-content"><div class="woocommerce"><div class="hdraq-quote-view">';

        if (!$quote) {
            echo '<p>' . esc_html__('Quote not found.', 'hdwebmobile-request-a-quote') . '</p>';
        } else {
            $this->render_quote_view_content($quote, $token);
        }

        echo '</div></div></div>';
        get_footer();
        exit;
    }

    private function render_quote_view_content($quote, $token)
    {
        $product = wc_get_product($quote->product_id);
        $notices = wc_get_notices('error');
        wc_clear_notices();

        echo '<h1>' . esc_html__('Your Quote Request', 'hdwebmobile-request-a-quote') . '</h1>';

        foreach ($notices as $notice) {
            echo '<div class="woocommerce-error">' . wp_kses_post($notice['notice']) . '</div>';
        }

        echo '<table class="woocommerce-table shop_table"><tbody>';
        printf('<tr><th>%s</th><td>%s</td></tr>', esc_html__('Product', 'hdwebmobile-request-a-quote'), $product ? esc_html($product->get_name()) : esc_html__('(no longer available)', 'hdwebmobile-request-a-quote'));
        printf('<tr><th>%s</th><td>%s</td></tr>', esc_html__('Quantity', 'hdwebmobile-request-a-quote'), esc_html($quote->quantity));

        switch ($quote->status) {
            case HDRAQ_Repository::STATUS_PENDING:
                printf('<tr><th>%s</th><td>%s</td></tr>', esc_html__('Status', 'hdwebmobile-request-a-quote'), esc_html__('Awaiting review', 'hdwebmobile-request-a-quote'));
                break;
            case HDRAQ_Repository::STATUS_REJECTED:
                printf('<tr><th>%s</th><td>%s</td></tr>', esc_html__('Status', 'hdwebmobile-request-a-quote'), esc_html__('Not approved', 'hdwebmobile-request-a-quote'));
                break;
            case HDRAQ_Repository::STATUS_ACCEPTED:
                printf('<tr><th>%s</th><td>%s</td></tr>', esc_html__('Status', 'hdwebmobile-request-a-quote'), esc_html__('Added to your cart', 'hdwebmobile-request-a-quote'));
                break;
            case HDRAQ_Repository::STATUS_CONVERTED:
                printf('<tr><th>%s</th><td>%s</td></tr>', esc_html__('Status', 'hdwebmobile-request-a-quote'), esc_html__('Already ordered', 'hdwebmobile-request-a-quote'));
                break;
            case HDRAQ_Repository::STATUS_QUOTED:
                printf('<tr><th>%s</th><td>%s</td></tr>', esc_html__('Quoted price (per unit)', 'hdwebmobile-request-a-quote'), wp_kses_post(wc_price($quote->quoted_price)));
                break;
        }

        if (!empty($quote->admin_note)) {
            printf('<tr><th>%s</th><td>%s</td></tr>', esc_html__('Note from the seller', 'hdwebmobile-request-a-quote'), esc_html($quote->admin_note));
        }

        echo '</tbody></table>';

        if (HDRAQ_Repository::STATUS_QUOTED === $quote->status && $product) {
            echo '<form method="post" class="hdraq-accept-form">';
            wp_nonce_field(self::NONCE_ACCEPT . '_' . $token, 'hdraq_nonce');
            echo '<input type="hidden" name="hdraq_action" value="accept" />';
            printf('<input type="hidden" name="hdraq_token" value="%s" />', esc_attr($token));
            echo '<button type="submit" class="woocommerce-button button wp-element-button">' . esc_html__('Accept & add to cart', 'hdwebmobile-request-a-quote') . '</button>';
            echo '</form>';
        }
    }

    /**
     * Plain text (wp_mail() default content type) -- there is no HTML-rendering
     * surface in this email, so no output-escaping is needed here.
     */
    private function notify_admin($product, $quantity, $name, $email, $message)
    {
        $subject = sprintf(
            /* translators: %s: product name */
            __('New quote request: %s', 'hdwebmobile-request-a-quote'),
            $product->get_name()
        );

        $body = sprintf(
            "%s (%s) requested a quote for %d x \"%s\".\n\nMessage: %s\n\nRespond from WooCommerce > HDWebmobile > Request a Quote.",
            $name,
            $email,
            $quantity,
            $product->get_name(),
            $message ?: '(none)'
        );

        wp_mail(get_option('admin_email'), $subject, $body);
    }
}
