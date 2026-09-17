<?php
session_start();

// 1. SECURITY: Only allow logged-in Admins
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: adminlogin.php");
    exit();
}

// 2. DATABASE CONNECTION
include('../db_connect.php'); 

// 3. CHECK FOR ID AND DELETE PROMO CARD
if (isset($_GET['id']) && !empty($_GET['id'])) {
    // Cast the ID to an integer for strict security against SQL injection
    $promo_id = intval($_GET['id']);
    
    // Execute the delete query
    $delete_query = "DELETE FROM promos WHERE id = $promo_id";
    mysqli_query($conn, $delete_query);
}

// 4. REDIRECT BACK TO DASHBOARD
header("Location: managepromo.php");
exit();
?>