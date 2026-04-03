<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Pulsetic_Ajax {

	public function init(): void {
		add_action( 'wp_ajax_pulsetic_fetch_monitors',    [ $this, 'admin_refresh' ] );
		add_action( 'wp_ajax_pulsetic_poll_group',        [ $this, 'poll_group' ] );
		add_action( 'wp_ajax_nopriv_pulsetic_poll_group', [ $this, 'poll_group' ] );
	}

	/**
	 * Admin refresh — busts the transient cache and returns the full monitor list.
	 * Nonce is verified first, then capability, so an attacker can't probe
	 * the capability check without a valid nonce.
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

	/**
	 * Frontend poll — returns lightweight status-only payload.
	 *
	 * Deliberately uses the transient cache so visitors cannot trigger live
	 * Pulsetic API fetches on every poll. Rate-limiting is implicit through
	 * the cache TTL.
	 */
	public function poll_group(): void {
		check_ajax_referer( 'pulsetic_frontend_nonce', 'nonce' );

		$group_id = sanitize_key( $_POST['group'] ?? 'default' );
		$monitors = Pulsetic_API::get_monitors();

		if ( is_wp_error( $monitors ) ) {
			wp_send_json_error( [ 'message' => $monitors->get_error_message() ] );
		}

		// Use shared helper — consistent with shortcode group resolution
		$group = pulsetic_find_group( $group_id );
		[ $monitors, $custom_labels, $custom_links ] = pulsetic_filter_group( $monitors, $group );

		$items = [];
		foreach ( $monitors as $m ) {
			$mid     = (string) ( $m['id'] ?? '' );
			$items[] = [
				'id'           => $mid,
				'status'       => pulsetic_resolve_status( $m ),
				'display_name' => isset( $custom_labels[ $mid ] ) && $custom_labels[ $mid ] !== ''
					? $custom_labels[ $mid ]
					: pulsetic_display_name( $m ),
				'url'          => (string) ( $m['url'] ?? '' ),
				'custom_link'  => (string) ( $custom_links[ $mid ] ?? '' ),
			];
		}

		// Cache TTL — lets the frontend show a "refreshes in X" countdown.
		// We read the raw option rather than the transient because get_transient()
		// doesn't expose TTL. This is the standard WP pattern for this purpose.
		$timeout   = (int) get_option( '_transient_timeout_' . PULSETIC_CACHE_KEY, 0 );
		$cache_ttl = $timeout > 0 ? max( 0, $timeout - time() ) : 0;

		wp_send_json_success( [
			'items'     => $items,
			'cache_ttl' => $cache_ttl,
		] );
	}
}
