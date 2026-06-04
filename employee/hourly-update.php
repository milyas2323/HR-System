<?php
include "../includes/db.php";
include "../includes/auth.php";
include "../includes/functions.php";

if($_SESSION['user']['role'] != 'employee'){
    header("Location: ../login.php");
    exit();
}

$employee_id = (int) $_SESSION['user']['id'];
$message = "";
$messageType = "danger";

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

if(isset($_POST['submit'])){
    $update_text = trim($_POST['update_text']);

    if(empty($update_text)){
        $message = "Please write a summary of your task before submitting.";
    } elseif(!$activeShift){
        $message = "You must start your shift before submitting hourly updates.";
    } else {
        $shift_id = (int) $activeShift['id'];
        $slot = findHourlySlotForTimestamp($slots, $dbNowTs);

        if(!$slot){
            $message = "Submission rejected: updates are only accepted in each 15-minute window (e.g. 7:00–7:15 PM). The current time is outside all valid slots.";
        } elseif(hasHourlyUpdateInSlot($conn, $employee_id, $shift_id, $slot['slot_date'], $slot['slot_hour'])){
            $message = "You already submitted an update for the " . $slot['label'] . " slot. Duplicate entries are not allowed.";
        } else {
            $stmt = $conn->prepare("
                INSERT INTO hourly_updates (employee_id, shift_id, slot_date, slot_hour, update_text)
                VALUES (?, ?, ?, ?, ?)
            ");
            $slot_hour = (int) $slot['slot_hour'];
            $stmt->bind_param("iisis", $employee_id, $shift_id, $slot['slot_date'], $slot_hour, $update_text);

            if($stmt->execute()){
                $_SESSION['msg'] = "Hourly update submitted for " . $slot['label'] . " slot.";
                echo "<script>window.location.href='dashboard.php';</script>";
                exit();
            } else {
                if (strpos($conn->error, 'uniq_employee_shift_slot') !== false) {
                    $message = "Duplicate blocked: an update for this time slot was already recorded.";
                } else {
                    $message = "Database Error: " . $conn->error;
                }
            }
        }
    }
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
                        if ($filled) {
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

        <form method="POST">
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
