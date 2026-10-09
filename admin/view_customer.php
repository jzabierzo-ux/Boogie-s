<?php
session_start();
require_once '../db_supabase.php';
date_default_timezone_set('Asia/Manila');

// Normalize legacy role names to the same role names used by manageusers.php.
$raw_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$role_aliases = [
    'administrator' => 'admin',
    'shop manager' => 'manager',
    'shop_manager' => 'manager',
    'vet' => 'veterinarian',
    'veterinary' => 'veterinarian',
    'front desk' => 'receptionist',
    'front desk / receptionist' => 'receptionist',
    'front_desk' => 'receptionist',
    'front_desk_receptionist' => 'receptionist',
    'veterinary assistant' => 'vet_assistant',
    'vet assistant' => 'vet_assistant',
    'veterinary_assistant' => 'vet_assistant',
    'veterinary nurse' => 'vet_nurse',
    'vet nurse' => 'vet_nurse',
    'veterinary_nurse' => 'vet_nurse',
    'grooming staff' => 'groomer',
    'grooming staff / groomer' => 'groomer',
    'grooming_staff' => 'groomer',
    'grooming_staff_groomer' => 'groomer',
    'pet hotel staff' => 'pet_hotel_staff',
    'pet hotel staff / attendant' => 'pet_hotel_staff',
    'pet_hotel_attendant' => 'pet_hotel_staff',
    'pet_hotel_staff_attendant' => 'pet_hotel_staff',
    // Legacy mappings retained while old staff accounts are migrated.
    'staff' => 'groomer',
    'supervisor' => 'receptionist',
];
$current_role = $role_aliases[$raw_role] ?? $raw_role;
if ($current_role !== '') {
    $_SESSION['role'] = $current_role;
}
$is_admin = ($current_role === 'admin');

if (($_SESSION['logged_in'] ?? false) !== true) {
    $login_target = ($current_role === 'admin') ? '../admin_login.php' : '../staff/stafflogin.php';
    header('Location: ' . $login_target);
    exit();
}

if (empty($_SESSION['user_id']) || !is_numeric($_SESSION['user_id']) || (int)$_SESSION['user_id'] < 1) {
    http_response_code(403);
    exit('Access denied: invalid session.');
}

if (in_array($current_role, ['veterinarian', 'vet_assistant', 'vet_nurse'], true)) {
    header('Location: ../staff/staffdashboard.php');
    exit();
}
if ($current_role === 'pet_hotel_staff') {
    header('Location: managebooking.php');
    exit();
}
$allowed_roles = ['admin', 'manager', 'receptionist', 'groomer'];
if (!in_array($current_role, $allowed_roles, true)) {
    http_response_code(403);
    exit('Access denied. This role cannot access customer details.');
}

// Editing a customer's contact number is an operational permission; permanent deletion is Admin-only.
$can_update_contact = in_array($current_role, ['admin', 'manager', 'receptionist'], true);
$can_delete_customer = $is_admin;

if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = (string)$_SESSION['csrf_token'];

$admin_full_name = (string)($_SESSION['user_name'] ?? 'User');
$profile_img_path = '';
$profile_img_url = '';
$first_name = trim(explode(' ', $admin_full_name)[0] ?? 'User', ',');

try {
    $uid = (int)$_SESSION['user_id'];
    $get_admin = $pdo->prepare('SELECT full_name, profile_image FROM users WHERE id = :id LIMIT 1');
    $get_admin->execute([':id' => $uid]);
    $admin_data = $get_admin->fetch(PDO::FETCH_ASSOC);
    if ($admin_data) {
        $admin_full_name = (string)($admin_data['full_name'] ?? $admin_full_name);
        $profile_img_path = trim((string)($admin_data['profile_image'] ?? ''));
        $_SESSION['user_name'] = $admin_full_name;
        $first_name = trim(explode(' ', $admin_full_name)[0] ?? 'User', ',');
    }
} catch (PDOException $e) {
    error_log('view_customer profile lookup failed: ' . $e->getMessage());
}

// Resolve profile images safely against the project's supported local upload paths.
if ($profile_img_path !== '') {
    $normalized_profile_path = str_replace('\\', '/', $profile_img_path);
    if (strpos($normalized_profile_path, 'uploads/') === 0 && strpos($normalized_profile_path, '..') === false) {
        $admin_upload_path = __DIR__ . '/' . $normalized_profile_path;
        $root_upload_path = dirname(__DIR__) . '/' . $normalized_profile_path;
        if (is_file($admin_upload_path)) {
            $profile_img_url = $normalized_profile_path;
        } elseif (is_file($root_upload_path)) {
            $profile_img_url = '../' . $normalized_profile_path;
        }
    } elseif (strpos($normalized_profile_path, '../uploads/') === 0 && strpos($normalized_profile_path, '..') === 0) {
        $root_relative = substr($normalized_profile_path, 3);
        if (strpos($root_relative, 'uploads/') === 0 && is_file(dirname(__DIR__) . '/' . $root_relative)) {
            $profile_img_url = '../' . $root_relative;
        }
    }
}

// Validate the customer ID before any read or write query.
$requested_id = (string)($_GET['id'] ?? '');
if ($requested_id === '' || !ctype_digit($requested_id) || (int)$requested_id < 1) {
    header('Location: manageusers.php');
    exit();
}
$customer_id = (int)$requested_id;
$page_message = '';
$page_message_type = 'error';

// Show post/redirect/get success message without replaying the submitted form.
if (!empty($_SESSION['view_customer_flash']) && is_array($_SESSION['view_customer_flash'])) {
    $page_message = (string)($_SESSION['view_customer_flash']['message'] ?? '');
    $page_message_type = (string)($_SESSION['view_customer_flash']['type'] ?? 'success');
    unset($_SESSION['view_customer_flash']);
}

// Process only explicitly named POST actions. All mutations require a valid CSRF token.
$is_update_request = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_contact']));
$is_delete_request = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user']));
if ($is_update_request || $is_delete_request) {
    $posted_token = (string)($_POST['csrf_token'] ?? '');
    if ($posted_token === '' || !hash_equals($csrf_token, $posted_token)) {
        http_response_code(403);
        exit('Invalid security token. Please refresh the page and try again.');
    }
}

if ($is_update_request) {
    if (!$can_update_contact) {
        http_response_code(403);
        exit('Access denied. You do not have permission to edit customer contact details.');
    }

    $new_contact = trim((string)($_POST['new_contact'] ?? ''));
    if (!preg_match('/^[0-9]{11}$/', $new_contact)) {
        $page_message = 'Invalid contact number. Please enter exactly 11 digits.';
        $page_message_type = 'error';
    } else {
        try {
            $stmt_update = $pdo->prepare("UPDATE users SET contact_number = :contact_number WHERE id = :customer_id AND role = 'customer'");
            $stmt_update->execute([
                ':contact_number' => $new_contact,
                ':customer_id' => $customer_id,
            ]);

            if ($stmt_update->rowCount() !== 1) {
                $page_message = 'Customer contact number was not updated. Please confirm that the customer still exists.';
                $page_message_type = 'error';
            } else {
                $_SESSION['view_customer_flash'] = [
                    'message' => 'Contact number updated successfully.',
                    'type' => 'success',
                ];
                header('Location: view_customer.php?id=' . $customer_id);
                exit();
            }
        } catch (PDOException $e) {
            error_log('view_customer contact update failed: ' . $e->getMessage());
            $page_message = 'Unable to update the contact number at this time.';
            $page_message_type = 'error';
        }
    }
}

if ($is_delete_request) {
    if (!$can_delete_customer) {
        http_response_code(403);
        exit('Access denied. Only an Admin can delete a customer account.');
    }

    try {
        $pdo->beginTransaction();

        // Confirm the target is a customer before deleting any related records.
        $customer_check = $pdo->prepare("SELECT id FROM users WHERE id = :customer_id AND role = 'customer' FOR UPDATE");
        $customer_check->execute([':customer_id' => $customer_id]);
        if (!$customer_check->fetchColumn()) {
            $pdo->rollBack();
            header('Location: manageusers.php');
            exit();
        }

        // Delete dependent rows in FK-safe order. All steps roll back together on failure.
        $delete_appointments = $pdo->prepare('DELETE FROM appointments WHERE user_id = :customer_id');
        $delete_appointments->execute([':customer_id' => $customer_id]);

        $delete_pets = $pdo->prepare('DELETE FROM pets WHERE owner_id = :customer_id');
        $delete_pets->execute([':customer_id' => $customer_id]);

        $delete_user = $pdo->prepare("DELETE FROM users WHERE id = :customer_id AND role = 'customer'");
        $delete_user->execute([':customer_id' => $customer_id]);
        if ($delete_user->rowCount() !== 1) {
            throw new RuntimeException('Customer account was not deleted.');
        }

        $pdo->commit();
        header('Location: manageusers.php');
        exit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('view_customer customer deletion failed: ' . $e->getMessage());
        $page_message = 'Unable to delete this customer account at this time. No partial changes were saved.';
        $page_message_type = 'error';
    }
}

// Fetch the target customer and their records using parameterized queries.
try {
    $user_stmt = $pdo->prepare("SELECT * FROM users WHERE id = :customer_id AND role = 'customer' LIMIT 1");
    $user_stmt->execute([':customer_id' => $customer_id]);
    $customer = $user_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('view_customer customer lookup failed: ' . $e->getMessage());
    $customer = false;
}

if (!$customer) {
    http_response_code(404);
    exit("<div style='text-align:center;padding:50px;font-family:sans-serif;'><h2>Customer not found.</h2><a href='manageusers.php'>Go Back</a></div>");
}

try {
    $pets_stmt = $pdo->prepare('SELECT * FROM pets WHERE owner_id = :customer_id ORDER BY id ASC');
    $pets_stmt->execute([':customer_id' => $customer_id]);
    $pets = $pets_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('view_customer pets lookup failed: ' . $e->getMessage());
    $pets = [];
}

try {
    $bookings_stmt = $pdo->prepare('SELECT a.*, p.name AS pet_name FROM appointments a LEFT JOIN pets p ON a.pet_id = p.id WHERE a.user_id = :customer_id ORDER BY a.appointment_date DESC');
    $bookings_stmt->execute([':customer_id' => $customer_id]);
    $bookings = $bookings_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('view_customer booking history lookup failed: ' . $e->getMessage());
    $bookings = [];
}

$category = !empty($customer['user_category']) ? (string)$customer['user_category'] : 'Pet Owner';
$cat_class = ($category === 'Pet Breeder') ? 'category-breeder' : 'category-owner';
$display_customer_name = (string)($customer['full_name'] ?? 'Customer');

// This table holds Admin-only alerts; do not expose it to operational staff roles.
$admin_notifications = [];
$unread_count = 0;
if ($is_admin) {
    try {
        $admin_notif_stmt = $pdo->prepare('SELECT id, message, created_at FROM admin_notifications WHERE is_read = 0 ORDER BY created_at DESC LIMIT 20');
        $admin_notif_stmt->execute();
        $admin_notifications = $admin_notif_stmt->fetchAll(PDO::FETCH_ASSOC);
        $unread_count = count($admin_notifications);
    } catch (PDOException $e) {
        error_log('view_customer admin notifications lookup failed: ' . $e->getMessage());
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

    <title><?php echo htmlspecialchars((string)($customer['full_name'] ?? 'Customer'), ENT_QUOTES, 'UTF-8'); ?> | Customer Details</title>

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght\@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>

    :root {

        /* Boogie's Signature Colors */

        --brand-blue: #001f3f; 

        --brand-yellow: #ffcc00;

        --brand-purple: #8b2cf5;

        --bg-light: #f4f7f6; 

        --white: #ffffff;

        --text-main: #2d3436;

        --text-muted: #64748b;

        --border: #e2e8f0;

    }

    * { margin: 0; padding: 0; box-sizing: border-box; }

    body { 

        font-family: 'Poppins', sans-serif; 

        background: var(--bg-light); 

        color: var(--text-main); 

        padding: 30px; 

    }

    /* Upgraded Back Button */

    .back-btn { 

        text-decoration: none; 

        color: var(--brand-blue); 

        font-weight: 700; 

        font-size: 14px; 

        display: inline-flex; 

        align-items: center; 

        gap: 8px; 

        margin-bottom: 25px; 

        transition: 0.3s; 

        padding: 10px 18px; 

        background: var(--white); 

        border-radius: 12px; 

        box-shadow: 0 4px 6px rgba(0,0,0,0.03); 

    }

    .back-btn:hover { 

        background: var(--brand-blue); 

        color: var(--brand-yellow); 

        transform: translateY(-2px); 

        box-shadow: 0 8px 15px rgba(0, 31, 63, 0.2);

    }

    .profile-grid { 

        display: grid; 

        grid-template-columns: 350px 1fr; 

        gap: 25px; 

        align-items: start; 

        max-width: 1200px;

        margin: 0 auto;

    }

    /* Upgraded Cards with Top Highlight */

    .card { 

        background: var(--white); 

        border-radius: 20px; 

        border: none; 

        padding: 30px; 

        box-shadow: 0 4px 15px rgba(0,0,0,0.03); 

        margin-bottom: 25px; 

        position: relative; 

        overflow: hidden; 

    }

    .card::before { 

        content: ''; 

        position: absolute; 

        top: 0; 

        left: 0; 

        width: 100%; 

        height: 5px; 

        background: linear-gradient(90deg, var(--brand-blue), var(--brand-purple)); 

    }

    .section-title { 

        font-size: 18px; 

        font-weight: 800; 

        margin-bottom: 20px; 

        display: flex; 

        align-items: center; 

        gap: 10px; 

        color: var(--brand-blue); 

    }

    .section-title i { color: var(--brand-purple); }

    /* Circular Avatar */

    .profile-header { text-align: center; margin-bottom: 25px; }

    .avatar-large { 

        width: 100px; 

        height: 100px; 

        background: #f3e8ff; 

        color: var(--brand-purple); 

        border-radius: 50%; 

        display: flex; 

        align-items: center; 

        justify-content: center; 

        font-size: 40px; 

        font-weight: 800; 

        margin: 0 auto 15px; 

        border: 4px solid var(--white); 

        box-shadow: 0 8px 16px rgba(139, 44, 245, 0.15); 

    }

    /* BAGO: Category Tags */

    .category-tag {

        display: inline-block;

        padding: 4px 12px;

        border-radius: 6px;

        font-size: 11px;

        font-weight: 700;

        text-transform: uppercase;

        letter-spacing: 0.5px;

        margin-bottom: 20px;

    }

    .category-owner { background: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd; }

    .category-breeder { background: #fef3c7; color: #d97706; border: 1px solid #fde68a; }

    /* Customer Info */

    .info-item { 

        margin-bottom: 15px; 

        padding-bottom: 12px; 

        border-bottom: 1px dashed var(--border); 

    }

    .info-item:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }

    .info-label { 

        font-size: 11px; 

        font-weight: 700; 

        color: var(--text-muted); 

        text-transform: uppercase; 

        letter-spacing: 0.5px; 

        margin-bottom: 4px; 

        display: flex;

        justify-content: space-between;

    }

    .info-value { 

        font-size: 14px; 

        font-weight: 700; 

        color: var(--brand-blue); 

    }

    /* Edit Input */

    .edit-input {

        padding: 8px 10px;

        border: 1px solid var(--border);

        border-radius: 6px;

        font-family: inherit;

        font-size: 13px;

        width: 65%;

        outline: none;

    }

    .edit-input:focus { border-color: var(--brand-blue); }

    .btn-save {

        padding: 8px 15px;

        background: var(--brand-blue);

        color: var(--brand-yellow);

        border: none;

        border-radius: 6px;

        font-size: 12px;

        font-weight: 700;

        cursor: pointer;

        transition: 0.2s;

    }

    .btn-save:hover { opacity: 0.9; }

    .btn-delete {

        width: 100%;

        padding: 14px;

        background: #fff1f2;

        color: #e11d48;

        border: 1px dashed #fda4af;

        border-radius: 12px;

        font-weight: 700;

        font-size: 14px;

        cursor: pointer;

        display: flex;

        align-items: center;

        justify-content: center;

        gap: 8px;

        margin-top: 25px;

        transition: 0.3s;

    }

    .btn-delete:hover {

        background: #e11d48;

        color: white;

        border-style: solid;

    }

    /* Interactive Pet Items */

    .pet-item { 

        display: flex; 

        align-items: center; 

        gap: 15px; 

        padding: 15px; 

        background: #f8fafc; 

        border-radius: 12px; 

        margin-bottom: 12px; 

        border-left: 4px solid var(--brand-purple); 

        transition: 0.2s; 

    }

    .pet-item:hover { 

        background: #f1f5f9; 

        transform: translateX(5px); 

    }

    /* Table Styles */

    table { width: 100%; border-collapse: collapse; }

    th { 

        text-align: left; 

        padding: 15px; 

        font-size: 12px; 

        color: var(--text-muted); 

        text-transform: uppercase; 

        border-bottom: 2px solid var(--bg-light); 

        letter-spacing: 0.5px; 

    }

    td { 

        padding: 15px; 

        font-size: 13px; 

        border-bottom: 1px solid #f1f5f9; 

        vertical-align: middle; 

        font-weight: 500;

    }

    tr:hover td { background-color: #f8fafc; }

    /* Status Pill */

    .status-pill { 

        padding: 6px 14px; 

        border-radius: 50px; 

        font-size: 10px; 

        font-weight: 800; 

        text-transform: uppercase; 

        background: #f1f5f9; 

        color: var(--text-muted); 

        display: inline-block; 

    }

    .st-cancelled, .st-no-show { background: #fee2e2; color: #b91c1c; }

    .st-completed { background: #dcfce7; color: #16a34a; }

    .st-confirmed { background: #e0f2fe; color: #0284c7; }

    @media (max-width: 900px) {

        .profile-grid { grid-template-columns: 1fr; }

    }

        /* ===== EXTRA MOBILE RESPONSIVENESS ===== */

        @media (max-width: 700px) {

            body {

                padding: 18px 12px;

            }

            .back-btn {

                width: 100%;

                justify-content: center;

                margin-bottom: 16px;

                padding: 12px 14px;

                font-size: 13px;

            }

            .profile-grid {

                grid-template-columns: 1fr;

                gap: 16px;

                max-width: 100%;

            }

            .card {

                padding: 20px 15px;

                border-radius: 16px;

                margin-bottom: 16px;

            }

            .section-title {

                font-size: 16px;

                line-height: 1.35;

            }

            .profile-header {

                margin-bottom: 20px;

            }

            .avatar-large {

                width: 86px;

                height: 86px;

                font-size: 34px;

            }

            .info-label {

                gap: 8px;

                flex-wrap: wrap;

            }

            .info-value {

                font-size: 13px;

                overflow-wrap: anywhere;

            }

            .edit-input {

                width: 100%;

                min-width: 0;

                min-height: 44px;

                padding: 9px 10px;

            }

            #contactForm > div {

                flex-direction: column;

                gap: 8px;

            }

            .btn-save {

                width: 100%;

                min-height: 44px;

            }

            .btn-delete {

                min-height: 46px;

                font-size: 13px;

            }

            .pet-item {

                align-items: flex-start;

                padding: 13px;

            }

            /* Keep appointment history usable on phones via horizontal scrolling. */

            .main-col .card > div[style*="overflow-x"] {

                overflow-x: auto;

                -webkit-overflow-scrolling: touch;

            }

            .main-col table {

                min-width: 620px;

            }

            th,

            td {

                white-space: nowrap;

            }

            td strong,

            td .status-pill {

                white-space: normal;

            }

        }

        @media (max-width: 400px) {

            body {

                padding: 12px 9px;

            }

            .card {

                padding: 18px 12px;

            }

            .section-title {

                font-size: 15px;

            }

            .avatar-large {

                width: 78px;

                height: 78px;

                font-size: 30px;

            }

        }

/* ===== SHARED ADMIN SHELL + MOBILE RESPONSIVENESS ===== */
:root{--navy-dark:#001f3f;--brand-yellow:#ffcc00;--sidebar-width:260px}
body{padding:0;display:flex;min-height:100vh;overflow-x:hidden}
aside{width:var(--sidebar-width);background:var(--navy-dark);color:#fff;display:flex;flex-direction:column;position:fixed;inset:0 auto 0 0;height:100vh;z-index:2000;overflow-y:auto}
.sidebar-header{padding:30px 20px;text-align:center;border-bottom:1px solid rgba(255,255,255,.05)}
.sidebar-logo{width:80px;height:auto;object-fit:contain;margin-bottom:10px}.sidebar-header h2{font-size:16px;color:var(--brand-yellow);text-transform:uppercase;letter-spacing:1px;font-weight:800}
.nav-links{flex-grow:1;padding:20px 15px;display:flex;flex-direction:column;gap:5px}.nav-item{display:flex;align-items:center;padding:14px 20px;color:#94a3b8;text-decoration:none;font-size:14px;font-weight:500;border-radius:10px;position:relative;transition:all .3s}.nav-item i{width:32px;font-size:18px}.nav-item:hover{color:#fff;background:rgba(255,255,255,.05);transform:translateX(4px)}.nav-item.active{color:var(--brand-yellow);background:rgba(255,204,0,.08);font-weight:700}.nav-item.active:before{content:'';position:absolute;left:-15px;top:15%;height:70%;width:5px;background:var(--brand-yellow);border-radius:0 5px 5px 0}
main{margin-left:var(--sidebar-width);flex:1;min-width:0;min-height:100vh}.top-bar{height:70px;padding:0 40px;background:#fff;display:flex;justify-content:space-between;align-items:center;box-shadow:0 1px 10px rgba(0,0,0,.08);position:sticky;top:0;z-index:1200}.topbar-left{display:flex;align-items:center;gap:12px;min-width:0}.mobile-menu-toggle{display:none;border:0;background:var(--navy-dark);color:var(--brand-yellow);width:40px;height:40px;border-radius:10px;cursor:pointer;font-size:18px;align-items:center;justify-content:center;flex:0 0 auto}.breadcrumb{font-weight:700;color:var(--navy-dark);font-size:15px;display:flex;align-items:center;gap:8px;white-space:nowrap}.top-right-actions{display:flex;align-items:center;gap:20px}.notif-wrapper,.profile-wrapper{position:relative;display:flex;align-items:center;cursor:pointer}.notif-wrapper{gap:0}.notif-badge{position:absolute;top:-9px;right:-9px;min-width:18px;height:18px;padding:0 5px;background:#e11d48;color:#fff;border-radius:999px;font-size:10px;font-weight:800;display:inline-flex;align-items:center;justify-content:center}.notif-dropdown,.profile-dropdown{display:none;position:absolute;background:#fff;border:1px solid #e2e8f0;box-shadow:0 10px 20px rgba(15,23,42,.12);border-radius:10px;z-index:3000;overflow:hidden}.notif-dropdown.show,.profile-dropdown.show{display:block}.notif-dropdown{right:-6px;top:32px;width:320px;text-align:left}.notif-header{padding:12px 15px;border-bottom:1px solid #e2e8f0;font-weight:800;font-size:14px;display:flex;justify-content:space-between;align-items:center;color:#0f172a}.notif-body{max-height:300px;overflow-y:auto}.notif-item{padding:12px 15px;border-bottom:1px solid #f1f5f9;font-size:13px;color:#334155;line-height:1.4}.notif-empty{padding:20px;text-align:center;color:#94a3b8;font-size:13px}.mark-read-btn{font-size:11px;color:#3b82f6;text-decoration:none;font-weight:700}.profile-wrapper{gap:8px;padding-left:10px;border-left:1px solid #e2e8f0}.admin-tag{background:var(--navy-dark);color:var(--brand-yellow);padding:5px 10px;border-radius:6px;font-size:9px;font-weight:800;letter-spacing:.5px}.top-avatar,.top-avatar-fallback{width:32px;height:32px;border-radius:50%}.top-avatar{object-fit:cover}.top-avatar-fallback{display:flex;align-items:center;justify-content:center;background:#e2e8f0;color:var(--navy-dark);font-weight:800}.profile-dropdown{right:0;top:44px;width:200px}.profile-item{padding:12px 15px;display:flex;align-items:center;gap:10px;font-size:13px;color:var(--text-main);text-decoration:none}.profile-item:hover{background:#f1f5f9}.profile-item.logout-text{color:#e11d48;border-top:1px solid #f1f5f9}.content-shell{padding:30px}.sidebar-backdrop{display:none}
@media(max-width:900px){body{overflow-x:hidden}aside{transform:translateX(-100%);transition:transform .25s ease;box-shadow:12px 0 30px rgba(0,0,0,.18)}aside.mobile-open{transform:translateX(0)}.sidebar-backdrop{position:fixed;inset:0;background:rgba(0,31,63,.48);z-index:1900}.sidebar-backdrop.show{display:block}main{margin-left:0;width:100%;min-width:0}.top-bar{height:64px;padding:0 14px;gap:10px}.mobile-menu-toggle{display:inline-flex}.topbar-left{flex:1 1 auto;min-width:0}.breadcrumb{font-size:13px;gap:6px;overflow:hidden;text-overflow:ellipsis;min-width:0}.top-right-actions{gap:10px;margin-left:auto;min-width:0;flex:0 0 auto}.profile-wrapper{gap:8px;padding-left:10px;flex:0 0 auto}.profile-wrapper>span:last-of-type{max-width:110px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px!important}.admin-tag{padding:5px 10px;font-size:9px}.top-avatar,.top-avatar-fallback{width:32px;height:32px}.notif-dropdown{width:min(320px,calc(100vw - 28px));right:-6px}.content-shell{padding:22px 16px}.back-btn{width:100%;justify-content:center}.profile-grid{grid-template-columns:1fr}.main-col table{min-width:620px}.main-col .card>div[style*="overflow-x"]{overflow-x:auto;-webkit-overflow-scrolling:touch}.edit-input{width:100%;min-width:0}.#contactForm>div{flex-direction:column;gap:8px}.btn-save{width:100%;min-height:44px}.pet-item{align-items:flex-start}}
@media(max-width:560px){.top-bar{height:58px;padding:0 8px}.topbar-left{gap:7px}.mobile-menu-toggle{width:36px;height:36px;border-radius:9px;font-size:16px}.breadcrumb{font-size:11px}.top-right-actions{gap:5px}.profile-wrapper{gap:5px;padding-left:6px}.profile-wrapper>span:last-of-type{display:none!important}.admin-tag{padding:4px 7px;font-size:7px}.top-avatar,.top-avatar-fallback{width:29px;height:29px}.content-shell{padding:16px 12px 22px}}

/* ===== COMPACT MOBILE VIEW ===== */
@media (max-width: 900px){
  .content-shell{padding:18px 12px 24px;}
  .profile-grid{gap:14px;}
  .card{padding:18px 14px;border-radius:14px;margin-bottom:14px;}
  .back-btn{padding:10px 12px;font-size:12px;margin-bottom:12px;}
  .profile-header{margin-bottom:16px;}
  .avatar-large{width:72px;height:72px;font-size:28px;margin-bottom:10px;}
  .profile-header h2{font-size:17px !important;}
  .category-tag{font-size:9px;padding:4px 9px;margin-bottom:14px;}
  .section-title{font-size:14px;margin-bottom:14px;gap:7px;}
  .info-item{margin-bottom:11px;padding-bottom:9px;}
  .info-label{font-size:9px;margin-bottom:3px;}
  .info-value{font-size:12px;line-height:1.35;}
  .edit-input{font-size:12px;min-height:40px;padding:8px 9px;}
  .btn-save{min-height:40px;font-size:11px;padding:8px 12px;}
  .btn-delete{min-height:40px;padding:10px;font-size:11px;margin-top:16px;}
  .pet-item{gap:10px;padding:10px;border-radius:9px;margin-bottom:8px;}
  .pet-item i{font-size:16px !important;}
  .pet-item div[style*="font-weight: 700"]{font-size:12px !important;}
  .pet-item div[style*="font-size: 12px"]{font-size:10px !important;}
  .main-col table{min-width:560px;}
  th{padding:9px 10px;font-size:9px;}
  td{padding:10px;font-size:10px;}
  .status-pill{padding:4px 9px;font-size:9px;}
}
@media (max-width: 560px){
  .top-bar{height:54px;padding:0 7px;}
  .mobile-menu-toggle{width:34px;height:34px;border-radius:8px;font-size:15px;}
  .breadcrumb{font-size:10px;}
  .notif-wrapper > i{font-size:17px !important;}
  .admin-tag{padding:3px 6px;font-size:6px;}
  .top-avatar,.top-avatar-fallback{width:27px;height:27px;}
  .content-shell{padding:12px 9px 18px;}
  .card{padding:15px 11px;border-radius:12px;}
  .profile-grid{gap:10px;}
  .back-btn{font-size:11px;padding:9px 10px;}
  .profile-header h2{font-size:16px !important;}
  .avatar-large{width:64px;height:64px;font-size:25px;}
  .section-title{font-size:13px;}
  .info-value{font-size:11px;}
  .btn-delete{font-size:10px;}
}


    .flash-message { margin: 0 0 18px; padding: 12px 15px; border-radius: 9px; font-size: 13px; line-height: 1.5; border-left: 4px solid transparent; }
    .flash-message.success { background: #dcfce7; color: #166534; border-left-color: #16a34a; }
    .flash-message.error { background: #fee2e2; color: #991b1b; border-left-color: #dc2626; }

</style>

</head>

<body>
<aside>
  <div class="sidebar-header"><img src="bg.png" alt="Boogie's Logo" class="sidebar-logo"><h2><?php echo ($current_role==='admin')?"Boogie's Admin":"Boogie's Staff"; ?></h2></div>
  <nav class="nav-links">
    <a href="admindashboard.php" class="nav-item"><i class="fas fa-chart-line"></i> Dashboard</a>
    <a href="managebooking.php" class="nav-item"><i class="fas fa-calendar-alt"></i> Bookings</a>
    <a href="manageusers.php" class="nav-item active"><i class="fas fa-users"></i> Users</a>
    <a href="managepet.php" class="nav-item"><i class="fas fa-dog"></i> Pets</a>
    <?php if($current_role==='admin'): ?>
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
  <div class="topbar-left"><button class="mobile-menu-toggle" type="button" aria-label="Open admin menu" aria-expanded="false" onclick="toggleMobileSidebar(event)"><i class="fas fa-bars"></i></button><div class="breadcrumb"><i class="fas fa-users" style="opacity:.5;font-size:14px"></i> Management / Customer Details</div></div>
  <div class="top-right-actions">
    <?php if ($is_admin): ?>
    <div class="notif-wrapper" onclick="toggleNotif(event)"><i class="fa-solid fa-bell" style="font-size:22px;color:#64748b"></i><span id="admin-notif-badge" class="notif-badge" style="display:<?php echo $unread_count>0?'inline-flex':'none'; ?>"><?php echo $unread_count; ?></span>
      <div class="notif-dropdown" id="notifBox" onclick="event.stopPropagation()"><div class="notif-header">Alerts <a href="mark_notifications_read.php" id="mark-read-link" class="mark-read-btn" style="display:<?php echo $unread_count>0?'inline-block':'none'; ?>">Mark all read</a></div><div class="notif-body" id="admin-notif-list"><?php if($unread_count>0): foreach($admin_notifications as $notif): ?><div class="notif-item"><i class="fa-solid fa-circle-exclamation" style="color:#e11d48;margin-right:5px"></i><?php echo htmlspecialchars((string)($notif['message']??''),ENT_QUOTES,'UTF-8'); ?><br><small style="color:#94a3b8;font-size:11px"><?php echo !empty($notif['created_at'])?date('M d, g:i A',strtotime((string)$notif['created_at'])):''; ?></small></div><?php endforeach; else: ?><div class="notif-empty">No new notifications.</div><?php endif; ?></div></div>
    </div>
    <?php endif; ?>
    <div class="profile-wrapper" onclick="toggleProfile(event)"><span class="admin-tag"><?php echo strtoupper($current_role?:'ADMIN'); ?></span><?php if($profile_img_url !== ''): ?><img src="<?php echo htmlspecialchars($profile_img_url,ENT_QUOTES,'UTF-8'); ?>" class="top-avatar" alt="Profile Picture"><?php else: ?><div class="top-avatar-fallback"><?php echo htmlspecialchars(strtoupper(substr($first_name,0,1)), ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?><span style="font-size:14px;font-weight:600;color:#4a5568;display:flex;align-items:center;gap:6px"><?php echo htmlspecialchars($admin_full_name,ENT_QUOTES,'UTF-8'); ?><i class="fas fa-chevron-down" style="font-size:10px;color:#94a3b8"></i></span><div class="profile-dropdown" id="profileBox" onclick="event.stopPropagation()"><a href="<?php echo $is_admin ? 'admin_profile.php' : '../staff/staff_profile.php'; ?>" class="profile-item"><i class="fas fa-user-circle"></i> My Profile</a><a href="../logout.php" class="profile-item logout-text"><i class="fas fa-sign-out-alt"></i> Logout</a></div></div>
  </div>
</header>
<div class="content-shell">

    <a href="manageusers.php" class="back-btn"><i class="fas fa-chevron-left"></i> Back to Directory</a>

    <?php if ($page_message !== ''): ?>
        <div class="flash-message <?php echo $page_message_type === 'success' ? 'success' : 'error'; ?>" role="status"><?php echo htmlspecialchars($page_message, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="profile-grid">

        <div class="sidebar-col">

            <div class="card">

                <div class="profile-header">

                    <div class="avatar-large"><?php echo htmlspecialchars(strtoupper(substr(trim((string)($customer['full_name'] ?? 'Customer'), ','), 0, 1)), ENT_QUOTES, 'UTF-8'); ?></div>

                    <h2 style="font-size: 20px; color: var(--brand-blue);"><?php echo htmlspecialchars((string)($customer['full_name'] ?? 'Customer'), ENT_QUOTES, 'UTF-8'); ?></h2>

                    <span class="category-tag <?php echo $cat_class; ?>"><?php echo htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?></span>

                </div>

                <div class="info-item">

                    <div class="info-label">Email Address</div>

                    <div class="info-value">

                        <?php 

                        $raw_email = $customer['email'] ?? '';

                        if (empty($raw_email) || strpos($raw_email, '@guest.local') !== false) {

                            echo '<span style="color:#ea580c; font-weight:700; font-size:11px; background:#ffedd5; padding:2px 6px; border-radius:4px; border: 1px solid #fdba74;">WALK-IN GUEST</span>';

                        } else {

                            echo htmlspecialchars((string)$raw_email, ENT_QUOTES, 'UTF-8');

                        }

                        ?>

                    </div>

                </div>

                <div class="info-item">

                    <div class="info-label">

                        Contact Number

                        <?php if ($can_update_contact): ?><a href="#" onclick="toggleEdit(); return false;" style="color:var(--brand-purple); text-decoration:none;"><i class="fas fa-edit"></i> Edit</a><?php endif; ?>

                    </div>

                    <div class="info-value" id="contactDisplay">

                        <?php echo htmlspecialchars((string)($customer['contact_number'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?>

                    </div>

                    <?php if ($can_update_contact): ?>
                    <form method="POST" action="view_customer.php?id=<?php echo $customer_id; ?>" id="contactForm" style="display:none; margin-top: 8px;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                        <div style="display: flex; gap: 5px;">

                            <input type="tel" inputmode="numeric" name="new_contact" class="edit-input" value="<?php echo htmlspecialchars((string)($customer['contact_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required maxlength="11" pattern="[0-9]{11}" oninput="this.value = this.value.replace(/[^0-9]/g, '')">

                            <button type="submit" name="update_contact" class="btn-save">Save</button>

                        </div>

                    </form>
                    <?php endif; ?>

                </div>

                <div class="info-item">

                    <div class="info-label">Account Verification</div>

                    <div class="info-value">

                        <?php

                        $verified_raw = $customer['is_verified'] ?? false;

                        $is_verified = in_array(

                            strtolower(trim((string)$verified_raw)),

                            ['1', 'true', 't'],

                            true

                        );

                        ?>

                        <?php if ($is_verified): ?>

                            <span style="color: #10b981; font-size: 13px;"><i class="fa-solid fa-circle-check"></i> Verified</span>

                        <?php else: ?>

                            <span style="color: #ef4444; font-size: 13px;"><i class="fa-solid fa-circle-xmark"></i> Unverified</span>

                        <?php endif; ?>

                    </div>

                </div>

                <div class="info-item">

                    <div class="info-label">Member Since</div>

                    <div class="info-value" style="font-weight: 500;">

                        <?php echo isset($customer['created_at']) ? date('F d, Y', strtotime($customer['created_at'])) : 'Unknown'; ?>

                    </div>

                </div>

                <?php if ($can_delete_customer): ?>
                <form method="POST" action="view_customer.php?id=<?php echo $customer_id; ?>" onsubmit="return confirm('WARNING: Are you sure you want to delete this user? All their pets and appointment history will also be permanently removed. This action cannot be undone.');">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" name="delete_user" class="btn-delete">
                        <i class="fas fa-trash-alt"></i> Delete Account
                    </button>
                </form>
                <?php endif; ?>

            </div>

            <div class="card">

                <div class="section-title"><i class="fas fa-paw"></i> Registered Pets</div>

                <?php if(count($pets) > 0): ?>

                    <?php foreach($pets as $pet): ?>

                        <div class="pet-item">

                            <i class="fas fa-dog" style="color:var(--brand-blue); font-size: 20px; opacity: 0.8;"></i>

                            <div>

                                <div style="font-weight: 700; font-size: 14px; color: var(--brand-blue);">

                                    <?php 

                                        if (isset($pet['pet_name'])) echo htmlspecialchars((string)$pet['pet_name'], ENT_QUOTES, 'UTF-8');

                                        elseif (isset($pet['name'])) echo htmlspecialchars((string)$pet['name'], ENT_QUOTES, 'UTF-8');

                                        else echo "Unknown Pet";

                                    ?>

                                </div>

                                <div style="font-size: 12px; color: var(--text-muted);">

                                    <?php echo htmlspecialchars((string)($pet['breed'] ?? 'Unknown Breed'), ENT_QUOTES, 'UTF-8'); ?>

                                </div>

                            </div>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <div style="text-align: center; padding: 20px;">

                        <i class="fa-solid fa-bone" style="font-size: 30px; color: #cbd5e1; margin-bottom: 10px;"></i>

                        <p style="font-size: 13px; color: var(--text-muted);">No pets registered yet.</p>

                    </div>

                <?php endif; ?>

            </div>

        </div>

        <div class="main-col">

            <div class="card">

                <div class="section-title"><i class="fas fa-history"></i> Appointment History</div>

                <div style="overflow-x: auto;">

                    <table>

                        <thead>

                            <tr>

                                <th>Date & Time</th>

                                <th>Service Type</th>

                                <th>Pet Name</th>

                                <th>Status</th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php if(count($bookings) > 0): ?>

                                <?php foreach($bookings as $book): 

                                    // Setup color coding for status

                                    $status = $book['booking_status'] ?? 'Pending';

                                    $s_class = '';

                                    if ($status == 'Cancelled') $s_class = 'st-cancelled';

                                    elseif ($status == 'Completed') $s_class = 'st-completed';

                                    elseif ($status == 'Confirmed') $s_class = 'st-confirmed';

                                    elseif ($status == 'No-Show') $s_class = 'st-no-show';

                                ?>

                                    <tr>

                                        <td>

                                            <div style="font-weight: 700; color: var(--brand-blue);">

                                                <?php echo isset($book['appointment_date']) ? date('M d, Y', strtotime($book['appointment_date'])) : 'N/A'; ?>

                                            </div>

                                            <div style="font-size: 11px; color: var(--text-muted); margin-top: 3px;">

                                                <i class="far fa-clock"></i> <?php echo htmlspecialchars((string)($book['appointment_time'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?>

                                            </div>

                                        </td>

                                        <td>

                                            <strong style="color: var(--text-main);"><?php echo htmlspecialchars((string)($book['service'] ?? 'General'), ENT_QUOTES, 'UTF-8'); ?></strong>

                                        </td>

                                        <td>

                                            <?php 

                                                if (isset($book['pet_name']) && !empty($book['pet_name'])) {

                                                    echo htmlspecialchars((string)$book['pet_name'], ENT_QUOTES, 'UTF-8');

                                                } else {

                                                    echo "<span style='color: #cbd5e1; font-style: italic;'>Unknown</span>";

                                                }

                                            ?>

                                        </td>

                                        <td>

                                            <span class="status-pill <?php echo $s_class; ?>"><?php echo htmlspecialchars((string)$status, ENT_QUOTES, 'UTF-8'); ?></span>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr><td colspan="4" style="text-align: center; padding: 50px; color: var(--text-muted);">No records found.</td></tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

    </div>

    </div>
    </main>

    <script>

        function toggleMobileSidebar(event) {
            if (event) event.stopPropagation();
            const aside = document.querySelector('aside');
            const backdrop = document.getElementById('sidebarBackdrop');
            const button = document.querySelector('.mobile-menu-toggle');
            if (!aside) return;

            const open = aside.classList.toggle('mobile-open');
            if (backdrop) backdrop.classList.toggle('show', open);
            if (button) button.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        function closeMobileSidebar() {
            const aside = document.querySelector('aside');
            const backdrop = document.getElementById('sidebarBackdrop');
            const button = document.querySelector('.mobile-menu-toggle');

            if (aside) aside.classList.remove('mobile-open');
            if (backdrop) backdrop.classList.remove('show');
            if (button) button.setAttribute('aria-expanded', 'false');
        }

        function toggleNotif(event) {
            event.stopPropagation();
            const notifBox = document.getElementById('notifBox');
            const profileBox = document.getElementById('profileBox');
            if (profileBox) profileBox.classList.remove('show');
            if (notifBox) notifBox.classList.toggle('show');
        }

        function toggleProfile(event) {
            event.stopPropagation();
            const profileBox = document.getElementById('profileBox');
            const notifBox = document.getElementById('notifBox');
            if (notifBox) notifBox.classList.remove('show');
            if (profileBox) profileBox.classList.toggle('show');
        }

        document.addEventListener('click', function(event) {
            const notifBox = document.getElementById('notifBox');
            const profileBox = document.getElementById('profileBox');

            if (!event.target.closest('.notif-wrapper') && notifBox) {
                notifBox.classList.remove('show');
            }

            if (!event.target.closest('.profile-wrapper') && profileBox) {
                profileBox.classList.remove('show');
            }
        });

        window.addEventListener('resize', function() {
            if (window.innerWidth > 900) closeMobileSidebar();
        });

        function toggleEdit() {

            var display = document.getElementById('contactDisplay');

            var form = document.getElementById('contactForm');

            if (form.style.display === 'none' || form.style.display === '') {

                form.style.display = 'block';

                display.style.display = 'none';

            } else {

                form.style.display = 'none';

                display.style.display = 'block';

            }

        }

    </script>

</body>

</html>