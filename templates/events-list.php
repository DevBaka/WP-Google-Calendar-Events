<?php
/**
 * Template for displaying events list with modern-expand theme
 * 
 * @var array $events Array of event data
 * @var array $args Shortcode attributes
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Ensure WordPress core functions are available
if (!function_exists('get_option')) {
    require_once(ABSPATH . 'wp-includes/option.php');
}

// Get plugin options
$options = get_option('gcal_settings', []);
$date_format = $options['date_format'] ?? 'd.m.Y';
$time_format = $options['time_format'] ?? 'H:i';
$theme = $options['theme'] ?? 'default';

// German month names for display
$months_de = [
    '01' => 'Januar', '02' => 'Februar', '03' => 'März', '04' => 'April',
    '05' => 'Mai', '06' => 'Juni', '07' => 'Juli', '08' => 'August',
    '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Dezember'
];

// Get current time in WordPress timezone
$now = current_time('mysql');

// Get WordPress timezone
$timezone_string = get_option('timezone_string');
if (empty($timezone_string)) {
    $gmt_offset = (float) get_option('gmt_offset');
    $hours = (int) $gmt_offset;
    $minutes = abs(($gmt_offset - $hours) * 60);
    $timezone_string = sprintf('%+03d:%02d', $hours, $minutes);
    if ($timezone_string === '+00:00') {
        $timezone_string = 'UTC';
    }
}

// Create timezone objects
$utc_timezone = new DateTimeZone('UTC');
$local_timezone = new DateTimeZone($timezone_string);

// Get events if not passed
if (!isset($events)) {
    global $gcal_display;
    if (!isset($gcal_display)) {
        $gcal_db = new GCAL_DB();
        $gcal_display = new GCAL_Display($gcal_db);
    }
    
    // Get limit from shortcode atts, default to 0 (no limit)
    $limit = isset($args['limit']) ? intval($args['limit']) : 0;
    $show_past = isset($args['show_past']) && $args['show_past'] === 'yes';
    
    // Use the get_events method which respects the limit
    $events = $gcal_display->get_events($limit, $show_past);
}
?>

<?php 
$options = get_option('gcal_settings', []);
$theme = $options['theme'] ?? 'default';
?>
<?php if ($theme === 'modern-expand') : ?>
    <div class="events-container theme-modern-expand">
        <div class="event-calendar">
            <div class="spacer"></div>
            <div class="event-list">
                <?php if (empty($events)) : ?>
                    <p class="no-events"><?php _e('Keine bevorstehenden Veranstaltungen.', 'gcal-events'); ?></p>
                <?php else : ?>
                    <?php 
                    $current_month = '';
                    foreach ($events as $event) : 
                        $date = new DateTime($event['start_time'], new DateTimeZone('UTC'));
                        $end_date = new DateTime($event['end_time'], new DateTimeZone('UTC'));
                        
                        // Convert to local timezone
                        $date->setTimezone($local_timezone);
                        $end_date->setTimezone($local_timezone);
                        
                        $formatted_date = $date->format($date_format);
                        $start_time = $date->format($time_format);
                        $end_time = $end_date->format($time_format);
                        
                        // Get day and month for modern-expand theme
                        $day = $date->format('d');
                        $month_num = $date->format('m');
                        $month_name = $months_de[$month_num] ?? $date->format('F');
                        
                        // Add month header if needed
                        $month = $date->format('F Y');
                        if ($month !== $current_month) :
                            $current_month = $month;
                    ?>
                        <div class="month-header"><?php echo esc_html($month); ?></div>
                    <?php endif; ?>
                    
                    <div class="event-item">
                        <a href="#" class="event">
                            <div class="event-inner">
                                <span class="date-container">
                                    <span class="date">
                                        <span class="day"><?php echo esc_html($day); ?></span>
                                        <span class="month"><?php echo esc_html(substr($month_name, 0, 3)); ?></span>
                                    </span>
                                </span>
                                <span class="detail-container">
                                    <span class="title"><?php echo esc_html($event['summary']); ?></span>
                                    <span class="time"><?php echo esc_html($start_time . ' - ' . $end_time); ?></span>
                                </span>
                            </div>
                        </a>
                        <div class="event-details">
                            <div class="event-info">
                                <div class="info-row">
                                    <span class="info-label">Datum:</span>
                                    <span class="info-value"><?php echo esc_html($formatted_date); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Uhrzeit:</span>
                                    <span class="info-value"><?php echo esc_html($start_time . ' - ' . $end_time); ?></span>
                                </div>
                                <?php if (!empty($event['location'])) : ?>
                                <div class="info-row">
                                    <span class="info-label">Ort:</span>
                                    <span class="info-value"><?php echo esc_html($event['location']); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($event['description'])) : ?>
                                <div class="event-description">
                                    <?php echo wp_kses_post(nl2br($event['description'])); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php else : ?>
    <div class="gcal-events-container <?php echo $theme === 'modern' ? 'theme-modern' : ''; ?>">
        <?php if (empty($events)) : ?>
            <p class="gcal-no-events"><?php _e('Keine bevorstehenden Veranstaltungen.', 'gcal-events'); ?></p>
        <?php else : ?>
            <?php 
            $current_month = '';
            foreach ($events as $event) : 
                $date = new DateTime($event['start_time'], new DateTimeZone('UTC'));
                $end_date = new DateTime($event['end_time'], new DateTimeZone('UTC'));
                
                // Convert to local timezone
                $date->setTimezone($local_timezone);
                $end_date->setTimezone($local_timezone);
                
                $month = $date->format('F Y');
                $formatted_date = $date->format($date_format);
                $start_time = $date->format($time_format);
                $end_time = $end_date->format($time_format);
                
                // Display month header if it's a new month
                if ($month !== $current_month) :
                    $current_month = $month;
            ?>
                <div class="gcal-month-group">
                    <h3 class="gcal-month-title"><?php echo esc_html($month); ?></h3>
            <?php endif; ?>
            
            <div class="gcal-event">
                <div class="gcal-event-inner">
                    <div class="gcal-event-date">
                        <span class="gcal-event-day"><?php echo esc_html($date->format('d')); ?></span>
                        <span class="gcal-event-month"><?php echo esc_html(substr($months_de[$date->format('m')] ?? $date->format('F'), 0, 3)); ?></span>
                    </div>
                    <div class="gcal-event-details">
                        <h4 class="gcal-event-title"><?php echo esc_html($event['summary']); ?></h4>
                        <div class="gcal-event-time">
                            <span class="gcal-event-date"><?php echo esc_html($formatted_date); ?></span>
                            <span class="gcal-event-time"><?php echo esc_html($start_time . ' - ' . $end_time); ?></span>
                        </div>
                        <?php if (!empty($event['location'])) : ?>
                            <div class="gcal-event-location">
                                <?php echo esc_html($event['location']); ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($event['description'])) : ?>
                            <div class="gcal-event-description">
                                <?php echo wp_kses_post(nl2br($event['description'])); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <?php 
                // Close month group if this is the last event or the next event is in a different month
                $next_index = array_search($event, $events) + 1;
                if ($next_index >= count($events) || 
                    (new DateTime($events[$next_index]['start_time']))->format('F Y') !== $month) : 
            ?>
                </div><!-- Close .gcal-month-group -->
            <?php 
                endif; 
            endforeach; 
            ?>
        <?php endif; ?>
    </div>
<?php endif; ?>
