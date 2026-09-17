<?php
session_start();
include 'db_connect.php';

// Access Control
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

$full_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : (isset($_SESSION['full_name']) ? $_SESSION['full_name'] : 'User');
$user_id = $_SESSION['user_id'];

// --- UPDATED: Kinuha ang profile_image (Tinanggal na ang no_show_count/strikes) ---
$user_query = mysqli_query($conn, "SELECT profile_image FROM users WHERE id = '$user_id'");
$user_data = mysqli_fetch_assoc($user_query);
$profile_image = isset($user_data['profile_image']) ? $user_data['profile_image'] : null;

// Use the same profile-image variable expected by the existing header markup.
$profile_pic = $profile_image ?: ($_SESSION['profile_image'] ?? '');

$notif_header_query = "SELECT COUNT(*) as unread FROM notifications WHERE user_id = ? AND is_read = 0";
$stmt_notif = $conn->prepare($notif_header_query);
$stmt_notif->bind_param("i", $user_id);
$stmt_notif->execute();
$notif_result = $stmt_notif->get_result(); 
$unread_count = ($notif_result && $notif_result->num_rows > 0) ? $notif_result->fetch_assoc()['unread'] : 0;

$notifications = [];
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

$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$status_filter = isset($_GET['status']) ? mysqli_real_escape_string($conn, $_GET['status']) : 'all';

$query = "SELECT a.*, p.name AS joined_pet_name, r.id AS review_id 
          FROM appointments a
          LEFT JOIN pets p ON a.pet_id = p.id
          LEFT JOIN reviews r ON a.id = r.appointment_id
          WHERE a.user_id = ?";

if (!empty($search)) {
    $query .= " AND (p.name LIKE '%$search%' OR a.service LIKE '%$search%')";
}

if ($status_filter !== 'all') {
    $query .= " AND a.booking_status = '$status_filter'";
}

$query .= " ORDER BY a.appointment_date DESC, a.appointment_time DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$bookings = [];
if ($result) {
    while ($booking_row = $result->fetch_assoc()) {
        $bookings[] = $booking_row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Schedule | Boogie's Pet Care Services</title>
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


        /* ===== MY SCHEDULE PAGE ===== */
        main {
            width: min(1120px, 92%);
            margin: 0 auto;
            padding: 42px 0 78px;
        }

        .schedule-intro {
            margin-bottom: 25px;
        }

        .back-nav {
            margin-bottom: 14px;
        }

        .back-nav a {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: #748396;
            text-decoration: none;
            font-size: 11px;
            font-weight: 700;
        }

        .back-nav a:hover {
            color: var(--brand-blue);
        }

        .schedule-title-row {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 25px;
        }

        .schedule-kicker {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 12px;
            border-radius: 999px;
            background: var(--brand-yellow-soft);
            border: 1px solid #ffe594;
            color: #8c6800;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .7px;
            font-weight: 800;
            margin-bottom: 10px;
        }

        .schedule-title h1 {
            color: var(--brand-blue);
            font-size: 34px;
            line-height: 1.18;
            font-weight: 800;
            letter-spacing: -.6px;
        }

        .schedule-title p {
            color: var(--muted);
            font-size: 13px;
            margin-top: 6px;
        }

        .book-new-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 17px;
            border-radius: 11px;
            background: var(--brand-blue);
            color: var(--brand-yellow);
            text-decoration: none;
            font-size: 11px;
            font-weight: 800;
            box-shadow: 0 8px 20px rgba(0,31,63,.10);
        }

        .book-new-btn:hover {
            background: var(--brand-blue-2);
        }

        .filter-card {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 14px;
            box-shadow: 0 7px 23px rgba(0,31,63,.04);
            margin-bottom: 23px;
        }

        .filter-row {
            display: grid;
            grid-template-columns: minmax(0,1fr) 190px auto;
            gap: 9px;
            align-items: center;
        }

        .filter-search {
            position: relative;
        }

        .filter-search i {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: #98a5b4;
            font-size: 12px;
        }

        .filter-search input,
        .filter-select {
            width: 100%;
            height: 42px;
            border: 1px solid #dce5ee;
            border-radius: 10px;
            background: #fbfcfe;
            color: var(--text);
            font-family: inherit;
            font-size: 11px;
            outline: none;
        }

        .filter-search input {
            padding: 0 12px 0 37px;
        }

        .filter-search input:focus,
        .filter-select:focus {
            border-color: #9bb6cc;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(0,31,63,.05);
        }

        .filter-select {
            padding: 0 12px;
        }

        .filter-btn {
            height: 42px;
            padding: 0 16px;
            border: 0;
            border-radius: 10px;
            background: var(--brand-blue);
            color: var(--brand-yellow);
            font-family: inherit;
            font-size: 10px;
            font-weight: 800;
            cursor: pointer;
        }

        .filter-btn:hover {
            background: var(--brand-blue-2);
        }

        .section-card {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 20px;
            box-shadow: var(--shadow);
            padding: 24px;
            margin-bottom: 22px;
        }

        .section-head {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 18px;
        }

        .section-head .kicker {
            color: #8b99a9;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 1.1px;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .section-head h2 {
            color: var(--brand-blue);
            font-size: 20px;
            line-height: 1.2;
            font-weight: 800;
        }

        .section-head p {
            color: var(--muted);
            font-size: 10px;
            margin-top: 5px;
        }

        .section-count {
            color: #8b99a9;
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .schedule-list {
            display: grid;
            gap: 11px;
        }

        .schedule-item {
            display: grid;
            grid-template-columns: 88px minmax(0,1fr) auto;
            gap: 17px;
            align-items: center;
            padding: 15px;
            border: 1px solid var(--line);
            border-radius: 15px;
            background: #fbfcfe;
        }

        .schedule-item:hover {
            border-color: #d5e0e9;
            box-shadow: 0 6px 18px rgba(0,31,63,.045);
        }

        .date-box {
            border-radius: 12px;
            overflow: hidden;
            background: #fff;
            border: 1px solid #dfe7ee;
            text-align: center;
        }

        .date-box .date-month {
            padding: 5px 7px;
            background: var(--brand-blue);
            color: var(--brand-yellow);
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .date-box .date-day {
            padding: 7px 5px 2px;
            color: var(--brand-blue);
            font-size: 24px;
            line-height: 1;
            font-weight: 800;
        }

        .date-box .date-week {
            padding: 1px 5px 8px;
            color: #8996a4;
            font-size: 8px;
            font-weight: 700;
        }

        .schedule-main {
            min-width: 0;
        }

        .schedule-service {
            color: var(--brand-blue);
            font-size: 15px;
            line-height: 1.3;
            font-weight: 800;
        }

        .schedule-details {
            display: flex;
            flex-wrap: wrap;
            gap: 11px;
            margin-top: 6px;
        }

        .schedule-detail {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            color: var(--muted);
            font-size: 9px;
            font-weight: 600;
        }

        .schedule-detail i {
            color: #7f96a8;
            font-size: 10px;
        }

        .schedule-note {
            color: #8a98a7;
            font-size: 9px;
            margin-top: 7px;
        }

        .schedule-status {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 7px;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 8px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .35px;
            white-space: nowrap;
        }

        .status-PENDING {
            background: #fff7df;
            color: #a66a00;
        }

        .status-PAID,
        .status-APPROVED,
        .status-CONFIRMED {
            background: #e7f8ef;
            color: #1e7e49;
        }

        .status-COMPLETED {
            background: #e9f6ff;
            color: #0d73a6;
        }

        .status-CANCELLED {
            background: #fee7e9;
            color: #a92734;
        }

        .status-NO-SHOW {
            background: #384452;
            color: #fff;
        }

        .payment-status {
            color: #7a8998;
            font-size: 8px;
            font-weight: 700;
            text-align: right;
        }

        .schedule-action {
            display: flex;
            gap: 7px;
            align-items: center;
        }

        .btn-cancel-schedule,
        .btn-review-schedule {
            min-width: 94px;
            border-radius: 9px;
            padding: 8px 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            font-family: inherit;
            font-size: 9px;
            font-weight: 800;
            text-decoration: none;
            cursor: pointer;
        }

        .btn-cancel-schedule {
            border: 1px solid #e99aa1;
            background: #fff;
            color: var(--danger);
        }

        .btn-cancel-schedule:hover {
            background: #fff0f1;
        }

        .btn-review-schedule {
            background: var(--brand-yellow);
            border: 1px solid #e2bb00;
            color: var(--brand-blue);
        }

        .btn-review-schedule:hover {
            background: #f3bf00;
        }

        .feedback-schedule {
            color: #238052;
            font-size: 9px;
            font-weight: 800;
            white-space: nowrap;
        }

        .schedule-empty {
            text-align: center;
            padding: 52px 18px;
        }

        .schedule-empty-icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            margin: 0 auto 13px;
            background: var(--brand-yellow-soft);
            color: #b18400;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 25px;
        }

        .schedule-empty h3 {
            color: var(--brand-blue);
            font-size: 18px;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .schedule-empty p {
            max-width: 470px;
            margin: 0 auto 17px;
            color: var(--muted);
            font-size: 10px;
            line-height: 1.7;
        }

        .schedule-info {
            display: grid;
            grid-template-columns: repeat(3,1fr);
            gap: 12px;
        }

        .info-mini {
            padding: 14px;
            border-radius: 13px;
            border: 1px solid var(--line);
            background: #fbfcfe;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-mini-icon {
            width: 38px;
            height: 38px;
            flex: 0 0 38px;
            border-radius: 11px;
            background: var(--brand-yellow-soft);
            color: var(--brand-blue);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .info-mini strong {
            display: block;
            color: var(--brand-blue);
            font-size: 10px;
            font-weight: 800;
        }

        .info-mini span {
            display: block;
            color: var(--muted);
            font-size: 8px;
            line-height: 1.5;
            margin-top: 2px;
        }

        @media (max-width: 980px) {
            .schedule-item {
                grid-template-columns: 82px 1fr;
            }

            .schedule-status {
                grid-column: 2;
                align-items: flex-start;
            }

            .schedule-info {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 720px) {
            main {
                width: 92%;
                padding-top: 30px;
            }

            .schedule-title-row {
                display: block;
            }

            .book-new-btn {
                width: 100%;
                margin-top: 15px;
                justify-content: center;
            }

            .filter-row {
                grid-template-columns: 1fr;
            }

            .filter-btn {
                width: 100%;
            }

            .section-card {
                padding: 18px;
            }

            .schedule-item {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .date-box {
                width: 82px;
            }

            .schedule-status {
                grid-column: auto;
            }

            .schedule-action {
                flex-wrap: wrap;
            }
        }


        /* ===== CANCEL MODAL ===== */
        .modal-overlay {
            position: fixed;
            inset: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,31,63,.58);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 20px;
        }

        .modal-content {
            width: 100%;
            max-width: 450px;
            background: #fff;
            border-radius: 18px;
            padding: 26px;
            box-shadow: 0 24px 55px rgba(0,0,0,.20);
        }

        .modal-header {
            display: flex;
            gap: 13px;
            align-items: flex-start;
            margin-bottom: 18px;
        }

        .warning-icon {
            width: 42px;
            height: 42px;
            flex: 0 0 42px;
            border-radius: 12px;
            background: #fff0f1;
            color: #dc3b45;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
        }

        .modal-header h2 {
            color: var(--brand-blue);
            font-size: 18px;
            line-height: 1.2;
            font-weight: 800;
            margin: 0 0 3px;
        }

        .modal-header p {
            color: var(--muted);
            font-size: 10px;
            margin: 0;
        }

        .booking-details-box {
            background: #f8fafc;
            border: 1px solid var(--line);
            border-radius: 13px;
            padding: 15px;
            margin-bottom: 18px;
        }

        .booking-details-box > p {
            color: #8b99a9;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: .7px;
            font-weight: 800;
            margin-bottom: 4px;
        }

        .booking-details-box h3 {
            color: var(--brand-blue);
            font-size: 16px;
            font-weight: 800;
            margin-bottom: 6px;
        }

        .detail-row {
            color: #53677b;
            font-size: 11px;
            margin-top: 3px;
        }

        .modal-form-group {
            margin-bottom: 17px;
        }

        .modal-form-group label {
            display: block;
            color: var(--text);
            font-size: 11px;
            font-weight: 800;
            margin-bottom: 7px;
        }

        .modal-form-group select {
            width: 100%;
            height: 42px;
            padding: 0 12px;
            border: 1px solid #dce5ed;
            border-radius: 10px;
            background: #fbfcfe;
            color: var(--text);
            font-family: inherit;
            font-size: 11px;
            outline: none;
        }

        .important-warning {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            padding: 12px;
            margin-bottom: 19px;
            border-radius: 11px;
            background: #fff7df;
            border: 1px solid #f3df99;
            color: #8c6207;
            font-size: 10px;
            line-height: 1.6;
        }

        .important-warning i {
            margin-top: 2px;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
        }

        .modal-actions button {
            flex: 1;
            min-height: 42px;
            border-radius: 10px;
            font-family: inherit;
            font-size: 11px;
            font-weight: 800;
            cursor: pointer;
        }

        .btn-keep {
            background: #fff;
            color: var(--text);
            border: 1px solid #cbd5e1;
        }

        .btn-confirm-cancel {
            background: #dc3b45;
            color: #fff;
            border: 0;
        }

        /* ===== FOOTER ===== */
        footer {
            width: 100%;
            background: var(--brand-blue);
            padding: 62px 28px 30px;
            color: #fff;
            border-top: 4px solid var(--brand-yellow);
            margin-top: 0;
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
            margin: 0 0 15px;
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
            margin: 0 0 8px;
        }

        .footer-main a:hover {
            color: #fff;
        }

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

        .footer-bottom a {
            color: #cbd5e1;
            text-decoration: none;
            font-weight: 700;
        }

        @media (max-width: 1000px) {
            .footer-main {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 680px) {
            .footer-main {
                grid-template-columns: 1fr;
                gap: 25px;
            }

            .modal-content {
                padding: 22px;
            }

            .modal-actions {
                flex-direction: column;
            }
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
        <div class="schedule-intro">
            <div class="back-nav">
                <a href="dashboard.php">
                    <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>

            <div class="schedule-title-row">
                <div class="schedule-title">
                    <div class="schedule-kicker">
                        <i class="fa-solid fa-calendar-days"></i>
                        Your appointment schedule
                    </div>

                    <h1>My Schedule</h1>
                    <p>See when you are expected to visit Boogie's with your pet.</p>
                </div>

                <a href="book_appointment.php" class="book-new-btn">
                    <i class="fa-solid fa-plus"></i>
                    Book New Appointment
                </a>
            </div>
        </div>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'success'): ?>
            <div class="alert alert-success">
                <i class="fa-solid fa-circle-check"></i>
                Appointment successfully booked! We look forward to seeing your pet.
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-error">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>
                    <?php
                        if ($_GET['error'] == 'late_cancellation') {
                            echo "Failed to cancel: You can only cancel an appointment at least 24 hours before your scheduled time. Payments for late cancellations or no-shows are strictly non-refundable.";
                        } elseif ($_GET['error'] == 'past_date') {
                            echo "Failed to cancel: Cannot cancel a past appointment.";
                        } else {
                            echo "Failed to cancel booking. Please contact support.";
                        }
                    ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="filter-card">
            <form method="GET" action="">
                <div class="filter-row">
                    <div class="filter-search">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input
                            type="text"
                            name="search"
                            placeholder="Search your schedule by pet or service..."
                            value="<?php echo htmlspecialchars($search); ?>"
                        >
                    </div>

                    <select name="status" class="filter-select">
                        <option value="all" <?php echo ($status_filter == 'all') ? 'selected' : ''; ?>>All Status</option>
                        <option value="Pending" <?php echo ($status_filter == 'Pending') ? 'selected' : ''; ?>>Pending</option>
                        <option value="Confirmed" <?php echo ($status_filter == 'Confirmed') ? 'selected' : ''; ?>>Confirmed</option>
                        <option value="Completed" <?php echo ($status_filter == 'Completed') ? 'selected' : ''; ?>>Completed</option>
                        <option value="Cancelled" <?php echo ($status_filter == 'Cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                        <option value="No-Show" <?php echo ($status_filter == 'No-Show') ? 'selected' : ''; ?>>No-Show</option>
                    </select>

                    <button type="submit" class="filter-btn">
                        <i class="fa-solid fa-filter"></i> Apply
                    </button>
                </div>
            </form>
        </div>

        <?php
            $today = date('Y-m-d');
            $upcoming = [];
            $past = [];

            foreach ($bookings as $booking) {
                $booking_date = date('Y-m-d', strtotime($booking['appointment_date']));
                $status = strtoupper($booking['booking_status'] ?? 'PENDING');

                if ($booking_date >= $today && !in_array($status, ['CANCELLED', 'COMPLETED', 'NO-SHOW'], true)) {
                    $upcoming[] = $booking;
                } else {
                    $past[] = $booking;
                }
            }

            usort($upcoming, function($a, $b) {
                $aKey = ($a['appointment_date'] ?? '') . ' ' . ($a['appointment_time'] ?? '');
                $bKey = ($b['appointment_date'] ?? '') . ' ' . ($b['appointment_time'] ?? '');
                return strcmp($aKey, $bKey);
            });

            usort($past, function($a, $b) {
                $aKey = ($a['appointment_date'] ?? '') . ' ' . ($a['appointment_time'] ?? '');
                $bKey = ($b['appointment_date'] ?? '') . ' ' . ($b['appointment_time'] ?? '');
                return strcmp($bKey, $aKey);
            });
        ?>

        <section class="section-card">
            <div class="section-head">
                <div>
                    <div class="kicker">Next visits</div>
                    <h2>Upcoming Schedule</h2>
                    <p>Your next confirmed or pending appointments.</p>
                </div>

                <div class="section-count">
                    <?php echo count($upcoming); ?> upcoming
                </div>
            </div>

            <?php if (!empty($upcoming)): ?>
                <div class="schedule-list">
                    <?php foreach ($upcoming as $row): ?>
                        <?php
                            $apt_id = isset($row['id']) ? (int)$row['id'] : 0;
                            $display_pet = !empty($row['joined_pet_name'])
                                ? $row['joined_pet_name']
                                : (!empty($row['pet_name']) ? $row['pet_name'] : 'Unknown Pet');

                            $current_status = empty($row['booking_status'])
                                ? 'Pending'
                                : $row['booking_status'];

                            $status_upper = strtoupper($current_status);

                            $pay_status = $row['payment_status'] ?? 'Pending';
                            $pay_method = $row['payment_method'] ?? 'N/A';
                            $payment_upper = strtoupper($pay_status);

                            $payment_class = (
                                $payment_upper === 'PAID' ||
                                $payment_upper === 'PENDING VERIFICATION'
                            ) ? 'status-APPROVED' : 'status-PENDING';

                            $fee = isset($row['total_price'])
                                ? floatval($row['total_price'])
                                : (isset($row['service_fee']) ? floatval($row['service_fee']) : 0);

                            $time_str = !empty($row['appointment_time'])
                                ? date('h:i A', strtotime($row['appointment_time']))
                                : 'Time not set';

                            $display_datetime = date('Y-m-d', strtotime($row['appointment_date'])) .
                                (!empty($row['appointment_time']) ? ' at ' . $time_str : '');
                        ?>

                        <article class="schedule-item">
                            <div class="date-box">
                                <div class="date-month"><?php echo date('M', strtotime($row['appointment_date'])); ?></div>
                                <div class="date-day"><?php echo date('d', strtotime($row['appointment_date'])); ?></div>
                                <div class="date-week"><?php echo date('l', strtotime($row['appointment_date'])); ?></div>
                            </div>

                            <div class="schedule-main">
                                <div class="schedule-service">
                                    <?php echo htmlspecialchars($row['service']); ?>
                                </div>

                                <div class="schedule-details">
                                    <span class="schedule-detail">
                                        <i class="fa-regular fa-clock"></i>
                                        <?php echo htmlspecialchars($time_str); ?>
                                    </span>

                                    <span class="schedule-detail">
                                        <i class="fa-solid fa-paw"></i>
                                        <?php echo htmlspecialchars($display_pet); ?>
                                    </span>

                                    <span class="schedule-detail">
                                        <i class="fa-solid fa-peso-sign"></i>
                                        <?php echo ($fee > 0) ? '₱' . number_format($fee, 2) : 'TBD'; ?>
                                    </span>
                                </div>

                                <div class="schedule-note">
                                    Payment: <?php echo htmlspecialchars($pay_method . ' - ' . $pay_status); ?>
                                </div>
                            </div>

                            <div class="schedule-status">
                                <span class="status-pill status-<?php echo strtoupper(str_replace(' ', '-', $current_status)); ?>">
                                    <i class="fa-solid fa-circle"></i>
                                    <?php echo htmlspecialchars($current_status); ?>
                                </span>

                                <span class="status-pill <?php echo $payment_class; ?>">
                                    <i class="fa-solid fa-wallet"></i>
                                    <?php echo htmlspecialchars($pay_status); ?>
                                </span>

                                <div class="schedule-action">
                                    <?php if ($status_upper === 'PENDING' || $status_upper === 'CONFIRMED'): ?>
                                        <button
                                            type="button"
                                            class="btn-cancel-schedule"
                                            onclick="openCancelModal(
                                                <?php echo $apt_id; ?>,
                                                '<?php echo addslashes(htmlspecialchars($row['service'])); ?>',
                                                '<?php echo addslashes(htmlspecialchars($display_pet)); ?>',
                                                '<?php echo addslashes($display_datetime); ?>'
                                            )"
                                        >
                                            <i class="fa-solid fa-xmark"></i> Cancel
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="schedule-empty">
                    <div class="schedule-empty-icon">
                        <i class="fa-regular fa-calendar-check"></i>
                    </div>

                    <h3>No upcoming appointments</h3>
                    <p>
                        Your future visits will appear here with the date, time,
                        service, pet, and booking status.
                    </p>

                    <a href="book_appointment.php" class="book-new-btn">
                        <i class="fa-solid fa-calendar-plus"></i>
                        Schedule an Appointment
                    </a>
                </div>
            <?php endif; ?>
        </section>

        <?php if (!empty($past)): ?>
            <section class="section-card">
                <div class="section-head">
                    <div>
                        <div class="kicker">Previous visits</div>
                        <h2>Past Appointments</h2>
                        <p>Your completed, cancelled, no-show, or previous bookings.</p>
                    </div>

                    <div class="section-count">
                        <?php echo count($past); ?> records
                    </div>
                </div>

                <div class="schedule-list">
                    <?php foreach ($past as $row): ?>
                        <?php
                            $apt_id = isset($row['id']) ? (int)$row['id'] : 0;
                            $display_pet = !empty($row['joined_pet_name'])
                                ? $row['joined_pet_name']
                                : (!empty($row['pet_name']) ? $row['pet_name'] : 'Unknown Pet');

                            $current_status = empty($row['booking_status'])
                                ? 'Pending'
                                : $row['booking_status'];

                            $status_upper = strtoupper($current_status);

                            $pay_status = $row['payment_status'] ?? 'Pending';
                            $pay_method = $row['payment_method'] ?? 'N/A';

                            $fee = isset($row['total_price'])
                                ? floatval($row['total_price'])
                                : (isset($row['service_fee']) ? floatval($row['service_fee']) : 0);

                            $time_str = !empty($row['appointment_time'])
                                ? date('h:i A', strtotime($row['appointment_time']))
                                : 'Time not set';

                            $display_datetime = date('Y-m-d', strtotime($row['appointment_date'])) .
                                (!empty($row['appointment_time']) ? ' at ' . $time_str : '');
                        ?>

                        <article class="schedule-item">
                            <div class="date-box">
                                <div class="date-month"><?php echo date('M', strtotime($row['appointment_date'])); ?></div>
                                <div class="date-day"><?php echo date('d', strtotime($row['appointment_date'])); ?></div>
                                <div class="date-week"><?php echo date('l', strtotime($row['appointment_date'])); ?></div>
                            </div>

                            <div class="schedule-main">
                                <div class="schedule-service">
                                    <?php echo htmlspecialchars($row['service']); ?>
                                </div>

                                <div class="schedule-details">
                                    <span class="schedule-detail">
                                        <i class="fa-regular fa-clock"></i>
                                        <?php echo htmlspecialchars($time_str); ?>
                                    </span>

                                    <span class="schedule-detail">
                                        <i class="fa-solid fa-paw"></i>
                                        <?php echo htmlspecialchars($display_pet); ?>
                                    </span>

                                    <span class="schedule-detail">
                                        <i class="fa-solid fa-peso-sign"></i>
                                        <?php echo ($fee > 0) ? '₱' . number_format($fee, 2) : 'TBD'; ?>
                                    </span>
                                </div>

                                <div class="schedule-note">
                                    Payment: <?php echo htmlspecialchars($pay_method . ' - ' . $pay_status); ?>
                                </div>
                            </div>

                            <div class="schedule-status">
                                <span class="status-pill status-<?php echo strtoupper(str_replace(' ', '-', $current_status)); ?>">
                                    <?php echo htmlspecialchars($current_status); ?>
                                </span>

                                <div class="schedule-action">
                                    <?php if ($status_upper === 'COMPLETED' && empty($row['review_id'])): ?>
                                        <a
                                            href="write_review.php?appointment_id=<?php echo $apt_id; ?>"
                                            class="btn-review-schedule"
                                        >
                                            <i class="fa-solid fa-star"></i> Write Review
                                        </a>
                                    <?php elseif ($status_upper === 'COMPLETED' && !empty($row['review_id'])): ?>
                                        <span class="feedback-schedule">
                                            <i class="fa-solid fa-check-double"></i> Feedback Sent
                                        </span>
                                    <?php else: ?>
                                        <span class="payment-status">
                                            Previous appointment
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="schedule-info">
            <div class="info-mini">
                <div class="info-mini-icon">
                    <i class="fa-regular fa-calendar-check"></i>
                </div>
                <div>
                    <strong>Check your schedule</strong>
                    <span>Use the upcoming section to see when you need to visit.</span>
                </div>
            </div>

            <div class="info-mini">
                <div class="info-mini-icon">
                    <i class="fa-solid fa-clock"></i>
                </div>
                <div>
                    <strong>Arrive on time</strong>
                    <span>Keep your appointment time in mind for a smooth visit.</span>
                </div>
            </div>

            <div class="info-mini">
                <div class="info-mini-icon">
                    <i class="fa-solid fa-phone"></i>
                </div>
                <div>
                    <strong>Need help?</strong>
                    <span>Call Boogie's at (046) 887 4714 for assistance.</span>
                </div>
            </div>
        </section>
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

        // --- DROPDOWN LOGIC MULA SA DASHBOARD ---
        function toggleDropdown(id) {
            document.querySelectorAll('.dropdown-menu').forEach(menu => {
                if (menu.id !== id) {
                    menu.classList.remove('active');
                }
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

        // --- REAL-TIME AJAX SCRIPT WITH SOUND ---
        const notifSound = new Audio('notification.mp3'); 
        let previousUnreadCount = <?php echo $unread_count; ?>;

        function updateNotifications() {
            fetch('get_unread_notifs.php')
                .then(response => response.json())
                .then(data => {
                    const badge = document.getElementById('notif-badge');
                    
                    if (data.unread > previousUnreadCount) {
                        notifSound.play().catch(err => console.log("User needs to interact with the page first to play sound."));
                    }
                    
                    previousUnreadCount = data.unread;
                    
                    if (data.unread > 0) {
                        badge.style.display = 'inline-block';
                        badge.innerText = data.unread;
                    } else {
                        badge.style.display = 'none';
                    }
                })
                .catch(error => console.error('Error fetching notifications:', error));
        }

        setInterval(updateNotifications, 3000);
    </script>
</body>
</html>
