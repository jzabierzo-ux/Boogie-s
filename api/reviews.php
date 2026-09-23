<?php

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../db_supabase.php';

if (!isset($pdo) || !$pdo) {
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

    try {
        $stats_query = $pdo->query("
            SELECT
                COUNT(*) AS total_reviews,
                COALESCE(AVG(rating), 0) AS average_rating
            FROM reviews
        ");

        $stats = $stats_query->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to read review statistics.'
        ]);
        exit;
    }

    /*
     * IMPORTANT:
     * The existing users table uses full_name.
     * Do not reference u.name or u.username because those
     * columns are not present in the current database.
     */
    try {
        $stmt = $pdo->prepare("
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

        $stmt->execute();

        $reviews = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $reviews[] = [
                'id' => (int) $row['id'],
                'rating' => max(1, min(5, (int) $row['rating'])),
                'comment' => $row['comment'] ?? '',
                'review_date' => $row['review_date'] ?? '',
                'reviewer_name' => $row['reviewer_name'] ?? 'Valued Client',
                'service_type' => $row['service_type'] ?? 'Pet Care'
            ];
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to prepare reviews query.'
        ]);
        exit;
    }

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

try {
    $stmt_auth = $pdo->prepare("
        SELECT user_id
        FROM api_tokens
        WHERE token_hash = :token_hash
          AND expires_at > CURRENT_TIMESTAMP
        LIMIT 1
    ");

    $stmt_auth->execute([
        ':token_hash' => $token_hash
    ]);

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
| Read JSON body
|--------------------------------------------------------------------------
*/
$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    $input = [];
}

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
try {
    $stmt_booking = $pdo->prepare("
        SELECT id, service
        FROM appointments
        WHERE id = :appointment_id
          AND user_id = :user_id
          AND booking_status = 'Completed'
        LIMIT 1
    ");

    $stmt_booking->execute([
        ':appointment_id' => $appointment_id,
        ':user_id' => $user_id
    ]);

    $booking = $stmt_booking->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to validate appointment.'
    ]);
    exit;
}

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
try {
    $stmt_existing = $pdo->prepare("
        SELECT id
        FROM reviews
        WHERE user_id = :user_id
          AND appointment_id = :appointment_id
        LIMIT 1
    ");

    $stmt_existing->execute([
        ':user_id' => $user_id,
        ':appointment_id' => $appointment_id
    ]);

    $existing_review = $stmt_existing->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to check existing review.'
    ]);
    exit;
}

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
try {
    $stmt_insert = $pdo->prepare("
        INSERT INTO reviews
            (user_id, appointment_id, rating, comment, review_date)
        VALUES
            (:user_id, :appointment_id, :rating, :comment, CURRENT_TIMESTAMP)
        RETURNING id
    ");

    $stmt_insert->execute([
        ':user_id' => $user_id,
        ':appointment_id' => $appointment_id,
        ':rating' => $rating,
        ':comment' => $comment
    ]);

    $review_id = $stmt_insert->fetchColumn();
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to save review.'
    ]);
    exit;
}

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
