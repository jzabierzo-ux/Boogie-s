<?php
session_start();
include '../db_supabase.php';

header('Content-Type: application/json; charset=utf-8');

$current_role = strtolower(trim($_SESSION['role'] ?? ''));

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !in_array($current_role, ['admin', 'manager', 'vet'], true)
) {
    http_response_code(403);
    echo json_encode([
        'unread' => 0,
        'html' => ''
    ]);
    exit();
}

try {
    $stmt = $pdo->query("
        SELECT id, message, created_at
        FROM admin_notifications
        WHERE is_read = 0
        ORDER BY created_at DESC
        LIMIT 20
    ");

    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $html = '';

    foreach ($notifications as $notif) {
        $message = htmlspecialchars(
            (string)($notif['message'] ?? ''),
            ENT_QUOTES,
            'UTF-8'
        );

        $created_at = '';
        if (!empty($notif['created_at'])) {
            $created_at = date(
                'M d, g:i A',
                strtotime((string)$notif['created_at'])
            );
        }

        $html .= '<div class="notif-item">';
        $html .= '<i class="fa-solid fa-circle-exclamation" style="color: #e11d48; margin-right: 5px;"></i>';
        $html .= $message;
        $html .= '<br><small style="color: #94a3b8; font-size: 11px;">';
        $html .= htmlspecialchars($created_at, ENT_QUOTES, 'UTF-8');
        $html .= '</small>';
        $html .= '</div>';
    }

    echo json_encode([
        'unread' => count($notifications),
        'html' => $html
    ]);
} catch (PDOException $e) {
    error_log('Admin notification fetch error: ' . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'unread' => 0,
        'html' => ''
    ]);
}
?>
