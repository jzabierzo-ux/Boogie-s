<?php
declare(strict_types=1);

require_once __DIR__ . '/shared_session_bootstrap.php';

// Capture the role before clearing the session.
$role = strtolower(trim((string) ($_SESSION['role'] ?? '')));
$position = strtolower(trim((string) ($_SESSION['position'] ?? '')));

$normalize = static fn(string $value): string =>
    preg_replace('/[^a-z]/', '', $value) ?? '';

$role = $normalize($role);
$position = $normalize($position);

$staffRoles = [
    'manager',
    'shopmanager',
    'receptionist',
    'frontdesk',
    'groomer',
    'groomingstaff',
    'pethotelstaff',
    'pethotelstaffattendant',
    'pethotelattendant',
    'veterinarian',
    'veterinary',
    'vet',
    'vetassistant',
    'veterinaryassistant',
    'vetnurse',
    'veterinarynurse',
    'staff',
    'supervisor'
];

$isAdmin = ($_SESSION['admin_logged_in'] ?? false) === true
    || in_array($role, ['admin', 'administrator'], true);

$isStaff = in_array($role, $staffRoles, true)
    || in_array($position, $staffRoles, true);

// Determine the destination before destroying the session.
if ($isAdmin) {
    $redirect = '/admin/admin_login.php';
} elseif ($isStaff) {
    $redirect = '/staff/stafflogin.php';
} else {
    $redirect = '/customer/login.php';
}

// Clear the session.
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'] ?: '/',
        $params['domain'] ?? '',
        (bool) $params['secure'],
        (bool) $params['httponly']
    );
}

session_destroy();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Location: ' . $redirect, true, 302);
exit;