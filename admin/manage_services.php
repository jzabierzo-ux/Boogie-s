<?php
session_start();

// 1. SECURITY: STRICTLY ADMIN ONLY (Restricted ito sa Supervisor)
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: adminlogin.php");
    exit();
}

// 2. DATABASE CONNECTION
include('../db_connect.php'); 

// 3. FETCH ADMIN PROFILE (Updated with Profile Image Logic)
$admin_full_name = "Administrator";
$profile_img_path = "";
$first_name = "Administrator";

if (isset($_SESSION['user_id'])) {
    $uid = $_SESSION['user_id'];
    $get_admin = mysqli_query($conn, "SELECT full_name, profile_image FROM users WHERE id = '$uid'");
    if($admin_data = mysqli_fetch_assoc($get_admin)) {
        $admin_full_name = $admin_data['full_name'];
        $profile_img_path = $admin_data['profile_image']; 
        $_SESSION['user_name'] = $admin_full_name; 
        
        // Fix para walang comma sa avatar fallback
        $first_name = explode(' ', $admin_full_name)[0];
        $first_name = trim($first_name, ',');
    }
}

// --- FETCH ADMIN NOTIFICATIONS ---
$admin_notif_query = mysqli_query($conn, "SELECT * FROM admin_notifications WHERE is_read = 0 ORDER BY created_at DESC");
$unread_count = mysqli_num_rows($admin_notif_query);

// --- HANDLE DELETE ACTION ---
if (isset($_GET['delete_id'])) {
    $id = intval($_GET['delete_id']);
    mysqli_query($conn, "DELETE FROM services_pricelist WHERE id=$id");
    header("Location: manage_services.php");
    exit();
}

// --- HANDLE ADD / UPDATE ACTION ---
if (isset($_POST['save_service'])) {
    $cat = mysqli_real_escape_string($conn, $_POST['category']);
    $name = mysqli_real_escape_string($conn, $_POST['service_name']);
    $price = mysqli_real_escape_string($conn, $_POST['price']);
    $available = mysqli_real_escape_string($conn, $_POST['is_available']);

    if (!empty($_POST['service_id'])) {
        $id = intval($_POST['service_id']);
        $sql = "UPDATE services_pricelist SET category='$cat', service_name='$name', price='$price', is_available='$available' WHERE id='$id'";
    } else {
        $sql = "INSERT INTO services_pricelist (category, service_name, price, is_available) VALUES ('$cat', '$name', '$price', 1)";
    }
    mysqli_query($conn, $sql);
    header("Location: manage_services.php");
    exit();
}

$query = "SELECT * FROM services_pricelist ORDER BY category ASC";
$result = mysqli_query($conn, $query);
$services = mysqli_fetch_all($result, MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Services | Boogie's Pet Care</title>
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
        main { margin-left: var(--sidebar-width); flex-grow: 1; display: flex; flex-direction: column; }
        .top-bar { background-color: var(--white); height: 70px; padding: 0 40px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 10px rgba(0,0,0,0.08); position: sticky; top: 0; z-index: 1000; }
        .breadcrumb { font-weight: 700; color: var(--navy-dark); font-size: 15px; display: flex; align-items: center; gap: 8px; }

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
        .admin-tag { background: var(--navy-dark); color: var(--brand-yellow); padding: 6px 16px; border-radius: 50px; font-size: 10px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; border: 1px solid var(--brand-yellow); }
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

        /* --- TABLE & CONTENT --- */
        .container { padding: 40px; flex-grow: 1; }
        .data-box { background: var(--white); border-radius: 16px; padding: 30px; box-shadow: 0 4px 6px rgba(0,0,0,0.03); border: 1px solid #f1f5f9;}
        
        .admin-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .admin-table th { text-align: left; padding: 15px; border-bottom: 2px solid #edf2f7; color: var(--text-muted); font-size: 12px; text-transform: uppercase; font-weight: 700;}
        .admin-table td { padding: 15px; border-bottom: 1px solid #f1f5f9; font-size: 14px; color: var(--navy-dark); vertical-align: middle;}
        .admin-table tr:hover td { background-color: #f8fafc; }
        
        .status-pill { padding: 6px 14px; border-radius: 20px; font-size: 10px; font-weight: 800; text-transform: uppercase; box-shadow: inset 0 0 0 1px rgba(0,0,0,0.05); letter-spacing: 0.5px;}
        .available { background: #dcfce7; color: #166534; }
        .hidden { background: #fee2e2; color: #991b1b; }
        
        .btn-add { background: var(--navy-dark); color: var(--brand-yellow); padding: 12px 24px; border: none; border-radius: 8px; cursor: pointer; font-weight: 700; font-size: 14px; transition: 0.2s; font-family: 'Poppins', sans-serif; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);}
        .btn-add:hover { transform: translateY(-2px); box-shadow: 0 6px 12px rgba(0,0,0,0.15); opacity: 0.95; }
        
        /* Modern Action Buttons */
        .action-group { display: flex; gap: 8px; }
        .btn-icon { width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; text-decoration: none; transition: all 0.2s ease; font-size: 14px; cursor: pointer; border: none;}
        .btn-edit { background: #f1f5f9; color: var(--navy-dark); }
        .btn-edit:hover { background: var(--brand-yellow); color: var(--navy-dark); }
        .btn-delete { background: #fee2e2; color: #dc2626; }
        .btn-delete:hover { background: #dc2626; color: white; }

        /* --- MODAL --- */
        .modal { position: fixed; z-index: 1001; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,31,63,0.6); display: none; align-items: center; justify-content: center; }
        .modal-content { background: white; padding: 35px; width: 100%; max-width: 450px; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.2);}
        .modal-content label { display: block; font-size: 13px; font-weight: 700; color: var(--navy-dark); margin-bottom: 8px; }
        .modal-content input, .modal-content select { width: 100%; padding: 12px 15px; margin-bottom: 20px; border: 1px solid #e2e8f0; border-radius: 8px; outline: none; font-family: 'Poppins', sans-serif; font-size: 14px; background: #f8fafc; transition: 0.2s;}
        .modal-content input:focus, .modal-content select:focus { background: white; border-color: var(--navy-dark); box-shadow: 0 0 0 3px rgba(0,31,63,0.1);}
        
        .btn-save { background: var(--navy-dark); color: var(--brand-yellow); width: 100%; padding: 14px; border: none; border-radius: 8px; cursor: pointer; font-weight: 700; transition: 0.2s; font-family: 'Poppins', sans-serif; font-size: 14px; margin-top: 10px;}
        .btn-save:hover { opacity: 0.95; transform: translateY(-2px); box-shadow: 0 4px 10px rgba(0,0,0,0.1);}
        
        footer { text-align: center; padding: 40px; color: var(--text-muted); font-size: 12px; border-top: 1px solid rgba(0,0,0,0.05);}
    </style>
</head>
<body>

    <aside>
        <div class="sidebar-header">
            <img src="bg.png" alt="Boogie's Logo" class="sidebar-logo">
            <h2>Boogie's Admin</h2>
        </div>
        <nav class="nav-links">
            <a href="admindashboard.php" class="nav-item"><i class="fas fa-chart-line"></i> Dashboard</a>
            <a href="managebooking.php" class="nav-item"><i class="fas fa-calendar-alt"></i> Bookings</a>
            <a href="manageusers.php" class="nav-item"><i class="fas fa-users"></i> Users</a>
            <a href="managepet.php" class="nav-item"><i class="fas fa-dog"></i> Pets</a>

            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                <a href="managestaff.php" class="nav-item"><i class="fas fa-id-badge"></i> Personnel</a>
                <a href="managepromo.php" class="nav-item"><i class="fas fa-tags"></i> Promos</a>
                <a href="manage_services.php" class="nav-item active"><i class="fas fa-list-ul"></i> Pricelist</a>
                <a href="sales_report.php" class="nav-item"><i class="fas fa-file-invoice-dollar"></i> Sales Report</a>
                <a href="admin_account_logs.php" class="nav-item"><i class="fa-solid fa-clock-rotate-left"></i> Account Logs</a>
            <?php endif; ?>
        </nav>
    </aside>

    <main>
        <header class="top-bar">
            <div class="breadcrumb">
                <i class="fas fa-list-ul" style="opacity: 0.5; font-size: 14px;"></i> 
                Management / Pricelist
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
                                <div class="notif-empty">No new notifications.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="profile-wrapper" onclick="toggleProfile(event)">
                    <span class="admin-tag"><?php echo strtoupper($_SESSION['role'] ?? 'ADMIN'); ?></span>
                    
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
            <div class="data-box">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; border-bottom: 2px solid #f8fafc; padding-bottom: 20px;">
                    <div>
                        <h3 style="font-size: 22px; color: var(--navy-dark); font-weight: 800; margin-bottom: 5px;">Service Pricelist</h3>
                        <p style="color: var(--text-muted); font-size: 13px; font-weight: 500;">Manage the prices and availability of your shop's services.</p>
                    </div>
                    <button class="btn-add" onclick="openAddModal()"><i class="fas fa-plus"></i> New Service</button>
                </div>

                <div style="overflow-x: auto;">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Service Name</th>
                                <th>Price (PHP)</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($services as $service): ?>
                            <tr>
                                <td><span style="background: #f1f5f9; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 700; color: var(--text-muted);"><?php echo htmlspecialchars($service['category']); ?></span></td>
                                <td style="font-weight: 600; font-size: 15px;"><?php echo htmlspecialchars($service['service_name']); ?></td>
                                <td style="font-weight: 800; color: var(--navy-dark); font-size: 15px;">₱<?php echo number_format($service['price'], 2); ?></td>
                                <td>
                                    <span class="status-pill <?php echo $service['is_available'] == 1 ? 'available' : 'hidden'; ?>">
                                        <?php echo $service['is_available'] == 1 ? 'Available' : 'Hidden'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-group">
                                        <button class="btn-icon btn-edit" onclick='openEditModal(<?php echo json_encode($service); ?>)' title="Edit Price/Details"><i class="fas fa-edit"></i></button>
                                        <a href="manage_services.php?delete_id=<?php echo $service['id']; ?>" class="btn-icon btn-delete" onclick="return confirm('Delete this service permanently? This cannot be undone.')" title="Delete Service"><i class="fas fa-trash"></i></a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <footer>
            © <?php echo date("Y"); ?> BOOGIE'S PET CARE & SERVICES - DASMARIÑAS BRANCH
        </footer>
    </main>

    <div id="serviceModal" class="modal">
        <div class="modal-content">
            <h3 id="modalTitle" style="margin-bottom: 25px; color: var(--navy-dark); font-size: 22px; font-weight: 800;">Add New Service</h3>
            <form method="POST">
                <input type="hidden" name="service_id" id="service_id">
                
                <label>Category Group</label>
                <input type="text" name="category" id="cat_input" placeholder="e.g. Grooming, Pet Hotel" required>
                
                <label>Service Description</label>
                <input type="text" name="service_name" id="name_input" placeholder="e.g. Small Breed (1-5kg)" required>
                
                <label>Standard Price (PHP)</label>
                <input type="number" step="0.01" name="price" id="price_input" placeholder="0.00" required>

                <label>Customer Visibility</label>
                <select name="is_available" id="status_input">
                    <option value="1">Show in Booking Form (Available)</option>
                    <option value="0">Hide from Booking Form (Hidden)</option>
                </select>

                <button type="submit" name="save_service" class="btn-save">Save Service Details</button>
                <button type="button" onclick="closeModal()" style="width:100%; margin-top:15px; background:none; border:none; color:#94a3b8; font-weight:600; cursor:pointer; transition: 0.2s; font-family: 'Poppins', sans-serif;" onmouseover="this.style.color='#dc2626'" onmouseout="this.style.color='#94a3b8'">Cancel</button>
            </form>
        </div>
    </div>

    <script>
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

        // Close dropdowns and modals when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('serviceModal');
            if (event.target == modal) {
                closeModal();
            }

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

        // --- MODAL LOGIC ---
        const modal = document.getElementById('serviceModal');

        function openAddModal() {
            document.getElementById('modalTitle').innerText = "Add New Service";
            document.getElementById('service_id').value = "";
            document.getElementById('cat_input').value = "";
            document.getElementById('name_input').value = "";
            document.getElementById('price_input').value = "";
            document.getElementById('status_input').value = "1";
            modal.style.display = "flex";
        }

        function openEditModal(service) {
            document.getElementById('modalTitle').innerText = "Edit Service Details";
            document.getElementById('service_id').value = service.id;
            document.getElementById('cat_input').value = service.category;
            document.getElementById('name_input').value = service.service_name;
            document.getElementById('price_input').value = service.price;
            document.getElementById('status_input').value = service.is_available;
            modal.style.display = "flex";
        }

        function closeModal() {
            modal.style.display = "none";
        }
    </script>
</body>
</html>