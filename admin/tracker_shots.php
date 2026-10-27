<?php
/**
 * Admin-only JSON: screenshots for the given filters (date range + employee),
 * polled by the tracker page so the gallery auto-refreshes without a reload.
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

if (!function_exists('formatWorkDuration')) {
    function formatWorkDuration($s) { $s = max(0, (int) $s); return intdiv($s, 3600) . 'h ' . intdiv($s % 3600, 60) . 'm'; }
}

$WD = 'INTERVAL 6 HOUR';
function trkVD($d) { $dt = DateTime::createFromFormat('Y-m-d', (string) $d); return $dt && $dt->format('Y-m-d') === $d; }
$today = date('Y-m-d', time() - 6 * 3600);
$from = trkVD($_GET['t_from'] ?? '') ? $_GET['t_from'] : $today;
$to   = trkVD($_GET['t_to'] ?? '') ? $_GET['t_to'] : $today;
if ($from > $to) { $t = $from; $from = $to; $to = $t; }
$fromSql = mysqli_real_escape_string($conn, $from);
$toSql   = mysqli_real_escape_string($conn, $to);
$emp = (int) ($_GET['t_emp'] ?? 0);
$empClause = $emp > 0 ? " AND ts.employee_id = $emp " : '';

$rows = [];
$res = $conn->query("
    SELECT ts.*, u.name
    FROM tracker_screenshots ts
    JOIN users u ON u.id = ts.employee_id
    WHERE DATE(ts.captured_at - $WD) BETWEEN '$fromSql' AND '$toSql'
    $empClause
    ORDER BY ts.captured_at DESC
    LIMIT 300
");
if ($res) {
    while ($s = $res->fetch_assoc()) {
        $ts = strtotime($s['captured_at']);
        $rows[] = [
            'id' => (int) $s['id'],
            'url' => '../' . ltrim((string) $s['file'], '/'),
            'name' => $s['name'],
            'time' => date('d M, h:i:s A', $ts),
            'fulltime' => date('d M Y, h:i:s A', $ts),
            'is_idle' => (int) $s['is_idle'] === 1,
            'idle_label' => formatWorkDuration((int) $s['idle_seconds']),
        ];
    }
}

echo json_encode(['success' => true, 'rows' => $rows]);
