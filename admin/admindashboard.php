<?php
session_start();
include '../db_connect.php'; 

// --- UNIVERSAL SECURITY CHECK ---
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';

// Allow Admin, Manager, and Vet
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // No active session: use the dedicated Admin login portal.
    header("Location: ../admin_login.php");
    exit();
}

if (!in_array($current_role, ['admin', 'manager', 'vet'])) {
    // Logged-in user has an invalid/unauthorized staff role.
    header("Location: ../staff/stafflogin.php");
    exit();
}

// --- PROFILE LOGIC (Updated to fetch profile picture) ---
$user_id = $_SESSION['user_id'];
$get_user = mysqli_query($conn, "SELECT full_name, profile_image FROM users WHERE id = '$user_id'");
$profile_img_path = '';

if($user_data = mysqli_fetch_assoc($get_user)) {
    $full_name = $user_data['full_name'];
    $profile_img_path = $user_data['profile_image']; // Kinuha na natin ang image
    $_SESSION['user_name'] = $full_name; 
} else {
    $full_name = $_SESSION['user_name'] ?? 'User'; 
}

// Kumuha ng unang letra para sa avatar fallback, at inalis ang mga comma
$first_name = explode(' ', $full_name)[0];
$first_name = trim($first_name, ',');

// --- FETCH ADMIN NOTIFICATIONS ---
$admin_notif_query = mysqli_query($conn, "SELECT * FROM admin_notifications WHERE is_read = 0 ORDER BY created_at DESC");
$unread_count = mysqli_num_rows($admin_notif_query);

// --- FETCH REAL-TIME DATA ---
$user_count_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM users WHERE role = 'customer'");
$total_users = mysqli_fetch_assoc($user_count_query)['total'] ?? 0;

$staff_count_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM users WHERE role IN ('staff', 'vet', 'supervisor')");
$total_staff = mysqli_fetch_assoc($staff_count_query)['total'] ?? 0;

$pet_count_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM pets");
$total_pets = ($pet_count_query) ? mysqli_fetch_assoc($pet_count_query)['total'] : 0;

$bookings_query = mysqli_query($conn, "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN booking_status = 'Pending' OR booking_status = 'pending' OR booking_status IS NULL OR booking_status = '' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN booking_status = 'Completed' OR booking_status = 'completed' THEN 1 ELSE 0 END) as completed
    FROM appointments");
$booking_data = mysqli_fetch_assoc($bookings_query);
$total_bookings = $booking_data['total'] ?? 0;
$pending_bookings = $booking_data['pending'] ?? 0;
$completed_bookings = $booking_data['completed'] ?? 0;

// --- TODAY'S REVENUE (RBAC: Admin Only) ---
$todays_revenue = 0;
if ($_SESSION['role'] === 'admin') {
    $revenue_check = mysqli_query($conn, "SHOW COLUMNS FROM appointments LIKE 'service_fee'");
    if(mysqli_num_rows($revenue_check) > 0) {
        $revenue_query = mysqli_query($conn, "SELECT SUM(service_fee) as revenue FROM appointments WHERE (booking_status = 'Completed' OR booking_status = 'completed') AND DATE(appointment_date) = CURDATE()");
        $revenue_data = mysqli_fetch_assoc($revenue_query);
        $todays_revenue = $revenue_data['revenue'] ?? 0;
    }
}

// Fetch Today's Activity (All Bookings) + VERIFICATION STATUS
$todays_activity_query = mysqli_query($conn, "SELECT a.*, p.name as pet_name, p.verification_status 
                                              FROM appointments a 
                                              LEFT JOIN pets p ON a.pet_id = p.id 
                                              WHERE DATE(a.appointment_date) = CURDATE()
                                              AND (a.booking_status IS NULL OR a.booking_status NOT IN ('Cancelled', 'cancelled', 'Completed', 'completed'))
                                              ORDER BY a.appointment_time ASC LIMIT 5");

// Fetch Today's Vet Consultations + VERIFICATION STATUS
$vet_schedule_query = mysqli_query($conn, "SELECT a.*, p.name as pet_name, p.verification_status 
                                           FROM appointments a 
                                           LEFT JOIN pets p ON a.pet_id = p.id 
                                           WHERE DATE(a.appointment_date) = CURDATE() 
                                           AND a.service LIKE 'Vet Services%'
                                           AND (a.booking_status IS NULL OR a.booking_status NOT IN ('Cancelled', 'cancelled', 'Completed', 'completed'))
                                           ORDER BY a.appointment_time ASC LIMIT 5");

// Fetch Recent Users
$recent_users_query = mysqli_query($conn, "SELECT full_name, email, created_at 
                                           FROM users WHERE role = 'customer' 
                                           ORDER BY id DESC LIMIT 5");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | Boogie's Pet Care</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --navy-dark: #001f3f; --brand-yellow: #ffcc00; 
            --bg-light: #f4f7f6; --white: #ffffff; --text-main: #2d3436;
            --text-muted: #636e72; --sidebar-width: 260px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Poppins', sans-serif; }
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
        .nav-item:hover {
            color: var(--white); background-color: rgba(255, 255, 255, 0.05);
            transform: translateX(4px); 
        }
        .nav-item.active {
            color: var(--brand-yellow); background-color: rgba(255, 204, 0, 0.08); font-weight: 700;
        }
        .nav-item.active::before {
            content: ''; position: absolute; left: -15px; top: 15%;
            height: 70%; width: 5px; background-color: var(--brand-yellow);
            border-radius: 0 5px 5px 0; box-shadow: 2px 0 8px rgba(255, 204, 0, 0.5); 
        }

        main { margin-left: var(--sidebar-width); flex-grow: 1; display: flex; flex-direction: column; }
        .top-bar { background-color: var(--white); height: 70px; padding: 0 40px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 10px rgba(0,0,0,0.08); position: sticky; top: 0; z-index: 1000; }
        .breadcrumb { font-weight: 700; color: var(--navy-dark); font-size: 15px; display: flex; align-items: center; gap: 8px; }
        
        /* Fixed Admin Tag */
        .admin-tag { background: var(--navy-dark); color: var(--brand-yellow); padding: 6px 16px; border-radius: 50px; font-size: 10px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; border: 1px solid var(--brand-yellow); }
        
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

        /* --- PROFILE DROPDOWN & AVATAR STYLES --- */
        .profile-wrapper { position: relative; display: inline-flex; align-items: center; gap: 12px; border-left: 1px solid #e2e8f0; padding-left: 20px; cursor: pointer; user-select: none; }
        
        /* Fixed Avatar Colors */
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
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 25px; }
        .stat-card { background: var(--white); padding: 25px; border-radius: 16px; box-shadow: 0 4px 6px rgba(0,0,0,0.03); position: relative; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; border-left: 4px solid var(--navy-dark); }
        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 10px 20px rgba(0,0,0,0.08); }
        .stat-card h3 { font-size: 12px; color: var(--text-muted); text-transform: uppercase; margin-bottom: 8px; font-weight: 700; letter-spacing: 0.5px;}
        .stat-card .val { font-size: 28px; font-weight: 800; color: var(--navy-dark); }
        .stat-card i { position: absolute; top: 25px; right: 20px; font-size: 24px; opacity: 0.1; color: var(--navy-dark); }
        
        .action-banners { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px; }
        .banner { padding: 25px; border-radius: 16px; color: white; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; }
        .banner:hover { transform: translateY(-4px); box-shadow: 0 10px 20px rgba(0,0,0,0.15); }
        .banner-green { background: linear-gradient(135deg, #10b981, #059669); }
        .banner-blue { background: linear-gradient(135deg, #3b82f6, #2563eb); }
        .banner-purple { background: linear-gradient(135deg, var(--navy-dark), #003366); } /* Fixed to Navy Blue */
        .banner h3 { font-size: 14px; font-weight: 600; margin-bottom: 5px; opacity: 0.9;}
        .banner h2 { font-size: 32px; font-weight: 800; }
        
        /* 3 columns para pumasok yung Vet Consultations */
        .table-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 25px; }
        .data-box { background: var(--white); border-radius: 16px; padding: 25px; min-height: 200px; box-shadow: 0 4px 6px rgba(0,0,0,0.03); }
        .data-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #f1f5f9; padding-bottom: 15px; }
        .data-header h3 { font-size: 16px; color: var(--navy-dark); font-weight: 700; }
        
        /* Fixed View Link Color */
        .view-link { color: var(--navy-dark); text-decoration: none; font-size: 12px; font-weight: 700; background: #f1f5f9; padding: 6px 12px; border-radius: 6px; transition: 0.2s; }
        .view-link:hover { background: var(--brand-yellow); color: var(--navy-dark); }
        
        .recent-list { list-style: none; padding: 0; margin: 0; }
        .recent-list li { display: flex; justify-content: space-between; align-items: center; padding: 15px 0; border-bottom: 1px solid #f1f5f9; transition: 0.2s;}
        .recent-list li:hover { background-color: #f8fafc; border-radius: 8px; padding-left: 10px; padding-right: 10px;}
        .recent-list li:last-child { border-bottom: none; }
        .recent-info { display: flex; flex-direction: column; gap: 4px; }
        .recent-name { font-weight: 700; font-size: 14px; color: var(--navy-dark); margin-bottom: 2px; }
        .recent-sub { font-size: 12px; color: var(--text-muted); font-weight: 500;}
        .recent-service { font-size: 12px; font-weight: 700; color: var(--navy-dark); } /* Fixed to Navy Blue */
        
        .recent-status { font-size: 10px; font-weight: 800; padding: 4px 10px; border-radius: 6px; text-transform: uppercase; letter-spacing: 0.5px;}
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-confirmed { background: #dbeafe; color: #1e40af; }
        .status-completed { background: #dcfce7; color: #166534; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }

        footer { text-align: center; padding: 40px; color: var(--text-muted); font-size: 12px; border-top: 1px solid rgba(0,0,0,0.05); margin-top: 20px; }

        @media (max-width: 1200px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .action-banners { grid-template-columns: 1fr; }
            .table-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <aside>
        <div class="sidebar-header">
            <img src="bg.png" alt="Boogie's Logo" class="sidebar-logo">
            <h2>
                <?php echo (isset($_SESSION['role']) && strtolower($_SESSION['role']) === 'admin') ? "Boogie's Admin" : "Boogie's Staff"; ?>
            </h2>
        </div>
        
        <nav class="nav-links">
            <a href="admindashboard.php" class="nav-item active"><i class="fas fa-chart-line"></i> Dashboard</a>
            <a href="managebooking.php" class="nav-item"><i class="fas fa-calendar-alt"></i> Bookings</a>
            <a href="manageusers.php" class="nav-item"><i class="fas fa-users"></i> Users</a>
            <a href="managepet.php" class="nav-item"><i class="fas fa-dog"></i> Pets</a>

            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                <a href="managestaff.php" class="nav-item"><i class="fas fa-id-badge"></i> Personnel</a>
                <a href="managepromo.php" class="nav-item"><i class="fas fa-tags"></i> Promos</a>
                <a href="manage_services.php" class="nav-item"><i class="fas fa-list-ul"></i> Pricelist</a>
                <a href="sales_report.php" class="nav-item"><i class="fas fa-file-invoice-dollar"></i> Sales Report</a>

                <!-- ACCOUNT LOGS: ADMIN ONLY -->
                <a href="admin_account_logs.php" class="nav-item">
                    <i class="fas fa-clock-rotate-left"></i>
                    <span>Account Logs</span>
                </a>
            <?php endif; ?>
        </nav>
    </aside>

    <main>
        <header class="top-bar">
            <div class="breadcrumb">
                <i class="fas fa-desktop" style="opacity: 0.5; font-size: 14px;"></i> Overview / Dashboard
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
                    <span class="admin-tag"><?php echo strtoupper($_SESSION['role'] ?? 'ADMIN'); ?></span>
                    
                    <?php if (!empty($profile_img_path) && file_exists($profile_img_path)): ?>
                        <img src="<?php echo htmlspecialchars($profile_img_path); ?>" class="top-avatar" alt="Profile Picture">
                    <?php else: ?>
                        <div class="top-avatar-fallback"><?php echo strtoupper(substr($first_name, 0, 1)); ?></div>
                    <?php endif; ?>
                    <span style="font-size: 14px; font-weight: 600; color: #4a5568; display: flex; align-items: center; gap: 6px;">
                        <?php echo htmlspecialchars($full_name); ?>
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
            <div style="margin-bottom: 25px;">
                <h1 style="font-size: 26px; color: var(--navy-dark); font-weight: 800;">Welcome Back, <?php echo htmlspecialchars($full_name); ?>!</h1>
                <p style="color: var(--text-muted); font-size: 14px; font-weight: 500;">Here is what's happening with Boogie's Pet Care today.</p>
            </div>

            <div class="stats-grid">
                <div class="stat-card" onclick="window.location.href='manageusers.php'">
                    <h3>Registered Users</h3><div class="val"><?php echo $total_users; ?></div><i class="fas fa-users"></i>
                </div>
                
                <?php if ($_SESSION['role'] === 'admin'): ?>
                    <div class="stat-card" onclick="window.location.href='managestaff.php'">
                        <h3>Personnel</h3><div class="val"><?php echo $total_staff; ?></div><i class="fas fa-id-card"></i>
                    </div>
                <?php else: ?>
                    <div class="stat-card" style="cursor: default; opacity: 0.7;" title="You do not have access to view personnel list.">
                        <h3>Personnel</h3><div class="val"><?php echo $total_staff; ?></div><i class="fas fa-lock"></i>
                    </div>
                <?php endif; ?>

                <div class="stat-card" onclick="window.location.href='managepet.php'">
                    <h3>Pet Profiles</h3><div class="val"><?php echo $total_pets; ?></div><i class="fas fa-paw"></i>
                </div>
                <div class="stat-card" onclick="window.location.href='managebooking.php'">
                    <h3>Total Bookings</h3><div class="val"><?php echo $total_bookings; ?></div><i class="fas fa-calendar-check"></i>
                </div>
            </div>

            <div class="action-banners">
                <div class="banner banner-green" onclick="window.location.href='managebooking.php?status=completed'">
                    <h3>Completed Bookings</h3><h2><?php echo $completed_bookings; ?></h2>
                </div>
                <div class="banner banner-blue" onclick="window.location.href='managebooking.php?status=pending'">
                    <h3>Pending Bookings</h3><h2><?php echo $pending_bookings; ?></h2>
                </div>
                
                <?php if ($_SESSION['role'] === 'admin'): ?>
                <div class="banner banner-purple" onclick="window.location.href='sales_report.php'">
                    <h3>Today's Revenue</h3><h2>₱<?php echo number_format($todays_revenue, 2); ?></h2>
                </div>
                <?php else: ?>
                <div class="banner banner-purple" style="opacity: 0.8; cursor: default;">
                    <h3>Today's Revenue</h3><h2 style="font-size: 16px; margin-top: 5px;"><i class="fas fa-lock"></i> Restricted</h2>
                </div>
                <?php endif; ?>
            </div>

            <div class="table-row">
                
                <div class="data-box">
                    <div class="data-header">
                        <h3>Today's Activity</h3>
                        <a href="managebooking.php" class="view-link">VIEW ALL</a>
                    </div>
                    <?php if(mysqli_num_rows($todays_activity_query) > 0): ?>
                        <ul class="recent-list">
                            <?php while($rb = mysqli_fetch_assoc($todays_activity_query)): 
                                $raw_status = $rb['booking_status'] ?? '';
                                $disp_status = empty($raw_status) ? 'Pending' : ucfirst($raw_status);
                                $status_class = strtolower($disp_status);
                                
                                $display_time = isset($rb['appointment_time']) && !empty($rb['appointment_time']) 
                                                ? date('g:i A', strtotime($rb['appointment_time'])) 
                                                : date('g:i A', strtotime($rb['appointment_date']));
                            ?>
                            <li>
                                <div class="recent-info">
                                    <span class="recent-name">
                                        <?php echo htmlspecialchars($rb['pet_name'] ?? 'Unknown Pet'); ?>
                                    </span>
                                    <span class="recent-service"><i class="fas fa-clipboard-list" style="margin-right: 6px; color: #94a3b8;"></i><?php echo htmlspecialchars($rb['service'] ?? 'General Service'); ?></span>
                                    <span class="recent-sub"><i class="far fa-clock" style="margin-right: 6px;"></i><?php echo $display_time; ?></span>
                                </div>
                                <span class="recent-status status-<?php echo $status_class; ?>"><?php echo $disp_status; ?></span>
                            </li>
                            <?php endwhile; ?>
                        </ul>
                    <?php else: ?>
                        <div style="text-align: center; color: #94a3b8; padding: 40px 0;">
                            <i class="fas fa-calendar-check" style="font-size: 35px; margin-bottom: 12px; opacity: 0.3;"></i>
                            <p style="font-size: 13px; font-weight: 500; margin: 0;">No appointments scheduled for today.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="data-box">
                    <div class="data-header">
                        <h3><i class="fas fa-user-md" style="color: var(--brand-blue); margin-right: 5px;"></i> Vet Consultations</h3>
                    </div>
                    <?php if(mysqli_num_rows($vet_schedule_query) > 0): ?>
                        <ul class="recent-list">
                            <?php while($vs = mysqli_fetch_assoc($vet_schedule_query)): 
                                $raw_status = $vs['booking_status'] ?? '';
                                $disp_status = empty($raw_status) ? 'Pending' : ucfirst($raw_status);
                                $status_class = strtolower($disp_status);
                                
                                $display_time = isset($vs['appointment_time']) && !empty($vs['appointment_time']) 
                                                ? date('g:i A', strtotime($vs['appointment_time'])) 
                                                : date('g:i A', strtotime($vs['appointment_date']));
                            ?>
                            <li>
                                <div class="recent-info">
                                    <span class="recent-name">
                                        <?php echo htmlspecialchars($vs['pet_name'] ?? 'Unknown Pet'); ?>
                                    </span>
                                    <span class="recent-service"><i class="fas fa-stethoscope" style="margin-right: 6px; color: #94a3b8;"></i><?php echo htmlspecialchars($vs['service'] ?? 'Vet Services'); ?></span>
                                    <span class="recent-sub"><i class="far fa-clock" style="margin-right: 6px;"></i><?php echo $display_time; ?></span>
                                </div>
                                <span class="recent-status status-<?php echo $status_class; ?>"><?php echo $disp_status; ?></span>
                            </li>
                            <?php endwhile; ?>
                        </ul>
                    <?php else: ?>
                        <div style="text-align: center; color: #94a3b8; padding: 40px 0;">
                            <i class="fas fa-user-md" style="font-size: 35px; margin-bottom: 12px; opacity: 0.3;"></i>
                            <p style="font-size: 13px; font-weight: 500; margin: 0;">No vet consults for today.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="data-box">
                    <div class="data-header">
                        <h3>Newest Users</h3>
                        <a href="manageusers.php" class="view-link">VIEW ALL</a>
                    </div>
                    <?php if(mysqli_num_rows($recent_users_query) > 0): ?>
                        <ul class="recent-list">
                            <?php while($ru = mysqli_fetch_assoc($recent_users_query)): ?>
                            <li>
                                <div class="recent-info">
                                    <span class="recent-name"><?php echo htmlspecialchars($ru['full_name']); ?></span>
                                    <span class="recent-sub"><i class="far fa-envelope" style="margin-right: 6px;"></i> <?php echo htmlspecialchars($ru['email']); ?></span>
                                </div>
                                <span class="recent-sub" style="font-weight: 700;"><?php echo isset($ru['created_at']) ? date('M d', strtotime($ru['created_at'])) : ''; ?></span>
                            </li>
                            <?php endwhile; ?>
                        </ul>
                    <?php else: ?>
                        <div style="text-align: center; color: #94a3b8; padding: 40px 0;">
                            <i class="fas fa-users" style="font-size: 35px; margin-bottom: 12px; opacity: 0.3;"></i>
                            <p style="font-size: 13px; font-weight: 500; margin: 0;">No new users registered recently.</p>
                        </div>
                    <?php endif; ?>
                </div>
                
            </div>

            <footer>© <?php echo date("Y"); ?> BOOGIE'S PET CARE & SERVICES - DASMARIÑAS BRANCH</footer>
        </div>
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
        let previousUnreadCount = <?php echo $unread_count; ?>;
        
        function fetchAdminNotifs() {
            fetch('get_admin_notifs.php')
                .then(response => response.json())
                .then(data => {
                    const badge = document.getElementById('admin-notif-badge');
                    const notifList = document.getElementById('admin-notif-list');
                    const markReadBtn = document.getElementById('mark-read-link');
                    
                    if (data.unread > 0) {
                        badge.style.display = 'inline-block';
                        badge.innerText = data.unread;
                        if(markReadBtn) markReadBtn.style.display = 'inline-block';
                    } else {
                        badge.style.display = 'none';
                        if(markReadBtn) markReadBtn.style.display = 'none';
                    }

                    if (data.html !== "") {
                        notifList.innerHTML = data.html;
                    } else {
                        notifList.innerHTML = '<div class="notif-empty">No new notifications.</div>';
                    }
                })
                .catch(error => console.error('Error fetching admin notifications:', error));
        }

        setInterval(fetchAdminNotifs, 3000);
    </script>
</body>
</html>