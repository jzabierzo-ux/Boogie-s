<?php
session_start();
include '../db_connect.php';
require_once '../includes/iprog_sms.php'; 

// --- UNIVERSAL SECURITY CHECK ---
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';

if (!isset($_SESSION['logged_in']) || !in_array($current_role, ['admin', 'manager', 'vet'])) {
    header("Location: ../staff/stafflogin.php");
    exit();
}

// 2. FETCH ADMIN PROFILE
$admin_full_name = "User";
$profile_img_path = "";
$first_name = "User";

if (isset($_SESSION['user_id'])) {
    $uid = $_SESSION['user_id'];
    $get_admin = mysqli_query($conn, "SELECT full_name, profile_image FROM users WHERE id = '$uid'");
    if($admin_data = mysqli_fetch_assoc($get_admin)) {
        $admin_full_name = $admin_data['full_name'];
        $profile_img_path = $admin_data['profile_image']; 
        $_SESSION['user_name'] = $admin_full_name; 
        
        $first_name = explode(' ', $admin_full_name)[0];
        $first_name = trim($first_name, ',');
    }
}

// ==========================================
// BAGO: WALK-IN BOOKING LOGIC
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_walkin'])) {
    $c_name = mysqli_real_escape_string($conn, $_POST['customer_name']);
    $p_name = mysqli_real_escape_string($conn, $_POST['pet_name']);
    $service = mysqli_real_escape_string($conn, $_POST['service']);
    $date = mysqli_real_escape_string($conn, $_POST['appointment_date']);
    $time = mysqli_real_escape_string($conn, $_POST['appointment_time']);
    $amount = floatval($_POST['amount']);

    // 1. Gagawa ng mabilis na "dummy" account para sa walk-in
    $dummy_email = 'walkin_' . time() . '@boogies.local';
    $insert_user = "INSERT INTO users (full_name, email, password, role, is_verified) VALUES ('$c_name (Walk-in)', '$dummy_email', 'walkin123', 'user', 1)";
    mysqli_query($conn, $insert_user);
    $new_user_id = mysqli_insert_id($conn);

    // 2. I-save yung alagang hayop
    $insert_pet = "INSERT INTO pets (owner_id, name, pet_type) VALUES ('$new_user_id', '$p_name', 'Walk-in Pet')";
    mysqli_query($conn, $insert_pet);
    $new_pet_id = mysqli_insert_id($conn);

    // 3. I-save sa appointments (Auto-Confirmed at Paid Cash)
    $insert_appt = "INSERT INTO appointments (user_id, pet_id, service, appointment_date, appointment_time, service_fee, total_price, payment_method, payment_status, booking_status) 
                    VALUES ('$new_user_id', '$new_pet_id', '$service', '$date', '$time', '$amount', '$amount', 'Cash (Walk-in)', 'Paid', 'Completed')";
    mysqli_query($conn, $insert_appt);

    $_SESSION['alert_msg'] = "Walk-in booking successfully added and marked as completed!";
    header("Location: managebooking.php");
    exit();
}
// ==========================================

// --- LOGIC: UPDATE STATUS WITH NOTIFICATIONS, PAYMENT & SMS ---
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $new_status = mysqli_real_escape_string($conn, $_GET['action']);
    
    // --- MANUAL PAYMENT LOGIC ---
    if ($new_status === 'pay') {
        mysqli_query($conn, "UPDATE appointments SET payment_status = 'Paid' WHERE id = $id");
        $_SESSION['alert_msg'] = "Payment successfully marked as Paid.";
        header("Location: managebooking.php");
        exit();
    }

    // --- DB UPDATE LOGIC ---
    if ($new_status === 'verify_gcash') {
        mysqli_query($conn, "UPDATE appointments SET payment_status = 'Paid', booking_status = 'Confirmed' WHERE id = $id");
        $new_status = 'Confirmed'; 
        $_SESSION['alert_msg'] = "GCash Payment Verified and Booking Confirmed!";
    } else {
        if ($new_status === 'Completed' || $new_status === 'Confirmed') {
            mysqli_query($conn, "UPDATE appointments SET booking_status = '$new_status', payment_status = 'Paid' WHERE id = $id");
        } else {
            mysqli_query($conn, "UPDATE appointments SET booking_status = '$new_status' WHERE id = $id");
        }
    }
    
    // --- NOTIFICATION & SMS BUILDER ---
    $info_query = mysqli_query($conn, "
        SELECT 
            a.*,
            p.name AS pet_real_name,
            p.owner_id,
            u.full_name AS customer_name,
            u.contact_number
        FROM appointments a
        LEFT JOIN pets p ON a.pet_id = p.id
        LEFT JOIN users u ON a.user_id = u.id
        WHERE a.id = $id
        LIMIT 1
    ");

    if ($info_query && mysqli_num_rows($info_query) > 0) {
        $booking_info = mysqli_fetch_assoc($info_query);

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
            $msg = "Your booking for $pet ($service) was marked as No-Show. Payments are non-refundable.";
            $sms_msg = "Notice: Your booking for $pet ($service) was marked as NO-SHOW. Payments are non-refundable. - Boogie's Pet Care";
        }

        // INSERT IN-APP NOTIFICATION
        if (!empty($msg) && $u_id > 0) {
            $safe_title = mysqli_real_escape_string($conn, $title);
            $safe_msg = mysqli_real_escape_string($conn, $msg);
            $notif_query = "INSERT INTO notifications (user_id, title, message, type, is_read, created_at)
                            VALUES ($u_id, '$safe_title', '$safe_msg', 'booking', 0, NOW())";
            mysqli_query($conn, $notif_query);
        }

        // ==========================================
        // IPROG SMS — ONLY SMS PROVIDER
        // ==========================================
        if (!empty($sms_msg)) {
            // Use the phone number joined from the exact appointment's user_id.
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
                        ' | Phone: ' . $sms_result['phone_number'] .
                        ($message_id !== '' ? ' | Message ID: ' . $message_id : '')
                    );
                } else {
                    $safe_error = trim((string)($sms_result['response'] ?? 'Unknown IPROG error'));
                    $_SESSION['alert_msg'] = "Booking updated, but SMS failed (HTTP " .
                        (int)($sms_result['http_code'] ?? 0) . ": " .
                        htmlspecialchars($safe_error) . ").";

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

    if (!isset($_SESSION['alert_msg'])) {
        $_SESSION['alert_msg'] = "Status updated to " . htmlspecialchars($new_status) . " successfully.";
    }
    header("Location: managebooking.php");
    exit();
}

// --- FETCH ADMIN NOTIFICATIONS ---
$admin_notif_query = mysqli_query($conn, "SELECT * FROM admin_notifications WHERE is_read = 0 ORDER BY created_at DESC");
$unread_count = $admin_notif_query ? mysqli_num_rows($admin_notif_query) : 0;

// --- DYNAMIC COUNTS ---
$total_res = mysqli_query($conn, "SELECT COUNT(*) as count FROM appointments");
$total_count = $total_res ? mysqli_fetch_assoc($total_res)['count'] : 0;

$pending_res = mysqli_query($conn, "SELECT COUNT(*) as count FROM appointments WHERE booking_status = 'Pending' OR booking_status IS NULL OR booking_status = ''");
$pending_count = $pending_res ? mysqli_fetch_assoc($pending_res)['count'] : 0;

$confirmed_res = mysqli_query($conn, "SELECT COUNT(*) as count FROM appointments WHERE booking_status = 'Confirmed'");
$confirmed_count = $confirmed_res ? mysqli_fetch_assoc($confirmed_res)['count'] : 0;

$completed_res = mysqli_query($conn, "SELECT COUNT(*) as count FROM appointments WHERE booking_status = 'Completed'");
$completed_count = $completed_res ? mysqli_fetch_assoc($completed_res)['count'] : 0;

$cancelled_res = mysqli_query($conn, "SELECT COUNT(*) as count FROM appointments WHERE booking_status = 'Cancelled' OR booking_status = 'No-Show'");
$cancelled_count = $cancelled_res ? mysqli_fetch_assoc($cancelled_res)['count'] : 0;

// --- DETERMINE FILTER STATUS FROM URL ---
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'Active';

$where_clause = "";
if ($filter_status === 'Active') {
    $where_clause = "WHERE a.booking_status NOT IN ('Completed', 'Cancelled', 'No-Show') OR a.booking_status IS NULL";
} elseif ($filter_status === 'Pending') {   
    $where_clause = "WHERE a.booking_status = 'Pending' OR a.booking_status IS NULL OR a.booking_status = ''";
} elseif ($filter_status === 'All') {
    $where_clause = ""; 
} elseif ($filter_status === 'Cancelled') {
    $where_clause = "WHERE a.booking_status IN ('Cancelled', 'No-Show')";
} else {
    $safe_status = mysqli_real_escape_string($conn, $filter_status);
    $where_clause = "WHERE a.booking_status = '$safe_status'";
}

// --- FETCH BOOKINGS ---
$bookings_result = mysqli_query($conn, "SELECT a.*, p.name as pet_display_name 
                                        FROM appointments a 
                                        LEFT JOIN pets p ON a.pet_id = p.id 
                                        $where_clause
                                        ORDER BY 
                                            CASE WHEN a.appointment_date >= CURDATE() THEN 0 ELSE 1 END,
                                            CASE WHEN a.appointment_date >= CURDATE() THEN a.appointment_date END ASC,
                                            CASE WHEN a.appointment_date < CURDATE() THEN a.appointment_date END DESC,
                                            a.appointment_time ASC");
$total_rows_showing = $bookings_result ? mysqli_num_rows($bookings_result) : 0;

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
        
        .btn-verify-gcash { background: #10b981; color: white; padding: 6px 12px; border-radius: 6px; text-decoration: none; font-size: 12px; font-weight: bold; display: inline-flex; align-items: center; gap: 5px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); transition: 0.2s; }
        .btn-verify-gcash:hover { background: #059669; transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.15); }

        .status-pill { padding: 6px 14px; border-radius: 6px; font-size: 11px; font-weight: 800; letter-spacing: 0.5px; box-shadow: inset 0 0 0 1px rgba(0,0,0,0.05); text-transform: uppercase; }
        .status-Pending { background: #fef3c7; color: #92400e; }
        .status-Confirmed { background: #dbeafe; color: #1e40af; }
        .status-Completed { background: #dcfce7; color: #166534; }
        .status-Cancelled { background: #fee2e2; color: #991b1b; }
        .status-No-Show { background: #ffedd5; color: #ea580c; border: 1px solid #fdba74;}

        .empty-state { text-align: center; padding: 80px 0; color: #94a3b8; }
        .empty-state i { font-size: 50px; margin-bottom: 15px; opacity: 0.3; }

        /* Modal Styles */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 31, 63, 0.6); display: none; align-items: center; justify-content: center; z-index: 2000; padding: 20px; }
        .modal-content { background: var(--white); width: 100%; max-width: 450px; border-radius: 16px; padding: 30px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
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

    <main>
        <header class="top-bar">
            <div class="breadcrumb">
                <i class="fas fa-calendar-alt" style="opacity: 0.5; font-size: 14px;"></i> 
                Management / Bookings
            </div>
            
            <div class="top-right-actions">
                <div class="notif-wrapper" onclick="toggleNotif(event)">
                    <i class="fa-solid fa-bell" style="font-size: 22px; color: #64748b;"></i>
                    <?php if($unread_count > 0): ?>
                        <span class="notif-badge"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                    <div class="notif-dropdown" id="notifBox" onclick="event.stopPropagation()">
                        <div class="notif-header">
                            Alerts
                            <?php if($unread_count > 0): ?>
                                <a href="mark_notifications_read.php" class="mark-read-btn">Mark all read</a>
                            <?php endif; ?>
                        </div>
                        <div class="notif-body">
                            <?php if($unread_count > 0 && $admin_notif_query): ?>
                                <?php while($notif = mysqli_fetch_assoc($admin_notif_query)): ?>
                                    <div class="notif-item">
                                        <i class="fa-solid fa-circle-exclamation" style="color: #e11d48; margin-right: 5px;"></i>
                                        <?php echo htmlspecialchars($notif['message']); ?>
                                        <br><small style="color: #94a3b8; font-size: 11px;"><?php echo date('M d, g:i A', strtotime($notif['created_at'])); ?></small>
                                    </div>
                                <?php endwhile; ?>
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
                                <?php while($row = mysqli_fetch_assoc($bookings_result)): 
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
                                                        <a href="managebooking.php?action=No-Show&id=<?php echo $row['id']; ?>" class="btn-icon btn-icon-noshow" title="Mark as No-Show" onclick="return confirm('Mark as No-Show? The payment will be forfeited.');">
                                                            <i class="fas fa-user-slash"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
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
            <form method="POST" action="">
                <input type="hidden" name="add_walkin" value="1">
                
                <div class="modal-form-group">
                    <label>Customer Name *</label>
                    <input type="text" name="customer_name" required placeholder="e.g. Juan Dela Cruz">
                </div>
                <div class="modal-form-group">
                    <label>Pet Name *</label>
                    <input type="text" name="pet_name" required placeholder="e.g. Bantay">
                </div>
                <div class="modal-form-group">
                    <label>Service / Category *</label>
                    <select name="service" required>
                        <option value="">Select Service...</option>
                        <optgroup label="Grooming">
                            <option value="Grooming - Basic Pet Grooming">Basic Pet Grooming</option>
                            <option value="Grooming - Full Grooming Package">Full Grooming Package</option>
                        </optgroup>
                        <optgroup label="Vet Services">
                            <option value="Vet Services - Deworming">Deworming</option>
                            <option value="Vet Services - Vaccination">Vaccination</option>
                        </optgroup>
                        <optgroup label="Pet Hotel">
                            <option value="Pet Hotel - Pet Daycare">Pet Daycare</option>
                            <option value="Pet Hotel - Pet Boarding">Pet Boarding</option>
                        </optgroup>
                    </select>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="modal-form-group">
                        <label>Date *</label>
                        <input type="date" name="appointment_date" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="modal-form-group">
                        <label>Time *</label>
                        <input type="time" name="appointment_time" required>
                    </div>
                </div>
                <div class="modal-form-group">
                    <label>Amount Paid (₱) - Cash *</label>
                    <input type="number" step="0.01" name="amount" required placeholder="0.00">
                </div>
                <div class="modal-actions" style="margin-top: 20px;">
                    <button type="button" class="btn-keep" onclick="closeWalkinModal()">Cancel</button>
                    <button type="submit" style="background:#10b981; color:white; border:none;" class="btn-confirm-cancel">Save Walk-in</button>
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

        function openWalkinModal() {
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
                let visibleCount = 0;

                tableRows.forEach(function(row) {
                    const rowStatus = row.getAttribute("data-status");
                    const rowService = row.getAttribute("data-service") || "";

                    const matchesStatus = (selectedStatus === "all" || rowStatus === selectedStatus);
                    const matchesService = (selectedService === "all" || rowService.includes(selectedService));

                    if (matchesStatus && matchesService) {
                        row.style.display = ""; 
                        visibleCount++;
                    } else {
                        row.style.display = "none";
                    }
                });

                if (showingCount) {
                    showingCount.textContent = `Showing ${visibleCount} of ${totalCount} ${currentFilterStatus} bookings`;
                }
            }

            if (statusFilter) statusFilter.addEventListener("change", applyFilters);
            if (serviceFilter) serviceFilter.addEventListener("change", applyFilters);
        });

        let previousUnreadCount = <?php echo $unread_count; ?>;

        function updateNotifications() {
            fetch('get_unread_notifs.php')
                .then(response => response.json())
                .then(data => {
                    const badge = document.querySelector('.notif-badge'); 
                    
                    if (data.unread > previousUnreadCount) {
                    }
                    
                    previousUnreadCount = data.unread;
                    
                    if (badge) {
                        if (data.unread > 0) {
                            badge.style.display = 'inline-block';
                            badge.innerText = data.unread;
                        } else {
                            badge.style.display = 'none';
                        }
                    }
                })
                .catch(error => console.error('Error fetching notifications:', error));
        }

        setInterval(updateNotifications, 3000);
    </script>
</body>
</html> 