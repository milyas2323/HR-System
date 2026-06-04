<?php
include "../includes/db.php";
include "../includes/auth.php";

if(session_status() == PHP_SESSION_NONE){
    session_start();
}

$user = $_SESSION['user'];
$message = "";
$messageType = "danger";

if(isset($_POST['upload'])){
    if(!empty($_FILES['profile_pic']['name'])){
        $file = $_FILES['profile_pic']['name'];
        $tmp  = $_FILES['profile_pic']['tmp_name'];
        
        // Clean filename to prevent spaces/special character issues
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $newFilename = time() . "_" . $user['id'] . "." . $ext;
        
        $folder = "../uploads/profile/";
        if(!is_dir($folder)){
            mkdir($folder, 0777, true);
        }
        
        $path = $folder . $newFilename;

        // Check if image upload succeeded
        if(move_uploaded_file($tmp, $path)){
            $update = $conn->query("
                UPDATE users 
                SET profile_pic='$newFilename'
                WHERE id='{$user['id']}'
            ");

            if($update){
                // Update session info
                $_SESSION['user']['profile_pic'] = $newFilename;
                
                $_SESSION['msg'] = "Profile picture uploaded successfully!";
                echo "<script>window.location.href='dashboard.php?page=profile';</script>";
                exit();
            } else {
                $message = "Database error: " . $conn->error;
            }
        } else {
            $message = "File transfer failed. Verify write permissions on uploads/profile/ folder.";
        }
    } else {
        $message = "Please select an image file to upload.";
    }
}
?>

<div class="card" style="max-width: 500px; margin: 0 auto;">
    <h2>Profile Picture</h2>
    <p style="color: var(--text-muted); margin-bottom: 24px;">
        Update your personal avatar display in your Employee Portal.
    </p>

    <!-- STATUS ALERT -->
    <?php if($message != "") { ?>
        <div class="alert <?php echo $messageType; ?>">
            <span>⚠️</span>
            <span><?php echo $message; ?></span>
        </div>
    <?php } ?>

    <div style="text-align: center; margin-bottom: 25px;">
        <img 
            src="../uploads/profile/<?php echo $user['profile_pic'] ? $user['profile_pic'] : 'default.png'; ?>" 
            style="width: 130px; height: 130px; border-radius: 50%; object-fit: cover; border: 4px solid var(--primary); box-shadow: 0 0 25px var(--primary-glow); margin: 0 auto;"
            alt="Current Profile Pic"
        >
        <h4 style="margin-top: 15px;"><?php echo htmlspecialchars($user['name']); ?></h4>
        <p style="color: var(--text-muted); font-size: 0.85rem;"><?php echo htmlspecialchars($user['email']); ?></p>
    </div>

    <form method="POST" enctype="multipart/form-data">
        <div class="form-group">
            <label>Select Profile Image File</label>
            <input type="file" name="profile_pic" accept="image/*" required style="padding: 10px;">
        </div>

        <button type="submit" name="upload" class="glowing-element" style="width: 100%;">
            👤 Upload Avatar
        </button>
    </form>
</div>