<?php
/**
 * Self-healing schema for the desktop activity tracker.
 *
 * Kept separate from includes/db.php on purpose: db.php holds per-server database
 * credentials and must never be overwritten during deployment. This file has no
 * credentials, so it can be uploaded as-is to any server. It is invoked from the
 * tracker API bootstrap and the admin tracker page, and (for local dev) from db.php.
 *
 * All statements are idempotent (CREATE TABLE IF NOT EXISTS), safe to run on every
 * request.
 */

if (!function_exists('ensureTrackerSchema')) {
    function ensureTrackerSchema($conn) {
        // 1. Bearer tokens for the desktop agent (no PHP session).
        $conn->query("CREATE TABLE IF NOT EXISTS api_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token VARCHAR(64) NOT NULL,
            device VARCHAR(255) DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            last_used_at DATETIME DEFAULT NULL,
            revoked TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_api_token (token),
            KEY idx_api_token_user (user_id)
        )");

        // 2. Per-minute activity samples: active vs idle seconds.
        $conn->query("CREATE TABLE IF NOT EXISTS tracker_activity (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            shift_id INT DEFAULT NULL,
            window_start DATETIME NOT NULL,
            window_end DATETIME NOT NULL,
            active_seconds INT NOT NULL DEFAULT 0,
            idle_seconds INT NOT NULL DEFAULT 0,
            max_idle_seconds INT NOT NULL DEFAULT 0,
            is_idle TINYINT(1) NOT NULL DEFAULT 0,
            device VARCHAR(255) DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_activity_window (employee_id, shift_id, window_start),
            KEY idx_activity_employee (employee_id),
            KEY idx_activity_shift (shift_id),
            KEY idx_activity_window_start (window_start)
        )");

        // 3. Periodic screen captures uploaded by the agent.
        $conn->query("CREATE TABLE IF NOT EXISTS tracker_screenshots (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            shift_id INT DEFAULT NULL,
            file VARCHAR(255) NOT NULL,
            captured_at DATETIME NOT NULL,
            is_idle TINYINT(1) NOT NULL DEFAULT 0,
            idle_seconds INT NOT NULL DEFAULT 0,
            device VARCHAR(255) DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_shot_employee (employee_id),
            KEY idx_shot_shift (shift_id),
            KEY idx_shot_captured (captured_at)
        )");
    }
}
