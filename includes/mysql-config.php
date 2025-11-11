<?php

class MySQLConfig {
    private $db_connection;
    
    public function __construct() {
        $this->init_db_connection();
        $this->check_and_create_tables();
    }
    
    private function init_db_connection() {
        global $wpdb;
        $this->db_connection = $wpdb;
    }
    
    // Check and create tables if they don't exist or are outdated
    private function check_and_create_tables() {
        global $wpdb;
        
        // Check if tables exist and have correct structure
        if (!$this->verify_table_structure()) {
            $this->create_tables();
        }
    }
    
    public function create_tables() {
        global $wpdb;
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Drop existing tables to recreate them with correct structure
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}attendance_employees");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}attendance_records");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}employee_password_history");
        
        // Create employees table with ALL required columns
        $sql_employees = "CREATE TABLE {$wpdb->prefix}attendance_employees (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id VARCHAR(50) UNIQUE NOT NULL,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(100) NOT NULL,
            password VARCHAR(255) NOT NULL,
            scheduled_time TIME DEFAULT '09:00:00',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) $charset_collate;";
        dbDelta($sql_employees);
        
        // Create attendance table with ALL required columns
        $sql_attendance = "CREATE TABLE {$wpdb->prefix}attendance_records (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id VARCHAR(50) NOT NULL,
            employee_name VARCHAR(100) NOT NULL,
            date DATE NOT NULL,
            in_time DATETIME,
            out_time DATETIME,
            scheduled_time TIME DEFAULT '09:00:00',
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            late_status ENUM('on_time', 'late', 'absent') DEFAULT 'on_time',
            late_minutes INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_employee_date (employee_id, date),
            INDEX idx_status (status),
            INDEX idx_date (date)
        ) $charset_collate;";
        dbDelta($sql_attendance);
        
        // Create password history table
        $sql_password_history = "CREATE TABLE {$wpdb->prefix}employee_password_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id VARCHAR(50) NOT NULL,
            password VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_employee_id (employee_id)
        ) $charset_collate;";
        dbDelta($sql_password_history);
        
        return true;
    }
    
    // Reset and recreate tables
    public function reset_tables() {
        return $this->create_tables();
    }
    
    // Emergency database repair
    public function emergency_repair() {
        global $wpdb;
        
        $results = array();
        
        // First, check if tables have the correct structure
        if (!$this->verify_table_structure()) {
            $results[] = "⚠️ Table structure outdated. Recreating tables...";
            $this->create_tables();
            $results[] = "✅ Tables recreated with latest structure";
            return $results;
        }
        
        // Check for orphaned records
        $orphaned = $wpdb->get_results(
            "SELECT ar.* FROM {$wpdb->prefix}attendance_records ar
             LEFT JOIN {$wpdb->prefix}attendance_employees ae ON ar.employee_id = ae.employee_id
             WHERE ae.employee_id IS NULL"
        , ARRAY_A);
        
        if (!empty($orphaned)) {
            $results[] = "Found " . count($orphaned) . " orphaned attendance records";
            $deleted = $wpdb->query("DELETE ar FROM {$wpdb->prefix}attendance_records ar 
                              LEFT JOIN {$wpdb->prefix}attendance_employees ae ON ar.employee_id = ae.employee_id 
                              WHERE ae.employee_id IS NULL");
            $results[] = "Deleted $deleted orphaned records";
        }
        
        // Check for duplicate today records
        $duplicates = $wpdb->get_results(
            "SELECT employee_id, date, COUNT(*) as count 
             FROM {$wpdb->prefix}attendance_records 
             WHERE date = CURDATE() 
             GROUP BY employee_id, date 
             HAVING count > 1"
        , ARRAY_A);
        
        if (!empty($duplicates)) {
            $results[] = "Found " . count($duplicates) . " employees with duplicate today records";
            
            foreach ($duplicates as $dup) {
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}attendance_records 
                     WHERE employee_id = %s AND date = %s 
                     AND id NOT IN (
                         SELECT id FROM (
                             SELECT MAX(id) as id 
                             FROM {$wpdb->prefix}attendance_records 
                             WHERE employee_id = %s AND date = %s
                         ) as temp
                     )",
                    $dup['employee_id'], $dup['date'], $dup['employee_id'], $dup['date']
                ));
            }
            $results[] = "Cleaned up duplicate records";
        }
        
        // Check for records with invalid check-in/out times
        $invalid_times = $wpdb->get_results(
            "SELECT id, employee_id, in_time, out_time 
             FROM {$wpdb->prefix}attendance_records 
             WHERE (in_time IS NULL OR in_time = '') 
             OR (out_time IS NOT NULL AND out_time != '' AND in_time IS NULL)"
        , ARRAY_A);
        
        if (!empty($invalid_times)) {
            $results[] = "Found " . count($invalid_times) . " records with invalid times";
            $fixed = $wpdb->query(
                "UPDATE {$wpdb->prefix}attendance_records 
                 SET out_time = NULL 
                 WHERE out_time IS NOT NULL AND (in_time IS NULL OR in_time = '')"
            );
            $results[] = "Fixed $fixed records with invalid time data";
        }
        
        if (empty($results)) {
            $results[] = "✅ No issues found. Database is clean.";
        }
        
        return $results;
    }
    
    // Verify table structure has all required columns
    private function verify_table_structure() {
        global $wpdb;
        
        // Check if tables exist
        $employees_table = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}attendance_employees'");
        $attendance_table = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}attendance_records'");
        $password_table = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}employee_password_history'");
        
        if (!$employees_table || !$attendance_table || !$password_table) {
            return false;
        }
        
        // Check employees table columns
        $employees_columns = $wpdb->get_col("DESCRIBE {$wpdb->prefix}attendance_employees", 0);
        $required_employees_columns = ['employee_id', 'name', 'email', 'password', 'scheduled_time'];
        
        foreach ($required_employees_columns as $column) {
            if (!in_array($column, $employees_columns)) {
                return false;
            }
        }
        
        // Check attendance_records table columns
        $attendance_columns = $wpdb->get_col("DESCRIBE {$wpdb->prefix}attendance_records", 0);
        $required_attendance_columns = ['status', 'late_status', 'late_minutes', 'scheduled_time'];
        
        foreach ($required_attendance_columns as $column) {
            if (!in_array($column, $attendance_columns)) {
                return false;
            }
        }
        
        return true;
    }
    
    // Check table structure
    public function check_table_structure() {
        global $wpdb;
        
        $result = [];
        
        // Check employees table structure
        $employees_structure = $wpdb->get_results("DESCRIBE {$wpdb->prefix}attendance_employees", ARRAY_A);
        
        if (empty($employees_structure)) {
            return "❌ Employees table doesn't exist or is empty";
        }
        
        $result[] = "Employees Table Columns:";
        foreach ($employees_structure as $column) {
            $result[] = " - " . $column['Field'] . " (" . $column['Type'] . ")";
        }
        
        // Check if email column exists
        $email_exists = false;
        $scheduled_time_exists = false;
        foreach ($employees_structure as $column) {
            if ($column['Field'] === 'email') {
                $email_exists = true;
            }
            if ($column['Field'] === 'scheduled_time') {
                $scheduled_time_exists = true;
            }
        }
        
        $result[] = $email_exists ? "✅ Email column exists" : "❌ Email column missing";
        $result[] = $scheduled_time_exists ? "✅ Scheduled time column exists" : "❌ Scheduled time column missing";
        
        // Check attendance_records table structure
        $attendance_structure = $wpdb->get_results("DESCRIBE {$wpdb->prefix}attendance_records", ARRAY_A);
        
        if (empty($attendance_structure)) {
            $result[] = "❌ Attendance records table doesn't exist or is empty";
        } else {
            $result[] = "Attendance Records Table Columns:";
            foreach ($attendance_structure as $column) {
                $result[] = " - " . $column['Field'] . " (" . $column['Type'] . ")";
            }
            
            $status_exists = false;
            $late_status_exists = false;
            $late_minutes_exists = false;
            foreach ($attendance_structure as $column) {
                if ($column['Field'] === 'status') $status_exists = true;
                if ($column['Field'] === 'late_status') $late_status_exists = true;
                if ($column['Field'] === 'late_minutes') $late_minutes_exists = true;
            }
            
            $result[] = $status_exists ? "✅ Status column exists" : "❌ Status column missing";
            $result[] = $late_status_exists ? "✅ Late status column exists" : "❌ Late status column missing";
            $result[] = $late_minutes_exists ? "✅ Late minutes column exists" : "❌ Late minutes column missing";
        }
        
        return implode("\n", $result);
    }
    
    // Check if any WordPress user has attendance admin role
    public function check_admin_exists() {
        $admins = get_users(array(
            'role' => 'attendance_admin',
            'number' => 1
        ));
        return !empty($admins);
    }
    
    // Create a new WordPress user with attendance admin role
    public function register_admin($username, $password, $email) {
        // Check if username already exists
        if (username_exists($username)) {
            return array('error' => 'Username already exists');
        }
        
        // Check if email already exists
        if (email_exists($email)) {
            return array('error' => 'Email already exists');
        }
        
        // Create new user
        $user_id = wp_create_user($username, $password, $email);
        
        if (is_wp_error($user_id)) {
            return array('error' => $user_id->get_error_message());
        }
        
        // Add attendance admin role
        $user = new WP_User($user_id);
        $user->add_role('attendance_admin');
        
        return array('success' => true, 'user_id' => $user_id);
    }
    
    // Verify admin using WordPress authentication
    public function verify_admin($username, $password) {
        $user = wp_authenticate($username, $password);
        
        if (is_wp_error($user)) {
            return false;
        }
        
        // Check if user has attendance admin role
        return in_array('attendance_admin', $user->roles);
    }
    
    // Employee management methods
    public function add_employee($employee_id, $name, $email, $password, $scheduled_time = '09:00') {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'attendance_employees';
        
        // Check if employee ID already exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT employee_id FROM $table_name WHERE employee_id = %s",
            $employee_id
        ));
        
        if ($existing) {
            return array('error' => 'Employee ID already exists');
        }
        
        // Check if email already exists
        $existing_email = $wpdb->get_var($wpdb->prepare(
            "SELECT email FROM $table_name WHERE email = %s",
            $email
        ));
        
        if ($existing_email) {
            return array('error' => 'Email already exists');
        }
        
        $hashed_password = wp_hash_password($password);
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'employee_id' => $employee_id,
                'name' => $name,
                'email' => $email,
                'password' => $hashed_password,
                'scheduled_time' => $scheduled_time
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );
        
        if ($result) {
            // Store password in history
            $this->store_password_history($employee_id, $hashed_password);
            return array('success' => true, 'id' => $wpdb->insert_id);
        } else {
            return array('error' => 'Failed to add employee: ' . $wpdb->last_error);
        }
    }
    
    // Store password in history
    private function store_password_history($employee_id, $password_hash) {
        global $wpdb;
        
        $result = $wpdb->insert(
            $wpdb->prefix . 'employee_password_history',
            array(
                'employee_id' => $employee_id,
                'password' => $password_hash
            ),
            array('%s', '%s')
        );
        return $result !== false;
    }
    
    // Check if password was used before
    public function is_previous_password($employee_id, $password) {
        global $wpdb;
        
        $previous_passwords = $wpdb->get_col($wpdb->prepare(
            "SELECT password FROM {$wpdb->prefix}employee_password_history 
             WHERE employee_id = %s ORDER BY created_at DESC LIMIT 5",
            $employee_id
        ));
        
        foreach ($previous_passwords as $old_hash) {
            if (wp_check_password($password, $old_hash)) {
                return true;
            }
        }
        return false;
    }
    
    // Update employee password
    public function update_employee_password($employee_id, $new_password) {
        global $wpdb;
        
        $hashed_password = wp_hash_password($new_password);
        
        $result = $wpdb->update(
            $wpdb->prefix . 'attendance_employees',
            array('password' => $hashed_password),
            array('employee_id' => $employee_id),
            array('%s'),
            array('%s')
        );
        
        if ($result !== false) {
            // Store in password history
            $this->store_password_history($employee_id, $hashed_password);
            return array('success' => true);
        } else {
            return array('error' => 'Employee not found or update failed');
        }
    }
    
    // Update employee credentials
    public function update_employee_credentials($employee_id, $name, $email, $password = null, $scheduled_time = null) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'attendance_employees';
        
        $update_data = array(
            'name' => $name,
            'email' => $email
        );
        
        $format = array('%s', '%s');
        
        if ($scheduled_time) {
            $update_data['scheduled_time'] = $scheduled_time;
            $format[] = '%s';
        }
        
        if ($password) {
            $hashed_password = wp_hash_password($password);
            $update_data['password'] = $hashed_password;
            $format[] = '%s';
        }
        
        $result = $wpdb->update(
            $table_name,
            $update_data,
            array('employee_id' => $employee_id),
            $format,
            array('%s')
        );
        
        if ($result !== false && $result > 0) {
            if ($password) {
                $this->store_password_history($employee_id, $hashed_password);
            }
            return array('success' => true);
        }
        
        return array('error' => 'Employee not found or no changes made');
    }
    
    // Delete employee
    public function delete_employee($employee_id) {
        global $wpdb;
        
        // Delete from employees table
        $result1 = $wpdb->delete(
            $wpdb->prefix . 'attendance_employees',
            array('employee_id' => $employee_id),
            array('%s')
        );
        
        // Delete password history
        $result2 = $wpdb->delete(
            $wpdb->prefix . 'employee_password_history',
            array('employee_id' => $employee_id),
            array('%s')
        );
        
        // Delete attendance records
        $result3 = $wpdb->delete(
            $wpdb->prefix . 'attendance_records',
            array('employee_id' => $employee_id),
            array('%s')
        );
        
        return array(
            'success' => ($result1 !== false), 
            'deleted_employees' => $result1,
            'deleted_password_history' => $result2,
            'deleted_attendance_records' => $result3
        );
    }
    public function auto_mark_absent_employees() {
    global $wpdb;
    
    // Set Pakistan timezone
    date_default_timezone_set('Asia/Karachi');
    
    // Use Pakistan time
    $today = date('Y-m-d');
    $current_time = date('Y-m-d H:i:s');
    
    error_log("🔄 AUTO-ABSENCE [PAKISTAN]: Starting for date: $today, Time: $current_time");
    
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
    
    error_log("🔍 AUTO-ABSENCE: Found " . count($absent_employees) . " employees without attendance records");
    
    $marked_count = 0;
    
    foreach ($absent_employees as $employee) {
        $scheduled_time = $employee['scheduled_time'];
        
        // Calculate cutoff time (scheduled time + 4 hours) in Pakistan time
        $scheduled_datetime = $today . ' ' . $scheduled_time;
        $cutoff_datetime = date('Y-m-d H:i:s', strtotime($scheduled_datetime) + (4 * 3600));
        
        error_log("🔍 AUTO-ABSENCE CHECK: {$employee['employee_id']} - Scheduled: $scheduled_time, Cutoff: $cutoff_datetime, Current: $current_time");
        
        // Only mark absent if we're past cutoff time (4 hours after scheduled time)
        if (strtotime($current_time) >= strtotime($cutoff_datetime)) {
            // Check if record already exists to avoid duplicates
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}attendance_records 
                 WHERE employee_id = %s AND date = %s",
                $employee['employee_id'], $today
            ));
            
            if (!$existing) {
                $result = $wpdb->insert(
                    $wpdb->prefix . 'attendance_records',
                    array(
                        'employee_id' => $employee['employee_id'],
                        'employee_name' => $employee['name'],
                        'date' => $today,
                        'in_time' => NULL,
                        'out_time' => NULL,
                        'scheduled_time' => $employee['scheduled_time'],
                        'status' => 'pending',
                        'late_status' => 'absent',
                        'late_minutes' => 240, // 4 hours in minutes
                        'created_at' => date('Y-m-d H:i:s')
                    ),
                    array('%s', '%s', '%s', null, null, '%s', '%s', '%s', '%d', '%s')
                );
                
                if ($result) {
                    $marked_count++;
                    error_log("✅ AUTO-ABSENT: {$employee['employee_id']} - {$employee['name']} (Scheduled: $scheduled_time)");
                } else {
                    error_log("❌ AUTO-ABSENT FAILED: {$employee['employee_id']} - " . $wpdb->last_error);
                }
            } else {
                error_log("⚠️ AUTO-ABSENT SKIPPED: {$employee['employee_id']} - Record already exists");
            }
        } else {
            error_log("⏰ NOT YET: {$employee['employee_id']} - Cutoff not reached (Cutoff: $cutoff_datetime, Current: $current_time)");
        }
    }
    
    error_log("📊 AUTO-ABSENCE SUMMARY: Marked $marked_count employees as absent");
    return $marked_count;
}
    // Get employee by ID
    public function get_employee($employee_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}attendance_employees WHERE employee_id = %s",
            $employee_id
        ), ARRAY_A);
    }
    
    public function reset_password($employee_id, $email) {
        global $wpdb;
        
        // First verify the employee exists with the given email
        $employee = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}attendance_employees 
             WHERE employee_id = %s AND email = %s",
            $employee_id, $email
        ), ARRAY_A);
        
        if (!$employee) {
            return array('error' => 'Employee ID and email combination not found');
        }
        
        // Generate new password
        $new_password = wp_generate_password(12, true);
        $hashed_password = wp_hash_password($new_password);
        
        // Update password in database
        $result = $wpdb->update(
            $wpdb->prefix . 'attendance_employees',
            array('password' => $hashed_password),
            array('employee_id' => $employee_id, 'email' => $email),
            array('%s'),
            array('%s', '%s')
        );
        
        if ($result !== false && $result > 0) {
            // Store in password history
            $this->store_password_history($employee_id, $hashed_password);
            
            // Send email notification
            $subject = 'Password Reset - Employee Portal';
            $message = "Hello " . $employee['name'] . ",\n\n";
            $message .= "Your password has been successfully reset.\n\n";
            $message .= "Employee ID: " . $employee_id . "\n";
            $message .= "New Password: " . $new_password . "\n\n";
            $message .= "Please login to the employee portal and change your password immediately for security reasons.\n\n";
            $message .= "Best regards,\nAttendance System Admin";
            
            $headers = array('Content-Type: text/plain; charset=UTF-8');
            
            $mail_sent = wp_mail($email, $subject, $message, $headers);
            
            if ($mail_sent) {
                return array('success' => true, 'new_password' => $new_password);
            } else {
                return array('error' => 'Password reset but email could not be sent');
            }
        } else {
            return array('error' => 'Password reset failed - no rows affected');
        }
    }
    
    public function verify_employee($employee_id, $password) {
        global $wpdb;
        
        $employee = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}attendance_employees WHERE employee_id = %s",
            $employee_id
        ), ARRAY_A);
        
        if ($employee && wp_check_password($password, $employee['password'])) {
            return $employee;
        }
        return null;
    }
    
    public function insert_attendance($data) {
        global $wpdb;
        
        // Calculate late status
        if (!empty($data['in_time']) && !empty($data['scheduled_time'])) {
            $checkin_time = strtotime($data['in_time']);
            $scheduled_time = strtotime($data['date'] . ' ' . $data['scheduled_time']);
            $late_minutes = max(0, ($checkin_time - $scheduled_time) / 60);
            
            $data['late_minutes'] = $late_minutes;
            
            if ($late_minutes >= 180) { // 3 hours = absent
                $data['late_status'] = 'absent';
            } elseif ($late_minutes >= 120) { // 2 hours = late
                $data['late_status'] = 'late';
            } else {
                $data['late_status'] = 'on_time';
            }
        }
        
        $result = $wpdb->insert(
            $wpdb->prefix . 'attendance_records',
            $data,
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d')
        );
        
        if ($result) {
            return array('success' => true, 'id' => $wpdb->insert_id);
        } else {
            return array('error' => 'Failed to insert attendance record: ' . $wpdb->last_error);
        }
    }
    
    public function get_today_attendance($employee_id, $date) {
        global $wpdb;
        
        $record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}attendance_records 
             WHERE employee_id = %s AND date = %s",
            $employee_id, $date
        ), ARRAY_A);
        
        return $record;
    }
    
    public function update_attendance_record($record_id, $data) {
        global $wpdb;
        
        $result = $wpdb->update(
            $wpdb->prefix . 'attendance_records',
            $data,
            array('id' => $record_id),
            array('%s', '%s'),
            array('%d')
        );
        
        if ($result !== false) {
            return array('success' => true, 'affected_rows' => $result);
        } else {
            return array('error' => 'Failed to update attendance record: ' . $wpdb->last_error);
        }
    }
    
    // Update attendance approval status
    public function update_attendance_status($record_id, $status) {
        global $wpdb;
        
        $result = $wpdb->update(
            $wpdb->prefix . 'attendance_records',
            array('status' => $status),
            array('id' => $record_id),
            array('%s'),
            array('%d')
        );
        
        if ($result !== false) {
            return array('success' => true, 'affected_rows' => $result);
        } else {
            return array('error' => 'Failed to update attendance status: ' . $wpdb->last_error);
        }
    }
    
    // Get pending approvals
    public function get_pending_approvals() {
        global $wpdb;
        
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}attendance_records 
             WHERE status = 'pending' 
             ORDER BY date DESC, created_at DESC",
            ARRAY_A
        );
    }
    
    // Get monthly summary
    public function get_monthly_summary($month = null, $year = null) {
        global $wpdb;
        
        if (!$month) $month = date('m');
        if (!$year) $year = date('Y');
        
        $start_date = "$year-$month-01";
        $end_date = date('Y-m-t', strtotime($start_date));
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                COUNT(*) as total_records,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN late_status = 'late' THEN 1 ELSE 0 END) as late_count,
                SUM(CASE WHEN late_status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                SUM(CASE WHEN late_status = 'on_time' THEN 1 ELSE 0 END) as on_time_count
             FROM {$wpdb->prefix}attendance_records 
             WHERE date BETWEEN %s AND %s",
            $start_date, $end_date
        ), ARRAY_A);
        
        return $results[0] ?? array();
    }
    
    public function get_attendance_records($filters = array()) {
        global $wpdb;
        
        $where = "1=1";
        $params = array();
        
        if (!empty($filters['date'])) {
            $where .= " AND date = %s";
            $params[] = $filters['date'];
        }
        
        if (!empty($filters['employee_id'])) {
            $where .= " AND employee_id = %s";
            $params[] = $filters['employee_id'];
        }
        
        if (!empty($filters['status'])) {
            $where .= " AND status = %s";
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $where .= " AND date BETWEEN %s AND %s";
            $params[] = $filters['start_date'];
            $params[] = $filters['end_date'];
        }
        
        $sql = "SELECT * FROM {$wpdb->prefix}attendance_records WHERE $where ORDER BY date DESC, employee_id ASC";
        
        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }
        
        return $wpdb->get_results($sql, ARRAY_A);
    }
    
    public function get_all_employees() {
        global $wpdb;
        
        return $wpdb->get_results(
            "SELECT employee_id, name, email, scheduled_time, created_at 
             FROM {$wpdb->prefix}attendance_employees 
             ORDER BY name ASC",
            ARRAY_A
        );
    }
    
    public function get_employees_count() {
        global $wpdb;
        
        return $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}attendance_employees"
        );
    }
    
    public function get_attendance_summary($date = null) {
        global $wpdb;
        
        if (!$date) {
            $date = current_time('Y-m-d');
        }
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT ar.employee_id, ar.employee_name, ar.date, ar.in_time, ar.out_time,
                    ar.status, ar.late_status, ar.late_minutes, ar.scheduled_time,
                    ae.email
             FROM {$wpdb->prefix}attendance_records ar
             LEFT JOIN {$wpdb->prefix}attendance_employees ae ON ar.employee_id = ae.employee_id
             WHERE ar.date = %s
             ORDER BY ar.in_time ASC",
            $date
        ), ARRAY_A);
    }
    
    public function test_connection() {
        global $wpdb;
        
        try {
            $tables = [
                $wpdb->prefix . 'attendance_employees',
                $wpdb->prefix . 'attendance_records', 
                $wpdb->prefix . 'employee_password_history'
            ];
            $results = [];
            
            foreach ($tables as $table) {
                $exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") ? '✅' : '❌';
                $results[] = "$exists " . str_replace($wpdb->prefix, '', $table);
            }
            
            // Check table structure
            $structure = $this->check_table_structure();
            
            // Check WordPress users table
            $users_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}");
            $results[] = "✅ wp_users ($users_count users)";
            
            // Check employees count
            $employees_count = $this->get_employees_count();
            $results[] = "✅ Employees ($employees_count employees)";
            
            return "✅ Database connected!<br>" . implode('<br>', $results) . "<br><br>Table Structure:<br>" . str_replace("\n", "<br>", $structure);
            
        } catch (Exception $e) {
            return "❌ Database test failed: " . $e->getMessage();
        }
    }
    
    // Close database connection (for cleanup)
    public function close_connection() {
        // WordPress handles connection automatically
        return true;
    }
}
?>