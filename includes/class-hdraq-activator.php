<?php

namespace htrxuan\hdraq;

if (!defined('ABSPATH')) {
    exit;
}

class HDRAQ_Activator
{

    public static function activate()
    {
        if (!self::is_woocommerce_active()) {
            deactivate_plugins(plugin_basename(HDRAQ_PLUGIN_FILE));
            set_transient('hdraq_wc_missing_notice', true, 30);
            return;
        }

        self::maybe_upgrade_db();

        // The "My Quote Requests" My Account tab needs this endpoint's rewrite rule
        // present before the first visit, not just registered on the next 'init'.
        require_once HDRAQ_PLUGIN_DIR . 'includes/class-hdraq-myaccount.php';
        add_rewrite_endpoint(HDRAQ_MyAccount::ENDPOINT, EP_ROOT | EP_PAGES);
        flush_rewrite_rules();
    }

    public static function is_woocommerce_active()
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active('woocommerce/woocommerce.php') || class_exists('WooCommerce');
    }

    public static function maybe_upgrade_db()
    {
        if (get_option('hdraq_db_version') === HDRAQ_DB_VERSION) {
            return;
        }

        require_once HDRAQ_PLUGIN_DIR . 'includes/class-hdraq-repository.php';
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta(HDRAQ_Repository::get_schema_sql());

        update_option('hdraq_db_version', HDRAQ_DB_VERSION);
    }

    public static function declare_hpos_compatibility()
    {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', HDRAQ_PLUGIN_FILE, true);
        }
    }
}
