<?php
session_start();
require_once '../db_supabase.php';

// --- UNIVERSAL SECURITY CHECK ---
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !in_array($current_role, ['admin', 'supervisor', 'staff'], true)) {
    header("Location: stafflogin.php");
    exit();
}

$success_msg = "";
$error_msg = "";

// 2. HANDLE FORM SUBMISSION (CREATE PET + SEND NOTIFICATION)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p_name = trim($_POST['name'] ?? '');
    $p_type = trim($_POST['pet_type'] ?? '');
    $p_breed = trim($_POST['breed'] ?? '');
    $p_gender = trim($_POST['gender'] ?? '');
    $p_age = trim($_POST['age'] ?? '');
    $p_weight = trim($_POST['weight'] ?? '');
    $owner_id = (int)($_POST['owner_id'] ?? 0);

    if ($p_name === '' || $p_type === '' || $p_gender === '' || $owner_id <= 0) {
        $error_msg = "Please complete all required pet and owner fields.";
    } else {
        try {
            // Kunin ang full_name ng napiling owner
            $user_stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = :owner_id LIMIT 1");
            $user_stmt->execute([':owner_id' => $owner_id]);
            $user_row = $user_stmt->fetch(PDO::FETCH_ASSOC);

            $owner_name = $user_row['full_name'] ?? 'Unknown Owner';

            if (!$user_row) {
                throw new RuntimeException('Selected customer was not found.');
            }

            // A. INSERT SA PETS TABLE
            $insert_stmt = $pdo->prepare("\n                INSERT INTO pets\n                    (owner_id, owner_name, name, pet_type, breed, gender, age, weight)\n                VALUES\n                    (:owner_id, :owner_name, :name, :pet_type, :breed, :gender, :age, :weight)\n            ");

            $insert_stmt->execute([
                ':owner_id' => $owner_id,
                ':owner_name' => $owner_name,
                ':name' => $p_name,
                ':pet_type' => $p_type,
                ':breed' => $p_breed,
                ':gender' => $p_gender,
                ':age' => $p_age,
                ':weight' => $p_weight
            ]);

            // B. AUTO-NOTIFICATION PARA SA CUSTOMER
            $notif_title = "New Pet Profile Created!";
            $notif_message = "A new pet profile for '{$p_name}' has been successfully registered to your account.";
            $notif_type = "system";

            $notif_stmt = $pdo->prepare("\n                INSERT INTO notifications\n                    (user_id, title, message, type, is_read, created_at)\n                VALUES\n                    (:user_id, :title, :message, :type, 0, CURRENT_TIMESTAMP)\n            ");

            $notif_stmt->execute([
                ':user_id' => $owner_id,
                ':title' => $notif_title,
                ':message' => $notif_message,
                ':type' => $notif_type
            ]);

            $success_msg = "New pet successfully added and notification sent to owner!";
        } catch (PDOException $e) {
            error_log("Add pet failed: " . $e->getMessage());
            $error_msg = "Error adding record. Please try again.";
        } catch (Throwable $e) {
            error_log("Add pet failed: " . $e->getMessage());
            $error_msg = "Error adding record: " . $e->getMessage();
        }
    }
}

// 3. FETCH CUSTOMERS ONLY (Para hindi kasama ang Admin at Vet/Staff)
$users_result = [];

try {
    $users_stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE role = 'customer' ORDER BY full_name ASC");
    $users_stmt->execute();
    $users_result = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Fetch customers failed: " . $e->getMessage());
    $error_msg = $error_msg ?: "Unable to load customer records.";
}
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
            overflow-x: hidden;
        }

        .container { max-width: 800px; width: 100%; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .btn-back { background: var(--white); color: var(--navy-dark); padding: 10px 20px; border-radius: 8px; border: 1px solid var(--border); text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; min-height: 42px; }
        .btn-back:hover { background: #e2e8f0; }
        .card { background: var(--white); padding: 30px; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); border: 1px solid var(--border); }
        .card-title { font-size: 20px; color: var(--navy-dark); font-weight: 700; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; border-bottom: 1px solid var(--border); padding-bottom: 15px; line-height: 1.35; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .form-group { margin-bottom: 15px; min-width: 0; }
        .form-group.full-width { grid-column: span 2; }
        label { display: block; font-size: 13px; font-weight: 600; color: #64748b; margin-bottom: 8px; text-transform: uppercase; }
        input[type="text"], select { width: 100%; min-height: 46px; padding: 12px 15px; border: 1px solid var(--border); border-radius: 8px; font-size: 14px; outline: none; box-sizing: border-box; background: #fff; }
        input:focus, select:focus { border-color: var(--admin-purple); box-shadow: 0 0 0 3px rgba(139, 44, 245, 0.1); }
        .btn-submit { background: var(--admin-purple); color: white; padding: 12px 25px; border: none; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; transition: 0.2s; width: 100%; min-height: 46px; margin-top: 10px; }
        .btn-submit:hover { background: #7322cc; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; font-size: 14px; line-height: 1.55; overflow-wrap: anywhere; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        @media (max-width: 700px) {
            body { padding: 20px 14px; }
            .header { margin-bottom: 16px; }
            .btn-back { width: 100%; justify-content: center; padding: 11px 14px; }
            .card { padding: 20px 16px; border-radius: 10px; }
            .card-title { font-size: 18px; margin-bottom: 18px; }
            .form-grid { grid-template-columns: 1fr; gap: 0; }
            .form-group.full-width { grid-column: auto; }
            .form-group { margin-bottom: 14px; }
            label { font-size: 12px; }
            input[type="text"], select { font-size: 16px; min-height: 48px; }
            .btn-submit { font-size: 14px; min-height: 48px; }
            .alert { font-size: 13px; padding: 13px; }
        }

        @media (max-width: 380px) {
            body { padding: 14px 10px; }
            .card { padding: 16px 13px; }
            .card-title { font-size: 16px; }
        }
    </style>
</head>
<body>

    <div class="container">
        <div class="header">
            <a href="managepet.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back to Pets</a>
        </div>

        <?php if($success_msg): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_msg); ?></div>
        <?php endif; ?>

        <?php if($error_msg): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_msg); ?></div>
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
                            if (!empty($users_result)) {
                                foreach ($users_result as $u_row) {
                                    echo '<option value="' . (int)$u_row['id'] . '">' . htmlspecialchars($u_row['full_name'] ?? '') . '</option>';
                                }
                            } else {
                                echo '<option value="" disabled>No customer records found</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Pet Name</label>
                        <input type="text" name="name" placeholder="Enter pet's name" autocomplete="off" required>
                    </div>
                    <div class="form-group">
                        <label>Pet Type</label>
                        <input type="text" name="pet_type" placeholder="e.g. Dog, Cat" autocomplete="off" required>
                    </div>
                    <div class="form-group">
                        <label>Breed</label>
                        <input type="text" name="breed" placeholder="e.g. Bulldog" autocomplete="off">
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
                        <input type="text" name="age" placeholder="e.g. 6 yrs" inputmode="decimal" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label>Weight</label>
                        <input type="text" name="weight" placeholder="e.g. 20" inputmode="decimal" autocomplete="off">
                    </div>
                </div>
                <button type="submit" class="btn-submit"><i class="fas fa-save"></i> Add Pet Profile</button>
            </form>
        </div>
    </div>

</body>
</html>
