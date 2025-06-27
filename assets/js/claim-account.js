/**
 * LCD People - Claim Account JavaScript (Page-Based Experience)
 */

(function($) {
    'use strict';

    var lcdClaimAccount = {
        init: function() {
            this.bindEvents();
            this.handleTokenFromUrl();
        },

        bindEvents: function() {
            // Handle page-based email form submission
            $(document).on('submit', '#lcd-claim-email-form', this.handleEmailFormSubmit.bind(this));
            
            // Handle account creation form submission
            $(document).on('submit', '#lcd-create-account-form', this.handleCreateAccountSubmit.bind(this));
            
            // Password validation
            $(document).on('input', '#create-password', this.checkPasswordStrength.bind(this));
            $(document).on('input', '#confirm-password', this.validatePasswordMatch.bind(this));
        },

        handleTokenFromUrl: function() {
            // Check if we have a token in the URL (from email link)
            var urlParams = new URLSearchParams(window.location.search);
            var token = urlParams.get('token');
            
            if (token) {
                this.verifyTokenAndShowCreateForm(token);
            }
        },

        handleEmailFormSubmit: function(e) {
            e.preventDefault();
            
            var email = $('#claim-email').val().trim();
            if (!email || !this.isValidEmail(email)) {
                this.showError('Please enter a valid email address.');
                return;
            }

            this.showLoading('Sending verification email...');
            this.clearErrors();

            $.ajax({
                url: lcdClaimAccountVars.ajaxurl,
                type: 'POST',
                data: {
                    action: 'lcd_send_claim_verification_email',
                    email: email,
                    nonce: lcdClaimAccountVars.nonce
                },
                success: function(response) {
                    if (response.success) {
                        lcdClaimAccount.showEmailSentStep();
                    } else {
                        lcdClaimAccount.hideLoading();
                        lcdClaimAccount.showError(response.data.message || 'An error occurred while sending the verification email.');
                    }
                },
                error: function() {
                    lcdClaimAccount.hideLoading();
                    lcdClaimAccount.showError('Network error. Please try again.');
                }
            });
        },

        verifyTokenAndShowCreateForm: function(token) {
            this.showLoading('Verifying your link...');
            this.clearErrors();
            
            $.ajax({
                url: lcdClaimAccountVars.ajaxurl,
                type: 'POST',
                data: {
                    action: 'lcd_verify_claim_token',
                    token: token,
                    nonce: lcdClaimAccountVars.nonce
                },
                success: function(response) {
                    if (response.success) {
                        lcdClaimAccount.showCreateAccountStep(token, response.data.person);
                    } else {
                        lcdClaimAccount.hideLoading();
                        lcdClaimAccount.showError(response.data.message || 'Invalid or expired link. Please request a new verification email.');
                    }
                },
                error: function() {
                    lcdClaimAccount.hideLoading();
                    lcdClaimAccount.showError('Network error. Please try again.');
                }
            });
        },

        handleCreateAccountSubmit: function(e) {
            e.preventDefault();
            
            var password = $('#create-password').val();
            var confirmPassword = $('#confirm-password').val();
            var token = $('#claim-token').val();
            
            // Validate passwords
            if (password.length < 8) {
                this.showError('Password must be at least 8 characters long.');
                return;
            }
            
            if (password !== confirmPassword) {
                this.showError('Passwords do not match.');
                return;
            }
            
            if (!token) {
                this.showError('Invalid session. Please try again.');
                return;
            }

            this.showLoading('Creating your account...');
            this.clearErrors();

            $.ajax({
                url: lcdClaimAccountVars.ajaxurl,
                type: 'POST',
                data: {
                    action: 'lcd_claim_create_account_with_token',
                    token: token,
                    password: password,
                    nonce: lcdClaimAccountVars.nonce
                },
                success: function(response) {
                    if (response.success) {
                        lcdClaimAccount.showSuccessStep();
                        // Redirect after a short delay
                        setTimeout(function() {
                            window.location.href = lcdClaimAccountVars.dashboardUrl || '/';
                        }, 3000);
                    } else {
                        lcdClaimAccount.hideLoading();
                        lcdClaimAccount.showError(response.data.message || 'Failed to create account. Please try again.');
                    }
                },
                error: function() {
                    lcdClaimAccount.hideLoading();
                    lcdClaimAccount.showError('Network error. Please try again.');
                }
            });
        },

        // Step Navigation
        showEmailSentStep: function() {
            this.hideLoading();
            this.hideAllSteps();
            $('#step-email-sent').show();
        },

        showCreateAccountStep: function(token, person) {
            this.hideLoading();
            this.hideAllSteps();
            
            // Populate person information
            this.populatePersonInfo(person);
            
            // Set the token
            $('#claim-token').val(token);
            
            // Show the step
            $('#step-create-account').show();
            
            // Focus on password field
            setTimeout(function() {
                $('#create-password').focus();
            }, 100);
        },

        showSuccessStep: function() {
            this.hideLoading();
            this.hideAllSteps();
            $('#step-success').show();
        },

        showEmailForm: function() {
            this.hideAllSteps();
            this.clearErrors();
            $('#step-email-verification').show();
            $('#claim-email').focus();
        },

        hideAllSteps: function() {
            $('.lcd-claim-step').hide();
        },

        populatePersonInfo: function(person) {
            var html = '<div class="person-info-display">';
            html += '<h4>Confirm Your Information</h4>';
            html += '<div class="info-grid">';
            html += '<div class="info-item"><span class="info-label">Name:</span> <span class="info-value">' + this.escapeHtml(person.name) + '</span></div>';
            html += '<div class="info-item"><span class="info-label">Email:</span> <span class="info-value">' + this.escapeHtml(person.email) + '</span></div>';
            
            if (person.phone) {
                html += '<div class="info-item"><span class="info-label">Phone:</span> <span class="info-value">' + this.escapeHtml(person.phone) + '</span></div>';
            }
            
            if (person.membership_status) {
                html += '<div class="info-item"><span class="info-label">Membership Status:</span> <span class="info-value">' + this.escapeHtml(person.membership_status) + '</span></div>';
            }
            
            html += '</div></div>';
            
            $('#person-info-display').html(html);
        },

        // Password Validation
        checkPasswordStrength: function() {
            var password = $('#create-password').val();
            var $strengthIndicator = $('.password-strength');
            
            // Create strength indicator if it doesn't exist
            if ($strengthIndicator.length === 0) {
                $('#create-password').after('<div class="password-strength"><div class="strength-bar"></div><div class="strength-text"></div></div>');
                $strengthIndicator = $('.password-strength');
            }
            
            var $strengthBar = $strengthIndicator.find('.strength-bar');
            var $strengthText = $strengthIndicator.find('.strength-text');
            
            if (password.length === 0) {
                $strengthIndicator.hide();
                return;
            }
            
            $strengthIndicator.show();
            
            var strength = this.calculatePasswordStrength(password);
            
            // Reset classes
            $strengthBar.removeClass('weak fair good strong');
            $strengthText.removeClass('weak fair good strong');
            
            // Apply new strength class
            var strengthClass = strength.level;
            $strengthBar.addClass(strengthClass);
            $strengthText.addClass(strengthClass).text(strength.text);
        },

        calculatePasswordStrength: function(password) {
            var score = 0;
            
            if (password.length >= 8) score++;
            if (password.length >= 12) score++;
            if (/[a-z]/.test(password)) score++;
            if (/[A-Z]/.test(password)) score++;
            if (/[0-9]/.test(password)) score++;
            if (/[^A-Za-z0-9]/.test(password)) score++;
            
            if (score < 2) {
                return { level: 'weak', text: 'Weak' };
            } else if (score < 4) {
                return { level: 'fair', text: 'Fair' };
            } else if (score < 5) {
                return { level: 'good', text: 'Good' };
            } else {
                return { level: 'strong', text: 'Strong' };
            }
        },

        validatePasswordMatch: function() {
            var password = $('#create-password').val();
            var confirmPassword = $('#confirm-password').val();
            var $confirmField = $('#confirm-password');
            var $matchIndicator = $('.password-match-indicator');
            
            if (confirmPassword.length === 0) {
                $matchIndicator.remove();
                $confirmField.removeClass('password-match password-mismatch');
                return;
            }
            
            // Create match indicator if it doesn't exist
            if ($matchIndicator.length === 0) {
                $confirmField.after('<div class="password-match-indicator"></div>');
                $matchIndicator = $('.password-match-indicator');
            }
            
            if (password === confirmPassword) {
                $confirmField.removeClass('password-mismatch').addClass('password-match');
                $matchIndicator.removeClass('mismatch').addClass('match').text('✓ Passwords match');
            } else {
                $confirmField.removeClass('password-match').addClass('password-mismatch');
                $matchIndicator.removeClass('match').addClass('mismatch').text('✗ Passwords do not match');
            }
        },

        // Utility Methods
        showLoading: function(message = 'Loading...') {
            $('#lcd-claim-loading p').text(message);
            $('#lcd-claim-loading').show();
        },

        hideLoading: function() {
            $('#lcd-claim-loading').hide();
        },

        showError: function(message) {
            $('#lcd-claim-errors .error-content').text(message);
            $('#lcd-claim-errors').show();
            
            // Scroll to error if needed
            $('html, body').animate({
                scrollTop: $('#lcd-claim-errors').offset().top - 100
            }, 300);
        },

        clearErrors: function() {
            $('#lcd-claim-errors').hide();
        },

        isValidEmail: function(email) {
            var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return emailRegex.test(email);
        },

        escapeHtml: function(text) {
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }
    };

    // Initialize when DOM is ready
    $(document).ready(function() {
        lcdClaimAccount.init();
        
        // Make available globally for template callbacks
        window.lcdClaimAccount = lcdClaimAccount;
    });

})(jQuery); 