<?php
require_once __DIR__ . '/../shared_session_bootstrap.php';
require_once __DIR__ . '/../db_supabase.php';

// Only an authenticated account may update its own notifications.
if (($_SESSION['logged_in'] ?? false) !== true) {
    header('Location: login.php');
    exit();
}

$userId = filter_var(
    $_SESSION['user_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
if ($userId === false || $userId === null) {
    header('Location: login.php');
    exit();
}

// This endpoint changes data, so accept CSRF-protected POST requests only.
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
    // Confirm the expected table/column exists in the public schema and
    // determine the column type before selecting a safe SQL literal.
    $typeStmt = $pdo->prepare(
        "SELECT data_type
         FROM information_schema.columns
         WHERE table_schema = 'public'
           AND table_name = 'notifications'
           AND column_name = 'is_read'
         LIMIT 1"
    );
    $typeStmt->execute();
    $isReadType = strtolower(trim((string) $typeStmt->fetchColumn()));

    if ($isReadType === 'boolean') {
        $readValue = 'TRUE';
    } elseif (in_array(
        $isReadType,
        ['smallint', 'integer', 'bigint', 'numeric', 'decimal', 'real', 'double precision'],
        true
    )) {
        $readValue = '1';
    } elseif (in_array($isReadType, ['text', 'character varying', 'character'], true)) {
        $readValue = "'1'";
    } else {
        throw new RuntimeException('Unable to determine a supported public.notifications.is_read data type.');
    }

    // Ownership is enforced in the UPDATE itself. Only unread rows belonging
    // to this customer are changed; other users' notifications are untouched.
    $updateStmt = $pdo->prepare(
        "UPDATE public.notifications
         SET is_read = {$readValue}
         WHERE user_id = :user_id
           AND (
               LOWER(CAST(is_read AS TEXT)) IN ('0', 'false', 'f')
               OR is_read IS NULL
           )"
    );
    $updateStmt->execute([':user_id' => (int) $userId]);

    unset($_SESSION['notification_update_error']);
} catch (Throwable $e) {
    error_log('Mark all customer notifications as read failed: ' . $e->getMessage());
    $_SESSION['notification_update_error'] = 'Unable to update notifications right now. Please try again.';
}

// Post/Redirect/Get prevents accidental form resubmission on refresh.
header('Location: notifications.php');
exit();
