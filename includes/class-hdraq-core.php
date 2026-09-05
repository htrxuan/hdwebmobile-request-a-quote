<?php

namespace htrxuan\hdraq;

if (!defined('ABSPATH')) {
    exit;
}

final class HDRAQ_Core
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
        $this->includes();
        $this->init_hooks();
    }

    private function __clone()
    {
    }

    private function includes()
    {
        require_once HDRAQ_PLUGIN_DIR . 'includes/class-hdraq-repository.php';
        require_once HDRAQ_PLUGIN_DIR . 'includes/class-hdraq-frontend.php';
        require_once HDRAQ_PLUGIN_DIR . 'includes/class-hdraq-cart.php';
        require_once HDRAQ_PLUGIN_DIR . 'includes/class-hdraq-myaccount.php';
        require_once HDRAQ_PLUGIN_DIR . 'includes/class-hdraq-admin.php';
    }

    private function init_hooks()
    {
        add_action('admin_notices', array($this, 'render_missing_woocommerce_notice'));
        add_action('admin_init', array(HDRAQ_Activator::class, 'maybe_upgrade_db'));

        if (!class_exists('WooCommerce')) {
            return;
        }

        HDRAQ_Frontend::get_instance();
        HDRAQ_Cart::get_instance();
        HDRAQ_MyAccount::get_instance();

        // HDRAQ_Admin registers admin-menu/settings hooks itself, but this must load
        // unconditionally (not only when is_admin()) since it also owns the
        // hdwebmobile_hub_tabs registration used by the shared hub page.
        HDRAQ_Admin::get_instance();
    }

    public function render_missing_woocommerce_notice()
    {
        $screen = get_current_screen();
        if (!$screen || 'plugins' !== $screen->id) {
            return;
        }

        if (!get_transient('hdraq_wc_missing_notice')) {
            return;
        }
        delete_transient('hdraq_wc_missing_notice');
        ?>
        <div class="notice notice-error is-dismissible">
            <p>
                <?php esc_html_e('HDWebmobile Request a Quote requires WooCommerce to be installed and active. The plugin has been deactivated.', 'hdwebmobile-request-a-quote'); ?>
            </p>
        </div>
        <?php
    }
}
