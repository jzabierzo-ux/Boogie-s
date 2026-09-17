<?php
session_start();

// 1. SECURITY: Only allow logged-in Admins
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: adminlogin.php");
    exit();
}

// 2. DATABASE CONNECTION
include('../db_connect.php'); 

$message = "";

// Check if an ID was passed in the URL
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: managepromo.php");
    exit();
}

$promo_id = intval($_GET['id']); // Using intval for extra security

// 3. HANDLE FORM SUBMISSION (UPDATE)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $tag = mysqli_real_escape_string($conn, $_POST['tag']);
    $title = mysqli_real_escape_string($conn, $_POST['title']);
    $description = mysqli_real_escape_string($conn, $_POST['description']);
    $theme_color = mysqli_real_escape_string($conn, $_POST['theme_color']);
    $expiry_date = mysqli_real_escape_string($conn, $_POST['expiry_date']);
    $status = mysqli_real_escape_string($conn, $_POST['status']);

    // Update the database with the new fields (button_text and target_url removed)
    $update_query = "UPDATE promos SET 
                     tag='$tag', 
                     title='$title', 
                     description='$description', 
                     theme_color='$theme_color', 
                     expiry_date='$expiry_date', 
                     status='$status' 
                     WHERE id='$promo_id'";
    
    if (mysqli_query($conn, $update_query)) {
        $message = "<div class='alert success'>Promo Card successfully updated! <a href='managepromo.php'>Back to Promos</a></div>";
    } else {
        $message = "<div class='alert error'>Error updating record: " . mysqli_error($conn) . "</div>";
    }
}

// 4. FETCH CURRENT PROMO DATA
$fetch_query = mysqli_query($conn, "SELECT * FROM promos WHERE id='$promo_id'");

// If promo doesn't exist, send them back to the dashboard
if (mysqli_num_rows($fetch_query) == 0) {
    header("Location: managepromo.php");
    exit();
}

$promo_data = mysqli_fetch_assoc($fetch_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Promo Card | Boogie's Pet Care</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --navy-dark: #001f3f;
            --admin-purple: #8b2cf5;
            --bg-light: #f4f7f6;
            --white: #ffffff;
            --text-main: #2d3436;
            --text-muted: #636e72;
        }

        body { font-family: 'Segoe UI', Tahoma, sans-serif; background-color: var(--bg-light); padding: 40px; display: flex; justify-content: center; }
        
        .form-container { background: var(--white); border-radius: 12px; padding: 40px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); width: 100%; max-width: 600px; }
        .form-container h2 { color: var(--navy-dark); margin-bottom: 5px; }
        .form-container p { color: var(--text-muted); font-size: 14px; margin-bottom: 25px; }
        
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: var(--text-main); margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 12px 15px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; box-sizing: border-box; outline: none; transition: border-color 0.3s; }
        .form-group textarea { resize: vertical; min-height: 80px; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: var(--admin-purple); }
        
        .btn-submit { background-color: var(--admin-purple); color: white; border: none; padding: 14px 24px; border-radius: 8px; font-size: 15px; font-weight: 700; cursor: pointer; width: 100%; transition: 0.3s; margin-top: 10px; }
        .btn-submit:hover { opacity: 0.9; transform: translateY(-2px); }
        
        .btn-back { display: block; text-align: center; margin-top: 15px; color: var(--text-muted); text-decoration: none; font-size: 14px; }
        .btn-back:hover { color: var(--admin-purple); text-decoration: underline; }

        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; font-weight: 600; text-align: center; }
        .alert.success { background-color: #d1fae5; color: #059669; border: 1px solid #10b981; }
        .alert.error { background-color: #fee2e2; color: #ef4444; border: 1px solid #f87171; }
        .alert a { color: inherit; text-decoration: underline; }
    </style>
</head>
<body>

    <div class="form-container">
        <h2>Edit Promo Card</h2>
        <p>Update the display details for this promotional card.</p>

        <?php echo $message; ?>

        <form action="editpromo.php?id=<?php echo $promo_id; ?>" method="POST">
            
            <div class="form-group">
                <label for="tag">Small Tag (e.g. SPECIAL OFFER)</label>
                <input type="text" id="tag" name="tag" value="<?php echo htmlspecialchars($promo_data['tag'] ?? ''); ?>" required>
            </div>

            <div class="form-group">
                <label for="title">Promo Title</label>
                <input type="text" id="title" name="title" value="<?php echo htmlspecialchars($promo_data['title'] ?? ''); ?>" required>
            </div>

            <div class="form-group">
                <label for="description">Description text</label>
                <textarea id="description" name="description" required><?php echo htmlspecialchars($promo_data['description'] ?? ''); ?></textarea>
            </div>

            <div class="form-group">
                <label for="theme_color">Color Theme</label>
                <select id="theme_color" name="theme_color" required>
                    <option value="purple" <?php echo (isset($promo_data['theme_color']) && $promo_data['theme_color'] == 'purple') ? 'selected' : ''; ?>>Purple</option>
                    <option value="teal" <?php echo (isset($promo_data['theme_color']) && $promo_data['theme_color'] == 'teal') ? 'selected' : ''; ?>>Teal</option>
                    <option value="red" <?php echo (isset($promo_data['theme_color']) && $promo_data['theme_color'] == 'red') ? 'selected' : ''; ?>>Red</option>
                    <option value="orange" <?php echo (isset($promo_data['theme_color']) && $promo_data['theme_color'] == 'orange') ? 'selected' : ''; ?>>Orange</option>
                </select>
            </div>

            <div class="form-group">
                <label for="expiry_date">Expiry Date</label>
                <input type="date" id="expiry_date" name="expiry_date" value="<?php echo htmlspecialchars($promo_data['expiry_date'] ?? ''); ?>" required>
            </div>

            <div class="form-group">
                <label for="status">Status</label>
                <select id="status" name="status" required>
                    <option value="active" <?php echo (isset($promo_data['status']) && $promo_data['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo (isset($promo_data['status']) && $promo_data['status'] == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>

            <button type="submit" class="btn-submit">Update Promo Card</button>
            <a href="managepromo.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        </form>
    </div>

</body>
</html>