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

/** How many days of screenshots to keep. Older images (files + rows) are purged. */
if (!defined('TRACKER_SCREENSHOT_RETENTION_DAYS')) {
    define('TRACKER_SCREENSHOT_RETENTION_DAYS', 5);
}

/**
 * Delete screenshot files + rows older than the retention window.
 * Keeps disk usage bounded so the gallery never has to load months of images.
 */
if (!function_exists('purgeOldTrackerScreenshots')) {
    function purgeOldTrackerScreenshots($conn, $days = null) {
        $days = (int) ($days !== null ? $days : TRACKER_SCREENSHOT_RETENTION_DAYS);
        if ($days < 1) { $days = 1; }

        $cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));
        $cutoffEsc = mysqli_real_escape_string($conn, $cutoff);

        // Work in batches so one request never does an unbounded delete.
        $res = $conn->query("SELECT id, file FROM tracker_screenshots WHERE captured_at < '$cutoffEsc' LIMIT 3000");
        if (!$res) { return; }

        $ids = [];
        while ($row = $res->fetch_assoc()) {
            $rel = ltrim((string) $row['file'], '/');
            $path = __DIR__ . '/../' . $rel;
            if (is_file($path)) { @unlink($path); }
            $ids[] = (int) $row['id'];
        }
        if (!empty($ids)) {
            $conn->query("DELETE FROM tracker_screenshots WHERE id IN (" . implode(',', $ids) . ")");
        }
    }
}

/**
 * Run the purge at most once every few hours (marker file), so it is safe to
 * call on every screenshot upload / admin page load without a cron job.
 */
if (!function_exists('maybePurgeOldTrackerScreenshots')) {
    function maybePurgeOldTrackerScreenshots($conn, $everySeconds = 21600) {
        $dir = __DIR__ . '/../uploads/tracker';
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        $marker = $dir . '/.last_purge';

        $now = time();
        $last = is_file($marker) ? (int) @file_get_contents($marker) : 0;
        if ($now - $last < $everySeconds) { return; }

        @file_put_contents($marker, (string) $now); // claim the slot first to avoid overlap
        purgeOldTrackerScreenshots($conn);
    }
}

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
