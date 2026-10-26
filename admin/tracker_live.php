<?php
/**
 * Admin-only JSON: current "Right Now" status per employee.
 * Polled by the tracker page so the live board updates without a full reload.
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

$dbNowTs = function_exists('getDatabaseNowTimestamp') ? getDatabaseNowTimestamp($conn) : time();
$empFilter = (int) ($_GET['t_emp'] ?? 0);
$ACTIVE_WINDOW = 150;
$IDLE_THRESHOLD = 300;

$sql = "
    SELECT u.id, u.name,
        s.id AS open_shift_id,
        s.current_location AS shift_location,
        (SELECT ta.window_start FROM tracker_activity ta WHERE ta.employee_id = u.id ORDER BY ta.window_start DESC LIMIT 1) AS last_window,
        (SELECT ta.is_idle FROM tracker_activity ta WHERE ta.employee_id = u.id ORDER BY ta.window_start DESC LIMIT 1) AS last_idle,
        (SELECT ta.max_idle_seconds FROM tracker_activity ta WHERE ta.employee_id = u.id ORDER BY ta.window_start DESC LIMIT 1) AS last_max_idle,
        (SELECT MAX(t.last_used_at) FROM api_tokens t WHERE t.user_id = u.id AND t.revoked = 0) AS agent_seen,
        (SELECT t2.last_location FROM api_tokens t2 WHERE t2.user_id = u.id AND t2.revoked = 0 AND t2.last_location IS NOT NULL ORDER BY t2.last_used_at DESC LIMIT 1) AS last_location
    FROM users u
    LEFT JOIN shifts s ON s.employee_id = u.id AND s.status = 'active'
    WHERE u.role = 'employee' " . ($empFilter > 0 ? " AND u.id = $empFilter " : '') . "
    ORDER BY u.name ASC
";

$rows = [];
$res = $conn->query($sql);
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $lastTs = !empty($r['last_window']) ? strtotime($r['last_window']) : 0;
        $age = $lastTs ? ($dbNowTs - $lastTs) : PHP_INT_MAX;
        if ($age <= $ACTIVE_WINDOW && (int) $r['last_idle'] === 0) { $status = 'active'; }
        elseif ($age <= $ACTIVE_WINDOW) { $status = 'idle'; }
        elseif ($age <= $IDLE_THRESHOLD) { $status = 'away'; }
        else { $status = 'offline'; }

        $shiftLoc = trim((string) ($r['shift_location'] ?? ''));
        if ($shiftLoc !== '' && stripos($shiftLoc, 'unknown') === false) {
            $locText = explode(',', $shiftLoc)[0]; $locSrc = 'check-in GPS';
        } elseif (!empty($r['last_location'])) {
            $locText = $r['last_location']; $locSrc = 'approx. by IP';
        } else { $locText = 'Locating…'; $locSrc = ''; }

        $rows[] = [
            'name' => $r['name'],
            'status' => $status,
            'shift_open' => $r['open_shift_id'] ? true : false,
            'location' => $locText,
            'loc_src' => $locSrc,
            'seen' => !empty($r['agent_seen']) ? date('h:i A', strtotime($r['agent_seen'])) : 'never',
            'idle_secs' => (int) $r['last_max_idle'],
            'idle_label' => (in_array($status, ['idle', 'away'], true) && !empty($r['last_max_idle']))
                ? (intdiv((int) $r['last_max_idle'], 3600) . 'h ' . intdiv(((int) $r['last_max_idle']) % 3600, 60) . 'm')
                : '',
        ];
    }
}

echo json_encode(['success' => true, 'rows' => $rows, 'now' => date('h:i:s A')]);
