<?php

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
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

$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

// XAMPP/Apache may store the Authorization header differently.
if (empty($authorization) && function_exists('getallheaders')) {
    $headers = getallheaders();

    if (isset($headers['Authorization'])) {
        $authorization = $headers['Authorization'];
    } elseif (isset($headers['authorization'])) {
        $authorization = $headers['authorization'];
    }
}

// Additional Apache fallback.
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
        'message' => 'API token storage is not configured yet.',
        'hint' => 'Create the api_tokens table first.'
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

$pet_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($pet_id > 0) {
    $stmt = $conn->prepare("
        SELECT id, name, pet_type, breed, gender, age, weight
        FROM pets
        WHERE id = ?
          AND owner_id = ?
        LIMIT 1
    ");

    $stmt->bind_param('ii', $pet_id, $user_id);
    $stmt->execute();

    $result = $stmt->get_result();
    $pet = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$pet) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Pet not found.'
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'id' => (int) $pet['id'],
            'name' => $pet['name'],
            'pet_type' => $pet['pet_type'],
            'breed' => $pet['breed'],
            'gender' => $pet['gender'],
            'age' => $pet['age'],
            'weight' => $pet['weight']
        ]
    ], JSON_PRETTY_PRINT);

    exit;
}

$stmt = $conn->prepare("
    SELECT id, name, pet_type, breed, gender, age, weight
    FROM pets
    WHERE owner_id = ?
    ORDER BY name ASC
");

$stmt->bind_param('i', $user_id);
$stmt->execute();

$result = $stmt->get_result();

$pets = [];

while ($row = $result->fetch_assoc()) {
    $pets[] = [
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'pet_type' => $row['pet_type'],
        'breed' => $row['breed'],
        'gender' => $row['gender'],
        'age' => $row['age'],
        'weight' => $row['weight']
    ];
}

$stmt->close();

echo json_encode([
    'success' => true,
    'count' => count($pets),
    'data' => $pets
], JSON_PRETTY_PRINT);

?>