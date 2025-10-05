<?php
/**
 * Handles installation, updates, and uninstallation of the plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class GCAL_Install {
    /**
     * Current database version
     */
    private static $db_version = '1.1';
    
    /**
     * Initialize the installation
     */
    public static function init() {
        register_activation_hook(GCAL_PLUGIN_FILE, [__CLASS__, 'activate']);
        register_deactivation_hook(GCAL_PLUGIN_FILE, [__CLASS__, 'deactivate']);
        register_uninstall_hook(GCAL_PLUGIN_FILE, [__CLASS__, 'uninstall']);
        
        // Handle database updates
        add_action('plugins_loaded', [__CLASS__, 'check_db_version']);
    }
    
    /**
     * Plugin activation
     */
    public static function activate() {
        global $wpdb;
        
        // Create or update database tables
        self::create_tables();
        
        // Schedule daily import
        if (!wp_next_scheduled('gcal_daily_import')) {
            wp_schedule_event(time(), 'daily', 'gcal_daily_import');
        }
        
        // Set default options if they don't exist
        $default_options = [
            'theme' => 'modern',
            'cache_duration' => 24,
            'date_format' => 'd.m.Y',
            'time_format' => 'H:i',
            'cleanup_on_deactivate' => 1,
            'lookahead_months' => 1
        ];
        
        $current_options = get_option('gcal_settings', []);
        update_option('gcal_settings', wp_parse_args($current_options, $default_options));
    }
    
    /**
     * Plugin deactivation
     */
    public static function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook('gcal_daily_import');
        
        // Check if we should clean up on deactivation
        $options = get_option('gcal_settings', []);
        if (isset($options['cleanup_on_deactivate']) && $options['cleanup_on_deactivate']) {
            self::drop_tables();
            delete_option('gcal_db_version');
        }
    }
    
    /**
     * Plugin uninstallation
     */
    public static function uninstall() {
        // Always clean up on uninstall
        self::drop_tables();
        
        // Delete options
        delete_option('gcal_settings');
        delete_option('gcal_db_version');
        delete_option('gcal_last_import');
        delete_option('gcal_import_running');
        delete_option('gcal_import_errors');
        
        // Clear any transients
        global $wpdb;
        $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '%gcal_%'");
    }
    
    /**
     * Check if we need to update the database
     */
    public static function check_db_version() {
        if (get_option('gcal_db_version') !== self::$db_version) {
            self::create_tables();
        }
    }
    
    /**
     * Create database tables
     */
    private static function create_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'gcal_events';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            uid varchar(255) NOT NULL,
            summary text NOT NULL,
            location text DEFAULT '',
            description text DEFAULT '',
            start_time datetime NOT NULL,
            end_time datetime NOT NULL,
            last_modified datetime NOT NULL,
            rrule text DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uid (uid),
            KEY start_time (start_time)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Update the database version
        update_option('gcal_db_version', self::$db_version);
    }
    
    /**
     * Drop database tables
     */
    private static function drop_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'gcal_events';
        
        // Disable foreign key checks
        $wpdb->query('SET FOREIGN_KEY_CHECKS=0');
        
        // Drop the table if it exists
        $wpdb->query("DROP TABLE IF EXISTS `$table_name`");
        
        // Re-enable foreign key checks
        $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
    }
}

// Initialize the installer
GCAL_Install::init();
