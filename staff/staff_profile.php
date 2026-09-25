<?php
session_start();
require_once '../db_supabase.php';

// 1. SECURITY: Check if admin, manager, or vet is logged in
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';
$is_authorized = isset($_SESSION['logged_in'])
    && in_array($current_role, ['admin', 'manager', 'vet'], true);

if (!$is_authorized) {
    header("Location: stafflogin.php");
    exit;
}

// CSRF protection for profile changes
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Safely get the user ID
$user_id = (int)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? $_SESSION['id'] ?? 0);

$success_msg = "";
$error_msg = "";

// 2. HANDLE PROFILE PICTURE UPLOAD
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_image'])) {
    $submitted_csrf = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrf_token, $submitted_csrf)) {
        $error_msg = "Invalid request. Please refresh the page and try again.";
    } elseif ($user_id <= 0) {
        $error_msg = "Invalid user session.";
    } elseif ($_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
        $error_msg = "Failed to upload image. Please try again.";
    } elseif ($_FILES['profile_image']['size'] > 2 * 1024 * 1024) {
        $error_msg = "Profile picture must not exceed 2MB.";
    } else {
        $upload_dir = '../uploads/';

        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $tmp_name = $_FILES['profile_image']['tmp_name'];
        $original_name = $_FILES['profile_image']['name'] ?? '';
        $file_ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        $allowed_mimes = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif'
        ];

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = $finfo ? finfo_file($finfo, $tmp_name) : false;
        if ($finfo) {
            finfo_close($finfo);
        }

        if (!isset($allowed_mimes[$file_ext]) || $mime_type !== $allowed_mimes[$file_ext]) {
            $error_msg = "Invalid image file. Only JPG, PNG, and GIF images are allowed.";
        } else {
            try {
                $new_filename = 'staff_' . $user_id . '_' . bin2hex(random_bytes(8)) . '.' . $file_ext;
                $target_path = $upload_dir . $new_filename;

                if (!move_uploaded_file($tmp_name, $target_path)) {
                    $error_msg = "Failed to upload image. Please check folder permissions.";
                } else {
                    $update_img_stmt = $pdo->prepare("
                        UPDATE users
                        SET profile_image = :profile_image
                        WHERE id = :user_id
                    ");

                    $update_img_stmt->execute([
                        ':profile_image' => $target_path,
                        ':user_id' => $user_id
                    ]);

                    $success_msg = "Profile picture updated successfully!";
                }
            } catch (PDOException $e) {
                error_log("Profile image update failed: " . $e->getMessage());
                $error_msg = "Database error. Failed to save image path.";
            } catch (Throwable $e) {
                error_log("Profile image upload failed: " . $e->getMessage());
                $error_msg = "Unable to process the profile picture.";
            }
        }
    }
}

// 3. HANDLE PROFILE DETAILS UPDATE (TINANGGAL NA ANG EMAIL)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $submitted_csrf = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrf_token, $submitted_csrf)) {
        $error_msg = "Invalid request. Please refresh the page and try again.";
    }

    $new_name = trim($_POST['full_name'] ?? '');
    $new_username = trim($_POST['username'] ?? '');
    $new_contact = trim($_POST['contact_number'] ?? '');

    if ($submitted_csrf !== '' && hash_equals($csrf_token, $submitted_csrf) && $user_id > 0) {
        try {
            // Check muna kung may kaparehas na username ang iba
            $check_user_stmt = $pdo->prepare("
                SELECT id
                FROM users
                WHERE username = :username
                  AND id != :user_id
                LIMIT 1
            ");

            $check_user_stmt->execute([
                ':username' => $new_username,
                ':user_id' => $user_id
            ]);

            if ($check_user_stmt->fetchColumn()) {
                $error_msg = "Username is already taken by another account. Please choose another one.";
            } else {
                // Update kasama ang username, walang email
                $update_info_stmt = $pdo->prepare("
                    UPDATE users
                    SET full_name = :full_name,
                        username = :username,
                        contact_number = :contact_number
                    WHERE id = :user_id
                ");

                $update_info_stmt->execute([
                    ':full_name' => $new_name,
                    ':username' => $new_username,
                    ':contact_number' => $new_contact,
                    ':user_id' => $user_id
                ]);

                $_SESSION['staff_name'] = $new_name;
                $_SESSION['user_name'] = $new_name;
                $success_msg = "Profile details saved successfully!";
            }
        } catch (PDOException $e) {
            error_log("Profile details update failed: " . $e->getMessage());
            $error_msg = "Failed to update profile details.";
        }
    }
}

// 4. FETCH CURRENT DATA (Buong details mula sa Database)
$staff_data = null;

if ($user_id > 0) {
    try {
        $get_staff_stmt = $pdo->prepare("
            SELECT *
            FROM users
            WHERE id = :user_id
            LIMIT 1
        ");

        $get_staff_stmt->execute([
            ':user_id' => $user_id
        ]);

        $staff_data = $get_staff_stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        error_log("Staff data query failed: " . $e->getMessage());
    }
}

// Siguradong full_name ang gagamitin at ilalabas din natin ang username
$full_display_name = $staff_data['full_name'] ?? $_SESSION['staff_name'] ?? 'Personnel';
$profile_img_path = $staff_data['profile_image'] ?? '';
$staff_username = $staff_data['username'] ?? '';
$staff_contact = $staff_data['contact_number'] ?? '';
$staff_position = $staff_data['position'] ?? strtoupper($current_role);

// Format first name at initial para sa display
$clean_name = trim(str_replace('Dr. ', '', $full_display_name), " ,");
$first_name_only = explode(' ', $clean_name)[0];
$first_letter = strtoupper(substr($clean_name, 0, 1));

// Siguraduhing may "Dr. " sa unahan ng full name para sa formal displays kung VET siya
if ($current_role === 'vet') {
    $display_with_title = (stripos($full_display_name, 'Dr.') === false)
        ? 'Dr. ' . $full_display_name
        : $full_display_name;
} else {
    $display_with_title = $full_display_name;
}

// --- FETCH NOTIFICATIONS ---
$admin_notifications = [];
$unread_count = 0;

try {
    $admin_notif_stmt = $pdo->query("
        SELECT *
        FROM admin_notifications
        WHERE is_read = 0
        ORDER BY created_at DESC
    ");

    $admin_notifications = $admin_notif_stmt->fetchAll(PDO::FETCH_ASSOC);
    $unread_count = count($admin_notifications);
} catch (PDOException $e) {
    error_log("Admin notifications query failed: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | Boogie's Pet Care</title>
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

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif;}
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
        .nav-item i { width: 32px; font-size: 18px; transition: transform 0.3s; text-align: center;}
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
        .main-content { margin-left: 260px; width: calc(100% - 260px); min-height: 100vh; display: flex; flex-direction: column; }
        header { background: var(--white); height: 70px; padding: 0 40px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 1000;}
        .breadcrumb { font-size: 14px; font-weight: 700; color: var(--sidebar-navy); display: flex; align-items: center; gap: 8px; text-transform: uppercase; }

        /* --- NOTIFICATIONS & PROFILE UI --- */
        .top-right-actions { display: flex; align-items: center; gap: 20px; }
        .notif-wrapper { position: relative; display: inline-flex; align-items: center; cursor: pointer; }
        .notif-badge { position: absolute; top: -5px; right: -8px; background: #e11d48; color: white; border-radius: 50%; padding: 2px 6px; font-size: 10px; font-weight: bold; }
        .notif-dropdown { display: none; position: absolute; right: 0; top: 35px; width: 320px; background: white; border: 1px solid var(--border); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); border-radius: 8px; z-index: 1000; text-align: left; }
        .notif-dropdown.show { display: block; }
        .notif-header { padding: 12px 15px; border-bottom: 1px solid var(--border); font-weight: 700; font-size: 13px; color: var(--sidebar-navy); display: flex; justify-content: space-between; align-items: center;}
        .notif-body { max-height: 300px; overflow-y: auto; }
        .notif-item { padding: 12px 15px; border-bottom: 1px solid #f1f5f9; font-size: 13px; line-height: 1.4; color: #475569; }
        .notif-item:hover { background: #f8fafc; }
        
        .role-label {
            display: flex; align-items: center; gap: 6px; background: rgba(255, 204, 0, 0.15); 
            color: #d97706; padding: 4px 12px; border-radius: 6px; font-size: 11px;
            font-weight: 800; letter-spacing: 0.5px; border: 1px solid rgba(255, 204, 0, 0.3);
            text-transform: uppercase;
        }
        .profile-wrapper { position: relative; display: flex; align-items: center; gap: 15px; border-left: 1px solid var(--border); padding-left: 20px; cursor: pointer; user-select: none; }
        .top-avatar { width: 35px; height: 35px; border-radius: 50%; object-fit: cover; border: 2px solid var(--brand-blue); }
        .top-avatar-fallback { width: 35px; height: 35px; border-radius: 50%; background: var(--sidebar-navy); color: var(--brand-yellow); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 14px; border: 2px solid var(--brand-yellow); box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        
        .profile-dropdown { display: none; position: absolute; right: 0; top: 45px; width: 200px; background: white; border: 1px solid var(--border); box-shadow: 0 10px 25px rgba(0,0,0,0.1); border-radius: 12px; z-index: 1000; overflow: hidden; text-align: left; }
        .profile-dropdown.show { display: block; }
        .profile-item { padding: 12px 15px; display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--text-main); text-decoration: none; transition: 0.2s; font-weight: 600;}
        .profile-item:hover { background: #f8fafc; color: var(--brand-blue); }

        /* --- PROFILE CARD STYLES --- */
        .container { padding: 40px; flex-grow: 1; display: flex; flex-direction: column; align-items: center; }
        
        .profile-card { 
            background: var(--white); border-radius: 20px; padding: 40px; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.03); width: 100%; max-width: 700px; 
            border: 1px solid var(--border); border-top: 5px solid var(--sidebar-navy);
        }
        
        .card-header { text-align: center; margin-bottom: 30px; border-bottom: 1px solid #f1f5f9; padding-bottom: 20px;}
        .card-header h1 { font-size: 24px; font-weight: 800; color: var(--sidebar-navy); margin-bottom: 5px;}
        .card-header p { font-size: 14px; color: var(--text-muted); font-weight: 500;}
        
        .avatar-upload-container { position: relative; width: 140px; height: 140px; margin: 0 auto 30px; }
        .avatar-preview { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; border: 4px solid var(--white); box-shadow: 0 8px 20px rgba(0, 21, 41, 0.15); }
        .avatar-fallback-large { width: 100%; height: 100%; border-radius: 50%; background: var(--sidebar-navy); color: var(--brand-yellow); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 45px; border: 4px solid var(--white); box-shadow: 0 8px 20px rgba(0, 21, 41, 0.15); }
        
        .avatar-edit-btn { 
            position: absolute; bottom: 5px; right: 5px; background: var(--brand-yellow); 
            color: var(--sidebar-navy); width: 40px; height: 40px; border-radius: 50%; 
            display: flex; align-items: center; justify-content: center; cursor: pointer; 
            border: 3px solid white; transition: 0.3s; box-shadow: 0 4px 10px rgba(0,0,0,0.1); 
            font-size: 16px;
        }
        .avatar-edit-btn:hover { transform: scale(1.1); background: #e6b800;}

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; text-align: left;}
        
        .form-group { text-align: left; }
        .form-group.full-width { grid-column: span 2; }
        .form-group label { display: block; font-size: 14px; font-weight: 700; color: var(--sidebar-navy); margin-bottom: 8px; }
        .form-group input { 
            width: 100%; padding: 14px 18px; border: 2px solid var(--border); 
            border-radius: 10px; font-size: 14px; outline: none; transition: 0.2s; 
            background: #f8fafc; font-family: 'Poppins', sans-serif;
        }
        .form-group input:focus { border-color: var(--sidebar-navy); background: white; }
        
        .form-group input[readonly] { background: #e2e8f0; color: #64748b; cursor: not-allowed; border-color: #cbd5e1; }
        
        .btn-save { 
            background: var(--brand-yellow); color: var(--sidebar-navy); border: none; 
            padding: 15px 25px; border-radius: 10px; font-weight: 800; cursor: pointer; 
            transition: 0.3s; width: 100%; font-size: 15px; margin-top: 20px; 
            font-family: 'Poppins', sans-serif; box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            display: flex; align-items: center; justify-content: center; gap: 10px;
        }
        .btn-save:hover { transform: translateY(-2px); box-shadow: 0 8px 15px rgba(0,0,0,0.1); }

        .alert { padding: 15px; border-radius: 10px; margin-bottom: 25px; font-size: 14px; font-weight: 600; text-align: center; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        footer { text-align: center; padding: 30px; color: var(--text-muted); font-size: 12px; margin-top: auto; }
        
        @media (max-width: 600px) {
            .form-grid { grid-template-columns: 1fr; }
            .form-group.full-width { grid-column: span 1; }
        }

        /* ===== EXTRA MOBILE RESPONSIVENESS ===== */
        @media (max-width: 900px) {
            .sidebar {
                width: 220px;
            }

            .main-content {
                margin-left: 220px;
                width: calc(100% - 220px);
            }

            header {
                padding: 0 22px;
            }

            .container {
                padding: 28px 22px;
            }

            .profile-card {
                max-width: 100%;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-group.full-width {
                grid-column: span 1;
            }
        }

        @media (max-width: 680px) {
            body {
                display: block;
                overflow-x: hidden;
            }

            .sidebar {
                position: relative;
                width: 100%;
                height: auto;
            }

            .sidebar-header {
                padding: 18px 15px;
            }

            .sidebar-logo-img {
                width: 52px;
                margin-bottom: 6px;
            }

            .sidebar-header h2 {
                font-size: 13px;
            }

            .nav-links {
                padding: 8px 10px 12px;
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 6px;
            }

            .nav-item {
                padding: 10px 6px;
                justify-content: center;
                flex-direction: column;
                gap: 4px;
                text-align: center;
                font-size: 9px;
                line-height: 1.2;
            }

            .nav-item i {
                width: auto;
                font-size: 16px;
            }

            .nav-item:hover {
                transform: none;
            }

            .nav-item.active::before {
                left: 8px;
                right: 8px;
                top: auto;
                bottom: 2px;
                width: auto;
                height: 3px;
                border-radius: 4px;
            }

            .main-content {
                margin-left: 0;
                width: 100%;
                min-height: auto;
            }

            header {
                height: auto;
                min-height: 64px;
                padding: 11px 14px;
                gap: 10px;
                flex-wrap: wrap;
            }

            .breadcrumb {
                font-size: 11px;
                line-height: 1.35;
                flex: 1 1 100%;
            }

            .top-right-actions {
                width: 100%;
                justify-content: flex-end;
                gap: 12px;
            }

            .role-label {
                font-size: 9px;
                padding: 4px 8px;
            }

            .profile-wrapper {
                gap: 8px;
                padding-left: 10px;
            }

            .profile-wrapper > span {
                max-width: 145px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
                font-size: 12px !important;
            }

            .top-avatar,
            .top-avatar-fallback {
                width: 32px;
                height: 32px;
                font-size: 12px;
            }

            .notif-dropdown {
                position: fixed;
                top: 115px;
                right: 10px;
                left: 10px;
                width: auto;
            }

            .profile-dropdown {
                right: 0;
                top: 44px;
            }

            .container {
                padding: 22px 13px 18px;
            }

            .profile-card {
                padding: 24px 16px;
                border-radius: 15px;
            }

            .card-header {
                margin-bottom: 22px;
                padding-bottom: 16px;
            }

            .card-header h1 {
                font-size: 21px;
                line-height: 1.3;
            }

            .card-header p {
                font-size: 12px;
                line-height: 1.5;
            }

            .avatar-upload-container {
                width: 112px;
                height: 112px;
                margin-bottom: 24px;
            }

            .avatar-fallback-large {
                font-size: 36px;
            }

            .avatar-edit-btn {
                width: 36px;
                height: 36px;
                font-size: 14px;
                bottom: 2px;
                right: 2px;
            }

            .form-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .form-group.full-width {
                grid-column: span 1;
            }

            .form-group label {
                font-size: 12px;
                line-height: 1.4;
            }

            .form-group input {
                min-height: 46px;
                padding: 11px 12px;
                font-size: 14px;
            }

            .btn-save {
                min-height: 48px;
                font-size: 13px;
                padding: 13px 16px;
            }

            .alert {
                padding: 12px 13px;
                font-size: 12px;
                line-height: 1.5;
                overflow-wrap: anywhere;
            }

            footer {
                padding: 22px 13px;
                font-size: 10px;
            }
        }

        @media (max-width: 400px) {
            .nav-links {
                grid-template-columns: repeat(2, 1fr);
            }

            .nav-item {
                flex-direction: row;
                justify-content: flex-start;
                gap: 7px;
                padding: 9px 10px;
                font-size: 10px;
            }

            .nav-item i {
                width: 18px;
                font-size: 14px;
            }

            .profile-wrapper > span {
                max-width: 95px;
            }

            .profile-card {
                padding: 20px 12px;
            }

            .avatar-upload-container {
                width: 100px;
                height: 100px;
            }

            .card-header h1 {
                font-size: 19px;
            }
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
            <a href="pets.php" class="nav-item"><i class="fas fa-paw"></i> Patients</a>
            <a href="tasks.php" class="nav-item"><i class="fas fa-tasks"></i> My Tasks</a>
        </nav>
    </aside>

    <main class="main-content">
        <header>
            <div class="breadcrumb">
                <i class="fas fa-user-circle" style="color: var(--brand-blue);"></i> 
                <?php echo ($current_role == 'vet') ? 'Veterinarian Portal' : 'Manager Portal'; ?> / My Profile
            </div>
            
            <div class="top-right-actions">
                <div class="notif-wrapper" onclick="toggleNotif(event)">
                    <i class="fa-solid fa-bell" style="font-size: 20px; color: var(--text-muted);"></i>
                    <span id="staff-notif-badge" class="notif-badge" style="display: <?php echo ($unread_count > 0) ? 'inline-block' : 'none'; ?>;"><?php echo $unread_count; ?></span>
                    
                    <div class="notif-dropdown" id="notifBox" onclick="event.stopPropagation()">
                        <div class="notif-header">
                            Alerts
                            <a href="mark_notifications_read.php" id="mark-read-link" class="mark-read-btn" style="display: <?php echo ($unread_count > 0) ? 'inline-block' : 'none'; ?>;">Mark all read</a>
                        </div>
                        <div class="notif-body" id="staff-notif-list">
                            <?php if($unread_count > 0 && !empty($admin_notifications)): ?>
                                <?php foreach($admin_notifications as $notif): ?>
                                    <div class="notif-item">
                                        <i class="fa-solid fa-circle-exclamation" style="color: #ef4444; margin-right: 5px;"></i>
                                        <?php echo htmlspecialchars($notif['message']); ?>
                                        <br><small style="color: #94a3b8; font-size: 11px;"><?php echo date('M d, g:i A', strtotime($notif['created_at'])); ?></small>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="notif-empty">No new alerts.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="profile-wrapper" onclick="toggleProfile(event)">
                    <div class="role-label">
                        <i class="<?php echo ($current_role == 'vet') ? 'fas fa-user-md' : 'fas fa-user-tie'; ?>"></i> 
                        <?php echo strtoupper($current_role); ?>
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
            <div class="profile-card">
                
                <div class="card-header">
                    <h1>My Profile</h1>
                    <p>Manage your account settings and preferences</p>
                </div>

                <?php if (!empty($success_msg)): ?>
                    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success_msg; ?></div>
                <?php endif; ?>
                
                <?php if (!empty($error_msg)): ?>
                    <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo $error_msg; ?></div>
                <?php endif; ?>

                <form action="" method="POST" enctype="multipart/form-data" id="imageForm" style="display:none;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="file" id="imageUpload" name="profile_image" accept=".png, .jpg, .jpeg, .gif" onchange="document.getElementById('imageForm').submit()">
                </form>

                <div class="avatar-upload-container">
                    <?php if(!empty($profile_img_path) && file_exists($profile_img_path)): ?>
                        <img src="<?php echo htmlspecialchars($profile_img_path); ?>" class="avatar-preview" alt="Profile">
                    <?php else: ?>
                        <div class="avatar-fallback-large"><?php echo $first_letter; ?></div>
                    <?php endif; ?>
                    
                    <label for="imageUpload" class="avatar-edit-btn" title="Change Profile Picture">
                        <i class="fas fa-camera"></i>
                    </label>
                </div>

                <form action="" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label>Full Name</label>
                            <input type="text" name="full_name" value="<?php echo htmlspecialchars($full_display_name); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Username (Used for Login)</label>
                            <input type="text" name="username" value="<?php echo htmlspecialchars($staff_username); ?>" required>
                        </div>

                        <div class="form-group">
                            <label>Contact Number</label>
                            <input type="text" name="contact_number" inputmode="numeric" value="<?php echo htmlspecialchars($staff_contact); ?>" placeholder="e.g. 09123456789">
                        </div>

                        <div class="form-group full-width">
                            <label>Clinic Position</label>
                            <input type="text" value="<?php echo htmlspecialchars($staff_position); ?>" readonly>
                        </div>
                    </div>
                    
                    <button type="submit" name="update_profile" class="btn-save"><i class="fas fa-save"></i> Save Profile Details</button>
                </form>
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
        
        let previousUnreadCount = <?php echo $unread_count; ?>;
        
        function fetchAdminNotifs() {
            fetch('../admin/get_admin_notifs.php', { credentials: 'same-origin' })
                .then(response => response.json())
                .then(data => {
                    const badge = document.getElementById('staff-notif-badge');
                    const notifList = document.getElementById('staff-notif-list');
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
                        notifList.innerHTML = '<div class="notif-empty">No new alerts.</div>';
                    }
                })
                .catch(error => console.error('Error fetching notifications:', error));
        }

        setInterval(fetchAdminNotifs, 3000);
    </script>
</body>
</html>