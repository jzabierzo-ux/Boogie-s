<?php
session_start();
require_once 'db_supabase.php';

$error_msg = '';

/*
|--------------------------------------------------------------------------
| ADMIN ACCOUNT LOGGING
|--------------------------------------------------------------------------
*/
function logAdminAccount(PDO $pdo, $user_id, string $action, string $status): void
{
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';

    try {
        $stmt = $pdo->prepare("\n            INSERT INTO admin_account_logs\n            (user_id, action, status, ip_address, user_agent)\n            VALUES (:user_id, :action, :status, :ip_address, :user_agent)\n        ");

        $stmt->execute([
            ':user_id' => $user_id,
            ':action' => $action,
            ':status' => $status,
            ':ip_address' => $ip_address,
            ':user_agent' => $user_agent
        ]);
    } catch (PDOException $e) {
        error_log("Admin log insert failed: " . $e->getMessage());
    }
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
        try {
            $stmt = $pdo->prepare("\n                SELECT *\n                FROM users\n                WHERE username = :username\n                AND role = 'admin'\n                LIMIT 1\n            ");
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                if (isset($user['password']) && password_verify($password, $user['password'])) {
                    logAdminAccount($pdo, (int)$user['id'], 'LOGIN', 'SUCCESS');

                    session_regenerate_id(true);

                    $_SESSION['logged_in'] = true;
                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['user_id'] = (int)$user['id'];
                    $_SESSION['role'] = 'admin';
                    $_SESSION['user_name'] = !empty($user['full_name']) ? $user['full_name'] : 'Admin';

                    header("Location: ./admin/admindashboard.php");
                    exit();
                } else {
                    logAdminAccount($pdo, (int)$user['id'], 'LOGIN', 'FAILED');
                    $error_msg = "Incorrect password.";
                }
            } else {
                logAdminAccount($pdo, null, 'LOGIN', 'FAILED');
                $error_msg = "Access Denied: Admin account not found.";
            }
        } catch (PDOException $e) {
            error_log("Admin login query failed: " . $e->getMessage());
            logAdminAccount($pdo, null, 'LOGIN', 'FAILED');
            $error_msg = "Unable to process login right now. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Portal - Boogie's</title>
    <style>
        * { box-sizing: border-box; }
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
        .login-box h2 { color: #1e293b; margin-bottom: 20px; }
        .form-group { margin-bottom: 15px; text-align: left; }
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
        .form-control:focus { border-color: #6366f1; }
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
        .btn-login:hover { background: #4f46e5; }
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
                <label for="username">Username</label>
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
                <label for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    class="form-control"
                    autocomplete="current-password"
                    required
                >
            </div>

            <button type="submit" class="btn-login">Login to Dashboard</button>
        </form>
    </div>
</body>
</html>
