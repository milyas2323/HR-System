<?php
include "../includes/db.php";
include "../includes/auth.php";

if($_SESSION['user']['role'] != 'admin'){
    exit("Access Denied");
}

$employee_id = $_GET['employee_id'] ?? '';
?>

<div class="page-title">Workforce Audit Reports</div>

<!-- SELECT EMPLOYEE DROPDOWN -->
<div class="card">
    <form method="GET" action="dashboard.php" id="reportSelectForm">
        <input type="hidden" name="page" value="reports">
        
        <div class="form-group" style="margin-bottom: 0;">
            <label>Choose Employee to View Audits</label>
            <select name="employee_id" onchange="document.getElementById('reportSelectForm').submit()" style="max-width: 400px;">
                <option value="">-- Choose Employee --</option>
                <?php
                $empsList = $conn->query("SELECT id, name, email FROM users WHERE role='employee' ORDER BY name ASC");
                while($e = $empsList->fetch_assoc()){
                ?>
                    <option value="<?php echo $e['id']; ?>" <?php if($employee_id == $e['id']) echo "selected"; ?>>
                        <?php echo htmlspecialchars($e['name']); ?> (<?php echo htmlspecialchars($e['email']); ?>)
                    </option>
                <?php } ?>
            </select>
        </div>
    </form>
</div>

<!-- AUDIT RESULTS -->
<?php if(empty($employee_id)){ ?>
    <div class="card">
        <p style="color: var(--text-muted); text-align: center; padding: 20px;">
            Please select an employee from the dropdown above to view their shift logs, hourly updates, and login history.
        </p>
    </div>
<?php } else { 
    $empDetails = $conn->query("SELECT * FROM users WHERE id='$employee_id' AND role='employee'")->fetch_assoc();
    if(!$empDetails){
        echo '<div class="alert danger">Employee record not found.</div>';
    } else {
        // Fetch current database time to prevent timezone offset discrepancies with NOW()
        $dbNowQuery = $conn->query("SELECT NOW() as db_now");
        $dbNow = ($dbNowQuery && $dbNowQuery->num_rows > 0) ? $dbNowQuery->fetch_assoc()['db_now'] : date('Y-m-d H:i:s');

        // Stats calculations
        $updatesCount = $conn->query("SELECT COUNT(*) as total FROM hourly_updates WHERE employee_id='$employee_id'")->fetch_assoc();
        $penaltiesSum = $conn->query("SELECT SUM(amount) as total FROM penalties WHERE employee_id='$employee_id'")->fetch_assoc();
        $shiftActive = $conn->query("SELECT COUNT(*) as total FROM shifts WHERE employee_id='$employee_id' AND status='active'")->fetch_assoc();
?>

    <div class="card" style="background: rgba(6, 182, 212, 0.08); border-color: rgba(6, 182, 212, 0.2);">
        <h3 style="margin: 0; color: var(--accent); display: flex; align-items: center; gap: 8px;">
            👤 Auditing Profile: <?php echo htmlspecialchars($empDetails['name']); ?> (<?php echo htmlspecialchars($empDetails['email']); ?>)
        </h3>
    </div>

    <!-- METRICS GRID -->
    <div class="grid">
        <div class="card stat-box">
            <h4>Total Updates Logged</h4>
            <h2><?php echo $updatesCount['total'] ?? 0; ?></h2>
        </div>

        <div class="card stat-box" style="border-bottom: 4px solid var(--danger);">
            <h4>Total Deductions Fined</h4>
            <h2 style="color: var(--danger);">PKR <?php echo number_format($penaltiesSum['total'] ?? 0); ?></h2>
        </div>

        <div class="card stat-box" style="border-bottom: 4px solid var(--success);">
            <h4>Shift Active</h4>
            <h2 style="color: var(--success);"><?php echo ($shiftActive['total'] > 0) ? 'YES' : 'NO'; ?></h2>
        </div>
    </div>

    <!-- SHIFT HISTORY -->
    <div class="card">
        <h2>Shift History</h2>
        <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 20px;">
            Recent check-ins with clock times, duration, device, and location captured at shift start.
        </p>

        <?php
        $shiftsQuery = $conn->query("
            SELECT * FROM shifts 
            WHERE employee_id='$employee_id' 
            ORDER BY id DESC 
            LIMIT 5
        ");

        if ($shiftsQuery && $shiftsQuery->num_rows > 0) {
            while ($shiftRow = $shiftsQuery->fetch_assoc()) {
                $status = strtolower($shiftRow['status']);
                $startTimeStr = $shiftRow['start_time'];
                $endTimeStr = ($status === 'active') ? $dbNow : $shiftRow['end_time'];
                $startTimestamp = strtotime($startTimeStr);
                $endTimestamp = strtotime($endTimeStr);
                $elapsedSeconds = max(0, $endTimestamp - $startTimestamp);
                $elapsedHours = floor($elapsedSeconds / 3600);
                $elapsedMinutes = floor(($elapsedSeconds % 3600) / 60);
        ?>
                <div style="border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 20px; margin-bottom: 20px; background: rgba(255,255,255,0.01);">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 15px; margin-bottom: 12px;">
                        <div>
                            <span class="badge <?php echo ($status === 'active') ? 'success' : 'danger'; ?>" style="margin-bottom: 5px; display: inline-block;">
                                <?php echo strtoupper($status); ?> SHIFT
                            </span>
                            <div style="font-size: 0.95rem; font-weight: 600; margin-top: 5px;">
                                Clock In: <span style="color: var(--text-main);"><?php echo date('h:i A - d M Y', $startTimestamp); ?></span>
                            </div>
                            <?php if ($status !== 'active' && !empty($shiftRow['end_time'])) { ?>
                                <div style="font-size: 0.95rem; font-weight: 600; margin-top: 5px;">
                                    Clock Out: <span style="color: var(--text-main);"><?php echo date('h:i A - d M Y', $endTimestamp); ?></span>
                                </div>
                            <?php } ?>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-size: 0.85rem; color: var(--text-muted);">Duration</div>
                            <div style="font-size: 1.25rem; font-weight: 700; color: var(--accent);"><?php echo "{$elapsedHours}h {$elapsedMinutes}m"; ?></div>
                        </div>
                    </div>
                    <div style="font-size: 0.9rem; color: var(--text-muted); line-height: 1.6;">
                        <?php if (!empty($shiftRow['device'])) { ?>
                            <div>Device: <strong style="color: var(--text-main);"><?php echo htmlspecialchars($shiftRow['device']); ?></strong></div>
                        <?php } ?>
                        <?php if (!empty($shiftRow['ip_address'])) { ?>
                            <div>IP: <strong style="color: var(--text-main);"><?php echo htmlspecialchars($shiftRow['ip_address']); ?></strong></div>
                        <?php } ?>
                        <?php if (!empty($shiftRow['current_location'])) { ?>
                            <div>Location: <strong style="color: var(--text-main);"><?php echo htmlspecialchars($shiftRow['current_location']); ?></strong></div>
                        <?php } ?>
                        <?php if (!empty($shiftRow['morning_message'])) { ?>
                            <div style="margin-top: 8px;">Morning message: <?php echo htmlspecialchars($shiftRow['morning_message']); ?></div>
                        <?php } ?>
                    </div>
                    <?php if (!empty($shiftRow['screenshot'])) { ?>
                        <div style="margin-top: 15px;">
                            <img src="../uploads/screenshots/<?php echo htmlspecialchars($shiftRow['screenshot']); ?>" alt="Shift screenshot" style="max-width: 100%; max-height: 220px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.1);">
                        </div>
                    <?php } ?>
                </div>
        <?php
            }
        } else {
        ?>
            <div style="text-align: center; color: var(--text-muted); padding: 20px;">No shift history recorded for this employee.</div>
        <?php
        }
        ?>
    </div>

    <!-- LOGIN AUDIT HISTORY -->
    <div class="card">
        <h2>System Login Audit History</h2>
        <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 15px;">
            Audits client device agents, physical coordinates, resolved location names, and network IPs logged during account sign-in.
        </p>

        <div class="table-box">
            <table>
                <tr>
                    <th>Sign-in Time</th>
                    <th>Network IP Address</th>
                    <th>Device Details (Browser / OS)</th>
                    <th>Resolved Physical Location</th>
                </tr>
                <?php
                $logins = $conn->query("
                    SELECT * FROM login_logs 
                    WHERE user_id='$employee_id' 
                    ORDER BY id DESC 
                    LIMIT 8
                ");
                if($logins && $logins->num_rows > 0){
                    while($row = $logins->fetch_assoc()){
                ?>
                    <tr>
                        <td>📅 <?php echo date('d M Y - h:i A', strtotime($row['created_at'])); ?></td>
                        <td style="font-weight: 600; color: var(--accent);"><?php echo htmlspecialchars($row['ip_address']); ?></td>
                        <td><?php echo htmlspecialchars($row['device']); ?></td>
                        <td style="font-size: 0.85rem;">
                            📍 <?php echo htmlspecialchars($row['location']); ?>
                            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px;">
                                Coords: <?php echo htmlspecialchars($row['latitude']); ?>, <?php echo htmlspecialchars($row['longitude']); ?>
                            </div>
                        </td>
                    </tr>
                <?php }
                } else { ?>
                    <tr>
                        <td colspan="4" style="text-align: center; color: var(--text-muted); padding: 15px;">No login logs recorded yet.</td>
                    </tr>
                <?php } ?>
            </table>
        </div>
    </div>

    <!-- HOURLY PROGRESS UPDATES -->
    <div class="card">
        <h2>Hourly Progress Logs</h2>
        
        <div class="table-box">
            <table>
                <tr>
                    <th>Time Submitted</th>
                    <th>Date Logged</th>
                    <th>Progress Log text</th>
                </tr>
                <?php
                $progressLogs = $conn->query("
                    SELECT * FROM hourly_updates 
                    WHERE employee_id='$employee_id' 
                    ORDER BY id DESC 
                    LIMIT 10
                ");
                if($progressLogs && $progressLogs->num_rows > 0){
                    while($row = $progressLogs->fetch_assoc()){
                        $ts = strtotime($row['created_at']);
                ?>
                    <tr>
                        <td style="font-weight: 600; color: var(--primary);">⏰ <?php echo date('h:i A', $ts); ?></td>
                        <td style="color: var(--text-muted); font-size: 0.85rem;">📅 <?php echo date('d M Y', $ts); ?></td>
                        <td style="line-height: 1.5; color: var(--text-main); max-width: 500px; word-wrap: break-word;">
                            <?php echo nl2br(htmlspecialchars($row['update_text'])); ?>
                        </td>
                    </tr>
                <?php }
                } else { ?>
                    <tr>
                        <td colspan="3" style="text-align: center; color: var(--text-muted); padding: 15px;">No progress logs found.</td>
                    </tr>
                <?php } ?>
            </table>
        </div>
    </div>

<?php } } ?>