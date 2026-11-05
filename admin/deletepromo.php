<?php
// The shared session bootstrap initializes the shared session handler and
// loads db_supabase.php, making the PDO connection available in this scope.
require_once __DIR__ . '/../shared_session_bootstrap.php';

// Admin-only authorization. Keep legacy Admin role naming compatible while
// requiring the separate Admin-session marker set by admin_login.php.
$raw_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
$current_role = ($raw_role === 'administrator') ? 'admin' : $raw_role;
$is_admin_session = (
    ($_SESSION['logged_in'] ?? false) === true
    && ($_SESSION['admin_logged_in'] ?? false) === true
    && $current_role === 'admin'
    && isset($_SESSION['user_id'])
    && is_numeric($_SESSION['user_id'])
    && (int)$_SESSION['user_id'] > 0
);

if (!$is_admin_session) {
    header('Location: ../admin_login.php');
    exit();
}

/** Read the dedicated Supabase Storage settings for promotion images. */
function deletePromoStorageConfig(): ?array
{
    $supabaseUrl = trim((string)(getenv('SUPABASE_URL') ?: ''));
    $secretKey = trim((string)(getenv('SUPABASE_SECRET_KEY') ?: ''));
    $legacyServiceRoleKey = trim((string)(getenv('SUPABASE_SERVICE_ROLE_KEY') ?: ''));
    $apiKey = $secretKey !== '' ? $secretKey : $legacyServiceRoleKey;
    $usesLegacyJwt = ($secretKey === '' && $legacyServiceRoleKey !== '');

    if ($supabaseUrl === '' || $apiKey === '' || preg_match('/[\\r\\n]/', $apiKey)) {
        return null;
    }

    $supabaseUrl = rtrim($supabaseUrl, '/');
    $parsedUrl = parse_url($supabaseUrl);
    if (
        !filter_var($supabaseUrl, FILTER_VALIDATE_URL) ||
        !is_array($parsedUrl) ||
        strtolower((string)($parsedUrl['scheme'] ?? '')) !== 'https' ||
        empty($parsedUrl['host']) ||
        isset($parsedUrl['user']) ||
        isset($parsedUrl['pass'])
    ) {
        return null;
    }

    return [
        'url' => $supabaseUrl,
        'key' => $apiKey,
        'uses_legacy_jwt' => $usesLegacyJwt,
        'bucket' => 'promos'
    ];
}

/** Accept only public image URLs belonging to this project's promos bucket. */
function deletePromoStorageObjectFromPublicUrl($storedUrl): ?string
{
    if (!is_string($storedUrl) || trim($storedUrl) === '') {
        return null;
    }
    $config = deletePromoStorageConfig();
    if ($config === null) {
        return null;
    }

    $base = parse_url($config['url']);
    $url = parse_url(trim($storedUrl));
    if (!is_array($base) || !is_array($url)) {
        return null;
    }
    if (
        strtolower((string)($url['scheme'] ?? '')) !== 'https' ||
        strtolower((string)($url['host'] ?? '')) !== strtolower((string)($base['host'] ?? '')) ||
        (int)($url['port'] ?? 443) !== (int)($base['port'] ?? 443) ||
        isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
    ) {
        return null;
    }

    $prefix = '/storage/v1/object/public/promos/';
    $urlPath = (string)($url['path'] ?? '');
    if (strpos($urlPath, $prefix) !== 0) {
        return null;
    }

    $objectName = rawurldecode(substr($urlPath, strlen($prefix)));
    if (
        $objectName === '' ||
        basename($objectName) !== $objectName ||
        !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,180}$/', $objectName)
    ) {
        return null;
    }
    return $objectName;
}

/** Delete one known object from the dedicated promos bucket using server credentials. */
function deletePromoStorageObject(string $objectName): array
{
    $config = deletePromoStorageConfig();
    if ($config === null) {
        return ['ok' => false, 'status' => 0, 'error' => 'Supabase Storage is not configured.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'error' => 'The PHP cURL extension is not available.'];
    }
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,180}$/', $objectName)) {
        return ['ok' => false, 'status' => 0, 'error' => 'Invalid Storage object name.'];
    }

    $endpoint = $config['url'] . '/storage/v1/object/' . rawurlencode($config['bucket']) . '/' . rawurlencode($objectName);
    $headers = ['apikey: ' . $config['key']];
    if (!empty($config['uses_legacy_jwt'])) {
        $headers[] = 'Authorization: Bearer ' . $config['key'];
    }

    $curl = curl_init();
    if ($curl === false) {
        return ['ok' => false, 'status' => 0, 'error' => 'Unable to initialize the Storage connection.'];
    }
    curl_setopt_array($curl, [
        CURLOPT_URL => $endpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false
    ]);

    $body = curl_exec($curl);
    $curlError = curl_error($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($body === false) {
        error_log('Delete promo Supabase Storage request failed: ' . $curlError);
        return ['ok' => false, 'status' => $status, 'error' => 'Could not connect to Supabase Storage.'];
    }

    $ok = in_array($status, [200, 204], true);
    if (!$ok) {
        error_log('Delete promo Supabase Storage returned HTTP ' . $status . ': ' . substr((string)$body, 0, 500));
    }
    return [
        'ok' => $ok,
        'status' => $status,
        'error' => $ok ? '' : 'Supabase Storage rejected the delete request (HTTP ' . $status . ').'
    ];
}

// This endpoint only accepts form submissions that change data.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

// Validate the session-bound CSRF token generated by managepromo.php.
$session_token = $_SESSION['csrf_token'] ?? '';
$submitted_token = $_POST['csrf_token'] ?? '';
if (
    !is_string($session_token)
    || $session_token === ''
    || !is_string($submitted_token)
    || $submitted_token === ''
    || !hash_equals($session_token, $submitted_token)
) {
    http_response_code(403);
    exit('Invalid security token.');
}

// Validate the promotion ID before starting a database transaction.
$raw_promo_id = $_POST['id'] ?? null;
$promo_id = filter_var($raw_promo_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($promo_id === false || $promo_id === null) {
    header('Location: managepromo.php', true, 303);
    exit();
}
$promo_id = (int)$promo_id;

// shared_session_bootstrap.php is responsible for creating the PDO connection.
if (!isset($pdo) || !($pdo instanceof PDO)) {
    error_log('Delete promo: database connection was not initialized.');
    http_response_code(503);
    exit('The database is temporarily unavailable. Please try again.');
}

$image_url = '';
$should_delete_image = false;
$storage_object_to_delete = null;

try {
    $pdo->beginTransaction();

    // Lock the promotion row so concurrent edits/deletes cannot race this action.
    $find_stmt = $pdo->prepare(
        'SELECT id, image_url FROM promos WHERE id = :promo_id LIMIT 1 FOR UPDATE'
    );
    $find_stmt->execute([':promo_id' => $promo_id]);
    $promo = $find_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$promo) {
        $pdo->rollBack();
        header('Location: managepromo.php', true, 303);
        exit();
    }

    $image_url = trim((string)($promo['image_url'] ?? ''));

    $delete_stmt = $pdo->prepare('DELETE FROM promos WHERE id = :promo_id');
    $delete_stmt->execute([':promo_id' => $promo_id]);

    if ($delete_stmt->rowCount() !== 1) {
        throw new RuntimeException('Promotion could not be deleted.');
    }

    // If this is a public URL from this project's promos bucket, check whether
    // another promotion still references the same URL before removing the object.
    $storage_object = deletePromoStorageObjectFromPublicUrl($image_url);
    if ($storage_object !== null) {
        $reference_stmt = $pdo->prepare('SELECT 1 FROM promos WHERE image_url = :image_url LIMIT 1');
        $reference_stmt->execute([':image_url' => $image_url]);
        if (!$reference_stmt->fetchColumn()) {
            $storage_object_to_delete = $storage_object;
        }
    }

    // Keep backward-compatible cleanup for legacy local promo image paths.
    // External URLs and paths outside uploads/promos are never removed.
    $normalized_image_url = ltrim(str_replace('\\', '/', $image_url), '/');
    if (
        $storage_object === null &&
        $normalized_image_url !== '' &&
        preg_match(
            '#^uploads/promos/([A-Za-z0-9._-]+\\.(?:jpg|jpeg|png|webp))$#i',
            $normalized_image_url,
            $matches
        )
    ) {
        $reference_stmt = $pdo->prepare(
            "SELECT 1 FROM promos WHERE LTRIM(COALESCE(image_url, ''), '/') = :image_path LIMIT 1"
        );
        $reference_stmt->execute([':image_path' => $normalized_image_url]);
        $should_delete_image = !$reference_stmt->fetchColumn();
    }

    // Commit the database deletion before attempting Storage/filesystem cleanup.
    $pdo->commit();

    if ($storage_object_to_delete !== null) {
        $storage_result = deletePromoStorageObject($storage_object_to_delete);
        if (empty($storage_result['ok'])) {
            error_log(
                'Delete promo: database row was deleted, but its Supabase Storage image could not be removed for promo ID ' .
                $promo_id . ': ' . (string)($storage_result['error'] ?? 'Unknown Storage error')
            );
        }
    }

    if ($should_delete_image && isset($matches[1])) {
        $project_root = dirname(__DIR__);
        $upload_dir = realpath(
            $project_root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'promos'
        );

        if ($upload_dir !== false) {
            $candidate = $upload_dir . DIRECTORY_SEPARATOR . $matches[1];
            $real_image_path = realpath($candidate);

            // realpath + parent-directory comparison prevents traversal and
            // refuses symlinks that resolve outside the promo upload directory.
            if (
                $real_image_path !== false
                && is_file($real_image_path)
                && dirname($real_image_path) === $upload_dir
                && !@unlink($real_image_path)
            ) {
                error_log('Delete promo: unable to remove associated image file for promo ID ' . $promo_id);
            }
        }
    }

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Delete promo failed for promo ID ' . $promo_id . ': ' . $e->getMessage());
}

// managepromo.php currently has no delete-result flash-message renderer, so
// preserve its existing post/redirect behavior without adding an unused alert.
header('Location: managepromo.php', true, 303);
exit();
