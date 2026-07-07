<?php
include_once "../includes/db.php";
include_once "../includes/auth.php";
include_once "../includes/functions.php";

if($_SESSION['user']['role'] != 'admin'){
    exit("Access Denied");
}

$currentMonth = date('Y-m');

$employee_id = isset($_GET['employee_id']) ? (int) $_GET['employee_id'] : 0;
if ($employee_id < 0) {
    $employee_id = 0;
}

$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$lastMonthStart = date('Y-m-01', strtotime('first day of last month'));
$lastMonthEnd = date('Y-m-t', strtotime('last day of last month'));
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

$hourly_from_input = trim($_GET['hourly_from'] ?? '');
$hourly_to_input = trim($_GET['hourly_to'] ?? '');
$hourlyDateFilterActive = false;
$hourlyDateFilterDefault = false;
$hourlyDateFilterError = '';
$hourly_from = '';
$hourly_to = '';
$hourlyShowAll = isset($_GET['hourly_all']) && $_GET['hourly_all'] === '1';
$hourly_sort_input = strtolower(trim($_GET['hourly_sort'] ?? 'desc'));
$hourly_sort = ($hourly_sort_input === 'asc') ? 'asc' : 'desc';

if ($employee_id > 0) {
    if ($hourlyShowAll) {
        // Show all hourly logs (no date filter on history section).
    } elseif ($hourly_from_input === '' && $hourly_to_input === '') {
        $hourly_from = $yesterday;
        $hourly_to = $today;
        $hourly_from_input = $yesterday;
        $hourly_to_input = $today;
        $hourlyDateFilterActive = true;
        $hourlyDateFilterDefault = true;
    } elseif ($hourly_from_input === '' || $hourly_to_input === '') {
        $hourlyDateFilterError = 'Please select both a start date and an end date for hourly history.';
    } else {
        $hdf = DateTime::createFromFormat('Y-m-d', $hourly_from_input);
        $hdt = DateTime::createFromFormat('Y-m-d', $hourly_to_input);
        $hdfValid = $hdf && $hdf->format('Y-m-d') === $hourly_from_input;
        $hdtValid = $hdt && $hdt->format('Y-m-d') === $hourly_to_input;

        if (!$hdfValid || !$hdtValid) {
            $hourlyDateFilterError = 'Invalid hourly history date format. Use the date picker.';
        } elseif ($hdf > $hdt) {
            $hourlyDateFilterError = 'Hourly history start date cannot be after end date.';
        } else {
            $hourlyDateFilterActive = true;
            $hourly_from = $hourly_from_input;
            $hourly_to = $hourly_to_input;
        }
    }
}

function reportsDateBetweenClause($conn, $column, $from, $to) {
    $from = mysqli_real_escape_string($conn, $from);
    $to = mysqli_real_escape_string($conn, $to);
    return "DATE($column) BETWEEN '$from' AND '$to'";
}

$dbNowTs = getDatabaseNowTimestamp($conn);

/**
 * Fetch shifts for employee, optionally filtered by start date.
 */
function reportsFetchEmployeeShifts($conn, $employeeId, $dateFilterActive, $dateFrom, $dateTo) {
    $employeeId = (int) $employeeId;
    $dateClause = $dateFilterActive
        ? ' AND ' . reportsDateBetweenClause($conn, 'start_time', $dateFrom, $dateTo)
        : '';

    $shifts = [];
    $result = $conn->query("
        SELECT * FROM shifts
        WHERE employee_id='$employeeId' $dateClause
        ORDER BY start_time DESC
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $shifts[] = $row;
        }
    }
    return $shifts;
}

/**
 * Sum missed updates across shifts.
 */
function reportsCountMissedForShifts($conn, $employeeId, $shiftsList, $auditTimestamp) {
    $total = 0;
    foreach ($shiftsList as $shift) {
        $breakdown = getMissedUpdatesBreakdownForShift($conn, $employeeId, $shift, $auditTimestamp);
        $total += $breakdown['total'];
    }
    return $total;
}

function reportsDetailUrl($employeeId, $dateFrom = '', $dateTo = '', $hourlyFrom = '', $hourlyTo = '', $hourlyShowAll = false, $hourlySort = 'desc') {
    $url = 'dashboard.php?page=reports&employee_id=' . (int) $employeeId;
    if ($dateFrom !== '' && $dateTo !== '') {
        $url .= '&date_from=' . urlencode($dateFrom) . '&date_to=' . urlencode($dateTo);
    }
    if ($hourlyShowAll) {
        $url .= '&hourly_all=1';
    } elseif ($hourlyFrom !== '' && $hourlyTo !== '') {
        $url .= '&hourly_from=' . urlencode($hourlyFrom) . '&hourly_to=' . urlencode($hourlyTo);
    }
    if ($hourlySort === 'asc') {
        $url .= '&hourly_sort=asc';
    }
    return $url;
}

function reportsHourlyHistoryQuerySuffix($hourlyDateFilterActive, $hourlyFrom, $hourlyTo, $hourlyShowAll, $hourlyDateFilterDefault, $hourlySort) {
    if ($hourlyShowAll) {
        $suffix = '&hourly_all=1';
    } elseif ($hourlyDateFilterActive) {
        $suffix = '&hourly_from=' . urlencode($hourlyFrom) . '&hourly_to=' . urlencode($hourlyTo);
    } else {
        $suffix = '';
    }
    if ($hourlySort === 'asc') {
        $suffix .= '&hourly_sort=asc';
    }
    return $suffix;
}

function reportsPreserveQueryHiddenFields($employeeId, $dateFilterActive, $dateFrom, $dateTo, $hourlyDateFilterActive, $hourlyFrom, $hourlyTo, $hourlyShowAll, $hourlyDateFilterDefault, $hourlySort = 'desc') {
    if ($employeeId <= 0) {
        return;
    }
    if ($dateFilterActive) {
        echo '<input type="hidden" name="date_from" value="' . htmlspecialchars($dateFrom) . '">';
        echo '<input type="hidden" name="date_to" value="' . htmlspecialchars($dateTo) . '">';
    }
    if ($hourlyShowAll) {
        echo '<input type="hidden" name="hourly_all" value="1">';
    } elseif ($hourlyDateFilterActive && !$hourlyDateFilterDefault) {
        echo '<input type="hidden" name="hourly_from" value="' . htmlspecialchars($hourlyFrom) . '">';
        echo '<input type="hidden" name="hourly_to" value="' . htmlspecialchars($hourlyTo) . '">';
    }
    if ($hourlySort === 'asc') {
        echo '<input type="hidden" name="hourly_sort" value="asc">';
    }
}

function reportsGetPenaltySum($conn, $employeeId, $month = null) {
    $employeeId = (int) $employeeId;
    $sql = "SELECT COALESCE(SUM(amount), 0) AS total FROM penalties WHERE employee_id='$employeeId'";
    if ($month !== null) {
        $month = mysqli_real_escape_string($conn, $month);
        $sql .= " AND DATE_FORMAT(created_at, '%Y-%m')='$month'";
    }
    $row = $conn->query($sql)->fetch_assoc();
    return floatval($row['total'] ?? 0);
}
?>

<?php if (isset($_SESSION['msg'])) { ?>
    <div class="alert success" style="margin-bottom: 16px;">
        <span>✅</span>
        <span><?php echo htmlspecialchars($_SESSION['msg']); unset($_SESSION['msg']); ?></span>
    </div>
<?php } ?>

<div class="page-title">Workforce Performance Reports</div>

<!-- DATE RANGE + QUICK FILTERS -->
<div class="card">
    <form method="GET" action="dashboard.php">
        <input type="hidden" name="page" value="reports">
        <?php if ($employee_id > 0) { ?>
            <input type="hidden" name="employee_id" value="<?php echo $employee_id; ?>">
            <?php reportsPreserveQueryHiddenFields($employee_id, $dateFilterActive, $date_from, $date_to, $hourlyDateFilterActive, $hourly_from, $hourly_to, $hourlyShowAll, $hourlyDateFilterDefault, $hourly_sort); ?>
        <?php } ?>

        <div class="form-group">
            <label>Report date range <span style="color: var(--text-muted); font-weight: normal;">(optional — filters shifts &amp; missed updates)</span></label>
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
                    <a href="dashboard.php?page=reports<?php echo $employee_id > 0 ? '&amp;employee_id=' . $employee_id : ''; ?>" class="btn-secondary" style="display: inline-flex; align-items: center; padding: 10px 16px; text-decoration: none; margin-bottom: 0;">
                        Clear dates
                    </a>
                <?php } ?>
            </div>
        </div>
    </form>

    <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px;">
        <?php
        $empQ = $employee_id > 0 ? '&amp;employee_id=' . $employee_id : '';
        ?>
        <a href="dashboard.php?page=reports<?php echo $empQ; ?>&amp;date_from=<?php echo $yesterday; ?>&amp;date_to=<?php echo $yesterday; ?>" class="badge warning" style="text-decoration: none; padding: 8px 14px;">Yesterday</a>
        <a href="dashboard.php?page=reports<?php echo $empQ; ?>&amp;date_from=<?php echo date('Y-m-d', strtotime('-6 days')); ?>&amp;date_to=<?php echo $today; ?>" class="badge warning" style="text-decoration: none; padding: 8px 14px;">Last 7 days</a>
        <a href="dashboard.php?page=reports<?php echo $empQ; ?>&amp;date_from=<?php echo date('Y-m-01'); ?>&amp;date_to=<?php echo $today; ?>" class="badge warning" style="text-decoration: none; padding: 8px 14px;">This month</a>
        <a href="dashboard.php?page=reports<?php echo $empQ; ?>&amp;date_from=<?php echo $lastMonthStart; ?>&amp;date_to=<?php echo $lastMonthEnd; ?>" class="badge warning" style="text-decoration: none; padding: 8px 14px;">Last month</a>
    </div>

    <?php if ($dateFilterError !== '') { ?>
        <div class="alert danger" style="margin-top: 16px; margin-bottom: 0;">
            <span>⚠️</span>
            <span><?php echo htmlspecialchars($dateFilterError); ?></span>
        </div>
    <?php } elseif ($dateFilterActive) { ?>
        <div class="alert info" style="margin-top: 16px; margin-bottom: 0;">
            <span>ℹ️</span>
            <span>Filtering shifts, missed updates, penalties, and net salary from <strong><?php echo date('d M Y', strtotime($date_from)); ?></strong> to <strong><?php echo date('d M Y', strtotime($date_to)); ?></strong> using live calculations.</span>
        </div>
    <?php } ?>

    <form method="POST" action="dashboard.php?page=reports<?php echo $employee_id > 0 ? '&amp;employee_id=' . $employee_id : ''; ?>" style="margin-top: 16px;" onsubmit="return confirm('Recalculate all automated penalties? This removes old incorrect absence fines and rebuilds from corrected rules.');">
        <input type="hidden" name="recalculate_penalties" value="1">
        <?php if ($employee_id > 0) { ?>
            <input type="hidden" name="employee_id" value="<?php echo $employee_id; ?>">
        <?php } ?>
        <button type="submit" class="btn-secondary" style="font-size: 0.85rem;">↻ Recalculate automated penalties</button>
    </form>
</div>

<?php if ($employee_id === 0) {
    // ===================== ALL EMPLOYEES OVERVIEW =====================
    $employees = [];
    $empResult = $conn->query("SELECT id, name, email, salary FROM users WHERE role='employee' ORDER BY name ASC");
    if ($empResult) {
        while ($row = $empResult->fetch_assoc()) {
            $employees[] = $row;
        }
    }

    $overviewPenaltyFrom = ($dateFilterActive && $dateFilterError === '') ? $date_from : date('Y-m-01');
    $overviewPenaltyTo = ($dateFilterActive && $dateFilterError === '') ? $date_to : date('Y-m-d');
    $reportPeriodLabel = ($dateFilterActive && $dateFilterError === '')
        ? date('d M', strtotime($date_from)) . ' – ' . date('d M Y', strtotime($date_to))
        : date('M Y');
?>

<div class="card">
    <h2>All Employees — Performance Overview</h2>
    <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 20px;">
        Summary of shifts, missed hourly/end-report updates, penalties, and net salary. Click <strong>View Details</strong> for daily and monthly missed-update breakdown.
        <?php if ($dateFilterActive) { ?>
            Shifts and missed counts reflect the selected date range.
        <?php } else { ?>
            Shifts and missed counts are all-time.
        <?php } ?>
    </p>

    <div class="table-box">
        <table>
            <tr>
                <th>Employee</th>
                <th>Total Shifts</th>
                <th>Missed Updates</th>
                <th>Penalty (<?php echo htmlspecialchars($reportPeriodLabel); ?>)</th>
                <th>Net Salary (<?php echo htmlspecialchars($reportPeriodLabel); ?>)</th>
                <th>Action</th>
            </tr>
            <?php
            if (count($employees) > 0) {
                foreach ($employees as $emp) {
                    $eid = (int) $emp['id'];
                    $shifts = reportsFetchEmployeeShifts($conn, $eid, $dateFilterActive, $date_from, $date_to);
                    $totalShifts = count($shifts);
                    $penaltyData = calculateEmployeeDynamicPenalties($conn, $eid, $overviewPenaltyFrom, $overviewPenaltyTo, $dbNowTs);
                    $totalMissed = $penaltyData['missed_updates_total'];
                    $finedMissed = $penaltyData['missed_updates_fined_count'];
                    $monthPenalty = $penaltyData['total'];
                    $grossSalary = floatval($emp['salary']);
                    $netSalary = max(0, $grossSalary - $monthPenalty);
                    $detailUrl = reportsDetailUrl($eid, $dateFilterActive ? $date_from : '', $dateFilterActive ? $date_to : '');
            ?>
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars($emp['name']); ?></strong>
                        <div style="font-size: 0.8rem; color: var(--text-muted);"><?php echo htmlspecialchars($emp['email']); ?></div>
                    </td>
                    <td style="font-weight: 600;"><?php echo $totalShifts; ?></td>
                    <td>
                        <span class="badge <?php echo $totalMissed > 0 ? 'danger' : 'success'; ?>">
                            <?php echo $totalMissed; ?> missed
                        </span>
                        <?php if ($finedMissed > 0) { ?>
                            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 4px;">
                                <?php echo $finedMissed; ?> fined after 3 free/mo
                            </div>
                        <?php } ?>
                    </td>
                    <td style="color: var(--danger); font-weight: 600;">PKR <?php echo number_format($monthPenalty); ?></td>
                    <td>
                        <div style="font-weight: 700; color: var(--success);">PKR <?php echo number_format($netSalary); ?></div>
                        <div style="font-size: 0.75rem; color: var(--text-muted);">Gross <?php echo number_format($grossSalary); ?> − <?php echo htmlspecialchars($reportPeriodLabel); ?></div>
                    </td>
                    <td>
                        <a href="<?php echo htmlspecialchars($detailUrl); ?>" class="btn" style="padding: 8px 14px; font-size: 0.85rem; text-decoration: none;">
                            View Details →
                        </a>
                    </td>
                </tr>
            <?php }
            } else { ?>
                <tr>
                    <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 30px;">No employees found.</td>
                </tr>
            <?php } ?>
        </table>
    </div>
</div>

<?php } else {
    // ===================== SINGLE EMPLOYEE DETAIL =====================
    $empDetails = $conn->query("SELECT * FROM users WHERE id='$employee_id' AND role='employee'")->fetch_assoc();
    if (!$empDetails) {
        echo '<div class="alert danger">Employee record not found.</div>';
    } else {
        $dbNowQuery = $conn->query("SELECT NOW() as db_now");
        $dbNow = ($dbNowQuery && $dbNowQuery->num_rows > 0) ? $dbNowQuery->fetch_assoc()['db_now'] : date('Y-m-d H:i:s');

        $employeeShifts = reportsFetchEmployeeShifts($conn, $employee_id, $dateFilterActive, $date_from, $date_to);
        $missedReport = buildEmployeeMissedUpdatesReport($conn, $employee_id, $employeeShifts, $dbNowTs);
        $missedCounts = countBillableMissedUpdatesForShifts($conn, $employee_id, $employeeShifts, $dbNowTs);

        $detailPenaltyFrom = ($dateFilterActive && $dateFilterError === '') ? $date_from : date('Y-m-01');
        $detailPenaltyTo = ($dateFilterActive && $dateFilterError === '') ? $date_to : date('Y-m-d');
        $detailPeriodLabel = ($dateFilterActive && $dateFilterError === '')
            ? date('d M', strtotime($date_from)) . ' – ' . date('d M Y', strtotime($date_to))
            : date('M Y');

        $penaltyReport = buildEmployeePenaltyReportRows($conn, $employee_id, $detailPenaltyFrom, $detailPenaltyTo, $dbNowTs);
        $penaltyRows = $penaltyReport['rows'];
        $penaltyBreakdown = $penaltyReport['breakdown'];
        $penaltyBreakdownTotal = $penaltyReport['total'];
        $detailPenaltyData = $penaltyReport['dynamic'];

        $totalPenaltyAllTime = reportsGetPenaltySum($conn, $employee_id);
        $totalPenaltyPeriod = $detailPenaltyData['total'];
        $grossSalary = floatval($empDetails['salary']);
        $netSalary = max(0, $grossSalary - $totalPenaltyPeriod);

        $billablePeriodMissed = $detailPenaltyData['missed_updates_total'];
        $finedMissedCount = $detailPenaltyData['missed_updates_fined_count'];
        $expectedMissedFine = $detailPenaltyData['missed_updates_fine'];

        $currentMonthShifts = reportsFetchEmployeeShifts($conn, $employee_id, true, date('Y-m-01'), date('Y-m-t'));
        $currentMonthMissedCounts = countBillableMissedUpdatesForShifts($conn, $employee_id, $currentMonthShifts, $dbNowTs);
        $pendingMonthMissed = $currentMonthMissedCounts['pending'];

        $shiftActive = $conn->query("
            SELECT COUNT(*) as total FROM shifts
            WHERE employee_id='$employee_id' AND status='active'
        ")->fetch_assoc();

        $dateClauseLogins = $dateFilterActive ? ' AND ' . reportsDateBetweenClause($conn, 'created_at', $date_from, $date_to) : '';
        $loginLimit = $dateFilterActive ? '' : ' LIMIT 8';

        // Monthly penalties for monthly missed summary (live calculation per month)
        $penaltiesByMonth = [];
        foreach ($detailPenaltyData['by_month'] as $monthKey => $monthData) {
            $penaltiesByMonth[$monthKey] = $monthData['absence_fine'] + $monthData['missed_updates_fine'] + $monthData['manual_fine'];
        }

        $hourlyHistoryRows = [];
        $hourlyHistoryCount = 0;
        $hourlyDateClause = '';
        if ($hourlyDateFilterActive) {
            $hourlyDateClause = ' AND ' . reportsDateBetweenClause($conn, 'h.created_at', $hourly_from, $hourly_to);
        }
        $hourlyOrderDir = ($hourly_sort === 'asc') ? 'ASC' : 'DESC';
        $hourlyHistoryResult = $conn->query("
            SELECT h.*, s.start_time AS shift_start
            FROM hourly_updates h
            LEFT JOIN shifts s ON s.id = h.shift_id
            WHERE h.employee_id='$employee_id' $hourlyDateClause
            ORDER BY h.created_at $hourlyOrderDir, h.id $hourlyOrderDir
        ");
        if ($hourlyHistoryResult) {
            $hourlyHistoryCount = $hourlyHistoryResult->num_rows;
            while ($row = $hourlyHistoryResult->fetch_assoc()) {
                $hourlyHistoryRows[] = $row;
            }
        }

        $hourlyHistoryBaseUrl = 'dashboard.php?page=reports&employee_id=' . $employee_id;
        if ($dateFilterActive) {
            $hourlyHistoryBaseUrl .= '&date_from=' . urlencode($date_from) . '&date_to=' . urlencode($date_to);
        }
        $hourlySortToggle = ($hourly_sort === 'asc') ? 'desc' : 'asc';
        $hourlySortToggleUrl = $hourlyHistoryBaseUrl . reportsHourlyHistoryQuerySuffix(
            $hourlyDateFilterActive,
            $hourly_from,
            $hourly_to,
            $hourlyShowAll,
            $hourlyDateFilterDefault,
            $hourlySortToggle
        );
?>

    <div style="margin-bottom: 16px;">
        <a href="dashboard.php?page=reports<?php echo $dateFilterActive ? '&amp;date_from=' . urlencode($date_from) . '&amp;date_to=' . urlencode($date_to) : ''; ?>" class="btn-secondary" style="display: inline-flex; align-items: center; padding: 10px 16px; text-decoration: none;">
            ← Back to All Employees
        </a>
    </div>

    <div class="card" style="background: rgba(6, 182, 212, 0.08); border-color: rgba(6, 182, 212, 0.2);">
        <h3 style="margin: 0; color: var(--accent); display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
            👤 <?php echo htmlspecialchars($empDetails['name']); ?>
            <span style="font-size: 0.85rem; font-weight: 400; color: var(--text-muted);"><?php echo htmlspecialchars($empDetails['email']); ?></span>
            <?php if ($dateFilterActive) { ?>
                <span style="font-size: 0.75rem; font-weight: 500; color: var(--text-muted);">
                    — <?php echo date('d M Y', strtotime($date_from)); ?> to <?php echo date('d M Y', strtotime($date_to)); ?>
                </span>
            <?php } ?>
        </h3>
    </div>

    <!-- METRICS -->
    <div class="grid">
        <div class="card stat-box">
            <h4>Total Shifts<?php echo $dateFilterActive ? ' (in range)' : ''; ?></h4>
            <h2><?php echo count($employeeShifts); ?></h2>
        </div>

        <div class="card stat-box" style="border-bottom: 4px solid var(--danger);">
            <h4>Missed Updates<?php echo $dateFilterActive ? ' (in range)' : ''; ?></h4>
            <h2 style="color: var(--danger);"><?php echo $missedCounts['billable']; ?></h2>
            <p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 6px;">
                Billable (fined)
                <?php if ($missedCounts['pending'] > 0) { ?>
                    · <?php echo $missedCounts['pending']; ?> pending open shift(s)
                <?php } ?>
            </p>
        </div>

        <div class="card stat-box" style="border-bottom: 4px solid var(--danger);">
            <h4>Penalty (<?php echo htmlspecialchars($detailPeriodLabel); ?>)</h4>
            <h2 style="color: var(--danger);">PKR <?php echo number_format($totalPenaltyPeriod); ?></h2>
            <p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 6px;">DB all-time: PKR <?php echo number_format($totalPenaltyAllTime); ?></p>
        </div>

        <div class="card stat-box" style="border-bottom: 4px solid var(--success);">
            <h4>Net Salary (<?php echo htmlspecialchars($detailPeriodLabel); ?>)</h4>
            <h2 style="color: var(--success);">PKR <?php echo number_format($netSalary); ?></h2>
            <p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 6px;">Gross PKR <?php echo number_format($grossSalary); ?> − <?php echo htmlspecialchars($detailPeriodLabel); ?> penalties</p>
        </div>
    </div>

    <?php if (($shiftActive['total'] ?? 0) > 0) { ?>
        <div class="alert success" style="margin-bottom: 20px;">
            <span>✓</span>
            <span>This employee currently has an <strong>active shift</strong>. Open slot windows are not counted as missed yet.</span>
        </div>
    <?php } ?>

    <!-- PENALTY BREAKDOWN (separate from missed-update count) -->
    <div class="card" style="border-color: rgba(239, 68, 68, 0.25);">
        <h2>Penalty Breakdown</h2>
        <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 16px;">
            Penalties below are computed <strong>live</strong> from shifts, missed slots, end reports, approved leaves, and relaxations.
            Absences are charged PKR 5,000 per weekday <em>after the employee’s first clock-in</em> (not before join).
            Missed-update fines: 3 free/month, then PKR 1,000 each.
        </p>

        <?php if ($pendingMonthMissed > 0) { ?>
            <div class="alert warning" style="margin-bottom: 16px;">
                <span>⚠️</span>
                <span><strong><?php echo $pendingMonthMissed; ?></strong> missed update(s) on still-open shifts are shown in the daily log but <strong>not fined yet</strong> until the shift ends.</span>
            </div>
        <?php } ?>

        <div class="alert info" style="margin-bottom: 20px;">
            <span>ℹ️</span>
            <span>
                <strong><?php echo htmlspecialchars($detailPeriodLabel); ?>:</strong>
                <?php echo $billablePeriodMissed; ?> total missed update(s)
                (<?php echo $finedMissedCount; ?> fined after 3 free) →
                fine <strong>PKR <?php echo number_format($expectedMissedFine); ?></strong>
                (live total penalties in range: <strong>PKR <?php echo number_format($totalPenaltyPeriod); ?></strong>).
                Values refresh every page load.
            </span>
        </div>

        <div class="grid" style="margin-bottom: 24px;">
            <?php foreach ($penaltyBreakdown as $key => $group) { ?>
                <div class="card stat-box" style="margin-bottom: 0; border-bottom: 4px solid var(--danger);">
                    <h4><?php echo htmlspecialchars($group['label']); ?></h4>
                    <h2 style="color: var(--danger); font-size: 1.5rem;">PKR <?php echo number_format($group['total']); ?></h2>
                    <p style="font-size: 0.8rem; color: var(--text-muted); margin-top: 8px;">
                        <?php echo (int) $group['count']; ?> record<?php echo $group['count'] === 1 ? '' : 's'; ?>
                        · <?php echo htmlspecialchars($group['description']); ?>
                    </p>
                </div>
            <?php } ?>
            <div class="card stat-box" style="margin-bottom: 0; background: rgba(239, 68, 68, 0.08);">
                <h4>Total<?php echo $dateFilterActive ? ' (in range)' : ' (all-time)'; ?></h4>
                <h2 style="color: var(--danger);">PKR <?php echo number_format($penaltyBreakdownTotal); ?></h2>
            </div>
        </div>

        <div class="table-box">
            <table>
                <tr>
                    <th>Date</th>
                    <th>Category</th>
                    <th>Reason / Detail</th>
                    <th>Amount</th>
                </tr>
                <?php if (count($penaltyRows) > 0) {
                    foreach ($penaltyRows as $pRow) {
                        $pType = $pRow['type'];
                ?>
                    <tr>
                        <td style="white-space: nowrap; font-size: 0.85rem;">
                            <?php echo date('d M Y', strtotime($pRow['created_at'])); ?>
                            <div style="color: var(--text-muted); font-size: 0.75rem;"><?php echo date('h:i A', strtotime($pRow['created_at'])); ?></div>
                        </td>
                        <td>
                            <span class="badge <?php echo htmlspecialchars($pType['badge']); ?>">
                                <?php echo htmlspecialchars($pType['label']); ?>
                            </span>
                        </td>
                        <td style="max-width: 400px; line-height: 1.5; word-wrap: break-word;">
                            <?php echo htmlspecialchars($pRow['reason']); ?>
                        </td>
                        <td style="color: var(--danger); font-weight: 700; white-space: nowrap;">
                            PKR <?php echo number_format(floatval($pRow['amount'])); ?>
                        </td>
                    </tr>
                <?php }
                } else { ?>
                    <tr>
                        <td colspan="4" style="text-align: center; color: var(--text-muted); padding: 24px;">
                            No penalty records<?php echo $dateFilterActive ? ' in the selected date range' : ''; ?>.
                        </td>
                    </tr>
                <?php } ?>
            </table>
        </div>
    </div>

    <!-- MISSED UPDATES — MONTHLY -->
    <div class="card">
        <h2>Missed Updates — Monthly Summary</h2>
        <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 15px;">
            Hourly slots (7× :00–:15 windows) and end-of-day reports. Matches penalty engine rules.
        </p>
        <div class="table-box">
            <table>
                <tr>
                    <th>Month</th>
                    <th>Shifts</th>
                    <th>Missed Hourly Slots</th>
                    <th>Missed End Reports</th>
                    <th>Total Missed</th>
                    <th>Penalties (month)</th>
                </tr>
                <?php if (count($missedReport['monthly']) > 0) {
                    foreach ($missedReport['monthly'] as $monthRow) {
                        $monthPenalty = $penaltiesByMonth[$monthRow['month']] ?? 0;
                ?>
                    <tr>
                        <td><strong><?php echo date('F Y', strtotime($monthRow['month'] . '-01')); ?></strong></td>
                        <td><?php echo $monthRow['shifts']; ?></td>
                        <td><span class="badge <?php echo $monthRow['hourly_missed'] > 0 ? 'danger' : 'success'; ?>"><?php echo $monthRow['hourly_missed']; ?></span></td>
                        <td><span class="badge <?php echo $monthRow['summary_missed'] > 0 ? 'danger' : 'success'; ?>"><?php echo $monthRow['summary_missed']; ?></span></td>
                        <td><strong style="color: var(--danger);"><?php echo $monthRow['total_missed']; ?></strong></td>
                        <td style="color: var(--danger);">PKR <?php echo number_format($monthPenalty); ?></td>
                    </tr>
                <?php }
                } else { ?>
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 20px;">No shift data<?php echo $dateFilterActive ? ' in selected range' : ''; ?>.</td>
                    </tr>
                <?php } ?>
            </table>
        </div>
    </div>

    <!-- MISSED UPDATES — DAILY -->
    <div class="card">
        <h2>Missed Updates — Daily Breakdown</h2>
        <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 15px;">
            Per workday shift: which 15-minute windows were missed and whether the end report was submitted.
            Use <strong>Grant relaxation</strong> to auto-credit missed hourly slots (admin placeholder logs).
            <?php if ($dateFilterActive) { ?>
                Use <strong>Yesterday</strong> above to audit the previous day quickly.
            <?php } ?>
        </p>
        <div class="table-box">
            <table>
                <tr>
                    <th>Workday</th>
                    <th>Clock In</th>
                    <th>Shift</th>
                    <th>Hourly (filled / 7)</th>
                    <th>Missed Slots</th>
                    <th>End Report</th>
                    <th>Total Missed</th>
                    <th>Action</th>
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
                            <?php echo $dayRow['hourly_filled']; ?> / <?php echo $dayRow['hourly_required']; ?>
                            <?php if ($dayRow['hourly_missed'] > 0) { ?>
                                <span class="badge danger" style="margin-left: 4px;"><?php echo $dayRow['hourly_missed']; ?> missed</span>
                            <?php } ?>
                        </td>
                        <td style="font-size: 0.8rem; max-width: 280px; line-height: 1.5;">
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
                        <td><strong style="color: <?php echo $dayRow['total_missed'] > 0 ? 'var(--danger)' : 'var(--success)'; ?>;"><?php echo $dayRow['total_missed']; ?></strong></td>
                        <td>
                            <?php if ($dayRow['hourly_missed'] > 0) { ?>
                                <form method="POST" action="dashboard.php?page=reports&amp;employee_id=<?php echo $employee_id; ?>" style="margin: 0;" onsubmit="return confirm('Credit <?php echo (int) $dayRow['hourly_missed']; ?> missed hourly slot(s) for <?php echo date('d M Y', strtotime($dayRow['date'])); ?>? This adds grandfathered placeholder logs.');">
                                    <input type="hidden" name="grant_hourly_relaxation" value="1">
                                    <input type="hidden" name="employee_id" value="<?php echo $employee_id; ?>">
                                    <input type="hidden" name="shift_id" value="<?php echo (int) $dayRow['shift_id']; ?>">
                                    <?php if ($dateFilterActive) { ?>
                                        <input type="hidden" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                                        <input type="hidden" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                                    <?php } ?>
                                    <?php if ($hourlyShowAll) { ?>
                                        <input type="hidden" name="hourly_all" value="1">
                                    <?php } elseif ($hourlyDateFilterActive) { ?>
                                        <input type="hidden" name="hourly_from" value="<?php echo htmlspecialchars($hourly_from); ?>">
                                        <input type="hidden" name="hourly_to" value="<?php echo htmlspecialchars($hourly_to); ?>">
                                    <?php } ?>
                                    <?php if ($hourly_sort === 'asc') { ?>
                                        <input type="hidden" name="hourly_sort" value="asc">
                                    <?php } ?>
                                    <button type="submit" class="btn-secondary" style="padding: 6px 10px; font-size: 0.75rem; white-space: nowrap;">
                                        Grant relaxation
                                    </button>
                                </form>
                            <?php } else { ?>
                                <span style="color: var(--text-muted); font-size: 0.8rem;">—</span>
                            <?php } ?>
                        </td>
                    </tr>
                <?php }
                } else { ?>
                    <tr>
                        <td colspan="8" style="text-align: center; color: var(--text-muted); padding: 20px;">
                            No shifts<?php echo $dateFilterActive ? ' in the selected date range' : ''; ?>.
                        </td>
                    </tr>
                <?php } ?>
            </table>
        </div>
    </div>

    <!-- HOURLY UPDATES HISTORY -->
    <div class="card">
        <h2>Hourly Updates History</h2>
        <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 16px;">
            Submitted hourly logs for confirmation. Defaults to <strong>yesterday and today</strong>; use the range below to audit other days.
        </p>

        <form method="GET" action="dashboard.php" style="margin-bottom: 16px;">
            <input type="hidden" name="page" value="reports">
            <input type="hidden" name="employee_id" value="<?php echo $employee_id; ?>">
            <?php if ($dateFilterActive) { ?>
                <input type="hidden" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                <input type="hidden" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
            <?php } ?>
            <div style="display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;">
                <div>
                    <label style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 4px; display: block;">From</label>
                    <input type="date" name="hourly_from" value="<?php echo htmlspecialchars($hourly_from_input); ?>" style="max-width: 200px;">
                </div>
                <div>
                    <label style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 4px; display: block;">To</label>
                    <input type="date" name="hourly_to" value="<?php echo htmlspecialchars($hourly_to_input); ?>" style="max-width: 200px;">
                </div>
                <div>
                    <label style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 4px; display: block;">Sort by submitted</label>
                    <select name="hourly_sort" style="max-width: 200px; padding: 10px 12px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.12); background: rgba(0,0,0,0.2); color: var(--text-main);">
                        <option value="desc"<?php echo $hourly_sort === 'desc' ? ' selected' : ''; ?>>Newest first</option>
                        <option value="asc"<?php echo $hourly_sort === 'asc' ? ' selected' : ''; ?>>Oldest first</option>
                    </select>
                </div>
                <button type="submit" class="btn glowing-element" style="margin-bottom: 0;">Apply</button>
                <?php if ($hourlyDateFilterActive && !$hourlyDateFilterDefault) { ?>
                    <a href="<?php echo htmlspecialchars($hourlyHistoryBaseUrl); ?>" class="btn-secondary" style="display: inline-flex; align-items: center; padding: 10px 16px; text-decoration: none; margin-bottom: 0;">
                        Reset to default
                    </a>
                <?php } ?>
            </div>
        </form>

        <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px;">
            <?php
            $hourlySortParam = ($hourly_sort === 'asc') ? '&hourly_sort=asc' : '';
            ?>
            <a href="<?php echo htmlspecialchars($hourlyHistoryBaseUrl . '&hourly_from=' . $yesterday . '&hourly_to=' . $yesterday . $hourlySortParam); ?>" class="badge warning" style="text-decoration: none; padding: 8px 14px;">Yesterday</a>
            <a href="<?php echo htmlspecialchars($hourlyHistoryBaseUrl . '&hourly_from=' . date('Y-m-d', strtotime('-6 days')) . '&hourly_to=' . $today . $hourlySortParam); ?>" class="badge warning" style="text-decoration: none; padding: 8px 14px;">Last 7 days</a>
            <a href="<?php echo htmlspecialchars($hourlyHistoryBaseUrl . '&hourly_from=' . date('Y-m-01') . '&hourly_to=' . $today . $hourlySortParam); ?>" class="badge warning" style="text-decoration: none; padding: 8px 14px;">This month</a>
            <a href="<?php echo htmlspecialchars($hourlyHistoryBaseUrl . '&hourly_from=' . $lastMonthStart . '&hourly_to=' . $lastMonthEnd . $hourlySortParam); ?>" class="badge warning" style="text-decoration: none; padding: 8px 14px;">Last month</a>
            <a href="<?php echo htmlspecialchars($hourlyHistoryBaseUrl . '&hourly_all=1' . $hourlySortParam); ?>" class="badge warning" style="text-decoration: none; padding: 8px 14px;">Show all</a>
        </div>

        <?php if ($hourlyDateFilterError !== '') { ?>
            <div class="alert danger" style="margin-bottom: 16px;">
                <span>⚠️</span>
                <span><?php echo htmlspecialchars($hourlyDateFilterError); ?></span>
            </div>
        <?php } elseif ($hourlyShowAll) { ?>
            <div class="alert info" style="margin-bottom: 16px;">
                <span>ℹ️</span>
                <span>Showing <strong>all</strong> hourly updates for this employee.</span>
            </div>
        <?php } elseif ($hourlyDateFilterActive) { ?>
            <div class="alert info" style="margin-bottom: 16px;">
                <span>ℹ️</span>
                <span>
                    Showing <strong><?php echo (int) $hourlyHistoryCount; ?></strong> update<?php echo $hourlyHistoryCount === 1 ? '' : 's'; ?>
                    from <strong><?php echo date('d M Y', strtotime($hourly_from)); ?></strong>
                    to <strong><?php echo date('d M Y', strtotime($hourly_to)); ?></strong>
                    <?php if ($hourlyDateFilterDefault) { ?>
                        (default: yesterday &amp; today)
                    <?php } ?>
                    .
                </span>
            </div>
        <?php } ?>

        <div class="table-box">
            <table>
                <tr>
                    <th>
                        <a href="<?php echo htmlspecialchars($hourlySortToggleUrl); ?>" style="color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                            Submitted
                            <span style="font-size: 0.75rem; color: var(--accent);">
                                <?php echo $hourly_sort === 'asc' ? '↑ Oldest' : '↓ Newest'; ?>
                            </span>
                        </a>
                    </th>
                    <th>Slot window</th>
                    <th>Status</th>
                    <th>Shift clock-in</th>
                    <th>Work activity</th>
                </tr>
                <?php if (count($hourlyHistoryRows) > 0) {
                    foreach ($hourlyHistoryRows as $hRow) {
                        $hTimestamp = strtotime($hRow['created_at']);
                        $slotLabel = '—';
                        $hasSlot = isHourlyUpdateRowValidForSlot($hRow);
                        if ($hasSlot) {
                            $slotLabel = formatHourlySlotLabel($hRow['slot_date'], $hRow['slot_hour']);
                        }
                ?>
                    <tr>
                        <td style="white-space: nowrap; font-size: 0.85rem;">
                            <?php echo date('d M Y', $hTimestamp); ?>
                            <div style="color: var(--text-muted); font-size: 0.75rem;"><?php echo date('h:i A', $hTimestamp); ?></div>
                        </td>
                        <td style="font-size: 0.85rem; color: var(--accent); white-space: nowrap;">
                            <?php echo htmlspecialchars($slotLabel); ?>
                        </td>
                        <td>
                            <?php if (!$hasSlot) { ?>
                                <span class="badge danger">Not counted</span>
                            <?php } elseif (!empty($hRow['is_grandfathered'])) { ?>
                                <span class="badge warning">Grandfathered</span>
                            <?php } else { ?>
                                <span class="badge success">On time</span>
                            <?php } ?>
                        </td>
                        <td style="font-size: 0.85rem; white-space: nowrap;">
                            <?php if (!empty($hRow['shift_start'])) { ?>
                                <?php echo date('d M Y', strtotime($hRow['shift_start'])); ?>
                                <div style="color: var(--text-muted); font-size: 0.75rem;"><?php echo date('h:i A', strtotime($hRow['shift_start'])); ?></div>
                            <?php } else { ?>
                                <span style="color: var(--text-muted);">—</span>
                            <?php } ?>
                        </td>
                        <td style="max-width: 420px; line-height: 1.6; word-wrap: break-word;">
                            <?php echo nl2br(htmlspecialchars($hRow['update_text'])); ?>
                        </td>
                    </tr>
                <?php }
                } else { ?>
                    <tr>
                        <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 24px;">
                            No hourly updates<?php
                            if ($hourlyDateFilterActive) {
                                echo ' from ' . date('d M Y', strtotime($hourly_from)) . ' to ' . date('d M Y', strtotime($hourly_to));
                            } elseif ($hourlyShowAll) {
                                echo ' recorded';
                            }
                            ?>.
                        </td>
                    </tr>
                <?php } ?>
            </table>
        </div>
    </div>

    <!-- SHIFT HISTORY -->
    <div class="card">
        <h2>Shift History</h2>
        <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 15px;">
            Check-in details, screenshots, and location.
            <?php if (!$dateFilterActive && count($employeeShifts) > 5) { ?>
                <em>Showing latest 5 — apply a date range above to see all.</em>
            <?php } ?>
        </p>

        <?php
        $displayShifts = count($employeeShifts) > 0
            ? ($dateFilterActive ? $employeeShifts : array_slice($employeeShifts, 0, 5))
            : [];
        ?>

        <div class="table-box">
            <table>
                <tr>
                    <th>Workday</th>
                    <th>Screenshot</th>
                    <th>Morning Message</th>
                    <th>Clock In / Out</th>
                    <th>Duration</th>
                    <th>Details (IP / Agent)</th>
                    <th>Status</th>
                    <th>Check-In Location</th>
                </tr>
                <?php if (count($displayShifts) > 0) {
                    foreach ($displayShifts as $shiftRow) {
                        $status = strtolower(trim($shiftRow['status']));
                        $badgeClass = ($status === 'active') ? 'success' : 'warning';
                        $startTimestamp = strtotime($shiftRow['start_time']);
                        $endTimeStr = ($status === 'active') ? $dbNow : $shiftRow['end_time'];
                        $endTimestamp = $endTimeStr ? strtotime($endTimeStr) : $startTimestamp;
                        $elapsedSeconds = max(0, $endTimestamp - $startTimestamp);
                        $elapsedHours = floor($elapsedSeconds / 3600);
                        $elapsedMinutes = floor(($elapsedSeconds % 3600) / 60);
                ?>
                    <tr>
                        <td style="white-space: nowrap; font-size: 0.85rem;">
                            <strong><?php echo date('d M Y', $startTimestamp); ?></strong>
                        </td>
                        <td>
                            <?php if (!empty($shiftRow['screenshot'])) { ?>
                                <a href="../uploads/screenshots/<?php echo htmlspecialchars($shiftRow['screenshot']); ?>" target="_blank">
                                    <img src="../uploads/screenshots/<?php echo htmlspecialchars($shiftRow['screenshot']); ?>" class="screenshot" alt="Shift screenshot">
                                </a>
                            <?php } else { ?>
                                <span class="badge warning" style="font-size: 0.7rem;">No Screen Capture</span>
                            <?php } ?>
                        </td>
                        <td style="max-width: 180px; font-style: italic; color: var(--text-muted); word-wrap: break-word;">
                            "<?php echo htmlspecialchars(!empty($shiftRow['morning_message']) ? $shiftRow['morning_message'] : '-'); ?>"
                        </td>
                        <td style="font-size: 0.85rem;">
                            <div>🟢 <strong style="color: var(--success);"><?php echo date('h:i A', $startTimestamp); ?></strong> (<?php echo date('d M Y', $startTimestamp); ?>)</div>
                            <?php if (!empty($shiftRow['end_time'])) { ?>
                                <div style="margin-top: 5px;">🔴 <strong style="color: var(--danger);"><?php echo date('h:i A', $endTimestamp); ?></strong> (<?php echo date('d M Y', $endTimestamp); ?>)</div>
                            <?php } else { ?>
                                <div style="margin-top: 5px; color: var(--text-muted);">🔴 Open Shift</div>
                            <?php } ?>
                        </td>
                        <td style="white-space: nowrap; font-weight: 700; color: var(--accent);">
                            <?php echo "{$elapsedHours}h {$elapsedMinutes}m"; ?>
                        </td>
                        <td style="font-size: 0.8rem; color: var(--text-muted);">
                            <div>IP: <strong style="color: var(--text-main);"><?php echo htmlspecialchars(!empty($shiftRow['ip_address']) ? $shiftRow['ip_address'] : '—'); ?></strong></div>
                            <div style="margin-top: 2px;">UA: <?php echo htmlspecialchars($shiftRow['device'] ?? '—'); ?></div>
                        </td>
                        <td>
                            <span class="badge <?php echo $badgeClass; ?>">
                                <?php echo strtoupper($status); ?>
                            </span>
                        </td>
                        <td style="max-width: 220px; font-size: 0.8rem; line-height: 1.4;">
                            <?php if (!empty($shiftRow['current_location']) && $shiftRow['current_location'] !== 'Unknown Location') { ?>
                                <div style="font-weight: 600; color: var(--accent);">📍 <?php echo htmlspecialchars(explode(',', $shiftRow['current_location'])[0]); ?></div>
                                <div style="color: var(--text-muted); font-size: 0.75rem; margin-top: 2px;">
                                    Coords: <?php echo htmlspecialchars(!empty($shiftRow['current_latitude']) ? $shiftRow['current_latitude'] : '0.0'); ?>, <?php echo htmlspecialchars(!empty($shiftRow['current_longitude']) ? $shiftRow['current_longitude'] : '0.0'); ?>
                                </div>
                            <?php } else { ?>
                                <span class="badge danger" style="font-size: 0.7rem;">No Location</span>
                            <?php } ?>
                        </td>
                    </tr>
                <?php }
                } else { ?>
                    <tr>
                        <td colspan="8" style="text-align: center; color: var(--text-muted); padding: 30px;">
                            No shift history<?php echo $dateFilterActive ? ' in the selected date range' : ''; ?>.
                        </td>
                    </tr>
                <?php } ?>
            </table>
        </div>
    </div>

    <!-- LOGIN AUDIT (collapsed importance) -->
    <div class="card">
        <h2>System Login Audit History</h2>
        <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 15px;">Portal sign-ins only (not shift check-ins).<?php if (!$dateFilterActive) { ?> <em>Latest 8.</em><?php } ?></p>
        <div class="table-box">
            <table>
                <tr>
                    <th>Sign-in Time</th>
                    <th>IP</th>
                    <th>Device</th>
                    <th>Location</th>
                </tr>
                <?php
                $logins = $conn->query("
                    SELECT * FROM login_logs
                    WHERE user_id='$employee_id' $dateClauseLogins
                    ORDER BY created_at DESC
                    $loginLimit
                ");
                if ($logins && $logins->num_rows > 0) {
                    while ($row = $logins->fetch_assoc()) {
                ?>
                    <tr>
                        <td><?php echo date('d M Y - h:i A', strtotime($row['created_at'])); ?></td>
                        <td><?php echo htmlspecialchars($row['ip_address']); ?></td>
                        <td><?php echo htmlspecialchars($row['device']); ?></td>
                        <td style="font-size: 0.85rem;"><?php echo htmlspecialchars($row['location']); ?></td>
                    </tr>
                <?php }
                } else { ?>
                    <tr><td colspan="4" style="text-align:center;color:var(--text-muted);padding:15px;">No login logs.</td></tr>
                <?php } ?>
            </table>
        </div>
    </div>

<?php } } ?>
