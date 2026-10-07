<?php

session_start();

require_once '../db_supabase.php';



// --- UNIVERSAL SECURITY CHECK ---

$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';



if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !in_array($current_role, ['admin', 'supervisor', 'staff'], true)) {

    header("Location: stafflogin.php");

    exit();

}



$success_msg = "";

$error_msg = "";



// 2. HANDLE FORM SUBMISSION (CREATE PET + SEND NOTIFICATION)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $p_name = trim($_POST['name'] ?? '');

    $p_type = trim($_POST['pet_type'] ?? '');

    $p_breed = trim($_POST['breed'] ?? '');

    $p_gender = trim($_POST['gender'] ?? '');

    $p_age = trim($_POST['age'] ?? '');

    $p_weight = trim($_POST['weight'] ?? '');

    $owner_id = (int)($_POST['owner_id'] ?? 0);



    if ($p_name === '' || $p_type === '' || $p_gender === '' || $owner_id <= 0) {

        $error_msg = "Please complete all required pet and owner fields.";

    } else {

        try {

            // Kunin ang full_name ng napiling owner

            $user_stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = :owner_id LIMIT 1");

            $user_stmt->execute([':owner_id' => $owner_id]);

            $user_row = $user_stmt->fetch(PDO::FETCH_ASSOC);



            $owner_name = $user_row['full_name'] ?? 'Unknown Owner';



            if (!$user_row) {

                throw new RuntimeException('Selected customer was not found.');

            }



            // A. INSERT SA PETS TABLE

            $insert_stmt = $pdo->prepare("\n                INSERT INTO pets\n                    (owner_id, owner_name, name, pet_type, breed, gender, age, weight)\n                VALUES\n                    (:owner_id, :owner_name, :name, :pet_type, :breed, :gender, :age, :weight)\n            ");



            $insert_stmt->execute([

                ':owner_id' => $owner_id,

                ':owner_name' => $owner_name,

                ':name' => $p_name,

                ':pet_type' => $p_type,

                ':breed' => $p_breed,

                ':gender' => $p_gender,

                ':age' => $p_age,

                ':weight' => $p_weight

            ]);



            // B. AUTO-NOTIFICATION PARA SA CUSTOMER

            $notif_title = "New Pet Profile Created!";

            $notif_message = "A new pet profile for '{$p_name}' has been successfully registered to your account.";

            $notif_type = "system";



            $notif_stmt = $pdo->prepare("\n                INSERT INTO notifications\n                    (user_id, title, message, type, is_read, created_at)\n                VALUES\n                    (:user_id, :title, :message, :type, 0, CURRENT_TIMESTAMP)\n            ");



            $notif_stmt->execute([

                ':user_id' => $owner_id,

                ':title' => $notif_title,

                ':message' => $notif_message,

                ':type' => $notif_type

            ]);



            $success_msg = "New pet successfully added and notification sent to owner!";

        } catch (PDOException $e) {

            error_log("Add pet failed: " . $e->getMessage());

            $error_msg = "Error adding record. Please try again.";

        } catch (Throwable $e) {

            error_log("Add pet failed: " . $e->getMessage());

            $error_msg = "Error adding record: " . $e->getMessage();

        }

    }

}



// 3. FETCH CUSTOMERS ONLY (Para hindi kasama ang Admin at Vet/Staff)

$users_result = [];



try {

    $users_stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE role = 'customer' ORDER BY full_name ASC");

    $users_stmt->execute();

    $users_result = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    error_log("Fetch customers failed: " . $e->getMessage());

    $error_msg = $error_msg ?: "Unable to load customer records.";

}


// --- ADMIN TOPBAR PROFILE + NOTIFICATIONS ---
$admin_full_name = $_SESSION['user_name'] ?? 'User';
$profile_img_path = '';
$first_name = 'User';

if (isset($_SESSION['user_id'])) {
    try {
        $uid = (int)$_SESSION['user_id'];
        $profile_stmt = $pdo->prepare("
            SELECT full_name, profile_image
            FROM users
            WHERE id = :id
            LIMIT 1
        ");
        $profile_stmt->execute([':id' => $uid]);
        $profile_data = $profile_stmt->fetch(PDO::FETCH_ASSOC);

        if ($profile_data) {
            $admin_full_name = $profile_data['full_name'] ?? $admin_full_name;
            $profile_img_path = $profile_data['profile_image'] ?? '';
            $_SESSION['user_name'] = $admin_full_name;
        }
    } catch (PDOException $e) {
        error_log("Add pet profile lookup failed: " . $e->getMessage());
    }
}

$first_name = trim(explode(' ', $admin_full_name)[0] ?? 'User', ',');
$admin_notifications = [];
$unread_count = 0;

try {
    $notif_stmt = $pdo->query("
        SELECT id, message, created_at
        FROM admin_notifications
        WHERE is_read = 0
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $admin_notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);
    $unread_count = count($admin_notifications);
} catch (PDOException $e) {
    $admin_notifications = [];
    $unread_count = 0;
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Add New Pet | Admin</title>

    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght\@400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>

        :root {

            --admin-purple: #8b2cf5;

            --navy-dark: #001f3f;

            --bg-light: #f4f7f6;

            --white: #ffffff;

            --text-main: #2d3436;

            --border: #e2e8f0;

        }



        body {

            font-family: 'Plus Jakarta Sans', sans-serif;

            background-color: var(--bg-light);

            color: var(--text-main);

            padding: 40px;

            margin: 0;

            overflow-x: hidden;

        }



        .container { max-width: 800px; width: 100%; margin: 0 auto; }

        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }

        .btn-back { background: var(--white); color: var(--navy-dark); padding: 10px 20px; border-radius: 8px; border: 1px solid var(--border); text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; min-height: 42px; }

        .btn-back:hover { background: #e2e8f0; }

        .card { background: var(--white); padding: 30px; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); border: 1px solid var(--border); }

        .card-title { font-size: 20px; color: var(--navy-dark); font-weight: 700; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; border-bottom: 1px solid var(--border); padding-bottom: 15px; line-height: 1.35; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }

        .form-group { margin-bottom: 15px; min-width: 0; }

        .form-group.full-width { grid-column: span 2; }

        label { display: block; font-size: 13px; font-weight: 600; color: #64748b; margin-bottom: 8px; text-transform: uppercase; }

        input[type="text"], select { width: 100%; min-height: 46px; padding: 12px 15px; border: 1px solid var(--border); border-radius: 8px; font-size: 14px; outline: none; box-sizing: border-box; background: #fff; }

        input:focus, select:focus { border-color: var(--admin-purple); box-shadow: 0 0 0 3px rgba(139, 44, 245, 0.1); }

        .btn-submit { background: var(--admin-purple); color: white; padding: 12px 25px; border: none; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; transition: 0.2s; width: 100%; min-height: 46px; margin-top: 10px; }

        .btn-submit:hover { background: #7322cc; }

        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; font-size: 14px; line-height: 1.55; overflow-wrap: anywhere; }

        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }

        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }



        @media (max-width: 700px) {

            body { padding: 20px 14px; }

            .header { margin-bottom: 16px; }

            .btn-back { width: 100%; justify-content: center; padding: 11px 14px; }

            .card { padding: 20px 16px; border-radius: 10px; }

            .card-title { font-size: 18px; margin-bottom: 18px; }

            .form-grid { grid-template-columns: 1fr; gap: 0; }

            .form-group.full-width { grid-column: auto; }

            .form-group { margin-bottom: 14px; }

            label { font-size: 12px; }

            input[type="text"], select { font-size: 16px; min-height: 48px; }

            .btn-submit { font-size: 14px; min-height: 48px; }

            .alert { font-size: 13px; padding: 13px; }

        }



        @media (max-width: 380px) {

            body { padding: 14px 10px; }

            .card { padding: 16px 13px; }

            .card-title { font-size: 16px; }

        }

    
        /* ===== SHARED ADMIN SHELL ===== */
        :root {
            --navy-dark: #001f3f;
            --brand-yellow: #ffcc00;
            --sidebar-width: 260px;
            --text-main: #2d3436;
        }

        body {
            margin: 0;
            padding: 0;
            display: flex;
            min-height: 100vh;
            overflow-x: hidden;
        }

        aside {
            width: var(--sidebar-width);
            background: var(--navy-dark);
            color: #fff;
            display: flex;
            flex-direction: column;
            position: fixed;
            height: 100vh;
            z-index: 2000;
            left: 0;
            top: 0;
        }

        .sidebar-header {
            padding: 30px 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }

        .sidebar-logo {
            width: 80px;
            height: auto;
            object-fit: contain;
            margin-bottom: 10px;
        }

        .sidebar-header h2 {
            font-size: 16px;
            color: var(--brand-yellow);
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 800;
        }

        .nav-links {
            flex-grow: 1;
            padding: 20px 15px;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            padding: 14px 20px;
            color: #94a3b8;
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 14px;
            font-weight: 500;
            border-radius: 10px;
            position: relative;
        }

        .nav-item i {
            width: 32px;
            font-size: 18px;
        }

        .nav-item:hover {
            color: #fff;
            background: rgba(255,255,255,0.05);
            transform: translateX(4px);
        }

        .nav-item.active {
            color: var(--brand-yellow);
            background: rgba(255,204,0,0.08);
            font-weight: 700;
        }

        .nav-item.active::before {
            content: '';
            position: absolute;
            left: -15px;
            top: 15%;
            height: 70%;
            width: 5px;
            background: var(--brand-yellow);
            border-radius: 0 5px 5px 0;
            box-shadow: 2px 0 8px rgba(255,204,0,0.5);
        }

        main {
            margin-left: var(--sidebar-width);
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            width: calc(100% - var(--sidebar-width));
        }

        .top-bar {
            background: #fff;
            height: 70px;
            padding: 0 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 1px 10px rgba(0,0,0,0.08);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .mobile-menu-toggle {
            display: none;
            border: 0;
            background: var(--navy-dark);
            color: var(--brand-yellow);
            width: 40px;
            height: 40px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 18px;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
        }

        .breadcrumb {
            font-weight: 700;
            color: var(--navy-dark);
            font-size: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .top-right-actions {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-left: auto;
            flex: 0 0 auto;
        }

        .notif-wrapper {
            position: relative;
            display: inline-flex;
            align-items: center;
            cursor: pointer;
        }

        .notif-badge {
            position: absolute;
            top: -6px;
            right: -9px;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            background: #e11d48;
            color: white;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .notif-dropdown {
            display: none;
            position: absolute;
            right: 0;
            top: 35px;
            width: 320px;
            background: white;
            border: 1px solid #e2e8f0;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
            border-radius: 8px;
            z-index: 3000;
            text-align: left;
            overflow: hidden;
        }

        .notif-dropdown.show { display: block; }

        .notif-header {
            padding: 12px 15px;
            border-bottom: 1px solid #e2e8f0;
            font-weight: 700;
            font-size: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: var(--navy-dark);
        }

        .notif-body { max-height: 300px; overflow-y: auto; }
        .notif-item {
            padding: 12px 15px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 13px;
            color: #334155;
            line-height: 1.4;
        }
        .notif-item:last-child { border-bottom: none; }
        .notif-empty {
            padding: 20px;
            text-align: center;
            color: #94a3b8;
            font-size: 13px;
        }
        .mark-read-btn {
            font-size: 11px;
            color: #3b82f6;
            text-decoration: none;
            font-weight: 600;
        }

        .profile-wrapper {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 12px;
            border-left: 1px solid #e2e8f0;
            padding-left: 20px;
            cursor: pointer;
            user-select: none;
        }

        .admin-tag {
            background: var(--navy-dark);
            color: var(--brand-yellow);
            padding: 6px 16px;
            border-radius: 50px;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 1px;
            text-transform: uppercase;
            border: 1px solid var(--brand-yellow);
        }

        .top-avatar,
        .top-avatar-fallback {
            width: 35px;
            height: 35px;
            border-radius: 50%;
        }

        .top-avatar {
            object-fit: cover;
            border: 2px solid var(--navy-dark);
        }

        .top-avatar-fallback {
            background: linear-gradient(135deg, var(--navy-dark), #003366);
            color: var(--brand-yellow);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 14px;
            border: 2px solid var(--brand-yellow);
        }

        .profile-dropdown {
            display: none;
            position: absolute;
            right: 0;
            top: 45px;
            width: 200px;
            background: #fff;
            border: 1px solid #e2e8f0;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
            border-radius: 8px;
            z-index: 3000;
            overflow: hidden;
            text-align: left;
        }

        .profile-dropdown.show { display: block; }

        .profile-item {
            padding: 12px 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            color: var(--text-main);
            text-decoration: none;
        }

        .profile-item:hover { background: #f1f5f9; color: var(--navy-dark); }
        .profile-item i { width: 16px; text-align: center; }
        .profile-item.logout-text {
            color: #e11d48;
            border-top: 1px solid #f1f5f9;
        }

        .sidebar-backdrop { display: none; }

        @media (max-width: 900px) {
            html, body {
                width: 100%;
                min-width: 100%;
                overflow-x: hidden;
            }

            aside {
                transform: translateX(-100%);
                transition: transform 0.25s ease;
                box-shadow: 12px 0 30px rgba(0,0,0,0.18);
            }

            aside.mobile-open { transform: translateX(0); }

            .sidebar-backdrop {
                position: fixed;
                inset: 0;
                background: rgba(0,31,63,0.48);
                z-index: 1900;
            }

            .sidebar-backdrop.show { display: block; }

            main {
                margin-left: 0;
                width: 100%;
                min-width: 0;
            }

            .top-bar {
                height: 60px;
                padding: 0 10px;
                gap: 8px;
            }

            .mobile-menu-toggle { display: inline-flex; }

            .breadcrumb {
                font-size: 12px;
                gap: 6px;
            }

            .top-right-actions {
                gap: 8px;
            }

            .profile-wrapper {
                gap: 7px;
                padding-left: 8px;
            }

            .profile-wrapper > span:last-of-type {
                max-width: 105px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
                font-size: 12px !important;
            }

            .admin-tag {
                padding: 5px 9px;
                font-size: 8px;
            }

            .top-avatar,
            .top-avatar-fallback {
                width: 30px;
                height: 30px;
            }

            .notif-dropdown {
                width: min(320px, calc(100vw - 28px));
                right: -6px;
            }

            .container {
                padding: 22px 16px;
                max-width: 100%;
            }

            .header {
                margin-bottom: 14px;
            }

            .btn-back {
                width: 100%;
                justify-content: center;
            }

            .card {
                padding: 20px 16px;
            }

            .card-title {
                font-size: 18px;
            }

            .form-grid {
                grid-template-columns: 1fr;
                gap: 0;
            }

            .form-group.full-width {
                grid-column: auto;
            }
        }

        @media (max-width: 560px) {
            .top-bar {
                height: 58px;
                padding: 0 8px;
            }

            .topbar-left { gap: 7px; }

            .mobile-menu-toggle {
                width: 36px;
                height: 36px;
                border-radius: 9px;
                font-size: 16px;
            }

            .breadcrumb { font-size: 11px; }

            .top-right-actions { gap: 5px; }

            .notif-wrapper > i { font-size: 19px !important; }

            .profile-wrapper {
                gap: 5px;
                padding-left: 6px;
            }

            .profile-wrapper > span:last-of-type {
                display: none !important;
            }

            .admin-tag {
                padding: 4px 7px;
                font-size: 7px;
            }

            .top-avatar,
            .top-avatar-fallback {
                width: 29px;
                height: 29px;
            }

            .container {
                padding: 16px 12px 22px;
            }

            .card {
                padding: 18px 13px;
            }

            .card-title {
                font-size: 16px;
            }

            .btn-back {
                min-height: 42px;
                font-size: 13px;
            }
        }

</style>

</head>

<body>



    <div class="container">

        <div class="header">

            <a href="managepet.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back to Pets</a>

        </div>



        <?php if($success_msg): ?>

            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_msg); ?></div>

        <?php endif; ?>



        <?php if($error_msg): ?>

            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_msg); ?></div>

        <?php endif; ?>



        <div class="card">

            <div class="card-title">

                <i class="fas fa-plus-circle" style="color: var(--admin-purple);"></i> Register New Pet

            </div>



            <form action="" method="POST">

                <div class="form-grid">

                    <div class="form-group full-width">

                        <label>Select Pet Owner</label>

                        <select name="owner_id" required>

                            <option value="" disabled selected>-- Select Owner (Customers Only) --</option>

                            <?php

                            if (!empty($users_result)) {

                                foreach ($users_result as $u_row) {

                                    echo '<option value="' . (int)$u_row['id'] . '">' . htmlspecialchars($u_row['full_name'] ?? '') . '</option>';

                                }

                            } else {

                                echo '<option value="" disabled>No customer records found</option>';

                            }

                            ?>

                        </select>

                    </div>



                    <div class="form-group">

                        <label>Pet Name</label>

                        <input type="text" name="name" placeholder="Enter pet's name" autocomplete="off" required>

                    </div>

                    <div class="form-group">

                        <label>Pet Type</label>

                        <input type="text" name="pet_type" placeholder="e.g. Dog, Cat" autocomplete="off" required>

                    </div>

                    <div class="form-group">

                        <label>Breed</label>

                        <input type="text" name="breed" placeholder="e.g. Bulldog" autocomplete="off">

                    </div>

                    <div class="form-group">

                        <label>Gender</label>

                        <select name="gender" required>

                            <option value="" disabled selected>-- Select Gender --</option>

                            <option value="Male">Male</option>

                            <option value="Female">Female</option>

                        </select>

                    </div>

                    <div class="form-group">

                        <label>Age (Years)</label>

                        <input type="text" name="age" placeholder="e.g. 6 yrs" inputmode="decimal" autocomplete="off">

                    </div>

                    <div class="form-group">

                        <label>Weight</label>

                        <input type="text" name="weight" placeholder="e.g. 20" inputmode="decimal" autocomplete="off">

                    </div>

                </div>

                <button type="submit" class="btn-submit"><i class="fas fa-save"></i> Add Pet Profile</button>

            </form>

        </div>

    </div>



        </div>
    </main>

    <script>
        function toggleMobileSidebar(event) {
            if (event) event.stopPropagation();
            const sidebar = document.querySelector('aside');
            const backdrop = document.getElementById('sidebarBackdrop');
            const button = document.querySelector('.mobile-menu-toggle');
            if (!sidebar) return;
            const open = sidebar.classList.toggle('mobile-open');
            if (backdrop) backdrop.classList.toggle('show', open);
            if (button) button.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        function closeMobileSidebar() {
            const sidebar = document.querySelector('aside');
            const backdrop = document.getElementById('sidebarBackdrop');
            const button = document.querySelector('.mobile-menu-toggle');
            if (sidebar) sidebar.classList.remove('mobile-open');
            if (backdrop) backdrop.classList.remove('show');
            if (button) button.setAttribute('aria-expanded', 'false');
        }

        function toggleNotif(event) {
            if (event) event.stopPropagation();
            const notifBox = document.getElementById('notifBox');
            const profileBox = document.getElementById('profileBox');
            if (notifBox) notifBox.classList.toggle('show');
            if (profileBox) profileBox.classList.remove('show');
        }

        function toggleProfile(event) {
            if (event) event.stopPropagation();
            const profileBox = document.getElementById('profileBox');
            const notifBox = document.getElementById('notifBox');
            if (profileBox) profileBox.classList.toggle('show');
            if (notifBox) notifBox.classList.remove('show');
        }

        document.addEventListener('click', function(event) {
            if (!event.target.closest('.notif-wrapper')) {
                const notifBox = document.getElementById('notifBox');
                if (notifBox) notifBox.classList.remove('show');
            }
            if (!event.target.closest('.profile-wrapper')) {
                const profileBox = document.getElementById('profileBox');
                if (profileBox) profileBox.classList.remove('show');
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeMobileSidebar();
                const notifBox = document.getElementById('notifBox');
                const profileBox = document.getElementById('profileBox');
                if (notifBox) notifBox.classList.remove('show');
                if (profileBox) profileBox.classList.remove('show');
            }
        });

        document.querySelectorAll('.nav-item').forEach(function(link) {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 900) closeMobileSidebar();
            });
        });

        function renderAdminNotifications(data) {
            const badge = document.getElementById('admin-notif-badge');
            const notifList = document.getElementById('admin-notif-list');
            const markReadBtn = document.getElementById('mark-read-link');
            if (!badge || !notifList) return;

            const unread = Number(data && data.unread ? data.unread : 0);
            badge.style.display = unread > 0 ? 'inline-flex' : 'none';
            badge.textContent = unread;

            if (markReadBtn) {
                markReadBtn.style.display = unread > 0 ? 'inline-block' : 'none';
            }

            notifList.innerHTML = (data && data.html)
                ? data.html
                : '<div class="notif-empty">No new notifications.</div>';
        }

        function fetchAdminNotifs() {
            fetch('get_admin_notifs.php', {
                method: 'GET',
                cache: 'no-store',
                credentials: 'same-origin'
            })
            .then(function(response) {
                if (!response.ok) throw new Error('Notification request failed: HTTP ' + response.status);
                return response.json();
            })
            .then(renderAdminNotifications)
            .catch(function(error) {
                console.error('Error fetching admin notifications:', error);
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            fetchAdminNotifs();
            setInterval(fetchAdminNotifs, 3000);
        });
    </script>
</body>

</html>
