<?php
session_start();
include '../db_connect.php';

// --- UNIVERSAL SECURITY CHECK (BAGONG IPAPALIT) ---
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';

if (!isset($_SESSION['logged_in']) || !in_array($current_role, ['admin', 'supervisor', 'staff'])) {
    header("Location: stafflogin.php");
    exit();
}

$success_msg = "";
$error_msg = "";

// 2. HANDLE FORM SUBMISSION (CREATE PET + SEND NOTIFICATION)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p_name = $_POST['name'];
    $p_type = $_POST['pet_type'];
    $p_breed = $_POST['breed'];
    $p_gender = $_POST['gender'];
    $p_age = $_POST['age'];
    $p_weight = $_POST['weight'];
    $owner_id = $_POST['owner_id']; 

    // Kunin ang full_name ng napiling owner
    $user_query = "SELECT full_name FROM users WHERE id = ?";
    $user_stmt = mysqli_prepare($conn, $user_query);
    mysqli_stmt_bind_param($user_stmt, "i", $owner_id);
    mysqli_stmt_execute($user_stmt);
    $user_result = mysqli_stmt_get_result($user_stmt);
    
    $owner_name = "Unknown Owner";
    if ($user_row = mysqli_fetch_assoc($user_result)) {
        $owner_name = $user_row['full_name']; 
    }

    // A. INSERT SA PETS TABLE
    $insert_query = "INSERT INTO pets (owner_id, owner_name, name, pet_type, breed, gender, age, weight) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $insert_stmt = mysqli_prepare($conn, $insert_query);
    mysqli_stmt_bind_param($insert_stmt, "isssssss", $owner_id, $owner_name, $p_name, $p_type, $p_breed, $p_gender, $p_age, $p_weight);
    
    if (mysqli_stmt_execute($insert_stmt)) {
        
        // B. AUTO-NOTIFICATION PARA SA CUSTOMER
        $notif_title = "New Pet Profile Created!";
        $notif_message = "A new pet profile for '$p_name' has been successfully registered to your account.";
        $notif_type = "system"; 
        
        // Siguraduhin na 'user_id' ang column name sa notifications table mo
        $notif_query = "INSERT INTO notifications (user_id, title, message, type, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())";
        $notif_stmt = mysqli_prepare($conn, $notif_query);
        mysqli_stmt_bind_param($notif_stmt, "isss", $owner_id, $notif_title, $notif_message, $notif_type);
        mysqli_stmt_execute($notif_stmt);

        $success_msg = "New pet successfully added and notification sent to owner!";
    } else {
        $error_msg = "Error adding record: " . mysqli_error($conn);
    }
}

// 3. FETCH CUSTOMERS ONLY (Para hindi kasama ang Admin at Vet/Staff)
$users_query = "SELECT id, full_name FROM users WHERE role = 'customer' ORDER BY full_name ASC";
$users_result = mysqli_query($conn, $users_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Pet | Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
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
        }

        .container { max-width: 800px; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .btn-back { background: var(--white); color: var(--navy-dark); padding: 10px 20px; border-radius: 8px; border: 1px solid var(--border); text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; }
        .btn-back:hover { background: #e2e8f0; }
        .card { background: var(--white); padding: 30px; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); border: 1px solid var(--border); }
        .card-title { font-size: 20px; color: var(--navy-dark); font-weight: 700; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; border-bottom: 1px solid var(--border); padding-bottom: 15px; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .form-group { margin-bottom: 15px; }
        .form-group.full-width { grid-column: span 2; }
        label { display: block; font-size: 13px; font-weight: 600; color: #64748b; margin-bottom: 8px; text-transform: uppercase; }
        input[type="text"], select { width: 100%; padding: 12px 15px; border: 1px solid var(--border); border-radius: 8px; font-size: 14px; outline: none; box-sizing: border-box; }
        input:focus, select:focus { border-color: var(--admin-purple); box-shadow: 0 0 0 3px rgba(139, 44, 245, 0.1); }
        .btn-submit { background: var(--admin-purple); color: white; padding: 12px 25px; border: none; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; transition: 0.2s; width: 100%; margin-top: 10px; }
        .btn-submit:hover { background: #7322cc; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; font-size: 14px; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    </style>
</head>
<body>

    <div class="container">
        <div class="header">
            <a href="managepet.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back to Pets</a>
        </div>

        <?php if($success_msg): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success_msg; ?></div>
        <?php endif; ?>

        <?php if($error_msg): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error_msg; ?></div>
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
                            if ($users_result && mysqli_num_rows($users_result) > 0) {
                                while ($u_row = mysqli_fetch_assoc($users_result)) {
                                    echo '<option value="' . $u_row['id'] . '">' . htmlspecialchars($u_row['full_name']) . '</option>';
                                }
                            } else {
                                echo '<option value="" disabled>No customer records found</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Pet Name</label>
                        <input type="text" name="name" placeholder="Enter pet's name" required>
                    </div>
                    <div class="form-group">
                        <label>Pet Type</label>
                        <input type="text" name="pet_type" placeholder="e.g. Dog, Cat" required>
                    </div>
                    <div class="form-group">
                        <label>Breed</label>
                        <input type="text" name="breed" placeholder="e.g. Bulldog">
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
                        <input type="text" name="age" placeholder="e.g. 6 yrs">
                    </div>
                    <div class="form-group">
                        <label>Weight</label>
                        <input type="text" name="weight" placeholder="e.g. 20">
                    </div>
                </div>
                <button type="submit" class="btn-submit"><i class="fas fa-save"></i> Add Pet Profile</button>
            </form>
        </div>
    </div>

</body>
</html>