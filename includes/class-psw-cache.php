<?php
/**
 * PSW_Cache — Cache compatibility for Pulsetic Speed Widget.
 *
 * ── How PSW pages are cached ───────────────────────────────────────────────
 * The [pulsetic_speed] / [pulsetic_uptime] shortcodes render skeleton HTML
 * only — completely static markup that is safe for any page cache to store.
 * Live data is fetched client-side by psw-widget.js via the REST endpoints.
 *
 * ── WP Super Page Cache Pro (SWCFPC) — primary integration ────────────────
 * SWCFPC already bypasses ALL /wp-json/* requests at two independent layers:
 *   1. advanced-cache.php (disk cache): swcfpc_is_api_request() skips disk
 *      cache for any URI starting with /wp-json.
 *   2. PHP cache controller: is_api_request() returns true for REST requests,
 *      causing is_url_to_bypass() to return true → no cache headers set.
 *
 * PSW hooks into SWCFPC's official filter (swcfpc_cache_bypass) as an
 * explicit integration point — not relying on the general API detection.
 *
 * When PSW settings change we fire do_action('swcfpc_purge_cache', []) so
 * SWCFPC purges Cloudflare/Bunny for any page that cached widget HTML.
 *
 * ── CDN-level cache headers ───────────────────────────────────────────────
 * Bunny.net and other CDN edge nodes may independently cache REST responses.
 * We send no-cache headers on every PSW REST response via rest_post_dispatch.
 */

defined( 'ABSPATH' ) || exit;

class PSW_Cache {

    public static function init() {

        // ── WP Super Page Cache Pro (SWCFPC) ──────────────────────────────
        add_filter( 'swcfpc_cache_bypass', [ __CLASS__, 'spc_bypass_psw_routes' ] );
        add_action( 'update_option_psw_settings', [ __CLASS__, 'spc_purge_on_settings_change' ], 10, 2 );

        // ── WordPress REST nocache headers ────────────────────────────────
        add_filter( 'rest_send_nocache_headers', [ __CLASS__, 'rest_nocache_headers_for_psw' ] );

        // ── CDN-level no-cache headers ────────────────────────────────────
        add_filter( 'rest_post_dispatch', [ __CLASS__, 'rest_cdn_nocache_headers' ], 10, 3 );

        // ── WP Rocket — JS asset exclusions ──────────────────────────────
        add_filter( 'rocket_exclude_js',                     [ __CLASS__, 'rocket_exclude_js' ] );
        add_filter( 'rocket_delay_js_exclusions',            [ __CLASS__, 'rocket_exclude_js' ] );
        add_filter( 'rocket_excluded_strings_from_delay_js', [ __CLASS__, 'rocket_exclude_js' ] );

        // ── LiteSpeed Cache — JS defer exclusion ──────────────────────────
        add_filter( 'litespeed_optimize_js_excludes', [ __CLASS__, 'litespeed_exclude_js' ] );
        add_filter( 'litespeed_optm_js_defer_exc',    [ __CLASS__, 'litespeed_exclude_js' ] );

        // ── Autoptimize — JS combine/defer exclusion ──────────────────────
        add_filter( 'autoptimize_filter_js_exclude',      [ __CLASS__, 'autoptimize_exclude_js' ] );
        add_filter( 'autoptimize_filter_js_defer_not_in', [ __CLASS__, 'autoptimize_exclude_js' ] );
    }

    // ── WP Super Page Cache Pro ────────────────────────────────────────────

    /**
     * Bypass PSW REST routes via SWCFPC's swcfpc_cache_bypass filter.
     * Hooked into SWCFPC_Cache_Controller::can_i_bypass_cache().
     */
    public static function spc_bypass_psw_routes( $bypass ) {
        if ( $bypass ) {
            return $bypass;
        }
        return self::is_psw_rest_request();
    }

    /**
     * When PSW settings change, purge SWCFPC's Cloudflare/Bunny cache so
     * pages embedding the widget don't serve stale cached HTML.
     *
     * Uses SWCFPC's documented programmatic purge action:
     *   do_action( 'swcfpc_purge_cache', $urls )
     * Empty array = purge all, per purge_cache_programmatically() in SWCFPC.
     */
    public static function spc_purge_on_settings_change( $old_value, $new_value ) {
        $watched = [ 'api_token', 'default_monitor', 'cache_seconds', 'refresh_interval' ];
        foreach ( $watched as $key ) {
            if ( ( $old_value[ $key ] ?? '' ) !== ( $new_value[ $key ] ?? '' ) ) {
                do_action( 'swcfpc_purge_cache', [] );
                return;
            }
        }
    }

    // ── REST API headers ───────────────────────────────────────────────────

    /**
     * Tell WordPress core to send nocache headers on PSW REST responses.
     * SWCFPC reads this filter and adjusts its own header logic.
     */
    public static function rest_nocache_headers_for_psw( $send ) {
        if ( self::is_psw_rest_request() ) {
            return true;
        }
        return $send;
    }

    /**
     * Add CDN-specific no-cache headers to PSW REST responses.
     * Targets edge nodes that operate independently of WordPress page cache.
     */
    public static function rest_cdn_nocache_headers( $response, $server, $request ) {
        if ( ! self::is_psw_route( $request->get_route() ) ) {
            return $response;
        }

        $response->header( 'Cache-Control',         'no-store, no-cache, must-revalidate, max-age=0, s-maxage=0' );
        $response->header( 'Pragma',                'no-cache' );
        $response->header( 'Expires',               '0' );
        $response->header( 'CDN-Cache-Control',     'no-store' );
        $response->header( 'Bunny-Cache-Control',   'no-cache' );
        $response->header( 'Surrogate-Control',     'no-store' );
        $response->header( 'X-Accel-Expires',       '0' );
        $response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );

        return $response;
    }

    // ── WP Rocket ─────────────────────────────────────────────────────────

    public static function rocket_exclude_js( $exclusions ) {
        $exclusions[] = 'psw-widget';
        return $exclusions;
    }

    // ── LiteSpeed Cache ────────────────────────────────────────────────────

    public static function litespeed_exclude_js( $excludes ) {
        $excludes[] = 'psw-widget';
        return $excludes;
    }

    // ── Autoptimize ────────────────────────────────────────────────────────

    public static function autoptimize_exclude_js( $exclusions ) {
        if ( ! is_string( $exclusions ) ) {
            $exclusions = '';
        }
        $parts   = array_filter( array_map( 'trim', explode( ',', $exclusions ) ) );
        $parts[] = 'psw-widget.js';
        return implode( ', ', array_unique( $parts ) );
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    public static function is_psw_rest_request() {
        if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
            return false;
        }
        $uri = wp_unslash( $_SERVER['REQUEST_URI'] );
        return strpos( $uri, '/wp-json/psw/' ) !== false
            || strpos( $uri, 'rest_route=/psw/' ) !== false;
    }

    private static function is_psw_route( $route ) {
        return strpos( (string) $route, '/psw/v' ) === 0;
    }

    public static function detect_cache_plugins() {
        $found = [];
        if ( defined( 'SWCFPC_PLUGIN_PATH' ) || class_exists( 'SW_CLOUDFLARE_PAGECACHE' ) ) {
            $found[] = 'WP Super Page Cache Pro';
        }
        if ( defined( 'WP_ROCKET_VERSION' ) )         $found[] = 'WP Rocket';
        if ( defined( 'LSWCP_TAG' ) || class_exists( 'LiteSpeed_Cache' ) ) $found[] = 'LiteSpeed Cache';
        if ( class_exists( 'autoptimizeBase' ) )       $found[] = 'Autoptimize';
        if ( defined( 'W3TC' ) )                       $found[] = 'W3 Total Cache';
        if ( function_exists( 'wp_cache_phase1' ) )    $found[] = 'WP Super Cache';
        if ( class_exists( 'WpFastestCache' ) )        $found[] = 'WP Fastest Cache';
        return $found;
    }
}
