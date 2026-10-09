<?php
require_once __DIR__ . '/shared_session_bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private, max-age=0, must-revalidate');
header('Pragma: no-cache');

$user_id = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
if (($_SESSION['logged_in'] ?? false) !== true || !$user_id || $user_id < 1) {
    http_response_code(401);
    echo json_encode(['unread' => null, 'error' => 'Authentication required']);
    exit;
}

try {
    require_once __DIR__ . '/db_supabase.php';

    // Cast to text to work with both PostgreSQL BOOLEAN and numeric is_read columns.
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM notifications
        WHERE user_id = :user_id
          AND (LOWER(CAST(is_read AS TEXT)) IN ('0', 'false', 'f') OR is_read IS NULL)
    ");
    $stmt->execute([':user_id' => (int)$user_id]);
    $unread = (int)($stmt->fetchColumn() ?? 0);

    echo json_encode(['unread' => $unread]);
} catch (Throwable $e) {
    error_log('Unread notification count failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['unread' => null, 'error' => 'Unable to fetch notification count']);
}
exit;
?>
