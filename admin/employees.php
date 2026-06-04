<?php
include "../includes/db.php";
include "../includes/functions.php";

if($_SESSION['user']['role'] != 'admin'){
    exit("Access Denied");
}

$message = "";
$messageType = "success";

/* 1. ADD EMPLOYEE */
if(isset($_POST['add_employee'])){
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    $salary = floatval($_POST['salary']);

    // Check if email already exists
    $check = $conn->query("SELECT id FROM users WHERE email='$email' LIMIT 1");
    if($check->num_rows > 0){
        $message = "An account with this email address already exists.";
        $messageType = "danger";
    } else {
        $hashedPassword = hashPassword($password);
        $stmt = $conn->prepare("
            INSERT INTO users (name, email, password, role, salary, total_deduction)
            VALUES (?, ?, ?, 'employee', ?, 0)
        ");
        $stmt->bind_param("sssd", $name, $email, $hashedPassword, $salary);
        
        if($stmt->execute()){
            $message = "Employee added successfully.";
        } else {
            $message = "Database Error: " . $conn->error;
            $messageType = "danger";
        }
    }
}

/* 2. UPDATE EMPLOYEE */
if(isset($_POST['update_employee'])){
    $id = intval($_POST['id']);
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $salary = floatval($_POST['salary']);

    $stmt = $conn->prepare("
        UPDATE users 
        SET name=?, email=?, salary=?
        WHERE id=? AND role='employee'
    ");
    $stmt->bind_param("ssdi", $name, $email, $salary, $id);
    
    if($stmt->execute()){
        $message = "Employee details updated successfully.";
        
        // Optional password update
        if(!empty($_POST['new_password'])){
            $newPass = hashPassword(trim($_POST['new_password']));
            $pStmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
            $pStmt->bind_param("si", $newPass, $id);
            $pStmt->execute();
            $message .= " Password reset successfully.";
        }
    } else {
        $message = "Database Error: " . $conn->error;
        $messageType = "danger";
    }
}

/* 3. DELETE EMPLOYEE */
if(isset($_GET['delete'])){
    $delId = intval($_GET['delete']);
    $conn->query("DELETE FROM users WHERE id='$delId' AND role='employee'");
    $message = "Employee account deleted successfully.";
    $messageType = "success";
}

/* 4. EDIT FETCH */
$editData = null;
if(isset($_GET['edit'])){
    $editId = intval($_GET['edit']);
    $editData = $conn->query("SELECT * FROM users WHERE id='$editId' AND role='employee'")->fetch_assoc();
}

$totalEmployees = $conn->query("SELECT COUNT(*) as total FROM users WHERE role='employee'")->fetch_assoc();
?>

<div class="page-title">Manage Workforce Employees</div>

<!-- STATUS MESSAGE -->
<?php if($message != "") { ?>
    <div class="alert <?php echo $messageType; ?>">
        <span><?php echo ($messageType === 'success') ? '✅' : '⚠️'; ?></span>
        <span><?php echo $message; ?></span>
    </div>
<?php } ?>

<div class="card" style="padding: 16px; background: rgba(99, 102, 241, 0.08); border-color: rgba(99, 102, 241, 0.2);">
    <h3 style="margin: 0; font-size: 1.1rem; color: var(--primary);">
        📊 System Capacity: <?php echo $totalEmployees['total'] ?? 0; ?> Employees Registered
    </h3>
</div>

<!-- MANAGEMENT FORM -->
<div class="card">
    <h2><?php echo $editData ? "⚙️ Modify Employee Workstation Profile" : "👤 Register New Employee Record"; ?></h2>
    
    <form method="POST" action="dashboard.php?page=employees">
        <input type="hidden" name="id" value="<?php echo $editData['id'] ?? ''; ?>">

        <h3 style="font-size: 1.15rem; margin-bottom: 15px; border-bottom: 1px solid var(--panel-border); padding-bottom: 8px;">
            1. Core Credentials & Salary
        </h3>
        
        <div class="form-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 20px;">
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="name" value="<?php echo htmlspecialchars($editData['name'] ?? ''); ?>" placeholder="e.g. John Doe" required>
            </div>

            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" value="<?php echo htmlspecialchars($editData['email'] ?? ''); ?>" placeholder="name@company.com" required>
            </div>

            <div class="form-group">
                <label>Monthly Salary (PKR)</label>
                <input type="number" name="salary" value="<?php echo htmlspecialchars($editData['salary'] ?? ''); ?>" placeholder="e.g. 75000" min="0" required>
            </div>

            <div class="form-group">
                <label><?php echo $editData ? 'New Password (Optional)' : 'Password'; ?></label>
                <input type="password" name="<?php echo $editData ? 'new_password' : 'password'; ?>" placeholder="<?php echo $editData ? 'Leave empty to keep current' : 'Enter pass key'; ?>" <?php echo $editData ? '' : 'required'; ?>>
            </div>
        </div>

        <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-top: 15px;">
            <button type="submit" name="<?php echo $editData ? 'update_employee' : 'add_employee'; ?>">
                💾 Save Employee Record
            </button>
            <?php if($editData){ ?>
                <a href="dashboard.php?page=employees" class="btn btn-secondary" style="padding-top: 15px;">Cancel</a>
            <?php } ?>
        </div>
    </form>
</div>

<!-- EMPLOYEES DIRECTORY -->
<div class="card">
    <h2>Workforce Registry Directory</h2>
    
    <div class="table-box">
        <table>
            <tr>
                <th>ID</th>
                <th>Employee Name</th>
                <th>Email Address</th>
                <th>Password State</th>
                <th>Salary Rate</th>
                <th>Action Controls</th>
            </tr>
            <?php
            $registry = $conn->query("SELECT * FROM users WHERE role='employee' ORDER BY name ASC");
            if($registry && $registry->num_rows > 0){
                while($row = $registry->fetch_assoc()){
                    $isHashed = (strpos($row['password'], '$2y$') === 0);
            ?>
                <tr>
                    <td>#<?php echo $row['id']; ?></td>
                    <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                    <td>
                        <?php if($isHashed){ ?>
                            <span class="badge success" style="font-size: 0.7rem;">Secure Hash</span>
                        <?php } else { ?>
                            <span class="badge warning" style="font-size: 0.7rem; font-family: monospace;">
                                <?php echo htmlspecialchars($row['password']); ?>
                            </span>
                        <?php } ?>
                    </td>
                    <td>PKR <?php echo number_format($row['salary']); ?></td>
                    <td class="actions">
                        <a href="dashboard.php?page=employees&edit=<?php echo $row['id']; ?>" class="btn btn-secondary" style="padding: 6px 12px; font-size: 0.8rem; margin-right: 5px;">
                            ✏️ Edit
                        </a>
                        <a href="dashboard.php?page=employees&delete=<?php echo $row['id']; ?>" class="btn btn-danger" style="padding: 6px 12px; font-size: 0.8rem;" onclick="return confirm('Permanently delete this employee account?');">
                            🗑️ Delete
                        </a>
                    </td>
                </tr>
            <?php } 
            } else { ?>
                <tr>
                    <td colspan="6" style="text-align: center; color: var(--text-muted);">No employees registered yet.</td>
                </tr>
            <?php } ?>
        </table>
    </div>
</div>