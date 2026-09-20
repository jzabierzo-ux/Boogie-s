<?php
session_start();
include '../db_supabase.php';

header('Content-Type: application/json; charset=UTF-8');

// Security check (Admin only)
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] ?? '') !== 'admin') {
    echo json_encode(['unread' => 0, 'html' => '']);
    exit();
}

try {
    $query = "
        SELECT *
        FROM admin_notifications
        WHERE is_read = FALSE
        ORDER BY created_at DESC
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $unread_count = count($notifications);
    $html = '';

    foreach ($notifications as $notif) {
        $time = date('M d, g:i A', strtotime($notif['created_at']));
        $message = htmlspecialchars(
            $notif['message'] ?? '',
            ENT_QUOTES,
            'UTF-8'
        );

        $html .= '<div class="notif-item">
                    <i class="fa-solid fa-circle-exclamation" style="color: #e11d48; margin-right: 5px;"></i>
                    ' . $message . '
                    <br><small style="color: #94a3b8; font-size: 11px;">' . $time . '</small>
                  </div>';
    }

    echo json_encode([
        'unread' => $unread_count,
        'html' => $html
    ]);
} catch (PDOException $e) {
    // Do not expose database details to the browser.
    echo json_encode([
        'unread' => 0,
        'html' => ''
    ]);
}

exit();
?>
