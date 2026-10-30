<?php
/**
 * Mark the signed-in veterinary user's unread notifications as read.
 * This endpoint intentionally updates only the current user's notifications.
 */

require_once __DIR__ . '/../shared_session_bootstrap.php';

// Normalize supported veterinary role aliases.
$roleRaw = strtolower(trim((string)($_SESSION['role'] ?? '')));
$normalizedRole = preg_replace('/[\s_-]+/', ' ', $roleRaw);
$roleAliases = [
    'vet' => 'veterinarian',
    'veterinary' => 'veterinarian',
    'veterinarian' => 'veterinarian',
    'vet assistant' => 'vet_assistant',
    'veterinary assistant' => 'vet_assistant',
    'vet nurse' => 'vet_nurse',
    'veterinary nurse' => 'vet_nurse',
];
$currentRole = $roleAliases[$normalizedRole] ?? $normalizedRole;

$loginFlags = [
    $_SESSION['logged_in'] ?? false,
    $_SESSION['staff_logged_in'] ?? false,
];
$isLoggedIn = false;
foreach ($loginFlags as $flag) {
    if ($flag === true || $flag === 1 || $flag === '1' ||
        (is_string($flag) && strtolower(trim($flag)) === 'true')) {
        $isLoggedIn = true;
        break;
    }
}

if (!$isLoggedIn || !in_array($currentRole, ['veterinarian', 'vet_assistant', 'vet_nurse'], true)) {
    header('Location: stafflogin.php', true, 302);
    exit;
}

$userIdRaw = $_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? $_SESSION['id'] ?? 0;
$userId = filter_var($userIdRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($userId === false) {
    http_response_code(403);
    exit('Unable to identify the staff account.');
}

// Resolve a safe in-directory return page from a same-origin Referer only.
$allowedPages = [
    'staffdashboard.php',
    'appointments.php',
    'view_records.php',
    'edit_medical_records.php',
    'pets.php',
    'tasks.php',
    'staff_profile.php',
    'profile.php',
];
$redirectPage = 'staffdashboard.php';
$referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
$httpHost = (string)($_SERVER['HTTP_HOST'] ?? '');
$currentScheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$currentAuthority = parse_url($currentScheme . '://' . $httpHost);
$refererParts = $referer !== '' ? parse_url($referer) : false;

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

    // Supports /staff/ and deployments nested under a project folder.
    if ($sameOrigin && preg_match('~/staff/([^/]+\.php)$~i', $refererPath, $matches)) {
        $candidate = basename($matches[1]);
        if (in_array($candidate, $allowedPages, true)) {
            $redirectPage = $candidate;
            $query = (string)($refererParts['query'] ?? '');
            if ($query !== '' && preg_match('/^[A-Za-z0-9_.=&%-]*$/D', $query)) {
                $redirectPage .= '?' . $query;
            }
        }
    }
}

// State-changing requests must use POST and a token already issued in this session.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$tokenInput = $_POST['csrf_token'] ?? '';
$submittedToken = is_string($tokenInput) ? $tokenInput : '';
$sessionTokens = [
    (string)($_SESSION['csrf_token'] ?? ''),
    (string)($_SESSION['tasks_csrf'] ?? ''),
    (string)($_SESSION['staff_dashboard_csrf'] ?? ''),
];
$validToken = false;
if ($submittedToken !== '') {
    foreach ($sessionTokens as $sessionToken) {
        if ($sessionToken !== '' && hash_equals($sessionToken, $submittedToken)) {
            $validToken = true;
            break;
        }
    }
}

if (!$validToken) {
    http_response_code(403);
    exit('Invalid security token. Refresh the page and try again.');
}


header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

try {
    // Connect only after authentication and request validation.
    require_once __DIR__ . '/../db_supabase.php';

    $updateStmt = $pdo->prepare('
        UPDATE notifications
        SET is_read = 1
        WHERE user_id = :user_id
          AND is_read = 0
    ');
    $updateStmt->execute([':user_id' => $userId]);
} catch (PDOException $e) {
    error_log('Mark staff notifications read error: ' . $e->getMessage());
} catch (Throwable $e) {
    error_log('Mark staff notifications read unexpected error: ' . $e->getMessage());
}

header('Location: ' . $redirectPage, true, 303);
exit;
