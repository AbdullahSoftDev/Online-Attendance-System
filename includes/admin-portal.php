<?php

class AdminPortal {
    private $mysql;
    private $recovery_key = 'admin_recovery_2024'; // Change this to something unique
    private $calendar;
    
    public function __construct() {
        $this->mysql = new MySQLConfig();
        $this->setup_admin_role();
        $this->calendar = new CalendarSystem();
        $this->setup_recovery_options();
        
        add_action('wp_ajax_save_date_event', array($this, 'handle_save_date_event'));
    	add_action('wp_ajax_remove_date_event', array($this, 'handle_remove_date_event'));
    }
    public function handle_save_date_event() {
    // Start output buffering to catch any errors
    ob_start();
    
    try {
        // Log everything
        error_log("🎯 SAVE_DATE_EVENT STARTED");
        
        // Check if WordPress is loaded
        if (!defined('ABSPATH')) {
            wp_send_json_error('WordPress not loaded');
            return;
        }
        
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('No admin permission');
            return;
        }
        
        error_log("✅ Permission check passed");
        
        // Check nonce
        if (!isset($_POST['nonce'])) {
            wp_send_json_error('No nonce provided');
            return;
        }
        
        if (!wp_verify_nonce($_POST['nonce'], 'save_date_event_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        error_log("✅ Nonce check passed");
        
        // Get data with defaults
        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
        $event_type = isset($_POST['event_type']) ? sanitize_text_field($_POST['event_type']) : '';
        $event_note = isset($_POST['event_note']) ? sanitize_text_field($_POST['event_note']) : '';
        
        error_log("📝 Data received: date=$date, event_type=$event_type, event_note=$event_note");
        
        // Basic validation
        if (empty($date) || empty($event_type)) {
            wp_send_json_error('Date and event type required');
            return;
        }
        
        // Test database connection
        global $wpdb;
        error_log("🔗 Database global available: " . (!empty($wpdb) ? 'yes' : 'no'));
        
        $table_name = $wpdb->prefix . 'attendance_calendar_events';
        error_log("📊 Table name: $table_name");
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        error_log("📋 Table exists: " . ($table_exists ? 'yes' : 'no'));
        
        if (!$table_exists) {
            error_log("🔄 Creating table...");
            $sql = "CREATE TABLE $table_name (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                event_date date NOT NULL,
                event_type varchar(20) NOT NULL,
                event_note text,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY event_date (event_date)
            ) " . $wpdb->get_charset_collate() . ";";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            $result = dbDelta($sql);
            error_log("🛠️ Table creation result: " . print_r($result, true));
        }
        
        // Simple database operation
        error_log("💾 Attempting database replace...");
        $result = $wpdb->replace(
            $table_name,
            array(
                'event_date' => $date,
                'event_type' => $event_type,
                'event_note' => $event_note
            ),
            array('%s', '%s', '%s')
        );
        
        error_log("📈 Database result: " . ($result === false ? 'FAILED' : 'SUCCESS'));
        
        if ($result === false) {
            $error = $wpdb->last_error;
            error_log("❌ Database error: $error");
            wp_send_json_error("Database error: $error");
            return;
        }
        
        error_log("✅ SUCCESS - Event saved");
        wp_send_json_success('Event saved successfully');
        
    } catch (Exception $e) {
        $error = $e->getMessage();
        error_log("💥 EXCEPTION: $error");
        wp_send_json_error("Exception: $error");
    } finally {
        // Capture any output that shouldn't be there
        $output = ob_get_clean();
        if (!empty($output)) {
            error_log("🚨 UNEXPECTED OUTPUT: $output");
        }
    }
}
public function enforce_holiday_immediately($date) {
    global $wpdb;
    
    // 1. Delete ALL existing attendance records for this date
    $deleted = $wpdb->delete(
        $wpdb->prefix . 'attendance_records',
        array('date' => $date),
        array('%s')
    );
    
    // 2. Update calendar table to reflect holiday
    $year = date('Y', strtotime($date));
    $month = date('m', strtotime($date));
    $day = date('d', strtotime($date));
    
    $wpdb->update(
        $wpdb->prefix . 'attendance_calendar',
        array(
            'day_type' => 'holiday',
            'description' => 'Holiday - Attendance blocked'
        ),
        array(
            'year' => $year,
            'month' => $month,
            'day' => $day
        ),
        array('%s', '%s'),
        array('%d', '%d', '%d')
    );
    
    // 3. Log the action
    error_log("🎯 HOLIDAY ENFORCED: Deleted $deleted records for $date");
    
    return [
        'success' => true,
        'deleted_records' => $deleted,
        'message' => "✅ Holiday enforced! Deleted $deleted attendance records and blocked future check-ins."
    ];
}
public function handle_remove_date_event() {
    ob_start();
    
    try {
        error_log("🎯 REMOVE_DATE_EVENT STARTED");
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('No permission');
            return;
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'remove_date_event_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
        
        if (empty($date)) {
            wp_send_json_error('No date provided');
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'attendance_calendar_events';
        
        // Only delete if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
            $result = $wpdb->delete(
                $table_name,
                array('event_date' => $date),
                array('%s')
            );
            error_log("🗑️ Delete result: " . ($result === false ? 'failed' : 'success'));
        }
        
        wp_send_json_success('Event removed');
        
    } catch (Exception $e) {
        error_log("💥 REMOVE EXCEPTION: " . $e->getMessage());
        wp_send_json_error("Exception: " . $e->getMessage());
    } finally {
        $output = ob_get_clean();
        if (!empty($output)) {
            error_log("🚨 REMOVE UNEXPECTED OUTPUT: $output");
        }
    }
}
    // Setup custom role for attendance admins
    private function setup_admin_role() {
        if (!get_role('attendance_admin')) {
            add_role('attendance_admin', 'Attendance Admin', array(
                'read' => true,
                'manage_attendance' => true,
            ));
        }
    }
    public function test_ajax_connection() {
    ?>
    <div class="notice notice-info">
        <h3>🔧 Testing Basic Connection</h3>
        <button onclick="testBasicAJAX()" class="button">Test Basic AJAX</button>
        <div id="basicTestResult"></div>
    </div>

    <script>
    function testBasicAJAX() {
        const formData = new FormData();
        formData.append('action', 'save_date_event');
        formData.append('date', '2024-01-01');
        formData.append('event_type', 'working');
        formData.append('event_note', 'test');
        formData.append('nonce', '<?php echo wp_create_nonce('save_date_event_nonce'); ?>');
        
        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => {
            document.getElementById('basicTestResult').innerHTML = 'Status: ' + response.status;
            return response.json();
        })
        .then(data => {
            document.getElementById('basicTestResult').innerHTML += '<br>Response: ' + JSON.stringify(data);
        })
        .catch(error => {
            document.getElementById('basicTestResult').innerHTML = 'Error: ' + error.message;
        });
    }
    </script>
    <?php
}
    // Setup recovery options in WordPress options table
    private function setup_recovery_options() {
        if (!get_option('attendance_recovery_key')) {
            update_option('attendance_recovery_key', $this->recovery_key);
        }
        if (!get_option('attendance_recovery_email')) {
            // Set default recovery email to first admin's email
            $admins = get_users(array('role' => 'attendance_admin', 'number' => 1));
            if (!empty($admins)) {
                update_option('attendance_recovery_email', $admins[0]->user_email);
            }
        }
    }
    public function emergency_ajax_test() {
    ?>
    <div class="notice notice-warning">
        <h3>🚨 Emergency AJAX Test</h3>
        <p>Testing basic PHP functionality...</p>
        <?php
        // Test basic PHP
        echo "<p>PHP Version: " . phpversion() . "</p>";
        
        // Test WordPress
        echo "<p>WordPress Loaded: " . (defined('ABSPATH') ? '✅ Yes' : '❌ No') . "</p>";
        
        // Test database
        global $wpdb;
        echo "<p>Database: " . ($wpdb ? '✅ Connected' : '❌ Not connected') . "</p>";
        
        // Test AJAX URL
        echo "<p>AJAX URL: " . admin_url('admin-ajax.php') . "</p>";
        
        // Test if we can call the method directly
        echo '<p><button onclick="testDirectCall()" class="button">Test Direct PHP Call</button></p>';
        ?>
    </div>

    <script>
    function testDirectCall() {
        // Test if we can even reach the PHP file
        jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', {
            action: 'test_connection'
        }, function(response) {
            alert('Basic AJAX: ' + JSON.stringify(response));
        }).fail(function() {
            alert('❌ Cannot reach admin-ajax.php');
        });
    }
    </script>
    <?php
}
    private function display_calendar_settings() {
    $settings = $this->calendar->get_calendar_settings();
    $this->handle_date_event_form();
    $this->handle_date_events_save();
    ?>
    <div class="wrap">
        <h1>📅 Calendar System Settings</h1>
        
        <div class="admin-nav">
            <a href="<?php echo admin_url('admin.php?page=attendance-admin'); ?>" class="button">📊 Dashboard</a>
            <a href="<?php echo admin_url('admin.php?page=attendance-admin&tab=calendar'); ?>" class="button button-primary">📅 Calendar Settings</a>
            <a href="<?php echo admin_url('admin.php?page=attendance-admin&tab=employees'); ?>" class="button">👥 Employees</a>
            <a href="<?php echo admin_url('admin.php?page=attendance-admin&action=logout'); ?>" class="button">🚪 Logout</a>
        </div>
        
        <!-- Calendar Weekly Settings Card -->
        <div class="dashboard-card" style="background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); border: 1px solid #e1e8ed; margin: 25px 0; width: 100%; box-sizing: border-box;">
            <h3 style="margin-top: 0; color: #2c3e50; border-bottom: 2px solid #f8f9fa; padding-bottom: 15px;">Calendar Weekly Settings</h3>
            
            <form method="post">
                <?php wp_nonce_field('save_calendar_settings', 'calendar_nonce'); ?>
                
                <div class="week-days-grid" style="display: flex; flex-direction: column; gap: 20px; margin: 20px 0;">
                    <!-- Row 1: Mon, Tue, Wed -->
                    <div class="week-day-row" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
                        <?php 
                        $days_row1 = ['monday', 'tuesday', 'wednesday'];
                        foreach($days_row1 as $day): 
                        ?>
                        <div class="day-setting" style="background: #f8f9fa; padding: 20px; border-radius: 8px; border: 2px solid #e1e8ed; text-align: center;">
                            <label style="display: block; font-weight: 600; margin-bottom: 10px; color: #2c3e50;"><?php echo ucfirst($day); ?></label>
                            <select name="calendar_settings[<?php echo $day; ?>]" style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid #ddd;">
                                <option value="working" <?php selected($settings[$day], 'working'); ?>>🟢 Working Day</option>
                                <option value="holiday" <?php selected($settings[$day], 'holiday'); ?>>🔴 Holiday</option>
                                <option value="half_day" <?php selected($settings[$day], 'half_day'); ?>>🟡 Half Day</option>
                                <option value="special" <?php selected($settings[$day], 'special'); ?>>🔵 Special Event</option>
                            </select>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Row 2: Thu, Fri, Sat -->
                    <div class="week-day-row" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
                        <?php 
                        $days_row2 = ['thursday', 'friday', 'saturday'];
                        foreach($days_row2 as $day): 
                        ?>
                        <div class="day-setting" style="background: #f8f9fa; padding: 20px; border-radius: 8px; border: 2px solid #e1e8ed; text-align: center;">
                            <label style="display: block; font-weight: 600; margin-bottom: 10px; color: #2c3e50;"><?php echo ucfirst($day); ?></label>
                            <select name="calendar_settings[<?php echo $day; ?>]" style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid #ddd;">
                                <option value="working" <?php selected($settings[$day], 'working'); ?>>🟢 Working Day</option>
                                <option value="holiday" <?php selected($settings[$day], 'holiday'); ?>>🔴 Holiday</option>
                                <option value="half_day" <?php selected($settings[$day], 'half_day'); ?>>🟡 Half Day</option>
                                <option value="special" <?php selected($settings[$day], 'special'); ?>>🔵 Special Event</option>
                            </select>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Row 3: Sunday (centered) -->
                    <div class="week-day-row" style="display: flex; justify-content: center;">
                        <div class="day-setting" style="background: #f8f9fa; padding: 20px; border-radius: 8px; border: 2px solid #e1e8ed; text-align: center; width: 200px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 10px; color: #2c3e50;">Sunday</label>
                            <select name="calendar_settings[sunday]" style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid #ddd;">
                                <option value="working" <?php selected($settings['sunday'], 'working'); ?>>🟢 Working Day</option>
                                <option value="holiday" <?php selected($settings['sunday'], 'holiday'); ?>>🔴 Holiday</option>
                                <option value="half_day" <?php selected($settings['sunday'], 'half_day'); ?>>🟡 Half Day</option>
                                <option value="special" <?php selected($settings['sunday'], 'special'); ?>>🔵 Special Event</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div style="text-align: center; margin-top: 25px;">
                    <button type="submit" name="save_calendar_settings" class="button button-primary" style="padding: 12px 30px; font-size: 16px; font-weight: 600;">
                        💾 Save Calendar Settings
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Current Month Preview Card -->
        <div class="dashboard-card" style="background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); border: 1px solid #e1e8ed; margin: 25px 0; width: 100%; box-sizing: border-box; text-align: center;">
            <h3 style="margin-top: 0; color: #2c3e50; border-bottom: 2px solid #f8f9fa; padding-bottom: 15px;">Current Month Preview</h3>
            <div style="display: inline-block; margin: 0 auto;">
                <?php echo $this->calendar->display_calendar_with_events(); ?>
            </div>
        </div>
    </div>
    
    <style>
    .dashboard-card {
        transition: all 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
    }
    .dashboard-card:hover {
        transform: translateY(-5px) scale(1.02);
        box-shadow: 0 12px 30px rgba(0,0,0,0.15);
        border-color: #3498db;
    }
    .day-setting:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 20px rgba(0,0,0,0.12);
        border-color: #3498db;
    }
    </style>
    <?php
}
    
private function handle_date_event_form() {
    // Check if our form was submitted
    if (isset($_POST['save_date_event_form']) && isset($_POST['date_event_nonce'])) {
        
        error_log("📝 Date event form submitted from DASHBOARD!");
        
        // Verify nonce
        if (!wp_verify_nonce($_POST['date_event_nonce'], 'save_date_event_form')) {
            echo '<div class="notice notice-error"><p>❌ Security check failed!</p></div>';
            return;
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>❌ Insufficient permissions!</p></div>';
            return;
        }
        
        // Get form data
        $date = isset($_POST['event_date']) ? sanitize_text_field($_POST['event_date']) : '';
        $event_type = isset($_POST['event_type']) ? sanitize_text_field($_POST['event_type']) : '';
        $event_note = isset($_POST['event_note']) ? sanitize_text_field($_POST['event_note']) : '';
        $action = isset($_POST['action']) ? sanitize_text_field($_POST['action']) : '';
        
        // Validate
        if (empty($date)) {
            echo '<div class="notice notice-error"><p>❌ No date selected!</p></div>';
            return;
        }
        
        if ($action === 'save') {
            // Save event
            error_log("💾 Saving event from dashboard: $date -> $event_type");
            $result = $this->calendar->save_date_event($date, $event_type, $event_note);
            
            if ($result['success']) {
                echo '<div class="notice notice-success"><p>✅ Event saved successfully for ' . $date . '!</p></div>';
                
                // Update the main calendar table immediately
                $year = date('Y', strtotime($date));
                $month = date('m', strtotime($date));
                $day = date('d', strtotime($date));
                
                global $wpdb;
                $calendar_table = $wpdb->prefix . 'attendance_calendar';
                
                $wpdb->update(
                    $calendar_table,
                    array(
                        'day_type' => $event_type,
                        'description' => 'Custom: ' . ucfirst(str_replace('_', ' ', $event_type)) . ($event_note ? " - $event_note" : '')
                    ),
                    array(
                        'year' => $year,
                        'month' => $month, 
                        'day' => $day
                    ),
                    array('%s', '%s'),
                    array('%d', '%d', '%d')
                );
                
                error_log("✅ Calendar table updated for $date");
                
            } else {
                echo '<div class="notice notice-error"><p>❌ Failed to save event for ' . $date . '! Error: ' . $result['error'] . '</p></div>';
            }
            
        } elseif ($action === 'remove') {
            // Remove event
            error_log("🗑️ Removing event from dashboard: $date");
            $result = $this->calendar->remove_date_event($date);
            
            if ($result['success']) {
                echo '<div class="notice notice-success"><p>✅ Event removed successfully for ' . $date . '! Reverted to default day type.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>❌ Failed to remove event for ' . $date . '! Error: ' . $result['error'] . '</p></div>';
            }
        }
        
        // IMPORTANT: We stay on the dashboard, no redirect to calendar settings
        // The page will refresh and show the updated calendar
    }
}
private function handle_calendar_settings() {
    if (isset($_POST['save_calendar_settings']) && isset($_POST['calendar_nonce'])) {
        
        // Verify nonce
        if (!wp_verify_nonce($_POST['calendar_nonce'], 'save_calendar_settings')) {
            echo '<div class="notice notice-error"><p>❌ Security check failed!</p></div>';
            return;
        }
        
        $settings = $_POST['calendar_settings'];
        
        // Validate settings
        $valid_types = ['working', 'holiday', 'half_day', 'special'];
        foreach ($settings as $day => $type) {
            if (!in_array($type, $valid_types)) {
                echo '<div class="notice notice-error"><p>❌ Invalid day type for ' . ucfirst($day) . '!</p></div>';
                return;
            }
        }
        
        // Save settings
        update_option('attendance_calendar_settings', $settings);
        
        echo '<div class="notice notice-success"><p>✅ Calendar settings saved successfully!</p></div>';
        echo '<div class="notice notice-info"><p>🔄 Regenerating ALL calendar months with new settings...</p></div>';
        
        // Force regeneration of current year and next year
        $current_year = date('Y');
        $current_month = date('m');
        
        // Show what we're generating
        echo '<div class="notice notice-info"><p>📅 Regenerating months: ';
        
        // Regenerate all months for current year and next year
        $regenerated_count = 0;
        for ($year = $current_year; $year <= $current_year + 1; $year++) {
            for ($month = 1; $month <= 12; $month++) {
                $this->calendar->generate_monthly_calendar($year, $month);
                echo " $year-" . str_pad($month, 2, '0', STR_PAD_LEFT);
                $regenerated_count++;
            }
        }
        
        echo '</p></div>';
        
        echo '<div class="notice notice-success"><p>✅ Successfully regenerated ' . $regenerated_count . ' months with new settings!</p></div>';
        
        // Show current settings for confirmation
        $saved_settings = get_option('attendance_calendar_settings');
        echo '<div class="notice notice-info">';
        echo '<p><strong>Current Weekday Settings:</strong></p>';
        echo '<ul style="margin-left: 20px;">';
        foreach ($saved_settings as $day => $type) {
            $icon = '';
            switch($type) {
                case 'working': $icon = '🟢'; break;
                case 'holiday': $icon = '🔴'; break;
                case 'half_day': $icon = '🟡'; break;
                case 'special': $icon = '🔵'; break;
            }
            echo '<li>' . $icon . ' ' . ucfirst($day) . ': ' . ucfirst(str_replace('_', ' ', $type)) . '</li>';
        }
        echo '</ul>';
        echo '</div>';
        
        // Force immediate page refresh to show updated calendar
        echo '<script>
            setTimeout(function() {
                window.location.href = "' . admin_url('admin.php?page=attendance-admin&tab=calendar') . '&refresh=' . time() . '";
            }, 3000);
        </script>';
    }
}
private function handle_date_events_save() {
    if (isset($_POST['save_date_events']) && isset($_POST['date_events_nonce'])) {
        
        // Verify nonce
        if (!wp_verify_nonce($_POST['date_events_nonce'], 'save_date_events')) {
            echo '<div class="notice notice-error"><p>❌ Security check failed!</p></div>';
            return;
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>❌ Insufficient permissions!</p></div>';
            return;
        }
        
        $date_events = $_POST['date_events'] ?? array();
        $saved_count = 0;
        $error_count = 0;
        
        foreach ($date_events as $date => $event_data) {
            $event_type = sanitize_text_field($event_data['event_type']);
            $event_note = sanitize_text_field($event_data['event_note']);
            
            // Save to database
            $result = $this->calendar->save_date_event($date, $event_type, $event_note);
            
            if ($result) {
                $saved_count++;
            } else {
                $error_count++;
            }
        }
        
        if ($error_count > 0) {
            echo '<div class="notice notice-warning"><p>⚠️ Saved ' . $saved_count . ' events, but ' . $error_count . ' failed.</p></div>';
        } else {
            echo '<div class="notice notice-success"><p>✅ Successfully saved ' . $saved_count . ' date events!</p></div>';
        }
    }
}
    public function display_admin_interface() {
        // Handle logout with JavaScript redirect to avoid header issues
        if (isset($_GET['action']) && $_GET['action'] === 'logout') {
            $this->logout();
            return; // Stop further execution
        }
        
        // Handle attendance approval actions
        if (isset($_POST['approve_attendance'])) {
            $this->handle_attendance_approval('approved');
        } elseif (isset($_POST['reject_attendance'])) {
            $this->handle_attendance_approval('rejected');
        }
        
        // Handle admin password reset
        if (isset($_POST['reset_admin_password'])) {
            $this->handle_admin_password_reset();
        }
        
        // Handle setup recovery credentials
        if (isset($_POST['setup_recovery'])) {
            $this->handle_setup_recovery();
            return;
        }
        
        // Handle admin access recovery - CHECK THIS FIRST
        if (isset($_POST['verify_recovery_key']) || isset($_POST['verify_recovery_email']) || isset($_POST['reset_admin_access']) || isset($_POST['recovery_method'])) {
            $this->handle_admin_access_recovery();
            return;
        }
        
        // Check if we need to show registration form
        if (!$this->mysql->check_admin_exists()) {
            $this->display_admin_registration();
            return;
        }
        
        if (isset($_POST['admin_login'])) {
            $this->handle_admin_login();
        } elseif (isset($_GET['tab']) && $_GET['tab'] === 'employees') {
            $this->display_employee_management();
            return;
        } elseif (isset($_GET['tab']) && $_GET['tab'] === 'approvals') {
            $this->display_approval_system();
            return;
        } elseif (isset($_GET['action']) && $_GET['action'] === 'recover_access') {
            $this->display_access_recovery_form();
            return;
        } elseif (isset($_GET['action']) && $_GET['action'] === 'setup_recovery') {
            $this->display_setup_recovery_form();
            return;
        }elseif (isset($_GET['tab']) && $_GET['tab'] === 'calendar') {
        $this->handle_calendar_settings();
        $this->display_calendar_settings();
        return;
    }
        
        if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in']) {
            $this->display_admin_dashboard();
        } else {
            $this->display_admin_login_form();
        }
    }
    
    private function display_admin_registration() {
        if (isset($_POST['register_admin'])) {
            $this->handle_admin_registration();
        }
        
        $recovery_key = get_option('attendance_recovery_key', $this->recovery_key);
        ?>
        <div class="wrap">
            <div class="notice notice-warning">
                <h2>🚨 First Time Setup - Create Administrator</h2>
                <p>No attendance administrator found. Please create the first administrator account to continue.</p>
            </div>
            
            <div class="card">
                <h2>Create Administrator Account</h2>
                <form method="post">
                    <table class="form-table">
                        <tr>
                            <th>Username:</th>
                            <td>
                                <input type="text" name="admin_username" required placeholder="Enter username">
                                <p class="description">This will be your login username</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Email:</th>
                            <td>
                                <input type="email" name="admin_email" required placeholder="Enter email address">
                                <p class="description">Used for account recovery</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Password:</th>
                            <td>
                                <input type="password" name="admin_password" required minlength="8" placeholder="Enter secure password">
                                <p class="description">Minimum 8 characters</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Confirm Password:</th>
                            <td>
                                <input type="password" name="confirm_password" required minlength="8" placeholder="Confirm your password">
                            </td>
                        </tr>
                    </table>
                    <button type="submit" name="register_admin" class="button button-primary">Create Administrator Account</button>
                </form>
            </div>
            
            <div class="card">
                <h3>🔐 Important Security Information</h3>
                <div class="recovery-info">
                    <h4>Save This Recovery Key:</h4>
                    <div class="recovery-key">
                        <?php echo esc_html($recovery_key); ?>
                    </div>
                    <p><strong>This key will be required if you lose admin access.</strong></p>
                    <p>Store it in a secure location. You'll need it to recover your account if you forget credentials.</p>
                    
                    <div class="recovery-warning" style="margin-top: 15px;">
                        <h4>📧 Email Recovery Option</h4>
                        <p>You can also recover access using your registered email address.</p>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <h3>System Information</h3>
                <p><strong>Database Status:</strong> <?php echo $this->mysql->test_connection(); ?></p>
                <p><strong>Admin Status:</strong> ❌ No administrator registered yet</p>
            </div>
        </div>
        <?php
    }
    
    private function display_admin_login_form() {
    ?>
    <style>
    /* ===== ADMIN LOGIN PORTAL STYLES ===== */
    .admin-login-portal {
        max-width: 500px;
        margin: 40px auto; /* Reduced margin for better spacing */
        padding: 40px 30px; /* Reduced padding */
        border: 1px solid #e1e8ed;
        border-radius: 20px;
        background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
        box-shadow: 0 20px 60px rgba(0,0,0,0.1);
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        position: relative;
        overflow: hidden;
        animation: fadeInUp 0.8s ease-out; /* Added animation */
    }

    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @keyframes bounce {
        0%, 20%, 50%, 80%, 100% { 
            transform: translateY(0); 
        }
        40% { 
            transform: translateY(-8px); 
        }
        60% { 
            transform: translateY(-4px); 
        }
    }

    .admin-login-portal::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
    }

    .admin-login-header {
        text-align: center;
        margin-bottom: 30px; /* Reduced margin */
        position: relative;
        z-index: 2;
        padding-top: 10px; /* Added padding */
    }

    .admin-login-header h2 {
        color: #2c3e50;
        font-size: 28px; /* Slightly smaller font */
        font-weight: 700;
        margin: 10px 0; /* Better spacing */
        background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        line-height: 1.2; /* Better line height */
    }

    .admin-login-subtitle {
        color: #7f8c8d;
        font-size: 14px; /* Slightly smaller */
        font-weight: 500;
        margin: 5px 0 15px 0; /* Better spacing */
        display: block;
    }

    .admin-login-badge {
        display: inline-block;
        background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
        color: white;
        padding: 6px 16px; /* Slightly smaller */
        border-radius: 20px;
        font-size: 12px; /* Smaller font */
        font-weight: 600;
        margin-top: 10px;
        box-shadow: 0 4px 15px rgba(231, 76, 60, 0.3);
        animation: bounce 2s infinite; /* Added bounce animation */
    }

    .admin-login-form {
        position: relative;
        z-index: 2;
    }

    .admin-form-group {
        margin-bottom: 25px; /* Reduced margin */
        position: relative;
    }

    .admin-form-group label {
        display: block;
        margin-bottom: 8px; /* Reduced margin */
        font-weight: 600;
        color: #2c3e50;
        font-size: 13px; /* Slightly smaller */
        text-transform: uppercase;
        letter-spacing: 0.5px; /* Reduced letter spacing */
    }

    .admin-form-input {
        width: 100%;
        padding: 16px 18px; /* Slightly reduced padding */
        border: 2px solid #e1e8ed;
        border-radius: 12px;
        font-size: 15px; /* Slightly smaller */
        transition: all 0.3s ease;
        background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
        box-sizing: border-box;
    }

    .admin-form-input:focus {
        border-color: #3498db;
        background: white;
        box-shadow: 0 0 0 4px rgba(52, 152, 219, 0.1);
        outline: none;
        transform: translateY(-2px);
    }

    .admin-form-input:hover {
        border-color: #a5b1c2;
        background: white;
        transform: translateY(-1px);
    }

    .admin-form-input::placeholder {
        color: #a5b1c2;
        font-size: 14px;
    }

    .admin-login-btn {
        width: 100%;
        padding: 18px; /* Slightly reduced padding */
        border: none;
        border-radius: 12px;
        font-size: 16px; /* Slightly smaller */
        font-weight: 700;
        cursor: pointer;
        transition: all 0.3s ease;
        background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
        color: white;
        box-shadow: 0 8px 25px rgba(52, 152, 219, 0.3);
        position: relative;
        overflow: hidden;
        text-transform: uppercase;
        letter-spacing: 0.5px; /* Reduced letter spacing */
    }

    .admin-login-btn:hover {
        background: linear-gradient(135deg, #2980b9 0%, #21618c 100%);
        transform: translateY(-3px);
        box-shadow: 0 12px 35px rgba(52, 152, 219, 0.4);
    }

    .admin-login-btn:active {
        transform: translateY(-1px);
    }

    .admin-login-actions {
        display: flex;
        justify-content: center;
        align-items: center;
        margin-top: 20px; /* Reduced margin */
        position: relative;
        z-index: 2;
    }

    .admin-recovery-link {
        color: #3498db;
        text-decoration: none;
        font-size: 13px; /* Slightly smaller */
        font-weight: 600;
        transition: all 0.3s ease;
        padding: 6px 12px; /* Reduced padding */
        border-radius: 6px;
        background: rgba(52, 152, 219, 0.1);
    }

    .admin-recovery-link:hover {
        color: #2980b9;
        text-decoration: none;
        background: rgba(52, 152, 219, 0.2);
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(52, 152, 219, 0.2);
    }

    .admin-login-features {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px; /* Reduced gap */
        margin-top: 25px; /* Reduced margin */
        position: relative;
        z-index: 2;
    }

    .admin-feature {
        text-align: center;
        padding: 12px; /* Reduced padding */
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        border-radius: 8px;
        transition: all 0.3s ease;
    }

    .admin-feature:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }

    .admin-feature-icon {
        font-size: 20px; /* Slightly smaller */
        margin-bottom: 6px; /* Reduced margin */
        display: block;
    }

    .admin-feature-text {
        font-size: 11px; /* Smaller font */
        color: #6c757d;
        font-weight: 500;
    }

    .recovery-info {
        background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
        padding: 15px; /* Reduced padding */
        border-radius: 10px;
        margin-bottom: 20px; /* Reduced margin */
        border-left: 4px solid #2196f3;
        text-align: center;
    }

    .recovery-info p {
        margin: 0;
        color: #1565c0;
        font-size: 14px; /* Slightly smaller */
        font-weight: 500;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .admin-login-portal {
            margin: 20px;
            padding: 30px 20px;
        }
        
        .admin-login-features {
            grid-template-columns: 1fr;
        }
        
        .admin-login-header h2 {
            font-size: 24px;
        }
        
        .admin-login-header {
            margin-bottom: 25px;
        }
    }

    @media (max-width: 480px) {
        .admin-login-portal {
            margin: 15px;
            padding: 25px 15px;
        }
        
        .admin-login-header h2 {
            font-size: 22px;
        }
        
        .admin-login-badge {
            font-size: 11px;
            padding: 5px 12px;
        }
    }
    </style>

    <div class="admin-login-portal">
        <div class="admin-login-header">
            <p class="admin-login-subtitle">Attendance System Administrator</p>
            <h2>Admin Portal</h2>
            <div class="admin-login-badge">
                🔐 Secure Admin Access
            </div>
        </div>
        
        <div class="admin-login-form">
            <div class="recovery-info">
                <p><strong>🔧 Please Enter Your Credentials</strong></p>
            </div>
            
            <form method="post">
                <div class="admin-form-group">
                    <label for="admin_username">ADMIN USERNAME:</label>
                    <input type="text" id="admin_username" name="admin_username" required 
                           class="admin-form-input"
                           placeholder="Enter your username">
                </div>
                
                <div class="admin-form-group">
                    <label for="admin_password">PASSWORD:</label>
                    <input type="password" id="admin_password" name="admin_password" required 
                           class="admin-form-input"
                           placeholder="Enter your password">
                </div>
                
                <button type="submit" name="admin_login" class="admin-login-btn">
                    🔐 Login to Admin Portal
                </button>
            </form>
            
            <div class="admin-login-actions">
                <a href="<?php echo admin_url('admin.php?page=attendance-admin&action=recover_access'); ?>" class="admin-recovery-link">
                    🔑 Recover Admin Access
                </a>
            </div>
            
            <div class="admin-login-features">
                <div class="admin-feature">
                    <span class="admin-feature-icon">📊</span>
                    <div class="admin-feature-text">Dashboard Analytics</div>
                </div>
                <div class="admin-feature">
                    <span class="admin-feature-icon">👥</span>
                    <div class="admin-feature-text">Employee Management</div>
                </div>
                <div class="admin-feature">
                    <span class="admin-feature-icon">✅</span>
                    <div class="admin-feature-text">Approval System</div>
                </div>
                <div class="admin-feature">
                    <span class="admin-feature-icon">🔒</span>
                    <div class="admin-feature-text">Secure Access</div>
                </div>
            </div>
        </div>
        
        <div style="text-align: center; margin-top: 25px; padding: 15px; border-top: 1px solid #e1e8ed;">
            <p style="color: #7f8c8d; font-size: 11px; margin: 0;">
                Attendance System v1.2 | Secure Admin Portal
            </p>
        </div>
    </div>
    <?php
}
    public function debug_ajax_system() {
    global $wpdb;
    ?>
    <div class="notice notice-info">
        <h3>🔧 AJAX Debug System</h3>
        
        <p><strong>WordPress Loaded:</strong> <?php echo defined('ABSPATH') ? '✅ Yes' : '❌ No'; ?></p>
        <p><strong>Database Connected:</strong> <?php echo !empty($wpdb) ? '✅ Yes' : '❌ No'; ?></p>
        <p><strong>User is Admin:</strong> <?php echo current_user_can('manage_options') ? '✅ Yes' : '❌ No'; ?></p>
        <p><strong>AJAX URL:</strong> <code><?php echo admin_url('admin-ajax.php'); ?></code></p>
        <p><strong>Plugin Path:</strong> <code><?php echo plugin_dir_path(__FILE__); ?></code></p>
        
        <div style="margin: 15px 0;">
            <button onclick="testSimpleAJAX()" class="button">Test Simple AJAX</button>
            <button onclick="testDirectURL()" class="button">Test Direct URL</button>
        </div>
        
        <div id="debugResult" style="padding: 10px; background: #f0f0f1; border-radius: 4px; min-height: 50px;"></div>
    </div>

    <script>
    function testSimpleAJAX() {
        document.getElementById('debugResult').innerHTML = 'Testing...';
        
        // Simple test with minimal data
        const formData = new FormData();
        formData.append('action', 'save_date_event');
        formData.append('date', '2024-01-01');
        formData.append('event_type', 'working');
        formData.append('event_note', 'test');
        formData.append('nonce', '<?php echo wp_create_nonce('save_date_event_nonce'); ?>');
        
        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            document.getElementById('debugResult').innerHTML = 'Status: ' + response.status + '<br>';
            return response.text(); // Get raw response first
        })
        .then(text => {
            document.getElementById('debugResult').innerHTML += 'Raw: ' + text.substring(0, 200);
            console.log('Raw response:', text);
            
            // Try to parse as JSON
            try {
                const data = JSON.parse(text);
                document.getElementById('debugResult').innerHTML += '<br>Parsed: ' + JSON.stringify(data);
            } catch (e) {
                document.getElementById('debugResult').innerHTML += '<br>JSON Parse Error: ' + e.message;
            }
        })
        .catch(error => {
            document.getElementById('debugResult').innerHTML = 'Fetch Error: ' + error.message;
        });
    }
    
    function testDirectURL() {
        window.open('<?php echo admin_url('admin-ajax.php'); ?>?action=save_date_event&date=2024-01-01&event_type=working&nonce=<?php echo wp_create_nonce('save_date_event_nonce'); ?>', '_blank');
    }
    </script>
    <?php
}
    private function display_access_recovery_form() {
        // Check if recovery credentials are set up
        $recovery_key = get_option('attendance_recovery_key');
        $recovery_email = get_option('attendance_recovery_email');
        
        if (!$recovery_key || !$recovery_email) {
            $this->display_recovery_setup_required();
            return;
        }

        // Check if we're already in a recovery method
        $recovery_method = isset($_POST['recovery_method']) ? $_POST['recovery_method'] : '';
        ?>
        <div class="wrap">
            <h1>🔓 Admin Access Recovery</h1>
            
            <div class="attendance-login-form">
                <h3>Recover Your Admin Access</h3>
                <div class="recovery-info">
                    <p><strong>Lost your admin credentials?</strong> Choose a recovery method below:</p>
                </div>
                
                <?php if (empty($recovery_method)): ?>
                <div class="recovery-options">
                    <div class="recovery-option recovery-option-key">
                        <h4>🔑 Recovery Key Method</h4>
                        <p>Use the secure recovery key that was provided during setup</p>
                        <form method="post">
                            <input type="hidden" name="recovery_method" value="key">
                            <button type="submit" class="recovery-option-btn">
                                Use Recovery Key
                            </button>
                        </form>
                    </div>
                    
                    <div class="recovery-option recovery-option-email">
                        <h4>📧 Email Recovery Method</h4>
                        <p>Recover access using your registered email address</p>
                        <form method="post">
                            <input type="hidden" name="recovery_method" value="email">
                            <button type="submit" class="recovery-option-btn email-option-btn">
                                Use Email Recovery
                            </button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php
                // Display the appropriate form based on recovery method
                if ($recovery_method === 'key') {
                    $this->display_recovery_key_form();
                } elseif ($recovery_method === 'email') {
                    $this->display_email_recovery_form();
                }
                ?>
                
                <div class="back-link">
                    <a href="<?php echo admin_url('admin.php?page=attendance-admin'); ?>">
                        ↩ Back to Login
                    </a>
                </div>
            </div>
        </div>
        <?php
    }
    
    private function display_recovery_setup_required() {
        ?>
        <div class="wrap">
            <h1>🔐 Recovery Setup Required</h1>
            
            <div class="attendance-login-form">
                <div class="recovery-warning">
                    <h3>⚠️ Recovery System Not Configured</h3>
                    <p>Before you can use the recovery system, you need to set up recovery credentials.</p>
                </div>
                
                <div class="setup-action">
                    <a href="<?php echo admin_url('admin.php?page=attendance-admin&action=setup_recovery'); ?>" class="setup-recovery-btn">
                        🛠️ Setup Recovery Credentials
                    </a>
                </div>
                
                <div class="back-link">
                    <a href="<?php echo admin_url('admin.php?page=attendance-admin'); ?>">
                        ↩ Back to Login
                    </a>
                </div>
            </div>
        </div>
        <?php
    }
    
    private function display_setup_recovery_form() {
        ?>
        <div class="wrap">
            <h1>🛠️ Setup Recovery Credentials</h1>
            
            <div class="attendance-login-form">
                <h3>Configure Recovery Options</h3>
                <div class="recovery-info">
                    <p>Set up recovery credentials to ensure you can regain access if you forget your login details.</p>
                </div>
                
                <form method="post">
                    <div class="form-group">
                        <label for="recovery_key">Recovery Key:</label>
                        <input type="text" id="recovery_key" name="recovery_key" required 
                               value="<?php echo esc_attr(get_option('attendance_recovery_key', 'admin_recovery_2024')); ?>"
                               placeholder="Enter a recovery key">
                        <p class="description">This key will be used to recover your account. Make it secure but memorable.</p>
                    </div>
                    
                    <div class="form-group">
                        <label for="recovery_email">Recovery Email:</label>
                        <input type="email" id="recovery_email" name="recovery_email" required 
                               value="<?php echo esc_attr(get_option('attendance_recovery_email', '')); ?>"
                               placeholder="Enter recovery email">
                        <p class="description">This email will be used for account recovery.</p>
                    </div>
                    
                    <button type="submit" name="setup_recovery" class="recovery-submit-btn save-btn">
                        💾 Save Recovery Credentials
                    </button>
                </form>
                
                <div class="back-link">
                    <a href="<?php echo admin_url('admin.php?page=attendance-admin'); ?>">
                        ↩ Back to Login
                    </a>
                </div>
            </div>
        </div>
        <?php
    }
    
    private function handle_setup_recovery() {
        $recovery_key = sanitize_text_field($_POST['recovery_key']);
        $recovery_email = sanitize_email($_POST['recovery_email']);
        
        if (empty($recovery_key) || empty($recovery_email)) {
            echo '<div class="recovery-error">❌ Both recovery key and email are required!</div>';
            $this->display_setup_recovery_form();
            return;
        }
        
        if (!is_email($recovery_email)) {
            echo '<div class="recovery-error">❌ Please enter a valid email address!</div>';
            $this->display_setup_recovery_form();
            return;
        }
        
        // Save recovery credentials
        update_option('attendance_recovery_key', $recovery_key);
        update_option('attendance_recovery_email', $recovery_email);
        
        echo '<div class="recovery-success">✅ Recovery credentials saved successfully!</div>';
        echo '<script>setTimeout(function(){ window.location.href = "' . admin_url('admin.php?page=attendance-admin&action=recover_access') . '"; }, 2000);</script>';
    }
    
    private function display_recovery_key_form() {
        ?>
        <div class="recovery-method-form">
            <h4>🔑 Recovery Key Verification</h4>
            <div class="recovery-info">
                <p>Enter the recovery key that was set up for this system.</p>
            </div>
            
            <?php 
            // Handle verification results
            if (isset($_POST['verify_recovery_key'])) {
                $this->handle_recovery_key_verification();
            } else {
                // Show the form
                ?>
                <form method="post">
                    <input type="hidden" name="recovery_method" value="key">
                    <div class="form-group">
                        <label for="recovery_key">Recovery Key:</label>
                        <input type="text" id="recovery_key" name="recovery_key" required 
                               placeholder="Enter the exact recovery key">
                        <p class="description">Enter the exact recovery key you set up during initial configuration</p>
                    </div>
                    <button type="submit" name="verify_recovery_key" class="recovery-submit-btn">
                        🔍 Verify Recovery Key
                    </button>
                </form>
                <?php
            }
            ?>
        </div>
        <?php
    }

    private function display_email_recovery_form() {
        ?>
        <div class="recovery-method-form">
            <h4>📧 Email Recovery Verification</h4>
            <div class="recovery-info">
                <p>Enter the email address associated with your admin account.</p>
            </div>
            
            <?php 
            // Handle verification results
            if (isset($_POST['verify_recovery_email'])) {
                $this->handle_email_recovery_verification();
            } else {
                // Show the form
                ?>
                <form method="post">
                    <input type="hidden" name="recovery_method" value="email">
                    <div class="form-group">
                        <label for="recovery_email">Registered Email:</label>
                        <input type="email" id="recovery_email" name="recovery_email" required 
                               placeholder="Enter your registered email address">
                        <p class="description">Enter the email address you used during registration</p>
                    </div>
                    <button type="submit" name="verify_recovery_email" class="recovery-submit-btn email-btn">
                        📧 Verify Email Address
                    </button>
                </form>
                <?php
            }
            ?>
        </div>
        <?php
    }
    
    private function handle_admin_access_recovery() {
        if (isset($_POST['verify_recovery_key'])) {
            $this->handle_recovery_key_verification();
        } elseif (isset($_POST['verify_recovery_email'])) {
            $this->handle_email_recovery_verification();
        } elseif (isset($_POST['reset_admin_access'])) {
            $this->handle_admin_access_reset();
        } else {
            // If no specific action, show the recovery form again
            $this->display_access_recovery_form();
        }
    }
    
    private function handle_recovery_key_verification() {
        $entered_key = sanitize_text_field($_POST['recovery_key']);
        $stored_key = get_option('attendance_recovery_key');
        
        if ($this->verify_recovery_key($entered_key, $stored_key)) {
            $_SESSION['recovery_verified'] = true;
            $_SESSION['recovery_method'] = 'key';
            $this->display_admin_reset_form();
        } else {
            echo '<div class="recovery-error">
                    <h4>❌ Invalid Recovery Key</h4>
                    <p>The recovery key you entered is incorrect. Please try again.</p>
                    <form method="post">
                        <input type="hidden" name="recovery_method" value="key">
                        <button type="submit" class="recovery-submit-btn">
                            🔄 Try Again
                        </button>
                    </form>
                  </div>';
        }
    }
    
    private function handle_email_recovery_verification() {
        $email = sanitize_email($_POST['recovery_email']);
        $stored_email = get_option('attendance_recovery_email');
        
        if (empty($email) || !is_email($email)) {
            echo '<div class="recovery-error">
                    <h4>❌ Invalid Email Address</h4>
                    <p>Please enter a valid email address.</p>
                    <form method="post">
                        <input type="hidden" name="recovery_method" value="email">
                        <button type="submit" class="recovery-submit-btn">
                            🔄 Try Again
                        </button>
                    </form>
                  </div>';
            return;
        }
        
        // Check if email matches stored recovery email
        if ($email === $stored_email) {
            // Find admin user by email
            $admin_user = $this->find_admin_by_email($email);
            
            if ($admin_user) {
                $_SESSION['recovery_verified'] = true;
                $_SESSION['recovery_method'] = 'email';
                $_SESSION['recovery_email'] = $email;
                $_SESSION['current_username'] = $admin_user->user_login;
                $this->display_admin_reset_form();
            } else {
                echo '<div class="recovery-error">
                        <h4>❌ Account Not Found</h4>
                        <p>No admin account found with that email address.</p>
                        <form method="post">
                            <input type="hidden" name="recovery_method" value="email">
                            <button type="submit" class="recovery-submit-btn">
                                🔄 Try Again
                            </button>
                        </form>
                      </div>';
            }
        } else {
            echo '<div class="recovery-error">
                    <h4>❌ Email Not Registered</h4>
                    <p>This email does not match the registered recovery email.</p>
                    <form method="post">
                        <input type="hidden" name="recovery_method" value="email">
                        <button type="submit" class="recovery-submit-btn">
                            🔄 Try Again
                        </button>
                    </form>
                  </div>';
        }
    }
    
    private function display_admin_reset_form() {
        if (!isset($_SESSION['recovery_verified']) || !$_SESSION['recovery_verified']) {
            echo '<div class="recovery-error">❌ Recovery verification required first!</div>';
            return;
        }
        ?>
        <div class="recovery-success">
            <h4>✅ <?php echo $_SESSION['recovery_method'] === 'key' ? 'Recovery Key Verified!' : 'Email Verified!'; ?></h4>
            <p>You can now reset the admin account.</p>
            
            <?php if ($_SESSION['recovery_method'] === 'email' && isset($_SESSION['current_username'])): ?>
            <div class="recovery-info">
                <p><strong>Current Username:</strong> <?php echo esc_html($_SESSION['current_username']); ?></p>
                <p>You can keep the current username or change it below.</p>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="recovery-reset-form">
            <form method="post">
                <div class="form-group">
                    <label for="new_admin_username">New Admin Username:</label>
                    <input type="text" id="new_admin_username" name="new_admin_username" 
                           value="<?php echo isset($_SESSION['current_username']) ? esc_attr($_SESSION['current_username']) : ''; ?>" 
                           required placeholder="Enter new username">
                    <p class="description">Enter a new username or keep the current one</p>
                </div>
                <div class="form-group">
                    <label for="new_admin_password">New Password:</label>
                    <input type="password" id="new_admin_password" name="new_admin_password" required 
                           placeholder="Enter new password">
                </div>
                <div class="form-group">
                    <label for="confirm_admin_password">Confirm New Password:</label>
                    <input type="password" id="confirm_admin_password" name="confirm_admin_password" required 
                           placeholder="Confirm new password">
                </div>
                <div class="form-group">
                    <label for="admin_email">Email Address:</label>
                    <input type="email" id="admin_email" name="admin_email" 
                           value="<?php echo isset($_SESSION['recovery_email']) ? esc_attr($_SESSION['recovery_email']) : ''; ?>" 
                           required placeholder="Enter email address">
                </div>
                <button type="submit" name="reset_admin_access" class="recovery-submit-btn reset-btn">
                    🔄 Reset Admin Account
                </button>
            </form>
        </div>
        <?php
    }
    
    private function handle_admin_access_reset() {
        if (!isset($_SESSION['recovery_verified']) || !$_SESSION['recovery_verified']) {
            echo '<div class="recovery-error">❌ Recovery verification required first!</div>';
            return;
        }
        
        $new_username = sanitize_text_field($_POST['new_admin_username']);
        $new_password = sanitize_text_field($_POST['new_admin_password']);
        $confirm_password = sanitize_text_field($_POST['confirm_admin_password']);
        $email = sanitize_email($_POST['admin_email']);
        
        // Validation
        if (empty($new_username) || empty($new_password) || empty($confirm_password) || empty($email)) {
            echo '<div class="recovery-error">❌ All fields are required!</div>';
            return;
        }
        
        if ($new_password !== $confirm_password) {
            echo '<div class="recovery-error">❌ Passwords do not match!</div>';
            return;
        }
        
        if (strlen($new_password) < 8) {
            echo '<div class="recovery-error">❌ Password must be at least 8 characters long!</div>';
            return;
        }
        
        if (!is_email($email)) {
            echo '<div class="recovery-error">❌ Please enter a valid email address!</div>';
            return;
        }
        
        // For email recovery: only update the specific admin
        // For key recovery: remove all admins and create new one
        if ($_SESSION['recovery_method'] === 'email' && isset($_SESSION['current_username'])) {
            $result = $this->update_existing_admin($_SESSION['current_username'], $new_username, $new_password, $email);
        } else {
            // Key recovery: complete reset
            $this->remove_existing_admins();
            $result = $this->mysql->register_admin($new_username, $new_password, $email);
        }
        
        if (isset($result['success'])) {
            // Update recovery email if changed
            if ($_SESSION['recovery_method'] === 'email' && $email !== $_SESSION['recovery_email']) {
                update_option('attendance_recovery_email', $email);
            }
            
            // Clear recovery session
            unset($_SESSION['recovery_verified']);
            unset($_SESSION['recovery_method']);
            unset($_SESSION['recovery_email']);
            unset($_SESSION['current_username']);
            
            echo '<div class="recovery-success">
                    <h4>✅ Admin Account Reset Successfully!</h4>
                    <div class="admin-credentials">
                        <h4>Your New Credentials:</h4>
                        <div class="credential-item">
                            <span class="credential-label">Username:</span>
                            <span class="credential-value">' . esc_html($new_username) . '</span>
                        </div>
                        <div class="credential-item">
                            <span class="credential-label">Password:</span>
                            <span class="credential-value">' . esc_html($new_password) . '</span>
                        </div>
                        <div class="credential-item">
                            <span class="credential-label">Email:</span>
                            <span class="credential-value">' . esc_html($email) . '</span>
                        </div>
                    </div>
                    <p><strong>Save these credentials securely!</strong></p>
                  </div>';
            
            echo '<script>setTimeout(function(){ window.location.href = "' . admin_url('admin.php?page=attendance-admin') . '"; }, 10000);</script>';
        } else {
            echo '<div class="recovery-error">❌ Error resetting admin account: ' . $result['error'] . '</div>';
        }
    }
    
    private function verify_recovery_key($entered_key, $stored_key) {
        // Also allow a backup key based on site URL (for emergency)
        $backup_key = 'backup_' . md5(get_site_url());
        
        return $entered_key === $stored_key || $entered_key === $backup_key;
    }
    
    private function find_admin_by_email($email) {
        $admins = get_users(array(
            'role' => 'attendance_admin',
            'search' => $email,
            'search_columns' => array('user_email')
        ));
        
        foreach ($admins as $admin) {
            if ($admin->user_email === $email) {
                return $admin;
            }
        }
        
        return null;
    }
    
    private function update_existing_admin($old_username, $new_username, $new_password, $new_email) {
        $user = get_user_by('login', $old_username);
        
        if (!$user) {
            return array('error' => 'Original admin user not found');
        }
        
        // Update username if changed
        if ($old_username !== $new_username) {
            global $wpdb;
            $wpdb->update(
                $wpdb->users,
                array('user_login' => $new_username),
                array('ID' => $user->ID)
            );
            clean_user_cache($user->ID);
        }
        
        // Update email
        wp_update_user(array(
            'ID' => $user->ID,
            'user_email' => $new_email
        ));
        
        // Update password
        wp_set_password($new_password, $user->ID);
        
        return array('success' => true);
    }
    
    private function remove_existing_admins() {
        // Get all users with attendance_admin role
        $admins = get_users(array(
            'role' => 'attendance_admin'
        ));
        
        foreach ($admins as $admin) {
            // Remove the attendance_admin role
            $admin->remove_role('attendance_admin');
        }
        
        return count($admins);
    }

    private function handle_admin_password_reset() {
        $username = sanitize_text_field($_POST['admin_username']);
        $old_password = sanitize_text_field($_POST['old_password']);
        $new_password = sanitize_text_field($_POST['new_password']);
        $confirm_password = sanitize_text_field($_POST['confirm_password']);
        
        // Validation
        if (empty($username) || empty($old_password) || empty($new_password) || empty($confirm_password)) {
            echo '<div class="notice notice-error"><p>All fields are required!</p></div>';
            return;
        }
        
        if ($new_password !== $confirm_password) {
            echo '<div class="notice notice-error"><p>New passwords do not match!</p></div>';
            return;
        }
        
        if (strlen($new_password) < 8) {
            echo '<div class="notice notice-error"><p>New password must be at least 8 characters long!</p></div>';
            return;
        }
        
        // Get the user by username
        $user = get_user_by('login', $username);
        
        if (!$user) {
            echo '<div class="notice notice-error"><p>Username not found!</p></div>';
            return;
        }
        
        // Check if user has attendance admin role
        if (!in_array('attendance_admin', $user->roles)) {
            echo '<div class="notice notice-error"><p>This user is not an attendance administrator!</p></div>';
            return;
        }
        
        // Verify the old password against WordPress password history
        if (!wp_check_password($old_password, $user->data->user_pass, $user->ID)) {
            echo '<div class="notice notice-error"><p>Previous password is incorrect!</p></div>';
            return;
        }
        
        // Check if new password is same as old password
        if ($old_password === $new_password) {
            echo '<div class="notice notice-error"><p>New password cannot be the same as old password!</p></div>';
            return;
        }
        
        // Update the password
        wp_set_password($new_password, $user->ID);
        
        echo '<div class="notice notice-success"><p>✅ Password reset successfully! You can now login with your new password.</p></div>';
        echo '<script>setTimeout(function(){ window.location.href = "' . admin_url('admin.php?page=attendance-admin') . '"; }, 2000);</script>';
    }
    
    private function handle_admin_login() {
        $username = sanitize_text_field($_POST['admin_username']);
        $password = sanitize_text_field($_POST['admin_password']);
        
        if ($this->mysql->verify_admin($username, $password)) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username'] = $username;
            echo '<div class="employee-success">
                    ✅ SUCCESS! Logged in as: ' . $username . '
                  </div>';
            echo '<script>setTimeout(function(){ window.location.href = "' . admin_url('admin.php?page=attendance-admin') . '"; }, 1000);</script>';
        } else {
            echo '<div class="employee-error">
                    ❌ Invalid username or password!
                  </div>';
        }
    }
    
    private function display_admin_dashboard() {
    // Check recovery setup status
    $this->mysql->auto_mark_absent_employees();
    $this->handle_date_event_form();
    $recovery_key = get_option('attendance_recovery_key');
    $recovery_email = get_option('attendance_recovery_email');
    $recovery_setup = $recovery_key && $recovery_email;
    
    // Handle emergency repair
    if (isset($_POST['emergency_repair'])) {
        $results = $this->mysql->emergency_repair();
        if (!empty($results)) {
            echo '<div class="notice notice-info"><p>🔧 Emergency Repair Results:</p><ul>';
            foreach ($results as $result) {
                echo '<li>' . esc_html($result) . '</li>';
            }
            echo '</ul></div>';
        } else {
            echo '<div class="notice notice-success"><p>✅ No issues found. Database is clean.</p></div>';
        }
    }
    
    if (isset($_POST['run_auto_absence'])) {
    $mysql = new MySQLConfig();
    $marked = $mysql->auto_mark_absent_employees();
    
    echo '<div class="notice notice-success"><p>✅ Auto-absence completed! Marked ' . $marked . ' employees as absent.</p></div>';
    
    // Force immediate page refresh to show new records
    echo '<meta http-equiv="refresh" content="1">';
}
    ?>
    <div class="wrap">
        <h1>Attendance System - Admin Dashboard</h1>
        
        <?php if (!$recovery_setup): ?>
        <div class="notice notice-warning">
            <h3>⚠️ Recovery System Not Configured</h3>
            <p>Your recovery system is not set up. Without this, you won't be able to recover your account if you forget credentials.</p>
            <p><a href="<?php echo admin_url('admin.php?page=attendance-admin&action=setup_recovery'); ?>" class="button button-primary">🛠️ Setup Recovery Now</a></p>
        </div>
        <?php endif; ?>
        
        <div class="admin-nav">
            <a href="<?php echo admin_url('admin.php?page=attendance-admin'); ?>" class="button button-primary">📊 Dashboard</a>
            <a href="<?php echo admin_url('admin.php?page=attendance-admin&tab=employees'); ?>" class="button">👥 Employees</a>
            <a href="<?php echo admin_url('admin.php?page=attendance-admin&tab=approvals'); ?>" class="button">✅ Approvals</a>
            <a href="<?php echo admin_url('admin.php?page=attendance-admin&tab=calendar'); ?>" class="button">📅 Calendar</a>
            <form method="post" style="display: inline;">
                <button type="submit" name="fix_calendar" class="button" style="background: #ff6b6b; color: white;">🛠️ Fix Calendar Table</button>
            </form>
            <form method="post" style="display: inline;">
                <button type="submit" name="emergency_repair" class="button emergency-repair" onclick="return confirm('Run emergency database repair? This will fix duplicate and orphaned records.')">🔧 Emergency Repair</button>
            </form>
            <a href="<?php echo admin_url('admin.php?page=attendance-admin&action=logout'); ?>" class="button">🚪 Logout</a>
        </div>

        <?php
        // Handle calendar fix
        if (isset($_POST['fix_calendar'])) {
            $result = $this->calendar->emergency_fix_calendar();
            echo '<div class="notice notice-success"><p>✅ ' . $result . '</p></div>';
        }
        ?>
       
        <!-- DASHBOARD GRID WITH EQUAL CARDS AND HOVER EFFECTS -->
        <div class="dashboard-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px; margin: 25px 0;">
            <!-- Row 1: Today's Summary -->
            <div class="dashboard-card" style="background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); border: 1px solid #e1e8ed; min-height: 300px; display: flex; flex-direction: column; transition: all 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94); cursor: pointer;">
                <h3 style="margin-top: 0; color: #2c3e50; border-bottom: 2px solid #f8f9fa; padding-bottom: 15px;">📈 Today's Summary</h3>
                <div style="flex: 1; display: flex; flex-direction: column;">
                    <?php $this->display_today_summary(); ?>
                </div>
            </div>
            
            <!-- Row 1: Monthly Summary -->
            <div class="dashboard-card" style="background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); border: 1px solid #e1e8ed; min-height: 300px; display: flex; flex-direction: column; transition: all 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94); cursor: pointer;">
                <h3 style="margin-top: 0; color: #2c3e50; border-bottom: 2px solid #f8f9fa; padding-bottom: 15px;">📅 Monthly Summary</h3>
                <div style="flex: 1; display: flex; flex-direction: column;">
                    <?php $this->display_monthly_summary(); ?>
                </div>
            </div>
            
            <!-- Row 2: Pending Approvals -->
            <div class="dashboard-card" style="background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); border: 1px solid #e1e8ed; min-height: 300px; display: flex; flex-direction: column; transition: all 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94); cursor: pointer;">
                <h3 style="margin-top: 0; color: #2c3e50; border-bottom: 2px solid #f8f9fa; padding-bottom: 15px;">⏳ Pending Approvals</h3>
                <div style="flex: 1; display: flex; flex-direction: column;">
                    <?php $this->display_pending_approvals_quick(); ?>
                </div>
            </div>
            
            <!-- Row 2: Monthly Calendar -->
            <div class="dashboard-card" style="background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); border: 1px solid #e1e8ed; min-height: 300px; display: flex; flex-direction: column; transition: all 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94); cursor: pointer;">
                <h3 style="margin-top: 0; color: #2c3e50; border-bottom: 2px solid #f8f9fa; padding-bottom: 15px;">📅 Monthly Calendar</h3>
                <div style="flex: 1; display: flex; flex-direction: column;">
                    <?php echo $this->calendar->display_calendar_with_events(null, null, true); ?>
                </div>
            </div>
        </div>
        
        <?php $this->display_attendance_table(); ?>
    </div>
    
    <style>
    .dashboard-card:hover {
        transform: translateY(-5px) scale(1.02);
        box-shadow: 0 12px 30px rgba(0,0,0,0.15);
        border-color: #3498db;
    }
    
    .day-setting:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 20px rgba(0,0,0,0.12);
        border-color: #3498db;
    }
    </style>
    <?php
}
   // Add this method to your CalendarSystem class
public function emergency_calendar_reset() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'attendance_calendar';
    $events_table = $wpdb->prefix . 'attendance_calendar_events';
    
    // Clear all calendar data
    $wpdb->query("TRUNCATE TABLE $table_name");
    
    // Get current settings
    $settings = $this->get_calendar_settings();
    
    // Regenerate current year and next year
    $current_year = date('Y');
    $regenerated_count = 0;
    
    for ($year = $current_year; $year <= $current_year + 1; $year++) {
        for ($month = 1; $month <= 12; $month++) {
            $this->generate_monthly_calendar($year, $month);
            $regenerated_count++;
        }
    }
    
    return "✅ Calendar completely reset! Regenerated $regenerated_count months with current settings: " . print_r($settings, true);
}
    private function display_today_summary() {
        $today = current_time('Y-m-d');
        $summary = $this->mysql->get_attendance_summary($today);
        
        $total_employees = $this->mysql->get_employees_count();
        $checked_in = 0;
        $checked_out = 0;
        $pending_approvals = 0;
        $late_count = 0;
        $absent_count = 0;
        
        foreach ($summary as $record) {
            if (!empty($record['in_time'])) $checked_in++;
            if (!empty($record['out_time'])) $checked_out++;
            if ($record['status'] === 'pending') $pending_approvals++;
            if ($record['late_status'] === 'late') $late_count++;
            if ($record['late_status'] === 'absent') $absent_count++;
        }
        ?>
        <div class="stat-item">
            <span>Total Employees:</span>
            <span class="stat-value"><?php echo $total_employees; ?></span>
        </div>
        <div class="stat-item">
            <span>Checked In Today:</span>
            <span class="stat-value" style="color: #28a745;"><?php echo $checked_in; ?></span>
        </div>
        <div class="stat-item">
            <span>Checked Out Today:</span>
            <span class="stat-value" style="color: #dc3545;"><?php echo $checked_out; ?></span>
        </div>
        <div class="stat-item">
            <span>Pending Approvals:</span>
            <span class="stat-value" style="color: #ffc107;"><?php echo $pending_approvals; ?></span>
        </div>
        <div class="stat-item">
            <span>Late Today:</span>
            <span class="stat-value" style="color: #fd7e14;"><?php echo $late_count; ?></span>
        </div>
        <div class="stat-item">
            <span>Absent Today:</span>
            <span class="stat-value" style="color: #dc3545;"><?php echo $absent_count; ?></span>
        </div>
        <?php
    }
    
    private function display_monthly_summary() {
        $monthly = $this->mysql->get_monthly_summary();
        $current_month = date('F Y');
        ?>
        <div class="stat-item">
            <span>Month:</span>
            <span class="stat-value"><?php echo $current_month; ?></span>
        </div>
        <div class="stat-item">
            <span>Total Records:</span>
            <span class="stat-value"><?php echo $monthly['total_records'] ?? 0; ?></span>
        </div>
        <div class="stat-item">
            <span>Approved:</span>
            <span class="stat-value" style="color: #28a745;"><?php echo $monthly['approved'] ?? 0; ?></span>
        </div>
        <div class="stat-item">
            <span>Pending:</span>
            <span class="stat-value" style="color: #ffc107;"><?php echo $monthly['pending'] ?? 0; ?></span>
        </div>
        <div class="stat-item">
            <span>Rejected:</span>
            <span class="stat-value" style="color: #dc3545;"><?php echo $monthly['rejected'] ?? 0; ?></span>
        </div>
        <div class="stat-item">
            <span>Late Count:</span>
            <span class="stat-value" style="color: #fd7e14;"><?php echo $monthly['late_count'] ?? 0; ?></span>
        </div>
        <div class="stat-item">
            <span>Absent Count:</span>
            <span class="stat-value" style="color: #dc3545;"><?php echo $monthly['absent_count'] ?? 0; ?></span>
        </div>
        <?php
    }
    
    private function display_pending_approvals_quick() {
    // Force refresh of pending data
    $pending = $this->mysql->get_pending_approvals();
    
    if (empty($pending)) {
        echo '<p>✅ No pending approvals!</p>';
        return;
    }
    
    echo '<p>📋 <strong>' . count($pending) . ' records need approval</strong></p>';
    echo '<div style="max-height: 600px; overflow-y: auto; margin-top: 10px;">';
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr><th>Employee</th><th>Date</th><th>Check-in</th><th>Status</th><th>Action</th></tr></thead>';
    echo '<tbody>';
    
    foreach (array_slice($pending, 0, 8) as $record) { // Show more records
        $late_status = '';
        $status_color = '';
        
        switch($record['late_status']) {
            case 'late':
                $late_status = '⏰ Late';
                $status_color = 'style="color: #fd7e14;"';
                break;
            case 'absent':
                $late_status = '❌ Absent';
                $status_color = 'style="color: #dc3545;"';
                break;
            default:
                $late_status = '✅ On Time';
                $status_color = 'style="color: #28a745;"';
        }
        
        echo '<tr>';
        echo '<td>' . esc_html($record['employee_name']) . ' (' . esc_html($record['employee_id']) . ')</td>';
        echo '<td>' . esc_html($record['date']) . '</td>';
        echo '<td>' . esc_html($record['in_time'] ? date('H:i', strtotime($record['in_time'])) : 'N/A') . '</td>';
        echo '<td ' . $status_color . '>' . $late_status . '</td>';
        echo '<td>';
        echo '<form method="post" style="display: inline;">';
        echo '<input type="hidden" name="record_id" value="' . $record['id'] . '">';
        echo '<button type="submit" name="approve_attendance" class="button button-small" style="background: #28a745; color: white; margin-right: 2px; padding: 2px 6px; font-size: 11px;">✅</button>';
        echo '<button type="submit" name="reject_attendance" class="button button-small" style="background: #dc3545; color: white; padding: 2px 6px; font-size: 11px;">❎</button>';
        echo '</form>';
        echo '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '</div>';
    
    if (count($pending) > 8) {
        echo '<p style="margin-top: 10px;"><a href="' . admin_url('admin.php?page=attendance-admin&tab=approvals') . '" class="button">View All ' . count($pending) . ' Pending</a></p>';
    }
}
    
    private function display_approval_system() {
    $pending = $this->mysql->get_pending_approvals();
    
    if (isset($_POST['emergency_calendar_reset'])) {
        $result = $this->calendar->emergency_calendar_reset();
        echo '<div class="notice notice-success"><p>' . $result . '</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>Attendance Approval System</h1>
        
        <div class="admin-nav">
            <a href="<?php echo admin_url('admin.php?page=attendance-admin'); ?>" class="button">📊 Dashboard</a>
            <a href="<?php echo admin_url('admin.php?page=attendance-admin&tab=employees'); ?>" class="button">👥 Manage Employees</a>
            <a href="<?php echo admin_url('admin.php?page=attendance-admin&tab=approvals'); ?>" class="button button-primary">✅ Pending Approvals</a>
            <form method="post" style="display: inline;">
                <button type="submit" name="emergency_calendar_reset" class="button" style="background: #ff6b6b; color: white;" onclick="return confirm('⚠️ This will reset ALL calendar data and regenerate from settings. Continue?')">🔄 Emergency Calendar Reset</button>
            </form>
            <a href="<?php echo admin_url('admin.php?page=attendance-admin&action=logout'); ?>" class="button">🚪 Logout</a>
        </div>
        
        <!-- Full Screen Approvals Card -->
        <div class="dashboard-card" style="background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); border: 1px solid #e1e8ed; margin: 25px 0; width: 100%; box-sizing: border-box;">
            <h3 style="margin-top: 0; color: #2c3e50; border-bottom: 2px solid #f8f9fa; padding-bottom: 15px;">⏳ Pending Approval Requests</h3>
            
            <?php if (empty($pending)): ?>
                <div class="notice notice-success">
                    <p>✅ No pending approvals! All attendance records are approved.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="wp-list-table widefat fixed striped" style="width: 100%; margin: 0;">
                        <thead>
                            <tr>
                                <th>Employee ID</th>
                                <th>Employee Name</th>
                                <th>Date</th>
                                <th>Scheduled Time</th>
                                <th>Check-in Time</th>
                                <th>Check-out Time</th>
                                <th>Late Status</th>
                                <th>Late Minutes</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending as $record): ?>
                                <?php
                                $late_status = '';
                                $status_color = '';
                                
                                switch($record['late_status']) {
                                    case 'late':
                                        $late_status = '⏰ Late';
                                        $status_color = 'style="color: #fd7e14;"';
                                        break;
                                    case 'absent':
                                        $late_status = '❌ Absent';
                                        $status_color = 'style="color: #dc3545;"';
                                        break;
                                    default:
                                        $late_status = '✅ On Time';
                                        $status_color = 'style="color: #28a745;"';
                                }
                                ?>
                                <tr>
                                    <td><?php echo esc_html($record['employee_id']); ?></td>
                                    <td><?php echo esc_html($record['employee_name']); ?></td>
                                    <td><?php echo esc_html($record['date']); ?></td>
                                    <td><?php echo esc_html($record['scheduled_time']); ?></td>
                                    <td><?php echo esc_html($record['in_time'] ? date('H:i:s', strtotime($record['in_time'])) : 'N/A'); ?></td>
                                    <td><?php echo esc_html($record['out_time'] ? date('H:i:s', strtotime($record['out_time'])) : 'Not checked out'); ?></td>
                                    <td <?php echo $status_color; ?>><?php echo $late_status; ?></td>
                                    <td><?php echo $record['late_minutes']; ?> mins</td>
                                    <td>
                                        <form method="post" style="display: inline;">
                                            <input type="hidden" name="record_id" value="<?php echo $record['id']; ?>">
                                            <button type="submit" name="approve_attendance" class="button button-small" style="background: #28a745; color: white; margin-right: 2px; padding: 2px 6px; font-size: 11px;">✅</button>
                                            <button type="submit" name="reject_attendance" class="button button-small" style="background: #dc3545; color: white; padding: 2px 6px; font-size: 11px;">❎</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <style>
    .dashboard-card {
        transition: all 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
    }
    .dashboard-card:hover {
        transform: translateY(-5px) scale(1.02);
        box-shadow: 0 12px 30px rgba(0,0,0,0.15);
        border-color: #3498db;
    }
    </style>
    <?php
}
    
    private function handle_attendance_approval($status) {
        if (!isset($_POST['record_id'])) {
            echo '<div class="notice notice-error"><p>❌ No record selected!</p></div>';
            return;
        }
        
        $record_id = intval($_POST['record_id']);
        $result = $this->mysql->update_attendance_status($record_id, $status);
        
        if (isset($result['success'])) {
            $status_text = $status === 'approved' ? 'approved' : 'rejected';
            echo '<div class="notice notice-success"><p>✅ Attendance record ' . $status_text . ' successfully!</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>❌ Error: ' . $result['error'] . '</p></div>';
        }
    }
    
    private function display_employee_management() {
        if (isset($_POST['add_employee'])) {
            $this->handle_add_employee();
        } elseif (isset($_POST['bulk_add_employees'])) {
            $this->handle_bulk_add_employees();
        } elseif (isset($_POST['reset_password'])) {
            $this->handle_password_reset();
        } elseif (isset($_POST['update_employee'])) {
            $this->handle_update_employee();
        } elseif (isset($_POST['delete_employee'])) {
            $this->handle_delete_employee();
        } elseif (isset($_GET['edit_employee'])) {
            $this->display_edit_employee_form();
            return;
        }
        ?>
        <div class="wrap">
            <h1>Employee Management</h1>
            
            <div class="admin-nav">
                <a href="<?php echo admin_url('admin.php?page=attendance-admin'); ?>" class="button">📊 Dashboard</a>
                <a href="<?php echo admin_url('admin.php?page=attendance-admin&tab=employees'); ?>" class="button button-primary">👥 Manage Employees</a>
                <a href="<?php echo admin_url('admin.php?page=attendance-admin&tab=approvals'); ?>" class="button">✅ Pending Approvals</a>
                <a href="<?php echo admin_url('admin.php?page=attendance-admin&action=logout'); ?>" class="button">🚪 Logout</a>
            </div>
            
            <div class="card">
                <h3>Add Single Employee</h3>
                <form method="post">
                    <table class="form-table">
                        <tr>
                            <th>Employee ID</th>
                            <td><input type="text" name="employee_id" required></td>
                        </tr>
                        <tr>
                            <th>Full Name</th>
                            <td><input type="text" name="employee_name" required></td>
                        </tr>
                        <tr>
                            <th>Email</th>
                            <td><input type="email" name="employee_email" required></td>
                        </tr>
                        <tr>
                            <th>Scheduled Time</th>
                            <td>
                                <input type="time" name="scheduled_time" value="09:00" required>
                                <p class="description">Default work start time for this employee</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Initial Password</th>
                            <td><input type="text" name="employee_password" required></td>
                        </tr>
                    </table>
                    <button type="submit" name="add_employee" class="button button-primary">Add Employee</button>
                </form>
            </div>
            
            <div class="card">
                <h3>Bulk Add Employees</h3>
                <form method="post">
                    <textarea name="bulk_employees" rows="10" cols="50" placeholder="Format: employee_id,name,email,scheduled_time,password
Example:
emp005,John Doe,john@company.com,09:00,john123
emp006,Jane Smith,jane@company.com,09:30,jane123"></textarea>
                    <p class="description">Enter one employee per line in the format: ID,Name,Email,Scheduled Time,Password</p>
                    <button type="submit" name="bulk_add_employees" class="button button-primary">Bulk Add Employees</button>
                </form>
            </div>
            
            <div class="card">
                <h3>Reset Employee Password</h3>
                <form method="post">
                    <table class="form-table">
                        <tr>
                            <th>Employee ID</th>
                            <td><input type="text" name="reset_employee_id" required></td>
                        </tr>
                        <tr>
                            <th>Email</th>
                            <td><input type="email" name="reset_email" required></td>
                        </tr>
                    </table>
                    <button type="submit" name="reset_password" class="button">Reset Password & Send Email</button>
                </form>
            </div>
            
            <div class="card">
                <h3>Current Employees</h3>
                <?php $this->display_employee_list(); ?>
            </div>
        </div>
        <?php
    }
    
    private function display_employee_list() {
        $employees = $this->mysql->get_all_employees();
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Employee ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Scheduled Time</th>
                    <th>Created Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($employees)): ?>
                    <?php foreach ($employees as $employee): ?>
                        <tr>
                            <td><?php echo esc_html($employee['employee_id']); ?></td>
                            <td><?php echo esc_html($employee['name']); ?></td>
                            <td><?php echo esc_html($employee['email']); ?></td>
                            <td><?php echo esc_html($employee['scheduled_time']); ?></td>
                            <td><?php echo esc_html($employee['created_at']); ?></td>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page=attendance-admin&tab=employees&edit_employee=' . $employee['employee_id']); ?>" class="button button-small">Edit</a>
                                <form method="post" style="display: inline-block; margin-left: 5px;">
                                    <input type="hidden" name="delete_employee_id" value="<?php echo esc_attr($employee['employee_id']); ?>">
                                    <button type="submit" name="delete_employee" class="button button-small button-secondary" 
                                            onclick="return confirm('Are you sure you want to delete employee <?php echo esc_js($employee['name']); ?>? This action cannot be undone.')">
                                        Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6">No employees found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }
    
    private function display_edit_employee_form() {
        $employee_id = sanitize_text_field($_GET['edit_employee']);
        $employee = $this->mysql->get_employee($employee_id);
        
        if (!$employee) {
            echo '<div class="notice notice-error"><p>Employee not found!</p></div>';
            $this->display_employee_management();
            return;
        }
        ?>
        <div class="wrap">
            <h1>Edit Employee: <?php echo esc_html($employee['name']); ?></h1>
            
            <div class="admin-nav">
                <a href="<?php echo admin_url('admin.php?page=attendance-admin&tab=employees'); ?>" class="button">← Back to Employees</a>
            </div>
            
            <div class="card">
                <form method="post">
                    <input type="hidden" name="employee_id" value="<?php echo esc_attr($employee['employee_id']); ?>">
                    <table class="form-table">
                        <tr>
                            <th>Employee ID</th>
                            <td>
                                <strong><?php echo esc_html($employee['employee_id']); ?></strong>
                                <p class="description">Employee ID cannot be changed</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Full Name</th>
                            <td>
                                <input type="text" name="employee_name" value="<?php echo esc_attr($employee['name']); ?>" required>
                            </td>
                        </tr>
                        <tr>
                            <th>Email</th>
                            <td>
                                <input type="email" name="employee_email" value="<?php echo esc_attr($employee['email']); ?>" required>
                            </td>
                        </tr>
                        <tr>
                            <th>Scheduled Time</th>
                            <td>
                                <input type="time" name="scheduled_time" value="<?php echo esc_attr($employee['scheduled_time']); ?>" required>
                                <p class="description">Default work start time for this employee</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Change Password</th>
                            <td>
                                <input type="password" name="employee_password" placeholder="Leave blank to keep current password">
                                <p class="description">Enter new password only if you want to change it</p>
                            </td>
                        </tr>
                    </table>
                    <button type="submit" name="update_employee" class="button button-primary">Update Employee</button>
                    <a href="<?php echo admin_url('admin.php?page=attendance-admin&tab=employees'); ?>" class="button">Cancel</a>
                </form>
            </div>
        </div>
        <?php
    }
    
    private function handle_add_employee() {
        $employee_id = sanitize_text_field($_POST['employee_id']);
        $name = sanitize_text_field($_POST['employee_name']);
        $email = sanitize_email($_POST['employee_email']);
        $password = sanitize_text_field($_POST['employee_password']);
        $scheduled_time = sanitize_text_field($_POST['scheduled_time']);
        
        // Basic validation
        if (empty($employee_id) || empty($name) || empty($email) || empty($password) || empty($scheduled_time)) {
            echo '<div class="notice notice-error"><p>All fields are required!</p></div>';
            return;
        }
        
        if (!is_email($email)) {
            echo '<div class="notice notice-error"><p>Please enter a valid email address!</p></div>';
            return;
        }
        
        $result = $this->mysql->add_employee($employee_id, $name, $email, $password, $scheduled_time);
        
        if (isset($result['success'])) {
            echo '<div class="notice notice-success"><p>✅ Employee added successfully!</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>❌ Error: ' . $result['error'] . '</p></div>';
        }
    }
    
    private function handle_update_employee() {
        $employee_id = sanitize_text_field($_POST['employee_id']);
        $name = sanitize_text_field($_POST['employee_name']);
        $email = sanitize_email($_POST['employee_email']);
        $password = sanitize_text_field($_POST['employee_password']);
        $scheduled_time = sanitize_text_field($_POST['scheduled_time']);
        
        // Basic validation
        if (empty($employee_id) || empty($name) || empty($email) || empty($scheduled_time)) {
            echo '<div class="notice notice-error"><p>All fields except password are required!</p></div>';
            return;
        }
        
        if (!is_email($email)) {
            echo '<div class="notice notice-error"><p>Please enter a valid email address!</p></div>';
            return;
        }
        
        $result = $this->mysql->update_employee_credentials($employee_id, $name, $email, $password, $scheduled_time);
        
        if (isset($result['success'])) {
            echo '<div class="notice notice-success"><p>✅ Employee updated successfully!</p></div>';
            echo '<script>setTimeout(function(){ window.location.href = "' . admin_url('admin.php?page=attendance-admin&tab=employees') . '"; }, 1000);</script>';
        } else {
            echo '<div class="notice notice-error"><p>❌ Error: ' . $result['error'] . '</p></div>';
        }
    }
    
    private function handle_delete_employee() {
        $employee_id = sanitize_text_field($_POST['delete_employee_id']);
        
        if (empty($employee_id)) {
            echo '<div class="notice notice-error"><p>Employee ID is required!</p></div>';
            return;
        }
        
        $result = $this->mysql->delete_employee($employee_id);
        
        if (isset($result['success'])) {
            echo '<div class="notice notice-success"><p>✅ Employee deleted successfully!</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>❌ Error: ' . $result['error'] . '</p></div>';
        }
    }
    
    private function handle_bulk_add_employees() {
        $bulk_data = sanitize_textarea_field($_POST['bulk_employees']);
        $lines = explode("\n", $bulk_data);
        $success_count = 0;
        $error_count = 0;
        $errors = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            $parts = explode(',', $line);
            if (count($parts) === 5) {
                $employee_id = trim($parts[0]);
                $name = trim($parts[1]);
                $email = trim($parts[2]);
                $scheduled_time = trim($parts[3]);
                $password = trim($parts[4]);
                
                $result = $this->mysql->add_employee($employee_id, $name, $email, $password, $scheduled_time);
                
                if (isset($result['success'])) {
                    $success_count++;
                } else {
                    $error_count++;
                    $errors[] = "Line: '$line' - Error: " . $result['error'];
                }
            } else {
                $error_count++;
                $errors[] = "Invalid format: '$line' - Expected: ID,Name,Email,Scheduled Time,Password";
            }
        }
        
        echo '<div class="notice notice-success"><p>✅ Bulk import completed: ' . $success_count . ' successful, ' . $error_count . ' errors</p></div>';
        
        if (!empty($errors)) {
            echo '<div class="notice notice-warning"><p>Errors:</p><ul>';
            foreach ($errors as $error) {
                echo '<li>' . esc_html($error) . '</li>';
            }
            echo '</ul></div>';
        }
    }
    
    private function handle_password_reset() {
        $employee_id = sanitize_text_field($_POST['reset_employee_id']);
        $email = sanitize_email($_POST['reset_email']);
        
        if (empty($employee_id) || empty($email)) {
            echo '<div class="notice notice-error"><p>Both Employee ID and Email are required!</p></div>';
            return;
        }
        
        if (!is_email($email)) {
            echo '<div class="notice notice-error"><p>Please enter a valid email address!</p></div>';
            return;
        }
        
        $result = $this->mysql->reset_password($employee_id, $email);
        
        if (isset($result['success'])) {
            echo '<div class="notice notice-success"><p>✅ Password reset successfully! New password has been sent to ' . esc_html($email) . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>❌ Error: ' . $result['error'] . '</p></div>';
        }
    }
    
    private function display_attendance_table() {
        $records = $this->mysql->get_attendance_records();
        
        $filters = array();
        if (isset($_GET['date_filter']) && !empty($_GET['date_filter'])) {
            $filters['date'] = sanitize_text_field($_GET['date_filter']);
        }
        if (isset($_GET['employee_filter']) && !empty($_GET['employee_filter'])) {
            $filters['employee_id'] = sanitize_text_field($_GET['employee_filter']);
        }
        if (isset($_GET['status_filter']) && !empty($_GET['status_filter'])) {
            $filters['status'] = sanitize_text_field($_GET['status_filter']);
        }
        
        if (!empty($filters)) {
            $records = $this->mysql->get_attendance_records($filters);
        }
        ?>
        <div class="wrap">
            <h1>Attendance Records</h1>
            
            <div class="filters">
                <form method="get">
                    <input type="hidden" name="page" value="attendance-admin">
                    <label for="date_filter">Date:</label>
                    <input type="date" id="date_filter" name="date_filter" value="<?php echo isset($_GET['date_filter']) ? $_GET['date_filter'] : ''; ?>">
                    
                    <label for="employee_filter">Employee ID:</label>
                    <input type="text" id="employee_filter" name="employee_filter" value="<?php echo isset($_GET['employee_filter']) ? $_GET['employee_filter'] : ''; ?>">
                    
                    <label for="status_filter">Status:</label>
                    <select id="status_filter" name="status_filter">
                        <option value="">All</option>
                        <option value="pending" <?php echo (isset($_GET['status_filter']) && $_GET['status_filter'] === 'pending') ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo (isset($_GET['status_filter']) && $_GET['status_filter'] === 'approved') ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo (isset($_GET['status_filter']) && $_GET['status_filter'] === 'rejected') ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                    
                    <button type="submit" class="button">Filter</button>
                    <a href="<?php echo admin_url('admin.php?page=attendance-admin'); ?>" class="button">Clear</a>
                </form>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Employee ID</th>
                        <th>Employee Name</th>
                        <th>Scheduled Time</th>
                        <th>In Time</th>
                        <th>Out Time</th>
                        <th>Late Status</th>
                        <th>Approval Status</th>
                        <th>Hours Worked</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($records)): ?>
                        <?php foreach ($records as $record): ?>
                            <?php
                            $status_badge = '';
                            switch($record['status']) {
                                case 'approved':
                                    $status_badge = '<span class="approval-badge approved">✅ Approved</span>';
                                    break;
                                case 'rejected':
                                    $status_badge = '<span class="approval-badge rejected">❌ Rejected</span>';
                                    break;
                                default:
                                    $status_badge = '<span class="approval-badge pending">⏳ Pending</span>';
                            }
                            
                            $late_badge = '';
                            switch($record['late_status']) {
                                case 'late':
                                    $late_badge = '<span class="late-badge late">⏰ Late (' . $record['late_minutes'] . 'm)</span>';
                                    break;
                                case 'absent':
                                    $late_badge = '<span class="late-badge absent">❌ Absent</span>';
                                    break;
                                default:
                                    $late_badge = '<span class="late-badge on-time">✅ On Time</span>';
                            }
                            ?>
                            <tr>
                                <td><?php echo esc_html($record['date']); ?></td>
                                <td><?php echo esc_html($record['employee_id']); ?></td>
                                <td><?php echo esc_html($record['employee_name']); ?></td>
                                <td><?php echo esc_html($record['scheduled_time']); ?></td>
                                <td><?php echo esc_html($record['in_time'] ? date('H:i:s', strtotime($record['in_time'])) : 'N/A'); ?></td>
                                <td><?php echo esc_html($record['out_time'] ? date('H:i:s', strtotime($record['out_time'])) : 'Not checked out'); ?></td>
                                <td><?php echo $late_badge; ?></td>
                                <td><?php echo $status_badge; ?></td>
                                <td>
                                    <?php 
                                    if ($record['in_time'] && $record['out_time']) {
                                        $in_time = strtotime($record['in_time']);
                                        $out_time = strtotime($record['out_time']);
                                        $hours = ($out_time - $in_time) / 3600;
                                        echo number_format($hours, 2) . ' hours';
                                    } else {
                                        echo 'N/A';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9">No attendance records found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function logout() {
        session_destroy();
        ?>
        <script>
            window.location.href = '<?php echo admin_url('admin.php?page=attendance-admin'); ?>';
        </script>
        <?php
        exit;
    }
}
?>