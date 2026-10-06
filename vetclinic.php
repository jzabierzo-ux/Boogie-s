<?php

session_start();



// Include Supabase/PostgreSQL database connection

require_once 'db_supabase.php';



// Check if user is logged in

$is_logged_in = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;

$user_name = $is_logged_in ? $_SESSION['user_name'] : "Guest";



// Fetch ONLY vet services from your services_pricelist table safely

$result = [];

try {

    // PostgreSQL/Supabase boolean column

    $stmt = $pdo->query("

        SELECT *

        FROM services_pricelist

        WHERE category IN (

            'Deworming',

            'Vaccination',

            'Consultation',

            'Check-up',

            'General Check-up',

            'Vet Services'

        )

        AND is_available = 1

        ORDER BY category ASC, price ASC

    ");

    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    error_log("Vet clinic service query failed: " . $e->getMessage());

    $result = [];

}

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Vet Clinic | Boogie's Pet Care & Services</title>

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>

        :root {

            /* Dasmariñas Branch Brand Colors */

            --brand-yellow: #ffcc00;&#x20;

            --brand-blue: #001f3f;&#x20;

            --brand-blue-light: #002d5b;
            --brand-blue-2: #002d5b;

            --dark-bg: var(--brand-blue);

            --light-text: #ffffff;

            --text-on-yellow: #1e293b;

            --text: #17324d;

            --muted: #6b7c8f;

            --border: #e4eaf1;

        }



        * { margin: 0; padding: 0; box-sizing: border-box; }

        body { font-family: 'Poppins', sans-serif; color: #1e293b; background: #fff; line-height: 1.6; display: flex; flex-direction: column; min-height: 100vh; overflow-x: hidden;}



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





        /* ===== VET PAGE ===== */

        main { min-height: 70vh; }



        .hero {

            position: relative;

            overflow: hidden;

            background:

                radial-gradient(circle at 90% 18%, rgba(30, 154, 214, .15), transparent 28%),

                linear-gradient(135deg, #eef8ff 0%, #fff 72%, #fff9e7 100%);

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

            width: 340px;

            height: 340px;

            right: -100px;

            top: -130px;

            background: rgba(24, 144, 204, .10);

        }



        .hero::after {

            width: 240px;

            height: 240px;

            left: -90px;

            bottom: -120px;

            background: rgba(255, 204, 0, .13);

        }



        .hero-inner {

            max-width: 1180px;

            margin: 0 auto;

            padding: 62px 28px 52px;

            display: grid;

            grid-template-columns: 1.35fr .65fr;

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



        .eyebrow i { color: #1598cf; }



        .hero h1 {

            max-width: 760px;

            font-size: 44px;

            line-height: 1.12;

            color: var(--brand-blue);

            margin: 18px 0 14px;

            font-weight: 800;

            letter-spacing: -1px;

        }



        .hero p {

            max-width: 700px;

            color: var(--muted);

            font-size: 16px;

        }



        .hero-note {

            margin-top: 21px;

            display: flex;

            gap: 18px;

            flex-wrap: wrap;

            font-size: 12px;

            color: #708197;

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

            border-radius: 50%;

            justify-self: end;

            display: flex;

            align-items: center;

            justify-content: center;

            background: linear-gradient(145deg, #0b3b66, #1188bd);

            box-shadow: 0 18px 40px rgba(0,31,63,.18);

        }



        .hero-art i {

            color: #fff;

            font-size: 80px;

        }



        .services-wrap {

            max-width: 1180px;

            margin: 0 auto;

            padding: 54px 28px 90px;

        }



        .section-heading {

            display: flex;

            justify-content: space-between;

            align-items: end;

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

            font-weight: 800;

            line-height: 1.2;

        }



        .section-heading p {

            color: var(--muted);

            font-size: 13px;

            max-width: 500px;

            text-align: right;

        }



        .services-grid {

            display: grid;

            grid-template-columns: repeat(3, 1fr);

            gap: 24px;

        }



        .service-card {

            background: #fff;

            border: 1px solid var(--border);

            border-radius: 20px;

            overflow: hidden;

            display: flex;

            flex-direction: column;

            min-height: 410px;

            box-shadow: 0 8px 28px rgba(0,31,63,.05);

            transition: transform .28s ease, box-shadow .28s ease, border-color .28s ease;

        }



        .service-card:hover {

            transform: translateY(-7px);

            box-shadow: 0 18px 40px rgba(0,31,63,.10);

            border-color: #c8dbe8;

        }



        .service-visual {

            height: 155px;

            display: flex;

            align-items: center;

            justify-content: center;

            position: relative;

            overflow: hidden;

        }



        .service-visual::after {

            content: '';

            position: absolute;

            width: 160px;

            height: 160px;

            border-radius: 50%;

            right: -55px;

            bottom: -80px;

            background: rgba(255,255,255,.35);

        }



        .service-visual.consultation { background: linear-gradient(145deg, #e7f7ff, #d6efff); }

        .service-visual.deworming { background: linear-gradient(145deg, #f1eaff, #e4d7ff); }

        .service-visual.vaccination { background: linear-gradient(145deg, #eafbf1, #d7f2e3); }

        .service-visual.default { background: linear-gradient(145deg, #fff7d9, #ffedb4); }



        .visual-icon {

            width: 86px;

            height: 86px;

            border-radius: 50%;

            background: rgba(255,255,255,.84);

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 37px;

            box-shadow: 0 10px 24px rgba(0,31,63,.08);

            position: relative;

            z-index: 1;

        }



        .consultation .visual-icon { color: #0d82ba; }

        .deworming .visual-icon { color: #7850b8; }

        .vaccination .visual-icon { color: #188957; }

        .default .visual-icon { color: #a06c00; }



        .service-body {

            padding: 24px 24px 22px;

            display: flex;

            flex-direction: column;

            flex: 1;

        }



        .service-tag {

            display: inline-flex;

            align-self: flex-start;

            padding: 6px 10px;

            border-radius: 999px;

            font-size: 10px;

            text-transform: uppercase;

            font-weight: 800;

            letter-spacing: .6px;

            margin-bottom: 11px;

        }



        .tag-consultation { background: #e5f6ff; color: #0d82ba; }

        .tag-deworming { background: #f0e8ff; color: #7850b8; }

        .tag-vaccination { background: #e3f8ec; color: #188957; }

        .tag-default { background: #fff1c9; color: #936300; }



        .service-body h3 {

            color: var(--brand-blue);

            font-size: 20px;

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

            margin-bottom: 20px;

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

            font-weight: 700;

            letter-spacing: .4px;

        }



        .price {

            display: block;

            color: var(--brand-blue);

            font-size: 22px;

            font-weight: 800;

            margin-top: 1px;

        }



        .btn-book {

            display: inline-flex;

            align-items: center;

            justify-content: center;

            gap: 8px;

            min-width: 110px;

            padding: 11px 15px;

            border-radius: 11px;

            text-decoration: none;

            background: var(--brand-blue);

            color: var(--brand-yellow);

            font-size: 12px;

            font-weight: 800;

            transition: .25s;

        }



        .btn-book:hover {

            background: #0b3b66;

            transform: translateY(-1px);

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

        }



        .care-strip {

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



        .care-item {

            display: flex;

            align-items: center;

            gap: 12px;

        }



        .care-item i {

            width: 40px;

            height: 40px;

            border-radius: 12px;

            display: flex;

            align-items: center;

            justify-content: center;

            background: rgba(255,204,0,.13);

            color: var(--brand-yellow);

        }



        .care-item strong {

            display: block;

            font-size: 12px;

        }



        .care-item span {

            display: block;

            font-size: 10px;

            color: #c8d3df;

            margin-top: 2px;

        }






        /* ===== SEARCH STATUS ===== */
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

        .search-status strong {
            color: var(--brand-yellow);
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

            .services-grid { grid-template-columns: 1fr 1fr; }

            .care-strip { grid-template-columns: 1fr; }

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

            .services-grid { grid-template-columns: 1fr; }

            .card-footer { align-items: stretch; }

            .btn-book { min-width: 105px; }

            .footer-main { grid-template-columns: 1fr; }

        }





        /* ===== EXTRA MOBILE RESPONSIVE TUNING ===== */

        @media (max-width: 980px) {

            .nav-top {

                grid-template-columns: 1fr;

                gap: 12px;

                padding-left: 20px;

                padding-right: 20px;

            }



            .search {

                width: 100%;

                order: 2;

            }



            .nav-links {

                width: 100%;

                justify-content: flex-start;

                order: 3;

            }



            .categories ul {

                justify-content: flex-start;

                overflow-x: auto;

                scrollbar-width: thin;

            }



            .hero-inner {

                grid-template-columns: 1fr;

                padding-top: 50px;

            }



            .hero-art {

                justify-self: start;

                width: 165px;

                height: 165px;

            }



            .hero-art i {

                font-size: 58px;

            }



            .services-grid {

                grid-template-columns: 1fr 1fr;

                gap: 16px;

            }



            .care-strip {

                grid-template-columns: 1fr;

            }



            .footer-main {

                grid-template-columns: 1fr 1fr;

            }

        }



        @media (max-width: 680px) {

            .promo-bar {

                padding: 6px 10px;

                font-size: 10px;

                line-height: 1.4;

            }



            .nav-top {

                width: 100%;

                padding: 9px 12px;

                gap: 8px;

            }



            .logo {

                width: 100%;

                gap: 8px;

            }



            .nav-logo-img {

                width: 42px;

                height: 42px;

            }



            .logo-text b {

                font-size: 17px;

            }



            .logo-text span {

                font-size: 7px;

                letter-spacing: .9px;

            }



            .search {

                padding: 4px 6px 4px 11px;

                border-radius: 11px;

            }



            .search input {

                min-width: 0;

                padding: 8px 5px;

                font-size: 11px;

            }



            .search button {

                width: 36px;

                height: 36px;

                flex: 0 0 36px;

            }



            .nav-links {

                gap: 7px;

                flex-wrap: wrap;

            }



            .nav-links .cart-btn,

            .nav-links .login-btn {

                min-height: 42px;

                padding: 10px 13px;

                font-size: 10px;

                display: inline-flex;

                align-items: center;

                justify-content: center;

            }



            .nav-links .hello-user {

                width: 100%;

                font-size: 10px;

                line-height: 1.4;

            }



            .logout-link {

                font-size: 11px !important;

                margin-left: 0 !important;

                padding: 9px 4px !important;

            }



            .categories {

                overflow: hidden;

            }



            .categories ul {

                width: 100%;

                justify-content: flex-start;

                gap: 4px;

                padding: 6px 10px;

                overflow-x: auto;

                -webkit-overflow-scrolling: touch;

                scrollbar-width: none;

            }



            .categories ul::-webkit-scrollbar {

                display: none;

            }



            .categories ul li {

                flex: 0 0 auto;

            }



            .categories ul li a {

                padding: 9px 11px;

                font-size: 9px;

                gap: 6px;

                white-space: nowrap;

            }



            .categories i {

                font-size: 11px;

            }



            .hero-inner {

                padding: 38px 17px 34px;

                gap: 22px;

            }



            .eyebrow {

                font-size: 9px;

                padding: 6px 10px;

            }



            .hero h1 {

                font-size: 29px;

                line-height: 1.18;

                letter-spacing: -.5px;

                margin: 14px 0 11px;

            }



            .hero p {

                font-size: 12px;

                line-height: 1.65;

            }



            .hero-note {

                display: grid;

                grid-template-columns: 1fr;

                gap: 7px;

                margin-top: 16px;

                font-size: 9px;

            }



            .hero-art {

                width: 125px;

                height: 125px;

                justify-self: center;

            }



            .hero-art i {

                font-size: 43px;

            }



            .services-wrap {

                padding: 37px 15px 55px;

            }



            .section-heading {

                display: block;

                margin-bottom: 18px;

            }



            .section-kicker {

                font-size: 9px;

                margin-bottom: 5px;

            }



            .section-heading h2 {

                font-size: 22px;

                line-height: 1.25;

            }



            .section-heading p {

                text-align: left;

                font-size: 10px;

                line-height: 1.55;

                margin-top: 7px;

            }



            .services-grid {

                grid-template-columns: 1fr;

                gap: 14px;

            }



            .service-card {

                min-height: auto;

                border-radius: 17px;

            }



            .service-visual {

                height: 135px;

            }



            .visual-icon {

                width: 70px;

                height: 70px;

                font-size: 29px;

            }



            .service-body {

                padding: 18px 16px 17px;

            }



            .service-tag {

                font-size: 8px;

                padding: 5px 9px;

                margin-bottom: 8px;

            }



            .service-body h3 {

                font-size: 18px;

                line-height: 1.3;

            }



            .service-name {

                font-size: 10px;

                margin-bottom: 10px;

            }



            .service-description {

                font-size: 10px;

                line-height: 1.65;

                margin-bottom: 14px;

            }



            .card-footer {

                align-items: stretch;

                gap: 9px;

                padding-top: 13px;

            }



            .price-label {

                font-size: 8px;

            }



            .price {

                font-size: 19px;

            }



            .btn-book {

                min-width: 0;

                width: auto;

                min-height: 42px;

                padding: 10px 12px;

                font-size: 10px;

                white-space: nowrap;

            }



            .care-strip {

                margin-top: 17px;

                border-radius: 16px;

                padding: 15px;

                gap: 12px;

            }



            .care-item {

                gap: 9px;

                align-items: flex-start;

            }



            .care-item i {

                width: 34px;

                height: 34px;

                flex: 0 0 34px;

                border-radius: 10px;

                font-size: 12px;

            }



            .care-item strong {

                font-size: 10px;

            }



            .care-item span {

                font-size: 8px;

                line-height: 1.5;

            }



            .no-data {

                padding: 42px 16px;

                border-radius: 17px;

                font-size: 10px;

                line-height: 1.6;

            }



            .no-data i {

                font-size: 34px !important;

            }



            footer {

                padding: 42px 15px 24px;

            }



            .footer-main {

                grid-template-columns: 1fr;

                gap: 22px;

                padding-bottom: 28px;

            }



            .footer-main h4 {

                font-size: 10px;

                margin-bottom: 11px;

            }



            .footer-main p,

            .footer-main a {

                font-size: 10px;

                line-height: 1.65;

            }



            .socials {

                margin-top: 13px;

            }



            .socials a {

                width: 34px;

                height: 34px;

            }



            .footer-bottom {

                font-size: 8.5px;

                line-height: 1.5;

                padding-top: 18px;

            }

        }



        @media (max-width: 420px) {

            .nav-top {

                padding-left: 9px;

                padding-right: 9px;

            }



            .nav-logo-img {

                width: 38px;

                height: 38px;

            }



            .logo-text b {

                font-size: 15px;

            }



            .logo-text span {

                font-size: 6px;

                letter-spacing: .7px;

            }



            .categories ul li a {

                font-size: 8px;

                padding: 8px 10px;

            }



            .hero h1 {

                font-size: 26px;

            }



            .hero p {

                font-size: 11px;

            }



            .section-heading h2 {

                font-size: 20px;

            }



            .service-body h3 {

                font-size: 17px;

            }



            .service-description {

                font-size: 9.5px;

            }



            .card-footer {

                flex-direction: column;

            }



            .btn-book {

                width: 100%;

            }

        }




        /* ===== FINAL MOBILE HEADER ===== */
        @media (max-width: 680px) {
            .nav-top {
                width: 100%;
                display: grid;
                grid-template-columns: minmax(0, 1fr) auto;
                grid-template-rows: auto auto;
                gap: 8px;
                padding: 9px 12px;
            }

            .logo {
                grid-column: 1;
                grid-row: 1;
                width: 100%;
                min-width: 0;
            }

            .nav-links {
                grid-column: 2;
                grid-row: 1;
                width: auto;
                justify-content: flex-end;
                align-items: center;
                gap: 6px !important;
                flex-wrap: nowrap !important;
                min-width: 0;
            }

            /* Match Pet Services Login / Register button sizing */
            .nav-links .cart-btn,
            .nav-links .login-btn {
                min-height: 45px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 10px 13px !important;
                font-size: 10.5px !important;
                border-radius: 10px;
                white-space: nowrap;
            }

            .nav-top > .search {
                grid-column: 1 / -1;
                grid-row: 2;
                width: 100%;
                order: 0;
                min-width: 0;
                min-height: 46px;
                padding: 4px 6px 4px 11px;
                border-radius: 11px;
            }

            .nav-top > .search input {
                min-width: 0;
                font-size: 11px;
                padding: 8px 5px;
            }

            .nav-top > .search button {
                width: 36px;
                height: 36px;
                flex: 0 0 36px;
            }

            .categories {
                overflow: hidden;
            }

            .categories ul {
                width: 100%;
                justify-content: flex-start;
                gap: 4px;
                padding: 6px 10px;
                overflow-x: auto;
                overflow-y: hidden;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
            }

            .categories ul::-webkit-scrollbar {
                display: none;
            }

            .categories ul li {
                flex: 0 0 auto;
            }

            .categories ul li a {
                padding: 9px 11px;
                font-size: 9px;
                gap: 6px;
                white-space: nowrap;
            }

            .search-status {
                top: 150px;
                left: 12px;
                right: 12px;
                width: auto;
                min-width: 0;
                max-width: none;
                transform: translateY(-8px);
            }

            .search-status.show {
                transform: translateY(0);
            }
        }

        @media (max-width: 420px) {
            .nav-links .cart-btn,
            .nav-links .login-btn {
                padding: 9px 10px !important;
                font-size: 9px !important;
            }
        }

    

        /* ===== FINAL MOBILE HEADER FIX ===== */
        @media (max-width: 680px) {
            .nav-top {
                width: 100%;
                display: grid !important;
                grid-template-columns: minmax(0, 1fr) auto !important;
                grid-template-rows: auto auto !important;
                gap: 7px !important;
                padding: 8px 12px !important;
            }

            .nav-top > .logo {
                grid-column: 1 !important;
                grid-row: 1 !important;
                width: auto !important;
                min-width: 0 !important;
                max-width: 100%;
                overflow: hidden;
            }

            .nav-top > .logo .logo-text {
                min-width: 0;
                overflow: hidden;
            }

            .nav-top > .logo .logo-text b,
            .nav-top > .logo .logo-text span {
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .nav-links {
                grid-column: 2 !important;
                grid-row: 1 !important;
                width: auto !important;
                min-width: 0 !important;
                justify-content: flex-end !important;
                align-items: center !important;
                gap: 6px !important;
                flex-wrap: nowrap !important;
            }

            /* Do not show the long greeting on phones. */
            .nav-links > span,
            .nav-links .hello-user {
                display: none !important;
            }

            .nav-links .cart-btn,
            .nav-links .login-btn {
                min-height: 43px !important;
                padding: 9px 12px !important;
                font-size: 10px !important;
                border-radius: 10px !important;
                white-space: nowrap;
                display: inline-flex !important;
                align-items: center;
                justify-content: center;
            }

            /* There is only ONE website search bar: siteSearchForm. */
            .nav-top > .search {
                display: flex !important;
                grid-column: 1 / -1 !important;
                grid-row: 2 !important;
                width: 100% !important;
                min-width: 0 !important;
                min-height: 44px !important;
                margin: 0 !important;
                padding: 4px 6px 4px 11px !important;
                border-radius: 11px !important;
            }

            .nav-top > .search input {
                min-width: 0 !important;
                width: 100%;
                font-size: 11px !important;
                padding: 8px 5px !important;
            }

            .nav-top > .search button {
                width: 36px !important;
                height: 36px !important;
                flex: 0 0 36px !important;
            }

            /* Hide any accidental duplicate search wrapper from older edits. */
            .mobile-search-wrap,
            .mobileSiteSearchForm,
            #mobileSiteSearchForm {
                display: none !important;
            }

            .categories {
                overflow: hidden;
            }

            .categories ul {
                width: 100%;
                max-width: none;
                justify-content: flex-start !important;
                gap: 4px;
                padding: 6px 10px;
                overflow-x: auto;
                overflow-y: hidden;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
            }

            .categories ul::-webkit-scrollbar { display: none; }
            .categories ul li { flex: 0 0 auto; }
            .categories ul li a {
                padding: 9px 11px;
                font-size: 9px;
                gap: 6px;
                white-space: nowrap;
            }
        }

        @media (max-width: 420px) {
            .nav-top { padding-left: 9px !important; padding-right: 9px !important; }
            .nav-links .cart-btn,
            .nav-links .login-btn {
                padding: 9px 10px !important;
                font-size: 9px !important;
            }
            .nav-logo-img { width: 38px !important; height: 38px !important; }
            .logo-text b { font-size: 15px !important; }
            .logo-text span { font-size: 6px !important; letter-spacing: .7px !important; }
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
        font-size: 10px !important;
        padding: 8px 3px !important;
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



    <div id="searchStatus" class="search-status" role="status" aria-live="polite"></div>

    <header>

        <div class="nav-top">

            <a href="index.php" class="logo">

                <img src="bg.png" alt="Logo" class="nav-logo-img">

                <div class="logo-text">

                    <b>Boogie's</b>

                    <span>PET CARE SERVICES</span>

                </div>

            </a>



            <form class="search" id="siteSearchForm" autocomplete="off" role="search">
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

                <li><a href="index.php"><i class="fa-solid fa-house"></i> HOME</a></li>

                <li><a href="petservices.php"><i class="fa-solid fa-paw"></i> PET SERVICES</a></li>

                <li><a href="grooming.php"><i class="fa-solid fa-scissors"></i> GROOMING</a></li>

                <li><a href="vetclinic.php" class="active"><i class="fa-solid fa-stethoscope"></i> VET CLINIC</a></li>

                <li><a href="pethotel.php"><i class="fa-solid fa-hotel"></i> PET HOTEL</a></li>

                <li><a href="contactus.php"><i class="fa-solid fa-phone"></i> CONTACT</a></li>

            </ul>

        </nav>

    </header>







    <main>

        <section class="hero">

            <div class="hero-inner">

                <div>

                    <div class="eyebrow">

                        <i class="fa-solid fa-stethoscope"></i>

                        Boogie's Vet Clinic

                    </div>

                    <h1>Helping your pet stay healthy and happy.</h1>

                    <p>

                        Explore our veterinary services, review the current rates,

                        and book the care your pet needs.

                    </p>



                    <div class="hero-note">

                        <span><i class="fa-solid fa-circle-check"></i> Professional veterinary care</span>

                        <span><i class="fa-solid fa-shield-heart"></i> Preventive health services</span>

                        <span><i class="fa-solid fa-location-dot"></i> Dasmariñas, Cavite</span>

                    </div>

                </div>



                <div class="hero-art" aria-hidden="true">

                    <i class="fa-solid fa-stethoscope"></i>

                </div>

            </div>

        </section>



        <section class="services-wrap">

            <div class="section-heading">

                <div>

                    <div class="section-kicker">Vet clinic services</div>

                    <h2>Care for every stage of your pet's health</h2>

                </div>

                <p>

                    Choose a service below to check its current price and continue

                    directly to appointment booking.

                </p>

            </div>



            <div class="services-grid">



            <?php if (!empty($result)): ?>

                <?php foreach ($result as $row): ?>

                    <?php

                        $category = $row['category'];

                        $category_lower = strtolower($category);



                        $card_class = 'default';

                        $tag_class = 'tag-default';

                        $icon = 'fa-stethoscope';

                        $tag_text = 'Veterinary care';



                        if (stripos($category, 'consultation') !== false || stripos($category, 'check') !== false) {

                            $card_class = 'consultation';

                            $tag_class = 'tag-consultation';

                            $icon = 'fa-stethoscope';

                            $tag_text = 'Consultation';

                        } elseif (stripos($category, 'deworming') !== false) {

                            $card_class = 'deworming';

                            $tag_class = 'tag-deworming';

                            $icon = 'fa-pills';

                            $tag_text = 'Preventive care';

                        } elseif (stripos($category, 'vaccination') !== false) {

                            $card_class = 'vaccination';

                            $tag_class = 'tag-vaccination';

                            $icon = 'fa-syringe';

                            $tag_text = 'Vaccination';

                        }

                    ?>

                    <article class="service-card">

                        <div class="service-visual <?php echo $card_class; ?>">

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

                                Professional veterinary care designed to help keep your pet healthy,

                                protected, and comfortable.

                            </p>



                            <div class="card-footer">

                                <div>

                                    <span class="price-label">Service fee</span>

                                    <span class="price">₱<?php echo number_format($row['price'], 2); ?></span>

                                </div>



                                <a href="<?php echo $is_logged_in ? 'book_appointment.php?service_id=' . $row['id'] . '&category=Vet' : 'login.php'; ?>" class="btn-book">

                                    Book Now <i class="fa-solid fa-arrow-right"></i>

                                </a>

                            </div>

                        </div>

                    </article>

                <?php endforeach; ?>

            <?php else: ?>

                <div class="no-data">

                    <i class="fa-solid fa-circle-exclamation" style="font-size:42px; color:#cbd5e1; margin-bottom:15px; display:block;"></i>

                    <p>We are currently updating our vet clinic pricelist. Please check back later!</p>

                </div>

            <?php endif; ?>



            </div>



            <div class="care-strip">

                <div class="care-item">

                    <i class="fa-solid fa-heart-pulse"></i>

                    <div>

                        <strong>Health-focused care</strong>

                        <span>Services designed around your pet's well-being.</span>

                    </div>

                </div>



                <div class="care-item">

                    <i class="fa-solid fa-syringe"></i>

                    <div>

                        <strong>Preventive services</strong>

                        <span>Support your pet with routine health care.</span>

                    </div>

                </div>



                <div class="care-item">

                    <i class="fa-solid fa-calendar-check"></i>

                    <div>

                        <strong>Easy appointment booking</strong>

                        <span>Choose your service and schedule online.</span>

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

                    <a href="https://mail.google.com/mail/?view=cm&to=boogiespetcareservices@gmail.com"

                       onclick="openGmailCompose(event, this.href)"

                       aria-label="Email Boogie's Pet Care">

                        <i class="fa-solid fa-envelope"></i>

                    </a>

                </div>

            </div>

            <div>

                <h4>Quick Links</h4>

                <a href="index.php">Home</a>

                <a href="petservices.php">Services & Prices</a>

                <a href="contactus.php">Contact & Reviews</a>

                <a href="faqs.php">FAQs</a>

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



    <script>
        // ===== SHARED SMART / RELATED SEARCH =====
        const searchForm = document.getElementById('siteSearchForm');
        const searchInput = document.getElementById('siteSearchInput');
        const searchStatus = document.getElementById('searchStatus');

        const searchRoutes = [
            {
                keywords: [
                    'groom', 'grooming', 'groomed', 'bath', 'bathing', 'wash', 'shampoo',
                    'haircut', 'hair cut', 'trim', 'trimming', 'nail', 'nails',
                    'fur', 'coat', 'brush', 'brushing', 'styling', 'puppy cut',
                    'summer cut', 'shave', 'blow dry', 'cleaning',
                    'ligo', 'paligo', 'gupit', 'kuko'
                ],
                label: 'Grooming Services',
                url: 'grooming.php'
            },
            {
                keywords: [
                    'vet', 'veterinary', 'veterinarian', 'doctor', 'clinic', 'checkup',
                    'check-up', 'consultation', 'consult', 'health', 'wellness',
                    'medical', 'medicine', 'treatment', 'vaccination', 'vaccine',
                    'rabies', 'deworming', 'injection', 'sick', 'sakit', 'bakuna',
                    'gamot', 'doktor'
                ],
                label: 'Vet Clinic',
                url: 'vetclinic.php'
            },
            {
                keywords: [
                    'hotel', 'pet hotel', 'boarding', 'board', 'daycare', 'day care',
                    'overnight', 'overnight stay', 'stay', 'sleep', 'lodge', 'lodging',
                    'room', 'pet stay', 'temporary care', 'leave my pet',
                    'watch my pet', 'tulog', 'matulog', 'tirahan'
                ],
                label: 'Pet Hotel',
                url: 'pethotel.php'
            },
            {
                keywords: [
                    'service', 'services', 'pet service', 'pet services', 'price', 'prices',
                    'pricing', 'cost', 'costs', 'rate', 'rates', 'fee', 'fees',
                    'package', 'packages', 'price list', 'pet care', 'care',
                    'available', 'how much', 'presyo', 'magkano'
                ],
                label: 'Pet Services',
                url: 'petservices.php'
            },
            {
                keywords: [
                    'contact', 'phone', 'telephone', 'mobile', 'cell', 'email', 'gmail',
                    'address', 'location', 'where', 'directions', 'support', 'help',
                    'message', 'reach', 'call', 'tawag', 'lokasyon', 'saan'
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

        function levenshteinDistance(a, b) {
            a = String(a);
            b = String(b);

            if (a === b) return 0;
            if (!a.length) return b.length;
            if (!b.length) return a.length;

            const previous = Array.from({ length: b.length + 1 }, (_, i) => i);

            for (let i = 1; i <= a.length; i++) {
                const current = [i];

                for (let j = 1; j <= b.length; j++) {
                    current[j] = Math.min(
                        current[j - 1] + 1,
                        previous[j] + 1,
                        previous[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1)
                    );
                }

                for (let j = 0; j < current.length; j++) {
                    previous[j] = current[j];
                }
            }

            return previous[b.length];
        }

        function searchKeywordMatches(query, keyword) {
            if (!query || !keyword) return false;
            if (query.includes(keyword) || keyword.includes(query)) return true;

            const queryWords = query.split(' ').filter(Boolean);
            const keywordWords = keyword.split(' ').filter(Boolean);

            for (const qWord of queryWords) {
                for (const kWord of keywordWords) {
                    if (qWord.length >= 4 && kWord.length >= 4) {
                        const maxDistance = Math.min(
                            2,
                            Math.floor(Math.max(qWord.length, kWord.length) / 4)
                        );

                        if (levenshteinDistance(qWord, kWord) <= maxDistance) {
                            return true;
                        }
                    }
                }
            }

            return false;
        }

        function findSearchRoute(query) {
            const normalized = normalizeSearchText(query);

            if (!normalized) return null;

            for (const route of searchRoutes) {
                if (route.keywords.some(keyword =>
                    normalized.includes(normalizeSearchText(keyword))
                )) {
                    return route;
                }
            }

            for (const route of searchRoutes) {
                if (route.keywords.some(keyword =>
                    searchKeywordMatches(normalized, normalizeSearchText(keyword))
                )) {
                    return route;
                }
            }

            return null;
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
                    showSearchStatus(
                        'Type something to search, like <strong>bath</strong>, <strong>vaccine</strong>, <strong>boarding</strong>, or <strong>price</strong>.'
                    );
                    return;
                }

                const route = findSearchRoute(query);

                if (route) {
                    showSearchStatus(`Opening <strong>${route.label}</strong>...`);

                    setTimeout(() => {
                        window.location.href = route.url;
                    }, 180);
                } else {
                    showSearchStatus(
                        'No related service found. Try grooming, vet, boarding, price, or contact.'
                    );
                }
            });
        }

        // ===== GMAIL COMPOSE =====
        function openGmailCompose(event, url) {
            event.preventDefault();

            const width = 760;
            const height = 650;
            const left = Math.max(
                0,
                Math.round((window.screen.width - width) / 2)
            );
            const top = Math.max(
                0,
                Math.round((window.screen.height - height) / 2)
            );

            const popup = window.open(
                url,
                'boogiesGmailCompose',
                `width=${width},height=${height},left=${left},top=${top},resizable=yes,scrollbars=yes`
            );

            if (!popup) {
                window.location.href = url;
            }
        }
    </script>



</body>

</html>
