<?php
session_start();
include '../db_supabase.php';

// --- ROLE-BASED ACCESS CONTROL ---
$raw_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$role_aliases = [
    'administrator' => 'admin',
    'shop manager' => 'manager',
    'shop_manager' => 'manager',
    'front desk' => 'receptionist',
    'front desk / receptionist' => 'receptionist',
    'front_desk' => 'receptionist',
    'front_desk_receptionist' => 'receptionist',
    'grooming staff' => 'groomer',
    'grooming staff / groomer' => 'groomer',
    'grooming_staff' => 'groomer',
    'grooming_staff_groomer' => 'groomer',
    'pet hotel staff' => 'pet_hotel_staff',
    'pet hotel staff / attendant' => 'pet_hotel_staff',
    'pet_hotel_attendant' => 'pet_hotel_staff',
    'pet_hotel_staff_attendant' => 'pet_hotel_staff',
    'vet' => 'veterinarian',
    'veterinary' => 'veterinarian',
    'veterinary assistant' => 'vet_assistant',
    'vet assistant' => 'vet_assistant',
    'veterinary_assistant' => 'vet_assistant',
    'veterinary nurse' => 'vet_nurse',
    'vet nurse' => 'vet_nurse',
    'veterinary_nurse' => 'vet_nurse',
    // Keep compatibility with existing legacy staff accounts.
    'staff' => 'groomer',
    'supervisor' => 'receptionist',
];
$current_role = $role_aliases[$raw_role] ?? $raw_role;
if ($current_role !== '') {
    $_SESSION['role'] = $current_role;
}
$is_admin = ($current_role === 'admin');
$can_transfer_owner = in_array($current_role, ['admin', 'manager', 'receptionist'], true);

if (($_SESSION['logged_in'] ?? false) !== true) {
    // Admin and staff login pages remain separate.
    $login_target = ($current_role === 'admin') ? '../admin_login.php' : '../staff/stafflogin.php';
    header('Location: ' . $login_target);
    exit();
}

if (empty($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Access denied: invalid session.');
}

// Veterinary roles use their dedicated portal. Pet Hotel Staff are limited to
// permitted booking functions and cannot open the general pet registry.
if (in_array($current_role, ['veterinarian', 'vet_assistant', 'vet_nurse'], true)) {
    header('Location: ../staff/staffdashboard.php');
    exit();
}
if ($current_role === 'pet_hotel_staff') {
    header('Location: managebooking.php');
    exit();
}

$allowed_pet_roles = ['admin', 'manager', 'receptionist', 'groomer'];
if (!in_array($current_role, $allowed_pet_roles, true)) {
    http_response_code(403);
    exit('Access denied. This role cannot edit pet records.');
}

// CSRF protection for the edit form.
if (empty($_SESSION['editpet_csrf_token']) || !is_string($_SESSION['editpet_csrf_token'])) {
    $_SESSION['editpet_csrf_token'] = bin2hex(random_bytes(32));
}

function editpet_post_string(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_scalar($value) ? trim((string)$value) : '';
}

// Validate the requested pet ID before querying the database.
$pet_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($pet_id === false || $pet_id === null) {
    http_response_code(400);
    exit('Invalid Pet ID.');
}
$pet_id = (int)$pet_id;

$success_msg = '';
$error_msg = '';
$admin_full_name = 'User';
$profile_img_path = '';
$first_name = 'User';

// --- FETCH SIGNED-IN USER PROFILE ---
try {
    $profile_stmt = $pdo->prepare('SELECT full_name, profile_image FROM users WHERE id = :id LIMIT 1');
    $profile_stmt->execute([':id' => (int)$_SESSION['user_id']]);
    $profile_data = $profile_stmt->fetch(PDO::FETCH_ASSOC);

    if ($profile_data) {
        $admin_full_name = (string)($profile_data['full_name'] ?? 'User');
        $profile_img_path = (string)($profile_data['profile_image'] ?? '');
        $_SESSION['user_name'] = $admin_full_name;
    } else {
        $admin_full_name = (string)($_SESSION['user_name'] ?? 'User');
    }
} catch (PDOException $e) {
    error_log('Edit pet profile fetch failed: ' . $e->getMessage());
    $admin_full_name = (string)($_SESSION['user_name'] ?? 'User');
}
$first_name = trim(explode(' ', $admin_full_name)[0] ?? 'User', ',');
if ($first_name === '') {
    $first_name = 'U';
}

// Load the target pet first. This also prevents updates to a non-existent ID.
try {
    $pet_stmt = $pdo->prepare('SELECT * FROM pets WHERE id = :pet_id LIMIT 1');
    $pet_stmt->execute([':pet_id' => $pet_id]);
    $row = $pet_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Edit pet record fetch failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load the pet record at this time.');
}

if (!$row) {
    http_response_code(404);
    exit('Pet not found in the database.');
}

// Only customer accounts are eligible to own a pet. This list is used for
// ownership transfer, not for granting a user access to this page.
$users_list = [];
if ($can_transfer_owner) {
    try {
        $users_query = $pdo->prepare("SELECT id, full_name, email FROM users WHERE role = 'customer' ORDER BY full_name ASC");
        $users_query->execute();
        $users_list = $users_query->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Edit pet customer list fetch failed: ' . $e->getMessage());
        $error_msg = 'Unable to load the customer list. Please try again later.';
    }
}

// --- HANDLE FORM SUBMISSION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $session_token = isset($_SESSION['editpet_csrf_token']) && is_string($_SESSION['editpet_csrf_token'])
        ? $_SESSION['editpet_csrf_token']
        : '';
    $posted_token = isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])
        ? $_POST['csrf_token']
        : '';

    if ($session_token === '' || $posted_token === '' || !hash_equals($session_token, $posted_token)) {
        http_response_code(403);
        $error_msg = 'Your form session has expired. Refresh this page and try again.';
    } else {
        $p_name = editpet_post_string('name');
        $p_type = editpet_post_string('pet_type');
        $p_breed = editpet_post_string('breed');
        $p_gender = editpet_post_string('gender');
        $p_age = editpet_post_string('age');
        $p_weight = editpet_post_string('weight');

        $validation_error = '';
        if ($p_name === '' || $p_type === '' || $p_gender === '') {
            $validation_error = 'Please complete the required pet fields.';
        } elseif (strlen($p_name) > 150 || strlen($p_type) > 60 || strlen($p_breed) > 120) {
            $validation_error = 'Pet name, type, or breed is too long.';
        } elseif (!in_array($p_gender, ['Male', 'Female'], true)) {
            $validation_error = 'Please select a valid pet gender.';
        } elseif (strlen($p_age) > 30 || strlen($p_weight) > 60) {
            $validation_error = 'Age or weight value is too long.';
        }

        $new_owner_id = (int)($row['owner_id'] ?? 0);
        $new_owner_name = trim((string)($row['owner_name'] ?? ''));

        if ($validation_error === '' && $can_transfer_owner) {
            $owner_candidate = $_POST['owner_id'] ?? null;
            $submitted_owner_id = is_scalar($owner_candidate)
                ? filter_var($owner_candidate, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                : false;
            $selected_customer = null;
            if ($submitted_owner_id !== false && $submitted_owner_id !== null) {
                foreach ($users_list as $user) {
                    if ((int)$user['id'] === (int)$submitted_owner_id) {
                        $selected_customer = $user;
                        break;
                    }
                }
            }

            if (!$selected_customer) {
                $validation_error = 'Please select a valid customer as the pet owner.';
            } else {
                $new_owner_id = (int)$selected_customer['id'];
                $new_owner_name = trim((string)($selected_customer['full_name'] ?? ''));
                if ($new_owner_name === '') {
                    $validation_error = 'The selected customer has no valid name.';
                }
            }
        }

        if ($validation_error !== '') {
            $error_msg = $validation_error;
        } else {
            try {
                if ($can_transfer_owner) {
                    $update_query = "
                        UPDATE pets
                        SET name = :name,
                            pet_type = :pet_type,
                            breed = :breed,
                            gender = :gender,
                            age = :age,
                            weight = :weight,
                            owner_id = :owner_id,
                            owner_name = :owner_name
                        WHERE id = :pet_id
                    ";
                    $update_params = [
                        ':name' => $p_name,
                        ':pet_type' => $p_type,
                        ':breed' => $p_breed,
                        ':gender' => $p_gender,
                        ':age' => $p_age,
                        ':weight' => $p_weight,
                        ':owner_id' => $new_owner_id,
                        ':owner_name' => $new_owner_name,
                        ':pet_id' => $pet_id,
                    ];
                } else {
                    // Groomers may edit pet details but cannot transfer ownership.
                    $update_query = "
                        UPDATE pets
                        SET name = :name,
                            pet_type = :pet_type,
                            breed = :breed,
                            gender = :gender,
                            age = :age,
                            weight = :weight
                        WHERE id = :pet_id
                    ";
                    $update_params = [
                        ':name' => $p_name,
                        ':pet_type' => $p_type,
                        ':breed' => $p_breed,
                        ':gender' => $p_gender,
                        ':age' => $p_age,
                        ':weight' => $p_weight,
                        ':pet_id' => $pet_id,
                    ];
                }

                $update_stmt = $pdo->prepare($update_query);
                $update_stmt->execute($update_params);

                $row['name'] = $p_name;
                $row['pet_type'] = $p_type;
                $row['breed'] = $p_breed;
                $row['gender'] = $p_gender;
                $row['age'] = $p_age;
                $row['weight'] = $p_weight;
                if ($can_transfer_owner) {
                    $row['owner_id'] = $new_owner_id;
                    $row['owner_name'] = $new_owner_name;
                }
                $success_msg = 'Pet record successfully updated.';
            } catch (PDOException $e) {
                error_log('Edit pet update failed: ' . $e->getMessage());
                $error_msg = 'Unable to update the pet record. Please check the details and try again.';
            }
        }
    }
}

$current_owner_display = trim((string)($row['owner_name'] ?? ''));
if ($current_owner_display === '') {
    foreach ($users_list as $user) {
        if ((int)$user['id'] === (int)($row['owner_id'] ?? 0)) {
            $current_owner_display = trim((string)($user['full_name'] ?? ''));
            break;
        }
    }
}
if ($current_owner_display === '' && (int)($row['owner_id'] ?? 0) > 0) {
    try {
        $owner_name_stmt = $pdo->prepare('SELECT full_name FROM users WHERE id = :owner_id LIMIT 1');
        $owner_name_stmt->execute([':owner_id' => (int)$row['owner_id']]);
        $current_owner_display = trim((string)($owner_name_stmt->fetchColumn() ?: ''));
    } catch (PDOException $e) {
        error_log('Edit pet current owner lookup failed: ' . $e->getMessage());
    }
}
if ($current_owner_display === '') {
    $current_owner_display = 'Customer #' . (int)($row['owner_id'] ?? 0);
}

// Admin notifications are intentionally visible only to Admin accounts.
$admin_notifications = [];
$unread_count = 0;
if ($is_admin) {
    try {
        $admin_notif_stmt = $pdo->prepare("SELECT id, message, created_at FROM admin_notifications WHERE is_read = 0 ORDER BY created_at DESC LIMIT 20");
        $admin_notif_stmt->execute();
        $admin_notifications = $admin_notif_stmt->fetchAll(PDO::FETCH_ASSOC);
        $unread_count = count($admin_notifications);
    } catch (PDOException $e) {
        error_log('Edit pet admin notification fetch failed: ' . $e->getMessage());
        $admin_notifications = [];
        $unread_count = 0;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Pet Record | Boogie's Pet Care</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --brand-yellow: #ffcc00;
            --navy-dark: #001f3f;
            --bg-light: #f4f7f6;
            --white: #ffffff;
            --text-main: #2d3436;
            --text-muted: #64748b;
            --border: #e2e8f0;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Poppins', sans-serif; }
        body { background: var(--bg-light); color: var(--text-main); min-height: 100vh; overflow-x: hidden; }

        /* Shared admin shell */
        aside {
            width: 260px;
            background: var(--navy-dark);
            color: #fff;
            display: flex;
            flex-direction: column;
            position: fixed;
            height: 100vh;
            z-index: 2000;
        }
        .sidebar-header { padding: 30px 20px; text-align: center; border-bottom: 1px solid rgba(255,255,255,.05); }
        .sidebar-logo { width: 80px; height: auto; object-fit: contain; margin-bottom: 10px; }
        .sidebar-header h2 { font-size: 16px; color: var(--brand-yellow); text-transform: uppercase; letter-spacing: 1px; font-weight: 800; }
        .nav-links { flex-grow: 1; padding: 20px 15px; display: flex; flex-direction: column; gap: 5px; overflow-y: auto; }
        .nav-item { display: flex; align-items: center; padding: 14px 20px; color: #94a3b8; text-decoration: none; transition: all .25s ease; font-size: 14px; font-weight: 500; border-radius: 10px; position: relative; }
        .nav-item i { width: 32px; font-size: 18px; flex: 0 0 32px; }
        .nav-item:hover { color: #fff; background: rgba(255,255,255,.05); transform: translateX(4px); }
        .nav-item.active { color: var(--brand-yellow); background: rgba(255,204,0,.08); font-weight: 700; }
        .nav-item.active::before { content: ''; position: absolute; left: -15px; top: 15%; height: 70%; width: 5px; background: var(--brand-yellow); border-radius: 0 5px 5px 0; }

        main { margin-left: 260px; min-height: 100vh; }
        .top-bar { background: #fff; height: 70px; padding: 0 40px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 10px rgba(0,0,0,.08); position: sticky; top: 0; z-index: 1000; }
        .topbar-left { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .breadcrumb { font-weight: 700; color: var(--navy-dark); font-size: 15px; display: flex; align-items: center; gap: 8px; min-width: 0; }
        .mobile-menu-toggle { display: none; border: 0; background: var(--navy-dark); color: var(--brand-yellow); width: 40px; height: 40px; border-radius: 10px; cursor: pointer; font-size: 18px; align-items: center; justify-content: center; flex: 0 0 auto; }
        .top-right-actions { display: flex; align-items: center; gap: 20px; flex: 0 0 auto; }

        .notif-wrapper, .profile-wrapper { position: relative; cursor: pointer; }
        .notif-wrapper { display: flex; align-items: center; }
        .notif-badge { position: absolute; top: -8px; right: -9px; min-width: 18px; height: 18px; padding: 0 5px; background: #e11d48; color: white; border-radius: 999px; font-size: 10px; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; }
        .notif-dropdown, .profile-dropdown { display: none; position: absolute; right: 0; top: 46px; background: white; border: 1px solid #e2e8f0; box-shadow: 0 10px 20px rgba(15,23,42,.12); border-radius: 10px; z-index: 3000; overflow: hidden; }
        .notif-dropdown { width: 320px; text-align: left; }
        .profile-dropdown { width: 200px; }
        .notif-dropdown.show, .profile-dropdown.show { display: block; }
        .notif-header { padding: 12px 15px; border-bottom: 1px solid #e2e8f0; font-weight: 800; font-size: 14px; display: flex; justify-content: space-between; align-items: center; color: #0f172a; }
        .notif-body { max-height: 300px; overflow-y: auto; }
        .notif-item { padding: 12px 15px; border-bottom: 1px solid #f1f5f9; font-size: 13px; color: #334155; line-height: 1.4; }
        .notif-empty { padding: 20px; text-align: center; color: #94a3b8; font-size: 13px; }
        .mark-read-btn { font-size: 11px; color: #3b82f6; text-decoration: none; font-weight: 700; }
        .profile-wrapper { display: flex; align-items: center; gap: 8px; padding-left: 10px; border-left: 1px solid #e2e8f0; }
        .profile-wrapper > span:last-of-type { font-size: 14px; font-weight: 600; color: #4a5568; display: flex; align-items: center; gap: 6px; }
        .admin-tag { padding: 5px 10px; border-radius: 999px; background: var(--navy-dark); color: var(--brand-yellow); font-size: 9px; font-weight: 800; letter-spacing: .7px; }
        .top-avatar, .top-avatar-fallback { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; display: flex; align-items: center; justify-content: center; background: #e2e8f0; color: var(--navy-dark); font-weight: 800; font-size: 13px; flex: 0 0 auto; }
        .profile-item { padding: 12px 15px; display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--text-main); text-decoration: none; }
        .profile-item:hover { background: #f1f5f9; }
        .profile-item.logout-text { color: #e11d48; border-top: 1px solid #f1f5f9; }
        .sidebar-backdrop { display: none; }

        /* Edit form */
        .page-wrap { width: 100%; max-width: 1000px; margin: 0 auto; padding: 30px 24px 40px; }
        .header-actions { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .btn-back { background: var(--white); color: var(--navy-dark); padding: 10px 18px; border-radius: 8px; text-decoration: none; font-weight: 700; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 2px 4px rgba(0,0,0,.05); font-size: 14px; }
        .card { background: var(--white); padding: 32px; border-radius: 16px; box-shadow: 0 4px 10px rgba(0,0,0,.03); border: 1px solid var(--border); border-top: 5px solid var(--navy-dark); }
        .card-title { font-size: 22px; color: var(--navy-dark); font-weight: 800; margin-bottom: 25px; display: flex; align-items: center; gap: 12px; border-bottom: 2px solid #f8fafc; padding-bottom: 15px; }
        .card-title i { color: var(--brand-yellow); }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        .form-group { margin-bottom: 8px; }
        .form-group.full-width { grid-column: span 2; }
        label { display: block; font-size: 12px; font-weight: 700; color: var(--navy-dark); margin-bottom: 7px; text-transform: uppercase; letter-spacing: .5px; }
        input[type="text"], input[type="number"], select, textarea { width: 100%; padding: 12px 14px; border: 1px solid var(--border); border-radius: 8px; font-size: 14px; color: var(--text-main); outline: none; font-family: inherit; background: #f8fafc; transition: .2s; }
        input:focus:not([readonly]), select:focus, textarea:focus { background: #fff; border-color: var(--navy-dark); box-shadow: 0 0 0 3px rgba(0,31,63,.1); }
        .btn-save { background: var(--navy-dark); color: var(--brand-yellow); padding: 13px 20px; border: none; border-radius: 8px; font-size: 14px; font-weight: 700; cursor: pointer; width: 100%; margin-top: 18px; display: flex; justify-content: center; align-items: center; gap: 8px; box-shadow: 0 4px 6px rgba(0,0,0,.1); }
        .alert { padding: 13px 15px; border-radius: 8px; margin-bottom: 18px; font-weight: 600; font-size: 13px; display: flex; align-items: flex-start; gap: 10px; line-height: 1.45; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        @media (max-width: 1200px) {
            .page-wrap { max-width: 900px; }
        }
        @media (max-width: 900px) {
            body { overflow-x: hidden; }
            aside { transform: translateX(-100%); transition: transform .25s ease; box-shadow: 12px 0 30px rgba(0,0,0,.18); }
            aside.mobile-open { transform: translateX(0); }
            .sidebar-backdrop { position: fixed; inset: 0; background: rgba(0,31,63,.48); z-index: 1900; }
            .sidebar-backdrop.show { display: block; }
            main { margin-left: 0; width: 100%; min-width: 0; }
            .top-bar { height: 64px; padding: 0 14px; gap: 10px; }
            .mobile-menu-toggle { display: inline-flex; }
            .breadcrumb { font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .top-right-actions { gap: 10px; margin-left: auto; }
            .profile-wrapper > span:last-of-type { max-width: 110px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 12px; }
            .notif-dropdown { width: min(320px, calc(100vw - 24px)); right: -6px; }
            .page-wrap { max-width: 100%; padding: 22px 16px 30px; }
            .card { padding: 24px 18px; }
            .card-title { font-size: 19px; }
        }
        @media (max-width: 560px) {
            .top-bar { height: 58px; padding: 0 8px; }
            .topbar-left { gap: 7px; }
            .mobile-menu-toggle { width: 36px; height: 36px; border-radius: 9px; font-size: 16px; }
            .breadcrumb { font-size: 11px; }
            .top-right-actions { gap: 5px; }
            .profile-wrapper { gap: 5px; padding-left: 6px; }
            .profile-wrapper > span:last-of-type { display: none; }
            .admin-tag { padding: 4px 7px; font-size: 7px; }
            .top-avatar, .top-avatar-fallback { width: 29px; height: 29px; font-size: 11px; }
            .page-wrap { padding: 16px 12px 24px; }
            .header-actions { margin-bottom: 14px; }
            .btn-back { width: 100%; justify-content: center; padding: 11px 14px; font-size: 13px; }
            .card { padding: 20px 14px; border-radius: 14px; }
            .card-title { font-size: 17px; line-height: 1.35; gap: 9px; margin-bottom: 18px; padding-bottom: 12px; }
            .form-grid { grid-template-columns: 1fr; gap: 0; }
            .form-group, .form-group.full-width { grid-column: span 1; margin-bottom: 14px; }
            label { font-size: 10px; margin-bottom: 6px; }
            input[type="text"], input[type="number"], select, textarea { min-height: 44px; padding: 10px 11px; font-size: 13px; }
            .btn-save { min-height: 46px; padding: 12px 16px; font-size: 13px; margin-top: 6px; }
            .alert { padding: 11px 12px; font-size: 12px; }
            .owner-note { font-size: 10px !important; line-height: 1.45; align-items: flex-start !important; }
        }
        @media (max-width: 400px) {
            .page-wrap { padding-left: 9px; padding-right: 9px; }
            .card { padding: 18px 12px; }
            .card-title { font-size: 16px; }
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
            <a href="admindashboard.php" class="nav-item"><i class="fas fa-chart-line"></i> Dashboard</a>
            <a href="managebooking.php" class="nav-item"><i class="fas fa-calendar-alt"></i> Bookings</a>
            <a href="manageusers.php" class="nav-item"><i class="fas fa-users"></i> Users</a>
            <a href="managepet.php" class="nav-item active"><i class="fas fa-dog"></i> Pets</a>
            <?php if ($current_role === 'admin'): ?>
                <a href="managestaff.php" class="nav-item"><i class="fas fa-id-badge"></i> Personnel</a>
                <a href="managepromo.php" class="nav-item"><i class="fas fa-tags"></i> Promos</a>
                <a href="manage_services.php" class="nav-item"><i class="fas fa-list-ul"></i> Pricelist</a>
                <a href="sales_report.php" class="nav-item"><i class="fas fa-file-invoice-dollar"></i> Sales Report</a>
                <a href="admin_account_logs.php" class="nav-item"><i class="fa-solid fa-clock-rotate-left"></i> Account Logs</a>
            <?php endif; ?>
        </nav>
    </aside>
    <div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeMobileSidebar()"></div>

    <main>
        <header class="top-bar">
            <div class="topbar-left">
                <button class="mobile-menu-toggle" type="button" aria-label="Open admin menu" aria-expanded="false" onclick="toggleMobileSidebar(event)"><i class="fas fa-bars"></i></button>
                <div class="breadcrumb"><i class="fas fa-dog" style="opacity:.5;font-size:14px;"></i> Management / Edit Pet</div>
            </div>

            <div class="top-right-actions">
                <?php if ($is_admin): ?>
                <div class="notif-wrapper" onclick="toggleNotif(event)">
                    <i class="fa-solid fa-bell" style="font-size:22px;color:#64748b;"></i>
                    <span id="admin-notif-badge" class="notif-badge" style="display:<?php echo $unread_count > 0 ? 'inline-flex' : 'none'; ?>;"> <?php echo $unread_count; ?> </span>
                    <div class="notif-dropdown" id="notifBox" onclick="event.stopPropagation()">
                        <div class="notif-header">
                            Alerts
                            <a href="mark_notifications_read.php" id="mark-read-link" class="mark-read-btn" style="display:<?php echo $unread_count > 0 ? 'inline-block' : 'none'; ?>;">Mark all read</a>
                        </div>
                        <div class="notif-body" id="admin-notif-list">
                            <?php if ($unread_count > 0): ?>
                                <?php foreach ($admin_notifications as $notif): ?>
                                    <div class="notif-item">
                                        <i class="fa-solid fa-circle-exclamation" style="color:#e11d48;margin-right:5px;"></i>
                                        <?php echo htmlspecialchars((string)($notif['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                        <br><small style="color:#94a3b8;font-size:11px;"><?php echo !empty($notif['created_at']) ? date('M d, g:i A', strtotime((string)$notif['created_at'])) : ''; ?></small>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="notif-empty">No new notifications.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="profile-wrapper" onclick="toggleProfile(event)">
                    <span class="admin-tag"><?php echo strtoupper($current_role ?: 'STAFF'); ?></span>
                    <?php if (!empty($profile_img_path) && file_exists(__DIR__ . '/' . ltrim($profile_img_path, '/'))): ?>
                        <img src="<?php echo htmlspecialchars($profile_img_path, ENT_QUOTES, 'UTF-8'); ?>" class="top-avatar" alt="Profile Picture">
                    <?php else: ?>
                        <div class="top-avatar-fallback"><?php echo strtoupper(substr($first_name, 0, 1)); ?></div>
                    <?php endif; ?>
                    <span>
                        <?php echo htmlspecialchars($admin_full_name, ENT_QUOTES, 'UTF-8'); ?>
                        <i class="fas fa-chevron-down" style="font-size:10px;color:#94a3b8;"></i>
                    </span>
                    <div class="profile-dropdown" id="profileBox" onclick="event.stopPropagation()">
                        <a href="admin_profile.php" class="profile-item"><i class="fas fa-user-circle"></i> My Profile</a>
                        <a href="../logout.php" class="profile-item logout-text"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </header>

        <div class="page-wrap">
            <div class="header-actions">
                <a href="managepet.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back to Pets</a>
            </div>

            <?php if ($success_msg): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i><span><?php echo htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8'); ?></span></div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><span><?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?></span></div>
            <?php endif; ?>

            <div class="card">
                <div class="card-title"><i class="fas fa-edit"></i> Edit Pet Profile</div>

                <form action="" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['editpet_csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Pet Name</label>
                            <input type="text" name="name" value="<?php echo htmlspecialchars($row['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                        <div class="form-group">
                            <label>Pet Type</label>
                            <input type="text" name="pet_type" value="<?php echo htmlspecialchars($row['pet_type'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. Dog, Cat" required>
                        </div>

                        <div class="form-group">
                            <label>Breed</label>
                            <input type="text" name="breed" value="<?php echo htmlspecialchars($row['breed'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>

                        <div class="form-group">
                            <label>Gender</label>
                            <select name="gender" required>
                                <option value="Male" <?php echo (($row['gender'] ?? '') === 'Male') ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo (($row['gender'] ?? '') === 'Female') ? 'selected' : ''; ?>>Female</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Age (Years)</label>
                            <input type="text" inputmode="decimal" name="age" value="<?php echo htmlspecialchars($row['age'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>

                        <div class="form-group">
                            <label>Weight</label>
                            <input type="text" inputmode="decimal" name="weight" value="<?php echo htmlspecialchars($row['weight'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. 5kg">
                        </div>

                        <?php if ($can_transfer_owner): ?>
                        <div class="form-group full-width">
                            <label>Owner Name (Transfer Ownership)</label>
                            <select name="owner_id" required>
                                <option value="">-- Select New Owner --</option>
                                <?php foreach ($users_list as $user): ?>
                                    <option value="<?php echo (int)$user['id']; ?>" <?php echo (isset($row['owner_id']) && (int)$row['owner_id'] === (int)$user['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($user['full_name'] ?? '', ENT_QUOTES, 'UTF-8') . ' (' . htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8') . ')'; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="owner-note" style="color:#0369a1;font-size:11px;margin-top:6px;display:flex;align-items:center;gap:5px;font-weight:500;">
                                <i class="fas fa-exchange-alt" style="color:#0284c7;"></i>
                                You can reassign this pet to a different customer. Medical history transfers automatically.
                            </small>
                        </div>
                        <?php else: ?>
                            <div class="form-group full-width">
                                <label>Pet Owner</label>
                                <input type="text" value="<?php echo htmlspecialchars($current_owner_display, ENT_QUOTES, 'UTF-8'); ?>" readonly>
                                <small class="owner-note" style="color:#64748b;font-size:11px;margin-top:6px;display:flex;align-items:center;gap:5px;font-weight:500;">
                                    <i class="fas fa-lock" style="color:#64748b;"></i>
                                    Ownership transfers are restricted to Admin, Manager, and Receptionist roles.
                                </small>
                            </div>
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Changes</button>
                </form>
            </div>
        </div>
    </main>

    <script>
        function toggleMobileSidebar(event) {
            if (event) event.stopPropagation();
            const sidebar = document.querySelector('aside');
            const backdrop = document.getElementById('sidebarBackdrop');
            const button = document.querySelector('.mobile-menu-toggle');
            if (!sidebar) return;
            const open = sidebar.classList.toggle('mobile-open');
            if (backdrop) backdrop.classList.toggle('show', open);
            if (button) button.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        function closeMobileSidebar() {
            const sidebar = document.querySelector('aside');
            const backdrop = document.getElementById('sidebarBackdrop');
            const button = document.querySelector('.mobile-menu-toggle');
            if (sidebar) sidebar.classList.remove('mobile-open');
            if (backdrop) backdrop.classList.remove('show');
            if (button) button.setAttribute('aria-expanded', 'false');
        }

        function toggleNotif(event) {
            event.stopPropagation();
            const notifBox = document.getElementById('notifBox');
            const profileBox = document.getElementById('profileBox');
            if (notifBox) notifBox.classList.toggle('show');
            if (profileBox) profileBox.classList.remove('show');
        }

        function toggleProfile(event) {
            event.stopPropagation();
            const profileBox = document.getElementById('profileBox');
            const notifBox = document.getElementById('notifBox');
            if (profileBox) profileBox.classList.toggle('show');
            if (notifBox) notifBox.classList.remove('show');
        }

        document.addEventListener('click', function(event) {
            if (!event.target.closest('.notif-wrapper')) {
                const box = document.getElementById('notifBox');
                if (box) box.classList.remove('show');
            }
            if (!event.target.closest('.profile-wrapper')) {
                const box = document.getElementById('profileBox');
                if (box) box.classList.remove('show');
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeMobileSidebar();
                const notifBox = document.getElementById('notifBox');
                const profileBox = document.getElementById('profileBox');
                if (notifBox) notifBox.classList.remove('show');
                if (profileBox) profileBox.classList.remove('show');
            }
        });

        document.querySelectorAll('.nav-item').forEach(function(link) {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 900) closeMobileSidebar();
            });
        });
    </script>
</body>
</html>
