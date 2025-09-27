<?php
/**
 * Plugin Name: Google Calendar Events Manager
 * Description: Imports and displays events from Google Calendar
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL-2.0+
 * Text Domain: gcal-events
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Define plugin constants
define('GCAL_EVENTS_VERSION', '1.0.0');
define('GCAL_EVENTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GCAL_EVENTS_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once GCAL_EVENTS_PLUGIN_DIR . 'includes/class-gcal-db.php';
require_once GCAL_EVENTS_PLUGIN_DIR . 'includes/class-gcal-importer.php';
require_once GCAL_EVENTS_PLUGIN_DIR . 'includes/class-gcal-display.php';
require_once GCAL_EVENTS_PLUGIN_DIR . 'includes/class-gcal-settings.php';

// Activation hook
register_activation_hook(__FILE__, ['GCAL_DB', 'create_events_table']);

// Initialize the plugin
function gcal_init() {
    $db = new GCAL_DB();
    $importer = new GCAL_Importer($db);
    $display = new GCAL_Display($db);
    $settings = new GCAL_Settings();
    
    // Schedule daily import if not already scheduled
    if (!wp_next_scheduled('gcal_daily_import')) {
        wp_schedule_event(time(), 'daily', 'gcal_daily_import');
    }
    add_action('gcal_daily_import', [$importer, 'import_events']);
    
    // Add manual import action
    add_action('wp_ajax_gcal_manual_import', [$importer, 'manual_import']);
}
add_action('plugins_loaded', 'gcal_init');

// Deactivation hook
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('gcal_daily_import');
});

// Add settings link on plugin page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $settings_link = '<a href="' . admin_url('options-general.php?page=gcal-settings') . '">' . __('Settings') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});
