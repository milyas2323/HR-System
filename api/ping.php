<?php
/**
 * POST /api/ping.php
 * One activity sample per minute window from the desktop agent.
 * Body: {
 *   "window_start": "YYYY-MM-DD HH:MM:SS",  // start of the minute window (agent clock)
 *   "active_seconds": 0-60,
 *   "idle_seconds":   0-60,
 *   "max_idle_seconds": seconds since last input at window end,
 *   "device": "optional label"
 * }
 * Activity is attached to the employee's open shift. If no shift is open the
 * sample is discarded (web check-in is the geofenced shift opener) and the
 * agent is told so it can prompt the user.
 */

require_once __DIR__ . '/_bootstrap.php';

api_require_method('POST');

$user = api_authenticate($conn);
$employeeId = (int) $user['id'];

// Always-on tracking: record activity whether or not a shift is open.
// When a shift is open we attach to it; otherwise shift_id = 0 (tracked off-shift).
$openShiftId = api_active_shift_id($conn, $employeeId);
$shiftId = $openShiftId === null ? 0 : $openShiftId;

$input = api_input();

// Server trusts its own clock for the window; the agent value is a fallback.
$windowStart = trim((string) ($input['window_start'] ?? ''));
$ts = $windowStart !== '' ? strtotime($windowStart) : false;
if ($ts === false) {
    $ts = time();
}
// Normalise to the start of the minute so retries dedupe cleanly.
$ts -= $ts % 60;
$windowStart = date('Y-m-d H:i:s', $ts);
$windowEnd   = date('Y-m-d H:i:s', $ts + 60);

$activeSeconds = max(0, min(60, (int) ($input['active_seconds'] ?? 0)));
$idleSeconds   = max(0, min(60, (int) ($input['idle_seconds'] ?? 0)));
$maxIdle       = max(0, (int) ($input['max_idle_seconds'] ?? 0));
$isIdle        = $activeSeconds === 0 ? 1 : 0;
$device        = substr(trim((string) ($input['device'] ?? '')), 0, 255);
$ip            = api_client_ip();

// Idempotent on (employee_id, shift_id, window_start) — a retried minute updates in place.
$stmt = $conn->prepare("
    INSERT INTO tracker_activity
        (employee_id, shift_id, window_start, window_end, active_seconds, idle_seconds, max_idle_seconds, is_idle, device, ip_address)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        active_seconds = VALUES(active_seconds),
        idle_seconds = VALUES(idle_seconds),
        max_idle_seconds = VALUES(max_idle_seconds),
        is_idle = VALUES(is_idle),
        device = VALUES(device),
        ip_address = VALUES(ip_address)
");
$stmt->bind_param(
    'iissiiiiss',
    $employeeId,
    $shiftId,
    $windowStart,
    $windowEnd,
    $activeSeconds,
    $idleSeconds,
    $maxIdle,
    $isIdle,
    $device,
    $ip
);

if (!$stmt->execute()) {
    $stmt->close();
    api_error('Could not store activity sample.', 500);
}
$stmt->close();

api_respond([
    'success'        => true,
    'has_open_shift' => $openShiftId !== null,
    'stored'         => true,
    'shift_id'       => $shiftId,
    'window_start'   => $windowStart,
]);
