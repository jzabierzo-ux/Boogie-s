<?php
session_start();

// 1. SECURITY: Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// 2. DATABASE CONNECTION
include('db_connect.php'); 

$user_id = $_SESSION['user_id'] ?? 1;
// Kunin ang full name gaya ng sa dashboard
$full_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : (isset($_SESSION['full_name']) ? $_SESSION['full_name'] : 'User');

// --- FETCH PROFILE IMAGE ---
$user_query = mysqli_query($conn, "SELECT profile_image FROM users WHERE id = '$user_id'");
$user_data = mysqli_fetch_assoc($user_query);
$profile_image = isset($user_data['profile_image']) ? $user_data['profile_image'] : null;

// Match the existing header markup, which uses $profile_pic.
$profile_pic = $profile_image ?: ($_SESSION['profile_image'] ?? '');

// --- NEW LOGIC: MARK ALL NOTIFICATIONS AS READ UPON PAGE LOAD ---
// Kapag pinuntahan ni user ang page na ito, automatic mababasa lahat ng unread notifs.
$update_read_status = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
if ($update_read_status) {
    $update_read_status->bind_param("i", $user_id);
    $update_read_status->execute();
    $update_read_status->close();
}

// --- FETCH UNREAD NOTIFICATIONS COUNT FOR HEADER ---
// (Dapat 0 na ito palagi kapag nag-load dahil ginawa na nating read lahat sa itaas)
$count_query = mysqli_query($conn, "SELECT COUNT(*) as unread_count FROM notifications WHERE user_id = '$user_id' AND is_read = 0");
$count_row = mysqli_fetch_assoc($count_query);
$unread_count = $count_row['unread_count']; 

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

// 4. FETCH ALL NOTIFICATIONS FOR THIS USER (MAIN CONTENT)
$notifs_query = mysqli_query($conn, "SELECT * FROM notifications WHERE user_id = '$user_id' ORDER BY created_at DESC");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications | Boogie's Pet Care Services</title>
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


        /* ===== NOTIFICATIONS PAGE ===== */
        main {
            width: min(980px, 92%);
            margin: 0 auto;
            padding: 42px 0 76px;
        }

        .notification-intro {
            margin-bottom: 26px;
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

        .notification-title {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 25px;
        }

        .notification-kicker {
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

        .notification-title h1 {
            color: var(--brand-blue);
            font-size: 34px;
            line-height: 1.18;
            font-weight: 800;
            letter-spacing: -.6px;
        }

        .notification-title p {
            color: var(--muted);
            font-size: 13px;
            margin-top: 6px;
        }

        .notification-count {
            min-width: 120px;
            padding: 11px 13px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: #fff;
            text-align: center;
            color: #7e8d9d;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: .6px;
            font-weight: 800;
        }

        .notification-count strong {
            display: block;
            color: var(--brand-blue);
            font-size: 21px;
            line-height: 1.1;
            margin-top: 2px;
        }

        .notifications-list {
            display: grid;
            gap: 12px;
        }

        .notification-card {
            position: relative;
            display: flex;
            align-items: flex-start;
            gap: 15px;
            padding: 18px;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 16px;
            box-shadow: 0 8px 25px rgba(0,31,63,.045);
            overflow: hidden;
        }

        .notification-card::before {
            content: '';
            position: absolute;
            inset: 0 auto 0 0;
            width: 4px;
            background: var(--brand-blue);
        }

        .notification-card.announcement::before {
            background: #f19b3a;
        }

        .notification-card.promo::before {
            background: #9b51e0;
        }

        .notif-icon {
            width: 48px;
            height: 48px;
            flex: 0 0 48px;
            border-radius: 14px;
            background: #eef5fb;
            color: var(--brand-blue);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
        }

        .notification-card.announcement .notif-icon {
            background: #fff4df;
            color: #d57b0a;
        }

        .notification-card.promo .notif-icon {
            background: #f3eaff;
            color: #8050b1;
        }

        .notif-content {
            min-width: 0;
            flex: 1;
        }

        .notif-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 4px;
        }

        .notif-head h3 {
            color: var(--brand-blue);
            font-size: 14px;
            line-height: 1.35;
            font-weight: 800;
        }

        .notif-time {
            color: #96a2ae;
            font-size: 9px;
            white-space: nowrap;
            padding-top: 2px;
        }

        .notif-message {
            color: #435466;
            font-size: 11px;
            line-height: 1.7;
            margin-bottom: 8px;
        }

        .notif-meta {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            color: #8a98a7;
            font-size: 9px;
            font-weight: 700;
        }

        .empty-notifications {
            background: #fff;
            border: 1px dashed #d7e1ea;
            border-radius: 17px;
            padding: 55px 20px;
            text-align: center;
            box-shadow: 0 8px 25px rgba(0,31,63,.035);
        }

        .empty-notifications-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 13px;
            border-radius: 50%;
            background: var(--brand-yellow-soft);
            color: #b18400;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 25px;
        }

        .empty-notifications h3 {
            color: var(--brand-blue);
            font-size: 18px;
            font-weight: 800;
            margin-bottom: 4px;
        }

        .empty-notifications p {
            color: var(--muted);
            font-size: 10px;
        }

        .about-box {
            margin-top: 20px;
            padding: 21px 22px;
            background: #eef6ff;
            border: 1px solid #d9e8f5;
            border-radius: 16px;
        }

        .about-box-head {
            display: flex;
            align-items: center;
            gap: 9px;
            margin-bottom: 14px;
        }

        .about-box-head i {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            background: #fff;
            color: var(--brand-blue);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .about-box h4 {
            color: var(--brand-blue);
            font-size: 14px;
            font-weight: 800;
        }

        .about-box p {
            color: #5b6d80;
            font-size: 10px;
            margin-top: 2px;
        }

        .about-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px 20px;
        }

        .about-item {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            color: #426078;
            font-size: 10px;
            line-height: 1.6;
        }

        .about-item i {
            color: #1a9272;
            margin-top: 3px;
            font-size: 10px;
        }

        @media (max-width: 720px) {
            main {
                width: 92%;
                padding-top: 30px;
            }

            .notification-title {
                display: block;
            }

            .notification-count {
                display: inline-block;
                margin-top: 14px;
            }

            .notif-head {
                display: block;
            }

            .notif-time {
                display: block;
                margin-top: 3px;
            }

            .about-grid {
                grid-template-columns: 1fr;
            }
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
        <div class="notification-intro">
            <div class="back-nav">
                <a href="dashboard.php">
                    <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>

            <div class="notification-title">
                <div>
                    <div class="notification-kicker">
                        <i class="fa-solid fa-bell"></i>
                        Your updates
                    </div>

                    <h1>Notifications</h1>
                    <p>Booking confirmations, reminders, medical notes, and special updates from Boogie's.</p>
                </div>

                <div class="notification-count">
                    Total Notifications
                    <strong><?php echo mysqli_num_rows($notifs_query); ?></strong>
                </div>
            </div>
        </div>

        <?php if (mysqli_num_rows($notifs_query) > 0): ?>

            <section class="notifications-list">
                <?php while ($notif = mysqli_fetch_assoc($notifs_query)): ?>

                    <?php
                        $icon = 'fa-solid fa-stethoscope';
                        $card_class = 'notification-card';

                        if (isset($notif['type'])) {
                            if ($notif['type'] === 'promo') {
                                $icon = 'fa-solid fa-tags';
                                $card_class .= ' promo';
                            } elseif ($notif['type'] === 'announcement') {
                                $icon = 'fa-solid fa-bullhorn';
                                $card_class .= ' announcement';
                            } elseif ($notif['type'] === 'booking') {
                                $icon = 'fa-regular fa-calendar-check';
                            }
                        }
                    ?>

                    <article class="<?php echo $card_class; ?>">
                        <div class="notif-icon">
                            <i class="<?php echo $icon; ?>"></i>
                        </div>

                        <div class="notif-content">
                            <div class="notif-head">
                                <h3><?php echo htmlspecialchars($notif['title']); ?></h3>
                                <span class="notif-time">
                                    <?php echo date("M j, Y • g:i A", strtotime($notif['created_at'])); ?>
                                </span>
                            </div>

                            <p class="notif-message">
                                <?php echo htmlspecialchars($notif['message']); ?>
                            </p>

                            <div class="notif-meta">
                                <i class="fa-regular fa-circle-check"></i>
                                System Notification
                            </div>
                        </div>
                    </article>

                <?php endwhile; ?>
            </section>

        <?php else: ?>

            <div class="empty-notifications">
                <div class="empty-notifications-icon">
                    <i class="fa-regular fa-bell-slash"></i>
                </div>

                <h3>No notifications yet</h3>
                <p>You're all caught up. New updates from Boogie's will appear here.</p>
            </div>

        <?php endif; ?>

        <section class="about-box">
            <div class="about-box-head">
                <i class="fa-solid fa-circle-info"></i>
                <div>
                    <h4>What you'll receive here</h4>
                    <p>Important updates connected to your pet care account.</p>
                </div>
            </div>

            <div class="about-grid">
                <div class="about-item">
                    <i class="fa-solid fa-check"></i>
                    <span>Medical notes from our veterinarians.</span>
                </div>

                <div class="about-item">
                    <i class="fa-solid fa-check"></i>
                    <span>Booking confirmations after an appointment is confirmed.</span>
                </div>

                <div class="about-item">
                    <i class="fa-solid fa-check"></i>
                    <span>Reminders before your scheduled appointment.</span>
                </div>

                <div class="about-item">
                    <i class="fa-solid fa-check"></i>
                    <span>Special promotions and important announcements.</span>
                </div>
            </div>
        </section>
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
            // Close all other dropdowns
            document.querySelectorAll('.dropdown-menu').forEach(menu => {
                if (menu.id !== id) {
                    menu.classList.remove('active');
                }
            });
            // Toggle the target dropdown
            document.getElementById(id).classList.toggle('active');
        }

        // Close dropdown when clicking anywhere outside
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
