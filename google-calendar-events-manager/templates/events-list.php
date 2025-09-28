<?php
/**
 * Template for displaying events list
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
    $db = new GCAL_DB();
    $limit = isset($args['limit']) ? intval($args['limit']) : 0;
    $show_past = isset($args['show_past']) && $args['show_past'] === 'yes';
    
    $events = $db->get_events($limit);
    
    if (!$show_past) {
        $now = current_time('mysql');
        $events = array_filter($events, function($event) use ($now) {
            return $event['end_time'] >= $now;
        });
    }
}
?>

<?php
// Get current theme
$options = get_option('gcal_settings', []);
$theme = isset($options['theme']) && $options['theme'] === 'modern' ? 'modern' : 'default';
?>
<div class="gcal-events-container theme-<?php echo esc_attr($theme); ?>">
    <?php if (empty($events)) : ?>
        <p class="gcal-no-events"><?php _e('Keine bevorstehenden Veranstaltungen.', 'gcal-events'); ?></p>
    <?php else : ?>
        <?php 
        $current_month = '';
        $first_item = true;
        
        foreach ($events as $event) : 
            // Create DateTime objects from the stored UTC times
            $date = new DateTime($event['start_time'], new DateTimeZone('UTC'));
            $date_end = new DateTime($event['end_time'], new DateTimeZone('UTC'));
            
            // Set the timezone to the site's timezone for display
            $date->setTimezone($local_timezone);
            $date_end->setTimezone($local_timezone);
            
            // Get the day for grouping events by month
            $day = $date->format('d');
            $month_num = $date->format('m');
            $month = $months_de[$month_num] ?? $date->format('F');
            $year = $date->format('Y');
            $start_time = $date->format($time_format);
            $end_time = $date_end->format($time_format);
            $formatted_date = $date->format($date_format);
            
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
                            <?php if (!empty($event['location'])) : ?>
                                <span class="gcal-event-location">
                                    <i class="fas fa-map-marker-alt"></i> 
                                    <?php echo esc_html($event['location']); ?>
                                </span>
                            <?php endif; ?>
                            <span class="gcal-event-time">
                                <i class="far fa-clock"></i> 
                                <?php 
                                if ($date->format('Y-m-d') === $date_end->format('Y-m-d')) {
                                    // Same day
                                    echo esc_html(sprintf(
                                        '%s - %s Uhr', 
                                        $start_time, 
                                        $end_time
                                    ));
                                } else {
                                    // Multi-day event
                                    echo esc_html(sprintf(
                                        '%s - %s', 
                                        $date->format($date_format . ' H:i'),
                                        $date_end->format($date_format . ' H:i')
                                    ));
                                }
                                ?>
                            </span>
                        </div>
                        <?php if (!empty($event['description'])) : ?>
                            <div class="gcal-event-description">
                                <?php echo esc_html($event['description']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php 
        endforeach; 
        
        if (!empty($events)) {
            echo '</div>'; // Close the last month group
        }
        ?>
    <?php endif; ?>
</div>
