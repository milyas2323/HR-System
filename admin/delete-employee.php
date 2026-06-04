<?php
include "../includes/db.php";

$id = $_GET['id'];

$conn->query("DELETE FROM users WHERE id='$id'");

header("Location: dashboard.php?page=employees");
exit();
?>