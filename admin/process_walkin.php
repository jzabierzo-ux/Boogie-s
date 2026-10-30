<?php



require_once __DIR__ . '/../shared_session_bootstrap.php';

date_default_timezone_set('Asia/Manila');



// Use the same role aliases and allowed operational roles as managebooking.php.

$raw_role = strtolower(trim((string)($_SESSION['role'] ?? '')));

$role_aliases = [

    'administrator' => 'admin',

    'shop manager' => 'manager',

    'shop_manager' => 'manager',

    'front desk' => 'receptionist',

    'front desk / receptionist' => 'receptionist',

    'front_desk' => 'receptionist',

    'front_desk_receptionist' => 'receptionist',

    'grooming staff' => 'groomer',

    'grooming_staff' => 'groomer',

    'grooming staff / groomer' => 'groomer',

    'grooming_staff_groomer' => 'groomer',

    'pet hotel staff' => 'pet_hotel_staff',

    'pet hotel staff / attendant' => 'pet_hotel_staff',

    'pet_hotel_attendant' => 'pet_hotel_staff',

    'pet_hotel_staff_attendant' => 'pet_hotel_staff',

    'vet' => 'veterinarian',

    'veterinary' => 'veterinarian',

    'staff' => 'groomer',

    'supervisor' => 'receptionist',

];

$current_role = $role_aliases[$raw_role] ?? $raw_role;
if ($current_role !== '') {
    $_SESSION['role'] = $current_role;
}
$requested_portal = strtolower(trim((string)($_POST['login_portal'] ?? $_GET['portal'] ?? 'admin')));
$allowed_roles = ['admin', 'manager', 'receptionist', 'groomer', 'pet_hotel_staff'];

$admin_session_valid = (
    ($_SESSION['logged_in'] ?? false) === true
    && ($_SESSION['admin_logged_in'] ?? false) === true
    && $current_role === 'admin'
    && (int)($_SESSION['user_id'] ?? 0) > 0
);
$staff_session_valid = (
    ($_SESSION['logged_in'] ?? false) === true
    && ($_SESSION['staff_logged_in'] ?? false) === true
    && in_array($current_role, ['manager', 'receptionist', 'groomer', 'pet_hotel_staff'], true)
    && (int)($_SESSION['user_id'] ?? 0) > 0
);

if (!$admin_session_valid && !$staff_session_valid) {
    if ($requested_portal === 'staff') {
        header('Location: ../staff/stafflogin.php');
    } else {
        header('Location: ../admin_login.php');
    }
    exit();
}

if (!in_array($current_role, $allowed_roles, true)) {
    http_response_code(403);
    exit('Access denied. Your role cannot create walk-in bookings.');
}
$is_admin = $admin_session_valid;
$portal_mode = $is_admin ? 'admin' : 'staff';





if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['add_walkin'])) {

    header('Location: managebooking.php?portal=' . $portal_mode);

    exit();

}



$session_csrf = (string)($_SESSION['csrf_token'] ?? '');

$posted_csrf = (string)($_POST['csrf_token'] ?? '');

if ($session_csrf === '' || $posted_csrf === '' || !hash_equals($session_csrf, $posted_csrf)) {

    http_response_code(403);

    exit('Invalid or expired request token. Return to bookings and try again.');

}



$customer_name = trim((string)($_POST['customer_name'] ?? ''));

$contact_number = trim((string)($_POST['contact_number'] ?? ''));

$pet_name = trim((string)($_POST['pet_name'] ?? ''));

$pet_breed = trim((string)($_POST['pet_breed'] ?? ''));

$pet_type = trim((string)($_POST['pet_type'] ?? ''));

$pet_gender = trim((string)($_POST['pet_gender'] ?? ''));

$pet_weight = (float)($_POST['pet_weight'] ?? 0);

$service_category = trim((string)($_POST['service_category'] ?? ''));

$specific_service = trim((string)($_POST['specific_service'] ?? ''));

$haircut_style = trim((string)($_POST['haircut_style'] ?? ''));

$appointment_date = trim((string)($_POST['appointment_date'] ?? ''));

$appointment_time = trim((string)($_POST['appointment_time'] ?? ''));
$pickup_time = trim((string)($_POST['pickup_time'] ?? ''));
$checkout_date = trim((string)($_POST['checkout_date'] ?? ''));
$is_daycare = ($service_category === 'Pet Hotel' && stripos($specific_service, 'daycare') !== false);



// Enforce service permissions on the server; hiding options in the form is not enough.

$allowed_categories_by_role = [

    'admin' => ['Grooming', 'Vet Services', 'Pet Hotel'],

    'manager' => ['Grooming', 'Vet Services', 'Pet Hotel'],

    'receptionist' => ['Grooming', 'Vet Services', 'Pet Hotel'],

    'groomer' => ['Grooming'],

    'pet_hotel_staff' => ['Pet Hotel'],

];

if (!in_array($service_category, $allowed_categories_by_role[$current_role] ?? [], true)) {

    http_response_code(403);

    exit('Access denied. You cannot create a walk-in booking for this service category.');

}



$pricing_data = [

    'Dog' => [

        'Grooming' => [

            'Basic Pet Grooming' => [

                'Small (1-5kg)' => 400,

                'Medium (6-10kg)' => 500,

                'Large (11-15kg)' => 650,

                'Extra Large (16-20kg)' => 850,

                'XXL Large (21-25kg)' => 1000,

            ],

            'Full Grooming Package' => [

                'Small (1-5kg)' => 450,

                'Medium (6-10kg)' => 550,

                'Large (11-15kg)' => 700,

                'Extra Large (16-20kg)' => 900,

                'XXL Large (21-25kg)' => 1100,

            ],

            'Bath & Blow Dry' => [

                'Small (1-5kg)' => 300,

                'Medium (6-10kg)' => 350,

                'Large (11-15kg)' => 550,

                'Extra Large (16-20kg)' => 750,

                'XXL Large (21-25kg)' => 950,

            ],

        ],

        'Vet Services' => [

            'Deworming' => [

                'Small (1-5kg)' => 200,

                'Medium (6-10kg)' => 250,

                'Large (11-15kg)' => 300,

                'Extra Large (16-20kg)' => 350,

                'XXL Large (21-25kg)' => 450,

            ],

            'Vaccination - Anti Rabies' => ['default' => 300],

            'Vaccination - 5 in 1' => ['default' => 450],

            'Vaccination - 6 in 1' => ['default' => 600],

            'Vaccination - 8 in 1' => ['default' => 750],

        ],

        'Pet Hotel' => [

            'Pet Daycare (1st Hour - Succeeding fees apply)' => [

                'Small (1-5kg)' => 100,

                'Medium (6-10kg)' => 100,

                'Large (11-15kg)' => 150,

                'Extra Large (16-20kg)' => 150,

                'XXL Large (21-25kg)' => 200,

            ],

            'Pet Boarding (Overnight)' => [

                'Small (1-5kg)' => 500,

                'Medium (6-10kg)' => 500,

                'Large (11-15kg)' => 600,

                'Extra Large (16-20kg)' => 600,

                'XXL Large (21-25kg)' => 800,

            ],

        ],

    ],

    'Cat' => [

        'Grooming' => [

            'Cat Grooming (Basic)' => [

                'Small (1-5kg)' => 550,

                'Medium (6-10kg)' => 650,

                'Large (11-15kg)' => 750,

                'Extra Large (16-20kg)' => 850,

                'XXL Large (21-25kg)' => 950,

            ],

            'Cat Bath & Blow Dry' => [

                'Small (1-5kg)' => 400,

                'Medium (6-10kg)' => 500,

                'Large (11-15kg)' => 600,

                'Extra Large (16-20kg)' => 700,

                'XXL Large (21-25kg)' => 800,

            ],

        ],

        'Vet Services' => [

            'Deworming' => [

                'Small (1-5kg)' => 200,

                'Medium (6-10kg)' => 250,

                'Large (11-15kg)' => 300,

                'Extra Large (16-20kg)' => 350,

                'XXL Large (21-25kg)' => 450,

            ],

            'Vaccination - Anti Rabies' => ['default' => 300],

            'Vaccination - 4 in 1 (Cats)' => ['default' => 900],

        ],

        'Pet Hotel' => [

            'Pet Daycare (1st Hour - Succeeding fees apply)' => ['default' => 150],

            'Pet Boarding (Overnight)' => ['default' => 500],

        ],

    ],

];



function walkinSizeFromWeight(float $weight): string

{

    if ($weight > 0 && $weight <= 5) return 'Small (1-5kg)';

    if ($weight > 5 && $weight <= 10) return 'Medium (6-10kg)';

    if ($weight > 10 && $weight <= 15) return 'Large (11-15kg)';

    if ($weight > 15 && $weight <= 20) return 'Extra Large (16-20kg)';

    if ($weight > 20 && $weight <= 25) return 'XXL Large (21-25kg)';

    return '';

}



function walkinRedirect(string $message)
{
    $_SESSION['alert_msg'] = $message;
    $portal = (
        ($_SESSION['admin_logged_in'] ?? false) === true
        && strtolower(trim((string)($_SESSION['role'] ?? ''))) === 'admin'
    ) ? 'admin' : 'staff';
    header('Location: managebooking.php?portal=' . $portal);
    exit();
}



if ($customer_name === '' || $contact_number === '' || $pet_name === '' || $pet_type === '' || $pet_gender === '' || $pet_weight <= 0 || $service_category === '' || $specific_service === '' || $appointment_date === '' || $appointment_time === '') {

    walkinRedirect('Please complete all walk-in booking fields.');

}



if (mb_strlen($customer_name) > 150 || mb_strlen($pet_name) > 100 || mb_strlen($pet_breed) > 100) {

    walkinRedirect('Customer name, pet name, or breed is too long.');

}



if (!in_array($pet_gender, ['Male', 'Female'], true)) {

    walkinRedirect('Please select a valid pet gender.');

}



if (!preg_match('/^09\d{9}$/', $contact_number)) {

    walkinRedirect('Please enter a valid Philippine contact number (09XXXXXXXXX).');

}



if (!isset($pricing_data[$pet_type][$service_category][$specific_service])) {

    walkinRedirect('Invalid pet type or service selection.');

}



$service_prices = $pricing_data[$pet_type][$service_category][$specific_service];
$size_key = walkinSizeFromWeight($pet_weight);

if ($is_daycare) {
    // Pet Daycare uses its own client-confirmed weight tiers and additional-time rate.
    if ($pet_type === 'Cat') {
        $service_fee = 150.0;
    } elseif ($pet_weight > 0 && $pet_weight <= 10) {
        $service_fee = 100.0;
    } elseif ($pet_weight > 10 && $pet_weight <= 20) {
        $service_fee = 150.0;
    } elseif ($pet_weight > 20 && $pet_weight <= 50) {
        $service_fee = 200.0;
    } else {
        walkinRedirect('Pet Daycare supports dogs up to 50 kg. Please verify the pet weight.');
    }
} elseif (isset($service_prices['default'])) {
    $service_fee = (float)$service_prices['default'];
} elseif ($size_key !== '' && isset($service_prices[$size_key])) {
    $service_fee = (float)$service_prices[$size_key];
} else {
    walkinRedirect('The selected service does not have a price for this weight. Please enter a supported weight for the selected service.');
}



if ($specific_service === 'Full Grooming Package') {

    $allowed_haircut_styles = ['Puppy Cut', 'Summer Cut', 'Shave Down', 'Bear Cut', 'Poodle Cut'];

    if (!in_array($haircut_style, $allowed_haircut_styles, true)) {

        walkinRedirect('Please select a valid haircut style for the Full Grooming Package.');

    }

} else {

    $haircut_style = '';

}



$final_service_name = $service_category . ' - ' . $specific_service;

if ($specific_service === 'Full Grooming Package' && $haircut_style !== '') {

    $final_service_name .= ' (' . $haircut_style . ')';

}



$date_check = DateTime::createFromFormat('Y-m-d', $appointment_date);

if (!$date_check || $date_check->format('Y-m-d') !== $appointment_date) {

    walkinRedirect('Please enter a valid appointment date.');

}



$time_check = DateTime::createFromFormat('!H:i', $appointment_time);

if (!$time_check || $time_check->format('H:i') !== $appointment_time) {

    walkinRedirect('Please enter a valid appointment time.');

}

$is_overnight_boarding = ($service_category === 'Pet Hotel' && stripos($specific_service, 'boarding') !== false);
$daycare_start_minutes = null;
$daycare_end_minutes = null;
if ($is_overnight_boarding) {
    $checkout_check = DateTime::createFromFormat('!Y-m-d', $checkout_date);
    if (!$checkout_check || $checkout_check->format('Y-m-d') !== $checkout_date || $checkout_date <= $appointment_date) {
        walkinRedirect('For Pet Boarding, select a valid check-out date at least one day after check-in.');
    }
    $appointment_time = '09:00';
    $time_check = DateTime::createFromFormat('!H:i', $appointment_time);
    $pickup_time = '';
    $stay_start_for_price = new DateTimeImmutable($appointment_date);
    $stay_end_for_price = new DateTimeImmutable($checkout_date);
    $service_fee *= max(1, (int)$stay_start_for_price->diff($stay_end_for_price)->days);
} elseif ($is_daycare) {
    $checkout_date = '';
    $pickup_check = DateTime::createFromFormat('!H:i', $pickup_time);
    $pickup_errors = DateTime::getLastErrors();
    $pickup_valid = $pickup_check !== false && $pickup_check->format('H:i') === $pickup_time
        && ($pickup_errors === false || ($pickup_errors['warning_count'] === 0 && $pickup_errors['error_count'] === 0));
    if (!$pickup_valid) {
        walkinRedirect('For Pet Daycare, select a valid expected pickup time.');
    }

    $daycare_start_minutes = ((int)$time_check->format('H') * 60) + (int)$time_check->format('i');
    $daycare_end_minutes = ((int)$pickup_check->format('H') * 60) + (int)$pickup_check->format('i');
    if (($daycare_start_minutes % 30) !== 0) {
        walkinRedirect('Pet Daycare drop-off must be on a 30-minute time increment.');
    }
    if (($daycare_end_minutes % 30) !== 0 || $daycare_end_minutes <= $daycare_start_minutes || $daycare_end_minutes > 18 * 60) {
        walkinRedirect('Pet Daycare pickup must be later than drop-off, use a half-hour time, and be no later than 6:00 PM.');
    }

    $daycare_duration_minutes = $daycare_end_minutes - $daycare_start_minutes;
    $daycare_extra_blocks = (int)ceil(max(0, $daycare_duration_minutes - 60) / 30);
    $service_fee += $daycare_extra_blocks * 15;
} else {
    $checkout_date = '';
    $pickup_time = '';
}

if ($appointment_date < date('Y-m-d')) {
    walkinRedirect('Walk-in bookings cannot be created for a past date.');
}

if (!$is_overnight_boarding) {
    $start_minutes = ((int)$time_check->format('H') * 60) + (int)$time_check->format('i');
    if (($start_minutes % 30) !== 0) {
        walkinRedirect('Please choose an appointment start time on a 30-minute interval.');
    }

    $duration_minutes = $is_daycare
        ? ((int)$daycare_end_minutes - (int)$daycare_start_minutes)
        : ($service_category === 'Grooming'
            ? (stripos($specific_service, 'Full Grooming Package') !== false ? 120 : 60)
            : ($service_category === 'Vet Services' ? 30 : 60));
    if ($start_minutes < 9 * 60 || $start_minutes >= 18 * 60) {
        walkinRedirect('Choose a start time between 9:00 AM and 6:00 PM.');
    }
    $end_minutes = $start_minutes + $duration_minutes;
    $lunch_start_minutes = 12 * 60;
    $lunch_end_minutes = 13 * 60;
    if ($start_minutes >= $lunch_start_minutes && $start_minutes < $lunch_end_minutes) {
        walkinRedirect('Walk-in drop-off/start times are unavailable during the 12:00 PM–1:00 PM lunch break. Please choose another time.');
    }
    // Grooming and Vet appointments cannot run into the staff lunch break.
    // Daycare is a continuous stay, so a pet may remain in care during lunch.
    if (!$is_daycare && $start_minutes < $lunch_end_minutes && $end_minutes > $lunch_start_minutes) {
        walkinRedirect('This appointment overlaps the 12:00 PM–1:00 PM lunch break. Please choose another time.');
    }
    if ($end_minutes > 18 * 60) {
        walkinRedirect('This service would finish after closing time. Please choose an earlier start time.');
    }
    if ($service_category === 'Vet Services' && in_array((int)date('N', strtotime($appointment_date)), [3, 6], true)) {
        walkinRedirect('The veterinarian is not available on Wednesdays and Saturdays.');
    }
}

/** Convert a DB or form time (HH:MM[:SS]) to minutes after midnight. */
function walkinAvailabilityTimeToMinutes($value): ?int
{
    $time = trim((string)($value ?? ''));
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $time, $matches)) {
        return null;
    }
    $hour = (int)$matches[1];
    $minute = (int)$matches[2];
    if ($hour > 23 || $minute > 59) {
        return null;
    }
    return ($hour * 60) + $minute;
}

/** Read active Admin adjustments for [startDate, endDateExclusive). */
function walkinLoadAvailabilityAdjustments(PDO $pdo, string $startDate, string $endDateExclusive): array
{
    $tableExists = (bool)$pdo->query("SELECT to_regclass('public.availability_adjustments')")->fetchColumn();
    if (!$tableExists) {
        // Preserve regular walk-ins if the optional migration has not yet been installed.
        return [];
    }

    $stmt = $pdo->prepare("SELECT id, service_category,
                                  COALESCE(specific_service, '') AS specific_service,
                                  start_date::text AS start_date,
                                  end_date::text AS end_date,
                                  start_time::text AS start_time,
                                  end_time::text AS end_time,
                                  LOWER(BTRIM(adjustment_type)) AS adjustment_type,
                                  quantity, reason
                           FROM public.availability_adjustments
                           WHERE is_active = TRUE
                             AND start_date < CAST(:range_end AS date)
                             AND end_date > CAST(:range_start AS date)
                           ORDER BY start_date, start_time NULLS FIRST, id");
    $stmt->execute([
        ':range_start' => $startDate,
        ':range_end' => $endDateExclusive,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function walkinAdjustmentActiveOnDate(array $adjustment, string $date): bool
{
    $startDate = substr((string)($adjustment['start_date'] ?? ''), 0, 10);
    $endDate = substr((string)($adjustment['end_date'] ?? ''), 0, 10);
    return $startDate !== '' && $endDate !== '' && $startDate <= $date && $endDate > $date;
}

/** Count occupied manual units as real pets for daily capacity rules; blocked time is not a booking. */
function walkinManualOccupiedUnitsForDate(array $adjustments, string $date, ?string $category = null): int
{
    $total = 0;
    foreach ($adjustments as $adjustment) {
        if (!walkinAdjustmentActiveOnDate($adjustment, $date)) {
            continue;
        }
        if (strtolower(trim((string)($adjustment['adjustment_type'] ?? ''))) !== 'occupied') {
            continue;
        }
        if ($category !== null && (string)($adjustment['service_category'] ?? '') !== $category) {
            continue;
        }
        $total += max(1, (int)($adjustment['quantity'] ?? 1));
    }
    return $total;
}

/**
 * Expand manual occupancy into capacity units for this category/date.
 * An occupied adjustment uses its quantity. A matching blocked adjustment
 * reserves the full simultaneous capacity for its time range.
 */
/** Count unavailable service/room units for a date without treating them as actual pets. */
function walkinManualCapacityReducedUnitsForDate(array $adjustments, string $date, string $category, ?string $service = null): int
{
    $total = 0;
    foreach ($adjustments as $adjustment) {
        if (!walkinAdjustmentActiveOnDate($adjustment, $date)) continue;
        if ((string)($adjustment['service_category'] ?? '') !== $category) continue;
        if (strtolower(trim((string)($adjustment['adjustment_type'] ?? ''))) !== 'capacity_reduced') continue;
        $adjustmentService = trim((string)($adjustment['specific_service'] ?? ''));
        if ($adjustmentService !== '' && $service !== null && strcasecmp($adjustmentService, $service) !== 0) continue;
        $total += max(1, (int)($adjustment['quantity'] ?? 1));
    }
    return $total;
}

function walkinManualCapacityIntervals(
    array $adjustments,
    string $date,
    string $category,
    string $requestedService,
    int $normalCapacity
): array {
    $intervals = [];
    foreach ($adjustments as $adjustment) {
        if (!walkinAdjustmentActiveOnDate($adjustment, $date)
            || (string)($adjustment['service_category'] ?? '') !== $category) {
            continue;
        }

        $type = strtolower(trim((string)($adjustment['adjustment_type'] ?? '')));
        if (!in_array($type, ['occupied', 'blocked', 'capacity_reduced'], true)) {
            continue;
        }

        $adjustmentService = trim((string)($adjustment['specific_service'] ?? ''));
        // Service-specific blocks close only their selected service. Occupied
        // units consume shared groomer/vet/room capacity across the category.
        if (in_array($type, ['blocked', 'capacity_reduced'], true) && $adjustmentService !== ''
            && strcasecmp($adjustmentService, $requestedService) !== 0) {
            continue;
        }

        $rawStart = trim((string)($adjustment['start_time'] ?? ''));
        $rawEnd = trim((string)($adjustment['end_time'] ?? ''));
        $start = $rawStart !== '' ? walkinAvailabilityTimeToMinutes($rawStart) : null;
        $end = $rawEnd !== '' ? walkinAvailabilityTimeToMinutes($rawEnd) : null;

        if ($start === null && $end === null) {
            $start = 0;
            $end = 1440;
        } elseif ($start === null || $end === null || $end <= $start) {
            // The Admin form should prevent this; skip malformed legacy rows safely.
            continue;
        }

        $units = $type === 'blocked'
            ? max(1, $normalCapacity)
            : max(1, (int)($adjustment['quantity'] ?? 1));
        for ($unit = 0; $unit < $units; $unit++) {
            $intervals[] = [
                'start' => $start,
                'end' => $end,
                'type' => $type,
                'service' => $adjustmentService,
            ];
        }
    }
    return $intervals;
}

function walkinIntervalsOverlap(int $candidateStart, int $candidateEnd, int $existingStart, int $existingEnd): bool
{
    return $candidateStart < $existingEnd && $existingStart < $candidateEnd;
}

// Connect only after authorization, CSRF, and walk-in input validation succeed.
require_once __DIR__ . '/../db_supabase.php';

try {

    $pdo->beginTransaction();

    // Use the same per-date advisory locks as online booking so concurrent
    // online and walk-in submissions cannot both claim the last available capacity.
    $lock_start = new DateTimeImmutable($appointment_date, new DateTimeZone('Asia/Manila'));
    $lock_end = $is_overnight_boarding
        ? new DateTimeImmutable($checkout_date, new DateTimeZone('Asia/Manila'))
        : $lock_start->modify('+1 day');
    for ($lock_day = $lock_start; $lock_day < $lock_end; $lock_day = $lock_day->modify('+1 day')) {
        $capacity_lock = $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(:lock_key)::bigint)');
        $capacity_lock->execute([':lock_key' => 'boogie-capacity|' . $lock_day->format('Y-m-d')]);
    }

    $active_status_sql = "LOWER(BTRIM(COALESCE(booking_status, ''))) NOT IN ('cancelled', 'canceled', 'no-show', 'no show', 'noshow', 'for rescheduling')";
    $capacity_error = '';
    $manual_adjustments_end = $is_overnight_boarding ? $checkout_date : $lock_end->format('Y-m-d');
    $manual_adjustments = walkinLoadAvailabilityAdjustments($pdo, $appointment_date, $manual_adjustments_end);

    if ($is_overnight_boarding) {
        $range_stmt = $pdo->prepare("
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
        $range_stmt->execute([':checkout_date' => $checkout_date, ':checkin_date' => $appointment_date]);
        $rows = $range_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        for ($day = $lock_start; $day < $lock_end; $day = $day->modify('+1 day')) {
            $day_string = $day->format('Y-m-d');
            $total = 0;
            $hotel_count = 0;
            foreach ($rows as $row) {
                $existing_date = substr((string)($row['appointment_date'] ?? ''), 0, 10);
                if ($existing_date === '') continue;
                $service_name = (string)($row['service'] ?? '');
                $existing_boarding = stripos($service_name, 'Pet Hotel -') === 0 && stripos($service_name, 'boarding') !== false;
                $existing_checkout = substr((string)($row['checkout_date'] ?? ''), 0, 10);
                if ($existing_checkout === '') $existing_checkout = (new DateTimeImmutable($existing_date))->modify('+1 day')->format('Y-m-d');
                $active_on_day = $existing_boarding
                    ? ($existing_date <= $day_string && $existing_checkout > $day_string)
                    : ($existing_date === $day_string);
                if (!$active_on_day) continue;
                $total++;
                if (stripos($service_name, 'Pet Hotel -') === 0) $hotel_count++;
            }

            // Add Admin-marked, unrecorded walk-ins to each affected night.
            $total += walkinManualOccupiedUnitsForDate($manual_adjustments, $day_string);
            $hotel_count += walkinManualOccupiedUnitsForDate($manual_adjustments, $day_string, 'Pet Hotel');
            $hotel_count += walkinManualCapacityReducedUnitsForDate($manual_adjustments, $day_string, 'Pet Hotel', 'Pet Boarding (Overnight)');

            // Boarding occupies the room for the entire stay, so any matching
            // blocked Pet Hotel period during any night makes this stay unavailable.
            $boarding_manual_intervals = walkinManualCapacityIntervals(
                $manual_adjustments, $day_string, 'Pet Hotel', $specific_service, 6
            );
            foreach ($boarding_manual_intervals as $manual_interval) {
                if (($manual_interval['type'] ?? '') === 'blocked'
                    && walkinIntervalsOverlap(0, 1440, (int)$manual_interval['start'], (int)$manual_interval['end'])) {
                    $capacity_error = 'Pet Hotel availability is blocked by an Admin adjustment on ' . $day_string . '. Please choose different dates or ask the Admin to reopen the rooms.';
                    break;
                }
            }
            if ($capacity_error !== '') {
                break;
            }

            if ($total >= 20) {
                $capacity_error = 'The overall daily capacity of 20 pets has been reached on ' . $day_string . '.';
                break;
            }
            if ($hotel_count >= 6) {
                $capacity_error = 'Pet Hotel has reached its six-room capacity on ' . $day_string . '. Please choose different dates.';
                break;
            }
        }
    } else {
        $existing_stmt = $pdo->prepare("
            SELECT appointment_date::text AS appointment_date,
                   appointment_time::text AS appointment_time,
                   checkout_date::text AS checkout_date,
                   pickup_time::text AS pickup_time,
                   service
            FROM appointments
            WHERE {$active_status_sql}
              AND (
                appointment_date = CAST(:selected_date AS date)
                OR (
                  LOWER(COALESCE(service, '')) LIKE 'pet hotel%boarding%'
                  AND appointment_date < CAST(:selected_date_before AS date)
                  AND COALESCE(checkout_date, appointment_date + 1) > CAST(:selected_date_active AS date)
                )
              )
        ");
        $existing_stmt->execute([
            ':selected_date' => $appointment_date,
            ':selected_date_before' => $appointment_date,
            ':selected_date_active' => $appointment_date
        ]);
        $rows = $existing_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $daily_limits = ['Grooming' => 10, 'Vet Services' => 30, 'Pet Hotel' => 6];
        $simultaneous_limits = ['Grooming' => 2, 'Vet Services' => 1, 'Pet Hotel' => 6];
        // Manual occupied adjustments represent real walk-ins not yet registered
        // as appointments, so include them in both overall and category daily caps.
        $daily_total = count($rows) + walkinManualOccupiedUnitsForDate($manual_adjustments, $appointment_date);
        $category_count = walkinManualOccupiedUnitsForDate($manual_adjustments, $appointment_date, $service_category);
        $start_minutes = ((int)substr($appointment_time, 0, 2) * 60) + (int)substr($appointment_time, 3, 2);
        $duration = $is_daycare
            ? ((int)$daycare_end_minutes - (int)$daycare_start_minutes)
            : ($service_category === 'Grooming'
                ? (stripos($specific_service, 'Full Grooming') !== false ? 120 : 60)
                : ($service_category === 'Vet Services' ? 30 : 60));
        $end_minutes = $start_minutes + $duration;
        $overlaps = 0;
        foreach ($rows as $row) {
            $stored = (string)($row['service'] ?? '');
            if (stripos($stored, $service_category . ' -') !== 0) continue;
            $category_count++;
            $stored_date = substr((string)($row['appointment_date'] ?? ''), 0, 10);
            $stored_boarding = $service_category === 'Pet Hotel' && stripos($stored, 'boarding') !== false;
            if ($stored_boarding) {
                $stored_checkout = substr((string)($row['checkout_date'] ?? ''), 0, 10);
                if ($stored_checkout === '') {
                    $stored_checkout = (new DateTimeImmutable($stored_date))->modify('+1 day')->format('Y-m-d');
                }
                if ($stored_date <= $appointment_date && $stored_checkout > $appointment_date) {
                    // An active boarding reservation occupies a room all day.
                    $overlaps++;
                }
                continue;
            }
            if ($stored_date !== $appointment_date) continue;
            $stored_time = substr((string)($row['appointment_time'] ?? ''), 0, 5);
            if (!preg_match('/^\d{2}:\d{2}$/', $stored_time)) continue;
            $existing_start = ((int)substr($stored_time, 0, 2) * 60) + (int)substr($stored_time, 3, 2);
            $existing_duration = stripos($stored, 'Full Grooming Package') !== false ? 120 : ($service_category === 'Vet Services' ? 30 : 60);
            $existing_end = $existing_start + $existing_duration;
            if ($service_category === 'Pet Hotel' && stripos($stored, 'daycare') !== false) {
                $stored_pickup_minutes = walkinAvailabilityTimeToMinutes((string)($row['pickup_time'] ?? ''));
                if ($stored_pickup_minutes !== null && $stored_pickup_minutes > $existing_start) {
                    $existing_end = $stored_pickup_minutes;
                }
            }
            if ($start_minutes < $existing_end && $end_minutes > $existing_start) $overlaps++;
        }

        $manual_intervals = walkinManualCapacityIntervals(
            $manual_adjustments, $appointment_date, $service_category, $specific_service, $simultaneous_limits[$service_category]
        );
        foreach ($manual_intervals as $manual_interval) {
            if (walkinIntervalsOverlap($start_minutes, $end_minutes, (int)$manual_interval['start'], (int)$manual_interval['end'])) {
                $overlaps++;
            }
        }

        if ($daily_total >= 20) $capacity_error = 'The overall daily capacity of 20 pets has been reached. Please select another date.';
        elseif ($category_count >= $daily_limits[$service_category]) $capacity_error = $service_category . ' has reached its daily capacity. Please select another date.';
        elseif ($overlaps >= $simultaneous_limits[$service_category]) {
            $capacity_error = $service_category === 'Grooming' ? 'Both groomers are already booked or unavailable during this time. Please select another time.'
                : ($service_category === 'Vet Services' ? 'The veterinarian is already booked or unavailable during this time. Please select another time.' : 'No Pet Hotel room is available during this time.');
        }
    }

    if ($capacity_error !== '') {
        $pdo->rollBack();
        walkinRedirect($capacity_error);
    }



    $dummy_email = 'walkin_' . time() . '_' . bin2hex(random_bytes(4)) . '@boogies.local';



    $user_stmt = $pdo->prepare("

        INSERT INTO users

            (full_name, email, password, contact_number, role, position, user_category, is_verified, email_verified)

        VALUES

            (:full_name, :email, :password, :contact_number, 'customer', 'Customer', 'Pet Owner', 1, 1)

        RETURNING id

    ");



    $user_stmt->execute([

        ':full_name' => $customer_name . ' (Walk-in)',

        ':email' => $dummy_email,

       ':password' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),

        ':contact_number' => $contact_number,

    ]);



    $new_user_id = (int)$user_stmt->fetchColumn();



    $pet_stmt = $pdo->prepare("

        INSERT INTO pets

            (owner_id, name, pet_type, breed, gender, weight, owner_name)

        VALUES

            (:owner_id, :name, :pet_type, NULLIF(:breed, ''), :gender, :weight, :owner_name)

        RETURNING id

    ");



    $pet_stmt->execute([

        ':owner_id' => $new_user_id,

        ':name' => $pet_name,

        ':pet_type' => $pet_type,

        ':breed' => $pet_breed,

        ':gender' => $pet_gender,

        ':weight' => number_format($pet_weight, 1, '.', '') . 'kg',

        ':owner_name' => $customer_name . ' (Walk-in)',

    ]);



    $new_pet_id = (int)$pet_stmt->fetchColumn();



    $appointment_stmt = $pdo->prepare("

        INSERT INTO appointments

            (

                user_id,

                pet_id,

                service,

                appointment_date,

                appointment_time,

                service_fee,

                total_price,

                payment_method,

                payment_status,

                booking_status,

                appointment_type,

                vet_doctor,
                checkout_date,
                pickup_time

            )

        VALUES

            (

                :user_id,

                :pet_id,

                :service,

                :appointment_date,

                :appointment_time,

                :service_fee,

                :total_price,

                'Cash (Walk-in)',

                'Pending',

                'Confirmed',

                'Walk-in',

                :vet_doctor,
                :checkout_date,
                :pickup_time

            )

    ");



    $appointment_stmt->execute([

        ':user_id' => $new_user_id,

        ':pet_id' => $new_pet_id,

        ':service' => $final_service_name,

        ':appointment_date' => $appointment_date,

        ':appointment_time' => $appointment_time,

        ':service_fee' => $service_fee,

        ':total_price' => $service_fee,

        ':vet_doctor' => $service_category === 'Vet Services' ? 'Dr. Faith Casayuran' : null,
        ':checkout_date' => $is_overnight_boarding ? $checkout_date : null,
        ':pickup_time' => $is_daycare ? $pickup_time : null,

    ]);



    $pdo->commit();



    $_SESSION['alert_msg'] = 'Walk-in booking created and confirmed. Payment remains Pending until cash is actually received. Total: ₱' . number_format($service_fee, 2);

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {

        $pdo->rollBack();

    }



    error_log('Walk-in booking error: ' . $e->getMessage());

    $_SESSION['alert_msg'] = 'Unable to add the walk-in booking. Check the PHP error log for details.';

}



header('Location: managebooking.php?portal=' . $portal_mode);

exit();
