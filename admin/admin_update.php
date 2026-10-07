<?php

session_start();
require_once 'db_supabase.php';

/*
|--------------------------------------------------------------------------
| AUTHENTICATION / AUTHORIZATION
|--------------------------------------------------------------------------
| Only authorized staff can mark an appointment as No-Show.
*/
$current_role = strtolower(trim((string)($_SESSION['role'] ?? '')));

$allowed_roles = ['admin', 'manager', 'vet'];

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !in_array($current_role, $allowed_roles, true)
) {
    http_response_code(403);
    echo "Unauthorized access.";
    exit();
}

/*
|--------------------------------------------------------------------------
| POST ONLY
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo "Method not allowed.";
    exit();
}

/*
|--------------------------------------------------------------------------
| GET INPUT
|--------------------------------------------------------------------------
*/
$appointment_id = filter_input(INPUT_POST, 'appointment_id', FILTER_VALIDATE_INT);
$user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);

if (!$appointment_id || !$user_id) {
    http_response_code(400);
    echo "Invalid appointment_id or user_id.";
    exit();
}

try {

    /*
    |--------------------------------------------------------------------------
    | START TRANSACTION
    |--------------------------------------------------------------------------
    */
    $pdo->beginTransaction();

    /*
    |--------------------------------------------------------------------------
    | 1. VERIFY APPOINTMENT
    |--------------------------------------------------------------------------
    | Make sure the appointment exists and belongs to the given user.
    */
    $check_appointment = $pdo->prepare("
        SELECT id, user_id, booking_status
        FROM appointments
        WHERE id = :appointment_id
        LIMIT 1
        FOR UPDATE
    ");

    $check_appointment->execute([
        ':appointment_id' => $appointment_id
    ]);

    $appointment = $check_appointment->fetch(PDO::FETCH_ASSOC);

    if (!$appointment) {
        throw new RuntimeException("Appointment not found.");
    }

    /*
    |--------------------------------------------------------------------------
    | 2. VERIFY APPOINTMENT OWNER
    |--------------------------------------------------------------------------
    */
    if ((int)$appointment['user_id'] !== $user_id) {
        throw new RuntimeException("Appointment does not belong to this user.");
    }

    /*
    |--------------------------------------------------------------------------
    | 3. PREVENT DUPLICATE NO-SHOW
    |--------------------------------------------------------------------------
    */
    if (strtolower((string)$appointment['booking_status']) === 'no-show') {
        throw new RuntimeException("This appointment is already marked as No-Show.");
    }

    /*
    |--------------------------------------------------------------------------
    | 4. UPDATE APPOINTMENT STATUS
    |--------------------------------------------------------------------------
    */
    $update_appt = $pdo->prepare("
        UPDATE appointments
        SET booking_status = :status
        WHERE id = :appointment_id
    ");

    $update_appt->execute([
        ':status' => 'No-Show',
        ':appointment_id' => $appointment_id
    ]);

    if ($update_appt->rowCount() === 0) {
        throw new RuntimeException("Appointment status was not updated.");
    }

    /*
    |--------------------------------------------------------------------------
    | 5. LOCK AND GET CURRENT USER STRIKE COUNT
    |--------------------------------------------------------------------------
    */
    $check_user = $pdo->prepare("
        SELECT id, no_show_count, is_restricted
        FROM users
        WHERE id = :user_id
        LIMIT 1
        FOR UPDATE
    ");

    $check_user->execute([
        ':user_id' => $user_id
    ]);

    $user = $check_user->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new RuntimeException("User account not found.");
    }

    /*
    |--------------------------------------------------------------------------
    | 6. ADD ONE STRIKE
    |--------------------------------------------------------------------------
    */
    $new_strike_count = (int)($user['no_show_count'] ?? 0) + 1;

    /*
    |--------------------------------------------------------------------------
    | 7. RESTRICT AT 3 STRIKES
    |--------------------------------------------------------------------------
    */
    $is_restricted = $new_strike_count >= 3;

    $update_user = $pdo->prepare("
        UPDATE users
        SET
            no_show_count = :no_show_count,
            is_restricted = :is_restricted
        WHERE id = :user_id
    ");

    $update_user->execute([
        ':no_show_count' => $new_strike_count,
        ':is_restricted' => $is_restricted,
        ':user_id' => $user_id
    ]);

    /*
    |--------------------------------------------------------------------------
    | 8. COMMIT
    |--------------------------------------------------------------------------
    */
    $pdo->commit();

    /*
    |--------------------------------------------------------------------------
    | RESPONSE
    |--------------------------------------------------------------------------
    */
    if ($is_restricted) {
        echo "Marked as No-Show successfully. User has reached 3 strikes and is now restricted.";
    } else {
        echo "Marked as No-Show successfully. Strike count: {$new_strike_count}.";
    }

} catch (Throwable $e) {

    /*
    |--------------------------------------------------------------------------
    | ROLLBACK
    |--------------------------------------------------------------------------
    */
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        "Mark No-Show failed: " . $e->getMessage()
    );

    http_response_code(500);
    echo "Failed to mark appointment as No-Show.";
}

?>