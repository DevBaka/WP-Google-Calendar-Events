(function($) {
    'use strict';

    // Document ready
    $(document).ready(function() {
        // Initialize events
        initEvents();
        
        // Handle AJAX loading if needed
        if (typeof gcalEvents !== 'undefined' && gcalEvents.ajaxurl) {
            loadEvents();
        }
    });

    /**
     * Initialize event handlers
     */
    function initEvents() {
        // Toggle event details
        $(document).on('click', '.gcal-event', function(e) {
            e.preventDefault();
            
            const $event = $(this);
            const $details = $event.next('.gcal-event-details');
            
            // Toggle active class
            $event.toggleClass('active');
            
            // Toggle details
            if ($details.length) {
                $details.slideToggle(200);
            }
        });
        
        // Handle external links
        $(document).on('click', '.gcal-event a', function(e) {
            e.stopPropagation();
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
