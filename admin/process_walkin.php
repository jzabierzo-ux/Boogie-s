<?php
session_start();
require_once '../db_connect.php'; 

// 1. SECURITY CHECK
if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['role'], ['admin', 'supervisor', 'staff'])) {
    header("Location: stafflogin.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 2. KUNIN ANG MGA INPUTS MULA SA FORM
    $owner_name = mysqli_real_escape_string($conn, trim($_POST['owner_name']));
    $contact    = mysqli_real_escape_string($conn, trim($_POST['contact'])); 
    $pet_name   = mysqli_real_escape_string($conn, trim($_POST['pet_name']));
    $pet_type   = mysqli_real_escape_string($conn, $_POST['pet_type']);
    $breed      = mysqli_real_escape_string($conn, trim($_POST['breed'] ?? 'Unknown')); 
    $service    = mysqli_real_escape_string($conn, $_POST['service']);
    $vet_doctor = mysqli_real_escape_string($conn, $_POST['vet_doctor']);
    $service_fee= floatval($_POST['service_fee']);

    $user_id = 0;
    $pet_id = 0;
    
    date_default_timezone_set('Asia/Manila');
    $appointment_date = date('Y-m-d'); 
    $appointment_time = date('H:i:s'); 

    // =========================================================================
    // CAPACITY CHECKER (Para sa Defense: Vet=6, Grooming=10)
    // =========================================================================
    if (strpos($service, 'Vet Services') === 0) {
        $cap_check = mysqli_query($conn, "SELECT COUNT(*) as count FROM appointments WHERE appointment_date = '$appointment_date' AND service LIKE 'Vet Services%' AND booking_status != 'Cancelled'");
        $cap_row = mysqli_fetch_assoc($cap_check);
        if ($cap_row['count'] >= 6) {
            die("<script>alert('Walk-in Failed: Vet Clinic is already fully booked for today (Max 6).'); window.history.back();</script>");
        }
    } elseif (strpos($service, 'Grooming') === 0) {
        $cap_check = mysqli_query($conn, "SELECT COUNT(*) as count FROM appointments WHERE appointment_date = '$appointment_date' AND service LIKE 'Grooming%' AND booking_status != 'Cancelled'");
        $cap_row = mysqli_fetch_assoc($cap_check);
        if ($cap_row['count'] >= 10) {
            die("<script>alert('Walk-in Failed: Grooming is already fully booked for today (Max 10).'); window.history.back();</script>");
        }
    }

    // =========================================================================
    // QUICK AUTO-REGISTER LOGIC 
    // =========================================================================

    $check_user = mysqli_query($conn, "SELECT id FROM users WHERE full_name = '$owner_name' LIMIT 1");
    
    if ($check_user && mysqli_num_rows($check_user) > 0) {
        $u_row = mysqli_fetch_assoc($check_user);
        $user_id = $u_row['id'];
        
        // I-update ang contact number ng existing user kung sakaling nagbago
        mysqli_query($conn, "UPDATE users SET contact_number = '$contact' WHERE id = '$user_id'");
        
    } else {
        // GAGAWA NG GUEST ACCOUNT 
        $dummy_email = "walkin_" . time() . "_" . rand(100, 999) . "@guest.local";
        $dummy_pass = password_hash("boogieswalkin", PASSWORD_DEFAULT);
        
        $insert_user = "INSERT INTO users (full_name, contact_number, email, password, role) 
                        VALUES ('$owner_name', '$contact', '$dummy_email', '$dummy_pass', 'customer')";
        mysqli_query($conn, $insert_user);
        $user_id = mysqli_insert_id($conn);
    }

    $check_pet = mysqli_query($conn, "SELECT id FROM pets WHERE name = '$pet_name' AND owner_id = '$user_id' LIMIT 1");
    
    if ($check_pet && mysqli_num_rows($check_pet) > 0) {
        $p_row = mysqli_fetch_assoc($check_pet);
        $pet_id = $p_row['id'];
    } else {
        // Isinama na ang 'breed' sa pag-insert sa pets table
        $insert_pet = "INSERT INTO pets (owner_id, name, pet_type, breed, gender) 
                       VALUES ('$user_id', '$pet_name', '$pet_type', '$breed', 'Male')";
        mysqli_query($conn, $insert_pet);
        $pet_id = mysqli_insert_id($conn); 
    }

    // =========================================================================
    // INSERT APPOINTMENT (Ang Main Logic)
    // =========================================================================
    
    $appointment_type = 'Walk-in';
    $booking_status   = 'Confirmed';
    
    // --- UPDATED PAYMENT LOGIC (First Pay, First Serve = Paid in Cash instantly) ---
    $payment_status   = 'Paid';    
    $payment_method   = 'Cash'; 
    $total_price      = $service_fee;

    $insert_query = "INSERT INTO appointments 
                    (user_id, pet_id, service, vet_doctor, appointment_date, appointment_time, appointment_type, booking_status, payment_status, payment_method, service_fee, total_price) 
                    VALUES 
                    ('$user_id', '$pet_id', '$service', '$vet_doctor', '$appointment_date', '$appointment_time', '$appointment_type', '$booking_status', '$payment_status', '$payment_method', '$service_fee', '$total_price')";

    if (mysqli_query($conn, $insert_query)) {
        $_SESSION['alert_msg'] = "Walk-in saved! Payment marked as Paid (Cash).";
        header("Location: managebooking.php?status=Confirmed");
        exit();
    } else {
        die("Database Error: " . mysqli_error($conn) . "<br><a href='managebooking.php'>Go Back</a>");
    }
} else {
    header("Location: managebooking.php");
    exit();
}
?>