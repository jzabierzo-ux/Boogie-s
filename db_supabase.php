<?php

$host = "aws-0-ap-northeast-1.pooler.supabase.com";
$port = "5432";
$dbname = "postgres";
$user = "postgres.tdjdgdwvslkopymnjwyz";
$password = "warapak0143";

try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    die("Database connection failed.");
}