<?php
session_start();

if (file_exists('../db_connect.php')) {
    include '../db_connect.php';
} else {
    die("Error: db_connect.php not found.");
}

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

// Linisin ang pangalan para sa Avatar Initial (Tatanggalin ang "Dr. " at comma)
$clean_name = trim(str_replace('Dr. ', '', $full_display_name), " ,"); 
$first_letter = strtoupper(substr($clean_name, 0, 1)); 

// Siguraduhing may "Dr. " na nakadikit sa buong pangalan para formal
$display_with_title = (stripos($full_display_name, 'Dr.') === false) ? 'Dr. ' . $full_display_name : $full_display_name;

// --- FETCH NOTIFICATIONS ---
$admin_notif_query = mysqli_query($conn, "SELECT * FROM admin_notifications WHERE is_read = 0 ORDER BY created_at DESC");
$unread_count = ($admin_notif_query) ? mysqli_num_rows($admin_notif_query) : 0;

// --- TASK LOGIC (Automated from Appointments - SINGLE VET CLINIC) ---

// MARK AS COMPLETED
if (isset($_GET['complete'])) {
    $id = (int)$_GET['complete'];
    mysqli_query($conn, "UPDATE appointments SET booking_status = 'Completed' WHERE id = $id");
    header("Location: tasks.php");
    exit;
}

// FETCH CONFIRMED AND COMPLETED APPOINTMENTS FOR VET SERVICES
$query = "
    SELECT a.*, p.name as pet_name 
    FROM appointments a 
    LEFT JOIN pets p ON a.pet_id = p.id 
    WHERE a.booking_status IN ('Confirmed', 'Completed') 
    AND a.service LIKE 'Vet Services%'
    ORDER BY 
        CASE WHEN a.booking_status = 'Completed' THEN 1 ELSE 0 END, 
        a.appointment_date ASC, 
        a.appointment_time ASC
";
$tasks_res = mysqli_query($conn, $query);
$tasks = mysqli_fetch_all($tasks_res, MYSQLI_ASSOC);

// PROGRESS CALCULATION
$total_tasks = count($tasks);
$completed_tasks = count(array_filter($tasks, function($t) { return $t['booking_status'] == 'Completed'; }));
$progress = ($total_tasks > 0) ? ($completed_tasks / $total_tasks) * 100 : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Tasks | Boogie's Pet Care</title>
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
        
        /* --- EXACT ADMIN SIDEBAR CLONE CSS (YELLOW ACTIVE) --- */
        .nav-links { 
            flex-grow: 1; padding: 20px 15px; display: flex; flex-direction: column; gap: 5px; 
        }
        .nav-item {
            display: flex; align-items: center; padding: 14px 20px; color: #94a3b8; 
            text-decoration: none; transition: all 0.3s ease; font-size: 14px;
            font-weight: 500; border-radius: 10px; position: relative;
        }
        .nav-item i { width: 32px; font-size: 18px; transition: transform 0.3s; text-align: center; }
        .nav-item:hover { 
            color: var(--white); background-color: rgba(255, 255, 255, 0.05); 
            transform: translateX(4px); 
        }
        .nav-item.active { 
            color: var(--brand-yellow); 
            background-color: rgba(255, 204, 0, 0.08); 
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
        .notif-wrapper { position: relative; cursor: pointer; }
        .notif-badge { position: absolute; top: -5px; right: -8px; background: #ef4444; color: white; border-radius: 50%; padding: 2px 6px; font-size: 10px; font-weight: 800; }
        .notif-dropdown { display: none; position: absolute; right: 0; top: 35px; width: 320px; background: white; border: 1px solid var(--border); box-shadow: 0 10px 25px rgba(0,0,0,0.1); border-radius: 12px; z-index: 1000; overflow: hidden;}
        .notif-dropdown.show { display: block; }
        .notif-header { padding: 12px 15px; border-bottom: 1px solid var(--border); font-weight: 700; font-size: 13px; display: flex; justify-content: space-between; align-items: center; background: #f8fafc; }
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

        .profile-wrapper { position: relative; display: flex; align-items: center; gap: 15px; border-left: 1px solid var(--border); padding-left: 20px; cursor: pointer; user-select: none; }
        .top-avatar { width: 35px; height: 35px; border-radius: 50%; object-fit: cover; border: 2px solid var(--brand-blue); }
        .top-avatar-fallback { width: 35px; height: 35px; border-radius: 50%; background: var(--sidebar-navy); color: var(--brand-yellow); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 14px; border: 2px solid var(--brand-yellow); box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        
        .profile-dropdown { display: none; position: absolute; right: 0; top: 45px; width: 200px; background: white; border: 1px solid var(--border); box-shadow: 0 10px 25px rgba(0,0,0,0.1); border-radius: 12px; z-index: 1000; overflow: hidden; text-align: left; }
        .profile-dropdown.show { display: block; }
        .profile-item { padding: 12px 15px; display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--text-main); text-decoration: none; transition: 0.2s; font-weight: 600;}
        .profile-item:hover { background: #f8fafc; color: var(--brand-blue); }

        /* --- DASHBOARD STATS & TASKS STYLES --- */
        .container { padding: 35px 40px; flex-grow: 1; max-width: 1000px; margin: 0 auto; width: 100%;}

        /* Adjusted stats grid for Tasks (2 columns) */
        .stats-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 30px; }
        
        .stat-card { 
            background: var(--white); 
            padding: 20px; 
            border-radius: 16px; 
            border: 1px solid transparent; 
            cursor: pointer; 
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            box-shadow: 0 4px 6px rgba(0,0,0,0.02);
            position: relative;
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.05);
            border-color: #e2e8f0;
        }
        
        .stat-card h3 { font-size: 12px; color: var(--text-muted); text-transform: uppercase; margin-bottom: 8px; font-weight: 700; letter-spacing: 0.5px;}
        .stat-card p { font-size: 28px; font-weight: 800; color: var(--sidebar-navy); }
        .stat-card i { position: absolute; right: 25px; top: 25px; font-size: 30px; opacity: 0.1;}
        
        .c-total { border-left: 4px solid var(--sidebar-navy); }
        .c-total i { color: var(--sidebar-navy); }
        .c-completed { border-left: 4px solid #10b981; }
        .c-completed i { color: #10b981; }

        /* --- TASK LIST CONTAINER --- */
        .card { background: var(--white); border-radius: 20px; border: 1px solid var(--border); overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.02); margin-bottom: 20px; padding: 30px;}
        
        .progress-bar-bg { background: #f1f5f9; height: 10px; border-radius: 5px; margin: 20px 0; overflow: hidden; box-shadow: inset 0 1px 2px rgba(0,0,0,0.05); }
        .progress-bar-fill { background: linear-gradient(90deg, #10b981, #059669); height: 100%; border-radius: 5px; transition: width 0.4s ease; }
        
        .task-list { display: flex; flex-direction: column; gap: 15px; margin-top: 25px;}
        .task-item { display: flex; align-items: center; justify-content: space-between; padding: 20px 25px; border: 1px solid var(--border); border-radius: 16px; transition: 0.2s; background: #fff; border-left: 4px solid var(--sidebar-navy); }
        .task-item:hover { background: #f8fafc; border-color: #cbd5e1; transform: translateY(-2px); box-shadow: 0 4px 10px rgba(0,0,0,0.03); border-left-color: var(--brand-yellow);}
        .task-content { display: flex; align-items: center; gap: 20px; }
        
        .task-title { font-size: 16px; font-weight: 800; color: var(--sidebar-navy); margin-bottom: 3px; }
        .task-text { font-size: 14px; font-weight: 500; color: var(--text-main); }
        .task-date { font-size: 12px; color: var(--text-muted); display: flex; align-items: center; gap: 12px; margin-top: 8px; font-weight: 500;}
        
        .task-item.is-completed { opacity: 0.6; background: #f8fafc; border-left-color: #10b981;}
        .task-item.is-completed .task-text, .task-item.is-completed .task-title { text-decoration: line-through; color: var(--text-muted); }
        
        .btn-toggle { width: 35px; height: 35px; border-radius: 10px; border: 2px solid #cbd5e1; display: flex; align-items: center; justify-content: center; text-decoration: none; color: transparent; transition: 0.2s; flex-shrink: 0; background: white;}
        .btn-toggle:hover { border-color: #10b981; color: #10b981; background: #dcfce7; transform: scale(1.1);}
        .task-item.is-completed .btn-toggle { background: #10b981; border-color: #10b981; color: white; pointer-events: none; }
        
        .status-badge { font-size: 10px; padding: 6px 12px; border-radius: 8px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; display: inline-block;}
        .badge-confirmed { background: #e0f2fe; color: #0369a1; }
        .badge-completed { background: #dcfce7; color: #166534; }

        .empty-state { text-align: center; padding: 60px 0; color: #94a3b8; }
        .empty-state i { font-size: 50px; color: #cbd5e1; margin-bottom: 20px; opacity: 0.5; display: block; }
        .empty-state p { font-weight: 500; }

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
            <a href="staffdashboard.php" class="nav-item"><i class="fas fa-th-large"></i> Dashboard</a>
            <a href="appointments.php" class="nav-item"><i class="fas fa-calendar-alt"></i> Appointments</a>
            <a href="pets.php" class="nav-item"><i class="fas fa-paw"></i> Patients</a>
            <a href="tasks.php" class="nav-item active"><i class="fas fa-tasks"></i> My Tasks</a>
        </nav>
    </aside>

    <main class="main-content">
        <header>
            <div class="breadcrumb">
                <i class="fas fa-tasks" style="color: var(--brand-blue);"></i> 
                Veterinarian Portal / My Tasks
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
            <div style="margin-bottom: 30px;">
                <h1 style="font-size: 24px; font-weight: 800; color: var(--sidebar-navy);">My Tasks</h1>
                <p style="color: var(--text-muted); font-size: 14px; font-weight: 500;">Manage and track your daily medical procedures</p>
            </div>

            <div class="stats-grid">
                <div class="stat-card c-total">
                    <i class="fas fa-clipboard-list"></i>
                    <h3>Total Procedures</h3>
                    <p><?php echo $total_tasks; ?></p>
                </div>
                <div class="stat-card c-completed">
                    <i class="fas fa-check-circle"></i>
                    <h3>Completed</h3>
                    <p><?php echo $completed_tasks; ?></p>
                </div>
            </div>

            <div class="card">
                <h2 style="font-size: 18px; color: var(--sidebar-navy); margin-bottom: 20px; border-bottom: 2px solid #f8fafc; padding-bottom: 15px; font-weight: 800;">
                    <i class="fas fa-clock" style="color: var(--brand-blue); margin-right: 8px;"></i> Daily Queue
                </h2>
                
                <div style="font-size: 13px; font-weight: 700; color: var(--brand-blue);">Completion Progress: <?php echo $completed_tasks; ?> / <?php echo $total_tasks; ?> Tasks</div>
                <div class="progress-bar-bg">
                    <div class="progress-bar-fill" style="width: <?php echo $progress; ?>%;"></div>
                </div>

                <div class="task-list">
                    <?php if ($total_tasks > 0): ?>
                        <?php foreach ($tasks as $task): ?>
                            <?php $is_done = ($task['booking_status'] === 'Completed'); ?>
                            
                            <div class="task-item <?php echo $is_done ? 'is-completed' : ''; ?>">
                                <div class="task-content">
                                    <?php if (!$is_done): ?>
                                        <a href="tasks.php?complete=<?php echo $task['id']; ?>" class="btn-toggle" title="Mark as Completed" onclick="return confirm('Are you sure you have completed this service?');">
                                            <i class="fas fa-check" style="font-size: 16px;"></i>
                                        </a>
                                    <?php else: ?>
                                        <div class="btn-toggle">
                                            <i class="fas fa-check" style="font-size: 16px;"></i>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div>
                                        <div class="task-title"><?php echo htmlspecialchars($task['service']); ?></div>
                                        <div class="task-text">Patient: <strong><?php echo htmlspecialchars($task['pet_name'] ?? 'Unknown Pet'); ?></strong></div>
                                        <div class="task-date">
                                            <span><i class="far fa-calendar-alt"></i> <?php echo date("M d, Y", strtotime($task['appointment_date'])); ?></span>
                                            <span><i class="far fa-clock"></i> <?php echo !empty($task['appointment_time']) ? date("g:i A", strtotime($task['appointment_time'])) : 'TBA'; ?></span>
                                        </div>
                                    </div>
                                </div>
                                
                                <div>
                                    <span class="status-badge <?php echo $is_done ? 'badge-completed' : 'badge-confirmed'; ?>">
                                        <?php echo $task['booking_status']; ?>
                                    </span>
                                </div>
                            </div>
                            
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-clipboard-check"></i>
                            <p>No pending procedures.<br>Confirmed bookings will appear here automatically.</p>
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
        // --- Notification & Profile Toggle Logic ---
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

        // Close dropdowns when clicking outside
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

        // --- REAL-TIME NOTIFICATION FETCHER ---
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