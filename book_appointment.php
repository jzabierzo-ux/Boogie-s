<?php
require_once __DIR__ . '/shared_session_bootstrap.php';
include 'db_supabase.php';

// Set timezone to Philippines
date_default_timezone_set('Asia/Manila');

$bookingManilaTimezone = new DateTimeZone('Asia/Manila');
$minimumOnlineBookingDate = (new DateTimeImmutable('tomorrow', $bookingManilaTimezone))->format('Y-m-d');

/**
 * Format notification timestamps consistently with the Customer Dashboard.
 * Supabase values without an explicit timezone are treated as UTC.
 */
function formatCustomerNotificationTimestamp($value, string $format): string
{
    if ($value === null || trim((string)$value) === '') {
        return '';
    }

    try {
        $notificationDate = new DateTimeImmutable((string)$value, new DateTimeZone('UTC'));
        return $notificationDate
            ->setTimezone(new DateTimeZone('Asia/Manila'))
            ->format($format);
    } catch (Throwable $e) {
        error_log('Customer notification timestamp formatting failed: ' . $e->getMessage());
        return '';
    }
}


/**
 * Convert a stored pet weight to the size bracket used by booking prices.
 * Supports both current numeric kg values (e.g. 5.2) and legacy size labels.
 */
function bookingSizeFromWeight($weight): ?string
{
    $value = trim((string)($weight ?? ''));
    $allowedSizes = [
        'Small (1-5kg)',
        'Medium (6-10kg)',
        'Large (11-15kg)',
        'Extra Large (16-20kg)',
        'XXL Large (21-25kg)'
    ];

    if ($value === '') {
        return null;
    }

    if (in_array($value, $allowedSizes, true)) {
        return $value;
    }

    // Daycare categories are wider than the existing weight tiers used by other services.
    // Do not silently treat a wide category label as an exact weight for grooming/boarding.
    if (in_array($value, ['Small (1-10kg)', 'Medium (11-20kg)', 'Large (21-50kg)', 'Cat (All Sizes)'], true)) {
        return null;
    }

    if (!preg_match('/(-?\\d+(?:\\.\\d+)?)/', $value, $matches)) {
        return null;
    }

    $kg = (float)$matches[1];
    if ($kg <= 0 || $kg > 25) {
        return null;
    }

    if ($kg <= 5) return 'Small (1-5kg)';
    if ($kg <= 10) return 'Medium (6-10kg)';
    if ($kg <= 15) return 'Large (11-15kg)';
    if ($kg <= 20) return 'Extra Large (16-20kg)';
    return 'XXL Large (21-25kg)';
}

/**
 * Convert pet weight or a legacy size label to the client-confirmed Pet Daycare class.
 * Daycare uses different brackets from grooming/boarding: dogs 1-10, 11-20, 21-50 kg;
 * cats use one all-sizes rate.
 */
function bookingDaycareSizeFromWeight($weight, string $petType): ?string
{
    if ($petType === 'Cat') {
        return 'Cat (All Sizes)';
    }

    $value = trim((string)($weight ?? ''));
    if ($value === '') {
        return null;
    }

    $legacyMap = [
        'Small (1-10kg)' => 'Small (1-10kg)',
        'Medium (11-20kg)' => 'Medium (11-20kg)',
        'Large (21-50kg)' => 'Large (21-50kg)',
        'Small (1-5kg)' => 'Small (1-10kg)',
        'Medium (6-10kg)' => 'Small (1-10kg)',
        'Large (11-15kg)' => 'Medium (11-20kg)',
        'Extra Large (16-20kg)' => 'Medium (11-20kg)',
        'XXL Large (21-25kg)' => 'Large (21-50kg)',
    ];
    if (isset($legacyMap[$value])) {
        return $legacyMap[$value];
    }

    if (!preg_match('/(-?\d+(?:\.\d+)?)/', $value, $matches)) {
        return null;
    }

    $kg = (float)$matches[1];
    if ($kg <= 0 || $kg > 50) {
        return null;
    }

    if ($kg <= 10) return 'Small (1-10kg)';
    if ($kg <= 20) return 'Medium (11-20kg)';
    return 'Large (21-50kg)';
}

/** Convert strict 30-minute appointment start values to minutes after midnight. */
function bookingTimeToMinutes($time): ?int
{
    $value = trim((string)($time ?? ''));
    if (!preg_match('/^(0[9]|1[0-7]):(00|30):00$/', $value, $matches)) {
        return null;
    }
    return ((int)$matches[1] * 60) + (int)$matches[2];
}

/** Identify the top-level category from the service text already stored in appointments. */
function bookingCategoryFromStoredService(string $service): ?string
{
    foreach (['Grooming', 'Vet Services', 'Pet Hotel'] as $category) {
        if (stripos(trim($service), $category . ' -') === 0 || strcasecmp(trim($service), $category) === 0) {
            return $category;
        }
    }
    return null;
}

/** Whether a Pet Hotel service is an overnight boarding reservation. */
function bookingIsHotelBoarding(string $serviceName): bool
{
    $name = strtolower(trim($serviceName));
    return strpos($name, 'boarding') !== false || strpos($name, 'overnight') !== false;
}

/** Whether a Pet Hotel service is hourly daycare. */
function bookingIsHotelDaycare(string $serviceName): bool
{
    return strpos(strtolower(trim($serviceName)), 'daycare') !== false;
}

/** Parse daycare pickup times on the half-hour grid, including the 6:00 PM closing boundary. */
function bookingPickupTimeToMinutes($time): ?int
{
    $value = trim((string)($time ?? ''));
    if (!preg_match('/^(\d{1,2}):(00|30)(?::00)?$/', $value, $matches)) return null;
    $hour = (int)$matches[1];
    $minute = (int)$matches[2];
    if ($hour < 9 || $hour > 18 || ($hour === 18 && $minute !== 0)) return null;
    return ($hour * 60) + $minute;
}

/**
 * Default duration assumptions shared with check_slots.php.
 * These are initial values based on the client interview/adviser examples and can be adjusted later.
 */
function bookingServiceDurationMinutes(string $category, string $serviceName): int
{
    $name = strtolower(trim($serviceName));

    if ($category === 'Grooming') {
        if (strpos($name, 'full grooming package') !== false || preg_match('/\\bfg\\b/', $name)) return 120;
        if (strpos($name, 'bath & blow dry') !== false || strpos($name, 'bath and blow dry') !== false || strpos($name, 'cat bath') !== false) return 90;
        if (strpos($name, 'basic pet grooming') !== false || strpos($name, 'cat grooming (basic)') !== false) return 60;
        return 120; // Sa hindi pa kumpirmadong grooming duration, gamitin ang conservative default.
    }

    if ($category === 'Vet Services') return 30;

    if ($category === 'Pet Hotel') {
        if (strpos($name, 'daycare') !== false) return 180;
        // The appointment time marks the standard 9:00 AM check-in; checkout_date controls the overnight room-reservation range.
        if (strpos($name, 'boarding') !== false || strpos($name, 'overnight') !== false) return 30;
        return 180;
    }

    return 60;
}


/**
 * Generate the online start times for a service-duration-aligned schedule.
 * Each work period starts at its own boundary (9:00 AM and 1:00 PM); times
 * then advance by the selected service duration. Capacity validation still
 * checks the entire service interval against existing appointments.
 */
function bookingDurationAlignedStartTimes(string $category, string $serviceName): array
{
    $openingMinutes = 9 * 60;
    $breakStartMinutes = 12 * 60;
    $breakEndMinutes = 13 * 60;
    $closingMinutes = 18 * 60;

    if ($category === 'Pet Hotel' && bookingIsHotelBoarding($serviceName)) {
        return [$openingMinutes]; // Overnight stays always check in at 9:00 AM.
    }

    if ($category === 'Pet Hotel' && bookingIsHotelDaycare($serviceName)) {
        $times = [];
        foreach ([[$openingMinutes, $breakStartMinutes], [$breakEndMinutes, $closingMinutes]] as [$periodStart, $periodEnd]) {
            for ($start = $periodStart; $start < $periodEnd; $start += 30) $times[] = $start;
        }
        return $times;
    }

    $duration = bookingServiceDurationMinutes($category, $serviceName);
    if ($duration <= 0) return [];

    $times = [];
    foreach ([[$openingMinutes, $breakStartMinutes], [$breakEndMinutes, $closingMinutes]] as [$periodStart, $periodEnd]) {
        for ($start = $periodStart; $start < $periodEnd && ($start + $duration) <= $closingMinutes; $start += $duration) {
            $times[] = $start;
        }
    }

    return array_values(array_unique($times));
}

function bookingIntervalsOverlap(int $startA, int $endA, int $startB, int $endB): bool
{
    return $startA < $endB && $startB < $endA;
}

/** Capacity available at a point in time while lunch breaks are staggered. */
function bookingCapacityAtMinute(string $category, int $minute, int $normalCapacity, int $breakStart, int $breakEnd): int
{
    // One of the two groomers is assumed to take lunch, leaving one available.
    if ($category === 'Grooming' && $minute >= $breakStart && $minute < $breakEnd) return 1;

    // Vet/Hotel coverage remains at the configured capacity because staff shifts
    // are not currently stored in the database.
    return $normalCapacity;
}

/** Collect start points where active bookings or the lunch capacity can change. */
function bookingCapacityCheckpoints(int $candidateStart, int $candidateEnd, array $appointments, int $breakStart, int $breakEnd): array
{
    $checkpoints = [$candidateStart];
    foreach ([$breakStart, $breakEnd] as $boundary) {
        if ($boundary > $candidateStart && $boundary < $candidateEnd) $checkpoints[] = $boundary;
    }
    foreach ($appointments as $appointment) {
        if (!bookingIntervalsOverlap($candidateStart, $candidateEnd, $appointment['start'], $appointment['end'])) continue;
        if ($appointment['start'] > $candidateStart && $appointment['start'] < $candidateEnd) $checkpoints[] = $appointment['start'];
        if ($appointment['end'] > $candidateStart && $appointment['end'] < $candidateEnd) $checkpoints[] = $appointment['end'];
    }
    $checkpoints = array_values(array_unique($checkpoints));
    sort($checkpoints);
    return $checkpoints;
}

/** True when adding a booking would exceed the category's capacity at any point. */
function bookingWouldExceedCapacity(int $candidateStart, int $candidateEnd, array $appointments, string $category, int $normalCapacity, int $breakStart, int $breakEnd): bool
{
    foreach (bookingCapacityCheckpoints($candidateStart, $candidateEnd, $appointments, $breakStart, $breakEnd) as $point) {
        $activeExisting = 0;
        foreach ($appointments as $appointment) {
            if ($appointment['start'] <= $point && $appointment['end'] > $point) $activeExisting++;
        }
        $capacity = bookingCapacityAtMinute($category, $point, $normalCapacity, $breakStart, $breakEnd);
        if ($activeExisting + 1 > $capacity) return true;
    }
    return false;
}


/** Parse admin-entered availability-adjustment times (not limited to online start slots). */
function bookingAdjustmentTimeToMinutes($time): ?int
{
    $value = trim((string)($time ?? ''));
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $matches)) return null;
    $hour = (int)$matches[1];
    $minute = (int)$matches[2];
    if ($hour > 23 || $minute > 59) return null;
    return ($hour * 60) + $minute;
}

/** Load active Admin-created manual adjustments intersecting [startDate, endDateExclusive). */
function bookingLoadManualAvailabilityAdjustments(PDO $pdo, string $startDate, string $endDateExclusive): array
{
    static $tableExists = null;
    if ($tableExists === null) {
        $tableExists = (bool)$pdo->query("SELECT to_regclass('public.availability_adjustments')")->fetchColumn();
    }
    if (!$tableExists) return [];

    $stmt = $pdo->prepare("\n        SELECT id, service_category, COALESCE(specific_service, '') AS specific_service,\n               start_date::text AS start_date, end_date::text AS end_date,\n               start_time::text AS start_time, end_time::text AS end_time,\n               LOWER(BTRIM(adjustment_type)) AS adjustment_type, quantity, reason\n        FROM public.availability_adjustments\n        WHERE is_active = TRUE\n          AND start_date < CAST(:range_end AS date)\n          AND end_date > CAST(:range_start AS date)\n        ORDER BY start_date, start_time NULLS FIRST, id\n    ");
    $stmt->execute([':range_start' => $startDate, ':range_end' => $endDateExclusive]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Count manual occupied pets/rooms towards the global and category daily limits. */
function bookingManualOccupiedUnitsForDate(array $adjustments, string $date, ?string $category = null): int
{
    $total = 0;
    foreach ($adjustments as $adjustment) {
        $startDate = substr((string)($adjustment['start_date'] ?? ''), 0, 10);
        $endDate = substr((string)($adjustment['end_date'] ?? ''), 0, 10);
        if ($startDate === '' || $endDate === '' || $startDate > $date || $endDate <= $date) continue;
        if (strtolower(trim((string)($adjustment['adjustment_type'] ?? ''))) !== 'occupied') continue;
        if ($category !== null && (string)($adjustment['service_category'] ?? '') !== $category) continue;
        $total += max(1, (int)($adjustment['quantity'] ?? 1));
    }
    return $total;
}

/**
 * Turn manual occupied/block adjustments into capacity intervals for the requested service.
 * Occupied units consume shared capacity within their category; service-specific blocks
 * block only the selected service. A block without times closes the full day.
 */
function bookingManualCapacityIntervals(
    array $adjustments,
    string $date,
    string $category,
    string $requestedService,
    int $normalCapacity
): array {
    $intervals = [];
    foreach ($adjustments as $adjustment) {
        $startDate = substr((string)($adjustment['start_date'] ?? ''), 0, 10);
        $endDate = substr((string)($adjustment['end_date'] ?? ''), 0, 10);
        if ($startDate === '' || $endDate === '' || $startDate > $date || $endDate <= $date) continue;
        if ((string)($adjustment['service_category'] ?? '') !== $category) continue;

        $type = strtolower(trim((string)($adjustment['adjustment_type'] ?? '')));
        if (!in_array($type, ['occupied', 'blocked', 'capacity_reduced'], true)) continue;
        $adjustmentService = trim((string)($adjustment['specific_service'] ?? ''));
        if (in_array($type, ['blocked', 'capacity_reduced'], true) && $adjustmentService !== '' && $requestedService !== ''
            && strcasecmp($adjustmentService, $requestedService) !== 0) {
            continue;
        }

        $rawStart = trim((string)($adjustment['start_time'] ?? ''));
        $rawEnd = trim((string)($adjustment['end_time'] ?? ''));
        if ($rawStart === '' && $rawEnd === '') {
            $start = 0;
            $end = 1440;
        } else {
            $start = bookingAdjustmentTimeToMinutes($rawStart);
            $end = bookingAdjustmentTimeToMinutes($rawEnd);
            if ($start === null || $end === null || $end <= $start) continue;
        }

        $units = ($type === 'blocked')
            ? max(1, $normalCapacity)
            : max(1, (int)($adjustment['quantity'] ?? 1));
        for ($i = 0; $i < $units; $i++) {
            $intervals[] = ['start' => $start, 'end' => $end, 'manual_adjustment' => true, 'adjustment_type' => $type];
        }
    }
    return $intervals;
}

// Access Control
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

// Get user info
$full_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : (isset($_SESSION['full_name']) ? $_SESSION['full_name'] : 'User');
$user_id = (int)($_SESSION['user_id'] ?? 0);
if ($user_id <= 0) {
    header('Location: login.php');
    exit();
}

// One CSRF token is used by the booking form and the View All Notifications action.
if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// AJAX endpoint: refresh only the signed-in customer's recent notifications and unread count.
// Cast is_read to text so PostgreSQL BOOLEAN, numeric, and common text representations work.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && ($_GET['ajax_notifications'] ?? '') === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, private, max-age=0, must-revalidate');
    header('Pragma: no-cache');

    try {
        $ajax_count_stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM notifications
            WHERE user_id = :user_id
              AND (
                    is_read IS NULL
                    OR LOWER(CAST(is_read AS TEXT)) IN ('0', 'false', 'f')
                  )
        ");
        $ajax_count_stmt->execute([':user_id' => $user_id]);
        $ajax_unread_count = (int)($ajax_count_stmt->fetchColumn() ?? 0);

        $ajax_list_stmt = $pdo->prepare("
            SELECT
                id,
                message,
                created_at,
                CASE
                    WHEN is_read IS NULL
                      OR LOWER(CAST(is_read AS TEXT)) IN ('0', 'false', 'f')
                    THEN 'false'
                    ELSE 'true'
                END AS is_read_normalized
            FROM notifications
            WHERE user_id = :user_id
            ORDER BY created_at DESC, id DESC
            LIMIT 5
        ");
        $ajax_list_stmt->execute([':user_id' => $user_id]);

        $ajax_notifications = [];
        while ($row = $ajax_list_stmt->fetch(PDO::FETCH_ASSOC)) {
            $ajax_notifications[] = [
                'id' => (string)($row['id'] ?? ''),
                'message' => (string)($row['message'] ?? ''),
                'is_read' => strtolower(trim((string)($row['is_read_normalized'] ?? 'false'))) === 'true',
                'created_at' => formatCustomerNotificationTimestamp($row['created_at'] ?? null, 'M d, Y h:i A'),
            ];
        }

        echo json_encode([
            'success' => true,
            'unread_count' => $ajax_unread_count,
            'notifications' => $ajax_notifications,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        error_log('Book appointment notification refresh failed for user #' . $user_id . ': ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Unable to refresh notifications right now.',
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    exit();
}

$success_msg = '';
$error_msg = '';

// --- FETCH USER DETAILS FOR HEADER (Profile Pic) ---
$profile_pic = '';
try {
    $stmt_user = $pdo->prepare("SELECT profile_image FROM users WHERE id = :id LIMIT 1");
    $stmt_user->execute([':id' => $user_id]);
    $row = $stmt_user->fetch();
    if ($row) {
        $profile_pic = $row['profile_image'] ?? '';
    }
} catch (PDOException $e) {
    $profile_pic = '';
}

// --- FETCH UNREAD NOTIFICATIONS ---
$unread_count = 0;
$notifications = [];
try {
    $stmt_notif = $pdo->prepare("
        SELECT COUNT(*) AS unread_count
        FROM notifications
        WHERE user_id = :user_id
          AND (
                is_read IS NULL
                OR LOWER(CAST(is_read AS TEXT)) IN ('0', 'false', 'f')
              )
    ");
    $stmt_notif->execute([':user_id' => $user_id]);
    $row = $stmt_notif->fetch();
    if ($row) {
        $unread_count = (int)($row['unread_count'] ?? 0);
    }
} catch (PDOException $e) {
    $unread_count = 0;
}

// Fetch latest 5 notifications for dropdown
try {
    $stmt_notif_list = $pdo->prepare("
        SELECT
            id,
            message,
            created_at,
            CASE
                WHEN is_read IS NULL
                  OR LOWER(CAST(is_read AS TEXT)) IN ('0', 'false', 'f')
                THEN 'false'
                ELSE 'true'
            END AS is_read_normalized
        FROM notifications
        WHERE user_id = :user_id
        ORDER BY created_at DESC, id DESC
        LIMIT 5
    ");
    $stmt_notif_list->execute([':user_id' => $user_id]);
    $notifications = $stmt_notif_list->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($notifications as &$notification_row) {
        $notification_row['is_read'] = strtolower(trim((string)($notification_row['is_read_normalized'] ?? 'false'))) === 'true';
        unset($notification_row['is_read_normalized']);
    }
    unset($notification_row);
} catch (PDOException $e) {
    $notifications = [];
}

// --- CATCH THE CATEGORY FROM THE URL ---
$pre_selected_category = isset($_GET['category']) ? trim((string)$_GET['category']) : '';

// Fetch user's registered pets
$user_pets = [];
try {
    $stmt_pets = $pdo->prepare("SELECT id, name, pet_type, gender, weight FROM pets WHERE owner_id = :owner_id ORDER BY id DESC");
    $stmt_pets->execute([':owner_id' => $user_id]);
    $user_pets = $stmt_pets->fetchAll() ?: [];
} catch (PDOException $e) {
    $user_pets = [];
}

// Protect Backend
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $receipt_filename = '';
    $posted_csrf = (string)($_POST['csrf_token'] ?? '');
    $session_csrf = (string)($_SESSION['csrf_token'] ?? '');
    if ($session_csrf === '' || $posted_csrf === '' || !hash_equals($session_csrf, $posted_csrf)) {
        $error_msg = 'Your form session expired. Refresh the page and try again.';
    }

    $registered_pet_id = filter_var($_POST['registered_pet_id'] ?? 0, FILTER_VALIDATE_INT);
    $registered_pet_id = ($registered_pet_id !== false && $registered_pet_id > 0) ? (int)$registered_pet_id : 0;
    $registered_pet_row = null;

    $pet_name = trim((string)($_POST['pet_name'] ?? ''));
    $pet_gender = trim((string)($_POST['pet_gender'] ?? ''));
    $pet_type = trim((string)($_POST['pet_type'] ?? ''));
    $pet_size = trim((string)($_POST['pet_size'] ?? ''));
    $posted_category_for_size = trim((string)($_POST['service_category'] ?? ''));
    $posted_service_for_size = trim((string)($_POST['specific_service'] ?? ''));
    $posted_is_daycare_for_size = ($posted_category_for_size === 'Pet Hotel' && bookingIsHotelDaycare($posted_service_for_size));

    // For a selected registered pet, trust the record owned by the current customer
    // and derive the price bracket from its saved weight when that weight is available.
    if (empty($error_msg) && $registered_pet_id > 0) {
        try {
            $registered_pet_stmt = $pdo->prepare("
                SELECT id, name, pet_type, gender, weight
                FROM pets
                WHERE id = :pet_id AND owner_id = :owner_id
                LIMIT 1
            ");
            $registered_pet_stmt->execute([
                ':pet_id' => $registered_pet_id,
                ':owner_id' => $user_id
            ]);
            $registered_pet_row = $registered_pet_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$registered_pet_row) {
                $error_msg = 'The selected pet could not be found in your account. Please refresh and select it again.';
            } else {
                $pet_name = trim((string)($registered_pet_row['name'] ?? ''));
                $pet_type = trim((string)($registered_pet_row['pet_type'] ?? ''));
                $pet_gender = trim((string)($registered_pet_row['gender'] ?? ''));
                $stored_pet_weight = trim((string)($registered_pet_row['weight'] ?? ''));

                if ($stored_pet_weight !== '') {
                    if ($posted_is_daycare_for_size) {
                        $derived_pet_size = bookingDaycareSizeFromWeight($stored_pet_weight, $pet_type);
                        if ($derived_pet_size === null) {
                            $error_msg = 'Pet Daycare supports dog weights up to 50 kg. Please check the saved weight or contact the clinic.';
                        } else {
                            $pet_size = $derived_pet_size;
                        }
                    } else {
                        $daycareOnlyLabels = ['Small (1-10kg)', 'Medium (11-20kg)', 'Large (21-50kg)', 'Cat (All Sizes)'];
                        if (in_array($stored_pet_weight, $daycareOnlyLabels, true)) {
                            // A Daycare range cannot be safely converted to an exact grooming/weight tier.
                            // Use the generic range selected in this form instead of guessing.
                            $pet_size = trim((string)($_POST['pet_size'] ?? ''));
                            if ($pet_size === '') {
                                $error_msg = 'This pet profile has a Pet Daycare size range saved. Please select the pet\'s current size/weight for this service.';
                            }
                        } else {
                            $derived_pet_size = bookingSizeFromWeight($stored_pet_weight);
                            if ($derived_pet_size === null) {
                                $error_msg = 'The selected pet weight is outside the supported booking size ranges (up to 25 kg). Please contact the clinic.';
                            } else {
                                $pet_size = $derived_pet_size;
                            }
                        }
                    }
                }
            }
        } catch (PDOException $e) {
            error_log('Registered pet lookup failed for booking user #' . $user_id . ': ' . $e->getMessage());
            $error_msg = 'Unable to load the selected pet details. Please try again.';
        }
    }

    $service_category = trim((string)($_POST['service_category'] ?? ''));
    $specific_service = trim((string)($_POST['specific_service'] ?? ''));
    $haircut_style = trim((string)($_POST['haircut_style'] ?? ''));

    $appointment_date = trim((string)($_POST['appointment_date'] ?? ''));
    $appointment_time = trim((string)($_POST['appointment_time'] ?? ''));
    $pickup_time = trim((string)($_POST['pickup_time'] ?? ''));
    $checkout_date = trim((string)($_POST['checkout_date'] ?? ''));
    $remarks = trim((string)($_POST['remarks'] ?? ''));

    $is_overnight_boarding = ($service_category === 'Pet Hotel' && bookingIsHotelBoarding($specific_service));
    $is_daycare = ($service_category === 'Pet Hotel' && bookingIsHotelDaycare($specific_service));
    if ($is_daycare && $pet_size !== '') {
        $normalized_daycare_size = bookingDaycareSizeFromWeight($pet_size, $pet_type);
        if ($normalized_daycare_size !== null) {
            $pet_size = $normalized_daycare_size;
        }
    }
    // Overnight boarding uses the client's standard 9:00 AM check-in marker.
    if ($is_overnight_boarding) {
        $appointment_time = '09:00:00';
        $pickup_time = '';
    } elseif (!$is_daycare) {
        $checkout_date = '';
        $pickup_time = '';
    } else {
        // Daycare uses the appointment time as drop-off and pickup_time as the interval end.
        $checkout_date = '';
    }

    $gcash_ref = trim((string)($_POST['gcash_ref'] ?? ''));

    // Check Terms and Conditions
    if (!isset($_POST['agree_terms'])) {
        $error_msg = "Error: You must agree to the Terms and Conditions before booking.";
    }

    // Backend Field Validation
    if (empty($error_msg) && (
        $pet_name === '' ||
        $pet_type === '' ||
        $pet_gender === '' ||
        $pet_size === '' ||
        $service_category === '' ||
        $specific_service === '' ||
        $appointment_date === '' ||
        $appointment_time === '' ||
        $gcash_ref === ''
    )) {
        $error_msg = "Error: All required information (including GCash Reference Number) must be filled out.";
    }

    // Validate values on the server rather than relying on dropdown restrictions.
    $allowed_pet_types = ['Dog', 'Cat'];
    $allowed_genders = ['Male', 'Female'];
    $allowed_sizes = $is_daycare
        ? ($pet_type === 'Cat'
            ? ['Cat (All Sizes)']
            : ['Small (1-10kg)', 'Medium (11-20kg)', 'Large (21-50kg)'])
        : ['Small (1-5kg)', 'Medium (6-10kg)', 'Large (11-15kg)', 'Extra Large (16-20kg)', 'XXL Large (21-25kg)'];
    $allowed_categories = ['Grooming', 'Vet Services', 'Pet Hotel'];
    $allowed_haircuts = ['Puppy Cut', 'Summer Cut', 'Shave Down', 'Bear Cut', 'Poodle Cut'];

    if (empty($error_msg) && !in_array($pet_type, $allowed_pet_types, true)) {
        $error_msg = 'Please select a valid pet type.';
    } elseif (empty($error_msg) && !in_array($pet_gender, $allowed_genders, true)) {
        $error_msg = 'Please select a valid pet gender.';
    } elseif (empty($error_msg) && !in_array($pet_size, $allowed_sizes, true)) {
        $error_msg = 'Please select a valid pet size.';
    } elseif (empty($error_msg) && !in_array($service_category, $allowed_categories, true)) {
        $error_msg = 'Please select a valid service category.';
    } elseif (empty($error_msg) && strlen($pet_name) > 100) {
        $error_msg = 'Pet name must be 100 characters or fewer.';
    } elseif (empty($error_msg) && strlen($gcash_ref) > 100) {
        $error_msg = 'GCash reference number is too long.';
    } elseif (empty($error_msg) && !preg_match('/^[A-Za-z0-9 -]{4,100}$/', $gcash_ref)) {
        $error_msg = 'Please enter a valid GCash reference number.';
    } elseif (empty($error_msg) && strlen($remarks) > 1000) {
        $error_msg = 'Remarks must be 1,000 characters or fewer.';
    }

    // --- SERVER-SIDE PRICE RECALCULATION ---
    $service_fee = 0;
    $pricing_data = [
        "Dog" => [
            "Grooming" => [
                "Basic Pet Grooming" => ["Small (1-5kg)" => 400, "Medium (6-10kg)" => 500, "Large (11-15kg)" => 650, "Extra Large (16-20kg)" => 850, "XXL Large (21-25kg)" => 1000],
                "Full Grooming Package" => ["Small (1-5kg)" => 450, "Medium (6-10kg)" => 550, "Large (11-15kg)" => 700, "Extra Large (16-20kg)" => 900, "XXL Large (21-25kg)" => 1100],
                "Bath & Blow Dry" => ["Small (1-5kg)" => 300, "Medium (6-10kg)" => 350, "Large (11-15kg)" => 550, "Extra Large (16-20kg)" => 750, "XXL Large (21-25kg)" => 950]
            ],
            "Vet Services" => [
                "Deworming" => ["Small (1-5kg)" => 200, "Medium (6-10kg)" => 250, "Large (11-15kg)" => 300, "Extra Large (16-20kg)" => 350, "XXL Large (21-25kg)" => 450],
                "Vaccination - Anti Rabies" => ["default" => 300],
                "Vaccination - 5 in 1" => ["default" => 450],
                "Vaccination - 6 in 1" => ["default" => 600],
                "Vaccination - 8 in 1" => ["default" => 750]
            ],
            "Pet Hotel" => [
                "Pet Daycare (1st Hour - Succeeding fees apply)" => ["Small (1-10kg)" => 100, "Medium (11-20kg)" => 150, "Large (21-50kg)" => 200],
                "Pet Boarding (Overnight)" => ["Small (1-5kg)" => 500, "Medium (6-10kg)" => 500, "Large (11-15kg)" => 600, "Extra Large (16-20kg)" => 600, "XXL Large (21-25kg)" => 800]
            ]
        ],
        "Cat" => [
            "Grooming" => [
                "Cat Grooming (Basic)" => ["Small (1-5kg)" => 550, "Medium (6-10kg)" => 650, "Large (11-15kg)" => 750, "Extra Large (16-20kg)" => 850, "XXL Large (21-25kg)" => 950],
                "Cat Bath & Blow Dry" => ["Small (1-5kg)" => 400, "Medium (6-10kg)" => 500, "Large (11-15kg)" => 600, "Extra Large (16-20kg)" => 700, "XXL Large (21-25kg)" => 800]
            ],
            "Vet Services" => [
                "Deworming" => ["Small (1-5kg)" => 200, "Medium (6-10kg)" => 250, "Large (11-15kg)" => 300, "Extra Large (16-20kg)" => 350, "XXL Large (21-25kg)" => 450],
                "Vaccination - Anti Rabies" => ["default" => 300],
                "Vaccination - 4 in 1 (Cats)" => ["default" => 900]
            ],
            "Pet Hotel" => [
                "Pet Daycare (1st Hour - Succeeding fees apply)" => ["default" => 150],
                "Pet Boarding (Overnight)" => ["default" => 500]
            ]
        ]
    ];

    if ($is_daycare) {
        if ($pet_type === 'Cat') {
            $service_fee = ($pet_size === 'Cat (All Sizes)') ? 150.0 : 0.0;
        } else {
            $daycare_base_rates = [
                'Small (1-10kg)' => 100.0,
                'Medium (11-20kg)' => 150.0,
                'Large (21-50kg)' => 200.0,
            ];
            $service_fee = (float)($daycare_base_rates[$pet_size] ?? 0);
        }
    } elseif (isset($pricing_data[$pet_type][$service_category][$specific_service])) {
        $service_prices = $pricing_data[$pet_type][$service_category][$specific_service];
        if (isset($service_prices['default'])) {
            $service_fee = (float)$service_prices['default'];
        } elseif (isset($service_prices[$pet_size])) {
            $service_fee = (float)$service_prices[$pet_size];
        }
    }

    // Client-confirmed Pet Daycare billing: the first hour uses the displayed base rate;
    // succeeding time is ₱30/hour, billed in ₱15 half-hour blocks. Any started half-hour
    // block after the first hour is charged as one ₱15 block.
    if (empty($error_msg) && $is_daycare && $service_fee > 0) {
        $daycare_start_for_price = bookingTimeToMinutes($appointment_time);
        $daycare_end_for_price = bookingPickupTimeToMinutes($pickup_time);
        if ($daycare_end_for_price === null) {
            $error_msg = 'Please choose an expected pickup time for Pet Daycare.';
        } elseif ($daycare_start_for_price === null || $daycare_end_for_price <= $daycare_start_for_price) {
            $error_msg = 'Expected pickup time must be later than drop-off time.';
        } else {
            $daycare_duration = $daycare_end_for_price - $daycare_start_for_price;
            $extra_minutes_after_first_hour = max(0, $daycare_duration - 60);
            $half_hour_blocks = (int)ceil($extra_minutes_after_first_hour / 30);
            $service_fee += $half_hour_blocks * 15;
        }
    }

    if (empty($error_msg) && $service_fee <= 0) {
        $error_msg = 'The selected service and pet size are not valid. Please choose the service again.';
    }
    if (empty($error_msg) && $specific_service === 'Full Grooming Package' && !in_array($haircut_style, $allowed_haircuts, true)) {
        $error_msg = 'Please choose a valid haircut style for the Full Grooming Package.';
    }

    $date_obj = DateTimeImmutable::createFromFormat('!Y-m-d', $appointment_date);
    $date_errors = DateTimeImmutable::getLastErrors();
    if (empty($error_msg) && (
        $date_obj === false ||
        $date_obj->format('Y-m-d') !== $appointment_date ||
        ($date_errors !== false && ($date_errors['warning_count'] > 0 || $date_errors['error_count'] > 0))
    )) {
        $error_msg = 'Please select a valid appointment date.';
    }
    if (empty($error_msg) && bookingTimeToMinutes($appointment_time) === null) {
        $error_msg = 'Please select a valid appointment start time from the available schedule.';
    }
    if (empty($error_msg) && $is_daycare) {
        $daycare_start_for_price = bookingTimeToMinutes($appointment_time);
        $daycare_end_for_price = bookingPickupTimeToMinutes($pickup_time);
        if ($daycare_end_for_price === null) {
            $error_msg = 'Please choose an expected pickup time for Pet Daycare.';
        } elseif ($daycare_start_for_price === null || $daycare_end_for_price <= $daycare_start_for_price) {
            $error_msg = 'Expected pickup time must be later than drop-off time.';
        } elseif ($daycare_end_for_price > 18 * 60) {
            $error_msg = 'Pet Daycare pickup must be no later than 6:00 PM.';
        }
    }

    $stay_nights = 1;
    $checkout_date_to_save = null;
    if (empty($error_msg) && $is_overnight_boarding) {
        $checkin_object = DateTimeImmutable::createFromFormat('!Y-m-d', $appointment_date);
        $checkin_errors = DateTimeImmutable::getLastErrors();
        $checkout_object = DateTimeImmutable::createFromFormat('!Y-m-d', $checkout_date);
        $checkout_errors = DateTimeImmutable::getLastErrors();
        $checkin_valid = $checkin_object !== false && $checkin_object->format('Y-m-d') === $appointment_date
            && ($checkin_errors === false || ($checkin_errors['warning_count'] === 0 && $checkin_errors['error_count'] === 0));
        $checkout_valid = $checkout_object !== false && $checkout_object->format('Y-m-d') === $checkout_date
            && ($checkout_errors === false || ($checkout_errors['warning_count'] === 0 && $checkout_errors['error_count'] === 0));

        if (!$checkin_valid || !$checkout_valid) {
            $error_msg = 'Please select valid check-in and check-out dates for Pet Boarding.';
        } elseif ($checkout_date <= $appointment_date) {
            $error_msg = 'The check-out date must be at least one day after check-in.';
        } else {
            $stay_nights = (int)$checkin_object->diff($checkout_object)->days;
            if ($stay_nights < 1) {
                $error_msg = 'Pet Boarding must be booked for at least one night.';
            } else {
                $checkout_date_to_save = $checkout_date;
                // Existing overnight prices are treated as per-night rates.
                $service_fee *= $stay_nights;
            }
        }
    }

    // --- SERVICE DURATION, OPERATING HOURS, STAFF BREAK, AND CAPACITY VALIDATION ---
    // Existing active-status rule retained; all capacity checks are repeated server-side.
    $active_status_sql = "LOWER(BTRIM(COALESCE(booking_status, ''))) NOT IN ('cancelled', 'canceled', 'no-show', 'no show', 'noshow', 'for rescheduling')";

    if (empty($error_msg)) {
        $selected_start_minutes = bookingTimeToMinutes($appointment_time);
        $selected_pickup_minutes = $is_daycare ? bookingPickupTimeToMinutes($pickup_time) : null;
        $service_duration_minutes = bookingServiceDurationMinutes($service_category, $specific_service);
        $opening_minutes = 9 * 60;
        $closing_minutes = 18 * 60;
        $break_start_minutes = 12 * 60;
        $break_end_minutes = 13 * 60;
        $selected_end_minutes = $is_daycare
            ? (int)($selected_pickup_minutes ?? 0)
            : (($selected_start_minutes ?? 0) + $service_duration_minutes);
        // Online bookings must be submitted at least one calendar day in advance.
        // Enforce this on the server even if the browser date picker is bypassed.
        if ($appointment_date < $minimumOnlineBookingDate) {
            $error_msg = 'Online bookings must be made at least one calendar day in advance. Please select tomorrow or a later date.';
        } elseif ($selected_start_minutes === null) {
            $error_msg = 'Please select a valid appointment time.';
        } elseif ($is_daycare && ($selected_pickup_minutes === null || $selected_pickup_minutes <= $selected_start_minutes)) {
            $error_msg = 'Please choose an expected pickup time later than the drop-off time.';
        } elseif (!in_array($selected_start_minutes, bookingDurationAlignedStartTimes($service_category, $specific_service), true)) {
            $error_msg = 'Please select one of the available start times listed for this service duration.';
        } elseif ($selected_start_minutes < $opening_minutes || $selected_end_minutes > $closing_minutes) {
            $error_msg = 'This service does not fit within business hours (9:00 AM–6:00 PM). Please choose an earlier time.';
        } elseif ($selected_start_minutes >= $break_start_minutes && $selected_start_minutes < $break_end_minutes) {
            $error_msg = 'New appointments cannot start between 12:00 PM and 1:00 PM. Services started before lunch may continue through lunch if staff capacity allows.';
        } elseif ($service_category === 'Vet Services' && in_array((int)date('N', strtotime($appointment_date)), [3, 6], true)) {
            $error_msg = 'The veterinarian is not available on Wednesdays and Saturdays.';
        }

        if (empty($error_msg)) {
            try {
                $pdo->beginTransaction();

                $daily_limits = ['Grooming' => 10, 'Vet Services' => 30, 'Pet Hotel' => 6];
                $simultaneous_limits = ['Grooming' => 2, 'Vet Services' => 2, 'Pet Hotel' => 6];

                // Boarding locks each occupied date in chronological order so a regular booking
                // and another boarding request serialize against the same date-level capacity.
                if ($is_overnight_boarding) {
                    $stay_start = new DateTimeImmutable($appointment_date, new DateTimeZone('Asia/Manila'));
                    $stay_end = new DateTimeImmutable($checkout_date, new DateTimeZone('Asia/Manila'));
                    for ($lock_day = $stay_start; $lock_day < $stay_end; $lock_day = $lock_day->modify('+1 day')) {
                        $capacity_lock = $pdo->prepare("SELECT pg_advisory_xact_lock(hashtext(:lock_key)::bigint)");
                        $capacity_lock->execute([':lock_key' => 'boogie-capacity|' . $lock_day->format('Y-m-d')]);
                    }

                    $stmt_existing = $pdo->prepare("
                        SELECT appointment_date::text AS appointment_date,
                               appointment_time::text AS appointment_time,
                               checkout_date::text AS checkout_date,
                               pickup_time::text AS pickup_time,
                               service
                        FROM appointments
                        WHERE {$active_status_sql}
                          AND appointment_date < CAST(:checkout_date AS date)
                          AND COALESCE(checkout_date, appointment_date + 1) > CAST(:checkin_date AS date)
                        ORDER BY appointment_date, appointment_time
                    ");
                    $stmt_existing->execute([
                        ':checkout_date' => $checkout_date,
                        ':checkin_date' => $appointment_date,
                    ]);
                    $range_rows = $stmt_existing->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    // Include Admin-entered occupied rooms and blocked periods for every night.
                    $manual_adjustments = bookingLoadManualAvailabilityAdjustments($pdo, $appointment_date, $checkout_date);

                    for ($stay_day = $stay_start; $stay_day < $stay_end; $stay_day = $stay_day->modify('+1 day')) {
                        $day_string = $stay_day->format('Y-m-d');
                        $day_total = 0;
                        $day_hotel_count = 0;
                        $day_hotel_intervals = [];

                        foreach ($range_rows as $existing_row) {
                            $stored_service = (string)($existing_row['service'] ?? '');
                            $stored_category = bookingCategoryFromStoredService($stored_service);
                            $existing_date = substr((string)($existing_row['appointment_date'] ?? ''), 0, 10);
                            if ($existing_date === '') continue;

                            $existing_is_boarding = ($stored_category === 'Pet Hotel' && bookingIsHotelBoarding($stored_service));
                            if ($existing_is_boarding) {
                                $existing_checkout = substr((string)($existing_row['checkout_date'] ?? ''), 0, 10);
                                if ($existing_checkout === '') {
                                    $existing_checkout = (new DateTimeImmutable($existing_date))->modify('+1 day')->format('Y-m-d');
                                }
                                $active_on_day = ($existing_date <= $day_string && $existing_checkout > $day_string);
                            } else {
                                $active_on_day = ($existing_date === $day_string);
                            }

                            if (!$active_on_day) continue;
                            $day_total++;

                            if ($stored_category === 'Pet Hotel') {
                                $day_hotel_count++;
                                if ($existing_is_boarding) {
                                    // A boarding pet occupies its room throughout each date before checkout.
                                    $day_hotel_intervals[] = ['start' => 0, 'end' => 1440];
                                } else {
                                    $existing_start = bookingTimeToMinutes((string)($existing_row['appointment_time'] ?? ''));
                                    if ($existing_start !== null) {
                                        $stored_pickup = bookingIsHotelDaycare($stored_service)
                                            ? bookingPickupTimeToMinutes((string)($existing_row['pickup_time'] ?? ''))
                                            : null;
                                        $existing_end = ($stored_pickup !== null && $stored_pickup > $existing_start)
                                            ? $stored_pickup
                                            : ($existing_start + bookingServiceDurationMinutes('Pet Hotel', $stored_service));
                                        $day_hotel_intervals[] = ['start' => $existing_start, 'end' => $existing_end];
                                    }
                                }
                            }
                        }

                        // Manual occupied units count as pets/rooms for daily limits;
                        // blocked intervals reserve capacity but are not actual bookings.
                        $day_total += bookingManualOccupiedUnitsForDate($manual_adjustments, $day_string);
                        $day_hotel_count += bookingManualOccupiedUnitsForDate($manual_adjustments, $day_string, 'Pet Hotel');
                        foreach (bookingManualCapacityIntervals(
                            $manual_adjustments,
                            $day_string,
                            'Pet Hotel',
                            $specific_service,
                            $simultaneous_limits['Pet Hotel']
                        ) as $manual_interval) {
                            $day_hotel_intervals[] = $manual_interval;
                        }

                        if ($day_total >= 20) {
                            $error_msg = 'The overall daily capacity of 20 pets has been reached on ' . $day_string . '. Please choose a shorter stay or different dates.';
                            break;
                        }
                        if ($day_hotel_count >= $daily_limits['Pet Hotel']) {
                            $error_msg = 'Pet Hotel has reached its capacity on ' . $day_string . '. Please choose different dates.';
                            break;
                        }
                        if (bookingWouldExceedCapacity(0, 1440, $day_hotel_intervals, 'Pet Hotel', $simultaneous_limits['Pet Hotel'], $break_start_minutes, $break_end_minutes)) {
                            $error_msg = 'No Pet Hotel room is available for the entire stay. Capacity is reached on ' . $day_string . '.';
                            break;
                        }
                    }
                } else {
                    $capacity_lock = $pdo->prepare("SELECT pg_advisory_xact_lock(hashtext(:lock_key)::bigint)");
                    $capacity_lock->execute([':lock_key' => 'boogie-capacity|' . $appointment_date]);

                    // Also include overnight pets whose stay began earlier and continues today.
                    $stmt_existing = $pdo->prepare("
                        SELECT appointment_date::text AS appointment_date,
                               appointment_time::text AS appointment_time,
                               checkout_date::text AS checkout_date,
                               pickup_time::text AS pickup_time,
                               service
                        FROM appointments
                        WHERE {$active_status_sql}
                          AND (
                              appointment_date = CAST(:appointment_date_exact AS date)
                              OR (
                                  service ILIKE 'Pet Hotel%boarding%'
                                  AND appointment_date < CAST(:appointment_date_before AS date)
                                  AND COALESCE(checkout_date, appointment_date + 1) > CAST(:appointment_date_active AS date)
                              )
                          )
                        ORDER BY appointment_time
                    ");
                    $stmt_existing->execute([
                        ':appointment_date_exact' => $appointment_date,
                        ':appointment_date_before' => $appointment_date,
                        ':appointment_date_active' => $appointment_date,
                    ]);
                    $existing_rows = $stmt_existing->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    $next_date_exclusive = (new DateTimeImmutable($appointment_date, new DateTimeZone('Asia/Manila')))
                        ->modify('+1 day')->format('Y-m-d');
                    $manual_adjustments = bookingLoadManualAvailabilityAdjustments($pdo, $appointment_date, $next_date_exclusive);

                    $total_daily_bookings = count($existing_rows)
                        + bookingManualOccupiedUnitsForDate($manual_adjustments, $appointment_date);
                    $category_daily_counts = ['Grooming' => 0, 'Vet Services' => 0, 'Pet Hotel' => 0];
                    $existing_intervals = ['Grooming' => [], 'Vet Services' => [], 'Pet Hotel' => []];

                    foreach ($existing_rows as $existing_row) {
                        $stored_service = (string)($existing_row['service'] ?? '');
                        $stored_category = bookingCategoryFromStoredService($stored_service);
                        if ($stored_category === null) continue;
                        $category_daily_counts[$stored_category]++;

                        $existing_date = substr((string)($existing_row['appointment_date'] ?? ''), 0, 10);
                        $existing_is_boarding = ($stored_category === 'Pet Hotel' && bookingIsHotelBoarding($stored_service));
                        if ($existing_is_boarding && $existing_date <= $appointment_date) {
                            $existing_checkout = substr((string)($existing_row['checkout_date'] ?? ''), 0, 10);
                            if ($existing_checkout === '') {
                                $existing_checkout = (new DateTimeImmutable($existing_date))->modify('+1 day')->format('Y-m-d');
                            }
                            if ($existing_checkout > $appointment_date) {
                                $existing_intervals['Pet Hotel'][] = ['start' => 0, 'end' => 1440];
                            }
                            continue;
                        }

                        if ($existing_date !== $appointment_date) continue;
                        $existing_start = bookingTimeToMinutes((string)($existing_row['appointment_time'] ?? ''));
                        if ($existing_start === null) continue;
                        $stored_pickup = ($stored_category === 'Pet Hotel' && bookingIsHotelDaycare($stored_service))
                            ? bookingPickupTimeToMinutes((string)($existing_row['pickup_time'] ?? ''))
                            : null;
                        $existing_end = ($stored_pickup !== null && $stored_pickup > $existing_start)
                            ? $stored_pickup
                            : ($existing_start + bookingServiceDurationMinutes($stored_category, $stored_service));
                        $existing_intervals[$stored_category][] = [
                            'start' => $existing_start,
                            'end' => $existing_end,
                        ];
                    }

                    // Add per-category manual occupancy to daily quotas and selected-service
                    // intervals to the exact same capacity check used for appointments.
                    foreach (array_keys($category_daily_counts) as $manual_category) {
                        $category_daily_counts[$manual_category] += bookingManualOccupiedUnitsForDate(
                            $manual_adjustments,
                            $appointment_date,
                            $manual_category
                        );
                    }
                    foreach (bookingManualCapacityIntervals(
                        $manual_adjustments,
                        $appointment_date,
                        $service_category,
                        $specific_service,
                        $simultaneous_limits[$service_category]
                    ) as $manual_interval) {
                        $existing_intervals[$service_category][] = $manual_interval;
                    }

                    if ($total_daily_bookings >= 20) {
                        $error_msg = 'The overall daily capacity of 20 pets has been reached. Please select another date.';
                    } elseif ($category_daily_counts[$service_category] >= $daily_limits[$service_category]) {
                        $error_msg = $service_category . ' has reached its daily capacity. Please select another date.';
                    } elseif (bookingWouldExceedCapacity(
                        $selected_start_minutes,
                        $selected_end_minutes,
                        $existing_intervals[$service_category],
                        $service_category,
                        $simultaneous_limits[$service_category],
                        $break_start_minutes,
                        $break_end_minutes
                    )) {
                        if ($service_category === 'Grooming') {
                            $error_msg = 'Both groomers are already booked during part of this service. Please select another time.';
                        } elseif ($service_category === 'Vet Services') {
                            $error_msg = 'Both the doctor and reliever are already booked or unavailable during this time. Please select another time.';
                        } else {
                            $error_msg = 'No Pet Hotel room is available during this time. Please select another time.';
                        }
                    }
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Appointment capacity check failed: ' . $e->getMessage());
                $error_msg = 'Unable to check appointment availability. Please try again.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Appointment capacity calculation failed: ' . $e->getMessage());
                $error_msg = 'Unable to verify the selected schedule. Please try again.';
            }
        }
    }

    // --- GCASH RECEIPT UPLOAD HANDLING (REQUIRED) ---
    if (empty($error_msg)) {
        $receipt_file = $_FILES['gcash_receipt'] ?? null;
        if (!is_array($receipt_file) || ($receipt_file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $error_msg = 'Please upload a screenshot of your GCash receipt.';
        } elseif (($receipt_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error_msg = 'Error uploading receipt image. Please try again.';
        } else {
            $tmp_path = (string)($receipt_file['tmp_name'] ?? '');
            $file_size = (int)($receipt_file['size'] ?? 0);
            $extension = strtolower(pathinfo((string)($receipt_file['name'] ?? ''), PATHINFO_EXTENSION));
            $allowed_mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
            $detected_mime = '';
            if ($tmp_path !== '' && is_uploaded_file($tmp_path) && class_exists('finfo')) {
                $file_info = new finfo(FILEINFO_MIME_TYPE);
                $detected_mime = (string)$file_info->file($tmp_path);
            }
            $image_info = ($tmp_path !== '' && is_uploaded_file($tmp_path)) ? @getimagesize($tmp_path) : false;

            if ($file_size <= 0 || $file_size > 5 * 1024 * 1024) {
                $error_msg = 'Receipt image must be no larger than 5 MB.';
            } elseif (!isset($allowed_mimes[$extension]) || $detected_mime !== $allowed_mimes[$extension] || $image_info === false) {
                $error_msg = 'Invalid receipt image. Only genuine JPG and PNG images are allowed.';
            } else {
                $upload_dir = __DIR__ . '/uploads';
                if (!is_dir($upload_dir) && !@mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
                    $error_msg = 'Receipt storage is unavailable. Please try again later.';
                } else {
                    $receipt_filename = 'receipt_' . time() . '_' . $user_id . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
                    if (!move_uploaded_file($tmp_path, $upload_dir . '/' . $receipt_filename)) {
                        $receipt_filename = '';
                        $error_msg = 'Failed to save the receipt image. Please try again.';
                    }
                }
            }
        }
    }

    if (empty($error_msg)) {
        try {
            // 1. Use the selected registered pet directly, without changing its saved
            // numeric weight into a pricing bracket. Otherwise find/create by name.
            if ($registered_pet_id > 0 && $registered_pet_row) {
                $pet_id = (int)$registered_pet_row['id'];
            } else {
                $stmt = $pdo->prepare("SELECT id FROM pets WHERE owner_id = :owner_id AND name = :name LIMIT 1");
                $stmt->execute([
                    ':owner_id' => $user_id,
                    ':name' => $pet_name
                ]);
                $pet = $stmt->fetch();

                if ($pet) {
                    // Preserve existing pet-profile data, especially numeric weight.
                    $pet_id = (int)$pet['id'];
                } else {
                    $insert_pet = $pdo->prepare("INSERT INTO pets (owner_id, name, pet_type, gender, weight) VALUES (:owner_id, :name, :pet_type, :gender, :weight) RETURNING id");
                    $insert_pet->execute([
                        ':owner_id' => $user_id,
                        ':name' => $pet_name,
                        ':pet_type' => $pet_type,
                        ':gender' => $pet_gender,
                        ':weight' => $pet_size
                    ]);
                    $new_pet = $insert_pet->fetch();
                    $pet_id = $new_pet ? (int)$new_pet['id'] : 0;
                }
            }

            if ($pet_id <= 0) {
                throw new RuntimeException('Unable to create or find the pet record.');
            }

            // 2. Format Service Name
            $final_service_name = $service_category . " - " . $specific_service;
            if ($haircut_style !== '' && $specific_service === 'Full Grooming Package') {
                $final_service_name .= " (" . $haircut_style . ")";
            }

            // 3. Insert Appointment with GCash Details
            $payment_method = 'GCash';
            $payment_status = 'Pending Verification';
            $booking_status = 'Pending';

            if ($checkout_date_to_save !== null) {
                // Boarding stores its check-out date so the entire stay can be checked later.
                $insert_appt = $pdo->prepare("INSERT INTO appointments (user_id, pet_id, service, appointment_date, appointment_time, checkout_date, service_fee, total_price, gcash_ref, gcash_receipt, payment_method, payment_status, booking_status, remarks) VALUES (:user_id, :pet_id, :service, :appointment_date, :appointment_time, :checkout_date, :service_fee, :total_price, :gcash_ref, :gcash_receipt, :payment_method, :payment_status, :booking_status, :remarks) RETURNING id");
                $insert_appt->execute([
                    ':user_id' => $user_id,
                    ':pet_id' => $pet_id,
                    ':service' => $final_service_name,
                    ':appointment_date' => $appointment_date,
                    ':appointment_time' => $appointment_time,
                    ':checkout_date' => $checkout_date_to_save,
                    ':service_fee' => $service_fee,
                    ':total_price' => $service_fee,
                    ':gcash_ref' => $gcash_ref,
                    ':gcash_receipt' => $receipt_filename,
                    ':payment_method' => $payment_method,
                    ':payment_status' => $payment_status,
                    ':booking_status' => $booking_status,
                    ':remarks' => $remarks
                ]);
            } elseif ($is_daycare) {
                // Store the expected pickup time so staff can see the full reserved interval.
                $insert_appt = $pdo->prepare("INSERT INTO appointments (user_id, pet_id, service, appointment_date, appointment_time, pickup_time, service_fee, total_price, gcash_ref, gcash_receipt, payment_method, payment_status, booking_status, remarks) VALUES (:user_id, :pet_id, :service, :appointment_date, :appointment_time, :pickup_time, :service_fee, :total_price, :gcash_ref, :gcash_receipt, :payment_method, :payment_status, :booking_status, :remarks) RETURNING id");
                $insert_appt->execute([
                    ':user_id' => $user_id,
                    ':pet_id' => $pet_id,
                    ':service' => $final_service_name,
                    ':appointment_date' => $appointment_date,
                    ':appointment_time' => $appointment_time,
                    ':pickup_time' => $pickup_time,
                    ':service_fee' => $service_fee,
                    ':total_price' => $service_fee,
                    ':gcash_ref' => $gcash_ref,
                    ':gcash_receipt' => $receipt_filename,
                    ':payment_method' => $payment_method,
                    ':payment_status' => $payment_status,
                    ':booking_status' => $booking_status,
                    ':remarks' => $remarks
                ]);
            } else {
                // Preserve the existing insert path for Grooming and Vet bookings.
                $insert_appt = $pdo->prepare("INSERT INTO appointments (user_id, pet_id, service, appointment_date, appointment_time, service_fee, total_price, gcash_ref, gcash_receipt, payment_method, payment_status, booking_status, remarks) VALUES (:user_id, :pet_id, :service, :appointment_date, :appointment_time, :service_fee, :total_price, :gcash_ref, :gcash_receipt, :payment_method, :payment_status, :booking_status, :remarks) RETURNING id");
                $insert_appt->execute([
                    ':user_id' => $user_id,
                    ':pet_id' => $pet_id,
                    ':service' => $final_service_name,
                    ':appointment_date' => $appointment_date,
                    ':appointment_time' => $appointment_time,
                    ':service_fee' => $service_fee,
                    ':total_price' => $service_fee,
                    ':gcash_ref' => $gcash_ref,
                    ':gcash_receipt' => $receipt_filename,
                    ':payment_method' => $payment_method,
                    ':payment_status' => $payment_status,
                    ':booking_status' => $booking_status,
                    ':remarks' => $remarks
                ]);
            }
            $appointment_row = $insert_appt->fetch();
            $appointment_id = $appointment_row ? (int)$appointment_row['id'] : 0;

            if ($appointment_id <= 0) {
                throw new RuntimeException('The appointment could not be created.');
            }

            // --- START: AUTOMATIC TASK FOR VET ---
            // Task creation is secondary; a task-table issue must not discard a valid booking.
            if ($service_category === 'Vet Services') {
                $pdo->exec('SAVEPOINT vet_task_savepoint');
                try {
                    $formatted_time = date("g:i A", strtotime($appointment_time));
                    $task_msg = "Upcoming Appointment: " . $pet_name . " (" . $specific_service . ") on " . $appointment_date . " at " . $formatted_time;
                    $vet_staff_name = "Dr. Faith Casayuran";
                    $stmt_task = $pdo->prepare("INSERT INTO tasks (staff_name, task_text) VALUES (:staff_name, :task_text)");
                    $stmt_task->execute([
                        ':staff_name' => $vet_staff_name,
                        ':task_text' => $task_msg
                    ]);
                    $pdo->exec('RELEASE SAVEPOINT vet_task_savepoint');
                } catch (PDOException $task_error) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT vet_task_savepoint');
                    $pdo->exec('RELEASE SAVEPOINT vet_task_savepoint');
                    error_log('Vet task creation failed for booking user #' . $user_id . ': ' . $task_error->getMessage());
                }
            }

            // --- START: ADMIN NOTIFICATION ---
            // A failed optional alert must not roll back an otherwise valid booking.
            $pdo->exec('SAVEPOINT admin_notif_savepoint');
            try {
                $admin_msg = $full_name . " booked a new appointment for " . $pet_name . " via GCash. Pending Payment Verification.";
                $stmt_admin_notif = $pdo->prepare("INSERT INTO admin_notifications (message) VALUES (:message)");
                $stmt_admin_notif->execute([':message' => $admin_msg]);
                $pdo->exec('RELEASE SAVEPOINT admin_notif_savepoint');
            } catch (PDOException $notif_error) {
                $pdo->exec('ROLLBACK TO SAVEPOINT admin_notif_savepoint');
                $pdo->exec('RELEASE SAVEPOINT admin_notif_savepoint');
                error_log('Admin notification insert failed after booking for user #' . $user_id . ': ' . $notif_error->getMessage());
            }

            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            header("Location: bookings.php?msg=success");
            exit();
        } catch (PDOException | RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Appointment booking failed for user #' . $user_id . ': ' . $e->getMessage());
            $error_msg = 'Unable to complete your booking right now. Please verify the details and try again.';
        }
    }
}

// Roll back an open capacity transaction and remove any receipt if booking failed.
if ($error_msg !== '') {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (!empty($receipt_filename)) {
        $orphan_path = __DIR__ . '/uploads/' . basename($receipt_filename);
        if (is_file($orphan_path)) {
            @unlink($orphan_path);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Appointment | Boogie's Pet Care Services</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>

        :root {
            --brand-blue: #001f3f;
            --brand-blue-2: #0b3b66;
            --brand-yellow: #ffcc00;
            --brand-yellow-soft: #fff7d6;
            --brand-purple: #8b4bd6;
            --brand-purple-dark: #7136b4;
            --page-bg: #f5f8fb;
            --white: #ffffff;
            --text: #17324d;
            --muted: #6b7c8f;
            --line: #e3eaf1;
            --soft: #f8fafc;
            --success: #168553;
            --danger: #c73b47;
            --shadow: 0 10px 30px rgba(0,31,63,.06);
            --shadow-lg: 0 18px 42px rgba(0,31,63,.09);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        html { scroll-behavior: smooth; }

        body {
            min-height: 100vh;
            background: var(--page-bg);
            color: var(--text);
            line-height: 1.6;
            overflow-y: scroll;
            overflow-x: hidden;
        }

        /* ===== HEADER ===== */
        .promo-bar {
            background: var(--brand-blue);
            color: #fff;
            text-align: center;
            padding: 7px 16px;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: .2px;
            border-top: 3px solid var(--brand-yellow);
        }

        .promo-bar i {
            color: var(--brand-yellow);
            margin-right: 7px;
        }

        header {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: rgba(255,255,255,.97);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--line);
            box-shadow: 0 4px 18px rgba(0,0,0,.04);
        }

        .nav-top {
            max-width: 1320px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 25px;
            padding: 14px 28px;
        }

        .logo {
            display: inline-flex;
            align-items: center;
            gap: 11px;
            text-decoration: none;
            min-width: 225px;
        }

        .nav-logo-img {
            width: 50px;
            height: 50px;
            object-fit: contain;
            border-radius: 10px;
            display: block;
        }

        .logo-text {
            display: flex;
            flex-direction: column;
            line-height: 1.05;
        }

        .logo-text b {
            font-size: 20px;
            color: var(--brand-blue);
            font-weight: 800;
        }

        .logo-text span {
            margin-top: 3px;
            color: #8c9aae;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 1.2px;
        }

        .user-controls {
            display: flex;
            align-items: center;
            gap: 18px;
        }

        .notification-wrapper,
        .profile-wrapper {
            position: relative;
        }

        .notification-bell {
            position: relative;
            width: 42px;
            height: 42px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: #fff;
            color: var(--brand-blue);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .notification-bell:hover,
        .profile-trigger:hover {
            background: #f8fafc;
        }

        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--danger);
            color: #fff;
            font-size: 9px;
            font-weight: 800;
            border: 2px solid #fff;
            border-radius: 999px;
        }

        .profile-trigger {
            min-height: 42px;
            padding: 4px 8px 4px 5px;
            display: flex;
            align-items: center;
            gap: 9px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: #fff;
            color: var(--text);
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
        }

        .profile-img {
            width: 34px !important;
            height: 34px !important;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--brand-blue) !important;
        }

        .dropdown-menu {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: 270px;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 14px;
            box-shadow: var(--shadow-lg);
            display: none;
            flex-direction: column;
            z-index: 1001;
            overflow: hidden;
        }

        .dropdown-menu.active { display: flex; }

        .dropdown-header {
            padding: 14px 16px;
            background: #f8fafc;
            border-bottom: 1px solid var(--line);
            color: var(--muted);
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .7px;
        }

        .dropdown-item {
            display: block;
            padding: 12px 16px;
            text-decoration: none;
            color: var(--text);
            font-size: 12px;
            border-bottom: 1px solid #eef2f5;
            transition: .18s;
        }

        .dropdown-item:hover {
            background: #f8fafc;
            color: var(--brand-blue);
        }

        .dropdown-item i {
            width: 18px;
            margin-right: 7px;
            text-align: center;
        }

        .dropdown-item.unread {
            background: #eef6ff;
            font-weight: 700;
        }

        .dropdown-item:last-child { border-bottom: 0; }
        .view-all-link { text-align: center; color: var(--brand-blue); font-weight: 800; }
        .notif-view-all-button {
            width: 100%;
            border: 0;
            border-radius: 0;
            background: transparent;
            font: inherit;
            cursor: pointer;
        }

        /* ===== BOOKING PAGE ===== */
        main {
            width: min(1120px, 92%);
            margin: 0 auto;
            padding: 42px 0 75px;
        }

        .page-intro { margin-bottom: 24px; }

        .back-nav { margin-bottom: 14px; }

        .back-nav a {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            text-decoration: none;
            color: #748396;
            font-size: 11px;
            font-weight: 700;
        }

        .back-nav a:hover { color: var(--brand-blue); }

        .page-title-row {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 25px;
        }

        .page-kicker {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: var(--brand-yellow-soft);
            border: 1px solid #ffe594;
            color: #8c6800;
            padding: 7px 12px;
            border-radius: 999px;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .7px;
            font-weight: 800;
            margin-bottom: 10px;
        }

        .page-title h1 {
            color: var(--brand-blue);
            font-size: 33px;
            line-height: 1.2;
            font-weight: 800;
            letter-spacing: -.5px;
        }

        .page-title p {
            color: var(--muted);
            font-size: 13px;
            margin-top: 6px;
        }

        .booking-summary-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 13px;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 12px;
            color: #66798b;
            font-size: 10px;
            font-weight: 700;
            white-space: nowrap;
        }

        .booking-summary-pill i { color: var(--success); }

        .booking-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 285px;
            gap: 22px;
            align-items: start;
        }

        .booking-card,
        .side-card {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 20px;
            box-shadow: var(--shadow);
        }

        .booking-card { padding: 30px; }
        .side-card {
            padding: 22px;
            position: sticky;
            top: 115px;
        }

        /* Original form controls, restyled */
        .booking-card > form {
            width: 100%;
        }

        .form-group { margin-bottom: 17px; }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .booking-card label {
            display: block;
            color: var(--text);
            font-size: 11px;
            font-weight: 800;
            margin-bottom: 7px;
        }

        .booking-card input[type="text"],
        .booking-card input[type="date"],
        .booking-card select,
        .booking-card textarea,
        .booking-card input[type="file"] {
            width: 100%;
            padding: 11px 13px;
            border: 1px solid #dce5ee;
            border-radius: 11px;
            background: #fbfcfe;
            color: var(--text);
            font-family: inherit;
            font-size: 12px;
            outline: none;
            transition: border-color .2s, box-shadow .2s, background .2s;
        }

        .booking-card input:focus,
        .booking-card select:focus,
        .booking-card textarea:focus {
            border-color: #9bb6cc;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(0,31,63,.05);
        }

        .booking-card input[readonly] {
            background: #eef1f4;
        }

        .booking-card textarea {
            resize: vertical;
            min-height: 90px;
        }

        .booking-container,
        .header-section {
            all: unset;
        }

        /* Original header-section becomes compact form heading if still present. */
        .booking-card .header-section {
            display: block;
            padding: 0 0 18px;
            margin: 0 0 22px;
            border-bottom: 1px solid var(--line);
            text-align: left;
        }

        .booking-card .header-section h1 {
            color: var(--brand-blue);
            font-size: 19px;
            font-weight: 800;
            margin: 0 0 4px;
        }

        .booking-card .header-section p {
            color: var(--muted);
            font-size: 11px;
        }

        .booking-card .walkin-notice {
            background: #eef3ff;
            color: #4b45a4;
            padding: 11px 12px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
            margin-top: 13px;
            border: 1px dashed #8d84e8;
        }

        .booking-card .alert {
            padding: 12px 14px;
            border-radius: 11px;
            margin-bottom: 19px;
            font-size: 11px;
        }

        .booking-card .alert-error {
            background: #fff0f1;
            color: #b72f3a;
            border: 1px solid #f3c0c5;
        }

        .booking-card .alert-warning {
            background: #fffbeb;
            color: #92400e;
            border-left: 4px solid #f59e0b;
            border-radius: 11px;
            padding: 13px 14px;
        }

        .booking-card .vet-info-box {
            background: #edf8ff;
            color: #0d6d99;
            padding: 11px 12px;
            border-radius: 10px;
            font-size: 11px;
            margin-bottom: 17px;
            border: 1px dashed #8bcfee;
            text-align: center;
        }

        .booking-card .price-display {
            background: linear-gradient(135deg,#fffbea,#fff);
            border: 1px solid #ffe08a;
            border-radius: 14px;
            padding: 18px;
            text-align: center;
            margin-bottom: 20px;
        }

        .booking-card .price-display p {
            margin: 0;
            color: #7a8896;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .6px;
            font-weight: 800;
        }

        .booking-card .price-display h2 {
            margin: 4px 0 0;
            color: var(--brand-blue);
            font-size: 29px;
            font-weight: 800;
        }

        .booking-card .gcash-section {
            background: linear-gradient(145deg, #fffdf1 0%, #eef7ff 100%);
            padding: 20px;
            border-radius: 16px;
            border: 1px solid #d7e5f2;
            box-shadow: 0 8px 24px rgba(0,31,63,.05);
            margin-bottom: 20px;
            position: relative;
            overflow: hidden;
        }

        .booking-card .gcash-section::before {
            content: "";
            position: absolute;
            width: 150px;
            height: 150px;
            top: -75px;
            right: -55px;
            background: rgba(255,204,0,.18);
            border-radius: 50%;
            pointer-events: none;
        }

        .booking-card .gcash-section h4 {
            position: relative;
            color: var(--brand-blue);
            margin-bottom: 6px;
            font-size: 15px;
            font-weight: 800;
        }

        .booking-card .gcash-section h4 i {
            color: #0b76ff;
            margin-right: 4px;
        }

        .booking-card .gcash-section p {
            position: relative;
            color: #245386;
            font-size: 10px;
            line-height: 1.6;
            margin-bottom: 14px;
        }

        .gcash-qr-card {
            position: relative;
            max-width: 360px;
            margin: 0 auto 18px;
            padding: 10px 10px 0;
            border-radius: 18px;
            background: #f5c400;
            box-shadow: 0 10px 24px rgba(0,31,63,.10);
        }

        .gcash-qr-inner {
            background: #ffffff;
            border-radius: 14px;
            padding: 15px 15px 10px;
            border: 1px solid rgba(0,31,63,.08);
        }

        .gcash-qr-title {
            text-align: center;
            color: var(--brand-blue);
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .8px;
            margin-bottom: 9px;
        }

        .gcash-qr-image {
            display: block;
            width: min(280px, 100%);
            aspect-ratio: 1 / 1;
            object-fit: contain;
            margin: 0 auto;
            padding: 6px;
            background: #fff;
            border-radius: 10px;
        }

        .gcash-qr-caption {
            margin: 8px 0 0 !important;
            text-align: center;
            color: #5f6f80 !important;
            font-size: 9px !important;
            line-height: 1.45 !important;
        }

        .gcash-scan-strip {
            margin: 10px -10px 0;
            padding: 10px 12px;
            border-radius: 0 0 14px 14px;
            background: var(--brand-blue);
            color: var(--brand-yellow);
            text-align: center;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .5px;
            text-transform: uppercase;
        }

        .gcash-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            background: #e8f1ff;
            border: 1px solid #cfe0f5;
            padding: 8px 12px;
            border-radius: 9px;
            font-weight: 800;
            color: #1e3a8a;
            margin-bottom: 14px;
            font-size: 12px;
        }

        .gcash-number i {
            color: #0b76ff;
        }

        .gcash-account-card {
            max-width: 430px;
            margin: 2px auto 18px;
            padding: 15px 18px;
            border-radius: 14px;
            background: #ffffff;
            border: 1px solid #d6e3ef;
            box-shadow: 0 6px 18px rgba(0,31,63,.06);
            text-align: center;
        }

        .gcash-account-label {
            color: #718096;
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 4px;
        }

        .gcash-account-name {
            color: var(--brand-blue);
            font-size: 16px;
            font-weight: 800;
            letter-spacing: .5px;
            margin-bottom: 8px;
        }

        .gcash-account-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            color: #1e3a8a;
            background: #e8f1ff;
            border: 1px solid #cfe0f5;
            padding: 8px 14px;
            border-radius: 9px;
            font-size: 14px;
            font-weight: 800;
            letter-spacing: .8px;
        }

        .gcash-account-number i {
            color: #0b76ff;
        }

        @media (max-width: 680px) {
            .gcash-qr-card {
                max-width: 300px;
                padding: 8px 8px 0;
                border-radius: 15px;
            }

            .gcash-qr-inner {
                padding: 12px 12px 8px;
                border-radius: 12px;
            }

            .gcash-qr-image {
                width: min(235px, 100%);
                padding: 4px;
            }

            .gcash-scan-strip {
                margin: 8px -8px 0;
                padding: 9px 8px;
                font-size: 8px;
                line-height: 1.35;
            }

            .gcash-number {
                width: 100%;
                font-size: 10px;
                line-height: 1.4;
            }

            .gcash-account-card {
                padding: 12px 13px;
            }

            .gcash-account-name {
                font-size: 14px;
            }

            .gcash-account-number {
                width: 100%;
                font-size: 11px;
                line-height: 1.4;
            }
        }

        .booking-card .booking-policy-notice {
            background: #fffdf5;
            border: 1px solid #f3d37a;
            border-left: 5px solid #dc2626;
            padding: 16px 17px;
            border-radius: 11px;
            margin: 18px 0 12px;
            color: #243447;
            font-size: 13px;
            line-height: 1.65;
        }

        .booking-card .booking-policy-notice h4 {
            margin: 0 0 10px;
            color: #0b2545;
            font-size: 15px;
            font-weight: 850;
            line-height: 1.4;
        }

        .booking-card .booking-policy-item { margin: 9px 0; }

        .booking-card .booking-policy-alert {
            background: #fff1f2;
            border: 1px solid #fda4af;
            border-radius: 8px;
            padding: 11px 12px;
            margin: 11px 0;
            color: #881337;
        }

        .booking-card .booking-policy-alert strong,
        .booking-card .policy-critical {
            color: #b91c1c;
            font-weight: 900;
        }

        .booking-card .booking-policy-emergency {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            padding: 11px 12px;
            margin-top: 11px;
            color: #1e3a5f;
        }

        .booking-card .terms-container {
            background: #f8fafc;
            border: 1px solid var(--line);
            padding: 14px;
            border-radius: 11px;
            margin-bottom: 18px;
            font-size: 13px;
        }

        .booking-card .terms-container label {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            color: #334155;
            font-weight: 650;
            line-height: 1.6;
            margin: 0;
            cursor: pointer;
        }

        .booking-card .terms-container input[type="checkbox"] {
            width: 19px;
            height: 19px;
            margin-top: 2px;
            cursor: pointer;
            accent-color: var(--brand-blue);
            flex: 0 0 19px;
        }

        .booking-card .terms-container span.highlight {
            color: var(--brand-blue);
            font-weight: 900;
        }

        .booking-card .btn-submit {
            width: 100%;
            border: 0;
            padding: 14px;
            border-radius: 11px;
            background: var(--brand-blue);
            color: var(--brand-yellow);
            font-family: inherit;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 8px 20px rgba(0,31,63,.13);
        }

        .booking-card .btn-submit:hover {
            background: var(--brand-blue-2);
        }

        .hidden { display: none !important; }

        /* Booking guide */
        .side-kicker {
            color: #8b99a9;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 1.1px;
            font-weight: 800;
            margin-bottom: 6px;
        }

        .side-card h3 {
            color: var(--brand-blue);
            font-size: 18px;
            font-weight: 800;
            line-height: 1.25;
        }

        .side-card > p {
            color: var(--muted);
            font-size: 10px;
            line-height: 1.6;
            margin: 7px 0 18px;
        }

        .step-list {
            display: grid;
            gap: 14px;
        }

        .step-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .step-icon {
            width: 34px;
            height: 34px;
            flex: 0 0 34px;
            border-radius: 10px;
            background: var(--brand-yellow-soft);
            color: var(--brand-blue);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }

        .step-item strong {
            display: block;
            color: var(--brand-blue);
            font-size: 10px;
            font-weight: 800;
            margin-bottom: 1px;
        }

        .step-item span {
            display: block;
            color: #7f8d9c;
            font-size: 9px;
            line-height: 1.5;
        }

        .side-note {
            margin-top: 19px;
            padding: 12px;
            border-radius: 11px;
            background: #f0f7ff;
            border: 1px solid #d9eafa;
            color: #4e6980;
            font-size: 9px;
            line-height: 1.6;
        }

        .side-note i {
            color: var(--brand-blue);
            margin-right: 5px;
        }

        /* Footer */
        footer {
            background: var(--brand-blue);
            padding: 62px 28px 30px;
            color: #fff;
            border-top: 4px solid var(--brand-yellow);
        }

        .footer-main {
            max-width: 1180px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1.5fr;
            gap: 42px;
            padding-bottom: 40px;
            border-bottom: 1px solid rgba(255,255,255,.12);
        }

        .footer-main h4 {
            color: var(--brand-yellow);
            margin-bottom: 15px;
            text-transform: uppercase;
            font-weight: 800;
            font-size: 12px;
            letter-spacing: .5px;
        }

        .footer-main p,
        .footer-main a {
            color: #cbd5e1;
            text-decoration: none;
            font-size: 12px;
            line-height: 1.7;
            display: block;
            margin-bottom: 8px;
        }

        .footer-main a:hover { color: #fff; }

        .socials {
            display: flex;
            gap: 10px;
            margin-top: 16px;
        }

        .socials a {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: rgba(255,255,255,.09);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
        }

        .socials a:hover {
            background: var(--brand-yellow);
            color: var(--brand-blue);
        }

        .gmail-compose-link {
            cursor: pointer;
        }


        .footer-bottom {
            max-width: 1180px;
            margin: 0 auto;
            padding-top: 23px;
            color: #91a1b1;
            text-align: center;
            font-size: 11px;
        }

        @media (max-width: 980px) {
            .booking-layout { grid-template-columns: 1fr; }
            .side-card { position: static; }
            .page-title-row { display: block; }
            .booking-summary-pill { margin-top: 12px; }
            .footer-main { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 680px) {
            .nav-top { flex-wrap: wrap; padding: 12px 18px; }
            .user-controls { width: 100%; justify-content: flex-end; }
            .profile-trigger span { display: none; }

            main { width: 92%; padding-top: 30px; }
            .page-title h1 { font-size: 28px; }

            .booking-card { padding: 21px; }
            .form-row { grid-template-columns: 1fr; gap: 0; }

            .footer-main { grid-template-columns: 1fr; gap: 25px; }
        }


        /* ===== EXTRA MOBILE RESPONSIVE TUNING ===== */
        @media (max-width: 1100px) {
            .nav-top {
                gap: 15px;
            }

            .logo {
                min-width: 205px;
            }

            .booking-layout {
                grid-template-columns: minmax(0, 1fr) 250px;
            }

            .booking-card {
                padding: 25px;
            }
        }

        @media (max-width: 900px) {
            .nav-top {
                flex-wrap: wrap;
                padding: 11px 20px;
            }

            .logo {
                min-width: 0;
                flex: 1 1 auto;
            }

            .user-controls {
                flex: 0 0 auto;
            }

            .booking-layout {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .side-card {
                position: static;
            }

            .page-title-row {
                align-items: flex-start;
            }
        }

        @media (max-width: 680px) {
            .promo-bar {
                padding: 6px 10px;
                font-size: 10px;
                line-height: 1.4;
            }

            header {
                position: sticky;
                top: 0;
            }

            .nav-top {
                width: 100%;
                padding: 9px 12px;
                gap: 8px;
            }

            .logo {
                width: 100%;
                gap: 8px;
            }

            .nav-logo-img {
                width: 40px;
                height: 40px;
                border-radius: 9px;
            }

            .logo-text b {
                font-size: 16px;
            }

            .logo-text span {
                font-size: 7px;
                letter-spacing: .85px;
            }

            .user-controls {
                width: 100%;
                justify-content: flex-end;
                gap: 7px;
            }

            .notification-bell,
            .profile-trigger {
                width: 40px;
                min-width: 40px;
                height: 40px;
            }

            .notification-badge {
                min-width: 17px;
                height: 17px;
                font-size: 8px;
            }

            .profile-trigger {
                justify-content: center;
                padding: 3px;
            }

            .profile-trigger span,
            .profile-trigger > .fa-chevron-down {
                display: none;
            }

            .profile-img {
                width: 32px !important;
                height: 32px !important;
            }

            .dropdown-menu {
                top: calc(100% + 8px);
                right: 0;
                width: 270px;
                max-width: calc(100vw - 18px);
            }

            .dropdown-menu[style*="width:210px"] {
                width: 210px !important;
            }

            main {
                width: calc(100% - 22px);
                padding: 24px 0 48px;
            }

            .page-intro {
                margin-bottom: 18px;
            }

            .back-nav {
                margin-bottom: 10px;
            }

            .back-nav a {
                font-size: 10px;
            }

            .page-title-row {
                display: block;
            }

            .page-kicker {
                font-size: 8px;
                padding: 6px 10px;
                margin-bottom: 8px;
            }

            .page-title h1 {
                font-size: 26px;
                line-height: 1.2;
                letter-spacing: -.35px;
            }

            .page-title p {
                font-size: 10px;
                line-height: 1.55;
            }

            .booking-summary-pill {
                width: 100%;
                margin-top: 11px;
                justify-content: center;
                white-space: normal;
                text-align: center;
                padding: 9px 10px;
                font-size: 8px;
                line-height: 1.45;
            }

            .booking-layout {
                gap: 13px;
            }

            .booking-card,
            .side-card {
                border-radius: 17px;
            }

            .booking-card {
                padding: 15px;
            }

            .form-group {
                margin-bottom: 14px;
            }

            .form-row {
                grid-template-columns: 1fr;
                gap: 0;
            }

            .booking-card label {
                font-size: 10px;
                margin-bottom: 6px;
            }

            .booking-card input[type="text"],
            .booking-card input[type="date"],
            .booking-card select,
            .booking-card textarea,
            .booking-card input[type="file"] {
                min-height: 44px;
                padding: 10px 11px;
                font-size: 11px;
            }

            .booking-card input[type="file"] {
                padding: 9px;
                font-size: 9px;
            }

            .booking-card textarea {
                min-height: 105px;
            }

            .booking-card .header-section {
                padding-bottom: 14px;
                margin-bottom: 16px;
            }

            .booking-card .header-section h1 {
                font-size: 18px;
            }

            .booking-card .header-section p {
                font-size: 9px;
                line-height: 1.5;
            }

            .booking-card .walkin-notice,
            .booking-card .vet-info-box {
                font-size: 9px;
                line-height: 1.5;
            }

            .booking-card .vet-info-box {
                padding: 10px;
            }

            .booking-card .price-display {
                padding: 15px 12px;
                margin-bottom: 15px;
                border-radius: 13px;
            }

            .booking-card .price-display p {
                font-size: 8px;
                line-height: 1.45;
            }

            .booking-card .price-display h2 {
                font-size: 25px;
            }

            .booking-card .gcash-section {
                padding: 14px;
                border-radius: 13px;
                margin-bottom: 15px;
            }

            .booking-card .gcash-section h4 {
                font-size: 12px;
                line-height: 1.35;
            }

            .booking-card .gcash-section p {
                font-size: 9px;
                line-height: 1.6;
            }

            .booking-card .gcash-number {
                display: block;
                width: 100%;
                overflow-wrap: anywhere;
                word-break: break-word;
                padding: 8px 9px;
                margin-bottom: 12px;
                font-size: 11px;
                line-height: 1.45;
            }

            .booking-card .booking-policy-notice {
                padding: 12px;
                margin: 14px 0 10px;
                font-size: 12px;
                line-height: 1.6;
            }

            .booking-card .booking-policy-notice h4 { font-size: 14px; }

            .booking-card .terms-container {
                padding: 11px;
                margin-bottom: 15px;
                font-size: 12px;
                line-height: 1.55;
            }

            .booking-card .terms-container label { gap: 8px; }

            .booking-card .btn-submit {
                min-height: 46px;
                padding: 11px 12px;
                font-size: 10px;
                line-height: 1.3;
            }

            .side-card {
                padding: 15px;
            }

            .side-kicker {
                font-size: 8px;
            }

            .side-card h3 {
                font-size: 17px;
            }

            .side-card > p {
                font-size: 9px;
                line-height: 1.55;
                margin-bottom: 14px;
            }

            .step-list {
                gap: 11px;
            }

            .step-item {
                gap: 8px;
            }

            .step-icon {
                width: 32px;
                height: 32px;
                flex-basis: 32px;
                border-radius: 9px;
                font-size: 12px;
            }

            .step-item strong {
                font-size: 9px;
            }

            .step-item span {
                font-size: 8px;
                line-height: 1.5;
            }

            .side-note {
                margin-top: 15px;
                padding: 10px;
                font-size: 8px;
                line-height: 1.55;
            }

            footer {
                padding: 42px 15px 24px;
            }

            .footer-main {
                grid-template-columns: 1fr;
                gap: 22px;
                padding-bottom: 28px;
            }

            .footer-main h4 {
                font-size: 10px;
                margin-bottom: 11px;
            }

            .footer-main p,
            .footer-main a {
                font-size: 10px;
                line-height: 1.65;
            }

            .socials {
                margin-top: 13px;
            }

            .socials a {
                width: 34px;
                height: 34px;
            }

            .footer-bottom {
                font-size: 8.5px;
                line-height: 1.5;
                padding-top: 18px;
            }
        }

        @media (max-width: 420px) {
            .nav-top {
                padding-left: 9px;
                padding-right: 9px;
            }

            .nav-logo-img {
                width: 38px;
                height: 38px;
            }

            .logo-text b {
                font-size: 15px;
            }

            .logo-text span {
                font-size: 6px;
                letter-spacing: .7px;
            }

            main {
                width: calc(100% - 16px);
            }

            .page-title h1 {
                font-size: 23px;
            }

            .page-title p {
                font-size: 9px;
            }

            .booking-card {
                padding: 13px;
            }

            .side-card {
                padding: 13px;
            }

            .booking-card .gcash-number {
                font-size: 10px;
            }

            .booking-card .booking-policy-notice { font-size: 11.5px; }
            .booking-card .terms-container { font-size: 11.5px; }

            .booking-card .btn-submit {
                font-size: 9px;
            }
        }

    </style>
</head>

<body>


    <div class="promo-bar">
        <i class="fa-solid fa-phone"></i> Need help? Call us at (046) 887 4714
    </div>

    <header>
        <div class="nav-top">
            <a href="dashboard.php" class="logo">
                <img src="bg.png" alt="Boogie's Pet Care logo" class="nav-logo-img">
                <div class="logo-text">
                    <b>Boogie's</b>
                    <span>PET CARE SERVICES</span>
                </div>
            </a>

            <div class="user-controls">
                <div class="notification-wrapper">
                    <div class="notification-bell" onclick="toggleDropdown('notifDropdown')" aria-label="Notifications" role="button" tabindex="0" aria-expanded="false">
                        <i class="fa-solid fa-bell"></i>
                        <span id="notif-badge" class="notification-badge" style="<?php echo $unread_count > 0 ? '' : 'display:none;'; ?>"><?php echo (int)$unread_count; ?></span>
                    </div>

                    <div class="dropdown-menu" id="notifDropdown">
                        <div class="dropdown-header">Notifications</div>

                        <div id="notif-items-container">
                            <?php if (count($notifications) > 0): ?>
                                <?php foreach ($notifications as $notif): ?>
                                    <a href="notifications.php" class="dropdown-item <?php echo !empty($notif['is_read']) ? '' : 'unread'; ?>">
                                        <?php echo htmlspecialchars((string)($notif['message'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                        <br>
                                        <small style="color:#888;font-size:10px;">
                                            <?php echo htmlspecialchars(formatCustomerNotificationTimestamp($notif['created_at'] ?? null, 'M d, Y h:i A'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                        </small>
                                    </a>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="dropdown-item" style="text-align:center;color:#888;">
                                    No new notifications.
                                </div>
                            <?php endif; ?>
                        </div>

                        <form id="notif-view-all-form" action="mark_all_read.php" method="post" class="view-all-form" style="display: <?php echo count($notifications) > 0 ? 'block' : 'none'; ?>; margin:0;">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                            <button type="submit" class="dropdown-item view-all-link notif-view-all-button">View All Notifications</button>
                        </form>
                    </div>
                </div>

                <div class="profile-wrapper">
                    <div class="profile-trigger" onclick="toggleDropdown('profileDropdown')">
                        <?php if (!empty($profile_pic)): ?>
                            <img
                                src="<?php echo (strpos($profile_pic, 'uploads/') === false ? 'uploads/' : '') . htmlspecialchars($profile_pic); ?>"
                                alt="Profile"
                                class="profile-img"
                            >
                        <?php else: ?>
                            <i class="fa-solid fa-circle-user" style="font-size:20px;color:var(--brand-blue);"></i>
                        <?php endif; ?>

                        <span>Hi, <?php echo htmlspecialchars($full_name); ?></span>
                        <i class="fa-solid fa-chevron-down"></i>
                    </div>

                    <div class="dropdown-menu" id="profileDropdown" style="width:210px;">
                        <a href="edit_profile.php" class="dropdown-item">
                            <i class="fa-solid fa-user"></i> My Profile
                        </a>
                        <a href="bookings.php" class="dropdown-item">
                            <i class="fa-solid fa-calendar-check"></i> My Bookings
                        </a>
                        <a href="petprofile.php" class="dropdown-item">
                            <i class="fa-solid fa-paw"></i> My Pets
                        </a>
                        <a href="logout.php" class="dropdown-item" style="color:#dc3545;border-top:1px solid #eaeaea;">
                            <i class="fa-solid fa-right-from-bracket"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </header>



    <main>
        <div class="page-intro">
            <div class="back-nav">
                <a href="dashboard.php">
                    <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>

            <div class="page-title-row">
                <div class="page-title">
                    <div class="page-kicker">
                        <i class="fa-solid fa-calendar-check"></i>
                        Appointment booking
                    </div>
                    <h1>Book an Appointment</h1>
                    <p>Schedule professional care for your pet with Boogie's.</p>
                </div>

                <div class="booking-summary-pill">
                    <i class="fa-solid fa-shield-heart"></i>
                    Payment is verified before confirmation
                </div>
            </div>
        </div>

        <div class="booking-layout">
            <section class="booking-card">
            <form method="POST" action="" id="bookingForm" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                
                <div class="form-group">
                    <label>Select Registered Pet (Optional)</label>
                    <select id="pet_selector" name="registered_pet_id" onchange="autoFillPet()">
                        <option value="">-- Add New Pet / Enter Manually --</option>
                        <?php foreach($user_pets as $pet): ?>
                            <option value="<?php echo $pet['id']; ?>">
                                <?php echo htmlspecialchars($pet['name'] . ' (' . $pet['pet_type'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Pet's Name *</label>
                    <input type="text" name="pet_name" id="pet_name" autocomplete="off" placeholder="e.g., Kyle" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Pet Type *</label>
                        <select name="pet_type" id="pet_type" required onchange="updateOptions()">
                            <option value="">Select Type</option>
                            <option value="Dog">Dog</option>
                            <option value="Cat">Cat</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Gender *</label>
                        <select name="pet_gender" id="pet_gender" required>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Pet Size / Weight *</label>
                    <select name="pet_size" id="pet_size" required onchange="calculatePrice()">
                        <option value="">Select Size</option>
                        <option value="Small (1-5kg)">Small (1-5kg)</option>
                        <option value="Medium (6-10kg)">Medium (6-10kg)</option>
                        <option value="Large (11-15kg)">Large (11-15kg)</option>
                        <option value="Extra Large (16-20kg)">Extra Large (16-20kg)</option>
                        <option value="XXL Large (21-25kg)">XXL Large (21-25kg)</option>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Service Category *</label>
                        <select name="service_category" id="service_category" required onchange="updateOptions()">
                            <option value="">Select Category</option>
                            <option value="Grooming" <?php echo ($pre_selected_category === 'Grooming') ? 'selected' : ''; ?>>Grooming</option>
                            <option value="Vet Services" <?php echo ($pre_selected_category === 'Vet Services') ? 'selected' : ''; ?>>Vet Services</option>
                            <option value="Pet Hotel" <?php echo ($pre_selected_category === 'Pet Hotel') ? 'selected' : ''; ?>>Pet Hotel</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Specific Service *</label>
                        <select name="specific_service" id="specific_service" required onchange="handleServiceChange()">
                            <option value="">Choose specific service</option>
                        </select>
                    </div>
                </div>

                <div id="vetNameDisplay" class="vet-info-box hidden">
                    <i class="fa-solid fa-user-doctor"></i> Available Veterinary Staff: <strong>Doctor and Reliever</strong>
                </div>

                <div class="form-group hidden" id="haircutContainer">
                    <label>Desired Haircut Style *</label>
                    <select name="haircut_style" id="haircut_style">
                        <option value="">Select Style</option>
                        <option value="Puppy Cut">Puppy Cut</option>
                        <option value="Summer Cut">Summer Cut</option>
                        <option value="Shave Down">Shave Down</option>
                        <option value="Bear Cut">Bear Cut</option>
                        <option value="Poodle Cut">Poodle Cut</option>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label id="appointmentDateLabel" for="appointment_date">Select Date *</label>
                        <input type="date" name="appointment_date" id="appointment_date" min="<?php echo htmlspecialchars($minimumOnlineBookingDate, ENT_QUOTES, 'UTF-8'); ?>" required>
                        <p id="bookingDatePolicy" style="margin-top:8px;color:#6b7c8f;font-size:10px;line-height:1.5;">Online bookings must be made at least one calendar day in advance. Same-day bookings are not accepted.</p>
                    </div>
                    <div class="form-group">
                        <label id="appointmentTimeLabel" for="appointment_time">Select Time *</label>
                        <select name="appointment_time" id="appointment_time" required>
                            <option value="">Choose a date, category, and specific service first</option>
                        </select>
                        <p id="availabilityMessage" role="status" aria-live="polite" style="margin-top:8px;color:#6b7c8f;font-size:10px;line-height:1.5;">Available time slots will appear after you choose a date and service.</p>
                    </div>
                </div>

                <div class="form-group hidden" id="checkoutDateGroup">
                    <label for="checkout_date">Check-out Date *</label>
                    <input type="date" name="checkout_date" id="checkout_date">
                    <p style="margin-top:8px;color:#6b7c8f;font-size:10px;line-height:1.5;">Choose the date your pet will check out. The checkout date must be after check-in. The system checks room capacity for every night of the stay. The displayed overnight rate is charged per night.</p>
                </div>

                <div class="form-group hidden" id="pickupTimeGroup">
                    <label for="pickup_time">Expected Pickup Time *</label>
                    <select name="pickup_time" id="pickup_time">
                        <option value="">Choose drop-off time first</option>
                    </select>
                    <p style="margin-top:8px;color:#6b7c8f;font-size:10px;line-height:1.5;">Pet Daycare includes the first-hour rate. After the first hour, the additional fee is ₱30 per hour, billed in ₱15 half-hour blocks. Pickup must be by 6:00 PM.</p>
                </div>

                <div class="form-group">
                    <label>Remarks / Special Instructions (Optional)</label>
                    <textarea name="remarks" placeholder="Any allergies, behaviors, or specific requests?"></textarea>
                </div>

                <div class="price-display" id="priceDisplay">
                    <p>Total Amount Due (GCash Full Payment)</p>
                    <h2 id="priceText">₱0.00</h2>
                </div>

                <div class="gcash-section" id="gcashSection">
                    <h4><i class="fa-solid fa-mobile-screen-button"></i> GCash Payment Detail</h4>
                    <p>To submit your booking, please send the exact amount to our GCash account and upload your receipt. Your booking will remain pending until the admin verifies your payment, and the selected time is checked again when you submit.</p>
                    
                    <div class="gcash-qr-card" aria-label="Boogie's GCash QR payment">
                        <div class="gcash-qr-inner">
                            <div class="gcash-qr-title">
                                <i class="fa-solid fa-qrcode"></i>
                                Scan to Pay via GCash
                            </div>

                            <img
                                src="images/qr.png"
                                alt="Boogie's Pet Care GCash QR Code"
                                class="gcash-qr-image"
                                loading="eager"
                            >

                            <p class="gcash-qr-caption">
                                Open your GCash app and scan the QR code.
                                Send the exact amount shown in your booking total.
                            </p>
                        </div>

                        <div class="gcash-scan-strip">
                            <i class="fa-solid fa-bolt"></i>
                            For Fast &amp; Secure Mobile Checkout — Scan This
                        </div>
                    </div>

                    <div class="gcash-account-card">
                        <div class="gcash-account-label">GCash Account</div>
                        <div class="gcash-account-name">HA***H RO*SE T.</div>
                        <div class="gcash-account-number">
                            <i class="fa-solid fa-mobile-screen-button"></i>
                            09668939327
                        </div>
                    </div>

                    <div class="form-group">
                        <label>GCash Reference Number *</label>
                        <input type="text" name="gcash_ref" placeholder="e.g., 1001234567890" id="gcash_ref_input" inputmode="numeric" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label>Upload Screenshot of Receipt *</label>
                        <input type="file" name="gcash_receipt" accept="image/png, image/jpeg, image/jpg" style="background: white;" id="gcash_receipt_input">
                    </div>
                </div>

                <section class="booking-policy-notice" id="bookingPolicies" aria-labelledby="bookingPoliciesTitle">
                    <h4 id="bookingPoliciesTitle"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i> Important Booking Policies — Please Read Before Confirming</h4>

                    <div class="booking-policy-item">
                        <strong>Late arrival and first missed appointment:</strong>
                        A 30-minute grace period applies. If you miss your scheduled appointment beyond the grace period, you may request one reschedule within 3 days, subject to availability. The payment remains valid for the same service.
                    </div>

                    <div class="booking-policy-alert" role="note" aria-label="No-show and non-refundable policy">
                        <strong><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> NO-SHOW &amp; NON-REFUNDABLE POLICY:</strong>
                        If you also miss the rescheduled appointment, the booking will be marked <strong>NO-SHOW</strong> and the payment will be <strong>NON-REFUNDABLE</strong>. Please make sure you can attend the selected date and time.
                    </div>

                    <div class="booking-policy-emergency">
                        <strong>Emergency leave or service personnel unavailability:</strong>
                        If the assigned service personnel must take emergency leave or becomes unexpectedly unavailable, the Admin may reschedule your booking to an available date and time and notify you. An Admin-initiated reschedule due to staff unavailability will not be treated as a customer No-Show; your existing payment will remain valid for the same service on the agreed replacement schedule.
                    </div>

                    <div class="booking-policy-item">
                        <strong>Payment confirmation:</strong> Online bookings remain pending until the Admin verifies the GCash payment and confirms the reservation.
                    </div>
                </section>

                <div class="terms-container">
                    <label for="agree_terms">
                        <input type="checkbox" name="agree_terms" id="agree_terms" required aria-describedby="bookingPolicies">
                        <span>
                            I have read and agree to the <span class="highlight">Booking Policies</span> above, including the <span class="policy-critical">No-Show / Non-Refundable Policy</span> and the Admin's emergency-rescheduling policy.
                        </span>
                    </label>
                </div>

                <button type="submit" class="btn-submit" id="btnSubmit">Submit & Verify Payment</button>
            </form>
            </section>

            <aside class="side-card">
                <div class="side-kicker">Booking guide</div>
                <h3>Almost there.</h3>
                <p>Complete the form, send your GCash payment, then our team will verify your appointment.</p>
                <div class="side-note" style="margin-top: 14px;">
                    <i class="fa-solid fa-calendar-days"></i>
                    One reschedule is allowed within 3 days after a missed appointment. The same payment remains valid for the same service.
                </div>

                <div class="step-list">
                    <div class="step-item">
                        <div class="step-icon"><i class="fa-solid fa-paw"></i></div>
                        <div>
                            <strong>Choose your pet</strong>
                            <span>Use an existing profile or enter a new one.</span>
                        </div>
                    </div>

                    <div class="step-item">
                        <div class="step-icon"><i class="fa-solid fa-list-check"></i></div>
                        <div>
                            <strong>Select a service</strong>
                            <span>Your total updates automatically based on your choices.</span>
                        </div>
                    </div>

                    <div class="step-item">
                        <div class="step-icon"><i class="fa-solid fa-calendar-days"></i></div>
                        <div>
                            <strong>Pick date & time</strong>
                            <span>Unavailable slots are checked before you submit.</span>
                        </div>
                    </div>

                    <div class="step-item">
                        <div class="step-icon"><i class="fa-solid fa-mobile-screen-button"></i></div>
                        <div>
                            <strong>Pay via GCash</strong>
                            <span>Upload your receipt and reference number for verification.</span>
                        </div>
                    </div>
                </div>

                <div class="side-note">
                    <i class="fa-solid fa-circle-info"></i>
                    Your slot is only confirmed after payment verification.
                </div>
            </aside>
        </div>
    </main>



    <footer>
        <div class="footer-main">
            <div>
                <h4><i class="fa-solid fa-paw"></i> Boogie's Pet Care</h4>
                <p>Your trusted partner for all your pet care needs in Dasmariñas, Cavite.</p>
                <div class="socials">
                    <a href="https://www.facebook.com/boogiespetsupplies" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
                    <a href="https://mail.google.com/mail/?view=cm&to=boogiespetcareservices@gmail.com" class="gmail-compose-link" aria-label="Email Boogie's Pet Care Services"><i class="fa-solid fa-envelope"></i></a>
                </div>
            </div>

            <div>
                <h4>Quick Links</h4>
                <a href="home.php">Home</a>
                <a href="petservices.php">Services & Prices</a>
                <a href="contactus.php">Contact & Reviews</a>
                <a href="faqs.php">FAQs</a>
            </div>

            <div>
                <h4>Services</h4>
                <a href="grooming.php">Grooming</a>
                <a href="pethotel.php">Pet Hotel</a>
                <a href="vetclinic.php">Vet Clinic</a>
            </div>

            <div>
                <h4>Contact Us</h4>
                <p><i class="fa-solid fa-phone"></i> (046) 887 4714</p>
                <p><i class="fa-solid fa-envelope"></i> boogiespetcareservices@gmail.com</p>
                <p><i class="fa-solid fa-location-dot"></i> 110 Don Placido Campos Ave San Agustin 3, Dasmariñas, Philippines, 4114</p>
            </div>
        </div>

        <div class="footer-bottom">
            © <?php echo date("Y"); ?> Boogie's Pet Care & Services - Dasmariñas Branch. All rights reserved.
            <?php include __DIR__ . '/customer_footer.php'; ?>
        </div>
    </footer>


    <script>
        // --- GMAIL COMPOSE POPUP ---
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.gmail-compose-link').forEach(function (link) {
                link.addEventListener('click', function (e) {
                    // On small screens, let Gmail open normally for better mobile behavior.
                    if (window.innerWidth <= 680) {
                        return;
                    }

                    e.preventDefault();

                    const width = 760;
                    const height = 650;
                    const left = Math.max(0, Math.round((window.screen.width - width) / 2));
                    const top = Math.max(0, Math.round((window.screen.height - height) / 2));

                    const popup = window.open(
                        this.href,
                        'boogiesGmailCompose',
                        'width=' + width +
                        ',height=' + height +
                        ',left=' + left +
                        ',top=' + top +
                        ',resizable=yes,scrollbars=yes'
                    );

                    if (!popup) {
                        window.location.href = this.href;
                    }
                });
            });
        });

        // --- DROPDOWN LOGIC ---
        function toggleDropdown(id) {
            document.querySelectorAll('.dropdown-menu').forEach(menu => {
                if (menu.id !== id) { menu.classList.remove('active'); }
            });
            document.getElementById(id).classList.toggle('active');
        }

        window.addEventListener('click', function(e) {
            if (!document.querySelector('.notification-wrapper').contains(e.target) && 
                !document.querySelector('.profile-wrapper').contains(e.target)) {
                document.querySelectorAll('.dropdown-menu').forEach(menu => {
                    menu.classList.remove('active');
                });
            }
        });

        // ===== AUTOMATIC CUSTOMER NOTIFICATIONS =====
        const notificationBell = document.querySelector('.notification-bell');
        const notificationBadge = document.getElementById('notif-badge');
        const notificationItemsContainer = document.getElementById('notif-items-container');
        const notificationViewAllForm = document.getElementById('notif-view-all-form');
        const notificationSound = new Audio('notification.mp3');
        let previousUnreadCount = <?php echo (int)$unread_count; ?>;
        let previousLatestNotificationId = <?php echo json_encode((string)($notifications[0]['id'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        let notificationRefreshInProgress = false;

        function renderAppointmentNotifications(items) {
            if (!notificationItemsContainer) return;
            notificationItemsContainer.replaceChildren();

            if (!Array.isArray(items) || items.length === 0) {
                const emptyMessage = document.createElement('div');
                emptyMessage.className = 'dropdown-item';
                emptyMessage.style.textAlign = 'center';
                emptyMessage.style.color = '#888';
                emptyMessage.textContent = 'No new notifications.';
                notificationItemsContainer.appendChild(emptyMessage);
                if (notificationViewAllForm) notificationViewAllForm.style.display = 'none';
                return;
            }

            items.forEach(notification => {
                const link = document.createElement('a');
                link.href = 'notifications.php';
                link.className = 'dropdown-item' + (notification.is_read === true ? '' : ' unread');
                link.appendChild(document.createTextNode(String(notification.message || '')));
                link.appendChild(document.createElement('br'));

                const timestamp = document.createElement('small');
                timestamp.style.color = '#888';
                timestamp.style.fontSize = '10px';
                timestamp.textContent = String(notification.created_at || '');
                link.appendChild(timestamp);
                notificationItemsContainer.appendChild(link);
            });

            if (notificationViewAllForm) notificationViewAllForm.style.display = 'block';
        }

        async function updateAppointmentNotifications() {
            if (notificationRefreshInProgress) return;
            notificationRefreshInProgress = true;

            try {
                const response = await fetch('book_appointment.php?ajax_notifications=1', {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: { 'Accept': 'application/json' }
                });

                if (!response.ok) throw new Error('Notification request failed (' + response.status + ')');
                const data = await response.json();
                const unreadCount = Number(data && data.unread_count);
                if (data.success !== true || !Number.isInteger(unreadCount) || unreadCount < 0 || !Array.isArray(data.notifications)) {
                    throw new Error('Invalid notification response');
                }

                const latestId = data.notifications.length ? String(data.notifications[0].id || '') : '';
                const latestIsUnread = data.notifications.length > 0 && data.notifications[0].is_read !== true;
                const newUnreadNotification = latestId !== '' && latestId !== previousLatestNotificationId && latestIsUnread;

                if (unreadCount > previousUnreadCount || newUnreadNotification) {
                    notificationSound.play().catch(() => {});
                }

                previousUnreadCount = unreadCount;
                previousLatestNotificationId = latestId;

                if (notificationBadge) {
                    notificationBadge.textContent = String(unreadCount);
                    notificationBadge.style.display = unreadCount > 0 ? 'inline-flex' : 'none';
                }

                renderAppointmentNotifications(data.notifications);
            } catch (error) {
                console.warn('Could not refresh appointment-page notifications:', error);
            } finally {
                notificationRefreshInProgress = false;
            }
        }

        updateAppointmentNotifications();
        setInterval(() => {
            if (!document.hidden) updateAppointmentNotifications();
        }, 3000);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) updateAppointmentNotifications();
        });

        if (notificationBell) {
            notificationBell.addEventListener('keydown', event => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    toggleDropdown('notifDropdown');
                    notificationBell.setAttribute('aria-expanded', String(document.getElementById('notifDropdown')?.classList.contains('active') === true));
                }
            });
            notificationBell.addEventListener('click', () => {
                window.setTimeout(() => {
                    notificationBell.setAttribute('aria-expanded', String(document.getElementById('notifDropdown')?.classList.contains('active') === true));
                }, 0);
            });
        }

        const userPetsArray = <?php echo json_encode($user_pets); ?>;

        window.addEventListener('DOMContentLoaded', (event) => {
            if (document.getElementById('appointment_date')) {
                updateOptions();
                
                const dateInput = document.getElementById('appointment_date');
                const now = new Date();
                const tomorrow = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1);
                const formatLocalDate = (date) => {
                    const year = date.getFullYear();
                    const month = String(date.getMonth() + 1).padStart(2, '0');
                    const day = String(date.getDate()).padStart(2, '0');
                    return `${year}-${month}-${day}`;
                };
                // Same-day online appointments are never allowed; tomorrow is the earliest date.
                dateInput.setAttribute('min', formatLocalDate(tomorrow));
            }
        });

        function bookingSizeFromPetWeight(weight, petType = document.getElementById('pet_type').value, daycareMode = isDaycareSelected()) {
            const value = String(weight ?? '').trim();
            if (!value) return '';

            if (daycareMode) {
                if (petType === 'Cat') return 'Cat (All Sizes)';
                const daycareLabels = {
                    'Small (1-10kg)': 'Small (1-10kg)',
                    'Medium (11-20kg)': 'Medium (11-20kg)',
                    'Large (21-50kg)': 'Large (21-50kg)',
                    'Small (1-5kg)': 'Small (1-10kg)',
                    'Medium (6-10kg)': 'Small (1-10kg)',
                    'Large (11-15kg)': 'Medium (11-20kg)',
                    'Extra Large (16-20kg)': 'Medium (11-20kg)',
                    'XXL Large (21-25kg)': 'Large (21-50kg)'
                };
                if (daycareLabels[value]) return daycareLabels[value];
                const match = value.match(/(-?\d+(?:\.\d+)?)/);
                if (!match) return '';
                const kg = Number.parseFloat(match[1]);
                if (!Number.isFinite(kg) || kg <= 0 || kg > 50) return '';
                if (kg <= 10) return 'Small (1-10kg)';
                if (kg <= 20) return 'Medium (11-20kg)';
                return 'Large (21-50kg)';
            }

            const legacySizes = ['Small (1-5kg)', 'Medium (6-10kg)', 'Large (11-15kg)', 'Extra Large (16-20kg)', 'XXL Large (21-25kg)'];
            if (legacySizes.includes(value)) return value;
            if (['Small (1-10kg)', 'Medium (11-20kg)', 'Large (21-50kg)', 'Cat (All Sizes)'].includes(value)) return '';
            const match = value.match(/(-?\d+(?:\.\d+)?)/);
            if (!match) return '';
            const kg = Number.parseFloat(match[1]);
            if (!Number.isFinite(kg) || kg <= 0 || kg > 25) return '';
            if (kg <= 5) return 'Small (1-5kg)';
            if (kg <= 10) return 'Medium (6-10kg)';
            if (kg <= 15) return 'Large (11-15kg)';
            if (kg <= 20) return 'Extra Large (16-20kg)';
            return 'XXL Large (21-25kg)';
        }

        function getSelectedRegisteredPet() {
            const selector = document.getElementById('pet_selector');
            if (!selector || !selector.value) return null;
            return userPetsArray.find(p => String(p.id) === String(selector.value)) || null;
        }

        function updatePetSizeOptions() {
            const sizeSelect = document.getElementById('pet_size');
            if (!sizeSelect) return;

            const petType = document.getElementById('pet_type').value;
            const daycareMode = isDaycareSelected();
            const previousValue = sizeSelect.value;
            const selectedPet = getSelectedRegisteredPet();
            let options;

            if (daycareMode && petType === 'Cat') {
                options = [{ value: 'Cat (All Sizes)', label: 'Cat (All Sizes)' }];
            } else if (daycareMode) {
                options = [
                    { value: 'Small (1-10kg)', label: 'Small (1-10kg)' },
                    { value: 'Medium (11-20kg)', label: 'Medium (11-20kg)' },
                    { value: 'Large (21-50kg)', label: 'Large (21-50kg)' }
                ];
            } else {
                options = [
                    { value: 'Small (1-5kg)', label: 'Small (1-5kg)' },
                    { value: 'Medium (6-10kg)', label: 'Medium (6-10kg)' },
                    { value: 'Large (11-15kg)', label: 'Large (11-15kg)' },
                    { value: 'Extra Large (16-20kg)', label: 'Extra Large (16-20kg)' },
                    { value: 'XXL Large (21-25kg)', label: 'XXL Large (21-25kg)' }
                ];
            }

            let nextValue = '';
            if (selectedPet && String(selectedPet.weight ?? '').trim() !== '') {
                nextValue = bookingSizeFromPetWeight(selectedPet.weight, petType, daycareMode);
            } else if (daycareMode) {
                nextValue = bookingSizeFromPetWeight(previousValue, petType, true);
            } else if (options.some(option => option.value === previousValue)) {
                nextValue = previousValue;
            }

            sizeSelect.innerHTML = '<option value="">Select Size / Weight</option>';
            options.forEach(item => {
                const option = document.createElement('option');
                option.value = item.value;
                option.textContent = item.label;
                sizeSelect.appendChild(option);
            });
            sizeSelect.value = options.some(option => option.value === nextValue) ? nextValue : '';
        }

        function autoFillPet() {
            const petSelector = document.getElementById('pet_selector').value;
            const nameInput = document.getElementById('pet_name');
            const typeSelect = document.getElementById('pet_type');
            const genderSelect = document.getElementById('pet_gender');
            const sizeSelect = document.getElementById('pet_size');

            if (petSelector === '') {
                nameInput.value = '';
                nameInput.readOnly = false;
                typeSelect.value = '';
                genderSelect.value = 'Male';
                sizeSelect.value = '';
            } else {
                const selectedPet = getSelectedRegisteredPet();
                if (selectedPet) {
                    nameInput.value = selectedPet.name ?? '';
                    nameInput.readOnly = true;
                    typeSelect.value = selectedPet.pet_type ?? '';
                    genderSelect.value = selectedPet.gender ?? 'Male';
                }
            }

            updateOptions();
            const selectedPet = getSelectedRegisteredPet();
            const serviceChosen = Boolean(document.getElementById('service_category').value && document.getElementById('specific_service').value);
            if (selectedPet && serviceChosen && String(selectedPet.weight ?? '').trim() !== '' && !sizeSelect.value) {
                const daycareMode = isDaycareSelected();
                const savedWeight = String(selectedPet.weight ?? '').trim();
                const daycareOnlyLabels = ['Small (1-10kg)', 'Medium (11-20kg)', 'Large (21-50kg)', 'Cat (All Sizes)'];
                if (!daycareMode && daycareOnlyLabels.includes(savedWeight)) {
                    alert('This pet profile has a Pet Daycare size range saved. Please select the pet’s current size/weight for this service.');
                } else {
                    const maximum = daycareMode && selectedPet.pet_type === 'Dog' ? '50 kg for Pet Daycare' : '25 kg';
                    alert(`This pet’s saved weight could not be matched to the current size options (supported up to ${maximum}). Please check the saved pet weight or contact the clinic.`);
                }
            }
            calculatePrice();
        }

        const pricingData = {
            "Dog": {
                "Grooming": {
                    "Basic Pet Grooming": { "Small (1-5kg)": 400, "Medium (6-10kg)": 500, "Large (11-15kg)": 650, "Extra Large (16-20kg)": 850, "XXL Large (21-25kg)": 1000 },
                    "Full Grooming Package": { "Small (1-5kg)": 450, "Medium (6-10kg)": 550, "Large (11-15kg)": 700, "Extra Large (16-20kg)": 900, "XXL Large (21-25kg)": 1100 },
                    "Bath & Blow Dry": { "Small (1-5kg)": 300, "Medium (6-10kg)": 350, "Large (11-15kg)": 550, "Extra Large (16-20kg)": 750, "XXL Large (21-25kg)": 950 }
                },
                "Vet Services": {
                    "Deworming": { "Small (1-5kg)": 200, "Medium (6-10kg)": 250, "Large (11-15kg)": 300, "Extra Large (16-20kg)": 350, "XXL Large (21-25kg)": 450 },
                    "Vaccination - Anti Rabies": { default: 300 },
                    "Vaccination - 5 in 1": { default: 450 },
                    "Vaccination - 6 in 1": { default: 600 },
                    "Vaccination - 8 in 1": { default: 750 }
                },
                "Pet Hotel": {
                    "Pet Daycare (1st Hour - Succeeding fees apply)": { "Small (1-10kg)": 100, "Medium (11-20kg)": 150, "Large (21-50kg)": 200 },
                    "Pet Boarding (Overnight)": { "Small (1-5kg)": 500, "Medium (6-10kg)": 500, "Large (11-15kg)": 600, "Extra Large (16-20kg)": 600, "XXL Large (21-25kg)": 800 }
                }
            },
            "Cat": {
                "Grooming": {
                    "Cat Grooming (Basic)": { "Small (1-5kg)": 550, "Medium (6-10kg)": 650, "Large (11-15kg)": 750, "Extra Large (16-20kg)": 850, "XXL Large (21-25kg)": 950 },
                    "Cat Bath & Blow Dry": { "Small (1-5kg)": 400, "Medium (6-10kg)": 500, "Large (11-15kg)": 600, "Extra Large (16-20kg)": 700, "XXL Large (21-25kg)": 800 }
                },
                "Vet Services": {
                    "Deworming": { "Small (1-5kg)": 200, "Medium (6-10kg)": 250, "Large (11-15kg)": 300, "Extra Large (16-20kg)": 350, "XXL Large (21-25kg)": 450 },
                    "Vaccination - Anti Rabies": { default: 300 },
                    "Vaccination - 4 in 1 (Cats)": { default: 900 }
                },
                "Pet Hotel": {
                    "Pet Daycare (1st Hour - Succeeding fees apply)": { default: 150 },
                    "Pet Boarding (Overnight)": { default: 500 }
                }
            }
        };

        function updateOptions() {
            const petType = document.getElementById('pet_type').value;
            const category = document.getElementById('service_category').value;
            const specificServiceDropdown = document.getElementById('specific_service');
            const vetNameDisplay = document.getElementById('vetNameDisplay');
            
            const walkInNotice = document.getElementById('walkInNotice');
            const submitBtn = document.getElementById('btnSubmit');
            
            const previousSelection = specificServiceDropdown.value;

            specificServiceDropdown.innerHTML = '<option value="">Choose specific service</option>';
            document.getElementById('haircutContainer').classList.add('hidden');

            if (category === 'Vet Services') {
                vetNameDisplay.classList.remove('hidden');
            } else {
                vetNameDisplay.classList.add('hidden');
            }

            // walkInNotice is optional on this page.
            // Prevent a missing element from stopping updateOptions()
            // before the Specific Service dropdown gets populated.
            if (walkInNotice) {
                if (category === 'Vet Services' || category === 'Grooming') {
                    walkInNotice.style.display = 'block';
                } else {
                    walkInNotice.style.display = 'none';
                }
            }

            if (petType && category && pricingData[petType][category]) {
                const services = Object.keys(pricingData[petType][category]);
                services.forEach(service => {
                    const option = document.createElement('option');
                    option.value = service; option.text = service;
                    if (service === previousSelection) { option.selected = true; }
                    specificServiceDropdown.appendChild(option);
                });
            }

            updatePetSizeOptions();
            validateVetDate();
            handleServiceChange();
        }

        function isOvernightBoardingSelected() {
            const category = document.getElementById('service_category').value;
            const specific = document.getElementById('specific_service').value.toLowerCase();
            return category === 'Pet Hotel' && (specific.includes('boarding') || specific.includes('overnight'));
        }

        function isDaycareSelected() {
            const category = document.getElementById('service_category').value;
            const specific = document.getElementById('specific_service').value.toLowerCase();
            return category === 'Pet Hotel' && specific.includes('daycare');
        }

        function jsTimeToMinutes(value) {
            const match = String(value || '').match(/^(\d{1,2}):(\d{2})/);
            if (!match) return null;
            return Number(match[1]) * 60 + Number(match[2]);
        }

        function jsMinutesToTime(value) {
            return `${String(Math.floor(value / 60)).padStart(2, '0')}:${String(value % 60).padStart(2, '0')}:00`;
        }

        function jsMinutesToLabel(value) {
            const hour = Math.floor(value / 60);
            const minute = value % 60;
            const suffix = hour >= 12 ? 'PM' : 'AM';
            const displayHour = hour % 12 || 12;
            return `${displayHour}:${String(minute).padStart(2, '0')} ${suffix}`;
        }

        function populateDaycareDropoffTimes() {
            const select = document.getElementById('appointment_time');
            if (!select) return;
            const previous = select.value;
            resetTimeOptions('Choose drop-off time');
            const times = [];
            [[9 * 60, 12 * 60], [13 * 60, 18 * 60]].forEach(([periodStart, periodEnd]) => {
                for (let value = periodStart; value < periodEnd; value += 30) times.push(value);
            });
            times.forEach(value => {
                const option = document.createElement('option');
                option.value = jsMinutesToTime(value);
                option.textContent = jsMinutesToLabel(value);
                select.appendChild(option);
            });
            if (times.some(value => jsMinutesToTime(value) === previous)) select.value = previous;
            updateDaycarePickupTimes();
        }

        function updateDaycarePickupTimes() {
            const group = document.getElementById('pickupTimeGroup');
            const select = document.getElementById('pickup_time');
            if (!select) return;
            const previous = select.value;
            select.innerHTML = '';
            const start = jsTimeToMinutes(document.getElementById('appointment_time')?.value || '');
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = start === null ? 'Choose drop-off time first' : 'Choose expected pickup time';
            select.appendChild(placeholder);
            if (start !== null) {
                for (let value = start + 30; value <= 18 * 60; value += 30) {
                    const option = document.createElement('option');
                    option.value = jsMinutesToTime(value);
                    option.textContent = jsMinutesToLabel(value);
                    select.appendChild(option);
                }
            }
            if (Array.from(select.options).some(option => option.value === previous)) select.value = previous;
        }

        function updateBoardingFields() {
            const isBoarding = isOvernightBoardingSelected();
            const isDaycare = isDaycareSelected();
            const checkoutGroup = document.getElementById('checkoutDateGroup');
            const checkoutInput = document.getElementById('checkout_date');
            const pickupGroup = document.getElementById('pickupTimeGroup');
            const pickupInput = document.getElementById('pickup_time');
            const dateLabel = document.getElementById('appointmentDateLabel');
            const timeLabel = document.getElementById('appointmentTimeLabel');

            if (checkoutGroup && checkoutInput) {
                checkoutGroup.classList.toggle('hidden', !isBoarding);
                checkoutInput.required = isBoarding;
                if (!isBoarding) checkoutInput.value = '';
            }
            if (pickupGroup && pickupInput) {
                pickupGroup.classList.toggle('hidden', !isDaycare);
                pickupInput.required = isDaycare;
                if (!isDaycare) pickupInput.value = '';
            }
            if (dateLabel) dateLabel.textContent = isBoarding ? 'Check-in Date *' : (isDaycare ? 'Daycare Date *' : 'Select Date *');
            if (timeLabel) timeLabel.textContent = isBoarding ? 'Check-in Time *' : (isDaycare ? 'Drop-off Time *' : 'Select Time *');
            updateCheckoutDateMinimum();
            if (isDaycare) populateDaycareDropoffTimes();
        }

        function updateCheckoutDateMinimum() {
            const checkinInput = document.getElementById('appointment_date');
            const checkoutInput = document.getElementById('checkout_date');
            if (!checkinInput || !checkoutInput) return;
            if (!checkinInput.value) {
                checkoutInput.removeAttribute('min');
                return;
            }
            const checkin = new Date(checkinInput.value + 'T12:00:00');
            checkin.setDate(checkin.getDate() + 1);
            const minDate = `${checkin.getFullYear()}-${String(checkin.getMonth() + 1).padStart(2, '0')}-${String(checkin.getDate()).padStart(2, '0')}`;
            checkoutInput.min = minDate;
            if (checkoutInput.value && checkoutInput.value < minDate) checkoutInput.value = '';
        }

        function handleServiceChange() {
            const specificService = document.getElementById('specific_service').value;
            const haircutContainer = document.getElementById('haircutContainer');
            const haircutSelect = document.getElementById('haircut_style');

            if (specificService === 'Full Grooming Package') {
                haircutContainer.classList.remove('hidden');
                haircutSelect.required = true;
            } else {
                haircutContainer.classList.add('hidden');
                haircutSelect.required = false;
            }
            updatePetSizeOptions();
            updateBoardingFields();
            calculatePrice();
            checkRealTimeAvailability();
        }

        function validateVetDate() {
            const category = document.getElementById('service_category').value;
            const dateInput = document.getElementById('appointment_date');
            
            if (category === 'Vet Services' && dateInput.value) {
                const [year, month, dayOfMonth] = dateInput.value.split('-').map(Number);
                const selectedDate = new Date(year, month - 1, dayOfMonth);
                const day = selectedDate.getDay(); 
                
                if (day === 3 || day === 6) {
                    alert("Dr. Faith Casayuran is not available on Wednesdays and Saturdays. Please select a different date.");
                    dateInput.value = '';
                }
            }
        }

        if (document.getElementById('appointment_date')) {
            document.getElementById('appointment_date').addEventListener('change', () => {
                validateVetDate();
                updateCheckoutDateMinimum();
                calculatePrice();
                checkRealTimeAvailability();
            });
        }
        if (document.getElementById('checkout_date')) {
            document.getElementById('checkout_date').addEventListener('change', () => {
                calculatePrice();
                checkRealTimeAvailability();
            });
        }
        if (document.getElementById('appointment_time')) {
            document.getElementById('appointment_time').addEventListener('change', () => {
                const daycareSelected = isDaycareSelected();

                if (daycareSelected) {
                    // Daycare availability depends on the full drop-off-to-pickup interval.
                    // Refresh after the selected drop-off changes, while keeping the
                    // selected time available for the interval-capacity check.
                    updateDaycarePickupTimes();
                    calculatePrice();
                    checkRealTimeAvailability();
                    return;
                }

                // For Grooming and Vet appointments, the available start-time options
                // have already been checked when date/category/service changed. Fetching
                // the same list again after selecting one of those options resets the
                // dropdown and can send it back to "Choose an available time".
                // Pet Boarding also uses its pre-checked fixed check-in option.
                // Recalculate only; do not rebuild the selected time dropdown here.
                calculatePrice();
            });
        }
        if (document.getElementById('pickup_time')) {
            document.getElementById('pickup_time').addEventListener('change', () => {
                calculatePrice();
                checkRealTimeAvailability();
            });
        }

        // --- SERVICE DURATION AND CAPACITY-AWARE SLOT AVAILABILITY ---
        let availabilityRequestId = 0;

        function setAvailabilityMessage(message, isError = false) {
            const messageEl = document.getElementById('availabilityMessage');
            if (!messageEl) return;
            messageEl.textContent = message;
            messageEl.style.color = isError ? '#b72f3a' : '#6b7c8f';
        }

        function resetTimeOptions(message = 'Choose a date, category, and specific service first.') {
            const timeSelect = document.getElementById('appointment_time');
            if (!timeSelect) return;
            timeSelect.innerHTML = '';
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = message;
            timeSelect.appendChild(placeholder);
        }

        function checkRealTimeAvailability() {
            const requestId = ++availabilityRequestId;
            const date = document.getElementById('appointment_date').value;
            const category = document.getElementById('service_category').value;
            const specificService = document.getElementById('specific_service').value;
            const checkoutDate = document.getElementById('checkout_date') ? document.getElementById('checkout_date').value : '';
            const isBoarding = isOvernightBoardingSelected();
            const isDaycare = isDaycareSelected();
            const timeSelect = document.getElementById('appointment_time');

            if (isDaycare) {
                if (!date || !category || !specificService) {
                    resetTimeOptions('Choose a date and Pet Daycare service first');
                    updateDaycarePickupTimes();
                    setAvailabilityMessage('Choose a date, then select the daycare drop-off and expected pickup times.');
                    return;
                }
                populateDaycareDropoffTimes();
                const dropoffTime = timeSelect.value;
                const pickupTime = document.getElementById('pickup_time').value;
                if (!dropoffTime || !pickupTime) {
                    setAvailabilityMessage('Select both drop-off and expected pickup times to check capacity and calculate the total.');
                    calculatePrice();
                    return;
                }
                setAvailabilityMessage('Checking Pet Daycare room capacity for the complete stay…');
                const daycareUrl = `check_slots.php?date=${encodeURIComponent(date)}&category=${encodeURIComponent(category)}&specific_service=${encodeURIComponent(specificService)}&dropoff_time=${encodeURIComponent(dropoffTime)}&pickup_time=${encodeURIComponent(pickupTime)}`;
                fetch(daycareUrl, { cache: 'no-store' })
                    .then(response => {
                        if (!response.ok) throw new Error('Availability endpoint returned HTTP ' + response.status);
                        return response.json();
                    })
                    .then(data => {
                        if (requestId !== availabilityRequestId) return;
                        if (data.error) {
                            setAvailabilityMessage(data.error, true);
                        } else if (data.available) {
                            setAvailabilityMessage(`Daycare room available for ${data.duration_display || 'the selected stay'}; ${Number(data.remaining_slots || 0)} room(s) available during the full interval.`);
                        } else {
                            setAvailabilityMessage(data.reason || 'No Pet Daycare room is available for the entire selected interval.', true);
                        }
                        calculatePrice();
                    })
                    .catch(error => {
                        if (requestId !== availabilityRequestId) return;
                        console.error('Error fetching Pet Daycare availability:', error);
                        setAvailabilityMessage('Unable to confirm Pet Daycare capacity. Please check your connection and try again.', true);
                    });
                return;
            }

            resetTimeOptions();
            if (!date || !category || !specificService) {
                setAvailabilityMessage('Available times will appear after you choose a date, category, and specific service.');
                return;
            }
            if (isBoarding && (!checkoutDate || checkoutDate <= date)) {
                resetTimeOptions('Choose valid check-in and check-out dates first');
                setAvailabilityMessage('For Pet Boarding, choose a check-out date at least one day after check-in.');
                return;
            }

            // Preserve the customer's chosen check-in/start time when the availability
            // refresh runs after the select's change event. Without this, rebuilding
            // the dropdown clears the selection and makes it appear impossible to choose.
            const previouslySelectedTime = timeSelect ? timeSelect.value : '';

            resetTimeOptions('Checking available times…');
            setAvailabilityMessage('Checking availability based on service duration and remaining capacity…');

            let url = `check_slots.php?date=${encodeURIComponent(date)}&category=${encodeURIComponent(category)}&specific_service=${encodeURIComponent(specificService)}`;
            if (isBoarding) url += `&checkout_date=${encodeURIComponent(checkoutDate)}`;
            fetch(url, { cache: 'no-store' })
                .then(response => {
                    if (!response.ok) throw new Error('Availability endpoint returned HTTP ' + response.status);
                    return response.json();
                })
                .then(data => {
                    if (requestId !== availabilityRequestId) return;
                    resetTimeOptions('Choose an available time');

                    if (data.error) {
                        resetTimeOptions('Availability could not be loaded');
                        setAvailabilityMessage(data.error, true);
                        return;
                    }

                    if (!Array.isArray(data.slots)) {
                        resetTimeOptions('Availability could not be loaded');
                        setAvailabilityMessage('The availability response is incomplete. Please refresh the page or try again.', true);
                        return;
                    }

                    let enabledCount = 0;
                    let visibleCount = 0;
                    data.slots.forEach(slot => {
                        const reason = String(slot.reason || '');
                        const normalizedReason = reason.toLowerCase();

                        // Keep the dropdown focused on start times the customer can reasonably choose.
                        // Hide times outside closing hours, starts during the 12:00 PM–1:00 PM break,
                        // and start times that have already passed. Keep valid-but-full slots visible
                        // and disabled so customers can understand that the time is booked.
                        const shouldHide =
                            normalizedReason === 'outside operating hours' ||
                            normalizedReason.startsWith('staff break') ||
                            normalizedReason === 'time has passed';
                        if (shouldHide) return;

                        const option = document.createElement('option');
                        option.value = slot.time;
                        option.disabled = !slot.available;
                        visibleCount++;

                        if (slot.available) {
                            enabledCount++;
                            const remaining = Number(slot.remaining_slots || 0);
                            option.textContent = `${slot.label} — ${remaining} slot${remaining === 1 ? '' : 's'} available`;
                        } else {
                            option.textContent = `${slot.label} — ${reason || 'Fully booked'}`;
                        }
                        timeSelect.appendChild(option);
                    });

                    // Restore the previous time only when it still exists and remains available.
                    // If capacity changed and the time is no longer selectable, leave the placeholder.
                    if (previouslySelectedTime) {
                        const stillAvailableOption = Array.from(timeSelect.options).find(
                            option => option.value === previouslySelectedTime && !option.disabled
                        );
                        if (stillAvailableOption) {
                            timeSelect.value = previouslySelectedTime;
                        }
                    }

                    if (visibleCount === 0) {
                        resetTimeOptions('No valid start times for this date and service');
                        setAvailabilityMessage(data.full_reason || 'No valid start times remain within business hours for this service.', true);
                    } else if (enabledCount === 0 || data.is_full) {
                        setAvailabilityMessage(data.full_reason || 'No available time slots remain for this date and service.', true);
                    } else {
                        const duration = Number(data.duration_minutes || 0);
                        const durationDisplay = String(data.duration_display || `${duration} minutes`);
                        if (isBoarding) {
                            setAvailabilityMessage(`Pet boarding stay: ${durationDisplay}. Standard check-in is 9:00 AM; check-out is 6:00 AM on the selected check-out date.`);
                        } else {
                            setAvailabilityMessage(`Service duration: ${durationDisplay}. The displayed times show the full reserved service window. New appointments cannot start from 12:00 PM–1:00 PM.`);
                        }
                    }
                })
                .catch(error => {
                    if (requestId !== availabilityRequestId) return;
                    console.error('Error fetching appointment availability:', error);
                    resetTimeOptions('Availability could not be loaded');
                    setAvailabilityMessage('Unable to load available times. Please check your connection and try again.', true);
                });
        }

        function calculatePrice() {
            const petType = document.getElementById('pet_type').value;
            const size = document.getElementById('pet_size').value;
            const category = document.getElementById('service_category').value;
            const specific = document.getElementById('specific_service').value;

            const displayBox = document.getElementById('priceDisplay');
            const priceText = document.getElementById('priceText');
            const gcashSection = document.getElementById('gcashSection');
            const gcashRefInput = document.getElementById('gcash_ref_input');
            const gcashReceiptInput = document.getElementById('gcash_receipt_input');

            if (petType && size && category && specific && pricingData[petType] && pricingData[petType][category]) {
                let basePrice = 0;
                const serviceData = pricingData[petType][category][specific];
                if (serviceData && serviceData.default) {
                    basePrice = Number(serviceData.default);
                } else if (serviceData && serviceData[size]) {
                    basePrice = Number(serviceData[size]);
                }

                if (basePrice > 0) {
                    let totalPrice = basePrice;
                    if (isOvernightBoardingSelected()) {
                        const checkin = document.getElementById('appointment_date').value;
                        const checkout = document.getElementById('checkout_date').value;
                        if (!checkin || !checkout || checkout <= checkin) {
                            priceText.innerText = `₱${basePrice.toFixed(2)} per night — choose check-out date`;
                        } else {
                            const checkinDate = new Date(checkin + 'T12:00:00');
                            const checkoutDate = new Date(checkout + 'T12:00:00');
                            const nights = Math.round((checkoutDate - checkinDate) / 86400000);
                            if (nights < 1) {
                                priceText.innerText = `₱${basePrice.toFixed(2)} per night — choose a valid stay`;
                            } else {
                                totalPrice = basePrice * nights;
                                priceText.innerText = `₱${totalPrice.toFixed(2)} (${nights} night${nights === 1 ? '' : 's'} × ₱${basePrice.toFixed(2)})`;
                            }
                        }
                    } else if (isDaycareSelected()) {
                        const dropoff = jsTimeToMinutes(document.getElementById('appointment_time').value);
                        const pickup = jsTimeToMinutes(document.getElementById('pickup_time').value);
                        if (dropoff === null || pickup === null || pickup <= dropoff) {
                            priceText.innerText = `₱${basePrice.toFixed(2)} first hour — choose drop-off and pickup times`;
                        } else {
                            const durationMinutes = pickup - dropoff;
                            const extraMinutes = Math.max(0, durationMinutes - 60);
                            const halfHourBlocks = Math.ceil(extraMinutes / 30);
                            const extraFee = halfHourBlocks * 15;
                            totalPrice = basePrice + extraFee;
                            const hours = Math.floor(durationMinutes / 60);
                            const minutes = durationMinutes % 60;
                            priceText.innerText = `₱${totalPrice.toFixed(2)} (${hours ? `${hours}h ` : ''}${minutes ? `${minutes}m` : ''}; first hour ₱${basePrice.toFixed(2)} + ₱${extraFee.toFixed(2)} extra)`;
                        }
                    } else {
                        priceText.innerText = '₱' + totalPrice.toFixed(2);
                    }
                    displayBox.style.display = 'block';
                    gcashSection.style.display = 'block';
                    gcashRefInput.required = true;
                    gcashReceiptInput.required = true;
                    return;
                }
            }

            displayBox.style.display = 'none';
            gcashSection.style.display = 'none';
            gcashRefInput.required = false;
            gcashReceiptInput.required = false;
        }

    </script>
</body>
</html>
