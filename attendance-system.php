<?php
/**
 * Plugin Name: Attendance System
 * Description: Employee attendance system with admin portal
 * Version: 1.2
 * Author: Muhammad Abdullah
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Start session at the very beginning
if (!session_id()) {
    session_start();
}

// Define plugin constants
define('ATTENDANCE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ATTENDANCE_PLUGIN_PATH', plugin_dir_path(__FILE__));

// Include required files
require_once ATTENDANCE_PLUGIN_PATH . 'includes/mysql-config.php';
require_once ATTENDANCE_PLUGIN_PATH . 'includes/admin-portal.php';
require_once ATTENDANCE_PLUGIN_PATH . 'includes/employee-portal.php';
require_once ATTENDANCE_PLUGIN_PATH . 'includes/calendar-system.php';

class AttendanceSystem {
    
    public function __construct() {
        if (!get_option('attendance_timezone_set')) {
        update_option('timezone_string', 'Asia/Karachi');
        update_option('gmt_offset', 5);
        update_option('attendance_timezone_set', true);
    }
        add_shortcode('attendance_system', array($this, 'display_attendance_system'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_save_date_event', array($this, 'handle_save_date_event'));
    	add_action('wp_ajax_remove_date_event', array($this, 'handle_remove_date_event'));
        add_action('attendance_auto_absence_daily', array($this, 'run_auto_absence'));
    	add_action('wp', array($this, 'schedule_auto_absence'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', function() {
    		$calendar_system = new CalendarSystem();
   		});
        register_activation_hook(__FILE__, array($this, 'create_tables'));
    }
    
    public function enqueue_scripts() {
        wp_enqueue_script('jquery');
        wp_enqueue_style('attendance-style', ATTENDANCE_PLUGIN_URL . 'assets/style.css', array(), '1.2');
    }
    
   public function enqueue_admin_scripts($hook) {
    wp_enqueue_style('attendance-admin-style', ATTENDANCE_PLUGIN_URL . 'assets/style.css', array(), '1.2');
    wp_enqueue_style('calendar-system-style', ATTENDANCE_PLUGIN_URL . 'assets/calendar-system.css', array(), '1.3');
}
    public function schedule_auto_absence() {
    if (!wp_next_scheduled('attendance_auto_absence_daily')) {
        // Schedule to run daily at 2:00 PM (covers most scheduled times)
        wp_schedule_event(strtotime('14:00:00'), 'daily', 'attendance_auto_absence_daily');
        error_log("📅 AUTO-ABSENCE: Scheduled daily at 2:00 PM");
    }
}

public function run_auto_absence() {
    $mysql = new MySQLConfig();
    $marked = $mysql->auto_mark_absent_employees();
    error_log("📊 AUTO-ABSENCE: Marked $marked employees as absent");
}
    // Add this method to the MySQLConfig class in mysql-config.php
public function auto_mark_absent_employees() {
    global $wpdb;
    
    $today = current_time('Y-m-d');
    
    // Get all employees who haven't checked in today
    $absent_employees = $wpdb->get_results(
        $wpdb->prepare("
            SELECT e.employee_id, e.name, e.scheduled_time 
            FROM {$wpdb->prefix}attendance_employees e
            LEFT JOIN {$wpdb->prefix}attendance_records ar 
                ON e.employee_id = ar.employee_id AND ar.date = %s
            WHERE ar.id IS NULL
        ", $today),
        ARRAY_A
    );
    
    $marked_count = 0;
    
    foreach ($absent_employees as $employee) {
        $scheduled_time = $employee['scheduled_time'];
        $cutoff_time = $today . ' ' . date('H:i:s', strtotime($scheduled_time) + (4 * 3600)); // 4 hours after scheduled time
        
        // Only mark absent if we're past cutoff time (4 hours after scheduled time)
        if (current_time('Y-m-d H:i:s') > $cutoff_time) {
            $result = $wpdb->insert(
                $wpdb->prefix . 'attendance_records',
                array(
                    'employee_id' => $employee['employee_id'],
                    'employee_name' => $employee['name'],
                    'date' => $today,
                    'in_time' => NULL,
                    'out_time' => NULL,
                    'status' => 'pending',
                    'late_status' => 'absent',
                    'late_minutes' => 240, // 4 hours in minutes
                    'scheduled_time' => $employee['scheduled_time'],
                    'created_at' => current_time('Y-m-d H:i:s')
                ),
                array('%s', '%s', '%s', null, null, '%s', '%s', '%d', '%s', '%s')
            );
            
            if ($result) {
                $marked_count++;
                error_log("✅ AUTO-ABSENT: {$employee['employee_id']} - {$employee['name']} (Scheduled: $scheduled_time)");
            }
        }
    }
    
    return $marked_count;
}
    public function add_admin_menu() {
        add_menu_page(
            'Attendance System',
            'Attendance',
            'manage_options',
            'attendance-admin',
            array($this, 'display_admin_page'),
            'dashicons-clock',
            30
        );
    }
    
    public function display_admin_page() {
        $admin_portal = new AdminPortal();
        $admin_portal->display_admin_interface();
    }
    
    public function display_attendance_system() {
        $employee_portal = new EmployeePortal();
        return $employee_portal->display_employee_interface();
    }
    
    public function create_tables() {
        // Create database tables on plugin activation
        $mysql_config = new MySQLConfig();
        $mysql_config->create_tables();
    }
}

new AttendanceSystem();
?>