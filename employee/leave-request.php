<?php
// Included from dashboard.php — auth and POST handled there.
$user_id = (int) $user['id'];
$message = $leaveRequestMessage ?? '';
$messageType = $leaveRequestMessageType ?? 'danger';
?>

<div class="card">
    <h2>Apply for Time Off / Leave</h2>
    <p style="color: var(--text-muted); margin-bottom: 24px;">
        Submit your leave details. Prior approval is mandatory to prevent out-of-workstation and absence salary deductions.
    </p>

    <?php if($message != "") { ?>
        <div class="alert <?php echo $messageType; ?>">
            <span>⚠️</span>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php } ?>

    <form method="POST" action="dashboard.php?page=leave-request">
        <div class="form-group">
            <label>Reason for Leave</label>
            <textarea name="reason" required placeholder="E.g., Family emergency / Medical appointment..." style="min-height: 120px;"></textarea>
        </div>

        <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 16px;">
            <div class="form-group">
                <label>From Date</label>
                <input type="date" name="from_date" required>
            </div>
            <div class="form-group">
                <label>To Date</label>
                <input type="date" name="to_date" required>
            </div>
        </div>

        <button type="submit" name="submit" class="glowing-element">📅 Submit Leave Request</button>
    </form>
</div>
