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
 * Process employee hourly update form submission.
 */
function processEmployeeHourlyUpdateSubmission($conn, $employeeId, $updateText) {
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

    $stmt = $conn->prepare("
        INSERT INTO hourly_updates (employee_id, shift_id, slot_date, slot_hour, is_grandfathered, update_text)
        VALUES (?, ?, ?, ?, 0, ?)
    ");
    $slotHour = (int) $slot['slot_hour'];
    $stmt->bind_param('iisis', $employeeId, $shiftId, $slot['slot_date'], $slotHour, $updateText);

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
    return $type['key'] !== 'manual';
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
        foreach (getAuditableShiftsForPenaltyMonth($conn, $employeeId, $month, $auditTimestamp) as $shift) {
            $shiftDate = date('Y-m-d', strtotime($shift['start_time']));
            if ($shiftDate < $dateFrom || $shiftDate > $dateTo) {
                continue;
            }
            $breakdown = getMissedUpdatesBreakdownForShift($conn, $employeeId, $shift, $auditTimestamp);
            $monthMissed += $breakdown['total'];
        }

        $monthAbsenceFine = $monthAbsences * 5000;
        $monthMissedFine = calculateMissedUpdatesFineAmount($monthMissed);
        $monthFinedMissedCount = max(0, $monthMissed - 3);

        $absenceCount += $monthAbsences;
        $absenceFine += $monthAbsenceFine;
        $missedUpdatesTotal += $monthMissed;
        $missedUpdatesFinedCount += $monthFinedMissedCount;
        $missedUpdatesFine += $monthMissedFine;

        $byMonth[$month] = [
            'absences' => $monthAbsences,
            'absence_fine' => $monthAbsenceFine,
            'missed_updates' => $monthMissed,
            'missed_updates_fined_count' => $monthFinedMissedCount,
            'missed_updates_fine' => $monthMissedFine,
            'manual_fine' => 0.0,
        ];

        $month = date('Y-m', strtotime($month . '-01 +1 month'));
    }

    $manualFine = 0.0;
    $manualResult = $conn->query("
        SELECT amount, reason, created_at
        FROM penalties
        WHERE employee_id='$employeeId'
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
                    'manual_fine' => 0.0,
                ];
            }
            $byMonth[$rowMonth]['manual_fine'] += $amount;
        }
    }

    $automatedTotal = $absenceFine + $missedUpdatesFine;

    return [
        'total' => $automatedTotal + $manualFine,
        'automated_total' => $automatedTotal,
        'absence_count' => $absenceCount,
        'absence_fine' => $absenceFine,
        'missed_updates_total' => $missedUpdatesTotal,
        'missed_updates_fined_count' => $missedUpdatesFinedCount,
        'missed_updates_fine' => $missedUpdatesFine,
        'manual_fine' => $manualFine,
        'by_month' => $byMonth,
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
    }

    $manualResult = $conn->query("
        SELECT id, reason, amount, created_at
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
        'manual' => [
            'label' => 'Manual / Misconduct',
            'description' => 'Logged by admin',
            'count' => 0,
            'total' => 0.0,
        ],
    ];
    $total = 0.0;

    foreach ($rows as $row) {
        $key = $row['type']['key'];
        $amount = floatval($row['amount']);
        $breakdown[$key]['count']++;
        $breakdown[$key]['total'] += $amount;
        $total += $amount;
    }

    return [
        'rows' => $rows,
        'breakdown' => $breakdown,
        'total' => $total,
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

    foreach ($shiftsList as $shift) {
        $breakdown = getMissedUpdatesBreakdownForShift($conn, $employeeId, $shift, $auditTimestamp);
        $shiftDate = date('Y-m-d', strtotime($shift['start_time']));
        $monthKey = date('Y-m', strtotime($shift['start_time']));

        $missedLabels = array_map(function ($slot) {
            return $slot['label'];
        }, $breakdown['hourly']);

        $daily[] = [
            'shift_id' => (int) $shift['id'],
            'date' => $shiftDate,
            'start_time' => $shift['start_time'],
            'status' => strtolower(trim($shift['status'] ?? '')),
            'hourly_missed' => $breakdown['hourly_count'],
            'hourly_filled' => $breakdown['hourly_filled'],
            'hourly_required' => $breakdown['hourly_required'],
            'missed_slots' => $missedLabels,
            'summary_missed' => $breakdown['summary_missed'],
            'total_missed' => $breakdown['total'],
        ];

        if (!isset($monthly[$monthKey])) {
            $monthly[$monthKey] = [
                'month' => $monthKey,
                'shifts' => 0,
                'hourly_missed' => 0,
                'summary_missed' => 0,
                'total_missed' => 0,
            ];
        }

        $monthly[$monthKey]['shifts']++;
        $monthly[$monthKey]['hourly_missed'] += $breakdown['hourly_count'];
        $monthly[$monthKey]['summary_missed'] += $breakdown['summary_count'];
        $monthly[$monthKey]['total_missed'] += $breakdown['total'];
        $totalMissed += $breakdown['total'];
    }

    krsort($monthly);
    usort($daily, function ($a, $b) {
        return strcmp($b['start_time'], $a['start_time']);
    });

    return [
        'total_missed' => $totalMissed,
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

    return [
        'absences' => $missedShiftsCount,
        'absence_dates' => $absenceDates,
        'missed_updates' => $totalMissedUpdates,
        'missed_updates_fine' => calculateMissedUpdatesFineAmount($totalMissedUpdates),
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
            )
        ");

        runMonthlyPenaltyAuditForEmployee($conn, $emp, $month, $auditEndDate);

        $sumResQuery = $conn->query("
            SELECT SUM(amount) as total
            FROM penalties
            WHERE employee_id='$user_id'
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
?>