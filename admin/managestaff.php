<?php
session_start();
include '../db_supabase.php';

// --- UNIVERSAL SECURITY CHECK ---
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';

// 1. SECURITY: Allow Admin, Manager, and Vet only
if (!isset($_SESSION['logged_in']) || !in_array($current_role, ['admin', 'manager', 'vet', 'supervisor', 'staff'], true)) {
    header("Location: stafflogin.php");
    exit();
}

// --- HANDLE DELETE ACTION ---
if (isset($_GET['delete_id'])) {
    $delete_id = (int)$_GET['delete_id'];

    // Security check: Only delete manager or vet accounts.
    // This prevents this page from deleting an admin account.
    try {
        $delete_stmt = $pdo->prepare("
            DELETE FROM users
            WHERE id = :delete_id
              AND role IN ('manager', 'vet', 'supervisor', 'staff')
        ");

        $delete_stmt->execute([':delete_id' => $delete_id]);

        if ($delete_stmt->rowCount() > 0) {
            echo "<script>alert('Personnel account deleted successfully.'); window.location.href='managestaff.php';</script>";
        } else {
            echo "<script>alert('Personnel account not found or cannot be deleted.'); window.location.href='managestaff.php';</script>";
        }
        exit();
    } catch (PDOException $e) {
        echo "<script>alert('Error deleting account.'); window.location.href='managestaff.php';</script>";
        exit();
    }
}

// 3. FETCH ADMIN PROFILE
$admin_full_name = "User";
$profile_img_path = "";
$first_name = "User";

if (isset($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];

    try {
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

            $first_name = trim(explode(' ', $admin_full_name)[0], ',');
        }
    } catch (PDOException $e) {
        $admin_full_name = $_SESSION['user_name'] ?? 'User';
        $first_name = trim(explode(' ', $admin_full_name)[0], ',');
    }
}

// 4. FETCH STATISTICS (Manager, Vet)
try {
    $total_staff_stmt = $pdo->query("
        SELECT COUNT(*) AS count
        FROM users
        WHERE role IN ('manager', 'vet', 'supervisor', 'staff')
    ");
    $total_staff = (int)($total_staff_stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0);
} catch (PDOException $e) {
    $total_staff = 0;
}

// --- FETCH ADMIN NOTIFICATIONS ---
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
    $admin_notifications = [];
    $unread_count = 0;
}

// 5. FETCH PERSONNEL LIST WITH SEARCH
$search = trim($_GET['search'] ?? '');

try {
    $query = "
        SELECT *
        FROM users
        WHERE role IN ('admin', 'manager', 'vet', 'supervisor', 'staff')
    ";

    $params = [];

    if ($search !== '') {
        $query .= "
            AND (
                full_name ILIKE :search
                OR username ILIKE :search
            )
        ";
        $params[':search'] = '%' . $search . '%';
    }

    $query .= " ORDER BY full_name ASC";

    $staff_stmt = $pdo->prepare($query);
    $staff_stmt->execute($params);
    $vet_list = $staff_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $vet_list = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Personnel Management | Boogie's Pet Care</title>
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
        main { margin-left: var(--sidebar-width); flex-grow: 1; display: flex; flex-direction: column; min-height: 100vh; }
        .top-bar { background-color: var(--white); height: 70px; padding: 0 40px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 10px rgba(0,0,0,0.08); position: sticky; top: 0; z-index: 1000; }
        .breadcrumb { font-weight: 700; color: var(--navy-dark); font-size: 15px; display: flex; align-items: center; gap: 8px; }

        /* Fixed Admin Tag */
        .admin-tag { background: var(--navy-dark); color: var(--brand-yellow); padding: 6px 16px; border-radius: 50px; font-size: 10px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; border: 1px solid var(--brand-yellow); }

        /* --- NOTIFICATIONS --- */
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

        .container { padding: 40px; flex-grow: 1; }

        .page-header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 30px; }
        .page-header h1 { font-size: 26px; color: var(--navy-dark); font-weight: 800;}
        .page-header p { color: var(--text-muted); font-size: 14px; margin-top: 5px; font-weight: 500;}
        
        .btn-add-staff { background-color: var(--navy-dark); color: var(--brand-yellow); text-decoration: none; padding: 12px 24px; border-radius: 8px; font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 10px; cursor: pointer; border:none; transition: 0.2s; box-shadow: 0 4px 6px rgba(0,0,0,0.1);}
        .btn-add-staff:hover { transform: translateY(-2px); box-shadow: 0 6px 12px rgba(0,0,0,0.15); opacity: 0.9;}

        .status-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 30px; }
        .status-card { padding: 25px; border-radius: 16px; color: white; display: flex; flex-direction: column; gap: 10px; position: relative; border-left: 4px solid var(--navy-dark); box-shadow: 0 4px 6px rgba(0,0,0,0.03); transition: transform 0.2s;}
        .status-card:hover { transform: translateY(-4px); box-shadow: 0 10px 20px rgba(0,0,0,0.1); border-left-color: var(--brand-yellow);}
        .status-card.total { background: linear-gradient(135deg, var(--navy-dark), #003366); }
        .status-card.info { background: linear-gradient(135deg, #10b981, #059669); }
        .status-card .count { font-size: 32px; font-weight: 800; }
        .status-card .label { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;}
        .status-card i { position: absolute; right: 25px; top: 25px; font-size: 30px; opacity: 0.2; }

        .filter-container { background: var(--white); padding: 15px 25px; border-radius: 12px; display: flex; align-items: center; gap: 15px; margin-bottom: 25px; border: 1px solid #f1f5f9; box-shadow: 0 2px 4px rgba(0,0,0,0.02);}
        .search-input { border: none; outline: none; flex-grow: 1; font-size: 14px; background: transparent; width: 100%; font-family: 'Poppins', sans-serif;}

        .staff-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; }
        .staff-card { background: var(--white); padding: 25px; border-radius: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); display: flex; align-items: center; gap: 15px; border: 1px solid transparent; transition: transform 0.2s; position: relative; border-left: 4px solid var(--navy-dark); }
        .staff-card:hover { transform: translateY(-4px); border-color: #e2e8f0; border-left-color: var(--brand-yellow); box-shadow: 0 10px 20px rgba(0,0,0,0.08);}
        .staff-avatar { width: 50px; height: 50px; background: #f1f5f9; color: var(--navy-dark); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; }
        .staff-info h3 { font-size: 16px; color: var(--navy-dark); margin-bottom: 4px; padding-right: 30px; font-weight: 700;} 
        .staff-info .position { font-size: 10px; font-weight: 800; text-transform: uppercase; color: #0284c7; background: #e0f2fe; padding: 3px 8px; border-radius: 4px; display: inline-block; margin-bottom: 8px; letter-spacing: 0.5px;}
        .info-item { display: flex; align-items: center; gap: 8px; color: var(--text-muted); font-size: 12px; margin-bottom: 3px; font-weight: 500;}

        .btn-delete-staff { position: absolute; top: 15px; right: 15px; color: #cbd5e1; font-size: 15px; text-decoration: none; transition: 0.2s; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; border-radius: 50%; background: #f8fafc; }
        .btn-delete-staff:hover { color: #ef4444; background: #fee2e2; }

        /* Modal Updates */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 31, 63, 0.6); z-index: 2000; justify-content: center; align-items: center; }
        .modal-box { background: white; width: 100%; max-width: 550px; padding: 30px; border-radius: 20px; box-shadow: 0 10px 25px rgba(0,0,0,0.2);}
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .modal-header h2 { color: var(--navy-dark); font-weight: 800; font-size: 20px; }
        .close-btn { background: none; border: none; font-size: 28px; cursor: pointer; color: #94a3b8; transition: 0.2s;}
        .close-btn:hover { color: #ef4444; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 5px; color: var(--navy-dark); }
        .form-group input, .form-group select { width: 100%; padding: 12px; border: 1px solid #e2e8f0; border-radius: 8px; outline: none; background-color: #f8fafc; font-family: 'Poppins', sans-serif; transition: 0.2s;}
        .form-group input:focus, .form-group select:focus { background-color: #fff; border-color: var(--navy-dark); box-shadow: 0 0 0 3px rgba(0, 31, 63, 0.1); }
        input[readonly] { background-color: #f1f5f9; color: #94a3b8; cursor: not-allowed; font-weight: 600; }
        .btn-save { background: var(--navy-dark); color: var(--brand-yellow); border: none; padding: 14px; border-radius: 10px; font-weight: 700; cursor: pointer; width: 100%; margin-top: 10px; font-size: 14px; transition: 0.2s; font-family: 'Poppins', sans-serif;}
        .btn-save:hover { opacity: 0.9; transform: translateY(-2px); box-shadow: 0 4px 10px rgba(0,0,0,0.1); }

        footer { text-align: center; padding: 40px; color: var(--text-muted); font-size: 12px; margin-top: auto; border-top: 1px solid rgba(0,0,0,0.05);}


        /* ===== FLEXIBLE PERSONNEL + MOBILE RESPONSIVENESS ===== */
        .topbar-left { display:flex; align-items:center; gap:12px; min-width:0; }
        .mobile-menu-toggle {
            display:none; border:0; background:var(--navy-dark); color:var(--brand-yellow);
            width:40px; height:40px; border-radius:10px; cursor:pointer; font-size:18px;
            align-items:center; justify-content:center; flex:0 0 auto;
        }
        .sidebar-backdrop { display:none; }
        .staff-info { min-width:0; flex:1; }
        .staff-info h3, .staff-info .info-item span, .staff-info .position { overflow-wrap:anywhere; }

        @media (max-width: 1200px) {
            .container { padding:30px; }
            .staff-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        @media (max-width: 900px) {
            html, body { width:100%; min-width:100%; overflow-x:hidden; }
            body { display:block; }
            aside {
                transform:translateX(-100%); transition:transform .25s ease;
                box-shadow:12px 0 30px rgba(0,0,0,.18); z-index:2000;
            }
            aside.mobile-open { transform:translateX(0); }
            .sidebar-backdrop { position:fixed; inset:0; background:rgba(0,31,63,.48); z-index:1900; }
            .sidebar-backdrop.show { display:block; }
            main { margin-left:0; width:100%; max-width:100%; min-width:0; }
            .top-bar { width:100%; height:64px; padding:0 14px; gap:10px; }
            .topbar-left { flex:1 1 auto; min-width:0; }
            .mobile-menu-toggle { display:inline-flex; }
            .breadcrumb { min-width:0; font-size:13px; gap:6px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
            .top-right-actions { gap:10px; min-width:0; margin-left:auto; flex:0 0 auto; }
            .notif-wrapper > i { font-size:20px !important; }
            .profile-wrapper { gap:8px; padding-left:10px; flex:0 0 auto; }
            .profile-wrapper > span:last-of-type { max-width:110px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:12px !important; }
            .admin-tag { padding:5px 10px; font-size:9px; }
            .top-avatar, .top-avatar-fallback { width:32px; height:32px; }
            .notif-dropdown { width:min(320px,calc(100vw - 28px)); right:-6px; }
            .container { padding:22px 16px; }
            .page-header { align-items:flex-start; gap:16px; flex-direction:column; margin-bottom:22px; }
            .page-header h1 { font-size:22px; }
            .page-header p { font-size:12px; line-height:1.5; }
            .btn-add-staff { width:100%; justify-content:center; min-height:44px; }
            .status-grid { grid-template-columns:1fr; gap:12px; margin-bottom:18px; }
            .status-card { padding:18px; border-radius:14px; }
            .status-card .count { font-size:26px; }
            .filter-container { padding:11px 13px; margin-bottom:18px; }
            .search-input { font-size:13px; min-width:0; }
            .staff-grid { grid-template-columns:1fr; gap:12px; }
            .staff-card { padding:16px 14px; border-radius:13px; gap:12px; align-items:flex-start; }
            .staff-avatar { width:42px; height:42px; min-width:42px; border-radius:10px; font-size:17px; }
            .staff-info .position { font-size:9px; margin-bottom:5px; max-width:100%; }
            .staff-info h3 { font-size:14px; line-height:1.3; margin-bottom:5px; padding-right:30px; }
            .staff-info .info-item { font-size:11px; gap:6px; margin-bottom:2px; }
            .btn-delete-staff { top:10px; right:10px; width:28px; height:28px; font-size:13px; }
            footer { padding:24px 12px; font-size:10px; }
            .modal-overlay { padding:12px; align-items:center; overflow-y:auto; }
            .modal-box { width:100%; max-width:520px; max-height:calc(100dvh - 24px); overflow-y:auto; padding:20px 16px; border-radius:16px; }
            .modal-header { margin-bottom:16px; }
            .modal-header h2 { font-size:18px; }
            .form-row { grid-template-columns:1fr; gap:0; }
            .form-group { margin-bottom:12px; }
            .form-group label { font-size:12px; }
            .form-group input, .form-group select { min-height:44px; padding:10px 11px; font-size:13px; }
            .btn-save { min-height:45px; margin-top:5px; }
        }

        @media (max-width: 560px) {
            .top-bar { height:58px; padding:0 8px; }
            .topbar-left { gap:7px; }
            .mobile-menu-toggle { width:36px; height:36px; border-radius:9px; font-size:16px; }
            .breadcrumb { font-size:11px; }
            .top-right-actions { gap:5px; }
            .profile-wrapper { gap:5px; padding-left:6px; }
            .profile-wrapper > span:last-of-type { display:none !important; }
            .admin-tag { padding:4px 7px; font-size:7px; }
            .top-avatar, .top-avatar-fallback { width:29px; height:29px; }
            .container { padding:16px 12px 22px; }
            .page-header h1 { font-size:20px; }
            .status-card { padding:16px; }
            .staff-card { padding:14px 12px; }
            .modal-box { padding:18px 13px; }
        }

    </style>
</head>
<body>

    <aside>
        <div class="sidebar-header">
            <img src="bg.png" alt="Boogie's Logo" class="sidebar-logo">
            <h2>
                <?php echo (isset($_SESSION['role']) && strtolower($_SESSION['role']) === 'admin') ? "Boogie's Admin" : "Boogie's Staff"; ?>
            </h2>
        </div>
        <nav class="nav-links">
            <a href="admindashboard.php" class="nav-item"><i class="fas fa-chart-line"></i> Dashboard</a>
            <a href="managebooking.php" class="nav-item"><i class="fas fa-calendar-alt"></i> Bookings</a>
            <a href="manageusers.php" class="nav-item"><i class="fas fa-users"></i> Users</a>
            <a href="managepet.php" class="nav-item"><i class="fas fa-dog"></i> Pets</a>

            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                <a href="managestaff.php" class="nav-item active"><i class="fas fa-id-badge"></i> Personnel</a>
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
                <button class="mobile-menu-toggle" type="button" aria-label="Open admin menu" aria-expanded="false" onclick="toggleMobileSidebar(event)">☰</button>
            <div class="breadcrumb">
                <i class="fas fa-id-badge" style="opacity: 0.5; font-size: 14px;"></i> 
                Management / Personnel
            </div>
            </div>
            <div class="top-right-actions">
                <div class="notif-wrapper" onclick="toggleNotif(event)">
                    <i class="fa-solid fa-bell" style="font-size: 22px; color: #64748b;"></i>
                    
                    <?php if($unread_count > 0): ?>
                        <span id="admin-notif-badge" class="notif-badge"><?php echo $unread_count; ?></span>
                    <?php else: ?>
                        <span id="admin-notif-badge" class="notif-badge" style="display: none;">0</span>
                    <?php endif; ?>
                    
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
                                        <?php echo htmlspecialchars($notif['message']); ?>
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
                    <span class="admin-tag"><?php echo strtoupper($current_role); ?></span>
                    
                    <?php if (!empty($profile_img_path) && file_exists($profile_img_path)): ?>
                        <img src="<?php echo htmlspecialchars($profile_img_path); ?>" class="top-avatar" alt="Profile Picture">
                    <?php else: ?>
                        <div class="top-avatar-fallback"><?php echo strtoupper(substr($first_name, 0, 1)); ?></div>
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
                <div>
                    <h1>Personnel Management</h1>
                    <p>Manage personnel accounts, system access, and assigned positions.</p>
                </div>
                <button class="btn-add-staff" onclick="openStaffModal()">
                    <i class="fas fa-plus"></i> Add Personnel
                </button>
            </div>

            <div class="status-grid">
                <div class="status-card total">
                    <i class="fas fa-users"></i>
                    <span class="label">Total Personnel</span>
                    <div class="count"><?php echo $total_staff; ?></div>
                </div>
                <div class="status-card info">
                    <i class="fas fa-check-shield"></i>
                    <span class="label">System Access</span>
                    <div class="count">Verified</div>
                </div>
            </div>

            <form action="" method="GET" class="filter-container">
                <i class="fas fa-search"></i>
                <input type="text" name="search" class="search-input" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search name or username...">
                <button type="submit" style="display:none;"></button>
            </form>

            <?php if (!empty($vet_list)): ?>
                <div class="staff-grid">
                    <?php foreach ($vet_list as $staff): ?>
                        <div class="staff-card">
                            
                            <?php if (strtolower((string)($staff['role'] ?? '')) !== 'admin'): ?>
                                <a href="managestaff.php?delete_id=<?php echo $staff['id']; ?>" class="btn-delete-staff" title="Delete Account" onclick="return confirm('Are you sure you want to delete <?php echo htmlspecialchars(addslashes($staff['full_name'])); ?>? This cannot be undone.');">
                                    <i class="fas fa-trash"></i>
                                </a>
                            <?php endif; ?>

                            <div class="staff-avatar">
                                <i class="<?php
                                    $role_icon = match (strtolower((string)($staff['role'] ?? ''))) {
                                        'vet' => 'fas fa-user-md',
                                        'staff' => 'fas fa-user',
                                        'supervisor' => 'fas fa-user-shield',
                                        'admin' => 'fas fa-user-gear',
                                        default => 'fas fa-user-tie'
                                    };
                                    echo $role_icon;
                                ?>"></i>
                            </div>
                            <div class="staff-info">
                                <span class="position"><?php echo htmlspecialchars($staff['position'] ?? 'Personnel'); ?></span>
                                <h3><?php echo htmlspecialchars($staff['full_name']); ?></h3>
                                <div class="info-item"><i class="fas fa-shield-halved"></i><span>Access: <?php echo htmlspecialchars(strtoupper($staff['role'] ?? 'STAFF')); ?></span></div>
                                <div class="info-item">
                                    <i class="fas fa-user-tag"></i>
                                    <span><?php echo htmlspecialchars($staff['username'] ?? 'No Username'); ?></span>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-fingerprint"></i>
                                    <span style="font-family: monospace; font-weight: 600;">ID: ACC-0<?php echo $staff['id']; ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 80px; background: white; border-radius: 16px; border: 1px dashed #cbd5e1; box-shadow: 0 4px 6px rgba(0,0,0,0.03);">
                    <i class="fas fa-search" style="font-size: 40px; color: #cbd5e1; margin-bottom: 15px;"></i>
                    <p style="color: #64748b; font-weight: 500;">No personnel found.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <footer>
            © <?php echo date("Y"); ?> BOOGIE'S PET CARE & SERVICES - DASMARIÑAS BRANCH
        </footer>
    </main>

    <div id="addStaffModal" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-header">
                <h2>New Personnel Account</h2>
                <button class="close-btn" onclick="closeStaffModal()">&times;</button>
            </div>
            <form action="addstaff.php" method="POST">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="full_name" required placeholder="e.g., Juan Dela Cruz">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" name="username" required placeholder="Enter username">
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>System Role / Access</label>
                        <select name="role" required>
                            <option value="staff" selected>Staff</option>
                            <option value="admin">Admin</option>
                            <option value="manager">Manager</option>
                            <option value="supervisor">Supervisor</option>
                            <option value="vet">Veterinarian</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Position / Assignment</label>
                        <input type="text" name="position" list="personnelPositions" required placeholder="e.g. Vet Nurse">
                        <datalist id="personnelPositions">
                            <option value="Administrator">
                            <option value="Shop Manager">
                            <option value="Supervisor">
                            <option value="Front Desk / Receptionist">
                            <option value="Veterinarian">
                            <option value="Vet Assistant">
                            <option value="Vet Nurse">
                            <option value="Groomer">
                            <option value="Pet Hotel Staff / Attendant">
                        </datalist>
                    </div>
                </div>
                <div style="font-size:11px; color:#64748b; margin-top:-4px; margin-bottom:8px; line-height:1.5;">System access controls what the account can open. Position describes the person's actual job and can be customized.</div>
                
                <button type="submit" class="btn-save">Create Account</button>
            </form>
        </div>
    </div>

    <script>
        function openStaffModal() { document.getElementById('addStaffModal').style.display = 'flex'; }
        function closeStaffModal() { document.getElementById('addStaffModal').style.display = 'none'; }
        
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
            if (event.target == document.getElementById('addStaffModal')) {
                closeStaffModal();
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

    </script>
</body>
</html>