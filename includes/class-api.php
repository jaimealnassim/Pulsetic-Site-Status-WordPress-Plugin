<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Pulsetic_API
 *
 * Handles all communication with the Pulsetic REST API.
 *
 * Caching strategy:
 *  - Results stored in a transient for pulsetic_get_cache_ttl() seconds (admin-configurable).
 *  - A static in-memory cache prevents duplicate API calls within a single page request.
 *  - Stale-while-revalidate: within pulsetic_stale_window() seconds of expiry, a WP-Cron
 *    single event refreshes the cache in the background so visitors never block on a live fetch.
 */
class Pulsetic_API {

	/** In-process cache: avoids duplicate fetches within one page load. */
	private static ?array $runtime_cache = null;

	/** @return array|WP_Error */
	public static function get_monitors() {
		// 1. In-memory cache (within same request)
		if ( self::$runtime_cache !== null ) {
			return self::$runtime_cache;
		}

		// 2. Transient cache
		$cached = get_transient( PULSETIC_CACHE_KEY );
		if ( $cached !== false ) {
			self::$runtime_cache = $cached;

			// Schedule background refresh if within the stale window before expiry
			$timeout = get_option( '_transient_timeout_' . PULSETIC_CACHE_KEY, 0 );
			if ( $timeout && ( $timeout - time() ) < pulsetic_stale_window() ) {
				self::schedule_background_refresh();
			}

			return self::$runtime_cache;
		}

		// 3. Live fetch
		return self::fetch_and_cache();
	}

	/** Force-refresh: bust caches and re-fetch. */
	public static function refresh(): array|WP_Error {
		self::$runtime_cache = null;
		delete_transient( PULSETIC_CACHE_KEY );
		return self::fetch_and_cache();
	}

	/** @return array|WP_Error */
	private static function fetch_and_cache() {
		$token = get_option( PULSETIC_OPT_TOKEN, '' );
		if ( empty( $token ) ) {
			return new WP_Error( 'no_token', 'No API token configured.' );
		}

		$response = wp_remote_get( PULSETIC_API_BASE . '/monitors?per_page=100', [
			'headers' => [
				'Authorization' => $token,
				'Accept'        => 'application/json',
			],
			'timeout' => 12,
		] );

		if ( is_wp_error( $response ) ) return $response;

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code === 401 ) return new WP_Error( 'unauthorized', 'Invalid API token (401).' );
		if ( $code !== 200 ) return new WP_Error( 'bad_status', "API returned HTTP {$code}." );

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		$monitors = self::extract_monitors( $data, $body );
		if ( is_wp_error( $monitors ) ) return $monitors;

		set_transient( PULSETIC_CACHE_KEY, $monitors, pulsetic_get_cache_ttl() );
		self::$runtime_cache = $monitors;

		return $monitors;
	}

	/** Normalise various API response shapes into a flat monitor array. */
	private static function extract_monitors( mixed $data, string $raw_body ): array|WP_Error {
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'bad_response', 'Could not parse API response. Raw: ' . substr( $raw_body, 0, 200 ) );
		}

		if ( isset( $data['monitors'] ) && is_array( $data['monitors'] ) ) return $data['monitors'];
		if ( isset( $data['data'] )     && is_array( $data['data'] ) )     return $data['data'];
		if ( array_key_exists( 0, $data ) )                                return $data;

		$first = reset( $data );
		if ( is_array( $first ) ) return array_values( $data );

		return new WP_Error( 'empty', 'API returned no monitors. Check token permissions.' );
	}

	/** Schedule a one-off WP-Cron event to refresh the cache in the background. */
	private static function schedule_background_refresh(): void {
		if ( ! wp_next_scheduled( 'pulsetic_background_refresh' ) ) {
			wp_schedule_single_event( time(), 'pulsetic_background_refresh' );
		}
	}

	/** Format a raw monitor array into the shape used by JS / shortcode. */
	public static function format_monitor( array $m ): array {
		return [
			'id'     => (string) ( $m['id'] ?? '' ),
			'name'   => pulsetic_display_name( $m ),
			'url'    => $m['url'] ?? '',
			'status' => pulsetic_resolve_status( $m ),
		];
	}
}

// Background refresh hook
add_action( 'pulsetic_background_refresh', [ 'Pulsetic_API', 'refresh' ] );
