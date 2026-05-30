<?php
session_start();
include 'db_connect.php';
header('Content-Type: application/json');

// Ensure only logged-in voters can access this
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'voter') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Fetch current recovery email
    $stmt = $conn->prepare("SELECT recovery_email FROM user WHERE id = ?");
    $stmt->bind_param('s', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        echo json_encode(['success' => true, 'recovery_email' => $row['recovery_email']]);
    } else {
        echo json_encode(['success' => false, 'error' => 'User not found']);
    }
    $stmt->close();

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Update recovery email
    $data = json_decode(file_get_contents('php://input'), true);
    $new_email = strtolower(trim($data['recovery_email'] ?? ''));
    $old_pass = $data['old_password'] ?? '';
    $new_pass = $data['new_password'] ?? '';

    if (!filter_var($new_email, FILTER_VALIDATE_EMAIL) || !str_ends_with($new_email, '@gmail.com')) {
        echo json_encode(['success' => false, 'error' => 'Invalid Gmail address.']);
        exit;
    }

    // Prevent duplicate emails (check if someone else is already using this Gmail)
    $check = $conn->prepare("SELECT id FROM user WHERE recovery_email = ? AND id != ?");
    $check->bind_param('ss', $new_email, $user_id);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'error' => 'This Gmail is already linked to another account.']);
        exit;
    }
    $check->close();

    // If they filled out the new password fields, validate and update it
    if (!empty($new_pass)) {
        if (empty($old_pass)) {
            echo json_encode(['success' => false, 'error' => 'Current password is required to change password.']);
            exit;
        }
        if (strlen($new_pass) < 6) {
            echo json_encode(['success' => false, 'error' => 'New password must be at least 6 characters.']);
            exit;
        }

        $stmt = $conn->prepare("SELECT password FROM user WHERE id = ?");
        $stmt->bind_param('s', $user_id);
        $stmt->execute();
        $user_data = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user_data || !password_verify($old_pass, $user_data['password'])) {
            echo json_encode(['success' => false, 'error' => 'Incorrect current password.']);
            exit;
        }

        $hashed_pass = password_hash($new_pass, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE user SET password = ? WHERE id = ?");
        $stmt->bind_param('ss', $hashed_pass, $user_id);
        $stmt->execute();
        $stmt->close();
    }

    $stmt = $conn->prepare("UPDATE user SET recovery_email = ? WHERE id = ?");
    $stmt->bind_param('ss', $new_email, $user_id);
    echo json_encode(['success' => $stmt->execute()]);
    $stmt->close();
}
?>