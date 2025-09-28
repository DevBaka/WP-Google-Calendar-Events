<?php
if (!defined('ABSPATH')) {
    exit;
}

class GCAL_JSON_Export {
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Add init hook with high priority
        add_action('init', [$this, 'add_endpoint'], 1);
        add_filter('query_vars', [$this, 'add_query_vars']);
        
        // Use template_redirect instead of parse_request for better compatibility
        add_action('template_redirect', [$this, 'handle_request']);
        
        // Add settings update hook
        add_action('gcal_after_settings_updated', [$this, 'flush_rewrite_rules']);
        
        // Add activation and deactivation hooks
        if (function_exists('register_activation_hook')) {
            register_activation_hook(GCAL_PLUGIN_FILE, [$this, 'activate']);
            register_deactivation_hook(GCAL_PLUGIN_FILE, [$this, 'deactivate']);
        }
    }
    
    public function activate() {
        $this->add_endpoint();
        flush_rewrite_rules();
    }
    
    public function deactivate() {
        flush_rewrite_rules();
    }
    
    public function add_endpoint() {
        $options = get_option('gcal_settings', []);
        $slug = isset($options['json_export_slug']) ? sanitize_title($options['json_export_slug']) : 'calendar-events';
        if (empty($slug)) $slug = 'calendar-events';
        
        // Add rewrite rule
        add_rewrite_rule(
            '^' . $slug . '\.json$',
            'index.php?gcal_json_export=1',
            'top'
        );
        
        // Add rewrite tag
        add_rewrite_tag('%gcal_json_export%', '([^&]+)');
        
        // Flush rules if our rule isn't in the array
        $rules = get_option('rewrite_rules');
        if (!isset($rules['^' . $slug . '\.json$'])) {
            flush_rewrite_rules(false);
        }
    }
    
    public function add_query_vars($vars) {
        $vars[] = 'gcal_json_export';
        return $vars;
    }
    
    public function handle_request() {
        // Check both query var and direct parameter for better compatibility
        $json_export = get_query_var('gcal_json_export');
        $direct_param = isset($_GET['gcal_json_export']) ? $_GET['gcal_json_export'] : '';
        
        if (!empty($json_export) || !empty($direct_param)) {
            $options = get_option('gcal_settings', []);
            $enabled = isset($options['enable_json_export']) ? (bool)$options['enable_json_export'] : false;
            
            if (!$enabled) {
                status_header(403);
                wp_send_json_error('JSON export is not enabled in settings');
                exit;
            }
            
            $this->output_json();
            exit;
        }
    }
    
    public function output_json() {
        global $wpdb;
        
        try {
            // Get events from database
            $table_name = $wpdb->prefix . 'gcal_events';
            $now = current_time('mysql');
            
            $events = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table_name} WHERE end_time >= %s ORDER BY start_time ASC",
                    $now
                ),
                ARRAY_A
            );
            
            if ($wpdb->last_error) {
                throw new Exception('Database error: ' . $wpdb->last_error);
            }
            
            // Format events
            $formatted_events = [];
            foreach ($events as $event) {
                $formatted_events[] = [
                    'id' => $event['id'],
                    'title' => $event['summary'],
                    'start' => $event['start_time'],
                    'end' => $event['end_time'],
                    'location' => $event['location'],
                    'description' => $event['description'],
                    'uid' => $event['uid']
                ];
            }
            
            $response = [
                'status' => 'success',
                'generated' => current_time('mysql'),
                'count' => count($formatted_events),
                'events' => $formatted_events
            ];
            
            // Output JSON with pretty print for better readability
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $json = json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            } else {
                $json = json_encode($response);
            }
            
            if ($json === false) {
                throw new Exception('Failed to encode JSON: ' . json_last_error_msg());
            }
            
            // Set headers and output JSON
            status_header(200);
            header('Content-Type: application/json; charset=' . get_option('blog_charset'));
            header('Cache-Control: no-cache, must-revalidate');
            header('Expires: 0');
            
            echo $json;
            
        } catch (Exception $e) {
            status_header(500);
            wp_send_json_error([
                'status' => 'error',
                'message' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
        }
        
        exit;
    }
    
    public function flush_rewrite_rules() {
        error_log('GCAL JSON Export: Flushing rewrite rules');
        $result = flush_rewrite_rules(false);
        error_log('GCAL JSON Export: Flush result - ' . ($result ? 'Success' : 'Failed'));
    }
}

// Initialize the JSON export functionality
function gcal_init_json_export() {
    return GCAL_JSON_Export::get_instance();
}
add_action('plugins_loaded', 'gcal_init_json_export');
