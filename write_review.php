<?php
session_start();
include 'db_connect.php';

// Access Control
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$appointment_id = isset($_GET['appointment_id']) ? intval($_GET['appointment_id']) : 0;

// Security Check: Ensure this appointment belongs to the user and is COMPLETED
$check_query = "SELECT id, service FROM appointments WHERE id = ? AND user_id = ? AND booking_status = 'Completed'";
$stmt = $conn->prepare($check_query);
$stmt->bind_param("ii", $appointment_id, $user_id);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();

if (!$booking) {
    die("Invalid request or appointment not eligible for review.");
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rating = intval($_POST['rating']);
    $comment = mysqli_real_escape_string($conn, $_POST['comment']);

    $insert_query = "INSERT INTO reviews (user_id, appointment_id, rating, comment, review_date) VALUES (?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($insert_query);
    $stmt->bind_param("iiis", $user_id, $appointment_id, $rating, $comment);

    if ($stmt->execute()) {
        header("Location: bookings.php?msg=review_success");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Write a Review | Boogie's Pet Care</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --brand-blue: #001f3f; --brand-yellow: #ffcc00; --bg-gray: #f0f2f5; }
        body { font-family: 'Poppins', sans-serif; background: var(--bg-gray); display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .review-card { background: white; padding: 40px; border-radius: 15px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); width: 100%; max-width: 500px; }
        h2 { color: var(--brand-blue); margin-top: 0; }
        .service-tag { background: #eef6ff; color: var(--brand-blue); padding: 5px 15px; border-radius: 20px; font-size: 14px; font-weight: 600; display: inline-block; margin-bottom: 20px; }
        .star-rating { direction: rtl; display: inline-block; padding: 20px 0; }
        .star-rating input { display: none; }
        .star-rating label { color: #ddd; font-size: 30px; padding: 0 5px; cursor: pointer; transition: 0.2s; }
        .star-rating label:hover, .star-rating label:hover ~ label, .star-rating input:checked ~ label { color: var(--brand-yellow); }
        textarea { width: 100%; padding: 15px; border: 1px solid #ddd; border-radius: 10px; resize: none; font-family: inherit; margin-bottom: 20px; box-sizing: border-box; }
        .btn-submit { background: var(--brand-blue); color: var(--brand-yellow); border: none; width: 100%; padding: 15px; border-radius: 10px; font-weight: 700; cursor: pointer; font-size: 16px; }
        .btn-submit:hover { background: #002d5b; }
    </style>
</head>
<body>

<div class="review-card">
    <h2>Share your experience</h2>
    <div class="service-tag">Service: <?php echo htmlspecialchars($booking['service']); ?></div>
    
    <form method="POST">
        <p style="margin-bottom: 5px; font-weight: 600;">How would you rate our service?</p>
        <div class="star-rating">
            <input type="radio" id="5-stars" name="rating" value="5" required /><label for="5-stars" class="fas fa-star"></label>
            <input type="radio" id="4-stars" name="rating" value="4" /><label for="4-stars" class="fas fa-star"></label>
            <input type="radio" id="3-stars" name="rating" value="3" /><label for="3-stars" class="fas fa-star"></label>
            <input type="radio" id="2-stars" name="rating" value="2" /><label for="2-stars" class="fas fa-star"></label>
            <input type="radio" id="1-star" name="rating" value="1" /><label for="1-star" class="fas fa-star"></label>
        </div>

        <p style="margin-bottom: 5px; font-weight: 600;">Your Comments</p>
        <textarea name="comment" rows="4" placeholder="Tell us what you liked or how we can improve..." required></textarea>

        <button type="submit" class="btn-submit">Submit Review</button>
        <a href="bookings.php" style="display: block; text-align: center; margin-top: 15px; color: #666; text-decoration: none; font-size: 14px;">Cancel</a>
    </form>
</div>

</body>
</html>