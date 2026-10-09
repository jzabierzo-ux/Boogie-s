<?php
require_once __DIR__ . '/shared_session_bootstrap.php';
// Check if user is logged in
$is_logged_in = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && strtolower(trim((string)($_SESSION['role'] ?? ''))) === 'customer';
$user_name = $is_logged_in ? (string)($_SESSION['user_name'] ?? 'Customer') : 'Guest';
// Generate booking URLs dynamically based on login status
$book_grooming = $is_logged_in ? 'book_appointment.php?category=Grooming' : 'login.php';
$book_vet      = $is_logged_in ? 'book_appointment.php?category=Vet Services' : 'login.php';
$book_hotel    = $is_logged_in ? 'book_appointment.php?category=Pet Hotel' : 'login.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pet Services | Boogie's Pet Care & Services</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --brand-yellow: #ffcc00;
            --brand-yellow-soft: #fff6c7;
            --brand-blue: #001f3f;
            --brand-blue-2: #0b3b66;
            --brand-blue-light: #eef5fb;
            --text: #17324d;
            --muted: #6b7c8f;
            --bg: #f7f9fc;
            --white: #ffffff;
            --border: #e4eaf1;
            --shadow: 0 18px 50px rgba(0,31,63,.08);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            font-family: 'Poppins', sans-serif;
            color: var(--text);
            background: var(--bg);
            line-height: 1.6;
            min-height: 100vh;
            overflow-x: hidden;
        }
        a { color: inherit; }
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
        /* MAIN HERO */
        main { min-height: 70vh; }
        .hero {
            position:relative;
            overflow:hidden;
            background:linear-gradient(135deg, #eef6ff 0%, #fffdf5 100%);
            border-bottom:1px solid var(--border);
        }
        .hero::before,
        .hero::after {
            content:'';
            position:absolute;
            border-radius:50%;
            pointer-events:none;
        }
        .hero::before { width:340px; height:340px; background:rgba(255,204,0,.16); right:-100px; top:-120px; }
        .hero::after { width:250px; height:250px; background:rgba(0,91,140,.08); left:-90px; bottom:-120px; }
        .hero-inner { max-width:1180px; margin:0 auto; padding:64px 28px 54px; position:relative; z-index:1; }
        .eyebrow { display:inline-flex; align-items:center; gap:8px; background:#fff; border:1px solid var(--border); color:var(--brand-blue); padding:7px 13px; border-radius:999px; font-size:11px; font-weight:800; letter-spacing:.6px; text-transform:uppercase; box-shadow:0 6px 18px rgba(0,31,63,.05); }
        .eyebrow i { color:#f0b400; }
        .hero h1 { max-width:760px; font-size:44px; line-height:1.15; color:var(--brand-blue); margin:18px 0 14px; font-weight:800; letter-spacing:-1px; }
        .hero p { max-width:700px; color:var(--muted); font-size:16px; }
        .hero-note { margin-top:22px; display:flex; gap:18px; flex-wrap:wrap; font-size:12px; color:#708197; font-weight:600; }
        .hero-note span { display:inline-flex; align-items:center; gap:7px; }
        .hero-note i { color:#15906f; }
        /* SERVICES */
        .services-wrap { max-width:1180px; margin:0 auto; padding:54px 28px 90px; }
        .section-heading { display:flex; justify-content:space-between; align-items:end; gap:25px; margin-bottom:26px; }
        .section-kicker { color:#8b99a9; font-size:11px; text-transform:uppercase; letter-spacing:1.3px; font-weight:800; margin-bottom:7px; }
        .section-heading h2 { color:var(--brand-blue); font-size:28px; font-weight:800; line-height:1.2; }
        .section-heading p { color:var(--muted); font-size:13px; max-width:490px; text-align:right; }
        .services-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:24px; }
        .service-card {
            background:var(--white);
            border:1px solid var(--border);
            border-radius:20px;
            overflow:hidden;
            display:flex;
            flex-direction:column;
            min-height:440px;
            box-shadow:0 8px 28px rgba(0,31,63,.05);
            transition:transform .28s ease, box-shadow .28s ease, border-color .28s ease;
        }
        .service-card:hover { transform:translateY(-7px); box-shadow:var(--shadow); border-color:#ccd8e4; }
        .service-visual { height:180px; display:flex; align-items:center; justify-content:center; position:relative; overflow:hidden; }
        .service-visual::after { content:''; position:absolute; width:150px; height:150px; border-radius:50%; background:rgba(255,255,255,.38); right:-50px; bottom:-65px; }
        .service-visual.grooming { background:linear-gradient(145deg,#f4e8ff,#ead9ff); }
        .service-visual.vet { background:linear-gradient(145deg,#e2f4ff,#d3edff); }
        .service-visual.hotel { background:linear-gradient(145deg,#fff4cf,#ffe9a2); }
        .visual-icon { width:88px; height:88px; border-radius:50%; background:rgba(255,255,255,.82); display:flex; align-items:center; justify-content:center; font-size:38px; box-shadow:0 10px 24px rgba(0,31,63,.08); position:relative; z-index:1; }
        .grooming .visual-icon { color:#8b42c8; }
        .vet .visual-icon { color:#0d82ba; }
        .hotel .visual-icon { color:#b77900; }
        .service-body { padding:26px 25px 25px; display:flex; flex-direction:column; flex:1; }
        .service-tag { display:inline-flex; align-self:flex-start; padding:6px 10px; border-radius:999px; font-size:10px; text-transform:uppercase; font-weight:800; letter-spacing:.6px; margin-bottom:12px; }
        .tag-groom { background:#f3e8ff; color:#8b42c8; }
        .tag-vet { background:#e6f5ff; color:#0d82ba; }
        .tag-hotel { background:#fff3cd; color:#9a6700; }
        .service-card h3 { font-size:23px; color:var(--brand-blue); margin-bottom:10px; font-weight:800; }
        .service-card p.desc { font-size:13px; color:var(--muted); line-height:1.7; margin-bottom:22px; flex:1; }
        .button-group { display:grid; grid-template-columns:1fr auto; gap:10px; align-items:center; }
        .btn-book { background:var(--brand-blue); color:var(--brand-yellow); padding:12px 16px; border-radius:11px; text-decoration:none; font-weight:800; font-size:13px; display:flex; align-items:center; justify-content:center; gap:8px; transition:.25s; }
        .btn-book:hover { transform:translateY(-1px); background:var(--brand-blue-2); }
        .btn-secondary { background:#f4f7fa; color:var(--brand-blue); padding:11px 14px; border-radius:11px; text-decoration:none; font-weight:700; font-size:12px; transition:.25s; text-align:center; border:1px solid #e9edf2; }
        .btn-secondary:hover { background:#eaf0f6; }
        .booking-hint { display:flex; align-items:center; gap:6px; font-size:10px; color:#9aa7b7; margin-top:11px; }
        /* QUICK TRUST STRIP */
        .trust-strip { margin-top:26px; background:var(--brand-blue); color:#fff; border-radius:20px; padding:22px 26px; display:grid; grid-template-columns:repeat(3,1fr); gap:20px; box-shadow:0 14px 30px rgba(0,31,63,.13); }
        .trust-item { display:flex; align-items:center; gap:12px; }
        .trust-item i { width:40px; height:40px; border-radius:12px; display:flex; align-items:center; justify-content:center; background:rgba(255,204,0,.13); color:var(--brand-yellow); }
        .trust-item strong { display:block; font-size:12px; }
        .trust-item span { display:block; font-size:10px; color:#c8d3df; margin-top:2px; }
        /* FOOTER */
        footer { background:var(--brand-blue); padding:68px 28px 30px; color:#fff; border-top:4px solid var(--brand-yellow); }
        .footer-main { display:grid; grid-template-columns:2fr 1fr 1fr 1.5fr; gap:42px; border-bottom:1px solid rgba(255,255,255,.12); padding-bottom:42px; max-width:1180px; margin:0 auto; }
        .footer-main h4 { color:var(--brand-yellow); margin-bottom:16px; text-transform:uppercase; font-weight:800; font-size:12px; letter-spacing:.5px; }
        .footer-main p, .footer-main a { color:#cbd5e1; text-decoration:none; font-size:12px; display:block; margin-bottom:9px; line-height:1.7; }
        .footer-main a:hover { color:#fff; }
        .socials { display:flex; gap:10px; margin-top:18px; }
        .socials a { background:rgba(255,255,255,.09); width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; transition:.25s; color:#fff; margin:0; }
        .socials a:hover { background:var(--brand-yellow); color:var(--brand-blue); }
        .footer-bottom { max-width:1180px; margin:0 auto; padding-top:24px; text-align:center; font-size:11px; color:#91a1b1; }
        @media (max-width: 980px) {
            .nav-top { grid-template-columns:1fr; gap:12px; }
            .nav-links { justify-content:flex-start; }
            .hero h1 { font-size:37px; }
            .services-grid { grid-template-columns:1fr 1fr; }
            .trust-strip { grid-template-columns:1fr; }
            .footer-main { grid-template-columns:1fr 1fr; }
        }
        @media (max-width: 680px) {
            .categories ul { overflow-x:auto; justify-content:flex-start; padding-left:12px; }
            .categories ul li a { white-space:nowrap; padding:10px 13px; }
            .hero-inner { padding-top:45px; }
            .hero h1 { font-size:31px; }
            .hero p { font-size:14px; }
            .section-heading { display:block; }
            .section-heading p { text-align:left; margin-top:8px; }
            .services-grid { grid-template-columns:1fr; }
            .footer-main { grid-template-columns:1fr; gap:25px; }
            .button-group { grid-template-columns:1fr; }
            .hello-user { width:100%; }
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
            .hello-user {
                font-size: 12px;
            }
            .categories ul {
                justify-content: flex-start;
                overflow-x: auto;
                scrollbar-width: thin;
            }
            .services-grid {
                gap: 18px;
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
                gap: 9px;
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
                flex: 0 0 36px;
                width: 36px;
                height: 36px;
            }
            .nav-links {
                gap: 7px;
                flex-wrap: wrap;
            }
            .nav-links .cart-btn,
            .nav-links .login-btn {
                min-height: 42px;
                padding: 10px 13px;
                font-size: 11px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }
            .hello-user {
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
                padding: 39px 18px 34px;
            }
            .eyebrow {
                font-size: 9px;
                padding: 6px 10px;
            }
            .hero h1 {
                font-size: 29px;
                line-height: 1.18;
                letter-spacing: -.5px;
                margin: 15px 0 11px;
            }
            .hero p {
                font-size: 12px;
                line-height: 1.65;
            }
            .hero-note {
                display: grid;
                grid-template-columns: 1fr;
                gap: 7px;
                margin-top: 17px;
                font-size: 9px;
            }
            .services-wrap {
                padding: 38px 15px 55px;
            }
            .section-heading {
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
                height: 145px;
            }
            .visual-icon {
                width: 72px;
                height: 72px;
                font-size: 30px;
            }
            .service-body {
                padding: 19px 17px 18px;
            }
            .service-tag {
                font-size: 8px;
                padding: 5px 9px;
                margin-bottom: 9px;
            }
            .service-card h3 {
                font-size: 20px;
                margin-bottom: 7px;
            }
            .service-card p.desc {
                font-size: 11px;
                line-height: 1.65;
                margin-bottom: 15px;
            }
            .button-group {
                grid-template-columns: 1fr;
                gap: 7px;
            }
            .btn-book,
            .btn-secondary {
                width: 100%;
                min-height: 43px;
                padding: 10px 12px;
                font-size: 10px;
            }
            .booking-hint {
                font-size: 8px;
                margin-top: 8px;
            }
            .trust-strip {
                margin-top: 17px;
                border-radius: 16px;
                padding: 15px;
                gap: 12px;
            }
            .trust-item {
                gap: 9px;
                align-items: flex-start;
            }
            .trust-item i {
                width: 34px;
                height: 34px;
                flex: 0 0 34px;
                border-radius: 10px;
                font-size: 12px;
            }
            .trust-item strong {
                font-size: 10px;
            }
            .trust-item span {
                font-size: 8px;
                line-height: 1.5;
            }
            footer {
                padding: 43px 15px 24px;
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
                letter-spacing: .8px;
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
            .service-card h3 {
                font-size: 18px;
            }
            .service-card p.desc {
                font-size: 10px;
            }
        }
        /* ===== SEARCH + MOBILE HEADER CONSISTENCY ===== */
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
        .mobile-search-wrap {
            display: none;
        }
        @media (max-width: 680px) {
            header {
                width: 100%;
            }
            .nav-top {
                grid-template-columns: minmax(0, 1fr) auto !important;
                grid-template-rows: auto !important;
                gap: 9px !important;
                width: 100%;
                padding: 10px 12px !important;
                align-items: center;
            }
            .nav-top > .logo {
                grid-column: 1;
                grid-row: 1;
                width: auto;
                min-width: 0;
            }
            .nav-top > .search {
                display: none !important;
            }
            .nav-links {
                grid-column: 2;
                grid-row: 1;
                width: auto !important;
                justify-content: flex-end !important;
                align-items: center;
                flex-wrap: nowrap !important;
                gap: 6px !important;
                min-width: 0;
            }
            .nav-links .cart-btn {
                min-height: 45px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 10px 13px !important;
                font-size: 10.5px !important;
                border-radius: 10px;
                white-space: nowrap;
            }
            .mobile-search-wrap {
                display: block;
                width: 100%;
                padding: 0 12px 10px;
                background: #fff;
            }
            .mobile-search-wrap .search {
                display: flex !important;
                width: 100%;
                min-height: 46px;
                margin: 0;
                padding: 4px 6px 4px 11px;
                border-radius: 12px;
                background: #f5f8fb;
                border: 1px solid #e0e7ef;
            }
            .mobile-search-wrap .search input {
                min-width: 0;
                width: 100%;
                padding: 8px 5px;
                font-size: 11.5px;
            }
            .mobile-search-wrap .search button {
                width: 36px;
                height: 36px;
                flex: 0 0 36px;
                border: none;
                border-radius: 9px;
                background: var(--brand-blue);
                color: #fff;
            }
            .search-status {
                top: 145px;
                left: 12px;
                right: 12px;
                width: auto;
                min-width: 0;
                max-width: none;
                transform: translateY(-8px);
            }
            .search-status.show { transform: translateY(0); }
            .categories ul {
                width: 100%;
                max-width: none;
                overflow-x: auto;
                overflow-y: hidden;
                justify-content: flex-start !important;
                gap: 4px;
                padding: 6px 10px;
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
                border-radius: 9px;
            }
            .categories i { font-size: 11px; }
        }
        @media (max-width: 420px) {
            .nav-top { padding-left: 9px !important; padding-right: 9px !important; }
            .nav-logo-img { width: 38px; height: 38px; }
            .logo-text b { font-size: 15px; }
            .logo-text span { font-size: 6px; letter-spacing: .8px; }
            .nav-links .cart-btn { padding: 9px 10px !important; font-size: 9px !important; }
            .mobile-search-wrap { padding-left: 9px; padding-right: 9px; }
            .mobile-search-wrap .search input { font-size: 11px; }
            .categories ul li a { font-size: 8px; padding: 8px 10px; }
        }
        /* ===== FINAL MOBILE HEADER OVERLAP FIX ===== */
        @media (max-width: 680px) {
            .nav-top {
                grid-template-columns: minmax(0, 1fr) auto !important;
                grid-template-rows: auto !important;
                gap: 8px !important;
                width: 100%;
                padding: 10px 12px !important;
                align-items: center;
            }
            .nav-top > .logo {
                grid-column: 1 !important;
                grid-row: 1 !important;
                width: 100% !important;
                min-width: 0 !important;
                max-width: 100%;
                overflow: hidden;
                gap: 8px;
            }
            .nav-top > .logo .logo-text {
                min-width: 0;
                overflow: hidden;
            }
            .nav-top > .logo .logo-text b,
            .nav-top > .logo .logo-text span {
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .nav-top > .search {
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
                overflow: visible;
            }
            /* Do not let the long "Hi, Full Name" push into the logo. */
            .nav-links > span {
                display: none !important;
            }
            .nav-links .cart-btn {
                min-height: 42px !important;
                padding: 10px 13px !important;
                font-size: 10px !important;
                white-space: nowrap;
                display: inline-flex !important;
                align-items: center;
                justify-content: center;
            }
            .nav-links a[style*="ef4444"] {
                font-size: 11px !important;
                margin-left: 0 !important;
                padding: 9px 4px !important;
                white-space: nowrap;
            }
            .mobile-search-wrap {
                display: block;
                width: 100%;
                padding: 0 12px 10px;
                background: #fff;
            }
            .mobile-search-wrap .search {
                display: flex !important;
                width: 100%;
                min-height: 46px;
            }
        }
        @media (max-width: 420px) {
            .nav-top {
                padding-left: 9px !important;
                padding-right: 9px !important;
            }
            .nav-links .cart-btn {
                padding: 9px 10px !important;
                font-size: 9px !important;
            }
            .mobile-search-wrap {
                padding-left: 9px;
                padding-right: 9px;
            }
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
    <header>
        <div class="nav-top">
            <a href="index.php" class="logo">
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
                    <span style="color: var(--brand-blue); font-weight: 600; font-size: 14px;"><i class="fa-regular fa-user"></i> Hi, <?php echo htmlspecialchars($user_name); ?></span>
                    <a href="dashboard.php" class="cart-btn">Dashboard</a>
                    <a href="logout.php" style="color: #ef4444; text-decoration:none; font-weight: 600; font-size: 14px; margin-left:10px;">Logout</a>
                <?php else: ?>
                    <a href="login.php" class="cart-btn">Login / Register</a>
                <?php endif; ?>
            </div>
        </div>
        <!-- Dedicated mobile search row. Desktop keeps the original header search. -->
        <div class="mobile-search-wrap" aria-label="Mobile site search">
            <form class="search" id="mobileSiteSearchForm" autocomplete="off">
                <input
                    type="search"
                    id="mobileSiteSearchInput"
                    placeholder="Search for grooming, hotel, or vet services..."
                    aria-label="Search services"
                >
                <button type="submit" aria-label="Search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </button>
            </form>
        </div>
        <nav class="categories">
            <ul>
                <li><a href="index.php"><i class="fa-solid fa-house"></i> HOME</a></li>
                <li><a href="petservices.php" class="active"><i class="fa-solid fa-paw"></i> PET SERVICES</a></li>
                <li><a href="grooming.php"><i class="fa-solid fa-scissors"></i> GROOMING</a></li>
                <li><a href="vetclinic.php"><i class="fa-solid fa-stethoscope"></i> VET CLINIC</a></li>
                <li><a href="pethotel.php"><i class="fa-solid fa-hotel"></i> PET HOTEL</a></li>
                <li><a href="contactus.php"><i class="fa-solid fa-phone"></i> CONTACT</a></li>
            </ul>
        </nav>
    </header>
    <div id="searchStatus" class="search-status" role="status" aria-live="polite"></div>
    <main>
        <section class="hero">
            <div class="hero-inner">
                <div class="eyebrow"><i class="fa-solid fa-paw"></i> Care made for every pet</div>
                <h1>Everything your pet needs, all in one place.</h1>
                <p>From grooming and veterinary care to a safe place to stay, choose the service that fits your pet's needs and book with confidence.</p>
                <div class="hero-note">
                    <span><i class="fa-solid fa-circle-check"></i> Friendly professional care</span>
                    <span><i class="fa-solid fa-circle-check"></i> Convenient appointment booking</span>
                    <span><i class="fa-solid fa-circle-check"></i> Dasmariñas, Cavite</span>
                </div>
            </div>
        </section>
        <section class="services-wrap">
            <div class="section-heading">
                <div>
                    <div class="section-kicker">Our services</div>
                    <h2>Find the right care for your pet</h2>
                </div>
                <p>Explore our main services, check the details, and book the option that works best for you.</p>
            </div>
            <div class="services-grid">
                <article class="service-card">
                    <div class="service-visual grooming">
                        <div class="visual-icon"><i class="fa-solid fa-scissors"></i></div>
                    </div>
                    <div class="service-body">
                        <span class="service-tag tag-groom">Grooming care</span>
                        <h3>Pet Grooming</h3>
                        <p class="desc">Keep your furry friend clean, comfortable, and looking their best with professional baths, haircuts, and grooming packages.</p>
                        <div class="button-group">
                            <a href="<?php echo $book_grooming; ?>" class="btn-book">Book Grooming <i class="fa-solid fa-arrow-right"></i></a>
                            <a href="grooming.php" class="btn-secondary">View details</a>
                        </div>
                        <p class="booking-hint"><i class="fa-solid fa-circle-info"></i> Choose your package on the next page.</p>
                    </div>
                </article>
                <article class="service-card">
                    <div class="service-visual vet">
                        <div class="visual-icon"><i class="fa-solid fa-stethoscope"></i></div>
                    </div>
                    <div class="service-body">
                        <span class="service-tag tag-vet">Health & wellness</span>
                        <h3>Vet Services</h3>
                        <p class="desc">Support your pet's health with check-ups, vaccinations, deworming, diagnostics, and professional veterinary care.</p>
                        <div class="button-group">
                            <a href="<?php echo $book_vet; ?>" class="btn-book" style="background:#0d82ba;color:#fff;">Book Vet Visit <i class="fa-solid fa-arrow-right"></i></a>
                            <a href="vetclinic.php" class="btn-secondary">View details</a>
                        </div>
                        <p class="booking-hint"><i class="fa-solid fa-circle-info"></i> Pick your schedule on the next page.</p>
                    </div>
                </article>
                <article class="service-card">
                    <div class="service-visual hotel">
                        <div class="visual-icon"><i class="fa-solid fa-house-chimney-window"></i></div>
                    </div>
                    <div class="service-body">
                        <span class="service-tag tag-hotel">Stay & boarding</span>
                        <h3>Pet Hotel</h3>
                        <p class="desc">Going away? Let your pet stay somewhere safe, comfortable, and supervised with our daycare and overnight boarding service.</p>
                        <div class="button-group">
                            <a href="<?php echo $book_hotel; ?>" class="btn-book" style="background:#b77900;color:#fff;">Book Pet Hotel <i class="fa-solid fa-arrow-right"></i></a>
                            <a href="pethotel.php" class="btn-secondary">View details</a>
                        </div>
                        <p class="booking-hint"><i class="fa-solid fa-circle-info"></i> Specify your dates on the next page.</p>
                    </div>
                </article>
            </div>
            <div class="trust-strip">
                <div class="trust-item">
                    <i class="fa-solid fa-heart"></i>
                    <div><strong>Pet-first care</strong><span>Comfort and safety always come first.</span></div>
                </div>
                <div class="trust-item">
                    <i class="fa-solid fa-calendar-check"></i>
                    <div><strong>Easy booking</strong><span>Choose a service and schedule online.</span></div>
                </div>
                <div class="trust-item">
                    <i class="fa-solid fa-location-dot"></i>
                    <div><strong>Local & convenient</strong><span>Proudly serving pet owners in Dasmariñas.</span></div>
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
                <p><i class="fa-solid fa-location-dot"></i> 110 Don Placido Campos Ave San Agustin 3, Dasmariñas, Philippines</p>
            </div>
        </div>
        <div class="footer-bottom">
            <p>© 2026 Boogie's Pet Care & Services - Dasmariñas Branch. All rights reserved.</p>
            <div style="margin-top:10px;">
            </div>
        </div>
    </footer>
    <script>
        /* ===== SMART / RELATED SITE SEARCH ===== */
        const searchStatus = document.getElementById('searchStatus');
        const searchForms = [
            {
                form: document.getElementById('siteSearchForm'),
                input: document.getElementById('siteSearchInput')
            },
            {
                form: document.getElementById('mobileSiteSearchForm'),
                input: document.getElementById('mobileSiteSearchInput')
            }
        ].filter(item => item.form && item.input);
        const searchRoutes = [
            {
                keywords: [
                    'groom', 'grooming', 'groomed', 'bath', 'bathing', 'wash', 'shampoo',
                    'haircut', 'hair cut', 'cut', 'trim', 'trimming', 'nail', 'nails',
                    'nail trim', 'fur', 'fur care', 'coat', 'brush', 'brushing', 'styling',
                    'style', 'puppy cut', 'summer cut', 'shave', 'shave down', 'bear cut',
                    'poodle cut', 'blow dry', 'bath blow dry', 'clean', 'cleaning',
                    'alaga sa balahibo', 'ligo', 'paligo', 'paliguan', 'gupit', 'kuko', 'balbas'
                ],
                label: 'Grooming Services',
                url: 'grooming.php'
            },
            {
                keywords: [
                    'vet', 'vets', 'veterinary', 'veterinarian', 'doctor', 'dr', 'clinic',
                    'checkup', 'check-up', 'check up', 'consultation', 'consult', 'health',
                    'wellness', 'medical', 'medicine', 'medication', 'treatment', 'diagnostic',
                    'diagnostics', 'vaccination', 'vaccination shot', 'vaccine', 'vaccines',
                    'rabies', 'anti rabies', 'deworming', 'deworm', 'worm', 'worms', 'shot',
                    'shots', 'injection', 'sick', 'ill', 'illness', 'sakit', 'kalusugan',
                    'bakuna', 'gamot', 'doktor', 'check'
                ],
                label: 'Vet Clinic',
                url: 'vetclinic.php'
            },
            {
                keywords: [
                    'hotel', 'pet hotel', 'boarding', 'board', 'daycare', 'day care', 'day-care',
                    'overnight', 'overnight stay', 'stay', 'sleep', 'lodge', 'lodging',
                    'accommodation', 'room', 'pet stay', 'temporary care', 'leave my pet',
                    'watch my pet', 'tulog', 'matulog', 'pahinga', 'tirahan', 'pag stay'
                ],
                label: 'Pet Hotel',
                url: 'pethotel.php'
            },
            {
                keywords: [
                    'service', 'services', 'pet service', 'pet services', 'price', 'prices',
                    'pricing', 'cost', 'costs', 'rate', 'rates', 'fee', 'fees', 'package',
                    'packages', 'menu', 'list', 'pricelist', 'price list', 'pet care',
                    'care', 'option', 'options', 'available', 'how much', 'presyo', 'magkano',
                    'alaga', 'alagaan'
                ],
                label: 'Pet Services',
                url: 'petservices.php'
            },
            {
                keywords: [
                    'contact', 'contacts', 'phone', 'telephone', 'mobile', 'cell', 'email',
                    'gmail', 'address', 'location', 'where', 'directions', 'support', 'help',
                    'message', 'reach', 'call', 'tawag', 'lokasyon', 'saan', 'contact us'
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
                    const insertCost = current[j - 1] + 1;
                    const deleteCost = previous[j] + 1;
                    const replaceCost = previous[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1);
                    current[j] = Math.min(insertCost, deleteCost, replaceCost);
                }
                for (let j = 0; j < current.length; j++) {
                    previous[j] = current[j];
                }
            }
            return previous[b.length];
        }
        function findSearchRoute(query) {
    const normalized = normalizeSearchText(query);
    const queryWords = normalized.split(' ').filter(Boolean);
    if (!normalized) return null;

    let bestRoute = null;
    let bestScore = 0;

    for (const route of searchRoutes) {
        let score = 0;
        for (const keyword of route.keywords) {
            const normalizedKeyword = normalizeSearchText(keyword);
            if (!normalizedKeyword) continue;
            if (normalized.includes(normalizedKeyword)) {
                score += normalizedKeyword.includes(' ') ? 6 : 4;
                continue;
            }
            const keywordWords = normalizedKeyword.split(' ').filter(Boolean);
            for (const qWord of queryWords) {
                for (const kWord of keywordWords) {
                    if (qWord.length >= 3 && kWord.startsWith(qWord)) {
                        score += 2;
                    } else if (kWord.length >= 3 && qWord.startsWith(kWord)) {
                        score += 2;
                    } else if (qWord.length >= 5 && kWord.length >= 5 && levenshteinDistance(qWord, kWord) <= 2) {
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
        searchForms.forEach(({ form, input }) => {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                const query = input.value.trim();
                if (!query) {
                    input.focus();
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
                        'No related service found. Try a service, treatment, grooming term, boarding term, price, or contact detail.'
                    );
                }
            });
        });
    </script>
    <script>
        // ===== GMAIL COMPOSE =====
        function openGmailCompose(event, url) {
            event.preventDefault();
            const width = 760;
            const height = 650;
            const left = Math.max(0, Math.round((window.screen.width - width) / 2));
            const top = Math.max(0, Math.round((window.screen.height - height) / 2));
            const popup = window.open(
                url,
                'boogiesGmailCompose',
                `width=${width},height=${height},left=${left},top=${top},resizable=yes,scrollbars=yes`
            );
            // Mobile browsers may block popup-style windows, so use Gmail in the same tab.
            if (!popup) {
                window.location.href = url;
            }
        }
    </script>
</body>
</html>
