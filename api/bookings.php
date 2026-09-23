<?php

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Use GET.'
    ]);
    exit;
}

require_once '../db_supabase.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed.'
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| Read Authorization header
|--------------------------------------------------------------------------
*/
$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if (empty($authorization) && function_exists('getallheaders')) {
    $headers = getallheaders();

    if (isset($headers['Authorization'])) {
        $authorization = $headers['Authorization'];
    } elseif (isset($headers['authorization'])) {
        $authorization = $headers['authorization'];
    }
}

if (empty($authorization) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $authorization = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
}

if (!preg_match('/Bearer\s+(.+)/i', trim($authorization), $matches)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Missing Bearer token.'
    ]);
    exit;
}

$plain_token = trim($matches[1]);

if ($plain_token === '') {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid Bearer token.'
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| Validate API token
|--------------------------------------------------------------------------
*/
$token_hash = hash('sha256', $plain_token);

try {
    $stmt_auth = $pdo->prepare("
        SELECT user_id
        FROM api_tokens
        WHERE token_hash = :token_hash
          AND expires_at > CURRENT_TIMESTAMP
        LIMIT 1
    ");

    $stmt_auth->execute([':token_hash' => $token_hash]);
    $auth_row = $stmt_auth->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'API token storage is not configured yet.'
    ]);
    exit;
}

if (!$auth_row) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid or expired API token.'
    ]);
    exit;
}

$user_id = (int) $auth_row['user_id'];

/*
|--------------------------------------------------------------------------
| Optional single-booking request
|--------------------------------------------------------------------------
| /api/bookings.php?id=123
|--------------------------------------------------------------------------
*/
$booking_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($booking_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT
                a.id,
                a.pet_id,
                COALESCE(p.name, 'Unknown Pet') AS pet_name,
                a.service,
                a.appointment_date,
                a.appointment_time,
                a.service_fee,
                a.total_price,
                a.payment_method,
                a.payment_status,
                a.booking_status,
                a.remarks
            FROM appointments a
            LEFT JOIN pets p ON a.pet_id = p.id
            WHERE a.id = :booking_id
              AND a.user_id = :user_id
            LIMIT 1
        ");

        $stmt->execute([
            ':booking_id' => $booking_id,
            ':user_id' => $user_id
        ]);

        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to prepare booking query.'
        ]);
        exit;
    }

    if (!$booking) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Booking not found.'
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'id' => (int) $booking['id'],
            'pet_id' => (int) $booking['pet_id'],
            'pet_name' => $booking['pet_name'],
            'service' => $booking['service'],
            'appointment_date' => $booking['appointment_date'],
            'appointment_time' => $booking['appointment_time'],
            'service_fee' => (float) $booking['service_fee'],
            'total_price' => (float) $booking['total_price'],
            'payment_method' => $booking['payment_method'],
            'payment_status' => $booking['payment_status'],
            'booking_status' => $booking['booking_status'],
            'remarks' => $booking['remarks']
        ]
    ], JSON_PRETTY_PRINT);

    exit;
}

/*
|--------------------------------------------------------------------------
| Get all bookings belonging to authenticated customer
|--------------------------------------------------------------------------
*/
try {
    $stmt = $pdo->prepare("
        SELECT
            a.id,
            a.pet_id,
            COALESCE(p.name, 'Unknown Pet') AS pet_name,
            a.service,
            a.appointment_date,
            a.appointment_time,
            a.service_fee,
            a.total_price,
            a.payment_method,
            a.payment_status,
            a.booking_status,
            a.remarks
        FROM appointments a
        LEFT JOIN pets p ON a.pet_id = p.id
        WHERE a.user_id = :user_id
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
    ");

    $stmt->execute([':user_id' => $user_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare bookings query.'
    ]);
    exit;
}

$bookings = [];

foreach ($rows as $row) {
    $bookings[] = [
        'id' => (int) $row['id'],
        'pet_id' => (int) $row['pet_id'],
        'pet_name' => $row['pet_name'],
        'service' => $row['service'],
        'appointment_date' => $row['appointment_date'],
        'appointment_time' => $row['appointment_time'],
        'service_fee' => (float) $row['service_fee'],
        'total_price' => (float) $row['total_price'],
        'payment_method' => $row['payment_method'],
        'payment_status' => $row['payment_status'],
        'booking_status' => $row['booking_status'],
        'remarks' => $row['remarks']
    ];
}

echo json_encode([
    'success' => true,
    'count' => count($bookings),
    'data' => $bookings
], JSON_PRETTY_PRINT);

?>
