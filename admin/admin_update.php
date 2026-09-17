<?php
// Siguraduhing may database connection ka na dito (e.g., include 'db_connect.php';)

$appointment_id = $_POST['appointment_id'];
$user_id = $_POST['user_id']; // Kunin din ang user_id ng nag-book

// 1. I-update ang status ng appointment to 'No-Show'
$update_appt = "UPDATE appointments SET status = 'No-Show' WHERE appointment_id = '$appointment_id'";
mysqli_query($conn, $update_appt);

// 2. Dagdagan ng 1 strike ang user account
$update_user = "UPDATE users SET no_show_count = no_show_count + 1 WHERE user_id = '$user_id'";
mysqli_query($conn, $update_user);

// 3. I-check kung umabot na sa 3 strikes, i-restrict kung oo
$check_strikes = "SELECT no_show_count FROM users WHERE user_id = '$user_id'";
$result = mysqli_query($conn, $check_strikes);
$row = mysqli_fetch_assoc($result);

if ($row['no_show_count'] >= 3) {
    // I-lock ang account sa pag-book
    $restrict_user = "UPDATE users SET is_restricted = 1 WHERE user_id = '$user_id'";
    mysqli_query($conn, $restrict_user);
}

echo "Marked as No-Show successfully.";
?>