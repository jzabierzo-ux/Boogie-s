<?php
session_start();
include 'db_connect.php'; 

// 1. SECURITY: Check if logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

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

// 2. CHECK PET ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: petprofile.php");
    exit;
}

$pet_id = mysqli_real_escape_string($conn, $_GET['id']);

// 3. FETCH PET DETAILS
$pet_query = "SELECT * FROM pets WHERE id = '$pet_id' AND owner_id = '$user_id'";
$pet_result = mysqli_query($conn, $pet_query);

if (!$pet_result || mysqli_num_rows($pet_result) == 0) {
    die("
    <div style='text-align:center; padding: 100px; font-family: Poppins, sans-serif; background: #f4f7f6; height: 100vh;'>
        <h2 style='color: #dc3545;'><i class='fa-solid fa-triangle-exclamation'></i> Pet Not Found</h2>
        <p>You are not authorized to view this record or it doesn't exist.</p>
        <a href='petprofile.php' style='display:inline-block; margin-top:15px; padding: 10px 20px; background:#001f3f; color:white; text-decoration:none; border-radius:8px;'>Go Back</a>
    </div>");
}
$pet = mysqli_fetch_assoc($pet_result);

// 4. FETCH RECORDS FROM APPOINTMENTS TABLE (Filtered by 'Completed' booking_status)

// A. VET MEDICAL RECORDS 
$vet_query = "SELECT * FROM appointments 
              WHERE pet_id = '$pet_id' AND service LIKE '%Vet%' AND booking_status = 'Completed' 
              ORDER BY appointment_date DESC";
$vet_result = mysqli_query($conn, $vet_query);

// B. GROOMING HISTORY 
$grooming_query = "SELECT * FROM appointments 
                   WHERE pet_id = '$pet_id' AND service LIKE '%Grooming%' AND booking_status = 'Completed' 
                   ORDER BY appointment_date DESC";
$grooming_result = mysqli_query($conn, $grooming_query);

// C. PET HOTEL HISTORY 
$hotel_query = "SELECT * FROM appointments 
                WHERE pet_id = '$pet_id' AND service LIKE '%Hotel%' AND booking_status = 'Completed' 
                ORDER BY appointment_date DESC";
$hotel_result = mysqli_query($conn, $hotel_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($pet['name'] ?? 'Pet'); ?>'s Records | Boogie's Pet Care</title>
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
    --purple:#7650a8;
    --purple-soft:#f1eaff;
    --green:#178957;
    --green-soft:#e7f8ef;
    --orange:#b56b0b;
    --orange-soft:#fff3df;
    --shadow:0 10px 30px rgba(0,31,63,.06);
    --shadow-lg:0 18px 42px rgba(0,31,63,.10);
}

*{box-sizing:border-box;margin:0;padding:0;font-family:'Poppins',sans-serif}
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
.logo-text{display:flex;flex-direction:column;line-height:1.05}
.logo-text b{color:var(--brand-blue);font-size:20px;font-weight:800}
.logo-text span{
    margin-top:3px;
    color:#8c9aae;
    font-size:9px;
    font-weight:700;
    letter-spacing:1.2px;
}
.user-controls{display:flex;align-items:center;gap:12px}
.notification-wrapper,.profile-wrapper{position:relative}
.notification-bell{
    position:relative;
    width:42px;height:42px;
    border:1px solid var(--line);
    border-radius:12px;
    background:#fff;
    color:var(--brand-blue);
    display:flex;align-items:center;justify-content:center;
    cursor:pointer;
}
.notification-bell:hover{background:#f8fafc}
.notification-badge{
    position:absolute;
    top:-5px;right:-5px;
    min-width:18px;height:18px;
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
    width:34px;height:34px;
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
.dropdown-item:hover{background:#f8fafc;color:var(--brand-blue)}
.dropdown-item.unread{background:#eef6ff;font-weight:700}
.dropdown-item i{width:18px;margin-right:7px;text-align:center}
.dropdown-item:last-child{border-bottom:0}
.view-all-link{text-align:center;font-weight:800;color:var(--brand-blue)}

/* ===== PAGE ===== */
main{
    width:min(1120px,92%);
    margin:0 auto;
    padding:42px 0 80px;
}
.back-nav{margin-bottom:14px}
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

.page-heading-row{
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
.owner-chip{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:10px 13px;
    background:#fff;
    border:1px solid var(--line);
    border-radius:12px;
    color:#66798b;
    font-size:10px;
    font-weight:700;
    white-space:nowrap;
}
.owner-chip i{color:var(--brand-blue)}

.pet-hero{
    position:relative;
    overflow:hidden;
    background:#fff;
    border:1px solid var(--line);
    border-radius:20px;
    box-shadow:var(--shadow);
    padding:25px;
    margin-bottom:22px;
}
.pet-hero::before{
    content:"";
    position:absolute;
    width:230px;height:230px;
    right:-90px;top:-120px;
    border-radius:50%;
    background:rgba(255,204,0,.12);
}
.pet-hero::after{
    content:"";
    position:absolute;
    width:140px;height:140px;
    left:-65px;bottom:-90px;
    border-radius:50%;
    background:rgba(0,31,63,.04);
}
.pet-hero-inner{
    position:relative;
    z-index:1;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:25px;
}
.pet-main{
    display:flex;
    align-items:center;
    gap:17px;
    min-width:0;
}
.pet-icon{
    width:76px;height:76px;
    flex:0 0 76px;
    border-radius:20px;
    background:var(--brand-yellow-soft);
    color:var(--brand-blue);
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:32px;
}
.pet-info h2{
    color:var(--brand-blue);
    font-size:25px;
    line-height:1.2;
    font-weight:800;
    text-transform:capitalize;
}
.pet-details{
    display:flex;
    flex-wrap:wrap;
    gap:7px;
    margin-top:8px;
}
.pet-details span{
    display:inline-flex;
    align-items:center;
    gap:5px;
    padding:6px 9px;
    background:var(--soft);
    border:1px solid var(--line);
    border-radius:999px;
    color:#607487;
    font-size:9px;
    font-weight:700;
}
.pet-details i{color:#8297a8}
.pet-profile-action{
    display:inline-flex;
    align-items:center;
    gap:7px;
    padding:10px 13px;
    border-radius:10px;
    border:1px solid #d7e1e9;
    background:#fff;
    color:var(--brand-blue);
    text-decoration:none;
    font-size:10px;
    font-weight:800;
}
.pet-profile-action:hover{background:#f8fafc}

.medical-alert{
    position:relative;
    z-index:1;
    margin-top:20px;
    padding:13px 15px;
    border-radius:12px;
    background:#fff2f2;
    border:1px solid #f2cccc;
    border-left:4px solid #df454f;
}
.medical-alert h3{
    display:flex;
    align-items:center;
    gap:8px;
    color:#aa2936;
    font-size:12px;
    font-weight:800;
    margin-bottom:5px;
}
.medical-alert p{
    color:#7c3940;
    font-size:10px;
    line-height:1.7;
    margin-top:3px;
}
.medical-alert strong{color:#992c38}

.record-section{
    background:#fff;
    border:1px solid var(--line);
    border-radius:18px;
    box-shadow:var(--shadow);
    margin-bottom:20px;
    overflow:hidden;
}
.record-head{
    padding:19px 21px;
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:15px;
    border-bottom:1px solid #edf1f5;
}
.record-title{
    display:flex;
    align-items:center;
    gap:10px;
}
.record-icon{
    width:38px;height:38px;
    flex:0 0 38px;
    border-radius:11px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:15px;
}
.record-icon.vet{background:var(--purple-soft);color:var(--purple)}
.record-icon.groom{background:var(--green-soft);color:var(--green)}
.record-icon.hotel{background:var(--orange-soft);color:var(--orange)}
.record-head h2{
    color:var(--brand-blue);
    font-size:17px;
    line-height:1.2;
    font-weight:800;
}
.record-head p{
    color:var(--muted);
    font-size:9px;
    margin-top:3px;
}
.record-count{
    padding:6px 9px;
    border-radius:999px;
    background:#f7f9fb;
    color:#7e8d9b;
    font-size:8px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.5px;
}
.table-wrap{overflow-x:auto}
table{
    width:100%;
    border-collapse:collapse;
    min-width:650px;
}
th{
    padding:12px 16px;
    background:#f8fafc;
    color:#81909e;
    border-bottom:1px solid var(--line);
    font-size:8px;
    text-transform:uppercase;
    letter-spacing:.7px;
    font-weight:800;
    text-align:left;
}
td{
    padding:14px 16px;
    border-bottom:1px solid #edf1f5;
    color:#435466;
    font-size:10px;
    vertical-align:top;
    line-height:1.6;
}
tr:last-child td{border-bottom:0}
tr:hover td{background:#fbfcfe}
.date-cell strong{
    color:var(--brand-blue);
    font-size:10px;
    font-weight:800;
}
.service-badge{
    display:inline-flex;
    align-items:center;
    padding:5px 8px;
    border-radius:999px;
    font-size:8px;
    font-weight:800;
}
.service-badge.vet{background:var(--purple-soft);color:var(--purple)}
.service-badge.groom{background:var(--green-soft);color:var(--green)}
.service-badge.hotel{background:var(--orange-soft);color:var(--orange)}
.amount{
    color:var(--brand-blue);
    font-weight:800;
}
.empty-records{
    padding:44px 20px;
    text-align:center;
}
.empty-records-icon{
    width:58px;height:58px;
    margin:0 auto 11px;
    border-radius:50%;
    background:#f5f8fb;
    color:#a4b0bb;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:22px;
}
.empty-records p{
    color:var(--muted);
    font-size:10px;
}

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
    color:#cbd5e1;
    text-decoration:none;
    display:block;
    font-size:12px;
    line-height:1.7;
    margin-bottom:8px;
}
.footer-main a:hover{color:#fff}
.socials{display:flex;gap:10px;margin-top:16px}
.socials a{
    width:36px;height:36px;
    margin:0;
    border-radius:50%;
    background:rgba(255,255,255,.09);
    display:flex;
    align-items:center;
    justify-content:center;
    color:#fff;
}
.socials a:hover{background:var(--brand-yellow);color:var(--brand-blue)}
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
    .pet-hero-inner{align-items:flex-start}
    .footer-main{grid-template-columns:1fr 1fr}
}
@media (max-width:680px){
    .nav-top{width:92%;flex-wrap:wrap}
    .profile-trigger span{display:none}
    main{width:92%;padding-top:30px}
    .page-heading-row{display:block}
    .owner-chip{margin-top:14px}
    .pet-hero-inner{display:block}
    .pet-main{align-items:flex-start}
    .pet-profile-action{margin-top:14px}
    .pet-info h2{font-size:22px}
    .pet-icon{width:64px;height:64px;flex-basis:64px;font-size:27px}
    .record-head{padding:17px}
    footer{padding:50px 20px 25px}
    .footer-main{grid-template-columns:1fr;gap:25px}
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
                            <i class="fa-solid fa-circle-user" style="font-size:20px;color:var(--brand-blue);display:none;"></i>
                        <?php else: ?>
                            <i class="fa-solid fa-circle-user" style="font-size:20px;color:var(--brand-blue);"></i>
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
                        <a href="logout.php" class="dropdown-item" style="color:#dc3545;border-top:1px solid #eaeaea;">
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

        <div class="page-heading-row">
            <div class="page-heading">
                <div class="page-kicker">
                    <i class="fa-solid fa-file-medical"></i>
                    Pet records
                </div>

                <h1>Medical & Care Records</h1>
                <p>Keep track of your pet's completed veterinary, grooming, and hotel visits.</p>
            </div>

            <div class="owner-chip">
                <i class="fa-solid fa-shield-dog"></i>
                Owner-only access
            </div>
        </div>

        <section class="pet-hero">
            <div class="pet-hero-inner">
                <div class="pet-main">
                    <div class="pet-icon">
                        <?php
                            $type = strtolower($pet['pet_type'] ?? '');
                            echo (strpos($type, 'cat') !== false)
                                ? '<i class="fa-solid fa-cat"></i>'
                                : '<i class="fa-solid fa-dog"></i>';
                        ?>
                    </div>

                    <div class="pet-info">
                        <h2><?php echo htmlspecialchars($pet['name'] ?? 'Unknown Pet'); ?></h2>

                        <div class="pet-details">
                            <span>
                                <i class="fa-solid fa-tag"></i>
                                <?php echo htmlspecialchars($pet['pet_type'] ?? 'N/A'); ?>
                            </span>

                            <span>
                                <i class="fa-solid fa-dna"></i>
                                <?php echo htmlspecialchars($pet['breed'] ?? 'N/A'); ?>
                            </span>

                            <span>
                                <i class="fa-solid fa-venus-mars"></i>
                                <?php echo htmlspecialchars($pet['gender'] ?? 'N/A'); ?>
                            </span>

                            <span>
                                <i class="fa-solid fa-weight-scale"></i>
                                <?php echo htmlspecialchars($pet['weight'] ?? '0'); ?> kg
                            </span>
                        </div>
                    </div>
                </div>

                <a href="edit_pet.php?id=<?php echo urlencode($pet_id); ?>" class="pet-profile-action">
                    <i class="fa-solid fa-pen-to-square"></i>
                    Edit Pet Profile
                </a>
            </div>

            <?php if (!empty($pet['medical_history']) || !empty($pet['special_needs'])): ?>
                <div class="medical-alert">
                    <h3>
                        <i class="fa-solid fa-circle-exclamation"></i>
                        Health & Medical Alert
                    </h3>

                    <?php if(!empty($pet['medical_history'])): ?>
                        <p>
                            <strong>Medical History:</strong>
                            <?php echo nl2br(htmlspecialchars($pet['medical_history'])); ?>
                        </p>
                    <?php endif; ?>

                    <?php if(!empty($pet['special_needs'])): ?>
                        <p>
                            <strong>Special Needs / Allergies:</strong>
                            <?php echo nl2br(htmlspecialchars($pet['special_needs'])); ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="record-section">
            <div class="record-head">
                <div class="record-title">
                    <div class="record-icon vet">
                        <i class="fa-solid fa-stethoscope"></i>
                    </div>

                    <div>
                        <h2>Vet & Medical History</h2>
                        <p>Completed veterinary appointments and recorded findings.</p>
                    </div>
                </div>

                <?php $vet_count = $vet_result ? mysqli_num_rows($vet_result) : 0; ?>
                <span class="record-count"><?php echo $vet_count; ?> records</span>
            </div>

            <?php if ($vet_count > 0): ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Service</th>
                                <th>Attending Vet</th>
                                <th>Diagnosis / Findings</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php while($row = mysqli_fetch_assoc($vet_result)): ?>
                                <tr>
                                    <td class="date-cell">
                                        <strong><?php echo date('M d, Y', strtotime($row['appointment_date'])); ?></strong>
                                    </td>

                                    <td>
                                        <span class="service-badge vet">
                                            <?php echo htmlspecialchars($row['service']); ?>
                                        </span>
                                    </td>

                                    <td>
                                        Dr. <?php echo htmlspecialchars($row['vet_doctor'] ?? 'N/A'); ?>
                                    </td>

                                    <td>
                                        <?php
                                            echo !empty($row['remarks'])
                                                ? nl2br(htmlspecialchars($row['remarks']))
                                                : 'No findings recorded.';
                                        ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-records">
                    <div class="empty-records-icon">
                        <i class="fa-solid fa-notes-medical"></i>
                    </div>
                    <p>No vet records found for this pet yet.</p>
                </div>
            <?php endif; ?>
        </section>

        <section class="record-section">
            <div class="record-head">
                <div class="record-title">
                    <div class="record-icon groom">
                        <i class="fa-solid fa-scissors"></i>
                    </div>

                    <div>
                        <h2>Grooming Logs</h2>
                        <p>Completed grooming services and care notes.</p>
                    </div>
                </div>

                <?php $groom_count = $grooming_result ? mysqli_num_rows($grooming_result) : 0; ?>
                <span class="record-count"><?php echo $groom_count; ?> records</span>
            </div>

            <?php if ($groom_count > 0): ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Service Package</th>
                                <th>Style / Remarks</th>
                                <th>Amount</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php while($row = mysqli_fetch_assoc($grooming_result)): ?>
                                <tr>
                                    <td class="date-cell">
                                        <strong><?php echo date('M d, Y', strtotime($row['appointment_date'])); ?></strong>
                                    </td>

                                    <td>
                                        <span class="service-badge groom">
                                            <?php echo htmlspecialchars($row['service']); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?php echo !empty($row['remarks']) ? htmlspecialchars($row['remarks']) : '-'; ?>
                                    </td>

                                    <td class="amount">
                                        ₱<?php echo number_format($row['total_price'] ?? 0, 2); ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-records">
                    <div class="empty-records-icon">
                        <i class="fa-solid fa-bath"></i>
                    </div>
                    <p>No grooming history found.</p>
                </div>
            <?php endif; ?>
        </section>

        <section class="record-section">
            <div class="record-head">
                <div class="record-title">
                    <div class="record-icon hotel">
                        <i class="fa-solid fa-house-chimney-window"></i>
                    </div>

                    <div>
                        <h2>Pet Hotel History</h2>
                        <p>Completed boarding stays and checkout details.</p>
                    </div>
                </div>

                <?php $hotel_count = $hotel_result ? mysqli_num_rows($hotel_result) : 0; ?>
                <span class="record-count"><?php echo $hotel_count; ?> records</span>
            </div>

            <?php if ($hotel_count > 0): ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Check-in</th>
                                <th>Check-out</th>
                                <th>Service</th>
                                <th>Boarding Remarks</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php while($row = mysqli_fetch_assoc($hotel_result)): ?>
                                <tr>
                                    <td class="date-cell">
                                        <strong><?php echo date('M d, Y', strtotime($row['appointment_date'])); ?></strong>
                                    </td>

                                    <td class="date-cell">
                                        <strong>
                                            <?php
                                                echo (!empty($row['checkout_date']) && $row['checkout_date'] != '0000-00-00')
                                                    ? date('M d, Y', strtotime($row['checkout_date']))
                                                    : 'Still Boarding';
                                            ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <span class="service-badge hotel">
                                            <?php echo htmlspecialchars($row['service']); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?php echo !empty($row['remarks']) ? htmlspecialchars($row['remarks']) : '-'; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-records">
                    <div class="empty-records-icon">
                        <i class="fa-solid fa-bed"></i>
                    </div>
                    <p>No pet hotel bookings found.</p>
                </div>
            <?php endif; ?>
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
                .catch(error => console.error('Error fetching notifications:', error));
        }

        setInterval(updateNotifications, 3000);
    </script>

</body>
</html>
