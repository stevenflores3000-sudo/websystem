<?php
session_start();
include 'db_connect.php';

// Prevent direct access unless the OTP was successfully verified
if (!isset($_SESSION['reset_user_id']) || empty($_SESSION['otp_verified'])) {
    header('Location: index.html');
    exit();
}

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_pass = $_POST['new_password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (strlen($new_pass) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } elseif ($new_pass !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        // Get current password to check if new password is same
        $stmt_old = $conn->prepare("SELECT password FROM user WHERE id = ?");
        $stmt_old->bind_param('s', $_SESSION['reset_user_id']);
        $stmt_old->execute();
        $row_old = $stmt_old->get_result()->fetch_assoc();
        $stmt_old->close();

        if ($row_old && password_verify($new_pass, $row_old['password'])) {
            $error = 'New password cannot be the same as your old password.';
        } else {
            // Encrypt the new password and update the database
            $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE user SET password = ? WHERE id = ?");
            $stmt->bind_param('ss', $hashed, $_SESSION['reset_user_id']);

            if ($stmt->execute()) {
                // Destroy the reset session data so it can't be reused
                unset($_SESSION['reset_user_id'], $_SESSION['reset_recovery_email'], $_SESSION['otp_verified']);
                $success = true;
            } else {
                $error = 'Database error. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New Password — NU-SmartVote</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=Libre+Baskerville:ital,wght@0,700;1,400&display=swap" rel="stylesheet">
    <style>
        /* Reusing the dark theme layout */
        :root { --nu-blue: #0a58ca; --nu-blue-deep: #06357a; --nu-gold: #f59e0b; --success: #1aab6d; }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Sora', sans-serif; min-height: 100vh; background: linear-gradient(145deg, var(--nu-blue-deep) 0%, #041f4a 60%, #021230 100%); display: flex; align-items: center; justify-content: center; overflow: hidden; position: relative; color: white; }
        .card { position: relative; z-index: 10; background: rgba(255,255,255,0.04); backdrop-filter: blur(20px); border: 1px solid rgba(255,255,255,0.1); border-radius: 28px; padding: 3rem 3.5rem; max-width: 480px; width: 90%; text-align: center; box-shadow: 0 32px 80px rgba(0,0,0,0.4); animation: cardIn 0.6s cubic-bezier(0.34,1.56,0.64,1) both; }
        @keyframes cardIn { from{opacity:0;transform:translateY(40px) scale(0.92);} to{opacity:1;transform:translateY(0) scale(1);} }
        
        .icon-wrap { width: 80px; height: 80px; margin: 0 auto 1.5rem; background: linear-gradient(135deg, var(--nu-blue), var(--nu-blue-deep)); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2rem; box-shadow: 0 12px 30px rgba(10,88,202,0.4); }
        .icon-success { background: linear-gradient(135deg, var(--success), #0d7a4e); box-shadow: 0 12px 30px rgba(26,171,109,0.4); }
        
        h1 { font-family: 'Libre Baskerville', serif; font-size: 1.8rem; font-weight: 700; margin-bottom: 0.5rem; }
        h1 em { color: var(--nu-gold); font-style: italic; }
        .sub { font-size: 0.85rem; color: rgba(255,255,255,0.6); line-height: 1.6; margin-bottom: 2rem; }

        .input-label { display: block; text-align: left; font-size: 0.8rem; color: rgba(255,255,255,0.6); margin-bottom: 6px; }
        .input-field { width: 100%; background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.15); color: white; padding: 12px 14px; border-radius: 12px; font-family: 'Sora', sans-serif; margin-bottom: 1.2rem; transition: all 0.2s; }
        .input-field:focus { outline: none; border-color: var(--nu-gold); box-shadow: 0 0 15px rgba(245,158,11,0.2); }
        
        .btn-submit { display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; height: 50px; background: linear-gradient(135deg, var(--nu-gold), #d97706); color: white; border: none; border-radius: 12px; font-size: 0.95rem; font-weight: 700; cursor: pointer; box-shadow: 0 8px 24px rgba(245,158,11,0.3); transition: transform 0.2s, filter 0.2s; margin-top: 0.5rem;}
        .btn-submit:hover { transform: translateY(-2px); filter: brightness(1.1); }
        
        .btn-login { background: linear-gradient(135deg, var(--nu-blue), var(--nu-blue-deep)); box-shadow: 0 8px 24px rgba(10,88,202,0.4); text-decoration: none;}
        
        .error-box { background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: #fca5a5; padding: 12px; border-radius: 12px; font-size: 0.85rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 8px; text-align: left; }

        .password-wrapper { position: relative; margin-bottom: 1.2rem; }
        .password-wrapper .input-field { padding-right: 45px; }
        .toggle-password { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; color: rgba(255,255,255,0.6); cursor: pointer; font-size: 1.1rem; transition: color 0.2s; }
        .toggle-password:hover { color: var(--nu-gold); }
    </style>
</head>
<body>
    <div class="card">
        <?php if ($success): ?>
            <!-- SUCCESS STATE -->
            <div class="icon-wrap icon-success"><i class="bi bi-check-lg"></i></div>
            <h1>Password <em>Updated!</em></h1>
            <p class="sub">Your password has been successfully changed. You can now log into your account using your new credentials.</p>
            
            <a href="index.html" class="btn-submit btn-login">
                <i class="bi bi-box-arrow-in-right"></i> Go to Login
            </a>
            
        <?php else: ?>
            <!-- RESET FORM STATE -->
            <div class="icon-wrap"><i class="bi bi-shield-lock-fill"></i></div>
            <h1>Create <em>Password</em></h1>
            <p class="sub">Enter a strong password for your account.</p>
            
            <?php if ($error): ?>
                <div class="error-box"><i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <div>
                    <label class="input-label">New Password</label>
                    <div class="password-wrapper">
                        <input type="password" name="new_password" class="input-field password-input" id="pwd1" placeholder="Min. 6 characters" required autofocus>
                        <button type="button" class="toggle-password" onclick="togglePassword('pwd1')">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>
                <div>
                    <label class="input-label">Confirm Password</label>
                    <div class="password-wrapper">
                        <input type="password" name="confirm_password" class="input-field password-input" id="pwd2" placeholder="Repeat new password" required>
                        <button type="button" class="toggle-password" onclick="togglePassword('pwd2')">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="bi bi-save"></i> Update Password
                </button>
            </form>

            <script>
                function togglePassword(fieldId) {
                    const field = document.getElementById(fieldId);
                    const icon = event.target.closest('.toggle-password').querySelector('i');

                    if (field.type === 'password') {
                        field.type = 'text';
                        icon.classList.remove('bi-eye');
                        icon.classList.add('bi-eye-slash');
                    } else {
                        field.type = 'password';
                        icon.classList.remove('bi-eye-slash');
                        icon.classList.add('bi-eye');
                    }
                }
            </script>
        <?php endif; ?>
    </div>
</body>
</html>