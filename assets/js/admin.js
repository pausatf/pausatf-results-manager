/**
 * PAUSATF Results Manager - Admin JavaScript
 */

(function($) {
    'use strict';

    // Import form handling
    $('#pausatf-import-form').on('submit', function(e) {
        var $form = $(this);
        var $btn = $form.find('button[type="submit"]');
        var $spinner = $form.find('.spinner');

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
    });

    // URL analysis
    $('#pausatf-analyze-btn').on('click', function(e) {
        e.preventDefault();

        var url = $('#import_url').val();
        if (!url) {
            alert('Please enter a URL');
            return;
        }

        var $btn = $(this);
        var $spinner = $btn.next('.spinner');
        var $results = $('#pausatf-analysis-results');

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');

        $.post(pausatfResults.ajaxUrl, {
            action: 'pausatf_analyze_url',
            url: url,
            _wpnonce: pausatfResults.nonce
        }, function(response) {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');

            if (response.success) {
                var html = '<table class="widefat">';
                html += '<tr><td><strong>Parser</strong></td><td>' + (response.data.selected_parser || 'None') + '</td></tr>';
                html += '<tr><td><strong>Has Tables</strong></td><td>' + (response.data.has_tables ? 'Yes' : 'No') + '</td></tr>';
                html += '<tr><td><strong>Has PRE</strong></td><td>' + (response.data.has_pre ? 'Yes' : 'No') + '</td></tr>';
                html += '<tr><td><strong>Word HTML</strong></td><td>' + (response.data.is_word_html ? 'Yes' : 'No') + '</td></tr>';
                html += '</table>';
                $results.html(html).show();
            } else {
                $results.html('<p class="error">' + response.data + '</p>').show();
            }
        });
    });

    // Bulk athlete creation
    $('#pausatf-bulk-create-athletes').on('click', function() {
        var $btn = $(this);
        var $spinner = $btn.next('.spinner');

        if (!confirm('This will create athlete profiles for all competitors with enough events. Continue?')) {
            return;
        }

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');

        $.post(pausatfResults.ajaxUrl, {
            action: 'pausatf_bulk_create_athletes',
            _wpnonce: pausatfResults.nonce
        }, function(response) {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');

            if (response.success) {
                alert('Created ' + response.data.created + ' athlete profiles.\n' +
                      'Total eligible: ' + response.data.total_eligible);
            } else {
                alert('Error: ' + response.data);
            }
        });
    });

    // Re-parse all events
    $('#pausatf-reparse-all').on('click', function() {
        var $btn = $(this);

        if (!confirm('This will re-download and parse all previously imported events. This may take a long time. Continue?')) {
            return;
        }

        alert('Batch re-parse has been queued. Check the import history for progress.');
    });

    // File upload preview
    $('#results_file').on('change', function() {
        var file = this.files[0];
        if (file) {
            var $info = $(this).siblings('.file-info');
            if (!$info.length) {
                $info = $('<p class="file-info description"></p>');
                $(this).after($info);
            }
            $info.text('Selected: ' + file.name + ' (' + formatBytes(file.size) + ')');
        }
    });

    function formatBytes(bytes) {
        if (bytes === 0) return '0 Bytes';
        var k = 1024;
        var sizes = ['Bytes', 'KB', 'MB', 'GB'];
        var i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

})(jQuery);
