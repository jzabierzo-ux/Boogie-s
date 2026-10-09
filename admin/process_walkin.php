<?php

require_once __DIR__ . '/../shared_session_bootstrap.php';
require_once '../db_supabase.php';

date_default_timezone_set('Asia/Manila');

// Use the same role aliases and allowed operational roles as managebooking.php.
$raw_role = strtolower(trim((string)($_SESSION['role'] ?? '')));
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
    'vet' => 'veterinarian',
    'veterinary' => 'veterinarian',
    'staff' => 'groomer',
    'supervisor' => 'receptionist',
];
$current_role = $role_aliases[$raw_role] ?? $raw_role;
$is_admin = $current_role === 'admin';
$allowed_roles = ['admin', 'manager', 'receptionist', 'groomer', 'pet_hotel_staff'];

if (($_SESSION['logged_in'] ?? false) !== true) {
    header('Location: ../staff/stafflogin.php');
    exit();
}

if (!in_array($current_role, $allowed_roles, true)) {
    http_response_code(403);
    exit('Access denied. Your role cannot create walk-in bookings.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['add_walkin'])) {
    header('Location: managebooking.php');
    exit();
}

$session_csrf = (string)($_SESSION['csrf_token'] ?? '');
$posted_csrf = (string)($_POST['csrf_token'] ?? '');
if ($session_csrf === '' || $posted_csrf === '' || !hash_equals($session_csrf, $posted_csrf)) {
    http_response_code(403);
    exit('Invalid or expired request token. Return to bookings and try again.');
}

$customer_name = trim((string)($_POST['customer_name'] ?? ''));
$contact_number = trim((string)($_POST['contact_number'] ?? ''));
$pet_name = trim((string)($_POST['pet_name'] ?? ''));
$pet_breed = trim((string)($_POST['pet_breed'] ?? ''));
$pet_type = trim((string)($_POST['pet_type'] ?? ''));
$pet_gender = trim((string)($_POST['pet_gender'] ?? ''));
$pet_weight = (float)($_POST['pet_weight'] ?? 0);
$service_category = trim((string)($_POST['service_category'] ?? ''));
$specific_service = trim((string)($_POST['specific_service'] ?? ''));
$haircut_style = trim((string)($_POST['haircut_style'] ?? ''));
$appointment_date = trim((string)($_POST['appointment_date'] ?? ''));
$appointment_time = trim((string)($_POST['appointment_time'] ?? ''));

// Enforce service permissions on the server; hiding options in the form is not enough.
$allowed_categories_by_role = [
    'admin' => ['Grooming', 'Vet Services', 'Pet Hotel'],
    'manager' => ['Grooming', 'Vet Services', 'Pet Hotel'],
    'receptionist' => ['Grooming', 'Vet Services', 'Pet Hotel'],
    'groomer' => ['Grooming'],
    'pet_hotel_staff' => ['Pet Hotel'],
];
if (!in_array($service_category, $allowed_categories_by_role[$current_role] ?? [], true)) {
    http_response_code(403);
    exit('Access denied. You cannot create a walk-in booking for this service category.');
}

$pricing_data = [
    'Dog' => [
        'Grooming' => [
            'Basic Pet Grooming' => [
                'Small (1-5kg)' => 400,
                'Medium (6-10kg)' => 500,
                'Large (11-15kg)' => 650,
                'Extra Large (16-20kg)' => 850,
                'XXL Large (21-25kg)' => 1000,
            ],
            'Full Grooming Package' => [
                'Small (1-5kg)' => 450,
                'Medium (6-10kg)' => 550,
                'Large (11-15kg)' => 700,
                'Extra Large (16-20kg)' => 900,
                'XXL Large (21-25kg)' => 1100,
            ],
            'Bath & Blow Dry' => [
                'Small (1-5kg)' => 300,
                'Medium (6-10kg)' => 350,
                'Large (11-15kg)' => 550,
                'Extra Large (16-20kg)' => 750,
                'XXL Large (21-25kg)' => 950,
            ],
        ],
        'Vet Services' => [
            'Deworming' => [
                'Small (1-5kg)' => 200,
                'Medium (6-10kg)' => 250,
                'Large (11-15kg)' => 300,
                'Extra Large (16-20kg)' => 350,
                'XXL Large (21-25kg)' => 450,
            ],
            'Vaccination - Anti Rabies' => ['default' => 300],
            'Vaccination - 5 in 1' => ['default' => 450],
            'Vaccination - 6 in 1' => ['default' => 600],
            'Vaccination - 8 in 1' => ['default' => 750],
        ],
        'Pet Hotel' => [
            'Pet Daycare (1st Hour - Succeeding fees apply)' => [
                'Small (1-5kg)' => 100,
                'Medium (6-10kg)' => 100,
                'Large (11-15kg)' => 150,
                'Extra Large (16-20kg)' => 150,
                'XXL Large (21-25kg)' => 200,
            ],
            'Pet Boarding (Overnight)' => [
                'Small (1-5kg)' => 500,
                'Medium (6-10kg)' => 500,
                'Large (11-15kg)' => 600,
                'Extra Large (16-20kg)' => 600,
                'XXL Large (21-25kg)' => 800,
            ],
        ],
    ],
    'Cat' => [
        'Grooming' => [
            'Cat Grooming (Basic)' => [
                'Small (1-5kg)' => 550,
                'Medium (6-10kg)' => 650,
                'Large (11-15kg)' => 750,
                'Extra Large (16-20kg)' => 850,
                'XXL Large (21-25kg)' => 950,
            ],
            'Cat Bath & Blow Dry' => [
                'Small (1-5kg)' => 400,
                'Medium (6-10kg)' => 500,
                'Large (11-15kg)' => 600,
                'Extra Large (16-20kg)' => 700,
                'XXL Large (21-25kg)' => 800,
            ],
        ],
        'Vet Services' => [
            'Deworming' => [
                'Small (1-5kg)' => 200,
                'Medium (6-10kg)' => 250,
                'Large (11-15kg)' => 300,
                'Extra Large (16-20kg)' => 350,
                'XXL Large (21-25kg)' => 450,
            ],
            'Vaccination - Anti Rabies' => ['default' => 300],
            'Vaccination - 4 in 1 (Cats)' => ['default' => 900],
        ],
        'Pet Hotel' => [
            'Pet Daycare (1st Hour - Succeeding fees apply)' => ['default' => 150],
            'Pet Boarding (Overnight)' => ['default' => 500],
        ],
    ],
];

function walkinSizeFromWeight(float $weight): string
{
    if ($weight > 0 && $weight <= 5) return 'Small (1-5kg)';
    if ($weight > 5 && $weight <= 10) return 'Medium (6-10kg)';
    if ($weight > 10 && $weight <= 15) return 'Large (11-15kg)';
    if ($weight > 15 && $weight <= 20) return 'Extra Large (16-20kg)';
    if ($weight > 20 && $weight <= 25) return 'XXL Large (21-25kg)';
    return '';
}

function walkinRedirect(string $message)
{
    $_SESSION['alert_msg'] = $message;
    header('Location: managebooking.php');
    exit();
}

if ($customer_name === '' || $contact_number === '' || $pet_name === '' || $pet_type === '' || $pet_gender === '' || $pet_weight <= 0 || $service_category === '' || $specific_service === '' || $appointment_date === '' || $appointment_time === '') {
    walkinRedirect('Please complete all walk-in booking fields.');
}

if (mb_strlen($customer_name) > 150 || mb_strlen($pet_name) > 100 || mb_strlen($pet_breed) > 100) {
    walkinRedirect('Customer name, pet name, or breed is too long.');
}

if (!in_array($pet_gender, ['Male', 'Female'], true)) {
    walkinRedirect('Please select a valid pet gender.');
}

if (!preg_match('/^09\d{9}$/', $contact_number)) {
    walkinRedirect('Please enter a valid Philippine contact number (09XXXXXXXXX).');
}

if (!isset($pricing_data[$pet_type][$service_category][$specific_service])) {
    walkinRedirect('Invalid pet type or service selection.');
}

$service_prices = $pricing_data[$pet_type][$service_category][$specific_service];
$size_key = walkinSizeFromWeight($pet_weight);

if (isset($service_prices['default'])) {
    $service_fee = (float)$service_prices['default'];
} elseif ($size_key !== '' && isset($service_prices[$size_key])) {
    $service_fee = (float)$service_prices[$size_key];
} else {
    walkinRedirect('The selected service does not have a price for this weight. Please enter a weight from 0.1 to 25 kg.');
}

if ($specific_service === 'Full Grooming Package') {
    $allowed_haircut_styles = ['Puppy Cut', 'Summer Cut', 'Shave Down', 'Bear Cut', 'Poodle Cut'];
    if (!in_array($haircut_style, $allowed_haircut_styles, true)) {
        walkinRedirect('Please select a valid haircut style for the Full Grooming Package.');
    }
} else {
    $haircut_style = '';
}

$final_service_name = $service_category . ' - ' . $specific_service;
if ($specific_service === 'Full Grooming Package' && $haircut_style !== '') {
    $final_service_name .= ' (' . $haircut_style . ')';
}

$date_check = DateTime::createFromFormat('Y-m-d', $appointment_date);
if (!$date_check || $date_check->format('Y-m-d') !== $appointment_date) {
    walkinRedirect('Please enter a valid appointment date.');
}

$time_check = DateTime::createFromFormat('!H:i', $appointment_time);
if (!$time_check || $time_check->format('H:i') !== $appointment_time) {
    walkinRedirect('Please enter a valid appointment time.');
}

try {
    $pdo->beginTransaction();

    $dummy_email = 'walkin_' . time() . '_' . bin2hex(random_bytes(4)) . '@boogies.local';

    $user_stmt = $pdo->prepare("
        INSERT INTO users
            (full_name, email, password, contact_number, role, position, user_category, is_verified, email_verified)
        VALUES
            (:full_name, :email, :password, :contact_number, 'customer', 'Customer', 'Pet Owner', 1, 1)
        RETURNING id
    ");

    $user_stmt->execute([
        ':full_name' => $customer_name . ' (Walk-in)',
        ':email' => $dummy_email,
       ':password' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
        ':contact_number' => $contact_number,
    ]);

    $new_user_id = (int)$user_stmt->fetchColumn();

    $pet_stmt = $pdo->prepare("
        INSERT INTO pets
            (owner_id, name, pet_type, breed, gender, weight, owner_name)
        VALUES
            (:owner_id, :name, :pet_type, NULLIF(:breed, ''), :gender, :weight, :owner_name)
        RETURNING id
    ");

    $pet_stmt->execute([
        ':owner_id' => $new_user_id,
        ':name' => $pet_name,
        ':pet_type' => $pet_type,
        ':breed' => $pet_breed,
        ':gender' => $pet_gender,
        ':weight' => number_format($pet_weight, 1, '.', '') . 'kg',
        ':owner_name' => $customer_name . ' (Walk-in)',
    ]);

    $new_pet_id = (int)$pet_stmt->fetchColumn();

    $appointment_stmt = $pdo->prepare("
        INSERT INTO appointments
            (
                user_id,
                pet_id,
                service,
                appointment_date,
                appointment_time,
                service_fee,
                total_price,
                payment_method,
                payment_status,
                booking_status,
                appointment_type,
                vet_doctor
            )
        VALUES
            (
                :user_id,
                :pet_id,
                :service,
                :appointment_date,
                :appointment_time,
                :service_fee,
                :total_price,
                'Cash (Walk-in)',
                'Pending',
                'Confirmed',
                'Walk-in',
                :vet_doctor
            )
    ");

    $appointment_stmt->execute([
        ':user_id' => $new_user_id,
        ':pet_id' => $new_pet_id,
        ':service' => $final_service_name,
        ':appointment_date' => $appointment_date,
        ':appointment_time' => $appointment_time,
        ':service_fee' => $service_fee,
        ':total_price' => $service_fee,
        ':vet_doctor' => $service_category === 'Vet Services' ? 'Dr. Faith Casayuran' : null,
    ]);

    $pdo->commit();

    $_SESSION['alert_msg'] = 'Walk-in booking created and confirmed. Payment remains Pending until cash is actually received. Total: ₱' . number_format($service_fee, 2);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Walk-in booking error: ' . $e->getMessage());
    $_SESSION['alert_msg'] = 'Unable to add the walk-in booking. Check the PHP error log for details.';
}

header('Location: managebooking.php');
exit();
