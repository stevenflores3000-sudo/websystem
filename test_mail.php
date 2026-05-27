<?php
// Quick test to see if PHPMailer is working
require 'vendor/autoload.php';
require 'config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);

try {
    echo "<pre>Testing PHPMailer connection...\n\n";
    
    // Turn on detailed debugging text
    $mail->SMTPDebug = 2;

    $mail->isSMTP();
    $mail->Host       = MAIL_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = MAIL_USERNAME;
    $mail->Password   = MAIL_PASSWORD;
    $mail->SMTPSecure = (MAIL_PORT == 465) ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = MAIL_PORT;

    // Bypass local XAMPP SSL checks
    $mail->SMTPOptions = array(
        'ssl' => array(
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        )
    );

    echo "✓ SMTP settings configured\n";

    // Try to connect
    if (!$mail->smtpConnect()) {
        echo "❌ SMTP Connection failed: " . $mail->ErrorInfo . "\n";
        exit(1);
    }
    echo "✓ Connected to Gmail SMTP\n";

    $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
    $mail->addAddress('kuyarodelthesecond@gmail.com'); // <-- PUT YOUR REAL GMAIL HERE!
    $mail->Subject = 'TEST - NU-SmartVote Email System';
    $mail->Body    = 'If you see this, PHPMailer is working correctly!';

    if ($mail->send()) {
        echo "✓ Email sent successfully!\n";
        echo "Check your inbox at your personal Gmail!\n";
    } else {
        echo "❌ Send failed: " . $mail->ErrorInfo . "\n";
    }
    echo "</pre>";

} catch (Exception $e) {
    echo "❌ Exception: " . $e->getMessage() . "\n";
    echo "Error Info: " . $mail->ErrorInfo . "\n</pre>";
}
?>
