<?php
session_start();
include 'db_connect.php';

// Set timezone to Philippines
date_default_timezone_set('Asia/Manila');

// Access Control
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

// Get user info
$full_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : (isset($_SESSION['full_name']) ? $_SESSION['full_name'] : 'User');
$user_id = $_SESSION['user_id']; 

$success_msg = '';
$error_msg = '';

// --- FETCH USER DETAILS FOR HEADER (Profile Pic) ---
$profile_pic = '';
$stmt_user = $conn->prepare("SELECT profile_image FROM users WHERE id = ?");
if ($stmt_user) {
    $stmt_user->bind_param("i", $user_id);
    $stmt_user->execute();
    $user_res = $stmt_user->get_result();
    if ($row = $user_res->fetch_assoc()) {
        $profile_pic = $row['profile_image']; 
    }
    $stmt_user->close();
}

// --- FETCH UNREAD NOTIFICATIONS ---
$unread_count = 0;
$notifications = [];
$stmt_notif = $conn->prepare("SELECT COUNT(*) AS unread_count FROM notifications WHERE user_id = ? AND is_read = 0");
if ($stmt_notif) {
    $stmt_notif->bind_param("i", $user_id);
    $stmt_notif->execute();
    $notif_res = $stmt_notif->get_result();
    if ($notif_res) {
        $unread_count = $notif_res->fetch_assoc()['unread_count'];
    }
    $stmt_notif->close();
}

// Fetch latest 5 notifications for dropdown
$stmt_notif_list = $conn->prepare("SELECT id, message, created_at, is_read FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
if ($stmt_notif_list) {
    $stmt_notif_list->bind_param("i", $user_id);
    $stmt_notif_list->execute();
    $res_list = $stmt_notif_list->get_result();
    if ($res_list) {
        $notifications = $res_list->fetch_all(MYSQLI_ASSOC);
    }
    $stmt_notif_list->close();
}

// --- CATCH THE CATEGORY FROM THE URL ---
$pre_selected_category = isset($_GET['category']) ? $_GET['category'] : '';

// Fetch user's registered pets
$user_pets = [];
$stmt_pets = $conn->prepare("SELECT id, name, pet_type, gender, weight FROM pets WHERE owner_id = ?");
$stmt_pets->bind_param("i", $user_id);
$stmt_pets->execute();
$res_pets = $stmt_pets->get_result();
while($row = $res_pets->fetch_assoc()) {
    $user_pets[] = $row;
}
$stmt_pets->close();

// Protect Backend
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pet_name = mysqli_real_escape_string($conn, $_POST['pet_name']);
    $pet_gender = mysqli_real_escape_string($conn, $_POST['pet_gender']);
    $pet_type = mysqli_real_escape_string($conn, $_POST['pet_type']);
    $pet_size = mysqli_real_escape_string($conn, $_POST['pet_size']);
    
    $service_category = mysqli_real_escape_string($conn, $_POST['service_category']);
    $specific_service = mysqli_real_escape_string($conn, $_POST['specific_service']);
    $haircut_style = isset($_POST['haircut_style']) ? mysqli_real_escape_string($conn, $_POST['haircut_style']) : '';
    
    $appointment_date = mysqli_real_escape_string($conn, $_POST['appointment_date']);
    $appointment_time = mysqli_real_escape_string($conn, $_POST['appointment_time']);
    $remarks = isset($_POST['remarks']) ? mysqli_real_escape_string($conn, $_POST['remarks']) : '';
    
    $gcash_ref = mysqli_real_escape_string($conn, $_POST['gcash_ref']);

    // BAGO: I-check kung chineck ba nila yung Terms and Conditions
    if (!isset($_POST['agree_terms'])) {
        $error_msg = "Error: You must agree to the Terms and Conditions before booking.";
    }

    // --- BACKEND FIELD VALIDATION ---
    if (empty($error_msg) && (empty(trim($pet_name)) || empty($pet_type) || empty($pet_gender) || empty($pet_size) || empty($service_category) || empty($specific_service) || empty($appointment_date) || empty($appointment_time) || empty(trim($gcash_ref)))) {
        $error_msg = "Error: All required information (including GCash Reference Number) must be filled out.";
    }

    // --- GCASH RECEIPT UPLOAD HANDLING (REQUIRED) ---
    $receipt_filename = '';
    if (empty($error_msg)) {
        if (!isset($_FILES['gcash_receipt']) || $_FILES['gcash_receipt']['error'] == UPLOAD_ERR_NO_FILE) {
            $error_msg = "Error: Please upload a screenshot of your GCash receipt.";
        } elseif ($_FILES['gcash_receipt']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png'];
            $filename = $_FILES['gcash_receipt']['name'];
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            if (in_array(strtolower($ext), $allowed)) {
                // Ensure uploads directory exists
                if (!is_dir('uploads')) { mkdir('uploads', 0777, true); }
                
                $receipt_filename = 'receipt_' . time() . '_' . $user_id . '.' . $ext;
                if (!move_uploaded_file($_FILES['gcash_receipt']['tmp_name'], 'uploads/' . $receipt_filename)) {
                    $error_msg = "Failed to upload receipt image.";
                }
            } else {
                $error_msg = "Invalid receipt image format. Only JPG and PNG are allowed.";
            }
        } else {
            $error_msg = "Error uploading receipt image. Please try again.";
        }
    }

    // --- SERVER-SIDE PRICE RECALCULATION ---
    $service_fee = 0;
    $pricing_data = [
        "Dog" => [
            "Grooming" => [
                "Basic Pet Grooming" => ["Small (1-5kg)" => 400, "Medium (6-10kg)" => 500, "Large (11-15kg)" => 650, "Extra Large (16-20kg)" => 850, "XXL Large (21-25kg)" => 1000],
                "Full Grooming Package" => ["Small (1-5kg)" => 450, "Medium (6-10kg)" => 550, "Large (11-15kg)" => 700, "Extra Large (16-20kg)" => 900, "XXL Large (21-25kg)" => 1100],
                "Bath & Blow Dry" => ["Small (1-5kg)" => 300, "Medium (6-10kg)" => 350, "Large (11-15kg)" => 550, "Extra Large (16-20kg)" => 750, "XXL Large (21-25kg)" => 950]
            ],
            "Vet Services" => [
                "Deworming" => ["Small (1-5kg)" => 200, "Medium (6-10kg)" => 250, "Large (11-15kg)" => 300, "Extra Large (16-20kg)" => 350, "XXL Large (21-25kg)" => 450],
                "Vaccination - Anti Rabies" => ["default" => 300],
                "Vaccination - 5 in 1" => ["default" => 450],
                "Vaccination - 6 in 1" => ["default" => 600],
                "Vaccination - 8 in 1" => ["default" => 750]
            ],
            "Pet Hotel" => [
                "Pet Daycare (1st Hour - Succeeding fees apply)" => ["Small (1-5kg)" => 100, "Medium (6-10kg)" => 100, "Large (11-15kg)" => 150, "Extra Large (16-20kg)" => 150, "XXL Large (21-25kg)" => 200],
                "Pet Boarding (Overnight)" => ["Small (1-5kg)" => 500, "Medium (6-10kg)" => 500, "Large (11-15kg)" => 600, "Extra Large (16-20kg)" => 600, "XXL Large (21-25kg)" => 800]
            ]
        ],
        "Cat" => [
            "Grooming" => [
                "Cat Grooming (Basic)" => ["Small (1-5kg)" => 550, "Medium (6-10kg)" => 650, "Large (11-15kg)" => 750, "Extra Large (16-20kg)" => 850, "XXL Large (21-25kg)" => 950],
                "Cat Bath & Blow Dry" => ["Small (1-5kg)" => 400, "Medium (6-10kg)" => 500, "Large (11-15kg)" => 600, "Extra Large (16-20kg)" => 700, "XXL Large (21-25kg)" => 800]
            ],
            "Vet Services" => [
                "Deworming" => ["Small (1-5kg)" => 200, "Medium (6-10kg)" => 250, "Large (11-15kg)" => 300, "Extra Large (16-20kg)" => 350, "XXL Large (21-25kg)" => 450],
                "Vaccination - Anti Rabies" => ["default" => 300],
                "Vaccination - 4 in 1 (Cats)" => ["default" => 900]
            ],
            "Pet Hotel" => [
                "Pet Daycare (1st Hour - Succeeding fees apply)" => ["default" => 150],
                "Pet Boarding (Overnight)" => ["default" => 500]
            ]
        ]
    ];

    if (isset($pricing_data[$pet_type][$service_category][$specific_service])) {
        $service_prices = $pricing_data[$pet_type][$service_category][$specific_service];
        if (isset($service_prices['default'])) {
            $service_fee = $service_prices['default'];
        } elseif (isset($service_prices[$pet_size])) {
            $service_fee = $service_prices[$pet_size];
        }
    }

    // --- TIME TRAVEL & CUT-OFF VALIDATION (BAGO) ---
    $current_date = date('Y-m-d');
    $current_time = date('H:i:s');

    if (empty($error_msg)) {
        if ($appointment_date < $current_date) {
            $error_msg = "Error: Cannot book appointments on past dates.";
        } 
        elseif ($appointment_date === $current_date && $current_time >= '18:00:00') {
            // Cut-off Time Logic: Bawal magbook for same day kapag lampas na ng 6 PM
            $error_msg = "Error: Cut-off time for same-day bookings is 6:00 PM. Please book for tomorrow or another day.";
        } 
        elseif ($appointment_date === $current_date && $appointment_time < $current_time) {
            $error_msg = "Error: The selected time has already passed for today. Please select a different time.";
        } 
        elseif ($appointment_time > '18:00:00') {
            $error_msg = "Shop is closed at this time. Please select an earlier time.";
        }
    }

    // --- SERVICE-SPECIFIC TIME SLOT BLOCKING ---
    if (empty($error_msg)) {
        $stmt_slot = $conn->prepare("SELECT COUNT(*) as slot_count FROM appointments WHERE appointment_date = ? AND appointment_time = ? AND service LIKE CONCAT(?, '%') AND booking_status != 'Cancelled'");
        $stmt_slot->bind_param("sss", $appointment_date, $appointment_time, $service_category);
        $stmt_slot->execute();
        $slot_result = $stmt_slot->get_result()->fetch_assoc();
        
        if ($slot_result['slot_count'] >= 1) {
            $error_msg = "The " . date("g:i A", strtotime($appointment_time)) . " slot is already taken for " . htmlspecialchars($service_category) . ". Please choose another time.";
        }
        $stmt_slot->close();
    }

    // --- VET SPECIFIC VALIDATION ---
    if (empty($error_msg) && $service_category === 'Vet Services') {
        if ($pet_type !== 'Dog' && $pet_type !== 'Cat') {
            $error_msg = "Error: Only dogs and cats are allowed to book for Vet Services.";
        } else {
            $day_of_week = date('N', strtotime($appointment_date)); 
            if ($day_of_week == 3 || $day_of_week == 6) {
                $error_msg = "Dr. Faith Casayuran is not available on Wednesdays and Saturdays.";
            } else {
                $stmt_check = $conn->prepare("SELECT COUNT(*) as vet_count FROM appointments WHERE appointment_date = ? AND service LIKE 'Vet Services%' AND booking_status != 'Cancelled'");
                $stmt_check->bind_param("s", $appointment_date);
                $stmt_check->execute();
                $row_check = $stmt_check->get_result()->fetch_assoc();
                
                if ($row_check['vet_count'] >= 24) {
                    $error_msg = "Dr. Faith Casayuran is fully booked for this date.";
                }
                $stmt_check->close();
            }
        }
    }

    // --- GROOMING SPECIFIC VALIDATION ---
    if (empty($error_msg) && $service_category === 'Grooming') {
        $stmt_groom_check = $conn->prepare("SELECT COUNT(*) as grooming_count FROM appointments WHERE appointment_date = ? AND service LIKE 'Grooming%' AND booking_status != 'Cancelled'");
        $stmt_groom_check->bind_param("s", $appointment_date);
        $stmt_groom_check->execute();
        $row_groom_check = $stmt_groom_check->get_result()->fetch_assoc();
        
        if ($row_groom_check['grooming_count'] >= 30) { 
            $error_msg = "Grooming services are fully booked for this date.";
        }
        $stmt_groom_check->close();
    }

    if (empty($error_msg)) {
        // 1. Find or Create the Pet
        $stmt = $conn->prepare("SELECT id FROM pets WHERE owner_id = ? AND name = ?");
        $stmt->bind_param("is", $user_id, $pet_name);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $pet = $result->fetch_assoc();
            $pet_id = $pet['id'];
            
            $update_pet = $conn->prepare("UPDATE pets SET weight = ?, pet_type = ?, gender = ? WHERE id = ?");
            $update_pet->bind_param("sssi", $pet_size, $pet_type, $pet_gender, $pet_id);
            $update_pet->execute();
        } else {
            $insert_pet = $conn->prepare("INSERT INTO pets (owner_id, name, pet_type, gender, weight) VALUES (?, ?, ?, ?, ?)");
            $insert_pet->bind_param("issss", $user_id, $pet_name, $pet_type, $pet_gender, $pet_size);
            if ($insert_pet->execute()) {
                $pet_id = $insert_pet->insert_id;
            }
        }

        // 2. Format Service Name
        $final_service_name = $service_category . " - " . $specific_service;
        if (!empty($haircut_style) && $specific_service === 'Full Grooming Package') {
            $final_service_name .= " (" . $haircut_style . ")";
        }

        // 3. Insert Appointment with GCash Details
        $payment_method = 'GCash'; 
        $payment_status = 'Pending Verification';
        $booking_status = 'Pending'; 

        $insert_appt = $conn->prepare("INSERT INTO appointments (user_id, pet_id, service, appointment_date, appointment_time, service_fee, total_price, gcash_ref, gcash_receipt, payment_method, payment_status, booking_status, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $insert_appt->bind_param("iisssddssssss", $user_id, $pet_id, $final_service_name, $appointment_date, $appointment_time, $service_fee, $service_fee, $gcash_ref, $receipt_filename, $payment_method, $payment_status, $booking_status, $remarks);
        
        if ($insert_appt->execute()) {
            
            // --- START: AUTOMATIC TASK FOR VET ---
            if ($service_category === 'Vet Services') {
                $formatted_time = date("g:i A", strtotime($appointment_time));
                $task_msg = "Upcoming Appointment: " . $pet_name . " (" . $specific_service . ") on " . $appointment_date . " at " . $formatted_time;
                
                $vet_staff_name = "Dr. Faith Casayuran"; 
                
                $stmt_task = $conn->prepare("INSERT INTO tasks (staff_name, task_text) VALUES (?, ?)");
                $stmt_task->bind_param("ss", $vet_staff_name, $task_msg);
                $stmt_task->execute();
                $stmt_task->close();
            }

            // --- START: ADMIN NOTIFICATION ---
            $admin_msg = $full_name . " booked a new appointment for " . $pet_name . " via GCash. Pending Payment Verification.";
            $stmt_admin_notif = $conn->prepare("INSERT INTO admin_notifications (message) VALUES (?)");
            if ($stmt_admin_notif) {
                $stmt_admin_notif->bind_param("s", $admin_msg);
                $stmt_admin_notif->execute();
                $stmt_admin_notif->close();
            }

            header("Location: bookings.php?msg=success");
            exit();
        } else {
            $error_msg = "Error booking appointment: " . $conn->error;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Appointment | Boogie's Pet Care Services</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>

        :root {
            --brand-blue: #001f3f;
            --brand-blue-2: #0b3b66;
            --brand-yellow: #ffcc00;
            --brand-yellow-soft: #fff7d6;
            --brand-purple: #8b4bd6;
            --brand-purple-dark: #7136b4;
            --page-bg: #f5f8fb;
            --white: #ffffff;
            --text: #17324d;
            --muted: #6b7c8f;
            --line: #e3eaf1;
            --soft: #f8fafc;
            --success: #168553;
            --danger: #c73b47;
            --shadow: 0 10px 30px rgba(0,31,63,.06);
            --shadow-lg: 0 18px 42px rgba(0,31,63,.09);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        html { scroll-behavior: smooth; }

        body {
            min-height: 100vh;
            background: var(--page-bg);
            color: var(--text);
            line-height: 1.6;
            overflow-y: scroll;
        }

        /* ===== HEADER ===== */
        .promo-bar {
            background: var(--brand-blue);
            color: #fff;
            text-align: center;
            padding: 7px 16px;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: .2px;
            border-top: 3px solid var(--brand-yellow);
        }

        .promo-bar i {
            color: var(--brand-yellow);
            margin-right: 7px;
        }

        header {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: rgba(255,255,255,.97);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--line);
            box-shadow: 0 4px 18px rgba(0,0,0,.04);
        }

        .nav-top {
            max-width: 1320px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 25px;
            padding: 14px 28px;
        }

        .logo {
            display: inline-flex;
            align-items: center;
            gap: 11px;
            text-decoration: none;
            min-width: 225px;
        }

        .nav-logo-img {
            width: 50px;
            height: 50px;
            object-fit: contain;
            border-radius: 10px;
            display: block;
        }

        .logo-text {
            display: flex;
            flex-direction: column;
            line-height: 1.05;
        }

        .logo-text b {
            font-size: 20px;
            color: var(--brand-blue);
            font-weight: 800;
        }

        .logo-text span {
            margin-top: 3px;
            color: #8c9aae;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 1.2px;
        }

        .user-controls {
            display: flex;
            align-items: center;
            gap: 18px;
        }

        .notification-wrapper,
        .profile-wrapper {
            position: relative;
        }

        .notification-bell {
            position: relative;
            width: 42px;
            height: 42px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: #fff;
            color: var(--brand-blue);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .notification-bell:hover,
        .profile-trigger:hover {
            background: #f8fafc;
        }

        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--danger);
            color: #fff;
            font-size: 9px;
            font-weight: 800;
            border: 2px solid #fff;
            border-radius: 999px;
        }

        .profile-trigger {
            min-height: 42px;
            padding: 4px 8px 4px 5px;
            display: flex;
            align-items: center;
            gap: 9px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: #fff;
            color: var(--text);
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
        }

        .profile-img {
            width: 34px !important;
            height: 34px !important;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--brand-blue) !important;
        }

        .dropdown-menu {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: 270px;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 14px;
            box-shadow: var(--shadow-lg);
            display: none;
            flex-direction: column;
            z-index: 1001;
            overflow: hidden;
        }

        .dropdown-menu.active { display: flex; }

        .dropdown-header {
            padding: 14px 16px;
            background: #f8fafc;
            border-bottom: 1px solid var(--line);
            color: var(--muted);
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .7px;
        }

        .dropdown-item {
            display: block;
            padding: 12px 16px;
            text-decoration: none;
            color: var(--text);
            font-size: 12px;
            border-bottom: 1px solid #eef2f5;
            transition: .18s;
        }

        .dropdown-item:hover {
            background: #f8fafc;
            color: var(--brand-blue);
        }

        .dropdown-item i {
            width: 18px;
            margin-right: 7px;
            text-align: center;
        }

        .dropdown-item.unread {
            background: #eef6ff;
            font-weight: 700;
        }

        .dropdown-item:last-child { border-bottom: 0; }
        .view-all-link { text-align: center; color: var(--brand-blue); font-weight: 800; }

        /* ===== BOOKING PAGE ===== */
        main {
            width: min(1120px, 92%);
            margin: 0 auto;
            padding: 42px 0 75px;
        }

        .page-intro { margin-bottom: 24px; }

        .back-nav { margin-bottom: 14px; }

        .back-nav a {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            text-decoration: none;
            color: #748396;
            font-size: 11px;
            font-weight: 700;
        }

        .back-nav a:hover { color: var(--brand-blue); }

        .page-title-row {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 25px;
        }

        .page-kicker {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: var(--brand-yellow-soft);
            border: 1px solid #ffe594;
            color: #8c6800;
            padding: 7px 12px;
            border-radius: 999px;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .7px;
            font-weight: 800;
            margin-bottom: 10px;
        }

        .page-title h1 {
            color: var(--brand-blue);
            font-size: 33px;
            line-height: 1.2;
            font-weight: 800;
            letter-spacing: -.5px;
        }

        .page-title p {
            color: var(--muted);
            font-size: 13px;
            margin-top: 6px;
        }

        .booking-summary-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 13px;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 12px;
            color: #66798b;
            font-size: 10px;
            font-weight: 700;
            white-space: nowrap;
        }

        .booking-summary-pill i { color: var(--success); }

        .booking-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 285px;
            gap: 22px;
            align-items: start;
        }

        .booking-card,
        .side-card {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 20px;
            box-shadow: var(--shadow);
        }

        .booking-card { padding: 30px; }
        .side-card {
            padding: 22px;
            position: sticky;
            top: 115px;
        }

        /* Original form controls, restyled */
        .booking-card > form {
            width: 100%;
        }

        .form-group { margin-bottom: 17px; }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .booking-card label {
            display: block;
            color: var(--text);
            font-size: 11px;
            font-weight: 800;
            margin-bottom: 7px;
        }

        .booking-card input[type="text"],
        .booking-card input[type="date"],
        .booking-card select,
        .booking-card textarea,
        .booking-card input[type="file"] {
            width: 100%;
            padding: 11px 13px;
            border: 1px solid #dce5ee;
            border-radius: 11px;
            background: #fbfcfe;
            color: var(--text);
            font-family: inherit;
            font-size: 12px;
            outline: none;
            transition: border-color .2s, box-shadow .2s, background .2s;
        }

        .booking-card input:focus,
        .booking-card select:focus,
        .booking-card textarea:focus {
            border-color: #9bb6cc;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(0,31,63,.05);
        }

        .booking-card input[readonly] {
            background: #eef1f4;
        }

        .booking-card textarea {
            resize: vertical;
            min-height: 90px;
        }

        .booking-container,
        .header-section {
            all: unset;
        }

        /* Original header-section becomes compact form heading if still present. */
        .booking-card .header-section {
            display: block;
            padding: 0 0 18px;
            margin: 0 0 22px;
            border-bottom: 1px solid var(--line);
            text-align: left;
        }

        .booking-card .header-section h1 {
            color: var(--brand-blue);
            font-size: 19px;
            font-weight: 800;
            margin: 0 0 4px;
        }

        .booking-card .header-section p {
            color: var(--muted);
            font-size: 11px;
        }

        .booking-card .walkin-notice {
            background: #eef3ff;
            color: #4b45a4;
            padding: 11px 12px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
            margin-top: 13px;
            border: 1px dashed #8d84e8;
        }

        .booking-card .alert {
            padding: 12px 14px;
            border-radius: 11px;
            margin-bottom: 19px;
            font-size: 11px;
        }

        .booking-card .alert-error {
            background: #fff0f1;
            color: #b72f3a;
            border: 1px solid #f3c0c5;
        }

        .booking-card .alert-warning {
            background: #fffbeb;
            color: #92400e;
            border-left: 4px solid #f59e0b;
            border-radius: 11px;
            padding: 13px 14px;
        }

        .booking-card .vet-info-box {
            background: #edf8ff;
            color: #0d6d99;
            padding: 11px 12px;
            border-radius: 10px;
            font-size: 11px;
            margin-bottom: 17px;
            border: 1px dashed #8bcfee;
            text-align: center;
        }

        .booking-card .price-display {
            background: linear-gradient(135deg,#fffbea,#fff);
            border: 1px solid #ffe08a;
            border-radius: 14px;
            padding: 18px;
            text-align: center;
            margin-bottom: 20px;
        }

        .booking-card .price-display p {
            margin: 0;
            color: #7a8896;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .6px;
            font-weight: 800;
        }

        .booking-card .price-display h2 {
            margin: 4px 0 0;
            color: var(--brand-blue);
            font-size: 29px;
            font-weight: 800;
        }

        .booking-card .gcash-section {
            background: #eef7ff;
            padding: 19px;
            border-radius: 14px;
            border: 1px dashed #6eaff0;
            margin-bottom: 20px;
        }

        .booking-card .gcash-section h4 {
            color: #1d4ed8;
            margin-bottom: 6px;
            font-size: 14px;
            font-weight: 800;
        }

        .booking-card .gcash-section p {
            color: #245386;
            font-size: 10px;
            line-height: 1.6;
            margin-bottom: 13px;
        }

        .booking-card .gcash-number {
            background: #dcecff;
            display: inline-block;
            padding: 8px 13px;
            border-radius: 8px;
            font-weight: 800;
            color: #1e3a8a;
            margin-bottom: 14px;
            font-size: 15px;
            letter-spacing: .7px;
        }

        .booking-card .terms-container {
            background: #f8fafc;
            border: 1px solid var(--line);
            padding: 14px;
            border-radius: 11px;
            margin-bottom: 18px;
            font-size: 10px;
        }

        .booking-card .terms-container label {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            color: #556779;
            font-weight: 600;
            line-height: 1.6;
            margin: 0;
            cursor: pointer;
        }

        .booking-card .terms-container input[type="checkbox"] {
            width: 17px;
            height: 17px;
            margin-top: 1px;
            cursor: pointer;
            accent-color: var(--brand-blue);
            flex: 0 0 17px;
        }

        .booking-card .terms-container span.highlight {
            color: var(--brand-blue);
            font-weight: 800;
        }

        .booking-card .btn-submit {
            width: 100%;
            border: 0;
            padding: 14px;
            border-radius: 11px;
            background: var(--brand-blue);
            color: var(--brand-yellow);
            font-family: inherit;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 8px 20px rgba(0,31,63,.13);
        }

        .booking-card .btn-submit:hover {
            background: var(--brand-blue-2);
        }

        .hidden { display: none !important; }

        /* Booking guide */
        .side-kicker {
            color: #8b99a9;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 1.1px;
            font-weight: 800;
            margin-bottom: 6px;
        }

        .side-card h3 {
            color: var(--brand-blue);
            font-size: 18px;
            font-weight: 800;
            line-height: 1.25;
        }

        .side-card > p {
            color: var(--muted);
            font-size: 10px;
            line-height: 1.6;
            margin: 7px 0 18px;
        }

        .step-list {
            display: grid;
            gap: 14px;
        }

        .step-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .step-icon {
            width: 34px;
            height: 34px;
            flex: 0 0 34px;
            border-radius: 10px;
            background: var(--brand-yellow-soft);
            color: var(--brand-blue);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }

        .step-item strong {
            display: block;
            color: var(--brand-blue);
            font-size: 10px;
            font-weight: 800;
            margin-bottom: 1px;
        }

        .step-item span {
            display: block;
            color: #7f8d9c;
            font-size: 9px;
            line-height: 1.5;
        }

        .side-note {
            margin-top: 19px;
            padding: 12px;
            border-radius: 11px;
            background: #f0f7ff;
            border: 1px solid #d9eafa;
            color: #4e6980;
            font-size: 9px;
            line-height: 1.6;
        }

        .side-note i {
            color: var(--brand-blue);
            margin-right: 5px;
        }

        /* Footer */
        footer {
            background: var(--brand-blue);
            padding: 62px 28px 30px;
            color: #fff;
            border-top: 4px solid var(--brand-yellow);
        }

        .footer-main {
            max-width: 1180px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1.5fr;
            gap: 42px;
            padding-bottom: 40px;
            border-bottom: 1px solid rgba(255,255,255,.12);
        }

        .footer-main h4 {
            color: var(--brand-yellow);
            margin-bottom: 15px;
            text-transform: uppercase;
            font-weight: 800;
            font-size: 12px;
            letter-spacing: .5px;
        }

        .footer-main p,
        .footer-main a {
            color: #cbd5e1;
            text-decoration: none;
            font-size: 12px;
            line-height: 1.7;
            display: block;
            margin-bottom: 8px;
        }

        .footer-main a:hover { color: #fff; }

        .socials {
            display: flex;
            gap: 10px;
            margin-top: 16px;
        }

        .socials a {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: rgba(255,255,255,.09);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
        }

        .socials a:hover {
            background: var(--brand-yellow);
            color: var(--brand-blue);
        }

        .footer-bottom {
            max-width: 1180px;
            margin: 0 auto;
            padding-top: 23px;
            color: #91a1b1;
            text-align: center;
            font-size: 11px;
        }

        @media (max-width: 980px) {
            .booking-layout { grid-template-columns: 1fr; }
            .side-card { position: static; }
            .page-title-row { display: block; }
            .booking-summary-pill { margin-top: 12px; }
            .footer-main { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 680px) {
            .nav-top { flex-wrap: wrap; padding: 12px 18px; }
            .user-controls { width: 100%; justify-content: flex-end; }
            .profile-trigger span { display: none; }

            main { width: 92%; padding-top: 30px; }
            .page-title h1 { font-size: 28px; }

            .booking-card { padding: 21px; }
            .form-row { grid-template-columns: 1fr; gap: 0; }

            .footer-main { grid-template-columns: 1fr; gap: 25px; }
        }

    </style>
</head>

<body>


    <div class="promo-bar">
        <i class="fa-solid fa-phone"></i> Need help? Call us at (046) 887 4714
    </div>

    <header>
        <div class="nav-top">
            <a href="dashboard.php" class="logo">
                <img src="bg.png" alt="Boogie's Pet Care logo" class="nav-logo-img">
                <div class="logo-text">
                    <b>Boogie's</b>
                    <span>PET CARE SERVICES</span>
                </div>
            </a>

            <div class="user-controls">
                <div class="notification-wrapper">
                    <div class="notification-bell" onclick="toggleDropdown('notifDropdown')" aria-label="Notifications">
                        <i class="fa-solid fa-bell"></i>
                        <?php if($unread_count > 0): ?>
                            <span class="notification-badge"><?php echo $unread_count; ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="dropdown-menu" id="notifDropdown">
                        <div class="dropdown-header">Notifications</div>

                        <?php if(count($notifications) > 0): ?>
                            <?php foreach($notifications as $notif): ?>
                                <a href="notifications.php" class="dropdown-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>">
                                    <?php echo htmlspecialchars($notif['message']); ?>
                                    <br>
                                    <small style="color:#888;font-size:10px;">
                                        <?php echo date('M d, Y h:i A', strtotime($notif['created_at'])); ?>
                                    </small>
                                </a>
                            <?php endforeach; ?>

                            <a href="notifications.php" class="dropdown-item view-all-link">
                                View All Notifications
                            </a>
                        <?php else: ?>
                            <div class="dropdown-item" style="text-align:center;color:#888;">
                                No new notifications.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="profile-wrapper">
                    <div class="profile-trigger" onclick="toggleDropdown('profileDropdown')">
                        <?php if (!empty($profile_pic)): ?>
                            <img
                                src="<?php echo (strpos($profile_pic, 'uploads/') === false ? 'uploads/' : '') . htmlspecialchars($profile_pic); ?>"
                                alt="Profile"
                                class="profile-img"
                            >
                        <?php else: ?>
                            <i class="fa-solid fa-circle-user" style="font-size:20px;color:var(--brand-blue);"></i>
                        <?php endif; ?>

                        <span>Hi, <?php echo htmlspecialchars($full_name); ?></span>
                        <i class="fa-solid fa-chevron-down"></i>
                    </div>

                    <div class="dropdown-menu" id="profileDropdown" style="width:210px;">
                        <a href="edit_profile.php" class="dropdown-item">
                            <i class="fa-solid fa-user"></i> My Profile
                        </a>
                        <a href="bookings.php" class="dropdown-item">
                            <i class="fa-solid fa-calendar-check"></i> My Bookings
                        </a>
                        <a href="petprofile.php" class="dropdown-item">
                            <i class="fa-solid fa-paw"></i> My Pets
                        </a>
                        <a href="logout.php" class="dropdown-item" style="color:#dc3545;border-top:1px solid #eaeaea;">
                            <i class="fa-solid fa-right-from-bracket"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </header>



    <main>
        <div class="page-intro">
            <div class="back-nav">
                <a href="dashboard.php">
                    <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>

            <div class="page-title-row">
                <div class="page-title">
                    <div class="page-kicker">
                        <i class="fa-solid fa-calendar-check"></i>
                        Appointment booking
                    </div>
                    <h1>Book an Appointment</h1>
                    <p>Schedule professional care for your pet with Boogie's.</p>
                </div>

                <div class="booking-summary-pill">
                    <i class="fa-solid fa-shield-heart"></i>
                    Payment is verified before confirmation
                </div>
            </div>
        </div>

        <div class="booking-layout">
            <section class="booking-card">
            <form method="POST" action="" id="bookingForm" enctype="multipart/form-data">
                
                <div class="form-group">
                    <label>Select Registered Pet (Optional)</label>
                    <select id="pet_selector" onchange="autoFillPet()">
                        <option value="">-- Add New Pet / Enter Manually --</option>
                        <?php foreach($user_pets as $pet): ?>
                            <option value="<?php echo $pet['id']; ?>">
                                <?php echo htmlspecialchars($pet['name'] . ' (' . $pet['pet_type'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Pet's Name *</label>
                    <input type="text" name="pet_name" id="pet_name" placeholder="e.g., Kyle" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Pet Type *</label>
                        <select name="pet_type" id="pet_type" required onchange="updateOptions()">
                            <option value="">Select Type</option>
                            <option value="Dog">Dog</option>
                            <option value="Cat">Cat</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Gender *</label>
                        <select name="pet_gender" id="pet_gender" required>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Pet Size / Weight *</label>
                    <select name="pet_size" id="pet_size" required onchange="calculatePrice()">
                        <option value="">Select Size</option>
                        <option value="Small (1-5kg)">Small (1-5kg)</option>
                        <option value="Medium (6-10kg)">Medium (6-10kg)</option>
                        <option value="Large (11-15kg)">Large (11-15kg)</option>
                        <option value="Extra Large (16-20kg)">Extra Large (16-20kg)</option>
                        <option value="XXL Large (21-25kg)">XXL Large (21-25kg)</option>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Service Category *</label>
                        <select name="service_category" id="service_category" required onchange="updateOptions()">
                            <option value="">Select Category</option>
                            <option value="Grooming" <?php echo ($pre_selected_category === 'Grooming') ? 'selected' : ''; ?>>Grooming</option>
                            <option value="Vet Services" <?php echo ($pre_selected_category === 'Vet Services') ? 'selected' : ''; ?>>Vet Services</option>
                            <option value="Pet Hotel" <?php echo ($pre_selected_category === 'Pet Hotel') ? 'selected' : ''; ?>>Pet Hotel</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Specific Service *</label>
                        <select name="specific_service" id="specific_service" required onchange="handleServiceChange()">
                            <option value="">Choose specific service</option>
                        </select>
                    </div>
                </div>

                <div id="vetNameDisplay" class="vet-info-box hidden">
                    <i class="fa-solid fa-user-doctor"></i> Attending Veterinarian: <strong>Dr. Faith Casayuran</strong>
                </div>

                <div class="form-group hidden" id="haircutContainer">
                    <label>Desired Haircut Style *</label>
                    <select name="haircut_style" id="haircut_style">
                        <option value="">Select Style</option>
                        <option value="Puppy Cut">Puppy Cut</option>
                        <option value="Summer Cut">Summer Cut</option>
                        <option value="Shave Down">Shave Down</option>
                        <option value="Bear Cut">Bear Cut</option>
                        <option value="Poodle Cut">Poodle Cut</option>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Select Date *</label>
                        <input type="date" name="appointment_date" id="appointment_date" required>
                    </div>
                    <div class="form-group">
                        <label>Select Time *</label>
                        <select name="appointment_time" id="appointment_time" required>
                            <option value="">Choose a time slot</option>
                            <option value="10:00:00">10:00 AM</option>
                            <option value="11:00:00">11:00 AM</option>
                            <option value="12:00:00">12:00 PM</option>
                            <option value="13:00:00">01:00 PM</option>
                            <option value="14:00:00">02:00 PM</option>
                            <option value="15:00:00">03:00 PM</option>
                            <option value="16:00:00">04:00 PM</option>
                            <option value="17:00:00">05:00 PM</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Remarks / Special Instructions (Optional)</label>
                    <textarea name="remarks" placeholder="Any allergies, behaviors, or specific requests?"></textarea>
                </div>

                <div class="price-display" id="priceDisplay">
                    <p>Total Amount Due (GCash Full Payment)</p>
                    <h2 id="priceText">₱0.00</h2>
                </div>

                <div class="gcash-section" id="gcashSection">
                    <h4><i class="fa-solid fa-mobile-screen-button"></i> GCash Payment Detail</h4>
                    <p>To secure your appointment, please send the exact amount to our GCash account. <strong>Your time slot will not be blocked until the payment is verified.</strong></p>
                    
                    <div style="text-align: center;">
                        <span class="gcash-number">GCash: 0912-345-6789 (Boogie's Pet Care)</span>
                    </div>

                    <div class="form-group">
                        <label>GCash Reference Number *</label>
                        <input type="text" name="gcash_ref" placeholder="e.g., 1001234567890" id="gcash_ref_input">
                    </div>
                    <div class="form-group">
                        <label>Upload Screenshot of Receipt *</label>
                        <input type="file" name="gcash_receipt" accept="image/png, image/jpeg, image/jpg" style="background: white;" id="gcash_receipt_input">
                    </div>
                </div>

                <div class="terms-container">
                    <label>
                        <input type="checkbox" name="agree_terms" id="agree_terms" required>
                        <div>
                            I agree to the <span class="highlight">Terms & Conditions</span>. I understand that my slot will only be confirmed upon GCash payment verification, and that arriving <strong>late for 30 minutes</strong> will result in automatic cancellation and the payment will be <strong>strictly non-refundable</strong>.
                        </div>
                    </label>
                </div>

                <button type="submit" class="btn-submit" id="btnSubmit">Submit & Verify Payment</button>
            </form>
            </section>

            <aside class="side-card">
                <div class="side-kicker">Booking guide</div>
                <h3>Almost there.</h3>
                <p>Complete the form, send your GCash payment, then our team will verify your appointment.</p>

                <div class="step-list">
                    <div class="step-item">
                        <div class="step-icon"><i class="fa-solid fa-paw"></i></div>
                        <div>
                            <strong>Choose your pet</strong>
                            <span>Use an existing profile or enter a new one.</span>
                        </div>
                    </div>

                    <div class="step-item">
                        <div class="step-icon"><i class="fa-solid fa-list-check"></i></div>
                        <div>
                            <strong>Select a service</strong>
                            <span>Your total updates automatically based on your choices.</span>
                        </div>
                    </div>

                    <div class="step-item">
                        <div class="step-icon"><i class="fa-solid fa-calendar-days"></i></div>
                        <div>
                            <strong>Pick date & time</strong>
                            <span>Unavailable slots are checked before you submit.</span>
                        </div>
                    </div>

                    <div class="step-item">
                        <div class="step-icon"><i class="fa-solid fa-mobile-screen-button"></i></div>
                        <div>
                            <strong>Pay via GCash</strong>
                            <span>Upload your receipt and reference number for verification.</span>
                        </div>
                    </div>
                </div>

                <div class="side-note">
                    <i class="fa-solid fa-circle-info"></i>
                    Your slot is only confirmed after payment verification.
                </div>
            </aside>
        </div>
    </main>



    <footer>
        <div class="footer-main">
            <div>
                <h4><i class="fa-solid fa-paw"></i> Boogie's Pet Care</h4>
                <p>Your trusted partner for all your pet care needs in Dasmariñas, Cavite.</p>
                <div class="socials">
                    <a href="https://www.facebook.com/boogiespetsupplies" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
                    <a href="mailto:boogiespetcareservices@gmail.com" aria-label="Email"><i class="fa-solid fa-envelope"></i></a>
                </div>
            </div>

            <div>
                <h4>Quick Links</h4>
                <a href="home.php">Home</a>
                <a href="petservices.php">Services & Prices</a>
                <a href="contactus.php">Contact & Reviews</a>
                <a href="faqs.html">FAQs</a>
            </div>

            <div>
                <h4>Services</h4>
                <a href="grooming.php">Grooming</a>
                <a href="pethotel.php">Pet Hotel</a>
                <a href="vetclinic.php">Vet Clinic</a>
            </div>

            <div>
                <h4>Contact Us</h4>
                <p><i class="fa-solid fa-phone"></i> (046) 887 4714</p>
                <p><i class="fa-solid fa-envelope"></i> boogiespetcareservices@gmail.com</p>
                <p><i class="fa-solid fa-location-dot"></i> 110 Don Placido Campos Ave San Agustin 3, Dasmariñas, Philippines, 4114</p>
            </div>
        </div>

        <div class="footer-bottom">
            © <?php echo date("Y"); ?> Boogie's Pet Care & Services - Dasmariñas Branch. All rights reserved.
        </div>
    </footer>


    <script>
        // --- DROPDOWN LOGIC ---
        function toggleDropdown(id) {
            document.querySelectorAll('.dropdown-menu').forEach(menu => {
                if (menu.id !== id) { menu.classList.remove('active'); }
            });
            document.getElementById(id).classList.toggle('active');
        }

        window.addEventListener('click', function(e) {
            if (!document.querySelector('.notification-wrapper').contains(e.target) && 
                !document.querySelector('.profile-wrapper').contains(e.target)) {
                document.querySelectorAll('.dropdown-menu').forEach(menu => {
                    menu.classList.remove('active');
                });
            }
        });

        const userPetsArray = <?php echo json_encode($user_pets); ?>;

        window.addEventListener('DOMContentLoaded', (event) => {
            if (document.getElementById('appointment_date')) {
                updateOptions();
                
                // BAGO: CUT-OFF TIME LOGIC
                const dateInput = document.getElementById('appointment_date');
                const now = new Date();
                const todayStr = now.toISOString().split('T')[0];
                
                // Kung lampas na ng 6:00 PM (18:00), bukas na ang pwedeng i-book
                if (now.getHours() >= 18) {
                    const tomorrow = new Date(now);
                    tomorrow.setDate(tomorrow.getDate() + 1);
                    const tomorrowStr = tomorrow.toISOString().split('T')[0];
                    dateInput.setAttribute('min', tomorrowStr);
                    
                    // Mag-alert sa user kung bakit bukas na siya pinapabook
                    dateInput.addEventListener('click', function() {
                        if(!this.dataset.alerted) {
                            alert("Notice: It is past 6:00 PM. Same-day bookings are now closed. Please select a date starting tomorrow.");
                            this.dataset.alerted = "true";
                        }
                    });
                } else {
                    dateInput.setAttribute('min', todayStr);
                }
            }
        });

        function autoFillPet() {
            const petSelector = document.getElementById('pet_selector').value;
            const nameInput = document.getElementById('pet_name');
            const typeSelect = document.getElementById('pet_type');
            const genderSelect = document.getElementById('pet_gender');
            const sizeSelect = document.getElementById('pet_size');

            if (petSelector === "") {
                nameInput.value = ""; nameInput.readOnly = false;
                typeSelect.value = ""; genderSelect.value = "Male"; sizeSelect.value = "";
            } else {
                const selectedPet = userPetsArray.find(p => p.id == petSelector);
                if (selectedPet) {
                    nameInput.value = selectedPet.name; nameInput.readOnly = true; 
                    typeSelect.value = selectedPet.pet_type; genderSelect.value = selectedPet.gender; sizeSelect.value = selectedPet.weight;
                }
            }
            updateOptions(); 
        }

        const pricingData = {
            "Dog": {
                "Grooming": {
                    "Basic Pet Grooming": { "Small (1-5kg)": 400, "Medium (6-10kg)": 500, "Large (11-15kg)": 650, "Extra Large (16-20kg)": 850, "XXL Large (21-25kg)": 1000 },
                    "Full Grooming Package": { "Small (1-5kg)": 450, "Medium (6-10kg)": 550, "Large (11-15kg)": 700, "Extra Large (16-20kg)": 900, "XXL Large (21-25kg)": 1100 },
                    "Bath & Blow Dry": { "Small (1-5kg)": 300, "Medium (6-10kg)": 350, "Large (11-15kg)": 550, "Extra Large (16-20kg)": 750, "XXL Large (21-25kg)": 950 }
                },
                "Vet Services": {
                    "Deworming": { "Small (1-5kg)": 200, "Medium (6-10kg)": 250, "Large (11-15kg)": 300, "Extra Large (16-20kg)": 350, "XXL Large (21-25kg)": 450 },
                    "Vaccination - Anti Rabies": { default: 300 },
                    "Vaccination - 5 in 1": { default: 450 },
                    "Vaccination - 6 in 1": { default: 600 },
                    "Vaccination - 8 in 1": { default: 750 }
                },
                "Pet Hotel": {
                    "Pet Daycare (1st Hour - Succeeding fees apply)": { "Small (1-5kg)": 100, "Medium (6-10kg)": 100, "Large (11-15kg)": 150, "Extra Large (16-20kg)": 150, "XXL Large (21-25kg)": 200 },
                    "Pet Boarding (Overnight)": { "Small (1-5kg)": 500, "Medium (6-10kg)": 500, "Large (11-15kg)": 600, "Extra Large (16-20kg)": 600, "XXL Large (21-25kg)": 800 }
                }
            },
            "Cat": {
                "Grooming": {
                    "Cat Grooming (Basic)": { "Small (1-5kg)": 550, "Medium (6-10kg)": 650, "Large (11-15kg)": 750, "Extra Large (16-20kg)": 850, "XXL Large (21-25kg)": 950 },
                    "Cat Bath & Blow Dry": { "Small (1-5kg)": 400, "Medium (6-10kg)": 500, "Large (11-15kg)": 600, "Extra Large (16-20kg)": 700, "XXL Large (21-25kg)": 800 }
                },
                "Vet Services": {
                    "Deworming": { "Small (1-5kg)": 200, "Medium (6-10kg)": 250, "Large (11-15kg)": 300, "Extra Large (16-20kg)": 350, "XXL Large (21-25kg)": 450 },
                    "Vaccination - Anti Rabies": { default: 300 },
                    "Vaccination - 4 in 1 (Cats)": { default: 900 }
                },
                "Pet Hotel": {
                    "Pet Daycare (1st Hour - Succeeding fees apply)": { default: 150 },
                    "Pet Boarding (Overnight)": { default: 500 }
                }
            }
        };

        function updateOptions() {
            const petType = document.getElementById('pet_type').value;
            const category = document.getElementById('service_category').value;
            const specificServiceDropdown = document.getElementById('specific_service');
            const vetNameDisplay = document.getElementById('vetNameDisplay');
            
            const walkInNotice = document.getElementById('walkInNotice');
            const submitBtn = document.getElementById('btnSubmit');
            
            const previousSelection = specificServiceDropdown.value;

            specificServiceDropdown.innerHTML = '<option value="">Choose specific service</option>';
            document.getElementById('haircutContainer').classList.add('hidden');

            if (category === 'Vet Services') {
                vetNameDisplay.classList.remove('hidden');
            } else {
                vetNameDisplay.classList.add('hidden');
            }

            // walkInNotice is optional on this page.
            // Prevent a missing element from stopping updateOptions()
            // before the Specific Service dropdown gets populated.
            if (walkInNotice) {
                if (category === 'Vet Services' || category === 'Grooming') {
                    walkInNotice.style.display = 'block';
                } else {
                    walkInNotice.style.display = 'none';
                }
            }

            if (petType && category && pricingData[petType][category]) {
                const services = Object.keys(pricingData[petType][category]);
                services.forEach(service => {
                    const option = document.createElement('option');
                    option.value = service; option.text = service;
                    if (service === previousSelection) { option.selected = true; }
                    specificServiceDropdown.appendChild(option);
                });
            }
            
            validateVetDate();
            calculatePrice();
            checkRealTimeAvailability(); 
        }

        function handleServiceChange() {
            const specificService = document.getElementById('specific_service').value;
            const haircutContainer = document.getElementById('haircutContainer');
            const haircutSelect = document.getElementById('haircut_style');

            if (specificService === 'Full Grooming Package') {
                haircutContainer.classList.remove('hidden');
                haircutSelect.required = true;
            } else {
                haircutContainer.classList.add('hidden');
                haircutSelect.required = false;
            }
            calculatePrice();
        }

        function validateVetDate() {
            const category = document.getElementById('service_category').value;
            const dateInput = document.getElementById('appointment_date');
            
            if (category === 'Vet Services' && dateInput.value) {
                const selectedDate = new Date(dateInput.value);
                const day = selectedDate.getDay(); 
                
                if (day === 3 || day === 6) {
                    alert("Dr. Faith Casayuran is not available on Wednesdays and Saturdays. Please select a different date.");
                    dateInput.value = '';
                }
            }
        }

        if (document.getElementById('appointment_date')) {
            document.getElementById('appointment_date').addEventListener('change', () => {
                validateVetDate();
                checkRealTimeAvailability();
            });
        }

        // --- REAL-TIME SLOT AVAILABILITY CHECKER ---
        function checkRealTimeAvailability() {
            const date = document.getElementById('appointment_date').value;
            const category = document.getElementById('service_category').value;
            const timeSelect = document.getElementById('appointment_time');

            if (!date || !category) return;

            const originalTimes = {
                "10:00:00": "10:00 AM", "11:00:00": "11:00 AM", "12:00:00": "12:00 PM",
                "13:00:00": "01:00 PM", "14:00:00": "02:00 PM", "15:00:00": "03:00 PM",
                "16:00:00": "04:00 PM", "17:00:00": "05:00 PM"
            };

            Array.from(timeSelect.options).forEach(opt => {
                if (opt.value !== "") { opt.disabled = false; opt.text = originalTimes[opt.value]; }
            });

            fetch(`check_slots.php?date=${date}&category=${encodeURIComponent(category)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.is_full) {
                        alert('The selected date is fully booked for ' + category + '. Please choose another date.');
                        timeSelect.value = "";
                        Array.from(timeSelect.options).forEach(opt => {
                            if (opt.value !== "") opt.disabled = true;
                        });
                    } else {
                        data.booked_times.forEach(bookedTime => {
                            let option = timeSelect.querySelector(`option[value="${bookedTime}"]`);
                            if (option) {
                                option.disabled = true;
                                option.text += ' (Taken)';
                            }
                        });
                    }
                })
                .catch(error => console.error("Error fetching slots:", error));
        }

        function calculatePrice() {
            const petType = document.getElementById('pet_type').value;
            const size = document.getElementById('pet_size').value;
            const category = document.getElementById('service_category').value;
            const specific = document.getElementById('specific_service').value;
            
            const displayBox = document.getElementById('priceDisplay');
            const priceText = document.getElementById('priceText');
            const gcashSection = document.getElementById('gcashSection');
            const gcashRefInput = document.getElementById('gcash_ref_input');
            const gcashReceiptInput = document.getElementById('gcash_receipt_input');

            if (petType && size && category && specific) {
                let price = 0;
                const serviceData = pricingData[petType][category][specific];

                if (serviceData.default) {
                    price = serviceData.default;
                } else if (serviceData[size]) {
                    price = serviceData[size];
                }

                if (price > 0) {
                    priceText.innerText = '₱' + price.toFixed(2);
                    displayBox.style.display = 'block';
                    
                    // Show GCash Section and make REF and Receipt required
                    gcashSection.style.display = 'block';
                    gcashRefInput.required = true;
                    gcashReceiptInput.required = true;
                    return;
                }
            }
            displayBox.style.display = 'none';
            gcashSection.style.display = 'none';
            gcashRefInput.required = false;
            gcashReceiptInput.required = false;
        }
    </script>
</body>

</body>
</html>
