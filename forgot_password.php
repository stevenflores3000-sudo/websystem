<?php
// ═══════════════════════════════════════════════════════════════════════
//  forgot_password.php
//  Accepts POST from the "Forgot Password" form.
//  Looks up the user by their recovery_email (personal Gmail),
//  generates a 6-digit OTP, stores it in password_reset_tokens,
//  and sends it via PHP mail() / PHPMailer.
//
//  POST: recovery_email
//  → On success: redirect to verify_otp.php
//  → On fail:    redirect to index.html?error=reset_failed
// ═══════════════════════════════════════════════════════════════════════
session_start();
include 'db_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.html');
    exit();
}

$recovery_email = strtolower(trim($_POST['recovery_email'] ?? ''));

if (!filter_var($recovery_email, FILTER_VALIDATE_EMAIL)) {
    header('Location: index.html?error=invalid_reset_email');
    exit();
}

// ── 1. Look up user by recovery_email ───────────────────────────────
$stmt = $conn->prepare("SELECT id, name FROM user WHERE recovery_email = ? LIMIT 1");
$stmt->bind_param('s', $recovery_email);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    // Do NOT reveal whether the email exists — security best practice
    // Show the same "check your inbox" message either way
    header('Location: index.html?reset=sent');
    exit();
}

$user_id   = $row['id'];
$user_name = $row['name'];

// ── 2. Expire any previous unused tokens for this user ──────────────
$stmt_update = $conn->prepare("UPDATE password_reset_tokens SET used = 1 WHERE user_id = ? AND used = 0");
if ($stmt_update) {
    $stmt_update->bind_param('s', $user_id);
    $stmt_update->execute();
    $stmt_update->close();
}
// (fire-and-forget — errors here are non-critical)

// ── 3. Generate 6-digit OTP ─────────────────────────────────────────
$otp        = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
// Use database time to avoid timezone issues
$stmt_time = $conn->prepare("SELECT DATE_ADD(NOW(), INTERVAL 15 MINUTE) as expires_at");
$stmt_time->execute();
$time_row = $stmt_time->get_result()->fetch_assoc();
$expires_at = $time_row['expires_at'];
$stmt_time->close();

// ── 4. Store token in DB ─────────────────────────────────────────────
$stmt = $conn->prepare(
    "INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)"
);
$stmt->bind_param('sss', $user_id, $otp, $expires_at);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    header('Location: index.html?error=reset_db_error');
    exit();
}

// ── 5. Send OTP via email ────────────────────────────────────────────
/* ── Option A: PHP mail() (Disabled for local XAMPP) ─────────────
 * $subject = 'NU-SmartVote — Your Password Reset Code';
 * $body    = "Hello {$user_name},\n\n"
 *          . "Your NU-SmartVote password reset code is:\n\n"
 *          . "  {$otp}\n\n"
 *          . "This code expires in 15 minutes. Do not share it with anyone.\n\n"
 *          . "If you did not request this, ignore this email.\n\n"
 *          . "— NU-SmartVote System";
 * 
 * $headers = "From: noreply@nu-dasma.edu.ph\r\n"
 *          . "Reply-To: noreply@nu-dasma.edu.ph\r\n"
 *          . "X-Mailer: PHP/" . phpversion();
 * 
 * mail($recovery_email, $subject, $body, $headers);
 */

// ── Option B: PHPMailer via Gmail SMTP
if (!file_exists('vendor/autoload.php')) {
    error_log("PHPMailer is not installed. Please run 'composer require phpmailer/phpmailer'");
    header('Location: index.html?error=phpmailer_missing');
    exit();
}
require 'vendor/autoload.php';
require 'config.php';
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
    $mail->Subject = 'NU-SmartVote — Your Password Reset Code';
    $mail->Body    = "Hello {$user_name},\n\nYour reset code: {$otp}\n\nExpires in 15 minutes.";
    $mail->send();
} catch (Exception $e) {
    // Log but don't expose — show success page anyway (security)
    error_log("PHPMailer error: " . $mail->ErrorInfo);
}

// ── 6. Store recovery email in session for verify_otp.php ───────────
$_SESSION['reset_user_id']       = $user_id;
$_SESSION['reset_recovery_email'] = $recovery_email;

header('Location: verify_otp.php');
exit();
?>