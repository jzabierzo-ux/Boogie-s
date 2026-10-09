<?php
session_start();
require_once '../db_supabase.php';

// --- SECURITY CHECK ---
// This page is for veterinary-team accounts only.
$current_role = isset($_SESSION['role']) ? strtolower(trim((string)$_SESSION['role'])) : '';
$vet_roles = ['vet', 'veterinarian', 'vet assistant', 'vet_assistant', 'vet nurse', 'vet_nurse'];
$is_vet = isset($_SESSION['logged_in'])
    && $_SESSION['logged_in'] === true
    && in_array($current_role, $vet_roles, true);

if (!$is_vet) {
    header("Location: stafflogin.php");
    exit();
}

// CSRF token for state-changing actions.
if (empty($_SESSION['tasks_csrf'])) {
    $_SESSION['tasks_csrf'] = bin2hex(random_bytes(32));
}
$tasks_csrf = $_SESSION['tasks_csrf'];

// SET CORRECT TIMEZONE FOR PHILIPPINES
date_default_timezone_set('Asia/Manila');

$staff_name = $_SESSION['staff_name'] ?? 'Doctor';

// --- FETCH STAFF PROFILE IMAGE & FULL NAME ---
$profile_img_path = "";
$full_display_name = $staff_name;

if (isset($_SESSION['user_id']) || isset($_SESSION['staff_id'])) {
    $uid = $_SESSION['user_id'] ?? $_SESSION['staff_id'];

    try {
        // Idinagdag ang full_name para makuha ang buong pangalan
        $get_staff = $pdo->prepare("
            SELECT full_name, profile_image
            FROM users
            WHERE id = :uid
            LIMIT 1
        ");

        $get_staff->execute([
            ':uid' => (int)$uid
        ]);

        $staff_data = $get_staff->fetch(PDO::FETCH_ASSOC);

        if ($staff_data) {
            $profile_img_path = $staff_data['profile_image'] ?? '';

            if (!empty($staff_data['full_name'])) {
                $full_display_name = $staff_data['full_name'];
            }
        }
    } catch (PDOException $e) {
        error_log("Staff profile query failed: " . $e->getMessage());
    }
}

// Linisin ang pangalan para sa Avatar Initial (Tatanggalin ang "Dr. " at comma)
$clean_name = trim(str_replace('Dr. ', '', $full_display_name), " ,");
$first_letter = strtoupper(substr($clean_name, 0, 1));

// Siguraduhing may "Dr. " na nakadikit sa buong pangalan para formal
$display_with_title = (stripos($full_display_name, 'Dr.') === false)
    ? 'Dr. ' . $full_display_name
    : $full_display_name;

// Mobile top bar: show first name only to prevent crowding.
$mobile_name_source = trim(preg_replace('/^\s*(?:Dr\.?|Dra\.?|Doc\.?)\s+/i', '', (string)$display_with_title));
$first_name_only = trim((string)(preg_split('/\s+/', $mobile_name_source)[0] ?? ''));


// Notifications are intentionally not read from admin_notifications here.
// That table and its polling endpoint belong to the Admin side; a staff-specific
// notification table/schema was not available to verify safely.
$staff_notifications = [];
$unread_count = 0;

// Profile image paths may be stored as a URL or as a path relative to the project root.
$profile_img_src = '';
$profile_img_is_remote = false;
if (is_string($profile_img_path) && trim($profile_img_path) !== '') {
    $profile_img_path = trim($profile_img_path);
    $parsed_img_url = filter_var($profile_img_path, FILTER_VALIDATE_URL) ? parse_url($profile_img_path) : false;
    $profile_img_is_remote = is_array($parsed_img_url)
        && isset($parsed_img_url['scheme'])
        && in_array(strtolower($parsed_img_url['scheme']), ['http', 'https'], true);

    if ($profile_img_is_remote) {
        $profile_img_src = $profile_img_path;
    } else {
        $normalized_img_path = str_replace('\\', '/', $profile_img_path);
        $clean_img_path = preg_replace('#^(?:\./|\.\./)+#', '', ltrim($normalized_img_path, '/'));
        $candidate_paths = [
            __DIR__ . '/' . ltrim($normalized_img_path, '/'),
            dirname(__DIR__) . '/' . $clean_img_path,
        ];
        foreach ($candidate_paths as $candidate_path) {
            if (is_file($candidate_path)) {
                if (strpos($normalized_img_path, '/') === 0) {
                    $profile_img_src = $normalized_img_path;
                } elseif (strpos($normalized_img_path, '../') === 0) {
                    $profile_img_src = $normalized_img_path;
                } else {
                    $profile_img_src = '../' . $clean_img_path;
                }
                break;
            }
        }
    }
}

// --- TASK LOGIC (Automated from Appointments - SINGLE VET CLINIC) ---

// MARK AS COMPLETED
// Use POST + CSRF and verify that the appointment is a confirmed vet service.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_task'])) {
    $id = filter_input(INPUT_POST, 'appointment_id', FILTER_VALIDATE_INT);
    $csrf = $_POST['csrf_token'] ?? '';

    if (!is_string($csrf) || !$id || !hash_equals($tasks_csrf, $csrf)) {
        http_response_code(400);
        exit('Invalid request.');
    }

    try {
        $complete_stmt = $pdo->prepare("
            UPDATE appointments
            SET booking_status = 'Completed'
            WHERE id = :id
              AND booking_status = 'Confirmed'
              AND service LIKE 'Vet Services%'
        ");

        $complete_stmt->execute([
            ':id' => $id
        ]);
    } catch (PDOException $e) {
        error_log("Task completion update failed: " . $e->getMessage());
    }

    header("Location: tasks.php");
    exit;
}

// FETCH CONFIRMED AND COMPLETED APPOINTMENTS FOR VET SERVICES
try {
    $tasks_stmt = $pdo->prepare("
        SELECT
            a.*,
            p.name AS pet_name
        FROM appointments a
        LEFT JOIN pets p
            ON a.pet_id = p.id
        WHERE a.booking_status IN ('Confirmed', 'Completed')
          AND a.service LIKE 'Vet Services%'
        ORDER BY
            CASE
                WHEN a.booking_status = 'Completed' THEN 1
                ELSE 0
            END,
            a.appointment_date ASC,
            a.appointment_time ASC
    ");

    $tasks_stmt->execute();
    $tasks = $tasks_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Tasks query failed: " . $e->getMessage());
    $tasks = [];
}

// PROGRESS CALCULATION
$total_tasks = count($tasks);
$completed_tasks = count(
    array_filter(
        $tasks,
        function ($t) {
            return ($t['booking_status'] ?? '') === 'Completed';
        }
    )
);

$progress = ($total_tasks > 0)
    ? ($completed_tasks / $total_tasks) * 100
    : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Tasks | Boogie's Pet Care</title>
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

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
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
        .nav-item i { width: 32px; font-size: 18px; transition: transform 0.3s; text-align: center; }
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
        .main-content { margin-left: 260px; width: calc(100% - 260px); min-height: 100vh; display: flex; flex-direction: column;}
        header { background: var(--white); height: 70px; padding: 0 40px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 1000; box-shadow: 0 1px 10px rgba(0,0,0,0.02);}
        .breadcrumb { font-size: 14px; font-weight: 700; color: var(--sidebar-navy); display: flex; align-items: center; gap: 8px; }
        
        /* --- NOTIFICATION STYLES --- */
        .top-right-actions { display: flex; align-items: center; gap: 20px; }
        .notif-wrapper { position: relative; cursor: pointer; }
        .notif-badge { position: absolute; top: -5px; right: -8px; background: #ef4444; color: white; border-radius: 50%; padding: 2px 6px; font-size: 10px; font-weight: 800; }
        .notif-dropdown { display: none; position: absolute; right: 0; top: 35px; width: 320px; background: white; border: 1px solid var(--border); box-shadow: 0 10px 25px rgba(0,0,0,0.1); border-radius: 12px; z-index: 1000; overflow: hidden;}
        .notif-dropdown.show { display: block; }
        .notif-header { padding: 12px 15px; border-bottom: 1px solid var(--border); font-weight: 700; font-size: 13px; display: flex; justify-content: space-between; align-items: center; background: #f8fafc; }
        .notif-item { padding: 12px 15px; border-bottom: 1px solid #f1f5f9; font-size: 13px; line-height: 1.4; color: #475569; }
        .notif-item:hover { background: #f8fafc; }

        /* --- PINAGANDANG ROLE TAG AT PROFILE --- */
        .role-label {
            display: flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 204, 0, 0.15); 
            color: #d97706; 
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.5px;
            border: 1px solid rgba(255, 204, 0, 0.3);
            text-transform: uppercase;
        }

        .profile-wrapper { position: relative; display: flex; align-items: center; gap: 15px; border-left: 1px solid var(--border); padding-left: 20px; cursor: pointer; user-select: none; }
        .top-avatar { width: 35px; height: 35px; border-radius: 50%; object-fit: cover; border: 2px solid var(--brand-blue); }
        .top-avatar-fallback { width: 35px; height: 35px; border-radius: 50%; background: var(--sidebar-navy); color: var(--brand-yellow); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 14px; border: 2px solid var(--brand-yellow); box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        
        .profile-dropdown { display: none; position: absolute; right: 0; top: 45px; width: 200px; background: white; border: 1px solid var(--border); box-shadow: 0 10px 25px rgba(0,0,0,0.1); border-radius: 12px; z-index: 1000; overflow: hidden; text-align: left; }
        .profile-dropdown.show { display: block; }
        .profile-item { padding: 12px 15px; display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--text-main); text-decoration: none; transition: 0.2s; font-weight: 600;}
        .profile-item:hover { background: #f8fafc; color: var(--brand-blue); }

        /* --- DASHBOARD STATS & TASKS STYLES --- */
        .container { padding: 35px 40px; flex-grow: 1; max-width: 1000px; margin: 0 auto; width: 100%;}

        /* Adjusted stats grid for Tasks (2 columns) */
        .stats-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 30px; }
        
        .stat-card { 
            background: var(--white); 
            padding: 20px; 
            border-radius: 16px; 
            border: 1px solid transparent; 
            cursor: pointer; 
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            box-shadow: 0 4px 6px rgba(0,0,0,0.02);
            position: relative;
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.05);
            border-color: #e2e8f0;
        }
        
        .stat-card h3 { font-size: 12px; color: var(--text-muted); text-transform: uppercase; margin-bottom: 8px; font-weight: 700; letter-spacing: 0.5px;}
        .stat-card p { font-size: 28px; font-weight: 800; color: var(--sidebar-navy); }
        .stat-card i { position: absolute; right: 25px; top: 25px; font-size: 30px; opacity: 0.1;}
        
        .c-total { border-left: 4px solid var(--sidebar-navy); }
        .c-total i { color: var(--sidebar-navy); }
        .c-completed { border-left: 4px solid #10b981; }
        .c-completed i { color: #10b981; }

        /* --- TASK LIST CONTAINER --- */
        .card { background: var(--white); border-radius: 20px; border: 1px solid var(--border); overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.02); margin-bottom: 20px; padding: 30px;}
        
        .progress-bar-bg { background: #f1f5f9; height: 10px; border-radius: 5px; margin: 20px 0; overflow: hidden; box-shadow: inset 0 1px 2px rgba(0,0,0,0.05); }
        .progress-bar-fill { background: linear-gradient(90deg, #10b981, #059669); height: 100%; border-radius: 5px; transition: width 0.4s ease; }
        
        .task-list { display: flex; flex-direction: column; gap: 15px; margin-top: 25px;}
        .task-item { display: flex; align-items: center; justify-content: space-between; padding: 20px 25px; border: 1px solid var(--border); border-radius: 16px; transition: 0.2s; background: #fff; border-left: 4px solid var(--sidebar-navy); }
        .task-item:hover { background: #f8fafc; border-color: #cbd5e1; transform: translateY(-2px); box-shadow: 0 4px 10px rgba(0,0,0,0.03); border-left-color: var(--brand-yellow);}
        .task-content { display: flex; align-items: center; gap: 20px; }
        
        .task-title { font-size: 16px; font-weight: 800; color: var(--sidebar-navy); margin-bottom: 3px; }
        .task-text { font-size: 14px; font-weight: 500; color: var(--text-main); }
        .task-date { font-size: 12px; color: var(--text-muted); display: flex; align-items: center; gap: 12px; margin-top: 8px; font-weight: 500;}
        
        .task-item.is-completed { opacity: 0.6; background: #f8fafc; border-left-color: #10b981;}
        .task-item.is-completed .task-text, .task-item.is-completed .task-title { text-decoration: line-through; color: var(--text-muted); }
        
        .btn-toggle { width: 35px; height: 35px; border-radius: 10px; border: 2px solid #cbd5e1; display: flex; align-items: center; justify-content: center; text-decoration: none; color: transparent; transition: 0.2s; flex-shrink: 0; background: white;}
        .btn-toggle:hover { border-color: #10b981; color: #10b981; background: #dcfce7; transform: scale(1.1);}
        .task-item.is-completed .btn-toggle { background: #10b981; border-color: #10b981; color: white; pointer-events: none; }
        
        .status-badge { font-size: 10px; padding: 6px 12px; border-radius: 8px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; display: inline-block;}
        .badge-confirmed { background: #e0f2fe; color: #0369a1; }
        .badge-completed { background: #dcfce7; color: #166534; }

        .empty-state { text-align: center; padding: 60px 0; color: #94a3b8; }
        .empty-state i { font-size: 50px; color: #cbd5e1; margin-bottom: 20px; opacity: 0.5; display: block; }
        .empty-state p { font-weight: 500; }

        footer { text-align: center; padding: 30px; color: var(--text-muted); font-size: 12px; margin-top: auto; border-top: 1px solid var(--border);}

        /* --- MOBILE RESPONSIVE --- */
        .mobile-menu-btn {
            display: none;
            width: 42px;
            height: 42px;
            border: 1px solid var(--border);
            background: var(--white);
            color: var(--sidebar-navy);
            border-radius: 10px;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 18px;
            flex-shrink: 0;
        }

        .sidebar-overlay {
            display: none;
        }

        .profile-name-text {
            min-width: 0;
        }

        @media (max-width: 1100px) {
            .sidebar {
                width: 230px;
            }

            .main-content {
                margin-left: 230px;
                width: calc(100% - 230px);
            }

            header {
                padding: 0 24px;
            }

            .container {
                padding: 28px 24px;
            }

            .profile-wrapper {
                gap: 10px;
                padding-left: 15px;
            }

            .role-label {
                padding: 4px 9px;
            }

            .profile-name-text {
                max-width: 180px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
        }

        @media (max-width: 900px) {
            .sidebar {
                width: 260px;
                left: -270px;
                transition: left 0.25s ease;
                box-shadow: 8px 0 30px rgba(0,0,0,0.15);
            }

            .sidebar.mobile-open {
                left: 0;
            }

            .sidebar-overlay {
                position: fixed;
                inset: 0;
                background: rgba(0,0,0,0.38);
                z-index: 90;
                display: none;
            }

            .sidebar-overlay.show {
                display: block;
            }

            .main-content {
                margin-left: 0;
                width: 100%;
            }

            .mobile-menu-btn {
                display: inline-flex;
                position: fixed;
                top: 12px;
                left: 12px;
                z-index: 1100;
            }

            header {
                height: 66px;
                padding: 0 16px 0 66px;
                gap: 12px;
            }

            .breadcrumb {
                min-width: 0;
                flex: 1;
                overflow: hidden;
            }

            .breadcrumb {
                white-space: nowrap;
                text-overflow: ellipsis;
            }

            .breadcrumb i {
                flex-shrink: 0;
            }

            .top-right-actions {
                gap: 12px;
                flex-shrink: 0;
            }

            .profile-wrapper {
                gap: 8px;
                padding-left: 12px;
            }

            .profile-name-text {
                display: none !important;
            }

            .role-label {
                font-size: 10px;
                padding: 4px 8px;
            }

            .notif-dropdown {
                position: fixed;
                top: 70px;
                right: 12px;
                width: min(340px, calc(100vw - 24px));
            }

            .profile-dropdown {
                position: fixed;
                top: 70px;
                right: 12px;
                width: min(220px, calc(100vw - 24px));
            }

            .container {
                max-width: none;
                padding: 22px 16px 28px;
            }

            .container > div:first-child {
                margin-bottom: 22px !important;
            }

            .container > div:first-child h1 {
                font-size: 22px !important;
                line-height: 1.3;
            }

            .container > div:first-child p {
                font-size: 13px !important;
                line-height: 1.5;
            }

            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 14px;
                margin-bottom: 22px;
            }

            .stat-card {
                padding: 18px;
                border-radius: 15px;
                min-width: 0;
            }

            .stat-card h3 {
                font-size: 10px;
                line-height: 1.35;
                padding-right: 34px;
            }

            .stat-card p {
                font-size: 27px;
            }

            .stat-card i {
                right: 18px;
                top: 18px;
                font-size: 26px;
            }

            .card {
                padding: 20px 16px;
                border-radius: 16px;
            }

            .card > h2 {
                font-size: 16px !important;
                line-height: 1.4;
            }

            .task-list {
                gap: 12px;
                margin-top: 20px;
            }

            .task-item {
                display: flex;
                flex-direction: column;
                align-items: stretch;
                gap: 14px;
                padding: 16px;
                border-radius: 14px;
            }

            .task-content {
                gap: 13px;
                min-width: 0;
                align-items: flex-start;
            }

            .task-title {
                font-size: 14px;
                line-height: 1.4;
                overflow-wrap: anywhere;
            }

            .task-text {
                font-size: 12px;
                line-height: 1.45;
                overflow-wrap: anywhere;
            }

            .task-date {
                font-size: 11px;
                line-height: 1.5;
                gap: 8px;
                flex-wrap: wrap;
            }

            .btn-toggle {
                width: 40px;
                height: 40px;
                flex: 0 0 40px;
            }

            .task-item > div:last-child {
                align-self: flex-start;
            }

            .status-badge {
                font-size: 9px;
                padding: 6px 10px;
            }

            .progress-bar-bg {
                margin: 16px 0;
            }

            .empty-state {
                padding: 45px 8px;
            }

            footer {
                padding: 24px 16px;
                line-height: 1.5;
            }
        }

        @media (max-width: 560px) {
            .top-right-actions {
                gap: 8px;
            }

            .role-label {
                display: none;
            }

            .top-avatar,
            .top-avatar-fallback {
                width: 34px;
                height: 34px;
            }

            .container {
                padding-left: 12px;
                padding-right: 12px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .stat-card {
                display: grid;
                grid-template-columns: 40px minmax(0, 1fr) auto;
                grid-template-rows: auto auto;
                column-gap: 12px;
                align-items: center;
                padding: 15px 16px;
            }

            .stat-card i {
                position: static;
                grid-row: 1 / 3;
                grid-column: 1;
                margin: 0;
                font-size: 24px;
                opacity: 0.16;
            }

            .stat-card h3 {
                grid-column: 2;
                grid-row: 1 / 3;
                padding-right: 0;
                margin: 0;
            }

            .stat-card p {
                grid-column: 3;
                grid-row: 1 / 3;
                margin: 0;
                font-size: 24px;
            }

            .card {
                padding: 18px 12px;
            }

            .task-item {
                padding: 14px;
            }

            .task-content {
                gap: 10px;
            }

            .task-date {
                display: flex;
                flex-direction: column;
                align-items: flex-start;
                gap: 4px;
            }

            .empty-state i {
                font-size: 42px;
            }
        }

        @media (max-width: 380px) {
            .mobile-menu-btn {
                width: 38px;
                height: 38px;
                top: 11px;
                left: 10px;
            }

            header {
                padding-left: 58px;
            }

            .breadcrumb {
                font-size: 12px;
                gap: 6px;
            }

            .top-avatar,
            .top-avatar-fallback {
                width: 32px;
                height: 32px;
            }

            .task-content {
                align-items: flex-start;
            }

            .btn-toggle {
                width: 36px;
                height: 36px;
                flex-basis: 36px;
            }

            .task-item > div:last-child {
                width: 100%;
            }

            .status-badge {
                width: fit-content;
            }
        }


        /* ===== Mobile-flexible layout additions ===== */
        html { width: 100%; overflow-x: hidden; }
        body { width: 100%; max-width: 100%; }
        img { max-width: 100%; }
        input, select, textarea, button { max-width: 100%; }


        /* ===== Final mobile containment ===== */
        @media (max-width: 900px) {
            html, body { width:100%; max-width:100%; overflow-x:hidden; }
            .main-content { width:100% !important; min-width:0; }
            header { width:100%; max-width:100%; }
            .container, .card, .schedule-card, .profile-card { max-width:100%; }
            img { max-width:100%; height:auto; }
            input, select, textarea, button { max-width:100%; }
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
    .main-content > header .top-right-actions {
        gap:7px !important;
        min-width:0 !important;
        flex:0 1 auto !important;
    }
    .main-content > header .profile-wrapper {
        gap:6px !important;
        padding-left:7px !important;
        min-width:0 !important;
        max-width:150px !important;
        flex:0 1 auto !important;
    }
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

    <button class="mobile-menu-btn" id="mobileMenuBtn" type="button" aria-label="Open menu" aria-expanded="false">
        <i class="fas fa-bars"></i>
    </button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>


    <aside class="sidebar">
        <div class="sidebar-header">
            <img src="../bg.png" alt="Boogie's Logo" class="sidebar-logo-img">
            <h2>Boogie's Clinic</h2>
        </div>
        <nav class="nav-links">
            <a href="staffdashboard.php" class="nav-item"><i class="fas fa-th-large"></i> Dashboard</a>
            <a href="appointments.php" class="nav-item"><i class="fas fa-calendar-alt"></i> Appointments</a>
            <a href="pets.php" class="nav-item"><i class="fas fa-paw"></i> Patients</a>
            <a href="tasks.php" class="nav-item active"><i class="fas fa-tasks"></i> My Tasks</a>
        </nav>
    </aside>

    <main class="main-content">
        <header>
            <div class="breadcrumb">
                <i class="fas fa-tasks" style="color: var(--brand-blue);"></i> 
                <span class="breadcrumb-text">Veterinarian Portal / My Tasks</span></div>
            
            <div class="top-right-actions">
                <div class="notif-wrapper" onclick="toggleNotif(event)">
                    <i class="fa-solid fa-bell" style="font-size: 20px; color: var(--text-muted);"></i>
                    
                    <?php if($unread_count > 0): ?>
                        <span id="admin-notif-badge" class="notif-badge"><?php echo $unread_count; ?></span>
                    <?php else: ?>
                        <span id="admin-notif-badge" class="notif-badge" style="display: none;">0</span>
                    <?php endif; ?>
                    
                    <div class="notif-dropdown" id="notifBox" onclick="event.stopPropagation()">
                        <div class="notif-header">
                            Alerts
                        </div>
                        
                        <div class="notif-body" id="staff-notif-list">
                            <div class="notif-empty">Staff notifications are not connected on this page yet.</div>
                        </div>
                    </div>
                </div>

                <div class="profile-wrapper" onclick="toggleProfile(event)">
                    <div class="role-label">
                        <i class="fas fa-user-md"></i> VET
                    </div>
                    
                    <?php if ($profile_img_src !== ''): ?>
                        <img src="<?php echo htmlspecialchars($profile_img_src, ENT_QUOTES, 'UTF-8'); ?>" class="top-avatar" alt="Profile Picture">
                    <?php else: ?>
                        <div class="top-avatar-fallback"><?php echo htmlspecialchars($first_letter !== '' ? $first_letter : 'D', ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                    
                    <span class="profile-name-text desktop-profile-name" style="font-size: 14px; font-weight: 700; color: var(--sidebar-navy); display: flex; align-items: center; gap: 6px;">
                        <?php echo htmlspecialchars($display_with_title); ?>
                        <i class="fas fa-chevron-down" style="font-size: 10px; color: var(--text-muted); opacity: 0.5;"></i>
                    </span>
                    <span class="mobile-profile-first-name">
                        <?php echo htmlspecialchars($first_name_only); ?>
                    </span>

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
            <div style="margin-bottom: 30px;">
                <h1 style="font-size: 24px; font-weight: 800; color: var(--sidebar-navy);">My Tasks</h1>
                <p style="color: var(--text-muted); font-size: 14px; font-weight: 500;">Manage and track your daily medical procedures</p>
            </div>

            <div class="stats-grid">
                <div class="stat-card c-total">
                    <i class="fas fa-clipboard-list"></i>
                    <h3>Total Procedures</h3>
                    <p><?php echo $total_tasks; ?></p>
                </div>
                <div class="stat-card c-completed">
                    <i class="fas fa-check-circle"></i>
                    <h3>Completed</h3>
                    <p><?php echo $completed_tasks; ?></p>
                </div>
            </div>

            <div class="card">
                <h2 style="font-size: 18px; color: var(--sidebar-navy); margin-bottom: 20px; border-bottom: 2px solid #f8fafc; padding-bottom: 15px; font-weight: 800;">
                    <i class="fas fa-clock" style="color: var(--brand-blue); margin-right: 8px;"></i> Daily Queue
                </h2>
                
                <div style="font-size: 13px; font-weight: 700; color: var(--brand-blue);">Completion Progress: <?php echo $completed_tasks; ?> / <?php echo $total_tasks; ?> Tasks</div>
                <div class="progress-bar-bg">
                    <div class="progress-bar-fill" style="width: <?php echo $progress; ?>%;"></div>
                </div>

                <div class="task-list">
                    <?php if ($total_tasks > 0): ?>
                        <?php foreach ($tasks as $task): ?>
                            <?php $is_done = ($task['booking_status'] === 'Completed'); ?>
                            
                            <div class="task-item <?php echo $is_done ? 'is-completed' : ''; ?>">
                                <div class="task-content">
                                    <?php if (!$is_done): ?>
                                        <form method="POST" action="tasks.php" onsubmit="return confirm('Are you sure you have completed this service?');" style="margin:0;">
                                            <input type="hidden" name="appointment_id" value="<?php echo (int)$task['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($tasks_csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                            <button type="submit" name="complete_task" class="btn-toggle" title="Mark as Completed">
                                                <i class="fas fa-check" style="font-size: 16px;"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <div class="btn-toggle">
                                            <i class="fas fa-check" style="font-size: 16px;"></i>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div>
                                        <div class="task-title"><?php echo htmlspecialchars((string)($task['service'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="task-text">Patient: <strong><?php echo htmlspecialchars((string)($task['pet_name'] ?? 'Unknown Pet'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                        <div class="task-date">
                                            <span><i class="far fa-calendar-alt"></i> <?php echo !empty($task['appointment_date']) && strtotime((string)$task['appointment_date']) !== false ? date("M d, Y", strtotime((string)$task['appointment_date'])) : 'Date TBA'; ?></span>
                                            <span><i class="far fa-clock"></i> <?php echo !empty($task['appointment_time']) && strtotime((string)$task['appointment_time']) !== false ? date("g:i A", strtotime((string)$task['appointment_time'])) : 'TBA'; ?></span>
                                        </div>
                                    </div>
                                </div>
                                
                                <div>
                                    <span class="status-badge <?php echo $is_done ? 'badge-completed' : 'badge-confirmed'; ?>">
                                        <?php echo htmlspecialchars((string)($task['booking_status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </div>
                            </div>
                            
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-clipboard-check"></i>
                            <p>No pending procedures.<br>Confirmed bookings will appear here automatically.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <footer>
            © <?php echo date("Y"); ?> BOOGIE'S PET CARE & SERVICES - DASMARIÑAS BRANCH
        </footer>
    </main>

    <script>

        // --- MOBILE SIDEBAR ---
        document.querySelectorAll('.main-content > header, header').forEach(function (header) { if (!header.querySelector('.mobile-menu-btn')) header.classList.add('mobile-header-no-button'); });
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.querySelector('.sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        function toggleMobileMenu() {
            const isOpen = sidebar.classList.toggle('mobile-open');
            sidebarOverlay.classList.toggle('show', isOpen);
            mobileMenuBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            mobileMenuBtn.innerHTML = isOpen
                ? '<i class="fas fa-times"></i>'
                : '<i class="fas fa-bars"></i>';
        }

        function closeMobileMenu() {
            sidebar.classList.remove('mobile-open');
            sidebarOverlay.classList.remove('show');
            mobileMenuBtn.setAttribute('aria-expanded', 'false');
            mobileMenuBtn.innerHTML = '<i class="fas fa-bars"></i>';
        }

        mobileMenuBtn.addEventListener('click', toggleMobileMenu);
        sidebarOverlay.addEventListener('click', closeMobileMenu);

        document.querySelectorAll('.nav-item').forEach(function(link) {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 900) {
                    closeMobileMenu();
                }
            });
        });

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

        // Close dropdowns when clicking outside
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


        window.addEventListener('resize', function() {
            if (window.innerWidth > 900) {
                closeMobileMenu();
            }
        });

        // Staff notification API/table is not configured here. Do not poll Admin endpoints from staff pages.
    </script>
</body>
</html>