<?php

$host = "aws-0-ap-northeast-1.pooler.supabase.com";
$port = "5432";
$dbname = "postgres";
$user = "postgres.tdjdgdwvslkopymnjwyz";
$password = "warapak0143";

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    echo "SUCCESS! Connected to Supabase.";
} catch (PDOException $e) {
    echo "CONNECTION FAILED: " . $e->getMessage();
}
?>