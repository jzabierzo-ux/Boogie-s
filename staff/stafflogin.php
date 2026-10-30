<?php
require_once __DIR__ . '/../shared_session_bootstrap.php';

/*
 * Normalize legacy role labels without changing the stored database role.
 */
function normalizePersonnelRole($role): string
{
    $role = strtolower(trim((string)$role));
    static $aliases = [
        'administrator' => 'admin',
        'shop manager' => 'manager',
        'shop_manager' => 'manager',
        'vet' => 'veterinarian',
        'veterinary' => 'veterinarian',
        'front desk' => 'receptionist',
        'front desk / receptionist' => 'receptionist',
        'front_desk' => 'receptionist',
        'front_desk_receptionist' => 'receptionist',
        'veterinary assistant' => 'vet_assistant',
        'vet assistant' => 'vet_assistant',
        'veterinary_assistant' => 'vet_assistant',
        'veterinary nurse' => 'vet_nurse',
        'vet nurse' => 'vet_nurse',
        'veterinary_nurse' => 'vet_nurse',
        'grooming staff' => 'groomer',
        'grooming staff / groomer' => 'groomer',
        'grooming_staff' => 'groomer',
        'grooming_staff_groomer' => 'groomer',
        'pet hotel staff' => 'pet_hotel_staff',
        'pet hotel staff / attendant' => 'pet_hotel_staff',
        'pet_hotel_attendant' => 'pet_hotel_staff',
        'pet_hotel_staff_attendant' => 'pet_hotel_staff',
        'staff' => 'groomer',
        'supervisor' => 'receptionist',
    ];

    return $aliases[$role] ?? $role;
}

/** Only personnel roles may use this login; Admin has a separate login page. */
function isAllowedPersonnelRole(string $role): bool
{
    return in_array($role, [
        'manager',
        'receptionist',
        'groomer',
        'pet_hotel_staff',
        'veterinarian',
        'vet_assistant',
        'vet_nurse',
    ], true);
}

/** Write an account event without allowing an audit-log failure to break login. */
function logPersonnelAccount(PDO $pdo, ?int $userId, string $action, string $status): void
{
    try {
        $stmt = $pdo->prepare('
            INSERT INTO admin_account_logs
                (user_id, action, status, ip_address, user_agent)
            VALUES
                (:user_id, :action, :status, :ip_address, :user_agent)
        ');
        $stmt->execute([
            ':user_id' => $userId,
            ':action' => $action,
            ':status' => $status,
            ':ip_address' => (string)($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'),
            ':user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN'),
        ]);
    } catch (Throwable $e) {
        error_log('Personnel account log insert failed: ' . $e->getMessage());
    }
}

function showPersonnelLoginError(string $message): void
{
    $safeMessage = json_encode(
        $message,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    echo '<script>alert(' . $safeMessage . '); window.history.back();</script>';
    exit;
}

// Keep already-authenticated users in the correct portal.
$sessionRole = normalizePersonnelRole($_SESSION['role'] ?? '');
$sessionLoggedIn = ($_SESSION['logged_in'] ?? false) === true;
$sessionUserId = filter_var(
    $_SESSION['user_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$validSessionUser = $sessionUserId !== false && $sessionUserId !== null;

if (
    $sessionLoggedIn &&
    ($_SESSION['admin_logged_in'] ?? false) === true &&
    $sessionRole === 'admin' &&
    $validSessionUser
) {
    header('Location: ../admin/admindashboard.php?portal=admin');
    exit();
}

if (
    $sessionLoggedIn &&
    ($_SESSION['staff_logged_in'] ?? false) === true &&
    $validSessionUser &&
    isAllowedPersonnelRole($sessionRole)
) {
    if (in_array($sessionRole, ['veterinarian', 'vet_assistant', 'vet_nurse'], true)) {
        header('Location: staffdashboard.php');
    } else {
        header('Location: ../admin/admindashboard.php?portal=staff');
    }
    exit();
}

if (
    empty($_SESSION['personnel_login_csrf']) ||
    !is_string($_SESSION['personnel_login_csrf'])
) {
    $_SESSION['personnel_login_csrf'] = bin2hex(random_bytes(32));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $expectedCsrf = (string)($_SESSION['personnel_login_csrf'] ?? '');
    $postedCsrf = (string)($_POST['personnel_login_csrf'] ?? '');

    if ($expectedCsrf === '' || $postedCsrf === '' || !hash_equals($expectedCsrf, $postedCsrf)) {
        http_response_code(403);
        showPersonnelLoginError('Your login form expired. Refresh the page and try again.');
    }

    $usernameInput = trim((string)($_POST['username'] ?? ''));
    $passwordInput = (string)($_POST['password'] ?? '');

    if ($usernameInput === '' || $passwordInput === '') {
        showPersonnelLoginError('Please enter your username/email and password.');
    }

    try {
        // The database is only needed for a valid POST login attempt.
        require_once __DIR__ . '/../db_supabase.php';

        // Fetch only the fields used by authentication, role routing and session setup.
        // Exact username matches take priority over case-insensitive email matches.
        $stmt = $pdo->prepare('
            SELECT id, username, email, password, role, full_name, position
            FROM users
            WHERE username = :username OR LOWER(email) = LOWER(:email)
            ORDER BY CASE WHEN username = :preferred_username THEN 0 ELSE 1 END
            LIMIT 1
        ');
        $stmt->execute([
            ':username' => $usernameInput,
            ':email' => $usernameInput,
            ':preferred_username' => $usernameInput,
        ]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (
            !$user ||
            !isset($user['password']) ||
            !password_verify($passwordInput, (string)$user['password'])
        ) {
            logPersonnelAccount(
                $pdo,
                $user ? (int)$user['id'] : null,
                'LOGIN',
                'FAILED'
            );
            showPersonnelLoginError('Invalid username/email or password.');
        }

        $normalizedRole = normalizePersonnelRole($user['role'] ?? '');

        if ($normalizedRole === 'admin') {
            logPersonnelAccount($pdo, (int)$user['id'], 'LOGIN', 'FAILED');
            showPersonnelLoginError('This account is not authorized for the Personnel Portal.');
        }

        if (!isAllowedPersonnelRole($normalizedRole)) {
            logPersonnelAccount($pdo, (int)$user['id'], 'LOGIN', 'FAILED');
            http_response_code(403);
            showPersonnelLoginError('This account does not have an authorized personnel role.');
        }

        logPersonnelAccount($pdo, (int)$user['id'], 'LOGIN', 'SUCCESS');

        // Rotate the session ID and replace pre-authentication session data.
        session_regenerate_id(true);
        $_SESSION = [];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['logged_in'] = true;
        $_SESSION['staff_logged_in'] = true;
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['role'] = $normalizedRole;

        $fullName = trim((string)($user['full_name'] ?? ''));
        $_SESSION['user_name'] = $fullName !== '' ? $fullName : 'System User';
        $_SESSION['staff_name'] = $fullName !== '' ? $fullName : 'System User';

        $position = trim((string)($user['position'] ?? ''));
        $_SESSION['staff_position'] = $position !== '' ? $position : 'Personnel';

        // Route each role to its intended area; destination pages must authorize independently.
        if (in_array($normalizedRole, ['veterinarian', 'vet_assistant', 'vet_nurse'], true)) {
            header('Location: staffdashboard.php');
            exit();
        }

        if (in_array($normalizedRole, ['manager', 'receptionist', 'groomer', 'pet_hotel_staff'], true)) {
            header('Location: ../admin/admindashboard.php?portal=staff');
            exit();
        }

        // Defensive fallback; should be unreachable for an allowed role.
        session_unset();
        session_destroy();
        http_response_code(403);
        exit('Unauthorized personnel role.');
    } catch (Throwable $e) {
        error_log('Personnel login processing failed: ' . $e->getMessage());
        showPersonnelLoginError('Unable to process login right now. Please try again.');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#001f3f">
  <title>Personnel Portal | Boogie's Pet Care Services</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    :root {
      --navy: #001f3f;
      --navy-2: #0b355c;
      --yellow: #ffcc00;
      --ink: #12263a;
      --muted: #66778a;
      --line: #e2e9f0;
      --surface: #f4f7fb;
      --white: #fff;
      --shadow: 0 24px 70px rgba(0, 31, 63, .16);
    }
    * { box-sizing: border-box; }
    html { min-height: 100%; }
    body {
      margin: 0; min-height: 100vh; min-height: 100svh; padding: 28px;
      display: grid; place-items: center; background: var(--surface); color: var(--ink);
      font-family: 'Poppins', sans-serif;
    }
    a { color: inherit; }
    .page-shell {
      width: min(100%, 1020px); min-height: 610px; display: grid;
      grid-template-columns: .95fr 1.05fr; background: var(--white);
      border: 1px solid rgba(226,233,240,.95); border-radius: 28px;
      overflow: hidden; box-shadow: var(--shadow);
    }
    .brand-panel {
      position: relative; isolation: isolate; overflow: hidden; padding: 38px 40px;
      display: flex; flex-direction: column; justify-content: space-between;
      color: var(--white); background: linear-gradient(145deg, var(--navy) 0%, #062d50 58%, #0d4167 100%);
    }
    .brand-panel::before, .brand-panel::after {
      content: ''; position: absolute; z-index: -1; border-radius: 50%; pointer-events: none;
      border: 1px solid rgba(255,204,0,.18);
    }
    .brand-panel::before { width: 380px; height: 380px; right: -185px; top: -120px; box-shadow: 0 0 0 28px rgba(255,204,0,.035), 0 0 0 58px rgba(255,204,0,.025); }
    .brand-panel::after { width: 300px; height: 300px; left: -190px; bottom: -145px; }
    .back-home { display: inline-flex; align-items: center; gap: 9px; width: fit-content; color: rgba(255,255,255,.82); text-decoration: none; font-size: 12px; font-weight: 600; transition: color .2s; }
    .back-home:hover { color: var(--yellow); }
    .brand-main { padding: 46px 0 42px; }
    .brand-logo-wrap { width: 86px; height: 86px; padding: 6px; display: grid; place-items: center; border-radius: 20px; background: var(--white); box-shadow: 0 9px 28px rgba(0,0,0,.18); margin-bottom: 25px; }
    .brand-logo { display: block; width: 100%; height: 100%; object-fit: contain; border-radius: 13px; }
    .brand-eyebrow { margin: 0 0 10px; color: var(--yellow); text-transform: uppercase; letter-spacing: 1.8px; font-weight: 800; font-size: 10px; }
    .brand-title { margin: 0; max-width: 360px; font-size: clamp(28px, 3vw, 39px); line-height: 1.18; letter-spacing: -.8px; font-weight: 800; }
    .brand-description { max-width: 340px; margin: 15px 0 0; color: rgba(255,255,255,.75); font-size: 13px; line-height: 1.8; }
    .role-list { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 28px; }
    .role-chip { padding: 7px 11px; border: 1px solid rgba(255,255,255,.19); border-radius: 999px; background: rgba(255,255,255,.07); color: rgba(255,255,255,.88); font-size: 10px; font-weight: 600; }
    .brand-footer { display: flex; align-items: center; gap: 10px; color: rgba(255,255,255,.56); font-size: 10px; }
    .brand-footer i { color: var(--yellow); }
    .form-panel { display: flex; flex-direction: column; justify-content: center; padding: 52px clamp(28px, 5vw, 62px); }
    .form-topline { display: flex; align-items: center; gap: 8px; margin-bottom: 25px; color: #6b7b8c; font-size: 10px; font-weight: 800; letter-spacing: 1.15px; text-transform: uppercase; }
    .form-topline span { width: 23px; height: 3px; border-radius: 4px; background: var(--yellow); }
    .form-title { margin: 0; color: var(--navy); font-size: clamp(27px, 3vw, 34px); line-height: 1.2; letter-spacing: -.6px; font-weight: 800; }
    .form-subtitle { margin: 10px 0 27px; color: var(--muted); font-size: 12px; line-height: 1.8; }
    .security-note { display: flex; gap: 10px; align-items: flex-start; padding: 12px 13px; margin-bottom: 24px; background: #fff9df; border: 1px solid #ffe58a; border-radius: 12px; color: #705a08; font-size: 10px; line-height: 1.6; }
    .security-note i { margin-top: 2px; color: #997800; }
    .form-group { margin-bottom: 18px; }
    label { display: block; margin-bottom: 8px; color: #20384f; font-size: 11px; font-weight: 700; }
    .input-wrap { position: relative; }
    .input-wrap > i { position: absolute; top: 50%; left: 15px; transform: translateY(-50%); color: #8393a5; font-size: 14px; pointer-events: none; }
    input {
      display: block; width: 100%; height: 51px; padding: 0 44px 0 43px;
      color: var(--ink); background: #fbfcfe; border: 1px solid #dbe4ed;
      border-radius: 12px; outline: none; font: inherit; font-size: 12px;
      transition: border-color .2s, box-shadow .2s, background .2s;
    }
    input::placeholder { color: #9aa7b6; }
    input:focus { background: var(--white); border-color: #537da1; box-shadow: 0 0 0 4px rgba(11,59,102,.09); }
    .password-toggle { position: absolute; top: 50%; right: 10px; transform: translateY(-50%); display: grid; place-items: center; width: 32px; height: 32px; border: 0; border-radius: 8px; background: transparent; color: #74869a; cursor: pointer; }
    .password-toggle:hover { background: #edf2f7; color: var(--navy); }
    .login-btn { display: flex; justify-content: center; align-items: center; gap: 10px; width: 100%; min-height: 52px; margin-top: 7px; padding: 13px 18px; border: 0; border-radius: 12px; background: var(--navy); color: var(--yellow); font: inherit; font-weight: 800; font-size: 11px; letter-spacing: .8px; cursor: pointer; box-shadow: 0 8px 18px rgba(0,31,63,.16); transition: transform .18s, background .18s, box-shadow .18s; }
    .login-btn:hover { transform: translateY(-1px); background: #082b4b; box-shadow: 0 12px 22px rgba(0,31,63,.2); }
    .login-btn:focus-visible, .password-toggle:focus-visible, a:focus-visible { outline: 3px solid #7da9d1; outline-offset: 3px; }
    .form-links { display: flex; justify-content: center; flex-wrap: wrap; gap: 14px 20px; padding-top: 22px; margin-top: 23px; border-top: 1px solid #edf1f5; }
    .form-links a { display: inline-flex; align-items: center; gap: 7px; color: #667a8e; text-decoration: none; font-size: 10px; font-weight: 600; }
    .form-links a:hover { color: var(--navy); }
    .form-links .customer-link { color: var(--navy); }
    .copyright { margin-top: 27px; color: #9aa7b6; font-size: 9px; text-align: center; }
    @media (max-width: 760px) {
      body { padding: 16px; align-items: start; }
      .page-shell { max-width: 480px; min-height: auto; grid-template-columns: 1fr; border-radius: 22px; margin: auto; }
      .brand-panel { padding: 22px 24px 25px; }
      .brand-main { display: grid; grid-template-columns: 66px minmax(0,1fr); grid-template-areas: "logo eyebrow" "logo title" "description description" "roles roles"; column-gap: 16px; align-items: center; padding: 26px 0 14px; }
      .brand-logo-wrap { width: 66px; height: 66px; border-radius: 16px; margin: 0; grid-row: span 2; }
      .brand-eyebrow { margin-bottom: 5px; font-size: 9px; letter-spacing: 1.1px; }
      .brand-title { font-size: 25px; }
      .brand-description { grid-column: 1 / -1; margin-top: 15px; font-size: 11px; }
      .role-list { display: none; }
      .brand-footer { font-size: 9px; }
      .form-panel { padding: 29px 25px 25px; }
      .form-topline { margin-bottom: 16px; }
      .form-subtitle { margin-bottom: 20px; }
      .security-note { margin-bottom: 20px; }
      .copyright { margin-top: 20px; }
    }
    @media (max-width: 380px) {
      body { padding: 10px; }
      .brand-panel { padding: 18px 18px 21px; }
      .form-panel { padding: 25px 18px 20px; }
      .brand-title { font-size: 22px; }
      .form-title { font-size: 27px; }
      .form-links { gap: 12px; }
    }
    @media (prefers-reduced-motion: reduce) { *, *::before, *::after { scroll-behavior: auto !important; transition: none !important; } }
  </style>
</head>
<body>
  <main class="page-shell">
    <section class="brand-panel" aria-label="Boogie's Pet Care Services">
      <a href="../home.php" class="back-home"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to website</a>
      <div class="brand-main">
        <div class="brand-logo-wrap" style="grid-area:logo"><img src="../admin/bg.png" class="brand-logo" alt="Boogie's Pet Care Services logo"></div>
        <p class="brand-eyebrow" style="grid-area:eyebrow">Boogie's Pet Care &amp; Services</p>
        <h1 class="brand-title" style="grid-area:title">Your work. Their care.</h1>
        <p class="brand-description" style="grid-area:description">Sign in to access the staff tools for bookings, customer details, and pet care records assigned to your role.</p>
        <div class="role-list" style="grid-area:roles" aria-label="Authorized personnel roles">
          <span class="role-chip">Manager</span>
          <span class="role-chip">Receptionist</span>
          <span class="role-chip">Groomer</span>
          <span class="role-chip">Pet Hotel Staff</span>
          <span class="role-chip">Veterinary Team</span>
        </div>
      </div>
      <div class="brand-footer"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Secure access for authorized personnel</div>
    </section>

    <section class="form-panel" aria-labelledby="login-title">
      <div class="form-topline"><span></span> Personnel Portal</div>
      <h2 class="form-title" id="login-title">Welcome back</h2>
      <p class="form-subtitle">Enter your assigned username or email and password to continue.</p>

      <div class="security-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><div><strong>Staff access only.</strong> Your account permissions determine which pages and actions you can use.</div></div>

      <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>" method="POST" autocomplete="on">
        <input type="hidden" name="personnel_login_csrf" value="<?php echo htmlspecialchars($_SESSION['personnel_login_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
        <div class="form-group">
          <label for="username">Username or email</label>
          <div class="input-wrap">
            <i class="fa-regular fa-user" aria-hidden="true"></i>
            <input type="text" id="username" name="username" placeholder="Enter your username or email" autocomplete="username" autocapitalize="none" spellcheck="false" required>
          </div>
        </div>
        <div class="form-group">
          <label for="password">Password</label>
          <div class="input-wrap">
            <i class="fa-solid fa-lock" aria-hidden="true"></i>
            <input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
            <button class="password-toggle" type="button" id="togglePassword" aria-label="Show password" aria-pressed="false"><i class="fa-regular fa-eye" aria-hidden="true"></i></button>
          </div>
        </div>
        <button type="submit" class="login-btn">Sign in securely <i class="fa-solid fa-arrow-right-to-bracket" aria-hidden="true"></i></button>
      </form>

      <nav class="form-links" aria-label="Other pages">
        <a href="../home.php"><i class="fa-solid fa-house" aria-hidden="true"></i> Back to website</a>
        <a class="customer-link" href="../login.php"><i class="fa-regular fa-user" aria-hidden="true"></i> Customer login</a>
      </nav>
      <p class="copyright">&copy; <?php echo date('Y'); ?> Boogie's Pet Care &amp; Services</p>
    </section>
  </main>
  <script>
    (function () {
      const password = document.getElementById('password');
      const toggle = document.getElementById('togglePassword');
      if (!password || !toggle) return;
      toggle.addEventListener('click', function () {
        const visible = password.type === 'password';
        password.type = visible ? 'text' : 'password';
        toggle.setAttribute('aria-pressed', visible ? 'true' : 'false');
        toggle.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
        const icon = toggle.querySelector('i');
        if (icon) icon.className = visible ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye';
      });
    }());
  </script>
</body>
</html>
