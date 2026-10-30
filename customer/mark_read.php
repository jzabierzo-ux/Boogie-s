<?php
require_once __DIR__ . '/../shared_session_bootstrap.php';
require_once __DIR__ . '/../db_supabase.php';

// Only an authenticated account may update its own notifications.
if (($_SESSION['logged_in'] ?? false) !== true) {
    header('Location: login.php');
    exit();
}

$user_id = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
if ($user_id === false || $user_id === null || $user_id < 1) {
    header('Location: login.php');
    exit();
}

// This endpoint changes data, so accept POST requests only.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: notifications.php');
    exit();
}

// Verify the CSRF token before accessing the notification record.
$submitted_token = $_POST['csrf_token'] ?? '';
$session_token = $_SESSION['csrf_token'] ?? '';
if (
    !is_string($submitted_token) ||
    !is_string($session_token) ||
    $submitted_token === '' ||
    $session_token === '' ||
    !hash_equals($session_token, $submitted_token)
) {
    http_response_code(403);
    exit('Invalid security token. Please return to notifications and try again.');
}

$notif_id = filter_var($_POST['notification_id'] ?? null, FILTER_VALIDATE_INT);
if ($notif_id === false || $notif_id === null || $notif_id < 1) {
    header('Location: notifications.php');
    exit();
}

try {
    // Support the existing PostgreSQL schemas where is_read may be BOOLEAN,
    // numeric, or a text field. Only fixed, whitelisted SQL literals are used.
    $type_stmt = $pdo->prepare(
        "SELECT data_type
         FROM information_schema.columns
         WHERE table_schema = current_schema()
           AND table_name = 'notifications'
           AND column_name = 'is_read'
         LIMIT 1"
    );
    $type_stmt->execute();
    $is_read_type = strtolower(trim((string) $type_stmt->fetchColumn()));

    if ($is_read_type === 'boolean') {
        $read_value = 'TRUE';
    } elseif (in_array(
        $is_read_type,
        ['smallint', 'integer', 'bigint', 'numeric', 'decimal', 'real', 'double precision'],
        true
    )) {
        $read_value = '1';
    } elseif (in_array($is_read_type, ['text', 'character varying', 'character'], true)) {
        $read_value = "'1'";
    } else {
        throw new RuntimeException('Unsupported or missing notifications.is_read column type.');
    }

    // Ownership is enforced in the UPDATE itself; already-read rows are not rewritten.
    $update_stmt = $pdo->prepare(
        "UPDATE notifications
         SET is_read = {$read_value}
         WHERE id = :notification_id
           AND user_id = :user_id
           AND is_read IS DISTINCT FROM {$read_value}"
    );
    $update_stmt->execute([
        ':notification_id' => (int) $notif_id,
        ':user_id' => (int) $user_id,
    ]);
} catch (Throwable $e) {
    error_log('Mark notification as read failed: ' . $e->getMessage());
    $_SESSION['notification_update_error'] = 'Unable to update the notification right now. Please try again.';
}

// Post/Redirect/Get prevents accidental form resubmission on refresh.
header('Location: notifications.php');
exit();
