<?php
session_start();
include 'db_supabase.php';

// Set timezone to Philippines
date_default_timezone_set('Asia/Manila');


/**
 * Convert a stored pet weight to the size bracket used by booking prices.
 * Supports both current numeric kg values (e.g. 5.2) and legacy size labels.
 */
function bookingSizeFromWeight($weight): ?string
{
    $value = trim((string)($weight ?? ''));
    $allowedSizes = [
        'Small (1-5kg)',
        'Medium (6-10kg)',
        'Large (11-15kg)',
        'Extra Large (16-20kg)',
        'XXL Large (21-25kg)'
    ];

    if ($value === '') {
        return null;
    }

    if (in_array($value, $allowedSizes, true)) {
        return $value;
    }

    if (!preg_match('/(-?\\d+(?:\\.\\d+)?)/', $value, $matches)) {
        return null;
    }

    $kg = (float)$matches[1];
    if ($kg <= 0 || $kg > 25) {
        return null;
    }

    if ($kg <= 5) return 'Small (1-5kg)';
    if ($kg <= 10) return 'Medium (6-10kg)';
    if ($kg <= 15) return 'Large (11-15kg)';
    if ($kg <= 20) return 'Extra Large (16-20kg)';
    return 'XXL Large (21-25kg)';
}

// Access Control
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

// Get user info
$full_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : (isset($_SESSION['full_name']) ? $_SESSION['full_name'] : 'User');
$user_id = (int)($_SESSION['user_id'] ?? 0);
if ($user_id <= 0) {
    header('Location: login.php');
    exit();
}

// One CSRF token is used by the booking form.
if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$success_msg = '';
$error_msg = '';

// --- FETCH USER DETAILS FOR HEADER (Profile Pic) ---
$profile_pic = '';
try {
    $stmt_user = $pdo->prepare("SELECT profile_image FROM users WHERE id = :id LIMIT 1");
    $stmt_user->execute([':id' => $user_id]);
    $row = $stmt_user->fetch();
    if ($row) {
        $profile_pic = $row['profile_image'] ?? '';
    }
} catch (PDOException $e) {
    $profile_pic = '';
}

// --- FETCH UNREAD NOTIFICATIONS ---
$unread_count = 0;
$notifications = [];
try {
    $stmt_notif = $pdo->prepare("SELECT COUNT(*) AS unread_count FROM notifications WHERE user_id = :user_id AND is_read = 0");
    $stmt_notif->execute([':user_id' => $user_id]);
    $row = $stmt_notif->fetch();
    if ($row) {
        $unread_count = (int)($row['unread_count'] ?? 0);
    }
} catch (PDOException $e) {
    $unread_count = 0;
}

// Fetch latest 5 notifications for dropdown
try {
    $stmt_notif_list = $pdo->prepare("SELECT id, message, created_at, is_read FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 5");
    $stmt_notif_list->execute([':user_id' => $user_id]);
    $notifications = $stmt_notif_list->fetchAll() ?: [];
} catch (PDOException $e) {
    $notifications = [];
}

// --- CATCH THE CATEGORY FROM THE URL ---
$pre_selected_category = isset($_GET['category']) ? trim((string)$_GET['category']) : '';

// Fetch user's registered pets
$user_pets = [];
try {
    $stmt_pets = $pdo->prepare("SELECT id, name, pet_type, gender, weight FROM pets WHERE owner_id = :owner_id ORDER BY id DESC");
    $stmt_pets->execute([':owner_id' => $user_id]);
    $user_pets = $stmt_pets->fetchAll() ?: [];
} catch (PDOException $e) {
    $user_pets = [];
}

// Protect Backend
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $receipt_filename = '';
    $posted_csrf = (string)($_POST['csrf_token'] ?? '');
    $session_csrf = (string)($_SESSION['csrf_token'] ?? '');
    if ($session_csrf === '' || $posted_csrf === '' || !hash_equals($session_csrf, $posted_csrf)) {
        $error_msg = 'Your form session expired. Refresh the page and try again.';
    }

    $registered_pet_id = filter_var($_POST['registered_pet_id'] ?? 0, FILTER_VALIDATE_INT);
    $registered_pet_id = ($registered_pet_id !== false && $registered_pet_id > 0) ? (int)$registered_pet_id : 0;
    $registered_pet_row = null;

    $pet_name = trim((string)($_POST['pet_name'] ?? ''));
    $pet_gender = trim((string)($_POST['pet_gender'] ?? ''));
    $pet_type = trim((string)($_POST['pet_type'] ?? ''));
    $pet_size = trim((string)($_POST['pet_size'] ?? ''));

    // For a selected registered pet, trust the record owned by the current customer
    // and derive the price bracket from its saved weight when that weight is available.
    if (empty($error_msg) && $registered_pet_id > 0) {
        try {
            $registered_pet_stmt = $pdo->prepare("
                SELECT id, name, pet_type, gender, weight
                FROM pets
                WHERE id = :pet_id AND owner_id = :owner_id
                LIMIT 1
            ");
            $registered_pet_stmt->execute([
                ':pet_id' => $registered_pet_id,
                ':owner_id' => $user_id
            ]);
            $registered_pet_row = $registered_pet_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$registered_pet_row) {
                $error_msg = 'The selected pet could not be found in your account. Please refresh and select it again.';
            } else {
                $pet_name = trim((string)($registered_pet_row['name'] ?? ''));
                $pet_type = trim((string)($registered_pet_row['pet_type'] ?? ''));
                $pet_gender = trim((string)($registered_pet_row['gender'] ?? ''));
                $stored_pet_weight = trim((string)($registered_pet_row['weight'] ?? ''));

                if ($stored_pet_weight !== '') {
                    $derived_pet_size = bookingSizeFromWeight($stored_pet_weight);
                    if ($derived_pet_size === null) {
                        $error_msg = 'The selected pet weight is outside the supported booking size ranges (up to 25 kg). Please contact the clinic.';
                    } else {
                        $pet_size = $derived_pet_size;
                    }
                }
            }
        } catch (PDOException $e) {
            error_log('Registered pet lookup failed for booking user #' . $user_id . ': ' . $e->getMessage());
            $error_msg = 'Unable to load the selected pet details. Please try again.';
        }
    }

    $service_category = trim((string)($_POST['service_category'] ?? ''));
    $specific_service = trim((string)($_POST['specific_service'] ?? ''));
    $haircut_style = trim((string)($_POST['haircut_style'] ?? ''));

    $appointment_date = trim((string)($_POST['appointment_date'] ?? ''));
    $appointment_time = trim((string)($_POST['appointment_time'] ?? ''));
    $remarks = trim((string)($_POST['remarks'] ?? ''));

    $gcash_ref = trim((string)($_POST['gcash_ref'] ?? ''));

    // Check Terms and Conditions
    if (!isset($_POST['agree_terms'])) {
        $error_msg = "Error: You must agree to the Terms and Conditions before booking.";
    }

    // Backend Field Validation
    if (empty($error_msg) && (
        $pet_name === '' ||
        $pet_type === '' ||
        $pet_gender === '' ||
        $pet_size === '' ||
        $service_category === '' ||
        $specific_service === '' ||
        $appointment_date === '' ||
        $appointment_time === '' ||
        $gcash_ref === ''
    )) {
        $error_msg = "Error: All required information (including GCash Reference Number) must be filled out.";
    }

    // Validate values on the server rather than relying on dropdown restrictions.
    $allowed_pet_types = ['Dog', 'Cat'];
    $allowed_genders = ['Male', 'Female'];
    $allowed_sizes = ['Small (1-5kg)', 'Medium (6-10kg)', 'Large (11-15kg)', 'Extra Large (16-20kg)', 'XXL Large (21-25kg)'];
    $allowed_categories = ['Grooming', 'Vet Services', 'Pet Hotel'];
    $allowed_times = ['10:00:00', '11:00:00', '12:00:00', '13:00:00', '14:00:00', '15:00:00', '16:00:00', '17:00:00'];
    $allowed_haircuts = ['Puppy Cut', 'Summer Cut', 'Shave Down', 'Bear Cut', 'Poodle Cut'];

    if (empty($error_msg) && !in_array($pet_type, $allowed_pet_types, true)) {
        $error_msg = 'Please select a valid pet type.';
    } elseif (empty($error_msg) && !in_array($pet_gender, $allowed_genders, true)) {
        $error_msg = 'Please select a valid pet gender.';
    } elseif (empty($error_msg) && !in_array($pet_size, $allowed_sizes, true)) {
        $error_msg = 'Please select a valid pet size.';
    } elseif (empty($error_msg) && !in_array($service_category, $allowed_categories, true)) {
        $error_msg = 'Please select a valid service category.';
    } elseif (empty($error_msg) && strlen($pet_name) > 100) {
        $error_msg = 'Pet name must be 100 characters or fewer.';
    } elseif (empty($error_msg) && strlen($gcash_ref) > 100) {
        $error_msg = 'GCash reference number is too long.';
    } elseif (empty($error_msg) && !preg_match('/^[A-Za-z0-9 -]{4,100}$/', $gcash_ref)) {
        $error_msg = 'Please enter a valid GCash reference number.';
    } elseif (empty($error_msg) && strlen($remarks) > 1000) {
        $error_msg = 'Remarks must be 1,000 characters or fewer.';
    }

    // --- SERVER-SIDE PRICE RECALCULATION ---
    $service_fee = 0;
    $pricing_data = [
        "Dog" => [
            "Grooming" => [
                "Basic Pet Grooming" => ["Small (1-5kg)" => 400, "Medium (6-10kg)" => 500, "Large (11-15kg)" => 650, "Extra Large (16-20kg)" => 850, "XXL Large (21-25kg)" => 1000],
                "Full Grooming Package" => ["Small (1-5kg)" => 450, "Medium (6-10kg)" => 550, "Large (11-15kg)" => 700, "Extra Large (16-20kg)" => 900, "XXL Large (21-25kg)" => 1100],
                "Bath & Blow Dry" => ["Small (1-5kg)" => 300, "Medium (6-10kg)" => 350, "Large (11-15kg)" => 550, "Extra Large (16-20kg)" => 750, "XXL Large (21-25kg)" => 950]
            ],
            "Vet Services" => [
                "Deworming" => ["Small (1-5kg)" => 200, "Medium (6-10kg)" => 250, "Large (11-15kg)" => 300, "Extra Large (16-20kg)" => 350, "XXL Large (21-25kg)" => 450],
                "Vaccination - Anti Rabies" => ["default" => 300],
                "Vaccination - 5 in 1" => ["default" => 450],
                "Vaccination - 6 in 1" => ["default" => 600],
                "Vaccination - 8 in 1" => ["default" => 750]
            ],
            "Pet Hotel" => [
                "Pet Daycare (1st Hour - Succeeding fees apply)" => ["Small (1-5kg)" => 100, "Medium (6-10kg)" => 100, "Large (11-15kg)" => 150, "Extra Large (16-20kg)" => 150, "XXL Large (21-25kg)" => 200],
                "Pet Boarding (Overnight)" => ["Small (1-5kg)" => 500, "Medium (6-10kg)" => 500, "Large (11-15kg)" => 600, "Extra Large (16-20kg)" => 600, "XXL Large (21-25kg)" => 800]
            ]
        ],
        "Cat" => [
            "Grooming" => [
                "Cat Grooming (Basic)" => ["Small (1-5kg)" => 550, "Medium (6-10kg)" => 650, "Large (11-15kg)" => 750, "Extra Large (16-20kg)" => 850, "XXL Large (21-25kg)" => 950],
                "Cat Bath & Blow Dry" => ["Small (1-5kg)" => 400, "Medium (6-10kg)" => 500, "Large (11-15kg)" => 600, "Extra Large (16-20kg)" => 700, "XXL Large (21-25kg)" => 800]
            ],
            "Vet Services" => [
                "Deworming" => ["Small (1-5kg)" => 200, "Medium (6-10kg)" => 250, "Large (11-15kg)" => 300, "Extra Large (16-20kg)" => 350, "XXL Large (21-25kg)" => 450],
                "Vaccination - Anti Rabies" => ["default" => 300],
                "Vaccination - 4 in 1 (Cats)" => ["default" => 900]
            ],
            "Pet Hotel" => [
                "Pet Daycare (1st Hour - Succeeding fees apply)" => ["default" => 150],
                "Pet Boarding (Overnight)" => ["default" => 500]
            ]
        ]
    ];

    if (isset($pricing_data[$pet_type][$service_category][$specific_service])) {
        $service_prices = $pricing_data[$pet_type][$service_category][$specific_service];
        if (isset($service_prices['default'])) {
            $service_fee = (float)$service_prices['default'];
        } elseif (isset($service_prices[$pet_size])) {
            $service_fee = (float)$service_prices[$pet_size];
        }
    }

    if (empty($error_msg) && $service_fee <= 0) {
        $error_msg = 'The selected service and pet size are not valid. Please choose the service again.';
    }
    if (empty($error_msg) && $specific_service === 'Full Grooming Package' && !in_array($haircut_style, $allowed_haircuts, true)) {
        $error_msg = 'Please choose a valid haircut style for the Full Grooming Package.';
    }

    $date_obj = DateTimeImmutable::createFromFormat('!Y-m-d', $appointment_date);
    $date_errors = DateTimeImmutable::getLastErrors();
    if (empty($error_msg) && (
        $date_obj === false ||
        $date_obj->format('Y-m-d') !== $appointment_date ||
        ($date_errors !== false && ($date_errors['warning_count'] > 0 || $date_errors['error_count'] > 0))
    )) {
        $error_msg = 'Please select a valid appointment date.';
    }
    if (empty($error_msg) && !in_array($appointment_time, $allowed_times, true)) {
        $error_msg = 'Please select a valid appointment time between 10:00 AM and 5:00 PM.';
    }

    // --- TIME TRAVEL & CUT-OFF VALIDATION ---
    $current_date = date('Y-m-d');
    $current_time = date('H:i:s');

    if (empty($error_msg)) {
        if ($appointment_date < $current_date) {
            $error_msg = "Error: Cannot book appointments on past dates.";
        } elseif ($appointment_date === $current_date && $current_time >= '18:00:00') {
            $error_msg = "Error: Cut-off time for same-day bookings is 6:00 PM. Please book for tomorrow or another day.";
        } elseif ($appointment_date === $current_date && $appointment_time < $current_time) {
            $error_msg = "Error: The selected time has already passed for today. Please select a different time.";
        } elseif ($appointment_time > '18:00:00') {
            $error_msg = "Shop is closed at this time. Please select an earlier time.";
        }
    }

    // --- SERVER-SIDE CAPACITY AND SLOT CHECKS ---
    // Serialize new bookings for the same service category/date to prevent
    // concurrent requests from claiming the same slot or exceeding the daily cap.
    $active_status_sql = "LOWER(BTRIM(COALESCE(booking_status, ''))) NOT IN ('cancelled', 'canceled', 'no-show', 'no show', 'noshow', 'for rescheduling')";
    if (empty($error_msg)) {
        try {
            $pdo->beginTransaction();
            $capacity_lock = $pdo->prepare("SELECT pg_advisory_xact_lock(hashtext(:lock_key)::bigint)");
            $capacity_lock->execute([
                ':lock_key' => 'boogie-capacity|' . $appointment_date . '|' . $service_category
            ]);

            $slot_sql = "SELECT COUNT(*) AS slot_count
                FROM appointments
                WHERE appointment_date = :appointment_date
                  AND appointment_time = :appointment_time
                  AND service ILIKE :service_prefix
                  AND " . $active_status_sql;
            $stmt_slot = $pdo->prepare($slot_sql);
            $stmt_slot->execute([
                ':appointment_date' => $appointment_date,
                ':appointment_time' => $appointment_time,
                ':service_prefix' => $service_category . '%'
            ]);
            $slot_result = $stmt_slot->fetch(PDO::FETCH_ASSOC) ?: [];
            if ((int)($slot_result['slot_count'] ?? 0) >= 1) {
                $error_msg = 'The selected time slot is already taken for ' . $service_category . '. Please choose another time.';
            }

            if (empty($error_msg)) {
                // Vet already has a six-per-day cap; other categories have eight visible slots.
                $daily_limit = ($service_category === 'Vet Services') ? 6 : 8;
                $daily_sql = "SELECT COUNT(*) AS total_bookings
                    FROM appointments
                    WHERE appointment_date = :appointment_date
                      AND service ILIKE :service_prefix
                      AND " . $active_status_sql;
                $stmt_daily = $pdo->prepare($daily_sql);
                $stmt_daily->execute([
                    ':appointment_date' => $appointment_date,
                    ':service_prefix' => $service_category . '%'
                ]);
                $daily_result = $stmt_daily->fetch(PDO::FETCH_ASSOC) ?: [];
                if ((int)($daily_result['total_bookings'] ?? 0) >= $daily_limit) {
                    $error_msg = ($service_category === 'Vet Services')
                        ? 'Dr. Faith Casayuran is fully booked for this date.'
                        : $service_category . ' services are fully booked for this date.';
                }
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Appointment capacity check failed: ' . $e->getMessage());
            $error_msg = 'Unable to check appointment availability. Please try again.';
        }
    }

    // --- VET SPECIFIC VALIDATION ---
    if (empty($error_msg) && $service_category === 'Vet Services') {
        $day_of_week = (int)date('N', strtotime($appointment_date));
        if ($day_of_week === 3 || $day_of_week === 6) {
            $error_msg = 'Dr. Faith Casayuran is not available on Wednesdays and Saturdays.';
        }
    }

    // Grooming's eight-slot daily limit is enforced in the capacity check above.

    // --- GCASH RECEIPT UPLOAD HANDLING (REQUIRED) ---
    if (empty($error_msg)) {
        $receipt_file = $_FILES['gcash_receipt'] ?? null;
        if (!is_array($receipt_file) || ($receipt_file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $error_msg = 'Please upload a screenshot of your GCash receipt.';
        } elseif (($receipt_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error_msg = 'Error uploading receipt image. Please try again.';
        } else {
            $tmp_path = (string)($receipt_file['tmp_name'] ?? '');
            $file_size = (int)($receipt_file['size'] ?? 0);
            $extension = strtolower(pathinfo((string)($receipt_file['name'] ?? ''), PATHINFO_EXTENSION));
            $allowed_mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
            $detected_mime = '';
            if ($tmp_path !== '' && is_uploaded_file($tmp_path) && class_exists('finfo')) {
                $file_info = new finfo(FILEINFO_MIME_TYPE);
                $detected_mime = (string)$file_info->file($tmp_path);
            }
            $image_info = ($tmp_path !== '' && is_uploaded_file($tmp_path)) ? @getimagesize($tmp_path) : false;

            if ($file_size <= 0 || $file_size > 5 * 1024 * 1024) {
                $error_msg = 'Receipt image must be no larger than 5 MB.';
            } elseif (!isset($allowed_mimes[$extension]) || $detected_mime !== $allowed_mimes[$extension] || $image_info === false) {
                $error_msg = 'Invalid receipt image. Only genuine JPG and PNG images are allowed.';
            } else {
                $upload_dir = __DIR__ . '/uploads';
                if (!is_dir($upload_dir) && !@mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
                    $error_msg = 'Receipt storage is unavailable. Please try again later.';
                } else {
                    $receipt_filename = 'receipt_' . time() . '_' . $user_id . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
                    if (!move_uploaded_file($tmp_path, $upload_dir . '/' . $receipt_filename)) {
                        $receipt_filename = '';
                        $error_msg = 'Failed to save the receipt image. Please try again.';
                    }
                }
            }
        }
    }

    if (empty($error_msg)) {
        try {
            // 1. Use the selected registered pet directly, without changing its saved
            // numeric weight into a pricing bracket. Otherwise find/create by name.
            if ($registered_pet_id > 0 && $registered_pet_row) {
                $pet_id = (int)$registered_pet_row['id'];
            } else {
                $stmt = $pdo->prepare("SELECT id FROM pets WHERE owner_id = :owner_id AND name = :name LIMIT 1");
                $stmt->execute([
                    ':owner_id' => $user_id,
                    ':name' => $pet_name
                ]);
                $pet = $stmt->fetch();

                if ($pet) {
                    // Preserve existing pet-profile data, especially numeric weight.
                    $pet_id = (int)$pet['id'];
                } else {
                    $insert_pet = $pdo->prepare("INSERT INTO pets (owner_id, name, pet_type, gender, weight) VALUES (:owner_id, :name, :pet_type, :gender, :weight) RETURNING id");
                    $insert_pet->execute([
                        ':owner_id' => $user_id,
                        ':name' => $pet_name,
                        ':pet_type' => $pet_type,
                        ':gender' => $pet_gender,
                        ':weight' => $pet_size
                    ]);
                    $new_pet = $insert_pet->fetch();
                    $pet_id = $new_pet ? (int)$new_pet['id'] : 0;
                }
            }

            if ($pet_id <= 0) {
                throw new RuntimeException('Unable to create or find the pet record.');
            }

            // 2. Format Service Name
            $final_service_name = $service_category . " - " . $specific_service;
            if ($haircut_style !== '' && $specific_service === 'Full Grooming Package') {
                $final_service_name .= " (" . $haircut_style . ")";
            }

            // 3. Insert Appointment with GCash Details
            $payment_method = 'GCash';
            $payment_status = 'Pending Verification';
            $booking_status = 'Pending';

            $insert_appt = $pdo->prepare("INSERT INTO appointments (user_id, pet_id, service, appointment_date, appointment_time, service_fee, total_price, gcash_ref, gcash_receipt, payment_method, payment_status, booking_status, remarks) VALUES (:user_id, :pet_id, :service, :appointment_date, :appointment_time, :service_fee, :total_price, :gcash_ref, :gcash_receipt, :payment_method, :payment_status, :booking_status, :remarks) RETURNING id");
            $insert_appt->execute([
                ':user_id' => $user_id,
                ':pet_id' => $pet_id,
                ':service' => $final_service_name,
                ':appointment_date' => $appointment_date,
                ':appointment_time' => $appointment_time,
                ':service_fee' => $service_fee,
                ':total_price' => $service_fee,
                ':gcash_ref' => $gcash_ref,
                ':gcash_receipt' => $receipt_filename,
                ':payment_method' => $payment_method,
                ':payment_status' => $payment_status,
                ':booking_status' => $booking_status,
                ':remarks' => $remarks
            ]);
            $appointment_row = $insert_appt->fetch();
            $appointment_id = $appointment_row ? (int)$appointment_row['id'] : 0;

            if ($appointment_id <= 0) {
                throw new RuntimeException('The appointment could not be created.');
            }

            // --- START: AUTOMATIC TASK FOR VET ---
            // Task creation is secondary; a task-table issue must not discard a valid booking.
            if ($service_category === 'Vet Services') {
                $pdo->exec('SAVEPOINT vet_task_savepoint');
                try {
                    $formatted_time = date("g:i A", strtotime($appointment_time));
                    $task_msg = "Upcoming Appointment: " . $pet_name . " (" . $specific_service . ") on " . $appointment_date . " at " . $formatted_time;
                    $vet_staff_name = "Dr. Faith Casayuran";
                    $stmt_task = $pdo->prepare("INSERT INTO tasks (staff_name, task_text) VALUES (:staff_name, :task_text)");
                    $stmt_task->execute([
                        ':staff_name' => $vet_staff_name,
                        ':task_text' => $task_msg
                    ]);
                    $pdo->exec('RELEASE SAVEPOINT vet_task_savepoint');
                } catch (PDOException $task_error) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT vet_task_savepoint');
                    $pdo->exec('RELEASE SAVEPOINT vet_task_savepoint');
                    error_log('Vet task creation failed for booking user #' . $user_id . ': ' . $task_error->getMessage());
                }
            }

            // --- START: ADMIN NOTIFICATION ---
            // A failed optional alert must not roll back an otherwise valid booking.
            $pdo->exec('SAVEPOINT admin_notif_savepoint');
            try {
                $admin_msg = $full_name . " booked a new appointment for " . $pet_name . " via GCash. Pending Payment Verification.";
                $stmt_admin_notif = $pdo->prepare("INSERT INTO admin_notifications (message) VALUES (:message)");
                $stmt_admin_notif->execute([':message' => $admin_msg]);
                $pdo->exec('RELEASE SAVEPOINT admin_notif_savepoint');
            } catch (PDOException $notif_error) {
                $pdo->exec('ROLLBACK TO SAVEPOINT admin_notif_savepoint');
                $pdo->exec('RELEASE SAVEPOINT admin_notif_savepoint');
                error_log('Admin notification insert failed after booking for user #' . $user_id . ': ' . $notif_error->getMessage());
            }

            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            header("Location: bookings.php?msg=success");
            exit();
        } catch (PDOException | RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Appointment booking failed for user #' . $user_id . ': ' . $e->getMessage());
            $error_msg = 'Unable to complete your booking right now. Please verify the details and try again.';
        }
    }
}

// Roll back an open capacity transaction and remove any receipt if booking failed.
if ($error_msg !== '') {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (!empty($receipt_filename)) {
        $orphan_path = __DIR__ . '/uploads/' . basename($receipt_filename);
        if (is_file($orphan_path)) {
            @unlink($orphan_path);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Appointment | Boogie's Pet Care Services</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>

        :root {
            --brand-blue: #001f3f;
            --brand-blue-2: #0b3b66;
            --brand-yellow: #ffcc00;
            --brand-yellow-soft: #fff7d6;
            --brand-purple: #8b4bd6;
            --brand-purple-dark: #7136b4;
            --page-bg: #f5f8fb;
            --white: #ffffff;
            --text: #17324d;
            --muted: #6b7c8f;
            --line: #e3eaf1;
            --soft: #f8fafc;
            --success: #168553;
            --danger: #c73b47;
            --shadow: 0 10px 30px rgba(0,31,63,.06);
            --shadow-lg: 0 18px 42px rgba(0,31,63,.09);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        html { scroll-behavior: smooth; }

        body {
            min-height: 100vh;
            background: var(--page-bg);
            color: var(--text);
            line-height: 1.6;
            overflow-y: scroll;
            overflow-x: hidden;
        }

        /* ===== HEADER ===== */
        .promo-bar {
            background: var(--brand-blue);
            color: #fff;
            text-align: center;
            padding: 7px 16px;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: .2px;
            border-top: 3px solid var(--brand-yellow);
        }

        .promo-bar i {
            color: var(--brand-yellow);
            margin-right: 7px;
        }

        header {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: rgba(255,255,255,.97);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--line);
            box-shadow: 0 4px 18px rgba(0,0,0,.04);
        }

        .nav-top {
            max-width: 1320px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 25px;
            padding: 14px 28px;
        }

        .logo {
            display: inline-flex;
            align-items: center;
            gap: 11px;
            text-decoration: none;
            min-width: 225px;
        }

        .nav-logo-img {
            width: 50px;
            height: 50px;
            object-fit: contain;
            border-radius: 10px;
            display: block;
        }

        .logo-text {
            display: flex;
            flex-direction: column;
            line-height: 1.05;
        }

        .logo-text b {
            font-size: 20px;
            color: var(--brand-blue);
            font-weight: 800;
        }

        .logo-text span {
            margin-top: 3px;
            color: #8c9aae;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 1.2px;
        }

        .user-controls {
            display: flex;
            align-items: center;
            gap: 18px;
        }

        .notification-wrapper,
        .profile-wrapper {
            position: relative;
        }

        .notification-bell {
            position: relative;
            width: 42px;
            height: 42px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: #fff;
            color: var(--brand-blue);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .notification-bell:hover,
        .profile-trigger:hover {
            background: #f8fafc;
        }

        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--danger);
            color: #fff;
            font-size: 9px;
            font-weight: 800;
            border: 2px solid #fff;
            border-radius: 999px;
        }

        .profile-trigger {
            min-height: 42px;
            padding: 4px 8px 4px 5px;
            display: flex;
            align-items: center;
            gap: 9px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: #fff;
            color: var(--text);
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
        }

        .profile-img {
            width: 34px !important;
            height: 34px !important;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--brand-blue) !important;
        }

        .dropdown-menu {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: 270px;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 14px;
            box-shadow: var(--shadow-lg);
            display: none;
            flex-direction: column;
            z-index: 1001;
            overflow: hidden;
        }

        .dropdown-menu.active { display: flex; }

        .dropdown-header {
            padding: 14px 16px;
            background: #f8fafc;
            border-bottom: 1px solid var(--line);
            color: var(--muted);
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .7px;
        }

        .dropdown-item {
            display: block;
            padding: 12px 16px;
            text-decoration: none;
            color: var(--text);
            font-size: 12px;
            border-bottom: 1px solid #eef2f5;
            transition: .18s;
        }

        .dropdown-item:hover {
            background: #f8fafc;
            color: var(--brand-blue);
        }

        .dropdown-item i {
            width: 18px;
            margin-right: 7px;
            text-align: center;
        }

        .dropdown-item.unread {
            background: #eef6ff;
            font-weight: 700;
        }

        .dropdown-item:last-child { border-bottom: 0; }
        .view-all-link { text-align: center; color: var(--brand-blue); font-weight: 800; }

        /* ===== BOOKING PAGE ===== */
        main {
            width: min(1120px, 92%);
            margin: 0 auto;
            padding: 42px 0 75px;
        }

        .page-intro { margin-bottom: 24px; }

        .back-nav { margin-bottom: 14px; }

        .back-nav a {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            text-decoration: none;
            color: #748396;
            font-size: 11px;
            font-weight: 700;
        }

        .back-nav a:hover { color: var(--brand-blue); }

        .page-title-row {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 25px;
        }

        .page-kicker {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: var(--brand-yellow-soft);
            border: 1px solid #ffe594;
            color: #8c6800;
            padding: 7px 12px;
            border-radius: 999px;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .7px;
            font-weight: 800;
            margin-bottom: 10px;
        }

        .page-title h1 {
            color: var(--brand-blue);
            font-size: 33px;
            line-height: 1.2;
            font-weight: 800;
            letter-spacing: -.5px;
        }

        .page-title p {
            color: var(--muted);
            font-size: 13px;
            margin-top: 6px;
        }

        .booking-summary-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 13px;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 12px;
            color: #66798b;
            font-size: 10px;
            font-weight: 700;
            white-space: nowrap;
        }

        .booking-summary-pill i { color: var(--success); }

        .booking-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 285px;
            gap: 22px;
            align-items: start;
        }

        .booking-card,
        .side-card {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 20px;
            box-shadow: var(--shadow);
        }

        .booking-card { padding: 30px; }
        .side-card {
            padding: 22px;
            position: sticky;
            top: 115px;
        }

        /* Original form controls, restyled */
        .booking-card > form {
            width: 100%;
        }

        .form-group { margin-bottom: 17px; }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .booking-card label {
            display: block;
            color: var(--text);
            font-size: 11px;
            font-weight: 800;
            margin-bottom: 7px;
        }

        .booking-card input[type="text"],
        .booking-card input[type="date"],
        .booking-card select,
        .booking-card textarea,
        .booking-card input[type="file"] {
            width: 100%;
            padding: 11px 13px;
            border: 1px solid #dce5ee;
            border-radius: 11px;
            background: #fbfcfe;
            color: var(--text);
            font-family: inherit;
            font-size: 12px;
            outline: none;
            transition: border-color .2s, box-shadow .2s, background .2s;
        }

        .booking-card input:focus,
        .booking-card select:focus,
        .booking-card textarea:focus {
            border-color: #9bb6cc;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(0,31,63,.05);
        }

        .booking-card input[readonly] {
            background: #eef1f4;
        }

        .booking-card textarea {
            resize: vertical;
            min-height: 90px;
        }

        .booking-container,
        .header-section {
            all: unset;
        }

        /* Original header-section becomes compact form heading if still present. */
        .booking-card .header-section {
            display: block;
            padding: 0 0 18px;
            margin: 0 0 22px;
            border-bottom: 1px solid var(--line);
            text-align: left;
        }

        .booking-card .header-section h1 {
            color: var(--brand-blue);
            font-size: 19px;
            font-weight: 800;
            margin: 0 0 4px;
        }

        .booking-card .header-section p {
            color: var(--muted);
            font-size: 11px;
        }

        .booking-card .walkin-notice {
            background: #eef3ff;
            color: #4b45a4;
            padding: 11px 12px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
            margin-top: 13px;
            border: 1px dashed #8d84e8;
        }

        .booking-card .alert {
            padding: 12px 14px;
            border-radius: 11px;
            margin-bottom: 19px;
            font-size: 11px;
        }

        .booking-card .alert-error {
            background: #fff0f1;
            color: #b72f3a;
            border: 1px solid #f3c0c5;
        }

        .booking-card .alert-warning {
            background: #fffbeb;
            color: #92400e;
            border-left: 4px solid #f59e0b;
            border-radius: 11px;
            padding: 13px 14px;
        }

        .booking-card .vet-info-box {
            background: #edf8ff;
            color: #0d6d99;
            padding: 11px 12px;
            border-radius: 10px;
            font-size: 11px;
            margin-bottom: 17px;
            border: 1px dashed #8bcfee;
            text-align: center;
        }

        .booking-card .price-display {
            background: linear-gradient(135deg,#fffbea,#fff);
            border: 1px solid #ffe08a;
            border-radius: 14px;
            padding: 18px;
            text-align: center;
            margin-bottom: 20px;
        }

        .booking-card .price-display p {
            margin: 0;
            color: #7a8896;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .6px;
            font-weight: 800;
        }

        .booking-card .price-display h2 {
            margin: 4px 0 0;
            color: var(--brand-blue);
            font-size: 29px;
            font-weight: 800;
        }

        .booking-card .gcash-section {
            background: linear-gradient(145deg, #fffdf1 0%, #eef7ff 100%);
            padding: 20px;
            border-radius: 16px;
            border: 1px solid #d7e5f2;
            box-shadow: 0 8px 24px rgba(0,31,63,.05);
            margin-bottom: 20px;
            position: relative;
            overflow: hidden;
        }

        .booking-card .gcash-section::before {
            content: "";
            position: absolute;
            width: 150px;
            height: 150px;
            top: -75px;
            right: -55px;
            background: rgba(255,204,0,.18);
            border-radius: 50%;
            pointer-events: none;
        }

        .booking-card .gcash-section h4 {
            position: relative;
            color: var(--brand-blue);
            margin-bottom: 6px;
            font-size: 15px;
            font-weight: 800;
        }

        .booking-card .gcash-section h4 i {
            color: #0b76ff;
            margin-right: 4px;
        }

        .booking-card .gcash-section p {
            position: relative;
            color: #245386;
            font-size: 10px;
            line-height: 1.6;
            margin-bottom: 14px;
        }

        .gcash-qr-card {
            position: relative;
            max-width: 360px;
            margin: 0 auto 18px;
            padding: 10px 10px 0;
            border-radius: 18px;
            background: #f5c400;
            box-shadow: 0 10px 24px rgba(0,31,63,.10);
        }

        .gcash-qr-inner {
            background: #ffffff;
            border-radius: 14px;
            padding: 15px 15px 10px;
            border: 1px solid rgba(0,31,63,.08);
        }

        .gcash-qr-title {
            text-align: center;
            color: var(--brand-blue);
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .8px;
            margin-bottom: 9px;
        }

        .gcash-qr-image {
            display: block;
            width: min(280px, 100%);
            aspect-ratio: 1 / 1;
            object-fit: contain;
            margin: 0 auto;
            padding: 6px;
            background: #fff;
            border-radius: 10px;
        }

        .gcash-qr-caption {
            margin: 8px 0 0 !important;
            text-align: center;
            color: #5f6f80 !important;
            font-size: 9px !important;
            line-height: 1.45 !important;
        }

        .gcash-scan-strip {
            margin: 10px -10px 0;
            padding: 10px 12px;
            border-radius: 0 0 14px 14px;
            background: var(--brand-blue);
            color: var(--brand-yellow);
            text-align: center;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .5px;
            text-transform: uppercase;
        }

        .gcash-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            background: #e8f1ff;
            border: 1px solid #cfe0f5;
            padding: 8px 12px;
            border-radius: 9px;
            font-weight: 800;
            color: #1e3a8a;
            margin-bottom: 14px;
            font-size: 12px;
        }

        .gcash-number i {
            color: #0b76ff;
        }

        .gcash-account-card {
            max-width: 430px;
            margin: 2px auto 18px;
            padding: 15px 18px;
            border-radius: 14px;
            background: #ffffff;
            border: 1px solid #d6e3ef;
            box-shadow: 0 6px 18px rgba(0,31,63,.06);
            text-align: center;
        }

        .gcash-account-label {
            color: #718096;
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 4px;
        }

        .gcash-account-name {
            color: var(--brand-blue);
            font-size: 16px;
            font-weight: 800;
            letter-spacing: .5px;
            margin-bottom: 8px;
        }

        .gcash-account-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            color: #1e3a8a;
            background: #e8f1ff;
            border: 1px solid #cfe0f5;
            padding: 8px 14px;
            border-radius: 9px;
            font-size: 14px;
            font-weight: 800;
            letter-spacing: .8px;
        }

        .gcash-account-number i {
            color: #0b76ff;
        }

        @media (max-width: 680px) {
            .gcash-qr-card {
                max-width: 300px;
                padding: 8px 8px 0;
                border-radius: 15px;
            }

            .gcash-qr-inner {
                padding: 12px 12px 8px;
                border-radius: 12px;
            }

            .gcash-qr-image {
                width: min(235px, 100%);
                padding: 4px;
            }

            .gcash-scan-strip {
                margin: 8px -8px 0;
                padding: 9px 8px;
                font-size: 8px;
                line-height: 1.35;
            }

            .gcash-number {
                width: 100%;
                font-size: 10px;
                line-height: 1.4;
            }

            .gcash-account-card {
                padding: 12px 13px;
            }

            .gcash-account-name {
                font-size: 14px;
            }

            .gcash-account-number {
                width: 100%;
                font-size: 11px;
                line-height: 1.4;
            }
        }

        .booking-card .terms-container {
            background: #f8fafc;
            border: 1px solid var(--line);
            padding: 14px;
            border-radius: 11px;
            margin-bottom: 18px;
            font-size: 10px;
        }

        .booking-card .terms-container label {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            color: #556779;
            font-weight: 600;
            line-height: 1.6;
            margin: 0;
            cursor: pointer;
        }

        .booking-card .terms-container input[type="checkbox"] {
            width: 17px;
            height: 17px;
            margin-top: 1px;
            cursor: pointer;
            accent-color: var(--brand-blue);
            flex: 0 0 17px;
        }

        .booking-card .terms-container span.highlight {
            color: var(--brand-blue);
            font-weight: 800;
        }

        .booking-card .btn-submit {
            width: 100%;
            border: 0;
            padding: 14px;
            border-radius: 11px;
            background: var(--brand-blue);
            color: var(--brand-yellow);
            font-family: inherit;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 8px 20px rgba(0,31,63,.13);
        }

        .booking-card .btn-submit:hover {
            background: var(--brand-blue-2);
        }

        .hidden { display: none !important; }

        /* Booking guide */
        .side-kicker {
            color: #8b99a9;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 1.1px;
            font-weight: 800;
            margin-bottom: 6px;
        }

        .side-card h3 {
            color: var(--brand-blue);
            font-size: 18px;
            font-weight: 800;
            line-height: 1.25;
        }

        .side-card > p {
            color: var(--muted);
            font-size: 10px;
            line-height: 1.6;
            margin: 7px 0 18px;
        }

        .step-list {
            display: grid;
            gap: 14px;
        }

        .step-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .step-icon {
            width: 34px;
            height: 34px;
            flex: 0 0 34px;
            border-radius: 10px;
            background: var(--brand-yellow-soft);
            color: var(--brand-blue);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }

        .step-item strong {
            display: block;
            color: var(--brand-blue);
            font-size: 10px;
            font-weight: 800;
            margin-bottom: 1px;
        }

        .step-item span {
            display: block;
            color: #7f8d9c;
            font-size: 9px;
            line-height: 1.5;
        }

        .side-note {
            margin-top: 19px;
            padding: 12px;
            border-radius: 11px;
            background: #f0f7ff;
            border: 1px solid #d9eafa;
            color: #4e6980;
            font-size: 9px;
            line-height: 1.6;
        }

        .side-note i {
            color: var(--brand-blue);
            margin-right: 5px;
        }

        /* Footer */
        footer {
            background: var(--brand-blue);
            padding: 62px 28px 30px;
            color: #fff;
            border-top: 4px solid var(--brand-yellow);
        }

        .footer-main {
            max-width: 1180px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1.5fr;
            gap: 42px;
            padding-bottom: 40px;
            border-bottom: 1px solid rgba(255,255,255,.12);
        }

        .footer-main h4 {
            color: var(--brand-yellow);
            margin-bottom: 15px;
            text-transform: uppercase;
            font-weight: 800;
            font-size: 12px;
            letter-spacing: .5px;
        }

        .footer-main p,
        .footer-main a {
            color: #cbd5e1;
            text-decoration: none;
            font-size: 12px;
            line-height: 1.7;
            display: block;
            margin-bottom: 8px;
        }

        .footer-main a:hover { color: #fff; }

        .socials {
            display: flex;
            gap: 10px;
            margin-top: 16px;
        }

        .socials a {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: rgba(255,255,255,.09);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
        }

        .socials a:hover {
            background: var(--brand-yellow);
            color: var(--brand-blue);
        }

        .gmail-compose-link {
            cursor: pointer;
        }


        .footer-bottom {
            max-width: 1180px;
            margin: 0 auto;
            padding-top: 23px;
            color: #91a1b1;
            text-align: center;
            font-size: 11px;
        }

        @media (max-width: 980px) {
            .booking-layout { grid-template-columns: 1fr; }
            .side-card { position: static; }
            .page-title-row { display: block; }
            .booking-summary-pill { margin-top: 12px; }
            .footer-main { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 680px) {
            .nav-top { flex-wrap: wrap; padding: 12px 18px; }
            .user-controls { width: 100%; justify-content: flex-end; }
            .profile-trigger span { display: none; }

            main { width: 92%; padding-top: 30px; }
            .page-title h1 { font-size: 28px; }

            .booking-card { padding: 21px; }
            .form-row { grid-template-columns: 1fr; gap: 0; }

            .footer-main { grid-template-columns: 1fr; gap: 25px; }
        }


        /* ===== EXTRA MOBILE RESPONSIVE TUNING ===== */
        @media (max-width: 1100px) {
            .nav-top {
                gap: 15px;
            }

            .logo {
                min-width: 205px;
            }

            .booking-layout {
                grid-template-columns: minmax(0, 1fr) 250px;
            }

            .booking-card {
                padding: 25px;
            }
        }

        @media (max-width: 900px) {
            .nav-top {
                flex-wrap: wrap;
                padding: 11px 20px;
            }

            .logo {
                min-width: 0;
                flex: 1 1 auto;
            }

            .user-controls {
                flex: 0 0 auto;
            }

            .booking-layout {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .side-card {
                position: static;
            }

            .page-title-row {
                align-items: flex-start;
            }
        }

        @media (max-width: 680px) {
            .promo-bar {
                padding: 6px 10px;
                font-size: 10px;
                line-height: 1.4;
            }

            header {
                position: sticky;
                top: 0;
            }

            .nav-top {
                width: 100%;
                padding: 9px 12px;
                gap: 8px;
            }

            .logo {
                width: 100%;
                gap: 8px;
            }

            .nav-logo-img {
                width: 40px;
                height: 40px;
                border-radius: 9px;
            }

            .logo-text b {
                font-size: 16px;
            }

            .logo-text span {
                font-size: 7px;
                letter-spacing: .85px;
            }

            .user-controls {
                width: 100%;
                justify-content: flex-end;
                gap: 7px;
            }

            .notification-bell,
            .profile-trigger {
                width: 40px;
                min-width: 40px;
                height: 40px;
            }

            .notification-badge {
                min-width: 17px;
                height: 17px;
                font-size: 8px;
            }

            .profile-trigger {
                justify-content: center;
                padding: 3px;
            }

            .profile-trigger span,
            .profile-trigger > .fa-chevron-down {
                display: none;
            }

            .profile-img {
                width: 32px !important;
                height: 32px !important;
            }

            .dropdown-menu {
                top: calc(100% + 8px);
                right: 0;
                width: 270px;
                max-width: calc(100vw - 18px);
            }

            .dropdown-menu[style*="width:210px"] {
                width: 210px !important;
            }

            main {
                width: calc(100% - 22px);
                padding: 24px 0 48px;
            }

            .page-intro {
                margin-bottom: 18px;
            }

            .back-nav {
                margin-bottom: 10px;
            }

            .back-nav a {
                font-size: 10px;
            }

            .page-title-row {
                display: block;
            }

            .page-kicker {
                font-size: 8px;
                padding: 6px 10px;
                margin-bottom: 8px;
            }

            .page-title h1 {
                font-size: 26px;
                line-height: 1.2;
                letter-spacing: -.35px;
            }

            .page-title p {
                font-size: 10px;
                line-height: 1.55;
            }

            .booking-summary-pill {
                width: 100%;
                margin-top: 11px;
                justify-content: center;
                white-space: normal;
                text-align: center;
                padding: 9px 10px;
                font-size: 8px;
                line-height: 1.45;
            }

            .booking-layout {
                gap: 13px;
            }

            .booking-card,
            .side-card {
                border-radius: 17px;
            }

            .booking-card {
                padding: 15px;
            }

            .form-group {
                margin-bottom: 14px;
            }

            .form-row {
                grid-template-columns: 1fr;
                gap: 0;
            }

            .booking-card label {
                font-size: 10px;
                margin-bottom: 6px;
            }

            .booking-card input[type="text"],
            .booking-card input[type="date"],
            .booking-card select,
            .booking-card textarea,
            .booking-card input[type="file"] {
                min-height: 44px;
                padding: 10px 11px;
                font-size: 11px;
            }

            .booking-card input[type="file"] {
                padding: 9px;
                font-size: 9px;
            }

            .booking-card textarea {
                min-height: 105px;
            }

            .booking-card .header-section {
                padding-bottom: 14px;
                margin-bottom: 16px;
            }

            .booking-card .header-section h1 {
                font-size: 18px;
            }

            .booking-card .header-section p {
                font-size: 9px;
                line-height: 1.5;
            }

            .booking-card .walkin-notice,
            .booking-card .vet-info-box {
                font-size: 9px;
                line-height: 1.5;
            }

            .booking-card .vet-info-box {
                padding: 10px;
            }

            .booking-card .price-display {
                padding: 15px 12px;
                margin-bottom: 15px;
                border-radius: 13px;
            }

            .booking-card .price-display p {
                font-size: 8px;
                line-height: 1.45;
            }

            .booking-card .price-display h2 {
                font-size: 25px;
            }

            .booking-card .gcash-section {
                padding: 14px;
                border-radius: 13px;
                margin-bottom: 15px;
            }

            .booking-card .gcash-section h4 {
                font-size: 12px;
                line-height: 1.35;
            }

            .booking-card .gcash-section p {
                font-size: 9px;
                line-height: 1.6;
            }

            .booking-card .gcash-number {
                display: block;
                width: 100%;
                overflow-wrap: anywhere;
                word-break: break-word;
                padding: 8px 9px;
                margin-bottom: 12px;
                font-size: 11px;
                line-height: 1.45;
            }

            .booking-card .terms-container {
                padding: 11px;
                margin-bottom: 15px;
                font-size: 8px;
                line-height: 1.55;
            }

            .booking-card .terms-container label {
                gap: 8px;
            }

            .booking-card .terms-container input[type="checkbox"] {
                width: 17px;
                height: 17px;
                flex-basis: 17px;
            }

            .booking-card .btn-submit {
                min-height: 46px;
                padding: 11px 12px;
                font-size: 10px;
                line-height: 1.3;
            }

            .side-card {
                padding: 15px;
            }

            .side-kicker {
                font-size: 8px;
            }

            .side-card h3 {
                font-size: 17px;
            }

            .side-card > p {
                font-size: 9px;
                line-height: 1.55;
                margin-bottom: 14px;
            }

            .step-list {
                gap: 11px;
            }

            .step-item {
                gap: 8px;
            }

            .step-icon {
                width: 32px;
                height: 32px;
                flex-basis: 32px;
                border-radius: 9px;
                font-size: 12px;
            }

            .step-item strong {
                font-size: 9px;
            }

            .step-item span {
                font-size: 8px;
                line-height: 1.5;
            }

            .side-note {
                margin-top: 15px;
                padding: 10px;
                font-size: 8px;
                line-height: 1.55;
            }

            footer {
                padding: 42px 15px 24px;
            }

            .footer-main {
                grid-template-columns: 1fr;
                gap: 22px;
                padding-bottom: 28px;
            }

            .footer-main h4 {
                font-size: 10px;
                margin-bottom: 11px;
            }

            .footer-main p,
            .footer-main a {
                font-size: 10px;
                line-height: 1.65;
            }

            .socials {
                margin-top: 13px;
            }

            .socials a {
                width: 34px;
                height: 34px;
            }

            .footer-bottom {
                font-size: 8.5px;
                line-height: 1.5;
                padding-top: 18px;
            }
        }

        @media (max-width: 420px) {
            .nav-top {
                padding-left: 9px;
                padding-right: 9px;
            }

            .nav-logo-img {
                width: 38px;
                height: 38px;
            }

            .logo-text b {
                font-size: 15px;
            }

            .logo-text span {
                font-size: 6px;
                letter-spacing: .7px;
            }

            main {
                width: calc(100% - 16px);
            }

            .page-title h1 {
                font-size: 23px;
            }

            .page-title p {
                font-size: 9px;
            }

            .booking-card {
                padding: 13px;
            }

            .side-card {
                padding: 13px;
            }

            .booking-card .gcash-number {
                font-size: 10px;
            }

            .booking-card .terms-container {
                font-size: 7.5px;
            }

            .booking-card .btn-submit {
                font-size: 9px;
            }
        }

    </style>
</head>

<body>


    <div class="promo-bar">
        <i class="fa-solid fa-phone"></i> Need help? Call us at (046) 887 4714
    </div>

    <header>
        <div class="nav-top">
            <a href="dashboard.php" class="logo">
                <img src="bg.png" alt="Boogie's Pet Care logo" class="nav-logo-img">
                <div class="logo-text">
                    <b>Boogie's</b>
                    <span>PET CARE SERVICES</span>
                </div>
            </a>

            <div class="user-controls">
                <div class="notification-wrapper">
                    <div class="notification-bell" onclick="toggleDropdown('notifDropdown')" aria-label="Notifications">
                        <i class="fa-solid fa-bell"></i>
                        <?php if($unread_count > 0): ?>
                            <span class="notification-badge"><?php echo $unread_count; ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="dropdown-menu" id="notifDropdown">
                        <div class="dropdown-header">Notifications</div>

                        <?php if(count($notifications) > 0): ?>
                            <?php foreach($notifications as $notif): ?>
                                <a href="notifications.php" class="dropdown-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>">
                                    <?php echo htmlspecialchars($notif['message']); ?>
                                    <br>
                                    <small style="color:#888;font-size:10px;">
                                        <?php echo date('M d, Y h:i A', strtotime($notif['created_at'])); ?>
                                    </small>
                                </a>
                            <?php endforeach; ?>

                            <a href="notifications.php" class="dropdown-item view-all-link">
                                View All Notifications
                            </a>
                        <?php else: ?>
                            <div class="dropdown-item" style="text-align:center;color:#888;">
                                No new notifications.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="profile-wrapper">
                    <div class="profile-trigger" onclick="toggleDropdown('profileDropdown')">
                        <?php if (!empty($profile_pic)): ?>
                            <img
                                src="<?php echo (strpos($profile_pic, 'uploads/') === false ? 'uploads/' : '') . htmlspecialchars($profile_pic); ?>"
                                alt="Profile"
                                class="profile-img"
                            >
                        <?php else: ?>
                            <i class="fa-solid fa-circle-user" style="font-size:20px;color:var(--brand-blue);"></i>
                        <?php endif; ?>

                        <span>Hi, <?php echo htmlspecialchars($full_name); ?></span>
                        <i class="fa-solid fa-chevron-down"></i>
                    </div>

                    <div class="dropdown-menu" id="profileDropdown" style="width:210px;">
                        <a href="edit_profile.php" class="dropdown-item">
                            <i class="fa-solid fa-user"></i> My Profile
                        </a>
                        <a href="bookings.php" class="dropdown-item">
                            <i class="fa-solid fa-calendar-check"></i> My Bookings
                        </a>
                        <a href="petprofile.php" class="dropdown-item">
                            <i class="fa-solid fa-paw"></i> My Pets
                        </a>
                        <a href="logout.php" class="dropdown-item" style="color:#dc3545;border-top:1px solid #eaeaea;">
                            <i class="fa-solid fa-right-from-bracket"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </header>



    <main>
        <div class="page-intro">
            <div class="back-nav">
                <a href="dashboard.php">
                    <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>

            <div class="page-title-row">
                <div class="page-title">
                    <div class="page-kicker">
                        <i class="fa-solid fa-calendar-check"></i>
                        Appointment booking
                    </div>
                    <h1>Book an Appointment</h1>
                    <p>Schedule professional care for your pet with Boogie's.</p>
                </div>

                <div class="booking-summary-pill">
                    <i class="fa-solid fa-shield-heart"></i>
                    Payment is verified before confirmation
                </div>
            </div>
        </div>

        <div class="booking-layout">
            <section class="booking-card">
            <form method="POST" action="" id="bookingForm" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                
                <div class="form-group">
                    <label>Select Registered Pet (Optional)</label>
                    <select id="pet_selector" name="registered_pet_id" onchange="autoFillPet()">
                        <option value="">-- Add New Pet / Enter Manually --</option>
                        <?php foreach($user_pets as $pet): ?>
                            <option value="<?php echo $pet['id']; ?>">
                                <?php echo htmlspecialchars($pet['name'] . ' (' . $pet['pet_type'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Pet's Name *</label>
                    <input type="text" name="pet_name" id="pet_name" autocomplete="off" placeholder="e.g., Kyle" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Pet Type *</label>
                        <select name="pet_type" id="pet_type" required onchange="updateOptions()">
                            <option value="">Select Type</option>
                            <option value="Dog">Dog</option>
                            <option value="Cat">Cat</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Gender *</label>
                        <select name="pet_gender" id="pet_gender" required>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Pet Size / Weight *</label>
                    <select name="pet_size" id="pet_size" required onchange="calculatePrice()">
                        <option value="">Select Size</option>
                        <option value="Small (1-5kg)">Small (1-5kg)</option>
                        <option value="Medium (6-10kg)">Medium (6-10kg)</option>
                        <option value="Large (11-15kg)">Large (11-15kg)</option>
                        <option value="Extra Large (16-20kg)">Extra Large (16-20kg)</option>
                        <option value="XXL Large (21-25kg)">XXL Large (21-25kg)</option>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Service Category *</label>
                        <select name="service_category" id="service_category" required onchange="updateOptions()">
                            <option value="">Select Category</option>
                            <option value="Grooming" <?php echo ($pre_selected_category === 'Grooming') ? 'selected' : ''; ?>>Grooming</option>
                            <option value="Vet Services" <?php echo ($pre_selected_category === 'Vet Services') ? 'selected' : ''; ?>>Vet Services</option>
                            <option value="Pet Hotel" <?php echo ($pre_selected_category === 'Pet Hotel') ? 'selected' : ''; ?>>Pet Hotel</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Specific Service *</label>
                        <select name="specific_service" id="specific_service" required onchange="handleServiceChange()">
                            <option value="">Choose specific service</option>
                        </select>
                    </div>
                </div>

                <div id="vetNameDisplay" class="vet-info-box hidden">
                    <i class="fa-solid fa-user-doctor"></i> Attending Veterinarian: <strong>Dr. Faith Casayuran</strong>
                </div>

                <div class="form-group hidden" id="haircutContainer">
                    <label>Desired Haircut Style *</label>
                    <select name="haircut_style" id="haircut_style">
                        <option value="">Select Style</option>
                        <option value="Puppy Cut">Puppy Cut</option>
                        <option value="Summer Cut">Summer Cut</option>
                        <option value="Shave Down">Shave Down</option>
                        <option value="Bear Cut">Bear Cut</option>
                        <option value="Poodle Cut">Poodle Cut</option>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Select Date *</label>
                        <input type="date" name="appointment_date" id="appointment_date" required>
                    </div>
                    <div class="form-group">
                        <label>Select Time *</label>
                        <select name="appointment_time" id="appointment_time" required>
                            <option value="">Choose a time slot</option>
                            <option value="10:00:00">10:00 AM</option>
                            <option value="11:00:00">11:00 AM</option>
                            <option value="12:00:00">12:00 PM</option>
                            <option value="13:00:00">01:00 PM</option>
                            <option value="14:00:00">02:00 PM</option>
                            <option value="15:00:00">03:00 PM</option>
                            <option value="16:00:00">04:00 PM</option>
                            <option value="17:00:00">05:00 PM</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Remarks / Special Instructions (Optional)</label>
                    <textarea name="remarks" placeholder="Any allergies, behaviors, or specific requests?"></textarea>
                </div>

                <div class="price-display" id="priceDisplay">
                    <p>Total Amount Due (GCash Full Payment)</p>
                    <h2 id="priceText">₱0.00</h2>
                </div>

                <div class="gcash-section" id="gcashSection">
                    <h4><i class="fa-solid fa-mobile-screen-button"></i> GCash Payment Detail</h4>
                    <p>To secure your appointment, please send the exact amount to our GCash account. <strong>Your time slot will not be blocked until the payment is verified.</strong></p>
                    
                    <div class="gcash-qr-card" aria-label="Boogie's GCash QR payment">
                        <div class="gcash-qr-inner">
                            <div class="gcash-qr-title">
                                <i class="fa-solid fa-qrcode"></i>
                                Scan to Pay via GCash
                            </div>

                            <img
                                src="images/qr.png"
                                alt="Boogie's Pet Care GCash QR Code"
                                class="gcash-qr-image"
                                loading="eager"
                            >

                            <p class="gcash-qr-caption">
                                Open your GCash app and scan the QR code.
                                Send the exact amount shown in your booking total.
                            </p>
                        </div>

                        <div class="gcash-scan-strip">
                            <i class="fa-solid fa-bolt"></i>
                            For Fast &amp; Secure Mobile Checkout — Scan This
                        </div>
                    </div>

                    <div class="gcash-account-card">
                        <div class="gcash-account-label">GCash Account</div>
                        <div class="gcash-account-name">HA***H RO*SE T.</div>
                        <div class="gcash-account-number">
                            <i class="fa-solid fa-mobile-screen-button"></i>
                            09668939327
                        </div>
                    </div>

                    <div class="form-group">
                        <label>GCash Reference Number *</label>
                        <input type="text" name="gcash_ref" placeholder="e.g., 1001234567890" id="gcash_ref_input" inputmode="numeric" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label>Upload Screenshot of Receipt *</label>
                        <input type="file" name="gcash_receipt" accept="image/png, image/jpeg, image/jpg" style="background: white;" id="gcash_receipt_input">
                    </div>
                </div>

                <div class="terms-container">
                    <label>
                        <input type="checkbox" name="agree_terms" id="agree_terms" required>
                        <div>
                            I agree to the <span class="highlight">Terms & Conditions</span>. I understand that my booking will only be confirmed after GCash payment verification. If I miss my appointment beyond the allowed 30-minute grace period, I may reschedule the appointment once within 3 days using the same payment for the same service. If I miss the rescheduled appointment, it will be marked as <strong>No-Show</strong> and the payment will be <strong>non-refundable</strong>.
                        </div>
                    </label>
                </div>

                <button type="submit" class="btn-submit" id="btnSubmit">Submit & Verify Payment</button>
            </form>
            </section>

            <aside class="side-card">
                <div class="side-kicker">Booking guide</div>
                <h3>Almost there.</h3>
                <p>Complete the form, send your GCash payment, then our team will verify your appointment.</p>
                <div class="side-note" style="margin-top: 14px;">
                    <i class="fa-solid fa-calendar-days"></i>
                    One reschedule is allowed within 3 days after a missed appointment. The same payment remains valid for the same service.
                </div>

                <div class="step-list">
                    <div class="step-item">
                        <div class="step-icon"><i class="fa-solid fa-paw"></i></div>
                        <div>
                            <strong>Choose your pet</strong>
                            <span>Use an existing profile or enter a new one.</span>
                        </div>
                    </div>

                    <div class="step-item">
                        <div class="step-icon"><i class="fa-solid fa-list-check"></i></div>
                        <div>
                            <strong>Select a service</strong>
                            <span>Your total updates automatically based on your choices.</span>
                        </div>
                    </div>

                    <div class="step-item">
                        <div class="step-icon"><i class="fa-solid fa-calendar-days"></i></div>
                        <div>
                            <strong>Pick date & time</strong>
                            <span>Unavailable slots are checked before you submit.</span>
                        </div>
                    </div>

                    <div class="step-item">
                        <div class="step-icon"><i class="fa-solid fa-mobile-screen-button"></i></div>
                        <div>
                            <strong>Pay via GCash</strong>
                            <span>Upload your receipt and reference number for verification.</span>
                        </div>
                    </div>
                </div>

                <div class="side-note">
                    <i class="fa-solid fa-circle-info"></i>
                    Your slot is only confirmed after payment verification.
                </div>
            </aside>
        </div>
    </main>



    <footer>
        <div class="footer-main">
            <div>
                <h4><i class="fa-solid fa-paw"></i> Boogie's Pet Care</h4>
                <p>Your trusted partner for all your pet care needs in Dasmariñas, Cavite.</p>
                <div class="socials">
                    <a href="https://www.facebook.com/boogiespetsupplies" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
                    <a href="https://mail.google.com/mail/?view=cm&to=boogiespetcareservices@gmail.com" class="gmail-compose-link" aria-label="Email Boogie's Pet Care Services"><i class="fa-solid fa-envelope"></i></a>
                </div>
            </div>

            <div>
                <h4>Quick Links</h4>
                <a href="home.php">Home</a>
                <a href="petservices.php">Services & Prices</a>
                <a href="contactus.php">Contact & Reviews</a>
                <a href="faqs.php">FAQs</a>
            </div>

            <div>
                <h4>Services</h4>
                <a href="grooming.php">Grooming</a>
                <a href="pethotel.php">Pet Hotel</a>
                <a href="vetclinic.php">Vet Clinic</a>
            </div>

            <div>
                <h4>Contact Us</h4>
                <p><i class="fa-solid fa-phone"></i> (046) 887 4714</p>
                <p><i class="fa-solid fa-envelope"></i> boogiespetcareservices@gmail.com</p>
                <p><i class="fa-solid fa-location-dot"></i> 110 Don Placido Campos Ave San Agustin 3, Dasmariñas, Philippines, 4114</p>
            </div>
        </div>

        <div class="footer-bottom">
            © <?php echo date("Y"); ?> Boogie's Pet Care & Services - Dasmariñas Branch. All rights reserved.
        </div>
    </footer>


    <script>
        // --- GMAIL COMPOSE POPUP ---
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.gmail-compose-link').forEach(function (link) {
                link.addEventListener('click', function (e) {
                    // On small screens, let Gmail open normally for better mobile behavior.
                    if (window.innerWidth <= 680) {
                        return;
                    }

                    e.preventDefault();

                    const width = 760;
                    const height = 650;
                    const left = Math.max(0, Math.round((window.screen.width - width) / 2));
                    const top = Math.max(0, Math.round((window.screen.height - height) / 2));

                    const popup = window.open(
                        this.href,
                        'boogiesGmailCompose',
                        'width=' + width +
                        ',height=' + height +
                        ',left=' + left +
                        ',top=' + top +
                        ',resizable=yes,scrollbars=yes'
                    );

                    if (!popup) {
                        window.location.href = this.href;
                    }
                });
            });
        });

        // --- DROPDOWN LOGIC ---
        function toggleDropdown(id) {
            document.querySelectorAll('.dropdown-menu').forEach(menu => {
                if (menu.id !== id) { menu.classList.remove('active'); }
            });
            document.getElementById(id).classList.toggle('active');
        }

        window.addEventListener('click', function(e) {
            if (!document.querySelector('.notification-wrapper').contains(e.target) && 
                !document.querySelector('.profile-wrapper').contains(e.target)) {
                document.querySelectorAll('.dropdown-menu').forEach(menu => {
                    menu.classList.remove('active');
                });
            }
        });

        const userPetsArray = <?php echo json_encode($user_pets); ?>;

        window.addEventListener('DOMContentLoaded', (event) => {
            if (document.getElementById('appointment_date')) {
                updateOptions();
                
                // BAGO: CUT-OFF TIME LOGIC
                const dateInput = document.getElementById('appointment_date');
                const now = new Date();
                const todayStr = now.toISOString().split('T')[0];
                
                // Kung lampas na ng 6:00 PM (18:00), bukas na ang pwedeng i-book
                if (now.getHours() >= 18) {
                    const tomorrow = new Date(now);
                    tomorrow.setDate(tomorrow.getDate() + 1);
                    const tomorrowStr = tomorrow.toISOString().split('T')[0];
                    dateInput.setAttribute('min', tomorrowStr);
                    
                    // Mag-alert sa user kung bakit bukas na siya pinapabook
                    dateInput.addEventListener('click', function() {
                        if(!this.dataset.alerted) {
                            alert("Notice: It is past 6:00 PM. Same-day bookings are now closed. Please select a date starting tomorrow.");
                            this.dataset.alerted = "true";
                        }
                    });
                } else {
                    dateInput.setAttribute('min', todayStr);
                }
            }
        });

        function bookingSizeFromPetWeight(weight) {
            const value = String(weight ?? '').trim();
            const sizeSelect = document.getElementById('pet_size');
            const validSizes = Array.from(sizeSelect.options).map(option => option.value).filter(Boolean);

            // Backward compatibility for older pet records whose weight is already a size label.
            if (validSizes.includes(value)) return value;

            // Current pet profiles store the actual weight in kilograms (for example, 5.2).
            const match = value.match(/(-?\d+(?:\.\d+)?)/);
            if (!match) return '';

            const kg = Number.parseFloat(match[1]);
            if (!Number.isFinite(kg) || kg <= 0 || kg > 25) return '';
            if (kg <= 5) return 'Small (1-5kg)';
            if (kg <= 10) return 'Medium (6-10kg)';
            if (kg <= 15) return 'Large (11-15kg)';
            if (kg <= 20) return 'Extra Large (16-20kg)';
            return 'XXL Large (21-25kg)';
        }

        function autoFillPet() {
            const petSelector = document.getElementById('pet_selector').value;
            const nameInput = document.getElementById('pet_name');
            const typeSelect = document.getElementById('pet_type');
            const genderSelect = document.getElementById('pet_gender');
            const sizeSelect = document.getElementById('pet_size');

            if (petSelector === "") {
                nameInput.value = "";
                nameInput.readOnly = false;
                typeSelect.value = "";
                genderSelect.value = "Male";
                sizeSelect.value = "";
            } else {
                const selectedPet = userPetsArray.find(p => String(p.id) === String(petSelector));
                if (selectedPet) {
                    nameInput.value = selectedPet.name ?? '';
                    nameInput.readOnly = true;
                    typeSelect.value = selectedPet.pet_type ?? '';
                    genderSelect.value = selectedPet.gender ?? 'Male';
                    sizeSelect.value = bookingSizeFromPetWeight(selectedPet.weight);
                    if (!sizeSelect.value && String(selectedPet.weight ?? '').trim() !== '') {
                        alert('This pet’s saved weight is outside the supported booking size ranges or is invalid. Please update the pet profile or contact the clinic.');
                    }
                }
            }

            updateOptions();
        }

        const pricingData = {
            "Dog": {
                "Grooming": {
                    "Basic Pet Grooming": { "Small (1-5kg)": 400, "Medium (6-10kg)": 500, "Large (11-15kg)": 650, "Extra Large (16-20kg)": 850, "XXL Large (21-25kg)": 1000 },
                    "Full Grooming Package": { "Small (1-5kg)": 450, "Medium (6-10kg)": 550, "Large (11-15kg)": 700, "Extra Large (16-20kg)": 900, "XXL Large (21-25kg)": 1100 },
                    "Bath & Blow Dry": { "Small (1-5kg)": 300, "Medium (6-10kg)": 350, "Large (11-15kg)": 550, "Extra Large (16-20kg)": 750, "XXL Large (21-25kg)": 950 }
                },
                "Vet Services": {
                    "Deworming": { "Small (1-5kg)": 200, "Medium (6-10kg)": 250, "Large (11-15kg)": 300, "Extra Large (16-20kg)": 350, "XXL Large (21-25kg)": 450 },
                    "Vaccination - Anti Rabies": { default: 300 },
                    "Vaccination - 5 in 1": { default: 450 },
                    "Vaccination - 6 in 1": { default: 600 },
                    "Vaccination - 8 in 1": { default: 750 }
                },
                "Pet Hotel": {
                    "Pet Daycare (1st Hour - Succeeding fees apply)": { "Small (1-5kg)": 100, "Medium (6-10kg)": 100, "Large (11-15kg)": 150, "Extra Large (16-20kg)": 150, "XXL Large (21-25kg)": 200 },
                    "Pet Boarding (Overnight)": { "Small (1-5kg)": 500, "Medium (6-10kg)": 500, "Large (11-15kg)": 600, "Extra Large (16-20kg)": 600, "XXL Large (21-25kg)": 800 }
                }
            },
            "Cat": {
                "Grooming": {
                    "Cat Grooming (Basic)": { "Small (1-5kg)": 550, "Medium (6-10kg)": 650, "Large (11-15kg)": 750, "Extra Large (16-20kg)": 850, "XXL Large (21-25kg)": 950 },
                    "Cat Bath & Blow Dry": { "Small (1-5kg)": 400, "Medium (6-10kg)": 500, "Large (11-15kg)": 600, "Extra Large (16-20kg)": 700, "XXL Large (21-25kg)": 800 }
                },
                "Vet Services": {
                    "Deworming": { "Small (1-5kg)": 200, "Medium (6-10kg)": 250, "Large (11-15kg)": 300, "Extra Large (16-20kg)": 350, "XXL Large (21-25kg)": 450 },
                    "Vaccination - Anti Rabies": { default: 300 },
                    "Vaccination - 4 in 1 (Cats)": { default: 900 }
                },
                "Pet Hotel": {
                    "Pet Daycare (1st Hour - Succeeding fees apply)": { default: 150 },
                    "Pet Boarding (Overnight)": { default: 500 }
                }
            }
        };

        function updateOptions() {
            const petType = document.getElementById('pet_type').value;
            const category = document.getElementById('service_category').value;
            const specificServiceDropdown = document.getElementById('specific_service');
            const vetNameDisplay = document.getElementById('vetNameDisplay');
            
            const walkInNotice = document.getElementById('walkInNotice');
            const submitBtn = document.getElementById('btnSubmit');
            
            const previousSelection = specificServiceDropdown.value;

            specificServiceDropdown.innerHTML = '<option value="">Choose specific service</option>';
            document.getElementById('haircutContainer').classList.add('hidden');

            if (category === 'Vet Services') {
                vetNameDisplay.classList.remove('hidden');
            } else {
                vetNameDisplay.classList.add('hidden');
            }

            // walkInNotice is optional on this page.
            // Prevent a missing element from stopping updateOptions()
            // before the Specific Service dropdown gets populated.
            if (walkInNotice) {
                if (category === 'Vet Services' || category === 'Grooming') {
                    walkInNotice.style.display = 'block';
                } else {
                    walkInNotice.style.display = 'none';
                }
            }

            if (petType && category && pricingData[petType][category]) {
                const services = Object.keys(pricingData[petType][category]);
                services.forEach(service => {
                    const option = document.createElement('option');
                    option.value = service; option.text = service;
                    if (service === previousSelection) { option.selected = true; }
                    specificServiceDropdown.appendChild(option);
                });
            }
            
            validateVetDate();
            calculatePrice();
            checkRealTimeAvailability(); 
        }

        function handleServiceChange() {
            const specificService = document.getElementById('specific_service').value;
            const haircutContainer = document.getElementById('haircutContainer');
            const haircutSelect = document.getElementById('haircut_style');

            if (specificService === 'Full Grooming Package') {
                haircutContainer.classList.remove('hidden');
                haircutSelect.required = true;
            } else {
                haircutContainer.classList.add('hidden');
                haircutSelect.required = false;
            }
            calculatePrice();
        }

        function validateVetDate() {
            const category = document.getElementById('service_category').value;
            const dateInput = document.getElementById('appointment_date');
            
            if (category === 'Vet Services' && dateInput.value) {
                const selectedDate = new Date(dateInput.value);
                const day = selectedDate.getDay(); 
                
                if (day === 3 || day === 6) {
                    alert("Dr. Faith Casayuran is not available on Wednesdays and Saturdays. Please select a different date.");
                    dateInput.value = '';
                }
            }
        }

        if (document.getElementById('appointment_date')) {
            document.getElementById('appointment_date').addEventListener('change', () => {
                validateVetDate();
                checkRealTimeAvailability();
            });
        }

        // --- REAL-TIME SLOT AVAILABILITY CHECKER ---
        function checkRealTimeAvailability() {
            const date = document.getElementById('appointment_date').value;
            const category = document.getElementById('service_category').value;
            const timeSelect = document.getElementById('appointment_time');

            if (!date || !category) return;

            const originalTimes = {
                "10:00:00": "10:00 AM", "11:00:00": "11:00 AM", "12:00:00": "12:00 PM",
                "13:00:00": "01:00 PM", "14:00:00": "02:00 PM", "15:00:00": "03:00 PM",
                "16:00:00": "04:00 PM", "17:00:00": "05:00 PM"
            };

            Array.from(timeSelect.options).forEach(opt => {
                if (opt.value !== "") { opt.disabled = false; opt.text = originalTimes[opt.value]; }
            });

            fetch(`check_slots.php?date=${date}&category=${encodeURIComponent(category)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.is_full) {
                        alert('The selected date is fully booked for ' + category + '. Please choose another date.');
                        timeSelect.value = "";
                        Array.from(timeSelect.options).forEach(opt => {
                            if (opt.value !== "") opt.disabled = true;
                        });
                    } else {
                        data.booked_times.forEach(bookedTime => {
                            let option = timeSelect.querySelector(`option[value="${bookedTime}"]`);
                            if (option) {
                                option.disabled = true;
                                option.text += ' (Taken)';
                            }
                        });
                    }
                })
                .catch(error => console.error("Error fetching slots:", error));
        }

        function calculatePrice() {
            const petType = document.getElementById('pet_type').value;
            const size = document.getElementById('pet_size').value;
            const category = document.getElementById('service_category').value;
            const specific = document.getElementById('specific_service').value;
            
            const displayBox = document.getElementById('priceDisplay');
            const priceText = document.getElementById('priceText');
            const gcashSection = document.getElementById('gcashSection');
            const gcashRefInput = document.getElementById('gcash_ref_input');
            const gcashReceiptInput = document.getElementById('gcash_receipt_input');

            if (petType && size && category && specific) {
                let price = 0;
                const serviceData = pricingData[petType][category][specific];

                if (serviceData.default) {
                    price = serviceData.default;
                } else if (serviceData[size]) {
                    price = serviceData[size];
                }

                if (price > 0) {
                    priceText.innerText = '₱' + price.toFixed(2);
                    displayBox.style.display = 'block';
                    
                    // Show GCash Section and make REF and Receipt required
                    gcashSection.style.display = 'block';
                    gcashRefInput.required = true;
                    gcashReceiptInput.required = true;
                    return;
                }
            }
            displayBox.style.display = 'none';
            gcashSection.style.display = 'none';
            gcashRefInput.required = false;
            gcashReceiptInput.required = false;
        }
    </script>
</body>
</html>
