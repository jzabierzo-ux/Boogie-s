<?php
session_start();
require_once 'db_supabase.php';

// Get appointment and user IDs from POST
$appointment_id = $_POST['appointment_id'] ?? null;
$user_id = $_POST['user_id'] ?? null;

if (empty($appointment_id) || empty($user_id)) {
    http_response_code(400);
    echo "Missing appointment_id or user_id.";
    exit();
}

try {
    // Start transaction so the appointment/user updates stay consistent
    $pdo->beginTransaction();

    // 1. Update appointment status to 'No-Show'
    // Uses the appointment primary key column: id
    $update_appt = $pdo->prepare("
        UPDATE appointments
        SET booking_status = :status
        WHERE id = :appointment_id
    ");

    $update_appt->execute([
        ':status' => 'No-Show',
        ':appointment_id' => $appointment_id
    ]);

    // 2. Add 1 strike to the user's account
    // COALESCE prevents NULL + 1 from staying NULL
    $update_user = $pdo->prepare("
        UPDATE users
        SET no_show_count = COALESCE(no_show_count, 0) + 1
        WHERE id = :user_id
    ");

    $update_user->execute([
        ':user_id' => $user_id
    ]);

    // 3. Check the user's current strike count
    $check_strikes = $pdo->prepare("
        SELECT no_show_count
        FROM users
        WHERE id = :user_id
        LIMIT 1
    ");

    $check_strikes->execute([
        ':user_id' => $user_id
    ]);

    $row = $check_strikes->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new RuntimeException("User account not found.");
    }

    // 4. Restrict booking when strikes reach 3
    if ((int)$row['no_show_count'] >= 3) {
        $restrict_user = $pdo->prepare("
            UPDATE users
            SET is_restricted = TRUE
            WHERE id = :user_id
        ");

        $restrict_user->execute([
            ':user_id' => $user_id
        ]);
    }

    $pdo->commit();

    echo "Marked as No-Show successfully.";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("Mark No-Show failed: " . $e->getMessage());

    http_response_code(500);
    echo "Failed to mark appointment as No-Show.";
}
?>