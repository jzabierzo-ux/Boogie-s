<?php
session_start();
include 'db_connect.php'; // Siguraduhing tama ang path

header('Content-Type: application/json');

// Check kung naka-login ang user
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['user_id'])) {
    echo json_encode(['unread' => 0]);
    exit();
}

$user_id = $_SESSION['user_id'];

// Kunin ang count ng unread notifications
$notif_query = "SELECT COUNT(*) as unread FROM notifications WHERE user_id = '$user_id' AND is_read = 0";
$notif_result = @mysqli_query($conn, $notif_query);

if ($notif_result) {
    $row = mysqli_fetch_assoc($notif_result);
    echo json_encode(['unread' => (int)$row['unread']]);
} else {
    echo json_encode(['unread' => 0]);
}
?>