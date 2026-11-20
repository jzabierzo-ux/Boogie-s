<?php



require_once __DIR__ . '/../shared_session_bootstrap.php';



require_once __DIR__ . '/../db_supabase.php';

/**
 * Format Supabase notification timestamps in Philippine time.
 * Explicit timezone offsets in the value are respected; values without an
 * offset are treated as UTC, matching the customer dashboard display.
 */
function formatCustomerNotificationTimestamp($value, string $format = 'M d, Y h:i A'): string
{
    if ($value === null || trim((string)$value) === '') {
        return '';
    }

    try {
        $notificationDate = new DateTimeImmutable((string)$value, new DateTimeZone('UTC'));
        return $notificationDate
            ->setTimezone(new DateTimeZone('Asia/Manila'))
            ->format($format);
    } catch (Throwable $e) {
        error_log('Customer notification timestamp formatting failed: ' . $e->getMessage());
        return '';
    }
}








// 1. SECURITY: Check if logged in



if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0 || strtolower(trim((string)($_SESSION['role'] ?? ''))) !== 'customer') {



    header("Location: login.php");



    exit;



}







$user_id = (int)$_SESSION['user_id'];



if (empty($_SESSION['petprofile_csrf'])) {

    $_SESSION['petprofile_csrf'] = bin2hex(random_bytes(32));

}

$petprofile_csrf = $_SESSION['petprofile_csrf'];

// Shared CSRF token used by mark_all_read.php for the notification dropdown.
if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}




// AJAX endpoint: return the authenticated customer's latest notifications and unread count.
// This endpoint is intentionally placed after authentication and scopes both queries by user_id.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && (($_GET['ajax_notifications'] ?? '') === '1')) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, private, max-age=0, must-revalidate');
    header('Pragma: no-cache');

    try {
        $ajax_count_stmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM notifications\n            WHERE user_id = :user_id\n              AND (LOWER(CAST(is_read AS TEXT)) IN ('0', 'false', 'f') OR is_read IS NULL)\n        ");
        $ajax_count_stmt->execute([':user_id' => $user_id]);
        $ajax_unread_count = (int)($ajax_count_stmt->fetchColumn() ?? 0);

        $ajax_list_stmt = $pdo->prepare("\n            SELECT id, message, created_at, is_read\n            FROM notifications\n            WHERE user_id = :user_id\n            ORDER BY created_at DESC, id DESC\n            LIMIT 5\n        ");
        $ajax_list_stmt->execute([':user_id' => $user_id]);

        $ajax_notifications = [];
        while ($row = $ajax_list_stmt->fetch(PDO::FETCH_ASSOC)) {
            $raw_is_read = strtolower(trim((string)($row['is_read'] ?? '')));
            $ajax_notifications[] = [
                'id' => (string)($row['id'] ?? ''),
                'message' => (string)($row['message'] ?? ''),
                'is_read' => in_array($raw_is_read, ['1', 'true', 't', 'yes'], true),
                'time' => formatCustomerNotificationTimestamp($row['created_at'] ?? null, 'M d, Y h:i A'),
            ];
        }

        echo json_encode([
            'success' => true,
            'unread_count' => $ajax_unread_count,
            'notifications' => $ajax_notifications,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        error_log('Pet profile notification refresh failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Unable to refresh notifications right now.',
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    exit();
}


$full_name = isset($_SESSION['user_name'])



    ? $_SESSION['user_name']



    : (isset($_SESSION['full_name']) ? $_SESSION['full_name'] : 'User');







// --- FETCH UNREAD NOTIFICATIONS COUNT FOR HEADER ---



try {



    $notif_header_stmt = $pdo->prepare("



        SELECT COUNT(*) AS unread



        FROM notifications



        WHERE user_id = :user_id



          AND (LOWER(CAST(is_read AS TEXT)) IN ('0', 'false', 'f') OR is_read IS NULL)



    ");



    $notif_header_stmt->execute([



        ':user_id' => $user_id



    ]);



    $unread_count = (int)($notif_header_stmt->fetchColumn() ?? 0);



} catch (PDOException $e) {



    error_log("Notification count query failed: " . $e->getMessage());



    $unread_count = 0;



}







// --- FETCH LATEST 5 NOTIFICATIONS FOR DROPDOWN ---



$notifications = [];







try {



    $stmt_notif_list = $pdo->prepare("



        SELECT id, message, created_at, is_read



        FROM notifications



        WHERE user_id = :user_id



        ORDER BY created_at DESC, id DESC



        LIMIT 5



    ");







    $stmt_notif_list->execute([



        ':user_id' => $user_id



    ]);







    $notifications = $stmt_notif_list->fetchAll(PDO::FETCH_ASSOC);



} catch (PDOException $e) {



    error_log("Notification list query failed: " . $e->getMessage());



}







// --- UPDATED: Fetching profile_image ONLY (Removed max_pets since limit is abolished) ---



try {



    $user_stmt = $pdo->prepare("



        SELECT profile_image



        FROM users



        WHERE id = :user_id



        LIMIT 1



    ");



    $user_stmt->execute([



        ':user_id' => $user_id



    ]);







    $user_data = $user_stmt->fetch(PDO::FETCH_ASSOC) ?: [];



    $profile_image = $user_data['profile_image'] ?? null;



} catch (PDOException $e) {



    error_log("Profile image query failed: " . $e->getMessage());



    $profile_image = null;
}

// Normalize customer profile image paths after the Customer Side moved into /customer.
// Stored paths such as uploads/photo.jpg or customer/uploads/photo.jpg resolve to /uploads.
$profile_image_src = '';
$stored_profile_image = trim((string)($profile_image ?? ''));
if ($stored_profile_image !== '' && basename($stored_profile_image) !== 'default-avatar.png') {
    if (preg_match('~^(?:https?://|data:)~i', $stored_profile_image)) {
        $profile_image_src = $stored_profile_image;
    } else {
        $image_path = ltrim($stored_profile_image, '/');
        $image_path = preg_replace('~^(?:customer/)?uploads/~i', '', $image_path);
        $profile_filename = basename($image_path);
        if ($profile_filename !== '' && $profile_filename !== '.' && $profile_filename !== '..') {
            $profile_image_src = '/uploads/' . rawurlencode($profile_filename);
        }
    }
}

// Check how many pets the user currently has



try {



    $count_stmt = $pdo->prepare("



        SELECT COUNT(*) AS pet_count



        FROM pets



        WHERE owner_id = :user_id



    ");



    $count_stmt->execute([



        ':user_id' => $user_id



    ]);







    $current_pet_count = (int)($count_stmt->fetchColumn() ?? 0);



} catch (PDOException $e) {



    error_log("Pet count query failed: " . $e->getMessage());



    $current_pet_count = 0;



}







// --- 2. LOGIC PARA SA PAG-ADD NG PET (Unlimited now) ---



if (isset($_POST['add_pet'])) {

    $submitted_csrf = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($petprofile_csrf, $submitted_csrf)) {

        http_response_code(403);

        exit('Invalid request. Refresh the page and try again.');

    }







    // Kinukuha na rin natin yung pet_type, breed at gender para kumpleto sa display



    $pet_name = trim($_POST['name'] ?? '');



    $pet_type = trim($_POST['pet_type'] ?? '');



    $breed = trim($_POST['breed'] ?? '');



    $age = trim($_POST['age'] ?? '');

    $weight = trim((string)($_POST['weight'] ?? ''));



    $gender = trim($_POST['gender'] ?? '');

    // Optional customer-provided health and care information.
    $medical_history_input = $_POST['medical_history'] ?? '';
    $special_needs_input = $_POST['special_needs'] ?? '';
    if (!is_string($medical_history_input) || !is_string($special_needs_input)) {
        http_response_code(400);
        exit('Invalid pet care information.');
    }
    $medical_history = trim($medical_history_input);
    $special_needs = trim($special_needs_input);

    if (strlen($medical_history) > 4000 || strlen($special_needs) > 4000) {
        http_response_code(400);
        exit('Pet care information must not exceed 4,000 characters per field.');
    }

    if ($pet_name === '' || $pet_type === '' || $breed === '' || $age === '' || $weight === '' || $gender === '') {

        http_response_code(400);

        exit("Please complete all required pet fields.");

    }



    if (!in_array($pet_type, ['Dog', 'Cat'], true) ||

        !in_array($gender, ['Male', 'Female', 'Unknown'], true) ||

        strlen($pet_name) > 120 || strlen($breed) > 120 || strlen($age) > 40 ||
        !is_numeric($age) || (float)$age < 0 ||
        !is_numeric($weight) || (float)$weight <= 0 || (float)$weight > 1000) {

        http_response_code(400);

        exit('Please check the pet type, gender, and field lengths.');

    }







    try {



        // Insert Query: Automatically approved, no status needed



        $insert_pet_stmt = $pdo->prepare("



            INSERT INTO pets (



                owner_id,



                name,



                pet_type,



                breed,



                age,
                weight,
                gender,
                medical_history,
                special_needs,
                created_at



            )



            VALUES (



                :owner_id,



                :name,



                :pet_type,



                :breed,



                :age,
                :weight,



                :gender,
                :medical_history,
                :special_needs,



                CURRENT_TIMESTAMP



            )



        ");







        $insert_pet_stmt->execute([



            ':owner_id' => $user_id,



            ':name' => $pet_name,



            ':pet_type' => $pet_type,



            ':breed' => $breed,



            ':age' => $age,

            ':weight' => (float)$weight,



            ':gender' => $gender,
            ':medical_history' => $medical_history,
            ':special_needs' => $special_needs



        ]);







        // --- 3. LOGIC PARA SA NOTIFICATION ---



        try {

            $notif_title = "New Pet Profile Created!";



            $raw_notif_message = "A new pet profile for '$pet_name' has been successfully registered to your account.";



            $notif_type = "announcement";







            $notif_stmt = $pdo->prepare("



                INSERT INTO notifications (



                    user_id,



                    title,



                    message,



                    type,



                    is_read,



                    created_at



                )



                VALUES (



                    :user_id,



                    :title,



                    :message,



                    :type,



                    0,



                    CURRENT_TIMESTAMP



                )



            ");







            $notif_stmt->execute([



                ':user_id' => $user_id,



                ':title' => $notif_title,



                ':message' => $raw_notif_message,



                ':type' => $notif_type



            ]);



        } catch (PDOException $notificationError) {

            error_log("Pet created but notification insert failed: " . $notificationError->getMessage());

        }



        header("Location: petprofile.php?success=1");



        exit();



    } catch (PDOException $e) {



        error_log("Pet insert failed: " . $e->getMessage());



        die("Error inserting pet.");



    }



}







// --- CREATE A GROUP FROM EXISTING REGISTERED PETS ---
// The legacy pet_groups.mother_pet_id column is retained as the group's primary pet
// so existing database records and related pages remain compatible. No new pet rows
// are created here; every selected pet must already belong to the authenticated user.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['create_pet_group'])) {
    $submitted_csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($petprofile_csrf, $submitted_csrf)) {
        http_response_code(403);
        exit('Invalid request. Refresh the page and try again.');
    }

    $raw_group_name = $_POST['group_name'] ?? '';
    $group_name = is_string($raw_group_name) || is_numeric($raw_group_name) ? trim((string)$raw_group_name) : '';
    $raw_group_pet_ids = $_POST['group_pet_ids'] ?? [];

    if (!is_array($raw_group_pet_ids)) {
        $_SESSION['pet_group_flash_error'] = 'Please select at least two registered pets for the group.';
        header('Location: petprofile.php?group_error=1');
        exit();
    }

    if (strlen($group_name) > 120 || count($raw_group_pet_ids) < 2 || count($raw_group_pet_ids) > 20) {
        $_SESSION['pet_group_flash_error'] = 'Enter a group name with up to 120 characters and select 2 to 20 registered pets.';
        header('Location: petprofile.php?group_error=1');
        exit();
    }

    $group_pet_ids = [];
    foreach ($raw_group_pet_ids as $raw_pet_id) {
        if (!is_scalar($raw_pet_id)) {
            $_SESSION['pet_group_flash_error'] = 'The selected pet list is invalid. Please try again.';
            header('Location: petprofile.php?group_error=1');
            exit();
        }
        $pet_id = filter_var((string)$raw_pet_id, FILTER_VALIDATE_INT);
        if ($pet_id === false || $pet_id <= 0) {
            $_SESSION['pet_group_flash_error'] = 'One of the selected pet profiles is invalid.';
            header('Location: petprofile.php?group_error=1');
            exit();
        }
        $group_pet_ids[] = (int)$pet_id;
    }

    if (count(array_unique($group_pet_ids)) !== count($group_pet_ids)) {
        $_SESSION['pet_group_flash_error'] = 'A pet can only be selected once in a group.';
        header('Location: petprofile.php?group_error=1');
        exit();
    }

    try {
        $pdo->beginTransaction();

        $pet_placeholders = implode(',', array_fill(0, count($group_pet_ids), '?'));
        $owned_pets_stmt = $pdo->prepare("
            SELECT id, name, pet_type, breed
            FROM pets
            WHERE owner_id = ? AND id IN ($pet_placeholders)
            ORDER BY id ASC
            FOR UPDATE
        ");
        $owned_pets_stmt->execute(array_merge([$user_id], $group_pet_ids));
        $owned_pets = $owned_pets_stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($owned_pets) !== count($group_pet_ids)) {
            throw new DomainException('One or more selected pets are not registered under your account. Refresh the page and try again.');
        }

        // A registered pet may belong to only one group at a time, whether it is
        // stored as the legacy primary pet or as a regular group member.
        $already_grouped_stmt = $pdo->prepare("
            SELECT p.id, p.name
            FROM pets p
            WHERE p.owner_id = ?
              AND p.id IN ($pet_placeholders)
              AND (
                    EXISTS (
                        SELECT 1 FROM pet_groups pg
                        WHERE pg.owner_id = p.owner_id AND pg.mother_pet_id = p.id
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM pet_group_members gm
                        INNER JOIN pet_groups pgm ON pgm.id = gm.group_id
                        WHERE pgm.owner_id = p.owner_id AND gm.pet_id = p.id
                    )
              )
            ORDER BY p.name ASC
        ");
        $already_grouped_stmt->execute(array_merge([$user_id], $group_pet_ids));
        $already_grouped = $already_grouped_stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($already_grouped)) {
            throw new DomainException('One or more selected pets already belong to a group. Select pets that are not grouped yet.');
        }

        $pets_by_id = [];
        foreach ($owned_pets as $owned_pet) {
            $pets_by_id[(int)$owned_pet['id']] = $owned_pet;
        }
        $primary_pet = $pets_by_id[$group_pet_ids[0]] ?? null;
        if (!$primary_pet) {
            throw new DomainException('The primary pet could not be identified. Please try again.');
        }

        if ($group_name === '') {
            $primary_name = trim((string)($primary_pet['name'] ?? ''));
            $group_name = ($primary_name !== '' ? $primary_name : 'Pet') . "'s Group";
        }
        if (function_exists('mb_substr')) {
            $group_name = mb_substr($group_name, 0, 120, 'UTF-8');
        } else {
            $group_name = substr($group_name, 0, 120);
        }

        // Keep the first selected pet in mother_pet_id for compatibility with
        // the existing schema; the UI now calls it the Primary Pet, not a mother.
        $create_group_stmt = $pdo->prepare("
            INSERT INTO pet_groups (owner_id, mother_pet_id, group_name, created_at)
            VALUES (:owner_id, :primary_pet_id, :group_name, CURRENT_TIMESTAMP)
            RETURNING id
        ");
        $create_group_stmt->execute([
            ':owner_id' => $user_id,
            ':primary_pet_id' => (int)$primary_pet['id'],
            ':group_name' => $group_name,
        ]);
        $group_id = (int)$create_group_stmt->fetchColumn();
        if ($group_id <= 0) {
            throw new RuntimeException('The pet group could not be created.');
        }

        $insert_member_stmt = $pdo->prepare("
            INSERT INTO pet_group_members (group_id, pet_id, relationship, created_at)
            VALUES (:group_id, :pet_id, 'member', CURRENT_TIMESTAMP)
        ");
        foreach (array_slice($group_pet_ids, 1) as $member_pet_id) {
            $insert_member_stmt->execute([
                ':group_id' => $group_id,
                ':pet_id' => $member_pet_id,
            ]);
        }

        $pdo->commit();
        $_SESSION['pet_group_flash_success'] = count($group_pet_ids) . ' existing pet profiles were added to ' . $group_name . '. No new pet profiles were created.';
        header('Location: petprofile.php?success=group_created');
        exit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Pet group creation failed: ' . $e->getMessage());
        $safe_error = $e instanceof DomainException ? $e->getMessage() : 'Unable to create the group right now. Please check the Pet Group database setup and try again.';
        $_SESSION['pet_group_flash_error'] = $safe_error;
        header('Location: petprofile.php?group_error=1');
        exit();
    }
}

// --- LOGIC PARA SA PAG-DELETE NG PET (POST + CSRF) ---

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_pet'])) {

    $submitted_csrf = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($petprofile_csrf, $submitted_csrf)) {

        http_response_code(403);

        exit('Invalid request. Refresh the page and try again.');

    }



    $delete_id = filter_var($_POST['pet_id'] ?? null, FILTER_VALIDATE_INT);

    if (!$delete_id || $delete_id <= 0) {

        http_response_code(400);

        exit('Invalid pet ID.');

    }



    try {

        $delete_stmt = $pdo->prepare("DELETE FROM pets WHERE id = :delete_id AND owner_id = :owner_id");

        $delete_stmt->execute([':delete_id' => $delete_id, ':owner_id' => $user_id]);

        header("Location: petprofile.php?success=deleted");

        exit();

    } catch (PDOException $e) {

        error_log("Pet delete failed: " . $e->getMessage());

        http_response_code(500);

        exit('Unable to delete this pet right now.');

    }

}



// --- 4. FETCH PETS ---



try {



    $pets_stmt = $pdo->prepare("



        SELECT *



        FROM pets



        WHERE owner_id = :user_id



        ORDER BY id DESC



    ");







    $pets_stmt->execute([



        ':user_id' => $user_id



    ]);







    $pets = $pets_stmt->fetchAll(PDO::FETCH_ASSOC);



} catch (PDOException $e) {



    error_log("Pet list query failed: " . $e->getMessage());



    $pets = [];



}



// --- PET GROUPS (EXISTING REGISTERED PET PROFILES) ---
$pet_groups = [];
$pet_group_children = []; // Existing table name retained; these are additional group members.
$eligible_group_pets = [];
$pet_group_schema_ready = true;
$pet_group_flash_success = $_SESSION['pet_group_flash_success'] ?? '';
$pet_group_flash_error = $_SESSION['pet_group_flash_error'] ?? '';
unset($_SESSION['pet_group_flash_success'], $_SESSION['pet_group_flash_error']);

try {
    $groups_stmt = $pdo->prepare("
        SELECT
            g.id AS group_id,
            g.group_name,
            g.created_at AS group_created_at,
            m.id AS primary_pet_id,
            m.name AS primary_pet_name,
            m.pet_type AS primary_pet_type,
            m.breed AS primary_pet_breed,
            m.weight AS primary_pet_weight,
            COUNT(gm.id) AS member_count
        FROM pet_groups g
        INNER JOIN pets m ON m.id = g.mother_pet_id
        LEFT JOIN pet_group_members gm ON gm.group_id = g.id
        WHERE g.owner_id = :owner_id
        GROUP BY g.id, m.id
        ORDER BY g.created_at DESC, g.id DESC
    ");
    $groups_stmt->execute([':owner_id' => $user_id]);
    $pet_groups = $groups_stmt->fetchAll(PDO::FETCH_ASSOC);

    $members_stmt = $pdo->prepare("
        SELECT
            p.id, p.name, p.pet_type, p.breed, p.age, p.weight, p.gender,
            p.medical_history, p.special_needs, gm.relationship
        FROM pet_group_members gm
        INNER JOIN pet_groups g ON g.id = gm.group_id
        INNER JOIN pets p ON p.id = gm.pet_id
        WHERE g.owner_id = :owner_id AND gm.group_id = :group_id
        ORDER BY p.name ASC, p.id ASC
    ");
    foreach ($pet_groups as $group_row) {
        $members_stmt->execute([
            ':owner_id' => $user_id,
            ':group_id' => (int)$group_row['group_id'],
        ]);
        $pet_group_children[(int)$group_row['group_id']] = $members_stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Only pets that do not already act as a group's primary pet or member
    // may be selected for another group.
    $available_group_pets_stmt = $pdo->prepare("
        SELECT p.id, p.name, p.pet_type, p.breed, p.weight, p.gender
        FROM pets p
        WHERE p.owner_id = :owner_id
          AND NOT EXISTS (
              SELECT 1 FROM pet_groups pg
              WHERE pg.owner_id = p.owner_id AND pg.mother_pet_id = p.id
          )
          AND NOT EXISTS (
              SELECT 1
              FROM pet_group_members gm
              INNER JOIN pet_groups pgm ON pgm.id = gm.group_id
              WHERE pgm.owner_id = p.owner_id AND gm.pet_id = p.id
          )
        ORDER BY p.name ASC, p.id ASC
    ");
    $available_group_pets_stmt->execute([':owner_id' => $user_id]);
    $eligible_group_pets = $available_group_pets_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $pet_group_schema_ready = false;
    error_log('Pet group data load failed: ' . $e->getMessage());
}

?>



<!DOCTYPE html>



<html lang="en">



<head>



<meta charset="UTF-8">



<meta name="viewport" content="width=device-width, initial-scale=1.0">



<title>Pet Profiles | Boogie's Pet Care Services - Dasmariñas</title>



<link href="https://fonts.googleapis.com/css2?family=Poppins:wght\@300;400;500;600;700;800&display=swap" rel="stylesheet">



<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">



<style>



:root{



    --brand-blue:#001f3f;



    --brand-blue-2:#0b3b66;



    --brand-yellow:#ffcc00;



    --brand-yellow-soft:#fff7d6;



    --page-bg:#f5f8fb;



    --white:#fff;



    --text:#17324d;



    --muted:#6b7c8f;



    --line:#e3eaf1;



    --soft:#f8fafc;



    --success:#168553;



    --danger:#c73b47;



    --danger-soft:#fff0f1;



    --purple:#7650a8;



    --purple-soft:#f1eaff;



    --shadow:0 10px 30px rgba(0,31,63,.06);



    --shadow-lg:0 18px 42px rgba(0,31,63,.10);



}







*{



    box-sizing:border-box;



    margin:0;



    padding:0;



    font-family:'Poppins',sans-serif;



}







html{scroll-behavior:smooth}







body{



    min-height:100vh;



    background:var(--page-bg);



    color:var(--text);



    line-height:1.6;



    overflow-y:scroll;



    overflow-x:hidden;



    display:flex;



    flex-direction:column;



}







a{color:inherit}



button,input,select{font:inherit}







/* ===== HEADER ===== */



.promo-bar{



    background:var(--brand-blue);



    color:#fff;



    text-align:center;



    padding:7px 16px;



    font-size:12px;



    font-weight:700;



    border-top:3px solid var(--brand-yellow);



}







.promo-bar i{



    color:var(--brand-yellow);



    margin-right:7px;



}







header{



    position:sticky;



    top:0;



    z-index:1000;



    background:rgba(255,255,255,.98);



    border-bottom:1px solid var(--line);



    box-shadow:0 4px 18px rgba(0,0,0,.04);



}







.nav-top{



    width:min(1320px,92%);



    min-height:78px;



    margin:0 auto;



    padding:13px 0;



    display:flex;



    align-items:center;



    justify-content:space-between;



    gap:24px;



}







.logo{



    display:flex;



    align-items:center;



    gap:11px;



    text-decoration:none;



    flex:0 0 auto;



}







.nav-logo-img{



    width:50px;



    height:50px;



    object-fit:contain;



    border-radius:10px;



}







.logo-text{



    display:flex;



    flex-direction:column;



    line-height:1.05;



}







.logo-text b{



    color:var(--brand-blue);



    font-size:20px;



    font-weight:800;



}







.logo-text span{



    margin-top:3px;



    color:#8c9aae;



    font-size:9px;



    font-weight:700;



    letter-spacing:1.2px;



}







.user-controls{



    display:flex;



    align-items:center;



    gap:12px;



}







.notification-wrapper,



.profile-wrapper{



    position:relative;



}







.notification-bell{



    position:relative;



    width:42px;



    height:42px;



    border:1px solid var(--line);



    border-radius:12px;



    background:#fff;



    color:var(--brand-blue);



    display:flex;



    align-items:center;



    justify-content:center;



    cursor:pointer;



}







.notification-bell:hover{



    background:#f8fafc;



}







.notification-badge{



    position:absolute;



    top:-5px;



    right:-5px;



    min-width:18px;



    height:18px;



    padding:0 5px;



    border:2px solid #fff;



    border-radius:999px;



    background:#dc3b45;



    color:#fff;



    display:inline-flex;



    align-items:center;



    justify-content:center;



    font-size:9px;



    font-weight:800;



}







.profile-trigger{



    min-height:42px;



    padding:4px 9px 4px 5px;



    border:1px solid var(--line);



    border-radius:12px;



    background:#fff;



    color:var(--text);



    display:flex;



    align-items:center;



    gap:9px;



    cursor:pointer;



    font-size:13px;



    font-weight:700;



}







.profile-trigger:hover{



    background:#f8fafc;



}







.profile-avatar{



    width:34px;



    height:34px;



    object-fit:cover;



    border-radius:50%;



    border:2px solid var(--brand-blue);



}







.dropdown-menu{



    position:absolute;



    top:calc(100% + 10px);



    right:0;



    width:270px;



    background:#fff;



    border:1px solid var(--line);



    border-radius:14px;



    box-shadow:var(--shadow-lg);



    display:none;



    flex-direction:column;



    overflow:hidden;



    z-index:1100;



}







.dropdown-menu.active{



    display:flex;



}







.dropdown-header{



    padding:14px 16px;



    background:#f8fafc;



    border-bottom:1px solid var(--line);



    color:var(--muted);



    font-size:11px;



    font-weight:800;



    text-transform:uppercase;



    letter-spacing:.7px;



}







.dropdown-item{



    display:block;



    padding:12px 16px;



    border-bottom:1px solid #eef2f5;



    text-decoration:none;



    color:var(--text);



    font-size:12px;



}







.dropdown-item:hover{



    background:#f8fafc;



    color:var(--brand-blue);



}







.dropdown-item.unread{



    background:#eef6ff;



    font-weight:700;



}







.dropdown-item i{



    width:18px;



    margin-right:7px;



    text-align:center;



}







.dropdown-item:last-child{



    border-bottom:0;



}







.view-all-link{



    text-align:center;



    font-weight:800;



    color:var(--brand-blue);



}







/* ===== PAGE ===== */



main{



    width:min(1120px,92%);



    margin:0 auto;



    padding:42px 0 76px;



    flex:1 0 auto;



}







.page-top{



    margin-bottom:24px;



}







.back-link{



    display:inline-flex;



    align-items:center;



    gap:7px;



    color:#748396;



    text-decoration:none;



    font-size:11px;



    font-weight:700;



    margin-bottom:14px;



}







.back-link:hover{



    color:var(--brand-blue);



}







.page-heading-row{



    display:flex;



    align-items:flex-end;



    justify-content:space-between;



    gap:20px;



}







.page-kicker{



    display:inline-flex;



    align-items:center;



    gap:7px;



    padding:7px 12px;



    border-radius:999px;



    background:var(--brand-yellow-soft);



    border:1px solid #ffe594;



    color:#8c6800;



    font-size:10px;



    text-transform:uppercase;



    letter-spacing:.7px;



    font-weight:800;



    margin-bottom:10px;



}







.page-heading h1{



    color:var(--brand-blue);



    font-size:34px;



    line-height:1.18;



    font-weight:800;



    letter-spacing:-.6px;



}







.page-heading p{



    color:var(--muted);



    font-size:13px;



    margin-top:6px;



}







.header-stat{



    padding:11px 14px;



    min-width:145px;



    text-align:center;



    background:#fff;



    border:1px solid var(--line);



    border-radius:12px;



    box-shadow:0 6px 18px rgba(0,31,63,.04);



}







.header-stat span{



    color:#8d9baa;



    font-size:8px;



    text-transform:uppercase;



    letter-spacing:.7px;



    font-weight:800;



}







.header-stat strong{



    display:block;



    color:var(--brand-blue);



    font-size:22px;



    line-height:1.1;



    margin-top:2px;



    font-weight:800;



}







.alert{



    display:flex;



    align-items:flex-start;



    gap:9px;



    padding:12px 13px;



    border-radius:11px;



    margin-bottom:18px;



    font-size:10px;



    line-height:1.6;



    font-weight:700;



}







.alert.success{



    background:#e7f8ef;



    border:1px solid #ccebd9;



    color:#176c47;



}







.empty-state{



    background:#fff;



    border:1px dashed #d7e1ea;



    border-radius:20px;



    padding:62px 24px;



    text-align:center;



    box-shadow:var(--shadow);



}







.empty-icon{



    width:68px;



    height:68px;



    margin:0 auto 14px;



    border-radius:50%;



    display:flex;



    align-items:center;



    justify-content:center;



    background:var(--brand-yellow-soft);



    color:#b18400;



    font-size:27px;



}







.empty-state h2{



    color:var(--brand-blue);



    font-size:20px;



    font-weight:800;



    margin-bottom:5px;



}







.empty-state p{



    color:var(--muted);



    font-size:11px;



    margin-bottom:18px;



}







.btn-primary{



    display:inline-flex;



    align-items:center;



    justify-content:center;



    gap:8px;



    min-height:42px;



    padding:0 17px;



    border-radius:10px;



    background:var(--brand-blue);



    color:var(--brand-yellow);



    text-decoration:none;



    font-size:11px;



    font-weight:800;



    border:0;



    cursor:pointer;



    position:relative;



    z-index:1;



    pointer-events:auto;



}







.btn-primary:hover{



    background:var(--brand-blue-2);



}







/* ===== PET CARDS ===== */



.pet-grid{



    display:grid;



    grid-template-columns:repeat(3,minmax(0,1fr));



    gap:18px;



}







.pet-card{



    position:relative;



    overflow:hidden;



    background:#fff;



    border:1px solid var(--line);



    border-radius:18px;



    box-shadow:var(--shadow);



    padding:21px;



    transition:border-color .2s,box-shadow .2s,transform .2s;



}







.pet-card:hover{



    border-color:#d1dde7;



    box-shadow:0 15px 30px rgba(0,31,63,.08);



    transform:translateY(-2px);



}







.pet-card-top{



    display:flex;



    align-items:flex-start;



    gap:13px;



    margin-bottom:18px;



}







.pet-icon{



    width:52px;



    height:52px;



    flex:0 0 52px;



    border-radius:15px;



    background:var(--brand-yellow-soft);



    color:var(--brand-blue);



    display:flex;



    align-items:center;



    justify-content:center;



    font-size:21px;



}







.pet-heading{



    min-width:0;



    flex:1;



}







.pet-type-label{



    display:block;



    color:#8c99a8;



    font-size:8px;



    text-transform:uppercase;



    letter-spacing:.7px;



    font-weight:800;



    margin-bottom:4px;



}







.pet-heading h2{



    font-size:18px;



    line-height:1.25;



    margin:0;



}







.pet-heading h2 a{



    color:var(--brand-blue);



    text-decoration:none;



    font-weight:800;



}







.pet-heading h2 a:hover{



    color:#8a6900;



}







.pet-info-grid{



    display:grid;



    grid-template-columns:1fr 1fr;



    gap:10px;



    padding-top:15px;



    border-top:1px solid #edf1f5;



}







.info-item{



    min-width:0;



    padding:10px;



    border-radius:10px;



    background:#f8fafc;



}







.info-item.full{



    grid-column:1 / -1;



}







.info-item label{



    display:block;



    color:#8d9aaa;



    font-size:8px;



    text-transform:uppercase;



    letter-spacing:.6px;



    font-weight:800;



    margin-bottom:3px;



}







.info-item span{



    display:block;



    color:var(--brand-blue);



    font-size:10px;



    font-weight:700;



    line-height:1.45;



}







.pet-actions{



    display:grid;



    grid-template-columns:1.25fr 1fr 1fr;



    gap:7px;



    margin-top:16px;



}







.pet-action{



    min-height:38px;



    border-radius:10px;



    display:flex;



    align-items:center;



    justify-content:center;



    gap:5px;



    text-decoration:none;



    font-size:9px;



    font-weight:800;



    border:1px solid transparent;



}







.pet-action.records{



    background:#eef6ff;



    color:var(--brand-blue);



    border-color:#d6e7f5;



}







.pet-action.records:hover{



    background:#dfeef9;



}







.pet-action.edit{



    background:#f8fafc;



    color:var(--brand-blue);



    border-color:#e1e8ef;



}







.pet-action.edit:hover{



    background:#eef2f6;



}







.pet-action.delete{



    background:var(--danger-soft);



    color:var(--danger);



    border-color:#f3d0d4;



}







.pet-action.delete:hover{



    background:#ffe5e8;



}







.pet-card::after{



    content:'';



    position:absolute;



    width:95px;



    height:95px;



    right:-46px;



    top:-46px;



    border-radius:50%;



    background:rgba(255,204,0,.08);



    pointer-events:none;



}







/* ===== ADD PET MODAL ===== */



.modal{



    display:none;



    position:fixed;



    inset:0;



    z-index:2000;



    background:rgba(0,31,63,.58);



    align-items:center;



    justify-content:center;



    padding:20px;



}







.modal.active{



    display:flex;



}







.modal-content{



    width:100%;



    max-width:500px;



    max-height:90vh;



    overflow:auto;



    background:#fff;



    border:1px solid rgba(255,255,255,.3);



    border-radius:19px;



    box-shadow:var(--shadow-lg);



}







.modal-head{



    position:sticky;



    top:0;



    z-index:2;



    display:flex;



    align-items:flex-start;



    justify-content:space-between;



    gap:15px;



    padding:21px 22px;



    background:#fff;



    border-bottom:1px solid var(--line);



}







.modal-head .kicker{



    color:#8b99a9;



    font-size:8px;



    text-transform:uppercase;



    letter-spacing:1px;



    font-weight:800;



    margin-bottom:4px;



}







.modal-head h2{



    color:var(--brand-blue);



    font-size:20px;



    line-height:1.2;



    font-weight:800;



}







.modal-head p{



    color:var(--muted);



    font-size:9px;



    margin-top:4px;



}







.modal-close{



    width:36px;



    height:36px;



    border:1px solid var(--line);



    border-radius:10px;



    background:#fff;



    color:#778797;



    cursor:pointer;



    display:flex;



    align-items:center;



    justify-content:center;



}







.modal-close:hover{



    background:#f8fafc;



    color:var(--brand-blue);



}







.modal-body{



    padding:22px;



}







.form-group{



    margin-bottom:16px;



}







.form-group label{



    display:block;



    color:var(--brand-blue);



    font-size:10px;



    font-weight:800;



    margin-bottom:6px;



}







.form-control{



    width:100%;



    min-height:42px;



    padding:9px 12px;



    border:1px solid #dce5ed;



    border-radius:10px;



    background:#fbfcfe;



    color:var(--text);



    font-size:11px;



    outline:none;



}







.form-control:focus{



    background:#fff;



    border-color:#9bb6cc;



    box-shadow:0 0 0 3px rgba(0,31,63,.05);



}







.form-grid{



    display:grid;



    grid-template-columns:1fr 1fr;



    gap:0 14px;



}







.modal-actions{



    display:flex;



    justify-content:flex-end;



    gap:9px;



    margin-top:19px;



}







.modal-cancel,



.modal-submit{



    min-height:42px;



    padding:0 16px;



    border-radius:10px;



    font-size:10px;



    font-weight:800;



    cursor:pointer;



}







.modal-cancel{



    background:#fff;



    color:var(--text);



    border:1px solid #cbd5e1;



}







.modal-cancel:hover{



    background:#f8fafc;



}







.modal-submit{



    background:var(--brand-blue);



    color:var(--brand-yellow);



    border:0;



}







.modal-submit:hover{



    background:var(--brand-blue-2);



}







/* FOOTER */



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



            transition: .2s;



        }







        .socials a:hover {



            background: var(--brand-yellow);



            color: var(--brand-blue);



        }







        .footer-bottom {



            max-width: 1180px;



            margin: 0 auto;



            padding-top: 23px;



            color: #91a1b1;



            text-align: center;



            font-size: 11px;



        }







        @media (max-width:980px) {



            .footer-main {



                grid-template-columns: 1fr 1fr;



            }



        }







/* ===== ENHANCED MOBILE RESPONSIVE ===== */



@media (max-width:680px){



    .promo-bar{



        padding:7px 10px;



        font-size:10px;



    }







    .nav-top{



        width:calc(100% - 24px);



        min-height:64px;



        padding:10px 0;



        gap:8px;



    }







    .logo{



        min-width:0;



        gap:8px;



        flex:1;



        max-width:calc(100% - 104px);



    }







    .nav-logo-img{



        width:42px;



        height:42px;



    }







    .logo-text b{



        font-size:16px;



    }







    .logo-text span{



        font-size:7px;



        letter-spacing:.8px;



        white-space:nowrap;



    }







    .user-controls{



        flex:0 0 auto;



        gap:7px;



    }







    .notification-bell{



        width:40px;



        height:40px;



        border-radius:10px;



    }







    .notification-badge{



        top:-3px;



        right:-3px;



        min-width:17px;



        height:17px;



    }







    .profile-trigger{



        min-height:40px;



        padding:4px;



        gap:0;



        border-radius:10px;



    }







    .profile-trigger span,



    .profile-trigger > i:last-child{



        display:none;



    }







    .profile-avatar{



        width:32px;



        height:32px;



    }







    .dropdown-menu{



        position:fixed;



        top:74px;



        left:12px;



        right:12px;



        width:auto !important;



        max-width:none;



        max-height:calc(100vh - 88px);



        overflow-y:auto;



        border-radius:14px;



    }







    main{



        width:calc(100% - 24px);



        padding:26px 0 48px;



    }







    .page-top{



        margin-bottom:20px;



    }







    .back-link{



        font-size:10px;



        margin-bottom:12px;



    }







    .page-heading-row{



        align-items:stretch;



    }







    .page-kicker{



        font-size:9px;



        padding:6px 10px;



    }







    .page-heading h1{



        font-size:25px;



        line-height:1.2;



        overflow-wrap:anywhere;



    }







    .page-heading p{



        font-size:11px;



        line-height:1.55;



    }







    .page-heading-row > div:last-child{



        gap:8px;



    }







    .page-heading-row > div:last-child .btn-primary{



        min-height:44px;



        font-size:10px;



        padding:0 12px;



    }







    .header-stat{



        min-width:118px;



        padding:9px 10px;



        border-radius:11px;



    }







    .header-stat span{



        font-size:7px;



    }







    .header-stat strong{



        font-size:19px;



    }







    .alert{



        padding:10px 11px;



        font-size:9px;



        margin-bottom:14px;



    }







    .pet-grid{



        grid-template-columns:1fr;



        gap:12px;



    }







    .pet-card{



        padding:17px;



        border-radius:15px;



    }







    .pet-card-top{



        gap:10px;



        margin-bottom:14px;



    }







    .pet-icon{



        width:44px;



        height:44px;



        flex:0 0 44px;



        border-radius:12px;



        font-size:18px;



    }







    .pet-type-label{



        font-size:7px;



    }







    .pet-heading h2{



        font-size:16px;



        overflow-wrap:anywhere;



    }







    .pet-info-grid{



        gap:8px;



        padding-top:12px;



    }







    .info-item{



        padding:9px;



    }







    .info-item label{



        font-size:7px;



    }







    .info-item span{



        font-size:9px;



        overflow-wrap:anywhere;



    }







    .pet-actions{



        gap:6px;



        margin-top:13px;



    }







    .pet-action{



        min-height:42px;



        font-size:9px;



        padding:0 4px;



        text-align:center;



    }







    .empty-state{



        padding:48px 18px;



    }







    .empty-icon{



        width:60px;



        height:60px;



        font-size:24px;



    }







    .empty-state h2{



        font-size:18px;



    }







    .empty-state p{



        font-size:10px;



        line-height:1.6;



    }







    .modal{



        padding:10px;



        align-items:flex-start;



    }







    .modal-content{



        width:100%;



        max-width:500px;



        max-height:calc(100vh - 20px);



        margin-top:10px;



        border-radius:15px;



    }







    .modal-head{



        padding:16px 15px;



    }







    .modal-head h2{



        font-size:18px;



    }







    .modal-head p{



        font-size:9px;



    }







    .modal-close{



        width:34px;



        height:34px;



        flex:0 0 34px;



    }







    .modal-body{



        padding:16px 15px 18px;



    }







    .form-grid{



        grid-template-columns:1fr;



        gap:0;



    }







    .form-group{



        margin-bottom:13px;



    }







    .form-group label{



        font-size:9px;



    }







    .form-control{



        min-height:44px;



        padding:9px 11px;



        font-size:11px;



    }







    .modal-actions{



        display:grid;



        grid-template-columns:1fr 1.4fr;



        gap:8px;



        margin-top:14px;



    }







    .modal-cancel,



    .modal-submit{



        min-height:44px;



        width:100%;



        padding:0 10px;



        font-size:9px;



    }







    footer{



        padding:40px 14px 24px;



    }







    .footer-main{



        grid-template-columns:1fr;



        gap:24px;



    }







    .footer-main h4{



        font-size:11px;



    }







    .footer-main p,



    .footer-main a{



        font-size:11px;



    }







    .footer-bottom{



        font-size:9px;



        line-height:1.6;



    }



}







@media (max-width:380px){



    .nav-top{



        width:calc(100% - 18px);



    }







    .logo{



        max-width:calc(100% - 96px);



    }







    .logo-text b{



        font-size:14px;



    }







    .logo-text span{



        font-size:6px;



    }







    .notification-bell{



        width:38px;



        height:38px;



    }







    .profile-avatar{



        width:30px;



        height:30px;



    }







    main{



        width:calc(100% - 18px);



        padding-top:22px;



    }







    .page-heading h1{



        font-size:22px;



    }







    .header-stat{



        min-width:104px;



    }







    .pet-actions{



        grid-template-columns:1fr;



    }







    .pet-action{



        min-height:40px;



    }







    .modal-actions{



        grid-template-columns:1fr;



    }



}







@media (max-width:680px){



    .nav-top{



        width:92%;



        flex-wrap:wrap;



    }







    .page-heading-row{



        display:block;



    }







    .header-stat{



        display:inline-block;



        margin-top:14px;



        text-align:left;



    }







    .page-heading-row > div:last-child{



        width:100%;



        display:flex !important;



        align-items:center;



        justify-content:space-between;



        gap:10px;



        margin-top:14px;



    }







    .page-heading-row > div:last-child .btn-primary{



        flex:1;



    }







    main{



        width:92%;



        padding-top:30px;



    }







    .pet-grid{



        grid-template-columns:1fr;



    }







    .form-grid{



        grid-template-columns:1fr;



    }







    .pet-actions{



        grid-template-columns:1fr 1fr 1fr;



    }







    .footer-main{



        grid-template-columns:1fr;



        gap:25px;



    }



}





        .delete-form { display:inline; margin:0; }

        .delete-form button { font:inherit; }



/* ===== FINAL MOBILE READABILITY + HEADER ALIGNMENT ===== */
@media (max-width: 680px) {
    /* Keep the brand and account controls on one horizontal row. */
    header .nav-top {
        width: calc(100% - 24px) !important;
        min-height: 58px !important;
        padding: 8px 0 !important;
        display: flex !important;
        flex-wrap: nowrap !important;
        align-items: center !important;
        justify-content: space-between !important;
        gap: 8px !important;
    }

    header .logo {
        flex: 1 1 auto !important;
        min-width: 0 !important;
        max-width: none !important;
        gap: 8px !important;
        overflow: hidden;
    }

    header .nav-logo-img {
        width: 40px !important;
        height: 40px !important;
        flex: 0 0 40px !important;
    }

    header .logo-text {
        min-width: 0;
    }

    header .logo-text b {
        font-size: 16px !important;
        line-height: 1.15;
        white-space: nowrap;
    }

    header .logo-text span {
        font-size: 8px !important;
        letter-spacing: .7px !important;
        white-space: nowrap;
    }

    header .user-controls {
        display: flex !important;
        flex: 0 0 auto !important;
        align-items: center !important;
        gap: 6px !important;
    }

    header .notification-bell,
    header .profile-trigger {
        width: 40px !important;
        min-width: 40px !important;
        height: 40px !important;
        min-height: 40px !important;
        padding: 3px !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        border-radius: 10px !important;
    }

    header .profile-trigger span,
    header .profile-trigger > i:last-child {
        display: none !important;
    }

    header .profile-avatar {
        width: 32px !important;
        height: 32px !important;
    }

    header .dropdown-menu {
        position: absolute !important;
        top: calc(100% + 8px) !important;
        right: 0 !important;
        left: auto !important;
        width: min(310px, calc(100vw - 20px)) !important;
        max-width: calc(100vw - 20px) !important;
        max-height: 70vh;
        overflow-y: auto;
    }

    header .dropdown-header {
        font-size: 12px !important;
    }

    header .dropdown-item {
        padding: 12px 14px !important;
        font-size: 12px !important;
        line-height: 1.5;
        overflow-wrap: anywhere;
    }

    header .dropdown-item small {
        font-size: 10px !important;
    }

    .promo-bar {
        padding: 7px 10px !important;
        font-size: 11px !important;
        line-height: 1.45;
    }

    main {
        width: calc(100% - 24px) !important;
        padding: 25px 0 48px !important;
    }

    .page-top {
        margin-bottom: 19px;
    }

    .back-link {
        font-size: 11px !important;
        margin-bottom: 11px;
    }

    .page-heading-row {
        display: block !important;
    }

    .page-kicker {
        font-size: 10px !important;
        padding: 7px 10px !important;
    }

    .page-heading h1 {
        font-size: 27px !important;
        line-height: 1.2;
        overflow-wrap: anywhere;
    }

    .page-heading p {
        font-size: 12px !important;
        line-height: 1.6;
    }

    .page-heading-row > div:last-child {
        width: 100%;
        display: flex !important;
        align-items: center;
        gap: 9px !important;
        margin-top: 14px;
    }

    .page-heading-row > div:last-child .btn-primary {
        flex: 1 1 auto;
        min-height: 46px;
        padding: 0 12px;
        font-size: 12px !important;
        white-space: nowrap;
    }

    .header-stat {
        min-width: 112px !important;
        padding: 9px 10px !important;
        border-radius: 11px;
    }

    .header-stat span {
        font-size: 9px !important;
        line-height: 1.35;
    }

    .header-stat strong {
        font-size: 20px !important;
    }

    .alert {
        padding: 11px 12px !important;
        font-size: 11px !important;
        line-height: 1.55;
    }

    .pet-grid {
        grid-template-columns: minmax(0, 1fr) !important;
        gap: 13px !important;
    }

    .pet-card {
        padding: 17px !important;
        border-radius: 16px;
    }

    .pet-card-top {
        gap: 11px;
        margin-bottom: 14px;
    }

    .pet-icon {
        width: 46px;
        height: 46px;
        flex-basis: 46px;
        font-size: 19px;
    }

    .pet-type-label {
        font-size: 9px !important;
        line-height: 1.4;
    }

    .pet-heading h2 {
        font-size: 18px !important;
        line-height: 1.3;
        overflow-wrap: anywhere;
    }

    .pet-info-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
        padding-top: 13px;
    }

    .info-item {
        min-width: 0;
        padding: 10px;
    }

    .info-item label {
        font-size: 9px !important;
        letter-spacing: .4px;
    }

    .info-item span {
        font-size: 12px !important;
        line-height: 1.5;
        overflow-wrap: anywhere;
    }

    .pet-actions {
        grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
        gap: 6px;
        margin-top: 14px;
    }

    .delete-form {
        min-width: 0;
        margin: 0;
    }

    .pet-action,
    .delete-form .pet-action {
        width: 100%;
        min-width: 0;
        min-height: 43px;
        padding: 5px 3px;
        gap: 5px;
        font-size: 10px !important;
        line-height: 1.25;
        text-align: center;
        white-space: normal;
    }

    .empty-state {
        padding: 43px 17px !important;
        border-radius: 16px;
    }

    .empty-state h2 {
        font-size: 19px !important;
    }

    .empty-state p {
        font-size: 12px !important;
        line-height: 1.6;
    }

    /* Add Pet modal: larger fields and readable health/care instructions. */
    .modal {
        padding: 10px !important;
        align-items: center !important;
        overflow-y: auto;
    }

    .modal-content {
        width: 100% !important;
        max-width: 520px;
        max-height: calc(100vh - 20px) !important;
        max-height: calc(100dvh - 20px) !important;
        margin: auto !important;
        border-radius: 16px;
    }

    .modal-head {
        padding: 17px 16px !important;
        gap: 10px;
    }

    .modal-head .kicker {
        font-size: 10px !important;
    }

    .modal-head h2 {
        font-size: 20px !important;
        line-height: 1.25;
    }

    .modal-head p {
        font-size: 12px !important;
        line-height: 1.5;
    }

    .modal-close {
        width: 40px;
        height: 40px;
        flex: 0 0 40px;
    }

    .modal-body {
        padding: 17px 16px 19px !important;
    }

    .modal-body .form-grid {
        grid-template-columns: minmax(0, 1fr) !important;
        gap: 0;
    }

    .modal-body .form-group {
        margin-bottom: 14px;
    }

    .modal-body .form-group label {
        font-size: 12px !important;
        line-height: 1.45;
    }

    .modal-body .form-control {
        width: 100%;
        min-height: 48px;
        padding: 11px 12px;
        font-size: 16px !important;
        line-height: 1.4;
    }

    .modal-body textarea.form-control {
        min-height: 115px !important;
        font-size: 15px !important;
        line-height: 1.55;
    }

    .modal-body .form-group small {
        font-size: 11px !important;
        line-height: 1.55 !important;
    }

    .modal-body > div[style*="background:#fff9e6"] {
        padding: 12px !important;
        margin: 8px 0 13px !important;
    }

    .modal-body > div[style*="background:#fff9e6"] > div {
        font-size: 12px !important;
        line-height: 1.45;
    }

    .modal-body > div[style*="background:#fff9e6"] p {
        font-size: 11px !important;
        line-height: 1.55 !important;
    }

    .modal-actions {
        display: grid !important;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 9px;
        margin-top: 17px;
    }

    .modal-cancel,
    .modal-submit {
        width: 100%;
        min-height: 46px;
        padding: 8px 10px;
        font-size: 11px !important;
        line-height: 1.3;
    }

    footer {
        padding: 42px 15px 24px !important;
    }

    .footer-main {
        grid-template-columns: 1fr !important;
        gap: 23px;
        padding-bottom: 27px;
    }

    .footer-main h4 {
        font-size: 12px !important;
    }

    .footer-main p,
    .footer-main a {
        font-size: 12px !important;
        line-height: 1.65;
        overflow-wrap: anywhere;
    }

    .footer-bottom {
        font-size: 10px !important;
        line-height: 1.6;
        padding-top: 18px;
    }
}

@media (max-width: 380px) {
    header .nav-top {
        width: calc(100% - 18px) !important;
    }

    header .logo-text b {
        font-size: 15px !important;
    }

    header .logo-text span {
        font-size: 7px !important;
    }

    main {
        width: calc(100% - 18px) !important;
    }

    .page-heading h1 {
        font-size: 25px !important;
    }

    .page-heading-row > div:last-child {
        gap: 7px !important;
    }

    .header-stat {
        min-width: 102px !important;
    }

    .pet-card {
        padding: 14px !important;
    }

    .pet-action,
    .delete-form .pet-action {
        font-size: 9.5px !important;
        gap: 3px;
    }

    .modal-actions {
        grid-template-columns: 1fr !important;
    }
}



/* ===== PET GROUPS ===== */
.pet-groups-section{margin:0 0 26px;background:#fff;border:1px solid var(--line);border-radius:18px;padding:20px;box-shadow:var(--shadow)}
.pet-groups-heading{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:14px}
.pet-groups-heading h2{color:var(--brand-blue);font-size:18px;font-weight:800}
.pet-groups-heading p{color:var(--muted);font-size:10px;margin-top:3px;max-width:560px}
.pet-groups-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:13px}
.pet-group-card{border:1px solid #e4ebf2;border-radius:14px;padding:15px;background:#fbfdff;min-width:0}
.pet-group-card h3{color:var(--brand-blue);font-size:14px;font-weight:800;margin-bottom:8px;overflow-wrap:anywhere}
.pet-group-mother{display:flex;align-items:center;gap:9px;padding:10px;background:#fff8df;border:1px solid #f4e4ad;border-radius:10px;margin-bottom:10px}
.pet-group-mother i{color:#9a7600;font-size:19px}
.pet-group-mother strong{display:block;color:var(--brand-blue);font-size:11px}
.pet-group-mother small{display:block;color:#7f8792;font-size:9px}
.pet-group-card details{border-top:1px solid #e8edf2;padding-top:9px}
.pet-group-card summary{cursor:pointer;color:var(--brand-blue);font-size:10px;font-weight:800;list-style:none}
.pet-group-card summary::-webkit-details-marker{display:none}
.pet-group-card summary:after{content:'+';float:right;font-size:15px;line-height:10px}
.pet-group-card details[open] summary:after{content:'−'}
.pet-group-puppy-list{display:grid;gap:7px;margin-top:10px}
.pet-group-puppy{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 9px;background:#fff;border:1px solid #e9eef3;border-radius:9px}
.pet-group-puppy strong{display:block;color:var(--text);font-size:10px}
.pet-group-puppy small{display:block;color:var(--muted);font-size:9px}
.pet-group-puppy a{color:var(--brand-blue);font-size:9px;font-weight:800;text-decoration:none;white-space:nowrap}
.pet-group-note{padding:12px 13px;background:#f8fafc;border:1px dashed #d8e1e9;border-radius:11px;color:var(--muted);font-size:10px;line-height:1.55}
.pet-group-modal-content{max-width:720px}
.pet-group-intro{padding:11px 13px;background:#f0f7ff;border:1px solid #d9eafa;border-radius:10px;color:#315574;font-size:10px;line-height:1.55;margin-bottom:15px}
.group-pet-picker-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:18px 0 9px}
.group-pet-picker-heading h3{color:var(--brand-blue);font-size:13px;font-weight:800}
.group-pet-picker-heading p{color:var(--muted);font-size:9px;margin-top:3px}
.group-pet-count{flex:0 0 auto;padding:6px 9px;background:var(--brand-yellow-soft);border:1px solid #ffe594;border-radius:999px;color:#8c6800;font-size:9px;font-weight:800}
.group-pet-picker{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px;max-height:340px;overflow-y:auto;padding:2px}
.group-pet-option{position:relative;display:flex;align-items:center;gap:9px;min-width:0;padding:11px;border:1px solid #dfe7ef;background:#fbfdff;border-radius:11px;cursor:pointer;transition:border-color .16s,background .16s,box-shadow .16s}
.group-pet-option:has(input:checked){border-color:var(--brand-blue);background:#f0f6fb;box-shadow:0 0 0 2px rgba(0,31,63,.06)}
.group-pet-option input{width:17px;height:17px;flex:0 0 17px;accent-color:var(--brand-blue)}
.group-pet-option-icon{width:36px;height:36px;flex:0 0 36px;display:flex;align-items:center;justify-content:center;border-radius:10px;background:var(--brand-yellow-soft);color:var(--brand-blue)}
.group-pet-option-copy{min-width:0;flex:1}
.group-pet-option-copy strong{display:block;color:var(--brand-blue);font-size:10px;overflow-wrap:anywhere}
.group-pet-option-copy small{display:block;color:var(--muted);font-size:8px;line-height:1.4;overflow-wrap:anywhere}
.group-pet-check{display:none;color:#168553;font-size:12px}
.group-pet-option:has(input:checked) .group-pet-check{display:block}
.group-pet-help{color:var(--muted);font-size:9px;line-height:1.5;margin-top:9px}
.pet-group-entry-help{color:var(--muted);font-size:9px;line-height:1.5;margin-top:6px}
.pet-group-form-actions{display:flex;justify-content:flex-end;gap:9px;margin-top:17px}
@media(max-width:680px){
 .pet-groups-section{padding:15px;border-radius:15px}
 .pet-groups-heading{align-items:flex-start;flex-direction:column}
 .pet-groups-heading .btn-primary{width:100%}
 .pet-group-modal-content{max-width:100%}
 .group-pet-picker{grid-template-columns:1fr;max-height:42vh}
 .pet-group-form-actions{display:grid;grid-template-columns:1fr 1.4fr}
}

</style>



</head>



<body>







    <div class="promo-bar">



        <i class="fa-solid fa-phone"></i>



        Need help? Call us at (046) 887 4714



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



                        <span



                            id="notif-badge"



                            class="notification-badge"



                            style="display: <?php echo ($unread_count > 0) ? 'inline-flex' : 'none'; ?>;"



                        >



                            <?php echo $unread_count; ?>



                        </span>



                    </div>







                    <div class="dropdown-menu" id="notifDropdown">



                        <div class="dropdown-header">Notifications</div>

                        <div id="notif-content">







                        <?php if(count($notifications) > 0): ?>



                            <?php foreach($notifications as $notif): ?>



                                <a



                                    href="notifications.php"



                                    class="dropdown-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>"



                                >



                                    <?php echo htmlspecialchars($notif['message']); ?>



                                    <br>



                                    <small style="color:#888;font-size:10px;">



                                        <?php echo htmlspecialchars(formatCustomerNotificationTimestamp($notif['created_at'] ?? null, 'M d, Y h:i A'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>



                                    </small>



                                </a>



                            <?php endforeach; ?>







                            <form action="mark_all_read.php" method="post" class="view-all-form" style="margin:0;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="submit" class="dropdown-item view-all-link" style="width:100%;border:0;background:#fff;cursor:pointer;font-family:inherit;">
                                    View All Notifications
                                </button>
                            </form>



                        <?php else: ?>



                            <div class="dropdown-item" style="text-align:center;color:#888;">



                                No new notifications.



                            </div>



                        <?php endif; ?>

                        </div>

                    </div>



                </div>







                <div class="profile-wrapper">



                    <div class="profile-trigger" onclick="toggleDropdown('profileDropdown')">



                        <?php if (!empty($profile_image)): ?>



                            <img



                                src="<?php echo htmlspecialchars($profile_image_src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"



                                alt="Profile"



                                class="profile-avatar"



                                onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-block';"



                            >



                            <i



                                class="fa-solid fa-circle-user"



                                style="font-size:20px;color:var(--brand-blue);display:none;"



                            ></i>



                        <?php else: ?>



                            <i



                                class="fa-solid fa-circle-user"



                                style="font-size:20px;color:var(--brand-blue);"



                            ></i>



                        <?php endif; ?>







                        <span>Hi, <?php echo htmlspecialchars($full_name); ?></span>



                        <i class="fa-solid fa-chevron-down" style="font-size:10px;color:#91a0ae;"></i>



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



                        <a



                            href="logout.php"



                            class="dropdown-item"



                            style="color:#dc3545;border-top:1px solid #eaeaea;"



                        >



                            <i class="fa-solid fa-right-from-bracket"></i> Logout



                        </a>



                    </div>



                </div>



            </div>



        </div>



    </header>







    <main>



        <div class="page-top">



            <a href="dashboard.php" class="back-link">



                <i class="fa-solid fa-arrow-left"></i>



                Back to Dashboard



            </a>







            <div class="page-heading-row">



                <div class="page-heading">



                    <div class="page-kicker">



                        <i class="fa-solid fa-paw"></i>



                        Your pets



                    </div>







                    <h1>Pet Profiles</h1>



                    <p>Manage your pets' official medical and booking information.</p>



                </div>







                <div style="display:flex; align-items:center; gap:10px;">



                    <button type="button" class="btn-primary" data-open-add-pet-modal>



                        <i class="fa-solid fa-plus"></i>



                        Add New Pet



                    </button>

                    <?php if ($pet_group_schema_ready && count($eligible_group_pets) >= 2): ?>
                        <button type="button" class="btn-primary" data-open-pet-group-modal>
                            <i class="fa-solid fa-people-roof"></i>
                            Create a Group
                        </button>
                    <?php endif; ?>







                    <div class="header-stat">



                        <span>Registered Pets</span>



                        <strong><?php echo (int)$current_pet_count; ?></strong>



                    </div>



                </div>



            </div>



        </div>







        <?php if ($pet_group_flash_success !== ''): ?>
            <div class="alert success"><i class="fa-solid fa-circle-check"></i><div><?php echo htmlspecialchars($pet_group_flash_success, ENT_QUOTES, 'UTF-8'); ?></div></div>
        <?php endif; ?>
        <?php if ($pet_group_flash_error !== ''): ?>
            <div class="alert" style="background:#fff0f1;border:1px solid #f3d0d4;color:#a52f3d;"><i class="fa-solid fa-circle-exclamation"></i><div><?php echo htmlspecialchars($pet_group_flash_error, ENT_QUOTES, 'UTF-8'); ?></div></div>
        <?php endif; ?>

        <?php if(isset($_GET['success'])): ?>



            <?php if($_GET['success'] == '1'): ?>



                <div class="alert success">



                    <i class="fa-solid fa-circle-check"></i>



                    <div>Pet added successfully!</div>



                </div>



            <?php elseif($_GET['success'] == 'deleted'): ?>



                <div class="alert success">



                    <i class="fa-solid fa-circle-check"></i>



                    <div>Pet profile deleted successfully!</div>



                </div>



            <?php endif; ?>



        <?php endif; ?>







        <section class="pet-groups-section" aria-labelledby="petGroupsTitle">
            <div class="pet-groups-heading">
                <div>
                    <h2 id="petGroupsTitle"><i class="fa-solid fa-people-roof"></i> Pet Groups</h2>
                    <p>Put your registered pets in one group for easier management. Each pet keeps its own profile and medical records.</p>
                </div>
                <?php if ($pet_group_schema_ready && count($eligible_group_pets) >= 2): ?>
                    <button type="button" class="btn-primary" data-open-pet-group-modal><i class="fa-solid fa-plus"></i> Create a Group</button>
                <?php endif; ?>
            </div>

            <?php if (!$pet_group_schema_ready): ?>
                <div class="pet-group-note">Pet Group database setup is not available yet. Please check the Pet Group member migration in Supabase.</div>
            <?php else: ?>
                <?php if (count($eligible_group_pets) < 2): ?>
                    <div class="pet-group-note" style="margin-bottom:13px;">
                        <?php if (empty($pet_groups)): ?>
                            You need at least <strong>2 registered pets</strong> that are not in another group before you can create a group. Add another pet profile first.
                        <?php else: ?>
                            To create another group, add at least 2 more registered pet profiles that are not already assigned to a group.
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (empty($pet_groups)): ?>
                    <div class="pet-group-note">No groups yet. Once you have at least 2 registered pets, select <strong>Create a Group</strong> and choose which pets belong together.</div>
                <?php else: ?>
                    <div class="pet-groups-grid">
                        <?php foreach ($pet_groups as $group): ?>
                            <?php
                                $group_id = (int)$group['group_id'];
                                $group_members = $pet_group_children[$group_id] ?? [];
                                $group_pet_total = 1 + count($group_members);
                                $primary_type = strtolower(trim((string)($group['primary_pet_type'] ?? '')));
                                $primary_icon = $primary_type === 'cat' ? 'fa-cat' : 'fa-dog';
                            ?>
                            <article class="pet-group-card">
                                <h3><?php echo htmlspecialchars($group['group_name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                                <div class="pet-group-mother">
                                    <i class="fa-solid <?php echo $primary_icon; ?>"></i>
                                    <div>
                                        <strong><?php echo htmlspecialchars($group['primary_pet_name'], ENT_QUOTES, 'UTF-8'); ?> <span style="font-weight:500;color:#8a6a00;">(Primary Pet)</span></strong>
                                        <small><?php echo htmlspecialchars(ucfirst((string)$group['primary_pet_type']), ENT_QUOTES, 'UTF-8'); ?><?php echo !empty($group['primary_pet_breed']) ? ' · ' . htmlspecialchars((string)$group['primary_pet_breed'], ENT_QUOTES, 'UTF-8') : ''; ?></small>
                                        <small><a href="pet_records.php?id=<?php echo (int)$group['primary_pet_id']; ?>" style="color:var(--brand-blue);font-weight:800;text-decoration:none;">View Pet Records</a></small>
                                    </div>
                                </div>
                                <details>
                                    <summary>View all <?php echo $group_pet_total; ?> pets in this group</summary>
                                    <div class="pet-group-puppy-list">
                                        <div class="pet-group-puppy">
                                            <div>
                                                <strong><?php echo htmlspecialchars($group['primary_pet_name'], ENT_QUOTES, 'UTF-8'); ?> <span style="font-weight:500;color:#8a6a00;">(Primary)</span></strong>
                                                <small><?php echo htmlspecialchars(ucfirst((string)$group['primary_pet_type']), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($group['primary_pet_breed'] ?? 'Breed not set', ENT_QUOTES, 'UTF-8'); ?></small>
                                            </div>
                                            <a href="pet_records.php?id=<?php echo (int)$group['primary_pet_id']; ?>">View Records</a>
                                        </div>
                                        <?php foreach ($group_members as $group_member): ?>
                                            <?php $member_type = strtolower(trim((string)($group_member['pet_type'] ?? ''))); ?>
                                            <div class="pet-group-puppy">
                                                <div>
                                                    <strong><?php echo htmlspecialchars($group_member['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                                    <small><?php echo htmlspecialchars(ucfirst((string)$group_member['pet_type']), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($group_member['breed'] ?? 'Breed not set', ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($group_member['gender'] ?? 'Gender not set', ENT_QUOTES, 'UTF-8'); ?></small>
                                                </div>
                                                <a href="pet_records.php?id=<?php echo (int)$group_member['id']; ?>">View Records</a>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                                <p class="puppy-entry-help" style="margin-top:9px;">Created <?php echo htmlspecialchars(date('M d, Y', strtotime((string)$group['group_created_at'])), ENT_QUOTES, 'UTF-8'); ?></p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <?php if (!empty($pets)): ?>







            <div class="pet-grid">



                <?php foreach($pets as $row): ?>







                    <?php



                        $pet_type = $row['pet_type'] ?? 'Pet';



                        $pet_icon = strtolower($pet_type) === 'cat' ? 'fa-cat' : 'fa-dog';



                    ?>







                    <article class="pet-card">



                        <div class="pet-card-top">



                            <div class="pet-icon">



                                <i class="fa-solid <?php echo $pet_icon; ?>"></i>



                            </div>







                            <div class="pet-heading">



                                <span class="pet-type-label">



                                    <?php echo htmlspecialchars($pet_type); ?>



                                    <?php if (!empty($row['breed'])): ?>



                                        · <?php echo htmlspecialchars($row['breed']); ?>



                                    <?php endif; ?>



                                </span>







                                <h2>



                                    <a



                                        href="pet_records.php?id=<?php echo $row['id']; ?>"



                                        title="View Medical & Booking Records"



                                    >



                                        <?php echo htmlspecialchars($row['name']); ?>



                                    </a>



                                </h2>



                            </div>



                        </div>







                        <div class="pet-info-grid">



                            <div class="info-item">



                                <label>Gender</label>



                                <span><?php echo htmlspecialchars($row['gender'] ?? 'Not set'); ?></span>



                            </div>







                            <div class="info-item">



                                <label>Age</label>



                                <span><?php echo htmlspecialchars($row['age']); ?> Years</span>



                            </div>







                            <div class="info-item">

                                <label>Weight</label>

                                <span><?php
                                    $display_weight = trim((string)($row['weight'] ?? ''));
                                    if ($display_weight === '') {
                                        $display_weight = 'Not set';
                                    } elseif (is_numeric($display_weight)) {
                                        $display_weight .= ' kg';
                                    }
                                    echo htmlspecialchars($display_weight, ENT_QUOTES, 'UTF-8');
                                ?></span>

                            </div>



                            <div class="info-item full">



                                <label>Registered</label>



                                <span>



                                    <?php echo isset($row['created_at'])



                                        ? date('M d, Y', strtotime($row['created_at']))



                                        : 'N/A'; ?>



                                </span>



                            </div>



                        </div>







                        <div class="pet-actions">



                            <a



                                href="pet_records.php?id=<?php echo $row['id']; ?>"



                                class="pet-action records"



                            >



                                <i class="fa-solid fa-book-medical"></i>



                                Records



                            </a>







                            <a



                                href="edit_pet.php?id=<?php echo $row['id']; ?>"



                                class="pet-action edit"



                            >



                                <i class="fa-solid fa-pen-to-square"></i>



                                Edit



                            </a>







                            <form method="POST" action="petprofile.php" class="delete-form" onsubmit="return confirm('Are you sure you want to delete this pet profile? This action cannot be undone.');">

                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($petprofile_csrf, ENT_QUOTES, 'UTF-8'); ?>">

                                <input type="hidden" name="pet_id" value="<?php echo (int)$row['id']; ?>">

                                <button type="submit" name="delete_pet" value="1" class="pet-action delete">

                                    <i class="fa-solid fa-trash"></i>

                                    Delete

                                </button>

                            </form>



                        </div>



                    </article>







                <?php endforeach; ?>



            </div>







        <?php else: ?>







            <div class="empty-state">



                <div class="empty-icon">



                    <i class="fa-solid fa-dog"></i>



                </div>







                <h2>No pets registered yet</h2>







                <p>



                    Add your first pet profile to keep their information ready for



                    appointments and medical records.



                </p>







                <button type="button" class="btn-primary" data-open-add-pet-modal>



                    <i class="fa-solid fa-plus"></i>



                    Add New Pet



                </button>



            </div>







        <?php endif; ?>



    </main>







    <?php if ($pet_group_schema_ready && count($eligible_group_pets) >= 2): ?>
    <div id="petGroupModal" class="modal" aria-hidden="true">
        <div class="modal-content pet-group-modal-content" role="dialog" aria-modal="true" aria-labelledby="petGroupTitle">
            <div class="modal-head">
                <div>
                    <div class="kicker">Manage your registered pets</div>
                    <h2 id="petGroupTitle">Create a Pet Group</h2>
                    <p>Select the pets you want to organize together.</p>
                </div>
                <button type="button" class="modal-close" data-close-pet-group-modal aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <div class="pet-group-intro"><strong>How this works:</strong> Select 2 to 20 existing pet profiles and give the group a name. This will not create duplicate pet profiles or combine medical records. Each pet keeps its own information.</div>
                <form method="POST" action="petprofile.php" id="createPetGroupForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($petprofile_csrf, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="form-group">
                        <label for="pet_group_name">Group Name</label>
                        <input type="text" id="pet_group_name" name="group_name" class="form-control" maxlength="120" placeholder="e.g. My 3 Dogs or Family Pets">
                    </div>

                    <div class="group-pet-picker-heading">
                        <div>
                            <h3>Choose Registered Pets</h3>
                            <p>Select at least 2 pets. You can group dogs, cats, or both together.</p>
                        </div>
                        <span id="groupPetSelectedCount" class="group-pet-count" aria-live="polite">0 selected</span>
                    </div>
                    <div class="group-pet-picker" id="groupPetPicker">
                        <?php foreach ($eligible_group_pets as $group_pet_option): ?>
                            <?php
                                $option_type = strtolower(trim((string)($group_pet_option['pet_type'] ?? '')));
                                $option_icon = $option_type === 'cat' ? 'fa-cat' : 'fa-dog';
                                $option_details = ucfirst($option_type);
                                if (!empty($group_pet_option['breed'])) $option_details .= ' · ' . (string)$group_pet_option['breed'];
                                if (isset($group_pet_option['weight']) && $group_pet_option['weight'] !== '') $option_details .= ' · ' . (string)$group_pet_option['weight'] . ' kg';
                            ?>
                            <label class="group-pet-option">
                                <input type="checkbox" name="group_pet_ids[]" value="<?php echo (int)$group_pet_option['id']; ?>">
                                <span class="group-pet-option-icon"><i class="fa-solid <?php echo $option_icon; ?>"></i></span>
                                <span class="group-pet-option-copy">
                                    <strong><?php echo htmlspecialchars($group_pet_option['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <small><?php echo htmlspecialchars($option_details, ENT_QUOTES, 'UTF-8'); ?></small>
                                </span>
                                <span class="group-pet-check"><i class="fa-solid fa-check"></i></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="group-pet-help">A pet can only belong to one group at a time. To move a pet to another group, it must first be removed from its current group.</p>

                    <div class="pet-group-form-actions">
                        <button type="button" class="modal-cancel" data-close-pet-group-modal>Cancel</button>
                        <button type="submit" name="create_pet_group" value="1" class="modal-submit"><i class="fa-solid fa-floppy-disk"></i> Save Pet Group</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>


    <div id="addPetModal" class="modal" aria-hidden="true">



        <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="addPetTitle">







            <div class="modal-head">



                <div>



                    <div class="kicker">Pet registration</div>



                    <h2 id="addPetTitle">Register New Pet</h2>



                    <p>Add the basic details for your pet profile.</p>



                </div>







                <button



                    type="button"



                    class="modal-close"



                    onclick="closeModal()"



                    aria-label="Close"



                >



                    <i class="fa-solid fa-xmark"></i>



                </button>



            </div>







            <div class="modal-body">



                <form method="POST" action="">

                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($petprofile_csrf, ENT_QUOTES, 'UTF-8'); ?>">



                    <div class="form-grid">







                        <div class="form-group">



                            <label for="pet_name">Pet Name</label>



                            <input



                                type="text"



                                id="pet_name"



                                name="name"



                                class="form-control"



                                required



                                placeholder="e.g. Max"



                            >



                        </div>







                        <div class="form-group">



                            <label for="pet_type">Pet Type</label>



                            <select id="pet_type" name="pet_type" class="form-control" required>



                                <option value="" disabled selected>Select Pet Type</option>



                                <option value="Dog">Dog</option>



                                <option value="Cat">Cat</option>
</select>



                        </div>







                        <div class="form-group">



                            <label for="breed">Breed</label>



                            <input



                                type="text"



                                id="breed"



                                name="breed"



                                class="form-control"



                                required



                                placeholder="e.g. Golden Retriever"



                            >



                        </div>







                        <div class="form-group">



                            <label for="age">Age (Years)</label>



                            <input



                                type="number"



                                step="0.1"



                                min="0"



                                id="age"



                                name="age"



                                inputmode="decimal"



                                class="form-control"



                                required



                                placeholder="e.g. 2"



                            >



                        </div>







                        <div class="form-group">

                            <label for="weight">Weight (kg)</label>

                            <input
                                type="number"
                                step="0.1"
                                min="0.1"
                                max="1000"
                                id="weight"
                                name="weight"
                                inputmode="decimal"
                                class="form-control"
                                required
                                placeholder="e.g. 5.2"
                            >

                        </div>



                        <div class="form-group" style="grid-column:1/-1;">



                            <label for="gender">Gender</label>



                            <select id="gender" name="gender" class="form-control" required>



                                <option value="" disabled selected>Select Gender</option>



                                <option value="Male">Male</option>



                                <option value="Female">Female</option>



                                <option value="Unknown">Unknown</option>



                            </select>



                        </div>







                    </div>







                    <div style="grid-column:1/-1;margin:8px 0 12px;padding:13px 14px;background:#fff9e6;border:1px solid #f4e3a5;border-radius:11px;">
                            <div style="color:#6b5200;font-size:11px;font-weight:800;display:flex;align-items:center;gap:7px;">
                                <i class="fa-solid fa-shield-dog"></i>
                                Additional Pet Health & Care Information
                            </div>
                            <p style="margin-top:5px;color:#776b48;font-size:9px;line-height:1.55;">
                                Help our staff understand your pet's health, behavior, and special care needs before handling or providing a service. These fields are optional.
                            </p>
                        </div>

                        <div class="form-group" style="grid-column:1/-1;">
                            <label for="medical_history">Health Information (Allergies, Conditions & Medications)</label>
                            <textarea
                                id="medical_history"
                                name="medical_history"
                                class="form-control"
                                rows="4"
                                maxlength="4000"
                                style="min-height:105px;resize:vertical;"
                                placeholder="Examples: food or medicine allergies, existing health conditions, current medications, previous surgeries, or other health concerns. Leave blank if none."
                            ></textarea>
                            <small style="display:block;margin-top:5px;color:var(--text-muted);font-size:9px;line-height:1.5;">
                                Please include anything that staff or the veterinarian should know about your pet's health.
                            </small>
                        </div>

                        <div class="form-group" style="grid-column:1/-1;">
                            <label for="special_needs">Behavior & Special Care Instructions</label>
                            <textarea
                                id="special_needs"
                                name="special_needs"
                                class="form-control"
                                rows="4"
                                maxlength="4000"
                                style="min-height:105px;resize:vertical;"
                                placeholder="Examples: friendly, shy, anxious, afraid of loud noises, uncomfortable around other pets, sensitive areas, bite or scratch history, feeding restrictions, or preferred handling instructions."
                            ></textarea>
                            <small style="display:block;margin-top:5px;color:var(--text-muted);font-size:9px;line-height:1.5;">
                                Mention any behavior, safety concern, food restriction, or special handling reminder that can help keep your pet and our staff safe.
                            </small>
                        </div>

                        <div class="modal-actions">



                        <button type="button" class="modal-cancel" onclick="closeModal()">



                            Cancel



                        </button>







                        <button type="submit" name="add_pet" class="modal-submit">



                            <i class="fa-solid fa-floppy-disk"></i>



                            Save Pet Profile



                        </button>



                    </div>



                </form>



            </div>



        </div>



    </div>







    <footer>



        <div class="footer-main">



            <div>



                <h4 style="display:flex; align-items:center; gap:10px;"><i class="fa-solid fa-paw"></i> Boogie's Pet Care</h4>



                <p>Your trusted partner for all your pet care needs in Dasmariñas, Cavite.</p>



                <div class="socials">



                    <a href="https://www.facebook.com/boogiespetsupplies"><i class="fa-brands fa-facebook-f"></i></a>



                    <a href="https://mail.google.com/mail/?view=cm&to=boogiespetcareservices\@gmail.com"



                       class="gmail-compose-link"



                       aria-label="Email Boogie's Pet Care"



                       onclick="openGmailCompose(event, this.href);">



                        <i class="fa-solid fa-envelope"></i>



                    </a>



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



                <p><i class="fa-solid fa-envelope"></i> boogiespetcareservices\@gmail.com</p>



                <p><i class="fa-solid fa-location-dot"></i> 110 Don Placido Campos Ave San Agustin 3, Dasmariñas, Philippines</p>



            </div>



        </div>



        <div class="footer-bottom">



            <p>© 2026 Boogie's Pet Care & Services - Dasmariñas Branch. All rights reserved.</p>

           <?php include __DIR__ . '/customer_footer.php'; ?>

        </div> 



    </footer>







    <script>



        const modal = document.getElementById("addPetModal");







        function openModal() {



            if (!modal) return;



            modal.classList.add("active");



            modal.setAttribute("aria-hidden", "false");



            document.body.style.overflow = "hidden";



            const firstField = document.getElementById("pet_name");



            if (firstField) {



                setTimeout(() => firstField.focus(), 50);



            }



        }







        function closeModal() {



            if (!modal) return;



            modal.classList.remove("active");



            modal.setAttribute("aria-hidden", "true");



            document.body.style.overflow = "";



        }







        // ===== GMAIL COMPOSE =====



        function openGmailCompose(event, url) {



            event.preventDefault();







            // On phones, let Gmail/browser handle the normal mobile compose page.



            if (window.innerWidth <= 680) {



                window.location.href = url;



                return;



            }







            const width = 760;



            const height = 650;



            const left = Math.max(0, Math.round((window.screen.availWidth - width) / 2));



            const top = Math.max(0, Math.round((window.screen.availHeight - height) / 2));







            // Open a blank popup first so the browser can apply the requested size,



            // then navigate that same popup to Gmail.



            const popup = window.open(



                'about:blank',



                'boogiesGmailCompose',



                `popup=yes,width=${width},height=${height},left=${left},top=${top},resizable=yes,scrollbars=yes`



            );







            if (popup) {



                popup.location.href = url;



                popup.focus();



            } else {



                // Browser blocked the popup; fall back to opening Gmail normally.



                window.location.href = url;



            }



        }







        // ===== DROPDOWN LOGIC =====



        function toggleDropdown(id) {



            document.querySelectorAll('.dropdown-menu').forEach(menu => {



                if (menu.id !== id) {



                    menu.classList.remove('active');



                }



            });







            const target = document.getElementById(id);



            if (target) {



                target.classList.toggle('active');



            }



        }







        // Close modal/dropdowns when clicking outside.



        window.addEventListener('click', function(e) {



            if (modal && e.target === modal) {



                closeModal();



            }







            const notif = document.querySelector('.notification-wrapper');



            const profile = document.querySelector('.profile-wrapper');







            if (



                notif && profile &&



                !notif.contains(e.target) &&



                !profile.contains(e.target)



            ) {



                document.querySelectorAll('.dropdown-menu').forEach(menu => {



                    menu.classList.remove('active');



                });



            }



        });







        document.addEventListener('keydown', function(e) {



            if (e.key === 'Escape') {



                closeModal();







                document.querySelectorAll('.dropdown-menu').forEach(menu => {



                    menu.classList.remove('active');



                });



            }



        });







        // ===== AUTOMATIC NOTIFICATION DROPDOWN + UNREAD BADGE REFRESH =====
        const notifSound = new Audio('notification.mp3');
        let previousUnreadCount = <?php echo (int)$unread_count; ?>;
        let previousLatestNotificationId = <?php echo json_encode(isset($notifications[0]['id']) ? (string)$notifications[0]['id'] : '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        let notificationRefreshInProgress = false;
        const notificationCsrfToken = <?php echo json_encode((string)($_SESSION['csrf_token'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        function renderNotificationDropdown(items) {
            const content = document.getElementById('notif-content');
            if (!content) return;
            content.replaceChildren();

            if (!Array.isArray(items) || items.length === 0) {
                const emptyMessage = document.createElement('div');
                emptyMessage.className = 'dropdown-item';
                emptyMessage.style.textAlign = 'center';
                emptyMessage.style.color = '#888';
                emptyMessage.textContent = 'No new notifications.';
                content.appendChild(emptyMessage);
                return;
            }

            items.forEach(notification => {
                const link = document.createElement('a');
                link.href = 'notifications.php';
                link.className = 'dropdown-item' + (notification.is_read ? '' : ' unread');
                link.appendChild(document.createTextNode(String(notification.message || '')));
                link.appendChild(document.createElement('br'));

                const timestamp = document.createElement('small');
                timestamp.style.color = '#888';
                timestamp.style.fontSize = '10px';
                timestamp.textContent = String(notification.time || '');
                link.appendChild(timestamp);
                content.appendChild(link);
            });

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'mark_all_read.php';
            form.className = 'view-all-form';
            form.style.margin = '0';

            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = notificationCsrfToken;
            form.appendChild(csrfInput);

            const viewAllButton = document.createElement('button');
            viewAllButton.type = 'submit';
            viewAllButton.className = 'dropdown-item view-all-link';
            viewAllButton.style.width = '100%';
            viewAllButton.style.border = '0';
            viewAllButton.style.background = '#fff';
            viewAllButton.style.cursor = 'pointer';
            viewAllButton.style.fontFamily = 'inherit';
            viewAllButton.textContent = 'View All Notifications';
            form.appendChild(viewAllButton);
            content.appendChild(form);
        }

        async function updateNotifications() {
            if (notificationRefreshInProgress) return;
            notificationRefreshInProgress = true;

            try {
                const response = await fetch('petprofile.php?ajax_notifications=1', {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: { 'Accept': 'application/json' }
                });
                if (!response.ok) throw new Error('Notification request failed (' + response.status + ')');

                const data = await response.json();
                if (data.success !== true || !Number.isFinite(Number(data.unread_count)) || !Array.isArray(data.notifications)) {
                    throw new Error('Invalid notification response');
                }

                const unreadCount = Number(data.unread_count);
                const notifications = data.notifications;
                const latestId = notifications.length ? String(notifications[0].id || '') : '';
                const latestIsUnread = notifications.length > 0 && notifications[0].is_read !== true;
                const isNewUnreadNotification = latestId !== '' && latestId !== previousLatestNotificationId && latestIsUnread;

                if (unreadCount > previousUnreadCount || isNewUnreadNotification) {
                    notifSound.play().catch(() => {});
                }
                previousUnreadCount = unreadCount;
                if (latestId !== '') previousLatestNotificationId = latestId;

                const badge = document.getElementById('notif-badge');
                if (badge) {
                    badge.textContent = String(unreadCount);
                    badge.style.display = unreadCount > 0 ? 'inline-flex' : 'none';
                }
                renderNotificationDropdown(notifications);
            } catch (error) {
                console.warn('Could not refresh pet-profile notifications:', error);
            } finally {
                notificationRefreshInProgress = false;
            }
        }

        // The page already renders the initial notification state in PHP.
        // Poll every 15 seconds instead of every 3 seconds to reduce DB requests.
        setInterval(() => {
            if (!document.hidden) updateNotifications();
        }, 15000);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) updateNotifications();
        });



    </script>

    <script>
        // Reliable Add New Pet click handler for both buttons (header and empty state).
        document.querySelectorAll('[data-open-add-pet-modal]').forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                var addPetModal = document.getElementById('addPetModal');
                if (!addPetModal) {
                    console.error('Add Pet modal was not found in the page.');
                    return;
                }
                addPetModal.classList.add('active');
                addPetModal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
                var firstField = document.getElementById('pet_name');
                if (firstField) {
                    window.setTimeout(function () { firstField.focus({ preventScroll: true }); }, 50);
                }
            });
        });
    </script>






    <script>
    (function () {
        const groupModal = document.getElementById('petGroupModal');
        const openButtons = document.querySelectorAll('[data-open-pet-group-modal]');
        const closeButtons = document.querySelectorAll('[data-close-pet-group-modal]');
        const groupForm = document.getElementById('createPetGroupForm');
        const picker = document.getElementById('groupPetPicker');
        const selectedCount = document.getElementById('groupPetSelectedCount');
        const maxGroupPets = 20;

        function selectedPetCheckboxes() {
            return picker ? Array.from(picker.querySelectorAll('input[name="group_pet_ids[]"]:checked')) : [];
        }

        function updateGroupSelection() {
            if (!picker) return;
            const checkboxes = Array.from(picker.querySelectorAll('input[name="group_pet_ids[]"]'));
            const selected = selectedPetCheckboxes();
            if (selectedCount) selectedCount.textContent = selected.length + ' selected';
            checkboxes.forEach(input => {
                input.disabled = !input.checked && selected.length >= maxGroupPets;
                const label = input.closest('.group-pet-option');
                if (label) label.classList.toggle('is-disabled', input.disabled);
            });
        }

        function openGroupModal() {
            if (!groupModal) return;
            groupModal.classList.add('active');
            groupModal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            const firstInput = groupForm ? groupForm.querySelector('input[name="group_name"]') : null;
            if (firstInput) window.setTimeout(() => firstInput.focus(), 60);
        }

        function closeGroupModal() {
            if (!groupModal) return;
            groupModal.classList.remove('active');
            groupModal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }

        openButtons.forEach(button => button.addEventListener('click', openGroupModal));
        closeButtons.forEach(button => button.addEventListener('click', closeGroupModal));
        if (groupModal) {
            groupModal.addEventListener('click', event => {
                if (event.target === groupModal) closeGroupModal();
            });
        }
        if (picker) picker.addEventListener('change', updateGroupSelection);
        if (groupForm) {
            groupForm.addEventListener('submit', event => {
                const count = selectedPetCheckboxes().length;
                if (count < 2 || count > maxGroupPets) {
                    event.preventDefault();
                    alert('Please select between 2 and 20 registered pets for the group.');
                }
            });
        }

        updateGroupSelection();
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && groupModal && groupModal.classList.contains('active')) {
                closeGroupModal();
            }
        });
    })();
    </script>

</body>
</html>