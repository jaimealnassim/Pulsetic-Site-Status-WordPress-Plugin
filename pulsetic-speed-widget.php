<?php
/**
 * Plugin Name: Pulsetic Speed Widget
 * Plugin URI:  https://nahnuplugins.com
 * Description: Live load times and uptime from Pulsetic. Two shortcodes: [pulsetic_speed] and [pulsetic_uptime]. API key stays server-side. Full cache-plugin compatibility included.
 * Version:     1.6.1
 * Author:      Nahnu Media
 * Author URI:  https://nahnumedia.com
 * License:     GPL-2.0+
 * Text Domain: pulsetic-speed-widget
 */

defined( 'ABSPATH' ) || exit;

define( 'PSW_VERSION', '1.6.1' );
define( 'PSW_FILE',    __FILE__ );
define( 'PSW_DIR',     plugin_dir_path( __FILE__ ) );
define( 'PSW_URL',     plugin_dir_url( __FILE__ ) );
define( 'PSW_SLUG',    'pulsetic-speed-widget' );

// Nahnu auto-updater — do not remove
if ( ! defined( 'NAHNU_UPDATER_WORKER_URL' ) ) {
	define( 'NAHNU_UPDATER_WORKER_URL', 'https://nahnu-updates.nahnucdn.com' );
}
require_once PSW_DIR . 'includes/class-nahnu-updater.php';
Nahnu_Updater::register( __FILE__ );

require_once PSW_DIR . 'includes/class-psw-api.php';
require_once PSW_DIR . 'includes/class-psw-cache.php';
require_once PSW_DIR . 'includes/class-psw-rest.php';
require_once PSW_DIR . 'includes/class-psw-shortcode.php';
require_once PSW_DIR . 'admin/class-psw-admin.php';

add_action( 'plugins_loaded', 'psw_init' );

function psw_init() {
    PSW_Cache::init();   // must run before REST so headers are registered
    PSW_Rest::init();
    PSW_Shortcode::init();
    if ( is_admin() ) {
        PSW_Admin::init();
    }
}

register_activation_hook( PSW_FILE, 'psw_activate' );
function psw_activate() {
    add_option( 'psw_settings', [
        'api_token'        => '',
        'default_monitor'  => '',
        'cache_seconds'    => 60,
        'refresh_interval' => 60,
    ] );
}

register_deactivation_hook( PSW_FILE, 'psw_deactivate' );
function psw_deactivate() {
    global $wpdb;
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_psw_%' OR option_name LIKE '_transient_timeout_psw_%'" );
}
