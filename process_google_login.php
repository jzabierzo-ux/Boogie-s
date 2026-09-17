<?php
session_start();
include 'db_connect.php'; 

// --- BAGO: EMAIL CREDENTIALS SETUP ---
// Sinigurado nating nandidito yung email at app password para sa Google Login part
if (!defined('SMTP_EMAIL')) define('SMTP_EMAIL', 'nikylepresentacion@gmail.com'); 
if (!defined('SMTP_PASS')) define('SMTP_PASS', 'myzidcrtrnndddnn'); 

// TAWAGIN ANG PHPMAILER CLASSES
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// AYUSIN ANG PATH DEPENDE KUNG SAAN NAKALAGAY ANG PHPMAILER FOLDER MO
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

header('Content-Type: application/json');

if (isset($_POST['email']) && isset($_POST['full_name'])) {
    
    $full_name = mysqli_real_escape_string($conn, $_POST['full_name']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);

    // 1. GENERATE 6-DIGIT OTP
    $otp = rand(100000, 999999);

    // 2. CHECK KUNG EXISTING NA SA DATABASE
    $check = mysqli_query($conn, "SELECT * FROM users WHERE email = '$email'");

    if (mysqli_num_rows($check) > 0) {
        $user = mysqli_fetch_assoc($check);
        mysqli_query($conn, "UPDATE users SET otp_code = '$otp' WHERE id = " . $user['id']);
    } else {
        $contact = "Not Provided"; 
        $pass = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT); 
        $position = "Customer"; 
        $category = "Pet Owner"; 
        
        $insert = "INSERT INTO users (full_name, contact_number, email, password, role, position, user_category, otp_code, is_verified) 
                   VALUES ('$full_name', '$contact', '$email', '$pass', 'customer', '$position', '$category', '$otp', 0)";
        mysqli_query($conn, $insert);
    }

    // 3. SEND OTP VIA PHPMAILER
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        
        $mail->Username   = SMTP_EMAIL; 
        $mail->Password   = SMTP_PASS; 
        
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // --- BAGO: XAMPP SSL Bypass ---
        // Pampalusot sa local server para hindi mag-error ang PHPMailer
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );

        $mail->setFrom(SMTP_EMAIL, "Boogie's Pet Care");
        $mail->addAddress($email, $full_name);
        $mail->isHTML(true);
        $mail->Subject = 'Google Login Verification - Boogie\'s Pet Care';
        $mail->Body    = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 10px;'>
                <h2 style='color: #001f3f; text-align: center;'>2-Step Verification</h2>
                <p>Hi $full_name,</p>
                <p>We detected a Google Sign-In attempt. To complete your login, please enter this verification code:</p>
                <h1 style='background: #f4f7fe; padding: 15px; text-align: center; color: #1d63ff; letter-spacing: 5px; border-radius: 8px;'>$otp</h1>
                <p style='font-size: 12px; color: #64748b; text-align: center; margin-top: 30px;'>© Boogie's Pet Care & Services</p>
            </div>";

        $mail->send();

        // 4. I-REDIRECT SI USER SA OTP SCREEN
        $_SESSION['temp_email'] = $email;
        echo json_encode(["success" => true, "redirect" => "register.php"]);
        exit();
        
    } catch (Exception $e) {
        $_SESSION['temp_email'] = $email;
        echo json_encode(["success" => true, "redirect" => "register.php", "message" => "Mail failed but redirected for testing. OTP is: $otp"]);
        exit();
    }

} else {
    echo json_encode(["success" => false, "message" => "No data received from Google."]);
    exit();
}
?>