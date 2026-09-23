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

try {
    if ($category !== '') {

        $stmt = $pdo->prepare("
            SELECT id, service_name, category, price
            FROM services_pricelist
            WHERE category = :category
              AND is_available = 1
            ORDER BY service_name ASC, price ASC
        ");

        $stmt->execute([
            ':category' => $category
        ]);

    } else {

        $stmt = $pdo->prepare("
            SELECT id, service_name, category, price
            FROM services_pricelist
            WHERE is_available = 1
            ORDER BY category ASC, service_name ASC, price ASC
        ");

        $stmt->execute();
    }

    $services = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $services[] = [
            'id' => (int) $row['id'],
            'service_name' => $row['service_name'],
            'category' => $row['category'],
            'price' => (float) $row['price']
        ];
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch services.'
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'count' => count($services),
    'data' => $services
], JSON_PRETTY_PRINT);

?>
