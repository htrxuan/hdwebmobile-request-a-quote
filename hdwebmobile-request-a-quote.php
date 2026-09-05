<?php

/**
 * Plugin Name: HDWebmobile Request a Quote
 * Plugin URI: https://hdwebmobile.com/plugins/hdwebmobile-request-a-quote/
 * Description: Let customers request a custom price on any product -- only your own signed-off quote can ever become a cart price.
 * Version: 1.0.0
 * Author: htrxuan - Han Tran
 * Author URI: https://hdwebmobile.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: hdwebmobile-request-a-quote
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 * Requires at least: 6.9
 */

namespace htrxuan\hdraq;

if (!defined('ABSPATH')) {
    exit;
}

// Define Constants
define('HDRAQ_VERSION', '1.0.0');
define('HDRAQ_DB_VERSION', '1.0.0');
define('HDRAQ_PLUGIN_FILE', __FILE__);
define('HDRAQ_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HDRAQ_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once HDRAQ_PLUGIN_DIR . 'includes/class-hdraq-activator.php';

register_activation_hook(HDRAQ_PLUGIN_FILE, array(HDRAQ_Activator::class, 'activate'));
add_action('before_woocommerce_init', array(HDRAQ_Activator::class, 'declare_hpos_compatibility'));

add_action('plugins_loaded', function () {
    require_once HDRAQ_PLUGIN_DIR . 'includes/class-hdraq-core.php';
    HDRAQ_Core::get_instance();
});

add_filter('plugin_action_links_' . plugin_basename(HDRAQ_PLUGIN_FILE), function ($links) {
    $donate_link = '<a href="https://paypal.me/htrxuan/20" target="_blank" style="color:#d54e21;font-weight:bold;">' . __('Donate', 'hdwebmobile-request-a-quote') . '</a>';
    array_unshift($links, $donate_link);
    return $links;
});
