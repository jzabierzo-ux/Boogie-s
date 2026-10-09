<?php
session_start();

header('Content-Type: application/json; charset=UTF-8');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/PHPMailer/src/Exception.php';
require __DIR__ . '/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/PHPMailer/src/SMTP.php';

$mail = null;
$created_user_id = 0;
$otp = null;
$email = '';

function jsonResponse(bool $success, string $message, ?string $redirect = null, int $status = 200): void
{
    http_response_code($status);
    $payload = ['success' => $success, 'message' => $message];
    if ($redirect !== null) {
        $payload['redirect'] = $redirect;
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require __DIR__ . '/db_supabase.php';

    if (!defined('SMTP_EMAIL')) {
        define('SMTP_EMAIL', getenv('SMTP_EMAIL') ?: 'prototyp6712@gmail.com');
    }
    if (!defined('SMTP_PASS')) {
        define('SMTP_PASS', getenv('SMTP_PASS') ?: '');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'Invalid request method.', null, 405);
    }

    // Replace any stale in-progress login challenge before starting a new one.
    unset($_SESSION['login_temp_email']);

    $full_name = trim((string)($_POST['full_name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));

    if ($full_name === '' || $email === '') {
        jsonResponse(false, 'Name and email are required.', null, 400);
    }
    if (strlen($full_name) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        jsonResponse(false, 'Please provide a valid name and email address.', null, 400);
    }

    // Do not allow this customer login endpoint to reset OTPs for staff/admin accounts.
    $find = $pdo->prepare('
        SELECT id, email, full_name, role, is_verified
        FROM users
        WHERE LOWER(email) = LOWER(:email)
        LIMIT 1
    ');
    $find->execute([':email' => $email]);
    $user = $find->fetch(PDO::FETCH_ASSOC);

    $otp = (string)random_int(100000, 999999);

    if ($user) {
        if (strtolower(trim((string)($user['role'] ?? ''))) !== 'customer') {
            jsonResponse(false, 'This account cannot use customer Google login. Please use the appropriate login page.', null, 403);
        }

        $created_user_id = (int)$user['id'];
        $mail_name = trim((string)($user['full_name'] ?? '')) ?: $full_name;
        $email = strtolower(trim((string)($user['email'] ?? $email)));

        $update = $pdo->prepare('UPDATE users SET otp_code = :otp WHERE id = :id');
        $update->execute([':otp' => $otp, ':id' => $created_user_id]);
        $is_new_user = false;
    } else {
        $generated_password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

        $insert = $pdo->prepare('
            INSERT INTO users (
                full_name, contact_number, email, password, role, position,
                user_category, otp_code, is_verified
            )
            VALUES (
                :full_name, :contact_number, :email, :password, :role, :position,
                :user_category, :otp, FALSE
            )
            RETURNING id
        ');
        $insert->execute([
            ':full_name' => $full_name,
            ':contact_number' => 'Not Provided',
            ':email' => $email,
            ':password' => $generated_password,
            ':role' => 'customer',
            ':position' => 'Customer',
            ':user_category' => 'Pet Owner',
            ':otp' => $otp
        ]);
        $created_user_id = (int)$insert->fetchColumn();
        $mail_name = $full_name;
        $is_new_user = true;
    }

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = SMTP_EMAIL;
    $mail->Password = preg_replace('/\s+/', '', SMTP_PASS);
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;
    $mail->Timeout = 20;
    $mail->CharSet = 'UTF-8';

    $mail->setFrom(SMTP_EMAIL, "Boogie's Pet Care");
    $mail->addAddress($email, $mail_name);
    $mail->isHTML(true);
    $mail->Subject = "Google Login Verification - Boogie's Pet Care";

    $safe_name = htmlspecialchars($mail_name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $mail->Body = "
        <div style='font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:20px;border:1px solid #e2e8f0;border-radius:10px;background:#ffffff;'>
            <h2 style='color:#001f3f;text-align:center;'>2-Step Verification</h2>
            <p>Hi {$safe_name},</p>
            <p>We detected a Google Sign-In attempt.</p>
            <p>To complete your login, please enter this verification code:</p>
            <h1 style='background:#f4f7fe;padding:15px;text-align:center;color:#1d63ff;letter-spacing:5px;border-radius:8px;'>{$otp}</h1>
            <p style='font-size:12px;color:#64748b;text-align:center;margin-top:30px;'>© Boogie's Pet Care &amp; Services</p>
        </div>
    ";
    $mail->AltBody = "Hi {$mail_name}, your Boogie's Pet Care verification code is {$otp}.";
    $mail->send();

    // This key must match the verification handler used by login.php.
    $_SESSION['login_temp_email'] = $email;

    jsonResponse(true, 'Verification code sent to your email.', 'login.php');
} catch (Throwable $e) {
    error_log('Google login OTP error: ' . $e->getMessage());

    // Clear this attempt's OTP if email delivery failed. If we created a new, unverified
    // account for this request, remove it so a failed email does not leave a dead account.
    if (isset($pdo) && $pdo instanceof PDO && $created_user_id > 0 && $otp !== null) {
        try {
            if (!empty($is_new_user)) {
                $cleanup = $pdo->prepare('DELETE FROM users WHERE id = :id AND is_verified = FALSE AND otp_code = :otp');
                $cleanup->execute([':id' => $created_user_id, ':otp' => $otp]);
            } else {
                $cleanup = $pdo->prepare('UPDATE users SET otp_code = NULL WHERE id = :id AND otp_code = :otp');
                $cleanup->execute([':id' => $created_user_id, ':otp' => $otp]);
            }
        } catch (Throwable $cleanupError) {
            error_log('Google login OTP cleanup error: ' . $cleanupError->getMessage());
        }
    }

    // Do not expose SMTP/server error details to clients.
    jsonResponse(false, 'We could not send a verification code right now. Please try again later.', null, 500);
}
?>
