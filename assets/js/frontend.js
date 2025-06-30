/**
 * LCD People Frontend JavaScript
 */
(function($) {
    'use strict';

    // Initialize the member profile functionality
    function initMemberProfile() {
        initTabSwitching();
        initVolunteerInterestsToggle();
    }

    // Initialize tab switching functionality
    function initTabSwitching() {
        $('.lcd-tab-button').on('click', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var targetTab = $button.data('tab');
            
            // Don't do anything if the tab is already active
            if ($button.hasClass('active')) {
                return;
            }
            
            // Remove active class from all buttons and content
            $('.lcd-tab-button').removeClass('active').attr('aria-selected', 'false');
            $('.lcd-tab-content').removeClass('active');
            
            // Add active class to clicked button
            $button.addClass('active').attr('aria-selected', 'true');
            
            // Show the corresponding tab content
            $('#' + targetTab + '-tab').addClass('active');
            
            // Store the active tab in session storage for persistence
            if (typeof(Storage) !== "undefined") {
                sessionStorage.setItem('lcd_member_active_tab', targetTab);
            }
        });
        
        // Add keyboard navigation for tabs
        $('.lcd-tab-button').on('keydown', function(e) {
            var $buttons = $('.lcd-tab-button');
            var currentIndex = $buttons.index(this);
            var $target;
            
            switch(e.which) {
                case 37: // Left arrow
                    $target = $buttons.eq(currentIndex - 1);
                    if ($target.length === 0) {
                        $target = $buttons.last();
                    }
                    break;
                case 39: // Right arrow
                    $target = $buttons.eq(currentIndex + 1);
                    if ($target.length === 0) {
                        $target = $buttons.first();
                    }
                    break;
                case 36: // Home
                    $target = $buttons.first();
                    break;
                case 35: // End
                    $target = $buttons.last();
                    break;
                default:
                    return;
            }
            
            e.preventDefault();
            $target.focus().trigger('click');
        });
        
        // Restore the active tab from session storage
        if (typeof(Storage) !== "undefined") {
            var activeTab = sessionStorage.getItem('lcd_member_active_tab');
            if (activeTab) {
                var $targetButton = $('.lcd-tab-button[data-tab="' + activeTab + '"]');
                if ($targetButton.length) {
                    $targetButton.trigger('click');
                }
            }
        }
    }

    // Initialize volunteer interests toggle functionality
    function initVolunteerInterestsToggle() {
        $('.lcd-volunteer-interests-header').on('click', function(e) {
            e.preventDefault();
            toggleVolunteerInterests($(this));
        });
        
        // Add keyboard support for the toggle
        $('.lcd-volunteer-interests-header').on('keydown', function(e) {
            // Enter or Space key
            if (e.which === 13 || e.which === 32) {
                e.preventDefault();
                toggleVolunteerInterests($(this));
            }
        });
    }

    // Toggle volunteer interests section
    function toggleVolunteerInterests($header) {
        var $content = $header.siblings('.lcd-volunteer-interests-content');
        var isExpanded = $header.attr('aria-expanded') === 'true';
        
        if (isExpanded) {
            // Collapse
            $content.slideUp(300);
            $header.attr('aria-expanded', 'false');
        } else {
            // Expand
            $content.slideDown(300);
            $header.attr('aria-expanded', 'true');
        }
        
        // Store the state in session storage
        if (typeof(Storage) !== "undefined") {
            sessionStorage.setItem('lcd_volunteer_interests_expanded', !isExpanded);
        }
    }

    // Restore volunteer interests toggle state
    function restoreVolunteerInterestsState() {
        if (typeof(Storage) !== "undefined") {
            var isExpanded = sessionStorage.getItem('lcd_volunteer_interests_expanded') === 'true';
            if (isExpanded) {
                var $header = $('.lcd-volunteer-interests-header');
                var $content = $('.lcd-volunteer-interests-content');
                
                $header.attr('aria-expanded', 'true');
                $content.show();
            }
        }
    }

    // Initialize subscription preferences functionality
    function initSubscriptionPreferences() {
        if (typeof lcdSubscriptionPrefs === 'undefined') {
            window.lcdSubscriptionPrefs = {
                init: function() {
                    this.loadPreferences();
                    this.bindEvents();
                },
                
                loadPreferences: function() {
                    this.showLoading();
                    
                    $.post(lcdPeopleFrontend.ajaxurl, {
                        action: 'lcd_get_subscription_preferences',
                        nonce: lcdPeopleFrontend.subscription_nonce
                    })
                    .done(function(response) {
                        if (response.success) {
                            lcdSubscriptionPrefs.populateForm(response.data);
                            lcdSubscriptionPrefs.showForm();
                        } else {
                            lcdSubscriptionPrefs.showNotFound();
                        }
                    })
                    .fail(function() {
                        lcdSubscriptionPrefs.showError(lcdPeopleFrontend.strings.error);
                    });
                },
                
                populateForm: function(data) {
                    $('#sub-email').val(data.email);
                    $('#sub-first-name').val(data.first_name || '');
                    $('#sub-last-name').val(data.last_name || '');
                    $('#sub-phone').val(data.phone || '');
                    $('#sub-sms-consent').prop('checked', data.phone && data.phone.length > 0);
                    
                    // Show a notice if basic data is missing
                    if (!data.first_name || !data.last_name) {
                        $('#subscription-form-container').prepend(
                            '<div id="missing-data-notice" class="lcd-info-message" style="margin-bottom: 20px;">' +
                            '<p><strong>Notice:</strong> Some of your information is missing from our email system. ' +
                            'Please fill in your name below and we\'ll update your record.</p>' +
                            '</div>'
                        );
                    }
                    
                    // Populate groups
                    var groupsContainer = $('#subscription-groups');
                    groupsContainer.empty();
                    
                    if (data.available_groups && Object.keys(data.available_groups).length > 0) {
                        Object.keys(data.available_groups).forEach(function(groupId) {
                            var group = data.available_groups[groupId];
                            var isChecked = data.user_groups.includes(groupId);
                            
                            var checkbox = $('<label class="subscription-checkbox-label">' +
                                '<input type="checkbox" name="groups[]" value="' + groupId + '"' + 
                                (isChecked ? ' checked' : '') + '>' +
                                '<span class="checkmark"></span>' +
                                group.name +
                                '</label>');
                            
                            groupsContainer.append(checkbox);
                        });
                    } else {
                        groupsContainer.append('<p class="description">No subscription groups available.</p>');
                    }
                },
                
                bindEvents: function() {
                    var self = this;
                    
                    // Form submission
                    $('#subscription-preferences-form').on('submit', function(e) {
                        e.preventDefault();
                        self.updatePreferences();
                    });
                    
                    // Unsubscribe all
                    $('#unsubscribe-all-btn').on('click', function(e) {
                        e.preventDefault();
                        if (confirm(lcdPeopleFrontend.strings.confirm_unsubscribe)) {
                            self.unsubscribeAll();
                        }
                    });
                    
                    // Retry button
                    $('.retry-btn').on('click', function(e) {
                        e.preventDefault();
                        self.loadPreferences();
                    });
                    
                    // SMS consent checkbox
                    $('#sub-sms-consent').on('change', function() {
                        var phoneField = $('#sub-phone');
                        if ($(this).is(':checked')) {
                            phoneField.prop('required', true);
                        } else {
                            phoneField.prop('required', false);
                        }
                    });
                },
                
                updatePreferences: function() {
                    var formData = {
                        action: 'lcd_update_subscription_preferences',
                        nonce: lcdPeopleFrontend.subscription_nonce,
                        first_name: $('#sub-first-name').val(),
                        last_name: $('#sub-last-name').val(),
                        phone: $('#sub-phone').val(),
                        sms_consent: $('#sub-sms-consent').is(':checked') ? 1 : 0,
                        groups: []
                    };
                    
                    // Get selected groups
                    $('input[name="groups[]"]:checked').each(function() {
                        formData.groups.push($(this).val());
                    });
                    
                    this.showUpdating();
                    
                    $.post(lcdPeopleFrontend.ajaxurl, formData)
                    .done(function(response) {
                        lcdSubscriptionPrefs.resetButtons();
                        if (response.success) {
                            lcdSubscriptionPrefs.showSuccess(response.data.message);
                        } else {
                            lcdSubscriptionPrefs.showError(response.data.message);
                        }
                    })
                    .fail(function(xhr, status, error) {
                        console.error('AJAX request failed:', status, error);
                        lcdSubscriptionPrefs.resetButtons();
                        lcdSubscriptionPrefs.showError(lcdPeopleFrontend.strings.error);
                    });
                },
                
                unsubscribeAll: function() {
                    this.showUpdating();
                    
                    $.post(lcdPeopleFrontend.ajaxurl, {
                        action: 'lcd_unsubscribe_from_all',
                        nonce: lcdPeopleFrontend.subscription_nonce
                    })
                    .done(function(response) {
                        if (response.success) {
                            lcdSubscriptionPrefs.showSuccess(response.data.message);
                            // Hide the form after successful unsubscribe
                            $('#subscription-form-container').hide();
                        } else {
                            lcdSubscriptionPrefs.resetButtons();
                            lcdSubscriptionPrefs.showError(response.data.message);
                        }
                    })
                    .fail(function() {
                        lcdSubscriptionPrefs.resetButtons();
                        lcdSubscriptionPrefs.showError(lcdPeopleFrontend.strings.error);
                    });
                },
                
                showLoading: function() {
                    $('#subscription-loading').show();
                    $('#subscription-error').hide();
                    $('#subscription-form-container').hide();
                    $('#subscription-not-found').hide();
                    $('#subscription-success').hide();
                },
                
                showForm: function() {
                    $('#subscription-loading').hide();
                    $('#subscription-error').hide();
                    $('#subscription-form-container').show();
                    $('#subscription-not-found').hide();
                    $('#subscription-success').hide();
                    
                    // Clear any previous missing data notice
                    $('#missing-data-notice').remove();
                },
                
                showError: function(message) {
                    $('#subscription-loading').hide();
                    $('#subscription-error .error-text').text(message);
                    $('#subscription-error').show();
                    $('#subscription-form-container').hide();
                    $('#subscription-not-found').hide();
                    $('#subscription-success').hide();
                },
                
                showSuccess: function(message) {
                    $('#subscription-success .success-text').text(message);
                    $('#subscription-success').show();
                    $('#subscription-loading').hide();
                    $('#subscription-error').hide();
                    
                    // Hide success message after 5 seconds
                    setTimeout(function() {
                        $('#subscription-success').fadeOut();
                    }, 5000);
                },
                
                showNotFound: function() {
                    $('#subscription-loading').hide();
                    $('#subscription-error').hide();
                    $('#subscription-form-container').hide();
                    $('#subscription-not-found').show();
                    $('#subscription-success').hide();
                },
                
                showUpdating: function() {
                    $('#subscription-preferences-form button').prop('disabled', true);
                    $('#subscription-preferences-form button[type="submit"]').text(lcdPeopleFrontend.strings.updating);
                },
                
                resetButtons: function() {
                    $('#subscription-preferences-form button').prop('disabled', false);
                    $('#subscription-preferences-form button[type="submit"]').text(lcdPeopleFrontend.strings.update_preferences_btn);
                }
            };
            
            lcdSubscriptionPrefs.init();
        }
    }

    // Initialize when document is ready
    $(document).ready(function() {
        initMemberProfile();
        restoreVolunteerInterestsState();
        
        // Initialize subscription preferences if the tab exists
        if ($('#subscriptions-tab').length) {
            initSubscriptionPreferences();
        }
    });

})(jQuery); 