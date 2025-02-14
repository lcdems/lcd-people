jQuery(document).ready(function($) {
    // Initialize Select2 if the element exists
    const $userSearch = $('#lcd_person_user_search');
    if ($userSearch.length && typeof $.fn.select2 === 'function') {
        $userSearch.select2({
            ajax: {
                url: lcdPeople.ajaxurl,
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    return {
                        q: params.term,
                        action: 'lcd_search_users',
                        nonce: lcdPeople.nonce
                    };
                },
                processResults: function(data) {
                    return {
                        results: data
                    };
                },
                cache: true
            },
            minimumInputLength: 2,
            placeholder: $userSearch.attr('placeholder')
        }).on('select2:select', function(e) {
            const userId = e.params.data.id;
            
            // Check for existing connection
            $.ajax({
                url: lcdPeople.ajaxurl,
                data: {
                    action: 'lcd_check_user_connection',
                    user_id: userId,
                    nonce: lcdPeople.nonce
                },
                success: function(response) {
                    if (response.connected) {
                        alert(response.message);
                        $userSearch.val(null).trigger('change');
                    } else {
                        $('#lcd_person_user_id').val(userId);
                    }
                }
            });
        });
    }

    // Handle user disconnection
    $('.lcd-disconnect-user').on('click', function(e) {
        e.preventDefault();
        
        if (confirm($(this).data('confirm'))) {
            // Clear the user ID
            $('#lcd_person_user_id').val('');
            
            // Submit the form to save changes
            $(this).closest('form').submit();
        }
    });

    // Membership functionality
    function showActBlueEditDialog() {
        const currentId = $('#lcd_person_actblue_lineitem_id').val();
        const dialog = $('<div>', {
            title: currentId ? 'Edit ActBlue Payment' : 'Add ActBlue Payment',
            class: 'actblue-edit-dialog'
        }).appendTo('body');

        const content = $('<div>', {
            class: 'actblue-edit-content'
        }).appendTo(dialog);

        content.append(
            $('<p>').text('Enter the ActBlue Line Item ID for this membership payment:'),
            $('<input>', {
                type: 'number',
                id: 'actblue_lineitem_id_edit',
                value: currentId || ''
            }),
            $('<p>', { class: 'description' }).text('You can find this ID in the URL of the payment in ActBlue')
        );

        dialog.dialog({
            modal: true,
            width: 400,
            buttons: {
                Save: function() {
                    const lineItemId = $('#actblue_lineitem_id_edit').val();
                    if (lineItemId) {
                        $('#lcd_person_actblue_lineitem_id').val(lineItemId);
                        updateActBlueView(lineItemId);
                    }
                    $(this).dialog('close');
                },
                Cancel: function() {
                    $(this).dialog('close');
                }
            },
            close: function() {
                $(this).dialog('destroy').remove();
            }
        });
    }

    function updateActBlueView(lineItemId) {
        const container = $('#actblue-payment-details');
        if (lineItemId) {
            const url = `https://secure.actblue.com/entities/155025/lineitems/${lineItemId}`;
            container.html(`
                <input type="hidden" id="lcd_person_actblue_lineitem_id" name="lcd_person_actblue_lineitem_id" value="${lineItemId}">
                <a href="${url}" target="_blank" class="button button-primary">
                    <span class="dashicons dashicons-external"></span>
                    View Payment on ActBlue
                </a>
                <a href="#" class="edit-actblue-payment">
                    <span class="dashicons dashicons-edit"></span>
                </a>
            `);
        } else {
            container.html(`
                <input type="hidden" id="lcd_person_actblue_lineitem_id" name="lcd_person_actblue_lineitem_id" value="">
                <button type="button" class="button button-secondary add-actblue-payment">
                    <span class="dashicons dashicons-plus-alt2"></span>
                    Add ActBlue Payment
                </button>
            `);
        }
    }

    // Handle payment method changes
    $('#lcd_person_dues_paid_via').on('change', function() {
        const method = $(this).val();
        const container = $('#actblue-payment-details');
        
        if (method === 'actblue') {
            container.show();
            const lineItemId = container.find('#lcd_person_actblue_lineitem_id').val();
            if (!lineItemId) {
                showActBlueEditDialog();
            } else {
                updateActBlueView(lineItemId);
            }
        } else {
            container.hide();
        }
    });

    // Handle edit/add button clicks
    $(document).on('click', '.edit-actblue-payment, .add-actblue-payment', function(e) {
        e.preventDefault();
        showActBlueEditDialog();
    });

    // Handle membership cancellation
    $('#lcd-cancel-membership').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm('Are you sure you want to cancel this membership? This will update several fields and cannot be undone.')) {
            return;
        }

        const $button = $(this);
        $button.prop('disabled', true);

        $.ajax({
            url: lcdPeople.ajaxurl,
            type: 'POST',
            data: {
                action: 'lcd_cancel_membership',
                nonce: lcdPeople.nonce,
                person_id: $('#post_ID').val()
            },
            success: function(response) {
                if (response.success) {
                    // Update form fields
                    $('#lcd_person_membership_status').val('inactive');
                    $('#lcd_person_is_sustaining').prop('checked', false);
                    $('#lcd_person_end_date').val(response.data.current_date);
                    $('#lcd_person_dues_paid_via').val('').trigger('change');
                    
                    // Hide the cancel button
                    $button.hide();
                    
                    // Show success message
                    alert('Membership has been cancelled successfully.');
                } else {
                    alert(response.data.message || 'Failed to cancel membership.');
                    $button.prop('disabled', false);
                }
            },
            error: function() {
                alert('Failed to cancel membership.');
                $button.prop('disabled', false);
            }
        });
    });

    // Initialize the ActBlue view on page load
    const duesMethod = $('#lcd_person_dues_paid_via').val();
    const container = $('#actblue-payment-details');
    
    if (duesMethod === 'actblue') {
        container.show();
        const lineItemId = $('#lcd_person_actblue_lineitem_id').val();
        updateActBlueView(lineItemId);
    } else {
        container.hide();
    }

    // Handle password toggle functionality
    $(document).on('click', '.password-toggle-wrapper .toggle-password', function(e) {
        e.preventDefault();
        const $this = $(this);
        const $input = $this.closest('.password-toggle-wrapper').find('input');
        
        if ($input.attr('type') === 'password') {
            $input.attr('type', 'text');
            $this.removeClass('dashicons-visibility').addClass('dashicons-hidden');
        } else {
            $input.attr('type', 'password');
            $this.removeClass('dashicons-hidden').addClass('dashicons-visibility');
        }
    });

    // Initialize the re-trigger welcome dialog
    var $retriggerDialog = $('#lcd-retrigger-welcome-dialog').dialog({
        autoOpen: false,
        modal: true,
        buttons: {
            "Re-trigger": function() {
                var dialog = this;
                var $button = $('#lcd-retrigger-welcome');
                var postId = $('#post_ID').val();

                $button.prop('disabled', true);
                
                $.post(lcdPeople.ajaxurl, {
                    action: 'lcd_retrigger_welcome',
                    person_id: postId,
                    nonce: lcdPeople.nonce
                })
                .done(function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert(response.data.message);
                    }
                })
                .fail(function() {
                    alert(lcdPeople.strings.retriggerError);
                })
                .always(function() {
                    $button.prop('disabled', false);
                    $(dialog).dialog('close');
                });
            },
            "Cancel": function() {
                $(this).dialog('close');
            }
        }
    });

    // Handle re-trigger welcome button click
    $('#lcd-retrigger-welcome').on('click', function(e) {
        e.preventDefault();
        $retriggerDialog.dialog('open');
    });
}); 