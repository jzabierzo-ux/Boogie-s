<?php
// Log out the current user even if the database or audit logging is unavailable.
if (session_status() !== PHP_SESSION_ACTIVE) {
    require_once __DIR__ . '/shared_session_bootstrap.php';
}

// Preserve only the values needed for routing and the admin audit log.
$rawUserId = $_SESSION['user_id'] ?? null;
$validatedUserId = filter_var($rawUserId, FILTER_VALIDATE_INT);
$userId = ($validatedUserId !== false && $validatedUserId !== null && $validatedUserId > 0)
    ? $validatedUserId
    : null;

$rawRole = strtolower(trim((string)($_SESSION['role'] ?? '')));

// Normalize legacy and display-name role values used by the staff portal.
$roleAliases = [
    'administrator' => 'admin',
    'shop manager' => 'manager',
    'shop_manager' => 'manager',
    'front desk' => 'receptionist',
    'front desk / receptionist' => 'receptionist',
    'front_desk' => 'receptionist',
    'front_desk_receptionist' => 'receptionist',
    'supervisor' => 'receptionist',
    'grooming staff' => 'groomer',
    'grooming staff / groomer' => 'groomer',
    'grooming_staff' => 'groomer',
    'grooming_staff_groomer' => 'groomer',
    'staff' => 'groomer',
    'pet hotel staff' => 'pet_hotel_staff',
    'pet hotel staff / attendant' => 'pet_hotel_staff',
    'pet_hotel_attendant' => 'pet_hotel_staff',
    'pet_hotel_staff_attendant' => 'pet_hotel_staff',
    'vet' => 'veterinarian',
    'veterinary' => 'veterinarian',
    'veterinary assistant' => 'vet_assistant',
    'vet assistant' => 'vet_assistant',
    'veterinary_assistant' => 'vet_assistant',
    'veterinary nurse' => 'vet_nurse',
    'vet nurse' => 'vet_nurse',
    'veterinary_nurse' => 'vet_nurse',
];
$role = $roleAliases[$rawRole] ?? $rawRole;

$ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

// Keep Admin, personnel, and customer login destinations separate.
$redirectPage = 'login.php';
if ($role === 'admin') {
    $redirectPage = 'admin_login.php';
} elseif (in_array($role, [
    'manager',
    'receptionist',
    'groomer',
    'pet_hotel_staff',
    'veterinarian',
    'vet_assistant',
    'vet_nurse',
], true)) {
    $redirectPage = 'staff/stafflogin.php';
}

// Destroy the session and clear its browser cookie before trying to write logs.
// This ensures logout still completes when the database is unavailable.
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $cookieParams = session_get_cookie_params();
    $cookieOptions = [
        'expires' => time() - 42000,
        'path' => $cookieParams['path'] ?? '/',
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

// Set the redirect before audit logging, so a logging/configuration failure
// cannot leave the user on a page with an active session.
header('Location: ' . $redirectPage, true, 303);

// Admin logout audit entry is best-effort; it must never block logout.
if ($role === 'admin') {
    try {
        require_once __DIR__ . '/db_supabase.php';

        if (isset($pdo) && $pdo instanceof PDO) {
            if ($userId !== null) {
                $stmt = $pdo->prepare(
                    "INSERT INTO admin_account_logs
                        (user_id, action, status, ip_address, user_agent)
                     VALUES (:user_id, 'LOGOUT', 'SUCCESS', :ip_address, :user_agent)"
                );
                $stmt->execute([
                    ':user_id' => $userId,
                    ':ip_address' => $ipAddress,
                    ':user_agent' => $userAgent,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO admin_account_logs
                        (user_id, action, status, ip_address, user_agent)
                     VALUES (NULL, 'LOGOUT', 'SUCCESS', :ip_address, :user_agent)"
                );
                $stmt->execute([
                    ':ip_address' => $ipAddress,
                    ':user_agent' => $userAgent,
                ]);
            }
        }
    } catch (Throwable $e) {
        error_log('Admin logout audit logging failed: ' . $e->getMessage());
    }
}

exit();
