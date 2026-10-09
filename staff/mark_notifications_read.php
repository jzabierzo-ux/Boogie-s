<?php
session_start();
require_once '../db_supabase.php';

// Normalize the session flag but keep this endpoint restricted to veterinary staff.
$current_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$current_role = preg_replace('/[\s_-]+/', ' ', $current_role);
$login_flag = $_SESSION['logged_in'] ?? false;
$is_logged_in = ($login_flag === true || $login_flag === 1 || $login_flag === '1' ||
    (is_string($login_flag) && strtolower(trim($login_flag)) === 'true'));
$is_vet_role = in_array($current_role, ['vet', 'veterinarian'], true);

if (!$is_logged_in || !$is_vet_role) {
    header("Location: stafflogin.php");
    exit();
}

$user_id = (int)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? $_SESSION['id'] ?? 0);

// Return only to an approved page in the current /staff/ directory.
$allowed_pages = [
    'staffdashboard.php',
    'appointments.php',
    'view_records.php',
    'edit_medical_records.php',
    'pets.php',
    'tasks.php',
    'staff_profile.php',
    'profile.php',
];
$redirect_page = 'staffdashboard.php';

$referer = $_SERVER['HTTP_REFERER'] ?? '';
$current_host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));

if ($referer !== '') {
    $parsed = parse_url($referer);
    $referer_host = strtolower((string)($parsed['host'] ?? ''));
    $referer_path = (string)($parsed['path'] ?? '');

    // Supports local URLs such as /staff/ and deployments nested under /Pets/staff/.
    if ($referer_host !== '' && $referer_host === $current_host &&
        preg_match('~/staff/([^/]+\.php)$~i', $referer_path, $matches)) {
        $candidate = basename($matches[1]);
        if (in_array($candidate, $allowed_pages, true)) {
            $redirect_page = $candidate;
            $query = (string)($parsed['query'] ?? '');
            // Preserve simple query strings such as ?id=12 while rejecting header-control chars.
            if ($query !== '' && preg_match('/^[A-Za-z0-9_.=&%-]*$/', $query)) {
                $redirect_page .= '?' . $query;
            }
        }
    }
}

try {
    // Mark only this signed-in user's staff notifications as read.
    // Do not update every user's/admin's notifications globally.
    if ($user_id > 0) {
        $update_stmt = $pdo->prepare("
            UPDATE notifications
            SET is_read = 1
            WHERE user_id = :user_id
              AND is_read = 0
        ");
        $update_stmt->execute([':user_id' => $user_id]);
    }

    header('Location: ' . $redirect_page);
    exit();
} catch (PDOException $e) {
    error_log('Mark staff notifications read error: ' . $e->getMessage());
    header('Location: ' . $redirect_page);
    exit();
}
?>
