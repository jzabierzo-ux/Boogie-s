<?php
session_start();
include 'db_supabase.php';
require_once __DIR__ . '/includes/iprog_sms.php';

// Set default timezone
date_default_timezone_set('Asia/Manila');

// Access Control
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

$full_name = isset($_SESSION['user_name'])
    ? $_SESSION['user_name']
    : (isset($_SESSION['full_name']) ? $_SESSION['full_name'] : 'User');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Alamin kung sino ang nagca-cancel: Admin ba o mismong Customer?
    $is_admin = isset($_SESSION['role']) &&
        in_array(strtolower(trim($_SESSION['role'])), ['admin', 'manager', 'vet']);

    $appointment_id = isset($_POST['appointment_id']) ? intval($_POST['appointment_id']) : 0;
    $cancel_reason = isset($_POST['cancel_reason']) ? trim($_POST['cancel_reason']) : '';

    if ($appointment_id > 0 && $cancel_reason !== '') {

        try {
            // 1. Fetch appointment details and verify ownership.
            // Admin/manager/vet can cancel any appointment.
            // Customer can only cancel their own appointment.
            $query = "
                SELECT
                    a.appointment_date,
                    a.appointment_time,
                    a.booking_status,
                    a.payment_status,
                    a.service,
                    a.user_id,
                    p.name AS pet_name,
                    u.contact_number
                FROM appointments a
                LEFT JOIN pets p ON a.pet_id = p.id
                LEFT JOIN users u ON a.user_id = u.id
                WHERE a.id = :appointment_id
            ";

            $params = [':appointment_id' => $appointment_id];

            if (!$is_admin) {
                $query .= " AND a.user_id = :session_user_id";
                $params[':session_user_id'] = intval($_SESSION['user_id']);
            }

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $service_name = $row['service'];
                $pet_name = !empty($row['pet_name']) ? $row['pet_name'] : 'your pet';
                $customer_phone = !empty($row['contact_number']) ? $row['contact_number'] : '';
                $customer_id = $row['user_id'];
                $appt_date_str = date('M d, Y', strtotime($row['appointment_date']));

                // SECURITY CHECK: Bawal i-cancel kapag Completed, No-Show, o Cancelled na
                $current_status = strtoupper(trim((string)$row['booking_status']));

                if (in_array($current_status, ['COMPLETED', 'NO-SHOW', 'CANCELLED'], true)) {
                    $redirect_url = $is_admin
                        ? "managebooking.php?error=invalid_status"
                        : "bookings.php?error=invalid_status";

                    header("Location: $redirect_url");
                    exit();
                }

                // 2. Calculate exact times
                $appt_timestamp = strtotime(
                    $row['appointment_date'] . ' ' . $row['appointment_time']
                );
                $current_timestamp = time();

                $time_until_appointment = $appt_timestamp - $current_timestamp;
                $twenty_four_hours = 24 * 60 * 60;

                // If the appointment is already in the past
                if ($time_until_appointment <= 0) {
                    $redirect_url = $is_admin
                        ? "managebooking.php?error=past_date"
                        : "bookings.php?error=past_date";

                    header("Location: $redirect_url");
                    exit();
                }

                // Enforce 24-Hour Rule for customers.
                // Admins can bypass this rule.
                if (!$is_admin && $time_until_appointment < $twenty_four_hours) {
                    header("Location: bookings.php?error=late_cancellation");
                    exit();
                }

                $new_status = 'Cancelled';
                $new_payment_status = (
                    $row['payment_status'] === 'Pending Verification' ||
                    $row['payment_status'] === 'Paid'
                ) ? 'Refund Requested' : 'Cancelled';

                // 3. Update the database
                $update_query = "
                    UPDATE appointments
                    SET booking_status = :booking_status,
                        payment_status = :payment_status,
                        cancel_reason = :cancel_reason
                    WHERE id = :appointment_id
                ";

                $update_stmt = $pdo->prepare($update_query);
                $update_stmt->execute([
                    ':booking_status' => $new_status,
                    ':payment_status' => $new_payment_status,
                    ':cancel_reason' => $cancel_reason,
                    ':appointment_id' => $appointment_id
                ]);

                // --- IN-APP NOTIFICATIONS ---
                if ($is_admin) {
                    // Notify Customer that Admin cancelled their booking
                    $notif_title = "Booking Cancelled by Clinic";
                    $notif_msg = "Sorry, your booking for $pet_name ($service_name) was cancelled by our staff. Reason: $cancel_reason.";

                    $notif_stmt = $pdo->prepare("
                        INSERT INTO notifications
                            (user_id, title, message, type, is_read, created_at)
                        VALUES
                            (:user_id, :title, :message, 'booking', 0, NOW())
                    ");

                    $notif_stmt->execute([
                        ':user_id' => $customer_id,
                        ':title' => $notif_title,
                        ':message' => $notif_msg
                    ]);

                    $redirect_target = "managebooking.php?msg=cancelled";
                } else {
                    // Notify Admin that Customer cancelled their booking
                    $notif_message =
                        $full_name .
                        " cancelled their " .
                        $service_name .
                        " appointment. Reason: " .
                        $cancel_reason .
                        ". (Payment Status: " .
                        $new_payment_status .
                        ")";

                    $notif_stmt = $pdo->prepare("
                        INSERT INTO admin_notifications
                            (message, is_read, created_at)
                        VALUES
                            (:message, 0, NOW())
                    ");

                    $notif_stmt->execute([
                        ':message' => $notif_message
                    ]);

                    $redirect_target = "bookings.php?msg=cancelled";
                }

                // ==========================================
                // ==========================================
                // IPROG SMS LOGIC (For Cancellations)
                // ==========================================
                if (!empty($customer_phone) && $customer_phone !== 'N/A') {

                    if ($is_admin) {
                        $sms_body =
                            "Hi! Your booking for $pet_name ($service_name) on $appt_date_str " .
                            "was CANCELLED by the clinic. Reason: $cancel_reason. - Boogie's Pet Care";
                    } else {
                        $sms_body =
                            "Hi $full_name, you have successfully cancelled your booking for " .
                            "$pet_name ($service_name) on $appt_date_str. - Boogie's Pet Care";
                    }

                    $sms_result = sendIPROGSMS($customer_phone, $sms_body);

                    if (!empty($sms_result['success'])) {
                        error_log(
                            'IPROG SMS queued successfully for cancellation. ' .
                            'Appointment ID: ' . $appointment_id .
                            ' | Message ID: ' . ($sms_result['message_id'] ?? 'N/A')
                        );
                    } else {
                        error_log(
                            'IPROG SMS failed for cancellation. ' .
                            'Appointment ID: ' . $appointment_id .
                            ' | HTTP: ' . ($sms_result['http_code'] ?? 0) .
                            ' | Response: ' . ($sms_result['response'] ?? 'Unknown error')
                        );
                    }
                }
                // ==========================================
                header("Location: " . $redirect_target);
                exit();

            } else {
                $redirect_url = $is_admin
                    ? "managebooking.php?error=unauthorized"
                    : "bookings.php?error=unauthorized";

                header("Location: $redirect_url");
                exit();
            }

        } catch (PDOException $e) {
            // Keep database details out of the browser.
            $redirect_url = $is_admin
                ? "managebooking.php?error=db_error"
                : "bookings.php?error=db_error";

            header("Location: $redirect_url");
            exit();
        }

    } else {
        $redirect_url = isset($_SERVER['HTTP_REFERER'])
            ? $_SERVER['HTTP_REFERER']
            : "index.php";

        header("Location: $redirect_url");
        exit();
    }

} else {
    header("Location: index.php");
    exit();
}
?>
