<?php
// Included from dashboard.php — auth and POST handled there.
$message = $profileMessage ?? '';
$messageType = $profileMessageType ?? 'danger';
?>

<div class="card">
    <h2>Profile Settings</h2>
    <p style="color: var(--text-muted); margin-bottom: 20px;">Update your profile picture.</p>

    <?php if($message != "") { ?>
        <div class="alert <?php echo $messageType; ?>">
            <span><?php echo $messageType === 'success' ? '✅' : '⚠️'; ?></span>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php } ?>

    <div style="text-align: center; margin-bottom: 24px;">
        <img src="../uploads/profile/<?php echo $user['profile_pic'] ? htmlspecialchars($user['profile_pic']) : 'default.png'; ?>"
             alt="Profile"
             style="width: 120px; height: 120px; border-radius: 50%; object-fit: cover; border: 3px solid var(--primary);">
    </div>

    <form method="POST" action="dashboard.php?page=profile" enctype="multipart/form-data">
        <div class="form-group">
            <label>Choose New Profile Picture</label>
            <input type="file" name="profile_pic" accept="image/*" required>
        </div>
        <button type="submit" name="upload" class="glowing-element">📷 Upload Picture</button>
    </form>
</div>
