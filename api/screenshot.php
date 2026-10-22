<?php
/**
 * POST /api/screenshot.php
 * Uploads one periodic screen capture from the desktop agent.
 * Accepts either:
 *   - multipart/form-data with file field "screenshot" (preferred, JPEG), or
 *   - JSON/form with "screenshot_data" as a base64 data URI.
 * Optional fields: captured_at, idle_seconds, is_idle, device.
 * Saved under uploads/tracker/<employee_id>/ and linked to the open shift.
 */

require_once __DIR__ . '/_bootstrap.php';

api_require_method('POST');

$user = api_authenticate($conn);
$employeeId = (int) $user['id'];
$openShiftId = api_active_shift_id($conn, $employeeId);
$shiftId = $openShiftId === null ? 0 : $openShiftId; // 0 = captured off-shift (always-on tracking)

$input = $_POST;
if (empty($input)) {
    $input = api_input();
}

$folder = __DIR__ . '/../uploads/tracker/' . $employeeId . '/';
if (!is_dir($folder)) {
    mkdir($folder, 0777, true);
}

$binary = '';
$ext = 'jpg';

if (!empty($_FILES['screenshot']) && is_uploaded_file($_FILES['screenshot']['tmp_name'])) {
    $binary = file_get_contents($_FILES['screenshot']['tmp_name']);
    $origName = strtolower($_FILES['screenshot']['name'] ?? '');
    if (substr($origName, -4) === '.png') {
        $ext = 'png';
    }
} elseif (!empty($input['screenshot_data'])) {
    $data = (string) $input['screenshot_data'];
    if (strpos($data, 'image/png') !== false) {
        $ext = 'png';
    }
    $data = preg_replace('#^data:image/\w+;base64,#', '', $data);
    $data = str_replace(' ', '+', $data);
    $binary = base64_decode($data, true) ?: '';
}

if ($binary === '' || $binary === false) {
    api_error('No screenshot payload received.', 422);
}

// Guard against oversized uploads (cap ~8 MB per capture).
if (strlen($binary) > 8 * 1024 * 1024) {
    api_error('Screenshot too large.', 413);
}

$fileName = date('Ymd_His') . '_' . $employeeId . '_' . substr(bin2hex(random_bytes(3)), 0, 6) . '.' . $ext;
if (file_put_contents($folder . $fileName, $binary) === false) {
    api_error('Could not save screenshot.', 500);
}

$relPath = 'uploads/tracker/' . $employeeId . '/' . $fileName;

$capturedAtRaw = trim((string) ($input['captured_at'] ?? ''));
$ts = $capturedAtRaw !== '' ? strtotime($capturedAtRaw) : false;
$capturedAt = date('Y-m-d H:i:s', $ts !== false ? $ts : time());

$idleSeconds = max(0, (int) ($input['idle_seconds'] ?? 0));
$isIdle = (int) (!empty($input['is_idle']) ? 1 : 0);
$device = substr(trim((string) ($input['device'] ?? '')), 0, 255);
$ip = api_client_ip();

$stmt = $conn->prepare("
    INSERT INTO tracker_screenshots
        (employee_id, shift_id, file, captured_at, is_idle, idle_seconds, device, ip_address)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->bind_param(
    'iissiiss',
    $employeeId,
    $shiftId,
    $relPath,
    $capturedAt,
    $isIdle,
    $idleSeconds,
    $device,
    $ip
);

if (!$stmt->execute()) {
    $stmt->close();
    api_error('Could not record screenshot.', 500);
}
$insertId = $stmt->insert_id;
$stmt->close();

api_respond([
    'success'     => true,
    'id'          => $insertId,
    'file'        => $relPath,
    'shift_id'    => $shiftId,
    'captured_at' => $capturedAt,
]);
