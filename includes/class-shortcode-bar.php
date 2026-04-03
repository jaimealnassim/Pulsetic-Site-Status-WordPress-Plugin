<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * [pulsetic_bar] — Horizontal pill/chip row design.
 * Compact; great for headers, footers, or sidebar "all systems go" indicators.
 */
class Pulsetic_Shortcode_Bar {

	private bool $assets_enqueued = false;

	public function init(): void {
		add_shortcode( 'pulsetic_bar', [ $this, 'render' ] );
	}

	/** @param array<string,string>|string $atts */
	public function render( $atts ): string {
		$atts = shortcode_atts( [
			'group'            => 'default',
			'label_online'     => 'Online',
			'label_offline'    => 'Offline',
			'label_paused'     => 'Paused',
			'label_error'      => 'Could not load status',
			'show_name'        => 'true',
			'show_status'      => 'false',
			'refresh_interval' => '60',
		], $atts, 'pulsetic_bar' );

		$show_name        = filter_var( $atts['show_name'],   FILTER_VALIDATE_BOOLEAN );
		$show_status      = filter_var( $atts['show_status'], FILTER_VALIDATE_BOOLEAN );
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

		$uid  = 'pb_' . substr( md5( 'bar-' . $atts['group'] ), 0, 8 );
		$html = '';

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

			$aria = esc_attr( $display_name . ': ' . $status_label );

			if ( $custom_link !== '' ) {
				$html .= '<a href="' . esc_url( $custom_link ) . '" target="_blank" rel="noopener noreferrer"'
					. ' class="pb-pill ' . esc_attr( $state ) . '" data-monitor-id="' . esc_attr( $mid ) . '"'
					. ' aria-label="' . $aria . '">';
			} else {
				$html .= '<span class="pb-pill ' . esc_attr( $state ) . '" data-monitor-id="' . esc_attr( $mid ) . '"'
					. ' aria-label="' . $aria . '">';
			}

			$html .= '<span class="pb-dot ' . esc_attr( $state ) . '" aria-hidden="true"></span>';

			if ( $show_name ) {
				$html .= '<span class="pb-name">' . esc_html( $display_name ) . '</span>';
			}
			if ( $show_status ) {
				$html .= '<span class="pb-status-text">· ' . esc_html( $status_label ) . '</span>';
			}

			$html .= $custom_link !== '' ? '</a>' : '</span>';
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
				'show_url'      => false,
				'custom_labels' => $custom_labels,
				'custom_links'  => $custom_links,
				'style'         => 'bar',
			] );
		}

		return $ajax_config
			. '<div id="' . esc_attr( $uid ) . '" class="pulsetic-bar" data-group="' . esc_attr( $atts['group'] ) . '">'
			. $html
			. '</div>';
	}
}
