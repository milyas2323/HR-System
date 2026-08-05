<?php
include "../includes/db.php";

/* CHECK IF ALREADY RUN THIS MONTH */
$month = date('Y-m');

$check = $conn->query("
    SELECT id FROM penalty_archive 
    WHERE month='$month'
    LIMIT 1
");

if($check->num_rows > 0){
    exit("Already processed this month");
}

/* GET TOTAL PER EMPLOYEE */
$data = $conn->query("
    SELECT employee_id, SUM(amount) as total
    FROM penalties
    WHERE waived = 0
    GROUP BY employee_id
");

while($row = $data->fetch_assoc()){

    $emp_id = $row['employee_id'];
    $total = $row['total'] ?? 0;

    /* SAVE TO ARCHIVE */
    $conn->query("
        INSERT INTO penalty_archive
        (employee_id, total_amount, month)
        VALUES
        ('$emp_id', '$total', '$month')
    ");

    /* RESET USER DEDUCTION */
    $conn->query("
        UPDATE users
        SET total_deduction = 0
        WHERE id='$emp_id'
    ");
}

/* DELETE CURRENT MONTH PENALTIES */
$conn->query("DELETE FROM penalties");

echo "Monthly reset completed successfully";
?>