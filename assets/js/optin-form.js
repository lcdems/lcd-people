/**
 * LCD People Opt-in Form JavaScript
 * 
 * Handles opt-in form functionality and modal integration
 */

(function($) {
    'use strict';

    // Main opt-in form functionality
    window.lcdOptinForm = {
        currentStep: 'email',
        sessionKey: null,
        
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
            
            // Email form submission
            $(document).on('submit', '#lcd-optin-email-form', function(e) {
                e.preventDefault();
                self.submitEmailStep();
            });
            
            // SMS form submission
            $(document).on('submit', '#lcd-optin-sms-form', function(e) {
                e.preventDefault();
                self.submitFinalStep(true);
            });
            
            // Skip SMS button
            $(document).on('click', '#lcd-skip-sms-btn', function(e) {
                e.preventDefault();
                self.submitFinalStep(false);
            });
            
            // SMS consent checkbox
            $(document).on('change', '#lcd-optin-sms-consent', function() {
                this.updateSMSButtonState();
            }.bind(this));
            
            // Main consent checkbox on SMS step
            $(document).on('change', '#lcd-optin-main-consent-sms', function() {
                this.updateSMSButtonState();
            }.bind(this));
        },
        
        updateSMSButtonState: function() {
            var smsConsentGiven = $('#lcd-optin-sms-consent').is(':checked');
            var mainConsentGiven = $('#lcd-optin-main-consent-sms').is(':checked');
            var phoneField = $('#lcd-optin-phone');
            var submitBtn = $('#lcd-sms-optin-btn');
            
            if (smsConsentGiven && mainConsentGiven) {
                phoneField.prop('required', true);
                submitBtn.prop('disabled', false);
            } else {
                phoneField.prop('required', false);
                submitBtn.prop('disabled', true);
            }
        },
        
        submitEmailStep: function() {
            // Validate main consent checkbox if present
            var mainConsentCheckbox = $('#lcd-optin-main-consent');
            if (mainConsentCheckbox.length && !mainConsentCheckbox.is(':checked')) {
                this.showError(lcdOptinVars.strings.required_consent || 'Please accept the terms and conditions.');
                return;
            }
            
            var formData = {
                action: 'lcd_optin_submit_email',
                nonce: lcdOptinVars.nonce,
                first_name: $('#lcd-optin-first-name').val(),
                last_name: $('#lcd-optin-last-name').val(),
                email: $('#lcd-optin-email').val(),
                groups: [],
                main_consent: mainConsentCheckbox.is(':checked') ? 1 : 0
            };
            
            // Get selected groups
            $('input[name="groups[]"]:checked').each(function() {
                formData.groups.push($(this).val());
            });
            
            // Auto-select the first group if none are selected but groups are available
            if (formData.groups.length === 0) {
                var firstGroup = $('input[name="groups[]"]').first();
                if (firstGroup.length) {
                    firstGroup.prop('checked', true);
                    formData.groups.push(firstGroup.val());
                }
            }
            // Note: Empty groups array is OK - form will use auto-add groups from settings
            
            this.showLoading();
            
            var self = this;
            $.post(lcdOptinVars.ajaxurl, formData)
                .done(function(response) {
                    if (response.success) {
                        self.sessionKey = response.data.session_key;
                        // Fire email conversion tracking since user is now signed up
                        self.fireEmailConversionTracking();
                        self.showStep('sms');
                    } else {
                        self.showError(response.data.message || lcdOptinVars.strings.error);
                    }
                })
                .fail(function() {
                    self.showError(lcdOptinVars.strings.error);
                });
        },
        
        submitFinalStep: function(includeSMS) {
            // Validate main consent checkbox on SMS step
            var mainConsentCheckbox = $('#lcd-optin-main-consent-sms');
            if (mainConsentCheckbox.length && !mainConsentCheckbox.is(':checked')) {
                this.showError(lcdOptinVars.strings.required_consent || 'Please accept the terms and conditions.');
                return;
            }
            
            var formData = {
                action: 'lcd_optin_submit_final',
                nonce: lcdOptinVars.nonce,
                session_key: this.sessionKey,
                main_consent: mainConsentCheckbox.is(':checked') ? 1 : 0
            };
            
            if (includeSMS) {
                formData.phone = $('#lcd-optin-phone').val();
                formData.sms_consent = $('#lcd-optin-sms-consent').is(':checked') ? 1 : 0;
            }
            
            this.showLoading();
            
            var self = this;
            $.post(lcdOptinVars.ajaxurl, formData)
                .done(function(response) {
                    if (response.success) {
                        // Only fire SMS tracking if user opted in for SMS
                        if (includeSMS) {
                            self.fireSMSConversionTracking();
                        }
                        self.showSuccess(response.data.message);
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
            this.currentStep = step;
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
            this.showStep(this.currentStep);
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
            this.fireFacebookEvent('CompleteRegistration', {
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
        
        fireFinalConversionTracking: function(includedSMS) {
            var conversionValue = lcdOptinVars.tracking.conversion_value;
            var eventLabel = includedSMS ? 'email_sms_conversion' : 'email_only_conversion';
            var contentName = includedSMS ? 'Email + SMS Signup' : 'Email Signup';
            
            // Fire Google Analytics conversion
            this.fireGoogleEvent('conversion', {
                event_category: 'engagement',
                event_label: eventLabel,
                value: conversionValue
            });
            
            // Fire Google Ads conversion if configured
            if (lcdOptinVars.tracking.google_conversion_label) {
                this.fireGoogleAdsConversion(conversionValue);
            }
            
            // Fire Facebook conversion
            this.fireFacebookEvent('CompleteRegistration', {
                content_name: contentName,
                content_category: 'Newsletter Signup',
                value: conversionValue
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
    });

})(jQuery); 