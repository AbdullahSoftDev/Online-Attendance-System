<?php

class CalendarSystem {
    private $mysql;
    
    public function __construct() {
        $this->mysql = new MySQLConfig();
        $this->create_calendar_table_manual();
        $this->create_events_table(); // Add events table
        $this->generate_current_month_calendar();
    }
    
    private function create_calendar_table_manual() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'attendance_calendar';
        
        // First check if table exists with correct structure
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        
        if ($table_exists) {
            // Check if columns exist
            $columns = $wpdb->get_results("DESCRIBE $table_name");
            $has_year = false;
            $has_month = false;
            $has_day = false;
            
            foreach ($columns as $column) {
                if ($column->Field == 'year') $has_year = true;
                if ($column->Field == 'month') $has_month = true;
                if ($column->Field == 'day') $has_day = true;
            }
            
            if ($has_year && $has_month && $has_day) {
                return true; // Table is correct
            } else {
                // Drop incorrect table
                $wpdb->query("DROP TABLE $table_name");
                $table_exists = false;
            }
        }
        
        // Create table with proper structure
        if (!$table_exists) {
            $sql = "CREATE TABLE $table_name (
                id INT(11) NOT NULL AUTO_INCREMENT,
                year INT(4) NOT NULL,
                month INT(2) NOT NULL,
                day INT(2) NOT NULL,
                day_type ENUM('working', 'holiday', 'half_day', 'special') DEFAULT 'working',
                description VARCHAR(255),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY unique_date (year, month, day)
            ) " . $wpdb->get_charset_collate() . ";";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            $result = dbDelta($sql);
        }
        
        return true;
    }

    // Create events table for date-specific events
    private function create_events_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'attendance_calendar_events';
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
            return true;
        }
        
        // Create events table
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            event_date date NOT NULL,
            event_type varchar(20) NOT NULL,
            event_note text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY event_date (event_date)
        ) " . $wpdb->get_charset_collate() . ";";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        return true;
    }
    
    private function generate_current_month_calendar() {
        $year = date('Y');
        $month = date('m');
        $this->generate_monthly_calendar($year, $month);
    }
    
    public function debug_calendar_month($year = null, $month = null) {
        if (!$year) $year = date('Y');
        if (!$month) $month = date('m');
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'attendance_calendar';
        
        $days = $wpdb->get_results($wpdb->prepare(
            "SELECT day, day_type, description FROM $table_name 
             WHERE year = %d AND month = %d 
             ORDER BY day ASC",
            $year, $month
        ), ARRAY_A);
        
        $settings = $this->get_calendar_settings();
        
        $html = '<div class="notice notice-warning">';
        $html .= '<h3>🔧 Calendar Debug for ' . date('F Y', strtotime("$year-$month-01")) . '</h3>';
        $html .= '<p><strong>Expected Settings:</strong> ' . print_r($settings, true) . '</p>';
        $html .= '<p><strong>Actual Calendar Data:</strong></p>';
        $html .= '<table style="width: 100%; border-collapse: collapse;">';
        $html .= '<tr style="background: #f1f1f1;"><th>Day</th><th>Day Type</th><th>Description</th><th>Weekday</th><th>Expected</th></tr>';
        
        foreach ($days as $day) {
            $date_string = sprintf('%04d-%02d-%02d', $year, $month, $day['day']);
            $weekday = strtolower(date('l', strtotime($date_string)));
            $expected = $settings[$weekday] ?? 'working';
            
            $html .= '<tr style="border-bottom: 1px solid #ddd;">';
            $html .= '<td>' . $day['day'] . '</td>';
            $html .= '<td>' . $day['day_type'] . '</td>';
            $html .= '<td>' . $day['description'] . '</td>';
            $html .= '<td>' . $weekday . '</td>';
            $html .= '<td>' . $expected . '</td>';
            $html .= '</tr>';
        }
        
        $html .= '</table>';
        $html .= '</div>';
        
        return $html;
    }
    
    public function get_calendar_settings() {
        $defaults = array(
            'sunday' => 'holiday',
            'monday' => 'working', 
            'tuesday' => 'working',
            'wednesday' => 'working',
            'thursday' => 'working',
            'friday' => 'working',
            'saturday' => 'holiday'
        );
        
        $settings = get_option('attendance_calendar_settings', $defaults);
        
        // Ensure all days are present
        foreach ($defaults as $day => $default_value) {
            if (!isset($settings[$day])) {
                $settings[$day] = $default_value;
            }
        }
        
        error_log("📋 Calendar Settings Loaded: " . print_r($settings, true));
        return $settings;
    }
    
    public function update_calendar_settings($settings) {
        update_option('attendance_calendar_settings', $settings);
        $this->generate_monthly_calendar(date('Y'), date('m'));
        return true;
    }
    
    public function generate_monthly_calendar($year, $month) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'attendance_calendar';
        $events_table = $wpdb->prefix . 'attendance_calendar_events';
        $settings = $this->get_calendar_settings();
        
        error_log("🔄 Generating calendar for $year-$month with settings: " . print_r($settings, true));
        
        // STEP 1: Get existing custom events for this month to preserve them
        $start_date = sprintf('%04d-%02d-01', $year, $month);
        $end_date = date('Y-m-t', strtotime($start_date));
        
        $existing_events = array();
        if ($wpdb->get_var("SHOW TABLES LIKE '$events_table'") == $events_table) {
            $events = $wpdb->get_results($wpdb->prepare(
                "SELECT event_date, event_type, event_note 
                 FROM $events_table 
                 WHERE event_date BETWEEN %s AND %s",
                $start_date, $end_date
            ), ARRAY_A);
            
            foreach ($events as $event) {
                $existing_events[$event['event_date']] = $event;
            }
        }
        
        // STEP 2: Clear existing calendar data for this month
        $wpdb->delete($table_name, array('year' => $year, 'month' => $month));
        
        $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        
        // STEP 3: Generate new calendar - USE SETTINGS AS DEFAULT, only keep custom events that DIFFER from settings
        for ($day = 1; $day <= $days_in_month; $day++) {
            $date_string = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $timestamp = strtotime($date_string);
            $day_of_week = strtolower(date('l', $timestamp));
            
            // Get default day type from settings
            $default_day_type = isset($settings[$day_of_week]) ? $settings[$day_of_week] : 'working';
            
            // Check if we have a custom event for this date
            $day_type = $default_day_type; // Start with default
            $description = ucfirst($day_of_week) . ' - ' . ucfirst(str_replace('_', ' ', $default_day_type));
            
            if (isset($existing_events[$date_string])) {
                $custom_event_type = $existing_events[$date_string]['event_type'];
                $custom_note = $existing_events[$date_string]['event_note'];
                
                // Only use custom event if it's DIFFERENT from the default setting
                if ($custom_event_type !== $default_day_type) {
                    $day_type = $custom_event_type;
                    $description = $custom_note ?: 'Custom: ' . ucfirst(str_replace('_', ' ', $day_type));
                    error_log("📅 Day $day ($day_of_week): Using CUSTOM event - $day_type (default was: $default_day_type)");
                } else {
                    // Custom event is same as default, so use default and remove the redundant custom event
                    $wpdb->delete($events_table, array('event_date' => $date_string));
                    error_log("📅 Day $day ($day_of_week): Removing redundant custom event, using DEFAULT - $default_day_type");
                }
            } else {
                error_log("📅 Day $day ($day_of_week): Using DEFAULT - $default_day_type");
            }
            
            $result = $wpdb->insert(
                $table_name,
                array(
                    'year' => $year,
                    'month' => $month,
                    'day' => $day,
                    'day_type' => $day_type,
                    'description' => $description
                ),
                array('%d', '%d', '%d', '%s', '%s')
            );
            
            if ($result === false) {
                error_log("❌ Failed to insert day $day: " . $wpdb->last_error);
            }
        }
        
        error_log("✅ Completed generating calendar for $year-$month");
        return true;
    }
    
    public function get_month_calendar($year, $month) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'attendance_calendar';
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE year = %d AND month = %d ORDER BY day ASC",
            $year, $month
        ), ARRAY_A);
        
        return $results;
    }
    
    public function is_working_day($date) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'attendance_calendar';
        $timestamp = strtotime($date);
        $year = date('Y', $timestamp);
        $month = date('m', $timestamp);
        $day = date('d', $timestamp);
        
        error_log("🔍 CALENDAR CHECK: Checking if $date is working day...");
        
        // FIRST: Check the main calendar table (this contains the weekly schedule)
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT day_type FROM $table_name WHERE year = %d AND month = %d AND day = %d",
            $year, $month, $day
        ), ARRAY_A);
        
        if ($result) {
            // 🎯 ONLY 'holiday' days block attendance
            $is_working = ($result['day_type'] !== 'holiday');
            error_log("📅 CALENDAR RECORD: $date -> " . $result['day_type'] . " -> " . ($is_working ? 'WORKING' : 'HOLIDAY'));
            
            // If it's a holiday in the main calendar, return immediately
            if (!$is_working) {
                return false;
            }
        } else {
            // If no record exists, generate calendar for this month
            error_log("🔄 CALENDAR: No record found for $date, generating calendar...");
            $this->generate_monthly_calendar($year, $month);
            
            // Try again after generation
            $result = $wpdb->get_row($wpdb->prepare(
                "SELECT day_type FROM $table_name WHERE year = %d AND month = %d AND day = %d",
                $year, $month, $day
            ), ARRAY_A);
            
            if ($result) {
                $is_working = ($result['day_type'] !== 'holiday');
                error_log("🔄 CALENDAR REGENERATED: $date -> " . $result['day_type'] . " -> " . ($is_working ? 'WORKING' : 'HOLIDAY'));
                if (!$is_working) {
                    return false;
                }
            }
        }
        
        // SECOND: Check for specific event overrides (individual date changes)
        $events_table = $wpdb->prefix . 'attendance_calendar_events';
        $event_override = $wpdb->get_row($wpdb->prepare(
            "SELECT event_type FROM $events_table WHERE event_date = %s",
            $date
        ), ARRAY_A);
        
        if ($event_override) {
            // 🎯 EVENT OVERRIDE FOUND - Use the custom event type
            $is_working = ($event_override['event_type'] !== 'holiday');
            error_log("🎯 CALENDAR OVERRIDE: $date -> " . $event_override['event_type'] . " -> " . ($is_working ? 'WORKING' : 'HOLIDAY'));
            return $is_working;
        }
        
        // FINAL FALLBACK: If we got here and don't have a clear answer, use settings
        if (!isset($is_working)) {
            $day_of_week = strtolower(date('l', $timestamp));
            $settings = $this->get_calendar_settings();
            $default_type = $settings[$day_of_week] ?? 'working';
            $is_working = ($default_type !== 'holiday');
            
            error_log("📋 CALENDAR FALLBACK: $date ($day_of_week) -> $default_type -> " . ($is_working ? 'WORKING' : 'HOLIDAY'));
        }
        
        return $is_working;
    }
    
    public function save_date_event($date, $event_type, $event_note = '') {
        global $wpdb;
        
        error_log("💾 Saving event: $date -> $event_type");
        
        // Force create table first
        $this->create_events_table();
        $table_name = $wpdb->prefix . 'attendance_calendar_events';
        
        $result = $wpdb->replace(
            $table_name,
            array(
                'event_date' => $date,
                'event_type' => $event_type,
                'event_note' => $event_note
            ),
            array('%s', '%s', '%s')
        );
        
        if ($result === false) {
            error_log("❌ Save failed: " . $wpdb->last_error);
            return array('success' => false, 'error' => $wpdb->last_error);
        }
        
        // 🚨 CRITICAL: REGENERATE CALENDAR FOR THIS MONTH TO APPLY THE EVENT
        $timestamp = strtotime($date);
        $year = date('Y', $timestamp);
        $month = date('m', $timestamp);
        $this->generate_monthly_calendar($year, $month);
        error_log("🔄 Calendar regenerated for $year-$month to apply event: $event_type");
        
        // 🚨 CRITICAL: ONLY ENFORCE IMMEDIATELY FOR HOLIDAYS (delete existing records)
        if ($event_type === 'holiday') {
            $admin_portal = new AdminPortal();
            $enforcement_result = $admin_portal->enforce_holiday_immediately($date);
            
            error_log("🚨 HOLIDAY ENFORCEMENT: " . $enforcement_result['message']);
            
            return [
                'success' => true, 
                'enforcement' => $enforcement_result,
                'message' => 'Holiday saved and enforced immediately!'
            ];
        } else {
            // For half_day, special, working - just update calendar, NO record deletion
            error_log("✅ $event_type event saved for $date - No attendance records affected");
            
            return [
                'success' => true,
                'message' => ucfirst(str_replace('_', ' ', $event_type)) . ' event saved successfully!'
            ];
        }
    }
    
    public function remove_date_event($date) {
        global $wpdb;
        
        error_log("🎯 CalendarSystem::remove_date_event called: date=$date");
        
        $table_name = $wpdb->prefix . 'attendance_calendar_events';
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        
        if (!$table_exists) {
            error_log("ℹ️ Table doesn't exist, nothing to remove");
            return array('success' => true);
        }
        
        // Remove the event
        $result = $wpdb->delete(
            $table_name,
            array('event_date' => $date),
            array('%s')
        );
        
        if ($result === false) {
            error_log("❌ Database Error: " . $wpdb->last_error);
            return array('success' => false, 'error' => $wpdb->last_error);
        }
        
        error_log("✅ Event removed successfully from database");
        
        // Get the default day type for this date
        $default_day_type = $this->get_default_day_type($date);
        
        // Update the main calendar table to reflect the default day type
        $year = date('Y', strtotime($date));
        $month = date('m', strtotime($date));
        $day = date('d', strtotime($date));
        
        $calendar_table = $wpdb->prefix . 'attendance_calendar';
        
        $update_result = $wpdb->update(
            $calendar_table,
            array(
                'day_type' => $default_day_type,
                'description' => ucfirst(strtolower(date('l', strtotime($date)))) . ' - ' . ucfirst(str_replace('_', ' ', $default_day_type))
            ),
            array(
                'year' => $year,
                'month' => $month,
                'day' => $day
            ),
            array('%s', '%s'),
            array('%d', '%d', '%d')
        );
        
        if ($update_result === false) {
            error_log("❌ Failed to update calendar table: " . $wpdb->last_error);
        } else {
            error_log("✅ Calendar table updated with default day type: $default_day_type");
        }
        
        return array('success' => true);
    }
    
    private function get_default_day_type($date) {
        $timestamp = strtotime($date);
        $day_of_week = strtolower(date('l', $timestamp));
        $settings = $this->get_calendar_settings();
        
        error_log("🔍 Getting default for $date ($day_of_week): " . ($settings[$day_of_week] ?? 'working'));
        
        return $settings[$day_of_week] ?? 'working';
    }
    
    public function emergency_regenerate_all_calendars() {
        $current_year = date('Y');
        $results = [];
        
        // Regenerate current year and next year
        for ($year = $current_year; $year <= $current_year + 1; $year++) {
            for ($month = 1; $month <= 12; $month++) {
                $this->generate_monthly_calendar($year, $month);
                $results[] = "Regenerated $year-" . str_pad($month, 2, '0', STR_PAD_LEFT);
            }
        }
        
        return $results;
    }
    
    // Original display_calendar method (keep for backward compatibility)
    public function display_calendar($year = null, $month = null) {
        if (!$year) $year = date('Y');
        if (!$month) $month = date('m');
        
        $calendar_data = $this->get_month_calendar($year, $month);
        $month_name = date('F Y', strtotime("$year-$month-01"));
        $first_day = date('N', strtotime("$year-$month-01"));
        $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $today = date('j');
        
        // If no calendar data, generate it
        if (empty($calendar_data)) {
            $this->generate_monthly_calendar($year, $month);
            $calendar_data = $this->get_month_calendar($year, $month);
        }
        
        $html = '<div class="simple-calendar">';
        $html .= '<div class="calendar-header">';
        $html .= '<h3>📅 ' . $month_name . '</h3>';
        $html .= '</div>';
        
        $html .= '<div class="calendar-legends">';
        $html .= '<div class="legend-item"><span class="color-dot working"></span> Working Day</div>';
        $html .= '<div class="legend-item"><span class="color-dot holiday"></span> Holiday</div>';
        $html .= '<div class="legend-item"><span class="color-dot half_day"></span> Half Day</div>';
        $html .= '<div class="legend-item"><span class="color-dot special"></span> Special</div>';
        $html .= '<div class="legend-item"><span class="color-dot today"></span> Today</div>';
        $html .= '</div>';
        
        $html .= '<div class="calendar-grid">';
        
        // Day headers
        $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        foreach ($days as $day) {
            $html .= '<div class="calendar-day-header">' . $day . '</div>';
        }
        
        // Empty cells for days before first day of month
        for ($i = 1; $i < $first_day; $i++) {
            $html .= '<div class="calendar-day empty"></div>';
        }
        
        // Days of the month
        for ($day = 1; $day <= $days_in_month; $day++) {
            $day_data = $this->get_day_data($calendar_data, $day);
            $day_type = $day_data['day_type'] ?? 'working';
            $is_today = ($day == $today && $month == date('m') && $year == date('Y'));
            
            $day_class = $day_type;
            if ($is_today) {
                $day_class .= ' today';
            }
            
            $html .= '<div class="calendar-day ' . $day_class . '">';
            $html .= '<span class="day-number">' . $day . '</span>';
            $html .= '<span class="day-dot"></span>';
            $html .= '</div>';
        }
        
        $html .= '</div>'; // .calendar-grid
        $html .= '</div>'; // .calendar-container
        
        return $html;
    }

    // NEW: Display calendar with clickable dates and popup events
    public function display_calendar_with_events($year = null, $month = null, $is_dashboard = false) {
        if (!$year) $year = date('Y');
        if (!$month) $month = date('m');
        
        $calendar_data = $this->get_month_calendar($year, $month);
        $date_events = $this->get_date_specific_events($year, $month);
        
        $month_name = date('F Y', strtotime("$year-$month-01"));
        $first_day = date('N', strtotime("$year-$month-01"));
        $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $today = date('j');
        $current_month = date('m');
        $current_year = date('Y');
        
        // If no calendar data, generate it
        if (empty($calendar_data)) {
            $this->generate_monthly_calendar($year, $month);
            $calendar_data = $this->get_month_calendar($year, $month);
        }

        $html = '<div class="simple-calendar">';
        $html .= '<div class="calendar-header">';
        $html .= '<h3>📅 ' . $month_name . '</h3>';
        $html .= '<p style="font-size: 12px; color: #666; margin: 5px 0;">Click any date to set custom event</p>';
        $html .= '</div>';
        
        $html .= '<div class="calendar-legends">';
        $html .= '<div class="legend-item"><span class="color-dot working"></span> Working Day</div>';
        $html .= '<div class="legend-item"><span class="color-dot holiday"></span> Holiday</div>';
        $html .= '<div class="legend-item"><span class="color-dot half_day"></span> Half Day</div>';
        $html .= '<div class="legend-item"><span class="color-dot special"></span> Special</div>';
        $html .= '<div class="legend-item"><span class="color-dot today"></span> Today</div>';
        $html .= '<div class="legend-item"><span style="color: #e74c3c; font-weight: bold;">★</span> Custom Event</div>';
        $html .= '</div>';
        
        // EVENT EDITING FORM - APPEARS BELOW CALENDAR WHEN DATE IS CLICKED
        $html .= '<div id="calendarEventForm" style="display: none; background: #f8f9fa; padding: 20px; border-radius: 10px; margin: 15px 0; border: 2px solid #3498db;">';
        $html .= '<h4 style="margin-top: 0; color: #2c3e50;">📅 Edit Date Event</h4>';
        
        // CRITICAL FIX: Use current URL for form action to stay on dashboard
        $current_url = admin_url('admin.php?page=attendance-admin');
        $html .= '<form method="post" action="' . $current_url . '">';
        $html .= wp_nonce_field('save_date_event_form', 'date_event_nonce', true, false);
        $html .= '<input type="hidden" name="save_date_event_form" value="1">';
        $html .= '<input type="hidden" id="eventDate" name="event_date">';
        
        $html .= '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">';
        $html .= '<div>';
        $html .= '<label style="display: block; font-weight: 600; margin-bottom: 5px;">Event Type:</label>';
        $html .= '<select id="eventType" name="event_type" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 5px;">';
        $html .= '<option value="working">🟢 Working Day</option>';
        $html .= '<option value="holiday">🔴 Holiday</option>';
        $html .= '<option value="half_day">🟡 Half Day</option>';
        $html .= '<option value="special">🔵 Special Event</option>';
        $html .= '</select>';
        $html .= '</div>';
        $html .= '<div>';
        $html .= '<label style="display: block; font-weight: 600; margin-bottom: 5px;">Event Note:</label>';
        $html .= '<input type="text" id="eventNote" name="event_note" placeholder="Optional note" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 5px;">';
        $html .= '</div>';
        $html .= '</div>';
        
        $html .= '<div style="display: flex; gap: 10px;">';
        $html .= '<button type="submit" name="action" value="save" style="background: #27ae60; color: white; border: none; padding: 10px 15px; border-radius: 5px; cursor: pointer; font-weight: 600;">💾 Save Event</button>';
        $html .= '<button type="submit" name="action" value="remove" style="background: #e74c3c; color: white; border: none; padding: 10px 15px; border-radius: 5px; cursor: pointer; font-weight: 600;">🗑️ Remove Event</button>';
        $html .= '<button type="button" onclick="hideEventForm()" style="background: #95a5a6; color: white; border: none; padding: 10px 15px; border-radius: 5px; cursor: pointer; font-weight: 600;">❌ Cancel</button>';
        $html .= '</div>';
        $html .= '</form>';
        $html .= '</div>';
        
        $html .= '<div class="calendar-grid">';
        
        // Day headers
        $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        foreach ($days as $day) {
            $html .= '<div class="calendar-day-header">' . $day . '</div>';
        }
        
        // Empty cells for days before first day of month
        for ($i = 1; $i < $first_day; $i++) {
            $html .= '<div class="calendar-day empty"></div>';
        }
        
        // Days of the month - CLICKABLE
        for ($day = 1; $day <= $days_in_month; $day++) {
            $date_string = sprintf('%04d-%02d-%02d', $year, $month, $day);
            
            // Get day data from calendar
            $day_data = $this->get_day_data($calendar_data, $day);
            $default_day_type = $day_data['day_type'] ?? 'working';
            
            // Check for date-specific event (overrides default)
            $current_event_type = $default_day_type;
            $has_custom_event = false;
            $event_note = '';
            
            if (isset($date_events[$date_string])) {
                $current_event_type = $date_events[$date_string]['event_type'];
                $has_custom_event = true;
                $event_note = $date_events[$date_string]['event_note'] ?? '';
            }
            
            $is_today = ($day == $today && $month == $current_month && $year == $current_year);
            
            $day_class = $current_event_type;
            if ($is_today) {
                $day_class .= ' today';
            }
            
            $html .= '<div class="calendar-day ' . $day_class . '" 
                          data-date="' . $date_string . '" 
                          data-event="' . $current_event_type . '" 
                          data-note="' . esc_attr($event_note) . '"
                          style="cursor: pointer; position: relative;" 
                          onclick="showEventForm(\'' . $date_string . '\', \'' . $current_event_type . '\', \'' . esc_attr($event_note) . '\')"
                          title="' . ($has_custom_event ? 'Custom: ' . ucfirst(str_replace('_', ' ', $current_event_type)) : 'Default: ' . ucfirst(str_replace('_', ' ', $current_event_type))) . '">
                <span class="day-number">' . $day . '</span>';
            
            // Show ★ indicator if there's a custom event (overriding default)
            if ($has_custom_event) {
                $html .= '<span class="custom-event-indicator" style="position: absolute; top: 2px; right: 2px; color: #e74c3c; font-size: 14px; font-weight: bold;">★</span>';
            }
            
            $html .= '</div>';
        }
        
        $html .= '</div>'; // .calendar-grid
        $html .= '</div>'; // .calendar-container
        
        // Add JavaScript
        $html .= $this->get_calendar_event_javascript();
        
        return $html;
    }

    private function get_calendar_event_javascript() {
        ob_start();
        ?>
        <script>
        function showEventForm(dateString, currentEvent, currentNote) {
            // Update form fields
            document.getElementById('eventDate').value = dateString;
            document.getElementById('eventType').value = currentEvent;
            document.getElementById('eventNote').value = currentNote || '';
            
            // Show the form
            document.getElementById('calendarEventForm').style.display = 'block';
            
            // Scroll to form
            document.getElementById('calendarEventForm').scrollIntoView({ 
                behavior: 'smooth', 
                block: 'start' 
            });
        }
        
        function hideEventForm() {
            document.getElementById('calendarEventForm').style.display = 'none';
        }
        </script>
        
         <style>
    .simple-calendar .calendar-day {
        border: 2px solid #e1e8ed;
        border-radius: 6px;
    }
    .simple-calendar .calendar-day.empty {
        border: none;
        background: transparent;
    }
    .simple-calendar .calendar-day.working { 
        border-color: #c3e6cb;
    }
    .simple-calendar .calendar-day.holiday { 
        border-color: #f1b0b7;
    }
    .simple-calendar .calendar-day.half_day { 
        border-color: #ffeaa7;
    }
    .simple-calendar .calendar-day.special { 
        border-color: #99ceff;
    }
    .simple-calendar .calendar-day.today { 
        border: 3px solid #007cba;
    }
    .simple-calendar .calendar-day:hover {
        border-color: #3498db;
    }
    </style>
        <?php
        return ob_get_clean();
    }
    
    // NEW: Get date-specific events for a month
    private function get_date_specific_events($year = null, $month = null) {
        global $wpdb;
        
        if (!$year) $year = date('Y');
        if (!$month) $month = date('m');
        
        $table_name = $wpdb->prefix . 'attendance_calendar_events';
        
        // Check if events table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            return array();
        }
        
        $start_date = sprintf('%04d-%02d-01', $year, $month);
        $end_date = date('Y-m-t', strtotime($start_date));
        
        $events = $wpdb->get_results($wpdb->prepare(
            "SELECT event_date, event_type, event_note 
             FROM $table_name 
             WHERE event_date BETWEEN %s AND %s",
            $start_date, $end_date
        ), ARRAY_A);
        
        $event_map = array();
        foreach ($events as $event) {
            $event_map[$event['event_date']] = array(
                'event_type' => $event['event_type'],
                'event_note' => $event['event_note']
            );
        }
        
        return $event_map;
    }
    
    private function get_day_data($calendar_data, $day) {
        foreach ($calendar_data as $data) {
            if ($data['day'] == $day) {
                return $data;
            }
        }
        return null;
    }
    
    // Emergency method to fix table
    public function emergency_fix_calendar() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'attendance_calendar';
        
        // Drop and recreate table
        $wpdb->query("DROP TABLE IF EXISTS $table_name");
        $this->create_calendar_table_manual();
        $this->generate_current_month_calendar();
        
        return "Calendar table fixed and regenerated!";
    }
    
    // Debug method to check table structure
    public function debug_table_structure() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'attendance_calendar';
        
        $structure = $wpdb->get_results("DESCRIBE $table_name");
        $data = $wpdb->get_results("SELECT * FROM $table_name LIMIT 5");
        
        return [
            'structure' => $structure,
            'sample_data' => $data,
            'table_exists' => $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name
        ];
    }
}
?>