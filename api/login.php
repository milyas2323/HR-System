<?php
/**
 * POST /api/login.php
 * Body: { "email": "...", "password": "...", "device": "optional label" }
 * Returns a bearer token for the desktop tracker agent plus basic profile info.
 */

require_once __DIR__ . '/_bootstrap.php';

api_require_method('POST');

$input = api_input();
$email = trim((string) ($input['email'] ?? ''));
$password = (string) ($input['password'] ?? '');
$device = trim((string) ($input['device'] ?? 'Desktop Tracker'));

if ($email === '' || $password === '') {
    api_error('Email and password are required.', 422);
}

$stmt = $conn->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
$stmt->bind_param('s', $email);
$stmt->execute();
$res = $stmt->get_result();
$user = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$user || !verifyPassword($password, $user['password'])) {
    api_error('Invalid email or password.', 401);
}

// Issue a fresh token (collision-safe on the unique index).
$token = bin2hex(random_bytes(32));
$userId = (int) $user['id'];
$ip = api_client_ip();
$device = substr($device, 0, 255);

$ins = $conn->prepare("INSERT INTO api_tokens (user_id, token, device, ip_address, last_used_at) VALUES (?, ?, ?, ?, NOW())");
$ins->bind_param('isss', $userId, $token, $device, $ip);
if (!$ins->execute()) {
    $ins->close();
    api_error('Could not create session. Please try again.', 500);
}
$ins->close();

// Seed the agent's location immediately so admins see it without waiting for the first ping.
updateAgentLocation($conn, $token, $ip);

api_respond([
    'success'      => true,
    'token'        => $token,
    'user'         => [
        'id'    => $userId,
        'name'  => $user['name'],
        'email' => $user['email'],
        'role'  => normalizeUserRole($user['role'] ?? 'employee'),
    ],
    'active_shift_id' => api_active_shift_id($conn, $userId),
    'server_time'     => date('Y-m-d H:i:s'),
]);
