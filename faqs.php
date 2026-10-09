<?php

require_once __DIR__ . '/shared_session_bootstrap.php';

require_once __DIR__ . '/db_supabase.php';



$is_logged_in = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;

$full_name = (string)($_SESSION['user_name'] ?? ($_SESSION['full_name'] ?? 'User'));

$user_id = (int)($_SESSION['user_id'] ?? 0);



$profile_image = null;

$unread_count = 0;

$notifications = [];

function isFaqNotificationRead($value): bool
{
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 't', 'yes'], true);
}



if ($is_logged_in && $user_id > 0) {

    try {

        $stmt = $pdo->prepare("

            SELECT profile_image

            FROM users

            WHERE id = :user_id

            LIMIT 1

        ");

        $stmt->execute([':user_id' => $user_id]);

        $user_data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $profile_image = $user_data['profile_image'] ?? null;

    } catch (PDOException $e) {

        error_log("FAQ profile query failed: " . $e->getMessage());

    }



    try {

        $stmt = $pdo->prepare("

            SELECT COUNT(*) 

            FROM notifications

            WHERE user_id = :user_id

              AND LOWER(CAST(is_read AS TEXT)) IN ('0', 'false', 'f')

        ");

        $stmt->execute([':user_id' => $user_id]);

        $unread_count = (int)$stmt->fetchColumn();

    } catch (PDOException $e) {

        error_log("FAQ notification count failed: " . $e->getMessage());

    }



    try {

        $stmt = $pdo->prepare("

            SELECT id, message, created_at, is_read

            FROM notifications

            WHERE user_id = :user_id

            ORDER BY created_at DESC

            LIMIT 5

        ");

        $stmt->execute([':user_id' => $user_id]);

        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {

        error_log("FAQ notifications query failed: " . $e->getMessage());

    }

}

?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>FAQs | Boogie's Pet Care Services</title>

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>

:root{

    --brand-blue:#001f3f;

    --brand-blue-2:#0b3b66;

    --brand-yellow:#ffcc00;

    --brand-yellow-soft:#fff7d6;

    --page-bg:#f5f8fb;

    --white:#fff;

    --text:#17324d;

    --muted:#6b7c8f;

    --line:#e3eaf1;

    --shadow:0 10px 30px rgba(0,31,63,.06);

    --shadow-lg:0 18px 42px rgba(0,31,63,.10);

}

*{

    box-sizing:border-box;

    margin:0;

    padding:0;

    font-family:'Poppins',sans-serif;

}

html{scroll-behavior:smooth}

body{

    min-height:100vh;

    background:var(--page-bg);

    color:var(--text);

    line-height:1.6;

    overflow-x:hidden;

}

a{color:inherit}

button{font:inherit}



/* HEADER */

.promo-bar{

    background:var(--brand-blue);

    color:#fff;

    text-align:center;

    padding:7px 16px;

    font-size:12px;

    font-weight:700;

    border-top:3px solid var(--brand-yellow);

}

.promo-bar i{color:var(--brand-yellow);margin-right:7px}

header{

    position:sticky;

    top:0;

    z-index:1000;

    background:rgba(255,255,255,.98);

    border-bottom:1px solid var(--line);

    box-shadow:0 4px 18px rgba(0,0,0,.04);

}

.nav-top{

    width:min(1320px,92%);

    min-height:78px;

    margin:0 auto;

    padding:13px 0;

    display:flex;

    align-items:center;

    justify-content:space-between;

    gap:24px;

}

.logo{

    display:flex;

    align-items:center;

    gap:11px;

    text-decoration:none;

    flex:0 0 auto;

}

.nav-logo-img{

    width:50px;

    height:50px;

    object-fit:contain;

    border-radius:10px;

}

.logo-text{

    display:flex;

    flex-direction:column;

    line-height:1.05;

}

.logo-text b{

    color:var(--brand-blue);

    font-size:20px;

    font-weight:800;

}

.logo-text span{

    margin-top:3px;

    color:#8c9aae;

    font-size:9px;

    font-weight:700;

    letter-spacing:1.2px;

}

.user-controls{

    display:flex;

    align-items:center;

    gap:12px;

}

.notification-wrapper,.profile-wrapper{position:relative}

.notification-bell{

    position:relative;

    width:42px;

    height:42px;

    border:1px solid var(--line);

    border-radius:12px;

    background:#fff;

    color:var(--brand-blue);

    display:flex;

    align-items:center;

    justify-content:center;

    cursor:pointer;

}

.notification-badge{

    position:absolute;

    top:-5px;

    right:-5px;

    min-width:18px;

    height:18px;

    padding:0 5px;

    border:2px solid #fff;

    border-radius:999px;

    background:#dc3b45;

    color:#fff;

    display:inline-flex;

    align-items:center;

    justify-content:center;

    font-size:9px;

    font-weight:800;

}

.profile-trigger{

    min-height:42px;

    padding:4px 9px 4px 5px;

    border:1px solid var(--line);

    border-radius:12px;

    background:#fff;

    color:var(--text);

    display:flex;

    align-items:center;

    gap:9px;

    cursor:pointer;

    font-size:13px;

    font-weight:700;

}

.profile-avatar{

    width:34px;

    height:34px;

    object-fit:cover;

    border-radius:50%;

    border:2px solid var(--brand-blue);

}

.dropdown-menu{

    position:absolute;

    top:calc(100% + 10px);

    right:0;

    width:270px;

    background:#fff;

    border:1px solid var(--line);

    border-radius:14px;

    box-shadow:var(--shadow-lg);

    display:none;

    flex-direction:column;

    overflow:hidden;

    z-index:1100;

}

.dropdown-menu.active{display:flex}

.dropdown-header{

    padding:14px 16px;

    background:#f8fafc;

    border-bottom:1px solid var(--line);

    color:var(--muted);

    font-size:11px;

    font-weight:800;

    text-transform:uppercase;

    letter-spacing:.7px;

}

.dropdown-item{

    display:block;

    padding:12px 16px;

    border-bottom:1px solid #eef2f5;

    text-decoration:none;

    color:var(--text);

    font-size:12px;

}

.dropdown-item.unread{background:#eef6ff;font-weight:700}

.dropdown-item:last-child{border-bottom:0}

.view-all-link{text-align:center;font-weight:800;color:var(--brand-blue)}



/* PAGE */

main{

    width:min(1040px,92%);

    margin:0 auto;

    padding:42px 0 76px;

}

.back-link{

    display:inline-flex;

    align-items:center;

    gap:7px;

    color:#748396;

    text-decoration:none;

    font-size:11px;

    font-weight:700;

    margin-bottom:14px;

}

.back-link:hover{color:var(--brand-blue)}

.page-kicker{

    display:inline-flex;

    align-items:center;

    gap:7px;

    padding:7px 12px;

    border-radius:999px;

    background:var(--brand-yellow-soft);

    border:1px solid #ffe594;

    color:#8c6800;

    font-size:10px;

    text-transform:uppercase;

    letter-spacing:.7px;

    font-weight:800;

    margin-bottom:10px;

}

.page-heading h1{

    color:var(--brand-blue);

    font-size:36px;

    line-height:1.18;

    font-weight:800;

}

.page-heading p{

    color:var(--muted);

    font-size:14px;

    margin-top:7px;

}

.welcome-line{

    color:#6b7c8f;

    font-size:12px;

    margin-top:9px;

}

.faq-list{

    margin-top:30px;

    display:grid;

    gap:10px;

}

.faq-item{

    background:#fff;

    border:1px solid var(--line);

    border-radius:15px;

    box-shadow:var(--shadow);

    overflow:hidden;

}

.faq-question{

    width:100%;

    border:0;

    background:#fff;

    color:var(--brand-blue);

    padding:19px 20px;

    display:flex;

    align-items:center;

    justify-content:space-between;

    gap:15px;

    text-align:left;

    cursor:pointer;

    font-size:14px;

    font-weight:800;

}

.faq-question i{

    color:#8b99a9;

    transition:transform .2s ease;

    flex:0 0 auto;

}

.faq-answer{

    max-height:0;

    overflow:hidden;

    transition:max-height .25s ease;

}

.faq-answer-inner{

    padding:0 20px 19px;

    color:#52677c;

    font-size:13px;

    line-height:1.8;

}

.faq-item.active .faq-question i{transform:rotate(180deg)}

.faq-item.active .faq-answer{max-height:300px}

.contact-note{

    margin-top:24px;

    padding:15px 16px;

    background:#eff7ff;

    border:1px solid #dbeaf7;

    border-left:4px solid var(--brand-blue);

    border-radius:12px;

    color:#49667f;

    font-size:11px;

    line-height:1.75;

}



/* FOOTER */

footer{

    width:100%;

    background:var(--brand-blue);

    color:#fff;

    border-top:4px solid var(--brand-yellow);

    padding:62px 28px 30px;

}

.footer-main{

    width:min(1180px,100%);

    margin:0 auto;

    display:grid;

    grid-template-columns:2fr 1fr 1fr 1.5fr;

    gap:42px;

    padding-bottom:40px;

    border-bottom:1px solid rgba(255,255,255,.12);

}

.footer-main h4{

    color:var(--brand-yellow);

    margin-bottom:15px;

    font-size:12px;

    font-weight:800;

    text-transform:uppercase;

    letter-spacing:.5px;

}

.footer-main p,.footer-main a{

    display:block;

    color:#cbd5e1;

    text-decoration:none;

    font-size:12px;

    line-height:1.7;

    margin-bottom:8px;

}

.socials{

    display:flex;

    gap:10px;

    margin-top:16px;

}

.socials a{

    width:36px;

    height:36px;

    margin:0;

    border-radius:50%;

    background:rgba(255,255,255,.09);

    display:flex;

    align-items:center;

    justify-content:center;

    color:#fff;

}

.socials a:hover{

    background:var(--brand-yellow);

    color:var(--brand-blue);

}

.footer-bottom{

    width:min(1180px,100%);

    margin:0 auto;

    padding-top:23px;

    text-align:center;

    color:#91a1b1;

    font-size:11px;

}



@media (max-width:980px){

    .footer-main{grid-template-columns:1fr 1fr}

}

@media (max-width:680px){

    .promo-bar{padding:7px 10px;font-size:10px}

    .nav-top{

        width:calc(100% - 24px);

        min-height:64px;

        padding:10px 0;

        gap:8px;

    }

    .logo{

        min-width:0;

        gap:8px;

        flex:1;

        max-width:calc(100% - 104px);

    }

    .nav-logo-img{width:42px;height:42px}

    .logo-text b{font-size:16px}

    .logo-text span{font-size:7px;letter-spacing:.8px;white-space:nowrap}

    .user-controls{flex:0 0 auto;gap:7px}

    .notification-bell{width:40px;height:40px;border-radius:10px}

    .profile-trigger{min-height:40px;padding:4px;gap:0;border-radius:10px}

    .profile-trigger span,.profile-trigger > i:last-child{display:none}

    .profile-avatar{width:32px;height:32px}

    .dropdown-menu{

        position:fixed;

        top:74px;

        left:12px;

        right:12px;

        width:auto !important;

        max-height:calc(100vh - 88px);

        overflow-y:auto;

    }

    main{

        width:calc(100% - 24px);

        padding:28px 0 48px;

    }

    .page-heading h1{font-size:28px}

    .page-heading p{font-size:12px}

    .welcome-line{font-size:11px}

    .faq-list{margin-top:22px;gap:9px}

    .faq-question{padding:17px 15px;font-size:13px}

    .faq-answer-inner{padding:0 15px 17px;font-size:12px;line-height:1.75}

    .contact-note{font-size:9px;padding:12px 13px}

    footer{padding:40px 14px 24px}

    .footer-main{grid-template-columns:1fr;gap:25px}

    .footer-main h4{font-size:11px}

    .footer-main p,.footer-main a{font-size:11px}

    .footer-bottom{font-size:9px;line-height:1.6}

}

@media (max-width:380px){

    .logo-text b{font-size:14px}

    .logo-text span{font-size:6px}

    .notification-bell{width:38px;height:38px}

    .profile-avatar{width:30px;height:30px}

    main{width:calc(100% - 18px);padding-top:22px}

    .page-heading h1{font-size:22px}

}

</style>

</head>

<body>



<div class="promo-bar">

    <i class="fa-solid fa-phone"></i>

    Need help? Call us at (046) 887 4714

</div>



<header>

    <div class="nav-top">

        <a href="<?php echo $is_logged_in ? 'dashboard.php' : 'index.php'; ?>" class="logo">

            <img src="bg.png" alt="Boogie's Pet Care logo" class="nav-logo-img">

            <div class="logo-text">

                <b>Boogie's</b>

                <span>PET CARE SERVICES</span>

            </div>

        </a>



        <?php if ($is_logged_in): ?>

        <div class="user-controls">

            <div class="notification-wrapper">

                <div class="notification-bell" onclick="toggleDropdown('notifDropdown')" aria-label="Notifications">

                    <i class="fa-solid fa-bell"></i>

                    <span id="notif-badge" class="notification-badge"

                          style="display:<?php echo $unread_count > 0 ? 'inline-flex' : 'none'; ?>;">

                        <?php echo $unread_count; ?>

                    </span>

                </div>



                <div class="dropdown-menu" id="notifDropdown">

                    <div class="dropdown-header">Notifications</div>

                    <?php if (!empty($notifications)): ?>

                        <?php foreach ($notifications as $notif): ?>

                            <a href="notifications.php"

                               class="dropdown-item <?php echo isFaqNotificationRead($notif['is_read'] ?? null) ? '' : 'unread'; ?>">

                                <?php echo htmlspecialchars((string)($notif['message'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?><br>

                                <small style="color:#888;font-size:10px;">

                                    <?php echo date('M d, Y h:i A', strtotime($notif['created_at'])); ?>

                                </small>

                            </a>

                        <?php endforeach; ?>

                        <a href="notifications.php" class="dropdown-item view-all-link">

                            View All Notifications

                        </a>

                    <?php else: ?>

                        <div class="dropdown-item" style="text-align:center;color:#888;">

                            No new notifications.

                        </div>

                    <?php endif; ?>

                </div>

            </div>



            <div class="profile-wrapper">

                <div class="profile-trigger" onclick="toggleDropdown('profileDropdown')">

                    <?php if (!empty($profile_image)): ?>

                        <img src="<?php echo htmlspecialchars((string)$profile_image, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" alt="Profile" class="profile-avatar">

                    <?php else: ?>

                        <i class="fa-solid fa-circle-user" style="font-size:20px;color:var(--brand-blue);"></i>

                    <?php endif; ?>

                    <span>Hi, <?php echo htmlspecialchars($full_name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>

                    <i class="fa-solid fa-chevron-down" style="font-size:10px;color:#91a0ae;"></i>

                </div>



                <div class="dropdown-menu" id="profileDropdown" style="width:210px;">

                    <a href="edit_profile.php" class="dropdown-item">

                        <i class="fa-solid fa-user"></i> My Profile

                    </a>

                    <a href="bookings.php" class="dropdown-item">

                        <i class="fa-solid fa-calendar-check"></i> My Bookings

                    </a>

                    <a href="petprofile.php" class="dropdown-item">

                        <i class="fa-solid fa-paw"></i> My Pets

                    </a>

                    <a href="logout.php" class="dropdown-item" style="color:#dc3545;border-top:1px solid #eaeaea;">

                        <i class="fa-solid fa-right-from-bracket"></i> Logout

                    </a>

                </div>

            </div>

        </div>

        <?php else: ?>

        <div class="user-controls">

            <a href="login.php" class="profile-trigger" style="padding:8px 14px;text-decoration:none;">

                Login

            </a>

        </div>

        <?php endif; ?>

    </div>

</header>



<main>

    <a href="<?php echo $is_logged_in ? 'dashboard.php' : 'index.php'; ?>" class="back-link">

        <i class="fa-solid fa-arrow-left"></i>

        Back to <?php echo $is_logged_in ? 'Dashboard' : 'Home'; ?>

    </a>



    <div class="page-kicker">

        <i class="fa-solid fa-circle-question"></i>

        Frequently Asked Questions

    </div>



    <section class="page-heading">

        <h1>How can we help?</h1>

        <p>Find answers to common questions about Boogie's Pet Care Services.</p>

        <?php if ($is_logged_in): ?>

            <div class="welcome-line">

                Hello, <strong><?php echo htmlspecialchars($full_name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></strong>. Here are some quick answers for you.

            </div>

        <?php endif; ?>

    </section>



    <div class="faq-list">

        <article class="faq-item">

            <button type="button" class="faq-question">

                <span>How do I book an appointment?</span>

                <i class="fa-solid fa-chevron-down"></i>

            </button>

            <div class="faq-answer">

                <div class="faq-answer-inner">

                    Log in to your account, open the Online Booking page, choose your pet, service, preferred date and time, then complete the required GCash payment and upload the payment proof and reference number.

                </div>

            </div>

        </article>



        <article class="faq-item">

            <button type="button" class="faq-question">

                <span>What payment method do you accept?</span>

                <i class="fa-solid fa-chevron-down"></i>

            </button>

            <div class="faq-answer">

                <div class="faq-answer-inner">

                    Online appointments are secured through GCash. Customers submit the payment reference number and proof of payment for verification.

                </div>

            </div>

        </article>



        <article class="faq-item">

            <button type="button" class="faq-question">

                <span>Is my payment refundable?</span>

                <i class="fa-solid fa-chevron-down"></i>

            </button>

            <div class="faq-answer">

                <div class="faq-answer-inner">

                    Payments are generally non-refundable under the booking policy, including final no-show cases after the allowed rescheduling opportunity.

                </div>

            </div>

        </article>



        <article class="faq-item">

            <button type="button" class="faq-question">

                <span>What happens if I arrive late?</span>

                <i class="fa-solid fa-chevron-down"></i>

            </button>

            <div class="faq-answer">

                <div class="faq-answer-inner">

                    Customers are given a 30-minute grace period. Beyond that period, the appointment may be treated according to the system's late/no-show policy.

                </div>

            </div>

        </article>



        <article class="faq-item">

            <button type="button" class="faq-question">

                <span>Can I reschedule my appointment?</span>

                <i class="fa-solid fa-chevron-down"></i>

            </button>

            <div class="faq-answer">

                <div class="faq-answer-inner">

                    Yes. One reschedule may be allowed, subject to availability and the system's rescheduling rules. The new appointment must fall within the permitted rescheduling period.

                </div>

            </div>

        </article>



        <article class="faq-item">

            <button type="button" class="faq-question">

                <span>What happens if I miss my rescheduled appointment?</span>

                <i class="fa-solid fa-chevron-down"></i>

            </button>

            <div class="faq-answer">

                <div class="faq-answer-inner">

                    A second missed appointment is treated as a final no-show and the payment becomes non-refundable under the booking policy.

                </div>

            </div>

        </article>



        <article class="faq-item">

            <button type="button" class="faq-question">

                <span>Can I register more than one pet?</span>

                <i class="fa-solid fa-chevron-down"></i>

            </button>

            <div class="faq-answer">

                <div class="faq-answer-inner">

                    Yes. You can add and manage multiple pet profiles under the same customer account.

                </div>

            </div>

        </article>



        <article class="faq-item">

            <button type="button" class="faq-question">

                <span>Will I receive SMS notifications?</span>

                <i class="fa-solid fa-chevron-down"></i>

            </button>

            <div class="faq-answer">

                <div class="faq-answer-inner">

                    Yes. The system can send SMS updates for appointment confirmations, changes, cancellations, and other booking-related updates.

                </div>

            </div>

        </article>



        <article class="faq-item">

            <button type="button" class="faq-question">

                <span>Do you accept walk-in customers?</span>

                <i class="fa-solid fa-chevron-down"></i>

            </button>

            <div class="faq-answer">

                <div class="faq-answer-inner">

                    Walk-in customers may be accommodated depending on staff availability and the remaining daily service capacity.

                </div>

            </div>

        </article>



        <article class="faq-item">

            <button type="button" class="faq-question">

                <span>How can I contact Boogie's Pet Care Services?</span>

                <i class="fa-solid fa-chevron-down"></i>

            </button>

            <div class="faq-answer">

                <div class="faq-answer-inner">

                    You can use the Contact Us page or the contact information shown in the footer of the website.

                </div>

            </div>

        </article>

    </div>



    <div class="contact-note">

        Still have a question? Visit <a href="contactus.php" style="font-weight:800;color:var(--brand-blue);">Contact Us</a> and send us a message.

    </div>

</main>



<footer>

    <div class="footer-main">

        <div>

            <h4><i class="fa-solid fa-paw"></i> Boogie's Pet Care</h4>

            <p>Your trusted partner for all your pet care needs in Dasmariñas, Cavite.</p>

            <div class="socials">

                <a href="https://www.facebook.com/boogiespetsupplies" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a>

                <a href="https://mail.google.com/mail/?view=cm&to=boogiespetcareservices@gmail.com" aria-label="Email"><i class="fa-solid fa-envelope"></i></a>

            </div>

        </div>



        <div>

            <h4>Quick Links</h4>

            <a href="<?php echo $is_logged_in ? 'dashboard.php' : 'index.php'; ?>">Home</a>

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

        © 2026 Boogie's Pet Care & Services - Dasmariñas Branch. All rights reserved.

    </div>

</footer>



<script>

function toggleDropdown(id){

    document.querySelectorAll('.dropdown-menu').forEach(menu => {

        if(menu.id !== id) menu.classList.remove('active');

    });

    const target = document.getElementById(id);

    if(target) target.classList.toggle('active');

}



document.addEventListener('click', function(e){

    const notif = document.querySelector('.notification-wrapper');

    const profile = document.querySelector('.profile-wrapper');

    if(

        (notif && !notif.contains(e.target)) &&

        (profile && !profile.contains(e.target))

    ){

        document.querySelectorAll('.dropdown-menu').forEach(menu => menu.classList.remove('active'));

    }

});



document.querySelectorAll('.faq-question').forEach(button => {

    button.addEventListener('click', function(){

        const item = this.closest('.faq-item');

        const isActive = item.classList.contains('active');



        document.querySelectorAll('.faq-item').forEach(other => {

            other.classList.remove('active');

        });



        if(!isActive){

            item.classList.add('active');

        }

    });

});

</script>



</body>

</html>
