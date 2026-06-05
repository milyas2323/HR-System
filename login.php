<?php
session_start();
include_once "includes/db.php";
include_once "includes/functions.php";

$message = "";

if(isset($_POST['login'])){
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);

    // Fetch user details safely
    $stmt = $conn->prepare("SELECT * FROM users WHERE email=? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if($result->num_rows > 0){
        $user = $result->fetch_assoc();

        // Secure password verify (with plain text fallback)
        if(verifyPassword($password, $user['password'])){
            $_SESSION['user'] = $user;

            // Audit Login Details
            $ip = getUserIP();
            $ua = parseUserAgent($_SERVER['HTTP_USER_AGENT']);
            $deviceStr = $ua['device'] . " (" . $ua['os'] . " / " . $ua['browser'] . ")";
            
            // Geolocation based on IP (with fallback for local testing)
            $locationName = "Local System Loop";
            $lat = "0.0";
            $lng = "0.0";

            if($ip !== '127.0.0.1' && $ip !== '::1' && !empty($ip)){
                $ctx = stream_context_create(['http' => ['timeout' => 2]]);
                $geoJson = @file_get_contents("http://ip-api.com/json/{$ip}", false, $ctx);
                if($geoJson){
                    $geoData = json_decode($geoJson, true);
                    if(isset($geoData['status']) && $geoData['status'] === 'success'){
                        $locationName = ($geoData['city'] ?? '') . ", " . ($geoData['regionName'] ?? '') . ", " . ($geoData['country'] ?? '');
                        $lat = strval($geoData['lat'] ?? '0.0');
                        $lng = strval($geoData['lon'] ?? '0.0');
                    }
                }
            }

            // Insert into login_logs table
            $logStmt = $conn->prepare("INSERT INTO login_logs (user_id, ip_address, device, location, latitude, longitude) VALUES (?, ?, ?, ?, ?, ?)");
            $logStmt->bind_param("isssss", $user['id'], $ip, $deviceStr, $locationName, $lat, $lng);
            $logStmt->execute();

            // Redirect based on role
            if($user['role'] == 'admin'){
                header("Location: admin/dashboard.php");
            } else {
                header("Location: employee/dashboard.php");
            }
            exit();
        } else {
            $message = "Invalid email or password";
        }
    } else {
        $message = "Invalid email or password";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Workforce Login</title>
    
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
    </style>
</head>
<body>

    <div class="card auth-box">
        
        <div class="auth-header">
            <h2>Welcome Back</h2>
            <p style="color: var(--text-muted); font-size: 0.9rem;">Sign in to access your portal</p>
        </div>

        <!-- ERROR MESSAGE -->
        <?php if($message != "") { ?>
            <div class="alert danger">
                <span>⚠️</span>
                <span><?php echo $message; ?></span>
            </div>
        <?php } ?>

        <form method="POST">
            
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="name@company.com" required>
            </div>

            <div class="form-group" style="margin-bottom: 25px;">
                <label>Password</label>
                <input type="password" name="password" placeholder="••••••••" required>
            </div>

            <button type="submit" name="login" style="width: 100%;">
                Sign In
            </button>

        </form>

        <p style="text-align: center; margin-top: 24px; font-size: 0.9rem; color: var(--text-muted);">
            Don't have an account? <a href="register.php" style="color: var(--primary); text-decoration: none; font-weight: 600;">Register here</a>
        </p>

    </div>

</body>
</html>