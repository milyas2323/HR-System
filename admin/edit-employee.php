<?php
include "../includes/db.php";

if(!isset($_GET['id'])){
    echo "<div class='card'>Invalid Employee ID</div>";
    exit();
}

$id = $_GET['id'];

// GET EMPLOYEE DATA
$result = $conn->query("SELECT * FROM users WHERE id='$id'");
$user = $result->fetch_assoc();

if(!$user){
    echo "<div class='card'>Employee not found</div>";
    exit();
}

// UPDATE DATA
if(isset($_POST['update'])){

    $name = $_POST['name'];
    $email = $_POST['email'];

    $conn->query("
        UPDATE users 
        SET name='$name', email='$email'
        WHERE id='$id'
    ");

    echo "<script>
        alert('Employee updated successfully');
        window.location.href='dashboard.php?page=employees';
    </script>";

    exit();
}
?>

<div class="card">

<h2>Edit Employee</h2>

<form method="POST">

    <label>Name</label><br>
    <input type="text" name="name" 
           value="<?php echo $user['name']; ?>" 
           required style="width:100%;padding:10px;">

    <br><br>

    <label>Email</label><br>
    <input type="email" name="email" 
           value="<?php echo $user['email']; ?>" 
           required style="width:100%;padding:10px;">

    <br><br>

    <button type="submit" name="update"
            style="padding:10px 20px;background:green;color:#fff;border:none;">
        Update Employee
    </button>

</form>

</div>