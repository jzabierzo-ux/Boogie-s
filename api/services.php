<?php

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

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
| Optional category filter
|--------------------------------------------------------------------------
|
| Examples:
|   /api/services.php?category=Grooming
|   /api/services.php?category=Vet%20Services
|   /api/services.php?category=Pet%20Hotel
|
*/
$category = trim($_GET['category'] ?? '');

if ($category !== '') {

    $stmt = $conn->prepare("
        SELECT id, service_name, category, price
        FROM services_pricelist
        WHERE category = ?
          AND is_available = 1
        ORDER BY service_name ASC, price ASC
    ");

    if (!$stmt) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to prepare services query.'
        ]);
        exit;
    }

    $stmt->bind_param('s', $category);
    $stmt->execute();

    $result = $stmt->get_result();

} else {

    $stmt = $conn->prepare("
        SELECT id, service_name, category, price
        FROM services_pricelist
        WHERE is_available = 1
        ORDER BY category ASC, service_name ASC, price ASC
    ");

    if (!$stmt) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to prepare services query.'
        ]);
        exit;
    }

    $stmt->execute();
    $result = $stmt->get_result();
}

$services = [];

while ($row = $result->fetch_assoc()) {
    $services[] = [
        'id' => (int) $row['id'],
        'service_name' => $row['service_name'],
        'category' => $row['category'],
        'price' => (float) $row['price']
    ];
}

$stmt->close();

echo json_encode([
    'success' => true,
    'count' => count($services),
    'data' => $services
], JSON_PRETTY_PRINT);

?>
