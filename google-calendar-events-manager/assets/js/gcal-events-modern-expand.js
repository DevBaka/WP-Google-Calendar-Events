/**
 * Modern Expand Theme JavaScript for Google Calendar Events
 */
(function($) {
    'use strict';

    $(document).ready(function($) {
        'use strict';
        
        console.log('Modern Expand JS initialized');
        
        // Handle click on event items
        $(document).on('click', '.theme-modern-expand .event', function(e) {
            e.preventDefault();
            
            var $event = $(this);
            var $eventItem = $event.closest('.event-item');
            
            // Toggle current item
            $eventItem.toggleClass('active');
            
            // Close other open items
            $('.event-item').not($eventItem).removeClass('active');
            
            // Smooth scroll to the clicked event if opening
            if ($eventItem.hasClass('active')) {
                $('html, body').stop().animate({
                    scrollTop: $event.offset().top - 20
                }, 300);
            }
        });
        
        // Close details when clicking outside
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.event-item').length) {
                $('.event-item').removeClass('active');
            }
        });
        
        // Prevent event propagation when clicking inside details
        $(document).on('click', '.theme-modern-expand .event-details', function(e) {
            e.stopPropagation();
        });
    });

})(jQuery);
