<div class="lcd-optin-container <?php echo esc_attr($container_class); ?>" id="lcd-optin-form">
    <!-- Step 1: Email and Groups -->
    <div class="lcd-optin-step lcd-optin-step-email active" id="lcd-optin-step-email">
        <?php if (!$is_modal): ?>
        <div class="lcd-optin-header">
            <h3><?php echo esc_html($settings['email_title']); ?></h3>
        </div>
        <?php endif; ?>
        
        <form id="lcd-optin-email-form" class="lcd-optin-form">
            <div class="lcd-form-group">
                <label for="lcd-optin-first-name"><?php _e('First Name', 'lcd-people'); ?> <span class="required">*</span></label>
                <input type="text" id="lcd-optin-first-name" name="first_name" required>
            </div>
            
            <div class="lcd-form-group">
                <label for="lcd-optin-last-name"><?php _e('Last Name', 'lcd-people'); ?> <span class="required">*</span></label>
                <input type="text" id="lcd-optin-last-name" name="last_name" required>
            </div>
            
            <div class="lcd-form-group">
                <label for="lcd-optin-email"><?php _e('Email Address', 'lcd-people'); ?> <span class="required">*</span></label>
                <input type="email" id="lcd-optin-email" name="email" required>
            </div>
            
            <?php if (count($available_groups) > 1): ?>
                <div class="lcd-form-group">
                    <label><?php _e('I\'m interested in:', 'lcd-people'); ?></label>
                    <div class="lcd-checkbox-group">
                        <?php foreach ($available_groups as $group_id => $group_data): ?>
                            <label class="lcd-checkbox-label">
                                <input type="checkbox" name="groups[]" value="<?php echo esc_attr($group_id); ?>" 
                                       <?php checked(!empty($group_data['default'])); ?>>
                                <span class="checkmark"></span>
                                <?php echo esc_html($group_data['name']); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <!-- Single group - auto-select it -->
                <?php $group_id = array_key_first($available_groups); ?>
                <input type="hidden" name="groups[]" value="<?php echo esc_attr($group_id); ?>">
            <?php endif; ?>
            
            <?php if (!empty($settings['main_disclaimer'])): ?>
                <div class="lcd-form-disclaimer">
                    <p><?php echo wp_kses_post($settings['main_disclaimer']); ?></p>
                </div>
            <?php endif; ?>
            
            <div class="lcd-form-actions">
                <button type="submit" class="lcd-btn lcd-btn-primary">
                    <?php echo esc_html($settings['email_cta']); ?>
                </button>
            </div>
        </form>
    </div>
    
    <!-- Step 2: SMS Opt-in -->
    <div class="lcd-optin-step lcd-optin-step-sms" id="lcd-optin-step-sms" style="display: none;">
        <div class="lcd-optin-header">
            <h3><?php echo esc_html($settings['sms_title']); ?></h3>
            <p><?php _e('You\'re almost done! Would you like to receive text messages too?', 'lcd-people'); ?></p>
        </div>
        
        <form id="lcd-optin-sms-form" class="lcd-optin-form">
            <div class="lcd-form-group">
                <label for="lcd-optin-phone"><?php _e('Phone Number', 'lcd-people'); ?></label>
                <input type="tel" id="lcd-optin-phone" name="phone" placeholder="(555) 123-4567">
            </div>
            
            <div class="lcd-form-group">
                <label class="lcd-checkbox-label lcd-sms-consent">
                    <input type="checkbox" id="lcd-optin-sms-consent" name="sms_consent" value="1">
                    <span class="checkmark"></span>
                    <span class="consent-text">
                        <?php echo wp_kses_post($settings['sms_disclaimer']); ?>
                    </span>
                </label>
            </div>
            
            <div class="lcd-form-actions">
                <button type="submit" class="lcd-btn lcd-btn-primary" id="lcd-sms-optin-btn" disabled>
                    <?php echo esc_html($settings['sms_cta']); ?>
                </button>
                <button type="button" class="lcd-btn lcd-btn-secondary" id="lcd-skip-sms-btn">
                    <?php echo esc_html($settings['skip_sms_cta']); ?>
                </button>
            </div>
        </form>
    </div>
    
    <!-- Success Message -->
    <div class="lcd-optin-step lcd-optin-step-success" id="lcd-optin-step-success" style="display: none;">
        <div class="lcd-optin-header">
            <h3><?php _e('Thank You!', 'lcd-people'); ?></h3>
            <p id="lcd-success-message"><?php _e('You\'ve been successfully added to our list.', 'lcd-people'); ?></p>
        </div>
    </div>
    
    <!-- Loading State -->
    <div class="lcd-optin-loading" id="lcd-optin-loading" style="display: none;">
        <div class="lcd-spinner"></div>
        <p><?php _e('Processing...', 'lcd-people'); ?></p>
    </div>
    
    <!-- Error Messages -->
    <div class="lcd-optin-error" id="lcd-optin-error" style="display: none;">
        <p class="error-message"></p>
        <button type="button" class="lcd-btn lcd-btn-secondary" onclick="lcdOptinForm.hideError()">
            <?php _e('Try Again', 'lcd-people'); ?>
        </button>
    </div>
</div>

<style>
.lcd-optin-container {
    max-width: 500px;
    margin: 0 auto;
    padding: 20px;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}

.lcd-optin-modal {
    max-width: none;
    margin: 0;
    padding: 0;
}

/* Modal scrolling for short screens */
.lcd-optin-modal-wrapper .lcd-modal-content {
    max-height: 90vh;
    overflow-y: auto;
}

.lcd-optin-modal-wrapper .lcd-modal-body {
    max-height: calc(90vh - 120px); /* Account for header/footer */
    overflow-y: auto;
    padding: 20px;
}

.lcd-optin-header h3 {
    margin: 0 0 10px 0;
    font-size: 24px;
    color: #333;
}

.lcd-optin-header p {
    margin: 0 0 20px 0;
    color: #666;
}

.lcd-form-group {
    margin-bottom: 20px;
}

.lcd-form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
    color: #333;
}

.lcd-form-group input[type="text"],
.lcd-form-group input[type="email"],
.lcd-form-group input[type="tel"] {
    width: 100%;
    padding: 12px;
    border: 2px solid #ddd;
    border-radius: 6px;
    font-size: 16px;
    box-sizing: border-box;
    transition: border-color 0.3s;
}

.lcd-form-group input:focus {
    outline: none;
    border-color: #007cba;
}

.required {
    color: #d63638;
}

.lcd-checkbox-group {
    margin-top: 10px;
}

.lcd-checkbox-label {
    display: flex;
    align-items: flex-start;
    margin-bottom: 10px;
    cursor: pointer;
    font-weight: normal;
}

.lcd-checkbox-label input[type="checkbox"] {
    margin-right: 10px;
    margin-top: 2px;
}

.lcd-sms-consent {
    align-items: flex-start;
}

.consent-text {
    font-size: 14px;
    line-height: 1.4;
    color: #666;
}

.lcd-form-disclaimer {
    background: #f9f9f9;
    padding: 15px;
    border-radius: 6px;
    margin-bottom: 20px;
}

.lcd-form-disclaimer p {
    margin: 0;
    font-size: 14px;
    color: #666;
}

.lcd-form-actions {
    text-align: center;
    margin-top: 25px;
}

.lcd-btn {
    display: inline-block;
    padding: 12px 24px;
    border: none;
    border-radius: 6px;
    font-size: 16px;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.3s;
    margin: 0 5px;
}

.lcd-btn-primary {
    background: #007cba;
    color: white;
}

.lcd-btn-primary:hover:not(:disabled) {
    background: #005a87;
}

.lcd-btn-primary:disabled {
    background: #ccc;
    cursor: not-allowed;
}

.lcd-btn-secondary {
    background: #f0f0f1;
    color: #3c434a;
    border: 2px solid #dcdcde;
}

.lcd-btn-secondary:hover {
    background: #e9e9ea;
}

.lcd-optin-loading {
    text-align: center;
    padding: 40px 20px;
}

.lcd-spinner {
    width: 40px;
    height: 40px;
    border: 4px solid #f3f3f3;
    border-top: 4px solid #007cba;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin: 0 auto 20px;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.lcd-optin-error {
    text-align: center;
    padding: 20px;
    background: #f8d7da;
    border: 1px solid #f5c6cb;
    border-radius: 6px;
    color: #721c24;
}

.lcd-optin-error .error-message {
    margin: 0 0 15px 0;
    font-weight: 600;
}

/* Responsive design */
@media (max-width: 768px) {
    .lcd-optin-container {
        padding: 15px;
    }
    
    .lcd-optin-header h3 {
        font-size: 20px;
    }
    
    .lcd-btn {
        display: block;
        width: 100%;
        margin: 5px 0;
    }
    
    .lcd-form-actions {
        margin-top: 20px;
    }
}
</style> 