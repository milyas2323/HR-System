<?php
session_start();
include_once "includes/db.php";
include_once "includes/functions.php";

$message = "";
$messageType = "danger";

if(isset($_POST['register'])){
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    $salary = floatval($_POST['salary']);
    $role = trim($_POST['role']);

    if(empty($name) || empty($email) || empty($password) || empty($salary) || empty($role)){
        $message = "All fields are required";
    } else {
        // Check if email already exists
        $checkStmt = $conn->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
        $checkStmt->bind_param("s", $email);
        $checkStmt->execute();
        $checkRes = $checkStmt->get_result();

        if($checkRes->num_rows > 0){
            $message = "An account with this email already exists";
        } else {
            // Hash password securely
            $hashedPassword = hashPassword($password);

            $stmt = $conn->prepare("
                INSERT INTO users (name, email, password, role, salary, total_deduction)
                VALUES (?, ?, ?, ?, ?, 0)
            ");
            $stmt->bind_param("ssssd", $name, $email, $hashedPassword, $role, $salary);

            if($stmt->execute()){
                $message = "Account created successfully. You can now log in!";
                $messageType = "success";
            } else {
                $message = "Database Error: " . $conn->error;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Workforce Account</title>
    
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/responsive.css">

    <style>
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            background: radial-gradient(circle at top right, #1e1b4b 0%, #030712 100%);
        }
        .auth-box {
            width: 480px;
        }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
    </style>
</head>
<body>

    <div class="card auth-box">
        
        <div class="auth-header">
            <h2>Join the Platform</h2>
            <p style="color: var(--text-muted); font-size: 0.9rem;">Register a new workforce account</p>
        </div>

        <!-- NOTIFICATION ALERT -->
        <?php if($message != "") { ?>
            <div class="alert <?php echo $messageType; ?>">
                <span><?php echo ($messageType === 'success') ? '✅' : '⚠️'; ?></span>
                <span><?php echo $message; ?></span>
            </div>
        <?php } ?>

        <form method="POST">
            
            <div class="form-grid">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="name" placeholder="John Doe" required>
                </div>

                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" placeholder="john@company.com" required>
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" placeholder="Min. 6 chars" required>
                </div>

                <div class="form-group">
                    <label>Monthly Salary</label>
                    <input type="number" name="salary" placeholder="e.g. 80000" min="0" required>
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 25px;">
                <label>Select Portal Role</label>
                <select name="role" required>
                    <option value="">-- Choose Role --</option>
                    <option value="employee">Employee Portal</option>
                    <option value="admin">System Administrator</option>
                </select>
            </div>

            <button type="submit" name="register" style="width: 100%;">
                Create Account
            </button>

        </form>

        <p style="text-align: center; margin-top: 24px; font-size: 0.9rem; color: var(--text-muted);">
            Already have an account? <a href="login.php" style="color: var(--primary); text-decoration: none; font-weight: 600;">Sign in here</a>
        </p>

    </div>

</body>
</html>