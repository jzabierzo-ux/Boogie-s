<?php
session_start();
require_once __DIR__ . '/../db_supabase.php';

// This page is part of the Admin portal. Keep Admin and Staff login separate.
$current_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
if (
    !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true ||
    $current_role !== 'admin'
) {
    header('Location: ../admin_login.php');
    exit();
}

$user_id = filter_var(
    $_SESSION['user_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
if (!$user_id) {
    header('Location: ../admin_login.php');
    exit();
}
$user_id = (int)$user_id;

// CSRF token shared by both profile update forms.
if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
$message = '';
$user_data = [];
$new_uploaded_path = null;

function boogiesResolveProfileImageUrl($storedPath): ?string
{
    if (!is_string($storedPath) || trim($storedPath) === '') {
        return null;
    }

    $path = trim(str_replace('\\', '/', $storedPath));

    // Supports a future Supabase Storage public URL, but only http(s) URLs.
    if (preg_match('#^https?://#i', $path)) {
        return filter_var($path, FILTER_VALIDATE_URL) ? $path : null;
    }

    $path = ltrim($path, '/');
    $filename = basename($path);
    if ($filename === '' || $filename === '.' || $filename === '..') {
        return null;
    }

    $projectRoot = dirname(__DIR__);
    $adminUploadPath = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $filename;
    $rootUploadPath = $projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $filename;

    // Admin profile uploads are stored in admin/uploads. Older customer/staff
    // profile paths may point to the project-root uploads directory.
    if (strpos($path, 'uploads/') === 0 && is_file($adminUploadPath)) {
        return 'uploads/' . rawurlencode($filename);
    }
    if ((strpos($path, '../uploads/') === 0 || strpos($path, 'uploads/') === 0) && is_file($rootUploadPath)) {
        return '../uploads/' . rawurlencode($filename);
    }

    return null;
}

// Fetch the signed-in Admin's profile.
try {
    $get_user = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $get_user->execute([':id' => $user_id]);
    $user_data = $get_user->fetch(PDO::FETCH_ASSOC) ?: [];

    if (!$user_data) {
        $message = '<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> User account not found.</div>';
    }
} catch (PDOException $e) {
    error_log('Admin profile fetch failed: ' . $e->getMessage());
    $message = '<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> Failed to load profile.</div>';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['update_profile']) || isset($_POST['update_password']))) {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($csrf_token, $submittedToken)) {
        http_response_code(403);
        $message = '<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> Invalid or expired security token. Refresh the page and try again.</div>';
    } elseif (empty($user_data)) {
        $message = '<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> The user profile could not be loaded.</div>';
    } elseif (isset($_POST['update_profile'])) {
        $full_name = trim((string)($_POST['full_name'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $contact_number = trim((string)($_POST['contact_number'] ?? ''));
        $profile_image = (string)($user_data['profile_image'] ?? '');
        $has_error = false;

        if ($full_name === '' || (function_exists('mb_strlen') ? mb_strlen($full_name, 'UTF-8') : strlen($full_name)) > 150) {
            $message = '<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> Enter a full name with no more than 150 characters.</div>';
            $has_error = true;
        } elseif ($username === '' || (function_exists('mb_strlen') ? mb_strlen($username, 'UTF-8') : strlen($username)) > 100) {
            $message = '<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> Enter a username with no more than 100 characters.</div>';
            $has_error = true;
        } elseif ($contact_number !== '' && !preg_match('/^[0-9]{11}$/', $contact_number)) {
            $message = '<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> Invalid contact number. Enter exactly 11 digits or leave it blank.</div>';
            $has_error = true;
        }

        // Do not let one Admin overwrite another account's username.
        if (!$has_error) {
            try {
                $username_check = $pdo->prepare(
                    'SELECT id FROM users WHERE LOWER(username) = LOWER(:username) AND id <> :id LIMIT 1'
                );
                $username_check->execute([':username' => $username, ':id' => $user_id]);
                if ($username_check->fetchColumn()) {
                    $message = '<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> That username is already in use. Choose another one.</div>';
                    $has_error = true;
                }
            } catch (PDOException $e) {
                error_log('Admin profile username check failed: ' . $e->getMessage());
                $message = '<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> Unable to validate the username right now.</div>';
                $has_error = true;
            }
        }

        // Validate optional image replacement using its actual MIME type.
        if (!$has_error && isset($_FILES['profile_picture']) && (int)($_FILES['profile_picture']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $upload = $_FILES['profile_picture'];
            $maxSize = 5 * 1024 * 1024;
            $allowedMimes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
            ];

            if ((int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $message = '<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> Image upload failed. Please try again.</div>';
                $has_error = true;
            } elseif ((int)($upload['size'] ?? 0) <= 0 || (int)$upload['size'] > $maxSize) {
                $message = '<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> The image must be larger than 0 bytes and no more than 5 MB.</div>';
                $has_error = true;
            } elseif (!is_uploaded_file((string)($upload['tmp_name'] ?? ''))) {
                $message = '<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> Invalid uploaded image.</div>';
                $has_error = true;
            } else {
                $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
                $mime = $finfo ? finfo_file($finfo, (string)$upload['tmp_name']) : false;
                if ($finfo) {
                    finfo_close($finfo);
                }

                if (!is_string($mime) || !isset($allowedMimes[$mime])) {
                    $message = '<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> Invalid image type. Only JPG, PNG, and GIF files are allowed.</div>';
                    $has_error = true;
                } else {
                    $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
                    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                        $message = '<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> The profile image folder could not be created.</div>';
                        $has_error = true;
                    } elseif (!is_writable($uploadDir)) {
                        $message = '<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> The profile image folder is not writable.</div>';
                        $has_error = true;
                    } else {
                        try {
                            $randomName = 'admin_' . $user_id . '_' . bin2hex(random_bytes(12)) . '.' . $allowedMimes[$mime];
                        } catch (Throwable $e) {
                            error_log('Admin profile image filename generation failed: ' . $e->getMessage());
                            $message = '<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> Unable to prepare the uploaded image.</div>';
                            $has_error = true;
                            $randomName = '';
                        }

                        if (!$has_error) {
                            $destination = $uploadDir . DIRECTORY_SEPARATOR . $randomName;
                            if (move_uploaded_file((string)$upload['tmp_name'], $destination)) {
                                $new_uploaded_path = $destination;
                                $profile_image = 'uploads/' . $randomName;
                            } else {
                                $message = '<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> Failed to save the uploaded image.</div>';
                                $has_error = true;
                            }
                        }
                    }
                }
            }
        }

        if (!$has_error) {
            try {
                $update_stmt = $pdo->prepare(
                    'UPDATE users SET full_name = :full_name, username = :username, contact_number = :contact_number, profile_image = :profile_image WHERE id = :id'
                );
                $update_stmt->execute([
                    ':full_name' => $full_name,
                    ':username' => $username,
                    ':contact_number' => $contact_number,
                    ':profile_image' => $profile_image,
                    ':id' => $user_id,
                ]);

                $_SESSION['user_name'] = $full_name;
                $message = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Profile updated successfully!</div>';

                $get_user = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
                $get_user->execute([':id' => $user_id]);
                $user_data = $get_user->fetch(PDO::FETCH_ASSOC) ?: $user_data;
                $new_uploaded_path = null; // Keep the new file after the database update succeeds.
            } catch (PDOException $e) {
                if ($new_uploaded_path && is_file($new_uploaded_path)) {
                    @unlink($new_uploaded_path);
                }
                error_log('Admin profile update failed: ' . $e->getMessage());
                $message = '<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> Error updating profile. Check that the username is unique and the database columns are available.</div>';
            }
        }
    } elseif (isset($_POST['update_password'])) {
        $current_password = (string)($_POST['current_password'] ?? '');
        $new_password = (string)($_POST['new_password'] ?? '');
        $confirm_password = (string)($_POST['confirm_password'] ?? '');

        if (!password_verify($current_password, (string)($user_data['password'] ?? ''))) {
            $message = '<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> Incorrect current password.</div>';
        } elseif (strlen($new_password) < 8) {
            $message = '<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> The new password must be at least 8 characters.</div>';
        } elseif (strlen($new_password) > 4096) {
            $message = '<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> The new password is too long.</div>';
        } elseif ($new_password !== $confirm_password) {
            $message = '<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> New passwords do not match.</div>';
        } else {
            try {
                $hashed_new_password = password_hash($new_password, PASSWORD_DEFAULT);
                $update_pass_stmt = $pdo->prepare('UPDATE users SET password = :password WHERE id = :id');
                $update_pass_stmt->execute([':password' => $hashed_new_password, ':id' => $user_id]);
                $message = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Password successfully updated!</div>';

                $get_user = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
                $get_user->execute([':id' => $user_id]);
                $user_data = $get_user->fetch(PDO::FETCH_ASSOC) ?: $user_data;
            } catch (PDOException $e) {
                error_log('Admin password update failed: ' . $e->getMessage());
                $message = '<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> Failed to update password.</div>';
            }
        }
    }
}

// Keep submitted profile values visible if validation fails.
$display_name_raw = (string)($user_data['full_name'] ?? 'User');
$display_username_raw = (string)($user_data['username'] ?? 'No Username');
$display_phone_raw = (string)($user_data['contact_number'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile']) && strpos($message, 'alert-error') !== false) {
    $display_name_raw = trim((string)($_POST['full_name'] ?? $display_name_raw));
    $display_username_raw = trim((string)($_POST['username'] ?? $display_username_raw));
    $display_phone_raw = trim((string)($_POST['contact_number'] ?? $display_phone_raw));
}
$display_name = htmlspecialchars($display_name_raw, ENT_QUOTES, 'UTF-8');
$display_username = htmlspecialchars($display_username_raw, ENT_QUOTES, 'UTF-8');
$display_phone = htmlspecialchars($display_phone_raw, ENT_QUOTES, 'UTF-8');
$display_image_raw = !empty($user_data['profile_image']) ? (string)$user_data['profile_image'] : null;
$profile_image_url = boogiesResolveProfileImageUrl($display_image_raw);
$display_image = $profile_image_url !== null ? htmlspecialchars($profile_image_url, ENT_QUOTES, 'UTF-8') : null;
$joinTimestamp = !empty($user_data['created_at']) ? strtotime((string)$user_data['created_at']) : false;
$join_date = $joinTimestamp !== false ? date('F d, Y', $joinTimestamp) : 'Unknown';

$display_role_badge = 'SYSTEM ADMIN';
$admin_full_name = $display_name_raw;
$first_name = trim(explode(' ', $admin_full_name)[0] ?? 'A', ',');
$admin_notifications = [];
$unread_count = 0;
try {
    $admin_notif_stmt = $pdo->prepare(
        'SELECT id, message, created_at FROM admin_notifications WHERE is_read = 0 ORDER BY created_at DESC LIMIT 20'
    );
    $admin_notif_stmt->execute();
    $admin_notifications = $admin_notif_stmt->fetchAll(PDO::FETCH_ASSOC);
    $unread_count = count($admin_notifications);
} catch (PDOException $e) {
    error_log('Admin profile notifications fetch failed: ' . $e->getMessage());
    $admin_notifications = [];
    $unread_count = 0;
}

$top_profile_name_raw = $display_name_raw;
$top_profile_initial = strtoupper(substr(trim($top_profile_name_raw) !== '' ? trim($top_profile_name_raw) : 'A', 0, 1));
$top_profile_image = $profile_image_url;
?>

    <!DOCTYPE html>

    <html lang="en">

    <head>

        <meta charset="UTF-8">

        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>My Profile &amp; Settings | Boogie's Admin</title>

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

            /* =========================================================
            SHARED ADMIN SHELL / RESPONSIVE MOBILE LAYOUT
            Matches the managepet.php admin layout pattern.
            ========================================================= */
            :root {
                --navy-dark: #001f3f;
                --brand-yellow: #ffcc00;
            }

            html, body {
                width: 100%;
                min-width: 0;
            }

            body {
                overflow-x: hidden;
            }

            aside {
                width: 260px;
                background: var(--navy-dark);
                color: #fff;
                display: flex;
                flex-direction: column;
                position: fixed;
                top: 0;
                left: 0;
                height: 100vh;
                z-index: 100;
            }

            .sidebar-header {
                padding: 30px 20px;
                text-align: center;
                border-bottom: 1px solid rgba(255,255,255,0.05);
            }

            .sidebar-logo {
                width: 80px;
                height: auto;
                object-fit: contain;
                margin-bottom: 10px;
            }

            .sidebar-header h2 {
                font-size: 16px;
                color: var(--brand-yellow);
                text-transform: uppercase;
                letter-spacing: 1px;
                font-weight: 800;
            }

            .nav-links {
                flex-grow: 1;
                padding: 20px 15px;
                display: flex;
                flex-direction: column;
                gap: 5px;
                overflow-y: auto;
            }

            .nav-item {
                display: flex;
                align-items: center;
                padding: 14px 20px;
                color: #94a3b8;
                text-decoration: none;
                transition: all 0.3s ease;
                font-size: 14px;
                font-weight: 500;
                border-radius: 10px;
                position: relative;
            }

            .nav-item i {
                width: 32px;
                font-size: 18px;
            }

            .nav-item:hover {
                color: #fff;
                background: rgba(255,255,255,0.05);
                transform: translateX(4px);
            }

            .nav-item.active {
                color: var(--brand-yellow);
                background: rgba(255,204,0,0.08);
                font-weight: 700;
            }

            .nav-item.active::before {
                content: '';
                position: absolute;
                left: -15px;
                top: 15%;
                height: 70%;
                width: 5px;
                background: var(--brand-yellow);
                border-radius: 0 5px 5px 0;
                box-shadow: 2px 0 8px rgba(255,204,0,0.5);
            }

            main {
                margin-left: 260px;
                flex-grow: 1;
                display: flex;
                flex-direction: column;
                min-height: 100vh;
                min-width: 0;
            }

            .top-bar {
                background: #fff;
                height: 70px;
                padding: 0 40px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                box-shadow: 0 1px 10px rgba(0,0,0,0.08);
                position: sticky;
                top: 0;
                z-index: 1000;
                gap: 20px;
            }

            .topbar-left {
                display: flex;
                align-items: center;
                gap: 12px;
                min-width: 0;
            }

            .mobile-menu-toggle {
                display: none;
                border: 0;
                background: var(--navy-dark);
                color: var(--brand-yellow);
                width: 40px;
                height: 40px;
                border-radius: 10px;
                cursor: pointer;
                font-size: 18px;
                align-items: center;
                justify-content: center;
                flex: 0 0 auto;
            }

            .breadcrumb {
                font-weight: 700;
                color: var(--navy-dark);
                font-size: 15px;
                display: flex;
                align-items: center;
                gap: 8px;
                min-width: 0;
                white-space: nowrap;
            }

            .top-right-actions {
                display: flex;
                align-items: center;
                gap: 20px;
                margin-left: auto;
                flex: 0 0 auto;
            }

            .notif-wrapper {
                position: relative;
                display: inline-flex;
                align-items: center;
                cursor: pointer;
            }

            .notif-badge {
                position: absolute;
                top: -8px;
                right: -9px;
                min-width: 18px;
                height: 18px;
                padding: 0 5px;
                border-radius: 999px;
                background: #e11d48;
                color: #fff;
                font-size: 10px;
                font-weight: 800;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                line-height: 18px;
            }

            .notif-dropdown {
                display: none;
                position: absolute;
                right: 0;
                top: 36px;
                width: 320px;
                max-width: calc(100vw - 28px);
                background: #fff;
                border: 1px solid #e2e8f0;
                box-shadow: 0 10px 20px rgba(15,23,42,0.12);
                border-radius: 10px;
                z-index: 2000;
                text-align: left;
                overflow: hidden;
            }

            .notif-dropdown.show {
                display: block;
            }

            .notif-header {
                padding: 12px 15px;
                border-bottom: 1px solid #e2e8f0;
                font-weight: 800;
                font-size: 14px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                color: #0f172a;
            }

            .notif-body {
                max-height: 300px;
                overflow-y: auto;
            }

            .notif-item {
                padding: 12px 15px;
                border-bottom: 1px solid #f1f5f9;
                font-size: 13px;
                color: #334155;
                line-height: 1.4;
            }

            .notif-item:last-child {
                border-bottom: none;
            }

            .notif-empty {
                padding: 20px;
                text-align: center;
                color: #94a3b8;
                font-size: 13px;
            }

            .mark-read-btn {
                font-size: 11px;
                color: #3b82f6;
                text-decoration: none;
                font-weight: 700;
            }

            .mark-read-btn:hover {
                text-decoration: underline;
            }

            .profile-wrapper {
                position: relative;
                display: flex;
                align-items: center;
                gap: 8px;
                padding-left: 12px;
                border-left: 1px solid #e2e8f0;
                cursor: pointer;
                min-width: 0;
            }

            .admin-tag {
                background: var(--navy-dark);
                color: var(--brand-yellow);
                border-radius: 6px;
                padding: 5px 10px;
                font-size: 9px;
                font-weight: 800;
                letter-spacing: 0.5px;
                white-space: nowrap;
            }

            .top-avatar,
            .top-avatar-fallback {
                width: 32px;
                height: 32px;
                border-radius: 50%;
                flex: 0 0 auto;
            }

            .top-avatar {
                object-fit: cover;
                border: 2px solid #e2e8f0;
                background: #f1f5f9;
            }

            .top-avatar-fallback {
                display: flex;
                align-items: center;
                justify-content: center;
                background: #f1f5f9;
                color: var(--navy-dark);
                font-weight: 800;
                font-size: 13px;
                border: 2px solid #e2e8f0;
            }

            .profile-name {
                font-size: 14px;
                font-weight: 600;
                color: #4a5568;
                display: flex;
                align-items: center;
                gap: 6px;
                min-width: 0;
            }

            .profile-name-text {
                max-width: 180px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .profile-dropdown {
                display: none;
                position: absolute;
                right: 0;
                top: 45px;
                width: 200px;
                background: #fff;
                border: 1px solid #e2e8f0;
                box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
                border-radius: 8px;
                z-index: 2000;
                overflow: hidden;
                text-align: left;
            }

            .profile-dropdown.show {
                display: block;
            }

            .profile-item {
                padding: 12px 15px;
                display: flex;
                align-items: center;
                gap: 10px;
                font-size: 13px;
                color: #1c1e21;
                text-decoration: none;
                transition: background 0.2s;
            }

            .profile-item:hover {
                background: #f1f5f9;
                color: var(--navy-dark);
            }

            .profile-item i {
                width: 16px;
                text-align: center;
            }

            .profile-item.logout-text {
                color: #e11d48;
                border-top: 1px solid #f1f5f9;
            }

            .profile-item.logout-text:hover {
                background: #fff1f2;
                color: #be123c;
            }

            .sidebar-backdrop {
                display: none;
            }

            @media (max-width: 1200px) {
                .container {
                    padding: 30px;
                }
            }

            @media (max-width: 900px) {
                html, body {
                    width: 100%;
                    min-width: 100%;
                    overflow-x: hidden;
                }

                body {
                    display: block;
                }

                aside {
                    transform: translateX(-100%);
                    transition: transform 0.25s ease;
                    box-shadow: 12px 0 30px rgba(0,0,0,0.18);
                    z-index: 2000;
                }

                aside.mobile-open {
                    transform: translateX(0);
                }

                .sidebar-backdrop {
                    position: fixed;
                    inset: 0;
                    background: rgba(0,31,63,0.48);
                    z-index: 1900;
                }

                .sidebar-backdrop.show {
                    display: block;
                }

                main {
                    margin-left: 0;
                    width: 100%;
                    max-width: 100%;
                    min-width: 0;
                }

                .top-bar {
                    width: 100%;
                    max-width: 100%;
                    height: 64px;
                    padding: 0 14px;
                    gap: 10px;
                }

                .topbar-left {
                    flex: 1 1 auto;
                    min-width: 0;
                }

                .mobile-menu-toggle {
                    display: inline-flex;
                }

                .breadcrumb {
                    font-size: 13px;
                    gap: 6px;
                    overflow: hidden;
                    text-overflow: ellipsis;
                }

                .breadcrumb i {
                    font-size: 13px !important;
                }

                .top-right-actions {
                    gap: 10px;
                    min-width: 0;
                    margin-left: auto;
                }

                .profile-wrapper {
                    gap: 8px;
                    padding-left: 10px;
                }

                .profile-name-text {
                    max-width: 110px;
                    font-size: 12px;
                }

                .admin-tag {
                    padding: 5px 10px;
                    font-size: 9px;
                }

                .top-avatar,
                .top-avatar-fallback {
                    width: 32px;
                    height: 32px;
                }

                .notif-dropdown {
                    width: min(320px, calc(100vw - 28px));
                    right: -6px;
                }

                .container {
                    width: 100%;
                    max-width: 100%;
                    padding: 22px 16px 28px;
                }
            }

            @media (max-width: 560px) {
                .top-bar {
                    height: 58px;
                    padding: 0 8px;
                }

                .topbar-left {
                    gap: 7px;
                }

                .mobile-menu-toggle {
                    width: 36px;
                    height: 36px;
                    border-radius: 9px;
                    font-size: 16px;
                }

                .breadcrumb {
                    font-size: 11px;
                }

                .top-right-actions {
                    gap: 5px;
                }

                .profile-wrapper {
                    gap: 5px;
                    padding-left: 6px;
                }

                .profile-name {
                    display: none;
                }

                .admin-tag {
                    padding: 4px 7px;
                    font-size: 7px;
                }

                .top-avatar,
                .top-avatar-fallback {
                    width: 29px;
                    height: 29px;
                }

                .container {
                    padding: 16px 12px 22px;
                }
            }

        </style>

    </head>

    <body>

        <aside>
            <div class="sidebar-header">
                <img src="bg.png" alt="Boogie's Logo" class="sidebar-logo">
                <h2><?php echo ($current_role === 'admin') ? "Boogie's Admin" : "Boogie's Staff"; ?></h2>
            </div>

            <nav class="nav-links">
                <a href="admindashboard.php" class="nav-item">
                    <i class="fas fa-chart-line"></i> Dashboard
                </a>
                <a href="managebooking.php" class="nav-item">
                    <i class="fas fa-calendar-alt"></i> Bookings
                </a>
                <a href="manageusers.php" class="nav-item">
                    <i class="fas fa-users"></i> Users
                </a>
                <a href="managepet.php" class="nav-item">
                    <i class="fas fa-dog"></i> Pets
                </a>

                <?php if ($current_role === 'admin'): ?>
                    <a href="managestaff.php" class="nav-item">
                        <i class="fas fa-id-badge"></i> Personnel
                    </a>
                    <a href="managepromo.php" class="nav-item">
                        <i class="fas fa-tags"></i> Promos
                    </a>
                    <a href="manage_services.php" class="nav-item">
                        <i class="fas fa-list-ul"></i> Pricelist
                    </a>
                    <a href="sales_report.php" class="nav-item">
                        <i class="fas fa-file-invoice-dollar"></i> Sales Report
                    </a>
                    <a href="admin_account_logs.php" class="nav-item">
                        <i class="fa-solid fa-clock-rotate-left"></i> Account Logs
                    </a>
                <?php endif; ?>
            </nav>
        </aside>

        <div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeMobileSidebar()"></div>

        <main>

            <header class="top-bar">
                <div class="topbar-left">
                    <button
                        class="mobile-menu-toggle"
                        type="button"
                        aria-label="Open admin menu"
                        aria-expanded="false"
                        onclick="toggleMobileSidebar(event)"
                    >
                        <i class="fas fa-bars" aria-hidden="true"></i>
                    </button>

                    <div class="breadcrumb">
                        <i class="fas fa-user-circle" style="opacity: 0.5; font-size: 14px;"></i>
                        Account / Profile &amp; Settings
                    </div>
                </div>

                <div class="top-right-actions">
                    <div class="notif-wrapper" onclick="toggleNotif(event)">
                        <i class="fa-solid fa-bell" style="font-size:22px;color:#64748b;"></i>

                        <span
                            id="admin-notif-badge"
                            class="notif-badge"
                            style="display: <?php echo $unread_count > 0 ? 'inline-flex' : 'none'; ?>;"
                        >
                            <?php echo $unread_count; ?>
                        </span>

                        <div class="notif-dropdown" id="notifBox" onclick="event.stopPropagation()">
                            <div class="notif-header">
                                Alerts
                                <a
                                    href="mark_notifications_read.php"
                                    id="mark-read-link"
                                    class="mark-read-btn"
                                    style="display: <?php echo $unread_count > 0 ? 'inline-block' : 'none'; ?>;"
                                >
                                    Mark all read
                                </a>
                            </div>

                            <div class="notif-body" id="admin-notif-list">
                                <?php if ($unread_count > 0): ?>
                                    <?php foreach ($admin_notifications as $notif): ?>
                                        <div class="notif-item">
                                            <i class="fa-solid fa-circle-exclamation" style="color:#e11d48;margin-right:5px;"></i>
                                            <?php
                                            echo htmlspecialchars(
                                                (string)($notif['message'] ?? ''),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            );
                                            ?>
                                            <br>
                                            <small style="color:#94a3b8;font-size:11px;">
                                                <?php
                                                echo !empty($notif['created_at'])
                                                    ? date('M d, g:i A', strtotime((string)$notif['created_at']))
                                                    : '';
                                                ?>
                                            </small>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="notif-empty">No new notifications.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="profile-wrapper" onclick="toggleProfile(event)">
                        <span class="admin-tag">
                            <?php echo strtoupper($current_role ?: 'ADMIN'); ?>
                        </span>

                        <?php if (!empty($top_profile_image)): ?>
                            <img
                                src="<?php echo htmlspecialchars((string)$top_profile_image, ENT_QUOTES, 'UTF-8'); ?>"
                                class="top-avatar"
                                alt="Profile Picture"
                            >
                        <?php else: ?>
                            <div class="top-avatar-fallback">
                                <?php echo htmlspecialchars($top_profile_initial, ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                        <?php endif; ?>

                        <span class="profile-name">
                            <span class="profile-name-text">
                                <?php echo htmlspecialchars($top_profile_name_raw, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <i class="fas fa-chevron-down" style="font-size:10px;color:#94a3b8;"></i>
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

                    <h2>Profile & Settings</h2>

                    <a href="admindashboard.php" class="btn-back">

                        <i class="fas fa-arrow-left"></i> Back to Dashboard

                    </a>

                </div>

                <?php echo $message; ?>

                <div class="profile-wrapper">

                    <div class="card profile-card">

                        <div class="profile-avatar">

                            <?php if (!empty($display_image)): ?>

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

                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

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

                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

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

    <script>
    (function () {
        function closeFloatingMenus() {
            const notifBox = document.getElementById('notifBox');
            const profileBox = document.getElementById('profileBox');

            if (notifBox) notifBox.classList.remove('show');
            if (profileBox) profileBox.classList.remove('show');
        }

        window.toggleMobileSidebar = function (event) {
            if (event) event.stopPropagation();

            const sidebar = document.querySelector('aside');
            const backdrop = document.getElementById('sidebarBackdrop');
            const button = document.querySelector('.mobile-menu-toggle');

            if (!sidebar || !backdrop) return;

            const isOpen = sidebar.classList.toggle('mobile-open');
            backdrop.classList.toggle('show', isOpen);

            if (button) {
                button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            }

            if (isOpen) closeFloatingMenus();
        };

        window.closeMobileSidebar = function () {
            const sidebar = document.querySelector('aside');
            const backdrop = document.getElementById('sidebarBackdrop');
            const button = document.querySelector('.mobile-menu-toggle');

            if (sidebar) sidebar.classList.remove('mobile-open');
            if (backdrop) backdrop.classList.remove('show');
            if (button) button.setAttribute('aria-expanded', 'false');
        };

        window.toggleNotif = function (event) {
            if (event) event.stopPropagation();

            const notifBox = document.getElementById('notifBox');
            const profileBox = document.getElementById('profileBox');

            if (profileBox) profileBox.classList.remove('show');
            if (notifBox) notifBox.classList.toggle('show');
        };

        window.toggleProfile = function (event) {
            if (event) event.stopPropagation();

            const profileBox = document.getElementById('profileBox');
            const notifBox = document.getElementById('notifBox');

            if (notifBox) notifBox.classList.remove('show');
            if (profileBox) profileBox.classList.toggle('show');
        };

        function renderAdminNotifications(data) {
            const badge = document.getElementById('admin-notif-badge');
            const notifList = document.getElementById('admin-notif-list');
            const markReadBtn = document.getElementById('mark-read-link');

            if (!badge || !notifList) return;

            const unread = Number(data && data.unread ? data.unread : 0);

            badge.style.display = unread > 0 ? 'inline-flex' : 'none';
            badge.textContent = unread;

            if (markReadBtn) {
                markReadBtn.style.display = unread > 0 ? 'inline-block' : 'none';
            }

            notifList.innerHTML = data && data.html
                ? data.html
                : '<div class="notif-empty">No new notifications.</div>';
        }

        function fetchAdminNotifs() {
            fetch('get_admin_notifs.php', {
                method: 'GET',
                cache: 'no-store',
                credentials: 'same-origin'
            })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Notification request failed: HTTP ' + response.status);
                }
                return response.json();
            })
            .then(renderAdminNotifications)
            .catch(function (error) {
                console.error('Error fetching admin notifications:', error);
            });
        }

        document.addEventListener('click', function (event) {
            if (!event.target.closest('.notif-wrapper')) {
                const notifBox = document.getElementById('notifBox');
                if (notifBox) notifBox.classList.remove('show');
            }

            if (!event.target.closest('.profile-wrapper')) {
                const profileBox = document.getElementById('profileBox');
                if (profileBox) profileBox.classList.remove('show');
            }
        });

        document.addEventListener('DOMContentLoaded', function () {
            fetchAdminNotifs();
            setInterval(fetchAdminNotifs, 3000);
        });

        window.addEventListener('resize', function () {
            if (window.innerWidth > 900) {
                window.closeMobileSidebar();
            }
        });
    })();
    </script>

    </body>

    </html>
