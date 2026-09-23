<?php
session_start();
require_once '../db_supabase.php';

// --- UNIVERSAL SECURITY CHECK ---
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';

// Payagan ang admin, supervisor, at staff
if (!isset($_SESSION['logged_in']) || !in_array($current_role, ['admin', 'supervisor', 'staff'], true)) {
    header("Location: stafflogin.php");
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
            --navy-dark: #001f3f;
            --brand-yellow: #ffcc00;
            --admin-purple: #8b2cf5;
            --bg-light: #f4f7f6;
            --white: #ffffff;
            --text-main: #1c1e21;
            --text-muted: #64748b;
            --border: #e2e8f0;
        }

        * { box-sizing: border-box; }

        html { scroll-behavior: smooth; }

        body {
            font-family: 'Segoe UI', Tahoma, sans-serif;
            background-color: var(--bg-light);
            margin: 0;
            min-height: 100vh;
            color: var(--text-main);
        }

        main {
            width: 100%;
            min-height: 100vh;
        }

        .container {
            width: min(800px, 100%);
            padding: 40px 24px;
            margin: 0 auto;
        }

        .page-title {
            color: var(--navy-dark);
            margin: 0 0 20px;
            font-size: 28px;
            line-height: 1.25;
            font-weight: 800;
        }

        .form-box {
            background: var(--white);
            padding: 30px;
            border-radius: 14px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border: 1px solid var(--border);
        }

        .section-title {
            margin: 0 0 18px;
            padding-bottom: 12px;
            border-bottom: 1px solid #eee;
            font-size: 18px;
            line-height: 1.35;
            color: var(--navy-dark);
        }

        .form-group { margin-bottom: 20px; }

        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: var(--navy-dark);
            margin-bottom: 8px;
        }

        .form-group input {
            width: 100%;
            min-height: 48px;
            padding: 12px 14px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 15px;
            box-sizing: border-box;
            outline: none;
            transition: border-color .2s, box-shadow .2s;
        }

        .form-group input:focus {
            border-color: var(--admin-purple);
            box-shadow: 0 0 0 3px rgba(139,44,245,.10);
        }

        .btn-row {
            display: flex;
            justify-content: flex-end;
        }

        .btn-submit {
            background: var(--admin-purple);
            color: white;
            border: none;
            min-height: 46px;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            transition: .2s;
        }

        .btn-submit:hover {
            background: #7a22db;
            transform: translateY(-1px);
        }

        .alert {
            padding: 14px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            line-height: 1.5;
            overflow-wrap: anywhere;
        }

        .alert.success { background: #dcfce7; color: #166534; }
        .alert.error { background: #fee2e2; color: #991b1b; }

        @media (max-width: 600px) {
            .container { padding: 24px 16px 40px; }
            .page-title { font-size: 23px; }
            .form-box { padding: 21px 18px; border-radius: 12px; }
            .section-title { font-size: 16px; }
            .form-group input { min-height: 46px; font-size: 16px; }
            .btn-row { display: block; }
            .btn-submit { width: 100%; }
        }

        @media (max-width: 360px) {
            .container { padding-left: 12px; padding-right: 12px; }
            .form-box { padding: 18px 14px; }
            .page-title { font-size: 21px; }
            .btn-submit { padding-left: 16px; padding-right: 16px; }
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
                                    <input type="text" name="full_name" class="form-control" value="<?php echo $display_name; ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Username</label>
                                    <input type="text" name="username" class="form-control" value="<?php echo $display_username; ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Contact Number</label>
                                    <input type="tel" name="contact_number" class="form-control" value="<?php echo $display_phone; ?>" placeholder="e.g. 09123456789" maxlength="11" pattern="[0-9]{11}" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
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
                                    <input type="password" name="current_password" class="form-control" required placeholder="Enter current password">
                                </div>
                                <div class="form-group">
                                    <label>New Password</label>
                                    <input type="password" name="new_password" class="form-control" minlength="8" required placeholder="Enter new password">
                                </div>
                                <div class="form-group">
                                    <label>Confirm New Password</label>
                                    <input type="password" name="confirm_password" class="form-control" minlength="8" required placeholder="Confirm new password">
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