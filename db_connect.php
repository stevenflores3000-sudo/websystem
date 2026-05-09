<?php
$host = "localhost";
$user = "root";
$pass = "";
$db   = "voting_system";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
// If you see nothing on the screen, the connection is successful!
?>