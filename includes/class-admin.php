<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Pulsetic_Admin {

	public function init(): void {
		add_action( 'admin_menu',                        [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts',             [ $this, 'enqueue_assets' ] );
		add_action( 'admin_post_pulsetic_save_settings', [ $this, 'handle_save' ] );
	}

	public function register_menu(): void {
		add_options_page(
			'Pulsetic Status',
			'Pulsetic Status',
			'manage_options',
			'pulsetic-status',
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( $hook !== 'settings_page_pulsetic-status' ) return;

		wp_enqueue_style(
			'spectrum',
			'https://cdnjs.cloudflare.com/ajax/libs/spectrum/1.8.1/spectrum.min.css',
			[],
			'1.8.1'
		);
		wp_enqueue_script(
			'spectrum',
			'https://cdnjs.cloudflare.com/ajax/libs/spectrum/1.8.1/spectrum.min.js',
			[ 'jquery' ],
			'1.8.1',
			true
		);
		wp_enqueue_style(
			'pulsetic-admin',
			PULSETIC_URL . 'assets/css/admin.css',
			[],
			PULSETIC_VERSION
		);
		wp_enqueue_script(
			'pulsetic-admin',
			PULSETIC_URL . 'assets/js/admin.js',
			[ 'jquery', 'spectrum' ],
			PULSETIC_VERSION,
			true
		);

		// Only attempt monitor fetch if a token is configured
		$token      = get_option( PULSETIC_OPT_TOKEN, '' );
		$mon_js     = [];
		if ( ! empty( $token ) ) {
			$monitors_raw = Pulsetic_API::get_monitors();
			if ( $monitors_raw && ! is_wp_error( $monitors_raw ) ) {
				foreach ( $monitors_raw as $m ) {
					$mon_js[] = Pulsetic_API::format_monitor( $m );
				}
			}
		}

		wp_localize_script( 'pulsetic-admin', 'pulseticAdmin', [
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( 'pulsetic_ajax_nonce' ),
			'monitors'      => $mon_js,
			'groupCount'    => count( pulsetic_get_groups() ),
			'defaultColors' => pulsetic_default_colors(),
			'defaultSizes'  => pulsetic_default_sizes(),
		] );
	}

	public function handle_save(): void {
		check_admin_referer( 'pulsetic_save_settings' );

		// Capability check after nonce so nonce acts as the first gate
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'pulsetic-site-status' ), 403 );
		}

		// Token
		$new_token = sanitize_text_field( wp_unslash( $_POST['pulsetic_api_token'] ?? '' ) );
		$old_token = get_option( PULSETIC_OPT_TOKEN, '' );
		update_option( PULSETIC_OPT_TOKEN, $new_token );
		pulsetic_flush_option_cache( PULSETIC_OPT_TOKEN );
		if ( $new_token !== $old_token ) {
			delete_transient( PULSETIC_CACHE_KEY );
		}

		// Scan interval — validate against strict allowlist
		$allowed_intervals = array_keys( pulsetic_scan_interval_options() );
		$new_interval      = (int) ( $_POST['pulsetic_scan_interval'] ?? 300 );
		if ( ! in_array( $new_interval, $allowed_intervals, true ) ) {
			$new_interval = 300; // safe default: 5 minutes
		}
		$old_interval = (int) pulsetic_get_option( 'pulsetic_scan_interval', 300 );
		update_option( 'pulsetic_scan_interval', $new_interval );
		pulsetic_flush_option_cache( 'pulsetic_scan_interval' );
		// Bust the cache so the new TTL applies on the next request
		if ( $new_interval !== $old_interval ) {
			delete_transient( PULSETIC_CACHE_KEY );
		}

		// Colors
		$color_defaults = pulsetic_default_colors();
		$colors         = [];
		$posted_colors  = isset( $_POST['pulsetic_color'] ) && is_array( $_POST['pulsetic_color'] )
			? $_POST['pulsetic_color']
			: [];
		foreach ( array_keys( $color_defaults ) as $k ) {
			$raw        = pulsetic_sanitize_css_value( (string) ( $posted_colors[ $k ] ?? '' ) );
			$colors[$k] = $raw !== '' ? $raw : $color_defaults[$k];
		}
		update_option( PULSETIC_OPT_COLOR, $colors );
		pulsetic_flush_option_cache( PULSETIC_OPT_COLOR );

		// Sizes
		$size_defaults = pulsetic_default_sizes();
		$sizes         = [];
		$posted_sizes  = isset( $_POST['pulsetic_sizes'] ) && is_array( $_POST['pulsetic_sizes'] )
			? $_POST['pulsetic_sizes']
			: [];
		foreach ( array_keys( $size_defaults ) as $k ) {
			$raw       = pulsetic_sanitize_css_value( (string) ( $posted_sizes[ $k ] ?? '' ) );
			$sizes[$k] = $raw !== '' ? $raw : $size_defaults[$k];
		}
		update_option( 'pulsetic_sizes', $sizes );
		pulsetic_flush_option_cache( 'pulsetic_sizes' );

		// Groups
		$saved         = [];
		$posted_groups = isset( $_POST['pulsetic_groups'] ) && is_array( $_POST['pulsetic_groups'] )
			? $_POST['pulsetic_groups']
			: [];

		foreach ( $posted_groups as $g ) {
			if ( ! is_array( $g ) ) continue;

			$gid  = sanitize_key( $g['id']   ?? '' );
			if ( $gid === '' ) continue;

			$gname = sanitize_text_field( wp_unslash( $g['name'] ?? 'Group' ) );
			$mons  = array_values( array_map(
				'sanitize_text_field',
				array_map( 'wp_unslash', (array) ( $g['monitors'] ?? [] ) )
			) );

			$labels = [];
			foreach ( (array) ( $g['labels'] ?? [] ) as $mid => $lbl ) {
				$clean_mid            = sanitize_text_field( (string) $mid );
				$labels[ $clean_mid ] = sanitize_text_field( wp_unslash( (string) $lbl ) );
			}

			$links = [];
			foreach ( (array) ( $g['links'] ?? [] ) as $mid => $url ) {
				$clean_mid           = sanitize_text_field( (string) $mid );
				$links[ $clean_mid ] = esc_url_raw( wp_unslash( (string) $url ) );
			}

			$saved[] = [
				'id'       => $gid,
				'name'     => $gname,
				'monitors' => $mons,
				'labels'   => $labels,
				'links'    => $links,
			];
		}

		if ( empty( $saved ) ) {
			$saved = [ [ 'id' => 'default', 'name' => 'Default', 'monitors' => [], 'labels' => [], 'links' => [] ] ];
		}
		update_option( PULSETIC_OPT_GROUPS, $saved );
		pulsetic_flush_option_cache( PULSETIC_OPT_GROUPS );

		// Use wp_safe_redirect — prevents open-redirect if admin_url is somehow tampered
		wp_safe_redirect( add_query_arg(
			[ 'page' => 'pulsetic-status', 'updated' => '1' ],
			admin_url( 'options-general.php' )
		) );
		exit;
	}

	public function render_page(): void {
		// Verify capability before rendering
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'pulsetic-site-status' ) );
		}

		$token            = get_option( PULSETIC_OPT_TOKEN, '' );
		$colors           = pulsetic_get_colors();
		$defaults         = pulsetic_default_colors();
		$sizes            = pulsetic_get_sizes();
		$size_defaults    = pulsetic_default_sizes();
		$groups           = pulsetic_get_groups();
		$scan_interval    = pulsetic_get_cache_ttl();
		$interval_options = pulsetic_scan_interval_options();
		$monitors         = ! empty( $token ) ? Pulsetic_API::get_monitors() : null;
		$updated          = isset( $_GET['updated'] ) && current_user_can( 'manage_options' );

		$mon_js = [];
		if ( $monitors && ! is_wp_error( $monitors ) ) {
			foreach ( $monitors as $m ) {
				$mon_js[] = Pulsetic_API::format_monitor( $m );
			}
		}

		include PULSETIC_PATH . 'includes/views/admin-page.php';
	}
}
