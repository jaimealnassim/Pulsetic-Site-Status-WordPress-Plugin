<?php
defined( 'ABSPATH' ) || exit;

class PSW_Rest {

    const NAMESPACE = 'psw/v1';

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    public static function register_routes() {

        register_rest_route( self::NAMESPACE, '/speed', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_speed' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'monitor_id' => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                'minutes'    => [ 'required' => false, 'default' => 30, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/uptime', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_uptime' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'monitor_id' => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                'days'       => [ 'required' => false, 'default' => 30, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/monitors', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_monitors' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
        ] );

        register_rest_route( self::NAMESPACE, '/speed-history', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_speed_history' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( self::NAMESPACE, '/debug', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_debug' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
        ] );

        register_rest_route( self::NAMESPACE, '/clear-cache', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'clear_cache' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
        ] );
    }

    public static function get_speed( WP_REST_Request $req ) {
        $settings   = get_option( 'psw_settings', [] );
        $monitor_id = $req->get_param( 'monitor_id' ) ?: ( $settings['default_monitor'] ?? '' );
        $minutes    = min( (int) $req->get_param( 'minutes' ), 120 );

        if ( empty( $monitor_id ) ) {
            return new WP_REST_Response( [ 'error' => 'No monitor ID provided or configured.' ], 400 );
        }

        $data = PSW_Api::get_speed_data( $monitor_id, $minutes );
        if ( is_wp_error( $data ) ) {
            return new WP_REST_Response( [ 'error' => $data->get_error_message() ], 502 );
        }

        return new WP_REST_Response( $data, 200 );
    }

    public static function get_uptime( WP_REST_Request $req ) {
        $settings   = get_option( 'psw_settings', [] );
        $monitor_id = $req->get_param( 'monitor_id' ) ?: ( $settings['default_monitor'] ?? '' );
        $days       = min( max( 1, (int) $req->get_param( 'days' ) ), 90 );

        if ( empty( $monitor_id ) ) {
            return new WP_REST_Response( [ 'error' => 'No monitor ID provided or configured.' ], 400 );
        }

        $data = PSW_Api::get_uptime_data( $monitor_id, $days );
        if ( is_wp_error( $data ) ) {
            return new WP_REST_Response( [ 'error' => $data->get_error_message() ], 502 );
        }

        return new WP_REST_Response( $data, 200 );
    }

    public static function get_monitors( WP_REST_Request $req ) {
        $data = PSW_Api::get_monitors();
        if ( is_wp_error( $data ) ) {
            return new WP_REST_Response( [ 'error' => $data->get_error_message() ], 502 );
        }

        $monitors = isset( $data['data'] ) ? $data['data'] : ( is_array( $data ) ? $data : [] );
        $output   = array_map( fn( $m ) => [
            'id'   => $m['id'] ?? '',
            'name' => $m['name'] ?? ( $m['url'] ?? 'Monitor ' . ( $m['id'] ?? '' ) ),
            'url'  => $m['url'] ?? '',
        ], $monitors );

        return new WP_REST_Response( $output, 200 );
    }

    public static function clear_cache( WP_REST_Request $req ) {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_psw_%' OR option_name LIKE '_transient_timeout_psw_%'" );
        return new WP_REST_Response( [ 'cleared' => true ], 200 );
    }

    /**
     * Paginated multi-day speed history using snapshots endpoint.
     * Snapshots are Pulsetic's pre-aggregated data — perfect for historical averages.
     */
    public static function get_speed_history( WP_REST_Request $req ) {
        $s          = get_option( 'psw_settings', [] );
        $monitor_id = sanitize_text_field( $req->get_param( 'monitor_id' ) ?: ( $s['default_monitor'] ?? '' ) );
        $days       = min( 30, max( 1, (int) ( $req->get_param( 'days' ) ?: 7 ) ) );
        $cache_key  = 'psw_history_' . md5( $monitor_id . '_' . $days . '_' . PSW_VERSION );
        $cached     = get_transient( $cache_key );
        if ( $cached ) return new WP_REST_Response( $cached, 200 );

        // Use snapshots — Pulsetic aggregates these so one snapshot = one check window
        $start    = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
        $end      = gmdate( 'Y-m-d H:i:s' );
        $token    = PSW_Api::token();

        // Fetch up to 3 pages of snapshots (300 records) to cover multiple locations over the window
        $all = [];
        for ( $page = 1; $page <= 3; $page++ ) {
            $url  = PSW_Api::BASE_URL . "/monitors/{$monitor_id}/snapshots?" . http_build_query( [
                'start_dt' => $start, 'end_dt' => $end, 'per_page' => 100, 'page' => $page,
            ] );
            $resp = wp_remote_get( $url, [ 'timeout' => 15, 'headers' => [ 'Authorization' => $token ] ] );
            if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) break;
            $body = json_decode( wp_remote_retrieve_body( $resp ), true );
            $rows = $body['data'] ?? ( is_array( $body ) ? $body : [] );
            if ( empty( $rows ) ) break;
            $all = array_merge( $all, $rows );
            if ( count( $rows ) < 100 ) break; // last page
        }

        $monitor = PSW_Api::get_monitor( $monitor_id );
        $result  = PSW_Api::process_snapshots( $all, $monitor, $days );
        set_transient( $cache_key, $result, 12 * HOUR_IN_SECONDS ); // cache history 12hrs
        return new WP_REST_Response( $result, 200 );
    }

    public static function get_debug( WP_REST_Request $req ) {
        $s          = get_option( 'psw_settings', [] );
        $monitor_id = sanitize_text_field( $s['default_monitor'] ?? '' );
        if ( empty( $monitor_id ) ) {
            return new WP_REST_Response( [ 'error' => 'No default monitor set in plugin settings.' ], 400 );
        }
        $raw = PSW_Api::debug_raw( $monitor_id );
        return new WP_REST_Response( $raw, 200 );
    }
}
