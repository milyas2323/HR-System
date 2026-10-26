<?php
/**
 * POST /api/idle_alert.php
 * The desktop agent records one row here each time it warns the employee for
 * being idle (10+ min during a shift). Admin reports then show alert counts.
 * Body: { "idle_seconds": <int> }
 */

require_once __DIR__ . '/_bootstrap.php';

api_require_method('POST');

$user = api_authenticate($conn);
$employeeId = (int) $user['id'];
$shiftId = api_active_shift_id($conn, $employeeId);
$shiftId = $shiftId === null ? 0 : $shiftId;

$input = api_input();
$idle = max(0, (int) ($input['idle_seconds'] ?? 0));

$stmt = $conn->prepare("INSERT INTO tracker_idle_alerts (employee_id, shift_id, idle_seconds) VALUES (?, ?, ?)");
$stmt->bind_param('iii', $employeeId, $shiftId, $idle);
$ok = $stmt->execute();
$id = $stmt->insert_id;
$stmt->close();

if (!$ok) {
    api_error('Could not record alert.', 500);
}

// Recompute this shift's idle-alert fine (5 free, then PKR 100 each).
if ($shiftId > 0) {
    syncIdleAlertPenalty($conn, $shiftId);
}

api_respond(['success' => true, 'id' => $id, 'shift_id' => $shiftId]);
