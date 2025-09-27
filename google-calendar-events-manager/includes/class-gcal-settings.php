<?php
if (!defined('ABSPATH')) {
    exit;
}

class GCAL_Settings {
    private $options;
    private $page_slug = 'gcal-settings';
    
    public function __construct() {
        $this->options = get_option('gcal_settings');
        
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_notices', [$this, 'admin_notices']);
    }
    
    public function add_settings_page() {
        add_options_page(
            __('Google Calendar Einstellungen', 'gcal-events'),
            __('Google Calendar', 'gcal-events'),
            'manage_options',
            $this->page_slug,
            [$this, 'render_settings_page']
        );
        
        // Add import/export submenu
        add_submenu_page(
            null, // Don't add to menu
            __('Google Calendar Import', 'gcal-events'),
            '',
            'manage_options',
            'gcal-import',
            [$this, 'render_import_page']
        );
    }
    
    public function enqueue_admin_assets($hook) {
        if ($hook !== 'settings_page_' . $this->page_slug) {
            return;
        }
        
        wp_enqueue_style(
            'gcal-admin',
            GCAL_EVENTS_PLUGIN_URL . 'assets/css/admin.css',
            [],
            GCAL_EVENTS_VERSION
        );
        
        wp_enqueue_script(
            'gcal-admin',
            GCAL_EVENTS_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            GCAL_EVENTS_VERSION,
            true
        );
        
        wp_localize_script('gcal-admin', 'gcalAdmin', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('gcal_manual_import'),
            'i18n' => [
                'importing' => __('Importiere...', 'gcal-events'),
                'imported' => __('Import erfolgreich!', 'gcal-events'),
                'error' => __('Fehler beim Import', 'gcal-events')
            ]
        ]);
    }
    
    public function register_settings() {
        register_setting(
            'gcal_settings_group',
            'gcal_settings',
            [$this, 'sanitize_settings']
        );
        
        // General Settings Section
        add_settings_section(
            'gcal_general_section',
            __('Allgemeine Einstellungen', 'gcal-events'),
            [$this, 'render_general_section_info'],
            $this->page_slug
        );
        
        // ICS URL Field
        add_settings_field(
            'ics_url',
            __('ICS URL', 'gcal-events'),
            [$this, 'render_ics_url_field'],
            $this->page_slug,
            'gcal_general_section'
        );
        
        // Cache Duration Field
        add_settings_field(
            'cache_duration',
            __('Cache-Dauer (Stunden)', 'gcal-events'),
            [$this, 'render_cache_duration_field'],
            $this->page_slug,
            'gcal_general_section'
        );
        
        // Date Format Field
        add_settings_field(
            'date_format',
            __('Datumsformat', 'gcal-events'),
            [$this, 'render_date_format_field'],
            $this->page_slug,
            'gcal_general_section'
        );
        
        // Time Format Field
        add_settings_field(
            'time_format',
            __('Zeitformat', 'gcal-events'),
            [$this, 'render_time_format_field'],
            $this->page_slug,
            'gcal_display_section'
        );
    }
    
    public function sanitize_settings($input) {
        $sanitized = [];
        
        if (isset($input['ics_url'])) {
            $sanitized['ics_url'] = esc_url_raw($input['ics_url']);
            
            // Update the cron schedule if URL changed
            if ($sanitized['ics_url'] !== $this->options['ics_url'] ?? '') {
                wp_clear_scheduled_hook('gcal_daily_import');
                if (!empty($sanitized['ics_url'])) {
                    wp_schedule_event(time() + 3600, 'daily', 'gcal_daily_import');
                }
            }
        }
        
        $sanitized['cache_duration'] = isset($input['cache_duration']) 
            ? absint($input['cache_duration']) 
            : 24;
            
        $sanitized['date_format'] = isset($input['date_format'])
            ? sanitize_text_field($input['date_format'])
            : 'd.m.Y';
            
        $sanitized['time_format'] = isset($input['time_format'])
            ? sanitize_text_field($input['time_format'])
            : 'H:i';
        
        add_settings_error(
            'gcal_settings',
            'settings_updated',
            __('Einstellungen wurden gespeichert.', 'gcal-events'),
            'success'
        );
        
        return $sanitized;
    }
    
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Check if we should show the import tab
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <nav class="nav-tab-wrapper">
                <a href="?page=<?php echo $this->page_slug; ?>" 
                   class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Einstellungen', 'gcal-events'); ?>
                </a>
                <a href="?page=<?php echo $this->page_slug; ?>&tab=import" 
                   class="nav-tab <?php echo $active_tab === 'import' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Import', 'gcal-events'); ?>
                </a>
                <a href="?page=<?php echo $this->page_slug; ?>&tab=help" 
                   class="nav-tab <?php echo $active_tab === 'help' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Hilfe', 'gcal-events'); ?>
                </a>
            </nav>
            
            <div class="gcal-settings-container">
                <?php if ($active_tab === 'general'): ?>
                    <form method="post" action="options.php">
                        <?php
                        settings_fields('gcal_settings_group');
                        do_settings_sections($this->page_slug);
                        submit_button();
                        ?>
                    </form>
                <?php elseif ($active_tab === 'import'): ?>
                    <?php $this->render_import_page(); ?>
                <?php else: ?>
                    <?php $this->render_help_page(); ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    public function render_import_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="gcal-import-container">
            <h2><?php _e('Kalender importieren', 'gcal-events'); ?></h2>
            <p><?php _e('Hier können Sie die Kalenderdaten manuell importieren.', 'gcal-events'); ?></p>
            
            <div class="gcal-import-actions">
                <button id="gcal-manual-import" class="button button-primary">
                    <?php _e('Jetzt importieren', 'gcal-events'); ?>
                </button>
                <span id="gcal-import-status" class="gcal-status"></span>
            </div>
            
            <div class="gcal-import-logs">
                <h3><?php _e('Letzte Importe', 'gcal-events'); ?></h3>
                <div id="gcal-import-logs">
                    <?php $this->render_import_logs(); ?>
                </div>
            </div>
        </div>
        <?php
    }
    
    public function render_help_page() {
        ?>
        <div class="gcal-help-container">
            <h2><?php _e('Hilfe & Anleitung', 'gcal-events'); ?></h2>
            
            <h3><?php _e('Kurzanleitung', 'gcal-events'); ?></h3>
            <ol>
                <li><?php _e('Fügen Sie die ICS-URL Ihres Google Kalenders in den Einstellungen ein.', 'gcal-events'); ?></li>
                <li><?php _e('Fügen Sie den Shortcode <code>[gcal_events]</code> auf einer beliebigen Seite ein.', 'gcal-events'); ?></li>
                <li><?php _e('Optional: Passen Sie die Anzeige mit den verfügbaren Shortcode-Attributen an.', 'gcal-events'); ?></li>
            </ol>
            
            <h3><?php _e('Shortcode Attribute', 'gcal-events'); ?></h3>
            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php _e('Attribut', 'gcal-events'); ?></th>
                        <th><?php _e('Standard', 'gcal-events'); ?></th>
                        <th><?php _e('Beschreibung', 'gcal-events'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>limit</code></td>
                        <td>0 (kein Limit)</td>
                        <td><?php _e('Maximale Anzahl an anzuzeigenden Terminen', 'gcal-events'); ?></td>
                    </tr>
                    <tr>
                        <td><code>show_past</code></td>
                        <td>no</td>
                        <td><?php _e('Vergangene Termine anzeigen (yes/no)', 'gcal-events'); ?></td>
                    </tr>
                    <tr>
                        <td><code>category</code></td>
                        <td>''</td>
                        <td><?php _e('Nur Termine einer bestimmten Kategorie anzeigen', 'gcal-events'); ?></td>
                    </tr>
                </tbody>
            </table>
            
            <h3><?php _e('Beispiele', 'gcal-events'); ?></h3>
            <ul>
                <li><code>[gcal_events]</code> - <?php _e('Zeigt alle zukünftigen Termine an', 'gcal-events'); ?></li>
                <li><code>[gcal_events limit="5"]</code> - <?php _e('Zeigt die nächsten 5 Termine an', 'gcal-events'); ?></li>
                <li><code>[gcal_events show_past="yes"]</code> - <?php _e('Zeigt alle Termine inkl. vergangener an', 'gcal-events'); ?></li>
                <li><code>[gcal_events category="Konzert"]</code> - <?php _e('Zeigt nur Termine der Kategorie "Konzert" an', 'gcal-events'); ?></li>
            </ul>
            
            <h3><?php _e('Support', 'gcal-events'); ?></h3>
            <p><?php _e('Bei Fragen oder Problemen wenden Sie sich bitte an den Support.', 'gcal-events'); ?></p>
        </div>
        <?php
    }
    
    private function render_import_logs() {
        $logs = get_option('gcal_import_logs', []);
        
        if (empty($logs)) {
            echo '<p>' . __('Keine Import-Logs vorhanden.', 'gcal-events') . '</p>';
            return;
        }
        
        echo '<ul class="gcal-import-log-list">';
        foreach (array_slice($logs, 0, 10) as $log) { // Show last 10 logs
            $class = isset($log['success']) && $log['success'] ? 'success' : 'error';
            echo sprintf(
                '<li class="%s"><strong>%s</strong>: %s</li>',
                esc_attr($class),
                date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $log['time']),
                esc_html($log['message'])
            );
        }
        echo '</ul>';
    }
    
    public function admin_notices() {
        settings_errors('gcal_settings');
    }
    
    // Section Render Functions
    public function render_general_section_info() {
        _e('Allgemeine Einstellungen für den Google Kalender Import.', 'gcal-events');
    }
    
    // Field Render Functions
    public function render_ics_url_field() {
        $value = $this->options['ics_url'] ?? '';
        ?>
        <input type="url" id="gcal_ics_url" name="gcal_settings[ics_url]" 
               value="<?php echo esc_attr($value); ?>" 
               placeholder="https://calendar.google.com/calendar/ical/..." 
               class="regular-text">
        <p class="description">
            <?php _e('Die ICS-URL deines Google Kalenders. Diese findest du in den Kalendereinstellungen unter "Privatadresse im iCal-Format".', 'gcal-events'); ?>
        </p>
        <?php
    }
    
    public function render_cache_duration_field() {
        $value = $this->options['cache_duration'] ?? 24;
        ?>
        <input type="number" id="gcal_cache_duration" name="gcal_settings[cache_duration]" 
               value="<?php echo esc_attr($value); ?>" min="1" step="1" class="small-text">
        <p class="description">
            <?php _e('Wie viele Stunden sollen die Kalenderdaten zwischengespeichert werden?', 'gcal-events'); ?>
        </p>
        <?php
    }
    
    public function render_date_format_field() {
        $value = $this->options['date_format'] ?? 'd.m.Y';
        $formats = [
            'd.m.Y' => date_i18n('d.m.Y'),
            'Y-m-d' => date_i18n('Y-m-d'),
            'l, j. F Y' => date_i18n('l, j. F Y'),
            'custom' => __('Benutzerdefiniert', 'gcal-events')
        ];
        
        echo '<fieldset>';
        foreach ($formats as $format => $example) {
            $id = 'date_format_' . sanitize_title($format);
            $checked = ($format === $value) || ($format === 'custom' && !isset($formats[$value]));
            
            echo sprintf(
                '<label for="%s">' .
                '<input type="radio" name="gcal_settings[date_format]" id="%s" value="%s" %s> ' .
                '<code>%s</code> <span class="date-example">%s</span>' .
                '</label><br>',
                esc_attr($id),
                esc_attr($id),
                esc_attr($format),
                checked($checked, true, false),
                esc_html($format),
                esc_html($example)
            );
        }
        
        // Custom format input
        $custom_style = (isset($formats[$value]) && $value !== 'custom') ? ' style="display:none;"' : '';
        echo sprintf(
            '<div id="custom_date_format"%s>' .
            '<input type="text" name="gcal_settings[date_format]" value="%s" class="regular-text"> ' .
            '<span class="description">%s <a href="https://wordpress.org/support/article/formatting-date-and-time/" target="_blank">%s</a></span>' .
            '</div>',
            $custom_style,
            esc_attr($value),
            __('Verwenden Sie die PHP-Datumsformate.', 'gcal-events'),
            __('Mehr Informationen', 'gcal-events')
        );
        
        echo '</fieldset>';
    }
    
    public function render_time_format_field() {
        $value = $this->options['time_format'] ?? 'H:i';
        $formats = [
            'H:i' => date_i18n('H:i'),
            'g:i a' => date_i18n('g:i a'),
            'g:i A' => date_i18n('g:i A'),
            'custom' => __('Benutzerdefiniert', 'gcal-events')
        ];
        
        echo '<fieldset>';
        foreach ($formats as $format => $example) {
            $id = 'time_format_' . sanitize_title($format);
            $checked = ($format === $value) || ($format === 'custom' && !isset($formats[$value]));
            
            echo sprintf(
                '<label for="%s">' .
                '<input type="radio" name="gcal_settings[time_format]" id="%s" value="%s" %s> ' .
                '<code>%s</code> <span class="time-example">%s</span>' .
                '</label><br>',
                esc_attr($id),
                esc_attr($id),
                esc_attr($format),
                checked($checked, true, false),
                esc_html($format),
                esc_html($example)
            );
        }
        
        // Custom format input
        $custom_style = (isset($formats[$value]) && $value !== 'custom') ? ' style="display:none;"' : '';
        echo sprintf(
            '<div id="custom_time_format"%s>' .
            '<input type="text" name="gcal_settings[time_format]" value="%s" class="regular-text"> ' .
            '<span class="description">%s <a href="https://wordpress.org/support/article/formatting-date-and-time/" target="_blank">%s</a></span>' .
            '</div>',
            $custom_style,
            esc_attr($value),
            __('Verwenden Sie die PHP-Zeitformate.', 'gcal-events'),
            __('Mehr Informationen', 'gcal-events')
        );
        
        echo '</fieldset>';
    }
    
    // Helper method to add an import log entry
    public static function add_import_log($message, $success = true) {
        $logs = get_option('gcal_import_logs', []);
        
        // Keep only the last 50 logs
        $logs = array_slice($logs, 0, 49);
        
        array_unshift($logs, [
            'time' => time(),
            'message' => $message,
            'success' => $success
        ]);
        
        update_option('gcal_import_logs', $logs);
    }
}
