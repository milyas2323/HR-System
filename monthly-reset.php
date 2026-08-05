<?php
include "includes/db.php";

/* get last month */
$month = date('Y-m', strtotime('last month'));

/* archive data */
$conn->query("
INSERT INTO penalty_monthly_archive (employee_id, total_amount, total_cases, month)
SELECT 
    employee_id,
    SUM(amount),
    COUNT(*),
    '$month'
FROM penalties
WHERE waived = 0
GROUP BY employee_id
");

/* reset penalties */
$conn->query("DELETE FROM penalties");

/* reset user deduction */
$conn->query("UPDATE users SET total_deduction = 0");

echo "Monthly reset completed";
?>