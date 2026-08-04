<?php
include_once "../includes/db.php";
include_once "../includes/functions.php";

if($_SESSION['user']['role'] != 'admin'){
    exit("Access Denied");
}

$allowedStatuses = ['pending', 'approved', 'rejected', 'all'];
$statusFilter = strtolower(trim($_GET['status'] ?? 'pending'));
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'pending';
}

$requestCounts = getEmployeeRequestStatusCounts($conn);
$requests = getEmployeeRequests($conn, null, $statusFilter);
$requestTypes = getEmployeeRequestTypes();

$statusTabs = [
    'pending' => 'Pending (' . (int) $requestCounts['pending'] . ')',
    'approved' => 'Approved (' . (int) $requestCounts['approved'] . ')',
    'rejected' => 'Rejected (' . (int) $requestCounts['rejected'] . ')',
    'all' => 'All (' . (int) $requestCounts['total'] . ')',
];
?>

<?php if (isset($_SESSION['request_msg'])) { ?>
    <div class="alert <?php echo ($_SESSION['request_msg_type'] ?? 'success') === 'success' ? 'success' : 'danger'; ?>" style="margin-bottom: 16px;">
        <span><?php echo ($_SESSION['request_msg_type'] ?? 'success') === 'success' ? '✅' : '⚠️'; ?></span>
        <span><?php echo htmlspecialchars($_SESSION['request_msg']); unset($_SESSION['request_msg'], $_SESSION['request_msg_type']); ?></span>
    </div>
<?php } ?>

<div class="page-title">Employee Requests</div>

<!-- STATUS FILTER -->
<div class="card" style="margin-bottom: 24px;">
    <div class="form-group" style="margin-bottom: 0;">
        <label>Filter by status</label>
        <div style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center;">
            <?php foreach($statusTabs as $key => $label){ ?>
                <a href="dashboard.php?page=employee-requests&amp;status=<?php echo $key; ?>"
                   class="badge <?php echo ($statusFilter === $key) ? 'success' : 'warning'; ?>"
                   style="text-decoration: none; padding: 8px 14px;">
                    <?php echo htmlspecialchars($label); ?>
                </a>
            <?php } ?>
        </div>
    </div>
    <div class="alert info" style="margin-top: 14px; margin-bottom: 0;">
        <span>ℹ️</span>
        <span>Employees submit late joining, urgent issue, extended break and similar requests here. Approve or reject each one — your remarks are shown back to the employee.</span>
    </div>
</div>

<!-- PENALTY POLICY CAUTION -->
<div class="alert danger" style="margin-bottom: 24px;">
    <span>⚠️</span>
    <span>
        <strong>Penalty policy:</strong> rejecting a request applies a
        <strong>PKR <?php echo number_format(REQUEST_VIOLATION_PENALTY_AMOUNT); ?></strong> fine automatically,
        because the shift change went ahead without authorisation. Untick
        <em>Apply penalty</em> before rejecting if the employee simply asked in advance and did not go ahead.
        Approving a request clears any fine already logged for that employee, type and date.
    </span>
</div>

<!-- SUMMARY STATS -->
<div class="summary" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 24px; margin-bottom: 30px;">
    <div class="card stat-box" style="margin-bottom: 0; border-bottom: 4px solid var(--warning);">
        <h4>Pending Review</h4>
        <h2 style="color: var(--warning);"><?php echo (int) $requestCounts['pending']; ?></h2>
    </div>
    <div class="card stat-box" style="margin-bottom: 0; border-bottom: 4px solid var(--success);">
        <h4>Approved</h4>
        <h2 style="color: var(--success);"><?php echo (int) $requestCounts['approved']; ?></h2>
    </div>
    <div class="card stat-box" style="margin-bottom: 0; border-bottom: 4px solid var(--danger);">
        <h4>Rejected</h4>
        <h2 style="color: var(--danger);"><?php echo (int) $requestCounts['rejected']; ?></h2>
    </div>
    <div class="card stat-box" style="margin-bottom: 0;">
        <h4>Total Requests</h4>
        <h2><?php echo (int) $requestCounts['total']; ?></h2>
    </div>
</div>

<!-- REQUEST QUEUE -->
<div class="card">
    <h2>
        <?php echo $statusFilter === 'all' ? 'All Requests' : ucfirst($statusFilter) . ' Requests'; ?>
    </h2>

    <div class="table-box">
        <table>
            <tr>
                <th>Employee</th>
                <th>Type</th>
                <th>Subject / Details</th>
                <th>Applies To</th>
                <th>Status</th>
                <th>Approval Controls</th>
            </tr>
            <?php if(count($requests) > 0){
                foreach($requests as $req){
                    $meta = getEmployeeRequestTypeMeta($req['request_type']);
                    $status = strtolower(trim($req['status']));
                    $requestId = (int) $req['id'];
            ?>
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars($req['employee_name'] ?? 'Unknown employee'); ?></strong>
                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo htmlspecialchars($req['employee_email'] ?? ''); ?></div>
                        <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 4px;">
                            Sent <?php echo date('d M Y h:i A', strtotime($req['created_at'])); ?>
                        </div>
                    </td>

                    <td style="white-space: nowrap;">
                        <?php echo $meta['icon']; ?> <strong><?php echo htmlspecialchars($meta['label']); ?></strong>
                    </td>

                    <td style="max-width: 280px; line-height: 1.5; word-wrap: break-word;">
                        <strong><?php echo htmlspecialchars($req['subject']); ?></strong>
                        <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 4px;">
                            <?php echo nl2br(htmlspecialchars($req['details'])); ?>
                        </div>
                    </td>

                    <td style="white-space: nowrap; font-size: 0.85rem;">
                        📅 <?php echo !empty($req['request_date']) ? date('d M Y', strtotime($req['request_date'])) : '-'; ?>
                        <?php if(!empty($req['from_time']) || !empty($req['to_time'])){ ?>
                            <div style="color: var(--text-muted); font-size: 0.8rem; margin-top: 4px;">
                                🕒 <?php echo !empty($req['from_time']) ? date('h:i A', strtotime($req['from_time'])) : '—'; ?>
                                to <?php echo !empty($req['to_time']) ? date('h:i A', strtotime($req['to_time'])) : '—'; ?>
                            </div>
                        <?php } ?>
                    </td>

                    <td>
                        <span class="badge <?php echo getEmployeeRequestStatusBadge($status); ?>">
                            <?php echo strtoupper($status); ?>
                        </span>
                        <?php if(!empty($req['penalty_applied'])){ ?>
                            <div style="margin-top: 6px;">
                                <span class="badge danger" style="font-size: 0.7rem;">
                                    − PKR <?php echo number_format(floatval($req['penalty_amount'])); ?>
                                </span>
                            </div>
                        <?php } ?>
                    </td>

                    <td style="min-width: 240px;">
                        <?php if($status === 'pending'){ ?>
                            <form method="POST" action="dashboard.php?page=employee-requests&amp;status=<?php echo urlencode($statusFilter); ?>" style="margin: 0;">
                                <input type="hidden" name="request_id" value="<?php echo $requestId; ?>">
                                <input type="text" name="remarks" placeholder="Remarks (optional)" maxlength="500" style="margin-bottom: 8px; font-size: 0.8rem;">
                                <label style="display: flex; align-items: center; gap: 6px; font-size: 0.75rem; color: var(--text-muted); margin-bottom: 8px; font-weight: 400;">
                                    <input type="checkbox" name="apply_penalty" value="1" checked style="width: auto; margin: 0;">
                                    Apply PKR <?php echo number_format(REQUEST_VIOLATION_PENALTY_AMOUNT); ?> penalty on reject
                                </label>
                                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                    <button type="submit" name="approve_request" class="btn" style="background: linear-gradient(135deg, var(--success) 0%, #059669 100%); padding: 8px 14px; font-size: 0.8rem; box-shadow: none; margin: 0;">
                                        ✔️ Approve
                                    </button>
                                    <button type="submit" name="reject_request" class="btn-danger" style="padding: 8px 14px; font-size: 0.8rem; box-shadow: none; margin: 0;">
                                        ❌ Reject
                                    </button>
                                </div>
                            </form>
                        <?php } else { ?>
                            <div style="font-size: 0.85rem; color: var(--text-muted); line-height: 1.5;">
                                <?php echo htmlspecialchars($req['admin_response'] ?? 'Processed'); ?>
                                <?php if(!empty($req['reviewed_at'])){ ?>
                                    <div style="font-size: 0.75rem; margin-top: 4px;">
                                        — <?php echo htmlspecialchars($req['reviewed_by_name'] ?? 'Admin'); ?>,
                                        <?php echo date('d M Y h:i A', strtotime($req['reviewed_at'])); ?>
                                    </div>
                                <?php } ?>
                            </div>
                        <?php } ?>
                    </td>
                </tr>
            <?php }
            } else { ?>
                <tr>
                    <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 30px;">
                        No <?php echo $statusFilter === 'all' ? '' : htmlspecialchars($statusFilter) . ' '; ?>requests found.
                    </td>
                </tr>
            <?php } ?>
        </table>
    </div>
</div>

<!-- LOG A VIOLATION WITH NO REQUEST -->
<div class="card">
    <h2>Log Unrequested Violation</h2>
    <p style="color: var(--text-muted); margin-bottom: 20px;">
        Use this when an employee joined late, took an extended break, signed off early or changed workstation
        <strong>without submitting any request</strong>. It applies the PKR
        <?php echo number_format(REQUEST_VIOLATION_PENALTY_AMOUNT); ?> fine and records a rejected entry in the
        employee's own request history so they can see why they were fined.
    </p>

    <form method="POST" action="dashboard.php?page=employee-requests&amp;status=<?php echo urlencode($statusFilter); ?>">
        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px;">
            <div class="form-group">
                <label>Employee</label>
                <select name="employee_id" required>
                    <option value="">-- Choose Employee --</option>
                    <?php
                    $violationEmps = $conn->query("SELECT id, name, email FROM users WHERE role='employee' ORDER BY name ASC");
                    while($empRow = $violationEmps->fetch_assoc()){
                    ?>
                        <option value="<?php echo $empRow['id']; ?>">
                            <?php echo htmlspecialchars($empRow['name']); ?> (<?php echo htmlspecialchars($empRow['email']); ?>)
                        </option>
                    <?php } ?>
                </select>
            </div>

            <div class="form-group">
                <label>Violation Type</label>
                <select name="request_type" required>
                    <option value="">-- Choose Type --</option>
                    <?php foreach($requestTypes as $key => $meta){ ?>
                        <option value="<?php echo htmlspecialchars($key); ?>">
                            <?php echo $meta['icon'] . ' ' . htmlspecialchars($meta['label']); ?>
                        </option>
                    <?php } ?>
                </select>
            </div>

            <div class="form-group">
                <label>Date It Happened</label>
                <input type="date" name="violation_date" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
        </div>

        <div class="form-group" style="margin-bottom: 20px;">
            <label>Note <span style="color: var(--text-muted); font-weight: 400;">(optional)</span></label>
            <input type="text" name="note" maxlength="500" placeholder="e.g. Joined 90 minutes late with no prior notice">
        </div>

        <button type="submit" name="log_violation" class="btn-danger glowing-element"
                onclick="return confirm('Apply a PKR <?php echo number_format(REQUEST_VIOLATION_PENALTY_AMOUNT); ?> penalty for this unrequested violation?');">
            ⚠️ Log Violation &amp; Apply PKR <?php echo number_format(REQUEST_VIOLATION_PENALTY_AMOUNT); ?> Penalty
        </button>
    </form>

    <p style="color: var(--text-muted); font-size: 0.8rem; margin-top: 14px;">
        The same employee, type and date is never fined twice, and an existing <strong>approved</strong> request
        for that day blocks the penalty.
    </p>
</div>

<!-- REQUEST TYPE LEGEND -->
<div class="card">
    <h2>Request Types</h2>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; margin-top: 12px;">
        <?php foreach($requestTypes as $meta){ ?>
            <div style="padding: 12px 14px; border: 1px solid var(--panel-border); border-radius: 10px;">
                <strong><?php echo $meta['icon'] . ' ' . htmlspecialchars($meta['label']); ?></strong>
                <p style="color: var(--text-muted); font-size: 0.8rem; margin-top: 6px;">
                    <?php echo htmlspecialchars($meta['hint']); ?>
                </p>
            </div>
        <?php } ?>
    </div>
</div>
