<?php
require_once __DIR__ . '/../shared_session_bootstrap.php';
require_once '../db_supabase.php';

/*
|--------------------------------------------------------------------------
| RESPONSE HEADERS
|--------------------------------------------------------------------------
| This endpoint is polled frequently, so prevent browser/proxy caching.
*/
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

/*
|--------------------------------------------------------------------------
| JSON RESPONSE HELPER
|--------------------------------------------------------------------------
*/
function sendAdminNotificationResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit();
}

/*
|--------------------------------------------------------------------------
| ONLY ALLOW GET REQUESTS
|--------------------------------------------------------------------------
*/
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    sendAdminNotificationResponse(405, [
        'success' => false,
        'unread' => 0,
        'html' => '',
        'message' => 'Method not allowed.'
    ]);
}

/*
|--------------------------------------------------------------------------
| ADMIN-ONLY AUTHENTICATION
|--------------------------------------------------------------------------
| These are administrator notifications. Staff, managers, and veterinary
| accounts must use their own role-scoped notification endpoint if needed.
*/
$currentRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
$isLoggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;

if (!$isLoggedIn || $currentRole !== 'admin') {
    sendAdminNotificationResponse(403, [
        'success' => false,
        'unread' => 0,
        'html' => '',
        'message' => 'Unauthorized access.'
    ]);
}

/*
|--------------------------------------------------------------------------
| HELPER: FORMAT NOTIFICATION DATE
|--------------------------------------------------------------------------
*/
function formatAdminNotificationDate($value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    $timestamp = strtotime((string)$value);
    return $timestamp === false ? '' : date('M d, g:i A', $timestamp);
}

/*
|--------------------------------------------------------------------------
| FETCH NOTIFICATIONS AND ACCURATE UNREAD COUNT
|--------------------------------------------------------------------------
*/
try {
    // Count all unread notifications; the dropdown itself displays the latest 20.
    $countStmt = $pdo->query(
        'SELECT COUNT(*) FROM admin_notifications WHERE is_read = 0'
    );
    $unreadCount = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT id, message, created_at
         FROM admin_notifications
         WHERE is_read = 0
         ORDER BY created_at DESC, id DESC
         LIMIT 20'
    );
    $stmt->execute();
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* Build safe HTML; all database text is escaped before insertion. */
    $html = '';

    foreach ($notifications as $notification) {
        $message = htmlspecialchars(
            (string)($notification['message'] ?? ''),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $createdAt = formatAdminNotificationDate($notification['created_at'] ?? null);

        $html .= '<div class="notif-item">';
        $html .= '<i class="fa-solid fa-circle-exclamation" style="color:#e11d48;margin-right:5px;" aria-hidden="true"></i>';
        $html .= $message;

        if ($createdAt !== '') {
            $html .= '<br><small style="color:#94a3b8;font-size:11px;">';
            $html .= htmlspecialchars(
                $createdAt,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );
            $html .= '</small>';
        }

        $html .= '</div>';
    }

    if ($html === '') {
        $html = '<div class="notif-empty">No new notifications.</div>';
    }

    // Keep the existing frontend contract: JavaScript expects unread and html.
    sendAdminNotificationResponse(200, [
        'success' => true,
        'unread' => $unreadCount,
        'html' => $html
    ]);
} catch (PDOException $e) {
    error_log('Admin notification fetch error: ' . $e->getMessage());

    sendAdminNotificationResponse(500, [
        'success' => false,
        'unread' => 0,
        'html' => '<div class="notif-empty">Notifications are temporarily unavailable.</div>',
        'message' => 'Unable to fetch notifications.'
    ]);
}
