<?php
/**
 * Pulsetic Site Status — Uninstall
 *
 * Runs automatically when the plugin is deleted via the WordPress admin.
 * Removes all options, transients, and scheduled cron events created by this plugin.
 *
 * This file is intentionally standalone (no require of plugin files) because
 * WordPress calls it directly without loading the plugin itself.
 *
 * @see https://developer.wordpress.org/plugins/plugin-basics/uninstall-methods/
 */

// Bail if not called by WordPress during uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// ── Options ───────────────────────────────────────────────────────────────────

$options = [
	'pulsetic_api_token',    // API token
	'pulsetic_colors',       // Custom status colors
	'pulsetic_groups',       // Monitor groups + labels + links
	'pulsetic_sizes',        // Dot / font size overrides
	'pulsetic_scan_interval', // Cache TTL / scan frequency
];

foreach ( $options as $option ) {
	delete_option( $option );
}

// ── Transients ────────────────────────────────────────────────────────────────

delete_transient( 'pulsetic_monitors_cache' );

// ── Scheduled cron events ────────────────────────────────────────────────────

$timestamp = wp_next_scheduled( 'pulsetic_background_refresh' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'pulsetic_background_refresh' );
}

// Clear all instances of the hook in case more than one was queued
wp_clear_scheduled_hook( 'pulsetic_background_refresh' );
