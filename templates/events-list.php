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
    
    // Debug: Log the number of events and theme
    error_log('GCAL Template - Theme: ' . $theme . ', Events count: ' . count($events) . ', Limit: ' . $limit);
}

// Process events for display
$processed_events = [];
if (!empty($events)) {
    foreach ($events as $event) {
        try {
            $date = new DateTime($event['start_time'], $utc_timezone);
            $end_date = new DateTime($event['end_time'], $utc_timezone);
            
            // Convert to local timezone
            $date->setTimezone($local_timezone);
            $end_date->setTimezone($local_timezone);
            
            $month_num = $date->format('m');
            $month_name = $months_de[$month_num] ?? $date->format('F');
            
            $processed_events[] = [
                'date' => $date,
                'end_date' => $end_date,
                'formatted_date' => $date->format($date_format),
                'start_time' => $date->format($time_format),
                'end_time' => $end_date->format($time_format),
                'day' => $date->format('d'),
                'month' => $date->format('F Y'),
                'month_num' => $month_num,
                'month_name' => $month_name,
                'data' => $event
            ];
        } catch (Exception $e) {
            error_log('Error processing event: ' . $e->getMessage());
            continue;
        }
    }
}
?>

<?php if ($theme === 'modern-expand') : ?>
    <div class="events-container theme-modern-expand">
        <?php if (empty($processed_events)) : ?>
            <p class="no-events"><?php _e('Keine bevorstehenden Veranstaltungen.', 'gcal-events'); ?></p>
        <?php else : ?>
            <div class="event-calendar">
                <div class="spacer"></div>
                <div class="event-list">
                    <?php 
                    $current_month = '';
                    foreach ($processed_events as $event) : 
                        $date = $event['date'];
                        
                        // Display month header if it's a new month
                        if ($event['month'] !== $current_month) :
                            if ($current_month !== '') {
                                echo '</div>'; // Close previous month group
                            }
                            $current_month = $event['month'];
                    ?>
                        <div class="month-group">
                            <h3 class="month-header"><?php echo esc_html($current_month); ?></h3>
                    <?php endif; ?>
                    
                    <div class="event-item">
                        <a href="#" class="event">
                            <div class="event-inner">
                                <span class="date-container">
                                    <span class="date">
                                        <span class="day"><?php echo esc_html($event['day']); ?></span>
                                        <span class="month"><?php echo esc_html(substr($event['month_name'], 0, 3)); ?></span>
                                    </span>
                                </span>
                                <span class="detail-container">
                                    <span class="title"><?php echo esc_html($event['data']['summary']); ?></span>
                                    <span class="time"><?php echo esc_html($event['start_time'] . ' - ' . $event['end_time']); ?></span>
                                </span>
                            </div>
                        </a>
                        <div class="event-details">
                            <div class="event-info">
                                <div class="info-row">
                                    <span class="info-label">Datum:</span>
                                    <span class="info-value"><?php echo esc_html($event['formatted_date']); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Uhrzeit:</span>
                                    <span class="info-value"><?php echo esc_html($event['start_time'] . ' - ' . $event['end_time']); ?></span>
                                </div>
                                <?php if (!empty($event['data']['location'])) : ?>
                                <div class="info-row">
                                    <span class="info-label">Ort:</span>
                                    <span class="info-value"><?php echo esc_html($event['data']['location']); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($event['data']['description'])) : ?>
                                <div class="event-description">
                                    <?php echo wp_kses_post(nl2br($event['data']['description'])); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <?php 
                        // Close the month group if this is the last event
                        if ($event === end($processed_events)) : 
                    ?>
                        </div><!-- Close .month-group -->
                    <?php 
                        endif;
                    endforeach; 
                    ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
<?php else : ?>
    <div class="gcal-events-container theme-<?php echo esc_attr($theme); ?>">
        <?php if (empty($processed_events)) : ?>
            <p class="gcal-no-events"><?php _e('Keine bevorstehenden Veranstaltungen.', 'gcal-events'); ?></p>
        <?php else : ?>
            <?php
            $current_month = '';
            foreach ($processed_events as $event) :
                if ($event['month'] !== $current_month) :
                    if ($current_month !== '') {
                        echo '</div>';
                    }
                    $current_month = $event['month'];
            ?>
                <div class="gcal-month-group">
                    <h3 class="gcal-month-title"><?php echo esc_html($current_month); ?></h3>
            <?php endif; ?>

                    <div class="gcal-event">
                        <div class="gcal-event-inner">
                            <div class="gcal-event-date">
                                <span class="gcal-event-day"><?php echo esc_html($event['day']); ?></span>
                                <span class="gcal-event-month"><?php echo esc_html(substr($event['month_name'], 0, 3)); ?></span>
                            </div>
                            <div class="gcal-event-content">
                                <h4 class="gcal-event-title"><?php echo esc_html($event['data']['summary']); ?></h4>
                                <div class="gcal-event-meta">
                                    <?php if (!empty($event['data']['location'])) : ?>
                                        <span class="gcal-event-location">
                                            <i class="fas fa-map-marker-alt"></i>
                                            <?php echo esc_html($event['data']['location']); ?>
                                        </span>
                                    <?php endif; ?>
                                    <span class="gcal-event-time">
                                        <i class="far fa-clock"></i>
                                        <?php echo esc_html($event['start_time'] . ' - ' . $event['end_time']); ?>
                                    </span>
                                </div>
                                <?php if (!empty($event['data']['description'])) : ?>
                                    <div class="gcal-event-description">
                                        <?php echo wp_kses_post(nl2br($event['data']['description'])); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

            <?php
                if ($event === end($processed_events)) :
                    echo '</div>';
                endif;
            endforeach;
            ?>
        <?php endif; ?>
    </div>
<?php endif; ?>
