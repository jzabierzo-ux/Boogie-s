<?php
require_once __DIR__ . '/../shared_session_bootstrap.php';

// This endpoint creates personnel accounts and is restricted to Admin sessions.
$raw_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$current_role = ($raw_role === 'administrator') ? 'admin' : $raw_role;

$is_admin_session = (
    ($_SESSION['logged_in'] ?? false) === true &&
    ($_SESSION['admin_logged_in'] ?? false) === true &&
    ($_SESSION['staff_logged_in'] ?? false) !== true &&
    $current_role === 'admin' &&
    filter_var(
        $_SESSION['user_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    ) !== false
);

if (!$is_admin_session) {
    if (($_SESSION['logged_in'] ?? false) !== true) {
        header('Location: admin_login.php');
        exit();
    }

    http_response_code(403);
    exit('Access denied. Admin only.');
}

// Do not initialize the database connection for unauthorized requests.
require_once __DIR__ . '/../db_supabase.php';

// Re-check the current account's database role so a stale or inconsistent
// Admin session cannot continue managing personnel after its role is changed.
$adminUserId = (int)($_SESSION['user_id'] ?? 0);
try {
    $adminRoleStmt = $pdo->prepare(
        "SELECT LOWER(BTRIM(COALESCE(role, ''))) FROM users WHERE id = :id LIMIT 1"
    );
    $adminRoleStmt->execute([':id' => $adminUserId]);
    $databaseRole = (string)($adminRoleStmt->fetchColumn() ?: '');
} catch (Throwable $e) {
    error_log('Add staff authorization check failed: ' . $e->getMessage());
    http_response_code(503);
    exit('Unable to verify Admin authorization. Please try again later.');
}

if (!in_array($databaseRole, ['admin', 'administrator'], true)) {
    http_response_code(403);
    exit('Access denied. Your account is not authorized to manage personnel.');
}

/** Return a safe string value from POST without converting arrays to "Array". */
function staffPostString(string $key, bool $trim = true): string
{
    $value = $_POST[$key] ?? '';
    if (!is_string($value)) {
        return '';
    }

    return $trim ? trim($value) : $value;
}

/** Display a fixed, safely encoded message and return to the form. */
function staffFormAlert(string $message): void
{
    $encodedMessage = json_encode(
        $message,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
    );
    echo '<script>alert(' . $encodedMessage . '); window.history.back();</script>';
    exit();
}

/** Use multibyte length when available, with a safe fallback. */
function staffStringLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify the session token before processing any submitted account data.
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($sessionToken) ||
        !is_string($postedToken) ||
        $sessionToken === '' ||
        $postedToken === '' ||
        !hash_equals($sessionToken, $postedToken)
    ) {
        http_response_code(403);
        exit('Invalid or expired request token. Refresh Personnel Management and try again.');
    }

    $full_name = staffPostString('full_name');
    $username = staffPostString('username');
    $role = strtolower(staffPostString('role'));
    // Do not trim passwords: leading/trailing spaces may be intentional.
    $plain_password = staffPostString('password', false);

    if ($full_name === '' || $username === '' || $role === '' || $plain_password === '') {
        staffFormAlert('Please complete all required fields.');
    }

    // Only the three roles approved in the panel revision may be created.
    // "manager" is the existing internal role used by the Staff portal; the
    // Personnel page displays it as the unified role "Staff". This keeps the
    // current Staff login route compatible while old accounts remain supported.
    $allowedRoles = ['admin', 'manager', 'veterinarian'];

    if (!in_array($role, $allowedRoles, true)) {
        staffFormAlert('Invalid personnel role selected. Only Admin, Staff, and Veterinarian accounts can be created.');
    }

    // Position is derived on the server from the selected role. No separate
    // position field is required or trusted from the browser.
    $rolePositions = [
        'admin' => 'Administrator',
        'manager' => 'Staff',
        'veterinarian' => 'Veterinarian',
    ];
    $position = $rolePositions[$role];

    if (staffStringLength($full_name) > 150 || staffStringLength($username) > 100) {
        staffFormAlert('Full name or username is too long.');
    }

    if (strlen($plain_password) < 8) {
        staffFormAlert('Password must be at least 8 characters long.');
    }

    $passwordHash = password_hash($plain_password, PASSWORD_DEFAULT);
    if ($passwordHash === false) {
        error_log('Add staff failed: password hashing returned false.');
        staffFormAlert('Unable to secure the password. Please try again.');
    }

    try {
        // Keep the friendly duplicate check; a database unique constraint is still
        // recommended to protect against simultaneous requests.
        $checkUser = $pdo->prepare(
            'SELECT 1 FROM users WHERE username = :username LIMIT 1'
        );
        $checkUser->execute([':username' => $username]);

        if ($checkUser->fetchColumn() !== false) {
            staffFormAlert('Error: Username is already taken! Please choose another one.');
        }

        $dummyEmail = strtolower(preg_replace('/\s+/', '', $username)) . '@boogies.clinic';
        $contact = 'N/A';
        $category = 'Personnel';

        $insertSql = <<<'SQL'
            INSERT INTO users (
                full_name,
                username,
                email,
                contact_number,
                password,
                role,
                position,
                user_category,
                is_verified,
                email_verified
            ) VALUES (
                :full_name,
                :username,
                :email,
                :contact_number,
                :password,
                :role,
                :position,
                :user_category,
                1,
                1
            )
        SQL;

        $insertStmt = $pdo->prepare($insertSql);
        $insertStmt->execute([
            ':full_name' => $full_name,
            ':username' => $username,
            ':email' => $dummyEmail,
            ':contact_number' => $contact,
            ':password' => $passwordHash,
            ':role' => $role,
            ':position' => $position,
            ':user_category' => $category,
        ]);

        echo "<script>alert('Personnel account created successfully!'); window.location.href='managestaff.php';</script>";
        exit();
    } catch (PDOException $e) {
        error_log('Add staff failed: ' . $e->getMessage());

        // PostgreSQL unique-constraint violation (for example, simultaneous
        // submissions after both requests passed the pre-check).
        $sqlState = (string)($e->errorInfo[0] ?? $e->getCode());
        if ($sqlState === '23505') {
            staffFormAlert('Error: Username or generated email is already in use. Please use a different username.');
        }

        staffFormAlert('Error adding account. Please try again.');
    } catch (Throwable $e) {
        error_log('Add staff failed unexpectedly: ' . $e->getMessage());
        staffFormAlert('Error adding account. Please try again.');
    }
}

header('Location: managestaff.php');
exit();
?>
