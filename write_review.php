<?php
session_start();
include 'db_supabase.php';

// Access Control
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$appointment_id = isset($_GET['appointment_id']) ? intval($_GET['appointment_id']) : 0;

// Security Check: Ensure this appointment belongs to the user and is COMPLETED
$check_query = "
    SELECT id, service
    FROM appointments
    WHERE id = :appointment_id
      AND user_id = :user_id
      AND booking_status = 'Completed'
";

try {
    $stmt = $pdo->prepare($check_query);
    $stmt->execute([
        ':appointment_id' => $appointment_id,
        ':user_id' => $user_id
    ]);

    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Unable to verify the appointment at this time.");
}

if (!$booking) {
    die("Invalid request or appointment not eligible for review.");
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rating = intval($_POST['rating'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');

    $insert_query = "
        INSERT INTO reviews
            (user_id, appointment_id, rating, comment, review_date)
        VALUES
            (:user_id, :appointment_id, :rating, :comment, NOW())
    ";

    try {
        $stmt = $pdo->prepare($insert_query);
        $stmt->execute([
            ':user_id' => $user_id,
            ':appointment_id' => $appointment_id,
            ':rating' => $rating,
            ':comment' => $comment
        ]);

        header("Location: bookings.php?msg=review_success");
        exit();
    } catch (PDOException $e) {
        die("Unable to submit the review at this time.");
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Write a Review | Boogie's Pet Care</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --brand-blue: #001f3f;
            --brand-blue-2: #002d5b;
            --brand-yellow: #ffcc00;
            --brand-yellow-soft: #fff7d6;
            --bg-gray: #f0f2f5;
            --text: #17324d;
            --muted: #6b7c8f;
            --line: #dfe5eb;
            --white: #ffffff;
            --shadow: 0 14px 35px rgba(0, 31, 63, .10);
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background:
                radial-gradient(circle at top right, rgba(255,204,0,.12), transparent 28%),
                var(--bg-gray);
            color: var(--text);
            min-height: 100vh;
            margin: 0;
            padding: 24px;
            display: flex;
            justify-content: center;
            align-items: center;
            line-height: 1.6;
        }

        .review-card {
            background: var(--white);
            padding: 38px;
            border-radius: 20px;
            box-shadow: var(--shadow);
            width: 100%;
            max-width: 540px;
            border: 1px solid rgba(0,31,63,.07);
        }

        .review-kicker {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 12px;
            border-radius: 999px;
            background: var(--brand-yellow-soft);
            border: 1px solid #ffe594;
            color: #8c6800;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .7px;
            font-weight: 800;
            margin-bottom: 12px;
        }

        h2 {
            color: var(--brand-blue);
            margin: 0 0 8px;
            font-size: 28px;
            line-height: 1.2;
            font-weight: 800;
        }

        .subtitle {
            color: var(--muted);
            font-size: 12px;
            margin: 0 0 20px;
        }

        .service-tag {
            background: #eef6ff;
            color: var(--brand-blue);
            padding: 8px 14px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-bottom: 22px;
            max-width: 100%;
            overflow-wrap: anywhere;
        }

        .form-label {
            margin: 0 0 7px;
            font-weight: 700;
            color: var(--brand-blue);
            font-size: 12px;
        }

        .star-rating-wrap {
            display: flex;
            justify-content: center;
            margin: 4px 0 22px;
            padding: 12px 0 6px;
            overflow-x: auto;
        }

        .star-rating {
            direction: rtl;
            display: inline-flex;
            align-items: center;
            white-space: nowrap;
        }

        .star-rating input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .star-rating label {
            color: #d9dee4;
            font-size: 34px;
            padding: 0 4px;
            cursor: pointer;
            transition: color .2s ease, transform .2s ease;
            line-height: 1;
            -webkit-tap-highlight-color: transparent;
        }

        .star-rating label:hover,
        .star-rating label:hover ~ label,
        .star-rating input:checked ~ label {
            color: var(--brand-yellow);
        }

        .star-rating label:hover {
            transform: translateY(-2px);
        }

        textarea {
            width: 100%;
            min-height: 130px;
            padding: 13px 14px;
            border: 1px solid #d7dfe7;
            border-radius: 12px;
            resize: vertical;
            font-family: inherit;
            font-size: 13px;
            line-height: 1.6;
            color: var(--text);
            background: #fbfcfe;
            outline: none;
            transition: .2s;
            margin-bottom: 18px;
        }

        textarea:focus {
            border-color: #9bb6cc;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(0,31,63,.05);
        }

        .textarea-help {
            color: #93a0ad;
            font-size: 10px;
            margin: -10px 0 18px;
        }

        .btn-submit {
            background: var(--brand-blue);
            color: var(--brand-yellow);
            border: none;
            width: 100%;
            min-height: 48px;
            padding: 12px 16px;
            border-radius: 11px;
            font-family: inherit;
            font-weight: 800;
            cursor: pointer;
            font-size: 14px;
            transition: .2s;
        }

        .btn-submit:hover {
            background: var(--brand-blue-2);
        }

        .btn-submit:active {
            transform: translateY(1px);
        }

        .cancel-link {
            display: block;
            text-align: center;
            margin-top: 14px;
            color: #6d7782;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            padding: 7px;
        }

        .cancel-link:hover {
            color: var(--brand-blue);
        }

        .review-footer-note {
            margin-top: 22px;
            padding-top: 16px;
            border-top: 1px solid #edf1f5;
            color: #8a98a7;
            font-size: 9px;
            text-align: center;
            line-height: 1.6;
        }

        @media (max-width: 600px) {
            body {
                padding: 16px;
                align-items: flex-start;
            }

            .review-card {
                margin: auto 0;
                padding: 27px 20px;
                border-radius: 17px;
            }

            h2 {
                font-size: 24px;
            }

            .subtitle {
                font-size: 11px;
            }

            .service-tag {
                font-size: 11px;
                line-height: 1.5;
            }

            .star-rating-wrap {
                justify-content: center;
                margin-bottom: 18px;
            }

            .star-rating label {
                font-size: 29px;
                padding: 0 3px;
            }

            textarea {
                min-height: 120px;
                font-size: 12px;
            }

            .btn-submit {
                min-height: 46px;
                font-size: 13px;
            }
        }

        @media (max-width: 380px) {
            body {
                padding: 10px;
            }

            .review-card {
                padding: 22px 15px;
            }

            h2 {
                font-size: 21px;
            }

            .review-kicker,
            .service-tag {
                font-size: 9px;
            }

            .star-rating label {
                font-size: 25px;
                padding: 0 2px;
            }
        }
    </style>
</head>
<body>

<div class="review-card">
    <div class="review-kicker">
        <i class="fa-solid fa-star"></i>
        Customer Review
    </div>

    <h2>Share your experience</h2>
    <p class="subtitle">Tell us about your recent visit to Boogie's Pet Care.</p>

    <div class="service-tag">
        <i class="fa-solid fa-paw"></i>
        Service: <?php echo htmlspecialchars($booking['service']); ?>
    </div>

    <form method="POST">
        <p class="form-label">How would you rate our service?</p>

        <div class="star-rating-wrap">
            <div class="star-rating">
                <input type="radio" id="5-stars" name="rating" value="5" required>
                <label for="5-stars" class="fas fa-star" aria-label="5 stars"></label>

                <input type="radio" id="4-stars" name="rating" value="4">
                <label for="4-stars" class="fas fa-star" aria-label="4 stars"></label>

                <input type="radio" id="3-stars" name="rating" value="3">
                <label for="3-stars" class="fas fa-star" aria-label="3 stars"></label>

                <input type="radio" id="2-stars" name="rating" value="2">
                <label for="2-stars" class="fas fa-star" aria-label="2 stars"></label>

                <input type="radio" id="1-star" name="rating" value="1">
                <label for="1-star" class="fas fa-star" aria-label="1 star"></label>
            </div>
        </div>

        <p class="form-label">Your Comments</p>

        <textarea
            name="comment"
            rows="4"
            placeholder="Tell us what you liked or how we can improve..."
            required
        ></textarea>

        <div class="textarea-help">Your feedback helps us improve our pet care services.</div>

        <button type="submit" class="btn-submit">
            <i class="fa-solid fa-paper-plane"></i>
            Submit Review
        </button>

        <a href="bookings.php" class="cancel-link">Cancel</a>
    </form>

    <div class="review-footer-note">
        Your review is submitted for this completed appointment only.
    </div>
</div>

</body>
</html>
