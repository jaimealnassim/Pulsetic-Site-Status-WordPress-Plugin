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

	function initInstance( cfg ) {
		const widget = document.getElementById( cfg.uid );
		if ( ! widget || ! ( cfg.interval > 0 ) ) return;

		let pending = false;

		// Stagger multiple instances to spread server load
		const jitter  = Math.random() * 5000;
		let   timerId = null;

		function scheduledPoll() {
			if ( pending ) return;
			pending = true;
			poll( cfg, widget ).finally( function () { pending = false; } );
		}

		timerId = setInterval( scheduledPoll, cfg.interval );
		setTimeout( scheduledPoll, cfg.interval + jitter );

		// Pause when tab is hidden, resume when visible
		document.addEventListener( 'visibilitychange', function () {
			if ( document.visibilityState === 'hidden' ) {
				clearInterval( timerId );
			} else {
				timerId = setInterval( scheduledPoll, cfg.interval );
				scheduledPoll();
			}
		} );
	}

	// ── Poll — REST preferred, admin-ajax fallback ────────────────────────────

	/**
	 * Try the WP REST endpoint first (GET /wp-json/pulsetic/v1/status/{group}).
	 * If that fails (network error, 404, host blocking /wp-json/), fall back
	 * to the legacy admin-ajax POST endpoint.
	 *
	 * @returns {Promise<void>}
	 */
	function poll( cfg, widget ) {
		const restUrl = ( pulseticFrontend.restUrl || '' ).replace( /\/$/, '' ) + '/' + encodeURIComponent( cfg.group );

		return fetch( restUrl, { method: 'GET' } )
			.then( function ( r ) {
				// If the REST endpoint returned a non-success status, fall through to ajax fallback
				if ( ! r.ok ) throw new Error( 'REST ' + r.status );
				return r.json();
			} )
			.then( function ( data ) {
				// REST returns the payload directly (not wrapped in {success, data})
				if ( data && Array.isArray( data.items ) ) {
					applyDiff( cfg, widget, data.items );
				}
			} )
			.catch( function () {
				// ── Fallback: admin-ajax ──────────────────────────────────────
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
					if ( ! r.ok ) throw new Error( 'AJAX ' + r.status );
					return r.json();
				} )
				.then( function ( data ) {
					if ( data.success && Array.isArray( data.data.items ) ) {
						applyDiff( cfg, widget, data.data.items );
					}
				} )
				.catch( function () {} ); // final silent fail
			} );
	}

	// ── DOM diff ─────────────────────────────────────────────────────────────

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
		setStatus( dot,   [ 'pc-dot',  'online', 'offline', 'paused' ], newStatus );
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
			void el.offsetWidth; // force reflow so animation re-triggers
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
