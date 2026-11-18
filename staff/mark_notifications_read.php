<?php
/**
 * Mark the signed-in veterinary staff member's unread notifications as read.
 * This endpoint never reads or updates Admin notifications or another user's alerts.
 */

require_once __DIR__ . '/../shared_session_bootstrap.php';

// Prevent cached copies of this state-changing endpoint and its redirects.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: SAMEORIGIN');

/** Interpret only explicit truthy values used by the application's session code. */
function staffMarkReadFlagIsTrue($value): bool
{
    return $value === true
        || $value === 1
        || $value === '1'
        || (is_string($value) && strtolower(trim($value)) === 'true');
}

/** Normalize supported database role aliases without changing the stored role. */
function staffMarkReadNormalizeRole($role): string
{
    $role = strtolower(trim(is_scalar($role) ? (string)$role : ''));
    $role = preg_replace('/[\s_-]+/', ' ', $role) ?? $role;

    $aliases = [
        'vet' => 'veterinarian',
        'veterinary' => 'veterinarian',
        'veterinarian' => 'veterinarian',
        'vet assistant' => 'vet_assistant',
        'veterinary assistant' => 'vet_assistant',
        'vet nurse' => 'vet_nurse',
        'veterinary nurse' => 'vet_nurse',
    ];

    return $aliases[$role] ?? str_replace(' ', '_', $role);
}

/** Resolve a legacy database role to one of the application's three system roles. */
function staffMarkReadSystemRole($role): string
{
    $normalized = staffMarkReadNormalizeRole($role);

    if (in_array($normalized, ['veterinarian', 'vet_assistant', 'vet_nurse'], true)) {
        return 'veterinarian';
    }
    if (in_array($normalized, ['admin', 'administrator'], true)) {
        return 'admin';
    }
    if (in_array($normalized, ['staff', 'manager', 'receptionist', 'groomer', 'pet_hotel_staff', 'supervisor'], true)) {
        return 'staff';
    }

    return '';
}

/** Return a type-safe unread predicate and value for the notifications.is_read column. */
function staffMarkReadNotificationSql(PDO $pdo): array
{
    $typeStmt = $pdo->prepare(
        "SELECT data_type
         FROM information_schema.columns
         WHERE table_schema = 'public'
           AND table_name = 'notifications'
           AND column_name = 'is_read'
         LIMIT 1"
    );
    $typeStmt->execute();
    $dataType = strtolower(trim((string)$typeStmt->fetchColumn()));

    if ($dataType === 'boolean') {
        return ['COALESCE(is_read, FALSE) = FALSE', 'TRUE'];
    }

    if (in_array($dataType, ['smallint', 'integer', 'bigint', 'numeric', 'decimal', 'real', 'double precision'], true)) {
        return ['(is_read = 0 OR is_read IS NULL)', '1'];
    }

    if (in_array($dataType, ['text', 'character varying', 'character'], true)) {
        return [
            "(is_read IS NULL OR LOWER(BTRIM(CAST(is_read AS TEXT))) IN ('0', 'false', 'f', 'no', 'unread', ''))",
            "'1'"
        ];
    }

    throw new RuntimeException('Unsupported or unknown notifications.is_read column type.');
}

// Require a valid Staff portal session and explicitly reject Admin sessions.
$sessionRole = staffMarkReadNormalizeRole($_SESSION['role'] ?? '');
$sessionSystemRole = strtolower(trim((string)($_SESSION['system_role'] ?? '')));
$loggedIn = staffMarkReadFlagIsTrue($_SESSION['logged_in'] ?? false);
$staffLoggedIn = staffMarkReadFlagIsTrue($_SESSION['staff_logged_in'] ?? false);
$adminLoggedIn = staffMarkReadFlagIsTrue($_SESSION['admin_logged_in'] ?? false);
$allowedVeterinaryRoles = ['veterinarian', 'vet_assistant', 'vet_nurse'];

if (
    !$loggedIn
    || !$staffLoggedIn
    || $adminLoggedIn
    || $sessionSystemRole !== 'veterinarian'
    || !in_array($sessionRole, $allowedVeterinaryRoles, true)
) {
    header('Location: stafflogin.php', true, 302);
    exit;
}

$userIdRaw = $_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? $_SESSION['id'] ?? null;
$userId = filter_var($userIdRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($userId === false || $userId === null) {
    http_response_code(403);
    exit('Unable to identify the staff account.');
}
$userId = (int)$userId;

// State-changing requests must use POST.
$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($requestMethod !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

// Accept the CSRF token names issued by the Staff pages using this endpoint.
$tokenInput = $_POST['csrf_token'] ?? '';
$submittedToken = is_string($tokenInput) ? $tokenInput : '';
$sessionTokens = [
    $_SESSION['csrf_token'] ?? '',
    $_SESSION['tasks_csrf'] ?? '',
    $_SESSION['staff_dashboard_csrf'] ?? '',
];
$validToken = false;

if ($submittedToken !== '') {
    foreach ($sessionTokens as $sessionToken) {
        if (
            is_string($sessionToken)
            && $sessionToken !== ''
            && hash_equals($sessionToken, $submittedToken)
        ) {
            $validToken = true;
            break;
        }
    }
}

if (!$validToken) {
    http_response_code(403);
    exit('Invalid security token. Refresh the page and try again.');
}

// Resolve a safe in-directory return page from a same-origin Referer only.
$allowedPages = [
    'staffdashboard.php',
    'appointments.php',
    'view_records.php',
    'edit_medical.php',
    'edit_medical_records.php',
    'pets.php',
    'edit.php',
    'tasks.php',
    'staff_profile.php',
    'profile.php',
];
$redirectPage = 'staffdashboard.php';
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$httpHost = $_SERVER['HTTP_HOST'] ?? '';
$currentScheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$currentAuthority = is_string($httpHost) && $httpHost !== ''
    ? parse_url($currentScheme . '://' . $httpHost)
    : false;
$refererParts = is_string($referer) && $referer !== '' ? parse_url($referer) : false;

if (is_array($currentAuthority) && is_array($refererParts)) {
    $currentHost = strtolower((string)($currentAuthority['host'] ?? ''));
    $refererHost = strtolower((string)($refererParts['host'] ?? ''));
    $currentPort = (int)($currentAuthority['port'] ?? ($currentScheme === 'https' ? 443 : 80));
    $refererScheme = strtolower((string)($refererParts['scheme'] ?? ''));
    $refererPort = (int)($refererParts['port'] ?? ($refererScheme === 'https' ? 443 : 80));
    $refererPath = (string)($refererParts['path'] ?? '');

    $sameOrigin = in_array($refererScheme, ['http', 'https'], true)
        && $refererScheme === $currentScheme
        && $currentHost !== ''
        && hash_equals($currentHost, $refererHost)
        && $currentPort === $refererPort;

    // Supports /staff/ and deployments nested beneath a project folder.
    if ($sameOrigin && preg_match('~/staff/([^/]+\.php)$~i', $refererPath, $matches)) {
        $candidate = basename($matches[1]);
        if (in_array($candidate, $allowedPages, true)) {
            $redirectPage = $candidate;
            $query = (string)($refererParts['query'] ?? '');
            // Copy only a restricted query-string character set.
            if ($query !== '' && preg_match('/^[A-Za-z0-9_.=&%-]*$/D', $query)) {
                $redirectPage .= '?' . $query;
            }
        }
    }
}

try {
    // Load the database only after session, role, method, and CSRF checks.
    require_once __DIR__ . '/../db_supabase.php';
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is unavailable.');
    }

    // Re-check the account role in the database; session values alone are not trusted.
    $roleStmt = $pdo->prepare('SELECT role FROM users WHERE id = :user_id LIMIT 1');
    $roleStmt->execute([':user_id' => $userId]);
    $databaseRole = $roleStmt->fetchColumn();

    if ($databaseRole === false || $databaseRole === null) {
        http_response_code(403);
        exit('Access denied. The account could not be verified.');
    }

    $normalizedDatabaseRole = staffMarkReadNormalizeRole($databaseRole);
    $databaseSystemRole = staffMarkReadSystemRole($normalizedDatabaseRole);

    if (
        !in_array($normalizedDatabaseRole, $allowedVeterinaryRoles, true)
        || $normalizedDatabaseRole !== $sessionRole
        || $databaseSystemRole !== 'veterinarian'
    ) {
        http_response_code(403);
        exit('Access denied. Veterinary Staff access is required.');
    }

    // Update only this signed-in veterinary user's unread notifications.
    [$unreadSql, $markReadValue] = staffMarkReadNotificationSql($pdo);
    $updateStmt = $pdo->prepare(
        "UPDATE notifications
         SET is_read = {$markReadValue}
         WHERE user_id = :user_id
           AND {$unreadSql}"
    );
    $updateStmt->execute([':user_id' => $userId]);
} catch (Throwable $e) {
    error_log('Mark veterinary staff notifications read failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to update notifications right now. Please try again.');
}

header('Location: ' . $redirectPage, true, 303);
exit;
