<?php
session_start();
require_once '../db_supabase.php';

// Check if authorized staff is logged in
$current_role = strtolower(trim($_SESSION['role'] ?? ''));

if (
    (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) ||
    $current_role !== 'vet'
) {
    header("Location: stafflogin.php");
    exit;
}

// CSRF protection for pet updates
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$pet_data = null;
$error_message = '';
$success_message = '';

// Get the pet ID from GET when opening the edit page, or POST when submitting the form.
$pet_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$pet_id && isset($_POST['pet_id'])) {
    $pet_id = filter_var($_POST['pet_id'], FILTER_VALIDATE_INT);
}

if (!$pet_id) {
    echo "<script>alert('Invalid pet ID.'); window.location.href='pets.php';</script>";
    exit;
}

// Load the current pet record so the form has its existing values.
try {
    $pet_stmt = $pdo->prepare("SELECT * FROM pets WHERE id = :id LIMIT 1");
    $pet_stmt->execute([':id' => $pet_id]);
    $pet_data = $pet_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = 'Unable to load the pet record.';
}

if (!$pet_data) {
    echo "<script>alert('Pet record not found.'); window.location.href='pets.php';</script>";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF validation
    $submitted_csrf = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $submitted_csrf)) {
        $error_message = 'Invalid form request. Please refresh the page and try again.';
    } else {
        // 1. Grab and clean all form data.
        $owner_id = filter_var($_POST['customer_id'] ?? '', FILTER_VALIDATE_INT);
        $pet_name = trim($_POST['pet_name'] ?? '');
        $pet_type = trim($_POST['pet_type'] ?? '');
        $breed = trim($_POST['breed'] ?? '');
        $age = trim($_POST['age'] ?? '');
        $weight = trim($_POST['weight'] ?? '');
        $gender = trim($_POST['gender'] ?? '');
        $medical_history = trim($_POST['medical_history'] ?? '');

        // Preserve the existing workflow: edited staff records become Approved.
        $status = 'Approved';

        if (!$owner_id || $pet_name === '' || $pet_type === '' || $breed === '' || $age === '' || $weight === '' || $gender === '') {
            $error_message = 'Please complete all required fields.';
        } elseif (!in_array($pet_type, ['Dog', 'Cat', 'Other'], true)) {
            $error_message = 'Invalid pet type selected.';
        } elseif (!in_array($gender, ['Male', 'Female'], true)) {
            $error_message = 'Invalid gender selected.';
        } else {
            try {
                // Confirm the selected owner is an actual customer account.
                $owner_stmt = $pdo->prepare("SELECT id FROM users WHERE id = :owner_id AND role = 'customer' LIMIT 1");
                $owner_stmt->execute([':owner_id' => $owner_id]);

                if (!$owner_stmt->fetchColumn()) {
                    $error_message = 'Invalid customer selected.';
                } else {
                    // 2. Update the database using owner_id.
                    $update_stmt = $pdo->prepare("UPDATE pets SET
                        owner_id = :owner_id,
                        name = :pet_name,
                        pet_type = :pet_type,
                        breed = :breed,
                        age = :age,
                        weight = :weight,
                        gender = :gender,
                        medical_history = :medical_history,
                        status = :status
                        WHERE id = :pet_id");

                    $update_stmt->execute([
                        ':owner_id' => $owner_id,
                        ':pet_name' => $pet_name,
                        ':pet_type' => $pet_type,
                        ':breed' => $breed,
                        ':age' => $age,
                        ':weight' => $weight,
                        ':gender' => $gender,
                        ':medical_history' => $medical_history,
                        ':status' => $status,
                        ':pet_id' => $pet_id
                    ]);

                    echo "<script>
                            alert('Pet details successfully updated and approved!');
                            window.location.href='pets.php';
                          </script>";
                    exit;
                }
            } catch (PDOException $e) {
                error_log('Staff pet update failed: ' . $e->getMessage());
                $error_message = 'Unable to update the pet record. Please try again.';
            }
        }

        // Keep the edited values on screen if validation/update fails.
        $pet_data['owner_id'] = $owner_id ?: $pet_data['owner_id'];
        $pet_data['name'] = $pet_name;
        $pet_data['pet_type'] = $pet_type;
        $pet_data['breed'] = $breed;
        $pet_data['age'] = $age;
        $pet_data['weight'] = $weight;
        $pet_data['gender'] = $gender;
        $pet_data['medical_history'] = $medical_history;
    }
}

// Customer list for the dropdown.
try {
    $cust_stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE role = 'customer' ORDER BY full_name ASC");
    $cust_stmt->execute();
    $customers = $cust_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $customers = [];
    $error_message = 'Unable to load the customer list.';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Pet | Staff Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --brand-blue: #1d63ff;
            --bg-light: #f4f7fe;
            --white: #ffffff;
            --text-main: #2d3748;
            --text-muted: #718096;
            --border: #e2e8f0;
            --brand-pink: #e91e63;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg-light); color: var(--text-main); }
        
        .container { max-width: 800px; margin: 40px auto; padding: 0 20px; }
        
        .back-link { display: inline-flex; align-items: center; gap: 8px; color: var(--brand-blue); text-decoration: none; font-weight: 600; font-size: 14px; margin-bottom: 20px; }
        
        .form-card { background: var(--white); padding: 40px; border-radius: 16px; border: 1px solid var(--border); box-shadow: 0 5px 15px rgba(0,0,0,0.02); }
        .form-header { text-align: center; margin-bottom: 30px; }
        .form-header .icon-circle { width: 50px; height: 50px; background: var(--brand-pink); color: white; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 20px; margin-bottom: 15px; }
        .form-header h2 { font-size: 24px; color: #1a202c; }
        
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: #4a5568; margin-bottom: 8px; }
        .form-control { width: 100%; padding: 12px 15px; border: 1px solid var(--border); border-radius: 8px; font-size: 14px; color: var(--text-main); outline: none; transition: 0.3s; font-family: inherit; }
        .form-control:focus { border-color: var(--brand-blue); box-shadow: 0 0 0 3px rgba(29, 99, 255, 0.1); }
        
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        
        .submit-btn { background: var(--brand-pink); color: white; border: none; padding: 14px 24px; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; width: 100%; margin-top: 20px; transition: 0.3s; }
        .submit-btn:hover { opacity: 0.9; transform: translateY(-2px); }

        .alert-box { margin-bottom: 20px; padding: 12px 15px; border-radius: 8px; font-size: 13px; }
        .alert-error { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }

        /* ===== EXTRA MOBILE RESPONSIVENESS ===== */
        @media (max-width: 700px) {
            body {
                overflow-x: hidden;
            }

            .container {
                width: 100%;
                max-width: 100%;
                margin: 24px auto;
                padding: 0 14px;
            }

            .back-link {
                font-size: 13px;
                margin-bottom: 15px;
                min-height: 42px;
            }

            .form-card {
                padding: 24px 16px;
                border-radius: 14px;
            }

            .form-header {
                margin-bottom: 22px;
            }

            .form-header .icon-circle {
                width: 46px;
                height: 46px;
                font-size: 18px;
            }

            .form-header h2 {
                font-size: 21px;
                line-height: 1.3;
            }

            .form-header p {
                font-size: 12px !important;
                line-height: 1.5;
            }

            .form-group {
                margin-bottom: 17px;
            }

            .form-group label {
                font-size: 12px;
                line-height: 1.4;
            }

            .form-control {
                min-height: 46px;
                padding: 11px 12px;
                font-size: 14px;
            }

            textarea.form-control {
                min-height: 150px;
                line-height: 1.6;
            }

            .grid-2 {
                grid-template-columns: 1fr;
                gap: 0;
            }

            .submit-btn {
                min-height: 48px;
                padding: 13px 18px;
                font-size: 14px;
                margin-top: 8px;
            }

            .alert-box {
                font-size: 12px;
                line-height: 1.5;
                overflow-wrap: anywhere;
            }
        }

        @media (max-width: 400px) {
            .container {
                padding: 0 10px;
                margin: 16px auto;
            }

            .form-card {
                padding: 20px 12px;
            }

            .form-header h2 {
                font-size: 19px;
            }

            .form-control {
                font-size: 13px;
            }

            textarea.form-control {
                min-height: 135px;
            }
        }

    </style>
</head>
<body>

    <div class="container">
        <a href="pets.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Pet Directory</a>

        <div class="form-card">
            <div class="form-header">
                <div class="icon-circle"><i class="fas fa-paw"></i></div>
                <h2>Edit Pet Profile</h2>
                <p style="color: var(--text-muted); font-size: 14px;">Update official pet profile and tags</p>
            </div>

            <?php if ($error_message !== ''): ?>
                <div class="alert-box alert-error"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <form action="" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="pet_id" value="<?php echo (int)$pet_id; ?>">

                <div class="form-group">
                    <label>Select Customer *</label>
                    <select name="customer_id" class="form-control" required>
                        <?php foreach ($customers as $customer): ?>
                            <?php $selected = ((string)$customer['id'] === (string)$pet_data['owner_id']) ? 'selected' : ''; ?>
                            <option value="<?php echo (int)$customer['id']; ?>" <?php echo $selected; ?>>
                                <?php echo htmlspecialchars($customer['full_name'] ?? ''); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="font-weight: 700; margin: 30px 0 15px; font-size: 15px;">Pet Information</div>

                <div class="grid-2">
                    <div class="form-group">
                        <label>Pet Name *</label>
                        <input type="text" name="pet_name" class="form-control" value="<?php echo htmlspecialchars($pet_data['name'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Pet Type *</label>
                        <select name="pet_type" class="form-control" required>
                            <option value="Dog" <?php echo (isset($pet_data['pet_type']) && $pet_data['pet_type'] === 'Dog') ? 'selected' : ''; ?>>Dog</option>
                            <option value="Cat" <?php echo (isset($pet_data['pet_type']) && $pet_data['pet_type'] === 'Cat') ? 'selected' : ''; ?>>Cat</option>
                            <option value="Other" <?php echo (isset($pet_data['pet_type']) && $pet_data['pet_type'] === 'Other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label>Breed *</label>
                        <input type="text" name="breed" class="form-control" value="<?php echo htmlspecialchars($pet_data['breed'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Age *</label>
                        <input type="text" inputmode="decimal" name="age" class="form-control" value="<?php echo htmlspecialchars($pet_data['age'] ?? ''); ?>" required>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label>Weight *</label>
                        <input type="text" inputmode="decimal" name="weight" class="form-control" value="<?php echo htmlspecialchars($pet_data['weight'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Gender *</label>
                        <select name="gender" class="form-control" required>
                            <option value="Male" <?php echo (($pet_data['gender'] ?? '') === 'Male') ? 'selected' : ''; ?>>Male</option>
                            <option value="Female" <?php echo (($pet_data['gender'] ?? '') === 'Female') ? 'selected' : ''; ?>>Female</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Medical History / Alerts</label>
                    <textarea name="medical_history" class="form-control" rows="4"><?php echo htmlspecialchars($pet_data['medical_history'] ?? ''); ?></textarea>
                </div>

                <button type="submit" class="submit-btn">Update Pet Record</button>
            </form>
        </div>
    </div>

</body>
</html>
