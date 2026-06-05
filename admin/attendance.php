<?php
include_once "../includes/db.php";
include_once "../includes/auth.php";

if($_SESSION['user']['role'] != 'admin'){
    exit("Access Denied");
}

$today = date('Y-m-d');
$show_all = isset($_GET['show_all']) && $_GET['show_all'] === '1';
$date_from_input = trim($_GET['date_from'] ?? '');
$date_to_input = trim($_GET['date_to'] ?? '');
$dateFilterActive = false;
$dateFilterError = '';
$date_from = '';
$date_to = '';
$isDefaultToday = false;

if ($show_all) {
    $dateFilterActive = false;
} elseif ($date_from_input === '' && $date_to_input === '') {
    $dateFilterActive = true;
    $isDefaultToday = true;
    $date_from = $today;
    $date_to = $today;
    $date_from_input = $today;
    $date_to_input = $today;
} elseif ($date_from_input !== '' || $date_to_input !== '') {
    if ($date_from_input === '' || $date_to_input === '') {
        $dateFilterError = 'Please select both a start date and an end date.';
    } else {
        $df = DateTime::createFromFormat('Y-m-d', $date_from_input);
        $dt = DateTime::createFromFormat('Y-m-d', $date_to_input);
        $dfValid = $df && $df->format('Y-m-d') === $date_from_input;
        $dtValid = $dt && $dt->format('Y-m-d') === $date_to_input;

        if (!$dfValid || !$dtValid) {
            $dateFilterError = 'Invalid date format. Use the date picker to select valid dates.';
        } elseif ($df > $dt) {
            $dateFilterError = 'Start date cannot be after end date.';
        } else {
            $dateFilterActive = true;
            $date_from = $date_from_input;
            $date_to = $date_to_input;
            $isDefaultToday = ($date_from === $today && $date_to === $today);
        }
    }
}

function attendanceDateBetweenClause($conn, $column, $from, $to) {
    $from = mysqli_real_escape_string($conn, $from);
    $to = mysqli_real_escape_string($conn, $to);
    return "DATE($column) BETWEEN '$from' AND '$to'";
}

$dateClauseShifts = $dateFilterActive
    ? ' WHERE ' . attendanceDateBetweenClause($conn, 'start_time', $date_from, $date_to)
    : '';

$dateClauseShiftsAnd = $dateFilterActive
    ? ' AND ' . attendanceDateBetweenClause($conn, 'start_time', $date_from, $date_to)
    : '';

/* STATISTICS */
$totalAttendance = $conn->query("SELECT COUNT(*) as total FROM shifts" . $dateClauseShifts)->fetch_assoc();
$activeShifts = $conn->query("SELECT COUNT(*) as total FROM shifts WHERE status='active'" . $dateClauseShiftsAnd)->fetch_assoc();
$closedShifts = $conn->query("SELECT COUNT(*) as total FROM shifts WHERE status='closed'" . $dateClauseShiftsAnd)->fetch_assoc();
?>

<div class="page-title">Workplace Attendance Audit Log</div>

<!-- DATE RANGE FILTER -->
<div class="card">
    <form method="GET" action="dashboard.php">
        <input type="hidden" name="page" value="attendance">

        <div class="form-group" style="margin-bottom: 0;">
            <label>Filter by date range <span style="color: var(--text-muted); font-weight: normal;">(defaults to today)</span></label>
            <div style="display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;">
                <div>
                    <label style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 4px; display: block;">From</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from_input); ?>" style="max-width: 200px;">
                </div>
                <div>
                    <label style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 4px; display: block;">To</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to_input); ?>" style="max-width: 200px;">
                </div>
                <button type="submit" class="btn glowing-element" style="margin-bottom: 0;">Apply Filters</button>
                <a href="dashboard.php?page=attendance" class="btn-secondary" style="display: inline-flex; align-items: center; padding: 10px 16px; text-decoration: none; margin-bottom: 0;">
                    Today
                </a>
                <?php if ($dateFilterActive) { ?>
                    <a href="dashboard.php?page=attendance&amp;show_all=1" class="btn-secondary" style="display: inline-flex; align-items: center; padding: 10px 16px; text-decoration: none; margin-bottom: 0;">
                        Show all records
                    </a>
                <?php } ?>
            </div>
        </div>
    </form>

    <?php if ($dateFilterError !== '') { ?>
        <div class="alert danger" style="margin-top: 16px; margin-bottom: 0;">
            <span>⚠️</span>
            <span><?php echo htmlspecialchars($dateFilterError); ?></span>
        </div>
    <?php } elseif ($dateFilterActive) { ?>
        <div class="alert info" style="margin-top: 16px; margin-bottom: 0;">
            <span>ℹ️</span>
            <span>
                <?php if ($isDefaultToday && $date_from === $date_to) { ?>
                    Showing <strong>today's</strong> attendance (<?php echo date('d M Y', strtotime($date_from)); ?>).
                <?php } elseif ($date_from === $date_to) { ?>
                    Showing attendance for <strong><?php echo date('d M Y', strtotime($date_from)); ?></strong>.
                <?php } else { ?>
                    Showing attendance from <strong><?php echo date('d M Y', strtotime($date_from)); ?></strong> to <strong><?php echo date('d M Y', strtotime($date_to)); ?></strong>.
                <?php } ?>
            </span>
        </div>
    <?php } elseif ($show_all) { ?>
        <div class="alert info" style="margin-top: 16px; margin-bottom: 0;">
            <span>ℹ️</span>
            <span>Showing <strong>all</strong> attendance records (no date filter).</span>
        </div>
    <?php } ?>
</div>

<!-- SYSTEM ACTIONS -->
<div style="display: flex; justify-content: flex-end; margin-bottom: 20px;">
    <form method="POST" onsubmit="return confirm('WARNING: Are you sure you want to permanently clear ALL attendance shifts? This action is irreversible!');">
        <button type="submit" name="reset_attendance" class="btn-danger">
            ⚠️ Reset All Attendance Records
        </button>
    </form>
</div>

<!-- STATS SUMMARY GRID -->
<div class="grid">
    <div class="card stat-box">
        <h4>Audit Records<?php echo $dateFilterActive ? ' (in range)' : ''; ?></h4>
        <h2><?php echo $totalAttendance['total'] ?? 0; ?></h2>
    </div>

    <div class="card stat-box" style="border-bottom: 4px solid var(--success);">
        <h4>Active Workshifts<?php echo $dateFilterActive ? ' (in range)' : ''; ?></h4>
        <h2 style="color: var(--success);"><?php echo $activeShifts['total'] ?? 0; ?></h2>
    </div>

    <div class="card stat-box">
        <h4>Closed Shifts<?php echo $dateFilterActive ? ' (in range)' : ''; ?></h4>
        <h2><?php echo $closedShifts['total'] ?? 0; ?></h2>
    </div>
</div>

<!-- LOG DATA TABLE -->
<div class="card">
    <h2>Attendance Shifts<?php echo $dateFilterActive ? ' — filtered' : ($show_all ? ' — all records' : ''); ?></h2>

    <div class="table-box">
        <table>
            <tr>
                <th>Employee</th>
                <th>Workday Screenshot</th>
                <th>Morning Message</th>
                <th>Clock In / Out</th>
                <th>Details (IP / Agent)</th>
                <th>Status</th>
                <th>Check-In Location</th>
            </tr>
            <?php
            $logs = $conn->query("
                SELECT s.*, u.name
                FROM shifts s
                JOIN users u ON u.id = s.employee_id
                $dateClauseShifts
                ORDER BY s.start_time DESC
            ");

            if($logs && $logs->num_rows > 0){
                while($row = $logs->fetch_assoc()){
                    $status = strtolower(trim($row['status']));
                    $badgeClass = ($status === 'active') ? 'success' : 'danger';
            ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>

                    <td>
                        <?php if(!empty($row['screenshot'])){ ?>
                            <a href="../uploads/screenshots/<?php echo $row['screenshot']; ?>" target="_blank">
                                <img src="../uploads/screenshots/<?php echo $row['screenshot']; ?>" class="screenshot" alt="Screenshot preview">
                            </a>
                        <?php } else { ?>
                            <span class="badge warning" style="font-size: 0.7rem;">No Screen Capture</span>
                        <?php } ?>
                    </td>

                    <td style="max-width: 180px; font-style: italic; color: var(--text-muted); word-wrap: break-word;">
                        "<?php echo htmlspecialchars($row['morning_message'] ? $row['morning_message'] : '-'); ?>"
                    </td>

                    <td style="font-size: 0.85rem;">
                        <div>🟢 <strong style="color: var(--success);"><?php echo date('h:i A', strtotime($row['start_time'])); ?></strong> (<?php echo date('d M Y', strtotime($row['start_time'])); ?>)</div>
                        <?php if(!empty($row['end_time'])){ ?>
                            <div style="margin-top: 5px;">🔴 <strong style="color: var(--danger);"><?php echo date('h:i A', strtotime($row['end_time'])); ?></strong> (<?php echo date('d M Y', strtotime($row['end_time'])); ?>)</div>
                        <?php } else { ?>
                            <div style="margin-top: 5px; color: var(--text-muted);">🔴 Open Shift</div>
                        <?php } ?>
                    </td>

                    <td style="font-size: 0.8rem; color: var(--text-muted);">
                        <div>IP: <strong style="color: var(--text-main);"><?php echo htmlspecialchars($row['ip_address'] ? $row['ip_address'] : 'Dev Loop'); ?></strong></div>
                        <div style="margin-top: 2px;">UA: <?php echo htmlspecialchars($row['device']); ?></div>
                    </td>

                    <td>
                        <span class="badge <?php echo $badgeClass; ?>">
                            <?php echo strtoupper($status); ?>
                        </span>
                    </td>

                    <td style="max-width: 220px; font-size: 0.8rem; line-height: 1.4;">
                        <?php if($row['current_location'] && $row['current_location'] !== 'Unknown Location'){ ?>
                            <div style="font-weight: 600; color: var(--accent);">📍 <?php echo htmlspecialchars(explode(',', $row['current_location'])[0]); ?></div>
                            <div style="color: var(--text-muted); font-size: 0.75rem; margin-top: 2px;">
                                Coords: <?php echo htmlspecialchars($row['current_latitude'] ? $row['current_latitude'] : '0.0'); ?>, <?php echo htmlspecialchars($row['current_longitude'] ? $row['current_longitude'] : '0.0'); ?>
                            </div>
                        <?php } else { ?>
                            <span class="badge danger" style="font-size: 0.7rem;">No Location</span>
                        <?php } ?>
                    </td>
                </tr>
            <?php }
            } else { ?>
                <tr>
                    <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 30px;">
                        No workday shifts<?php echo $dateFilterActive ? ' in the selected date range' : ''; ?>.
                    </td>
                </tr>
            <?php } ?>
        </table>
    </div>
</div>
