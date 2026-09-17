<?php
session_start();
include '../db_connect.php'; 

// --- UNIVERSAL SECURITY CHECK ---
// Nilinis natin ang role para iwas error (case-insensitive)
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';

// 1. SECURITY: Allow logged-in Admins, Managers, and Vets
if (!isset($_SESSION['logged_in']) || !in_array($current_role, ['admin', 'manager', 'vet'])) {
    header("Location: stafflogin.php"); 
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // 2. GET DATA FROM MODAL & SANITIZE
    $full_name = mysqli_real_escape_string($conn, $_POST['full_name']);
    
    // BAGO: Kukunin na natin ang 'username' galing sa form
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    
    $role = mysqli_real_escape_string($conn, $_POST['role']); 
    $position = mysqli_real_escape_string($conn, $_POST['position']);
    
    // We use password_hash for security
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    // 3. CHECK IF USERNAME ALREADY EXISTS
    $check_user = mysqli_query($conn, "SELECT id FROM users WHERE username = '$username'");
    if (mysqli_num_rows($check_user) > 0) {
        echo "<script>alert('Error: Username is already taken! Please choose another one.'); window.history.back();</script>";
        exit();
    }

    // 4. DUMMY DATA PARA HINDI MAG-ERROR ANG DATABASE (Dahil bawal ang NULL sa old columns)
    // Gagawin niyang parang email yung username just to satisfy the database requirements
    $dummy_email = strtolower(str_replace(' ', '', $username)) . "@boogies.clinic";
    $contact = "N/A";
    $category = "Personnel";

    // 5. INSERT INTO DATABASE (Isinama ang 'username' at naka auto-verify agad)
    $query = "INSERT INTO users (full_name, username, email, contact_number, password, role, position, user_category, is_verified, email_verified) 
              VALUES ('$full_name', '$username', '$dummy_email', '$contact', '$password', '$role', '$position', '$category', 1, 1)";

    if (mysqli_query($conn, $query)) {
        // Redirect back to staff management with a success popup
        echo "<script>alert('Personnel account created successfully!'); window.location.href='managestaff.php';</script>";
        exit();
    } else {
        // If it fails, show the error without breaking the page
        echo "<script>alert('Error adding account: " . mysqli_error($conn) . "'); window.history.back();</script>";
        exit();
    }
}
?>