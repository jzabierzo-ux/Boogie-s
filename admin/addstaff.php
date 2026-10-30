<?php
require_once __DIR__ . '/../shared_session_bootstrap.php';

// This endpoint creates personnel accounts and is restricted to Admins.
$current_role = strtolower(trim((string)($_SESSION['role'] ?? '')));

if (($_SESSION['logged_in'] ?? false) !== true) {
    header('Location: ../admin_login.php');
    exit();
}

if ($current_role !== 'admin') {
    http_response_code(403);
    exit('Access denied. Admin only.');
}

// Do not initialize the database connection for unauthorized requests.
require_once __DIR__ . '/../db_supabase.php';

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
    $position = staffPostString('position');
    // Do not trim passwords: leading/trailing spaces may be intentional.
    $plain_password = staffPostString('password', false);

    if ($full_name === '' || $username === '' || $role === '' || $position === '' || $plain_password === '') {
        staffFormAlert('Please complete all required fields.');
    }

    $allowedRoles = [
        'admin',
        'manager',
        'receptionist',
        'groomer',
        'pet_hotel_staff',
        'veterinarian',
        'vet_assistant',
        'vet_nurse',
        'vet',
        'supervisor',
        'staff',
    ];

    if (!in_array($role, $allowedRoles, true)) {
        staffFormAlert('Invalid personnel role selected.');
    }

    if (staffStringLength($full_name) > 150 || staffStringLength($username) > 100) {
        staffFormAlert('Full name or username is too long.');
    }

    if (staffStringLength($position) > 100) {
        staffFormAlert('Position is too long. Please keep it within 100 characters.');
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
