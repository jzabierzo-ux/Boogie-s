<?php
require_once __DIR__ . '/../shared_session_bootstrap.php';

// Customer-only booking endpoint.
$loggedIn = ($_SESSION['logged_in'] ?? false) === true;
$sessionRole = strtolower(trim(is_scalar($_SESSION['role'] ?? null) ? (string)$_SESSION['role'] : ''));
$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);

if (!$loggedIn || $sessionRole !== 'customer' || !$userId || $userId < 1) {
    header('Location: login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit();
}

require_once __DIR__ . '/../db_supabase.php';
date_default_timezone_set('Asia/Manila');

/** A validation error that can safely be shown to the customer. */
class CustomerBookingValidationException extends RuntimeException
{
    public int $httpStatus;

    public function __construct(string $message, int $httpStatus = 400)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
    }
}

/** Read a scalar POST value without allowing array-to-string warnings. */
function bookingPostText(string $key, int $maxLength, bool $required = false): string
{
    $value = $_POST[$key] ?? '';
    if (!is_string($value) && !is_numeric($value)) {
        throw new CustomerBookingValidationException('Invalid booking information. Please review your entries.');
    }

    $value = trim((string)$value);
    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);

    if ($required && $value === '') {
        throw new CustomerBookingValidationException('Please complete all required booking fields.');
    }
    if ($length > $maxLength) {
        throw new CustomerBookingValidationException('Some booking details are too long. Please review your entries.');
    }

    return $value;
}

/** Return a small alert page without exposing server or database details. */
function customerBookingAlert(string $message, int $statusCode = 400): void
{
    http_response_code($statusCode);
    header('Content-Type: text/html; charset=UTF-8');
    $encodedMessage = json_encode(
        $message,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
    );
    echo '<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Booking</title></head><body><script>alert(' . $encodedMessage . ');window.history.back();</script></body></html>';
    exit();
}

// Generate a booking-specific CSRF token. The booking form must submit this
// value in a hidden input named csrf_token.
if (empty($_SESSION['booking_csrf_token']) || !is_string($_SESSION['booking_csrf_token'])) {
    $_SESSION['booking_csrf_token'] = bin2hex(random_bytes(32));
}
$bookingCsrfToken = $_SESSION['booking_csrf_token'];

$receiptPath = null;
$bookingSaved = false;

try {
    // Validate the request token before doing any database or file work.
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($postedToken) || $postedToken === '' || !hash_equals($bookingCsrfToken, $postedToken)) {
        throw new CustomerBookingValidationException('Your booking session expired. Refresh the booking page and try again.');
    }

    // Re-read the customer profile from the database instead of trusting display data in the session.
    $userStmt = $pdo->prepare(
        'SELECT full_name, contact_number, role FROM users WHERE id = :user_id LIMIT 1'
    );
    $userStmt->execute([':user_id' => $userId]);
    $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$userRow || strtolower(trim((string)($userRow['role'] ?? ''))) !== 'customer') {
        throw new CustomerBookingValidationException('Your customer account could not be verified. Please log in again.', 403);
    }

    $fullName = trim((string)($userRow['full_name'] ?? '')) ?: 'Customer';
    $contactNumber = trim((string)($userRow['contact_number'] ?? ''));

    // Read and validate every submitted value as a scalar.
    $petName = bookingPostText('pet_name', 120, true);
    $petType = bookingPostText('pet_type', 60, true);
    $petGender = bookingPostText('pet_gender', 30, true);
    $petSize = bookingPostText('pet_size', 50, true);
    $serviceCategory = bookingPostText('service_category', 40, true);
    $specificService = bookingPostText('specific_service', 160, true);
    $haircutStyle = bookingPostText('haircut_style', 100);
    $date = bookingPostText('appointment_date', 10, true);
    $time = bookingPostText('appointment_time', 5, true);
    $remarks = bookingPostText('remarks', 2000);
    $gcashRef = bookingPostText('gcash_ref', 120, true);

    if (!in_array($serviceCategory, ['Grooming', 'Vet Services', 'Pet Hotel'], true)) {
        throw new CustomerBookingValidationException('Invalid service category. Please select a listed service.');
    }

    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (
        !$parsedDate ||
        ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) ||
        $parsedDate->format('Y-m-d') !== $date ||
        $date < date('Y-m-d')
    ) {
        throw new CustomerBookingValidationException('Please select a valid appointment date that is not in the past.');
    }

    if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
        throw new CustomerBookingValidationException('Please select a valid appointment time.');
    }

    if ($haircutStyle !== '' && $specificService === 'Full Grooming Package') {
        $finalServiceName = $serviceCategory . ' - ' . $specificService . ' (' . $haircutStyle . ')';
    } else {
        $finalServiceName = $serviceCategory . ' - ' . $specificService;
    }

    // Validate the uploaded GCash receipt before opening the database transaction.
    $receipt = $_FILES['gcash_receipt'] ?? null;
    if (!is_array($receipt)) {
        throw new CustomerBookingValidationException('Please upload your GCash receipt screenshot.');
    }

    $uploadError = filter_var($receipt['error'] ?? UPLOAD_ERR_NO_FILE, FILTER_VALIDATE_INT);
    if ($uploadError !== UPLOAD_ERR_OK) {
        if ($uploadError === UPLOAD_ERR_NO_FILE) {
            throw new CustomerBookingValidationException('Please upload your GCash receipt screenshot.');
        }
        throw new CustomerBookingValidationException('The receipt upload did not finish. Please try uploading it again.');
    }

    $originalFilename = is_string($receipt['name'] ?? null) ? $receipt['name'] : '';
    $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
    $receiptSize = filter_var($receipt['size'] ?? 0, FILTER_VALIDATE_INT);
    $tempReceipt = is_string($receipt['tmp_name'] ?? null) ? $receipt['tmp_name'] : '';
    $allowedMimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];

    if (
        !isset($allowedMimes[$extension]) ||
        !$receiptSize || $receiptSize < 1 || $receiptSize > 5 * 1024 * 1024 ||
        $tempReceipt === '' || !is_uploaded_file($tempReceipt) ||
        !function_exists('finfo_open')
    ) {
        throw new CustomerBookingValidationException('Invalid receipt image. Upload a JPG or PNG image up to 5MB.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $receiptMime = $finfo ? finfo_file($finfo, $tempReceipt) : false;
    if ($finfo) {
        finfo_close($finfo);
    }
    $imageInfo = @getimagesize($tempReceipt);
    if (
        $receiptMime !== $allowedMimes[$extension] ||
        !is_array($imageInfo) ||
        ($imageInfo['mime'] ?? '') !== $allowedMimes[$extension]
    ) {
        throw new CustomerBookingValidationException('Invalid receipt image. Upload a valid JPG or PNG image up to 5MB.');
    }

    $receiptFilename = 'receipt_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $uploadDirectory = __DIR__ . '/../uploads';
    $receiptPath = $uploadDirectory . DIRECTORY_SEPARATOR . $receiptFilename;

    // Keep the existing capacity rules and closed days.
    if ($serviceCategory === 'Vet Services') {
        $dayOfWeek = (int)$parsedDate->format('w');
        if ($dayOfWeek === 3 || $dayOfWeek === 6) {
            throw new CustomerBookingValidationException('Dr. Faith Casayuran is not available on Wednesdays and Saturdays. Please select a valid day.');
        }
    }

    // Serialize submissions for the same date/category, so two requests handled
    // by this endpoint cannot both pass the same capacity/time-slot checks.
    $pdo->beginTransaction();
    $lockKey = 'customer-booking:' . $date . ':' . $serviceCategory;
    $lockStmt = $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(:lock_key))');
    $lockStmt->execute([':lock_key' => $lockKey]);

    if ($serviceCategory === 'Vet Services' || $serviceCategory === 'Grooming') {
        $capacityLimit = ($serviceCategory === 'Vet Services') ? 6 : 10;
        $capacityStmt = $pdo->prepare(
            "SELECT COUNT(id) AS total_booked
             FROM appointments
             WHERE appointment_date = :appointment_date
               AND service ILIKE :service_pattern
               AND booking_status IS DISTINCT FROM 'Cancelled'"
        );
        $capacityStmt->execute([
            ':appointment_date' => $date,
            ':service_pattern' => $serviceCategory . '%'
        ]);
        $capacityRow = $capacityStmt->fetch(PDO::FETCH_ASSOC);

        if ((int)($capacityRow['total_booked'] ?? 0) >= $capacityLimit) {
            $message = ($serviceCategory === 'Vet Services')
                ? 'The Vet Clinic is fully booked for this date (Max 6 capacity). Please select another date.'
                : 'Grooming services are fully booked for this date (Max 10 capacity). Please select another date.';
            throw new CustomerBookingValidationException($message);
        }
    }

    $timeConflictStmt = $pdo->prepare(
        "SELECT id
         FROM appointments
         WHERE appointment_date = :appointment_date
           AND appointment_time = :appointment_time
           AND service ILIKE :service_pattern
           AND booking_status IS DISTINCT FROM 'Cancelled'
         LIMIT 1"
    );
    $timeConflictStmt->execute([
        ':appointment_date' => $date,
        ':appointment_time' => $time,
        ':service_pattern' => $serviceCategory . '%'
    ]);

    if ($timeConflictStmt->fetch(PDO::FETCH_ASSOC)) {
        throw new CustomerBookingValidationException('This exact time slot is already taken. Please choose another time.');
    }

    // Lock by customer and normalized pet name, then find or create the pet record.
    $petLockKey = 'customer-pet:' . $userId . ':' . strtolower($petName);
    $petLockStmt = $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(:lock_key))');
    $petLockStmt->execute([':lock_key' => $petLockKey]);

    $checkPetStmt = $pdo->prepare(
        'SELECT id FROM pets WHERE owner_id = :owner_id AND name = :pet_name ORDER BY id ASC LIMIT 1 FOR UPDATE'
    );
    $checkPetStmt->execute([':owner_id' => $userId, ':pet_name' => $petName]);
    $petRow = $checkPetStmt->fetch(PDO::FETCH_ASSOC);

    if ($petRow) {
        $petId = (int)$petRow['id'];
        $updatePetStmt = $pdo->prepare(
            'UPDATE pets
             SET weight = :weight, pet_type = :pet_type, gender = :gender
             WHERE id = :pet_id AND owner_id = :owner_id'
        );
        $updatePetStmt->execute([
            ':weight' => $petSize,
            ':pet_type' => $petType,
            ':gender' => $petGender,
            ':pet_id' => $petId,
            ':owner_id' => $userId
        ]);
    } else {
        $insertPetStmt = $pdo->prepare(
            'INSERT INTO pets (owner_id, name, pet_type, gender, weight)
             VALUES (:owner_id, :name, :pet_type, :gender, :weight)
             RETURNING id'
        );
        $insertPetStmt->execute([
            ':owner_id' => $userId,
            ':name' => $petName,
            ':pet_type' => $petType,
            ':gender' => $petGender,
            ':weight' => $petSize
        ]);
        $petId = (int)$insertPetStmt->fetchColumn();
    }

    if ($petId < 1) {
        throw new RuntimeException('Pet profile could not be saved.');
    }

    // Store the receipt only after all validation and capacity checks pass.
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException('Receipt upload directory could not be created.');
    }
    if (!is_writable($uploadDirectory) || !move_uploaded_file($tempReceipt, $receiptPath)) {
        throw new RuntimeException('Receipt image could not be saved.');
    }

    // Keep the existing payment and booking statuses.
    $insertAppointmentStmt = $pdo->prepare(
        "INSERT INTO appointments (
            user_id, pet_id, service, appointment_date, appointment_time,
            gcash_ref, gcash_receipt, payment_method, payment_status,
            booking_status, remarks
        ) VALUES (
            :user_id, :pet_id, :service, :appointment_date, :appointment_time,
            :gcash_ref, :gcash_receipt, :payment_method, :payment_status,
            :booking_status, :remarks
        )"
    );
    $insertAppointmentStmt->execute([
        ':user_id' => $userId,
        ':pet_id' => $petId,
        ':service' => $finalServiceName,
        ':appointment_date' => $date,
        ':appointment_time' => $time,
        ':gcash_ref' => $gcashRef,
        ':gcash_receipt' => $receiptFilename,
        ':payment_method' => 'GCash',
        ':payment_status' => 'Pending Verification',
        ':booking_status' => 'Pending',
        ':remarks' => $remarks
    ]);

    $adminMessage = $fullName . ' booked ' . $petName . ' via GCash. Ref: ' . $gcashRef . '. Pending Verification.';
    $adminNotifStmt = $pdo->prepare('INSERT INTO admin_notifications (message) VALUES (:message)');
    $adminNotifStmt->execute([':message' => $adminMessage]);

    $pdo->commit();
    $bookingSaved = true;

    // SMS is sent after commit so a delivery problem cannot undo a saved booking.
    if (preg_match('/^09[0-9]{9}$/', $contactNumber)) {
        $iprogHelper = __DIR__ . '/../includes/iprog_sms.php';
        if (is_file($iprogHelper)) {
            try {
                require_once $iprogHelper;
                if (function_exists('sendIPROGSMS')) {
                    $formattedTime = date('g:i A', strtotime($time));
                    $smsMessage = "Hi {$fullName}, we received your GCash booking request for {$petName} on {$date} at {$formattedTime}. Please wait for the admin to verify your payment. Thank you! - Boogie's Pet Care";
                    $smsResult = sendIPROGSMS($contactNumber, $smsMessage);
                    if (!is_array($smsResult) || empty($smsResult['success'])) {
                        error_log('IPROG SMS failed for booking notification.');
                    }
                } else {
                    error_log('sendIPROGSMS() function not found.');
                }
            } catch (Throwable $smsError) {
                error_log('IPROG SMS exception: ' . $smsError->getMessage());
            }
        } else {
            error_log('IPROG SMS helper not found: ' . $iprogHelper);
        }
    }

    header('Content-Type: text/html; charset=UTF-8');
    echo "<!doctype html><html lang='en'><head><meta charset='UTF-8'></head><body><script>alert('Booking Request Submitted! Please wait for admin verification.');window.location.href='bookings.php';</script></body></html>";
    exit();
} catch (CustomerBookingValidationException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (!$bookingSaved && $receiptPath !== null && is_file($receiptPath)) {
        @unlink($receiptPath);
    }
    customerBookingAlert($e->getMessage(), $e->httpStatus);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (!$bookingSaved && $receiptPath !== null && is_file($receiptPath)) {
        @unlink($receiptPath);
    }
    error_log('Process booking failed: ' . $e->getMessage());
    customerBookingAlert('We could not save your booking right now. Please try again. If the problem continues, contact the clinic.', 500);
}
