<?php
session_start();

// 1. SECURITY: Only allow logged-in Admins
if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    ($_SESSION['role'] ?? '') !== 'admin'
) {
    header("Location: adminlogin.php");
    exit();
}

// 2. DATABASE CONNECTION
include('../db_supabase.php');

// 3. CHECK FOR ID AND DELETE PROMO CARD
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $promo_id = (int)$_GET['id'];

    try {
        // Execute the delete query using a prepared statement.
        $delete_stmt = $pdo->prepare("
            DELETE FROM promos
            WHERE id = :promo_id
        ");

        $delete_stmt->execute([
            ':promo_id' => $promo_id
        ]);
    } catch (PDOException $e) {
        // Keep database error details out of the browser.
    }
}

// 4. REDIRECT BACK TO DASHBOARD
header("Location: managepromo.php");
exit();
?>
