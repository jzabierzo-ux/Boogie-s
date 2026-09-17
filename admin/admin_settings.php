<?php
session_start();
include '../db_connect.php'; 

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: adminlogin.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';

// Handle Password Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    // Fetch current hashed password from DB
    $get_pass = mysqli_query($conn, "SELECT password FROM users WHERE id = '$user_id'");
    $row = mysqli_fetch_assoc($get_pass);

    if (password_verify($current_password, $row['password'])) {
        if ($new_password === $confirm_password) {
            $hashed_new_password = password_hash($new_password, PASSWORD_DEFAULT);
            $update_query = "UPDATE users SET password = '$hashed_new_password' WHERE id = '$user_id'";
            
            if (mysqli_query($conn, $update_query)) {
                $message = '<div class="alert success">Password successfully updated!</div>';
            } else {
                $message = '<div class="alert error">Failed to update password in database.</div>';
            }
        } else {
            $message = '<div class="alert error">New passwords do not match.</div>';
        }
    } else {
        $message = '<div class="alert error">Incorrect current password.</div>';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings | Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Use the same basic CSS from the profile page */
        :root { --navy-dark: #001f3f; --brand-yellow: #ffcc00; --admin-purple: #8b2cf5; --bg-light: #f4f7f6; --white: #ffffff; }
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background-color: var(--bg-light); margin: 0; display: flex; min-height: 100vh; }
        .container { padding: 40px; width: 100%; max-width: 800px; margin: 0 auto; }
        .form-box { background: var(--white); padding: 30px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-size: 14px; font-weight: 600; color: var(--navy-dark); margin-bottom: 8px; }
        .form-group input { width: 100%; padding: 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px; box-sizing: border-box; }
        .btn-submit { background: var(--admin-purple); color: white; border: none; padding: 12px 24px; border-radius: 6px; font-weight: bold; cursor: pointer; transition: 0.2s; }
        .btn-submit:hover { background: #7a22db; }
        .alert { padding: 15px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; }
        .alert.success { background: #dcfce7; color: #166534; }
        .alert.error { background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>
    <main style="flex-grow: 1;">
        <div class="container">
            <h2 style="color: var(--navy-dark); margin-bottom: 20px;"><i class="fas fa-cog"></i> Account Settings</h2>
            
            <div class="form-box">
                <h3 style="margin-bottom: 15px; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 10px;">Change Password</h3>
                <?php echo $message; ?>
                <form method="POST" action="">
                    <div class="form-group">
                        <label>Current Password</label>
                        <input type="password" name="current_password" required placeholder="Enter current password">
                    </div>
                    <div class="form-group">
                        <label>New Password</label>
                        <input type="password" name="new_password" required placeholder="Enter new password">
                    </div>
                    <div class="form-group">
                        <label>Confirm New Password</label>
                        <input type="password" name="confirm_password" required placeholder="Confirm new password">
                    </div>
                    <button type="submit" name="update_password" class="btn-submit"><i class="fas fa-key"></i> Update Password</button>
                </form>
            </div>
        </div>
    </main>
</body>
</html>