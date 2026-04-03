/* global pulseticAdmin, ajaxurl */
( function ( $ ) {
	'use strict';

	const { monitors, defaultColors } = pulseticAdmin;
	let gc = parseInt( pulseticAdmin.groupCount, 10 );

	// ── Accordion ──────────────────────────────────────────────────────────────

	$( document ).on( 'click', '.group-header', function ( e ) {
		if ( $( e.target ).is( 'input, button' ) ) return;
		$( this ).closest( '.group-item' ).toggleClass( 'open' );
	} );

	$( '#groups-list .group-item:first' ).addClass( 'open' );

	// ── Name → slug ────────────────────────────────────────────────────────────

	$( document ).on( 'input', '.group-name-input', function () {
		const slug = $( this ).val()
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, '-' )
			.replace( /^-+|-+$/g, '' ) || 'group';

		const $item = $( this ).closest( '.group-item' );
		$item.find( '.group-id-field' ).val( slug );
		$item.find( '.group-slug-pill' ).text( slug );
		$item.find( '.group-sc' ).attr( 'data-slug', slug );

		// Update all three shortcode span variants
		$item.find( '.gsc-list' ).html(
			'[pulsetic_status <span class="at">group</span>=<span class="vl">"' + esc( slug ) + '"</span>]'
		);
		$item.find( '.gsc-cards' ).html(
			'[pulsetic_cards <span class="at">group</span>=<span class="vl">"' + esc( slug ) + '"</span>]'
		);
		$item.find( '.gsc-bar' ).html(
			'[pulsetic_bar <span class="at">group</span>=<span class="vl">"' + esc( slug ) + '"</span>]'
		);
	} );

	// ── Shortcode tab switcher ─────────────────────────────────────────────────

	$( document ).on( 'click', '.gst-tab', function () {
		const $tab   = $( this );
		const style  = $tab.data( 'style' );
		const $group = $tab.closest( '.group-item' );

		$group.find( '.gst-tab' ).removeClass( 'active' );
		$tab.addClass( 'active' );

		$group.find( '.gsc-list, .gsc-cards, .gsc-bar' ).hide();
		$group.find( '.gsc-' + style ).show();
	} );

	// ── Checkbox highlight ──────────────────────────────────────────────────────

	$( document ).on( 'change', '.mon-row input[type=checkbox]', function () {
		$( this ).closest( '.mon-row' ).toggleClass( 'ck', this.checked );
	} );

	// ── Select all / none ──────────────────────────────────────────────────────

	$( document ).on( 'click', '.sa', function () {
		const selectAll = $( this ).data( 'a' ) === 'all';
		$( this ).closest( '.group-body' ).find( '.mon-row input[type=checkbox]' ).each( function () {
			this.checked = selectAll;
			$( this ).closest( '.mon-row' ).toggleClass( 'ck', selectAll );
		} );
	} );

	// ── Delete group ───────────────────────────────────────────────────────────

	$( document ).on( 'click', '.group-del', function ( e ) {
		e.stopPropagation();
		if ( $( '.group-item' ).length <= 1 ) {
			alert( 'You need at least one group.' );
			return;
		}
		if ( confirm( 'Remove this group?' ) ) {
			$( this ).closest( '.group-item' ).remove();
		}
	} );

	// ── Add group ──────────────────────────────────────────────────────────────

	$( '#add-group-btn' ).on( 'click', function () {
		const idx  = gc++;
		const slug = 'new-group-' + idx;

		const html = `
<div class="group-item open" data-index="${ idx }">
  <div class="group-header">
    <span class="group-chevron">▶</span>
    <input type="text" class="group-name-input"
      name="pulsetic_groups[${ idx }][name]" value="New Group" placeholder="Group name"/>
    <input type="hidden" name="pulsetic_groups[${ idx }][id]"
      class="group-id-field" value="${ slug }"/>
    <span class="group-slug-wrap">
      <span class="group-slug-label">slug</span>
      <span class="group-slug-pill">${ slug }</span>
    </span>
    <button type="button" class="group-del">✕ Remove</button>
  </div>
  <div class="group-body">
    <div class="group-sc-tabs">
      <button type="button" class="gst-tab active" data-style="list">List</button>
      <button type="button" class="gst-tab" data-style="cards">Cards</button>
      <button type="button" class="gst-tab" data-style="bar">Bar</button>
    </div>
    <div class="group-sc" data-slug="${ slug }">
      <span class="gsc-list">[pulsetic_status <span class="at">group</span>=<span class="vl">"${ slug }"</span>]</span>
      <span class="gsc-cards" style="display:none">[pulsetic_cards <span class="at">group</span>=<span class="vl">"${ slug }"</span>]</span>
      <span class="gsc-bar" style="display:none">[pulsetic_bar <span class="at">group</span>=<span class="vl">"${ slug }"</span>]</span>
    </div>
    <div class="sctr">
      <button type="button" class="sa" data-a="all">Select all</button>
      <button type="button" class="sa" data-a="none">Deselect all</button>
    </div>
    <div class="mon-list">${ buildMonitorRows( idx ) }</div>
  </div>
</div>`;

		$( '#groups-list' ).append( html );
	} );

	function buildMonitorRows( idx ) {
		return monitors.map( function ( m ) {
			return `
<div class="mon-row">
  <label class="mon-check-wrap">
    <input type="checkbox" name="pulsetic_groups[${ idx }][monitors][]" value="${ esc( m.id ) }">
    <span class="mdot ${ m.status }"></span>
    <span class="mon-info">
      <span class="mn">${ esc( m.name ) }</span>
      <span class="mu">${ esc( m.url ) }</span>
    </span>
  </label>
  <div class="mon-label-wrap">
    <span>Label:</span>
    <input type="text" class="mon-label-input"
      name="pulsetic_groups[${ idx }][labels][${ esc( m.id ) }]"
      placeholder="${ esc( m.name ) }">
  </div>
  <div class="mon-label-wrap mon-link-wrap">
    <span>Link:</span>
    <input type="text" class="mon-label-input"
      name="pulsetic_groups[${ idx }][links][${ esc( m.id ) }]"
      placeholder="https://">
  </div>
</div>`;
		} ).join( '' );
	}

	// ── Refresh monitors (admin) ───────────────────────────────────────────────

	$( '#prfbtn' ).on( 'click', function () {
		const $btn   = $( this );
		const $stat  = $( '#pmstat' );
		$btn.prop( 'disabled', true ).text( 'Loading…' );
		$stat.css( 'color', '#94a3b8' ).text( '' );

		$.post( pulseticAdmin.ajaxUrl, {
			action: 'pulsetic_fetch_monitors',
			nonce:  pulseticAdmin.nonce,
		}, function ( r ) {
			$btn.prop( 'disabled', false ).text( '↺ Refresh' );
			if ( r.success ) {
				$stat.text( '✓ ' + r.data.monitors.length + ' monitors — save to apply.' );
			} else {
				$stat.css( 'color', '#ef4444' ).text( '⚠ ' + r.data.message );
			}
		} ).fail( function () {
			$btn.prop( 'disabled', false ).text( '↺ Refresh' );
			$stat.css( 'color', '#ef4444' ).text( '⚠ Failed.' );
		} );
	} );

	// ── Colors ─────────────────────────────────────────────────────────────────

	const COLOR_KEYS = [
		'online_dot', 'online_badge_bg', 'online_badge_text',
		'offline_dot', 'offline_badge_bg', 'offline_badge_text',
		'paused_dot', 'paused_badge_bg', 'paused_badge_text',
	];

	function isHex( v ) {
		return /^#[0-9a-fA-F]{3,6}$/.test( v );
	}

	function syncPreview() {
		[ 'online', 'offline', 'paused' ].forEach( function ( s ) {
			const dot   = $( '#c_' + s + '_dot' ).val();
			const bg    = $( '#c_' + s + '_badge_bg' ).val();
			const text  = $( '#c_' + s + '_badge_text' ).val();
			$( '#pd_' + s ).css( 'background', dot );
			$( '#pb_' + s ).css( { background: bg, color: text } );
		} );
	}

	COLOR_KEYS.forEach( function ( k ) {
		const $input  = $( '#c_' + k );
		const $swatch = $( '#sw_' + k );
		const initial = $input.val();

		// Only init Spectrum for hex values; for var() etc. just skip picker
		if ( isHex( initial ) ) {
			$swatch.spectrum( {
				color:           initial,
				showInput:       true,
				preferredFormat: 'hex',
				showButtons:     false,
				move:   function ( c ) { apply( c.toHexString() ); },
				change: function ( c ) { apply( c.toHexString() ); },
			} );
		}

		$input.on( 'input', function () {
			const val = this.value.trim();
			// Update swatch preview for anything (hex, var(), named, etc.)
			$swatch.css( 'background', val );
			// Sync Spectrum if valid hex
			if ( isHex( val ) ) {
				try { $swatch.spectrum( 'set', val ); } catch(e) {}
			}
			syncPreview();
		} );

		function apply( hex ) {
			$input.val( hex );
			$swatch.css( 'background', hex );
			syncPreview();
		}
	} );

	$( '#rcl' ).on( 'click', function () {
		COLOR_KEYS.forEach( function ( k ) {
			const def = defaultColors[ k ];
			$( '#c_' + k ).val( def );
			$( '#sw_' + k ).css( 'background', def );
			try { $( '#sw_' + k ).spectrum( 'set', def ); } catch(e) {}
		} );
		syncPreview();
	} );

	// ── Sizes reset ────────────────────────────────────────────────────────────

	$( '#rclsz' ).on( 'click', function () {
		const sizeDefaults = pulseticAdmin.defaultSizes || {};
		Object.keys( sizeDefaults ).forEach( function ( k ) {
			$( '#sz_' + k ).val( sizeDefaults[ k ] );
		} );
	} );

	// ── Utils ──────────────────────────────────────────────────────────────────

	function esc( s ) {
		return $( '<div>' ).text( String( s ) ).html();
	}

} )( jQuery );
