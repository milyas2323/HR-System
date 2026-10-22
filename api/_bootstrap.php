<?php
/**
 * Shared bootstrap for the desktop tracker API.
 *
 * All /api/*.php endpoints are JSON, stateless (no PHP session) and authed with a
 * bearer token from the api_tokens table — except login.php, which issues the token.
 * The desktop agent (Electron, Windows) is the only client.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tracker_schema.php';
ensureTrackerSchema($conn); // self-create tracker tables without touching the server's db.php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');

// Preflight
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/** Send a JSON response and stop. */
function api_respond($data, $httpCode = 200) {
    http_response_code($httpCode);
    echo json_encode($data);
    exit;
}

/** Send a JSON error and stop. */
function api_error($message, $httpCode = 400) {
    api_respond(['success' => false, 'error' => $message], $httpCode);
}

/** Require a given HTTP method or fail. */
function api_require_method($method) {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
        api_error('Method not allowed.', 405);
    }
}

/**
 * Read the request body as an associative array. Supports JSON bodies and
 * classic form-encoded POSTs so the agent can send either.
 */
function api_input() {
    $raw = file_get_contents('php://input');
    if ($raw !== '' && $raw !== false) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return $_POST;
}

/** Extract the bearer token from the Authorization header or a token field. */
function api_bearer_token() {
    $header = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $header = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strtolower($name) === 'authorization') {
                $header = $value;
                break;
            }
        }
    }

    if (stripos($header, 'Bearer ') === 0) {
        return trim(substr($header, 7));
    }

    // Fallbacks for environments that strip the header
    if (!empty($_REQUEST['token'])) {
        return trim($_REQUEST['token']);
    }
    $input = api_input();
    if (!empty($input['token'])) {
        return trim($input['token']);
    }
    return '';
}

/**
 * Authenticate the request. Returns the users row on success, or sends a 401.
 * Touches last_used_at so the admin can see live agent connections.
 */
function api_authenticate($conn) {
    $token = api_bearer_token();
    if ($token === '') {
        api_error('Missing authentication token.', 401);
    }

    $stmt = $conn->prepare("SELECT user_id FROM api_tokens WHERE token = ? AND revoked = 0 LIMIT 1");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $res = $stmt->get_result();
    $tokenRow = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$tokenRow) {
        api_error('Invalid or revoked token.', 401);
    }

    $userId = (int) $tokenRow['user_id'];
    $userStmt = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $userStmt->bind_param('i', $userId);
    $userStmt->execute();
    $userRes = $userStmt->get_result();
    $user = $userRes ? $userRes->fetch_assoc() : null;
    $userStmt->close();

    if (!$user) {
        api_error('Account no longer exists.', 401);
    }

    $touch = $conn->prepare("UPDATE api_tokens SET last_used_at = NOW() WHERE token = ?");
    $touch->bind_param('s', $token);
    $touch->execute();
    $touch->close();

    // Refresh the agent's current location (throttled internally to ~30 min / IP change).
    updateAgentLocation($conn, $token, api_client_ip());

    return $user;
}

/** Find the employee's currently open shift id, or null. */
function api_active_shift_id($conn, $employeeId) {
    $employeeId = (int) $employeeId;
    $stmt = $conn->prepare("SELECT id FROM shifts WHERE employee_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('i', $employeeId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ? (int) $row['id'] : null;
}

/** Client IP of the agent (reuses getUserIP from functions.php). */
function api_client_ip() {
    return getUserIP();
}
