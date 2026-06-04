<?php
session_start();

include "../includes/db.php";
include "../includes/auth.php";

/* CHECK LOGIN */
if(!isset($_SESSION['user'])){
    header("Location: ../login.php");
    exit();
}

/* USER ID */
$id = $_SESSION['user']['id'];

/* CLOSE ACTIVE SHIFT */
$result = $conn->query("
    UPDATE shifts
    SET 
        end_time = NOW(),
        status = 'closed'
    WHERE employee_id = '$id'
    AND status = 'active'
");

/* CHECK SUCCESS */
if($conn->affected_rows > 0){

    $_SESSION['msg'] = "Shift closed successfully";

} else {

    $_SESSION['msg'] = "No active shift found";
}

/* REDIRECT */
header("Location: dashboard.php");
exit();
?>