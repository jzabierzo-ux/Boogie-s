<?php

session_start();
require_once '../db_supabase.php';

// SECURITY CHECK
$current_role = strtolower(trim($_SESSION['role'] ?? ''));

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    $current_role !== 'vet'
) {
    header("Location: stafflogin.php");
    exit();
}

try {

    // Mark all unread shared staff/admin notifications as read
    $update_stmt = $pdo->prepare("
        UPDATE admin_notifications
        SET is_read = 1
        WHERE is_read = 0
    ");

    $update_stmt->execute();

    /*
     * Return to the staff page where the user came from.
     * Only allow same-site /staff/ URLs.
     */
    $redirect_page = 'staffdashboard.php';

    if (!empty($_SERVER['HTTP_REFERER'])) {

        $referer = $_SERVER['HTTP_REFERER'];
        $parsed = parse_url($referer);

        $current_host = $_SERVER['HTTP_HOST'] ?? '';

        if (
            isset($parsed['host'], $parsed['path']) &&
            $parsed['host'] === $current_host &&
            str_starts_with($parsed['path'], '/Pets/staff/')
        ) {
            $redirect_page = $referer;
        }
    }

    header("Location: " . $redirect_page);
    exit();

} catch (PDOException $e) {

    error_log(
        "Mark staff notifications read error: " .
        $e->getMessage()
    );

    header("Location: staffdashboard.php");
    exit();
}
?>