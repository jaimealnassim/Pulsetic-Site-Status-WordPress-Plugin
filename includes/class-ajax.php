<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Pulsetic_Ajax {

	public function init(): void {
		// ── Admin ajax (force-refresh, admin-only) ────────────────────────────
		add_action( 'wp_ajax_pulsetic_fetch_monitors', [ $this, 'admin_refresh' ] );

		// ── Frontend poll via WP REST API (preferred) ─────────────────────────
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

		// ── Frontend poll via admin-ajax (fallback for hosts that block REST) ──
		add_action( 'wp_ajax_pulsetic_poll_group',        [ $this, 'poll_group_ajax' ] );
		add_action( 'wp_ajax_nopriv_pulsetic_poll_group', [ $this, 'poll_group_ajax' ] );
	}

	// ── REST routes ───────────────────────────────────────────────────────────

	public function register_rest_routes(): void {
		register_rest_route( 'pulsetic/v1', '/status/(?P<group>[a-z0-9\-]+)', [
			'methods'             => WP_REST_Server::READABLE, // GET
			'callback'            => [ $this, 'rest_poll_group' ],
			'permission_callback' => '__return_true', // public endpoint — data is already public
			'args'                => [
				'group' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
					'validate_callback' => function( string $v ): bool {
						// Must match a real group slug
						return pulsetic_find_group( $v ) !== null;
					},
				],
			],
		] );
	}

	/**
	 * REST GET /wp-json/pulsetic/v1/status/{group}
	 *
	 * No nonce required — this is a public GET endpoint, equivalent to
	 * visiting a status page. The data (site names + up/down) is already
	 * publicly displayed on the frontend. Rate-limiting is implicit through
	 * the server-side transient cache; the endpoint never forces a live fetch.
	 *
	 * Benefits over admin-ajax:
	 *  - Proper HTTP caching headers (Cache-Control, ETag via WP REST)
	 *  - Cleaner URL, easier to inspect/debug
	 *  - No POST overhead for a read-only operation
	 *  - Works with page caching more predictably
	 */
	public function rest_poll_group( WP_REST_Request $request ): WP_REST_Response {
		$group_id = $request->get_param( 'group' );
		$result   = $this->build_poll_payload( $group_id );

		$response = new WP_REST_Response( $result, 200 );

		// Let CDNs and browsers cache for half the scan interval, revalidate after
		$ttl = max( 30, (int) ( pulsetic_get_cache_ttl() / 2 ) );
		$response->header( 'Cache-Control', 'public, max-age=' . $ttl . ', stale-while-revalidate=' . $ttl );

		return $response;
	}

	// ── admin-ajax fallback ───────────────────────────────────────────────────

	/**
	 * POST admin-ajax.php?action=pulsetic_poll_group
	 * Kept as fallback for hosts/configs that block /wp-json/ requests.
	 */
	public function poll_group_ajax(): void {
		check_ajax_referer( 'pulsetic_frontend_nonce', 'nonce' );

		$group_id = sanitize_key( $_POST['group'] ?? 'default' );
		$result   = $this->build_poll_payload( $group_id );

		wp_send_json_success( $result );
	}

	// ── Admin force-refresh ───────────────────────────────────────────────────

	/**
	 * POST admin-ajax.php?action=pulsetic_fetch_monitors
	 * Busts the transient cache and returns the full monitor list.
	 */
	public function admin_refresh(): void {
		check_ajax_referer( 'pulsetic_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
		}

		$monitors = Pulsetic_API::refresh();
		if ( is_wp_error( $monitors ) ) {
			wp_send_json_error( [ 'message' => $monitors->get_error_message() ] );
		}

		$formatted = array_values( array_map( [ 'Pulsetic_API', 'format_monitor' ], $monitors ) );
		wp_send_json_success( [ 'monitors' => $formatted ] );
	}

	// ── Shared payload builder ────────────────────────────────────────────────

	/**
	 * Build the status payload for a given group slug.
	 * Used by both the REST endpoint and the admin-ajax fallback so the
	 * response shape is always identical.
	 *
	 * @return array{ items: array<int,array<string,mixed>>, cache_ttl: int }
	 */
	private function build_poll_payload( string $group_id ): array {
		$monitors = Pulsetic_API::get_monitors();

		if ( is_wp_error( $monitors ) ) {
			// Return empty payload — frontend keeps existing DOM, no crash
			return [ 'items' => [], 'cache_ttl' => 0, 'error' => $monitors->get_error_message() ];
		}

		$group = pulsetic_find_group( $group_id );
		[ $monitors, $custom_labels, $custom_links ] = pulsetic_filter_group( $monitors, $group );

		$items = [];
		foreach ( $monitors as $m ) {
			$mid     = (string) ( $m['id'] ?? '' );
			$items[] = [
				'id'           => $mid,
				'status'       => pulsetic_resolve_status( $m ),
				'display_name' => ( $custom_labels[ $mid ] ?? '' ) !== ''
					? $custom_labels[ $mid ]
					: pulsetic_display_name( $m ),
				'url'          => (string) ( $m['url'] ?? '' ),
				'custom_link'  => (string) ( $custom_links[ $mid ] ?? '' ),
			];
		}

		$timeout   = (int) get_option( '_transient_timeout_' . PULSETIC_CACHE_KEY, 0 );
		$cache_ttl = $timeout > 0 ? max( 0, $timeout - time() ) : 0;

		return [ 'items' => $items, 'cache_ttl' => $cache_ttl ];
	}
}
