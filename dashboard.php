<?php
session_start();
include 'db_connect.php'; 

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

$full_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : (isset($_SESSION['full_name']) ? $_SESSION['full_name'] : 'User');
$user_id = $_SESSION['user_id'];

// --- FETCH USER PROFILE IMAGE ---
$user_query = "SELECT profile_image FROM users WHERE id = ?";
$stmt_user = $conn->prepare($user_query);
$stmt_user->bind_param("i", $user_id);
$stmt_user->execute();
$user_result = $stmt_user->get_result();
$user_data = $user_result->fetch_assoc();
$current_profile_pic = !empty($user_data['profile_image']) ? $user_data['profile_image'] : 'default-avatar.png';
$stmt_user->close();

// --- FETCH PETS FOR THIS USER (Secured with Prepared Statements) ---
$pet_query = "SELECT * FROM pets WHERE owner_id = ?";
$stmt_pets = $conn->prepare($pet_query);
$stmt_pets->bind_param("i", $user_id);
$stmt_pets->execute();
$pet_result = $stmt_pets->get_result();
$pet_count = $pet_result->num_rows;

// --- FETCH BOOKING COUNT (Secured) ---
$booking_query = "SELECT COUNT(*) as total FROM appointments WHERE user_id = ?";
$stmt_booking = $conn->prepare($booking_query);
$stmt_booking->bind_param("i", $user_id);
$stmt_booking->execute();
$booking_result = $stmt_booking->get_result();
$booking_count = ($booking_result && $booking_result->num_rows > 0) ? $booking_result->fetch_assoc()['total'] : 0;

// --- 1. INITIAL FETCH UNREAD NOTIFICATIONS COUNT (Secured) ---
$notif_query = "SELECT COUNT(*) as unread FROM notifications WHERE user_id = ? AND is_read = 0";
$stmt_notif = $conn->prepare($notif_query);
$stmt_notif->bind_param("i", $user_id);
$stmt_notif->execute();
$notif_result = $stmt_notif->get_result(); 
$unread_count = ($notif_result && $notif_result->num_rows > 0) ? $notif_result->fetch_assoc()['unread'] : 0;

// --- FETCH LATEST 5 NOTIFICATIONS FOR DROPDOWN ---
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

// --- 2. FETCH UPCOMING APPOINTMENT (Secured) ---
$upcoming_query = "SELECT a.*, p.name as pet_name  
                   FROM appointments a
                   LEFT JOIN pets p ON a.pet_id = p.id
                   WHERE a.user_id = ? 
                   AND a.booking_status = 'Confirmed' 
                   ORDER BY a.appointment_date ASC LIMIT 1";
$stmt_upcoming = $conn->prepare($upcoming_query);
$stmt_upcoming->bind_param("i", $user_id);
$stmt_upcoming->execute();
$upcoming_result = $stmt_upcoming->get_result();
$has_upcoming = $upcoming_result->num_rows > 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Boogie's Pet Care Services</title>

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --brand-blue: #001f3f;
            --brand-blue-2: #0b3b66;
            --brand-yellow: #ffcc00;
            --brand-yellow-soft: #fff7d6;
            --page-bg: #f6f8fb;
            --white: #ffffff;
            --text: #17324d;
            --muted: #6c7d8f;
            --line: #e4eaf1;
            --success: #178957;
            --danger: #dc3b45;
            --shadow: 0 12px 32px rgba(0,31,63,.06);
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
            overflow-y: scroll;
            line-height: 1.6;
        }

        a { color: inherit; }

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
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--brand-blue);
            background: #fff;
            cursor: pointer;
        }

        .notification-bell:hover {
            background: #f8fafc;
        }

        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            background: var(--danger);
            color: #fff;
            font-size: 9px;
            font-weight: 800;
            border-radius: 999px;
            border: 2px solid #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
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
            cursor: pointer;
            color: var(--text);
            font-size: 13px;
            font-weight: 700;
        }

        .profile-trigger:hover {
            background: #f8fafc;
        }

        .profile-trigger img {
            width: 34px !important;
            height: 34px !important;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--brand-blue) !important;
        }

        .profile-trigger .chevron {
            font-size: 11px;
            color: #91a0af;
            margin-left: 2px;
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

        .dropdown-menu.active {
            display: flex;
        }

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
            color: var(--text);
            text-decoration: none;
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

        .dropdown-item:last-child {
            border-bottom: 0;
        }

        .view-all-link {
            text-align: center;
            color: var(--brand-blue);
            font-weight: 800;
        }

        /* ===== DASHBOARD ===== */
        main {
            width: min(1180px, 92%);
            margin: 0 auto;
            padding: 42px 0 74px;
        }

        .welcome-area {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 30px;
            margin-bottom: 25px;
        }

        .welcome-copy .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 12px;
            border-radius: 999px;
            background: var(--brand-yellow-soft);
            border: 1px solid #ffe593;
            color: #8a6900;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .7px;
            margin-bottom: 12px;
        }

        .welcome-copy h1 {
            color: var(--brand-blue);
            font-size: 33px;
            line-height: 1.18;
            font-weight: 800;
            letter-spacing: -.5px;
        }

        .welcome-copy p {
            color: var(--muted);
            font-size: 13px;
            margin-top: 7px;
        }

        .welcome-action {
            flex: 0 0 auto;
        }

        .primary-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 18px;
            border-radius: 11px;
            background: var(--brand-blue);
            color: var(--brand-yellow);
            text-decoration: none;
            font-size: 12px;
            font-weight: 800;
            box-shadow: 0 8px 20px rgba(0,31,63,.11);
        }

        .primary-action:hover {
            background: var(--brand-blue-2);
        }

        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(4, minmax(0,1fr));
            gap: 18px;
            margin-bottom: 25px;
        }

        .dash-card {
            position: relative;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 21px;
            text-decoration: none;
            box-shadow: 0 8px 24px rgba(0,31,63,.045);
            overflow: hidden;
        }

        .dash-card::after {
            content: '';
            position: absolute;
            width: 90px;
            height: 90px;
            right: -35px;
            bottom: -42px;
            border-radius: 50%;
            background: rgba(255,204,0,.10);
        }

        .dash-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--brand-yellow-soft);
            color: var(--brand-blue);
            font-size: 21px;
            margin-bottom: 16px;
        }

        .dash-card h3 {
            color: var(--brand-blue);
            font-size: 15px;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .dash-card p {
            color: var(--muted);
            font-size: 11px;
            margin-bottom: 12px;
        }

        .dash-value {
            color: var(--brand-blue);
            font-size: 23px;
            font-weight: 800;
        }

        .dash-link {
            color: #8593a2;
            font-size: 10px;
            font-weight: 800;
        }

        .overview-grid {
            display: grid;
            grid-template-columns: 1.05fr .95fr;
            gap: 22px;
        }

        .panel {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 20px;
            box-shadow: 0 8px 28px rgba(0,31,63,.045);
            overflow: hidden;
        }

        .panel-header {
            padding: 22px 24px 17px;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 15px;
            border-bottom: 1px solid #edf1f5;
        }

        .panel-heading .kicker {
            color: #8b99a9;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 1.1px;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .panel-heading h2 {
            color: var(--brand-blue);
            font-size: 20px;
            line-height: 1.2;
            font-weight: 800;
        }

        .panel-link {
            color: var(--brand-blue);
            font-size: 10px;
            font-weight: 800;
            text-decoration: none;
            white-space: nowrap;
        }

        .panel-link:hover { color: #8a6900; }

        .panel-body {
            padding: 20px 24px 24px;
        }

        /* Pets */
        .pet-list {
            display: grid;
            gap: 10px;
        }

        .pet-item {
            display: flex;
            align-items: center;
            gap: 13px;
            padding: 12px;
            background: #fbfcfe;
            border: 1px solid #edf1f5;
            border-radius: 13px;
            text-decoration: none;
        }

        .pet-item:hover {
            background: #f8fafc;
        }

        .pet-avatar {
            width: 48px;
            height: 48px;
            flex: 0 0 48px;
            border-radius: 14px;
            background: #edf6ff;
            color: var(--brand-blue);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .pet-info {
            min-width: 0;
            flex: 1;
        }

        .pet-info h4 {
            color: var(--brand-blue);
            font-size: 13px;
            font-weight: 800;
            margin-bottom: 2px;
        }

        .pet-info p {
            color: var(--muted);
            font-size: 10px;
        }

        .pet-arrow {
            color: #b5c0cb;
            font-size: 12px;
        }

        .empty-state {
            text-align: center;
            padding: 28px 18px;
            color: #aeb8c3;
        }

        .empty-state > i {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 58px;
            height: 58px;
            border-radius: 50%;
            background: var(--brand-yellow-soft);
            color: #b18400;
            font-size: 24px;
            margin-bottom: 12px;
        }

        .empty-state p:first-of-type {
            color: var(--brand-blue);
            font-weight: 800;
            font-size: 13px !important;
        }

        .empty-state p:last-of-type {
            max-width: 360px;
            margin: 5px auto 0;
            font-size: 10px !important;
            line-height: 1.6;
        }

        .notice-box {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 12px 13px;
            background: #eff7ff;
            border: 1px solid #dbeaf7;
            border-left: 4px solid var(--brand-blue);
            border-radius: 11px;
            margin-top: 16px;
        }

        .notice-box i {
            color: var(--brand-blue);
            margin-top: 3px;
        }

        .notice-box span {
            color: #49667f;
            font-size: 10px;
            line-height: 1.6;
        }

        /* Upcoming */
        .appointment-card {
            background: linear-gradient(135deg, #f8fbff 0%, #fffdf4 100%);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 19px;
        }

        .appointment-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
        }

        .appointment-service {
            color: var(--brand-blue);
            font-size: 18px;
            line-height: 1.3;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .appointment-meta {
            display: grid;
            gap: 7px;
        }

        .appointment-meta p {
            color: var(--muted);
            font-size: 11px;
        }

        .appointment-meta i {
            width: 15px;
            color: var(--brand-blue);
            margin-right: 4px;
        }

        .status-pill {
            flex: 0 0 auto;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            background: #e7f8ef;
            color: var(--success);
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .appointment-footer {
            margin-top: 18px;
            padding-top: 15px;
            border-top: 1px solid #edf1f5;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .appointment-label {
            color: #8b99a9;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: .7px;
            font-weight: 800;
        }

        .appointment-pet {
            color: var(--brand-blue);
            font-size: 12px;
            font-weight: 800;
            margin-top: 2px;
        }

        .appointment-action {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: var(--brand-blue);
            text-decoration: none;
            font-size: 10px;
            font-weight: 800;
        }

        .appointment-action:hover { color: #8a6900; }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(3,1fr);
            gap: 10px;
            margin-top: 15px;
        }

        .quick-action {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 40px;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 11px;
            text-decoration: none;
            color: var(--brand-blue);
            font-size: 10px;
            font-weight: 800;
        }

        .quick-action:hover { background: #f8fafc; }

        .quick-action i {
            color: #9a7600;
        }

        /* FOOTER */
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
            transition: .2s;
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

        /* RESPONSIVE */
        @media (max-width: 1000px) {
            .nav-top { flex-wrap: wrap; }
            .logo { min-width: auto; }
            .dashboard-cards { grid-template-columns: repeat(2, minmax(0,1fr)); }
            .overview-grid { grid-template-columns: 1fr; }
            .footer-main { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 680px) {
            .promo-bar { font-size: 10px; }
            .nav-top { padding: 12px 18px; }
            .user-controls { width: 100%; justify-content: flex-end; }
            .profile-trigger span.profile-name { display: none; }

            main {
                width: 92%;
                padding-top: 30px;
            }

            .welcome-area {
                display: block;
            }

            .welcome-action {
                margin-top: 16px;
            }

            .primary-action {
                width: 100%;
            }

            .welcome-copy h1 { font-size: 28px; }

            .dashboard-cards {
                grid-template-columns: 1fr 1fr;
                gap: 12px;
            }

            .dash-card { padding: 17px; }
            .dash-card h3 { font-size: 13px; }
            .dash-card p { font-size: 10px; }
            .dash-value { font-size: 19px; }

            .panel-header { padding: 19px 18px 15px; }
            .panel-body { padding: 16px 18px 20px; }

            .appointment-top { display: block; }
            .status-pill { margin-top: 11px; }

            .quick-actions {
                grid-template-columns: 1fr;
            }

            .footer-main {
                grid-template-columns: 1fr;
                gap: 25px;
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
            <a href="home.php" class="logo">
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
                        <span id="notif-badge" class="notification-badge"
                              style="display: <?php echo ($unread_count > 0) ? 'inline-flex' : 'none'; ?>;">
                            <?php echo $unread_count; ?>
                        </span>
                    </div>

                    <div class="dropdown-menu" id="notifDropdown">
                        <div class="dropdown-header">Notifications</div>

                        <?php if(count($notifications) > 0): ?>
                            <?php foreach($notifications as $notif): ?>
                                <a href="notifications.php"
                                   class="dropdown-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>">
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
                        <img src="<?php echo htmlspecialchars($current_profile_pic); ?>" alt="Profile">
                        <span class="profile-name">Hi, <?php echo htmlspecialchars($full_name); ?></span>
                        <i class="fa-solid fa-chevron-down chevron"></i>
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
        <section class="welcome-area">
            <div class="welcome-copy">
                <div class="eyebrow">
                    <i class="fa-solid fa-paw"></i> Customer dashboard
                </div>

                <h1>Good day, <?php echo htmlspecialchars($full_name); ?>!</h1>
                <p>Here's what's happening with your pets today.</p>
            </div>

            <div class="welcome-action">
                <a href="book_appointment.php" class="primary-action">
                    <i class="fa-solid fa-plus"></i> Book Appointment
                </a>
            </div>
        </section>

        <section class="dashboard-cards">
            <a href="petprofile.php" class="dash-card">
                <div class="dash-icon"><i class="fa-solid fa-paw"></i></div>
                <h3>My Pets</h3>
                <p>Registered pet profiles</p>
                <span class="dash-value"><?php echo $pet_count; ?></span>
                <span class="dash-link">View profiles →</span>
            </a>

            <a href="book_appointment.php" class="dash-card">
                <div class="dash-icon"><i class="fa-solid fa-calendar-plus"></i></div>
                <h3>Book Appointment</h3>
                <p>Schedule a new service</p>
                <span class="dash-link">Book now →</span>
            </a>

            <a href="bookings.php" class="dash-card">
                <div class="dash-icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
                <h3>My Bookings</h3>
                <p>Total appointments</p>
                <span class="dash-value"><?php echo $booking_count; ?></span>
                <span class="dash-link">View history →</span>
            </a>

            <a href="notifications.php" class="dash-card">
                <div class="dash-icon"><i class="fa-solid fa-bell"></i></div>
                <h3>Notifications</h3>
                <p>Unread messages & reminders</p>
                <span class="dash-value" id="notif-card-count"><?php echo $unread_count; ?></span>
                <span class="dash-link">Check alerts →</span>
            </a>
        </section>

        <section class="overview-grid">

            <article class="panel">
                <div class="panel-header">
                    <div class="panel-heading">
                        <div class="kicker">Your pets</div>
                        <h2>Pet Profiles</h2>
                    </div>

                    <a href="petprofile.php" class="panel-link">Manage pets →</a>
                </div>

                <div class="panel-body">
                    <?php if ($pet_count > 0): ?>
                        <div class="pet-list">
                            <?php
                                $pet_result->data_seek(0);
                                while($pet = $pet_result->fetch_assoc()):
                            ?>
                                <a href="petprofile.php?id=<?php echo htmlspecialchars($pet['id']); ?>" class="pet-item">
                                    <div class="pet-avatar">
                                        <i class="fa-solid fa-dog"></i>
                                    </div>

                                    <div class="pet-info">
                                        <h4><?php echo htmlspecialchars($pet['name']); ?></h4>
                                        <p>
                                            <?php echo htmlspecialchars($pet['breed']); ?>
                                            •
                                            <?php echo htmlspecialchars($pet['age']); ?> years old
                                        </p>
                                    </div>

                                    <i class="fa-solid fa-chevron-right pet-arrow"></i>
                                </a>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fa-solid fa-dog"></i>
                            <p style="font-size:14px;">No pets registered yet</p>
                            <p style="font-size:12px;">
                                Our staff will add your pets during your first shop visit.
                            </p>
                        </div>
                    <?php endif; ?>

                    <div class="notice-box">
                        <i class="fa-solid fa-circle-info"></i>
                        <span>
                            Official medical profiles are maintained by our staff to
                            help keep your pet's health records accurate.
                        </span>
                    </div>
                </div>
            </article>

            <article class="panel">
                <div class="panel-header">
                    <div class="panel-heading">
                        <div class="kicker">Next visit</div>
                        <h2>Upcoming Appointment</h2>
                    </div>

                    <a href="bookings.php" class="panel-link">View all →</a>
                </div>

                <div class="panel-body">
                    <?php if ($has_upcoming):
                        $upcoming = $upcoming_result->fetch_assoc();
                    ?>
                        <div class="appointment-card">
                            <div class="appointment-top">
                                <div>
                                    <div class="appointment-service">
                                        <?php echo htmlspecialchars($upcoming['service']); ?>
                                    </div>

                                    <div class="appointment-meta">
                                        <p>
                                            <i class="fa-regular fa-calendar"></i>
                                            <?php echo date('F j, Y', strtotime($upcoming['appointment_date'])); ?>
                                        </p>

                                        <p>
                                            <i class="fa-regular fa-clock"></i>
                                            <?php echo htmlspecialchars($upcoming['appointment_time']); ?>
                                        </p>
                                    </div>
                                </div>

                                <span class="status-pill">
                                    <i class="fa-solid fa-circle-check"></i>
                                    <?php echo htmlspecialchars($upcoming['booking_status']); ?>
                                </span>
                            </div>

                            <div class="appointment-footer">
                                <div>
                                    <div class="appointment-label">For your pet</div>
                                    <div class="appointment-pet">
                                        <?php echo htmlspecialchars($upcoming['pet_name'] ?? 'Unknown Pet'); ?>
                                    </div>
                                </div>

                                <a href="bookings.php" class="appointment-action">
                                    View details <i class="fa-solid fa-arrow-right"></i>
                                </a>
                            </div>
                        </div>

                        <div class="quick-actions">
                            <a href="bookings.php" class="quick-action">
                                <i class="fa-solid fa-list-check"></i> My Bookings
                            </a>
                            <a href="petprofile.php" class="quick-action">
                                <i class="fa-solid fa-paw"></i> My Pets
                            </a>
                            <a href="notifications.php" class="quick-action">
                                <i class="fa-solid fa-bell"></i> Notifications
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fa-regular fa-calendar-check"></i>
                            <p style="font-size:14px;">No upcoming appointments</p>
                            <p style="font-size:12px;">
                                You can book your pet's next service whenever you're ready.
                            </p>

                            <a href="book_appointment.php" class="primary-action" style="margin-top:16px;">
                                <i class="fa-solid fa-calendar-plus"></i>
                                Book an Appointment
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </article>

        </section>
    </main>

    <footer>
        <div class="footer-main">
            <div>
                <h4 style="display:flex; align-items:center; gap:10px;"><i class="fa-solid fa-paw"></i> Boogie's Pet Care</h4>
                <p>Your trusted partner for all your pet care needs in Dasmariñas, Cavite.</p>
                <div class="socials">
                    <a href="https://www.facebook.com/boogiespetsupplies"><i class="fa-brands fa-facebook-f"></i></a>
                    <a href="mailto:boogiespetcareservices@gmail.com"><i class="fa-solid fa-envelope"></i></a>
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
                <p><i class="fa-solid fa-location-dot"></i> 110 Don Placido Campos Ave San Agustin 3, Dasmariñas, Philippines</p>
            </div>
        </div>
        <div class="footer-bottom">
            <p>© 2026 Boogie's Pet Care & Services - Dasmariñas Branch. All rights reserved.</p>
        </div>
    </footer>

    <script>
        // --- DROPDOWN LOGIC ---
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
                    const cardCount = document.getElementById('notif-card-count');
                    
                    if (data.unread > previousUnreadCount) {
                        notifSound.play().catch(err => console.log("User needs to interact with the page first to play sound."));
                    }
                    
                    previousUnreadCount = data.unread;
                    
                    if (data.unread > 0) {
                        badge.style.display = 'inline-block';
                        badge.innerText = data.unread;
                        if(cardCount) cardCount.innerText = data.unread;
                    } else {
                        badge.style.display = 'none';
                        if(cardCount) cardCount.innerText = "0";
                    }
                })
                .catch(error => console.error('Error fetching notifications:', error));
        }

        setInterval(updateNotifications, 3000);
    </script>
</body>

    <script>
        // ===== DROPDOWN LOGIC =====
        function toggleDropdown(id) {
            document.querySelectorAll('.dropdown-menu').forEach(menu => {
                if (menu.id !== id) {
                    menu.classList.remove('active');
                }
            });

            const target = document.getElementById(id);
            if (target) {
                target.classList.toggle('active');
            }
        }

        window.addEventListener('click', function(e) {
            const notif = document.querySelector('.notification-wrapper');
            const profile = document.querySelector('.profile-wrapper');

            if (
                (notif && !notif.contains(e.target)) &&
                (profile && !profile.contains(e.target))
            ) {
                document.querySelectorAll('.dropdown-menu').forEach(menu => {
                    menu.classList.remove('active');
                });
            }
        });

        // ===== REAL-TIME NOTIFICATIONS =====
        const notifSound = new Audio('notification.mp3');
        let previousUnreadCount = <?php echo $unread_count; ?>;

        function updateNotifications() {
            fetch('get_unread_notifs.php')
                .then(response => {
                    if (!response.ok) throw new Error('Notification request failed');
                    return response.json();
                })
                .then(data => {
                    const badge = document.getElementById('notif-badge');
                    const cardCount = document.getElementById('notif-card-count');

                    if (data.unread > previousUnreadCount) {
                        notifSound.play().catch(() => {});
                    }

                    previousUnreadCount = data.unread;

                    if (data.unread > 0) {
                        badge.style.display = 'inline-flex';
                        badge.innerText = data.unread;

                        if (cardCount) {
                            cardCount.innerText = data.unread;
                        }
                    } else {
                        badge.style.display = 'none';

                        if (cardCount) {
                            cardCount.innerText = '0';
                        }
                    }
                })
                .catch(error => console.error('Error fetching notifications:', error));
        }

        setInterval(updateNotifications, 3000);
    </script>

</body>
</html>
