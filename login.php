<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
include('db_connect.php'); 

// --- BAGO: EMAIL CREDENTIALS SETUP ---
if (!defined('SMTP_EMAIL')) define('SMTP_EMAIL', 'prototyp6712@gmail.com'); 
if (!defined('SMTP_PASS')) define('SMTP_PASS', 'jwkvmplgbfuxlwdr'); 

// TAWAGIN ANG PHPMAILER CLASSES
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// AYUSIN ANG PATH KUNG SAAAN NAKALAGAY ANG PHPMailer FOLDER MO
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

// --- VERIFICATION LOGIC INSIDE LOGIN ---
if (isset($_POST['verify_login_btn'])) {
    $email = $_SESSION['login_temp_email'];
    $entered_otp = mysqli_real_escape_string($conn, $_POST['otp_code']);
    
    $query = "SELECT * FROM users WHERE email='$email' AND otp_code='$entered_otp' LIMIT 1";
    $result = mysqli_query($conn, $query);

    if (mysqli_num_rows($result) > 0) {
        $user = mysqli_fetch_assoc($result);
        
        // Update to verified
        mysqli_query($conn, "UPDATE users SET is_verified = 1, email_verified = 1, otp_code = NULL WHERE id = ".$user['id']);
        
        // Proceed with Login
        $_SESSION['logged_in'] = true;
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role']; 
        $_SESSION['position'] = $user['position']; 
        
        unset($_SESSION['login_temp_email']); // clear temp
        
        if ($_SESSION['role'] === 'admin') {
            header("Location: admindashboard.php");
        } else {
            header("Location: index.php"); 
        }
        exit();
    } else {
        echo "<script>alert('Invalid Verification Code.');</script>";
    }
}

// --- NORMAL LOGIN LOGIC ---
if (isset($_POST['login_btn'])) {
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $password = $_POST['password'];

    $query = "SELECT * FROM users WHERE email='$email' LIMIT 1";
    $result = mysqli_query($conn, $query);

    if (mysqli_num_rows($result) > 0) {
        $user = mysqli_fetch_assoc($result);
        
        if (password_verify($password, $user['password'])) {
            
            // ============================================================
            // GENERATE NEW OTP & SEND VIA PHPMAILER FOR EVERY LOGIN
            // ============================================================

            $new_otp = random_int(100000, 999999);

            // Save the new OTP in database
            $update_otp = mysqli_query(
                $conn,
                "UPDATE users SET otp_code = '$new_otp' WHERE id = " . $user['id']
            );

            if (!$update_otp) {
                echo "<script>
                        alert('Failed to generate verification code. Please try again.');
                      </script>";
                exit();
            }

            // ============================================================
            // SEND OTP THROUGH GMAIL
            // ============================================================

            $mail = new PHPMailer(true);

            try {

                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;

                // Gmail credentials
                $mail->Username   = SMTP_EMAIL;
                $mail->Password   = SMTP_PASS;

                // Gmail SMTP
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;

                // XAMPP SSL settings
                $mail->SMTPOptions = array(
                    'ssl' => array(
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    )
                );

                // ========================================================
                // TEMPORARY DEBUG
                // Remove these 2 lines once email is working
                // ========================================================
                $mail->SMTPDebug = 2;
                $mail->Debugoutput = 'html';

                // ========================================================
                // EMAIL DETAILS
                // ========================================================

                $mail->setFrom(
                    SMTP_EMAIL,
                    "Boogie's Pet Care Services"
                );

                $mail->addAddress(
                    $email,
                    $user['full_name']
                );

                $mail->isHTML(true);

                $mail->Subject =
                    "Login Verification Code - Boogie's Pet Care";

                $mail->Body = "
                    <div style='
                        font-family: Arial, sans-serif;
                        max-width: 600px;
                        margin: auto;
                        padding: 20px;
                        border: 1px solid #e2e8f0;
                        border-radius: 10px;
                        background: #ffffff;
                    '>

                        <h2 style='
                            color: #001f3f;
                            text-align: center;
                        '>
                            Login Verification
                        </h2>

                        <p>
                            Hi " . htmlspecialchars($user['full_name']) . ",
                        </p>

                        <p>
                            We detected a login attempt on your
                            Boogie's Pet Care account.
                        </p>

                        <p>
                            Please enter the verification code below
                            to continue logging in:
                        </p>

                        <h1 style='
                            background: #f4f7fe;
                            padding: 15px;
                            text-align: center;
                            color: #1d63ff;
                            letter-spacing: 5px;
                            border-radius: 8px;
                        '>
                            $new_otp
                        </h1>

                        <p>
                            This code is required to complete your login.
                        </p>

                        <p>
                            If you did not attempt to log in,
                            please secure your account.
                        </p>

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

                // Send email
                $mail->send();

                // ========================================================
                // SAVE TEMPORARY LOGIN SESSION
                // ========================================================

                $_SESSION['login_temp_email'] = $email;

                // DO NOT LOG IN YET
                echo "<script>
                        alert('Verification code sent to your email.');
                        window.location='login.php';
                      </script>";

                exit();

            } catch (Exception $e) {

                $_SESSION['login_temp_email'] = $email;

                echo "<pre style='
                    font-family: Arial;
                    padding: 20px;
                    white-space: pre-wrap;
                '>";

                echo "PHPMailer Error:\n";
                echo htmlspecialchars($mail->ErrorInfo);

                echo "\n\nException:\n";
                echo htmlspecialchars($e->getMessage());

                echo "</pre>";

                exit();
            }

        } else {
            echo "<script>alert('Invalid Password!');</script>";
        }
    } else {
        echo "<script>alert('No account found with that email!');</script>";
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Boogie's Pet Care Services</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --brand-yellow: #ffcc00; --brand-blue: #001f3f; --brand-blue-light: #002d5b; --white: #ffffff; --light-gray: #f1f5f9; --text-gray: #64748b; }
        body { margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: var(--brand-yellow); display: flex; justify-content: center; align-items: center; height: 100vh; }
        .back-home { position: absolute; top: 25px; left: 25px; text-decoration: none; color: var(--brand-blue); font-weight: 700; display: flex; align-items: center; gap: 8px; font-size: 14px; text-transform: uppercase; }
        .login-card { background: var(--white); padding: 45px; border-radius: 20px; box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15); width: 100%; max-width: 420px; text-align: center; border-bottom: 5px solid var(--brand-blue); }
        .login-icon { font-size: 45px; color: var(--brand-blue); margin-bottom: 15px; }
        h2 { margin: 0 0 5px 0; color: var(--brand-blue); font-size: 26px; font-weight: 800; }
        .branch-tag { font-size: 12px; color: var(--text-gray); font-weight: 600; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 25px; display: block; }
        .form-group { text-align: left; margin-bottom: 20px; }
        label { display: block; font-size: 13px; font-weight: 700; margin-bottom: 8px; color: var(--brand-blue); }
        input { width: 100%; padding: 14px; border: 2px solid var(--light-gray); border-radius: 10px; box-sizing: border-box; font-size: 15px; transition: all 0.3s ease; }
        input:focus { outline: none; border-color: var(--brand-yellow); background-color: #fffdf5; }
        .login-btn { width: 100%; padding: 15px; background-color: var(--brand-blue); color: var(--brand-yellow); border: none; border-radius: 10px; font-size: 16px; font-weight: 800; cursor: pointer; margin-top: 10px; text-transform: uppercase; transition: transform 0.2s ease, opacity 0.3s; }
        .login-btn:hover { opacity: 0.95; transform: translateY(-2px); }
        .divider { display: flex; align-items: center; text-align: center; margin: 20px 0; color: var(--text-gray); font-size: 12px; font-weight: 600; }
        .divider::before, .divider::after { content: ''; flex: 1; border-bottom: 1px solid var(--light-gray); }
        .divider span { padding: 0 10px; }
        .google-btn { width: 100%; padding: 14px; background-color: var(--white); color: var(--text-gray); border: 2px solid var(--light-gray); border-radius: 10px; font-size: 15px; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 10px; transition: all 0.3s ease; }
        .google-btn:hover { border-color: #cbd5e1; background-color: #f8fafc; }
        .register-link { margin-top: 25px; font-size: 14px; color: var(--text-gray); }
        .register-link a { color: var(--brand-blue); text-decoration: none; font-weight: 700; border-bottom: 2px solid var(--brand-yellow); }
        .footer-links { margin-top: 35px; padding-top: 20px; border-top: 1px solid var(--light-gray); }
        .footer-links a { color: var(--text-gray); text-decoration: none; font-size: 12px; font-weight: 600; margin: 0 10px; transition: color 0.3s; }
        .footer-links a:hover { color: var(--brand-blue); }
        .brand-footer { margin-top: 15px; font-size: 11px; color: var(--brand-blue-light); opacity: 0.7; }
        .otp-input { text-align: center; font-size: 24px; letter-spacing: 5px; font-weight: bold; }
    </style>
</head>
<body>

    <a href="index.php" class="back-home"><i class="fas fa-chevron-left"></i> Back to Website</a>

    <div class="login-card">
        
        <?php if(isset($_SESSION['login_temp_email'])): ?>
            <div class="login-icon"><i class="fa-solid fa-shield-halved"></i></div>
            <h2>Verify Account</h2>
            <span class="branch-tag">Security Check</span>
            <p style="font-size: 13px; color: var(--text-gray); margin-bottom: 20px;">Please enter the 6-digit code sent to your email address to continue logging in.</p>

            <form action="login.php" method="POST">
                <div class="form-group">
                    <input type="text" name="otp_code" class="otp-input" maxlength="6" pattern="[0-9]{6}" required placeholder="000000" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                </div>
                <button type="submit" name="verify_login_btn" class="login-btn">Verify & Login</button>
            </form>
            <div class="register-link">
                <a href="logout.php">Cancel & Return to Login</a>
            </div>

        <?php else: ?>
            <div class="login-icon"><i class="fas fa-paw"></i></div>
            <h2>Welcome Back!</h2>
            <span class="branch-tag">Dasmariñas Branch</span>

            <form action="login.php" method="POST">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" name="email" id="email" placeholder="Enter your email" required>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" name="password" id="password" placeholder="Enter your password" required>
                </div>
                <button type="submit" name="login_btn" class="login-btn">Login to Account</button>
            </form>

            <div class="divider"><span>OR</span></div>
            <button id="googleLoginBtn" class="google-btn" type="button">
                <img src="https://upload.wikimedia.org/wikipedia/commons/c/c1/Google_%22G%22_logo.svg" alt="Google Logo" width="20">
                Continue with Google
            </button>

            <div class="register-link">New fur-parent? <a href="register.php">Register here</a></div>
            
            <div class="footer-links">
                <a href="staff/stafflogin.php"><i class="fas fa-user-shield"></i> Personal Portal</a>
                <a href="#"><i class="fas fa-question-circle"></i> Help</a>
            </div>
            <div class="brand-footer">© <?php echo date("Y"); ?> Boogie's Pet Care Services</div>
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