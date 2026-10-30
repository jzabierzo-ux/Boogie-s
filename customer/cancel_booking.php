<?php
require_once __DIR__ . '/../shared_session_bootstrap.php';
require_once __DIR__ . '/../db_supabase.php';
require_once __DIR__ . '/../includes/iprog_sms.php';

date_default_timezone_set('Asia/Manila');

function normalizeCancellationRole(string $role): string
{
    $role = strtolower(trim($role));
    $aliases = [
        'administrator' => 'admin',
        'shop manager' => 'manager',
        'shop_manager' => 'manager',
        'vet' => 'veterinarian',
        'veterinary' => 'veterinarian',
        'front desk' => 'receptionist',
        'front desk / receptionist' => 'receptionist',
        'front_desk' => 'receptionist',
        'front_desk_receptionist' => 'receptionist',
        'veterinary assistant' => 'vet_assistant',
        'vet assistant' => 'vet_assistant',
        'veterinary_assistant' => 'vet_assistant',
        'veterinary nurse' => 'vet_nurse',
        'vet nurse' => 'vet_nurse',
        'veterinary_nurse' => 'vet_nurse',
        'grooming staff' => 'groomer',
        'grooming staff / groomer' => 'groomer',
        'grooming_staff' => 'groomer',
        'grooming_staff_groomer' => 'groomer',
        'pet hotel staff' => 'pet_hotel_staff',
        'pet hotel staff / attendant' => 'pet_hotel_staff',
        'pet_hotel_attendant' => 'pet_hotel_staff',
        'pet_hotel_staff_attendant' => 'pet_hotel_staff',
        // Temporary compatibility for older account roles.
        'staff' => 'groomer',
        'supervisor' => 'receptionist'
    ];

    return $aliases[$role] ?? $role;
}

function cancellationServiceCategory(string $service): string
{
    $service = strtolower(trim($service));

    if (str_contains($service, 'pet hotel') || str_contains($service, 'boarding') || str_contains($service, 'daycare')) {
        return 'pet_hotel';
    }

    if (str_contains($service, 'vet services') || str_contains($service, 'vaccination') || str_contains($service, 'deworming') || str_contains($service, 'consultation')) {
        return 'vet';
    }

    if (str_contains($service, 'groom') || str_contains($service, 'bath & blow dry') || str_contains($service, 'bath and blow dry')) {
        return 'grooming';
    }

    return 'other';
}

function cancellationRedirect(string $role, string $query = ''): void
{
    global $pdo;

    // A validation failure can occur after the appointment row has been locked.
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $isPersonnel = in_array($role, [
        'admin', 'manager', 'receptionist', 'groomer', 'pet_hotel_staff',
        'veterinarian', 'vet_assistant', 'vet_nurse'
    ], true);

    if (in_array($role, ['veterinarian', 'vet_assistant', 'vet_nurse'], true)) {
        $target = 'staff/appointments.php';
    } elseif ($isPersonnel) {
        $target = 'admin/managebooking.php';
    } else {
        $target = 'bookings.php';
    }

    header('Location: ' . $target . ($query !== '' ? (str_contains($target, '?') ? '&' : '?') . $query : ''));
    exit;
}

function cancelBookingError(string $role, string $error): void
{
    cancellationRedirect($role, 'error=' . rawurlencode($error));
}

/** Return a safe SQL literal for the deployed is_read column type. */
function cancellationNotificationReadValue(PDO $pdo, string $table, bool $isRead): string
{
    $allowedTables = ['notifications', 'admin_notifications'];
    if (!in_array($table, $allowedTables, true)) {
        throw new InvalidArgumentException('Unsupported notification table.');
    }

    $typeStmt = $pdo->prepare("
        SELECT data_type
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = :table_name
          AND column_name = 'is_read'
        LIMIT 1
    ");
    $typeStmt->execute([':table_name' => $table]);
    $columnType = strtolower(trim((string)$typeStmt->fetchColumn()));

    if ($columnType === 'boolean') {
        return $isRead ? 'TRUE' : 'FALSE';
    }

    if (in_array($columnType, ['smallint', 'integer', 'bigint', 'numeric', 'decimal', 'real', 'double precision'], true)) {
        return $isRead ? '1' : '0';
    }

    if (in_array($columnType, ['text', 'character varying', 'character'], true)) {
        return $isRead ? "'1'" : "'0'";
    }

    throw new RuntimeException('Unable to determine a supported ' . $table . '.is_read data type.');
}

function cancellationTextLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

$rawRole = is_scalar($_SESSION['role'] ?? null) ? (string)$_SESSION['role'] : 'customer';
$currentRole = normalizeCancellationRole($rawRole !== '' ? $rawRole : 'customer');
$isLoggedIn = ($_SESSION['logged_in'] ?? false) === true;
$isPersonnel = in_array($currentRole, [
    'admin', 'manager', 'receptionist', 'groomer', 'pet_hotel_staff',
    'veterinarian', 'vet_assistant', 'vet_nurse'
], true);
$isAdmin = $currentRole === 'admin';

$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
if (!$isLoggedIn || $userId === false || $userId === null || $userId < 1) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

// All cancellation forms must submit the CSRF token generated by the page.
$sessionToken = is_string($_SESSION['csrf_token'] ?? null) ? $_SESSION['csrf_token'] : '';
$postedToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
if ($sessionToken === '' || $postedToken === '' || !hash_equals($sessionToken, $postedToken)) {
    http_response_code(403);
    exit('Invalid or expired request token. Refresh the page and try again.');
}

$fullNameRaw = $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? 'User';
$fullName = is_scalar($fullNameRaw) ? trim((string)$fullNameRaw) : 'User';
$fullName = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $fullName) ?? 'User';
$fullName = trim(function_exists('mb_substr') ? mb_substr($fullName, 0, 100, 'UTF-8') : substr($fullName, 0, 100));
if ($fullName === '') {
    $fullName = 'User';
}

$appointmentId = filter_var($_POST['appointment_id'] ?? null, FILTER_VALIDATE_INT);
$rawReason = $_POST['cancel_reason'] ?? '';
$cancelReason = is_scalar($rawReason) ? trim((string)$rawReason) : '';
$cancelReason = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $cancelReason) ?? '';
$cancelReason = preg_replace('/[\r\n\t]+/u', ' ', $cancelReason) ?? '';
$cancelReason = trim($cancelReason);

if ($appointmentId === false || $appointmentId === null || $appointmentId < 1 || $cancelReason === '' || cancellationTextLength($cancelReason) > 500) {
    cancelBookingError($currentRole, 'invalid_request');
}

try {
    // Lock the appointment row before checking status/time to prevent concurrent cancellations.
    $pdo->beginTransaction();

    $query = "
        SELECT
            a.id,
            a.appointment_date,
            a.appointment_time,
            a.booking_status,
            a.payment_status,
            a.service,
            a.user_id,
            p.name AS pet_name,
            u.contact_number
        FROM appointments a
        LEFT JOIN pets p ON a.pet_id = p.id
        LEFT JOIN users u ON a.user_id = u.id
        WHERE a.id = :appointment_id
    ";
    $params = [':appointment_id' => (int)$appointmentId];

    if (!$isPersonnel) {
        $query .= ' AND a.user_id = :session_user_id';
        $params[':session_user_id'] = (int)$userId;
    }

    $query .= ' LIMIT 1 FOR UPDATE OF a';
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        cancelBookingError($currentRole, 'unauthorized_or_not_found');
    }

    // Role-specific service access: direct POST requests are checked as well.
    if ($isPersonnel && !$isAdmin) {
        $category = cancellationServiceCategory((string)($booking['service'] ?? ''));
        $allowed = match ($currentRole) {
            'groomer' => $category === 'grooming',
            'pet_hotel_staff' => $category === 'pet_hotel',
            'veterinarian', 'vet_assistant', 'vet_nurse' => $category === 'vet',
            'manager', 'receptionist' => in_array($category, ['grooming', 'pet_hotel', 'vet'], true),
            default => false
        };

        if (!$allowed) {
            http_response_code(403);
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            exit('Access denied for this service booking.');
        }
    }

    $currentStatus = strtoupper(trim((string)($booking['booking_status'] ?? '')));
    if (in_array($currentStatus, ['COMPLETED', 'NO-SHOW', 'CANCELLED'], true)) {
        cancelBookingError($currentRole, 'invalid_status');
    }

    $dateValue = trim((string)($booking['appointment_date'] ?? ''));
    $timeValue = trim((string)($booking['appointment_time'] ?? '00:00:00'));
    $appointmentTimestamp = strtotime($dateValue . ' ' . $timeValue);
    if ($appointmentTimestamp === false || $appointmentTimestamp <= time()) {
        cancelBookingError($currentRole, 'past_date');
    }

    // Customers must cancel at least 24 hours before the appointment.
    // Authorized personnel can handle operational cancellations at any time before the appointment.
    if (!$isPersonnel && ($appointmentTimestamp - time()) < 86400) {
        cancelBookingError($currentRole, 'late_cancellation');
    }

    $serviceName = trim((string)($booking['service'] ?? 'Service')) ?: 'Service';
    $petName = trim((string)($booking['pet_name'] ?? '')) ?: 'your pet';
    $customerPhone = trim((string)($booking['contact_number'] ?? ''));
    $customerId = (int)($booking['user_id'] ?? 0);
    $appointmentDateDisplay = date('M d, Y', $appointmentTimestamp);
    $paymentStatus = strtolower(trim((string)($booking['payment_status'] ?? '')));

    // A paid or pending-verification payment is sent for review/refund handling; this endpoint never verifies GCash.
    $newPaymentStatus = in_array($paymentStatus, ['paid', 'pending verification'], true)
        ? 'Refund Requested'
        : 'Cancelled';

    $updateSql = "
        UPDATE appointments
        SET booking_status = 'Cancelled',
            payment_status = :payment_status,
            cancel_reason = :cancel_reason
        WHERE id = :appointment_id
          AND UPPER(COALESCE(booking_status, '')) NOT IN ('COMPLETED', 'NO-SHOW', 'CANCELLED')
    ";
    $updateParams = [
        ':payment_status' => $newPaymentStatus,
        ':cancel_reason' => $cancelReason,
        ':appointment_id' => (int)$appointmentId
    ];

    // Keep ownership in the write query as a second authorization check for customers.
    if (!$isPersonnel) {
        $updateSql .= ' AND user_id = :session_user_id';
        $updateParams[':session_user_id'] = (int)$userId;
    }

    $updateStmt = $pdo->prepare($updateSql);
    $updateStmt->execute($updateParams);

    if ($updateStmt->rowCount() !== 1) {
        cancelBookingError($currentRole, 'invalid_status');
    }

    if ($customerId > 0) {
        if ($isPersonnel) {
            $notificationTitle = 'Booking Cancelled by Clinic';
            $notificationMessage = "Your booking for {$petName} ({$serviceName}) was cancelled by clinic staff. Reason: {$cancelReason}. Payment status: {$newPaymentStatus}.";
            $notificationReadValue = cancellationNotificationReadValue($pdo, 'notifications', false);

            $notificationStmt = $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, type, is_read, created_at)
                VALUES (:user_id, :title, :message, 'booking', {$notificationReadValue}, NOW())
            ");
            $notificationStmt->execute([
                ':user_id' => $customerId,
                ':title' => $notificationTitle,
                ':message' => $notificationMessage
            ]);
        } else {
            $notificationMessage = $fullName . ' cancelled their ' . $serviceName
                . ' appointment. Reason: ' . $cancelReason
                . '. Payment status: ' . $newPaymentStatus . '.';
            $notificationReadValue = cancellationNotificationReadValue($pdo, 'admin_notifications', false);

            $notificationStmt = $pdo->prepare("
                INSERT INTO admin_notifications (message, is_read, created_at)
                VALUES (:message, {$notificationReadValue}, NOW())
            ");
            $notificationStmt->execute([':message' => $notificationMessage]);
        }
    }

    $pdo->commit();

    // Send customer SMS only after the database transaction commits.
    if ($customerPhone !== '' && strtoupper($customerPhone) !== 'N/A') {
        if ($isPersonnel) {
            $smsBody = "Hi! Your booking for {$petName} ({$serviceName}) on {$appointmentDateDisplay} was CANCELLED by the clinic. Reason: {$cancelReason}. - Boogie's Pet Care";
        } else {
            $smsBody = "Hi {$fullName}, you have successfully cancelled your booking for {$petName} ({$serviceName}) on {$appointmentDateDisplay}. - Boogie's Pet Care";
        }

        try {
            $smsResult = sendIPROGSMS($customerPhone, $smsBody);
            if (empty($smsResult['success'])) {
                $smsHttpCode = (int)($smsResult['http_code'] ?? 0);
                $smsResponse = is_scalar($smsResult['response'] ?? null)
                    ? substr((string)$smsResult['response'], 0, 1000)
                    : 'Unknown error';
                error_log(
                    'IPROG SMS failed for cancellation. Appointment ID: ' . (int)$appointmentId
                    . ' | HTTP: ' . $smsHttpCode
                    . ' | Response: ' . $smsResponse
                );
            }
        } catch (Throwable $smsError) {
            error_log('IPROG SMS exception for cancellation, appointment #' . (int)$appointmentId . ': ' . $smsError->getMessage());
        }
    }

    cancellationRedirect($currentRole, 'msg=cancelled');

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Cancel booking database error: ' . $e->getMessage());
    cancelBookingError($currentRole, 'db_error');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Cancel booking error: ' . $e->getMessage());
    cancelBookingError($currentRole, 'unexpected_error');
}
