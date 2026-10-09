<?php
// Temporary PHPMailer installation checker.
// Place this file in the same directory as the PHPMailer folder.

declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');

$baseDir = __DIR__;
$checks = [
    'PHPMailer folder' => $baseDir . '/PHPMailer',
    'src folder' => $baseDir . '/PHPMailer/src',
    'Exception.php' => $baseDir . '/PHPMailer/src/Exception.php',
    'PHPMailer.php' => $baseDir . '/PHPMailer/src/PHPMailer.php',
    'SMTP.php' => $baseDir . '/PHPMailer/src/SMTP.php',
];

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$allPresent = true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHPMailer Installation Check</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 760px; margin: 32px auto; padding: 0 16px; color: #17324d; }
        h1 { color: #001f3f; font-size: 24px; }
        .path { overflow-wrap: anywhere; background: #f3f6f9; padding: 12px; border-radius: 8px; }
        .check { padding: 10px 12px; margin: 8px 0; border-radius: 8px; background: #f7f9fc; }
        .ok { color: #146c43; }
        .fail { color: #b42332; }
        .note { margin-top: 20px; padding: 12px; border-left: 4px solid #ffcc00; background: #fff9df; }
    </style>
</head>
<body>
    <h1>PHPMailer Installation Check</h1>
    <p><strong>Current directory:</strong></p>
    <div class="path"><?= h($baseDir) ?></div>

    <h2>Required folders and files</h2>
    <?php foreach ($checks as $label => $path): ?>
        <?php
        $isDirectory = in_array($label, ['PHPMailer folder', 'src folder'], true);
        $exists = $isDirectory ? is_dir($path) : is_file($path);
        $readable = $exists && is_readable($path);
        $passed = $exists && $readable;
        if (!$passed) {
            $allPresent = false;
        }
        ?>
        <div class="check">
            <?php if ($passed): ?>
                <strong class="ok">&#10004; <?= h($label) ?> exists and is readable.</strong>
            <?php else: ?>
                <strong class="fail">&#10008; <?= h($label) ?> is missing or not readable.</strong>
                <div class="path"><?= h($path) ?></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <?php if ($allPresent): ?>
        <p class="ok"><strong>Result: All required PHPMailer files are present and readable.</strong></p>
    <?php else: ?>
        <p class="fail"><strong>Result: PHPMailer is incomplete or not readable. Check the paths above.</strong></p>
    <?php endif; ?>

    <div class="note">
        <strong>Security note:</strong> This page displays the server directory path. Use it only for temporary troubleshooting and delete <code>check_phpmailer.php</code> after checking.
    </div>
</body>
</html>
