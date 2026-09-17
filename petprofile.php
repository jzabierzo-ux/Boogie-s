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

// --- UPDATED: Fetching profile_image ONLY (Removed max_pets since limit is abolished) ---
$user_query = mysqli_query($conn, "SELECT profile_image FROM users WHERE id = '$user_id'");
$user_data = mysqli_fetch_assoc($user_query);
$profile_image = isset($user_data['profile_image']) ? $user_data['profile_image'] : null;

// Check how many pets the user currently has
$count_query = "SELECT COUNT(*) as pet_count FROM pets WHERE owner_id = '$user_id'";
$count_result = mysqli_query($conn, $count_query);
$count_row = mysqli_fetch_assoc($count_result);
$current_pet_count = $count_row['pet_count'];

// --- 2. LOGIC PARA SA PAG-ADD NG PET (Unlimited now) ---
if (isset($_POST['add_pet'])) {
    
    // Kinukuha na rin natin yung pet_type, breed at gender para kumpleto sa display
    $pet_name = mysqli_real_escape_string($conn, $_POST['name']);
    $pet_type = mysqli_real_escape_string($conn, $_POST['pet_type']);
    $breed = mysqli_real_escape_string($conn, $_POST['breed']);
    $age = mysqli_real_escape_string($conn, $_POST['age']);
    $gender = mysqli_real_escape_string($conn, $_POST['gender']);
    
    // Insert Query: Automatically approved, no status needed
    $insert_pet_query = "INSERT INTO pets (owner_id, name, pet_type, breed, age, gender, created_at) 
                         VALUES ('$user_id', '$pet_name', '$pet_type', '$breed', '$age', '$gender', NOW())";

    if (mysqli_query($conn, $insert_pet_query)) {
        
        // --- 3. LOGIC PARA SA NOTIFICATION ---
        $notif_title = "New Pet Profile Created!";
        $raw_notif_message = "A new pet profile for '$pet_name' has been successfully registered to your account.";
        $safe_notif_message = mysqli_real_escape_string($conn, $raw_notif_message);
        $notif_type = "announcement"; 

        $notif_query = "INSERT INTO notifications (user_id, title, message, type, is_read, created_at) 
                        VALUES ('$user_id', '$notif_title', '$safe_notif_message', '$notif_type', 0, NOW())";
        
        mysqli_query($conn, $notif_query);
        
        header("Location: petprofile.php?success=1");
        exit();
    } else {
        die("Error inserting pet: " . mysqli_error($conn));
    }
}

// --- LOGIC PARA SA PAG-DELETE NG PET ---
if (isset($_GET['delete_id'])) {
    $delete_id = mysqli_real_escape_string($conn, $_GET['delete_id']);

    // Security: Only delete if the pet belongs to the currently logged-in user
    $delete_query = "DELETE FROM pets WHERE id = '$delete_id' AND owner_id = '$user_id'";
    
    if (mysqli_query($conn, $delete_query)) {
        header("Location: petprofile.php?success=deleted");
        exit();
    } else {
        die("Error deleting pet: " . mysqli_error($conn));
    }
}

// --- 4. FETCH PETS ---
$query = "SELECT * FROM pets WHERE owner_id = '$user_id' ORDER BY id DESC";
$result = mysqli_query($conn, $query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pet Profiles | Boogie's Pet Care Services - Dasmariñas</title>
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

.notification-bell:hover{
    background:#f8fafc;
}

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

.profile-trigger:hover{
    background:#f8fafc;
}

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

.dropdown-menu.active{
    display:flex;
}

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

.dropdown-item:last-child{
    border-bottom:0;
}

.view-all-link{
    text-align:center;
    font-weight:800;
    color:var(--brand-blue);
}

/* ===== PAGE ===== */
main{
    width:min(1120px,92%);
    margin:0 auto;
    padding:42px 0 76px;
}

.page-top{
    margin-bottom:24px;
}

.back-link{
    display:inline-flex;
    align-items:center;
    gap:7px;
    color:#748396;
    text-decoration:none;
    font-size:11px;
    font-weight:700;
    margin-bottom:14px;
}

.back-link:hover{
    color:var(--brand-blue);
}

.page-heading-row{
    display:flex;
    align-items:flex-end;
    justify-content:space-between;
    gap:20px;
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

.header-stat{
    padding:11px 14px;
    min-width:145px;
    text-align:center;
    background:#fff;
    border:1px solid var(--line);
    border-radius:12px;
    box-shadow:0 6px 18px rgba(0,31,63,.04);
}

.header-stat span{
    color:#8d9baa;
    font-size:8px;
    text-transform:uppercase;
    letter-spacing:.7px;
    font-weight:800;
}

.header-stat strong{
    display:block;
    color:var(--brand-blue);
    font-size:22px;
    line-height:1.1;
    margin-top:2px;
    font-weight:800;
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
    font-weight:700;
}

.alert.success{
    background:#e7f8ef;
    border:1px solid #ccebd9;
    color:#176c47;
}

.empty-state{
    background:#fff;
    border:1px dashed #d7e1ea;
    border-radius:20px;
    padding:62px 24px;
    text-align:center;
    box-shadow:var(--shadow);
}

.empty-icon{
    width:68px;
    height:68px;
    margin:0 auto 14px;
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    background:var(--brand-yellow-soft);
    color:#b18400;
    font-size:27px;
}

.empty-state h2{
    color:var(--brand-blue);
    font-size:20px;
    font-weight:800;
    margin-bottom:5px;
}

.empty-state p{
    color:var(--muted);
    font-size:11px;
    margin-bottom:18px;
}

.btn-primary{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    min-height:42px;
    padding:0 17px;
    border-radius:10px;
    background:var(--brand-blue);
    color:var(--brand-yellow);
    text-decoration:none;
    font-size:11px;
    font-weight:800;
    border:0;
    cursor:pointer;
}

.btn-primary:hover{
    background:var(--brand-blue-2);
}

/* ===== PET CARDS ===== */
.pet-grid{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:18px;
}

.pet-card{
    position:relative;
    overflow:hidden;
    background:#fff;
    border:1px solid var(--line);
    border-radius:18px;
    box-shadow:var(--shadow);
    padding:21px;
    transition:border-color .2s,box-shadow .2s,transform .2s;
}

.pet-card:hover{
    border-color:#d1dde7;
    box-shadow:0 15px 30px rgba(0,31,63,.08);
    transform:translateY(-2px);
}

.pet-card-top{
    display:flex;
    align-items:flex-start;
    gap:13px;
    margin-bottom:18px;
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

.pet-heading{
    min-width:0;
    flex:1;
}

.pet-type-label{
    display:block;
    color:#8c99a8;
    font-size:8px;
    text-transform:uppercase;
    letter-spacing:.7px;
    font-weight:800;
    margin-bottom:4px;
}

.pet-heading h2{
    font-size:18px;
    line-height:1.25;
    margin:0;
}

.pet-heading h2 a{
    color:var(--brand-blue);
    text-decoration:none;
    font-weight:800;
}

.pet-heading h2 a:hover{
    color:#8a6900;
}

.pet-info-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:10px;
    padding-top:15px;
    border-top:1px solid #edf1f5;
}

.info-item{
    min-width:0;
    padding:10px;
    border-radius:10px;
    background:#f8fafc;
}

.info-item.full{
    grid-column:1 / -1;
}

.info-item label{
    display:block;
    color:#8d9aaa;
    font-size:8px;
    text-transform:uppercase;
    letter-spacing:.6px;
    font-weight:800;
    margin-bottom:3px;
}

.info-item span{
    display:block;
    color:var(--brand-blue);
    font-size:10px;
    font-weight:700;
    line-height:1.45;
}

.pet-actions{
    display:grid;
    grid-template-columns:1.25fr 1fr 1fr;
    gap:7px;
    margin-top:16px;
}

.pet-action{
    min-height:38px;
    border-radius:10px;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:5px;
    text-decoration:none;
    font-size:9px;
    font-weight:800;
    border:1px solid transparent;
}

.pet-action.records{
    background:#eef6ff;
    color:var(--brand-blue);
    border-color:#d6e7f5;
}

.pet-action.records:hover{
    background:#dfeef9;
}

.pet-action.edit{
    background:#f8fafc;
    color:var(--brand-blue);
    border-color:#e1e8ef;
}

.pet-action.edit:hover{
    background:#eef2f6;
}

.pet-action.delete{
    background:var(--danger-soft);
    color:var(--danger);
    border-color:#f3d0d4;
}

.pet-action.delete:hover{
    background:#ffe5e8;
}

.pet-card::after{
    content:'';
    position:absolute;
    width:95px;
    height:95px;
    right:-46px;
    top:-46px;
    border-radius:50%;
    background:rgba(255,204,0,.08);
    pointer-events:none;
}

/* ===== ADD PET MODAL ===== */
.modal{
    display:none;
    position:fixed;
    inset:0;
    z-index:2000;
    background:rgba(0,31,63,.58);
    align-items:center;
    justify-content:center;
    padding:20px;
}

.modal.active{
    display:flex;
}

.modal-content{
    width:100%;
    max-width:500px;
    max-height:90vh;
    overflow:auto;
    background:#fff;
    border:1px solid rgba(255,255,255,.3);
    border-radius:19px;
    box-shadow:var(--shadow-lg);
}

.modal-head{
    position:sticky;
    top:0;
    z-index:2;
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:15px;
    padding:21px 22px;
    background:#fff;
    border-bottom:1px solid var(--line);
}

.modal-head .kicker{
    color:#8b99a9;
    font-size:8px;
    text-transform:uppercase;
    letter-spacing:1px;
    font-weight:800;
    margin-bottom:4px;
}

.modal-head h2{
    color:var(--brand-blue);
    font-size:20px;
    line-height:1.2;
    font-weight:800;
}

.modal-head p{
    color:var(--muted);
    font-size:9px;
    margin-top:4px;
}

.modal-close{
    width:36px;
    height:36px;
    border:1px solid var(--line);
    border-radius:10px;
    background:#fff;
    color:#778797;
    cursor:pointer;
    display:flex;
    align-items:center;
    justify-content:center;
}

.modal-close:hover{
    background:#f8fafc;
    color:var(--brand-blue);
}

.modal-body{
    padding:22px;
}

.form-group{
    margin-bottom:16px;
}

.form-group label{
    display:block;
    color:var(--brand-blue);
    font-size:10px;
    font-weight:800;
    margin-bottom:6px;
}

.form-control{
    width:100%;
    min-height:42px;
    padding:9px 12px;
    border:1px solid #dce5ed;
    border-radius:10px;
    background:#fbfcfe;
    color:var(--text);
    font-size:11px;
    outline:none;
}

.form-control:focus{
    background:#fff;
    border-color:#9bb6cc;
    box-shadow:0 0 0 3px rgba(0,31,63,.05);
}

.form-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:0 14px;
}

.modal-actions{
    display:flex;
    justify-content:flex-end;
    gap:9px;
    margin-top:19px;
}

.modal-cancel,
.modal-submit{
    min-height:42px;
    padding:0 16px;
    border-radius:10px;
    font-size:10px;
    font-weight:800;
    cursor:pointer;
}

.modal-cancel{
    background:#fff;
    color:var(--text);
    border:1px solid #cbd5e1;
}

.modal-cancel:hover{
    background:#f8fafc;
}

.modal-submit{
    background:var(--brand-blue);
    color:var(--brand-yellow);
    border:0;
}

.modal-submit:hover{
    background:var(--brand-blue-2);
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
    display:block;
    color:#cbd5e1;
    text-decoration:none;
    font-size:12px;
    line-height:1.7;
    margin-bottom:8px;
}

.footer-main a:hover{
    color:#fff;
}

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

@media (max-width:980px){
    .pet-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .profile-trigger span{
        display:none;
    }

    .footer-main{
        grid-template-columns:1fr 1fr;
    }
}

@media (max-width:680px){
    .nav-top{
        width:92%;
        flex-wrap:wrap;
    }

    .page-heading-row{
        display:block;
    }

    .header-stat{
        display:inline-block;
        margin-top:14px;
        text-align:left;
    }

    .page-heading-row > div:last-child{
        width:100%;
        display:flex !important;
        align-items:center;
        justify-content:space-between;
        gap:10px;
        margin-top:14px;
    }

    .page-heading-row > div:last-child .btn-primary{
        flex:1;
    }

    main{
        width:92%;
        padding-top:30px;
    }

    .pet-grid{
        grid-template-columns:1fr;
    }

    .form-grid{
        grid-template-columns:1fr;
    }

    .pet-actions{
        grid-template-columns:1fr 1fr 1fr;
    }

    .footer-main{
        grid-template-columns:1fr;
        gap:25px;
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
                        <span
                            id="notif-badge"
                            class="notification-badge"
                            style="display: <?php echo ($unread_count > 0) ? 'inline-flex' : 'none'; ?>;"
                        >
                            <?php echo $unread_count; ?>
                        </span>
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
                                onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-block';"
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
        <div class="page-top">
            <a href="dashboard.php" class="back-link">
                <i class="fa-solid fa-arrow-left"></i>
                Back to Dashboard
            </a>

            <div class="page-heading-row">
                <div class="page-heading">
                    <div class="page-kicker">
                        <i class="fa-solid fa-paw"></i>
                        Your pets
                    </div>

                    <h1>Pet Profiles</h1>
                    <p>Manage your pets' official medical and booking information.</p>
                </div>

                <div style="display:flex; align-items:center; gap:10px;">
                    <button type="button" class="btn-primary" onclick="openModal()">
                        <i class="fa-solid fa-plus"></i>
                        Add New Pet
                    </button>

                    <div class="header-stat">
                        <span>Registered Pets</span>
                        <strong><?php echo (int)$current_pet_count; ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <?php if(isset($_GET['success'])): ?>
            <?php if($_GET['success'] == '1'): ?>
                <div class="alert success">
                    <i class="fa-solid fa-circle-check"></i>
                    <div>Pet added successfully!</div>
                </div>
            <?php elseif($_GET['success'] == 'deleted'): ?>
                <div class="alert success">
                    <i class="fa-solid fa-circle-check"></i>
                    <div>Pet profile deleted successfully!</div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (mysqli_num_rows($result) > 0): ?>

            <div class="pet-grid">
                <?php while($row = mysqli_fetch_assoc($result)): ?>

                    <?php
                        $pet_type = $row['pet_type'] ?? 'Pet';
                        $pet_icon = strtolower($pet_type) === 'cat' ? 'fa-cat' : 'fa-dog';
                    ?>

                    <article class="pet-card">
                        <div class="pet-card-top">
                            <div class="pet-icon">
                                <i class="fa-solid <?php echo $pet_icon; ?>"></i>
                            </div>

                            <div class="pet-heading">
                                <span class="pet-type-label">
                                    <?php echo htmlspecialchars($pet_type); ?>
                                    <?php if (!empty($row['breed'])): ?>
                                        · <?php echo htmlspecialchars($row['breed']); ?>
                                    <?php endif; ?>
                                </span>

                                <h2>
                                    <a
                                        href="pet_records.php?id=<?php echo $row['id']; ?>"
                                        title="View Medical & Booking Records"
                                    >
                                        <?php echo htmlspecialchars($row['name']); ?>
                                    </a>
                                </h2>
                            </div>
                        </div>

                        <div class="pet-info-grid">
                            <div class="info-item">
                                <label>Gender</label>
                                <span><?php echo htmlspecialchars($row['gender'] ?? 'Not set'); ?></span>
                            </div>

                            <div class="info-item">
                                <label>Age</label>
                                <span><?php echo htmlspecialchars($row['age']); ?> Years</span>
                            </div>

                            <div class="info-item full">
                                <label>Registered</label>
                                <span>
                                    <?php echo isset($row['created_at'])
                                        ? date('M d, Y', strtotime($row['created_at']))
                                        : 'N/A'; ?>
                                </span>
                            </div>
                        </div>

                        <div class="pet-actions">
                            <a
                                href="pet_records.php?id=<?php echo $row['id']; ?>"
                                class="pet-action records"
                            >
                                <i class="fa-solid fa-book-medical"></i>
                                Records
                            </a>

                            <a
                                href="edit_pet.php?id=<?php echo $row['id']; ?>"
                                class="pet-action edit"
                            >
                                <i class="fa-solid fa-pen-to-square"></i>
                                Edit
                            </a>

                            <a
                                href="?delete_id=<?php echo $row['id']; ?>"
                                class="pet-action delete"
                                onclick="return confirm('Are you sure you want to delete <?php echo htmlspecialchars(addslashes($row['name'])); ?>\\'s profile? This action cannot be undone.');"
                            >
                                <i class="fa-solid fa-trash"></i>
                                Delete
                            </a>
                        </div>
                    </article>

                <?php endwhile; ?>
            </div>

        <?php else: ?>

            <div class="empty-state">
                <div class="empty-icon">
                    <i class="fa-solid fa-dog"></i>
                </div>

                <h2>No pets registered yet</h2>

                <p>
                    Add your first pet profile to keep their information ready for
                    appointments and medical records.
                </p>

                <button type="button" class="btn-primary" onclick="openModal()">
                    <i class="fa-solid fa-plus"></i>
                    Add New Pet
                </button>
            </div>

        <?php endif; ?>
    </main>

    <div id="addPetModal" class="modal" aria-hidden="true">
        <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="addPetTitle">

            <div class="modal-head">
                <div>
                    <div class="kicker">Pet registration</div>
                    <h2 id="addPetTitle">Register New Pet</h2>
                    <p>Add the basic details for your pet profile.</p>
                </div>

                <button
                    type="button"
                    class="modal-close"
                    onclick="closeModal()"
                    aria-label="Close"
                >
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <div class="modal-body">
                <form method="POST" action="">
                    <div class="form-grid">

                        <div class="form-group">
                            <label for="pet_name">Pet Name</label>
                            <input
                                type="text"
                                id="pet_name"
                                name="name"
                                class="form-control"
                                required
                                placeholder="e.g. Max"
                            >
                        </div>

                        <div class="form-group">
                            <label for="pet_type">Pet Type</label>
                            <select id="pet_type" name="pet_type" class="form-control" required>
                                <option value="" disabled selected>Select Pet Type</option>
                                <option value="Dog">Dog</option>
                                <option value="Cat">Cat</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="breed">Breed</label>
                            <input
                                type="text"
                                id="breed"
                                name="breed"
                                class="form-control"
                                required
                                placeholder="e.g. Golden Retriever"
                            >
                        </div>

                        <div class="form-group">
                            <label for="age">Age (Years)</label>
                            <input
                                type="number"
                                step="0.1"
                                min="0"
                                id="age"
                                name="age"
                                class="form-control"
                                required
                                placeholder="e.g. 2"
                            >
                        </div>

                        <div class="form-group" style="grid-column:1/-1;">
                            <label for="gender">Gender</label>
                            <select id="gender" name="gender" class="form-control" required>
                                <option value="" disabled selected>Select Gender</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Unknown">Unknown</option>
                            </select>
                        </div>

                    </div>

                    <div class="modal-actions">
                        <button type="button" class="modal-cancel" onclick="closeModal()">
                            Cancel
                        </button>

                        <button type="submit" name="add_pet" class="modal-submit">
                            <i class="fa-solid fa-floppy-disk"></i>
                            Save Pet Profile
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

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
        const modal = document.getElementById("addPetModal");

        function openModal() {
            if (!modal) return;
            modal.classList.add("active");
            modal.setAttribute("aria-hidden", "false");
            const firstField = document.getElementById("pet_name");
            if (firstField) {
                setTimeout(() => firstField.focus(), 50);
            }
        }

        function closeModal() {
            if (!modal) return;
            modal.classList.remove("active");
            modal.setAttribute("aria-hidden", "true");
        }

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

        // Close modal/dropdowns when clicking outside.
        window.addEventListener('click', function(e) {
            if (modal && e.target === modal) {
                closeModal();
            }

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

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();

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
