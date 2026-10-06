<?php
session_start();
require_once 'db_supabase.php';

if (isset($_GET['id']) && isset($_SESSION['user_id'])) {
    $notif_id = (int)$_GET['id'];
    $user_id = (int)$_SESSION['user_id'];

    try {
        // Mark only this user's notification as read
        $stmt = $pdo->prepare("
            UPDATE notifications
            SET is_read = 1
            WHERE id = :notif_id
              AND user_id = :user_id
        ");

        $stmt->execute([
            ':notif_id' => $notif_id,
            ':user_id' => $user_id
        ]);
    } catch (PDOException $e) {
        error_log("Mark notification as read failed: " . $e->getMessage());
    }
}

// Return to notifications page
header("Location: notifications.php");
exit();
?>