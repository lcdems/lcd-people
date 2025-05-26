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

    // Initialize when document is ready
    $(document).ready(function() {
        initMemberProfile();
        restoreVolunteerInterestsState();
    });

})(jQuery); 