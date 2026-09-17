<?php
$host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "pets"; 

// The default XAMPP connection
$conn = mysqli_connect($host, $db_user, $db_pass, $db_name);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// =========================================================
// --- EMAIL CONFIGURATION (PARA SA OTP AT NOTIFICATIONS) ---
// =========================================================

// GINAMIT MUNA NATIN ANG PERSONAL EMAIL MO FOR TESTING
if (!defined('SMTP_EMAIL')) {
    define('SMTP_EMAIL', 'prototyp6712@gmail.com'); 
}

// ITO YUNG 16-LETTER APP PASSWORD NA NA-GENERATE MO KANINA
if (!defined('SMTP_PASS')) {
    define('SMTP_PASS', 'jwkvmplgbfuxlwdr'); 
}

?>