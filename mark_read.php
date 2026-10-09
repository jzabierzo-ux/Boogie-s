<?php
session_start();
require_once __DIR__ . '/db_supabase.php';

// Only an authenticated user can mark their own notifications as read.
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

$submitted_token = (string)($_POST['csrf_token'] ?? '');
$session_token = (string)($_SESSION['csrf_token'] ?? '');
if ($submitted_token === '' || $session_token === '' || !hash_equals($session_token, $submitted_token)) {
    http_response_code(403);
    exit('Invalid security token. Please return to notifications and try again.');
}

$notif_id = filter_var($_POST['notification_id'] ?? null, FILTER_VALIDATE_INT);
if ($notif_id === false || $notif_id === null || $notif_id < 1) {
    header('Location: notifications.php');
    exit();
}

try {
    // Supabase/PostgreSQL projects may define is_read as BOOLEAN or SMALLINT/INTEGER.
    // Detect the column type so the update writes a value valid for the actual schema.
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

    // Enforce ownership inside the UPDATE itself; repeating this request is harmless.
    $stmt = $pdo->prepare("
        UPDATE notifications
        SET is_read = {$read_value}
        WHERE id = :notif_id
          AND user_id = :user_id
    ");
    $stmt->execute([
        ':notif_id' => (int)$notif_id,
        ':user_id' => (int)$user_id,
    ]);
} catch (Throwable $e) {
    error_log('Mark notification as read failed: ' . $e->getMessage());
    $_SESSION['notification_update_error'] = 'Unable to update the notification right now. Please try again.';
}

header('Location: notifications.php');
exit();
