<?php
if (!defined('ABSPATH')) {
    exit;
}

class GCAL_Importer {
    private $db;
    private $ics_url;
    private $timezone;
    
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
        foreach ($events as $event) {
            if ($this->db->update_or_insert_event($event)) {
                $imported++;
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
                    // Skip events that have already ended
                    if (isset($event['end_time']) && $event['end_time'] > current_time('mysql')) {
                        $events[] = $event;
                    }
                }
                $in_event = false;
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
                            
                        case 'LAST-MODIFIED':
                        case 'DTSTAMP':
                            $event['last_modified'] = $this->parse_ical_date($value);
                            break;
                    }
                }
            }
        }
        
        return $events;
    }
    
    private function parse_ical_date($value) {
        $is_utc = (substr($value, -1) === 'Z');
        $date_str = str_replace('Z', '', $value);
        
        // Handle different date formats
        if (strpos($date_str, 'T') !== false) {
            // Date with time
            $format = 'Ymd\THis';
        } else {
            // Date only
            $format = 'Ymd';
            $date_str .= 'T000000'; // Add midnight time
        }
        
        try {
            $date = DateTime::createFromFormat($format, $date_str, $is_utc ? new DateTimeZone('UTC') : null);
            
            if ($date) {
                if ($is_utc) {
                    $date->setTimezone($this->timezone);
                }
                return $date->format('Y-m-d H:i:s');
            }
        } catch (Exception $e) {
            error_log('GCAL Importer: Error parsing date: ' . $e->getMessage());
        }
        
        return current_time('mysql');
    }
    
    private function unescape_ical_text($text) {
        $text = str_replace('\\\\', '\\', $text);
        $text = str_replace('\\n', "\n", $text);
        $text = str_replace('\\,', ',', $text);
        $text = str_replace('\\;', ';', $text);
        return $text;
    }
}
