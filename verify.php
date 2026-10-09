<?php
session_start();
require_once __DIR__ . '/db_supabase.php';

if (empty($_SESSION['temp_email']) || !is_string($_SESSION['temp_email'])) {
    header('Location: register.php');
    exit();
}

$email = trim($_SESSION['temp_email']);
$error_msg = '';

if (empty($_SESSION['verify_csrf_token'])) {
    $_SESSION['verify_csrf_token'] = bin2hex(random_bytes(32));
}
$verify_csrf_token = $_SESSION['verify_csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_btn'])) {
    $submitted_token = $_POST['csrf_token'] ?? '';
    $entered_otp = trim((string)($_POST['otp_code'] ?? ''));

    if (!is_string($submitted_token) || !hash_equals($verify_csrf_token, $submitted_token)) {
        http_response_code(403);
        $error_msg = 'Your session expired. Please refresh the page and try again.';
    } elseif (!preg_match('/^[0-9]{6}$/', $entered_otp)) {
        $error_msg = 'Please enter the 6-digit verification code.';
    } else {
        try {
            // Verify and consume the OTP in one database update so it cannot be reused.
            $update_stmt = $pdo->prepare("
                UPDATE users
                SET is_verified = 1,
                    email_verified = 1,
                    otp_code = NULL
                WHERE email = :email
                  AND otp_code = :otp_code
            ");
            $update_stmt->execute([
                ':email' => $email,
                ':otp_code' => $entered_otp
            ]);

            if ($update_stmt->rowCount() === 1) {
                unset($_SESSION['temp_email'], $_SESSION['verify_csrf_token']);
                header('Location: login.php?msg=account_verified');
                exit();
            }

            $error_msg = 'Invalid or already-used verification code. Please try again.';
        } catch (PDOException $e) {
            error_log('OTP verification failed: ' . $e->getMessage());
            $error_msg = 'Unable to verify the account right now. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Account - Boogie's Pet Care</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand-blue:#001f3f; --brand-blue-2:#0b3b66; --brand-yellow:#ffcc00;
            --page-bg:#f5f8fb; --white:#fff; --text:#17324d; --muted:#6b7c8f;
            --line:#dce5ed; --danger:#c73b47; --danger-bg:#fff0f1;
            --shadow:0 16px 40px rgba(0,31,63,.10);
        }
        * { box-sizing:border-box; margin:0; padding:0; font-family:'Poppins',sans-serif; }
        html { min-height:100%; }
        body { min-height:100vh; padding:20px; display:flex; align-items:center; justify-content:center; background:var(--page-bg); color:var(--text); }
        .verify-card { width:min(100%,430px); background:var(--white); border:1px solid var(--line); border-radius:20px; box-shadow:var(--shadow); padding:38px 34px; text-align:center; }
        .verify-icon { width:64px; height:64px; margin:0 auto 16px; border-radius:18px; display:flex; align-items:center; justify-content:center; background:#fff7d6; color:var(--brand-blue); font-size:25px; font-weight:800; }
        h2 { color:var(--brand-blue); font-size:26px; line-height:1.2; margin-bottom:9px; font-weight:800; }
        p { color:var(--muted); font-size:13px; line-height:1.7; margin-bottom:24px; }
        .error { padding:11px 13px; margin-bottom:16px; border:1px solid #f1c4c9; border-radius:10px; background:var(--danger-bg); color:var(--danger); font-size:12px; line-height:1.5; font-weight:600; }
        .otp-input { display:block; width:100%; min-height:56px; padding:12px 14px; border:2px solid var(--line); border-radius:12px; background:#fbfcfe; color:var(--brand-blue); font-size:22px; text-align:center; letter-spacing:7px; outline:none; font-weight:800; transition:.2s; margin-bottom:17px; }
        .otp-input::placeholder { color:#b7c1cb; opacity:1; letter-spacing:7px; }
        .otp-input:focus { border-color:#89a9c4; background:#fff; box-shadow:0 0 0 4px rgba(0,31,63,.06); }
        .btn-verify { width:100%; min-height:50px; padding:12px 16px; border:0; border-radius:12px; background:var(--brand-blue); color:var(--brand-yellow); font-size:14px; font-weight:800; cursor:pointer; transition:.2s; }
        .btn-verify:hover { background:var(--brand-blue-2); }
        .back-link { display:inline-block; margin-top:17px; color:#718294; text-decoration:none; font-size:12px; font-weight:600; }
        .back-link:hover { color:var(--brand-blue); }
        .helper { margin-top:15px; margin-bottom:0; color:#98a5b2; font-size:10px; line-height:1.6; }
        @media(max-width:480px) {
            body { padding:14px; }
            .verify-card { padding:28px 20px; border-radius:17px; }
            .verify-icon { width:56px; height:56px; border-radius:15px; font-size:22px; margin-bottom:13px; }
            h2 { font-size:22px; }
            p { font-size:12px; margin-bottom:20px; }
            .otp-input { min-height:52px; font-size:20px; letter-spacing:6px; }
            .otp-input::placeholder { letter-spacing:6px; }
            .btn-verify { min-height:48px; font-size:13px; }
        }
        @media(max-width:360px) {
            .verify-card { padding:24px 16px; }
            h2 { font-size:20px; }
            .otp-input { font-size:18px; letter-spacing:5px; }
            .otp-input::placeholder { letter-spacing:5px; }
        }
    </style>
</head>
<body>
    <div class="verify-card">
        <div class="verify-icon" aria-hidden="true">✓</div>
        <h2>Enter Verification Code</h2>
        <p>We've sent a 6-digit code to your registered email address. Please enter it below.</p>

        <?php if ($error_msg !== ''): ?>
            <div class="error" role="alert"><?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($verify_csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
            <input
                class="otp-input"
                type="text"
                name="otp_code"
                maxlength="6"
                minlength="6"
                pattern="[0-9]{6}"
                inputmode="numeric"
                autocomplete="one-time-code"
                required
                placeholder="000000"
                aria-label="6-digit verification code"
                oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,6)"
            >
            <button type="submit" name="verify_btn" class="btn-verify">Verify Account</button>
        </form>
        <a href="register.php" class="back-link">Back to Registration</a>
        <p class="helper">Enter the 6-digit code exactly as received.</p>
    </div>
</body>
</html>
