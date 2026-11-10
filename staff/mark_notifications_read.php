<?php
/**
 * Mark the signed-in veterinary staff member's unread notifications as read.
 * This endpoint never reads or updates Admin notifications.
 */

require_once __DIR__ . '/../shared_session_bootstrap.php';

// Do not allow cached copies of a state-changing endpoint or its redirects.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: SAMEORIGIN');

/** Interpret only the explicit truthy values used by the existing session code. */
function staffMarkReadFlagIsTrue($value): bool
{
    return $value === true || $value === 1 || $value === '1'
        || (is_string($value) && strtolower(trim($value)) === 'true');
}

/** Normalize supported role aliases to the canonical role stored in the app. */
function staffMarkReadNormalizeRole($role): string
{
    $role = strtolower(trim((string)$role));
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

// Require the dedicated Staff session flag and explicitly reject Admin sessions.
$sessionRole = staffMarkReadNormalizeRole($_SESSION['role'] ?? '');
$loggedIn = staffMarkReadFlagIsTrue($_SESSION['logged_in'] ?? false);
$staffLoggedIn = staffMarkReadFlagIsTrue($_SESSION['staff_logged_in'] ?? false);
$adminLoggedIn = staffMarkReadFlagIsTrue($_SESSION['admin_logged_in'] ?? false);
$allowedRoles = ['veterinarian', 'vet_assistant', 'vet_nurse'];

if (
    !$loggedIn ||
    !$staffLoggedIn ||
    $adminLoggedIn ||
    !in_array($sessionRole, $allowedRoles, true)
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
$_SESSION['role'] = $sessionRole;

// State-changing requests must use POST.
$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($requestMethod !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

// Accept the CSRF token names already issued by the Staff pages using this endpoint.
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
        if (is_string($sessionToken) && $sessionToken !== '' && hash_equals($sessionToken, $submittedToken)) {
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
            // Query strings are copied only when they contain safe URL characters.
            if ($query !== '' && preg_match('/^[A-Za-z0-9_.=&%-]*$/D', $query)) {
                $redirectPage .= '?' . $query;
            }
        }
    }
}

try {
    // Load the database only after session, role, HTTP method, and CSRF checks.
    require_once __DIR__ . '/../db_supabase.php';
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is unavailable.');
    }

    // Re-check the role against the database so a stale or modified session cannot
    // authorize another account type to use this veterinary-only endpoint.
    $roleStmt = $pdo->prepare('SELECT role FROM users WHERE id = :user_id LIMIT 1');
    $roleStmt->execute([':user_id' => $userId]);
    $databaseRole = $roleStmt->fetchColumn();
    $normalizedDatabaseRole = staffMarkReadNormalizeRole($databaseRole);

    if (!in_array($normalizedDatabaseRole, $allowedRoles, true) || $normalizedDatabaseRole !== $sessionRole) {
        http_response_code(403);
        exit('Access denied. Veterinary Staff access is required.');
    }

    // Update only the current veterinary user's own unread notifications.
    $updateStmt = $pdo->prepare(
        'UPDATE notifications
         SET is_read = 1
         WHERE user_id = :user_id
           AND is_read = 0'
    );
    $updateStmt->execute([':user_id' => $userId]);
} catch (Throwable $e) {
    error_log('Mark veterinary staff notifications read failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to update notifications right now. Please try again.');
}

header('Location: ' . $redirectPage, true, 303);
exit;
