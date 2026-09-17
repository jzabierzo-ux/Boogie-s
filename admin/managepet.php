<?php
session_start();
include '../db_connect.php'; 

// --- UNIVERSAL SECURITY CHECK ---
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';

// 1. SECURITY: Payagan ang 'admin', 'supervisor', AT 'staff'
if (!isset($_SESSION['logged_in']) || !in_array($current_role, ['admin', 'manager', 'vet'])) {
    header("Location: stafflogin.php");
    exit();
}

// 2. FETCH ADMIN PROFILE (Updated with Profile Image Logic)
$admin_full_name = "User";
$profile_img_path = "";
$first_name = "User";

if (isset($_SESSION['user_id'])) {
    $uid = $_SESSION['user_id'];
    $get_admin = mysqli_query($conn, "SELECT full_name, profile_image FROM users WHERE id = '$uid'");
    if($admin_data = mysqli_fetch_assoc($get_admin)) {
        $admin_full_name = $admin_data['full_name'];
        $profile_img_path = $admin_data['profile_image']; 
        $_SESSION['user_name'] = $admin_full_name; 
        
        // Inayos ang first name para walang comma sa avatar fallback
        $first_name = explode(' ', $admin_full_name)[0];
        $first_name = trim($first_name, ',');
    }
}

$success_msg = '';
$error_msg = '';

// --- ADD NEW PET LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_pet'])) {
    $owner_id = mysqli_real_escape_string($conn, $_POST['owner_id']);
    $pet_name = mysqli_real_escape_string($conn, $_POST['pet_name']);
    $pet_type = mysqli_real_escape_string($conn, $_POST['pet_type']);
    $pet_breed = mysqli_real_escape_string($conn, $_POST['pet_breed']);
    $pet_gender = mysqli_real_escape_string($conn, $_POST['pet_gender']);
    $pet_weight = mysqli_real_escape_string($conn, $_POST['pet_weight']);

    $insert_query = "INSERT INTO pets (owner_id, name, pet_type, breed, gender, weight) 
                     VALUES ('$owner_id', '$pet_name', '$pet_type', '$pet_breed', '$pet_gender', '$pet_weight')";
    
    if (mysqli_query($conn, $insert_query)) {
        $success_msg = "New pet '$pet_name' successfully registered to Owner ID #$owner_id!";
    } else {
        $error_msg = "Error adding pet: " . mysqli_error($conn);
    }
}

// FETCH USERS FOR DROPDOWN (For the Add Pet Modal)
$users_list_query = mysqli_query($conn, "SELECT id, full_name FROM users ORDER BY full_name ASC");

// 3. DATABASE INITIALIZATION & SAFETY CHECK
$table_name = "pets";
$check_table = mysqli_query($conn, "SHOW TABLES LIKE '$table_name'");

// Initialize variables to prevent "Undefined Variable" notices
$total_pets = $male_pets = $female_pets = 0;
$breeds_query = false;
$pets_list = false;
$showing_count = 0;
$filter_gender = $_GET['gender'] ?? '';

if (mysqli_num_rows($check_table) > 0) {
    // 4. FETCH PET STATISTICS
    $total_pets_q  = mysqli_query($conn, "SELECT COUNT(*) as count FROM $table_name");
    $male_pets_q   = mysqli_query($conn, "SELECT COUNT(*) as count FROM $table_name WHERE gender = 'Male'");
    $female_pets_q = mysqli_query($conn, "SELECT COUNT(*) as count FROM $table_name WHERE gender = 'Female'");

    $total_pets  = mysqli_fetch_assoc($total_pets_q)['count'] ?? 0;
    $male_pets   = mysqli_fetch_assoc($male_pets_q)['count'] ?? 0;
    $female_pets = mysqli_fetch_assoc($female_pets_q)['count'] ?? 0;

    // 5. FETCH BREEDS FOR FILTER
    $breeds_query = mysqli_query($conn, "SELECT DISTINCT breed FROM $table_name WHERE breed IS NOT NULL AND breed != '' ORDER BY breed ASC");

    // 6. FETCH ALL PETS WITH PHP FILTERING
    if ($filter_gender === 'Male') {
        $pets_list = mysqli_query($conn, "SELECT * FROM $table_name WHERE gender = 'Male' ORDER BY id DESC");
    } elseif ($filter_gender === 'Female') {
        $pets_list = mysqli_query($conn, "SELECT * FROM $table_name WHERE gender = 'Female' ORDER BY id DESC");
    } else {
        $pets_list = mysqli_query($conn, "SELECT * FROM $table_name ORDER BY id DESC");
    }
    
    $showing_count = ($pets_list) ? mysqli_num_rows($pets_list) : 0;
}

// --- FETCH ADMIN NOTIFICATIONS ---
$admin_notif_query = mysqli_query($conn, "SELECT * FROM admin_notifications WHERE is_read = 0 ORDER BY created_at DESC");
$unread_count = ($admin_notif_query) ? mysqli_num_rows($admin_notif_query) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pet Profiles | Boogie's Pet Care</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --navy-dark: #001f3f; 
            --brand-yellow: #ffcc00; 
            --brand-blue: #001f3f;
            --bg-light: #f4f7f6; 
            --white: #ffffff; 
            --text-main: #2d3436;
            --text-muted: #64748b; 
            --sidebar-width: 260px;
            --border-color: #d1d5db;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Poppins', sans-serif;}
        body { background-color: var(--bg-light); display: flex; min-height: 100vh; }

        /* --- SIDEBAR --- */
        aside { width: var(--sidebar-width); background-color: var(--navy-dark); color: var(--white); display: flex; flex-direction: column; position: fixed; height: 100vh; z-index: 100; }
        .sidebar-header { padding: 30px 20px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .sidebar-logo { width: 80px; height: auto; object-fit: contain; margin-bottom: 10px; }
        .sidebar-header h2 { font-size: 16px; color: var(--brand-yellow); text-transform: uppercase; letter-spacing: 1px; font-weight: 800;}
        
        /* --- MODERNIZED SIDEBAR NAVIGATION --- */
        .nav-links { flex-grow: 1; padding: 20px 15px; display: flex; flex-direction: column; gap: 5px; }
        .nav-item {
            display: flex; align-items: center; padding: 14px 20px; color: #94a3b8; 
            text-decoration: none; transition: all 0.3s ease; font-size: 14px;
            font-weight: 500; border-radius: 10px; position: relative;
        }
        .nav-item i { width: 32px; font-size: 18px; transition: transform 0.3s;}
        .nav-item:hover { color: var(--white); background-color: rgba(255, 255, 255, 0.05); transform: translateX(4px); }
        .nav-item.active { color: var(--brand-yellow); background-color: rgba(255, 204, 0, 0.08); font-weight: 700; }
        .nav-item.active::before {
            content: ''; position: absolute; left: -15px; top: 15%; height: 70%; width: 5px; 
            background-color: var(--brand-yellow); border-radius: 0 5px 5px 0; box-shadow: 2px 0 8px rgba(255, 204, 0, 0.5); 
        }

        /* --- MAIN CONTENT & HEADER --- */
        main { margin-left: var(--sidebar-width); flex-grow: 1; display: flex; flex-direction: column; min-height: 100vh; }
        .top-bar { background-color: var(--white); height: 70px; padding: 0 40px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 10px rgba(0,0,0,0.08); position: sticky; top: 0; z-index: 1000; }
        .breadcrumb { font-weight: 700; color: var(--navy-dark); font-size: 15px; display: flex; align-items: center; gap: 8px; }

        /* Fixed Admin Tag */
        .admin-tag { background: var(--navy-dark); color: var(--brand-yellow); padding: 6px 16px; border-radius: 50px; font-size: 10px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; border: 1px solid var(--brand-yellow); }

        /* --- NOTIFICATION STYLES --- */
        .top-right-actions { display: flex; align-items: center; gap: 20px; }
        .notif-wrapper { position: relative; display: inline-flex; align-items: center; cursor: pointer; }
        .notif-badge { position: absolute; top: -5px; right: -8px; background: #e11d48; color: white; border-radius: 50%; padding: 2px 6px; font-size: 10px; font-weight: bold; }
        .notif-dropdown { display: none; position: absolute; right: 0; top: 35px; width: 320px; background: white; border: 1px solid #e2e8f0; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); border-radius: 8px; z-index: 1000; text-align: left; }
        .notif-dropdown.show { display: block; }
        .notif-header { padding: 12px 15px; border-bottom: 1px solid #e2e8f0; font-weight: 700; font-size: 14px; display: flex; justify-content: space-between; align-items: center; color: #001f3f; }
        .notif-body { max-height: 300px; overflow-y: auto; }
        .notif-item { padding: 12px 15px; border-bottom: 1px solid #f1f5f9; font-size: 13px; color: #334155; line-height: 1.4; }
        .notif-item:last-child { border-bottom: none; }
        .notif-empty { padding: 20px; text-align: center; color: #94a3b8; font-size: 13px; }
        .mark-read-btn { font-size: 11px; color: #3b82f6; text-decoration: none; font-weight: 600; }
        .mark-read-btn:hover { text-decoration: underline; }

        /* --- PROFILE DROPDOWN & AVATAR --- */
        .profile-wrapper { position: relative; display: inline-flex; align-items: center; gap: 12px; border-left: 1px solid #e2e8f0; padding-left: 20px; cursor: pointer; user-select: none; }
        .top-avatar { width: 35px; height: 35px; border-radius: 50%; object-fit: cover; border: 2px solid var(--navy-dark); }
        .top-avatar-fallback { width: 35px; height: 35px; border-radius: 50%; background: linear-gradient(135deg, var(--navy-dark), #003366); color: var(--brand-yellow); display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 14px; border: 2px solid var(--brand-yellow); box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        
        .profile-dropdown { display: none; position: absolute; right: 0; top: 45px; width: 200px; background: white; border: 1px solid #e2e8f0; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); border-radius: 8px; z-index: 1000; overflow: hidden; text-align: left; }
        .profile-dropdown.show { display: block; }
        .profile-item { padding: 12px 15px; display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--text-main); text-decoration: none; transition: background 0.2s; }
        .profile-item:hover { background: #f1f5f9; color: var(--navy-dark); }
        .profile-item i { width: 16px; text-align: center; }
        .profile-item.logout-text { color: #e11d48; border-top: 1px solid #f1f5f9; }
        .profile-item.logout-text:hover { background: #fff1f2; color: #be123c; }

        /* --- PAGE CONTENT --- */
        .container { padding: 40px; flex-grow: 1; }
        .page-header { margin-bottom: 25px; }
        .page-header h1 { font-size: 26px; color: var(--navy-dark); font-weight: 800;}
        .page-header p { color: #64748b; margin-top: 5px; font-weight: 500;}

        /* --- SUMMARY CARDS --- */
        .status-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px; }
        .status-card { padding: 25px; border-radius: 16px; color: white; display: flex; flex-direction: column; gap: 10px; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; position: relative; border-left: 4px solid var(--navy-dark);}
        .status-card:hover { transform: translateY(-4px); box-shadow: 0 10px 20px rgba(0,0,0,0.15); border-left-color: var(--brand-yellow);}
        .status-card.total { background: linear-gradient(135deg, var(--navy-dark), #003366); }
        .status-card.male { background: linear-gradient(135deg, #3b82f6, #1d4ed8); }
        .status-card.female { background: linear-gradient(135deg, #f43f5e, #e11d48); }
        .status-card .label { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; opacity: 0.9;}
        .status-card .count { font-size: 32px; font-weight: 800; }
        .status-card i { position: absolute; right: 25px; top: 25px; font-size: 30px; opacity: 0.2;}

        /* --- SEARCH & FILTERS --- */
        .filter-container { background: var(--white); padding: 15px 25px; border-radius: 12px; display: flex; align-items: center; gap: 15px; box-shadow: 0 4px 6px rgba(0,0,0,0.03); margin-bottom: 25px; border: 1px solid transparent; justify-content: space-between; flex-wrap: wrap; }
        .filter-group { display: flex; align-items: center; gap: 15px; flex-grow: 1; min-width: 300px; }
        .filter-container i.fa-search { color: #94a3b8; }
        .search-input { border: none; outline: none; flex-grow: 1; font-size: 14px; color: #1e293b; background: transparent; font-family: 'Poppins', sans-serif;}
        .breed-select { padding: 8px 15px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 13px; color: #4a5568; outline: none; background: #fff; cursor: pointer; font-family: 'Poppins', sans-serif;}
        .result-count { font-size: 12px; color: #94a3b8; font-weight: 600; }

        /* ADD PET BUTTON */
        .btn-add { background: var(--brand-yellow); color: var(--navy-dark); border: none; padding: 10px 20px; border-radius: 8px; font-size: 14px; font-weight: 700; cursor: pointer; transition: 0.3s; display: flex; align-items: center; gap: 8px; white-space: nowrap; }
        .btn-add:hover { transform: translateY(-2px); box-shadow: 0 4px 10px rgba(255, 204, 0, 0.4); }

        /* --- MODAL STYLES --- */
        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal-content { background-color: var(--white); padding: 30px; border-radius: 16px; width: 100%; max-width: 500px; box-shadow: 0 15px 30px rgba(0,0,0,0.2); position: relative; max-height: 90vh; overflow-y: auto; }
        .close-btn { position: absolute; top: 20px; right: 25px; font-size: 24px; cursor: pointer; color: var(--text-muted); transition: 0.2s; }
        .close-btn:hover { color: #e11d48; }
        .modal-content h2 { margin-bottom: 20px; color: var(--navy-dark); font-size: 22px; font-weight: 700; }
        
        .form-group { margin-bottom: 15px; text-align: left;}
        .form-row { display: flex; gap: 15px; }
        .form-row .form-group { flex: 1; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: var(--text-main); }
        .form-group input, .form-group select { width: 100%; padding: 10px 15px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 14px; outline: none; background: #f9fafb; font-family: 'Poppins', sans-serif;}
        .form-group input:focus, .form-group select:focus { border-color: var(--brand-yellow); background: var(--white);}
        
        .btn-submit-modal { width: 100%; background: var(--navy-dark); color: var(--brand-yellow); border: none; padding: 12px; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; transition: 0.3s; margin-top: 10px;}
        .btn-submit-modal:hover { background: #003366; }

        /* --- TABLE STYLING --- */
        .pet-table { width: 100%; border-collapse: collapse; background: var(--white); border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.03); }
        .pet-table th { background: #f8fafc; padding: 15px 20px; font-size: 12px; text-transform: uppercase; color: #64748b; font-weight: 700; text-align: left; border-bottom: 2px solid #edf2f7;}
        .pet-table td { padding: 15px 20px; font-size: 14px; color: #2d3436; border-bottom: 1px solid #edf2f7; vertical-align: middle; }
        .pet-table tr:hover { background-color: #f8fafc; }

        .gender-badge { font-size: 12px; padding: 4px 10px; border-radius: 20px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
        .gender-male { color: #3b82f6; background: #eff6ff; }
        .gender-female { color: #f43f5e; background: #fff1f2; }

        .empty-state { background: var(--white); border-radius: 16px; padding: 100px 0; text-align: center; color: #94a3b8; box-shadow: 0 4px 6px rgba(0,0,0,0.03); border: 1px dashed #e2e8f0; }
        .empty-state i { font-size: 60px; margin-bottom: 20px; opacity: 0.2; }

        footer { text-align: center; padding: 40px; color: var(--text-muted); font-size: 12px; background: transparent; border-top: 1px solid rgba(0,0,0,0.05); }

        /* Action link hover effects */
        .action-link { transition: color 0.2s; display: inline-block; color: #94a3b8; font-size: 16px; margin-left: 10px;}
        .action-link:hover { color: var(--brand-yellow) !important; opacity: 1; }
        .btn-view { color: var(--navy-dark); }
        .btn-view:hover { color: var(--brand-yellow); }
    </style>
</head>
<body>

    <aside>
        <div class="sidebar-header">
            <img src="bg.png" alt="Boogie's Logo" class="sidebar-logo">
            <h2>
                <?php 
                    echo (isset($_SESSION['role']) && strtolower($_SESSION['role']) === 'admin') ? "Boogie's Admin" : "Boogie's Staff"; 
                ?>
            </h2>
        </div>
        <nav class="nav-links">
            <a href="admindashboard.php" class="nav-item"><i class="fas fa-chart-line"></i> Dashboard</a>
            <a href="managebooking.php" class="nav-item"><i class="fas fa-calendar-alt"></i> Bookings</a>
            <a href="manageusers.php" class="nav-item"><i class="fas fa-users"></i> Users</a>
            <a href="managepet.php" class="nav-item active"><i class="fas fa-dog"></i> Pets</a>

            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                <a href="managestaff.php" class="nav-item"><i class="fas fa-id-badge"></i> Personnel</a>
                <a href="managepromo.php" class="nav-item"><i class="fas fa-tags"></i> Promos</a>
                <a href="manage_services.php" class="nav-item"><i class="fas fa-list-ul"></i> Pricelist</a>
                <a href="sales_report.php" class="nav-item">
                    <i class="fas fa-file-invoice-dollar"></i>
                    Sales Report
                </a>

                <!-- ACCOUNT LOGS: ADMIN ONLY -->
                <a href="admin_account_logs.php" class="nav-item">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                    <span>Account Logs</span>
                </a>
            <?php endif; ?>
        </nav>
    </aside>

    <main>
        <header class="top-bar">
            <div class="breadcrumb">
                <i class="fas fa-dog" style="opacity: 0.5; font-size: 14px;"></i> 
                Management / Pets
            </div>
            
            <div class="top-right-actions">
                <div class="notif-wrapper" onclick="toggleNotif(event)">
                    <i class="fa-solid fa-bell" style="font-size: 22px; color: #64748b;"></i>
                    
                    <span id="admin-notif-badge" class="notif-badge" style="display: <?php echo ($unread_count > 0) ? 'inline-block' : 'none'; ?>;">
                        <?php echo $unread_count; ?>
                    </span>
                    
                    <div class="notif-dropdown" id="notifBox" onclick="event.stopPropagation()">
                        <div class="notif-header">
                            Alerts
                            <a href="mark_notifications_read.php" id="mark-read-link" class="mark-read-btn" style="display: <?php echo ($unread_count > 0) ? 'inline-block' : 'none'; ?>;">Mark all read</a>
                        </div>
                        
                        <div class="notif-body" id="admin-notif-list">
                            <?php if($unread_count > 0): ?>
                                <?php while($notif = mysqli_fetch_assoc($admin_notif_query)): ?>
                                    <div class="notif-item">
                                        <i class="fa-solid fa-circle-exclamation" style="color: #e11d48; margin-right: 5px;"></i>
                                        <?php echo htmlspecialchars($notif['message']); ?>
                                        <br><small style="color: #94a3b8; font-size: 11px;"><?php echo date('M d, g:i A', strtotime($notif['created_at'])); ?></small>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="notif-empty">No new alerts.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="profile-wrapper" onclick="toggleProfile(event)">
                    <span class="admin-tag"><?php echo strtoupper($current_role); ?></span>
                    
                    <?php if (!empty($profile_img_path) && file_exists($profile_img_path)): ?>
                        <img src="<?php echo htmlspecialchars($profile_img_path); ?>" class="top-avatar" alt="Profile Picture">
                    <?php else: ?>
                        <div class="top-avatar-fallback"><?php echo strtoupper(substr($first_name, 0, 1)); ?></div>
                    <?php endif; ?>
                    
                    <span style="font-size: 14px; font-weight: 600; color: #4a5568; display: flex; align-items: center; gap: 6px;">
                        <?php echo htmlspecialchars($admin_full_name); ?>
                        <i class="fas fa-chevron-down" style="font-size: 10px; color: #94a3b8;"></i>
                    </span>

                    <div class="profile-dropdown" id="profileBox" onclick="event.stopPropagation()">
                        <a href="admin_profile.php" class="profile-item">
                            <i class="fas fa-user-circle"></i> My Profile
                        </a>
                        <a href="../logout.php" class="profile-item logout-text">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <div class="container">
            <div class="page-header">
                <h1>Pet Profiles</h1>
                <p>View and manage all registered pet profiles and their medical history.</p>
            </div>

            <?php if(!empty($success_msg)): ?>
                <div style="background: #dcfce7; color: #166534; padding: 12px 20px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #bbf7d0; font-size: 14px; font-weight: 500;">
                    <i class="fas fa-check-circle"></i> <?php echo $success_msg; ?>
                </div>
            <?php endif; ?>
            <?php if(!empty($error_msg)): ?>
                <div style="background: #fee2e2; color: #991b1b; padding: 12px 20px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #fecaca; font-size: 14px; font-weight: 500;">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error_msg; ?>
                </div>
            <?php endif; ?>

            <div class="status-grid">
                <div class="status-card total" onclick="window.location.href='managepet.php'">
                    <i class="fas fa-paw"></i>
                    <span class="label">Total Pets</span>
                    <div class="count"><?php echo $total_pets; ?></div>
                </div>
                <div class="status-card male" onclick="window.location.href='managepet.php?gender=Male'">
                    <i class="fas fa-mars"></i>
                    <span class="label">Male Pets</span>
                    <div class="count"><?php echo $male_pets; ?></div>
                </div>
                <div class="status-card female" onclick="window.location.href='managepet.php?gender=Female'">
                    <i class="fas fa-venus"></i>
                    <span class="label">Female Pets</span>
                    <div class="count"><?php echo $female_pets; ?></div>
                </div>
            </div>

            <div class="filter-container">
                <div class="filter-group">
                    <i class="fas fa-search"></i>
                    <input type="text" id="petSearch" class="search-input" placeholder="Search by pet name, breed, or owner ID...">
                    <select id="breedFilter" class="breed-select">
                        <option value="">All Breeds</option>
                        <?php if ($breeds_query): ?>
                            <?php while($breed = mysqli_fetch_assoc($breeds_query)): ?>
                                <option value="<?php echo htmlspecialchars($breed['breed']); ?>">
                                    <?php echo htmlspecialchars($breed['breed']); ?>
                                </option>
                            <?php endwhile; ?>
                            <?php mysqli_data_seek($breeds_query, 0); ?>
                        <?php endif; ?>
                    </select>
                    <span class="result-count" id="showingCountText">Showing <?php echo $showing_count; ?> of <?php echo $showing_count; ?> pets</span>
                </div>
                <div class="filter-actions">
                    <button class="btn-add" onclick="openPetModal()"><i class="fas fa-plus"></i> Add New Pet</button>
                </div>
            </div>

            <?php if ($showing_count > 0): ?>
                <table class="pet-table" id="petsTable">
                    <thead>
                        <tr>
                            <th>Pet Name</th>
                            <th>Breed</th>
                            <th>Gender</th>
                            <th>Owner ID</th>
                            <th style="text-align: center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($pet = mysqli_fetch_assoc($pets_list)): ?>
                        <tr class="pet-row" data-breed="<?php echo htmlspecialchars($pet['breed']); ?>">
                            <td>
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <div style="width: 35px; height: 35px; background: #f1f5f9; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: var(--navy-dark);">
                                        <i class="fas fa-paw"></i>
                                    </div>
                                    <div style="font-weight: 700; color: var(--navy-dark);"><?php echo htmlspecialchars($pet['name']); ?></div>
                                </div>
                            </td>
                            <td style="color: var(--text-muted); font-weight: 500;"><?php echo htmlspecialchars($pet['breed']); ?></td>
                            <td>
                                <?php if($pet['gender'] == 'Male'): ?>
                                    <span class="gender-badge gender-male"><i class="fas fa-mars"></i> Male</span>
                                <?php else: ?>
                                    <span class="gender-badge gender-female"><i class="fas fa-venus"></i> Female</span>
                                <?php endif; ?>
                            </td>
                            <td style="color: var(--text-muted); font-weight: 600;">#<?php echo $pet['owner_id']; ?></td>
                            
                            <td style="text-align: center;">
                                <a href="view_records.php?id=<?php echo $pet['id']; ?>" class="action-link btn-view" title="View Records">
                                    <i class="fas fa-file-medical"></i>
                                </a>
                                
                                <a href="edit.php?id=<?php echo $pet['id']; ?>" class="action-link" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-dog"></i>
                    <p style="font-weight: 500;">No pet profiles found for this view.</p>
                </div>
            <?php endif; ?>
        </div>

        <footer>
            © <?php echo date("Y"); ?> BOOGIE'S PET CARE & SERVICES - DASMARIÑAS BRANCH
        </footer>
    </main>

    <div id="addPetModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="closePetModal()">&times;</span>
            <h2><i class="fas fa-paw" style="color: var(--brand-yellow);"></i> Register New Pet</h2>
            <form method="POST" action="">
                
                <div class="form-group">
                    <label>Select Owner (Customer) *</label>
                    <select name="owner_id" required>
                        <option value="">-- Search / Choose Customer --</option>
                        <?php 
                        if ($users_list_query) {
                            while($user = mysqli_fetch_assoc($users_list_query)) {
                                echo "<option value='".$user['id']."'>ID: ".$user['id']." - ".htmlspecialchars($user['full_name'])."</option>";
                            }
                        }
                        ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Pet's Name *</label>
                    <input type="text" name="pet_name" placeholder="e.g. Pochi" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Pet Type *</label>
                        <select name="pet_type" required>
                            <option value="">Select Type</option>
                            <option value="Dog">Dog</option>
                            <option value="Cat">Cat</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Gender *</label>
                        <select name="pet_gender" required>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Breed</label>
                        <input type="text" name="pet_breed" placeholder="e.g. Shih Tzu">
                    </div>
                    <div class="form-group">
                        <label>Size / Weight *</label>
                        <select name="pet_weight" required>
                            <option value="">Select Size</option>
                            <option value="Small (1-5kg)">Small (1-5kg)</option>
                            <option value="Medium (6-10kg)">Medium (6-10kg)</option>
                            <option value="Large (11-15kg)">Large (11-15kg)</option>
                            <option value="Extra Large (16-20kg)">Extra Large (16-20kg)</option>
                            <option value="XXL Large (21-25kg)">XXL Large (21-25kg)</option>
                        </select>
                    </div>
                </div>

                <button type="submit" name="add_pet" class="btn-submit-modal">Register Pet</button>
            </form>
        </div>
    </div>

    <script>
    // --- MODAL LOGIC ---
    function openPetModal() {
        document.getElementById('addPetModal').style.display = 'flex';
    }

    function closePetModal() {
        document.getElementById('addPetModal').style.display = 'none';
    }

    // Close modal if clicked outside the content box
    window.onclick = function(event) {
        const modal = document.getElementById('addPetModal');
        if (event.target == modal) {
            modal.style.display = "none";
        }
        
        // existing notification logic
        if (!event.target.closest('.notif-wrapper')) {
            const notifBox = document.getElementById("notifBox");
            if (notifBox && notifBox.classList.contains('show')) {
                notifBox.classList.remove('show');
            }
        }
        if (!event.target.closest('.profile-wrapper')) {
            const profileBox = document.getElementById("profileBox");
            if (profileBox && profileBox.classList.contains('show')) {
                profileBox.classList.remove('show');
            }
        }
    }

    // --- Notification & Profile Toggle Logic ---
    function toggleNotif(event) {
        event.stopPropagation();
        document.getElementById("notifBox").classList.toggle("show");
        document.getElementById("profileBox").classList.remove("show");
    }

    function toggleProfile(event) {
        event.stopPropagation();
        document.getElementById("profileBox").classList.toggle("show");
        document.getElementById("notifBox").classList.remove("show");
    }

    // --- REAL-TIME NOTIFICATION FETCHER ---
    let previousUnreadCount = <?php echo $unread_count; ?>;
    
    function fetchAdminNotifs() {
        fetch('get_admin_notifs.php')
            .then(response => response.json())
            .then(data => {
                const badge = document.getElementById('admin-notif-badge');
                const notifList = document.getElementById('admin-notif-list');
                const markReadBtn = document.getElementById('mark-read-link');
                
                if (data.unread > 0) {
                    badge.style.display = 'inline-block';
                    badge.innerText = data.unread;
                    if(markReadBtn) markReadBtn.style.display = 'inline-block';
                } else {
                    badge.style.display = 'none';
                    if(markReadBtn) markReadBtn.style.display = 'none';
                }

                if (data.html !== "") {
                    notifList.innerHTML = data.html;
                } else {
                    notifList.innerHTML = '<div class="notif-empty">No new notifications.</div>';
                }
            })
            .catch(error => console.error('Error fetching admin notifications:', error));
    }

    setInterval(fetchAdminNotifs, 3000);

    // --- Pet Filter Logic ---
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('petSearch');
        const breedFilter = document.getElementById('breedFilter');
        const tableRows = document.querySelectorAll('.pet-row');
        const countText = document.getElementById('showingCountText');
        
        const baseFilteredTotal = <?php echo $showing_count; ?>;

        function performFilter() {
            const searchTerm = searchInput.value.toLowerCase();
            const selectedBreed = breedFilter.value.toLowerCase();
            let visibleCount = 0;

            tableRows.forEach(row => {
                const petName = row.querySelector('td:first-child').innerText.toLowerCase();
                const ownerId = row.querySelector('td:nth-child(4)').innerText.toLowerCase();
                const breed = row.getAttribute('data-breed').toLowerCase();

                const matchesSearch = petName.includes(searchTerm) || ownerId.includes(searchTerm);
                const matchesBreed = selectedBreed === "" || breed === selectedBreed;

                if (matchesSearch && matchesBreed) {
                    row.style.display = "";
                    visibleCount++;
                } else {
                    row.style.display = "none";
                }
            });

            countText.textContent = `Showing ${visibleCount} of ${baseFilteredTotal} pets`;
        }

        searchInput.addEventListener('input', performFilter);
        breedFilter.addEventListener('change', performFilter);
    });
    </script>
</body>
</html>