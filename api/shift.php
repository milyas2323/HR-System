<?php
/**
 * GET /api/shift.php
 * Returns the authenticated employee's currently open shift (if any) so the
 * agent knows whether to attach activity, or prompt the user to check in on
 * the web first (web check-in stays the geofenced shift opener).
 */

require_once __DIR__ . '/_bootstrap.php';

api_require_method('GET');

$user = api_authenticate($conn);
$employeeId = (int) $user['id'];

$shift = null;
$stmt = $conn->prepare("SELECT id, start_time, status FROM shifts WHERE employee_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
$stmt->bind_param('i', $employeeId);
$stmt->execute();
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$stmt->close();

if ($row) {
    $shift = [
        'id'         => (int) $row['id'],
        'start_time' => $row['start_time'],
        'status'     => $row['status'],
    ];
}

api_respond([
    'success'       => true,
    'has_open_shift'=> $shift !== null,
    'shift'         => $shift,
    'server_time'   => date('Y-m-d H:i:s'),
]);
