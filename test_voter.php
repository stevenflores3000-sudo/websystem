<?php
$_GET['section'] = 'tally';
$_SERVER['REQUEST_METHOD'] = 'GET';
session_start();
$_SESSION['user_id'] = 'user_1';
$_SESSION['role'] = 'voter';
ob_start();
include 'get_stats.php';
$output = ob_get_clean();
echo "--- RAW OUTPUT START ---\n";
echo $output;
echo "\n--- RAW OUTPUT END ---\n";
?>