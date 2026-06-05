<?php
include_once "../includes/db.php";
include_once "../includes/auth.php";

if($_SESSION['user']['role'] != 'admin'){
    exit("Access Denied");
}

/* FETCH ALL REQUESTS */
$data = $conn->query("
    SELECT l.*, u.name, u.email
    FROM leave_requests l
    JOIN users u ON u.id = l.employee_id
    ORDER BY l.id DESC
");
?>

<div class="page-title">Employee Leave Applications</div>

<!-- LEAVE DATA LOG -->
<div class="card">
    <h2>Leave Applications Queue</h2>

    <div class="table-box">
        <table>
            <tr>
                <th>Employee Name</th>
                <th>Leave Details / Reason</th>
                <th>From Date</th>
                <th>To Date</th>
                <th>Status</th>
                <th>Approval Controls</th>
            </tr>
            <?php if($data && $data->num_rows > 0){ 
                while($row = $data->fetch_assoc()){
                    $status = strtolower(trim($row['status']));
                    $badgeClass = "warning";
                    if($status === 'approved') $badgeClass = "success";
                    if($status === 'rejected') $badgeClass = "danger";
            ?>
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars($row['name']); ?></strong>
                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo htmlspecialchars($row['email']); ?></div>
                    </td>

                    <td style="max-width: 250px; line-height: 1.5; word-wrap: break-word; color: var(--text-main);">
                        <?php echo htmlspecialchars($row['reason']); ?>
                    </td>

                    <td>📅 <?php echo date('d M Y', strtotime($row['from_date'])); ?></td>
                    
                    <td>📅 <?php echo date('d M Y', strtotime($row['to_date'])); ?></td>

                    <td>
                        <span class="badge <?php echo $badgeClass; ?>">
                            <?php echo strtoupper($status); ?>
                        </span>
                    </td>

                    <td>
                        <?php if($status === 'pending'){ ?>
                            <div style="display: flex; gap: 8px;">
                                <a href="dashboard.php?page=leave-requests&action=approved&id=<?php echo $row['id']; ?>" class="btn" style="background: linear-gradient(135deg, var(--success) 0%, #059669 100%); padding: 8px 14px; font-size: 0.8rem; box-shadow: none;">
                                    ✔️ Approve
                                </a>
                                <a href="dashboard.php?page=leave-requests&action=rejected&id=<?php echo $row['id']; ?>" class="btn btn-danger" style="padding: 8px 14px; font-size: 0.8rem; box-shadow: none;">
                                    ❌ Reject
                                </a>
                            </div>
                        <?php } else { ?>
                            <span style="font-size: 0.85rem; color: var(--text-muted); font-weight: 600;">Processed</span>
                        <?php } ?>
                    </td>
                </tr>
            <?php } 
            } else { ?>
                <tr>
                    <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 30px;">
                        No leave applications submitted yet.
                    </td>
                </tr>
            <?php } ?>
        </table>
    </div>
</div>