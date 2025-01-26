jQuery(document).ready(function($) {
    // Initialize select2 for user search
    $('#lcd_person_user_search').select2({
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
        placeholder: $(this).attr('placeholder')
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
                    $('#lcd_person_user_search').val(null).trigger('change');
                } else {
                    $('#lcd_person_user_id').val(userId);
                }
            }
        });
    });

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
}); 