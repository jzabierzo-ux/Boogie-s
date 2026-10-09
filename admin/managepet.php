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
    // Preserve compatibility with the existing legacy staff accounts.
    'staff' => 'groomer',
    'supervisor' => 'receptionist',
];
$current_role = $role_aliases[$raw_role] ?? $raw_role;
if ($current_role !== '') {
    $_SESSION['role'] = $current_role;
}
$is_admin = ($current_role === 'admin');

if (($_SESSION['logged_in'] ?? false) !== true) {
    // Keep the Admin login separate from the Staff login.
    $login_target = ($current_role === 'admin') ? '../admin_login.php' : '../staff/stafflogin.php';
    header('Location: ' . $login_target);
    exit();
}

if (empty($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Access denied: invalid session.');
}

// Veterinary roles use their dedicated portal. Pet Hotel Staff are limited
// to allowed booking functions and must not browse the general pet registry.
if (in_array($current_role, ['veterinarian', 'vet_assistant', 'vet_nurse'], true)) {
    header('Location: ../staff/staffdashboard.php');
    exit();
}
if ($current_role === 'pet_hotel_staff') {
    header('Location: managebooking.php');
    exit();
}

// Admin, Manager, Receptionist, and Groomer may access Pet Management.
$allowed_pet_page_roles = ['admin', 'manager', 'receptionist', 'groomer'];
if (!in_array($current_role, $allowed_pet_page_roles, true)) {
    http_response_code(403);
    exit('Access denied. This role cannot access pet management.');
}

// CSRF token for the Add Pet form.
if (empty($_SESSION['managepet_csrf_token']) || !is_string($_SESSION['managepet_csrf_token'])) {
    $_SESSION['managepet_csrf_token'] = bin2hex(random_bytes(32));
}



// 2. FETCH ADMIN PROFILE

$admin_full_name = "User";

$profile_img_path = "";

$first_name = "User";



try {

    if (isset($_SESSION['user_id'])) {

        $uid = (int)$_SESSION['user_id'];



        $get_admin = $pdo->prepare("

            SELECT full_name, profile_image

            FROM users

            WHERE id = :id

            LIMIT 1

        ");

        $get_admin->execute([':id' => $uid]);

        $admin_data = $get_admin->fetch(PDO::FETCH_ASSOC);



        if ($admin_data) {

            $admin_full_name = $admin_data['full_name'] ?? 'User';

            $profile_img_path = $admin_data['profile_image'] ?? '';

            $_SESSION['user_name'] = $admin_full_name;



            $first_name = explode(' ', $admin_full_name)[0];

            $first_name = trim($first_name, ',');

        } else {

            $admin_full_name = $_SESSION['user_name'] ?? 'User';

            $first_name = trim(explode(' ', $admin_full_name)[0], ',');

        }

    } else {

        $admin_full_name = $_SESSION['user_name'] ?? 'User';

        $first_name = trim(explode(' ', $admin_full_name)[0], ',');

    }

} catch (PDOException $e) {

    $admin_full_name = $_SESSION['user_name'] ?? 'User';

    $first_name = trim(explode(' ', $admin_full_name)[0], ',');

}



$success_msg = (string)($_SESSION['managepet_flash_success'] ?? '');
unset($_SESSION['managepet_flash_success']);
$error_msg = '';

// --- ADD NEW PET LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_pet'])) {
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!is_string($submitted_token) || !hash_equals($_SESSION['managepet_csrf_token'], $submitted_token)) {
        http_response_code(403);
        $error_msg = 'Security check failed. Please refresh the page and try again.';
    } else {
        $raw_owner_id = $_POST['owner_id'] ?? '';
        $owner_id = is_string($raw_owner_id) ? filter_var($raw_owner_id, FILTER_VALIDATE_INT) : false;
        $pet_name = is_string($_POST['pet_name'] ?? null) ? trim($_POST['pet_name']) : '';
        $pet_type = is_string($_POST['pet_type'] ?? null) ? trim($_POST['pet_type']) : '';
        $pet_breed = is_string($_POST['pet_breed'] ?? null) ? trim($_POST['pet_breed']) : '';
        $pet_gender = is_string($_POST['pet_gender'] ?? null) ? trim($_POST['pet_gender']) : '';
        $pet_weight = is_string($_POST['pet_weight'] ?? null) ? trim($_POST['pet_weight']) : '';

        $allowed_pet_types = ['Dog', 'Cat'];
        $allowed_genders = ['Male', 'Female'];
        $allowed_weights = [
            'Small (1-5kg)',
            'Medium (6-10kg)',
            'Large (11-15kg)',
            'Extra Large (16-20kg)',
            'XXL Large (21-25kg)',
        ];
        $name_length = function_exists('mb_strlen') ? mb_strlen($pet_name, 'UTF-8') : strlen($pet_name);
        $breed_length = function_exists('mb_strlen') ? mb_strlen($pet_breed, 'UTF-8') : strlen($pet_breed);
        $has_control_chars = (bool)preg_match('/[\x00-\x1F\x7F]/', $pet_name);

        if (!is_int($owner_id) || $owner_id < 1 || $pet_name === '' || $pet_type === '' || $pet_gender === '' || $pet_weight === '') {
            $error_msg = 'Please complete all required pet fields.';
        } elseif ($name_length > 100 || $breed_length > 100 || $has_control_chars) {
            $error_msg = 'Pet name and breed must be 100 characters or fewer. Remove any invalid control characters from the pet name.';
        } elseif (!in_array($pet_type, $allowed_pet_types, true) || !in_array($pet_gender, $allowed_genders, true) || !in_array($pet_weight, $allowed_weights, true)) {
            $error_msg = 'Please select a valid pet type, gender, and size.';
        } else {
            try {
                // Validate ownership against the database; do not trust a posted owner ID.
                $owner_stmt = $pdo->prepare("
                    SELECT id
                    FROM users
                    WHERE id = :owner_id
                      AND LOWER(TRIM(COALESCE(role, ''))) = 'customer'
                    LIMIT 1
                ");
                $owner_stmt->execute([':owner_id' => $owner_id]);
                $valid_owner_id = $owner_stmt->fetchColumn();

                if ($valid_owner_id === false) {
                    $error_msg = 'Please select a valid customer account as the pet owner.';
                } else {
                    $insert_stmt = $pdo->prepare("
                        INSERT INTO pets (owner_id, name, pet_type, breed, gender, weight)
                        VALUES (:owner_id, :name, :pet_type, :breed, :gender, :weight)
                    ");
                    $insert_stmt->execute([
                        ':owner_id' => $owner_id,
                        ':name' => $pet_name,
                        ':pet_type' => $pet_type,
                        ':breed' => $pet_breed,
                        ':gender' => $pet_gender,
                        ':weight' => $pet_weight,
                    ]);

                    $_SESSION['managepet_flash_success'] = sprintf(
                        'New pet "%s" successfully registered to Owner ID #%d!',
                        $pet_name,
                        $owner_id
                    );

                    // Post/Redirect/Get prevents duplicate inserts on browser refresh.
                    $redirect_url = 'managepet.php';
                    $current_gender_filter = $_GET['gender'] ?? '';
                    if (is_string($current_gender_filter) && in_array($current_gender_filter, ['Male', 'Female'], true)) {
                        $redirect_url .= '?gender=' . rawurlencode($current_gender_filter);
                    }
                    header('Location: ' . $redirect_url, true, 303);
                    exit();
                }
            } catch (PDOException $e) {
                error_log('Manage Pet add error: ' . $e->getMessage());
                $error_msg = 'Error adding pet. Please check the pet details and try again.';
            }
        }
    }
}

// FETCH CUSTOMER ACCOUNTS ONLY FOR THE ADD PET OWNER DROPDOWN.
try {
    $users_list_stmt = $pdo->query("
        SELECT id, full_name
        FROM users
        WHERE LOWER(TRIM(COALESCE(role, ''))) = 'customer'
        ORDER BY full_name ASC
    ");
    $users_list = $users_list_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Manage Pet customer list error: ' . $e->getMessage());
    $users_list = [];
    $error_msg = $error_msg ?: 'Unable to load customer list.';
}



// 3. DATABASE INITIALIZATION & SAFETY CHECK

// PostgreSQL/Supabase does not use MySQL's SHOW TABLES syntax.

// We query the pets table directly and gracefully handle database errors.

$total_pets = $male_pets = $female_pets = 0;

$breeds = [];

$pets_list = [];

$showing_count = 0;

$filter_gender = (isset($_GET['gender']) && is_string($_GET['gender'])) ? $_GET['gender'] : '';



try {

    // 4. FETCH PET STATISTICS

    $total_pets_stmt = $pdo->query("SELECT COUNT(*) AS count FROM pets");

    $male_pets_stmt = $pdo->query("SELECT COUNT(*) AS count FROM pets WHERE gender = 'Male'");

    $female_pets_stmt = $pdo->query("SELECT COUNT(*) AS count FROM pets WHERE gender = 'Female'");



    $total_pets = (int)($total_pets_stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0);

    $male_pets = (int)($male_pets_stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0);

    $female_pets = (int)($female_pets_stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0);



    // 5. FETCH BREEDS FOR FILTER

    $breeds_stmt = $pdo->query("

        SELECT DISTINCT breed

        FROM pets

        WHERE breed IS NOT NULL

          AND breed <> ''

        ORDER BY breed ASC

    ");

    $breeds = $breeds_stmt->fetchAll(PDO::FETCH_ASSOC);



    // 6. FETCH ALL PETS WITH GENDER FILTER

    $pets_query = "SELECT * FROM pets";

    $pets_params = [];



    if ($filter_gender === 'Male' || $filter_gender === 'Female') {

        $pets_query .= " WHERE gender = :gender";

        $pets_params[':gender'] = $filter_gender;

    }



    $pets_query .= " ORDER BY id DESC";



    $pets_stmt = $pdo->prepare($pets_query);

    $pets_stmt->execute($pets_params);

    $pets_list = $pets_stmt->fetchAll(PDO::FETCH_ASSOC);

    $showing_count = count($pets_list);

} catch (PDOException $e) {

    $total_pets = 0;

    $male_pets = 0;

    $female_pets = 0;

    $breeds = [];

    $pets_list = [];

    $showing_count = 0;



    if ($error_msg === '') {

        $error_msg = "Unable to load pet data from the database.";

    }

}



// --- FETCH ADMIN NOTIFICATIONS: ADMIN ONLY ---
$admin_notifications = [];
$unread_count = 0;

if ($is_admin) {
    try {
        $admin_notif_stmt = $pdo->prepare("
            SELECT *
            FROM admin_notifications
            WHERE is_read = 0
            ORDER BY created_at DESC
        ");
        $admin_notif_stmt->execute();
        $admin_notifications = $admin_notif_stmt->fetchAll(PDO::FETCH_ASSOC);
        $unread_count = count($admin_notifications);
    } catch (PDOException $e) {
        error_log('Manage Pet admin notification error: ' . $e->getMessage());
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

    <title>Pet Profiles | Boogie's Pet Care</title>

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>

        :root {

            --navy-dark: #001f3f; 

            --brand-yellow: #ffcc00; 

            --brand-blue: #001f3f;

            --bg-light: #f4f7f6; 

            --white: #ffffff; 

            --text-main: #2d3436;

            --text-muted: #64748b; 

            --sidebar-width: 260px;

            --border-color: #d1d5db;

        }



        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Poppins', sans-serif;}

        body { background-color: var(--bg-light); display: flex; min-height: 100vh; }



        /* --- SIDEBAR --- */

        aside { width: var(--sidebar-width); background-color: var(--navy-dark); color: var(--white); display: flex; flex-direction: column; position: fixed; height: 100vh; z-index: 100; }

        .sidebar-header { padding: 30px 20px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.05); }

        .sidebar-logo { width: 80px; height: auto; object-fit: contain; margin-bottom: 10px; }

        .sidebar-header h2 { font-size: 16px; color: var(--brand-yellow); text-transform: uppercase; letter-spacing: 1px; font-weight: 800;}



        /* --- MODERNIZED SIDEBAR NAVIGATION --- */

        .nav-links { flex-grow: 1; padding: 20px 15px; display: flex; flex-direction: column; gap: 5px; }

        .nav-item {

            display: flex; align-items: center; padding: 14px 20px; color: #94a3b8; 

            text-decoration: none; transition: all 0.3s ease; font-size: 14px;

            font-weight: 500; border-radius: 10px; position: relative;

        }

        .nav-item i { width: 32px; font-size: 18px; transition: transform 0.3s;}

        .nav-item:hover { color: var(--white); background-color: rgba(255, 255, 255, 0.05); transform: translateX(4px); }

        .nav-item.active { color: var(--brand-yellow); background-color: rgba(255, 204, 0, 0.08); font-weight: 700; }

        .nav-item.active::before {

            content: ''; position: absolute; left: -15px; top: 15%; height: 70%; width: 5px; 

            background-color: var(--brand-yellow); border-radius: 0 5px 5px 0; box-shadow: 2px 0 8px rgba(255, 204, 0, 0.5); 

        }



        /* --- MAIN CONTENT & HEADER --- */

        main { margin-left: var(--sidebar-width); flex-grow: 1; display: flex; flex-direction: column; min-height: 100vh; }

        .top-bar { background-color: var(--white); height: 70px; padding: 0 40px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 10px rgba(0,0,0,0.08); position: sticky; top: 0; z-index: 1000; }

        .breadcrumb { font-weight: 700; color: var(--navy-dark); font-size: 15px; display: flex; align-items: center; gap: 8px; }



        /* Fixed Admin Tag */

        .admin-tag { background: var(--navy-dark); color: var(--brand-yellow); padding: 6px 16px; border-radius: 50px; font-size: 10px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; border: 1px solid var(--brand-yellow); }



        /* --- NOTIFICATION STYLES --- */

        .top-right-actions { display: flex; align-items: center; gap: 20px; }

        .notif-wrapper { position: relative; display: inline-flex; align-items: center; cursor: pointer; }

        .notif-badge { position: absolute; top: -5px; right: -8px; background: #e11d48; color: white; border-radius: 50%; padding: 2px 6px; font-size: 10px; font-weight: bold; }

        .notif-dropdown { display: none; position: absolute; right: 0; top: 35px; width: 320px; background: white; border: 1px solid #e2e8f0; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); border-radius: 8px; z-index: 1000; text-align: left; }

        .notif-dropdown.show { display: block; }

        .notif-header { padding: 12px 15px; border-bottom: 1px solid #e2e8f0; font-weight: 700; font-size: 14px; display: flex; justify-content: space-between; align-items: center; color: #001f3f; }

        .notif-body { max-height: 300px; overflow-y: auto; }

        .notif-item { padding: 12px 15px; border-bottom: 1px solid #f1f5f9; font-size: 13px; color: #334155; line-height: 1.4; }

        .notif-item:last-child { border-bottom: none; }

        .notif-empty { padding: 20px; text-align: center; color: #94a3b8; font-size: 13px; }

        .mark-read-btn { font-size: 11px; color: #3b82f6; text-decoration: none; font-weight: 600; }

        .mark-read-btn:hover { text-decoration: underline; }



        /* --- PROFILE DROPDOWN & AVATAR --- */

        .profile-wrapper { position: relative; display: inline-flex; align-items: center; gap: 12px; border-left: 1px solid #e2e8f0; padding-left: 20px; cursor: pointer; user-select: none; }

        .top-avatar { width: 35px; height: 35px; border-radius: 50%; object-fit: cover; border: 2px solid var(--navy-dark); }

        .top-avatar-fallback { width: 35px; height: 35px; border-radius: 50%; background: linear-gradient(135deg, var(--navy-dark), #003366); color: var(--brand-yellow); display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 14px; border: 2px solid var(--brand-yellow); box-shadow: 0 2px 4px rgba(0,0,0,0.1); }



        .profile-dropdown { display: none; position: absolute; right: 0; top: 45px; width: 200px; background: white; border: 1px solid #e2e8f0; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); border-radius: 8px; z-index: 1000; overflow: hidden; text-align: left; }

        .profile-dropdown.show { display: block; }

        .profile-item { padding: 12px 15px; display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--text-main); text-decoration: none; transition: background 0.2s; }

        .profile-item:hover { background: #f1f5f9; color: var(--navy-dark); }

        .profile-item i { width: 16px; text-align: center; }

        .profile-item.logout-text { color: #e11d48; border-top: 1px solid #f1f5f9; }

        .profile-item.logout-text:hover { background: #fff1f2; color: #be123c; }



        /* --- PAGE CONTENT --- */

        .container { padding: 40px; flex-grow: 1; }

        .page-header { margin-bottom: 25px; }

        .page-header h1 { font-size: 26px; color: var(--navy-dark); font-weight: 800;}

        .page-header p { color: #64748b; margin-top: 5px; font-weight: 500;}



        /* --- SUMMARY CARDS --- */

        .status-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px; }

        .status-card { padding: 25px; border-radius: 16px; color: white; display: flex; flex-direction: column; gap: 10px; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; position: relative; border-left: 4px solid var(--navy-dark);}

        .status-card:hover { transform: translateY(-4px); box-shadow: 0 10px 20px rgba(0,0,0,0.15); border-left-color: var(--brand-yellow);}

        .status-card.total { background: linear-gradient(135deg, var(--navy-dark), #003366); }

        .status-card.male { background: linear-gradient(135deg, #3b82f6, #1d4ed8); }

        .status-card.female { background: linear-gradient(135deg, #f43f5e, #e11d48); }

        .status-card .label { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; opacity: 0.9;}

        .status-card .count { font-size: 32px; font-weight: 800; }

        .status-card i { position: absolute; right: 25px; top: 25px; font-size: 30px; opacity: 0.2;}



        /* --- SEARCH & FILTERS --- */

        .filter-container { background: var(--white); padding: 15px 25px; border-radius: 12px; display: flex; align-items: center; gap: 15px; box-shadow: 0 4px 6px rgba(0,0,0,0.03); margin-bottom: 25px; border: 1px solid transparent; justify-content: space-between; flex-wrap: wrap; }

        .filter-group { display: flex; align-items: center; gap: 15px; flex-grow: 1; min-width: 300px; }

        .filter-container i.fa-search { color: #94a3b8; }

        .search-input { border: none; outline: none; flex-grow: 1; font-size: 14px; color: #1e293b; background: transparent; font-family: 'Poppins', sans-serif;}

        .breed-select { padding: 8px 15px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 13px; color: #4a5568; outline: none; background: #fff; cursor: pointer; font-family: 'Poppins', sans-serif;}

        .result-count { font-size: 12px; color: #94a3b8; font-weight: 600; }



        /* ADD PET BUTTON */

        .btn-add { background: var(--brand-yellow); color: var(--navy-dark); border: none; padding: 10px 20px; border-radius: 8px; font-size: 14px; font-weight: 700; cursor: pointer; transition: 0.3s; display: flex; align-items: center; gap: 8px; white-space: nowrap; }

        .btn-add:hover { transform: translateY(-2px); box-shadow: 0 4px 10px rgba(255, 204, 0, 0.4); }



        /* --- MODAL STYLES --- */

        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); align-items: center; justify-content: center; backdrop-filter: blur(4px); }

        .modal-content { background-color: var(--white); padding: 30px; border-radius: 16px; width: 100%; max-width: 500px; box-shadow: 0 15px 30px rgba(0,0,0,0.2); position: relative; max-height: 90vh; overflow-y: auto; }

        .close-btn { position: absolute; top: 20px; right: 25px; font-size: 24px; cursor: pointer; color: var(--text-muted); transition: 0.2s; }

        .close-btn:hover { color: #e11d48; }

        .modal-content h2 { margin-bottom: 20px; color: var(--navy-dark); font-size: 22px; font-weight: 700; }



        .form-group { margin-bottom: 15px; text-align: left;}

        .form-row { display: flex; gap: 15px; }

        .form-row .form-group { flex: 1; }

        .form-group label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: var(--text-main); }

        .form-group input, .form-group select { width: 100%; padding: 10px 15px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 14px; outline: none; background: #f9fafb; font-family: 'Poppins', sans-serif;}

        .form-group input:focus, .form-group select:focus { border-color: var(--brand-yellow); background: var(--white);}



        .btn-submit-modal { width: 100%; background: var(--navy-dark); color: var(--brand-yellow); border: none; padding: 12px; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; transition: 0.3s; margin-top: 10px;}

        .btn-submit-modal:hover { background: #003366; }



        /* --- TABLE STYLING --- */

        .pet-table { width: 100%; border-collapse: collapse; background: var(--white); border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.03); }

        .pet-table th { background: #f8fafc; padding: 15px 20px; font-size: 12px; text-transform: uppercase; color: #64748b; font-weight: 700; text-align: left; border-bottom: 2px solid #edf2f7;}

        .pet-table td { padding: 15px 20px; font-size: 14px; color: #2d3436; border-bottom: 1px solid #edf2f7; vertical-align: middle; }

        .pet-table tr:hover { background-color: #f8fafc; }



        .gender-badge { font-size: 12px; padding: 4px 10px; border-radius: 20px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }

        .gender-male { color: #3b82f6; background: #eff6ff; }

        .gender-female { color: #f43f5e; background: #fff1f2; }



        .empty-state { background: var(--white); border-radius: 16px; padding: 100px 0; text-align: center; color: #94a3b8; box-shadow: 0 4px 6px rgba(0,0,0,0.03); border: 1px dashed #e2e8f0; }

        .empty-state i { font-size: 60px; margin-bottom: 20px; opacity: 0.2; }



        footer { text-align: center; padding: 40px; color: var(--text-muted); font-size: 12px; background: transparent; border-top: 1px solid rgba(0,0,0,0.05); }



        /* Action link hover effects */

        .action-link { transition: color 0.2s; display: inline-block; color: #94a3b8; font-size: 16px; margin-left: 10px;}

        .action-link:hover { color: var(--brand-yellow) !important; opacity: 1; }

        .btn-view { color: var(--navy-dark); }

        .btn-view:hover { color: var(--brand-yellow); }


        /* --- RESPONSIVE ADMIN LAYOUT --- */
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

        .sidebar-backdrop {
            display: none;
        }

        @media (max-width: 1200px) {
            .status-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .container {
                padding: 30px;
            }
        }

        @media (max-width: 900px) {
            body {
                overflow-x: hidden;
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
                min-width: 0;
            }

            .top-bar {
                height: 60px;
                padding: 0 10px;
                gap: 8px;
            }

            .mobile-menu-toggle {
                display: inline-flex;
            }

            .breadcrumb {
                font-size: 12px;
                gap: 6px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .top-right-actions {
                gap: 8px;
                margin-left: auto;
                flex: 0 0 auto;
            }

            .profile-wrapper {
                gap: 7px;
                padding-left: 8px;
            }

            .profile-wrapper > span:last-of-type {
                max-width: 100px;
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
                width: min(320px, calc(100vw - 20px));
                right: -4px;
            }

            .container {
                padding: 18px 14px 24px;
            }

            .page-header h1 {
                font-size: 22px;
                line-height: 1.25;
            }

            .page-header p {
                font-size: 13px;
                line-height: 1.5;
            }

            .status-grid {
                grid-template-columns: 1fr;
                gap: 10px;
                margin-bottom: 18px;
            }

            .status-card {
                padding: 18px;
                border-radius: 14px;
            }

            .status-card .label {
                font-size: 10px;
            }

            .status-card .count {
                font-size: 26px;
            }

            .status-card i {
                right: 18px;
                top: 18px;
                font-size: 24px;
            }

            .filter-container {
                padding: 14px;
                gap: 12px;
                align-items: stretch;
            }

            .filter-group {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
                min-width: 0;
            }

            .filter-group > .fa-search {
                display: none;
            }

            .search-input {
                width: 100%;
                min-width: 0;
                padding: 10px 12px;
                border: 1px solid #e2e8f0;
                border-radius: 8px;
                background: #fff;
            }

            .breed-select {
                width: 100%;
            }

            .result-count {
                font-size: 11px;
            }

            .filter-actions {
                width: 100%;
            }

            .btn-add {
                width: 100%;
                justify-content: center;
                min-height: 44px;
            }

            .pet-table {
                min-width: 720px;
            }

            .table-scroll,
            .pet-table-wrapper {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            .modal {
                padding: 12px;
            }

            .modal-content {
                max-height: calc(100dvh - 24px);
                padding: 22px 18px;
                border-radius: 14px;
            }

            .form-row {
                flex-direction: column;
                gap: 0;
            }

            footer {
                padding: 24px 12px;
                font-size: 10px;
            }
        }

        @media (max-width: 560px) {
            .top-bar {
                height: 58px;
                padding: 0 8px;
            }

            .topbar-left {
                gap: 7px;
                min-width: 0;
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

            .notif-wrapper > i {
                font-size: 19px !important;
            }

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

            .page-header h1 {
                font-size: 21px;
            }

            .page-header p {
                font-size: 12px;
            }

            .status-card {
                padding: 17px;
            }

            .filter-container {
                border-radius: 12px;
            }

            .modal-content h2 {
                font-size: 19px;
            }
        }

    </style>

</head>

<body>



    <aside>

        <div class="sidebar-header">

            <img src="bg.png" alt="Boogie's Logo" class="sidebar-logo">

            <h2>

                <?php 

                    echo $is_admin ? "Boogie's Admin" : "Boogie's Staff"; 

                ?>

            </h2>

        </div>

        <nav class="nav-links">

            <a href="admindashboard.php" class="nav-item"><i class="fas fa-chart-line"></i> Dashboard</a>

            <a href="managebooking.php" class="nav-item"><i class="fas fa-calendar-alt"></i> Bookings</a>

            <a href="manageusers.php" class="nav-item"><i class="fas fa-users"></i> Users</a>

            <a href="managepet.php" class="nav-item active"><i class="fas fa-dog"></i> Pets</a>



            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>

                <a href="managestaff.php" class="nav-item"><i class="fas fa-id-badge"></i> Personnel</a>

                <a href="managepromo.php" class="nav-item"><i class="fas fa-tags"></i> Promos</a>

                <a href="manage_services.php" class="nav-item"><i class="fas fa-list-ul"></i> Pricelist</a>

                <a href="sales_report.php" class="nav-item">

                    <i class="fas fa-file-invoice-dollar"></i>

                    Sales Report

                </a>



                <!-- ACCOUNT LOGS: ADMIN ONLY -->

                <a href="admin_account_logs.php" class="nav-item">

                    <i class="fa-solid fa-clock-rotate-left"></i>

                    <span>Account Logs</span>

                </a>

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
                    <i class="fas fa-dog" style="opacity: 0.5; font-size: 14px;"></i>
                    Management / Pets
                </div>
            </div>



            <div class="top-right-actions">

<?php if ($is_admin): ?>
                <div class="notif-wrapper" onclick="toggleNotif(event)">

                    <i class="fa-solid fa-bell" style="font-size: 22px; color: #64748b;"></i>



                    <span id="admin-notif-badge" class="notif-badge" style="display: <?php echo ($unread_count > 0) ? 'inline-block' : 'none'; ?>;">

                        <?php echo $unread_count; ?>

                    </span>



                    <div class="notif-dropdown" id="notifBox" onclick="event.stopPropagation()">

                        <div class="notif-header">

                            Alerts

                            <a href="mark_notifications_read.php" id="mark-read-link" class="mark-read-btn" style="display: <?php echo ($unread_count > 0) ? 'inline-block' : 'none'; ?>;">Mark all read</a>

                        </div>



                        <div class="notif-body" id="admin-notif-list">

                            <?php if($unread_count > 0): ?>

                                <?php foreach($admin_notifications as $notif): ?>

                                    <div class="notif-item">

                                        <i class="fa-solid fa-circle-exclamation" style="color: #e11d48; margin-right: 5px;"></i>

                                        <?php echo htmlspecialchars((string)($notif['message'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>

                                        <br><small style="color: #94a3b8; font-size: 11px;"><?php echo date('M d, g:i A', strtotime($notif['created_at'])); ?></small>

                                    </div>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <div class="notif-empty">No new alerts.</div>

                            <?php endif; ?>

                        </div>

                    </div>

                </div>
                <?php endif; ?>



                <div class="profile-wrapper" onclick="toggleProfile(event)">

                    <span class="admin-tag"><?php echo htmlspecialchars(strtoupper($current_role), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>



                    <?php if (!empty($profile_img_path) && file_exists($profile_img_path)): ?>

                        <img src="<?php echo htmlspecialchars($profile_img_path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" class="top-avatar" alt="Profile Picture">

                    <?php else: ?>

                        <div class="top-avatar-fallback"><?php echo htmlspecialchars(strtoupper(substr($first_name, 0, 1)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>

                    <?php endif; ?>



                    <span style="font-size: 14px; font-weight: 600; color: #4a5568; display: flex; align-items: center; gap: 6px;">

                        <?php echo htmlspecialchars($admin_full_name); ?>

                        <i class="fas fa-chevron-down" style="font-size: 10px; color: #94a3b8;"></i>

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

                <h1>Pet Profiles</h1>

                <p>View and manage all registered pet profiles and their medical history.</p>

            </div>



            <?php if(!empty($success_msg)): ?>

                <div style="background: #dcfce7; color: #166534; padding: 12px 20px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #bbf7d0; font-size: 14px; font-weight: 500;">

                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>

                </div>

            <?php endif; ?>

            <?php if(!empty($error_msg)): ?>

                <div style="background: #fee2e2; color: #991b1b; padding: 12px 20px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #fecaca; font-size: 14px; font-weight: 500;">

                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>

                </div>

            <?php endif; ?>



            <div class="status-grid">

                <div class="status-card total" onclick="window.location.href='managepet.php'">

                    <i class="fas fa-paw"></i>

                    <span class="label">Total Pets</span>

                    <div class="count"><?php echo $total_pets; ?></div>

                </div>

                <div class="status-card male" onclick="window.location.href='managepet.php?gender=Male'">

                    <i class="fas fa-mars"></i>

                    <span class="label">Male Pets</span>

                    <div class="count"><?php echo $male_pets; ?></div>

                </div>

                <div class="status-card female" onclick="window.location.href='managepet.php?gender=Female'">

                    <i class="fas fa-venus"></i>

                    <span class="label">Female Pets</span>

                    <div class="count"><?php echo $female_pets; ?></div>

                </div>

            </div>



            <div class="filter-container">

                <div class="filter-group">

                    <i class="fas fa-search"></i>

                    <input type="text" id="petSearch" class="search-input" placeholder="Search by pet name, breed, or owner ID...">

                    <select id="breedFilter" class="breed-select">

                        <option value="">All Breeds</option>

                        <?php if (!empty($breeds)): ?>

                            <?php foreach($breeds as $breed): ?>

                                <option value="<?php echo htmlspecialchars((string)($breed['breed'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">

                                    <?php echo htmlspecialchars((string)($breed['breed'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>

                                </option>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </select>

                    <span class="result-count" id="showingCountText">Showing <?php echo $showing_count; ?> of <?php echo $showing_count; ?> pets</span>

                </div>

                <div class="filter-actions">

                    <button class="btn-add" onclick="openPetModal()"><i class="fas fa-plus"></i> Add New Pet</button>

                </div>

            </div>



            <?php if ($showing_count > 0): ?>
                <div class="pet-table-wrapper">
                    <table class="pet-table" id="petsTable">

                    <thead>

                        <tr>

                            <th>Pet Name</th>

                            <th>Breed</th>

                            <th>Gender</th>

                            <th>Owner ID</th>

                            <th style="text-align: center;">Actions</th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach($pets_list as $pet): ?>

                        <tr class="pet-row" data-breed="<?php echo htmlspecialchars((string)($pet['breed'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">

                            <td>

                                <div style="display: flex; align-items: center; gap: 12px;">

                                    <div style="width: 35px; height: 35px; background: #f1f5f9; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: var(--navy-dark);">

                                        <i class="fas fa-paw"></i>

                                    </div>

                                    <div style="font-weight: 700; color: var(--navy-dark);"><?php echo htmlspecialchars((string)($pet['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>

                                </div>

                            </td>

                            <td style="color: var(--text-muted); font-weight: 500;"><?php echo htmlspecialchars((string)($pet['breed'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>

                            <td>

                                <?php if($pet['gender'] == 'Male'): ?>

                                    <span class="gender-badge gender-male"><i class="fas fa-mars"></i> Male</span>

                                <?php else: ?>

                                    <span class="gender-badge gender-female"><i class="fas fa-venus"></i> Female</span>

                                <?php endif; ?>

                            </td>

                            <td style="color: var(--text-muted); font-weight: 600;">#<?php echo $pet['owner_id']; ?></td>



                            <td style="text-align: center;">

                                <a href="view_records.php?id=<?php echo $pet['id']; ?>" class="action-link btn-view" title="View Records">

                                    <i class="fas fa-file-medical"></i>

                                </a>



                                <a href="editpet.php?id=<?php echo $pet['id']; ?>" class="action-link" title="Edit">

                                    <i class="fas fa-edit"></i>

                                </a>

                            </td>

                        </tr>

                        <?php endforeach; ?>

                    </tbody>

                    </table>
                </div>
            <?php else: ?>

                <div class="empty-state">

                    <i class="fas fa-dog"></i>

                    <p style="font-weight: 500;">No pet profiles found for this view.</p>

                </div>

            <?php endif; ?>

        </div>



        <footer>

            © <?php echo date("Y"); ?> BOOGIE'S PET CARE & SERVICES - DASMARIÑAS BRANCH

        </footer>

    </main>



    <div id="addPetModal" class="modal">

        <div class="modal-content">

            <span class="close-btn" onclick="closePetModal()">&times;</span>

            <h2><i class="fas fa-paw" style="color: var(--brand-yellow);"></i> Register New Pet</h2>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['managepet_csrf_token'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">

                <div class="form-group">

                    <label>Select Owner (Customer) *</label>

                    <select name="owner_id" required>

                        <option value="">-- Search / Choose Customer --</option>

                        <?php foreach ($users_list as $user): ?>

                            <option value="<?php echo (int)$user['id']; ?>">

                                ID: <?php echo (int)$user['id']; ?> - <?php echo htmlspecialchars((string)($user['full_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>



                <div class="form-group">

                    <label>Pet's Name *</label>

                    <input type="text" name="pet_name" placeholder="e.g. Pochi" maxlength="100" required>

                </div>



                <div class="form-row">

                    <div class="form-group">

                        <label>Pet Type *</label>

                        <select name="pet_type" required>

                            <option value="">Select Type</option>

                            <option value="Dog">Dog</option>

                            <option value="Cat">Cat</option>

                        </select>

                    </div>

                    <div class="form-group">

                        <label>Gender *</label>

                        <select name="pet_gender" required>

                            <option value="Male">Male</option>

                            <option value="Female">Female</option>

                        </select>

                    </div>

                </div>



                <div class="form-row">

                    <div class="form-group">

                        <label>Breed</label>

                        <input type="text" name="pet_breed" placeholder="e.g. Shih Tzu" maxlength="100">

                    </div>

                    <div class="form-group">

                        <label>Size / Weight *</label>

                        <select name="pet_weight" required>

                            <option value="">Select Size</option>

                            <option value="Small (1-5kg)">Small (1-5kg)</option>

                            <option value="Medium (6-10kg)">Medium (6-10kg)</option>

                            <option value="Large (11-15kg)">Large (11-15kg)</option>

                            <option value="Extra Large (16-20kg)">Extra Large (16-20kg)</option>

                            <option value="XXL Large (21-25kg)">XXL Large (21-25kg)</option>

                        </select>

                    </div>

                </div>



                <button type="submit" name="add_pet" class="btn-submit-modal">Register Pet</button>

            </form>

        </div>

    </div>



    <script>

    // --- MODAL LOGIC ---

    function openPetModal() {

        document.getElementById('addPetModal').style.display = 'flex';

    }



    function closePetModal() {

        document.getElementById('addPetModal').style.display = 'none';

    }



    // Close modal if clicked outside the content box

    window.onclick = function(event) {

        const modal = document.getElementById('addPetModal');

        if (event.target == modal) {

            modal.style.display = "none";

        }



        // existing notification logic

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




    // --- MOBILE SIDEBAR ---
    function toggleMobileSidebar(event) {
        if (event) event.stopPropagation();

        const sidebar = document.querySelector('aside');
        const backdrop = document.getElementById('sidebarBackdrop');
        const button = document.querySelector('.mobile-menu-toggle');

        if (!sidebar) return;

        const open = sidebar.classList.toggle('mobile-open');

        if (backdrop) {
            backdrop.classList.toggle('show', open);
        }

        if (button) {
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    }

    function closeMobileSidebar() {
        const sidebar = document.querySelector('aside');
        const backdrop = document.getElementById('sidebarBackdrop');
        const button = document.querySelector('.mobile-menu-toggle');

        if (sidebar) sidebar.classList.remove('mobile-open');
        if (backdrop) backdrop.classList.remove('show');
        if (button) button.setAttribute('aria-expanded', 'false');
    }

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeMobileSidebar();
        }
    });

    document.querySelectorAll('.nav-item').forEach(function(link) {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 900) {
                closeMobileSidebar();
            }
        });
    });

    // --- REAL-TIME NOTIFICATION FETCHER ---

    function renderAdminNotifications(data) {

        const badge = document.getElementById('admin-notif-badge');

        const notifList = document.getElementById('admin-notif-list');

        const markReadBtn = document.getElementById('mark-read-link');



        if (!badge || !notifList) return;



        const unread = Number(data && data.unread ? data.unread : 0);



        badge.style.display = unread > 0 ? 'inline-block' : 'none';

        badge.textContent = unread;



        if (markReadBtn) {

            markReadBtn.style.display = unread > 0 ? 'inline-block' : 'none';

        }



        notifList.innerHTML =

            (data && data.html)

                ? data.html

                : '<div class="notif-empty">No new notifications.</div>';

    }



    function fetchAdminNotifs() {

        fetch('get_admin_notifs.php', {

            method: 'GET',

            cache: 'no-store',

            credentials: 'same-origin'

        })

        .then(function(response) {

            if (!response.ok) {

                throw new Error(

                    'Notification request failed: HTTP ' + response.status

                );

            }

            return response.json();

        })

        .then(renderAdminNotifications)

        .catch(function(error) {

            console.error('Error fetching admin notifications:', error);

        });

    }



    window.fetchAdminNotifs = fetchAdminNotifs;



    const canFetchAdminNotifications = <?php echo $is_admin ? 'true' : 'false'; ?>;

    document.addEventListener('DOMContentLoaded', function() {
        if (canFetchAdminNotifications) {
            fetchAdminNotifs();
            setInterval(fetchAdminNotifs, 3000);
        }
    });



    // --- Pet Filter Logic ---

    document.addEventListener('DOMContentLoaded', function() {

        const searchInput = document.getElementById('petSearch');

        const breedFilter = document.getElementById('breedFilter');

        const tableRows = document.querySelectorAll('.pet-row');

        const countText = document.getElementById('showingCountText');



        const baseFilteredTotal = <?php echo $showing_count; ?>;



        function performFilter() {

            const searchTerm = searchInput.value.toLowerCase();

            const selectedBreed = breedFilter.value.toLowerCase();

            let visibleCount = 0;



            tableRows.forEach(row => {

                const petName = row.querySelector('td:first-child').innerText.toLowerCase();

                const ownerId = row.querySelector('td:nth-child(4)').innerText.toLowerCase();

                const breed = row.getAttribute('data-breed').toLowerCase();



                const matchesSearch = petName.includes(searchTerm) || breed.includes(searchTerm) || ownerId.includes(searchTerm);

                const matchesBreed = selectedBreed === "" || breed === selectedBreed;



                if (matchesSearch && matchesBreed) {

                    row.style.display = "";

                    visibleCount++;

                } else {

                    row.style.display = "none";

                }

            });



            countText.textContent = `Showing ${visibleCount} of ${baseFilteredTotal} pets`;

        }



        searchInput.addEventListener('input', performFilter);

        breedFilter.addEventListener('change', performFilter);

    });

    </script>

</body>

</html>