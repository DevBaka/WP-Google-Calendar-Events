(function($) {
    'use strict';

    // Document ready
    $(document).ready(function() {
        // Initialize tabs
        initTabs();
        
        // Initialize date/time format toggles
        initFormatToggles();
        
        // Handle manual import
        $('#gcal-manual-import').on('click', handleManualImport);
    });

    /**
     * Initialize tab functionality
     */
    function initTabs() {
        // Show the first tab by default
        $('.gcal-tab-content').hide().first().show();
        $('.gcal-tab').first().addClass('gcal-tab-active');
        
        // Handle tab clicks
        $('.gcal-tab').on('click', function(e) {
            e.preventDefault();
            
            const tabId = $(this).data('tab');
            
            // Update active tab
            $('.gcal-tab').removeClass('gcal-tab-active');
            $(this).addClass('gcal-tab-active');
            
            // Show corresponding content
            $('.gcal-tab-content').hide();
            $(`#${tabId}`).show();
            
            // Update URL hash
            window.location.hash = tabId;
        });
        
        // Check for hash in URL
        if (window.location.hash) {
            const hash = window.location.hash.substring(1);
            $(`.gcal-tab[data-tab="${hash}"]`).trigger('click');
        }
    }

    /**
     * Initialize date/time format toggles
     */
    function initFormatToggles() {
        // Date format toggle
        $('input[name^="gcal_settings[date_format]"]').on('change', function() {
            const isCustom = $(this).val() === 'custom';
            $('#custom_date_format').toggle(isCustom);
            
            if (!isCustom) {
                $('input[name="gcal_settings[date_format]"][value="' + $(this).val() + '"]')
                    .not(this)
                    .prop('checked', true);
            }
        });
        
        // Time format toggle
        $('input[name^="gcal_settings[time_format]"]').on('change', function() {
            const isCustom = $(this).val() === 'custom';
            $('#custom_time_format').toggle(isCustom);
            
            if (!isCustom) {
                $('input[name="gcal_settings[time_format]"][value="' + $(this).val() + '"]')
                    .not(this)
                    .prop('checked', true);
            }
        });
    }

    /**
     * Handle manual import button click
     */
    function handleManualImport(e) {
        e.preventDefault();
        
        const $button = $(this);
        const $status = $('#gcal-import-status');
        
        // Disable button and show loading state
        $button.prop('disabled', true);
        $status.removeClass('success error').text(gcalAdmin.i18n.importing);
        
        // Make AJAX request
        $.ajax({
            url: gcalAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'gcal_manual_import',
                nonce: gcalAdmin.nonce
            },
            dataType: 'json'
        })
        .done(function(response) {
            if (response.success) {
                $status.addClass('success').text(gcalAdmin.i18n.imported);
                
                // Reload logs
                $.get(ajaxurl, {
                    action: 'gcal_get_import_logs',
                    nonce: gcalAdmin.nonce
                }, function(logs) {
                    if (logs.success) {
                        $('#gcal-import-logs').html(logs.data.html);
                    }
                });
            } else {
                $status.addClass('error').text(response.data || gcalAdmin.i18n.error);
            }
        })
        .fail(function() {
            $status.addClass('error').text(gcalAdmin.i18n.error);
        })
        .always(function() {
            // Re-enable button after 2 seconds
            setTimeout(function() {
                $button.prop('disabled', false);
            }, 2000);
        });
    }

    /**
     * Show a notification message
     */
    function showNotice(message, type = 'success') {
        const $notice = $(`
            <div class="notice notice-${type} is-dismissible">
                <p>${message}</p>
                <button type="button" class="notice-dismiss">
                    <span class="screen-reader-text">Dismiss this notice.</span>
                </button>
            </div>
        `);
        
        $('.wrap > h1').after($notice);
        
        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            $notice.fadeOut('slow', function() {
                $(this).remove();
            });
        }, 5000);
        
        // Handle dismiss button
        $notice.on('click', '.notice-dismiss', function() {
            $notice.fadeOut('slow', function() {
                $(this).remove();
            });
        });
    }

})(jQuery);
