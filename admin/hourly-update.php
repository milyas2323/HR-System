<?php
include_once "../includes/db.php";
include_once "../includes/auth.php";
include_once "../includes/functions.php";

if($_SESSION['user']['role'] != 'admin'){
    exit("Access Denied");
}

$today = date('Y-m-d');
$currentMonthStart = date('Y-m-01');
$lastMonthStart = date('Y-m-01', strtotime('first day of last month'));
$lastMonthEnd = date('Y-m-t', strtotime('last day of last month'));

$employee_id = isset($_GET['employee_id']) ? (int) $_GET['employee_id'] : 0;
if ($employee_id < 0) {
    $employee_id = 0;
}

$employees = [];
$empResult = $conn->query("SELECT id, name FROM users WHERE role='employee' ORDER BY name ASC");
if ($empResult) {
    while ($row = $empResult->fetch_assoc()) {
        $employees[] = $row;
    }
}

$date_from_input = trim($_GET['date_from'] ?? '');
$date_to_input = trim($_GET['date_to'] ?? '');
$dateFilterActive = false;
$dateFilterDefault = false;
$dateFilterError = '';
$date_from = '';
$date_to = '';

if ($date_from_input === '' && $date_to_input === '') {
    $dateFilterActive = true;
    $dateFilterDefault = true;
    $date_from = $currentMonthStart;
    $date_to = $today;
    $date_from_input = $currentMonthStart;
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
            $dateFilterDefault = ($date_from === $currentMonthStart && $date_to === $today);
        }
    }
}

$applyDateFilter = $dateFilterActive && $dateFilterError === '';
$isCurrentMonth = ($date_from === $currentMonthStart && $date_to === $today);
$isLastMonth = ($date_from === $lastMonthStart && $date_to === $lastMonthEnd);

function hourlyUpdateDateBetweenClause($conn, $column, $from, $to) {
    $from = mysqli_real_escape_string($conn, $from);
    $to = mysqli_real_escape_string($conn, $to);
    return "DATE($column) BETWEEN '$from' AND '$to'";
}

function hourlyUpdateBuildWhere($conn, $applyDateFilter, $dateFrom, $dateTo, $employeeId, $dateColumn = 'h.created_at', $employeeColumn = 'h.employee_id') {
    $parts = [];
    if ($applyDateFilter) {
        $parts[] = hourlyUpdateDateBetweenClause($conn, $dateColumn, $dateFrom, $dateTo);
    }
    if ($employeeId > 0) {
        $parts[] = $employeeColumn . " = '" . (int) $employeeId . "'";
    }
    return count($parts) ? ' WHERE ' . implode(' AND ', $parts) : '';
}

function hourlyUpdateSystemDevice($row) {
    $device = trim((string) ($row['sys_device'] ?? ''));
    return $device !== '' ? $device : '—';
}

function hourlyUpdateSystemIp($row) {
    $ip = trim((string) ($row['sys_ip'] ?? ''));
    return $ip !== '' ? $ip : '—';
}

function hourlyUpdateSystemLocation($row) {
    $location = trim((string) ($row['sys_location'] ?? ''));
    if ($location === '' || $location === 'Unknown Location') {
        return '—';
    }
    return $location;
}

function hourlyUpdateSystemLocationPreview($row) {
    $location = hourlyUpdateSystemLocation($row);
    if ($location === '—') {
        return '—';
    }
    $parts = explode(',', $location);
    return trim($parts[0]);
}

$whereHourly = hourlyUpdateBuildWhere($conn, $applyDateFilter, $date_from, $date_to, $employee_id);
$whereHourlyBare = hourlyUpdateBuildWhere($conn, $applyDateFilter, $date_from, $date_to, $employee_id, 'created_at', 'employee_id');

$selectedEmployeeName = '';
if ($employee_id > 0) {
    foreach ($employees as $emp) {
        if ((int) $emp['id'] === $employee_id) {
            $selectedEmployeeName = $emp['name'];
            break;
        }
    }
}

/* SUMMARY DETAILS */
$totalUpdates = $conn->query("SELECT COUNT(*) as total FROM hourly_updates" . $whereHourlyBare)->fetch_assoc();
$totalEmployees = $conn->query("SELECT COUNT(*) as total FROM users WHERE role='employee'")->fetch_assoc();
$totalActiveShifts = $conn->query("SELECT COUNT(*) as total FROM shifts WHERE status='active'")->fetch_assoc();

$employeesInRange = null;
if ($applyDateFilter || $employee_id > 0) {
    $employeesInRange = $conn->query("
        SELECT COUNT(DISTINCT employee_id) as total
        FROM hourly_updates
        $whereHourlyBare
    ")->fetch_assoc();
}

$empQ = $employee_id > 0 ? '&amp;employee_id=' . $employee_id : '';
$hasCustomFilters = !$dateFilterDefault || $employee_id > 0;
?>

<div class="page-title">Hourly Progress Logs Monitor</div>

<!-- DATE RANGE FILTER -->
<div class="card">
    <form method="GET" action="dashboard.php">
        <input type="hidden" name="page" value="hourly-update">

        <div class="form-group" style="margin-bottom: 14px;">
            <label>Filter by date range <span style="color: var(--text-muted); font-weight: normal;">(defaults to current month)</span></label>
            <div style="display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;">
                <div>
                    <label style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 4px; display: block;">From</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from_input); ?>" style="max-width: 200px;">
                </div>
                <div>
                    <label style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 4px; display: block;">To</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to_input); ?>" style="max-width: 200px;">
                </div>
                <div>
                    <label style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 4px; display: block;">Employee</label>
                    <select name="employee_id" style="max-width: 220px; min-width: 180px;">
                        <option value="0">All employees</option>
                        <?php foreach ($employees as $emp) { ?>
                            <option value="<?php echo (int) $emp['id']; ?>" <?php echo $employee_id === (int) $emp['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($emp['name']); ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>
                <button type="submit" class="btn glowing-element" style="margin-bottom: 0;">Apply Filters</button>
                <?php if ($hasCustomFilters) { ?>
                    <a href="dashboard.php?page=hourly-update" class="btn-secondary" style="display: inline-flex; align-items: center; padding: 10px 16px; text-decoration: none; margin-bottom: 0;">
                        Reset filters
                    </a>
                <?php } ?>
            </div>
        </div>
    </form>

    <div style="display: flex; flex-wrap: wrap; gap: 8px;">
        <a href="dashboard.php?page=hourly-update&amp;date_from=<?php echo $currentMonthStart; ?>&amp;date_to=<?php echo $today; ?><?php echo $empQ; ?>" class="badge <?php echo $isCurrentMonth ? 'success' : 'warning'; ?>" style="text-decoration: none; padding: 8px 14px;">Current month</a>
        <a href="dashboard.php?page=hourly-update&amp;date_from=<?php echo $lastMonthStart; ?>&amp;date_to=<?php echo $lastMonthEnd; ?><?php echo $empQ; ?>" class="badge <?php echo $isLastMonth ? 'success' : 'warning'; ?>" style="text-decoration: none; padding: 8px 14px;">Last month</a>
    </div>

    <?php if ($dateFilterError !== '') { ?>
        <div class="alert danger" style="margin-top: 16px; margin-bottom: 0;">
            <span>⚠️</span>
            <span><?php echo htmlspecialchars($dateFilterError); ?></span>
        </div>
    <?php } elseif ($applyDateFilter) { ?>
        <div class="alert info" style="margin-top: 16px; margin-bottom: 0;">
            <span>ℹ️</span>
            <span>
                Showing hourly updates from <strong><?php echo date('d M Y', strtotime($date_from)); ?></strong> to <strong><?php echo date('d M Y', strtotime($date_to)); ?></strong>
                <?php if ($employee_id > 0 && $selectedEmployeeName !== '') { ?>
                    for <strong><?php echo htmlspecialchars($selectedEmployeeName); ?></strong>
                <?php } ?>
                <?php if ($dateFilterDefault && $employee_id === 0) { ?>
                    (current month default)
                <?php } ?>
                .
            </span>
        </div>
    <?php } ?>
</div>

<!-- STATS SUMMARY GRID -->
<div class="grid">
    <div class="card stat-box">
        <h4>Updates Logged<?php echo ($applyDateFilter || $employee_id > 0) ? ' (filtered)' : ''; ?></h4>
        <h2><?php echo $totalUpdates['total'] ?? 0; ?></h2>
    </div>

    <div class="card stat-box">
        <h4>Active Shifts Now</h4>
        <h2><?php echo $totalActiveShifts['total'] ?? 0; ?></h2>
    </div>

    <div class="card stat-box">
        <?php if ($applyDateFilter || $employee_id > 0) { ?>
            <h4>Employees With Logs (filtered)</h4>
            <h2><?php echo $employeesInRange['total'] ?? 0; ?></h2>
        <?php } else { ?>
            <h4>Employees Monitored</h4>
            <h2><?php echo $totalEmployees['total'] ?? 0; ?></h2>
        <?php } ?>
    </div>
</div>

<!-- DATA FEED TABLE -->
<div class="card">
    <h2>Progress Feed<?php echo ($applyDateFilter || $employee_id > 0) ? ' — filtered' : ''; ?></h2>

    <div class="table-box">
        <table>
            <tr>
                <th>Employee Name</th>
                <th>Slot window</th>
                <th>Hourly Logged Work Activity</th>
                <th>System / Device</th>
                <th>Location</th>
                <th>Time Submitted</th>
                <th>Date Logged</th>
            </tr>
            <?php
            $data = $conn->query("
                SELECT h.*, u.name,
                    NULLIF(TRIM(h.device), '') AS sys_device,
                    NULLIF(TRIM(h.ip_address), '') AS sys_ip,
                    NULLIF(TRIM(h.current_location), '') AS sys_location
                FROM hourly_updates h
                JOIN users u ON u.id = h.employee_id
                $whereHourly
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

                    <td style="font-size: 0.82rem; line-height: 1.5; min-width: 160px;">
                        <div style="font-weight: 600; color: var(--text-main);">
                            <?php echo htmlspecialchars(hourlyUpdateSystemDevice($row)); ?>
                        </div>
                        <div style="color: var(--text-muted); margin-top: 4px;">
                            IP: <strong style="color: var(--text-main);"><?php echo htmlspecialchars(hourlyUpdateSystemIp($row)); ?></strong>
                        </div>
                    </td>

                    <td style="font-size: 0.82rem; line-height: 1.5; max-width: 220px; word-wrap: break-word;">
                        <?php if (hourlyUpdateSystemLocation($row) !== '—') { ?>
                            <div style="font-weight: 600; color: var(--accent);">
                                📍 <?php echo htmlspecialchars(hourlyUpdateSystemLocationPreview($row)); ?>
                            </div>
                            <?php if (hourlyUpdateSystemLocation($row) !== hourlyUpdateSystemLocationPreview($row)) { ?>
                                <div style="color: var(--text-muted); margin-top: 4px; font-size: 0.75rem;">
                                    <?php echo htmlspecialchars(hourlyUpdateSystemLocation($row)); ?>
                                </div>
                            <?php } ?>
                        <?php } else { ?>
                            <span style="color: var(--text-muted);">—</span>
                        <?php } ?>
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
                    <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 30px;">
                        No hourly updates<?php echo ($applyDateFilter || $employee_id > 0) ? ' for the selected filters' : ''; ?>.
                    </td>
                </tr>
            <?php } ?>
        </table>
    </div>
</div>
