<?php

require_once __DIR__ . '/../shared_session_bootstrap.php';

require_once '../db_supabase.php';



/*

|--------------------------------------------------------------------------

| PERSONNEL ACCOUNT LOGGING

|--------------------------------------------------------------------------

*/

function logAdminAccount($pdo, $user_id, $action, $status)

{

    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';

    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';



    try {

        $stmt = $pdo->prepare("

            INSERT INTO admin_account_logs

                (user_id, action, status, ip_address, user_agent)

            VALUES

                (:user_id, :action, :status, :ip_address, :user_agent)

        ");

        $stmt->execute([

            ':user_id' => $user_id !== null ? (int)$user_id : null,

            ':action' => $action,

            ':status' => $status,

            ':ip_address' => $ip_address,

            ':user_agent' => $user_agent

        ]);

    } catch (PDOException $e) {

        // A logging issue must not expose database details to the user.

        error_log('Account log insert failed: ' . $e->getMessage());

    }

}



/** Normalize role labels without changing the stored database role. */

function normalizePersonnelRole($role)

{

    $role = strtolower(trim((string)$role));

    $aliases = [

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
        // Normalize legacy database labels consistently with the other staff pages.
        'staff' => 'groomer',
        'supervisor' => 'receptionist'

    ];



    return $aliases[$role] ?? $role;

}



/**
 * Only personnel roles may use this login. Admin accounts use admin_login.php.
 */

function isAllowedPersonnelRole($role)
{
    // Admin is intentionally excluded because Admin has a separate login page.
    return in_array($role, [
        'manager',
        'receptionist',
        'groomer',
        'pet_hotel_staff',
        'veterinarian',
        'vet_assistant',
        'vet_nurse'
    ], true);
}


function showPersonnelLoginError($message)

{

    $safeMessage = json_encode(

        $message,

        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT

    );

    echo '<script>alert(' . $safeMessage . '); window.history.back();</script>';

    exit;

}



if (empty($_SESSION['personnel_login_csrf']) || !is_string($_SESSION['personnel_login_csrf'])) {
    $_SESSION['personnel_login_csrf'] = bin2hex(random_bytes(32));
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

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

        // Retrieve by username/email first, then verify the stored password.

        // Role matching happens after lookup so legacy labels can be normalized.

        $stmt = $pdo->prepare("

            SELECT *

            FROM users

            WHERE username = :username OR LOWER(email) = LOWER(:email)

            LIMIT 1

        ");

        $stmt->execute([

            ':username' => $usernameInput,

            ':email' => $usernameInput

        ]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);



        if (

            !$user ||

            !isset($user['password']) ||

            !password_verify($passwordInput, (string)$user['password'])

        ) {

            logAdminAccount(

                $pdo,

                $user ? (int)$user['id'] : null,

                'LOGIN',

                'FAILED'

            );

            showPersonnelLoginError('Invalid username/email or password.');

        }



        $normalizedRole = normalizePersonnelRole($user['role'] ?? '');

        if ($normalizedRole === 'admin') {
            logAdminAccount($pdo, (int)$user['id'], 'LOGIN', 'FAILED');
            showPersonnelLoginError('Admin accounts must sign in through the separate Admin Login page.');
        }

        if (!isAllowedPersonnelRole($normalizedRole)) {

            logAdminAccount($pdo, (int)$user['id'], 'LOGIN', 'FAILED');

            http_response_code(403);

            showPersonnelLoginError('This account does not have an authorized personnel role.');

        }



        logAdminAccount($pdo, (int)$user['id'], 'LOGIN', 'SUCCESS');
        session_regenerate_id(true);
        $_SESSION = [];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['logged_in'] = true;

        $_SESSION['staff_logged_in'] = true;

        $_SESSION['user_id'] = (int)$user['id'];

        $_SESSION['role'] = $normalizedRole;

        $_SESSION['user_name'] = !empty($user['full_name'])

            ? $user['full_name']

            : 'System User';

        $_SESSION['staff_name'] = !empty($user['full_name'])

            ? $user['full_name']

            : 'System User';

        $_SESSION['staff_position'] = !empty($user['position'])

            ? $user['position']

            : 'Personnel';



        // Route each role to its intended area. Destination pages must enforce

        // their own server-side authorization; redirects are not permissions.

        if (in_array(

            $normalizedRole,

            ['veterinarian', 'vet_assistant', 'vet_nurse'],

            true

        )) {

            header('Location: staffdashboard.php');

            exit;

        }



        if (in_array(

            $normalizedRole,

            ['manager', 'receptionist', 'groomer', 'pet_hotel_staff'],

            true

        )) {

            header('Location: ../admin/admindashboard.php');

            exit;

        }



        // Defensive fallback; should be unreachable for an allowed role.

        session_unset();

        session_destroy();

        http_response_code(403);

        exit('Unauthorized personnel role.');



    } catch (PDOException $e) {

        error_log('Personnel login query failed: ' . $e->getMessage());

        showPersonnelLoginError('Unable to process login right now. Please try again.');

    }

}

?>



<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta

        name="viewport"

        content="width=device-width, initial-scale=1.0"

    >



    <title>Personnel Portal - Boogie's Pet Care Services</title>



    <link

        rel="stylesheet"

        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"

    >



    <style>

        :root {

            --brand-yellow: #ffcc00;

            --brand-navy: #001a33;

            --white: #ffffff;

            --light-gray: #f1f5f9;

            --text-dark: #001f3f;

            --text-gray: #64748b;

            --staff-note-bg: #fdf4ff;

            --staff-note-text: #701a75;

        }



        * {

            box-sizing: border-box;

        }



        body {

            margin: 0;

            padding: 0;

            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;

            background-color: var(--brand-yellow);

            display: flex;

            justify-content: center;

            align-items: center;

            min-height: 100vh;

            position: relative;

            padding: 20px;

        }



        .back-home {

            position: absolute;

            top: 25px;

            left: 25px;

            text-decoration: none;

            color: var(--brand-navy);

            font-weight: 700;

            display: flex;

            align-items: center;

            gap: 8px;

            font-size: 14px;

            text-transform: uppercase;

        }



        .back-home:hover {

            opacity: 0.75;

        }



        .login-card {

            background: var(--white);

            padding: 45px;

            border-radius: 20px;

            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);

            width: 100%;

            max-width: 420px;

            text-align: center;

            border-bottom: 5px solid var(--brand-navy);

        }



        .login-icon {

            font-size: 50px;

            color: var(--brand-navy);

            margin-bottom: 15px;

        }



        h2 {

            margin: 0 0 5px 0;

            color: var(--text-dark);

            font-size: 28px;

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

            font-size: 14px;

            font-weight: 700;

            margin-bottom: 8px;

            color: var(--text-dark);

        }



        input {

            width: 100%;

            padding: 14px;

            border: 1px solid #e2e8f0;

            border-radius: 10px;

            font-size: 15px;

            transition: all 0.3s ease;

            outline-color: var(--brand-yellow);

        }



        input:focus {

            border-color: var(--brand-navy);

            box-shadow: 0 0 0 3px rgba(0, 26, 51, 0.08);

        }



        .login-btn {

            width: 100%;

            padding: 16px;

            background-color: var(--brand-navy);

            color: var(--brand-yellow);

            border: none;

            border-radius: 10px;

            font-size: 16px;

            font-weight: 800;

            cursor: pointer;

            margin-top: 10px;

            text-transform: uppercase;

            display: flex;

            align-items: center;

            justify-content: center;

            gap: 10px;

            transition: 0.3s;

        }



        .login-btn:hover {

            opacity: 0.9;

            transform: translateY(-2px);

        }



        .staff-note {

            background: var(--staff-note-bg);

            border: 1px solid #fae8ff;

            padding: 12px;

            border-radius: 10px;

            margin-top: 20px;

            text-align: left;

            font-size: 12px;

            color: var(--staff-note-text);

            line-height: 1.4;

            display: flex;

            align-items: center;

            gap: 10px;

        }



        .footer-links {

            margin-top: 30px;

            padding-top: 20px;

            border-top: 1px solid var(--light-gray);

            display: flex;

            justify-content: center;

            gap: 15px;

        }



        .footer-links a {

            color: var(--text-gray);

            text-decoration: none;

            font-size: 13px;

            font-weight: 600;

        }



        .footer-links a:hover {

            color: var(--brand-navy);

        }



        .brand-footer {

            margin-top: 15px;

            font-size: 11px;

            color: var(--text-gray);

        }



        @media (max-width: 600px) {

            .login-card {

                padding: 30px 25px;

            }



            .back-home {

                top: 15px;

                left: 15px;

                font-size: 12px;

            }



            .login-icon {

                font-size: 42px;

            }



            h2 {

                font-size: 24px;

            }

        }



        /* --- MOBILE RESPONSIVE --- */

        @media (max-width: 600px) {

            body {

                align-items: flex-start;

                min-height: 100svh;

                padding: 72px 14px 20px;

            }



            .back-home {

                top: 18px;

                left: 14px;

                right: 14px;

                width: auto;

                font-size: 11px;

            }



            .login-card {

                width: 100%;

                max-width: 420px;

                padding: 30px 20px 22px;

                border-radius: 16px;

            }



            .login-icon {

                font-size: 42px;

                margin-bottom: 12px;

            }



            h2 {

                font-size: 24px;

                line-height: 1.25;

            }



            .branch-tag {

                font-size: 11px;

                margin-bottom: 22px;

            }



            .form-group {

                margin-bottom: 17px;

            }



            label {

                font-size: 13px;

                margin-bottom: 7px;

            }



            input {

                min-height: 50px;

                padding: 13px 14px;

                font-size: 16px;

            }



            .login-btn {

                min-height: 52px;

                padding: 14px 16px;

                font-size: 14px;

                margin-top: 7px;

            }



            .staff-note {

                font-size: 11.5px;

                padding: 12px 11px;

                margin-top: 18px;

                gap: 8px;

            }



            .staff-note i {

                flex-shrink: 0;

            }



            .footer-links {

                margin-top: 24px;

                padding-top: 17px;

                gap: 8px;

            }



            .footer-links a {

                font-size: 12px;

                text-align: center;

            }



            .brand-footer {

                margin-top: 13px;

                font-size: 10px;

                line-height: 1.4;

            }

        }



        @media (max-width: 380px) {

            body {

                padding-left: 10px;

                padding-right: 10px;

            }



            .login-card {

                padding: 26px 16px 20px;

            }



            .back-home {

                left: 10px;

                right: 10px;

                top: 14px;

            }



            .login-icon {

                font-size: 38px;

            }



            h2 {

                font-size: 22px;

            }



            .branch-tag {

                font-size: 10px;

                letter-spacing: 0.7px;

            }



            .staff-note {

                font-size: 11px;

            }



            .footer-links a {

                font-size: 11px;

            }

        }



        @media (max-height: 650px) and (max-width: 600px) {

            body {

                align-items: flex-start;

                padding-top: 58px;

            }



            .login-card {

                padding-top: 22px;

                padding-bottom: 18px;

            }



            .login-icon {

                font-size: 34px;

                margin-bottom: 8px;

            }



            .branch-tag {

                margin-bottom: 15px;

            }



            .form-group {

                margin-bottom: 12px;

            }



            .staff-note,

            .footer-links {

                margin-top: 14px;

            }

        }





        /* ===== Mobile-flexible layout additions ===== */

        html { width: 100%; overflow-x: hidden; }

        body { width: 100%; max-width: 100%; }

        img { max-width: 100%; }

        input, select, textarea, button { max-width: 100%; }





        /* ===== Final small-screen containment ===== */

        @media (max-width: 600px) {

            html, body { width:100%; max-width:100%; overflow-x:hidden; }

            .container, .login-card { width:100%; max-width:100%; }

            input, select, textarea, button { width:100%; max-width:100%; }

            img { max-width:100%; height:auto; }

        }





/* ================================================================

   FINAL STAFF TOP BAR FIX — MATCH ADMIN MOBILE BEHAVIOR

   Keep the staff name visible on phones while preventing overflow.

   ================================================================ */

.profile-name-text {

    white-space: nowrap !important;

    min-width: 0 !important;

}



@media (max-width: 640px) {

    .main-content > header,

    .main-content > header:first-child {

        width: 100% !important;

        min-width: 0 !important;

        height: 60px !important;

        min-height: 60px !important;

        padding: 0 10px !important;

        display: flex !important;

        flex-wrap: nowrap !important;

        align-items: center !important;

        gap: 7px !important;

        overflow: visible !important;

    }



    .mobile-menu-btn {

        display: inline-flex !important;

        width: 36px !important;

        min-width: 36px !important;

        height: 36px !important;

        flex: 0 0 36px !important;

        align-items: center !important;

        justify-content: center !important;

    }



    .topbar-left {

        flex: 0 0 auto !important;

        min-width: 0 !important;

    }



    /* On mobile the breadcrumb is unnecessary beside the menu button. */

    .breadcrumb {

        display: none !important;

    }



    .top-right-actions {

        display: flex !important;

        align-items: center !important;

        justify-content: flex-end !important;

        gap: 7px !important;

        flex: 1 1 auto !important;

        min-width: 0 !important;

        margin-left: auto !important;

    }



    .notif-wrapper {

        flex: 0 0 auto !important;

    }



    .notif-wrapper > i {

        font-size: 19px !important;

    }



    .role-label {

        display: inline-flex !important;

        align-items: center !important;

        justify-content: center !important;

        flex: 0 0 auto !important;

        white-space: nowrap !important;

        padding: 4px 7px !important;

        font-size: 8px !important;

    }



    .profile-wrapper {

        display: flex !important;

        align-items: center !important;

        flex: 0 1 auto !important;

        min-width: 0 !important;

        max-width: 150px !important;

        gap: 6px !important;

        padding-left: 7px !important;

        border-left: 1px solid var(--border) !important;

        overflow: visible !important;

    }



    .top-avatar,

    .top-avatar-fallback {

        width: 30px !important;

        height: 30px !important;

        min-width: 30px !important;

        flex: 0 0 30px !important;

    }



    /* Keep the staff name visible like the admin top bar. */

    .profile-name-text {

        display: block !important;

        flex: 0 1 105px !important;

        width: 105px !important;

        min-width: 0 !important;

        max-width: 105px !important;

        overflow: hidden !important;

        text-overflow: ellipsis !important;

        white-space: nowrap !important;

        line-height: 1.2 !important;

        font-size: 12px !important;

        font-weight: 700 !important;

        padding-right: 13px !important;

        position: relative !important;

    }



    .profile-name-text i {

        display: none !important;

    }



    .profile-name-text::after {

        content: '\\\f078';

        font-family: 'Font Awesome 6 Free';

        font-weight: 900;

        position: absolute;

        right: 0;

        top: 50%;

        transform: translateY(-50%);

        font-size: 9px;

        color: var(--text-muted, #64748b);

        opacity: .7;

    }

}



@media (max-width: 480px) {

    .main-content > header,

    .main-content > header:first-child {

        padding: 0 9px !important;

        gap: 6px !important;

    }



    .top-right-actions {

        gap: 6px !important;

    }



    .role-label {

        padding: 4px 7px !important;

        font-size: 8px !important;

    }



    .profile-wrapper {

        gap: 5px !important;

        max-width: 138px !important;

        padding-left: 6px !important;

    }



    .profile-name-text {

        flex-basis: 92px !important;

        width: 92px !important;

        max-width: 92px !important;

        font-size: 11px !important;

    }

}



@media (max-width: 400px) {

    .role-label {

        display: none !important;

    }



    .profile-wrapper {

        max-width: 135px !important;

        padding-left: 5px !important;

    }



    .profile-name-text {

        flex-basis: 100px !important;

        width: 100px !important;

        max-width: 100px !important;

    }

}



</style>

</head>



<body>



    <a href="../home.php" class="back-home">

        <i class="fas fa-chevron-left"></i>

        BACK TO WEBSITE

    </a>





    <div class="login-card">



        <div class="login-icon">

            <i class="fas fa-user-shield"></i>

        </div>



        <h2>Personnel Portal</h2>



        <span class="branch-tag">

            DASMARIÑAS BRANCH

        </span>





        <form

            action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>"

            method="POST"

        >
            <input type="hidden" name="personnel_login_csrf" value="<?php echo htmlspecialchars($_SESSION['personnel_login_csrf'], ENT_QUOTES, 'UTF-8'); ?>">

            <div class="form-group">



                <label for="username">

                    Username

                </label>



                <input

                    type="text"

                    id="username"

                    name="username"

                    placeholder="Enter assigned username"

                    autocomplete="username"

                    required

                >



            </div>





            <div class="form-group">



                <label for="password">

                    Security Password

                </label>



                <input

                    type="password"

                    id="password"

                    name="password"

                    placeholder="••••••••"

                    autocomplete="current-password"

                    required

                >



            </div>





            <button

                type="submit"

                class="login-btn"

            >

                SECURE LOGIN

                <i class="fas fa-right-to-bracket"></i>

            </button>



        </form>





        <div class="staff-note">



            <i class="fas fa-info-circle"></i>



            <span>

                <strong>Note:</strong>

                This portal is for authorized staff only: Manager, Receptionist,

                Groomer, Pet Hotel Staff, Veterinarian, Vet Assistant, and Vet Nurse.

                Admin accounts must use the separate Admin Login portal.

            </span>



        </div>





        <div class="footer-links">

            <a href="../admin_login.php">
                <i class="fas fa-user-shield"></i>
                Admin Login
            </a>

            <a href="../login.php">

                <i class="fas fa-user"></i>

                Switch to Customer Login

            </a>



        </div>





        <div class="brand-footer">

            © <?php echo date("Y"); ?> Boogie's Pet Care Services

        </div>



    </div>



</body>

</html>