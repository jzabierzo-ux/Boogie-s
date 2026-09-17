<?php
session_start();
include '../db_connect.php';

// --- UNIVERSAL SECURITY CHECK ---
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';

if (!isset($_SESSION['logged_in']) || !in_array($current_role, ['admin', 'supervisor', 'staff'])) {
    header("Location: stafflogin.php");
    exit();
}

// 2. CHECK ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid Pet ID.");
}

$pet_id = $_GET['id'];
$success_msg = "";
$error_msg = "";

// --- BAGO: Fetch all users for Transfer of Ownership dropdown ---
$users_list = [];
$users_query = mysqli_query($conn, "SELECT id, full_name, email FROM users ORDER BY full_name ASC");
if ($users_query) {
    while ($u = mysqli_fetch_assoc($users_query)) {
        $users_list[] = $u;
    }
}

// 3. HANDLE FORM SUBMISSION (UPDATE)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p_name = $_POST['name'];
    $p_type = $_POST['pet_type'];
    $p_breed = $_POST['breed'];
    $p_gender = $_POST['gender'];
    $p_age = $_POST['age'];
    $p_weight = $_POST['weight'];
    
    // BAGO: Kunin ang ID ng bagong owner mula sa dropdown
    $new_owner_id = $_POST['owner_id'];
    $new_owner_name = "";

    // Hanapin yung pangalan nung piniling owner id para i-save din sa owner_name column
    foreach ($users_list as $u) {
        if ($u['id'] == $new_owner_id) {
            $new_owner_name = $u['full_name'];
            break;
        }
    }

    // BAGO: In-update ang query para isama ang owner_id at owner_name
    $update_query = "UPDATE pets SET name=?, pet_type=?, breed=?, gender=?, age=?, weight=?, owner_id=?, owner_name=? WHERE id=?";
    $update_stmt = mysqli_prepare($conn, $update_query);
    
    // "ssssssisi" -> 6 strings, 1 integer (owner_id), 1 string (owner_name), 1 integer (pet_id)
    mysqli_stmt_bind_param($update_stmt, "ssssssisi", $p_name, $p_type, $p_breed, $p_gender, $p_age, $p_weight, $new_owner_id, $new_owner_name, $pet_id);
    
    if (mysqli_stmt_execute($update_stmt)) {
        $success_msg = "Pet record and ownership successfully updated!";
    } else {
        $error_msg = "Error updating record: " . mysqli_error($conn);
    }
}

// 4. FETCH CURRENT DATA
$query = "SELECT * FROM pets WHERE id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $pet_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (!$row = mysqli_fetch_assoc($result)) {
    die("Pet not found in the database.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Pet Record | Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --brand-yellow: #ffcc00;
            --navy-dark: #001f3f;
            --bg-light: #f4f7f6;
            --white: #ffffff;
            --text-main: #2d3436;
            --text-muted: #64748b;
            --border: #e2e8f0;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Poppins', sans-serif;}

        body {
            background-color: var(--bg-light);
            color: var(--text-main);
            padding: 40px;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 100vh;
        }

        .container { 
            width: 100%; 
            max-width: 800px; 
        }
        
        .header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 25px; 
        }
        
        .btn-back { 
            background: var(--white); 
            color: var(--navy-dark); 
            padding: 10px 20px; 
            border-radius: 8px; 
            text-decoration: none; 
            font-weight: 700; 
            display: inline-flex; 
            align-items: center; 
            gap: 8px; 
            transition: 0.3s; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.05); 
            font-size: 14px;
        }
        .btn-back:hover { 
            background: var(--navy-dark); 
            color: var(--brand-yellow); 
            transform: translateY(-2px); 
            box-shadow: 0 6px 12px rgba(0,31,63,0.15);
        }

        .card { 
            background: var(--white); 
            padding: 40px; 
            border-radius: 16px; 
            box-shadow: 0 4px 10px rgba(0,0,0,0.03); 
            border: 1px solid var(--border); 
            border-top: 5px solid var(--navy-dark);
        }
        
        .card-title { 
            font-size: 22px; 
            color: var(--navy-dark); 
            font-weight: 800; 
            margin-bottom: 25px; 
            display: flex; 
            align-items: center; 
            gap: 12px; 
            border-bottom: 2px solid #f8fafc; 
            padding-bottom: 15px; 
        }
        .card-title i { color: var(--brand-yellow); }

        .form-grid { 
            display: grid; 
            grid-template-columns: 1fr 1fr; 
            gap: 20px; 
        }
        .form-group { margin-bottom: 15px; }
        .form-group.full-width { grid-column: span 2; }
        
        label { 
            display: block; 
            font-size: 12px; 
            font-weight: 700; 
            color: var(--navy-dark); 
            margin-bottom: 8px; 
            text-transform: uppercase; 
            letter-spacing: 0.5px;
        }
        
        input[type="text"], input[type="number"], select, textarea {
            width: 100%; 
            padding: 12px 15px; 
            border: 1px solid var(--border); 
            border-radius: 8px; 
            font-size: 14px; 
            color: var(--text-main); 
            outline: none; 
            font-family: 'Poppins', sans-serif; 
            box-sizing: border-box;
            background: #f8fafc;
            transition: 0.2s;
        }
        
        input:focus:not([readonly]), select:focus, textarea:focus { 
            background: var(--white); 
            border-color: var(--navy-dark); 
            box-shadow: 0 0 0 3px rgba(0, 31, 63, 0.1); 
        }
        
        textarea { resize: vertical; min-height: 120px; }
        
        /* Readonly Styling */
        input[readonly] {
            background-color: #f1f5f9;
            color: #94a3b8;
            cursor: not-allowed;
            font-weight: 600;
            border: 1px dashed #cbd5e1;
        }

        .btn-save { 
            background: var(--navy-dark); 
            color: var(--brand-yellow); 
            padding: 14px 25px; 
            border: none; 
            border-radius: 8px; 
            font-size: 15px; 
            font-weight: 700; 
            cursor: pointer; 
            transition: 0.3s; 
            width: 100%; 
            margin-top: 20px; 
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .btn-save:hover { 
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.15);
            opacity: 0.95;
        }

        .alert { 
            padding: 15px 20px; 
            border-radius: 8px; 
            margin-bottom: 25px; 
            font-weight: 600; 
            font-size: 14px; 
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        
        @media (max-width: 600px) {
            .form-grid { grid-template-columns: 1fr; }
            .form-group.full-width { grid-column: span 1; }
            .container { padding: 0; }
        }
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
                <i class="fas fa-edit"></i> Edit Pet Profile
            </div>

            <form action="" method="POST">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Pet Name</label>
                        <input type="text" name="name" value="<?php echo htmlspecialchars($row['name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Pet Type</label>
                        <input type="text" name="pet_type" value="<?php echo htmlspecialchars($row['pet_type']); ?>" placeholder="e.g. Dog, Cat" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Breed</label>
                        <input type="text" name="breed" value="<?php echo htmlspecialchars($row['breed']); ?>">
                    </div>
                    <div class="form-group">
                        <label>Gender</label>
                        <select name="gender" required>
                            <option value="Male" <?php if($row['gender'] == 'Male') echo 'selected'; ?>>Male</option>
                            <option value="Female" <?php if($row['gender'] == 'Female') echo 'selected'; ?>>Female</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Age (Years)</label>
                        <input type="text" name="age" value="<?php echo htmlspecialchars($row['age']); ?>">
                    </div>
                    <div class="form-group">
                        <label>Weight</label>
                        <input type="text" name="weight" value="<?php echo htmlspecialchars($row['weight']); ?>" placeholder="e.g. 5kg">
                    </div>

                    <div class="form-group full-width">
                        <label>Owner Name (Transfer Ownership)</label>
                        <select name="owner_id" required>
                            <option value="">-- Select New Owner --</option>
                            <?php foreach ($users_list as $user): ?>
                                <option value="<?php echo $user['id']; ?>" <?php echo (isset($row['owner_id']) && $row['owner_id'] == $user['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['full_name']) . ' (' . htmlspecialchars($user['email']) . ')'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: #0369a1; font-size: 11px; margin-top: 6px; display: flex; align-items: center; gap: 5px; font-weight: 500;">
                            <i class="fas fa-exchange-alt" style="color: #0284c7;"></i> You can reassign this pet to a different customer. Medical history transfers automatically.
                        </small>
                    </div>
                </div>

                <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Changes</button>
            </form>
        </div>
    </div>

</body>
</html> 