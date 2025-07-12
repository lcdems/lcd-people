/**
 * JavaScript for LCD People admin list page
 * Handles the "Copy Emails" button functionality
 */
jQuery(document).ready(function($) {
    // Copy Emails Button Functionality
    $('#lcd-copy-emails-button').on('click', function() {
        var $button = $(this);
        $button.prop('disabled', true).text('Copying...'); // Disable button and change text

        // Construct the data object from current filters
        var data = {
            action: 'lcd_get_filtered_emails',
            _ajax_nonce: lcdPeopleAdmin.nonce, // Use the localized nonce
            membership_status: $('select[name="membership_status"]').val(),
            membership_type: $('select[name="membership_type"]').val(),
            is_sustaining: $('select[name="is_sustaining"]').val(),
            lcd_role: $('select[name="lcd_role"]').val(),
            s: $('#post-search-input').val() // Include search term
        };

        $.get(lcdPeopleAdmin.ajaxurl, data, function(response) {
            if (response.success && response.data.emails && response.data.emails.length > 0) {
                var emailsString = response.data.emails.join('\n'); 
                navigator.clipboard.writeText(emailsString).then(function() {
                    alert(lcdPeopleAdmin.strings.copySuccess + ' (' + response.data.total + ' emails)');
                }, function(err) {
                    alert(lcdPeopleAdmin.strings.copyError + ' ' + err);
                });
            } else if (response.success) {
                 alert(lcdPeopleAdmin.strings.noEmails);
            } else {
                alert(lcdPeopleAdmin.strings.copyError + ' ' + (response.data && response.data.message ? response.data.message : 'Unknown error'));
            }
            $button.prop('disabled', false).text('Copy Emails'); // Re-enable button
        }).fail(function() {
            alert(lcdPeopleAdmin.strings.copyError + ' AJAX request failed.');
            $button.prop('disabled', false).text('Copy Emails'); // Re-enable button
        });
    });

    // Sync All to Sender Button Functionality
    $('#lcd-sync-all-sender-button').on('click', function() {
        var $button = $(this);
        var $spinner = $button.siblings('.spinner');
        var $resultDiv = $('#lcd-sync-results'); // Div to display results

        // Create result div if it doesn't exist
        if ($resultDiv.length === 0) {
            $resultDiv = $('<div id="lcd-sync-results" style="margin-top: 10px; padding: 10px; border: 1px solid #ccc; background: #f9f9f9; display: none;"></div>');
            $('.lcd-people-actions').after($resultDiv); // Place it after the buttons container
        }
        $resultDiv.hide().empty(); // Clear previous results

        if (!confirm(lcdPeopleAdmin.strings.confirmSyncAll)) {
            return;
        }

        $button.prop('disabled', true);
        $spinner.addClass('is-active');
        $resultDiv.html('<p>' + lcdPeopleAdmin.strings.syncingAll + '</p>').show();

        $.post(lcdPeopleAdmin.ajaxurl, {
            action: 'lcd_sync_all_to_sender',
            nonce: lcdPeopleAdmin.nonce // Pass nonce correctly
        }, function(response) {
            if (response.success) {
                var resultHtml = '<p><strong>' + response.data.message + '</strong></p>';
                // Add error details if any
                if (response.data.results && response.data.results.failed > 0 && response.data.results.error_messages) {
                    resultHtml += '<h5>' + lcdPeopleAdmin.strings.syncErrors + '</h5><ul>';
                    $.each(response.data.results.error_messages, function(id, msg) {
                        resultHtml += '<li>' + msg + '</li>';
                    });
                     resultHtml += '</ul>';
                }
                 $resultDiv.html(resultHtml);
            } else {
                $resultDiv.html('<p style="color: red;"><strong>' + lcdPeopleAdmin.strings.syncAllError + '</strong> ' + (response.data && response.data.message ? response.data.message : '') + '</p>');
            }
        }).fail(function() {
            $resultDiv.html('<p style="color: red;"><strong>' + lcdPeopleAdmin.strings.syncAllError + '</strong> ' + lcdPeopleAdmin.strings.ajaxRequestFailed + '</p>');
        }).always(function() {
            $button.prop('disabled', false);
            $spinner.removeClass('is-active');
        });
    });

    // Export CSV Button Functionality
    $('#lcd-export-csv-button').on('click', function() {
        var $button = $(this);
        $button.prop('disabled', true).text('Exporting...');

        // Construct the data object from current filters
        var data = {
            action: 'lcd_export_people_csv',
            nonce: lcdPeopleAdmin.nonce,
            membership_status: $('select[name="membership_status"]').val(),
            membership_type: $('select[name="membership_type"]').val(),
            is_sustaining: $('select[name="is_sustaining"]').val(),
            lcd_role: $('select[name="lcd_role"]').val(),
            s: $('#post-search-input').val(),
            orderby: $('input[name="orderby"]').val(),
            order: $('input[name="order"]').val()
        };

        $.get(lcdPeopleAdmin.ajaxurl, data, function(response) {
            if (response.success && response.data.csv_content) {
                // Create downloadable CSV file
                var blob = new Blob([response.data.csv_content], { type: 'text/csv;charset=utf-8;' });
                var link = document.createElement('a');
                var url = URL.createObjectURL(blob);
                link.setAttribute('href', url);
                link.setAttribute('download', response.data.filename);
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                
                alert(lcdPeopleAdmin.strings.exportSuccess + ' (' + response.data.total + ' records)');
            } else if (response.success) {
                alert(lcdPeopleAdmin.strings.noData);
            } else {
                alert(lcdPeopleAdmin.strings.exportError + ' ' + (response.data && response.data.message ? response.data.message : 'Unknown error'));
            }
        }).fail(function() {
            alert(lcdPeopleAdmin.strings.exportError + ' ' + lcdPeopleAdmin.strings.ajaxRequestFailed);
        }).always(function() {
            $button.prop('disabled', false).text('Export CSV');
        });
    });
}); 