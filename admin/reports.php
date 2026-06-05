<?php
include "../includes/db.php";
include "../includes/auth.php";
include "../includes/functions.php";

if($_SESSION['user']['role'] != 'admin'){
    exit("Access Denied");
}

$employee_id = isset($_GET['employee_id']) ? (int) $_GET['employee_id'] : 0;
if ($employee_id < 0) {
    $employee_id = 0;
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

/**
 * Build a DATE(column) BETWEEN clause (dates must already be validated Y-m-d).
 */
function reportsDateBetweenClause($conn, $column, $from, $to) {
    $from = mysqli_real_escape_string($conn, $from);
    $to = mysqli_real_escape_string($conn, $to);
    return "DATE($column) BETWEEN '$from' AND '$to'";
}
?>

<div class="page-title">Workforce Audit Reports</div>

<!-- SELECT EMPLOYEE + DATE RANGE -->
<div class="card">
    <form method="GET" action="dashboard.php" id="reportSelectForm">
        <input type="hidden" name="page" value="reports">

        <div class="form-group">
            <label>Choose Employee to View Audits</label>
            <select name="employee_id" style="max-width: 400px;">
                <option value="">-- Choose Employee --</option>
                <?php
                $empsList = $conn->query("SELECT id, name, email FROM users WHERE role='employee' ORDER BY name ASC");
                while($e = $empsList->fetch_assoc()){
                    $eid = (int) $e['id'];
                ?>
                    <option value="<?php echo $eid; ?>" <?php if($employee_id === $eid) echo "selected"; ?>>
                        <?php echo htmlspecialchars($e['name']); ?> (<?php echo htmlspecialchars($e['email']); ?>)
                    </option>
                <?php } ?>
            </select>
        </div>

        <div class="form-group">
            <label>Report date range <span style="color: var(--text-muted); font-weight: normal;">(optional — applies after you select an employee)</span></label>
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
                <?php if ($employee_id > 0 && ($dateFilterActive || $date_from_input !== '' || $date_to_input !== '')) { ?>
                    <a href="dashboard.php?page=reports&amp;employee_id=<?php echo $employee_id; ?>" class="btn-secondary" style="display: inline-flex; align-items: center; padding: 10px 16px; text-decoration: none; margin-bottom: 0;">
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
            <span>Showing records from <strong><?php echo date('d M Y', strtotime($date_from)); ?></strong> to <strong><?php echo date('d M Y', strtotime($date_to)); ?></strong>.</span>
        </div>
    <?php } ?>
</div>

<!-- AUDIT RESULTS -->
<?php if($employee_id === 0){ ?>
    <div class="card">
        <p style="color: var(--text-muted); text-align: center; padding: 20px;">
            Please select an employee from the dropdown above to view their shift logs, hourly updates, and login history.
            You can optionally narrow results with a date range.
        </p>
    </div>
<?php } else {
    $empDetails = $conn->query("SELECT * FROM users WHERE id='$employee_id' AND role='employee'")->fetch_assoc();
    if(!$empDetails){
        echo '<div class="alert danger">Employee record not found.</div>';
    } else {
        $dbNowQuery = $conn->query("SELECT NOW() as db_now");
        $dbNow = ($dbNowQuery && $dbNowQuery->num_rows > 0) ? $dbNowQuery->fetch_assoc()['db_now'] : date('Y-m-d H:i:s');

        $dateClauseUpdates = $dateFilterActive ? ' AND ' . reportsDateBetweenClause($conn, 'created_at', $date_from, $date_to) : '';
        $dateClausePenalties = $dateFilterActive ? ' AND ' . reportsDateBetweenClause($conn, 'created_at', $date_from, $date_to) : '';
        $dateClauseShifts = $dateFilterActive ? ' AND ' . reportsDateBetweenClause($conn, 'start_time', $date_from, $date_to) : '';
        $dateClauseLogins = $dateFilterActive ? ' AND ' . reportsDateBetweenClause($conn, 'created_at', $date_from, $date_to) : '';

        $updatesCount = $conn->query("
            SELECT COUNT(*) as total FROM hourly_updates
            WHERE employee_id='$employee_id' $dateClauseUpdates
        ")->fetch_assoc();

        $penaltiesSum = $conn->query("
            SELECT SUM(amount) as total FROM penalties
            WHERE employee_id='$employee_id' $dateClausePenalties
        ")->fetch_assoc();

        $shiftActive = $conn->query("
            SELECT COUNT(*) as total FROM shifts
            WHERE employee_id='$employee_id' AND status='active'
        ")->fetch_assoc();

        $shiftLimit = $dateFilterActive ? '' : ' LIMIT 5';
        $loginLimit = $dateFilterActive ? '' : ' LIMIT 8';
        $hourlyLimit = $dateFilterActive ? '' : ' LIMIT 10';
?>

    <div class="card" style="background: rgba(6, 182, 212, 0.08); border-color: rgba(6, 182, 212, 0.2);">
        <h3 style="margin: 0; color: var(--accent); display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
            👤 Auditing Profile: <?php echo htmlspecialchars($empDetails['name']); ?> (<?php echo htmlspecialchars($empDetails['email']); ?>)
            <?php if ($dateFilterActive) { ?>
                <span style="font-size: 0.75rem; font-weight: 500; color: var(--text-muted);">
                    — <?php echo date('d M Y', strtotime($date_from)); ?> to <?php echo date('d M Y', strtotime($date_to)); ?>
                </span>
            <?php } ?>
        </h3>
    </div>

    <!-- METRICS GRID -->
    <div class="grid">
        <div class="card stat-box">
            <h4>Updates Logged<?php echo $dateFilterActive ? ' (in range)' : ''; ?></h4>
            <h2><?php echo $updatesCount['total'] ?? 0; ?></h2>
        </div>

        <div class="card stat-box" style="border-bottom: 4px solid var(--danger);">
            <h4>Deductions Fined<?php echo $dateFilterActive ? ' (in range)' : ''; ?></h4>
            <h2 style="color: var(--danger);">PKR <?php echo number_format($penaltiesSum['total'] ?? 0); ?></h2>
        </div>

        <div class="card stat-box" style="border-bottom: 4px solid var(--success);">
            <h4>Shift Active Now</h4>
            <h2 style="color: var(--success);"><?php echo ($shiftActive['total'] > 0) ? 'YES' : 'NO'; ?></h2>
        </div>
    </div>

    <!-- SHIFT HISTORY -->
    <div class="card">
        <h2>Shift History</h2>
        <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 20px;">
            Check-ins with clock times, duration, device, and location captured at shift start.
            <?php if (!$dateFilterActive) { ?>
                <em>(Showing latest 5 — use a date range to see all matching shifts.)</em>
            <?php } ?>
        </p>

        <?php
        $shiftsQuery = $conn->query("
            SELECT * FROM shifts
            WHERE employee_id='$employee_id' $dateClauseShifts
            ORDER BY start_time DESC
            $shiftLimit
        ");

        if ($shiftsQuery && $shiftsQuery->num_rows > 0) {
            while ($shiftRow = $shiftsQuery->fetch_assoc()) {
                $status = strtolower($shiftRow['status']);
                $startTimeStr = $shiftRow['start_time'];
                $endTimeStr = ($status === 'active') ? $dbNow : $shiftRow['end_time'];
                $startTimestamp = strtotime($startTimeStr);
                $endTimestamp = strtotime($endTimeStr);
                $elapsedSeconds = max(0, $endTimestamp - $startTimestamp);
                $elapsedHours = floor($elapsedSeconds / 3600);
                $elapsedMinutes = floor(($elapsedSeconds % 3600) / 60);
        ?>
                <div style="border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 20px; margin-bottom: 20px; background: rgba(255,255,255,0.01);">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 15px; margin-bottom: 12px;">
                        <div>
                            <span class="badge <?php echo ($status === 'active') ? 'success' : 'danger'; ?>" style="margin-bottom: 5px; display: inline-block;">
                                <?php echo strtoupper($status); ?> SHIFT
                            </span>
                            <div style="font-size: 0.95rem; font-weight: 600; margin-top: 5px;">
                                Clock In: <span style="color: var(--text-main);"><?php echo date('h:i A - d M Y', $startTimestamp); ?></span>
                            </div>
                            <?php if ($status !== 'active' && !empty($shiftRow['end_time'])) { ?>
                                <div style="font-size: 0.95rem; font-weight: 600; margin-top: 5px;">
                                    Clock Out: <span style="color: var(--text-main);"><?php echo date('h:i A - d M Y', $endTimestamp); ?></span>
                                </div>
                            <?php } ?>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-size: 0.85rem; color: var(--text-muted);">Duration</div>
                            <div style="font-size: 1.25rem; font-weight: 700; color: var(--accent);"><?php echo "{$elapsedHours}h {$elapsedMinutes}m"; ?></div>
                        </div>
                    </div>
                    <div style="font-size: 0.9rem; color: var(--text-muted); line-height: 1.6;">
                        <?php if (!empty($shiftRow['device'])) { ?>
                            <div>Device: <strong style="color: var(--text-main);"><?php echo htmlspecialchars($shiftRow['device']); ?></strong></div>
                        <?php } ?>
                        <?php if (!empty($shiftRow['ip_address'])) { ?>
                            <div>IP: <strong style="color: var(--text-main);"><?php echo htmlspecialchars($shiftRow['ip_address']); ?></strong></div>
                        <?php } ?>
                        <?php if (!empty($shiftRow['current_location'])) { ?>
                            <div>Location: <strong style="color: var(--text-main);"><?php echo htmlspecialchars($shiftRow['current_location']); ?></strong></div>
                        <?php } ?>
                        <?php if (!empty($shiftRow['morning_message'])) { ?>
                            <div style="margin-top: 8px;">Morning message: <?php echo htmlspecialchars($shiftRow['morning_message']); ?></div>
                        <?php } ?>
                    </div>
                    <?php if (!empty($shiftRow['screenshot'])) { ?>
                        <div style="margin-top: 15px;">
                            <img src="../uploads/screenshots/<?php echo htmlspecialchars($shiftRow['screenshot']); ?>" alt="Shift screenshot" style="max-width: 100%; max-height: 220px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.1);">
                        </div>
                    <?php } ?>
                </div>
        <?php
            }
        } else {
        ?>
            <div style="text-align: center; color: var(--text-muted); padding: 20px;">
                No shift history<?php echo $dateFilterActive ? ' in the selected date range' : ''; ?> for this employee.
            </div>
        <?php
        }
        ?>
    </div>

    <!-- LOGIN AUDIT HISTORY -->
    <div class="card">
        <h2>System Login Audit History</h2>
        <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 15px;">
            Device agents, coordinates, resolved locations, and IPs logged at sign-in.
            <?php if (!$dateFilterActive) { ?>
                <em>(Showing latest 8.)</em>
            <?php } ?>
        </p>

        <div class="table-box">
            <table>
                <tr>
                    <th>Sign-in Time</th>
                    <th>Network IP Address</th>
                    <th>Device Details (Browser / OS)</th>
                    <th>Resolved Physical Location</th>
                </tr>
                <?php
                $logins = $conn->query("
                    SELECT * FROM login_logs
                    WHERE user_id='$employee_id' $dateClauseLogins
                    ORDER BY created_at DESC
                    $loginLimit
                ");
                if($logins && $logins->num_rows > 0){
                    while($row = $logins->fetch_assoc()){
                ?>
                    <tr>
                        <td>📅 <?php echo date('d M Y - h:i A', strtotime($row['created_at'])); ?></td>
                        <td style="font-weight: 600; color: var(--accent);"><?php echo htmlspecialchars($row['ip_address']); ?></td>
                        <td><?php echo htmlspecialchars($row['device']); ?></td>
                        <td style="font-size: 0.85rem;">
                            📍 <?php echo htmlspecialchars($row['location']); ?>
                            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px;">
                                Coords: <?php echo htmlspecialchars($row['latitude']); ?>, <?php echo htmlspecialchars($row['longitude']); ?>
                            </div>
                        </td>
                    </tr>
                <?php }
                } else { ?>
                    <tr>
                        <td colspan="4" style="text-align: center; color: var(--text-muted); padding: 15px;">
                            No login logs<?php echo $dateFilterActive ? ' in the selected date range' : ''; ?>.
                        </td>
                    </tr>
                <?php } ?>
            </table>
        </div>
    </div>

    <!-- HOURLY PROGRESS UPDATES -->
    <div class="card">
        <h2>Hourly Progress Logs</h2>
        <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 15px;">
            <?php if (!$dateFilterActive) { ?>
                <em>Showing latest 10.</em>
            <?php } ?>
        </p>

        <div class="table-box">
            <table>
                <tr>
                    <th>Slot window</th>
                    <th>Time Submitted</th>
                    <th>Date Logged</th>
                    <th>Progress Log text</th>
                </tr>
                <?php
                $progressLogs = $conn->query("
                    SELECT * FROM hourly_updates
                    WHERE employee_id='$employee_id' $dateClauseUpdates
                    ORDER BY created_at DESC
                    $hourlyLimit
                ");
                if($progressLogs && $progressLogs->num_rows > 0){
                    while($row = $progressLogs->fetch_assoc()){
                        $ts = strtotime($row['created_at']);
                        $slotLabel = '—';
                        if (!empty($row['slot_date']) && $row['slot_hour'] !== null && $row['slot_hour'] !== '') {
                            $slotLabel = formatHourlySlotLabel($row['slot_date'], $row['slot_hour']);
                        }
                ?>
                    <tr>
                        <td style="font-size: 0.85rem; color: var(--accent); white-space: nowrap;"><?php echo htmlspecialchars($slotLabel); ?></td>
                        <td style="font-weight: 600; color: var(--primary);">⏰ <?php echo date('h:i A', $ts); ?></td>
                        <td style="color: var(--text-muted); font-size: 0.85rem;">📅 <?php echo date('d M Y', $ts); ?></td>
                        <td style="line-height: 1.5; color: var(--text-main); max-width: 500px; word-wrap: break-word;">
                            <?php echo nl2br(htmlspecialchars($row['update_text'])); ?>
                        </td>
                    </tr>
                <?php }
                } else { ?>
                    <tr>
                        <td colspan="4" style="text-align: center; color: var(--text-muted); padding: 15px;">
                            No progress logs<?php echo $dateFilterActive ? ' in the selected date range' : ''; ?>.
                        </td>
                    </tr>
                <?php } ?>
            </table>
        </div>
    </div>

<?php } } ?>
