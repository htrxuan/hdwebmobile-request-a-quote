<?php

namespace htrxuan\hdraq;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers this plugin's own copy of the shared WooCommerce > HDWebmobile hub page.
 * Every HDWebmobile plugin that contributes a tab carries its own uniquely-named,
 * uniquely-namespaced copy of this class (no two HDWebmobile plugins share a class
 * name), so there is no redeclaration risk and no class_exists()/function_exists()
 * guard is needed on it. Coordination happens at runtime instead: maybe_register()
 * inspects WordPress's own live $submenu state and only calls add_submenu_page() if
 * no HDWebmobile plugin has already registered the page this request, so exactly one
 * "HDWebmobile" menu item ever appears regardless of which subset of plugins is
 * active or their load order -- every plugin's own copy renders identically (driven
 * by the shared hdwebmobile_hub_tabs filter), so it never matters which one "wins".
 *
 * The "Overview" tab strings are deliberately not passed through __()/_e() -- this
 * file's content is duplicated (with per-plugin naming) into every plugin's zip, and
 * since it doesn't belong to any single plugin's text domain, translating it
 * correctly per-copy isn't possible. Every other tab's own content is still fully
 * translatable in that plugin's own text domain, as before.
 */
final class HDRAQ_Hub
{

    public static function init()
    {
        add_action('admin_menu', array(__CLASS__, 'maybe_register'));
    }

    public static function maybe_register()
    {
        global $submenu;
        if (isset($submenu['woocommerce'])) {
            foreach ($submenu['woocommerce'] as $item) {
                if ('hdwebmobile' === $item[2]) {
                    return;
                }
            }
        }

        add_submenu_page(
            'woocommerce',
            'HDWebmobile',
            'HDWebmobile',
            'manage_woocommerce',
            'hdwebmobile',
            array(__CLASS__, 'render')
        );
    }

    public static function render()
    {
        // Intentionally shared across every HDWebmobile plugin so they can all
        // contribute a tab to one common page -- not a candidate for a per-plugin
        // prefix, since a per-plugin name would defeat the cross-plugin coordination
        // this hook exists for.
        $tabs = apply_filters('hdwebmobile_hub_tabs', array()); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
        uasort($tabs, function ($a, $b) {
            return ($a['order'] ?? 10) <=> ($b['order'] ?? 10);
        });

        // The Overview tab always leads, regardless of what other tabs register.
        $tabs = array('overview' => array('label' => 'Overview', 'render' => array(__CLASS__, 'render_overview'))) + $tabs;

        // Read-only tab selector, same pattern as core's own admin tab UIs -- no state
        // change occurs from reading it, so nonce verification doesn't apply here.
        $current = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!$current || !isset($tabs[$current])) {
            $current = 'overview';
        }

        echo '<div class="wrap"><h1>HDWebmobile</h1><h2 class="nav-tab-wrapper">';
        foreach ($tabs as $slug => $tab) {
            printf(
                '<a href="%s" class="nav-tab%s">%s</a>',
                esc_url(admin_url('admin.php?page=hdwebmobile&tab=' . $slug)),
                $slug === $current ? ' nav-tab-active' : '',
                esc_html($tab['label'])
            );
        }
        echo '</h2>';

        if (isset($tabs[$current]['render']) && is_callable($tabs[$current]['render'])) {
            call_user_func($tabs[$current]['render']);
        }

        echo '</div>';
    }

    /**
     * The default landing tab: a plain-English introduction to every HDWebmobile
     * plugin (not just the ones with a tab on this page), so this page also works as
     * a directory of the whole product line -- the same list the individual "More
     * WooCommerce Plugins by HDWebmobile" panels show, just hosted centrally.
     */
    public static function render_overview()
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = array(
            array('name' => 'Abandoned Cart Recovery', 'file' => 'hdwebmobile-abandoned-cart-recovery/hdwebmobile-abandoned-cart-recovery.php', 'description' => 'Recover lost sales with automatic cart-recovery emails.'),
            array('name' => 'Back In Stock & Waitlist', 'file' => 'hdwebmobile-back-in-stock-waitlist/hdwebmobile-back-in-stock-waitlist.php', 'description' => 'Notify customers the moment an out-of-stock product is back.'),
            array('name' => 'Booking & Appointments', 'file' => 'hdwebmobile-booking-appointments/hdwebmobile-booking-appointments.php', 'description' => 'Sell bookable services and appointments -- a booking\'s owner is set once, server-side, at purchase and never accepted from any later request.'),
            array('name' => 'Checkout Delivery Scheduler', 'file' => 'hdwebmobile-checkout-delivery-scheduler/hdwebmobile-checkout-delivery-scheduler.php', 'description' => 'Let customers pick a delivery date and time slot at checkout.'),
            array('name' => 'Currency Switcher', 'file' => 'hdwebmobile-currency-switcher/hdwebmobile-currency-switcher.php', 'description' => 'Let customers browse in their own currency -- the admin-configured rates can only ever change through a capability- and nonce-checked form, never a bare URL parameter.'),
            array('name' => 'Formula Pricing', 'file' => 'hdwebmobile-formula-pricing/hdwebmobile-formula-pricing.php', 'description' => 'Price products from customer-entered numbers via a safe formula -- no eval().'),
            array('name' => 'Frequently Bought Together', 'file' => 'hdwebmobile-frequently-bought-together/hdwebmobile-frequently-bought-together.php', 'description' => 'An Amazon-style widget that adds several related products in one click.'),
            array('name' => 'Gift Cards', 'file' => 'hdwebmobile-gift-cards/hdwebmobile-gift-cards.php', 'description' => 'Sell digital gift cards redeemed through WooCommerce\'s own native coupon field.'),
            array('name' => 'Invoices & Packing Slips', 'file' => 'hdwebmobile-invoices-packing-slips/hdwebmobile-invoices-packing-slips.php', 'description' => 'On-demand invoice and packing-slip documents, printable to PDF from any browser.'),
            array('name' => 'License Key Delivery', 'file' => 'hdwebmobile-license-key-delivery/hdwebmobile-license-key-delivery.php', 'description' => 'Sell software license keys through WooCommerce -- keys are pasted or generated in wp-admin, never imported from an uploaded file.'),
            array('name' => 'Live Shopping with Agora', 'file' => 'hdwebmobile-live-shopping-with-agora/hdwebmobile-live-shopping-with-agora.php', 'description' => 'Sell live on livestream video with real-time WooCommerce checkout.'),
            array('name' => 'Loyalty Points & Store Credit', 'file' => 'hdwebmobile-loyalty-points/hdwebmobile-loyalty-points.php', 'description' => 'Customers earn points on orders and redeem them for a discount -- balance always computed from an audit-logged ledger.'),
            array('name' => 'Media Folder Roles', 'file' => 'hdwebmobile-media-folder-roles/hd-media-folder-roles.php', 'description' => 'Organize the Media Library into folders with per-role permissions.'),
            array('name' => 'Mix & Match Bundles', 'file' => 'hdwebmobile-mix-match-bundles/hdwebmobile-mix-match-bundles.php', 'description' => 'Build-a-box bundles for WooCommerce.'),
            array('name' => 'Order Export', 'file' => 'hdwebmobile-order-export/hdwebmobile-order-export.php', 'description' => 'Export orders to CSV, filtered by date range and status.'),
            array('name' => 'OTP Login', 'file' => 'hdwebmobile-otp-login/hdwebmobile-otp-login.php', 'description' => 'Passwordless email one-time-code login -- a session is only ever created after a hashed, single-use code is verified.'),
            array('name' => 'Photo & Video Reviews', 'file' => 'hdwebmobile-photo-video-reviews/hdwebmobile-photo-video-reviews.php', 'description' => 'Let customers attach photos and short videos to their product reviews.'),
            array('name' => 'Product Enquiry', 'file' => 'hdwebmobile-product-enquiry/hdwebmobile-product-enquiry.php', 'description' => 'A pre-sale "ask a question" form for any product -- every field is escaped at the point of output.'),
            array('name' => 'Product File Upload', 'file' => 'hdwebmobile-product-file-upload/hdwebmobile-product-file-upload.php', 'description' => 'Let customers upload a file with a product -- accepted types are always a hardcoded safe list, never "allow all".'),
            array('name' => 'Product Options & Add-ons', 'file' => 'hdwebmobile-product-options/hdwebmobile-product-options.php', 'description' => 'Add paid text, dropdown, and checkbox options to products -- flat pricing only, no formulas.'),
            array('name' => 'Request a Quote', 'file' => 'hdwebmobile-request-a-quote/hdwebmobile-request-a-quote.php', 'description' => 'Let customers request a custom price on any product -- only your own signed-off quote, viewed by its owner or an unguessable token, can ever become a cart price.'),
            array('name' => 'Return & Refund Requests', 'file' => 'hdwebmobile-return-refund-requests/hdwebmobile-return-refund-requests.php', 'description' => 'Let customers request a return on their own completed orders -- refund amounts are always computed server-side, and there is no file upload anywhere in this plugin.'),
            array('name' => 'Shipment Tracking', 'file' => 'hdwebmobile-shipment-tracking/hdwebmobile-shipment-tracking.php', 'description' => 'Add a carrier and tracking number to any order and show it to the customer.'),
            array('name' => 'Simple Subscriptions', 'file' => 'hdwebmobile-simple-subscriptions/hdwebmobile-simple-subscriptions.php', 'description' => 'Sell recurring products -- renewal state is always plain scalar columns, never a serialized blob.'),
            array('name' => 'Social Login', 'file' => 'hdwebmobile-social-login/hdwebmobile-social-login.php', 'description' => 'Sign in with Google -- every identity token is cryptographically verified against Google\'s own current public keys before any account is ever touched.'),
            array('name' => 'Socials Floating', 'file' => 'hdwebmobile-socials-floating/hdwebmobile-socials-floating.php', 'description' => 'A floating social-media contact bar for your site.'),
            array('name' => 'Spin & Win', 'file' => 'hdwebmobile-spin-and-win/hdwebmobile-spin-and-win.php', 'description' => 'A gamified discount-wheel popup with server-enforced one spin per email.'),
            array('name' => 'Vendor Marketplace', 'file' => 'hdwebmobile-vendor-marketplace/hdwebmobile-vendor-marketplace.php', 'description' => 'Turn your store into a multi-vendor marketplace -- vendors can only ever touch their own products, orders, and earnings, enforced by WordPress\'s own core capability system.'),
            array('name' => 'Wishlist', 'file' => 'hdwebmobile-wishlist/hdwebmobile-wishlist.php', 'description' => 'A wishlist that works with block-based Shop pages and guest browsing.'),
        );
        ?>
        <p style="max-width:800px;margin:1em 0;">A suite of focused WooCommerce plugins by HDWebmobile -- each one does one job well, with no bloat. Below is every plugin in the family; the tabs above are the ones currently installed here.</p>
        <div class="hdwebmobile-sibling-plugins" style="padding:1.5em;background:#fff;border:1px solid #dcdcde;border-radius:4px;max-width:800px;">
            <table class="widefat striped">
                <tbody>
                    <?php foreach ($plugins as $plugin) : ?>
                        <?php $active = is_plugin_active($plugin['file']); ?>
                        <?php $installed = file_exists(WP_PLUGIN_DIR . '/' . $plugin['file']); ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($plugin['name']); ?></strong><br />
                                <span style="opacity:.75;"><?php echo esc_html($plugin['description']); ?></span>
                            </td>
                            <td style="text-align:right;white-space:nowrap;vertical-align:middle;">
                                <?php if ($active) : ?>
                                    <span style="color:#1a7f37;">&#10003; Active</span>
                                <?php elseif ($installed) : ?>
                                    <a href="<?php echo esc_url(admin_url('plugins.php')); ?>" class="button button-small">Activate</a>
                                <?php else : ?>
                                    <a href="https://hdwebmobile.com/plugins/" target="_blank" rel="noopener noreferrer" class="button button-small">Learn More</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}

HDRAQ_Hub::init();
