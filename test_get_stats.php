<?php
$_GET['section'] = 'tally';
session_start();
$_SESSION['user_id'] = '1';
$_SESSION['role'] = 'admin';
include 'get_stats.php';
?>