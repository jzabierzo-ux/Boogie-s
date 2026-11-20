<?php
require_once __DIR__ . '/../shared_session_bootstrap.php';

// Only an authenticated account may update its own notifications.
if (($_SESSION['logged_in'] ?? false) !== true) {
    header('Location: login.php', true, 303);
    exit();
}

$userId = filter_var(
    $_SESSION['user_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
if ($userId === false || $userId === null) {
    header('Location: login.php', true, 303);
    exit();
}

// This endpoint changes data, so accept POST requests only.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: notifications.php', true, 303);
    exit();
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

// Verify the CSRF token before accessing the notification record.
$submittedToken = $_POST['csrf_token'] ?? null;
$sessionToken = $_SESSION['csrf_token'] ?? null;
if (
    !is_string($submittedToken) ||
    !is_string($sessionToken) ||
    $submittedToken === '' ||
    $sessionToken === '' ||
    !hash_equals($sessionToken, $submittedToken)
) {
    http_response_code(403);
    exit('Invalid security token. Please return to notifications and try again.');
}

$rawNotificationId = $_POST['notification_id'] ?? null;
$notificationId = is_scalar($rawNotificationId)
    ? filter_var((string) $rawNotificationId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
if ($notificationId === false || $notificationId === null) {
    header('Location: notifications.php', true, 303);
    exit();
}

require_once __DIR__ . '/../db_supabase.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(503);
    exit('Database connection is unavailable. Please try again.');
}

try {
    // Read the exact column type from the public table. The supported SQL
    // values below are fixed literals; no user input is inserted into SQL.
    $typeStmt = $pdo->prepare(
        "SELECT data_type, udt_name
         FROM information_schema.columns
         WHERE table_schema = 'public'
           AND table_name = 'notifications'
           AND column_name = 'is_read'
         LIMIT 1"
    );
    $typeStmt->execute();
    $columnType = $typeStmt->fetch(PDO::FETCH_ASSOC);

    if (!$columnType) {
        throw new RuntimeException('The public.notifications.is_read column is missing.');
    }

    $dataType = strtolower(trim((string) ($columnType['data_type'] ?? '')));
    $udtName = strtolower(trim((string) ($columnType['udt_name'] ?? '')));

    if ($dataType === 'boolean' || $udtName === 'bool') {
        $readValueSql = 'TRUE';
    } elseif (in_array($dataType, ['smallint', 'integer', 'bigint', 'numeric', 'decimal', 'real', 'double precision'], true)
        || in_array($udtName, ['int2', 'int4', 'int8', 'numeric', 'float4', 'float8'], true)) {
        $readValueSql = '1';
    } elseif (in_array($dataType, ['text', 'character varying', 'character'], true)
        || in_array($udtName, ['text', 'varchar', 'bpchar'], true)) {
        $readValueSql = "'1'";
    } else {
        throw new RuntimeException('Unsupported notifications.is_read column type: ' . $dataType . '.');
    }

    // Ownership is enforced in the UPDATE itself. A notification belonging
    // to another customer can never be marked as read through this endpoint.
    // Already-read records are left unchanged.
    $updateStmt = $pdo->prepare(
        "UPDATE public.notifications
         SET is_read = {$readValueSql}
         WHERE id = :notification_id
           AND user_id = :user_id
           AND is_read IS DISTINCT FROM {$readValueSql}"
    );
    $updateStmt->execute([
        ':notification_id' => (int) $notificationId,
        ':user_id' => (int) $userId,
    ]);
} catch (Throwable $e) {
    error_log('Mark customer notification as read failed: ' . $e->getMessage());
    $_SESSION['notification_update_error'] = 'Unable to update the notification right now. Please try again.';
}

// Post/Redirect/Get prevents accidental form resubmission on refresh.
header('Location: notifications.php', true, 303);
exit();
