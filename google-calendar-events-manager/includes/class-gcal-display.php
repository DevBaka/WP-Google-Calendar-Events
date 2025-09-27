<?php
if (!defined('ABSPATH')) {
    exit;
}

class GCAL_Display {
    private $db;
    private $months_de = [
        '01' => 'Januar', '02' => 'Februar', '03' => 'März', '04' => 'April',
        '05' => 'Mai', '06' => 'Juni', '07' => 'Juli', '08' => 'August',
        '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Dezember'
    ];
    
    public function __construct($db) {
        $this->db = $db;
        add_shortcode('gcal_events', [$this, 'render_events_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_gcal_get_events', [$this, 'ajax_get_events']);
        add_action('wp_ajax_nopriv_gcal_get_events', [$this, 'ajax_get_events']);
    }
    
    public function enqueue_assets() {
        // Only load on pages that use the shortcode
        global $post;
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'gcal_events')) {
            wp_enqueue_style(
                'gcal-events',
                GCAL_EVENTS_PLUGIN_URL . 'assets/css/gcal-events.css',
                [],
                GCAL_EVENTS_VERSION
            );
            
            wp_enqueue_script(
                'gcal-events',
                GCAL_EVENTS_PLUGIN_URL . 'assets/js/gcal-events.js',
                ['jquery'],
                GCAL_EVENTS_VERSION,
                true
            );
            
            wp_localize_script('gcal-events', 'gcalEvents', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('gcal_events_nonce')
            ]);
        }
    }
    
    public function render_events_shortcode($atts = []) {
        $atts = shortcode_atts([
            'limit' => 0,
            'show_past' => 'no',
            'category' => ''
        ], $atts, 'gcal_events');
        
        ob_start();
        include GCAL_EVENTS_PLUGIN_DIR . 'templates/events-list.php';
        return ob_get_clean();
    }
    
    public function ajax_get_events() {
        check_ajax_referer('gcal_events_nonce', 'nonce');
        
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 0;
        $show_past = isset($_GET['show_past']) ? $_GET['show_past'] === 'yes' : false;
        
        $events = $this->get_events($limit, $show_past);
        
        if (empty($events)) {
            wp_send_json_success(['html' => '<p class="no-events">Keine bevorstehenden Veranstaltungen.</p>']);
        }
        
        ob_start();
        $this->render_events_list($events);
        $html = ob_get_clean();
        
        wp_send_json_success(['html' => $html]);
    }
    
    private function get_events($limit = 0, $show_past = false) {
        $events = $this->db->get_events($limit);
        
        if (!$show_past) {
            $now = current_time('mysql');
            $events = array_filter($events, function($event) use ($now) {
                return $event['end_time'] >= $now;
            });
        }
        
        return $events;
    }
    
    private function render_events_list($events) {
        if (empty($events)) {
            echo '<p class="no-events">Keine Veranstaltungen gefunden.</p>';
            return;
        }
        
        $current_month = '';
        $first_item = true;
        
        foreach ($events as $event) {
            $date = new DateTime($event['start_time']);
            $date_end = new DateTime($event['end_time']);
            
            $day = $date->format('d');
            $month = $this->months_de[$date->format('m')];
            $year = $date->format('Y');
            $start_time = $date->format('H:i');
            $end_time = $date_end->format('H:i');
            $formatted_date = $date->format('d.m.Y');
            
            // Check if we need to output a month header
            $event_month = $date->format('Y-m');
            if ($current_month !== $event_month) {
                if (!$first_item) {
                    echo '</div>'; // Close previous month group
                }
                echo '<div class="gcal-month-group">';
                echo '<h3 class="gcal-month-title">' . esc_html(ucfirst($month) . ' ' . $year) . '</h3>';
                $current_month = $event_month;
                $first_item = false;
            }
            ?>
            <div class="gcal-event" data-event-id="<?php echo esc_attr($event['id']); ?>">
                <div class="gcal-event-inner">
                    <div class="gcal-event-date">
                        <span class="gcal-event-day"><?php echo esc_html($day); ?></span>
                        <span class="gcal-event-month"><?php echo esc_html(substr($month, 0, 3)); ?></span>
                    </div>
                    <div class="gcal-event-content">
                        <h4 class="gcal-event-title"><?php echo esc_html($event['summary']); ?></h4>
                        <div class="gcal-event-meta">
                            <?php if (!empty($event['location'])): ?>
                                <span class="gcal-event-location">
                                    <i class="fas fa-map-marker-alt"></i> 
                                    <?php echo esc_html($event['location']); ?>
                                </span>
                            <?php endif; ?>
                            <span class="gcal-event-time">
                                <i class="far fa-clock"></i> 
                                <?php echo esc_html($start_time); ?> - <?php echo esc_html($end_time); ?> Uhr
                            </span>
                        </div>
                        <?php if (!empty($event['description'])): ?>
                            <div class="gcal-event-description">
                                <?php echo nl2br(esc_html($event['description'])); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php
        }
        
        if (!empty($events)) {
            echo '</div>'; // Close the last month group
        }
    }
}
