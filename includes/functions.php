<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── In-request option cache ────────────────────────────────────────────────────
// Prevents repeated get_option() calls within the same page load when multiple
// shortcodes, admin pages, or AJAX handlers run in one request.

/** @var array<string,mixed> */
$_pulsetic_option_cache = [];

function pulsetic_get_option( string $key, mixed $default = false ): mixed {
	global $_pulsetic_option_cache;
	if ( ! array_key_exists( $key, $_pulsetic_option_cache ) ) {
		$_pulsetic_option_cache[ $key ] = get_option( $key, $default );
	}
	return $_pulsetic_option_cache[ $key ];
}

function pulsetic_flush_option_cache( string $key ): void {
	global $_pulsetic_option_cache;
	unset( $_pulsetic_option_cache[ $key ] );
}

// ── Color defaults ─────────────────────────────────────────────────────────────

function pulsetic_default_colors(): array {
	return [
		'online_dot'         => '#22c55e',
		'online_badge_bg'    => '#dcfce7',
		'online_badge_text'  => '#15803d',
		'offline_dot'        => '#ef4444',
		'offline_badge_bg'   => '#fee2e2',
		'offline_badge_text' => '#b91c1c',
		'paused_dot'         => '#f59e0b',
		'paused_badge_bg'    => '#fef9c3',
		'paused_badge_text'  => '#92400e',
	];
}

function pulsetic_get_colors(): array {
	return wp_parse_args( pulsetic_get_option( PULSETIC_OPT_COLOR, [] ), pulsetic_default_colors() );
}

// ── Size defaults ──────────────────────────────────────────────────────────────

function pulsetic_default_sizes(): array {
	return [
		'dot_size'        => '10px',
		'item_font_size'  => '.94em',
		'badge_font_size' => '.73em',
	];
}

function pulsetic_get_sizes(): array {
	return wp_parse_args( pulsetic_get_option( 'pulsetic_sizes', [] ), pulsetic_default_sizes() );
}

// ── Scan interval ──────────────────────────────────────────────────────────────

/**
 * Allowed scan interval options in seconds → display label.
 * Keeping a strict allowlist means we never cache for an arbitrary duration.
 *
 * @return array<int,string>
 */
function pulsetic_scan_interval_options(): array {
	return [
		60    => '1 minute',
		120   => '2 minutes',
		300   => '5 minutes',
		600   => '10 minutes',
		900   => '15 minutes',
		1800  => '30 minutes',
		3600  => '1 hour',
	];
}

/**
 * Return the active scan interval in seconds.
 * Falls back to 5 minutes if the stored value is not in the allowlist.
 */
function pulsetic_get_cache_ttl(): int {
	$stored  = (int) pulsetic_get_option( 'pulsetic_scan_interval', 300 );
	$allowed = array_keys( pulsetic_scan_interval_options() );
	return in_array( $stored, $allowed, true ) ? $stored : 300;
}

/**
 * Stale-while-revalidate window: 10% of the TTL, clamped between 30 s and 120 s.
 * This is how far before expiry we schedule the background WP-Cron refresh.
 */
function pulsetic_stale_window(): int {
	return (int) min( 120, max( 30, pulsetic_get_cache_ttl() * 0.1 ) );
}

function pulsetic_get_groups(): array {
	$g = pulsetic_get_option( PULSETIC_OPT_GROUPS, [] );
	if ( empty( $g ) || ! is_array( $g ) ) {
		$g = [ [ 'id' => 'default', 'name' => 'Default', 'monitors' => [], 'labels' => [], 'links' => [] ] ];
	}
	return $g;
}

// ── Sanitisation ───────────────────────────────────────────────────────────────

/**
 * Permissive CSS value sanitizer.
 *
 * Accepts: #hex, rgb/rgba(), hsl/hsla(), named colors, CSS custom properties
 * (var(--token)), ACSS tokens, px/em/rem/%/vw, calc(), clamp().
 * Blocks: anything with <, >, quotes, braces, `javascript`, `expression`, `@import`.
 */
function pulsetic_sanitize_css_value( string $value ): string {
	$value = trim( $value );

	if ( $value === '' ) return '';

	// Block injection vectors
	if ( preg_match( '/[<>"\'{}]|javascript\s*:|expression\s*\(|@import/i', $value ) ) {
		return '';
	}

	// Allow: word chars, spaces, #, (, ), ,, ., -, %, /
	if ( preg_match( '/^[\w\s#(),.\-\/%]+$/', $value ) ) {
		return $value;
	}

	return '';
}

/**
 * Build the CSS :root block for color + size custom properties.
 * Values are sanitized at save time; we re-sanitize here as defence-in-depth
 * before writing to HTML output.
 */
function pulsetic_build_css_vars( array $colors, array $sizes ): string {
	$out = ':root{';
	foreach ( array_merge( $colors, $sizes ) as $key => $value ) {
		$prop   = '--pulsetic-' . str_replace( '_', '-', sanitize_key( $key ) );
		$safe_v = pulsetic_sanitize_css_value( (string) $value );
		if ( $safe_v !== '' ) {
			$out .= $prop . ':' . $safe_v . ';';
		}
	}
	$out .= '}';
	return $out;
}

// ── Status resolution ──────────────────────────────────────────────────────────

/**
 * Resolve monitor status.
 * Priority: explicit down > paused (not running) > up signals > uptime fallback.
 *
 * @param array<string,mixed> $m Raw monitor from API.
 * @return 'online'|'offline'|'paused'
 */
function pulsetic_resolve_status( array $m ): string {
	$raw     = strtolower( trim( (string) ( $m['status'] ?? '' ) ) );
	$running = isset( $m['is_running'] ) ? (bool) $m['is_running'] : true;

	if ( in_array( $raw, [ 'down', 'offline', '0' ], true ) ) return 'offline';
	if ( ! $running && ! in_array( $raw, [ 'up', 'online', '1' ], true ) ) return 'paused';
	if ( in_array( $raw, [ 'up', 'online', '1' ], true ) ) return 'online';
	if ( isset( $m['uptime'] ) && (float) $m['uptime'] > 0 ) return 'online';

	return 'paused';
}

/**
 * Extract a human-readable display name from a raw monitor array.
 *
 * @param array<string,mixed> $m
 */
function pulsetic_display_name( array $m ): string {
	$raw_name = (string) ( $m['name'] ?? '' );
	$raw_url  = (string) ( $m['url']  ?? '' );
	return ( $raw_name !== '' && $raw_name !== $raw_url )
		? $raw_name
		: ( (string) parse_url( $raw_url, PHP_URL_HOST ) ?: $raw_url );
}

/**
 * Filter $all_monitors down to the ones selected for $group.
 *
 * @param  array<int,array<string,mixed>> $all_monitors
 * @param  array<string,mixed>|null       $group
 * @return array{ 0: array<int,array<string,mixed>>, 1: array<string,string>, 2: array<string,string> }
 */
function pulsetic_filter_group( array $all_monitors, ?array $group ): array {
	if ( $group === null ) {
		return [ [], [], [] ];
	}

	$selected      = (array) ( $group['monitors'] ?? [] );
	$custom_labels = (array) ( $group['labels']   ?? [] );
	$custom_links  = (array) ( $group['links']    ?? [] );

	if ( ! empty( $selected ) ) {
		$all_monitors = array_values( array_filter( $all_monitors, function( array $m ) use ( $selected ): bool {
			return in_array( (string) ( $m['id'] ?? '' ), $selected, true );
		} ) );
	}

	return [ $all_monitors, $custom_labels, $custom_links ];
}

/**
 * Find a group by slug. Returns null (not first group) if not found —
 * callers must handle null explicitly.
 *
 * @return array<string,mixed>|null
 */
function pulsetic_find_group( string $slug ): ?array {
	foreach ( pulsetic_get_groups() as $g ) {
		if ( ( $g['id'] ?? '' ) === $slug ) return $g;
	}
	return null;
}

/**
 * Build the inline <script> that registers a widget instance for AJAX polling.
 * wp_json_encode flags JSON_HEX_TAG | JSON_HEX_AMP by default which prevents
 * </script> injection inside the JSON payload.
 *
 * @param array<string,mixed> $config
 */
function pulsetic_poll_script( array $config ): string {
	$json = wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
	return '<script>window.pulseticInstances=window.pulseticInstances||[];window.pulseticInstances.push(' . $json . ');</script>';
}

// ── Shared frontend asset enqueue ─────────────────────────────────────────────

/**
 * Enqueue the frontend stylesheet + JS exactly once per page, regardless of
 * how many shortcodes are used or which style they use.
 *
 * @param bool $already_done Pass the class's $assets_enqueued flag.
 *                           The function reads it but does not mutate it —
 *                           caller must flip the flag after calling.
 */
function pulsetic_enqueue_frontend_assets( bool $already_done ): void {
	if ( $already_done ) return;

	// Style — guard against multiple shortcode classes on the same page
	if ( ! wp_style_is( 'pulsetic-frontend', 'enqueued' ) ) {
		wp_enqueue_style(
			'pulsetic-frontend',
			PULSETIC_URL . 'assets/css/frontend.css',
			[],
			PULSETIC_VERSION
		);

		// Inject CSS custom properties once — values are re-sanitized here
		$css_vars = pulsetic_build_css_vars( pulsetic_get_colors(), pulsetic_get_sizes() );
		wp_add_inline_style( 'pulsetic-frontend', $css_vars );
	}

	// Script — guard same way
	if ( ! wp_script_is( 'pulsetic-frontend', 'enqueued' ) ) {
		wp_enqueue_script(
			'pulsetic-frontend',
			PULSETIC_URL . 'assets/js/frontend.js',
			[],
			PULSETIC_VERSION,
			true  // footer
		);
		wp_localize_script( 'pulsetic-frontend', 'pulseticFrontend', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'restUrl' => rest_url( 'pulsetic/v1/status' ),
			'nonce'   => wp_create_nonce( 'pulsetic_frontend_nonce' ),
		] );
	}
}
