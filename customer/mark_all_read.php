<?php
require_once __DIR__ . '/../shared_session_bootstrap.php';
require_once __DIR__ . '/../db_supabase.php';

// Only an authenticated user can update their own notifications.
if (($_SESSION['logged_in'] ?? false) !== true) {
    header('Location: login.php');
    exit();
}

$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
if ($userId === false || $userId === null || $userId < 1) {
    header('Location: login.php');
    exit();
}

// This endpoint only accepts a CSRF-protected POST request.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: notifications.php');
    exit();
}

$sessionToken = $_SESSION['csrf_token'] ?? null;
$postedToken = $_POST['csrf_token'] ?? null;
if (
    !is_string($sessionToken) || $sessionToken === '' ||
    !is_string($postedToken) || $postedToken === '' ||
    !hash_equals($sessionToken, $postedToken)
) {
    http_response_code(403);
    exit('Invalid request. Please refresh the notifications page and try again.');
}

try {
    // Detect the actual column type so the update value is valid for this schema.
    $typeStmt = $pdo->prepare("
        SELECT data_type
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'notifications'
          AND column_name = 'is_read'
        LIMIT 1
    ");
    $typeStmt->execute();
    $isReadType = strtolower(trim((string) $typeStmt->fetchColumn()));

    if ($isReadType === 'boolean') {
        $readValue = 'TRUE';
    } elseif (in_array($isReadType, ['smallint', 'integer', 'bigint', 'numeric', 'decimal', 'real', 'double precision'], true)) {
        $readValue = '1';
    } elseif (in_array($isReadType, ['text', 'character varying', 'character'], true)) {
        $readValue = "'1'";
    } else {
        throw new RuntimeException('Unable to determine a supported notifications.is_read data type.');
    }

    // Scope the update to this user and change only currently unread rows.
    // The value above is selected from a fixed whitelist, not from user input.
    $updateStmt = $pdo->prepare("
        UPDATE notifications
        SET is_read = {$readValue}
        WHERE user_id = :user_id
          AND (
              LOWER(CAST(is_read AS TEXT)) IN ('0', 'false', 'f')
              OR is_read IS NULL
          )
    ");
    $updateStmt->execute([':user_id' => (int) $userId]);

    unset($_SESSION['notification_update_error']);
} catch (Throwable $e) {
    error_log('Mark all notifications as read failed: ' . $e->getMessage());
    $_SESSION['notification_update_error'] = 'Unable to update notifications right now. Please try again.';
}

header('Location: notifications.php');
exit();
