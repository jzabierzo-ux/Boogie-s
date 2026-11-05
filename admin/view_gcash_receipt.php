<?php
require_once __DIR__ . '/../shared_session_bootstrap.php';
require_once __DIR__ . '/../db_supabase.php';

date_default_timezone_set('Asia/Manila');
ini_set('display_errors', '0');

header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cross-Origin-Resource-Policy: same-origin');

/** Return a generic response without exposing storage paths or credentials. */
function gcashReceiptFail(int $statusCode, string $message): void
{
    http_response_code($statusCode);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $message;
    exit;
}

/** Check the actual Admin session flags used by admin/admin_login.php. */
$role = strtolower(trim((string)($_SESSION['role'] ?? '')));
if ($role === 'administrator') {
    $role = 'admin';
}
$adminAuthorized = (
    ($_SESSION['logged_in'] ?? false) === true
    && ($_SESSION['admin_logged_in'] ?? false) === true
    && $role === 'admin'
    && (int)($_SESSION['user_id'] ?? 0) > 0
);

if (!$adminAuthorized) {
    gcashReceiptFail(403, 'Access denied. Admin authentication is required.');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    gcashReceiptFail(405, 'Method not allowed.');
}

$rawBookingId = $_GET['booking_id'] ?? null;
if (!is_string($rawBookingId) && !is_int($rawBookingId)) {
    gcashReceiptFail(400, 'Invalid booking request.');
}
$bookingId = filter_var($rawBookingId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($bookingId === false) {
    gcashReceiptFail(400, 'Invalid booking request.');
}

try {
    $bookingStmt = $pdo->prepare('
        SELECT id, payment_method, gcash_receipt
        FROM appointments
        WHERE id = :booking_id
        LIMIT 1
    ');
    $bookingStmt->execute([':booking_id' => (int)$bookingId]);
    $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('GCash receipt viewer database lookup failed for booking #' . (int)$bookingId . ': ' . $e->getMessage());
    gcashReceiptFail(503, 'Receipt service is temporarily unavailable.');
}

if (!$booking || strtolower(trim((string)($booking['payment_method'] ?? ''))) !== 'gcash') {
    gcashReceiptFail(404, 'Receipt not found.');
}

$storedReceipt = trim((string)($booking['gcash_receipt'] ?? ''));
if ($storedReceipt === '' || preg_match('/[\x00-\x1F\x7F]/', $storedReceipt)) {
    gcashReceiptFail(404, 'Receipt not found.');
}

/** Validate image bytes and stream only genuine JPEG or PNG data. */
function gcashReceiptOutputImage(string $contents, string $sourceDescription = 'private storage'): void
{
    if ($contents === '' || strlen($contents) > 5 * 1024 * 1024) {
        gcashReceiptFail(404, 'Receipt not found.');
    }

    $imageInfo = @getimagesizefromstring($contents);
    $mime = is_array($imageInfo) ? strtolower((string)($imageInfo['mime'] ?? '')) : '';
    if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
        error_log('GCash receipt viewer rejected non-JPEG/PNG image from ' . $sourceDescription . '.');
        gcashReceiptFail(415, 'Unsupported receipt image.');
    }

    $extension = $mime === 'image/png' ? 'png' : 'jpg';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="gcash-receipt.' . $extension . '"');
    header('Content-Length: ' . strlen($contents));
    echo $contents;
    exit;
}

/**
 * Create a short-lived signed URL and fetch it server-to-server.
 * The signed URL and Supabase secret key are never returned to the browser.
 */
function gcashReceiptDownloadFromStorage(string $objectPath): string
{
    $supabaseUrl = trim((string)(getenv('SUPABASE_URL') ?: ''));
    $secretKey = trim((string)(getenv('SUPABASE_SECRET_KEY') ?: ''));
    $legacyServiceRoleKey = trim((string)(getenv('SUPABASE_SERVICE_ROLE_KEY') ?: ''));
    $bucket = trim((string)(getenv('SUPABASE_RECEIPT_BUCKET') ?: 'gcash-receipts-private'));

    $apiKey = $secretKey !== '' ? $secretKey : $legacyServiceRoleKey;
    if ($supabaseUrl === '' || $apiKey === '' || $bucket === '') {
        error_log('GCash receipt viewer is missing Supabase Storage environment configuration.');
        gcashReceiptFail(503, 'Receipt storage is not configured.');
    }
    if (!function_exists('curl_init')) {
        error_log('GCash receipt viewer requires the PHP cURL extension.');
        gcashReceiptFail(503, 'Receipt storage is temporarily unavailable.');
    }

    $supabaseUrl = rtrim($supabaseUrl, '/');
    $parsedUrl = parse_url($supabaseUrl);
    if (
        !filter_var($supabaseUrl, FILTER_VALIDATE_URL)
        || !is_array($parsedUrl)
        || strtolower((string)($parsedUrl['scheme'] ?? '')) !== 'https'
        || empty($parsedUrl['host'])
        || isset($parsedUrl['user'])
        || isset($parsedUrl['pass'])
        || (isset($parsedUrl['path']) && trim((string)$parsedUrl['path'], '/') !== '')
        || !preg_match('/^[a-z0-9][a-z0-9_-]{1,61}[a-z0-9]$/i', $bucket)
    ) {
        error_log('GCash receipt viewer has invalid Supabase Storage configuration.');
        gcashReceiptFail(503, 'Receipt storage is not configured correctly.');
    }

    $segments = explode('/', $objectPath);
    if (
        count($segments) < 2
        || $segments[0] !== 'receipts'
        || strlen($objectPath) > 300
        || in_array('', $segments, true)
        || in_array('.', $segments, true)
        || in_array('..', $segments, true)
    ) {
        gcashReceiptFail(404, 'Receipt not found.');
    }
    foreach ($segments as $segment) {
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $segment)) {
            gcashReceiptFail(404, 'Receipt not found.');
        }
    }
    if (!preg_match('/\.(?:jpe?g|png)$/i', end($segments))) {
        gcashReceiptFail(404, 'Receipt not found.');
    }

    $encodedPath = implode('/', array_map('rawurlencode', $segments));
    $signEndpoint = $supabaseUrl . '/storage/v1/object/sign/' . rawurlencode($bucket) . '/' . $encodedPath;
    $headers = ['apikey: ' . $apiKey, 'Content-Type: application/json', 'Accept: application/json'];

    // New Supabase sb_secret_* keys are sent as apikey only. Legacy service_role JWTs
    // require the Bearer header as well.
    $isLegacyJwt = ($secretKey === '' && $legacyServiceRoleKey !== '')
        || (substr_count($apiKey, '.') === 2 && str_starts_with($apiKey, 'eyJ'));
    if ($isLegacyJwt) {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }

    $signCurl = curl_init();
    if ($signCurl === false) {
        gcashReceiptFail(503, 'Receipt storage is temporarily unavailable.');
    }
    curl_setopt_array($signCurl, [
        CURLOPT_URL => $signEndpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['expiresIn' => 60], JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $signBody = curl_exec($signCurl);
    $signError = curl_error($signCurl);
    $signStatus = (int)curl_getinfo($signCurl, CURLINFO_HTTP_CODE);
    curl_close($signCurl);

    if ($signBody === false || $signStatus !== 200) {
        error_log('GCash receipt signing request failed with HTTP ' . $signStatus . ($signError !== '' ? ': ' . $signError : '') . '.');
        if ($signStatus === 404) {
            gcashReceiptFail(404, 'Receipt not found in private storage.');
        }
        gcashReceiptFail(502, 'Unable to retrieve the receipt right now.');
    }

    $signData = json_decode((string)$signBody, true);
    $signedPath = is_array($signData) ? trim((string)($signData['signedURL'] ?? '')) : '';
    if ($signedPath === '') {
        error_log('GCash receipt signing response did not contain a signedURL.');
        gcashReceiptFail(502, 'Unable to retrieve the receipt right now.');
    }

    // Only accept a relative Storage signed path or an HTTPS URL on this exact project host.
    if (str_starts_with($signedPath, '/object/sign/')) {
        $downloadUrl = $supabaseUrl . '/storage/v1' . $signedPath;
    } elseif (str_starts_with($signedPath, '/storage/v1/object/sign/')) {
        $downloadUrl = $supabaseUrl . $signedPath;
    } else {
        $signedUrlParts = parse_url($signedPath);
        if (
            !is_array($signedUrlParts)
            || strtolower((string)($signedUrlParts['scheme'] ?? '')) !== 'https'
            || strtolower((string)($signedUrlParts['host'] ?? '')) !== strtolower((string)$parsedUrl['host'])
            || isset($signedUrlParts['user'])
            || isset($signedUrlParts['pass'])
            || !str_starts_with((string)($signedUrlParts['path'] ?? ''), '/storage/v1/object/sign/')
        ) {
            error_log('GCash receipt signing endpoint returned an unexpected URL format.');
            gcashReceiptFail(502, 'Unable to retrieve the receipt right now.');
        }
        $downloadUrl = $signedPath;
    }

    $contents = '';
    $maxBytes = 5 * 1024 * 1024;
    $downloadCurl = curl_init();
    if ($downloadCurl === false) {
        gcashReceiptFail(503, 'Receipt storage is temporarily unavailable.');
    }
    curl_setopt_array($downloadCurl, [
        CURLOPT_URL => $downloadUrl,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => ['Accept: image/jpeg, image/png'],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$contents, $maxBytes): int {
            $chunkLength = strlen($chunk);
            if (strlen($contents) + $chunkLength > $maxBytes) {
                return 0;
            }
            $contents .= $chunk;
            return $chunkLength;
        },
    ]);
    $downloadResult = curl_exec($downloadCurl);
    $downloadError = curl_error($downloadCurl);
    $downloadStatus = (int)curl_getinfo($downloadCurl, CURLINFO_HTTP_CODE);
    curl_close($downloadCurl);

    if ($downloadResult === false || $downloadStatus !== 200) {
        error_log('GCash receipt signed download failed with HTTP ' . $downloadStatus . ($downloadError !== '' ? ': ' . $downloadError : '') . '.');
        if ($downloadStatus === 404) {
            gcashReceiptFail(404, 'Receipt not found in private storage.');
        }
        gcashReceiptFail(502, 'Unable to retrieve the receipt right now.');
    }

    return $contents;
}

// New bookings store a private Storage object path such as receipts/YYYY/MM/name.jpg.
if (str_starts_with($storedReceipt, 'receipts/')) {
    $receiptContents = gcashReceiptDownloadFromStorage($storedReceipt);
    gcashReceiptOutputImage($receiptContents);
}

// Backward compatibility for older bookings created before private Storage was enabled.
// Only a single legacy filename (or an uploads/filename value) is accepted; paths and URLs
// supplied through the database field are never fetched from remote hosts.
$legacyFilename = null;
if (preg_match('~^(?:(?:\.\./)?uploads/)?([A-Za-z0-9][A-Za-z0-9._-]{0,180}\.(?:jpe?g|png))$~i', $storedReceipt, $legacyMatch)) {
    $legacyFilename = $legacyMatch[1];
}
if ($legacyFilename === null) {
    gcashReceiptFail(404, 'Receipt not found.');
}

$uploadsDirectory = realpath(__DIR__ . '/../uploads');
$legacyFile = $uploadsDirectory !== false ? realpath($uploadsDirectory . DIRECTORY_SEPARATOR . $legacyFilename) : false;
if (
    $uploadsDirectory === false
    || $legacyFile === false
    || !is_file($legacyFile)
    || !is_readable($legacyFile)
    || !str_starts_with($legacyFile, $uploadsDirectory . DIRECTORY_SEPARATOR)
) {
    gcashReceiptFail(404, 'Legacy receipt file is not available on this deployment.');
}

$fileSize = filesize($legacyFile);
if ($fileSize === false || $fileSize <= 0 || $fileSize > 5 * 1024 * 1024) {
    gcashReceiptFail(404, 'Receipt not found.');
}
$fileContents = file_get_contents($legacyFile);
if (!is_string($fileContents)) {
    gcashReceiptFail(404, 'Receipt not found.');
}
gcashReceiptOutputImage($fileContents, 'legacy local uploads');
