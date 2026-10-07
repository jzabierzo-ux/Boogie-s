<?php

session_start();

// SECURITY: Only allow logged-in Admins

if (

    !isset($_SESSION['logged_in']) ||

    $_SESSION['logged_in'] !== true ||

    ($_SESSION['role'] ?? '') !== 'admin'

) {

    header("Location: ../admin_login.php");

    exit();

}

// DATABASE CONNECTION

include('../db_supabase.php');

$message = "";

// --- SHARED ADMIN HEADER DATA ---

$current_role = isset($_SESSION['role']) ? strtolower(trim((string)$_SESSION['role'])) : '';

$admin_full_name = $_SESSION['user_name'] ?? 'Admin';

$profile_img_path = '';

$first_name = 'Admin';

$admin_notifications = [];

$unread_count = 0;

if (isset($_SESSION['user_id'])) {

    try {

        $profile_stmt = $pdo->prepare("

            SELECT full_name, profile_image

            FROM users

            WHERE id = :id

            LIMIT 1

        ");

        $profile_stmt->execute([':id' => (int)$_SESSION['user_id']]);

        $profile_data = $profile_stmt->fetch(PDO::FETCH_ASSOC);

        if ($profile_data) {

            $admin_full_name = $profile_data['full_name'] ?? $admin_full_name;

            $profile_img_path = $profile_data['profile_image'] ?? '';

            $_SESSION['user_name'] = $admin_full_name;

            $first_name = trim(explode(' ', $admin_full_name)[0], ',');

        }

    } catch (PDOException $e) {

        $first_name = trim(explode(' ', $admin_full_name)[0], ',');

    }

}

try {

    $admin_notif_stmt = $pdo->prepare("

        SELECT id, message, created_at

        FROM admin_notifications

        WHERE is_read = 0

        ORDER BY created_at DESC

        LIMIT 20

    ");

    $admin_notif_stmt->execute();

    $admin_notifications = $admin_notif_stmt->fetchAll(PDO::FETCH_ASSOC);

    $unread_count = count($admin_notifications);

} catch (PDOException $e) {

    $admin_notifications = [];

    $unread_count = 0;

}

$presets = [

    [

        'name' => 'Grooming Special',

        'icon' => 'fa-scissors',

        'tag' => 'GROOMING DEAL',

        'title' => 'Fresh Look, Happy Pup',

        'description' => 'Enjoy a special grooming offer for your furry friend. Book a grooming session today.',

        'theme' => 'purple'

    ],

    [

        'name' => 'Vet Wellness',

        'icon' => 'fa-stethoscope',

        'tag' => 'VET WELLNESS',

        'title' => 'Wellness Check Promo',

        'description' => 'Keep your pet healthy with a convenient veterinary wellness consultation.',

        'theme' => 'teal'

    ],

    [

        'name' => 'Pet Hotel',

        'icon' => 'fa-hotel',

        'tag' => 'PET HOTEL',

        'title' => 'Stay More, Save More',

        'description' => 'Planning a trip? Give your pet a comfortable stay while you are away.',

        'theme' => 'orange'

    ],

    [

        'name' => 'Birthday Promo',

        'icon' => 'fa-cake-candles',

        'tag' => 'BIRTHDAY PAW-TY',

        'title' => 'Birthday Treat for Your Pet',

        'description' => 'Celebrate your pet\'s special month with an exclusive Boogie\'s birthday offer.',

        'theme' => 'red'

    ]

];

// HANDLE FORM SUBMISSION

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $tag = trim($_POST['tag'] ?? '');

    $title = trim($_POST['title'] ?? '');

    $description = trim($_POST['description'] ?? '');

    $theme_color = trim($_POST['theme_color'] ?? '');

    $expiry_date = trim($_POST['expiry_date'] ?? '');

    $status = trim($_POST['status'] ?? '');

    $image_url = null;

    // IMAGE UPLOAD

    if (isset($_FILES['promo_image']) && $_FILES['promo_image']['error'] !== UPLOAD_ERR_NO_FILE) {

        $upload = $_FILES['promo_image'];

        $allowed_mimes = [

            'image/jpeg' => 'jpg',

            'image/png'  => 'png',

            'image/webp' => 'webp'

        ];

        $max_size = 5 * 1024 * 1024; // 5 MB

        if ($upload['error'] !== UPLOAD_ERR_OK) {

            $message = "<div class='alert error'><i class='fa-solid fa-circle-exclamation'></i> Image upload failed.</div>";

        } elseif ($upload['size'] > $max_size) {

            $message = "<div class='alert error'><i class='fa-solid fa-circle-exclamation'></i> Image must be 5 MB or smaller.</div>";

        } else {

            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            $mime = $finfo ? finfo_file($finfo, $upload['tmp_name']) : '';

            if ($finfo) {

                finfo_close($finfo);

            }

            if (!isset($allowed_mimes[$mime])) {

                $message = "<div class='alert error'><i class='fa-solid fa-circle-exclamation'></i> Please upload a JPG, PNG, or WEBP image.</div>";

            } else {

                $upload_dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'promos' . DIRECTORY_SEPARATOR;

                if (!is_dir($upload_dir)) {

                    @mkdir($upload_dir, 0755, true);

                }

                if (!is_dir($upload_dir) || !is_writable($upload_dir)) {

                    $message = "<div class='alert error'><i class='fa-solid fa-circle-exclamation'></i> Promo upload folder is not writable. Create <strong>uploads/promos</strong> inside the project folder.</div>";

                } else {

                    try {

                        $random_name = bin2hex(random_bytes(12)) . '.' . $allowed_mimes[$mime];

                    } catch (Exception $e) {

                        $random_name = uniqid('promo_', true) . '.' . $allowed_mimes[$mime];

                    }

                    $destination = $upload_dir . $random_name;

                    if (move_uploaded_file($upload['tmp_name'], $destination)) {

                        $image_url = 'uploads/promos/' . $random_name;

                    } else {

                        $message = "<div class='alert error'><i class='fa-solid fa-circle-exclamation'></i> Unable to save the uploaded image.</div>";

                    }

                }

            }

        }

    }

    // VALIDATE FIELDS

    if ($message === "" && (

        $tag === '' ||

        $title === '' ||

        $description === '' ||

        $theme_color === '' ||

        $expiry_date === '' ||

        $status === ''

    )) {

        $message = "<div class='alert error'><i class='fa-solid fa-circle-exclamation'></i> Please complete all required fields.</div>";

    }

    if ($message === "" && !in_array($theme_color, ['purple', 'teal', 'red', 'orange'], true)) {

        $message = "<div class='alert error'><i class='fa-solid fa-circle-exclamation'></i> Invalid color theme selected.</div>";

    }

    if ($message === "" && !in_array($status, ['active', 'inactive'], true)) {

        $message = "<div class='alert error'><i class='fa-solid fa-circle-exclamation'></i> Invalid promo status selected.</div>";

    }

    // SAVE PROMO

    if ($message === "") {

        try {

            $insert_query = "

                INSERT INTO promos

                    (tag, title, description, theme_color, expiry_date, status, image_url)

                VALUES

                    (:tag, :title, :description, :theme_color, :expiry_date, :status, :image_url)

            ";

            $stmt = $pdo->prepare($insert_query);

            $stmt->execute([

                ':tag' => $tag,

                ':title' => $title,

                ':description' => $description,

                ':theme_color' => $theme_color,

                ':expiry_date' => $expiry_date,

                ':status' => $status,

                ':image_url' => $image_url

            ]);

            $message = "<div class='alert success'><i class='fa-solid fa-circle-check'></i> Promo published successfully. <a href='managepromo.php'>View Promotions</a></div>";

            // Clear form values after successful save

            $_POST = [];

        } catch (PDOException $e) {

            // Delete newly-uploaded file when database insert fails

            if ($image_url) {

                $uploaded_file = dirname(__DIR__) . DIRECTORY_SEPARATOR . ltrim(str_replace('../', '', $image_url), '/\\\\');

                if (is_file($uploaded_file)) {

                    @unlink($uploaded_file);

                }

            }

            $message = "<div class='alert error'><i class='fa-solid fa-circle-exclamation'></i> Unable to save the promo. Make sure the <strong>image_url</strong> column has been added to the promos table.</div>";

            error_log('Create promo error: ' . $e->getMessage());

        }

    }

}

$today = date('Y-m-d');

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Add Promotion | Boogie's Pet Care</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>

        :root{

            --navy:#001f3f;

            --blue:#0b3b66;

            --yellow:#ffcc00;

            --purple:#0b3b66;

            --bg:#f5f7fb;

            --white:#fff;

            --text:#172033;

            --muted:#6b7280;

            --border:#e4e8ef;

            --shadow:0 16px 40px rgba(0,31,63,.08);

        }

        *{box-sizing:border-box}

        body{

            margin:0;

            min-height:100vh;

            font-family:'Segoe UI',Tahoma,sans-serif;

            background:

                radial-gradient(circle at top left, rgba(255,204,0,.12), transparent 28%),

                radial-gradient(circle at top right, rgba(11,59,102,.10), transparent 28%),

                var(--bg);

            color:var(--text);

        }

        .topbar{

            background:var(--navy);

            color:#fff;

            padding:16px 28px;

            display:flex;

            justify-content:space-between;

            align-items:center;

            gap:15px;

            box-shadow:0 6px 18px rgba(0,0,0,.08);

        }

        .brand{

            display:flex;

            align-items:center;

            gap:12px;

        }

        .brand-icon{

            width:42px;

            height:42px;

            display:grid;

            place-items:center;

            background:var(--yellow);

            color:var(--navy);

            border-radius:12px;

            font-size:18px;

        }

        .brand strong{display:block;font-size:16px}

        .brand span{display:block;color:#cbd5e1;font-size:12px;margin-top:2px}

        .page{

            max-width:1180px;

            margin:30px auto;

            padding:0 18px 40px;

        }

        .page-heading{

            margin-bottom:22px;

        }

        .eyebrow{

            display:inline-flex;

            align-items:center;

            gap:7px;

            background:#fff;

            border:1px solid var(--border);

            border-radius:999px;

            padding:7px 11px;

            color:var(--purple);

            font-size:11px;

            font-weight:800;

            text-transform:uppercase;

            letter-spacing:.5px;

            box-shadow:0 5px 16px rgba(0,0,0,.03);

        }

        h1{margin:12px 0 5px;color:var(--navy);font-size:30px}

        .subtitle{margin:0;color:var(--muted);font-size:14px}

        .layout{

            display:grid;

            grid-template-columns:minmax(0,1.25fr) minmax(320px,.75fr);

            gap:22px;

            align-items:start;

        }

        .card{

            background:#fff;

            border:1px solid var(--border);

            border-radius:20px;

            box-shadow:var(--shadow);

        }

        .form-card{padding:24px}

        .preview-card{

            position:sticky;

            top:18px;

            padding:18px;

        }

        .card-title{

            display:flex;

            align-items:center;

            justify-content:space-between;

            gap:10px;

            margin-bottom:18px;

        }

        .card-title h2{

            margin:0;

            color:var(--navy);

            font-size:18px;

        }

        .mini-note{font-size:11px;color:var(--muted)}

        .section-label{

            display:block;

            margin:18px 0 9px;

            color:var(--navy);

            font-size:12px;

            font-weight:800;

            letter-spacing:.45px;

            text-transform:uppercase;

        }

        .input, select, textarea{

            width:100%;

            border:1px solid var(--border);

            border-radius:12px;

            background:#fff;

            color:var(--text);

            font:inherit;

            font-size:14px;

            outline:none;

            transition:.18s ease;

        }

        .input, select{

            min-height:46px;

            padding:11px 13px;

        }

        textarea{

            min-height:126px;

            padding:12px 13px;

            resize:vertical;

            line-height:1.55;

        }

        .input:focus, select:focus, textarea:focus{

            border-color:var(--purple);

            box-shadow:0 0 0 4px rgba(11,59,102,.10);

        }

        .row{

            display:grid;

            grid-template-columns:1fr 1fr;

            gap:14px;

        }

        .preset-grid{

            display:grid;

            grid-template-columns:repeat(2,minmax(0,1fr));

            gap:14px;

        }

        .preset{

            position:relative;

            width:100%;

            min-height:132px;

            border:1px solid #dfe5ec;

            border-radius:18px;

            background:#fff;

            padding:16px;

            cursor:pointer;

            text-align:left;

            overflow:hidden;

            transition:transform .2s ease, border-color .2s ease, box-shadow .2s ease;

        }

        .preset::before{

            content:'';

            position:absolute;

            left:0;

            top:0;

            right:0;

            height:5px;

            background:#cbd5e1;

        }

        .preset:hover{

            transform:translateY(-3px);

            border-color:#cbd5e1;

            box-shadow:0 12px 25px rgba(0,31,63,.09);

        }

        .preset.active{

            border-color:var(--navy);

            box-shadow:0 0 0 3px rgba(0,31,63,.10), 0 12px 25px rgba(0,31,63,.08);

        }

        .preset.active::after{

            content:'SELECTED';

            position:absolute;

            top:13px;

            right:13px;

            padding:4px 7px;

            border-radius:999px;

            background:var(--yellow);

            color:var(--navy);

            font-size:8px;

            font-weight:900;

            letter-spacing:.5px;

        }

        .preset[data-theme="purple"]::before{background:#0b3b66}

        .preset[data-theme="teal"]::before{background:#14b8a6}

        .preset[data-theme="orange"]::before{background:#f97316}

        .preset[data-theme="red"]::before{background:#ef4444}

        .preset-top{

            display:flex;

            align-items:center;

            justify-content:space-between;

            gap:10px;

            margin-bottom:12px;

        }

        .preset-icon{

            width:40px;

            height:40px;

            border-radius:12px;

            display:grid;

            place-items:center;

            background:#f4f7fb;

            color:var(--navy);

            border:1px solid #e8edf3;

            font-size:16px;

        }

        .preset[data-theme="purple"] .preset-icon{background:#eef4f9;color:#0b3b66}

        .preset[data-theme="teal"] .preset-icon{background:#e8fbf8;color:#0f9488}

        .preset[data-theme="orange"] .preset-icon{background:#fff3e7;color:#d97706}

        .preset[data-theme="red"] .preset-icon{background:#fff0f1;color:#dc2626}

        .preset-check{

            width:25px;

            height:25px;

            display:grid;

            place-items:center;

            border-radius:8px;

            border:1px solid #e2e8f0;

            color:transparent;

            font-size:11px;

        }

        .preset.active .preset-check{

            background:var(--navy);

            border-color:var(--navy);

            color:var(--yellow);

        }

        .preset strong{display:block;font-size:14px;color:var(--navy);font-weight:800}

        .preset small{display:block;color:var(--muted);font-size:11px;line-height:1.45;margin-top:4px;max-width:92%}

        .preset-use{

            display:inline-flex;

            align-items:center;

            gap:5px;

            margin-top:12px;

            color:#64748b;

            font-size:10px;

            font-weight:800;

            text-transform:uppercase;

            letter-spacing:.4px;

        }

        .preset:hover .preset-use{color:var(--navy)}

        .upload-box{

            border:1.5px dashed #cfd6e2;

            border-radius:16px;

            padding:15px;

            background:#fbfcfe;

        }

        .upload-box input[type=file]{display:none}

        .upload-label{

            display:flex;

            align-items:center;

            justify-content:center;

            gap:9px;

            min-height:52px;

            border:1px solid var(--border);

            border-radius:12px;

            background:#fff;

            color:var(--navy);

            font-weight:700;

            cursor:pointer;

        }

        .upload-help{font-size:11px;color:var(--muted);margin:9px 2px 0}

        .image-preview{display:none;margin-top:12px;border-radius:14px;overflow:hidden;background:#eef2f7}

        .image-preview img{width:100%;height:auto;object-fit:contain;display:block}

        .theme-options{

            display:grid;

            grid-template-columns:repeat(4,1fr);

            gap:9px;

        }

        .theme-option{position:relative}

        .theme-option input{position:absolute;opacity:0;pointer-events:none}

        .theme-pill{

            display:flex;

            align-items:center;

            justify-content:center;

            gap:8px;

            min-height:44px;

            border:1px solid var(--border);

            border-radius:12px;

            background:#fff;

            cursor:pointer;

            font-size:12px;

            font-weight:700;

            color:var(--text);

        }

        .theme-option input:checked + .theme-pill{

            border-color:var(--purple);

            box-shadow:0 0 0 3px rgba(11,59,102,.10);

        }

        .dot{width:10px;height:10px;border-radius:999px;display:inline-block}

        .dot.purple{background:#0b3b66}.dot.teal{background:#14b8a6}.dot.red{background:#ef4444}.dot.orange{background:#f97316}

        .action-row{

            display:flex;

            gap:10px;

            margin-top:22px;

        }

        .btn{

            min-height:48px;

            border-radius:12px;

            padding:12px 17px;

            font-weight:800;

            font-size:14px;

            text-decoration:none;

            display:inline-flex;

            justify-content:center;

            align-items:center;

            gap:8px;

            cursor:pointer;

            border:0;

        }

        .btn-primary{background:var(--purple);color:#fff;flex:1}

        .btn-primary:hover{filter:brightness(.96);transform:translateY(-1px)}

        .btn-secondary{background:#fff;color:var(--navy);border:1px solid var(--border)}

        .alert{

            padding:13px 15px;

            border-radius:12px;

            font-size:13px;

            margin-bottom:17px;

            line-height:1.5;

        }

        .alert.success{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}

        .alert.error{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}

        .alert a{color:inherit;font-weight:800}

        /* Customer-style preview */

        .promo-preview{

            border-radius:18px;

            overflow:hidden;

            border:1px solid rgba(0,0,0,.06);

            background:#fff;

            box-shadow:0 12px 30px rgba(0,0,0,.07);

        }

        .preview-image{display:none;width:100%;height:auto;object-fit:contain;background:#eef2f7}

        .preview-body{

            padding:20px;

            position:relative;

            overflow:hidden;

        }

        .preview-body::before{

            content:"";

            width:150px;

            height:150px;

            border-radius:50%;

            position:absolute;

            right:-70px;

            top:-70px;

            background:rgba(11,59,102,.10);

        }

        .preview-tag{

            display:inline-flex;

            align-items:center;

            min-height:28px;

            padding:5px 9px;

            border-radius:999px;

            font-size:10px;

            font-weight:800;

            letter-spacing:.4px;

            text-transform:uppercase;

            background:#eef4f9;

            color:#0b3b66;

            position:relative;

            z-index:1;

        }

        .preview-title{

            margin:13px 0 7px;

            font-size:23px;

            line-height:1.2;

            color:var(--navy);

            position:relative;

            z-index:1;

        }

        .preview-description{

            color:var(--muted);

            font-size:13px;

            line-height:1.6;

            position:relative;

            z-index:1;

        }

        .preview-meta{

            display:flex;

            justify-content:space-between;

            align-items:center;

            gap:10px;

            margin-top:18px;

            padding-top:14px;

            border-top:1px solid #edf0f4;

            position:relative;

            z-index:1;

        }

        .preview-status{

            display:inline-flex;

            align-items:center;

            gap:7px;

            color:#047857;

            font-size:11px;

            font-weight:800;

        }

        .preview-status .live-dot{

            width:7px;height:7px;border-radius:50%;background:#10b981;

        }

        .preview-expiry{font-size:11px;color:var(--muted)}

        .preview-caption{

            margin:12px 2px 0;

            color:var(--muted);

            font-size:11px;

            line-height:1.5;

        }

        @media(max-width:900px){

            .layout{grid-template-columns:1fr}

            .preview-card{position:static}

        }

        @media(max-width:640px){

            .topbar{padding:13px 15px}

            .page{margin:22px auto;padding:0 12px 28px}

            h1{font-size:25px}

            .row{grid-template-columns:1fr}

            .preset-grid{grid-template-columns:1fr}

            .preset{min-height:122px}

            .theme-options{grid-template-columns:repeat(2,1fr)}

            .form-card,.preview-card{padding:16px}

            .action-row{flex-direction:column}

            .btn{width:100%}

        }

        /* ===== Shared admin shell ===== */
        .admin-sidebar{
            position:fixed;
            top:0;
            left:0;
            bottom:0;
            width:245px;
            background:var(--navy);
            color:#fff;
            z-index:1000;
            overflow-y:auto;
            box-shadow:8px 0 24px rgba(0,0,0,.08);
        }
        .sidebar-header{
            padding:20px 18px 18px;
            display:flex;
            align-items:center;
            gap:12px;
            border-bottom:1px solid rgba(255,255,255,.10);
        }
        .sidebar-logo{
            width:42px;
            height:42px;
            border-radius:12px;
            object-fit:cover;
            background:#fff;
        }
        .sidebar-header h2{
            margin:0;
            font-size:16px;
            line-height:1.2;
            color:#fff;
        }
        .nav-links{padding:12px 10px 20px;}
        .nav-item{
            display:flex;
            align-items:center;
            gap:11px;
            color:#dbe7f3;
            text-decoration:none;
            padding:11px 13px;
            margin:4px 0;
            border-radius:11px;
            font-size:13px;
            font-weight:700;
            transition:.18s ease;
        }
        .nav-item:hover{
            background:rgba(255,255,255,.08);
            color:#fff;
        }
        .nav-item.active{
            background:rgba(255,204,0,.16);
            color:var(--yellow);
        }
        .nav-item i{width:18px;text-align:center;}
        .admin-main{
            margin-left:245px;
            min-height:100vh;
        }
        .admin-top-bar{
            position:sticky;
            top:0;
            z-index:1000;
            height:70px;
            padding:0 40px;
            background:#fff;
            border-bottom:0;
            box-shadow:0 1px 10px rgba(0,0,0,.08);
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:20px;
        }
        .topbar-left{
            display:flex;
            align-items:center;
            gap:12px;
            min-width:0;
        }
        .breadcrumb{
            display:flex;
            align-items:center;
            gap:8px;
            color:#001f3f;
            font-size:15px;
            font-weight:700;
            white-space:nowrap;
        }
        .mobile-menu-toggle {
            display: none;
            border: 0;
            background: #001f3f;
            color: #ffcc00;
            width: 40px;
            height: 40px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 18px;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
        }
        .top-right-actions{
            display:flex;
            align-items:center;
            gap:20px;
            margin-left:auto;
        }
        .notif-wrapper{
            position:relative;
            display:inline-flex;
            align-items:center;
            cursor:pointer;
        }
        .notif-wrapper > i{
            color:#64748b;
            font-size:22px;
        }
        .profile-wrapper{
            position:relative;
            display:inline-flex;
            align-items:center;
            gap:12px;
            border-left:1px solid #e2e8f0;
            padding-left:20px;
            cursor:pointer;
            user-select:none;
        }
        .notif-badge{
            position:absolute;
            top:-8px;
            right:-8px;
            min-width:17px;
            height:17px;
            padding:0 4px;
            border-radius:999px;
            background:#ef4444;
            color:#fff;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            font-size:9px;
            font-weight:800;
        }
        .notif-dropdown,.profile-dropdown{
            position:absolute;
            top:calc(100% + 12px);
            right:0;
            width:330px;
            background:#fff;
            border:1px solid var(--border);
            border-radius:14px;
            box-shadow:0 18px 40px rgba(0,0,0,.12);
            display:none;
            overflow:hidden;
        }
        .notif-dropdown.show,.profile-dropdown.show{display:block;}
        .notif-header{
            padding:12px 14px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            font-size:13px;
            font-weight:800;
            color:var(--navy);
            border-bottom:1px solid var(--border);
        }
        .mark-read-btn{
            color:#0b3b66;
            text-decoration:none;
            font-size:10px;
            font-weight:800;
        }
        .notif-body{max-height:330px;overflow:auto;}
        .notif-item{
            padding:12px 14px;
            border-bottom:1px solid #eef2f7;
            font-size:12px;
            line-height:1.45;
            color:#334155;
        }
        .notif-empty{
            padding:22px;
            color:#94a3b8;
            font-size:12px;
            text-align:center;
        }
        .admin-tag{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            background:#001f3f;
            color:#ffcc00;
            padding:6px 16px;
            border-radius:50px;
            min-height:0;
            font-size:10px;
            font-weight:800;
            letter-spacing:1px;
            text-transform:uppercase;
            border:1px solid #ffcc00;
        }
        .top-avatar,.top-avatar-fallback{
            width:38px;
            height:38px;
            border-radius:50%;
            object-fit:cover;
            border:1px solid #e2e8f0;
        }
        .top-avatar-fallback{
            display:grid;
            place-items:center;
            background:var(--yellow);
            color:var(--navy);
            font-size:13px;
            font-weight:900;
        }
        .name-label{
            font-size:14px;
            font-weight:600;
            color:#4a5568;
            display:flex;
            align-items:center;
            gap:6px;
            white-space:nowrap;
        }
        .name-label i{font-size:10px;color:#94a3b8;}
        .profile-dropdown{width:210px;}
        .profile-item{
            display:flex;
            align-items:center;
            gap:10px;
            padding:12px 14px;
            color:#334155;
            text-decoration:none;
            font-size:12px;
            font-weight:700;
        }
        .profile-item:hover{background:#f8fafc;}
        .logout-text{color:#dc2626;}
        .sidebar-backdrop{
            display:none;
            position:fixed;
            inset:0;
            background:rgba(0,0,0,.38);
            z-index:950;
        }
        .sidebar-backdrop.show{display:block;}

        @media(max-width:900px){
            .admin-sidebar{
                transform:translateX(-105%);
                transition:transform .22s ease;
            }
            .admin-sidebar.mobile-open{
                transform:translateX(0);
            }
            .admin-main{margin-left:0;}
            .mobile-menu-toggle{
                display:inline-flex !important;
            }
            .admin-top-bar{
                height:64px;
                padding:0 14px;
                gap:10px;
            }
            .breadcrumb{
                min-width:0;
                font-size:13px;
                gap:6px;
                overflow:hidden;
                text-overflow:ellipsis;
            }
            .profile-wrapper{gap:8px; padding-left:10px;}
            .name-label{max-width:110px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:12px;}
            .notif-dropdown{
                position:fixed;
                top:66px;
                right:12px;
                width:min(330px,calc(100vw - 24px));
            }
            .profile-dropdown{
                position:fixed;
                top:66px;
                right:12px;
            }
        }

        @media(max-width:560px){
            .admin-top-bar{height:60px; padding:0 10px;}
            .topbar-left{gap:8px;}
            .top-right-actions{gap:6px;}
            .profile-wrapper{padding-left:7px; gap:6px;}
            .name-label{display:none !important;}
            .admin-tag{padding:4px 8px; font-size:8px; letter-spacing:.7px;}
            .top-avatar,.top-avatar-fallback{
                width:30px;
                height:30px;
            }
        }

        .mobile-menu-toggle i {
            color: #ffcc00;
        }


        /* =========================================================
           SIDEBAR — MATCH MANAGEPET.PHP EXACTLY
           Layout/design only; promo functionality is untouched.
           ========================================================= */
        .admin-sidebar {
            width: 260px !important;
            background-color: #001f3f !important;
            color: #ffffff !important;
            display: flex !important;
            flex-direction: column !important;
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            height: 100vh !important;
            z-index: 100 !important;
            overflow: visible !important;
            box-shadow: none !important;
        }

        .admin-sidebar .sidebar-header {
            padding: 30px 20px !important;
            text-align: center !important;
            border-bottom: 1px solid rgba(255,255,255,0.05) !important;
            display: block !important;
        }

        .admin-sidebar .sidebar-logo {
            width: 80px !important;
            height: auto !important;
            object-fit: contain !important;
            margin-bottom: 10px !important;
            border-radius: 0 !important;
            background: transparent !important;
        }

        .admin-sidebar .sidebar-header h2 {
            margin: 0 !important;
            font-size: 16px !important;
            color: #ffcc00 !important;
            text-transform: uppercase !important;
            letter-spacing: 1px !important;
            font-weight: 800 !important;
            line-height: normal !important;
        }

        .admin-sidebar .nav-links {
            flex-grow: 1 !important;
            padding: 20px 15px !important;
            display: flex !important;
            flex-direction: column !important;
            gap: 5px !important;
            overflow-y: auto !important;
        }

        .admin-sidebar .nav-item {
            display: flex !important;
            align-items: center !important;
            padding: 14px 20px !important;
            margin: 0 !important;
            gap: 0 !important;
            color: #94a3b8 !important;
            text-decoration: none !important;
            transition: all 0.3s ease !important;
            font-size: 14px !important;
            font-weight: 500 !important;
            border-radius: 10px !important;
            position: relative !important;
            transform: none;
        }

        .admin-sidebar .nav-item i {
            width: 32px !important;
            min-width: 32px !important;
            font-size: 18px !important;
            transition: transform 0.3s !important;
            text-align: left !important;
        }

        .admin-sidebar .nav-item:hover {
            color: #ffffff !important;
            background-color: rgba(255, 255, 255, 0.05) !important;
            transform: translateX(4px) !important;
        }

        .admin-sidebar .nav-item.active {
            color: #ffcc00 !important;
            background-color: rgba(255, 204, 0, 0.08) !important;
            font-weight: 700 !important;
        }

        .admin-sidebar .nav-item.active::before {
            content: '' !important;
            position: absolute !important;
            left: -15px !important;
            top: 15% !important;
            height: 70% !important;
            width: 5px !important;
            background-color: #ffcc00 !important;
            border-radius: 0 5px 5px 0 !important;
            box-shadow: 2px 0 8px rgba(255, 204, 0, 0.5) !important;
        }

        .admin-main {
            margin-left: 260px !important;
            min-height: 100vh !important;
        }

        @media (max-width: 900px) {
            .admin-sidebar {
                width: 260px !important;
                transform: translateX(-100%) !important;
                transition: transform 0.25s ease !important;
                box-shadow: 12px 0 30px rgba(0,0,0,0.18) !important;
                z-index: 2000 !important;
            }

            .admin-sidebar.mobile-open {
                transform: translateX(0) !important;
            }

            .admin-main {
                margin-left: 0 !important;
                width: 100% !important;
                min-width: 0 !important;
            }

            .mobile-menu-toggle {
                display: inline-flex !important;
                border: 0 !important;
                background: #001f3f !important;
                color: #ffcc00 !important;
                width: 40px !important;
                height: 40px !important;
                border-radius: 10px !important;
                cursor: pointer !important;
                font-size: 18px !important;
                align-items: center !important;
                justify-content: center !important;
                flex: 0 0 auto !important;
            }

            .mobile-menu-toggle i {
                color: #ffcc00 !important;
            }

            .sidebar-backdrop {
                position: fixed !important;
                inset: 0 !important;
                background: rgba(0,31,63,0.48) !important;
                z-index: 1900 !important;
            }

            .sidebar-backdrop.show {
                display: block !important;
            }
        }

</style>
</head>
<body>

<aside class="admin-sidebar" id="adminSidebar">

    <div class="sidebar-header">

        <img src="bg.png" alt="Boogie's Logo" class="sidebar-logo">

        <h2><?php echo $current_role === 'admin' ? "Boogie's Admin" : "Boogie's Staff"; ?></h2>

    </div>

    <nav class="nav-links">

        <a href="admindashboard.php" class="nav-item"><i class="fas fa-chart-line"></i> Dashboard</a>

        <a href="managebooking.php" class="nav-item"><i class="fas fa-calendar-alt"></i> Bookings</a>

        <a href="manageusers.php" class="nav-item"><i class="fas fa-users"></i> Users</a>

        <a href="managepet.php" class="nav-item"><i class="fas fa-dog"></i> Pets</a>

        <?php if ($current_role === 'admin'): ?>

            <a href="managestaff.php" class="nav-item"><i class="fas fa-id-badge"></i> Personnel</a>

            <a href="managepromo.php" class="nav-item active"><i class="fas fa-tags"></i> Promos</a>

            <a href="manage_services.php" class="nav-item"><i class="fas fa-list-ul"></i> Pricelist</a>

            <a href="sales_report.php" class="nav-item"><i class="fas fa-file-invoice-dollar"></i> Sales Report</a>

            <a href="admin_account_logs.php" class="nav-item"><i class="fa-solid fa-clock-rotate-left"></i> Account Logs</a>

        <?php endif; ?>

    </nav>

</aside>

<div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeMobileSidebar()"></div>

<main class="admin-main">

<header class="admin-top-bar">

    <div class="topbar-left">

        <button class="mobile-menu-toggle" type="button" aria-label="Open admin menu" aria-expanded="false" onclick="toggleMobileSidebar(event)">

            <i class="fas fa-bars" aria-hidden="true"></i>

        </button>

        <div class="breadcrumb">

            <i class="fas fa-tags" style="opacity:.5;font-size:14px;"></i>

            Management / Add Promotion

        </div>

    </div>

    <div class="top-right-actions">

        <div class="notif-wrapper" onclick="toggleNotif(event)">

            <i class="fa-solid fa-bell"></i>

            <span id="admin-notif-badge" class="notif-badge" style="display: <?php echo $unread_count > 0 ? 'inline-flex' : 'none'; ?>;">

                <?php echo $unread_count; ?>

            </span>

            <div class="notif-dropdown" id="notifBox" onclick="event.stopPropagation()">

                <div class="notif-header">

                    Alerts

                    <a href="mark_notifications_read.php" id="mark-read-link" class="mark-read-btn" style="display: <?php echo $unread_count > 0 ? 'inline-block' : 'none'; ?>;">Mark all read</a>

                </div>

                <div class="notif-body" id="admin-notif-list">

                    <?php if ($unread_count > 0): ?>

                        <?php foreach ($admin_notifications as $notif): ?>

                            <div class="notif-item">

                                <i class="fa-solid fa-circle-exclamation" style="color:#e11d48;margin-right:5px;"></i>

                                <?php echo htmlspecialchars((string)($notif['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>

                                <br>

                                <small style="color:#94a3b8;font-size:11px;">

                                    <?php echo !empty($notif['created_at']) ? date('M d, g:i A', strtotime((string)$notif['created_at'])) : ''; ?>

                                </small>

                            </div>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <div class="notif-empty">No new notifications.</div>

                    <?php endif; ?>

                </div>

            </div>

        </div>

        <div class="profile-wrapper" onclick="toggleProfile(event)">

            <span class="admin-tag"><?php echo strtoupper($current_role ?: 'ADMIN'); ?></span>

            <?php if (!empty($profile_img_path) && file_exists($profile_img_path)): ?>

                <img src="<?php echo htmlspecialchars($profile_img_path, ENT_QUOTES, 'UTF-8'); ?>" class="top-avatar" alt="Profile Picture">

            <?php else: ?>

                <div class="top-avatar-fallback"><?php echo strtoupper(substr($first_name ?: 'A', 0, 1)); ?></div>

            <?php endif; ?>

            <span class="name-label">

                <?php echo htmlspecialchars($admin_full_name, ENT_QUOTES, 'UTF-8'); ?>

                <i class="fas fa-chevron-down" style="font-size:10px;color:#94a3b8;"></i>

            </span>

            <div class="profile-dropdown" id="profileBox" onclick="event.stopPropagation()">

                <a href="admin_profile.php" class="profile-item"><i class="fas fa-user-circle"></i> My Profile</a>

                <a href="../logout.php" class="profile-item logout-text"><i class="fas fa-sign-out-alt"></i> Logout</a>

            </div>

        </div>

    </div>

</header>

<div class="page">

    <div class="page-heading">

        <div class="eyebrow"><i class="fa-solid fa-wand-magic-sparkles"></i> Create customer promotion</div>

        <h1>Add New Promotion</h1>

        <p class="subtitle">Choose a ready-made promo, upload a banner, edit the details, then set it to Active so customers can see it on the website.</p>

    </div>

    <div class="layout">

        <section class="card form-card">

            <div class="card-title">

                <h2>Promo Details</h2>

                <span class="mini-note">All fields marked are required.</span>

            </div>

            <?php echo $message; ?>

            <form action="addpromo.php" method="POST" enctype="multipart/form-data" id="promoForm">

                <label class="section-label">1. Start with a ready-made promo</label>

                <div class="preset-grid">

                    <?php foreach ($presets as $index => $preset): ?>

                        <button

                            type="button"

                            class="preset <?php echo $index === 0 ? 'active' : ''; ?>"

                            data-index="<?php echo $index; ?>"

                            data-theme="<?php echo htmlspecialchars($preset['theme']); ?>"

                        >

                            <div class="preset-top">

                                <span class="preset-icon"><i class="fa-solid <?php echo htmlspecialchars($preset['icon']); ?>"></i></span>

                                <span class="preset-check"><i class="fa-solid fa-check"></i></span>

                            </div>

                            <strong><?php echo htmlspecialchars($preset['name']); ?></strong>

                            <small><?php echo htmlspecialchars($preset['description']); ?></small>

                            <span class="preset-use"><i class="fa-solid fa-sparkles"></i> Use template</span>

                        </button>

                    <?php endforeach; ?>

                </div>

                <label class="section-label" for="promo_image">2. Promo Image / Banner <span style="color:#ef4444">*</span></label>

                <div class="upload-box">

                    <label class="upload-label" for="promo_image">

                        <i class="fa-solid fa-cloud-arrow-up"></i>

                        <span>Choose promo image</span>

                    </label>

                    <input type="file" id="promo_image" name="promo_image" accept=".jpg,.jpeg,.png,.webp" required>

                    <div class="upload-help"><strong>Recommended:</strong> 4:5 poster (e.g. 1080×1350). JPG, PNG, or WEBP only. Maximum 5 MB. Other image sizes are supported.</div>

                    <div class="image-preview" id="imagePreview">

                        <img id="imagePreviewImg" src="" alt="Selected promotion">

                    </div>

                </div>

                <label class="section-label" for="tag">3. Promo Tag</label>

                <input

                    class="input"

                    type="text"

                    id="tag"

                    name="tag"

                    value="<?php echo htmlspecialchars($_POST['tag'] ?? 'GROOMING DEAL'); ?>"

                    maxlength="80"

                    placeholder="e.g. GROOMING DEAL"

                    required

                >

                <label class="section-label" for="title">Promo Headline</label>

                <input

                    class="input"

                    type="text"

                    id="title"

                    name="title"

                    value="<?php echo htmlspecialchars($_POST['title'] ?? 'Fresh Look, Happy Pup'); ?>"

                    maxlength="120"

                    placeholder="e.g. 20% OFF Grooming"

                    required

                >

                <label class="section-label" for="description">Promo Description</label>

                <textarea

                    id="description"

                    name="description"

                    maxlength="500"

                    placeholder="Explain the promotion in a few clear sentences."

                    required

                ><?php echo htmlspecialchars($_POST['description'] ?? 'Enjoy a special grooming offer for your furry friend. Book a grooming session today.'); ?></textarea>

                <label class="section-label">Theme</label>

                <div class="theme-options">

                    <?php

                    $selected_theme = $_POST['theme_color'] ?? 'purple';

                    $themes = ['purple' => 'Purple', 'teal' => 'Teal', 'red' => 'Red', 'orange' => 'Orange'];

                    foreach ($themes as $value => $label):

                    ?>

                        <label class="theme-option">

                            <input type="radio" name="theme_color" value="<?php echo $value; ?>" <?php echo $selected_theme === $value ? 'checked' : ''; ?>>

                            <span class="theme-pill">

                                <span class="dot <?php echo $value; ?>"></span><?php echo $label; ?>

                            </span>

                        </label>

                    <?php endforeach; ?>

                </div>

                <div class="row">

                    <div>

                        <label class="section-label" for="expiry_date">Expiry Date</label>

                        <input

                            class="input"

                            type="date"

                            id="expiry_date"

                            name="expiry_date"

                            value="<?php echo htmlspecialchars($_POST['expiry_date'] ?? ''); ?>"

                            min="<?php echo htmlspecialchars($today); ?>"

                            required

                        >

                    </div>

                    <div>

                        <label class="section-label" for="status">Website Visibility</label>

                        <select id="status" name="status" required>

                            <option value="active" <?php echo (($_POST['status'] ?? 'active') === 'active') ? 'selected' : ''; ?>>Active — Show on website</option>

                            <option value="inactive" <?php echo (($_POST['status'] ?? '') === 'inactive') ? 'selected' : ''; ?>>Inactive — Hide from website</option>

                        </select>

                    </div>

                </div>

                <div class="action-row">

                    <a href="managepromo.php" class="btn btn-secondary">Cancel</a>

                    <button type="submit" class="btn btn-primary">

                        <i class="fa-solid fa-paper-plane"></i> Publish Promotion

                    </button>

                </div>

            </form>

        </section>

        <aside class="card preview-card">

            <div class="card-title">

                <h2>Customer Preview</h2>

                <span class="mini-note">Live</span>

            </div>

            <div class="promo-preview">

                <img class="preview-image" id="previewImage" src="" alt="Promotion preview">

                <div class="preview-body" id="previewBody">

                    <span class="preview-tag" id="previewTag">GROOMING DEAL</span>

                    <h3 class="preview-title" id="previewTitle">Fresh Look, Happy Pup</h3>

                    <p class="preview-description" id="previewDescription">Enjoy a special grooming offer for your furry friend. Book a grooming session today.</p>

                    <div class="preview-meta">

                        <span class="preview-status">

                            <span class="live-dot"></span> Visible to customers

                        </span>

                        <span class="preview-expiry" id="previewExpiry">Set an expiry date</span>

                    </div>

                </div>

            </div>

            <p class="preview-caption">

                The customer website can use the same active promo data and display this banner together with the tag, headline, description, and expiry date.

            </p>

        </aside>

    </div>

</div>

</main>

<script>

const presets = <?php echo json_encode($presets, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

const tagInput = document.getElementById('tag');

const titleInput = document.getElementById('title');

const descriptionInput = document.getElementById('description');

const expiryInput = document.getElementById('expiry_date');

const imageInput = document.getElementById('promo_image');

const previewTag = document.getElementById('previewTag');

const previewTitle = document.getElementById('previewTitle');

const previewDescription = document.getElementById('previewDescription');

const previewExpiry = document.getElementById('previewExpiry');

const previewImage = document.getElementById('previewImage');

const imagePreview = document.getElementById('imagePreview');

const imagePreviewImg = document.getElementById('imagePreviewImg');

function updatePreview() {

    previewTag.textContent = tagInput.value || 'PROMO';

    previewTitle.textContent = titleInput.value || 'Promotion Headline';

    previewDescription.textContent = descriptionInput.value || 'Your promotion description will appear here.';

    if (expiryInput.value) {

        const date = new Date(expiryInput.value + 'T00:00:00');

        previewExpiry.textContent = 'Until ' + date.toLocaleDateString(undefined, {

            year: 'numeric',

            month: 'short',

            day: 'numeric'

        });

    } else {

        previewExpiry.textContent = 'Set an expiry date';

    }

}

document.querySelectorAll('.preset').forEach((button) => {

    button.addEventListener('click', () => {

        const preset = presets[button.dataset.index];

        if (!preset) return;

        tagInput.value = preset.tag;

        titleInput.value = preset.title;

        descriptionInput.value = preset.description;

        const theme = document.querySelector(`input[name="theme_color"][value="${preset.theme}"]`);

        if (theme) theme.checked = true;

        document.querySelectorAll('.preset').forEach((item) => item.classList.remove('active'));

        button.classList.add('active');

        updatePreview();

    });

});

[tagInput, titleInput, descriptionInput, expiryInput].forEach((el) => {

    el.addEventListener('input', updatePreview);

    el.addEventListener('change', updatePreview);

});

imageInput.addEventListener('change', () => {

    const file = imageInput.files && imageInput.files[0];

    if (!file) {

        imagePreview.style.display = 'none';

        previewImage.style.display = 'none';

        previewImage.removeAttribute('src');

        return;

    }

    const url = URL.createObjectURL(file);

    imagePreview.style.display = 'block';

    imagePreviewImg.src = url;

    previewImage.style.display = 'block';

    previewImage.src = url;

});

updatePreview();

// Shared admin shell interactions. These do not replace the promo functions above.

function toggleMobileSidebar(event) {

    if (event) event.stopPropagation();

    const sidebar = document.getElementById('adminSidebar');

    const backdrop = document.getElementById('sidebarBackdrop');

    const button = document.querySelector('.mobile-menu-toggle');

    if (!sidebar) return;

    const open = sidebar.classList.toggle('mobile-open');

    if (backdrop) backdrop.classList.toggle('show', open);

    if (button) button.setAttribute('aria-expanded', open ? 'true' : 'false');

}

function closeMobileSidebar() {

    const sidebar = document.getElementById('adminSidebar');

    const backdrop = document.getElementById('sidebarBackdrop');

    const button = document.querySelector('.mobile-menu-toggle');

    if (sidebar) sidebar.classList.remove('mobile-open');

    if (backdrop) backdrop.classList.remove('show');

    if (button) button.setAttribute('aria-expanded', 'false');

}

function toggleNotif(event) {

    if (event) event.stopPropagation();

    const notif = document.getElementById('notifBox');

    const profile = document.getElementById('profileBox');

    if (notif) notif.classList.toggle('show');

    if (profile) profile.classList.remove('show');

}

function toggleProfile(event) {

    if (event) event.stopPropagation();

    const profile = document.getElementById('profileBox');

    const notif = document.getElementById('notifBox');

    if (profile) profile.classList.toggle('show');

    if (notif) notif.classList.remove('show');

}

function renderAdminNotifications(data) {

    const badge = document.getElementById('admin-notif-badge');

    const list = document.getElementById('admin-notif-list');

    const markRead = document.getElementById('mark-read-link');

    if (!badge || !list) return;

    const unread = Number(data && data.unread ? data.unread : 0);

    badge.style.display = unread > 0 ? 'inline-flex' : 'none';

    badge.textContent = unread;

    if (markRead) markRead.style.display = unread > 0 ? 'inline-block' : 'none';

    list.innerHTML = (data && data.html) ? data.html : '<div class="notif-empty">No new notifications.</div>';

}

function fetchAdminNotifs() {

    fetch('get_admin_notifs.php', { method: 'GET', cache: 'no-store', credentials: 'same-origin' })

        .then(function(response) {

            if (!response.ok) throw new Error('Notification request failed: HTTP ' + response.status);

            return response.json();

        })

        .then(renderAdminNotifications)

        .catch(function(error) { console.error('Error fetching admin notifications:', error); });

}

document.addEventListener('click', function(event) {

    if (!event.target.closest('.notif-wrapper')) {

        const notif = document.getElementById('notifBox');

        if (notif) notif.classList.remove('show');

    }

    if (!event.target.closest('.profile-wrapper')) {

        const profile = document.getElementById('profileBox');

        if (profile) profile.classList.remove('show');

    }

});

document.addEventListener('keydown', function(event) {

    if (event.key === 'Escape') {

        closeMobileSidebar();

        const notif = document.getElementById('notifBox');

        const profile = document.getElementById('profileBox');

        if (notif) notif.classList.remove('show');

        if (profile) profile.classList.remove('show');

    }

});

document.querySelectorAll('.admin-sidebar .nav-item').forEach(function(link) {

    link.addEventListener('click', function() {

        if (window.innerWidth <= 900) closeMobileSidebar();

    });

});

fetchAdminNotifs();

setInterval(fetchAdminNotifs, 3000);

</script>

</body>

</html>
