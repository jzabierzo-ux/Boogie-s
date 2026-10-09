<?php
session_start();

// Admin-only authorization.
$current_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $current_role !== 'admin') {
    header('Location: ../admin_login.php');
    exit();
}

require_once '../db_supabase.php';

// CSRF token used by all state-changing service actions.
if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function redirectWithServiceFlash(string $type, string $message): void
{
    $_SESSION['manage_services_flash'] = [
        'type' => $type === 'success' ? 'success' : 'error',
        'text' => $message,
    ];
    header('Location: manage_services.php');
    exit();
}

// Show one-time feedback following a POST/redirect/GET cycle.
$flash_message = $_SESSION['manage_services_flash'] ?? null;
unset($_SESSION['manage_services_flash']);

// Shared Admin header data.
$admin_full_name = 'Administrator';
$profile_img_path = '';
$first_name = 'Administrator';
$admin_notifications = [];
$unread_count = 0;

if (isset($_SESSION['user_id'])) {
    try {
        $get_admin = $pdo->prepare(
            'SELECT full_name, profile_image FROM users WHERE id = :id LIMIT 1'
        );
        $get_admin->execute([':id' => (int)$_SESSION['user_id']]);
        $admin_data = $get_admin->fetch(PDO::FETCH_ASSOC);

        if ($admin_data) {
            $admin_full_name = (string)($admin_data['full_name'] ?? 'Administrator');
            $profile_img_path = (string)($admin_data['profile_image'] ?? '');
            $_SESSION['user_name'] = $admin_full_name;
            $first_name = trim(explode(' ', $admin_full_name)[0], ',');
        } else {
            $admin_full_name = (string)($_SESSION['user_name'] ?? 'Administrator');
            $first_name = trim(explode(' ', $admin_full_name)[0], ',');
        }
    } catch (PDOException $e) {
        error_log('Manage services profile lookup failed: ' . $e->getMessage());
        $admin_full_name = (string)($_SESSION['user_name'] ?? 'Administrator');
        $first_name = trim(explode(' ', $admin_full_name)[0], ',');
    }
}

try {
    $admin_notif_stmt = $pdo->prepare(
        'SELECT id, message, created_at FROM admin_notifications WHERE is_read = 0 ORDER BY created_at DESC'
    );
    $admin_notif_stmt->execute();
    $admin_notifications = $admin_notif_stmt->fetchAll(PDO::FETCH_ASSOC);
    $unread_count = count($admin_notifications);
} catch (PDOException $e) {
    error_log('Manage services notification lookup failed: ' . $e->getMessage());
    $admin_notifications = [];
    $unread_count = 0;
}

// All changes must be submitted through POST with a valid CSRF token.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_token = $_POST['csrf_token'] ?? '';
    if (!is_string($posted_token) || !hash_equals($csrf_token, $posted_token)) {
        http_response_code(403);
        exit('Invalid security token. Refresh the page and try again.');
    }

    // Delete a service using POST, never a query-string GET request.
    if (isset($_POST['delete_service'])) {
        $service_id = filter_var($_POST['delete_id'] ?? null, FILTER_VALIDATE_INT);
        if ($service_id === false || $service_id === null || $service_id <= 0) {
            redirectWithServiceFlash('error', 'Invalid service selected.');
        }

        try {
            $delete_stmt = $pdo->prepare('DELETE FROM services_pricelist WHERE id = :id');
            $delete_stmt->execute([':id' => $service_id]);
            if ($delete_stmt->rowCount() !== 1) {
                redirectWithServiceFlash('error', 'Service not found or already deleted.');
            }
            redirectWithServiceFlash('success', 'Service deleted successfully.');
        } catch (PDOException $e) {
            error_log('Delete service error: ' . $e->getMessage());
            redirectWithServiceFlash('error', 'Unable to delete this service. Please check whether it is used by existing bookings.');
        }
    }

    // Add or update a service.
    if (isset($_POST['save_service'])) {
        $category = trim((string)($_POST['category'] ?? ''));
        $service_name = trim((string)($_POST['service_name'] ?? ''));
        $price_raw = trim((string)($_POST['price'] ?? ''));
        $available_raw = (string)($_POST['is_available'] ?? '');
        $service_id_raw = trim((string)($_POST['service_id'] ?? ''));
        $service_id = null;

        if ($service_id_raw !== '') {
            $validated_id = filter_var($service_id_raw, FILTER_VALIDATE_INT);
            if ($validated_id === false || $validated_id <= 0) {
                redirectWithServiceFlash('error', 'Invalid service selected for editing.');
            }
            $service_id = $validated_id;
        }

        if ($category === '' || $service_name === '' || $price_raw === '') {
            redirectWithServiceFlash('error', 'Please complete all required service fields.');
        }
        if (!is_numeric($price_raw) || !is_finite((float)$price_raw) || (float)$price_raw < 0) {
            redirectWithServiceFlash('error', 'Enter a valid price of zero or greater.');
        }
        if (!in_array($available_raw, ['0', '1'], true)) {
            redirectWithServiceFlash('error', 'Select a valid service visibility option.');
        }

        // The Supabase schema may store is_available as a smallint, so bind 0/1.
        $available = (int)$available_raw;
        try {
            if ($service_id !== null) {
                $sql = 'UPDATE services_pricelist
                        SET category = :category,
                            service_name = :service_name,
                            price = :price,
                            is_available = :is_available
                        WHERE id = :id';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':category' => $category,
                    ':service_name' => $service_name,
                    ':price' => (float)$price_raw,
                    ':is_available' => $available,
                    ':id' => $service_id,
                ]);
                if ($stmt->rowCount() !== 1) {
                    redirectWithServiceFlash('error', 'Service not found or no longer available.');
                }
                redirectWithServiceFlash('success', 'Service details updated successfully.');
            }

            $sql = 'INSERT INTO services_pricelist (category, service_name, price, is_available)
                    VALUES (:category, :service_name, :price, :is_available)';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':category' => $category,
                ':service_name' => $service_name,
                ':price' => (float)$price_raw,
                ':is_available' => $available,
            ]);
            redirectWithServiceFlash('success', 'Service added successfully.');
        } catch (PDOException $e) {
            error_log('Save service error: ' . $e->getMessage());
            redirectWithServiceFlash('error', 'Unable to save the service. Please check the service details and database logs.');
        }
    }

    http_response_code(400);
    exit('Invalid service action.');
}

// Fetch services for the table.
$services = [];
try {
    $services_stmt = $pdo->query('SELECT * FROM services_pricelist ORDER BY category ASC, id ASC');
    $services = $services_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Fetch services error: ' . $e->getMessage());
    $flash_message = [
        'type' => 'error',
        'text' => 'Unable to load service pricing right now. Please check the application logs.',
    ];
}
?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Manage Services | Boogie's Pet Care</title>

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

        main { margin-left: var(--sidebar-width); flex-grow: 1; display: flex; flex-direction: column; }

        .top-bar { background-color: var(--white); height: 70px; padding: 0 40px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 10px rgba(0,0,0,0.08); position: sticky; top: 0; z-index: 1000; }

        .breadcrumb { font-weight: 700; color: var(--navy-dark); font-size: 15px; display: flex; align-items: center; gap: 8px; }

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

        .admin-tag { background: var(--navy-dark); color: var(--brand-yellow); padding: 6px 16px; border-radius: 50px; font-size: 10px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; border: 1px solid var(--brand-yellow); }

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

        /* --- TABLE & CONTENT --- */

        .container { padding: 40px; flex-grow: 1; }

        .data-box { background: var(--white); border-radius: 16px; padding: 30px; box-shadow: 0 4px 6px rgba(0,0,0,0.03); border: 1px solid #f1f5f9;}

        .flash-message { padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; font-size: 13px; font-weight: 600; line-height: 1.45; }
        .flash-message.success { color: #166534; background: #dcfce7; border: 1px solid #bbf7d0; }
        .flash-message.error { color: #991b1b; background: #fee2e2; border: 1px solid #fecaca; }
        .action-group form { display: inline-flex; margin: 0; }
        .btn-icon { cursor: pointer; }

        .admin-table { width: 100%; border-collapse: collapse; margin-top: 20px; }

        .admin-table th { text-align: left; padding: 15px; border-bottom: 2px solid #edf2f7; color: var(--text-muted); font-size: 12px; text-transform: uppercase; font-weight: 700;}

        .admin-table td { padding: 15px; border-bottom: 1px solid #f1f5f9; font-size: 14px; color: var(--navy-dark); vertical-align: middle;}

        .admin-table tr:hover td { background-color: #f8fafc; }

        .status-pill { padding: 6px 14px; border-radius: 20px; font-size: 10px; font-weight: 800; text-transform: uppercase; box-shadow: inset 0 0 0 1px rgba(0,0,0,0.05); letter-spacing: 0.5px;}

        .available { background: #dcfce7; color: #166534; }

        .hidden { background: #fee2e2; color: #991b1b; }

        .btn-add { background: var(--navy-dark); color: var(--brand-yellow); padding: 12px 24px; border: none; border-radius: 8px; cursor: pointer; font-weight: 700; font-size: 14px; transition: 0.2s; font-family: 'Poppins', sans-serif; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);}

        .btn-add:hover { transform: translateY(-2px); box-shadow: 0 6px 12px rgba(0,0,0,0.15); opacity: 0.95; }

        /* Modern Action Buttons */

        .action-group { display: flex; gap: 8px; }

        .btn-icon { width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; text-decoration: none; transition: all 0.2s ease; font-size: 14px; cursor: pointer; border: none;}

        .btn-edit { background: #f1f5f9; color: var(--navy-dark); }

        .btn-edit:hover { background: var(--brand-yellow); color: var(--navy-dark); }

        .btn-delete { background: #fee2e2; color: #dc2626; }

        .btn-delete:hover { background: #dc2626; color: white; }

        /* --- MODAL --- */

        .modal { position: fixed; z-index: 1001; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,31,63,0.6); display: none; align-items: center; justify-content: center; }

        .modal-content { background: white; padding: 35px; width: 100%; max-width: 450px; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.2);}

        .modal-content label { display: block; font-size: 13px; font-weight: 700; color: var(--navy-dark); margin-bottom: 8px; }

        .modal-content input, .modal-content select { width: 100%; padding: 12px 15px; margin-bottom: 20px; border: 1px solid #e2e8f0; border-radius: 8px; outline: none; font-family: 'Poppins', sans-serif; font-size: 14px; background: #f8fafc; transition: 0.2s;}

        .modal-content input:focus, .modal-content select:focus { background: white; border-color: var(--navy-dark); box-shadow: 0 0 0 3px rgba(0,31,63,0.1);}

        .btn-save { background: var(--navy-dark); color: var(--brand-yellow); width: 100%; padding: 14px; border: none; border-radius: 8px; cursor: pointer; font-weight: 700; transition: 0.2s; font-family: 'Poppins', sans-serif; font-size: 14px; margin-top: 10px;}

        .btn-save:hover { opacity: 0.95; transform: translateY(-2px); box-shadow: 0 4px 10px rgba(0,0,0,0.1);}

        footer { text-align: center; padding: 40px; color: var(--text-muted); font-size: 12px; border-top: 1px solid rgba(0,0,0,0.05);}

        /* ===== RESPONSIVE ADMIN LAYOUT — same responsive pattern as managepet.php ===== */
        .topbar-left { display:flex; align-items:center; gap:12px; min-width:0; }
        .mobile-menu-toggle {
            display:none; border:0; background:var(--navy-dark); color:var(--brand-yellow);
            width:40px; height:40px; border-radius:10px; cursor:pointer; font-size:18px;
            align-items:center; justify-content:center; flex:0 0 auto;
        }
        .sidebar-backdrop { display:none; }

        @media (max-width: 1200px) {
            .container { padding:30px; }
            .data-box { padding:24px; }
        }

        @media (max-width: 900px) {
            body { overflow-x:hidden; }
            aside {
                transform:translateX(-100%); transition:transform .25s ease;
                box-shadow:12px 0 30px rgba(0,0,0,.18); z-index:2000;
            }
            aside.mobile-open { transform:translateX(0); }
            .sidebar-backdrop {
                position:fixed; inset:0; background:rgba(0,31,63,.48); z-index:1900;
            }
            .sidebar-backdrop.show { display:block; }
            main { margin-left:0; width:100%; min-width:0; }
            .top-bar { height:60px; padding:0 10px; gap:8px; }
            .mobile-menu-toggle { display:inline-flex; }
            .breadcrumb {
                font-size:12px; gap:6px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
            }
            .top-right-actions { gap:8px; margin-left:auto; flex:0 0 auto; }
            .profile-wrapper { gap:7px; padding-left:8px; }
            .profile-wrapper > span:last-of-type {
                max-width:100px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
                font-size:12px !important;
            }
            .admin-tag { padding:5px 9px; font-size:8px; }
            .top-avatar, .top-avatar-fallback { width:30px; height:30px; }
            .notif-dropdown { width:min(320px,calc(100vw - 20px)); right:-4px; }
            .container { padding:18px 14px 24px; }
            .data-box { padding:18px; border-radius:14px; overflow:hidden; }
            .service-page-head {
                flex-direction:column; align-items:stretch !important; gap:12px;
                margin-bottom:18px !important;
            }
            .service-page-head h3 { font-size:20px !important; }
            .service-page-head p { font-size:12px !important; line-height:1.5; }
            .btn-add { width:100%; justify-content:center; min-height:44px; }
            .admin-table { min-width:760px; margin-top:12px; }
            .table-scroll-mobile { overflow-x:auto !important; -webkit-overflow-scrolling:touch; }
            .modal { padding:12px; }
            .modal-content {
                width:min(450px,100%); max-height:calc(100dvh - 24px); overflow-y:auto;
                padding:22px 18px; border-radius:14px;
            }
        }

        @media (max-width:560px) {
            .top-bar { height:58px; padding:0 8px; }
            .topbar-left { gap:7px; }
            .mobile-menu-toggle { width:36px; height:36px; border-radius:9px; font-size:16px; }
            .breadcrumb { font-size:11px; }
            .top-right-actions { gap:5px; }
            .notif-wrapper > i { font-size:19px !important; }
            .profile-wrapper { gap:5px; padding-left:6px; }
            .profile-wrapper > span:last-of-type { display:none !important; }
            .admin-tag { padding:4px 7px; font-size:7px; }
            .top-avatar, .top-avatar-fallback { width:29px; height:29px; }
            .container { padding:16px 12px 22px; }
            .data-box { padding:16px 12px; }
            .service-page-head h3 { font-size:19px !important; }
            .service-page-head p { font-size:11px !important; }
            .modal-content { padding:20px 16px; }
        }

</style>

</head>

<body>

    <aside>

        <div class="sidebar-header">

            <img src="bg.png" alt="Boogie's Logo" class="sidebar-logo">

            <h2>Boogie's Admin</h2>

        </div>

        <nav class="nav-links">

            <a href="admindashboard.php" class="nav-item"><i class="fas fa-chart-line"></i> Dashboard</a>

            <a href="managebooking.php" class="nav-item"><i class="fas fa-calendar-alt"></i> Bookings</a>

            <a href="manageusers.php" class="nav-item"><i class="fas fa-users"></i> Users</a>

            <a href="managepet.php" class="nav-item"><i class="fas fa-dog"></i> Pets</a>

            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>

                <a href="managestaff.php" class="nav-item"><i class="fas fa-id-badge"></i> Personnel</a>

                <a href="managepromo.php" class="nav-item"><i class="fas fa-tags"></i> Promos</a>

                <a href="manage_services.php" class="nav-item active"><i class="fas fa-list-ul"></i> Pricelist</a>

                <a href="sales_report.php" class="nav-item"><i class="fas fa-file-invoice-dollar"></i> Sales Report</a>

                <a href="admin_account_logs.php" class="nav-item"><i class="fa-solid fa-clock-rotate-left"></i> Account Logs</a>

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

                <i class="fas fa-list-ul" style="opacity: 0.5; font-size: 14px;"></i>

                Management / Pricelist

            </div>

            </div>

<div class="top-right-actions">

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

                                        <?php echo htmlspecialchars((string)($notif['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>

                                        <br><small style="color: #94a3b8; font-size: 11px;"><?php echo date('M d, g:i A', strtotime($notif['created_at'])); ?></small>

                                    </div>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <div class="notif-empty">No new notifications.</div>

                            <?php endif; ?>

                        </div>

                    </div>

                </div>

                <div class="profile-wrapper" onclick="toggleProfile(event)">

                    <span class="admin-tag"><?php echo strtoupper($_SESSION['role'] ?? 'ADMIN'); ?></span>

                    <?php if (!empty($profile_img_path) && file_exists($profile_img_path)): ?>

                        <img src="<?php echo htmlspecialchars($profile_img_path, ENT_QUOTES, 'UTF-8'); ?>" class="top-avatar" alt="Profile Picture">

                    <?php else: ?>

                        <div class="top-avatar-fallback"><?php echo strtoupper(substr($first_name, 0, 1)); ?></div>

                    <?php endif; ?>

                    <span style="font-size: 14px; font-weight: 600; color: #4a5568; display: flex; align-items: center; gap: 6px;">

                        <?php echo htmlspecialchars($admin_full_name, ENT_QUOTES, 'UTF-8'); ?>

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

            <div class="data-box">

                <?php if (is_array($flash_message) && !empty($flash_message['text'])): ?>
                    <div class="flash-message <?php echo ($flash_message['type'] ?? 'error') === 'success' ? 'success' : 'error'; ?>" role="status">
                        <i class="fas <?php echo ($flash_message['type'] ?? 'error') === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                        <?php echo htmlspecialchars((string)$flash_message['text'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <div class="service-page-head" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; border-bottom: 2px solid #f8fafc; padding-bottom: 20px;">

                    <div>

                        <h3 style="font-size: 22px; color: var(--navy-dark); font-weight: 800; margin-bottom: 5px;">Service Pricelist</h3>

                        <p style="color: var(--text-muted); font-size: 13px; font-weight: 500;">Manage the prices and availability of your shop's services.</p>

                    </div>

                    <button class="btn-add" onclick="openAddModal()"><i class="fas fa-plus"></i> New Service</button>

                </div>

                <div class="table-scroll-mobile" style="overflow-x: auto;">

                    <table class="admin-table">

                        <thead>

                            <tr>

                                <th>Category</th>

                                <th>Service Name</th>

                                <th>Price (PHP)</th>

                                <th>Status</th>

                                <th>Actions</th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach($services as $service): ?>

                            <tr>

                                <td><span style="background: #f1f5f9; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 700; color: var(--text-muted);"><?php echo htmlspecialchars((string)$service['category'], ENT_QUOTES, 'UTF-8'); ?></span></td>

                                <td style="font-weight: 600; font-size: 15px;"><?php echo htmlspecialchars((string)$service['service_name'], ENT_QUOTES, 'UTF-8'); ?></td>

                                <td style="font-weight: 800; color: var(--navy-dark); font-size: 15px;">₱<?php echo number_format($service['price'], 2); ?></td>

                                <td>

                                    <span class="status-pill <?php echo ((string)$service['is_available'] === '1' || $service['is_available'] === true) ? 'available' : 'hidden'; ?>">

                                        <?php echo ((string)$service['is_available'] === '1' || $service['is_available'] === true) ? 'Available' : 'Hidden'; ?>

                                    </span>

                                </td>

                                <td>

                                    <div class="action-group">

                                        <button class="btn-icon btn-edit" onclick='openEditModal(<?php echo htmlspecialchars(json_encode($service, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8"); ?>)' title="Edit Price/Details"><i class="fas fa-edit"></i></button>

                                        <form method="POST" action="manage_services.php" onsubmit="return confirm('Delete this service permanently? This cannot be undone.');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="delete_id" value="<?php echo (int)$service['id']; ?>">
                                            <button type="submit" name="delete_service" value="1" class="btn-icon btn-delete" title="Delete Service" aria-label="Delete Service"><i class="fas fa-trash"></i></button>
                                        </form>

                                    </div>

                                </td>

                            </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

        <footer>

            © <?php echo date("Y"); ?> BOOGIE'S PET CARE & SERVICES - DASMARIÑAS BRANCH

        </footer>

    </main>

    <div id="serviceModal" class="modal">

        <div class="modal-content">

            <h3 id="modalTitle" style="margin-bottom: 25px; color: var(--navy-dark); font-size: 22px; font-weight: 800;">Add New Service</h3>

            <form method="POST" action="manage_services.php">

                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="service_id" id="service_id">

                <label>Category Group</label>

                <input type="text" name="category" id="cat_input" placeholder="e.g. Grooming, Pet Hotel" required>

                <label>Service Description</label>

                <input type="text" name="service_name" id="name_input" placeholder="e.g. Small Breed (1-5kg)" required>

                <label>Standard Price (PHP)</label>

                <input type="number" min="0" step="0.01" name="price" id="price_input" placeholder="0.00" required>

                <label>Customer Visibility</label>

                <select name="is_available" id="status_input">

                    <option value="1">Show in Booking Form (Available)</option>

                    <option value="0">Hide from Booking Form (Hidden)</option>

                </select>

                <button type="submit" name="save_service" class="btn-save">Save Service Details</button>

                <button type="button" onclick="closeModal()" style="width:100%; margin-top:15px; background:none; border:none; color:#94a3b8; font-weight:600; cursor:pointer; transition: 0.2s; font-family: 'Poppins', sans-serif;" onmouseover="this.style.color='#dc2626'" onmouseout="this.style.color='#94a3b8'">Cancel</button>

            </form>

        </div>

    </div>

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

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') closeMobileSidebar();
        });

        document.querySelectorAll('.nav-item').forEach(function(link) {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 900) closeMobileSidebar();
            });
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

        // Close dropdowns and modals when clicking outside

        window.onclick = function(event) {

            const modal = document.getElementById('serviceModal');

            if (event.target == modal) {

                closeModal();

            }

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

            notifList.innerHTML = (data && data.html)

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

        fetchAdminNotifs();

        setInterval(fetchAdminNotifs, 3000);

// --- MODAL LOGIC ---

        const modal = document.getElementById('serviceModal');

        function openAddModal() {

            document.getElementById('modalTitle').innerText = "Add New Service";

            document.getElementById('service_id').value = "";

            document.getElementById('cat_input').value = "";

            document.getElementById('name_input').value = "";

            document.getElementById('price_input').value = "";

            document.getElementById('status_input').value = "1";

            modal.style.display = "flex";

        }

        function openEditModal(service) {

            document.getElementById('modalTitle').innerText = "Edit Service Details";

            document.getElementById('service_id').value = service.id;

            document.getElementById('cat_input').value = service.category;

            document.getElementById('name_input').value = service.service_name;

            document.getElementById('price_input').value = service.price;

            document.getElementById('status_input').value = service.is_available;

            modal.style.display = "flex";

        }

        function closeModal() {

            modal.style.display = "none";

        }

    </script>

</body>

</html>