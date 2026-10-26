<?php
/**
 * Admin-only: CSV export of the Shift Report for a date range (payroll-friendly).
 * Same filters as the tracker page: t_from, t_to, t_emp. Shift-accurate rows.
 */

include_once "../includes/db.php";
include_once "../includes/auth.php";
include_once "../includes/functions.php";
include_once "../includes/tracker_schema.php";
ensureTrackerSchema($conn);

if (($_SESSION['user']['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Access denied');
}

if (!function_exists('getShiftWorkSummary')) {
    // Without the span helper we still export activity; span column will be blank.
}
$hasShiftSummary = function_exists('getShiftWorkSummary');

define('TRK_WD_OFFSET', 6);
$WD = 'INTERVAL ' . TRK_WD_OFFSET . ' HOUR';

function trkVD($d) { $dt = DateTime::createFromFormat('Y-m-d', (string) $d); return $dt && $dt->format('Y-m-d') === $d; }
$today = date('Y-m-d', time() - TRK_WD_OFFSET * 3600);
$from = trkVD($_GET['t_from'] ?? '') ? $_GET['t_from'] : $today;
$to   = trkVD($_GET['t_to'] ?? '') ? $_GET['t_to'] : $today;
if ($from > $to) { $t = $from; $from = $to; $to = $t; }
$fromSql = mysqli_real_escape_string($conn, $from);
$toSql   = mysqli_real_escape_string($conn, $to);
$emp = (int) ($_GET['t_emp'] ?? 0);
$empClause = $emp > 0 ? " AND s.employee_id = $emp " : '';

$dbNow = function_exists('getDatabaseNowTimestamp') ? getDatabaseNowTimestamp($conn) : time();

$shiftRows = [];
$ids = [];
$res = $conn->query("
    SELECT s.*, u.name FROM shifts s JOIN users u ON u.id = s.employee_id
    WHERE DATE(s.start_time - $WD) BETWEEN '$fromSql' AND '$toSql' $empClause
    ORDER BY s.start_time DESC
");
if ($res) { while ($r = $res->fetch_assoc()) { $shiftRows[] = $r; $ids[] = (int) $r['id']; } }

$act = [];
if (!empty($ids)) {
    $idList = implode(',', array_map('intval', $ids));
    $r = $conn->query("SELECT shift_id, SUM(active_seconds) a, SUM(idle_seconds) i FROM tracker_activity WHERE shift_id IN ($idList) GROUP BY shift_id");
    if ($r) { while ($x = $r->fetch_assoc()) { $act[(int) $x['shift_id']] = $x; } }
}

$fname = 'shift-report_' . $from . '_to_' . $to . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Employee', 'Work Night', 'Clock In', 'Clock Out', 'Status', 'Shift Span', 'Dedicated Active', 'Dedicated Hours (decimal)', 'Idle', 'Idle %', 'Screenshots', 'Idle Alerts']);

function trkDur($s) { $s = max(0, (int) $s); return intdiv($s, 3600) . 'h ' . intdiv($s % 3600, 60) . 'm'; }

foreach ($shiftRows as $s) {
    $sid = (int) $s['id'];
    $a = isset($act[$sid]) ? (int) $act[$sid]['a'] : 0;
    $i = isset($act[$sid]) ? (int) $act[$sid]['i'] : 0;
    $pct = ($a + $i) > 0 ? round($i * 100 / ($a + $i)) : 0;
    $span = $hasShiftSummary ? (int) getShiftWorkSummary($s, $dbNow)['worked_seconds'] : 0;
    $workNight = date('Y-m-d', strtotime($s['start_time'] . ' -' . TRK_WD_OFFSET . ' hours'));

    // Screenshot + alert counts for this shift.
    $sc = 0;
    $cr = $conn->query("SELECT COUNT(*) c FROM tracker_screenshots WHERE shift_id = $sid");
    if ($cr) { $sc = (int) $cr->fetch_assoc()['c']; }
    $ac = 0;
    $ar = $conn->query("SELECT COUNT(*) c FROM tracker_idle_alerts WHERE shift_id = $sid");
    if ($ar) { $ac = (int) $ar->fetch_assoc()['c']; }

    fputcsv($out, [
        $s['name'],
        $workNight,
        date('Y-m-d h:i A', strtotime($s['start_time'])),
        !empty($s['end_time']) ? date('Y-m-d h:i A', strtotime($s['end_time'])) : 'Running',
        strtoupper((string) $s['status']),
        $hasShiftSummary ? trkDur($span) : '',
        trkDur($a),
        number_format($a / 3600, 2),
        trkDur($i),
        $pct . '%',
        $sc,
        $ac,
    ]);
}
fclose($out);
