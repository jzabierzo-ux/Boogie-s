<?php
require_once __DIR__ . '/shared_session_bootstrap.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
require_once __DIR__ . '/db_supabase.php';

// ============================================================
// EMAIL SETTINGS
// ============================================================
// Keep SMTP credentials outside the source code.
// Set SMTP_EMAIL and SMTP_PASS in the local/server environment.
// ============================================================
define('SMTP_EMAIL', getenv('SMTP_EMAIL') ?: 'prototyp6712@gmail.com');
define('SMTP_PASS', getenv('SMTP_PASS') ?: '');
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require __DIR__ . '/PHPMailer/src/Exception.php';
require __DIR__ . '/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/PHPMailer/src/SMTP.php';

// Validate CSRF on login and OTP submissions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_csrf = (string)($_POST['csrf_token'] ?? '');
    $session_csrf = (string)($_SESSION['csrf_token'] ?? '');
    if ($posted_csrf === '' || $session_csrf === '' || !hash_equals($session_csrf, $posted_csrf)) {
        header('Location: login.php');
        exit();
    }
}


/** Return the correct separate login portal for privileged accounts, or null for customers. */
function customerLoginBlockedPortal(array $user): ?string
{
    $role = strtolower(trim((string)($user['role'] ?? '')));
    $role = preg_replace('/[^a-z]/', '', $role);
    if (in_array($role, ['admin', 'administrator'], true)) {
        return 'admin_login.php';
    }
    $personnel = [
        'manager', 'shopmanager', 'receptionist', 'frontdesk', 'groomer', 'groomingstaff',
        'pethotelstaff', 'pethotelstaffattendant', 'pethotelattendant', 'veterinarian', 'veterinary',
        'vet', 'vetassistant', 'veterinaryassistant', 'vetnurse', 'veterinarynurse', 'staff', 'supervisor'
    ];
    return in_array($role, $personnel, true) ? 'staff/stafflogin.php' : null;
}

// ============================================================
// RESEND LOGIN OTP
// ============================================================
if (isset($_POST['resend_login_otp_btn'])) {
    $email = trim((string)($_SESSION['login_temp_email'] ?? ''));
    if ($email === '') {
        echo "<script>alert('Your login session expired. Please sign in again.'); window.location='login.php';</script>";
        exit;
    }

    $lastSentAt = (int)($_SESSION['login_otp_last_sent'] ?? 0);
    if ($lastSentAt > 0 && (time() - $lastSentAt) < 30) {
        echo "<script>alert('Please wait 30 seconds before requesting another code.'); window.location='login.php';</script>";
        exit;
    }

    if ((int)($_SESSION['login_otp_resends'] ?? 0) >= 3) {
        echo "<script>alert('You have reached the resend limit. Please sign in again to request a new code.'); window.location='login.php';</script>";
        exit;
    }

    if (trim(SMTP_PASS) === '') {
        echo "<script>alert('Email service is not configured. Please try again later.'); window.location='login.php';</script>";
        exit;
    }

    try {
        $userStmt = $pdo->prepare('SELECT id, full_name FROM users WHERE email = :email LIMIT 1');
        $userStmt->execute([':email' => $email]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            unset($_SESSION['login_temp_email'], $_SESSION['login_temp_expires'], $_SESSION['login_otp_attempts'], $_SESSION['login_otp_resends'], $_SESSION['login_otp_last_sent']);
            echo "<script>alert('Account not found. Please sign in again.'); window.location='login.php';</script>";
            exit;
        }

        $new_otp = (string)random_int(100000, 999999);
        $updateOtp = $pdo->prepare('UPDATE users SET otp_code = :otp WHERE id = :id');
        $updateOtp->execute([':otp' => $new_otp, ':id' => $user['id']]);

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
        $mail->setFrom(SMTP_EMAIL, "Boogie's Pet Care Services");
        $mail->addAddress($email, (string)($user['full_name'] ?? 'Customer'));
        $mail->isHTML(true);
        $mail->Subject = "New Login Verification Code - Boogie's Pet Care";
        $safeName = htmlspecialchars((string)($user['full_name'] ?? 'Customer'), ENT_QUOTES, 'UTF-8');
        $mail->Body = "<div style='font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:20px;border:1px solid #e2e8f0;border-radius:10px;background:#fff'><h2 style='color:#001f3f;text-align:center'>Login Verification</h2><p>Hi {$safeName},</p><p>Your new login verification code is:</p><h1 style='background:#f4f7fe;padding:15px;text-align:center;color:#1d63ff;letter-spacing:5px;border-radius:8px'>{$new_otp}</h1><p>This code expires in 15 minutes. If you did not request it, you can ignore this email.</p></div>";
        $mail->AltBody = "Your new Boogie's Pet Care login verification code is {$new_otp}. It expires in 15 minutes.";
        $mail->send();

        $_SESSION['login_temp_expires'] = time() + 900;
        $_SESSION['login_otp_attempts'] = 0;
        $_SESSION['login_otp_resends'] = (int)($_SESSION['login_otp_resends'] ?? 0) + 1;
        $_SESSION['login_otp_last_sent'] = time();
        echo "<script>alert('A new verification code has been sent. It expires in 15 minutes.'); window.location='login.php';</script>";
        exit;
    } catch (Throwable $e) {
        try {
            $clearOtp = $pdo->prepare('UPDATE users SET otp_code = NULL WHERE email = :email');
            $clearOtp->execute([':email' => $email]);
        } catch (PDOException $cleanupError) {
            error_log('Resend OTP cleanup failed: ' . $cleanupError->getMessage());
        }
        error_log('Login OTP resend failed: ' . $e->getMessage());
        $_SESSION['login_temp_expires'] = 0;
        echo "<script>alert('Could not send a new verification code. Please try again later.'); window.location='login.php';</script>";
        exit;
    }
}

// ============================================================
// VERIFY LOGIN OTP
// ============================================================
if (isset($_POST['verify_login_btn'])) {
    $email = trim((string)($_SESSION['login_temp_email'] ?? ''));
    $entered_otp = trim((string)($_POST['otp_code'] ?? ''));
    $otp_expires_at = (int)($_SESSION['login_temp_expires'] ?? 0);
    if ($email === '' || $otp_expires_at <= time()) {
        if ($email !== '') {
            try {
                $clearOtp = $pdo->prepare('UPDATE users SET otp_code = NULL WHERE email = :email');
                $clearOtp->execute([':email' => $email]);
                // Keep the temporary email so the user can request a fresh code.
                $_SESSION['login_temp_expires'] = 0;
                unset($_SESSION['login_otp_attempts']);
            } catch (PDOException $e) {
                error_log('Expired login OTP cleanup failed: ' . $e->getMessage());
            }
        } else {
            unset($_SESSION['login_temp_email'], $_SESSION['login_temp_expires'], $_SESSION['login_otp_attempts'], $_SESSION['login_otp_resends'], $_SESSION['login_otp_last_sent']);
        }
        echo "<script>alert('Verification code expired. You can resend a new code or sign in again.'); window.location='login.php';</script>";
        exit;
    }
    if ((int)($_SESSION['login_otp_attempts'] ?? 0) >= 5) {
        try {
            $clearOtp = $pdo->prepare('UPDATE users SET otp_code = NULL WHERE email = :email');
            $clearOtp->execute([':email' => $email]);
        } catch (PDOException $e) {
            error_log('OTP attempt-limit cleanup failed: ' . $e->getMessage());
        }
        // Expire this code but let the user use the Resend Code action.
        $_SESSION['login_temp_expires'] = 0;
        unset($_SESSION['login_otp_attempts']);
        echo "<script>alert('Too many incorrect codes. Please resend a new code or sign in again.'); window.location='login.php';</script>";
        exit;
    }
    if (!preg_match('/^[0-9]{6}$/', $entered_otp)) {
        echo "<script>alert('Please enter the 6-digit verification code.');</script>";
    } else {
        $stmt = $pdo->prepare(
            'SELECT * FROM users WHERE email = :email AND otp_code = :otp LIMIT 1'
        );
        $stmt->execute([
            ':email' => $email,
            ':otp' => $entered_otp
        ]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $blocked_portal = customerLoginBlockedPortal($user);
            if ($blocked_portal !== null) {
                $clearOtp = $pdo->prepare('UPDATE users SET otp_code = NULL WHERE id = :id');
                $clearOtp->execute([':id' => $user['id']]);
                unset($_SESSION['login_temp_email'], $_SESSION['login_temp_expires'], $_SESSION['login_otp_attempts'], $_SESSION['login_otp_resends'], $_SESSION['login_otp_last_sent']);
                $portal_json = json_encode($blocked_portal, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
                echo "<script>alert('Please use the correct portal for this account.'); window.location=" . $portal_json . ";</script>";
                exit;
            }
            $stmt = $pdo->prepare(
                'UPDATE users
                 SET is_verified = 1,
                     email_verified = 1,
                     otp_code = NULL
                 WHERE id = :id'
            );
            $stmt->execute([':id' => $user['id']]);
            session_regenerate_id(true);
            $_SESSION['logged_in'] = true;
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['position'] = $user['position'] ?? null;
            unset($_SESSION['login_temp_email'], $_SESSION['login_temp_expires'], $_SESSION['login_otp_attempts'], $_SESSION['login_otp_resends'], $_SESSION['login_otp_last_sent']);
            header('Location: home.php');
            exit();
        }
        $_SESSION['login_otp_attempts'] = (int)($_SESSION['login_otp_attempts'] ?? 0) + 1;
        if ($_SESSION['login_otp_attempts'] >= 5) {
            try {
                $clearOtp = $pdo->prepare('UPDATE users SET otp_code = NULL WHERE email = :email');
                $clearOtp->execute([':email' => $email]);
            } catch (PDOException $e) {
                error_log('OTP attempt-limit cleanup failed: ' . $e->getMessage());
            }
            $_SESSION['login_temp_expires'] = 0;
            unset($_SESSION['login_otp_attempts']);
            echo "<script>alert('Too many incorrect codes. Please resend a new code or sign in again.'); window.location='login.php';</script>";
            exit;
        }
        echo "<script>alert('Invalid Verification Code.');</script>";
    }
}
// ============================================================
// NORMAL EMAIL/PASSWORD LOGIN
// ============================================================
if (isset($_POST['login_btn'])) {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($email === '' || $password === '') {
        echo "<script>alert('Please enter your email and password.');</script>";
    } else {
        $stmt = $pdo->prepare(
            'SELECT * FROM users WHERE email = :email LIMIT 1'
        );
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            echo "<script>alert('No account found with that email!');</script>";
        } elseif (!password_verify($password, $user['password'])) {
            echo "<script>alert('Invalid Password!');</script>";
        } else {
            $blocked_portal = customerLoginBlockedPortal($user);
            if ($blocked_portal !== null) {
                $portal_json = json_encode($blocked_portal, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
                echo "<script>alert('Please use the correct portal for this account.'); window.location=" . $portal_json . ";</script>";
                exit;
            }
            $new_otp = random_int(100000, 999999);
            $stmt = $pdo->prepare(
                'UPDATE users SET otp_code = :otp WHERE id = :id'
            );
            $update_otp = $stmt->execute([
                ':otp' => $new_otp,
                ':id' => $user['id']
            ]);
            if (!$update_otp) {
                echo "<script>alert('Failed to generate verification code. Please try again.');</script>";
                exit;
            }
            if (trim(SMTP_PASS) === '') {
                $clear = $pdo->prepare(
                    'UPDATE users SET otp_code = NULL WHERE id = :id'
                );
                $clear->execute([':id' => $user['id']]);
                echo "<script>alert('Email service is not configured. Please try again later.');</script>";
            } else {
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host = 'smtp.gmail.com';
                    $mail->SMTPAuth = true;
                    $mail->Username = SMTP_EMAIL;
                    $mail->Password = SMTP_PASS;
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port = 587;
                    $mail->Timeout = 20;
                    $mail->CharSet = 'UTF-8';
                    $mail->setFrom(SMTP_EMAIL, "Boogie's Pet Care Services");
                    $mail->addAddress($email, $user['full_name']);
                    $mail->isHTML(true);
                    $mail->Subject = "Login Verification Code - Boogie's Pet Care";
                    $safe_name = htmlspecialchars(
                        (string)$user['full_name'],
                        ENT_QUOTES,
                        'UTF-8'
                    );
                    $mail->Body = "
                        <div style='font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:20px;border:1px solid #e2e8f0;border-radius:10px;background:#ffffff;'>
                            <h2 style='color:#001f3f;text-align:center;'>Login Verification</h2>
                            <p>Hi {$safe_name},</p>
                            <p>We detected a login attempt on your Boogie's Pet Care account.</p>
                            <p>Please enter the verification code below to continue logging in:</p>
                            <h1 style='background:#f4f7fe;padding:15px;text-align:center;color:#1d63ff;letter-spacing:5px;border-radius:8px;'>{$new_otp}</h1>
                            <p>This code expires in 15 minutes and is required to complete your login.</p>
                            <p>If you did not attempt to log in, please secure your account.</p>
                            <p style='font-size:12px;color:#64748b;text-align:center;margin-top:30px;'>© Boogie's Pet Care &amp; Services</p>
                        </div>
                    ";
                    $mail->send();
                    $_SESSION['login_temp_email'] = $email;
                    $_SESSION['login_temp_expires'] = time() + 900;
                    $_SESSION['login_otp_attempts'] = 0;
                    $_SESSION['login_otp_resends'] = 0;
                    $_SESSION['login_otp_last_sent'] = time();
                    echo "<script>alert('Verification code sent to your email.'); window.location='login.php';</script>";
                    exit;
                } catch (Exception $e) {
                    $clear = $pdo->prepare(
                        'UPDATE users SET otp_code = NULL WHERE id = :id'
                    );
                    $clear->execute([':id' => $user['id']]);
                    error_log('Login verification email failed: ' . $mail->ErrorInfo);
                    echo "<script>alert('The verification email could not be sent. Please try again later.');</script>";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Boogie's Pet Care Services</title>
    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"
    >
    <style>
        :root {
            --brand-yellow: #ffcc00;
            --brand-blue: #001f3f;
            --brand-blue-light: #002d5b;
            --white: #ffffff;
            --light-gray: #f1f5f9;
            --text-gray: #64748b;
        }
        * {
            box-sizing: border-box;
        }
        html,
        body {
            margin: 0;
            padding: 0;
            width: 100%;
            min-height: 100%;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--brand-yellow);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .back-home {
            position: absolute;
            top: 25px;
            left: 25px;
            text-decoration: none;
            color: var(--brand-blue);
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            text-transform: uppercase;
        }
        .login-card {
            background: var(--white);
            padding: 45px;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
            width: 100%;
            max-width: 420px;
            text-align: center;
            border-bottom: 5px solid var(--brand-blue);
        }
        .login-icon {
            font-size: 45px;
            color: var(--brand-blue);
            margin-bottom: 15px;
        }
        h2 {
            margin: 0 0 5px;
            color: var(--brand-blue);
            font-size: 26px;
            font-weight: 800;
        }
        .branch-tag {
            font-size: 12px;
            color: var(--text-gray);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 25px;
            display: block;
        }
        .form-group {
            text-align: left;
            margin-bottom: 20px;
        }
        label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--brand-blue);
        }
        input {
            width: 100%;
            padding: 14px;
            border: 2px solid var(--light-gray);
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.3s ease;
        }
        input:focus {
            outline: none;
            border-color: var(--brand-yellow);
            background-color: #fffdf5;
        }
        .login-btn {
            width: 100%;
            padding: 15px;
            background-color: var(--brand-blue);
            color: var(--brand-yellow);
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 800;
            cursor: pointer;
            margin-top: 10px;
            text-transform: uppercase;
            transition: transform 0.2s ease, opacity 0.3s;
        }
        .login-btn:hover {
            opacity: 0.95;
            transform: translateY(-2px);
        }
        .divider {
            display: flex;
            align-items: center;
            text-align: center;
            margin: 20px 0;
            color: var(--text-gray);
            font-size: 12px;
            font-weight: 600;
        }
        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid var(--light-gray);
        }
        .divider span {
            padding: 0 10px;
        }
        .google-btn {
            width: 100%;
            min-height: 50px;
            padding: 14px;
            background-color: var(--white);
            color: var(--text-gray);
            border: 2px solid var(--light-gray);
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.3s ease;
        }
        .google-btn:hover {
            border-color: #cbd5e1;
            background-color: #f8fafc;
        }
        .google-btn:disabled {
            cursor: wait;
        }
        .register-link {
            margin-top: 25px;
            font-size: 14px;
            color: var(--text-gray);
        }
        .register-link a {
            color: var(--brand-blue);
            text-decoration: none;
            font-weight: 700;
            border-bottom: 2px solid var(--brand-yellow);
        }
        .footer-links {
            margin-top: 35px;
            padding-top: 20px;
            border-top: 1px solid var(--light-gray);
        }
        .footer-links a {
            color: var(--text-gray);
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            margin: 0 10px;
            transition: color 0.3s;
        }
        .footer-links a:hover {
            color: var(--brand-blue);
        }
        .brand-footer {
            margin-top: 15px;
            font-size: 11px;
            color: var(--brand-blue-light);
            opacity: 0.7;
        }
        .otp-input {
            text-align: center;
            font-size: 24px;
            letter-spacing: 5px;
            font-weight: bold;
        }
        /* Mobile */
        @media (max-width: 600px) {
            body {
                min-height: 100vh;
                height: auto;
                padding: 72px 14px 24px;
                align-items: flex-start;
                overflow-x: hidden;
            }
            .back-home {
                top: 16px;
                left: 14px;
                right: 14px;
                font-size: 12px;
                gap: 6px;
                padding: 8px 0;
            }
            .login-card {
                width: 100%;
                max-width: 420px;
                margin: 0 auto;
                padding: 30px 20px 24px;
                border-radius: 16px;
                border-bottom-width: 4px;
            }
            .login-icon {
                font-size: 40px;
                margin-bottom: 12px;
            }
            h2 {
                font-size: 23px;
                line-height: 1.2;
            }
            .branch-tag {
                font-size: 10px;
                margin-bottom: 20px;
            }
            .form-group {
                margin-bottom: 16px;
            }
            label {
                font-size: 12px;
                margin-bottom: 7px;
            }
            input {
                min-height: 48px;
                padding: 12px 13px;
                font-size: 15px;
            }
            .otp-input {
                min-height: 56px;
                font-size: 24px;
                letter-spacing: 6px;
            }
            .login-btn {
                min-height: 50px;
                padding: 13px 14px;
                font-size: 14px;
                margin-top: 6px;
            }
            .divider {
                margin: 17px 0;
                font-size: 11px;
            }
            .google-btn {
                min-height: 50px;
                padding: 12px 14px;
                font-size: 14px;
                touch-action: manipulation;
            }
            .register-link {
                margin-top: 20px;
                font-size: 13px;
                line-height: 1.6;
            }
            .footer-links {
                margin-top: 24px;
                padding-top: 16px;
            }
            .footer-links a {
                display: inline-block;
                margin: 4px 7px;
                font-size: 11px;
            }
            .brand-footer {
                font-size: 10px;
            }
            .login-card p {
                font-size: 12px !important;
                line-height: 1.6;
            }
        }
        @media (max-width: 380px) {
            body {
                padding-left: 10px;
                padding-right: 10px;
            }
            .login-card {
                padding: 26px 15px 20px;
            }
            h2 {
                font-size: 21px;
            }
            .otp-input {
                font-size: 21px;
                letter-spacing: 4px;
            }
            .google-btn,
            .login-btn {
                font-size: 13px;
            }
            .footer-links a {
                margin-left: 4px;
                margin-right: 4px;
            }
        }
    </style>
</head>
<body>
    <a href="index.php" class="back-home">
        <i class="fas fa-chevron-left"></i>
        Back to Website
    </a>
    <div class="login-card">
        <?php if (isset($_SESSION['login_temp_email'])): ?>
            <div class="login-icon">
                <i class="fa-solid fa-shield-halved"></i>
            </div>
            <h2>Verify Account</h2>
            <span class="branch-tag">Security Check</span>
            <p style="font-size: 13px; color: var(--text-gray); margin-bottom: 20px;">
                Please enter the 6-digit code sent to your email address to continue logging in.
            </p>
            <form action="login.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <div class="form-group">
                    <input
                        type="text"
                        name="otp_code"
                        class="otp-input"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        maxlength="6"
                        pattern="[0-9]{6}"
                        required
                        placeholder="000000"
                        oninput="this.value = this.value.replace(/[^0-9]/g, '')"
                    >
                </div>
                <button type="submit" name="verify_login_btn" class="login-btn">
                    Verify &amp; Login
                </button>
            </form>
            <form action="login.php" method="POST" style="margin-top:12px;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" name="resend_login_otp_btn" class="google-btn">
                    <i class="fa-solid fa-rotate-right"></i> Resend Verification Code
                </button>
            </form>
            <div class="register-link">
                <a href="logout.php">Cancel &amp; Return to Login</a>
            </div>
        <?php else: ?>
            <div class="login-icon">
                <i class="fas fa-paw"></i>
            </div>
            <h2>Welcome Back!</h2>
            <span class="branch-tag">Dasmariñas Branch</span>
            <form action="login.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input
                        type="email"
                        name="email"
                        id="email"
                        placeholder="Enter your email"
                        autocomplete="email"
                        required
                    >
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input
                        type="password"
                        name="password"
                        id="password"
                        placeholder="Enter your password"
                        autocomplete="current-password"
                        required
                    >
                </div>
                <button type="submit" name="login_btn" class="login-btn">
                    Login to Account
                </button>
            </form>
            <div class="divider"><span>OR</span></div>
            <button id="googleLoginBtn" class="google-btn" type="button">
                <img
                    src="https://upload.wikimedia.org/wikipedia/commons/c/c1/Google_%22G%22_logo.svg"
                    alt="Google Logo"
                    width="20"
                    height="20"
                >
                <span>Continue with Google</span>
            </button>
            <div class="register-link">
                New fur-parent? <a href="register.php">Register here</a>
            </div>
            <div class="footer-links">
                <a href="staff/stafflogin.php">
                    <i class="fas fa-user-shield"></i> Personal Portal
                </a>
                <a href="#">
                    <i class="fas fa-question-circle"></i> Help
                </a>
            </div>
            <div class="brand-footer">
                © <?php echo date('Y'); ?> Boogie's Pet Care Services
            </div>
        <?php endif; ?>
    </div>
    <script type="module">
        import { initializeApp } from 'https://www.gstatic.com/firebasejs/10.8.1/firebase-app.js';
        import {
            getAuth,
            setPersistence,
            browserLocalPersistence,
            signInWithPopup,
            signInWithRedirect,
            getRedirectResult,
            GoogleAuthProvider,
            onAuthStateChanged
        } from 'https://www.gstatic.com/firebasejs/10.8.1/firebase-auth.js';
        const firebaseConfig = {
            apiKey: 'AIzaSyDhJFrsb9HgQRC7uUEoMdn1TA23mOZmo5M',
            authDomain: 'boogiespetcare.firebaseapp.com',
            projectId: 'boogiespetcare',
            storageBucket: 'boogiespetcare.firebasestorage.app',
            messagingSenderId: '181436323114',
            appId: '1:181436323114:web:49f3cf12298256fd1f7a53',
            measurementId: 'G-4ZCN6N283T'
        };
        const app = initializeApp(firebaseConfig);
        const auth = getAuth(app);
        const provider = new GoogleAuthProvider();
        let backendProcessing = false;
        function setGoogleButtonState(button, loading) {
            if (!button) return;
            button.disabled = loading;
            button.style.opacity = loading ? '0.7' : '';
            button.style.cursor = loading ? 'wait' : '';
        }
        async function authenticateWithBackend(name, email) {
            if (backendProcessing || !email) return;
            backendProcessing = true;
            try {
                const response = await fetch('process_google_login.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body:
                        'full_name=' + encodeURIComponent(name || '') +
                        '&email=' + encodeURIComponent(email)
                });
                if (!response.ok) {
                    throw new Error('Server returned HTTP ' + response.status);
                }
                const data = await response.json();
                if (data.success) {
                    sessionStorage.removeItem('google_redirect_pending');
                    window.location.replace(data.redirect || 'login.php');
                    return;
                }
                backendProcessing = false;
                sessionStorage.removeItem('google_redirect_pending');
                alert('Login failed: ' + (data.message || 'Please try again.'));
            } catch (error) {
                backendProcessing = false;
                console.error('Backend Google login error:', error);
                alert('Could not connect to the server. Please try again.');
            }
        }
        async function finishGoogleRedirect() {
            try {
                const result = await getRedirectResult(auth);
                if (result && result.user) {
                    await authenticateWithBackend(
                        result.user.displayName || '',
                        result.user.email || ''
                    );
                }
            } catch (error) {
                console.error('Google redirect sign-in error:', error);
                sessionStorage.removeItem('google_redirect_pending');
                alert(
                    'Google Sign-in Error: ' +
                    (error?.message || 'Unable to complete Google login.')
                );
            }
        }
        onAuthStateChanged(auth, async (user) => {
            const pending =
                sessionStorage.getItem('google_redirect_pending') === '1';
            if (user && pending && !backendProcessing) {
                await authenticateWithBackend(
                    user.displayName || '',
                    user.email || ''
                );
            }
        });
        window.addEventListener('load', async () => {
            const btn = document.getElementById('googleLoginBtn');
            try {
                await setPersistence(auth, browserLocalPersistence);
            } catch (error) {
                console.error('Firebase persistence setup failed:', error);
            }
            const redirectPending =
                sessionStorage.getItem('google_redirect_pending') === '1';
            if (redirectPending) {
                await finishGoogleRedirect();
            }
            if (!btn) return;
            btn.addEventListener('click', async (event) => {
                event.preventDefault();
                setGoogleButtonState(btn, true);
                try {
                    // Popup first on both desktop and mobile.
                    // This fixes the current mobile LAN-testing flow where
                    // the previous code always forced redirect on mobile.
                    const result = await signInWithPopup(auth, provider);
                    await authenticateWithBackend(
                        result.user.displayName || '',
                        result.user.email || ''
                    );
                } catch (error) {
                    console.error('Google popup sign-in error:', error);
                    // Fallback to redirect only when popup is blocked.
                    if (error?.code === 'auth/popup-blocked') {
                        try {
                            sessionStorage.setItem('google_redirect_pending', '1');
                            await signInWithRedirect(auth, provider);
                            return;
                        } catch (redirectError) {
                            console.error(
                                'Google redirect sign-in error:',
                                redirectError
                            );
                            sessionStorage.removeItem('google_redirect_pending');
                            alert(
                                'Google Sign-in Error: ' +
                                (
                                    redirectError?.message ||
                                    'Unable to complete Google login.'
                                )
                            );
                        }
                    } else {
                        alert(
                            'Google Sign-in Error: ' +
                            (
                                error?.message ||
                                'Unable to complete Google login.'
                            )
                        );
                    }
                    setGoogleButtonState(btn, false);
                }
            });
        });
    </script>
</body>
</html>
