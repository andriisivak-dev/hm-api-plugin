/**
 * Customers CSV Import — Admin JS
 *
 * Port of clients-import.js from hemant-core plugin.
 * Runs a chunked import loop via wp-ajax.
 */
(function ($) {
    'use strict';

    var localFile = '';
    var offset    = 0;
    var limit     = 200;
    var running   = false;

    function log(msg) {
        var $log = $('#csp-log');
        $log.append(msg + '\n');
        $log.scrollTop($log[0].scrollHeight);
    }

    function runChunk() {
        if (!running) { return; }

        $.post(CSPImport.ajax, {
            action:     'csp_customers_import_chunk',
            nonce:      CSPImport.nonce,
            file:       $('#csp_google_url').val(),
            local_file: localFile,
            offset:     offset
        })
        .done(function (res) {
            if (!res || !res.success) {
                log('Error: ' + (res && res.data ? res.data.message : 'Unknown error'));
                running = false;
                return;
            }

            var data = res.data;
            localFile = data.local_file || localFile;
            offset += data.processed;

            var s = data.stats;
            log('Processed ' + data.processed + ' rows (inserted: ' + s.inserted + ', updated: ' + s.updated + ', skipped: ' + s.skipped + ')');

            if (data.done) {
                log('✅ Import complete. Total offset: ' + offset);
                running = false;
            } else {
                runChunk();
            }
        })
        .fail(function () {
            log('AJAX request failed.');
            running = false;
        });
    }

    $(document).on('click', '#csp-start-import', function () {
        if (running) { return; }

        var url = $('#csp_google_url').val().trim();
        if (!url) {
            alert('Please enter a Google Sheets URL or CSV URL.');
            return;
        }

        localFile = '';
        offset    = 0;
        running   = true;
        $('#csp-log').text('');
        log('Starting import…');
        runChunk();
    });

}(jQuery));
