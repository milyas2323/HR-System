<?php
include "../includes/db.php";
include "../includes/auth.php";

if($_SESSION['user']['role'] != 'employee'){
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user']['id'];
$message = "";
$messageType = "danger";

if(isset($_POST['submit'])){
    $reason = trim($_POST['reason']);
    $from = $_POST['from_date'];
    $to = $_POST['to_date'];

    if(empty($reason) || empty($from) || empty($to)){
        $message = "Please complete all fields.";
    } elseif(strtotime($from) > strtotime($to)){
        $message = "The 'From Date' must be before or equal to the 'To Date'.";
    } else {
        $stmt = $conn->prepare("
            INSERT INTO leave_requests (employee_id, reason, from_date, to_date, status)
            VALUES (?, ?, ?, ?, 'pending')
        ");
        $stmt->bind_param("isss", $user_id, $reason, $from, $to);

        if($stmt->execute()){
            $_SESSION['msg'] = "Leave request submitted successfully. Waiting for admin approval!";
            echo "<script>window.location.href='dashboard.php';</script>";
            exit();
        } else {
            $message = "Database Error: " . $conn->error;
        }
    }
}
?>

<div class="card">
    <h2>Apply for Time Off / Leave</h2>
    <p style="color: var(--text-muted); margin-bottom: 24px;">
        Submit your leave details. Prior approval is mandatory to prevent out-of-workstation and absence salary deductions.
    </p>

    <!-- STATUS ALERT -->
    <?php if($message != "") { ?>
        <div class="alert <?php echo $messageType; ?>">
            <span>⚠️</span>
            <span><?php echo $message; ?></span>
        </div>
    <?php } ?>

    <form method="POST">
        <div class="form-group">
            <label>Reason for Leave</label>
            <textarea 
                name="reason" 
                placeholder="Brief description of the reason for absence or out-of-town travel..." 
                required 
                style="min-height: 120px;"
            ></textarea>
        </div>

        <div class="form-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
            <div class="form-group">
                <label>From Date</label>
                <input type="date" name="from_date" required>
            </div>

            <div class="form-group">
                <label>To Date</label>
                <input type="date" name="to_date" required>
            </div>
        </div>

        <button type="submit" name="submit" class="glowing-element">
            📅 Submit Leave Application
        </button>
    </form>
</div>

<!-- LEAVE STATUS LOG -->
<div class="card" style="margin-top: 30px;">
    <h2>Leave Application History</h2>
    <?php
    $history = $conn->query("
        SELECT * FROM leave_requests 
        WHERE employee_id='$user_id' 
        ORDER BY id DESC
    ");
    
    if($history && $history->num_rows > 0){
    ?>
        <div class="table-box">
            <table>
                <tr>
                    <th>From Date</th>
                    <th>To Date</th>
                    <th>Reason</th>
                    <th>Status</th>
                    <th>Response Message</th>
                </tr>
                <?php while($row = $history->fetch_assoc()){ 
                    $status = strtolower(trim($row['status']));
                    $badgeClass = "warning";
                    if($status === 'approved') $badgeClass = "success";
                    if($status === 'rejected') $badgeClass = "danger";
                ?>
                    <tr>
                        <td><?php echo date('d M Y', strtotime($row['from_date'])); ?></td>
                        <td><?php echo date('d M Y', strtotime($row['to_date'])); ?></td>
                        <td style="max-width: 250px; word-wrap: break-word;"><?php echo htmlspecialchars($row['reason']); ?></td>
                        <td>
                            <span class="badge <?php echo $badgeClass; ?>">
                                <?php echo strtoupper($status); ?>
                            </span>
                        </td>
                        <td><?php echo $row['message'] ? htmlspecialchars($row['message']) : '-'; ?></td>
                    </tr>
                <?php } ?>
            </table>
        </div>
    <?php } else { ?>
        <p style="color: var(--text-muted); text-align: center; padding: 20px;">No leave requests found.</p>
    <?php } ?>
</div>