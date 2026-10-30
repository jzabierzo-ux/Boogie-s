<?php

declare(strict_types=1);

require_once __DIR__ . '/../shared_session_bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

use PHPMailer\PHPMailer\PHPMailer;

/** Decode a strict base64url JWT segment. */
function googleLoginBase64UrlDecode(string $value): string|false
{
    if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
        return false;
    }

    $remainder = strlen($value) % 4;
    if ($remainder === 1) {
        return false;
    }

    $value = strtr($value, '-_', '+/');
    if ($remainder !== 0) {
        $value .= str_repeat('=', 4 - $remainder);
    }

    return base64_decode($value, true);
}

/**
 * Fetch and cache Google's Firebase Secure Token public certificates.
 * The endpoint's max-age header controls the cache lifetime.
 */
function googleLoginFirebasePublicKeys(bool $forceRefresh = false): array
{
    static $memoryKeys = null;
    static $memoryExpiresAt = 0;

    $projectId = trim((string)(getenv('FIREBASE_PROJECT_ID') ?: 'boogiespetcare'));
    $cacheFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'boogie_firebase_keys_' . hash('sha256', $projectId) . '.json';
    $now = time();

    if (!$forceRefresh && is_array($memoryKeys) && $memoryExpiresAt > $now) {
        return $memoryKeys;
    }

    if (!$forceRefresh && is_file($cacheFile)) {
        $cachedJson = @file_get_contents($cacheFile);
        if (is_string($cachedJson) && $cachedJson !== '') {
            $cached = json_decode($cachedJson, true);
            if (
                is_array($cached)
                && isset($cached['expires_at'], $cached['keys'])
                && is_numeric($cached['expires_at'])
                && (int)$cached['expires_at'] > $now
                && is_array($cached['keys'])
                && $cached['keys'] !== []
            ) {
                $memoryKeys = $cached['keys'];
                $memoryExpiresAt = (int)$cached['expires_at'];
                return $memoryKeys;
            }
        }
    }

    $certUrl = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 8,
            'ignore_errors' => false,
            'header' => "Accept: application/json\r\nUser-Agent: BoogiesPetCare-FirebaseTokenVerifier/1.0\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ],
    ]);

    $responseHeaders = [];
    $certBody = @file_get_contents($certUrl, false, $context);
    if (!isset($http_response_header) || !is_array($http_response_header)) {
        throw new RuntimeException('Firebase signing certificates could not be retrieved.');
    }
    $responseHeaders = $http_response_header;

    $httpStatus = 0;
    $cacheMaxAge = 3600;
    foreach ($responseHeaders as $headerLine) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $headerLine, $statusMatch)) {
            $httpStatus = (int)$statusMatch[1];
        } elseif (stripos($headerLine, 'Cache-Control:') === 0
            && preg_match('/(?:^|[,\s])max-age=(\d+)/i', $headerLine, $ageMatch)) {
            $cacheMaxAge = max(60, min(21600, (int)$ageMatch[1]));
        }
    }

    if ($certBody === false || $httpStatus !== 200 || strlen($certBody) > 65536) {
        throw new RuntimeException('Firebase signing certificates are temporarily unavailable.');
    }

    $keys = json_decode($certBody, true);
    if (!is_array($keys) || $keys === []) {
        throw new RuntimeException('Firebase signing certificate response was invalid.');
    }

    foreach ($keys as $keyId => $certificate) {
        if (
            !is_string($keyId)
            || $keyId === ''
            || !is_string($certificate)
            || strpos($certificate, '-----BEGIN CERTIFICATE-----') === false
            || strpos($certificate, '-----END CERTIFICATE-----') === false
        ) {
            throw new RuntimeException('Firebase signing certificate response was invalid.');
        }
    }

    $expiresAt = $now + $cacheMaxAge;
    $memoryKeys = $keys;
    $memoryExpiresAt = $expiresAt;

    $cachePayload = json_encode(
        ['expires_at' => $expiresAt, 'keys' => $keys],
        JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if (is_string($cachePayload)) {
        $temporaryFile = @tempnam(sys_get_temp_dir(), 'boogie-firebase-keys-');
        if ($temporaryFile !== false) {
            @chmod($temporaryFile, 0600);
            if (@file_put_contents($temporaryFile, $cachePayload, LOCK_EX) !== false) {
                @rename($temporaryFile, $cacheFile);
            } else {
                @unlink($temporaryFile);
            }
        }
    }

    return $keys;
}

/** Verify the Firebase ID-token signature and required identity claims. */
function googleLoginVerifyFirebaseIdToken(string $idToken): array
{
    if ($idToken === '' || strlen($idToken) > 16384) {
        throw new InvalidArgumentException('Missing or invalid Google sign-in token.');
    }

    $segments = explode('.', $idToken);
    if (count($segments) !== 3) {
        throw new InvalidArgumentException('Malformed Firebase ID token.');
    }

    [$encodedHeader, $encodedClaims, $encodedSignature] = $segments;
    $headerJson = googleLoginBase64UrlDecode($encodedHeader);
    $claimsJson = googleLoginBase64UrlDecode($encodedClaims);
    $signature = googleLoginBase64UrlDecode($encodedSignature);

    if ($headerJson === false || $claimsJson === false || $signature === false || $signature === '') {
        throw new InvalidArgumentException('Malformed Firebase ID token.');
    }

    $header = json_decode($headerJson, true);
    $claims = json_decode($claimsJson, true);
    if (!is_array($header) || !is_array($claims)) {
        throw new InvalidArgumentException('Malformed Firebase ID token claims.');
    }

    $keyId = $header['kid'] ?? '';
    if (($header['alg'] ?? '') !== 'RS256' || !is_string($keyId) || $keyId === '') {
        throw new InvalidArgumentException('Unsupported Firebase ID token signature.');
    }

    $keys = googleLoginFirebasePublicKeys(false);
    if (!isset($keys[$keyId])) {
        // Refresh once in case Google has rotated its signing certificates.
        $keys = googleLoginFirebasePublicKeys(true);
    }
    if (!isset($keys[$keyId])) {
        throw new InvalidArgumentException('Unrecognized Firebase ID token signing key.');
    }

    $projectId = trim((string)(getenv('FIREBASE_PROJECT_ID') ?: 'boogiespetcare'));
    $now = time();
    $clockSkew = 60;
    $expectedIssuer = 'https://securetoken.google.com/' . $projectId;

    if (
        $projectId === ''
        || ($claims['aud'] ?? null) !== $projectId
        || ($claims['iss'] ?? null) !== $expectedIssuer
        || !isset($claims['exp'], $claims['iat'], $claims['auth_time'])
        || !is_numeric($claims['exp'])
        || !is_numeric($claims['iat'])
        || !is_numeric($claims['auth_time'])
        || (int)$claims['exp'] <= $now
        || (int)$claims['iat'] <= 0
        || (int)$claims['iat'] > ($now + $clockSkew)
        || (int)$claims['auth_time'] <= 0
        || (int)$claims['auth_time'] > ($now + $clockSkew)
        || !isset($claims['sub'])
        || !is_string($claims['sub'])
        || trim($claims['sub']) === ''
        || strlen($claims['sub']) > 128
    ) {
        throw new InvalidArgumentException('Firebase ID token claims did not pass validation.');
    }

    $firebaseClaims = $claims['firebase'] ?? null;
    if (
        !is_array($firebaseClaims)
        || ($firebaseClaims['sign_in_provider'] ?? '') !== 'google.com'
        || ($claims['email_verified'] ?? false) !== true
    ) {
        throw new InvalidArgumentException('A verified Google account is required.');
    }

    $email = strtolower(trim((string)($claims['email'] ?? '')));
    if ($email === '' || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('The verified Google account has no valid email address.');
    }

    $certificate = openssl_x509_read($keys[$keyId]);
    if ($certificate === false) {
        throw new RuntimeException('Firebase signing certificate could not be loaded.');
    }

    $verified = openssl_verify(
        $encodedHeader . '.' . $encodedClaims,
        $signature,
        $certificate,
        OPENSSL_ALGO_SHA256
    );
    if ($verified !== 1) {
        throw new InvalidArgumentException('Firebase ID token signature is invalid.');
    }

    $name = trim((string)($claims['name'] ?? ''));
    if ($name === '') {
        $name = strstr($email, '@', true) ?: 'Customer';
    }
    if (strlen($name) > 150) {
        // Avoid clipping a UTF-8 name in the middle of a multi-byte character.
        $name = strstr($email, '@', true) ?: 'Customer';
    }

    return [
        'uid' => $claims['sub'],
        'email' => $email,
        'full_name' => $name,
    ];
}

/** Return a consistent JSON response and stop processing. */
function googleLoginJsonResponse(bool $success, string $message, ?string $redirect = null, int $status = 200): void
{
    http_response_code($status);
    $payload = ['success' => $success, 'message' => $message];

    if ($redirect !== null) {
        $payload['redirect'] = $redirect;
    }

    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

/** Read a scalar POST value without generating array-to-string warnings. */
function googleLoginPostString(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    googleLoginJsonResponse(false, 'Invalid request method.', null, 405);
}

// Add a same-origin browser-request check. This supplements, but does not
// replace, a session CSRF token supplied by the calling login page.
$fetchSite = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
if ($fetchSite === 'cross-site') {
    googleLoginJsonResponse(false, 'This request is not allowed.', null, 403);
}

$origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
if ($origin !== '') {
    $originHost = strtolower((string)parse_url($origin, PHP_URL_HOST));
    $requestHost = strtolower((string)parse_url('http://' . (string)($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));

    if ($originHost === '' || $requestHost === '' || !hash_equals($requestHost, $originHost)) {
        googleLoginJsonResponse(false, 'This request is not allowed.', null, 403);
    }
}

$postedCsrf = googleLoginPostString('csrf_token');
$sessionCsrf = (string)($_SESSION['csrf_token'] ?? '');
if ($postedCsrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $postedCsrf)) {
    googleLoginJsonResponse(false, 'Your session has expired. Refresh the page and try again.', null, 403);
}

$idToken = googleLoginPostString('id_token');
try {
    $verifiedGoogleUser = googleLoginVerifyFirebaseIdToken($idToken);
} catch (InvalidArgumentException $tokenError) {
    error_log('Google login token rejected: ' . $tokenError->getMessage());
    googleLoginJsonResponse(false, 'Google sign-in could not be verified. Please try again.', null, 401);
} catch (Throwable $tokenError) {
    error_log('Google login token verification unavailable: ' . $tokenError->getMessage());
    googleLoginJsonResponse(false, 'Google sign-in verification is temporarily unavailable. Please try again later.', null, 503);
}

// These values now come only from the cryptographically verified Firebase token.
$fullName = $verifiedGoogleUser['full_name'];
$email = $verifiedGoogleUser['email'];

// Prevent repeated OTP emails from rapid requests in the same browser session.
$lastGoogleOtpSent = (int)($_SESSION['google_login_last_sent'] ?? 0);
if ($lastGoogleOtpSent > 0 && (time() - $lastGoogleOtpSent) < 30) {
    $waitSeconds = 30 - (time() - $lastGoogleOtpSent);
    googleLoginJsonResponse(
        false,
        'Please wait ' . $waitSeconds . ' seconds before requesting another verification code.',
        null,
        429
    );
}

if (!defined('SMTP_EMAIL')) {
    define('SMTP_EMAIL', getenv('SMTP_EMAIL') ?: 'prototyp6712@gmail.com');
}
if (!defined('SMTP_PASS')) {
    define('SMTP_PASS', getenv('SMTP_PASS') ?: '');
}

$smtpEmail = trim((string)SMTP_EMAIL);
$smtpPassword = preg_replace('/\s+/', '', (string)SMTP_PASS);

if ($smtpEmail === '' || $smtpPassword === '') {
    error_log('Google login OTP unavailable: SMTP_EMAIL or SMTP_PASS is not configured.');
    googleLoginJsonResponse(false, 'Email verification is not configured. Please contact the administrator.', null, 500);
}

$createdUserId = 0;
$otp = (string)random_int(100000, 999999);
$isNewUser = false;
$previousOtp = null;
$previousOtpCaptured = false;
$mailName = $fullName;
$pdo = null;

try {
    require_once __DIR__ . '/../db_supabase.php';
    require_once __DIR__ . '/../PHPMailer/src/Exception.php';
    require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/../PHPMailer/src/SMTP.php';

    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is unavailable.');
    }

    // The signed Firebase token has been verified; only customer accounts may use this endpoint.
    $findUser = $pdo->prepare(
        'SELECT id, email, full_name, role, is_verified, otp_code
         FROM users
         WHERE LOWER(email) = LOWER(:email)
         LIMIT 1'
    );
    $findUser->execute([':email' => $email]);
    $user = $findUser->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $existingRole = strtolower(trim((string)($user['role'] ?? '')));
        if ($existingRole !== 'customer') {
            googleLoginJsonResponse(
                false,
                'This account cannot use customer Google login. Please use the appropriate login page.',
                null,
                403
            );
        }

        $createdUserId = (int)$user['id'];
        $mailName = trim((string)($user['full_name'] ?? '')) ?: $fullName;
        $previousOtp = $user['otp_code'] ?? null;
        $previousOtpCaptured = true;

        $update = $pdo->prepare(
            "UPDATE users
             SET otp_code = :otp
             WHERE id = :id
               AND LOWER(TRIM(role)) = 'customer'"
        );
        $update->execute([
            ':otp' => $otp,
            ':id' => $createdUserId,
        ]);

        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Customer account could not be prepared for verification.');
        }
    } else {
        $generatedPassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        if ($generatedPassword === false) {
            throw new RuntimeException('Unable to prepare the customer account.');
        }

        $insert = $pdo->prepare(
            'INSERT INTO users (
                full_name, contact_number, email, password, role, position,
                user_category, otp_code, is_verified, email_verified
             ) VALUES (
                :full_name, :contact_number, :email, :password, :role, :position,
                :user_category, :otp, FALSE, FALSE
             )
             RETURNING id'
        );
        $insert->execute([
            ':full_name' => $fullName,
            ':contact_number' => 'Not Provided',
            ':email' => $email,
            ':password' => $generatedPassword,
            ':role' => 'customer',
            ':position' => 'Customer',
            ':user_category' => 'Pet Owner',
            ':otp' => $otp,
        ]);

        $createdUserId = (int)$insert->fetchColumn();
        if ($createdUserId <= 0) {
            throw new RuntimeException('Customer account could not be created.');
        }

        $isNewUser = true;
    }

    // Retry Gmail delivery via implicit TLS if STARTTLS on port 587 fails.
    $smtpModes = [
        ['port' => 587, 'encryption' => PHPMailer::ENCRYPTION_STARTTLS],
        ['port' => 465, 'encryption' => PHPMailer::ENCRYPTION_SMTPS],
    ];
    $smtpErrors = [];
    $emailSent = false;

    $safeName = htmlspecialchars($mailName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');

    foreach ($smtpModes as $smtpMode) {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = $smtpEmail;
            $mail->Password = $smtpPassword;
            $mail->SMTPSecure = $smtpMode['encryption'];
            $mail->Port = $smtpMode['port'];
            $mail->Timeout = 20;
            $mail->SMTPDebug = 0;
            $mail->CharSet = 'UTF-8';

            $mail->setFrom($smtpEmail, "Boogie's Pet Care");
            $mail->addAddress($email, $mailName);
            $mail->isHTML(true);
            $mail->Subject = "Google Login Verification - Boogie's Pet Care";
            $mail->Body = "
                <div style='font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:20px;border:1px solid #e2e8f0;border-radius:10px;background:#ffffff;'>
                    <h2 style='color:#001f3f;text-align:center;'>2-Step Verification</h2>
                    <p>Hi {$safeName},</p>
                    <p>We detected a Google Sign-In attempt.</p>
                    <p>To complete your login, please enter this verification code:</p>
                    <h1 style='background:#f4f7fe;padding:15px;text-align:center;color:#1d63ff;letter-spacing:5px;border-radius:8px;'>{$safeOtp}</h1>
                    <p style='font-size:12px;color:#64748b;text-align:center;margin-top:30px;'>© Boogie's Pet Care &amp; Services</p>
                </div>
            ";
            $mail->AltBody = "Hi {$mailName}, your Boogie's Pet Care verification code is {$otp}.";
            $mail->send();
            $emailSent = true;
            break;
        } catch (Throwable $mailError) {
            $smtpErrors[] = 'port ' . $smtpMode['port'] . ': ' . $mailError->getMessage();
            error_log('Google login OTP SMTP attempt failed on port ' . $smtpMode['port'] . ': ' . $mailError->getMessage());
        }
    }

    if (!$emailSent) {
        throw new RuntimeException('Gmail SMTP delivery failed on all configured connection methods. ' . implode(' | ', $smtpErrors));
    }

    // Replace the previous challenge only after the new code has been emailed.
    $_SESSION['login_temp_email'] = $email;
    $_SESSION['login_temp_expires'] = time() + 900;
    $_SESSION['login_otp_attempts'] = 0;
    $_SESSION['login_otp_resends'] = 0;
    $_SESSION['login_otp_last_sent'] = time();
    $_SESSION['google_login_last_sent'] = time();

    googleLoginJsonResponse(true, 'Verification code sent to your email.', 'login.php');
} catch (Throwable $e) {
    error_log('Google login OTP error: ' . $e->getMessage());

    // Restore the previous customer OTP if delivery failed, or remove only the
    // unverified customer row created by this request.
    if ($pdo instanceof PDO && $createdUserId > 0 && $otp !== null) {
        try {
            if ($isNewUser) {
                $cleanup = $pdo->prepare(
                    "DELETE FROM users
                     WHERE id = :id
                       AND LOWER(TRIM(role)) = 'customer'
                       AND is_verified = FALSE
                       AND otp_code = :otp"
                );
                $cleanup->execute([
                    ':id' => $createdUserId,
                    ':otp' => $otp,
                ]);
            } elseif ($previousOtpCaptured) {
                $restore = $pdo->prepare(
                    'UPDATE users
                     SET otp_code = :previous_otp
                     WHERE id = :id
                       AND otp_code = :current_otp'
                );
                $restore->execute([
                    ':previous_otp' => $previousOtp,
                    ':id' => $createdUserId,
                    ':current_otp' => $otp,
                ]);
            }
        } catch (Throwable $cleanupError) {
            error_log('Google login OTP cleanup error: ' . $cleanupError->getMessage());
        }
    }

    // Keep any previously active login challenge intact on failure.
    googleLoginJsonResponse(
        false,
        'We could not send a verification code right now. Please try again later.',
        null,
        500
    );
}
