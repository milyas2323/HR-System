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
    define('TRACKER_SCREENSHOT_RETENTION_DAYS', 3);
}

/** The current agent build. Installs reporting an older version are flagged "update needed". */
if (!defined('TRACKER_LATEST_AGENT_VERSION')) { define('TRACKER_LATEST_AGENT_VERSION', '1.3.0'); }

/** Idle-alert penalty policy: N free alerts per shift, then a flat fine per extra alert. */
if (!defined('IDLE_ALERTS_FREE_PER_SHIFT')) { define('IDLE_ALERTS_FREE_PER_SHIFT', 5); }
if (!defined('IDLE_ALERT_PENALTY_RATE'))    { define('IDLE_ALERT_PENALTY_RATE', 100); }

/**
 * Recompute the idle-alert fine for one shift and keep the penalties table in sync.
 *
 * Policy: the first IDLE_ALERTS_FREE_PER_SHIFT alerts in a shift are free; each alert
 * beyond that is fined IDLE_ALERT_PENALTY_RATE. e.g. 10 alerts -> 5 chargeable -> 500.
 *
 * Idempotent: it removes its own previous (non-waived) row for the shift and reinserts
 * the current amount, keeping users.total_deduction consistent (mirrors addPenalty).
 * If an admin has WAIVED the shift's idle fine, it is left untouched (forgiven).
 * The fine is attributed to the shift's start date so it lands in the right payroll month.
 */
if (!function_exists('syncIdleAlertPenalty')) {
    function syncIdleAlertPenalty($conn, $shiftId) {
        $shiftId = (int) $shiftId;
        if ($shiftId <= 0) { return; }

        $sres = $conn->query("SELECT id, employee_id, start_time FROM shifts WHERE id = $shiftId LIMIT 1");
        $shift = $sres ? $sres->fetch_assoc() : null;
        if (!$shift) { return; }
        $empId = (int) $shift['employee_id'];

        $cres = $conn->query("SELECT COUNT(*) c FROM tracker_idle_alerts WHERE shift_id = $shiftId");
        $count = $cres ? (int) $cres->fetch_assoc()['c'] : 0;
        $chargeable = max(0, $count - IDLE_ALERTS_FREE_PER_SHIFT);
        $amount = $chargeable * IDLE_ALERT_PENALTY_RATE;

        $prefix = "Idle Alert Deduction (shift #$shiftId)";
        $prefixEsc = mysqli_real_escape_string($conn, $prefix);
        $eres = $conn->query("SELECT id, amount, waived FROM penalties WHERE reason LIKE '$prefixEsc%'");
        $rows = [];
        if ($eres) { while ($r = $eres->fetch_assoc()) { $rows[] = $r; } }

        // If any existing row for this shift was waived by an admin, leave everything as is.
        foreach ($rows as $r) { if (!empty($r['waived'])) { return; } }

        // Remove previous (non-waived) rows and roll back their deduction.
        foreach ($rows as $r) {
            $rid = (int) $r['id'];
            $amt = (float) $r['amount'];
            $conn->query("DELETE FROM penalties WHERE id = $rid");
            $conn->query("UPDATE users SET total_deduction = GREATEST(total_deduction - $amt, 0) WHERE id = $empId");
        }

        if ($amount <= 0) { return; } // within the free allowance — no fine

        $workDate = date('Y-m-d', strtotime($shift['start_time']));
        $reason = "$prefix - $workDate: $chargeable alert(s) over " . IDLE_ALERTS_FREE_PER_SHIFT
            . " free x PKR " . IDLE_ALERT_PENALTY_RATE;
        $reasonEsc = mysqli_real_escape_string($conn, $reason);
        $tsEsc = mysqli_real_escape_string($conn, date('Y-m-d H:i:s', strtotime($shift['start_time'])));

        $conn->query("INSERT INTO penalties (employee_id, reason, amount, created_at)
                      VALUES ($empId, '$reasonEsc', $amount, '$tsEsc')");
        $conn->query("UPDATE users SET total_deduction = total_deduction + $amount WHERE id = $empId");
    }
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

        // 4. Location columns on api_tokens (current agent location, IP-geolocated).
        $check = $conn->query("SHOW COLUMNS FROM api_tokens LIKE 'last_location'");
        if ($check && $check->num_rows == 0) {
            $conn->query("ALTER TABLE api_tokens
                ADD COLUMN last_location VARCHAR(255) DEFAULT NULL,
                ADD COLUMN last_lat VARCHAR(32) DEFAULT NULL,
                ADD COLUMN last_lng VARCHAR(32) DEFAULT NULL,
                ADD COLUMN located_ip VARCHAR(45) DEFAULT NULL,
                ADD COLUMN located_at DATETIME DEFAULT NULL");
        }

        // 4b. Agent version column on api_tokens (which build each install is running).
        $cv = $conn->query("SHOW COLUMNS FROM api_tokens LIKE 'agent_version'");
        if ($cv && $cv->num_rows == 0) {
            $conn->query("ALTER TABLE api_tokens ADD COLUMN agent_version VARCHAR(20) DEFAULT NULL");
        }

        // 5. Idle alerts: one row each time the agent warns an employee for being idle.
        $conn->query("CREATE TABLE IF NOT EXISTS tracker_idle_alerts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            shift_id INT DEFAULT NULL,
            idle_seconds INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_alert_employee (employee_id),
            KEY idx_alert_shift (shift_id),
            KEY idx_alert_created (created_at)
        )");
    }
}

/**
 * Look up an approximate location for a public IP (city-level), using the same
 * free ip-api.com service the login audit already relies on. Returns null for
 * private/local IPs or on any failure. Called rarely (throttled by the caller).
 */
if (!function_exists('trackerGeolocateIp')) {
    function trackerGeolocateIp($ip) {
        $ip = trim((string) $ip);
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null; // private/local/reserved — no public geolocation
        }
        $url = 'http://ip-api.com/json/' . urlencode($ip) . '?fields=status,country,regionName,city,lat,lon';
        $ctx = stream_context_create(['http' => ['timeout' => 3]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) { return null; }
        $data = json_decode($raw, true);
        if (!is_array($data) || ($data['status'] ?? '') !== 'success') { return null; }
        $parts = array_filter([$data['city'] ?? '', $data['regionName'] ?? '', $data['country'] ?? '']);
        return [
            'location' => implode(', ', $parts),
            'lat' => isset($data['lat']) ? (string) $data['lat'] : null,
            'lng' => isset($data['lon']) ? (string) $data['lon'] : null,
        ];
    }
}

/**
 * Update the stored location for an agent token, throttled so ip-api is only
 * called when the IP changes or the cached location is older than 30 minutes.
 */
if (!function_exists('updateAgentLocation')) {
    function updateAgentLocation($conn, $token, $ip) {
        $ip = trim((string) $ip);
        if ($token === '' ) { return; }

        $stmt = $conn->prepare("SELECT located_ip, located_at FROM api_tokens WHERE token = ? LIMIT 1");
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$row) { return; }

        $fresh = !empty($row['located_at']) && (time() - strtotime($row['located_at']) < 1800);
        if ($fresh && ($row['located_ip'] === $ip)) {
            return; // recent and same IP — nothing to do
        }

        // Private/local IP: record it plainly without an external lookup.
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $loc = 'Local / private network';
            $u = $conn->prepare("UPDATE api_tokens SET last_location = ?, last_lat = NULL, last_lng = NULL, located_ip = ?, located_at = NOW() WHERE token = ?");
            $u->bind_param('sss', $loc, $ip, $token);
            $u->execute();
            $u->close();
            return;
        }

        $geo = trackerGeolocateIp($ip);
        if ($geo === null) { return; }
        $u = $conn->prepare("UPDATE api_tokens SET last_location = ?, last_lat = ?, last_lng = ?, located_ip = ?, located_at = NOW() WHERE token = ?");
        $u->bind_param('sssss', $geo['location'], $geo['lat'], $geo['lng'], $ip, $token);
        $u->execute();
        $u->close();
    }
}
