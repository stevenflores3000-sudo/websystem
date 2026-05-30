<?php
session_start();
include 'db_connect.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $admin_id = strtolower(trim($_POST['admin_id']));
    $password = $_POST['password'];

    // Verify Admin Credentials against the database
    $stmt = $conn->prepare("SELECT * FROM admin WHERE admin_id = ?");
    $stmt->bind_param('s', $admin_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $admin = $result->fetch_assoc();

        if (password_verify($password, $admin['password'])) {
            session_regenerate_id(true);
            // Set session variables
            $_SESSION['user_id']   = $admin['id'];
            $_SESSION['admin_id']  = $admin['admin_id'];
            $_SESSION['user_name'] = $admin['name'];
            $_SESSION['role']      = 'admin';

            // Log the successful login action
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $stmt_log = $conn->prepare("INSERT INTO audit_log (admin_id, action, details, ip_address) VALUES (?, 'ADMIN_LOGIN', 'Admin successfully logged in', ?)");
            $stmt_log->bind_param('ss', $admin['admin_id'], $ip);
            $stmt_log->execute();

            $stmt->close();
            header("Location: index.html?admin=success&role=admin&name=" . urlencode($admin['name']));
            exit();
        } else {
            $stmt->close();
            header("Location: index.html?error=wrong_password&role=admin");
            exit();
        }
    } else {
        $stmt->close();
        header("Location: index.html?error=not_found&role=admin");
        exit();
    }
} else {
    header("Location: index.html");
    exit();
}
?>