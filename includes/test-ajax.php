<?php
/**
 * Test AJAX - Direct PHP file to isolate issues
 */

// Load WordPress
require_once('../../../wp-load.php');

// Check if we can access WordPress
if (!defined('ABSPATH')) {
    die('WordPress not loaded');
}

// Check user
if (!current_user_can('manage_options')) {
    wp_send_json_error('Not admin');
}

// Test database
global $wpdb;
$table_name = $wpdb->prefix . 'attendance_calendar_events';

// Create table if needed
if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
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
    dbDelta($sql);
}

// Test insert
$result = $wpdb->insert(
    $table_name,
    array(
        'event_date' => '2024-01-03',
        'event_type' => 'working',
        'event_note' => 'test from direct file'
    ),
    array('%s', '%s', '%s')
);

if ($result) {
    wp_send_json_success('Direct test worked!');
} else {
    wp_send_json_error('Direct test failed: ' . $wpdb->last_error);
}
?>