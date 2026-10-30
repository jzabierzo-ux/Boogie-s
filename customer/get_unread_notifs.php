<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared_session_bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

// This endpoint only returns the unread count for the current session user.
$loggedIn = ($_SESSION['logged_in'] ?? false) === true;
$rawUserId = $_SESSION['user_id'] ?? null;
$userId = is_scalar($rawUserId)
    ? filter_var($rawUserId, FILTER_VALIDATE_INT)
    : false;

if (!$loggedIn || $userId === false || $userId === null || $userId < 1) {
    http_response_code(401);
    echo json_encode([
        'unread' => null,
        'error' => 'Authentication required',
    ]);
    exit;
}

try {
    require_once __DIR__ . '/../db_supabase.php';

    // Casting to TEXT keeps the unread check compatible with PostgreSQL
    // BOOLEAN, numeric, and text-based is_read columns.
    $stmt = $pdo->prepare(<<<'SQL'
        SELECT COUNT(*)
        FROM notifications
        WHERE user_id = :user_id
          AND (
              LOWER(CAST(is_read AS TEXT)) IN ('0', 'false', 'f')
              OR is_read IS NULL
          )
    SQL);
    $stmt->bindValue(':user_id', (int)$userId, PDO::PARAM_INT);
    $stmt->execute();

    $unreadCount = $stmt->fetchColumn();
    $unread = max(0, (int)($unreadCount ?? 0));

    echo json_encode([
        'unread' => $unread,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('Unread notification count failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'unread' => null,
        'error' => 'Unable to fetch notification count',
    ]);
}

exit;
