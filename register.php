<?php
session_start();
include 'db_connect.php';

// TAWAGIN ANG PHPMAILER CLASSES
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

// --- VERIFICATION LOGIC (WITH AUTO-LOGIN) ---
if (isset($_POST['verify_btn'])) {
    $email = $_SESSION['temp_email'];
    $entered_otp = mysqli_real_escape_string($conn, $_POST['otp_code']);
    
    $check_query = "SELECT * FROM users WHERE email = '$email' AND otp_code = '$entered_otp'";
    $result = mysqli_query($conn, $check_query);
    
    if (mysqli_num_rows($result) > 0) {
        $user_data = mysqli_fetch_assoc($result);

        mysqli_query($conn, "UPDATE users SET is_verified = 1, email_verified = 1, otp_code = NULL WHERE email = '$email'");
        unset($_SESSION['temp_email']);

        $_SESSION['logged_in'] = true;
        $_SESSION['user_id'] = $user_data['id'];
        $_SESSION['user_name'] = $user_data['full_name'];
        $_SESSION['role'] = $user_data['role'];
        $_SESSION['position'] = $user_data['position']; 

        echo "<script>alert('Account verified successfully! Logging you in...'); window.location='index.php';</script>";
        exit();
    } else {
        echo "<script>alert('Invalid Verification Code. Please try again.');</script>";
    }
}

// --- REGISTRATION LOGIC ---
if (isset($_POST['register_btn'])) {
    $full_name = mysqli_real_escape_string($conn, $_POST['full_name']);
    $contact = mysqli_real_escape_string($conn, $_POST['contact']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $user_category = mysqli_real_escape_string($conn, $_POST['user_category']); 
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // 1. STRICT VALIDATIONS
    if (!preg_match("/^[a-zA-Z\s]*$/", $full_name)) {
        echo "<script>alert('Invalid name. Only letters and spaces are allowed.');</script>";
    } elseif (!preg_match("/^09[0-9]{9}$/", $contact)) {
        echo "<script>alert('Invalid contact number. Must start with 09 and be exactly 11 digits (e.g., 09123456789).');</script>";
    } elseif (!preg_match("/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.(com|net|ph)$/i", $email)) {
        echo "<script>alert('Invalid email. Please use a complete email address.');</script>";
    } elseif (!preg_match("/^(?=.*[A-Z]).{8,}$/", $password)) {
        // BAGO: Min 8 chars at kahit 1 Uppercase lang
        echo "<script>alert('Password must be at least 8 characters long and include at least 1 uppercase letter.');</script>";
    } elseif ($password !== $confirm_password) {
        echo "<script>alert('Passwords do not match.');</script>";
    } else {
        $check_email = "SELECT email FROM users WHERE email='$email'";
        $result = mysqli_query($conn, $check_email);
        
        if (mysqli_num_rows($result) > 0) {
            echo "<script>alert('Email is already registered.');</script>";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $role = 'customer'; 
            $position = 'Customer'; 
            $otp = rand(100000, 999999);
            
            $sql = "INSERT INTO users (full_name, contact_number, email, password, role, position, user_category, otp_code, is_verified) 
                    VALUES ('$full_name', '$contact', '$email', '$hashed_password', '$role', '$position', '$user_category', '$otp', 0)";

            if (mysqli_query($conn, $sql)) {
                
                // --- SEND OTP VIA PHPMAILER (GMAIL) ---
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = 'smtp.gmail.com';
                    $mail->SMTPAuth   = true;
                    
                    $mail->Username   = SMTP_EMAIL; 
                    $mail->Password   = SMTP_PASS; 
                    
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;

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
                    $mail->Subject = 'Verify Your Account - Boogie\'s Pet Care';
                    $mail->Body    = "
                        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 10px;'>
                            <h2 style='color: #001f3f; text-align: center;'>Welcome to Boogie's Pet Care!</h2>
                            <p>Hi $full_name,</p>
                            <p>Thank you for registering. To activate your account, please enter the OTP code below:</p>
                            <h1 style='background: #f4f7fe; padding: 15px; text-align: center; color: #1d63ff; letter-spacing: 5px; border-radius: 8px;'>$otp</h1>
                            <p>If you did not request this, please ignore this email.</p>
                            <p style='font-size: 12px; color: #64748b; text-align: center; margin-top: 30px;'>© Boogie's Pet Care & Services</p>
                        </div>
                    ";

                    $mail->send();
                    
                    $_SESSION['temp_email'] = $email;
                    echo "<script>alert('Account created! We have sent a verification code to your email.'); window.location='register.php';</script>";
                    exit();

                } catch (Exception $e) {
    echo "<pre>";
    echo "PHPMailer Error: " . $mail->ErrorInfo;
    echo "</pre>";
    exit();
}
            } else {
                echo "<script>alert('Error: " . mysqli_error($conn) . "');</script>";
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
    <title>Register - Boogie's Pet Care</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --fb-navy: #001f3f; --fb-yellow: #ffcc00; --bg-wash: #f0f2f5; --text-main: #1c1e21; --text-secondary: #65676b; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; min-height: 100vh; display: flex; align-items: center; justify-content: center; background-color: var(--bg-wash); padding: 20px; }
        .back-nav { position: absolute; top: 30px; left: 30px; color: #001f3f; text-decoration: none; font-weight: 600; display: flex; align-items: center; gap: 8px; font-size: 14px; }
        .register-card { background: #ffffff; width: 100%; max-width: 500px; padding: 50px 40px; border-radius: 15px; box-shadow: 0 2px 12px rgba(0, 0, 0, 0.1); text-align: center; margin-top: 40px; }
        .user-icon { font-size: 40px; color: #001f3f; margin-bottom: 15px; }
        h2 { font-size: 28px; font-weight: 700; color: var(--text-main); margin-bottom: 5px; }
        .subtitle { font-size: 14px; color: var(--text-secondary); margin-bottom: 30px; }
        .form-group { text-align: left; margin-bottom: 18px; }
        label { display: block; font-size: 13px; font-weight: 600; color: var(--text-main); margin-bottom: 8px; }
        label span { color: #ef4444; }
        input, select { width: 100%; padding: 12px 16px; border: 1px solid #dddfe2; border-radius: 8px; font-size: 14px; outline: none; background-color: #fff; }
        input:focus, select:focus { border-color: #001f3f; }
        .terms-box { background: #f5f6f7; border: 1px solid #dddfe2; border-radius: 8px; padding: 15px; text-align: left; margin-bottom: 25px; }
        .terms-box h4 { font-size: 12px; font-weight: 700; margin-bottom: 5px; }
        .terms-box p { font-size: 11px; color: var(--text-secondary); line-height: 1.4; margin-bottom: 10px; }
        .checkbox-row { display: flex; align-items: center; gap: 8px; font-size: 12px; font-weight: 600; }
        .checkbox-row input { width: auto; }
        .btn-submit { width: 100%; padding: 14px; background: #001f3f; color: #ffcc00; border: none; border-radius: 8px; font-size: 16px; font-weight: 700; cursor: pointer; margin-top: 10px; }
        .divider { display: flex; align-items: center; text-align: center; margin: 20px 0; color: var(--text-secondary); font-size: 12px; font-weight: 600; }
        .divider::before, .divider::after { content: ''; flex: 1; border-bottom: 1px solid #dddfe2; }
        .divider span { padding: 0 10px; }
        .google-btn { width: 100%; padding: 14px; background-color: #ffffff; color: var(--text-main); border: 2px solid #dddfe2; border-radius: 8px; font-size: 15px; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 10px; transition: all 0.3s ease; }
        .google-btn:hover { border-color: #cbd5e1; background-color: #f8fafc; }
        .login-link { margin-top: 20px; font-size: 13px; color: var(--text-secondary); }
        .login-link a { color: #001f3f; text-decoration: none; font-weight: 700; }
        .otp-input { text-align: center; font-size: 24px; letter-spacing: 5px; font-weight: bold; }
    </style>
</head>
<body>

    <a href="index.php" class="back-nav"><i class="fa-solid fa-arrow-left"></i> Back to Home</a>

    <div class="register-card">
        
        <?php if(isset($_SESSION['temp_email'])): ?>
            <div class="user-icon"><i class="fa-solid fa-shield-halved"></i></div>
            <h2>Verify Account</h2>
            <p class="subtitle">Enter the 6-digit code sent to your email address.</p>
            
            <form action="register.php" method="POST">
                <div class="form-group">
                    <input type="text" name="otp_code" class="otp-input" maxlength="6" pattern="[0-9]{6}" required placeholder="000000" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                </div>
                <button type="submit" name="verify_btn" class="btn-submit">Verify Now</button>
            </form>
            <div class="login-link">
                Wrong email? <a href="logout.php">Start Over</a>
            </div>

        <?php else: ?>
            <div class="user-icon"><i class="fa-solid fa-user-plus"></i></div>
            <h2>Create Account</h2>
            <p class="subtitle">Join Boogie's Pet Care & Services</p>

            <form action="register.php" method="POST">
                <div class="form-group">
                    <label>Full Name <span>*</span></label>
                    <input type="text" name="full_name" placeholder="Enter your full name" required>
                </div>
                <div class="form-group">
                    <label>Contact Number <span>*</span></label>
                    <input type="tel" name="contact" placeholder="09XXXXXXXXX" required maxlength="11" pattern="09[0-9]{9}" title="Must start with 09 and be exactly 11 digits" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                </div>
                <div class="form-group">
                    <label>Email Address <span>*</span></label>
                    <input type="email" name="email" placeholder="Enter your email" required>
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
                    <input type="password" name="password" placeholder="Create a password" required minlength="8" pattern="(?=.*[A-Z]).{8,}" title="Must contain at least 8 characters, including at least 1 uppercase letter">
                </div>
                <div class="form-group">
                    <label>Confirm Password <span>*</span></label>
                    <input type="password" name="confirm_password" placeholder="Confirm your password" required minlength="8">
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
            <button id="googleLoginBtn" class="google-btn" type="button">
                <img src="https://upload.wikimedia.org/wikipedia/commons/c/c1/Google_%22G%22_logo.svg" alt="Google Logo" width="20">
                Sign up with Google
            </button>

            <div class="login-link">
                Already have an account? <a href="login.php">Login here</a>
            </div>
        <?php endif; ?>

    </div>

    <script type="module">
    import { initializeApp } from "https://www.gstatic.com/firebasejs/10.8.1/firebase-app.js";
    import { getAuth, signInWithPopup, GoogleAuthProvider } from "https://www.gstatic.com/firebasejs/10.8.1/firebase-auth.js";
    const firebaseConfig = { apiKey: "AIzaSyDhJFrsb9HgQRC7uUEoMdn1TA23mOZmo5M", authDomain: "boogiespetcare.firebaseapp.com", projectId: "boogiespetcare", storageBucket: "boogiespetcare.firebasestorage.app", messagingSenderId: "181436323114", appId: "1:181436323114:web:49f3cf12298256fd1f7a53", measurementId: "G-4ZCN6N283T" };
    const app = initializeApp(firebaseConfig); const auth = getAuth(app); const provider = new GoogleAuthProvider();
    window.onload = function() {
        const btn = document.getElementById('googleLoginBtn');
        if (btn) {
            btn.addEventListener('click', async (e) => {
                e.preventDefault();
                try {
                    const result = await signInWithPopup(auth, provider);
                    authenticateWithBackend(result.user.displayName, result.user.email);
                } catch (error) { alert("Google Sign-in Error: " + error.message); }
            });
        }
    };
    async function authenticateWithBackend(name, email) {
        try {
            const response = await fetch('process_google_login.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: `full_name=${encodeURIComponent(name)}&email=${encodeURIComponent(email)}` });
            const data = await response.json();
            if (data.success) { window.location.href = data.redirect; } else { alert("Login failed: " + data.message); }
        } catch (error) { alert("Could not connect to the server."); }
    }
    </script>
</body>
</html>