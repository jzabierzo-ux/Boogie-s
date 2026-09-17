<?php
session_start();
include '../db_connect.php'; 

// --- SECURITY CHECK (FIXED PARA SA VET/STAFF) ---
$is_admin_or_supervisor = isset($_SESSION['logged_in']) && in_array(strtolower(trim($_SESSION['role'] ?? '')), ['admin', 'supervisor', 'staff']);
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

// Linisin ang pangalan para sa Initial
$clean_name = trim(str_replace('Dr. ', '', $full_display_name), " ,"); 
$first_letter = strtoupper(substr($clean_name, 0, 1)); 

// Display with title
$display_with_title = (stripos($full_display_name, 'Dr.') === false) ? 'Dr. ' . $full_display_name : $full_display_name;

// --- FETCH NOTIFICATIONS ---
$admin_notif_query = mysqli_query($conn, "SELECT * FROM admin_notifications WHERE is_read = 0 ORDER BY created_at DESC");
$unread_count = ($admin_notif_query) ? mysqli_num_rows($admin_notif_query) : 0;

// Check ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid Patient ID.");
}

$pet_id = $_GET['id'];

// --- UPDATE LOGIC ---
if (isset($_POST['update_medical'])) {
    $updated_history = mysqli_real_escape_string($conn, $_POST['medical_history']);
    $updated_needs = mysqli_real_escape_string($conn, $_POST['special_needs']);
    
    $update_query = "UPDATE pets SET medical_history = ?, special_needs = ? WHERE id = ?";
    $update_stmt = mysqli_prepare($conn, $update_query);
    mysqli_stmt_bind_param($update_stmt, "ssi", $updated_history, $updated_needs, $pet_id);
    
    if (mysqli_stmt_execute($update_stmt)) {
        header("Location: view_records.php?id=" . $pet_id);
        exit;
    } else {
        $error_msg = "Error updating records.";
    }
}

// Fetch pet data
$query = "SELECT * FROM pets WHERE id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $pet_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (!$row = mysqli_fetch_assoc($result)) {
    die("Patient not found.");
}

$p_name = $row['name'] ?? 'Unknown';
$med_history = $row['medical_history'] ?? '';
$special_needs = $row['special_needs'] ?? ''; 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Medical Records | Boogie's Pet Care</title>
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
        
        .nav-links { flex-grow: 1; padding: 20px 15px; display: flex; flex-direction: column; gap: 5px; }
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

        /* --- MAIN CONTENT --- */
        .main-content { margin-left: 260px; width: calc(100% - 260px); min-height: 100vh; display: flex; flex-direction: column;}
        header { background: var(--white); height: 70px; padding: 0 40px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 1000; box-shadow: 0 1px 10px rgba(0,0,0,0.02);}
        .breadcrumb { font-size: 14px; font-weight: 700; color: var(--sidebar-navy); display: flex; align-items: center; gap: 8px; }
        
        .top-right-actions { display: flex; align-items: center; gap: 20px; }
        .notif-wrapper { position: relative; cursor: pointer; }
        .notif-badge { position: absolute; top: -5px; right: -8px; background: #ef4444; color: white; border-radius: 50%; padding: 2px 6px; font-size: 10px; font-weight: 800; }
        .notif-dropdown { display: none; position: absolute; right: 0; top: 35px; width: 320px; background: white; border: 1px solid var(--border); box-shadow: 0 10px 25px rgba(0,0,0,0.1); border-radius: 12px; z-index: 1000; overflow: hidden; }
        .notif-dropdown.show { display: block; }
        .notif-header { padding: 12px 15px; border-bottom: 1px solid var(--border); font-weight: 700; font-size: 13px; display: flex; justify-content: space-between; align-items: center; background: #f8fafc;}
        .notif-item { padding: 12px 15px; border-bottom: 1px solid #f1f5f9; font-size: 13px; line-height: 1.4; color: #475569; }

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

        .header-actions { margin-bottom: 25px; }
        .btn-back { 
            background: var(--white); color: var(--sidebar-navy); padding: 10px 20px; 
            border-radius: 8px; border: 1px solid var(--border); text-decoration: none; 
            font-weight: 700; display: inline-flex; align-items: center; gap: 8px; 
            transition: 0.2s; font-size: 14px;
        }
        .btn-back:hover { background: var(--sidebar-navy); color: var(--brand-yellow); }

        .card { background: var(--white); border-radius: 20px; border: 1px solid var(--border); padding: 40px; box-shadow: 0 4px 10px rgba(0,0,0,0.03); border-top: 5px solid var(--sidebar-navy);}
        .pet-title { font-size: 22px; font-weight: 800; margin-bottom: 30px; color: var(--sidebar-navy); display: flex; align-items: center; gap: 12px; border-bottom: 2px solid #f8fafc; padding-bottom: 20px; }

        .form-group { margin-bottom: 25px; }
        .form-group label { display: block; font-weight: 700; margin-bottom: 10px; color: var(--sidebar-navy); font-size: 15px; }
        .form-group textarea { width: 100%; padding: 15px; border: 2px solid var(--border); border-radius: 10px; outline: none; font-size: 14px; resize: vertical; min-height: 200px; line-height: 1.6; background: #f8fafc; transition: 0.2s; }
        .form-group textarea:focus { border-color: var(--sidebar-navy); background: #fff; }

        .btn-submit { background: var(--brand-yellow); color: var(--sidebar-navy); border: none; padding: 14px 30px; border-radius: 8px; cursor: pointer; font-weight: 800; font-size: 15px; transition: 0.2s; width: 100%; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 6px 12px rgba(0,0,0,0.1); }
        
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
            <a href="pets.php" class="nav-item active"><i class="fas fa-paw"></i> Patients</a>
            <a href="tasks.php" class="nav-item"><i class="fas fa-tasks"></i> My Tasks</a>
        </nav>
    </aside>

    <main class="main-content">
        <header>
            <div class="breadcrumb">
                <i class="fas fa-paw" style="color: var(--brand-blue);"></i> 
                Veterinarian Portal / Edit Records
            </div>
            
            <div class="top-right-actions">
                <div class="notif-wrapper" onclick="toggleNotif(event)">
                    <i class="fa-solid fa-bell" style="font-size: 20px; color: var(--text-muted);"></i>
                    <?php if($unread_count > 0): ?>
                        <span id="admin-notif-badge" class="notif-badge"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                    <div class="notif-dropdown" id="notifBox" onclick="event.stopPropagation()">
                        <div class="notif-header">Clinic Alerts</div>
                        <div class="notif-body">
                            <?php if($unread_count > 0 && $admin_notif_query): ?>
                                <?php while($notif = mysqli_fetch_assoc($admin_notif_query)): ?>
                                    <div class="notif-item"><?php echo htmlspecialchars($notif['message']); ?></div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div style="padding: 20px; text-align: center; color: var(--text-muted);">No new alerts.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="profile-wrapper" onclick="toggleProfile(event)">
                    <div class="role-label"><i class="fas fa-user-md"></i> VET</div>
                    <?php if (!empty($profile_img_path) && file_exists($profile_img_path)): ?>
                        <img src="<?php echo htmlspecialchars($profile_img_path); ?>" class="top-avatar">
                    <?php else: ?>
                        <div class="top-avatar-fallback"><?php echo $first_letter; ?></div>
                    <?php endif; ?>
                    <span style="font-size: 14px; font-weight: 700; color: var(--sidebar-navy);"><?php echo htmlspecialchars($display_with_title); ?></span>
                    <div class="profile-dropdown" id="profileBox">
                        <a href="staff_profile.php" class="profile-item"><i class="fas fa-user-circle"></i> My Profile</a>
                        <a href="../logout.php" class="profile-item logout-text" style="color: #ef4444;"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </header>

        <div class="container">
            <div class="header-actions">
                <a href="view_records.php?id=<?php echo $pet_id; ?>" class="btn-back"><i class="fas fa-arrow-left"></i> Cancel & Go Back</a>
            </div>

            <div class="card">
                <div class="pet-title">
                    <div style="background: #f1f5f9; width: 45px; height: 45px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-edit" style="color: var(--sidebar-navy);"></i>
                    </div>
                    Edit Medical Profile: <?php echo htmlspecialchars($p_name); ?>
                </div>

                <form method="POST" action="">
                    <div class="form-group">
                        <label><i class="fas fa-notes-medical" style="color: #ef4444;"></i> Full Medical History</label>
                        <textarea name="medical_history" placeholder="Enter complete medical history..."><?php echo htmlspecialchars($med_history); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-clipboard-list" style="color: var(--brand-blue);"></i> Special Needs / Care Instructions</label>
                        <textarea name="special_needs" placeholder="E.g., Allergies, daily maintenance..."><?php echo htmlspecialchars($special_needs); ?></textarea>
                    </div>

                    <button type="submit" name="update_medical" class="btn-submit">Save Medical Records</button>
                </form>
            </div>
        </div>
        
        <footer>© <?php echo date("Y"); ?> BOOGIE'S PET CARE & SERVICES</footer>
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
        window.onclick = function() {
            document.getElementById("notifBox").classList.remove("show");
            document.getElementById("profileBox").classList.remove("show");
        }
    </script>
</body>
</html>