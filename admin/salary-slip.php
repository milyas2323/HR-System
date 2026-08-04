<?php
include_once "../includes/db.php";
include_once "../includes/auth.php";
include_once "../includes/functions.php";

if($_SESSION['user']['role'] != 'admin'){
    exit("Access Denied");
}

if(!isset($_GET['employee_id'])){
    echo '<div class="alert danger">Invalid request. Employee ID is required.</div>';
    exit();
}

$employee_id = intval($_GET['employee_id']);
$currentMonth = date('Y-m');
$previousMonth = date('Y-m', strtotime('first day of last month'));
$monthInput = trim($_GET['month'] ?? '');
$selectedMonth = $currentMonth;

if ($monthInput !== '') {
    if (preg_match('/^\d{4}-\d{2}$/', $monthInput)) {
        $selectedMonth = $monthInput;
    }
}

$display_month = date('F Y', strtotime($selectedMonth . '-01'));
$isCurrentMonth = ($selectedMonth === $currentMonth);
$isPreviousMonth = ($selectedMonth === $previousMonth);

// Fetch Employee Details
$empRes = $conn->query("SELECT * FROM users WHERE id='$employee_id' AND role='employee' LIMIT 1");
$emp = $empRes->fetch_assoc();

if(!$emp){
    echo '<div class="alert danger">Employee record not found.</div>';
    exit();
}

$salary = floatval($emp['salary']);

list($monthFrom, $monthTo) = getPayrollMonthDateRange($selectedMonth);
$dbNowTs = getDatabaseNowTimestamp($conn);
$penaltyReport = buildEmployeePenaltyReportRows($conn, $employee_id, $monthFrom, $monthTo, $dbNowTs);
$deductions = $penaltyReport['rows'];
$total_deductions = $penaltyReport['total'];

$bonuses = getEmployeeBonusRows($conn, $employee_id, $selectedMonth);
$total_bonuses = getEmployeeBonusTotal($conn, $employee_id, $selectedMonth);
$total_earnings = $salary + $total_bonuses;
$net_salary = $total_earnings - $total_deductions;
?>

<style>
    .payslip-container {
        max-width: 800px;
        margin: 0 auto;
        padding: 40px;
    }
    
    .payslip-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        border-bottom: 2px solid var(--panel-border);
        padding-bottom: 25px;
        margin-bottom: 30px;
    }
    
    .company-logo {
        font-family: var(--font-heading);
        font-size: 1.8rem;
        font-weight: 800;
        color: var(--primary);
    }
    
    .payslip-title {
        text-align: right;
    }
    
    .payslip-title h2 {
        margin: 0;
        font-size: 1.5rem;
        letter-spacing: 0.5px;
    }
    
    .payslip-meta {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 30px;
        margin-bottom: 40px;
        font-size: 0.95rem;
    }
    
    .meta-group h4 {
        color: var(--primary);
        margin-bottom: 10px;
        font-size: 0.9rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .meta-group p {
        margin-bottom: 5px;
        color: var(--text-muted);
    }
    
    .meta-group strong {
        color: var(--text-main);
    }
    
    .salary-table {
        margin-bottom: 40px;
    }
    
    .salary-table table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .salary-table th {
        background: rgba(255, 255, 255, 0.05);
        font-family: var(--font-heading);
        font-weight: 600;
        font-size: 0.9rem;
    }
    
    .total-row td {
        font-family: var(--font-heading);
        font-weight: 700;
        font-size: 1.05rem;
        border-top: 2px solid var(--panel-border);
        background: rgba(99, 102, 241, 0.05);
    }

    .total-row.net td {
        font-size: 1.25rem;
        color: var(--success);
        background: rgba(16, 185, 129, 0.08);
    }
    
    .action-controls {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 20px;
    }

    /* ==========================================
       PRINT MEDIA QUERY (STYLES FOR PDF PRINT)
       ========================================== */
    @media print {
        body {
            background: #ffffff !important;
            color: #000000 !important;
        }
        .sidebar, .topbar, .action-controls, .alert, .glowing-element {
            display: none !important;
        }
        .main {
            margin-left: 0 !important;
            padding: 0 !important;
            min-height: auto !important;
        }
        .card, .payslip-container {
            background: none !important;
            border: none !important;
            box-shadow: none !important;
            padding: 0 !important;
            margin: 0 !important;
            width: 100% !important;
            color: #000000 !important;
        }
        .payslip-header {
            border-bottom: 2px solid #000000 !important;
        }
        .company-logo {
            color: #000000 !important;
        }
        .meta-group h4 {
            color: #000000 !important;
            border-bottom: 1px solid #000000 !important;
        }
        .meta-group p {
            color: #000000 !important;
        }
        .meta-group strong {
            color: #000000 !important;
        }
        table, th, td {
            border-color: #000000 !important;
            color: #000000 !important;
        }
        th {
            background: #f3f4f6 !important;
            border-bottom: 2px solid #000000 !important;
        }
        td {
            border-bottom: 1px solid #e5e7eb !important;
        }
        .total-row td {
            background: none !important;
            border-top: 2px solid #000000 !important;
        }
        .total-row.net td {
            color: #000000 !important;
            background: #f3f4f6 !important;
            border-bottom: 2px solid #000000 !important;
        }
    }
</style>

<div class="card" style="max-width: 800px; margin: 0 auto 20px;">
    <div class="form-group" style="margin-bottom: 0;">
        <label>Payslip month</label>
        <div style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center;">
            <a href="dashboard.php?page=salary-slip&amp;employee_id=<?php echo $employee_id; ?>&amp;month=<?php echo $currentMonth; ?>" class="badge <?php echo $isCurrentMonth ? 'success' : 'warning'; ?>" style="text-decoration: none; padding: 8px 14px;">Current month</a>
            <a href="dashboard.php?page=salary-slip&amp;employee_id=<?php echo $employee_id; ?>&amp;month=<?php echo $previousMonth; ?>" class="badge <?php echo $isPreviousMonth ? 'success' : 'warning'; ?>" style="text-decoration: none; padding: 8px 14px;">Previous month</a>
            <form method="GET" action="dashboard.php" style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 0;">
                <input type="hidden" name="page" value="salary-slip">
                <input type="hidden" name="employee_id" value="<?php echo $employee_id; ?>">
                <input type="month" name="month" value="<?php echo htmlspecialchars($selectedMonth); ?>" style="max-width: 180px;">
                <button type="submit" class="btn glowing-element" style="padding: 8px 14px; margin: 0;">Apply</button>
            </form>
        </div>
    </div>
</div>

<div class="card payslip-container">
    
    <!-- PAYSLIP HEADER -->
    <div class="payslip-header">
        <div class="company-logo">
            🏢 Workforce Attendance System
        </div>
        <div class="payslip-title">
            <h2>MONTHLY PAYSLIP</h2>
            <p style="color: var(--text-muted); font-size: 0.9rem;"><?php echo htmlspecialchars($display_month); ?></p>
        </div>
    </div>

    <!-- META INFORMATION -->
    <div class="payslip-meta">
        <div class="meta-group">
            <h4>Employee Details</h4>
            <p>Name: <strong><?php echo htmlspecialchars($emp['name']); ?></strong></p>
            <p>Email: <strong><?php echo htmlspecialchars($emp['email']); ?></strong></p>
            <p>Designation: <strong>Staff Member</strong></p>
        </div>
        <div class="meta-group">
            <h4>Payslip Details</h4>
            <p>Deduction Cycle: <strong><?php echo htmlspecialchars($display_month); ?></strong></p>
            <p>Bonuses Added: <strong>PKR <?php echo number_format($total_bonuses); ?></strong></p>
            <p>Slip Date: <strong><?php echo date('d M Y'); ?></strong></p>
            <p>Payment Mode: <strong>Bank Transfer</strong></p>
        </div>
    </div>

    <!-- DETAILED SALARY SHEET -->
    <div class="salary-table">
        <h3>Earnings & Deductions Summary</h3>
        <div class="table-box" style="margin-top: 15px;">
            <table>
                <thead>
                    <tr>
                        <th>Description</th>
                        <th style="text-align: right;">Earnings (PKR)</th>
                        <th style="text-align: right;">Deductions (PKR)</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- BASE SALARY -->
                    <tr>
                        <td><strong>Basic Monthly Salary Rate</strong></td>
                        <td style="text-align: right; color: var(--accent); font-weight: 600;">
                            <?php echo number_format($salary); ?>
                        </td>
                        <td style="text-align: right; color: var(--text-muted);">-</td>
                    </tr>

                    <!-- ITEMIZED BONUSES -->
                    <?php foreach($bonuses as $bonus){ ?>
                        <tr>
                            <td style="color: var(--text-muted); padding-left: 20px;">
                                🎁 Bonus — <?php echo htmlspecialchars($bonus['title']); ?>
                                <span style="font-size: 0.75rem;">(added <?php echo date('d M', strtotime($bonus['created_at'])); ?> by <?php echo htmlspecialchars($bonus['created_by_name'] ?? 'Admin'); ?>)</span>
                            </td>
                            <td style="text-align: right; color: var(--success); font-weight: 600;">
                                <?php echo number_format(floatval($bonus['amount'])); ?>
                            </td>
                            <td style="text-align: right; color: var(--text-muted);">-</td>
                        </tr>
                    <?php } ?>

                    <!-- ITEMIZED DEDUCTIONS -->
                    <?php if(count($deductions) > 0){
                        foreach($deductions as $fine){
                    ?>
                        <tr>
                            <td style="color: var(--text-muted); padding-left: 20px;">
                                ⚠️ <?php echo htmlspecialchars($fine['reason']); ?>
                                <span style="font-size: 0.75rem;">(<?php echo date('d M', strtotime($fine['created_at'])); ?>)</span>
                            </td>
                            <td style="text-align: right; color: var(--text-muted);">-</td>
                            <td style="text-align: right; color: var(--danger); font-weight: 600;">
                                <?php echo number_format(floatval($fine['amount'])); ?>
                            </td>
                        </tr>
                    <?php } 
                    } else { ?>
                        <tr>
                            <td style="color: var(--success); font-style: italic; padding-left: 20px;">
                                No fines/deductions applied this month.
                            </td>
                            <td style="text-align: right; color: var(--text-muted);">-</td>
                            <td style="text-align: right; color: var(--success); font-weight: 600;">0.00</td>
                        </tr>
                    <?php } ?>

                    <!-- TOTAL SUMMARY ROWS -->
                    <tr class="total-row">
                        <td><strong>Subtotals</strong></td>
                        <td style="text-align: right; font-weight: 700; color: var(--accent);">
                            <?php echo number_format($total_earnings); ?>
                        </td>
                        <td style="text-align: right; font-weight: 700; color: var(--danger);">
                            <?php echo number_format($total_deductions); ?>
                        </td>
                    </tr>

                    <!-- NET PAYOUT -->
                    <tr class="total-row net">
                        <td><strong>Net Salary Payout</strong></td>
                        <td colspan="2" style="text-align: right; font-weight: 800;">
                            PKR <?php echo number_format($net_salary); ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ACTION CONTROLS -->
    <div class="action-controls">
        <a href="dashboard.php?page=penalties&amp;month=<?php echo urlencode($selectedMonth); ?>" class="btn btn-secondary">
            ⬅️ Back to Payroll
        </a>
        <button onclick="window.print()" class="glowing-element">
            🖨️ Print / Download Slip (PDF)
        </button>
    </div>

</div>
