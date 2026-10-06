<?php
session_start();
require_once 'db_supabase.php';

// Security check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

$user_id = (int)($_SESSION['user_id'] ?? 0);

if ($user_id <= 0) {
    header("Location: login.php");
    exit();
}

try {
    // Mark all unread notifications belonging to this user as read
    $stmt = $pdo->prepare("
        UPDATE notifications
        SET is_read = 1
        WHERE user_id = :user_id
          AND is_read = 0
    ");

    $stmt->execute([
        ':user_id' => $user_id
    ]);

    // Redirect back to notifications page
    header("Location: notifications.php");
    exit();

} catch (PDOException $e) {
    error_log("Mark all notifications as read failed: " . $e->getMessage());
    echo "Error updating records.";
}
?>