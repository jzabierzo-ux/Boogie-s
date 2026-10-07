<?php
session_start();
require_once '../db_supabase.php';

/*
|--------------------------------------------------------------------------
| RESPONSE HEADERS
|--------------------------------------------------------------------------
| This endpoint is polled frequently, so prevent browser/proxy caching.
*/
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

/*
|--------------------------------------------------------------------------
| ONLY ALLOW GET REQUESTS
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');

    echo json_encode([
        'success' => false,
        'unread' => 0,
        'html' => '',
        'message' => 'Method not allowed.'
    ], JSON_UNESCAPED_UNICODE);

    exit();
}

/*
|--------------------------------------------------------------------------
| AUTHENTICATION / ROLE CHECK
|--------------------------------------------------------------------------
*/
$current_role = strtolower(
    trim(
        (string)($_SESSION['role'] ?? '')
    )
);

$is_logged_in = (
    isset($_SESSION['logged_in']) &&
    $_SESSION['logged_in'] === true
);

$allowed_roles = ['admin', 'manager', 'vet'];

if (!$is_logged_in || !in_array($current_role, $allowed_roles, true)) {
    http_response_code(403);

    echo json_encode([
        'success' => false,
        'unread' => 0,
        'html' => '',
        'message' => 'Unauthorized access.'
    ], JSON_UNESCAPED_UNICODE);

    exit();
}

/*
|--------------------------------------------------------------------------
| HELPER: FORMAT NOTIFICATION DATE
|--------------------------------------------------------------------------
*/
function formatNotificationDate($value): string
{
    if (empty($value)) {
        return '';
    }

    $timestamp = strtotime((string)$value);

    if ($timestamp === false) {
        return '';
    }

    return date('M d, g:i A', $timestamp);
}

/*
|--------------------------------------------------------------------------
| FETCH UNREAD NOTIFICATIONS
|--------------------------------------------------------------------------
*/
try {
    $stmt = $pdo->prepare("
        SELECT id, message, created_at
        FROM admin_notifications
        WHERE is_read = 0
        ORDER BY created_at DESC, id DESC
        LIMIT 20
    ");

    $stmt->execute();

    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /*
    |--------------------------------------------------------------------------
    | BUILD SAFE HTML
    |--------------------------------------------------------------------------
    | Escape all database content before inserting it into HTML.
    */
    $html = '';

    foreach ($notifications as $notif) {

        $message = htmlspecialchars(
            (string)($notif['message'] ?? ''),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        $created_at = formatNotificationDate(
            $notif['created_at'] ?? null
        );

        $html .= '<div class="notif-item">';

        $html .= '<i class="fa-solid fa-circle-exclamation" ';
        $html .= 'style="color:#e11d48;margin-right:5px;" ';
        $html .= 'aria-hidden="true"></i>';

        $html .= $message;

        if ($created_at !== '') {
            $html .= '<br>';
            $html .= '<small style="color:#94a3b8;font-size:11px;">';
            $html .= htmlspecialchars(
                $created_at,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );
            $html .= '</small>';
        }

        $html .= '</div>';
    }

    /*
    |--------------------------------------------------------------------------
    | EMPTY STATE
    |--------------------------------------------------------------------------
    */
    if ($html === '') {
        $html = '<div class="notif-empty">';
        $html .= 'No new notifications.';
        $html .= '</div>';
    }

    /*
    |--------------------------------------------------------------------------
    | JSON RESPONSE
    |--------------------------------------------------------------------------
    | Keep unread + html because your existing JavaScript uses them.
    */
    echo json_encode([
        'success' => true,
        'unread' => count($notifications),
        'html' => $html
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {

    /*
    |--------------------------------------------------------------------------
    | SERVER-SIDE ERROR LOG
    |--------------------------------------------------------------------------
    | Don't expose database details to the browser.
    */
    error_log(
        'Admin notification fetch error: ' . $e->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'unread' => 0,
        'html' => '',
        'message' => 'Unable to fetch notifications.'
    ], JSON_UNESCAPED_UNICODE);
}
?>