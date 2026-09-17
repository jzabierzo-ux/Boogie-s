<?php
session_start();
include '../db_connect.php';

// Security check (Admin only)
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['unread' => 0, 'html' => '']);
    exit();
}

$query = mysqli_query($conn, "SELECT * FROM admin_notifications WHERE is_read = 0 ORDER BY created_at DESC");
$unread_count = mysqli_num_rows($query);

$html = '';

if ($unread_count > 0) {
    while($notif = mysqli_fetch_assoc($query)) {
        $time = date('M d, g:i A', strtotime($notif['created_at']));
        $message = htmlspecialchars($notif['message']);
        
        $html .= '<div class="notif-item">
                    <i class="fa-solid fa-circle-exclamation" style="color: #e11d48; margin-right: 5px;"></i>
                    ' . $message . '
                    <br><small style="color: #94a3b8; font-size: 11px;">' . $time . '</small>
                  </div>';
    }
}

echo json_encode([
    'unread' => $unread_count,
    'html' => $html
]);
?>