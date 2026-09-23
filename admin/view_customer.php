<?php
session_start();
include '../db_supabase.php';

// --- UNIVERSAL SECURITY CHECK ---
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';

// 1. SECURITY: Allow Admin, Supervisor, and Staff
if (!isset($_SESSION['logged_in']) || !in_array($current_role, ['admin', 'supervisor', 'staff'], true)) {
    header("Location: ../staff/stafflogin.php");
    exit();
}

// 2. FETCH ADMIN/SUPERVISOR PROFILE
$admin_full_name = "User";
$profile_img_path = "";
$first_name = "User";

if (isset($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];

    try {
        $get_admin = $pdo->prepare("
            SELECT full_name, profile_image
            FROM users
            WHERE id = :id
            LIMIT 1
        ");
        $get_admin->execute([':id' => $uid]);
        $admin_data = $get_admin->fetch(PDO::FETCH_ASSOC);

        if ($admin_data) {
            $admin_full_name = $admin_data['full_name'] ?? 'User';
            $profile_img_path = $admin_data['profile_image'] ?? '';
            $_SESSION['user_name'] = $admin_full_name;

            $first_name = trim(explode(' ', $admin_full_name)[0], ',');
        }
    } catch (PDOException $e) {
        $admin_full_name = $_SESSION['user_name'] ?? 'User';
        $first_name = trim(explode(' ', $admin_full_name)[0], ',');
    }
}

// Redirect back to manage users if no ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: manageusers.php");
    exit();
}

$customer_id = (int)$_GET['id'];

// --- ACTION LOGIC (UPDATE & DELETE) ---

// A. UPDATE CONTACT NUMBER
if (isset($_POST['update_contact'])) {
    $new_contact = trim($_POST['new_contact'] ?? '');

    // Strict Validation
    if (!preg_match("/^[0-9]{11}$/", $new_contact)) {
        echo "<script>alert('Invalid contact number. Please enter exactly 11 digits.'); window.history.back();</script>";
        exit();
    }

    try {
        $stmt_update = $pdo->prepare("
            UPDATE users
            SET contact_number = :contact_number
            WHERE id = :customer_id
        ");
        $stmt_update->execute([
            ':contact_number' => $new_contact,
            ':customer_id' => $customer_id
        ]);

        header("Location: view_customer.php?id=" . $customer_id);
        exit();
    } catch (PDOException $e) {
        die("Unable to update the contact number at this time.");
    }
}

// B. DELETE USER
if (isset($_POST['delete_user'])) {
    try {
        $pdo->beginTransaction();

        // Delete associated appointments first, then pets, then the user.
        // This order is safer when foreign keys are enforced in PostgreSQL.
        $delete_appointments = $pdo->prepare("
            DELETE FROM appointments
            WHERE user_id = :customer_id
        ");
        $delete_appointments->execute([':customer_id' => $customer_id]);

        $delete_pets = $pdo->prepare("
            DELETE FROM pets
            WHERE owner_id = :customer_id
        ");
        $delete_pets->execute([':customer_id' => $customer_id]);

        $delete_user = $pdo->prepare("
            DELETE FROM users
            WHERE id = :customer_id
              AND role = 'customer'
        ");
        $delete_user->execute([':customer_id' => $customer_id]);

        $pdo->commit();

        header("Location: manageusers.php");
        exit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        die("Unable to delete this customer account at this time.");
    }
}

// --- FETCH DATA ---

// 2. Fetch Customer Details
try {
    $user_stmt = $pdo->prepare("
        SELECT *
        FROM users
        WHERE id = :customer_id
          AND role = 'customer'
        LIMIT 1
    ");
    $user_stmt->execute([':customer_id' => $customer_id]);
    $customer = $user_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $customer = false;
}

if (!$customer) {
    die("<div style='text-align:center; padding:50px; font-family:sans-serif;'><h2>Customer not found.</h2><a href='manageusers.php'>Go Back</a></div>");
}

// 3. Fetch Customer's Pets
try {
    $pets_stmt = $pdo->prepare("
        SELECT *
        FROM pets
        WHERE owner_id = :customer_id
        ORDER BY id ASC
    ");
    $pets_stmt->execute([':customer_id' => $customer_id]);
    $pets = $pets_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $pets = [];
}

// 4. Fetch Booking History
try {
    $bookings_stmt = $pdo->prepare("
        SELECT
            a.*,
            p.name AS pet_name
        FROM appointments a
        LEFT JOIN pets p ON a.pet_id = p.id
        WHERE a.user_id = :customer_id
        ORDER BY a.appointment_date DESC
    ");
    $bookings_stmt->execute([':customer_id' => $customer_id]);
    $bookings = $bookings_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $bookings = [];
}

// 5. User Category Helper
$category = isset($customer['user_category']) && !empty($customer['user_category'])
    ? $customer['user_category']
    : 'Pet Owner';

$cat_class = ($category === 'Pet Breeder')
    ? 'category-breeder'
    : 'category-owner';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($customer['full_name'] ?? 'Customer'); ?> | Admin View</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    :root {
        /* Boogie's Signature Colors */
        --brand-blue: #001f3f; 
        --brand-yellow: #ffcc00;
        --brand-purple: #8b2cf5;
        --bg-light: #f4f7f6; 
        --white: #ffffff;
        --text-main: #2d3436;
        --text-muted: #64748b;
        --border: #e2e8f0;
    }

    * { margin: 0; padding: 0; box-sizing: border-box; }
    
    body { 
        font-family: 'Poppins', sans-serif; 
        background: var(--bg-light); 
        color: var(--text-main); 
        padding: 30px; 
    }

    /* Upgraded Back Button */
    .back-btn { 
        text-decoration: none; 
        color: var(--brand-blue); 
        font-weight: 700; 
        font-size: 14px; 
        display: inline-flex; 
        align-items: center; 
        gap: 8px; 
        margin-bottom: 25px; 
        transition: 0.3s; 
        padding: 10px 18px; 
        background: var(--white); 
        border-radius: 12px; 
        box-shadow: 0 4px 6px rgba(0,0,0,0.03); 
    }
    .back-btn:hover { 
        background: var(--brand-blue); 
        color: var(--brand-yellow); 
        transform: translateY(-2px); 
        box-shadow: 0 8px 15px rgba(0, 31, 63, 0.2);
    }

    .profile-grid { 
        display: grid; 
        grid-template-columns: 350px 1fr; 
        gap: 25px; 
        align-items: start; 
        max-width: 1200px;
        margin: 0 auto;
    }

    /* Upgraded Cards with Top Highlight */
    .card { 
        background: var(--white); 
        border-radius: 20px; 
        border: none; 
        padding: 30px; 
        box-shadow: 0 4px 15px rgba(0,0,0,0.03); 
        margin-bottom: 25px; 
        position: relative; 
        overflow: hidden; 
    }
    .card::before { 
        content: ''; 
        position: absolute; 
        top: 0; 
        left: 0; 
        width: 100%; 
        height: 5px; 
        background: linear-gradient(90deg, var(--brand-blue), var(--brand-purple)); 
    }

    .section-title { 
        font-size: 18px; 
        font-weight: 800; 
        margin-bottom: 20px; 
        display: flex; 
        align-items: center; 
        gap: 10px; 
        color: var(--brand-blue); 
    }
    .section-title i { color: var(--brand-purple); }

    /* Circular Avatar */
    .profile-header { text-align: center; margin-bottom: 25px; }
    .avatar-large { 
        width: 100px; 
        height: 100px; 
        background: #f3e8ff; 
        color: var(--brand-purple); 
        border-radius: 50%; 
        display: flex; 
        align-items: center; 
        justify-content: center; 
        font-size: 40px; 
        font-weight: 800; 
        margin: 0 auto 15px; 
        border: 4px solid var(--white); 
        box-shadow: 0 8px 16px rgba(139, 44, 245, 0.15); 
    }

    /* BAGO: Category Tags */
    .category-tag {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 6px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 20px;
    }
    .category-owner { background: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd; }
    .category-breeder { background: #fef3c7; color: #d97706; border: 1px solid #fde68a; }

    /* Customer Info */
    .info-item { 
        margin-bottom: 15px; 
        padding-bottom: 12px; 
        border-bottom: 1px dashed var(--border); 
    }
    .info-item:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
    
    .info-label { 
        font-size: 11px; 
        font-weight: 700; 
        color: var(--text-muted); 
        text-transform: uppercase; 
        letter-spacing: 0.5px; 
        margin-bottom: 4px; 
        display: flex;
        justify-content: space-between;
    }
    
    .info-value { 
        font-size: 14px; 
        font-weight: 700; 
        color: var(--brand-blue); 
    }

    /* Edit Input */
    .edit-input {
        padding: 8px 10px;
        border: 1px solid var(--border);
        border-radius: 6px;
        font-family: inherit;
        font-size: 13px;
        width: 65%;
        outline: none;
    }
    .edit-input:focus { border-color: var(--brand-blue); }
    
    .btn-save {
        padding: 8px 15px;
        background: var(--brand-blue);
        color: var(--brand-yellow);
        border: none;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
        transition: 0.2s;
    }
    .btn-save:hover { opacity: 0.9; }
    
    .btn-delete {
        width: 100%;
        padding: 14px;
        background: #fff1f2;
        color: #e11d48;
        border: 1px dashed #fda4af;
        border-radius: 12px;
        font-weight: 700;
        font-size: 14px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        margin-top: 25px;
        transition: 0.3s;
    }
    .btn-delete:hover {
        background: #e11d48;
        color: white;
        border-style: solid;
    }

    /* Interactive Pet Items */
    .pet-item { 
        display: flex; 
        align-items: center; 
        gap: 15px; 
        padding: 15px; 
        background: #f8fafc; 
        border-radius: 12px; 
        margin-bottom: 12px; 
        border-left: 4px solid var(--brand-purple); 
        transition: 0.2s; 
    }
    .pet-item:hover { 
        background: #f1f5f9; 
        transform: translateX(5px); 
    }

    /* Table Styles */
    table { width: 100%; border-collapse: collapse; }
    th { 
        text-align: left; 
        padding: 15px; 
        font-size: 12px; 
        color: var(--text-muted); 
        text-transform: uppercase; 
        border-bottom: 2px solid var(--bg-light); 
        letter-spacing: 0.5px; 
    }
    td { 
        padding: 15px; 
        font-size: 13px; 
        border-bottom: 1px solid #f1f5f9; 
        vertical-align: middle; 
        font-weight: 500;
    }
    tr:hover td { background-color: #f8fafc; }

    /* Status Pill */
    .status-pill { 
        padding: 6px 14px; 
        border-radius: 50px; 
        font-size: 10px; 
        font-weight: 800; 
        text-transform: uppercase; 
        background: #f1f5f9; 
        color: var(--text-muted); 
        display: inline-block; 
    }
    .st-cancelled, .st-no-show { background: #fee2e2; color: #b91c1c; }
    .st-completed { background: #dcfce7; color: #16a34a; }
    .st-confirmed { background: #e0f2fe; color: #0284c7; }
    
    @media (max-width: 900px) {
        .profile-grid { grid-template-columns: 1fr; }
    }

        /* ===== EXTRA MOBILE RESPONSIVENESS ===== */
        @media (max-width: 700px) {
            body {
                padding: 18px 12px;
            }

            .back-btn {
                width: 100%;
                justify-content: center;
                margin-bottom: 16px;
                padding: 12px 14px;
                font-size: 13px;
            }

            .profile-grid {
                grid-template-columns: 1fr;
                gap: 16px;
                max-width: 100%;
            }

            .card {
                padding: 20px 15px;
                border-radius: 16px;
                margin-bottom: 16px;
            }

            .section-title {
                font-size: 16px;
                line-height: 1.35;
            }

            .profile-header {
                margin-bottom: 20px;
            }

            .avatar-large {
                width: 86px;
                height: 86px;
                font-size: 34px;
            }

            .info-label {
                gap: 8px;
                flex-wrap: wrap;
            }

            .info-value {
                font-size: 13px;
                overflow-wrap: anywhere;
            }

            .edit-input {
                width: 100%;
                min-width: 0;
                min-height: 44px;
                padding: 9px 10px;
            }

            #contactForm > div {
                flex-direction: column;
                gap: 8px;
            }

            .btn-save {
                width: 100%;
                min-height: 44px;
            }

            .btn-delete {
                min-height: 46px;
                font-size: 13px;
            }

            .pet-item {
                align-items: flex-start;
                padding: 13px;
            }

            /* Keep appointment history usable on phones via horizontal scrolling. */
            .main-col .card > div[style*="overflow-x"] {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            .main-col table {
                min-width: 620px;
            }

            th,
            td {
                white-space: nowrap;
            }

            td strong,
            td .status-pill {
                white-space: normal;
            }
        }

        @media (max-width: 400px) {
            body {
                padding: 12px 9px;
            }

            .card {
                padding: 18px 12px;
            }

            .section-title {
                font-size: 15px;
            }

            .avatar-large {
                width: 78px;
                height: 78px;
                font-size: 30px;
            }
        }

    </style>
</head>
<body>

    <a href="manageusers.php" class="back-btn"><i class="fas fa-chevron-left"></i> Back to Directory</a>

    <div class="profile-grid">
        <div class="sidebar-col">
            <div class="card">
                <div class="profile-header">
                    <div class="avatar-large"><?php echo strtoupper(substr(trim($customer['full_name'], ','), 0, 1)); ?></div>
                    <h2 style="font-size: 20px; color: var(--brand-blue);"><?php echo htmlspecialchars($customer['full_name']); ?></h2>
                    <span class="category-tag <?php echo $cat_class; ?>"><?php echo htmlspecialchars($category); ?></span>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Email Address</div>
                    <div class="info-value">
                        <?php 
                        $raw_email = $customer['email'] ?? '';
                        if (empty($raw_email) || strpos($raw_email, '@guest.local') !== false) {
                            echo '<span style="color:#ea580c; font-weight:700; font-size:11px; background:#ffedd5; padding:2px 6px; border-radius:4px; border: 1px solid #fdba74;">WALK-IN GUEST</span>';
                        } else {
                            echo htmlspecialchars($raw_email);
                        }
                        ?>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">
                        Contact Number
                        <a href="#" onclick="toggleEdit()" style="color:var(--brand-purple); text-decoration:none;"><i class="fas fa-edit"></i> Edit</a>
                    </div>
                    
                    <div class="info-value" id="contactDisplay">
                        <?php echo htmlspecialchars($customer['contact_number'] ?? 'N/A'); ?>
                    </div>
                    
                    <form method="POST" id="contactForm" style="display:none; margin-top: 8px;">
                        <div style="display: flex; gap: 5px;">
                            <input type="tel" inputmode="numeric" name="new_contact" class="edit-input" value="<?php echo htmlspecialchars($customer['contact_number'] ?? ''); ?>" required maxlength="11" pattern="[0-9]{11}" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                            <button type="submit" name="update_contact" class="btn-save">Save</button>
                        </div>
                    </form>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Account Verification</div>
                    <div class="info-value">
                        <?php
                        $verified_raw = $customer['is_verified'] ?? false;
                        $is_verified = in_array(
                            strtolower(trim((string)$verified_raw)),
                            ['1', 'true', 't'],
                            true
                        );
                        ?>
                        <?php if ($is_verified): ?>
                            <span style="color: #10b981; font-size: 13px;"><i class="fa-solid fa-circle-check"></i> Verified</span>
                        <?php else: ?>
                            <span style="color: #ef4444; font-size: 13px;"><i class="fa-solid fa-circle-xmark"></i> Unverified</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="info-item">
                    <div class="info-label">Member Since</div>
                    <div class="info-value" style="font-weight: 500;">
                        <?php echo isset($customer['created_at']) ? date('F d, Y', strtotime($customer['created_at'])) : 'Unknown'; ?>
                    </div>
                </div>

                <form method="POST" onsubmit="return confirm('WARNING: Are you sure you want to delete this user? All their pets and appointment history will also be permanently removed. This action cannot be undone.');">
                    <button type="submit" name="delete_user" class="btn-delete">
                        <i class="fas fa-trash-alt"></i> Delete Account
                    </button>
                </form>
            </div>

            <div class="card">
                <div class="section-title"><i class="fas fa-paw"></i> Registered Pets</div>
                <?php if(count($pets) > 0): ?>
                    <?php foreach($pets as $pet): ?>
                        <div class="pet-item">
                            <i class="fas fa-dog" style="color:var(--brand-blue); font-size: 20px; opacity: 0.8;"></i>
                            <div>
                                <div style="font-weight: 700; font-size: 14px; color: var(--brand-blue);">
                                    <?php 
                                        if (isset($pet['pet_name'])) echo htmlspecialchars($pet['pet_name']);
                                        elseif (isset($pet['name'])) echo htmlspecialchars($pet['name']);
                                        else echo "Unknown Pet";
                                    ?>
                                </div>
                                <div style="font-size: 12px; color: var(--text-muted);">
                                    <?php echo htmlspecialchars($pet['breed'] ?? 'Unknown Breed'); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="text-align: center; padding: 20px;">
                        <i class="fa-solid fa-bone" style="font-size: 30px; color: #cbd5e1; margin-bottom: 10px;"></i>
                        <p style="font-size: 13px; color: var(--text-muted);">No pets registered yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="main-col">
            <div class="card">
                <div class="section-title"><i class="fas fa-history"></i> Appointment History</div>
                
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Service Type</th>
                                <th>Pet Name</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(count($bookings) > 0): ?>
                                <?php foreach($bookings as $book): 
                                    // Setup color coding for status
                                    $status = $book['booking_status'] ?? 'Pending';
                                    $s_class = '';
                                    if ($status == 'Cancelled') $s_class = 'st-cancelled';
                                    elseif ($status == 'Completed') $s_class = 'st-completed';
                                    elseif ($status == 'Confirmed') $s_class = 'st-confirmed';
                                    elseif ($status == 'No-Show') $s_class = 'st-no-show';
                                ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 700; color: var(--brand-blue);">
                                                <?php echo isset($book['appointment_date']) ? date('M d, Y', strtotime($book['appointment_date'])) : 'N/A'; ?>
                                            </div>
                                            <div style="font-size: 11px; color: var(--text-muted); margin-top: 3px;">
                                                <i class="far fa-clock"></i> <?php echo htmlspecialchars($book['appointment_time'] ?? 'N/A'); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <strong style="color: var(--text-main);"><?php echo htmlspecialchars($book['service'] ?? 'General'); ?></strong>
                                        </td>
                                        
                                        <td>
                                            <?php 
                                                if (isset($book['pet_name']) && !empty($book['pet_name'])) {
                                                    echo htmlspecialchars($book['pet_name']);
                                                } else {
                                                    echo "<span style='color: #cbd5e1; font-style: italic;'>Unknown</span>";
                                                }
                                            ?>
                                        </td>
                                        
                                        <td>
                                            <span class="status-pill <?php echo $s_class; ?>"><?php echo htmlspecialchars($status); ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4" style="text-align: center; padding: 50px; color: var(--text-muted);">No records found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleEdit() {
            var display = document.getElementById('contactDisplay');
            var form = document.getElementById('contactForm');
            
            if (form.style.display === 'none' || form.style.display === '') {
                form.style.display = 'block';
                display.style.display = 'none';
            } else {
                form.style.display = 'none';
                display.style.display = 'block';
            }
        }
    </script>
</body>
</html>