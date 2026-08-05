<?php
if(session_status() == PHP_SESSION_NONE){
    session_start();
}

/**
 * Normalize role from DB/session (admin, employee).
 */
function normalizeUserRole($role) {
    return strtolower(trim((string) $role));
}

/**
 * Redirect URL for a user role after login.
 */
function dashboardUrlForRole($role) {
    return normalizeUserRole($role) === 'admin'
        ? 'admin/dashboard.php'
        : 'employee/dashboard.php';
}

/**
 * Reload the logged-in user from DB and normalize role.
 * Returns refreshed user array or null when session/user is invalid.
 */
function refreshSessionUserFromDatabase($conn) {
    if (!isset($_SESSION['user']['id'])) {
        return null;
    }

    $userId = (int) $_SESSION['user']['id'];
    if ($userId <= 0) {
        unset($_SESSION['user']);
        return null;
    }

    $result = $conn->query("SELECT * FROM users WHERE id='$userId' LIMIT 1");
    if (!$result || $result->num_rows === 0) {
        unset($_SESSION['user']);
        return null;
    }

    $user = $result->fetch_assoc();
    $user['role'] = normalizeUserRole($user['role'] ?? '');
    $_SESSION['user'] = $user;
    return $user;
}

/**
 * Validate live location data sent with an hourly update.
 */
function validateHourlyUpdateLocationSubmission($postData) {
    $location = trim((string) ($postData['current_location'] ?? ''));
    $latitude = trim((string) ($postData['current_latitude'] ?? ''));
    $longitude = trim((string) ($postData['current_longitude'] ?? ''));
    $accuracy = trim((string) ($postData['location_accuracy'] ?? ''));

    if ($latitude === '' || $longitude === '' || !is_numeric($latitude) || !is_numeric($longitude)) {
        return [
            'valid' => false,
            'message' => 'Location access is required. Allow browser location permission and try again.',
        ];
    }

    $lat = (float) $latitude;
    $lng = (float) $longitude;
    if (abs($lat) < 0.000001 && abs($lng) < 0.000001) {
        return [
            'valid' => false,
            'message' => 'Invalid location coordinates. Enable GPS/location access and try again.',
        ];
    }

    if ($location === '' || strcasecmp($location, 'Unknown Location') === 0) {
        $location = 'Lat: ' . $latitude . ', Lng: ' . $longitude;
    }

    if ($accuracy !== '' && is_numeric($accuracy)) {
        $location .= ' (GPS accuracy: ~' . (int) $accuracy . 'm)';
    }

    return [
        'valid' => true,
        'location' => $location,
        'latitude' => $latitude,
        'longitude' => $longitude,
    ];
}

/**
 * Process employee hourly update form submission.
 */
function processEmployeeHourlyUpdateSubmission($conn, $employeeId, $updateText, $postData = []) {
    $employeeId = (int) $employeeId;
    $updateText = trim((string) $updateText);
    $result = [
        'success' => false,
        'message' => '',
        'messageType' => 'danger',
        'popup' => null,
    ];

    if ($updateText === '') {
        $result['message'] = 'Please write a summary of your task before submitting.';
        return $result;
    }

    $activeShift = $conn->query("
        SELECT id, start_time FROM shifts
        WHERE employee_id='$employeeId' AND status='active'
        LIMIT 1
    ")->fetch_assoc();

    if (!$activeShift) {
        $result['message'] = 'You must start your shift before submitting hourly updates.';
        return $result;
    }

    $dbNowTs = getDatabaseNowTimestamp($conn);
    $slots = getHourlySlotDefinitionsForShift($activeShift['start_time']);
    $slot = findHourlySlotForTimestamp($slots, $dbNowTs);
    $shiftId = (int) $activeShift['id'];

    if (!$slot) {
        $result['message'] = 'Submission rejected: updates are only accepted in each 15-minute window (e.g. 7:00–7:15 PM). The current time is outside all valid slots.';
        return $result;
    }

    if (hasHourlyUpdateInSlot($conn, $employeeId, $shiftId, $slot['slot_date'], $slot['slot_hour'])) {
        $result['message'] = 'You already submitted an update for the ' . $slot['label'] . ' slot. Duplicate entries are not allowed.';
        return $result;
    }

    $locationCheck = validateHourlyUpdateLocationSubmission($postData);
    if (!$locationCheck['valid']) {
        $result['message'] = $locationCheck['message'];
        return $result;
    }

    $ua = parseUserAgent($_SERVER['HTTP_USER_AGENT'] ?? '');
    $submitDevice = $ua['device'] . ' (' . $ua['os'] . ' / ' . $ua['browser'] . ')';
    $submitIp = getUserIP();
    $submitLocation = $locationCheck['location'];

    $stmt = $conn->prepare("
        INSERT INTO hourly_updates (employee_id, shift_id, slot_date, slot_hour, is_grandfathered, update_text, ip_address, device, current_location)
        VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?)
    ");
    $slotHour = (int) $slot['slot_hour'];
    $stmt->bind_param('iisissss', $employeeId, $shiftId, $slot['slot_date'], $slotHour, $updateText, $submitIp, $submitDevice, $submitLocation);

    if ($stmt->execute()) {
        $result['success'] = true;
        $result['messageType'] = 'success';
        $result['popup'] = [
            'slot' => $slot['label'],
            'submitted_at' => date('h:i A - d M Y'),
        ];
        return $result;
    }

    if (strpos($conn->error, 'uniq_employee_shift_slot') !== false) {
        $result['message'] = 'Duplicate blocked: an update for this time slot was already recorded.';
    } else {
        $result['message'] = 'Database Error: ' . $conn->error;
    }

    return $result;
}

/**
 * Admin submits an hourly update on behalf of an employee (no time-window check).
 */
function processAdminHourlyUpdateSubmission($conn, $adminId, $employeeId, $slotDate, $slotHour, $updateText) {
    $adminId = (int) $adminId;
    $employeeId = (int) $employeeId;
    $slotHour = (int) $slotHour;
    $updateText = trim((string) $updateText);
    $slotDate = trim((string) $slotDate);
    $result = [
        'success' => false,
        'message' => '',
        'messageType' => 'danger',
    ];

    if ($employeeId <= 0) {
        $result['message'] = 'Please select an employee.';
        return $result;
    }

    if ($updateText === '') {
        $result['message'] = 'Please enter update text.';
        return $result;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $slotDate)) {
        $result['message'] = 'Invalid slot date.';
        return $result;
    }

    $empRes = $conn->query("SELECT id, name FROM users WHERE id='$employeeId' AND role='employee' LIMIT 1");
    if (!$empRes || $empRes->num_rows === 0) {
        $result['message'] = 'Employee not found.';
        return $result;
    }
    $employee = $empRes->fetch_assoc();

    if (!$activeShift) {
        $result['message'] = 'This employee has no active shift. Start a shift first, then submit the hourly update.';
        return $result;
    }

    $shiftId = (int) $activeShift['id'];
    $slots = getHourlySlotDefinitionsForShift($activeShift['start_time']);
    $slotValid = false;
    $slotLabel = '';
    foreach ($slots as $slot) {
        if ($slot['slot_date'] === $slotDate && (int) $slot['slot_hour'] === $slotHour) {
            $slotValid = true;
            $slotLabel = $slot['label'];
            break;
        }
    }

    if (!$slotValid) {
        $result['message'] = 'Invalid hourly slot selected for this employee\'s active shift.';
        return $result;
    }

    if (hasHourlyUpdateInSlot($conn, $employeeId, $shiftId, $slotDate, $slotHour)) {
        $result['message'] = 'This slot already has an update for ' . $employee['name'] . '.';
        return $result;
    }

    $adminUser = $conn->query("SELECT name FROM users WHERE id='$adminId' LIMIT 1")->fetch_assoc();
    $adminName = $adminUser['name'] ?? 'Admin';
    $deviceLabel = 'Admin submission by ' . $adminName;

    $stmt = $conn->prepare("
        INSERT INTO hourly_updates (
            employee_id, shift_id, slot_date, slot_hour, is_grandfathered, admin_submitted_by,
            update_text, ip_address, device, current_location
        ) VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?)
    ");
    $submitIp = getUserIP();
    $locationNote = 'Submitted by admin';
    $stmt->bind_param('iisiissss', $employeeId, $shiftId, $slotDate, $slotHour, $adminId, $updateText, $submitIp, $deviceLabel, $locationNote);

    if ($stmt->execute()) {
        $result['success'] = true;
        $result['messageType'] = 'success';
        $result['message'] = 'Hourly update saved for ' . $employee['name'] . ' (' . $slotLabel . ').';
        return $result;
    }

    if (strpos($conn->error, 'uniq_employee_shift_slot') !== false) {
        $result['message'] = 'Duplicate blocked: this slot already has an update.';
    } else {
        $result['message'] = 'Database error: ' . $conn->error;
    }
    return $result;
}

/**
 * Process employee end-of-shift report submission.
 */
function processEmployeeEndReportSubmission($conn, $employeeId, $reportText) {
    $employeeId = (int) $employeeId;
    $reportText = trim((string) $reportText);
    $result = ['success' => false, 'message' => '', 'messageType' => 'danger'];

    if ($reportText === '') {
        $result['message'] = 'Please enter your report text before submitting.';
        return $result;
    }

    $active = $conn->query("
        SELECT id FROM shifts
        WHERE employee_id='$employeeId' AND status='active'
        LIMIT 1
    ")->fetch_assoc();

    if (!$active) {
        $result['message'] = 'No active shift found to close.';
        return $result;
    }

    $shiftId = (int) $active['id'];
    $stmt = $conn->prepare("
        INSERT INTO end_reports (employee_id, shift_id, report_text)
        VALUES (?, ?, ?)
    ");
    $stmt->bind_param('iis', $employeeId, $shiftId, $reportText);

    if (!$stmt->execute()) {
        $result['message'] = 'Database Error: ' . $conn->error;
        return $result;
    }

    $conn->query("UPDATE shifts SET status='closed', end_time=NOW() WHERE id='$shiftId'");
    $result['success'] = true;
    $result['messageType'] = 'success';
    $result['message'] = 'Shift closed and daily report submitted successfully!';
    return $result;
}

/**
 * Process employee leave request submission.
 */
function processEmployeeLeaveRequestSubmission($conn, $employeeId, $reason, $fromDate, $toDate) {
    $employeeId = (int) $employeeId;
    $reason = trim((string) $reason);
    $fromDate = trim((string) $fromDate);
    $toDate = trim((string) $toDate);
    $result = ['success' => false, 'message' => '', 'messageType' => 'danger'];

    if ($reason === '' || $fromDate === '' || $toDate === '') {
        $result['message'] = 'Please complete all fields.';
        return $result;
    }

    if (strtotime($fromDate) > strtotime($toDate)) {
        $result['message'] = "The 'From Date' must be before or equal to the 'To Date'.";
        return $result;
    }

    $stmt = $conn->prepare("
        INSERT INTO leave_requests (employee_id, reason, from_date, to_date, status)
        VALUES (?, ?, ?, ?, 'pending')
    ");
    $stmt->bind_param('isss', $employeeId, $reason, $fromDate, $toDate);

    if ($stmt->execute()) {
        $result['success'] = true;
        $result['messageType'] = 'success';
        $result['message'] = 'Leave request submitted successfully. Waiting for admin approval!';
        return $result;
    }

    $result['message'] = 'Database Error: ' . $conn->error;
    return $result;
}

/**
 * Process employee profile picture upload.
 */
function processEmployeeProfileUpload($conn, $employeeId, $fileInput) {
    $employeeId = (int) $employeeId;
    $result = ['success' => false, 'message' => '', 'messageType' => 'danger', 'filename' => null];

    if (empty($fileInput['name'])) {
        $result['message'] = 'Please select an image file to upload.';
        return $result;
    }

    $ext = pathinfo($fileInput['name'], PATHINFO_EXTENSION);
    $newFilename = time() . '_' . $employeeId . '.' . $ext;
    $folder = __DIR__ . '/../uploads/profile/';

    if (!is_dir($folder)) {
        mkdir($folder, 0777, true);
    }

    $path = $folder . $newFilename;
    if (!move_uploaded_file($fileInput['tmp_name'], $path)) {
        $result['message'] = 'File transfer failed. Verify write permissions on uploads/profile/ folder.';
        return $result;
    }

    $newFilenameEsc = mysqli_real_escape_string($conn, $newFilename);
    $ok = $conn->query("UPDATE users SET profile_pic='$newFilenameEsc' WHERE id='$employeeId'");

    if (!$ok) {
        $result['message'] = 'Database error: ' . $conn->error;
        return $result;
    }

    $result['success'] = true;
    $result['messageType'] = 'success';
    $result['message'] = 'Profile picture uploaded successfully!';
    $result['filename'] = $newFilename;
    return $result;
}

/**
 * Process employee shift check-in.
 */
function processEmployeeStartShift($conn, $employeeId, $postData) {
    $employeeId = (int) $employeeId;
    $result = ['success' => false, 'message' => '', 'messageType' => 'danger'];

    $message = mysqli_real_escape_string($conn, trim($postData['message'] ?? ''));
    $location = trim($postData['current_location'] ?? '');
    $latitude = trim($postData['current_latitude'] ?? '');
    $longitude = trim($postData['current_longitude'] ?? '');
    $locationAccuracy = trim($postData['location_accuracy'] ?? '');

    if ($locationAccuracy !== '' && is_numeric($locationAccuracy)) {
        $location .= ' (GPS accuracy: ~' . (int) $locationAccuracy . 'm)';
    }

    if ($latitude === '' || $longitude === '' || !is_numeric($latitude) || !is_numeric($longitude)) {
        $result['message'] = 'Workstation location is required. Allow browser location access and try again.';
        return $result;
    }

    $check = $conn->query("SELECT id FROM shifts WHERE employee_id='$employeeId' AND status='active' LIMIT 1");
    if ($check && $check->num_rows > 0) {
        $result['message'] = 'Shift is already active!';
        return $result;
    }

    $fileName = '';
    if (!empty($postData['screenshot_data'])) {
        $image = str_replace('data:image/png;base64,', '', $postData['screenshot_data']);
        $image = str_replace(' ', '+', $image);
        $imageData = base64_decode($image);
        $folder = __DIR__ . '/../uploads/screenshots/';
        if (!is_dir($folder)) {
            mkdir($folder, 0777, true);
        }
        $fileName = time() . '_' . $employeeId . '.png';
        file_put_contents($folder . $fileName, $imageData);
        $fileName = mysqli_real_escape_string($conn, $fileName);
    }

    $ip = mysqli_real_escape_string($conn, getUserIP());
    $ua = parseUserAgent($_SERVER['HTTP_USER_AGENT'] ?? '');
    $device = mysqli_real_escape_string($conn, $ua['device'] . ' (' . $ua['os'] . ' / ' . $ua['browser'] . ')');
    $location = mysqli_real_escape_string($conn, $location);
    $latitude = mysqli_real_escape_string($conn, $latitude);
    $longitude = mysqli_real_escape_string($conn, $longitude);

    $insert = $conn->query("
        INSERT INTO shifts
        (employee_id, screenshot, morning_message, start_time, status, ip_address, device, current_location, current_latitude, current_longitude)
        VALUES
        ('$employeeId', '$fileName', '$message', NOW(), 'active', '$ip', '$device', '$location', '$latitude', '$longitude')
    ");

    if ($insert) {
        $result['success'] = true;
        $result['messageType'] = 'success';
        $result['message'] = 'Shift started successfully!';
        return $result;
    }

    $result['message'] = 'Database Error starting shift.';
    return $result;
}

/**
 * Process admin leave approve/reject action.
 */
function processAdminLeaveRequestAction($conn, $requestId, $action) {
    $requestId = (int) $requestId;
    $action = strtolower(trim((string) $action));

    if (!in_array($action, ['approved', 'rejected'], true)) {
        return ['success' => false, 'message' => 'Invalid leave action.'];
    }

    $responseMsg = ($action === 'approved')
        ? 'Leave request approved by admin.'
        : 'Leave request rejected.';

    $stmt = $conn->prepare("UPDATE leave_requests SET status=?, message=? WHERE id=?");
    $stmt->bind_param('ssi', $action, $responseMsg, $requestId);
    $stmt->execute();

    return [
        'success' => true,
        'message' => 'Leave request successfully ' . $action . '!',
    ];
}

/**
 * Process admin misconduct penalty form.
 */
function processAdminMisconductPenalty($conn, $employeeId, $reason, $amount) {
    $employeeId = (int) $employeeId;
    $reason = trim((string) $reason);
    $amount = floatval($amount);

    if ($employeeId <= 0 || $reason === '' || $amount <= 0) {
        return ['success' => false, 'message' => 'Please verify all form inputs.'];
    }

    addPenalty($conn, $employeeId, $reason, $amount);

    return [
        'success' => true,
        'message' => 'Misconduct penalty of PKR ' . number_format($amount) . ' applied successfully!',
    ];
}

/**
 * Log a penalty and deduct salary
 */
function addPenalty($conn, $employee_id, $reason, $amount){
    $employee_id = intval($employee_id);
    $amount = floatval($amount);
    $reason = mysqli_real_escape_string($conn, $reason);

    $conn->query("INSERT INTO penalties (employee_id, reason, amount, created_at)
                  VALUES ('$employee_id', '$reason', '$amount', NOW())");

    $conn->query("UPDATE users
                  SET total_deduction = total_deduction + $amount
                  WHERE id='$employee_id'");
}

/**
 * Retrieve the client's actual IP address
 */
function getUserIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    return trim($ip);
}

/**
 * Parse browser, OS, and device type from User Agent string
 */
function parseUserAgent($userAgent) {
    $browser = "Unknown Browser";
    $os = "Unknown OS";
    $device = "Desktop";

    // 1. Device Type
    if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i', $userAgent)) {
        $device = "Tablet";
    } elseif (preg_match('/(mobi|ipod|phone|blackberry|opera mini|fennec|minimo|symbian|psp|nintendo|windows phone)/i', $userAgent)) {
        $device = "Mobile";
    }

    // 2. OS Detection
    if (preg_match('/windows|win32/i', $userAgent)) {
        $os = 'Windows';
    } elseif (preg_match('/macintosh|mac os x/i', $userAgent)) {
        $os = 'macOS';
    } elseif (preg_match('/linux/i', $userAgent)) {
        $os = 'Linux';
    } elseif (preg_match('/iphone|ipad|ipod/i', $userAgent)) {
        $os = 'iOS';
    } elseif (preg_match('/android/i', $userAgent)) {
        $os = 'Android';
    }

    // 3. Browser Detection
    if (preg_match('/msie/i', $userAgent) && !preg_match('/opera/i', $userAgent)) {
        $browser = 'Internet Explorer';
    } elseif (preg_match('/firefox/i', $userAgent)) {
        $browser = 'Firefox';
    } elseif (preg_match('/chrome/i', $userAgent)) {
        $browser = 'Chrome';
    } elseif (preg_match('/safari/i', $userAgent)) {
        $browser = 'Safari';
    } elseif (preg_match('/opera/i', $userAgent)) {
        $browser = 'Opera';
    } elseif (preg_match('/netscape/i', $userAgent)) {
        $browser = 'Netscape';
    } elseif (preg_match('/edge/i', $userAgent)) {
        $browser = 'Edge';
    }

    return [
        'device' => $device,
        'os' => $os,
        'browser' => $browser
    ];
}

/**
 * Calculates distance between two coordinates in meters using the Haversine formula
 */
function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    if (empty($lat1) || empty($lon1) || empty($lat2) || empty($lon2)) {
        return 0;
    }

    $lat1 = deg2rad(floatval($lat1));
    $lon1 = deg2rad(floatval($lon1));
    $lat2 = deg2rad(floatval($lat2));
    $lon2 = deg2rad(floatval($lon2));

    $earthRadius = 6371000; // in meters

    $dlat = $lat2 - $lat1;
    $dlon = $lon2 - $lon1;

    $a = sin($dlat / 2) * sin($dlat / 2) +
         cos($lat1) * cos($lat2) *
         sin($dlon / 2) * sin($dlon / 2);

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
}

/**
 * Verify a password against standard bcrypt hash, with plain text fallback
 */
function verifyPassword($password, $hash) {
    if (empty($hash)) {
        return false;
    }
    // Check if it's a bcrypt hash (starts with $2y$)
    if (strpos($hash, '$2y$') === 0) {
        return password_verify($password, $hash);
    }
    // Otherwise fallback to direct plain text comparison
    return ($password === $hash);
}

/**
 * Securely hash a password using bcrypt
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

/** Evening shift: 7 required hourly slots (7:00–7:15 PM through 1:00–1:15 AM) */
define('HOURLY_UPDATES_REQUIRED', 7);
define('HOURLY_SLOT_WINDOW_MINUTES', 15);

/** New submissions from this datetime onward must be inside :00–:15 windows (PKT). */
define('HOURLY_SLOT_STRICT_START', '2026-06-05 00:00:00');

/**
 * Build the 7 fifteen-minute submission windows for a shift day.
 */
function getHourlySlotDefinitionsForShift($shiftStartDatetime) {
    $tz = new DateTimeZone('Asia/Karachi');
    $shiftStart = new DateTime($shiftStartDatetime, $tz);
    $shiftDate = $shiftStart->format('Y-m-d');
    $nextDate = (clone $shiftStart)->modify('+1 day')->format('Y-m-d');

    $assignments = [
        ['hour' => 19, 'date' => $shiftDate],
        ['hour' => 20, 'date' => $shiftDate],
        ['hour' => 21, 'date' => $shiftDate],
        ['hour' => 22, 'date' => $shiftDate],
        ['hour' => 23, 'date' => $shiftDate],
        ['hour' => 0, 'date' => $nextDate],
        ['hour' => 1, 'date' => $nextDate],
    ];

    $slots = [];
    foreach ($assignments as $row) {
        $hour = (int) $row['hour'];
        $date = $row['date'];
        $start = new DateTime($date . ' ' . sprintf('%02d:00:00', $hour), $tz);
        $end = new DateTime($date . ' ' . sprintf('%02d:15:00', $hour), $tz);
        $slots[] = [
            'slot_date' => $date,
            'slot_hour' => $hour,
            'label' => $start->format('g:i A') . ' – ' . $end->format('g:i A'),
            'start_ts' => $start->getTimestamp(),
            'end_ts' => $end->getTimestamp(),
        ];
    }

    return $slots;
}

/**
 * Find which slot (if any) a timestamp falls into (strict 15-minute window).
 */
function findHourlySlotForTimestamp($slots, $timestamp) {
    foreach ($slots as $slot) {
        if ($timestamp >= $slot['start_ts'] && $timestamp <= $slot['end_ts']) {
            return $slot;
        }
    }
    return null;
}

/**
 * Lenient slot for legacy rows: map submission to the hour's slot (relaxation).
 */
function findLenientSlotForTimestamp($shiftStartDatetime, $timestamp) {
    $slots = getHourlySlotDefinitionsForShift($shiftStartDatetime);
    $tz = new DateTimeZone('Asia/Karachi');
    $dt = new DateTime('@' . (int) $timestamp);
    $dt->setTimezone($tz);
    $createdHour = (int) $dt->format('G');

    foreach ($slots as $slot) {
        if ((int) $slot['slot_hour'] === $createdHour) {
            return $slot;
        }
    }

    // After 1:15 AM but still same shift night → credit last slot (1:00 AM).
    if ($createdHour >= 2 && $createdHour <= 3) {
        foreach ($slots as $slot) {
            if ((int) $slot['slot_hour'] === 1) {
                return $slot;
            }
        }
    }

    // Clocked in before 7 PM window → credit first slot (7:00 PM).
    $shiftStartTs = strtotime($shiftStartDatetime);
    if ($timestamp >= $shiftStartTs && $createdHour < 19) {
        foreach ($slots as $slot) {
            if ((int) $slot['slot_hour'] === 19) {
                return $slot;
            }
        }
    }

    return null;
}

/**
 * Whether a stored hourly row counts as filling a slot (strict or grandfathered).
 */
function isHourlyUpdateRowValidForSlot($row) {
    return !empty($row['shift_id'])
        && !empty($row['slot_date'])
        && $row['slot_hour'] !== null
        && $row['slot_hour'] !== '';
}

/**
 * Format slot window label from stored date + hour.
 */
function formatHourlySlotLabel($slotDate, $slotHour) {
    $tz = new DateTimeZone('Asia/Karachi');
    $hour = (int) $slotHour;
    $start = new DateTime($slotDate . ' ' . sprintf('%02d:00:00', $hour), $tz);
    $end = new DateTime($slotDate . ' ' . sprintf('%02d:15:00', $hour), $tz);
    return $start->format('g:i A') . ' – ' . $end->format('g:i A');
}

/**
 * Slots that apply to a shift (exclude windows that ended before clock-in).
 */
function getApplicableHourlySlotsForShift($shiftStartDatetime, $auditTimestamp = null) {
    $auditTimestamp = $auditTimestamp ?? time();
    $shiftStartTs = strtotime($shiftStartDatetime);
    $slots = getHourlySlotDefinitionsForShift($shiftStartDatetime);
    $applicable = [];

    foreach ($slots as $slot) {
        if ($slot['end_ts'] <= $shiftStartTs) {
            continue;
        }
        $applicable[] = $slot;
    }

    return $applicable;
}

/**
 * Whether a slot window had ended before the employee clocked in (not required).
 */
function isHourlySlotRequiredForShift($slot, $shiftStartDatetime) {
    return $slot['end_ts'] > strtotime($shiftStartDatetime);
}

/**
 * Check whether this employee has a valid update for a slot (strict or grandfathered).
 */
function hasHourlyUpdateInSlot($conn, $employeeId, $shiftId, $slotDate, $slotHour) {
    $employeeId = (int) $employeeId;
    $slotHour = (int) $slotHour;
    $slotDate = mysqli_real_escape_string($conn, $slotDate);

    $result = $conn->query("
        SELECT id FROM hourly_updates
        WHERE employee_id='$employeeId'
        AND slot_date='$slotDate'
        AND slot_hour='$slotHour'
        AND shift_id IS NOT NULL
        LIMIT 1
    ");

    return ($result && $result->num_rows > 0);
}

/**
 * Whether new submissions must use strict 15-minute windows.
 */
function isHourlySubmissionStrictPeriod($timestamp = null) {
    $timestamp = $timestamp ?? time();
    return $timestamp >= strtotime(HOURLY_SLOT_STRICT_START);
}

/**
 * Count how many required slots are filled for a shift.
 */
function countFilledHourlySlotsForShift($conn, $employeeId, $shiftId, $slots, $shiftStartDatetime = null, $auditTimestamp = null) {
    $filled = 0;
    foreach ($slots as $slot) {
        if ($shiftStartDatetime !== null && !isHourlySlotRequiredForShift($slot, $shiftStartDatetime)) {
            continue;
        }
        if ($auditTimestamp !== null && $auditTimestamp < $slot['end_ts']) {
            continue;
        }
        if (hasHourlyUpdateInSlot($conn, $employeeId, $shiftId, $slot['slot_date'], $slot['slot_hour'])) {
            $filled++;
        }
    }
    return $filled;
}

/**
 * Count missed hourly slots (only applicable slots whose window has already ended).
 */
function countMissedHourlySlotsForShift($conn, $employeeId, $shiftId, $shiftStartDatetime, $auditTimestamp = null) {
    $auditTimestamp = $auditTimestamp ?? time();
    $slots = getHourlySlotDefinitionsForShift($shiftStartDatetime);
    $missed = 0;

    foreach ($slots as $slot) {
        if (!isHourlySlotRequiredForShift($slot, $shiftStartDatetime)) {
            continue;
        }
        if ($auditTimestamp < $slot['end_ts']) {
            continue;
        }
        if (!hasHourlyUpdateInSlot($conn, $employeeId, $shiftId, $slot['slot_date'], $slot['slot_hour'])) {
            $missed++;
        }
    }

    return $missed;
}

/**
 * Get current DB time as Unix timestamp (Asia/Karachi aligned via MySQL session).
 */
function getDatabaseNowTimestamp($conn) {
    $result = $conn->query("SELECT UNIX_TIMESTAMP(NOW()) AS ts");
    if ($result && $row = $result->fetch_assoc()) {
        return (int) $row['ts'];
    }
    return time();
}

/* =========================================================
   WORKING HOURS — 8h dedicated work + 1h unpaid break
   ========================================================= */

/** Net working hours every shift must deliver (break excluded). */
define('SHIFT_REQUIRED_WORK_SECONDS', 8 * 3600);

/** Break allowance deducted from every shift, whether taken or not. */
define('SHIFT_BREAK_ALLOWANCE_SECONDS', 3600);

/** Clock-in to clock-out time needed to deliver the required working hours. */
define('SHIFT_REQUIRED_SPAN_SECONDS', SHIFT_REQUIRED_WORK_SECONDS + SHIFT_BREAK_ALLOWANCE_SECONDS);

/** Fine per closed weekday shift that delivered less than the required hours. */
define('SHORT_HOURS_PENALTY_AMOUNT', 1000);

/** An open shift running past this is abandoned, not overtime — hours unverifiable. */
define('SHIFT_STALE_OPEN_SECONDS', SHIFT_REQUIRED_SPAN_SECONDS + (3 * 3600));

/** Approved request types that excuse a shift from the short-hours fine. */
function getShortHoursWaiverRequestTypes() {
    return ['early_leave', 'extended_break'];
}

/**
 * Human duration label, e.g. 8h 05m.
 */
function formatWorkDuration($seconds) {
    $seconds = max(0, (int) $seconds);
    return intdiv($seconds, 3600) . 'h ' . str_pad(intdiv($seconds % 3600, 60), 2, '0', STR_PAD_LEFT) . 'm';
}

/**
 * Shortfall label rounded up to the next minute, so a few seconds short never
 * reads as "0h 00m short" next to a fine.
 */
function formatShortfallDuration($seconds) {
    $seconds = max(0, (int) $seconds);
    return formatWorkDuration((int) (ceil($seconds / 60) * 60));
}

/**
 * Working hours for one shift: clock-in to clock-out minus the 1h break
 * allowance. Open shifts are measured against the audit timestamp.
 */
function getShiftWorkSummary($shiftRow, $auditTimestamp = null) {
    $auditTimestamp = $auditTimestamp ?? time();

    $startTs = strtotime((string) ($shiftRow['start_time'] ?? ''));
    $endValue = trim((string) ($shiftRow['end_time'] ?? ''));
    $endTs = ($endValue !== '' && strpos($endValue, '0000-00-00') !== 0) ? strtotime($endValue) : false;
    $status = strtolower(trim((string) ($shiftRow['status'] ?? '')));
    $isClosed = ($status === 'closed' && $endTs !== false);

    $summary = [
        'is_closed' => $isClosed,
        'is_stale' => false,
        'start_ts' => $startTs ?: null,
        'end_ts' => $isClosed ? $endTs : null,
        'span_seconds' => 0,
        'break_seconds' => 0,
        'worked_seconds' => 0,
        'short_seconds' => SHIFT_REQUIRED_WORK_SECONDS,
        'required_seconds' => SHIFT_REQUIRED_WORK_SECONDS,
        'required_span_seconds' => SHIFT_REQUIRED_SPAN_SECONDS,
        'break_allowance_seconds' => SHIFT_BREAK_ALLOWANCE_SECONDS,
        'is_complete' => false,
        'is_short' => false,
        'span_label' => formatWorkDuration(0),
        'break_label' => formatWorkDuration(0),
        'worked_label' => formatWorkDuration(0),
        'short_label' => formatWorkDuration(SHIFT_REQUIRED_WORK_SECONDS),
        'required_label' => formatWorkDuration(SHIFT_REQUIRED_WORK_SECONDS),
        'required_span_label' => formatWorkDuration(SHIFT_REQUIRED_SPAN_SECONDS),
    ];

    if (!$startTs) {
        return $summary;
    }

    $referenceTs = $isClosed ? $endTs : $auditTimestamp;
    $isStale = (!$isClosed && ($referenceTs - $startTs) >= SHIFT_STALE_OPEN_SECONDS);
    if ($isStale) {
        $referenceTs = $startTs + SHIFT_REQUIRED_SPAN_SECONDS;
    }

    $span = max(0, $referenceTs - $startTs);
    $break = min($span, SHIFT_BREAK_ALLOWANCE_SECONDS);
    $worked = max(0, $span - $break);
    $short = max(0, SHIFT_REQUIRED_WORK_SECONDS - $worked);

    $summary['is_stale'] = $isStale;
    $summary['span_seconds'] = $span;
    $summary['break_seconds'] = $break;
    $summary['worked_seconds'] = $worked;
    $summary['short_seconds'] = $short;
    $summary['is_complete'] = ($short === 0 && !$isStale);
    $summary['is_short'] = ($isClosed && $short > 0);
    $summary['span_label'] = formatWorkDuration($span);
    $summary['break_label'] = formatWorkDuration($break);
    $summary['worked_label'] = formatWorkDuration($worked);
    $summary['short_label'] = formatShortfallDuration($short);

    return $summary;
}

/**
 * Approved Early Sign-off / Extended Break request for that workday.
 */
function hasApprovedShortHoursWaiver($conn, $employeeId, $shiftDate) {
    $employeeId = (int) $employeeId;
    if (strtotime((string) $shiftDate) === false) {
        return false;
    }

    $dateEsc = mysqli_real_escape_string($conn, date('Y-m-d', strtotime($shiftDate)));
    $types = "'" . implode("','", getShortHoursWaiverRequestTypes()) . "'";

    $result = $conn->query("
        SELECT id FROM employee_requests
        WHERE employee_id='$employeeId'
        AND request_date='$dateEsc'
        AND status='approved'
        AND request_type IN ($types)
        LIMIT 1
    ");

    return ($result && $result->num_rows > 0);
}

/**
 * Whether an admin has waived the short-hours fine for one shift.
 */
function isShiftShortHoursRelaxed($conn, $employeeId, $shiftId) {
    $employeeId = (int) $employeeId;
    $shiftId = (int) $shiftId;
    if ($employeeId <= 0 || $shiftId <= 0) {
        return false;
    }

    $result = $conn->query("
        SELECT id FROM short_hours_relaxations
        WHERE employee_id='$employeeId' AND shift_id='$shiftId'
        LIMIT 1
    ");

    return ($result && $result->num_rows > 0);
}

/**
 * Admin: waive the short-hours fine for one shift and rebuild that month.
 */
function grantAdminRelaxationForShiftShortHours($conn, $employeeId, $shiftId, $grantedBy = 'Admin', $note = '') {
    $employeeId = (int) $employeeId;
    $shiftId = (int) $shiftId;
    $grantedBy = trim((string) $grantedBy) ?: 'Admin';

    $shiftResult = $conn->query("
        SELECT * FROM shifts
        WHERE id='$shiftId' AND employee_id='$employeeId'
        LIMIT 1
    ");
    if (!$shiftResult || $shiftResult->num_rows === 0) {
        return ['success' => false, 'granted' => false, 'message' => 'Shift not found for this employee.'];
    }

    $shift = $shiftResult->fetch_assoc();
    $shiftDate = date('Y-m-d', strtotime($shift['start_time']));

    if (isShiftShortHoursRelaxed($conn, $employeeId, $shiftId)) {
        return [
            'success' => true,
            'granted' => false,
            'message' => 'Short-hours fine for ' . date('d M Y', strtotime($shiftDate)) . ' is already waived.',
        ];
    }

    $summary = getShiftWorkSummary($shift, getDatabaseNowTimestamp($conn));
    if (!$summary['is_short']) {
        return [
            'success' => false,
            'granted' => false,
            'message' => 'That shift delivered the required hours — nothing to waive.',
        ];
    }

    $shiftDateEsc = mysqli_real_escape_string($conn, $shiftDate);
    $grantedByEsc = mysqli_real_escape_string($conn, $grantedBy);
    $noteEsc = mysqli_real_escape_string($conn, trim((string) $note));

    $ok = $conn->query("
        INSERT INTO short_hours_relaxations (employee_id, shift_id, shift_date, note, granted_by)
        VALUES ('$employeeId', '$shiftId', '$shiftDateEsc', '$noteEsc', '$grantedByEsc')
    ");

    if (!$ok) {
        return ['success' => false, 'granted' => false, 'message' => 'Could not waive the fine: ' . $conn->error];
    }

    recalculateAutomatedPenaltiesForEmployeeMonth($conn, $employeeId, date('Y-m', strtotime($shiftDate)));

    return [
        'success' => true,
        'granted' => true,
        'message' => 'Short-hours fine waived for ' . date('d M Y', strtotime($shiftDate))
            . ' (' . $summary['short_label'] . ' short). Penalties recalculated.',
    ];
}

/**
 * Admin: undo a short-hours waiver and rebuild that month.
 */
function revokeAdminRelaxationForShiftShortHours($conn, $employeeId, $shiftId) {
    $employeeId = (int) $employeeId;
    $shiftId = (int) $shiftId;

    if (!isShiftShortHoursRelaxed($conn, $employeeId, $shiftId)) {
        return ['success' => false, 'revoked' => false, 'message' => 'No short-hours waiver found for that shift.'];
    }

    $conn->query("
        DELETE FROM short_hours_relaxations
        WHERE employee_id='$employeeId' AND shift_id='$shiftId'
    ");

    $shiftResult = $conn->query("SELECT start_time FROM shifts WHERE id='$shiftId' LIMIT 1");
    $shiftDate = ($shiftResult && $shiftResult->num_rows > 0)
        ? date('Y-m-d', strtotime($shiftResult->fetch_assoc()['start_time']))
        : date('Y-m-d');

    recalculateAutomatedPenaltiesForEmployeeMonth($conn, $employeeId, date('Y-m', strtotime($shiftDate)));

    return [
        'success' => true,
        'revoked' => true,
        'message' => 'Short-hours waiver removed for ' . date('d M Y', strtotime($shiftDate)) . '. Penalties recalculated.',
    ];
}

/**
 * Whether a shift carries the short-hours fine: closed, weekday, under the
 * required working hours, no approved request covering that day, and not
 * waived by an admin.
 */
function isShiftShortHoursFineable($conn, $employeeId, $shiftRow, $auditTimestamp = null, $workSummary = null) {
    $summary = $workSummary ?? getShiftWorkSummary($shiftRow, $auditTimestamp);
    if (!$summary['is_short']) {
        return false;
    }

    $startTs = strtotime((string) ($shiftRow['start_time'] ?? ''));
    if (!$startTs) {
        return false;
    }
    if ((int) date('N', $startTs) >= 6) {
        return false;
    }

    if (isShiftShortHoursRelaxed($conn, $employeeId, $shiftRow['id'] ?? 0)) {
        return false;
    }

    return !hasApprovedShortHoursWaiver($conn, $employeeId, date('Y-m-d', $startTs));
}

/**
 * Short-hours totals across a list of shifts.
 */
function summariseShortHoursForShifts($conn, $employeeId, $shiftsList, $auditTimestamp = null) {
    $auditTimestamp = $auditTimestamp ?? time();
    $count = 0;
    $shortSeconds = 0;
    $waived = 0;
    $pending = 0;
    $dates = [];

    foreach ($shiftsList as $shift) {
        $summary = getShiftWorkSummary($shift, $auditTimestamp);

        if (!$summary['is_closed']) {
            if (!$summary['is_complete'] && !$summary['is_stale']) {
                $pending++;
            }
            continue;
        }

        if (!$summary['is_short']) {
            continue;
        }

        if (!isShiftShortHoursFineable($conn, $employeeId, $shift, $auditTimestamp, $summary)) {
            $waived++;
            continue;
        }

        $count++;
        $shortSeconds += $summary['short_seconds'];
        $dates[] = date('Y-m-d', strtotime($shift['start_time']));
    }

    return [
        'count' => $count,
        'short_seconds' => $shortSeconds,
        'waived' => $waived,
        'pending' => $pending,
        'dates' => $dates,
        'fine' => calculateShortHoursFineAmount($count),
    ];
}

/**
 * PKR fine for shifts that finished under the required working hours.
 */
function calculateShortHoursFineAmount($shortShiftCount) {
    return max(0, (int) $shortShiftCount) * SHORT_HOURS_PENALTY_AMOUNT;
}

/**
 * Canonical penalty reason for the monthly short-hours fine.
 */
function buildShortHoursPenaltyReason($shortShiftCount, $shortSeconds = 0) {
    $shortShiftCount = max(0, (int) $shortShiftCount);
    $label = $shortShiftCount === 1 ? 'shift' : 'shifts';

    return "Monthly Short Hours ($shortShiftCount $label under 8h, "
        . formatWorkDuration($shortSeconds) . ' short)';
}

/**
 * Whether a shift is ready for missed-update fine calculation.
 * Includes closed shifts, 9h+ elapsed, or all hourly slot windows have ended.
 */
function isShiftAuditableForMissedUpdateFines($shiftRow, $auditTimestamp = null) {
    $auditTimestamp = $auditTimestamp ?? time();
    $status = strtolower(trim($shiftRow['status'] ?? ''));
    if ($status === 'closed') {
        return true;
    }

    $startTs = strtotime($shiftRow['start_time']);
    if ($startTs !== false && $auditTimestamp >= ($startTs + (9 * 3600))) {
        return true;
    }

    $slots = getHourlySlotDefinitionsForShift($shiftRow['start_time']);
    if (count($slots) > 0) {
        $lastSlot = $slots[count($slots) - 1];
        if ($auditTimestamp >= $lastSlot['end_ts']) {
            return true;
        }
    }

    return false;
}

/**
 * Fetch shifts in a month that are auditable for missed-update fines.
 */
function getAuditableShiftsForPenaltyMonth($conn, $employeeId, $month, $auditTimestamp = null) {
    $employeeId = (int) $employeeId;
    $month = mysqli_real_escape_string($conn, $month);
    $auditTimestamp = $auditTimestamp ?? getDatabaseNowTimestamp($conn);
    $shifts = [];

    $result = $conn->query("
        SELECT id, start_time, status, end_time
        FROM shifts
        WHERE employee_id='$employeeId'
        AND DATE_FORMAT(start_time, '%Y-%m')='$month'
        ORDER BY start_time ASC
    ");

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            if (isShiftAuditableForMissedUpdateFines($row, $auditTimestamp)) {
                $shifts[] = $row;
            }
        }
    }

    return $shifts;
}

/**
 * Count missed updates only on shifts eligible for fines (matches penalty engine).
 */
function countBillableMissedUpdatesForShifts($conn, $employeeId, $shiftsList, $auditTimestamp = null) {
    $auditTimestamp = $auditTimestamp ?? getDatabaseNowTimestamp($conn);
    $employeeId = (int) $employeeId;
    $billable = 0;
    $pending = 0;

    foreach ($shiftsList as $shift) {
        $breakdown = getMissedUpdatesBreakdownForShift($conn, $employeeId, $shift, $auditTimestamp);
        if (isShiftAuditableForMissedUpdateFines($shift, $auditTimestamp)) {
            $billable += $breakdown['total'];
        } else {
            $pending += $breakdown['total'];
        }
    }

    return [
        'billable' => $billable,
        'pending' => $pending,
        'total' => $billable + $pending,
    ];
}

/**
 * Whether end-of-day report can be marked missed (shift closed or 9h elapsed).
 */
function isShiftEndReportAuditable($shiftRow, $auditTimestamp) {
    return isShiftAuditableForMissedUpdateFines($shiftRow, $auditTimestamp);
}

/**
 * Missed hourly slots + end report for one shift (matches penalty engine rules).
 */
function getMissedUpdatesBreakdownForShift($conn, $employeeId, $shiftRow, $auditTimestamp = null) {
    $auditTimestamp = $auditTimestamp ?? getDatabaseNowTimestamp($conn);
    $employeeId = (int) $employeeId;
    $shiftId = (int) $shiftRow['id'];
    $startTime = $shiftRow['start_time'];

    $slots = getHourlySlotDefinitionsForShift($startTime);
    $missedHourly = [];

    $requiredEnded = 0;
    foreach ($slots as $slot) {
        if (!isHourlySlotRequiredForShift($slot, $startTime)) {
            continue;
        }
        if ($auditTimestamp < $slot['end_ts']) {
            continue;
        }
        $requiredEnded++;
        if (!hasHourlyUpdateInSlot($conn, $employeeId, $shiftId, $slot['slot_date'], $slot['slot_hour'])) {
            $missedHourly[] = $slot;
        }
    }

    $summaryMissed = false;
    if (isShiftEndReportAuditable($shiftRow, $auditTimestamp)) {
        $er = $conn->query("SELECT id FROM end_reports WHERE shift_id='$shiftId' LIMIT 1");
        $summaryMissed = !($er && $er->num_rows > 0);
    }

    $filledHourly = countFilledHourlySlotsForShift($conn, $employeeId, $shiftId, $slots, $startTime, $auditTimestamp);
    $applicableSlots = getApplicableHourlySlotsForShift($startTime, $auditTimestamp);

    return [
        'hourly' => $missedHourly,
        'hourly_count' => count($missedHourly),
        'hourly_filled' => $filledHourly,
        'hourly_required' => $requiredEnded > 0 ? $requiredEnded : count($applicableSlots),
        'summary_missed' => $summaryMissed,
        'summary_count' => $summaryMissed ? 1 : 0,
        'total' => count($missedHourly) + ($summaryMissed ? 1 : 0),
    ];
}

/**
 * Admin: credit missed hourly slots for a shift by inserting grandfathered placeholder logs.
 */
function grantAdminRelaxationForShiftMissedHourly($conn, $employeeId, $shiftId, $grantedBy = 'Admin') {
    $employeeId = (int) $employeeId;
    $shiftId = (int) $shiftId;
    $grantedBy = trim((string) $grantedBy);
    if ($grantedBy === '') {
        $grantedBy = 'Admin';
    }
    $grantedByEsc = mysqli_real_escape_string($conn, $grantedBy);

    $shiftResult = $conn->query("
        SELECT * FROM shifts
        WHERE id='$shiftId' AND employee_id='$employeeId'
        LIMIT 1
    ");
    if (!$shiftResult || $shiftResult->num_rows === 0) {
        return ['success' => false, 'credited' => 0, 'message' => 'Shift not found for this employee.'];
    }

    $shift = $shiftResult->fetch_assoc();
    $dbNowTs = getDatabaseNowTimestamp($conn);
    $breakdown = getMissedUpdatesBreakdownForShift($conn, $employeeId, $shift, $dbNowTs);
    $credited = 0;
    $errors = 0;

    foreach ($breakdown['hourly'] as $slot) {
        if (hasHourlyUpdateInSlot($conn, $employeeId, $shiftId, $slot['slot_date'], $slot['slot_hour'])) {
            continue;
        }

        $slotDate = mysqli_real_escape_string($conn, $slot['slot_date']);
        $slotHour = (int) $slot['slot_hour'];
        $createdAt = date('Y-m-d H:i:s', $slot['start_ts']);
        $updateText = mysqli_real_escape_string(
            $conn,
            "[Admin relaxation] Missed slot credited by {$grantedBy} — {$slot['label']}"
        );

        $ok = $conn->query("
            INSERT INTO hourly_updates (employee_id, shift_id, slot_date, slot_hour, is_grandfathered, update_text, created_at)
            VALUES ('$employeeId', '$shiftId', '$slotDate', '$slotHour', 1, '$updateText', '$createdAt')
        ");

        if ($ok) {
            $credited++;
        } else {
            $errors++;
        }
    }

    if ($credited > 0) {
        $message = "Relaxation granted: {$credited} missed hourly slot(s) credited.";
    } elseif ($errors > 0) {
        $message = 'Could not credit missed slots. They may already be filled.';
    } else {
        $message = 'No missed hourly slots to credit for this shift.';
    }

    return [
        'success' => $credited > 0,
        'credited' => $credited,
        'errors' => $errors,
        'message' => $message,
    ];
}

/**
 * Whether a penalty reason is system-generated (absence / missed updates).
 */
function isAutomatedPenaltyReason($reason) {
    $type = classifyPenaltyType($reason);
    // Only the monthly engine-generated rows are recreated on recalc. Everything
    // else (admin misconduct, request violations) is a stored row that must be
    // summed and displayed as-is.
    return in_array($type['key'], ['absence', 'missed_updates', 'short_hours'], true);
}

/**
 * Sum admin-logged penalties in a date range (excludes automated monthly rows).
 */
function getManualPenaltySumInRange($conn, $employeeId, $dateFrom, $dateTo) {
    $employeeId = (int) $employeeId;
    $dateFrom = mysqli_real_escape_string($conn, $dateFrom);
    $dateTo = mysqli_real_escape_string($conn, $dateTo);
    $total = 0.0;

    $result = $conn->query("
        SELECT amount, reason
        FROM penalties
        WHERE employee_id='$employeeId'
        AND waived = 0
        AND DATE(created_at) BETWEEN '$dateFrom' AND '$dateTo'
    ");

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            if (!isAutomatedPenaltyReason($row['reason'])) {
                $total += floatval($row['amount']);
            }
        }
    }

    return $total;
}

/**
 * Real-time penalty totals from attendance rules (not stale DB rows).
 */
function calculateEmployeeDynamicPenalties($conn, $employeeId, $dateFrom, $dateTo, $auditTimestamp = null) {
    $employeeId = (int) $employeeId;
    $auditTimestamp = $auditTimestamp ?? getDatabaseNowTimestamp($conn);

    $absenceCount = 0;
    $absenceFine = 0.0;
    $missedUpdatesTotal = 0;
    $missedUpdatesFinedCount = 0;
    $missedUpdatesFine = 0.0;
    $shortHoursCount = 0;
    $shortHoursSeconds = 0;
    $shortHoursFine = 0.0;
    $byMonth = [];

    $month = date('Y-m', strtotime($dateFrom));
    $endMonth = date('Y-m', strtotime($dateTo));

    while ($month <= $endMonth) {
        $monthStart = max($dateFrom, $month . '-01');
        $monthEnd = min($dateTo, date('Y-m-t', strtotime($month . '-01')));
        $auditEndDate = $monthEnd;

        if ($month === date('Y-m')) {
            $yesterday = date('Y-m-d', strtotime('yesterday'));
            if (strtotime($yesterday) >= strtotime($month . '-01')) {
                $auditEndDate = min($monthEnd, $yesterday);
            }
        }

        $monthAbsences = 0;
        if (strtotime($auditEndDate) >= strtotime($month . '-01')) {
            foreach (getEmployeeAbsenceDatesForMonth($conn, $employeeId, $month, $auditEndDate) as $row) {
                if (!empty($row['relaxed'])) {
                    continue;
                }
                if ($row['date'] >= $monthStart && $row['date'] <= $monthEnd) {
                    $monthAbsences++;
                }
            }
        }

        $monthMissed = 0;
        $monthShifts = [];
        foreach (getAuditableShiftsForPenaltyMonth($conn, $employeeId, $month, $auditTimestamp) as $shift) {
            $shiftDate = date('Y-m-d', strtotime($shift['start_time']));
            if ($shiftDate < $dateFrom || $shiftDate > $dateTo) {
                continue;
            }
            $breakdown = getMissedUpdatesBreakdownForShift($conn, $employeeId, $shift, $auditTimestamp);
            $monthMissed += $breakdown['total'];
            $monthShifts[] = $shift;
        }

        $monthShortHours = summariseShortHoursForShifts($conn, $employeeId, $monthShifts, $auditTimestamp);

        $monthAbsenceFine = $monthAbsences * 5000;
        $monthMissedFine = calculateMissedUpdatesFineAmount($monthMissed);
        $monthFinedMissedCount = max(0, $monthMissed - 3);

        $absenceCount += $monthAbsences;
        $absenceFine += $monthAbsenceFine;
        $missedUpdatesTotal += $monthMissed;
        $missedUpdatesFinedCount += $monthFinedMissedCount;
        $missedUpdatesFine += $monthMissedFine;
        $shortHoursCount += $monthShortHours['count'];
        $shortHoursSeconds += $monthShortHours['short_seconds'];
        $shortHoursFine += $monthShortHours['fine'];

        $byMonth[$month] = [
            'absences' => $monthAbsences,
            'absence_fine' => $monthAbsenceFine,
            'missed_updates' => $monthMissed,
            'missed_updates_fined_count' => $monthFinedMissedCount,
            'missed_updates_fine' => $monthMissedFine,
            'short_hours' => $monthShortHours['count'],
            'short_hours_seconds' => $monthShortHours['short_seconds'],
            'short_hours_waived' => $monthShortHours['waived'],
            'short_hours_fine' => $monthShortHours['fine'],
            'manual_fine' => 0.0,
        ];

        $month = date('Y-m', strtotime($month . '-01 +1 month'));
    }

    $manualFine = 0.0;
    $manualResult = $conn->query("
        SELECT amount, reason, created_at
        FROM penalties
        WHERE employee_id='$employeeId'
        AND waived = 0
        AND DATE(created_at) BETWEEN '" . mysqli_real_escape_string($conn, $dateFrom) . "'
        AND '" . mysqli_real_escape_string($conn, $dateTo) . "'
    ");
    if ($manualResult) {
        while ($row = $manualResult->fetch_assoc()) {
            if (isAutomatedPenaltyReason($row['reason'])) {
                continue;
            }
            $amount = floatval($row['amount']);
            $manualFine += $amount;
            $rowMonth = date('Y-m', strtotime($row['created_at']));
            if (!isset($byMonth[$rowMonth])) {
                $byMonth[$rowMonth] = [
                    'absences' => 0,
                    'absence_fine' => 0.0,
                    'missed_updates' => 0,
                    'missed_updates_fined_count' => 0,
                    'missed_updates_fine' => 0.0,
                    'short_hours' => 0,
                    'short_hours_seconds' => 0,
                    'short_hours_waived' => 0,
                    'short_hours_fine' => 0.0,
                    'manual_fine' => 0.0,
                ];
            }
            $byMonth[$rowMonth]['manual_fine'] += $amount;
        }
    }

    $automatedTotal = $absenceFine + $missedUpdatesFine + $shortHoursFine;

    return [
        'total' => $automatedTotal + $manualFine,
        'automated_total' => $automatedTotal,
        'absence_count' => $absenceCount,
        'absence_fine' => $absenceFine,
        'short_hours_count' => $shortHoursCount,
        'short_hours_seconds' => $shortHoursSeconds,
        'short_hours_fine' => $shortHoursFine,
        'missed_updates_total' => $missedUpdatesTotal,
        'missed_updates_fined_count' => $missedUpdatesFinedCount,
        'missed_updates_fine' => $missedUpdatesFine,
        'manual_fine' => $manualFine,
        'by_month' => $byMonth,
    ];
}

/**
 * Date range for payroll / penalty views for a given YYYY-MM month.
 */
function getPayrollMonthDateRange($month) {
    $from = $month . '-01';
    $to = date('Y-m-t', strtotime($from));
    if ($month === date('Y-m')) {
        $to = date('Y-m-d');
    }
    return [$from, $to];
}

/**
 * Live penalty totals for all employees in a date range.
 */
function calculateWorkforceDynamicPenalties($conn, $dateFrom, $dateTo, $auditTimestamp = null) {
    $auditTimestamp = $auditTimestamp ?? getDatabaseNowTimestamp($conn);
    $total = 0.0;
    $byEmployee = [];

    $result = $conn->query("SELECT id FROM users WHERE role='employee'");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $employeeId = (int) $row['id'];
            $data = calculateEmployeeDynamicPenalties($conn, $employeeId, $dateFrom, $dateTo, $auditTimestamp);
            $byEmployee[$employeeId] = $data;
            $total += $data['total'];
        }
    }

    return [
        'total' => $total,
        'by_employee' => $byEmployee,
    ];
}

/**
 * Build penalty rows for reports UI from live rules + manual DB entries.
 */
function buildEmployeePenaltyReportRows($conn, $employeeId, $dateFrom, $dateTo, $auditTimestamp = null) {
    $employeeId = (int) $employeeId;
    $auditTimestamp = $auditTimestamp ?? getDatabaseNowTimestamp($conn);
    $dynamic = calculateEmployeeDynamicPenalties($conn, $employeeId, $dateFrom, $dateTo, $auditTimestamp);
    $rows = [];

    krsort($dynamic['by_month']);
    foreach ($dynamic['by_month'] as $month => $data) {
        if ($data['absence_fine'] > 0) {
            $reason = "Monthly Shift Absences ({$data['absences']} missed)";
            $rows[] = [
                'id' => 0,
                'reason' => $reason,
                'amount' => $data['absence_fine'],
                'created_at' => penaltyCreatedAtForMonth($month),
                'type' => classifyPenaltyType($reason),
                'penalty_month' => $month,
                'dynamic' => true,
            ];
        }
        if ($data['missed_updates_fine'] > 0) {
            $reason = "Monthly Missed Updates ({$data['missed_updates']} missed, 3 allowed)";
            $rows[] = [
                'id' => 0,
                'reason' => $reason,
                'amount' => $data['missed_updates_fine'],
                'created_at' => penaltyCreatedAtForMonth($month),
                'type' => classifyPenaltyType($reason),
                'penalty_month' => $month,
                'dynamic' => true,
            ];
        }
        if (!empty($data['short_hours_fine'])) {
            $reason = buildShortHoursPenaltyReason($data['short_hours'], $data['short_hours_seconds']);
            $rows[] = [
                'id' => 0,
                'reason' => $reason,
                'amount' => $data['short_hours_fine'],
                'created_at' => penaltyCreatedAtForMonth($month),
                'type' => classifyPenaltyType($reason),
                'penalty_month' => $month,
                'dynamic' => true,
            ];
        }
    }

    $manualResult = $conn->query("
        SELECT id, reason, amount, created_at, waived, waived_at, waived_by, waive_note
        FROM penalties
        WHERE employee_id='$employeeId'
        AND DATE(created_at) BETWEEN '" . mysqli_real_escape_string($conn, $dateFrom) . "'
        AND '" . mysqli_real_escape_string($conn, $dateTo) . "'
        ORDER BY created_at DESC
    ");
    if ($manualResult) {
        while ($row = $manualResult->fetch_assoc()) {
            if (isAutomatedPenaltyReason($row['reason'])) {
                continue;
            }
            $row['type'] = classifyPenaltyType($row['reason']);
            $row['penalty_month'] = date('Y-m', strtotime($row['created_at']));
            $row['dynamic'] = false;
            $row['waived'] = !empty($row['waived']);
            $rows[] = $row;
        }
    }

    usort($rows, function ($a, $b) {
        return strtotime($b['created_at']) <=> strtotime($a['created_at']);
    });

    $breakdown = [
        'absence' => [
            'label' => 'Shift Absences',
            'description' => 'PKR 5,000 per weekday with no shift start',
            'count' => 0,
            'total' => 0.0,
        ],
        'missed_updates' => [
            'label' => 'Missed Updates Fines',
            'description' => '3 free per month, then PKR 1,000 each',
            'count' => 0,
            'total' => 0.0,
        ],
        'short_hours' => [
            'label' => 'Short Working Hours',
            'description' => 'PKR ' . number_format(SHORT_HOURS_PENALTY_AMOUNT) . ' per shift under 8 worked hours (1h break excluded)',
            'count' => 0,
            'total' => 0.0,
        ],
        'request_violation' => [
            'label' => 'Unapproved Requests',
            'description' => 'PKR ' . number_format(REQUEST_VIOLATION_PENALTY_AMOUNT) . ' per unapproved shift change',
            'count' => 0,
            'total' => 0.0,
        ],
        'manual' => [
            'label' => 'Manual / Misconduct',
            'description' => 'Logged by admin',
            'count' => 0,
            'total' => 0.0,
        ],
    ];
    $total = 0.0;

    $waivedTotal = 0.0;
    $waivedCount = 0;

    foreach ($rows as $row) {
        $key = $row['type']['key'];
        $amount = floatval($row['amount']);
        if (!empty($row['waived'])) {
            $waivedTotal += $amount;
            $waivedCount++;
            continue;
        }
        $breakdown[$key]['count']++;
        $breakdown[$key]['total'] += $amount;
        $total += $amount;
    }

    return [
        'rows' => $rows,
        'breakdown' => $breakdown,
        'total' => $total,
        'waived_total' => $waivedTotal,
        'waived_count' => $waivedCount,
        'dynamic' => $dynamic,
    ];
}

/**
 * Classify a penalty row for reporting (absence, missed updates, manual).
 */
function classifyPenaltyType($reason) {
    $reason = strtolower(trim((string) $reason));

    if (strpos($reason, 'monthly shift absences') !== false) {
        return [
            'key' => 'absence',
            'label' => 'Shift Absence',
            'badge' => 'danger',
            'description' => 'PKR 5,000 per weekday with no shift start',
        ];
    }

    if (strpos($reason, 'monthly missed updates') !== false || strpos($reason, 'monthly missed hourly updates') !== false) {
        return [
            'key' => 'missed_updates',
            'label' => 'Missed Updates Fine',
            'badge' => 'warning',
            'description' => '3 free per month, then PKR 1,000 each',
        ];
    }

    if (strpos($reason, 'monthly short hours') !== false) {
        return [
            'key' => 'short_hours',
            'label' => 'Short Working Hours',
            'badge' => 'warning',
            'description' => 'PKR ' . number_format(SHORT_HOURS_PENALTY_AMOUNT) . ' per shift under 8 worked hours (1h break excluded)',
        ];
    }

    if (strpos($reason, 'unapproved request') !== false) {
        return [
            'key' => 'request_violation',
            'label' => 'Unapproved Request',
            'badge' => 'danger',
            'description' => 'PKR ' . number_format(REQUEST_VIOLATION_PENALTY_AMOUNT) . ' — no approved request for a shift change',
        ];
    }

    return [
        'key' => 'manual',
        'label' => 'Manual / Misconduct',
        'badge' => 'danger',
        'description' => 'Logged by admin',
    ];
}

/**
 * Build daily + monthly missed-update aggregates for an employee's shifts.
 */
function buildEmployeeMissedUpdatesReport($conn, $employeeId, $shiftsList, $auditTimestamp = null) {
    $auditTimestamp = $auditTimestamp ?? getDatabaseNowTimestamp($conn);
    $employeeId = (int) $employeeId;

    $daily = [];
    $monthly = [];
    $totalMissed = 0;
    $totalWorkedSeconds = 0;
    $totalShortShifts = 0;

    foreach ($shiftsList as $shift) {
        $breakdown = getMissedUpdatesBreakdownForShift($conn, $employeeId, $shift, $auditTimestamp);
        $shiftDate = date('Y-m-d', strtotime($shift['start_time']));
        $monthKey = date('Y-m', strtotime($shift['start_time']));

        $missedLabels = array_map(function ($slot) {
            return $slot['label'];
        }, $breakdown['hourly']);

        $work = getShiftWorkSummary($shift, $auditTimestamp);
        $workFineable = isShiftShortHoursFineable($conn, $employeeId, $shift, $auditTimestamp, $work);

        $daily[] = [
            'shift_id' => (int) $shift['id'],
            'date' => $shiftDate,
            'start_time' => $shift['start_time'],
            'end_time' => $shift['end_time'] ?? null,
            'status' => strtolower(trim($shift['status'] ?? '')),
            'hourly_missed' => $breakdown['hourly_count'],
            'hourly_filled' => $breakdown['hourly_filled'],
            'hourly_required' => $breakdown['hourly_required'],
            'missed_slots' => $missedLabels,
            'summary_missed' => $breakdown['summary_missed'],
            'total_missed' => $breakdown['total'],
            'work' => $work,
            'short_hours_fineable' => $workFineable,
            'short_hours_relaxed' => isShiftShortHoursRelaxed($conn, $employeeId, $shift['id']),
        ];

        if (!isset($monthly[$monthKey])) {
            $monthly[$monthKey] = [
                'month' => $monthKey,
                'shifts' => 0,
                'hourly_missed' => 0,
                'summary_missed' => 0,
                'total_missed' => 0,
                'worked_seconds' => 0,
                'short_hours_shifts' => 0,
                'short_hours_seconds' => 0,
            ];
        }

        $monthly[$monthKey]['shifts']++;
        $monthly[$monthKey]['hourly_missed'] += $breakdown['hourly_count'];
        $monthly[$monthKey]['summary_missed'] += $breakdown['summary_count'];
        $monthly[$monthKey]['total_missed'] += $breakdown['total'];
        if (!$work['is_stale']) {
            $monthly[$monthKey]['worked_seconds'] += $work['worked_seconds'];
            $totalWorkedSeconds += $work['worked_seconds'];
        }
        if ($workFineable) {
            $monthly[$monthKey]['short_hours_shifts']++;
            $monthly[$monthKey]['short_hours_seconds'] += $work['short_seconds'];
        }
        $totalMissed += $breakdown['total'];
        if ($workFineable) {
            $totalShortShifts++;
        }
    }

    krsort($monthly);
    usort($daily, function ($a, $b) {
        return strcmp($b['start_time'], $a['start_time']);
    });

    return [
        'total_missed' => $totalMissed,
        'total_worked_seconds' => $totalWorkedSeconds,
        'total_worked_label' => formatWorkDuration($totalWorkedSeconds),
        'short_hours_shifts' => $totalShortShifts,
        'short_hours_fine' => calculateShortHoursFineAmount($totalShortShifts),
        'daily' => $daily,
        'monthly' => array_values($monthly),
    ];
}

/**
 * Weekday dates (Mon–Fri) between two Y-m-d dates inclusive.
 */
function getWeekdaysBetweenDates($startDate, $endDate) {
    $dates = [];
    $current = $startDate;

    while (strtotime($current) <= strtotime($endDate)) {
        $dayOfWeekNum = (int) date('N', strtotime($current));
        if ($dayOfWeekNum >= 1 && $dayOfWeekNum <= 5) {
            $dates[] = $current;
        }
        $current = date('Y-m-d', strtotime($current . ' +1 day'));
    }

    return $dates;
}

/**
 * First date absences can be counted: after join, and day after first clock-in.
 * Returns null if employee has never started a shift (no absence fines).
 */
function getEmployeeAbsenceStartDate($conn, $userId, $monthStart) {
    $userId = (int) $userId;
    $user = $conn->query("SELECT created_at FROM users WHERE id='$userId' LIMIT 1")->fetch_assoc();
    if (!$user) {
        return null;
    }

    $firstShift = $conn->query("
        SELECT MIN(DATE(start_time)) AS first_day
        FROM shifts
        WHERE employee_id='$userId'
    ")->fetch_assoc();

    if (empty($firstShift['first_day'])) {
        return null;
    }

    $joinDate = date('Y-m-d', strtotime($user['created_at']));
    $dayAfterFirstShift = date('Y-m-d', strtotime($firstShift['first_day'] . ' +1 day'));

    return max($monthStart, $joinDate, $dayAfterFirstShift);
}

/**
 * Whether admin granted relaxation for a weekday absence.
 */
function isAbsenceDateRelaxed($conn, $employeeId, $absenceDate) {
    $employeeId = (int) $employeeId;
    $absenceDate = mysqli_real_escape_string($conn, $absenceDate);
    $result = $conn->query("
        SELECT id FROM absence_relaxations
        WHERE employee_id='$employeeId' AND absence_date='$absenceDate'
        LIMIT 1
    ");
    return ($result && $result->num_rows > 0);
}

/**
 * Weekday absence dates for a month (includes already-relaxed days).
 */
function getEmployeeAbsenceDatesForMonth($conn, $employeeId, $month, $auditEndDate = null) {
    $employeeId = (int) $employeeId;
    $monthStart = $month . '-01';
    $monthEnd = date('Y-m-t', strtotime($monthStart));

    if ($auditEndDate === null) {
        $auditEndDate = ($month === date('Y-m')) ? date('Y-m-d', strtotime('yesterday')) : $monthEnd;
    }

    $absenceStartDate = getEmployeeAbsenceStartDate($conn, $employeeId, $monthStart);
    $rows = [];

    if ($absenceStartDate === null || strtotime($absenceStartDate) > strtotime($auditEndDate)) {
        return $rows;
    }

    $rangeStart = max($absenceStartDate, $monthStart);
    $weekdays = getWeekdaysBetweenDates($rangeStart, $auditEndDate);

    foreach ($weekdays as $date) {
        $relaxed = isAbsenceDateRelaxed($conn, $employeeId, $date);
        if ($relaxed) {
            $rows[] = ['date' => $date, 'relaxed' => true];
            continue;
        }

        $dateEsc = mysqli_real_escape_string($conn, $date);
        $shiftQuery = $conn->query("
            SELECT id FROM shifts
            WHERE employee_id='$employeeId'
            AND DATE(start_time)='$dateEsc'
            LIMIT 1
        ");
        if ($shiftQuery && $shiftQuery->num_rows > 0) {
            continue;
        }

        $leaveQuery = $conn->query("
            SELECT id FROM leave_requests
            WHERE employee_id='$employeeId'
            AND status='approved'
            AND '$dateEsc' BETWEEN from_date AND to_date
            LIMIT 1
        ");
        if ($leaveQuery && $leaveQuery->num_rows > 0) {
            continue;
        }

        $rows[] = ['date' => $date, 'relaxed' => false];
    }

    return $rows;
}

/**
 * Absence dates in a calendar range (for reports breakdown).
 */
function getEmployeeAbsenceDatesInRange($conn, $employeeId, $dateFrom, $dateTo) {
    $employeeId = (int) $employeeId;
    $rows = [];
    $month = date('Y-m', strtotime($dateFrom));
    $endMonth = date('Y-m', strtotime($dateTo));

    while ($month <= $endMonth) {
        $monthEnd = date('Y-m-t', strtotime($month . '-01'));
        $auditEnd = min($dateTo, $monthEnd);
        foreach (getEmployeeAbsenceDatesForMonth($conn, $employeeId, $month, $auditEnd) as $row) {
            if ($row['date'] >= $dateFrom && $row['date'] <= $dateTo) {
                $rows[] = $row;
            }
        }
        $month = date('Y-m', strtotime($month . '-01 +1 month'));
    }

    usort($rows, function ($a, $b) {
        return strcmp($b['date'], $a['date']);
    });

    return $rows;
}

/**
 * Recalculate automated penalties for one employee and month.
 */
function recalculateAutomatedPenaltiesForEmployeeMonth($conn, $employeeId, $month) {
    $employeeId = (int) $employeeId;
    $emp = $conn->query("SELECT * FROM users WHERE id='$employeeId' AND role='employee'")->fetch_assoc();
    if (!$emp) {
        return false;
    }

    $monthEsc = mysqli_real_escape_string($conn, $month);
    $conn->query("
        DELETE FROM penalties
        WHERE employee_id='$employeeId'
        AND DATE_FORMAT(created_at, '%Y-%m')='$monthEsc'
        AND (
            reason LIKE 'Monthly Shift Absences%'
            OR reason LIKE 'Monthly Missed Hourly Updates%'
            OR reason LIKE 'Monthly Missed Updates%'
            OR reason LIKE 'Monthly Short Hours%'
        )
    ");

    $monthEnd = date('Y-m-t', strtotime($month . '-01'));
    $auditEndDate = ($month === date('Y-m')) ? date('Y-m-d', strtotime('yesterday')) : $monthEnd;

    if (strtotime($auditEndDate) >= strtotime($month . '-01')) {
        runMonthlyPenaltyAuditForEmployee($conn, $emp, $month, $auditEndDate);
    }

    if ($month === date('Y-m')) {
        $sumRes = $conn->query("
            SELECT SUM(amount) AS total
            FROM penalties
            WHERE employee_id='$employeeId'
            AND waived = 0
            AND DATE_FORMAT(created_at, '%Y-%m')='" . date('Y-m') . "'
        ");
        $totalDeductions = 0;
        if ($sumRes) {
            $totalDeductions = floatval($sumRes->fetch_assoc()['total'] ?? 0);
        }
        $conn->query("UPDATE users SET total_deduction='$totalDeductions' WHERE id='$employeeId'");
    }

    return true;
}

/**
 * Admin: grant relaxation for one weekday absence.
 */
function grantAdminRelaxationForAbsenceDate($conn, $employeeId, $absenceDate, $grantedBy = 'Admin') {
    $employeeId = (int) $employeeId;
    $grantedBy = trim((string) $grantedBy) ?: 'Admin';
    $grantedByEsc = mysqli_real_escape_string($conn, $grantedBy);
    $absenceDate = trim((string) $absenceDate);
    $ts = strtotime($absenceDate);

    if ($ts === false) {
        return ['success' => false, 'credited' => 0, 'message' => 'Invalid absence date.'];
    }

    $absenceDate = date('Y-m-d', $ts);
    $month = date('Y-m', $ts);

    if (isAbsenceDateRelaxed($conn, $employeeId, $absenceDate)) {
        return ['success' => false, 'credited' => 0, 'message' => 'Relaxation already granted for this day.'];
    }

    $pending = getEmployeeAbsenceDatesForMonth($conn, $employeeId, $month);
    $isBillable = false;
    foreach ($pending as $row) {
        if ($row['date'] === $absenceDate && empty($row['relaxed'])) {
            $isBillable = true;
            break;
        }
    }

    if (!$isBillable) {
        return ['success' => false, 'credited' => 0, 'message' => 'This day is not a billable absence (shift exists, leave approved, or not eligible).'];
    }

    $dateEsc = mysqli_real_escape_string($conn, $absenceDate);
    $ok = $conn->query("
        INSERT INTO absence_relaxations (employee_id, absence_date, granted_by)
        VALUES ('$employeeId', '$dateEsc', '$grantedByEsc')
    ");

    if (!$ok) {
        return ['success' => false, 'credited' => 0, 'message' => 'Could not save relaxation: ' . $conn->error];
    }

    recalculateAutomatedPenaltiesForEmployeeMonth($conn, $employeeId, $month);

    return [
        'success' => true,
        'credited' => 1,
        'message' => 'Relaxation granted for absence on ' . date('d M Y', $ts) . '. Penalties recalculated.',
    ];
}

/**
 * Admin: grant relaxation for all billable absences in a month.
 */
function grantAdminRelaxationForEmployeeAbsenceMonth($conn, $employeeId, $month, $grantedBy = 'Admin') {
    $employeeId = (int) $employeeId;
    $grantedBy = trim((string) $grantedBy) ?: 'Admin';
    $grantedByEsc = mysqli_real_escape_string($conn, $grantedBy);
    $credited = 0;

    foreach (getEmployeeAbsenceDatesForMonth($conn, $employeeId, $month) as $row) {
        if (!empty($row['relaxed'])) {
            continue;
        }
        $dateEsc = mysqli_real_escape_string($conn, $row['date']);
        $ok = $conn->query("
            INSERT INTO absence_relaxations (employee_id, absence_date, granted_by)
            VALUES ('$employeeId', '$dateEsc', '$grantedByEsc')
        ");
        if ($ok) {
            $credited++;
        }
    }

    if ($credited > 0) {
        recalculateAutomatedPenaltiesForEmployeeMonth($conn, $employeeId, $month);
        $message = "Relaxation granted: {$credited} absence day(s) waived. Penalties recalculated.";
    } else {
        $message = 'No billable absence days to waive for this month.';
    }

    return [
        'success' => $credited > 0,
        'credited' => $credited,
        'message' => $message,
    ];
}

/**
 * PKR fine for missed updates per monthly rules (3 free, then 1,000 each).
 */
function calculateMissedUpdatesFineAmount($missedCount) {
    $missedCount = (int) $missedCount;
    if ($missedCount <= 3) {
        return 0;
    }
    return ($missedCount - 3) * 1000;
}

/**
 * Timestamp to store on penalty rows for a given audit month.
 */
function penaltyCreatedAtForMonth($month) {
    if ($month === date('Y-m')) {
        return date('Y-m-d H:i:s');
    }
    return date('Y-m-t', strtotime($month . '-01')) . ' 23:59:00';
}

/**
 * Run automated penalty audit for one employee and month.
 */
function runMonthlyPenaltyAuditForEmployee($conn, $emp, $month, $auditEndDate) {
    $user_id = (int) $emp['id'];
    $monthStart = $month . '-01';
    $createdAt = mysqli_real_escape_string($conn, penaltyCreatedAtForMonth($month));
    $auditEndDate = mysqli_real_escape_string($conn, $auditEndDate);

    $absenceStartDate = getEmployeeAbsenceStartDate($conn, $user_id, $monthStart);
    $missedShiftsCount = 0;
    $absenceDates = [];

    foreach (getEmployeeAbsenceDatesForMonth($conn, $user_id, $month, $auditEndDate) as $row) {
        if (!empty($row['relaxed'])) {
            continue;
        }
        $missedShiftsCount++;
        $absenceDates[] = $row['date'];
    }

    if ($missedShiftsCount > 0) {
        $fineAmount = $missedShiftsCount * 5000;
        $reason = "Monthly Shift Absences ($missedShiftsCount missed)";
        $conn->query("
            INSERT INTO penalties (employee_id, reason, amount, created_at)
            VALUES ('$user_id', '$reason', '$fineAmount', '$createdAt')
        ");
    }

    $auditTimestamp = getDatabaseNowTimestamp($conn);
    $shiftsList = getAuditableShiftsForPenaltyMonth($conn, $user_id, $month, $auditTimestamp);
    $totalMissedUpdates = 0;
    foreach ($shiftsList as $shiftRow) {
        $breakdown = getMissedUpdatesBreakdownForShift(
            $conn,
            $user_id,
            $shiftRow,
            $auditTimestamp
        );
        $totalMissedUpdates += $breakdown['total'];
    }

    if ($totalMissedUpdates > 3) {
        $fineAmount = calculateMissedUpdatesFineAmount($totalMissedUpdates);
        $reason = "Monthly Missed Updates ($totalMissedUpdates missed, 3 allowed)";
        $conn->query("
            INSERT INTO penalties (employee_id, reason, amount, created_at)
            VALUES ('$user_id', '$reason', '$fineAmount', '$createdAt')
        ");
    }

    $shortHours = summariseShortHoursForShifts($conn, $user_id, $shiftsList, $auditTimestamp);

    if ($shortHours['fine'] > 0) {
        $fineAmount = $shortHours['fine'];
        $reason = mysqli_real_escape_string(
            $conn,
            buildShortHoursPenaltyReason($shortHours['count'], $shortHours['short_seconds'])
        );
        $conn->query("
            INSERT INTO penalties (employee_id, reason, amount, created_at)
            VALUES ('$user_id', '$reason', '$fineAmount', '$createdAt')
        ");
    }

    return [
        'absences' => $missedShiftsCount,
        'absence_dates' => $absenceDates,
        'missed_updates' => $totalMissedUpdates,
        'missed_updates_fine' => calculateMissedUpdatesFineAmount($totalMissedUpdates),
        'short_hours' => $shortHours['count'],
        'short_hours_dates' => $shortHours['dates'],
        'short_hours_fine' => $shortHours['fine'],
    ];
}

/**
 * Run automated penalty audit for all employees for a given month (default: current).
 */
function runMonthlyPenaltyAudit($conn, $month = null) {
    $month = $month ?: date('Y-m');
    $monthEsc = mysqli_real_escape_string($conn, $month);
    $today = date('Y-m-d');
    $monthEnd = date('Y-m-t', strtotime($month . '-01'));
    $auditEndDate = ($month === date('Y-m')) ? date('Y-m-d', strtotime('yesterday')) : $monthEnd;

    if (strtotime($auditEndDate) < strtotime($month . '-01')) {
        return;
    }

    $employeesQuery = $conn->query("SELECT * FROM users WHERE role='employee'");
    if (!$employeesQuery) {
        return;
    }

    while ($emp = $employeesQuery->fetch_assoc()) {
        $user_id = (int) $emp['id'];

        $conn->query("
            DELETE FROM penalties
            WHERE employee_id='$user_id'
            AND DATE_FORMAT(created_at, '%Y-%m')='$monthEsc'
            AND (
                reason LIKE 'Monthly Shift Absences%'
                OR reason LIKE 'Monthly Missed Hourly Updates%'
                OR reason LIKE 'Monthly Missed Updates%'
                OR reason LIKE 'Monthly Short Hours%'
            )
        ");

        runMonthlyPenaltyAuditForEmployee($conn, $emp, $month, $auditEndDate);

        $sumResQuery = $conn->query("
            SELECT SUM(amount) as total
            FROM penalties
            WHERE employee_id='$user_id'
            AND waived = 0
            AND DATE_FORMAT(created_at, '%Y-%m')='" . date('Y-m') . "'
        ");

        $totalDeductions = 0;
        if ($sumResQuery) {
            $sumRes = $sumResQuery->fetch_assoc();
            $totalDeductions = floatval($sumRes['total'] ?? 0);
        }

        if ($month === date('Y-m')) {
            $conn->query("
                UPDATE users
                SET total_deduction='$totalDeductions'
                WHERE id='$user_id'
            ");
        }
    }
}

/**
 * Delete and rebuild all automated penalties using corrected rules.
 */
function recalculateAllAutomatedPenalties($conn) {
    $conn->query("
        DELETE FROM penalties
        WHERE reason LIKE 'Monthly Shift Absences%'
        OR reason LIKE 'Monthly Missed Hourly Updates%'
        OR reason LIKE 'Monthly Missed Updates%'
    ");

    $minRow = $conn->query("
        SELECT MIN(DATE(created_at)) AS start_date
        FROM users
        WHERE role='employee'
    ")->fetch_assoc();

    $startMonth = date('Y-m', strtotime($minRow['start_date'] ?? date('Y-m-d')));
    $endMonth = date('Y-m');
    $cursor = $startMonth;

    while ($cursor <= $endMonth) {
        runMonthlyPenaltyAudit($conn, $cursor);
        $cursor = date('Y-m', strtotime($cursor . '-01 +1 month'));
    }
}

/* =========================================================
   BONUSES — admin-added earnings applied to a chosen month
   ========================================================= */

/**
 * True when the string is a valid YYYY-MM payslip month key.
 */
function isValidPayrollMonthKey($month) {
    return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $month);
}

/**
 * Write one entry into the bonus activity log.
 */
function logBonusActivity($conn, $bonusId, $employeeId, $month, $action, $amount, $message, $adminId = null, $adminName = 'Admin') {
    $stmt = $conn->prepare("
        INSERT INTO bonus_logs
            (bonus_id, employee_id, bonus_month, action, amount, message, performed_by, performed_by_name, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    if (!$stmt) {
        return false;
    }

    $bonusId = $bonusId > 0 ? (int) $bonusId : null;
    $employeeId = (int) $employeeId;
    $amount = floatval($amount);
    $adminId = $adminId > 0 ? (int) $adminId : null;

    $stmt->bind_param('iissdsis', $bonusId, $employeeId, $month, $action, $amount, $message, $adminId, $adminName);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

/**
 * Admin action: add a bonus to a specific payslip month.
 */
function processAdminAddBonus($conn, $employeeId, $month, $title, $amount, $adminId = null, $adminName = 'Admin') {
    $employeeId = (int) $employeeId;
    $month = trim((string) $month);
    $title = trim((string) $title);
    $amount = floatval($amount);

    if ($employeeId <= 0) {
        return ['success' => false, 'message' => 'Please select an employee.'];
    }
    if (!isValidPayrollMonthKey($month)) {
        return ['success' => false, 'message' => 'Please select a valid bonus month (YYYY-MM).'];
    }
    if ($title === '') {
        return ['success' => false, 'message' => 'Please enter a bonus title / reason.'];
    }
    if ($amount <= 0) {
        return ['success' => false, 'message' => 'Bonus amount must be greater than zero.'];
    }

    $empRow = $conn->query("SELECT id, name FROM users WHERE id='$employeeId' AND role='employee' LIMIT 1");
    $emp = $empRow ? $empRow->fetch_assoc() : null;
    if (!$emp) {
        return ['success' => false, 'message' => 'Employee record not found.'];
    }

    $stmt = $conn->prepare("
        INSERT INTO bonuses (employee_id, bonus_month, title, amount, created_by, created_by_name, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    if (!$stmt) {
        return ['success' => false, 'message' => 'Could not save the bonus (database error).'];
    }

    $createdBy = $adminId > 0 ? (int) $adminId : null;
    $stmt->bind_param('issdis', $employeeId, $month, $title, $amount, $createdBy, $adminName);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'message' => 'Could not save the bonus (database error).'];
    }
    $bonusId = (int) $stmt->insert_id;
    $stmt->close();

    $monthLabel = date('F Y', strtotime($month . '-01'));
    $logMessage = 'Bonus of PKR ' . number_format($amount) . ' (' . $title . ') added to '
        . $emp['name'] . "'s " . $monthLabel . ' salary slip by ' . $adminName . '.';
    logBonusActivity($conn, $bonusId, $employeeId, $month, 'added', $amount, $logMessage, $adminId, $adminName);

    return [
        'success' => true,
        'message' => $logMessage,
        'bonus_id' => $bonusId,
    ];
}

/**
 * Admin action: remove a bonus and log the removal.
 */
function processAdminDeleteBonus($conn, $bonusId, $adminId = null, $adminName = 'Admin') {
    $bonusId = (int) $bonusId;
    if ($bonusId <= 0) {
        return ['success' => false, 'message' => 'Invalid bonus reference.'];
    }

    $result = $conn->query("
        SELECT b.*, u.name AS employee_name
        FROM bonuses b
        LEFT JOIN users u ON u.id = b.employee_id
        WHERE b.id='$bonusId'
        LIMIT 1
    ");
    $bonus = $result ? $result->fetch_assoc() : null;
    if (!$bonus) {
        return ['success' => false, 'message' => 'Bonus entry not found.'];
    }

    if (!$conn->query("DELETE FROM bonuses WHERE id='$bonusId'")) {
        return ['success' => false, 'message' => 'Could not remove the bonus (database error).'];
    }

    $amount = floatval($bonus['amount']);
    $monthLabel = date('F Y', strtotime($bonus['bonus_month'] . '-01'));
    $logMessage = 'Bonus of PKR ' . number_format($amount) . ' (' . $bonus['title'] . ') removed from '
        . ($bonus['employee_name'] ?? 'employee') . "'s " . $monthLabel . ' salary slip by ' . $adminName . '.';
    logBonusActivity($conn, 0, (int) $bonus['employee_id'], $bonus['bonus_month'], 'removed', $amount, $logMessage, $adminId, $adminName);

    return ['success' => true, 'message' => $logMessage];
}

/**
 * Bonus rows for one employee. Pass $month = null for all-time history.
 */
function getEmployeeBonusRows($conn, $employeeId, $month = null) {
    $employeeId = (int) $employeeId;
    $sql = "SELECT id, employee_id, bonus_month, title, amount, created_by_name, created_at
            FROM bonuses
            WHERE employee_id='$employeeId'";

    if ($month !== null) {
        if (!isValidPayrollMonthKey($month)) {
            return [];
        }
        $sql .= " AND bonus_month='" . mysqli_real_escape_string($conn, $month) . "'";
    }

    $sql .= ' ORDER BY bonus_month DESC, created_at DESC, id DESC';

    $rows = [];
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    return $rows;
}

/**
 * Bonus total for one employee. Pass $month = null for the all-time total.
 */
function getEmployeeBonusTotal($conn, $employeeId, $month = null) {
    $employeeId = (int) $employeeId;
    $sql = "SELECT COALESCE(SUM(amount), 0) AS total FROM bonuses WHERE employee_id='$employeeId'";

    if ($month !== null) {
        if (!isValidPayrollMonthKey($month)) {
            return 0.0;
        }
        $sql .= " AND bonus_month='" . mysqli_real_escape_string($conn, $month) . "'";
    }

    $result = $conn->query($sql);
    $row = $result ? $result->fetch_assoc() : null;

    return floatval($row['total'] ?? 0);
}

/**
 * Per-month bonus totals for one employee (newest month first).
 */
function getEmployeeBonusMonthlyTotals($conn, $employeeId) {
    $employeeId = (int) $employeeId;
    $months = [];
    $result = $conn->query("
        SELECT bonus_month, COUNT(*) AS entries, SUM(amount) AS total
        FROM bonuses
        WHERE employee_id='$employeeId'
        GROUP BY bonus_month
        ORDER BY bonus_month DESC
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $months[] = [
                'month' => $row['bonus_month'],
                'label' => date('F Y', strtotime($row['bonus_month'] . '-01')),
                'entries' => (int) $row['entries'],
                'total' => floatval($row['total']),
            ];
        }
    }

    return $months;
}

/**
 * Bonus totals for the whole workforce in a given month.
 */
function getWorkforceBonusTotals($conn, $month) {
    $byEmployee = [];
    $total = 0.0;

    if (!isValidPayrollMonthKey($month)) {
        return ['total' => 0.0, 'by_employee' => []];
    }

    $result = $conn->query("
        SELECT employee_id, SUM(amount) AS total
        FROM bonuses
        WHERE bonus_month='" . mysqli_real_escape_string($conn, $month) . "'
        GROUP BY employee_id
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $amount = floatval($row['total']);
            $byEmployee[(int) $row['employee_id']] = $amount;
            $total += $amount;
        }
    }

    return ['total' => $total, 'by_employee' => $byEmployee];
}

/**
 * Bonus rows for a month across all employees (admin listing).
 */
function getBonusesForMonth($conn, $month) {
    if (!isValidPayrollMonthKey($month)) {
        return [];
    }

    $rows = [];
    $result = $conn->query("
        SELECT b.*, u.name AS employee_name, u.email AS employee_email
        FROM bonuses b
        LEFT JOIN users u ON u.id = b.employee_id
        WHERE b.bonus_month='" . mysqli_real_escape_string($conn, $month) . "'
        ORDER BY b.created_at DESC, b.id DESC
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    return $rows;
}

/* =========================================================
   EMPLOYEE REQUESTS — late joining, urgent issues, breaks
   ========================================================= */

// Fine applied when one of these shift changes happens without an approved request.
define('REQUEST_VIOLATION_PENALTY_AMOUNT', 5000);

/**
 * Log a penalty against a specific date (not "now"), so back-dated violations
 * land on the correct payslip month.
 */
function addPenaltyOnDate($conn, $employeeId, $reason, $amount, $penaltyDate = null) {
    $employeeId = (int) $employeeId;
    $amount = floatval($amount);
    $reasonEsc = mysqli_real_escape_string($conn, $reason);

    $timestamp = ($penaltyDate !== null && strtotime($penaltyDate) !== false)
        ? date('Y-m-d H:i:s', strtotime($penaltyDate))
        : date('Y-m-d H:i:s');
    $timestampEsc = mysqli_real_escape_string($conn, $timestamp);

    $conn->query("INSERT INTO penalties (employee_id, reason, amount, created_at)
                  VALUES ('$employeeId', '$reasonEsc', '$amount', '$timestampEsc')");

    $conn->query("UPDATE users
                  SET total_deduction = total_deduction + $amount
                  WHERE id='$employeeId'");

    return (int) $conn->insert_id;
}

/**
 * Canonical penalty reason for an unapproved request, e.g.
 * "Unapproved Request — Late Joining [2026-08-04]".
 * The ISO date in brackets makes the row matchable for waiving.
 */
function buildRequestViolationReason($typeKey, $date) {
    $label = getEmployeeRequestTypeMeta($typeKey)['label'];
    $iso = (strtotime((string) $date) !== false) ? date('Y-m-d', strtotime($date)) : date('Y-m-d');

    return 'Unapproved Request — ' . $label . ' [' . $iso . ']';
}

/**
 * Find an existing request-violation penalty for one employee/type/date.
 */
function findRequestViolationPenalty($conn, $employeeId, $typeKey, $date) {
    $employeeId = (int) $employeeId;
    $reason = mysqli_real_escape_string($conn, buildRequestViolationReason($typeKey, $date));

    $result = $conn->query("
        SELECT id, amount
        FROM penalties
        WHERE employee_id='$employeeId'
        AND reason='$reason'
        LIMIT 1
    ");

    return $result ? $result->fetch_assoc() : null;
}

/**
 * Apply the PKR 5,000 fine for a shift change made without an approved request.
 * Idempotent: the same employee/type/date is never fined twice.
 */
function applyRequestViolationPenalty($conn, $employeeId, $typeKey, $date, $appliedBy = 'Admin') {
    $employeeId = (int) $employeeId;

    if ($employeeId <= 0) {
        return ['success' => false, 'applied' => false, 'message' => 'Invalid employee reference.'];
    }
    if (!array_key_exists($typeKey, getEmployeeRequestTypes())) {
        return ['success' => false, 'applied' => false, 'message' => 'Invalid request type.'];
    }
    if (strtotime((string) $date) === false) {
        return ['success' => false, 'applied' => false, 'message' => 'Invalid violation date.'];
    }

    $label = getEmployeeRequestTypeMeta($typeKey)['label'];
    $isoDate = date('Y-m-d', strtotime($date));

    if (findRequestViolationPenalty($conn, $employeeId, $typeKey, $isoDate)) {
        return [
            'success' => true,
            'applied' => false,
            'message' => $label . ' penalty is already recorded for ' . date('d M Y', strtotime($isoDate)) . '.',
        ];
    }

    $reason = buildRequestViolationReason($typeKey, $isoDate);
    addPenaltyOnDate($conn, $employeeId, $reason, REQUEST_VIOLATION_PENALTY_AMOUNT, $isoDate . ' ' . date('H:i:s'));

    return [
        'success' => true,
        'applied' => true,
        'amount' => REQUEST_VIOLATION_PENALTY_AMOUNT,
        'message' => 'PKR ' . number_format(REQUEST_VIOLATION_PENALTY_AMOUNT) . ' penalty applied for unapproved '
            . $label . ' on ' . date('d M Y', strtotime($isoDate)) . '.',
    ];
}

/**
 * Remove a request-violation fine (used when a request is approved after the
 * fine was already logged).
 */
function waiveRequestViolationPenalty($conn, $employeeId, $typeKey, $date) {
    $employeeId = (int) $employeeId;
    $existing = findRequestViolationPenalty($conn, $employeeId, $typeKey, $date);

    if (!$existing) {
        return ['success' => true, 'waived' => false, 'message' => ''];
    }

    $penaltyId = (int) $existing['id'];
    $amount = floatval($existing['amount']);

    $conn->query("DELETE FROM penalties WHERE id='$penaltyId'");
    $conn->query("UPDATE users
                  SET total_deduction = GREATEST(total_deduction - $amount, 0)
                  WHERE id='$employeeId'");

    return [
        'success' => true,
        'waived' => true,
        'amount' => $amount,
        'message' => 'PKR ' . number_format($amount) . ' penalty waived.',
    ];
}

/* =========================================================
   ADMIN ACTIONS ON STORED PENALTY ROWS (misconduct etc.)
   ========================================================= */

/**
 * Load one stored penalty row with its employee name.
 */
function getStoredPenaltyRow($conn, $penaltyId) {
    $penaltyId = (int) $penaltyId;
    if ($penaltyId <= 0) {
        return null;
    }

    $result = $conn->query("
        SELECT p.*, u.name AS employee_name
        FROM penalties p
        LEFT JOIN users u ON u.id = p.employee_id
        WHERE p.id='$penaltyId'
        LIMIT 1
    ");

    return $result ? $result->fetch_assoc() : null;
}

/**
 * Automated monthly rows are rebuilt by the penalty engine, so admin waive /
 * delete only applies to admin-logged fines (misconduct, unapproved requests).
 */
function isAdminEditablePenaltyRow($row) {
    return $row && !isAutomatedPenaltyReason($row['reason']);
}

/**
 * Waive a stored fine without losing the record. The row stays visible in
 * reports with a waived badge and stops counting toward any total.
 */
function waiveStoredPenalty($conn, $penaltyId, $waivedBy = 'Admin', $note = '') {
    $row = getStoredPenaltyRow($conn, $penaltyId);

    if (!$row) {
        return ['success' => false, 'message' => 'Penalty record not found.'];
    }
    if (!isAdminEditablePenaltyRow($row)) {
        return ['success' => false, 'message' => 'Automated penalties cannot be waived here — use the relaxation buttons in the daily breakdown.'];
    }
    if (!empty($row['waived'])) {
        return ['success' => true, 'message' => 'This penalty is already waived.'];
    }

    $penaltyId = (int) $row['id'];
    $employeeId = (int) $row['employee_id'];
    $amount = floatval($row['amount']);
    $waivedByEsc = mysqli_real_escape_string($conn, trim((string) $waivedBy) ?: 'Admin');
    $noteEsc = mysqli_real_escape_string($conn, mb_substr(trim((string) $note), 0, 255));

    $conn->query("
        UPDATE penalties
        SET waived = 1,
            waived_at = NOW(),
            waived_by = '$waivedByEsc',
            waive_note = " . ($noteEsc === '' ? 'NULL' : "'$noteEsc'") . "
        WHERE id='$penaltyId'
    ");

    $conn->query("UPDATE users
                  SET total_deduction = GREATEST(total_deduction - $amount, 0)
                  WHERE id='$employeeId'");

    return [
        'success' => true,
        'message' => 'PKR ' . number_format($amount) . ' fine waived off for '
            . ($row['employee_name'] ?? 'employee') . '. The record is kept for audit.',
    ];
}

/**
 * Undo a waiver — the fine counts again.
 */
function restoreStoredPenalty($conn, $penaltyId) {
    $row = getStoredPenaltyRow($conn, $penaltyId);

    if (!$row) {
        return ['success' => false, 'message' => 'Penalty record not found.'];
    }
    if (empty($row['waived'])) {
        return ['success' => true, 'message' => 'This penalty is already active.'];
    }

    $penaltyId = (int) $row['id'];
    $employeeId = (int) $row['employee_id'];
    $amount = floatval($row['amount']);

    $conn->query("
        UPDATE penalties
        SET waived = 0,
            waived_at = NULL,
            waived_by = NULL,
            waive_note = NULL
        WHERE id='$penaltyId'
    ");

    $conn->query("UPDATE users
                  SET total_deduction = total_deduction + $amount
                  WHERE id='$employeeId'");

    return [
        'success' => true,
        'message' => 'PKR ' . number_format($amount) . ' fine re-applied.',
    ];
}

/**
 * Remove a stored fine permanently (no audit trail left).
 */
function deleteStoredPenalty($conn, $penaltyId) {
    $row = getStoredPenaltyRow($conn, $penaltyId);

    if (!$row) {
        return ['success' => false, 'message' => 'Penalty record not found.'];
    }
    if (!isAdminEditablePenaltyRow($row)) {
        return ['success' => false, 'message' => 'Automated penalties cannot be deleted here — they are rebuilt by the penalty engine.'];
    }

    $penaltyId = (int) $row['id'];
    $employeeId = (int) $row['employee_id'];
    $amount = floatval($row['amount']);
    $wasWaived = !empty($row['waived']);

    $conn->query("DELETE FROM penalties WHERE id='$penaltyId'");

    if (!$wasWaived) {
        $conn->query("UPDATE users
                      SET total_deduction = GREATEST(total_deduction - $amount, 0)
                      WHERE id='$employeeId'");
    }

    return [
        'success' => true,
        'message' => 'PKR ' . number_format($amount) . ' fine deleted for '
            . ($row['employee_name'] ?? 'employee') . '.',
    ];
}

/**
 * Supported request categories, keyed by the value stored in the DB.
 */
function getEmployeeRequestTypes() {
    return [
        'late_joining' => [
            'label' => 'Late Joining',
            'icon' => '🕐',
            'hint' => 'You will start your shift later than the scheduled time.',
        ],
        'urgent_issue' => [
            'label' => 'Urgent Issue',
            'icon' => '🚨',
            'hint' => 'Emergency or blocker that affects your shift today.',
        ],
        'extended_break' => [
            'label' => 'Extended Break',
            'icon' => '☕',
            'hint' => 'A break longer than the standard allowance.',
        ],
        'early_leave' => [
            'label' => 'Early Sign-off',
            'icon' => '🏃',
            'hint' => 'You need to close your shift before the end time.',
        ],
        'change_workstation' => [
            'label' => 'Change Workstation',
            'icon' => '🖥️',
            'hint' => 'Working from a different desk, system, office or location. Mention the new location in the details.',
        ],
        'other' => [
            'label' => 'Other Request',
            'icon' => '📌',
            'hint' => 'Anything not covered by the categories above.',
        ],
    ];
}

/**
 * Display metadata for one request type (falls back to a generic label).
 */
function getEmployeeRequestTypeMeta($typeKey) {
    $types = getEmployeeRequestTypes();
    if (isset($types[$typeKey])) {
        return $types[$typeKey];
    }

    return ['label' => ucwords(str_replace('_', ' ', (string) $typeKey)), 'icon' => '📌', 'hint' => ''];
}

/**
 * Badge class for a request status.
 */
function getEmployeeRequestStatusBadge($status) {
    $status = strtolower(trim((string) $status));
    if ($status === 'approved') {
        return 'success';
    }
    if ($status === 'rejected') {
        return 'danger';
    }
    return 'warning';
}

/**
 * Employee action: submit a new request for admin approval.
 */
function processEmployeeRequestSubmission($conn, $employeeId, $type, $subject, $details, $requestDate, $fromTime = '', $toTime = '') {
    $employeeId = (int) $employeeId;
    $type = trim((string) $type);
    $subject = trim((string) $subject);
    $details = trim((string) $details);
    $requestDate = trim((string) $requestDate);
    $fromTime = trim((string) $fromTime);
    $toTime = trim((string) $toTime);
    $result = ['success' => false, 'message' => '', 'messageType' => 'danger'];

    if (!array_key_exists($type, getEmployeeRequestTypes())) {
        $result['message'] = 'Please choose a valid request type.';
        return $result;
    }

    if ($details === '') {
        $result['message'] = 'Please describe your request so the admin can review it.';
        return $result;
    }

    if ($requestDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestDate) || strtotime($requestDate) === false) {
        $result['message'] = 'Please select the date this request applies to.';
        return $result;
    }

    if ($fromTime !== '' && $toTime !== '' && strtotime($fromTime) > strtotime($toTime)) {
        $result['message'] = "The 'From Time' must be before or equal to the 'To Time'.";
        return $result;
    }

    if ($subject === '') {
        $subject = getEmployeeRequestTypeMeta($type)['label'];
    }

    $stmt = $conn->prepare("
        INSERT INTO employee_requests
            (employee_id, request_type, subject, details, request_date, from_time, to_time, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    if (!$stmt) {
        $result['message'] = 'Database Error: ' . $conn->error;
        return $result;
    }

    $fromTimeValue = ($fromTime !== '') ? $fromTime : null;
    $toTimeValue = ($toTime !== '') ? $toTime : null;
    $stmt->bind_param('issssss', $employeeId, $type, $subject, $details, $requestDate, $fromTimeValue, $toTimeValue);

    if (!$stmt->execute()) {
        $stmt->close();
        $result['message'] = 'Database Error: ' . $conn->error;
        return $result;
    }
    $stmt->close();

    $result['success'] = true;
    $result['messageType'] = 'success';
    $result['message'] = getEmployeeRequestTypeMeta($type)['label']
        . ' request submitted for ' . date('d M Y', strtotime($requestDate)) . '. Waiting for admin approval.';

    return $result;
}

/**
 * Admin action: approve or reject an employee request, with optional remarks.
 */
function processAdminEmployeeRequestAction($conn, $requestId, $action, $remarks = '', $adminId = null, $adminName = 'Admin', $applyPenalty = true) {
    $requestId = (int) $requestId;
    $action = strtolower(trim((string) $action));
    $remarks = trim((string) $remarks);

    if (!in_array($action, ['approved', 'rejected'], true)) {
        return ['success' => false, 'message' => 'Invalid request action.'];
    }
    if ($requestId <= 0) {
        return ['success' => false, 'message' => 'Invalid request reference.'];
    }

    $result = $conn->query("
        SELECT r.*, u.name AS employee_name
        FROM employee_requests r
        LEFT JOIN users u ON u.id = r.employee_id
        WHERE r.id='$requestId'
        LIMIT 1
    ");
    $request = $result ? $result->fetch_assoc() : null;
    if (!$request) {
        return ['success' => false, 'message' => 'Request not found.'];
    }

    if (strtolower(trim($request['status'])) !== 'pending') {
        return [
            'success' => false,
            'message' => 'This request was already ' . strtolower($request['status']) . '.',
        ];
    }

    if ($remarks === '') {
        $remarks = ($action === 'approved')
            ? 'Approved by ' . $adminName . '.'
            : 'Rejected by ' . $adminName . '.';
    }

    $employeeId = (int) $request['employee_id'];
    $typeKey = $request['request_type'];
    $violationDate = !empty($request['request_date']) ? $request['request_date'] : date('Y-m-d');

    // Rejected = the shift change was not authorised, so the standard fine applies.
    // Approved = clear any fine already logged for that employee/type/date.
    $penaltyNote = '';
    $penaltyApplied = 0;
    $penaltyAmount = 0.0;

    if ($action === 'rejected' && $applyPenalty) {
        $penaltyResult = applyRequestViolationPenalty($conn, $employeeId, $typeKey, $violationDate, $adminName);
        if (!empty($penaltyResult['applied'])) {
            $penaltyApplied = 1;
            $penaltyAmount = floatval($penaltyResult['amount']);
            $penaltyNote = ' ' . $penaltyResult['message'];
            $remarks .= ' (Penalty: PKR ' . number_format($penaltyAmount) . ')';
        }
    } elseif ($action === 'approved') {
        $waiveResult = waiveRequestViolationPenalty($conn, $employeeId, $typeKey, $violationDate);
        if (!empty($waiveResult['waived'])) {
            $penaltyNote = ' ' . $waiveResult['message'];
            $remarks .= ' (Previously logged penalty waived)';
        }
    }

    $stmt = $conn->prepare("
        UPDATE employee_requests
        SET status=?, admin_response=?, reviewed_by=?, reviewed_by_name=?, reviewed_at=NOW(),
            penalty_applied=?, penalty_amount=?
        WHERE id=? AND status='pending'
    ");
    if (!$stmt) {
        return ['success' => false, 'message' => 'Could not update the request (database error).'];
    }

    $reviewerId = $adminId > 0 ? (int) $adminId : null;
    $stmt->bind_param('ssisidi', $action, $remarks, $reviewerId, $adminName, $penaltyApplied, $penaltyAmount, $requestId);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'message' => 'Could not update the request (database error).'];
    }
    $stmt->close();

    $typeLabel = getEmployeeRequestTypeMeta($typeKey)['label'];

    return [
        'success' => true,
        'penalty_applied' => (bool) $penaltyApplied,
        'message' => $typeLabel . ' request from ' . ($request['employee_name'] ?? 'employee')
            . ' has been ' . $action . '.' . $penaltyNote,
    ];
}

/**
 * Admin: log a shift change that happened with no request at all, and fine it.
 */
function processAdminUnrequestedViolation($conn, $employeeId, $typeKey, $date, $note = '', $adminId = null, $adminName = 'Admin') {
    $employeeId = (int) $employeeId;
    $date = trim((string) $date);
    $note = trim((string) $note);

    if ($employeeId <= 0) {
        return ['success' => false, 'message' => 'Please select an employee.'];
    }
    if (!array_key_exists($typeKey, getEmployeeRequestTypes())) {
        return ['success' => false, 'message' => 'Please choose a valid violation type.'];
    }
    if ($date === '' || strtotime($date) === false) {
        return ['success' => false, 'message' => 'Please select the date the violation happened.'];
    }

    $empRow = $conn->query("SELECT id, name FROM users WHERE id='$employeeId' AND role='employee' LIMIT 1");
    $emp = $empRow ? $empRow->fetch_assoc() : null;
    if (!$emp) {
        return ['success' => false, 'message' => 'Employee record not found.'];
    }

    $isoDate = date('Y-m-d', strtotime($date));
    $label = getEmployeeRequestTypeMeta($typeKey)['label'];

    // An approved request for the same day means this was authorised after all.
    $approved = $conn->query("
        SELECT id FROM employee_requests
        WHERE employee_id='$employeeId'
        AND request_type='" . mysqli_real_escape_string($conn, $typeKey) . "'
        AND request_date='" . mysqli_real_escape_string($conn, $isoDate) . "'
        AND status='approved'
        LIMIT 1
    ");
    if ($approved && $approved->num_rows > 0) {
        return [
            'success' => false,
            'message' => $emp['name'] . ' already has an approved ' . $label . ' request for '
                . date('d M Y', strtotime($isoDate)) . '. No penalty applied.',
        ];
    }

    $penaltyResult = applyRequestViolationPenalty($conn, $employeeId, $typeKey, $isoDate, $adminName);
    if (!$penaltyResult['success']) {
        return ['success' => false, 'message' => $penaltyResult['message']];
    }
    if (empty($penaltyResult['applied'])) {
        return ['success' => false, 'message' => $penaltyResult['message']];
    }

    // Record it as a rejected request so it shows in the employee's own history.
    $subject = 'Unrequested ' . $label;
    $details = ($note !== '')
        ? $note
        : $label . ' on ' . date('d M Y', strtotime($isoDate)) . ' without submitting a request.';
    $response = 'Logged by ' . $adminName . ' — no request was submitted. Penalty: PKR '
        . number_format(REQUEST_VIOLATION_PENALTY_AMOUNT) . '.';

    $stmt = $conn->prepare("
        INSERT INTO employee_requests
            (employee_id, request_type, subject, details, request_date, status,
             admin_response, reviewed_by, reviewed_by_name, reviewed_at,
             penalty_applied, penalty_amount, created_at)
        VALUES (?, ?, ?, ?, ?, 'rejected', ?, ?, ?, NOW(), 1, ?, NOW())
    ");
    if ($stmt) {
        $reviewerId = $adminId > 0 ? (int) $adminId : null;
        $amount = (float) REQUEST_VIOLATION_PENALTY_AMOUNT;
        $stmt->bind_param('isssssisd', $employeeId, $typeKey, $subject, $details, $isoDate, $response, $reviewerId, $adminName, $amount);
        $stmt->execute();
        $stmt->close();
    }

    return [
        'success' => true,
        'message' => $emp['name'] . ': ' . $penaltyResult['message'],
    ];
}

/**
 * Fetch employee requests. Pass $employeeId = null for all employees,
 * $status = null (or 'all') for every status.
 */
function getEmployeeRequests($conn, $employeeId = null, $status = null, $limit = 0) {
    $where = [];

    if ($employeeId !== null) {
        $where[] = "r.employee_id='" . (int) $employeeId . "'";
    }

    $status = strtolower(trim((string) $status));
    if ($status !== '' && $status !== 'all' && in_array($status, ['pending', 'approved', 'rejected'], true)) {
        $where[] = "r.status='" . mysqli_real_escape_string($conn, $status) . "'";
    }

    $sql = "SELECT r.*, u.name AS employee_name, u.email AS employee_email
            FROM employee_requests r
            LEFT JOIN users u ON u.id = r.employee_id";

    if (count($where) > 0) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    // Pending first, then newest.
    $sql .= " ORDER BY (r.status='pending') DESC, r.request_date DESC, r.id DESC";

    $limit = (int) $limit;
    if ($limit > 0) {
        $sql .= " LIMIT $limit";
    }

    $rows = [];
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    return $rows;
}

/**
 * Request counts per status. Pass $employeeId = null for the whole workforce.
 */
function getEmployeeRequestStatusCounts($conn, $employeeId = null) {
    $counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'total' => 0];

    $sql = "SELECT status, COUNT(*) AS total FROM employee_requests";
    if ($employeeId !== null) {
        $sql .= " WHERE employee_id='" . (int) $employeeId . "'";
    }
    $sql .= ' GROUP BY status';

    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $key = strtolower(trim($row['status']));
            $total = (int) $row['total'];
            if (isset($counts[$key])) {
                $counts[$key] = $total;
            }
            $counts['total'] += $total;
        }
    }

    return $counts;
}

/**
 * Recent bonus activity log entries. Pass $month = null for all months.
 */
function getBonusActivityLog($conn, $limit = 20, $month = null) {
    $limit = max(1, (int) $limit);
    $sql = "SELECT l.*, u.name AS employee_name
            FROM bonus_logs l
            LEFT JOIN users u ON u.id = l.employee_id";

    if ($month !== null && isValidPayrollMonthKey($month)) {
        $sql .= " WHERE l.bonus_month='" . mysqli_real_escape_string($conn, $month) . "'";
    }

    $sql .= " ORDER BY l.id DESC LIMIT $limit";

    $rows = [];
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    return $rows;
}
?>