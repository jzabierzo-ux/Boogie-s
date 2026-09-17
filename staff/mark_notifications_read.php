<?php
session_start();
include '../db_connect.php'; 

// --- SECURITY CHECK ---
if (!isset($_SESSION['staff_logged_in']) || $_SESSION['staff_logged_in'] !== true) {
    header("Location: stafflogin.php");
    exit;
}

// UPDATE QUERY: Gawing '1' (Read) ang lahat ng '0' (Unread) sa admin_notifications table
$update_query = "UPDATE admin_notifications SET is_read = 1 WHERE is_read = 0";

if (mysqli_query($conn, $update_query)) {
    // I-redirect pabalik kung saan nanggaling
    if (isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_REFERER'])) {
        header("Location: " . $_SERVER['HTTP_REFERER']);
    } else {
        header("Location: staffdashboard.php");
    }
    exit;
} else {
    // Kung may error sa pag-update
    echo "May problema sa database: " . mysqli_error($conn);
}
?>