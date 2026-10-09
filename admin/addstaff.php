<?php
require_once __DIR__ . '/../shared_session_bootstrap.php';
require_once '../db_supabase.php';

$current_role = strtolower(trim((string)($_SESSION['role'] ?? '')));

if (($_SESSION['logged_in'] ?? false) !== true) {
    header('Location: ../admin_login.php');
    exit();
}

if ($current_role !== 'admin') {
    http_response_code(403);
    exit('Access denied. Admin only.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $session_token = (string)($_SESSION['csrf_token'] ?? '');
    $posted_token = (string)($_POST['csrf_token'] ?? '');
    if ($session_token === '' || $posted_token === '' || !hash_equals($session_token, $posted_token)) {
        http_response_code(403);
        exit('Invalid or expired request token. Refresh Personnel Management and try again.');
    }

    $full_name = trim((string)($_POST['full_name'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $role = trim((string)($_POST['role'] ?? ''));
    $position = trim((string)($_POST['position'] ?? ''));
    $plain_password = (string)($_POST['password'] ?? '');

    if ($full_name === '' || $username === '' || $role === '' || $position === '' || $plain_password === '') {
        echo "<script>alert('Please complete all required fields.'); window.history.back();</script>";
        exit();
    }

    if (!in_array($role, ['admin', 'manager', 'receptionist', 'groomer', 'pet_hotel_staff', 'veterinarian', 'vet_assistant', 'vet_nurse', 'vet', 'supervisor', 'staff'], true)) {
        echo "<script>alert('Invalid personnel role selected.'); window.history.back();</script>";
        exit();
    }

    if (mb_strlen($full_name) > 150 || mb_strlen($username) > 100) {
        echo "<script>alert('Full name or username is too long.'); window.history.back();</script>";
        exit();
    }

    if (mb_strlen($position) > 100) {
        echo "<script>alert('Position is too long. Please keep it within 100 characters.'); window.history.back();</script>";
        exit();
    }

    if (strlen($plain_password) < 8) {
        echo "<script>alert('Password must be at least 8 characters long.'); window.history.back();</script>";
        exit();
    }

    $password = password_hash($plain_password, PASSWORD_DEFAULT);

    try {
        $check_user = $pdo->prepare("
            SELECT id
            FROM users
            WHERE username = :username
            LIMIT 1
        ");
        $check_user->execute([':username' => $username]);

        if ($check_user->fetch(PDO::FETCH_ASSOC)) {
            echo "<script>alert('Error: Username is already taken! Please choose another one.'); window.history.back();</script>";
            exit();
        }

        $dummy_email = strtolower(str_replace(' ', '', $username)) . '@boogies.clinic';
        $contact = 'N/A';
        $category = 'Personnel';

        $query = "
            INSERT INTO users
                (
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
                )
            VALUES
                (
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
        ";

        $stmt = $pdo->prepare($query);
        $stmt->execute([
            ':full_name' => $full_name,
            ':username' => $username,
            ':email' => $dummy_email,
            ':contact_number' => $contact,
            ':password' => $password,
            ':role' => $role,
            ':position' => $position,
            ':user_category' => $category,
        ]);

        echo "<script>alert('Personnel account created successfully!'); window.location.href='managestaff.php';</script>";
        exit();
    } catch (PDOException $e) {
        error_log('Add staff failed: ' . $e->getMessage());
        echo "<script>alert('Error adding account. Please try again.'); window.history.back();</script>";
        exit();
    }
}

header('Location: managestaff.php');
exit();
?>
