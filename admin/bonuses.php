<?php
include_once "../includes/db.php";
include_once "../includes/functions.php";

if($_SESSION['user']['role'] != 'admin'){
    exit("Access Denied");
}

$currentMonth = date('Y-m');
$previousMonth = date('Y-m', strtotime('first day of last month'));
$monthInput = trim($_GET['month'] ?? '');
$selectedMonth = $currentMonth;

if ($monthInput !== '' && isValidPayrollMonthKey($monthInput)) {
    $selectedMonth = $monthInput;
}

$displayMonth = date('F Y', strtotime($selectedMonth . '-01'));
$isCurrentMonth = ($selectedMonth === $currentMonth);
$isPreviousMonth = ($selectedMonth === $previousMonth);

$monthBonuses = getBonusesForMonth($conn, $selectedMonth);
$monthTotals = getWorkforceBonusTotals($conn, $selectedMonth);
$activityLog = getBonusActivityLog($conn, 20);

$employeesPaid = count($monthTotals['by_employee']);
?>

<?php if (isset($_SESSION['bonus_msg'])) { ?>
    <div class="alert <?php echo ($_SESSION['bonus_msg_type'] ?? 'success') === 'success' ? 'success' : 'danger'; ?>" style="margin-bottom: 16px;">
        <span><?php echo ($_SESSION['bonus_msg_type'] ?? 'success') === 'success' ? '✅' : '⚠️'; ?></span>
        <span><?php echo htmlspecialchars($_SESSION['bonus_msg']); unset($_SESSION['bonus_msg'], $_SESSION['bonus_msg_type']); ?></span>
    </div>
<?php } ?>

<div class="page-title">Add Bonuses</div>

<!-- MONTH FILTER -->
<div class="card" style="margin-bottom: 24px;">
    <div class="form-group" style="margin-bottom: 0;">
        <label>Bonus month</label>
        <div style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center;">
            <a href="dashboard.php?page=bonuses&amp;month=<?php echo $currentMonth; ?>" class="badge <?php echo $isCurrentMonth ? 'success' : 'warning'; ?>" style="text-decoration: none; padding: 8px 14px;">Current month</a>
            <a href="dashboard.php?page=bonuses&amp;month=<?php echo $previousMonth; ?>" class="badge <?php echo $isPreviousMonth ? 'success' : 'warning'; ?>" style="text-decoration: none; padding: 8px 14px;">Previous month</a>
            <form method="GET" action="dashboard.php" style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 0;">
                <input type="hidden" name="page" value="bonuses">
                <input type="month" name="month" value="<?php echo htmlspecialchars($selectedMonth); ?>" style="max-width: 180px;">
                <button type="submit" class="btn glowing-element" style="padding: 8px 14px; margin: 0;">Apply</button>
            </form>
        </div>
    </div>
    <div class="alert info" style="margin-top: 14px; margin-bottom: 0;">
        <span>ℹ️</span>
        <span>A bonus is attached to the month you pick below and is added automatically to that month's salary slip (admin and employee view).</span>
    </div>
</div>

<!-- SUMMARY STATS -->
<div class="summary" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 24px; margin-bottom: 30px;">
    <div class="card stat-box" style="margin-bottom: 0; border-bottom: 4px solid var(--success);">
        <h4>Bonuses (<?php echo htmlspecialchars($displayMonth); ?>)</h4>
        <h2 style="color: var(--success);">PKR <?php echo number_format($monthTotals['total']); ?></h2>
    </div>

    <div class="card stat-box" style="margin-bottom: 0;">
        <h4>Bonus Entries</h4>
        <h2><?php echo count($monthBonuses); ?></h2>
        <p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 6px;">Across <?php echo $employeesPaid; ?> employee(s)</p>
    </div>
</div>

<!-- ADD BONUS FORM -->
<div class="card">
    <h2>Add Bonus to a Salary Slip</h2>
    <p style="color: var(--text-muted); margin-bottom: 24px;">
        Select the employee and the month the bonus belongs to. It is credited as an earning on that month's payslip and recorded in the activity log below.
    </p>

    <form method="POST" action="dashboard.php?page=bonuses&amp;month=<?php echo urlencode($selectedMonth); ?>">
        <div class="form-group">
            <label>Select Employee</label>
            <select name="employee_id" required>
                <option value="">-- Choose Employee --</option>
                <?php
                $emps = $conn->query("SELECT id, name, email FROM users WHERE role='employee' ORDER BY name ASC");
                while($row = $emps->fetch_assoc()){
                ?>
                    <option value="<?php echo $row['id']; ?>">
                        <?php echo htmlspecialchars($row['name']); ?> (<?php echo htmlspecialchars($row['email']); ?>)
                    </option>
                <?php } ?>
            </select>
        </div>

        <div class="form-group">
            <label>Apply to Salary Slip Month</label>
            <input type="month" name="bonus_month" value="<?php echo htmlspecialchars($selectedMonth); ?>" required>
            <p style="color: var(--text-muted); font-size: 0.8rem; margin-top: 6px;">
                The bonus shows up on this month's payslip, regardless of today's date.
            </p>
        </div>

        <div class="form-group">
            <label>Bonus Title / Reason</label>
            <input type="text" name="title" placeholder="e.g. Performance bonus, Eid bonus, Overtime reward" maxlength="255" required>
        </div>

        <div class="form-group" style="margin-bottom: 25px;">
            <label>Bonus Amount (PKR)</label>
            <input type="number" name="amount" min="1" step="1" placeholder="5000" required>
        </div>

        <button type="submit" name="add_bonus" class="btn glowing-element">
            🎁 Add Bonus to Payslip
        </button>
    </form>
</div>

<!-- BONUSES FOR SELECTED MONTH -->
<div class="card">
    <h2>Bonuses — <?php echo htmlspecialchars($displayMonth); ?></h2>

    <div class="table-box">
        <table>
            <tr>
                <th>Employee Name</th>
                <th>Bonus Title / Reason</th>
                <th>Amount</th>
                <th>Added By</th>
                <th>Added On</th>
                <th>Actions</th>
            </tr>
            <?php if(count($monthBonuses) > 0){
                foreach($monthBonuses as $bonus){
            ?>
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars($bonus['employee_name'] ?? 'Unknown employee'); ?></strong>
                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo htmlspecialchars($bonus['employee_email'] ?? ''); ?></div>
                    </td>

                    <td style="max-width: 320px; line-height: 1.5; word-wrap: break-word;">
                        <?php echo htmlspecialchars($bonus['title']); ?>
                    </td>

                    <td style="color: var(--success); font-weight: 700; font-family: var(--font-heading);">
                        + PKR <?php echo number_format(floatval($bonus['amount'])); ?>
                    </td>

                    <td style="color: var(--text-muted); font-size: 0.85rem;">
                        <?php echo htmlspecialchars($bonus['created_by_name'] ?? 'Admin'); ?>
                    </td>

                    <td style="color: var(--text-muted); font-size: 0.85rem;">
                        📅 <?php echo date('d M Y h:i A', strtotime($bonus['created_at'])); ?>
                    </td>

                    <td style="display: flex; gap: 6px; flex-wrap: wrap;">
                        <a href="dashboard.php?page=salary-slip&amp;employee_id=<?php echo (int) $bonus['employee_id']; ?>&amp;month=<?php echo urlencode($bonus['bonus_month']); ?>" class="btn" style="padding: 6px 12px; font-size: 0.8rem; background: linear-gradient(135deg, var(--accent) 0%, #0891b2 100%); box-shadow: none;">
                            📄 Slip
                        </a>
                        <form method="POST" action="dashboard.php?page=bonuses&amp;month=<?php echo urlencode($selectedMonth); ?>" style="margin: 0;" onsubmit="return confirm('Remove this bonus from the payslip? This will be recorded in the activity log.');">
                            <input type="hidden" name="bonus_id" value="<?php echo (int) $bonus['id']; ?>">
                            <button type="submit" name="delete_bonus" class="btn-danger" style="padding: 6px 12px; font-size: 0.8rem;">
                                🗑️ Remove
                            </button>
                        </form>
                    </td>
                </tr>
            <?php }
            } else { ?>
                <tr>
                    <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 30px;">
                        No bonuses added for <?php echo htmlspecialchars($displayMonth); ?> yet.
                    </td>
                </tr>
            <?php } ?>
        </table>
    </div>
</div>

<!-- ACTIVITY LOG -->
<div class="card">
    <h2>Bonus Activity Log</h2>
    <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 15px;">
        Every bonus added or removed is logged here with the admin who performed it.
    </p>

    <div class="table-box">
        <table>
            <tr>
                <th>Action</th>
                <th>Log Message</th>
                <th>Slip Month</th>
                <th>Performed By</th>
                <th>Logged At</th>
            </tr>
            <?php if(count($activityLog) > 0){
                foreach($activityLog as $log){
                    $isAdded = ($log['action'] === 'added');
            ?>
                <tr>
                    <td>
                        <span class="badge <?php echo $isAdded ? 'success' : 'danger'; ?>">
                            <?php echo $isAdded ? '➕ Added' : '➖ Removed'; ?>
                        </span>
                    </td>

                    <td style="max-width: 420px; line-height: 1.5; word-wrap: break-word;">
                        <?php echo htmlspecialchars($log['message'] ?? ''); ?>
                    </td>

                    <td style="color: var(--text-muted); font-size: 0.85rem;">
                        <?php echo !empty($log['bonus_month']) ? htmlspecialchars(date('M Y', strtotime($log['bonus_month'] . '-01'))) : '-'; ?>
                    </td>

                    <td style="color: var(--text-muted); font-size: 0.85rem;">
                        <?php echo htmlspecialchars($log['performed_by_name'] ?? 'Admin'); ?>
                    </td>

                    <td style="color: var(--text-muted); font-size: 0.85rem;">
                        🕒 <?php echo date('d M Y h:i A', strtotime($log['created_at'])); ?>
                    </td>
                </tr>
            <?php }
            } else { ?>
                <tr>
                    <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px;">
                        No bonus activity logged yet.
                    </td>
                </tr>
            <?php } ?>
        </table>
    </div>
</div>
