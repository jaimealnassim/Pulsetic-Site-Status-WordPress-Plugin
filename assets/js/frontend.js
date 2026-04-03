/* global pulseticFrontend */
( function () {
	'use strict';

	// ── Boot ──────────────────────────────────────────────────────────────────

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}

	function boot() {
		( window.pulseticInstances || [] ).forEach( initInstance );
	}

	/**
	 * Initialise one shortcode instance and start its polling loop.
	 *
	 * @param {Object}  cfg
	 * @param {string}  cfg.uid            Widget element ID (e.g. "ps_abc123")
	 * @param {string}  cfg.group          Group slug
	 * @param {number}  cfg.interval       Poll interval in ms
	 * @param {string}  cfg.style          'list' | 'cards' | 'bar'
	 * @param {string}  cfg.label_online
	 * @param {string}  cfg.label_offline
	 * @param {string}  cfg.label_paused
	 * @param {boolean} cfg.show_name
	 * @param {boolean} cfg.show_url
	 * @param {Object}  cfg.custom_labels
	 * @param {Object}  cfg.custom_links
	 */
	function initInstance( cfg ) {
		const widget = document.getElementById( cfg.uid );
		if ( ! widget || ! ( cfg.interval > 0 ) ) return;

		// In-flight guard: skip a poll if the previous one hasn't returned yet
		let pending = false;

		// Stagger multiple instances (up to 5 s) to spread server load
		const jitter   = Math.random() * 5000;
		let   timerId  = null;

		function scheduledPoll() {
			if ( ! pending ) {
				pending = true;
				poll( cfg, widget ).finally( function () { pending = false; } );
			}
		}

		timerId = setInterval( scheduledPoll, cfg.interval );

		// Initial poll fires after (interval + jitter) so we don't hammer on load
		setTimeout( scheduledPoll, cfg.interval + jitter );

		// Clean up intervals when the page is hidden (tab switch, bfcache unload)
		// This prevents stale intervals accumulating in SPA / full-page-cache scenarios
		document.addEventListener( 'visibilitychange', function handleVisibility() {
			if ( document.visibilityState === 'hidden' ) {
				clearInterval( timerId );
			} else {
				// Resume when tab becomes visible again
				timerId = setInterval( scheduledPoll, cfg.interval );
				scheduledPoll(); // immediate refresh so user sees current status
			}
		} );
	}

	// ── Poll ─────────────────────────────────────────────────────────────────

	/**
	 * @returns {Promise<void>}
	 */
	function poll( cfg, widget ) {
		const body = new URLSearchParams( {
			action: 'pulsetic_poll_group',
			nonce:  pulseticFrontend.nonce,
			group:  cfg.group,
		} );

		return fetch( pulseticFrontend.ajaxUrl, {
			method:  'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body:    body.toString(),
		} )
		.then( function ( r ) {
			if ( ! r.ok ) throw new Error( 'HTTP ' + r.status );
			return r.json();
		} )
		.then( function ( data ) {
			if ( data.success && Array.isArray( data.data.items ) ) {
				applyDiff( cfg, widget, data.data.items );
			}
		} )
		.catch( function () {
			// Silent fail — stale DOM stays, no console noise for the end user
		} );
	}

	// ── Diff ─────────────────────────────────────────────────────────────────

	/**
	 * Update only the items whose status changed.
	 * No full re-render — preserves focus, avoids layout thrash.
	 */
	function applyDiff( cfg, widget, items ) {
		const style = cfg.style || 'list';

		items.forEach( function ( item ) {
			const el = widget.querySelector( '[data-monitor-id="' + item.id + '"]' );
			if ( ! el ) return;

			if ( style === 'list' )  updateList(  cfg, el, item.status, item );
			if ( style === 'cards' ) updateCards( cfg, el, item.status, item );
			if ( style === 'bar' )   updateBar(   cfg, el, item.status, item );
		} );
	}

	// ── Per-style updaters ───────────────────────────────────────────────────

	function updateList( cfg, li, newStatus, item ) {
		const dot   = li.querySelector( '.psd' );
		const badge = li.querySelector( '.psb' );
		if ( ! dot || ! badge ) return;
		if ( getStatus( dot ) === newStatus ) return;

		setStatus( dot,   [ 'psd', 'online', 'offline', 'paused' ], newStatus );
		setStatus( badge, [ 'psb', 'online', 'offline', 'paused' ], newStatus );
		badge.textContent = label( cfg, newStatus );
		pulse( dot, badge );
		li.setAttribute( 'aria-label', ( item.display_name || item.id ) + ': ' + badge.textContent );
	}

	function updateCards( cfg, card, newStatus, item ) {
		const dot   = card.querySelector( '.pc-dot' );
		const badge = card.querySelector( '.pc-badge' );
		if ( ! dot || ! badge ) return;
		if ( getStatus( dot ) === newStatus ) return;

		setStatus( card,  [ 'online', 'offline', 'paused' ], newStatus );
		setStatus( dot,   [ 'pc-dot', 'online', 'offline', 'paused' ], newStatus );
		setStatus( badge, [ 'pc-badge', 'online', 'offline', 'paused' ], newStatus );
		badge.textContent = label( cfg, newStatus );
		pulse( dot, badge );
		card.setAttribute( 'aria-label', ( item.display_name || item.id ) + ': ' + badge.textContent );
	}

	function updateBar( cfg, pill, newStatus, item ) {
		const dot = pill.querySelector( '.pb-dot' );
		if ( ! dot ) return;
		if ( getStatus( dot ) === newStatus ) return;

		setStatus( pill, [ 'pb-pill', 'online', 'offline', 'paused' ], newStatus );
		setStatus( dot,  [ 'pb-dot',  'online', 'offline', 'paused' ], newStatus );
		pulse( dot, pill );

		const statusEl = pill.querySelector( '.pb-status-text' );
		if ( statusEl ) statusEl.textContent = '· ' + label( cfg, newStatus );

		pill.setAttribute( 'aria-label', ( item.display_name || item.id ) + ': ' + label( cfg, newStatus ) );
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	function getStatus( el ) {
		for ( const s of [ 'online', 'offline', 'paused' ] ) {
			if ( el.classList.contains( s ) ) return s;
		}
		return '';
	}

	function setStatus( el, toRemove, toAdd ) {
		el.classList.remove( ...toRemove );
		el.classList.add( toAdd );
	}

	function pulse( ...els ) {
		els.forEach( function ( el ) {
			el.classList.remove( 'pulsetic-changed' );
			// Force a reflow so the animation re-triggers even for repeated changes
			void el.offsetWidth;
			el.classList.add( 'pulsetic-changed' );
			setTimeout( function () { el.classList.remove( 'pulsetic-changed' ); }, 700 );
		} );
	}

	function label( cfg, status ) {
		if ( status === 'online' )  return cfg.label_online;
		if ( status === 'offline' ) return cfg.label_offline;
		return cfg.label_paused;
	}

} )();
