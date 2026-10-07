<?php

session_start();



// 1. SECURITY: STRICTLY ADMIN ONLY (Restricted ito sa Supervisor)

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {

    header("Location: stafflogin.php"); // Updated to unified login

    exit();

}



// 2. DATABASE CONNECTION

include('../db_supabase.php');



// 3. FETCH ADMIN PROFILE (Updated with Profile Image Logic)

$admin_full_name = "Administrator";

$profile_img_path = "";

$first_name = "Administrator";



try {

    if (isset($_SESSION['user_id'])) {

        $uid = $_SESSION['user_id'];



        $get_admin = $pdo->prepare("

            SELECT full_name, profile_image

            FROM users

            WHERE id = :id

            LIMIT 1

        ");

        $get_admin->execute([':id' => $uid]);



        if ($admin_data = $get_admin->fetch(PDO::FETCH_ASSOC)) {

            $admin_full_name = $admin_data['full_name'] ?? 'Administrator';

            $profile_img_path = $admin_data['profile_image'] ?? '';

            $_SESSION['user_name'] = $admin_full_name;



            // Fix para walang comma sa avatar fallback

            $first_name = explode(' ', $admin_full_name)[0] ?? 'Administrator';

            $first_name = trim($first_name, ',');

        }

    }



    // --- DATE FILTER LOGIC ---

    $start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';

    $end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';



    $date_filter_query = "";

    $date_params = [];



    if (!empty($start_date) && !empty($end_date)) {

        $date_filter_query = " AND a.appointment_date::date BETWEEN :start_date AND :end_date";

        $date_params = [

            ':start_date' => $start_date,

            ':end_date' => $end_date

        ];

    } elseif (!empty($start_date)) {

        $date_filter_query = " AND a.appointment_date::date >= :start_date";

        $date_params = [':start_date' => $start_date];

    } elseif (!empty($end_date)) {

        $date_filter_query = " AND a.appointment_date::date <= :end_date";

        $date_params = [':end_date' => $end_date];

    }



    // --- FETCH SALES SUMMARY ---

    $total_revenue = 0;

    $total_transactions = 0;



    // PostgreSQL equivalent of: SHOW COLUMNS FROM appointments LIKE 'service_fee'

    $revenue_check = $pdo->prepare("

        SELECT 1

        FROM information_schema.columns

        WHERE table_schema = current_schema()

          AND table_name = 'appointments'

          AND column_name = 'service_fee'

        LIMIT 1

    ");

    $revenue_check->execute();



    if ($revenue_check->fetchColumn()) {

        // Total Revenue computation based on filter

        $rev_query = $pdo->prepare("

            SELECT

                COALESCE(SUM(a.service_fee), 0) AS total_rev,

                COUNT(*) AS total_trans

            FROM appointments a

            WHERE (a.booking_status = 'Completed' OR a.booking_status = 'completed')

            $date_filter_query

        ");

        $rev_query->execute($date_params);



        if ($rev_data = $rev_query->fetch(PDO::FETCH_ASSOC)) {

            $total_revenue = $rev_data['total_rev'] ?? 0;

            $total_transactions = $rev_data['total_trans'] ?? 0;

        }

    }



    // --- FETCH DETAILED HISTORY (UPDATED TO GET CUSTOMER / WALK-IN NAME) ---

    $history_query = $pdo->prepare("

        SELECT

            a.id,

            a.appointment_date,

            a.appointment_time,

            a.service,

            a.service_fee,

            p.name AS pet_name,

            p.owner_name AS walkin_owner,

            u.full_name AS registered_name

        FROM appointments a

        LEFT JOIN pets p ON a.pet_id = p.id

        LEFT JOIN users u ON a.user_id = u.id

        WHERE (a.booking_status = 'Completed' OR a.booking_status = 'completed')

        $date_filter_query

        ORDER BY a.appointment_date DESC, a.appointment_time DESC

    ");

    $history_query->execute($date_params);

    $history_rows = $history_query->fetchAll(PDO::FETCH_ASSOC);



    // --- FETCH ADMIN NOTIFICATIONS ---

    $admin_notif_query = $pdo->prepare("

        SELECT *

        FROM admin_notifications

        WHERE is_read = 0

        ORDER BY created_at DESC

    ");

    $admin_notif_query->execute();

    $admin_notifications = $admin_notif_query->fetchAll(PDO::FETCH_ASSOC);

    $unread_count = count($admin_notifications);



} catch (PDOException $e) {

    // Keep the report page from crashing if a database query fails.

    $history_rows = [];

    $admin_notifications = [];

    $unread_count = 0;



    // Keep summary values safe for display.

    $total_revenue = 0;

    $total_transactions = 0;



    error_log("Sales report database error: " . $e->getMessage());

}

?>





<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Sales Report | Boogie's Pet Care</title>

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>

        :root {

            --navy-dark: #001f3f; 

            --brand-yellow: #ffcc00; 

            --brand-blue: #001f3f;

            --bg-light: #f4f7f6; 

            --white: #ffffff; 

            --text-main: #2d3436;

            --text-muted: #64748b; 

            --sidebar-width: 260px;

        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Poppins', sans-serif;}

        body { background-color: var(--bg-light); display: flex; min-height: 100vh; }



        /* --- SIDEBAR --- */

        aside { width: var(--sidebar-width); background-color: var(--navy-dark); color: var(--white); display: flex; flex-direction: column; position: fixed; height: 100vh; z-index: 100; }

        .sidebar-header { padding: 30px 20px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.05); }

        .sidebar-logo { width: 80px; height: auto; object-fit: contain; margin-bottom: 10px; }

        .sidebar-header h2 { font-size: 16px; color: var(--brand-yellow); text-transform: uppercase; letter-spacing: 1px; font-weight: 800;}



        /* --- MODERNIZED SIDEBAR NAVIGATION --- */

        .nav-links { flex-grow: 1; padding: 20px 15px; display: flex; flex-direction: column; gap: 5px; }

        .nav-item {

            display: flex; align-items: center; padding: 14px 20px; color: #94a3b8; 

            text-decoration: none; transition: all 0.3s ease; font-size: 14px;

            font-weight: 500; border-radius: 10px; position: relative;

        }

        .nav-item i { width: 32px; font-size: 18px; transition: transform 0.3s;}

        .nav-item:hover { color: var(--white); background-color: rgba(255, 255, 255, 0.05); transform: translateX(4px); }

        .nav-item.active { color: var(--brand-yellow); background-color: rgba(255, 204, 0, 0.08); font-weight: 700; }

        .nav-item.active::before {

            content: ''; position: absolute; left: -15px; top: 15%; height: 70%; width: 5px; 

            background-color: var(--brand-yellow); border-radius: 0 5px 5px 0; box-shadow: 2px 0 8px rgba(255, 204, 0, 0.5); 

        }



        /* --- MAIN CONTENT & HEADER --- */

        main { margin-left: var(--sidebar-width); flex-grow: 1; display: flex; flex-direction: column; }

        .top-bar { background-color: var(--white); height: 70px; padding: 0 40px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 10px rgba(0,0,0,0.08); position: sticky; top: 0; z-index: 1000; }

        .breadcrumb { font-weight: 700; color: var(--navy-dark); font-size: 15px; display: flex; align-items: center; gap: 8px; }



        /* --- NOTIFICATION STYLES --- */

        .top-right-actions { display: flex; align-items: center; gap: 20px; }

        .notif-wrapper { position: relative; display: inline-flex; align-items: center; cursor: pointer; }

        .notif-badge { position: absolute; top: -5px; right: -8px; background: #e11d48; color: white; border-radius: 50%; padding: 2px 6px; font-size: 10px; font-weight: bold; }

        .notif-dropdown { display: none; position: absolute; right: 0; top: 35px; width: 320px; background: white; border: 1px solid #e2e8f0; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); border-radius: 8px; z-index: 1000; text-align: left; }

        .notif-dropdown.show { display: block; }

        .notif-header { padding: 12px 15px; border-bottom: 1px solid #e2e8f0; font-weight: 700; font-size: 14px; display: flex; justify-content: space-between; align-items: center; color: #001f3f; }

        .notif-body { max-height: 300px; overflow-y: auto; }

        .notif-item { padding: 12px 15px; border-bottom: 1px solid #f1f5f9; font-size: 13px; color: #334155; line-height: 1.4; }

        .notif-item:last-child { border-bottom: none; }

        .notif-empty { padding: 20px; text-align: center; color: #94a3b8; font-size: 13px; }

        .mark-read-btn { font-size: 11px; color: #3b82f6; text-decoration: none; font-weight: 600; }

        .mark-read-btn:hover { text-decoration: underline; }



        /* --- PROFILE DROPDOWN & AVATAR --- */

        .admin-tag { background: var(--navy-dark); color: var(--brand-yellow); padding: 6px 16px; border-radius: 50px; font-size: 10px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; border: 1px solid var(--brand-yellow);}

        .profile-wrapper { position: relative; display: inline-flex; align-items: center; gap: 12px; border-left: 1px solid #e2e8f0; padding-left: 20px; cursor: pointer; user-select: none; }

        .top-avatar { width: 35px; height: 35px; border-radius: 50%; object-fit: cover; border: 2px solid var(--navy-dark); }

        .top-avatar-fallback { width: 35px; height: 35px; border-radius: 50%; background: linear-gradient(135deg, var(--navy-dark), #003366); color: var(--brand-yellow); display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 14px; border: 2px solid var(--brand-yellow); box-shadow: 0 2px 4px rgba(0,0,0,0.1); }



        .profile-dropdown { display: none; position: absolute; right: 0; top: 45px; width: 200px; background: white; border: 1px solid #e2e8f0; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); border-radius: 8px; z-index: 1000; overflow: hidden; text-align: left; }

        .profile-dropdown.show { display: block; }

        .profile-item { padding: 12px 15px; display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--text-main); text-decoration: none; transition: background 0.2s; }

        .profile-item:hover { background: #f1f5f9; color: var(--navy-dark); }

        .profile-item i { width: 16px; text-align: center; }

        .profile-item.logout-text { color: #e11d48; border-top: 1px solid #f1f5f9; }

        .profile-item.logout-text:hover { background: #fff1f2; color: #be123c; }



        .container { padding: 40px; }



        /* Filter Section */

        .filter-section { background: var(--white); padding: 25px; border-radius: 16px; box-shadow: 0 4px 6px rgba(0,0,0,0.03); margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; border: 1px solid #f1f5f9; flex-wrap: wrap; gap: 20px;}

        .filter-form { display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;}

        .input-group { display: flex; flex-direction: column; gap: 8px; }

        .input-group label { font-size: 12px; font-weight: 700; color: var(--navy-dark); text-transform: uppercase; letter-spacing: 0.5px;}

        .input-group input { padding: 10px 15px; border: 1px solid #cbd5e1; border-radius: 8px; outline: none; font-family: 'Poppins', sans-serif; font-size: 13px; transition: 0.2s;}

        .input-group input:focus { border-color: var(--navy-dark); box-shadow: 0 0 0 3px rgba(0,31,63,0.1);}



        .btn-filter { background: var(--navy-dark); color: var(--brand-yellow); border: none; padding: 12px 25px; border-radius: 8px; cursor: pointer; font-weight: 700; font-size: 13px; transition: 0.2s; font-family: 'Poppins', sans-serif; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);}

        .btn-filter:hover { opacity: 0.95; transform: translateY(-2px); box-shadow: 0 6px 12px rgba(0,0,0,0.15);}

        .btn-clear { background: #fee2e2; color: #dc2626; text-decoration: none; padding: 12px 20px; border-radius: 8px; font-weight: 700; font-size: 13px; transition: 0.2s;}

        .btn-clear:hover { background: #f87171; color: white;}



        /* Summary Cards */

        .summary-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 30px; }

        .summary-card { background: linear-gradient(135deg, var(--navy-dark), #003366); color: white; padding: 30px; border-radius: 16px; position: relative; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.05); transition: transform 0.2s;}

        .summary-card:hover { transform: translateY(-4px); box-shadow: 0 10px 20px rgba(0,0,0,0.1); }

        .summary-card.green { background: linear-gradient(135deg, #10b981, #059669); }

        .summary-card h3 { font-size: 14px; opacity: 0.9; margin-bottom: 10px; font-weight: 600; letter-spacing: 0.5px; text-transform: uppercase;}

        .summary-card h1 { font-size: 38px; margin: 0; font-weight: 800;}

        .summary-card i { position: absolute; right: -10px; bottom: -10px; font-size: 100px; opacity: 0.1; }



        /* Table Section */

        .table-container { background: var(--white); border-radius: 16px; padding: 30px; box-shadow: 0 4px 6px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; position: relative;}

        .table-container h2 { font-size: 20px; color: var(--navy-dark); margin-bottom: 20px; border-bottom: 2px solid #f8fafc; padding-bottom: 15px; font-weight: 800;}



        .print-btn { background: var(--brand-yellow); color: var(--navy-dark); border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 700; position: absolute; right: 30px; top: 25px; transition: 0.2s; font-family: 'Poppins', sans-serif; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05);}

        .print-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 12px rgba(0,0,0,0.1); background: #e6b800;}



        .table-wrapper { overflow-x: auto; }

        table { width: 100%; border-collapse: collapse; min-width: 700px;}

        th { text-align: left; padding: 15px 20px; border-bottom: 2px solid #edf2f7; color: var(--text-muted); font-size: 12px; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px;}

        td { padding: 15px 20px; border-bottom: 1px solid #f1f5f9; font-size: 14px; color: var(--text-main); font-weight: 500;}

        tr:hover td { background-color: #f8fafc; }

        .price-col { font-weight: 800; color: #10b981; font-size: 15px;}



        .guest-badge { background: #ffedd5; color: #ea580c; font-size: 10px; padding: 3px 8px; border-radius: 6px; font-weight: 800; border: 1px solid #fdba74; margin-left: 8px; letter-spacing: 0.5px;}



        footer { text-align: center; padding: 40px; color: var(--text-muted); font-size: 12px; border-top: 1px solid rgba(0,0,0,0.05); margin-top: 20px;}



        @media print {

            aside, .top-bar, .filter-section, .print-btn { display: none !important; }

            main { margin-left: 0; padding: 0; }

            .container { padding: 0; }

            body { background: white; }

            .table-container { box-shadow: none; border: none;}

            .summary-card { color: black !important; background: none !important; border: 1px solid #ccc; box-shadow: none;}

            .summary-card h3 { color: #666; }

            .summary-card h1 { color: #000; }

            .summary-card i { display: none; }

        }

        /* ===== RESPONSIVE ADMIN LAYOUT (same pattern as managepet.php) ===== */
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
            .summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .container { padding: 30px; }
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
                background: rgba(0,31,63,0.48);
                z-index: 1900;
            }

            .sidebar-backdrop.show { display: block; }

            main {
                margin-left: 0;
                width: 100%;
                min-width: 0;
            }

            .top-bar {
                height: 60px;
                padding: 0 10px;
                gap: 8px;
            }

            .mobile-menu-toggle { display: inline-flex; }

            .breadcrumb {
                font-size: 12px;
                gap: 6px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .top-right-actions {
                gap: 8px;
                margin-left: auto;
                flex: 0 0 auto;
            }

            .profile-wrapper { gap: 7px; padding-left: 8px; }

            .profile-wrapper > span:last-of-type {
                max-width: 100px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
                font-size: 12px !important;
            }

            .admin-tag { padding: 5px 9px; font-size: 8px; }
            .top-avatar, .top-avatar-fallback { width: 30px; height: 30px; }

            .notif-dropdown {
                width: min(320px, calc(100vw - 20px));
                right: -4px;
            }

            .container { padding: 18px 14px 24px; }

            .filter-section {
                flex-direction: column;
                align-items: stretch;
                gap: 14px;
                padding: 16px;
            }

            .filter-form {
                width: 100%;
                display: grid;
                grid-template-columns: 1fr;
                gap: 10px;
                align-items: stretch;
            }

            .input-group { width: 100%; gap: 6px; }
            .input-group input { width: 100%; min-width: 0; }

            .btn-filter,
            .btn-clear {
                width: 100%;
                min-height: 44px;
                justify-content: center;
                display: inline-flex;
                align-items: center;
            }

            .summary-grid {
                grid-template-columns: 1fr;
                gap: 10px;
                margin-bottom: 18px;
            }

            .summary-card { padding: 20px; border-radius: 14px; }
            .summary-card h3 { font-size: 12px; }
            .summary-card h1 { font-size: 30px; }
            .summary-card i { font-size: 75px; }

            .table-container { padding: 18px 14px; border-radius: 14px; }

            .table-container h2 {
                font-size: 18px;
                padding-bottom: 12px;
                margin-bottom: 16px;
                padding-right: 95px;
            }

            .print-btn {
                right: 14px;
                top: 18px;
                padding: 8px 12px;
                font-size: 11px;
            }

            .table-wrapper {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            table { min-width: 700px; }

            footer { padding: 24px 12px; font-size: 10px; }
        }

        @media (max-width: 560px) {
            .top-bar { height: 58px; padding: 0 8px; }
            .topbar-left { gap: 7px; }
            .mobile-menu-toggle { width: 36px; height: 36px; border-radius: 9px; font-size: 16px; }
            .breadcrumb { font-size: 11px; }
            .top-right-actions { gap: 5px; }
            .notif-wrapper > i { font-size: 19px !important; }
            .profile-wrapper { gap: 5px; padding-left: 6px; }
            .profile-wrapper > span:last-of-type { display: none !important; }
            .admin-tag { padding: 4px 7px; font-size: 7px; }
            .top-avatar, .top-avatar-fallback { width: 29px; height: 29px; }
            .container { padding: 16px 12px 22px; }
            .filter-section { border-radius: 12px; padding: 14px; }
            .summary-card { padding: 17px; }
            .table-container { padding: 16px 12px; }
            .print-btn { right: 12px; top: 16px; }
        }

    </style>
</head>

<body>



    <aside>

        <div class="sidebar-header">

            <img src="bg.png" alt="Boogie's Logo" class="sidebar-logo">

            <h2>Boogie's Admin</h2>

        </div>

        <nav class="nav-links">

            <a href="admindashboard.php" class="nav-item"><i class="fas fa-chart-line"></i> Dashboard</a>

            <a href="managebooking.php" class="nav-item"><i class="fas fa-calendar-alt"></i> Bookings</a>

            <a href="manageusers.php" class="nav-item"><i class="fas fa-users"></i> Users</a>

            <a href="managepet.php" class="nav-item"><i class="fas fa-dog"></i> Pets</a>



            <?php if (isset($_SESSION['role']) && strtolower(trim($_SESSION['role'])) === 'admin'): ?>

                <a href="managestaff.php" class="nav-item"><i class="fas fa-id-badge"></i> Personnel</a>

                <a href="managepromo.php" class="nav-item"><i class="fas fa-tags"></i> Promos</a>

                <a href="manage_services.php" class="nav-item"><i class="fas fa-list-ul"></i> Pricelist</a>

                <a href="sales_report.php" class="nav-item active"><i class="fas fa-file-invoice-dollar"></i> Sales Report</a>

                <a href="admin_account_logs.php" class="nav-item"><i class="fa-solid fa-clock-rotate-left"></i> Account Logs</a>

            <?php endif; ?>

        </nav>

    </aside>



    <div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeMobileSidebar()"></div>

    <main>

        <header class="top-bar">

            <div class="topbar-left">
                <button class="mobile-menu-toggle" type="button" aria-label="Open admin menu" aria-expanded="false" onclick="toggleMobileSidebar(event)">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="breadcrumb">

                <i class="fas fa-file-invoice-dollar" style="opacity: 0.5; font-size: 14px;"></i> Reports / Sales History

            </div>
            </div>



            <div class="top-right-actions">

                <div class="notif-wrapper" onclick="toggleNotif(event)">

                    <i class="fa-solid fa-bell" style="font-size: 22px; color: #64748b;"></i>



                    <span id="admin-notif-badge" class="notif-badge" style="display: <?php echo ($unread_count > 0) ? 'inline-block' : 'none'; ?>;">

                        <?php echo $unread_count; ?>

                    </span>



                    <div class="notif-dropdown" id="notifBox" onclick="event.stopPropagation()">

                        <div class="notif-header">

                            Alerts

                            <a href="mark_notifications_read.php" id="mark-read-link" class="mark-read-btn" style="display: <?php echo ($unread_count > 0) ? 'inline-block' : 'none'; ?>;">Mark all read</a>

                        </div>



                        <div class="notif-body" id="admin-notif-list">

                            <?php if($unread_count > 0): ?>

                                <?php foreach($admin_notifications as $notif): ?>

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

                    <span class="admin-tag"><?php echo strtoupper($_SESSION['role'] ?? 'ADMIN'); ?></span>



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



            <div class="filter-section">

                <div>

                    <h3 style="color: var(--navy-dark); font-size: 18px; margin-bottom: 5px; font-weight: 800;">Filter Sales Data</h3>

                    <p style="color: var(--text-muted); font-size: 13px; font-weight: 500;">Generate an income report based on specific dates.</p>

                </div>

                <form class="filter-form" method="GET" action="sales_report.php">

                    <div class="input-group">

                        <label>From Date</label>

                        <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">

                    </div>

                    <div class="input-group">

                        <label>To Date</label>

                        <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">

                    </div>

                    <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Apply Filter</button>

                    <?php if(!empty($start_date) || !empty($end_date)): ?>

                        <a href="sales_report.php" class="btn-clear">Clear</a>

                    <?php endif; ?>

                </form>

            </div>



            <div class="summary-grid">

                <div class="summary-card">

                    <h3>Computed Total Revenue</h3>

                    <h1>₱<?php echo number_format($total_revenue, 2); ?></h1>

                    <i class="fas fa-wallet"></i>

                </div>

                <div class="summary-card green">

                    <h3>Completed Transactions</h3>

                    <h1><?php echo $total_transactions; ?></h1>

                    <i class="fas fa-check-circle"></i>

                </div>

            </div>



            <div class="table-container">

                <h2>Transaction History</h2>

                <button class="print-btn" onclick="window.print()"><i class="fas fa-print"></i> Print Report</button>



                <div class="table-wrapper">

                    <?php if(count($history_rows) > 0): ?>

                        <table>

                            <thead>

                                <tr>

                                    <th>Date & Time</th>

                                    <th>Customer Name</th>

                                    <th>Pet</th>

                                    <th>Service Availed</th>

                                    <th>Amount Paid</th>

                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach($history_rows as $row): 

                                    // SMART CUSTOMER NAME LOGIC:

                                    // Kung may registered_name, yun ang gagamitin.

                                    // Kung wala (ibig sabihin Walk-In sa lumang data), kukunin natin sa pets table ang pangalan nila.

                                    // Kung wala pa rin, 'Walk-in Guest' ang lalabas.



                                    $is_walkin = empty($row['registered_name']);

                                    $final_customer_name = !$is_walkin ? $row['registered_name'] : (!empty($row['walkin_owner']) ? $row['walkin_owner'] : 'Walk-in Guest');

                                ?>

                                <tr>

                                    <td>

                                        <strong style="color: var(--navy-dark);"><?php echo date('M d, Y', strtotime($row['appointment_date'])); ?></strong><br>

                                        <small style="color: var(--text-muted); font-weight: 600;"><i class="far fa-clock"></i> <?php echo isset($row['appointment_time']) && !empty($row['appointment_time']) ? date('g:i A', strtotime($row['appointment_time'])) : ''; ?></small>

                                    </td>

                                    <td>

                                        <span style="font-weight: 600;"><?php echo htmlspecialchars($final_customer_name); ?></span>

                                        <?php if($is_walkin): ?>

                                            <span class="guest-badge">GUEST</span>

                                        <?php endif; ?>

                                    </td>

                                    <td><?php echo htmlspecialchars($row['pet_name'] ?? 'N/A'); ?></td>

                                    <td><?php echo htmlspecialchars($row['service']); ?></td>

                                    <td class="price-col">₱<?php echo number_format($row['service_fee'] ?? 0, 2); ?></td>

                                </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    <?php else: ?>

                        <div style="text-align: center; padding: 60px 0; color: #94a3b8;">

                            <i class="fas fa-box-open" style="font-size: 50px; margin-bottom: 15px; opacity: 0.3;"></i>

                            <p style="font-weight: 500;">No completed sales found for the selected dates.</p>

                        </div>

                    <?php endif; ?>

                </div>

            </div>



        </div>

        <footer>

            © <?php echo date("Y"); ?> BOOGIE'S PET CARE & SERVICES - DASMARIÑAS BRANCH

        </footer>

    </main>



    <script>

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

    }

        // REAL-TIME NOTIFICATION FETCHER

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

                    throw new Error(

                        'Notification request failed: HTTP ' + response.status

                    );

                }

                return response.json();

            })

            .then(renderAdminNotifications)

            .catch(function(error) {

                console.error('Error fetching admin notifications:', error);

            });

        }



        fetchAdminNotifs();

        setInterval(fetchAdminNotifs, 3000);

        // --- MOBILE SIDEBAR (same behavior as managepet.php) ---
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

    </script>
</body>

</html>