<?php
require_once __DIR__ . '/shared_session_bootstrap.php';
require_once __DIR__ . '/db_supabase.php';

$error_msg = '';

// Generate a CSRF token for the admin login form.
if (empty($_SESSION['admin_login_csrf']) || !is_string($_SESSION['admin_login_csrf'])) {
    $_SESSION['admin_login_csrf'] = bin2hex(random_bytes(32));
}

// If this browser already has a valid admin session, go straight to the dashboard.
if (
    ($_SESSION['logged_in'] ?? false) === true &&
    ($_SESSION['admin_logged_in'] ?? false) === true &&
    strtolower(trim((string)($_SESSION['role'] ?? ''))) === 'admin' &&
    (int)($_SESSION['user_id'] ?? 0) > 0
) {
    header('Location: ./admin/admindashboard.php');
    exit();
}

function logAdminAccount(PDO $pdo, ?int $user_id, string $action, string $status): void
{
    $ip_address = (string)($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN');
    $user_agent = (string)($_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN');

    // Avoid unexpectedly large values in audit columns.
    $ip_address = substr($ip_address, 0, 100);
    $user_agent = substr($user_agent, 0, 1000);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO admin_account_logs
                (user_id, action, status, ip_address, user_agent)
            VALUES
                (:user_id, :action, :status, :ip_address, :user_agent)
        ");

        $stmt->execute([
            ':user_id' => $user_id,
            ':action' => $action,
            ':status' => $status,
            ':ip_address' => $ip_address,
            ':user_agent' => $user_agent,
        ]);
    } catch (Throwable $e) {
        // Audit logging must not prevent a legitimate login or error response.
        error_log('Admin account log insert failed: ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string)($_POST['csrf_token'] ?? '');
    $sessionToken = (string)($_SESSION['admin_login_csrf'] ?? '');

    if ($sessionToken === '' || $postedToken === '' || !hash_equals($sessionToken, $postedToken)) {
        $error_msg = 'Your login form expired. Refresh the page and try again.';
        // Rotate the token so a stale form cannot be submitted repeatedly.
        $_SESSION['admin_login_csrf'] = bin2hex(random_bytes(32));
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $error_msg = 'Please enter your username and password.';
        } elseif (mb_strlen($username) > 255 || strlen($password) > 4096) {
            $error_msg = 'Invalid username or password.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    SELECT id, username, password, full_name, role
                    FROM users
                    WHERE username = :username
                      AND LOWER(TRIM(COALESCE(role, ''))) = 'admin'
                    LIMIT 1
                ");
                $stmt->execute([':username' => $username]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user && isset($user['password']) && password_verify($password, (string)$user['password'])) {
                    $adminId = (int)($user['id'] ?? 0);

                    if ($adminId <= 0) {
                        logAdminAccount($pdo, null, 'LOGIN', 'FAILED');
                        $error_msg = 'Unable to process login right now. Please try again.';
                    } else {
                        session_regenerate_id(true);

                        // Set a clean, explicit admin session.
                        $_SESSION['logged_in'] = true;
                        $_SESSION['admin_logged_in'] = true;
                        $_SESSION['user_id'] = $adminId;
                        $_SESSION['role'] = 'admin';
                        $_SESSION['user_name'] = trim((string)($user['full_name'] ?? '')) !== ''
                            ? (string)$user['full_name']
                            : 'Admin';
                        unset($_SESSION['login_temp_email']);
                        $_SESSION['admin_login_csrf'] = bin2hex(random_bytes(32));

                        logAdminAccount($pdo, $adminId, 'LOGIN', 'SUCCESS');
                        header('Location: ./admin/admindashboard.php');
                        exit();
                    }
                } else {
                    logAdminAccount($pdo, $user ? (int)($user['id'] ?? 0) : null, 'LOGIN', 'FAILED');
                    // Use a generic error to avoid revealing whether a username exists.
                    $error_msg = 'Invalid username or password.';
                }
            } catch (PDOException $e) {
                error_log('Admin login query failed: ' . $e->getMessage());
                logAdminAccount($pdo, null, 'LOGIN', 'FAILED');
                $error_msg = 'Unable to process login right now. Please try again.';
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
    <meta name="robots" content="noindex, nofollow">
    <title>Admin Portal - Boogie's</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root{
            --brand-blue:#001f3f;
            --brand-blue-2:#0b3b66;
            --brand-yellow:#ffcc00;
            --page-bg:#eef3f8;
            --white:#fff;
            --text:#17324d;
            --muted:#6b7c8f;
            --line:#d9e2eb;
            --danger:#c73b47;
            --danger-bg:#fff0f1;
        }
        *{box-sizing:border-box;margin:0;padding:0}
        body{
            min-height:100vh;
            padding:20px;
            display:flex;
            align-items:center;
            justify-content:center;
            font-family:'Poppins',sans-serif;
            background:
                radial-gradient(circle at top left, rgba(255,204,0,.18), transparent 32%),
                linear-gradient(135deg,#071a2d,#0f2741 55%,#001f3f);
            color:var(--text);
        }
        .admin-shell{width:100%;max-width:430px}
        .brand-bar{
            display:flex;align-items:center;justify-content:center;gap:10px;
            color:#fff;margin-bottom:14px;font-size:12px;font-weight:700;letter-spacing:.6px;
            text-transform:uppercase;text-align:center;
        }
        .brand-bar i{color:var(--brand-yellow)}
        .login-box{
            width:100%;background:var(--white);padding:34px;border-radius:20px;
            border:1px solid rgba(255,255,255,.5);
            border-top:5px solid var(--brand-yellow);
            box-shadow:0 22px 55px rgba(0,0,0,.28);
            text-align:center;
        }
        .security-icon{
            width:62px;height:62px;margin:0 auto 14px;border-radius:18px;
            display:flex;align-items:center;justify-content:center;
            background:#eef5fb;color:var(--brand-blue);font-size:25px;
        }
        .login-box h2{color:var(--brand-blue);font-size:27px;line-height:1.2;margin-bottom:5px;font-weight:800}
        .subtitle{color:var(--muted);font-size:11px;line-height:1.6;margin-bottom:24px}
        .form-group{margin-bottom:17px;text-align:left}
        .form-group label{display:block;font-size:11px;font-weight:800;color:var(--brand-blue);margin-bottom:7px}
        .input-wrap{position:relative}
        .input-wrap i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#91a0ae;font-size:12px}
        .form-control{
            width:100%;min-height:46px;padding:11px 13px 11px 38px;
            border:1px solid var(--line);border-radius:11px;background:#fbfcfe;
            color:var(--text);font-size:13px;outline:none;transition:.2s;
        }
        .form-control:focus{border-color:#91acc3;background:#fff;box-shadow:0 0 0 3px rgba(0,31,63,.06)}
        .btn-login{
            width:100%;min-height:47px;margin-top:5px;border:0;border-radius:11px;
            background:var(--brand-blue);color:var(--brand-yellow);font-size:13px;font-weight:800;
            cursor:pointer;transition:.2s;box-shadow:0 8px 20px rgba(0,31,63,.18)
        }
        .btn-login:hover{background:var(--brand-blue-2);transform:translateY(-1px)}
        .error{
            display:flex;align-items:flex-start;gap:8px;text-align:left;color:#a92734;
            font-size:10px;line-height:1.5;font-weight:600;background:var(--danger-bg);
            border:1px solid #f1c4c9;padding:11px 12px;border-radius:10px;margin-bottom:16px;
        }
        .notice{
            margin-top:18px;padding-top:16px;border-top:1px solid #edf1f5;
            color:#8493a2;font-size:9px;line-height:1.6;
        }
        .notice i{color:var(--brand-blue);margin-right:4px}
        .footer-note{text-align:center;color:rgba(255,255,255,.65);font-size:9px;margin-top:14px}
        @media (max-width:480px){
            body{padding:14px}
            .login-box{padding:27px 20px;border-radius:16px}
            .login-box h2{font-size:23px}
            .security-icon{width:55px;height:55px;border-radius:15px;font-size:22px}
            .brand-bar{font-size:10px}
        }
        @media (max-width:350px){
            body{padding:10px}
            .login-box{padding:23px 16px}
            .form-control{min-height:44px;font-size:12px}
            .btn-login{min-height:45px}
        }
    </style>
</head>
<body>
    <div class="admin-shell">
        <div class="brand-bar">
            <i class="fa-solid fa-paw"></i>
            Boogie's Pet Care • Admin Access
        </div>

        <div class="login-box">
            <div class="security-icon"><i class="fa-solid fa-user-shield"></i></div>
            <h2>Admin Portal</h2>
            <p class="subtitle">Authorized administrators only. Sign in to access the management dashboard.</p>

            <?php if (!empty($error_msg)): ?>
                <div class="error" role="alert">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <div><?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['admin_login_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
                <div class="form-group">
                    <label for="username">Username</label>
                    <div class="input-wrap">
                        <i class="fa-solid fa-user"></i>
                        <input type="text" id="username" name="username" class="form-control"
                               autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="255" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <i class="fa-solid fa-lock"></i>
                        <input type="password" id="password" name="password" class="form-control"
                               autocomplete="current-password" maxlength="4096" required>
                    </div>
                </div>

                <button type="submit" class="btn-login">
                    <i class="fa-solid fa-right-to-bracket"></i> Login to Dashboard
                </button>
            </form>

            <div class="notice">
                <i class="fa-solid fa-shield-halved"></i>
                Admin login activity is recorded in the account logs.
            </div>
        </div>

        <div class="footer-note">© <?php echo date('Y'); ?> Boogie's Pet Care &amp; Services</div>
    </div>
</body>
</html>
