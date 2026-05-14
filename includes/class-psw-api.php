<?php
defined( 'ABSPATH' ) || exit;

class PSW_Api {

    public const BASE_URL = 'https://api.pulsetic.com/api/public';

    public static function get( $endpoint, $params = [] ) {
        $token = self::token();
        if ( empty( $token ) ) {
            return new WP_Error( 'psw_no_token', __( 'No Pulsetic API token configured.', 'pulsetic-speed-widget' ) );
        }
        $url = add_query_arg( $params, self::BASE_URL . $endpoint );
        $response = wp_remote_get( $url, [
            'timeout' => 15,
            'headers' => [ 'Authorization' => $token, 'Accept' => 'application/json' ],
        ] );
        if ( is_wp_error( $response ) ) return $response;
        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code !== 200 ) {
            return new WP_Error( 'psw_api_error', $data['message'] ?? "HTTP {$code}", [ 'status' => $code ] );
        }
        return $data;
    }

    public static function get_monitors() { return self::get( '/monitors', [ 'per_page' => 100 ] ); }
    public static function get_monitor( $id ) { return self::get( "/monitors/{$id}" ); }

    public static function get_speed_data( $monitor_id, $minutes = 30 ) {
        $settings  = get_option( 'psw_settings', [] );
        $ttl       = (int) ( $settings['cache_seconds'] ?? 60 );
        $cache_key = "psw_speed_{$monitor_id}_{$minutes}";
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) return $cached;

        $end   = gmdate( 'Y-m-d H:i:s' );
        $start = gmdate( 'Y-m-d H:i:s', time() - $minutes * 60 );
        // Confirmed correct endpoint: GET /monitors/{id}/checks (the usage examples doc had a typo)
        $checks  = self::get( "/monitors/{$monitor_id}/checks", [ 'start_dt' => $start, 'end_dt' => $end, 'per_page' => 500 ] );
        if ( is_wp_error( $checks ) ) return $checks;
        $monitor = self::get_monitor( $monitor_id );
        $result  = self::process_checks( $checks, $monitor );
        set_transient( $cache_key, $result, $ttl );
        return $result;
    }

    private static function process_checks( $checks, $monitor ) {
        $nodes = [];
        $items = $checks['data'] ?? ( is_array( $checks ) ? $checks : [] );
        foreach ( $items as $c ) {
            // Skip failed checks
            if ( ! empty( $c['is_failed'] ) ) continue;

            // Pulsetic stores response_time in SECONDS — always multiply by 1000
            $rt = null;
            foreach ( [ 'response_time', 'time_total' ] as $field ) {
                if ( isset( $c[ $field ] ) && (float) $c[ $field ] > 0 ) {
                    $rt = (float) $c[ $field ] * 1000; // seconds → ms
                    break;
                }
            }
            if ( ! $rt || $rt <= 0 ) continue;

            // Checks response has node_id only — no node name.
            // Derive city from CDN-Server response header (BunnyCDN-NY1-885 → New York).
            $name = self::location_from_cdn_headers( $c['response_headers'] ?? '' );
            if ( ! $name ) $name = 'Node ' . ( $c['node_id'] ?? '?' );

            $code = (int) ( $c['response_code'] ?? 0 );
            if ( ! isset( $nodes[ $name ] ) ) $nodes[ $name ] = [ 'times' => [], 'errors' => 0, 'total' => 0 ];
            $nodes[ $name ]['times'][] = $rt;
            $nodes[ $name ]['total']++;
            if ( $code >= 400 ) $nodes[ $name ]['errors']++;
        }
        $output = [];
        foreach ( $nodes as $name => $n ) {
            if ( empty( $n['times'] ) ) continue;
            $avg      = array_sum( $n['times'] ) / count( $n['times'] );
            $output[] = [
                'node'        => $name,
                'flag'        => self::node_flag( $name ),
                'region'      => self::node_region( $name ),
                'avg_ms'      => round( $avg ),
                'avg_s'       => round( $avg / 1000, 2 ),
                'check_count' => $n['total'],
                'error_count' => $n['errors'],
            ];
        }
        usort( $output, fn( $a, $b ) => $a['avg_ms'] <=> $b['avg_ms'] );
        $status = 'unknown'; $monitor_name = '';
        if ( ! is_wp_error( $monitor ) ) {
            $m      = $monitor['data'] ?? $monitor;
            $status = self::normalize_status( $m );
            $monitor_name = $m['name'] ?? $m['url'] ?? '';
        }
        return [
            'nodes'        => $output,
            'total_nodes'  => count( $output ),
            'status'       => $status,
            'monitor_name' => $monitor_name,
            'fetched_at'   => gmdate( 'c' ),
            'check_count'  => count( $items ),
        ];
    }


    public static function get_uptime_data( $monitor_id, $days = 30 ) {
        $settings  = get_option( 'psw_settings', [] );
        $ttl       = (int) ( $settings['cache_seconds'] ?? 60 );
        $cache_key = "psw_uptime_{$monitor_id}_{$days}";
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) return $cached;

        $window   = $days * DAY_IN_SECONDS;
        $downtime = self::get( "/monitors/{$monitor_id}/downtime", [ 'seconds' => $window ] );
        if ( is_wp_error( $downtime ) ) return $downtime;
        $monitor  = self::get_monitor( $monitor_id );

        $dt = 0;
        if ( is_array( $downtime ) && ! is_null( $downtime ) ) {
            $dt = (float) ( $downtime['seconds'] ?? $downtime['downtime'] ?? $downtime['data']['seconds'] ?? 0 );
        }
        $pct     = $window > 0 ? max( 0, min( 100, round( ( ( $window - $dt ) / $window ) * 100, 4 ) ) ) : 100;
        $display = $pct >= 100 ? '100.00' : number_format( $pct, 2 );

        $status = 'unknown'; $monitor_name = '';
        if ( ! is_wp_error( $monitor ) ) {
            $m      = $monitor['data'] ?? $monitor;
            $status = self::normalize_status( $m );
            $monitor_name = $m['name'] ?? $m['url'] ?? '';
        }

        $result = [ 'uptime_pct' => $pct, 'uptime_display' => $display, 'downtime_seconds' => (int) $dt, 'downtime_human' => self::fmt_downtime( (int) $dt ), 'window_days' => $days, 'status' => $status, 'monitor_name' => $monitor_name, 'fetched_at' => gmdate( 'c' ), '_raw_monitor' => isset( $m ) ? $m : null ];
        set_transient( $cache_key, $result, $ttl );
        return $result;
    }

    private static function fmt_downtime( $s ) {
        if ( $s <= 0 ) return 'No downtime';
        if ( $s < 60 ) return $s . 's downtime';
        if ( $s < 3600 ) return round( $s / 60 ) . 'm downtime';
        return floor( $s / 3600 ) . 'h ' . round( ( $s % 3600 ) / 60 ) . 'm downtime';
    }

    /**
     * Process snapshots API response into the same node array format as process_checks.
     * Snapshots are Pulsetic's pre-aggregated data per node per time window.
     * We don't yet know the exact snapshot field names so we handle both
     * snapshot-style and check-style records gracefully.
     */
    /**
     * Process snapshots API response.
     * Snapshot structure (confirmed from API):
     *   $r['node']['title']        = city name e.g. "Stockholm"
     *   $r['node']['location']     = slug e.g. "stockholm"
     *   $r['node']['continent']    = e.g. "Europe"
     *   $r['average_time_total']   = seconds (float string)
     *   $r['total_checks']         = int
     *   $r['failed_checks']        = int
     * Each row is one node for one hour — group by node to get one avg per location.
     */
    public static function process_snapshots( $rows, $monitor, $days = 7 ) {
        $nodes = [];
        foreach ( $rows as $r ) {
            // Response time — confirmed field name from API
            $rt = (float) ( $r['average_time_total'] ?? $r['average_time_starttransfer'] ?? 0 );
            if ( $rt <= 0 ) continue;
            $rt = $rt * 1000; // seconds → ms

            // Node info is a nested object — confirmed from API response
            $node_obj = $r['node'] ?? [];
            $name     = is_array( $node_obj ) ? ( $node_obj['title'] ?? ucwords( str_replace( '_', ' ', $node_obj['location'] ?? '' ) ) ) : '';
            if ( ! $name ) continue;

            $slug      = is_array( $node_obj ) ? ( $node_obj['location'] ?? '' ) : '';
            $continent = is_array( $node_obj ) ? ( $node_obj['continent'] ?? '' ) : '';

            if ( ! isset( $nodes[ $name ] ) ) {
                $nodes[ $name ] = [
                    'times'     => [],
                    'total'     => 0,
                    'errors'    => 0,
                    'slug'      => $slug,
                    'continent' => $continent,
                ];
            }
            // Each row = one hour bucket — weight by check count for accurate average
            $checks = max( 1, (int) ( $r['total_checks'] ?? 1 ) );
            for ( $i = 0; $i < $checks; $i++ ) $nodes[ $name ]['times'][] = $rt;
            $nodes[ $name ]['total']  += $checks;
            $nodes[ $name ]['errors'] += (int) ( $r['failed_checks'] ?? 0 );
        }

        // Map location slug → country code for flags
        $slug_cc = [
            'stockholm'   => 'se', 'sydney'      => 'au', 'paris'       => 'fr',
            'tokyo'       => 'jp', 'ashburn'     => 'us', 'new_york'    => 'us',
            'toronto'     => 'ca', 'london'      => 'gb', 'frankfurt'   => 'de',
            'singapore'   => 'sg', 'bangalore'   => 'in', 'sao-paulo'   => 'br',
            'sao_paulo'   => 'br', 'johannesburg'=> 'za', 'amsterdam'   => 'nl',
            'madrid'      => 'es', 'milan'       => 'it', 'warsaw'      => 'pl',
            'los-angeles' => 'us', 'los_angeles' => 'us', 'chicago'     => 'us',
            'dallas'      => 'us', 'miami'       => 'us', 'seattle'     => 'us',
            'montreal'    => 'ca', 'vancouver'   => 'ca', 'helsinki'    => 'fi',
            'bucharest'   => 'ro', 'cape-town'   => 'za', 'lagos'       => 'ng',
            'nairobi'     => 'ke', 'hong-kong'   => 'hk', 'seoul'       => 'kr',
            'taipei'      => 'tw', 'dubai'       => 'ae', 'istanbul'    => 'tr',
            'buenos-aires'=> 'ar', 'santiago'    => 'cl', 'mexico'      => 'mx',
            'vienna'      => 'at', 'zurich'      => 'ch', 'lisbon'      => 'pt',
        ];

        // Map continent slug → our region names
        $continent_map = [
            'north america'   => 'North America',
            'europe'          => 'Europe',
            'asia/australia'  => 'Asia Pacific',
            'asia'            => 'Asia Pacific',
            'australia'       => 'Asia Pacific',
            'south america'   => 'South America',
            'africa'          => 'Africa',
            'middle east'     => 'Middle East',
        ];

        $output = [];
        foreach ( $nodes as $name => $n ) {
            if ( empty( $n['times'] ) ) continue;
            $avg   = array_sum( $n['times'] ) / count( $n['times'] );
            $slug  = $n['slug'];
            $cc    = $slug_cc[ $slug ] ?? self::node_flag( $name );
            $cont  = strtolower( $n['continent'] );
            $region = $continent_map[ $cont ] ?? self::node_region( $name );
            $output[] = [
                'node'        => $name,
                'flag'        => $cc,
                'region'      => $region,
                'avg_ms'      => round( $avg ),
                'avg_s'       => round( $avg / 1000, 2 ),
                'check_count' => $n['total'],
                'error_count' => $n['errors'],
            ];
        }
        usort( $output, fn( $a, $b ) => $a['avg_ms'] <=> $b['avg_ms'] );

        $status = 'unknown'; $monitor_name = '';
        if ( ! is_wp_error( $monitor ) ) {
            $m      = $monitor['data'] ?? $monitor;
            $status = self::normalize_status( $m );
            $monitor_name = $m['name'] ?? $m['url'] ?? '';
        }
        return [
            'nodes'        => $output,
            'total_nodes'  => count( $output ),
            'status'       => $status,
            'monitor_name' => $monitor_name,
            'window_days'  => $days,
            'row_count'    => count( $rows ),
            'fetched_at'   => gmdate( 'c' ),
        ];
    }

    /**
     * Returns a 2-letter ISO country code for use with flagcdn.com.
     * e.g. "us" → https://flagcdn.com/24x18/us.png
     */
    public static function node_flag( $node ) {
        $n   = strtolower( $node );
        $map = [
            'new york'      => 'us', 'ashburn'      => 'us', 'los angeles'   => 'us',
            'dallas'        => 'us', 'chicago'      => 'us', 'miami'         => 'us',
            'seattle'       => 'us', 'united states' => 'us',
            'toronto'       => 'ca', 'montreal'     => 'ca', 'vancouver'     => 'ca',
            'sao paulo'     => 'br', 'brazil'       => 'br',
            'london'        => 'gb', 'united kingdom' => 'gb',
            'paris'         => 'fr', 'france'       => 'fr',
            'nuremberg'     => 'de', 'frankfurt'    => 'de', 'berlin'        => 'de', 'germany' => 'de',
            'amsterdam'     => 'nl', 'netherlands'  => 'nl',
            'stockholm'     => 'se', 'sweden'       => 'se',
            'helsinki'      => 'fi', 'finland'      => 'fi',
            'madrid'        => 'es', 'spain'        => 'es',
            'milan'         => 'it', 'italy'        => 'it',
            'warsaw'        => 'pl', 'poland'       => 'pl',
            'prague'        => 'cz', 'bucharest'    => 'ro',
            'johannesburg'  => 'za', 'cape town'    => 'za', 'south africa'  => 'za',
            'lagos'         => 'ng', 'nairobi'      => 'ke',
            'singapore'     => 'sg',
            'bangalore'     => 'in', 'mumbai'       => 'in', 'india'         => 'in',
            'tokyo'         => 'jp', 'osaka'        => 'jp', 'japan'         => 'jp',
            'hong kong'     => 'hk', 'seoul'        => 'kr',
            'taipei'        => 'tw',
            'sydney'        => 'au', 'melbourne'    => 'au', 'australia'     => 'au',
            'dubai'         => 'ae', 'tel aviv'     => 'il',
            'istanbul'      => 'tr',
            'santiago'      => 'cl', 'buenos aires' => 'ar', 'mexico'        => 'mx',
            'vienna'        => 'at', 'zurich'       => 'ch', 'lisbon'        => 'pt',
        ];
        foreach ( $map as $kw => $cc ) { if ( strpos( $n, $kw ) !== false ) return $cc; }
        return '';
    }

    public static function node_region( $node ) {
        $n   = strtolower( $node );
        $map = [
            'North America' => [ 'new york', 'ashburn', 'los angeles', 'dallas', 'chicago', 'miami', 'seattle', 'toronto', 'montreal', 'vancouver', 'mexico', 'us', 'canada' ],
            'Europe'        => [ 'london', 'paris', 'nuremberg', 'frankfurt', 'amsterdam', 'stockholm', 'helsinki', 'madrid', 'milan', 'warsaw', 'prague', 'bucharest', 'berlin', 'lisbon', 'vienna', 'zurich', 'istanbul', 'uk', 'gb', 'germany', 'france', 'netherlands', 'sweden', 'finland', 'spain', 'italy', 'poland', 'turkey' ],
            'Asia Pacific'  => [ 'singapore', 'bangalore', 'mumbai', 'tokyo', 'osaka', 'hong kong', 'seoul', 'taipei', 'sydney', 'melbourne', 'india', 'japan', 'australia', 'korea' ],
            'Middle East'   => [ 'dubai', 'tel aviv', 'riyadh', 'bahrain', 'uae', 'israel' ],
            'South America' => [ 'sao paulo', 'buenos aires', 'santiago', 'bogota', 'brazil', 'argentina', 'chile' ],
            'Africa'        => [ 'johannesburg', 'cape town', 'lagos', 'nairobi', 'south africa', 'nigeria', 'kenya' ],
        ];
        foreach ( $map as $region => $keywords ) {
            foreach ( $keywords as $kw ) { if ( strpos( $n, $kw ) !== false ) return $region; }
        }
        return 'Other';
    }

    /**
     * Derive city name from Bunny CDN Server response header.
     * Pulsetic checks don't include node names — only node_id.
     * The CDN-Server header (e.g. "BunnyCDN-NY1-885") reliably identifies the
     * monitoring location since Bunny serves requests from the nearest PoP.
     */
    public static function location_from_cdn_headers( $headers_json ) {
        if ( empty( $headers_json ) ) return null;
        $headers = json_decode( $headers_json, true );
        if ( ! is_array( $headers ) ) return null;

        $server = '';
        $cc     = '';
        foreach ( $headers as $h ) {
            $n = $h['name'] ?? '';
            if ( $n === 'Server' )                  $server = $h['value'] ?? '';
            if ( $n === 'CDN-RequestCountryCode' )  $cc     = strtolower( $h['value'] ?? '' );
        }

        // Map Bunny CDN server prefix → city
        $map = [
            'BunnyCDN-NY'  => 'New York',
            'BunnyCDN-UK'  => 'London',
            'BunnyCDN-CA'  => 'Toronto',
            'BunnyCDN-BR'  => 'Sao Paulo',
            'BunnyCDN-IN'  => 'Bangalore',
            'BunnyCDN-SG'  => 'Singapore',
            'BunnyCDN-FR'  => 'Paris',
            'BunnyCDN-JP'  => 'Tokyo',
            'BunnyCDN-SYD' => 'Sydney',
            'BunnyCDN-JH'  => 'Johannesburg',
            'BunnyCDN-SE'  => 'Stockholm',
            'BunnyCDN-LA'  => 'Los Angeles',
            'BunnyCDN-ASB' => 'Ashburn',
            'BunnyCDN-STO' => 'Stockholm',
            'BunnyCDN-AMS' => 'Amsterdam',
            'BunnyCDN-MAD' => 'Madrid',
            'BunnyCDN-MIL' => 'Milan',
            'BunnyCDN-WAR' => 'Warsaw',
        ];

        foreach ( $map as $prefix => $city ) {
            if ( strpos( $server, $prefix ) === 0 ) return $city;
        }

        // BunnyCDN-DE1 serves multiple countries — use country code to disambiguate
        if ( strpos( $server, 'BunnyCDN-DE' ) === 0 ) {
            $cc_override = [ 'fi' => 'Helsinki', 'pl' => 'Warsaw', 'cz' => 'Prague', 'at' => 'Vienna', 'de' => 'Frankfurt' ];
            return $cc_override[ $cc ] ?? 'Frankfurt';
        }

        // Fallback: country code → city
        $cc_map = [
            'gb' => 'London',   'us' => 'New York', 'de' => 'Frankfurt',
            'fr' => 'Paris',    'jp' => 'Tokyo',    'au' => 'Sydney',
            'sg' => 'Singapore','in' => 'Bangalore','br' => 'Sao Paulo',
            'ca' => 'Toronto',  'za' => 'Johannesburg','se' => 'Stockholm',
            'fi' => 'Helsinki', 'kr' => 'Seoul',    'nl' => 'Amsterdam',
        ];
        return $cc_map[ $cc ] ?? null;
    }

    /**
     * Pulsetic may return: is_up (bool), status (string), or various string values.
     */
    public static function normalize_status( $m ) {
        // Boolean is_up field takes priority
        if ( isset( $m['is_up'] ) ) return $m['is_up'] ? 'up' : 'down';
        $raw = strtolower( (string) ( $m['status'] ?? '' ) );
        if ( in_array( $raw, [ 'up', 'ok', 'online', 'active', 'running', '1', 'true', 'alive' ], true ) ) return 'up';
        if ( in_array( $raw, [ 'down', 'offline', 'error', 'failed', '0', 'false', 'inactive', 'unreachable' ], true ) ) return 'down';
        return 'unknown';
    }

    /**
     * Dump raw API responses for debugging — used by admin Debug tab.
     */
    public static function debug_raw( $monitor_id ) {
        $monitor = self::get_monitor( $monitor_id );
        $end     = gmdate( 'Y-m-d H:i:s' );
        $start   = gmdate( 'Y-m-d H:i:s', time() - 60 * 60 ); // last 1 hour
        $token   = self::token();

        // Fetch checks raw so we can see body even if JSON decode fails
        $checks_url = self::BASE_URL . "/monitors/{$monitor_id}/checks?" . http_build_query( [ 'start_dt' => $start, 'end_dt' => $end, 'per_page' => 3 ] );
        $checks_raw = wp_remote_get( $checks_url, [ 'timeout' => 15, 'headers' => [ 'Authorization' => $token ] ] );
        $checks_code = is_wp_error( $checks_raw ) ? 'WP_Error' : wp_remote_retrieve_response_code( $checks_raw );
        $checks_body = is_wp_error( $checks_raw ) ? $checks_raw->get_error_message() : wp_remote_retrieve_body( $checks_raw );
        $checks_decoded = json_decode( $checks_body, true );

        $down  = self::get( "/monitors/{$monitor_id}/downtime", [ 'seconds' => 30 * DAY_IN_SECONDS ] );
        $stats = self::get( "/monitors/{$monitor_id}/stats" );

        $snaps = self::get( "/monitors/{$monitor_id}/snapshots", [
            'start_dt' => gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) ),
            'end_dt'   => gmdate( 'Y-m-d H:i:s' ),
            'per_page' => 5,
        ] );

        return [
            'snapshots_sample'  => is_wp_error( $snaps ) ? $snaps->get_error_message() : $snaps,
            'monitor'          => is_wp_error( $monitor ) ? $monitor->get_error_message() : $monitor,
            'checks_http_code' => $checks_code,
            'checks_decoded'   => $checks_decoded,
            'checks_raw_body'  => substr( $checks_body, 0, 2000 ),
            'checks_url'       => $checks_url,
            'downtime_raw'     => is_wp_error( $down ) ? $down->get_error_message() : $down,
            'stats'            => is_wp_error( $stats ) ? $stats->get_error_message() : $stats,
            'time_window'      => [ 'start' => $start, 'end' => $end ],
        ];
    }

    public static function token() {
        $s = get_option( 'psw_settings', [] );
        return trim( $s['api_token'] ?? '' );
    }
}
