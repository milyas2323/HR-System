<?php
include "../includes/db.php";
include "../includes/auth.php";
include "../includes/functions.php";

if($_SESSION['user']['role'] != 'admin'){
    exit("Access Denied");
}

/* =========================
   RESET HOURLY UPDATES
   ========================= */
if(isset($_POST['reset_updates'])){
    $conn->query("DELETE FROM hourly_updates");
    $_SESSION['msg'] = "Hourly updates log reset successfully!";
    echo "<script>window.location.href='dashboard.php?page=hourly-update';</script>";
    exit();
}

/* SUMMARY DETAILS */
$totalUpdates = $conn->query("SELECT COUNT(*) as total FROM hourly_updates")->fetch_assoc();
$totalEmployees = $conn->query("SELECT COUNT(*) as total FROM users WHERE role='employee'")->fetch_assoc();
$totalActiveShifts = $conn->query("SELECT COUNT(*) as total FROM shifts WHERE status='active'")->fetch_assoc();
?>

<div class="page-title">Hourly Progress Logs Monitor</div>

<!-- SYSTEM ACTIONS -->
<div style="display: flex; justify-content: flex-end; margin-bottom: 20px;">
    <form method="POST" onsubmit="return confirm('WARNING: Are you sure you want to permanently clear ALL employee hourly updates? This is irreversible!');">
        <button type="submit" name="reset_updates" class="btn-danger">
            ⚠️ Clear All Hourly Updates
        </button>
    </form>
</div>

<!-- STATS SUMMARY GRID -->
<div class="grid">
    <div class="card stat-box">
        <h4>Total Updates Logged</h4>
        <h2><?php echo $totalUpdates['total'] ?? 0; ?></h2>
    </div>

    <div class="card stat-box">
        <h4>Active Working Employees</h4>
        <h2><?php echo $totalActiveShifts['total'] ?? 0; ?></h2>
    </div>

    <div class="card stat-box">
        <h4>Employees Monitored</h4>
        <h2><?php echo $totalEmployees['total'] ?? 0; ?></h2>
    </div>
</div>

<!-- DATA FEED TABLE -->
<div class="card">
    <h2>Real-Time Progress Feed</h2>

    <div class="table-box">
        <table>
            <tr>
                <th>Employee Name</th>
                <th>Slot window</th>
                <th>Hourly Logged Work Activity</th>
                <th>Time Submitted</th>
                <th>Date Logged</th>
            </tr>
            <?php
            $data = $conn->query("
                SELECT h.*, u.name
                FROM hourly_updates h
                JOIN users u ON u.id = h.employee_id
                ORDER BY h.id DESC
            ");

            if($data && $data->num_rows > 0){
                while($row = $data->fetch_assoc()){
                    $timestamp = strtotime($row['created_at']);
                    $slotLabel = '—';
                    if (!empty($row['slot_date']) && $row['slot_hour'] !== null && $row['slot_hour'] !== '') {
                        $slotLabel = formatHourlySlotLabel($row['slot_date'], $row['slot_hour']);
                    }
            ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                    <td style="font-size: 0.85rem; color: var(--accent); white-space: nowrap;"><?php echo htmlspecialchars($slotLabel); ?></td>
                    
                    <td style="max-width: 450px; line-height: 1.6; word-wrap: break-word; color: var(--text-main);">
                        <?php echo nl2br(htmlspecialchars($row['update_text'])); ?>
                    </td>

                    <td style="color: var(--primary); font-weight: 600;">
                        ⏰ <?php echo date('h:i A', $timestamp); ?>
                    </td>

                    <td style="color: var(--text-muted); font-size: 0.85rem;">
                        📅 <?php echo date('d M Y', $timestamp); ?>
                    </td>
                </tr>
            <?php } 
            } else { ?>
                <tr>
                    <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px;">
                        No hourly updates submitted today.
                    </td>
                </tr>
            <?php } ?>
        </table>
    </div>
</div>