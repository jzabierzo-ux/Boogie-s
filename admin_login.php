<?php
session_start();
require_once 'db_connect.php';

$error_msg = '';

/*
|--------------------------------------------------------------------------
| ADMIN ACCOUNT LOGGING
|--------------------------------------------------------------------------
*/
function logAdminAccount($conn, $user_id, $action, $status)
{
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';

    if ($user_id === null) {
        $stmt = $conn->prepare("
            INSERT INTO admin_account_logs
            (user_id, action, status, ip_address, user_agent)
            VALUES (NULL, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            error_log("Admin log prepare failed: " . $conn->error);
            return;
        }

        $stmt->bind_param(
            "ssss",
            $action,
            $status,
            $ip_address,
            $user_agent
        );
    } else {
        $stmt = $conn->prepare("
            INSERT INTO admin_account_logs
            (user_id, action, status, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            error_log("Admin log prepare failed: " . $conn->error);
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
    }

    if (!$stmt->execute()) {
        error_log("Admin log insert failed: " . $stmt->error);
    }

    $stmt->close();
}


/*
|--------------------------------------------------------------------------
| ADMIN LOGIN
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error_msg = "Please enter your username and password.";
    } else {

        $username_sql = mysqli_real_escape_string($conn, $username);

        /*
        |--------------------------------------------------------------------------
        | ONLY ADMIN ROLE
        |--------------------------------------------------------------------------
        */
        $query = "
            SELECT *
            FROM users
            WHERE username = '$username_sql'
            AND role = 'admin'
            LIMIT 1
        ";

        $result = mysqli_query($conn, $query);

        if ($result && mysqli_num_rows($result) === 1) {

            $user = mysqli_fetch_assoc($result);

            /*
            |--------------------------------------------------------------------------
            | CHECK PASSWORD
            |--------------------------------------------------------------------------
            */
            if (
                isset($user['password']) &&
                password_verify($password, $user['password'])
            ) {

                /*
                |--------------------------------------------------------------------------
                | SUCCESSFUL ADMIN LOGIN
                |--------------------------------------------------------------------------
                */
                logAdminAccount(
                    $conn,
                    (int)$user['id'],
                    'LOGIN',
                    'SUCCESS'
                );

                /*
                |--------------------------------------------------------------------------
                | REGENERATE SESSION ID
                |--------------------------------------------------------------------------
                */
                session_regenerate_id(true);

                /*
                |--------------------------------------------------------------------------
                | SAVE ADMIN SESSION
                |--------------------------------------------------------------------------
                */
                $_SESSION['logged_in'] = true;
                $_SESSION['admin_logged_in'] = true;

                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['role'] = 'admin';

                $_SESSION['user_name'] =
                    !empty($user['full_name'])
                        ? $user['full_name']
                        : 'Admin';

                /*
                |--------------------------------------------------------------------------
                | REDIRECT
                |--------------------------------------------------------------------------
                */
                header("Location: ./admin/admindashboard.php");
                exit();

            } else {

                /*
                |--------------------------------------------------------------------------
                | WRONG PASSWORD
                |--------------------------------------------------------------------------
                */
                logAdminAccount(
                    $conn,
                    (int)$user['id'],
                    'LOGIN',
                    'FAILED'
                );

                $error_msg = "Incorrect password.";
            }

        } else {

            /*
            |--------------------------------------------------------------------------
            | ADMIN ACCOUNT NOT FOUND
            |--------------------------------------------------------------------------
            */
            logAdminAccount(
                $conn,
                null,
                'LOGIN',
                'FAILED'
            );

            $error_msg = "Access Denied: Admin account not found.";
        }
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

    <title>Admin Portal - Boogie's</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #0f172a;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }

        .login-box {
            background: #fff;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            width: 100%;
            max-width: 350px;
            text-align: center;
        }

        .login-box h2 {
            color: #1e293b;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 15px;
            text-align: left;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: bold;
            color: #475569;
            margin-bottom: 5px;
        }

        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            outline: none;
        }

        .form-control:focus {
            border-color: #6366f1;
        }

        .btn-login {
            width: 100%;
            padding: 10px;
            background: #6366f1;
            color: white;
            border: none;
            border-radius: 4px;
            font-weight: bold;
            cursor: pointer;
            margin-top: 10px;
        }

        .btn-login:hover {
            background: #4f46e5;
        }

        .error {
            color: #ef4444;
            font-size: 13px;
            margin-bottom: 15px;
            background: #fee2e2;
            padding: 10px;
            border-radius: 4px;
        }

    </style>

</head>

<body>

    <div class="login-box">

        <h2>Admin Portal</h2>

        <?php if (!empty($error_msg)): ?>

            <div class="error">
                <?php echo htmlspecialchars($error_msg); ?>
            </div>

        <?php endif; ?>


        <form method="POST" action="">

            <div class="form-group">

                <label for="username">
                    Username
                </label>

                <input
                    type="text"
                    id="username"
                    name="username"
                    class="form-control"
                    autocomplete="username"
                    required
                >

            </div>


            <div class="form-group">

                <label for="password">
                    Password
                </label>

                <input
                    type="password"
                    id="password"
                    name="password"
                    class="form-control"
                    autocomplete="current-password"
                    required
                >

            </div>


            <button
                type="submit"
                class="btn-login"
            >
                Login to Dashboard
            </button>

        </form>

    </div>

</body>

</html>
