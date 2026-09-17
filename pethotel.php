<?php
session_start();

// Include your database connection
require_once 'db_connect.php'; 

// Check if user is logged in
$is_logged_in = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
$user_name = $is_logged_in ? $_SESSION['user_name'] : "Guest";

// Fetch ONLY pet hotel services from your services_pricelist table
$result = false;
if (isset($conn)) {
    try {
        // Look exactly for the categories in your database 
        $sql = "SELECT * FROM services_pricelist 
                WHERE category IN ('Pet Daycare', 'Pet Boarding') 
                AND is_available = 1 
                ORDER BY category DESC, price ASC"; // DESC so Daycare comes before Boarding, or adjust as needed
        $result = $conn->query($sql);
    } catch (mysqli_sql_exception $e) {
        $result = false; 
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pet Hotel | Boogie's Pet Care & Services</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            /* Dasmariñas Branch Brand Colors */
            --brand-yellow: #ffcc00; 
            --brand-blue: #001f3f; 
            --brand-blue-light: #002d5b;
            --dark-bg: var(--brand-blue);
            --light-text: #ffffff;
            --text-on-yellow: #1e293b;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; color: #1e293b; background: #fff; line-height: 1.6; display: flex; flex-direction: column; min-height: 100vh;}


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



        /* ===== PET HOTEL PAGE ===== */
        main { min-height: 70vh; }

        .hero {
            position: relative;
            overflow: hidden;
            background:
                radial-gradient(circle at 90% 16%, rgba(255,204,0,.20), transparent 28%),
                linear-gradient(135deg, #fffbed 0%, #ffffff 58%, #eef7ff 100%);
            border-bottom: 1px solid var(--border);
        }

        .hero::before,
        .hero::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            pointer-events: none;
        }

        .hero::before {
            width: 350px;
            height: 350px;
            right: -110px;
            top: -135px;
            background: rgba(255,204,0,.13);
        }

        .hero::after {
            width: 240px;
            height: 240px;
            left: -90px;
            bottom: -120px;
            background: rgba(0,91,140,.07);
        }

        .hero-inner {
            max-width: 1180px;
            margin: 0 auto;
            padding: 62px 28px 52px;
            display: grid;
            grid-template-columns: 1.35fr .65fr;
            gap: 30px;
            align-items: center;
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
            text-transform: uppercase;
            letter-spacing: .6px;
            box-shadow: 0 6px 18px rgba(0,31,63,.05);
        }

        .eyebrow i { color: #c98500; }

        .hero h1 {
            max-width: 760px;
            margin: 18px 0 14px;
            color: var(--brand-blue);
            font-size: 44px;
            line-height: 1.12;
            font-weight: 800;
            letter-spacing: -1px;
        }

        .hero p {
            max-width: 710px;
            color: var(--muted);
            font-size: 16px;
        }

        .hero-note {
            display: flex;
            flex-wrap: wrap;
            gap: 18px;
            margin-top: 21px;
            color: #708197;
            font-size: 12px;
            font-weight: 600;
        }

        .hero-note span {
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }

        .hero-note i { color: #15906f; }

        .hero-art {
            width: 220px;
            height: 220px;
            justify-self: end;
            border-radius: 50%;
            background: linear-gradient(145deg, #8f6200, #d39b17);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 18px 40px rgba(115,82,0,.18);
        }

        .hero-art i {
            color: #fff;
            font-size: 78px;
        }

        .services-wrap {
            max-width: 1180px;
            margin: 0 auto;
            padding: 54px 28px 90px;
        }

        .section-heading {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 25px;
            margin-bottom: 26px;
        }

        .section-kicker {
            color: #8b99a9;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.3px;
            font-weight: 800;
            margin-bottom: 7px;
        }

        .section-heading h2 {
            color: var(--brand-blue);
            font-size: 28px;
            line-height: 1.2;
            font-weight: 800;
        }

        .section-heading p {
            max-width: 510px;
            color: var(--muted);
            font-size: 13px;
            text-align: right;
        }

        .services-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 24px;
        }

        .service-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 20px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            min-height: 400px;
            box-shadow: 0 8px 28px rgba(0,31,63,.05);
            transition: transform .28s ease, box-shadow .28s ease, border-color .28s ease;
        }

        .service-card:hover {
            transform: translateY(-7px);
            box-shadow: 0 18px 40px rgba(0,31,63,.10);
            border-color: #e2ce84;
        }

        .service-visual {
            height: 165px;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .service-visual::after {
            content: '';
            position: absolute;
            width: 170px;
            height: 170px;
            border-radius: 50%;
            right: -60px;
            bottom: -90px;
            background: rgba(255,255,255,.38);
        }

        .service-visual.daycare {
            background: linear-gradient(145deg, #fff7d8, #ffedb8);
        }

        .service-visual.boarding {
            background: linear-gradient(145deg, #e9f4ff, #d9edff);
        }

        .service-visual.default {
            background: linear-gradient(145deg, #fff8df, #e9f5ff);
        }

        .visual-icon {
            width: 88px;
            height: 88px;
            border-radius: 50%;
            background: rgba(255,255,255,.86);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            z-index: 1;
            font-size: 39px;
            box-shadow: 0 10px 24px rgba(0,31,63,.08);
        }

        .daycare .visual-icon { color: #bb7c00; }
        .boarding .visual-icon { color: #26709f; }
        .default .visual-icon { color: #946d00; }

        .service-body {
            display: flex;
            flex-direction: column;
            flex: 1;
            padding: 25px 24px 23px;
        }

        .service-tag {
            align-self: flex-start;
            display: inline-flex;
            padding: 6px 10px;
            margin-bottom: 11px;
            border-radius: 999px;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .6px;
            font-weight: 800;
        }

        .tag-daycare {
            background: #fff1c7;
            color: #986700;
        }

        .tag-boarding {
            background: #e6f4ff;
            color: #286d99;
        }

        .tag-default {
            background: #eef2f6;
            color: #5f6f80;
        }

        .service-body h3 {
            color: var(--brand-blue);
            font-size: 21px;
            line-height: 1.35;
            margin-bottom: 7px;
            font-weight: 800;
        }

        .service-name {
            color: #66788b;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 14px;
        }

        .service-description {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.7;
            margin-bottom: 22px;
            flex: 1;
        }

        .card-footer {
            border-top: 1px solid #edf1f5;
            padding-top: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .price-label {
            display: block;
            color: #94a3b8;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .4px;
            font-weight: 700;
        }

        .price {
            display: block;
            color: var(--brand-blue);
            font-size: 22px;
            font-weight: 800;
            margin-top: 1px;
        }

        .btn-book {
            min-width: 112px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px 15px;
            border-radius: 11px;
            background: var(--brand-blue);
            color: var(--brand-yellow);
            text-decoration: none;
            font-size: 12px;
            font-weight: 800;
            transition: .25s;
        }

        .btn-book:hover {
            background: var(--brand-blue-light);
            transform: translateY(-1px);
        }

        .hotel-note {
            margin-top: 30px;
            background: var(--brand-blue);
            color: #fff;
            border-radius: 20px;
            padding: 22px 26px;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            box-shadow: 0 14px 30px rgba(0,31,63,.13);
        }

        .hotel-note-item {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .hotel-note-item i {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: rgba(255,204,0,.13);
            color: var(--brand-yellow);
            display: flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 40px;
        }

        .hotel-note-item strong {
            display: block;
            font-size: 12px;
        }

        .hotel-note-item span {
            display: block;
            color: #c8d3df;
            font-size: 10px;
            margin-top: 2px;
        }

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
            display: block;
            margin-bottom: 9px;
            line-height: 1.7;
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

        @media (max-width: 980px) {
            .nav-top { grid-template-columns: 1fr; gap: 12px; }
            .nav-links { justify-content: flex-start; }
            .hero-inner { grid-template-columns: 1fr; }
            .hero h1 { font-size: 37px; }
            .hero-art { justify-self: start; width: 155px; height: 155px; }
            .hero-art i { font-size: 55px; }
            .services-grid { grid-template-columns: 1fr; }
            .hotel-note { grid-template-columns: 1fr; }
            .footer-main { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 680px) {
            .categories ul { overflow-x: auto; justify-content: flex-start; padding-left: 12px; }
            .categories ul li a { white-space: nowrap; padding: 10px 13px; }
            .hero-inner { padding-top: 45px; }
            .hero h1 { font-size: 31px; }
            .hero p { font-size: 14px; }
            .section-heading { display: block; }
            .section-heading p { text-align: left; margin-top: 8px; }
            .footer-main { grid-template-columns: 1fr; }
            .card-footer { align-items: stretch; }
            .btn-book { min-width: 105px; }
        }

        .no-data {
            grid-column: 1 / -1;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 60px 25px;
            text-align: center;
            color: var(--muted);
            box-shadow: 0 8px 25px rgba(15,23,42,.04);
            font-size: 14px;
        }

        .no-data i {
            font-size: 42px;
            color: #cbd5e1;
            margin-bottom: 14px;
            display: block;
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
                <li><a href="pethotel.php" class="active"><i class="fa-solid fa-hotel"></i> PET HOTEL</a></li>
                <li><a href="contactus.php"><i class="fa-solid fa-phone"></i> CONTACT</a></li>
            </ul>
        </nav>
    </header>



    <main>
        <section class="hero">
            <div class="hero-inner">
                <div>
                    <div class="eyebrow">
                        <i class="fa-solid fa-hotel"></i>
                        Boogie's Pet Hotel
                    </div>

                    <h1>A safe and comfortable stay for your pet.</h1>

                    <p>
                        Whether your pet needs daytime care or an overnight stay,
                        explore our available pet hotel services and choose the option
                        that best fits your needs.
                    </p>

                    <div class="hero-note">
                        <span><i class="fa-solid fa-circle-check"></i> Comfortable environment</span>
                        <span><i class="fa-solid fa-heart"></i> Pet-focused care</span>
                        <span><i class="fa-solid fa-location-dot"></i> Dasmariñas, Cavite</span>
                    </div>
                </div>

                <div class="hero-art" aria-hidden="true">
                    <i class="fa-solid fa-hotel"></i>
                </div>
            </div>
        </section>

        <section class="services-wrap">
            <div class="section-heading">
                <div>
                    <div class="section-kicker">Pet hotel & daycare</div>
                    <h2>Choose the right stay for your pet</h2>
                </div>

                <p>
                    View the current rates, check the available service,
                    and book your pet's stay online.
                </p>
            </div>

            <div class="services-grid">

            <?php if ($result && $result->num_rows > 0): ?>
                <?php while($row = $result->fetch_assoc()): ?>
                    <?php
                        $category = $row['category'];
                        $category_lower = strtolower($category);

                        if (stripos($category_lower, 'daycare') !== false) {
                            $visual_class = 'daycare';
                            $tag_class = 'tag-daycare';
                            $icon = 'fa-sun';
                            $tag_text = 'Daycare';
                            $description = 'A supervised daytime stay where your pet can relax, play, and enjoy a comfortable environment.';
                        } elseif (stripos($category_lower, 'boarding') !== false) {
                            $visual_class = 'boarding';
                            $tag_class = 'tag-boarding';
                            $icon = 'fa-bed';
                            $tag_text = 'Overnight boarding';
                            $description = 'A safe and comfortable place for your pet to stay while you are away.';
                        } else {
                            $visual_class = 'default';
                            $tag_class = 'tag-default';
                            $icon = 'fa-hotel';
                            $tag_text = 'Pet hotel';
                            $description = 'Comfortable pet care designed to give your furry companion a safe and pleasant stay.';
                        }
                    ?>
                    <article class="service-card">
                        <div class="service-visual <?php echo $visual_class; ?>">
                            <div class="visual-icon">
                                <i class="fa-solid <?php echo $icon; ?>"></i>
                            </div>
                        </div>

                        <div class="service-body">
                            <span class="service-tag <?php echo $tag_class; ?>">
                                <?php echo htmlspecialchars($tag_text); ?>
                            </span>

                            <h3><?php echo htmlspecialchars($category); ?></h3>

                            <div class="service-name">
                                <?php echo htmlspecialchars($row['service_name']); ?>
                            </div>

                            <p class="service-description">
                                <?php echo htmlspecialchars($description); ?>
                            </p>

                            <div class="card-footer">
                                <div>
                                    <span class="price-label">Service rate</span>
                                    <span class="price">₱<?php echo number_format($row['price'], 2); ?></span>
                                </div>

                                <a href="<?php echo $is_logged_in ? 'book_appointment.php?service_id=' . $row['id'] . '&category=Hotel' : 'login.php'; ?>" class="btn-book">
                                    Book Now <i class="fa-solid fa-arrow-right"></i>
                                </a>
                            </div>
                        </div>
                    </article>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="no-data">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <p>We are currently updating our pet hotel and daycare pricelist. Please check back later!</p>
                </div>
            <?php endif; ?>

            </div>

            <div class="hotel-note">
                <div class="hotel-note-item">
                    <i class="fa-solid fa-house"></i>
                    <div>
                        <strong>Comfortable stay</strong>
                        <span>A welcoming space for your furry companion.</span>
                    </div>
                </div>

                <div class="hotel-note-item">
                    <i class="fa-solid fa-shield-dog"></i>
                    <div>
                        <strong>Pet-first care</strong>
                        <span>Designed around comfort and peace of mind.</span>
                    </div>
                </div>

                <div class="hotel-note-item">
                    <i class="fa-solid fa-calendar-check"></i>
                    <div>
                        <strong>Easy booking</strong>
                        <span>Select your service and schedule your stay.</span>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer>
        <div class="footer-main">
            <div>
                <h4 style="display:flex; align-items:center; gap:10px;"><i class="fa-solid fa-paw"></i> Boogie's Pet Care</h4>
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
                <h4>Services</h4>
                <a href="grooming.php">Grooming</a>
                <a href="pethotel.php">Pet Hotel</a>
                <a href="vetclinic.php">Vet Clinic</a>
            </div>
            <div>
                <h4>Contact Us</h4>
                <p><i class="fa-solid fa-phone"></i> (046) 887 4714</p>
                <p><i class="fa-solid fa-envelope"></i> boogiespetcareservices@gmail.com</p>
                <p><i class="fa-solid fa-location-dot"></i> 110 Don Placido Campos Ave San Agustin 3, Dasmariñas, Philippines, 4114</p>
            </div>
        </div>
        <div class="footer-bottom">
            <p>© 2026 Boogie's Pet Care & Services - Dasmariñas Branch. All rights reserved.</p>
            <div style="margin-top:10px;">
                
            </div>
        </div>
    </footer>

</body>

</body>
</html>
