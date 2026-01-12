/**
 * LCD People Opt-in Form JavaScript
 * 
 * Handles opt-in form functionality and modal integration
 */

(function($) {
    'use strict';

    // Main opt-in form functionality
    window.lcdOptinForm = {
        
        init: function() {
            this.bindEvents();
            this.initTracking();
        },
        
        initTracking: function() {
            // Initialize tracking if IDs are configured
            if (lcdOptinVars.tracking.google_gtag_id && typeof gtag === 'undefined' && typeof window.google_tag_manager === 'undefined') {
                console.log('LCD Opt-in: Google Analytics not detected. Make sure gtag is loaded.');
            }
            
            if (lcdOptinVars.tracking.facebook_pixel_id && typeof fbq === 'undefined') {
                console.log('LCD Opt-in: Facebook Pixel not detected. Make sure fbq is loaded.');
            }
        },
        
        bindEvents: function() {
            var self = this;
            
            // Combined form submission
            $(document).on('submit', '#lcd-optin-combined-form', function(e) {
                e.preventDefault();
                self.submitCombinedForm();
            });
            
            // Combined form field validation
            $(document).on('input change', '#lcd-optin-combined-form input', function() {
                self.updateCombinedButtonState();
            });
            
            // Show/hide SMS consent based on phone input in combined form
            $(document).on('input', '#lcd-optin-phone-combined', function() {
                self.updateCombinedSMSVisibility();
            });
        },
        
        updateCombinedButtonState: function() {
            var email = $('#lcd-optin-email-combined').val().trim();
            var phone = $('#lcd-optin-phone-combined').val().trim();
            var smsConsent = $('#lcd-optin-sms-consent-combined').is(':checked');
            var mainConsentCheckbox = $('#lcd-optin-main-consent-combined');
            var mainConsent = mainConsentCheckbox.length ? mainConsentCheckbox.is(':checked') : true;
            var submitBtn = $('#lcd-combined-submit-btn');
            
            // Basic email validation - email is the only required field
            var emailValid = email && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
            
            // If phone is entered, SMS consent is required
            var smsValid = !phone || smsConsent;
            
            if (emailValid && mainConsent && smsValid) {
                submitBtn.prop('disabled', false);
            } else {
                submitBtn.prop('disabled', true);
            }
        },
        
        updateCombinedSMSVisibility: function() {
            var phone = $('#lcd-optin-phone-combined').val().trim();
            var smsWrapper = $('#lcd-sms-consent-wrapper-combined');
            var smsCheckbox = $('#lcd-optin-sms-consent-combined');
            
            if (phone) {
                smsWrapper.slideDown(200);
            } else {
                smsWrapper.slideUp(200);
                smsCheckbox.prop('checked', false);
            }
            
            // Update button state after visibility change
            this.updateCombinedButtonState();
        },
        
        submitCombinedForm: function() {
            // Validate main consent checkbox if present
            var mainConsentCheckbox = $('#lcd-optin-main-consent-combined');
            if (mainConsentCheckbox.length && !mainConsentCheckbox.is(':checked')) {
                this.showError(lcdOptinVars.strings.required_consent || 'Please accept the terms and conditions.');
                return;
            }
            
            var smsConsent = $('#lcd-optin-sms-consent-combined').is(':checked');
            var phone = $('#lcd-optin-phone-combined').val().trim();
            
            // If phone is provided, SMS consent is required
            if (phone && !smsConsent) {
                this.showError('Please accept the SMS consent to receive text messages.');
                return;
            }
            
            var formData = {
                action: 'lcd_optin_submit_combined',
                nonce: lcdOptinVars.nonce,
                first_name: $('#lcd-optin-first-name-combined').val(),
                last_name: $('#lcd-optin-last-name-combined').val(),
                email: $('#lcd-optin-email-combined').val(),
                phone: phone,
                groups: [],
                sms_consent: smsConsent ? 1 : 0,
                main_consent: mainConsentCheckbox.length ? (mainConsentCheckbox.is(':checked') ? 1 : 0) : 1,
                extra_sender_groups: $('input[name="extra_sender_groups"]').val() || '',
                extra_callhub_tags: $('input[name="extra_callhub_tags"]').val() || ''
            };
            
            // Get selected groups
            $('#lcd-optin-combined-form input[name="groups[]"]:checked').each(function() {
                formData.groups.push($(this).val());
            });
            
            // Auto-select the first group if none are selected but groups are available
            if (formData.groups.length === 0) {
                var firstGroup = $('#lcd-optin-combined-form input[name="groups[]"]').first();
                if (firstGroup.length) {
                    firstGroup.prop('checked', true);
                    formData.groups.push(firstGroup.val());
                }
            }
            
            this.showLoading();
            
            var self = this;
            var includedSMS = phone && smsConsent;
            var redirectUrl = $('input[name="redirect_url"]').val() || '';
            
            $.post(lcdOptinVars.ajaxurl, formData)
                .done(function(response) {
                    if (response.success) {
                        // Fire conversion tracking for email
                        self.fireEmailConversionTracking();
                        // Fire SMS tracking only if phone was provided
                        if (includedSMS) {
                            self.fireSMSConversionTracking();
                        }
                        
                        // If redirect URL is set, redirect instead of showing inline success
                        if (redirectUrl) {
                            window.location.href = redirectUrl;
                        } else {
                            self.showSuccess(response.data.message);
                        }
                    } else {
                        self.showError(response.data.message || lcdOptinVars.strings.error);
                    }
                })
                .fail(function() {
                    self.showError(lcdOptinVars.strings.error);
                });
        },
        
        showStep: function(step) {
            $('.lcd-optin-step').hide();
            $('#lcd-optin-step-' + step).show();
            $('#lcd-optin-loading').hide();
        },
        
        showLoading: function() {
            $('.lcd-optin-step').hide();
            $('#lcd-optin-error').hide();
            $('#lcd-optin-loading').show();
        },
        
        showSuccess: function(message) {
            $('#lcd-success-message').text(message);
            this.showStep('success');
            
            // Auto-close modal after 3 seconds if in modal
            if (typeof LCDModal !== 'undefined' && $('.lcd-optin-modal').length) {
                setTimeout(function() {
                    LCDModal.close();
                }, 3000);
            }
        },
        
        showError: function(message) {
            $('.lcd-optin-step').hide();
            $('#lcd-optin-loading').hide();
            $('#lcd-optin-error .error-message').text(message);
            $('#lcd-optin-error').show();
        },
        
        hideError: function() {
            $('#lcd-optin-error').hide();
            this.showStep('combined');
        },
        
        fireEmailConversionTracking: function() {
            var conversionValue = lcdOptinVars.tracking.conversion_value;
            
            // Fire Google Analytics conversion (email signup complete)
            this.fireGoogleEvent('conversion', {
                event_category: 'engagement',
                event_label: 'email_signup_complete',
                value: conversionValue
            });
            
            // Fire Google Ads conversion if configured
            if (lcdOptinVars.tracking.google_conversion_label) {
                this.fireGoogleAdsConversion(conversionValue);
            }
            
            // Fire Facebook conversion
            this.fireFacebookEvent('Lead', {
                content_name: 'Email Signup',
                content_category: 'Newsletter Signup',
                value: conversionValue
            });
        },
        
        fireSMSConversionTracking: function() {
            // Fire SMS-specific tracking events
            this.fireGoogleEvent('conversion', {
                event_category: 'engagement',
                event_label: 'sms_optin_added'
            });
            
            this.fireFacebookEvent('Subscribe', {
                content_name: 'SMS Opt-in',
                content_category: 'SMS Subscription'
            });
        },
        
        fireGoogleEvent: function(eventName, parameters) {
            if (!lcdOptinVars.tracking.google_gtag_id) return;
            
            if (typeof gtag === 'function') {
                gtag('event', eventName, parameters);
            } else if (typeof window.dataLayer !== 'undefined') {
                // Fallback for GTM
                window.dataLayer.push({
                    'event': 'lcd_optin_' + eventName,
                    'event_category': parameters.event_category || 'engagement',
                    'event_label': parameters.event_label || '',
                    'value': parameters.value || ''
                });
            }
        },
        
        fireGoogleAdsConversion: function(value) {
            if (!lcdOptinVars.tracking.google_gtag_id || !lcdOptinVars.tracking.google_conversion_label) return;
            
            if (typeof gtag === 'function') {
                var conversionConfig = {
                    'send_to': lcdOptinVars.tracking.google_gtag_id + '/' + lcdOptinVars.tracking.google_conversion_label
                };
                
                if (value && parseFloat(value) > 0) {
                    conversionConfig.value = parseFloat(value);
                    conversionConfig.currency = 'USD';
                }
                
                gtag('event', 'conversion', conversionConfig);
            }
        },
        
        fireFacebookEvent: function(eventName, parameters) {
            if (!lcdOptinVars.tracking.facebook_pixel_id) return;
            
            if (typeof fbq === 'function') {
                var eventData = {
                    content_name: parameters.content_name || '',
                    content_category: parameters.content_category || ''
                };
                
                if (parameters.value && parseFloat(parameters.value) > 0) {
                    eventData.value = parseFloat(parameters.value);
                    eventData.currency = 'USD';
                }
                
                fbq('track', eventName, eventData);
            }
        }
    };

    // Modal integration functionality
    function initModalIntegration() {
        if (typeof LCDModal !== 'undefined') {
            // Listen for modal triggers with high priority (capture phase)
            document.addEventListener('click', function(e) {
                if (e.target.getAttribute('data-modal') === 'optin-form' || 
                    e.target.closest('[data-modal="optin-form"]')) {
                    e.preventDefault();
                    e.stopPropagation(); // Prevent other modal handlers from firing
                    e.stopImmediatePropagation(); // Stop all other listeners on this element
                    
                    // Open modal with opt-in content
                    if (window.lcdOptinModalContent) {
                        LCDModal.open({
                            title: window.lcdOptinModalContent.title,
                            content: window.lcdOptinModalContent.content,
                            size: 'medium',
                            className: 'lcd-optin-modal-wrapper',
                            showCloseButton: true
                        });
                    }
                }
            }, true); // Use capture phase to run before other handlers
        } else {
            // Fallback: Handle modal triggers manually
            document.addEventListener('click', function(e) {
                if (e.target.getAttribute('data-modal') === 'optin-form') {
                    e.preventDefault();
                    alert('Modal system not available. Please use the shortcode [lcd_optin_form] instead.');
                }
            });
        }
    }

    // Initialize when document is ready
    $(document).ready(function() {
        lcdOptinForm.init();
        initModalIntegration();
        
        // Initialize combined form button state if present
        if ($('#lcd-optin-combined-form').length) {
            lcdOptinForm.updateCombinedButtonState();
        }
    });

})(jQuery);
