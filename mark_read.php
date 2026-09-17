<?php
session_start();
include 'db_connect.php';

if (isset($_GET['id']) && isset($_SESSION['user_id'])) {
    $notif_id = mysqli_real_escape_string($conn, $_GET['id']);
    $user_id = $_SESSION['user_id'];

    // I-update ang notification para maging is_read = 1
    $query = "UPDATE notifications SET is_read = 1 WHERE id = '$notif_id' AND user_id = '$user_id'";
    mysqli_query($conn, $query);
}

// Bumalik sa notifications page
header("Location: notifications.php");
exit();
?>  