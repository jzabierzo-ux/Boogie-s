<?php
session_start();
include('db_connect.php');

// Security check
if (!isset($_SESSION['logged_in'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// I-update lahat ng notifications ng user na 'unread' pa (is_read = 0)
$query = "UPDATE notifications SET is_read = 1 WHERE user_id = '$user_id' AND is_read = 0";

if (mysqli_query($conn, $query)) {
    // I-redirect pabalik
    header("Location: notifications.php");
    exit();
} else {
    echo "Error updating records: " . mysqli_error($conn);
}
?>