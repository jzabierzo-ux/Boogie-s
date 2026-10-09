<?php
// check_slots.php
require_once __DIR__ . '/db_supabase.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$response = [
    'is_full' => false,
    'booked_times' => [],
];

$date_input = $_GET['date'] ?? '';
$category_input = $_GET['category'] ?? '';
$date = is_string($date_input) ? trim($date_input) : '';
$category = is_string($category_input) ? trim($category_input) : '';

if ($date === '' || $category === '') {
    http_response_code(400);
    echo json_encode($response + ['error' => 'Missing or invalid parameters']);
    exit;
}

// Accept only a real date in ISO YYYY-MM-DD format.
$date_object = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
$date_errors = DateTimeImmutable::getLastErrors();
if (
    $date_object === false ||
    $date_object->format('Y-m-d') !== $date ||
    ($date_errors !== false && ($date_errors['warning_count'] > 0 || $date_errors['error_count'] > 0))
) {
    http_response_code(400);
    echo json_encode($response + ['error' => 'Invalid date format']);
    exit;
}

// These are the exact top-level categories used by the booking form.
$allowed_categories = ['Grooming', 'Vet Services', 'Pet Hotel'];
if (!in_array($category, $allowed_categories, true)) {
    http_response_code(400);
    echo json_encode($response + ['error' => 'Invalid service category']);
    exit;
}

// Eight appointment times are offered from 10 AM through 5 PM, one pet per slot.
$daily_limit = 8;
$service_prefix = $category . '%';

// Cancelled and no-show appointments do not hold capacity or a time slot.
// COALESCE also treats a missing status as an active booking instead of freeing capacity.
$active_status_sql = "LOWER(BTRIM(COALESCE(booking_status, ''))) NOT IN ('cancelled', 'canceled', 'no-show', 'no show', 'noshow')";

try {
    $stmt_count = $pdo->prepare("
        SELECT COUNT(*) AS total_bookings
        FROM appointments
        WHERE appointment_date = :appointment_date
          AND service LIKE :service_prefix
          AND {$active_status_sql}
    ");
    $stmt_count->execute([
        ':appointment_date' => $date,
        ':service_prefix' => $service_prefix,
    ]);
    $row_count = $stmt_count->fetch(PDO::FETCH_ASSOC) ?: [];

    if ((int)($row_count['total_bookings'] ?? 0) >= $daily_limit) {
        $response['is_full'] = true;
    } else {
        $stmt_slots = $pdo->prepare("
            SELECT appointment_time, COUNT(*) AS slot_count
            FROM appointments
            WHERE appointment_date = :appointment_date
              AND service LIKE :service_prefix
              AND {$active_status_sql}
            GROUP BY appointment_time
            ORDER BY appointment_time
        ");
        $stmt_slots->execute([
            ':appointment_date' => $date,
            ':service_prefix' => $service_prefix,
        ]);

        foreach ($stmt_slots->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((int)($row['slot_count'] ?? 0) >= 1) {
                $time = (string)($row['appointment_time'] ?? '');
                if ($time !== '') {
                    $response['booked_times'][] = $time;
                }
            }
        }
    }

    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (PDOException $e) {
    error_log('check_slots.php database error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode($response + ['error' => 'Database error']);
}

exit;
