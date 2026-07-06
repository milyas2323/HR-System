<?php
include_once "../includes/db.php";
include_once "../includes/auth.php";
include_once "../includes/functions.php";

if ($_SESSION['user']['role'] != 'admin') {
    exit("Access Denied");
}

$employee_id = isset($_GET['employee_id']) ? (int) $_GET['employee_id'] : 0;
if ($employee_id < 0) {
    $employee_id = 0;
}

$employees = [];
$empResult = $conn->query("SELECT id, name, email FROM users WHERE role='employee' ORDER BY name ASC");
if ($empResult) {
    while ($row = $empResult->fetch_assoc()) {
        $employees[] = $row;
    }
}

$selectedEmployee = null;
$activeShift = null;
$slots = [];

if ($employee_id > 0) {
    foreach ($employees as $emp) {
        if ((int) $emp['id'] === $employee_id) {
            $selectedEmployee = $emp;
            break;
        }
    }

    $activeShift = $conn->query("
        SELECT id, start_time, status
        FROM shifts
        WHERE employee_id='$employee_id' AND status='active'
        ORDER BY start_time DESC
        LIMIT 1
    ")->fetch_assoc();

    if ($activeShift) {
        $slots = getHourlySlotDefinitionsForShift($activeShift['start_time']);
    }
}

$hourlySubmitMessage = $_SESSION['hourly_submit_msg'] ?? '';
$hourlySubmitMessageType = $_SESSION['hourly_submit_msg_type'] ?? 'danger';
unset($_SESSION['hourly_submit_msg'], $_SESSION['hourly_submit_msg_type']);
?>

<div class="page-title">Submit Hourly Update for Employee</div>

<div class="card" style="margin-bottom: 20px;">
    <div class="alert info" style="margin-bottom: 0;">
        <span>ℹ️</span>
        <span>
            Submit a real hourly update on behalf of an employee. Updates appear in the normal hourly logs
            and count for that employee. <strong>No 15-minute time window</strong> is enforced for admin.
            Employee must have an <strong>active shift</strong>.
        </span>
    </div>
</div>

<?php if ($hourlySubmitMessage !== '') { ?>
    <div class="alert <?php echo htmlspecialchars($hourlySubmitMessageType); ?>" style="margin-bottom: 20px;">
        <span><?php echo $hourlySubmitMessageType === 'success' ? '✅' : '⚠️'; ?></span>
        <span><?php echo htmlspecialchars($hourlySubmitMessage); ?></span>
    </div>
<?php } ?>

<div class="card">
    <h2>Hourly Task Update <span class="badge warning" style="margin-left: 8px;">Admin submit</span></h2>

    <form method="GET" action="dashboard.php" style="margin-bottom: 24px;">
        <input type="hidden" name="page" value="hourly-submit">
        <div class="form-group" style="margin-bottom: 0;">
            <label>Select employee</label>
            <div style="display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;">
                <select name="employee_id" required style="max-width: 280px; min-width: 220px;">
                    <option value="">Choose employee…</option>
                    <?php foreach ($employees as $emp) { ?>
                        <option value="<?php echo (int) $emp['id']; ?>" <?php echo $employee_id === (int) $emp['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($emp['name']); ?> (<?php echo htmlspecialchars($emp['email']); ?>)
                        </option>
                    <?php } ?>
                </select>
                <button type="submit" class="btn glowing-element">Load employee</button>
                <a href="dashboard.php?page=hourly-update" class="btn-secondary" style="display: inline-flex; align-items: center; padding: 10px 16px; text-decoration: none;">
                    ← Back to Hourly Logs
                </a>
            </div>
        </div>
    </form>

    <?php if (!$selectedEmployee) { ?>
        <div class="alert warning">
            <span>⚠️</span>
            <span>Select an employee above to submit an hourly update on their behalf.</span>
        </div>
    <?php } elseif (!$activeShift) { ?>
        <div class="alert danger">
            <span>⚠️</span>
            <span><strong><?php echo htmlspecialchars($selectedEmployee['name']); ?></strong> has no active shift. They must start a shift before you can submit hourly updates for them.</span>
        </div>
    <?php } else { ?>

        <div class="alert success" style="margin-bottom: 20px;">
            <span>✓</span>
            <span>
                Active shift for <strong><?php echo htmlspecialchars($selectedEmployee['name']); ?></strong>
                — started <?php echo date('d M Y, h:i A', strtotime($activeShift['start_time'])); ?>.
            </span>
        </div>

        <div class="card" style="padding: 16px; margin-bottom: 24px; background: rgba(255,255,255,0.02);">
            <h3 style="margin: 0 0 12px 0; font-size: 1rem;">Slot schedule</h3>
            <div class="table-box">
                <table>
                    <tr>
                        <th>Time window</th>
                        <th>Status</th>
                    </tr>
                    <?php foreach ($slots as $slot) {
                        $filled = hasHourlyUpdateInSlot($conn, $employee_id, (int) $activeShift['id'], $slot['slot_date'], $slot['slot_hour']);
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($slot['label']); ?></td>
                            <td>
                                <span class="badge <?php echo $filled ? 'success' : 'warning'; ?>">
                                    <?php echo $filled ? 'Submitted' : 'Available'; ?>
                                </span>
                            </td>
                        </tr>
                    <?php } ?>
                </table>
            </div>
        </div>

        <form method="POST" action="dashboard.php?page=hourly-submit&amp;employee_id=<?php echo $employee_id; ?>">
            <input type="hidden" name="admin_hourly_check" value="1">
            <input type="hidden" name="employee_id" value="<?php echo $employee_id; ?>">

            <div class="form-group">
                <label>Hourly slot <span style="color: var(--text-muted); font-weight: normal;">(any slot — no time check)</span></label>
                <select name="slot_key" required style="max-width: 320px;">
                    <?php foreach ($slots as $slot) {
                        $key = $slot['slot_date'] . '|' . (int) $slot['slot_hour'];
                        $filled = hasHourlyUpdateInSlot($conn, $employee_id, (int) $activeShift['id'], $slot['slot_date'], $slot['slot_hour']);
                        if ($filled) {
                            continue;
                        }
                    ?>
                        <option value="<?php echo htmlspecialchars($key); ?>">
                            <?php echo htmlspecialchars($slot['label']); ?>
                        </option>
                    <?php } ?>
                </select>
            </div>

            <div class="form-group">
                <label>Describe work activity</label>
                <textarea
                    name="update_text"
                    placeholder="E.g., Completed task X for this employee…"
                    required
                    style="min-height: 150px;"
                ></textarea>
            </div>

            <button type="submit" class="glowing-element">
                🚀 Submit Hourly Update
            </button>
        </form>

    <?php } ?>
</div>
