<?php
require_once __DIR__ . '/../shared_session_bootstrap.php';

// Do not expose PHP warnings, SQL details, or cached pet/owner information.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cross-Origin-Resource-Policy: same-origin');

// --- 1. STRICT PORTAL AND ROLE-BASED ACCESS CONTROL ---
// A role value by itself is not proof of an Admin or Staff login.
$sessionRoleValue = $_SESSION['role'] ?? '';
$raw_role = is_string($sessionRoleValue) ? strtolower(trim($sessionRoleValue)) : '';
$role_aliases = [
    'administrator' => 'admin',
    'shop manager' => 'manager',
    'shop_manager' => 'manager',
    'front desk' => 'receptionist',
    'front desk / receptionist' => 'receptionist',
    'front_desk' => 'receptionist',
    'front_desk_receptionist' => 'receptionist',
    'grooming staff' => 'groomer',
    'grooming staff / groomer' => 'groomer',
    'grooming_staff' => 'groomer',
    'grooming_staff_groomer' => 'groomer',
    'pet hotel staff' => 'pet_hotel_staff',
    'pet hotel staff / attendant' => 'pet_hotel_staff',
    'pet_hotel_attendant' => 'pet_hotel_staff',
    'pet_hotel_staff_attendant' => 'pet_hotel_staff',
    'vet' => 'veterinarian',
    'veterinary' => 'veterinarian',
    'veterinary assistant' => 'vet_assistant',
    'vet assistant' => 'vet_assistant',
    'veterinary_assistant' => 'vet_assistant',
    'veterinary nurse' => 'vet_nurse',
    'vet nurse' => 'vet_nurse',
    'veterinary_nurse' => 'vet_nurse',
    // Match legacy-role handling in the current Pet Management pages.
    'staff' => 'groomer',
    'supervisor' => 'receptionist',
];
$current_role = $role_aliases[$raw_role] ?? $raw_role;
if ($current_role !== '') {
    $_SESSION['role'] = $current_role;
}

$is_logged_in = ($_SESSION['logged_in'] ?? false) === true;
$admin_flag = ($_SESSION['admin_logged_in'] ?? false) === true;
$staff_flag = ($_SESSION['staff_logged_in'] ?? false) === true;

// Never allow a mixed Admin/Staff portal session to access this page.
if ($admin_flag && $staff_flag) {
    http_response_code(403);
    exit('Access denied. Please log out and sign in again using one portal.');
}

if (!$is_logged_in) {
    // Keep the two login destinations separate.
    $login_target = $admin_flag ? '../admin_login.php' : '../staff/stafflogin.php';
    header('Location: ' . $login_target, true, 302);
    exit();
}

$rawUserId = $_SESSION['user_id'] ?? null;
$validatedUserId = (is_string($rawUserId) || is_int($rawUserId))
    ? filter_var($rawUserId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
if ($validatedUserId === false) {
    http_response_code(403);
    exit('Access denied: invalid session.');
}
$session_user_id = (int)$validatedUserId;

// Veterinary personnel must continue to their dedicated staff dashboard.
if ($staff_flag && !$admin_flag && in_array($current_role, ['veterinarian', 'vet_assistant', 'vet_nurse'], true)) {
    header('Location: ../staff/staffdashboard.php', true, 302);
    exit();
}

$allowed_staff_record_roles = ['manager', 'receptionist', 'groomer', 'pet_hotel_staff'];
$is_admin_session = $admin_flag && !$staff_flag && $current_role === 'admin';
$is_staff_session = $staff_flag && !$admin_flag && in_array($current_role, $allowed_staff_record_roles, true);

if (!$is_admin_session && !$is_staff_session) {
    http_response_code(403);
    exit('Access denied. Your portal session does not match your account role. Please log out and sign in again.');
}

// Admin, Manager, Receptionist, Groomer, and Pet Hotel Staff can view pet records.
$allowed_record_roles = ['admin', 'manager', 'receptionist', 'groomer', 'pet_hotel_staff'];
if (!in_array($current_role, $allowed_record_roles, true)) {
    http_response_code(403);
    exit('Access denied. This role cannot view pet records.');
}

// --- 2. VALIDATE PET ID ---
$back_link = 'managepet.php';
$rawPetId = $_GET['id'] ?? null;
$pet_id = (is_string($rawPetId) || is_int($rawPetId))
    ? filter_var($rawPetId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
if ($pet_id === false) {
    http_response_code(400);
    exit('Invalid Pet ID.');
}
$pet_id = (int)$pet_id;

// Open the database connection only after authentication and authorization.
require_once __DIR__ . '/../db_supabase.php';

// --- 3. FETCH PET DATA ---
try {
    // Join the customer account to retrieve the current owner name and contact details.
    // Keep pets.owner_name as a fallback for older records.
    $pet_query = <<<'SQL'
        SELECT
            p.name,
            p.pet_type,
            p.breed,
            p.gender,
            p.age,
            p.weight,
            COALESCE(
                NULLIF(BTRIM(u.full_name), ''),
                NULLIF(BTRIM(p.owner_name), ''),
                'Unknown'
            ) AS owner_display_name,
            COALESCE(NULLIF(BTRIM(u.contact_number), ''), 'Not Provided') AS owner_contact,
            p.medical_history,
            p.special_needs,
            p.status
        FROM pets p
        LEFT JOIN users u ON u.id = p.owner_id
        WHERE p.id = :pet_id
        LIMIT 1
    SQL;
    $pet_stmt = $pdo->prepare($pet_query);
    $pet_stmt->execute([':pet_id' => $pet_id]);
    $row = $pet_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('View pet record query failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load the pet record at this time.');
}

if (!$row) {
    http_response_code(404);
    exit('Pet not found in the database.');
}

// Map the pet data with safe display defaults.
$display_value = static function ($value, string $fallback = 'N/A'): string {
    $value = trim((string)($value ?? ''));
    return ($value === '' || strcasecmp($value, 'n/a') === 0) ? $fallback : $value;
};

$p_name = $display_value($row['name'] ?? null, 'Unknown');
$p_type = $display_value($row['pet_type'] ?? null);
$p_breed = $display_value($row['breed'] ?? null);
$p_gender = $display_value($row['gender'] ?? null);
$p_age_raw = $display_value($row['age'] ?? null);
$p_age = $p_age_raw;
if ($p_age_raw !== 'N/A' && is_numeric($p_age_raw)) {
    $p_age = $p_age_raw . ((float)$p_age_raw === 1.0 ? ' year old' : ' years old');
}
$p_weight = $display_value($row['weight'] ?? null);
$o_name = $display_value($row['owner_display_name'] ?? null, 'Unknown');
$o_contact = $display_value($row['owner_contact'] ?? null, 'Not Provided');

$med_history = trim((string)($row['medical_history'] ?? ''));
if ($med_history === '') {
    $med_history = 'No medical history recorded.';
}
$special_needs = trim((string)($row['special_needs'] ?? ''));
if ($special_needs === '') {
    $special_needs = 'No special care instructions provided.';
}
$p_status = $row['status'] ?? 'Pending';

// --- 4. FETCH APPOINTMENT & TRANSACTION HISTORY ---
$appointments = [];
$appointments_load_error = false;
try {
    // Use only the fields displayed in the history table and a stable tie-breaker.
    $appt_query = '
        SELECT
            id,
            appointment_date,
            appointment_time,
            service,
            service_fee,
            booking_status
        FROM appointments
        WHERE pet_id = :pet_id
        ORDER BY appointment_date DESC, appointment_time DESC, id DESC
    ';
    $appt_stmt = $pdo->prepare($appt_query);
    $appt_stmt->execute([':pet_id' => $pet_id]);
    $appointments = $appt_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('View pet appointment history query failed for pet #' . $pet_id . ': ' . $e->getMessage());
    $appointments_load_error = true;
}
?>


<!DOCTYPE html>



<html lang="en">



<head>



    <meta charset="UTF-8">



    <meta name="viewport" content="width=device-width, initial-scale=1.0">



    <title>View Pet Record | Boogie's Pet Care</title>



    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">



    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">



    <style>



        :root {



            --navy-dark: #001f3f; 



            --brand-yellow: #ffcc00; 



            --bg-light: #f4f7f6; 



            --white: #ffffff; 



            --text-main: #2d3436;



            --text-muted: #64748b; 



            --border: #e2e8f0;



        }



        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif;}



        body { background: var(--bg-light); color: var(--text-main); padding: 40px; }



        .container { max-width: 1000px; margin: 0 auto; }


        .header-actions { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }


        .btn-back { background: var(--white); color: var(--navy-dark); padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: 700; display: inline-flex; align-items: center; gap: 8px; transition: 0.3s; box-shadow: 0 2px 4px rgba(0,0,0,0.05); font-size: 14px;}



        .btn-back:hover { background: var(--navy-dark); color: var(--brand-yellow); transform: translateY(-2px); box-shadow: 0 6px 12px rgba(0,31,63,0.15);}

        .card { background: var(--white); border-radius: 16px; border: none; padding: 35px; box-shadow: 0 4px 10px rgba(0,0,0,0.03); margin-bottom: 25px; border-top: 5px solid var(--navy-dark);}


        .pet-header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #f8fafc; padding-bottom: 20px; margin-bottom: 25px; }



        .pet-title h1 { font-size: 28px; font-weight: 800; display: flex; align-items: center; gap: 15px; margin-bottom: 5px; color: var(--navy-dark);}



        .pet-title p { color: var(--text-muted); font-size: 15px; font-weight: 500;}


        .owner-summary { display: flex; flex-direction: column; align-items: flex-end; gap: 8px; }

        .status-badge { background: #f1f5f9; color: var(--navy-dark); padding: 8px 16px; border-radius: 8px; font-weight: 700; font-size: 13px; border: 1px solid var(--border); display: flex; align-items: center; gap: 8px;}



        .status-badge i { color: var(--brand-yellow); font-size: 16px;}

        .owner-contact { color: var(--text-muted); font-size: 12px; font-weight: 600; display: flex; align-items: center; gap: 7px; }

        .owner-contact i { color: var(--navy-dark); }



        .info-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 20px; margin-bottom: 35px; }



        .info-item { background: #f8fafc; padding: 20px; border-radius: 12px; border: 1px solid var(--border); border-left: 4px solid var(--brand-yellow); transition: 0.2s; }



        .info-item:hover { transform: translateY(-3px); box-shadow: 0 6px 12px rgba(0,0,0,0.05); }



        .info-item span { display: block; font-size: 11px; text-transform: uppercase; font-weight: 800; color: var(--text-muted); margin-bottom: 5px; letter-spacing: 0.5px;}



        .info-item strong { font-size: 18px; color: var(--navy-dark); font-weight: 700;}


        .section-title { font-size: 18px; font-weight: 800; margin-bottom: 15px; display: flex; align-items: center; gap: 10px; color: var(--navy-dark);}


        .text-box { background: #f8fafc; border: 1px solid var(--border); border-radius: 12px; padding: 25px; color: var(--text-main); font-size: 14px; line-height: 1.6; min-height: 100px; margin-bottom: 35px; font-weight: 500; border-left: 4px solid var(--navy-dark);}

        /* --- TRANSACTION HISTORY TABLE STYLES --- */



        .table-responsive { overflow-x: auto; background: var(--white); border: 1px solid var(--border); border-radius: 12px; }



        .history-table { width: 100%; border-collapse: collapse; text-align: left; }



        .history-table th { background: #f8fafc; padding: 15px; font-size: 12px; text-transform: uppercase; color: var(--text-muted); border-bottom: 2px solid var(--border); font-weight: 700; letter-spacing: 0.5px;}



        .history-table td { padding: 15px; border-bottom: 1px solid var(--border); font-size: 14px; vertical-align: middle; font-weight: 500;}



        .history-table tr:hover td { background-color: #f8fafc; }



        .history-table tr:last-child td { border-bottom: none; }







        .trn-id { font-family: monospace; font-weight: 800; color: #0284c7; background: #e0f2fe; padding: 4px 8px; border-radius: 6px; font-size: 12px; letter-spacing: 0.5px;}







        .status-pill { padding: 6px 14px; border-radius: 6px; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; box-shadow: inset 0 0 0 1px rgba(0,0,0,0.05);}



        .status-completed { background: #dcfce7; color: #166534; }



        .status-pending { background: #fef3c7; color: #92400e; }



        .status-cancelled { background: #fee2e2; color: #991b1b; }



        .status-confirmed { background: #dbeafe; color: #1e40af; }



        .status-no-show { background: #ffedd5; color: #ea580c; border: 1px solid #fdba74;}







        @media (max-width: 900px) {

            body {

                padding: 20px 14px;

                overflow-x: hidden;

            }



            .container {

                max-width: 100%;

            }



            .header-actions {

                margin-bottom: 14px;

            }



            .btn-back {

                width: 100%;

                justify-content: center;

                padding: 10px 12px;

                font-size: 12px;

            }



            .card {

                padding: 18px 14px;

                border-radius: 13px;

                margin-bottom: 14px;

                border-top-width: 4px;

            }



            .pet-header {

                flex-direction: column;

                gap: 10px;

                padding-bottom: 13px;

                margin-bottom: 16px;

            }



            .pet-title h1 {

                font-size: 20px;

                line-height: 1.25;

                gap: 8px;

            }



            .pet-title h1 > div {

                width: 36px !important;

                height: 36px !important;

                flex: 0 0 36px;

            }



            .pet-title h1 > div i {

                font-size: 19px !important;

            }



            .pet-title p {

                margin-left: 44px !important;

                margin-top: 2px;

                font-size: 11px;

                line-height: 1.45;

            }



            .owner-summary {




                width: 100%;




                align-items: stretch;




            }





            .status-badge {




                width: 100%;




                justify-content: center;

                padding: 8px 10px;

                font-size: 11px;

                line-height: 1.3;

                text-align: center;

                white-space: normal;

            }



            .owner-contact {




                justify-content: center;




                font-size: 10px;




            }





            .info-grid {




                grid-template-columns: repeat(2, minmax(0, 1fr));

                gap: 8px;

                margin-bottom: 20px;

            }



            .info-item {

                padding: 12px 11px;

                border-radius: 10px;

                border-left-width: 3px;

            }



            .info-item span {

                font-size: 9px;

                margin-bottom: 3px;

            }



            .info-item strong {

                font-size: 14px;

                overflow-wrap: anywhere;

            }



            .section-title {

                font-size: 14px;

                line-height: 1.3;

                gap: 7px;

                margin-bottom: 9px;

            }



            .text-box {

                padding: 13px 12px;

                min-height: 60px;

                margin-bottom: 20px;

                font-size: 11px;

                line-height: 1.55;

                border-left-width: 3px;

                overflow-wrap: anywhere;

            }



            .table-responsive {

                overflow-x: auto;

                -webkit-overflow-scrolling: touch;

                border-radius: 9px;

            }



            .history-table {

                min-width: 620px;

            }



            .history-table th,

            .history-table td {

                padding: 9px 10px;

                font-size: 10px;

            }



            .history-table td strong,

            .history-table td small {

                white-space: normal;

            }



            .trn-id {

                font-size: 9px;

                padding: 3px 6px;

            }



            .status-pill {

                padding: 4px 8px;

                font-size: 8px;

                white-space: nowrap;

            }

        }



        @media (max-width: 420px) {

            body {

                padding: 14px 10px 20px;

            }



            .card {

                padding: 16px 11px;

                border-radius: 12px;

            }



            .pet-title h1 {

                font-size: 18px;

            }



            .pet-title p {

                margin-left: 0 !important;

                font-size: 10px;

            }



            .info-grid {

                gap: 7px;

            }



            .info-item {

                padding: 11px 9px;

            }



            .info-item strong {

                font-size: 13px;

            }



            .section-title {

                font-size: 13px;

            }



            .text-box {

                padding: 12px 10px;

                font-size: 10px;

                margin-bottom: 17px;

            }



            .history-table {

                min-width: 590px;

            }

        }



        </style>



</head>



<body>

    <div class="container">



        <div class="header-actions">



            <a href="<?php echo $back_link; ?>" class="btn-back"><i class="fas fa-arrow-left"></i> Back to Directory</a>



        </div>

        <div class="card">



            <div class="pet-header">



                <div class="pet-title">



                    <h1>



                        <div style="background: #f1f5f9; width: 45px; height: 45px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">



                            <i class="fas fa-paw" style="color: var(--navy-dark); font-size: 24px;"></i>



                        </div>



                        <?php echo htmlspecialchars((string)$p_name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>



                    </h1>



                    <p style="margin-left: 60px;">Complete Pet Profile</p>



                </div>



                <div class="owner-summary">
                    <div class="status-badge"><i class="fas fa-user-check"></i> Owner: <?php echo htmlspecialchars((string)$o_name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                    <div class="owner-contact"><i class="fas fa-phone"></i> <?php echo htmlspecialchars((string)$o_contact, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                </div>



            </div>


            <div class="info-grid">
                <div class="info-item">
                    <span>Pet Type</span>
                    <strong><?php echo htmlspecialchars((string)$p_type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></strong>
                </div>

                <div class="info-item">
                    <span>Breed</span>
                    <strong><?php echo htmlspecialchars((string)$p_breed, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></strong>
                </div>

                <div class="info-item">
                    <span>Gender</span>
                    <strong><?php echo htmlspecialchars((string)$p_gender, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></strong>
                </div>

                <div class="info-item">
                    <span>Age</span>
                    <strong><?php echo htmlspecialchars((string)$p_age, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></strong>
                </div>

                <div class="info-item">
                    <span>Weight</span>
                    <strong><?php echo htmlspecialchars((string)$p_weight, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></strong>
                </div>

                <div class="info-item">
                    <span>Pet ID</span>
                    <strong>#<?php echo (int)$pet_id; ?></strong>
                </div>
            </div>

            <h3 class="section-title"><i class="fas fa-notes-medical" style="color: #ef4444;"></i> Medical History / Alerts</h3>

            <div class="text-box">

                <?php echo nl2br(htmlspecialchars((string)$med_history, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')); ?>

            </div>

            <h3 class="section-title"><i class="fas fa-clipboard-list" style="color: var(--navy-dark);"></i> Special Needs / Care Instructions</h3>

            <div class="text-box" style="border-left-color: var(--brand-yellow);">

                <?php echo nl2br(htmlspecialchars((string)$special_needs, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')); ?>

            </div>


            <h3 class="section-title"><i class="fas fa-history" style="color: var(--navy-dark);"></i> Transaction & Schedule History</h3>

            <div class="table-responsive">

                <table class="history-table">

                    <thead>

                        <tr>

                            <th>Transaction ID</th>

                            <th>Schedule (Date & Time)</th>

                            <th>Service Type</th>

                            <th>Service Fee</th>

                            <th>Status</th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php if (count($appointments) > 0): ?>

                            <?php foreach ($appointments as $appt): 

                                // Gagawa tayo ng Unique Transaction ID gamit ang Year-Month at ID ng booking

                                $appointment_timestamp = !empty($appt['appointment_date'])
                                    ? strtotime((string)$appt['appointment_date'])
                                    : false;
                                $date_part = $appointment_timestamp !== false ? date('Ym', $appointment_timestamp) : '000000';
                                $appointment_id = (int)($appt['id'] ?? 0);
                                $trn_id = 'TRN-' . $date_part . '-' . str_pad((string)$appointment_id, 4, '0', STR_PAD_LEFT);

                                // Format Date and Time defensively in case legacy rows are incomplete.
                                $sched_date = $appointment_timestamp !== false ? date('M d, Y', $appointment_timestamp) : 'Date unavailable';
                                $time_timestamp = !empty($appt['appointment_time'])
                                    ? strtotime((string)$appt['appointment_time'])
                                    : false;
                                $sched_time = $time_timestamp !== false ? date('g:i A', $time_timestamp) : '';

                                // Display the current service fee when it is numeric and greater than zero.
                                $service_fee = $appt['service_fee'] ?? null;
                                $fee = is_numeric($service_fee) && (float)$service_fee > 0
                                    ? '₱' . number_format((float)$service_fee, 2)
                                    : 'TBD';

                                // Status class is restricted to safe CSS-token characters.
                                $status = (string)($appt['booking_status'] ?? 'Pending');
                                $status_class = preg_replace('/[^a-z0-9-]+/', '-', strtolower(trim($status))) ?: 'pending';

                            ?>

                                <tr>

                                    <td><span class="trn-id"><?php echo $trn_id; ?></span></td>

                                    <td>

                                        <strong style="color: var(--navy-dark);"><?php echo $sched_date; ?></strong><br>

                                        <?php if($sched_time): ?>

                                            <small style="color: var(--text-muted); font-weight: 600;"><i class="far fa-clock"></i> <?php echo $sched_time; ?></small>


                                        <?php endif; ?>

                                    </td>

                                    <td><?php echo htmlspecialchars((string)($appt['service'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>

                                    <td style="font-weight: 800; color: #10b981; font-size: 15px;"><?php echo $fee; ?></td>

                                    <td><span class="status-pill status-<?php echo $status_class; ?>"><?php echo htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span></td>

                                </tr>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <tr>

                                <td colspan="5" style="text-align: center; color: #94a3b8; padding: 60px 30px;">

                                    <?php if ($appointments_load_error): ?>
                                        <i class="fas fa-triangle-exclamation" style="font-size: 40px; margin-bottom: 15px; opacity: 0.5;"></i><br>
                                        <span style="font-weight: 500;">Unable to load appointment history right now. Please refresh the page or try again later.</span>
                                    <?php else: ?>
                                        <i class="fas fa-calendar-times" style="font-size: 40px; margin-bottom: 15px; opacity: 0.3;"></i><br>
                                        <span style="font-weight: 500;">No appointment or transaction history found for this pet.</span>
                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</body>
</html>