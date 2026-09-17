<?php
session_start();
include 'db_connect.php';

// Set default timezone
date_default_timezone_set('Asia/Manila'); 

// Access Control
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

$full_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : (isset($_SESSION['full_name']) ? $_SESSION['full_name'] : 'User');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Alamin kung sino ang nagca-cancel: Admin ba o mismong Customer?
    $is_admin = isset($_SESSION['role']) && in_array(strtolower(trim($_SESSION['role'])), ['admin', 'manager', 'vet']);
    
    // Kung admin, kahit anong user_id ang owner ng appointment, pwede niyang i-cancel. Kung customer, sarili lang niya.
    $user_id_condition = $is_admin ? "1=1" : "a.user_id = '" . intval($_SESSION['user_id']) . "'";
    
    $appointment_id = isset($_POST['appointment_id']) ? intval($_POST['appointment_id']) : 0;
    $cancel_reason = isset($_POST['cancel_reason']) ? $_POST['cancel_reason'] : '';

    if ($appointment_id > 0 && !empty($cancel_reason)) {
        
        // 1. Fetch the appointment details and verify ownership
        // Join with pets and users to get contact info for Twilio
        $query = "SELECT a.appointment_date, a.appointment_time, a.booking_status, a.payment_status, a.service, a.user_id, p.name as pet_name, u.contact_number 
                  FROM appointments a 
                  LEFT JOIN pets p ON a.pet_id = p.id 
                  LEFT JOIN users u ON a.user_id = u.id 
                  WHERE a.id = ? AND " . $user_id_condition;
                  
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $appointment_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $service_name = $row['service'];
            $pet_name = $row['pet_name'] ?? 'your pet';
            $customer_phone = $row['contact_number'] ?? '';
            $customer_id = $row['user_id'];
            $appt_date_str = date('M d, Y', strtotime($row['appointment_date']));
            
            // SECURITY CHECK: Bawal i-cancel kapag Completed, No-Show, o Cancelled na
            $current_status = strtoupper($row['booking_status']);
            if (in_array($current_status, ['COMPLETED', 'NO-SHOW', 'CANCELLED'])) {
                $redirect_url = $is_admin ? "managebooking.php?error=invalid_status" : "bookings.php?error=invalid_status";
                header("Location: $redirect_url");
                exit();
            }
            
            // 2. LOGIC: Calculate exact times
            $appt_timestamp = strtotime($row['appointment_date'] . ' ' . $row['appointment_time']);
            $current_timestamp = time();
            
            $time_until_appointment = $appt_timestamp - $current_timestamp; 
            $twenty_four_hours = 24 * 60 * 60; 

            // If the appointment is already in the past
            if ($time_until_appointment <= 0) {
                $redirect_url = $is_admin ? "managebooking.php?error=past_date" : "bookings.php?error=past_date";
                header("Location: $redirect_url");
                exit();
            }

            // Enforce 24-Hour Rule (If customer is cancelling. Admins can bypass this rule).
            if (!$is_admin && $time_until_appointment < $twenty_four_hours) {
                header("Location: bookings.php?error=late_cancellation");
                exit();
            } else {
                $new_status = 'Cancelled';
                $new_payment_status = ($row['payment_status'] === 'Pending Verification' || $row['payment_status'] === 'Paid') ? 'Refund Requested' : 'Cancelled';
            }

            // 3. Update the database
            $update_query = "UPDATE appointments SET booking_status = ?, payment_status = ?, cancel_reason = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("sssi", $new_status, $new_payment_status, $cancel_reason, $appointment_id);
            
            if ($update_stmt->execute()) {
                
                // --- IN-APP NOTIFICATIONS ---
                if ($is_admin) {
                    // Notify Customer that Admin cancelled their booking
                    $notif_title = "Booking Cancelled by Clinic";
                    $notif_msg = "Sorry, your booking for $pet_name ($service_name) was cancelled by our staff. Reason: $cancel_reason.";
                    $safe_title = mysqli_real_escape_string($conn, $notif_title);
                    $safe_msg = mysqli_real_escape_string($conn, $notif_msg);
                    
                    mysqli_query($conn, "INSERT INTO notifications (user_id, title, message, type, is_read, created_at) 
                                         VALUES ($customer_id, '$safe_title', '$safe_msg', 'booking', 0, NOW())");
                                         
                    $redirect_target = "managebooking.php?msg=cancelled";
                } else {
                    // Notify Admin that Customer cancelled their booking
                    $notif_message = $full_name . " cancelled their " . $service_name . " appointment. Reason: " . $cancel_reason . ". (Payment Status: " . $new_payment_status . ")";
                    $notif_insert = "INSERT INTO admin_notifications (message, is_read, created_at) VALUES (?, 0, NOW())";
                    $notif_stmt = $conn->prepare($notif_insert);
                    $notif_stmt->bind_param("s", $notif_message);
                    $notif_stmt->execute();
                    
                    $redirect_target = "bookings.php?msg=cancelled";
                }

                // ==========================================
                // TWILIO SMS LOGIC (For Cancellations)
                // ==========================================
                if (!empty($customer_phone) && $customer_phone !== 'N/A') {
                    
                    $to_number = trim($customer_phone);
                    if (preg_match('/^09[0-9]{9}$/', $to_number)) {
                        $to_number = '+63' . substr($to_number, 1);
                    }

                    if ($is_admin) {
                        $sms_body = "Hi! Your booking for $pet_name ($service_name) on $appt_date_str was CANCELLED by the clinic. Reason: $cancel_reason. - Boogie's Pet Care";
                    } else {
                        $sms_body = "Hi $full_name, you have successfully cancelled your booking for $pet_name ($service_name) on $appt_date_str. - Boogie's Pet Care";
                    }

                    // MGA TWILIO CREDENTIALS MO BOSS:
                    $twilio_sid = 'AC54e50d7e53eb6333b1ef2048aa404d39';
                    $twilio_token = 'c49968bc778155f48fe6fdc77b05d538';
                    $twilio_number = '+14783757537';

                    $url = "https://api.twilio.com/2010-04-01/Accounts/$twilio_sid/Messages.json";
                    $data = [
                        'From' => $twilio_number,
                        'To' => $to_number,
                        'Body' => $sms_body
                    ];

                    $ch = curl_init($url);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); 
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_USERPWD, "$twilio_sid:$twilio_token");
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
                    curl_exec($ch);
                    curl_close($ch);
                }
                // ==========================================

                header("Location: " . $redirect_target);
                exit();
            } else {
                $redirect_url = $is_admin ? "managebooking.php?error=db_error" : "bookings.php?error=db_error";
                header("Location: $redirect_url");
                exit();
            }
        } else {
            $redirect_url = $is_admin ? "managebooking.php?error=unauthorized" : "bookings.php?error=unauthorized";
            header("Location: $redirect_url");
            exit();
        }
    } else {
        $redirect_url = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : "index.php";
        header("Location: $redirect_url");
        exit();
    }
} else {
    header("Location: index.php");
    exit();
}
?>