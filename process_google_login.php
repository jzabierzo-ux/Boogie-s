<?php
session_start();

header('Content-Type: application/json; charset=UTF-8');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/PHPMailer/src/Exception.php';
require __DIR__ . '/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/PHPMailer/src/SMTP.php';

try {
    require __DIR__ . '/db_supabase.php';

    if (!defined('SMTP_EMAIL')) {
        define('SMTP_EMAIL', getenv('SMTP_EMAIL') ?: 'prototyp6712@gmail.com');
    }

    if (!defined('SMTP_PASS')) {
    define('SMTP_PASS', getenv('SMTP_PASS') ?: '');
}

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid request method.'
        ]);
        exit();
    }

    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if ($full_name === '' || $email === '') {
        echo json_encode([
            'success' => false,
            'message' => 'Name and email are required.'
        ]);
        exit();
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid email address.'
        ]);
        exit();
    }

    $otp = random_int(100000, 999999);

    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if ($user) {
        $update = $pdo->prepare('UPDATE users SET otp_code = :otp WHERE id = :id');
        $update->execute([
            ':otp' => $otp,
            ':id' => $user['id']
        ]);
    } else {
        $generated_password = password_hash(
            bin2hex(random_bytes(16)),
            PASSWORD_DEFAULT
        );

        $insert = $pdo->prepare('
            INSERT INTO users (
                full_name,
                contact_number,
                email,
                password,
                role,
                position,
                user_category,
                otp_code,
                is_verified
            )
            VALUES (
                :full_name,
                :contact_number,
                :email,
                :password,
                :role,
                :position,
                :user_category,
                :otp,
                0
            )
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
    }

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = SMTP_EMAIL;
    $mail->Password = SMTP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;
    $mail->Timeout = 20;
    $mail->CharSet = 'UTF-8';

    $mail->setFrom(SMTP_EMAIL, "Boogie's Pet Care");
    $mail->addAddress($email, $full_name);
    $mail->isHTML(true);
    $mail->Subject = "Google Login Verification - Boogie's Pet Care";

    $safe_name = htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8');

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

    $mail->send();

    // This key matches login.php's verification page.
    $_SESSION['login_temp_email'] = $email;

    echo json_encode([
        'success' => true,
        'redirect' => 'login.php'
    ]);
    exit();

} catch (Throwable $e) {

    $smtpError = isset($mail) ? $mail->ErrorInfo : $e->getMessage();

    echo json_encode([
        "success" => false,
        "message" => "SMTP ERROR: " . $smtpError
    ]);

    exit();
}

?>
