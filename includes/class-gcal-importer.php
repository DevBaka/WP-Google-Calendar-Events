<?php
if (!defined('ABSPATH')) {
    exit;
}

class GCAL_Importer {
    private $db;
    private $ics_url;
    private $timezone;
    private $last_series_base_uids = [];
    
    public function __construct($db) {
        $this->db = $db;
        $this->timezone = new DateTimeZone(wp_timezone_string());
        $options = get_option('gcal_settings', []);
        $this->ics_url = $options['ics_url'] ?? '';
    }
    
    public function import_events() {
        if (empty($this->ics_url)) {
            $message = 'Keine ICS-URL konfiguriert';
            error_log('GCAL Importer: ' . $message);
            GCAL_Settings::add_import_log($message, false);
            return false;
        }
        
        $message = 'Import gestartet von: ' . $this->ics_url;
        GCAL_Settings::add_import_log($message);
        
        $response = wp_remote_get($this->ics_url, [
            'timeout' => 30,
            'sslverify' => false
        ]);
        
        if (is_wp_error($response)) {
            $message = 'Fehler beim Abrufen der ICS-Datei: ' . $response->get_error_message();
            error_log('GCAL Importer: ' . $message);
            GCAL_Settings::add_import_log($message, false);
            return false;
        }
        
        $ics_content = wp_remote_retrieve_body($response);
        if (empty($ics_content)) {
            $message = 'Leerer ICS-Inhalt empfangen';
            error_log('GCAL Importer: ' . $message);
            GCAL_Settings::add_import_log($message, false);
            return false;
        }
        
        $events = $this->parse_ics($ics_content);
        if (empty($events)) {
            $message = 'Keine Ereignisse in der ICS-Datei gefunden';
            error_log('GCAL Importer: ' . $message);
            GCAL_Settings::add_import_log($message, false);
            return false;
        }
        
        $imported = 0;
        $series_keep_uids = [];
        foreach ($events as $event) {
            if (!empty($event['uid']) && strpos($event['uid'], '|') !== false) {
                $base_uid = strstr($event['uid'], '|', true);
                if ($base_uid !== false && $base_uid !== '') {
                    if (!isset($series_keep_uids[$base_uid])) {
                        $series_keep_uids[$base_uid] = [];
                    }
                    $series_keep_uids[$base_uid][] = $event['uid'];
                }
            }

            if ($this->db->update_or_insert_event($event)) {
                $imported++;
            }
        }

        foreach ($series_keep_uids as $base_uid => $keep_uids) {
            $this->db->delete_missing_recurring_instances($base_uid, $keep_uids);
        }

        if (!empty($this->last_series_base_uids)) {
            foreach ($this->last_series_base_uids as $base_uid) {
                if (!isset($series_keep_uids[$base_uid])) {
                    $this->db->delete_missing_recurring_instances($base_uid, []);
                }
            }
        }
        
        // Clean up old events
        $deleted = $this->db->delete_old_events();
        
        $message = sprintf('Erfolgreich %d Ereignisse importiert, %d veraltete Ereignisse gelöscht', $imported, $deleted);
        GCAL_Settings::add_import_log($message);
        
        return $imported;
    }
    
    public function manual_import() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Nicht autorisiert', 'gcal-events'));
        }
        
        check_ajax_referer('gcal_manual_import', 'nonce');
        
        $result = $this->import_events();
        
        if ($result === false) {
            $logs = get_option('gcal_import_logs', []);
            $last_log = !empty($logs) ? $logs[0] : null;
            $error_message = $last_log && !$last_log['success'] ? $last_log['message'] : __('Unbekannter Fehler', 'gcal-events');
            
            wp_send_json_error(sprintf(__('Import fehlgeschlagen: %s', 'gcal-events'), $error_message));
        } else {
            $logs = get_option('gcal_import_logs', []);
            $last_log = !empty($logs) ? $logs[0] : null;
            
            $success_message = $last_log ? $last_log['message'] : 
                sprintf(__('Erfolgreich %d Ereignisse importiert', 'gcal-events'), $result);
                
            wp_send_json_success($success_message);
        }
    }
    
    private function parse_ics($ics_content) {
        $events = [];
        $lines = explode("\n", $ics_content);
        $event = [];
        $in_event = false;
        $rrule = null;
        $masters = [];
        $exceptions = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            if (strpos($line, 'BEGIN:VEVENT') !== false) {
                $event = [];
                $in_event = true;
                continue;
            }
            
            if (strpos($line, 'END:VEVENT') !== false) {
                if (!empty($event) && !empty($event['uid'])) {
                    if (!empty($event['rrule']) && empty($event['recurrence_id'])) {
                        if (!isset($masters[$event['uid']])) {
                            $masters[$event['uid']] = $event;
                        } else {
                            $existing_modified = !empty($masters[$event['uid']]['last_modified']) ? strtotime($masters[$event['uid']]['last_modified']) : 0;
                            $candidate_modified = !empty($event['last_modified']) ? strtotime($event['last_modified']) : 0;
                            if ($candidate_modified >= $existing_modified) {
                                $masters[$event['uid']] = $event;
                            }
                        }
                    } elseif (!empty($event['recurrence_id'])) {
                        if (!isset($exceptions[$event['uid']])) {
                            $exceptions[$event['uid']] = [];
                        }
                        $key = !empty($event['recurrence_key']) ? $event['recurrence_key'] : $event['recurrence_id'];
                        $exceptions[$event['uid']][$key] = $event;
                    } else {
                        if (isset($event['end_time']) && $event['end_time'] > current_time('mysql')) {
                            $events[] = $event;
                        }
                    }
                }
                $in_event = false;
                $rrule = null;
                continue;
            }
            
            if ($in_event) {
                // Handle multi-line values
                if (strpos($line, ' ') === 0 && !empty($event)) {
                    $last_key = array_key_last($event);
                    $event[$last_key] .= substr($line, 1);
                    continue;
                }
                
                if (strpos($line, ':') !== false) {
                    list($key, $value) = explode(':', $line, 2);
                    
                    // Handle parameters (e.g., TZID)
                    if (strpos($key, ';') !== false) {
                        list($key, $params) = explode(';', $key, 2);
                    }
                    
                    switch ($key) {
                        case 'UID':
                            $event['uid'] = $value;
                            break;

                        case 'RECURRENCE-ID':
                            $event['recurrence_id'] = $this->parse_ical_date($value);
                            $event['recurrence_key'] = $this->to_utc_key($event['recurrence_id']);
                            break;
                            
                        case 'SUMMARY':
                            $event['summary'] = $this->unescape_ical_text($value);
                            break;
                            
                        case 'LOCATION':
                            $event['location'] = $this->unescape_ical_text($value);
                            break;
                            
                        case 'DESCRIPTION':
                            $event['description'] = $this->unescape_ical_text($value);
                            break;
                            
                        case 'DTSTART':
                            $event['start_time'] = $this->parse_ical_date($value);
                            break;
                            
                        case 'DTEND':
                            $event['end_time'] = $this->parse_ical_date($value);
                            break;
                            
                        case 'RRULE':
                            $rrule = [];
                            $pairs = explode(';', $value);
                            foreach ($pairs as $pair) {
                                if (strpos($pair, '=') !== false) {
                                    list($key, $val) = explode('=', $pair, 2);
                                    $rrule[strtoupper($key)] = $val;
                                }
                            }
                            $event['rrule'] = $rrule;
                            break;

                        case 'EXDATE':
                            $exdates = explode(',', $value);
                            foreach ($exdates as $exdate) {
                                $exdate = trim($exdate);
                                if ($exdate === '') {
                                    continue;
                                }
                                $parsed = $this->parse_ical_date($exdate);
                                if (!empty($parsed)) {
                                    $event['exdates'][] = $parsed;
                                    $event['exdate_keys'][] = $this->to_utc_key($parsed);
                                }
                            }
                            break;

                        case 'STATUS':
                            $event['status'] = strtoupper(trim($value));
                            break;
                            
                        case 'LAST-MODIFIED':
                        case 'DTSTAMP':
                            $event['last_modified'] = $this->parse_ical_date($value);
                            break;
                    }
                }
            }
        }

        foreach ($masters as $base_uid => $master) {
            $instances = $this->generate_recurring_instances($master);

            if (!empty($master['exdate_keys'])) {
                $excluded = array_flip(array_map('strval', $master['exdate_keys']));
                $instances = array_values(array_filter($instances, function($inst) use ($excluded) {
                    return empty($inst['recurrence_key']) || !isset($excluded[(string)$inst['recurrence_key']]);
                }));
            }

            if (!empty($exceptions[$base_uid])) {
                $by_recurrence = [];
                foreach ($instances as $inst) {
                    if (!empty($inst['recurrence_key'])) {
                        $by_recurrence[$inst['recurrence_key']] = $inst;
                    }
                }

                foreach ($exceptions[$base_uid] as $recurrence_id => $exception) {
                    if (!empty($exception['status']) && $exception['status'] === 'CANCELLED') {
                        unset($by_recurrence[$recurrence_id]);
                        continue;
                    }

                    $by_recurrence[$recurrence_id] = $exception;
                }

                $instances = array_values($by_recurrence);
            }

            foreach ($instances as &$inst) {
                $key = !empty($inst['recurrence_key']) ? $inst['recurrence_key'] : $this->to_utc_key($inst['start_time']);
                $inst['uid'] = $base_uid . '|' . $key;
            }
            unset($inst);

            $events = array_merge($events, $instances);
        }

        $this->last_series_base_uids = array_keys($masters);

        foreach ($events as &$event_item) {
            if (!empty($event_item['rrule']) && is_array($event_item['rrule'])) {
                $event_item['rrule'] = wp_json_encode($event_item['rrule']);
            }
        }
        unset($event_item);

        return $events;
    }
    
    private function parse_ical_date($date_str, $timezone = null) {
        if (empty($date_str)) {
            return null;
        }
        
        $timezone = $timezone ?: $this->timezone;
        $utc_timezone = new DateTimeZone('UTC');
        $original_date = $date_str;
        $date = false;
        $log_errors = defined('WP_DEBUG') && WP_DEBUG;
        $is_utc = false;
        
        try {
            // Handle different iCal date formats
            if (preg_match('/TZID=([^:]+):(\d{8}T?\d{0,6})/', $date_str, $matches)) {
                // Format with TZID: TZID=Europe/Berlin:20250118T220000
                try {
                    $timezone = new DateTimeZone($matches[1]);
                } catch (Exception $e) {
                    if ($log_errors) {
                        error_log('GCAL Importer: Invalid timezone: ' . $matches[1]);
                    }
                }
                $date_str = $matches[2];
            } elseif (strpos($date_str, 'TZID=') !== false) {
                // Fallback for other TZID formats
                $date_str = substr($date_str, strrpos($date_str, ':') + 1);
            }
            
            // Check if date ends with Z (UTC)
            if (substr($date_str, -1) === 'Z') {
                $is_utc = true;
                $date_str = substr($date_str, 0, -1); // Remove Z for parsing
            }
            
            // Clean up the date string
            $date_str = preg_replace('/[^0-9T]/', '', $date_str);
            
            // Handle different date formats
            if (strpos($date_str, 'T') !== false) {
                // Date with time (YYYYMMDDTHHMMSS)
                $format = 'Ymd\THis';
                $date = DateTime::createFromFormat($format, $date_str, $is_utc ? $utc_timezone : $timezone);
            } else {
                // Date only (YYYYMMDD)
                $date = DateTime::createFromFormat('Ymd', $date_str, $is_utc ? $utc_timezone : $timezone);
                if ($date) {
                    // Set to start of day
                    $date->setTime(0, 0, 0);
                }
            }
            
            if ($date) {
                // If the date was in UTC, convert to site timezone
                if ($is_utc) {
                    $date->setTimezone($timezone);
                }
                
                // Only log timezone conversion in debug mode
                if ($log_errors) {
                    error_log(sprintf(
                        'GCAL Importer: Date conversion - %s%s -> %s (%s)',
                        $original_date,
                        $is_utc ? ' (UTC)' : '',
                        $date->format('Y-m-d H:i:s'),
                        $timezone->getName()
                    ));
                }
                
                return $date->format('Y-m-d H:i:s');
            }
            
            if ($log_errors) {
                error_log('GCAL Importer: Failed to parse date format: ' . $original_date);
            }
            return $original_date; // Return original string if parsing fails
            
        } catch (Exception $e) {
            if ($log_errors) {
                error_log('GCAL Importer: Date parsing error (' . $e->getMessage() . ') for: ' . $original_date);
            }
            return $original_date; // Return original string on error
        }
    }
    
    private function unescape_ical_text($text) {
        $text = str_replace('\\\\', '\\', $text);
        $text = str_replace('\\n', "\n", $text);
        $text = str_replace('\\,', ',', $text);
        // Remove any <br> tags and replace with newlines
        $text = str_ireplace(['<br>', '<br/>', '<br />'], "\n", $text);
        $text = str_replace('\\;', ';', $text);
        return $text;
    }
    
    /**
     * Generate recurring event instances based on RRULE
     */
    private function generate_recurring_instances($event) {
        if (!isset($event['rrule'])) {
            return [];
        }
        
        $rrule = $event['rrule'];
        $instances = [];
        
        // Get the lookahead period from settings (default 3 months)
        $options = get_option('gcal_settings', []);
        $lookahead_months = isset($options['lookahead_months']) ? 
            max(1, min(12, intval($options['lookahead_months']))) : 3;
            
        // Create timezone objects
        $site_timezone = $this->timezone;
        
        try {
            // Parse the start and end times in site's timezone first
            $start = new DateTime($event['start_time'], $site_timezone);
            $end = new DateTime($event['end_time'], $site_timezone);
            
            // Calculate duration in seconds
            $duration = $end->getTimestamp() - $start->getTimestamp();
            
            // Set end date in site's timezone
            $end_date = new DateTime('now', $site_timezone);
            $end_date->modify("+{$lookahead_months} months");

            // Respect RRULE UNTIL if present
            if (isset($rrule['UNTIL']) && !empty($rrule['UNTIL'])) {
                $until_mysql = $this->parse_ical_date($rrule['UNTIL']);
                if (!empty($until_mysql)) {
                    $until_dt = new DateTime($until_mysql, $site_timezone);
                    if ($until_dt < $end_date) {
                        $end_date = $until_dt;
                    }
                }
            }
            
            // Get the current time in site's timezone for comparison
            $now = new DateTime('now', $site_timezone);
            
            // Handle different recurrence rules
            if (isset($rrule['FREQ']) && $rrule['FREQ'] === 'WEEKLY') {
                $count = 0;
                $max_instances = 100; // Safety limit
                if (isset($rrule['COUNT']) && is_numeric($rrule['COUNT'])) {
                    $max_instances = min($max_instances, max(0, (int)$rrule['COUNT']));
                }
                $interval = isset($rrule['INTERVAL']) ? max(1, intval($rrule['INTERVAL'])) : 1;
                
                // Get the original event's time components
                $original_time = $start->format('H:i:s');
                $original_day_of_week = (int)$start->format('w'); // 0 (Sun) to 6 (Sat)
                
                // If BYDAY is specified, use those days, otherwise use the original day of week
                $recurrence_days = [];
                if (isset($rrule['BYDAY'])) {
                    $by_days = explode(',', $rrule['BYDAY']);
                    $day_map = [
                        'SU' => 0, 'MO' => 1, 'TU' => 2, 'WE' => 3,
                        'TH' => 4, 'FR' => 5, 'SA' => 6
                    ];
                    foreach ($by_days as $day) {
                        if (isset($day_map[$day])) {
                            $recurrence_days[] = $day_map[$day];
                        }
                    }
                } else {
                    $recurrence_days = [$original_day_of_week];
                }
                
                // Sort days to ensure consistent ordering
                sort($recurrence_days);
                
                // Start from the original event date or now, whichever is later
                $current = max($start, $now);
                
                // Generate instances until we reach the end date or max instances
                while ($current <= $end_date && $count < $max_instances) {
                    // Get the current day of week (0-6)
                    $current_day = (int)$current->format('w');
                    
                    // Find the next occurrence day from our recurrence days
                    $next_day = null;
                    foreach ($recurrence_days as $day) {
                        if ($day > $current_day || ($day == $current_day && $current->format('Y-m-d') > $now->format('Y-m-d'))) {
                            $next_day = $day;
                            break;
                        }
                    }
                    
                    // If no next day in this week, take the first day of next week
                    if ($next_day === null && !empty($recurrence_days)) {
                        $next_day = $recurrence_days[0];
                        $days_to_add = 7 - $current_day + $next_day;
                        $current->modify("+{$days_to_add} days");
                    } elseif ($next_day !== null) {
                        $days_to_add = ($next_day + 7 - $current_day) % 7;
                        $current->modify("+{$days_to_add} days");
                    } else {
                        break; // No valid days found
                    }
                    
                    // Set the time to the original event time
                    $current->setTime(
                        (int)substr($original_time, 0, 2), // hours
                        (int)substr($original_time, 3, 2), // minutes
                        (int)substr($original_time, 6, 2)  // seconds
                    );
                    
                    // Only add if it's within our date range
                    if ($current <= $end_date) {
                        // Create a new instance for this occurrence
                        $new_event = $event;
                        
                        // Set the start time
                        $new_event['start_time'] = $current->format('Y-m-d H:i:s');
                        
                        // Calculate end time
                        $new_end = clone $current;
                        $new_end->modify("+{$duration} seconds");
                        $new_event['end_time'] = $new_end->format('Y-m-d H:i:s');
                        
                        $new_event['recurrence_id'] = $current->format('Y-m-d H:i:s');
                        $new_event['recurrence_key'] = $this->to_utc_key($new_event['recurrence_id']);
                        
                        $instances[] = $new_event;
                        $count++;
                    }
                    
                    // Move to next week if we've processed all days
                    if (in_array(6, $recurrence_days) && (int)$current->format('w') === 6) {
                        $current->modify("+{$interval} weeks");
                    } else {
                        $current->modify('+1 day');
                    }
                }
            }
            // Handle other recurrence frequencies (DAILY, MONTHLY, etc.)
            else if (isset($rrule['FREQ'])) {
                $count = 0;
                $max_instances = 100; // Safety limit
                if (isset($rrule['COUNT']) && is_numeric($rrule['COUNT'])) {
                    $max_instances = min($max_instances, max(0, (int)$rrule['COUNT']));
                }
                $interval = isset($rrule['INTERVAL']) ? max(1, intval($rrule['INTERVAL'])) : 1;
                
                // Start from the original event date
                $current = clone $start;
                
                // If the original event is in the past, find the next occurrence
                if ($current < $now) {
                    $current = clone $now;
                }
                
                // Generate instances until we reach the end date or max instances
                while ($current <= $end_date && $count < $max_instances) {
                    // Only add if it's within our date range
                    if ($current >= $now && $current <= $end_date) {
                        // Create a new instance for this occurrence
                        $new_event = $event;
                        
                        // Set the start time
                        $new_event['start_time'] = $current->format('Y-m-d H:i:s');
                        
                        // Calculate end time
                        $new_end = clone $current;
                        $new_end->modify("+{$duration} seconds");
                        $new_event['end_time'] = $new_end->format('Y-m-d H:i:s');
                        
                        $new_event['recurrence_id'] = $current->format('Y-m-d H:i:s');
                        $new_event['recurrence_key'] = $this->to_utc_key($new_event['recurrence_id']);
                        
                        $instances[] = $new_event;
                        $count++;
                    }
                    
                    // Move to next interval based on frequency
                    switch (strtoupper($rrule['FREQ'])) {
                        case 'DAILY':
                            $current->modify("+{$interval} days");
                            break;
                        case 'WEEKLY':
                            $current->modify("+{$interval} weeks");
                            break;
                        case 'MONTHLY':
                            $current->modify("+{$interval} months");
                            break;
                        case 'YEARLY':
                            $current->modify("+{$interval} years");
                            break;
                        default:
                            $current->modify("+1 week");
                    }
                }
            }
            
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('GCAL Importer: Error generating recurring instances - ' . $e->getMessage());
                error_log('Event data: ' . print_r($event, true));
                error_log('RRULE: ' . print_r($rrule, true));
            }
        }
        
        return $instances;
    }

    private function to_utc_key($mysql_datetime) {
        if (empty($mysql_datetime)) {
            return '';
        }

        try {
            $dt = new DateTime($mysql_datetime, $this->timezone);
            $dt->setTimezone(new DateTimeZone('UTC'));
            return $dt->format('Ymd\\THis\\Z');
        } catch (Exception $e) {
            return '';
        }
    }
    
    /**
     * Get day name from day number (0=Sunday, 6=Saturday)
     * 
     * @param int $dayNumber Day of week (0-6)
     * @return string Full day name in lowercase
     */
    private function getDayName($dayNumber) {
        $days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
        $dayNumber = (int)$dayNumber;
        return $days[$dayNumber] ?? 'sunday'; // Default to Sunday if invalid
    }
}
