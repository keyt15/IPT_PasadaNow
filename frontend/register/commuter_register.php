<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../../backend/config.php';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname = trim($_POST['fullname'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if ($password !== $confirm)       { $error = "Passwords do not match."; }
    elseif (!isset($_POST['terms']))  { $error = "You must agree to the Terms & Conditions."; }
    else {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
        if (!$check) { $error = "DB error (check): " . $conn->error; }
        else {
            $check->bind_param("s", $email); $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $error = "Email already exists!";
            } else {
                $stmt = $conn->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, 'commuter')");
                if (!$stmt) { $error = "DB error (insert): " . $conn->error; }
                else {
                    $stmt->bind_param("sss", $fullname, $email, $hashed);
                    if ($stmt->execute()) { header("Location: ../login.php"); exit(); }
                    else { $error = "Registration failed: " . $stmt->error; }
                }
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
    <title>Commuter Registration - PasadaNow</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&family=Inter:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --navy-deep:   #0b1929; --navy-mid:  #0f2236; --panel-bg: #111f30;
            --border:      #1e4a72; --border-glow: #2878b4; --input-bg: #0d2035;
            --blue-accent: #2878b4; --orange: #e07820;
            --text-main:   #e8f0f8; --text-muted: #7a9bb8; --text-dim: #4a7090;
        }
        html, body { height: 100%; overflow: hidden; background: var(--navy-deep); font-family: 'Inter', sans-serif; color: var(--text-main); }
        .page-wrapper { display: flex; height: 100vh; overflow: hidden; }
        .left-panel { flex: 0 0 48%; position: relative; overflow: hidden; }
        .left-panel img { width: 100%; height: 100%; object-fit: cover; object-position: center top; filter: brightness(.88) saturate(1.05); }
        .left-panel::after { content: ''; position: absolute; inset: 0; background: linear-gradient(to right, rgba(11,25,41,.10) 0%, rgba(11,25,41,.55) 100%); }

        /* ── Overlay text on left panel ── */
        .left-overlay {
            position: absolute;
            bottom: 40px;
            left: 36px;
            right: 36px;
            z-index: 2;
        }
        .left-overlay h2 {
            font-family: 'Montserrat', sans-serif;
            font-size: 1.6rem;
            font-weight: 800;
            color: #fff;
            line-height: 1.25;
            margin-bottom: 8px;
            text-shadow: 0 2px 12px rgba(0,0,0,.5);
        }
        .left-overlay h2 span { color: var(--orange); }
        .left-overlay p {
            font-size: .82rem;
            color: rgba(255,255,255,.7);
            line-height: 1.5;
            text-shadow: 0 1px 6px rgba(0,0,0,.4);
        }
        .left-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(40,120,180,.35);
            border: 1px solid rgba(40,120,180,.5);
            backdrop-filter: blur(6px);
            border-radius: 20px;
            padding: 4px 14px;
            font-size: .7rem;
            font-weight: 700;
            color: #fff;
            letter-spacing: .5px;
            text-transform: uppercase;
            margin-bottom: 12px;
        }

        .right-panel { flex: 1; display: flex; align-items: center; justify-content: center; background: var(--navy-mid); padding: 20px 32px; position: relative; overflow: hidden; }
        .right-panel::before { content: ''; position: absolute; top: -80px; right: -80px; width: 320px; height: 320px; border-radius: 50%; background: radial-gradient(circle, rgba(40,120,180,.18) 0%, transparent 70%); pointer-events: none; }
        .form-card { width: 100%; max-width: 560px; background: var(--panel-bg); border: 1px solid rgba(40,120,180,.25); border-radius: 14px; padding: 22px 36px 20px; box-shadow: 0 0 0 1px rgba(40,120,180,.08), 0 20px 60px rgba(0,0,0,.6); animation: cardIn .55s cubic-bezier(.22,1,.36,1) both; display: flex; flex-direction: column; align-items: center; text-align: center; }
        .form-card form { width: 100%; }
        @keyframes cardIn { from { opacity:0; transform:translateY(28px); } to { opacity:1; transform:translateY(0); } }
        .back-link { display: flex; align-items: center; gap: 6px; font-size: .75rem; color: var(--text-dim); text-decoration: none; margin-bottom: 8px; align-self: flex-start; transition: color .2s; }
        .back-link:hover { color: var(--blue-accent); }
        .logo-row { display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 4px; }
        .logo-icon { width: 32px; height: 32px; object-fit: contain; }
        .logo-text { font-family: 'Montserrat', sans-serif; font-size: 1.35rem; font-weight: 800; line-height: 1; }
        .logo-text .pasada { color: var(--blue-accent); } .logo-text .now { color: var(--orange); }
        .tagline { font-size: .63rem; font-weight: 700; letter-spacing: 2.5px; text-transform: uppercase; color: var(--text-muted); margin-bottom: 6px; }
        .role-badge { display: inline-flex; align-items: center; gap: 6px; background: rgba(40,120,180,.12); border: 1px solid rgba(40,120,180,.3); border-radius: 20px; padding: 3px 12px; font-size: .68rem; font-weight: 700; color: var(--blue-accent); letter-spacing: .5px; text-transform: uppercase; margin-bottom: 6px; }
        .page-title { font-family: 'Montserrat', sans-serif; font-size: 1.2rem; font-weight: 800; margin-bottom: 1px; }
        .page-sub { font-size: .78rem; color: var(--text-muted); margin-bottom: 10px; }
        .section-divider { width: 100%; display: flex; align-items: center; gap: 10px; margin: 4px 0 8px; font-size: .6rem; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--text-dim); }
        .section-divider::before, .section-divider::after { content: ''; flex: 1; height: 1px; background: var(--border); }
        .alert { width: 100%; padding: 8px 12px; border-radius: 8px; font-size: .8rem; margin-bottom: 8px; text-align: left; }
        .alert-error { background: rgba(220,50,50,.12); border: 1px solid rgba(220,50,50,.35); color: #f08080; }
        .fields-row { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; width: 100%; }
        .field-group { margin-bottom: 8px; width: 100%; }
        label { display: block; font-size: .75rem; font-weight: 700; color: var(--text-main); margin-bottom: 4px; text-align: left; }
        .input-wrap { position: relative; }
        .input-wrap input { width: 100%; background: var(--input-bg); border: 1.5px solid var(--border); border-radius: 7px; padding: 8px 12px; color: var(--text-main); font-size: .85rem; font-family: 'Inter', sans-serif; outline: none; transition: border-color .2s, box-shadow .2s; }
        .input-wrap input::placeholder { color: var(--text-dim); }
        .input-wrap input:focus { border-color: var(--border-glow); box-shadow: 0 0 0 3px rgba(40,120,180,.18); }
        .toggle-pw { position: absolute; right: 9px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--text-dim); padding: 0; display: flex; align-items: center; transition: color .2s; }
        .toggle-pw:hover { color: var(--text-muted); }
        .terms-row { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; width: 100%; }
        .terms-row input[type="checkbox"] { width: 15px; height: 15px; accent-color: var(--blue-accent); cursor: pointer; flex-shrink: 0; }
        .terms-row label { font-size: .78rem; font-weight: 400; color: var(--text-muted); margin: 0; cursor: pointer; text-align: left; }
        .terms-row a { color: var(--blue-accent); text-decoration: none; }
        .btn-submit { width: 100%; padding: 10px; background: linear-gradient(135deg, var(--blue-accent) 0%, #1a5f9a 100%); border: none; border-radius: 8px; color: #fff; font-family: 'Montserrat', sans-serif; font-size: .9rem; font-weight: 800; cursor: pointer; transition: filter .2s, transform .15s, box-shadow .2s; box-shadow: 0 4px 20px rgba(40,120,180,.4); margin-bottom: 10px; }
        .btn-submit:hover { filter: brightness(1.1); transform: translateY(-1px); box-shadow: 0 8px 28px rgba(40,120,180,.5); }
        .btn-submit:active { transform: translateY(0); }
        .signin-link { font-size: .8rem; color: var(--text-muted); text-align: center; width: 100%; }
        .signin-link a { color: var(--blue-accent); text-decoration: none; font-weight: 600; }
        .signin-link a:hover { color: var(--orange); }
        @media (max-width: 768px) { .left-panel { display: none; } .right-panel { padding: 20px 16px; } .form-card { padding: 24px 18px 22px; } .fields-row { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="page-wrapper">

    <div class="left-panel">
        <img src="../images/angkel.png" alt="PasadaNow tricycle">
        <div class="left-overlay">
            <div class="left-badge">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                Commuter
            </div>
            <h2>Ride smarter<br>with <span>PasadaNow</span></h2>
            <p>Book a tricycle in seconds. Safe, affordable, and always on time.</p>
        </div>
    </div>

    <div class="right-panel">
        <div class="form-card">

            <a href="../register.php" class="back-link">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
                Back to role selection
            </a>

            <div class="logo-row">
                <img src="../images/logo.png" alt="Logo" class="logo-icon">
                <div class="logo-text"><span class="pasada">Pasada</span><span class="now">Now</span></div>
            </div>
            <div class="tagline">Tricycle Ride Hailing System</div>

            <div class="role-badge">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                Commuter
            </div>

            <h1 class="page-title">Create Account</h1>
            <p class="page-sub">Join PasadaNow and ride today!</p>

            <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <form method="POST" autocomplete="on">
                <div class="section-divider">Personal Info</div>
                <div class="fields-row">
                    <div class="field-group">
                        <label>Full Name</label>
                        <div class="input-wrap"><input type="text" name="fullname" placeholder="Full name" value="<?= htmlspecialchars($_POST['fullname'] ?? '') ?>" required></div>
                    </div>
                    <div class="field-group">
                        <label>Phone Number</label>
                        <div class="input-wrap"><input type="tel" name="phone" placeholder="09xx-xxx-xxxx" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"></div>
                    </div>
                </div>
                <div class="field-group">
                    <label>Email Address</label>
                    <div class="input-wrap"><input type="email" name="email" placeholder="Enter your email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required></div>
                </div>

                <div class="section-divider">Account Security</div>
                <div class="fields-row">
                    <div class="field-group">
                        <label>Password</label>
                        <div class="input-wrap">
                            <input type="password" id="pw1" name="password" placeholder="Password" required>
                            <button type="button" class="toggle-pw" onclick="togglePw('pw1','e1')">
                                <svg id="e1" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                            </button>
                        </div>
                    </div>
                    <div class="field-group">
                        <label>Confirm Password</label>
                        <div class="input-wrap">
                            <input type="password" id="pw2" name="confirm_password" placeholder="Confirm" required>
                            <button type="button" class="toggle-pw" onclick="togglePw('pw2','e2')">
                                <svg id="e2" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="terms-row">
                    <input type="checkbox" id="terms" name="terms">
                    <label for="terms">I agree to the <a href="#">Terms &amp; Conditions</a></label>
                </div>

                <button type="submit" class="btn-submit">Create Commuter Account</button>
            </form>

            <p class="signin-link">Already have an account? <a href="../login.php">Sign in</a></p>
        </div>
    </div>
</div>
<script>
function togglePw(f, i) {
    const el = document.getElementById(f), ic = document.getElementById(i);
    const s = el.type === 'password'; el.type = s ? 'text' : 'password';
    ic.innerHTML = s
        ? `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>`
        : `<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/>
           <path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/>
           <line x1="1" y1="1" x2="23" y2="23"/>`;
}
</script>
</body>
</html>