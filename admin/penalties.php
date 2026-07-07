<?php
include_once "../includes/db.php";
include_once "../includes/auth.php";
include_once "../includes/functions.php";

if($_SESSION['user']['role'] != 'admin'){
    exit("Access Denied");
}

$currentMonth = date('Y-m');
$previousMonth = date('Y-m', strtotime('first day of last month'));
$monthInput = trim($_GET['month'] ?? '');
$selectedMonth = $currentMonth;

if ($monthInput !== '') {
    if (preg_match('/^\d{4}-\d{2}$/', $monthInput)) {
        $selectedMonth = $monthInput;
    }
}

$displayMonth = date('F Y', strtotime($selectedMonth . '-01'));
$isCurrentMonth = ($selectedMonth === $currentMonth);
$isPreviousMonth = ($selectedMonth === $previousMonth);

list($monthFrom, $monthTo) = getPayrollMonthDateRange($selectedMonth);
$dbNowTs = getDatabaseNowTimestamp($conn);

/* TOTAL CAPACITY STATISTICS */
$totalSalary = $conn->query("
    SELECT SUM(salary) as total 
    FROM users 
    WHERE role='employee'
")->fetch_assoc();

$grossSalary = floatval($totalSalary['total'] ?? 0);
$workforcePenalties = calculateWorkforceDynamicPenalties($conn, $monthFrom, $monthTo, $dbNowTs);
$totalPenalty = $workforcePenalties['total'];
$netSalary = $grossSalary - $totalPenalty;
?>

<div class="page-title">Salaries & Deductions Auditor</div>

<!-- MONTH FILTER -->
<div class="card" style="margin-bottom: 24px;">
    <div class="form-group" style="margin-bottom: 0;">
        <label>Payout month</label>
        <div style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center;">
            <a href="dashboard.php?page=penalties&amp;month=<?php echo $currentMonth; ?>" class="badge <?php echo $isCurrentMonth ? 'success' : 'warning'; ?>" style="text-decoration: none; padding: 8px 14px;">Current month</a>
            <a href="dashboard.php?page=penalties&amp;month=<?php echo $previousMonth; ?>" class="badge <?php echo $isPreviousMonth ? 'success' : 'warning'; ?>" style="text-decoration: none; padding: 8px 14px;">Previous month</a>
            <form method="GET" action="dashboard.php" style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 0;">
                <input type="hidden" name="page" value="penalties">
                <input type="month" name="month" value="<?php echo htmlspecialchars($selectedMonth); ?>" style="max-width: 180px;">
                <button type="submit" class="btn glowing-element" style="padding: 8px 14px; margin: 0;">Apply</button>
            </form>
        </div>
    </div>
    <div class="alert info" style="margin-top: 14px; margin-bottom: 0;">
        <span>ℹ️</span>
        <span>Showing payroll for <strong><?php echo htmlspecialchars($displayMonth); ?></strong> using live penalty calculations. View Payslip opens the same month.</span>
    </div>
</div>

<!-- PAYROLL OVERVIEW STATS -->
<div class="summary" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 24px; margin-bottom: 30px;">
    <div class="card stat-box" style="margin-bottom: 0;">
        <h4>Gross Payout Base</h4>
        <h2 style="color: var(--accent);">PKR <?php echo number_format($grossSalary); ?></h2>
        <p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 6px;">Contract salaries (all employees)</p>
    </div>

    <div class="card stat-box" style="margin-bottom: 0; border-bottom: 4px solid var(--danger);">
        <h4>Fines (<?php echo htmlspecialchars($displayMonth); ?>)</h4>
        <h2 style="color: var(--danger);">PKR <?php echo number_format($totalPenalty); ?></h2>
    </div>

    <div class="card stat-box" style="margin-bottom: 0; border-bottom: 4px solid var(--success);">
        <h4>Net Payout Pool</h4>
        <h2 style="color: var(--success);">PKR <?php echo number_format($netSalary); ?></h2>
        <p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 6px;">Gross − <?php echo htmlspecialchars($displayMonth); ?> fines</p>
    </div>
</div>

<!-- PAYROLL AUDIT LIST -->
<div class="card">
    <h2>Monthly Employee Payout Sheet — <?php echo htmlspecialchars($displayMonth); ?></h2>

    <div class="table-box">
        <table>
            <tr>
                <th>Employee Name</th>
                <th>Contract Salary (Gross)</th>
                <th>Deductions (Fines)</th>
                <th>Net Payout (Calculated)</th>
                <th>Actions</th>
            </tr>
            <?php
            $payrolls = $conn->query("
                SELECT id, name, email, salary
                FROM users
                WHERE role='employee'
                ORDER BY name ASC
            ");

            if($payrolls && $payrolls->num_rows > 0){
                while($row = $payrolls->fetch_assoc()){
                    $employeeId = (int) $row['id'];
                    $salary = floatval($row['salary']);
                    $deduction = $workforcePenalties['by_employee'][$employeeId]['total'] ?? 0.0;
                    $remaining = $salary - $deduction;
            ?>
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars($row['name']); ?></strong>
                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo htmlspecialchars($row['email']); ?></div>
                    </td>

                    <td style="color: var(--accent); font-weight: 600; font-family: var(--font-heading);">
                        PKR <?php echo number_format($salary); ?>
                    </td>

                    <td style="color: var(--danger); font-weight: 600; font-family: var(--font-heading);">
                        PKR <?php echo number_format($deduction); ?>
                    </td>

                    <td style="color: var(--success); font-weight: 700; font-family: var(--font-heading); font-size: 1rem;">
                        PKR <?php echo number_format($remaining); ?>
                    </td>

                    <td>
                        <a href="dashboard.php?page=salary-slip&amp;employee_id=<?php echo $row['id']; ?>&amp;month=<?php echo urlencode($selectedMonth); ?>" class="btn" style="padding: 6px 12px; font-size: 0.8rem; background: linear-gradient(135deg, var(--accent) 0%, #0891b2 100%); box-shadow: none;">
                            📄 View Payslip
                        </a>
                    </td>
                </tr>
            <?php } 
            } else { ?>
                <tr>
                    <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px;">
                        No employee payouts sheet found.
                    </td>
                </tr>
            <?php } ?>
        </table>
    </div>
</div>
