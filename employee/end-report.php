<?php
// Included from dashboard.php — auth and POST handled there.
$user_id = (int) $user['id'];
$message = $endReportMessage ?? '';
$messageType = $endReportMessageType ?? 'danger';

$shift = $conn->query("
    SELECT * FROM shifts
    WHERE employee_id='$user_id'
    AND status='active'
    LIMIT 1
");
$active = $shift->fetch_assoc();
?>

<div class="card">
    <h2>Close Shift & Submit End Report</h2>
    <p style="color: var(--text-muted); margin-bottom: 16px;">
        Summarize your workday before clocking out. Missing this report counts as a missed update.
    </p>

    <?php if($message != "") { ?>
        <div class="alert <?php echo $messageType; ?>">
            <span>⚠️</span>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php } ?>

    <?php if(!$active) { ?>
        <div class="alert info">
            <span>ℹ️</span>
            <span>No active shift found. Start a shift first.</span>
        </div>
    <?php } else { ?>
        <form method="POST" action="dashboard.php?page=end-report">
            <div class="form-group">
                <label>End of Day Report</label>
                <textarea name="report" required style="min-height: 180px;" placeholder="Summarize tasks completed today..."></textarea>
            </div>
            <button type="submit" name="submit" class="glowing-element">🏁 Submit Report & Close Shift</button>
        </form>
    <?php } ?>
</div>
