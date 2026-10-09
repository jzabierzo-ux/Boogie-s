<?php
session_start();

/* -------------------------------------------------------------------------
   AUTHENTICATION AND VETERINARY ROLE ACCESS
   ------------------------------------------------------------------------- */
$current_role_raw = strtolower(trim((string)($_SESSION['role'] ?? '')));
$role_aliases = [
    'vet' => 'veterinarian',
    'veterinary' => 'veterinarian',
    'veterinarian' => 'veterinarian',
    'vet assistant' => 'vet_assistant',
    'veterinary assistant' => 'vet_assistant',
    'veterinary_assistant' => 'vet_assistant',
    'vet_assistant' => 'vet_assistant',
    'vet nurse' => 'vet_nurse',
    'veterinary nurse' => 'vet_nurse',
    'veterinary_nurse' => 'vet_nurse',
    'vet_nurse' => 'vet_nurse'
];
$current_role = $role_aliases[$current_role_raw] ?? $current_role_raw;

$session_logged_in = in_array($_SESSION['logged_in'] ?? null, [true, 1, '1'], true)
    || in_array($_SESSION['staff_logged_in'] ?? null, [true, 1, '1'], true);

if (!$session_logged_in) {
    header('Location: stafflogin.php');
    exit();
}

if (!in_array($current_role, ['veterinarian', 'vet_assistant', 'vet_nurse'], true)) {
    http_response_code(403);
    exit('Access denied. This dashboard is for veterinary personnel only.');
}

require_once '../db_supabase.php';
date_default_timezone_set('Asia/Manila');
header('Cache-Control: private, no-store, max-age=0');

$role_labels = [
    'veterinarian' => 'Veterinarian',
    'vet_assistant' => 'Vet Assistant',
    'vet_nurse' => 'Vet Nurse'
];
$role_label = $role_labels[$current_role] ?? 'Veterinary Staff';
$user_id = (int)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? 0);

if (empty($_SESSION['staff_dashboard_csrf'])) {
    $_SESSION['staff_dashboard_csrf'] = bin2hex(random_bytes(32));
}
$csrf_token = (string)$_SESSION['staff_dashboard_csrf'];

/* -------------------------------------------------------------------------
   PROFILE IMAGE PATH RESOLUTION
   Supports profile image URLs and existing local project upload folders.
   ------------------------------------------------------------------------- */
function resolveStaffDashboardImageUrl($storedPath): string
{
    $storedPath = trim((string)$storedPath);
    if ($storedPath === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $storedPath)) {
        return filter_var($storedPath, FILTER_VALIDATE_URL) ? $storedPath : '';
    }

    $cleanPath = str_replace('\\', '/', $storedPath);
    $cleanPath = ltrim($cleanPath, '/');
    $cleanPath = preg_replace('#^(?:\./)+#', '', $cleanPath);

    // Do not allow stored paths to escape the project folders.
    if ($cleanPath === '' || preg_match('#(^|/)\.\.(/|$)#', $cleanPath)) {
        return '';
    }

    $projectRoot = dirname(__DIR__);
    $candidates = [];

    if (str_starts_with($cleanPath, 'admin/')) {
        $candidates[] = [$projectRoot . '/' . $cleanPath, '../' . $cleanPath];
    } elseif (str_starts_with($cleanPath, 'staff/')) {
        $candidates[] = [$projectRoot . '/' . $cleanPath, '../' . $cleanPath];
    } elseif (str_starts_with($cleanPath, 'uploads/')) {
        $candidates[] = [__DIR__ . '/' . $cleanPath, $cleanPath];
        $candidates[] = [$projectRoot . '/admin/' . $cleanPath, '../admin/' . $cleanPath];
        $candidates[] = [$projectRoot . '/' . $cleanPath, '../' . $cleanPath];
    } else {
        $candidates[] = [__DIR__ . '/' . $cleanPath, $cleanPath];
    }

    foreach ($candidates as [$absolutePath, $urlPath]) {
        if (is_file($absolutePath)) {
            return $urlPath;
        }
    }

    return '';
}

/* -------------------------------------------------------------------------
   STAFF-OWNED NOTIFICATIONS
   These come from notifications.user_id, not admin_notifications.
   ------------------------------------------------------------------------- */
function loadStaffDashboardNotifications(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [0, []];
    }

    $countStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM notifications
        WHERE user_id = :user_id
          AND is_read = 0
    ");
    $countStmt->execute([':user_id' => $userId]);
    $unreadCount = (int)$countStmt->fetchColumn();

    $listStmt = $pdo->prepare("
        SELECT id, title, message, type, created_at
        FROM notifications
        WHERE user_id = :user_id
          AND is_read = 0
        ORDER BY created_at DESC, id DESC
        LIMIT 20
    ");
    $listStmt->execute([':user_id' => $userId]);

    return [$unreadCount, $listStmt->fetchAll(PDO::FETCH_ASSOC)];
}

function renderStaffDashboardNotifications(array $notifications): string
{
    $html = '';
    $icons = [
        'success' => 'fa-circle-check',
        'completed' => 'fa-circle-check',
        'booking' => 'fa-calendar-check',
        'appointment' => 'fa-calendar-check',
        'warning' => 'fa-triangle-exclamation',
        'error' => 'fa-circle-exclamation',
        'alert' => 'fa-circle-exclamation'
    ];

    foreach ($notifications as $notification) {
        $title = trim((string)($notification['title'] ?? ''));
        $message = trim((string)($notification['message'] ?? ''));
        $type = strtolower(trim((string)($notification['type'] ?? '')));
        $icon = $icons[$type] ?? 'fa-bell';

        $html .= '<div class="notif-item">';
        $html .= '<i class="fa-solid ' . $icon . '" style="color:#ef4444;margin-right:5px;" aria-hidden="true"></i>';

        if ($title !== '') {
            $html .= '<strong style="display:block;margin-bottom:3px;">' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong>';
        }

        if ($message !== '') {
            $html .= '<span>' . nl2br(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false) . '</span>';
        } elseif ($title === '') {
            $html .= 'New notification';
        }

        $timestamp = !empty($notification['created_at']) ? strtotime((string)$notification['created_at']) : false;
        if ($timestamp !== false) {
            $html .= '<br><small style="color:#94a3b8;font-size:11px;">' . htmlspecialchars(date('M d, g:i A', $timestamp), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</small>';
        }

        $html .= '</div>';
    }

    if ($html === '') {
        $html = '<div class="notif-empty">No new clinic alerts.</div>';
    }

    return $html;
}

/* -------------------------------------------------------------------------
   MARK ONLY THIS USER'S NOTIFICATIONS AS READ (POST + CSRF)
   ------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') !== 'mark_notifications_read') {
        http_response_code(405);
        header('Allow: GET, POST');
        exit('Method not allowed.');
    }

    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    if ($submittedToken === '' || !hash_equals($csrf_token, $submittedToken)) {
        http_response_code(403);
        exit('Invalid security token. Refresh the page and try again.');
    }

    if ($user_id <= 0) {
        http_response_code(403);
        exit('Unable to identify the staff account.');
    }

    try {
        $markReadStmt = $pdo->prepare("
            UPDATE notifications
            SET is_read = 1
            WHERE user_id = :user_id
              AND is_read = 0
        ");
        $markReadStmt->execute([':user_id' => $user_id]);
    } catch (PDOException $e) {
        error_log('Staff dashboard mark-notifications-read failed: ' . $e->getMessage());
        http_response_code(500);
        exit('Unable to update notifications right now.');
    }

    header('Location: staffdashboard.php', true, 303);
    exit();
}

/* -------------------------------------------------------------------------
   SAME-FILE AJAX ENDPOINT FOR THE STAFF NOTIFICATION DROPDOWN
   ------------------------------------------------------------------------- */
if (($_GET['ajax'] ?? '') === 'notifications') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    try {
        [$ajaxUnreadCount, $ajaxNotifications] = loadStaffDashboardNotifications($pdo, $user_id);
        echo json_encode([
            'success' => true,
            'unread' => $ajaxUnreadCount,
            'html' => renderStaffDashboardNotifications($ajaxNotifications)
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (PDOException $e) {
        error_log('Staff dashboard notification poll failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'unread' => 0,
            'html' => '<div class="notif-empty">Unable to load notifications.</div>'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit();
}

/* -------------------------------------------------------------------------
   LOAD STAFF PROFILE
   ------------------------------------------------------------------------- */
$staff_name = (string)($_SESSION['staff_name'] ?? $_SESSION['user_name'] ?? 'Staff');
$full_display_name = $staff_name;
$profile_img_path = '';
$profile_img_src = '';

if ($user_id > 0) {
    try {
        $getStaff = $pdo->prepare("
            SELECT full_name, profile_image
            FROM users
            WHERE id = :uid
            LIMIT 1
        ");
        $getStaff->execute([':uid' => $user_id]);
        $staffData = $getStaff->fetch(PDO::FETCH_ASSOC);

        if ($staffData) {
            $profile_img_path = (string)($staffData['profile_image'] ?? '');
            $profile_img_src = resolveStaffDashboardImageUrl($profile_img_path);
            if (!empty($staffData['full_name'])) {
                $full_display_name = (string)$staffData['full_name'];
            }
        }
    } catch (PDOException $e) {
        error_log('Staff profile query failed: ' . $e->getMessage());
    }
}

$name_without_title = trim((string)preg_replace('/^\s*(?:Dr\.?|Dra\.?|Doc\.?)\s+/i', '', $full_display_name));
$first_letter = strtoupper(substr($name_without_title !== '' ? $name_without_title : 'U', 0, 1));
$has_professional_title = preg_match('/^\s*(?:Dr\.?|Dra\.?|Doc\.?)\s+/i', $full_display_name) === 1;
$display_with_title = ($current_role === 'veterinarian' && !$has_professional_title)
    ? 'Dr. ' . $full_display_name
    : $full_display_name;
$mobile_name_source = trim((string)preg_replace('/^\s*(?:Dr\.?|Dra\.?|Doc\.?)\s+/i', '', $display_with_title));
$first_name_only = trim((string)(preg_split('/\s+/', $mobile_name_source)[0] ?? ''));

/* -------------------------------------------------------------------------
   INITIAL NOTIFICATION STATE
   ------------------------------------------------------------------------- */
$staff_notifications = [];
$unread_count = 0;
try {
    [$unread_count, $staff_notifications] = loadStaffDashboardNotifications($pdo, $user_id);
} catch (PDOException $e) {
    error_log('Staff dashboard notifications query failed: ' . $e->getMessage());
}
$staff_notification_html = renderStaffDashboardNotifications($staff_notifications);

$today = date('Y-m-d');
$hour = (int)date('H');
$greeting = ($hour < 12) ? 'Good Morning' : (($hour < 17) ? 'Good Afternoon' : 'Good Evening');

/* -------------------------------------------------------------------------
   DASHBOARD COUNTS AND TODAY'S VETERINARY SCHEDULE
   ------------------------------------------------------------------------- */
try {
    $todayCountStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM appointments
        WHERE appointment_date = :today
          AND service LIKE 'Vet Services%'
          AND (booking_status IS NULL OR booking_status <> 'Cancelled')
    ");
    $todayCountStmt->execute([':today' => $today]);
    $today_count = (int)$todayCountStmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Today's appointments count query failed: " . $e->getMessage());
    $today_count = 0;
}

try {
    $completedCountStmt = $pdo->query("
        SELECT COUNT(*)
        FROM appointments
        WHERE booking_status = 'Completed'
          AND service LIKE 'Vet Services%'
    ");
    $completed_count = (int)$completedCountStmt->fetchColumn();
} catch (PDOException $e) {
    error_log('Completed consultations count query failed: ' . $e->getMessage());
    $completed_count = 0;
}

try {
    $petCountStmt = $pdo->query('SELECT COUNT(*) FROM pets');
    $pet_count = (int)$petCountStmt->fetchColumn();
} catch (PDOException $e) {
    error_log('Patient count query failed: ' . $e->getMessage());
    $pet_count = 0;
}

$today_schedule = [];
try {
    $todayScheduleStmt = $pdo->prepare("
        SELECT a.*, p.name AS pet_name
        FROM appointments a
        LEFT JOIN pets p ON a.pet_id = p.id
        WHERE a.appointment_date = :today
          AND a.service LIKE 'Vet Services%'
          AND (a.booking_status <> 'Cancelled' OR a.booking_status IS NULL)
        ORDER BY a.appointment_time ASC
    ");
    $todayScheduleStmt->execute([':today' => $today]);
    $today_schedule = $todayScheduleStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Today's schedule query failed: " . $e->getMessage());
    $today_schedule = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($role_label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?> Dashboard | Boogie's Pet Care</title>
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
        
        /* --- EXACT ADMIN SIDEBAR CLONE CSS --- */
        .nav-links { 
            flex-grow: 1; padding: 20px 15px; display: flex; flex-direction: column; gap: 5px; 
        }
        .nav-item {
            display: flex; align-items: center; padding: 14px 20px; color: #94a3b8; 
            text-decoration: none; transition: all 0.3s ease; font-size: 14px;
            font-weight: 500; border-radius: 10px; position: relative;
        }
        .nav-item i { width: 32px; font-size: 18px; transition: transform 0.3s; text-align: center;}
        .nav-item:hover { 
            color: var(--white); background-color: rgba(255, 255, 255, 0.05); 
            transform: translateX(4px); 
        }
        .nav-item.active { 
            color: var(--brand-yellow); background-color: rgba(255, 204, 0, 0.08); 
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
        .notif-wrapper { position: relative; display: inline-flex; align-items: center; cursor: pointer; }
        .notif-badge { position: absolute; top: -5px; right: -8px; background: #e11d48; color: white; border-radius: 50%; padding: 2px 6px; font-size: 10px; font-weight: bold; }
        .notif-dropdown { display: none; position: absolute; right: 0; top: 35px; width: 320px; background: white; border: 1px solid var(--border); box-shadow: 0 10px 25px rgba(0,0,0,0.1); border-radius: 12px; z-index: 1000; overflow: hidden;}
        .notif-dropdown.show { display: block; }
        .notif-header { padding: 12px 15px; border-bottom: 1px solid var(--border); font-weight: 700; font-size: 13px; display: flex; justify-content: space-between; align-items: center; background: #f8fafc; }
        .notif-body { max-height: 300px; overflow-y: auto; }
        .notif-item { padding: 12px 15px; border-bottom: 1px solid #f1f5f9; font-size: 13px; line-height: 1.4; color: #475569; }
        .notif-item:hover { background: #f8fafc; }
        .mark-read-btn { border: 0; padding: 0; background: transparent; color: var(--brand-blue); font-size: 11px; font-weight: 700; cursor: pointer; }
        .mark-read-btn:hover { text-decoration: underline; }
        .notif-empty { padding: 20px; text-align: center; color: #94a3b8; font-size: 13px; }

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

        .profile-wrapper { 
            position: relative; 
            display: flex; 
            align-items: center; 
            gap: 15px; 
            border-left: 1px solid var(--border); 
            padding-left: 20px; 
            cursor: pointer; 
            user-select: none; 
        }

        .top-avatar { 
            width: 35px; 
            height: 35px; 
            border-radius: 50%; 
            object-fit: cover; 
            border: 2px solid var(--brand-blue); 
        }

        .top-avatar-fallback { 
            width: 35px; 
            height: 35px; 
            border-radius: 50%; 
            background: var(--sidebar-navy); 
            color: var(--brand-yellow); 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-weight: 800; 
            font-size: 14px; 
            border: 2px solid var(--brand-yellow);
        }
        
        .profile-dropdown { display: none; position: absolute; right: 0; top: 45px; width: 200px; background: white; border: 1px solid var(--border); box-shadow: 0 10px 25px rgba(0,0,0,0.1); border-radius: 12px; z-index: 1000; overflow: hidden; text-align: left; }
        .profile-dropdown.show { display: block; }
        .profile-item { padding: 12px 15px; display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--text-main); text-decoration: none; transition: 0.2s; font-weight: 600;}
        .profile-item:hover { background: #f8fafc; color: var(--brand-blue); }

        /* --- DASHBOARD ELEMENTS --- */
        .container { padding: 35px 40px; flex-grow: 1; }
        
        .hero-banner { 
            background: linear-gradient(135deg, #001529 0%, #003366 100%); 
            padding: 40px; border-radius: 20px; color: white; margin-bottom: 30px; 
            position: relative; overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 21, 41, 0.15);
        }
        .hero-banner::after {
            content: '\f1b0'; font-family: 'Font Awesome 6 Free'; font-weight: 900;
            position: absolute; right: -20px; bottom: -30px; font-size: 180px; opacity: 0.05; transform: rotate(-15deg);
        }
        .hero-banner h2 { font-size: 28px; font-weight: 800; margin-bottom: 8px; }
        .hero-banner p { font-size: 14px; opacity: 0.8; font-weight: 500;}

        .stats-grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 25px; margin-bottom: 35px; }
        .stat-card { 
            background: var(--white); padding: 25px; border-radius: 20px; 
            border: 1px solid transparent; transition: 0.3s; cursor: pointer; 
            box-shadow: 0 4px 6px rgba(0,0,0,0.02);
            border-bottom: 4px solid var(--sidebar-navy);
        }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 12px 25px rgba(0,0,0,0.08); border-bottom-color: var(--brand-yellow); }
        .stat-card i { font-size: 24px; margin-bottom: 15px; display: block; }
        .stat-card h4 { font-size: 11px; color: var(--text-muted); text-transform: uppercase; font-weight: 700; letter-spacing: 1px; margin-bottom: 5px; }
        .stat-card p { font-size: 32px; font-weight: 800; color: var(--sidebar-navy); }

        /* Dynamic Schedule Card Styles */
        .schedule-card { background: white; border-radius: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); overflow: hidden; border: 1px solid var(--border); }
        .schedule-header { padding: 25px 30px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; background: #f8fafc; }
        .schedule-header h3 { font-size: 17px; font-weight: 800; color: var(--sidebar-navy); }
        
        .schedule-list { padding: 0 30px; }
        .schedule-item { display: flex; align-items: center; padding: 20px 0; border-bottom: 1px solid #f1f5f9; transition: 0.2s;}
        .schedule-item:hover { background-color: #fafcfe; }
        .schedule-item:last-child { border-bottom: none; }
        
        .time-box { width: 100px; font-weight: 800; color: var(--brand-blue); font-size: 14px; text-align: center; background: #eff6ff; padding: 8px; border-radius: 10px; margin-right: 25px;}
        .pet-icon { width: 45px; height: 45px; background: #f1f5f9; border-radius: 12px; display: flex; align-items: center; justify-content: center; margin-right: 15px; color: var(--sidebar-navy); font-size: 18px;}
        
        .schedule-info { flex-grow: 1; }
        .schedule-info strong { display: block; color: var(--sidebar-navy); font-size: 16px; font-weight: 700; }
        .schedule-info span { color: var(--text-muted); font-size: 13px; font-weight: 500;}
        
        .schedule-status { font-size: 10px; padding: 6px 12px; border-radius: 8px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px;}
        .st-pending { background: #fef3c7; color: #92400e; }
        .st-confirmed { background: #dbeafe; color: #0369a1; }
        .st-completed { background: #dcfce7; color: #15803d; }
        .st-rescheduled { background: #ede9fe; color: #6d28d9; }
        .st-no-show { background: #fee2e2; color: #b91c1c; }
        .st-cancelled { background: #f1f5f9; color: #475569; }

        .btn-view-all { color: var(--brand-blue); text-decoration: none; font-size: 13px; font-weight: 700; background: #eff6ff; padding: 8px 16px; border-radius: 8px; transition: 0.2s;}
        .btn-view-all:hover { background: var(--brand-blue); color: white; }

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

        @media (max-width: 1100px) {
            .sidebar { width: 230px; }
            .main-content {
                margin-left: 230px;
                width: calc(100% - 230px);
            }
            header { padding: 0 24px; }
            .container { padding: 28px 24px; }
            .stats-grid-3 { gap: 18px; }
            .profile-wrapper { gap: 10px; padding-left: 15px; }
            .role-label { padding: 4px 9px; }
            .profile-name-text { max-width: 170px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
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
            }

            header {
                height: 66px;
                padding: 0 16px;
                gap: 12px;
            }

            .breadcrumb {
                min-width: 0;
                flex: 1;
            }

            .breadcrumb span {
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .top-right-actions {
                gap: 12px;
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
                padding: 22px 16px 28px;
            }

            .hero-banner {
                padding: 26px 22px;
                border-radius: 16px;
                margin-bottom: 22px;
            }

            .hero-banner h2 {
                font-size: 23px;
                line-height: 1.3;
                padding-right: 20px;
            }

            .hero-banner p {
                font-size: 13px;
                line-height: 1.55;
            }

            .stats-grid-3 {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 14px;
                margin-bottom: 22px;
            }

            .stat-card {
                padding: 20px 18px;
                border-radius: 16px;
            }

            .stat-card i {
                font-size: 21px;
                margin-bottom: 11px;
            }

            .stat-card h4 {
                font-size: 10px;
                letter-spacing: 0.6px;
            }

            .stat-card p {
                font-size: 28px;
            }

            .schedule-card {
                border-radius: 16px;
            }

            .schedule-header {
                padding: 18px 16px;
                gap: 12px;
                align-items: flex-start;
                flex-direction: column;
            }

            .schedule-header h3 {
                font-size: 15px;
                line-height: 1.4;
            }

            .btn-view-all {
                width: 100%;
                text-align: center;
            }

            .schedule-list {
                padding: 0 16px;
            }

            .schedule-item {
                display: grid;
                grid-template-columns: 82px 42px minmax(0, 1fr);
                gap: 12px;
                align-items: center;
                padding: 16px 0;
            }

            .time-box {
                width: 100%;
                margin-right: 0;
                font-size: 12px;
                padding: 8px 5px;
            }

            .pet-icon {
                width: 42px;
                height: 42px;
                margin-right: 0;
                border-radius: 11px;
            }

            .schedule-info strong {
                font-size: 14px;
                overflow-wrap: anywhere;
            }

            .schedule-info span {
                font-size: 12px;
                line-height: 1.4;
                display: block;
                overflow-wrap: anywhere;
            }

            .schedule-status {
                grid-column: 1 / -1;
                justify-self: start;
                margin-left: 0;
            }

            footer {
                padding: 24px 16px;
                line-height: 1.5;
            }
        }


        @media (max-width: 900px) {
            .mobile-menu-btn {
                position: fixed;
                top: 12px;
                left: 12px;
                z-index: 1100;
            }

            header {
                padding-left: 66px;
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

            .hero-banner {
                padding: 22px 18px;
            }

            .hero-banner h2 {
                font-size: 20px;
            }

            .stats-grid-3 {
                grid-template-columns: 1fr;
            }

            .stat-card {
                display: grid;
                grid-template-columns: 42px minmax(0, 1fr) auto;
                grid-template-rows: auto auto;
                column-gap: 12px;
                align-items: center;
                padding: 15px 16px;
            }

            .stat-card i {
                grid-row: 1 / 3;
                margin: 0;
            }

            .stat-card h4 {
                margin: 0;
            }

            .stat-card p {
                grid-column: 3;
                grid-row: 1 / 3;
                margin: 0;
                font-size: 25px;
            }

            .schedule-header h3 {
                font-size: 14px;
            }

            .schedule-item {
                grid-template-columns: 72px 40px minmax(0, 1fr);
                gap: 10px;
            }

            .time-box {
                font-size: 11px;
            }

            .pet-icon {
                width: 40px;
                height: 40px;
                font-size: 16px;
            }

            .schedule-info strong {
                font-size: 13px;
            }

            .schedule-info span {
                font-size: 11px;
            }
        }

        @media (max-width: 380px) {
            header {
                padding: 0 10px;
            }

            .breadcrumb {
                font-size: 12px;
            }

            .mobile-menu-btn {
                width: 38px;
                height: 38px;
            }

            .top-avatar,
            .top-avatar-fallback {
                width: 32px;
                height: 32px;
            }

            .schedule-item {
                grid-template-columns: 1fr 40px;
            }

            .time-box {
                grid-column: 1 / -1;
                justify-self: start;
                width: auto;
                min-width: 82px;
            }

            .pet-icon {
                grid-row: 2;
            }

            .schedule-info {
                grid-row: 2;
            }

            .schedule-status {
                grid-column: 1 / -1;
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

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <aside class="sidebar">
        <div class="sidebar-header">
            <img src="../bg.png" alt="Boogie's Logo" class="sidebar-logo-img">
            <h2>Boogie's Clinic</h2>
        </div>
        <nav class="nav-links">
            <a href="staffdashboard.php" class="nav-item active"><i class="fas fa-th-large"></i> Dashboard</a>
            <a href="appointments.php" class="nav-item"><i class="fas fa-calendar-alt"></i> Appointments</a>
            <a href="pets.php" class="nav-item"><i class="fas fa-paw"></i> Patients</a>
            <a href="tasks.php" class="nav-item"><i class="fas fa-tasks"></i> My Tasks</a>
        </nav>
    </aside>

    <main class="main-content">
        <header>
            <div class="topbar-left">
                <button class="mobile-menu-btn" id="mobileMenuBtn" type="button" aria-label="Open menu" aria-expanded="false">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="breadcrumb">
                <i class="fas fa-desktop" style="color: var(--brand-blue);"></i> <span class="breadcrumb-text">Overview / Dashboard</span></div>
            </div>
            
            <div class="top-right-actions">
                <div class="notif-wrapper" onclick="toggleNotif(event)">
                    <i class="fa-solid fa-bell" style="font-size: 20px; color: var(--text-muted);" aria-hidden="true"></i>
                    <span id="staff-notif-badge" class="notif-badge" style="display: <?php echo $unread_count > 0 ? 'inline-block' : 'none'; ?>;"><?php echo (int)$unread_count; ?></span>

                    <div class="notif-dropdown" id="notifBox" onclick="event.stopPropagation()">
                        <div class="notif-header">
                            <span>My Alerts</span>
                            <form method="POST" action="staffdashboard.php" id="mark-read-form" style="display: <?php echo $unread_count > 0 ? 'inline-block' : 'none'; ?>; margin: 0;">
                                <input type="hidden" name="action" value="mark_notifications_read">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                <button type="submit" id="mark-read-link" class="mark-read-btn">Mark all read</button>
                            </form>
                        </div>
                        <div class="notif-body" id="staff-notif-list">
                            <?php echo $staff_notification_html; ?>
                        </div>
                    </div>
                </div>

                <div class="profile-wrapper" onclick="toggleProfile(event)">
                    <div class="role-label">
                        <i class="fas fa-user-md"></i> <?php echo htmlspecialchars($role_label); ?>
                    </div>
                    
                    <?php if (!empty($profile_img_src)): ?>
                        <img src="<?php echo htmlspecialchars($profile_img_src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" class="top-avatar" alt="Profile Picture">
                    <?php else: ?>
                        <div class="top-avatar-fallback"><?php echo htmlspecialchars($first_letter, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                    <?php endif; ?>
                    
                    <span class="profile-name-text desktop-profile-name" style="font-size: 14px; font-weight: 700; color: var(--sidebar-navy); display: flex; align-items: center; gap: 6px;">
                        <?php echo htmlspecialchars($display_with_title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
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
            <div class="hero-banner">
                <h2><?php echo $greeting; ?>, <?php echo htmlspecialchars($display_with_title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>!</h2>
                <p>Welcome back to your clinical overview for today, <?php echo date('l, F j, Y'); ?>.</p>
            </div>

            <div class="stats-grid-3">
                <div class="stat-card" onclick="window.location.href='appointments.php'">
                    <i class="far fa-calendar-alt" style="color: var(--brand-blue);"></i>
                    <h4>Today's Appointments</h4>
                    <p><?php echo $today_count; ?></p>
                </div>
                <div class="stat-card" onclick="window.location.href='appointments.php?status=Completed'">
                    <i class="far fa-check-circle" style="color: #10b981;"></i>
                    <h4>Completed Consultations</h4> 
                    <p><?php echo $completed_count; ?></p>
                </div>
                <div class="stat-card" onclick="window.location.href='pets.php'">
                    <i class="fas fa-paw" style="color: #f43f5e;"></i>
                    <h4>Active Patients</h4>
                    <p><?php echo $pet_count; ?></p>
                </div>
            </div>

            <div class="schedule-card">
                <div class="schedule-header">
                    <h3><i class="far fa-clock" style="color: var(--brand-blue); margin-right: 10px;"></i> Upcoming Patients Today</h3>
                    <a href="appointments.php" class="btn-view-all">View Full Schedule</a>
                </div>
                
                <div class="schedule-list">
                    <?php if (!empty($today_schedule)): ?>
                        <?php foreach($today_schedule as $appt): 
                            $status = $appt['booking_status'] ?? 'Pending';
                            $s_class = 'st-pending';
                            if (strtolower($status) == 'confirmed') $s_class = 'st-confirmed';
                            elseif (strtolower($status) == 'completed') $s_class = 'st-completed';
                            elseif (strtolower($status) == 'for rescheduling' || strtolower($status) == 'rescheduled') $s_class = 'st-rescheduled';
                            elseif (strtolower($status) == 'no-show') $s_class = 'st-no-show';
                            elseif (strtolower($status) == 'cancelled') $s_class = 'st-cancelled';
                        ?>
                            <div class="schedule-item">
                                <div class="time-box">
                                    <?php $appointmentTimestamp = !empty($appt['appointment_time']) ? strtotime((string)$appt['appointment_time']) : false; echo $appointmentTimestamp !== false ? date('h:i A', $appointmentTimestamp) : 'Time TBD'; ?>
                                </div>
                                <div class="pet-icon">
                                    <i class="fas fa-dog"></i>
                                </div>
                                <div class="schedule-info">
                                    <strong><?php echo htmlspecialchars($appt['pet_name'] ?? 'Unknown Pet'); ?></strong>
                                    <span>Service: <?php echo htmlspecialchars((string)($appt['service'] ?? 'Vet Services'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                </div>
                                <div>
                                    <span class="schedule-status <?php echo $s_class; ?>"><?php echo htmlspecialchars((string)$status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 60px 0; color: #94a3b8;">
                            <i class="fas fa-calendar-day" style="font-size: 40px; margin-bottom: 15px; opacity: 0.2;"></i>
                            <p style="font-weight: 500;">No medical appointments scheduled for today.</p>
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
                if (window.innerWidth <= 900) closeMobileMenu();
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
        window.onclick = function(event) {
            if (!event.target.closest('.notif-wrapper')) {
                document.getElementById("notifBox").classList.remove("show");
            }
            if (!event.target.closest('.profile-wrapper')) {
                document.getElementById("profileBox").classList.remove("show");
            }
        }
        

        window.addEventListener('resize', function() {
            if (window.innerWidth > 900) {
                closeMobileMenu();
            }
        });

        function fetchStaffNotifs() {
            fetch('staffdashboard.php?ajax=notifications', {
                method: 'GET',
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Notification request failed: HTTP ' + response.status);
                }
                return response.json();
            })
            .then(function (data) {
                if (!data || data.success !== true) return;

                const badge = document.getElementById('staff-notif-badge');
                const notifList = document.getElementById('staff-notif-list');
                const markReadForm = document.getElementById('mark-read-form');
                if (!badge || !notifList) return;

                const unread = Math.max(0, Number(data.unread) || 0);
                badge.textContent = String(unread);
                badge.style.display = unread > 0 ? 'inline-block' : 'none';
                if (markReadForm) {
                    markReadForm.style.display = unread > 0 ? 'inline-block' : 'none';
                }
                notifList.innerHTML = data.html || '<div class="notif-empty">No new clinic alerts.</div>';
            })
            .catch(function (error) {
                console.error('Error fetching staff notifications:', error);
            });
        }

        fetchStaffNotifs();
        window.setInterval(fetchStaffNotifs, 15000);
    </script>
</body>
</html>