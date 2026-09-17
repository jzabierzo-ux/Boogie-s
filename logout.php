<?php
session_start();
include 'db_connect.php';

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

    if ($user_id !== null) {

        $stmt = $conn->prepare("
            INSERT INTO admin_account_logs
            (user_id, action, status, ip_address, user_agent)
            VALUES (?, 'LOGOUT', 'SUCCESS', ?, ?)
        ");

        if ($stmt) {
            $stmt->bind_param(
                "iss",
                $user_id,
                $ip_address,
                $user_agent
            );

            $stmt->execute();
            $stmt->close();
        }

    } else {

        $stmt = $conn->prepare("
            INSERT INTO admin_account_logs
            (user_id, action, status, ip_address, user_agent)
            VALUES (NULL, 'LOGOUT', 'SUCCESS', ?, ?)
        ");

        if ($stmt) {
            $stmt->bind_param(
                "ss",
                $ip_address,
                $user_agent
            );

            $stmt->execute();
            $stmt->close();
        }
    }

// ================================
// MANAGER / VET LOGOUT
// ================================
} elseif (in_array($role, ['manager', 'vet', 'supervisor', 'staff'])) {

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