<?php
include_once "../includes/db.php";
include_once "../includes/auth.php";
include_once "../includes/functions.php";

$user = $_SESSION['user'];

// Refresh user info from DB
$refreshUser = $conn->query("SELECT * FROM users WHERE id='{$user['id']}' LIMIT 1");
if ($refreshUser && $refreshUser->num_rows > 0) {
    $user = $refreshUser->fetch_assoc();
    $_SESSION['user'] = $user;
}

$page = $_GET['page'] ?? 'home';

/* ACTIVE SHIFT */
$shift = $conn->query("
    SELECT * FROM shifts 
    WHERE employee_id='{$user['id']}' 
    AND status='active' 
    LIMIT 1
");
$active = $shift->fetch_assoc();

/* LEAVE MESSAGES */
$leaveMsg = $conn->query("
    SELECT status, reason, from_date, to_date, message, created_at 
    FROM leave_requests 
    WHERE employee_id='{$user['id']}'
    ORDER BY id DESC 
    LIMIT 5
");

/* HOURLY UPDATE WARNING (15-minute slot windows) */
$warningMsg = "";
if($active){
    $dbNowTs = getDatabaseNowTimestamp($conn);
    $slots = getHourlySlotDefinitionsForShift($active['start_time']);
    $shiftId = (int) $active['id'];
    $employeeId = (int) $user['id'];
    $currentSlot = findHourlySlotForTimestamp($slots, $dbNowTs);

    $missedSoFar = 0;
    foreach ($slots as $slot) {
        if (!isHourlySlotRequiredForShift($slot, $active['start_time'])) {
            continue;
        }
        if ($dbNowTs > $slot['end_ts'] && !hasHourlyUpdateInSlot($conn, $employeeId, $shiftId, $slot['slot_date'], $slot['slot_hour'])) {
            $missedSoFar++;
        }
    }

    if ($missedSoFar > 0) {
        $warningMsg = "You have missed $missedSoFar hourly slot(s) so far. Each slot allows one submission between :00 and :15 (e.g. 7:00–7:15 PM). Late submissions are rejected.";
    } elseif ($currentSlot && !hasHourlyUpdateInSlot($conn, $employeeId, $shiftId, $currentSlot['slot_date'], $currentSlot['slot_hour'])) {
        $warningMsg = "Submit your hourly update now for the " . $currentSlot['label'] . " window before it closes.";
    }
}

/* MISSED UPDATES (current month) */
$employeeId = (int) $user['id'];
$dbNowTs = getDatabaseNowTimestamp($conn);
$currentMonthStart = date('Y-m-01');
$currentMonthEnd = date('Y-m-t');
$monthShifts = [];
$monthShiftsResult = $conn->query("
    SELECT * FROM shifts
    WHERE employee_id='$employeeId'
    AND DATE(start_time) BETWEEN '$currentMonthStart' AND '$currentMonthEnd'
    ORDER BY start_time DESC
");
if ($monthShiftsResult) {
    while ($row = $monthShiftsResult->fetch_assoc()) {
        $monthShifts[] = $row;
    }
}
$missedReport = buildEmployeeMissedUpdatesReport($conn, $employeeId, $monthShifts, $dbNowTs);
$missedCounts = countBillableMissedUpdatesForShifts($conn, $employeeId, $monthShifts, $dbNowTs);
$currentMonthKey = date('Y-m');
$currentMonthSummary = null;
foreach ($missedReport['monthly'] as $monthRow) {
    if ($monthRow['month'] === $currentMonthKey) {
        $currentMonthSummary = $monthRow;
        break;
    }
}
$expectedMissedFine = calculateMissedUpdatesFineAmount($missedCounts['billable']);
?>
<?php include "../includes/header.php"; ?>

<style>
    /* Add styling specifically for active links in employee panel */
    .sidebar a.active-page {
        background: linear-gradient(135deg, var(--primary) 0%, #4f46e5 100%);
        color: var(--text-main);
        transform: translateX(4px);
        box-shadow: 0 8px 20px var(--primary-glow);
    }
</style>

<!-- SIDEBAR -->
<div class="sidebar">

    <div class="profile">
        <img src="../uploads/profile/<?php echo $user['profile_pic'] ? $user['profile_pic'] : 'default.png'; ?>" alt="Profile">
        <h3><?php echo htmlspecialchars($user['name']); ?></h3>
        <p>Employee Portal</p>
    </div>

    <nav>
        <a href="dashboard.php" class="<?php echo ($page == 'home') ? 'active-page' : ''; ?>">
            <span>📊</span> Dashboard
        </a>
        <a href="dashboard.php?page=profile" class="<?php echo ($page == 'profile') ? 'active-page' : ''; ?>">
            <span>👤</span> Profile Settings
        </a>
        <a href="dashboard.php?page=start-shift" class="<?php echo ($page == 'start-shift') ? 'active-page' : ''; ?>">
            <span>⏰</span> Start Shift
        </a>
        <a href="dashboard.php?page=hourly-update" class="<?php echo ($page == 'hourly-update') ? 'active-page' : ''; ?>">
            <span>📝</span> Hourly Update
        </a>
        <a href="dashboard.php?page=end-report" class="<?php echo ($page == 'end-report') ? 'active-page' : ''; ?>">
            <span>🏁</span> End Shift Report
        </a>
        <a href="dashboard.php?page=leave-request" class="<?php echo ($page == 'leave-request') ? 'active-page' : ''; ?>">
            <span>📅</span> Leave Request
        </a>
        <a href="dashboard.php?page=salary-slip" class="<?php echo ($page == 'salary-slip') ? 'active-page' : ''; ?>">
            <span>💵</span> Salary Slip
        </a>
    </nav>

    <a href="../logout.php" style="margin-top: auto; background: rgba(239, 68, 68, 0.1); color: var(--danger); border-color: rgba(239, 68, 68, 0.2);">
        <span>🚪</span> Logout
    </a>

</div>

<!-- MAIN CONTENT -->
<div class="main">

    <!-- HOURLY UPDATE WARNING -->
    <?php if($warningMsg != "") { ?>
        <div class="alert danger glowing-element">
            <span>⚠️</span>
            <strong>Urgent Alert:</strong> <?php echo $warningMsg; ?>
        </div>
    <?php } ?>

    <!-- GENERAL MESSAGE -->
    <?php if(isset($_SESSION['msg'])){ ?>
        <div class="alert success">
            <span>✅</span>
            <span><?php echo $_SESSION['msg']; unset($_SESSION['msg']); ?></span>
        </div>
    <?php } ?>

    <?php if($page == 'home'){ ?>

        <!-- HEADER -->
        <div class="topbar">
            <div>
                <h1>Employee Dashboard</h1>
                <p>Welcome back, <?php echo htmlspecialchars($user['name']); ?>. Keep track of your daily tasks.</p>
            </div>
            <?php if($user['assigned_latitude'] && $user['assigned_longitude']){ ?>
                <span class="location-badge">
                    📍 Geofenced: <?php echo htmlspecialchars($user['assigned_location'] ? $user['assigned_location'] : 'Configured'); ?>
                </span>
            <?php } ?>
        </div>

        <div class="grid">
            <!-- SHIFT CARD -->
            <div class="card">
                <h2>Shift Status</h2>
                <?php if($active){ ?>
                    <p class="active" style="display: flex; align-items: center; gap: 8px;">
                        <span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: var(--success); box-shadow: 0 0 10px var(--success);"></span>
                        Active Shift Running
                    </p>
                    <div style="margin-top: 15px; color: var(--text-muted); font-size: 0.95rem;">
                        <p style="margin-bottom: 5px;">Started At: <strong style="color: var(--text-main);"><?php echo date("h:i A - d M Y", strtotime($active['start_time'])); ?></strong></p>
                        <p style="margin-bottom: 5px;">Device detected: <strong style="color: var(--text-main);"><?php echo htmlspecialchars($active['device']); ?></strong></p>
                        <p>Workplace IP: <strong style="color: var(--text-main);"><?php echo htmlspecialchars($active['ip_address']); ?></strong></p>
                    </div>
                <?php } else { ?>
                    <p class="inactive" style="display: flex; align-items: center; gap: 8px;">
                        <span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: var(--danger); box-shadow: 0 0 10px var(--danger);"></span>
                        No Active Shift
                    </p>
                    <p style="margin-top: 15px; color: var(--text-muted); font-size: 0.9rem;">
                        Please navigate to the <strong>Start Shift</strong> page to check-in.
                    </p>
                <?php } ?>
            </div>

            <!-- DEDUCTIONS CARD -->
            <div class="card">
                <h2>Salary Deductions</h2>
                <p style="font-size: 2rem; font-family: var(--font-heading); font-weight: 800; color: var(--danger);">
                    PKR <?php echo number_format($user['total_deduction']); ?>
                </p>
                <p style="color: var(--text-muted); font-size: 0.9rem; margin-top: 10px;">
                    Deductions accrued this month from absences, missed hourly slots (:00–:15 windows), or missed end reports.
                </p>
            </div>

            <!-- MISSED UPDATES SUMMARY -->
            <div class="card stat-box" style="border-bottom: 4px solid var(--danger);">
                <h2>Missed Updates (<?php echo date('M Y'); ?>)</h2>
                <p style="font-size: 2rem; font-family: var(--font-heading); font-weight: 800; color: var(--danger); margin: 0;">
                    <?php echo (int) $missedReport['total_missed']; ?>
                </p>
                <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: 10px;">
                    Total missed this month
                    <?php if ($missedCounts['pending'] > 0) { ?>
                        · <strong><?php echo (int) $missedCounts['pending']; ?></strong> pending on open shift
                    <?php } ?>
                </p>
                <?php if ($currentMonthSummary) { ?>
                    <p style="color: var(--text-muted); font-size: 0.8rem; margin-top: 8px;">
                        <?php echo (int) $currentMonthSummary['hourly_missed']; ?> hourly slot(s)
                        · <?php echo (int) $currentMonthSummary['summary_missed']; ?> end report(s)
                    </p>
                <?php } ?>
                <?php if ($expectedMissedFine > 0) { ?>
                    <p style="color: var(--danger); font-size: 0.8rem; margin-top: 8px;">
                        Est. missed-update fine: PKR <?php echo number_format($expectedMissedFine); ?> (3 free/month, then PKR 1,000 each)
                    </p>
                <?php } ?>
            </div>
        </div>

        <!-- MISSED UPDATES DETAIL -->
        <div class="card">
            <h2>Missed Updates — Daily Breakdown</h2>
            <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 15px;">
                Per shift: missed hourly windows (:00–:15) and end-of-day report. Open shift slots are not counted until the window closes.
            </p>

            <?php if ($active && $missedCounts['pending'] > 0) { ?>
                <div class="alert warning" style="margin-bottom: 16px;">
                    <span>⚠️</span>
                    <span>Your active shift has <strong><?php echo (int) $missedCounts['pending']; ?></strong> missed update(s) so far. Submit during each 15-minute window to avoid penalties.</span>
                </div>
            <?php } ?>

            <div class="table-box">
                <table>
                    <tr>
                        <th>Workday</th>
                        <th>Clock In</th>
                        <th>Shift</th>
                        <th>Hourly (filled / req.)</th>
                        <th>Missed Slots</th>
                        <th>End Report</th>
                        <th>Total Missed</th>
                    </tr>
                    <?php if (count($missedReport['daily']) > 0) {
                        foreach ($missedReport['daily'] as $dayRow) {
                    ?>
                        <tr>
                            <td><strong><?php echo date('d M Y', strtotime($dayRow['date'])); ?></strong></td>
                            <td style="font-size: 0.85rem;"><?php echo date('h:i A', strtotime($dayRow['start_time'])); ?></td>
                            <td>
                                <span class="badge <?php echo $dayRow['status'] === 'active' ? 'success' : 'warning'; ?>">
                                    <?php echo strtoupper($dayRow['status']); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo (int) $dayRow['hourly_filled']; ?> / <?php echo (int) $dayRow['hourly_required']; ?>
                                <?php if ($dayRow['hourly_missed'] > 0) { ?>
                                    <span class="badge danger" style="margin-left: 4px;"><?php echo (int) $dayRow['hourly_missed']; ?> missed</span>
                                <?php } ?>
                            </td>
                            <td style="font-size: 0.8rem; max-width: 260px; line-height: 1.5;">
                                <?php if (count($dayRow['missed_slots']) > 0) { ?>
                                    <?php foreach ($dayRow['missed_slots'] as $label) { ?>
                                        <span class="badge danger" style="margin: 2px 4px 2px 0; font-size: 0.7rem;"><?php echo htmlspecialchars($label); ?></span>
                                    <?php } ?>
                                <?php } else { ?>
                                    <span style="color: var(--text-muted);">—</span>
                                <?php } ?>
                            </td>
                            <td>
                                <?php
                                if ($dayRow['status'] === 'active' && !$dayRow['summary_missed']) {
                                    echo '<span class="badge warning">Pending</span>';
                                } elseif ($dayRow['summary_missed']) {
                                    echo '<span class="badge danger">Missed</span>';
                                } else {
                                    echo '<span class="badge success">Submitted</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <strong style="color: <?php echo $dayRow['total_missed'] > 0 ? 'var(--danger)' : 'var(--success)'; ?>;">
                                    <?php echo (int) $dayRow['total_missed']; ?>
                                </strong>
                            </td>
                        </tr>
                    <?php }
                    } else { ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 24px;">
                                No shifts recorded for <?php echo date('F Y'); ?> yet.
                            </td>
                        </tr>
                    <?php } ?>
                </table>
            </div>
        </div>

        <!-- LEAVE UPDATES -->
        <div class="card">
            <h2>Recent Leave Applications</h2>
            <?php if($leaveMsg && $leaveMsg->num_rows > 0){ ?>
                <div class="table-box">
                    <table>
                        <tr>
                            <th>From</th>
                            <th>To</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th>Response Message</th>
                        </tr>
                        <?php while($row = $leaveMsg->fetch_assoc()){ 
                            $status = strtolower(trim($row['status']));
                            $badgeClass = "warning";
                            if($status === 'approved') $badgeClass = "success";
                            if($status === 'rejected') $badgeClass = "danger";
                        ?>
                            <tr>
                                <td><?php echo $row['from_date']; ?></td>
                                <td><?php echo $row['to_date']; ?></td>
                                <td><?php echo htmlspecialchars($row['reason']); ?></td>
                                <td>
                                    <span class="badge <?php echo $badgeClass; ?>">
                                        <?php echo strtoupper($status); ?>
                                    </span>
                                </td>
                                <td><?php echo $row['message'] ? htmlspecialchars($row['message']) : '-'; ?></td>
                            </tr>
                        <?php } ?>
                    </table>
                </div>
            <?php } else { ?>
                <p style="color: var(--text-muted); text-align: center; padding: 20px;">No leave requests submitted yet.</p>
            <?php } ?>
        </div>

    <?php } else { 
        if($page == 'start-shift') include "checkin.php";
        elseif($page == 'hourly-update') include "hourly-update.php";
        elseif($page == 'end-report') include "end-report.php";
        elseif($page == 'leave-request') include "leave-request.php";
        elseif($page == 'profile') include "profile.php";
        elseif($page == 'salary-slip') include "salary-slip.php";
    } ?>

</div>

<?php include "../includes/footer.php"; ?>