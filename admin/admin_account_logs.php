<?php
session_start();

// Always query fresh audit records; this page may remain open while login happens in another tab.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$current_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$is_admin_logged_in = isset($_SESSION['logged_in'])
    && $_SESSION['logged_in'] === true
    && $current_role === 'admin';

if (!$is_admin_logged_in) {
    header('Location: ../admin_login.php');
    exit();
}

require_once __DIR__ . '/../db_supabase.php';
date_default_timezone_set('Asia/Manila');

/** Safely escape database or request values for HTML output. */
function adminLogEscape($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Format database timestamps in Philippine time.
 * Supabase/PostgreSQL may return timestamp-without-time-zone values without
 * an offset; the account-log timestamps are stored in UTC, so treat those
 * values as UTC before converting them to Asia/Manila (UTC+08:00).
 */
function adminLogFormatDate($value, string $format = 'M d, Y h:i A'): string
{
    if ($value === null || trim((string)$value) === '') {
        return 'Unknown';
    }

    $raw = trim((string)$value);
    $hasExplicitTimezone = (bool)preg_match('/(?:Z|[+-]\d{2}(?::?\d{2})?)$/i', $raw);

    try {
        $sourceTimezone = $hasExplicitTimezone
            ? null
            : new DateTimeZone('UTC');
        $dateTime = new DateTimeImmutable($raw, $sourceTimezone);
        $dateTime = $dateTime->setTimezone(new DateTimeZone('Asia/Manila'));
        return $dateTime->format($format);
    } catch (Throwable $e) {
        error_log('Account log timestamp formatting failed: ' . $e->getMessage());
        return 'Unknown';
    }
}

/**
 * Resolve only profile images stored under the project's uploads directories.
 * The returned path remains relative to this admin page for the browser.
 */
function adminLogSafeProfileImage($storedPath): string
{
    $relativePath = str_replace('\\', '/', trim((string)($storedPath ?? '')));
    if ($relativePath === '') {
        return '';
    }

    $allowedPrefix = (strpos($relativePath, 'uploads/') === 0)
        || (strpos($relativePath, '../uploads/') === 0);
    if (!$allowedPrefix) {
        return '';
    }

    $resolvedPath = realpath(__DIR__ . '/' . $relativePath);
    if ($resolvedPath === false || !is_file($resolvedPath)) {
        return '';
    }

    $allowedRoots = [
        realpath(__DIR__ . '/uploads'),
        realpath(__DIR__ . '/../uploads'),
    ];

    foreach ($allowedRoots as $root) {
        if ($root === false) {
            continue;
        }

        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strpos($resolvedPath, $rootPrefix) === 0) {
            return $relativePath;
        }
    }

    return '';
}

/* Current administrator data for the shared top bar. */
$admin_full_name = trim((string)($_SESSION['user_name'] ?? 'User'));
if ($admin_full_name === '') {
    $admin_full_name = 'User';
}
$profile_img_path = '';

try {
    if (isset($_SESSION['user_id'])) {
        $uid = (int)$_SESSION['user_id'];
        if ($uid > 0) {
            $get_admin = $pdo->prepare(
                'SELECT full_name, profile_image FROM users WHERE id = :id LIMIT 1'
            );
            $get_admin->execute([':id' => $uid]);
            $admin_data = $get_admin->fetch(PDO::FETCH_ASSOC);

            if ($admin_data) {
                $candidateName = trim((string)($admin_data['full_name'] ?? ''));
                if ($candidateName !== '') {
                    $admin_full_name = $candidateName;
                    $_SESSION['user_name'] = $admin_full_name;
                }
                $profile_img_path = (string)($admin_data['profile_image'] ?? '');
            }
        }
    }
} catch (PDOException $e) {
    error_log('Admin account logs profile lookup failed: ' . $e->getMessage());
}

$profile_img_src = adminLogSafeProfileImage($profile_img_path);
$nameParts = preg_split('/\s+/', trim($admin_full_name));
$first_name = (string)($nameParts[0] ?? 'U');

/* Read and constrain filters. */
$searchRaw = $_GET['search'] ?? '';
$search = is_string($searchRaw) ? trim(substr($searchRaw, 0, 150)) : '';
$statusRaw = $_GET['status'] ?? 'all';
$status_filter = is_string($statusRaw) ? strtoupper(trim($statusRaw)) : 'ALL';
if (!in_array($status_filter, ['ALL', 'SUCCESS', 'FAILED'], true)) {
    $status_filter = 'ALL';
}

$rows = [];
$total_logs = 0;
$success_logs = 0;
$failed_logs = 0;
$load_error = '';

/* Build and execute the account-log query with PostgreSQL-compatible filters. */
$query = '
    SELECT
        aal.id,
        aal.user_id,
        aal.action,
        aal.status,
        aal.ip_address,
        aal.user_agent,
        aal.created_at,
        u.full_name,
        u.email,
        u.role
    FROM admin_account_logs aal
    LEFT JOIN users u ON u.id = aal.user_id
    WHERE TRUE
      AND (u.id IS NULL OR LOWER(TRIM(COALESCE(u.role, \'\'))) <> \'customer\')
';
$params = [];

if ($search !== '') {
    $query .= '
        AND (
            u.full_name ILIKE :search_name
            OR u.email ILIKE :search_email
            OR CAST(aal.ip_address AS TEXT) ILIKE :search_ip
            OR aal.action ILIKE :search_action
            OR aal.status ILIKE :search_status
        )
    ';
    $searchPattern = '%' . $search . '%';
    $params = [
        ':search_name' => $searchPattern,
        ':search_email' => $searchPattern,
        ':search_ip' => $searchPattern,
        ':search_action' => $searchPattern,
        ':search_status' => $searchPattern,
    ];
}

if ($status_filter === 'SUCCESS' || $status_filter === 'FAILED') {
    $query .= ' AND aal.status = :status';
    $params[':status'] = $status_filter;
}
$query .= ' ORDER BY aal.created_at DESC NULLS LAST, aal.id DESC';

try {
    $result = $pdo->prepare($query);
    $result->execute($params);
    $rows = $result->fetchAll(PDO::FETCH_ASSOC);

    $count_stmt = $pdo->query("
        SELECT
            COUNT(*) AS total_logs,
            COUNT(*) FILTER (WHERE aal.status = 'SUCCESS' AND aal.action = 'LOGIN') AS success_logs,
            COUNT(*) FILTER (WHERE aal.status = 'FAILED' AND aal.action = 'LOGIN') AS failed_logs
        FROM admin_account_logs aal
        LEFT JOIN users u ON u.id = aal.user_id
        WHERE u.id IS NULL OR LOWER(TRIM(COALESCE(u.role, ''))) <> 'customer'
    ");
    $count_data = $count_stmt->fetch(PDO::FETCH_ASSOC);

    if ($count_data) {
        $total_logs = (int)($count_data['total_logs'] ?? 0);
        $success_logs = (int)($count_data['success_logs'] ?? 0);
        $failed_logs = (int)($count_data['failed_logs'] ?? 0);
    }
} catch (PDOException $e) {
    error_log('Admin account logs query failed: ' . $e->getMessage());
    $load_error = 'Account logs could not be loaded right now. Please try again later.';
}

/* Admin notifications only; staff notifications use their own portal. */
$admin_notifications = [];
$unread_count = 0;
try {
    $admin_notif_stmt = $pdo->prepare('
        SELECT id, message, created_at
        FROM admin_notifications
        WHERE is_read = 0
        ORDER BY created_at DESC, id DESC
        LIMIT 20
    ');
    $admin_notif_stmt->execute();
    $admin_notifications = $admin_notif_stmt->fetchAll(PDO::FETCH_ASSOC);
    $unread_count = count($admin_notifications);
} catch (PDOException $e) {
    error_log('Admin account logs notification query failed: ' . $e->getMessage());
    $admin_notifications = [];
    $unread_count = 0;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta

        name="viewport"

        content="width=device-width, initial-scale=1.0"

    >

    <title>Account Logs | Boogie's Pet Care</title>

    <link

        rel="stylesheet"

        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"

    >

    <style>

        * {

            box-sizing: border-box;

        }

        body {

            margin: 0;

            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;

            background: #f8fafc;

            color: #1e293b;

        }

        .page {

            padding: 30px;

            max-width: 1500px;

            margin: auto;

        }

        .top-bar {

            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 20px;

            margin-bottom: 25px;

            flex-wrap: wrap;

        }

        .title-area h1 {

            margin: 0;

            font-size: 28px;

            color: #0f172a;

        }

        .title-area p {

            margin: 6px 0 0;

            color: #64748b;

            font-size: 14px;

        }

        .back-btn {

            display: inline-flex;

            align-items: center;

            gap: 8px;

            padding: 11px 16px;

            border-radius: 8px;

            background: #0f172a;

            color: white;

            text-decoration: none;

            font-weight: 600;

            font-size: 14px;

        }

        .back-btn:hover {

            opacity: 0.9;

        }

        /*

        ------------------------------------------

        SUMMARY CARDS

        ------------------------------------------

        */

        .summary-grid {

            display: grid;

            grid-template-columns: repeat(3, 1fr);

            gap: 18px;

            margin-bottom: 25px;

        }

        .summary-card {

            background: white;

            border-radius: 12px;

            padding: 20px;

            box-shadow: 0 2px 10px rgba(15, 23, 42, 0.06);

            border: 1px solid #e2e8f0;

        }

        .summary-label {

            color: #64748b;

            font-size: 13px;

            font-weight: 600;

            margin-bottom: 7px;

        }

        .summary-number {

            font-size: 28px;

            font-weight: 800;

            color: #0f172a;

        }

        /*

        ------------------------------------------

        FILTER AREA

        ------------------------------------------

        */

        .filter-card {

            background: white;

            border: 1px solid #e2e8f0;

            border-radius: 12px;

            padding: 18px;

            margin-bottom: 20px;

        }

        .filter-form {

            display: flex;

            gap: 10px;

            flex-wrap: wrap;

        }

        .search-input,

        .status-select {

            padding: 11px 13px;

            border: 1px solid #cbd5e1;

            border-radius: 8px;

            font-size: 14px;

            outline: none;

            background: white;

        }

        .search-input {

            flex: 1;

            min-width: 240px;

        }

        .search-input:focus,

        .status-select:focus {

            border-color: #6366f1;

        }

        .filter-btn {

            border: none;

            background: #6366f1;

            color: white;

            padding: 11px 18px;

            border-radius: 8px;

            font-weight: 700;

            cursor: pointer;

        }

        .clear-btn {

            display: inline-flex;

            align-items: center;

            padding: 11px 18px;

            border-radius: 8px;

            background: #e2e8f0;

            color: #334155;

            text-decoration: none;

            font-weight: 700;

            font-size: 14px;

        }

        /*

        ------------------------------------------

        TABLE

        ------------------------------------------

        */

        .table-card {

            background: white;

            border: 1px solid #e2e8f0;

            border-radius: 12px;

            overflow: hidden;

            box-shadow: 0 2px 10px rgba(15, 23, 42, 0.05);

        }

        .table-wrapper {

            overflow-x: auto;

        }

        table {

            width: 100%;

            border-collapse: collapse;

            min-width: 950px;

        }

        th {

            background: #0f172a;

            color: white;

            text-align: left;

            padding: 14px 16px;

            font-size: 12px;

            text-transform: uppercase;

            letter-spacing: 0.4px;

        }

        td {

            padding: 14px 16px;

            border-bottom: 1px solid #e2e8f0;

            font-size: 13px;

            vertical-align: middle;

        }

        tr:hover td {

            background: #f8fafc;

        }

        .user-name {

            font-weight: 700;

            color: #0f172a;

        }

        .user-email {

            font-size: 11px;

            color: #64748b;

            margin-top: 2px;

        }

        .role-badge {

            display: inline-block;

            padding: 5px 9px;

            border-radius: 20px;

            background: #ede9fe;

            color: #5b21b6;

            font-size: 11px;

            font-weight: 700;

            text-transform: uppercase;

        }

        .action-badge {

            display: inline-block;

            padding: 5px 9px;

            border-radius: 20px;

            background: #e0f2fe;

            color: #0369a1;

            font-size: 11px;

            font-weight: 700;

        }

        .status-badge {

            display: inline-block;

            padding: 5px 10px;

            border-radius: 20px;

            font-size: 11px;

            font-weight: 800;

        }

        .status-success {

            background: #dcfce7;

            color: #166534;

        }

        .status-failed {

            background: #fee2e2;

            color: #b91c1c;

        }

        .ip-address {

            font-family: Consolas, monospace;

            color: #475569;

            font-size: 12px;

        }

        .date-time {

            white-space: nowrap;

        }

        .browser-info {

            max-width: 250px;

            white-space: nowrap;

            overflow: hidden;

            text-overflow: ellipsis;

            color: #64748b;

            font-size: 11px;

        }

        .error-banner {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
            border-left: 4px solid #dc2626;
            border-radius: 10px;
            padding: 14px 16px;
            margin-bottom: 20px;
            font-size: 14px;
            line-height: 1.5;
        }

        .status-unknown {
            background: #e2e8f0;
            color: #334155;
        }

        .empty-state {

            padding: 60px 20px;

            text-align: center;

            color: #64748b;

        }

        .empty-state i {

            font-size: 42px;

            margin-bottom: 12px;

            color: #94a3b8;

        }

        /* ------------------------------------------

           ADMIN NOTIFICATIONS

           ------------------------------------------ */

        .top-actions {

            display: flex;

            align-items: center;

            gap: 10px;

        }

        .notif-wrapper {

            position: relative;

            display: inline-flex;

            align-items: center;

            cursor: pointer;

        }

        .notif-bell-btn {

            width: 42px;

            height: 42px;

            border: 1px solid #e2e8f0;

            border-radius: 8px;

            background: white;

            color: #475569;

            display: inline-flex;

            align-items: center;

            justify-content: center;

            cursor: pointer;

        }

        .notif-bell-btn:hover {

            background: #f8fafc;

        }

        .notif-badge {

            position: absolute;

            top: -5px;

            right: -5px;

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

            line-height: 18px;

        }

        .notif-dropdown {

            display: none;

            position: absolute;

            right: 0;

            top: 48px;

            width: 320px;

            background: white;

            border: 1px solid #e2e8f0;

            box-shadow: 0 10px 20px rgba(15, 23, 42, 0.12);

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

        /*

        ------------------------------------------

        RESPONSIVE

        ------------------------------------------

        */

        @media (max-width: 900px) {

            .summary-grid {

                grid-template-columns: 1fr;

            }

            .page {

                padding: 20px;

            }

        }

        /* ===== RESPONSIVE ADMIN LAYOUT (same pattern as managepet.php) ===== */

        .topbar-left { display:flex; align-items:center; gap:12px; min-width:0; }

        .mobile-menu-toggle { display:none; border:0; background:var(--navy-dark); color:var(--brand-yellow); width:40px; height:40px; border-radius:10px; cursor:pointer; font-size:18px; align-items:center; justify-content:center; flex:0 0 auto; }

        .sidebar-backdrop { display:none; }

        .page-header-shell { display:flex; justify-content:space-between; align-items:flex-start; gap:20px; margin-bottom:25px; }

        .profile-name { font-size:14px; font-weight:600; color:#4a5568; display:flex; align-items:center; gap:6px; }

        .profile-name i { font-size:10px; color:#94a3b8; }

        /* Shared admin shell */

        :root { --navy-dark:#001f3f; --brand-yellow:#ffcc00; }

        body { overflow-x:hidden; }

        aside { width:260px; background:var(--navy-dark); color:#fff; display:flex; flex-direction:column; position:fixed; height:100vh; z-index:100; }

        .sidebar-header { padding:30px 20px; text-align:center; border-bottom:1px solid rgba(255,255,255,.05); }

        .sidebar-logo { width:80px; height:auto; object-fit:contain; margin-bottom:10px; }

        .sidebar-header h2 { font-size:16px; color:var(--brand-yellow); text-transform:uppercase; letter-spacing:1px; font-weight:800; }

        .nav-links { flex-grow:1; padding:20px 15px; display:flex; flex-direction:column; gap:5px; }

        .nav-item { display:flex; align-items:center; padding:14px 20px; color:#94a3b8; text-decoration:none; transition:all .3s ease; font-size:14px; font-weight:500; border-radius:10px; position:relative; }

        .nav-item i { width:32px; font-size:18px; }

        .nav-item:hover { color:#fff; background:rgba(255,255,255,.05); transform:translateX(4px); }

        .nav-item.active { color:var(--brand-yellow); background:rgba(255,204,0,.08); font-weight:700; }

        .nav-item.active::before { content:''; position:absolute; left:-15px; top:15%; height:70%; width:5px; background:var(--brand-yellow); border-radius:0 5px 5px 0; box-shadow:2px 0 8px rgba(255,204,0,.5); }

        main { margin-left:260px; flex-grow:1; display:flex; flex-direction:column; min-height:100vh; }

        .top-bar { background:#fff; height:70px; padding:0 40px; display:flex; justify-content:space-between; align-items:center; box-shadow:0 1px 10px rgba(0,0,0,.08); position:sticky; top:0; z-index:1000; }

        .breadcrumb { font-weight:700; color:var(--navy-dark); font-size:15px; display:flex; align-items:center; gap:8px; }

        .top-right-actions { display:flex; align-items:center; gap:20px; }

        .notif-wrapper { position:relative; display:inline-flex; align-items:center; cursor:pointer; }

        .notif-badge { position:absolute; top:-5px; right:-8px; background:#e11d48; color:#fff; border-radius:50%; padding:2px 6px; font-size:10px; font-weight:bold; }

        .profile-wrapper { position:relative; display:inline-flex; align-items:center; gap:12px; border-left:1px solid #e2e8f0; padding-left:20px; cursor:pointer; user-select:none; }

        .admin-tag { background:var(--navy-dark); color:var(--brand-yellow); padding:6px 16px; border-radius:50px; font-size:10px; font-weight:800; letter-spacing:1px; text-transform:uppercase; border:1px solid var(--brand-yellow); }

        .top-avatar { width:35px; height:35px; border-radius:50%; object-fit:cover; border:2px solid var(--navy-dark); }

        .top-avatar-fallback { width:35px; height:35px; border-radius:50%; background:linear-gradient(135deg,var(--navy-dark),#003366); color:var(--brand-yellow); display:flex; align-items:center; justify-content:center; font-weight:bold; font-size:14px; border:2px solid var(--brand-yellow); box-shadow:0 2px 4px rgba(0,0,0,.1); }

        .notif-dropdown,.profile-dropdown { z-index:3000; }

        .profile-dropdown { display:none; position:absolute; right:0; top:45px; width:200px; background:#fff; border:1px solid #e2e8f0; box-shadow:0 10px 15px -3px rgba(0,0,0,.1); border-radius:8px; overflow:hidden; text-align:left; }

        .profile-dropdown.show { display:block; }

        .profile-item { padding:12px 15px; display:flex; align-items:center; gap:10px; font-size:13px; color:#2d3436; text-decoration:none; }

        .profile-item:hover { background:#f1f5f9; color:var(--navy-dark); }

        .profile-item.logout-text { color:#e11d48; border-top:1px solid #f1f5f9; }

        .profile-item.logout-text:hover { background:#fff1f2; color:#be123c; }

        .container { width:100%; }

        @media (max-width:900px) {

            aside { transform:translateX(-100%); transition:transform .25s ease; box-shadow:12px 0 30px rgba(0,0,0,.18); z-index:2000; }

            aside.mobile-open { transform:translateX(0); }

            .sidebar-backdrop { position:fixed; inset:0; background:rgba(0,31,63,.48); z-index:1900; }

            .sidebar-backdrop.show { display:block; }

            main { margin-left:0; width:100%; min-width:0; }

            .top-bar { height:64px; padding:0 14px; gap:10px; }

            .mobile-menu-toggle { display:inline-flex; }

            .breadcrumb { font-size:13px; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

            .top-right-actions { gap:10px; margin-left:auto; min-width:0; }

            .profile-wrapper { gap:8px; padding-left:10px; }

            .profile-name { max-width:110px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:12px; }

            .admin-tag { padding:5px 10px; font-size:9px; }

            .top-avatar,.top-avatar-fallback { width:32px; height:32px; }

            .page { padding:24px 16px 30px; max-width:none; }

            .page-header-shell { flex-direction:column; align-items:stretch; gap:14px; }

            .title-area h1 { font-size:22px; line-height:1.2; }

            .title-area p { font-size:13px; line-height:1.45; }

            .back-btn { width:100%; justify-content:center; }

            .summary-grid { grid-template-columns:1fr; gap:12px; }

            .summary-card { padding:18px; border-radius:14px; }

            .summary-number { font-size:27px; }

            .filter-card { padding:14px 16px; }

            .filter-form { display:grid; grid-template-columns:1fr; gap:10px; align-items:stretch; }

            .search-input,.status-select { width:100%; min-width:0; }

            .filter-btn,.clear-btn { width:100%; min-height:44px; justify-content:center; display:inline-flex; align-items:center; }

            .table-card { border-radius:14px; }

            .table-wrapper { overflow-x:auto; -webkit-overflow-scrolling:touch; }

            table { min-width:950px; }

        }

        @media (max-width:560px) {

            .top-bar { height:60px; padding:0 10px; }

            .topbar-left { gap:8px; }

            .mobile-menu-toggle { width:36px; height:36px; border-radius:9px; font-size:16px; }

            .breadcrumb { font-size:11px; }

            .top-right-actions { gap:6px; }

            .notif-wrapper > i { font-size:20px !important; }

            .profile-wrapper { gap:6px; padding-left:7px; }

            .profile-name { display:none; }

            .admin-tag { padding:4px 8px; font-size:8px; letter-spacing:.7px; }

            .top-avatar,.top-avatar-fallback { width:30px; height:30px; }

            .page { padding:18px 12px 24px; }

            .title-area h1 { font-size:20px; }

            .title-area p { font-size:12px; }

            .filter-card { padding:12px; }

            .summary-card { padding:17px; }

        }

    </style>

</head>

<body>

    <aside>

        <div class="sidebar-header">

            <img src="bg.png" alt="Boogie's Logo" class="sidebar-logo">

            <h2><?php echo (isset($_SESSION['role']) && strtolower($_SESSION['role']) === 'admin') ? "Boogie's Admin" : "Boogie's Staff"; ?></h2>

        </div>

        <nav class="nav-links">

            <a href="admindashboard.php" class="nav-item"><i class="fas fa-chart-line"></i> Dashboard</a>

            <a href="managebooking.php" class="nav-item"><i class="fas fa-calendar-alt"></i> Bookings</a>

            <a href="manageusers.php" class="nav-item"><i class="fas fa-users"></i> Users</a>

            <a href="managepet.php" class="nav-item"><i class="fas fa-dog"></i> Pets</a>

            <?php if (isset($_SESSION['role']) && strtolower($_SESSION['role']) === 'admin'): ?>

                <a href="managestaff.php" class="nav-item"><i class="fas fa-id-badge"></i> Personnel</a>

                <a href="managepromo.php" class="nav-item"><i class="fas fa-tags"></i> Promos</a>

                <a href="manage_services.php" class="nav-item"><i class="fas fa-list-ul"></i> Pricelist</a>

                <a href="sales_report.php" class="nav-item"><i class="fas fa-file-invoice-dollar"></i> Sales Report</a>

                <a href="admin_account_logs.php" class="nav-item active"><i class="fa-solid fa-clock-rotate-left"></i> Account Logs</a>

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

                    <i class="fa-solid fa-clock-rotate-left" style="opacity:0.5; font-size:14px;"></i>

                    Management / Account Logs

                </div>

            </div>

            <div class="top-right-actions">

                <div class="notif-wrapper" onclick="toggleNotif(event)">

                    <i class="fa-solid fa-bell" style="font-size:22px; color:#64748b;"></i>

                    <span id="admin-notif-badge" class="notif-badge" style="display: <?php echo $unread_count > 0 ? 'inline-flex' : 'none'; ?>;">

                        <?php echo $unread_count; ?>

                    </span>

                    <div class="notif-dropdown" id="notifBox" onclick="event.stopPropagation()">

                        <div class="notif-header">

                            Alerts

                            <a href="mark_notifications_read.php" id="mark-read-link" class="mark-read-btn" style="display: <?php echo $unread_count > 0 ? 'inline-block' : 'none'; ?>;">Mark all read</a>

                        </div>

                        <div class="notif-body" id="admin-notif-list">

                            <?php if ($unread_count > 0): ?>

                                <?php foreach ($admin_notifications as $notif): ?>

                                    <div class="notif-item">

                                        <i class="fa-solid fa-circle-exclamation" style="color:#e11d48; margin-right:5px;"></i>

                                        <?php echo adminLogEscape($notif['message'] ?? ''); ?>

                                        <br><small style="color:#94a3b8;font-size:11px;">

                                            <?php echo !empty($notif['created_at']) ? adminLogEscape(adminLogFormatDate($notif['created_at'], 'M d, g:i A')) : ''; ?>

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

                    <span class="admin-tag"><?php echo strtoupper($current_role); ?></span>

                    <?php if ($profile_img_src !== ''): ?>
                        <img src="<?php echo adminLogEscape($profile_img_src); ?>" class="top-avatar" alt="Profile Picture">
                    <?php else: ?>

                        <div class="top-avatar-fallback"><?php echo adminLogEscape(strtoupper(substr($first_name, 0, 1))); ?></div>

                    <?php endif; ?>

                    <span class="profile-name">

                        <?php echo adminLogEscape($admin_full_name); ?>

                        <i class="fas fa-chevron-down"></i>

                    </span>

                    <div class="profile-dropdown" id="profileBox" onclick="event.stopPropagation()">

                        <a href="admin_profile.php" class="profile-item"><i class="fas fa-user-circle"></i> My Profile</a>

                        <a href="../logout.php" class="profile-item logout-text"><i class="fas fa-sign-out-alt"></i> Logout</a>

                    </div>

                </div>

            </div>

        </header>

        <div class="page">

            <div class="page-header-shell">

                <div class="title-area">

                    <h1><i class="fa-solid fa-clock-rotate-left"></i> Account Logs</h1>

                    <p>Monitor administrator and personnel sign-in activity and access attempts.</p>

                </div>

                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <a href="admin_account_logs.php<?php echo $search !== '' || $status_filter !== 'ALL' ? '?' . http_build_query(array_filter(['search' => $search, 'status' => $status_filter !== 'ALL' ? $status_filter : null], static fn($value) => $value !== null && $value !== '')) : ''; ?>" class="clear-btn" style="display:inline-flex;align-items:center;gap:8px;margin:0;">
                        <i class="fa-solid fa-rotate"></i> Refresh Logs
                    </a>
                    <a href="admindashboard.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Back to Dashboard</a>
                </div>

            </div>

            <?php if ($load_error !== ''): ?>
                <div class="error-banner" role="alert">
                    <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                    <?php echo adminLogEscape($load_error); ?>
                </div>
            <?php endif; ?>

            <!-- SUMMARY -->

    <div class="summary-grid">

        <div class="summary-card">

            <div class="summary-label">

                TOTAL LOGS

            </div>

            <div class="summary-number">

                <?php echo number_format($total_logs); ?>

            </div>

        </div>

        <div class="summary-card">

            <div class="summary-label">

                SUCCESSFUL LOGINS

            </div>

            <div class="summary-number">

                <?php echo number_format($success_logs); ?>

            </div>

        </div>

        <div class="summary-card">

            <div class="summary-label">

                FAILED ATTEMPTS

            </div>

            <div class="summary-number">

                <?php echo number_format($failed_logs); ?>

            </div>

        </div>

    </div>

    <!-- FILTER -->

    <div class="filter-card">

        <form

            method="GET"

            class="filter-form"

        >

            <input

                type="text"

                name="search"

                class="search-input"

                placeholder="Search name, email, IP, action..."

                value="<?php echo adminLogEscape($search); ?>"

            >

            <select

                name="status"

                class="status-select"

            >

                <option value="all"

                    <?php echo $status_filter === 'ALL' ? 'selected' : ''; ?>

                >

                    All Status

                </option>

                <option value="SUCCESS"

                    <?php echo $status_filter === 'SUCCESS' ? 'selected' : ''; ?>

                >

                    Successful

                </option>

                <option value="FAILED"

                    <?php echo $status_filter === 'FAILED' ? 'selected' : ''; ?>

                >

                    Failed

                </option>

            </select>

            <button

                type="submit"

                class="filter-btn"

            >

                <i class="fa-solid fa-magnifying-glass"></i>

                Search

            </button>

            <a

                href="?"

                class="clear-btn"

                title="Clear search and status filters"

            >

                <i class="fa-solid fa-rotate-left"></i>

                Clear

            </a>

        </form>

    </div>

    <!-- TABLE -->

    <div class="table-card">

        <div class="table-wrapper">

            <?php if (!empty($rows)): ?>

                <table>

                    <thead>

                        <tr>

                            <th>#</th>

                            <th>Account</th>

                            <th>Role</th>

                            <th>Action</th>

                            <th>Status</th>

                            <th>IP Address</th>

                            <th>Date & Time</th>

                            <th>Browser</th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach ($rows as $row): ?>

                            <tr>

                                <td>

                                    <?php echo (int)$row['id']; ?>

                                </td>

                                <td>

                                    <?php if (!empty($row['full_name'])): ?>

                                        <div class="user-name">

                                            <?php

                                            echo adminLogEscape($row['full_name']);

                                            ?>

                                        </div>

                                        <div class="user-email">

                                            <?php

                                            echo adminLogEscape($row['email'] ?? '');

                                            ?>

                                        </div>

                                    <?php else: ?>

                                        <div class="user-name">

                                            Unknown Account

                                        </div>

                                        <div class="user-email">

                                            Unrecognized login attempt

                                        </div>

                                    <?php endif; ?>

                                </td>

                                <td>

                                    <?php if (!empty($row['role'])): ?>

                                        <span class="role-badge">

                                            <?php

                                            echo adminLogEscape($row['role']);

                                            ?>

                                        </span>

                                    <?php else: ?>

                                        <span style="color:#94a3b8;">

                                            N/A

                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td>

                                    <span class="action-badge">

                                        <?php

                                        echo adminLogEscape($row['action']);

                                        ?>

                                    </span>

                                </td>

                                <td>

                                    <?php $row_status = strtoupper(trim((string)($row['status'] ?? 'UNKNOWN'))); ?>
                                    <?php if ($row_status === 'SUCCESS'): ?>
                                        <span class="status-badge status-success"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> SUCCESS</span>
                                    <?php elseif ($row_status === 'FAILED'): ?>
                                        <span class="status-badge status-failed"><i class="fa-solid fa-circle-xmark" aria-hidden="true"></i> FAILED</span>
                                    <?php else: ?>
                                        <span class="status-badge status-unknown"><?php echo adminLogEscape($row_status); ?></span>
                                    <?php endif; ?>

                                </td>

                                <td>

                                    <span class="ip-address">

                                        <?php

                                        echo adminLogEscape($row['ip_address'] ?? 'UNKNOWN');

                                        ?>

                                    </span>

                                </td>

                                <td class="date-time">

                                    <?php

                                    echo adminLogEscape(adminLogFormatDate($row['created_at'] ?? null));

                                    ?>

                                </td>

                                <td>

                                    <div

                                        class="browser-info"

                                        title="<?php

                                            echo adminLogEscape($row['user_agent'] ?? '');

                                        ?>"

                                    >

                                        <?php

                                        echo adminLogEscape($row['user_agent'] ?? 'Unknown');

                                        ?>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            <?php elseif ($load_error !== ''): ?>

                <div class="empty-state">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <h3>Account Logs Unavailable</h3>
                    <p>The records could not be retrieved. Please refresh the page later.</p>
                </div>

            <?php else: ?>

                <div class="empty-state">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                    <h3>No Account Logs Found</h3>
                    <p>No account activity has been recorded yet.</p>
                </div>

            <?php endif; ?>

        </div>

    </div>

        </div>

    </main>

    <script>

(function () {

    function renderAdminNotifications(data) {

        const badge = document.getElementById('admin-notif-badge');

        const notifList = document.getElementById('admin-notif-list');

        const markReadBtn = document.getElementById('mark-read-link');

        if (!badge || !notifList) return;

        const unread = Number(

            data && data.unread ? data.unread : 0

        );

        badge.style.display = unread > 0

            ? 'inline-flex'

            : 'none';

        badge.textContent = unread;

        if (markReadBtn) {

            markReadBtn.style.display = unread > 0

                ? 'inline-block'

                : 'none';

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

        .then(function (response) {

            if (!response.ok) {

                throw new Error(

                    'Notification request failed: HTTP ' +

                    response.status

                );

            }

            return response.json();

        })

        .then(renderAdminNotifications)

        .catch(function (error) {

            console.error(

                'Error fetching admin notifications:',

                error

            );

        });

    }

    function toggleNotif(event) {

        event.stopPropagation();

        const notifBox =

            document.getElementById('notifBox');

        if (notifBox) {

            notifBox.classList.toggle('show');

        }

    }

    window.toggleNotif = toggleNotif;

    document.addEventListener(

        'click',

        function (event) {

            if (!event.target.closest('.notif-wrapper')) {

                const notifBox =

                    document.getElementById('notifBox');

                if (

                    notifBox &&

                    notifBox.classList.contains('show')

                ) {

                    notifBox.classList.remove('show');

                }

            }

        }

    );

    document.addEventListener(

        'DOMContentLoaded',

        function () {

            fetchAdminNotifs();

            setInterval(fetchAdminNotifs, 3000);

        }

    );

})();

        // --- MOBILE SIDEBAR ---

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

        function toggleProfile(event) {

            event.stopPropagation();

            const profileBox = document.getElementById('profileBox');

            const notifBox = document.getElementById('notifBox');

            if (profileBox) profileBox.classList.toggle('show');

            if (notifBox) notifBox.classList.remove('show');

        }

        document.addEventListener('keydown', function(event) {

            if (event.key === 'Escape') closeMobileSidebar();

        });

        document.querySelectorAll('.nav-item').forEach(function(link) {

            link.addEventListener('click', function() {

                if (window.innerWidth <= 900) closeMobileSidebar();

            });

        });

        document.addEventListener('click', function(event) {

            if (!event.target.closest('.profile-wrapper')) {

                const profileBox = document.getElementById('profileBox');

                if (profileBox) profileBox.classList.remove('show');

            }

        });

    // Reload current log rows when returning to this browser tab,
    // so sign-ins made in another tab appear without requiring a manual refresh.
    (function refreshAccountLogsOnTabReturn() {
        let wasHidden = document.hidden;
        document.addEventListener('visibilitychange', function () {
            if (wasHidden && !document.hidden) {
                const active = document.activeElement;
                const editingFilter = active && active.closest && active.closest('.filter-form');
                if (!editingFilter) window.location.reload();
            }
            wasHidden = document.hidden;
        });
    })();
</script>

</body>

</html>
