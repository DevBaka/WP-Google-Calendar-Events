<?php
/**
 * Database handling class for Google Calendar Events Manager
 * 
 * @package Google_Calendar_Events
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Include WordPress database upgrade functions
if (!function_exists('dbDelta')) {
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
}

/**
 * Handles all database operations for the Google Calendar Events plugin
 */
class GCAL_DB {
    /**
     * Current database version
     * 
     * @since 1.0.0
     * @var string
     */
    const DB_VERSION = '1.0.0';
    
    /**
     * The database table name
     *
     * @since 1.0.0
     * @var string
     */
    private $table_name;
    
    /**
     * WordPress database abstraction object
     *
     * @since 1.0.0
     * @var wpdb
     */
    protected $wpdb;
    
    /**
     * Log an error message
     * 
     * @since 1.0.0
     * @param string $message The error message
     * @param string $level The error level (error, warning, notice, etc.)
     * @param mixed $data Optional data to include in the log
     * @return void
     */
    private function log_error($message, $level = 'error', $data = null) {
        if (defined('WP_DEBUG') && WP_DEBUG === true) {
            if (is_array($data) || is_object($data)) {
                $message .= ' ' . print_r($data, true);
            }
            
            error_log(sprintf(
                '[Google Calendar Events %s] %s',
                strtoupper($level),
                $message
            ));
        }
    }
    
    /**
     * Handle database errors
     * 
     * @since 1.0.0
     * @param string $method The method where the error occurred
     * @param string $query Optional SQL query that caused the error
     * @param mixed $data Optional data related to the error
     * @return false Always returns false to allow for method chaining
     */
    private function handle_error($method, $query = '', $data = null) {
        $error_message = $this->wpdb->last_error;
        
        // If no specific error message but we have a query, use a generic message
        if (empty($error_message) && !empty($query)) {
            $error_message = sprintf('Database query failed in %s', $method);
        }
        
        // Log the error with context
        $this->log_error(sprintf(
            '%s: %s',
            $method,
            $error_message
        ), 'error', [
            'query' => $query,
            'data' => $data
        ]);
        
        return false;
    }
    
    /**
     * Class constructor
     * 
     * @since 1.0.0
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'gcal_events';
        
        // Initialize error tracking
        $this->last_error = '';
        $this->last_query = '';
    }
    
    /**
     * Initialize the database class
     * 
     * @since 1.0.0
     * @return void
     */
    public function init() {
        $this->maybe_create_table();
    }
    
    /**
     * Check if we need to create or update the database table
     * 
     * @since 1.0.0
     * @return void
     */
    private function maybe_create_table() {
        $current_version = get_option('gcal_db_version', '0');
        
        if (version_compare($current_version, self::DB_VERSION, '<')) {
            $this->create_table();
            update_option('gcal_db_version', self::DB_VERSION);
        }
    }
    
    /**
     * Create the events table
     * 
     * @since 1.0.0
     * @return bool True if table was created or already exists, false on failure
     */
    public function create_table() {
        $charset_collate = $this->wpdb->get_charset_collate();
        
        // Store the query for error handling
        $this->last_query = "CREATE TABLE IF NOT EXISTS `{$this->table_name}` (
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
        
        // Check if we need to add the rrule column (for backward compatibility)
        $column_exists = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT COLUMN_NAME 
                FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = %s 
                AND TABLE_NAME = %s 
                AND COLUMN_NAME = 'rrule'",
                DB_NAME,
                $this->table_name
            )
        );
        
        if (empty($column_exists)) {
            $this->wpdb->query("ALTER TABLE `{$this->table_name}` ADD COLUMN rrule text DEFAULT ''");
        }
        
        return true;
    }
    
    /**
     * Drop the events table
     * 
     * @since 1.0.0
     * @return bool True if table was dropped or didn't exist, false on failure
     */
    public function drop_table() {
        if (!$this->table_exists()) {
            return true;
        }
        
        $result = $this->wpdb->query("DROP TABLE IF EXISTS `{$this->table_name}`");
        return $result !== false;
    }
    
    /**
     * Check if the events table exists
     * 
     * @since 1.0.0
     * @return bool True if table exists, false otherwise
     */
    public function table_exists() {
        return $this->wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name;
    }
    
    /**
     * Search events by keyword in title, description, or location
     * 
     * @since 1.0.0
     * @param string $search_term The search term
     * @param array $args Additional query arguments
     * @return array Array of matching events
     */
    public function search_events($search_term, $args = []) {
        if (empty($search_term)) {
            return [];
        }
        
        $defaults = [
            'limit' => 0,
            'order' => 'DESC',
            'orderby' => 'start_time',
            'show_past' => false
        ];
        
        $args = wp_parse_args($args, $defaults);
        $search_term = '%' . $this->wpdb->esc_like($search_term) . '%';
        
        $query = $this->wpdb->prepare(
            "SELECT * FROM `{$this->table_name}` 
            WHERE (
                summary LIKE %s OR 
                description LIKE %s OR 
                location LIKE %s
            )",
            $search_term,
            $search_term,
            $search_term
        );
        
        // Add date filter
        if (!$args['show_past']) {
            $now = current_time('mysql');
            $query .= $this->wpdb->prepare(" AND end_time >= %s", $now);
        }
        
        // Add ordering
        $query .= " ORDER BY {$args['orderby']} {$args['order']}";
        
        // Add limit
        if (!empty($args['limit']) && is_numeric($args['limit'])) {
            $query .= $this->wpdb->prepare(" LIMIT %d", (int) $args['limit']);
        }
        
        return $this->wpdb->get_results($query, ARRAY_A);
    }
    
    /**
     * Get events from the database
     * 
     * @since 1.0.0
     * @param int $limit Number of events to return (0 for no limit)
     * @param bool $show_past Whether to include past events
     * @param array $args Additional query arguments
     * @return array Array of event objects
     */
    public function get_events($limit = 0, $show_past = false, $args = []) {
        $defaults = [
            'start_date' => '',
            'end_date'   => '',
            'orderby'    => 'start_time',
            'order'      => 'ASC',
            'search'     => '',
        ];
        
        $args = wp_parse_args($args, $defaults);
        $query = "SELECT * FROM `{$this->table_name}` WHERE 1=1";
        $query_args = [];
        
        // Add date range conditions
        if (!empty($args['start_date'])) {
            $query .= " AND start_time >= %s";
            $query_args[] = $args['start_date'];
        }
        
        if (!empty($args['end_date'])) {
            $query .= " AND end_time <= %s";
            $query_args[] = $args['end_date'];
        }
        
        // Add search condition
        if (!empty($args['search'])) {
            $query .= " AND (summary LIKE %s OR description LIKE %s OR location LIKE %s)";
            $search_term = '%' . $this->wpdb->esc_like($args['search']) . '%';
            $query_args = array_merge($query_args, [$search_term, $search_term, $search_term]);
        }
        
        // Add ordering
        $order = in_array(strtoupper($args['order']), ['ASC', 'DESC']) ? $args['order'] : 'ASC';
        $orderby = in_array($args['orderby'], ['start_time', 'end_time', 'last_modified']) ? $args['orderby'] : 'start_time';
        $query .= " ORDER BY $orderby $order";
        
        // Add limit
        if ($limit > 0) {
            $query .= " LIMIT %d";
            $query_args[] = $limit;
        }
        
        // Prepare and execute the query
        if (!empty($query_args)) {
            $query = $this->wpdb->prepare($query, $query_args);
        }
        
        $events = $this->wpdb->get_results($query, ARRAY_A);
        
        // Filter out past events if needed
        if (!$show_past && !empty($events)) {
            $now = current_time('mysql');
            $events = array_filter($events, function($event) use ($now) {
                return $event['end_time'] >= $now;
            });
            // Re-index array after filtering
            $events = array_values($events);
        }
        
        return $events;
    }
    
    /**
     * Get events within a specific date range
     * 
     * @since 1.0.0
     * @param string $start_date Start date in Y-m-d format
     * @param string $end_date End date in Y-m-d format
     * @param array $args Additional query arguments
     * @return array Array of event objects
     */
    public function get_events_in_range($start_date, $end_date, $args = []) {
        if (empty($start_date) || empty($end_date)) {
            return [];
        }
        
        $defaults = [
            'order' => 'ASC',
            'orderby' => 'start_time',
            'limit' => 0,
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        $query = $this->wpdb->prepare(
            "SELECT * FROM `{$this->table_name}` 
            WHERE (
                (start_time >= %s AND start_time <= %s) OR 
                (end_time >= %s AND end_time <= %s) OR 
                (start_time <= %s AND end_time >= %s)
            )
            ORDER BY %s %s",
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59',
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59',
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59',
            $args['orderby'],
            $args['order']
        );
        
        if (!empty($args['limit']) && is_numeric($args['limit'])) {
            $query .= $this->wpdb->prepare(" LIMIT %d", (int) $args['limit']);
        }
        
        return $this->wpdb->get_results($query, ARRAY_A);
    }
    
    /**
     * Count events within a specific date range
     * 
     * @since 1.0.0
     * @param string $start_date Start date in Y-m-d format
     * @param string $end_date End date in Y-m-d format
     * @return int Number of events in the date range
     */
    public function count_events_in_range($start_date, $end_date) {
        if (empty($start_date) || empty($end_date)) {
            return 0;
        }
        
        $count = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM `{$this->table_name}` 
                WHERE (
                    (start_time >= %s AND start_time <= %s) OR 
                    (end_time >= %s AND end_time <= %s) OR 
                    (start_time <= %s AND end_time >= %s)
                )",
                $start_date . ' 00:00:00',
                $end_date . ' 23:59:59',
                $start_date . ' 00:00:00',
                $end_date . ' 23:59:59',
                $start_date . ' 00:00:00',
                $end_date . ' 23:59:59'
            )
        );
        
        return (int) $count;
    }
    
    /**
     * Get a single event by ID
     * 
     * @since 1.0.0
     * @param int $event_id The event ID
     * @return array|false The event data as an array, or false if not found
     */
    public function get_event($event_id) {
        if (empty($event_id)) {
            return false;
        }
        
        $query = $this->wpdb->prepare(
            "SELECT * FROM `{$this->table_name}` WHERE id = %d",
            $event_id
        );
        
        return $this->wpdb->get_row($query, ARRAY_A);
    }
    
    /**
     * Save an event to the database
     * 
     * @since 1.0.0
     * @param array $event_data The event data to save
     * @return int|false The event ID if successful, false on failure
     */
    public function save_event($event_data) {
        if (empty($event_data) || !is_array($event_data)) {
            return false;
        }
        
        $current_time = current_time('mysql');
        $defaults = [
            'uid' => '',
            'summary' => '',
            'location' => '',
            'description' => '',
            'start_time' => $current_time,
            'end_time' => $current_time,
            'last_modified' => $current_time,
            'rrule' => '',
        ];
        
        // Sanitize and validate event data
        $event_data = wp_parse_args($event_data, $defaults);
        
        // Check if this is an update
        $event_id = 0;
        if (!empty($event_data['id'])) {
            $event_id = (int) $event_data['id'];
            unset($event_data['id']);
        }
        
        // Check for existing event with the same UID
        if (!empty($event_data['uid'])) {
            $existing_id = $this->get_event_id_by_uid($event_data['uid']);
            if ($existing_id && $existing_id != $event_id) {
                $event_id = $existing_id;
            }
        }
        
        // Update existing event
        if ($event_id > 0) {
            $result = $this->wpdb->update(
                $this->table_name,
                $event_data,
                ['id' => $event_id],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'],
                ['%d']
            );
            
            return $result !== false ? $event_id : false;
        } 
        // Insert new event
        else {
            $result = $this->wpdb->insert(
                $this->table_name,
                $event_data,
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
            );
            
            return $result ? $this->wpdb->insert_id : false;
        }
    }
    
    /**
     * Delete an event by ID
     * 
     * @since 1.0.0
     * @param int $event_id The event ID to delete
     * @return bool True if the event was deleted, false on failure
     */
    public function delete_event($event_id) {
        if (empty($event_id)) {
            return false;
        }
        
        return $this->wpdb->delete(
            $this->table_name,
            ['id' => $event_id],
            ['%d']
        ) !== false;
    }
    
    /**
     * Get an event ID by UID
     * 
     * @since 1.0.0
     * @param string $uid The event UID
     * @return int|false The event ID if found, false otherwise
     */
    public function get_event_id_by_uid($uid) {
        if (empty($uid)) {
            return false;
        }
        
        $query = $this->wpdb->prepare(
            "SELECT id FROM `{$this->table_name}` WHERE uid = %s",
            $uid
        );
        
        return (int) $this->wpdb->get_var($query);
    }
    
    /**
     * Delete all events from the database
     * 
     * @since 1.0.0
     * @return int|false Number of rows deleted, or false on failure
     */
    public function delete_all_events() {
        return $this->wpdb->query("TRUNCATE TABLE `{$this->table_name}`");
    }
    
    /**
     * Delete old events that have already ended
     * 
     * @since 1.0.0
     * @param int $days_old Number of days to keep events (default: 30)
     * @return int|false Number of rows deleted, or false on failure
     */
    public function delete_old_events($days_old = 30) {
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-$days_old days"));
        return $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM `{$this->table_name}` WHERE end_time < %s",
                $cutoff_date
            )
        );
    }
    
    /**
     * Get the database version
     * 
     * @since 1.0.0
     * @return string The current database version
     */
    public function get_db_version() {
        return get_option('gcal_db_version', '0');
    }
    
    /**
     * Update the database version
     * 
     * @since 1.0.0
     * @param string $version The new version number
     * @return bool True if version was updated, false otherwise
     */
    public function update_db_version($version) {
        return update_option('gcal_db_version', $version);
    }
    
    /**
     * Clean up plugin data (for uninstall)
     * 
     * @since 1.0.0
     * @return bool True if cleanup was successful, false otherwise
     */
    public function cleanup() {
        // Delete all plugin options
        $options = [
            'gcal_db_version',
            'gcal_last_sync',
            'gcal_sync_token',
            'gcal_calendar_id',
            'gcal_client_id',
            'gcal_client_secret',
            'gcal_access_token',
            'gcal_refresh_token',
            'gcal_token_expires'
        ];
        
        foreach ($options as $option) {
            if (get_option($option) !== false) {
                delete_option($option);
            }
        }
        
        // Clear scheduled hooks
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook('gcal_sync_events');
            wp_clear_scheduled_hook('gcal_cleanup_events');
        }
        
        // Delete transients
        if (function_exists('delete_transient')) {
            delete_transient('gcal_events_cache');
            delete_transient('gcal_auth_error');
        }
        
        // Drop the database table
        return $this->drop_table();
    }
    
    /**
     * Static method to create the events table
     * 
     * @deprecated 1.1.0 Use instance method create_table() instead
     * @since 1.0.0
     * @return bool True if table was created, false on failure
     */
    public static function create_events_table() {
        $db = new self();
        return $db->create_table();
    }
    
    /**
     * Update or insert an event
     *
     * @since 1.0.0
     * @param array $event Event data
     * @return bool|int The number of rows affected, or false on error
     */
    public function update_or_insert_event($event) {
        if (empty($event['uid'])) {
            $this->log_error('Cannot update/insert event: Missing UID', 'error', $event);
            return false;
        }

        // Check if event with this UID already exists
        $existing = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT id, last_modified FROM {$this->table_name} WHERE uid = %s",
                $event['uid']
            )
        );

        $event_data = [
            'uid' => $event['uid'],
            'summary' => $event['summary'] ?? '',
            'location' => $event['location'] ?? '',
            'description' => $event['description'] ?? '',
            'start_time' => $event['start_time'] ?? current_time('mysql'),
            'end_time' => $event['end_time'] ?? current_time('mysql'),
            'last_modified' => $event['last_modified'] ?? current_time('mysql'),
            'rrule' => $event['rrule'] ?? ''
        ];

        if ($existing) {
            // Update existing event if it has been modified
            if (strtotime($existing->last_modified) < strtotime($event_data['last_modified'])) {
                $result = $this->wpdb->update(
                    $this->table_name,
                    $event_data,
                    ['id' => $existing->id],
                    ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'],
                    ['%d']
                );
                
                if ($result === false) {
                    return $this->handle_error(__METHOD__, $this->wpdb->last_query, $event_data);
                }
                
                $this->log_error(sprintf(
                    'Updated event: %s (ID: %d)',
                    $event_data['summary'],
                    $existing->id
                ), 'info', $event_data);
                
                return $result;
            }
            // No update needed - event is not modified
            return true;
        } else {
            // Insert new event
            $result = $this->wpdb->insert(
                $this->table_name,
                $event_data,
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
            );
            
            if ($result === false) {
                return $this->handle_error(__METHOD__, $this->wpdb->last_query, $event_data);
            }
            
            $this->log_error(sprintf(
                'Inserted new event: %s',
                $event_data['summary']
            ), 'info', $event_data);
            
            return $this->wpdb->insert_id;
        }
    }

    /**
     * Get the last database error
     * 
     * @since 1.0.0
     * @return string The last error message
     */
    public function get_last_error() {
        return $this->wpdb->last_error;
    }
    
    /**
     * Get the last database query
     * 
     * @since 1.0.0
     * @return string The last executed query
     */
    public function get_last_query() {
        return $this->wpdb->last_query;
    }
    
    /**
     * Get the number of rows affected by the last query
     * 
     * @since 1.0.0
     * @return int Number of affected rows
     */
    public function get_rows_affected() {
        return $this->wpdb->rows_affected;
    }
    
    /**
     * Check database connection and table health
     * 
     * @since 1.0.0
     * @return array Array with status information
     */
    public function check_health() {
        $status = [
            'connected' => false,
            'table_exists' => false,
            'table_columns' => [],
            'error' => ''
        ];
        
        // Check database connection
        if ($this->wpdb->check_connection()) {
            $status['connected'] = true;
            
            // Check if table exists
            if ($this->table_exists()) {
                $status['table_exists'] = true;
                
                // Get table columns
                $columns = $this->wpdb->get_results("SHOW COLUMNS FROM {$this->table_name}");
                if ($columns) {
                    foreach ($columns as $column) {
                        $status['table_columns'][] = $column->Field;
                    }
                } else {
                    $status['error'] = $this->wpdb->last_error;
                }
            } else {
                $status['error'] = 'Table does not exist';
            }
        } else {
            $status['error'] = 'Database connection failed';
        }
        
        return $status;
    }
}
// Close the class definition
?>
