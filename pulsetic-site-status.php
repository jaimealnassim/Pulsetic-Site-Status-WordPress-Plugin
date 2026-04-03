<?php
/**
 * Plugin Name:       Pulsetic Site Status
 * Plugin URI:        https://github.com/nahnumedia/pulsetic-site-status
 * Description:       Display live/down status of monitored sites via Pulsetic API. Supports multiple groups, three display styles, and live AJAX polling.
 * Version:           1.1.3
 * Author:            Nahnu Media
 * Author URI:        https://nahnumedia.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pulsetic-site-status
 * Requires at least: 6.0
 * Requires PHP:      8.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'PULSETIC_VERSION',    '1.1.3' );
define( 'PULSETIC_PATH',       plugin_dir_path( __FILE__ ) );
define( 'PULSETIC_URL',        plugin_dir_url( __FILE__ ) );
define( 'PULSETIC_CACHE_KEY',  'pulsetic_monitors_cache' );
// PULSETIC_CACHE_TTL is intentionally not defined here.
// Use pulsetic_get_cache_ttl() so the admin-configured scan interval is respected.
define( 'PULSETIC_API_BASE',   'https://api.pulsetic.com/api/public' );
define( 'PULSETIC_OPT_TOKEN',  'pulsetic_api_token' );
define( 'PULSETIC_OPT_COLOR',  'pulsetic_colors' );
define( 'PULSETIC_OPT_GROUPS', 'pulsetic_groups' );

// uninstall.php handles data cleanup when the plugin is deleted.
register_uninstall_hook( __FILE__, '__return_false' );

require_once PULSETIC_PATH . 'includes/functions.php';
require_once PULSETIC_PATH . 'includes/class-api.php';
require_once PULSETIC_PATH . 'includes/class-admin.php';
require_once PULSETIC_PATH . 'includes/class-shortcode.php';
require_once PULSETIC_PATH . 'includes/class-shortcode-cards.php';
require_once PULSETIC_PATH . 'includes/class-shortcode-bar.php';
require_once PULSETIC_PATH . 'includes/class-ajax.php';

( new Pulsetic_Admin() )->init();
( new Pulsetic_Shortcode() )->init();
( new Pulsetic_Shortcode_Cards() )->init();
( new Pulsetic_Shortcode_Bar() )->init();
( new Pulsetic_Ajax() )->init();
