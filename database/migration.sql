-- Database migration for Employee Attendance System overhaul

-- 1. Add workstation geofencing parameters to users table
ALTER TABLE users ADD COLUMN assigned_ip VARCHAR(45) DEFAULT NULL;
ALTER TABLE users ADD COLUMN assigned_location TEXT DEFAULT NULL;
ALTER TABLE users ADD COLUMN assigned_latitude VARCHAR(50) DEFAULT NULL;
ALTER TABLE users ADD COLUMN assigned_longitude VARCHAR(50) DEFAULT NULL;
ALTER TABLE users ADD COLUMN assigned_radius INT DEFAULT 500;

-- 2. Add missing ip_address column to shifts table
ALTER TABLE shifts ADD COLUMN ip_address VARCHAR(45) DEFAULT NULL;

-- 3. Create login_logs table to audit detailed login details
CREATE TABLE IF NOT EXISTS login_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    device VARCHAR(255) DEFAULT NULL,
    location TEXT DEFAULT NULL,
    latitude VARCHAR(50) DEFAULT NULL,
    longitude VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 4. Increase column sizes in shifts table to prevent insertion failures on long device/location values
ALTER TABLE shifts MODIFY COLUMN device VARCHAR(255) DEFAULT NULL;
ALTER TABLE shifts MODIFY COLUMN current_location TEXT DEFAULT NULL;

-- 5. Hourly update 15-minute slot tracking (one valid submission per slot per shift)
ALTER TABLE hourly_updates ADD COLUMN shift_id INT DEFAULT NULL AFTER employee_id;
ALTER TABLE hourly_updates ADD COLUMN slot_date DATE DEFAULT NULL AFTER shift_id;
ALTER TABLE hourly_updates ADD COLUMN slot_hour TINYINT UNSIGNED DEFAULT NULL AFTER slot_date;
ALTER TABLE hourly_updates ADD COLUMN is_grandfathered TINYINT(1) NOT NULL DEFAULT 0 AFTER slot_hour;
ALTER TABLE hourly_updates ADD UNIQUE KEY uniq_employee_shift_slot (employee_id, shift_id, slot_date, slot_hour);

-- 6. Hourly update submission audit (device, IP, location at submit time)
ALTER TABLE hourly_updates ADD COLUMN ip_address VARCHAR(45) DEFAULT NULL AFTER update_text;
ALTER TABLE hourly_updates ADD COLUMN device VARCHAR(255) DEFAULT NULL AFTER ip_address;
ALTER TABLE hourly_updates ADD COLUMN current_location TEXT DEFAULT NULL AFTER device;

-- 7. Admin-only hourly check entries (hidden from feeds and penalty logic)
ALTER TABLE hourly_updates ADD COLUMN is_admin_check TINYINT(1) NOT NULL DEFAULT 0 AFTER is_grandfathered;
ALTER TABLE hourly_updates ADD COLUMN admin_submitted_by INT DEFAULT NULL AFTER is_admin_check;


-- 8. Employee bonuses applied to an admin-selected payslip month (YYYY-MM)
CREATE TABLE IF NOT EXISTS bonuses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    bonus_month VARCHAR(7) NOT NULL,
    title VARCHAR(255) NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_by INT DEFAULT NULL,
    created_by_name VARCHAR(150) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_bonus_employee_month (employee_id, bonus_month)
);

-- 9. Bonus activity log (audit trail of bonus add/remove actions)
CREATE TABLE IF NOT EXISTS bonus_logs (
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
);

-- 10. Employee requests (late joining, urgent issue, extended break, etc.) with admin approval
CREATE TABLE IF NOT EXISTS employee_requests (
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
);

-- 11. Penalty tracking on employee requests (rejected / unrequested = PKR 5,000 fine)
ALTER TABLE employee_requests ADD COLUMN penalty_applied TINYINT(1) NOT NULL DEFAULT 0 AFTER reviewed_at;
ALTER TABLE employee_requests ADD COLUMN penalty_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER penalty_applied;
