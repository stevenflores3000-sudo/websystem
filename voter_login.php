<?php
session_start();
include 'db_connect.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $student_id = trim($_POST['student_id']);
    $password   = $_POST['password'];

    // Search by student_id OR email so both work as login
    $stmt = $conn->prepare("SELECT * FROM user WHERE student_id = ? OR email = ?");
    $stmt->bind_param('ss', $student_id, $student_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();

        if (password_verify($password, $user['password'])) {
            // Store session data
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['student_id'] = $user['student_id'];
            $_SESSION['user_name']  = $user['name'];
            $_SESSION['email']      = $user['email'];
            $_SESSION['department'] = $user['department'];
            $_SESSION['role']       = 'voter';

            $stmt->close();

            // Redirect back to index with success flag — JS reads this
            header("Location: index.html?login=success&name=" . urlencode($user['name']) . "&sid=" . urlencode($user['student_id']));
            exit();
        } else {
            $stmt->close();
            header("Location: index.html?error=wrong_password");
            exit();
        }
    } else {
        $stmt->close();
        header("Location: index.html?error=not_found");
        exit();
    }
} else {
    // Direct access blocked
    header("Location: index.html");
    exit();
}
?>