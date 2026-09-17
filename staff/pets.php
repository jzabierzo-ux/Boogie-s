<?php
session_start();
include '../db_connect.php'; 

// --- SECURITY CHECK (FIXED PARA SA VET/STAFF) ---
$is_admin_or_supervisor = isset($_SESSION['logged_in']) && in_array(strtolower(trim($_SESSION['role'] ?? '')), ['admin', 'supervisor', 'staff']);
$is_staff = isset($_SESSION['staff_logged_in']) && $_SESSION['staff_logged_in'] === true;

if (!$is_admin_or_supervisor && !$is_staff) {
    header("Location: stafflogin.php");
    exit();
}

// SET CORRECT TIMEZONE FOR PHILIPPINES
date_default_timezone_set('Asia/Manila');

$staff_name = $_SESSION['staff_name'] ?? 'Doctor';

// --- FETCH STAFF PROFILE IMAGE & FULL NAME ---
$profile_img_path = "";
$full_display_name = $staff_name;

if (isset($_SESSION['user_id']) || isset($_SESSION['staff_id'])) {
    $uid = $_SESSION['user_id'] ?? $_SESSION['staff_id'];
    
    // FIX: Idinagdag ang 'full_name' sa query para makuha ang buong pangalan
    $get_staff = mysqli_query($conn, "SELECT full_name, profile_image FROM users WHERE id = '$uid'");
    
    if($get_staff && $staff_data = mysqli_fetch_assoc($get_staff)) {
        $profile_img_path = $staff_data['profile_image']; 
        if (!empty($staff_data['full_name'])) {
            $full_display_name = $staff_data['full_name'];
        }
    }
}

// Linisin ang pangalan para sa Avatar Initial (Tatanggalin ang "Dr. " at comma)
$clean_name = trim(str_replace('Dr. ', '', $full_display_name), " ,"); 
$first_letter = strtoupper(substr($clean_name, 0, 1)); 

// Siguraduhing may "Dr. " na nakadikit sa buong pangalan para formal
$display_with_title = (stripos($full_display_name, 'Dr.') === false) ? 'Dr. ' . $full_display_name : $full_display_name;

// --- FETCH NOTIFICATIONS ---
$admin_notif_query = mysqli_query($conn, "SELECT * FROM admin_notifications WHERE is_read = 0 ORDER BY created_at DESC");
$unread_count = ($admin_notif_query) ? mysqli_num_rows($admin_notif_query) : 0;

// --- STATS LOGIC ---
$total_res = mysqli_query($conn, "SELECT COUNT(*) as total FROM pets");
$total_pets = ($total_res) ? mysqli_fetch_assoc($total_res)['total'] : 0;

// These now use the 'pet_type' column from your database
$dog_res = mysqli_query($conn, "SELECT COUNT(*) as total FROM pets WHERE pet_type = 'Dog'");
$dogs = ($dog_res) ? mysqli_fetch_assoc($dog_res)['total'] : 0;

$cat_res = mysqli_query($conn, "SELECT COUNT(*) as total FROM pets WHERE pet_type = 'Cat'");
$cats = ($cat_res) ? mysqli_fetch_assoc($cat_res)['total'] : 0;

$others = $total_pets - ($dogs + $cats);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patients Directory | Boogie's Pet Care</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --brand-blue: #1d63ff;
            --brand-yellow: #ffcc00;
            --sidebar-navy: #001529; 
            --bg-light: #f4f7fe;
            --white: #ffffff;
            --text-main: #2d3748;
            --text-muted: #64748b;
            --border: #e2e8f0;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif;}
        body { background: var(--bg-light); color: var(--text-main); display: flex; min-height: 100vh;}

        /* --- SIDEBAR --- */
        .sidebar { width: 260px; background: var(--sidebar-navy); height: 100vh; position: fixed; color: white; display: flex; flex-direction: column; z-index: 100;}
        .sidebar-header { padding: 30px 20px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .sidebar-logo-img { width: 70px; height: auto; object-fit: contain; margin-bottom: 10px; }
        .sidebar-header h2 { font-size: 15px; color: var(--brand-yellow); text-transform: uppercase; letter-spacing: 1px; font-weight: 800; }
        
        /* --- EXACT ADMIN SIDEBAR CLONE CSS (YELLOW ACTIVE) --- */
        .nav-links { 
            flex-grow: 1; padding: 20px 15px; display: flex; flex-direction: column; gap: 5px; 
        }
        .nav-item {
            display: flex; align-items: center; padding: 14px 20px; color: #94a3b8; 
            text-decoration: none; transition: all 0.3s ease; font-size: 14px;
            font-weight: 500; border-radius: 10px; position: relative;
        }
        .nav-item i { width: 32px; font-size: 18px; transition: transform 0.3s; text-align: center; }
        .nav-item:hover { 
            color: var(--white); background-color: rgba(255, 255, 255, 0.05); 
            transform: translateX(4px); 
        }
        .nav-item.active { 
            color: var(--brand-yellow); 
            background-color: rgba(255, 204, 0, 0.08); 
            font-weight: 700; 
        }
        .nav-item.active::before {
            content: ''; position: absolute; left: -15px; top: 15%; height: 70%; width: 5px; 
            background-color: var(--brand-yellow); border-radius: 0 5px 5px 0; 
            box-shadow: 2px 0 8px rgba(255, 204, 0, 0.5); 
        }

        /* --- MAIN CONTENT & HEADER --- */
        .main-content { margin-left: 260px; width: calc(100% - 260px); min-height: 100vh; display: flex; flex-direction: column;}
        header { background: var(--white); height: 70px; padding: 0 40px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 1000; box-shadow: 0 1px 10px rgba(0,0,0,0.02);}
        .breadcrumb { font-size: 14px; font-weight: 700; color: var(--sidebar-navy); display: flex; align-items: center; gap: 8px; }
        
        /* --- NOTIFICATION STYLES --- */
        .top-right-actions { display: flex; align-items: center; gap: 20px; }
        .notif-wrapper { position: relative; cursor: pointer; }
        .notif-badge { position: absolute; top: -5px; right: -8px; background: #ef4444; color: white; border-radius: 50%; padding: 2px 6px; font-size: 10px; font-weight: 800; }
        .notif-dropdown { display: none; position: absolute; right: 0; top: 35px; width: 320px; background: white; border: 1px solid var(--border); box-shadow: 0 10px 25px rgba(0,0,0,0.1); border-radius: 12px; z-index: 1000; overflow: hidden; }
        .notif-dropdown.show { display: block; }
        .notif-header { padding: 12px 15px; border-bottom: 1px solid var(--border); font-weight: 700; font-size: 13px; display: flex; justify-content: space-between; align-items: center; background: #f8fafc; }
        .notif-item { padding: 12px 15px; border-bottom: 1px solid #f1f5f9; font-size: 13px; line-height: 1.4; color: #475569; }
        .notif-item:hover { background: #f8fafc; }

        /* --- PINAGANDANG ROLE TAG AT PROFILE --- */
        .role-label {
            display: flex; align-items: center; gap: 6px; background: rgba(255, 204, 0, 0.15); 
            color: #d97706; padding: 4px 12px; border-radius: 6px; font-size: 11px;
            font-weight: 800; letter-spacing: 0.5px; border: 1px solid rgba(255, 204, 0, 0.3);
            text-transform: uppercase;
        }
        .profile-wrapper { position: relative; display: flex; align-items: center; gap: 15px; border-left: 1px solid var(--border); padding-left: 20px; cursor: pointer; user-select: none; }
        .top-avatar { width: 35px; height: 35px; border-radius: 50%; object-fit: cover; border: 2px solid var(--brand-blue); }
        .top-avatar-fallback { width: 35px; height: 35px; border-radius: 50%; background: var(--sidebar-navy); color: var(--brand-yellow); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 14px; border: 2px solid var(--brand-yellow); box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        
        .profile-dropdown { display: none; position: absolute; right: 40px; top: 60px; width: 200px; background: white; border: 1px solid var(--border); box-shadow: 0 10px 25px rgba(0,0,0,0.1); border-radius: 12px; z-index: 1000; overflow: hidden; text-align: left; }
        .profile-dropdown.show { display: block; }
        .profile-item { padding: 12px 15px; display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--text-main); text-decoration: none; transition: 0.2s; font-weight: 600;}
        .profile-item:hover { background: #f8fafc; color: var(--brand-blue); }

        .container { padding: 35px 40px; flex-grow: 1; }

        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px; }
        
        .stat-card { 
            background: var(--white); 
            padding: 25px; 
            border-radius: 16px; 
            border: 1px solid transparent; 
            cursor: pointer; 
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            box-shadow: 0 4px 6px rgba(0,0,0,0.02);
            position: relative;
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.08);
            border-color: #e2e8f0;
        }
        
        .stat-card h3 { font-size: 12px; color: var(--text-muted); text-transform: uppercase; margin-bottom: 8px; font-weight: 700; letter-spacing: 0.5px;}
        .stat-card p { font-size: 32px; font-weight: 800; color: var(--sidebar-navy); }
        
        .stat-card i { position: absolute; right: 25px; top: 25px; font-size: 30px; opacity: 0.1;}
        
        .c-total { border-left: 4px solid var(--sidebar-navy); }
        .c-total i { color: var(--sidebar-navy); }
        
        .c-dog { border-left: 4px solid #3b82f6; }
        .c-dog i { color: #3b82f6; }
        
        .c-cat { border-left: 4px solid #f43f5e; }
        .c-cat i { color: #f43f5e; }

        .card { background: var(--white); border-radius: 20px; border: 1px solid var(--border); overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.02); margin-bottom: 20px;}
        .table-controls { padding: 20px 25px; border-bottom: 1px solid #f8faff; display: flex; align-items: center; justify-content: space-between; background: #f8fafc;}
        
        .search-wrapper { position: relative; max-width: 400px; width: 100%; }
        .search-wrapper input { width: 100%; padding: 10px 15px 10px 40px; border-radius: 10px; border: 1px solid var(--border); font-family: 'Poppins', sans-serif; font-size: 13px; outline: none; transition: 0.2s;}
        .search-wrapper input:focus { border-color: var(--brand-blue); box-shadow: 0 0 0 3px rgba(29,99,255,0.1); }
        .search-wrapper i { position: absolute; left: 15px; top: 12px; color: #a0aec0; }

        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 700px;}
        thead { background: var(--white); border-bottom: 2px solid var(--border); }
        th { padding: 15px 25px; text-align: left; font-size: 12px; font-weight: 700; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px;}
        td { padding: 18px 25px; border-bottom: 1px solid #f8fafc; font-size: 14px; font-weight: 500;}
        tr:hover td { background-color: #fafcfe; }

        .owner-tag { color: var(--sidebar-navy); font-weight: 700; background: #f1f5f9; padding: 4px 10px; border-radius: 6px; font-size: 11px; letter-spacing: 0.5px;}
        
        .action-links { display: flex; gap: 8px; align-items: center; }
        .btn-icon { width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; text-decoration: none; transition: 0.2s; font-size: 14px; }
        .btn-icon:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        
        .btn-view { background: #e0f2fe; color: #0369a1; }
        .btn-view:hover { background: #0284c7; color: white; }
        
        .btn-delete { background: #fee2e2; color: #b91c1c; }
        .btn-delete:hover { background: #ef4444; color: white; }

        footer { text-align: center; padding: 30px; color: var(--text-muted); font-size: 12px; margin-top: auto; border-top: 1px solid var(--border);}
    </style>
</head>
<body>

    <aside class="sidebar">
        <div class="sidebar-header">
            <img src="../bg.png" alt="Boogie's Logo" class="sidebar-logo-img">
            <h2>Boogie's Clinic</h2>
        </div>
        <nav class="nav-links">
            <a href="staffdashboard.php" class="nav-item"><i class="fas fa-th-large"></i> Dashboard</a>
            <a href="appointments.php" class="nav-item"><i class="fas fa-calendar-alt"></i> Appointments</a>
            <a href="pets.php" class="nav-item active"><i class="fas fa-paw"></i> Patients</a>
            <a href="tasks.php" class="nav-item"><i class="fas fa-tasks"></i> My Tasks</a>
        </nav>
    </aside>

    <main class="main-content">
        <header>
            <div class="breadcrumb">
                <i class="fas fa-paw" style="color: var(--brand-blue);"></i> 
                Veterinarian Portal / Patients Directory
            </div>
            
            <div class="top-right-actions">
                <div class="notif-wrapper" onclick="toggleNotif(event)">
                    <i class="fa-solid fa-bell" style="font-size: 20px; color: var(--text-muted);"></i>
                    
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
                                        <i class="fa-solid fa-circle-exclamation" style="color: #ef4444; margin-right: 5px;"></i>
                                        <?php echo htmlspecialchars($notif['message']); ?>
                                        <br><small style="color: #94a3b8; font-size: 11px;"><?php echo date('M d, g:i A', strtotime($notif['created_at'])); ?></small>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="notif-empty">No new clinic alerts.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="profile-wrapper" onclick="toggleProfile(event)">
                    <div class="role-label">
                        <i class="fas fa-user-md"></i> VET
                    </div>
                    
                    <?php if (!empty($profile_img_path) && file_exists($profile_img_path)): ?>
                        <img src="<?php echo htmlspecialchars($profile_img_path); ?>" class="top-avatar" alt="Profile Picture">
                    <?php else: ?>
                        <div class="top-avatar-fallback"><?php echo $first_letter; ?></div>
                    <?php endif; ?>
                    
                    <span style="font-size: 14px; font-weight: 700; color: var(--sidebar-navy); display: flex; align-items: center; gap: 6px;">
                        <?php echo htmlspecialchars($display_with_title); ?>
                        <i class="fas fa-chevron-down" style="font-size: 10px; color: var(--text-muted); opacity: 0.5;"></i>
                    </span>

                    <div class="profile-dropdown" id="profileBox" onclick="event.stopPropagation()">
                        <a href="staff_profile.php" class="profile-item">
                            <i class="fas fa-user-circle"></i> My Profile
                        </a>
                        <a href="../logout.php" class="profile-item logout-text" style="color: #ef4444; border-top: 1px solid #f1f5f9;">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <div class="container">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
                <div>
                    <h1 style="font-size: 24px; font-weight: 800; color: var(--sidebar-navy);">Patients Directory</h1>
                    <p style="color: var(--text-muted); font-size: 14px; font-weight: 500;">Browse and manage all registered clinic patients</p>
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card c-total" onclick="window.location.href='pets.php'">
                    <i class="fas fa-paw"></i>
                    <h3>Total Patients</h3>
                    <p><?php echo $total_pets; ?></p>
                </div>
                <div class="stat-card c-dog" onclick="window.location.href='pets.php?type=Dog'">
                    <i class="fas fa-dog"></i>
                    <h3>Dogs</h3>
                    <p><?php echo $dogs; ?></p>
                </div>
                <div class="stat-card c-cat" onclick="window.location.href='pets.php?type=Cat'">
                    <i class="fas fa-cat"></i>
                    <h3>Cats</h3>
                    <p><?php echo $cats; ?></p>
                </div>
            </div>

            <div class="card">
                <div class="table-controls">
                    <div class="search-wrapper">
                        <i class="fas fa-search"></i>
                        <input type="text" id="searchInput" placeholder="Search patients by name, breed, or type...">
                    </div>
                    <?php if(isset($_GET['type'])): ?>
                        <div style="font-size: 13px; font-weight: 700; color: var(--brand-blue); background: #eff6ff; padding: 8px 16px; border-radius: 8px;">
                            Showing: <?php echo htmlspecialchars($_GET['type']); ?>s
                            <a href="pets.php" style="color: #ef4444; margin-left: 10px; text-decoration: none;"><i class="fas fa-times"></i> Clear</a>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Patient Name</th>
                                <th>Type</th>
                                <th>Breed</th>
                                <th>Owner</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // --- UPDATED: TABLE FILTER LOGIC ---
                            $filter_type = $_GET['type'] ?? '';
                            
                            if ($filter_type === 'Dog') {
                                $query = "SELECT * FROM pets WHERE pet_type = 'Dog' ORDER BY id DESC";
                            } elseif ($filter_type === 'Cat') {
                                $query = "SELECT * FROM pets WHERE pet_type = 'Cat' ORDER BY id DESC";
                            } elseif ($filter_type === 'Other') {
                                $query = "SELECT * FROM pets WHERE pet_type NOT IN ('Dog', 'Cat') ORDER BY id DESC";
                            } else {
                                $query = "SELECT * FROM pets ORDER BY id DESC";
                            }

                            $result = mysqli_query($conn, $query);

                            if ($result && mysqli_num_rows($result) > 0) {
                                while($row = mysqli_fetch_assoc($result)) {
                                    $p_name = $row['name'] ?? 'Unnamed';
                                    $p_type = $row['pet_type'] ?? 'Pet';
                                    $p_breed = $row['breed'] ?? 'N/A';
                                    $o_name = $row['owner_name'] ?? 'ID: ' . ($row['owner_id'] ?? 'N/A');
                                    
                                    echo "<tr>";
                                    echo "<td style='font-weight:700; color: var(--sidebar-navy);'><i class='fas fa-paw' style='color:#cbd5e0; margin-right:10px;'></i>" . htmlspecialchars($p_name) . "</td>";
                                    echo "<td>" . htmlspecialchars($p_type) . "</td>";
                                    echo "<td>" . htmlspecialchars($p_breed) . "</td>";
                                    echo "<td><span class='owner-tag'>" . htmlspecialchars($o_name) . "</span></td>";
                                    
                                    echo "<td>
                                            <div class='action-links'>
                                                <a href='view_records.php?id=" . urlencode($row['id']) . "' class='btn-icon btn-view' title='View Medical Records'>
                                                    <i class='fas fa-notes-medical'></i>
                                                </a>
                                                
                                                <a href='delete_pet.php?id=" . urlencode($row['id']) . "' class='btn-icon btn-delete' title='Delete Patient' onclick='return confirm(\"Are you sure you want to permanently delete this patient?\");'>
                                                    <i class='fas fa-trash-alt'></i>
                                                </a>
                                            </div>
                                          </td>";
                                    echo "</tr>";
                                }
                            } else {
                                echo "<tr><td colspan='5' style='text-align:center; padding: 60px 30px; color: var(--text-muted);'>
                                    <i class='fas fa-search' style='font-size:40px; opacity:0.3; margin-bottom:15px; display:block;'></i>
                                    <span style='font-weight:500;'>No patients found for this category.</span>
                                </td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <footer>
            © <?php echo date("Y"); ?> BOOGIE'S PET CARE & SERVICES - DASMARIÑAS BRANCH
        </footer>
    </main>

    <script>
        // --- Search functionality ---
        document.addEventListener("DOMContentLoaded", function() {
            const searchBox = document.getElementById("searchInput");
            const tableRows = document.querySelectorAll("table tbody tr");

            searchBox.addEventListener("keyup", function(e) {
                const searchTerm = e.target.value.toLowerCase();

                tableRows.forEach(function(row) {
                    if (row.cells[0].colSpan === 5) return; // Skip "No patients found" row (updated to 5)

                    const rowText = row.textContent.toLowerCase();

                    if (rowText.includes(searchTerm)) {
                        row.style.display = "";
                    } else {
                        row.style.display = "none";
                    }
                });
            });
        });

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

        // Close dropdowns when clicking outside
        window.onclick = function(event) {
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
                .catch(error => console.error('Error fetching notifications:', error));
        }

        setInterval(fetchAdminNotifs, 3000);
    </script>
</body>
</html>