<?php
/**
 * Admin-only JSON: minute-by-minute active/idle timeline for one shift.
 * Used by the Shift Report "Timeline" drilldown modal.
 */

include_once "../includes/db.php";
include_once "../includes/auth.php";
include_once "../includes/functions.php";
include_once "../includes/tracker_schema.php";
ensureTrackerSchema($conn);

header('Content-Type: application/json; charset=utf-8');

if (($_SESSION['user']['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

$shiftId = (int) ($_GET['shift'] ?? 0);
if ($shiftId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid shift']);
    exit;
}

$stmt = $conn->prepare("SELECT s.*, u.name FROM shifts s JOIN users u ON u.id = s.employee_id WHERE s.id = ? LIMIT 1");
$stmt->bind_param('i', $shiftId);
$stmt->execute();
$res = $stmt->get_result();
$shift = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$shift) {
    echo json_encode(['success' => false, 'error' => 'Shift not found']);
    exit;
}

// Minute samples for this shift, keyed by 'Y-m-d H:i'.
$minutes = [];
$stmt = $conn->prepare("SELECT window_start, active_seconds, idle_seconds FROM tracker_activity WHERE shift_id = ? ORDER BY window_start ASC");
$stmt->bind_param('i', $shiftId);
$stmt->execute();
$res = $stmt->get_result();
$totA = 0; $totI = 0;
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $key = date('Y-m-d H:i', strtotime($r['window_start']));
        $minutes[$key] = ['a' => (int) $r['active_seconds'], 'i' => (int) $r['idle_seconds']];
        $totA += (int) $r['active_seconds'];
        $totI += (int) $r['idle_seconds'];
    }
}
$stmt->close();

echo json_encode([
    'success' => true,
    'shift' => [
        'id' => $shiftId,
        'name' => $shift['name'],
        'start' => $shift['start_time'],
        'end' => $shift['end_time'],
        'status' => $shift['status'],
        'active_seconds' => $totA,
        'idle_seconds' => $totI,
    ],
    'minutes' => $minutes,
    'server_now' => date('Y-m-d H:i:s'),
]);
