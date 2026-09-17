<?php
echo "Current Directory: " . __DIR__ . "<br>";

if (is_dir(__DIR__ . '/PHPMailer')) {
    echo "✅ PHPMailer folder exists.<br>";
    if (is_dir(__DIR__ . '/PHPMailer/src')) {
        echo "✅ src folder exists.<br>";
        if (file_exists(__DIR__ . '/PHPMailer/src/Exception.php')) {
            echo "✅ Exception.php found!";
        } else {
            echo "❌ Exception.php is MISSING from the src folder.";
        }
    } else {
        echo "❌ src folder is MISSING inside PHPMailer.";
    }
} else {
    echo "❌ PHPMailer folder is MISSING from " . __DIR__;
}
?>