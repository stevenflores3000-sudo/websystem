<?php
session_start();
// Security check: ensure only admins can run this script
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Unauthorized access.");
}

// Quick test to see if PHPMailer is working
require 'vendor/autoload.php';
require 'config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);

try {
    echo "Testing PHPMailer connection...\n";

    $mail->isSMTP();
    $mail->Host       = MAIL_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = MAIL_USERNAME;
    $mail->Password   = MAIL_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = MAIL_PORT;

    echo "✓ SMTP settings configured\n";

    // Try to connect
    if (!$mail->smtpConnect()) {
        echo "❌ SMTP Connection failed: " . $mail->ErrorInfo . "\n";
        exit(1);
    }
    echo "✓ Connected to Gmail SMTP\n";

    $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
    $mail->addAddress('stevenfloresbayona@gmail.com'); // Your actual email
    $mail->Subject = 'TEST - NU-SmartVote Email System';
    $mail->Body    = 'If you see this, PHPMailer is working correctly!';

    if ($mail->send()) {
        echo "✓ Email sent successfully!\n";
        echo "Check your inbox at: stevenfloresbayona@gmail.com\n";
    } else {
        echo "❌ Send failed: " . $mail->ErrorInfo . "\n";
    }

} catch (Exception $e) {
    echo "❌ Exception: " . $e->getMessage() . "\n";
    echo "Error Info: " . $mail->ErrorInfo . "\n";
}
?>
