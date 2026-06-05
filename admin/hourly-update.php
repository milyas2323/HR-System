<?php
include "../includes/db.php";
include "../includes/auth.php";
include "../includes/functions.php";

if($_SESSION['user']['role'] != 'admin'){
    exit("Access Denied");
}

$date_from_input = trim($_GET['date_from'] ?? '');
$date_to_input = trim($_GET['date_to'] ?? '');
$dateFilterActive = false;
$dateFilterError = '';
$date_from = '';
$date_to = '';

if ($date_from_input !== '' || $date_to_input !== '') {
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
        }
    }
}

function hourlyUpdateDateBetweenClause($conn, $column, $from, $to) {
    $from = mysqli_real_escape_string($conn, $from);
    $to = mysqli_real_escape_string($conn, $to);
    return "DATE($column) BETWEEN '$from' AND '$to'";
}

$dateClauseHourly = $dateFilterActive
    ? ' WHERE ' . hourlyUpdateDateBetweenClause($conn, 'h.created_at', $date_from, $date_to)
    : '';

$dateClauseHourlyBare = $dateFilterActive
    ? ' WHERE ' . hourlyUpdateDateBetweenClause($conn, 'created_at', $date_from, $date_to)
    : '';

/* =========================
   RESET HOURLY UPDATES
   ========================= */
if(isset($_POST['reset_updates'])){
    $conn->query("DELETE FROM hourly_updates");
    $_SESSION['msg'] = "Hourly updates log reset successfully!";
    echo "<script>window.location.href='dashboard.php?page=hourly-update';</script>";
    exit();
}

/* SUMMARY DETAILS */
$totalUpdates = $conn->query("SELECT COUNT(*) as total FROM hourly_updates" . $dateClauseHourlyBare)->fetch_assoc();
$totalEmployees = $conn->query("SELECT COUNT(*) as total FROM users WHERE role='employee'")->fetch_assoc();
$totalActiveShifts = $conn->query("SELECT COUNT(*) as total FROM shifts WHERE status='active'")->fetch_assoc();

$employeesInRange = null;
if ($dateFilterActive) {
    $employeesInRange = $conn->query("
        SELECT COUNT(DISTINCT employee_id) as total
        FROM hourly_updates
        $dateClauseHourlyBare
    ")->fetch_assoc();
}
?>

<div class="page-title">Hourly Progress Logs Monitor</div>

<!-- DATE RANGE FILTER -->
<div class="card">
    <form method="GET" action="dashboard.php">
        <input type="hidden" name="page" value="hourly-update">

        <div class="form-group" style="margin-bottom: 0;">
            <label>Filter by date range</label>
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
                <?php if ($dateFilterActive || $date_from_input !== '' || $date_to_input !== '') { ?>
                    <a href="dashboard.php?page=hourly-update" class="btn-secondary" style="display: inline-flex; align-items: center; padding: 10px 16px; text-decoration: none; margin-bottom: 0;">
                        Clear dates
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
            <span>Showing hourly updates from <strong><?php echo date('d M Y', strtotime($date_from)); ?></strong> to <strong><?php echo date('d M Y', strtotime($date_to)); ?></strong>.</span>
        </div>
    <?php } ?>
</div>

<!-- SYSTEM ACTIONS -->
<div style="display: flex; justify-content: flex-end; margin-bottom: 20px;">
    <form method="POST" onsubmit="return confirm('WARNING: Are you sure you want to permanently clear ALL employee hourly updates? This is irreversible!');">
        <button type="submit" name="reset_updates" class="btn-danger">
            ⚠️ Clear All Hourly Updates
        </button>
    </form>
</div>

<!-- STATS SUMMARY GRID -->
<div class="grid">
    <div class="card stat-box">
        <h4>Updates Logged<?php echo $dateFilterActive ? ' (in range)' : ''; ?></h4>
        <h2><?php echo $totalUpdates['total'] ?? 0; ?></h2>
    </div>

    <div class="card stat-box">
        <h4>Active Shifts Now</h4>
        <h2><?php echo $totalActiveShifts['total'] ?? 0; ?></h2>
    </div>

    <div class="card stat-box">
        <?php if ($dateFilterActive) { ?>
            <h4>Employees With Logs (in range)</h4>
            <h2><?php echo $employeesInRange['total'] ?? 0; ?></h2>
        <?php } else { ?>
            <h4>Employees Monitored</h4>
            <h2><?php echo $totalEmployees['total'] ?? 0; ?></h2>
        <?php } ?>
    </div>
</div>

<!-- DATA FEED TABLE -->
<div class="card">
    <h2>Progress Feed<?php echo $dateFilterActive ? ' — filtered' : ''; ?></h2>

    <div class="table-box">
        <table>
            <tr>
                <th>Employee Name</th>
                <th>Slot window</th>
                <th>Hourly Logged Work Activity</th>
                <th>Time Submitted</th>
                <th>Date Logged</th>
            </tr>
            <?php
            $data = $conn->query("
                SELECT h.*, u.name
                FROM hourly_updates h
                JOIN users u ON u.id = h.employee_id
                $dateClauseHourly
                ORDER BY h.created_at DESC
            ");

            if($data && $data->num_rows > 0){
                while($row = $data->fetch_assoc()){
                    $timestamp = strtotime($row['created_at']);
                    $slotLabel = '—';
                    if (!empty($row['slot_date']) && $row['slot_hour'] !== null && $row['slot_hour'] !== '') {
                        $slotLabel = formatHourlySlotLabel($row['slot_date'], $row['slot_hour']);
                    }
            ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                    <td style="font-size: 0.85rem; color: var(--accent); white-space: nowrap;"><?php echo htmlspecialchars($slotLabel); ?></td>

                    <td style="max-width: 450px; line-height: 1.6; word-wrap: break-word; color: var(--text-main);">
                        <?php echo nl2br(htmlspecialchars($row['update_text'])); ?>
                    </td>

                    <td style="color: var(--primary); font-weight: 600;">
                        ⏰ <?php echo date('h:i A', $timestamp); ?>
                    </td>

                    <td style="color: var(--text-muted); font-size: 0.85rem;">
                        📅 <?php echo date('d M Y', $timestamp); ?>
                    </td>
                </tr>
            <?php }
            } else { ?>
                <tr>
                    <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px;">
                        No hourly updates<?php echo $dateFilterActive ? ' in the selected date range' : ''; ?>.
                    </td>
                </tr>
            <?php } ?>
        </table>
    </div>
</div>
