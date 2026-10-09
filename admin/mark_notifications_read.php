<?php
require_once __DIR__ . '/../shared_session_bootstrap.php';

/*
|--------------------------------------------------------------------------
| ADMIN-ONLY ACCESS
|--------------------------------------------------------------------------
*/
$currentRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
$isLoggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;

if (!$isLoggedIn || $currentRole !== 'admin') {
    header('Location: ../admin_login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| METHOD CHECK
|--------------------------------------------------------------------------
| The current notification dropdown uses a normal GET link. Keep GET
| compatibility for now; migrate the link and this endpoint to POST + CSRF
| together to prevent cross-site request forgery.
*/
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('Method not allowed.');
}

require_once '../db_supabase.php';

/*
|--------------------------------------------------------------------------
| MARK ADMIN NOTIFICATIONS AS READ
|--------------------------------------------------------------------------
| is_read is stored as a numeric flag in the current schema, so use 1/0.
*/
try {
    $stmt = $pdo->prepare('
        UPDATE admin_notifications
        SET is_read = 1
        WHERE is_read = 0
    ');
    $stmt->execute();
} catch (PDOException $e) {
    error_log('Mark admin notifications read failed: ' . $e->getMessage());
}

/*
|--------------------------------------------------------------------------
| RETURN TO ADMIN BOOKING MANAGEMENT
|--------------------------------------------------------------------------
*/
header('Location: managebooking.php');
exit;
