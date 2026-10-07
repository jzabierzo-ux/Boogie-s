<?php
session_start();
include '../db_supabase.php';
require_once '../includes/iprog_sms.php';

// Set default timezone
date_default_timezone_set('Asia/Manila');

// --- UNIVERSAL SECURITY CHECK ---
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';

if (!isset($_SESSION['logged_in']) || !in_array($current_role, ['admin', 'manager', 'vet'], true)) {
    header("Location: ../staff/stafflogin.php");
    exit();
}

// 2. FETCH ADMIN PROFILE
$admin_full_name = "User";
$profile_img_path = "";
$first_name = "User";

if (isset($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];

    try {
        $get_admin = $pdo->prepare("
            SELECT full_name, profile_image
            FROM users
            WHERE id = :id
            LIMIT 1
        ");
        $get_admin->execute([':id' => $uid]);

        if ($admin_data = $get_admin->fetch(PDO::FETCH_ASSOC)) {
            $admin_full_name = $admin_data['full_name'] ?? 'User';
            $profile_img_path = $admin_data['profile_image'] ?? '';
            $_SESSION['user_name'] = $admin_full_name;

            $first_name = explode(' ', $admin_full_name)[0];
            $first_name = trim($first_name, ',');
        }
    } catch (PDOException $e) {
        // Keep page usable even if profile lookup fails.
    }
}

// ==========================================
// BAGO: WALK-IN BOOKING LOGIC
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_walkin'])) {
    $c_name = trim($_POST['customer_name'] ?? '');
    $p_name = trim($_POST['pet_name'] ?? '');
    $service = trim($_POST['service'] ?? '');
    $date = trim($_POST['appointment_date'] ?? '');
    $time = trim($_POST['appointment_time'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);

    if ($c_name !== '' && $p_name !== '' && $service !== '' && $date !== '' && $time !== '' && $amount >= 0) {
        try {
            $pdo->beginTransaction();

            // 1. Gagawa ng mabilis na "dummy" account para sa walk-in
            $dummy_email = 'walkin_' . time() . '_' . bin2hex(random_bytes(3)) . '@boogies.local';

            $insert_user = $pdo->prepare("
                INSERT INTO users
                    (full_name, email, password, role, is_verified)
                VALUES
                    (:full_name, :email, :password, 'user', TRUE)
                RETURNING id
            ");

            $insert_user->execute([
                ':full_name' => $c_name . ' (Walk-in)',
                ':email' => $dummy_email,
                ':password' => 'walkin123'
            ]);

            $new_user_id = (int)$insert_user->fetchColumn();

            // 2. I-save yung alagang hayop
            $insert_pet = $pdo->prepare("
                INSERT INTO pets
                    (owner_id, name, pet_type)
                VALUES
                    (:owner_id, :name, 'Walk-in Pet')
                RETURNING id
            ");

            $insert_pet->execute([
                ':owner_id' => $new_user_id,
                ':name' => $p_name
            ]);

            $new_pet_id = (int)$insert_pet->fetchColumn();

            // 3. I-save sa appointments (Auto-Confirmed at Paid Cash)
            $insert_appt = $pdo->prepare("
                INSERT INTO appointments
                    (
                        user_id,
                        pet_id,
                        service,
                        appointment_date,
                        appointment_time,
                        service_fee,
                        total_price,
                        payment_method,
                        payment_status,
                        booking_status
                    )
                VALUES
                    (
                        :user_id,
                        :pet_id,
                        :service,
                        :appointment_date,
                        :appointment_time,
                        :service_fee,
                        :total_price,
                        'Cash (Walk-in)',
                        'Paid',
                        'Completed'
                    )
            ");

            $insert_appt->execute([
                ':user_id' => $new_user_id,
                ':pet_id' => $new_pet_id,
                ':service' => $service,
                ':appointment_date' => $date,
                ':appointment_time' => $time,
                ':service_fee' => $amount,
                ':total_price' => $amount
            ]);

            $pdo->commit();

            $_SESSION['alert_msg'] = "Walk-in booking successfully added and marked as completed!";
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Walk-in booking error: ' . $e->getMessage());
            $_SESSION['alert_msg'] = "Unable to add the walk-in booking.";
        }

        header("Location: managebooking.php");
        exit();
    }

    $_SESSION['alert_msg'] = "Please complete all walk-in booking fields.";
    header("Location: managebooking.php");
    exit();
}
// ==========================================

// --- LOGIC: UPDATE STATUS WITH NOTIFICATIONS, PAYMENT & SMS ---
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $requested_status = (string)$_GET['action'];

    // Allow only the actions used by this page.
    $allowed_actions = ['pay', 'verify_gcash', 'Completed', 'Confirmed', 'Cancelled', 'No-Show', 'For-Rescheduling'];

    if ($id <= 0 || !in_array($requested_status, $allowed_actions, true)) {
        $_SESSION['alert_msg'] = "Invalid booking action.";
        header("Location: managebooking.php");
        exit();
    }

    $new_status = $requested_status;

    try {
        // --- MANUAL PAYMENT LOGIC ---
        if ($new_status === 'pay') {
            $stmt = $pdo->prepare("
                UPDATE appointments
                SET payment_status = 'Paid'
                WHERE id = :id
            ");
            $stmt->execute([':id' => $id]);

            $_SESSION['alert_msg'] = "Payment successfully marked as Paid.";
            header("Location: managebooking.php");
            exit();
        }

        // --- FINAL NO-SHOW ENFORCEMENT ---
        if ($new_status === 'No-Show') {
            $booking_check = $pdo->prepare("
                SELECT booking_status, reschedule_count
                FROM appointments
                WHERE id = :id
                LIMIT 1
            ");
            $booking_check->execute([':id' => $id]);
            $booking_row = $booking_check->fetch(PDO::FETCH_ASSOC);

            $status_now = strtoupper(trim((string)($booking_row['booking_status'] ?? '')));
            $count_now = (int)($booking_row['reschedule_count'] ?? 0);

            if ($status_now === 'CONFIRMED' && $count_now === 0) {
                $_SESSION['alert_msg'] =
                    "This is the customer's first missed appointment. " .
                    "Move it to For Rescheduling first so the customer receives the one allowed reschedule.";
                header("Location: managebooking.php");
                exit();
            }

            if ($status_now !== 'RESCHEDULED' || $count_now !== 1) {
                $_SESSION['alert_msg'] =
                    "No-Show is only available after the customer's one-time reschedule has been used.";
                header("Location: managebooking.php");
                exit();
            }

            // The normal No-Show update logic below will now perform the final forfeiture.
        }

        // --- ONE-TIME RESCHEDULE ELIGIBILITY ---
        if ($new_status === 'For-Rescheduling') {
            $booking_check = $pdo->prepare("
                SELECT id, booking_status, payment_status, reschedule_count
                FROM appointments
                WHERE id = :id
                LIMIT 1
            ");
            $booking_check->execute([':id' => $id]);
            $booking_row = $booking_check->fetch(PDO::FETCH_ASSOC);

            if (!$booking_row) {
                $_SESSION['alert_msg'] = "Booking not found.";
                header("Location: managebooking.php");
                exit();
            }

            $status_now = strtoupper(trim((string)($booking_row['booking_status'] ?? '')));
            $payment_now = strtoupper(trim((string)($booking_row['payment_status'] ?? '')));
            $count_now = (int)($booking_row['reschedule_count'] ?? 0);

            if ($status_now !== 'CONFIRMED') {
                $_SESSION['alert_msg'] = "Only Confirmed bookings can be moved to For Rescheduling.";
                header("Location: managebooking.php");
                exit();
            }

            if ($payment_now !== 'PAID') {
                $_SESSION['alert_msg'] = "The booking must be paid and verified before it can be rescheduled.";
                header("Location: managebooking.php");
                exit();
            }

            if ($count_now >= 1) {
                $_SESSION['alert_msg'] = "This booking has already used its one reschedule.";
                header("Location: managebooking.php");
                exit();
            }

            $stmt = $pdo->prepare("
                UPDATE appointments
                SET booking_status = 'For Rescheduling'
                WHERE id = :id
                  AND booking_status = 'Confirmed'
                  AND payment_status = 'Paid'
                  AND reschedule_count = 0
            ");
            $stmt->execute([':id' => $id]);

            if ($stmt->rowCount() !== 1) {
                $_SESSION['alert_msg'] = "This booking could not be moved to rescheduling.";
                header("Location: managebooking.php");
                exit();
            }

            $info_stmt = $pdo->prepare("
                SELECT
                    a.*,
                    p.name AS pet_real_name,
                    u.full_name AS customer_name,
                    u.contact_number
                FROM appointments a
                LEFT JOIN pets p ON a.pet_id = p.id
                LEFT JOIN users u ON a.user_id = u.id
                WHERE a.id = :id
                LIMIT 1
            ");
            $info_stmt->execute([':id' => $id]);
            $booking_info = $info_stmt->fetch(PDO::FETCH_ASSOC);

            if ($booking_info) {
                $u_id = (int)($booking_info['user_id'] ?? 0);
                $service = $booking_info['service'] ?? 'Service';
                $pet = $booking_info['pet_real_name'] ?? 'your pet';

                if ($u_id > 0) {
                    $notif_stmt = $pdo->prepare("
                        INSERT INTO notifications
                            (user_id, title, message, type, is_read, created_at)
                        VALUES
                            (:user_id, :title, :message, 'booking', FALSE, NOW())
                    ");

                    $notif_stmt->execute([
                        ':user_id' => $u_id,
                        ':title' => 'Rescheduling Available',
                        ':message' => "Your booking for $pet ($service) was marked for rescheduling because the scheduled visit was missed. You have one reschedule opportunity within 3 days after the original appointment. Your GCash payment remains valid for the same service and is non-refundable."
                    ]);

                    $phone_number = trim((string)($booking_info['contact_number'] ?? ''));

                    if ($phone_number !== '' && strtoupper($phone_number) !== 'N/A') {
                        $sms_msg =
                            "Notice: Your booking for $pet ($service) is now FOR RESCHEDULING. " .
                            "You have one reschedule opportunity within 3 days after the original appointment. Your GCash payment remains valid for the same service and is non-refundable. " .
                            "- Boogie's Pet Care";

                        $sms_result = sendIPROGSMS($phone_number, $sms_msg);

                        if (empty($sms_result['success'])) {
                            error_log(
                                'IPROG SMS failed for rescheduling appointment #' . $id .
                                ' | ' . ($sms_result['response'] ?? 'Unknown error')
                            );
                        }
                    }
                }
            }

            $_SESSION['alert_msg'] =
                "Booking moved to For Rescheduling. The customer has one reschedule opportunity within 3 days.";

            header("Location: managebooking.php");
            exit();
        }

        // --- DB UPDATE LOGIC ---
        if ($new_status === 'verify_gcash') {
            // GCash verification applies to online GCash bookings.
            // A walk-in does not use this action.
            $stmt = $pdo->prepare("
                UPDATE appointments
                SET payment_status = 'Paid',
                    booking_status = 'Confirmed'
                WHERE id = :id
                  AND COALESCE(appointment_type, 'Online') <> 'Walk-in'
            ");
            $stmt->execute([':id' => $id]);

            if ($stmt->rowCount() !== 1) {
                $_SESSION['alert_msg'] = "GCash verification is only available for online bookings.";
                header("Location: managebooking.php");
                exit();
            }

            $new_status = 'Confirmed';
            $_SESSION['alert_msg'] = "GCash Payment Verified and Booking Confirmed!";
        } else {
            if ($new_status === 'Completed') {
                // Completing a walk-in must NOT automatically mark it as paid.
                // Payment may be collected after the service is finished.
                $stmt = $pdo->prepare("
                    UPDATE appointments
                    SET booking_status = 'Completed',
                        payment_status = CASE
                            WHEN appointment_type = 'Walk-in' THEN COALESCE(NULLIF(payment_status, ''), 'Pending')
                            ELSE 'Paid'
                        END
                    WHERE id = :id
                      AND (
                          appointment_type = 'Walk-in'
                          OR booking_status IN ('Confirmed', 'confirmed', 'Rescheduled', 'rescheduled')
                      )
                ");
                $stmt->execute([':id' => $id]);

                if ($stmt->rowCount() !== 1) {
                    $_SESSION['alert_msg'] = "A booking must be Confirmed or Rescheduled before it can be completed.";
                    header("Location: managebooking.php");
                    exit();
                }
            } elseif ($new_status === 'Confirmed') {
                // Online/manual confirmation keeps the existing paid behavior.
                // Walk-ins start as Confirmed + Pending and stay Pending until payment.
                $stmt = $pdo->prepare("
                    UPDATE appointments
                    SET booking_status = 'Confirmed',
                        payment_status = CASE
                            WHEN appointment_type = 'Walk-in' THEN COALESCE(NULLIF(payment_status, ''), 'Pending')
                            ELSE 'Paid'
                        END
                    WHERE id = :id
                ");
                $stmt->execute([':id' => $id]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE appointments
                    SET booking_status = :booking_status
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':booking_status' => $new_status,
                    ':id' => $id
                ]);
            }
        }

        // --- NOTIFICATION & SMS BUILDER ---
        $info_stmt = $pdo->prepare("
            SELECT
                a.*,
                p.name AS pet_real_name,
                p.owner_id,
                u.full_name AS customer_name,
                u.contact_number
            FROM appointments a
            LEFT JOIN pets p ON a.pet_id = p.id
            LEFT JOIN users u ON a.user_id = u.id
            WHERE a.id = :id
            LIMIT 1
        ");

        $info_stmt->execute([':id' => $id]);
        $booking_info = $info_stmt->fetch(PDO::FETCH_ASSOC);

        if ($booking_info) {
            // The appointment's user_id is the customer's actual users.id.
            $u_id = (int)($booking_info['user_id'] ?? 0);

            if ($u_id <= 0) {
                $u_id = (int)($booking_info['owner_id'] ?? 0);
            }

            $service = $booking_info['service'] ?? 'Service';
            $pet = $booking_info['pet_real_name'] ?? 'your pet';

            $appt_date = !empty($booking_info['appointment_date'])
                ? date('M d, Y', strtotime($booking_info['appointment_date']))
                : '';

            $title = "";
            $msg = "";
            $sms_msg = "";

            if ($new_status === 'Confirmed') {
                $title = "Booking Confirmed!";
                $msg = "Your booking for $pet ($service) is now confirmed. See you soon!";
                $sms_msg = "Hi! Your booking for $pet ($service) on $appt_date at Boogie's Pet Care is CONFIRMED. Thank you!";
            } elseif ($new_status === 'Cancelled') {
                $title = "Booking Cancelled";
                $msg = "Sorry, your booking for $pet ($service) was cancelled by the clinic.";
                $sms_msg = "Hi. Your booking for $pet ($service) on $appt_date was CANCELLED. Please check your portal for details. - Boogie's";
            } elseif ($new_status === 'Completed') {
                $title = "Service Completed!";
                $msg = "The $service for $pet is now marked as complete. Thank you for choosing Boogie's Pet Care!";
                $sms_msg = "Hi! The $service for $pet is now COMPLETE. Thank you for choosing Boogie's Pet Care!";
            } elseif ($new_status === 'No-Show') {
                $title = "Booking Forfeited (No-Show)";
                $msg = "Your booking for $pet ($service) was marked as No-Show after the allowed reschedule was missed. The GCash payment has been forfeited and is non-refundable.";
                $sms_msg = "Notice: Your booking for $pet ($service) was marked as NO-SHOW after the allowed reschedule was missed. The payment is forfeited and non-refundable. - Boogie's Pet Care";
            }

            // INSERT IN-APP NOTIFICATION
            if ($msg !== '' && $u_id > 0) {
                $notif_stmt = $pdo->prepare("
                    INSERT INTO notifications
                        (user_id, title, message, type, is_read, created_at)
                    VALUES
                        (:user_id, :title, :message, 'booking', FALSE, NOW())
                ");

                $notif_stmt->execute([
                    ':user_id' => $u_id,
                    ':title' => $title,
                    ':message' => $msg
                ]);
            }

            // ==========================================
            // IPROG SMS — ONLY SMS PROVIDER
            // ==========================================
            if ($sms_msg !== '') {
                $phone_number = trim((string)($booking_info['contact_number'] ?? ''));

                if ($u_id <= 0) {
                    $_SESSION['alert_msg'] = "Booking updated, but SMS was not sent: customer account was not found.";
                } elseif ($phone_number === '' || strtoupper($phone_number) === 'N/A') {
                    $_SESSION['alert_msg'] = "Booking updated, but SMS was not sent: customer has no contact number.";
                } else {
                    $sms_result = sendIPROGSMS($phone_number, $sms_msg);

                    if (!empty($sms_result['success'])) {
                        $message_id = '';
                        $decoded_sms = json_decode($sms_result['response'] ?? '', true);

                        if (is_array($decoded_sms) && isset($decoded_sms['message_id'])) {
                            $message_id = (string)$decoded_sms['message_id'];
                        }

                        $_SESSION['alert_msg'] = "Booking updated successfully. SMS queued for delivery.";

                        error_log(
                            'IPROG SMS queued for appointment #' . $id .
                            ' | Phone: ' . ($sms_result['phone_number'] ?? $phone_number) .
                            ($message_id !== '' ? ' | Message ID: ' . $message_id : '')
                        );
                    } else {
                        $safe_error = trim((string)($sms_result['response'] ?? 'Unknown IPROG error'));

                        $_SESSION['alert_msg'] = "Booking updated, but SMS failed (HTTP " .
                            (int)($sms_result['http_code'] ?? 0) . ": " .
                            htmlspecialchars($safe_error, ENT_QUOTES, 'UTF-8') . ").";

                        error_log(
                            'IPROG SMS FAILED for appointment #' . $id .
                            ' | HTTP ' . ($sms_result['http_code'] ?? 0) .
                            ' | ' . ($sms_result['response'] ?? 'Unknown error')
                        );
                    }
                }
            }
        } else {
            $_SESSION['alert_msg'] = "Booking status was updated, but the booking details could not be loaded for notifications/SMS.";
        }

    } catch (PDOException $e) {
        error_log('Manage booking database error: ' . $e->getMessage());
        $_SESSION['alert_msg'] = "Unable to update the booking right now.";
    } catch (Throwable $e) {
        error_log('Manage booking error: ' . $e->getMessage());
        $_SESSION['alert_msg'] = "An unexpected error occurred while updating the booking.";
    }

    if (!isset($_SESSION['alert_msg'])) {
        $_SESSION['alert_msg'] = "Status updated to " . htmlspecialchars($new_status, ENT_QUOTES, 'UTF-8') . " successfully.";
    }

    header("Location: managebooking.php");
    exit();
}

// --- FETCH ADMIN NOTIFICATIONS ---
try {
    $admin_notif_stmt = $pdo->prepare("
        SELECT *
        FROM admin_notifications
        WHERE is_read = 0
        ORDER BY created_at DESC
    ");
    $admin_notif_stmt->execute();
    $admin_notifications = $admin_notif_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $admin_notifications = [];
}

$unread_count = count($admin_notifications);

// --- DYNAMIC COUNTS ---
try {
    $total_count_stmt = $pdo->query("SELECT COUNT(*) FROM appointments");
    $total_count = (int)$total_count_stmt->fetchColumn();

    $pending_count_stmt = $pdo->query("
        SELECT COUNT(*)
        FROM appointments
        WHERE booking_status = 'Pending'
           OR booking_status IS NULL
           OR booking_status = ''
    ");
    $pending_count = (int)$pending_count_stmt->fetchColumn();

    $confirmed_count_stmt = $pdo->query("
        SELECT COUNT(*)
        FROM appointments
        WHERE booking_status = 'Confirmed'
    ");
    $confirmed_count = (int)$confirmed_count_stmt->fetchColumn();

    $completed_count_stmt = $pdo->query("
        SELECT COUNT(*)
        FROM appointments
        WHERE booking_status = 'Completed'
    ");
    $completed_count = (int)$completed_count_stmt->fetchColumn();

    $cancelled_count_stmt = $pdo->query("
        SELECT COUNT(*)
        FROM appointments
        WHERE booking_status = 'Cancelled'
           OR booking_status = 'No-Show'
    ");
    $cancelled_count = (int)$cancelled_count_stmt->fetchColumn();

    $reschedule_count_stmt = $pdo->query("
        SELECT COUNT(*)
        FROM appointments
        WHERE booking_status = 'For Rescheduling'
    ");
    $reschedule_count = (int)$reschedule_count_stmt->fetchColumn();

    $rescheduled_count_stmt = $pdo->query("
        SELECT COUNT(*)
        FROM appointments
        WHERE booking_status = 'Rescheduled'
    ");
    $rescheduled_count = (int)$rescheduled_count_stmt->fetchColumn();
} catch (PDOException $e) {
    $total_count = 0;
    $pending_count = 0;
    $confirmed_count = 0;
    $completed_count = 0;
    $cancelled_count = 0;
    $rescheduled_count = 0;
}

// --- DETERMINE FILTER STATUS FROM URL ---
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'All';

$where_clause = "";
$where_params = [];

if ($filter_status === 'Active') {
    $where_clause = "WHERE (a.booking_status NOT IN ('Completed', 'Cancelled', 'No-Show') OR a.booking_status IS NULL)";
} elseif ($filter_status === 'Pending') {
    $where_clause = "WHERE a.booking_status = 'Pending' OR a.booking_status IS NULL OR a.booking_status = ''";
} elseif ($filter_status === 'All') {
    $where_clause = "";
} elseif ($filter_status === 'Cancelled') {
    $where_clause = "WHERE a.booking_status IN ('Cancelled', 'No-Show')";
} elseif (in_array($filter_status, ['Confirmed', 'For Rescheduling', 'Rescheduled', 'Completed', 'No-Show'], true)) {
    $where_clause = "WHERE a.booking_status = :filter_status";
    $where_params[':filter_status'] = $filter_status;
} else {
    $filter_status = 'Active';
    $where_clause = "WHERE (a.booking_status NOT IN ('Completed', 'Cancelled', 'No-Show') OR a.booking_status IS NULL)";
}

// --- FETCH BOOKINGS ---
try {
    $bookings_sql = "
        SELECT
            a.*,
            p.name AS pet_display_name
        FROM appointments a
        LEFT JOIN pets p ON a.pet_id = p.id
        $where_clause
        ORDER BY
            CASE WHEN a.appointment_date >= CURRENT_DATE THEN 0 ELSE 1 END,
            CASE WHEN a.appointment_date >= CURRENT_DATE THEN a.appointment_date END ASC,
            CASE WHEN a.appointment_date < CURRENT_DATE THEN a.appointment_date END DESC,
            a.appointment_time ASC
    ";

    $bookings_stmt = $pdo->prepare($bookings_sql);
    $bookings_stmt->execute($where_params);
    $bookings = $bookings_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Manage bookings fetch error: ' . $e->getMessage());
    $bookings = [];
}

$total_rows_showing = count($bookings);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Bookings | Boogie's Pet Care</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --navy-dark: #001f3f;
            --brand-yellow: #ffcc00;
            --brand-blue: #001f3f;
            --staff-blue: #3b82f6;
            --bg-light: #f4f7f6;
            --white: #ffffff;
            --text-main: #2d3436;
            --text-muted: #636e72;
            --sidebar-width: 260px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Poppins', sans-serif; background-color: var(--bg-light); display: flex; min-height: 100vh; }

        /* --- SIDEBAR --- */
        aside { width: var(--sidebar-width); background-color: var(--navy-dark); color: var(--white); display: flex; flex-direction: column; position: fixed; height: 100vh; z-index: 100; }
        .sidebar-header { padding: 30px 20px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .sidebar-logo { width: 80px; height: auto; object-fit: contain; margin-bottom: 10px; }
        .sidebar-header h2 { font-size: 16px; color: var(--brand-yellow); text-transform: uppercase; letter-spacing: 1px; font-weight: 800; }
        
        .nav-links { flex-grow: 1; padding: 20px 15px; display: flex; flex-direction: column; gap: 5px; }
        .nav-item { display: flex; align-items: center; padding: 14px 20px; color: #94a3b8; text-decoration: none; transition: all 0.3s ease; font-size: 14px; font-weight: 500; border-radius: 10px; position: relative; }
        .nav-item i { width: 32px; font-size: 18px; transition: transform 0.3s; }
        .nav-item:hover { color: var(--white); background-color: rgba(255, 255, 255, 0.05); transform: translateX(4px); }
        .nav-item.active { color: var(--brand-yellow); background-color: rgba(255, 204, 0, 0.08); font-weight: 700; }
        .nav-item.active::before { content: ''; position: absolute; left: -15px; top: 15%; height: 70%; width: 5px; background-color: var(--brand-yellow); border-radius: 0 5px 5px 0; box-shadow: 2px 0 8px rgba(255, 204, 0, 0.5); }

        main { margin-left: var(--sidebar-width); flex-grow: 1; display: flex; flex-direction: column; min-height: 100vh; }
        .top-bar { background-color: var(--white); height: 70px; padding: 0 40px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 10px rgba(0,0,0,0.08); position: sticky; top: 0; z-index: 1000; }
        .breadcrumb { font-weight: 700; color: var(--navy-dark); font-size: 15px; display: flex; align-items: center; gap: 8px; }

        /* --- NOTIFICATIONS --- */
        .top-right-actions { display: flex; align-items: center; gap: 20px; }
        .notif-wrapper { position: relative; display: inline-flex; align-items: center; cursor: pointer; }
        .notif-badge { position: absolute; top: -5px; right: -8px; background: #e11d48; color: white; font-size: 10px; font-weight: bold; padding: 2px 5px; border-radius: 50%; }
        .notif-dropdown { display: none; position: absolute; right: 0; top: 35px; width: 320px; background: white; border: 1px solid #e2e8f0; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); border-radius: 8px; z-index: 1000; text-align: left; }
        .notif-dropdown.show { display: block; }
        .notif-header { padding: 12px 15px; border-bottom: 1px solid #e2e8f0; font-weight: 700; font-size: 14px; display: flex; justify-content: space-between; align-items: center; color: #001f3f; }
        .notif-body { max-height: 300px; overflow-y: auto; }
        .notif-item { padding: 12px 15px; border-bottom: 1px solid #f1f5f9; font-size: 13px; color: #334155; line-height: 1.4; }
        .notif-item:last-child { border-bottom: none; }
        .notif-empty { padding: 20px; text-align: center; color: #94a3b8; font-size: 13px; }
        .mark-read-btn { font-size: 11px; color: #3b82f6; text-decoration: none; font-weight: 600; }
        
        .profile-wrapper { position: relative; display: inline-flex; align-items: center; gap: 12px; border-left: 1px solid #e2e8f0; padding-left: 20px; cursor: pointer; user-select: none; }
        .top-avatar { width: 35px; height: 35px; border-radius: 50%; object-fit: cover; border: 2px solid var(--navy-dark); }
        .top-avatar-fallback { width: 35px; height: 35px; border-radius: 50%; background: linear-gradient(135deg, var(--navy-dark), var(--brand-blue)); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 14px; border: 2px solid white; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .profile-dropdown { display: none; position: absolute; right: 0; top: 45px; width: 200px; background: white; border: 1px solid #e2e8f0; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); border-radius: 8px; z-index: 1000; overflow: hidden; text-align: left; }
        .profile-dropdown.show { display: block; }
        .profile-item { padding: 12px 15px; display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--text-main); text-decoration: none; transition: background 0.2s; }
        .profile-item:hover { background: #f1f5f9; color: var(--navy-dark); }
        .profile-item.logout-text { color: #e11d48; border-top: 1px solid #f1f5f9; }

        .admin-tag { background: var(--brand-blue); color: var(--brand-yellow); padding: 6px 16px; border-radius: 50px; font-size: 10px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; }
        .admin-tag.staff { background: var(--staff-blue); color: white;}

        .container { padding: 40px; flex-grow: 1; }

        .page-header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 25px; }
        .page-header h1 { font-size: 26px; color: var(--navy-dark); font-weight: 800; }
        .page-header p { color: var(--text-muted); font-size: 14px; margin-top: 5px; font-weight: 500;}

        /* BAGO: Add Walk-in Button */
        .btn-add-walkin {
            background: var(--navy-dark); color: var(--brand-yellow); padding: 12px 20px; 
            border-radius: 8px; font-size: 14px; font-weight: 700; border: none; cursor: pointer;
            display: flex; align-items: center; gap: 8px; transition: 0.3s; box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .btn-add-walkin:hover { transform: translateY(-2px); box-shadow: 0 6px 12px rgba(0,0,0,0.15); }

        .status-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 20px; margin-bottom: 25px; }
        .status-card { background: var(--white); padding: 20px; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); border-bottom: 4px solid transparent; cursor: pointer; transition: transform 0.2s ease, box-shadow 0.2s ease; }
        .status-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.1); }
        .status-card.total { border-bottom-color: #e2e8f0; }
        .status-card.pending { border-bottom-color: #ffcc00; }
        .status-card.confirmed { border-bottom-color: #3b82f6; }
        .status-card.completed { border-bottom-color: #10b981; }
        .status-card.cancelled { border-bottom-color: #ef4444; }
        .status-card.rescheduling { border-bottom-color: #0ea5e9; }
        .status-card h4 { font-size: 12px; color: var(--text-muted); text-transform: uppercase; margin-bottom: 10px; font-weight: 700; letter-spacing: 0.5px;}
        .status-card .count { font-size: 24px; font-weight: 800; color: var(--navy-dark); }

        .table-container { background: var(--white); border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.03); }
        .table-controls { padding: 20px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; gap: 15px; }
        .filter-select { padding: 8px 15px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px; color: #4a5568; outline: none; font-family: 'Poppins', sans-serif;}

        .table-wrapper { overflow-x: auto; }
        .booking-table { width: 100%; border-collapse: collapse; min-width: 900px;}
        .booking-table th { padding: 15px 20px; background: #f8fafc; color: #64748b; font-size: 12px; text-transform: uppercase; text-align: left; border-bottom: 2px solid #edf2f7; font-weight: 700; }
        .booking-table td { padding: 15px 20px; font-size: 14px; color: #2d3436; border-bottom: 1px solid #edf2f7; vertical-align: middle; }
        .booking-table tr:hover { background-color: #f8fafc; }
        
        .action-group { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn-icon { width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; text-decoration: none; color: white; transition: all 0.2s ease; font-size: 14px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .btn-icon:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.15); }
        .btn-icon-pay { background: #3b82f6; } 
        .btn-icon-pay-process { background: #8b2cf5; } 
        .btn-icon-confirm { background: #10b981; } 
        .btn-icon-cancel { background: #ef4444; } 
        .btn-icon-noshow { background: #f97316; }
        .btn-icon-reschedule { background: #0ea5e9; } 
        
        .btn-verify-gcash { background: #10b981; color: white; padding: 6px 12px; border-radius: 6px; text-decoration: none; font-size: 12px; font-weight: bold; display: inline-flex; align-items: center; gap: 5px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); transition: 0.2s; }
        .btn-verify-gcash:hover { background: #059669; transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.15); }

        .status-pill { padding: 6px 14px; border-radius: 6px; font-size: 11px; font-weight: 800; letter-spacing: 0.5px; box-shadow: inset 0 0 0 1px rgba(0,0,0,0.05); text-transform: uppercase; }
        .status-Pending { background: #fef3c7; color: #92400e; }
        .status-Confirmed { background: #dbeafe; color: #1e40af; }
        .status-Completed { background: #dcfce7; color: #166534; }
        .status-Cancelled { background: #fee2e2; color: #991b1b; }
        .status-No-Show { background: #ffedd5; color: #ea580c; border: 1px solid #fdba74;}
        .status-Rescheduled { background: #e0f2fe; color: #0369a1; }

        .empty-state { text-align: center; padding: 80px 0; color: #94a3b8; }
        .empty-state i { font-size: 50px; margin-bottom: 15px; opacity: 0.3; }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            inset: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 31, 63, 0.6);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 28px;
            overflow-y: auto;
        }
        .modal-content {
            background: var(--white);
            width: min(520px, 100%);
            max-height: calc(100dvh - 56px);
            overflow-y: auto;
            border-radius: 16px;
            padding: 26px 28px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            margin: 0 auto;
        }
        .modal-content::-webkit-scrollbar { width: 7px; }
        .modal-content::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 8px; }
        .modal-content::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 8px; }
        .modal-content::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        @media (max-width: 680px) {
            .modal-overlay { padding: 14px; align-items: center; }
            .modal-content {
                width: 100%;
                max-width: 500px;
                max-height: calc(100dvh - 28px);
                padding: 22px 18px;
                border-radius: 14px;
            }
        }
        .modal-header { display: flex; gap: 15px; align-items: flex-start; margin-bottom: 20px; }
        .warning-icon { background: #fee2e2; color: #dc2626; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
        .modal-header h2 { font-size: 18px; color: var(--navy-dark); margin-bottom: 2px; font-weight: 800;}
        .modal-header p { font-size: 13px; color: var(--text-muted); }

        .booking-details-box { background: #f8fafc; border-radius: 8px; padding: 15px; margin-bottom: 20px; border: 1px solid #e2e8f0;}
        .booking-details-box p { font-size: 13px; color: var(--text-muted); margin-bottom: 5px; }
        .booking-details-box h3 { font-size: 16px; color: var(--navy-dark); margin-bottom: 5px; font-weight: 700;}
        .booking-details-box .detail-row { font-size: 13px; color: var(--text-main); font-weight: 500;}

        .modal-form-group { margin-bottom: 15px; }
        .modal-form-group label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 5px; color: var(--navy-dark); }
        .modal-form-group input, .modal-form-group select { width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; outline: none; font-family: 'Poppins', sans-serif;}

        .important-warning { background: #fefce8; border: 1px solid #fef08a; color: #b45309; padding: 12px 15px; border-radius: 8px; font-size: 12px; display: flex; gap: 10px; margin-bottom: 25px; }
        .modal-actions { display: flex; gap: 15px; }
        .modal-actions button { flex: 1; padding: 12px; border-radius: 8px; font-size: 14px; font-weight: 700; cursor: pointer; transition: 0.3s; font-family: 'Poppins', sans-serif;}
        .btn-keep { background: var(--white); color: var(--text-main); border: 1px solid #cbd5e1; }
        .btn-keep:hover { background: #f1f5f9; }
        .btn-confirm-cancel { background: #ef4444; color: var(--white); border: none; }
        .btn-confirm-cancel:hover { background: #dc2626; }

        footer { text-align: center; padding: 40px; color: var(--text-muted); font-size: 12px; background: transparent; }

        /* ===== RESPONSIVE ADMIN LAYOUT ===== */
        .topbar-left {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .mobile-menu-toggle {
            display: none;
            border: 0;
            background: var(--navy-dark);
            color: var(--brand-yellow);
            width: 40px;
            height: 40px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 18px;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
        }

        .sidebar-backdrop { display: none; }

        @media (max-width: 1200px) {
            .status-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .page-header { align-items: flex-start; }
        }

        @media (max-width: 900px) {
            body { overflow-x: hidden; }

            aside {
                transform: translateX(-100%);
                transition: transform 0.25s ease;
                box-shadow: 12px 0 30px rgba(0,0,0,0.18);
                z-index: 2000;
            }

            aside.mobile-open { transform: translateX(0); }

            .sidebar-backdrop {
                position: fixed;
                inset: 0;
                background: rgba(0, 31, 63, 0.48);
                z-index: 1900;
            }

            .sidebar-backdrop.show { display: block; }

            main {
                margin-left: 0;
                width: 100%;
                min-width: 0;
            }

            .top-bar {
                height: 64px;
                padding: 0 14px;
                gap: 10px;
            }

            .topbar-left {
                gap: 8px;
                min-width: 0;
                flex: 1 1 auto;
            }

            .mobile-menu-toggle { display: inline-flex; }

            .breadcrumb {
                min-width: 0;
                max-width: 100%;
                font-size: 13px;
                gap: 6px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .breadcrumb i {
                font-size: 13px !important;
                flex: 0 0 auto;
            }

            .top-right-actions {
                gap: 10px;
                flex: 0 0 auto;
            }

            .notif-wrapper > i { font-size: 20px !important; }

            .profile-wrapper {
                gap: 8px;
                padding-left: 10px;
                min-width: 0;
            }

            .profile-wrapper > span:last-of-type {
                max-width: 110px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
                font-size: 12px !important;
            }

            .admin-tag { padding: 5px 10px; font-size: 9px; }

            .top-avatar,
            .top-avatar-fallback {
                width: 32px;
                height: 32px;
            }

            .notif-dropdown {
                width: min(320px, calc(100vw - 28px));
                right: -6px;
            }

            .profile-dropdown {
                width: min(200px, calc(100vw - 28px));
            }

            .container { padding: 24px 16px; }

            .page-header {
                flex-direction: column;
                gap: 16px;
                align-items: stretch;
            }

            .page-header h1 { font-size: 23px; }
            .page-header p { font-size: 13px; line-height: 1.5; }

            .btn-add-walkin {
                width: 100%;
                justify-content: center;
                min-height: 44px;
            }

            .status-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px;
            }

            .status-card { padding: 16px; }
            .status-card h4 { font-size: 10px; }
            .status-card .count { font-size: 22px; }

            .table-container { border-radius: 10px; }

            .table-controls {
                padding: 14px;
                display: grid;
                grid-template-columns: auto 1fr;
                gap: 10px;
            }

            .table-controls .filter-select {
                width: 100%;
                min-width: 0;
                margin-left: 0 !important;
            }

            #showingCount {
                grid-column: 1 / -1;
                margin-left: 0 !important;
                font-size: 11px !important;
            }

            .table-wrapper {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            .booking-table { min-width: 900px; }

            footer {
                padding: 28px 16px;
                font-size: 10px;
            }
        }

        @media (max-width: 560px) {
            .top-bar {
                height: 60px;
                padding: 0 10px;
            }

            .mobile-menu-toggle {
                width: 36px;
                height: 36px;
                border-radius: 9px;
                font-size: 16px;
            }

            .breadcrumb { font-size: 11px; }

            .top-right-actions { gap: 6px; }

            .profile-wrapper {
                padding-left: 7px;
                gap: 6px;
            }

            .profile-wrapper > span:last-of-type { display: none !important; }

            .top-avatar,
            .top-avatar-fallback {
                width: 30px;
                height: 30px;
            }

            .admin-tag {
                padding: 4px 8px;
                font-size: 8px;
                letter-spacing: .7px;
            }

            .container { padding: 18px 12px; }

            .page-header { gap: 14px; margin-bottom: 18px; }
            .page-header h1 { font-size: 21px; }
            .page-header p { font-size: 12px; }

            .status-grid {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }

            .status-card {
                padding: 14px;
                border-radius: 10px;
            }

            .status-card h4 {
                font-size: 9px;
                line-height: 1.35;
            }

            .status-card .count { font-size: 20px; }

            .table-controls { grid-template-columns: 1fr; }
            .table-controls > .fa-filter { display: none; }
            #showingCount { text-align: left; }

            .modal-overlay { padding: 10px; }

            .modal-content {
                padding: 18px 14px;
                max-height: calc(100dvh - 20px);
            }

            .modal-actions { gap: 10px; }

            .modal-actions button { min-height: 44px; }
        }

    </style>
</head>
<body>

    <aside>
        <div class="sidebar-header">
            <img src="bg.png" alt="Boogie's Logo" class="sidebar-logo">
            <h2>
                <?php 
                    echo (isset($_SESSION['role']) && strtolower($_SESSION['role']) === 'admin') ? "Boogie's Admin" : "Boogie's Staff"; 
                ?>
            </h2>
        </div>
        <nav class="nav-links">
            <a href="admindashboard.php" class="nav-item"><i class="fas fa-chart-line"></i> Dashboard</a>
            <a href="managebooking.php" class="nav-item active"><i class="fas fa-calendar-alt"></i> Bookings</a>
            <a href="manageusers.php" class="nav-item"><i class="fas fa-users"></i> Users</a>
            <a href="managepet.php" class="nav-item"><i class="fas fa-dog"></i> Pets</a>
            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                <a href="managestaff.php" class="nav-item"><i class="fas fa-id-badge"></i> Personnel</a>
                <a href="managepromo.php" class="nav-item"><i class="fas fa-tags"></i> Promos</a>
                <a href="manage_services.php" class="nav-item"><i class="fas fa-list-ul"></i> Pricelist</a>
                <a href="sales_report.php" class="nav-item">
                    <i class="fas fa-file-invoice-dollar"></i>
                    Sales Report
                </a>

                <!-- ACCOUNT LOGS: ADMIN ONLY -->
                <a href="admin_account_logs.php" class="nav-item">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                    Account Logs
                </a>
            <?php endif; ?>
        </nav>
    </aside>

    <div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeMobileSidebar()"></div>

    <main>
        <header class="top-bar">
            <div class="topbar-left">
                <button class="mobile-menu-toggle" type="button"
                        aria-label="Open admin menu"
                        aria-expanded="false"
                        onclick="toggleMobileSidebar(event)">
                    <i class="fas fa-bars"></i>
                </button>

                <div class="breadcrumb">
                    <i class="fas fa-calendar-alt" style="opacity: 0.5; font-size: 14px;"></i>
                    Management / Bookings
                </div>
            </div>

            <div class="top-right-actions">
                <div class="notif-wrapper" onclick="toggleNotif(event)">
                    <i class="fa-solid fa-bell" style="font-size: 22px; color: #64748b;"></i>
                    <?php if($unread_count > 0): ?>
                        <span id="admin-notif-badge" class="notif-badge"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                    <div class="notif-dropdown" id="notifBox" onclick="event.stopPropagation()">
                        <div class="notif-header">
                            Alerts
                            <?php if($unread_count > 0): ?>
                                <a href="mark_notifications_read.php" id="mark-read-link" class="mark-read-btn">Mark all read</a>
                            <?php endif; ?>
                        </div>
                        <div class="notif-body" id="admin-notif-list">
                            <?php if($unread_count > 0 && $admin_notif_query): ?>
                                <?php foreach ($admin_notifications as $notif): ?>
                                    <div class="notif-item">
                                        <i class="fa-solid fa-circle-exclamation" style="color: #e11d48; margin-right: 5px;"></i>
                                        <?php echo htmlspecialchars($notif['message']); ?>
                                        <br><small style="color: #94a3b8; font-size: 11px;"><?php echo date('M d, g:i A', strtotime($notif['created_at'])); ?></small>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="notif-empty">No new notifications.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="profile-wrapper" onclick="toggleProfile(event)">
                    <span class="admin-tag <?php echo ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'supervisor') ? 'staff' : ''; ?>">
                        <?php echo strtoupper($_SESSION['role'] ?? 'ADMIN'); ?>
                    </span>
                    <?php if (!empty($profile_img_path) && file_exists($profile_img_path)): ?>
                        <img src="<?php echo htmlspecialchars($profile_img_path); ?>" class="top-avatar" alt="Profile Picture">
                    <?php else: ?>
                        <div class="top-avatar-fallback"><?php echo strtoupper(substr($first_name, 0, 1)); ?></div>
                    <?php endif; ?>
                    <span style="font-size: 14px; font-weight: 600; color: #4a5568; display: flex; align-items: center; gap: 6px;">
                        <?php echo htmlspecialchars($admin_full_name); ?>
                        <i class="fas fa-chevron-down" style="font-size: 10px; color: #94a3b8;"></i>
                    </span>

                    <div class="profile-dropdown" id="profileBox" onclick="event.stopPropagation()">
                        <a href="admin_profile.php" class="profile-item">
                            <i class="fas fa-user-circle"></i> My Profile
                        </a>
                        <a href="../logout.php" class="profile-item logout-text">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <div class="container">
            <?php if (isset($_SESSION['alert_msg'])): ?>
                <div style="background-color: #dcfce7; color: #166534; padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; border-left: 5px solid #16a34a; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                    <i class="fas fa-check-circle" style="margin-right: 8px;"></i>
                    <?php 
                        echo $_SESSION['alert_msg']; 
                        unset($_SESSION['alert_msg']); 
                    ?>
                </div>
            <?php endif; ?>

            <div class="page-header">
                <div>
                    <h1>Manage Bookings</h1>
                    <p>View, verify payments, and manage all active online service bookings.</p>
                </div>
                <button class="btn-add-walkin" onclick="openWalkinModal()">
                    <i class="fas fa-plus-circle"></i> Add Walk-in
                </button>
            </div>

            <div class="status-grid">
                <div class="status-card total" onclick="window.location.href='managebooking.php?status=All'">
                    <h4>Total</h4><div class="count"><?php echo $total_count; ?></div>
                </div>
                <div class="status-card pending" onclick="window.location.href='managebooking.php?status=Pending'">
                    <h4>Pending</h4><div class="count"><?php echo $pending_count; ?></div>
                </div>
                <div class="status-card confirmed" onclick="window.location.href='managebooking.php?status=Confirmed'">
                    <h4>Confirmed</h4><div class="count"><?php echo $confirmed_count; ?></div>
                </div>
                <div class="status-card completed" onclick="window.location.href='managebooking.php?status=Completed'">
                    <h4>Completed</h4><div class="count"><?php echo $completed_count; ?></div>
                </div>
                <div class="status-card rescheduling" onclick="window.location.href='managebooking.php?status=For%20Rescheduling'">
                    <h4>For Rescheduling</h4><div class="count"><?php echo $reschedule_count; ?></div>
                </div>
                <div class="status-card rescheduled" onclick="window.location.href='managebooking.php?status=Rescheduled'">
                    <h4>Rescheduled</h4><div class="count"><?php echo $rescheduled_count; ?></div>
                </div>
                <div class="status-card cancelled" onclick="window.location.href='managebooking.php?status=Cancelled'">
                    <h4>Cancelled</h4><div class="count"><?php echo $cancelled_count; ?></div>
                </div>
            </div>

            <div class="table-container">
                <div class="table-controls">
                    <i class="fas fa-filter" style="color: #94a3b8;"></i>
                    
                    <select class="filter-select" id="statusFilter">
                        <option value="all">All Statuses Here</option>
                        <option value="pending">Pending Only</option>
                        <option value="confirmed">Confirmed Only</option>
                        <option value="for rescheduling">For Rescheduling Only</option>
                        <option value="rescheduled">Rescheduled Only</option>
                        <option value="completed">Completed Only</option>
                        <option value="cancelled">Cancelled/No-Show</option>
                    </select>

                    <select class="filter-select" id="serviceFilter" style="margin-left: 10px;">
                        <option value="all">All Services</option>
                        <option value="grooming">Grooming</option>
                        <option value="vet">Vet Services</option>
                        <option value="hotel">Pet Hotel</option>
                    </select>
                    
                    <span id="showingCount" style="font-size: 13px; color: #64748b; margin-left: auto; font-weight: 600;">
                        Showing <?php echo $total_rows_showing; ?> <?php echo htmlspecialchars($filter_status === 'Active' ? 'Active' : $filter_status); ?> bookings
                    </span>
                </div>

                <div class="table-wrapper">
                    <?php if($total_rows_showing > 0): ?>
                        <table class="booking-table" id="bookingTable">
                            <thead>
                                <tr>
                                    <th>Pet Name</th>
                                    <th>Service</th>
                                    <th>Date & Time</th>
                                    <th>Vet/Staff</th>
                                    <th>Payment Details</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bookings as $row): 
                                    $raw_status = $row['booking_status'] ?? '';
                                    $display_status = (empty($raw_status)) ? 'Pending' : $raw_status;
                                    
                                    $fee = isset($row['service_fee']) ? $row['service_fee'] : (isset($row['total_price']) ? $row['total_price'] : 0);
                                    $pay_method = !empty($row['payment_method']) ? $row['payment_method'] : 'N/A';
                                    $pay_status = !empty($row['payment_status']) ? $row['payment_status'] : 'Pending';
                                    
                                    $pay_bg = (strtoupper($pay_status) === 'PAID') ? '#dcfce7' : '#f1f5f9';
                                    $pay_color = (strtoupper($pay_status) === 'PAID') ? '#166534' : '#475569';

                                    $service_string = htmlspecialchars(strtolower($row['service'] ?? ''));
                                    
                                    $appt_type = $row['appointment_type'] ?? 'Online';
                                    
                                    // Identify if it's a Walk-in or Online
                                    $booking_type_label = (strpos($pay_method, 'Walk-in') !== false) ? 'WALK-IN' : 'ONLINE';
                                ?>
                                    <tr class="booking-row" data-status="<?php echo strtolower($display_status); ?>" data-service="<?php echo $service_string; ?>">
                                        <td>
                                            <strong style="color: var(--navy-dark); font-size: 15px;"><?php echo htmlspecialchars($row['pet_display_name'] ?? 'Unknown Pet'); ?></strong><br>
                                            <span style="background: #e0e7ff; color: #3730a3; padding: 3px 6px; border-radius: 4px; font-size: 9px; font-weight: 800; letter-spacing: 0.5px;"><?php echo $booking_type_label; ?></span>
                                        </td>
                                        
                                        <td>
                                            <span style="color: #475569; font-weight: 600; font-size: 13px;">
                                                <?php echo htmlspecialchars($row['service'] ?? 'N/A'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span style="font-weight: 600; color: var(--navy-dark);"><?php echo isset($row['appointment_date']) ? date('M d, Y', strtotime($row['appointment_date'])) : 'N/A'; ?></span><br>
                                            <small style="color: #64748b; font-weight: 600;"><i class="far fa-clock"></i> <?php echo htmlspecialchars($row['appointment_time'] ?? ''); ?></small>
                                        </td>
                                        <td style="font-size: 13px; font-weight: 500;"><?php echo htmlspecialchars($row['vet_doctor'] ?? 'Any Available'); ?></td>
                                        
                                        <td>
                                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                                <strong style="color: var(--navy-dark); font-size: 15px;">₱<?php echo number_format($fee, 2); ?></strong>
                                                <div style="display: flex; align-items: center; gap: 6px;">
                                                    <span style="font-size: 11px; color: #64748b; font-weight: bold;"><?php echo htmlspecialchars($pay_method); ?></span>
                                                    <span style="color: <?php echo $pay_color; ?>; background: <?php echo $pay_bg; ?>; font-weight: 700; padding: 2px 6px; border-radius: 4px; font-size: 10px; letter-spacing: 0.5px;">
                                                        <?php echo htmlspecialchars($pay_status); ?>
                                                    </span>
                                                </div>
                                                
                                                <?php if ($pay_method === 'GCash' && !empty($row['gcash_ref'])): ?>
                                                    <span style="font-size: 11px; color: #0284c7; font-weight: 700; margin-top: 2px;">
                                                        Ref: <?php echo htmlspecialchars($row['gcash_ref']); ?>
                                                    </span>
                                                    <?php if (!empty($row['gcash_receipt'])): ?>
                                                        <a href="../uploads/<?php echo htmlspecialchars($row['gcash_receipt']); ?>" target="_blank" style="font-size: 11px; color: var(--brand-blue); font-weight: 600; text-decoration: underline;">
                                                            <i class="fa-solid fa-receipt"></i> View Screenshot
                                                        </a>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>

                                        <td>
                                            <span class="status-pill status-<?php echo str_replace(' ', '-', $display_status); ?>">
                                                <?php echo $display_status; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-group">
                                                <?php if ($pay_status === 'Pending Verification' && $pay_method === 'GCash' && $display_status === 'Pending'): ?>
                                                    <a href="managebooking.php?action=verify_gcash&id=<?php echo $row['id']; ?>" class="btn-verify-gcash" title="Verify Payment and Confirm Slot" onclick="return confirm('Ensure you have checked the GCash receipt. Verify and confirm booking?');">
                                                        <i class="fa-solid fa-money-bill-wave"></i> Verify
                                                    </a>
                                                    <a href="managebooking.php?action=Cancelled&id=<?php echo $row['id']; ?>" class="btn-icon btn-icon-cancel" title="Reject & Cancel Booking" onclick="return confirm('Reject this booking?');">
                                                        <i class="fas fa-times"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <?php 
                                                    if (strtoupper($pay_status) !== 'PAID' && $display_status !== 'Cancelled' && $display_status !== 'No-Show'): 
                                                        if ($display_status === 'Completed'): ?>
                                                            <a href="managebooking.php?action=pay&id=<?php echo $row['id']; ?>" class="btn-icon btn-icon-pay-process" title="Process Payment" onclick="return confirm('Process receipt of payment for this completed service?');">
                                                                <i class="fas fa-file-invoice-dollar"></i>
                                                            </a>
                                                        <?php else: ?>
                                                            <a href="managebooking.php?action=pay&id=<?php echo $row['id']; ?>" class="btn-icon btn-icon-pay" title="Mark as Paid" onclick="return confirm('Mark this booking as Paid in advance?');">
                                                                <i class="fas fa-wallet"></i>
                                                            </a>
                                                        <?php endif; 
                                                    endif; ?>

                                                    <?php if($display_status == 'Pending'): ?>
                                                        <a href="managebooking.php?action=Confirmed&id=<?php echo $row['id']; ?>" class="btn-icon btn-icon-confirm" title="Confirm Booking & Mark as Paid" onclick="return confirm('Confirm booking and mark payment as Paid? (An SMS will be sent to the customer)');">
                                                            <i class="fas fa-check"></i>
                                                        </a>
                                                        <a href="managebooking.php?action=Cancelled&id=<?php echo $row['id']; ?>" class="btn-icon btn-icon-cancel" title="Cancel Booking" onclick="return confirm('Are you sure you want to cancel this booking?');">
                                                            <i class="fas fa-times"></i>
                                                        </a>
                                                    <?php elseif($display_status == 'Confirmed'): ?>
                                                        <a href="managebooking.php?action=Completed&id=<?php echo $row['id']; ?>" class="btn-icon btn-icon-confirm" title="Mark as Completed" onclick="return confirm('Mark as Completed? (This will also set payment to Paid automatically)');">
                                                            <i class="fas fa-check-double"></i>
                                                        </a>
                                                        <a href="managebooking.php?action=Cancelled&id=<?php echo $row['id']; ?>" class="btn-icon btn-icon-cancel" title="Cancel Booking" onclick="return confirm('Are you sure you want to cancel this Confirmed booking?');">
                                                            <i class="fas fa-times"></i>
                                                        </a>
                                                        <a href="managebooking.php?action=For-Rescheduling&id=<?php echo $row['id']; ?>" class="btn-icon btn-icon-reschedule" title="Allow One-Time Reschedule" onclick="return confirm('Mark this booking as For Rescheduling? The customer gets ONE reschedule opportunity within 3 days, and the original payment remains valid for the same service.');">
                                                            <i class="fas fa-calendar-days"></i>
                                                        </a>
                                                        <!-- First missed appointment uses the one-time reschedule path. -->

                                                    <?php elseif($display_status == 'Rescheduled'): ?>
                                                        <a href="managebooking.php?action=Completed&id=<?php echo $row['id']; ?>" class="btn-icon btn-icon-confirm" title="Mark as Completed" onclick="return confirm('Mark as Completed?');">
                                                            <i class="fas fa-check-double"></i>
                                                        </a>
                                                        <a href="managebooking.php?action=No-Show&id=<?php echo $row['id']; ?>" class="btn-icon btn-icon-noshow" title="Final No-Show After Reschedule" onclick="return confirm('Mark as No-Show? The customer already used the one reschedule. The payment will be forfeited and non-refundable.');">
                                                            <i class="fas fa-user-slash"></i>
                                                        </a>
                                                    <?php elseif($display_status == 'For Rescheduling'): ?>
                                                        <span style="font-size:12px;font-weight:700;color:#0ea5e9;">Waiting for customer reschedule</span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="far fa-calendar-times"></i>
                            <p>No <?php echo htmlspecialchars($filter_status === 'Active' ? 'active' : strtolower($filter_status)); ?> bookings found.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <footer>
            © <?php echo date("Y"); ?> BOOGIE'S PET CARE & SERVICES - DASMARIÑAS BRANCH
        </footer>
    </main>

    <div id="cancelModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <div class="warning-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                <div>
                    <h2>Cancel Appointment</h2>
                    <p>This action cannot be undone</p>
                </div>
            </div>
            
            <div class="booking-details-box">
                <p>Booking Details:</p>
                <h3 id="modalService">Grooming</h3>
                <div class="detail-row">Pet: <span id="modalPet" style="color: var(--text-muted);"></span></div>
                <div class="detail-row">Date: <span id="modalDate" style="color: var(--text-muted);"></span></div>
            </div>

            <form action="cancel_booking.php" method="POST">
                <input type="hidden" name="appointment_id" id="cancelAppointmentId">
                <div class="modal-form-group">
                    <label>Reason for Cancellation *</label>
                    <select name="cancel_reason" required>
                        <option value="">Select a reason</option>
                        <option value="Schedule Conflict">Schedule Conflict</option>
                        <option value="Pet is Unwell">Pet is Unwell</option>
                        <option value="Personal Emergency">Personal Emergency</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="important-warning">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div><strong>Important:</strong> Slot will be released for other customers. Cancellations made less than 24 hours before the appointment are strictly non-refundable.</div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-keep" onclick="closeCancelModal()">Keep Booking</button>
                    <button type="submit" class="btn-confirm-cancel">Cancel Appointment</button>
                </div>
            </form>
        </div>
    </div>

    <div id="walkinModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <div class="warning-icon" style="background:#dcfce7; color:#10b981;"><i class="fas fa-walking"></i></div>
                <div>
                    <h2>Add Walk-in Booking</h2>
                    <p>Encode a walk-in customer into the system.</p>
                </div>
            </div>

            <form method="POST" action="process_walkin.php" id="walkinForm">
                <input type="hidden" name="add_walkin" value="1">

                <div class="modal-form-group">
                    <label>Customer Name *</label>
                    <input type="text" name="customer_name" required placeholder="e.g. Juan Dela Cruz">
                </div>

                <div class="modal-form-group">
                    <label>Contact Number *</label>
                    <input type="tel" name="contact_number" required maxlength="13" pattern="09[0-9]{9}" placeholder="e.g. 09171234567">
                </div>

                <div class="modal-form-group">
                    <label>Pet Name *</label>
                    <input type="text" name="pet_name" required placeholder="e.g. Bantay">
                </div>

                <div class="modal-form-group">
                    <label>Pet Breed</label>
                    <input type="text" name="pet_breed" maxlength="100" placeholder="e.g. Shih Tzu">
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;">
                    <div class="modal-form-group">
                        <label>Pet Type *</label>
                        <select name="pet_type" id="walkinPetType" required onchange="updateWalkinServices()">
                            <option value="">Select Type...</option>
                            <option value="Dog">Dog</option>
                            <option value="Cat">Cat</option>
                        </select>
                    </div>
                    <div class="modal-form-group">
                        <label>Weight (kg) *</label>
                        <input type="number" name="pet_weight" id="walkinWeight" min="0.1" max="25" step="0.1" required placeholder="e.g. 7.5" oninput="updateWalkinPrice()">
                    </div>
                </div>

                <div class="modal-form-group">
                    <label>Pet Gender *</label>
                    <select name="pet_gender" id="walkinPetGender" required>
                        <option value="">Select Gender...</option>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                    </select>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;">
                    <div class="modal-form-group">
                        <label>Service Category *</label>
                        <select name="service_category" id="walkinCategory" required onchange="updateWalkinServices()">
                            <option value="">Select Category...</option>
                            <option value="Grooming">Grooming</option>
                            <option value="Vet Services">Vet Services</option>
                            <option value="Pet Hotel">Pet Hotel</option>
                        </select>
                    </div>
                    <div class="modal-form-group">
                        <label>Specific Service *</label>
                        <select name="specific_service" id="walkinService" required onchange="handleWalkinServiceChange()">
                            <option value="">Choose specific service</option>
                        </select>
                    </div>
                </div>

                <div class="modal-form-group" id="walkinHaircutContainer" style="display:none;">
                    <label>Desired Haircut Style *</label>
                    <select name="haircut_style" id="walkinHaircutStyle">
                        <option value="">Select Style...</option>
                        <option value="Puppy Cut">Puppy Cut</option>
                        <option value="Summer Cut">Summer Cut</option>
                        <option value="Shave Down">Shave Down</option>
                        <option value="Bear Cut">Bear Cut</option>
                        <option value="Poodle Cut">Poodle Cut</option>
                    </select>
                </div>

                <div id="walkinPriceBox" style="display:none;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:14px 16px;margin-bottom:15px;">
                    <div style="font-size:12px;color:#166534;font-weight:700;text-transform:uppercase;letter-spacing:.4px;">Calculated Price</div>
                    <div id="walkinPriceText" style="font-size:24px;color:#166534;font-weight:800;margin-top:2px;">₱0.00</div>
                    <div id="walkinPriceNote" style="font-size:11px;color:#64748b;margin-top:4px;"></div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;">
                    <div class="modal-form-group">
                        <label>Date *</label>
                        <input type="date" name="appointment_date" id="walkinDate" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="modal-form-group">
                        <label>Time *</label>
                        <input type="time" name="appointment_time" id="walkinTime" required>
                    </div>
                </div>

                <div class="modal-form-group">
                    <label>Service Amount (₱) *</label>
                    <input type="number" step="0.01" name="amount" id="walkinAmount" required readonly placeholder="0.00" style="font-weight:700;background:#f8fafc;">
                    <small style="display:block;margin-top:6px;color:#64748b;font-size:11px;">Payment can be collected later. Saving the walk-in will keep payment status as Pending.</small>
                </div>

                <div class="modal-actions" style="margin-top:20px;">
                    <button type="button" class="btn-keep" onclick="closeWalkinModal()">Cancel</button>
                    <button type="submit" style="background:#10b981;color:white;border:none;" class="btn-confirm-cancel" id="walkinSaveBtn" disabled>
                        Save Walk-in
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openCancelModal(id, service, pet, dateStr) {
            document.getElementById('cancelAppointmentId').value = id;
            document.getElementById('modalService').innerText = service;
            document.getElementById('modalPet').innerText = pet;
            document.getElementById('modalDate').innerText = dateStr;
            document.getElementById('cancelModal').style.display = 'flex';
        }
        
        function closeCancelModal() {
            document.getElementById('cancelModal').style.display = 'none';
        }

        const walkinPricingData = {
            "Dog": {
                "Grooming": {
                    "Basic Pet Grooming": { "Small (1-5kg)": 400, "Medium (6-10kg)": 500, "Large (11-15kg)": 650, "Extra Large (16-20kg)": 850, "XXL Large (21-25kg)": 1000 },
                    "Full Grooming Package": { "Small (1-5kg)": 450, "Medium (6-10kg)": 550, "Large (11-15kg)": 700, "Extra Large (16-20kg)": 900, "XXL Large (21-25kg)": 1100 },
                    "Bath & Blow Dry": { "Small (1-5kg)": 300, "Medium (6-10kg)": 350, "Large (11-15kg)": 550, "Extra Large (16-20kg)": 750, "XXL Large (21-25kg)": 950 }
                },
                "Vet Services": {
                    "Deworming": { "Small (1-5kg)": 200, "Medium (6-10kg)": 250, "Large (11-15kg)": 300, "Extra Large (16-20kg)": 350, "XXL Large (21-25kg)": 450 },
                    "Vaccination - Anti Rabies": { "default": 300 },
                    "Vaccination - 5 in 1": { "default": 450 },
                    "Vaccination - 6 in 1": { "default": 600 },
                    "Vaccination - 8 in 1": { "default": 750 }
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
                    "Vaccination - Anti Rabies": { "default": 300 },
                    "Vaccination - 4 in 1 (Cats)": { "default": 900 }
                },
                "Pet Hotel": {
                    "Pet Daycare (1st Hour - Succeeding fees apply)": { "default": 150 },
                    "Pet Boarding (Overnight)": { "default": 500 }
                }
            }
        };

        function getWalkinSizeFromWeight(weight) {
            const kg = Number(weight);
            if (!Number.isFinite(kg) || kg <= 0) return '';
            if (kg <= 5) return 'Small (1-5kg)';
            if (kg <= 10) return 'Medium (6-10kg)';
            if (kg <= 15) return 'Large (11-15kg)';
            if (kg <= 20) return 'Extra Large (16-20kg)';
            if (kg <= 25) return 'XXL Large (21-25kg)';
            return '';
        }

        function updateWalkinServices() {
            const petType = document.getElementById('walkinPetType').value;
            const category = document.getElementById('walkinCategory').value;
            const serviceSelect = document.getElementById('walkinService');
            const previous = serviceSelect.value;

            serviceSelect.innerHTML = '<option value="">Choose specific service</option>';

            if (petType && category && walkinPricingData[petType] && walkinPricingData[petType][category]) {
                Object.keys(walkinPricingData[petType][category]).forEach(function(service) {
                    const option = document.createElement('option');
                    option.value = service;
                    option.textContent = service;
                    serviceSelect.appendChild(option);
                });
                if (Object.prototype.hasOwnProperty.call(walkinPricingData[petType][category], previous)) {
                    serviceSelect.value = previous;
                }
            }

            handleWalkinServiceChange();
        }

        function handleWalkinServiceChange() {
            const service = document.getElementById('walkinService').value;
            const haircutContainer = document.getElementById('walkinHaircutContainer');
            const haircutSelect = document.getElementById('walkinHaircutStyle');

            if (service === 'Full Grooming Package') {
                haircutContainer.style.display = 'block';
                haircutSelect.required = true;
            } else {
                haircutContainer.style.display = 'none';
                haircutSelect.required = false;
                haircutSelect.value = '';
            }

            updateWalkinPrice();
        }

        function updateWalkinPrice() {
            const petType = document.getElementById('walkinPetType').value;
            const category = document.getElementById('walkinCategory').value;
            const service = document.getElementById('walkinService').value;
            const weight = document.getElementById('walkinWeight').value;
            const size = getWalkinSizeFromWeight(weight);
            const priceBox = document.getElementById('walkinPriceBox');
            const priceText = document.getElementById('walkinPriceText');
            const priceNote = document.getElementById('walkinPriceNote');
            const amountInput = document.getElementById('walkinAmount');
            const saveBtn = document.getElementById('walkinSaveBtn');
            const serviceData = walkinPricingData[petType]?.[category]?.[service];

            let price = 0;
            priceNote.textContent = '';

            if (serviceData) {
                if (serviceData.default !== undefined) {
                    price = Number(serviceData.default);
                    priceNote.textContent = 'Fixed service price.';
                } else if (size && serviceData[size] !== undefined) {
                    price = Number(serviceData[size]);
                    priceNote.textContent = 'Based on ' + Number(weight).toFixed(1).replace('.0','') + ' kg (' + size + ').';
                }

                if (service === 'Pet Daycare (1st Hour - Succeeding fees apply)') {
                    priceNote.textContent = 'Price shown is for the first hour; succeeding-hour fees apply based on clinic pricing.';
                }
            }

            const valid = price > 0 && petType && category && service && Number(weight) > 0;
            if (valid) {
                priceText.textContent = '₱' + price.toFixed(2);
                priceBox.style.display = 'block';
                amountInput.value = price.toFixed(2);
                saveBtn.disabled = false;
                saveBtn.style.opacity = '1';
                saveBtn.style.cursor = 'pointer';
            } else {
                priceBox.style.display = 'none';
                amountInput.value = '';
                saveBtn.disabled = true;
                saveBtn.style.opacity = '.55';
                saveBtn.style.cursor = 'not-allowed';
            }
        }

        function resetWalkinForm() {
            const form = document.getElementById('walkinForm');
            if (form) form.reset();
            document.getElementById('walkinService').innerHTML = '<option value="">Choose specific service</option>';
            document.getElementById('walkinHaircutContainer').style.display = 'none';
            document.getElementById('walkinHaircutStyle').required = false;
            document.getElementById('walkinPriceBox').style.display = 'none';
            document.getElementById('walkinAmount').value = '';
            document.getElementById('walkinSaveBtn').disabled = true;
            document.getElementById('walkinSaveBtn').style.opacity = '.55';
            document.getElementById('walkinSaveBtn').style.cursor = 'not-allowed';
            document.getElementById('walkinDate').value = '<?php echo date('Y-m-d'); ?>';
        }

        function openWalkinModal() {
            resetWalkinForm();
            document.getElementById('walkinModal').style.display = 'flex';
        }
        function closeWalkinModal() {
            document.getElementById('walkinModal').style.display = 'none';
        }

        function toggleNotif(event) {
            event.stopPropagation();
            document.getElementById("notifBox").classList.toggle("show");
            document.getElementById("profileBox").classList.remove("show"); 
        }

        function toggleProfile(event) {
            event.stopPropagation();
            document.getElementById("profileBox").classList.toggle("show");
            document.getElementById("notifBox").classList.remove("show"); 
        }

        window.onclick = function(event) {
            if (!event.target.closest('.notif-wrapper')) {
                const notifBox = document.getElementById("notifBox");
                if (notifBox && notifBox.classList.contains('show')) {
                    notifBox.classList.remove('show');
                }
            }
            if (!event.target.closest('.profile-wrapper')) {
                const profileBox = document.getElementById("profileBox");
                if (profileBox && profileBox.classList.contains('show')) {
                    profileBox.classList.remove('show');
                }
            }
            if (event.target === document.getElementById('cancelModal')) {
                closeCancelModal();
            }
            if (event.target === document.getElementById('walkinModal')) {
                closeWalkinModal();
            }
        }

        document.addEventListener("DOMContentLoaded", function() {
            const statusFilter = document.getElementById("statusFilter");
            const serviceFilter = document.getElementById("serviceFilter");
            const tableRows = document.querySelectorAll(".booking-row");
            const showingCount = document.getElementById("showingCount");
            const totalCount = <?php echo $total_rows_showing; ?>;
            const currentFilterStatus = '<?php echo htmlspecialchars($filter_status === 'Active' ? 'Active' : $filter_status); ?>';

            function applyFilters() {
                const selectedStatus = statusFilter ? statusFilter.value : "all";
                const selectedService = serviceFilter ? serviceFilter.value : "all";

                // Status is loaded server-side because a page initially showing one status
                // cannot client-filter rows that were never loaded into the page.
                if (statusFilter) {
                    const statusMap = {
                        all: 'All',
                        pending: 'Pending',
                        confirmed: 'Confirmed',
                        'for rescheduling': 'For Rescheduling',
                        rescheduled: 'Rescheduled',
                        completed: 'Completed',
                        cancelled: 'Cancelled'
                    };
                    const targetStatus = statusMap[selectedStatus] || 'All';
                    const currentStatus = <?php echo json_encode($filter_status); ?>;
                    if (targetStatus !== currentStatus) {
                        const url = new URL(window.location.href);
                        url.searchParams.set('status', targetStatus);
                        window.location.href = url.toString();
                        return;
                    }
                }

                let visibleCount = 0;
                tableRows.forEach(function(row) {
                    const rowStatus = (row.getAttribute("data-status") || "").toLowerCase();
                    const rowService = row.getAttribute("data-service") || "";
                    const matchesStatus = selectedStatus === "all" ||
                        rowStatus === selectedStatus ||
                        (selectedStatus === "cancelled" && rowStatus === "no-show");
                    const matchesService = selectedService === "all" || rowService.includes(selectedService);

                    row.style.display = (matchesStatus && matchesService) ? "" : "none";
                    if (matchesStatus && matchesService) visibleCount++;
                });

                if (showingCount) {
                    showingCount.textContent = `Showing ${visibleCount} of ${totalCount} ${currentFilterStatus} bookings`;
                }
            }

            // Set the dropdown to match the server-side filter.
            if (statusFilter) {
                const current = <?php echo json_encode($filter_status); ?>;
                const reverse = {
                    All: 'all',
                    Pending: 'pending',
                    Confirmed: 'confirmed',
                    'For Rescheduling': 'for rescheduling',
                    Rescheduled: 'rescheduled',
                    Completed: 'completed',
                    Cancelled: 'cancelled'
                };
                statusFilter.value = reverse[current] || 'all';
                statusFilter.addEventListener("change", applyFilters);
            }
            if (serviceFilter) serviceFilter.addEventListener("change", applyFilters);
            applyFilters();
        });

        function renderAdminNotifications(data) {
            const badge = document.getElementById('admin-notif-badge');
            const notifList = document.getElementById('admin-notif-list');
            const markReadBtn = document.getElementById('mark-read-link');

            if (!badge || !notifList) return;

            const unread = Number(data && data.unread ? data.unread : 0);

            badge.style.display = unread > 0 ? 'inline-block' : 'none';
            badge.textContent = unread;

            if (markReadBtn) {
                markReadBtn.style.display = unread > 0 ? 'inline-block' : 'none';
            }

            notifList.innerHTML = (data && data.html)
                ? data.html
                : '<div class="notif-empty">No new notifications.</div>';
        }

        function fetchAdminNotifs() {
            fetch('get_admin_notifs.php', {
                method: 'GET',
                cache: 'no-store',
                credentials: 'same-origin'
            })
                .then(function(response) {
                    if (!response.ok) {
                        throw new Error('Notification request failed: HTTP ' + response.status);
                    }
                    return response.json();
                })
                .then(renderAdminNotifications)
                .catch(function(error) {
                    console.error('Error fetching admin notifications:', error);
                });
        }


        // ===== MOBILE SIDEBAR =====
        function toggleMobileSidebar(event) {
            if (event) event.stopPropagation();

            const sidebar = document.querySelector('aside');
            const backdrop = document.getElementById('sidebarBackdrop');
            const button = document.querySelector('.mobile-menu-toggle');

            if (!sidebar) return;

            const open = sidebar.classList.toggle('mobile-open');

            if (backdrop) backdrop.classList.toggle('show', open);
            if (button) button.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        function closeMobileSidebar() {
            const sidebar = document.querySelector('aside');
            const backdrop = document.getElementById('sidebarBackdrop');
            const button = document.querySelector('.mobile-menu-toggle');

            if (sidebar) sidebar.classList.remove('mobile-open');
            if (backdrop) backdrop.classList.remove('show');
            if (button) button.setAttribute('aria-expanded', 'false');
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') closeMobileSidebar();
        });

        document.querySelectorAll('.nav-item').forEach(function(link) {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 900) closeMobileSidebar();
            });
        });

        window.fetchAdminNotifs = fetchAdminNotifs;

        fetchAdminNotifs();
        setInterval(fetchAdminNotifs, 3000);
    </script>
</body>
</html> 