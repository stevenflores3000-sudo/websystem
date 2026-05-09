<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Created — NU-SmartVote</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&display=swap" rel="stylesheet">
    <style>
        :root {
            --nu-blue:      #0a58ca;
            --nu-blue-deep: #06357a;
            --nu-gold:      #f59e0b;
            --success:      #1aab6d;
            --success-deep: #0d7a4e;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Sora', sans-serif;
            min-height: 100vh;
            background: linear-gradient(145deg, var(--nu-blue-deep) 0%, #041f4a 60%, #021230 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }

        /* ── Animated background particles ── */
        .particles {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 0;
        }
        .particle {
            position: absolute;
            border-radius: 50%;
            opacity: 0;
            animation: floatUp linear infinite;
        }
        @keyframes floatUp {
            0%   { opacity: 0; transform: translateY(0) scale(0); }
            10%  { opacity: 1; }
            90%  { opacity: 0.6; }
            100% { opacity: 0; transform: translateY(-100vh) scale(1.5); }
        }

        /* ── Confetti pieces ── */
        .confetti-wrap {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 0;
            overflow: hidden;
        }
        .confetti-piece {
            position: absolute;
            top: -20px;
            width: 10px;
            height: 10px;
            opacity: 0;
            animation: confettiFall linear forwards;
        }
        @keyframes confettiFall {
            0%   { opacity: 1; transform: translateY(0) rotate(0deg); }
            100% { opacity: 0; transform: translateY(110vh) rotate(720deg); }
        }

        /* ── Glow orbs ── */
        .orb {
            position: fixed;
            border-radius: 50%;
            filter: blur(80px);
            pointer-events: none;
            z-index: 0;
        }
        .orb-1 { width: 400px; height: 400px; background: rgba(26,171,109,0.12); top: -100px; right: -100px; }
        .orb-2 { width: 300px; height: 300px; background: rgba(10,88,202,0.15); bottom: -80px; left: -80px; }
        .orb-3 { width: 200px; height: 200px; background: rgba(245,158,11,0.08); top: 50%; left: 50%; transform: translate(-50%,-50%); animation: pulse 3s ease infinite; }

        @keyframes pulse { 0%,100%{opacity:0.5;transform:translate(-50%,-50%) scale(1)} 50%{opacity:1;transform:translate(-50%,-50%) scale(1.3)} }

        /* ── Main card ── */
        .card {
            position: relative;
            z-index: 10;
            background: rgba(255,255,255,0.04);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 28px;
            padding: 3rem 3.5rem;
            max-width: 500px;
            width: 90%;
            text-align: center;
            box-shadow: 0 32px 80px rgba(0,0,0,0.4), 0 0 0 1px rgba(255,255,255,0.05);
            animation: cardIn 0.7s cubic-bezier(0.34,1.56,0.64,1) both;
        }
        @keyframes cardIn {
            from { opacity:0; transform: translateY(40px) scale(0.92); }
            to   { opacity:1; transform: translateY(0) scale(1); }
        }

        /* ── Success icon ── */
        .icon-wrap {
            width: 100px;
            height: 100px;
            margin: 0 auto 1.8rem;
            position: relative;
        }

        .icon-ring {
            position: absolute;
            inset: 0;
            border-radius: 50%;
            border: 3px solid rgba(26,171,109,0.3);
            animation: ringExpand 1.2s ease forwards 0.3s;
        }
        .icon-ring-2 {
            position: absolute;
            inset: -12px;
            border-radius: 50%;
            border: 2px solid rgba(26,171,109,0.15);
            animation: ringExpand 1.2s ease forwards 0.5s;
        }
        @keyframes ringExpand {
            from { transform: scale(0.5); opacity: 0; }
            to   { transform: scale(1);   opacity: 1; }
        }

        .icon-circle {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, var(--success), var(--success-deep));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.6rem;
            color: white;
            box-shadow: 0 12px 40px rgba(26,171,109,0.5);
            animation: iconPop 0.6s cubic-bezier(0.34,1.56,0.64,1) both 0.2s;
            position: relative;
            z-index: 1;
        }
        @keyframes iconPop {
            from { transform: scale(0) rotate(-180deg); opacity: 0; }
            to   { transform: scale(1) rotate(0deg);   opacity: 1; }
        }

        /* ── Text ── */
        .brand {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 50px;
            padding: 5px 14px;
            font-size: 0.72rem;
            font-weight: 700;
            color: rgba(255,255,255,0.6);
            letter-spacing: 1px;
            text-transform: uppercase;
            margin-bottom: 1.2rem;
            animation: fadeUp 0.5s ease both 0.5s;
        }
        .brand i { color: var(--nu-gold); }

        h1 {
            font-family: 'Libre Baskerville', serif;
            font-size: 2rem;
            font-weight: 700;
            color: white;
            line-height: 1.2;
            margin-bottom: 0.75rem;
            animation: fadeUp 0.5s ease both 0.6s;
        }
        h1 em { color: var(--nu-gold); font-style: italic; }

        .sub {
            font-size: 0.88rem;
            color: rgba(255,255,255,0.55);
            line-height: 1.7;
            margin-bottom: 2rem;
            animation: fadeUp 0.5s ease both 0.7s;
        }

        /* ── Info strip ── */
        .info-strip {
            background: rgba(26,171,109,0.1);
            border: 1px solid rgba(26,171,109,0.25);
            border-radius: 14px;
            padding: 1rem 1.2rem;
            display: flex;
            align-items: center;
            gap: 10px;
            text-align: left;
            margin-bottom: 2rem;
            animation: fadeUp 0.5s ease both 0.8s;
        }
        .info-strip i { color: var(--success); font-size: 1.2rem; flex-shrink: 0; }
        .info-strip p { font-size: 0.8rem; color: rgba(255,255,255,0.7); line-height: 1.55; }
        .info-strip strong { color: rgba(255,255,255,0.9); }

        /* ── Button ── */
        .btn-login {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            height: 52px;
            background: linear-gradient(135deg, var(--nu-blue), var(--nu-blue-deep));
            color: white;
            border: none;
            border-radius: 14px;
            font-family: 'Sora', sans-serif;
            font-size: 0.95rem;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            box-shadow: 0 8px 28px rgba(10,88,202,0.45);
            transition: transform 0.2s, box-shadow 0.2s, filter 0.2s;
            animation: fadeUp 0.5s ease both 0.9s;
            position: relative;
            overflow: hidden;
        }
        .btn-login::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.15), transparent);
            opacity: 0;
            transition: opacity 0.2s;
        }
        .btn-login:hover { transform: translateY(-2px); box-shadow: 0 12px 36px rgba(10,88,202,0.55); filter: brightness(1.08); }
        .btn-login:hover::before { opacity: 1; }
        .btn-login:active { transform: translateY(0); }

        /* ── Timer bar ── */
        .timer-bar-wrap {
            margin-top: 1.2rem;
            animation: fadeUp 0.5s ease both 1s;
        }
        .timer-label {
            font-size: 0.72rem;
            color: rgba(255,255,255,0.35);
            margin-bottom: 6px;
            display: flex;
            justify-content: space-between;
        }
        .timer-track {
            height: 3px;
            background: rgba(255,255,255,0.08);
            border-radius: 2px;
            overflow: hidden;
        }
        .timer-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--success), var(--nu-gold));
            border-radius: 2px;
            width: 100%;
            animation: timerShrink 6s linear forwards 1.2s;
        }
        @keyframes timerShrink { from { width:100%; } to { width:0%; } }

        @keyframes fadeUp {
            from { opacity:0; transform: translateY(16px); }
            to   { opacity:1; transform: translateY(0); }
        }
    </style>
</head>
<body>

<!-- Background orbs -->
<div class="orb orb-1"></div>
<div class="orb orb-2"></div>
<div class="orb orb-3"></div>

<!-- Floating particles -->
<div class="particles" id="particles"></div>

<!-- Confetti -->
<div class="confetti-wrap" id="confetti"></div>

<!-- Main Card -->
<div class="card">
    <div class="icon-wrap">
        <div class="icon-ring"></div>
        <div class="icon-ring-2"></div>
        <div class="icon-circle"><i class="bi bi-check-lg"></i></div>
    </div>

    <div class="brand"><i class="bi bi-shield-fill-check"></i> NU-SmartVote</div>

    <h1>Account <em>Created!</em></h1>
    <p class="sub">Your student voter account has been successfully registered in the NU-SmartVote system.</p>

    <div class="info-strip">
        <i class="bi bi-info-circle-fill"></i>
        <p>You can now log in using your <strong>Student ID</strong> and the password you just created. Keep your credentials safe.</p>
    </div>

    <a href="index.html" class="btn-login">
        <i class="bi bi-box-arrow-in-right"></i>
        Go to Login Now
    </a>

    <div class="timer-bar-wrap">
        <div class="timer-label">
            <span>Redirecting automatically…</span>
            <span id="countdown">6s</span>
        </div>
        <div class="timer-track"><div class="timer-fill"></div></div>
    </div>
</div>

<script>
    // ── Countdown + auto redirect ──
    let secs = 6;
    const countEl = document.getElementById('countdown');
    const timer = setInterval(() => {
        secs--;
        countEl.textContent = secs + 's';
        if (secs <= 0) { clearInterval(timer); window.location.href = 'index.html'; }
    }, 1000);

    // ── Floating particles ──
    const colors = ['#1aab6d','#0a58ca','#f59e0b','#ffffff','#6ee7b7'];
    const pWrap  = document.getElementById('particles');
    for (let i = 0; i < 20; i++) {
        const p = document.createElement('div');
        p.className = 'particle';
        const size = Math.random() * 8 + 3;
        p.style.cssText = `
            width:${size}px; height:${size}px;
            left:${Math.random()*100}%;
            bottom:${Math.random()*20}%;
            background:${colors[Math.floor(Math.random()*colors.length)]};
            animation-duration:${Math.random()*6+4}s;
            animation-delay:${Math.random()*3}s;
        `;
        pWrap.appendChild(p);
    }

    // ── Confetti burst ──
    const confettiColors = ['#1aab6d','#0a58ca','#f59e0b','#ec4899','#8b5cf6','#ffffff'];
    const cWrap = document.getElementById('confetti');
    for (let i = 0; i < 60; i++) {
        const c = document.createElement('div');
        c.className = 'confetti-piece';
        const size  = Math.random() * 10 + 5;
        const shape = Math.random() > 0.5 ? '50%' : (Math.random() > 0.5 ? '2px' : '0');
        c.style.cssText = `
            width:${size}px; height:${size}px;
            left:${Math.random()*100}%;
            background:${confettiColors[Math.floor(Math.random()*confettiColors.length)]};
            border-radius:${shape};
            animation-duration:${Math.random()*2.5+1.5}s;
            animation-delay:${Math.random()*0.8}s;
        `;
        cWrap.appendChild(c);
    }
</script>
</body>
</html>