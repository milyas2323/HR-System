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

    if ($absenceStartDate !== null && strtotime($absenceStartDate) <= strtotime($auditEndDate)) {
        $weekdays = getWeekdaysBetweenDates($absenceStartDate, $auditEndDate);

        foreach ($weekdays as $date) {
            $dateEsc = mysqli_real_escape_string($conn, $date);
            $shiftQuery = $conn->query("
                SELECT id FROM shifts
                WHERE employee_id='$user_id'
                AND DATE(start_time)='$dateEsc'
                LIMIT 1
            ");

            if ($shiftQuery && $shiftQuery->num_rows == 0) {
                $leaveQuery = $conn->query("
                    SELECT id FROM leave_requests
                    WHERE employee_id='$user_id'
                    AND status='approved'
                    AND '$dateEsc' BETWEEN from_date AND to_date
                    LIMIT 1
                ");

                if ($leaveQuery && $leaveQuery->num_rows == 0) {
                    $missedShiftsCount++;
                    $absenceDates[] = $date;
                }
            }
        }
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