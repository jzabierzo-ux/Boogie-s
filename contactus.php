<?php
session_start();
include 'db_connect.php'; 

// Check if user is logged in
$is_logged_in = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
$user_name = $is_logged_in ? ($_SESSION['user_name'] ?? 'User') : "Guest";

if (isset($_POST['send_contact'])) {
    if (!$is_logged_in) {
        echo "<script>alert('Please login first to send a message.'); window.location='login.php';</script>";
        exit();
    }

    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $message = mysqli_real_escape_string($conn, $_POST['message']);

    if (!preg_match("/^[a-zA-Z\s]*$/", $name)) {
        echo "<script>alert('Invalid name. Only letters and spaces are allowed.'); window.history.back();</script>";
        exit();
    }

    if (!preg_match("/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.(com|net|ph)$/i", $email)) {
        echo "<script>alert('Invalid email. Please use a complete address ending in .com, .net, or .ph'); window.history.back();</script>";
        exit();
    }

    $sql = "INSERT INTO contacts (name, email, message) VALUES ('$name', '$email', '$message')";
    
    if (mysqli_query($conn, $sql)) {
        echo "<script>alert('Message sent successfully and saved to database!'); window.location='contactus.php';</script>";
    } else {
        echo "<script>alert('Error: " . mysqli_error($conn) . "'); window.history.back();</script>";
    }
}

// --- LOGIC TO FETCH REVIEWS FROM DATABASE ---
$total_reviews = 0;
$avg_rating = '0.0';
$reviews_list = [];
$review_db_error = false;

try {
    // Get the real review count and average rating.
    $count_query = "SELECT COUNT(*) AS total, COALESCE(AVG(rating), 0) AS average FROM reviews";
    $count_result = mysqli_query($conn, $count_query);

    if ($count_result) {
        $count_data = mysqli_fetch_assoc($count_result);
        $total_reviews = (int)($count_data['total'] ?? 0);
        $avg_rating = number_format((float)($count_data['average'] ?? 0), 1);
    } else {
        $review_db_error = true;
    }

    // Get review + customer + appointment information in one query.
    // Reviews submitted through write_review.php are picked up here automatically.
    $review_query = "
        SELECT
            r.id,
            r.rating,
            r.comment,
            r.review_date,
            COALESCE(NULLIF(u.full_name, ''), 'Valued Client') AS reviewer_name,
            COALESCE(NULLIF(a.service, ''), 'Pet Care') AS service_type
        FROM reviews r
        LEFT JOIN users u ON u.id = r.user_id
        LEFT JOIN appointments a ON a.id = r.appointment_id
        ORDER BY r.review_date DESC, r.id DESC
        LIMIT 6
    ";

    $review_result = mysqli_query($conn, $review_query);

    if ($review_result) {
        while ($row = mysqli_fetch_assoc($review_result)) {
            $reviews_list[] = [
                'id' => (int)$row['id'],
                'rating' => max(1, min(5, (int)$row['rating'])),
                'comment' => $row['comment'] ?? '',
                'review_date' => $row['review_date'] ?? '',
                'reviewer_name' => $row['reviewer_name'] ?? 'Valued Client',
                'service_type' => $row['service_type'] ?? 'Pet Care'
            ];
        }
    } else {
        $review_db_error = true;
    }
} catch (Throwable $e) {
    $review_db_error = true;
}

$parent_text = ($total_reviews == 1) ? "happy fur-parent" : "happy fur-parents";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Us | Boogie's Pet Care Services</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --brand-yellow: #ffcc00;
            --brand-blue: #001f3f;
            --brand-blue-2: #0b3b66;
            --brand-blue-light: #eef5fb;
            --dark-bg: var(--brand-blue);
            --text-on-yellow: #1e293b;
            --text: #17324d;
            --muted: #6b7c8f;
            --border: #e4eaf1;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Poppins', sans-serif;
            color: var(--text);
            background: #f7f9fc;
            line-height: 1.6;
        }


        /* TOP BAR */
        .promo-bar {
            background: var(--brand-blue);
            color: #fff;
            text-align: center;
            padding: 7px 16px;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: .2px;
        }
        .promo-bar i { color: var(--brand-yellow); margin-right: 7px; }

        /* HEADER */
        header {
            position: sticky;
            top: 0;
            background: rgba(255,255,255,.97);
            backdrop-filter: blur(12px);
            z-index: 1000;
            border-bottom: 1px solid var(--border);
            box-shadow: 0 4px 18px rgba(0,0,0,.04);
        }
        .nav-top {
            max-width: 1320px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: auto minmax(250px, 460px) auto;
            align-items: center;
            gap: 28px;
            padding: 14px 28px;
        }
        .logo { display: inline-flex; align-items: center; gap: 11px; text-decoration: none; min-width: 0; }
        .nav-logo-img { height: 50px; width: 50px; object-fit: contain; display: block; border-radius: 10px; }
        .logo-text { display: flex; flex-direction: column; line-height: 1.05; }
        .logo-text b { font-size: 20px; color: var(--brand-blue); }
        .logo-text span { font-size: 9px; color: #8c9aae; text-transform: uppercase; letter-spacing: 1.2px; font-weight: 700; margin-top: 3px; }
        .search { display:flex; align-items:center; background:#f5f8fb; border:1px solid #e0e7ef; border-radius: 13px; padding: 5px 8px 5px 15px; }
        .search input { border:none; background:transparent; width:100%; padding:9px 6px; outline:none; font-size:13px; color:var(--text); }
        .search input::placeholder { color:#97a4b4; }
        .search button { width:38px; height:38px; border:none; border-radius:10px; background:var(--brand-blue); color:#fff; cursor:pointer; transition:.25s; }
        .search button:hover { background:var(--brand-blue-2); transform:translateY(-1px); }
        .nav-links { display:flex; justify-content:flex-end; align-items:center; gap:12px; flex-wrap:wrap; }
        .hello-user { color:var(--brand-blue); font-weight:600; font-size:13px; white-space:nowrap; }
        .cart-btn { background:var(--brand-blue); color:var(--brand-yellow); padding:10px 18px; border-radius:10px; text-decoration:none; font-weight:700; font-size:13px; transition:.25s; }
        .cart-btn:hover { transform:translateY(-2px); box-shadow:0 8px 20px rgba(0,31,63,.16); }
        .logout-link { color:#dc3b45; text-decoration:none; font-weight:600; font-size:13px; padding:9px 4px; }
        .login-btn { background:var(--brand-blue); color:#fff; border-radius:10px; padding:10px 18px; text-decoration:none; font-weight:700; font-size:13px; }

        /* CATEGORY NAV */
        .categories { background: var(--brand-yellow); border-bottom:1px solid rgba(0,0,0,.08); }
        .categories ul { max-width: 980px; margin:0 auto; display:flex; justify-content:center; align-items:center; gap:8px; list-style:none; padding:7px 18px; }
        .categories ul li a { text-decoration:none; color:var(--brand-blue); font-size:12px; font-weight:800; padding:10px 18px; border-radius:10px; transition:.25s; display:flex; align-items:center; gap:8px; }
        .categories ul li a:hover, .categories ul li a.active { background:rgba(0,31,63,.12); transform:translateY(-1px); }
        .categories i { font-size:14px; }

        /* ===== CONTACT PAGE ===== */
        .contact-hero {
            position: relative;
            overflow: hidden;
            background:
                radial-gradient(circle at 90% 14%, rgba(255,204,0,.20), transparent 28%),
                linear-gradient(135deg, #eef7ff 0%, #ffffff 58%, #fff9e7 100%);
            border-bottom: 1px solid var(--border);
        }

        .contact-hero::before,
        .contact-hero::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            pointer-events: none;
        }

        .contact-hero::before {
            width: 340px;
            height: 340px;
            right: -110px;
            top: -125px;
            background: rgba(255,204,0,.12);
        }

        .contact-hero::after {
            width: 240px;
            height: 240px;
            left: -90px;
            bottom: -120px;
            background: rgba(22,115,165,.07);
        }

        .contact-hero-inner {
            max-width: 1180px;
            margin: 0 auto;
            padding: 62px 28px 52px;
            display: grid;
            grid-template-columns: 1.3fr .7fr;
            align-items: center;
            gap: 30px;
            position: relative;
            z-index: 1;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #fff;
            border: 1px solid var(--border);
            color: var(--brand-blue);
            padding: 7px 13px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .6px;
            text-transform: uppercase;
            box-shadow: 0 6px 18px rgba(0,31,63,.05);
        }

        .eyebrow i { color: var(--brand-yellow); }

        .contact-hero h1 {
            max-width: 760px;
            margin: 18px 0 14px;
            color: var(--brand-blue);
            font-size: 44px;
            line-height: 1.12;
            font-weight: 800;
            letter-spacing: -1px;
        }

        .contact-hero p {
            max-width: 680px;
            color: var(--muted);
            font-size: 16px;
        }

        .contact-hero-note {
            display: flex;
            flex-wrap: wrap;
            gap: 18px;
            margin-top: 21px;
            color: #708197;
            font-size: 12px;
            font-weight: 600;
        }

        .contact-hero-note span {
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }

        .contact-hero-note i { color: #15906f; }

        .contact-hero-art {
            width: 210px;
            height: 210px;
            justify-self: end;
            border-radius: 50%;
            background: linear-gradient(145deg, #001f3f, #0d3b66);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 18px 40px rgba(0,31,63,.18);
        }

        .contact-hero-art i {
            color: var(--brand-yellow);
            font-size: 76px;
        }

        .contact-section {
            max-width: 1180px;
            margin: 0 auto;
            padding: 55px 28px 70px;
        }

        .contact-grid {
            display: grid;
            grid-template-columns: .9fr 1.25fr;
            gap: 24px;
            align-items: stretch;
        }

        .info-card,
        .form-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 20px;
            box-shadow: 0 8px 28px rgba(0,31,63,.05);
        }

        .info-card {
            padding: 30px;
        }

        .form-card {
            padding: 32px;
        }

        .card-heading {
            margin-bottom: 23px;
        }

        .card-heading .kicker {
            color: #8b99a9;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            margin-bottom: 6px;
        }

        .card-heading h2 {
            color: var(--brand-blue);
            font-size: 25px;
            font-weight: 800;
            line-height: 1.2;
        }

        .card-heading p {
            color: var(--muted);
            font-size: 12px;
            margin-top: 7px;
        }

        .info-item {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 15px 0;
            border-bottom: 1px solid #edf1f5;
        }

        .info-item:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }

        .info-icon {
            width: 44px;
            height: 44px;
            flex: 0 0 44px;
            border-radius: 13px;
            background: #fff7d6;
            color: var(--brand-blue);
            border: 1px solid #ffe89a;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
        }

        .info-item h4 {
            color: var(--brand-blue);
            font-size: 13px;
            font-weight: 800;
            margin-bottom: 3px;
        }

        .info-item p {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.6;
            margin: 0;
        }

        .form-group {
            margin-bottom: 17px;
        }

        .form-group label {
            display: block;
            color: var(--brand-blue);
            font-size: 12px;
            font-weight: 800;
            margin-bottom: 7px;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            border: 1px solid #dce5ef;
            border-radius: 11px;
            background: #fbfcfe;
            padding: 12px 14px;
            font-family: inherit;
            font-size: 13px;
            color: var(--text);
            outline: none;
            transition: .2s;
        }

        .form-group input:focus,
        .form-group textarea:focus {
            border-color: #9dbad0;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(0,31,63,.06);
        }

        .form-group textarea {
            height: 145px;
            resize: vertical;
            min-height: 120px;
        }

        .send-btn {
            width: 100%;
            border: 0;
            border-radius: 11px;
            padding: 13px 16px;
            background: var(--brand-blue);
            color: var(--brand-yellow);
            font-family: inherit;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            transition: .25s;
        }

        .send-btn:hover {
            background: var(--brand-blue-light);
            transform: translateY(-1px);
        }

        .reviews-section {
            background: #f4f8fc;
            border-top: 1px solid var(--border);
            padding: 62px 28px 78px;
        }

        .reviews-inner {
            max-width: 1180px;
            margin: 0 auto;
        }

        .reviews-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .reviews-kicker {
            color: #8b99a9;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1.3px;
            margin-bottom: 7px;
        }

        .reviews-header h2 {
            color: var(--brand-blue);
            font-size: 30px;
            font-weight: 800;
        }

        .overall-stars {
            color: #f4b900;
            font-size: 21px;
            display: flex;
            justify-content: center;
            gap: 4px;
            margin: 10px 0 7px;
        }

        .overall-rating-text {
            color: var(--muted);
            font-size: 12px;
        }

        .reviews-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }

        .review-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 23px;
            box-shadow: 0 8px 25px rgba(0,31,63,.045);
            display: flex;
            flex-direction: column;
            min-height: 220px;
        }

        .card-stars {
            display: flex;
            gap: 3px;
            color: #f4b900;
            font-size: 13px;
            margin-bottom: 13px;
        }

        .review-quote {
            color: #435466;
            font-size: 13px;
            line-height: 1.7;
            margin-bottom: 20px;
            flex: 1;
        }

        .reviewer-info {
            display: flex;
            align-items: center;
            gap: 11px;
            padding-top: 15px;
            border-top: 1px solid #edf1f5;
        }

        .reviewer-avatar {
            width: 42px;
            height: 42px;
            flex: 0 0 42px;
            border-radius: 50%;
            background: #fff7d6;
            border: 1px solid #ffdf70;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--brand-blue);
            font-size: 15px;
            font-weight: 800;
        }

        .reviewer-details h4 {
            color: var(--brand-blue);
            font-size: 13px;
            font-weight: 800;
            margin-bottom: 2px;
        }

        .reviewer-details p {
            color: #7c8b9b;
            font-size: 10px;
            margin: 0;
        }


        .reviews-empty {
            grid-column: 1 / -1;
            background: #fff;
            border: 1px dashed #d8e1eb;
            border-radius: 18px;
            padding: 42px 24px;
            text-align: center;
            color: var(--muted);
        }

        .reviews-empty-icon {
            width: 58px;
            height: 58px;
            margin: 0 auto 13px;
            border-radius: 50%;
            background: #fff7d6;
            color: #b18400;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .reviews-empty h3 {
            color: var(--brand-blue);
            font-size: 17px;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .reviews-empty p {
            max-width: 520px;
            margin: 0 auto;
            font-size: 12px;
            line-height: 1.7;
        }

        .read-more-container {
            text-align: center;
            margin-top: 27px;
        }

        .read-more-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--brand-blue);
            text-decoration: none;
            font-size: 12px;
            font-weight: 800;
        }

        .read-more-link:hover { color: #b18400; }

        /* ===== FOOTER ===== */
        footer {
            background: var(--brand-blue);
            padding: 68px 28px 30px;
            color: #fff;
            border-top: 4px solid var(--brand-yellow);
            margin-top: 0;
        }

        .footer-main {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1.5fr;
            gap: 42px;
            border-bottom: 1px solid rgba(255,255,255,.12);
            padding-bottom: 42px;
            max-width: 1180px;
            margin: 0 auto;
        }

        .footer-main h4 {
            color: var(--brand-yellow);
            margin-bottom: 16px;
            text-transform: uppercase;
            font-weight: 800;
            font-size: 12px;
            letter-spacing: .5px;
        }

        .footer-main p,
        .footer-main a {
            color: #cbd5e1;
            text-decoration: none;
            font-size: 12px;
            line-height: 1.7;
            display: block;
            margin-bottom: 9px;
        }

        .footer-main a:hover { color: #fff; }

        .socials {
            display: flex;
            gap: 10px;
            margin-top: 18px;
        }

        .socials a {
            background: rgba(255,255,255,.09);
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: .25s;
            color: #fff;
            margin: 0;
        }

        .socials a:hover {
            background: var(--brand-yellow);
            color: var(--brand-blue);
        }

        .footer-bottom {
            max-width: 1180px;
            margin: 0 auto;
            padding-top: 24px;
            text-align: center;
            font-size: 11px;
            color: #91a1b1;
        }

        @media (max-width: 1000px) {
            .contact-hero-inner {
                grid-template-columns: 1fr;
            }

            .contact-hero-art {
                justify-self: start;
                width: 155px;
                height: 155px;
            }

            .contact-hero-art i { font-size: 55px; }

            .contact-grid {
                grid-template-columns: 1fr;
            }

            .reviews-grid {
                grid-template-columns: 1fr 1fr;
            }

            .footer-main {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 680px) {
            .contact-hero h1 { font-size: 31px; }
            .contact-hero p { font-size: 14px; }

            .contact-section {
                padding: 40px 18px 60px;
            }

            .info-card,
            .form-card {
                padding: 23px;
            }

            .reviews-section {
                padding-left: 18px;
                padding-right: 18px;
            }

            .reviews-grid {
                grid-template-columns: 1fr;
            }

            .footer-main {
                grid-template-columns: 1fr;
            }
        }

    </style>
</head>

<body>

    <div class="promo-bar"><i class="fa-solid fa-phone"></i> Need help? Call us at (046) 887 4714</div>

    <header>
        <div class="nav-top">
            <a href="home.php" class="logo">
                <img src="bg.png" alt="Logo" class="nav-logo-img">
                <div class="logo-text">
                    <b>Boogie's</b>
                    <span>PET CARE SERVICES</span>
                </div>
            </a>
            
            <div class="search">
                <input type="text" placeholder="Search for grooming, hotel, or vet services...">
                <button><i class="fa-solid fa-magnifying-glass"></i></button>
            </div>
            
            <div class="nav-links">
                <?php if($is_logged_in): ?>
                    <span style="color: var(--brand-blue); font-weight: 600; font-size: 14px;"><i class="fa-regular fa-user"></i> Hi, <?php echo htmlspecialchars($user_name); ?></span>
                    <a href="dashboard.php" class="cart-btn">Dashboard</a>
                    <a href="logout.php" style="color: #ef4444; text-decoration:none; font-weight: 600; font-size: 14px; margin-left:10px;">Logout</a>
                <?php else: ?>
                    <a href="login.php" class="cart-btn">Login / Register</a>
                <?php endif; ?>
            </div>
        </div>
        
        <nav class="categories">
            <ul>
                <li><a href="petservices.php"><i class="fa-solid fa-paw"></i> PET SERVICES</a></li>
                <li><a href="grooming.php"><i class="fa-solid fa-scissors"></i> GROOMING</a></li>
                <li><a href="vetclinic.php"><i class="fa-solid fa-stethoscope"></i> VET CLINIC</a></li>
                <li><a href="pethotel.php"><i class="fa-solid fa-hotel"></i> PET HOTEL</a></li>
                <li><a href="contactus.php" class="active"><i class="fa-solid fa-phone"></i> CONTACT</a></li>
            </ul>
        </nav>
    </header>

    <main>
        <section class="contact-hero">
            <div class="contact-hero-inner">
                <div>
                    <div class="eyebrow">
                        <i class="fa-solid fa-phone"></i>
                        Boogie's Contact
                    </div>

                    <h1>We're here to help you and your pet.</h1>

                    <p>
                        Have a question about our services, appointments, or pet care?
                        Send us a message or reach us through the details below.
                    </p>

                    <div class="contact-hero-note">
                        <span><i class="fa-solid fa-circle-check"></i> Friendly support</span>
                        <span><i class="fa-solid fa-clock"></i> 9 AM to 6 PM</span>
                        <span><i class="fa-solid fa-location-dot"></i> Dasmariñas, Cavite</span>
                    </div>
                </div>

                <div class="contact-hero-art" aria-hidden="true">
                    <i class="fa-solid fa-headset"></i>
                </div>
            </div>
        </section>

        <section class="contact-section">
            <div class="contact-grid">
                <div class="info-card">
                    <div class="card-heading">
                        <div class="kicker">Contact information</div>
                        <h2>Get in touch</h2>
                        <p>Choose the easiest way to reach Boogie's Pet Care Services.</p>
                    </div>

                    <div class="info-item">
                        <div class="info-icon"><i class="fa-solid fa-phone"></i></div>
                        <div>
                            <h4>Phone</h4>
                            <p>(046) 887 4714</p>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-icon"><i class="fa-solid fa-clock"></i></div>
                        <div>
                            <h4>Business Hours</h4>
                            <p>9 AM to 6 PM - Vet & Grooming</p>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-icon"><i class="fa-solid fa-location-dot"></i></div>
                        <div>
                            <h4>Address</h4>
                            <p>110 Don Placido Campos Ave San Agustin 3, Dasmariñas, Philippines, 4114</p>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-icon"><i class="fa-solid fa-envelope"></i></div>
                        <div>
                            <h4>Email</h4>
                            <p>boogiespetcareservices@gmail.com</p>
                        </div>
                    </div>
                </div>

                <div class="form-card">
                    <div class="card-heading">
                        <div class="kicker">Send a message</div>
                        <h2>How can we help?</h2>
                        <p>Logged-in customers can send a message directly to our team.</p>
                    </div>

                    <form action="" method="POST">
                        <div class="form-group">
                            <label>Full Name</label>
                            <input
                                type="text"
                                name="name"
                                placeholder="Enter your full name"
                                value="<?php echo $is_logged_in ? htmlspecialchars($_SESSION['user_name']) : ''; ?>"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label>Email Address</label>
                            <input type="email" name="email" placeholder="example@gmail.com" required>
                        </div>

                        <div class="form-group">
                            <label>Message</label>
                            <textarea name="message" placeholder="How can we help you?" required></textarea>
                        </div>

                        <button type="submit" name="send_contact" class="send-btn">
                            <i class="fa-solid fa-paper-plane"></i>
                            Send Message
                        </button>
                    </form>
                </div>
            </div>
        </section>

        <section class="reviews-section">
            <div class="reviews-inner">
                <div class="reviews-header">
                    <div class="reviews-kicker">Customer reviews</div>
                    <h2>What our customers say</h2>

                    <div class="overall-stars">
                        <?php
                            if ($total_reviews > 0) {
                                $full_stars = floor((float)$avg_rating);

                                for ($i = 0; $i < 5; $i++) {
                                    if ($i < $full_stars) {
                                        echo '<i class="fa-solid fa-star"></i>';
                                    } elseif ($i == $full_stars && ((float)$avg_rating > $full_stars)) {
                                        echo '<i class="fa-solid fa-star-half-stroke"></i>';
                                    } else {
                                        echo '<i class="fa-regular fa-star"></i>';
                                    }
                                }
                            } else {
                                for ($i = 0; $i < 5; $i++) {
                                    echo '<i class="fa-regular fa-star"></i>';
                                }
                            }
                        ?>
                    </div>

                    <p class="overall-rating-text">
                        <?php
                            if ($review_db_error) {
                                echo "Reviews are temporarily unavailable.";
                            } elseif ($total_reviews > 0) {
                                echo "Rated " . htmlspecialchars($avg_rating) . "/5 by " . $total_reviews . " " . $parent_text;
                            } else {
                                echo "No reviews yet. Be the first happy fur-parent to share your experience!";
                            }
                        ?>
                    </p>
                </div>

                <div class="reviews-grid">
                    <?php if (!empty($reviews_list)): ?>
                        <?php foreach ($reviews_list as $review): ?>
                            <?php
                                $clean_name = htmlspecialchars($review['reviewer_name']);
                                $initial = strtoupper(substr($review['reviewer_name'], 0, 1));
                                if (!preg_match('/^[A-Z0-9]$/', $initial)) {
                                    $initial = 'P';
                                }

                                $rating = (int)$review['rating'];
                                $comment = htmlspecialchars(
                                    !empty($review['comment']) ? $review['comment'] : 'Excellent service!'
                                );
                                $service = htmlspecialchars($review['service_type']);
                            ?>

                            <article class="review-card">
                                <div class="card-stars" aria-label="<?php echo $rating; ?> out of 5 stars">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <?php if ($i <= $rating): ?>
                                            <i class="fa-solid fa-star"></i>
                                        <?php else: ?>
                                            <i class="fa-regular fa-star"></i>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                </div>

                                <p class="review-quote">"<?php echo $comment; ?>"</p>

                                <div class="reviewer-info">
                                    <div class="reviewer-avatar"><?php echo htmlspecialchars($initial); ?></div>
                                    <div class="reviewer-details">
                                        <h4><?php echo $clean_name; ?></h4>
                                        <p><?php echo $service; ?> Client</p>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="reviews-empty">
                            <div class="reviews-empty-icon">
                                <i class="fa-regular fa-comment-dots"></i>
                            </div>
                            <h3>No reviews yet</h3>
                            <p>Customer reviews will appear here automatically after they submit their experience.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($reviews_list)): ?>
                    <div class="read-more-container">
                        <a href="#" class="read-more-link">
                            Read More Reviews <i class="fa-solid fa-arrow-right"></i>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </section>

    <footer>
        <div class="footer-main">
            <div>
                <h4><i class="fa-solid fa-paw"></i> Boogie's Pet Care</h4>
                <p>Your trusted partner for all your pet care needs in Dasmariñas, Cavite.</p>
                <div class="socials">
                    <a href="https://www.facebook.com/boogiespetsupplies"><i class="fa-brands fa-facebook-f"></i></a>
                    <a href="mailto:boogiespetcareservices@gmail.com"><i class="fa-solid fa-envelope"></i></a>
                </div>
            </div>
            <div>   
                <h4>Quick Links</h4>
                <a href="home.php">Home</a>
                <a href="petservices.php">Services & Prices</a>
                <a href="contactus.php">Contact & Reviews</a>
                <a href="faqs.html">FAQs</a>
            </div>
            <div>
                <h4>Our Services</h4>
                <a href="grooming.php">Grooming</a>
                <a href="pethotel.php">Pet Hotel</a>
                <a href="vetclinic.php">Vet Clinic</a>
            </div>
            <div>
                <h4>Contact Details</h4>
                <p><i class="fa-solid fa-phone"></i> (046) 887 4714</p>
                <p><i class="fa-solid fa-envelope"></i> boogiespetcareservices@gmail.com</p>
                <p><i class="fa-solid fa-location-dot"></i> 110 Don Placido Campos Ave San Agustin 3, Dasmariñas, Philippines, 4114</p>
            </div>
        </div>
        <div class="footer-bottom">
            <p>© 2026 Boogie's Pet Care & Services - Dasmariñas Branch. All rights reserved.</p>
            <div style="margin-top:15px;">
                
            </div>
        </div>
    </footer>
</body>
</html>
