<?php
session_start();
require_once 'db_supabase.php';

// Kunin muna ang session details bago i-destroy
$user_id = $_SESSION['user_id'] ?? null;
$role = strtolower(trim($_SESSION['role'] ?? ''));

// Kunin ang IP at browser info
$ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

// Default redirect para customer
$redirect_page = 'login.php';

// ================================
// ADMIN LOGOUT LOGGING
// ================================
if ($role === 'admin') {

    // Admin logout → Admin login page
    $redirect_page = 'admin_login.php';

    try {
        if ($user_id !== null) {

            $stmt = $pdo->prepare("
                INSERT INTO admin_account_logs
                (user_id, action, status, ip_address, user_agent)
                VALUES (:user_id, 'LOGOUT', 'SUCCESS', :ip_address, :user_agent)
            ");

            $stmt->execute([
                ':user_id' => (int)$user_id,
                ':ip_address' => $ip_address,
                ':user_agent' => $user_agent
            ]);

        } else {

            $stmt = $pdo->prepare("
                INSERT INTO admin_account_logs
                (user_id, action, status, ip_address, user_agent)
                VALUES (NULL, 'LOGOUT', 'SUCCESS', :ip_address, :user_agent)
            ");

            $stmt->execute([
                ':ip_address' => $ip_address,
                ':user_agent' => $user_agent
            ]);
        }

    } catch (PDOException $e) {
        // Logout should still continue even if logging fails.
        error_log("Admin logout logging failed: " . $e->getMessage());
    }

// ================================
// MANAGER / VET LOGOUT
// ================================
} elseif (in_array($role, ['manager', 'vet', 'supervisor', 'staff'], true)) {

    // Personnel → Staff Login
    $redirect_page = 'staff/stafflogin.php';
}

// ================================
// DESTROY SESSION
// ================================
session_unset();
session_destroy();

// Redirect based on role
header("Location: " . $redirect_page);
exit();
?>