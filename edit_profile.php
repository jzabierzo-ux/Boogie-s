<?php
session_start();
include 'db_connect.php'; 

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$success_msg = "";
$error_msg = "";

// --- PROCESS FORM SUBMISSION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = mysqli_real_escape_string($conn, $_POST['full_name']);
    $contact_number = mysqli_real_escape_string($conn, trim($_POST['contact_number']));
    $user_category = mysqli_real_escape_string($conn, $_POST['user_category']); // BAGO: Kukunin ang user category
    
    $profile_pic_query = "";

    // --- BAGO: STRICT CONTACT NUMBER VALIDATION ---
    if (!preg_match("/^[0-9]{11}$/", $contact_number)) {
        $error_msg = "Invalid contact number. Please enter exactly 11 digits (e.g., 09123456789).";
    }

    // Handle Profile Picture Upload using your 'profile_image' column
    if (empty($error_msg) && isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == 0) {
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
        $file_name = $_FILES['profile_image']['name'];
        $file_size = $_FILES['profile_image']['size'];
        $file_tmp = $_FILES['profile_image']['tmp_name'];
        
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        if (in_array($file_ext, $allowed_extensions)) {
            if ($file_size < 5000000) { // Limit to 5MB
                // Create unique filename to avoid overwriting
                $new_file_name = "user_" . $user_id . "_" . time() . "." . $file_ext;
                $upload_path = "uploads/" . $new_file_name;

                // Make sure the 'uploads' folder exists!
                if (move_uploaded_file($file_tmp, $upload_path)) {
                    $profile_pic_query = ", profile_image = '$upload_path'";
                    $_SESSION['profile_image'] = $upload_path; 
                } else {
                    $error_msg = "Error uploading your image. Please check folder permissions.";
                }
            } else {
                $error_msg = "File is too large. Maximum size is 5MB.";
            }
        } else {
            $error_msg = "Invalid file type. Only JPG, JPEG, PNG, and GIF are allowed.";
        }
    }

    // Update query - Only execute if there are no errors
    if (empty($error_msg)) {
        // BAGO: Idinagdag ang user_category sa i-u-update
        $update_query = "UPDATE users SET full_name = '$full_name', contact_number = '$contact_number', user_category = '$user_category' $profile_pic_query WHERE id = '$user_id'";
        
        if (mysqli_query($conn, $update_query)) {
            $success_msg = "Your profile has been updated successfully!";
            $_SESSION['user_name'] = $full_name;
            $_SESSION['full_name'] = $full_name; 
        } else {
            $error_msg = "Error updating profile: " . mysqli_error($conn);
        }
    }
}

// --- FETCH CURRENT USER DATA TO PRE-FILL THE FORM ---
$query = "SELECT * FROM users WHERE id = '$user_id'";
$result = mysqli_query($conn, $query);
$user_data = mysqli_fetch_assoc($result);

// Determine the name to show in the header
$header_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : (isset($_SESSION['full_name']) ? $_SESSION['full_name'] : 'User');

// Fetch unread notifications count for the header
$notif_query = "SELECT COUNT(*) as unread FROM notifications WHERE user_id = '$user_id' AND is_read = 0";
$notif_result = @mysqli_query($conn, $notif_query);
$unread_count = ($notif_result) ? mysqli_fetch_assoc($notif_result)['unread'] : 0;

// Set default avatar if user has no profile image in DB
$current_profile_pic = !empty($user_data['profile_image']) ? $user_data['profile_image'] : 'default-avatar.png';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Profile | Boogie's Pet Care Services</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{
    --brand-blue:#001f3f;
    --brand-blue-2:#0b3b66;
    --brand-yellow:#ffcc00;
    --brand-yellow-soft:#fff7d6;
    --page-bg:#f5f8fb;
    --white:#fff;
    --text:#17324d;
    --muted:#6b7c8f;
    --line:#e3eaf1;
    --success:#168553;
    --danger:#c73b47;
    --shadow:0 10px 30px rgba(0,31,63,.06);
    --shadow-lg:0 18px 42px rgba(0,31,63,.10);
}

*{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{
    min-height:100vh;
    background:var(--page-bg);
    color:var(--text);
    font-family:'Poppins',sans-serif;
    line-height:1.6;
}
a{color:inherit}
button,input,select{font:inherit}

/* HEADER */
.promo-bar{
    background:var(--brand-blue);
    color:#fff;
    text-align:center;
    padding:7px 16px;
    font-size:12px;
    font-weight:700;
    letter-spacing:.1px;
    border-top:3px solid var(--brand-yellow);
}
.promo-bar i{color:var(--brand-yellow);margin-right:7px}

header{
    position:sticky;
    top:0;
    z-index:1000;
    background:rgba(255,255,255,.98);
    border-bottom:1px solid var(--line);
    box-shadow:0 4px 18px rgba(0,0,0,.04);
}
.nav-top{
    width:min(1320px,92%);
    min-height:78px;
    margin:0 auto;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:24px;
    padding:13px 0;
}
.logo{
    display:flex;
    align-items:center;
    gap:11px;
    text-decoration:none;
    flex:0 0 auto;
}
.nav-logo-img{
    width:50px;
    height:50px;
    object-fit:contain;
    border-radius:10px;
}
.logo-text{display:flex;flex-direction:column;line-height:1.05}
.logo-text b{font-size:20px;color:var(--brand-blue);font-weight:800}
.logo-text span{
    margin-top:3px;
    color:#8c9aae;
    font-size:9px;
    font-weight:700;
    letter-spacing:1.2px;
}

.user-controls{
    display:flex;
    align-items:center;
    gap:12px;
}
.notification-btn{
    position:relative;
    width:42px;
    height:42px;
    border:1px solid var(--line);
    border-radius:12px;
    background:#fff;
    color:var(--brand-blue);
    display:flex;
    align-items:center;
    justify-content:center;
    text-decoration:none;
}
.notification-btn:hover{background:#f8fafc}
.notification-badge{
    position:absolute;
    top:-5px;
    right:-5px;
    min-width:18px;
    height:18px;
    padding:0 5px;
    border-radius:999px;
    background:#dc3b45;
    color:#fff;
    border:2px solid #fff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:9px;
    font-weight:800;
}
.profile-link{
    display:flex;
    align-items:center;
    gap:9px;
    min-height:42px;
    padding:4px 10px 4px 5px;
    border:1px solid var(--line);
    border-radius:12px;
    background:#fff;
    text-decoration:none;
    color:var(--text);
    font-size:13px;
    font-weight:700;
}
.profile-link:hover{background:#f8fafc}
.profile-link img{
    width:34px;
    height:34px;
    border-radius:50%;
    object-fit:cover;
    border:2px solid var(--brand-blue);
}
.logout-link{
    text-decoration:none;
    color:#dc3b45;
    font-weight:700;
    font-size:13px;
    padding:9px 5px;
}
.logout-link:hover{color:#b92e39}

/* PAGE */
main{
    width:min(1080px,92%);
    margin:0 auto;
    padding:42px 0 75px;
}
.page-top{margin-bottom:24px}
.back-link{
    display:inline-flex;
    align-items:center;
    gap:7px;
    text-decoration:none;
    color:#748396;
    font-size:11px;
    font-weight:700;
    margin-bottom:14px;
}
.back-link:hover{color:var(--brand-blue)}

.page-heading-row{
    display:flex;
    align-items:flex-end;
    justify-content:space-between;
    gap:24px;
}
.page-heading .kicker{
    display:inline-flex;
    align-items:center;
    gap:7px;
    padding:7px 12px;
    border-radius:999px;
    background:var(--brand-yellow-soft);
    border:1px solid #ffe594;
    color:#8c6800;
    font-size:10px;
    text-transform:uppercase;
    letter-spacing:.7px;
    font-weight:800;
    margin-bottom:10px;
}
.page-heading h1{
    color:var(--brand-blue);
    font-size:34px;
    line-height:1.18;
    font-weight:800;
    letter-spacing:-.6px;
}
.page-heading p{
    color:var(--muted);
    font-size:13px;
    margin-top:6px;
}
.security-chip{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:10px 13px;
    background:#fff;
    border:1px solid var(--line);
    border-radius:12px;
    color:#6e8091;
    font-size:10px;
    font-weight:700;
    white-space:nowrap;
}
.security-chip i{color:var(--success)}

.profile-layout{
    display:grid;
    grid-template-columns:310px minmax(0,1fr);
    gap:22px;
    align-items:start;
}

/* PROFILE CARD */
.profile-card{
    background:#fff;
    border:1px solid var(--line);
    border-radius:20px;
    overflow:hidden;
    box-shadow:var(--shadow);
    position:sticky;
    top:108px;
}
.profile-cover{
    height:105px;
    position:relative;
    overflow:hidden;
    background:
        radial-gradient(circle at 90% 0%,rgba(255,204,0,.22),transparent 31%),
        linear-gradient(135deg,#eef6ff 0%,#fff 60%,#fff9df 100%);
    border-bottom:1px solid var(--line);
}
.profile-cover:before,
.profile-cover:after{
    content:"";
    position:absolute;
    border-radius:50%;
}
.profile-cover:before{
    width:165px;height:165px;right:-55px;top:-102px;
    background:rgba(255,204,0,.10);
}
.profile-cover:after{
    width:120px;height:120px;left:-55px;bottom:-78px;
    background:rgba(0,31,63,.045);
}
.profile-card-body{
    padding:0 22px 23px;
    text-align:center;
}
.avatar-wrap{
    width:118px;height:118px;
    margin:-55px auto 15px;
    position:relative;
    z-index:2;
}
.profile-photo{
    width:118px;height:118px;
    display:block;
    object-fit:cover;
    border-radius:50%;
    border:5px solid #fff;
    background:#fff;
    box-shadow:0 10px 28px rgba(0,31,63,.14);
}
.camera-badge{
    position:absolute;
    right:1px;
    bottom:1px;
    width:33px;height:33px;
    border:3px solid #fff;
    border-radius:50%;
    background:var(--brand-blue);
    color:var(--brand-yellow);
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:12px;
}
.profile-card-body h2{
    color:var(--brand-blue);
    font-size:18px;
    line-height:1.3;
    font-weight:800;
}
.profile-role{
    margin-top:3px;
    color:#8b99a9;
    font-size:10px;
    font-weight:700;
}
.photo-label{
    width:100%;
    min-height:41px;
    margin-top:15px;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    border:1px solid #dce5ed;
    border-radius:10px;
    background:#fff;
    color:var(--brand-blue);
    font-size:10px;
    font-weight:800;
    cursor:pointer;
}
.photo-label:hover{background:#f8fafc}
input[type=file]{display:none}
.photo-help{
    margin-top:8px;
    color:#9aa6b2;
    font-size:9px;
    line-height:1.55;
}
.profile-divider{
    height:1px;
    background:#edf1f5;
    margin:18px 0;
}
.summary-item{
    display:flex;
    align-items:flex-start;
    gap:8px;
    padding:9px 10px;
    border-radius:10px;
    background:#f8fafc;
    border:1px solid #edf1f5;
    text-align:left;
    margin-top:7px;
}
.summary-item:first-child{margin-top:0}
.summary-item i{
    color:#1a9272;
    font-size:10px;
    margin-top:3px;
}
.summary-item span{
    color:#607487;
    font-size:9px;
    line-height:1.6;
}

/* FORM CARD */
.form-card{
    background:#fff;
    border:1px solid var(--line);
    border-radius:20px;
    box-shadow:var(--shadow);
    padding:27px;
}
.form-card-head{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:18px;
    margin-bottom:21px;
}
.form-card-head .kicker{
    color:#8b99a9;
    font-size:9px;
    text-transform:uppercase;
    letter-spacing:1.1px;
    font-weight:800;
    margin-bottom:5px;
}
.form-card-head h2{
    color:var(--brand-blue);
    font-size:21px;
    line-height:1.2;
    font-weight:800;
}
.form-card-head p{
    color:var(--muted);
    font-size:10px;
    margin-top:5px;
}
.status-chip{
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:6px 9px;
    border-radius:999px;
    background:#e7f8ef;
    color:#1e7e49;
    font-size:8px;
    text-transform:uppercase;
    letter-spacing:.4px;
    font-weight:800;
    white-space:nowrap;
}

.alert{
    display:flex;
    align-items:flex-start;
    gap:9px;
    padding:12px 13px;
    border-radius:11px;
    margin-bottom:18px;
    font-size:10px;
    line-height:1.6;
    font-weight:600;
}
.alert.success{
    background:#e7f8ef;
    border:1px solid #ccebd9;
    color:#176c47;
}
.alert.error{
    background:#fff0f1;
    border:1px solid #f1c4c9;
    color:#a92734;
}
.form-divider{
    height:1px;
    background:#edf1f5;
    margin-bottom:22px;
}
.form-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:0 17px;
}
.form-group{margin-bottom:18px}
.form-group label{
    display:block;
    color:var(--brand-blue);
    font-size:11px;
    font-weight:800;
    margin-bottom:7px;
}
.field-note{
    color:#9aa6b2;
    font-size:9px;
    font-weight:500;
    margin-left:5px;
}
.form-control{
    width:100%;
    min-height:43px;
    padding:10px 13px;
    border:1px solid #dce5ed;
    border-radius:10px;
    background:#fbfcfe;
    color:var(--text);
    font-size:11px;
    outline:none;
    transition:.2s;
}
.form-control:focus{
    border-color:#9bb6cc;
    background:#fff;
    box-shadow:0 0 0 3px rgba(0,31,63,.05);
}
.form-control[readonly]{
    background:#f3f6f9;
    color:#798694;
    cursor:not-allowed;
}
.field-help{
    color:#96a3b0;
    font-size:9px;
    margin-top:5px;
}
.info-box{
    display:flex;
    align-items:flex-start;
    gap:9px;
    padding:12px 13px;
    border-radius:11px;
    background:#eef6ff;
    border:1px solid #dcebf7;
    color:#4c687f;
    font-size:9px;
    line-height:1.65;
}
.info-box i{color:var(--brand-blue);margin-top:2px}
.form-actions{
    display:flex;
    justify-content:flex-end;
    gap:10px;
    margin-top:20px;
}
.btn-save,.btn-cancel{
    min-height:42px;
    padding:0 17px;
    border-radius:10px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    font-size:11px;
    font-weight:800;
    text-decoration:none;
    cursor:pointer;
}
.btn-save{
    border:0;
    background:var(--brand-blue);
    color:var(--brand-yellow);
}
.btn-save:hover{background:var(--brand-blue-2)}
.btn-cancel{
    border:1px solid #cbd5e1;
    background:#fff;
    color:var(--text);
}
.btn-cancel:hover{background:#f8fafc}

/* FOOTER */
footer{
    width:100%;
    background:var(--brand-blue);
    color:#fff;
    border-top:4px solid var(--brand-yellow);
    padding:62px 28px 30px;
}
.footer-main{
    width:min(1180px,100%);
    margin:0 auto;
    display:grid;
    grid-template-columns:2fr 1fr 1fr 1.5fr;
    gap:42px;
    padding-bottom:40px;
    border-bottom:1px solid rgba(255,255,255,.12);
}
.footer-main h4{
    color:var(--brand-yellow);
    margin-bottom:15px;
    font-size:12px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.5px;
}
.footer-main p,
.footer-main a{
    color:#cbd5e1;
    text-decoration:none;
    display:block;
    font-size:12px;
    line-height:1.7;
    margin-bottom:8px;
}
.footer-main a:hover{color:#fff}
.socials{
    display:flex;
    gap:10px;
    margin-top:16px;
}
.socials a{
    width:36px;height:36px;
    border-radius:50%;
    background:rgba(255,255,255,.09);
    display:flex;
    align-items:center;
    justify-content:center;
    color:#fff;
}
.socials a:hover{
    background:var(--brand-yellow);
    color:var(--brand-blue);
}
.footer-bottom{
    width:min(1180px,100%);
    margin:0 auto;
    padding-top:23px;
    text-align:center;
    color:#91a1b1;
    font-size:11px;
}

/* RESPONSIVE */
@media (max-width:900px){
    .profile-layout{grid-template-columns:1fr}
    .profile-card{position:static}
    .footer-main{grid-template-columns:1fr 1fr}
}
@media (max-width:680px){
    .nav-top{
        width:92%;
        flex-wrap:wrap;
        min-height:auto;
    }
    .profile-link span{display:none}
    main{width:92%;padding-top:30px}
    .page-heading-row{display:block}
    .security-chip{
        margin-top:14px;
        white-space:normal;
    }
    .form-card{padding:20px}
    .form-card-head{display:block}
    .status-chip{margin-top:13px}
    .form-grid{grid-template-columns:1fr}
    .form-actions{display:grid;grid-template-columns:1fr 1fr}
    .btn-save,.btn-cancel{width:100%}
    footer{padding:50px 20px 25px}
    .footer-main{grid-template-columns:1fr;gap:25px}
}
@media (max-width:440px){
    .form-actions{grid-template-columns:1fr}
}
</style>
</head>
<body>

    <div class="promo-bar">
        <i class="fa-solid fa-phone"></i>
        Need help? Call us at (046) 887 4714
    </div>

    <header>
        <div class="nav-top">
            <a href="dashboard.php" class="logo">
                <img src="bg.png" alt="Boogie's Pet Care logo" class="nav-logo-img">
                <div class="logo-text">
                    <b>Boogie's</b>
                    <span>PET CARE SERVICES</span>
                </div>
            </a>

            <div class="user-controls">
                <a href="notifications.php" class="notification-btn" aria-label="Notifications">
                    <i class="fa-solid fa-bell"></i>
                    <?php if ($unread_count > 0): ?>
                        <span class="notification-badge">
                            <?php echo $unread_count; ?>
                        </span>
                    <?php endif; ?>
                </a>

                <a href="edit_profile.php" class="profile-link" title="Edit Profile">
                    <img
                        src="<?php echo htmlspecialchars($current_profile_pic); ?>"
                        alt="Profile"
                        onerror="this.src='default-avatar.png';"
                    >
                    <span>Hi, <?php echo htmlspecialchars($header_name); ?></span>
                </a>

                <a href="logout.php" class="logout-link">Logout</a>
            </div>
        </div>
    </header>

    <main>
        <div class="page-top">
            <a href="dashboard.php" class="back-link">
                <i class="fa-solid fa-arrow-left"></i>
                Back to Dashboard
            </a>

            <div class="page-heading-row">
                <div class="page-heading">
                    <div class="kicker">
                        <i class="fa-solid fa-user-pen"></i>
                        Account settings
                    </div>

                    <h1>Edit Profile</h1>
                    <p>Update your personal information and profile photo.</p>
                </div>

                <div class="security-chip">
                    <i class="fa-solid fa-shield-halved"></i>
                    Your account details are protected
                </div>
            </div>
        </div>

        <form action="edit_profile.php" method="POST" enctype="multipart/form-data">
            <div class="profile-layout">

                <aside class="profile-card">
                    <div class="profile-cover"></div>

                    <div class="profile-card-body">
                        <div class="avatar-wrap">
                            <img
                                src="<?php echo htmlspecialchars($current_profile_pic); ?>"
                                alt="Profile Picture"
                                class="profile-photo"
                                id="pic-preview"
                                onerror="this.src='default-avatar.png';"
                            >

                            <div class="camera-badge">
                                <i class="fa-solid fa-camera"></i>
                            </div>
                        </div>

                        <h2><?php echo htmlspecialchars($user_data['full_name']); ?></h2>

                        <div class="profile-role">
                            <?php echo htmlspecialchars($user_data['user_category'] ?? 'Pet Owner'); ?>
                        </div>

                        <label for="profile_image" class="photo-label">
                            <i class="fa-solid fa-camera"></i>
                            Change Profile Photo
                        </label>

                        <input
                            type="file"
                            id="profile_image"
                            name="profile_image"
                            accept="image/png, image/jpeg, image/jpg, image/gif"
                            onchange="previewImage(event)"
                        >

                        <div class="photo-help">
                            JPG, JPEG, PNG, or GIF · Maximum file size: 5MB
                        </div>

                        <div class="profile-divider"></div>

                        <div class="summary-item">
                            <i class="fa-solid fa-envelope"></i>
                            <span>Your email is linked to your account and cannot be changed here.</span>
                        </div>

                        <div class="summary-item">
                            <i class="fa-solid fa-phone"></i>
                            <span>Keep your contact number updated for booking reminders.</span>
                        </div>
                    </div>
                </aside>

                <section class="form-card">
                    <div class="form-card-head">
                        <div>
                            <div class="kicker">Personal information</div>
                            <h2>Account Details</h2>
                            <p>Make sure the information below is correct.</p>
                        </div>

                        <div class="status-chip">
                            <i class="fa-solid fa-lock"></i>
                            Secure profile
                        </div>
                    </div>

                    <?php if (!empty($success_msg)): ?>
                        <div class="alert success">
                            <i class="fa-solid fa-circle-check"></i>
                            <div><?php echo htmlspecialchars($success_msg); ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($error_msg)): ?>
                        <div class="alert error">
                            <i class="fa-solid fa-circle-exclamation"></i>
                            <div><?php echo htmlspecialchars($error_msg); ?></div>
                        </div>
                    <?php endif; ?>

                    <div class="form-divider"></div>

                    <div class="form-grid">

                        <div class="form-group">
                            <label for="full_name">Full Name</label>
                            <input
                                type="text"
                                id="full_name"
                                name="full_name"
                                class="form-control"
                                value="<?php echo htmlspecialchars($user_data['full_name']); ?>"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="email">
                                Email Address
                                <span class="field-note">(Cannot be changed)</span>
                            </label>

                            <input
                                type="email"
                                id="email"
                                class="form-control"
                                value="<?php echo htmlspecialchars($user_data['email']); ?>"
                                readonly
                                title="Your email address cannot be changed"
                            >
                        </div>

                        <div class="form-group">
                            <label for="user_category">I am a</label>

                            <select id="user_category" name="user_category" class="form-control" required>
                                <option value="Pet Owner" <?php echo (isset($user_data['user_category']) && $user_data['user_category'] == 'Pet Owner') ? 'selected' : ''; ?>>
                                    Pet Owner
                                </option>
                                <option value="Pet Breeder" <?php echo (isset($user_data['user_category']) && $user_data['user_category'] == 'Pet Breeder') ? 'selected' : ''; ?>>
                                    Pet Breeder
                                </option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="contact_number">Contact Number</label>

                            <input
                                type="tel"
                                id="contact_number"
                                name="contact_number"
                                class="form-control"
                                value="<?php echo htmlspecialchars(isset($user_data['contact_number']) && $user_data['contact_number'] !== 'Not Provided' ? $user_data['contact_number'] : ''); ?>"
                                placeholder="e.g. 09123456789"
                                required
                                maxlength="11"
                                pattern="[0-9]{11}"
                                inputmode="numeric"
                                oninput="this.value = this.value.replace(/[^0-9]/g, '')"
                            >

                            <div class="field-help">
                                Enter exactly 11 digits.
                            </div>
                        </div>

                    </div>

                    <div class="info-box">
                        <i class="fa-solid fa-circle-info"></i>
                        <span>
                            Your email address is your account login and cannot be changed from this page.
                            Contact Boogie's staff if you need assistance with your account.
                        </span>
                    </div>

                    <div class="form-actions">
                        <a href="dashboard.php" class="btn-cancel">
                            Cancel
                        </a>

                        <button type="submit" class="btn-save">
                            <i class="fa-solid fa-floppy-disk"></i>
                            Save Changes
                        </button>
                    </div>
                </section>

            </div>
        </form>
    </main>

    <footer>
        <div class="footer-main">
            <div>
                <h4><i class="fa-solid fa-paw"></i> Boogie's Pet Care</h4>
                <p>Your trusted partner for all your pet care needs in Dasmariñas, Cavite.</p>

                <div class="socials">
                    <a href="https://www.facebook.com/boogiespetsupplies" aria-label="Facebook">
                        <i class="fa-brands fa-facebook-f"></i>
                    </a>
                    <a href="mailto:boogiespetcareservices@gmail.com" aria-label="Email">
                        <i class="fa-solid fa-envelope"></i>
                    </a>
                </div>
            </div>

            <div>
                <h4>Quick Links</h4>
                <a href="home.php">Home</a>
                <a href="petservices.php">Services & Prices</a>
                <a href="contactus.php">Contact & Reviews</a>
                <a href="faqs.html">FAQs</a>
            </div>

            <div>
                <h4>Services</h4>
                <a href="grooming.php">Grooming</a>
                <a href="pethotel.php">Pet Hotel</a>
                <a href="vetclinic.php">Vet Clinic</a>
            </div>

            <div>
                <h4>Contact Us</h4>
                <p><i class="fa-solid fa-phone"></i> (046) 887 4714</p>
                <p><i class="fa-solid fa-envelope"></i> boogiespetcareservices@gmail.com</p>
                <p><i class="fa-solid fa-location-dot"></i> 110 Don Placido Campos Ave San Agustin 3, Dasmariñas, Philippines, 4114</p>
            </div>
        </div>

        <div class="footer-bottom">
            © 2026 Boogie's Pet Care & Services - Dasmariñas Branch. All rights reserved.
        </div>
    </footer>
    <script>
        function previewImage(event) {
            const reader = new FileReader();
            reader.onload = function(){
                const output = document.getElementById('pic-preview');
                output.src = reader.result;
            };
            if(event.target.files[0]) {
                reader.readAsDataURL(event.target.files[0]);
            }
        }
    </script>
</body>
</html>
