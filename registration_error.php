<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration Failed — NU-SmartVote</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&display=swap" rel="stylesheet">
    <style>
        :root {
            --nu-blue: #0a58ca; --nu-blue-deep: #06357a;
            --nu-gold: #f59e0b; --danger: #ef4444; --danger-deep: #b91c1c;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Sora', sans-serif; min-height: 100vh;
            background: linear-gradient(145deg, #1a0a0a 0%, #2d0f0f 40%, #1a1020 100%);
            display: flex; align-items: center; justify-content: center;
            overflow: hidden; position: relative;
        }
        .orb { position: fixed; border-radius: 50%; filter: blur(80px); pointer-events: none; z-index: 0; }
        .orb-1 { width:350px; height:350px; background:rgba(239,68,68,0.1); top:-80px; right:-80px; }
        .orb-2 { width:280px; height:280px; background:rgba(10,88,202,0.1); bottom:-60px; left:-60px; }
        .orb-3 { width:180px; height:180px; background:rgba(239,68,68,0.06); top:50%; left:50%;
                  transform:translate(-50%,-50%); animation:pulseDanger 2.5s ease infinite; }
        @keyframes pulseDanger {
            0%,100%{opacity:0.4;transform:translate(-50%,-50%) scale(1)}
            50%{opacity:0.8;transform:translate(-50%,-50%) scale(1.4)}
        }
        body::before {
            content:''; position:fixed; inset:0;
            background-image: linear-gradient(rgba(239,68,68,0.03) 1px,transparent 1px),
                              linear-gradient(90deg,rgba(239,68,68,0.03) 1px,transparent 1px);
            background-size:40px 40px; pointer-events:none; z-index:0;
        }
        .card {
            position:relative; z-index:10;
            background:rgba(255,255,255,0.03); backdrop-filter:blur(20px);
            border:1px solid rgba(239,68,68,0.15); border-radius:28px;
            padding:3rem 3.5rem; max-width:520px; width:90%; text-align:center;
            box-shadow:0 32px 80px rgba(0,0,0,0.5),0 0 60px rgba(239,68,68,0.06);
            animation:cardShake 0.6s cubic-bezier(0.36,0.07,0.19,0.97) both;
        }
        @keyframes cardShake {
            0%  {transform:translateY(40px) scale(0.92);opacity:0}
            40% {transform:translateY(-8px) scale(1.01);opacity:1}
            60% {transform:translateX(-5px)}
            80% {transform:translateX(5px)}
            100%{transform:translateX(0) translateY(0) scale(1);opacity:1}
        }
        .icon-wrap { width:100px; height:100px; margin:0 auto 1.8rem; position:relative; }
        .icon-ring  { position:absolute; inset:0;     border-radius:50%; border:2px solid rgba(239,68,68,0.3); animation:ringPop 0.5s ease forwards 0.3s; opacity:0; }
        .icon-ring-2{ position:absolute; inset:-14px; border-radius:50%; border:1.5px solid rgba(239,68,68,0.12); animation:ringPop 0.5s ease forwards 0.5s; opacity:0; }
        @keyframes ringPop { from{transform:scale(0.5);opacity:0} to{transform:scale(1);opacity:1} }
        .icon-circle {
            width:100px; height:100px;
            background:linear-gradient(135deg,var(--danger),var(--danger-deep));
            border-radius:50%; display:flex; align-items:center; justify-content:center;
            font-size:2.6rem; color:white;
            box-shadow:0 12px 40px rgba(239,68,68,0.45);
            animation:iconBounce 0.6s cubic-bezier(0.34,1.56,0.64,1) both 0.2s;
            position:relative; z-index:1;
        }
        @keyframes iconBounce { from{transform:scale(0);opacity:0} to{transform:scale(1);opacity:1} }
        .brand {
            display:inline-flex; align-items:center; gap:8px;
            background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.08);
            border-radius:50px; padding:5px 14px;
            font-size:0.72rem; font-weight:700; color:rgba(255,255,255,0.5);
            letter-spacing:1px; text-transform:uppercase; margin-bottom:1.2rem;
            animation:fadeUp 0.5s ease both 0.5s;
        }
        .brand i { color:var(--nu-gold); }
        h1 { font-family:'Libre Baskerville',serif; font-size:2rem; font-weight:700; color:white; line-height:1.2; margin-bottom:0.75rem; animation:fadeUp 0.5s ease both 0.6s; }
        h1 em { color:#fca5a5; font-style:italic; }
        .sub { font-size:0.88rem; color:rgba(255,255,255,0.45); line-height:1.7; margin-bottom:1.8rem; animation:fadeUp 0.5s ease both 0.7s; }
        .error-box {
            background:rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,0.2);
            border-radius:14px; padding:1rem 1.2rem;
            display:flex; align-items:flex-start; gap:10px;
            text-align:left; margin-bottom:2rem; animation:fadeUp 0.5s ease both 0.8s;
        }
        .error-box i { color:#f87171; font-size:1.1rem; flex-shrink:0; margin-top:1px; }
        .error-box p { font-size:0.82rem; color:rgba(255,255,255,0.65); line-height:1.6; }
        .error-box strong { color:#fca5a5; }
        .btn-group { display:flex; flex-direction:column; gap:10px; animation:fadeUp 0.5s ease both 0.9s; }
        .btn-retry {
            display:flex; align-items:center; justify-content:center; gap:8px; height:50px;
            background:linear-gradient(135deg,var(--danger),var(--danger-deep));
            color:white; border:none; border-radius:14px;
            font-family:'Sora',sans-serif; font-size:0.9rem; font-weight:700;
            cursor:pointer; text-decoration:none;
            box-shadow:0 8px 24px rgba(239,68,68,0.4);
            transition:transform 0.2s,box-shadow 0.2s,filter 0.2s;
        }
        .btn-retry:hover { transform:translateY(-2px); box-shadow:0 12px 32px rgba(239,68,68,0.5); filter:brightness(1.08); }
        .btn-login {
            display:flex; align-items:center; justify-content:center; gap:8px; height:46px;
            background:rgba(255,255,255,0.06); color:rgba(255,255,255,0.7);
            border:1px solid rgba(255,255,255,0.12); border-radius:14px;
            font-family:'Sora',sans-serif; font-size:0.85rem; font-weight:600;
            cursor:pointer; text-decoration:none;
            transition:background 0.2s,color 0.2s,border-color 0.2s;
        }
        .btn-login:hover { background:rgba(255,255,255,0.1); color:white; border-color:rgba(255,255,255,0.2); }
        @keyframes fadeUp { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:translateY(0)} }
    </style>
</head>
<body>
<div class="orb orb-1"></div>
<div class="orb orb-2"></div>
<div class="orb orb-3"></div>

<?php
$type = $_GET['type'] ?? 'unknown';

// ── Map error type → (title, message) ────────────────────────────
$errors = [
    'duplicate_id' => [
        'ID Already <em>Exists</em>',
        'A student account with that <strong>Student ID</strong> is already registered. If this is your ID, please log in instead.'
    ],
    'duplicate_email' => [
        'Email Already <em>Registered</em>',
        'An account with that <strong>NU email address</strong> already exists. Try logging in, or check the email you entered.'
    ],
    'duplicate_recovery_email' => [
        'Gmail Already <em>Used</em>',
        'That <strong>personal Gmail address</strong> is already linked to another account. Use a different Gmail for your recovery email.'
    ],
    'missing_fields' => [
        'Incomplete <em>Form</em>',
        'Some required fields were <strong>missing or empty</strong>. Please fill in all fields — including your personal Gmail — and try again.'
    ],
    'invalid_email' => [
        'Invalid <em>NU Email</em>',
        'The NU email address you entered is not valid. It should look like <strong>jdelacruz@nu-dasma.edu.ph</strong>.'
    ],
    'invalid_recovery_email' => [
        'Invalid <em>Gmail Address</em>',
        'The recovery email you entered is not a valid email address. Please enter your <strong>personal Gmail</strong> (e.g. yourname@gmail.com).'
    ],
    'recovery_not_gmail' => [
        'Not a <em>Gmail Address</em>',
        'The recovery email must be a <strong>Gmail address</strong> (e.g. yourname@gmail.com). Other email providers are not supported for password recovery.'
    ],
    'weak_password' => [
        'Password Too <em>Short</em>',
        'Your password must be <strong>at least 6 characters</strong> long. Please choose a stronger password.'
    ],
    'db_error' => [
        'Database <em>Error</em>',
        'A server-side error occurred. Please contact the system administrator or try again later. Make sure <strong>schema_updates.sql</strong> has been run.'
    ],
];

[$title, $message] = $errors[$type] ?? [
    'Registration <em>Failed</em>',
    'Something went wrong while creating your account. Please try again.'
];
?>

<div class="card">
    <div class="icon-wrap">
        <div class="icon-ring"></div>
        <div class="icon-ring-2"></div>
        <div class="icon-circle"><i class="bi bi-x-lg"></i></div>
    </div>
    <div class="brand"><i class="bi bi-shield-fill-check"></i> NU-SmartVote</div>
    <h1><?= $title ?></h1>
    <p class="sub">Your account could not be created. See the details below.</p>
    <div class="error-box">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <p><?= $message ?></p>
    </div>
    <div class="btn-group">
        <a href="javascript:history.back()" class="btn-retry">
            <i class="bi bi-arrow-counterclockwise"></i> Try Again
        </a>
        <a href="index.html" class="btn-login">
            <i class="bi bi-box-arrow-in-right"></i> Go to Login Instead
        </a>
    </div>
</div>
</body>
</html>