<?php
require_once __DIR__ . '/../shared_session_bootstrap.php';
require_once __DIR__ . '/../db_supabase.php';

if (
    !isset($_SESSION['register_csrf_token']) ||
    !is_string($_SESSION['register_csrf_token']) ||
    !preg_match('/\A[a-f0-9]{64}\z/i', $_SESSION['register_csrf_token'])
) {
    $_SESSION['register_csrf_token'] = bin2hex(random_bytes(32));
}
$register_csrf_token = $_SESSION['register_csrf_token'];

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../PHPMailer/src/Exception.php';
require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/src/SMTP.php';

if (!defined('SMTP_EMAIL')) {
    define('SMTP_EMAIL', getenv('SMTP_EMAIL') ?: 'prototyp6712@gmail.com');
}
if (!defined('SMTP_PASS')) {
    define('SMTP_PASS', getenv('SMTP_PASS') ?: '');
}

/** Read a scalar POST value without array-to-string warnings. */
function registerPostString(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? $value : '';
}

/** Normalize PostgreSQL/PDO boolean representations. */
function registerDbBooleanTrue($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (!is_scalar($value)) {
        return false;
    }
    return in_array(strtolower(trim((string)$value)), ['1', 't', 'true', 'yes', 'on'], true);
}

function registerStringLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

/** Decode a strict base64url JWT segment. */
function registerGoogleBase64UrlDecode(string $value): string|false
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
function registerGoogleFirebasePublicKeys(bool $forceRefresh = false): array
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
function registerGoogleVerifyFirebaseIdToken(string $idToken): array
{
    if ($idToken === '' || strlen($idToken) > 16384) {
        throw new InvalidArgumentException('Missing or invalid Google sign-in token.');
    }

    $segments = explode('.', $idToken);
    if (count($segments) !== 3) {
        throw new InvalidArgumentException('Malformed Firebase ID token.');
    }

    [$encodedHeader, $encodedClaims, $encodedSignature] = $segments;
    $headerJson = registerGoogleBase64UrlDecode($encodedHeader);
    $claimsJson = registerGoogleBase64UrlDecode($encodedClaims);
    $signature = registerGoogleBase64UrlDecode($encodedSignature);

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

    $keys = registerGoogleFirebasePublicKeys(false);
    if (!isset($keys[$keyId])) {
        // Refresh once in case Google has rotated its signing certificates.
        $keys = registerGoogleFirebasePublicKeys(true);
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


/** Output a safe alert and optionally navigate to a local page. */
function registerAlert(string $message, ?string $redirect = null): void
{
    $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    echo '<script>alert(' . json_encode($message, $flags) . ');';
    if ($redirect !== null) {
        $allowedRedirects = ['register.php', 'login.php', 'index.php'];
        if (in_array($redirect, $allowedRedirects, true)) {
            echo 'window.location.assign(' . json_encode($redirect, $flags) . ');';
        }
    }
    echo '</script>';
}

/** Send a registration OTP using Gmail SMTP, with a 465 fallback. */
function sendRegistrationOtpEmail(
    string $recipientEmail,
    string $recipientName,
    string $otp,
    string $subject,
    string $heading,
    string $intro
): void {
    $smtpEmail = trim((string)SMTP_EMAIL);
    $smtpPassword = preg_replace('/\s+/', '', (string)SMTP_PASS);

    if ($smtpEmail === '' || $smtpPassword === '') {
        throw new RuntimeException('SMTP_EMAIL or SMTP_PASS is not configured.');
    }

    $safeName = htmlspecialchars($recipientName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
    $safeHeading = htmlspecialchars($heading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeIntro = htmlspecialchars($intro, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $smtpModes = [
        ['port' => 587, 'encryption' => PHPMailer::ENCRYPTION_STARTTLS],
        ['port' => 465, 'encryption' => PHPMailer::ENCRYPTION_SMTPS],
    ];
    $errors = [];

    foreach ($smtpModes as $mode) {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = $smtpEmail;
            $mail->Password = $smtpPassword;
            $mail->SMTPSecure = $mode['encryption'];
            $mail->Port = $mode['port'];
            $mail->Timeout = 20;
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($smtpEmail, "Boogie's Pet Care & Services");
            $mail->addAddress($recipientEmail, $recipientName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = "
                <div style='font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:24px;border:1px solid #e2e8f0;border-radius:12px;background:#ffffff'>
                    <h2 style='color:#001f3f;text-align:center'>{$safeHeading}</h2>
                    <p>Hi {$safeName},</p>
                    <p>{$safeIntro}</p>
                    <h1 style='background:#fff7d6;padding:16px;text-align:center;color:#001f3f;letter-spacing:6px;border-radius:8px'>{$safeOtp}</h1>
                    <p>This 6-digit code is for account verification. If you did not request it, you can ignore this email.</p>
                    <p style='font-size:12px;color:#64748b;text-align:center;margin-top:26px'>© Boogie's Pet Care &amp; Services</p>
                </div>
            ";
            $mail->AltBody = "Hi {$recipientName}, {$intro} Your verification code is {$otp}. If you did not request it, you can ignore this email.";
            $mail->send();
            return;
        } catch (Throwable $e) {
            $errors[] = 'port ' . $mode['port'] . ': ' . $e->getMessage();
            error_log('Registration OTP SMTP attempt failed on port ' . $mode['port'] . ': ' . $e->getMessage());
        }
    }

    throw new RuntimeException('Gmail SMTP delivery failed on both configured connection methods. ' . implode(' | ', $errors));
}

// GOOGLE REGISTRATION: Firebase client authentication followed by email OTP.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['google_register_btn'])) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    $jsonResponse = static function (bool $success, string $message, ?string $redirect = null, int $status = 200): void {
        http_response_code($status);
        $body = ['success' => $success, 'message' => $message];
        if ($redirect !== null) {
            $body['redirect'] = $redirect;
        }
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    };

    $postedCsrf = registerPostString('csrf_token');
    if ($postedCsrf === '' || !hash_equals($register_csrf_token, $postedCsrf)) {
        $jsonResponse(false, 'Your registration session expired. Refresh the page and try again.', null, 403);
    }

    // Never trust a name or email supplied directly by the browser.
    // Verify the Firebase ID token and derive the account details from its signed claims.
    $idToken = registerPostString('id_token');
    try {
        $verifiedGoogleUser = registerGoogleVerifyFirebaseIdToken($idToken);
    } catch (InvalidArgumentException $tokenError) {
        $jsonResponse(false, 'Google authentication could not be verified. Please sign in with Google again.', null, 401);
    } catch (Throwable $tokenError) {
        error_log('Firebase token verification failed during registration: ' . $tokenError->getMessage());
        $jsonResponse(false, 'Google verification is temporarily unavailable. Please try again later.', null, 503);
    }

    $googleName = trim((string)$verifiedGoogleUser['full_name']);
    $googleEmail = strtolower(trim((string)$verifiedGoogleUser['email']));
    if (
        $googleName === '' || registerStringLength($googleName) > 150 ||
        !filter_var($googleEmail, FILTER_VALIDATE_EMAIL) || strlen($googleEmail) > 254
    ) {
        $jsonResponse(false, 'Google did not provide valid verified account details. Please try again.', null, 400);
    }

    if (trim((string)SMTP_EMAIL) === '' || preg_replace('/\s+/', '', (string)SMTP_PASS) === '') {
        $jsonResponse(false, 'Email verification is not configured on the server. Please contact the administrator.', null, 500);
    }

    $googleUserId = 0;
    $googleIsNewUser = false;
    $googleOtp = (string)random_int(100000, 999999);

    try {
        $findUser = $pdo->prepare(
            'SELECT id, email, full_name, role, is_verified FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1'
        );
        $findUser->execute([':email' => $googleEmail]);
        $existingGoogleUser = $findUser->fetch(PDO::FETCH_ASSOC);

        if ($existingGoogleUser) {
            if (strtolower(trim((string)($existingGoogleUser['role'] ?? ''))) !== 'customer') {
                $jsonResponse(false, 'This email belongs to a staff or administrator account. Please use the appropriate login page.', null, 403);
            }
            if (registerDbBooleanTrue($existingGoogleUser['is_verified'] ?? false)) {
                $jsonResponse(false, 'An account with this Google email already exists. Please use the Login page instead.', 'login.php', 409);
            }

            $googleUserId = (int)$existingGoogleUser['id'];
            $mailDisplayName = trim((string)($existingGoogleUser['full_name'] ?? '')) ?: $googleName;
            $saveGoogleOtp = $pdo->prepare(
                "UPDATE users
                 SET otp_code = :otp, is_verified = FALSE, email_verified = FALSE
                 WHERE id = :id
                   AND LOWER(TRIM(COALESCE(role, ''))) = 'customer'
                   AND COALESCE(is_verified, FALSE) = FALSE"
            );
            $saveGoogleOtp->execute([':otp' => $googleOtp, ':id' => $googleUserId]);
            if ($saveGoogleOtp->rowCount() !== 1) {
                $jsonResponse(false, 'This account has changed. Please refresh and try again.', null, 409);
            }
        } else {
            $randomPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
            if (!is_string($randomPassword) || $randomPassword === '') {
                throw new RuntimeException('Unable to create a secure account password.');
            }
            $insertGoogleUser = $pdo->prepare(
                'INSERT INTO users (
                    full_name, contact_number, email, password, role, position,
                    user_category, otp_code, is_verified, email_verified
                ) VALUES (
                    :full_name, :contact_number, :email, :password, :role, :position,
                    :user_category, :otp, FALSE, FALSE
                ) RETURNING id'
            );
            $insertGoogleUser->execute([
                ':full_name' => $googleName,
                ':contact_number' => 'Not Provided',
                ':email' => $googleEmail,
                ':password' => $randomPassword,
                ':role' => 'customer',
                ':position' => 'Customer',
                ':user_category' => 'Pet Owner',
                ':otp' => $googleOtp,
            ]);
            $googleUserId = (int)$insertGoogleUser->fetchColumn();
            if ($googleUserId < 1) {
                throw new RuntimeException('Google registration did not return a user ID.');
            }
            $googleIsNewUser = true;
            $mailDisplayName = $googleName;
        }

        sendRegistrationOtpEmail(
            $googleEmail,
            $mailDisplayName,
            $googleOtp,
            "Verify Your Google Account - Boogie's Pet Care",
            "Verify Your Boogie's Account",
            'Your Google account was authenticated. Enter this code on the registration page to finish creating your account.'
        );

        $_SESSION['temp_email'] = $googleEmail;
        $_SESSION['register_otp_last_sent'] = time();
        $_SESSION['register_otp_resends'] = 0;
        $jsonResponse(true, 'Verification code sent. Check your inbox and spam folder.', 'register.php');
    } catch (Throwable $googleRegisterError) {
        error_log('Google registration OTP send failed: ' . $googleRegisterError->getMessage());
        if ($googleUserId > 0) {
            try {
                if ($googleIsNewUser) {
                    $cleanupGoogleUser = $pdo->prepare(
                        'DELETE FROM users WHERE id = :id AND is_verified = FALSE AND otp_code = :otp'
                    );
                    $cleanupGoogleUser->execute([':id' => $googleUserId, ':otp' => $googleOtp]);
                } else {
                    $clearGoogleOtp = $pdo->prepare(
                        'UPDATE users SET otp_code = NULL WHERE id = :id AND otp_code = :otp AND is_verified = FALSE'
                    );
                    $clearGoogleOtp->execute([':id' => $googleUserId, ':otp' => $googleOtp]);
                }
            } catch (Throwable $cleanupGoogleError) {
                error_log('Google registration cleanup failed: ' . $cleanupGoogleError->getMessage());
            }
        }
        unset($_SESSION['temp_email'], $_SESSION['register_otp_last_sent'], $_SESSION['register_otp_resends']);
        $jsonResponse(false, 'We could not send the first verification code. Please try again later. If this continues, check the server SMTP settings.', null, 500);
    }
}

// RESEND OTP: 30-second cooldown and at most three resends per session challenge.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_register_otp_btn'])) {
    $submittedCsrf = registerPostString('csrf_token');
    if ($submittedCsrf === '' || !hash_equals($register_csrf_token, $submittedCsrf)) {
        http_response_code(403);
        exit('Invalid security token. Please refresh the page and try again.');
    }

    $email = strtolower(trim(is_string($_SESSION['temp_email'] ?? null) ? $_SESSION['temp_email'] : ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        unset($_SESSION['temp_email'], $_SESSION['register_otp_last_sent'], $_SESSION['register_otp_resends']);
        registerAlert('Your registration session expired. Please register again.', 'register.php');
        exit;
    }

    $lastSentAt = max(0, (int)($_SESSION['register_otp_last_sent'] ?? 0));
    $elapsed = time() - $lastSentAt;
    if ($lastSentAt > 0 && $elapsed < 30) {
        registerAlert('Please wait ' . (30 - $elapsed) . ' seconds before requesting another code.', 'register.php');
        exit;
    }
    if ((int)($_SESSION['register_otp_resends'] ?? 0) >= 3) {
        registerAlert('You have reached the resend limit. Please start registration again to request a new code.', 'register.php');
        exit;
    }
    if (trim((string)SMTP_EMAIL) === '' || preg_replace('/\s+/', '', (string)SMTP_PASS) === '') {
        registerAlert('Email verification is not configured. Please try again later.', 'register.php');
        exit;
    }

    $newOtp = (string)random_int(100000, 999999);
    $user = null;
    $previousOtp = null;
    try {
        $userStmt = $pdo->prepare(
            'SELECT id, full_name, role, is_verified, otp_code FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1'
        );
        $userStmt->execute([':email' => $email]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || strtolower(trim((string)($user['role'] ?? ''))) !== 'customer') {
            unset($_SESSION['temp_email'], $_SESSION['register_otp_last_sent'], $_SESSION['register_otp_resends']);
            registerAlert('Registration account not found. Please register again.', 'register.php');
            exit;
        }
        if (registerDbBooleanTrue($user['is_verified'] ?? false)) {
            unset($_SESSION['temp_email'], $_SESSION['register_otp_last_sent'], $_SESSION['register_otp_resends']);
            registerAlert('This account is already verified. Please log in.', 'login.php');
            exit;
        }

        $previousOtp = $user['otp_code'] ?? null;
        $updateOtp = $pdo->prepare(
            "UPDATE users
             SET otp_code = :otp, is_verified = FALSE, email_verified = FALSE
             WHERE id = :id
               AND LOWER(TRIM(COALESCE(role, ''))) = 'customer'
               AND COALESCE(is_verified, FALSE) = FALSE"
        );
        $updateOtp->execute([':otp' => $newOtp, ':id' => $user['id']]);
        if ($updateOtp->rowCount() !== 1) {
            throw new RuntimeException('Registration verification challenge is no longer active.');
        }

        sendRegistrationOtpEmail(
            $email,
            (string)($user['full_name'] ?? 'Customer'),
            $newOtp,
            "New Registration Verification Code - Boogie's Pet Care",
            'Your New Verification Code',
            "Here is your new Boogie's Pet Care account verification code:"
        );

        $_SESSION['register_otp_last_sent'] = time();
        $_SESSION['register_otp_resends'] = (int)($_SESSION['register_otp_resends'] ?? 0) + 1;
        registerAlert('A new verification code has been sent. Check your inbox and spam folder.', 'register.php');
        exit;
    } catch (Throwable $e) {
        try {
            if (isset($user['id'])) {
                $restoreOtp = $pdo->prepare(
                    'UPDATE users SET otp_code = :previous_otp WHERE id = :id AND otp_code = :new_otp AND is_verified = FALSE'
                );
                $restoreOtp->execute([
                    ':previous_otp' => $previousOtp,
                    ':id' => $user['id'],
                    ':new_otp' => $newOtp,
                ]);
            }
        } catch (Throwable $cleanupError) {
            error_log('Registration resend OTP restore failed: ' . $cleanupError->getMessage());
        }
        error_log('Registration OTP resend failed: ' . $e->getMessage());
        registerAlert('Could not send a new verification code. Please try again later.', 'register.php');
        exit;
    }
}

// VERIFY OTP: atomic UPDATE prevents the same OTP from being consumed twice.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_btn'])) {
    $submittedCsrf = registerPostString('csrf_token');
    if ($submittedCsrf === '' || !hash_equals($register_csrf_token, $submittedCsrf)) {
        http_response_code(403);
        exit('Invalid security token. Please refresh the page and try again.');
    }

    $email = $_SESSION['temp_email'] ?? '';
    if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        unset($_SESSION['temp_email'], $_SESSION['register_otp_last_sent'], $_SESSION['register_otp_resends']);
        registerAlert('Session expired. Please register again.', 'register.php');
        exit;
    }

    $enteredOtp = trim(registerPostString('otp_code'));
    if (!preg_match('/^[0-9]{6}$/', $enteredOtp)) {
        registerAlert('Please enter the 6-digit verification code.');
    } else {
        try {
            $verifyStmt = $pdo->prepare(
                "UPDATE users
                 SET is_verified = TRUE,
                     email_verified = TRUE,
                     otp_code = NULL
                 WHERE LOWER(email) = LOWER(:email)
                   AND otp_code = :otp
                   AND LOWER(TRIM(COALESCE(role, ''))) = 'customer'
                   AND COALESCE(is_verified, FALSE) = FALSE
                 RETURNING id, full_name, position"
            );
            $verifyStmt->execute([':email' => $email, ':otp' => $enteredOtp]);
            $userData = $verifyStmt->fetch(PDO::FETCH_ASSOC);

            if ($userData) {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_regenerate_id(true);
                }
                unset(
                    $_SESSION['temp_email'],
                    $_SESSION['register_otp_last_sent'],
                    $_SESSION['register_otp_resends'],
                    $_SESSION['register_csrf_token']
                );
                $_SESSION['logged_in'] = true;
                $_SESSION['user_id'] = (int)$userData['id'];
                $_SESSION['user_name'] = (string)($userData['full_name'] ?? 'Customer');
                $_SESSION['role'] = 'customer';
                $_SESSION['position'] = (string)($userData['position'] ?? 'Customer');
                registerAlert('Account verified successfully! Logging you in...', 'index.php');
                exit;
            }

            registerAlert('Invalid or already-used verification code. Please try again.');
        } catch (PDOException $e) {
            error_log('Registration OTP verification failed: ' . $e->getMessage());
            registerAlert('Unable to verify the account right now. Please try again later.');
        }
    }
}

// REGULAR CUSTOMER REGISTRATION.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_btn'])) {
    $submittedCsrf = registerPostString('csrf_token');
    if ($submittedCsrf === '' || !hash_equals($register_csrf_token, $submittedCsrf)) {
        http_response_code(403);
        exit('Invalid security token. Please refresh the page and try again.');
    }

    $fullName = trim(registerPostString('full_name'));
    $contact = trim(registerPostString('contact'));
    $email = strtolower(trim(registerPostString('email')));
    $userCategory = trim(registerPostString('user_category'));
    $password = registerPostString('password');
    $confirmPassword = registerPostString('confirm_password');

    $nameLength = registerStringLength($fullName);
    $validName = $nameLength >= 2 && $nameLength <= 150 &&
        (bool)preg_match('/^[\p{L}\p{M}][\p{L}\p{M} .\x{27}\x{2019}\-]*$/u', $fullName);

    if (!$validName) {
        registerAlert('Please enter a valid full name (letters, spaces, apostrophes, periods, or hyphens only; maximum 150 characters).');
    } elseif (!preg_match('/^09[0-9]{9}$/', $contact)) {
        registerAlert('Invalid contact number. Must start with 09 and be exactly 11 digits (e.g., 09123456789).');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        registerAlert('Invalid email. Please use a complete email address.');
    } elseif (!in_array($userCategory, ['Pet Owner', 'Pet Breeder'], true)) {
        registerAlert('Please select a valid registration category.');
    } elseif (!preg_match('/^(?=.*[A-Z]).{8,}$/', $password)) {
        registerAlert('Password must be at least 8 characters long and include at least 1 uppercase letter.');
    } elseif ($password !== $confirmPassword) {
        registerAlert('Passwords do not match.');
    } elseif (!isset($_POST['terms_agree'])) {
        registerAlert('Please agree to the terms and conditions.');
    } else {
        try {
            $checkStmt = $pdo->prepare(
                'SELECT id, role, is_verified FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1'
            );
            $checkStmt->execute([':email' => $email]);
            $existingUser = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingUser && strtolower(trim((string)($existingUser['role'] ?? ''))) !== 'customer') {
                registerAlert('This email belongs to a staff or administrator account. Please use the appropriate login page.');
            } elseif ($existingUser && registerDbBooleanTrue($existingUser['is_verified'] ?? false)) {
                registerAlert('Email is already registered. Please use the Login page.', 'login.php');
                exit;
            } else {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                if (!is_string($hashedPassword) || $hashedPassword === '') {
                    throw new RuntimeException('Unable to securely hash the account password.');
                }

                $otp = (string)random_int(100000, 999999);
                $existingUserId = $existingUser ? (int)$existingUser['id'] : 0;
                $newUserId = 0;

                if ($existingUserId > 0) {
                    $accountStmt = $pdo->prepare(
                        "UPDATE users
                         SET full_name = :full_name,
                             contact_number = :contact,
                             password = :password,
                             role = 'customer',
                             position = 'Customer',
                             user_category = :user_category,
                             otp_code = :otp,
                             is_verified = FALSE,
                             email_verified = FALSE
                         WHERE id = :id
                           AND LOWER(TRIM(COALESCE(role, ''))) = 'customer'
                           AND COALESCE(is_verified, FALSE) = FALSE"
                    );
                    $accountStmt->execute([
                        ':full_name' => $fullName,
                        ':contact' => $contact,
                        ':password' => $hashedPassword,
                        ':user_category' => $userCategory,
                        ':otp' => $otp,
                        ':id' => $existingUserId,
                    ]);
                    if ($accountStmt->rowCount() !== 1) {
                        throw new RuntimeException('The unverified customer account changed during registration.');
                    }
                } else {
                    $accountStmt = $pdo->prepare(
                        'INSERT INTO users (
                            full_name, contact_number, email, password, role, position,
                            user_category, otp_code, is_verified, email_verified
                         ) VALUES (
                            :full_name, :contact, :email, :password, :role, :position,
                            :user_category, :otp, FALSE, FALSE
                         ) RETURNING id'
                    );
                    $accountStmt->execute([
                        ':full_name' => $fullName,
                        ':contact' => $contact,
                        ':email' => $email,
                        ':password' => $hashedPassword,
                        ':role' => 'customer',
                        ':position' => 'Customer',
                        ':user_category' => $userCategory,
                        ':otp' => $otp,
                    ]);
                    $newUserId = (int)$accountStmt->fetchColumn();
                    if ($newUserId < 1) {
                        throw new RuntimeException('Registration did not return a user ID.');
                    }
                }

                try {
                    sendRegistrationOtpEmail(
                        $email,
                        $fullName,
                        $otp,
                        "Verify Your Account - Boogie's Pet Care",
                        "Welcome to Boogie's Pet Care!",
                        'Thank you for registering. To activate your account, please enter the 6-digit verification code below.'
                    );
                } catch (Throwable $mailError) {
                    // Only roll back this request's OTP; do not overwrite another newer challenge.
                    try {
                        if ($newUserId > 0) {
                            $deleteUser = $pdo->prepare(
                                'DELETE FROM users WHERE id = :id AND is_verified = FALSE AND otp_code = :otp'
                            );
                            $deleteUser->execute([':id' => $newUserId, ':otp' => $otp]);
                        } elseif ($existingUserId > 0) {
                            $clearOtp = $pdo->prepare(
                                'UPDATE users SET otp_code = NULL WHERE id = :id AND is_verified = FALSE AND otp_code = :otp'
                            );
                            $clearOtp->execute([':id' => $existingUserId, ':otp' => $otp]);
                        }
                    } catch (Throwable $cleanupError) {
                        error_log('Registration cleanup error: ' . $cleanupError->getMessage());
                    }
                    throw $mailError;
                }

                $_SESSION['temp_email'] = $email;
                $_SESSION['register_otp_last_sent'] = time();
                $_SESSION['register_otp_resends'] = 0;
                registerAlert('Account created! We have sent a verification code to your email.', 'register.php');
                exit;
            }
        } catch (Throwable $e) {
            error_log('Registration OTP error: ' . $e->getMessage());
            unset($_SESSION['temp_email'], $_SESSION['register_otp_last_sent'], $_SESSION['register_otp_resends']);
            registerAlert('We could not complete registration or send the verification code. Please try again later.');
        }
    }
}
?>


<!DOCTYPE html>


<html lang="en">


<head>


    <meta charset="UTF-8">


    <meta name="viewport" content="width=device-width, initial-scale=1.0">


    <title>Register - Boogie's Pet Care</title>


    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">


    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">


    <style>
        :root {
            --brand-blue: #001f3f;
            --brand-blue-2: #0b3b66;
            --brand-yellow: #ffcc00;
            --brand-yellow-soft: #fff8dc;
            --page-bg: #f5f8fc;
            --white: #ffffff;
            --text: #17324d;
            --muted: #6b7c8f;
            --line: #d9e2eb;
            --danger: #b42332;
            --danger-bg: #fff0f1;
            --shadow: 0 22px 55px rgba(0, 0, 0, .22);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        html { width: 100%; min-height: 100%; overflow-x: hidden; }

        body {
            min-height: 100vh;
            min-height: 100dvh;
            width: 100%;
            padding: 76px 20px 34px;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            font-family: 'Poppins', sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at 8% 8%, rgba(255, 204, 0, .18), transparent 28%),
                radial-gradient(circle at 93% 82%, rgba(255, 204, 0, .10), transparent 24%),
                linear-gradient(135deg, #071a2d 0%, #0f2741 55%, #001f3f 100%);
            overflow-x: hidden;
        }

        body::before,
        body::after {
            content: '';
            position: fixed;
            border: 1px solid rgba(255, 204, 0, .16);
            border-radius: 50%;
            pointer-events: none;
            z-index: 0;
        }
        body::before { width: 390px; height: 390px; top: -215px; right: -120px; }
        body::after { width: 300px; height: 300px; bottom: -205px; left: -125px; }

        .back-nav {
            position: absolute;
            top: 25px;
            left: 26px;
            z-index: 2;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #ffffff;
            text-decoration: none;
            font-size: 12px;
            font-weight: 700;
            transition: color .2s ease, opacity .2s ease;
        }
        .back-nav i { color: var(--brand-yellow); }
        .back-nav:hover { color: var(--brand-yellow); }

        .register-card {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 540px;
            margin: 12px auto 0;
            padding: 38px 38px 30px;
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, .75);
            border-top: 5px solid var(--brand-yellow);
            background: var(--white);
            box-shadow: var(--shadow);
            text-align: center;
        }

        .user-icon {
            width: 62px;
            height: 62px;
            margin: 0 auto 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #ffe28a;
            border-radius: 18px;
            background: var(--brand-yellow-soft);
            color: var(--brand-blue);
            font-size: 25px;
        }

        h2 {
            color: var(--brand-blue);
            font-size: 28px;
            line-height: 1.2;
            font-weight: 800;
            letter-spacing: -.35px;
            margin-bottom: 6px;
        }
        .subtitle {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.65;
            margin-bottom: 24px;
        }

        .form-group { margin-bottom: 16px; text-align: left; }
        label {
            display: block;
            margin-bottom: 7px;
            color: var(--brand-blue);
            font-size: 11px;
            font-weight: 800;
        }
        label span { color: #d92d20; }

        input:not([type="checkbox"]),
        select {
            display: block;
            width: 100%;
            max-width: 100%;
            min-height: 47px;
            padding: 12px 14px;
            border: 1px solid var(--line);
            border-radius: 11px;
            outline: none;
            background: #fbfcfe;
            color: var(--text);
            font-family: inherit;
            font-size: 12px;
            transition: border-color .2s ease, box-shadow .2s ease, background .2s ease;
        }
        input::placeholder { color: #98a6b5; }
        input:focus, select:focus {
            border-color: var(--brand-yellow);
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(255, 204, 0, .20);
        }

        .terms-box {
            margin: 4px 0 20px;
            padding: 15px;
            border: 1px solid #ffe28a;
            border-radius: 12px;
            background: #fffaf0;
            text-align: left;
        }
        .terms-box h4 {
            margin-bottom: 6px;
            color: var(--brand-blue);
            font-size: 11px;
            font-weight: 800;
        }
        .terms-box p {
            margin-bottom: 11px;
            color: var(--muted);
            font-size: 10px;
            line-height: 1.55;
        }
        .checkbox-row {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            color: var(--text);
            font-size: 11px;
            font-weight: 600;
            line-height: 1.5;
        }
        .checkbox-row input {
            width: 16px;
            height: 16px;
            flex: 0 0 16px;
            margin-top: 2px;
            accent-color: var(--brand-blue);
        }
        .checkbox-row label { margin: 0; font-size: 11px; line-height: 1.5; }

        .btn-submit {
            display: block;
            width: 100%;
            min-height: 48px;
            margin-top: 8px;
            padding: 13px 16px;
            border: 1px solid var(--brand-blue);
            border-radius: 11px;
            background: var(--brand-blue);
            color: var(--brand-yellow);
            font-family: inherit;
            font-size: 12px;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 8px 18px rgba(0, 31, 63, .16);
            transition: transform .18s ease, background .18s ease, box-shadow .18s ease;
        }
        .btn-submit:hover { background: var(--brand-blue-2); transform: translateY(-1px); box-shadow: 0 10px 22px rgba(0, 31, 63, .21); }
        .btn-submit:focus-visible, .google-btn:focus-visible, .back-nav:focus-visible, .login-link a:focus-visible {
            outline: 3px solid rgba(255, 204, 0, .85);
            outline-offset: 3px;
        }

        .divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 20px 0;
            color: #8996a5;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 1px;
        }
        .divider::before, .divider::after { content: ''; flex: 1; border-bottom: 1px solid #e8edf2; }
        .divider span { padding: 0 1px; }

        /* Keep Google's G mark in its native brand colors while matching the site accents. */
        .google-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            min-height: 48px;
            padding: 12px 14px;
            border: 1px solid #d9e2eb;
            border-radius: 11px;
            background: #ffffff;
            color: var(--brand-blue);
            font-family: inherit;
            font-size: 12px;
            font-weight: 800;
            cursor: pointer;
            transition: border-color .2s ease, background .2s ease, transform .2s ease;
        }
        .google-btn img { display: block; width: 20px; height: 20px; flex: 0 0 20px; }
        .google-btn:hover:not(:disabled) { border-color: var(--brand-yellow); background: #fffdf4; transform: translateY(-1px); }
        .google-btn:disabled { opacity: .65; cursor: wait; }

        .login-link {
            margin-top: 21px;
            color: var(--muted);
            font-size: 11px;
            line-height: 1.6;
        }
        .login-link a { color: var(--brand-blue); text-decoration: none; font-weight: 800; }
        .login-link a:hover { color: #946c00; text-decoration: underline; }

        .otp-input {
            min-height: 58px !important;
            text-align: center;
            font-size: 24px !important;
            font-weight: 800;
            letter-spacing: 7px;
        }

        @media (max-width: 600px) {
            body { padding: 66px 13px 24px; align-items: flex-start; }
            .back-nav { top: 17px; left: 15px; right: 15px; font-size: 11px; }
            .register-card { margin: 0 auto; max-width: 500px; padding: 29px 21px 24px; border-radius: 17px; }
            .user-icon { width: 56px; height: 56px; border-radius: 16px; font-size: 23px; margin-bottom: 13px; }
            h2 { font-size: 24px; }
            .subtitle { font-size: 11px; margin-bottom: 21px; }
            .form-group { margin-bottom: 14px; }
            label { font-size: 11px; }
            input:not([type="checkbox"]), select { min-height: 48px; padding: 12px 13px; font-size: 14px; }
            .terms-box { padding: 13px; margin-bottom: 18px; }
            .terms-box p { font-size: 10px; }
            .checkbox-row, .checkbox-row label { font-size: 10.5px; }
            .btn-submit, .google-btn { min-height: 49px; font-size: 12px; }
            .divider { margin: 17px 0; }
            .login-link { margin-top: 18px; font-size: 11px; }
        }

        @media (max-width: 380px) {
            body { padding: 62px 9px 18px; }
            .register-card { padding: 25px 15px 20px; border-radius: 15px; }
            h2 { font-size: 22px; }
            .subtitle { font-size: 10px; }
            .otp-input { font-size: 22px !important; letter-spacing: 5px; }
            .terms-box h4 { font-size: 10px; }
            .terms-box p, .checkbox-row, .checkbox-row label { font-size: 10px; }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { transition: none !important; scroll-behavior: auto !important; }
        }


        /* Shared Login/Register OTP verification layout */
        body.otp-screen {
            min-height: 100vh;
            min-height: 100dvh;
            padding: 72px 20px 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow-x: hidden;
            background:
                radial-gradient(circle at 8% 8%, rgba(255, 204, 0, .13), transparent 30%),
                radial-gradient(circle at 92% 90%, rgba(27, 86, 126, .34), transparent 32%),
                linear-gradient(145deg, #061a2e 0%, #0b3150 56%, #001f3f 100%);
        }

        body.otp-screen .back-nav,
        body.otp-screen .back-home {
            position: absolute;
            top: 25px;
            left: 26px;
            right: auto;
            z-index: 2;
            color: #ffcc00;
            text-decoration: none;
            font-size: 12px;
            font-weight: 800;
        }

        body.otp-screen .back-nav i,
        body.otp-screen .back-home i { color: #ffcc00; }

        body.otp-screen .register-card,
        body.otp-screen .login-card {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 420px;
            margin: 0 auto;
            padding: 38px 34px 30px;
            border: 1px solid rgba(255, 255, 255, .78);
            border-top: 5px solid #ffcc00;
            border-bottom: 0;
            border-radius: 20px;
            background: #ffffff;
            box-shadow: 0 20px 50px rgba(0, 0, 0, .25);
            text-align: center;
        }

        body.otp-screen .user-icon,
        body.otp-screen .login-icon {
            width: 62px;
            height: 62px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 14px;
            border: 1px solid #ffe28a;
            border-radius: 18px;
            background: #fff7cf;
            color: #001f3f;
            font-size: 25px;
        }

        body.otp-screen h2,
        body.otp-screen .register-card h2,
        body.otp-screen .login-card h2 {
            margin: 0 0 5px;
            color: #001f3f;
            font-size: 26px;
            line-height: 1.2;
            font-weight: 800;
            letter-spacing: -.25px;
        }

        body.otp-screen .branch-tag,
        body.otp-screen .verification-tag {
            display: block;
            margin: 0 0 20px;
            color: #64748b;
            font-size: 10px;
            line-height: 1.4;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        body.otp-screen .login-card p,
        body.otp-screen .register-card .verification-copy,
        body.otp-screen .register-card .subtitle {
            margin: 0 0 20px;
            color: #64748b;
            font-size: 12px;
            line-height: 1.6;
        }

        body.otp-screen .form-group { margin: 0 0 14px; text-align: left; }

        body.otp-screen input.otp-input {
            display: block;
            width: 100%;
            min-height: 56px;
            margin: 0;
            padding: 12px 14px;
            border: 1px solid #d9e2eb;
            border-radius: 11px;
            outline: none;
            background: #fbfcfe;
            color: #001f3f;
            font-family: inherit;
            font-size: 23px;
            line-height: 1.3;
            font-weight: 800;
            letter-spacing: 6px;
            text-align: center;
            box-shadow: none;
        }

        body.otp-screen input.otp-input:focus {
            border-color: #ffcc00;
            background: #fffdf5;
            box-shadow: 0 0 0 3px rgba(255, 204, 0, .18);
        }

        body.otp-screen .login-btn,
        body.otp-screen .btn-submit {
            display: block;
            width: 100%;
            min-height: 50px;
            margin: 0;
            padding: 13px 14px;
            border: 1px solid #001f3f;
            border-radius: 10px;
            background: #001f3f;
            color: #ffcc00;
            font-family: inherit;
            font-size: 13px;
            line-height: 1.3;
            font-weight: 800;
            text-transform: uppercase;
            cursor: pointer;
            box-shadow: 0 8px 20px rgba(0, 31, 63, .18);
        }

        body.otp-screen .google-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            width: 100%;
            min-height: 48px;
            margin: 0;
            padding: 12px 14px;
            border: 1px solid #d9e2eb;
            border-radius: 10px;
            background: #ffffff;
            color: #334155;
            font-family: inherit;
            font-size: 12px;
            line-height: 1.4;
            font-weight: 700;
            cursor: pointer;
        }

        body.otp-screen .google-btn:hover {
            border-color: #ffcc00;
            background: #fffdf5;
        }

        body.otp-screen .register-link,
        body.otp-screen .login-link {
            margin-top: 20px;
            color: #64748b;
            font-size: 11px;
            line-height: 1.6;
        }

        body.otp-screen .register-link a,
        body.otp-screen .login-link a {
            color: #001f3f;
            font-weight: 800;
            text-decoration: none;
            border-bottom: 2px solid #ffcc00;
        }

        @media (max-width: 600px) {
            body.otp-screen {
                align-items: flex-start;
                padding: 72px 14px 24px;
            }
            body.otp-screen .back-nav,
            body.otp-screen .back-home {
                top: 16px;
                left: 14px;
                right: 14px;
                font-size: 12px;
                padding: 8px 0;
            }
            body.otp-screen .register-card,
            body.otp-screen .login-card {
                width: 100%;
                max-width: 420px;
                margin: 0 auto;
                padding: 30px 20px 24px;
                border-radius: 17px;
            }
            body.otp-screen .user-icon,
            body.otp-screen .login-icon {
                width: 55px;
                height: 55px;
                margin-bottom: 12px;
                border-radius: 16px;
                font-size: 22px;
            }
            body.otp-screen h2,
            body.otp-screen .register-card h2,
            body.otp-screen .login-card h2 { font-size: 23px; }
            body.otp-screen input.otp-input {
                min-height: 54px;
                font-size: 22px;
                letter-spacing: 5px;
            }
            body.otp-screen .login-btn,
            body.otp-screen .btn-submit,
            body.otp-screen .google-btn { min-height: 49px; font-size: 12px; }
        }

        @media (max-width: 380px) {
            body.otp-screen { padding-left: 10px; padding-right: 10px; }
            body.otp-screen .register-card,
            body.otp-screen .login-card { padding: 26px 15px 21px; }
            body.otp-screen h2,
            body.otp-screen .register-card h2,
            body.otp-screen .login-card h2 { font-size: 21px; }
            body.otp-screen input.otp-input { font-size: 21px; letter-spacing: 4px; }
        }

    </style>


</head>


<body class="<?php echo isset($_SESSION['temp_email']) ? 'otp-screen' : 'registration-screen'; ?>">


    <a href="index.php" class="back-nav"><i class="fa-solid fa-arrow-left"></i> Back to Home</a>


    <div class="register-card">


        <?php if (isset($_SESSION['temp_email'])): ?>


            <div class="user-icon"><i class="fa-solid fa-shield-halved"></i></div>


            <h2>Verify Account</h2>

            <span class="verification-tag">Security Check</span>

            <p class="verification-copy">Please enter the 6-digit code sent to your email address to continue.</p>


            <form action="register.php" method="POST">

                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($register_csrf_token, ENT_QUOTES, 'UTF-8'); ?>">


                <div class="form-group">


                    <input type="text" name="otp_code" class="otp-input" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" required placeholder="000000" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6)">


                </div>


                <button type="submit" name="verify_btn" class="btn-submit">Verify Now</button>


            </form>

            <form action="register.php" method="POST" style="margin-top:12px;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($register_csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" name="resend_register_otp_btn" class="google-btn" style="margin-top:0;">
                    <i class="fa-solid fa-rotate-right" aria-hidden="true"></i>
                    Resend Verification Code
                </button>
            </form>


            <div class="login-link">


                Wrong email? <a href="logout.php">Start Over</a>


            </div>


        <?php else: ?>


            <div class="user-icon"><i class="fa-solid fa-user-plus"></i></div>


            <h2>Create Account</h2>


            <p class="subtitle">Join Boogie's Pet Care &amp; Services</p>


            <form action="register.php" method="POST">

                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($register_csrf_token, ENT_QUOTES, 'UTF-8'); ?>">


                <div class="form-group">


                    <label>Full Name <span>*</span></label>


                    <input type="text" name="full_name" placeholder="Enter your full name" required maxlength="150" autocomplete="name">


                </div>


                <div class="form-group">


                    <label>Contact Number <span>*</span></label>


                    <input type="tel" name="contact" inputmode="numeric" autocomplete="tel" placeholder="09XXXXXXXXX" required maxlength="11" pattern="09[0-9]{9}" title="Must start with 09 and be exactly 11 digits" oninput="this.value = this.value.replace(/[^0-9]/g, '')">


                </div>


                <div class="form-group">


                    <label>Email Address <span>*</span></label>


                    <input type="email" name="email" autocomplete="email" placeholder="Enter your email" required maxlength="254">


                </div>


                <div class="form-group">


                    <label>I am registering as a <span>*</span></label>


                    <select name="user_category" required>


                        <option value="" disabled selected>Select Category</option>


                        <option value="Pet Owner">Pet Owner</option>


                        <option value="Pet Breeder">Pet Breeder</option>


                    </select>


                </div>


                <div class="form-group">


                    <label>Password <span>*</span></label>


                    <input type="password" name="password" autocomplete="new-password" placeholder="Create a password" required minlength="8" pattern="(?=.*[A-Z]).{8,}" title="Must contain at least 8 characters, including at least 1 uppercase letter">


                </div>


                <div class="form-group">


                    <label>Confirm Password <span>*</span></label>


                    <input type="password" name="confirm_password" autocomplete="new-password" placeholder="Confirm your password" required minlength="8">


                </div>


                <div class="terms-box">


                    <h4>Terms, Consent, and Privacy Policy</h4>


                    <p>By creating an account, you agree to our terms of service and privacy policy.</p>


                    <div class="checkbox-row">


                        <input type="checkbox" id="agree" name="terms_agree" required>


                        <label for="agree" style="margin-bottom: 0;">I agree to the terms and conditions *</label>


                    </div>


                </div>


                <button type="submit" name="register_btn" class="btn-submit">Create Account</button>


            </form>


            <div class="divider"><span>OR</span></div>


            <button id="googleLoginBtn" class="google-btn" type="button" aria-label="Sign up with Google">


                <img src="https://upload.wikimedia.org/wikipedia/commons/c/c1/Google_%22G%22_logo.svg" alt="Google Logo" width="20">


                Sign up with Google


            </button>


            <div class="login-link">


                Already have an account? <a href="login.php">Login here</a>


            </div>


        <?php endif; ?>


    </div>


    <script type="module">

        import { initializeApp } from "https://www.gstatic.com/firebasejs/13.0.0/firebase-app.js";

        import {

            getAuth,

            signInWithPopup,

            GoogleAuthProvider

        } from "https://www.gstatic.com/firebasejs/13.0.0/firebase-auth.js";


        const firebaseConfig = {

            apiKey: "AIzaSyDhJFrsb9HgQRC7uUEoMdn1TA23mOZmo5M",

            authDomain: "boogiespetcare.firebaseapp.com",

            projectId: "boogiespetcare",

            storageBucket: "boogiespetcare.firebasestorage.app",

            messagingSenderId: "181436323114",

            appId: "1:181436323114:web:49f3cf12298256fd1f7a53",

            measurementId: "G-4ZCN6N283T"

        };


        const app = initializeApp(firebaseConfig);

        const auth = getAuth(app);

        const provider = new GoogleAuthProvider();

        provider.setCustomParameters({ prompt: "select_account" });


        const googleButton = document.getElementById("googleLoginBtn");

        let googleSignupInProgress = false;

        const originalButtonContent = googleButton ? googleButton.innerHTML : "";


        function setButtonBusy(isBusy) {

            if (!googleButton) return;

            googleButton.disabled = isBusy;

            googleButton.style.opacity = isBusy ? "0.7" : "";

            googleButton.style.cursor = isBusy ? "wait" : "";

            googleButton.innerHTML = isBusy

                ? '<i class="fa-solid fa-spinner fa-spin"></i> Connecting to Google...'

                : originalButtonContent;

        }


        function showGoogleError(error) {
            console.error("Register Google Sign-In failed:", error);
            let message = "Google sign-up could not be completed. Please try again.";

            switch (error?.code) {
                case "auth/popup-closed-by-user":
                    message = "Google sign-up was cancelled. You can try again when ready.";
                    break;
                case "auth/popup-blocked":
                    message = "The Google sign-in popup was blocked. Allow popups for this website and try again.";
                    break;
                case "auth/cancelled-popup-request":
                    message = "A Google sign-in request is already open. Finish or close it, then try again.";
                    break;
                case "auth/unauthorized-domain":
                    message = "This website domain is not authorized in Firebase. Add the current hostname under Firebase Console > Authentication > Settings > Authorized domains.";
                    break;
                case "auth/account-exists-with-different-credential":
                    message = "An account already exists with this email using another sign-in method. Please use the existing account login.";
                    break;
                case "auth/network-request-failed":
                    message = "Could not connect to Google. Check your internet connection and try again.";
                    break;
                default:
                    if (error?.message) message += "\n\n" + error.message;
            }

            alert("Google Sign-in Error: " + message);
        }


        async function authenticateWithBackend(firebaseUser) {

            if (!firebaseUser || typeof firebaseUser.getIdToken !== "function") {
                throw new Error("Google authentication did not return a valid Firebase user. Please try again.");
            }

            const idToken = await firebaseUser.getIdToken(true);
            if (!idToken || typeof idToken !== "string") {
                throw new Error("Could not retrieve the Google verification token. Please try again.");
            }

            const registerCsrfToken = <?php echo json_encode($register_csrf_token, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            const formBody = new URLSearchParams({
                google_register_btn: "1",
                csrf_token: registerCsrfToken,
                id_token: idToken
            });

            const response = await fetch("register.php", {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8"
                },
                body: formBody.toString()
            });


            let data;

            try {

                data = await response.json();

            } catch (parseError) {

                throw new Error("The server did not return a valid response. Check the PHP/Vercel logs.");

            }


            if (!response.ok || !data || data.success !== true) {

                throw new Error(data?.message || `Google sign-up failed (HTTP ${response.status}).`);

            }


            // This keeps Google sign-up in register.php's own OTP flow.
            const redirect = typeof data.redirect === "string" && data.redirect !== ""
                ? data.redirect
                : "register.php";

            window.location.assign(redirect);

        }


        if (googleButton) {

            googleButton.addEventListener("click", async (event) => {

                event.preventDefault();

                if (googleSignupInProgress) return;


                googleSignupInProgress = true;

                setButtonBusy(true);


                try {

                    const result = await signInWithPopup(auth, provider);

                    const user = result?.user;

                    if (!user?.email) {

                        throw new Error("Google did not provide an email address. Please choose a Google account with an email.");

                    }

                    await authenticateWithBackend(user);

                } catch (error) {

                    showGoogleError(error);

                    googleSignupInProgress = false;

                    setButtonBusy(false);

                }

            });

        } else {

            console.warn("Google sign-up button was not found on register.php.");

        }

    </script>


</body>


</html>
