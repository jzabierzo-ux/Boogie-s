<?php
require_once __DIR__ . '/../shared_session_bootstrap.php';

/* -------------------------------------------------------------------------
 * Veterinary Team authentication and role normalization.
 * This page is not available to Admin or non-veterinary personnel roles.
 * ---------------------------------------------------------------------- */
$rawRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
$roleAliases = [
    'vet' => 'veterinarian',
    'veterinary' => 'veterinarian',
    'veterinarian' => 'veterinarian',
    'vet assistant' => 'vet_assistant',
    'veterinary assistant' => 'vet_assistant',
    'veterinary_assistant' => 'vet_assistant',
    'vet_assistant' => 'vet_assistant',
    'vet nurse' => 'vet_nurse',
    'veterinary nurse' => 'vet_nurse',
    'veterinary_nurse' => 'vet_nurse',
    'vet_nurse' => 'vet_nurse',
];
$currentRole = $roleAliases[$rawRole] ?? $rawRole;
$loggedIn = ($_SESSION['logged_in'] ?? false) === true;
$staffLoggedIn = ($_SESSION['staff_logged_in'] ?? false) === true;
$userId = filter_var(
    $_SESSION['user_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$allowedVeterinaryRoles = ['veterinarian', 'vet_assistant', 'vet_nurse'];

if (
    !$loggedIn ||
    !$staffLoggedIn ||
    !in_array($currentRole, $allowedVeterinaryRoles, true) ||
    $userId === false ||
    $userId === null
) {
    header('Location: stafflogin.php');
    exit();
}

// Keep the normalized role in the session for consistency across Staff pages.
$_SESSION['role'] = $currentRole;
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$petData = null;
$errorMessage = '';
$customers = [];

/** Read a POST field only when it is a string, preventing array-to-string errors. */
function staffEditPetPostString(string $key): ?string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : null;
}

/** Show a safe alert and return to the Staff pet directory. */
function staffEditPetAlertRedirect(string $message, int $statusCode = 200): void
{
    http_response_code($statusCode);
    $safeMessage = json_encode(
        $message,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
    );
    echo '<script>alert(' . $safeMessage . '); window.location.href="pets.php";</script>';
    exit();
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Method not allowed.');
}

// On submit, trust the hidden POST ID; on initial load, use the query string.
$rawPetId = $method === 'POST' ? ($_POST['pet_id'] ?? null) : ($_GET['id'] ?? null);
$petId = filter_var($rawPetId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($petId === false || $petId === null) {
    staffEditPetAlertRedirect('Invalid pet ID.', 400);
}
$petId = (int)$petId;

// Load the database only after the session and request checks have passed.
require_once __DIR__ . '/../db_supabase.php';

// Load only the pet fields used by this page and its update form.
try {
    $petStmt = $pdo->prepare(
        'SELECT id, owner_id, name, pet_type, breed, age, weight, gender, medical_history, status
         FROM pets
         WHERE id = :id
         LIMIT 1'
    );
    $petStmt->execute([':id' => $petId]);
    $petData = $petStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Staff pet lookup failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load the pet record. Please try again.');
}

if (!$petData) {
    staffEditPetAlertRedirect('Pet record not found.', 404);
}

if ($method === 'POST') {
    $submittedCsrf = $_POST['csrf_token'] ?? '';
    if (
        !is_string($submittedCsrf) ||
        $submittedCsrf === '' ||
        !hash_equals($csrfToken, $submittedCsrf)
    ) {
        $errorMessage = 'Invalid form request. Please refresh the page and try again.';
    } else {
        $rawOwnerId = $_POST['customer_id'] ?? '';
        $ownerId = is_string($rawOwnerId) || is_int($rawOwnerId)
            ? filter_var($rawOwnerId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;

        $petName = staffEditPetPostString('pet_name');
        $petType = staffEditPetPostString('pet_type');
        $breed = staffEditPetPostString('breed');
        $age = staffEditPetPostString('age');
        $weight = staffEditPetPostString('weight');
        $gender = staffEditPetPostString('gender');
        $medicalHistory = staffEditPetPostString('medical_history');

        if (
            $ownerId === false || $ownerId === null ||
            $petName === null || $petType === null || $breed === null ||
            $age === null || $weight === null || $gender === null ||
            $medicalHistory === null
        ) {
            $errorMessage = 'Invalid pet record data. Please review the form and try again.';
        } elseif (
            $petName === '' || $petType === '' || $breed === '' ||
            $age === '' || $weight === '' || $gender === ''
        ) {
            $errorMessage = 'Please complete all required fields.';
        } elseif (!in_array($petType, ['Dog', 'Cat', 'Other'], true)) {
            $errorMessage = 'Invalid pet type selected.';
        } elseif (!in_array($gender, ['Male', 'Female'], true)) {
            $errorMessage = 'Invalid gender selected.';
        } else {
            // Preserve the existing workflow: edited Staff pet records become Approved.
            $status = 'Approved';

            try {
                $pdo->beginTransaction();

                // Lock the target pet to avoid updating a record deleted or changed concurrently.
                $lockPetStmt = $pdo->prepare(
                    'SELECT id FROM pets WHERE id = :pet_id LIMIT 1 FOR UPDATE'
                );
                $lockPetStmt->execute([':pet_id' => $petId]);
                if (!$lockPetStmt->fetchColumn()) {
                    throw new RuntimeException('Pet record no longer exists. Please return to the pet directory.');
                }

                // Confirm and lock the selected owner as an actual customer account.
                $ownerStmt = $pdo->prepare(
                    "SELECT id FROM users WHERE id = :owner_id AND role = 'customer' LIMIT 1 FOR KEY SHARE"
                );
                $ownerStmt->execute([':owner_id' => (int)$ownerId]);
                if (!$ownerStmt->fetchColumn()) {
                    $pdo->rollBack();
                    $errorMessage = 'Invalid customer selected.';
                } else {
                    $updateStmt = $pdo->prepare(
                        'UPDATE pets
                         SET owner_id = :owner_id,
                             name = :pet_name,
                             pet_type = :pet_type,
                             breed = :breed,
                             age = :age,
                             weight = :weight,
                             gender = :gender,
                             medical_history = :medical_history,
                             status = :status
                         WHERE id = :pet_id
                         RETURNING id'
                    );
                    $updateStmt->execute([
                        ':owner_id' => (int)$ownerId,
                        ':pet_name' => $petName,
                        ':pet_type' => $petType,
                        ':breed' => $breed,
                        ':age' => $age,
                        ':weight' => $weight,
                        ':gender' => $gender,
                        ':medical_history' => $medicalHistory,
                        ':status' => $status,
                        ':pet_id' => $petId,
                    ]);

                    $updatedPetId = $updateStmt->fetchColumn();
                    if ($updatedPetId === false) {
                        throw new RuntimeException('Pet record was not updated. Please try again.');
                    }

                    $pdo->commit();
                    staffEditPetAlertRedirect('Pet details successfully updated and approved!');
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Staff pet update failed: ' . $e->getMessage());
                $errorMessage = 'Unable to update the pet record. Please try again.';
            }
        }

        // Keep submitted values visible when validation or database update fails.
        if ($ownerId !== false && $ownerId !== null) {
            $petData['owner_id'] = (int)$ownerId;
        }
        if ($petName !== null) $petData['name'] = $petName;
        if ($petType !== null) $petData['pet_type'] = $petType;
        if ($breed !== null) $petData['breed'] = $breed;
        if ($age !== null) $petData['age'] = $age;
        if ($weight !== null) $petData['weight'] = $weight;
        if ($gender !== null) $petData['gender'] = $gender;
        if ($medicalHistory !== null) $petData['medical_history'] = $medicalHistory;
    }
}

// Customer list for the dropdown; only the fields rendered by the form are fetched.
try {
    $custStmt = $pdo->query(
        "SELECT id, full_name FROM users WHERE role = 'customer' ORDER BY full_name ASC, id ASC"
    );
    $customers = $custStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    error_log('Staff pet customer list lookup failed: ' . $e->getMessage());
    $customers = [];
    $errorMessage = 'Unable to load the customer list. Please try again.';
}

// Maintain the variable names used by the existing HTML form.
$pet_data = $petData;
$error_message = $errorMessage;
$csrf_token = $csrfToken;
$pet_id = $petId;
?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Edit Pet | Staff Portal</title>

    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>

        :root {

            --brand-blue: #1d63ff;

            --bg-light: #f4f7fe;

            --white: #ffffff;

            --text-main: #2d3748;

            --text-muted: #718096;

            --border: #e2e8f0;

            --brand-pink: #e91e63;

        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg-light); color: var(--text-main); }



        .container { max-width: 800px; margin: 40px auto; padding: 0 20px; }



        .back-link { display: inline-flex; align-items: center; gap: 8px; color: var(--brand-blue); text-decoration: none; font-weight: 600; font-size: 14px; margin-bottom: 20px; }



        .form-card { background: var(--white); padding: 40px; border-radius: 16px; border: 1px solid var(--border); box-shadow: 0 5px 15px rgba(0,0,0,0.02); }

        .form-header { text-align: center; margin-bottom: 30px; }

        .form-header .icon-circle { width: 50px; height: 50px; background: var(--brand-pink); color: white; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 20px; margin-bottom: 15px; }

        .form-header h2 { font-size: 24px; color: #1a202c; }



        .form-group { margin-bottom: 20px; }

        .form-group label { display: block; font-size: 13px; font-weight: 600; color: #4a5568; margin-bottom: 8px; }

        .form-control { width: 100%; padding: 12px 15px; border: 1px solid var(--border); border-radius: 8px; font-size: 14px; color: var(--text-main); outline: none; transition: 0.3s; font-family: inherit; }

        .form-control:focus { border-color: var(--brand-blue); box-shadow: 0 0 0 3px rgba(29, 99, 255, 0.1); }



        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }



        .submit-btn { background: var(--brand-pink); color: white; border: none; padding: 14px 24px; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; width: 100%; margin-top: 20px; transition: 0.3s; }

        .submit-btn:hover { opacity: 0.9; transform: translateY(-2px); }



        .alert-box { margin-bottom: 20px; padding: 12px 15px; border-radius: 8px; font-size: 13px; }

        .alert-error { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }



        /* ===== EXTRA MOBILE RESPONSIVENESS ===== */

        @media (max-width: 700px) {

            body {

                overflow-x: hidden;

            }



            .container {

                width: 100%;

                max-width: 100%;

                margin: 24px auto;

                padding: 0 14px;

            }



            .back-link {

                font-size: 13px;

                margin-bottom: 15px;

                min-height: 42px;

            }



            .form-card {

                padding: 24px 16px;

                border-radius: 14px;

            }



            .form-header {

                margin-bottom: 22px;

            }



            .form-header .icon-circle {

                width: 46px;

                height: 46px;

                font-size: 18px;

            }



            .form-header h2 {

                font-size: 21px;

                line-height: 1.3;

            }



            .form-header p {

                font-size: 12px !important;

                line-height: 1.5;

            }



            .form-group {

                margin-bottom: 17px;

            }



            .form-group label {

                font-size: 12px;

                line-height: 1.4;

            }



            .form-control {

                min-height: 46px;

                padding: 11px 12px;

                font-size: 14px;

            }



            textarea.form-control {

                min-height: 150px;

                line-height: 1.6;

            }



            .grid-2 {

                grid-template-columns: 1fr;

                gap: 0;

            }



            .submit-btn {

                min-height: 48px;

                padding: 13px 18px;

                font-size: 14px;

                margin-top: 8px;

            }



            .alert-box {

                font-size: 12px;

                line-height: 1.5;

                overflow-wrap: anywhere;

            }

        }



        @media (max-width: 400px) {

            .container {

                padding: 0 10px;

                margin: 16px auto;

            }



            .form-card {

                padding: 20px 12px;

            }



            .form-header h2 {

                font-size: 19px;

            }



            .form-control {

                font-size: 13px;

            }



            textarea.form-control {

                min-height: 135px;

            }

        }





        /* ===== Mobile-flexible layout additions ===== */

        html { width: 100%; overflow-x: hidden; }

        body { width: 100%; max-width: 100%; }

        img { max-width: 100%; }

        input, select, textarea, button { max-width: 100%; }





        /* ===== Final small-screen containment ===== */

        @media (max-width: 600px) {

            html, body { width:100%; max-width:100%; overflow-x:hidden; }

            .container, .login-card { width:100%; max-width:100%; }

            input, select, textarea, button { width:100%; max-width:100%; }

            img { max-width:100%; height:auto; }

        }





/* ================================================================

   FINAL STAFF TOP BAR FIX — MATCH ADMIN MOBILE BEHAVIOR

   Keep the staff name visible on phones while preventing overflow.

   ================================================================ */

.profile-name-text {

    white-space: nowrap !important;

    min-width: 0 !important;

}



@media (max-width: 640px) {

    .main-content > header,

    .main-content > header:first-child {

        width: 100% !important;

        min-width: 0 !important;

        height: 60px !important;

        min-height: 60px !important;

        padding: 0 10px !important;

        display: flex !important;

        flex-wrap: nowrap !important;

        align-items: center !important;

        gap: 7px !important;

        overflow: visible !important;

    }



    .mobile-menu-btn {

        display: inline-flex !important;

        width: 36px !important;

        min-width: 36px !important;

        height: 36px !important;

        flex: 0 0 36px !important;

        align-items: center !important;

        justify-content: center !important;

    }



    .topbar-left {

        flex: 0 0 auto !important;

        min-width: 0 !important;

    }



    /* On mobile the breadcrumb is unnecessary beside the menu button. */

    .breadcrumb {

        display: none !important;

    }



    .top-right-actions {

        display: flex !important;

        align-items: center !important;

        justify-content: flex-end !important;

        gap: 7px !important;

        flex: 1 1 auto !important;

        min-width: 0 !important;

        margin-left: auto !important;

    }



    .notif-wrapper {

        flex: 0 0 auto !important;

    }



    .notif-wrapper > i {

        font-size: 19px !important;

    }



    .role-label {

        display: inline-flex !important;

        align-items: center !important;

        justify-content: center !important;

        flex: 0 0 auto !important;

        white-space: nowrap !important;

        padding: 4px 7px !important;

        font-size: 8px !important;

    }



    .profile-wrapper {

        display: flex !important;

        align-items: center !important;

        flex: 0 1 auto !important;

        min-width: 0 !important;

        max-width: 150px !important;

        gap: 6px !important;

        padding-left: 7px !important;

        border-left: 1px solid var(--border) !important;

        overflow: visible !important;

    }



    .top-avatar,

    .top-avatar-fallback {

        width: 30px !important;

        height: 30px !important;

        min-width: 30px !important;

        flex: 0 0 30px !important;

    }



    /* Keep the staff name visible like the admin top bar. */

    .profile-name-text {

        display: block !important;

        flex: 0 1 105px !important;

        width: 105px !important;

        min-width: 0 !important;

        max-width: 105px !important;

        overflow: hidden !important;

        text-overflow: ellipsis !important;

        white-space: nowrap !important;

        line-height: 1.2 !important;

        font-size: 12px !important;

        font-weight: 700 !important;

        padding-right: 13px !important;

        position: relative !important;

    }



    .profile-name-text i {

        display: none !important;

    }



    .profile-name-text::after {

        content: '\f078';

        font-family: 'Font Awesome 6 Free';

        font-weight: 900;

        position: absolute;

        right: 0;

        top: 50%;

        transform: translateY(-50%);

        font-size: 9px;

        color: var(--text-muted, #64748b);

        opacity: .7;

    }

}



@media (max-width: 480px) {

    .main-content > header,

    .main-content > header:first-child {

        padding: 0 9px !important;

        gap: 6px !important;

    }



    .top-right-actions {

        gap: 6px !important;

    }



    .role-label {

        padding: 4px 7px !important;

        font-size: 8px !important;

    }



    .profile-wrapper {

        gap: 5px !important;

        max-width: 138px !important;

        padding-left: 6px !important;

    }



    .profile-name-text {

        flex-basis: 92px !important;

        width: 92px !important;

        max-width: 92px !important;

        font-size: 11px !important;

    }

}



@media (max-width: 400px) {

    .role-label {

        display: none !important;

    }



    .profile-wrapper {

        max-width: 135px !important;

        padding-left: 5px !important;

    }



    .profile-name-text {

        flex-basis: 100px !important;

        width: 100px !important;

        max-width: 100px !important;

    }

}





/* ===== FINAL PROFILE NAME FIX v11 ===== */

@media (min-width: 561px) {

    .main-content > header .profile-wrapper > .desktop-profile-name {

        display: inline-flex !important;

        visibility: visible !important;

        opacity: 1 !important;

    }

    .main-content > header .profile-wrapper > .mobile-profile-first-name {

        display: none !important;

        visibility: hidden !important;

    }

}



@media (max-width: 560px) {

    .main-content > header .profile-wrapper > .desktop-profile-name {

        display: none !important;

        visibility: hidden !important;

    }

    .main-content > header .profile-wrapper > .mobile-profile-first-name {

        display: inline-flex !important;

        align-items: center !important;

        flex: 0 1 auto !important;

        width: auto !important;

        min-width: 0 !important;

        max-width: 70px !important;

        overflow: hidden !important;

        text-overflow: ellipsis !important;

        white-space: nowrap !important;

        visibility: visible !important;

        opacity: 1 !important;

    }

}

</style>

</head>

<body>



    <div class="container">

        <a href="pets.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Pet Directory</a>



        <div class="form-card">

            <div class="form-header">

                <div class="icon-circle"><i class="fas fa-paw"></i></div>

                <h2>Edit Pet Profile</h2>

                <p style="color: var(--text-muted); font-size: 14px;">Update official pet profile and tags</p>

            </div>



            <?php if ($error_message !== ''): ?>

                <div class="alert-box alert-error"><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?></div>

            <?php endif; ?>



            <form action="" method="POST">

                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

                <input type="hidden" name="pet_id" value="<?php echo (int)$pet_id; ?>">



                <div class="form-group">

                    <label>Select Customer *</label>

                    <select name="customer_id" class="form-control" required>

                        <?php foreach ($customers as $customer): ?>

                            <?php $selected = ((string)$customer['id'] === (string)$pet_data['owner_id']) ? 'selected' : ''; ?>

                            <option value="<?php echo (int)$customer['id']; ?>" <?php echo $selected; ?>>

                                <?php echo htmlspecialchars($customer['full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>



                <div style="font-weight: 700; margin: 30px 0 15px; font-size: 15px;">Pet Information</div>



                <div class="grid-2">

                    <div class="form-group">

                        <label>Pet Name *</label>

                        <input type="text" name="pet_name" class="form-control" value="<?php echo htmlspecialchars($pet_data['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>

                    </div>

                    <div class="form-group">

                        <label>Pet Type *</label>

                        <select name="pet_type" class="form-control" required>

                            <option value="Dog" <?php echo (isset($pet_data['pet_type']) && $pet_data['pet_type'] === 'Dog') ? 'selected' : ''; ?>>Dog</option>

                            <option value="Cat" <?php echo (isset($pet_data['pet_type']) && $pet_data['pet_type'] === 'Cat') ? 'selected' : ''; ?>>Cat</option>

                            <option value="Other" <?php echo (isset($pet_data['pet_type']) && $pet_data['pet_type'] === 'Other') ? 'selected' : ''; ?>>Other</option>

                        </select>

                    </div>

                </div>



                <div class="grid-2">

                    <div class="form-group">

                        <label>Breed *</label>

                        <input type="text" name="breed" class="form-control" value="<?php echo htmlspecialchars($pet_data['breed'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>

                    </div>

                    <div class="form-group">

                        <label>Age *</label>

                        <input type="text" inputmode="decimal" name="age" class="form-control" value="<?php echo htmlspecialchars($pet_data['age'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>

                    </div>

                </div>



                <div class="grid-2">

                    <div class="form-group">

                        <label>Weight *</label>

                        <input type="text" inputmode="decimal" name="weight" class="form-control" value="<?php echo htmlspecialchars($pet_data['weight'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>

                    </div>

                    <div class="form-group">

                        <label>Gender *</label>

                        <select name="gender" class="form-control" required>

                            <option value="Male" <?php echo (($pet_data['gender'] ?? '') === 'Male') ? 'selected' : ''; ?>>Male</option>

                            <option value="Female" <?php echo (($pet_data['gender'] ?? '') === 'Female') ? 'selected' : ''; ?>>Female</option>

                        </select>

                    </div>

                </div>



                <div class="form-group">

                    <label>Medical History / Alerts</label>

                    <textarea name="medical_history" class="form-control" rows="4"><?php echo htmlspecialchars($pet_data['medical_history'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>

                </div>



                <button type="submit" class="submit-btn">Update Pet Record</button>

            </form>

        </div>

    </div>



</body>

</html>
