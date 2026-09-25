<?php

session_start();

$is_logged_in = isset($_SESSION['logged_in'])
    && $_SESSION['logged_in'] === true;

$user_name = $is_logged_in
    ? ($_SESSION['user_name'] ?? 'Guest')
    : 'Guest';

include 'db_supabase.php';

// Security: Redirect to login if not logged in
if (!$is_logged_in) {
    header("Location: login.php");
    exit();
}


// ============================================================
// FETCH REVIEWS
// ============================================================

$reviews_list = [];

try {

    $review_stmt = $pdo->prepare("
        SELECT
            r.*,
            u.full_name,
            a.service
        FROM reviews r
        JOIN users u
            ON r.user_id = u.id
        JOIN appointments a
            ON r.appointment_id = a.id
        WHERE r.rating >= 4
        ORDER BY r.review_date DESC
        LIMIT 3
    ");

    $review_stmt->execute();

    $reviews_list = $review_stmt->fetchAll();

} catch (PDOException $e) {

    $reviews_list = [];
}


// ============================================================
// FETCH AGGREGATE REVIEW STATS
// ============================================================

$total_reviews = 0;
$avg_rating = "0.0";

try {

    $stats_stmt = $pdo->query("
        SELECT
            COUNT(*) AS total_reviews,
            AVG(rating) AS avg_rating
        FROM reviews
    ");

    $stats_row = $stats_stmt->fetch();

    $total_reviews = (int)($stats_row['total_reviews'] ?? 0);

    $avg_rating = ($total_reviews > 0)
        ? number_format((float)$stats_row['avg_rating'], 1)
        : "0.0";

} catch (PDOException $e) {

    $total_reviews = 0;
    $avg_rating = "0.0";
}

$parent_text = ($total_reviews == 1)
    ? "happy fur-parent"
    : "happy fur-parents";


// ============================================================
// FETCH DYNAMIC PROMOTIONS
// ============================================================

$promos_list = [];

try {

    $current_date = date('Y-m-d');

    $promo_stmt = $pdo->prepare("
        SELECT *
        FROM promos
        WHERE status = 'active'
          AND (
                expiry_date >= :current_date
                OR expiry_date IS NULL
              )
        ORDER BY id DESC
        LIMIT 3
    ");

    $promo_stmt->execute([
        ':current_date' => $current_date
    ]);

    $promos_list = $promo_stmt->fetchAll();

} catch (PDOException $e) {

    $promos_list = [];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Boogie's Pet Care & Services - Dasmariñas Branch</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --brand-yellow: #ffcc00;
            --brand-yellow-soft: #fff6c7;
            --brand-blue: #001f3f;
            --brand-blue-2: #0b3b66;
            --brand-blue-light: #eef5fb;
            --dark-bg: var(--brand-blue);
            --light-text: #ffffff;
            --text-on-yellow: #1e293b;
            --text: #17324d;
            --muted: #6b7c8f;
            --border: #e4eaf1;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            font-family: 'Poppins', sans-serif;
            color: var(--text);
            background: #f7f9fc;
            line-height: 1.6;
            min-height: 100vh;
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

        /* ===== HOME PAGE ===== */
        main { min-height: 70vh; }

        .hero-slider {
            position: relative;
            overflow: hidden;
            background: var(--brand-blue);
        }

        .slider-track {
            display: flex;
            transition: transform .7s cubic-bezier(.4,0,.2,1);
            will-change: transform;
        }

        .slide {
            min-width: 100%;
            min-height: 560px;
            display: flex;
            align-items: center;
            position: relative;
            overflow: hidden;
        }

        .slide::before,
        .slide::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            pointer-events: none;
        }

        .slide::before {
            width: 420px;
            height: 420px;
            right: -120px;
            top: -160px;
            background: rgba(255,204,0,.11);
        }

        .slide::after {
            width: 270px;
            height: 270px;
            left: -120px;
            bottom: -145px;
            background: rgba(255,255,255,.06);
        }

        .slide-1 {
            background:
                linear-gradient(90deg, rgba(0,31,63,.91), rgba(0,31,63,.62)),
                url('https://images.unsplash.com/photo-1516734212186-a967f81ad0d7?q=80&w=2071&auto=format&fit=crop');
            background-size: cover;
            background-position: center;
        }

        .slide-2 {
            background:
                linear-gradient(90deg, rgba(0,31,63,.91), rgba(0,31,63,.62)),
                url('https://images.unsplash.com/photo-1583337130417-3346a1be7dee?q=80&w=1964&auto=format&fit=crop');
            background-size: cover;
            background-position: center;
        }

        .slide-inner {
            width: min(1180px, 92%);
            margin: 0 auto;
            padding: 80px 28px 95px;
            position: relative;
            z-index: 1;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.2);
            color: var(--brand-yellow);
            padding: 8px 13px;
            border-radius: 999px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .8px;
            font-weight: 800;
        }

        .hero-content {
            color: #fff;
            max-width: 670px;
            transform: translateY(18px);
            opacity: 0;
            transition: .7s ease;
        }
        .slide.active .hero-content {
            transform: translateY(0);
            opacity: 1;
        }

        .hero-content h1 {
            margin: 17px 0 15px;
            font-size: clamp(38px, 5vw, 58px);
            line-height: 1.08;
            letter-spacing: -1.2px;
            font-weight: 800;
        }

        .hero-content p {
            max-width: 620px;
            color: rgba(255,255,255,.88);
            font-size: 16px;
            line-height: 1.7;
            margin-bottom: 28px;
        }

        .btn-join {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            background: var(--brand-yellow);
            color: var(--brand-blue);
            padding: 13px 20px;
            border-radius: 11px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 800;
            transition: .25s;
        }
        .btn-join:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 22px rgba(255,204,0,.22);
        }

        .slider-dots {
            position: absolute;
            left: 50%;
            bottom: 28px;
            transform: translateX(-50%);
            display: flex;
            align-items: center;
            gap: 8px;
            z-index: 4;
        }
        .dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: rgba(255,255,255,.38);
            cursor: pointer;
            transition: .25s;
        }
        .dot.active {
            width: 26px;
            border-radius: 8px;
            background: var(--brand-yellow);
        }

        .stats-wrap {
            width: min(1180px, 92%);
            margin: -48px auto 0;
            position: relative;
            z-index: 5;
        }
        .stats-bar {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 20px;
            box-shadow: 0 18px 38px rgba(0,31,63,.08);
            overflow: hidden;
        }
        .stat-item {
            padding: 24px 18px;
            text-align: center;
            border-right: 1px solid var(--border);
        }
        .stat-item:last-child { border-right: 0; }
        .stat-item i {
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--brand-yellow);
            background: var(--brand-blue);
            width: 42px;
            height: 42px;
            border-radius: 12px;
            margin: 0 auto 10px;
        }
        .stat-item strong {
            display: block;
            color: var(--brand-blue);
            font-size: 24px;
            font-weight: 800;
        }
        .stat-item span {
            color: #8a99a9;
            font-size: 11px;
            font-weight: 700;
        }

        .home-section {
            max-width: 1180px;
            margin: 0 auto;
            padding: 72px 28px 0;
        }

        .section-heading {
            text-align: center;
            margin-bottom: 28px;
        }
        .section-kicker {
            color: #8b99a9;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1.3px;
            font-weight: 800;
            margin-bottom: 7px;
        }
        .section-heading h2 {
            color: var(--brand-blue);
            font-size: 30px;
            line-height: 1.2;
            font-weight: 800;
        }
        .section-heading p {
            color: var(--muted);
            font-size: 13px;
            max-width: 610px;
            margin: 8px auto 0;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 12px;
            background: var(--brand-yellow-soft);
            border: 1px solid #ffe68a;
            border-radius: 999px;
            color: #8b6900;
            font-size: 10px;
            text-transform: uppercase;
            font-weight: 800;
            letter-spacing: .7px;
            margin-bottom: 9px;
        }

        .promo-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
            gap: 24px;
            padding-bottom: 20px;
        }
        .promo-card {
            position: relative;
            min-height: 430px;
            border-radius: 19px;
            color: #fff;
            display: flex;
            flex-direction: column;
            box-shadow: 0 12px 28px rgba(0,0,0,.07);
            transition: .25s;
            overflow: hidden;
        }
        .promo-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 18px 35px rgba(0,0,0,.11);
        }

        .promo-grid .promo-card:only-child {
            width: min(700px, 100%);
            justify-self: center;
        }
        .promo-image {
            display: block;
            width: 100%;
            height: 240px;
            object-fit: cover;
            background: #eef2f7;
        }
        .promo-body {
            position: relative;
            flex: 1;
            padding: 23px 24px 21px;
            overflow: hidden;
        }
        .promo-body::after {
            content: '';
            position: absolute;
            width: 135px;
            height: 135px;
            border-radius: 50%;
            right: -48px;
            bottom: -58px;
            background: rgba(255,255,255,.11);
            pointer-events: none;
        }
        .promo-card:not(.has-image) .promo-body {
            min-height: 430px;
            padding: 29px 26px;
        }
        .promo-card .tag {
            align-self: flex-start;
            position: relative;
            z-index: 2;
            display: inline-flex;
            background: rgba(255,255,255,.17);
            border: 1px solid rgba(255,255,255,.16);
            padding: 5px 10px;
            border-radius: 999px;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: .7px;
            font-weight: 800;
            margin-bottom: 14px;
        }
        .promo-card h3 {
            position: relative;
            z-index: 2;
            font-size: 23px;
            line-height: 1.2;
            margin-bottom: 8px;
            font-weight: 800;
        }
        .promo-card p {
            position: relative;
            z-index: 2;
            margin: 0;
            font-size: 12px;
            line-height: 1.65;
            opacity: .92;
        }
        .promo-meta {
            position: relative;
            z-index: 2;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 15px;
            padding-top: 12px;
            border-top: 1px solid rgba(255,255,255,.18);
            color: rgba(255,255,255,.82);
            font-size: 10px;
            font-weight: 700;
        }
        .promo-meta i { color: var(--brand-yellow); }
        .promo-card.purple .promo-body { background: linear-gradient(135deg,#9b51e0,#7d31c7); }
        .promo-card.teal .promo-body { background: linear-gradient(135deg,#1bbba8,#0b8e80); }
        .promo-card.red .promo-body { background: linear-gradient(135deg,#ed6d72,#c92f3b); }
        .promo-card.orange .promo-body { background: linear-gradient(135deg,#f39b44,#d86a0a); }
        .promo-card:not(.has-image).purple .promo-body { background: linear-gradient(135deg,#9b51e0,#7d31c7); }
        .promo-card:not(.has-image).teal .promo-body { background: linear-gradient(135deg,#1bbba8,#0b8e80); }
        .promo-card:not(.has-image).red .promo-body { background: linear-gradient(135deg,#ed6d72,#c92f3b); }
        .promo-card:not(.has-image).orange .promo-body { background: linear-gradient(135deg,#f39b44,#d86a0a); }

        .service-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            padding-bottom: 20px;
        }
        .service-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 27px 20px;
            text-align: center;
            box-shadow: 0 8px 25px rgba(0,31,63,.045);
            transition: .25s;
        }
        .service-card:hover {
            transform: translateY(-6px);
            border-color: #d4dee8;
            box-shadow: 0 17px 32px rgba(0,31,63,.08);
        }
        .service-card i {
            width: 58px;
            height: 58px;
            border-radius: 16px;
            background: var(--brand-yellow-soft);
            color: var(--brand-blue);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 24px;
        }
        .service-card h4 {
            color: var(--brand-blue);
            font-size: 16px;
            font-weight: 800;
            margin-bottom: 9px;
        }
        .service-card a {
            color: #738497;
            text-decoration: none;
            font-size: 11px;
            font-weight: 800;
        }
        .service-card a:hover { color: var(--brand-blue); }

        .testimonials {
            margin-top: 72px;
            background: #f4f8fc;
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
            padding: 68px 28px 80px;
        }
        .testimonial-inner {
            max-width: 1180px;
            margin: 0 auto;
        }
        .testimonial-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-top: 28px;
        }
        .t-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 23px;
            box-shadow: 0 8px 25px rgba(0,31,63,.045);
        }
        .t-card .stars {
            color: #f4b900;
            font-size: 13px;
            margin-bottom: 13px;
        }
        .t-text {
            color: #435466;
            font-size: 13px;
            line-height: 1.7;
            margin-bottom: 19px;
        }
        .t-user {
            display: flex;
            align-items: center;
            gap: 11px;
            padding-top: 14px;
            border-top: 1px solid #edf1f5;
        }
        .t-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: var(--brand-yellow-soft);
            border: 1px solid #ffe081;
            color: var(--brand-blue);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 14px;
        }
        .t-user strong {
            display: block;
            color: var(--brand-blue);
            font-size: 12px;
            font-weight: 800;
        }
        .t-user small {
            color: #7d8b9a;
            font-size: 10px;
        }
        .empty-reviews {
            grid-column: 1 / -1;
            background: #fff;
            border: 1px dashed #d8e1eb;
            border-radius: 18px;
            padding: 37px 22px;
            text-align: center;
            color: var(--muted);
        }
        .empty-reviews i {
            width: 56px;
            height: 56px;
            margin: 0 auto 11px;
            border-radius: 50%;
            background: var(--brand-yellow-soft);
            color: #b18400;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }
        .empty-reviews h3 {
            color: var(--brand-blue);
            font-size: 16px;
            margin-bottom: 4px;
        }
        .empty-reviews p { font-size: 12px; }

        .reviews-link {
            display: flex;
            justify-content: center;
            margin-top: 25px;
            color: var(--brand-blue);
            text-decoration: none;
            font-size: 12px;
            font-weight: 800;
        }

        .cta-area {
            background: var(--brand-blue);
            padding: 38px 28px 46px;
        }
        .cta-box {
            max-width: 1000px;
            margin: 0 auto 38px;
            background: var(--brand-yellow);
            color: var(--brand-blue);
            border-radius: 20px;
            padding: 43px 30px;
            text-align: center;
            box-shadow: 0 18px 35px rgba(0,0,0,.17);
        }
        .cta-box h2 {
            font-size: 30px;
            font-weight: 800;
            margin-bottom: 9px;
        }
        .cta-box p {
            color: #3b4a55;
            font-size: 13px;
        }
        .cta-buttons {
            display: flex;
            justify-content: center;
            gap: 11px;
            flex-wrap: wrap;
            margin: 23px 0 17px;
        }
        .btn-blue-solid,
        .btn-blue-outline {
            padding: 11px 18px;
            border-radius: 10px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 800;
            transition: .25s;
        }
        .btn-blue-solid {
            background: var(--brand-blue);
            color: var(--brand-yellow);
        }
        .btn-blue-outline {
            border: 2px solid var(--brand-blue);
            color: var(--brand-blue);
            background: transparent;
        }
        .btn-blue-solid:hover,
        .btn-blue-outline:hover { transform: translateY(-1px); }
        .cta-footer {
            display: flex;
            justify-content: center;
            gap: 22px;
            flex-wrap: wrap;
            color: var(--brand-blue);
            font-size: 10px;
            font-weight: 700;
        }

        footer {
            background: var(--brand-blue);
            padding: 22px 28px 30px;
            color: #fff;
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

        @media (max-width: 980px) {
            .stats-bar { grid-template-columns: 1fr 1fr; }
            .stat-item:nth-child(2) { border-right: 0; }
            .stat-item:nth-child(-n+2) { border-bottom: 1px solid var(--border); }
            .promo-grid { grid-template-columns: 1fr 1fr; }
            .service-grid { grid-template-columns: 1fr 1fr; }
            .testimonial-grid { grid-template-columns: 1fr 1fr; }
            .footer-main { grid-template-columns: 1fr 1fr; }
            .hero-content h1 { font-size: 40px; }
        }

        /* MOBILE NAVIGATION */
        .menu-toggle {
            display: none;
            width: 42px;
            height: 42px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #fff;
            color: var(--brand-blue);
            cursor: pointer;
            font-size: 18px;
            align-items: center;
            justify-content: center;
        }

        .menu-toggle:focus-visible {
            outline: 3px solid rgba(255, 204, 0, .45);
            outline-offset: 2px;
        }

        @media (max-width: 680px) {
            body { overflow-x: hidden; }

            .promo-bar {
                padding: 7px 10px;
                font-size: 10px;
            }

            .nav-top {
                grid-template-columns: 1fr auto;
                gap: 10px;
                padding: 10px 14px;
            }

            .logo {
                gap: 8px;
            }

            .nav-logo-img {
                width: 42px;
                height: 42px;
            }

            .logo-text b {
                font-size: 16px;
            }

            .logo-text span {
                font-size: 7px;
                letter-spacing: .9px;
            }

            .nav-links {
                gap: 7px;
                flex-wrap: nowrap;
            }

            .hello-user {
                display: none;
            }

            .nav-links .cart-btn {
                padding: 9px 11px;
                font-size: 11px;
            }

            .logout-link {
                font-size: 11px;
                padding: 8px 2px;
            }

            .menu-toggle {
                display: inline-flex;
            }

            .search {
                grid-column: 1 / -1;
                width: 100%;
            }

            .search input {
                font-size: 12px;
                padding: 8px 5px;
            }

            .search button {
                width: 36px;
                height: 36px;
            }

            .categories {
                position: relative;
            }

            .categories ul {
                display: none;
                flex-direction: column;
                align-items: stretch;
                gap: 2px;
                padding: 8px 12px 10px;
                max-width: none;
            }

            .categories.open ul {
                display: flex;
            }

            .categories ul li a {
                justify-content: flex-start;
                padding: 10px 12px;
                font-size: 12px;
            }

            .slide {
                min-height: 470px;
            }

            .slide-inner {
                width: 100%;
                padding: 54px 18px 82px;
            }

            .hero-content {
                max-width: 100%;
            }

            .hero-badge {
                font-size: 9px;
                padding: 7px 10px;
            }

            .hero-content h1 {
                font-size: 31px;
                letter-spacing: -.6px;
                margin-top: 14px;
            }

            .hero-content p {
                font-size: 13px;
                line-height: 1.65;
                max-width: 100%;
            }

            .btn-join {
                width: 100%;
                justify-content: center;
                padding: 13px 16px;
                font-size: 12px;
            }

            .slider-dots {
                bottom: 20px;
            }

            .stats-wrap {
                width: calc(100% - 24px);
                margin-top: -28px;
            }

            .stats-bar {
                grid-template-columns: 1fr 1fr;
                border-radius: 16px;
            }

            .stat-item {
                padding: 17px 8px;
                border-right: 1px solid var(--border);
                border-bottom: 1px solid var(--border);
            }

            .stat-item:nth-child(2),
            .stat-item:nth-child(4) {
                border-right: 0;
            }

            .stat-item:nth-child(3),
            .stat-item:nth-child(4) {
                border-bottom: 0;
            }

            .stat-item i {
                width: 36px;
                height: 36px;
                border-radius: 10px;
                margin-bottom: 7px;
                font-size: 13px;
            }

            .stat-item strong {
                font-size: 18px;
            }

            .stat-item span {
                font-size: 9px;
            }

            .home-section {
                width: 100%;
                padding: 50px 14px 0;
            }

            .section-heading {
                margin-bottom: 22px;
            }

            .section-heading h2 {
                font-size: 24px;
            }

            .section-heading p {
                font-size: 11px;
            }

            .promo-grid,
            .service-grid,
            .testimonial-grid {
                grid-template-columns: 1fr;
                gap: 14px;
            }

            .promo-card {
                min-height: 180px;
                padding: 24px 20px;
            }

            .promo-card h3 {
                font-size: 22px;
            }

            .service-card {
                padding: 24px 18px;
            }

            .testimonials {
                margin-top: 52px;
                padding: 52px 14px 62px;
            }

            .t-card {
                padding: 19px;
            }

            .t-text {
                font-size: 12px;
            }

            .cta-area {
                padding: 28px 14px 34px;
            }

            .cta-box {
                padding: 30px 18px;
                border-radius: 16px;
            }

            .cta-box h2 {
                font-size: 23px;
            }

            .cta-box p {
                font-size: 11px;
            }

            .cta-buttons {
                flex-direction: column;
            }

            .btn-blue-solid,
            .btn-blue-outline {
                width: 100%;
                text-align: center;
                padding: 12px 16px;
            }

            .cta-footer {
                gap: 9px;
                flex-direction: column;
                font-size: 9px;
            }

            footer {
                padding: 20px 14px 26px;
            }

            .footer-main {
                grid-template-columns: 1fr;
                gap: 25px;
            }

            .footer-main h4 {
                font-size: 11px;
            }

            .footer-main p,
            .footer-main a {
                font-size: 11px;
            }

            .search-status {
                top: auto;
                bottom: 16px;
                left: 12px;
                right: 12px;
                width: auto;
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
            
            <form class="search" id="siteSearchForm" autocomplete="off">
                <input
                    type="search"
                    id="siteSearchInput"
                    placeholder="Search for grooming, hotel, or vet services..."
                    aria-label="Search services"
                >
                <button type="submit" aria-label="Search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </button>
            </form>
            
            <div class="nav-links">
                <?php if($is_logged_in): ?>
                    <span class="hello-user"><i class="fa-regular fa-user"></i> Hi, <?php echo htmlspecialchars($user_name); ?></span>
                    <a href="dashboard.php" class="cart-btn">Dashboard</a>
                    <a href="logout.php" class="logout-link">Logout</a>
                <?php else: ?>
                    <a href="login.php" class="cart-btn">Login / Register</a>
                <?php endif; ?>

                <button type="button" class="menu-toggle" id="menuToggle"
                        aria-label="Open navigation menu" aria-expanded="false" aria-controls="categoryMenu">
                    <i class="fa-solid fa-bars"></i>
                </button>
            </div>
        </div>
        
        <nav class="categories" id="categoryNav">
            <ul id="categoryMenu">
                <li><a href="petservices.php" class="active"><i class="fa-solid fa-paw"></i> PET SERVICES</a></li>
                <li><a href="grooming.php"><i class="fa-solid fa-scissors"></i> GROOMING</a></li>
                <li><a href="vetclinic.php"><i class="fa-solid fa-stethoscope"></i> VET CLINIC</a></li>
                <li><a href="pethotel.php"><i class="fa-solid fa-hotel"></i> PET HOTEL</a></li>
                <li><a href="contactus.php"><i class="fa-solid fa-phone"></i> CONTACT</a></li>
            </ul>
        </nav>
    </header>

    <main>
        <section class="hero-slider">
            <div class="slider-track">

                <article class="slide slide-1 active">
                    <div class="slide-inner">
                        <div class="hero-content">
                            <div class="hero-badge">
                                <i class="fa-solid fa-paw"></i> Boogie's Pet Care
                            </div>
                            <h1>Exceptional care for your best friend.</h1>
                            <p>
                                Dasmariñas' pet care destination for professional grooming,
                                veterinary services, and a comfortable place to stay.
                            </p>
                            <a href="petservices.php" class="btn-join">
                                Explore Our Services <i class="fa-solid fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                </article>

                <article class="slide slide-2">
                    <div class="slide-inner">
                        <div class="hero-content">
                            <div class="hero-badge">
                                <i class="fa-solid fa-heart-pulse"></i> Pet health & wellness
                            </div>
                            <h1>Professional veterinary care for every stage.</h1>
                            <p>
                                Help keep your furry family members healthy and happy
                                with convenient veterinary services and appointment booking.
                            </p>
                            <a href="vetclinic.php" class="btn-join">
                                View Vet Services <i class="fa-solid fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                </article>

            </div>

            <div class="slider-dots" aria-label="Hero slider controls">
                <span class="dot active" onclick="setSlide(0)"></span>
                <span class="dot" onclick="setSlide(1)"></span>
            </div>
        </section>

        <section class="stats-wrap">
            <div class="stats-bar">
                <div class="stat-item">
                    <i class="fa-solid fa-award"></i>
                    <strong><?php echo number_format($total_reviews); ?></strong>
                    <span>Happy Customers</span>
                </div>
                <div class="stat-item">
                    <i class="fa-solid fa-star"></i>
                    <strong><?php echo $avg_rating; ?>/5</strong>
                    <span>Average Rating</span>
                </div>
                <div class="stat-item">
                    <i class="fa-solid fa-clock"></i>
                    <strong>9am - 6pm</strong>
                    <span>Shop Service</span>
                </div>
                <div class="stat-item">
                    <i class="fa-solid fa-shield-heart"></i>
                    <strong>5+</strong>
                    <span>Years in Service</span>
                </div>
            </div>
        </section>

        <section class="home-section">
            <div class="section-heading">
                <span class="badge"><i class="fa-solid fa-tag"></i> Limited-time offers</span>
                <h2>Special Promotions This Month</h2>
                <p>Don't miss out on current deals for your furry friends.</p>
            </div>

            <div class="promo-grid">
                <?php if (!empty($promos_list)): ?>
                    <?php foreach ($promos_list as $promo): ?>
                        <?php
                            $theme_class = !empty($promo['theme_color'])
                                ? htmlspecialchars($promo['theme_color'], ENT_QUOTES, 'UTF-8')
                                : 'purple';

                            $image_path = trim((string)($promo['image_url'] ?? ''));
                            if ($image_path !== '') {
                                $image_path = ltrim(str_replace('../', '', $image_path), '/');
                            }
                        ?>
                        <article class="promo-card <?php echo $theme_class; ?> <?php echo $image_path !== '' ? 'has-image' : ''; ?>">
                            <?php if ($image_path !== ''): ?>
                                <img
                                    class="promo-image"
                                    src="<?php echo htmlspecialchars($image_path, ENT_QUOTES, 'UTF-8'); ?>"
                                    alt="<?php echo htmlspecialchars($promo['title'] ?? 'Promotion', ENT_QUOTES, 'UTF-8'); ?>"
                                    loading="lazy"
                                >
                            <?php endif; ?>

                            <div class="promo-body">
                                <span class="tag"><?php echo htmlspecialchars($promo['tag'] ?? 'PROMO', ENT_QUOTES, 'UTF-8'); ?></span>
                                <h3><?php echo htmlspecialchars($promo['title'] ?? 'Special Offer', ENT_QUOTES, 'UTF-8'); ?></h3>
                                <p><?php echo htmlspecialchars($promo['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>

                                <?php if (!empty($promo['expiry_date'])): ?>
                                    <div class="promo-meta">
                                        <i class="fa-regular fa-clock"></i>
                                        Until <?php echo htmlspecialchars(date('M j, Y', strtotime($promo['expiry_date'])), ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="promo-card purple">
                        <div class="promo-body">
                            <span class="tag">Vet Clinic Deal</span>
                            <h3>Free Checkup</h3>
                            <p>Get a complimentary wellness consultation when you book a complete vaccination package.</p>
                        </div>
                    </div>
                    <div class="promo-card teal">
                        <div class="promo-body">
                            <span class="tag">Boarding Perk</span>
                            <h3>Stay 5, Get 1</h3>
                            <p>Book 5 nights at our Pet Hotel and get the 6th night FREE, plus a complimentary exit bath!</p>
                        </div>
                    </div>
                    <div class="promo-card red">
                        <div class="promo-body">
                            <span class="tag">Birthday Special</span>
                            <h3>50% OFF</h3>
                            <p>Is it your pet's birth month? Bring their records and get half off their next grooming session!</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="home-section">
            <div class="section-heading">
                <div class="section-kicker">Everything in one place</div>
                <h2>Our Premium Services</h2>
                <p>Explore the care your pet needs, from grooming to veterinary support.</p>
            </div>

            <div class="service-grid">
                <div class="service-card">
                    <i class="fa-solid fa-scissors"></i>
                    <h4>Pet Grooming</h4>
                    <a href="grooming.php">View Services <i class="fa-solid fa-arrow-right"></i></a>
                </div>
                <div class="service-card">
                    <i class="fa-solid fa-hotel"></i>
                    <h4>Pet Hotel</h4>
                    <a href="pethotel.php">View Services <i class="fa-solid fa-arrow-right"></i></a>
                </div>
                <div class="service-card">
                    <i class="fa-solid fa-stethoscope"></i>
                    <h4>Veterinary Care</h4>
                    <a href="vetclinic.php">View Services <i class="fa-solid fa-arrow-right"></i></a>
                </div>
                <div class="service-card">
                    <i class="fa-solid fa-calendar-check"></i>
                    <h4>Easy Booking</h4>
                    <a href="petservices.php">Get Started <i class="fa-solid fa-arrow-right"></i></a>
                </div>
            </div>
        </section>

        <section class="testimonials">
            <div class="testimonial-inner">
                <div class="section-heading">
                    <div class="section-kicker">Real customer feedback</div>
                    <h2>What Our Customers Say</h2>

                    <div style="color:#f4b900;font-size:20px;margin-top:10px;">
                        <?php
                            if ($total_reviews > 0) {
                                $full_stars = floor((float)$avg_rating);
                                for($i = 0; $i < 5; $i++) {
                                    if ($i < $full_stars) {
                                        echo '<i class="fa-solid fa-star"></i>';
                                    } elseif ($i == $full_stars && ((float)$avg_rating > $full_stars)) {
                                        echo '<i class="fa-solid fa-star-half-stroke"></i>';
                                    } else {
                                        echo '<i class="fa-regular fa-star"></i>';
                                    }
                                }
                            } else {
                                for($i = 0; $i < 5; $i++) {
                                    echo '<i class="fa-regular fa-star"></i>';
                                }
                            }
                        ?>
                    </div>

                    <p>
                        <?php
                            if ($total_reviews > 0) {
                                echo "Rated " . htmlspecialchars($avg_rating) . "/5 by " . number_format($total_reviews) . " " . $parent_text;
                            } else {
                                echo "No reviews yet. Be the first to share your experience!";
                            }
                        ?>
                    </p>
                </div>

                <div class="testimonial-grid">
                    <?php if (!empty($reviews_list)): ?>

    <?php foreach ($reviews_list as $row): ?>

        <?php
            $review_name = $row['full_name'] ?? 'Valued Client';
            $initial = strtoupper(substr($review_name, 0, 1));

            if (!preg_match('/^[A-Z0-9]$/', $initial)) {
                $initial = 'P';
            }
        ?>

        <article class="t-card">

            <div class="stars">
                <?php
                    for ($i = 1; $i <= 5; $i++) {
                        echo $i <= (int)$row['rating']
                            ? '<i class="fa-solid fa-star"></i>'
                            : '<i class="fa-regular fa-star"></i>';
                    }
                ?>
            </div>

            <p class="t-text">
                "<?php echo htmlspecialchars($row['comment'] ?? ''); ?>"
            </p>

            <div class="t-user">

                <div class="t-avatar">
                    <?php echo htmlspecialchars($initial); ?>
                </div>

                <div>
                    <strong>
                        <?php echo htmlspecialchars($review_name); ?>
                    </strong>

                    <small>
                        <?php echo htmlspecialchars($row['service'] ?? ''); ?>
                        Client
                    </small>
                </div>

            </div>

        </article>

    <?php endforeach; ?>

<?php else: ?>

    <div class="empty-reviews">

        <i class="fa-regular fa-comment-dots"></i>

        <h3>No reviews yet</h3>

        <p>
            Customer reviews will appear here automatically once submitted.
        </p>

    </div>

<?php endif; ?>
                </div>

                <a href="contactus.php" class="reviews-link">
                    Read More Reviews <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>
        </section>
    </main>

    <section class="cta-area">
        <div class="cta-box">
            <h2>Ready to give your pet the best care?</h2>
            <p>Choose a service and book your pet's next visit with Boogie's.</p>

            <div class="cta-buttons">
                <a href="dashboard.php" class="btn-blue-solid">Go to Dashboard</a>
                <a href="petservices.php" class="btn-blue-outline">Browse Services</a>
            </div>

            <div class="cta-footer">
                <span><i class="fa-solid fa-check"></i> Easy online booking</span>
                <span><i class="fa-solid fa-check"></i> Reliable daily support</span>
                <span><i class="fa-solid fa-check"></i> SMS notifications</span>
            </div>
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
            © 2026 Boogie's Pet Care & Services - Dasmariñas Branch. All rights reserved.
            <div style="margin-top:10px;">
                <a href="staff/stafflogin.php" style="color:#cbd5e1;text-decoration:none;font-weight:700;">
                    <i class="fa-solid fa-briefcase"></i> Personal Portal
                </a>
            </div>
        </div>
    </footer>

    <script>
        let currentSlideIndex = 0;
        const slides = document.querySelectorAll('.slide');
        const dots = document.querySelectorAll('.dot');
        const track = document.querySelector('.slider-track');

        function setSlide(index) {
            currentSlideIndex = index;
            track.style.transform = `translateX(-${index * 100}%)`;

            slides.forEach(s => s.classList.remove('active'));
            dots.forEach(d => d.classList.remove('active'));

            if (slides[index]) slides[index].classList.add('active');
            if (dots[index]) dots[index].classList.add('active');
        }

        if (slides.length > 1) {
            setInterval(() => {
                currentSlideIndex = (currentSlideIndex + 1) % slides.length;
                setSlide(currentSlideIndex);
            }, 6000);
        }

        /* ===== RESPONSIVE MOBILE MENU ===== */
        const menuToggle = document.getElementById('menuToggle');
        const categoryNav = document.getElementById('categoryNav');

        if (menuToggle && categoryNav) {
            menuToggle.addEventListener('click', function () {
                const isOpen = categoryNav.classList.toggle('open');
                menuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                menuToggle.setAttribute('aria-label', isOpen ? 'Close navigation menu' : 'Open navigation menu');

                const icon = menuToggle.querySelector('i');
                if (icon) {
                    icon.classList.toggle('fa-bars', !isOpen);
                    icon.classList.toggle('fa-xmark', isOpen);
                }
            });

            categoryNav.querySelectorAll('a').forEach(function (link) {
                link.addEventListener('click', function () {
                    categoryNav.classList.remove('open');
                    menuToggle.setAttribute('aria-expanded', 'false');
                    menuToggle.setAttribute('aria-label', 'Open navigation menu');

                    const icon = menuToggle.querySelector('i');
                    if (icon) {
                        icon.classList.remove('fa-xmark');
                        icon.classList.add('fa-bars');
                    }
                });
            });
        }

        /* ===== WORKING SITE SEARCH ===== */
        const searchForm = document.getElementById('siteSearchForm');
        const searchInput = document.getElementById('siteSearchInput');
        const searchStatus = document.getElementById('searchStatus');

        const searchRoutes = [
            {
                keywords: ['grooming', 'groom', 'bath', 'haircut', 'hair cut'],
                label: 'Grooming Services',
                url: 'grooming.php'
            },
            {
                keywords: ['vet', 'veterinary', 'clinic', 'checkup', 'check-up', 'vaccination', 'vaccine', 'deworming'],
                label: 'Vet Clinic',
                url: 'vetclinic.php'
            },
            {
                keywords: ['hotel', 'boarding', 'daycare', 'day care', 'stay', 'pet hotel'],
                label: 'Pet Hotel',
                url: 'pethotel.php'
            },
            {
                keywords: ['service', 'services', 'price', 'prices', 'pet care', 'pet service'],
                label: 'Pet Services',
                url: 'petservices.php'
            },
            {
                keywords: ['contact', 'phone', 'email', 'address', 'support'],
                label: 'Contact Us',
                url: 'contactus.php'
            }
        ];

        function showSearchStatus(message) {
            if (!searchStatus) return;

            searchStatus.innerHTML = message;
            searchStatus.classList.add('show');

            clearTimeout(window.searchStatusTimer);
            window.searchStatusTimer = setTimeout(() => {
                searchStatus.classList.remove('show');
            }, 2600);
        }

        function findSearchRoute(query) {
            const normalized = query.toLowerCase().trim();

            // Exact/partial keyword match first.
            for (const route of searchRoutes) {
                if (route.keywords.some(keyword => normalized.includes(keyword))) {
                    return route;
                }
            }

            return null;
        }

        if (searchForm && searchInput) {
            searchForm.addEventListener('submit', function (event) {
                event.preventDefault();

                const query = searchInput.value.trim();

                if (!query) {
                    searchInput.focus();
                    showSearchStatus('Type a service to search, like <strong>grooming</strong>, <strong>vet</strong>, or <strong>hotel</strong>.');
                    return;
                }

                const route = findSearchRoute(query);

                if (route) {
                    showSearchStatus(`Opening <strong>${route.label}</strong>...`);
                    setTimeout(() => {
                        window.location.href = route.url;
                    }, 180);
                } else {
                    showSearchStatus('No matching service found. Try <strong>grooming</strong>, <strong>vet</strong>, <strong>hotel</strong>, or <strong>services</strong>.');
                }
            });
        }
    </script>

</body>
</html>
