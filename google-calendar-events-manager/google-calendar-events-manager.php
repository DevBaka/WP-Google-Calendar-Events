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

// Enqueue frontend styles
function gcal_enqueue_styles() {
    $options = get_option('gcal_settings', []);
    $theme = $options['theme'] ?? 'default';
    
    // Enqueue base styles
    wp_enqueue_style(
        'gcal-events-style',
        GCAL_EVENTS_PLUGIN_URL . 'assets/css/gcal-events.css',
        [],
        GCAL_EVENTS_VERSION
    );
    
    // Enqueue theme specific styles if not default
    if ($theme !== 'default') {
        wp_enqueue_style(
            'gcal-events-theme-' . $theme,
            GCAL_EVENTS_PLUGIN_URL . 'assets/css/gcal-events-theme-' . $theme . '.css',
            ['gcal-events-style'],
            GCAL_EVENTS_VERSION
        );
    }
    
    // Add inline style for theme class
    $custom_css = ".gcal-events-container { --gcal-theme: {$theme}; }";
    wp_add_inline_style('gcal-events-style', $custom_css);
}
add_action('wp_enqueue_scripts', 'gcal_enqueue_styles');

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

// Deactivation hook - clean up scheduled events and drop the table
register_deactivation_hook(__FILE__, 'gcal_deactivate_plugin');

// Activation hook - create the table
register_activation_hook(__FILE__, 'gcal_activate_plugin');

/**
 * Plugin activation function
 */
function gcal_activate_plugin() {
    // Include the database class
    require_once GCAL_EVENTS_PLUGIN_DIR . 'includes/class-gcal-db.php';
    
    // Create the database table
    $db = new GCAL_DB();
    $db->create_tables();
    
    // Schedule the daily import
    if (!wp_next_scheduled('gcal_daily_import')) {
        wp_schedule_event(time(), 'daily', 'gcal_daily_import');
    }
}

/**
 * Plugin deactivation function
 */
function gcal_deactivate_plugin() {
    // Debug log
    error_log('Google Calendar Events Manager: Starting deactivation');
    
    // Clear scheduled events
    wp_clear_scheduled_hook('gcal_daily_import');
    
    // Check if cleanup on deactivation is enabled
    $options = get_option('gcal_settings', []);
    error_log('Google Calendar Events Manager: Current settings: ' . print_r($options, true));
    
    if (isset($options['cleanup_on_deactivate']) && $options['cleanup_on_deactivate']) {
        error_log('Google Calendar Events Manager: Cleanup is enabled, dropping table');
        
        // Drop the database table with multiple methods
        global $wpdb;
        $table_name = $wpdb->prefix . 'gcal_events';
        
        // Method 1: Standard DROP TABLE
        $result1 = $wpdb->query("DROP TABLE IF EXISTS $table_name");
        error_log('Google Calendar Events Manager: DROP TABLE result: ' . ($result1 === false ? 'failed' : 'succeeded'));
        
        // Method 2: Direct query with error suppression
        $result2 = $wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS %s", $table_name));
        error_log('Google Calendar Events Manager: DROP TABLE (prepared) result: ' . ($result2 === false ? 'failed' : 'succeeded'));
        
        // Verify table doesn't exist
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        error_log('Google Calendar Events Manager: Table still exists after drop: ' . ($table_exists ? 'yes' : 'no'));
        
        if ($table_exists) {
            // Force drop with additional parameters
            $result3 = $wpdb->query("SET FOREIGN_KEY_CHECKS=0; DROP TABLE IF EXISTS $table_name; SET FOREIGN_KEY_CHECKS=1;");
            error_log('Google Calendar Events Manager: FORCE DROP TABLE result: ' . ($result3 === false ? 'failed' : 'succeeded'));
        }
        
        // Delete the database version option
        $deleted = delete_option('gcal_db_version');
        error_log('Google Calendar Events Manager: Deleted db_version option: ' . ($deleted ? 'yes' : 'no'));
    } else {
        error_log('Google Calendar Events Manager: Cleanup is disabled, keeping table');
    }
    
    error_log('Google Calendar Events Manager: Deactivation complete');
    
    // Note: We're not deleting the gcal_settings option here to preserve settings on reactivation
}

// Uninstall hook - this will be called when the plugin is deleted
register_uninstall_hook(__FILE__, 'gcal_uninstall');

/**
 * Clean up database and options when the plugin is uninstalled
 */
function gcal_uninstall() {
    // Include the database class
    require_once GCAL_EVENTS_PLUGIN_DIR . 'includes/class-gcal-db.php';
    
    // Drop the events table
    GCAL_DB::drop_events_table();
    
    // Delete plugin options
    delete_option('gcal_settings');
    delete_option('gcal_import_logs');
}

// Add settings link on plugin page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $settings_link = '<a href="' . admin_url('options-general.php?page=gcal-settings') . '">' . __('Settings') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});
