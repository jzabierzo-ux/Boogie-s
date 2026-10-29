<?php

require_once __DIR__ . '/../shared_session_bootstrap.php';

require_once __DIR__ . '/../db_supabase.php';

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

    header('Location: ./admin/admindashboard.php?portal=admin');

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

                        // Clear any prior customer or staff session before creating an admin-only session.

                        $_SESSION = [];

                        $_SESSION['logged_in'] = true;

                        $_SESSION['admin_logged_in'] = true;

                        $_SESSION['user_id'] = $adminId;

                        $_SESSION['role'] = 'admin';

                        $_SESSION['user_name'] = trim((string)($user['full_name'] ?? '')) !== ''

                            ? (string)$user['full_name']

                            : 'Admin';

                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                        $_SESSION['admin_login_csrf'] = bin2hex(random_bytes(32));

                        logAdminAccount($pdo, $adminId, 'LOGIN', 'SUCCESS');

                        header('Location: ./admin/admindashboard.php?portal=admin');

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
    :root {
        --brand-blue: #001f3f;
        --brand-blue-2: #0b3b66;
        --brand-yellow: #ffcc00;
        --page-bg: #f3f7fb;
        --white: #ffffff;
        --text: #17324d;
        --muted: #6b7c8f;
        --line: #dce5ee;
        --danger: #a92734;
        --danger-bg: #fff0f1;
        --shadow: 0 24px 70px rgba(0, 31, 63, .16);
    }

    * { box-sizing: border-box; }
    html { min-height: 100%; width: 100%; }
    body {
        min-height: 100vh;
        min-height: 100svh;
        margin: 0;
        padding: 28px;
        display: flex;
        align-items: center;
        justify-content: center;
        background:
            radial-gradient(circle at 12% 8%, rgba(255, 204, 0, .15), transparent 26%),
            linear-gradient(135deg, #eaf0f6 0%, #f7f9fc 55%, #e8eff6 100%);
        color: var(--text);
        font-family: 'Poppins', sans-serif;
    }

    .admin-shell {
        width: min(100%, 980px);
        min-height: 590px;
        display: grid;
        grid-template-columns: minmax(0, .92fr) minmax(0, 1.08fr);
        overflow: hidden;
        background: var(--white);
        border: 1px solid rgba(255,255,255,.8);
        border-radius: 26px;
        box-shadow: var(--shadow);
    }

    .brand-panel {
        position: relative;
        isolation: isolate;
        overflow: hidden;
        padding: 34px 38px;
        display: flex;
        flex-direction: column;
        color: #fff;
        background: linear-gradient(155deg, #001a33 0%, #073456 62%, #0b4166 100%);
    }
    .brand-panel::before,
    .brand-panel::after {
        content: '';
        position: absolute;
        z-index: -1;
        border: 1px solid rgba(255, 204, 0, .17);
        border-radius: 50%;
        pointer-events: none;
    }
    .brand-panel::before { width: 390px; height: 390px; right: -205px; top: -160px; box-shadow: 0 0 0 25px rgba(255,255,255,.025), 0 0 0 50px rgba(255,255,255,.02); }
    .brand-panel::after { width: 210px; height: 210px; left: -145px; bottom: -125px; }

    .back-home {
        align-self: flex-start;
        display: inline-flex;
        align-items: center;
        gap: 9px;
        color: rgba(255,255,255,.88);
        text-decoration: none;
        font-size: 12px;
        font-weight: 700;
        transition: color .18s ease, transform .18s ease;
    }
    .back-home:hover { color: var(--brand-yellow); transform: translateX(-2px); }

    .brand-content { margin: auto 0; padding: 42px 0 35px; }
    .brand-logo {
        display: block;
        width: 78px;
        height: 78px;
        margin-bottom: 24px;
        padding: 5px;
        object-fit: contain;
        background: #fff;
        border: 3px solid rgba(255,255,255,.95);
        border-radius: 19px;
        box-shadow: 0 8px 25px rgba(0,0,0,.14);
    }
    .brand-kicker {
        margin: 0 0 13px;
        color: var(--brand-yellow);
        font-size: 10px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 1.35px;
    }
    .brand-title {
        max-width: 360px;
        margin: 0;
        color: #fff;
        font-size: clamp(28px, 3.1vw, 39px);
        font-weight: 800;
        line-height: 1.17;
        letter-spacing: -.8px;
    }
    .brand-description {
        max-width: 340px;
        margin: 16px 0 0;
        color: rgba(255,255,255,.76);
        font-size: 12px;
        line-height: 1.85;
    }
    .brand-features { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 25px; }
    .brand-features span {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 8px 10px;
        color: rgba(255,255,255,.9);
        background: rgba(255,255,255,.07);
        border: 1px solid rgba(255,255,255,.14);
        border-radius: 999px;
        font-size: 9px;
        font-weight: 600;
    }
    .brand-features i { color: var(--brand-yellow); }
    .brand-footer {
        display: flex;
        align-items: center;
        gap: 8px;
        color: rgba(255,255,255,.62);
        font-size: 10px;
        line-height: 1.5;
    }
    .brand-footer i { color: var(--brand-yellow); }

    .login-panel {
        min-width: 0;
        padding: 54px clamp(28px, 5vw, 60px) 34px;
        display: flex;
        flex-direction: column;
        justify-content: center;
    }
    .login-kicker {
        display: inline-flex;
        align-items: center;
        gap: 9px;
        margin-bottom: 14px;
        color: #65788b;
        font-size: 10px;
        font-weight: 800;
        letter-spacing: 1.15px;
        text-transform: uppercase;
    }
    .login-kicker::before { content: ''; display: block; width: 22px; height: 3px; border-radius: 9px; background: var(--brand-yellow); }
    .login-panel h2 {
        margin: 0;
        color: var(--brand-blue);
        font-size: clamp(27px, 3vw, 35px);
        line-height: 1.18;
        font-weight: 800;
        letter-spacing: -.7px;
    }
    .subtitle { margin: 11px 0 25px; color: var(--muted); font-size: 12px; line-height: 1.75; }
    .admin-only-note {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        margin-bottom: 24px;
        padding: 12px 13px;
        color: #795d00;
        background: #fff8df;
        border: 1px solid #ffe28a;
        border-radius: 12px;
        font-size: 10px;
        line-height: 1.6;
    }
    .admin-only-note i { margin-top: 2px; color: #a77b00; }

    .error {
        display: flex;
        align-items: flex-start;
        gap: 9px;
        margin-bottom: 18px;
        padding: 12px 13px;
        color: var(--danger);
        background: var(--danger-bg);
        border: 1px solid #f1c4c9;
        border-radius: 11px;
        font-size: 11px;
        font-weight: 600;
        line-height: 1.55;
        text-align: left;
    }
    .error i { margin-top: 2px; }
    .form-group { margin-bottom: 18px; }
    .form-group label { display: block; margin-bottom: 8px; color: var(--brand-blue); font-size: 11px; font-weight: 800; }
    .input-wrap { position: relative; }
    .input-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #8b9bad; font-size: 13px; pointer-events: none; }
    .form-control {
        width: 100%;
        min-height: 50px;
        padding: 13px 44px 13px 41px;
        border: 1px solid var(--line);
        border-radius: 12px;
        outline: none;
        background: #fbfcfe;
        color: var(--text);
        font: inherit;
        font-size: 12px;
        transition: border-color .2s, box-shadow .2s, background .2s;
    }
    .form-control::placeholder { color: #9aa8b7; }
    .form-control:focus { border-color: #8faac4; background: #fff; box-shadow: 0 0 0 3px rgba(0,31,63,.07); }
    .password-toggle {
        position: absolute;
        top: 50%;
        right: 9px;
        width: 32px;
        height: 32px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transform: translateY(-50%);
        border: 0;
        border-radius: 8px;
        background: transparent;
        color: #75889b;
        cursor: pointer;
    }
    .password-toggle:hover { background: #eef3f8; color: var(--brand-blue); }
    .btn-login {
        width: 100%;
        min-height: 50px;
        margin-top: 5px;
        padding: 13px 16px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        border: 0;
        border-radius: 12px;
        background: var(--brand-blue);
        color: var(--brand-yellow);
        font: inherit;
        font-size: 12px;
        font-weight: 800;
        cursor: pointer;
        box-shadow: 0 9px 22px rgba(0,31,63,.15);
        transition: background .2s, transform .2s, box-shadow .2s;
    }
    .btn-login:hover { background: var(--brand-blue-2); transform: translateY(-1px); box-shadow: 0 12px 25px rgba(0,31,63,.2); }
    .btn-login:focus-visible, .password-toggle:focus-visible, .back-home:focus-visible { outline: 3px solid rgba(255,204,0,.75); outline-offset: 3px; }
    .notice { margin-top: 22px; padding-top: 18px; border-top: 1px solid #edf1f5; color: #7d8d9d; font-size: 10px; line-height: 1.7; text-align: center; }
    .notice i { margin-right: 5px; color: var(--brand-blue); }
    .footer-note { margin-top: 18px; color: #96a3b1; font-size: 9px; line-height: 1.5; text-align: center; }

    @media (max-width: 760px) {
        body { padding: 16px; align-items: flex-start; }
        .admin-shell { max-width: 520px; min-height: 0; margin: auto; grid-template-columns: minmax(0, 1fr); border-radius: 22px; }
        .brand-panel { padding: 22px 25px 20px; }
        .brand-content { margin: 0; padding: 22px 0 20px; }
        .brand-logo { width: 58px; height: 58px; margin-bottom: 14px; padding: 4px; border-radius: 14px; }
        .brand-kicker { margin-bottom: 8px; font-size: 9px; }
        .brand-title { max-width: 100%; font-size: 27px; }
        .brand-description { margin-top: 9px; font-size: 11px; line-height: 1.6; }
        .brand-features { gap: 6px; margin-top: 13px; }
        .brand-features span { padding: 6px 8px; font-size: 9px; }
        .brand-footer { font-size: 9px; }
        .login-panel { padding: 30px 25px 24px; }
        .login-panel h2 { font-size: 29px; }
        .subtitle { margin-bottom: 20px; }
        .admin-only-note { margin-bottom: 20px; }
    }
    @media (max-width: 380px) {
        body { padding: 9px; }
        .admin-shell { border-radius: 17px; }
        .brand-panel { padding: 18px 18px 16px; }
        .brand-content { padding: 17px 0; }
        .brand-title { font-size: 24px; }
        .brand-features span { font-size: 8px; }
        .login-panel { padding: 25px 18px 20px; }
        .login-panel h2 { font-size: 26px; }
        .form-control { min-height: 47px; font-size: 12px; }
        .btn-login { min-height: 47px; }
    }
    @media (max-height: 650px) and (min-width: 761px) {
        body { align-items: flex-start; }
        .admin-shell { margin: 10px auto; min-height: 0; }
        .brand-panel { padding-top: 24px; padding-bottom: 24px; }
        .brand-content { padding: 28px 0 20px; }
        .login-panel { padding-top: 28px; padding-bottom: 25px; }
    }
    @media (prefers-reduced-motion: reduce) { *, *::before, *::after { transition: none !important; scroll-behavior: auto !important; } }
</style>

</head>

<body>
    <main class="admin-shell">
        <section class="brand-panel" aria-label="Boogie's Pet Care and Services">
            <a href="home.php" class="back-home"><i class="fa-solid fa-arrow-left"></i> Back to website</a>
            <div class="brand-content">
                <img class="brand-logo" src="bg.png" alt="Boogie's Pet Care &amp; Services logo">
                <p class="brand-kicker">Boogie's Pet Care &amp; Services</p>
                <h1 class="brand-title">Manage with confidence. Care with purpose.</h1>
                <p class="brand-description">Sign in to manage appointments, customer information, pet profiles, and daily operations securely.</p>
                <div class="brand-features" aria-label="Admin portal features">
                    <span><i class="fa-solid fa-calendar-check"></i> Bookings</span>
                    <span><i class="fa-solid fa-paw"></i> Pet profiles</span>
                    <span><i class="fa-solid fa-chart-line"></i> Reports</span>
                </div>
            </div>
            <div class="brand-footer"><i class="fa-solid fa-shield-halved"></i> Secure access for authorized administrators only</div>
        </section>

        <section class="login-panel" aria-label="Administrator sign in">
            <div class="login-kicker">Administrator portal</div>
            <h2>Welcome back</h2>
            <p class="subtitle">Enter your administrator username and password to continue.</p>

            <div class="admin-only-note"><i class="fa-solid fa-circle-info"></i><div><strong>Admin access only.</strong> Staff accounts must use the separate Personnel Portal.</div></div>

            <?php if (!empty($error_msg)): ?>
                <div class="error" role="alert">
                    <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                    <div><?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['admin_login_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
                <div class="form-group">
                    <label for="username">Username</label>
                    <div class="input-wrap">
                        <i class="input-icon fa-solid fa-user" aria-hidden="true"></i>
                        <input type="text" id="username" name="username" class="form-control"
                               autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="255" placeholder="Enter your admin username" required>
                    </div>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <i class="input-icon fa-solid fa-lock" aria-hidden="true"></i>
                        <input type="password" id="password" name="password" class="form-control"
                               autocomplete="current-password" maxlength="4096" placeholder="Enter your password" required>
                        <button type="button" class="password-toggle" id="togglePassword" aria-label="Show password" aria-pressed="false">
                            <i class="fa-regular fa-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn-login"><i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i> Login to Dashboard</button>
            </form>

            <div class="notice"><i class="fa-solid fa-shield-halved"></i> Admin login activity is recorded in the account logs.</div>
            <div class="footer-note">&copy; <?php echo date('Y'); ?> Boogie's Pet Care &amp; Services</div>
        </section>
    </main>
    <script>
        (function () {
            const password = document.getElementById('password');
            const toggle = document.getElementById('togglePassword');
            if (!password || !toggle) return;
            toggle.addEventListener('click', function () {
                const showing = password.type === 'password';
                password.type = showing ? 'text' : 'password';
                toggle.setAttribute('aria-label', showing ? 'Hide password' : 'Show password');
                toggle.setAttribute('aria-pressed', showing ? 'true' : 'false');
                const icon = toggle.querySelector('i');
                if (icon) {
                    icon.classList.toggle('fa-eye', !showing);
                    icon.classList.toggle('fa-eye-slash', showing);
                }
            });
        })();
    </script>
</body>

</html>
