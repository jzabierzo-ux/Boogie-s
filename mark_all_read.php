<?php
session_start();
require_once __DIR__ . '/db_supabase.php';

if (($_SESSION['logged_in'] ?? false) !== true) {
    header('Location: login.php');
    exit();
}

$user_id = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
if (!$user_id || $user_id < 1) {
    header('Location: login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: notifications.php');
    exit();
}

$session_token = (string)($_SESSION['csrf_token'] ?? '');
$posted_token = (string)($_POST['csrf_token'] ?? '');
if ($session_token === '' || $posted_token === '' || !hash_equals($session_token, $posted_token)) {
    http_response_code(403);
    exit('Invalid request. Please refresh the notifications page and try again.');
}

try {
    // Detect BOOLEAN vs numeric/text storage for the actual Supabase column.
    $type_stmt = $pdo->prepare("
        SELECT data_type
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'notifications'
          AND column_name = 'is_read'
        LIMIT 1
    ");
    $type_stmt->execute();
    $is_read_type = strtolower(trim((string)$type_stmt->fetchColumn()));

    if ($is_read_type === 'boolean') {
        $read_value = 'TRUE';
    } elseif (in_array($is_read_type, ['smallint', 'integer', 'bigint', 'numeric', 'decimal', 'real', 'double precision'], true)) {
        $read_value = '1';
    } elseif (in_array($is_read_type, ['text', 'character varying', 'character'], true)) {
        $read_value = "'1'";
    } else {
        throw new RuntimeException('Unable to determine a supported notifications.is_read data type.');
    }

    $stmt = $pdo->prepare("
        UPDATE notifications
        SET is_read = {$read_value}
        WHERE user_id = :user_id
          AND (LOWER(CAST(is_read AS TEXT)) IN ('0', 'false', 'f') OR is_read IS NULL)
    ");
    $stmt->execute([':user_id' => (int)$user_id]);
    unset($_SESSION['notification_update_error']);
} catch (Throwable $e) {
    error_log('Mark all notifications as read failed: ' . $e->getMessage());
    $_SESSION['notification_update_error'] = 'Unable to update notifications right now. Please try again.';
}

header('Location: notifications.php');
exit();
