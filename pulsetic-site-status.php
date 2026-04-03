<?php
/**
 * Plugin Name: Pulsetic Site Status
 * Description: Display live/down status of monitored sites via Pulsetic API. Supports multiple groups per page.
 * Version: 1.1.1
 * Author: Exercise Library
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'PULSETIC_VERSION',    '1.1.1' );
define( 'PULSETIC_PATH',       plugin_dir_path( __FILE__ ) );
define( 'PULSETIC_URL',        plugin_dir_url( __FILE__ ) );
define( 'PULSETIC_CACHE_KEY',  'pulsetic_monitors_cache' );
define( 'PULSETIC_CACHE_TTL',  5 * MINUTE_IN_SECONDS );
define( 'PULSETIC_API_BASE',   'https://api.pulsetic.com/api/public' );
define( 'PULSETIC_OPT_TOKEN',  'pulsetic_api_token' );
define( 'PULSETIC_OPT_COLOR',  'pulsetic_colors' );
define( 'PULSETIC_OPT_GROUPS', 'pulsetic_groups' );

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
