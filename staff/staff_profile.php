<?php
session_start();
require_once '../db_supabase.php';

// 1. SECURITY: accept the canonical role names assigned by stafflogin.php
$current_role_raw = strtolower(trim((string)($_SESSION['role'] ?? '')));
$staff_role_aliases = [
    'vet' => 'veterinarian',
    'veterinary' => 'veterinarian',
    'veterinarian' => 'veterinarian',
    'vet assistant' => 'vet_assistant',
    'veterinary assistant' => 'vet_assistant',
    'veterinary_assistant' => 'vet_assistant',
    'vet_assistant' => 'vet_assistant',
    'vet nurse' => 'vet_nurse',
    'veterinary nurse' => 'vet_nurse',
    'veterinary_nurse' => 'vet_nurse',
    'vet_nurse' => 'vet_nurse'
];
$current_role = $staff_role_aliases[$current_role_raw] ?? $current_role_raw;
$session_logged_in = in_array($_SESSION['logged_in'] ?? null, [true, 1, '1'], true)
    || in_array($_SESSION['staff_logged_in'] ?? null, [true, 1, '1'], true);
$is_authorized = $session_logged_in
    && in_array($current_role, ['admin', 'manager', 'veterinarian', 'vet_assistant', 'vet_nurse'], true);

if (!$is_authorized) {
    header('Location: stafflogin.php');
    exit;
}

// CSRF protection for profile changes
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Safely get the user ID
$user_id = (int)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? $_SESSION['id'] ?? 0);


/** Resolve a stored profile-image value to a browser URL without trusting filesystem paths. */
function resolveStaffProfileImageUrl($storedPath): string
{
    $storedPath = trim((string)$storedPath);
    if ($storedPath === '') {
        return '';
    }

    if (filter_var($storedPath, FILTER_VALIDATE_URL)) {
        $scheme = strtolower((string)parse_url($storedPath, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) ? $storedPath : '';
    }

    $normalized = str_replace('\\', '/', $storedPath);
    $filename = basename($normalized);
    if ($filename === '' || $filename === '.' || $filename === '..') {
        return '';
    }

    $filePath = __DIR__ . '/../uploads/' . $filename;
    if (!is_file($filePath)) {
        return '';
    }

    return '../uploads/' . rawurlencode($filename);
}

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
        $upload_dir = __DIR__ . '/../uploads/';

        if (!is_dir($upload_dir) && !@mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
            $error_msg = 'The upload folder is not available. On Vercel, configure persistent Supabase Storage for profile pictures.';
        } elseif (!is_writable($upload_dir)) {
            $error_msg = 'The upload folder is not writable. On Vercel, configure persistent Supabase Storage for profile pictures.';
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

        if ($error_msg !== '') {
            // Keep the directory/deployment error above.
        } elseif (!is_uploaded_file($tmp_name) || !isset($allowed_mimes[$file_ext]) || $mime_type !== $allowed_mimes[$file_ext]) {
            $error_msg = "Invalid image file. Only JPG, PNG, and GIF images are allowed.";
        } else {
            try {
                $new_filename = 'staff_' . $user_id . '_' . bin2hex(random_bytes(8)) . '.' . $file_ext;
                $target_path = $upload_dir . $new_filename;
                $public_image_path = '../uploads/' . $new_filename;

                if (!move_uploaded_file($tmp_name, $target_path)) {
                    $error_msg = "Failed to upload image. Please check folder permissions.";
                } else {
                    $update_img_stmt = $pdo->prepare("
                        UPDATE users
                        SET profile_image = :profile_image
                        WHERE id = :user_id
                    ");

                    $update_img_stmt->execute([
                        ':profile_image' => $public_image_path,
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
        if ($new_name === '' || $new_username === '') {
            $error_msg = 'Full name and username are required.';
        } else {
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
}



// 4. HANDLE PASSWORD CHANGE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $submitted_csrf = $_POST['csrf_token'] ?? '';
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (!hash_equals($csrf_token, (string)$submitted_csrf)) {
        $error_msg = "Invalid request. Please refresh the page and try again.";
    } elseif ($user_id <= 0) {
        $error_msg = "Invalid user session.";
    } elseif ($current_password === '' || $new_password === '' || $confirm_password === '') {
        $error_msg = "Please complete all password fields.";
    } elseif (strlen($new_password) < 8) {
        $error_msg = "New password must be at least 8 characters long.";
    } elseif ($new_password !== $confirm_password) {
        $error_msg = "New password and confirmation password do not match.";
    } else {
        try {
            $password_stmt = $pdo->prepare("SELECT password FROM users WHERE id = :user_id LIMIT 1");
            $password_stmt->execute([':user_id' => $user_id]);
            $password_row = $password_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$password_row || empty($password_row['password'])) {
                $error_msg = "Unable to verify your current password.";
            } elseif (!password_verify($current_password, $password_row['password'])) {
                $error_msg = "Current password is incorrect.";
            } else {
                $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);

                $update_password_stmt = $pdo->prepare("\n                    UPDATE users\n                    SET password = :password\n                    WHERE id = :user_id\n                ");

                $update_password_stmt->execute([
                    ':password' => $new_password_hash,
                    ':user_id' => $user_id
                ]);

                $success_msg = "Password changed successfully!";
            }
        } catch (PDOException $e) {
            error_log("Password update failed: " . $e->getMessage());
            $error_msg = "Failed to change your password. Please try again.";
        } catch (Throwable $e) {
            error_log("Password update failed: " . $e->getMessage());
            $error_msg = "Unable to change your password right now.";
        }
    }
}

// 5. FETCH CURRENT DATA (Buong details mula sa Database)
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
$profile_img_url = resolveStaffProfileImageUrl($profile_img_path);
$staff_username = $staff_data['username'] ?? '';
$staff_contact = $staff_data['contact_number'] ?? '';
$staff_position = $staff_data['position'] ?? strtoupper($current_role);

// Format first name at initial para sa display
$clean_name = trim(str_replace('Dr. ', '', $full_display_name), " ,");
$first_name_only = explode(' ', $clean_name)[0];
$first_letter = strtoupper(substr($clean_name, 0, 1));

// Siguraduhing may "Dr. " sa unahan ng full name para sa formal displays kung VET siya
if ($current_role === 'veterinarian') {
    $display_with_title = (stripos($full_display_name, 'Dr.') === false)
        ? 'Dr. ' . $full_display_name
        : $full_display_name;
} else {
    $display_with_title = $full_display_name;
}

// Admin notifications are shown only to Admin/Manager accounts, never to Vet accounts.
$can_view_admin_notifications = in_array($current_role, ['admin', 'manager'], true);
$admin_notifications = [];
$unread_count = 0;

if ($can_view_admin_notifications) {
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


        /* ===== Mobile-flexible layout additions ===== */
        html { width: 100%; overflow-x: hidden; }
        body { width: 100%; max-width: 100%; }
        img { max-width: 100%; }
        input, select, textarea, button { max-width: 100%; }


        /* ===== CONSISTENT OFF-CANVAS MOBILE STAFF MENU ===== */
        .mobile-menu-btn {
            display: none;
            width: 40px;
            height: 40px;
            flex: 0 0 auto;
            border: 0;
            border-radius: 10px;
            background: var(--sidebar-navy);
            color: var(--brand-yellow);
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 18px;
        }
        .sidebar-overlay { display: none; }

        @media (max-width: 900px) {
            .sidebar {
                position: fixed !important;
                top: 0;
                left: 0;
                bottom: 0;
                width: 260px !important;
                height: 100vh !important;
                transform: translateX(-105%);
                transition: transform .25s ease;
                z-index: 2000;
                overflow-y: auto;
                box-shadow: 12px 0 30px rgba(0,0,0,.18);
            }

            .sidebar.mobile-open { transform: translateX(0); }

            .sidebar-overlay {
                display: none;
                position: fixed;
                inset: 0;
                background: rgba(0,31,63,.48);
                z-index: 1900;
            }

            .sidebar-overlay.show { display: block; }


            .sidebar-header { padding: 30px 20px; }
            .sidebar-logo-img { width: 70px; margin-bottom: 10px; }
            .sidebar-header h2 { font-size: 15px; }
            .nav-links {
                display: flex !important;
                flex-direction: column !important;
                padding: 20px 15px !important;
                gap: 5px !important;
            }
            .nav-item {
                flex-direction: row !important;
                justify-content: flex-start !important;
                padding: 14px 20px !important;
                gap: 0 !important;
                text-align: left !important;
                font-size: 14px !important;
                line-height: normal !important;
            }
            .nav-item i { width:32px !important; font-size:18px !important; }

            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
                min-width: 0;
            }

            header {
                height: 64px;
                min-height: 64px;
                padding: 0 14px;
                gap: 10px;
                width: 100%;
                flex-wrap: nowrap;
            }

            .mobile-menu-btn { display: inline-flex; }

            .breadcrumb {
                flex: 1 1 auto;
                min-width: 0;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
                font-size: 12px;
            }

            .top-right-actions {
                margin-left: auto;
                flex: 0 0 auto;
                gap: 10px;
                min-width: 0;
            }

            .role-label {
                padding: 4px 8px;
                font-size: 9px;
                white-space: nowrap;
            }

            .profile-wrapper {
                gap: 8px;
                padding-left: 10px;
                flex-shrink: 0;
                min-width: 0;
            }

            .profile-wrapper > span {
                max-width: 125px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
                font-size: 12px !important;
            }

            .top-avatar, .top-avatar-fallback {
                width: 32px;
                height: 32px;
                flex: 0 0 32px;
            }

            .notif-dropdown {
                position: fixed;
                top: 72px;
                left: 12px;
                right: 12px;
                width: auto;
                max-width: none;
            }

            .profile-dropdown {
                right: 0;
                max-width: calc(100vw - 24px);
            }

            .container {
                width: 100%;
                max-width: 100%;
                padding-left: 16px;
                padding-right: 16px;
            }
        }

        @media (max-width: 560px) {
            header { padding: 0 10px; }
            .mobile-menu-btn { width: 36px; height: 36px; font-size: 16px; border-radius: 9px; }
            .top-right-actions { gap: 6px; }
            .role-label { padding: 4px 7px; font-size: 8px; }
            .profile-wrapper { padding-left: 7px; gap: 6px; }
            .profile-wrapper > span { max-width: 100px; }
            .top-avatar, .top-avatar-fallback { width: 30px; height: 30px; flex-basis: 30px; }
        }

    

/* ================================================================
   FINAL MOBILE-FLEXIBLE STAFF LAYOUT
   Keeps desktop layout intact. Mobile only.
   ================================================================ */
html, body {
    max-width: 100%;
    width: 100%;
    overflow-x: hidden;
}

*, *::before, *::after {
    box-sizing: border-box;
}

img, svg, video, canvas {
    max-width: 100%;
}

button, input, select, textarea {
    font: inherit;
}

@media (max-width: 900px) {
    body {
        min-width: 0 !important;
        overflow-x: hidden !important;
    }

    /* Sidebar is an overlay on phones; it never pushes the page horizontally. */
    .sidebar {
        position: fixed !important;
        top: 64px !important;
        left: 0 !important;
        bottom: 0 !important;
        width: min(280px, 82vw) !important;
        height: auto !important;
        margin: 0 !important;
        transform: translateX(-105%) !important;
        transition: transform .24s ease !important;
        z-index: 2000 !important;
        overflow-y: auto !important;
        overflow-x: hidden !important;
        -webkit-overflow-scrolling: touch;
    }

    .sidebar.mobile-open {
        transform: translateX(0) !important;
    }

    .sidebar-overlay {
        position: fixed !important;
        top: 64px !important;
        left: 0 !important;
        right: 0 !important;
        bottom: 0 !important;
        background: rgba(0, 31, 63, .48) !important;
        z-index: 1900 !important;
        display: none !important;
    }

    .sidebar-overlay.show {
        display: block !important;
    }

    .sidebar-header {
        padding: 24px 18px 18px !important;
        flex: 0 0 auto !important;
    }

    .sidebar-logo-img {
        width: 68px !important;
        height: auto !important;
        margin-bottom: 8px !important;
    }

    .sidebar-header h2 {
        font-size: 14px !important;
        line-height: 1.2 !important;
        white-space: nowrap;
    }

    .nav-links {
        display: flex !important;
        flex-direction: column !important;
        gap: 5px !important;
        padding: 16px 14px 24px !important;
    }

    .nav-item,
    .nav-item:hover {
        display: flex !important;
        flex-direction: row !important;
        align-items: center !important;
        justify-content: flex-start !important;
        gap: 0 !important;
        width: 100% !important;
        min-height: 50px !important;
        padding: 12px 16px !important;
        margin: 0 !important;
        transform: none !important;
        font-size: 14px !important;
        line-height: 1.2 !important;
        text-align: left !important;
        white-space: nowrap;
    }

    .nav-item i {
        width: 32px !important;
        min-width: 32px !important;
        margin-right: 4px !important;
        font-size: 18px !important;
        text-align: center !important;
    }

    .nav-item.active::before {
        left: -14px !important;
        right: auto !important;
        top: 15% !important;
        bottom: auto !important;
        width: 5px !important;
        height: 70% !important;
        border-radius: 0 5px 5px 0 !important;
    }

    .main-content {
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        margin-left: 0 !important;
    }

    .main-content > header,
    header {
        position: sticky !important;
        top: 0 !important;
        z-index: 2100 !important;
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        min-height: 64px !important;
        height: 64px !important;
        padding: 0 12px !important;
        display: flex !important;
        align-items: center !important;
        gap: 10px !important;
        flex-wrap: nowrap !important;
        overflow: visible !important;
    }

    /* Pages where the menu button is outside the header (dashboard/tasks/etc.). */
    .mobile-header-no-button {
        padding-left: 62px !important;
    }

    .mobile-menu-btn {
        display: inline-flex !important;
        flex: 0 0 40px !important;
        width: 40px !important;
        height: 40px !important;
        min-width: 40px !important;
        padding: 0 !important;
        margin: 0 !important;
        border: 0 !important;
        border-radius: 10px !important;
        background: var(--sidebar-navy, #001f3f) !important;
        color: var(--brand-yellow, #ffcc00) !important;
        align-items: center !important;
        justify-content: center !important;
        cursor: pointer !important;
        z-index: 2200 !important;
    }

    .mobile-menu-btn i {
        color: var(--brand-yellow, #ffcc00) !important;
        font-size: 17px !important;
    }

    .breadcrumb {
        flex: 1 1 auto !important;
        min-width: 0 !important;
        max-width: 100% !important;
        display: flex !important;
        align-items: center !important;
        gap: 7px !important;
        overflow: hidden !important;
        white-space: nowrap !important;
        text-overflow: ellipsis !important;
        font-size: 12px !important;
        line-height: 1.2 !important;
    }

    .breadcrumb-text {
        min-width: 0 !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        white-space: nowrap !important;
    }

    .top-right-actions {
        display: flex !important;
        align-items: center !important;
        justify-content: flex-end !important;
        gap: 9px !important;
        margin-left: auto !important;
        flex: 0 0 auto !important;
        min-width: max-content !important;
    }

    .notif-wrapper,
    .profile-wrapper {
        flex: 0 0 auto !important;
        min-width: 0 !important;
    }

    .notif-wrapper > i {
        font-size: 19px !important;
    }

    .role-label {
        flex: 0 0 auto !important;
        white-space: nowrap !important;
        padding: 4px 8px !important;
        font-size: 9px !important;
    }

    .profile-wrapper {
        display: flex !important;
        align-items: center !important;
        gap: 7px !important;
        padding-left: 9px !important;
        border-left: 1px solid var(--border, #e2e8f0) !important;
        overflow: visible !important;
    }

    .top-avatar,
    .top-avatar-fallback {
        width: 32px !important;
        height: 32px !important;
        min-width: 32px !important;
        flex: 0 0 32px !important;
    }

    .profile-wrapper > span {
        min-width: 0 !important;
        max-width: 105px !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        white-space: nowrap !important;
        font-size: 12px !important;
        line-height: 1.2 !important;
    }

    .profile-dropdown {
        position: fixed !important;
        top: 70px !important;
        right: 12px !important;
        width: min(220px, calc(100vw - 24px)) !important;
        max-width: calc(100vw - 24px) !important;
        z-index: 3000 !important;
    }

    .notif-dropdown {
        position: fixed !important;
        top: 70px !important;
        left: 12px !important;
        right: 12px !important;
        width: auto !important;
        max-width: none !important;
        z-index: 3000 !important;
    }

    .container {
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
    }

    .card,
    .profile-card,
    .schedule-card,
    .form-card,
    .container > * {
        max-width: 100% !important;
        min-width: 0 !important;
    }

    input, select, textarea, button {
        max-width: 100%;
    }

    /* Prevent long names/services from forcing horizontal overflow. */
    h1, h2, h3, h4, p, span, td, th, label, a, strong {
        overflow-wrap: anywhere;
    }
}

@media (max-width: 560px) {
    .main-content > header,
    header {
        min-height: 60px !important;
        height: 60px !important;
        padding: 0 10px !important;
        gap: 7px !important;
    }

    .mobile-header-no-button {
        padding-left: 58px !important;
    }

    .mobile-menu-btn {
        flex-basis: 36px !important;
        width: 36px !important;
        height: 36px !important;
        min-width: 36px !important;
        border-radius: 9px !important;
    }

    .breadcrumb {
        font-size: 11px !important;
        gap: 6px !important;
    }

    .top-right-actions {
        gap: 6px !important;
    }

    .role-label {
        padding: 4px 7px !important;
        font-size: 8px !important;
    }

    .profile-wrapper {
        gap: 6px !important;
        padding-left: 7px !important;
    }

    .profile-wrapper > span {
        max-width: 76px !important;
        font-size: 11px !important;
    }

    .top-avatar,
    .top-avatar-fallback {
        width: 30px !important;
        height: 30px !important;
        min-width: 30px !important;
        flex-basis: 30px !important;
    }

    .role-label i {
        margin-right: 3px !important;
    }

    .container {
        padding-left: 12px !important;
        padding-right: 12px !important;
    }
}

@media (max-width: 400px) {
    .role-label {
        display: none !important;
    }

    .profile-wrapper > span {
        max-width: 72px !important;
    }
}

@media (max-width: 680px) {
    .task-item {
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        flex-wrap: wrap !important;
        gap: 12px !important;
        padding: 15px 14px !important;
    }

    .task-item > div,
    .task-item > section {
        min-width: 0 !important;
        max-width: 100% !important;
    }

    .form-grid,
    .grid-2,
    .row,
    .info-grid,
    .stats-grid,
    .stats-grid-3 {
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
    }

    .form-group,
    .card,
    .profile-card,
    .form-card {
        min-width: 0 !important;
    }

    textarea,
    input,
    select {
        min-width: 0 !important;
    }

    .header-actions,
    .action-row,
    .button-row {
        flex-wrap: wrap !important;
    }

    .header-actions > *,
    .action-row > *,
    .button-row > * {
        max-width: 100% !important;
    }
}


/* =========================================================
   BOOGIE'S STAFF SHELL — MATCH ADMIN DESKTOP/MOBILE BEHAVIOR
   Final responsive override. Does not change PHP/database logic.
   ========================================================= */
html, body { width:100%; max-width:100%; overflow-x:hidden; }

/* Desktop shell - same proportions as admin side */
.sidebar {
    width:260px !important;
    background:#001f3f !important;
    color:#fff !important;
    position:fixed !important;
    top:0 !important;
    left:0 !important;
    bottom:0 !important;
    height:100vh !important;
    z-index:1000 !important;
    display:flex !important;
    flex-direction:column !important;
    overflow-y:auto !important;
    box-shadow:none !important;
}
.sidebar-header {
    padding:30px 20px !important;
    text-align:center !important;
    border-bottom:1px solid rgba(255,255,255,.05) !important;
    display:block !important;
}
.sidebar-logo-img {
    width:80px !important;
    height:auto !important;
    object-fit:contain !important;
    margin:0 auto 10px !important;
    display:block !important;
}
.sidebar-header h2 {
    margin:0 !important;
    color:#ffcc00 !important;
    font-size:16px !important;
    text-transform:uppercase !important;
    letter-spacing:1px !important;
    font-weight:800 !important;
}
.nav-links {
    flex-grow:1 !important;
    padding:20px 15px !important;
    display:flex !important;
    flex-direction:column !important;
    gap:5px !important;
}
.nav-item {
    display:flex !important;
    align-items:center !important;
    padding:14px 20px !important;
    margin:0 !important;
    gap:0 !important;
    color:#94a3b8 !important;
    text-decoration:none !important;
    font-size:14px !important;
    font-weight:500 !important;
    border-radius:10px !important;
    position:relative !important;
    transform:none;
}
.nav-item i {
    width:32px !important;
    min-width:32px !important;
    font-size:18px !important;
    text-align:left !important;
}
.nav-item:hover {
    color:#fff !important;
    background:rgba(255,255,255,.05) !important;
    transform:translateX(4px) !important;
}
.nav-item.active {
    color:#ffcc00 !important;
    background:rgba(255,204,0,.08) !important;
    font-weight:700 !important;
}
.nav-item.active::before {
    content:'' !important;
    position:absolute !important;
    left:-15px !important;
    top:15% !important;
    width:5px !important;
    height:70% !important;
    background:#ffcc00 !important;
    border-radius:0 5px 5px 0 !important;
    box-shadow:2px 0 8px rgba(255,204,0,.5) !important;
}

.main-content {
    margin-left:260px !important;
    width:calc(100% - 260px) !important;
    min-height:100vh !important;
    display:flex !important;
    flex-direction:column !important;
}
.main-content > header,
.main-content > header:first-child {
    width:100% !important;
    min-height:70px !important;
    height:70px !important;
    padding:0 40px !important;
    background:#fff !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:16px !important;
    border-bottom:1px solid var(--border,#e2e8f0) !important;
    position:sticky !important;
    top:0 !important;
    z-index:1200 !important;
    box-shadow:0 1px 10px rgba(0,0,0,.04) !important;
}
.topbar-left {
    display:flex !important;
    align-items:center !important;
    gap:12px !important;
    min-width:0 !important;
    flex:0 1 auto !important;
}
.breadcrumb {
    min-width:0 !important;
    display:flex !important;
    align-items:center !important;
    gap:8px !important;
    color:#001f3f !important;
    font-size:14px !important;
    font-weight:700 !important;
    white-space:nowrap !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
}
.breadcrumb-text {
    min-width:0 !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
    white-space:nowrap !important;
}
.top-right-actions {
    margin-left:auto !important;
    display:flex !important;
    align-items:center !important;
    justify-content:flex-end !important;
    gap:16px !important;
    flex:0 0 auto !important;
    min-width:max-content !important;
}
.notif-wrapper,
.profile-wrapper {
    position:relative !important;
    flex:0 0 auto !important;
    min-width:0 !important;
}
.profile-wrapper {
    display:flex !important;
    align-items:center !important;
    gap:12px !important;
    border-left:1px solid #e2e8f0 !important;
    padding-left:18px !important;
    cursor:pointer !important;
    user-select:none !important;
}
.role-label {
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:5px !important;
    min-height:24px !important;
    padding:5px 11px !important;
    border-radius:999px !important;
    background:#001f3f !important;
    color:#ffcc00 !important;
    border:1px solid #ffcc00 !important;
    font-size:9px !important;
    font-weight:900 !important;
    letter-spacing:1px !important;
    text-transform:uppercase !important;
    white-space:nowrap !important;
    flex:0 0 auto !important;
}
.role-label i { font-size:9px !important; }
.top-avatar,
.top-avatar-fallback {
    width:35px !important;
    height:35px !important;
    min-width:35px !important;
    flex:0 0 35px !important;
    border-radius:50% !important;
    object-fit:cover !important;
}
.top-avatar-fallback {
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    background:#001f3f !important;
    color:#ffcc00 !important;
    font-size:14px !important;
    font-weight:800 !important;
    border:2px solid #ffcc00 !important;
}
.profile-wrapper > span:not(.name-label),
.profile-name-text,
.profile-wrapper .profile-name-text {
    min-width:0 !important;
    max-width:220px !important;
    display:flex !important;
    align-items:center !important;
    gap:6px !important;
    color:#001f3f !important;
    font-size:14px !important;
    font-weight:700 !important;
    white-space:nowrap !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
}
.notif-wrapper > i { color:#64748b !important; font-size:20px !important; }
.mobile-menu-btn {
    display:none !important;
    flex:0 0 auto !important;
    width:40px !important;
    height:40px !important;
    border:0 !important;
    border-radius:10px !important;
    background:#001f3f !important;
    color:#ffcc00 !important;
    align-items:center !important;
    justify-content:center !important;
    cursor:pointer !important;
    z-index:2200 !important;
}
.mobile-menu-btn i { color:#ffcc00 !important; font-size:17px !important; }
.sidebar-overlay {
    display:none !important;
    position:fixed !important;
    inset:0 !important;
    background:rgba(0,31,63,.48) !important;
    z-index:1900 !important;
}
.sidebar-overlay.show { display:block !important; }
.notif-dropdown,
.profile-dropdown {
    z-index:3000 !important;
}

@media (max-width:1200px) and (min-width:901px) {
    .main-content { width:calc(100% - 260px) !important; }
    .main-content > header { padding:0 28px !important; }
    .top-right-actions { gap:14px !important; }
    .profile-wrapper { gap:9px !important; padding-left:14px !important; }
    .profile-name-text,
    .profile-wrapper > span:not(.name-label) { max-width:150px !important; }
}

@media (max-width:900px) {
    body { overflow-x:hidden !important; }
    .sidebar {
        left:0 !important;
        top:0 !important;
        width:260px !important;
        height:100vh !important;
        transform:translateX(-100%) !important;
        transition:transform .25s ease !important;
        z-index:2000 !important;
        box-shadow:12px 0 30px rgba(0,0,0,.18) !important;
    }
    .sidebar.mobile-open { transform:translateX(0) !important; }
    .main-content {
        margin-left:0 !important;
        width:100% !important;
        min-width:0 !important;
    }
    .main-content > header,
    .main-content > header:first-child {
        min-height:64px !important;
        height:64px !important;
        padding:0 14px !important;
        gap:10px !important;
        position:sticky !important;
        top:0 !important;
        z-index:1800 !important;
    }
    .mobile-menu-btn { display:inline-flex !important; }
    .topbar-left { flex:1 1 auto !important; min-width:0 !important; }
    .breadcrumb { font-size:13px !important; gap:6px !important; max-width:100% !important; }
    .top-right-actions { gap:10px !important; }
    .role-label { padding:5px 9px !important; font-size:8px !important; }
    .profile-wrapper { gap:8px !important; padding-left:10px !important; }
    .top-avatar,
    .top-avatar-fallback { width:32px !important; height:32px !important; min-width:32px !important; flex-basis:32px !important; }
    .profile-name-text,
    .profile-wrapper > span:not(.name-label) { max-width:120px !important; font-size:12px !important; }
    .notif-dropdown { position:fixed !important; top:72px !important; left:12px !important; right:12px !important; width:auto !important; max-width:none !important; }
    .profile-dropdown { position:fixed !important; top:72px !important; right:12px !important; width:min(220px,calc(100vw - 24px)) !important; }
    .sidebar-header { padding:30px 20px !important; }
    .sidebar-logo-img { width:80px !important; }
    .nav-links { padding:20px 15px !important; gap:5px !important; }
    .nav-item { padding:14px 20px !important; font-size:14px !important; }
}

@media (max-width:560px) {
    .main-content > header,
    .main-content > header:first-child {
        min-height:60px !important;
        height:60px !important;
        padding:0 10px !important;
        gap:7px !important;
    }
    .mobile-menu-btn { width:36px !important; height:36px !important; min-width:36px !important; border-radius:9px !important; }
    .breadcrumb { font-size:11px !important; }
    .top-right-actions { gap:6px !important; }
    .role-label { padding:4px 7px !important; font-size:8px !important; }
    .profile-wrapper { gap:6px !important; padding-left:7px !important; }
    .profile-name-text,
    .profile-wrapper > span:not(.name-label) { max-width:76px !important; font-size:11px !important; }
    .top-avatar,
    .top-avatar-fallback { width:30px !important; height:30px !important; min-width:30px !important; flex-basis:30px !important; }
    .notif-wrapper > i { font-size:18px !important; }
    .container { padding-left:12px !important; padding-right:12px !important; }
}

@media (max-width:400px) {
    .role-label { display:none !important; }
    .profile-name-text,
    .profile-wrapper > span:not(.name-label) { max-width:70px !important; }
}

/* Prevent content-level overflow on small screens. */
.container,
.form-card,
.card,
.schedule-card,
.table-wrap,
.table-container,
.content-card,
.profile-card {
    max-width:100% !important;
    min-width:0 !important;
}
input, select, textarea, button, img { max-width:100%; }


/* ================================================================
   FINAL STAFF TOP BAR FIX — MATCH ADMIN MOBILE BEHAVIOR
   Keep the staff name visible on phones while preventing overflow.
   ================================================================ */
.profile-name-text {
    white-space: nowrap !important;
    min-width: 0 !important;
}

@media (max-width: 640px) {
    .main-content > header,
    .main-content > header:first-child {
        width: 100% !important;
        min-width: 0 !important;
        height: 60px !important;
        min-height: 60px !important;
        padding: 0 10px !important;
        display: flex !important;
        flex-wrap: nowrap !important;
        align-items: center !important;
        gap: 7px !important;
        overflow: visible !important;
    }

    .mobile-menu-btn {
        display: inline-flex !important;
        width: 36px !important;
        min-width: 36px !important;
        height: 36px !important;
        flex: 0 0 36px !important;
        align-items: center !important;
        justify-content: center !important;
    }

    .topbar-left {
        flex: 0 0 auto !important;
        min-width: 0 !important;
    }

    /* On mobile the breadcrumb is unnecessary beside the menu button. */
    .breadcrumb {
        display: none !important;
    }

    .top-right-actions {
        display: flex !important;
        align-items: center !important;
        justify-content: flex-end !important;
        gap: 7px !important;
        flex: 1 1 auto !important;
        min-width: 0 !important;
        margin-left: auto !important;
    }

    .notif-wrapper {
        flex: 0 0 auto !important;
    }

    .notif-wrapper > i {
        font-size: 19px !important;
    }

    .role-label {
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        flex: 0 0 auto !important;
        white-space: nowrap !important;
        padding: 4px 7px !important;
        font-size: 8px !important;
    }

    .profile-wrapper {
        display: flex !important;
        align-items: center !important;
        flex: 0 1 auto !important;
        min-width: 0 !important;
        max-width: 150px !important;
        gap: 6px !important;
        padding-left: 7px !important;
        border-left: 1px solid var(--border) !important;
        overflow: visible !important;
    }

    .top-avatar,
    .top-avatar-fallback {
        width: 30px !important;
        height: 30px !important;
        min-width: 30px !important;
        flex: 0 0 30px !important;
    }

    /* Keep the staff name visible like the admin top bar. */
    .profile-name-text {
        display: block !important;
        flex: 0 1 105px !important;
        width: 105px !important;
        min-width: 0 !important;
        max-width: 105px !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        white-space: nowrap !important;
        line-height: 1.2 !important;
        font-size: 12px !important;
        font-weight: 700 !important;
        padding-right: 13px !important;
        position: relative !important;
    }

    .profile-name-text i {
        display: none !important;
    }

    .profile-name-text::after {
        content: '\\f078';
        font-family: 'Font Awesome 6 Free';
        font-weight: 900;
        position: absolute;
        right: 0;
        top: 50%;
        transform: translateY(-50%);
        font-size: 9px;
        color: var(--text-muted, #64748b);
        opacity: .7;
    }
}

@media (max-width: 480px) {
    .main-content > header,
    .main-content > header:first-child {
        padding: 0 9px !important;
        gap: 6px !important;
    }

    .top-right-actions {
        gap: 6px !important;
    }

    .role-label {
        padding: 4px 7px !important;
        font-size: 8px !important;
    }

    .profile-wrapper {
        gap: 5px !important;
        max-width: 138px !important;
        padding-left: 6px !important;
    }

    .profile-name-text {
        flex-basis: 92px !important;
        width: 92px !important;
        max-width: 92px !important;
        font-size: 11px !important;
    }
}

@media (max-width: 400px) {
    .role-label {
        display: none !important;
    }

    .profile-wrapper {
        max-width: 135px !important;
        padding-left: 5px !important;
    }

    .profile-name-text {
        flex-basis: 100px !important;
        width: 100px !important;
        max-width: 100px !important;
    }
}



/* ================================================================
   FINAL TOP BAR — MATCH ADMIN SIDE / MOBILE-SAFE
   Keep staff name visible on phones without clipping the right edge.
   ================================================================ */
.main-content > header {
    width: 100% !important;
    min-width: 0 !important;
}

.main-content > header .topbar-left {
    display: flex !important;
    align-items: center !important;
    min-width: 0 !important;
}

.main-content > header .top-right-actions {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    margin-left: auto !important;
    flex: 0 0 auto !important;
    min-width: 0 !important;
    gap: 14px !important;
}

.main-content > header .profile-wrapper {
    display: flex !important;
    align-items: center !important;
    gap: 9px !important;
    flex: 0 0 auto !important;
    min-width: 0 !important;
    max-width: none !important;
    white-space: nowrap !important;
}

.main-content > header .role-label,
.main-content > header .top-avatar,
.main-content > header .top-avatar-fallback,
.main-content > header .profile-name-text {
    flex-shrink: 0;
}

.main-content > header .profile-name-text {
    min-width: 0 !important;
    max-width: 180px !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    white-space: nowrap !important;
}

@media (max-width: 900px) {
    .main-content > header {
        height: 64px !important;
        min-height: 64px !important;
        padding: 0 12px !important;
        gap: 8px !important;
        flex-wrap: nowrap !important;
    }

    .main-content > header .mobile-menu-btn {
        display: inline-flex !important;
        width: 40px !important;
        min-width: 40px !important;
        height: 40px !important;
        flex: 0 0 40px !important;
        align-items: center !important;
        justify-content: center !important;
    }

    .main-content > header .breadcrumb {
        display: none !important;
    }

    .main-content > header .top-right-actions {
        gap: 8px !important;
        margin-left: auto !important;
        flex: 0 0 auto !important;
        max-width: calc(100% - 48px) !important;
    }

    .main-content > header .notif-wrapper {
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        flex: 0 0 auto !important;
    }

    .main-content > header .notif-wrapper > i {
        font-size: 19px !important;
    }

    .main-content > header .role-label {
        min-width: 43px !important;
        height: 26px !important;
        padding: 4px 8px !important;
        gap: 4px !important;
        font-size: 8px !important;
        line-height: 1 !important;
        justify-content: center !important;
        white-space: nowrap !important;
    }

    .main-content > header .role-label i {
        font-size: 8px !important;
    }

    .main-content > header .profile-wrapper {
        gap: 6px !important;
        padding-left: 8px !important;
        max-width: none !important;
        min-width: 0 !important;
        border-left: 1px solid var(--border) !important;
    }

    .main-content > header .top-avatar,
    .main-content > header .top-avatar-fallback {
        width: 32px !important;
        height: 32px !important;
        min-width: 32px !important;
        flex: 0 0 32px !important;
        font-size: 12px !important;
    }

    /* Keep the staff name visible, but compact enough for a phone. */
    .main-content > header .profile-name-text {
        display: block !important;
        width: clamp(64px, 18vw, 88px) !important;
        min-width: clamp(64px, 18vw, 88px) !important;
        max-width: clamp(64px, 18vw, 88px) !important;
        flex: 0 1 clamp(64px, 18vw, 88px) !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        white-space: nowrap !important;
        font-size: 11px !important;
        line-height: 1.2 !important;
        padding-right: 11px !important;
        position: relative !important;
    }

    .main-content > header .profile-name-text i {
        display: none !important;
    }

    .main-content > header .profile-name-text::after {
        content: '\\f078';
        font-family: 'Font Awesome 6 Free';
        font-weight: 900;
        position: absolute;
        right: 0;
        top: 50%;
        transform: translateY(-50%);
        font-size: 8px;
        color: var(--text-muted, #64748b);
        opacity: .75;
    }
}

@media (max-width: 480px) {
    .main-content > header {
        padding: 0 10px !important;
        gap: 6px !important;
    }

    .main-content > header .mobile-menu-btn {
        width: 36px !important;
        min-width: 36px !important;
        height: 36px !important;
        flex-basis: 36px !important;
        border-radius: 9px !important;
        font-size: 16px !important;
    }

    .main-content > header .top-right-actions {
        gap: 6px !important;
    }

    .main-content > header .role-label {
        min-width: 41px !important;
        height: 25px !important;
        padding: 4px 7px !important;
        font-size: 8px !important;
    }

    .main-content > header .profile-wrapper {
        gap: 5px !important;
        padding-left: 6px !important;
    }

    .main-content > header .top-avatar,
    .main-content > header .top-avatar-fallback {
        width: 30px !important;
        height: 30px !important;
        min-width: 30px !important;
        flex-basis: 30px !important;
    }

    .main-content > header .profile-name-text {
        width: clamp(62px, 18vw, 84px) !important;
        min-width: clamp(62px, 18vw, 84px) !important;
        max-width: clamp(62px, 18vw, 84px) !important;
        flex-basis: clamp(62px, 18vw, 84px) !important;
        font-size: 10.5px !important;
    }
}

@media (max-width: 360px) {
    .main-content > header .top-right-actions {
        gap: 5px !important;
    }

    .main-content > header .role-label {
        min-width: 39px !important;
        padding-left: 6px !important;
        padding-right: 6px !important;
        font-size: 7.5px !important;
    }

    .main-content > header .profile-name-text {
        width: 64px !important;
        min-width: 64px !important;
        max-width: 64px !important;
        flex-basis: 64px !important;
        font-size: 10px !important;
    }
}



/* ===== CHANGE PASSWORD ===== */
.password-card { margin-top:22px; }
.password-help { margin:12px 0 0; color:#64748b; font-size:12px; text-align:left; }
.password-save-btn { margin-top:14px; }

/* ===== MOBILE STAFF NAME: FIRST NAME ONLY ===== */
.mobile-profile-first-name { display:none; }
@media (max-width:560px) {
    .main-content > header .desktop-profile-name { display:none !important; }
    .main-content > header .mobile-profile-first-name {
        display:block !important;
        flex:0 1 auto !important;
        min-width:0 !important;
        max-width:74px !important;
        overflow:hidden !important;
        text-overflow:ellipsis !important;
        white-space:nowrap !important;
        color:var(--sidebar-navy,#001f3f) !important;
        font-size:11px !important;
        font-weight:700 !important;
        line-height:1.2 !important;
    }
    .main-content > header .top-right-actions { gap:7px !important; min-width:0 !important; flex:0 1 auto !important; }
    .main-content > header .profile-wrapper { gap:6px !important; padding-left:7px !important; min-width:0 !important; max-width:150px !important; flex:0 1 auto !important; }
    .password-card { margin-top:16px; }
    .password-help { font-size:11px; line-height:1.5; }
}
@media (max-width:400px) {
    .main-content > header .mobile-profile-first-name { max-width:62px !important; font-size:10px !important; }
}
</style>
<style>

/* FINAL PROFILE NAME VISIBILITY FIX
   Desktop: show full name only.
   Mobile: show first name only.
*/
.mobile-profile-first-name {
    display: none !important;
}

@media (min-width: 561px) {
    .desktop-profile-name {
        display: flex !important;
    }
    .mobile-profile-first-name {
        display: none !important;
    }
}

@media (max-width: 560px) {
    .desktop-profile-name {
        display: none !important;
    }
    .mobile-profile-first-name {
        display: inline-flex !important;
        align-items: center !important;
        flex: 0 1 auto !important;
        width: auto !important;
        min-width: 0 !important;
        max-width: 82px !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        white-space: nowrap !important;
        color: #001f3f !important;
        font-size: 11px !important;
        font-weight: 700 !important;
        line-height: 1.2 !important;
    }
}

@media (max-width: 400px) {
    .mobile-profile-first-name {
        max-width: 68px !important;
        font-size: 10px !important;
    }
}

/* ===== FINAL PROFILE NAME FIX v11 ===== */
@media (min-width: 561px) {
    .main-content > header .profile-wrapper > .desktop-profile-name {
        display: inline-flex !important;
        visibility: visible !important;
        opacity: 1 !important;
    }
    .main-content > header .profile-wrapper > .mobile-profile-first-name {
        display: none !important;
        visibility: hidden !important;
    }
}

@media (max-width: 560px) {
    .main-content > header .profile-wrapper > .desktop-profile-name {
        display: none !important;
        visibility: hidden !important;
    }
    .main-content > header .profile-wrapper > .mobile-profile-first-name {
        display: inline-flex !important;
        align-items: center !important;
        flex: 0 1 auto !important;
        width: auto !important;
        min-width: 0 !important;
        max-width: 70px !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        white-space: nowrap !important;
        visibility: visible !important;
        opacity: 1 !important;
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

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <main class="main-content">
        <header>
            <button class="mobile-menu-btn" id="mobileMenuBtn" type="button" aria-label="Open menu" aria-expanded="false">
                <i class="fas fa-bars" aria-hidden="true"></i>
            </button>
            <div class="breadcrumb">
                <i class="fas fa-user-circle" style="color: var(--brand-blue);"></i> 
                <?php echo in_array($current_role, ['veterinarian', 'vet_assistant', 'vet_nurse'], true) ? 'Veterinary Portal' : (($current_role === 'admin') ? 'Admin Portal' : 'Manager Portal'); ?> / My Profile
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
                                        <?php echo htmlspecialchars((string)($notif['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
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
                        <i class="<?php echo in_array($current_role, ['veterinarian', 'vet_assistant', 'vet_nurse'], true) ? 'fas fa-user-md' : 'fas fa-user-tie'; ?>"></i> 
                        <?php echo strtoupper($current_role); ?>
                    </div>
                    
                    <?php if ($profile_img_url !== ''): ?>
                        <img src="<?php echo htmlspecialchars($profile_img_url, ENT_QUOTES, 'UTF-8'); ?>" class="top-avatar" alt="Profile Picture">
                    <?php else: ?>
                        <div class="top-avatar-fallback"><?php echo $first_letter; ?></div>
                    <?php endif; ?>
                    
                    <span class="profile-name-text desktop-profile-name" style="font-size:14px;font-weight:700;color:var(--sidebar-navy);display:flex;align-items:center;gap:6px;">
                        <?php echo htmlspecialchars($display_with_title); ?>
                        <i class="fas fa-chevron-down" style="font-size: 10px; color: var(--text-muted); opacity: 0.5;"></i>
                    </span>
                    <span class="mobile-profile-first-name"><?php echo htmlspecialchars($first_name_only); ?></span>

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
                    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
                
                <?php if (!empty($error_msg)): ?>
                    <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>

                <form action="" method="POST" enctype="multipart/form-data" id="imageForm" style="display:none;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="file" id="imageUpload" name="profile_image" accept=".png, .jpg, .jpeg, .gif" onchange="document.getElementById('imageForm').submit()">
                </form>

                <div class="avatar-upload-container">
                    <?php if ($profile_img_url !== ''): ?>
                        <img src="<?php echo htmlspecialchars($profile_img_url, ENT_QUOTES, 'UTF-8'); ?>" class="avatar-preview" alt="Profile">
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
                            <input type="text" name="full_name" value="<?php echo htmlspecialchars($full_display_name, ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Username (Used for Login)</label>
                            <input type="text" name="username" value="<?php echo htmlspecialchars($staff_username, ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                        <div class="form-group">
                            <label>Contact Number</label>
                            <input type="text" name="contact_number" inputmode="numeric" value="<?php echo htmlspecialchars($staff_contact, ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. 09123456789">
                        </div>

                        <div class="form-group full-width">
                            <label>Clinic Position</label>
                            <input type="text" value="<?php echo htmlspecialchars($staff_position, ENT_QUOTES, 'UTF-8'); ?>" readonly>
                        </div>
                    </div>
                    
                    <button type="submit" name="update_profile" class="btn-save"><i class="fas fa-save"></i> Save Profile Details</button>
                </form>
            </div>
        </div>


        <div class="profile-card password-card">
            <div class="card-header">
                <h1>Change Password</h1>
                <p>Update your staff account password securely.</p>
            </div>

            <form action="" method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="form-grid">
                    <div class="form-group full-width">
                        <label for="current_password">Current Password</label>
                        <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
                    </div>

                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password" autocomplete="new-password" minlength="8" required>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" minlength="8" required>
                    </div>
                </div>

                <p class="password-help">Use at least 8 characters for your new password.</p>

                <button type="submit" name="change_password" class="btn-save password-save-btn">
                    <i class="fas fa-key"></i> Change Password
                </button>
            </form>
        </div>

        <footer>
            © <?php echo date("Y"); ?> BOOGIE'S PET CARE & SERVICES - DASMARIÑAS BRANCH
        </footer>
    </main>

    <script>

        // --- Consistent mobile sidebar ---
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const mobileSidebar = document.querySelector('.sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        function toggleMobileMenu(event) {
            if (event) event.stopPropagation();
            if (!mobileSidebar || !sidebarOverlay || !mobileMenuBtn) return;

            const isOpen = mobileSidebar.classList.toggle('mobile-open');
            sidebarOverlay.classList.toggle('show', isOpen);
            mobileMenuBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            mobileMenuBtn.setAttribute('aria-label', isOpen ? 'Close menu' : 'Open menu');
            mobileMenuBtn.innerHTML = isOpen
                ? '<i class="fas fa-times" aria-hidden="true"></i>'
                : '<i class="fas fa-bars" aria-hidden="true"></i>';
        }

        function closeMobileMenu() {
            if (!mobileSidebar || !sidebarOverlay || !mobileMenuBtn) return;
            mobileSidebar.classList.remove('mobile-open');
            sidebarOverlay.classList.remove('show');
            mobileMenuBtn.setAttribute('aria-expanded', 'false');
            mobileMenuBtn.setAttribute('aria-label', 'Open menu');
            mobileMenuBtn.innerHTML = '<i class="fas fa-bars" aria-hidden="true"></i>';
        }

        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', toggleMobileMenu);
        }
        if (sidebarOverlay) {
            sidebarOverlay.addEventListener('click', closeMobileMenu);
        }
        document.querySelectorAll('.sidebar .nav-item').forEach(function (link) {
            link.addEventListener('click', function () {
                if (window.innerWidth <= 900) closeMobileMenu();
            });
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') closeMobileMenu();
        });

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

        if (<?php echo $can_view_admin_notifications ? 'true' : 'false'; ?>) {
            setInterval(fetchAdminNotifs, 3000);
        }
    </script>
</body>
</html>