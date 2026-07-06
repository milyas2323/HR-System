<?php
include_once "../includes/db.php";
include_once "../includes/auth.php";
include_once "../includes/functions.php";

$user = refreshSessionUserFromDatabase($conn);
if (!$user) {
    header('Location: ../login.php');
    exit();
}

if (($user['role'] ?? '') !== 'admin') {
    if (($user['role'] ?? '') === 'employee') {
        header('Location: ../employee/dashboard.php');
    } else {
        header('Location: ../login.php');
    }
    exit();
}

$page = isset($_GET['page']) ? $_GET['page'] : 'home';

// POST actions for included pages must run before any HTML output (reports.php is included later).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recalculate_penalties'])) {
    recalculateAllAutomatedPenalties($conn);
    $_SESSION['msg'] = 'Automated penalties recalculated using corrected rules (no fines before first clock-in).';
    $redirect = 'dashboard.php?page=reports';
    if (!empty($_POST['employee_id'])) {
        $redirect .= '&employee_id=' . (int) $_POST['employee_id'];
    }
    header('Location: ' . $redirect);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grant_hourly_relaxation'])) {
    $grantShiftId = (int) ($_POST['shift_id'] ?? 0);
    $grantEmployeeId = (int) ($_POST['employee_id'] ?? 0);
    $adminName = $_SESSION['user']['name'] ?? 'Admin';

    if ($grantShiftId > 0 && $grantEmployeeId > 0) {
        $grantResult = grantAdminRelaxationForShiftMissedHourly($conn, $grantEmployeeId, $grantShiftId, $adminName);
        if ($grantResult['credited'] > 0) {
            runMonthlyPenaltyAudit($conn);
        }
        $_SESSION['msg'] = $grantResult['message']
            . ($grantResult['credited'] > 0 ? ' Penalties recalculated.' : '');
    } else {
        $_SESSION['msg'] = 'Invalid relaxation request.';
    }

    $redirect = 'dashboard.php?page=reports&employee_id=' . $grantEmployeeId;
    if (!empty($_POST['date_from']) && !empty($_POST['date_to'])) {
        $redirect .= '&date_from=' . urlencode($_POST['date_from']) . '&date_to=' . urlencode($_POST['date_to']);
    }
    if (!empty($_POST['hourly_all'])) {
        $redirect .= '&hourly_all=1';
    } elseif (!empty($_POST['hourly_from']) && !empty($_POST['hourly_to'])) {
        $redirect .= '&hourly_from=' . urlencode($_POST['hourly_from']) . '&hourly_to=' . urlencode($_POST['hourly_to']);
    }
    if (!empty($_POST['hourly_sort']) && $_POST['hourly_sort'] === 'asc') {
        $redirect .= '&hourly_sort=asc';
    }
    header('Location: ' . $redirect);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grant_absence_relaxation_date'])) {
    $grantEmployeeId = (int) ($_POST['employee_id'] ?? 0);
    $absenceDate = trim($_POST['absence_date'] ?? '');
    $adminName = $_SESSION['user']['name'] ?? 'Admin';

    if ($grantEmployeeId > 0 && $absenceDate !== '') {
        $grantResult = grantAdminRelaxationForAbsenceDate($conn, $grantEmployeeId, $absenceDate, $adminName);
        $_SESSION['msg'] = $grantResult['message'];
    } else {
        $_SESSION['msg'] = 'Invalid absence relaxation request.';
    }

    $redirect = 'dashboard.php?page=reports&employee_id=' . $grantEmployeeId;
    if (!empty($_POST['date_from']) && !empty($_POST['date_to'])) {
        $redirect .= '&date_from=' . urlencode($_POST['date_from']) . '&date_to=' . urlencode($_POST['date_to']);
    }
    if (!empty($_POST['hourly_all'])) {
        $redirect .= '&hourly_all=1';
    } elseif (!empty($_POST['hourly_from']) && !empty($_POST['hourly_to'])) {
        $redirect .= '&hourly_from=' . urlencode($_POST['hourly_from']) . '&hourly_to=' . urlencode($_POST['hourly_to']);
    }
    if (!empty($_POST['hourly_sort']) && $_POST['hourly_sort'] === 'asc') {
        $redirect .= '&hourly_sort=asc';
    }
    header('Location: ' . $redirect);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grant_absence_relaxation_month'])) {
    $grantEmployeeId = (int) ($_POST['employee_id'] ?? 0);
    $penaltyMonth = trim($_POST['penalty_month'] ?? '');
    $adminName = $_SESSION['user']['name'] ?? 'Admin';

    if ($grantEmployeeId > 0 && preg_match('/^\d{4}-\d{2}$/', $penaltyMonth)) {
        $grantResult = grantAdminRelaxationForEmployeeAbsenceMonth($conn, $grantEmployeeId, $penaltyMonth, $adminName);
        $_SESSION['msg'] = $grantResult['message'];
    } else {
        $_SESSION['msg'] = 'Invalid absence relaxation request.';
    }

    $redirect = 'dashboard.php?page=reports&employee_id=' . $grantEmployeeId;
    if (!empty($_POST['date_from']) && !empty($_POST['date_to'])) {
        $redirect .= '&date_from=' . urlencode($_POST['date_from']) . '&date_to=' . urlencode($_POST['date_to']);
    }
    if (!empty($_POST['hourly_all'])) {
        $redirect .= '&hourly_all=1';
    } elseif (!empty($_POST['hourly_from']) && !empty($_POST['hourly_to'])) {
        $redirect .= '&hourly_from=' . urlencode($_POST['hourly_from']) . '&hourly_to=' . urlencode($_POST['hourly_to']);
    }
    if (!empty($_POST['hourly_sort']) && $_POST['hourly_sort'] === 'asc') {
        $redirect .= '&hourly_sort=asc';
    }
    header('Location: ' . $redirect);
    exit();
}

if ($page === 'leave-requests' && isset($_GET['action'], $_GET['id'])) {
    $leaveResult = processAdminLeaveRequestAction($conn, (int) $_GET['id'], $_GET['action']);
    $_SESSION['msg'] = $leaveResult['message'];
    header('Location: dashboard.php?page=leave-requests');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_attendance'])) {
    $conn->query('DELETE FROM shifts');
    $_SESSION['msg'] = 'Attendance data reset successfully!';
    header('Location: dashboard.php?page=attendance');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'hourly-submit' && isset($_POST['admin_hourly_check'])) {
    $slotKey = trim($_POST['slot_key'] ?? '');
    $slotDate = '';
    $slotHour = 0;
    if (preg_match('/^(\d{4}-\d{2}-\d{2})\|(\d{1,2})$/', $slotKey, $m)) {
        $slotDate = $m[1];
        $slotHour = (int) $m[2];
    }

    $submitResult = processAdminHourlyUpdateSubmission(
        $conn,
        (int) ($user['id'] ?? 0),
        (int) ($_POST['employee_id'] ?? 0),
        $slotDate,
        $slotHour,
        $_POST['update_text'] ?? ''
    );

    $_SESSION['hourly_submit_msg'] = $submitResult['message'];
    $_SESSION['hourly_submit_msg_type'] = $submitResult['messageType'];
    $redirectEmployeeId = (int) ($_POST['employee_id'] ?? 0);
    header('Location: dashboard.php?page=hourly-submit' . ($redirectEmployeeId > 0 ? '&employee_id=' . $redirectEmployeeId : ''));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'misconduct-penalty' && isset($_POST['submit'])) {
    $penaltyResult = processAdminMisconductPenalty(
        $conn,
        (int) ($_POST['employee_id'] ?? 0),
        $_POST['reason'] ?? '',
        $_POST['amount'] ?? 0
    );
    $_SESSION['msg'] = $penaltyResult['message'];
    header('Location: dashboard.php?page=misconduct-penalty');
    exit();
}

// --- PENALTY AUDIT: recalc current month on each admin visit (idempotent) ---
runMonthlyPenaltyAudit($conn);
?>
<?php include "../includes/header.php"; ?>

<style>
    /* Styling for active dashboard navigation links */
    .sidebar a.active-page {
        background: linear-gradient(135deg, var(--primary) 0%, #4f46e5 100%);
        color: var(--text-main);
        transform: translateX(4px);
        box-shadow: 0 8px 20px var(--primary-glow);
    }
</style>

<!-- SIDEBAR -->
<div class="sidebar">

    <div class="logo">
        <h2>Workforce Admin</h2>
        <p>Management Portal</p>
    </div>

    <nav>
        <a class="<?php echo ($page=='home') ? 'active-page' : ''; ?>" href="dashboard.php">
            <span>📊</span> Dashboard
        </a>
        <a class="<?php echo ($page=='employees') ? 'active-page' : ''; ?>" href="dashboard.php?page=employees">
            <span>👥</span> Employees
        </a>
        <a class="<?php echo ($page=='attendance') ? 'active-page' : ''; ?>" href="dashboard.php?page=attendance">
            <span>⏰</span> Attendance Logs
        </a>
        <a class="<?php echo ($page=='hourly-update' || $page=='hourly-submit') ? 'active-page' : ''; ?>" href="dashboard.php?page=hourly-update">
            <span>📝</span> Hourly Updates
        </a>
        <a class="<?php echo ($page=='penalties') ? 'active-page' : ''; ?>" href="dashboard.php?page=penalties">
            <span>💸</span> Salaries & Deduct
        </a>
        <a class="<?php echo ($page=='reports') ? 'active-page' : ''; ?>" href="dashboard.php?page=reports">
            <span>📁</span> Employee Reports
        </a>
        <a class="<?php echo ($page=='leave-requests') ? 'active-page' : ''; ?>" href="dashboard.php?page=leave-requests">
            <span>📅</span> Leave Approvals
        </a>
        <a class="<?php echo ($page=='misconduct-penalty') ? 'active-page' : ''; ?>" href="dashboard.php?page=misconduct-penalty">
            <span>⚠️</span> Log Misconduct
        </a>
    </nav>

    <a href="../logout.php" style="margin-top: auto; background: rgba(239, 68, 68, 0.1); color: var(--danger); border-color: rgba(239, 68, 68, 0.2);">
        <span>🚪</span> Logout
    </a>

</div>

<!-- MAIN VIEWPORT -->
<div class="main">

    <?php if($page == 'home'){ 
        // HOME STATISTICS QUERIES
        $employees = $conn->query("
            SELECT * FROM users 
            WHERE role='employee'
            ORDER BY id DESC
            LIMIT 5
        ");

        $total = $conn->query("
            SELECT COUNT(*) as total 
            FROM users 
            WHERE role='employee'
        ")->fetch_assoc();

        $penalties = $conn->query("
            SELECT SUM(amount) as total 
            FROM penalties
        ")->fetch_assoc();

        $attendance = $conn->query("
            SELECT COUNT(*) as total 
            FROM shifts 
            WHERE status='active'
        ")->fetch_assoc();
    ?>

        <!-- TOP HEADER BAR -->
        <div class="topbar">
            <div>
                <h1>Welcome Back, <?php echo htmlspecialchars($user['name']); ?> 👋</h1>
                <p>Monitor your active employee workforce, coordinate shift logs, and authorize pending leave requests.</p>
            </div>
        </div>

        <!-- STATS LAYOUT -->
        <div class="grid">
            <div class="card stat-box">
                <h4>Total Employees</h4>
                <h2><?php echo $total['total'] ?? 0; ?></h2>
            </div>

            <div class="card stat-box">
                <h4>Active Work Shifts</h4>
                <h2><?php echo $attendance['total'] ?? 0; ?></h2>
            </div>

            <div class="card stat-box" style="border-bottom: 4px solid var(--danger);">
                <h4>Total Fines Logged</h4>
                <h2 style="color: var(--danger);">PKR <?php echo number_format($penalties['total'] ?? 0); ?></h2>
            </div>
        </div>

        <!-- RECENT EMPLOYEES GRID -->
        <div class="card">
            <h2>Recent Additions</h2>
            
            <div class="table-box">
                <table>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email Address</th>
                        <th>Salary</th>
                    </tr>
                    <?php if($employees->num_rows > 0){ 
                        while($row = $employees->fetch_assoc()){
                    ?>
                        <tr>
                            <td>#<?php echo $row['id']; ?></td>
                            <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($row['email']); ?></td>
                            <td style="color: var(--accent); font-weight: 600;">PKR <?php echo number_format($row['salary']); ?></td>
                        </tr>
                        <?php } 
                    } else { ?>
                        <tr>
                            <td colspan="4" style="text-align: center; color: var(--text-muted);">No employee records found.</td>
                        </tr>
                    <?php } ?>
                </table>
            </div>

            <div style="margin-top: 20px;">
                <a href="dashboard.php?page=employees" class="btn">👥 Manage Employees</a>
            </div>
        </div>

    <?php } else {
        if($page == 'employees') include "employees.php";
        elseif($page == 'attendance') include "attendance.php";
        elseif($page == 'hourly-update') include "hourly-update.php";
        elseif($page == 'hourly-submit') include "hourly-submit.php";
        elseif($page == 'penalties') include "penalties.php";
        elseif($page == 'reports') include "reports.php";
        elseif($page == 'leave-requests') include "leave-requests.php";
        elseif($page == 'misconduct-penalty') include "misconduct-penalty.php";
        elseif($page == 'salary-slip') include "salary-slip.php";
    } ?>

</div>

<?php include "../includes/footer.php"; ?>