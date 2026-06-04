<?php
// Set PHP timezone globally to Pakistan Standard Time
date_default_timezone_set('Asia/Karachi');

$conn = new mysqli("localhost", "root", "", "employee_system");

if ($conn->connect_error) {
    die("Connection Failed: " . $conn->connect_error);
}

// Align MySQL session timezone with Pakistan Standard Time (+05:00)
$conn->query("SET time_zone = '+05:00'");


// --- SELF HEALING SCHEMA AUTO-MIGRATION ---
// 1. Check if assigned_ip column exists in users
$checkUsers = $conn->query("SHOW COLUMNS FROM users LIKE 'assigned_ip'");
if ($checkUsers && $checkUsers->num_rows == 0) {
    $conn->query("ALTER TABLE users ADD COLUMN assigned_ip VARCHAR(45) DEFAULT NULL");
    $conn->query("ALTER TABLE users ADD COLUMN assigned_location TEXT DEFAULT NULL");
    $conn->query("ALTER TABLE users ADD COLUMN assigned_latitude VARCHAR(50) DEFAULT NULL");
    $conn->query("ALTER TABLE users ADD COLUMN assigned_longitude VARCHAR(50) DEFAULT NULL");
    $conn->query("ALTER TABLE users ADD COLUMN assigned_radius INT DEFAULT 500");
}

// 2. Check if ip_address exists in shifts
$checkShifts = $conn->query("SHOW COLUMNS FROM shifts LIKE 'ip_address'");
if ($checkShifts && $checkShifts->num_rows == 0) {
    $conn->query("ALTER TABLE shifts ADD COLUMN ip_address VARCHAR(45) DEFAULT NULL");
}

// 3. Ensure device column in shifts is VARCHAR(255) to prevent truncation errors
$checkDevice = $conn->query("SHOW COLUMNS FROM shifts LIKE 'device'");
if ($checkDevice && $checkDevice->num_rows > 0) {
    $row = $checkDevice->fetch_assoc();
    if (strpos(strtolower($row['Type']), 'varchar(255)') === false) {
        $conn->query("ALTER TABLE shifts MODIFY COLUMN device VARCHAR(255) DEFAULT NULL");
    }
}

// 4. Ensure current_location column in shifts is TEXT to handle long geolocation addresses
$checkLocation = $conn->query("SHOW COLUMNS FROM shifts LIKE 'current_location'");
if ($checkLocation && $checkLocation->num_rows > 0) {
    $row = $checkLocation->fetch_assoc();
    if (strpos(strtolower($row['Type']), 'text') === false) {
        $conn->query("ALTER TABLE shifts MODIFY COLUMN current_location TEXT DEFAULT NULL");
    }
}

// 5. Check if login_logs table exists
$checkLogs = $conn->query("SHOW TABLES LIKE 'login_logs'");
if ($checkLogs && $checkLogs->num_rows == 0) {
    $conn->query("CREATE TABLE login_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        device VARCHAR(255) DEFAULT NULL,
        location TEXT DEFAULT NULL,
        latitude VARCHAR(50) DEFAULT NULL,
        longitude VARCHAR(50) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
}

// 6. Hourly update slot tracking (15-minute windows, one entry per slot)
$checkShiftId = $conn->query("SHOW COLUMNS FROM hourly_updates LIKE 'shift_id'");
if ($checkShiftId && $checkShiftId->num_rows == 0) {
    $conn->query("ALTER TABLE hourly_updates ADD COLUMN shift_id INT DEFAULT NULL AFTER employee_id");
    $conn->query("ALTER TABLE hourly_updates ADD COLUMN slot_date DATE DEFAULT NULL AFTER shift_id");
    $conn->query("ALTER TABLE hourly_updates ADD COLUMN slot_hour TINYINT UNSIGNED DEFAULT NULL AFTER slot_date");
}

$checkSlotIndex = $conn->query("SHOW INDEX FROM hourly_updates WHERE Key_name = 'uniq_employee_shift_slot'");
if ($checkSlotIndex && $checkSlotIndex->num_rows == 0) {
    $conn->query("
        ALTER TABLE hourly_updates
        ADD UNIQUE KEY uniq_employee_shift_slot (employee_id, shift_id, slot_date, slot_hour)
    ");
}
?>