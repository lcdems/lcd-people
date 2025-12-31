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
                // Track state
                smsOptedIn: false,
                originalPhone: '',
                pendingPhoneChange: false,
                pendingPhoneRemoval: false,
                
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
                    $('#sub-phone-original').val(data.phone || '');
                    
                    // Store original values
                    this.originalPhone = data.phone || '';
                    
                    // Determine SMS opt-in status from backend (based on SMS group membership)
                    this.smsOptedIn = !!(data.sms_opted_in);
                    
                    // Show a notice if basic data is missing
                    if (!data.first_name || !data.last_name) {
                        $('#subscription-form-container .lcd-preferences-section:first').prepend(
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
                        groupsContainer.closest('.subscription-form-group').show();
                    } else {
                        // Hide the entire Email Interests section when no groups are available
                        groupsContainer.closest('.subscription-form-group').hide();
                    }
                    
                    // Update SMS section based on opt-in status
                    this.updateSmsSection();
                },
                
                updateSmsSection: function() {
                    var hasPhone = $('#sub-phone').val().trim().length > 0;
                    var toggle = $('#sms-optin-toggle');
                    
                    // Hide all SMS panels first
                    $('#sms-optin-panel, #sms-optedin-status, #sms-optout-confirm, #sms-not-optedin-status').hide();
                    
                    if (this.smsOptedIn) {
                        // User is opted in
                        toggle.prop('checked', true);
                        $('#sms-optedin-status').show();
                    } else {
                        // User is not opted in
                        toggle.prop('checked', false);
                        $('#sms-not-optedin-status').show();
                    }
                },
                
                bindEvents: function() {
                    var self = this;
                    
                    // Form submission with phone change detection
                    $('#subscription-preferences-form').on('submit', function(e) {
                        e.preventDefault();
                        
                        var currentPhone = $('#sub-phone').val().trim();
                        var originalPhone = self.originalPhone;
                        var phoneChanged = self.normalizePhone(currentPhone) !== self.normalizePhone(originalPhone);
                        var phoneRemoved = currentPhone === '' && originalPhone !== '';
                        
                        // Check for phone removal while opted in
                        if (phoneRemoved && self.smsOptedIn) {
                            self.pendingPhoneRemoval = true;
                            $('#phone-removal-warning').show();
                            return;
                        }
                        
                        // Check for phone number change while opted in
                        if (phoneChanged && !phoneRemoved && self.smsOptedIn) {
                            self.pendingPhoneChange = true;
                            $('#phone-change-warning').show();
                            return;
                        }
                        
                        self.updatePreferences();
                    });
                    
                    // Phone change confirmation
                    $('#phone-change-confirm-btn').on('click', function() {
                        $('#phone-change-warning').hide();
                        // Reset SMS opt-in status since phone changed
                        self.smsOptedIn = false;
                        self.pendingPhoneChange = false;
                        self.updatePreferences(true); // Pass flag to indicate phone change
                    });
                    
                    $('#phone-change-cancel-btn').on('click', function() {
                        $('#phone-change-warning').hide();
                        self.pendingPhoneChange = false;
                        // Restore original phone
                        $('#sub-phone').val(self.originalPhone);
                    });
                    
                    // Phone removal confirmation
                    $('#phone-removal-confirm-btn').on('click', function() {
                        $('#phone-removal-warning').hide();
                        self.pendingPhoneRemoval = false;
                        
                        // First opt out, then save profile
                        self.smsOptout(function() {
                            self.smsOptedIn = false;
                            self.updatePreferences(true);
                        });
                    });
                    
                    $('#phone-removal-cancel-btn').on('click', function() {
                        $('#phone-removal-warning').hide();
                        self.pendingPhoneRemoval = false;
                        // Restore original phone
                        $('#sub-phone').val(self.originalPhone);
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
                    
                    // SMS opt-in toggle
                    $('#sms-optin-toggle').on('change', function() {
                        var isChecked = $(this).is(':checked');
                        
                        // Hide all panels
                        $('#sms-optin-panel, #sms-optedin-status, #sms-optout-confirm, #sms-not-optedin-status').hide();
                        
                        if (isChecked) {
                            if (self.smsOptedIn) {
                                // Already opted in, show status
                                $('#sms-optedin-status').show();
                            } else {
                                // Show opt-in panel
                                $('#sms-optin-panel').show();
                                self.checkSmsOptinReady();
                            }
                        } else {
                            if (self.smsOptedIn) {
                                // Currently opted in, show opt-out confirmation
                                $('#sms-optout-confirm').show();
                            } else {
                                // Not opted in, show not opted in status
                                $('#sms-not-optedin-status').show();
                            }
                        }
                    });
                    
                    // SMS consent checkbox - enable/disable opt-in button
                    $('#sms-consent-checkbox').on('change', function() {
                        self.checkSmsOptinReady();
                    });
                    
                    // Phone number change detection for SMS opt-in readiness
                    $('#sub-phone').on('input', function() {
                        self.checkSmsOptinReady();
                    });
                    
                    // SMS Opt-in button
                    $('#sms-optin-btn').on('click', function() {
                        self.smsOptin();
                    });
                    
                    // SMS Opt-out confirm button
                    $('#sms-optout-confirm-btn').on('click', function() {
                        self.smsOptout(function() {
                            self.smsOptedIn = false;
                            self.updateSmsSection();
                            self.showSuccess(lcdPeopleFrontend.strings.sms_optout_success || 'You have been opted out of SMS messages.');
                        });
                    });
                    
                    // SMS Opt-out cancel button
                    $('#sms-optout-cancel-btn').on('click', function() {
                        // Reset toggle to checked state
                        $('#sms-optin-toggle').prop('checked', true);
                        $('#sms-optout-confirm').hide();
                        $('#sms-optedin-status').show();
                    });
                },
                
                normalizePhone: function(phone) {
                    // Remove all non-digit characters for comparison
                    return (phone || '').replace(/\D/g, '');
                },
                
                checkSmsOptinReady: function() {
                    var hasPhone = $('#sub-phone').val().trim().length > 0;
                    var consentChecked = $('#sms-consent-checkbox').is(':checked');
                    
                    // Show/hide phone required notice
                    if (!hasPhone) {
                        $('#sms-phone-required-notice').show();
                        $('#sms-optin-btn').prop('disabled', true);
                    } else {
                        $('#sms-phone-required-notice').hide();
                        // Enable button only if consent is checked
                        $('#sms-optin-btn').prop('disabled', !consentChecked);
                    }
                },
                
                smsOptin: function() {
                    var self = this;
                    var phone = $('#sub-phone').val().trim();
                    
                    if (!phone) {
                        alert(lcdPeopleFrontend.strings.phone_required || 'Please enter a phone number first.');
                        return;
                    }
                    
                    $('#sms-optin-btn').prop('disabled', true).text(lcdPeopleFrontend.strings.processing || 'Processing...');
                    
                    $.post(lcdPeopleFrontend.ajaxurl, {
                        action: 'lcd_sms_optin',
                        nonce: lcdPeopleFrontend.subscription_nonce,
                        phone: phone
                    })
                    .done(function(response) {
                        $('#sms-optin-btn').prop('disabled', false).text(lcdPeopleFrontend.strings.sms_optin_btn || 'Opt In to SMS');
                        
                        if (response.success) {
                            self.smsOptedIn = true;
                            self.originalPhone = phone; // Update original phone
                            self.updateSmsSection();
                            self.showSuccess(response.data.message);
                        } else {
                            alert(response.data.message || lcdPeopleFrontend.strings.error);
                        }
                    })
                    .fail(function() {
                        $('#sms-optin-btn').prop('disabled', false).text(lcdPeopleFrontend.strings.sms_optin_btn || 'Opt In to SMS');
                        alert(lcdPeopleFrontend.strings.error);
                    });
                },
                
                smsOptout: function(callback) {
                    var self = this;
                    
                    $('#sms-optout-confirm-btn').prop('disabled', true).text(lcdPeopleFrontend.strings.processing || 'Processing...');
                    
                    $.post(lcdPeopleFrontend.ajaxurl, {
                        action: 'lcd_sms_optout',
                        nonce: lcdPeopleFrontend.subscription_nonce
                    })
                    .done(function(response) {
                        $('#sms-optout-confirm-btn').prop('disabled', false).text(lcdPeopleFrontend.strings.sms_optout_btn || 'Yes, Opt Out');
                        
                        if (response.success) {
                            if (typeof callback === 'function') {
                                callback();
                            }
                        } else {
                            alert(response.data.message || lcdPeopleFrontend.strings.error);
                            // Reset toggle
                            $('#sms-optin-toggle').prop('checked', true);
                            self.updateSmsSection();
                        }
                    })
                    .fail(function() {
                        $('#sms-optout-confirm-btn').prop('disabled', false).text(lcdPeopleFrontend.strings.sms_optout_btn || 'Yes, Opt Out');
                        alert(lcdPeopleFrontend.strings.error);
                        // Reset toggle
                        $('#sms-optin-toggle').prop('checked', true);
                        self.updateSmsSection();
                    });
                },
                
                updatePreferences: function(phoneChanged) {
                    var self = this;
                    var formData = {
                        action: 'lcd_update_subscription_preferences',
                        nonce: lcdPeopleFrontend.subscription_nonce,
                        first_name: $('#sub-first-name').val(),
                        last_name: $('#sub-last-name').val(),
                        phone: $('#sub-phone').val(),
                        sms_consent: self.smsOptedIn ? 1 : 0, // Only pass consent if already opted in
                        groups: [],
                        phone_changed: phoneChanged ? 1 : 0
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
                            // Update original phone to current value
                            self.originalPhone = $('#sub-phone').val().trim();
                            $('#sub-phone-original').val(self.originalPhone);
                            
                            // If phone was changed, reset SMS opt-in status
                            if (phoneChanged) {
                                self.smsOptedIn = false;
                                self.updateSmsSection();
                            }
                            
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