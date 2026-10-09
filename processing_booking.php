<?php

session_start();

require_once 'db_supabase.php';



// Set timezone to Philippines

date_default_timezone_set('Asia/Manila');



// Ensure user is logged in

if (
    !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true ||
    strtolower(trim((string)($_SESSION['role'] ?? ''))) !== 'customer'
) {
    header("Location: login.php");
    exit();
}



if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        // 1. Capture and clean form data

        $user_id   = (int)($_SESSION['user_id'] ?? 0);

        $full_name = $_SESSION['user_name'] ?? 'Customer';



        if ($user_id <= 0) {

            throw new RuntimeException('Invalid user session.');

        }



        // Fetch user contact number for SMS

        $contact_number = '';

        $stmt_user = $pdo->prepare("

            SELECT contact_number

            FROM users

            WHERE id = :user_id

            LIMIT 1

        ");

        $stmt_user->execute([

            ':user_id' => $user_id

        ]);

        $user_row = $stmt_user->fetch(PDO::FETCH_ASSOC);



        if ($user_row) {

            $contact_number = $user_row['contact_number'] ?? '';

        }



        $pet_name         = trim($_POST['pet_name'] ?? '');

        $pet_type         = trim($_POST['pet_type'] ?? '');

        $pet_gender       = trim($_POST['pet_gender'] ?? '');

        $pet_size         = trim($_POST['pet_size'] ?? '');

        $service_category = trim($_POST['service_category'] ?? '');

        $specific_service = trim($_POST['specific_service'] ?? '');

        $haircut_style    = trim($_POST['haircut_style'] ?? '');

        $date             = trim($_POST['appointment_date'] ?? '');

        $time             = trim($_POST['appointment_time'] ?? '');

        $remarks          = trim($_POST['remarks'] ?? '');

        $gcash_ref        = trim($_POST['gcash_ref'] ?? '');



        if (

            $pet_name === '' ||

            $pet_type === '' ||

            $pet_gender === '' ||

            $pet_size === '' ||

            $service_category === '' ||

            $specific_service === '' ||

            $date === '' ||

            $time === '' ||

            $gcash_ref === ''

        ) {

            throw new RuntimeException('Please complete all required booking fields.');

        }

        // Validate category/date/time server-side; browser form restrictions can be bypassed.
        if (!in_array($service_category, ['Grooming', 'Vet Services', 'Pet Hotel'], true)) {
            throw new RuntimeException('Invalid service category. Please select a listed service.');
        }

        $parsed_date = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $date_errors = DateTimeImmutable::getLastErrors();
        if (
            !$parsed_date ||
            ($date_errors !== false && ($date_errors['warning_count'] > 0 || $date_errors['error_count'] > 0)) ||
            $parsed_date->format('Y-m-d') !== $date ||
            $date < date('Y-m-d')
        ) {
            throw new RuntimeException('Please select a valid date that is not in the past.');
        }

        if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
            throw new RuntimeException('Please select a valid appointment time.');
        }

        if (strlen($pet_name) > 120 || strlen($specific_service) > 160 || strlen($gcash_ref) > 120) {
            throw new RuntimeException('Some booking details are too long. Please review your entries.');
        }



        // Combine service name

        $final_service_name = $service_category . " - " . $specific_service;



        if (

            $haircut_style !== '' &&

            $specific_service === 'Full Grooming Package'

        ) {

            $final_service_name .= " (" . $haircut_style . ")";

        }



        // --- 2. GCASH RECEIPT UPLOAD HANDLING ---

        $receipt_filename = '';



        if (

            isset($_FILES['gcash_receipt']) &&

            $_FILES['gcash_receipt']['error'] === UPLOAD_ERR_OK

        ) {

            $allowed_mimes = [
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png'
            ];
            $filename = (string)($_FILES['gcash_receipt']['name'] ?? '');
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $receipt_size = (int)($_FILES['gcash_receipt']['size'] ?? 0);
            $tmp_receipt = (string)($_FILES['gcash_receipt']['tmp_name'] ?? '');
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $receipt_mime = $finfo ? finfo_file($finfo, $tmp_receipt) : false;
            if ($finfo) {
                finfo_close($finfo);
            }

            if (
                !isset($allowed_mimes[$ext]) ||
                $receipt_mime !== $allowed_mimes[$ext] ||
                $receipt_size <= 0 ||
                $receipt_size > 5 * 1024 * 1024
            ) {
                echo "<script>alert('Invalid receipt image. Upload a JPG or PNG image up to 5MB.'); window.history.back();</script>";
                exit();
            }



            if (!is_dir('uploads')) {

                mkdir('uploads', 0755, true);

            }



            $receipt_filename = 'receipt_' . $user_id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;



            if (!move_uploaded_file(

                $_FILES['gcash_receipt']['tmp_name'],

                'uploads/' . $receipt_filename

            )) {

                echo "<script>alert('Failed to upload receipt image.'); window.history.back();</script>";

                exit();

            }

        } else {

            echo "<script>alert('Please upload your GCash receipt screenshot.'); window.history.back();</script>";

            exit();

        }



        // --- 3. CAPACITY RULES (10 Grooming, 6 Vet) ---

        if ($service_category === 'Vet Services') {

            $day_of_week = (int)date('w', strtotime($date));



            if ($day_of_week === 3 || $day_of_week === 6) {

                echo "<script>alert('Dr. Faith Casayuran is not available on Wednesdays and Saturdays. Please select a valid day.'); window.history.back();</script>";

                exit();

            }



            $capacity_stmt = $pdo->prepare("

                SELECT COUNT(id) AS total_booked

                FROM appointments

                WHERE appointment_date = :appointment_date

                  AND service ILIKE :service_pattern

                  AND booking_status IS DISTINCT FROM 'Cancelled'

            ");



            $capacity_stmt->execute([

                ':appointment_date' => $date,

                ':service_pattern' => 'Vet Services%'

            ]);



            $capacity_row = $capacity_stmt->fetch(PDO::FETCH_ASSOC);



            if ((int)($capacity_row['total_booked'] ?? 0) >= 6) {

                echo "<script>alert('The Vet Clinic is fully booked for this date (Max 6 capacity). Please select another date.'); window.history.back();</script>";

                exit();

            }

        } elseif ($service_category === 'Grooming') {

            $capacity_stmt = $pdo->prepare("

                SELECT COUNT(id) AS total_booked

                FROM appointments

                WHERE appointment_date = :appointment_date

                  AND service ILIKE :service_pattern

                  AND booking_status IS DISTINCT FROM 'Cancelled'

            ");



            $capacity_stmt->execute([

                ':appointment_date' => $date,

                ':service_pattern' => 'Grooming%'

            ]);



            $capacity_row = $capacity_stmt->fetch(PDO::FETCH_ASSOC);



            if ((int)($capacity_row['total_booked'] ?? 0) >= 10) {

                echo "<script>alert('Grooming services are fully booked for this date (Max 10 capacity). Please select another date.'); window.history.back();</script>";

                exit();

            }

        }



        // --- 4. EXACT TIME SLOT CONFLICT ---

        $time_conflict_stmt = $pdo->prepare("

            SELECT id

            FROM appointments

            WHERE appointment_date = :appointment_date

              AND appointment_time = :appointment_time

              AND service ILIKE :service_pattern

              AND booking_status IS DISTINCT FROM 'Cancelled'

            LIMIT 1

        ");



        $time_conflict_stmt->execute([

            ':appointment_date' => $date,

            ':appointment_time' => $time,

            ':service_pattern' => $service_category . '%'

        ]);



        if ($time_conflict_stmt->fetch(PDO::FETCH_ASSOC)) {

            echo "<script>alert('This exact time slot is already taken. Please choose another time.'); window.history.back();</script>";

            exit();

        }



        // --- 5. PET PROFILE HANDLING (Find or Auto-Create) ---

        $pet_id = null;



        $check_pet_stmt = $pdo->prepare("

            SELECT id

            FROM pets

            WHERE owner_id = :owner_id

              AND name = :pet_name

            LIMIT 1

        ");



        $check_pet_stmt->execute([

            ':owner_id' => $user_id,

            ':pet_name' => $pet_name

        ]);



        $pet_row = $check_pet_stmt->fetch(PDO::FETCH_ASSOC);



        if ($pet_row) {

            $pet_id = (int)$pet_row['id'];



            // Update size/type/gender in case they changed

            $update_pet_stmt = $pdo->prepare("

                UPDATE pets

                SET weight = :weight,

                    pet_type = :pet_type,

                    gender = :gender

                WHERE id = :pet_id

            ");



            $update_pet_stmt->execute([

                ':weight' => $pet_size,

                ':pet_type' => $pet_type,

                ':gender' => $pet_gender,

                ':pet_id' => $pet_id

            ]);

        } else {

            $insert_pet_stmt = $pdo->prepare("

                INSERT INTO pets

                    (owner_id, name, pet_type, gender, weight)

                VALUES

                    (:owner_id, :name, :pet_type, :gender, :weight)

                RETURNING id

            ");



            $insert_pet_stmt->execute([

                ':owner_id' => $user_id,

                ':name' => $pet_name,

                ':pet_type' => $pet_type,

                ':gender' => $pet_gender,

                ':weight' => $pet_size

            ]);



            $pet_id = (int)$insert_pet_stmt->fetchColumn();

        }



        // --- 6. INSERT APPOINTMENT (Pending Verification) ---

        $payment_method = 'GCash';

        $payment_status = 'Pending Verification';

        $booking_status = 'Pending';



        $insert_stmt = $pdo->prepare("

            INSERT INTO appointments

                (

                    user_id,

                    pet_id,

                    service,

                    appointment_date,

                    appointment_time,

                    gcash_ref,

                    gcash_receipt,

                    payment_method,

                    payment_status,

                    booking_status,

                    remarks

                )

            VALUES

                (

                    :user_id,

                    :pet_id,

                    :service,

                    :appointment_date,

                    :appointment_time,

                    :gcash_ref,

                    :gcash_receipt,

                    :payment_method,

                    :payment_status,

                    :booking_status,

                    :remarks

                )

        ");



        $insert_stmt->execute([

            ':user_id' => $user_id,

            ':pet_id' => $pet_id,

            ':service' => $final_service_name,

            ':appointment_date' => $date,

            ':appointment_time' => $time,

            ':gcash_ref' => $gcash_ref,

            ':gcash_receipt' => $receipt_filename,

            ':payment_method' => $payment_method,

            ':payment_status' => $payment_status,

            ':booking_status' => $booking_status,

            ':remarks' => $remarks

        ]);



        // Push Admin Notification

        $admin_msg = $full_name . " booked " . $pet_name .

                     " via GCash. Ref: " . $gcash_ref .

                     ". Pending Verification.";



        $admin_notif_stmt = $pdo->prepare("

            INSERT INTO admin_notifications (message)

            VALUES (:message)

        ");



        $admin_notif_stmt->execute([

            ':message' => $admin_msg

        ]);



        // --- 7. IPROG SMS NOTIFICATION (Customer Alert) ---

        if (preg_match('/^09[0-9]{9}$/', (string)$contact_number)) {

            $iprog_helper = __DIR__ . '/includes/iprog_sms.php';



            if (file_exists($iprog_helper)) {

                require_once $iprog_helper;



                if (function_exists('sendIPROGSMS')) {

                    $formatted_time = date("g:i A", strtotime($time));

                    $sms_message = "Hi $full_name, we received your GCash booking request for $pet_name on $date at $formatted_time. Please wait for the admin to verify your payment. Thank you! - Boogie's Pet Care";



                    $sms_result = sendIPROGSMS($contact_number, $sms_message);



                    if (empty($sms_result['success'] ?? false)) {

                        error_log("IPROG SMS failed for booking notification.");

                    }

                } else {

                    error_log("sendIPROGSMS() function not found.");

                }

            } else {

                error_log("IPROG SMS helper not found: " . $iprog_helper);

            }

        }



        echo "<script>

                alert('Booking Request Submitted! Please wait for admin verification.');

                window.location.href='bookings.php';

              </script>";

        exit();



    } catch (Throwable $e) {

        error_log("Process booking failed: " . $e->getMessage());

        http_response_code(500);
        echo "We could not save your booking right now. Please try again. If the problem continues, contact the clinic."; 

    }

} else {

    header("Location: dashboard.php");

    exit();

}

?>