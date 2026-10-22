<?php
/**
 * Admin-only: delete a single tracker screenshot (file + DB row).
 * Called via fetch() from the Activity Tracker gallery. Session-authed (admin).
 */

include_once "../includes/db.php";
include_once "../includes/auth.php";
include_once "../includes/functions.php";

header('Content-Type: application/json; charset=utf-8');

if (($_SESSION['user']['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid screenshot id']);
    exit;
}

$stmt = $conn->prepare("SELECT file FROM tracker_screenshots WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$row) {
    echo json_encode(['success' => false, 'error' => 'Screenshot not found']);
    exit;
}

// Remove the image file (ignore if already gone).
$path = __DIR__ . '/../' . ltrim((string) $row['file'], '/');
if (is_file($path)) {
    @unlink($path);
}

$del = $conn->prepare("DELETE FROM tracker_screenshots WHERE id = ?");
$del->bind_param('i', $id);
$ok = $del->execute();
$del->close();

if (!$ok) {
    echo json_encode(['success' => false, 'error' => 'Could not delete record']);
    exit;
}

echo json_encode(['success' => true, 'id' => $id]);
