<?php

session_start();
require_once '../db_supabase.php';

/*
|--------------------------------------------------------------------------
| SECURITY: ADMIN ONLY
|--------------------------------------------------------------------------
*/
$current_role = strtolower(
    trim(
        (string)($_SESSION['role'] ?? '')
    )
);

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    $current_role !== 'admin'
) {
    header('Location: ../admin_login.php');
    exit();
}

/*
|--------------------------------------------------------------------------
| POST ONLY
|--------------------------------------------------------------------------
| Deleting a promotion changes database state, so do not allow GET deletion.
*/
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

/*
|--------------------------------------------------------------------------
| CSRF PROTECTION
|--------------------------------------------------------------------------
*/
$csrf_token = $_POST['csrf_token'] ?? '';

if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals(
        (string)$_SESSION['csrf_token'],
        (string)$csrf_token
    )
) {
    http_response_code(403);
    exit('Invalid security token.');
}

/*
|--------------------------------------------------------------------------
| GET PROMO ID
|--------------------------------------------------------------------------
*/
$promo_id = filter_input(
    INPUT_POST,
    'id',
    FILTER_VALIDATE_INT
);

if (!$promo_id || $promo_id <= 0) {
    header('Location: managepromo.php');
    exit();
}

try {

    /*
    |--------------------------------------------------------------------------
    | START TRANSACTION
    |--------------------------------------------------------------------------
    */
    $pdo->beginTransaction();

    /*
    |--------------------------------------------------------------------------
    | 1. GET PROMO RECORD FIRST
    |--------------------------------------------------------------------------
    | We need the image path before deleting the database row.
    */
    $find_stmt = $pdo->prepare("
        SELECT id, image_url
        FROM promos
        WHERE id = :promo_id
        LIMIT 1
        FOR UPDATE
    ");

    $find_stmt->execute([
        ':promo_id' => $promo_id
    ]);

    $promo = $find_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$promo) {
        $pdo->rollBack();

        header('Location: managepromo.php');
        exit();
    }

    /*
    |--------------------------------------------------------------------------
    | 2. DELETE PROMO
    |--------------------------------------------------------------------------
    */
    $delete_stmt = $pdo->prepare("
        DELETE FROM promos
        WHERE id = :promo_id
    ");

    $delete_stmt->execute([
        ':promo_id' => $promo_id
    ]);

    if ($delete_stmt->rowCount() !== 1) {
        throw new RuntimeException(
            'Promotion could not be deleted.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 3. COMMIT DATABASE CHANGE
    |--------------------------------------------------------------------------
    */
    $pdo->commit();

    /*
    |--------------------------------------------------------------------------
    | 4. DELETE ASSOCIATED IMAGE FILE
    |--------------------------------------------------------------------------
    | Do this AFTER the DB delete succeeds.
    */
    if (!empty($promo['image_url'])) {

        $image_url = (string)$promo['image_url'];

        /*
        | Only allow deletion of files inside uploads/promos.
        | This prevents accidental deletion of unrelated files.
        */
        $upload_dir = realpath(
            dirname(__DIR__) .
            DIRECTORY_SEPARATOR .
            'uploads' .
            DIRECTORY_SEPARATOR .
            'promos'
        );

        if ($upload_dir !== false) {

            $filename = basename($image_url);

            $image_path = $upload_dir .
                DIRECTORY_SEPARATOR .
                $filename;

            $real_image_path = realpath($image_path);

            if (
                $real_image_path !== false &&
                is_file($real_image_path) &&
                dirname($real_image_path) === $upload_dir
            ) {
                @unlink($real_image_path);
            }
        }
    }

} catch (Throwable $e) {

    /*
    |--------------------------------------------------------------------------
    | ROLLBACK
    |--------------------------------------------------------------------------
    */
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Delete promo error: ' .
        $e->getMessage()
    );
}

/*
|--------------------------------------------------------------------------
| RETURN TO PROMO MANAGEMENT
|--------------------------------------------------------------------------
*/
header('Location: managepromo.php');
exit();

?>