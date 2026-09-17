<?php
session_start();
// Kung naka-login na sila, i-redirect sa home.php para sa personalized view
if(isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true){
    header("Location: home.php");
    exit();
}

include 'db_connect.php'; // Siguraduhing tama ang path

// --- FETCH REAL REVIEWS ---
$review_query = "SELECT r.*, u.full_name, a.service
                 FROM reviews r
                 JOIN users u ON r.user_id = u.id
                 JOIN appointments a ON r.appointment_id = a.id
                 WHERE r.rating >= 4
                 ORDER BY r.review_date DESC
                 LIMIT 3";
$reviews_result = @mysqli_query($conn, $review_query);

// --- FETCH AGGREGATE STATS ---
$stats_query = "SELECT COUNT(*) as total_reviews, AVG(rating) as avg_rating FROM reviews";
$stats_result = @mysqli_query($conn, $stats_query);
$stats_row = @mysqli_fetch_assoc($stats_result);

$total_reviews = $stats_row['total_reviews'] ?? 0;
$avg_rating = ($total_reviews > 0) ? number_format($stats_row['avg_rating'], 1) : "0.0";
$parent_text = ($total_reviews == 1) ? "happy fur-parent" : "happy fur-parents";

// --- FETCH DYNAMIC PROMOTIONS ---
$promos_list = [];
try {
    $current_date = date('Y-m-d');
    // Fetch from the updated promos table where status is active and not expired
    $promo_query = "SELECT * FROM promos 
                    WHERE status = 'active' 
                    AND (expiry_date >= '$current_date' OR expiry_date IS NULL OR expiry_date = '0000-00-00') 
                    ORDER BY id DESC LIMIT 3";
    
    $promo_result = @mysqli_query($conn, $promo_query);
    
    if ($promo_result && mysqli_num_rows($promo_result) > 0) {
        while($row = mysqli_fetch_assoc($promo_result)) {
            $promos_list[] = $row;
        }
    }
} catch (Exception $e) {
    // Failsafe if table doesn't exist yet
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
            /* Dasmariñas Branch Brand Colors */
            --brand-yellow: #ffcc00; 
            --brand-blue: #001f3f; 
            --brand-blue-light: #002d5b;
            --dark-bg: var(--brand-blue);
            --light-text: #ffffff;
            --text-on-yellow: #1e293b;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; color: #1e293b; background: #fff; line-height: 1.6; overflow-x: hidden;}

        /* --- HEADER & NAV (COPIED EXACTLY FROM PETSERVICES.PHP) --- */
        .promo-bar { background: var(--brand-blue); color: var(--brand-yellow); text-align: center; padding: 8px; font-size: 12px; font-weight: 500; border-bottom: 2px solid var(--brand-yellow); }
        header { position: sticky; top: 0; background: #fff; z-index: 1000; border-bottom: 1px solid #f1f5f9; box-shadow: 0 2px 5px rgba(0,0,0,0.03); }
        .nav-top { display: flex; justify-content: space-between; align-items: center; padding: 15px 5%; }
        .logo { display: flex; align-items: center; gap: 12px; text-decoration: none; }
        .nav-logo-img { height: 50px; width: auto; object-fit: contain; display: block; }
        .logo-text { display: flex; flex-direction: column; line-height: 1.1; }
        .logo-text b { font-size: 20px; color: var(--brand-blue); }
        .logo-text span { font-size: 10px; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; font-weight: 600; }
        
        .search { flex: 0 1 450px; display: flex; background: #f1f5f9; border-radius: 8px; padding: 5px 15px; margin: 0 20px; border: 1px solid #e2e8f0; }
        .search input { border: none; background: transparent; width: 100%; padding: 8px; outline: none; font-size: 14px; font-family: 'Poppins', sans-serif; }
        .search button { background: none; border: none; color: var(--brand-blue); cursor: pointer; }

        .nav-links { display: flex; align-items: center; gap: 15px; }
        .cart-btn { background: var(--brand-blue); color: var(--brand-yellow); padding: 10px 22px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px; }

        .categories { background: var(--brand-yellow); padding: 12px 5%; border-bottom: 2px solid rgba(0,0,0,0.05); }
        .categories ul { display: flex; justify-content: center; gap: 15px; list-style: none; }
        .categories ul li a { text-decoration: none; color: var(--text-on-yellow); font-size: 13px; font-weight: 700; padding: 10px 20px; border-radius: 8px; transition: all 0.3s ease; display: flex; align-items: center; gap: 8px; }
        .categories ul li a:hover { background: rgba(0, 31, 63, 0.1); transform: translateY(-2px); color: var(--brand-blue); }

        /* --- HERO SLIDER --- */
        .slider-section { position: relative; height: 550px; overflow: hidden; background: var(--brand-blue); }
        .slider-track { display: flex; height: 100%; transition: transform 0.7s cubic-bezier(0.4, 0, 0.2, 1); }
        .slide { min-width: 100%; height: 100%; display: flex; align-items: center; padding: 0 8%; position: relative; }
        
        .slide-1 { background: linear-gradient(rgba(0,31,63,0.7), rgba(0,31,63,0.7)), url('https://images.unsplash.com/photo-1516734212186-a967f81ad0d7?q=80&w=2071&auto=format&fit=crop'); background-size: cover; background-position: center; }
        .slide-2 { background: linear-gradient(rgba(0,31,63,0.7), rgba(0,31,63,0.7)), url('https://images.unsplash.com/photo-1583337130417-3346a1be7dee?q=80&w=1964&auto=format&fit=crop'); background-size: cover; background-position: center; }

        .hero-content { color: white; max-width: 650px; transform: translateY(30px); opacity: 0; transition: 0.8s all 0.3s; }
        .slide.active .hero-content { transform: translateY(0); opacity: 1; }
        .hero-content h1 { font-size: 52px; font-weight: 800; line-height: 1.1; margin-bottom: 20px; }
        .hero-content p { font-size: 18px; opacity: 0.9; margin-bottom: 30px; }
        
        .slider-dots { position: absolute; bottom: 30px; left: 50%; transform: translateX(-50%); display: flex; gap: 10px; }
        .dot { width: 10px; height: 10px; background: rgba(255,255,255,0.3); border-radius: 50%; cursor: pointer; transition: 0.3s; }
        .dot.active { background: var(--brand-yellow); width: 25px; border-radius: 5px; }

        .btn-join-hero { background: var(--brand-yellow); color: var(--brand-blue); padding: 15px 35px; border-radius: 8px; text-decoration: none; font-weight: 700; display: inline-block; transition: 0.3s; }
        .btn-join-hero:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(255, 204, 0, 0.3); }

        /* Stats */
        .stats-bar { display: grid; grid-template-columns: repeat(4, 1fr); width: 90%; margin: -60px auto 80px; background: white; padding: 40px; border-radius: 25px; box-shadow: 0 20px 40px rgba(0,0,0,0.06); position: relative; z-index: 10; text-align: center; }
        .stat-item i { font-size: 24px; display: block; margin-bottom: 10px; color: var(--brand-blue); }
        .stat-item strong { font-size: 26px; display: block; color: var(--brand-blue); }
        .stat-item span { font-size: 13px; color: #94a3b8; font-weight: 600; }

        /* Promotions */
        .section-header { text-align: center; margin-bottom: 50px; }
        .section-header h2 { color: var(--brand-blue); font-weight: 700; font-size: 32px; margin-top: 10px; }
        .badge { background: var(--brand-yellow); color: var(--text-on-yellow); padding: 6px 16px; border-radius: 20px; font-size: 12px; font-weight: 700; text-transform: uppercase; border: 1px solid var(--brand-blue); display: inline-block; margin-bottom: 20px;}
        
        .promo-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 25px; padding: 0 5% 100px; }
        .promo-card { border-radius: 15px; padding: 40px; color: white; text-align: center; position: relative; box-shadow: 0 10px 20px rgba(0,0,0,0.05); transition: transform 0.3s ease; display: flex; flex-direction: column; }
        .promo-card:hover { transform: translateY(-5px); }
        .promo-card .tag { background: rgba(255,255,255,0.2); padding: 4px 12px; border-radius: 10px; font-size: 12px; font-weight: 600; align-self: center; margin-bottom: 15px; text-transform: uppercase; letter-spacing: 1px; }
        .promo-card h3 { font-size: 28px; margin: 0 0 10px; font-weight: 800; }
        .promo-card p { font-size: 15px; margin-bottom: 0; flex-grow: 1; opacity: 0.9; }

        .promo-card.purple { background: linear-gradient(135deg, #a855f7, #8b2cf5); }
        .promo-card.teal { background: linear-gradient(135deg, #2dd4bf, #0d9488); }
        .promo-card.red { background: linear-gradient(135deg, #f87171, #dc2626); }
        .promo-card.orange { background: linear-gradient(135deg, #fb923c, #ea580c); }

        /* Services */
        .service-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; padding: 0 5% 100px; }
        .service-card { border-radius: 15px; padding: 50px 30px; color: white; text-align: center; transition: 0.3s; background: var(--brand-blue); border-bottom: 3px solid transparent; }
        .service-card:hover { transform: translateY(-8px); background: var(--brand-blue-light); border-bottom: 3px solid var(--brand-yellow); }
        .service-card i { font-size: 32px; margin-bottom: 20px; color: var(--brand-yellow); }
        .service-card h4 { font-size: 20px; margin-bottom: 10px; color: #fff; font-weight: 700; }
        .service-card a { color: var(--brand-yellow); text-decoration: none; font-size: 14px; font-weight: 600; }

        /* Testimonials */
        .testimonials { background: #f8fafc; padding: 100px 5%; text-align: center; }
        .testimonial-grid { display: flex; flex-wrap: wrap; justify-content: center; gap: 40px; margin-bottom: 40px; }
        .t-card { background: white; padding: 45px 40px; border-radius: 15px; box-shadow: 0 10px 25px rgba(0,0,0,0.06); text-align: left; border-left: 5px solid var(--brand-yellow); flex: 1 1 420px; max-width: 500px; width: 100%; }
        .t-card .stars { font-size: 22px; color: #fbbf24; margin-bottom: 20px; }
        .t-text { font-style: italic; color: #475569; font-size: 18px; line-height: 1.7; margin-bottom: 25px; }
        .t-user { display: flex; align-items: center; gap: 15px; }
        .t-avatar { width: 60px; height: 60px; background: #e2e8f0; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 24px; border: 2px solid var(--brand-yellow); font-weight: 700; color: var(--brand-blue); }
        .t-user strong { font-size: 18px; color: var(--brand-blue); display: block; margin-bottom: 3px; }
        .t-user small { font-size: 14px; color: #64748b; }

        /* CTA & Footer */
        .cta-section { background: var(--dark-bg); padding: 120px 5% 60px; color: white; border-top: 3px solid var(--brand-yellow); margin-top: 100px; }
        .cta-box { background: var(--brand-yellow); border-radius: 20px; padding: 60px; text-align: center; color: var(--brand-blue); margin-top: -220px; margin-bottom: 60px; box-shadow: 0 15px 30px rgba(0,0,0,0.2); }
        .cta-box h2 { font-size: 34px; margin-bottom: 15px; color: var(--brand-blue); font-weight: 800; }
        .cta-box p { color: var(--brand-blue-light); }
        .cta-box .btn-group { display: flex; justify-content: center; gap: 20px; margin: 30px 0; }
        .btn-blue-solid { background: var(--brand-blue); color: var(--brand-yellow); padding: 14px 30px; border-radius: 8px; text-decoration: none; font-weight: 700; }
        .btn-blue-outline { border: 2px solid var(--brand-blue); color: var(--brand-blue); padding: 12px 30px; border-radius: 8px; text-decoration: none; font-weight: 700; }
        .cta-footer { font-size: 13px; opacity: 0.9; display: flex; justify-content: center; gap: 30px; color: var(--brand-blue); font-weight: 600; }

        footer { background: transparent; padding: 20px 0 0; }
        .footer-main { display: grid; grid-template-columns: 2fr 1fr 1fr 1.5fr; gap: 40px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 50px; max-width: 1200px; margin: 0 auto; }
        .footer-main h4 { color: var(--brand-yellow); margin-bottom: 20px; text-transform: uppercase; font-weight: 700; }
        .footer-main p, .footer-main a { color: #cbd5e1; text-decoration: none; font-size: 14px; display: block; margin-bottom: 10px; }
        .socials { display: flex; gap: 15px; margin-top: 20px; }
        .socials a { background: rgba(255,255,255,0.1); width: 35px; height: 35px; border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: 0.3s; color: white; }
        .socials a:hover { background: var(--brand-yellow); color: var(--brand-blue); }

        .footer-bottom { padding-top: 30px; text-align: center; font-size: 12px; color: #94a3b8; }
        .footer-bottom a { color: #cbd5e1; text-decoration: none; margin: 0 10px; font-weight: 600; }

        @media (max-width: 900px) {
            .hero-content h1 { font-size: 36px; }
            .stats-bar, .service-grid, .footer-main { grid-template-columns: 1fr; }
            .search { display: none; }
            .cta-box { padding: 40px 20px; margin-top: -180px; }
            .cta-footer { flex-direction: column; gap: 10px; }
        }
    </style>
</head>
<body>

    <div class="promo-bar">| CALL US: (046) 887 4714</div>

    <header>
        <div class="nav-top">
            <a href="index.php" class="logo">
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
                <!-- ETO YUNG EKSATONG LOGIN/REGISTER BUTTON MULA SA PETSERVICES.PHP -->
                <a href="login.php" class="cart-btn">Login / Register</a>
            </div>
        </div>
        
        <nav class="categories">
            <ul>
                <li><a href="petservices.php"><i class="fa-solid fa-paw"></i> PET SERVICES</a></li>
                <li><a href="grooming.php"><i class="fa-solid fa-scissors"></i> GROOMING</a></li>
                <li><a href="vetclinic.php"><i class="fa-solid fa-stethoscope"></i> VET CLINIC</a></li>
                <li><a href="pethotel.php"><i class="fa-solid fa-hotel"></i> PET HOTEL</a></li>
                <li><a href="contactus.php"><i class="fa-solid fa-phone"></i> CONTACT</a></li>
            </ul>
        </nav>
    </header>

    <section class="slider-section">
        <div class="slider-track">
            <div class="slide slide-1 active">
                <div class="hero-content">
                    <h1>Exceptional Care for Your Best Friend</h1>
                    <p>Dasmariñas' most trusted pet destination for premium grooming, medical care, and luxury boarding.</p>
                    <a href="register.php" class="btn-join-hero">Get Started Today</a>
                </div>
            </div>
            <div class="slide slide-2">
                <div class="hero-content">
                    <h1>Expert Veterinary & Medical Services</h1>
                    <p>Our licensed professionals are dedicated to keeping your furry family members healthy and happy 24/7.</p>
                    <a href="login.php" class="btn-join-hero" style="background: var(--brand-yellow); color: var(--brand-blue);">Book an Appointment</a>
                </div>
            </div>
        </div>
        
        <div class="slider-dots">
            <span class="dot active" onclick="setSlide(0)"></span>
            <span class="dot" onclick="setSlide(1)"></span>
        </div>
    </section>

    <div class="stats-bar">
        <div class="stat-item"><i class="fa-solid fa-award"></i><strong><?php echo number_format($total_reviews); ?>+</strong><span>Happy Customers</span></div>
        <div class="stat-item"><i class="fa-solid fa-star"></i><strong><?php echo $avg_rating; ?>/5</strong><span>Average Rating</span></div>
        <div class="stat-item"><i class="fa-solid fa-clock"></i><strong>9am - 6pm</strong><span>Shop Service</span></div>
        <div class="stat-item"><i class="fa-solid fa-shield-check"></i><strong>5+</strong><span>Years in Service</span></div>
    </div>

    <div class="section-header">
        <span class="badge">Limited Time Offers</span>
        <h2>Special Promotions This Month</h2>
        <p style="color:#64748b;">Don't miss out on these amazing deals for your furry friends!</p>
    </div>

    <div class="promo-grid">
        <?php
        // Loop through the database promotions
        if (!empty($promos_list)) {
            foreach ($promos_list as $promo) {
                // Determine the class name based on theme_color (defaults to purple if empty)
                $theme_class = !empty($promo['theme_color']) ? htmlspecialchars($promo['theme_color']) : 'purple';
                
                echo '<div class="promo-card ' . $theme_class . '">';
                echo '<span class="tag">' . htmlspecialchars($promo['tag'] ?? 'PROMO') . '</span>';
                echo '<h3>' . htmlspecialchars($promo['title']) . '</h3>';
                echo '<p>' . htmlspecialchars($promo['description'] ?? '') . '</p>';
                echo '</div>';
            }
        } else {
            // Fallback content in case the database is completely empty
            echo '
            <div class="promo-card purple">
                <span class="tag">Vet Clinic Deal</span>
                <h3>Free Checkup</h3>
                <p>Get a complimentary wellness consultation when you book a complete vaccination package.</p>
            </div>
            <div class="promo-card teal">
                <span class="tag">Boarding Perk</span>
                <h3>Stay 5, Get 1</h3>
                <p>Book 5 nights at our Pet Hotel and get the 6th night FREE, plus a complimentary exit bath!</p>
            </div>
            <div class="promo-card red">
                <span class="tag">Birthday Special</span>
                <h3>50% OFF</h3>
                <p>Is it your pet\'s birth month? Bring their records and get half off their next grooming session!</p>
            </div>';
        }
        ?>
    </div>

    <div class="section-header">
        <h2>Our Premium Services</h2>
        <p style="color:#64748b;">Everything your pet needs, all under one roof</p>
    </div>

    <div class="service-grid">
        <div class="service-card"><i class="fa-solid fa-scissors"></i><h4>Pet Grooming</h4><a href="grooming.php">View Services →</a></div>
        <div class="service-card"><i class="fa-solid fa-hotel"></i><h4>Pet Hotel</h4><a href="pethotel.php">View Services →</a></div>
        <div class="service-card"><i class="fa-solid fa-stethoscope"></i><h4>Veterinary Care</h4><a href="vetclinic.php">View Services →</a></div>
        <div class="service-card"><i class="fa-solid fa-calendar-check"></i><h4>Easy Booking</h4><a href="petservices.php">Get Started →</a></div>
    </div>

    <section class="testimonials">
        <div class="section-header">
            <h2>What Our Customers Say</h2>
            <div class="stars" style="font-size:24px; margin-top:10px;">
                <?php
                $full_stars = floor($avg_rating);
                for($i=1; $i<=5; $i++) {
                    echo $i <= $full_stars ? '<i class="fa-solid fa-star"></i>' : '<i class="fa-regular fa-star"></i>';
                }
                ?>
            </div>
            <p style="color:#64748b;">
                Rated <?php echo $avg_rating; ?>/5 by <?php echo number_format($total_reviews); ?> <?php echo $parent_text; ?>
            </p>
        </div>

        <div class="testimonial-grid">
            <?php if ($reviews_result && mysqli_num_rows($reviews_result) > 0): ?>
                <?php while($row = mysqli_fetch_assoc($reviews_result)): ?>
                    <div class="t-card">
                        <div class="stars">
                            <?php
                            for($i=1; $i<=5; $i++) {
                                echo $i <= $row['rating'] ? '<i class="fa-solid fa-star"></i>' : '<i class="fa-regular fa-star"></i>';
                            }
                            ?>
                        </div>
                        <p class="t-text">"<?php echo htmlspecialchars($row['comment']); ?>"</p>
                        <div class="t-user">
                            <div class="t-avatar">
                                <?php echo strtoupper(substr($row['full_name'], 0, 1)); ?>
                            </div>
                            <div>
                                <strong><?php echo htmlspecialchars($row['full_name']); ?></strong><br>
                                <small><?php echo htmlspecialchars($row['service']); ?> Client</small>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p style="width: 100%; text-align: center; color: #64748b;">No reviews yet. Be the first to leave one!</p>
            <?php endif; ?>
        </div>
        <a href="contactus.php" style="color:var(--brand-blue); font-weight:700; text-decoration:none; display:inline-block;">Read More Reviews <i class="fa-solid fa-arrow-right"></i></a>
    </section>

    <footer class="cta-section">
        <div class="cta-box">
            <h2>Ready to Give Your Pet the Best Care?</h2>
            <p>Join thousands of satisfied customers who trust Boogie's Pet Care for all their pet needs</p>
            <div class="btn-group">
                <a href="register.php" class="btn-blue-solid">Create Free Account</a>
                <a href="petservices.php" class="btn-blue-outline">Browse Services</a>
            </div>
            <div class="cta-footer">
                <span><i class="fa-solid fa-check"></i> No credit card required</span>
                <span><i class="fa-solid fa-check"></i> Reliable Daily Support</span>
                <span><i class="fa-solid fa-check"></i> SMS notifications</span>
            </div>
        </div>

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
                <p><i class="fa-solid fa-location-dot"></i> 110 Don Placido Campos Ave San Agustin 3, Dasmariñas, Philippines</p>
            </div>
        </div>

        <div class="footer-bottom">
            <p>© 2026 Boogie's Pet Care & Services - Dasmariñas Branch. All rights reserved.</p>
            <div style="margin-top:10px;">
                <a href="staff/stafflogin.php"><i class="fa-solid fa-briefcase"></i> Personal Portal</a> |
                
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
        
        slides[index].classList.add('active');
        dots[index].classList.add('active');
    }

    setInterval(() => {
        currentSlideIndex = (currentSlideIndex + 1) % slides.length;
        setSlide(currentSlideIndex);
    }, 6000);
</script>

</body>
</html>