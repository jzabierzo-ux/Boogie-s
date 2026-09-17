<?php

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../db_connect.php';

if (!isset($conn) || !$conn) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed.'
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| GET /api/reviews.php
|--------------------------------------------------------------------------
| Public endpoint: returns review statistics and recent reviews.
*/
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $stats_query = $conn->query("
        SELECT
            COUNT(*) AS total_reviews,
            COALESCE(AVG(rating), 0) AS average_rating
        FROM reviews
    ");

    if (!$stats_query) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to read review statistics.'
        ]);
        exit;
    }

    $stats = $stats_query->fetch_assoc();

    /*
     * IMPORTANT:
     * The existing users table uses full_name.
     * Do not reference u.name or u.username because those
     * columns are not present in the current database.
     */
    $stmt = $conn->prepare("
        SELECT
            r.id,
            r.rating,
            r.comment,
            r.review_date,
            COALESCE(NULLIF(u.full_name, ''), 'Valued Client') AS reviewer_name,
            COALESCE(NULLIF(a.service, ''), 'Pet Care') AS service_type
        FROM reviews r
        LEFT JOIN users u ON u.id = r.user_id
        LEFT JOIN appointments a ON a.id = r.appointment_id
        ORDER BY r.review_date DESC, r.id DESC
        LIMIT 20
    ");

    if (!$stmt) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to prepare reviews query.'
        ]);
        exit;
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $reviews = [];

    while ($row = $result->fetch_assoc()) {
        $reviews[] = [
            'id' => (int) $row['id'],
            'rating' => max(1, min(5, (int) $row['rating'])),
            'comment' => $row['comment'] ?? '',
            'review_date' => $row['review_date'] ?? '',
            'reviewer_name' => $row['reviewer_name'] ?? 'Valued Client',
            'service_type' => $row['service_type'] ?? 'Pet Care'
        ];
    }

    $stmt->close();

    echo json_encode([
        'success' => true,
        'total_reviews' => (int) ($stats['total_reviews'] ?? 0),
        'average_rating' => number_format((float) ($stats['average_rating'] ?? 0), 1),
        'count' => count($reviews),
        'data' => $reviews
    ], JSON_PRETTY_PRINT);

    exit;
}

/*
|--------------------------------------------------------------------------
| POST /api/reviews.php
|--------------------------------------------------------------------------
| Authenticated customer can review a completed appointment.
|
| JSON body:
| {
|   "appointment_id": 123,
|   "rating": 5,
|   "comment": "Great service!"
| }
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Use GET or POST.'
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| Read Bearer token
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

$token_hash = hash('sha256', $plain_token);

$stmt_auth = $conn->prepare("
    SELECT user_id
    FROM api_tokens
    WHERE token_hash = ?
      AND expires_at > NOW()
    LIMIT 1
");

if (!$stmt_auth) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'API token storage is not configured yet.'
    ]);
    exit;
}

$stmt_auth->bind_param('s', $token_hash);
$stmt_auth->execute();

$auth_result = $stmt_auth->get_result();
$auth_row = $auth_result ? $auth_result->fetch_assoc() : null;
$stmt_auth->close();

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
| Read JSON body
|--------------------------------------------------------------------------
*/
$input = json_decode(file_get_contents('php://input'), true);

$appointment_id = (int) ($input['appointment_id'] ?? 0);
$rating = (int) ($input['rating'] ?? 0);
$comment = trim((string) ($input['comment'] ?? ''));

if ($appointment_id <= 0 || $rating < 1 || $rating > 5) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'appointment_id and a rating from 1 to 5 are required.'
    ]);
    exit;
}

if (strlen($comment) > 1000) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Comment must not exceed 1000 characters.'
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| Appointment must belong to this customer and be completed.
|--------------------------------------------------------------------------
*/
$stmt_booking = $conn->prepare("
    SELECT id, service
    FROM appointments
    WHERE id = ?
      AND user_id = ?
      AND booking_status = 'Completed'
    LIMIT 1
");

if (!$stmt_booking) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to validate appointment.'
    ]);
    exit;
}

$stmt_booking->bind_param('ii', $appointment_id, $user_id);
$stmt_booking->execute();

$booking_result = $stmt_booking->get_result();
$booking = $booking_result ? $booking_result->fetch_assoc() : null;
$stmt_booking->close();

if (!$booking) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Only your completed appointments can be reviewed.'
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| Prevent duplicate reviews for the same appointment.
|--------------------------------------------------------------------------
*/
$stmt_existing = $conn->prepare("
    SELECT id
    FROM reviews
    WHERE user_id = ?
      AND appointment_id = ?
    LIMIT 1
");

$stmt_existing->bind_param('ii', $user_id, $appointment_id);
$stmt_existing->execute();

$existing_result = $stmt_existing->get_result();
$existing_review = $existing_result ? $existing_result->fetch_assoc() : null;
$stmt_existing->close();

if ($existing_review) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'message' => 'This appointment has already been reviewed.'
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| Insert review
|--------------------------------------------------------------------------
*/
$stmt_insert = $conn->prepare("
    INSERT INTO reviews
        (user_id, appointment_id, rating, comment, review_date)
    VALUES
        (?, ?, ?, ?, NOW())
");

if (!$stmt_insert) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare review insert.'
    ]);
    exit;
}

$stmt_insert->bind_param(
    'iiis',
    $user_id,
    $appointment_id,
    $rating,
    $comment
);

if (!$stmt_insert->execute()) {
    $stmt_insert->close();

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to save review.'
    ]);
    exit;
}

$review_id = $stmt_insert->insert_id;
$stmt_insert->close();

echo json_encode([
    'success' => true,
    'message' => 'Review submitted successfully.',
    'data' => [
        'id' => (int) $review_id,
        'appointment_id' => $appointment_id,
        'rating' => $rating,
        'comment' => $comment
    ]
], JSON_PRETTY_PRINT);

?>
