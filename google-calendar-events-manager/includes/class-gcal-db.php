<?php
if (!defined('ABSPATH')) {
    exit;
}

class GCAL_DB {
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'gcal_events';
    }
    
    public static function create_events_table() {
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
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uid (uid),
            KEY start_time (start_time)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Add version to track updates
        add_option('gcal_db_version', '1.0');
    }
    
    public function get_events($limit = 0) {
        global $wpdb;
        $query = "SELECT * FROM {$this->table_name} 
                 WHERE start_time >= %s 
                 ORDER BY start_time ASC";
                 
        if ($limit > 0) {
            $query .= $wpdb->prepare(" LIMIT %d", $limit);
            return $wpdb->get_results(
                $wpdb->prepare($query, current_time('mysql')),
                ARRAY_A
            );
        }
        
        return $wpdb->get_results(
            $wpdb->prepare($query, current_time('mysql')),
            ARRAY_A
        );
    }
    
    public function update_or_insert_event($event_data) {
        global $wpdb;
        
        $defaults = [
            'uid' => '',
            'summary' => '',
            'location' => '',
            'description' => '',
            'start_time' => current_time('mysql'),
            'end_time' => current_time('mysql'),
            'last_modified' => current_time('mysql')
        ];
        
        $event_data = wp_parse_args($event_data, $defaults);
        
        // Sanitize data
        $event_data = array_map('wp_strip_all_tags', $event_data);
        
        $result = $wpdb->replace(
            $this->table_name,
            $event_data,
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        
        return $result !== false;
    }
    
    public function delete_old_events() {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table_name} WHERE end_time < %s",
                current_time('mysql', -1)
            )
        );
    }
}
