<?php
session_start();
include 'db_connect.php';

// Ensure the user went through the forgot_password flow first
if (!isset($_SESSION['reset_user_id']) || !isset($_SESSION['reset_recovery_email'])) {
    header('Location: index.html');
    exit();
}

$error = '';
$user_id = $_SESSION['reset_user_id'];
$email = $_SESSION['reset_recovery_email'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $otp = trim($_POST['otp'] ?? '');

    // Check if the OTP exists, belongs to the user, is unused, and hasn't expired
    $stmt = $conn->prepare("SELECT id FROM password_reset_tokens WHERE user_id = ? AND token = ? AND used = 0 AND expires_at > NOW() LIMIT 1");
    $stmt->bind_param('ss', $user_id, $otp);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        // 1. Mark token as used so it cannot be reused
        $update = $conn->prepare("UPDATE password_reset_tokens SET used = 1 WHERE id = ?");
        $update->bind_param('i', $row['id']);
        $update->execute();

        // 2. Grant session permission to access the reset password page
        $_SESSION['otp_verified'] = true;
        header('Location: reset_password.php');
        exit();
    } else {
        $error = 'Invalid or expired verification code.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Email — NU-SmartVote</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=Libre+Baskerville:ital,wght@0,700;1,400&display=swap" rel="stylesheet">
    <style>
        :root { --nu-blue: #0a58ca; --nu-blue-deep: #06357a; --nu-gold: #f59e0b; }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Sora', sans-serif; min-height: 100vh;
            background: linear-gradient(145deg, var(--nu-blue-deep) 0%, #041f4a 60%, #021230 100%);
            display: flex; align-items: center; justify-content: center;
            overflow: hidden; position: relative; color: white;
        }
        .card {
            position: relative; z-index: 10;
            background: rgba(255,255,255,0.04); backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.1); border-radius: 28px;
            padding: 3rem 3.5rem; max-width: 480px; width: 90%; text-align: center;
            box-shadow: 0 32px 80px rgba(0,0,0,0.4);
            animation: cardIn 0.6s cubic-bezier(0.34,1.56,0.64,1) both;
        }
        @keyframes cardIn { from{opacity:0;transform:translateY(40px) scale(0.92);} to{opacity:1;transform:translateY(0) scale(1);} }
        
        .icon-wrap { width: 80px; height: 80px; margin: 0 auto 1.5rem; background: linear-gradient(135deg, var(--nu-blue), var(--nu-blue-deep)); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2rem; box-shadow: 0 12px 30px rgba(10,88,202,0.4); }
        h1 { font-family: 'Libre Baskerville', serif; font-size: 1.8rem; font-weight: 700; margin-bottom: 0.5rem; }
        h1 em { color: var(--nu-gold); font-style: italic; }
        .sub { font-size: 0.85rem; color: rgba(255,255,255,0.6); line-height: 1.6; margin-bottom: 2rem; }
        .sub strong { color: rgba(255,255,255,0.9); }

        .input-field {
            width: 100%; background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.15);
            color: white; padding: 14px; border-radius: 12px; font-family: 'Sora', sans-serif;
            margin-bottom: 1.5rem; transition: all 0.2s;
        }
        .input-field:focus { outline: none; border-color: var(--nu-gold); box-shadow: 0 0 15px rgba(245,158,11,0.2); }
        .otp-field { font-size: 2rem; letter-spacing: 12px; text-align: left; font-weight: 700; }
        
        .btn-submit {
            display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; height: 50px;
            background: linear-gradient(135deg, var(--nu-gold), #d97706); color: white; border: none; border-radius: 12px;
            font-size: 0.95rem; font-weight: 700; cursor: pointer;
            box-shadow: 0 8px 24px rgba(245,158,11,0.3); transition: transform 0.2s, filter 0.2s;
        }
        .btn-submit:hover { transform: translateY(-2px); filter: brightness(1.1); }
        .error-box { background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: #fca5a5; padding: 12px; border-radius: 12px; font-size: 0.85rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 8px; text-align: left; }
        
        .link-cancel { display: inline-block; margin-top: 1.5rem; color: rgba(255,255,255,0.5); text-decoration: none; font-size: 0.8rem; transition: color 0.2s; }
        .link-cancel:hover { color: white; }

        .timer-box { background: rgba(245,158,11,0.1); border: 1px solid rgba(245,158,11,0.3); color: #fde047; padding: 12px; border-radius: 12px; font-size: 0.85rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 8px; justify-content: center; }
        .timer-box i { font-size: 1rem; }
        .timer-text { font-weight: 600; }

        .btn-resend { background: rgba(245,158,11,0.2); border: 1px solid rgba(245,158,11,0.4); color: #fde047; padding: 10px 16px; border-radius: 10px; cursor: pointer; font-size: 0.85rem; font-weight: 600; transition: all 0.2s; width: 100%; margin-top: 1rem; }
        .btn-resend:hover:not(:disabled) { background: rgba(245,158,11,0.3); transform: translateY(-1px); }
        .btn-resend:disabled { opacity: 0.6; cursor: not-allowed; }
        .resend-loading { display: none; color: #fde047; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon-wrap"><i class="bi bi-envelope-open-fill"></i></div>
        <h1>Verify <em>Email</em></h1>
        <p class="sub">Enter the 6-digit code we sent to <strong><?= htmlspecialchars($email) ?></strong> to continue.</p>

        <div class="timer-box">
            <i class="bi bi-hourglass-split"></i>
            <span class="timer-text">Resend code in <span id="timer">60</span>s</span>
        </div>

        <?php if ($error): ?>
            <div class="error-box"><i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="text" name="otp" class="input-field otp-field" placeholder="000000" maxlength="6" pattern="\d{6}" required autocomplete="off" autofocus>
            <button type="submit" class="btn-submit">
                Verify Code <i class="bi bi-arrow-right ms-1"></i>
            </button>
        </form>

        <button type="button" id="btnResend" class="btn-resend" onclick="resendCode()">
            <span class="resend-text"><i class="bi bi-arrow-clockwise"></i> Resend Code</span>
            <span class="resend-loading"><i class="bi bi-hourglass-split"></i> Sending...</span>
        </button>

        <a href="index.html" class="link-cancel">Cancel and return to login</a>
    </div>

    <script>
        let resendCooldown = 60;
        const timerEl = document.getElementById('timer');
        const btnResend = document.getElementById('btnResend');
        const resendText = document.querySelector('.resend-text');
        const resendLoading = document.querySelector('.resend-loading');

        // Disable resend button for 60 seconds on page load
        btnResend.disabled = true;
        const cooldownInterval = setInterval(() => {
            resendCooldown--;
            timerEl.textContent = resendCooldown;

            if (resendCooldown <= 0) {
                clearInterval(cooldownInterval);
                timerEl.parentElement.innerHTML = 'Ready to resend ✓';
                btnResend.disabled = false;
            }
        }, 1000);

        function resendCode() {
            btnResend.disabled = true;
            resendText.style.display = 'none';
            resendLoading.style.display = 'inline';

            fetch('resend_otp.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' }
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert('✅ ' + data.message);
                    resendCooldown = 60;
                    timerEl.textContent = '60';

                    clearInterval(cooldownInterval);
                    const newCooldown = setInterval(() => {
                        resendCooldown--;
                        timerEl.textContent = resendCooldown;
                        if (resendCooldown <= 0) {
                            clearInterval(newCooldown);
                            timerEl.parentElement.innerHTML = 'Ready to resend ✓';
                        }
                    }, 1000);

                    btnResend.disabled = true;
                    resendText.style.display = 'inline';
                    resendLoading.style.display = 'none';
                } else {
                    alert('❌ ' + data.message);
                    btnResend.disabled = false;
                    resendText.style.display = 'inline';
                    resendLoading.style.display = 'none';
                }
            })
            .catch(err => {
                alert('❌ Error: ' + err.message);
                btnResend.disabled = false;
                resendText.style.display = 'inline';
                resendLoading.style.display = 'none';
            });
        }
    </script>
</body>
</html>