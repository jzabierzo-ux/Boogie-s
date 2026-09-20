<?php
session_start();

include 'db_supabase.php';

// ============================================================
// EMAIL CREDENTIALS
// IMPORTANT: Huwag ilagay ang actual password sa GitHub.
// ============================================================

if (!defined('SMTP_EMAIL')) {
    define('SMTP_EMAIL', 'prototyp6712@gmail.com');
}

if (!defined('SMTP_PASS')) {
    define('SMTP_PASS', 'jwkvmplgbfuxlwdr');
}

// ============================================================
// PHPMailer
// ============================================================

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

header('Content-Type: application/json; charset=UTF-8');

// ============================================================
// CHECK GOOGLE LOGIN DATA
// ============================================================

if (!isset($_POST['email']) || !isset($_POST['full_name'])) {
    echo json_encode([
        "success" => false,
        "message" => "No data received from Google."
    ]);
    exit();
}

$full_name = trim($_POST['full_name']);
$email = trim($_POST['email']);

if ($full_name === '' || $email === '') {
    echo json_encode([
        "success" => false,
        "message" => "Name and email are required."
    ]);
    exit();
}

// ============================================================
// GENERATE 6-DIGIT OTP
// ============================================================

$otp = random_int(100000, 999999);

// ============================================================
// CHECK IF USER ALREADY EXISTS
// ============================================================

$stmt = $pdo->prepare("
    SELECT *
    FROM users
    WHERE email = :email
    LIMIT 1
");

$stmt->execute([
    ':email' => $email
]);

$user = $stmt->fetch();

// ============================================================
// EXISTING USER
// ============================================================

if ($user) {

    $update = $pdo->prepare("
        UPDATE users
        SET otp_code = :otp
        WHERE id = :id
    ");

    $update->execute([
        ':otp' => $otp,
        ':id' => $user['id']
    ]);

} else {

    // ========================================================
    // NEW GOOGLE USER
    // ========================================================

    $contact = "Not Provided";
    $generated_password = password_hash(
        bin2hex(random_bytes(8)),
        PASSWORD_DEFAULT
    );

    $position = "Customer";
    $category = "Pet Owner";

    $insert = $pdo->prepare("
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
            :otp_code,
            :is_verified
        )
    ");

    $insert->execute([
        ':full_name' => $full_name,
        ':contact_number' => $contact,
        ':email' => $email,
        ':password' => $generated_password,
        ':role' => 'customer',
        ':position' => $position,
        ':user_category' => $category,
        ':otp_code' => $otp,
        ':is_verified' => 0
    ]);
}

// ============================================================
// SEND OTP VIA GMAIL
// ============================================================

$mail = new PHPMailer(true);

try {

    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;

    $mail->Username = SMTP_EMAIL;
    $mail->Password = SMTP_PASS;

    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;

    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ];

    $mail->setFrom(
        SMTP_EMAIL,
        "Boogie's Pet Care"
    );

    $mail->addAddress(
        $email,
        $full_name
    );

    $mail->isHTML(true);

    $mail->Subject = "Google Login Verification - Boogie's Pet Care";

    $mail->Body = "
        <div style='
            font-family: Arial, sans-serif;
            max-width: 600px;
            margin: auto;
            padding: 20px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
        '>

            <h2 style='
                color: #001f3f;
                text-align: center;
            '>
                2-Step Verification
            </h2>

            <p>
                Hi " . htmlspecialchars($full_name) . ",
            </p>

            <p>
                We detected a Google Sign-In attempt.
                To complete your login, please enter
                this verification code:
            </p>

            <h1 style='
                background: #f4f7fe;
                padding: 15px;
                text-align: center;
                color: #1d63ff;
                letter-spacing: 5px;
                border-radius: 8px;
            '>
                $otp
            </h1>

            <p style='
                font-size: 12px;
                color: #64748b;
                text-align: center;
                margin-top: 30px;
            '>
                © Boogie's Pet Care & Services
            </p>

        </div>
    ";

    $mail->send();

    // ========================================================
    // SAVE SESSION
    // ========================================================

    $_SESSION['temp_email'] = $email;

    echo json_encode([
        "success" => true,
        "redirect" => "register.php"
    ]);

    exit();

} catch (Exception $e) {

    echo json_encode([
        "success" => false,
        "message" => "Failed to send verification email."
    ]);

    exit();
}
?>