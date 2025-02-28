/**
 * JavaScript for LCD People admin list page
 * Handles the "Copy Emails" button functionality
 */
jQuery(document).ready(function($) {
    // Handle the Copy Emails button click
    $('#lcd-copy-emails-button').on('click', function(e) {
        e.preventDefault();
        
        // Get button and change its text to show loading state
        const $button = $(this);
        const originalText = $button.text();
        $button.text('Loading...').prop('disabled', true);
        
        // Get the current URL params to pass along for filters
        const urlParams = new URLSearchParams(window.location.search);
        
        // Make AJAX request to get all emails
        $.ajax({
            url: lcdPeopleAdmin.ajaxurl,
            type: 'GET',
            data: {
                action: 'lcd_get_filtered_emails',
                nonce: lcdPeopleAdmin.nonce,
                s: urlParams.get('s') || '',
                membership_status: urlParams.get('membership_status') || '',
                membership_type: urlParams.get('membership_type') || '',
                is_sustaining: urlParams.get('is_sustaining') || '',
                lcd_role: urlParams.get('lcd_role') || ''
            },
            success: function(response) {
                if (response.success && response.data.emails.length > 0) {
                    // Create a comma-separated list of emails
                    const emailList = response.data.emails.join(', ');
                    
                    // Copy to clipboard
                    copyToClipboard(emailList, function(success) {
                        if (success) {
                            // Show success message
                            $button.text(lcdPeopleAdmin.strings.copySuccess + ' (' + response.data.total + ')');
                            setTimeout(function() {
                                $button.text(originalText).prop('disabled', false);
                            }, 3000);
                        } else {
                            // Show error message
                            $button.text(lcdPeopleAdmin.strings.copyError);
                            console.error('Failed to copy to clipboard');
                            setTimeout(function() {
                                $button.text(originalText).prop('disabled', false);
                            }, 3000);
                        }
                    });
                } else {
                    // Show no emails message
                    $button.text(lcdPeopleAdmin.strings.noEmails);
                    setTimeout(function() {
                        $button.text(originalText).prop('disabled', false);
                    }, 3000);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', error);
                $button.text(lcdPeopleAdmin.strings.copyError);
                setTimeout(function() {
                    $button.text(originalText).prop('disabled', false);
                }, 3000);
            }
        });
    });
    
    /**
     * Helper function to copy text to clipboard
     */
    function copyToClipboard(text, callback) {
        // Modern approach using the Clipboard API
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text)
                .then(() => callback(true))
                .catch(() => {
                    // Fallback for older browsers
                    fallbackCopyToClipboard(text, callback);
                });
        } else {
            // Fallback for older browsers or non-secure contexts
            fallbackCopyToClipboard(text, callback);
        }
    }
    
    /**
     * Fallback method to copy to clipboard using a temporary textarea
     */
    function fallbackCopyToClipboard(text, callback) {
        try {
            // Create a temporary textarea element
            const textarea = document.createElement('textarea');
            textarea.value = text;
            
            // Set the CSS to make it invisible
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            
            document.body.appendChild(textarea);
            textarea.select();
            
            // Execute the copy command
            const success = document.execCommand('copy');
            document.body.removeChild(textarea);
            
            callback(success);
        } catch (err) {
            console.error('Fallback copy to clipboard failed:', err);
            callback(false);
        }
    }
}); 