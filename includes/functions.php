<?php
if(session_status() == PHP_SESSION_NONE){
    session_start();
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
 * Find which slot (if any) a timestamp falls into.
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
 * Check whether this employee already submitted for this shift + slot.
 */
function hasHourlyUpdateInSlot($conn, $employeeId, $shiftId, $slotDate, $slotHour) {
    $employeeId = (int) $employeeId;
    $shiftId = (int) $shiftId;
    $slotHour = (int) $slotHour;
    $slotDate = mysqli_real_escape_string($conn, $slotDate);

    $result = $conn->query("
        SELECT id FROM hourly_updates
        WHERE employee_id='$employeeId'
        AND shift_id='$shiftId'
        AND slot_date='$slotDate'
        AND slot_hour='$slotHour'
        LIMIT 1
    ");

    return ($result && $result->num_rows > 0);
}

/**
 * Count how many required slots are filled for a shift.
 */
function countFilledHourlySlotsForShift($conn, $employeeId, $shiftId, $slots) {
    $filled = 0;
    foreach ($slots as $slot) {
        if (hasHourlyUpdateInSlot($conn, $employeeId, $shiftId, $slot['slot_date'], $slot['slot_hour'])) {
            $filled++;
        }
    }
    return $filled;
}

/**
 * Count missed hourly slots (only slots whose window has already ended).
 */
function countMissedHourlySlotsForShift($conn, $employeeId, $shiftId, $shiftStartDatetime, $auditTimestamp = null) {
    $auditTimestamp = $auditTimestamp ?? time();
    $slots = getHourlySlotDefinitionsForShift($shiftStartDatetime);
    $missed = 0;

    foreach ($slots as $slot) {
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
?>