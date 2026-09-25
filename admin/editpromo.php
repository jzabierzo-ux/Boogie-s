<?php
session_start();

// SECURITY: Admin only
if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    ($_SESSION['role'] ?? '') !== 'admin'
) {
    header('Location: ../admin_login.php');
    exit();
}

require_once '../db_supabase.php';

$message = '';
$promo_id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;

if ($promo_id <= 0) {
    header('Location: managepromo.php');
    exit();
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

// FETCH CURRENT PROMO
try {
    $fetch_stmt = $pdo->prepare('
        SELECT *
        FROM promos
        WHERE id = :id
        LIMIT 1
    ');
    $fetch_stmt->execute([':id' => $promo_id]);
    $promo = $fetch_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $promo = false;
}

if (!$promo) {
    header('Location: managepromo.php');
    exit();
}

// Preserve values after validation errors
$form = [
    'tag' => $promo['tag'] ?? '',
    'title' => $promo['title'] ?? '',
    'description' => $promo['description'] ?? '',
    'theme_color' => $promo['theme_color'] ?? 'purple',
    'expiry_date' => $promo['expiry_date'] ?? '',
    'status' => $promo['status'] ?? 'active',
    'image_url' => $promo['image_url'] ?? null,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['tag'] = trim($_POST['tag'] ?? '');
    $form['title'] = trim($_POST['title'] ?? '');
    $form['description'] = trim($_POST['description'] ?? '');
    $form['theme_color'] = trim($_POST['theme_color'] ?? '');
    $form['expiry_date'] = trim($_POST['expiry_date'] ?? '');
    $form['status'] = trim($_POST['status'] ?? '');

    $remove_image = isset($_POST['remove_image']) && $_POST['remove_image'] === '1';
    $new_image_url = $form['image_url'];
    $new_uploaded_path = null;
    $old_image_url = $promo['image_url'] ?? null;

    // Validate text fields first
    if (
        $form['tag'] === '' ||
        $form['title'] === '' ||
        $form['description'] === '' ||
        $form['theme_color'] === '' ||
        $form['expiry_date'] === '' ||
        $form['status'] === ''
    ) {
        $message = "<div class='alert error'><i class='fa-solid fa-circle-exclamation'></i> Please complete all required fields.</div>";
    } elseif (!in_array($form['theme_color'], ['purple', 'teal', 'red', 'orange'], true)) {
        $message = "<div class='alert error'><i class='fa-solid fa-circle-exclamation'></i> Invalid color theme selected.</div>";
    } elseif (!in_array($form['status'], ['active', 'inactive'], true)) {
        $message = "<div class='alert error'><i class='fa-solid fa-circle-exclamation'></i> Invalid promo status selected.</div>";
    }

    // Optional replacement image
    if ($message === '' && isset($_FILES['promo_image']) && $_FILES['promo_image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $upload = $_FILES['promo_image'];
        $allowed_mimes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp'
        ];
        $max_size = 5 * 1024 * 1024;

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
                        $new_image_url = 'uploads/promos/' . $random_name;
                        $new_uploaded_path = $destination;
                    } else {
                        $message = "<div class='alert error'><i class='fa-solid fa-circle-exclamation'></i> Unable to save the uploaded image.</div>";
                    }
                }
            }
        }
    }

    if ($message === '' && $remove_image && $new_uploaded_path === null) {
        $new_image_url = null;
    }

    // UPDATE DATABASE
    if ($message === '') {
        try {
            $update_stmt = $pdo->prepare('
                UPDATE promos
                SET
                    tag = :tag,
                    title = :title,
                    description = :description,
                    theme_color = :theme_color,
                    expiry_date = :expiry_date,
                    status = :status,
                    image_url = :image_url
                WHERE id = :id
            ');

            $update_stmt->execute([
                ':tag' => $form['tag'],
                ':title' => $form['title'],
                ':description' => $form['description'],
                ':theme_color' => $form['theme_color'],
                ':expiry_date' => $form['expiry_date'],
                ':status' => $form['status'],
                ':image_url' => $new_image_url,
                ':id' => $promo_id
            ]);

            // Delete old image only after the database update succeeds
            if (($new_image_url !== $old_image_url || $remove_image) && !empty($old_image_url)) {
                $old_name = basename((string)$old_image_url);
                $old_path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'promos' . DIRECTORY_SEPARATOR . $old_name;
                if (is_file($old_path) && realpath($old_path) === realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'promos' . DIRECTORY_SEPARATOR . $old_name)) {
                    @unlink($old_path);
                }
            }

            $form['image_url'] = $new_image_url;
            $promo['image_url'] = $new_image_url;
            $message = "<div class='alert success'><i class='fa-solid fa-circle-check'></i> Promotion updated successfully. <a href='managepromo.php'>Back to Promotions</a></div>";
        } catch (PDOException $e) {
            if ($new_uploaded_path && is_file($new_uploaded_path)) {
                @unlink($new_uploaded_path);
            }

            $message = "<div class='alert error'><i class='fa-solid fa-circle-exclamation'></i> Unable to update the promo. Make sure the <strong>image_url</strong> column exists in the promos table.</div>";
            error_log('Edit promo error: ' . $e->getMessage());
        }
    }
}

$today = date('Y-m-d');
$current_image = $form['image_url'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Promotion | Boogie's Pet Care</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root{
            --navy:#001f3f;
            --blue:#0b3b66;
            --yellow:#ffcc00;
            --purple:#8b2cf5;
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
                radial-gradient(circle at top left,rgba(255,204,0,.12),transparent 28%),
                radial-gradient(circle at top right,rgba(139,44,245,.10),transparent 28%),
                var(--bg);
            color:var(--text);
        }
        .topbar{
            background:var(--navy);color:#fff;padding:16px 28px;display:flex;
            justify-content:space-between;align-items:center;gap:15px;
            box-shadow:0 6px 18px rgba(0,0,0,.08)
        }
        .brand{display:flex;align-items:center;gap:12px}
        .brand-icon{width:42px;height:42px;display:grid;place-items:center;background:var(--yellow);color:var(--navy);border-radius:12px;font-size:18px}
        .brand strong{display:block;font-size:16px}.brand span{display:block;color:#cbd5e1;font-size:12px;margin-top:2px}
        .topbar a{color:#fff;text-decoration:none;font-size:13px;font-weight:700}
        .page{max-width:1180px;margin:30px auto;padding:0 18px 40px}
        .page-heading{margin-bottom:22px}
        .eyebrow{display:inline-flex;align-items:center;gap:7px;background:#fff;border:1px solid var(--border);border-radius:999px;padding:7px 11px;color:var(--purple);font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;box-shadow:0 5px 16px rgba(0,0,0,.03)}
        h1{margin:12px 0 5px;color:var(--navy);font-size:30px}.subtitle{margin:0;color:var(--muted);font-size:14px}
        .layout{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(320px,.75fr);gap:22px;align-items:start}
        .card{background:#fff;border:1px solid var(--border);border-radius:20px;box-shadow:var(--shadow)}
        .form-card{padding:24px}.preview-card{position:sticky;top:18px;padding:18px}
        .card-title{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:18px}.card-title h2{margin:0;color:var(--navy);font-size:18px}.mini-note{font-size:11px;color:var(--muted)}
        .section-label{display:block;margin:18px 0 9px;color:var(--navy);font-size:12px;font-weight:800;letter-spacing:.45px;text-transform:uppercase}
        .input,select,textarea{width:100%;border:1px solid var(--border);border-radius:12px;background:#fff;color:var(--text);font:inherit;font-size:14px;outline:none;transition:.18s ease}
        .input,select{min-height:46px;padding:11px 13px}textarea{min-height:126px;padding:12px 13px;resize:vertical;line-height:1.55}
        .input:focus,select:focus,textarea:focus{border-color:var(--purple);box-shadow:0 0 0 4px rgba(139,44,245,.10)}
        .row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
        .preset-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
        .preset{border:1px solid var(--border);background:#fff;border-radius:14px;padding:13px;cursor:pointer;text-align:left;transition:.18s ease}
        .preset:hover{transform:translateY(-2px);box-shadow:0 8px 18px rgba(0,31,63,.07)}.preset.active{border-color:var(--purple);box-shadow:0 0 0 3px rgba(139,44,245,.10)}
        .preset-icon{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;background:#f2edff;color:var(--purple);margin-bottom:9px}
        .preset strong{display:block;font-size:13px;color:var(--navy)}.preset small{display:block;color:var(--muted);font-size:11px;margin-top:3px}
        .upload-box{border:1.5px dashed #cfd6e2;border-radius:16px;padding:15px;background:#fbfcfe}.upload-box input[type=file]{display:none}
        .upload-label{display:flex;align-items:center;justify-content:center;gap:9px;min-height:52px;border:1px solid var(--border);border-radius:12px;background:#fff;color:var(--navy);font-weight:700;cursor:pointer}
        .upload-help{font-size:11px;color:var(--muted);margin:9px 2px 0}.current-image-wrap{margin-top:12px}.current-image-label{font-size:11px;color:var(--muted);font-weight:700;margin-bottom:7px}.current-image{width:100%;display:block;aspect-ratio:16/7;object-fit:cover;border-radius:14px;background:#eef2f7;border:1px solid var(--border)}
        .remove-row{display:flex;align-items:center;gap:8px;margin-top:10px;font-size:12px;color:#7f1d1d}.remove-row input{width:15px;height:15px;accent-color:#ef4444}
        .image-preview{display:none;margin-top:12px;border-radius:14px;overflow:hidden;aspect-ratio:16/7;background:#eef2f7}.image-preview img{width:100%;height:100%;object-fit:cover;display:block}
        .theme-options{display:grid;grid-template-columns:repeat(4,1fr);gap:9px}.theme-option{position:relative}.theme-option input{position:absolute;opacity:0;pointer-events:none}
        .theme-pill{display:flex;align-items:center;justify-content:center;gap:8px;min-height:44px;border:1px solid var(--border);border-radius:12px;background:#fff;cursor:pointer;font-size:12px;font-weight:700;color:var(--text)}
        .theme-option input:checked + .theme-pill{border-color:var(--purple);box-shadow:0 0 0 3px rgba(139,44,245,.10)}
        .dot{width:10px;height:10px;border-radius:999px;display:inline-block}.dot.purple{background:#8b2cf5}.dot.teal{background:#14b8a6}.dot.red{background:#ef4444}.dot.orange{background:#f97316}
        .action-row{display:flex;gap:10px;margin-top:22px}.btn{min-height:48px;border-radius:12px;padding:12px 17px;font-weight:800;font-size:14px;text-decoration:none;display:inline-flex;justify-content:center;align-items:center;gap:8px;cursor:pointer;border:0}.btn-primary{background:var(--purple);color:#fff;flex:1}.btn-primary:hover{filter:brightness(.96);transform:translateY(-1px)}.btn-secondary{background:#fff;color:var(--navy);border:1px solid var(--border)}
        .alert{padding:13px 15px;border-radius:12px;font-size:13px;margin-bottom:17px;line-height:1.5}.alert.success{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}.alert.error{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}.alert a{color:inherit;font-weight:800}
        .promo-preview{border-radius:18px;overflow:hidden;border:1px solid rgba(0,0,0,.06);background:#fff;box-shadow:0 12px 30px rgba(0,0,0,.07)}
        .preview-image{display:none;width:100%;aspect-ratio:16/8;object-fit:cover}.preview-body{padding:20px;position:relative;overflow:hidden}
        .preview-body::before{content:"";width:150px;height:150px;border-radius:50%;position:absolute;right:-70px;top:-70px;background:rgba(139,44,245,.10)}
        .preview-tag{display:inline-flex;align-items:center;min-height:28px;padding:5px 9px;border-radius:999px;font-size:10px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;background:#f2edff;color:#7227cc;position:relative;z-index:1}
        .preview-title{margin:13px 0 7px;font-size:23px;line-height:1.2;color:var(--navy);position:relative;z-index:1}.preview-description{color:var(--muted);font-size:13px;line-height:1.6;position:relative;z-index:1}
        .preview-meta{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:18px;padding-top:14px;border-top:1px solid #edf0f4;position:relative;z-index:1}.preview-status{display:inline-flex;align-items:center;gap:7px;color:#047857;font-size:11px;font-weight:800}.preview-status .live-dot{width:7px;height:7px;border-radius:50%;background:#10b981}.preview-expiry{font-size:11px;color:var(--muted)}
        .preview-caption{margin:12px 2px 0;color:var(--muted);font-size:11px;line-height:1.5}
        @media(max-width:900px){.layout{grid-template-columns:1fr}.preview-card{position:static}}
        @media(max-width:640px){.topbar{padding:13px 15px}.page{margin:22px auto;padding:0 12px 28px}h1{font-size:25px}.row{grid-template-columns:1fr}.preset-grid{grid-template-columns:1fr}.theme-options{grid-template-columns:repeat(2,1fr)}.form-card,.preview-card{padding:16px}.action-row{flex-direction:column}.btn{width:100%}}
    </style>
</head>
<body>

<div class="topbar">
    <div class="brand">
        <div class="brand-icon"><i class="fa-solid fa-bullhorn"></i></div>
        <div><strong>Boogie's Pet Care</strong><span>Promotion Management</span></div>
    </div>
    <a href="managepromo.php"><i class="fa-solid fa-arrow-left"></i> Back to Promos</a>
</div>

<main class="page">
    <div class="page-heading">
        <div class="eyebrow"><i class="fa-solid fa-pen-to-square"></i> Edit customer promotion</div>
        <h1>Edit Promotion</h1>
        <p class="subtitle">Update the promo details, replace the banner, or hide the promotion from the customer website.</p>
    </div>

    <div class="layout">
        <section class="card form-card">
            <div class="card-title">
                <h2>Promotion Details</h2>
                <span class="mini-note">Promo #<?php echo $promo_id; ?></span>
            </div>

            <?php echo $message; ?>

            <form action="editpromo.php?id=<?php echo $promo_id; ?>" method="POST" enctype="multipart/form-data" id="promoForm">

                <label class="section-label">1. Ready-made promo templates</label>
                <div class="preset-grid">
                    <?php foreach ($presets as $index => $preset): ?>
                        <button type="button" class="preset" data-index="<?php echo $index; ?>">
                            <span class="preset-icon"><i class="fa-solid <?php echo htmlspecialchars($preset['icon']); ?>"></i></span>
                            <strong><?php echo htmlspecialchars($preset['name']); ?></strong>
                            <small>Use this template</small>
                        </button>
                    <?php endforeach; ?>
                </div>

                <label class="section-label" for="promo_image">2. Promo Image / Banner</label>
                <div class="upload-box">
                    <label class="upload-label" for="promo_image">
                        <i class="fa-solid fa-cloud-arrow-up"></i><span>Choose replacement image</span>
                    </label>
                    <input type="file" id="promo_image" name="promo_image" accept=".jpg,.jpeg,.png,.webp">
                    <div class="upload-help">Leave empty to keep the current image. JPG, PNG, or WEBP only. Maximum 5 MB.</div>

                    <?php if (!empty($current_image)): ?>
                        <div class="current-image-wrap" id="currentImageWrap">
                            <div class="current-image-label">Current promo image</div>
                            <img class="current-image" src="../<?php echo htmlspecialchars(ltrim($current_image, '/')); ?>" alt="Current promotion image">
                            <label class="remove-row">
                                <input type="checkbox" name="remove_image" value="1" id="remove_image">
                                Remove current image
                            </label>
                        </div>
                    <?php endif; ?>

                    <div class="image-preview" id="imagePreview"><img id="imagePreviewImg" src="" alt="New promotion image"></div>
                </div>

                <label class="section-label" for="tag">3. Promo Tag</label>
                <input class="input" type="text" id="tag" name="tag" value="<?php echo htmlspecialchars($form['tag']); ?>" maxlength="80" required>

                <label class="section-label" for="title">Promo Headline</label>
                <input class="input" type="text" id="title" name="title" value="<?php echo htmlspecialchars($form['title']); ?>" maxlength="120" required>

                <label class="section-label" for="description">Promo Description</label>
                <textarea id="description" name="description" maxlength="500" required><?php echo htmlspecialchars($form['description']); ?></textarea>

                <label class="section-label">Theme</label>
                <div class="theme-options">
                    <?php
                    $themes = ['purple' => 'Purple', 'teal' => 'Teal', 'red' => 'Red', 'orange' => 'Orange'];
                    foreach ($themes as $value => $label):
                    ?>
                        <label class="theme-option">
                            <input type="radio" name="theme_color" value="<?php echo $value; ?>" <?php echo $form['theme_color'] === $value ? 'checked' : ''; ?>>
                            <span class="theme-pill"><span class="dot <?php echo $value; ?>"></span><?php echo $label; ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="row">
                    <div>
                        <label class="section-label" for="expiry_date">Expiry Date</label>
                        <input class="input" type="date" id="expiry_date" name="expiry_date" value="<?php echo htmlspecialchars($form['expiry_date']); ?>" required>
                    </div>
                    <div>
                        <label class="section-label" for="status">Website Visibility</label>
                        <select id="status" name="status" required>
                            <option value="active" <?php echo $form['status'] === 'active' ? 'selected' : ''; ?>>Active — Show on website</option>
                            <option value="inactive" <?php echo $form['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive — Hide from website</option>
                        </select>
                    </div>
                </div>

                <div class="action-row">
                    <a href="managepromo.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Promotion</button>
                </div>
            </form>
        </section>

        <aside class="card preview-card">
            <div class="card-title"><h2>Customer Preview</h2><span class="mini-note">Live</span></div>
            <div class="promo-preview">
                <img class="preview-image" id="previewImage" src="" alt="Promotion preview">
                <div class="preview-body">
                    <span class="preview-tag" id="previewTag"><?php echo htmlspecialchars($form['tag'] ?: 'PROMO'); ?></span>
                    <h3 class="preview-title" id="previewTitle"><?php echo htmlspecialchars($form['title'] ?: 'Promotion Headline'); ?></h3>
                    <p class="preview-description" id="previewDescription"><?php echo htmlspecialchars($form['description'] ?: 'Your promotion description will appear here.'); ?></p>
                    <div class="preview-meta">
                        <span class="preview-status"><span class="live-dot"></span><span id="visibilityText">Visible to customers</span></span>
                        <span class="preview-expiry" id="previewExpiry"><?php echo !empty($form['expiry_date']) ? 'Until ' . date('M d, Y', strtotime($form['expiry_date'])) : 'Set an expiry date'; ?></span>
                    </div>
                </div>
            </div>
            <p class="preview-caption">This preview mirrors the information that will be available to customers on the website.</p>
        </aside>
    </div>
</main>

<script>
const presets = <?php echo json_encode($presets, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const tagInput = document.getElementById('tag');
const titleInput = document.getElementById('title');
const descriptionInput = document.getElementById('description');
const expiryInput = document.getElementById('expiry_date');
const statusInput = document.getElementById('status');
const imageInput = document.getElementById('promo_image');
const removeImage = document.getElementById('remove_image');
const previewTag = document.getElementById('previewTag');
const previewTitle = document.getElementById('previewTitle');
const previewDescription = document.getElementById('previewDescription');
const previewExpiry = document.getElementById('previewExpiry');
const previewImage = document.getElementById('previewImage');
const imagePreview = document.getElementById('imagePreview');
const imagePreviewImg = document.getElementById('imagePreviewImg');
const visibilityText = document.getElementById('visibilityText');

function updatePreview() {
    previewTag.textContent = tagInput.value || 'PROMO';
    previewTitle.textContent = titleInput.value || 'Promotion Headline';
    previewDescription.textContent = descriptionInput.value || 'Your promotion description will appear here.';

    if (expiryInput.value) {
        const date = new Date(expiryInput.value + 'T00:00:00');
        previewExpiry.textContent = 'Until ' + date.toLocaleDateString(undefined, {year:'numeric', month:'short', day:'numeric'});
    } else {
        previewExpiry.textContent = 'Set an expiry date';
    }

    if (statusInput.value === 'active') {
        visibilityText.textContent = 'Visible to customers';
    } else {
        visibilityText.textContent = 'Hidden from customers';
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
        document.querySelectorAll('.preset').forEach(item => item.classList.remove('active'));
        button.classList.add('active');
        updatePreview();
    });
});

[tagInput, titleInput, descriptionInput, expiryInput, statusInput].forEach((el) => {
    el.addEventListener('input', updatePreview);
    el.addEventListener('change', updatePreview);
});

if (removeImage) {
    removeImage.addEventListener('change', () => {
        if (removeImage.checked) {
            previewImage.style.display = 'none';
            previewImage.removeAttribute('src');
        }
    });
}

imageInput.addEventListener('change', () => {
    const file = imageInput.files && imageInput.files[0];
    if (!file) {
        imagePreview.style.display = 'none';
        imagePreviewImg.removeAttribute('src');
        return;
    }

    if (removeImage) removeImage.checked = false;
    const url = URL.createObjectURL(file);
    imagePreview.style.display = 'block';
    imagePreviewImg.src = url;
    previewImage.style.display = 'block';
    previewImage.src = url;
});

const currentImageSrc = <?php echo json_encode(!empty($current_image) ? '../' . ltrim($current_image, '/') : ''); ?>;
if (currentImageSrc) {
    previewImage.style.display = 'block';
    previewImage.src = currentImageSrc;
}

updatePreview();
</script>

</body>
</html>
