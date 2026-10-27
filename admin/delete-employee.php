<?php
/**
 * Permanently delete an employee AND everything related to them:
 * tracker screenshots (image files + rows), activity, idle alerts, api tokens,
 * shifts (+ check-in screenshot files), hourly updates, reports, penalties,
 * bonuses, leave/requests, login logs, profile picture, and finally the user.
 */

include "../includes/db.php";
include "../includes/auth.php";
include_once "../includes/functions.php";

if (($_SESSION['user']['role'] ?? '') !== 'admin') {
    exit("Access Denied");
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header("Location: dashboard.php?page=employees");
    exit();
}

/** Delete rows only when the table and column actually exist (avoids query errors). */
function empSafeDelete($conn, $table, $col, $id) {
    $t = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    if (!$t || $t->num_rows === 0) { return; }
    $c = $conn->query("SHOW COLUMNS FROM `$table` LIKE '" . $conn->real_escape_string($col) . "'");
    if (!$c || $c->num_rows === 0) { return; }
    $conn->query("DELETE FROM `$table` WHERE `$col` = " . (int) $id);
}

$base = __DIR__ . '/..';

// --- 1. Delete files on disk ---

// Tracker screenshots folder (uploads/tracker/<id>/).
$trackerDir = $base . '/uploads/tracker/' . $id;
if (is_dir($trackerDir)) {
    foreach (glob($trackerDir . '/*') as $f) { @unlink($f); }
    @rmdir($trackerDir);
}

// Any tracker screenshot files recorded elsewhere (safety).
$res = $conn->query("SHOW TABLES LIKE 'tracker_screenshots'");
if ($res && $res->num_rows > 0) {
    $r = $conn->query("SELECT file FROM tracker_screenshots WHERE employee_id = $id");
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $p = $base . '/' . ltrim((string) $row['file'], '/');
            if (is_file($p)) { @unlink($p); }
        }
    }
}

// Check-in screenshots referenced in shifts (uploads/screenshots/<file>).
$r = $conn->query("SELECT screenshot FROM shifts WHERE employee_id = $id AND screenshot IS NOT NULL AND screenshot <> ''");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $p = $base . '/uploads/screenshots/' . basename((string) $row['screenshot']);
        if (is_file($p)) { @unlink($p); }
    }
}

// Profile picture.
$r = $conn->query("SELECT profile_pic FROM users WHERE id = $id");
if ($r && ($u = $r->fetch_assoc()) && !empty($u['profile_pic'])) {
    $p = $base . '/uploads/profile/' . basename((string) $u['profile_pic']);
    if (is_file($p)) { @unlink($p); }
}

// --- 2. Delete DB rows from every related table ---
$byEmployee = [
    'shifts', 'hourly_updates', 'end_reports', 'penalties', 'leave_requests',
    'employee_requests', 'bonuses', 'bonus_logs', 'activity_pings',
    'short_hours_relaxations', 'absence_relaxations',
    'penalty_archive', 'penalty_monthly_archive',
    'tracker_screenshots', 'tracker_activity', 'tracker_idle_alerts',
];
foreach ($byEmployee as $t) { empSafeDelete($conn, $t, 'employee_id', $id); }

$byUser = ['login_logs', 'api_tokens'];
foreach ($byUser as $t) { empSafeDelete($conn, $t, 'user_id', $id); }

// --- 3. Finally, the employee record ---
$conn->query("DELETE FROM users WHERE id = $id");

$_SESSION['msg'] = 'Employee and all related data (shifts, screenshots, activity, fines, tokens) permanently deleted.';
header("Location: dashboard.php?page=employees");
exit();
