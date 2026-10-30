<?php
require_once __DIR__ . '/../shared_session_bootstrap.php';

/*
 * Legacy standalone No-Show handler.
 * Keeps the same one-time-reschedule rule and role-scoped service access
 * enforced by admin/managebooking.php.
 *
 * shared_session_bootstrap.php already loads db_supabase.php because the
 * shared session store uses the Supabase connection. Do not include it again.
 */

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

// Normalize legacy role names to the canonical booking-management roles.
$current_role_raw = strtolower(trim((string)($_SESSION['role'] ?? '')));
$role_aliases = [
    'administrator' => 'admin',
    'shop manager' => 'manager',
    'shop_manager' => 'manager',
    'front desk' => 'receptionist',
    'front desk / receptionist' => 'receptionist',
    'front_desk' => 'receptionist',
    'front_desk_receptionist' => 'receptionist',
    'grooming staff' => 'groomer',
    'grooming_staff' => 'groomer',
    'grooming staff / groomer' => 'groomer',
    'grooming_staff_groomer' => 'groomer',
    'pet hotel staff' => 'pet_hotel_staff',
    'pet hotel staff / attendant' => 'pet_hotel_staff',
    'pet_hotel_attendant' => 'pet_hotel_staff',
    'pet_hotel_staff_attendant' => 'pet_hotel_staff',
    'staff' => 'groomer',
    'supervisor' => 'receptionist',
];
$current_role = $role_aliases[$current_role_raw] ?? $current_role_raw;
if ($current_role !== '') {
    $_SESSION['role'] = $current_role;
}

$staff_roles = ['manager', 'receptionist', 'groomer', 'pet_hotel_staff'];
$session_user_id = filter_var(
    $_SESSION['user_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$admin_session_valid = (
    ($_SESSION['logged_in'] ?? false) === true
    && ($_SESSION['admin_logged_in'] ?? false) === true
    && $current_role === 'admin'
    && $session_user_id !== false
);
$staff_session_valid = (
    ($_SESSION['logged_in'] ?? false) === true
    && ($_SESSION['staff_logged_in'] ?? false) === true
    && in_array($current_role, $staff_roles, true)
    && $session_user_id !== false
);

if (!$admin_session_valid && !$staff_session_valid) {
    http_response_code(403);
    exit('Unauthorized access.');
}

$allowed_roles = ['admin', 'manager', 'receptionist', 'groomer', 'pet_hotel_staff'];
if (!in_array($current_role, $allowed_roles, true)) {
    http_response_code(403);
    exit('Unauthorized access.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

// The booking-management page creates and supplies this session-bound token.
$session_token = (string)($_SESSION['csrf_token'] ?? '');
$posted_token = (string)($_POST['csrf_token'] ?? '');
if ($session_token === '' || $posted_token === '' || !hash_equals($session_token, $posted_token)) {
    http_response_code(403);
    exit('Invalid or expired request token. Refresh the page and try again.');
}

$appointment_id = filter_var(
    $_POST['appointment_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$user_id = filter_var(
    $_POST['user_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
if ($appointment_id === false || $user_id === false) {
    http_response_code(400);
    exit('Invalid appointment_id or user_id.');
}

/** Check whether the current operational role may act on this service. */
function noShowServiceAllowedForRole(string $role, string $service): bool
{
    $service = strtolower(trim($service));

    if ($role === 'groomer') {
        return str_starts_with($service, 'grooming');
    }
    if ($role === 'pet_hotel_staff') {
        return str_starts_with($service, 'pet hotel');
    }

    return in_array($role, ['admin', 'manager', 'receptionist'], true);
}

try {
    $pdo->beginTransaction();

    // Lock the appointment before validating its owner, service and state.
    $check_appointment = $pdo->prepare(
        "SELECT id, user_id, booking_status, reschedule_count, service
         FROM appointments
         WHERE id = :appointment_id
         LIMIT 1
         FOR UPDATE"
    );
    $check_appointment->execute([':appointment_id' => $appointment_id]);
    $appointment = $check_appointment->fetch(PDO::FETCH_ASSOC);

    if (!$appointment) {
        throw new DomainException('Appointment not found.', 404);
    }

    if ((int)($appointment['user_id'] ?? 0) !== $user_id) {
        throw new DomainException('Appointment does not belong to this user.', 403);
    }

    if (!noShowServiceAllowedForRole($current_role, (string)($appointment['service'] ?? ''))) {
        throw new DomainException('Access denied for this service booking.', 403);
    }

    $status_now = strtoupper(trim((string)($appointment['booking_status'] ?? '')));
    $reschedule_count = (int)($appointment['reschedule_count'] ?? 0);

    if ($status_now === 'NO-SHOW') {
        throw new DomainException('This appointment is already marked as No-Show.', 409);
    }

    // Match managebooking.php: only the single permitted reschedule can become No-Show.
    if ($status_now !== 'RESCHEDULED' || $reschedule_count !== 1) {
        throw new DomainException(
            "No-Show is only available after the customer's one-time reschedule has been used.",
            409
        );
    }

    // Lock the customer row so concurrent No-Show actions cannot lose strike updates.
    $check_user = $pdo->prepare(
        "SELECT id, no_show_count, is_restricted
         FROM users
         WHERE id = :user_id
         LIMIT 1
         FOR UPDATE"
    );
    $check_user->execute([':user_id' => $user_id]);
    $user = $check_user->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new DomainException('User account not found.', 404);
    }

    $new_strike_count = max(0, (int)($user['no_show_count'] ?? 0)) + 1;
    $restriction_value = strtolower(trim((string)($user['is_restricted'] ?? '')));
    // PostgreSQL PDO may return booleans as 't'/'f' strings instead of true/false.
    $already_restricted = in_array($restriction_value, ['1', 't', 'true', 'yes', 'on'], true);
    // Never clear an existing restriction merely because the stored strike count is inconsistent.
    $is_restricted = $already_restricted || $new_strike_count >= 3;

    // Guard the update again to protect against unexpected concurrent state changes.
    $update_appointment = $pdo->prepare(
        "UPDATE appointments
         SET booking_status = 'No-Show'
         WHERE id = :appointment_id
           AND user_id = :user_id
           AND UPPER(BTRIM(COALESCE(booking_status, ''))) = 'RESCHEDULED'
           AND COALESCE(reschedule_count, 0) = 1"
    );
    $update_appointment->execute([
        ':appointment_id' => $appointment_id,
        ':user_id' => $user_id,
    ]);

    if ($update_appointment->rowCount() !== 1) {
        throw new DomainException('Appointment status was not updated. Refresh and try again.', 409);
    }

    $update_user = $pdo->prepare(
        "UPDATE users
         SET no_show_count = :no_show_count,
             is_restricted = :is_restricted
         WHERE id = :user_id"
    );
    $update_user->execute([
        ':no_show_count' => $new_strike_count,
        ':is_restricted' => $is_restricted,
        ':user_id' => $user_id,
    ]);

    if ($update_user->rowCount() !== 1) {
        throw new RuntimeException('Customer No-Show strike count could not be updated.');
    }

    $pdo->commit();

    if ($is_restricted) {
        echo 'Marked as No-Show successfully. User has reached 3 strikes or was already restricted.';
    } else {
        echo "Marked as No-Show successfully. Strike count: {$new_strike_count}.";
    }
} catch (DomainException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $status_code = (int)$e->getCode();
    http_response_code($status_code >= 400 && $status_code < 500 ? $status_code : 400);
    echo $e->getMessage();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Mark No-Show database error: ' . $e->getMessage());
    http_response_code(500);
    echo 'Failed to mark appointment as No-Show.';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Mark No-Show unexpected error: ' . $e->getMessage());
    http_response_code(500);
    echo 'Failed to mark appointment as No-Show.';
}
