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
            <?php elseif (count($available_groups) === 1): ?>
                <!-- Single group - auto-select it -->
                <?php $group_id = array_key_first($available_groups); ?>
                <input type="hidden" name="groups[]" value="<?php echo esc_attr($group_id); ?>">
            <?php endif; ?>
            <?php // Note: If no available_groups, form will use auto-add groups from settings ?>
            
            <?php if (!empty($settings['main_disclaimer'])): ?>
                <div class="lcd-form-group">
                    <label class="lcd-checkbox-label lcd-main-disclaimer">
                        <input type="checkbox" id="lcd-optin-main-consent" name="main_consent" value="1" required>
                        <span class="checkmark"></span>
                        <span class="consent-text">
                            <?php echo wp_kses_post($settings['main_disclaimer']); ?>
                        </span>
                    </label>
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
            <p><?php _e('You\'re signed up for email updates! Would you like to receive text messages too?', 'lcd-people'); ?></p>
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
            
            <?php if (!empty($settings['main_disclaimer'])): ?>
                <div class="lcd-form-group">
                    <label class="lcd-checkbox-label lcd-main-disclaimer">
                        <input type="checkbox" id="lcd-optin-main-consent-sms" name="main_consent_sms" value="1" required>
                        <span class="checkmark"></span>
                        <span class="consent-text">
                            <?php echo wp_kses_post($settings['main_disclaimer']); ?>
                        </span>
                    </label>
                </div>
            <?php endif; ?>
            
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