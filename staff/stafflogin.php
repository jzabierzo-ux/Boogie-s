<?php
session_start();
include '../db_connect.php';

/*
|--------------------------------------------------------------------------
| ADMIN / STAFF ACCOUNT LOGGING
|--------------------------------------------------------------------------
| Records only personnel login activity:
| admin, manager, and vet.
|--------------------------------------------------------------------------
*/
function logAdminAccount($conn, $user_id, $action, $status)
{
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';

    $stmt = $conn->prepare("
        INSERT INTO admin_account_logs
        (user_id, action, status, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        error_log("Account log prepare failed: " . $conn->error);
        return;
    }

    $stmt->bind_param(
        "issss",
        $user_id,
        $action,
        $status,
        $ip_address,
        $user_agent
    );

    if (!$stmt->execute()) {
        error_log("Account log insert failed: " . $stmt->error);
    }

    $stmt->close();
}


/*
|--------------------------------------------------------------------------
| PERSONNEL LOGIN
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // ------------------------------------------------------------
    // 1. GET INPUTS
    // ------------------------------------------------------------
    $username_input = trim($_POST['username'] ?? '');
    $password_input = $_POST['password'] ?? '';

    // Basic validation
    if ($username_input === '' || $password_input === '') {
        echo "
            <script>
                alert('Please enter your username/email and password.');
                window.history.back();
            </script>
        ";
        exit;
    }

    // Escape username/email for SQL
    $username_sql = mysqli_real_escape_string($conn, $username_input);


    // ------------------------------------------------------------
    // 2. FIND PERSONNEL ACCOUNT
    // ------------------------------------------------------------
    $query = "
        SELECT *
        FROM users
        WHERE (username = '$username_sql' OR email = '$username_sql')
        AND role IN ('admin', 'manager', 'vet')
        LIMIT 1
    ";

    $result = mysqli_query($conn, $query);


    // ------------------------------------------------------------
    // 3. ACCOUNT FOUND
    // ------------------------------------------------------------
    if ($result && mysqli_num_rows($result) === 1) {

        $user = mysqli_fetch_assoc($result);


        // --------------------------------------------------------
        // 4. VERIFY PASSWORD
        // --------------------------------------------------------
        if (
            isset($user['password']) &&
            password_verify($password_input, $user['password'])
        ) {

            // ====================================================
            // SUCCESSFUL LOGIN
            // ====================================================
            logAdminAccount(
                $conn,
                (int)$user['id'],
                'LOGIN',
                'SUCCESS'
            );


            // ----------------------------------------------------
            // 5. REGENERATE SESSION ID
            // ----------------------------------------------------
            session_regenerate_id(true);


            // ----------------------------------------------------
            // 6. SAVE SESSION DATA
            // ----------------------------------------------------
            $_SESSION['logged_in'] = true;
            $_SESSION['staff_logged_in'] = true;

            $_SESSION['user_id'] = (int)$user['id'];

            $_SESSION['role'] = strtolower(
                trim($user['role'] ?? '')
            );

            $_SESSION['user_name'] =
                !empty($user['full_name'])
                    ? $user['full_name']
                    : 'System User';

            $_SESSION['staff_name'] =
                !empty($user['full_name'])
                    ? $user['full_name']
                    : 'System User';

            $_SESSION['staff_position'] =
                !empty($user['position'])
                    ? $user['position']
                    : 'Personnel';


            // ----------------------------------------------------
            // 7. REDIRECT BASED ON ROLE
            // ----------------------------------------------------
            if (
                in_array(
                    $_SESSION['role'],
                    ['admin', 'manager'],
                    true
                )
            ) {

                header(
                    "Location: ../admin/admindashboard.php"
                );
                exit;

            } elseif ($_SESSION['role'] === 'vet') {

                header(
                    "Location: staffdashboard.php"
                );
                exit;

            } else {

                // Safety fallback
                session_unset();
                session_destroy();

                echo "
                    <script>
                        alert('Unauthorized personnel role.');
                        window.location='../home.php';
                    </script>
                ";
                exit;
            }

        } else {

            // ====================================================
            // WRONG PASSWORD
            // ====================================================
            logAdminAccount(
                $conn,
                (int)$user['id'],
                'LOGIN',
                'FAILED'
            );

            echo "
                <script>
                    alert('Incorrect password.');
                    window.history.back();
                </script>
            ";
            exit;
        }

    } else {

        // ========================================================
        // ACCOUNT NOT FOUND / INVALID USERNAME
        // ========================================================
        logAdminAccount(
            $conn,
            null,
            'LOGIN',
            'FAILED'
        );

        echo "
            <script>
                alert('Invalid Username/Email or Account does not exist.');
                window.history.back();
            </script>
        ";
        exit;
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
                This portal is for authorized Admin,
                Clinic Manager, and Vet only.
            </span>

        </div>


        <div class="footer-links">

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