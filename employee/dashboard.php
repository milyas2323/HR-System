<?php
include "../includes/db.php";
include "../includes/auth.php";
include "../includes/functions.php";

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