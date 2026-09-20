<?php
session_start();
include '../db_supabase.php';

// Mark all unread admin notifications as read
try {
    $query = "UPDATE admin_notifications SET is_read = TRUE WHERE is_read = FALSE";
    $pdo->exec($query);
} catch (PDOException $e) {
    // Keep database error details out of the browser.
}

header("Location: managebooking.php");
exit();
?>
