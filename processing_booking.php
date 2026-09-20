<?php
session_start();
require_once 'db_supabase.php';

// Set timezone to Philippines
date_default_timezone_set('Asia/Manila');

// Ensure user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // 1. Capture and clean form data
        $user_id   = (int)($_SESSION['user_id'] ?? 0);
        $full_name = $_SESSION['user_name'] ?? 'Customer';

        if ($user_id <= 0) {
            throw new RuntimeException('Invalid user session.');
        }

        // Fetch user contact number for SMS
        $contact_number = '';
        $stmt_user = $pdo->prepare("
            SELECT contact_number
            FROM users
            WHERE id = :user_id
            LIMIT 1
        ");
        $stmt_user->execute([
            ':user_id' => $user_id
        ]);
        $user_row = $stmt_user->fetch(PDO::FETCH_ASSOC);

        if ($user_row) {
            $contact_number = $user_row['contact_number'] ?? '';
        }

        $pet_name         = trim($_POST['pet_name'] ?? '');
        $pet_type         = trim($_POST['pet_type'] ?? '');
        $pet_gender       = trim($_POST['pet_gender'] ?? '');
        $pet_size         = trim($_POST['pet_size'] ?? '');
        $service_category = trim($_POST['service_category'] ?? '');
        $specific_service = trim($_POST['specific_service'] ?? '');
        $haircut_style    = trim($_POST['haircut_style'] ?? '');
        $date             = trim($_POST['appointment_date'] ?? '');
        $time             = trim($_POST['appointment_time'] ?? '');
        $remarks          = trim($_POST['remarks'] ?? '');
        $gcash_ref        = trim($_POST['gcash_ref'] ?? '');

        if (
            $pet_name === '' ||
            $pet_type === '' ||
            $pet_gender === '' ||
            $pet_size === '' ||
            $service_category === '' ||
            $specific_service === '' ||
            $date === '' ||
            $time === '' ||
            $gcash_ref === ''
        ) {
            throw new RuntimeException('Please complete all required booking fields.');
        }

        // Combine service name
        $final_service_name = $service_category . " - " . $specific_service;

        if (
            $haircut_style !== '' &&
            $specific_service === 'Full Grooming Package'
        ) {
            $final_service_name .= " (" . $haircut_style . ")";
        }

        // --- 2. GCASH RECEIPT UPLOAD HANDLING ---
        $receipt_filename = '';

        if (
            isset($_FILES['gcash_receipt']) &&
            $_FILES['gcash_receipt']['error'] === UPLOAD_ERR_OK
        ) {
            $allowed = ['jpg', 'jpeg', 'png'];
            $filename = $_FILES['gcash_receipt']['name'] ?? '';
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            if (!in_array($ext, $allowed, true)) {
                echo "<script>alert('Invalid receipt image format. Only JPG and PNG.'); window.history.back();</script>";
                exit();
            }

            if (!is_dir('uploads')) {
                mkdir('uploads', 0777, true);
            }

            $receipt_filename = 'receipt_' . time() . '_' . $user_id . '.' . $ext;

            if (!move_uploaded_file(
                $_FILES['gcash_receipt']['tmp_name'],
                'uploads/' . $receipt_filename
            )) {
                echo "<script>alert('Failed to upload receipt image.'); window.history.back();</script>";
                exit();
            }
        } else {
            echo "<script>alert('Please upload your GCash receipt screenshot.'); window.history.back();</script>";
            exit();
        }

        // --- 3. CAPACITY RULES (10 Grooming, 6 Vet) ---
        if ($service_category === 'Vet Services') {
            $day_of_week = (int)date('w', strtotime($date));

            if ($day_of_week === 3 || $day_of_week === 6) {
                echo "<script>alert('Dr. Faith Casayuran is not available on Wednesdays and Saturdays. Please select a valid day.'); window.history.back();</script>";
                exit();
            }

            $capacity_stmt = $pdo->prepare("
                SELECT COUNT(id) AS total_booked
                FROM appointments
                WHERE appointment_date = :appointment_date
                  AND service ILIKE :service_pattern
                  AND booking_status <> 'Cancelled'
            ");

            $capacity_stmt->execute([
                ':appointment_date' => $date,
                ':service_pattern' => 'Vet Services%'
            ]);

            $capacity_row = $capacity_stmt->fetch(PDO::FETCH_ASSOC);

            if ((int)($capacity_row['total_booked'] ?? 0) >= 6) {
                echo "<script>alert('The Vet Clinic is fully booked for this date (Max 6 capacity). Please select another date.'); window.history.back();</script>";
                exit();
            }
        } elseif ($service_category === 'Grooming') {
            $capacity_stmt = $pdo->prepare("
                SELECT COUNT(id) AS total_booked
                FROM appointments
                WHERE appointment_date = :appointment_date
                  AND service ILIKE :service_pattern
                  AND booking_status <> 'Cancelled'
            ");

            $capacity_stmt->execute([
                ':appointment_date' => $date,
                ':service_pattern' => 'Grooming%'
            ]);

            $capacity_row = $capacity_stmt->fetch(PDO::FETCH_ASSOC);

            if ((int)($capacity_row['total_booked'] ?? 0) >= 10) {
                echo "<script>alert('Grooming services are fully booked for this date (Max 10 capacity). Please select another date.'); window.history.back();</script>";
                exit();
            }
        }

        // --- 4. EXACT TIME SLOT CONFLICT ---
        $time_conflict_stmt = $pdo->prepare("
            SELECT id
            FROM appointments
            WHERE appointment_date = :appointment_date
              AND appointment_time = :appointment_time
              AND service ILIKE :service_pattern
              AND booking_status <> 'Cancelled'
            LIMIT 1
        ");

        $time_conflict_stmt->execute([
            ':appointment_date' => $date,
            ':appointment_time' => $time,
            ':service_pattern' => $service_category . '%'
        ]);

        if ($time_conflict_stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "<script>alert('This exact time slot is already taken. Please choose another time.'); window.history.back();</script>";
            exit();
        }

        // --- 5. PET PROFILE HANDLING (Find or Auto-Create) ---
        $pet_id = null;

        $check_pet_stmt = $pdo->prepare("
            SELECT id
            FROM pets
            WHERE owner_id = :owner_id
              AND name = :pet_name
            LIMIT 1
        ");

        $check_pet_stmt->execute([
            ':owner_id' => $user_id,
            ':pet_name' => $pet_name
        ]);

        $pet_row = $check_pet_stmt->fetch(PDO::FETCH_ASSOC);

        if ($pet_row) {
            $pet_id = (int)$pet_row['id'];

            // Update size/type/gender in case they changed
            $update_pet_stmt = $pdo->prepare("
                UPDATE pets
                SET weight = :weight,
                    pet_type = :pet_type,
                    gender = :gender
                WHERE id = :pet_id
            ");

            $update_pet_stmt->execute([
                ':weight' => $pet_size,
                ':pet_type' => $pet_type,
                ':gender' => $pet_gender,
                ':pet_id' => $pet_id
            ]);
        } else {
            $insert_pet_stmt = $pdo->prepare("
                INSERT INTO pets
                    (owner_id, name, pet_type, gender, weight)
                VALUES
                    (:owner_id, :name, :pet_type, :gender, :weight)
                RETURNING id
            ");

            $insert_pet_stmt->execute([
                ':owner_id' => $user_id,
                ':name' => $pet_name,
                ':pet_type' => $pet_type,
                ':gender' => $pet_gender,
                ':weight' => $pet_size
            ]);

            $pet_id = (int)$insert_pet_stmt->fetchColumn();
        }

        // --- 6. INSERT APPOINTMENT (Pending Verification) ---
        $payment_method = 'GCash';
        $payment_status = 'Pending Verification';
        $booking_status = 'Pending';

        $insert_stmt = $pdo->prepare("
            INSERT INTO appointments
                (
                    user_id,
                    pet_id,
                    service,
                    appointment_date,
                    appointment_time,
                    gcash_ref,
                    gcash_receipt,
                    payment_method,
                    payment_status,
                    booking_status,
                    remarks
                )
            VALUES
                (
                    :user_id,
                    :pet_id,
                    :service,
                    :appointment_date,
                    :appointment_time,
                    :gcash_ref,
                    :gcash_receipt,
                    :payment_method,
                    :payment_status,
                    :booking_status,
                    :remarks
                )
        ");

        $insert_stmt->execute([
            ':user_id' => $user_id,
            ':pet_id' => $pet_id,
            ':service' => $final_service_name,
            ':appointment_date' => $date,
            ':appointment_time' => $time,
            ':gcash_ref' => $gcash_ref,
            ':gcash_receipt' => $receipt_filename,
            ':payment_method' => $payment_method,
            ':payment_status' => $payment_status,
            ':booking_status' => $booking_status,
            ':remarks' => $remarks
        ]);

        // Push Admin Notification
        $admin_msg = $full_name . " booked " . $pet_name .
                     " via GCash. Ref: " . $gcash_ref .
                     ". Pending Verification.";

        $admin_notif_stmt = $pdo->prepare("
            INSERT INTO admin_notifications (message)
            VALUES (:message)
        ");

        $admin_notif_stmt->execute([
            ':message' => $admin_msg
        ]);

        // --- 7. IPROG SMS NOTIFICATION (Customer Alert) ---
        if (!empty($contact_number)) {
            $iprog_helper = __DIR__ . '/includes/iprog_sms.php';

            if (file_exists($iprog_helper)) {
                require_once $iprog_helper;

                if (function_exists('sendIPROGSMS')) {
                    $formatted_time = date("g:i A", strtotime($time));
                    $sms_message = "Hi $full_name, we received your GCash booking request for $pet_name on $date at $formatted_time. Please wait for the admin to verify your payment. Thank you! - Boogie's Pet Care";

                    $sms_result = sendIPROGSMS($contact_number, $sms_message);

                    if (empty($sms_result['success'] ?? false)) {
                        error_log("IPROG SMS failed for booking notification.");
                    }
                } else {
                    error_log("sendIPROGSMS() function not found.");
                }
            } else {
                error_log("IPROG SMS helper not found: " . $iprog_helper);
            }
        }

        echo "<script>
                alert('Booking Request Submitted! Please wait for admin verification.');
                window.location.href='bookings.php';
              </script>";
        exit();

    } catch (Throwable $e) {
        error_log("Process booking failed: " . $e->getMessage());
        echo "Error saving booking: " . htmlspecialchars($e->getMessage());
    }
} else {
    header("Location: dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile & Settings | Personnel</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { 
            --brand-blue: #001f3f; 
            --brand-yellow: #ffcc00; 
            --bg-light: #f4f7f6; 
            --white: #ffffff; 
            --text-main: #1c1e21; 
            --text-muted: #64748b; 
            --border: #e2e8f0;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Poppins', sans-serif; }
        body { background-color: var(--bg-light); color: var(--text-main); display: flex; min-height: 100vh; }
        
        .container { padding: 40px; width: 100%; max-width: 1100px; margin: 0 auto; }
        
        .page-header { margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; }
        .page-header h2 { color: var(--brand-blue); font-size: 26px; margin: 0; font-weight: 800; }
        .btn-back { background: var(--white); color: var(--brand-blue); text-decoration: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; transition: 0.3s; display: flex; align-items: center; gap: 8px; font-size: 14px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .btn-back:hover { background: var(--brand-blue); color: var(--brand-yellow); transform: translateY(-2px); }
        
        /* ALERTS */
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 25px; font-size: 14px; display: flex; align-items: center; gap: 10px; font-weight: 500; }
        .alert-success { background: #dcfce7; color: #166534; border-left: 4px solid #16a34a; }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 4px solid #dc2626; }

        .profile-wrapper { display: grid; grid-template-columns: 320px 1fr; gap: 30px; align-items: start; }
        
        /* MODERN CARDS */
        .card { 
            background: var(--white); 
            border-radius: 20px; 
            padding: 30px; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.03); 
            position: relative; 
            overflow: hidden; 
            margin-bottom: 30px;
        }
        .card::before { 
            content: ''; 
            position: absolute; 
            top: 0; left: 0; 
            width: 100%; height: 5px; 
            background: linear-gradient(90deg, var(--brand-blue), var(--brand-yellow)); 
        }

        /* PROFILE DETAILS (LEFT SIDE) */
        .profile-card { text-align: center; }
        .profile-avatar { 
            width: 130px; height: 130px; 
            background: #f1f5f9; color: var(--brand-blue); 
            border-radius: 50%; 
            display: flex; align-items: center; justify-content: center; 
            font-size: 50px; font-weight: 800;
            margin: 0 auto 20px; 
            border: 4px solid var(--white);
            box-shadow: 0 8px 16px rgba(0, 31, 63, 0.15); 
            overflow: hidden; 
        }
        .profile-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .profile-card h3 { color: var(--brand-blue); font-size: 22px; font-weight: 800; margin-bottom: 5px; }
        .admin-badge { 
            background: var(--brand-blue); color: var(--brand-yellow); 
            padding: 6px 18px; border-radius: 50px; 
            font-size: 11px; font-weight: 800; letter-spacing: 1px; 
            display: inline-block; margin-bottom: 25px; 
        }
        
        .info-list { text-align: left; margin-top: 20px; font-size: 13px; color: var(--text-muted); line-height: 2; font-weight: 500;}
        .info-list div { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
        .info-list i { color: var(--brand-blue); font-size: 16px; width: 16px; text-align: center;}

        /* FORMS (RIGHT SIDE) */
        .section-title { font-size: 18px; font-weight: 700; color: var(--brand-blue); margin-bottom: 25px; padding-bottom: 15px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 10px; }
        
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .form-group { display: flex; flex-direction: column; gap: 8px; }
        .form-group.full-width { grid-column: span 2; }
        .form-group label { font-size: 13px; font-weight: 600; color: var(--brand-blue); }
        
        .form-control { 
            width: 100%; padding: 12px 15px; 
            border: 1px solid var(--border); border-radius: 8px; 
            font-size: 14px; color: var(--text-main); 
            background: #f8fafc; outline: none; transition: 0.2s;
        }
        .form-control:focus { background: var(--white); border-color: var(--brand-blue); box-shadow: 0 0 0 3px rgba(0, 31, 63, 0.1); }
        
        input[type="file"].form-control { padding: 10px; border: 1px dashed #cbd5e1; cursor: pointer; }
        
        .btn-submit { 
            background: var(--brand-blue); color: var(--brand-yellow); 
            border: none; padding: 12px 25px; border-radius: 8px; 
            font-weight: 700; font-size: 14px; cursor: pointer; 
            transition: 0.3s; display: inline-flex; align-items: center; gap: 8px; 
        }
        .btn-submit:hover { opacity: 0.9; transform: translateY(-2px); box-shadow: 0 4px 10px rgba(0, 31, 63, 0.2); }
        
        /* Mobile Responsiveness */
        @media (max-width: 900px) {
            .profile-wrapper { grid-template-columns: 1fr; }
            .form-grid { grid-template-columns: 1fr; }
            .form-group.full-width { grid-column: span 1; }
        }
    </style>
</head>
<body>
    
    <main style="flex-grow: 1;">
        <div class="container">
            <div class="page-header">
                <h2>Profile & Settings</h2>
                <a href="admindashboard.php" class="btn-back">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>

            <?php echo $message; ?>

            <div class="profile-wrapper">
                
                <div class="card profile-card">
                    <div class="profile-avatar">
                        <?php if ($display_image && file_exists($display_image)): ?>
                            <img src="<?php echo $display_image; ?>" alt="Profile Picture">
                        <?php else: ?>
                            <?php echo strtoupper(substr($display_name, 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <h3><?php echo $display_name; ?></h3>
                    <span class="admin-badge"><?php echo $display_role_badge; ?></span>
                    
                    <div class="info-list">
                        <div><i class="fas fa-user"></i> <?php echo $display_username; ?></div>
                        <div><i class="fas fa-phone"></i> <?php echo $display_phone ?: 'No contact number'; ?></div>
                        <div><i class="fas fa-calendar-alt"></i> Joined: <?php echo $join_date; ?></div>
                    </div>
                </div>

                <div>
                    <div class="card">
                        <form method="POST" action="" enctype="multipart/form-data">
                            <div class="section-title">
                                <i class="fas fa-user-edit"></i> Update Profile Information
                            </div>
                            <div class="form-grid">
                                <div class="form-group full-width">
                                    <label>Profile Picture</label>
                                    <input type="file" name="profile_picture" class="form-control" accept="image/jpeg, image/png, image/gif">
                                </div>
                                <div class="form-group full-width">
                                    <label>Full Name</label>
                                    <input type="text" name="full_name" class="form-control" value="<?php echo $display_name; ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Username</label>
                                    <input type="text" name="username" class="form-control" value="<?php echo $display_username; ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Contact Number</label>
                                    <input type="tel" name="contact_number" class="form-control" value="<?php echo $display_phone; ?>" placeholder="e.g. 09123456789" maxlength="11" pattern="[0-9]{11}" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                                </div>
                            </div>
                            <div style="text-align: right;">
                                <button type="submit" name="update_profile" class="btn-submit">
                                    <i class="fas fa-save"></i> Save Profile Details
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="card">
                        <form method="POST" action="">
                            <div class="section-title">
                                <i class="fas fa-lock"></i> Change Password
                            </div>
                            <div class="form-grid">
                                <div class="form-group full-width">
                                    <label>Current Password</label>
                                    <input type="password" name="current_password" class="form-control" required placeholder="Enter current password">
                                </div>
                                <div class="form-group">
                                    <label>New Password</label>
                                    <input type="password" name="new_password" class="form-control" minlength="8" required placeholder="Enter new password">
                                </div>
                                <div class="form-group">
                                    <label>Confirm New Password</label>
                                    <input type="password" name="confirm_password" class="form-control" minlength="8" required placeholder="Confirm new password">
                                </div>
                            </div>
                            <div style="text-align: right;">
                                <button type="submit" name="update_password" class="btn-submit">
                                    <i class="fas fa-key"></i> Update Password
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </main>
</body>
</html>