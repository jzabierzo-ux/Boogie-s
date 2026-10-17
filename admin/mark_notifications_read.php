<?php
require_once __DIR__ . '/../shared_session_bootstrap.php';

/*
|--------------------------------------------------------------------------
| AUTHENTICATION AND ROLE NORMALIZATION
|--------------------------------------------------------------------------
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

$isLoggedIn = ($_SESSION['logged_in'] ?? false) === true;
$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$isAdmin = $currentRole === 'admin';
$isServiceStaff = in_array($currentRole, ['groomer', 'pet_hotel_staff'], true);

if (!$isLoggedIn || (!$isAdmin && !$isServiceStaff)) {
    http_response_code(403);
    exit('Access denied.');
}
if ($userId === false || $userId === null) {
    http_response_code(403);
    exit('Access denied: invalid session.');
}

/*
|--------------------------------------------------------------------------
| METHOD + CSRF CHECK
|--------------------------------------------------------------------------
| Keep legacy GET compatibility for Admin while other admin-page links are
| migrated. Service staff must always submit POST + CSRF.
*/
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET' && $isAdmin) {
    // Transitional compatibility for older Admin notification links only.
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

require_once __DIR__ . '/../db_supabase.php';

/**
 * Return a safe update value and unread predicate for the table's actual
 * is_read type. Only fixed, allow-listed table names may be passed here.
 */
function getNotificationReadSql(PDO $pdo, string $tableName): array
{
    $allowedTables = ['admin_notifications', 'notifications'];
    if (!in_array($tableName, $allowedTables, true)) {
        throw new InvalidArgumentException('Unsupported notification table.');
    }

    $typeStmt = $pdo->prepare("SELECT data_type
        FROM information_schema.columns
        WHERE table_schema = current_schema()
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

    if (in_array($dataType, ['smallint', 'integer', 'bigint', 'numeric', 'decimal', 'real', 'double precision'], true)) {
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

    throw new RuntimeException('Unsupported or unknown is_read column type for ' . $tableName . '.');
}

try {
    if ($isAdmin) {
        $readSql = getNotificationReadSql($pdo, 'admin_notifications');
        // Admin alerts are shared Admin-only records, as in the existing schema.
        $stmt = $pdo->prepare('UPDATE admin_notifications SET is_read = ' . $readSql['read_value'] . ' WHERE ' . $readSql['unread_predicate']);
        $stmt->execute();
        $redirectTarget = 'managebooking.php';
    } else {
        $readSql = getNotificationReadSql($pdo, 'notifications');
        // Service staff may update only notification rows addressed to themselves.
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = ' . $readSql['read_value'] . ' WHERE user_id = :user_id AND ' . $readSql['unread_predicate']);
        $stmt->execute([':user_id' => (int)$userId]);
        $redirectTarget = 'admindashboard.php';
    }
} catch (Throwable $e) {
    error_log('Mark notifications read failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to mark notifications as read right now. Please try again.');
}

header('Location: ' . $redirectTarget);
exit;
