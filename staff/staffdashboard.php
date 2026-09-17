<?php
session_start();
include '../db_connect.php'; 

// --- SECURITY CHECK (FIXED PARA SA VET/STAFF) ---
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';
$is_admin_or_supervisor = isset($_SESSION['logged_in']) && in_array($current_role, ['admin', 'supervisor', 'staff']);
$is_staff = isset($_SESSION['staff_logged_in']) && $_SESSION['staff_logged_in'] === true;

if (!$is_admin_or_supervisor && !$is_staff) {
    header("Location: stafflogin.php");
    exit();
}

// SET CORRECT TIMEZONE FOR PHILIPPINES
date_default_timezone_set('Asia/Manila');

// Get details from Login session
$staff_name = $_SESSION['staff_name'] ?? 'Doctor';

// --- FETCH STAFF PROFILE IMAGE & FULL NAME ---
$profile_img_path = "";
$full_display_name = $staff_name;

if (isset($_SESSION['user_id']) || isset($_SESSION['staff_id'])) {
    $uid = $_SESSION['user_id'] ?? $_SESSION['staff_id'];
    
    // FIX: Idinagdag ang 'full_name' sa query para makuha ang buong pangalan
    $get_staff = mysqli_query($conn, "SELECT full_name, profile_image FROM users WHERE id = '$uid'");
    
    if($get_staff && $staff_data = mysqli_fetch_assoc($get_staff)) {
        $profile_img_path = $staff_data['profile_image']; 
        if (!empty($staff_data['full_name'])) {
            $full_display_name = $staff_data['full_name'];
        }
    }
}

// Linisin ang pangalan para sa Avatar Initial
$clean_name = trim(str_replace('Dr. ', '', $full_display_name), " ,"); 
$first_letter = strtoupper(substr($clean_name, 0, 1)); 

// Siguraduhing may "Dr. " na nakadikit
$display_with_title = (stripos($full_display_name, 'Dr.') === false) ? 'Dr. ' . $full_display_name : $full_display_name;

// --- FETCH NOTIFICATIONS ---
$admin_notif_query = mysqli_query($conn, "SELECT * FROM admin_notifications WHERE is_read = 0 ORDER BY created_at DESC");
$unread_count = ($admin_notif_query) ? mysqli_num_rows($admin_notif_query) : 0;

$today = date('Y-m-d');
$hour = date('H');
$greeting = ($hour < 12) ? "Good Morning" : (($hour < 17) ? "Good Afternoon" : "Good Evening");

// --- SQL QUERIES FOR VET DASHBOARD ---
$today_count_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE appointment_date = '$today' AND service LIKE 'Vet Services%'");
$today_count = ($today_count_query) ? mysqli_fetch_assoc($today_count_query)['total'] : 0;

$completed_count_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE booking_status = 'Completed' AND service LIKE 'Vet Services%'");
$completed_count = ($completed_count_query) ? mysqli_fetch_assoc($completed_count_query)['total'] : 0;

$pet_count_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM pets");
$pet_count = ($pet_count_query) ? mysqli_fetch_assoc($pet_count_query)['total'] : 0;

$today_schedule_query = mysqli_query($conn, "
    SELECT a.*, p.name as pet_name 
    FROM appointments a 
    LEFT JOIN pets p ON a.pet_id = p.id 
    WHERE a.appointment_date = '$today' 
    AND a.service LIKE 'Vet Services%'
    AND (a.booking_status != 'Cancelled' OR a.booking_status IS NULL)
    ORDER BY a.appointment_time ASC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Veterinarian Dashboard | Boogie's Pet Care</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --brand-blue: #1d63ff;
            --brand-yellow: #ffcc00;
            --sidebar-navy: #001529; 
            --bg-light: #f4f7fe;
            --white: #ffffff;
            --text-main: #2d3748;
            --text-muted: #64748b;
            --border: #e2e8f0;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { background: var(--bg-light); color: var(--text-main); display: flex; min-height: 100vh;}

        /* --- SIDEBAR --- */
        .sidebar { width: 260px; background: var(--sidebar-navy); height: 100vh; position: fixed; color: white; display: flex; flex-direction: column; z-index: 100;}
        .sidebar-header { padding: 30px 20px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .sidebar-logo-img { width: 70px; height: auto; object-fit: contain; margin-bottom: 10px; }
        .sidebar-header h2 { font-size: 15px; color: var(--brand-yellow); text-transform: uppercase; letter-spacing: 1px; font-weight: 800; }
        
        /* --- EXACT ADMIN SIDEBAR CLONE CSS --- */
        .nav-links { 
            flex-grow: 1; padding: 20px 15px; display: flex; flex-direction: column; gap: 5px; 
        }
        .nav-item {
            display: flex; align-items: center; padding: 14px 20px; color: #94a3b8; 
            text-decoration: none; transition: all 0.3s ease; font-size: 14px;
            font-weight: 500; border-radius: 10px; position: relative;
        }
        .nav-item i { width: 32px; font-size: 18px; transition: transform 0.3s; text-align: center;}
        .nav-item:hover { 
            color: var(--white); background-color: rgba(255, 255, 255, 0.05); 
            transform: translateX(4px); 
        }
        .nav-item.active { 
            color: var(--brand-yellow); background-color: rgba(255, 204, 0, 0.08); 
            font-weight: 700; 
        }
        .nav-item.active::before {
            content: ''; position: absolute; left: -15px; top: 15%; height: 70%; width: 5px; 
            background-color: var(--brand-yellow); border-radius: 0 5px 5px 0; 
            box-shadow: 2px 0 8px rgba(255, 204, 0, 0.5); 
        }

        /* --- MAIN CONTENT & HEADER --- */
        .main-content { margin-left: 260px; width: calc(100% - 260px); min-height: 100vh; display: flex; flex-direction: column;}
        header { background: var(--white); height: 70px; padding: 0 40px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 1000; box-shadow: 0 1px 10px rgba(0,0,0,0.02);}
        .breadcrumb { font-size: 14px; font-weight: 700; color: var(--sidebar-navy); display: flex; align-items: center; gap: 8px; }
        
        /* --- NOTIFICATION STYLES --- */
        .top-right-actions { display: flex; align-items: center; gap: 20px; }
        .notif-wrapper { position: relative; display: inline-flex; align-items: center; cursor: pointer; }
        .notif-badge { position: absolute; top: -5px; right: -8px; background: #e11d48; color: white; border-radius: 50%; padding: 2px 6px; font-size: 10px; font-weight: bold; }
        .notif-dropdown { display: none; position: absolute; right: 0; top: 35px; width: 320px; background: white; border: 1px solid var(--border); box-shadow: 0 10px 25px rgba(0,0,0,0.1); border-radius: 12px; z-index: 1000; overflow: hidden;}
        .notif-dropdown.show { display: block; }
        .notif-header { padding: 12px 15px; border-bottom: 1px solid var(--border); font-weight: 700; font-size: 13px; display: flex; justify-content: space-between; align-items: center; background: #f8fafc; }
        .notif-body { max-height: 300px; overflow-y: auto; }
        .notif-item { padding: 12px 15px; border-bottom: 1px solid #f1f5f9; font-size: 13px; line-height: 1.4; color: #475569; }
        .notif-item:hover { background: #f8fafc; }

        /* --- PINAGANDANG ROLE TAG AT PROFILE --- */
        .role-label {
            display: flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 204, 0, 0.15); 
            color: #d97706; 
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.5px;
            border: 1px solid rgba(255, 204, 0, 0.3);
            text-transform: uppercase;
        }

        .profile-wrapper { 
            position: relative; 
            display: flex; 
            align-items: center; 
            gap: 15px; 
            border-left: 1px solid var(--border); 
            padding-left: 20px; 
            cursor: pointer; 
            user-select: none; 
        }

        .top-avatar { 
            width: 35px; 
            height: 35px; 
            border-radius: 50%; 
            object-fit: cover; 
            border: 2px solid var(--brand-blue); 
        }

        .top-avatar-fallback { 
            width: 35px; 
            height: 35px; 
            border-radius: 50%; 
            background: var(--sidebar-navy); 
            color: var(--brand-yellow); 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-weight: 800; 
            font-size: 14px; 
            border: 2px solid var(--brand-yellow);
        }
        
        .profile-dropdown { display: none; position: absolute; right: 0; top: 45px; width: 200px; background: white; border: 1px solid var(--border); box-shadow: 0 10px 25px rgba(0,0,0,0.1); border-radius: 12px; z-index: 1000; overflow: hidden; text-align: left; }
        .profile-dropdown.show { display: block; }
        .profile-item { padding: 12px 15px; display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--text-main); text-decoration: none; transition: 0.2s; font-weight: 600;}
        .profile-item:hover { background: #f8fafc; color: var(--brand-blue); }

        /* --- DASHBOARD ELEMENTS --- */
        .container { padding: 35px 40px; flex-grow: 1; }
        
        .hero-banner { 
            background: linear-gradient(135deg, #001529 0%, #003366 100%); 
            padding: 40px; border-radius: 20px; color: white; margin-bottom: 30px; 
            position: relative; overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 21, 41, 0.15);
        }
        .hero-banner::after {
            content: '\f1b0'; font-family: 'Font Awesome 6 Free'; font-weight: 900;
            position: absolute; right: -20px; bottom: -30px; font-size: 180px; opacity: 0.05; transform: rotate(-15deg);
        }
        .hero-banner h2 { font-size: 28px; font-weight: 800; margin-bottom: 8px; }
        .hero-banner p { font-size: 14px; opacity: 0.8; font-weight: 500;}

        .stats-grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 25px; margin-bottom: 35px; }
        .stat-card { 
            background: var(--white); padding: 25px; border-radius: 20px; 
            border: 1px solid transparent; transition: 0.3s; cursor: pointer; 
            box-shadow: 0 4px 6px rgba(0,0,0,0.02);
            border-bottom: 4px solid var(--sidebar-navy);
        }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 12px 25px rgba(0,0,0,0.08); border-bottom-color: var(--brand-yellow); }
        .stat-card i { font-size: 24px; margin-bottom: 15px; display: block; }
        .stat-card h4 { font-size: 11px; color: var(--text-muted); text-transform: uppercase; font-weight: 700; letter-spacing: 1px; margin-bottom: 5px; }
        .stat-card p { font-size: 32px; font-weight: 800; color: var(--sidebar-navy); }

        /* Dynamic Schedule Card Styles */
        .schedule-card { background: white; border-radius: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); overflow: hidden; border: 1px solid var(--border); }
        .schedule-header { padding: 25px 30px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; background: #f8fafc; }
        .schedule-header h3 { font-size: 17px; font-weight: 800; color: var(--sidebar-navy); }
        
        .schedule-list { padding: 0 30px; }
        .schedule-item { display: flex; align-items: center; padding: 20px 0; border-bottom: 1px solid #f1f5f9; transition: 0.2s;}
        .schedule-item:hover { background-color: #fafcfe; }
        .schedule-item:last-child { border-bottom: none; }
        
        .time-box { width: 100px; font-weight: 800; color: var(--brand-blue); font-size: 14px; text-align: center; background: #eff6ff; padding: 8px; border-radius: 10px; margin-right: 25px;}
        .pet-icon { width: 45px; height: 45px; background: #f1f5f9; border-radius: 12px; display: flex; align-items: center; justify-content: center; margin-right: 15px; color: var(--sidebar-navy); font-size: 18px;}
        
        .schedule-info { flex-grow: 1; }
        .schedule-info strong { display: block; color: var(--sidebar-navy); font-size: 16px; font-weight: 700; }
        .schedule-info span { color: var(--text-muted); font-size: 13px; font-weight: 500;}
        
        .schedule-status { font-size: 10px; padding: 6px 12px; border-radius: 8px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px;}
        .st-pending { background: #fef3c7; color: #92400e; }
        .st-confirmed { background: #dbeafe; color: #0369a1; }
        .st-completed { background: #dcfce7; color: #15803d; }

        .btn-view-all { color: var(--brand-blue); text-decoration: none; font-size: 13px; font-weight: 700; background: #eff6ff; padding: 8px 16px; border-radius: 8px; transition: 0.2s;}
        .btn-view-all:hover { background: var(--brand-blue); color: white; }

        footer { text-align: center; padding: 30px; color: var(--text-muted); font-size: 12px; margin-top: auto; border-top: 1px solid var(--border);}
    </style>
</head>
<body>

    <aside class="sidebar">
        <div class="sidebar-header">
            <img src="../bg.png" alt="Boogie's Logo" class="sidebar-logo-img">
            <h2>Boogie's Clinic</h2>
        </div>
        <nav class="nav-links">
            <a href="staffdashboard.php" class="nav-item active"><i class="fas fa-th-large"></i> Dashboard</a>
            <a href="appointments.php" class="nav-item"><i class="fas fa-calendar-alt"></i> Appointments</a>
            <a href="pets.php" class="nav-item"><i class="fas fa-paw"></i> Patients</a>
            <a href="tasks.php" class="nav-item"><i class="fas fa-tasks"></i> My Tasks</a>
        </nav>
    </aside>

    <main class="main-content">
        <header>
            <div class="breadcrumb">
                <i class="fas fa-desktop" style="color: var(--brand-blue);"></i> Overview / Dashboard
            </div>
            
            <div class="top-right-actions">
                <div class="notif-wrapper" onclick="toggleNotif(event)">
                    <i class="fa-solid fa-bell" style="font-size: 20px; color: var(--text-muted);"></i>
                    
                    <?php if($unread_count > 0): ?>
                        <span id="admin-notif-badge" class="notif-badge"><?php echo $unread_count; ?></span>
                    <?php else: ?>
                        <span id="admin-notif-badge" class="notif-badge" style="display: none;">0</span>
                    <?php endif; ?>
                    
                    <div class="notif-dropdown" id="notifBox" onclick="event.stopPropagation()">
                        <div class="notif-header">
                            Alerts
                            <a href="mark_notifications_read.php" id="mark-read-link" class="mark-read-btn" style="display: <?php echo ($unread_count > 0) ? 'inline-block' : 'none'; ?>;">Mark all read</a>
                        </div>
                        
                        <div class="notif-body" id="admin-notif-list">
                            <?php if($unread_count > 0 && $admin_notif_query): ?>
                                <?php while($notif = mysqli_fetch_assoc($admin_notif_query)): ?>
                                    <div class="notif-item">
                                        <i class="fa-solid fa-circle-exclamation" style="color: #ef4444; margin-right: 5px;"></i>
                                        <?php echo htmlspecialchars($notif['message']); ?>
                                        <br><small style="color: #94a3b8; font-size: 11px;"><?php echo date('M d, g:i A', strtotime($notif['created_at'])); ?></small>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="notif-empty">No new clinic alerts.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="profile-wrapper" onclick="toggleProfile(event)">
                    <div class="role-label">
                        <i class="fas fa-user-md"></i> VET
                    </div>
                    
                    <?php if (!empty($profile_img_path) && file_exists($profile_img_path)): ?>
                        <img src="<?php echo htmlspecialchars($profile_img_path); ?>" class="top-avatar" alt="Profile Picture">
                    <?php else: ?>
                        <div class="top-avatar-fallback"><?php echo $first_letter; ?></div>
                    <?php endif; ?>
                    
                    <span style="font-size: 14px; font-weight: 700; color: var(--sidebar-navy); display: flex; align-items: center; gap: 6px;">
                        <?php echo htmlspecialchars($display_with_title); ?>
                        <i class="fas fa-chevron-down" style="font-size: 10px; color: var(--text-muted); opacity: 0.5;"></i>
                    </span>

                    <div class="profile-dropdown" id="profileBox" onclick="event.stopPropagation()">
                        <a href="staff_profile.php" class="profile-item">
                            <i class="fas fa-user-circle"></i> My Profile
                        </a>
                        <a href="../logout.php" class="profile-item logout-text" style="color: #ef4444; border-top: 1px solid #f1f5f9;">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <div class="container">
            <div class="hero-banner">
                <h2><?php echo $greeting; ?>, <?php echo htmlspecialchars($display_with_title); ?>!</h2>
                <p>Welcome back to your clinical overview for today, <?php echo date('l, F j, Y'); ?>.</p>
            </div>

            <div class="stats-grid-3">
                <div class="stat-card" onclick="window.location.href='appointments.php'">
                    <i class="far fa-calendar-alt" style="color: var(--brand-blue);"></i>
                    <h4>Today's Appointments</h4>
                    <p><?php echo $today_count; ?></p>
                </div>
                <div class="stat-card" onclick="window.location.href='appointments.php?status=Completed'">
                    <i class="far fa-check-circle" style="color: #10b981;"></i>
                    <h4>Completed Consultations</h4> 
                    <p><?php echo $completed_count; ?></p>
                </div>
                <div class="stat-card" onclick="window.location.href='pets.php'">
                    <i class="fas fa-paw" style="color: #f43f5e;"></i>
                    <h4>Active Patients</h4>
                    <p><?php echo $pet_count; ?></p>
                </div>
            </div>

            <div class="schedule-card">
                <div class="schedule-header">
                    <h3><i class="far fa-clock" style="color: var(--brand-blue); margin-right: 10px;"></i> Upcoming Patients Today</h3>
                    <a href="appointments.php" class="btn-view-all">View Full Schedule</a>
                </div>
                
                <div class="schedule-list">
                    <?php if (mysqli_num_rows($today_schedule_query) > 0): ?>
                        <?php while($appt = mysqli_fetch_assoc($today_schedule_query)): 
                            $status = $appt['booking_status'] ?? 'Pending';
                            $s_class = 'st-pending';
                            if (strtolower($status) == 'confirmed') $s_class = 'st-confirmed';
                            elseif (strtolower($status) == 'completed') $s_class = 'st-completed';
                        ?>
                            <div class="schedule-item">
                                <div class="time-box">
                                    <?php echo date('h:i A', strtotime($appt['appointment_time'])); ?>
                                </div>
                                <div class="pet-icon">
                                    <i class="fas fa-dog"></i>
                                </div>
                                <div class="schedule-info">
                                    <strong><?php echo htmlspecialchars($appt['pet_name'] ?? 'Unknown Pet'); ?></strong>
                                    <span>Service: <?php echo htmlspecialchars($appt['service']); ?></span>
                                </div>
                                <div>
                                    <span class="schedule-status <?php echo $s_class; ?>"><?php echo $status; ?></span>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 60px 0; color: #94a3b8;">
                            <i class="fas fa-calendar-day" style="font-size: 40px; margin-bottom: 15px; opacity: 0.2;"></i>
                            <p style="font-weight: 500;">No medical appointments scheduled for today.</p>
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
                document.getElementById("notifBox").classList.remove("show");
            }
            if (!event.target.closest('.profile-wrapper')) {
                document.getElementById("profileBox").classList.remove("show");
            }
        }
        
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
                .catch(error => console.error('Error fetching notifications:', error));
        }

        setInterval(fetchAdminNotifs, 3000);
    </script>
</body>
</html>