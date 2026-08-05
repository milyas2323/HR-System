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

// 7. Grandfathered flag: legacy rows relaxed; new rows strict time-bound
$checkGrandfathered = $conn->query("SHOW COLUMNS FROM hourly_updates LIKE 'is_grandfathered'");
if ($checkGrandfathered && $checkGrandfathered->num_rows == 0) {
    $conn->query("ALTER TABLE hourly_updates ADD COLUMN is_grandfathered TINYINT(1) NOT NULL DEFAULT 0 AFTER slot_hour");
}

// 8. Hourly update submission audit: device, IP, location
$checkHourlyIp = $conn->query("SHOW COLUMNS FROM hourly_updates LIKE 'ip_address'");
if ($checkHourlyIp && $checkHourlyIp->num_rows == 0) {
    $conn->query("ALTER TABLE hourly_updates ADD COLUMN ip_address VARCHAR(45) DEFAULT NULL AFTER update_text");
    $conn->query("ALTER TABLE hourly_updates ADD COLUMN device VARCHAR(255) DEFAULT NULL AFTER ip_address");
    $conn->query("ALTER TABLE hourly_updates ADD COLUMN current_location TEXT DEFAULT NULL AFTER device");
}

// 9. Employee bonuses, applied to an admin-selected payslip month (YYYY-MM)
$checkBonuses = $conn->query("SHOW TABLES LIKE 'bonuses'");
if ($checkBonuses && $checkBonuses->num_rows == 0) {
    $conn->query("CREATE TABLE bonuses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        bonus_month VARCHAR(7) NOT NULL,
        title VARCHAR(255) NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_by INT DEFAULT NULL,
        created_by_name VARCHAR(150) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_bonus_employee_month (employee_id, bonus_month)
    )");
}

// 10. Bonus activity log (who added/removed which bonus, and when)
$checkBonusLogs = $conn->query("SHOW TABLES LIKE 'bonus_logs'");
if ($checkBonusLogs && $checkBonusLogs->num_rows == 0) {
    $conn->query("CREATE TABLE bonus_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bonus_id INT DEFAULT NULL,
        employee_id INT NOT NULL,
        bonus_month VARCHAR(7) DEFAULT NULL,
        action VARCHAR(30) NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        message TEXT DEFAULT NULL,
        performed_by INT DEFAULT NULL,
        performed_by_name VARCHAR(150) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_bonus_log_employee (employee_id),
        KEY idx_bonus_log_month (bonus_month)
    )");
}

// 11. Employee requests (late joining, urgent issue, extended break, etc.)
$checkRequests = $conn->query("SHOW TABLES LIKE 'employee_requests'");
if ($checkRequests && $checkRequests->num_rows == 0) {
    $conn->query("CREATE TABLE employee_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        request_type VARCHAR(50) NOT NULL,
        subject VARCHAR(255) DEFAULT NULL,
        details TEXT NOT NULL,
        request_date DATE DEFAULT NULL,
        from_time TIME DEFAULT NULL,
        to_time TIME DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        admin_response TEXT DEFAULT NULL,
        reviewed_by INT DEFAULT NULL,
        reviewed_by_name VARCHAR(150) DEFAULT NULL,
        reviewed_at DATETIME DEFAULT NULL,
        penalty_applied TINYINT(1) NOT NULL DEFAULT 0,
        penalty_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_request_employee (employee_id),
        KEY idx_request_status (status)
    )");
}

// 12. Penalty tracking on employee requests (rejected/unrequested = PKR 5,000 fine)
$checkRequestPenalty = $conn->query("SHOW COLUMNS FROM employee_requests LIKE 'penalty_applied'");
if ($checkRequestPenalty && $checkRequestPenalty->num_rows == 0) {
    $conn->query("ALTER TABLE employee_requests ADD COLUMN penalty_applied TINYINT(1) NOT NULL DEFAULT 0 AFTER reviewed_at");
    $conn->query("ALTER TABLE employee_requests ADD COLUMN penalty_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER penalty_applied");
}

// 13. Admin waivers for the short-working-hours fine (one row per waived shift)
$checkShortHoursRelax = $conn->query("SHOW TABLES LIKE 'short_hours_relaxations'");
if ($checkShortHoursRelax && $checkShortHoursRelax->num_rows == 0) {
    $conn->query("CREATE TABLE short_hours_relaxations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        shift_id INT NOT NULL,
        shift_date DATE DEFAULT NULL,
        note VARCHAR(255) DEFAULT NULL,
        granted_by VARCHAR(100) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_employee_shift_short_hours (employee_id, shift_id),
        KEY idx_short_hours_relax_date (shift_date)
    )");
}
?>