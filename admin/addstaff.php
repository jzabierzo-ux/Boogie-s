<?php
session_start();
include '../db_supabase.php';

// --- UNIVERSAL SECURITY CHECK ---
// Nilinis natin ang role para iwas error (case-insensitive)
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';

// 1. SECURITY: Allow logged-in Admins, Managers, and Vets
if (!isset($_SESSION['logged_in']) || !in_array($current_role, ['admin', 'manager', 'vet'], true)) {
    header("Location: stafflogin.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // 2. GET DATA FROM MODAL
    $full_name = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $plain_password = $_POST['password'] ?? '';

    // Basic required-field validation
    if ($full_name === '' || $username === '' || $role === '' || $position === '' || $plain_password === '') {
        echo "<script>alert('Please complete all required fields.'); window.history.back();</script>";
        exit();
    }

    // Only allow the personnel roles offered by the form.
    if (!in_array($role, ['manager', 'vet'], true)) {
        echo "<script>alert('Invalid personnel role selected.'); window.history.back();</script>";
        exit();
    }

    // We use password_hash for security
    $password = password_hash($plain_password, PASSWORD_DEFAULT);

    try {
        // 3. CHECK IF USERNAME ALREADY EXISTS
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

        // 4. DUMMY DATA PARA HINDI MAG-ERROR ANG DATABASE
        // Gagawin niyang parang email yung username just to satisfy required columns.
        $dummy_email = strtolower(str_replace(' ', '', $username)) . "@boogies.clinic";
        $contact = "N/A";
        $category = "Personnel";

        // 5. INSERT INTO DATABASE
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

        // Redirect back to staff management with a success popup
        echo "<script>alert('Personnel account created successfully!'); window.location.href='managestaff.php';</script>";
        exit();

    } catch (PDOException $e) {
        // Keep database details out of the browser.
        echo "<script>alert('Error adding account. Please try again.'); window.history.back();</script>";
        exit();
    }
}

header("Location: managestaff.php");
exit();
?>
