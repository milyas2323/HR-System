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

/* ACTIVE SHIFT */
$shift = $conn->query("
    SELECT * FROM shifts 
    WHERE employee_id='$user_id' 
    AND status='active'
    LIMIT 1
");
$active = $shift->fetch_assoc();

/* SUBMIT REPORT */
if(isset($_POST['submit'])){
    $report_text = trim($_POST['report']);

    if(empty($report_text)){
        $message = "Please enter your report text before submitting.";
    } elseif($active){
        $shift_id = $active['id'];

        // Fix database field mismatch bug (originally tried to insert into 'report' column which does not exist)
        $stmt = $conn->prepare("
            INSERT INTO end_reports (employee_id, shift_id, report_text)
            VALUES (?, ?, ?)
        ");
        $stmt->bind_param("iis", $user_id, $shift_id, $report_text);

        if($stmt->execute()){
            // Close active shift
            $conn->query("
                UPDATE shifts 
                SET status='closed', end_time=NOW()
                WHERE id='$shift_id'
            ");

            $_SESSION['msg'] = "Shift closed and daily report submitted successfully!";
            echo "<script>window.location.href='dashboard.php';</script>";
            exit();
        } else {
            $message = "Database Error: " . $conn->error;
        }
    } else {
        $message = "No active shift found to close.";
    }
}
?>

<div class="card">
    <h2>Close Shift & Submit End Report</h2>
    <p style="color: var(--text-muted); margin-bottom: 24px;">
        To end your workday shift, write a detailed summary of your completed tasks below.
    </p>

    <!-- STATUS ALERT -->
    <?php if($message != "") { ?>
        <div class="alert <?php echo $messageType; ?>">
            <span>⚠️</span>
            <span><?php echo $message; ?></span>
        </div>
    <?php } ?>

    <?php if($active){ ?>
        <form method="POST">
            <div class="form-group">
                <label>Daily Summary Report</label>
                <textarea 
                    name="report" 
                    placeholder="Provide a breakdown of what tasks you completed today, any challenges faced, or next steps..." 
                    required 
                    style="min-height: 160px;"
                ></textarea>
            </div>

            <button type="submit" name="submit" class="btn-danger glowing-element">
                🏁 Submit Report & Close Shift
            </button>
        </form>
    <?php } else { ?>
        <div class="alert info">
            <span>ℹ️</span>
            <span>You do not have an active shift running. Click "Start Shift" in the sidebar to clock in.</span>
        </div>
    <?php } ?>
</div>