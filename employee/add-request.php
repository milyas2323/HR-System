<?php
// Included from dashboard.php — auth and POST handled there.
$employeeId = (int) $user['id'];
$message = $employeeRequestMessage ?? '';
$messageType = $employeeRequestMessageType ?? 'danger';

$requestTypes = getEmployeeRequestTypes();
$myRequests = getEmployeeRequests($conn, $employeeId);
$myCounts = getEmployeeRequestStatusCounts($conn, $employeeId);
?>

<div class="page-title">Add Request</div>

<!-- PENALTY CAUTION -->
<div class="alert danger glowing-element" style="margin-bottom: 24px;">
    <span>⚠️</span>
    <span>
        <strong>Caution — PKR <?php echo number_format(REQUEST_VIOLATION_PENALTY_AMOUNT); ?> penalty.</strong>
        Joining late, taking an extended break, signing off early, changing your workstation or any other
        urgent shift change <strong>must be requested and approved in advance</strong>.
        A fine of <strong>PKR <?php echo number_format(REQUEST_VIOLATION_PENALTY_AMOUNT); ?></strong> is applied if you
        go ahead <strong>without submitting a request</strong>, or if your request is <strong>rejected</strong>.
        Only an <strong>approved</strong> request protects you from the fine.
    </span>
</div>

<!-- REQUEST STATUS SUMMARY -->
<div class="summary" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 24px;">
    <div class="card stat-box" style="margin-bottom: 0; border-bottom: 4px solid var(--warning);">
        <h4>Pending</h4>
        <h2 style="color: var(--warning);"><?php echo (int) $myCounts['pending']; ?></h2>
    </div>
    <div class="card stat-box" style="margin-bottom: 0; border-bottom: 4px solid var(--success);">
        <h4>Approved</h4>
        <h2 style="color: var(--success);"><?php echo (int) $myCounts['approved']; ?></h2>
    </div>
    <div class="card stat-box" style="margin-bottom: 0; border-bottom: 4px solid var(--danger);">
        <h4>Rejected</h4>
        <h2 style="color: var(--danger);"><?php echo (int) $myCounts['rejected']; ?></h2>
    </div>
</div>

<!-- NEW REQUEST FORM -->
<div class="card">
    <h2>Send a Request to Admin</h2>
    <p style="color: var(--text-muted); margin-bottom: 24px;">
        Use this for late joining, an urgent issue, an extended break, or anything else that affects your shift.
        Getting approval in advance helps avoid absence and missed-update penalties.
    </p>

    <?php if($message != "") { ?>
        <div class="alert <?php echo $messageType; ?>">
            <span><?php echo $messageType === 'success' ? '✅' : '⚠️'; ?></span>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php } ?>

    <form method="POST" action="dashboard.php?page=add-request">
        <div class="form-group">
            <label>Request Type</label>
            <select name="request_type" id="requestTypeSelect" required>
                <option value="">-- Choose Request Type --</option>
                <?php foreach($requestTypes as $key => $meta){ ?>
                    <option value="<?php echo htmlspecialchars($key); ?>" data-hint="<?php echo htmlspecialchars($meta['hint']); ?>">
                        <?php echo $meta['icon'] . ' ' . htmlspecialchars($meta['label']); ?>
                    </option>
                <?php } ?>
            </select>
            <p id="requestTypeHint" style="color: var(--text-muted); font-size: 0.8rem; margin-top: 6px;">
                Pick the category that best matches your situation.
            </p>
        </div>

        <div class="form-group">
            <label>Subject <span style="color: var(--text-muted); font-weight: 400;">(optional)</span></label>
            <input type="text" name="subject" maxlength="255" placeholder="e.g. Joining 1 hour late — internet outage">
        </div>

        <div class="form-group">
            <label>Details</label>
            <textarea name="details" required placeholder="Explain the reason so the admin can approve it quickly..." style="min-height: 120px;"></textarea>
        </div>

        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px;">
            <div class="form-group">
                <label>Date This Applies To</label>
                <input type="date" name="request_date" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <div class="form-group">
                <label>From Time <span style="color: var(--text-muted); font-weight: 400;">(optional)</span></label>
                <input type="time" name="from_time">
            </div>
            <div class="form-group">
                <label>To Time <span style="color: var(--text-muted); font-weight: 400;">(optional)</span></label>
                <input type="time" name="to_time">
            </div>
        </div>

        <button type="submit" name="submit_request" class="glowing-element">📨 Submit Request</button>
    </form>
</div>

<!-- MY REQUESTS -->
<div class="card">
    <h2>My Requests</h2>
    <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 15px;">
        Pending requests are shown first. Admin remarks appear here once your request is reviewed.
    </p>

    <div class="table-box">
        <table>
            <tr>
                <th>Type</th>
                <th>Subject / Details</th>
                <th>Applies To</th>
                <th>Status</th>
                <th>Admin Response</th>
                <th>Submitted</th>
            </tr>
            <?php if(count($myRequests) > 0){
                foreach($myRequests as $req){
                    $meta = getEmployeeRequestTypeMeta($req['request_type']);
                    $status = strtolower(trim($req['status']));
            ?>
                <tr>
                    <td style="white-space: nowrap;">
                        <?php echo $meta['icon']; ?> <strong><?php echo htmlspecialchars($meta['label']); ?></strong>
                    </td>

                    <td style="max-width: 300px; line-height: 1.5; word-wrap: break-word;">
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
                                    Fined PKR <?php echo number_format(floatval($req['penalty_amount'])); ?>
                                </span>
                            </div>
                        <?php } ?>
                    </td>

                    <td style="max-width: 240px; line-height: 1.5; word-wrap: break-word; font-size: 0.85rem; color: var(--text-muted);">
                        <?php if(!empty($req['admin_response'])){ ?>
                            <?php echo htmlspecialchars($req['admin_response']); ?>
                            <?php if(!empty($req['reviewed_at'])){ ?>
                                <div style="font-size: 0.75rem; margin-top: 4px;">
                                    — <?php echo htmlspecialchars($req['reviewed_by_name'] ?? 'Admin'); ?>,
                                    <?php echo date('d M Y h:i A', strtotime($req['reviewed_at'])); ?>
                                </div>
                            <?php } ?>
                        <?php } else { ?>
                            Awaiting review
                        <?php } ?>
                    </td>

                    <td style="color: var(--text-muted); font-size: 0.85rem; white-space: nowrap;">
                        <?php echo date('d M Y h:i A', strtotime($req['created_at'])); ?>
                    </td>
                </tr>
            <?php }
            } else { ?>
                <tr>
                    <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 30px;">
                        You have not submitted any requests yet.
                    </td>
                </tr>
            <?php } ?>
        </table>
    </div>
</div>

<script>
    // Show the helper hint for the selected request type.
    (function () {
        var select = document.getElementById('requestTypeSelect');
        var hint = document.getElementById('requestTypeHint');
        if (!select || !hint) { return; }

        select.addEventListener('change', function () {
            var option = select.options[select.selectedIndex];
            var text = option ? option.getAttribute('data-hint') : '';
            hint.textContent = text ? text : 'Pick the category that best matches your situation.';
        });
    })();
</script>
