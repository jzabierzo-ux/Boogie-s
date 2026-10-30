<?php
require_once __DIR__ . '/../shared_session_bootstrap.php';

date_default_timezone_set('Asia/Manila');

// This page belongs only to the Veterinarian Portal. Require both the
// shared login flag and the personnel-specific session flag.
$raw_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$normalized_role = preg_replace('/[\\s_-]+/', ' ', $raw_role);
$role_aliases = [
    'vet' => 'veterinarian',
    'veterinary' => 'veterinarian',
];
$current_role = $role_aliases[$normalized_role] ?? $normalized_role;
$logged_in_flag = $_SESSION['logged_in'] ?? false;
$is_logged_in = ($logged_in_flag === true || $logged_in_flag === 1 || $logged_in_flag === '1'
    || (is_string($logged_in_flag) && strtolower(trim($logged_in_flag)) === 'true'));
$is_staff_session = ($_SESSION['staff_logged_in'] ?? false) === true;
$staff_user_id = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$is_vet_role = in_array($current_role, ['vet', 'veterinarian'], true);

if (!$is_logged_in || !$is_staff_session || !$is_vet_role || $staff_user_id === false || $staff_user_id === null) {
    header('Location: stafflogin.php');
    exit();
}

// Generate a page-specific CSRF token after successful authorization.
if (empty($_SESSION['staff_csrf_token']) || !is_string($_SESSION['staff_csrf_token'])) {
    $_SESSION['staff_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = (string)$_SESSION['staff_csrf_token'];

// Only open a database connection after the session and role are verified.
require_once __DIR__ . '/../db_supabase.php';

/** Return SQL fragments for the actual PostgreSQL is_read column type. */
function staffAppointmentNotificationReadSql(PDO $pdo): array
{
    $type_stmt = $pdo->prepare("SELECT data_type
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'notifications'
          AND column_name = 'is_read'
        LIMIT 1");
    $type_stmt->execute();
    $data_type = strtolower(trim((string)$type_stmt->fetchColumn()));

    if ($data_type === 'boolean') {
        return [
            'read_value' => 'FALSE',
            'unread_predicate' => 'COALESCE(is_read, FALSE) = FALSE',
        ];
    }
    if (in_array($data_type, ['smallint', 'integer', 'bigint', 'numeric', 'decimal', 'real', 'double precision'], true)) {
        return [
            'read_value' => '0',
            'unread_predicate' => '(is_read = 0 OR is_read IS NULL)',
        ];
    }
    if (in_array($data_type, ['text', 'character varying', 'character'], true)) {
        return [
            'read_value' => "'false'",
            'unread_predicate' => "(is_read IS NULL OR LOWER(BTRIM(CAST(is_read AS TEXT))) IN ('0', 'false', 'f', 'no', 'unread', ''))",
        ];
    }

    throw new RuntimeException('Unsupported or unknown public.notifications.is_read column type.');
}

/** Resolve only supported project upload paths or safe HTTPS/HTTP image URLs. */
function resolveStaffAppointmentProfileImageUrl($stored_path): string
{
    $path = trim((string)$stored_path);
    if ($path === '' || strpos($path, "\0") !== false) {
        return '';
    }

    if (filter_var($path, FILTER_VALIDATE_URL)) {
        $scheme = strtolower((string)parse_url($path, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) ? $path : '';
    }

    $path = str_replace('\\', '/', $path);
    $path = preg_replace('~^(?:\./)+~', '', $path);
    if (strpos($path, '../uploads/') === 0 || strpos($path, '../admin/uploads/') === 0) {
        $path = substr($path, 3);
    }
    $path = ltrim($path, '/');

    // Reject traversal and unexpected absolute/URI-like local paths.
    if ($path === '' || preg_match('~(^|/)\.\.?(/|$)|^[A-Za-z][A-Za-z0-9+.-]*:~', $path)) {
        return '';
    }

    $project_root = dirname(__DIR__);
    $allowed_locations = [
        'uploads/' => [$project_root . DIRECTORY_SEPARATOR . 'uploads', '../uploads/'],
        'admin/uploads/' => [$project_root . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'uploads', '../admin/uploads/'],
        'staff/uploads/' => [$project_root . DIRECTORY_SEPARATOR . 'staff' . DIRECTORY_SEPARATOR . 'uploads', 'uploads/'],
    ];

    foreach ($allowed_locations as $prefix => $location) {
        if (strpos($path, $prefix) !== 0) {
            continue;
        }
        [$base_path, $browser_prefix] = $location;
        $base_real = realpath($base_path);
        if ($base_real === false) {
            return '';
        }
        $relative_path = substr($path, strlen($prefix));
        if ($relative_path === '' || strpos($relative_path, '..') !== false) {
            return '';
        }
        $real_file = realpath($base_real . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative_path));
        if ($real_file === false || !is_file($real_file)
            || strpos($real_file, $base_real . DIRECTORY_SEPARATOR) !== 0) {
            return '';
        }

        $encoded_relative = implode('/', array_map('rawurlencode', explode('/', $relative_path)));
        return $browser_prefix . $encoded_relative;
    }

    return '';
}

/** Format timestamps in the application's Manila timezone. */
function formatStaffAppointmentNotificationDate($value): string
{
    $value = trim((string)($value ?? ''));
    if ($value === '') {
        return '';
    }
    try {
        $date = new DateTimeImmutable($value);
        return $date->setTimezone(new DateTimeZone('Asia/Manila'))->format('M d, g:i A');
    } catch (Throwable $e) {
        error_log('Staff appointment notification date format failed: ' . $e->getMessage());
        return '';
    }
}

$staff_name = (string)($_SESSION['staff_name'] ?? $_SESSION['user_name'] ?? 'Doctor');
$profile_img_path = '';
$profile_img_url = '';
$full_display_name = $staff_name;

// One small profile query; a profile lookup failure should not break the page.
try {
    $profile_stmt = $pdo->prepare('SELECT full_name, profile_image FROM public.users WHERE id = :id LIMIT 1');
    $profile_stmt->execute([':id' => (int)$staff_user_id]);
    $staff_data = $profile_stmt->fetch(PDO::FETCH_ASSOC);
    if ($staff_data) {
        $profile_img_path = (string)($staff_data['profile_image'] ?? '');
        $profile_img_url = resolveStaffAppointmentProfileImageUrl($profile_img_path);
        if (trim((string)($staff_data['full_name'] ?? '')) !== '') {
            $full_display_name = (string)$staff_data['full_name'];
        }
    }
} catch (Throwable $e) {
    error_log('Veterinarian profile lookup failed: ' . $e->getMessage());
}

$clean_name = trim((string)preg_replace('/^\\s*(?:Dr\\.?|Dra\\.?|Doc\\.?)\\s+/i', '', $full_display_name), " ,");
$first_letter = function_exists('mb_substr')
    ? mb_strtoupper(mb_substr($clean_name, 0, 1, 'UTF-8'), 'UTF-8')
    : strtoupper(substr($clean_name, 0, 1));
if ($first_letter === '') {
    $first_letter = 'D';
}
$has_professional_title = preg_match('/^\\s*(?:Dr\\.?|Dra\\.?|Doc\\.?)\\s+/i', $full_display_name) === 1;
$display_with_title = $has_professional_title ? $full_display_name : 'Dr. ' . $full_display_name;
$mobile_name_source = trim((string)preg_replace('/^\\s*(?:Dr\\.?|Dra\\.?|Doc\\.?)\\s+/i', '', $display_with_title));
$first_name_only = trim((string)(preg_split('/\\s+/', $mobile_name_source)[0] ?? ''));

// Fetch up to 20 unread notifications for this veterinarian only. The window
// count keeps the badge accurate even when more than 20 unread rows exist.
$admin_notifications = [];
$unread_count = 0;
try {
    $read_sql = staffAppointmentNotificationReadSql($pdo);
    $notification_stmt = $pdo->prepare("SELECT id, message, created_at,
                COUNT(*) OVER() AS total_unread
        FROM public.notifications
        WHERE user_id = :user_id
          AND {$read_sql['unread_predicate']}
        ORDER BY created_at DESC NULLS LAST, id DESC
        LIMIT 20");
    $notification_stmt->execute([':user_id' => (int)$staff_user_id]);
    $admin_notifications = $notification_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($admin_notifications) {
        $unread_count = (int)($admin_notifications[0]['total_unread'] ?? count($admin_notifications));
    }
} catch (Throwable $e) {
    error_log('Veterinarian appointment notifications query failed: ' . $e->getMessage());
}

$allowed_filters = ['All', 'Pending', 'Confirmed', 'For Rescheduling', 'Rescheduled', 'Completed', 'Cancelled', 'No-Show'];
$filter_status = trim((string)($_GET['status'] ?? 'All'));
if (!in_array($filter_status, $allowed_filters, true)) {
    $filter_status = 'All';
}

$flash_message = '';

// Handle appointment changes using a transaction so the status update and its
// customer notification either both succeed or both roll back.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['appointment_action'])) {
    $action = trim((string)($_POST['appointment_action'] ?? ''));
    $appointment_id = filter_var($_POST['appointment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $posted_token = (string)($_POST['csrf_token'] ?? '');
    $return_status = trim((string)($_POST['return_status'] ?? 'All'));
    if (!in_array($return_status, $allowed_filters, true)) {
        $return_status = 'All';
    }

    if ($posted_token === '' || !hash_equals($csrf_token, $posted_token)) {
        $flash_message = 'Security check failed. Please refresh the page and try again.';
    } elseif ($appointment_id === false || $appointment_id === null || !in_array($action, ['confirm', 'complete', 'cancel'], true)) {
        $flash_message = 'Invalid appointment action.';
    } else {
        try {
            $pdo->beginTransaction();

            // Lock only the appointment row; LEFT JOIN keeps the appointment
            // available for validation even if its pet record is missing.
            $appointment_stmt = $pdo->prepare("SELECT a.id, a.user_id, a.service, a.booking_status,
                    p.name AS pet_real_name
                FROM public.appointments a
                LEFT JOIN public.pets p ON p.id = a.pet_id
                WHERE a.id = :id
                LIMIT 1
                FOR UPDATE OF a");
            $appointment_stmt->execute([':id' => (int)$appointment_id]);
            $appointment = $appointment_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$appointment) {
                $pdo->rollBack();
                $flash_message = 'Appointment not found.';
            } elseif (stripos((string)($appointment['service'] ?? ''), 'Vet Services') !== 0) {
                $pdo->rollBack();
                $flash_message = 'This appointment is not part of the veterinarian schedule.';
            } else {
                $current_status = strtolower(trim((string)($appointment['booking_status'] ?? '')));
                if ($current_status === '') {
                    $current_status = 'pending';
                }
                $transition_allowed = (
                    (($action === 'confirm' || $action === 'cancel') && $current_status === 'pending') ||
                    ($action === 'complete' && $current_status === 'confirmed')
                );

                if (!$transition_allowed) {
                    $pdo->rollBack();
                    $flash_message = 'That appointment cannot be changed from its current status.';
                } else {
                    $new_status_map = [
                        'confirm' => 'Confirmed',
                        'complete' => 'Completed',
                        'cancel' => 'Cancelled',
                    ];
                    $new_status = $new_status_map[$action];

                    // Guard the write again in SQL against stale or concurrent actions.
                    $update_stmt = $pdo->prepare("UPDATE public.appointments
                        SET booking_status = :new_status
                        WHERE id = :id
                          AND LOWER(BTRIM(COALESCE(service, ''))) LIKE 'vet services%'
                          AND (
                              ((:pending_target = 'Confirmed' OR :cancel_target = 'Cancelled')
                                  AND LOWER(BTRIM(COALESCE(booking_status, ''))) IN ('pending', ''))
                              OR (:complete_target = 'Completed'
                                  AND LOWER(BTRIM(COALESCE(booking_status, ''))) = 'confirmed')
                          )");
                    $update_stmt->execute([
                        ':new_status' => $new_status,
                        ':id' => (int)$appointment_id,
                        ':pending_target' => $new_status,
                        ':cancel_target' => $new_status,
                        ':complete_target' => $new_status,
                    ]);

                    if ($update_stmt->rowCount() !== 1) {
                        $pdo->rollBack();
                        $flash_message = 'The appointment was not updated. Its status may have changed already.';
                    } else {
                        $customer_id = (int)($appointment['user_id'] ?? 0);
                        if ($customer_id > 0) {
                            $notification_read_sql = staffAppointmentNotificationReadSql($pdo);
                            $pet_name = trim((string)($appointment['pet_real_name'] ?? '')) ?: 'Unknown Pet';
                            $service_name = trim((string)($appointment['service'] ?? '')) ?: 'Veterinary';
                            $message = "Your {$service_name} appointment for {$pet_name} has been {$new_status}.";
                            $notification_insert = $pdo->prepare("INSERT INTO public.notifications (user_id, message, is_read)
                                VALUES (:user_id, :message, {$notification_read_sql['read_value']})");
                            $notification_insert->execute([
                                ':user_id' => $customer_id,
                                ':message' => $message,
                            ]);
                        }

                        $pdo->commit();
                        $flash_message = "Appointment successfully marked as {$new_status}.";
                    }
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Veterinarian appointment action failed: ' . $e->getMessage());
            $flash_message = 'Unable to update the appointment right now. Please try again.';
        }
    }

    $_SESSION['staff_appointment_flash'] = $flash_message;
    $redirect_url = 'appointments.php';
    if ($return_status !== 'All') {
        $redirect_url .= '?status=' . rawurlencode($return_status);
    }
    header('Location: ' . $redirect_url, true, 303);
    exit();
}

$flash_message = (string)($_SESSION['staff_appointment_flash'] ?? '');
unset($_SESSION['staff_appointment_flash']);

// Load dashboard counters in one aggregate query.
$stats = ['total' => 0, 'pending' => 0, 'confirmed' => 0, 'completed' => 0];
try {
    $stats_stmt = $pdo->prepare("SELECT
            COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN LOWER(BTRIM(COALESCE(booking_status, ''))) IN ('pending', '') THEN 1 ELSE 0 END), 0) AS pending,
            COALESCE(SUM(CASE WHEN LOWER(BTRIM(COALESCE(booking_status, ''))) = 'confirmed' THEN 1 ELSE 0 END), 0) AS confirmed,
            COALESCE(SUM(CASE WHEN LOWER(BTRIM(COALESCE(booking_status, ''))) = 'completed' THEN 1 ELSE 0 END), 0) AS completed
        FROM public.appointments
        WHERE service ILIKE :service_prefix");
    $stats_stmt->execute([':service_prefix' => 'Vet Services%']);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC) ?: $stats;
} catch (Throwable $e) {
    error_log('Veterinarian appointment stats query failed: ' . $e->getMessage());
    if ($flash_message === '') {
        $flash_message = 'Appointment statistics are temporarily unavailable.';
    }
}

// Use only fixed status predicates from the allow-listed filter values.
$status_condition = '';
switch ($filter_status) {
    case 'Pending':
        $status_condition = " AND LOWER(BTRIM(COALESCE(a.booking_status, ''))) IN ('pending', '')";
        break;
    case 'Confirmed':
        $status_condition = " AND LOWER(BTRIM(COALESCE(a.booking_status, ''))) = 'confirmed'";
        break;
    case 'For Rescheduling':
        $status_condition = " AND LOWER(BTRIM(COALESCE(a.booking_status, ''))) = 'for rescheduling'";
        break;
    case 'Rescheduled':
        $status_condition = " AND LOWER(BTRIM(COALESCE(a.booking_status, ''))) = 'rescheduled'";
        break;
    case 'Completed':
        $status_condition = " AND LOWER(BTRIM(COALESCE(a.booking_status, ''))) = 'completed'";
        break;
    case 'Cancelled':
        $status_condition = " AND LOWER(BTRIM(COALESCE(a.booking_status, ''))) = 'cancelled'";
        break;
    case 'No-Show':
        $status_condition = " AND LOWER(BTRIM(COALESCE(a.booking_status, ''))) = 'no-show'";
        break;
}

$appointments = [];
try {
    $appointments_stmt = $pdo->prepare("SELECT
            a.id,
            a.service,
            a.appointment_date::text AS appointment_date,
            a.appointment_time::text AS appointment_time,
            a.booking_status,
            p.name AS pet_name
        FROM public.appointments a
        LEFT JOIN public.pets p ON p.id = a.pet_id
        WHERE a.service ILIKE :service_prefix{$status_condition}
        ORDER BY a.appointment_date DESC, a.appointment_time ASC, a.id DESC");
    $appointments_stmt->execute([':service_prefix' => 'Vet Services%']);
    $appointments = $appointments_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('Veterinarian appointment list query failed: ' . $e->getMessage());
    if ($flash_message === '') {
        $flash_message = 'Appointment records are temporarily unavailable.';
    }
}
?>



<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical Appointments | Boogie's Pet Care</title>
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
        
        .profile-dropdown { display: none; position: absolute; right: 0; top: 45px; width: 200px; background: white; border: 1px solid var(--border); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); border-radius: 8px; z-index: 1000; overflow: hidden; text-align: left; }
        .profile-dropdown.show { display: block; }
        .profile-item { padding: 12px 15px; display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--text-main); text-decoration: none; transition: 0.2s; font-weight: 600;}
        .profile-item:hover { background: #f8fafc; color: var(--brand-blue); }
        .profile-item i { width: 16px; text-align: center; }

        /* --- DASHBOARD STATS --- */
        .container { padding: 35px 40px; flex-grow: 1;}

        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 30px; }
        
        .stat-card { 
            background: var(--white); 
            padding: 20px; 
            border-radius: 16px; 
            border: 1px solid var(--border); 
            cursor: pointer; 
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.05);
        }
        
        .stat-card h3 { font-size: 12px; color: var(--text-muted); text-transform: uppercase; margin-bottom: 8px; font-weight: 700; letter-spacing: 0.5px;}
        .stat-card p { font-size: 28px; font-weight: 800; color: var(--sidebar-navy); }
        
        .c-total { border-bottom: 4px solid var(--brand-blue); }
        .c-pending { border-bottom: 4px solid #f6ad55; }
        .c-confirmed { border-bottom: 4px solid #0369a1; }
        .c-completed { border-bottom: 4px solid #10b981; }

        /* --- TABLE SECTION --- */
        .card { background: var(--white); border-radius: 20px; border: 1px solid var(--border); overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.02); margin-bottom: 20px;}
        .table-controls { padding: 20px 25px; border-bottom: 1px solid #f8faff; display: flex; align-items: center; justify-content: space-between; background: #f8fafc;}
        
        .search-wrapper { position: relative; max-width: 400px; width: 100%; }
        .search-wrapper input { width: 100%; padding: 10px 15px 10px 40px; border-radius: 10px; border: 1px solid var(--border); font-family: 'Poppins', sans-serif; font-size: 13px; outline: none; transition: 0.2s;}
        .search-wrapper input:focus { border-color: var(--brand-blue); box-shadow: 0 0 0 3px rgba(29,99,255,0.1); }
        .search-wrapper i { position: absolute; left: 15px; top: 12px; color: #a0aec0; }

        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 800px;}
        thead { background: var(--white); border-bottom: 2px solid var(--border); }
        th { padding: 15px 25px; text-align: left; font-size: 12px; font-weight: 700; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px;}
        td { padding: 18px 25px; border-bottom: 1px solid #f8fafc; font-size: 14px; font-weight: 500;}
        tr:hover td { background-color: #fafcfe; }

        /* Status Pills */
        .status-pill { padding: 6px 12px; border-radius: 8px; font-size: 10px; font-weight: 800; display: inline-block; text-transform: uppercase; letter-spacing: 0.5px;}
        .st-pending { background: #fef3c7; color: #92400e; }
        .st-confirmed { background: #e0f2fe; color: #0369a1; }
        .st-completed { background: #dcfce7; color: #15803d; }
        .st-cancelled { background: #fee2e2; color: #b91c1c; }
        
        /* Modern Icon Buttons for Actions */
        .action-group { display: flex; gap: 8px; }
        .btn-icon { width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; text-decoration: none; transition: 0.2s; font-size: 14px; }
        .btn-icon:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .btn-icon { border: 0; padding: 0; cursor: pointer; font-family: inherit; }
        
        .btn-confirm { background: #e0f2fe; color: #0369a1; }
        .btn-confirm:hover { background: #0284c7; color: white; }
        
        .btn-complete { background: #dcfce7; color: #15803d; }
        .btn-complete:hover { background: #16a34a; color: white; }
        
        .btn-cancel { background: #fee2e2; color: #b91c1c; }
        .btn-cancel:hover { background: #ef4444; color: white; }

        footer { text-align: center; padding: 30px; color: var(--text-muted); font-size: 12px; margin-top: auto; border-top: 1px solid var(--border);}

        /* Search filter helper: must override mobile table display rules. */
        .table-responsive tbody tr.search-hidden {
            display: none !important;
        }

        /* ===== EXTRA MOBILE RESPONSIVENESS ===== */
        @media (max-width: 900px) {
            .sidebar {
                width: 220px;
            }

            .main-content {
                margin-left: 220px;
                width: calc(100% - 220px);
            }

            header {
                padding: 0 22px;
            }

            .container {
                padding: 28px 22px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 680px) {
            body {
                overflow-x: hidden;
            }

            .sidebar {
                position: relative;
                width: 100%;
                height: auto;
            }

            .sidebar-header {
                padding: 18px 15px;
            }

            .sidebar-logo-img {
                width: 52px;
                margin-bottom: 6px;
            }

            .sidebar-header h2 {
                font-size: 13px;
            }

            .nav-links {
                padding: 8px 10px 12px;
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 6px;
            }

            .nav-item {
                padding: 10px 6px;
                justify-content: center;
                flex-direction: column;
                gap: 4px;
                text-align: center;
                font-size: 9px;
                line-height: 1.2;
            }

            .nav-item i {
                width: auto;
                font-size: 16px;
            }

            .nav-item:hover {
                transform: none;
            }

            .nav-item.active::before {
                left: 8px;
                right: 8px;
                top: auto;
                bottom: 2px;
                width: auto;
                height: 3px;
                border-radius: 4px;
            }

            .main-content {
                margin-left: 0;
                width: 100%;
                min-height: auto;
            }

            header {
                height: auto;
                min-height: 64px;
                padding: 11px 14px;
                gap: 10px;
                flex-wrap: wrap;
            }

            .breadcrumb {
                font-size: 11px;
                line-height: 1.35;
                min-width: 0;
                flex: 1 1 100%;
            }

            .top-right-actions {
                width: 100%;
                justify-content: flex-end;
                gap: 12px;
            }

            .role-label {
                font-size: 9px;
                padding: 4px 8px;
            }

            .profile-wrapper {
                gap: 8px;
                padding-left: 10px;
            }

            .profile-wrapper > span {
                max-width: 145px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
                font-size: 12px !important;
            }

            .top-avatar,
            .top-avatar-fallback {
                width: 32px;
                height: 32px;
                font-size: 12px;
            }

            .notif-dropdown {
                position: fixed;
                top: 115px;
                right: 10px;
                left: 10px;
                width: auto;
                max-width: none;
            }

            .profile-dropdown {
                right: 0;
            }

            .container {
                padding: 24px 13px 20px;
            }

            .container > div:first-child {
                margin-bottom: 20px !important;
            }

            .container > div:first-child h1 {
                font-size: 21px !important;
                line-height: 1.3;
            }

            .container > div:first-child p {
                font-size: 12px !important;
                line-height: 1.55;
                margin-top: 5px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
                margin-bottom: 18px;
            }

            .stat-card {
                padding: 15px 12px;
                border-radius: 12px;
            }

            .stat-card h3 {
                font-size: 9px;
                line-height: 1.35;
            }

            .stat-card p {
                font-size: 24px;
            }

            .card {
                border-radius: 14px;
            }

            .table-controls {
                padding: 13px;
                display: flex;
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }

            .search-wrapper {
                max-width: none;
            }

            .search-wrapper input {
                min-height: 44px;
                font-size: 12px;
            }

            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            table {
                min-width: 720px;
            }

            th {
                padding: 12px 14px;
                font-size: 10px;
            }

            td {
                padding: 13px 14px;
                font-size: 12px;
            }

            .action-group {
                gap: 6px;
            }

            .btn-icon {
                width: 36px;
                height: 36px;
                font-size: 13px;
            }

            .status-pill {
                padding: 5px 9px;
                font-size: 9px;
            }

            footer {
                padding: 22px 13px;
                font-size: 10px;
            }
        }

        @media (max-width: 400px) {
            .nav-links {
                grid-template-columns: repeat(2, 1fr);
            }

            .nav-item {
                flex-direction: row;
                justify-content: flex-start;
                gap: 7px;
                padding: 9px 10px;
                font-size: 10px;
            }

            .nav-item i {
                width: 18px;
                font-size: 14px;
            }

            .top-right-actions {
                justify-content: space-between;
            }

            .profile-wrapper > span {
                max-width: 95px;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }

            .stat-card p {
                font-size: 22px;
            }
        }


        /* ===== Mobile-flexible layout additions ===== */
        html { width: 100%; overflow-x: hidden; }
        body { width: 100%; max-width: 100%; }
        img { max-width: 100%; }
        input, select, textarea, button { max-width: 100%; }


        /* ===== CONSISTENT OFF-CANVAS MOBILE STAFF MENU ===== */
        .mobile-menu-btn {
            display: none;
            width: 40px;
            height: 40px;
            flex: 0 0 auto;
            border: 0;
            border-radius: 10px;
            background: var(--sidebar-navy);
            color: var(--brand-yellow);
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 18px;
        }
        .sidebar-overlay { display: none; }

        @media (max-width: 900px) {
            .sidebar {
                position: fixed !important;
                top: 0;
                left: 0;
                bottom: 0;
                width: 260px !important;
                height: 100vh !important;
                transform: translateX(-105%);
                transition: transform .25s ease;
                z-index: 2000;
                overflow-y: auto;
                box-shadow: 12px 0 30px rgba(0,0,0,.18);
            }

            .sidebar.mobile-open { transform: translateX(0); }

            .sidebar-overlay {
                display: none;
                position: fixed;
                inset: 0;
                background: rgba(0,31,63,.48);
                z-index: 1900;
            }

            .sidebar-overlay.show { display: block; }


            .sidebar-header { padding: 30px 20px; }
            .sidebar-logo-img { width: 70px; margin-bottom: 10px; }
            .sidebar-header h2 { font-size: 15px; }
            .nav-links {
                display: flex !important;
                flex-direction: column !important;
                padding: 20px 15px !important;
                gap: 5px !important;
            }
            .nav-item {
                flex-direction: row !important;
                justify-content: flex-start !important;
                padding: 14px 20px !important;
                gap: 0 !important;
                text-align: left !important;
                font-size: 14px !important;
                line-height: normal !important;
            }
            .nav-item i { width:32px !important; font-size:18px !important; }

            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
                min-width: 0;
            }

            header {
                height: 64px;
                min-height: 64px;
                padding: 0 14px;
                gap: 10px;
                width: 100%;
                flex-wrap: nowrap;
            }

            .mobile-menu-btn { display: inline-flex; }

            .breadcrumb {
                flex: 1 1 auto;
                min-width: 0;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
                font-size: 12px;
            }

            .top-right-actions {
                margin-left: auto;
                flex: 0 0 auto;
                gap: 10px;
                min-width: 0;
            }

            .role-label {
                padding: 4px 8px;
                font-size: 9px;
                white-space: nowrap;
            }

            .profile-wrapper {
                gap: 8px;
                padding-left: 10px;
                flex-shrink: 0;
                min-width: 0;
            }

            .profile-wrapper > span {
                max-width: 125px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
                font-size: 12px !important;
            }

            .top-avatar, .top-avatar-fallback {
                width: 32px;
                height: 32px;
                flex: 0 0 32px;
            }

            .notif-dropdown {
                position: fixed;
                top: 72px;
                left: 12px;
                right: 12px;
                width: auto;
                max-width: none;
            }

            .profile-dropdown {
                right: 0;
                max-width: calc(100vw - 24px);
            }

            .container {
                width: 100%;
                max-width: 100%;
                padding-left: 16px;
                padding-right: 16px;
            }
        }

        @media (max-width: 560px) {
            header { padding: 0 10px; }
            .mobile-menu-btn { width: 36px; height: 36px; font-size: 16px; border-radius: 9px; }
            .top-right-actions { gap: 6px; }
            .role-label { padding: 4px 7px; font-size: 8px; }
            .profile-wrapper { padding-left: 7px; gap: 6px; }
            .profile-wrapper > span { max-width: 100px; }
            .top-avatar, .top-avatar-fallback { width: 30px; height: 30px; flex-basis: 30px; }
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


@media (max-width: 680px) {
    .table-responsive {
        overflow: visible !important;
    }

    .table-responsive table {
        display: block !important;
        width: 100% !important;
        min-width: 0 !important;
        table-layout: fixed !important;
    }

    .table-responsive thead {
        display: none !important;
    }

    .table-responsive tbody {
        display: block !important;
        width: 100% !important;
    }

    .table-responsive tbody tr {
        display: block !important;
        width: 100% !important;
        margin: 0 0 12px !important;
        padding: 8px 0 !important;
        border: 1px solid var(--border, #e2e8f0) !important;
        border-radius: 14px !important;
        background: #fff !important;
        box-shadow: 0 4px 12px rgba(0,0,0,.04) !important;
    }

    .table-responsive tbody tr:last-child {
        margin-bottom: 0 !important;
    }

    .table-responsive tbody td {
        display: flex !important;
        align-items: flex-start !important;
        justify-content: space-between !important;
        gap: 12px !important;
        width: 100% !important;
        min-width: 0 !important;
        padding: 9px 13px !important;
        border-bottom: 1px solid #f1f5f9 !important;
        text-align: right !important;
        font-size: 12px !important;
    }

    .table-responsive tbody td:last-child {
        border-bottom: 0 !important;
    }

    .table-responsive tbody td::before {
        flex: 0 0 auto !important;
        color: var(--text-muted, #64748b) !important;
        font-size: 9px !important;
        font-weight: 800 !important;
        text-transform: uppercase !important;
        letter-spacing: .5px !important;
        text-align: left !important;
    }

    .table-responsive tbody td:nth-child(1)::before { content: 'Date & Time'; }
    .table-responsive tbody td:nth-child(2)::before { content: 'Service'; }
    .table-responsive tbody td:nth-child(3)::before { content: 'Pet'; }
    .table-responsive tbody td:nth-child(4)::before { content: 'Status'; }
    .table-responsive tbody td:nth-child(5)::before { content: 'Actions'; }

    .table-responsive tbody td > * {
        min-width: 0 !important;
        max-width: 72% !important;
    }

    .table-responsive tbody td:nth-child(5) {
        align-items: center !important;
    }

    .table-responsive tbody td:nth-child(5) .action-group {
        justify-content: flex-end !important;
        flex-wrap: wrap !important;
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

    <aside class="sidebar">
        <div class="sidebar-header">
            <img src="../bg.png" alt="Boogie's Logo" class="sidebar-logo-img">
            <h2>Boogie's Clinic</h2>
        </div>
        <nav class="nav-links">
            <a href="staffdashboard.php" class="nav-item"><i class="fas fa-th-large"></i> Dashboard</a>
            <a href="appointments.php" class="nav-item active"><i class="fas fa-calendar-alt"></i> Appointments</a>
            <a href="pets.php" class="nav-item"><i class="fas fa-paw"></i> Patients</a>
            <a href="tasks.php" class="nav-item"><i class="fas fa-tasks"></i> My Tasks</a>
        </nav>
    </aside>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <main class="main-content">
        <header>
            <button class="mobile-menu-btn" id="mobileMenuBtn" type="button" aria-label="Open menu" aria-expanded="false">
                <i class="fas fa-bars" aria-hidden="true"></i>
            </button>
            <div class="breadcrumb">
                <i class="fas fa-calendar-alt" style="color: var(--brand-blue);"></i> 
                <span class="breadcrumb-text">Veterinarian Portal / Appointments</span></div>
            
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
                            <a href="mark_notifications_read.php" id="mark-read-link" class="mark-read-btn" style="display: <?php echo ($unread_count > 0) ? 'inline-block' : 'none'; ?>;">Mark all read</a>
                        </div>
                        
                        <div class="notif-body" id="admin-notif-list">
                            <?php if ($unread_count > 0): ?>
                                <?php foreach($admin_notifications as $notif): ?>
                                    <div class="notif-item">
                                        <i class="fa-solid fa-circle-exclamation" style="color: #ef4444; margin-right: 5px;"></i>
                                        <?php echo htmlspecialchars((string)($notif['message'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                        <br><small style="color: #94a3b8; font-size: 11px;"><?php echo htmlspecialchars(formatStaffAppointmentNotificationDate($notif['created_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></small>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="notif-empty">No new clinic alerts.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="profile-wrapper" onclick="toggleProfile(event)">
                    <div class="role-label">
                        <i class="fas fa-user-md"></i> VET
                    </div>
                    
                    <?php if ($profile_img_url !== ''): ?>
                        <img src="<?php echo htmlspecialchars($profile_img_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" class="top-avatar" alt="Profile Picture">
                    <?php else: ?>
                        <div class="top-avatar-fallback"><?php echo htmlspecialchars($first_letter, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                    <?php endif; ?>
                    
                    <span class="profile-name-text desktop-profile-name" style="font-size: 14px; font-weight: 700; color: var(--sidebar-navy); display: flex; align-items: center; gap: 6px;">
                        <?php echo htmlspecialchars($display_with_title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                        <i class="fas fa-chevron-down" style="font-size: 10px; color: var(--text-muted); opacity: 0.5;"></i>
                    </span>
                    <span class="mobile-profile-first-name">
                        <?php echo htmlspecialchars($first_name_only, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
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
                <h1 style="font-size: 24px; font-weight: 800; color: var(--sidebar-navy);">Consultation Schedule</h1>
                <p style="color: var(--text-muted); font-size: 14px; font-weight: 500;">View and manage medical appointments for your clinic</p>
            </div>
            <?php if ($flash_message !== ''): ?>
                <div style="margin:-8px 0 22px; padding:12px 16px; border-radius:10px; background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; font-size:13px; font-weight:600;">
                    <i class="fas fa-circle-info" style="margin-right:6px;"></i>
                    <?php echo htmlspecialchars($flash_message, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>


            <div class="stats-grid">
                <div class="stat-card c-total" onclick="window.location.href='appointments.php?status=All'">
                    <h3>Total Consultations</h3><p><?php echo $stats['total']; ?></p>
                </div>
                <div class="stat-card c-pending" onclick="window.location.href='appointments.php?status=Pending'">
                    <h3>Pending</h3><p><?php echo $stats['pending'] ?? 0; ?></p>
                </div>
                <div class="stat-card c-confirmed" onclick="window.location.href='appointments.php?status=Confirmed'">
                    <h3>Confirmed</h3><p><?php echo $stats['confirmed'] ?? 0; ?></p>
                </div>
                <div class="stat-card c-completed" onclick="window.location.href='appointments.php?status=Completed'">
                    <h3>Completed</h3><p><?php echo $stats['completed'] ?? 0; ?></p>
                </div>
            </div>

            <div class="card">
                <div class="table-controls">
                    <div class="search-wrapper">
                        <i class="fas fa-search"></i>
                        <input type="text" id="appointmentSearch" placeholder="Search patients, service, or date...">
                    </div>

                    <select id="statusFilter"
                            onchange="if (this.value === 'All') { window.location.href='appointments.php'; } else { window.location.href='appointments.php?status=' + encodeURIComponent(this.value); }"
                            style="padding:10px 14px; border:1px solid var(--border); border-radius:10px; background:#fff; font-family:'Poppins',sans-serif; font-size:13px; color:var(--text-main); outline:none;">
                        <?php foreach ($allowed_filters as $filter_option): ?>
                            <option value="<?php echo htmlspecialchars($filter_option, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $filter_status === $filter_option ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($filter_option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if($filter_status !== 'All'): ?>
                        <div style="font-size: 13px; font-weight: 700; color: var(--brand-blue); background: #eff6ff; padding: 8px 16px; border-radius: 8px;">
                            Showing: <?php echo htmlspecialchars($filter_status); ?>
                            <a href="appointments.php" style="color: #ef4444; margin-left: 10px; text-decoration: none;"><i class="fas fa-times"></i> Clear</a>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Service</th>
                                <th>Pet Name</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if ($appointments) {
                                foreach ($appointments as $row) {
                                    $raw_status = trim((string)($row['booking_status'] ?? ''));
                                    $status = $raw_status === '' ? 'Pending' : $raw_status;
                                    $status_lower = strtolower($status);
                                    $status_display = htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                                    if ($status_lower === 'confirmed') {
                                        $status_class = 'st-confirmed';
                                    } elseif (in_array($status_lower, ['completed', 'rescheduled'], true)) {
                                        $status_class = 'st-completed';
                                    } elseif (in_array($status_lower, ['cancelled', 'no-show'], true)) {
                                        $status_class = 'st-cancelled';
                                    } else {
                                        $status_class = 'st-pending';
                                    }

                                    $date_raw = (string)($row['appointment_date'] ?? '');
                                    $date_timestamp = $date_raw !== '' ? strtotime($date_raw) : false;
                                    $date_display = $date_timestamp !== false ? date('M d, Y', $date_timestamp) : 'Date unavailable';
                                    $time_raw = (string)($row['appointment_time'] ?? '');
                                    $time_timestamp = $time_raw !== '' ? strtotime($time_raw) : false;
                                    $formatted_time = $time_timestamp !== false ? date('g:i A', $time_timestamp) : '';
                                    $service_display = htmlspecialchars((string)($row['service'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                                    $pet_display = htmlspecialchars((string)($row['pet_name'] ?? 'Unknown Pet'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                                    echo '<tr>';
                                    echo '<td><strong style="color: var(--sidebar-navy); display:block;">' . htmlspecialchars($date_display, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong>';
                                    if ($formatted_time !== '') {
                                        echo '<small style="color: var(--text-muted); font-weight:600;"><i class="far fa-clock"></i> ' . htmlspecialchars($formatted_time, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</small>';
                                    }
                                    echo '</td>';
                                    echo '<td>' . $service_display . '</td>';
                                    echo '<td style="font-weight:700; color: var(--sidebar-navy);">' . $pet_display . '</td>';
                                    echo '<td><span class="status-pill ' . $status_class . '">' . $status_display . '</span></td>';
                                    echo '<td><div class="action-group">';

                                    if ($status_lower === 'pending') {
                                        echo '<form method="POST" action="appointments.php" style="display:inline; margin:0;" onsubmit="return confirm(\'Confirm this appointment?\');">';
                                        echo '<input type="hidden" name="appointment_action" value="confirm">';
                                        echo '<input type="hidden" name="appointment_id" value="' . (int)$row['id'] . '">';
                                        echo '<input type="hidden" name="return_status" value="' . htmlspecialchars($filter_status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
                                        echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf_token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
                                        echo '<button type="submit" class="btn-icon btn-confirm" title="Confirm Appointment"><i class="fas fa-check"></i></button>';
                                        echo '</form>';

                                        echo '<form method="POST" action="appointments.php" style="display:inline; margin:0;" onsubmit="return confirm(\'Cancel this appointment?\');">';
                                        echo '<input type="hidden" name="appointment_action" value="cancel">';
                                        echo '<input type="hidden" name="appointment_id" value="' . (int)$row['id'] . '">';
                                        echo '<input type="hidden" name="return_status" value="' . htmlspecialchars($filter_status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
                                        echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf_token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
                                        echo '<button type="submit" class="btn-icon btn-cancel" title="Cancel Appointment"><i class="fas fa-times"></i></button>';
                                        echo '</form>';
                                    } elseif ($status_lower === 'confirmed') {
                                        echo '<form method="POST" action="appointments.php" style="display:inline; margin:0;" onsubmit="return confirm(\'Mark this veterinary service as completed?\');">';
                                        echo '<input type="hidden" name="appointment_action" value="complete">';
                                        echo '<input type="hidden" name="appointment_id" value="' . (int)$row['id'] . '">';
                                        echo '<input type="hidden" name="return_status" value="' . htmlspecialchars($filter_status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
                                        echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf_token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
                                        echo '<button type="submit" class="btn-icon btn-complete" title="Mark as Completed"><i class="fas fa-check-double"></i></button>';
                                        echo '</form>';
                                    } else {
                                        echo '<span style="color: #cbd5e1; font-style: italic; font-size: 12px; font-weight:600;">No Actions</span>';
                                    }

                                    echo '</div></td>';
                                    echo '</tr>';
                                }
                            } else {
                                $empty_filter_label = strtolower($filter_status !== 'All' ? $filter_status : 'vet');
                                echo '<tr><td colspan="5" style="text-align:center; padding: 60px 30px; color: var(--text-muted);">'
                                    . '<i class="fas fa-calendar-times" style="font-size:40px; opacity:0.3; margin-bottom:15px; display:block;"></i>'
                                    . '<span style="font-weight:500;">No ' . htmlspecialchars($empty_filter_label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' appointments found.</span>'
                                    . '</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <footer>
            © <?php echo date("Y"); ?> BOOGIE'S PET CARE & SERVICES - DASMARIÑAS BRANCH
        </footer>
    </main>

    <script>
        // --- Notification & Profile Toggle Logic ---

        // --- Consistent mobile sidebar ---
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const mobileSidebar = document.querySelector('.sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        function toggleMobileMenu(event) {
            if (event) event.stopPropagation();
            if (!mobileSidebar || !sidebarOverlay || !mobileMenuBtn) return;

            const isOpen = mobileSidebar.classList.toggle('mobile-open');
            sidebarOverlay.classList.toggle('show', isOpen);
            mobileMenuBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            mobileMenuBtn.setAttribute('aria-label', isOpen ? 'Close menu' : 'Open menu');
            mobileMenuBtn.innerHTML = isOpen
                ? '<i class="fas fa-times" aria-hidden="true"></i>'
                : '<i class="fas fa-bars" aria-hidden="true"></i>';
        }

        function closeMobileMenu() {
            if (!mobileSidebar || !sidebarOverlay || !mobileMenuBtn) return;
            mobileSidebar.classList.remove('mobile-open');
            sidebarOverlay.classList.remove('show');
            mobileMenuBtn.setAttribute('aria-expanded', 'false');
            mobileMenuBtn.setAttribute('aria-label', 'Open menu');
            mobileMenuBtn.innerHTML = '<i class="fas fa-bars" aria-hidden="true"></i>';
        }

        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', toggleMobileMenu);
        }
        if (sidebarOverlay) {
            sidebarOverlay.addEventListener('click', closeMobileMenu);
        }
        document.querySelectorAll('.sidebar .nav-item').forEach(function (link) {
            link.addEventListener('click', function () {
                if (window.innerWidth <= 900) closeMobileMenu();
            });
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') closeMobileMenu();
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

        // Staff notifications are rendered from this signed-in user's notification rows.
        // Do not poll the Admin-only notification endpoint from the Vet portal.

        // --- CLIENT-SIDE SEARCH ---
        (function () {
            const searchInput = document.getElementById('appointmentSearch');
            if (!searchInput) return;

            const tableBody = searchInput.closest('.card')?.querySelector('.table-responsive tbody');
            if (!tableBody) return;

            function filterAppointments() {
                const term = searchInput.value.toLowerCase().trim();
                const rows = tableBody.querySelectorAll('tr');

                rows.forEach(function (row) {
                    const emptyRow = row.querySelector('td[colspan]');
                    if (emptyRow) {
                        row.classList.toggle('search-hidden', term !== '');
                        return;
                    }

                    const rowText = (row.textContent || '').replace(/\s+/g, ' ').toLowerCase();
                    const matches = term === '' || rowText.includes(term);
                    row.classList.toggle('search-hidden', !matches);
                });
            }

            searchInput.addEventListener('input', filterAppointments);
            searchInput.addEventListener('search', filterAppointments);
            filterAppointments();
        })();
    </script>
</body>
</html>