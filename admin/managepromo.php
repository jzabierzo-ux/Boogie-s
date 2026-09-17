<?php
session_start();

// 1. SECURITY: STRICTLY ADMIN ONLY (Dahil nakatago ang Promos sa Supervisor)
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: adminlogin.php");
    exit();
}

// 2. DATABASE CONNECTION
include('../db_connect.php'); 

// 3. FETCH ADMIN PROFILE (Updated with Profile Image Logic)
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
        
        // Fix para walang comma sa avatar fallback
        $first_name = explode(' ', $admin_full_name)[0];
        $first_name = trim($first_name, ',');
    }
}

// 4. DATABASE INITIALIZATION & SAFETY CHECK
$table_name = "promos";
$check_table = mysqli_query($conn, "SHOW TABLES LIKE '$table_name'");

// Initialize variables to prevent errors if table is missing
$total_promos = $active_promos = $expired_promos = 0;
$promos_list = false;
$current_date = date('Y-m-d');

if (mysqli_num_rows($check_table) > 0) {
    // 5. FETCH PROMO STATISTICS
    $total_q   = mysqli_query($conn, "SELECT COUNT(*) as count FROM $table_name");
    $active_q  = mysqli_query($conn, "SELECT COUNT(*) as count FROM $table_name WHERE expiry_date >= '$current_date' AND status = 'active'");
    $expired_q = mysqli_query($conn, "SELECT COUNT(*) as count FROM $table_name WHERE expiry_date < '$current_date'");

    $total_promos   = mysqli_fetch_assoc($total_q)['count'] ?? 0;
    $active_promos  = mysqli_fetch_assoc($active_q)['count'] ?? 0;
    $expired_promos = mysqli_fetch_assoc($expired_q)['count'] ?? 0;

    // 6. FETCH PROMOS FOR THE LIST (WITH FILTERING LOGIC)
    $filter = $_GET['filter'] ?? 'all';
    $query_condition = "";

    if ($filter === 'active') {
        $query_condition = "WHERE expiry_date >= '$current_date' AND status = 'active'";
    } elseif ($filter === 'expired') {
        $query_condition = "WHERE expiry_date < '$current_date'";
    }

    $promos_list = mysqli_query($conn, "SELECT * FROM $table_name $query_condition ORDER BY id DESC");
}

// --- FETCH ADMIN NOTIFICATIONS ---
$admin_notif_query = mysqli_query($conn, "SELECT * FROM admin_notifications WHERE is_read = 0 ORDER BY created_at DESC");
$unread_count = ($admin_notif_query) ? mysqli_num_rows($admin_notif_query) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Promos | Boogie's Pet Care</title>
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
        main { margin-left: var(--sidebar-width); flex-grow: 1; display: flex; flex-direction: column; min-height: 100vh; }
        .top-bar { background-color: var(--white); height: 70px; padding: 0 40px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 10px rgba(0,0,0,0.08); position: sticky; top: 0; z-index: 1000; }
        .breadcrumb { font-weight: 700; color: var(--navy-dark); font-size: 15px; display: flex; align-items: center; gap: 8px; }

        /* Fixed Admin Tag */
        .admin-tag { background: var(--navy-dark); color: var(--brand-yellow); padding: 6px 16px; border-radius: 50px; font-size: 10px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; border: 1px solid var(--brand-yellow); }

        /* --- NOTIFICATIONS --- */
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

        /* --- CONTAINER --- */
        .container { padding: 40px; flex-grow: 1; }
        .page-header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 30px; }
        .page-header h1 { font-size: 26px; color: var(--navy-dark); font-weight: 800;}
        .page-header p { color: var(--text-muted); font-size: 14px; margin-top: 5px; font-weight: 500; }

        .btn-new-promo { background-color: var(--navy-dark); color: var(--brand-yellow); text-decoration: none; padding: 12px 24px; border-radius: 8px; font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 10px; transition: 0.3s; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .btn-new-promo:hover { transform: translateY(-2px); box-shadow: 0 6px 12px rgba(0,0,0,0.15); opacity: 0.95; }

        /* --- SUMMARY CARDS --- */
        .status-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px; }
        .status-card { padding: 25px; border-radius: 16px; background: var(--white); color: var(--navy-dark); display: flex; flex-direction: column; gap: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.03); text-decoration: none; transition: transform 0.2s; position: relative; border-left: 4px solid var(--navy-dark);}
        .status-card:hover { transform: translateY(-4px); box-shadow: 0 10px 20px rgba(0,0,0,0.08); border-left-color: var(--brand-yellow);}
        .status-card i { position: absolute; right: 25px; top: 25px; font-size: 30px; opacity: 0.1; color: var(--navy-dark); }
        .status-card .label { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted);}
        .status-card .count { font-size: 32px; font-weight: 800; }

        /* --- CONTENT SECTION & DATA TABLE --- */
        .content-card { background: var(--white); border-radius: 16px; box-shadow: 0 4px 6px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; overflow: hidden; }
        .content-header { padding: 20px 25px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; background: #f8fafc; }
        .content-header h3 { font-size: 16px; color: var(--navy-dark); font-weight: 700; margin: 0;}
        
        .table-wrapper { overflow-x: auto; min-height: 300px; }
        .data-table { width: 100%; border-collapse: collapse; text-align: left; }
        .data-table th { padding: 15px 25px; border-bottom: 2px solid #edf2f7; color: #64748b; font-size: 12px; text-transform: uppercase; font-weight: 700; }
        .data-table td { padding: 15px 25px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; font-size: 14px; color: var(--text-main); font-weight: 500;}
        .data-table tr:hover td { background-color: #f8fafc; }
        
        .badge { padding: 6px 12px; border-radius: 6px; font-size: 10px; font-weight: 800; text-transform: uppercase; display: inline-block; text-align: center; min-width: 80px; letter-spacing: 0.5px; box-shadow: inset 0 0 0 1px rgba(0,0,0,0.05);}
        .badge.active { background: #dcfce7; color: #166534; }
        .badge.expired { background: #fee2e2; color: #991b1b; }
        .badge.inactive { background: #f1f5f9; color: #64748b; }
        
        /* Modern Action Buttons */
        .action-group { display: flex; gap: 8px; justify-content: center; }
        .btn-icon { width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; text-decoration: none; transition: all 0.2s ease; font-size: 14px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .btn-icon:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .btn-edit { background: #f1f5f9; color: var(--navy-dark); }
        .btn-edit:hover { background: var(--brand-yellow); color: var(--navy-dark); }
        .btn-delete { background: #fee2e2; color: #dc2626; }
        .btn-delete:hover { background: #dc2626; color: white; }

        .empty-state { text-align: center; padding: 80px 0; color: #94a3b8; }
        .empty-state i { font-size: 50px; margin-bottom: 15px; opacity: 0.3; }
        .empty-state p { font-size: 14px; font-weight: 600; margin: 0; }

        footer { text-align: center; padding: 40px; color: var(--text-muted); font-size: 12px; border-top: 1px solid rgba(0,0,0,0.05); }

        /* Custom utilities */
        .color-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 6px; }
        .target-url { color: #3b82f6; font-size: 12px; word-break: break-all; margin-top: 4px; font-weight: 400;}
        .clear-filter { font-size: 11px; color: #ef4444; text-decoration: none; font-weight: 700; background: #fee2e2; padding: 4px 10px; border-radius: 6px; display: inline-flex; align-items: center; gap: 5px; transition: 0.2s;}
        .clear-filter:hover { background: #f87171; color: white; }
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

            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                <a href="managestaff.php" class="nav-item"><i class="fas fa-id-badge"></i> Personnel</a>
                <a href="managepromo.php" class="nav-item active"><i class="fas fa-tags"></i> Promos</a>
                <a href="manage_services.php" class="nav-item"><i class="fas fa-list-ul"></i> Pricelist</a>
                <a href="sales_report.php" class="nav-item"><i class="fas fa-file-invoice-dollar"></i> Sales Report</a>
                <a href="admin_account_logs.php" class="nav-item"><i class="fa-solid fa-clock-rotate-left"></i> Account Logs</a>
            <?php endif; ?>
        </nav>
    </aside>

    <main>
        <header class="top-bar">
            <div class="breadcrumb">
                <i class="fas fa-tags" style="opacity: 0.5; font-size: 14px;"></i> 
                Management / Promos
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
            <div class="page-header">
                <div>
                    <h1>Manage Promo Cards</h1>
                    <p>Create and manage visual promotional UI cards and their targeted links.</p>
                </div>
                <a href="addpromo.php" class="btn-new-promo">
                    <i class="fas fa-plus"></i> New Promo
                </a>
            </div>

            <div class="status-grid">
                <a href="managepromo.php" class="status-card total" style="text-decoration: none;">
                    <i class="fas fa-bullhorn"></i>
                    <span class="label">Total Promos</span>
                    <div class="count"><?php echo $total_promos; ?></div>
                </a>
                <a href="managepromo.php?filter=active" class="status-card active-p" style="text-decoration: none;">
                    <i class="fas fa-calendar-check"></i>
                    <span class="label">Active Now</span>
                    <div class="count"><?php echo $active_promos; ?></div>
                </a>
                <a href="managepromo.php?filter=expired" class="status-card expired" style="text-decoration: none;">
                    <i class="fas fa-calendar-times"></i>
                    <span class="label">Expired</span>
                    <div class="count"><?php echo $expired_promos; ?></div>
                </a>
            </div>

            <div class="content-card">
                <div class="content-header">
                    <h3>
                        <?php 
                            if(isset($_GET['filter']) && $_GET['filter'] == 'expired') echo 'Expired Promotions';
                            elseif(isset($_GET['filter']) && $_GET['filter'] == 'active') echo 'Active Promotions';
                            else echo 'Live Promotional Cards';
                        ?>
                    </h3>
                    <?php if(isset($_GET['filter'])): ?>
                        <a href="managepromo.php" class="clear-filter"><i class="fas fa-times"></i> Clear Filter</a>
                    <?php endif; ?>
                </div>
                
                <div class="table-wrapper">
                    <?php if ($promos_list && mysqli_num_rows($promos_list) > 0): ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Promo Details</th>
                                    <th>Action Link</th>
                                    <th>Expiry Date</th>
                                    <th>Status</th>
                                    <th style="text-align: center;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($promo = mysqli_fetch_assoc($promos_list)): ?>
                                    <?php 
                                        // Determine the status dynamically
                                        $status_class = 'inactive';
                                        $display_status = 'Inactive';
                                        
                                        if (isset($promo['expiry_date']) && $promo['expiry_date'] < $current_date) {
                                            $status_class = 'expired';
                                            $display_status = 'Expired';
                                        } elseif (isset($promo['status']) && $promo['status'] === 'active') {
                                            $status_class = 'active';
                                            $display_status = 'Active';
                                        }

                                        // Determine hex color for the theme dot
                                        $theme = $promo['theme_color'] ?? 'purple';
                                        $hex_color = '#001f3f'; // Changed default to Navy Blue
                                        if($theme === 'teal') $hex_color = '#14b8a6';
                                        if($theme === 'red') $hex_color = '#ef4444';
                                        if($theme === 'orange') $hex_color = '#f97316';
                                    ?>
                                    <tr>
                                        <td style="color: var(--text-muted); font-weight: 700;">#<?php echo htmlspecialchars($promo['id']); ?></td>
                                        
                                        <td>
                                            <div style="font-size: 11px; font-weight: 800; color: <?php echo $hex_color; ?>; text-transform: uppercase; margin-bottom: 4px; letter-spacing: 0.5px;">
                                                <?php echo htmlspecialchars($promo['tag'] ?? 'N/A'); ?>
                                            </div>
                                            <div style="font-weight: 700; color: var(--navy-dark); font-size: 15px;">
                                                <?php echo htmlspecialchars($promo['title'] ?? 'N/A'); ?>
                                            </div>
                                            <div style="font-size: 12px; color: var(--text-muted); margin-top: 5px; display: flex; align-items: center;">
                                                <span class="color-dot" style="background-color: <?php echo $hex_color; ?>;"></span>
                                                <?php echo ucfirst(htmlspecialchars($theme)); ?> Theme
                                            </div>
                                        </td>
                                        
                                        <td>
                                            <div style="font-weight: 700; font-size: 13px; color: var(--navy-dark);">
                                                <?php echo htmlspecialchars($promo['button_text'] ?? 'N/A'); ?>
                                            </div>
                                            <div class="target-url">
                                                <?php echo htmlspecialchars($promo['target_url'] ?? 'N/A'); ?>
                                            </div>
                                        </td>
                                        
                                        <td>
                                            <div style="font-weight: 600; color: var(--navy-dark);">
                                                <?php 
                                                    if(!empty($promo['expiry_date'])) {
                                                        echo date("M j, Y", strtotime($promo['expiry_date'])); 
                                                    } else {
                                                        echo "No Expiry";
                                                    }
                                                ?>
                                            </div>
                                        </td>
                                        <td><span class="badge <?php echo $status_class; ?>"><?php echo $display_status; ?></span></td>
                                        <td>
                                            <div class="action-group">
                                                <a href="editpromo.php?id=<?php echo $promo['id']; ?>" class="btn-icon btn-edit" title="Edit Promo"><i class="fas fa-edit"></i></a>
                                                <a href="deletepromo.php?id=<?php echo $promo['id']; ?>" class="btn-icon btn-delete" title="Delete Promo" onclick="return confirm('Are you sure you want to delete this promotional card? This action cannot be undone.');"><i class="fas fa-trash"></i></a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-tags"></i>
                            <p>No promotions found for this category.</p>
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