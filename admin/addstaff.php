<?php
session_start();
include '../db_supabase.php';

// --- UNIVERSAL SECURITY CHECK ---
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';

// Allow logged-in Admins, Managers, and Vets.
// NOTE: Because this file is inside /admin, the staff login path is ../staff/stafflogin.php.
if (!isset($_SESSION['logged_in']) || !in_array($current_role, ['admin', 'manager', 'vet', 'supervisor', 'staff'], true)) {
    header("Location: ../staff/stafflogin.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $full_name = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $plain_password = $_POST['password'] ?? '';

    if ($full_name === '' || $username === '' || $role === '' || $position === '' || $plain_password === '') {
        echo "<script>alert('Please complete all required fields.'); window.history.back();</script>";
        exit();
    }

    if (!in_array($role, ['admin', 'manager', 'vet', 'supervisor', 'staff'], true)) {
        echo "<script>alert('Invalid personnel role selected.'); window.history.back();</script>";
        exit();
    }

    if ($role === 'admin' && $current_role !== 'admin') {
        echo "<script>alert('Only an Admin can create another Admin account.'); window.history.back();</script>";
        exit();
    }

    if (mb_strlen($position) > 100) {
        echo "<script>alert('Position is too long. Please keep it within 100 characters.'); window.history.back();</script>";
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

        $dummy_email = strtolower(str_replace(' ', '', $username)) . "@boogies.clinic";
        $contact = "N/A";
        $category = "Personnel";

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
                    TRUE,
                    TRUE
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
            ':user_category' => $category
        ]);

        echo "<script>alert('Personnel account created successfully!'); window.location.href='managestaff.php';</script>";
        exit();

    } catch (PDOException $e) {
        error_log("Add staff failed: " . $e->getMessage());
        echo "<script>alert('Error adding account. Please try again.'); window.history.back();</script>";
        exit();
    }
}

header("Location: managestaff.php");
exit();
?>
