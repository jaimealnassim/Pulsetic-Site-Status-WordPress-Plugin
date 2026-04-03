<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Pulsetic_Shortcode {

	private bool $assets_enqueued = false;

	public function init(): void {
		add_shortcode( 'pulsetic_status', [ $this, 'render' ] );
	}

	/** @param array<string,string>|string $atts */
	public function render( $atts ): string {
		$atts = shortcode_atts( [
			'group'            => 'default',
			'label_online'     => 'Online',
			'label_offline'    => 'Offline',
			'label_paused'     => 'Paused',
			'label_error'      => 'Could not load status',
			'show_url'         => 'false',
			'show_name'        => 'true',
			'show_refresh'     => 'false',
			'refresh_interval' => '60',
		], $atts, 'pulsetic_status' );

		$show_url         = filter_var( $atts['show_url'],     FILTER_VALIDATE_BOOLEAN );
		$show_name        = filter_var( $atts['show_name'],    FILTER_VALIDATE_BOOLEAN );
		$show_refresh     = filter_var( $atts['show_refresh'], FILTER_VALIDATE_BOOLEAN );
		$refresh_interval = max( 0, (int) $atts['refresh_interval'] );

		$monitors = Pulsetic_API::get_monitors();
		if ( is_wp_error( $monitors ) ) {
			return '<p class="pulsetic-error">' . esc_html( $atts['label_error'] ) . '</p>';
		}

		$group = pulsetic_find_group( $atts['group'] );
		if ( $group === null ) {
			return '<p class="pulsetic-error">' . esc_html( $atts['label_error'] ) . '</p>';
		}

		[ $monitors, $custom_labels, $custom_links ] = pulsetic_filter_group( $monitors, $group );

		if ( empty( $monitors ) ) {
			return '<p class="pulsetic-empty">' . esc_html( $atts['label_error'] ) . '</p>';
		}

		pulsetic_enqueue_frontend_assets( $this->assets_enqueued );
		$this->assets_enqueued = true;

		$uid  = 'ps_' . substr( md5( 'list-' . $atts['group'] ), 0, 8 );
		$rows = '';

		foreach ( $monitors as $m ) {
			$mid   = (string) ( $m['id'] ?? '' );
			$state = pulsetic_resolve_status( $m );

			$status_label = match( $state ) {
				'online'  => $atts['label_online'],
				'offline' => $atts['label_offline'],
				default   => $atts['label_paused'],
			};
			$display_name = ( $custom_labels[ $mid ] ?? '' ) !== '' ? $custom_labels[ $mid ] : pulsetic_display_name( $m );
			$custom_link  = $custom_links[ $mid ] ?? '';
			$raw_url      = (string) ( $m['url'] ?? '' );

			$rows .= '<li class="psi" data-monitor-id="' . esc_attr( $mid ) . '"'
				. ' aria-label="' . esc_attr( $display_name . ': ' . $status_label ) . '">';
			$rows .= '<span class="psd ' . esc_attr( $state ) . '" aria-hidden="true"></span>';

			if ( $show_name ) {
				if ( $custom_link !== '' ) {
					$rows .= '<a class="psn" href="' . esc_url( $custom_link ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $display_name ) . '</a>';
				} else {
					$rows .= '<span class="psn">' . esc_html( $display_name ) . '</span>';
				}
			}
			if ( $show_url && $raw_url !== '' ) {
				$rows .= '<span class="psu">' . esc_html( $raw_url ) . '</span>';
			}
			$rows .= '<span class="psb ' . esc_attr( $state ) . '">' . esc_html( $status_label ) . '</span>';
			$rows .= '</li>';
		}

		$refresh_html = '';
		if ( $show_refresh ) {
			$timeout = (int) get_option( '_transient_timeout_' . PULSETIC_CACHE_KEY, 0 );
			if ( $timeout > 0 ) {
				$refresh_html = '<p class="pst">Refreshes in ' . esc_html( human_time_diff( time(), $timeout ) ) . '</p>';
			}
		}

		$ajax_config = '';
		if ( $refresh_interval > 0 ) {
			$ajax_config = pulsetic_poll_script( [
				'uid'           => $uid,
				'group'         => $atts['group'],
				'interval'      => $refresh_interval * 1000,
				'label_online'  => $atts['label_online'],
				'label_offline' => $atts['label_offline'],
				'label_paused'  => $atts['label_paused'],
				'show_name'     => $show_name,
				'show_url'      => $show_url,
				'custom_labels' => $custom_labels,
				'custom_links'  => $custom_links,
				'style'         => 'list',
			] );
		}

		return $ajax_config
			. '<div id="' . esc_attr( $uid ) . '" class="pulsetic-widget" data-group="' . esc_attr( $atts['group'] ) . '">'
			. '<ul role="list">' . $rows . '</ul>'
			. $refresh_html
			. '</div>';
	}
}
