<?php
/**
 * Template Name: Claim Account
 * 
 * Page template for allowing people to claim/activate accounts
 * if they exist in the People database
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header(); ?>

<div class="lcd-claim-account-page">
    <div class="container">
        <?php while (have_posts()) : the_post(); ?>
            <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                <header class="entry-header">
                    <h1 class="entry-title"><?php the_title(); ?></h1>
                </header>

                <div class="entry-content">
                    <?php the_content(); ?>
                    
                    <div class="lcd-claim-account-form-container">
                        <!-- Step 1: Email Verification -->
                        <div id="step-email-verification" class="lcd-claim-step active">
                            <h3><?php _e('Request Account Access', 'lcd-people'); ?></h3>
                            <p><?php _e('Enter your email address and we\'ll send you instructions for accessing your account.', 'lcd-people'); ?></p>
                            
                            <form id="lcd-claim-email-form" class="lcd-claim-form">
                                <div class="form-group">
                                    <label for="claim-email"><?php _e('Email Address', 'lcd-people'); ?></label>
                                    <input type="email" id="claim-email" name="email" required 
                                           placeholder="<?php esc_attr_e('your@email.com', 'lcd-people'); ?>">
                                    <small class="form-help"><?php _e('We\'ll send you an email with next steps based on your account status.', 'lcd-people'); ?></small>
                                </div>
                                <div class="form-actions">
                                    <button type="submit" class="button button-primary">
                                        <?php _e('Send Verification Email', 'lcd-people'); ?>
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Step 2: Email Sent Confirmation -->
                        <div id="step-email-sent" class="lcd-claim-step" style="display: none;">
                            <div class="lcd-success-message">
                                <h3><?php _e('Email Sent!', 'lcd-people'); ?></h3>
                                <p><?php _e('We\'ve sent instructions to your email address. Please check your inbox (and spam folder) for next steps.', 'lcd-people'); ?></p>
                                <div class="form-actions">
                                    <button type="button" class="button button-secondary" onclick="lcdClaimAccount.showEmailForm()">
                                        <?php _e('Try Different Email', 'lcd-people'); ?>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Step 3: Create Account (Token-based) -->
                        <div id="step-create-account" class="lcd-claim-step" style="display: none;">
                            <h3><?php _e('Create Your Account', 'lcd-people'); ?></h3>
                            <p><?php _e('Please confirm your information and create a password for your account.', 'lcd-people'); ?></p>
                            
                            <form id="lcd-create-account-form" class="lcd-claim-form">
                                <div id="person-info-display">
                                    <!-- Person info will be populated by JavaScript -->
                                </div>
                                
                                <div class="form-group">
                                    <label for="create-password"><?php _e('Create Password', 'lcd-people'); ?></label>
                                    <input type="password" id="create-password" name="password" required 
                                           minlength="8"
                                           placeholder="<?php esc_attr_e('Enter a secure password', 'lcd-people'); ?>">
                                    <small class="form-help"><?php _e('Password must be at least 8 characters long.', 'lcd-people'); ?></small>
                                </div>
                                
                                <div class="form-group">
                                    <label for="confirm-password"><?php _e('Confirm Password', 'lcd-people'); ?></label>
                                    <input type="password" id="confirm-password" name="confirm_password" required 
                                           placeholder="<?php esc_attr_e('Confirm your password', 'lcd-people'); ?>">
                                </div>
                                
                                <div class="form-actions">
                                    <button type="submit" class="button button-primary">
                                        <?php _e('Create Account', 'lcd-people'); ?>
                                    </button>
                                </div>
                                
                                <input type="hidden" id="claim-token" name="token" value="">
                            </form>
                        </div>

                        <!-- Step 4: Success -->
                        <div id="step-success" class="lcd-claim-step" style="display: none;">
                            <div class="lcd-success-message">
                                <h3><?php _e('Account Created Successfully!', 'lcd-people'); ?></h3>
                                <p><?php _e('Your account has been created and you are now logged in.', 'lcd-people'); ?></p>
                                <p><?php _e('Redirecting to your member dashboard...', 'lcd-people'); ?></p>
                                <div class="form-actions">
                                    <a href="<?php echo esc_url(home_url('/member-dashboard')); ?>" class="button button-primary">
                                        <?php _e('Go to Dashboard', 'lcd-people'); ?>
                                    </a>
                                    <a href="<?php echo esc_url(home_url()); ?>" class="button button-secondary">
                                        <?php _e('Go to Homepage', 'lcd-people'); ?>
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Loading Spinner -->
                        <div id="lcd-claim-loading" class="lcd-loading" style="display: none;">
                            <div class="spinner"></div>
                            <p><?php _e('Processing...', 'lcd-people'); ?></p>
                        </div>

                        <!-- Error Messages -->
                        <div id="lcd-claim-errors" class="lcd-error-message" style="display: none;">
                            <div class="error-content"></div>
                        </div>
                    </div>
                </div>
            </article>
        <?php endwhile; ?>
    </div>
</div>

<?php get_footer(); ?> 