<?php

session_start();

include '../db_supabase.php';

// Mark all unread admin notifications as read
try {

    $query = "
        UPDATE admin_notifications
        SET is_read = 1
        WHERE is_read = 0
    ";

    $pdo->exec($query);

} catch (PDOException $e) {

    error_log(
        'Mark notifications read error: ' .
        $e->getMessage()
    );
}

header("Location: managebooking.php");
exit();

?>