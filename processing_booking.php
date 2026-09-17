<?php
session_start();
include 'db_connect.php';

// Set timezone to Philippines
date_default_timezone_set('Asia/Manila');

// Ensure user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Capture and Clean Form Data
    $user_id    = $_SESSION['user_id'];
    $full_name  = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Customer';
    
    // Fetch user contact number for SMS
    $contact_number = '';
    $stmt_user = $conn->prepare("SELECT contact_number FROM users WHERE id = ?");
    $stmt_user->bind_param("i", $user_id);
    $stmt_user->execute();
    $user_res = $stmt_user->get_result();
    if ($row = $user_res->fetch_assoc()) {
        $contact_number = $row['contact_number'];
    }
    $stmt_user->close();

    $pet_name         = mysqli_real_escape_string($conn, $_POST['pet_name']);
    $pet_type         = mysqli_real_escape_string($conn, $_POST['pet_type']);
    $pet_gender       = mysqli_real_escape_string($conn, $_POST['pet_gender']);
    $pet_size         = mysqli_real_escape_string($conn, $_POST['pet_size']);
    $service_category = mysqli_real_escape_string($conn, $_POST['service_category']);
    $specific_service = mysqli_real_escape_string($conn, $_POST['specific_service']);
    $haircut_style    = isset($_POST['haircut_style']) ? mysqli_real_escape_string($conn, $_POST['haircut_style']) : '';
    
    $date = mysqli_real_escape_string($conn, $_POST['appointment_date']);
    $time = mysqli_real_escape_string($conn, $_POST['appointment_time']);
    $remarks = isset($_POST['remarks']) ? mysqli_real_escape_string($conn, $_POST['remarks']) : '';
    $gcash_ref = mysqli_real_escape_string($conn, $_POST['gcash_ref']);

    // Combine service name
    $final_service_name = $service_category . " - " . $specific_service;
    if (!empty($haircut_style) && $specific_service === 'Full Grooming Package') {
        $final_service_name .= " (" . $haircut_style . ")";
    }

    // --- 2. GCASH RECEIPT UPLOAD HANDLING ---
    $receipt_filename = '';
    if (isset($_FILES['gcash_receipt']) && $_FILES['gcash_receipt']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png'];
        $filename = $_FILES['gcash_receipt']['name'];
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        
        if (in_array(strtolower($ext), $allowed)) {
            if (!is_dir('uploads')) { mkdir('uploads', 0777, true); }
            $receipt_filename = 'receipt_' . time() . '_' . $user_id . '.' . $ext;
            
            if (!move_uploaded_file($_FILES['gcash_receipt']['tmp_name'], 'uploads/' . $receipt_filename)) {
                echo "<script>alert('Failed to upload receipt image.'); window.history.back();</script>";
                exit();
            }
        } else {
            echo "<script>alert('Invalid receipt image format. Only JPG and PNG.'); window.history.back();</script>";
            exit();
        }
    } else {
        echo "<script>alert('Please upload your GCash receipt screenshot.'); window.history.back();</script>";
        exit();
    }

    // --- 3. CAPACITY RULES (Based on Manuscript: 10 Grooming, 6 Vet) ---
    if ($service_category === 'Vet Services') {
        $day_of_week = date('w', strtotime($date));
        if ($day_of_week == 3 || $day_of_week == 6) { // 3=Wed, 6=Sat
            echo "<script>alert('Dr. Faith Casayuran is not available on Wednesdays and Saturdays. Please select a valid day.'); window.history.back();</script>";
            exit();
        }

        $capacity_query = "SELECT COUNT(id) as total_booked FROM appointments WHERE appointment_date = '$date' AND service LIKE 'Vet Services%' AND booking_status != 'Cancelled'";
        $capacity_result = mysqli_query($conn, $capacity_query);
        $capacity_row = mysqli_fetch_assoc($capacity_result);
        
        if ($capacity_row['total_booked'] >= 6) {
            echo "<script>alert('The Vet Clinic is fully booked for this date (Max 6 capacity). Please select another date.'); window.history.back();</script>";
            exit(); 
        }
    } elseif ($service_category === 'Grooming') {
        $capacity_query = "SELECT COUNT(id) as total_booked FROM appointments WHERE appointment_date = '$date' AND service LIKE 'Grooming%' AND booking_status != 'Cancelled'";
        $capacity_result = mysqli_query($conn, $capacity_query);
        $capacity_row = mysqli_fetch_assoc($capacity_result);
        
        if ($capacity_row['total_booked'] >= 10) {
            echo "<script>alert('Grooming services are fully booked for this date (Max 10 capacity). Please select another date.'); window.history.back();</script>";
            exit(); 
        }
    }

    // --- 4. EXACT TIME SLOT CONFLICT (First Pay, First Serve protection) ---
    $time_conflict_query = "SELECT id FROM appointments WHERE appointment_date = '$date' AND appointment_time = '$time' AND service LIKE '$service_category%' AND booking_status NOT IN ('Cancelled')";
    $time_conflict_result = mysqli_query($conn, $time_conflict_query);
    if ($time_conflict_result && mysqli_num_rows($time_conflict_result) > 0) {
        echo "<script>alert('This exact time slot is already taken. Please choose another time.'); window.history.back();</script>";
        exit(); 
    }

    // --- 5. PET PROFILE HANDLING (Find or Auto-Create) ---
    $pet_id = null;
    $check_pet_query = "SELECT id FROM pets WHERE owner_id = '$user_id' AND name = '$pet_name' LIMIT 1";
    $pet_result = mysqli_query($conn, $check_pet_query);

    if ($pet_result && mysqli_num_rows($pet_result) > 0) {
        $pet_row = mysqli_fetch_assoc($pet_result);
        $pet_id = $pet_row['id'];
        // Update size/weight just in case it changed
        mysqli_query($conn, "UPDATE pets SET weight = '$pet_size', pet_type = '$pet_type', gender = '$pet_gender' WHERE id = '$pet_id'");
    } else {
        $insert_pet = "INSERT INTO pets (owner_id, name, pet_type, gender, weight) VALUES ('$user_id', '$pet_name', '$pet_type', '$pet_gender', '$pet_size')";
        if (mysqli_query($conn, $insert_pet)) {
            $pet_id = mysqli_insert_id($conn);
        } else {
            die("Error registering pet: " . mysqli_error($conn));
        }
    }

    // --- 6. INSERT APPOINTMENT (Pending Verification) ---
    $payment_method = 'GCash'; 
    $payment_status = 'Pending Verification';
    $booking_status = 'Pending'; 

    $insert_query = "INSERT INTO appointments (user_id, pet_id, service, appointment_date, appointment_time, gcash_ref, gcash_receipt, payment_method, payment_status, booking_status, remarks) 
                     VALUES ('$user_id', '$pet_id', '$final_service_name', '$date', '$time', '$gcash_ref', '$receipt_filename', '$payment_method', '$payment_status', '$booking_status', '$remarks')";

    if (mysqli_query($conn, $insert_query)) {
        
        // Push Admin Notification
        $admin_msg = $full_name . " booked " . $pet_name . " via GCash. Ref: " . $gcash_ref . ". Pending Verification.";
        mysqli_query($conn, "INSERT INTO admin_notifications (message) VALUES ('$admin_msg')");

        // --- 7. SEMAPHORE SMS NOTIFICATION (Customer Alert) ---
        if (!empty($contact_number)) {
            $sms_api_key = 'YOUR_SEMAPHORE_API_KEY_HERE'; // PALITAN MO NITO NG TOTOO MONG API KEY KAPAG READY NA
            $formatted_time = date("g:i A", strtotime($time));
            $sms_message = "Hi $full_name, we received your GCash booking request for $pet_name on $date at $formatted_time. Please wait for the admin to verify your payment. Thank you! - Boogie's Pet Care";
            
            $ch = curl_init();
            $parameters = array(
                'apikey' => $sms_api_key,
                'number' => $contact_number,
                'message' => $sms_message,
                'sendername' => 'SEMAPHORE' // Palitan kung may approved Sender ID kayo
            );
            curl_setopt($ch, CURLOPT_URL, 'https://api.semaphore.co/api/v4/messages');
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $sms_output = curl_exec($ch);
            curl_close($ch);
        }

        echo "<script>
                alert('Booking Request Submitted! Please wait for admin verification.');
                window.location.href='bookings.php';
              </script>";
        exit();
    } else {
        echo "Error saving booking: " . mysqli_error($conn);
    }
} else {
    header("Location: dashboard.php");
    exit();
}
?>