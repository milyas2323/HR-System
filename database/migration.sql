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

