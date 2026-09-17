<?php
session_start();
include '../db_connect.php'; 

// --- UNIVERSAL SECURITY CHECK ---
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';

// 1. SECURITY: Allow Admin, Manager, and Veterinarian
if (!isset($_SESSION['logged_in']) || !in_array($current_role, ['admin', 'manager', 'vet'])) {
    header("Location: stafflogin.php");
    exit();
}

// 2. FETCH ADMIN PROFILE (Variables explicitly defined to prevent errors)
$admin_full_name = "User";
$profile_img_path = "";
$first_name = "User";

if (isset($_SESSION['user_id'])) {
    $uid = $_SESSION['user_id'];
    $get_admin = mysqli_query($conn, "SELECT full_name, profile_image FROM users WHERE id = '$uid'");
    if($admin_data = mysqli_fetch_assoc($get_admin)) {
        $admin_full_name = $admin_data['full_name'] ?? 'User';
        $profile_img_path = $admin_data['profile_image'] ?? ''; 
        $_SESSION['user_name'] = $admin_full_name; 
        
        // Inayos ang first name para walang comma sa avatar fallback
        $first_name = explode(' ', $admin_full_name)[0];
        $first_name = trim($first_name, ',');
    }
}

// --- FETCH ADMIN NOTIFICATIONS ---
$admin_notif_query = mysqli_query($conn, "SELECT * FROM admin_notifications WHERE is_read = 0 ORDER BY created_at DESC");
$unread_count = ($admin_notif_query) ? mysqli_num_rows($admin_notif_query) : 0;

// 3. FETCH USER STATISTICS
$total_q    = mysqli_query($conn, "SELECT COUNT(*) as count FROM users WHERE role = 'customer'");
// UPDATED: Changed email_verified to is_verified to match our new OTP system
$verified_q = mysqli_query($conn, "SELECT COUNT(*) as count FROM users WHERE role = 'customer' AND is_verified = 1");
$contact_q  = mysqli_query($conn, "SELECT COUNT(*) as count FROM users WHERE role = 'customer' AND contact_number IS NOT NULL AND contact_number != ''");

$total_users    = mysqli_fetch_assoc($total_q)['count'] ?? 0;
$verified_users = mysqli_fetch_assoc($verified_q)['count'] ?? 0;
$contact_users  = mysqli_fetch_assoc($contact_q)['count'] ?? 0;

// 4. FETCH USER LIST WITH SEARCH & FILTER
$search = "";
$filter_type = $_GET['filter'] ?? '';

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = mysqli_real_escape_string($conn, $_GET['search']);
    $query = "SELECT * FROM users WHERE role = 'customer' AND (full_name LIKE '%$search%' OR email LIKE '%$search%' OR contact_number LIKE '%$search%') ORDER BY id DESC";
} elseif ($filter_type === 'verified') {
    $query = "SELECT * FROM users WHERE role = 'customer' AND is_verified = 1 ORDER BY id DESC";
} elseif ($filter_type === 'contact') {
    $query = "SELECT * FROM users WHERE role = 'customer' AND contact_number IS NOT NULL AND contact_number != '' ORDER BY id DESC";
} else {
    $query = "SELECT * FROM users WHERE role = 'customer' ORDER BY id DESC";
}

$users_list = mysqli_query($conn, $query);
$displayed_count = mysqli_num_rows($users_list);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registered Users | Boogie's Pet Care</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --navy-dark: #001f3f; --brand-yellow: #ffcc00; --admin-purple: #8b2cf5;
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

        /* --- DASHBOARD STATS --- */
        .container { padding: 40px; flex-grow: 1; }
        .page-header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 25px; }
        .page-header h1 { font-size: 26px; color: var(--navy-dark); margin-bottom: 5px; font-weight: 800;}
        .page-header p { color: #64748b; margin-bottom: 0; font-weight: 500;}

        .status-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px; }
        .status-card { padding: 25px; border-radius: 16px; color: white; display: flex; flex-direction: column; gap: 10px; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; position: relative; border-left: 4px solid var(--navy-dark);}
        .status-card:hover { transform: translateY(-4px); box-shadow: 0 10px 20px rgba(0,0,0,0.15); border-left-color: var(--brand-yellow);}
        .status-card.total { background: linear-gradient(135deg, var(--navy-dark), #003366); }
        .status-card.verified { background: linear-gradient(135deg, #3b82f6, #1d4ed8); }
        .status-card.contact { background: linear-gradient(135deg, #10b981, #059669); }
        .status-card .label { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; opacity: 0.9;}
        .status-card .count { font-size: 32px; font-weight: 800; }
        .status-card i { position: absolute; right: 25px; top: 25px; font-size: 30px; opacity: 0.2;}

        .search-container { background: var(--white); padding: 15px 25px; border-radius: 12px; display: flex; align-items: center; gap: 15px; box-shadow: 0 4px 6px rgba(0,0,0,0.03); margin-bottom: 25px; border: 1px solid #f1f5f9; }
        .search-input { border: none; outline: none; flex-grow: 1; font-size: 14px; background: transparent; font-family: 'Poppins', sans-serif;}

        /* --- USER CARDS --- */
        .users-list-container { display: grid; grid-template-columns: repeat(auto-fill, minmax(400px, 1fr)); gap: 20px; }
        .user-card { background: var(--white); padding: 25px; border-radius: 16px; box-shadow: 0 4px 6px rgba(0,0,0,0.03); display: flex; flex-direction: column; border: 1px solid transparent; transition: 0.2s; border-left: 4px solid var(--navy-dark); }
        .user-card:hover { border-color: #e2e8f0; transform: translateY(-4px); box-shadow: 0 10px 20px rgba(0,0,0,0.08); border-left-color: var(--brand-yellow);}
        
        .user-card-top { display: flex; align-items: flex-start; gap: 20px; width: 100%; }
        .user-avatar { width: 50px; height: 50px; background: #f1f5f9; color: var(--navy-dark); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
        .user-info h3 { font-size: 18px; color: var(--navy-dark); margin-bottom: 5px; font-weight: 700;}
        .info-item { display: flex; align-items: center; gap: 10px; color: #64748b; font-size: 13px; margin-bottom: 5px; font-weight: 500;}

        /* User Category Badge Styling */
        .category-tag { display: inline-block; padding: 4px 10px; border-radius: 6px; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px; }
        .category-owner { background: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd; }
        .category-breeder { background: #fef3c7; color: #d97706; border: 1px solid #fde68a; }

        .meta-info { margin-top: 15px; padding-top: 15px; border-top: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; }
        .meta-text { font-size: 11px; color: #94a3b8; font-weight: 700; text-transform: uppercase; }

        .view-prof-btn { background-color: #f1f5f9; color: var(--navy-dark); text-decoration: none; font-size: 11px; font-weight: 800; padding: 8px 16px; border-radius: 8px; transition: 0.3s; text-transform: uppercase; letter-spacing: 0.5px; }
        .view-prof-btn:hover { background-color: var(--brand-yellow); color: var(--navy-dark); box-shadow: 0 4px 10px rgba(255, 204, 0, 0.2); }

        footer { text-align: center; padding: 40px; color: var(--text-muted); font-size: 12px; border-top: 1px solid rgba(0,0,0,0.05); }
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
            <a href="admindashboard.php" class="nav-item"><i class="fas fa-chart-line"></i> Dashboard</a>
            <a href="managebooking.php" class="nav-item"><i class="fas fa-calendar-alt"></i> Bookings</a>
            <a href="manageusers.php" class="nav-item active"><i class="fas fa-users"></i> Users</a>
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
                    <span>Account Logs</span>
                </a>
            <?php endif; ?>
        </nav>
    </aside>

    <main>
        <header class="top-bar">
            <div class="breadcrumb">
                <i class="fas fa-users" style="opacity: 0.5; font-size: 14px;"></i> Management / Users
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
                    <span class="admin-tag"><?php echo strtoupper($current_role); ?></span>
                    
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
                    <h1>Registered Users</h1>
                    <p>View and manage all registered customer accounts.</p>
                </div>
            </div>

            <div class="status-grid">
                <div class="status-card total" onclick="window.location.href='manageusers.php'">
                    <i class="fas fa-users"></i>
                    <span class="label">Total Customers</span>
                    <div class="count"><?php echo $total_users; ?></div>
                </div>
                <div class="status-card verified" onclick="window.location.href='manageusers.php?filter=verified'">
                    <i class="fas fa-mobile-screen-button"></i>
                    <span class="label">Verified Accounts</span>
                    <div class="count"><?php echo $verified_users; ?></div>
                </div>
                <div class="status-card contact" onclick="window.location.href='manageusers.php?filter=contact'">
                    <i class="fas fa-phone-alt"></i>
                    <span class="label">With Contact Info</span>
                    <div class="count"><?php echo $contact_users; ?></div>
                </div>
            </div>

            <form action="" method="GET" class="search-container">
                <i class="fas fa-search" style="color: #94a3b8;"></i>
                <?php if (!empty($filter_type)): ?>
                    <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter_type); ?>">
                <?php endif; ?>
                <input type="text" name="search" class="search-input" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by name, email, or contact number...">
                <span class="result-count">Showing <?php echo $displayed_count; ?> users</span>
            </form>

            <div class="users-list-container">
                <?php if ($displayed_count > 0): ?>
                    <?php while($user = mysqli_fetch_assoc($users_list)): ?>
                        <?php 
                            $category = isset($user['user_category']) && !empty($user['user_category']) ? $user['user_category'] : 'Pet Owner';
                            $cat_class = ($category === 'Pet Breeder') ? 'category-breeder' : 'category-owner';
                            
                            $raw_email = $user['email'] ?? '';
                            if (empty($raw_email) || strpos($raw_email, '@guest.local') !== false) {
                                $display_email = '<span style="color:#ea580c; font-weight:700; font-size:10px; background:#ffedd5; padding:4px 8px; border-radius:4px; border: 1px solid #fdba74;">WALK-IN GUEST</span>';
                            } else {
                                $display_email = htmlspecialchars($raw_email);
                            }
                            
                            $is_verified = isset($user['is_verified']) ? (int)$user['is_verified'] : 0;
                        ?>
                        <div class="user-card">
                            <div class="user-card-top">
                                <div class="user-avatar">
                                    <i class="fas fa-user"></i>
                                </div>
                                <div class="user-info" style="flex-grow: 1;">
                                    <h3><?php echo htmlspecialchars($user['full_name']); ?></h3>
                                    <span class="category-tag <?php echo $cat_class; ?>"><?php echo htmlspecialchars($category); ?></span>
                                    
                                    <div class="info-item"><i class="fas fa-envelope" style="color: #cbd5e1;"></i><span><?php echo $display_email; ?></span></div>
                                    <div class="info-item"><i class="fas fa-phone" style="color: #cbd5e1;"></i><span><?php echo htmlspecialchars($user['contact_number'] ?: 'No phone provided'); ?></span></div>
                                    
                                    <div class="info-item" style="margin-top: 10px;">
                                        <?php if ($is_verified == 1): ?>
                                            <span style="color: #10b981; font-size: 11px; font-weight: 800; background: #dcfce7; padding: 4px 10px; border-radius: 4px;"><i class="fa-solid fa-circle-check"></i> Account Verified</span>
                                        <?php else: ?>
                                            <span style="color: #ef4444; font-size: 11px; font-weight: 800; background: #fee2e2; padding: 4px 10px; border-radius: 4px;"><i class="fa-solid fa-circle-xmark"></i> Unverified Account</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="meta-info">
                                <div>
                                    <div class="meta-text">USER ID: <?php echo $user['id']; ?></div>
                                    <div class="meta-text">JOINED: <?php echo isset($user['created_at']) ? date('M d, Y', strtotime($user['created_at'])) : 'N/A'; ?></div>
                                </div>
                                
                                <div style="display: flex; gap: 8px;">
                                    <a href="view_customer.php?id=<?php echo $user['id']; ?>" class="view-prof-btn">
                                        <i class="fas fa-eye" style="margin-right: 5px;"></i> View Profile
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="grid-column: 1/-1; text-align: center; padding: 80px; background: white; border-radius: 16px; border: 1px dashed #cbd5e1;">
                        <i class="fas fa-users" style="font-size: 40px; color: #cbd5e1; margin-bottom: 15px;"></i>
                        <p style="color: #64748b; font-weight: 500;">No users found matching your search.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <footer>© <?php echo date("Y"); ?> BOOGIE'S PET CARE & SERVICES - DASMARIÑAS BRANCH</footer>
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