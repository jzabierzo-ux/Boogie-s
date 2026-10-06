<?php
session_start();

$is_logged_in = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
$user_name = $is_logged_in ? ($_SESSION['user_name'] ?? 'Guest') : 'Guest';

require_once 'db_supabase.php';

// --- FETCH REAL REVIEWS ---
$reviews_list = [];

try {
    $review_stmt = $pdo->prepare("\n        SELECT\n            r.*,\n            u.full_name,\n            a.service\n        FROM reviews r\n        JOIN users u ON r.user_id = u.id\n        JOIN appointments a ON r.appointment_id = a.id\n        WHERE r.rating >= 4\n        ORDER BY r.review_date DESC\n        LIMIT 3\n    ");
    $review_stmt->execute();
    $reviews_list = $review_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Reviews query failed: ' . $e->getMessage());
    $reviews_list = [];
}

// --- FETCH AGGREGATE STATS ---
$total_reviews = 0;
$avg_rating = '0.0';

try {
    $stats_stmt = $pdo->query("\n        SELECT\n            COUNT(*) AS total_reviews,\n            AVG(rating) AS avg_rating\n        FROM reviews\n    ");
    $stats_row = $stats_stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $total_reviews = (int)($stats_row['total_reviews'] ?? 0);
    $avg_rating = ($total_reviews > 0)
        ? number_format((float)($stats_row['avg_rating'] ?? 0), 1)
        : '0.0';
} catch (PDOException $e) {
    error_log('Review stats query failed: ' . $e->getMessage());
}

$parent_text = ($total_reviews === 1)
    ? 'happy fur-parent'
    : 'happy fur-parents';

// --- FETCH DYNAMIC PROMOTIONS ---
$promos_list = [];

try {
    $promo_stmt = $pdo->prepare("\n        SELECT *\n        FROM promos\n        WHERE status = 'active'\n          AND (expiry_date >= CURRENT_DATE OR expiry_date IS NULL)\n        ORDER BY id DESC\n        LIMIT 3\n    ");
    $promo_stmt->execute();
    $promos_list = $promo_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Promotions query failed: ' . $e->getMessage());
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

        /* ===== SHARED PETSERVICES HEADER ===== */
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

        .logo {
            display: inline-flex;
            align-items: center;
            gap: 11px;
            text-decoration: none;
            min-width: 0;
        }
        .nav-logo-img {
            height: 50px;
            width: 50px;
            object-fit: contain;
            display: block;
            border-radius: 10px;
        }
        .logo-text { display: flex; flex-direction: column; line-height: 1.05; }
        .logo-text b { font-size: 20px; color: var(--brand-blue); }
        .logo-text span {
            font-size: 9px;
            color: #8c9aae;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            font-weight: 700;
            margin-top: 3px;
        }

        .search {
            display: flex;
            align-items: center;
            background: #f5f8fb;
            border: 1px solid #e0e7ef;
            border-radius: 13px;
            padding: 5px 8px 5px 15px;
        }
        .search input {
            border: none;
            background: transparent;
            width: 100%;
            padding: 9px 6px;
            outline: none;
            font-size: 13px;
            color: var(--text);
        }
        .search input::placeholder { color: #97a4b4; }
        .search button {
            width: 38px;
            height: 38px;
            border: none;
            border-radius: 10px;
            background: var(--brand-blue);
            color: #fff;
            cursor: pointer;
            transition: .25s;
        }
        .search button:hover { background: var(--brand-blue-2); }

        .nav-links {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .nav-links > span {
            color: var(--brand-blue);
            font-weight: 600;
            font-size: 13px;
            white-space: nowrap;
        }
        .cart-btn {
            background: var(--brand-blue);
            color: var(--brand-yellow);
            padding: 10px 18px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 700;
            font-size: 13px;
            transition: .25s;
        }
        .cart-btn:hover {
            box-shadow: 0 8px 20px rgba(0,31,63,.16);
        }
        .nav-links a[style*="ef4444"] {
            color: #dc3b45 !important;
            text-decoration: none !important;
            font-weight: 600 !important;
            font-size: 13px !important;
            margin-left: 0 !important;
        }

        .categories {
            background: var(--brand-yellow);
            border-bottom: 1px solid rgba(0,0,0,.08);
        }
        .categories ul {
            max-width: 980px;
            margin: 0 auto;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            list-style: none;
            padding: 7px 18px;
        }
        .categories ul li a {
            text-decoration: none;
            color: var(--brand-blue);
            font-size: 12px;
            font-weight: 800;
            padding: 10px 18px;
            border-radius: 10px;
            transition: .25s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .categories ul li a:hover,
        .categories ul li a.active {
            background: rgba(0,31,63,.12);
        }
        .categories i { font-size: 14px; }

        @media (max-width: 1000px) {
            .nav-top { grid-template-columns: 1fr; gap: 12px; }
            .nav-links { justify-content: flex-start; }
        }
        @media (max-width: 680px) {
            .categories ul {
                overflow-x: auto;
                justify-content: flex-start;
                padding-left: 12px;
            }
            .categories ul li a {
                white-space: nowrap;
                padding: 10px 13px;
            }
        }


        /* ===== HEADER SEARCH ===== */
        .search-status {
            position: fixed;
            top: 148px;
            left: 50%;
            transform: translateX(-50%) translateY(-8px);
            z-index: 1200;
            min-width: 280px;
            max-width: min(560px, 90vw);
            padding: 10px 14px;
            border-radius: 11px;
            background: var(--brand-blue);
            color: #fff;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
            box-shadow: 0 10px 25px rgba(0,31,63,.16);
            opacity: 0;
            pointer-events: none;
            transition: opacity .2s ease, transform .2s ease;
        }

        .search-status.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        .search-status strong { color: var(--brand-yellow); }

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

        @media (max-width: 680px) {
            .slide { min-height: 500px; }
            .slide-inner { padding-top: 55px; }
            .hero-content h1 { font-size: 32px; }
            .hero-content p { font-size: 14px; }
            .stats-wrap { margin-top: -32px; }
            .stats-bar { grid-template-columns: 1fr 1fr; }
            .home-section { padding-top: 55px; }
            .promo-grid,
            .service-grid,
            .testimonial-grid { grid-template-columns: 1fr; }
            .footer-main { grid-template-columns: 1fr; }
            .cta-box { padding: 34px 22px; }
            .cta-box h2 { font-size: 25px; }
            .cta-footer { gap: 10px; flex-direction: column; }
            .search-status { top: 186px; }
        }


        /* ===== PUBLIC INDEX: MOBILE RESPONSIVE IMPROVEMENTS ===== */
        .mobile-menu-btn {
            display: none;
            width: 44px;
            height: 44px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #fff;
            color: var(--brand-blue);
            font-size: 18px;
            cursor: pointer;
            align-items: center;
            justify-content: center;
        }

        .categories {
            transition: max-height .25s ease, opacity .25s ease;
        }

        @media (max-width: 1000px) {
            .nav-top {
                grid-template-columns: 1fr auto;
                gap: 12px 14px;
                padding: 12px 20px;
            }

            .mobile-menu-btn {
                display: inline-flex;
                justify-self: end;
            }

            .nav-top .search {
                grid-column: 1 / -1;
                grid-row: 2;
                width: 100%;
                margin: 0;
            }

            .nav-links {
                grid-column: 1 / -1;
                grid-row: 3;
                justify-content: center;
            }

            .categories ul {
                max-width: none;
            }
        }

        @media (max-width: 680px) {
            .promo-bar {
                padding: 7px 10px;
                font-size: 10px;
            }

            .nav-top {
                padding: 10px 14px 12px;
            }

            .nav-logo-img {
                width: 43px;
                height: 43px;
            }

            .logo-text b {
                font-size: 17px;
            }

            .logo-text span {
                font-size: 8px;
                letter-spacing: .9px;
            }

            .search {
                border-radius: 12px;
            }

            .search input {
                font-size: 12px;
                padding: 8px 4px;
            }

            .search button {
                width: 36px;
                height: 36px;
            }

            .nav-links {
                gap: 8px;
            }

            .cart-btn {
                width: 100%;
                text-align: center;
                padding: 10px 14px;
            }

            .categories {
                display: none;
                max-height: 0;
                overflow: hidden;
                opacity: 0;
            }

            .categories.mobile-open {
                display: block;
                max-height: 420px;
                opacity: 1;
            }

            .categories ul {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 6px;
                padding: 10px 12px 12px;
            }

            .categories ul li a {
                justify-content: center;
                text-align: center;
                padding: 10px 8px;
                min-height: 42px;
                font-size: 10px;
                gap: 5px;
            }

            .categories ul li:last-child {
                grid-column: 1 / -1;
            }

            .slide {
                min-height: 500px;
            }

            .slide-1,
            .slide-2 {
                background-position: 64% center;
            }

            .slide-inner {
                width: 100%;
                padding: 68px 20px 78px;
            }

            .hero-content {
                max-width: 100%;
            }

            .hero-content h1 {
                font-size: clamp(30px, 9vw, 38px);
                letter-spacing: -.7px;
            }

            .hero-content p {
                font-size: 13px;
                line-height: 1.65;
                max-width: 100%;
            }

            .btn-join {
                width: 100%;
                justify-content: center;
                min-height: 46px;
            }

            .slider-dots {
                bottom: 20px;
            }

            .stats-wrap {
                width: calc(100% - 24px);
                margin-top: -30px;
            }

            .stats-bar {
                grid-template-columns: 1fr 1fr;
                border-radius: 16px;
            }

            .stat-item {
                padding: 18px 10px;
            }

            .stat-item:nth-child(2) {
                border-right: 0;
            }

            .stat-item:nth-child(-n+2) {
                border-bottom: 1px solid var(--border);
            }

            .stat-item i {
                width: 36px;
                height: 36px;
                border-radius: 10px;
                margin-bottom: 8px;
            }

            .stat-item strong {
                font-size: 18px;
            }

            .stat-item span {
                font-size: 10px;
            }

            .home-section {
                padding: 48px 14px 0;
            }

            .section-heading {
                margin-bottom: 24px;
            }

            .section-heading h2 {
                font-size: 25px;
                line-height: 1.2;
            }

            .section-heading p {
                font-size: 12px;
            }

            .promo-grid,
            .service-grid,
            .testimonial-grid {
                grid-template-columns: 1fr;
            }

            .promo-grid {
                gap: 14px;
            }

            .promo-card {
                padding: 0;
                min-height: 0;
            }

            .promo-image {
                height: 190px;
            }

            .promo-body {
                padding: 22px 20px 20px;
            }

            .promo-card h3 {
                font-size: 24px;
            }

            .service-grid {
                gap: 12px;
            }

            .service-card {
                min-height: 150px;
                padding: 30px 20px;
            }

            .testimonials {
                padding: 58px 14px;
            }

            .testimonial-inner {
                width: 100%;
            }

            .t-card {
                padding: 26px 22px;
            }

            .t-text {
                font-size: 13px;
            }

            .cta-area {
                padding: 28px 14px 36px;
            }

            .cta-box {
                padding: 30px 20px;
                border-radius: 18px;
            }

            .cta-box h2 {
                font-size: 24px;
                line-height: 1.25;
            }

            .cta-buttons {
                flex-direction: column;
            }

            .btn-blue-solid,
            .btn-blue-outline {
                width: 100%;
                text-align: center;
                min-height: 44px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }

            .cta-footer {
                gap: 8px;
                flex-direction: column;
            }

            footer {
                padding: 24px 16px 28px;
            }

            .footer-main {
                grid-template-columns: 1fr;
                gap: 26px;
                padding-bottom: 28px;
            }

            .footer-bottom {
                padding-top: 18px;
                line-height: 1.6;
            }

            .search-status {
                top: 150px;
                max-width: calc(100vw - 28px);
                min-width: 0;
                width: calc(100vw - 28px);
            }
        }

        @media (max-width: 380px) {
            .logo-text b { font-size: 16px; }
            .logo-text span { font-size: 7px; }
            .hero-content h1 { font-size: 29px; }
            .stats-wrap { width: calc(100% - 18px); }
            .stat-item strong { font-size: 16px; }
        }

    

/* ============================================================
   BOOGIE'S SHARED HEADER — FINAL MOBILE STANDARD
   Same header sizes/spacing/colours across Home -> Contact.
   ============================================================ */
.logout-link,
.nav-links a[href="logout.php"],
.nav-links a[style*="ef4444"] {
    color: #dc3b45 !important;
    text-decoration: none !important;
}
.logout-link { color:#dc3b45; text-decoration:none; font-weight:600; font-size:13px; padding:9px 4px; }
.logout-link {

                font-size: 11px !important;

                margin-left: 0 !important;

                padding: 9px 4px !important;

            }

@media (max-width: 680px) {
    /* One consistent mobile header: logo + account controls, then ONE search bar. */
    header {
        width: 100% !important;
    }

    .promo-bar {
        min-height: 30px !important;
        padding: 6px 10px !important;
        font-size: 10px !important;
        line-height: 1.35 !important;
        white-space: nowrap;
    }

    .nav-top {
        width: 100% !important;
        display: grid !important;
        grid-template-columns: minmax(0, 1fr) auto !important;
        grid-template-rows: auto auto !important;
        gap: 7px !important;
        padding: 8px 12px !important;
        align-items: center !important;
    }

    .nav-top > .logo {
        grid-column: 1 !important;
        grid-row: 1 !important;
        width: auto !important;
        min-width: 0 !important;
        max-width: 100% !important;
        overflow: hidden !important;
        gap: 8px !important;
    }

    .nav-top > .logo .logo-text {
        min-width: 0 !important;
        overflow: hidden !important;
    }

    .nav-top > .logo .logo-text b,
    .nav-top > .logo .logo-text span {
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
    }

    .nav-logo-img {
        width: 42px !important;
        height: 42px !important;
        flex: 0 0 42px !important;
    }

    .logo-text b {
        font-size: 17px !important;
    }

    .logo-text span {
        font-size: 7px !important;
        letter-spacing: .9px !important;
    }

    /* No hamburger/dropdown replacing the actual yellow services bar. */
    .mobile-menu-btn,
    #mobileMenuBtn,
    .menu-toggle,
    #menuToggle {
        display: none !important;
    }

    .nav-links {
        grid-column: 2 !important;
        grid-row: 1 !important;
        width: auto !important;
        min-width: 0 !important;
        justify-content: flex-end !important;
        align-items: center !important;
        flex-wrap: nowrap !important;
        gap: 6px !important;
        overflow: visible !important;
    }

    /* Hide the long greeting on phones so it can never overlap the logo. */
    .nav-links > span,
    .nav-links .hello-user {
        display: none !important;
    }

    .nav-links .cart-btn,
    .nav-links .login-btn {
        min-height: 43px !important;
        height: 43px !important;
        padding: 9px 12px !important;
        font-size: 10px !important;
        border-radius: 10px !important;
        white-space: nowrap !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        width: auto !important;
    }

     .nav-links .logout-link,
    .nav-links a[href="logout.php"],
    .nav-links a[style*="ef4444"] {
        font-size: 11px !important;
        line-height: 1 !important;
        margin-left: 0 !important;
        padding: 8px 4px !important;
        white-space: nowrap !important;
        color: #dc3b45 !important;
    }

    /* Keep exactly ONE visible search form: the search inside .nav-top. */
    .nav-top > .search {
        display: flex !important;
        grid-column: 1 / -1 !important;
        grid-row: 2 !important;
        width: 100% !important;
        min-width: 0 !important;
        min-height: 44px !important;
        height: 44px !important;
        margin: 0 !important;
        padding: 4px 6px 4px 11px !important;
        border-radius: 11px !important;
        box-sizing: border-box !important;
    }

    .nav-top > .search input {
        display: block !important;
        min-width: 0 !important;
        width: 100% !important;
        padding: 8px 5px !important;
        font-size: 11px !important;
        line-height: 1.2 !important;
    }

    .nav-top > .search button {
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        width: 34px !important;
        height: 34px !important;
        min-width: 34px !important;
        flex: 0 0 34px !important;
        border-radius: 9px !important;
    }

    /* Older Pet Services version has an extra mobile search wrapper — hide it. */
    .mobile-search-wrap,
    #mobileSiteSearchForm,
    #mobileSiteSearchInput {
        display: none !important;
    }

    /* Always show the complete yellow service navigation. */
    .categories,
    .categories.mobile-open {
        display: block !important;
        visibility: visible !important;
        opacity: 1 !important;
        max-height: none !important;
        height: auto !important;
        overflow: hidden !important;
    }

    .categories ul {
        width: 100% !important;
        max-width: none !important;
        display: flex !important;
        flex-wrap: nowrap !important;
        justify-content: flex-start !important;
        align-items: center !important;
        gap: 4px !important;
        list-style: none !important;
        padding: 6px 10px !important;
        margin: 0 !important;
        overflow-x: auto !important;
        overflow-y: hidden !important;
        -webkit-overflow-scrolling: touch !important;
        scrollbar-width: none !important;
    }

    .categories ul::-webkit-scrollbar {
        display: none !important;
    }

    .categories ul li,
    .categories ul li:last-child {
        flex: 0 0 auto !important;
        grid-column: auto !important;
    }

    .categories ul li a {
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        white-space: nowrap !important;
        padding: 9px 11px !important;
        min-height: 36px !important;
        font-size: 9px !important;
        gap: 6px !important;
        border-radius: 9px !important;
    }

    .categories i {
        font-size: 11px !important;
    }
}

@media (max-width: 420px) {
    .nav-top {
        padding-left: 9px !important;
        padding-right: 9px !important;
    }

    .nav-logo-img {
        width: 38px !important;
        height: 38px !important;
        flex-basis: 38px !important;
    }

    .logo-text b {
        font-size: 15px !important;
    }

    .logo-text span {
        font-size: 6px !important;
        letter-spacing: .7px !important;
    }

    .nav-links .cart-btn,
    .nav-links .login-btn {
        min-height: 42px !important;
        height: 42px !important;
        padding: 9px 10px !important;
        font-size: 9px !important;
    }

    .nav-links .logout-link,
    .nav-links a[href="logout.php"],
    .nav-links a[style*="ef4444"] {
        font-size: 11px !important;
        padding: 8px 4px !important;
        
    }

    .nav-top > .search {
        min-height: 43px !important;
        height: 43px !important;
    }

    .nav-top > .search button {
        width: 34px !important;
        height: 34px !important;
        flex-basis: 34px !important;
    }
}

</style>
</head>
<body>

    <div class="promo-bar"><i class="fa-solid fa-phone"></i> Need help? Call us at (046) 887 4714</div>

    <header>
        <div class="nav-top">
            <a href="index.php" class="logo">
                <img src="bg.png" alt="Boogie's Pet Care logo" class="nav-logo-img">
                <div class="logo-text">
                    <b>Boogie's</b>
                    <span>PET CARE SERVICES</span>
                </div>
            </a>

            <button type="button" class="mobile-menu-btn" id="mobileMenuBtn"
                    aria-label="Open navigation menu" aria-expanded="false" aria-controls="publicCategories">
                <i class="fa-solid fa-bars"></i>
            </button>

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
                <?php if ($is_logged_in): ?>
                    <span class="hello-user"><i class="fa-regular fa-user"></i> Hi, <?php echo htmlspecialchars($user_name); ?></span>
                    <a href="dashboard.php" class="cart-btn">Dashboard</a>
                    <a href="logout.php" style="color: #ef4444; text-decoration:none; font-weight: 600; font-size: 14px; margin-left:10px;">Logout</a>
                <?php else: ?>
                    <a href="login.php" class="cart-btn">Login / Register</a>
                <?php endif; ?>
            </div>
        </div>

        <nav class="categories" id="publicCategories">
            <ul>
                <li><a href="home.php" class="active"><i class="fa-solid fa-house"></i> HOME</a></li>
                <li><a href="petservices.php"><i class="fa-solid fa-paw"></i> PET SERVICES</a></li>
                <li><a href="grooming.php"><i class="fa-solid fa-scissors"></i> GROOMING</a></li>
                <li><a href="vetclinic.php"><i class="fa-solid fa-stethoscope"></i> VET CLINIC</a></li>
                <li><a href="pethotel.php"><i class="fa-solid fa-hotel"></i> PET HOTEL</a></li>
                <li><a href="contactus.php"><i class="fa-solid fa-phone"></i> CONTACT</a></li>
            </ul>
        </nav>
    </header>

    <div id="searchStatus" class="search-status" role="status" aria-live="polite"></div>

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
                        <?php foreach($reviews_list as $row): ?>
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
                                        for($i = 1; $i <= 5; $i++) {
                                            echo $i <= $row['rating']
                                                ? '<i class="fa-solid fa-star"></i>'
                                                : '<i class="fa-regular fa-star"></i>';
                                        }
                                    ?>
                                </div>
                                <p class="t-text">"<?php echo htmlspecialchars($row['comment']); ?>"</p>
                                <div class="t-user">
                                    <div class="t-avatar"><?php echo htmlspecialchars($initial); ?></div>
                                    <div>
                                        <strong><?php echo htmlspecialchars($review_name); ?></strong>
                                        <small><?php echo htmlspecialchars($row['service']); ?> Client</small>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-reviews">
                            <i class="fa-regular fa-comment-dots"></i>
                            <h3>No reviews yet</h3>
                            <p>Customer reviews will appear here automatically once submitted.</p>
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
                <a href="register.php" class="btn-blue-solid">Create Free Account</a>
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
                <a href="index.php">Home</a>
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

        /* ===== MOBILE MENU ===== */
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const publicCategories = document.getElementById('publicCategories');

        if (mobileMenuBtn && publicCategories) {
            mobileMenuBtn.addEventListener('click', function () {
                const isOpen = publicCategories.classList.toggle('mobile-open');
                mobileMenuBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                mobileMenuBtn.setAttribute(
                    'aria-label',
                    isOpen ? 'Close navigation menu' : 'Open navigation menu'
                );
                mobileMenuBtn.innerHTML = isOpen
                    ? '<i class="fa-solid fa-xmark"></i>'
                    : '<i class="fa-solid fa-bars"></i>';
            });
        }

        /* ===== SMART / RELATED SITE SEARCH ===== */
        const searchForm = document.getElementById('siteSearchForm');
        const searchInput = document.getElementById('siteSearchInput');
        const searchStatus = document.getElementById('searchStatus');

        // Related words are mapped to the page/category where the user should be sent.
        // This is intentionally broader than exact category names.
        const searchRoutes = [
            {
                keywords: [
                    'grooming', 'groom', 'groomed', 'bath', 'bathing', 'shower',
                    'haircut', 'hair cut', 'trim', 'trimming', 'nail', 'nails',
                    'nail trim', 'shampoo', 'fur', 'fur care', 'coat', 'brush',
                    'brushing', 'styling', 'style', 'puppy cut', 'summer cut',
                    'shave', 'shave down', 'bear cut', 'poodle cut', 'bath blow dry',
                    'ligo', 'gupit', 'paligo', 'paliguan', 'kuko', 'balbas'
                ],
                label: 'Grooming Services',
                url: 'grooming.php'
            },
            {
                keywords: [
                    'vet', 'veterinary', 'veterinarian', 'doctor', 'clinic', 'checkup',
                    'check-up', 'check up', 'consultation', 'consult', 'health',
                    'wellness', 'medical', 'medicine', 'treatment', 'vaccination',
                    'vaccine', 'vaccines', 'rabies', 'anti rabies', 'deworming',
                    'deworm', 'worm', 'worms', 'shot', 'shots', 'injection',
                    'bakuna', 'gamot', 'doktor', 'sakit', 'kalusugan', 'check'
                ],
                label: 'Vet Clinic',
                url: 'vetclinic.php'
            },
            {
                keywords: [
                    'hotel', 'pet hotel', 'boarding', 'board', 'daycare', 'day care',
                    'day-care', 'overnight', 'overnight stay', 'stay', 'sleep',
                    'lodge', 'lodging', 'accommodation', 'room', 'pet stay',
                    'temporary care', 'tulog', 'matulog', 'pahinga', 'tirahan'
                ],
                label: 'Pet Hotel',
                url: 'pethotel.php'
            },
            {
                keywords: [
                    'service', 'services', 'pet service', 'pet services', 'price',
                    'prices', 'pricing', 'cost', 'costs', 'rate', 'rates', 'fee',
                    'fees', 'package', 'packages', 'menu', 'list', 'pricelist',
                    'price list', 'pet care', 'care', 'alaga', 'alagaan', 'presyo'
                ],
                label: 'Pet Services',
                url: 'petservices.php'
            },
            {
                keywords: [
                    'contact', 'contacts', 'phone', 'telephone', 'mobile', 'email',
                    'gmail', 'address', 'location', 'where', 'directions', 'support',
                    'help', 'message', 'reach', 'call', 'tawag', 'lokasyon', 'address'
                ],
                label: 'Contact Us',
                url: 'contactus.php'
            }
        ];

        function normalizeSearchText(value) {
            return String(value || '')
                .toLowerCase()
                .replace(/[^a-z0-9+\s-]/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();
        }

        // Small typo tolerance so searches like "groming" can still work.
        function levenshteinDistance(a, b) {
            a = String(a);
            b = String(b);
            const prev = Array.from({ length: b.length + 1 }, (_, i) => i);

            for (let i = 1; i <= a.length; i++) {
                const current = [i];
                for (let j = 1; j <= b.length; j++) {
                    const insertCost = current[j - 1] + 1;
                    const deleteCost = prev[j] + 1;
                    const replaceCost = prev[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1);
                    current[j] = Math.min(insertCost, deleteCost, replaceCost);
                }
                for (let j = 0; j < current.length; j++) prev[j] = current[j];
            }

            return prev[b.length];
        }

        function findSearchRoute(query) {
            const normalized = normalizeSearchText(query);
            const queryWords = normalized.split(' ').filter(Boolean);
            let bestRoute = null;
            let bestScore = 0;

            for (const route of searchRoutes) {
                let score = 0;

                for (const keyword of route.keywords) {
                    const normalizedKeyword = normalizeSearchText(keyword);
                    if (!normalizedKeyword) continue;

                    // Strong match for a full phrase anywhere in the query.
                    if (normalized.includes(normalizedKeyword)) {
                        score += normalizedKeyword.includes(' ') ? 6 : 4;
                        continue;
                    }

                    // Word-level partial/fuzzy matching.
                    const keywordWords = normalizedKeyword.split(' ');
                    for (const qWord of queryWords) {
                        for (const kWord of keywordWords) {
                            if (qWord.length >= 3 && kWord.startsWith(qWord)) {
                                score += 2;
                            } else if (kWord.length >= 3 && qWord.startsWith(kWord)) {
                                score += 2;
                            } else if (
                                qWord.length >= 5 &&
                                kWord.length >= 5 &&
                                levenshteinDistance(qWord, kWord) <= 2
                            ) {
                                score += 2;
                            }
                        }
                    }
                }

                if (score > bestScore) {
                    bestScore = score;
                    bestRoute = route;
                }
            }

            return bestScore > 0 ? bestRoute : null;
        }

        function showSearchStatus(message) {
            if (!searchStatus) return;

            searchStatus.innerHTML = message;
            searchStatus.classList.add('show');

            clearTimeout(window.searchStatusTimer);
            window.searchStatusTimer = setTimeout(() => {
                searchStatus.classList.remove('show');
            }, 2600);
        }

        if (searchForm && searchInput) {
            searchForm.addEventListener('submit', function (event) {
                event.preventDefault();

                const query = searchInput.value.trim();

                if (!query) {
                    searchInput.focus();
                    showSearchStatus('Type something to search, like <strong>bath</strong>, <strong>vaccine</strong>, <strong>boarding</strong>, or <strong>prices</strong>.');
                    return;
                }

                const route = findSearchRoute(query);

                if (route) {
                    showSearchStatus(`Opening <strong>${route.label}</strong>...`);
                    setTimeout(() => {
                        window.location.href = route.url;
                    }, 180);
                } else {
                    showSearchStatus('No related service found. Try searching a service, treatment, price, grooming need, stay, or contact detail.');
                }
            });
        }
    </script>

</body>
</html>
