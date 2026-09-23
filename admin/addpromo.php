<?php
session_start();

// 1. SECURITY: Only allow logged-in Admins
if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    ($_SESSION['role'] ?? '') !== 'admin'
) {
    header("Location: ../admin_login.php");
    exit();
}

// 2. DATABASE CONNECTION
include('../db_supabase.php');

$message = "";

// 3. HANDLE FORM SUBMISSION
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Get and trim form inputs
    $tag = trim($_POST['tag'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $theme_color = trim($_POST['theme_color'] ?? '');
    $expiry_date = trim($_POST['expiry_date'] ?? '');
    $status = trim($_POST['status'] ?? '');

    // Validate required fields
    if (
        $tag === '' ||
        $title === '' ||
        $description === '' ||
        $theme_color === '' ||
        $expiry_date === '' ||
        $status === ''
    ) {
        $message = "<div class='alert error'>Please complete all required fields.</div>";
    } elseif (!in_array($theme_color, ['purple', 'teal', 'red', 'orange'], true)) {
        $message = "<div class='alert error'>Invalid color theme selected.</div>";
    } elseif (!in_array($status, ['active', 'inactive'], true)) {
        $message = "<div class='alert error'>Invalid promo status selected.</div>";
    } else {
        try {
            // Insert into Supabase/PostgreSQL using a prepared statement
            $insert_query = "
                INSERT INTO promos
                    (tag, title, description, theme_color, expiry_date, status)
                VALUES
                    (:tag, :title, :description, :theme_color, :expiry_date, :status)
            ";

            $stmt = $pdo->prepare($insert_query);
            $stmt->execute([
                ':tag' => $tag,
                ':title' => $title,
                ':description' => $description,
                ':theme_color' => $theme_color,
                ':expiry_date' => $expiry_date,
                ':status' => $status
            ]);

            $message = "<div class='alert success'>Promo card successfully created! <a href='managepromo.php'>Back to Promos</a></div>";
        } catch (PDOException $e) {
            // Keep database details out of the browser.
            $message = "<div class='alert error'>Unable to create the promo card. Please try again.</div>";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Promo | Boogie's Pet Care</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root{
            --navy-dark:#001f3f;
            --admin-purple:#8b2cf5;
            --bg-light:#f4f7f6;
            --white:#ffffff;
            --text-main:#2d3436;
            --text-muted:#636e72;
            --border:#e2e8f0;
        }
        *{box-sizing:border-box}
        html{scroll-behavior:smooth}
        body{
            font-family:'Segoe UI',Tahoma,sans-serif;
            background:var(--bg-light);
            min-height:100vh;
            margin:0;
            padding:40px 20px;
            display:flex;
            justify-content:center;
            align-items:flex-start;
        }
        .form-container{
            background:var(--white);
            border-radius:14px;
            padding:40px;
            box-shadow:0 4px 15px rgba(0,0,0,.05);
            width:100%;
            max-width:600px;
            border:1px solid rgba(226,232,240,.8);
        }
        .form-container h2{
            color:var(--navy-dark);
            margin:0 0 6px;
            font-size:26px;
            line-height:1.25;
        }
        .form-container > p{
            color:var(--text-muted);
            font-size:14px;
            margin:0 0 25px;
            line-height:1.6;
        }
        .form-row{
            display:flex;
            gap:15px;
        }
        .form-row .form-group{flex:1;min-width:0}
        .form-group{margin-bottom:20px}
        .form-group label{
            display:block;
            font-size:13px;
            font-weight:600;
            color:var(--text-main);
            margin-bottom:8px;
            text-transform:uppercase;
            letter-spacing:.5px;
        }
        .form-group input,
        .form-group select,
        .form-group textarea{
            width:100%;
            min-height:46px;
            padding:12px 15px;
            border:1px solid var(--border);
            border-radius:8px;
            font-size:14px;
            outline:none;
            transition:border-color .2s,box-shadow .2s;
            font-family:inherit;
            background:#fff;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus{
            border-color:var(--admin-purple);
            box-shadow:0 0 0 3px rgba(139,44,245,.10);
        }
        .form-group textarea{
            resize:vertical;
            min-height:110px;
            line-height:1.5;
        }
        .btn-submit{
            min-height:48px;
            background-color:var(--admin-purple);
            color:#fff;
            border:none;
            padding:14px 24px;
            border-radius:8px;
            font-size:15px;
            font-weight:700;
            cursor:pointer;
            width:100%;
            transition:.2s;
            margin-top:5px;
            touch-action:manipulation;
        }
        .btn-submit:hover{opacity:.92;transform:translateY(-1px)}
        .btn-back{
            display:block;
            text-align:center;
            margin-top:15px;
            color:var(--text-muted);
            text-decoration:none;
            font-size:14px;
            padding:8px;
        }
        .btn-back:hover{color:var(--admin-purple);text-decoration:underline}
        .alert{
            padding:14px 15px;
            border-radius:8px;
            margin-bottom:20px;
            font-size:14px;
            font-weight:600;
            text-align:center;
            line-height:1.5;
            overflow-wrap:anywhere;
        }
        .alert.success{background:#d1fae5;color:#059669;border:1px solid #10b981}
        .alert.error{background:#fee2e2;color:#ef4444;border:1px solid #f87171}
        .alert a{color:inherit;text-decoration:underline}
        @media(max-width:680px){
            body{padding:22px 14px}
            .form-container{
                padding:24px 18px;
                border-radius:12px;
            }
            .form-container h2{font-size:22px}
            .form-container > p{font-size:13px;margin-bottom:21px}
            .form-row{
                display:grid;
                grid-template-columns:1fr;
                gap:0;
            }
            .form-group{margin-bottom:17px}
            .form-group label{font-size:11px;margin-bottom:7px}
            .form-group input,
            .form-group select,
            .form-group textarea{
                min-height:47px;
                font-size:14px;
                padding:12px 13px;
            }
            .form-group textarea{min-height:125px}
            .btn-submit{min-height:50px;font-size:15px}
            .btn-back{font-size:13px;margin-top:13px}
            .alert{font-size:13px}
        }
        @media(max-width:380px){
            body{padding:14px 10px}
            .form-container{padding:20px 14px}
            .form-container h2{font-size:20px}
            .form-container > p{font-size:12px}
            .form-group input,
            .form-group select,
            .form-group textarea{font-size:13px}
        }
    </style>
</head>
<body>

    <div class="form-container">
        <h2>Create Promo Announcement</h2>
        <p>Fill out the details below to generate a new promotional announcement card.</p>

        <?php echo $message; ?>

        <form action="addpromo.php" method="POST">

            <div class="form-row">
                <div class="form-group">
                    <label for="tag">Card Tag</label>
                    <input type="text" id="tag" name="tag" placeholder="e.g. Rainy Day Vet" required>
                </div>

                <div class="form-group">
                    <label for="theme_color">Color Theme</label>
                    <select id="theme_color" name="theme_color" required>
                        <option value="purple">Purple</option>
                        <option value="teal">Teal</option>
                        <option value="red">Red</option>
                        <option value="orange">Orange</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="title">Promo Main Headline</label>
                <input type="text" id="title" name="title" placeholder="e.g. Immune Booster" required>
            </div>

            <div class="form-group">
                <label for="description">Promo Description</label>
                <textarea id="description" name="description" placeholder="e.g. Flu season isn't just for humans. Protect your furry friends..." required></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="expiry_date">Expiry Date</label>
                    <input type="date" id="expiry_date" name="expiry_date" required>
                </div>

                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status" required>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>

            <button type="submit" class="btn-submit">Save Promo Card</button>
            <a href="managepromo.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        </form>
    </div>

</body>
</html>
