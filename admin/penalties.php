<?php
include "../includes/db.php";
include "../includes/auth.php";

if($_SESSION['user']['role'] != 'admin'){
    exit("Access Denied");
}

/* TOTAL CAPACITY STATISTICS */
$totalSalary = $conn->query("
    SELECT SUM(salary) as total 
    FROM users 
    WHERE role='employee'
")->fetch_assoc();

$totalDeduction = $conn->query("
    SELECT SUM(amount) as total 
    FROM penalties
")->fetch_assoc();

$grossSalary = $totalSalary['total'] ?? 0;
$totalPenalty = $totalDeduction['total'] ?? 0;
$netSalary = $grossSalary - $totalPenalty;
?>

<div class="page-title">Salaries & Deductions Auditor</div>

<!-- PAYROLL OVERVIEW STATS -->
<div class="summary" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 24px; margin-bottom: 30px;">
    <div class="card stat-box" style="margin-bottom: 0;">
        <h4>Gross Payout Base</h4>
        <h2 style="color: var(--accent);">PKR <?php echo number_format($grossSalary); ?></h2>
    </div>

    <div class="card stat-box" style="margin-bottom: 0; border-bottom: 4px solid var(--danger);">
        <h4>Cumulative Fines</h4>
        <h2 style="color: var(--danger);">PKR <?php echo number_format($totalPenalty); ?></h2>
    </div>

    <div class="card stat-box" style="margin-bottom: 0; border-bottom: 4px solid var(--success);">
        <h4>Net Payout Pool</h4>
        <h2 style="color: var(--success);">PKR <?php echo number_format($netSalary); ?></h2>
    </div>
</div>

<!-- PAYROLL AUDIT LIST -->
<div class="card">
    <h2>Monthly Employee Payout Sheet</h2>

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
                SELECT 
                    u.id, u.name, u.email, u.salary,
                    COALESCE(SUM(p.amount), 0) AS total_deduction
                FROM users u
                LEFT JOIN penalties p ON u.id = p.employee_id
                WHERE u.role='employee'
                GROUP BY u.id, u.name, u.email, u.salary
                ORDER BY u.name ASC
            ");

            if($payrolls && $payrolls->num_rows > 0){
                while($row = $payrolls->fetch_assoc()){
                    $salary = floatval($row['salary']);
                    $deduction = floatval($row['total_deduction']);
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
                        <a href="dashboard.php?page=salary-slip&employee_id=<?php echo $row['id']; ?>" class="btn" style="padding: 6px 12px; font-size: 0.8rem; background: linear-gradient(135deg, var(--accent) 0%, #0891b2 100%); box-shadow: none;">
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