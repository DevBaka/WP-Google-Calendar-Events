<?php
/**
 * Plugin Name: Google Calendar Events Manager
 * Description: Imports and displays events from Google Calendar
 * Version: 1.0.0
 * Author: DevBaka
 * License: GPL-2.0+
 * Text Domain: gcal-events
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('GCAL_VERSION', '1.0.0');
define('GCAL_PLUGIN_FILE', __FILE__);
define('GCAL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GCAL_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once GCAL_PLUGIN_DIR . 'includes/class-gcal-install.php';
require_once GCAL_PLUGIN_DIR . 'includes/class-gcal-db.php';
require_once GCAL_PLUGIN_DIR . 'includes/class-gcal-importer.php';
require_once GCAL_PLUGIN_DIR . 'includes/class-gcal-display.php';
require_once GCAL_PLUGIN_DIR . 'includes/class-gcal-settings.php';

// Enqueue frontend styles
function gcal_enqueue_styles() {
    $options = get_option('gcal_settings', []);
    $theme = $options['theme'] ?? 'default';
    
    // Enqueue base styles
    wp_enqueue_style(
        'gcal-events-style',
        GCAL_PLUGIN_URL . 'assets/css/gcal-events.css',
        [],
        GCAL_VERSION
    );
    
    // Enqueue theme specific styles if not default
    if ($theme !== 'default') {
        wp_enqueue_style(
            'gcal-events-theme-' . $theme,
            GCAL_PLUGIN_URL . 'assets/css/gcal-events-theme-' . $theme . '.css',
            ['gcal-events-style'],
            GCAL_VERSION
        );
    }
    
    // Add inline style for theme class
    $custom_css = ".gcal-events-container { --gcal-theme: {$theme}; }";
    wp_add_inline_style('gcal-events-style', $custom_css);
}
add_action('wp_enqueue_scripts', 'gcal_enqueue_styles');

// Initialize the plugin
function gcal_init() {
    // Only initialize if we're in the admin or on the frontend
    if (!is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
        $db = new GCAL_DB();
        $importer = new GCAL_Importer($db);
        $display = new GCAL_Display($db);
        
        // Schedule daily import if not already scheduled
        if (!wp_next_scheduled('gcal_daily_import')) {
            wp_schedule_event(time(), 'daily', 'gcal_daily_import');
        }
        
        add_action('gcal_daily_import', [$importer, 'import_events']);
        add_action('wp_ajax_gcal_manual_import', [$importer, 'manual_import']);
    }
    
    // Always initialize settings
    new GCAL_Settings();
}
add_action('plugins_loaded', 'gcal_init');

// Add settings link on plugin page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $settings_link = '<a href="' . admin_url('options-general.php?page=gcal-settings') . '">' . __('Settings', 'gcal-events') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});
