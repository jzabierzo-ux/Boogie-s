<?php
session_start();
// Make sure this points to your actual database connection file
include '../db_connect.php'; 

// Check if staff is logged in (Adjust session variable if yours is named differently)
if (!isset($_SESSION['staff_id']) && !isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // 1. Grab all the data from the form
    $pet_id = mysqli_real_escape_string($conn, $_POST['pet_id']);
    
    // We map the form's 'customer_id' input to the '$owner_id' variable
    $owner_id = mysqli_real_escape_string($conn, $_POST['customer_id']); 
    
    $pet_name = mysqli_real_escape_string($conn, $_POST['pet_name']);
    $pet_type = mysqli_real_escape_string($conn, $_POST['pet_type']);
    $breed = mysqli_real_escape_string($conn, $_POST['breed']);
    $age = mysqli_real_escape_string($conn, $_POST['age']);
    $weight = mysqli_real_escape_string($conn, $_POST['weight']);
    $gender = mysqli_real_escape_string($conn, $_POST['gender']);
    $medical_history = mysqli_real_escape_string($conn, $_POST['medical_history']);
    
    // If the staff is approving an edit request, set status back to Approved/Active
    $status = 'Approved'; 

    // 2. THE FIX: Update the database using 'owner_id' instead of 'customer_id'
    $update_query = "UPDATE pets SET 
                        owner_id = '$owner_id', 
                        name = '$pet_name', 
                        pet_type = '$pet_type', 
                        breed = '$breed', 
                        age = '$age', 
                        weight = '$weight', 
                        gender = '$gender', 
                        medical_history = '$medical_history',
                        status = '$status'
                     WHERE id = '$pet_id'";

    // 3. Execute and redirect
    if (mysqli_query($conn, $update_query)) {
        echo "<script>
                alert('Pet details successfully updated and approved!'); 
                window.location.href='dashboard.php'; 
              </script>";
        exit;
    } else {
        // This will print any new database errors so we can catch them
        echo "<script>alert('Database Error: " . mysqli_error($conn) . "');</script>";
    }
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

            <form action="" method="POST">
                
                <div class="form-group">
                    <label>Select Customer *</label>
                    <select name="customer_id" class="form-control" required>
                        <?php
                        $cust_query = "SELECT id, full_name FROM users WHERE role = 'customer'";
                        $cust_result = mysqli_query($conn, $cust_query);
                        while($row = mysqli_fetch_assoc($cust_result)) {
                            $selected = ($row['id'] == $pet_data['customer_id']) ? 'selected' : '';
                            echo "<option value='{$row['id']}' $selected>{$row['full_name']}</option>";
                        }
                        ?>
                    </select>
                </div>

                <div style="font-weight: 700; margin: 30px 0 15px; font-size: 15px;">Pet Information</div>

                <div class="grid-2">
                    <div class="form-group">
                        <label>Pet Name *</label>
                        <input type="text" name="pet_name" class="form-control" value="<?php echo htmlspecialchars($pet_data['name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Pet Type *</label>
                        <select name="pet_type" class="form-control" required>
                            <option value="Dog" <?php echo (isset($pet_data['pet_type']) && $pet_data['pet_type'] == 'Dog') ? 'selected' : ''; ?>>Dog</option>
                            <option value="Cat" <?php echo (isset($pet_data['pet_type']) && $pet_data['pet_type'] == 'Cat') ? 'selected' : ''; ?>>Cat</option>
                            <option value="Other" <?php echo (isset($pet_data['pet_type']) && $pet_data['pet_type'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label>Breed *</label>
                        <input type="text" name="breed" class="form-control" value="<?php echo htmlspecialchars($pet_data['breed']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Age *</label>
                        <input type="text" name="age" class="form-control" value="<?php echo htmlspecialchars($pet_data['age']); ?>" required>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label>Weight *</label>
                        <input type="text" name="weight" class="form-control" value="<?php echo htmlspecialchars($pet_data['weight']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Gender *</label>
                        <select name="gender" class="form-control" required>
                            <option value="Male" <?php echo ($pet_data['gender'] == 'Male') ? 'selected' : ''; ?>>Male</option>
                            <option value="Female" <?php echo ($pet_data['gender'] == 'Female') ? 'selected' : ''; ?>>Female</option>
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