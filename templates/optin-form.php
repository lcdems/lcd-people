<div class="lcd-optin-container <?php echo esc_attr($container_class); ?>" id="lcd-optin-form">
    
    <!-- Combined Form: All fields in one step -->
    <div class="lcd-optin-step lcd-optin-step-combined active" id="lcd-optin-step-combined">
        <?php if (!empty($form_title)): ?>
        <div class="lcd-optin-header">
            <h3><?php echo esc_html($form_title); ?></h3>
        </div>
        <?php endif; ?>
        
        <form id="lcd-optin-combined-form" class="lcd-optin-form">
            <?php // Hidden fields for extra groups/tags from shortcode ?>
            <?php if (!empty($extra_sender_groups)): ?>
                <input type="hidden" name="extra_sender_groups" value="<?php echo esc_attr(implode(',', $extra_sender_groups)); ?>">
            <?php endif; ?>
            <?php if (!empty($extra_callhub_tags)): ?>
                <input type="hidden" name="extra_callhub_tags" value="<?php echo esc_attr(implode(',', $extra_callhub_tags)); ?>">
            <?php endif; ?>
            
            <div class="lcd-form-group">
                <label for="lcd-optin-first-name-combined"><?php _e('First Name', 'lcd-people'); ?></label>
                <input type="text" id="lcd-optin-first-name-combined" name="first_name">
            </div>
            
            <div class="lcd-form-group">
                <label for="lcd-optin-last-name-combined"><?php _e('Last Name', 'lcd-people'); ?></label>
                <input type="text" id="lcd-optin-last-name-combined" name="last_name">
            </div>
            
            <div class="lcd-form-group">
                <label for="lcd-optin-email-combined"><?php _e('Email Address', 'lcd-people'); ?> <span class="required">*</span></label>
                <input type="email" id="lcd-optin-email-combined" name="email" required>
            </div>
            
            <div class="lcd-form-group">
                <label for="lcd-optin-phone-combined"><?php _e('Phone Number (optional)', 'lcd-people'); ?></label>
                <input type="tel" id="lcd-optin-phone-combined" name="phone">
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
            
            <!-- SMS consent - only visible when phone number is entered -->
            <div class="lcd-form-group lcd-sms-consent-wrapper" id="lcd-sms-consent-wrapper-combined" style="display: none;">
                <label class="lcd-checkbox-label lcd-sms-consent">
                    <input type="checkbox" id="lcd-optin-sms-consent-combined" name="sms_consent" value="1">
                    <span class="checkmark"></span>
                    <span class="consent-text">
                        <?php echo wp_kses_post($settings['sms_disclaimer']); ?>
                    </span>
                </label>
            </div>
            
            <?php if (!empty($settings['main_disclaimer'])): ?>
                <div class="lcd-form-group">
                    <label class="lcd-checkbox-label lcd-main-disclaimer">
                        <input type="checkbox" id="lcd-optin-main-consent-combined" name="main_consent" value="1" required>
                        <span class="checkmark"></span>
                        <span class="consent-text">
                            <?php echo wp_kses_post($settings['main_disclaimer']); ?>
                        </span>
                    </label>
                </div>
            <?php endif; ?>
            
            <div class="lcd-form-actions">
                <button type="submit" class="lcd-btn lcd-btn-primary" id="lcd-combined-submit-btn" disabled>
                    <?php echo esc_html($form_cta); ?>
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
