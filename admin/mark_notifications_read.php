<?php
session_start();
// Adjust this path if your db_connect is in a different folder!
include '../db_connect.php'; 

$query = "UPDATE admin_notifications SET is_read = 1 WHERE is_read = 0";
mysqli_query($conn, $query);

header("Location: managebooking.php");
exit();
?>