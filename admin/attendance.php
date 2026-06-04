<?php
include "../includes/db.php";
include "../includes/auth.php";

if($_SESSION['user']['role'] != 'admin'){
    exit("Access Denied");
}

/* =========================
   RESET ATTENDANCE DATA
   ========================= */
if(isset($_POST['reset_attendance'])){
    // Delete all shift attendance records
    $conn->query("DELETE FROM shifts");
    $_SESSION['msg'] = "Attendance data reset successfully!";
    echo "<script>window.location.href='dashboard.php?page=attendance';</script>";
    exit();
}

/* STATISTICS */
$totalAttendance = $conn->query("SELECT COUNT(*) as total FROM shifts")->fetch_assoc();
$activeShifts = $conn->query("SELECT COUNT(*) as total FROM shifts WHERE status='active'")->fetch_assoc();
$closedShifts = $conn->query("SELECT COUNT(*) as total FROM shifts WHERE status='closed'")->fetch_assoc();
?>

<div class="page-title">Workplace Attendance Audit Log</div>

<!-- SYSTEM ACTIONS -->
<div style="display: flex; justify-content: flex-end; margin-bottom: 20px;">
    <form method="POST" onsubmit="return confirm('WARNING: Are you sure you want to permanently clear ALL attendance shifts? This action is irreversible!');">
        <button type="submit" name="reset_attendance" class="btn-danger">
            ⚠️ Reset All Attendance Records
        </button>
    </form>
</div>

<!-- STATS SUMMARY GRID -->
<div class="grid">
    <div class="card stat-box">
        <h4>Total Audit Records</h4>
        <h2><?php echo $totalAttendance['total'] ?? 0; ?></h2>
    </div>

    <div class="card stat-box" style="border-bottom: 4px solid var(--success);">
        <h4>Active Workshifts</h4>
        <h2 style="color: var(--success);"><?php echo $activeShifts['total'] ?? 0; ?></h2>
    </div>

    <div class="card stat-box">
        <h4>Closed Shifts</h4>
        <h2><?php echo $closedShifts['total'] ?? 0; ?></h2>
    </div>
</div>

<!-- LOG DATA TABLE -->
<div class="card">
    <h2>Attendance Shifts</h2>

    <div class="table-box">
        <table>
            <tr>
                <th>Employee</th>
                <th>Workday Screenshot</th>
                <th>Morning Message</th>
                <th>Clock In / Out</th>
                <th>Details (IP / Agent)</th>
                <th>Status</th>
                <th>Check-In Location</th>
            </tr>
            <?php
            $logs = $conn->query("
                SELECT s.*, u.name
                FROM shifts s
                JOIN users u ON u.id = s.employee_id
                ORDER BY s.id DESC
            ");

            if($logs && $logs->num_rows > 0){
                while($row = $logs->fetch_assoc()){
                    $status = strtolower(trim($row['status']));
                    $badgeClass = ($status === 'active') ? 'success' : 'danger';
            ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                    
                    <td>
                        <?php if(!empty($row['screenshot'])){ ?>
                            <a href="../uploads/screenshots/<?php echo $row['screenshot']; ?>" target="_blank">
                                <img src="../uploads/screenshots/<?php echo $row['screenshot']; ?>" class="screenshot" alt="Screenshot preview">
                            </a>
                        <?php } else { ?>
                            <span class="badge warning" style="font-size: 0.7rem;">No Screen Capture</span>
                        <?php } ?>
                    </td>

                    <td style="max-width: 180px; font-style: italic; color: var(--text-muted); word-wrap: break-word;">
                        "<?php echo htmlspecialchars($row['morning_message'] ? $row['morning_message'] : '-'); ?>"
                    </td>

                    <td style="font-size: 0.85rem;">
                        <div>🟢 <strong style="color: var(--success);"><?php echo date('h:i A', strtotime($row['start_time'])); ?></strong> (<?php echo date('d M Y', strtotime($row['start_time'])); ?>)</div>
                        <?php if(!empty($row['end_time'])){ ?>
                            <div style="margin-top: 5px;">🔴 <strong style="color: var(--danger);"><?php echo date('h:i A', strtotime($row['end_time'])); ?></strong> (<?php echo date('d M Y', strtotime($row['end_time'])); ?>)</div>
                        <?php } else { ?>
                            <div style="margin-top: 5px; color: var(--text-muted);">🔴 Open Shift</div>
                        <?php } ?>
                    </td>

                    <td style="font-size: 0.8rem; color: var(--text-muted);">
                        <div>IP: <strong style="color: var(--text-main);"><?php echo htmlspecialchars($row['ip_address'] ? $row['ip_address'] : 'Dev Loop'); ?></strong></div>
                        <div style="margin-top: 2px;">UA: <?php echo htmlspecialchars($row['device']); ?></div>
                    </td>

                    <td>
                        <span class="badge <?php echo $badgeClass; ?>">
                            <?php echo strtoupper($status); ?>
                        </span>
                    </td>

                    <td style="max-width: 220px; font-size: 0.8rem; line-height: 1.4;">
                        <?php if($row['current_location'] && $row['current_location'] !== 'Unknown Location'){ ?>
                            <div style="font-weight: 600; color: var(--accent);">📍 <?php echo htmlspecialchars(explode(',', $row['current_location'])[0]); ?></div>
                            <div style="color: var(--text-muted); font-size: 0.75rem; margin-top: 2px;">
                                Coords: <?php echo htmlspecialchars($row['current_latitude'] ? $row['current_latitude'] : '0.0'); ?>, <?php echo htmlspecialchars($row['current_longitude'] ? $row['current_longitude'] : '0.0'); ?>
                            </div>
                        <?php } else { ?>
                            <span class="badge danger" style="font-size: 0.7rem;">No Location</span>
                        <?php } ?>
                    </td>
                </tr>
            <?php } 
            } else { ?>
                <tr>
                    <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 30px;">
                        No workday shifts logged in the system.
                    </td>
                </tr>
            <?php } ?>
        </table>
    </div>
</div>