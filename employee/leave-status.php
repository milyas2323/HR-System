<?php
include "../includes/db.php";
include "../includes/auth.php";

if($_SESSION['user']['role'] != 'employee'){
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user']['id'];
?>

<?php include "../includes/header.php"; ?>

<style>
table{
    width:100%;
    border-collapse:collapse;
    background:#fff;
}

th,td{
    padding:10px;
    border:1px solid #ddd;
    text-align:center;
}

.pending{ color:orange; font-weight:bold; }
.approved{ color:green; font-weight:bold; }
.rejected{ color:red; font-weight:bold; }
</style>

<div class="main">

<h2>My Leave Requests</h2>

<table>

<tr>
    <th>Reason</th>
    <th>From</th>
    <th>To</th>
    <th>Status</th>
    <th>Applied On</th>
</tr>

<?php
$data = $conn->query("
SELECT * FROM leave_requests
WHERE employee_id='$user_id'
ORDER BY id DESC
");

while($row = $data->fetch_assoc()){
?>

<tr>

    <td><?php echo $row['reason']; ?></td>
    <td><?php echo $row['from_date']; ?></td>
    <td><?php echo $row['to_date']; ?></td>

    <td class="<?php echo $row['status']; ?>">
        <?php echo strtoupper($row['status']); ?>
    </td>

    <td><?php echo $row['created_at']; ?></td>

</tr>

<?php } ?>

</table>

</div>

<?php include "../includes/footer.php"; ?>