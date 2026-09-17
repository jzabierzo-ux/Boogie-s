<?php
session_start();
include 'db_connect.php';

// 1. SECURITY: Check if logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// 2. DEFINE USER DATA
$user_id = $_SESSION['user_id'];
$full_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : (isset($_SESSION['full_name']) ? $_SESSION['full_name'] : 'User');

// --- FETCH UNREAD NOTIFICATIONS COUNT FOR HEADER ---
$notif_header_query = "SELECT COUNT(*) as unread FROM notifications WHERE user_id = '$user_id' AND is_read = 0";
$notif_header_result = @mysqli_query($conn, $notif_header_query);
$unread_count = ($notif_header_result) ? mysqli_fetch_assoc($notif_header_result)['unread'] : 0;

// --- FETCH LATEST 5 NOTIFICATIONS FOR DROPDOWN ---
$notifications = [];
$stmt_notif_list = $conn->prepare("SELECT id, message, created_at, is_read FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
if ($stmt_notif_list) {
    $stmt_notif_list->bind_param("i", $user_id);
    $stmt_notif_list->execute();
    $res_list = $stmt_notif_list->get_result();
    if ($res_list) {
        $notifications = $res_list->fetch_all(MYSQLI_ASSOC);
    }
    $stmt_notif_list->close();
}

// --- FETCH USER PROFILE IMAGE ---
$user_query = mysqli_query($conn, "SELECT profile_image FROM users WHERE id = '$user_id'");
$user_data = mysqli_fetch_assoc($user_query);
$profile_image = isset($user_data['profile_image']) ? $user_data['profile_image'] : null;

// 3. KUNIN ANG PET DATA PARA SA FORM (DISPLAY)
if (isset($_GET['id'])) {
    $pet_id = mysqli_real_escape_string($conn, $_GET['id']);
    $fetch_pet = mysqli_query($conn, "SELECT * FROM pets WHERE id = '$pet_id' AND owner_id = '$user_id'");
    $pet_data = mysqli_fetch_assoc($fetch_pet);

    // Kung walang nahanap na pet o hindi sa user ang pet, redirect pabalik
    if (!$pet_data) {
        header("Location: dashboard.php");
        exit;
    }
} else {
    // Kung walang ID sa URL, hindi pwedeng mag-edit
    header("Location: dashboard.php");
    exit;
}

// 4. UPDATE LOGIC (DIRECT UPDATE)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pet_id_post = mysqli_real_escape_string($conn, $_POST['pet_id']);
    
    // Tugma na ang mga variables sa name="" ng HTML form mo sa ibaba
    $p_name   = mysqli_real_escape_string($conn, $_POST['p_name']);
    $p_type   = mysqli_real_escape_string($conn, $_POST['p_type']); 
    $p_breed  = mysqli_real_escape_string($conn, $_POST['p_breed']);
    $p_age    = mysqli_real_escape_string($conn, $_POST['p_age']);
    $p_weight = mysqli_real_escape_string($conn, $_POST['p_weight']);
    $p_gender = mysqli_real_escape_string($conn, $_POST['p_gender']);

    // I-update lahat ng fields diretso sa database, kasama ang pet_type
    $update_query = "UPDATE pets SET 
                        name = '$p_name', 
                        pet_type = '$p_type', 
                        breed = '$p_breed', 
                        age = '$p_age', 
                        weight = '$p_weight',
                        gender = '$p_gender'
                     WHERE id = '$pet_id_post' AND owner_id = '$user_id'";

    if (mysqli_query($conn, $update_query)) {
        // Magpakita ng success alert at ibalik sa petprofile.php
        echo "<script>
                alert('Pet profile updated successfully!');
                window.location.href='petprofile.php';
              </script>";
        exit();
    } else {
        $error_msg = "Error updating profile: " . mysqli_error($conn);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Pet Profile | Boogie's Pet Care & Services</title>
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
    --soft:#f8fafc;
    --success:#168553;
    --danger:#c73b47;
    --danger-soft:#fff0f1;
    --shadow:0 10px 30px rgba(0,31,63,.06);
    --shadow-lg:0 18px 42px rgba(0,31,63,.10);
}

*{
    box-sizing:border-box;
    margin:0;
    padding:0;
    font-family:'Poppins',sans-serif;
}

html{scroll-behavior:smooth}

body{
    min-height:100vh;
    background:var(--page-bg);
    color:var(--text);
    line-height:1.6;
    overflow-y:scroll;
}

a{color:inherit}
button,input,select{font:inherit}

/* ===== HEADER ===== */
.promo-bar{
    background:var(--brand-blue);
    color:#fff;
    text-align:center;
    padding:7px 16px;
    font-size:12px;
    font-weight:700;
    border-top:3px solid var(--brand-yellow);
}

.promo-bar i{
    color:var(--brand-yellow);
    margin-right:7px;
}

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
    padding:13px 0;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:24px;
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

.logo-text{
    display:flex;
    flex-direction:column;
    line-height:1.05;
}

.logo-text b{
    color:var(--brand-blue);
    font-size:20px;
    font-weight:800;
}

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

.notification-wrapper,
.profile-wrapper{
    position:relative;
}

.notification-bell{
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
    cursor:pointer;
}

.notification-bell:hover{background:#f8fafc}

.notification-badge{
    position:absolute;
    top:-5px;
    right:-5px;
    min-width:18px;
    height:18px;
    padding:0 5px;
    border:2px solid #fff;
    border-radius:999px;
    background:#dc3b45;
    color:#fff;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:9px;
    font-weight:800;
}

.profile-trigger{
    min-height:42px;
    padding:4px 9px 4px 5px;
    border:1px solid var(--line);
    border-radius:12px;
    background:#fff;
    color:var(--text);
    display:flex;
    align-items:center;
    gap:9px;
    cursor:pointer;
    font-size:13px;
    font-weight:700;
}

.profile-trigger:hover{background:#f8fafc}

.profile-avatar{
    width:34px;
    height:34px;
    object-fit:cover;
    border-radius:50%;
    border:2px solid var(--brand-blue);
}

.dropdown-menu{
    position:absolute;
    top:calc(100% + 10px);
    right:0;
    width:270px;
    background:#fff;
    border:1px solid var(--line);
    border-radius:14px;
    box-shadow:var(--shadow-lg);
    display:none;
    flex-direction:column;
    overflow:hidden;
    z-index:1100;
}

.dropdown-menu.active{display:flex}

.dropdown-header{
    padding:14px 16px;
    background:#f8fafc;
    border-bottom:1px solid var(--line);
    color:var(--muted);
    font-size:11px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.7px;
}

.dropdown-item{
    display:block;
    padding:12px 16px;
    border-bottom:1px solid #eef2f5;
    text-decoration:none;
    color:var(--text);
    font-size:12px;
}

.dropdown-item:hover{
    background:#f8fafc;
    color:var(--brand-blue);
}

.dropdown-item.unread{
    background:#eef6ff;
    font-weight:700;
}

.dropdown-item i{
    width:18px;
    margin-right:7px;
    text-align:center;
}

.dropdown-item:last-child{border-bottom:0}
.view-all-link{text-align:center;font-weight:800;color:var(--brand-blue)}

/* ===== PAGE ===== */
main{
    width:min(1000px,92%);
    margin:0 auto;
    padding:42px 0 78px;
}

.back-nav{
    margin-bottom:14px;
}

.back-nav a{
    display:inline-flex;
    align-items:center;
    gap:7px;
    text-decoration:none;
    color:#748396;
    font-size:11px;
    font-weight:700;
}

.back-nav a:hover{color:var(--brand-blue)}

.page-intro{
    display:flex;
    align-items:flex-end;
    justify-content:space-between;
    gap:20px;
    margin-bottom:24px;
}

.page-kicker{
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

.pet-chip{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:10px 13px;
    border:1px solid var(--line);
    background:#fff;
    border-radius:12px;
    color:#66798b;
    font-size:10px;
    font-weight:700;
    white-space:nowrap;
}

.pet-chip i{color:var(--brand-blue)}

.alert-error{
    display:flex;
    align-items:flex-start;
    gap:9px;
    padding:12px 13px;
    border-radius:11px;
    margin-bottom:18px;
    background:var(--danger-soft);
    border:1px solid #f1c4c9;
    color:#a92734;
    font-size:10px;
    line-height:1.6;
    font-weight:600;
}

.form-card{
    background:#fff;
    border:1px solid var(--line);
    border-radius:20px;
    box-shadow:var(--shadow);
    padding:27px;
}

.form-card-top{
    display:flex;
    align-items:flex-start;
    gap:15px;
    margin-bottom:21px;
}

.pet-icon{
    width:52px;
    height:52px;
    flex:0 0 52px;
    border-radius:15px;
    background:var(--brand-yellow-soft);
    color:var(--brand-blue);
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:21px;
}

.form-card-heading .kicker{
    color:#8b99a9;
    font-size:9px;
    text-transform:uppercase;
    letter-spacing:1.1px;
    font-weight:800;
    margin-bottom:4px;
}

.form-card-heading h2{
    color:var(--brand-blue);
    font-size:21px;
    line-height:1.2;
    font-weight:800;
}

.form-card-heading p{
    color:var(--muted);
    font-size:10px;
    margin-top:4px;
}

.form-divider{
    height:1px;
    background:#edf1f5;
    margin-bottom:23px;
}

.form-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:0 17px;
}

.form-group{
    margin-bottom:19px;
}

.form-group.full{
    grid-column:1 / -1;
}

.form-group label{
    display:block;
    color:var(--brand-blue);
    font-size:11px;
    font-weight:800;
    margin-bottom:7px;
}

.required{
    color:#d13b47;
    margin-left:2px;
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

.field-help{
    color:#96a3b0;
    font-size:9px;
    margin-top:5px;
}

.form-note{
    display:flex;
    align-items:flex-start;
    gap:9px;
    padding:12px 13px;
    background:#eef6ff;
    border:1px solid #dcebf7;
    border-radius:11px;
    color:#4c687f;
    font-size:9px;
    line-height:1.6;
}

.form-note i{
    color:var(--brand-blue);
    margin-top:2px;
}

.form-actions{
    display:flex;
    justify-content:flex-end;
    gap:10px;
    margin-top:20px;
}

.btn{
    min-height:42px;
    padding:0 17px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    border-radius:10px;
    font-size:11px;
    font-weight:800;
    text-decoration:none;
    cursor:pointer;
}

.btn-cancel{
    background:#fff;
    color:var(--text);
    border:1px solid #cbd5e1;
}

.btn-cancel:hover{background:#f8fafc}

.btn-save{
    background:var(--brand-blue);
    color:var(--brand-yellow);
    border:0;
}

.btn-save:hover{background:var(--brand-blue-2)}

/* ===== FOOTER ===== */
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
    display:block;
    color:#cbd5e1;
    text-decoration:none;
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
    width:36px;
    height:36px;
    margin:0;
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

/* ===== RESPONSIVE ===== */
@media (max-width:900px){
    .page-intro{display:block}
    .pet-chip{margin-top:14px}
    .footer-main{grid-template-columns:1fr 1fr}
}

@media (max-width:680px){
    .nav-top{
        width:92%;
        flex-wrap:wrap;
    }

    .profile-trigger span{display:none}

    main{
        width:92%;
        padding-top:30px;
    }

    .form-card{padding:20px}

    .form-grid{
        grid-template-columns:1fr;
    }

    .form-group.full{
        grid-column:auto;
    }

    .form-actions{
        display:grid;
        grid-template-columns:1fr 1fr;
    }

    .btn{
        width:100%;
    }

    .footer-main{
        grid-template-columns:1fr;
        gap:25px;
    }
}

@media (max-width:440px){
    .form-actions{
        grid-template-columns:1fr;
    }
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
                <div class="notification-wrapper">
                    <div class="notification-bell" onclick="toggleDropdown('notifDropdown')" aria-label="Notifications">
                        <i class="fa-solid fa-bell"></i>

                        <?php if($unread_count > 0): ?>
                            <span id="notif-badge" class="notification-badge">
                                <?php echo $unread_count; ?>
                            </span>
                        <?php else: ?>
                            <span id="notif-badge" class="notification-badge" style="display:none;">0</span>
                        <?php endif; ?>
                    </div>

                    <div class="dropdown-menu" id="notifDropdown">
                        <div class="dropdown-header">Notifications</div>

                        <?php if(count($notifications) > 0): ?>
                            <?php foreach($notifications as $notif): ?>
                                <a
                                    href="notifications.php"
                                    class="dropdown-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>"
                                >
                                    <?php echo htmlspecialchars($notif['message']); ?>
                                    <br>
                                    <small style="color:#888;font-size:10px;">
                                        <?php echo date('M d, Y h:i A', strtotime($notif['created_at'])); ?>
                                    </small>
                                </a>
                            <?php endforeach; ?>

                            <a href="notifications.php" class="dropdown-item view-all-link">
                                View All Notifications
                            </a>
                        <?php else: ?>
                            <div class="dropdown-item" style="text-align:center;color:#888;">
                                No new notifications.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="profile-wrapper">
                    <div class="profile-trigger" onclick="toggleDropdown('profileDropdown')">
                        <?php if (!empty($profile_image)): ?>
                            <img
                                src="<?php echo htmlspecialchars($profile_image); ?>"
                                alt="Profile"
                                class="profile-avatar"
                                onerror="this.style.display='none';this.nextElementSibling.style.display='inline-block';"
                            >
                            <i
                                class="fa-solid fa-circle-user"
                                style="font-size:20px;color:var(--brand-blue);display:none;"
                            ></i>
                        <?php else: ?>
                            <i
                                class="fa-solid fa-circle-user"
                                style="font-size:20px;color:var(--brand-blue);"
                            ></i>
                        <?php endif; ?>

                        <span>Hi, <?php echo htmlspecialchars($full_name); ?></span>
                        <i class="fa-solid fa-chevron-down" style="font-size:10px;color:#91a0ae;"></i>
                    </div>

                    <div class="dropdown-menu" id="profileDropdown" style="width:210px;">
                        <a href="edit_profile.php" class="dropdown-item">
                            <i class="fa-solid fa-user"></i> My Profile
                        </a>

                        <a href="bookings.php" class="dropdown-item">
                            <i class="fa-solid fa-calendar-check"></i> My Bookings
                        </a>

                        <a href="petprofile.php" class="dropdown-item">
                            <i class="fa-solid fa-paw"></i> My Pets
                        </a>

                        <a
                            href="logout.php"
                            class="dropdown-item"
                            style="color:#dc3545;border-top:1px solid #eaeaea;"
                        >
                            <i class="fa-solid fa-right-from-bracket"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main>
        <div class="back-nav">
            <a href="petprofile.php">
                <i class="fa-solid fa-arrow-left"></i>
                Back to Pet Profiles
            </a>
        </div>

        <div class="page-intro">
            <div class="page-heading">
                <div class="page-kicker">
                    <i class="fa-solid fa-paw"></i>
                    Pet profile management
                </div>

                <h1>Edit Pet Profile</h1>
                <p>Update your pet's information and keep their profile accurate.</p>
            </div>

            <div class="pet-chip">
                <i class="fa-solid fa-shield-dog"></i>
                Owner-only access
            </div>
        </div>

        <?php if(isset($error_msg)): ?>
            <div class="alert-error">
                <i class="fa-solid fa-circle-exclamation"></i>
                <div><?php echo htmlspecialchars($error_msg); ?></div>
            </div>
        <?php endif; ?>

        <section class="form-card">
            <div class="form-card-top">
                <?php
                    $current_type = strtolower($pet_data['pet_type'] ?? 'dog');
                    $pet_icon = $current_type === 'cat' ? 'fa-cat' : 'fa-dog';
                ?>

                <div class="pet-icon">
                    <i class="fa-solid <?php echo $pet_icon; ?>"></i>
                </div>

                <div class="form-card-heading">
                    <div class="kicker">Pet details</div>
                    <h2><?php echo htmlspecialchars($pet_data['name']); ?></h2>
                    <p>Update the fields below, then save your changes.</p>
                </div>
            </div>

            <div class="form-divider"></div>

            <form
                action="edit_pet.php?id=<?php echo $pet_id; ?>"
                method="POST"
            >
                <input
                    type="hidden"
                    name="pet_id"
                    value="<?php echo htmlspecialchars($pet_id); ?>"
                >

                <div class="form-grid">

                    <div class="form-group">
                        <label for="p_name">
                            Pet Name <span class="required">*</span>
                        </label>

                        <input
                            type="text"
                            id="p_name"
                            name="p_name"
                            class="form-control"
                            value="<?php echo htmlspecialchars($pet_data['name']); ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="p_type">
                            Pet Type <span class="required">*</span>
                        </label>

                        <select
                            id="p_type"
                            name="p_type"
                            class="form-control"
                            required
                        >
                            <option
                                value="Dog"
                                <?php echo (isset($pet_data['pet_type']) && $pet_data['pet_type'] == 'Dog') ? 'selected' : ''; ?>
                            >
                                Dog
                            </option>

                            <option
                                value="Cat"
                                <?php echo (isset($pet_data['pet_type']) && $pet_data['pet_type'] == 'Cat') ? 'selected' : ''; ?>
                            >
                                Cat
                            </option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="p_breed">
                            Breed <span class="required">*</span>
                        </label>

                        <input
                            type="text"
                            id="p_breed"
                            name="p_breed"
                            class="form-control"
                            value="<?php echo htmlspecialchars($pet_data['breed']); ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="p_age">
                            Age <span class="required">*</span>
                        </label>

                        <input
                            type="number"
                            id="p_age"
                            name="p_age"
                            class="form-control"
                            step="0.1"
                            min="0"
                            value="<?php echo htmlspecialchars($pet_data['age']); ?>"
                            required
                        >

                        <div class="field-help">Enter age in years.</div>
                    </div>

                    <div class="form-group">
                        <label for="p_weight">
                            Weight (kg) <span class="required">*</span>
                        </label>

                        <input
                            type="number"
                            id="p_weight"
                            name="p_weight"
                            class="form-control"
                            step="0.1"
                            min="0"
                            value="<?php echo htmlspecialchars($pet_data['weight'] ?? ''); ?>"
                            required
                        >

                        <div class="field-help">Enter your pet's current weight in kilograms.</div>
                    </div>

                    <div class="form-group">
                        <label for="p_gender">
                            Gender <span class="required">*</span>
                        </label>

                        <select
                            id="p_gender"
                            name="p_gender"
                            class="form-control"
                            required
                        >
                            <option
                                value="Male"
                                <?php echo ($pet_data['gender'] == 'Male') ? 'selected' : ''; ?>
                            >
                                Male
                            </option>

                            <option
                                value="Female"
                                <?php echo ($pet_data['gender'] == 'Female') ? 'selected' : ''; ?>
                            >
                                Female
                            </option>
                        </select>
                    </div>

                </div>

                <div class="form-note">
                    <i class="fa-solid fa-circle-info"></i>
                    <span>
                        Keeping this information updated helps Boogie's staff provide
                        more accurate care, appointment details, and medical records.
                    </span>
                </div>

                <div class="form-actions">
                    <a href="petprofile.php" class="btn btn-cancel">
                        Cancel
                    </a>

                    <button type="submit" class="btn btn-save">
                        <i class="fa-solid fa-floppy-disk"></i>
                        Save Changes
                    </button>
                </div>
            </form>
        </section>
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
                <a href="faqs.php">FAQs</a>
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
        // ===== DROPDOWN LOGIC =====
        function toggleDropdown(id) {
            document.querySelectorAll('.dropdown-menu').forEach(menu => {
                if (menu.id !== id) {
                    menu.classList.remove('active');
                }
            });

            const target = document.getElementById(id);
            if (target) {
                target.classList.toggle('active');
            }
        }

        window.addEventListener('click', function(e) {
            const notif = document.querySelector('.notification-wrapper');
            const profile = document.querySelector('.profile-wrapper');

            if (
                notif && profile &&
                !notif.contains(e.target) &&
                !profile.contains(e.target)
            ) {
                document.querySelectorAll('.dropdown-menu').forEach(menu => {
                    menu.classList.remove('active');
                });
            }
        });

        // ===== REAL-TIME NOTIFICATIONS =====
        const notifSound = new Audio('notification.mp3');
        let previousUnreadCount = <?php echo $unread_count; ?>;

        function updateNotifications() {
            fetch('get_unread_notifs.php')
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Notification request failed');
                    }
                    return response.json();
                })
                .then(data => {
                    const badge = document.getElementById('notif-badge');

                    if (data.unread > previousUnreadCount) {
                        notifSound.play().catch(() => {});
                    }

                    previousUnreadCount = data.unread;

                    if (data.unread > 0) {
                        badge.style.display = 'inline-flex';
                        badge.innerText = data.unread;
                    } else {
                        badge.style.display = 'none';
                    }
                })
                .catch(error => {
                    console.error('Error fetching notifications:', error);
                });
        }

        setInterval(updateNotifications, 3000);
    </script>

</body>
</html>
