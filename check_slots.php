<?php
// check_slots.php
include 'db_connect.php';

header('Content-Type: application/json');

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

// UPDATE: Pinalitan ang condition. Hindi na bibilangin ang 'Cancelled' at 'No-Show' sa daily limit!
$stmt_count = $conn->prepare("
    SELECT COUNT(*) as total_bookings 
    FROM appointments 
    WHERE appointment_date = ? 
    AND service LIKE CONCAT(?, '%') 
    AND booking_status NOT IN ('Cancelled', 'No-Show')
");
$stmt_count->bind_param("ss", $date, $category);
$stmt_count->execute();
$row_count = $stmt_count->get_result()->fetch_assoc();

if ($row_count['total_bookings'] >= $daily_limit) {
    $response['is_full'] = true;
}
$stmt_count->close(); // ✅ BEST PRACTICE: Close the statement to free up resources

// 2. Check Taken Time Slots per category (Strictly 1 per slot)
// UPDATE: Hindi rin isasama ang 'No-Show' at 'Cancelled' dito para ma-recover ang slot.
$stmt_slots = $conn->prepare("
    SELECT appointment_time, COUNT(*) as slot_count 
    FROM appointments 
    WHERE appointment_date = ? 
    AND service LIKE CONCAT(?, '%') 
    AND booking_status NOT IN ('Cancelled', 'No-Show')
    GROUP BY appointment_time
");
$stmt_slots->bind_param("ss", $date, $category);
$stmt_slots->execute();
$result_slots = $stmt_slots->get_result();

while ($row = $result_slots->fetch_assoc()) {
    // DITO ANG MAGIC: Kung may 1 na booking, "Taken" na agad ang oras na ito!
    if ((int)$row['slot_count'] >= 1) {
        $response['booked_times'][] = $row['appointment_time'];
    }
}
$stmt_slots->close(); // ✅ BEST PRACTICE: Close the statement

echo json_encode($response);
?>