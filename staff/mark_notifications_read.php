<?php
session_start();
require_once '../db_supabase.php';

// --- SECURITY CHECK ---
if (!isset($_SESSION['staff_logged_in']) || $_SESSION['staff_logged_in'] !== true) {
    header("Location: stafflogin.php");
    exit;
}

// UPDATE QUERY: Gawing '1' (Read) ang lahat ng '0' (Unread) sa admin_notifications table
try {
    $update_stmt = $pdo->prepare("UPDATE admin_notifications SET is_read = 1 WHERE is_read = 0");
    $update_stmt->execute();

    // I-redirect pabalik kung saan nanggaling
    if (isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_REFERER'])) {
        header("Location: " . $_SERVER['HTTP_REFERER']);
    } else {
        header("Location: staffdashboard.php");
    }
    exit;
} catch (PDOException $e) {
    // Kung may error sa pag-update
    echo "May problema sa database: " . htmlspecialchars($e->getMessage());
}
?>
