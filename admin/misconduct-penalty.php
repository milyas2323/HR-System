<?php
include "../includes/db.php";
include "../includes/functions.php";

if($_SESSION['user']['role'] != 'admin'){
    exit("Access Denied");
}

/* =========================
   APPLY MISCONDUCT PENALTY
   ========================= */
if(isset($_POST['submit'])){
    $employee_id = intval($_POST['employee_id']);
    $reason = trim($_POST['reason']);
    $amount = floatval($_POST['amount']);

    if($employee_id > 0 && !empty($reason) && $amount > 0){
        // Add manual penalty via helper
        addPenalty($conn, $employee_id, $reason, $amount);
        
        $_SESSION['msg'] = "Misconduct penalty of PKR " . number_format($amount) . " applied successfully!";
        echo "<script>window.location.href='dashboard.php?page=misconduct-penalty';</script>";
        exit();
    } else {
        $_SESSION['msg'] = "Please verify all form inputs.";
    }
}
?>

<div class="page-title">Log Misconduct Incidents</div>

<!-- PENALTY ISSUANCE FORM -->
<div class="card">
    <h2>Apply Misconduct Fine</h2>
    <p style="color: var(--text-muted); margin-bottom: 24px;">
        Dishonesty, false reporting, or workplace misconduct triggers a standard penalty of <strong>PKR 10,000</strong>.
    </p>

    <form method="POST" action="dashboard.php?page=misconduct-penalty">
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
            <label>Misconduct Reason / Incident details</label>
            <textarea name="reason" placeholder="Describe the incident (e.g. Falsifying check-in coordinates)..." required style="min-height: 100px;"></textarea>
        </div>

        <div class="form-group" style="margin-bottom: 25px;">
            <label>Penalty Fine Amount (PKR)</label>
            <input type="number" name="amount" value="10000" min="0" required>
        </div>

        <button type="submit" name="submit" class="btn-danger glowing-element">
            ⚠️ Issue Financial Deduction
        </button>
    </form>
</div>

<!-- HISTORICAL LOG TABLE -->
<div class="card">
    <h2>Recent Logged Penalties</h2>

    <div class="table-box">
        <table>
            <tr>
                <th>Employee Name</th>
                <th>Violation Detail / Incident</th>
                <th>Deduction Amount</th>
                <th>Date Logged</th>
            </tr>
            <?php
            $penaltiesList = $conn->query("
                SELECT p.*, u.name, u.email 
                FROM penalties p
                JOIN users u ON u.id = p.employee_id
                ORDER BY p.id DESC
                LIMIT 15
            ");

            if($penaltiesList && $penaltiesList->num_rows > 0){
                while($row = $penaltiesList->fetch_assoc()){
            ?>
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars($row['name']); ?></strong>
                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo htmlspecialchars($row['email']); ?></div>
                    </td>
                    
                    <td style="max-width: 320px; line-height: 1.5; color: var(--text-main); word-wrap: break-word;">
                        <?php echo htmlspecialchars($row['reason']); ?>
                    </td>

                    <td style="color: var(--danger); font-weight: 700; font-family: var(--font-heading);">
                        - PKR <?php echo number_format($row['amount']); ?>
                    </td>

                    <td style="color: var(--text-muted); font-size: 0.85rem;">
                        📅 <?php echo date('d M Y h:i A', strtotime($row['created_at'])); ?>
                    </td>
                </tr>
            <?php } 
            } else { ?>
                <tr>
                    <td colspan="4" style="text-align: center; color: var(--text-muted); padding: 30px;">
                        No salary deductions recorded this month.
                    </td>
                </tr>
            <?php } ?>
        </table>
    </div>
</div>