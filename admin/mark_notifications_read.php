<?php
require_once __DIR__ . '/../shared_session_bootstrap.php';

/*
|--------------------------------------------------------------------------
| AUTHENTICATION AND ROLE NORMALIZATION
|--------------------------------------------------------------------------
| Admin alerts are shared Admin-only records. Groomer and Pet Hotel Staff
| may only mark notification rows addressed to their own user ID as read.
*/
$rawRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
$roleAliases = [
    'administrator' => 'admin',
    'grooming staff' => 'groomer',
    'grooming staff / groomer' => 'groomer',
    'grooming_staff' => 'groomer',
    'grooming_staff_groomer' => 'groomer',
    'staff' => 'groomer',
    'pet hotel staff' => 'pet_hotel_staff',
    'pet hotel staff / attendant' => 'pet_hotel_staff',
    'pet_hotel_attendant' => 'pet_hotel_staff',
    'pet_hotel_staff_attendant' => 'pet_hotel_staff',
];
$currentRole = $roleAliases[$rawRole] ?? $rawRole;
if ($currentRole !== '') {
    $_SESSION['role'] = $currentRole;
}

$loggedIn = ($_SESSION['logged_in'] ?? false) === true;
$userId = filter_var(
    $_SESSION['user_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$isAdmin = $currentRole === 'admin';
$isServiceStaff = in_array($currentRole, ['groomer', 'pet_hotel_staff'], true);

$adminSessionValid = $loggedIn
    && ($_SESSION['admin_logged_in'] ?? false) === true
    && $isAdmin
    && $userId !== false
    && $userId !== null;

$staffSessionValid = $loggedIn
    && ($_SESSION['staff_logged_in'] ?? false) === true
    && $isServiceStaff
    && $userId !== false
    && $userId !== null;

if (!$adminSessionValid && !$staffSessionValid) {
    http_response_code(403);
    exit('Access denied.');
}

$isAdmin = $adminSessionValid;

/*
|--------------------------------------------------------------------------
| METHOD AND CSRF VALIDATION
|--------------------------------------------------------------------------
| A few older Admin pages still use a GET anchor for "Mark all read".
| Keep that Admin-only compatibility until those pages are migrated to POST.
| Service Staff must always use POST with the shared session CSRF token.
*/
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET' && $isAdmin) {
    // Legacy Admin link compatibility; no GET access is granted to Staff.
} elseif ($method === 'POST') {
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($sessionToken) || $sessionToken === '' ||
        !is_string($postedToken) || $postedToken === '' ||
        !hash_equals($sessionToken, $postedToken)
    ) {
        http_response_code(403);
        exit('Invalid security token. Please refresh the page and try again.');
    }
} else {
    http_response_code(405);
    header('Allow: POST' . ($isAdmin ? ', GET' : ''));
    exit('Method not allowed.');
}

// shared_session_bootstrap.php normally loads the PDO connection itself.
// Keep this guarded include for compatibility with requests where a session
// was already active before the shared bootstrap was loaded.
if (!isset($pdo) || !($pdo instanceof PDO)) {
    require_once __DIR__ . '/../db_supabase.php';
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
    error_log('Mark notifications read: database connection is unavailable.');
    http_response_code(503);
    exit('Notifications are temporarily unavailable. Please try again.');
}

/**
 * Determine the correct SQL value and unread condition for a fixed table's
 * existing PostgreSQL is_read column type. Table identifiers are allow-listed.
 */
function markReadNotificationSql(PDO $pdo, string $tableName): array
{
    $allowedTables = ['admin_notifications', 'notifications'];
    if (!in_array($tableName, $allowedTables, true)) {
        throw new InvalidArgumentException('Unsupported notification table.');
    }

    $typeStmt = $pdo->prepare("SELECT data_type
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = :table_name
          AND column_name = 'is_read'
        LIMIT 1");
    $typeStmt->execute([':table_name' => $tableName]);
    $dataType = strtolower(trim((string)$typeStmt->fetchColumn()));

    if ($dataType === 'boolean') {
        return [
            'read_value' => 'TRUE',
            'unread_predicate' => 'COALESCE(is_read, FALSE) = FALSE',
        ];
    }

    if (in_array($dataType, [
        'smallint', 'integer', 'bigint', 'numeric', 'decimal', 'real', 'double precision'
    ], true)) {
        return [
            'read_value' => '1',
            'unread_predicate' => '(is_read = 0 OR is_read IS NULL)',
        ];
    }

    if (in_array($dataType, ['text', 'character varying', 'character'], true)) {
        return [
            'read_value' => "'true'",
            'unread_predicate' => "(is_read IS NULL OR LOWER(BTRIM(CAST(is_read AS TEXT))) IN ('0', 'false', 'f', 'no', 'unread', ''))",
        ];
    }

    throw new RuntimeException(
        'Unsupported or unknown is_read column type for public.' . $tableName . '.'
    );
}

try {
    if ($isAdmin) {
        $readSql = markReadNotificationSql($pdo, 'admin_notifications');
        $stmt = $pdo->prepare(
            'UPDATE public.admin_notifications '
            . 'SET is_read = ' . $readSql['read_value'] . ' '
            . 'WHERE ' . $readSql['unread_predicate']
        );
        $stmt->execute();
        $redirectTarget = 'managebooking.php';
    } else {
        $readSql = markReadNotificationSql($pdo, 'notifications');
        $stmt = $pdo->prepare(
            'UPDATE public.notifications '
            . 'SET is_read = ' . $readSql['read_value'] . ' '
            . 'WHERE user_id = :user_id AND ' . $readSql['unread_predicate']
        );
        $stmt->execute([':user_id' => (int)$userId]);
        $redirectTarget = 'admindashboard.php';
    }

    // 303 prevents a browser from replaying a successful POST on refresh.
    header('Location: ' . $redirectTarget, true, $method === 'POST' ? 303 : 302);
    exit();
} catch (Throwable $e) {
    error_log('Mark notifications read failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to mark notifications as read right now. Please try again.');
}
