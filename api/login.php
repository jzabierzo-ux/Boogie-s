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

require_once '../db_supabase.php';

if (!isset($pdo) || !$pdo) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed.'
    ]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    $input = [];
}

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

try {
    $stmt = $pdo->prepare("
        SELECT id, full_name, email, password, role, profile_image
        FROM users
        WHERE email = :email
          AND role = 'customer'
        LIMIT 1
    ");

    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

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

    $stmt_token = $pdo->prepare("
        INSERT INTO api_tokens (user_id, token_hash, expires_at, created_at)
        VALUES (:user_id, :token_hash, :expires_at, CURRENT_TIMESTAMP)
    ");

    $stmt_token->execute([
        ':user_id' => (int)$user['id'],
        ':token_hash' => $token_hash,
        ':expires_at' => $expires_at
    ]);

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
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to process login or create API token.'
    ]);
    exit;
}

?>
