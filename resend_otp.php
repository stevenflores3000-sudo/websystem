<?php
// ═══════════════════════════════════════════════════════════════════════
//  resend_otp.php
//  AJAX endpoint to resend OTP code if user didn't receive it
//  POST: none (uses session data)
//  Returns: JSON {success: true/false, message: "..."}
// ═══════════════════════════════════════════════════════════════════════
session_start();
header('Content-Type: application/json');

// Verify user is in the OTP verification flow
if (!isset($_SESSION['reset_user_id']) || !isset($_SESSION['reset_recovery_email'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Session expired. Please start over.']);
    exit();
}

include 'db_connect.php';
require 'config.php';

$user_id       = $_SESSION['reset_user_id'];
$recovery_email = $_SESSION['reset_recovery_email'];

// Get user name
$stmt = $conn->prepare("SELECT name FROM user WHERE id = ? LIMIT 1");
$stmt->bind_param('s', $user_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'User not found.']);
    exit();
}

$user_name = $row['name'];

// Generate new OTP
$otp        = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
// Use database time to avoid timezone issues
$stmt_time = $conn->prepare("SELECT DATE_ADD(NOW(), INTERVAL 15 MINUTE) as expires_at");
$stmt_time->execute();
$time_row = $stmt_time->get_result()->fetch_assoc();
$expires_at = $time_row['expires_at'];
$stmt_time->close();

// Mark old OTP as used
$stmt_update = $conn->prepare("UPDATE password_reset_tokens SET used = 1 WHERE user_id = ? AND used = 0");
if ($stmt_update) {
    $stmt_update->bind_param('s', $user_id);
    $stmt_update->execute();
    $stmt_update->close();
}

// Store new OTP in DB
$stmt = $conn->prepare(
    "INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)"
);
$stmt->bind_param('sss', $user_id, $otp, $expires_at);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
    exit();
}

// Send OTP via PHPMailer
if (!file_exists('vendor/autoload.php')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'PHPMailer not installed.']);
    exit();
}

require 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = MAIL_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = MAIL_USERNAME;
    $mail->Password   = MAIL_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = MAIL_PORT;

    $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
    $mail->addAddress($recovery_email, $user_name);
    $mail->Subject = 'NU-SmartVote — Your New Password Reset Code';
    $mail->Body    = "Hello {$user_name},\n\nHere is your new reset code:\n\n{$otp}\n\nExpires in 15 minutes.";
    $mail->send();

    echo json_encode(['success' => true, 'message' => 'New code sent to your email!']);
} catch (Exception $e) {
    error_log("PHPMailer resend error: " . $mail->ErrorInfo);
    echo json_encode(['success' => false, 'message' => 'Failed to send email. Try again.']);
}
?>
