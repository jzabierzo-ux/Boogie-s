<?php

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Use POST.'
    ]);
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

$input = json_decode(file_get_contents('php://input'), true);

$email = trim($input['email'] ?? '');
$password = $input['password'] ?? '';

if ($email === '' || $password === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Email and password are required.'
    ]);
    exit;
}

$stmt = $conn->prepare("
    SELECT id, full_name, email, password, role, profile_image
    FROM users
    WHERE email = ?
      AND role = 'customer'
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare login query.'
    ]);
    exit;
}

$stmt->bind_param('s', $email);
$stmt->execute();

$result = $stmt->get_result();
$user = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$user || !password_verify($password, $user['password'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid email or password.'
    ]);
    exit;
}

$plain_token = bin2hex(random_bytes(32));
$token_hash = hash('sha256', $plain_token);
$expires_at = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60));

$stmt_token = $conn->prepare("
    INSERT INTO api_tokens (user_id, token_hash, expires_at, created_at)
    VALUES (?, ?, ?, NOW())
");

if (!$stmt_token) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'API token storage is not configured yet.',
        'hint' => 'Create the api_tokens table first.'
    ]);
    exit;
}

$stmt_token->bind_param('iss', $user['id'], $token_hash, $expires_at);

if (!$stmt_token->execute()) {
    $stmt_token->close();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to create API token.'
    ]);
    exit;
}

$stmt_token->close();

echo json_encode([
    'success' => true,
    'message' => 'Login successful.',
    'token_type' => 'Bearer',
    'expires_at' => $expires_at,
    'data' => [
        'user_id' => (int) $user['id'],
        'full_name' => $user['full_name'],
        'email' => $user['email'],
        'role' => $user['role'],
        'profile_image' => $user['profile_image']
    ],
    'access_token' => $plain_token
], JSON_PRETTY_PRINT);

?>