<?php
// Included from dashboard.php — auth and POST submission handled there.
$employee_id = (int) $user['id'];
$message = $hourlyUpdateMessage ?? '';
$messageType = $hourlyUpdateMessageType ?? 'danger';

$activeShift = $conn->query("
    SELECT id, start_time FROM shifts
    WHERE employee_id='$employee_id' AND status='active'
    LIMIT 1
")->fetch_assoc();

$slots = [];
$currentSlot = null;
$dbNowTs = getDatabaseNowTimestamp($conn);

if ($activeShift) {
    $slots = getHourlySlotDefinitionsForShift($activeShift['start_time']);
    $currentSlot = findHourlySlotForTimestamp($slots, $dbNowTs);
}
?>

<div class="card">
    <h2>Hourly Task Update</h2>
    <p style="color: var(--text-muted); margin-bottom: 16px;">
        Submit exactly <strong>one update per hour</strong> during its <strong>15-minute window</strong>
        (e.g. 7:00–7:15 PM). Late or duplicate submissions do not count and may lead to salary deductions.
    </p>

    <?php if($message != "") { ?>
        <div class="alert <?php echo $messageType; ?>">
            <span>⚠️</span>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php } ?>

    <?php if(!$activeShift) { ?>
        <div class="alert info">
            <span>ℹ️</span>
            <span>Start your shift first from the <strong>Start Shift</strong> page.</span>
        </div>
    <?php } else { ?>

        <?php if($currentSlot) {
            $slotTaken = hasHourlyUpdateInSlot($conn, $employee_id, (int)$activeShift['id'], $currentSlot['slot_date'], $currentSlot['slot_hour']);
        ?>
            <div class="alert <?php echo $slotTaken ? 'warning' : 'success'; ?>" style="margin-bottom: 20px;">
                <span><?php echo $slotTaken ? '✓' : '⏰'; ?></span>
                <span>
                    <strong>Current window:</strong> <?php echo htmlspecialchars($currentSlot['label']); ?>
                    <?php if($slotTaken) { ?>
                        — already submitted for this slot.
                    <?php } else { ?>
                        — you may submit now.
                    <?php } ?>
                </span>
            </div>
        <?php } else { ?>
            <div class="alert warning" style="margin-bottom: 20px;">
                <span>⚠️</span>
                <span>
                    <strong>No active submission window.</strong> Wait for the next slot (see schedule below).
                    Submitting outside a window counts as a <strong>missed update</strong>.
                </span>
            </div>
        <?php } ?>

        <div class="card" style="padding: 16px; margin-bottom: 24px; background: rgba(255,255,255,0.02);">
            <h3 style="margin: 0 0 12px 0; font-size: 1rem;">Today's slot schedule</h3>
            <div class="table-box">
                <table>
                    <tr>
                        <th>Time window</th>
                        <th>Status</th>
                    </tr>
                    <?php foreach($slots as $slot) {
                        $filled = hasHourlyUpdateInSlot($conn, $employee_id, (int)$activeShift['id'], $slot['slot_date'], $slot['slot_hour']);
                        if (!isHourlySlotRequiredForShift($slot, $activeShift['start_time'])) {
                            $status = 'N/A (before clock-in)';
                            $badge = 'warning';
                        } elseif ($filled) {
                            $status = 'Submitted';
                            $badge = 'success';
                        } elseif ($dbNowTs > $slot['end_ts']) {
                            $status = 'Missed';
                            $badge = 'danger';
                        } elseif ($dbNowTs >= $slot['start_ts'] && $dbNowTs <= $slot['end_ts']) {
                            $status = 'Open now';
                            $badge = 'warning';
                        } else {
                            $status = 'Upcoming';
                            $badge = 'warning';
                        }
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($slot['label']); ?></td>
                        <td><span class="badge <?php echo $badge; ?>"><?php echo $status; ?></span></td>
                    </tr>
                    <?php } ?>
                </table>
            </div>
        </div>

        <?php
        $canSubmit = $currentSlot && !hasHourlyUpdateInSlot($conn, $employee_id, (int)$activeShift['id'], $currentSlot['slot_date'], $currentSlot['slot_hour']);
        ?>

        <form method="POST" action="dashboard.php?page=hourly-update">
            <div class="form-group">
                <label>Describe Your Current Work</label>
                <textarea
                    name="update_text"
                    placeholder="E.g., Troubleshooting database connection issues / Working on styling components..."
                    required
                    style="min-height: 150px;"
                    <?php echo $canSubmit ? '' : 'disabled'; ?>
                ></textarea>
            </div>

            <button type="submit" name="submit" class="glowing-element" <?php echo $canSubmit ? '' : 'disabled'; ?>>
                🚀 Submit Progress Log
            </button>
            <?php if(!$canSubmit && $currentSlot) { ?>
                <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: 10px;">
                    This slot already has a submission.
                </p>
            <?php } elseif(!$canSubmit) { ?>
                <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: 10px;">
                    Form unlocks when the next 15-minute window opens.
                </p>
            <?php } ?>
        </form>

    <?php } ?>
</div>

<?php if (isset($_SESSION['hourly_success_popup'])) {
    $hourlyPopup = $_SESSION['hourly_success_popup'];
    unset($_SESSION['hourly_success_popup']);
?>
<div id="hourly-success-modal" class="success-modal-overlay is-open" role="dialog" aria-modal="true" aria-labelledby="hourly-success-title">
    <div class="success-modal">
        <div class="success-modal-icon">✅</div>
        <h3 id="hourly-success-title">Hourly Update Submitted</h3>
        <p>
            Your progress log for <strong><?php echo htmlspecialchars($hourlyPopup['slot']); ?></strong>
            was saved successfully.
        </p>
        <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 20px;">
            Submitted at <?php echo htmlspecialchars($hourlyPopup['submitted_at']); ?>
        </p>
        <button type="button" class="btn glowing-element" id="hourly-success-close">Got it</button>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById('hourly-success-modal');
    var closeBtn = document.getElementById('hourly-success-close');
    function closeModal() {
        if (modal) {
            modal.classList.remove('is-open');
        }
    }
    if (closeBtn) {
        closeBtn.addEventListener('click', closeModal);
    }
    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                closeModal();
            }
        });
    }
})();
</script>
<?php } ?>
