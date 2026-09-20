<?php

session_start();

include 'db_supabase.php';

header('Content-Type: application/json; charset=UTF-8');

// Check kung naka-login ang user
if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id'])
) {
    echo json_encode(['unread' => 0]);
    exit();
}

$user_id = $_SESSION['user_id'];

try {

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS unread
        FROM notifications
        WHERE user_id = :user_id
          AND is_read = 0
    ");

    $stmt->execute([
        ':user_id' => $user_id
    ]);

    $row = $stmt->fetch();

    echo json_encode([
        'unread' => (int)($row['unread'] ?? 0)
    ]);

} catch (PDOException $e) {

    echo json_encode([
        'unread' => 0
    ]);
}

exit();
?>