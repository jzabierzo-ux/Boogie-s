<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['temp_email'])) {
    header("Location: register.php");
    exit();
}

$email = $_SESSION['temp_email'];

if (isset($_POST['verify_btn'])) {
    $entered_otp = mysqli_real_escape_string($conn, $_POST['otp_code']);
    
    // Check if OTP matches
    $check_query = "SELECT id FROM users WHERE email = '$email' AND otp_code = '$entered_otp'";
    $result = mysqli_query($conn, $check_query);
    
    if (mysqli_num_rows($result) > 0) {
        // Correct OTP! Update account to verified
        mysqli_query($conn, "UPDATE users SET is_verified = 1, otp_code = NULL WHERE email = '$email'");
        
        // Clear temp session
        unset($_SESSION['temp_email']);
        
        echo "<script>alert('Account verified successfully! You can now log in.'); window.location='login.php';</script>";
        exit();
    } else {
        $error_msg = "Invalid Verification Code. Please try again.";
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
        
        <?php if(isset($error_msg)): ?>
            <div class="error"><?php echo $error_msg; ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="text" name="otp_code" maxlength="6" pattern="[0-9]{6}" required placeholder="000000" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
            <button type="submit" name="verify_btn">Verify Account</button>
        </form>
    </div>
</body>
</html>