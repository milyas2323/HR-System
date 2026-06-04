<?php
include_once __DIR__ . "/../includes/db.php";
include_once __DIR__ . "/../includes/functions.php";

/**
 * =========================================================================
 * UNIFIED DAILY PENALTY AUDITOR ENGINE
 * =========================================================================
 * Shift Timings: 6:00 PM to 3:00 AM (Next Day)
 * Target: 7 Hourly Updates (15-min windows :00–:15 each hour) + 1 End of Day Report per workday
 * 
 * Rules:
 * 1. Working Days: Monday to Friday (Saturday & Sunday are excluded).
 * 2. Missed Shift Starts (Absences): Fined PKR 5,000 directly per absence (0 allowed).
 * 3. Missed Updates (Hourly + Summary): 3 allowed per month, PKR 1,000 per missed update thereafter.
 * =========================================================================
 */

$today = date('Y-m-d');
$month = date('Y-m');
$yesterday = date('Y-m-d', strtotime('yesterday'));

// Calculate past weekdays in the current month up to yesterday
$startOfMonth = date('Y-m-01');
$pastWeekdays = [];
$currentDate = $startOfMonth;

while (strtotime($currentDate) <= strtotime($yesterday)) {
    $dayOfWeekNum = date('N', strtotime($currentDate));
    if ($dayOfWeekNum >= 1 && $dayOfWeekNum <= 5) {
        $pastWeekdays[] = $currentDate;
    }
    $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
}

// Fetch all active employees into a PHP array first to avoid MySQLi cursor conflicts
$employeesList = [];
$employeesQuery = $conn->query("SELECT * FROM users WHERE role='employee'");
if ($employeesQuery) {
    while ($empRow = $employeesQuery->fetch_assoc()) {
        $employeesList[] = $empRow;
    }
}

foreach ($employeesList as $emp) {
    $user_id = $emp['id'];

    // Clear previous automated penalties of this month to perform a fresh recount
    $conn->query("
        DELETE FROM penalties 
        WHERE employee_id='$user_id' 
        AND DATE_FORMAT(created_at, '%Y-%m')='$month' 
        AND (reason LIKE 'Monthly Shift Absences%' OR reason LIKE 'Monthly Missed Hourly Updates%' OR reason LIKE 'Monthly Missed Updates%')
    ");

    // 1. --- AUDIT CUMULATIVE MISSED SHIFT STARTS (ABSENCES) ---
    $missedShiftsCount = 0;
    foreach ($pastWeekdays as $date) {
        $shiftQuery = $conn->query("
            SELECT id FROM shifts 
            WHERE employee_id='{$user_id}' 
            AND DATE(start_time)='{$date}' 
            LIMIT 1
        ");
        
        if ($shiftQuery && $shiftQuery->num_rows == 0) {
            // Check if there is an approved leave request covering this day
            $leaveQuery = $conn->query("
                SELECT id FROM leave_requests 
                WHERE employee_id='{$user_id}' 
                AND status='approved' 
                AND '{$date}' BETWEEN from_date AND to_date 
                LIMIT 1
            ");
            
            if ($leaveQuery && $leaveQuery->num_rows == 0) {
                $missedShiftsCount++;
            }
        }
    }

    // Apply PKR 5,000 fine for each missed shift start directly (0 allowed)
    if ($missedShiftsCount > 0) {
        $fineAmount = $missedShiftsCount * 5000;
        $reason = "Monthly Shift Absences ($missedShiftsCount missed)";
        
        $conn->query("
            INSERT INTO penalties (employee_id, reason, amount, created_at) 
            VALUES ('$user_id', '$reason', '$fineAmount', NOW())
        ");
    }

    // 2. --- AUDIT CUMULATIVE MISSED UPDATES (HOURLY + SUMMARY) ---
    $totalMissedUpdates = 0;
    
    // Fetch all completed shifts started in the current month into a PHP array first
    $shiftsList = [];
    $shiftsQuery = $conn->query("
        SELECT id, start_time 
        FROM shifts 
        WHERE employee_id='{$user_id}' 
        AND DATE_FORMAT(start_time, '%Y-%m')='$month'
        AND (status='closed' OR DATE_ADD(start_time, INTERVAL 9 HOUR) < NOW())
    ");
    
    if ($shiftsQuery) {
        while ($shiftRow = $shiftsQuery->fetch_assoc()) {
            $shiftsList[] = $shiftRow;
        }
    }
    
    foreach ($shiftsList as $shiftRow) {
        $start_time = $shiftRow['start_time'];
        $shift_id = $shiftRow['id'];
        
        // Count missed 15-minute hourly slots (7:00–7:15 PM through 1:00–1:15 AM)
        $hourlyMissed = countMissedHourlySlotsForShift(
            $conn,
            $user_id,
            $shift_id,
            $start_time,
            getDatabaseNowTimestamp($conn)
        );

        // Count summary report submitted for this shift
        $summaryCountQuery = $conn->query("
            SELECT COUNT(*) as total FROM end_reports 
            WHERE shift_id='{$shift_id}'
        ");

        $summarySubmitted = 0;
        if ($summaryCountQuery) {
            $summaryCount = $summaryCountQuery->fetch_assoc();
            $summarySubmitted = intval($summaryCount['total']);
        }
        $summaryMissed = max(0, 1 - min(1, $summarySubmitted));

        $totalMissedUpdates += ($hourlyMissed + $summaryMissed);
    }

    // Apply PKR 1,000 fine for each missed update past the first 3 allowed in the month
    if ($totalMissedUpdates > 3) {
        $breachUpdates = $totalMissedUpdates - 3;
        $fineAmount = $breachUpdates * 1000;
        $reason = "Monthly Missed Updates ($totalMissedUpdates missed, 3 allowed)";
        
        $conn->query("
            INSERT INTO penalties (employee_id, reason, amount, created_at) 
            VALUES ('$user_id', '$reason', '$fineAmount', NOW())
        ");
    }

    // 3. --- RECALCULATE MONTHLY ACCRUED DEDUCTIONS POOL ---
    $sumResQuery = $conn->query("
        SELECT SUM(amount) as total 
        FROM penalties 
        WHERE employee_id='$user_id' 
        AND DATE_FORMAT(created_at, '%Y-%m')='$month'
    ");
    
    $totalDeductions = 0;
    if ($sumResQuery) {
        $sumRes = $sumResQuery->fetch_assoc();
        $totalDeductions = floatval($sumRes['total'] ?? 0);
    }
    
    $conn->query("
        UPDATE users 
        SET total_deduction='$totalDeductions' 
        WHERE id='$user_id'
    ");
}
?>
