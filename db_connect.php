<?php
$host = "localhost";
$user = "root";
$pass = "";
$db   = "voting_system";

mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);

try {
    $conn = @new mysqli($host, $user, $pass, $db);
    if ($conn->connect_error) {
        throw new Exception($conn->connect_error);
    }
} catch (Exception $e) {
    // If the script expects JSON (e.g. API endpoints), output JSON
    $is_api = false;
    if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
        $is_api = true;
    } elseif (strpos($_SERVER['REQUEST_URI'] ?? '', 'api') !== false || strpos($_SERVER['REQUEST_URI'] ?? '', 'get_stats') !== false) {
        $is_api = true;
    }

    if ($is_api) {
        header('Content-Type: application/json');
        http_response_code(500);
        die(json_encode(['success' => false, 'error' => 'Database connection failed.']));
    } else {
        die("Connection failed: " . $e->getMessage());
    }
}
?>