jQuery(document).ready(function($) {
    $('.submission-toggle').on('click', function() {
        var target = $(this).data('target');
        var details = $('#' + target);
        var button = $(this);
        var textSpan = button.find('.button-text');
        
        if (details.is(':visible')) {
            details.slideUp();
            button.removeClass('expanded');
            if (textSpan.length) {
                textSpan.text(lcdPeopleVolunteering.viewText);
            }
        } else {
            details.slideDown();
            button.addClass('expanded');
            if (textSpan.length) {
                textSpan.text(lcdPeopleVolunteering.hideText);
            }
        }
    });
}); 