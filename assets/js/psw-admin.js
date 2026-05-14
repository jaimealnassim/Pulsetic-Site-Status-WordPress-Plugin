(function ($) {
    'use strict';
    const cfg = window.pswAdmin || {};

    $('#psw-load-monitors').on('click', function () {
        const btn = $(this), sel = $('#psw_monitor'), saved = sel.val();
        btn.prop('disabled', true).text('Loading…');
        $.ajax({
            url: cfg.monitorsUrl, method: 'GET',
            beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', cfg.nonce); },
            success: function (monitors) {
                sel.empty().append('<option value="">— Select a monitor —</option>');
                if (!monitors || !monitors.length) { sel.append('<option disabled>No monitors found</option>'); return; }
                monitors.forEach(function (m) {
                    const label = m.name + (m.url ? ' (' + m.url + ')' : '') + ' [ID: ' + m.id + ']';
                    sel.append('<option value="' + m.id + '"' + (m.id == saved ? ' selected' : '') + '>' + label + '</option>');
                });
                btn.text('Reload Monitors');
            },
            error: function (xhr) {
                const msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : 'Request failed';
                sel.empty().append('<option disabled>Error: ' + msg + '</option>');
                btn.text('Retry');
            },
            complete: function () { btn.prop('disabled', false); },
        });
    });

    $('#psw-test').on('click', function () {
        const btn = $(this), result = $('#psw-test-result');
        btn.prop('disabled', true).text('Testing…');
        $.ajax({
            url: cfg.monitorsUrl, method: 'GET',
            beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', cfg.nonce); },
            success: function (m) { result.text('✓ Connected — ' + (m ? m.length : 0) + ' monitor(s) found.').css('color', '#16a34a'); },
            error: function (xhr) { result.text('✗ ' + ((xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : 'Failed')).css('color', '#dc2626'); },
            complete: function () { btn.prop('disabled', false).text('Test Connection'); },
        });
    });

    $('#psw-clear-cache').on('click', function () {
        if (!confirm('Clear all cached Pulsetic data?')) return;
        const btn = $(this), result = $('#psw-clear-result');
        btn.prop('disabled', true);
        $.ajax({
            url: cfg.clearUrl, method: 'POST',
            beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', cfg.nonce); },
            success: function () { result.text('✓ Cache cleared.').css('color', '#16a34a'); },
            error: function () { result.text('✗ Failed.').css('color', '#dc2626'); },
            complete: function () { btn.prop('disabled', false); setTimeout(function () { result.text(''); }, 4000); },
        });
    });
})(jQuery);
