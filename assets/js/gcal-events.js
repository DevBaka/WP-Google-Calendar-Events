(function($) {
    'use strict';

    // Document ready
    $(document).ready(function() {
        // Initialize events
        initEvents();
    });

    /**
     * Initialize event handlers
     */
    function initEvents() {
        // Toggle event details for default theme
        $(document).on('click', '.theme-default .event, .gcal-event', function(e) {
            e.preventDefault();
            
            const $event = $(this);
            const $details = $event.hasClass('event') ? $event.next('.event-details') : $event.next('.gcal-event-details');
            
            // Toggle active class
            $event.toggleClass('active');
            
            // Toggle details
            if ($details.length) {
                $details.slideToggle(200);
            }
        });
        
        // Handle external links
        $(document).on('click', '.event a, .gcal-event a', function(e) {
            e.stopPropagation();
        });
        
        // Handle filter form submission
        $(document).on('submit', '.gcal-filter-form', function(e) {
            e.preventDefault();
            const $form = $(this);
            const $container = $form.closest('.gcal-events-container, .events-container');
            
            // Show loading
            $container.addClass('loading');
            
            // Get form data
            const formData = $form.serialize();
            
            // Send AJAX request
            $.ajax({
                url: gcalEvents.ajaxurl,
                type: 'POST',
                data: formData + '&action=gcal_filter_events',
                dataType: 'json',
                success: function(response) {
                    if (response.success && response.data && response.data.html) {
                        // Find the events container and update it
                        const $eventsContainer = $container.find('.event-list, .gcal-events-list');
                        $eventsContainer.html(response.data.html);
                    }
                },
                complete: function() {
                    $container.removeClass('loading');
                }
            });
        });
    }

    /**
     * Load events via AJAX
     */
    function loadEvents() {
        const $container = $('.gcal-events-container');
        
        // Show loading state
        $container.html('<div class="gcal-loading">Lade Termine...</div>');
        
        // AJAX request
        $.ajax({
            url: gcalEvents.ajaxurl,
            type: 'GET',
            data: {
                action: 'gcal_get_events',
                nonce: gcalEvents.nonce
            },
            dataType: 'json'
        })
        .done(function(response) {
            if (response.success && response.data && response.data.html) {
                $container.html(response.data.html);
            } else {
                showError('Fehler beim Laden der Termine.');
            }
        })
        .fail(function() {
            showError('Verbindungsfehler. Bitte versuchen Sie es später erneut.');
        });
    }

    /**
     * Show error message
     */
    function showError(message) {
        const $container = $('.gcal-events-container');
        $container.html(`
            <div class="gcal-error">
                <strong>Fehler:</strong> ${message}
            </div>
        `);
    }

    /**
     * Format date string
     */
    function formatDate(dateString) {
        const options = { 
            year: 'numeric', 
            month: 'long', 
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        };
        return new Date(dateString).toLocaleDateString('de-DE', options);
    }

})(jQuery);
