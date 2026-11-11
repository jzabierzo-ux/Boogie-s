<?php
declare(strict_types=1);

// Root logout endpoint for Customer, Admin, and Staff portals.
// shared_session_bootstrap.php initializes the shared session and database connection.
require_once __DIR__ . '/shared_session_bootstrap.php';

// Capture session identity before clearing it.
$loggedIn = ($_SESSION['logged_in'] ?? false) === true;
$adminFlag = ($_SESSION['admin_logged_in'] ?? false) === true;
$staffFlag = ($_SESSION['staff_logged_in'] ?? false) === true;

$rawUserId = $_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? $_SESSION['id'] ?? null;
$validatedUserId = filter_var($rawUserId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$userId = ($validatedUserId !== false && $validatedUserId !== null) ? (int)$validatedUserId : null;

// Normalize role labels and legacy aliases to stable role keys.
$normalize = static function ($value): string {
    $value = strtolower(trim((string)$value));
    return preg_replace('/[^a-z]/', '', $value) ?? '';
};

$roleAliases = [
    'administrator' => 'admin',
    'shopmanager' => 'manager',
    'frontdesk' => 'receptionist',
    'frontdeskreceptionist' => 'receptionist',
    'supervisor' => 'receptionist',
    'groomingstaff' => 'groomer',
    'groomingstaffgroomer' => 'groomer',
    'staff' => 'groomer',
    'pethotelstaffattendant' => 'pethotelstaff',
    'pethotelattendant' => 'pethotelstaff',
    'vet' => 'veterinarian',
    'veterinary' => 'veterinarian',
    'veterinaryassistant' => 'vetassistant',
    'veterinarynurse' => 'vetnurse',
];

$rawRole = $normalize($_SESSION['role'] ?? '');
$rawPosition = $normalize($_SESSION['position'] ?? '');
$role = $roleAliases[$rawRole] ?? $rawRole;
$position = $roleAliases[$rawPosition] ?? $rawPosition;

$staffRoles = [
    'manager',
    'receptionist',
    'groomer',
    'pethotelstaff',
    'veterinarian',
    'vetassistant',
    'vetnurse',
];

// Require mutually exclusive portal flags; don't treat a mixed session as Admin or Staff.
$isAdminSession = $loggedIn
    && $adminFlag
    && !$staffFlag
    && $role === 'admin'
    && $userId !== null;

// Some older accounts store a generic role and a specific position. Prefer a valid
// staff role, otherwise use the normalized position for classification.
$staffIdentity = in_array($role, $staffRoles, true)
    ? $role
    : (in_array($position, $staffRoles, true) ? $position : '');

$isStaffSession = $loggedIn
    && $staffFlag
    && !$adminFlag
    && $staffIdentity !== ''
    && $userId !== null;

// Keep portal redirects separate.
if ($isAdminSession) {
    $redirect = '/admin/admin_login.php';
} elseif ($isStaffSession) {
    $redirect = '/staff/stafflogin.php';
} else {
    $redirect = '/customer/login.php';
}

// Capture request metadata for the audit log.
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
if (!is_string($ipAddress) || filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
    $ipAddress = null;
}

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
if (!is_string($userAgent)) {
    $userAgent = null;
} else {
    $userAgent = substr($userAgent, 0, 1000);
}

// Destroy the authenticated session and expire the shared cookie.
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $cookieParams = session_get_cookie_params();
    $cookieOptions = [
        'expires' => time() - 42000,
        'path' => $cookieParams['path'] ?: '/',
        'secure' => (bool)($cookieParams['secure'] ?? false),
        'httponly' => (bool)($cookieParams['httponly'] ?? true),
        'samesite' => $cookieParams['samesite'] ?? 'Lax',
    ];
    if (!empty($cookieParams['domain'])) {
        $cookieOptions['domain'] = $cookieParams['domain'];
    }
    setcookie(session_name(), '', $cookieOptions);
}

session_destroy();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Location: ' . $redirect, true, 303);

// Record Admin and Staff logout events. Customer logout is intentionally excluded.
// Reuse the PDO connection initialized by the shared bootstrap. Never open a new
// connection here: a missing DB configuration must not interrupt logout.
if (($isAdminSession || $isStaffSession) && $userId !== null) {
    try {
        if (isset($pdo) && $pdo instanceof PDO) {
            $auditStmt = $pdo->prepare(
                "INSERT INTO admin_account_logs
                    (user_id, action, status, ip_address, user_agent)
                 VALUES (:user_id, 'LOGOUT', 'SUCCESS', :ip_address, :user_agent)"
            );
            $auditStmt->execute([
                ':user_id' => $userId,
                ':ip_address' => $ipAddress,
                ':user_agent' => $userAgent,
            ]);
        } else {
            error_log('Account logout audit logging skipped: shared PDO connection is unavailable.');
        }
    } catch (Throwable $e) {
        error_log('Account logout audit logging failed: ' . $e->getMessage());
    }
}

exit();
