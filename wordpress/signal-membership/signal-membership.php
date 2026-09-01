<?php
/**
 * Plugin Name: Signal Membership
 * Description: Per-member watchlists, dashboards, EMA ribbon charts, and nightly emails. Reads the Redline engine DB. Does not fetch prices.
 * Version: 1.4.11
 * Author: Redline Trading
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Text Domain: signal-membership
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SIG_PLUGIN_FILE', __FILE__);
define('SIG_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SIG_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SIG_PLUGIN_VER', '1.4.11');
define('SIG_LWC_VERSION', '5.0.8');

require_once SIG_PLUGIN_DIR . 'includes/class-db.php';
require_once SIG_PLUGIN_DIR . 'includes/class-access.php';
require_once SIG_PLUGIN_DIR . 'includes/class-signals.php';
require_once SIG_PLUGIN_DIR . 'includes/class-cache.php';
require_once SIG_PLUGIN_DIR . 'includes/class-watchlist.php';
require_once SIG_PLUGIN_DIR . 'includes/class-rest.php';
require_once SIG_PLUGIN_DIR . 'includes/class-email.php';
require_once SIG_PLUGIN_DIR . 'includes/class-admin.php';
require_once SIG_PLUGIN_DIR . 'includes/class-shortcodes.php';
require_once SIG_PLUGIN_DIR . 'includes/class-import-favorites.php';
require_once SIG_PLUGIN_DIR . 'includes/class-pages.php';
require_once SIG_PLUGIN_DIR . 'includes/class-pmpro-fields.php';
require_once SIG_PLUGIN_DIR . 'includes/class-levels.php';
require_once SIG_PLUGIN_DIR . 'includes/class-dreamteam-admin.php';

register_activation_hook(__FILE__, array('SIG_Pages', 'activate'));

add_action('plugins_loaded', function () {
    SIG_Access::init();
    SIG_DB::init();
    SIG_Watchlist::init();
    SIG_REST::init();
    SIG_Email::init();
    SIG_Admin::init();
    SIG_Shortcodes::init();
    SIG_Import_Favorites::init();
    SIG_Pmpro_Fields::init();
    SIG_Levels::init();
    SIG_Dreamteam_Admin::init();
});
