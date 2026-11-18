	<?php

require_once __DIR__ . '/../shared_session_bootstrap.php';

require_once __DIR__ . '/../db_supabase.php';

// --- THREE-ROLE SYSTEM ACCESS CHECK ---
// The application has three system roles: Admin, Staff, and Veterinarian.
// Keep the original database role for existing job-specific permissions, but
// use system_role for portal authorization. Never overwrite $_SESSION['role'].
function adminDashboardFlagEnabled($value): bool
{
    return $value === true || $value === 1 || $value === '1'
        || (is_string($value) && strtolower(trim($value)) === 'true');
}

function adminDashboardNormalizeLegacyRole($value): string
{
    $role = strtolower(trim(is_scalar($value) ? (string)$value : ''));
    $role = preg_replace('/[\s_-]+/', ' ', $role) ?? '';

    $aliases = [
        'administrator' => 'admin',
        'admin' => 'admin',
        'shop manager' => 'manager',
        'front desk' => 'receptionist',
        'front desk receptionist' => 'receptionist',
        'front desk / receptionist' => 'receptionist',
        'grooming staff' => 'groomer',
        'grooming staff groomer' => 'groomer',
        'grooming staff / groomer' => 'groomer',
        'pet hotel staff' => 'pet_hotel_staff',
        'pet hotel staff attendant' => 'pet_hotel_staff',
        'pet hotel staff / attendant' => 'pet_hotel_staff',
        'pet hotel attendant' => 'pet_hotel_staff',
        'supervisor' => 'receptionist',
        'vet' => 'veterinarian',
        'veterinary' => 'veterinarian',
        'vet assistant' => 'vet_assistant',
        'veterinary assistant' => 'vet_assistant',
        'vet nurse' => 'vet_nurse',
        'veterinary nurse' => 'vet_nurse',
    ];

    return $aliases[$role] ?? str_replace(' ', '_', $role);
}

/** Map retained database role names to the three application-wide system roles. */
function adminDashboardSystemRole($value): string
{
    $role = adminDashboardNormalizeLegacyRole($value);

    if ($role === 'admin') {
        return 'admin';
    }
    if (in_array($role, ['veterinarian', 'vet_assistant', 'vet_nurse'], true)) {
        return 'veterinarian';
    }
    if (in_array($role, ['staff', 'manager', 'receptionist', 'groomer', 'pet_hotel_staff'], true)) {
        return 'staff';
    }

    return '';
}

$session_legacy_role = adminDashboardNormalizeLegacyRole($_SESSION['role'] ?? '');
$mapped_session_system_role = adminDashboardSystemRole($session_legacy_role);
$stored_system_role = adminDashboardSystemRole($_SESSION['system_role'] ?? $session_legacy_role);
$logged_in = adminDashboardFlagEnabled($_SESSION['logged_in'] ?? false);
$admin_logged_in = adminDashboardFlagEnabled($_SESSION['admin_logged_in'] ?? false);
$staff_logged_in = adminDashboardFlagEnabled($_SESSION['staff_logged_in'] ?? false);

// A saved system role must agree with the retained legacy database role.
if ($mapped_session_system_role === '' || $stored_system_role !== $mapped_session_system_role) {
    http_response_code(403);
    exit('Access denied. Your account role is not recognized for this portal.');
}

if (!$logged_in) {
    $login_target = ($mapped_session_system_role === 'admin' || $admin_logged_in)
        ? 'admin_login.php'
        : '../staff/stafflogin.php';
    header('Location: ' . $login_target, true, 303);
    exit();
}

// Portal flags must be exclusive. Staff and Veterinarian use the Staff login portal.
if ($admin_logged_in === $staff_logged_in) {
    http_response_code(403);
    exit('Access denied. Please sign in through the correct portal.');
}

$is_admin = $admin_logged_in && !$staff_logged_in && $mapped_session_system_role === 'admin';
$is_staff = $staff_logged_in && !$admin_logged_in && $mapped_session_system_role === 'staff';
$is_veterinarian = $staff_logged_in && !$admin_logged_in && $mapped_session_system_role === 'veterinarian';

// Veterinarians use the dedicated dashboard, not the shared Admin/Staff dashboard.
if ($is_veterinarian) {
    header('Location: ../staff/staffdashboard.php', true, 303);
    exit();
}

if (!$is_admin && !$is_staff) {
    http_response_code(403);
    exit('Access denied. Only Admin and Staff can access this dashboard.');
}

$user_id = filter_var(
    $_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? $_SESSION['id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
if ($user_id === false || $user_id === null) {
    http_response_code(403);
    exit('Access denied: invalid session.');
}
$user_id = (int)$user_id;

// Authorization uses the three system roles; this legacy role is retained only
// for existing operational scoping (for example, Grooming or Pet Hotel bookings).
$current_role = $session_legacy_role;
$system_role = $mapped_session_system_role;
$_SESSION['system_role'] = $system_role;

$is_service_staff = $is_staff && in_array($current_role, ['groomer', 'pet_hotel_staff'], true);
$show_notifications = ($is_admin || $is_service_staff);

// Create/reuse the session CSRF token for the existing notification action.
$admin_notif_csrf_token = '';
if ($show_notifications) {
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $admin_notif_csrf_token = $_SESSION['csrf_token'];
}

// Dashboard navigation access is based on the Staff system role, not each
// legacy job title. Existing service-specific booking filters remain below.
$can_view_users_pets = ($is_admin || $is_staff);

$booking_scope_sql = '';
if ($is_staff && $current_role === 'groomer') {
    $booking_scope_sql = " AND LOWER(COALESCE(a.service, '')) LIKE '%grooming%'";
} elseif ($is_staff && $current_role === 'pet_hotel_staff') {
    $booking_scope_sql = " AND (
        LOWER(COALESCE(a.service, '')) LIKE '%pet hotel%'
        OR LOWER(COALESCE(a.service, '')) LIKE '%boarding%'
    )";
}

// Admin, Manager, and Receptionist legacy Staff accounts can see vet schedules.
$can_view_vet_schedule = ($is_admin || ($is_staff && in_array($current_role, ['manager', 'receptionist'], true)));

// --- PROFILE LOGIC ---

$user_id = (int) $user_id;

$profile_img_path = '';
$profile_img_src = '';

$full_name = (string)($_SESSION['user_name'] ?? 'User');

$first_name = trim(explode(' ', $full_name)[0] ?? 'User', ',');

$admin_notif_query = [];

$unread_count = 0;

try {

    try {
        $stmt = $pdo->prepare("
            SELECT role, full_name, profile_image
            FROM users
            WHERE id = :user_id
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $user_id]);
        $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $roleVerificationError) {
        error_log('Admin dashboard account-role verification failed: ' . $roleVerificationError->getMessage());
        http_response_code(503);
        exit('Unable to verify your account right now. Please try again.');
    }

    if (!$user_data) {
        http_response_code(403);
        exit('Access denied. The account could not be verified.');
    }

    $database_legacy_role = adminDashboardNormalizeLegacyRole($user_data['role'] ?? '');
    $database_system_role = adminDashboardSystemRole($database_legacy_role);
    if (
        $database_legacy_role === ''
        || $database_legacy_role !== $session_legacy_role
        || $database_system_role !== $system_role
    ) {
        http_response_code(403);
        exit('Access denied. The database account role does not match this portal session.');
    }

    // Preserve the original role in $_SESSION['role']; authorize with system_role.
    $_SESSION['system_role'] = $database_system_role;
    $full_name = (string)($user_data['full_name'] ?? ($_SESSION['user_name'] ?? 'User'));
    $profile_img_path = (string)($user_data['profile_image'] ?? '');
    $_SESSION['user_name'] = $full_name;

    // Kumuha ng unang letra para sa avatar fallback

    $first_name = explode(' ', $full_name)[0];

    $first_name = trim($first_name, ',');

    // --- FETCH ADMIN NOTIFICATIONS (ADMIN ONLY) ---

    if ($is_admin) {
        // Inspect the real column type so the initial render matches the
        // shared AJAX endpoint for BOOLEAN, numeric, or text is_read columns.
        $read_type_stmt = $pdo->prepare("SELECT data_type
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND table_name = 'admin_notifications'
              AND column_name = 'is_read'
            LIMIT 1");
        $read_type_stmt->execute();
        $admin_is_read_type = strtolower(trim((string)$read_type_stmt->fetchColumn()));

        if ($admin_is_read_type === 'boolean') {
            $admin_unread_predicate = 'COALESCE(is_read, FALSE) = FALSE';
        } elseif (in_array($admin_is_read_type, ['smallint', 'integer', 'bigint', 'numeric', 'decimal', 'real', 'double precision'], true)) {
            $admin_unread_predicate = '(is_read = 0 OR is_read IS NULL)';
        } elseif (in_array($admin_is_read_type, ['text', 'character varying', 'character'], true)) {
            $admin_unread_predicate = "(is_read IS NULL OR LOWER(BTRIM(CAST(is_read AS TEXT))) IN ('0', 'false', 'f', 'no', 'unread', ''))";
        } else {
            throw new RuntimeException('Unsupported or unknown admin_notifications.is_read column type.');
        }

        $stmt = $pdo->query("SELECT * FROM admin_notifications
            WHERE {$admin_unread_predicate}
            ORDER BY created_at DESC, id DESC");
        $admin_notif_query = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $unread_count = count($admin_notif_query);
    }

    // --- FETCH REAL-TIME DATA ---

    $total_users = 0;

    $total_pets = 0;

    $total_staff = 0;

    // Only query customer/pet counts for roles allowed to open those sections.

    if ($can_view_users_pets) {

        $stmt = $pdo->query("SELECT COUNT(*) AS total FROM users WHERE LOWER(COALESCE(role, '')) = 'customer'");

        $total_users = (int)($stmt->fetchColumn() ?? 0);

        $stmt = $pdo->query("SELECT COUNT(*) AS total FROM pets");

        $total_pets = (int)($stmt->fetchColumn() ?? 0);

    }

    // Personnel counts are retrieved only for the Admin dashboard.

    if ($is_admin) {

        $stmt = $pdo->query("

            SELECT COUNT(*) AS total

            FROM users

            WHERE LOWER(COALESCE(role, '')) IN (

                'manager', 'shop manager', 'shop_manager',

                'receptionist', 'front desk', 'front_desk', 'front desk / receptionist', 'front_desk_receptionist',

                'groomer', 'grooming staff', 'grooming staff / groomer', 'grooming_staff', 'grooming_staff_groomer', 'staff',

                'supervisor',

                'pet_hotel_staff', 'pet hotel staff', 'pet hotel staff / attendant', 'pet_hotel_attendant', 'pet_hotel_staff_attendant',

                'vet', 'veterinary', 'veterinarian',

                'vet_assistant', 'vet assistant', 'veterinary assistant', 'veterinary_assistant',

                'vet_nurse', 'vet nurse', 'veterinary nurse', 'veterinary_nurse'

            )

        ");

        $total_staff = (int)($stmt->fetchColumn() ?? 0);

    }

    $stmt = $pdo->query("

        SELECT

            COUNT(*) AS total,

            SUM(

                CASE

                    WHEN booking_status = 'Pending'

                      OR booking_status = 'pending'

                      OR booking_status IS NULL

                      OR booking_status = ''

                    THEN 1 ELSE 0

                END

            ) AS pending,

            SUM(

                CASE

                    WHEN booking_status = 'Completed'

                      OR booking_status = 'completed'

                    THEN 1 ELSE 0

                END

            ) AS completed

        FROM appointments a

        WHERE 1 = 1

        $booking_scope_sql

    ");

    $booking_data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $total_bookings = (int)($booking_data['total'] ?? 0);

    $pending_bookings = (int)($booking_data['pending'] ?? 0);

    $completed_bookings = (int)($booking_data['completed'] ?? 0);

    // --- TODAY'S REVENUE (RBAC: Admin Only) ---

    $todays_revenue = 0;

    if ($is_admin) {

        $stmt = $pdo->query("

            SELECT COALESCE(SUM(service_fee), 0) AS revenue

            FROM appointments

            WHERE (booking_status = 'Completed' OR booking_status = 'completed')

              AND DATE(appointment_date) = CURRENT_DATE

        ");

        $revenue_data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $todays_revenue = $revenue_data['revenue'] ?? 0;

    }

    // --- FETCH COMPLETED APPOINTMENTS TODAY ---

    $stmt = $pdo->query("

        SELECT

            a.*,

            p.name AS pet_name,

            p.verification_status

        FROM appointments a

        LEFT JOIN pets p ON a.pet_id = p.id

        WHERE DATE(a.appointment_date) = CURRENT_DATE

          AND (

              a.booking_status = 'Completed'

              OR a.booking_status = 'completed'

          )

          $booking_scope_sql

        ORDER BY a.appointment_time DESC

        LIMIT 5

    ");

    $completed_today_query = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- FETCH TODAY'S VET CONSULTATIONS ---

    $vet_schedule_query = [];

    if ($can_view_vet_schedule) {

        $stmt = $pdo->query("

            SELECT

                a.*,

                p.name AS pet_name,

                p.verification_status

            FROM appointments a

            LEFT JOIN pets p ON a.pet_id = p.id

            WHERE DATE(a.appointment_date) = CURRENT_DATE

              AND a.service ILIKE 'Vet Services%'

              AND (

                  a.booking_status IS NULL

                  OR a.booking_status NOT IN (

                      'Cancelled', 'cancelled',

                      'Completed', 'completed'

                  )

              )

            ORDER BY a.appointment_time ASC

            LIMIT 5

        ");

        $vet_schedule_query = $stmt->fetchAll(PDO::FETCH_ASSOC);

    }

    // --- FETCH UPCOMING BOOKINGS / APPOINTMENTS ---

    $stmt = $pdo->query("

        SELECT

            a.*,

            p.name AS pet_name,

            p.verification_status

        FROM appointments a

        LEFT JOIN pets p ON a.pet_id = p.id

        WHERE (

              DATE(a.appointment_date) > CURRENT_DATE

              OR (

                  DATE(a.appointment_date) = CURRENT_DATE

                  AND (

                      a.appointment_time IS NULL

                      OR CAST(a.appointment_time AS time) >= CURRENT_TIME

                  )

              )

          )

          AND (

              a.booking_status IS NULL

              OR a.booking_status NOT IN (

                  'Cancelled', 'cancelled',

                  'Completed', 'completed'

              )

          )

          $booking_scope_sql

        ORDER BY a.appointment_date ASC, a.appointment_time ASC

        LIMIT 5

    ");

    $upcoming_appointments_query = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    // Keep database error details out of the browser.

    $admin_notif_query = [];

    $unread_count = 0;

    $total_users = 0;

    $total_staff = 0;

    $total_pets = 0;

    $total_bookings = 0;

    $pending_bookings = 0;

    $completed_bookings = 0;

    $todays_revenue = 0;

    $completed_today_query = [];

    $vet_schedule_query = [];

    $upcoming_appointments_query = [];

    error_log('Admin dashboard database error: ' . $e->getMessage());

}

// Resolve profile images saved by both Admin and Staff profile pages.
// Admin uploads are stored in /admin/uploads; Staff uploads may be in /uploads.
$raw_profile_img = trim((string)$profile_img_path);
if ($raw_profile_img !== '') {
    if (preg_match('~^(?:https?://|data:image/)~i', $raw_profile_img)) {
        $profile_img_src = $raw_profile_img;
    } else {
        $normalized_profile_img = str_replace('\\', '/', $raw_profile_img);
        $normalized_profile_img = ltrim($normalized_profile_img, '/');
        $normalized_profile_img = preg_replace('~^(?:\./)+~', '', $normalized_profile_img);

        // Respect known paths written by the existing profile upload handlers.
        if (stripos($normalized_profile_img, '../uploads/') === 0) {
            $normalized_profile_img = substr($normalized_profile_img, strlen('../uploads/'));
            $profile_upload_locations = [
                ['disk' => __DIR__ . '/../uploads/', 'url' => '/uploads/']
            ];
        } elseif (stripos($normalized_profile_img, 'admin/uploads/') === 0) {
            $normalized_profile_img = substr($normalized_profile_img, strlen('admin/uploads/'));
            $profile_upload_locations = [
                ['disk' => __DIR__ . '/uploads/', 'url' => '/admin/uploads/']
            ];
        } elseif (stripos($normalized_profile_img, 'uploads/') === 0) {
            // admin_profile.php stores this form and writes files to admin/uploads/.
            $normalized_profile_img = substr($normalized_profile_img, strlen('uploads/'));
            $profile_upload_locations = [
                ['disk' => __DIR__ . '/uploads/', 'url' => '/admin/uploads/'],
                ['disk' => __DIR__ . '/../uploads/', 'url' => '/uploads/']
            ];
        } elseif (stripos($normalized_profile_img, 'staff/uploads/') === 0 || stripos($normalized_profile_img, 'customer/uploads/') === 0) {
            $normalized_profile_img = substr($normalized_profile_img, strpos($normalized_profile_img, 'uploads/') + strlen('uploads/'));
            $profile_upload_locations = [
                ['disk' => __DIR__ . '/../uploads/', 'url' => '/uploads/'],
                ['disk' => __DIR__ . '/uploads/', 'url' => '/admin/uploads/']
            ];
        } else {
            // Legacy bare filenames are checked in both supported upload folders.
            $profile_upload_locations = [
                ['disk' => __DIR__ . '/uploads/', 'url' => '/admin/uploads/'],
                ['disk' => __DIR__ . '/../uploads/', 'url' => '/uploads/']
            ];
        }

        // Reject traversal and unsafe characters before checking either location.
        if (
            $normalized_profile_img !== '' &&
            !preg_match('~(^|/)\.\.?(/|$)|[<>:"|?*\x00-\x1F]~', $normalized_profile_img)
        ) {
            foreach ($profile_upload_locations as $upload_location) {
                $profile_disk_path = $upload_location['disk'] . $normalized_profile_img;
                if (is_file($profile_disk_path)) {
                    $encoded_segments = array_map('rawurlencode', explode('/', $normalized_profile_img));
                    $profile_img_src = $upload_location['url'] . implode('/', $encoded_segments);
                    break;
                }
            }
        }
    }
}


// --- ADMIN-ONLY GCASH QR MANAGEMENT (EMBEDDED ON DASHBOARD) ---
$gcash_qr_success_message = '';
$gcash_qr_error_message = '';
$gcash_qr_storage_config = null;
$gcash_qr_bucket_configured = false;
$gcash_qr_image_url = '/images/qr.png';

/** Read the dedicated public QR bucket configuration without exposing its secret key. */
function adminDashboardGcashQrStorageConfig(): ?array
{
    $supabaseUrl = trim((string)(getenv('SUPABASE_URL') ?: ''));
    $secretKey = trim((string)(getenv('SUPABASE_SECRET_KEY') ?: ''));
    $serviceRoleKey = trim((string)(getenv('SUPABASE_SERVICE_ROLE_KEY') ?: ''));
    $apiKey = $secretKey !== '' ? $secretKey : $serviceRoleKey;
    $usesLegacyJwt = ($secretKey === '' && $serviceRoleKey !== '');
    $bucket = trim((string)(getenv('SUPABASE_GCASH_QR_BUCKET') ?: ''));

    if ($supabaseUrl === '' || $apiKey === '' || $bucket === '') {
        return null;
    }

    $supabaseUrl = rtrim($supabaseUrl, '/');
    $parsed = parse_url($supabaseUrl);
    if (
        !filter_var($supabaseUrl, FILTER_VALIDATE_URL)
        || !is_array($parsed)
        || strtolower((string)($parsed['scheme'] ?? '')) !== 'https'
        || empty($parsed['host'])
        || isset($parsed['user'])
        || isset($parsed['pass'])
        || !preg_match('/^[a-z0-9][a-z0-9_-]{1,61}[a-z0-9]$/i', $bucket)
    ) {
        return null;
    }

    return [
        'url' => $supabaseUrl,
        'key' => $apiKey,
        'uses_legacy_jwt' => $usesLegacyJwt,
        'bucket' => $bucket,
    ];
}

/** Replace the single customer-facing QR object in the separate public Storage bucket. */
function adminDashboardUploadGcashQr(string $temporaryPath): array
{
    $config = adminDashboardGcashQrStorageConfig();
    if ($config === null) {
        return ['ok' => false, 'message' => 'Supabase QR Storage is not configured. Set SUPABASE_GCASH_QR_BUCKET and the Supabase server credentials first.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'message' => 'The PHP cURL extension is not available on this server.'];
    }
    if (!is_file($temporaryPath) || !is_readable($temporaryPath)) {
        return ['ok' => false, 'message' => 'The uploaded QR image could not be read.'];
    }

    $imageBytes = file_get_contents($temporaryPath);
    if (!is_string($imageBytes)) {
        return ['ok' => false, 'message' => 'The uploaded QR image could not be read.'];
    }

    $objectPath = 'gcash/qr.png';
    $encodedObjectPath = implode('/', array_map('rawurlencode', explode('/', $objectPath)));
    $endpoint = $config['url'] . '/storage/v1/object/' . rawurlencode($config['bucket']) . '/' . $encodedObjectPath;
    $headers = [
        'apikey: ' . $config['key'],
        'Content-Type: image/png',
        'x-upsert: true',
        'Cache-Control: max-age=60',
    ];
    if (!empty($config['uses_legacy_jwt'])) {
        $headers[] = 'Authorization: Bearer ' . $config['key'];
    }

    $curl = curl_init();
    if ($curl === false) {
        return ['ok' => false, 'message' => 'Unable to initialize the Supabase Storage request.'];
    }

    curl_setopt_array($curl, [
        CURLOPT_URL => $endpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $imageBytes,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $responseBody = curl_exec($curl);
    $curlError = curl_error($curl);
    $statusCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($responseBody === false) {
        error_log('Dashboard GCash QR Storage connection failed: ' . $curlError);
        return ['ok' => false, 'message' => 'Could not connect to Supabase Storage. Please try again.'];
    }
    if (!in_array($statusCode, [200, 201], true)) {
        error_log('Dashboard GCash QR Storage upload rejected with HTTP ' . $statusCode . ': ' . substr((string)$responseBody, 0, 400));
        return ['ok' => false, 'message' => 'Supabase Storage rejected the QR upload (HTTP ' . $statusCode . '). Check the bucket and server credentials.'];
    }

    return ['ok' => true, 'message' => 'GCash QR Code has been updated successfully.'];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['dashboard_action'] ?? '') === 'update_gcash_qr') {
    // This POST action is valid only for an already verified Admin session.
    if (!$is_admin || $is_staff || $is_veterinarian) {
        http_response_code(403);
        exit('Access denied. Admin access is required to update the GCash QR.');
    }

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');

    $postedToken = $_POST['csrf_token'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (
        !is_string($postedToken)
        || $postedToken === ''
        || !is_string($sessionToken)
        || $sessionToken === ''
        || !hash_equals($sessionToken, $postedToken)
    ) {
        http_response_code(403);
        exit('Invalid security token. Refresh the dashboard and try again.');
    }

    $qrFile = $_FILES['gcash_qr_image'] ?? null;
    if (!is_array($qrFile) || (int)($qrFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $gcash_qr_error_message = 'Please select a PNG image of your GCash QR Code.';
    } elseif ((int)($qrFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $gcash_qr_error_message = 'The image upload failed. Please try again.';
    } else {
        $temporaryPath = (string)($qrFile['tmp_name'] ?? '');
        $fileSize = (int)($qrFile['size'] ?? 0);
        $extension = strtolower(pathinfo((string)($qrFile['name'] ?? ''), PATHINFO_EXTENSION));
        $detectedMime = '';
        if ($temporaryPath !== '' && is_uploaded_file($temporaryPath) && class_exists('finfo')) {
            $fileInfo = new finfo(FILEINFO_MIME_TYPE);
            $detectedMime = (string)$fileInfo->file($temporaryPath);
        }
        $imageInfo = ($temporaryPath !== '' && is_uploaded_file($temporaryPath)) ? @getimagesize($temporaryPath) : false;

        if ($fileSize <= 0 || $fileSize > 5 * 1024 * 1024) {
            $gcash_qr_error_message = 'The QR image must be no larger than 5 MB.';
        } elseif (
            $extension !== 'png'
            || $detectedMime !== 'image/png'
            || !is_array($imageInfo)
            || ($imageInfo['mime'] ?? '') !== 'image/png'
        ) {
            $gcash_qr_error_message = 'Invalid image. Please upload a genuine PNG file only.';
        } else {
            $uploadResult = adminDashboardUploadGcashQr($temporaryPath);
            if (!empty($uploadResult['ok'])) {
                $gcash_qr_success_message = (string)$uploadResult['message'];
            } else {
                $gcash_qr_error_message = (string)$uploadResult['message'];
            }
        }
    }

    // POST/Redirect/GET prevents the browser from submitting the same QR twice on refresh.
    $_SESSION['dashboard_gcash_qr_flash'] = [
        'type' => ($gcash_qr_success_message !== '') ? 'success' : 'error',
        'message' => ($gcash_qr_success_message !== '') ? $gcash_qr_success_message : $gcash_qr_error_message,
    ];
    header('Location: admindashboard.php#gcash-qr-management', true, 303);
    exit;
}

if ($is_admin) {
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $dashboard_gcash_qr_csrf = $_SESSION['csrf_token'];

    $gcash_qr_flash = $_SESSION['dashboard_gcash_qr_flash'] ?? null;
    unset($_SESSION['dashboard_gcash_qr_flash']);
    if (is_array($gcash_qr_flash)) {
        $flashType = (string)($gcash_qr_flash['type'] ?? '');
        $flashMessage = (string)($gcash_qr_flash['message'] ?? '');
        if ($flashMessage !== '' && in_array($flashType, ['success', 'error'], true)) {
            if ($flashType === 'success') {
                $gcash_qr_success_message = $flashMessage;
            } else {
                $gcash_qr_error_message = $flashMessage;
            }
        }
    }

    $gcash_qr_storage_config = adminDashboardGcashQrStorageConfig();
    $gcash_qr_bucket_configured = ($gcash_qr_storage_config !== null);
    if ($gcash_qr_bucket_configured) {
        $gcash_qr_image_url = $gcash_qr_storage_config['url']
            . '/storage/v1/object/public/' . rawurlencode($gcash_qr_storage_config['bucket'])
            . '/gcash/qr.png?v=' . rawurlencode((string)time());
    }
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?php echo $is_admin ? 'Admin Dashboard' : 'Operations Dashboard'; ?> | Boogie's Pet Care</title>

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>

        :root {

            --navy-dark: #001f3f; --brand-yellow: #ffcc00; 

            --bg-light: #f4f7f6; --white: #ffffff; --text-main: #2d3436;

            --text-muted: #636e72; --sidebar-width: 260px;

        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Poppins', sans-serif; }

        body { background-color: var(--bg-light); display: flex; min-height: 100vh; }

        /* --- SIDEBAR --- */

        aside { width: var(--sidebar-width); background-color: var(--navy-dark); color: var(--white); display: flex; flex-direction: column; position: fixed; height: 100vh; z-index: 100; }

        .sidebar-header { padding: 30px 20px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.05); }

        .sidebar-logo { width: 80px; height: auto; object-fit: contain; margin-bottom: 10px; }

        .sidebar-header h2 { font-size: 16px; color: var(--brand-yellow); text-transform: uppercase; letter-spacing: 1px; font-weight: 800;}

        /* --- MODERNIZED SIDEBAR NAVIGATION --- */

        .nav-links {
            flex: 1 1 auto;
            min-height: 0;
            padding: 20px 15px;
            display: flex;
            flex-direction: column;
            gap: 5px;
            overflow-y: auto;
            overscroll-behavior: contain;
            scrollbar-width: thin;
            scrollbar-color: rgba(148, 163, 184, 0.35) transparent;
        }
        .nav-links::-webkit-scrollbar { width: 5px; }
        .nav-links::-webkit-scrollbar-track { background: transparent; }
        .nav-links::-webkit-scrollbar-thumb { background: rgba(148, 163, 184, 0.35); border-radius: 10px; }
        .nav-item { white-space: nowrap; }

        .nav-item {

            display: flex; align-items: center; padding: 14px 20px; color: #94a3b8; 

            text-decoration: none; transition: all 0.3s ease; font-size: 14px;

            font-weight: 500; border-radius: 10px; position: relative;

        }

        .nav-item i { width: 32px; font-size: 18px; transition: transform 0.3s;}

        .nav-item:hover {

            color: var(--white); background-color: rgba(255, 255, 255, 0.05);

            transform: translateX(4px); 

        }

        .nav-item.active {

            color: var(--brand-yellow); background-color: rgba(255, 204, 0, 0.08); font-weight: 700;

        }

        .nav-item.active::before {

            content: ''; position: absolute; left: -15px; top: 15%;

            height: 70%; width: 5px; background-color: var(--brand-yellow);

            border-radius: 0 5px 5px 0; box-shadow: 2px 0 8px rgba(255, 204, 0, 0.5); 

        }

        main { margin-left: var(--sidebar-width); flex: 1 1 0; min-width: 0; display: flex; flex-direction: column; }

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

        .mark-read-btn { font-size: 11px; color: #3b82f6; text-decoration: none; font-weight: 600; border: 0; background: transparent; padding: 0; cursor: pointer; font-family: inherit; }

        .mark-read-btn:hover { text-decoration: underline; }

        /* --- PROFILE DROPDOWN & AVATAR STYLES --- */

        .profile-wrapper { position: relative; display: inline-flex; align-items: center; gap: 12px; border-left: 1px solid #e2e8f0; padding-left: 20px; cursor: pointer; user-select: none; }

        /* Fixed Avatar Colors */

        .top-avatar { width: 35px; height: 35px; border-radius: 50%; object-fit: cover; border: 2px solid var(--navy-dark); }

        .top-avatar-fallback { width: 35px; height: 35px; border-radius: 50%; background: linear-gradient(135deg, var(--navy-dark), #003366); color: var(--brand-yellow); display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 14px; border: 2px solid var(--brand-yellow); box-shadow: 0 2px 4px rgba(0,0,0,0.1); }

        .profile-dropdown { display: none; position: absolute; right: 0; top: 45px; width: 200px; background: white; border: 1px solid #e2e8f0; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); border-radius: 8px; z-index: 1000; overflow: hidden; text-align: left; }

        .profile-dropdown.show { display: block; }

        .profile-item { padding: 12px 15px; display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--text-main); text-decoration: none; transition: background 0.2s; }

        .profile-item:hover { background: #f1f5f9; color: var(--navy-dark); }

        .profile-item i { width: 16px; text-align: center; }

        .profile-item.logout-text { color: #e11d48; border-top: 1px solid #f1f5f9; }

        .profile-item.logout-text:hover { background: #fff1f2; color: #be123c; }

        .container { padding: 40px; width: 100%; min-width: 0; }

        .stats-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 20px; margin-bottom: 25px; min-width: 0; }

        .stat-card { background: var(--white); padding: 25px; border-radius: 16px; box-shadow: 0 4px 6px rgba(0,0,0,0.03); position: relative; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; border-left: 4px solid var(--navy-dark); }

        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 10px 20px rgba(0,0,0,0.08); }

        .stat-card h3 { font-size: 12px; color: var(--text-muted); text-transform: uppercase; margin-bottom: 8px; font-weight: 700; letter-spacing: 0.5px;}

        .stat-card .val { font-size: 28px; font-weight: 800; color: var(--navy-dark); }

        .stat-card i { position: absolute; top: 25px; right: 20px; font-size: 24px; opacity: 0.1; color: var(--navy-dark); }

        .action-banners { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 20px; margin-bottom: 30px; min-width: 0; }

        .banner { padding: 25px; border-radius: 16px; color: white; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; }

        .banner:hover { transform: translateY(-4px); box-shadow: 0 10px 20px rgba(0,0,0,0.15); }

        .banner-green { background: linear-gradient(135deg, #10b981, #059669); }

        .banner-blue { background: linear-gradient(135deg, #3b82f6, #2563eb); }

        .banner-purple { background: linear-gradient(135deg, var(--navy-dark), #003366); } /* Fixed to Navy Blue */

        .banner h3 { font-size: 14px; font-weight: 600; margin-bottom: 5px; opacity: 0.9;}

        .banner h2 { font-size: 32px; font-weight: 800; }

        /* 3 columns para pumasok yung Vet Consultations */

        .table-row { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 25px; min-width: 0; }

        .data-box { background: var(--white); border-radius: 16px; padding: 25px; min-height: 200px; box-shadow: 0 4px 6px rgba(0,0,0,0.03); }

        .data-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #f1f5f9; padding-bottom: 15px; }

        .data-header h3 { font-size: 16px; color: var(--navy-dark); font-weight: 700; }

        /* Fixed View Link Color */

        .view-link { color: var(--navy-dark); text-decoration: none; font-size: 12px; font-weight: 700; background: #f1f5f9; padding: 6px 12px; border-radius: 6px; transition: 0.2s; }

        .view-link:hover { background: var(--brand-yellow); color: var(--navy-dark); }

        .recent-list { list-style: none; padding: 0; margin: 0; }

        .recent-list li { display: flex; justify-content: space-between; align-items: center; padding: 15px 0; border-bottom: 1px solid #f1f5f9; transition: 0.2s;}

        .recent-list li:hover { background-color: #f8fafc; border-radius: 8px; padding-left: 10px; padding-right: 10px;}

        .recent-list li:last-child { border-bottom: none; }

        .recent-info { display: flex; flex-direction: column; gap: 4px; }

        .recent-name { font-weight: 700; font-size: 14px; color: var(--navy-dark); margin-bottom: 2px; }

        .recent-sub { font-size: 12px; color: var(--text-muted); font-weight: 500;}

        .recent-service { font-size: 12px; font-weight: 700; color: var(--navy-dark); } /* Fixed to Navy Blue */

        .recent-status { font-size: 10px; font-weight: 800; padding: 4px 10px; border-radius: 6px; text-transform: uppercase; letter-spacing: 0.5px;}

        .status-pending { background: #fef3c7; color: #92400e; }

        .status-confirmed { background: #dbeafe; color: #1e40af; }

        .status-completed { background: #dcfce7; color: #166534; }

        .status-cancelled { background: #fee2e2; color: #991b1b; }

        footer { text-align: center; padding: 40px; color: var(--text-muted); font-size: 12px; border-top: 1px solid rgba(0,0,0,0.05); margin-top: 20px; }

        /* --- RESPONSIVE ADMIN LAYOUT --- */

        .topbar-left { display: flex; align-items: center; gap: 12px; min-width: 0; }

        .top-right-actions { margin-left: auto; flex-shrink: 0; }

        .mobile-menu-toggle {

            display: none; border: 0; background: var(--navy-dark); color: var(--brand-yellow);

            width: 40px; height: 40px; border-radius: 10px; cursor: pointer; font-size: 18px;

            align-items: center; justify-content: center; flex: 0 0 auto;

        }

        .sidebar-backdrop { display: none; }

        @media (max-width: 1200px) {

            .stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }

            .action-banners { grid-template-columns: 1fr; }

            .table-row { grid-template-columns: 1fr; }

        }

        @media (max-width: 900px) {

            :root { --sidebar-width: 260px; }

            .topbar-left { flex: 1 1 auto; min-width: 0; }

            .top-right-actions { margin-left: auto; flex: 0 0 auto; justify-content: flex-end; }

            body { overflow-x: hidden; }

            aside {

                transform: translateX(-100%);

                transition: transform 0.25s ease;

                box-shadow: 12px 0 30px rgba(0,0,0,0.18);

                z-index: 2000;

            }

            aside.mobile-open { transform: translateX(0); }

            .sidebar-backdrop {

                position: fixed; inset: 0; background: rgba(0, 31, 63, 0.48);

                z-index: 1900;

            }

            .sidebar-backdrop.show { display: block; }

            main { margin-left: 0; width: 100%; min-width: 0; }

            .top-bar {

                height: 64px; padding: 0 14px; gap: 10px;

            }

            .mobile-menu-toggle { display: inline-flex; }

            .breadcrumb { font-size: 13px; gap: 6px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

            .breadcrumb i { font-size: 13px !important; }

            .top-right-actions { gap: 10px; min-width: 0; }

            .notif-wrapper > i { font-size: 20px !important; }

            .profile-wrapper { gap: 8px; padding-left: 10px; border-left: 1px solid #e2e8f0; }

            .profile-wrapper > span:last-of-type {

                max-width: 110px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;

                font-size: 12px !important;

            }

            .admin-tag { padding: 5px 10px; font-size: 9px; }

            .top-avatar, .top-avatar-fallback { width: 32px; height: 32px; }

            .notif-dropdown {

                width: min(320px, calc(100vw - 28px));

                right: -6px;

            }

            .container { padding: 22px 16px; }

            .container > div:first-child h1 { font-size: 22px !important; line-height: 1.25; }

            .container > div:first-child p { font-size: 13px !important; line-height: 1.5; }

            .stats-grid {

                grid-template-columns: repeat(2, minmax(0, 1fr));

                gap: 12px;

            }

            .stat-card { padding: 18px; border-radius: 14px; }

            .stat-card h3 { font-size: 10px; }

            .stat-card .val { font-size: 24px; }

            .stat-card i { top: 18px; right: 16px; font-size: 20px; }

            .action-banners { gap: 12px; margin-bottom: 20px; }

            .banner { padding: 20px; border-radius: 14px; }

            .banner h3 { font-size: 13px; }

            .banner h2 { font-size: 28px; }

            .table-row { gap: 14px; }

            .data-box { padding: 18px; border-radius: 14px; min-height: 180px; }

            .data-header { margin-bottom: 14px; padding-bottom: 12px; }

            .data-header h3 { font-size: 14px; }

            .recent-list li { padding: 12px 0; }

            .recent-name { font-size: 13px; }

            .recent-sub, .recent-service { font-size: 11px; }

            footer { padding: 28px 16px; font-size: 10px; }

        }

        @media (max-width: 560px) {

            .top-bar { height: 60px; padding: 0 10px; }

            .topbar-left { gap: 8px; }

            .mobile-menu-toggle { width: 36px; height: 36px; border-radius: 9px; font-size: 16px; }

            .breadcrumb { font-size: 11px; }

            .top-right-actions { gap: 6px; }

            .profile-wrapper { padding-left: 7px; gap: 6px; }

            .profile-wrapper > span:last-of-type { display: none !important; }

            .profile-wrapper .top-avatar, .profile-wrapper .top-avatar-fallback { width: 30px; height: 30px; }

            .admin-tag { padding: 4px 8px; font-size: 8px; letter-spacing: .7px; }

            .container { padding: 18px 12px; }

            .stats-grid { grid-template-columns: 1fr; gap: 10px; }

            .stat-card { padding: 17px; }

            .stat-card .val { font-size: 23px; }

            .banner { padding: 18px; }

            .banner h2 { font-size: 26px; }

            .data-box { padding: 16px; min-height: 160px; }

            .data-header { align-items: flex-start; gap: 8px; }

            .view-link { font-size: 10px; padding: 5px 8px; }

            .notif-dropdown { top: 32px; }

        }


        /* --- ADMIN DASHBOARD GCASH QR QUICK MANAGER --- */
        .gcash-qr-management {
            background: var(--white);
            border-radius: 16px;
            padding: 22px 24px;
            margin: 0 0 28px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.035);
            border: 1px solid #edf1f5;
        }
        .gcash-qr-management-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
        }
        .gcash-qr-management-header h2 {
            color: var(--navy-dark);
            font-size: 16px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 9px;
        }
        .gcash-qr-management-header h2 i { color: #d5a900; }
        .gcash-qr-management-header p {
            color: var(--text-muted);
            font-size: 11px;
            line-height: 1.55;
            margin-top: 4px;
        }
        .gcash-qr-management-body {
            display: grid;
            grid-template-columns: 150px minmax(0, 1fr);
            gap: 22px;
            align-items: center;
        }
        .gcash-qr-dashboard-preview {
            background: #fff9df;
            border: 1px dashed #e6c64b;
            border-radius: 12px;
            min-height: 150px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px;
        }
        .gcash-qr-dashboard-preview img {
            display: block;
            width: 128px;
            height: 128px;
            object-fit: contain;
            background: #fff;
            border-radius: 8px;
            padding: 5px;
            box-shadow: 0 3px 10px rgba(0,0,0,.06);
        }
        .gcash-qr-dashboard-form { min-width: 0; }
        .gcash-qr-dashboard-form label {
            display: block;
            color: #334155;
            font-size: 11px;
            font-weight: 700;
            margin-bottom: 7px;
        }
        .gcash-qr-dashboard-form input[type="file"] {
            display: block;
            width: 100%;
            max-width: 520px;
            padding: 9px;
            border: 1px solid #dbe2e8;
            border-radius: 8px;
            background: #fff;
            color: #475569;
            font-size: 11px;
        }
        .gcash-qr-dashboard-help {
            color: #64748b;
            font-size: 10px;
            line-height: 1.55;
            margin-top: 7px;
        }
        .gcash-qr-dashboard-submit {
            margin-top: 12px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 15px;
            border: 0;
            border-radius: 8px;
            background: var(--navy-dark);
            color: var(--brand-yellow);
            font-size: 11px;
            font-weight: 800;
            cursor: pointer;
        }
        .gcash-qr-dashboard-submit:hover { background: #07345d; }
        .gcash-qr-dashboard-submit:disabled { opacity: .6; cursor: not-allowed; }
        .gcash-qr-notice {
            padding: 10px 13px;
            border-radius: 9px;
            font-size: 11px;
            line-height: 1.55;
            margin: 0 0 14px;
        }
        .gcash-qr-notice.success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
        .gcash-qr-notice.error { background: #fff1f2; border: 1px solid #fecdd3; color: #9f1239; }
        .gcash-qr-notice.warning { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
        .gcash-qr-notice code { font-family: monospace; background: rgba(15,23,42,.06); padding: 1px 4px; border-radius: 3px; }

    </style>

<link rel="stylesheet" href="../boogies_notification_mobile.css">
</head>

<body>

    <aside>

        <div class="sidebar-header">

            <img src="bg.png" alt="Boogie's Logo" class="sidebar-logo">

            <h2>

                <?php echo $is_admin ? "Boogie's Admin" : "Boogie's Staff"; ?>

            </h2>

        </div>

        <nav class="nav-links">

            <a href="admindashboard.php" class="nav-item active"><i class="fas fa-chart-line"></i> Dashboard</a>

            <a href="managebooking.php" class="nav-item"><i class="fas fa-calendar-alt"></i> Bookings</a>

            <?php if ($can_view_users_pets): ?>

                <a href="manageusers.php" class="nav-item"><i class="fas fa-users"></i> Users</a>

                <a href="managepet.php" class="nav-item"><i class="fas fa-dog"></i> Pets</a>

            <?php endif; ?>

            <?php if ($is_admin): ?>

                <a href="managestaff.php" class="nav-item"><i class="fas fa-id-badge"></i> Personnel</a>

                <a href="managepromo.php" class="nav-item"><i class="fas fa-tags"></i> Promos</a>

                <a href="manage_services.php" class="nav-item"><i class="fas fa-list-ul"></i> Pricelist</a>

                <a href="sales_report.php" class="nav-item"><i class="fas fa-file-invoice-dollar"></i> Sales Report</a>

                <!-- ACCOUNT LOGS: ADMIN ONLY -->

                <a href="admin_account_logs.php" class="nav-item">

                    <i class="fas fa-clock-rotate-left"></i>

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

                <i class="fas fa-desktop" style="opacity: 0.5; font-size: 14px;"></i> Overview / Dashboard

            </div>

            </div>

            <div class="top-right-actions">

<?php if ($show_notifications): ?>

                <div class="notif-wrapper" onclick="toggleNotif(event)">

                    <i class="fa-solid fa-bell" style="font-size: 22px; color: #64748b;"></i>

                    <span id="admin-notif-badge" class="notif-badge" style="display: <?php echo ($unread_count > 0) ? 'inline-block' : 'none'; ?>;">

                        <?php echo $unread_count; ?>

                    </span>

                    <div class="notif-dropdown" id="notifBox" onclick="event.stopPropagation()">

                        <div class="notif-header">

                            Alerts

                            <form action="mark_notifications_read.php" method="post" style="margin: 0;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_notif_csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="submit" id="mark-read-link" class="mark-read-btn" style="display: <?php echo ($unread_count > 0) ? 'inline-block' : 'none'; ?>;">Mark all read</button>
                            </form>

                        </div>

                        <div class="notif-body" id="admin-notif-list">

                            <?php if ($is_admin && $unread_count > 0): ?>

                                <?php foreach ($admin_notif_query as $notif): ?>

                                    <div class="notif-item">

                                        <i class="fa-solid fa-circle-exclamation" style="color: #e11d48; margin-right: 5px;"></i>

                                        <?php echo htmlspecialchars((string)($notif['message'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>

                                        <br><small style="color: #94a3b8; font-size: 11px;"><?php echo htmlspecialchars(date('M d, g:i A', strtotime((string)($notif['created_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8'); ?></small>

                                    </div>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <div class="notif-empty"><?php echo $is_admin ? 'No new notifications.' : 'Loading notifications...'; ?></div>

                            <?php endif; ?>

                        </div>

                    </div>

                </div>

<?php endif; ?>

                <div class="profile-wrapper" onclick="toggleProfile(event)">

                    <span class="admin-tag"><?php echo $is_admin ? 'ADMIN' : 'STAFF'; ?></span>

                    <?php if ($profile_img_src !== ''): ?>

                        <img src="<?php echo htmlspecialchars($profile_img_src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" class="top-avatar" alt="Profile Picture" onerror="this.onerror=null; this.style.display='none'; if(this.nextElementSibling){this.nextElementSibling.style.display='flex';}">
                        <div class="top-avatar-fallback" style="display:none;"><?php echo strtoupper(substr($first_name, 0, 1)); ?></div>

                    <?php else: ?>

                        <div class="top-avatar-fallback"><?php echo strtoupper(substr($first_name, 0, 1)); ?></div>

                    <?php endif; ?>

                    <span style="font-size: 14px; font-weight: 600; color: #4a5568; display: flex; align-items: center; gap: 6px;">

                        <?php echo htmlspecialchars($full_name); ?>

                        <i class="fas fa-chevron-down" style="font-size: 10px; color: #94a3b8;"></i>

                    </span>

                    <div class="profile-dropdown" id="profileBox" onclick="event.stopPropagation()">

                        <a href="<?php echo $is_admin ? 'admin_profile.php' : '../staff/staff_profile.php'; ?>" class="profile-item">

                            <i class="fas fa-user-circle"></i> My Profile

                        </a>

                        <a href="../customer/logout.php" class="profile-item logout-text">

                            <i class="fas fa-sign-out-alt"></i> Logout

                        </a>

                    </div>

                </div>

            </div>

        </header>

        <div class="container">

            <div style="margin-bottom: 25px;">

                <h1 style="font-size: 26px; color: var(--navy-dark); font-weight: 800;">Welcome Back, <?php echo htmlspecialchars($full_name); ?>!</h1>

                <p style="color: var(--text-muted); font-size: 14px; font-weight: 500;"><?php

                    if ($current_role === 'groomer') {

                        echo 'Here is the current grooming bookings overview.';

                    } elseif ($current_role === 'pet_hotel_staff') {

                        echo 'Here is the current pet hotel bookings overview.';

                    } else {

                        echo "Here is what's happening with Boogie's Pet Care today.";

                    }

                ?></p>

            </div>

            <div class="stats-grid">

                <?php if ($can_view_users_pets): ?>

                    <div class="stat-card" onclick="window.location.href='manageusers.php'">

                        <h3>Registered Users</h3><div class="val"><?php echo $total_users; ?></div><i class="fas fa-users"></i>

                    </div>

                <?php else: ?>

                    <div class="stat-card" style="cursor: default; opacity: 0.7;" title="User information is restricted for this role.">

                        <h3>Registered Users</h3><div class="val">Restricted</div><i class="fas fa-lock"></i>

                    </div>

                <?php endif; ?>

                <?php if ($is_admin): ?>

                    <div class="stat-card" onclick="window.location.href='managestaff.php'">

                        <h3>Personnel</h3><div class="val"><?php echo $total_staff; ?></div><i class="fas fa-id-card"></i>

                    </div>

                <?php else: ?>

                    <div class="stat-card" style="cursor: default; opacity: 0.7;" title="Personnel information is restricted to Admin.">

                        <h3>Personnel</h3><div class="val">Restricted</div><i class="fas fa-lock"></i>

                    </div>

                <?php endif; ?>

                <?php if ($can_view_users_pets): ?>

                    <div class="stat-card" onclick="window.location.href='managepet.php'">

                        <h3>Pet Profiles</h3><div class="val"><?php echo $total_pets; ?></div><i class="fas fa-paw"></i>

                    </div>

                <?php else: ?>

                    <div class="stat-card" style="cursor: default; opacity: 0.7;" title="Pet profiles are restricted for this role.">

                        <h3>Pet Profiles</h3><div class="val">Restricted</div><i class="fas fa-lock"></i>

                    </div>

                <?php endif; ?>

                <div class="stat-card" onclick="window.location.href='managebooking.php'">

                    <h3>Total Bookings</h3><div class="val"><?php echo $total_bookings; ?></div><i class="fas fa-calendar-check"></i>

                </div>

            </div>

            <div class="action-banners">

                <div class="banner banner-green" onclick="window.location.href='managebooking.php?status=completed'">

                    <h3>Completed Bookings</h3><h2><?php echo $completed_bookings; ?></h2>

                </div>

                <div class="banner banner-blue" onclick="window.location.href='managebooking.php?status=pending'">

                    <h3>Pending Bookings</h3><h2><?php echo $pending_bookings; ?></h2>

                </div>

                <?php if ($is_admin): ?>

                <div class="banner banner-purple" onclick="window.location.href='sales_report.php'">

                    <h3>Today's Revenue</h3><h2>₱<?php echo number_format($todays_revenue, 2); ?></h2>

                </div>

                <?php else: ?>

                <div class="banner banner-purple" style="opacity: 0.8; cursor: default;">

                    <h3>Today's Revenue</h3><h2 style="font-size: 16px; margin-top: 5px;"><i class="fas fa-lock"></i> Restricted</h2>

                </div>

                <?php endif; ?>

            </div>


            <?php if ($is_admin): ?>
                <section class="gcash-qr-management" id="gcash-qr-management" aria-labelledby="gcashQrDashboardTitle">
                    <div class="gcash-qr-management-header">
                        <div>
                            <h2 id="gcashQrDashboardTitle"><i class="fas fa-qrcode"></i> GCash QR Payment</h2>
                            <p>Preview or replace the QR code customers see on the Book Appointment page.</p>
                        </div>
                    </div>

                    <?php if ($gcash_qr_success_message !== ''): ?>
                        <div class="gcash-qr-notice success" role="status"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($gcash_qr_success_message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                    <?php endif; ?>
                    <?php if ($gcash_qr_error_message !== ''): ?>
                        <div class="gcash-qr-notice error" role="alert"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($gcash_qr_error_message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                    <?php endif; ?>
                    <?php if (!$gcash_qr_bucket_configured): ?>
                        <div class="gcash-qr-notice warning"><strong><i class="fas fa-triangle-exclamation"></i> Storage setup required.</strong> The current QR remains visible. To enable replacement, create a separate <strong>public</strong> Supabase Storage bucket and set <code>SUPABASE_GCASH_QR_BUCKET</code>, <code>SUPABASE_URL</code>, and a server-side <code>SUPABASE_SECRET_KEY</code> (or legacy Service Role key) in your local environment and Vercel.</div>
                    <?php endif; ?>

                    <div class="gcash-qr-management-body">
                        <div class="gcash-qr-dashboard-preview">
                            <img src="<?php echo htmlspecialchars($gcash_qr_image_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" alt="Current GCash QR Code" onerror="this.onerror=null;this.src='/images/qr.png';">
                        </div>
                        <form class="gcash-qr-dashboard-form" method="POST" enctype="multipart/form-data" id="dashboardGcashQrForm">
                            <input type="hidden" name="dashboard_action" value="update_gcash_qr">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($dashboard_gcash_qr_csrf ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            <label for="dashboard_gcash_qr_image">Upload new GCash QR (PNG only)</label>
                            <input type="file" name="gcash_qr_image" id="dashboard_gcash_qr_image" accept="image/png,.png" required <?php echo !$gcash_qr_bucket_configured ? 'disabled' : ''; ?>>
                            <p class="gcash-qr-dashboard-help">Maximum 5 MB. Replacing this image updates the same QR location shown to customers.</p>
                            <button class="gcash-qr-dashboard-submit" type="submit" <?php echo !$gcash_qr_bucket_configured ? 'disabled' : ''; ?>><i class="fas fa-save"></i> Save New QR</button>
                            <p class="gcash-qr-dashboard-help"><i class="fas fa-shield-halved"></i> Admin only. Supabase secret credentials stay on the server.</p>
                        </form>
                    </div>
                </section>
            <?php endif; ?>

            <div class="table-row">

                <div class="data-box">

                    <div class="data-header">

                        <h3>Completed Today</h3>

                        <a href="managebooking.php?status=completed" class="view-link">VIEW ALL</a>

                    </div>

                    <?php if(count($completed_today_query) > 0): ?>

                        <ul class="recent-list">

                            <?php foreach($completed_today_query as $cb):

                                $display_time = isset($cb['appointment_time']) && !empty($cb['appointment_time'])

                                                ? date('g:i A', strtotime($cb['appointment_time']))

                                                : date('g:i A', strtotime($cb['appointment_date']));

                            ?>

                            <li>

                                <div class="recent-info">

                                    <span class="recent-name">

                                        <?php echo htmlspecialchars($cb['pet_name'] ?? 'Unknown Pet'); ?>

                                    </span>

                                    <span class="recent-service"><i class="fas fa-clipboard-check" style="margin-right: 6px; color: #94a3b8;"></i><?php echo htmlspecialchars($cb['service'] ?? 'General Service'); ?></span>

                                    <span class="recent-sub"><i class="far fa-clock" style="margin-right: 6px;"></i><?php echo $display_time; ?></span>

                                </div>

                                <span class="recent-status status-completed">Completed</span>

                            </li>

                            <?php endforeach; ?>

                        </ul>

                    <?php else: ?>

                        <div style="text-align: center; color: #94a3b8; padding: 40px 0;">

                            <i class="fas fa-clipboard-check" style="font-size: 35px; margin-bottom: 12px; opacity: 0.3;"></i>

                            <p style="font-size: 13px; font-weight: 500; margin: 0;">No completed appointments today.</p>

                        </div>

                    <?php endif; ?>

                </div>

                <?php if ($can_view_vet_schedule): ?>

<div class="data-box">

                    <div class="data-header">

                        <h3><i class="fas fa-user-md" style="color: var(--brand-blue); margin-right: 5px;"></i> Vet Consultations</h3>

                    </div>

                    <?php if(count($vet_schedule_query) > 0): ?>

                        <ul class="recent-list">

                            <?php foreach($vet_schedule_query as $vs): 

                                $raw_status = $vs['booking_status'] ?? '';

                                $disp_status = empty($raw_status) ? 'Pending' : ucfirst($raw_status);

                                $status_class = strtolower($disp_status);

                                $display_time = isset($vs['appointment_time']) && !empty($vs['appointment_time']) 

                                                ? date('g:i A', strtotime($vs['appointment_time'])) 

                                                : date('g:i A', strtotime($vs['appointment_date']));

                            ?>

                            <li>

                                <div class="recent-info">

                                    <span class="recent-name">

                                        <?php echo htmlspecialchars($vs['pet_name'] ?? 'Unknown Pet'); ?>

                                    </span>

                                    <span class="recent-service"><i class="fas fa-stethoscope" style="margin-right: 6px; color: #94a3b8;"></i><?php echo htmlspecialchars($vs['service'] ?? 'Vet Services'); ?></span>

                                    <span class="recent-sub"><i class="far fa-clock" style="margin-right: 6px;"></i><?php echo $display_time; ?></span>

                                </div>

                                <span class="recent-status status-<?php echo $status_class; ?>"><?php echo $disp_status; ?></span>

                            </li>

                            <?php endforeach; ?>

                        </ul>

                    <?php else: ?>

                        <div style="text-align: center; color: #94a3b8; padding: 40px 0;">

                            <i class="fas fa-user-md" style="font-size: 35px; margin-bottom: 12px; opacity: 0.3;"></i>

                            <p style="font-size: 13px; font-weight: 500; margin: 0;">No vet consults for today.</p>

                        </div>

                    <?php endif; ?>

                </div>

                <?php endif; ?>

                <div class="data-box">

                    <div class="data-header">

                        <h3>Upcoming Appointments</h3>

                        <a href="managebooking.php" class="view-link">VIEW ALL</a>

                    </div>

                    <?php if(count($upcoming_appointments_query) > 0): ?>

                        <ul class="recent-list">

                            <?php foreach($upcoming_appointments_query as $ub):

                                $display_date = isset($ub['appointment_date']) && !empty($ub['appointment_date'])

                                                ? date('M d, Y', strtotime($ub['appointment_date']))

                                                : '';

                                $display_time = isset($ub['appointment_time']) && !empty($ub['appointment_time'])

                                                ? date('g:i A', strtotime($ub['appointment_time']))

                                                : 'Time not set';

                                $raw_status = $ub['booking_status'] ?? '';

                                $disp_status = empty($raw_status) ? 'Pending' : ucfirst($raw_status);

                                $status_class = strtolower($disp_status);

                            ?>

                            <li>

                                <div class="recent-info">

                                    <span class="recent-name">

                                        <?php echo htmlspecialchars($ub['pet_name'] ?? 'Unknown Pet'); ?>

                                    </span>

                                    <span class="recent-service"><i class="fas fa-clipboard-list" style="margin-right: 6px; color: #94a3b8;"></i><?php echo htmlspecialchars($ub['service'] ?? 'General Service'); ?></span>

                                    <span class="recent-sub"><i class="far fa-calendar" style="margin-right: 6px;"></i><?php echo $display_date; ?> · <?php echo $display_time; ?></span>

                                </div>

                                <span class="recent-status status-<?php echo $status_class; ?>"><?php echo $disp_status; ?></span>

                            </li>

                            <?php endforeach; ?>

                        </ul>

                    <?php else: ?>

                        <div style="text-align: center; color: #94a3b8; padding: 40px 0;">

                            <i class="fas fa-calendar-plus" style="font-size: 35px; margin-bottom: 12px; opacity: 0.3;"></i>

                            <p style="font-size: 13px; font-weight: 500; margin: 0;">No upcoming appointments.</p>

                        </div>

                    <?php endif; ?>

                </div>

            </div>

            </div>

            <footer>© <?php echo date("Y"); ?> BOOGIE'S PET CARE & SERVICES - DASMARIÑAS BRANCH</footer>

        </div>

    </main>

    <script>

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

        // NOTIFICATION FETCHER: avoid overlapping requests and pause while the tab is hidden.

        let previousUnreadCount = <?php echo $unread_count; ?>;
        let adminNotifFetchInProgress = false;

        function fetchAdminNotifs() {

            if (adminNotifFetchInProgress || document.hidden) return;
            adminNotifFetchInProgress = true;

            fetch('get_admin_notifs.php', { cache: 'no-store', credentials: 'same-origin' })

                .then(response => response.json().then(data => ({ ok: response.ok, data })))

                .then(result => {

                    const data = result.data;
                    // Preserve the current display if the endpoint fails; do not
                    // replace a valid unread count with a misleading zero.
                    if (!result.ok || !data || data.success !== true || !Number.isFinite(Number(data.unread))) {
                        return;
                    }

                    const badge = document.getElementById('admin-notif-badge');

                    const notifList = document.getElementById('admin-notif-list');

                    const markReadBtn = document.getElementById('mark-read-link');
                    if (!badge || !notifList) return;

                    if (Number(data.unread) > 0) {

                        badge.style.display = 'inline-block';

                        badge.innerText = String(Number(data.unread));

                        if(markReadBtn) markReadBtn.style.display = 'inline-block';

                    } else {

                        badge.style.display = 'none';

                        if(markReadBtn) markReadBtn.style.display = 'none';

                    }

                    if (typeof data.html === 'string' && data.html !== '') {

                        notifList.innerHTML = data.html;

                    } else {

                        notifList.innerHTML = '<div class="notif-empty">No new notifications.</div>';

                    }

                })

                .catch(error => console.error('Error fetching admin notifications:', error))
                .finally(() => { adminNotifFetchInProgress = false; });

        }

        <?php if ($show_notifications): ?>

        // Initial refresh, then every 15 seconds instead of every 3 seconds.
        fetchAdminNotifs();
        setInterval(fetchAdminNotifs, 15000);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) fetchAdminNotifs();
        });

        <?php endif; ?>


        const dashboardGcashQrForm = document.getElementById('dashboardGcashQrForm');
        if (dashboardGcashQrForm) {
            dashboardGcashQrForm.addEventListener('submit', function () {
                const submitButton = this.querySelector('button[type="submit"]');
                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
                }
            });
        }

    </script>

</body>

</html>
