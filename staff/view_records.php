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

$staff_name = $_SESSION['staff_name'] ?? 'Doctor';

// --- FETCH STAFF PROFILE IMAGE & FULL NAME ---
$profile_img_path = "";
$full_display_name = $staff_name;

if (isset($_SESSION['user_id']) || isset($_SESSION['staff_id'])) {
    $uid = $_SESSION['user_id'] ?? $_SESSION['staff_id'];
    
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

// Siguraduhing may "Dr. " na nakadikit sa buong pangalan para formal
$display_with_title = (stripos($full_display_name, 'Dr.') === false) ? 'Dr. ' . $full_display_name : $full_display_name;

// --- FETCH NOTIFICATIONS ---
$admin_notif_query = mysqli_query($conn, "SELECT * FROM admin_notifications WHERE is_read = 0 ORDER BY created_at DESC");
$unread_count = ($admin_notif_query) ? mysqli_num_rows($admin_notif_query) : 0;

// --- VIEW RECORD LOGIC ---
// Check if a valid ID was passed in the URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid Patient ID.");
}

$pet_id = $_GET['id'];

// Fetch pet data from the database
$query = "SELECT * FROM pets WHERE id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $pet_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (!$row = mysqli_fetch_assoc($result)) {
    die("Patient not found in the database.");
}

// Map the data
$p_name = $row['name'] ?? 'Unknown';
$p_type = $row['pet_type'] ?? 'Unknown';
$p_breed = $row['breed'] ?? 'Unknown';
$p_gender = $row['gender'] ?? 'Unknown';
$p_age = $row['age'] ?? 'Unknown';
$p_weight = $row['weight'] ?? 'Unknown';
$o_name = $row['owner_name'] ?? 'Unknown';

// --- KINUHA NATIN ANG TAMANG OWNER ID PARA SA NOTIF ---
$owner_id = $row['owner_id'] ?? 0; 

$med_history = $row['medical_history'] ?? '';
$special_needs = $row['special_needs'] ?? ''; 
$p_status = $row['status'] ?? 'Pending';

// --- SEND NOTE TO USER LOGIC (REMOVED MEDICAL HISTORY UPDATE) ---
if (isset($_POST['add_note']) && !empty(trim($_POST['new_note']))) {
    $new_note_text = trim($_POST['new_note']);
    $doctor_name = "Dr. " . str_replace('Dr. ', '', $clean_name);
    
    // --- SEND NOTIFICATION TO THE PET OWNER ONLY ---
    if ($owner_id > 0) {
        $notif_title = "New Medical Note for " . $p_name;
        $notif_message = "$doctor_name added a note for $p_name: \"$new_note_text\"";
        
        $notif_query = "INSERT INTO notifications (user_id, title, message, is_read, created_at) VALUES (?, ?, ?, 0, NOW())";
        $notif_stmt = mysqli_prepare($conn, $notif_query);
        
        if ($notif_stmt) {
            mysqli_stmt_bind_param($notif_stmt, "iss", $owner_id, $notif_title, $notif_message);
            mysqli_stmt_execute($notif_stmt);
        }
    }
    
    // Refresh page
    header("Location: view_records.php?id=" . $pet_id);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Patient Record | Boogie's Pet Care</title>
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
        body { background: var(--bg-light); color: var(--text-main); display: flex; min-height: 100vh; }

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
        .notif-dropdown { display: none; position: absolute; right: 0; top: 35px; width: 320px; background: white; border: 1px solid var(--border); box-shadow: 0 10px 25px rgba(0,0,0,0.1); border-radius: 12px; z-index: 1000; overflow: hidden; }
        .notif-dropdown.show { display: block; }
        .notif-header { padding: 12px 15px; border-bottom: 1px solid var(--border); font-weight: 700; font-size: 13px; display: flex; justify-content: space-between; align-items: center; background: #f8fafc;}
        .notif-item { padding: 12px 15px; border-bottom: 1px solid #f1f5f9; font-size: 13px; line-height: 1.4; color: #475569; }
        .notif-item:hover { background: #f8fafc; }

        /* --- PINAGANDANG ROLE TAG AT PROFILE --- */
        .role-label {
            display: flex; align-items: center; gap: 6px; background: rgba(255, 204, 0, 0.15); 
            color: #d97706; padding: 4px 12px; border-radius: 6px; font-size: 11px;
            font-weight: 800; letter-spacing: 0.5px; border: 1px solid rgba(255, 204, 0, 0.3);
            text-transform: uppercase;
        }
        .profile-wrapper { position: relative; display: flex; align-items: center; gap: 15px; border-left: 1px solid var(--border); padding-left: 20px; cursor: pointer; user-select: none; }
        .top-avatar { width: 35px; height: 35px; border-radius: 50%; object-fit: cover; border: 2px solid var(--brand-blue); }
        .top-avatar-fallback { width: 35px; height: 35px; border-radius: 50%; background: var(--sidebar-navy); color: var(--brand-yellow); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 14px; border: 2px solid var(--brand-yellow); box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        
        .profile-dropdown { display: none; position: absolute; right: 40px; top: 60px; width: 200px; background: white; border: 1px solid var(--border); box-shadow: 0 10px 25px rgba(0,0,0,0.1); border-radius: 12px; z-index: 1000; overflow: hidden; text-align: left; }
        .profile-dropdown.show { display: block; }
        .profile-item { padding: 12px 15px; display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--text-main); text-decoration: none; transition: 0.2s; font-weight: 600;}
        .profile-item:hover { background: #f8fafc; color: var(--brand-blue); }

        .container { padding: 35px 40px; max-width: 1000px; margin: 0 auto; flex-grow: 1; width: 100%;}

        /* --- HEADER ACTIONS --- */
        .header-actions { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        
        .btn-back { 
            background: var(--white); color: var(--sidebar-navy); padding: 10px 20px; 
            border-radius: 8px; border: 1px solid var(--border); text-decoration: none; 
            font-weight: 700; display: inline-flex; align-items: center; gap: 8px; 
            transition: 0.2s; font-size: 14px; box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }
        .btn-back:hover { background: var(--sidebar-navy); color: var(--brand-yellow); transform: translateY(-2px);}
        
        .btn-edit { 
            background: var(--sidebar-navy); color: var(--brand-yellow); padding: 10px 20px; 
            border-radius: 8px; text-decoration: none; font-weight: 700; display: inline-flex; 
            align-items: center; gap: 8px; transition: 0.2s; border: none; font-size: 14px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .btn-edit:hover { opacity: 0.95; transform: translateY(-2px); box-shadow: 0 6px 12px rgba(0,0,0,0.15); }

        /* --- CARD & PET HEADER --- */
        .card { background: var(--white); border-radius: 20px; border: 1px solid var(--border); padding: 40px; box-shadow: 0 4px 10px rgba(0,0,0,0.03); margin-bottom: 25px; border-top: 5px solid var(--sidebar-navy);}
        
        .pet-header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #f8fafc; padding-bottom: 25px; margin-bottom: 30px; }
        .pet-title h1 { 
            font-size: 28px; font-weight: 800; display: flex; align-items: center; 
            gap: 15px; margin-bottom: 5px; color: var(--sidebar-navy);
        }
        .pet-title p { color: var(--text-muted); font-size: 15px; font-weight: 500; margin-left: 60px;}
        
        .status-badge { 
            background: #f1f5f9; color: var(--sidebar-navy); padding: 8px 16px; 
            border-radius: 8px; font-weight: 700; font-size: 13px; 
            border: 1px solid var(--border); display: flex; align-items: center; gap: 8px;
        }
        .status-badge i { color: var(--brand-yellow); font-size: 16px;}

        /* --- INFO GRID --- */
        .info-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 40px; }
        .info-item { background: #f8fafc; padding: 20px; border-radius: 12px; border: 1px solid var(--border); border-left: 4px solid var(--brand-yellow); transition: 0.2s; }
        .info-item:hover { transform: translateY(-3px); box-shadow: 0 6px 12px rgba(0,0,0,0.05); }
        .info-item span { display: block; font-size: 11px; text-transform: uppercase; font-weight: 800; color: var(--text-muted); margin-bottom: 5px; letter-spacing: 0.5px;}
        .info-item strong { font-size: 18px; color: var(--sidebar-navy); font-weight: 700;}

        /* --- SECTION TITLES & BOXES --- */
        .section-title { font-size: 18px; font-weight: 800; margin-bottom: 15px; display: flex; align-items: center; gap: 10px; color: var(--sidebar-navy);}
        
        .text-box { 
            background: #f8fafc; border: 1px solid var(--border); border-radius: 12px; 
            padding: 25px; color: var(--text-main); font-size: 14px; line-height: 1.6; 
            min-height: 100px; margin-bottom: 35px; white-space: pre-wrap; 
            font-weight: 500; border-left: 4px solid var(--sidebar-navy);
        }
        
        /* --- FORM STYLES --- */
        .note-form { display: flex; flex-direction: column; gap: 15px; margin-bottom: 35px; background: #fff; padding: 25px; border-radius: 12px; border: 1px solid var(--border); box-shadow: 0 4px 6px rgba(0,0,0,0.02);}
        textarea { 
            width: 100%; padding: 15px; border: 2px solid var(--border); border-radius: 10px; 
            outline: none; font-family: 'Poppins', sans-serif; font-size: 14px; resize: vertical; 
            min-height: 100px; transition: 0.2s; background: #f8fafc;
        }
        textarea:focus { border-color: var(--sidebar-navy); background: white;}
        
        .btn-submit { 
            background: var(--brand-yellow); color: var(--sidebar-navy); border: none; 
            padding: 12px 25px; border-radius: 8px; cursor: pointer; font-weight: 700; 
            align-self: flex-start; transition: 0.2s; font-family: 'Poppins', sans-serif;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05); display: flex; align-items: center; gap: 8px;
        }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 6px 12px rgba(0,0,0,0.1); }

        footer { text-align: center; padding: 30px; color: var(--text-muted); font-size: 12px; margin-top: auto; border-top: 1px solid var(--border);}
        
        @media (max-width: 900px) {
            .info-grid { grid-template-columns: repeat(2, 1fr); }
            .pet-header { flex-direction: column; gap: 15px; }
            .pet-title p { margin-left: 0; margin-top: 10px;}
        }
        @media (max-width: 600px) {
            .info-grid { grid-template-columns: 1fr; }
        }
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
            <a href="pets.php" class="nav-item active"><i class="fas fa-paw"></i> Patients</a>
            <a href="tasks.php" class="nav-item"><i class="fas fa-tasks"></i> My Tasks</a>
        </nav>
    </aside>

    <main class="main-content">
        <header>
            <div class="breadcrumb">
                <i class="fas fa-paw" style="color: var(--brand-blue);"></i> 
                Veterinarian Portal / Patient Record
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
            <div class="header-actions">
                <a href="pets.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back to Directory</a>
                <a href="edit_medical.php?id=<?php echo $pet_id; ?>" class="btn-edit"><i class="fas fa-edit"></i> Edit Full Medical Profile</a>
            </div>

            <div class="card">
                <div class="pet-header">
                    <div class="pet-title">
                        <h1>
                            <div style="background: #f1f5f9; width: 45px; height: 45px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                                <i class="fas fa-paw" style="color: var(--sidebar-navy); font-size: 24px;"></i>
                            </div>
                            <?php echo htmlspecialchars($p_name); ?>
                        </h1>
                        <p style="margin-left: 60px;"><?php echo htmlspecialchars($p_breed); ?> • <?php echo htmlspecialchars($p_type); ?></p>
                    </div>
                    <div class="status-badge"><i class="fas fa-user-check"></i> Owner: <?php echo htmlspecialchars($o_name); ?></div>
                </div>

                <div class="info-grid">
                    <div class="info-item">
                        <span>Gender</span>
                        <strong><?php echo htmlspecialchars($p_gender); ?></strong>
                    </div>
                    <div class="info-item">
                        <span>Age</span>
                        <strong><?php echo htmlspecialchars($p_age); ?> yrs</strong>
                    </div>
                    <div class="info-item">
                        <span>Weight</span>
                        <strong><?php echo htmlspecialchars($p_weight); ?></strong>
                    </div>
                    
                </div>

                <h3 class="section-title"><i class="fas fa-stethoscope" style="color: var(--sidebar-navy);"></i> Add Consultation Note</h3>
                <form method="POST" action="" class="note-form">
                    <textarea name="new_note" placeholder="Type diagnosis, prescriptions, or clinical notes here to notify the owner..." required></textarea>
                    <button type="submit" name="add_note" class="btn-submit"><i class="fas fa-paper-plane"></i> Send Note to Owner</button>
                </form>

                <h3 class="section-title"><i class="fas fa-notes-medical" style="color: #ef4444;"></i> Medical History</h3>
                <div class="text-box">
                    <?php 
                        if (empty($med_history)) {
                            echo "<span style='color: #94a3b8; font-style: italic;'><i class='fas fa-info-circle'></i> No medical history recorded yet.</span>";
                        } else {
                            echo htmlspecialchars($med_history); 
                        }
                    ?>
                </div>

                <h3 class="section-title"><i class="fas fa-clipboard-list" style="color: var(--brand-blue);"></i> Special Needs / Care Instructions</h3>
                <div class="text-box" style="border-left-color: var(--brand-yellow);">
                    <?php 
                        if (empty($special_needs)) {
                            echo "<span style='color: #94a3b8; font-style: italic;'><i class='fas fa-info-circle'></i> No special care instructions provided.</span>";
                        } else {
                            echo htmlspecialchars($special_needs); 
                        }
                    ?>
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