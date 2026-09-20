<?php
session_start();
require_once '../db_supabase.php';

// 1. SECURITY CHECK
$current_role = strtolower(trim($_SESSION['role'] ?? ''));

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !in_array($current_role, ['admin', 'supervisor', 'staff'], true)
) {
    header("Location: stafflogin.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // 2. KUNIN ANG MGA INPUTS MULA SA FORM
        $owner_name = trim($_POST['owner_name'] ?? '');
        $contact    = trim($_POST['contact'] ?? '');
        $pet_name   = trim($_POST['pet_name'] ?? '');
        $pet_type   = trim($_POST['pet_type'] ?? '');
        $breed      = trim($_POST['breed'] ?? 'Unknown');
        $service    = trim($_POST['service'] ?? '');
        $vet_doctor = trim($_POST['vet_doctor'] ?? '');
        $service_fee = (float)($_POST['service_fee'] ?? 0);

        if ($owner_name === '' || $pet_name === '' || $pet_type === '' || $service === '') {
            throw new RuntimeException('Missing required walk-in information.');
        }

        $user_id = 0;
        $pet_id = 0;

        date_default_timezone_set('Asia/Manila');
        $appointment_date = date('Y-m-d');
        $appointment_time = date('H:i:s');

        // =========================================================================
        // CAPACITY CHECKER (Vet = 6, Grooming = 10)
        // =========================================================================
        if (strpos($service, 'Vet Services') === 0) {
            $cap_check = $pdo->prepare("
                SELECT COUNT(*) AS count
                FROM appointments
                WHERE appointment_date = :appointment_date
                  AND service ILIKE :service_pattern
                  AND booking_status <> 'Cancelled'
            ");

            $cap_check->execute([
                ':appointment_date' => $appointment_date,
                ':service_pattern' => 'Vet Services%'
            ]);

            $cap_row = $cap_check->fetch(PDO::FETCH_ASSOC);

            if ((int)($cap_row['count'] ?? 0) >= 6) {
                die("<script>alert('Walk-in Failed: Vet Clinic is already fully booked for today (Max 6).'); window.history.back();</script>");
            }
        } elseif (strpos($service, 'Grooming') === 0) {
            $cap_check = $pdo->prepare("
                SELECT COUNT(*) AS count
                FROM appointments
                WHERE appointment_date = :appointment_date
                  AND service ILIKE :service_pattern
                  AND booking_status <> 'Cancelled'
            ");

            $cap_check->execute([
                ':appointment_date' => $appointment_date,
                ':service_pattern' => 'Grooming%'
            ]);

            $cap_row = $cap_check->fetch(PDO::FETCH_ASSOC);

            if ((int)($cap_row['count'] ?? 0) >= 10) {
                die("<script>alert('Walk-in Failed: Grooming is already fully booked for today (Max 10).'); window.history.back();</script>");
            }
        }

        // =========================================================================
        // QUICK AUTO-REGISTER LOGIC
        // =========================================================================

        $check_user = $pdo->prepare("
            SELECT id
            FROM users
            WHERE full_name = :full_name
            LIMIT 1
        ");
        $check_user->execute([
            ':full_name' => $owner_name
        ]);

        $u_row = $check_user->fetch(PDO::FETCH_ASSOC);

        if ($u_row) {
            $user_id = (int)$u_row['id'];

            $update_user = $pdo->prepare("
                UPDATE users
                SET contact_number = :contact
                WHERE id = :user_id
            ");
            $update_user->execute([
                ':contact' => $contact,
                ':user_id' => $user_id
            ]);
        } else {
            $dummy_email = "walkin_" . time() . "_" . random_int(100, 999) . "@guest.local";
            $dummy_pass = password_hash("boogieswalkin", PASSWORD_DEFAULT);

            $insert_user = $pdo->prepare("
                INSERT INTO users
                (full_name, contact_number, email, password, role)
                VALUES
                (:full_name, :contact, :email, :password, 'customer')
                RETURNING id
            ");

            $insert_user->execute([
                ':full_name' => $owner_name,
                ':contact' => $contact,
                ':email' => $dummy_email,
                ':password' => $dummy_pass
            ]);

            $user_id = (int)$insert_user->fetchColumn();
        }

        // =========================================================================
        // PET CHECK / CREATE
        // =========================================================================

        $check_pet = $pdo->prepare("
            SELECT id
            FROM pets
            WHERE name = :pet_name
              AND owner_id = :owner_id
            LIMIT 1
        ");
        $check_pet->execute([
            ':pet_name' => $pet_name,
            ':owner_id' => $user_id
        ]);

        $p_row = $check_pet->fetch(PDO::FETCH_ASSOC);

        if ($p_row) {
            $pet_id = (int)$p_row['id'];
        } else {
            $insert_pet = $pdo->prepare("
                INSERT INTO pets
                (owner_id, name, pet_type, breed, gender)
                VALUES
                (:owner_id, :name, :pet_type, :breed, 'Male')
                RETURNING id
            ");

            $insert_pet->execute([
                ':owner_id' => $user_id,
                ':name' => $pet_name,
                ':pet_type' => $pet_type,
                ':breed' => $breed
            ]);

            $pet_id = (int)$insert_pet->fetchColumn();
        }

        // =========================================================================
        // INSERT APPOINTMENT
        // =========================================================================

        $appointment_type = 'Walk-in';
        $booking_status   = 'Confirmed';
        $payment_status   = 'Paid';
        $payment_method   = 'Cash';
        $total_price      = $service_fee;

        $insert_query = $pdo->prepare("
            INSERT INTO appointments
            (
                user_id,
                pet_id,
                service,
                vet_doctor,
                appointment_date,
                appointment_time,
                appointment_type,
                booking_status,
                payment_status,
                payment_method,
                service_fee,
                total_price
            )
            VALUES
            (
                :user_id,
                :pet_id,
                :service,
                :vet_doctor,
                :appointment_date,
                :appointment_time,
                :appointment_type,
                :booking_status,
                :payment_status,
                :payment_method,
                :service_fee,
                :total_price
            )
        ");

        $insert_query->execute([
            ':user_id' => $user_id,
            ':pet_id' => $pet_id,
            ':service' => $service,
            ':vet_doctor' => $vet_doctor,
            ':appointment_date' => $appointment_date,
            ':appointment_time' => $appointment_time,
            ':appointment_type' => $appointment_type,
            ':booking_status' => $booking_status,
            ':payment_status' => $payment_status,
            ':payment_method' => $payment_method,
            ':service_fee' => $service_fee,
            ':total_price' => $total_price
        ]);

        $_SESSION['alert_msg'] = "Walk-in saved! Payment marked as Paid (Cash).";
        header("Location: managebooking.php?status=Confirmed");
        exit();

    } catch (Throwable $e) {
        error_log("Process walk-in failed: " . $e->getMessage());
        die("Database Error: " . htmlspecialchars($e->getMessage()) . "<br><a href='managebooking.php'>Go Back</a>");
    }
} else {
    header("Location: managebooking.php");
    exit();
}
?>