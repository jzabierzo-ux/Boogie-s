<?php

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Use GET.'
    ]);
    exit;
}

require_once '../db_supabase.php';

if (!isset($pdo) || !$pdo) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed.'
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| Read Authorization header
|--------------------------------------------------------------------------
*/
$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if (empty($authorization) && function_exists('getallheaders')) {
    $headers = getallheaders();

    if (isset($headers['Authorization'])) {
        $authorization = $headers['Authorization'];
    } elseif (isset($headers['authorization'])) {
        $authorization = $headers['authorization'];
    }
}

if (empty($authorization) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $authorization = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
}

if (!preg_match('/Bearer\s+(.+)/i', trim($authorization), $matches)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Missing Bearer token.'
    ]);
    exit;
}

$plain_token = trim($matches[1]);

if ($plain_token === '') {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid Bearer token.'
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| Validate API token
|--------------------------------------------------------------------------
*/
$token_hash = hash('sha256', $plain_token);

try {
    $stmt_auth = $pdo->prepare("
        SELECT user_id
        FROM api_tokens
        WHERE token_hash = :token_hash
          AND expires_at > CURRENT_TIMESTAMP
        LIMIT 1
    ");

    $stmt_auth->execute([
        ':token_hash' => $token_hash
    ]);

    $auth_row = $stmt_auth->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'API token storage is not configured yet.'
    ]);
    exit;
}

if (!$auth_row) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid or expired API token.'
    ]);
    exit;
}

$user_id = (int) $auth_row['user_id'];

/*
|--------------------------------------------------------------------------
| Optional single notification
|--------------------------------------------------------------------------
| /api/notifications.php?id=123
|--------------------------------------------------------------------------
*/
$notification_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($notification_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, title, message, type, is_read, created_at
            FROM notifications
            WHERE id = :notification_id
              AND user_id = :user_id
            LIMIT 1
        ");

        $stmt->execute([
            ':notification_id' => $notification_id,
            ':user_id' => $user_id
        ]);

        $notification = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to prepare notification query.'
        ]);
        exit;
    }

    if (!$notification) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Notification not found.'
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'id' => (int) $notification['id'],
            'title' => $notification['title'],
            'message' => $notification['message'],
            'type' => $notification['type'],
            'is_read' => (bool) $notification['is_read'],
            'created_at' => $notification['created_at']
        ]
    ], JSON_PRETTY_PRINT);

    exit;
}

/*
|--------------------------------------------------------------------------
| Get unread count
|--------------------------------------------------------------------------
*/
try {
    $stmt_count = $pdo->prepare("
        SELECT COUNT(*) AS unread_count
        FROM notifications
        WHERE user_id = :user_id
          AND is_read = 0
    ");

    $stmt_count->execute([
        ':user_id' => $user_id
    ]);

    $count_row = $stmt_count->fetch(PDO::FETCH_ASSOC);
    $unread_count = (int) ($count_row['unread_count'] ?? 0);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare unread-count query.'
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| Get latest notifications
|--------------------------------------------------------------------------
*/
try {
    $stmt = $pdo->prepare("
        SELECT id, title, message, type, is_read, created_at
        FROM notifications
        WHERE user_id = :user_id
        ORDER BY created_at DESC
    ");

    $stmt->execute([
        ':user_id' => $user_id
    ]);

    $notifications = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $notifications[] = [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'message' => $row['message'],
            'type' => $row['type'],
            'is_read' => (bool) $row['is_read'],
            'created_at' => $row['created_at']
        ];
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare notifications query.'
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'unread_count' => $unread_count,
    'count' => count($notifications),
    'data' => $notifications
], JSON_PRETTY_PRINT);

?>
