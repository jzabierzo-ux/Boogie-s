<?php
session_start();
require_once '../db_supabase.php';

// --- UNIVERSAL SECURITY CHECK ---
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';

// Payagan ang admin, supervisor, at staff
if (!isset($_SESSION['logged_in']) || !in_array($current_role, ['admin', 'supervisor', 'staff'], true)) {
    header("Location: ../staff/stafflogin.php");
    exit();
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$message = '';

// Kumuha ng user data mula sa database
$user_data = [];

try {
    $get_user = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $get_user->execute([':id' => $user_id]);
    $user_data = $get_user->fetch(PDO::FETCH_ASSOC);

    if (!$user_data) {
        $message = '<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> User account not found.</div>';
    }
} catch (PDOException $e) {
    error_log("Profile fetch failed: " . $e->getMessage());
    $message = '<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> Failed to load profile.</div>';
}

// ---------------------------------------------------------
// 1. HANDLE PROFILE & PICTURE UPDATE
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile']) && !empty($user_data)) {
    $full_name = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');

    $profile_image = $user_data['profile_image'] ?? ''; // Default to current image
    $has_error = false;

    // Contact Number Validation (Kung nilagyan ng laman)
    if ($contact_number !== '' && !preg_match("/^[0-9]{11}$/", $contact_number)) {
        $message = '<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> Invalid contact number. Must be exactly 11 digits.</div>';
        $has_error = true;
    }

    if (!$has_error) {
        // Handle File Upload
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === 0) {
            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
            $file_name = $_FILES['profile_picture']['name'] ?? '';
            $file_size = (int)($_FILES['profile_picture']['size'] ?? 0);
            $file_tmp = $_FILES['profile_picture']['tmp_name'] ?? '';
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

            if (in_array($file_ext, $allowed_ext, true)) {
                if ($file_size < 5000000) { // Limit to 5MB
                    $new_file_name = 'admin_' . $user_id . '_' . time() . '.' . $file_ext;
                    $upload_dir = __DIR__ . '/uploads';
                    $upload_path = $upload_dir . '/' . $new_file_name;
                    $profile_db_path = 'uploads/' . $new_file_name;

                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }

                    if (move_uploaded_file($file_tmp, $upload_path)) {
                        $profile_image = $profile_db_path;
                    } else {
                        $message = '<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> Failed to move uploaded file.</div>';
                        $has_error = true;
                    }
                } else {
                    $message = '<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> File size is too large (Max: 5MB).</div>';
                    $has_error = true;
                }
            } else {
                $message = '<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> Invalid file type. Only JPG, PNG, and GIF allowed.</div>';
                $has_error = true;
            }
        }
    }

    if (!$has_error) {
        try {
            $update_stmt = $pdo->prepare("
                UPDATE users
                SET full_name = :full_name,
                    username = :username,
                    contact_number = :contact_number,
                    profile_image = :profile_image
                WHERE id = :id
            ");

            $update_stmt->execute([
                ':full_name' => $full_name,
                ':username' => $username,
                ':contact_number' => $contact_number,
                ':profile_image' => $profile_image,
                ':id' => $user_id
            ]);

            $_SESSION['user_name'] = $full_name;
            $message = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Profile updated successfully!</div>';

            // Refresh data after update
            $get_user = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
            $get_user->execute([':id' => $user_id]);
            $user_data = $get_user->fetch(PDO::FETCH_ASSOC) ?: $user_data;
        } catch (PDOException $e) {
            error_log("Profile update failed: " . $e->getMessage());
            $message = '<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> Error updating profile.</div>';
        }
    }
}

// ---------------------------------------------------------
// 2. HANDLE PASSWORD UPDATE
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_password']) && !empty($user_data)) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (password_verify($current_password, $user_data['password'] ?? '')) {
        if ($new_password === $confirm_password) {
            $hashed_new_password = password_hash($new_password, PASSWORD_DEFAULT);

            try {
                $update_pass_stmt = $pdo->prepare("UPDATE users SET password = :password WHERE id = :id");
                $update_pass_stmt->execute([
                    ':password' => $hashed_new_password,
                    ':id' => $user_id
                ]);

                $message = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Password successfully updated!</div>';

                $get_user = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
                $get_user->execute([':id' => $user_id]);
                $user_data = $get_user->fetch(PDO::FETCH_ASSOC) ?: $user_data;
            } catch (PDOException $e) {
                error_log("Password update failed: " . $e->getMessage());
                $message = '<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> Failed to update password.</div>';
            }
        } else {
            $message = '<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> New passwords do not match.</div>';
        }
    } else {
        $message = '<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> Incorrect current password.</div>';
    }
}

// Setup display variables
$display_name_raw = $user_data['full_name'] ?? 'User';
$display_username_raw = $user_data['username'] ?? 'No Username';
$display_phone_raw = $user_data['contact_number'] ?? '';
$display_image_raw = !empty($user_data['profile_image']) ? $user_data['profile_image'] : null;

$display_name = htmlspecialchars($display_name_raw, ENT_QUOTES, 'UTF-8');
$display_username = htmlspecialchars($display_username_raw, ENT_QUOTES, 'UTF-8');
$display_phone = htmlspecialchars($display_phone_raw, ENT_QUOTES, 'UTF-8');
$display_image = $display_image_raw !== null ? htmlspecialchars($display_image_raw, ENT_QUOTES, 'UTF-8') : null;
$join_date = isset($user_data['created_at']) ? date('F d, Y', strtotime($user_data['created_at'])) : 'Unknown';

// Dynamic Badge Logic based on Role
$display_role_badge = strtoupper($current_role);
if ($current_role === 'staff') {
    $display_role_badge = 'STAFF / FRONT DESK';
} elseif ($current_role === 'admin') {
    $display_role_badge = 'SYSTEM ADMIN';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile & Settings | Personnel</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { 
            --brand-blue: #001f3f; 
            --brand-yellow: #ffcc00; 
            --bg-light: #f4f7f6; 
            --white: #ffffff; 
            --text-main: #1c1e21; 
            --text-muted: #64748b; 
            --border: #e2e8f0;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Poppins', sans-serif; }
        body { background-color: var(--bg-light); color: var(--text-main); display: flex; min-height: 100vh; }
        
        .container { padding: 40px; width: 100%; max-width: 1100px; margin: 0 auto; }
        
        .page-header { margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; }
        .page-header h2 { color: var(--brand-blue); font-size: 26px; margin: 0; font-weight: 800; }
        .btn-back { background: var(--white); color: var(--brand-blue); text-decoration: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; transition: 0.3s; display: flex; align-items: center; gap: 8px; font-size: 14px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .btn-back:hover { background: var(--brand-blue); color: var(--brand-yellow); transform: translateY(-2px); }
        
        /* ALERTS */
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 25px; font-size: 14px; display: flex; align-items: center; gap: 10px; font-weight: 500; }
        .alert-success { background: #dcfce7; color: #166534; border-left: 4px solid #16a34a; }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 4px solid #dc2626; }

        .profile-wrapper { display: grid; grid-template-columns: 320px 1fr; gap: 30px; align-items: start; }
        
        /* MODERN CARDS */
        .card { 
            background: var(--white); 
            border-radius: 20px; 
            padding: 30px; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.03); 
            position: relative; 
            overflow: hidden; 
            margin-bottom: 30px;
        }
        .card::before { 
            content: ''; 
            position: absolute; 
            top: 0; left: 0; 
            width: 100%; height: 5px; 
            background: linear-gradient(90deg, var(--brand-blue), var(--brand-yellow)); 
        }

        /* PROFILE DETAILS (LEFT SIDE) */
        .profile-card { text-align: center; }
        .profile-avatar { 
            width: 130px; height: 130px; 
            background: #f1f5f9; color: var(--brand-blue); 
            border-radius: 50%; 
            display: flex; align-items: center; justify-content: center; 
            font-size: 50px; font-weight: 800;
            margin: 0 auto 20px; 
            border: 4px solid var(--white);
            box-shadow: 0 8px 16px rgba(0, 31, 63, 0.15); 
            overflow: hidden; 
        }
        .profile-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .profile-card h3 { color: var(--brand-blue); font-size: 22px; font-weight: 800; margin-bottom: 5px; }
        .admin-badge { 
            background: var(--brand-blue); color: var(--brand-yellow); 
            padding: 6px 18px; border-radius: 50px; 
            font-size: 11px; font-weight: 800; letter-spacing: 1px; 
            display: inline-block; margin-bottom: 25px; 
        }
        
        .info-list { text-align: left; margin-top: 20px; font-size: 13px; color: var(--text-muted); line-height: 2; font-weight: 500;}
        .info-list div { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
        .info-list i { color: var(--brand-blue); font-size: 16px; width: 16px; text-align: center;}

        /* FORMS (RIGHT SIDE) */
        .section-title { font-size: 18px; font-weight: 700; color: var(--brand-blue); margin-bottom: 25px; padding-bottom: 15px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 10px; }
        
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .form-group { display: flex; flex-direction: column; gap: 8px; }
        .form-group.full-width { grid-column: span 2; }
        .form-group label { font-size: 13px; font-weight: 600; color: var(--brand-blue); }
        
        .form-control { 
            width: 100%; padding: 12px 15px; 
            border: 1px solid var(--border); border-radius: 8px; 
            font-size: 14px; color: var(--text-main); 
            background: #f8fafc; outline: none; transition: 0.2s;
        }
        .form-control:focus { background: var(--white); border-color: var(--brand-blue); box-shadow: 0 0 0 3px rgba(0, 31, 63, 0.1); }
        
        input[type="file"].form-control { padding: 10px; border: 1px dashed #cbd5e1; cursor: pointer; }
        
        .btn-submit { 
            background: var(--brand-blue); color: var(--brand-yellow); 
            border: none; padding: 12px 25px; border-radius: 8px; 
            font-weight: 700; font-size: 14px; cursor: pointer; 
            transition: 0.3s; display: inline-flex; align-items: center; gap: 8px; 
        }
        .btn-submit:hover { opacity: 0.9; transform: translateY(-2px); box-shadow: 0 4px 10px rgba(0, 31, 63, 0.2); }
        
        /* ===== RESPONSIVE ===== */
        @media (max-width: 900px) {
            .profile-wrapper { grid-template-columns: 1fr; }
            .form-grid { grid-template-columns: 1fr; }
            .form-group.full-width { grid-column: span 1; }
        }

        @media (max-width: 680px) {
            body {
                display: block;
            }

            .container {
                padding: 24px 16px 30px;
            }

            .page-header {
                display: flex;
                flex-direction: column;
                align-items: stretch;
                gap: 14px;
                margin-bottom: 22px;
            }

            .page-header h2 {
                font-size: 23px;
            }

            .btn-back {
                justify-content: center;
                width: 100%;
            }

            .alert {
                align-items: flex-start;
                font-size: 13px;
                line-height: 1.5;
            }

            .profile-wrapper {
                gap: 18px;
            }

            .card {
                padding: 22px 18px;
                border-radius: 16px;
                margin-bottom: 18px;
            }

            .profile-avatar {
                width: 105px;
                height: 105px;
                font-size: 40px;
                margin-bottom: 16px;
            }

            .profile-card h3 {
                font-size: 20px;
                overflow-wrap: anywhere;
            }

            .admin-badge {
                padding: 6px 14px;
                font-size: 10px;
                line-height: 1.3;
            }

            .info-list {
                font-size: 12px;
            }

            .info-list div {
                align-items: flex-start;
                gap: 10px;
                overflow-wrap: anywhere;
            }

            .info-list i {
                flex: 0 0 16px;
                margin-top: 3px;
            }

            .section-title {
                font-size: 16px;
                margin-bottom: 20px;
                padding-bottom: 12px;
            }

            .form-group label {
                font-size: 12px;
            }

            .form-control {
                min-height: 46px;
                font-size: 14px;
            }

            input[type="file"].form-control {
                min-height: 48px;
                padding: 9px;
            }

            .btn-submit {
                width: 100%;
                min-height: 46px;
                justify-content: center;
            }

            .card form > div[style*="text-align: right"] {
                text-align: stretch !important;
            }
        }

        @media (max-width: 420px) {
            .container {
                padding-left: 12px;
                padding-right: 12px;
            }

            .card {
                padding: 20px 15px;
            }

            .page-header h2 {
                font-size: 21px;
            }

            .section-title {
                font-size: 15px;
            }
        }
    </style>
</head>
<body>
    
    <main style="flex-grow: 1;">
        <div class="container">
            <div class="page-header">
                <h2>Profile & Settings</h2>
                <a href="admindashboard.php" class="btn-back">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>

            <?php echo $message; ?>

            <div class="profile-wrapper">
                
                <div class="card profile-card">
                    <div class="profile-avatar">
                        <?php if ($display_image && file_exists($display_image)): ?>
                            <img src="<?php echo $display_image; ?>" alt="Profile Picture">
                        <?php else: ?>
                            <?php echo strtoupper(substr($display_name_raw, 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <h3><?php echo $display_name; ?></h3>
                    <span class="admin-badge"><?php echo $display_role_badge; ?></span>
                    
                    <div class="info-list">
                        <div><i class="fas fa-user"></i> <?php echo $display_username; ?></div>
                        <div><i class="fas fa-phone"></i> <?php echo $display_phone ?: 'No contact number'; ?></div>
                        <div><i class="fas fa-calendar-alt"></i> Joined: <?php echo $join_date; ?></div>
                    </div>
                </div>

                <div>
                    <div class="card">
                        <form method="POST" action="" enctype="multipart/form-data">
                            <div class="section-title">
                                <i class="fas fa-user-edit"></i> Update Profile Information
                            </div>
                            <div class="form-grid">
                                <div class="form-group full-width">
                                    <label>Profile Picture</label>
                                    <input type="file" name="profile_picture" class="form-control" accept="image/jpeg, image/png, image/gif">
                                </div>
                                <div class="form-group full-width">
                                    <label>Full Name</label>
                                    <input type="text" name="full_name" class="form-control" value="<?php echo $display_name; ?>" autocomplete="name" required>
                                </div>
                                <div class="form-group">
                                    <label>Username</label>
                                    <input type="text" name="username" class="form-control" value="<?php echo $display_username; ?>" autocomplete="username" required>
                                </div>
                                <div class="form-group">
                                    <label>Contact Number</label>
                                    <input type="tel" name="contact_number" class="form-control" inputmode="numeric" autocomplete="tel" value="<?php echo $display_phone; ?>" placeholder="e.g. 09123456789" maxlength="11" pattern="[0-9]{11}" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                                </div>
                            </div>
                            <div style="text-align: right;">
                                <button type="submit" name="update_profile" class="btn-submit">
                                    <i class="fas fa-save"></i> Save Profile Details
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="card">
                        <form method="POST" action="">
                            <div class="section-title">
                                <i class="fas fa-lock"></i> Change Password
                            </div>
                            <div class="form-grid">
                                <div class="form-group full-width">
                                    <label>Current Password</label>
                                    <input type="password" name="current_password" class="form-control" autocomplete="current-password" required placeholder="Enter current password">
                                </div>
                                <div class="form-group">
                                    <label>New Password</label>
                                    <input type="password" name="new_password" class="form-control" autocomplete="new-password" minlength="8" required placeholder="Enter new password">
                                </div>
                                <div class="form-group">
                                    <label>Confirm New Password</label>
                                    <input type="password" name="confirm_password" class="form-control" autocomplete="new-password" minlength="8" required placeholder="Confirm new password">
                                </div>
                            </div>
                            <div style="text-align: right;">
                                <button type="submit" name="update_password" class="btn-submit">
                                    <i class="fas fa-key"></i> Update Password
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </main>
</body>
</html>