<?php
session_start();
require_once 'db_supabase.php';

if (!isset($_SESSION['temp_email'])) {
    header("Location: register.php");
    exit();
}

$email = $_SESSION['temp_email'];
$error_msg = '';

if (isset($_POST['verify_btn'])) {
    $entered_otp = trim($_POST['otp_code'] ?? '');

    try {
        // Check if OTP matches
        $check_stmt = $pdo->prepare("
            SELECT id
            FROM users
            WHERE email = :email
              AND otp_code = :otp_code
            LIMIT 1
        ");

        $check_stmt->execute([
            ':email' => $email,
            ':otp_code' => $entered_otp
        ]);

        $user = $check_stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // Correct OTP: mark account as verified and clear OTP
            $update_stmt = $pdo->prepare("
                UPDATE users
                SET is_verified = TRUE,
                    otp_code = NULL
                WHERE email = :email
            ");

            $update_stmt->execute([
                ':email' => $email
            ]);

            // Clear temporary session
            unset($_SESSION['temp_email']);

            echo "<script>alert('Account verified successfully! You can now log in.'); window.location='login.php';</script>";
            exit();
        } else {
            $error_msg = "Invalid Verification Code. Please try again.";
        }
    } catch (PDOException $e) {
        error_log("OTP verification failed: " . $e->getMessage());
        $error_msg = "Unable to verify the account right now. Please try again.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Account - Boogie's Pet Care</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #f0f2f5; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .verify-card { background: #fff; padding: 40px; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); text-align: center; width: 100%; max-width: 400px; }
        h2 { color: #001f3f; margin-bottom: 10px; }
        p { color: #65676b; font-size: 14px; margin-bottom: 25px; }
        input { width: 100%; padding: 15px; font-size: 18px; text-align: center; letter-spacing: 5px; border: 2px solid #ddd; border-radius: 8px; margin-bottom: 20px; outline: none; font-weight: bold;}
        input:focus { border-color: #001f3f; }
        button { width: 100%; padding: 15px; background: #001f3f; color: #ffcc00; border: none; border-radius: 8px; font-size: 16px; font-weight: bold; cursor: pointer; transition: 0.3s; }
        button:hover { opacity: 0.9; }
        .error { color: #dc3545; font-size: 13px; margin-bottom: 15px; font-weight: 600; }
    </style>
</head>
<body>
    <div class="verify-card">
        <h2>Enter Verification Code</h2>
        <p>We've sent a 6-digit code to your registered contact number. Please enter it below.</p>

        <?php if (!empty($error_msg)): ?>
            <div class="error"><?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="text" name="otp_code" maxlength="6" pattern="[0-9]{6}" required placeholder="000000" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
            <button type="submit" name="verify_btn">Verify Account</button>
        </form>
    </div>
</body>
</html>
