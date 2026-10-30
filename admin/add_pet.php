<?php
require_once __DIR__ . '/../shared_session_bootstrap.php';

// Normalize the role names used across the Admin/Staff portals.
// This keeps Add New Pet access consistent with the operational roles
// that can already access managepet.php, while keeping Veterinarians blocked.
$raw_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$role_key = preg_replace('/[\s\/\-]+/', '_', $raw_role);
$role_key = preg_replace('/_+/', '_', trim((string)$role_key, '_'));

$role_aliases = [
    'administrator' => 'admin',
    'shop_manager' => 'manager',
    'front_desk' => 'receptionist',
    'front_desk_receptionist' => 'receptionist',
    'grooming_staff' => 'groomer',
    'grooming_staff_groomer' => 'groomer',
    'pet_hotel_attendant' => 'pet_hotel_staff',
    'pet_hotel_staff_attendant' => 'pet_hotel_staff',
    'vet' => 'veterinarian',
    'veterinary' => 'veterinarian',
];

$current_role = $role_aliases[$role_key] ?? $role_key;
$allowed_roles = [
    'admin',
    'manager',
    'receptionist',
    'groomer',
    'pet_hotel_staff',
    'staff',
    'supervisor',
];

if (($_SESSION['logged_in'] ?? false) !== true) {
    header('Location: ../admin_login.php');
    exit();
}

if (!in_array($current_role, $allowed_roles, true)) {
    http_response_code(403);
    exit('Access denied.');
}

// Do not initialize the database connection for unauthenticated or unauthorized users.
require_once __DIR__ . '/../db_supabase.php';
date_default_timezone_set('Asia/Manila');

if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function addPetEscape($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function addPetPostString(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) || is_numeric($value) ? trim((string)$value) : '';
}

/** Resolve only known project upload folders, preventing arbitrary local paths. */
function addPetProfileImageSource($storedPath): string
{
    $path = trim((string)($storedPath ?? ''));
    if ($path === '') {
        return '';
    }

    // Allow already-hosted image URLs only; local filesystem paths are never emitted.
    if (filter_var($path, FILTER_VALIDATE_URL)) {
        $scheme = strtolower((string)parse_url($path, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) ? $path : '';
    }

    $path = str_replace('\\', '/', $path);
    $path = ltrim($path, '/');
    $path = preg_replace('#^(?:admin|staff|customer)/#i', '', $path);

    if (strpos($path, '../uploads/') === 0) {
        $relativeFile = substr($path, strlen('../uploads/'));
        $candidateRoots = [
            ['root' => realpath(__DIR__ . '/../uploads'), 'src' => '../uploads/'],
        ];
    } elseif (strpos($path, 'uploads/') === 0) {
        $relativeFile = substr($path, strlen('uploads/'));
        $candidateRoots = [
            ['root' => realpath(__DIR__ . '/uploads'), 'src' => 'uploads/'],
            ['root' => realpath(__DIR__ . '/../uploads'), 'src' => '../uploads/'],
        ];
    } else {
        return '';
    }

    if ($relativeFile === '' || strpos($relativeFile, '..') !== false || preg_match('/[\\x00-\\x1F]/', $relativeFile)) {
        return '';
    }

    foreach ($candidateRoots as $candidate) {
        $root = $candidate['root'];
        if ($root === false) {
            continue;
        }
        $resolved = realpath($root . DIRECTORY_SEPARATOR . $relativeFile);
        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($resolved !== false && is_file($resolved) && strpos($resolved, $rootPrefix) === 0) {
            $encoded = implode('/', array_map('rawurlencode', explode('/', $relativeFile)));
            return $candidate['src'] . $encoded;
        }
    }

    return '';
}

$success_msg = '';
$error_msg = '';
$form_values = [];

// Create the pet and its customer notification as one atomic operation.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_values = $_POST;
    $posted_token = $_POST['csrf_token'] ?? '';

    if (!is_string($posted_token) || !hash_equals($csrf_token, $posted_token)) {
        http_response_code(403);
        exit('Invalid security token. Refresh the page and try again.');
    }

    $p_name = addPetPostString('name');
    $p_type = addPetPostString('pet_type');
    $p_breed = addPetPostString('breed');
    $p_gender = addPetPostString('gender');
    $p_age = addPetPostString('age');
    $p_weight = addPetPostString('weight');
    $owner_id = filter_var($_POST['owner_id'] ?? null, FILTER_VALIDATE_INT);

    if ($p_name === '' || $p_type === '' || $p_gender === '' || !in_array($p_gender, ['Male', 'Female'], true) || $owner_id === false || $owner_id === null || $owner_id <= 0) {
        $error_msg = 'Please complete all required pet and owner fields using valid values.';
    } else {
        try {
            $pdo->beginTransaction();

            // Re-check the owner role on the server; the dropdown alone is not a security boundary.
            $user_stmt = $pdo->prepare(
                "SELECT full_name FROM users WHERE id = :owner_id AND LOWER(TRIM(COALESCE(role, ''))) = 'customer' LIMIT 1"
            );
            $user_stmt->execute([':owner_id' => $owner_id]);
            $user_row = $user_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user_row) {
                throw new RuntimeException('Selected customer was not found.');
            }

            $owner_name = (string)($user_row['full_name'] ?? 'Unknown Owner');
            $insert_stmt = $pdo->prepare(
                'INSERT INTO pets (owner_id, owner_name, name, pet_type, breed, gender, age, weight) '
                . 'VALUES (:owner_id, :owner_name, :name, :pet_type, :breed, :gender, :age, :weight)'
            );
            $insert_stmt->execute([
                ':owner_id' => $owner_id,
                ':owner_name' => $owner_name,
                ':name' => $p_name,
                ':pet_type' => $p_type,
                ':breed' => $p_breed,
                ':gender' => $p_gender,
                ':age' => $p_age,
                ':weight' => $p_weight,
            ]);

            $notif_stmt = $pdo->prepare(
                'INSERT INTO notifications (user_id, title, message, type, is_read, created_at) '
                . 'VALUES (:user_id, :title, :message, :type, 0, CURRENT_TIMESTAMP)'
            );
            $notif_stmt->execute([
                ':user_id' => $owner_id,
                ':title' => 'New Pet Profile Created!',
                ':message' => "A new pet profile for '{$p_name}' has been successfully registered to your account.",
                ':type' => 'system',
            ]);

            $pdo->commit();
            $success_msg = 'New pet successfully added and notification sent to owner!';
            $form_values = [];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Add pet database operation failed: ' . $e->getMessage());
            $error_msg = 'Unable to add the pet right now. Please try again.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Add pet operation failed: ' . $e->getMessage());
            $error_msg = 'Unable to add the pet right now. Please check the selected owner and try again.';
        }
    }
}

// Load only customer accounts for the owner selector.
$users_result = [];
try {
    $users_stmt = $pdo->query(
        "SELECT id, full_name FROM users WHERE LOWER(TRIM(COALESCE(role, ''))) = 'customer' ORDER BY full_name ASC, id ASC"
    );
    $users_result = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Add pet customer list query failed: ' . $e->getMessage());
    $error_msg = $error_msg ?: 'Unable to load customer records.';
}

// Shared profile information for the Admin/Staff header.
$admin_full_name = trim((string)($_SESSION['user_name'] ?? 'User'));
if ($admin_full_name === '') {
    $admin_full_name = 'User';
}
$profile_img_path = '';
if (isset($_SESSION['user_id'])) {
    $uid = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT);
    if ($uid !== false && $uid !== null && $uid > 0) {
        try {
            $profile_stmt = $pdo->prepare('SELECT full_name, profile_image FROM users WHERE id = :id LIMIT 1');
            $profile_stmt->execute([':id' => $uid]);
            $profile_data = $profile_stmt->fetch(PDO::FETCH_ASSOC);
            if ($profile_data) {
                $candidate_name = trim((string)($profile_data['full_name'] ?? ''));
                if ($candidate_name !== '') {
                    $admin_full_name = $candidate_name;
                    $_SESSION['user_name'] = $admin_full_name;
                }
                $profile_img_path = (string)($profile_data['profile_image'] ?? '');
            }
        } catch (PDOException $e) {
            error_log('Add pet profile lookup failed: ' . $e->getMessage());
        }
    }
}
$name_parts = preg_split('/\s+/', trim($admin_full_name));
$first_name = (string)($name_parts[0] ?? 'U');
$profile_img_src = addPetProfileImageSource($profile_img_path);

// Fetch only the newest 20 notification rows while retaining the full unread count.
$admin_notifications = [];
$unread_count = 0;
try {
    $notif_stmt = $pdo->query(
        'SELECT id, message, created_at, COUNT(*) OVER() AS total_unread '
        . 'FROM admin_notifications WHERE is_read = 0 '
        . 'ORDER BY created_at DESC, id DESC LIMIT 20'
    );
    $admin_notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($admin_notifications)) {
        $unread_count = (int)$admin_notifications[0]['total_unread'];
        foreach ($admin_notifications as &$notification_row) {
            unset($notification_row['total_unread']);
        }
        unset($notification_row);
    }
} catch (PDOException $e) {
    error_log('Add pet notification lookup failed: ' . $e->getMessage());
    $admin_notifications = [];
    $unread_count = 0;
}
?>
<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Add New Pet | Admin</title>

    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>

        :root {

            --admin-purple: #8b2cf5;

            --navy-dark: #001f3f;

            --bg-light: #f4f7f6;

            --white: #ffffff;

            --text-main: #2d3436;

            --border: #e2e8f0;

        }



        body {

            font-family: 'Plus Jakarta Sans', sans-serif;

            background-color: var(--bg-light);

            color: var(--text-main);

            padding: 40px;

            margin: 0;

            overflow-x: hidden;

        }



        .container { max-width: 800px; width: 100%; margin: 0 auto; }

        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }

        .btn-back { background: var(--white); color: var(--navy-dark); padding: 10px 20px; border-radius: 8px; border: 1px solid var(--border); text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; min-height: 42px; }

        .btn-back:hover { background: #e2e8f0; }

        .card { background: var(--white); padding: 30px; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); border: 1px solid var(--border); }

        .card-title { font-size: 20px; color: var(--navy-dark); font-weight: 700; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; border-bottom: 1px solid var(--border); padding-bottom: 15px; line-height: 1.35; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }

        .form-group { margin-bottom: 15px; min-width: 0; }

        .form-group.full-width { grid-column: span 2; }

        label { display: block; font-size: 13px; font-weight: 600; color: #64748b; margin-bottom: 8px; text-transform: uppercase; }

        input[type="text"], select { width: 100%; min-height: 46px; padding: 12px 15px; border: 1px solid var(--border); border-radius: 8px; font-size: 14px; outline: none; box-sizing: border-box; background: #fff; }

        input:focus, select:focus { border-color: var(--admin-purple); box-shadow: 0 0 0 3px rgba(139, 44, 245, 0.1); }

        .btn-submit { background: var(--admin-purple); color: white; padding: 12px 25px; border: none; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; transition: 0.2s; width: 100%; min-height: 46px; margin-top: 10px; }

        .btn-submit:hover { background: #7322cc; }

        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; font-size: 14px; line-height: 1.55; overflow-wrap: anywhere; }

        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }

        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }



        @media (max-width: 700px) {

            body { padding: 20px 14px; }

            .header { margin-bottom: 16px; }

            .btn-back { width: 100%; justify-content: center; padding: 11px 14px; }

            .card { padding: 20px 16px; border-radius: 10px; }

            .card-title { font-size: 18px; margin-bottom: 18px; }

            .form-grid { grid-template-columns: 1fr; gap: 0; }

            .form-group.full-width { grid-column: auto; }

            .form-group { margin-bottom: 14px; }

            label { font-size: 12px; }

            input[type="text"], select { font-size: 16px; min-height: 48px; }

            .btn-submit { font-size: 14px; min-height: 48px; }

            .alert { font-size: 13px; padding: 13px; }

        }



        @media (max-width: 380px) {

            body { padding: 14px 10px; }

            .card { padding: 16px 13px; }

            .card-title { font-size: 16px; }

        }

    
        /* ===== SHARED ADMIN SHELL ===== */
        :root {
            --navy-dark: #001f3f;
            --brand-yellow: #ffcc00;
            --sidebar-width: 260px;
            --text-main: #2d3436;
        }

        body {
            margin: 0;
            padding: 0;
            display: flex;
            min-height: 100vh;
            overflow-x: hidden;
        }

        aside {
            width: var(--sidebar-width);
            background: var(--navy-dark);
            color: #fff;
            display: flex;
            flex-direction: column;
            position: fixed;
            height: 100vh;
            z-index: 2000;
            left: 0;
            top: 0;
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
            margin-left: var(--sidebar-width);
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            width: calc(100% - var(--sidebar-width));
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
            overflow: hidden;
            text-overflow: ellipsis;
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
            top: -6px;
            right: -9px;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            background: #e11d48;
            color: white;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .notif-dropdown {
            display: none;
            position: absolute;
            right: 0;
            top: 35px;
            width: 320px;
            background: white;
            border: 1px solid #e2e8f0;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
            border-radius: 8px;
            z-index: 3000;
            text-align: left;
            overflow: hidden;
        }

        .notif-dropdown.show { display: block; }

        .notif-header {
            padding: 12px 15px;
            border-bottom: 1px solid #e2e8f0;
            font-weight: 700;
            font-size: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: var(--navy-dark);
        }

        .notif-body { max-height: 300px; overflow-y: auto; }
        .notif-item {
            padding: 12px 15px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 13px;
            color: #334155;
            line-height: 1.4;
        }
        .notif-item:last-child { border-bottom: none; }
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
            font-weight: 600;
        }

        .profile-wrapper {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 12px;
            border-left: 1px solid #e2e8f0;
            padding-left: 20px;
            cursor: pointer;
            user-select: none;
        }

        .admin-tag {
            background: var(--navy-dark);
            color: var(--brand-yellow);
            padding: 6px 16px;
            border-radius: 50px;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 1px;
            text-transform: uppercase;
            border: 1px solid var(--brand-yellow);
        }

        .top-avatar,
        .top-avatar-fallback {
            width: 35px;
            height: 35px;
            border-radius: 50%;
        }

        .top-avatar {
            object-fit: cover;
            border: 2px solid var(--navy-dark);
        }

        .top-avatar-fallback {
            background: linear-gradient(135deg, var(--navy-dark), #003366);
            color: var(--brand-yellow);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 14px;
            border: 2px solid var(--brand-yellow);
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
            z-index: 3000;
            overflow: hidden;
            text-align: left;
        }

        .profile-dropdown.show { display: block; }

        .profile-item {
            padding: 12px 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            color: var(--text-main);
            text-decoration: none;
        }

        .profile-item:hover { background: #f1f5f9; color: var(--navy-dark); }
        .profile-item i { width: 16px; text-align: center; }
        .profile-item.logout-text {
            color: #e11d48;
            border-top: 1px solid #f1f5f9;
        }

        .sidebar-backdrop { display: none; }

        @media (max-width: 900px) {
            html, body {
                width: 100%;
                min-width: 100%;
                overflow-x: hidden;
            }

            aside {
                transform: translateX(-100%);
                transition: transform 0.25s ease;
                box-shadow: 12px 0 30px rgba(0,0,0,0.18);
            }

            aside.mobile-open { transform: translateX(0); }

            .sidebar-backdrop {
                position: fixed;
                inset: 0;
                background: rgba(0,31,63,0.48);
                z-index: 1900;
            }

            .sidebar-backdrop.show { display: block; }

            main {
                margin-left: 0;
                width: 100%;
                min-width: 0;
            }

            .top-bar {
                height: 60px;
                padding: 0 10px;
                gap: 8px;
            }

            .mobile-menu-toggle { display: inline-flex; }

            .breadcrumb {
                font-size: 12px;
                gap: 6px;
            }

            .top-right-actions {
                gap: 8px;
            }

            .profile-wrapper {
                gap: 7px;
                padding-left: 8px;
            }

            .profile-wrapper > span:last-of-type {
                max-width: 105px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
                font-size: 12px !important;
            }

            .admin-tag {
                padding: 5px 9px;
                font-size: 8px;
            }

            .top-avatar,
            .top-avatar-fallback {
                width: 30px;
                height: 30px;
            }

            .notif-dropdown {
                width: min(320px, calc(100vw - 28px));
                right: -6px;
            }

            .container {
                padding: 22px 16px;
                max-width: 100%;
            }

            .header {
                margin-bottom: 14px;
            }

            .btn-back {
                width: 100%;
                justify-content: center;
            }

            .card {
                padding: 20px 16px;
            }

            .card-title {
                font-size: 18px;
            }

            .form-grid {
                grid-template-columns: 1fr;
                gap: 0;
            }

            .form-group.full-width {
                grid-column: auto;
            }
        }

        @media (max-width: 560px) {
            .top-bar {
                height: 58px;
                padding: 0 8px;
            }

            .topbar-left { gap: 7px; }

            .mobile-menu-toggle {
                width: 36px;
                height: 36px;
                border-radius: 9px;
                font-size: 16px;
            }

            .breadcrumb { font-size: 11px; }

            .top-right-actions { gap: 5px; }

            .notif-wrapper > i { font-size: 19px !important; }

            .profile-wrapper {
                gap: 5px;
                padding-left: 6px;
            }

            .profile-wrapper > span:last-of-type {
                display: none !important;
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

            .card {
                padding: 18px 13px;
            }

            .card-title {
                font-size: 16px;
            }

            .btn-back {
                min-height: 42px;
                font-size: 13px;
            }
        }

</style>

</head>
<body>
    <aside>
        <div class="sidebar-header">
            <img src="bg.png" alt="Boogie's Logo" class="sidebar-logo">
            <h2><?php echo $current_role === 'admin' ? "Boogie's Admin" : "Boogie's Staff"; ?></h2>
        </div>
        <nav class="nav-links">
            <a href="admindashboard.php" class="nav-item"><i class="fas fa-chart-line"></i> Dashboard</a>
            <a href="managebooking.php" class="nav-item"><i class="fas fa-calendar-alt"></i> Bookings</a>
            <a href="manageusers.php" class="nav-item"><i class="fas fa-users"></i> Users</a>
            <a href="managepet.php" class="nav-item active"><i class="fas fa-dog"></i> Pets</a>
            <?php if ($current_role === 'admin'): ?>
                <a href="managestaff.php" class="nav-item"><i class="fas fa-id-badge"></i> Personnel</a>
                <a href="managepromo.php" class="nav-item"><i class="fas fa-tags"></i> Promos</a>
                <a href="manage_services.php" class="nav-item"><i class="fas fa-list-ul"></i> Pricelist</a>
                <a href="sales_report.php" class="nav-item"><i class="fas fa-file-invoice-dollar"></i> Sales Report</a>
                <a href="admin_account_logs.php" class="nav-item"><i class="fas fa-clock-rotate-left"></i> Account Logs</a>
            <?php endif; ?>
        </nav>
    </aside>
    <div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeMobileSidebar()"></div>

    <main>
        <header class="top-bar">
            <div class="topbar-left">
                <button class="mobile-menu-toggle" type="button" aria-label="Open admin menu" aria-expanded="false" onclick="toggleMobileSidebar(event)">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="breadcrumb">
                    <i class="fas fa-dog" style="opacity:.5;font-size:14px;"></i>
                    Management / Add New Pet
                </div>
            </div>
            <div class="top-right-actions">
                <div class="notif-wrapper" onclick="toggleNotif(event)">
                    <i class="fa-solid fa-bell" style="font-size:22px;color:#64748b;"></i>
                    <span id="admin-notif-badge" class="notif-badge" style="display:<?php echo $unread_count > 0 ? 'inline-flex' : 'none'; ?>;">
                        <?php echo number_format($unread_count); ?>
                    </span>
                    <div class="notif-dropdown" id="notifBox" onclick="event.stopPropagation()">
                        <div class="notif-header">
                            Alerts
                            <a href="mark_notifications_read.php" id="mark-read-link" class="mark-read-btn" style="display:<?php echo $unread_count > 0 ? 'inline-block' : 'none'; ?>;">Mark all read</a>
                        </div>
                        <div class="notif-body" id="admin-notif-list">
                            <?php if ($admin_notifications): ?>
                                <?php foreach ($admin_notifications as $notif): ?>
                                    <div class="notif-item">
                                        <i class="fa-solid fa-circle-exclamation" style="color:#e11d48;margin-right:5px;"></i>
                                        <?php echo addPetEscape($notif['message'] ?? ''); ?>
                                        <br><small style="color:#94a3b8;font-size:11px;">
                                            <?php echo !empty($notif['created_at']) ? addPetEscape(date('M d, g:i A', strtotime((string)$notif['created_at']))) : ''; ?>
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
                    <span class="admin-tag"><?php echo addPetEscape(strtoupper($current_role)); ?></span>
                    <?php if ($profile_img_src !== ''): ?>
                        <img src="<?php echo addPetEscape($profile_img_src); ?>" class="top-avatar" alt="Profile Picture">
                    <?php else: ?>
                        <div class="top-avatar-fallback"><?php echo addPetEscape(strtoupper(substr($first_name, 0, 1))); ?></div>
                    <?php endif; ?>
                    <span style="font-size:14px;font-weight:600;color:#4a5568;display:flex;align-items:center;gap:6px;">
                        <?php echo addPetEscape($admin_full_name); ?>
                        <i class="fas fa-chevron-down" style="font-size:10px;color:#94a3b8;"></i>
                    </span>
                    <div class="profile-dropdown" id="profileBox" onclick="event.stopPropagation()">
                        <a href="admin_profile.php" class="profile-item"><i class="fas fa-user-circle"></i> My Profile</a>
                        <a href="../logout.php" class="profile-item logout-text"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </header>

        <div class="container">
            <div class="header">
                <a href="managepet.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back to Pets</a>
            </div>

            <?php if ($success_msg !== ''): ?>
                <div class="alert alert-success" role="status"><i class="fas fa-check-circle"></i> <?php echo addPetEscape($success_msg); ?></div>
            <?php endif; ?>

            <?php if ($error_msg !== ''): ?>
                <div class="alert alert-error" role="alert"><i class="fas fa-exclamation-circle"></i> <?php echo addPetEscape($error_msg); ?></div>
            <?php endif; ?>

            <div class="card">
                <div class="card-title">
                    <i class="fas fa-plus-circle" style="color:var(--admin-purple);"></i> Register New Pet
                </div>

                <form action="" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo addPetEscape($csrf_token); ?>">
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label for="owner_id">Select Pet Owner</label>
                            <select name="owner_id" id="owner_id" required>
                                <option value="" disabled <?php echo empty($form_values['owner_id']) ? 'selected' : ''; ?>>-- Select Owner (Customers Only) --</option>
                                <?php foreach ($users_result as $u_row): ?>
                                    <option value="<?php echo (int)$u_row['id']; ?>" <?php echo (string)($form_values['owner_id'] ?? '') === (string)$u_row['id'] ? 'selected' : ''; ?>>
                                        <?php echo addPetEscape($u_row['full_name'] ?? ''); ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if (!$users_result): ?>
                                    <option value="" disabled>No customer records found</option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="pet_name">Pet Name</label>
                            <input type="text" id="pet_name" name="name" placeholder="Enter pet's name" autocomplete="off" value="<?php echo addPetEscape($form_values['name'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="pet_type">Pet Type</label>
                            <input type="text" id="pet_type" name="pet_type" placeholder="e.g. Dog, Cat" autocomplete="off" value="<?php echo addPetEscape($form_values['pet_type'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="breed">Breed</label>
                            <input type="text" id="breed" name="breed" placeholder="e.g. Bulldog" autocomplete="off" value="<?php echo addPetEscape($form_values['breed'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="gender">Gender</label>
                            <select name="gender" id="gender" required>
                                <option value="" disabled <?php echo empty($form_values['gender']) ? 'selected' : ''; ?>>-- Select Gender --</option>
                                <option value="Male" <?php echo ($form_values['gender'] ?? '') === 'Male' ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo ($form_values['gender'] ?? '') === 'Female' ? 'selected' : ''; ?>>Female</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="age">Age (Years)</label>
                            <input type="text" id="age" name="age" placeholder="e.g. 6 yrs" inputmode="decimal" autocomplete="off" value="<?php echo addPetEscape($form_values['age'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="weight">Weight</label>
                            <input type="text" id="weight" name="weight" placeholder="e.g. 20" inputmode="decimal" autocomplete="off" value="<?php echo addPetEscape($form_values['weight'] ?? ''); ?>">
                        </div>
                    </div>
                    <button type="submit" class="btn-submit"><i class="fas fa-save"></i> Add Pet Profile</button>
                </form>
            </div>
        </div>
    </main>

    <script>
    (function () {
        const sidebar = document.querySelector('aside');
        const backdrop = document.getElementById('sidebarBackdrop');
        const menuButton = document.querySelector('.mobile-menu-toggle');
        const notifBox = document.getElementById('notifBox');
        const profileBox = document.getElementById('profileBox');
        const badge = document.getElementById('admin-notif-badge');
        const notifList = document.getElementById('admin-notif-list');
        const markReadBtn = document.getElementById('mark-read-link');
        let notificationRequestPending = false;
        let notificationTimer = null;

        window.toggleMobileSidebar = function (event) {
            if (event) event.stopPropagation();
            if (!sidebar) return;
            const isOpen = sidebar.classList.toggle('mobile-open');
            if (backdrop) backdrop.classList.toggle('show', isOpen);
            if (menuButton) menuButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        };

        window.closeMobileSidebar = function () {
            if (sidebar) sidebar.classList.remove('mobile-open');
            if (backdrop) backdrop.classList.remove('show');
            if (menuButton) menuButton.setAttribute('aria-expanded', 'false');
        };

        window.toggleNotif = function (event) {
            if (event) event.stopPropagation();
            if (notifBox) notifBox.classList.toggle('show');
            if (profileBox) profileBox.classList.remove('show');
        };

        window.toggleProfile = function (event) {
            if (event) event.stopPropagation();
            if (profileBox) profileBox.classList.toggle('show');
            if (notifBox) notifBox.classList.remove('show');
        };

        function renderAdminNotifications(data) {
            if (!badge || !notifList) return;
            const unread = Math.max(0, Number(data && data.unread ? data.unread : 0));
            badge.style.display = unread > 0 ? 'inline-flex' : 'none';
            badge.textContent = unread > 99 ? '99+' : String(unread);
            if (markReadBtn) markReadBtn.style.display = unread > 0 ? 'inline-block' : 'none';
            notifList.innerHTML = (data && typeof data.html === 'string' && data.html !== '')
                ? data.html
                : '<div class="notif-empty">No new notifications.</div>';
        }

        function fetchAdminNotifs() {
            if (document.hidden || notificationRequestPending || !badge || !notifList) return;
            notificationRequestPending = true;
            fetch('get_admin_notifs.php', {
                method: 'GET',
                cache: 'no-store',
                credentials: 'same-origin'
            })
            .then(function (response) {
                if (!response.ok) throw new Error('Notification request failed: HTTP ' + response.status);
                return response.json();
            })
            .then(renderAdminNotifications)
            .catch(function (error) {
                console.error('Error fetching admin notifications:', error);
            })
            .finally(function () {
                notificationRequestPending = false;
            });
        }

        function startNotificationPolling() {
            if (notificationTimer !== null) clearInterval(notificationTimer);
            fetchAdminNotifs();
            notificationTimer = setInterval(fetchAdminNotifs, 15000);
        }

        document.addEventListener('click', function (event) {
            if (!event.target.closest('.notif-wrapper') && notifBox) notifBox.classList.remove('show');
            if (!event.target.closest('.profile-wrapper') && profileBox) profileBox.classList.remove('show');
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                window.closeMobileSidebar();
                if (notifBox) notifBox.classList.remove('show');
                if (profileBox) profileBox.classList.remove('show');
            }
        });

        document.querySelectorAll('.nav-item').forEach(function (link) {
            link.addEventListener('click', function () {
                if (window.innerWidth <= 900) window.closeMobileSidebar();
            });
        });

        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                if (notificationTimer !== null) {
                    clearInterval(notificationTimer);
                    notificationTimer = null;
                }
            } else {
                startNotificationPolling();
            }
        });

        if (!document.hidden) startNotificationPolling();
    })();
    </script>
</body>
</html>