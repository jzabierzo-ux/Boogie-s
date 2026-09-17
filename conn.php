<?php
$host = "localhost";
$user = "root";
$pass = ""; // XAMPP default is an empty string
$dbname = "pets";

$conn = mysqli_connect($host, $user, $pass, $dbname);

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}
?>