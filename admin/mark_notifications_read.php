<?php

session_start();

require_once '../db_supabase.php';

/*
|--------------------------------------------------------------------------
| SECURITY / AUTHENTICATION CHECK
|--------------------------------------------------------------------------
*/
$current_role = strtolower(
    trim(
        (string)($_SESSION['role'] ?? '')
    )
);

$allowed_roles = ['admin', 'manager', 'vet'];

$is_logged_in = (
    isset($_SESSION['logged_in']) &&
    $_SESSION['logged_in'] === true
);

if (!$is_logged_in || !in_array($current_role, $allowed_roles, true)) {
    http_response_code(403);

    // Keep the response simple and do not expose sensitive information.
    exit('Unauthorized access.');
}

/*
|--------------------------------------------------------------------------
| ONLY ALLOW GET FOR CURRENT FRONTEND COMPATIBILITY
|--------------------------------------------------------------------------
| Your notification dropdown currently uses:
| mark_notifications_read.php
|
| We keep GET here so no frontend changes are required.
*/
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');

    exit('Method not allowed.');
}

/*
|--------------------------------------------------------------------------
| MARK ALL UNREAD NOTIFICATIONS AS READ
|--------------------------------------------------------------------------
*/
try {

    $stmt = $pdo->prepare("
        UPDATE admin_notifications
        SET is_read = 1
        WHERE is_read = 0
    ");

    $stmt->execute();

} catch (PDOException $e) {

    /*
    |--------------------------------------------------------------------------
    | LOG SERVER ERROR
    |--------------------------------------------------------------------------
    | Do not show database details to the user.
    */
    error_log(
        'Mark notifications read error: ' .
        $e->getMessage()
    );
}

/*
|--------------------------------------------------------------------------
| RETURN TO BOOKING MANAGEMENT
|--------------------------------------------------------------------------
*/
header('Location: managebooking.php');
exit();

?>