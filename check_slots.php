<?php
// check_slots.php
require_once 'db_supabase.php';

header('Content-Type: application/json; charset=UTF-8');

// Added trim() to remove any accidental spaces from the request
$date = isset($_GET['date']) ? trim($_GET['date']) : '';
$category = isset($_GET['category']) ? trim($_GET['category']) : '';

if (empty($date) || empty($category)) {
    echo json_encode(["error" => "Missing parameters"]);
    exit;
}

$response = [
    "is_full" => false,
    "booked_times" => []
];

// 1. Check Daily Limits
// Dahil 1 pet per slot na lang tayo at may 8 available timeslots (10AM to 5PM),
// Ang maximum daily limit na lang ay 8 per service category.
$daily_limit = 8;

try {
    // UPDATE: Hindi na bibilangin ang 'Cancelled' at 'No-Show' sa daily limit.
    // PostgreSQL/PDO version of the original LIKE CONCAT(?, '%')
    $stmt_count = $pdo->prepare("
        SELECT COUNT(*) AS total_bookings
        FROM appointments
        WHERE appointment_date = :appointment_date
          AND service LIKE :service_prefix
          AND booking_status NOT IN ('Cancelled', 'No-Show')
    ");

    $stmt_count->execute([
        ':appointment_date' => $date,
        ':service_prefix' => $category . '%'
    ]);

    $row_count = $stmt_count->fetch(PDO::FETCH_ASSOC);

    if ((int)($row_count['total_bookings'] ?? 0) >= $daily_limit) {
        $response['is_full'] = true;
    }

    // 2. Check Taken Time Slots per category (Strictly 1 per slot)
    // UPDATE: Hindi rin isasama ang 'No-Show' at 'Cancelled' dito para ma-recover ang slot.
    $stmt_slots = $pdo->prepare("
        SELECT appointment_time, COUNT(*) AS slot_count
        FROM appointments
        WHERE appointment_date = :appointment_date
          AND service LIKE :service_prefix
          AND booking_status NOT IN ('Cancelled', 'No-Show')
        GROUP BY appointment_time
        ORDER BY appointment_time
    ");

    $stmt_slots->execute([
        ':appointment_date' => $date,
        ':service_prefix' => $category . '%'
    ]);

    $result_slots = $stmt_slots->fetchAll(PDO::FETCH_ASSOC);

    foreach ($result_slots as $row) {
        // DITO ANG MAGIC: Kung may 1 na booking, "Taken" na agad ang oras na ito!
        if ((int)($row['slot_count'] ?? 0) >= 1) {
            $response['booked_times'][] = $row['appointment_time'];
        }
    }

    echo json_encode($response);
} catch (PDOException $e) {
    error_log("check_slots.php database error: " . $e->getMessage());

    echo json_encode([
        "error" => "Database error",
        "is_full" => false,
        "booked_times" => []
    ]);
}

exit;
?>