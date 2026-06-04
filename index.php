<?php
session_start();

// If user is already logged in, redirect based on role
if(isset($_SESSION['user'])){
    if($_SESSION['user']['role'] == 'admin'){
        header("Location: admin/dashboard.php");
        exit();
    }
    if($_SESSION['user']['role'] == 'employee'){
        header("Location: employee/dashboard.php");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Attendance System</title>
    
    <!-- STYLES -->
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/responsive.css">
    
    <style>
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            background: radial-gradient(circle at top right, #1e1b4b 0%, #020617 100%);
            position: relative;
            overflow: hidden;
        }

        /* BACKGROUND GLOWS */
        .glow-orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(120px);
            opacity: 0.15;
            z-index: 1;
        }

        .orb-1 {
            width: 450px;
            height: 450px;
            background: var(--primary);
            top: -100px;
            left: -100px;
        }

        .orb-2 {
            width: 400px;
            height: 400px;
            background: var(--accent);
            bottom: -100px;
            right: -100px;
        }

        .portal-card {
            width: 440px;
            padding: 50px 40px;
            z-index: 10;
            text-align: center;
        }

        .portal-logo {
            width: 80px;
            height: 80px;
            margin: 0 auto 24px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            border-radius: 22px;
            display: flex;
            justify-content: center;
            align-items: center;
            font-family: var(--font-heading);
            font-size: 2.2rem;
            font-weight: 800;
            color: #fff;
            box-shadow: 0 10px 30px rgba(99, 102, 241, 0.4);
            animation: pulseGlow 4s infinite;
        }

        .portal-title {
            font-family: var(--font-heading);
            font-size: 1.85rem;
            font-weight: 700;
            color: #fff;
            margin-bottom: 8px;
        }

        .portal-desc {
            color: var(--text-muted);
            font-size: 0.95rem;
            margin-bottom: 35px;
            line-height: 1.6;
        }

        .portal-btn {
            width: 100%;
            margin-bottom: 16px;
        }

        .portal-footer {
            margin-top: 10px;
            font-size: 0.8rem;
            color: var(--text-muted);
        }
    </style>
</head>
<body>

    <!-- DECORATIVE BACKDROPS -->
    <div class="glow-orb orb-1"></div>
    <div class="glow-orb orb-2"></div>

    <!-- MAIN PORTAL CONTROL -->
    <div class="card portal-card">
        
        <div class="portal-logo">EA</div>
        
        <h1 class="portal-title">Workforce Hub</h1>
        
        <p class="portal-desc">
            Access your dashboard to clock in, track geofenced workstations, check leaves, and view your records.
        </p>

        <!-- ACTIONS -->
        <button class="portal-btn" onclick="window.location.href='login.php'">
            Sign In to Account
        </button>

        <button class="portal-btn btn-secondary" onclick="window.location.href='register.php'">
            Register New Account
        </button>

        <div class="portal-footer">
            &copy; <?php echo date("Y"); ?> Attendance System. All rights reserved.
        </div>

    </div>

</body>
</html>