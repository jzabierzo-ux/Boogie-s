<?php
/**
 * Boogie's Pet Care & Services - slot availability endpoint.
 *
 * Compatibility:
 * - Requests containing only date + category retain the legacy response/behavior
 *   until book_appointment.php is updated.
 * - Requests that also include specific_service use the capacity/duration-aware
 *   scheduler and return additional `slots`, `available_slots`, and capacity data.
 *
 * Initial scheduling assumptions based on the client survey/interview and adviser:
 * - Business hours: 09:00-18:00 Asia/Manila.
 * - New bookings may not START from 12:00-13:00.
 *   Services started before the break may continue through it if staff capacity allows.
 *   Assume one groomer is on lunch, so grooming capacity drops to 1 during the break.
 * - Overall daily limit: 20 pets.
 * - Per-category daily limits: Grooming 10, Vet Services 30, Pet Hotel 6.
 * - Simultaneous capacity: Grooming 2, Vet Services 2 (doctor + reliever), Pet Hotel 6.
 * - Durations are defaults: Basic grooming 60 min, Full Grooming 120 min,
 *   Bath & Blow Dry 90 min, Vet 30 min, Pet Daycare 180 min.
 * - Overnight boarding uses checkout_date and checks room/global capacity for every occupied night.
 */

require_once __DIR__ . '/../db_supabase.php';
date_default_timezone_set('Asia/Manila');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$response = [
    'is_full' => false,
    'booked_times' => [],
];

$date_input = $_GET['date'] ?? '';
$category_input = $_GET['category'] ?? '';
$specific_service_input = $_GET['specific_service'] ?? ($_GET['service'] ?? '');

$date = is_string($date_input) ? trim($date_input) : '';
$category = is_string($category_input) ? trim($category_input) : '';
$specific_service = is_string($specific_service_input) ? trim($specific_service_input) : '';
$checkout_date_input = $_GET['checkout_date'] ?? '';
$checkout_date = is_string($checkout_date_input) ? trim($checkout_date_input) : '';
$dropoff_time_input = $_GET['dropoff_time'] ?? '';
$dropoff_time = is_string($dropoff_time_input) ? trim($dropoff_time_input) : '';
$pickup_time_input = $_GET['pickup_time'] ?? '';
$pickup_time = is_string($pickup_time_input) ? trim($pickup_time_input) : '';

if ($date === '' || $category === '') {
    http_response_code(400);
    echo json_encode(['is_full' => false, 'booked_times' => [], 'error' => 'Missing or invalid parameters']);
    exit;
}

$date_object = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
$date_errors = DateTimeImmutable::getLastErrors();
if (
    $date_object === false ||
    $date_object->format('Y-m-d') !== $date ||
    ($date_errors !== false && ($date_errors['warning_count'] > 0 || $date_errors['error_count'] > 0))
) {
    http_response_code(400);
    echo json_encode(['is_full' => false, 'booked_times' => [], 'error' => 'Invalid date format']);
    exit;
}

$allowed_categories = ['Grooming', 'Vet Services', 'Pet Hotel'];
if (!in_array($category, $allowed_categories, true)) {
    http_response_code(400);
    echo json_encode(['is_full' => false, 'booked_times' => [], 'error' => 'Invalid service category']);
    exit;
}

// A request without a specific service is from the existing frontend. Keep its old
// contract until book_appointment.php is upgraded in the next step.
$advanced_mode = ($specific_service !== '');

$legacy_times = [
    '10:00:00', '11:00:00', '12:00:00', '13:00:00',
    '14:00:00', '15:00:00', '16:00:00', '17:00:00'
];

$global_daily_limit = 20;
$rules = [
    'Grooming' => ['daily_limit' => 10, 'simultaneous_limit' => 2],
    'Vet Services' => ['daily_limit' => 30, 'simultaneous_limit' => 2],
    'Pet Hotel' => ['daily_limit' => 6, 'simultaneous_limit' => 6],
];

$active_status_sql = "LOWER(BTRIM(COALESCE(booking_status, ''))) NOT IN ('cancelled', 'canceled', 'no-show', 'no show', 'noshow', 'for rescheduling')";
$service_prefix = $category . '%';

$opening_minutes = 9 * 60;
$closing_minutes = 18 * 60;
$break_start = 12 * 60;
$break_end = 13 * 60; // One-hour break is an implementation assumption from the available interview details.
$selected_day_of_week = (int)$date_object->format('N');
$vet_unavailable_day = ($category === 'Vet Services' && in_array($selected_day_of_week, [3, 6], true));

/** Convert HH:MM[:SS] to minutes since midnight. */
function slotTimeToMinutes($time): ?int
{
    $value = trim((string)($time ?? ''));
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $m)) {
        return null;
    }
    $hour = (int)$m[1];
    $minute = (int)$m[2];
    if ($hour > 23 || $minute > 59) return null;
    return ($hour * 60) + $minute;
}

/** Pickup can be at 6:00 PM, the closing boundary; starts are separately restricted. */
function slotPickupTimeToMinutes($time): ?int
{
    $minutes = slotTimeToMinutes($time);
    if ($minutes === null || $minutes < 9 * 60 || $minutes > 18 * 60) return null;
    if (($minutes % 30) !== 0) return null;
    return $minutes;
}

function slotIsHotelDaycare(string $serviceName): bool
{
    return strpos(strtolower(trim($serviceName)), 'daycare') !== false;
}

/**
 * Estimate duration for a stored appointment based on known service labels.
 * Longest-known duration is used for unrecognized grooming services to reduce
 * the chance of an overlapping booking until all durations are confirmed.
 */
function slotDurationMinutes(string $category, string $serviceName, bool $requestedService = false): int
{
    $name = strtolower(trim($serviceName));

    if ($category === 'Grooming') {
        if (strpos($name, 'full grooming package') !== false || preg_match('/\bfg\b/', $name)) return 120;
        if (strpos($name, 'bath & blow dry') !== false || strpos($name, 'bath and blow dry') !== false || strpos($name, 'cat bath') !== false) return 90;
        if (strpos($name, 'basic pet grooming') !== false || strpos($name, 'cat grooming (basic)') !== false) return 60;
        return 120; // Conservative default for unknown grooming labels.
    }

    if ($category === 'Vet Services') {
        return 30;
    }

    if ($category === 'Pet Hotel') {
        if (strpos($name, 'daycare') !== false) return 180;
        // Overnight boarding is handled as a date range using checkout_date; the stored
        // appointment_time is the standard 9:00 AM check-in marker.
        if (strpos($name, 'boarding') !== false || strpos($name, 'overnight') !== false) return 30;
        return 180;
    }

    return 60;
}


/**
 * Return online start times aligned with the selected service duration.
 * Morning and afternoon periods have separate anchors so lunch does not shift
 * the afternoon schedule. Pet Boarding uses the separate fixed 9:00 AM check-in.
 */
function slotDurationAlignedStartMinutes(string $category, string $specificService, int $opening, int $closing, int $breakStart, int $breakEnd): array
{
    if ($category === 'Pet Hotel' && slotIsHotelBoarding($specificService)) {
        return [$opening];
    }

    if ($category === 'Pet Hotel' && slotIsHotelDaycare($specificService)) {
        $times = [];
        foreach ([[$opening, $breakStart], [$breakEnd, $closing]] as [$periodStart, $periodEnd]) {
            for ($start = $periodStart; $start < $periodEnd; $start += 30) $times[] = $start;
        }
        return $times;
    }

    $duration = slotDurationMinutes($category, $specificService, true);
    if ($duration <= 0) return [];

    $times = [];
    foreach ([[$opening, $breakStart], [$breakEnd, $closing]] as [$periodStart, $periodEnd]) {
        for ($start = $periodStart; $start < $periodEnd && ($start + $duration) <= $closing; $start += $duration) {
            $times[] = $start;
        }
    }
    return array_values(array_unique($times));
}

function slotCategoryForService(string $service): ?string
{
    foreach (['Grooming', 'Vet Services', 'Pet Hotel'] as $name) {
        if (stripos($service, $name . ' -') === 0 || strcasecmp(trim($service), $name) === 0) {
            return $name;
        }
    }
    return null;
}

/** Whether a Pet Hotel appointment represents overnight boarding. */
function slotIsHotelBoarding(string $serviceName): bool
{
    $name = strtolower(trim($serviceName));
    return strpos($name, 'boarding') !== false || strpos($name, 'overnight') !== false;
}

/**
 * Load Admin-created manual availability adjustments for a date range.
 * The table is introduced by availability_adjustments.sql. Until the migration
 * is applied, this endpoint keeps its current appointment-only behavior.
 *
 * endDateExclusive follows the same convention as checkout_date for boarding.
 */
function slotLoadManualAdjustments(PDO $pdo, string $startDate, string $endDateExclusive): array
{
    static $tableExists = null;
    if ($tableExists === null) {
        $tableExists = (bool)$pdo->query("SELECT to_regclass('public.availability_adjustments')")->fetchColumn();
    }
    if (!$tableExists) return [];

    $stmt = $pdo->prepare("
        SELECT id, service_category, COALESCE(specific_service, '') AS specific_service,
               start_date::text AS start_date, end_date::text AS end_date,
               start_time::text AS start_time, end_time::text AS end_time,
               LOWER(BTRIM(adjustment_type)) AS adjustment_type, quantity, reason
        FROM public.availability_adjustments
        WHERE is_active = TRUE
          AND start_date < CAST(:range_end AS date)
          AND end_date > CAST(:range_start AS date)
        ORDER BY start_date, start_time NULLS FIRST, id
    ");
    $stmt->execute([
        ':range_start' => $startDate,
        ':range_end' => $endDateExclusive,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** True when the adjustment occupies the requested calendar date. */
function slotManualAdjustmentActiveOnDate(array $adjustment, string $date): bool
{
    $startDate = substr((string)($adjustment['start_date'] ?? ''), 0, 10);
    $endDate = substr((string)($adjustment['end_date'] ?? ''), 0, 10);
    return $startDate !== '' && $endDate !== '' && $startDate <= $date && $endDate > $date;
}

/** Count real/occupied manual units; blocked slots do not consume the daily pet quota. */
function slotManualOccupiedUnitsForDate(array $adjustments, string $date, ?string $category = null): int
{
    $total = 0;
    foreach ($adjustments as $adjustment) {
        if (!slotManualAdjustmentActiveOnDate($adjustment, $date)) continue;
        if (strtolower(trim((string)($adjustment['adjustment_type'] ?? ''))) !== 'occupied') continue;
        if ($category !== null && (string)($adjustment['service_category'] ?? '') !== $category) continue;
        $total += max(1, (int)($adjustment['quantity'] ?? 1));
    }
    return $total;
}

/**
 * Convert manual occupied/block adjustments into capacity intervals.
 * Each occupied quantity represents a real unrecorded walk-in/unit. A blocked
 * interval reserves the entire configured simultaneous capacity for that period.
 */
function slotManualIntervalsForDate(
    array $adjustments,
    string $date,
    string $category,
    string $requestedService,
    int $normalCapacity
): array {
    $intervals = [];
    foreach ($adjustments as $adjustment) {
        if (!slotManualAdjustmentActiveOnDate($adjustment, $date)) continue;
        if ((string)($adjustment['service_category'] ?? '') !== $category) continue;

        $type = strtolower(trim((string)($adjustment['adjustment_type'] ?? '')));
        if (!in_array($type, ['occupied', 'blocked', 'capacity_reduced'], true)) continue;

        $adjustmentService = trim((string)($adjustment['specific_service'] ?? ''));
        // A service-specific BLOCK closes that service only. OCCUPIED units still
        // consume shared staff/room capacity for other services in the same category.
        if (in_array($type, ['blocked', 'capacity_reduced'], true) && $adjustmentService !== '' && $requestedService !== ''
            && strcasecmp($adjustmentService, $requestedService) !== 0) {
            continue;
        }

        $rawStart = trim((string)($adjustment['start_time'] ?? ''));
        $rawEnd = trim((string)($adjustment['end_time'] ?? ''));
        $start = ($rawStart !== '') ? slotTimeToMinutes($rawStart) : null;
        $end = ($rawEnd !== '') ? slotTimeToMinutes($rawEnd) : null;

        if ($start === null && $end === null) {
            // Both times blank means a full-day/room-stay adjustment.
            $start = 0;
            $end = 1440;
        } elseif ($start === null) {
            $start = 0;
        } elseif ($end === null || $end <= $start) {
            $serviceForDuration = ($adjustmentService !== '') ? $adjustmentService : $requestedService;
            $end = min(1440, $start + slotDurationMinutes($category, $serviceForDuration));
        }

        if ($start === null || $end === null || $end <= $start) continue;
        $units = ($type === 'blocked')
            ? max(1, $normalCapacity)
            : max(1, (int)($adjustment['quantity'] ?? 1));
        $serviceLabel = $adjustmentService !== '' ? $adjustmentService : $requestedService;
        for ($i = 0; $i < $units; $i++) {
            $intervals[] = [
                'start' => $start,
                'end' => $end,
                'service' => $category . ' - ' . $serviceLabel,
                'manual_adjustment' => true,
                'adjustment_type' => $type,
            ];
        }
    }
    return $intervals;
}

function slotIntervalsOverlap(int $startA, int $endA, int $startB, int $endB): bool
{
    return $startA < $endB && $startB < $endA;
}

/** Capacity available at a point in time when staff breaks are staggered. */
function slotCapacityAtMinute(string $category, int $minute, int $normalCapacity, int $breakStart, int $breakEnd): int
{
    // The shop can keep working during lunch, but only one groomer is assumed on duty.
    if ($category === 'Grooming' && $minute >= $breakStart && $minute < $breakEnd) {
        return 1;
    }

    // Vet and hotel coverage remain at their normal assumed capacity because staff
    // shift assignments are not stored in this project.
    return $normalCapacity;
}

/** Collect candidate interval start points where occupancy or capacity can change. */
function slotCapacityCheckpoints(int $candidateStart, int $candidateEnd, array $appointments, int $breakStart, int $breakEnd): array
{
    $checkpoints = [$candidateStart];
    foreach ([$breakStart, $breakEnd] as $boundary) {
        if ($boundary > $candidateStart && $boundary < $candidateEnd) $checkpoints[] = $boundary;
    }
    foreach ($appointments as $appointment) {
        if (!slotIntervalsOverlap($candidateStart, $candidateEnd, $appointment['start'], $appointment['end'])) continue;
        if ($appointment['start'] > $candidateStart && $appointment['start'] < $candidateEnd) $checkpoints[] = $appointment['start'];
        if ($appointment['end'] > $candidateStart && $appointment['end'] < $candidateEnd) $checkpoints[] = $appointment['end'];
    }
    $checkpoints = array_values(array_unique($checkpoints));
    sort($checkpoints);
    return $checkpoints;
}

/** True if adding one booking would exceed capacity at any point in its duration. */
function slotWouldExceedCapacity(int $candidateStart, int $candidateEnd, array $appointments, string $category, int $normalCapacity, int $breakStart, int $breakEnd): bool
{
    foreach (slotCapacityCheckpoints($candidateStart, $candidateEnd, $appointments, $breakStart, $breakEnd) as $point) {
        $activeExisting = 0;
        foreach ($appointments as $appointment) {
            if ($appointment['start'] <= $point && $appointment['end'] > $point) $activeExisting++;
        }
        $capacity = slotCapacityAtMinute($category, $point, $normalCapacity, $breakStart, $breakEnd);
        if ($activeExisting + 1 > $capacity) return true;
    }
    return false;
}

/** Minimum simultaneous places available at any point of the candidate duration. */
function slotMinimumRemaining(int $candidateStart, int $candidateEnd, array $appointments, string $category, int $normalCapacity, int $breakStart, int $breakEnd): int
{
    $minimum = $normalCapacity;
    foreach (slotCapacityCheckpoints($candidateStart, $candidateEnd, $appointments, $breakStart, $breakEnd) as $point) {
        $activeExisting = 0;
        foreach ($appointments as $appointment) {
            if ($appointment['start'] <= $point && $appointment['end'] > $point) $activeExisting++;
        }
        $capacity = slotCapacityAtMinute($category, $point, $normalCapacity, $breakStart, $breakEnd);
        $minimum = min($minimum, max(0, $capacity - $activeExisting));
    }
    return $minimum;
}

try {
    // Legacy mode deliberately preserves old UI behavior until the form is updated.
    if (!$advanced_mode) {
        $legacy_daily_limit = 8;
        $stmt_count = $pdo->prepare("\n            SELECT COUNT(*) AS total_bookings\n            FROM appointments\n            WHERE appointment_date = :appointment_date\n              AND service ILIKE :service_prefix\n              AND {$active_status_sql}\n        ");
        $stmt_count->execute([
            ':appointment_date' => $date,
            ':service_prefix' => $service_prefix,
        ]);
        $row_count = $stmt_count->fetch(PDO::FETCH_ASSOC) ?: [];
        $next_day = $date_object->modify('+1 day')->format('Y-m-d');
        $manual_adjustments = slotLoadManualAdjustments($pdo, $date, $next_day);
        $manual_occupied_count = slotManualOccupiedUnitsForDate($manual_adjustments, $date, $category);
        $legacy_total_bookings = (int)($row_count['total_bookings'] ?? 0) + $manual_occupied_count;

        if ($legacy_total_bookings >= $legacy_daily_limit) {
            $response['is_full'] = true;
        } else {
            $stmt_slots = $pdo->prepare("\n                SELECT appointment_time::text AS appointment_time, COUNT(*) AS slot_count\n                FROM appointments\n                WHERE appointment_date = :appointment_date\n                  AND service ILIKE :service_prefix\n                  AND {$active_status_sql}\n                GROUP BY appointment_time\n                ORDER BY appointment_time\n            ");
            $stmt_slots->execute([
                ':appointment_date' => $date,
                ':service_prefix' => $service_prefix,
            ]);

            foreach ($stmt_slots->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ((int)($row['slot_count'] ?? 0) >= 1) {
                    $time = (string)($row['appointment_time'] ?? '');
                    if ($time !== '') $response['booked_times'][] = $time;
                }
            }
        }

        foreach ($manual_adjustments as $adjustment) {
            if (!slotManualAdjustmentActiveOnDate($adjustment, $date)
                || (string)($adjustment['service_category'] ?? '') !== $category) {
                continue;
            }
            $rawTime = trim((string)($adjustment['start_time'] ?? ''));
            if ($rawTime === '') {
                foreach ($legacy_times as $legacyTime) $response['booked_times'][] = $legacyTime;
            } else {
                $minutes = slotTimeToMinutes($rawTime);
                if ($minutes !== null) {
                    $response['booked_times'][] = sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
                }
            }
        }
        $response['booked_times'] = array_values(array_unique($response['booked_times']));

        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    $allowed_services = [
        'Grooming' => ['Basic Pet Grooming', 'Full Grooming Package', 'Bath & Blow Dry', 'Cat Grooming (Basic)', 'Cat Bath & Blow Dry'],
        'Vet Services' => ['Deworming', 'Vaccination - Anti Rabies', 'Vaccination - 5 in 1', 'Vaccination - 6 in 1', 'Vaccination - 8 in 1', 'Vaccination - 4 in 1 (Cats)'],
        'Pet Hotel' => ['Pet Daycare (1st Hour - Succeeding fees apply)', 'Pet Boarding (Overnight)'],
    ];
    if (!in_array($specific_service, $allowed_services[$category], true)) {
        http_response_code(400);
        echo json_encode(['is_full' => false, 'booked_times' => [], 'error' => 'Invalid specific service']);
        exit;
    }

    // Daycare availability is checked against the customer's actual drop-off/pickup interval.
    if ($category === 'Pet Hotel' && slotIsHotelDaycare($specific_service) && $dropoff_time !== '' && $pickup_time !== '') {
        $start = slotTimeToMinutes($dropoff_time);
        $end = slotPickupTimeToMinutes($pickup_time);
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
        $today = $now->format('Y-m-d');
        $current_minutes = ((int)$now->format('H') * 60) + (int)$now->format('i');
        $opening = 9 * 60;
        $breakStart = 12 * 60;
        $breakEnd = 13 * 60;
        $closing = 18 * 60;
        $reason = '';
        $available = true;
        $remaining = 6;

        $valid_starts = slotDurationAlignedStartMinutes('Pet Hotel', $specific_service, $opening, $closing, $breakStart, $breakEnd);
        if ($start === null || !in_array($start, $valid_starts, true)) {
            $available = false;
            $reason = 'Please select a valid drop-off time in the available schedule.';
        } elseif ($end === null || $end <= $start) {
            $available = false;
            $reason = 'Expected pickup must be later than drop-off and use a half-hour time.';
        } elseif ($end > $closing) {
            $available = false;
            $reason = 'Pet Daycare pickup must be no later than 6:00 PM.';
        } elseif ($start >= $breakStart && $start < $breakEnd) {
            $available = false;
            $reason = 'New Pet Daycare bookings cannot start from 12:00 PM to 1:00 PM.';
        } elseif ($date < $today || ($date === $today && $start <= $current_minutes)) {
            $available = false;
            $reason = 'The selected drop-off time has already passed.';
        }

        if ($available) {
            $stmt_daycare = $pdo->prepare("
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
            $stmt_daycare->execute([
                ':appointment_date_exact' => $date,
                ':appointment_date_before' => $date,
                ':appointment_date_active' => $date,
            ]);
            $day_rows = $stmt_daycare->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $next_day = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
            $manual_adjustments = slotLoadManualAdjustments($pdo, $date, $next_day);
            $total_bookings = count($day_rows) + slotManualOccupiedUnitsForDate($manual_adjustments, $date);
            $hotel_bookings = slotManualOccupiedUnitsForDate($manual_adjustments, $date, 'Pet Hotel');
            $hotel_intervals = [];

            foreach ($day_rows as $row) {
                $stored_service = (string)($row['service'] ?? '');
                $stored_category = slotCategoryForService($stored_service);
                $row_date = substr((string)($row['appointment_date'] ?? ''), 0, 10);
                if ($row_date === '') continue;
                $row_is_boarding = ($stored_category === 'Pet Hotel' && slotIsHotelBoarding($stored_service));

                if ($row_is_boarding) {
                    $row_checkout = substr((string)($row['checkout_date'] ?? ''), 0, 10);
                    if ($row_checkout === '') $row_checkout = (new DateTimeImmutable($row_date))->modify('+1 day')->format('Y-m-d');
                    $active_on_date = ($row_date <= $date && $row_checkout > $date);
                } else {
                    $active_on_date = ($row_date === $date);
                }
                if (!$active_on_date) continue;

                if ($stored_category === 'Pet Hotel') {
                    $hotel_bookings++;
                    if ($row_is_boarding) {
                        $hotel_intervals[] = ['start' => 0, 'end' => 1440, 'service' => $stored_service];
                    } else {
                        $row_start = slotTimeToMinutes((string)($row['appointment_time'] ?? ''));
                        if ($row_start === null) continue;
                        $stored_pickup = slotIsHotelDaycare($stored_service)
                            ? slotPickupTimeToMinutes((string)($row['pickup_time'] ?? ''))
                            : null;
                        $row_end = ($stored_pickup !== null && $stored_pickup > $row_start)
                            ? $stored_pickup
                            : ($row_start + slotDurationMinutes('Pet Hotel', $stored_service));
                        $hotel_intervals[] = ['start' => $row_start, 'end' => $row_end, 'service' => $stored_service];
                    }
                }
            }
            $hotel_intervals = array_merge(
                $hotel_intervals,
                slotManualIntervalsForDate($manual_adjustments, $date, 'Pet Hotel', $specific_service, $rules['Pet Hotel']['simultaneous_limit'])
            );

            if ($total_bookings >= $global_daily_limit) {
                $available = false;
                $reason = 'Overall daily capacity of 20 pets has been reached.';
            } elseif ($hotel_bookings >= $rules['Pet Hotel']['daily_limit']) {
                $available = false;
                $reason = 'Pet Hotel daily capacity has been reached.';
            } elseif (slotWouldExceedCapacity($start, $end, $hotel_intervals, 'Pet Hotel', $rules['Pet Hotel']['simultaneous_limit'], $breakStart, $breakEnd)) {
                $available = false;
                $reason = 'No Pet Daycare room is available for the entire selected interval.';
            } else {
                $remaining = slotMinimumRemaining($start, $end, $hotel_intervals, 'Pet Hotel', $rules['Pet Hotel']['simultaneous_limit'], $breakStart, $breakEnd);
                // Report rooms available concurrently throughout this exact interval.
                // The daily/global limits are already enforced above; subtracting all
                // same-day bookings here incorrectly makes a later non-overlapping
                // Daycare interval appear to have fewer rooms than it actually has.
                $remaining = max(0, $remaining);
            }
        }

        $response['mode'] = 'daycare_interval';
        $response['date'] = $date;
        $response['category'] = $category;
        $response['specific_service'] = $specific_service;
        $response['dropoff_time'] = $dropoff_time;
        $response['pickup_time'] = $pickup_time;
        $response['duration_minutes'] = ($start !== null && $end !== null && $end > $start) ? ($end - $start) : 0;
        $response['duration_display'] = ($start !== null && $end !== null && $end > $start)
            ? (intdiv($end - $start, 60) . ' hour' . (intdiv($end - $start, 60) === 1 ? '' : 's') . (($end - $start) % 60 ? ' ' . (($end - $start) % 60) . ' min' : ''))
            : 'selected interval';
        $response['available'] = $available;
        $response['remaining_slots'] = $available ? $remaining : 0;
        $response['reason'] = $available ? '' : $reason;
        $response['is_full'] = !$available;
        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    // Overnight Boarding is a date-range reservation, not a 30-minute appointment.
    // The checkout date is exclusive for overnight room occupancy because the client
    // specified checkout at 6:00 AM and normal check-in at 9:00 AM.
    if ($category === 'Pet Hotel' && slotIsHotelBoarding($specific_service)) {
        if ($checkout_date === '') {
            http_response_code(400);
            echo json_encode(['is_full' => false, 'booked_times' => [], 'error' => 'A check-out date is required for Pet Boarding.']);
            exit;
        }

        $checkout_object = DateTimeImmutable::createFromFormat('!Y-m-d', $checkout_date);
        $checkout_errors = DateTimeImmutable::getLastErrors();
        if (
            $checkout_object === false ||
            $checkout_object->format('Y-m-d') !== $checkout_date ||
            ($checkout_errors !== false && ($checkout_errors['warning_count'] > 0 || $checkout_errors['error_count'] > 0))
        ) {
            http_response_code(400);
            echo json_encode(['is_full' => false, 'booked_times' => [], 'error' => 'Invalid check-out date']);
            exit;
        }
        if ($checkout_date <= $date) {
            http_response_code(400);
            echo json_encode(['is_full' => false, 'booked_times' => [], 'error' => 'Check-out date must be at least one day after check-in.']);
            exit;
        }

        $stay_nights = (int)$date_object->diff($checkout_object)->days;
        $opening_minutes = 9 * 60;
        $closing_minutes = 18 * 60;
        $break_start = 12 * 60;
        $break_end = 13 * 60;
        $room_capacity = 6;
        $global_limit = 20;
        $today_now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
        $today_string = $today_now->format('Y-m-d');
        $current_minutes = ((int)$today_now->format('H') * 60) + (int)$today_now->format('i');

        $response['mode'] = 'boarding_range';
        $response['date'] = $date;
        $response['checkout_date'] = $checkout_date;
        $response['stay_nights'] = $stay_nights;
        $response['category'] = $category;
        $response['specific_service'] = $specific_service;
        $response['duration_minutes'] = 0;
        $response['duration_display'] = $stay_nights . ' night' . ($stay_nights === 1 ? '' : 's') . '; check-in 9:00 AM, check-out 6:00 AM on the check-out date';
        $response['daily_limit'] = 6;
        $response['global_daily_limit'] = $global_limit;
        $response['simultaneous_capacity'] = $room_capacity;
        $response['slots'] = [];
        $response['available_slots'] = 0;
        $available = true;
        $reason = '';
        $remaining_min = $room_capacity;

        if ($date < $today_string) {
            $available = false;
            $reason = 'Check-in date has passed';
        } elseif ($date === $today_string && $current_minutes >= 9 * 60) {
            $available = false;
            $reason = 'The 9:00 AM check-in time has passed today';
        }

        if ($available) {
            // Include bookings that start during the stay and boarders that checked in earlier.
            $stmt_range = $pdo->prepare("
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
            $stmt_range->execute([
                ':checkout_date' => $checkout_date,
                ':checkin_date' => $date,
            ]);
            $range_rows = $stmt_range->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $manual_adjustments_range = slotLoadManualAdjustments($pdo, $date, $checkout_date);

            for ($stay_day = $date_object; $stay_day < $checkout_object; $stay_day = $stay_day->modify('+1 day')) {
                $day_string = $stay_day->format('Y-m-d');
                $day_total = slotManualOccupiedUnitsForDate($manual_adjustments_range, $day_string);
                $day_hotel_count = slotManualOccupiedUnitsForDate($manual_adjustments_range, $day_string, 'Pet Hotel');
                $hotel_intervals = [];

                foreach ($range_rows as $row) {
                    $stored_service = (string)($row['service'] ?? '');
                    $stored_category = slotCategoryForService($stored_service);
                    $row_date = substr((string)($row['appointment_date'] ?? ''), 0, 10);
                    if ($row_date === '') continue;
                    $row_is_boarding = ($stored_category === 'Pet Hotel' && slotIsHotelBoarding($stored_service));

                    if ($row_is_boarding) {
                        $row_checkout = substr((string)($row['checkout_date'] ?? ''), 0, 10);
                        if ($row_checkout === '') {
                            $row_checkout = (new DateTimeImmutable($row_date))->modify('+1 day')->format('Y-m-d');
                        }
                        $active_on_day = ($row_date <= $day_string && $row_checkout > $day_string);
                    } else {
                        $active_on_day = ($row_date === $day_string);
                    }
                    if (!$active_on_day) continue;

                    $day_total++;
                    if ($stored_category === 'Pet Hotel') {
                        $day_hotel_count++;
                        if ($row_is_boarding) {
                            // A boarded pet occupies its room for the full day before checkout.
                            $hotel_intervals[] = ['start' => 0, 'end' => 1440];
                        } else {
                            $row_start = slotTimeToMinutes((string)($row['appointment_time'] ?? ''));
                            if ($row_start !== null) {
                                $hotel_intervals[] = [
                                    'start' => $row_start,
                                    'end' => $row_start + slotDurationMinutes('Pet Hotel', $stored_service),
                                ];
                            }
                        }
                    }
                }
                $hotel_intervals = array_merge(
                    $hotel_intervals,
                    slotManualIntervalsForDate($manual_adjustments_range, $day_string, 'Pet Hotel', $specific_service, $room_capacity)
                );

                if ($day_total >= $global_limit) {
                    $available = false;
                    $reason = 'Overall daily capacity is reached on ' . $day_string;
                    break;
                }
                if ($day_hotel_count >= 6) {
                    $available = false;
                    $reason = 'Pet Hotel daily capacity is reached on ' . $day_string;
                    break;
                }

                $day_remaining = slotMinimumRemaining(0, 1440, $hotel_intervals, 'Pet Hotel', $room_capacity, $break_start, $break_end);
                $day_remaining = min($day_remaining, 6 - $day_hotel_count, $global_limit - $day_total);
                $remaining_min = min($remaining_min, max(0, $day_remaining));
                if (slotWouldExceedCapacity(0, 1440, $hotel_intervals, 'Pet Hotel', $room_capacity, $break_start, $break_end)) {
                    $available = false;
                    $reason = 'No room is available for the full stay; capacity is reached on ' . $day_string;
                    break;
                }
            }
        }

        if ($available && $remaining_min > 0) {
            $response['available_slots'] = 1;
        } else {
            $available = false;
            if ($reason === '') $reason = 'No room is available for the entire stay';
            $response['booked_times'][] = '09:00:00';
            $response['full_reason'] = $reason;
        }
        $response['is_full'] = !$available;
        $response['slots'][] = [
            'time' => '09:00:00',
            'label' => '9:00 AM',
            'available' => $available,
            'remaining_slots' => $available ? $remaining_min : 0,
            'capacity' => $room_capacity,
            'duration_minutes' => 0,
            'reason' => $available ? '' : $reason,
        ];
        $response['remaining_rooms_for_full_stay'] = $available ? $remaining_min : 0;
        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    $duration_minutes = slotDurationMinutes($category, $specific_service, true);
    $daily_limit = $rules[$category]['daily_limit'];
    $simultaneous_limit = $rules[$category]['simultaneous_limit'];

    // Load active appointments for the selected date. Only read operations occur here.
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
        ':appointment_date_exact' => $date,
        ':appointment_date_before' => $date,
        ':appointment_date_active' => $date,
    ]);
    $all_rows = $stmt_existing->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $next_day = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
    $manual_adjustments = slotLoadManualAdjustments($pdo, $date, $next_day);

    $total_daily_bookings = count($all_rows) + slotManualOccupiedUnitsForDate($manual_adjustments, $date);
    $category_rows = [];
    foreach ($all_rows as $row) {
        $row_category = slotCategoryForService((string)($row['service'] ?? ''));
        if ($row_category === $category) $category_rows[] = $row;
    }
    $category_daily_bookings = count($category_rows)
        + slotManualOccupiedUnitsForDate($manual_adjustments, $date, $category);

    $response['mode'] = 'capacity_aware';
    $response['date'] = $date;
    $response['category'] = $category;
    $response['specific_service'] = $specific_service;
    $response['duration_minutes'] = $duration_minutes;
    if ($category === 'Pet Hotel' && slotIsHotelBoarding($specific_service)) {
        $response['duration_display'] = '30-minute check-in; room capacity is checked for the selected date only. For multi-night stays, contact the clinic to confirm availability.';
    } elseif ($category === 'Pet Hotel' && slotIsHotelDaycare($specific_service)) {
        $response['duration_display'] = 'variable; choose drop-off and expected pickup times';
    } else {
        $response['duration_display'] = $duration_minutes . ' minutes';
    }
    $response['daily_limit'] = $daily_limit;
    $response['global_daily_limit'] = $global_daily_limit;
    $response['simultaneous_capacity'] = $simultaneous_limit;
    $response['total_daily_bookings'] = $total_daily_bookings;
    $response['category_daily_bookings'] = $category_daily_bookings;
    $response['daily_capacity_reached'] = ($total_daily_bookings >= $global_daily_limit || $category_daily_bookings >= $daily_limit);
    $response['slots'] = [];
    $response['available_slots'] = 0;

    // Existing appointments for the selected category with estimated occupied intervals.
    // Overnight stays that overlap this date are included as full-day room occupancy.
    $existing_intervals = [];
    foreach ($category_rows as $row) {
        $stored_service = (string)($row['service'] ?? '');
        $row_date = substr((string)($row['appointment_date'] ?? ''), 0, 10);
        if ($category === 'Pet Hotel' && slotIsHotelBoarding($stored_service)) {
            $row_checkout = substr((string)($row['checkout_date'] ?? ''), 0, 10);
            if ($row_checkout === '') {
                $row_checkout = (new DateTimeImmutable($row_date))->modify('+1 day')->format('Y-m-d');
            }
            if ($row_date <= $date && $row_checkout > $date) {
                $existing_intervals[] = ['start' => 0, 'end' => 1440, 'service' => $stored_service];
            }
            continue;
        }
        if ($row_date !== $date) continue;
        $start = slotTimeToMinutes((string)($row['appointment_time'] ?? ''));
        if ($start === null) continue;
        $interval_start = $start;
        $stored_pickup = ($category === 'Pet Hotel' && slotIsHotelDaycare($stored_service))
            ? slotPickupTimeToMinutes((string)($row['pickup_time'] ?? ''))
            : null;
        $interval_end = ($stored_pickup !== null && $stored_pickup > $start)
            ? $stored_pickup
            : ($start + slotDurationMinutes($category, $stored_service));
        $existing_intervals[] = ['start' => $interval_start, 'end' => $interval_end, 'service' => $stored_service];
    }
    $existing_intervals = array_merge(
        $existing_intervals,
        slotManualIntervalsForDate($manual_adjustments, $date, $category, $specific_service, $simultaneous_limit)
    );

    // Generate online start times based on service duration, not a universal
    // 30-minute grid. Vet remains 30 minutes; Grooming/Daycare align to their duration.
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    $today = $now->format('Y-m-d');
    $current_minutes = ((int)$now->format('H') * 60) + (int)$now->format('i');

    foreach (slotDurationAlignedStartMinutes($category, $specific_service, $opening_minutes, $closing_minutes, $break_start, $break_end) as $start) {
        $end = $start + $duration_minutes;
        $time_value = sprintf('%02d:%02d:00', intdiv($start, 60), $start % 60);
        $end_value = sprintf('%02d:%02d:00', intdiv($end, 60), $end % 60);
        $label = date('g:i A', strtotime($time_value)) . ' – ' . date('g:i A', strtotime($end_value));
        $available = true;
        $reason = '';

        // For overnight boarding, check room capacity across the full selected day,
        // while the selected time remains a short check-in appointment.
        $capacity_start = $start;
        $capacity_end = $end;
        if ($category === 'Pet Hotel' && slotIsHotelBoarding($specific_service)) {
            $capacity_start = $opening_minutes;
            $capacity_end = $closing_minutes;
        }

        if ($end > $closing_minutes) {
            $available = false;
            $reason = 'Outside operating hours';
        } elseif ($vet_unavailable_day) {
            $available = false;
            $reason = 'Veterinarian unavailable on Wednesdays and Saturdays';
        } elseif ($start >= $break_start && $start < $break_end) {
            $available = false;
            $reason = 'Staff break (12:00 PM–1:00 PM)';
        } elseif ($date < $today || ($date === $today && $start <= $current_minutes)) {
            $available = false;
            $reason = 'Time has passed';
        } elseif ($total_daily_bookings >= $global_daily_limit) {
            $available = false;
            $reason = 'Overall daily capacity reached';
        } elseif ($category_daily_bookings >= $daily_limit) {
            $available = false;
            $reason = 'Service daily capacity reached';
        } else {
            // New starts during 12:00-13:00 are blocked above. Services started
            // before lunch may continue, subject to the reduced grooming capacity.
            if (slotWouldExceedCapacity(
                $capacity_start,
                $capacity_end,
                $existing_intervals,
                $category,
                $simultaneous_limit,
                $break_start,
                $break_end
            )) {
                $available = false;
                $reason = ($category === 'Pet Hotel')
                    ? 'No pet hotel room is available during this period'
                    : (($category === 'Vet Services')
                        ? 'Both veterinary staff capacity units are occupied or unavailable during this time'
                        : 'Staff capacity reached during this time');
            }
        }

        if (!$available) $response['booked_times'][] = $time_value;
        else $response['available_slots']++;

        $simultaneous_remaining = slotMinimumRemaining(
            $capacity_start,
            $capacity_end,
            $existing_intervals,
            $category,
            $simultaneous_limit,
            $break_start,
            $break_end
        );
        $category_daily_remaining = max(0, $daily_limit - $category_daily_bookings);
        $global_daily_remaining = max(0, $global_daily_limit - $total_daily_bookings);
        // The UI should show the tightest remaining limit, not just staff/room capacity.
        $remaining = min($simultaneous_remaining, $category_daily_remaining, $global_daily_remaining);

        $response['slots'][] = [
            'time' => $time_value,
            'label' => $label,
            'available' => $available,
            'remaining_slots' => $available ? $remaining : 0,
            'capacity' => $simultaneous_limit,
            'duration_minutes' => $duration_minutes,
            'reason' => $reason,
        ];
    }

    $response['is_full'] = ($response['available_slots'] === 0);
    if ($vet_unavailable_day) {
        $response['full_reason'] = 'The veterinarian is unavailable on Wednesdays and Saturdays.';
    } elseif ($total_daily_bookings >= $global_daily_limit) {
        $response['full_reason'] = 'Overall daily capacity of 20 pets has been reached.';
    } elseif ($category_daily_bookings >= $daily_limit) {
        $response['full_reason'] = $category . ' daily capacity has been reached.';
    } elseif ($response['available_slots'] === 0) {
        $response['full_reason'] = 'No available time slots remain for the selected service duration.';
    }

    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (PDOException $e) {
    error_log('check_slots.php database error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['is_full' => false, 'booked_times' => [], 'error' => 'Unable to check availability right now. Please try again.']);
} catch (Throwable $e) {
    error_log('check_slots.php unexpected error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['is_full' => false, 'booked_times' => [], 'error' => 'Unable to check availability right now. Please try again.']);
}

exit;
