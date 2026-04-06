<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once '../backend/config.php';

$error = ''; $success = '';

// ── Forgot Password Handler ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['forgot_email'])) {
    $forgot_email = trim($_POST['forgot_email'] ?? '');
    $forgot_msg   = '';
    $forgot_error = '';

    if (!$forgot_email || !filter_var($forgot_email, FILTER_VALIDATE_EMAIL)) {
        $forgot_error = 'Please enter a valid email address.';
    } else {
        $stmt = $conn->prepare("SELECT id, username FROM users WHERE email = ?");
        $stmt->bind_param("s", $forgot_email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($user = $result->fetch_assoc()) {
            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $stmt2 = $conn->prepare(
                "INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)"
            );
            $stmt2->bind_param("iss", $user['id'], $token, $expires);

            if ($stmt2->execute()) {
                $reset_link = "http://{$_SERVER['HTTP_HOST']}/week1/frontend/reset-password.php?token={$token}";

                $to      = $forgot_email;
                $subject = "PasadaNow – Reset Your Password";
                $body    = "Hello {$user['username']},\n\n"
                         . "We received a request to reset your PasadaNow password.\n"
                         . "Click the link below to set a new password (expires in 1 hour):\n\n"
                         . $reset_link . "\n\n"
                         . "If you did not request this, please ignore this email.\n\n"
                         . "— PasadaNow Team";
                $headers = "From: no-reply@pasadanow.local\r\n"
                         . "Reply-To: no-reply@pasadanow.local\r\n"
                         . "X-Mailer: PHP/" . phpversion();

                mail($to, $subject, $body, $headers);
                $forgot_msg = "If that email is registered, a reset link has been sent. Check your inbox.";
            } else {
                $forgot_error = "Something went wrong. Please try again.";
            }
        } else {
            $forgot_msg = "If that email is registered, a reset link has been sent. Check your inbox.";
        }
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success' => empty($forgot_error),
        'message' => $forgot_error ?: $forgot_msg,
    ]);
    exit();
}

// ── Login Handler ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($user = $result->fetch_assoc()) {
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id']  = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = $user['role'];

            switch ($_SESSION['role']) {
                case 'admin':
                    header("Location: dashboard/admin_dashboard.php");
                    break;
                case 'driver':
                    header("Location: dashboard/driver_dashboard.php");
                    break;
                case 'commuter':
                    header("Location: dashboard/commuter_dashboard.php");
                    break;
                default:
                    header("Location: login.php?error=invalid_role");
                    break;
            }
            exit();
        } else {
            $error = "Invalid password.";
        }
    } else {
        $error = "User not found.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - PasadaNow</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&family=Inter:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --navy-deep:   #0b1929;
            --navy-mid:    #0f2236;
            --panel-bg:    #111f30;
            --panel-dark:  #0d1a27;
            --border:      #1e4a72;
            --border-glow: #2878b4;
            --input-bg:    #0d2035;
            --blue-accent: #2878b4;
            --orange:      #e07820;
            --orange-lit:  #f08c30;
            --text-main:   #e8f0f8;
            --text-muted:  #7a9bb8;
            --text-dim:    #4a7090;
        }

        html, body {
            height: 100%;
            overflow: hidden;
            background: var(--navy-deep);
            font-family: 'Inter', sans-serif;
            color: var(--text-main);
        }

        .page-wrapper {
            display: flex;
            height: 100vh;
            overflow: hidden;
        }

        .left-panel {
            flex: 0 0 48%;
            position: relative;
            overflow: hidden;
        }

        .left-panel img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center top;
            display: block;
            filter: brightness(0.88) saturate(1.05);
        }

        .left-panel::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(to right, rgba(11,25,41,0.10) 0%, rgba(11,25,41,0.55) 100%);
        }

        .right-panel {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--navy-mid);
            padding: 40px 32px;
            position: relative;
            overflow: hidden;
        }

        .right-panel::before {
            content: '';
            position: absolute;
            top: -80px; right: -80px;
            width: 320px; height: 320px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(40,120,180,0.18) 0%, transparent 70%);
            pointer-events: none;
        }

        .form-card {
            width: 100%;
            max-width: 480px;
            background: var(--panel-bg);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 44px 44px 36px;
            box-shadow: 0 0 0 1px rgba(40,120,180,0.12), 0 24px 64px rgba(0,0,0,0.5), inset 0 1px 0 rgba(255,255,255,0.04);
            animation: cardIn 0.55s cubic-bezier(.22,1,.36,1) both;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }

        @keyframes cardIn {
            from { opacity: 0; transform: translateY(28px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .form-card form, .social-row, .row-options, .register-link, .divider { width: 100%; }

        .logo-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 6px;
        }

        .logo-icon { width: 48px; height: 48px; flex-shrink: 0; }

        .logo-text {
            font-family: 'Montserrat', sans-serif;
            font-size: 2rem;
            font-weight: 800;
            line-height: 1;
            letter-spacing: -0.5px;
        }
        .logo-text .pasada { color: var(--blue-accent); }
        .logo-text .now    { color: var(--orange); }

        .tagline {
            font-size: 0.72rem;
            font-weight: 600;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 28px;
        }

        .welcome {
            font-family: 'Montserrat', sans-serif;
            font-size: 1.85rem;
            font-weight: 800;
            font-style: italic;
            color: var(--text-main);
            margin-bottom: 4px;
        }

        .sub-welcome {
            font-size: 0.88rem;
            font-style: italic;
            color: var(--text-muted);
            margin-bottom: 30px;
        }

        .alert {
            width: 100%;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 0.85rem;
            margin-bottom: 18px;
            text-align: left;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .alert-error   { background: rgba(220,50,50,0.12);  border: 1px solid rgba(220,50,50,0.35);  color: #f08080; }
        .alert-success { background: rgba(40,160,90,0.12);  border: 1px solid rgba(40,160,90,0.35);  color: #6edda0; }

        .field-group {
            margin-bottom: 16px;
            width: 100%;
            position: relative;
        }

        label {
            display: block;
            font-size: 0.82rem;
            font-weight: 700;
            font-style: italic;
            color: var(--text-main);
            margin-bottom: 8px;
            letter-spacing: 0.3px;
            text-align: left;
        }

        .input-wrap { position: relative; }

        .input-wrap input {
            width: 100%;
            background: var(--input-bg);
            border: 1.5px solid var(--border);
            border-radius: 8px;
            padding: 13px 44px 13px 16px;
            color: var(--text-main);
            font-size: 0.92rem;
            font-family: 'Inter', sans-serif;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .input-wrap input::placeholder { color: var(--text-dim); }

        .input-wrap input:focus {
            border-color: var(--border-glow);
            box-shadow: 0 0 0 3px rgba(40,120,180,0.18);
        }

        .input-wrap input.error {
            border-color: #ea5455 !important;
            box-shadow: 0 0 0 3px rgba(234,84,85,0.18) !important;
        }

        .input-wrap input.success {
            border-color: #28c76f !important;
            box-shadow: 0 0 0 3px rgba(40,199,111,0.18) !important;
        }

        .field-error {
            display: none;
            align-items: center;
            gap: 6px;
            margin-top: 6px;
            font-size: 0.76rem;
            color: #ea5455;
            font-weight: 600;
            text-align: left;
            animation: shakeIn 0.3s ease;
        }

        .field-error.show { display: flex; }

        @keyframes shakeIn {
            0%   { transform: translateX(0); }
            25%  { transform: translateX(-6px); }
            50%  { transform: translateX(6px); }
            75%  { transform: translateX(-4px); }
            100% { transform: translateX(0); }
        }

        .input-icon {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-dim);
            pointer-events: none;
            display: flex;
        }

        .toggle-pw {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-dim);
            padding: 0;
            display: flex;
            align-items: center;
            transition: color 0.2s;
        }
        .toggle-pw:hover { color: var(--text-muted); }

        .row-options {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 28px;
        }

        .remember-label {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            font-size: 0.85rem;
            color: var(--text-muted);
            user-select: none;
        }

        .remember-label input[type="checkbox"] {
            width: 16px; height: 16px;
            accent-color: var(--blue-accent);
            cursor: pointer;
        }

        .forgot-link {
            font-size: 0.85rem;
            font-style: italic;
            color: var(--blue-accent);
            text-decoration: none;
            background: none;
            border: none;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            transition: color 0.2s;
        }
        .forgot-link:hover { color: var(--orange); }

        .btn-signin {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, var(--blue-accent) 0%, #1a5f9a 100%);
            border: none;
            border-radius: 8px;
            color: #fff;
            font-family: 'Montserrat', sans-serif;
            font-size: 1rem;
            font-weight: 800;
            font-style: italic;
            letter-spacing: 0.5px;
            cursor: pointer;
            transition: filter 0.2s, transform 0.15s, box-shadow 0.2s;
            box-shadow: 0 4px 20px rgba(40,120,180,0.4);
        }
        .btn-signin:hover {
            filter: brightness(1.1);
            transform: translateY(-1px);
            box-shadow: 0 8px 28px rgba(40,120,180,0.5);
        }
        .btn-signin:active { transform: translateY(0); }
        .btn-signin:disabled { opacity: 0.7; cursor: not-allowed; transform: none; }

        .divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 22px 0;
            color: var(--text-dim);
            font-size: 0.75rem;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }
        .divider::before, .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        .social-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 24px;
        }

        .btn-social {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px 16px;
            background: transparent;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            color: var(--text-main);
            font-family: 'Montserrat', sans-serif;
            font-size: 0.85rem;
            font-weight: 700;
            font-style: italic;
            cursor: pointer;
            transition: border-color 0.2s, background 0.2s;
        }
        .btn-social:hover {
            background: rgba(40,120,180,0.08);
            border-color: var(--border-glow);
        }

        .fb-icon { color: #1877f2; }
        .g-icon  { width: 18px; height: 18px; }

        .register-link {
            text-align: center;
            font-size: 0.84rem;
            font-style: italic;
            color: var(--text-muted);
        }
        .register-link a {
            color: var(--blue-accent);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s;
        }
        .register-link a:hover { color: var(--orange); }

        .form-card:hover {
            border-color: rgba(40,120,180,0.5);
            transition: border-color 0.4s;
        }

        .popup-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            animation: fadeIn 0.3s ease;
        }

        .popup-box {
            background: var(--panel-bg);
            border: 1px solid rgba(224,120,32,0.4);
            border-radius: 16px;
            padding: 40px 36px;
            max-width: 380px;
            width: 90%;
            text-align: center;
            box-shadow: 0 0 0 1px rgba(224,120,32,0.15), 0 24px 60px rgba(0,0,0,0.6);
            animation: popIn 0.4s cubic-bezier(.22,1,.36,1);
        }

        .popup-box.invalid {
            border: 1px solid rgba(220,50,50,0.4);
            box-shadow: 0 0 0 1px rgba(220,50,50,0.15), 0 24px 60px rgba(0,0,0,0.6);
        }
        .popup-box.forgot-box {
            border: 1px solid rgba(40,120,180,0.4);
            box-shadow: 0 0 0 1px rgba(40,120,180,0.12), 0 24px 60px rgba(0,0,0,0.6);
        }

        .popup-icon { font-size: 3.5rem; margin-bottom: 16px; }

        .popup-title {
            font-family: 'Montserrat', sans-serif;
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--orange);
            margin-bottom: 10px;
        }

        .popup-title.invalid { color: #f08080; }
        .popup-title.blue    { color: var(--blue-accent); }

        .popup-msg {
            font-size: 0.88rem;
            color: var(--text-muted);
            margin-bottom: 28px;
            line-height: 1.7;
        }

        .popup-btn {
            padding: 11px 36px;
            background: linear-gradient(135deg, var(--orange) 0%, #b85e10 100%);
            border: none;
            border-radius: 8px;
            color: #fff;
            font-family: 'Montserrat', sans-serif;
            font-size: 0.9rem;
            font-weight: 800;
            cursor: pointer;
            transition: filter 0.2s, transform 0.15s;
            box-shadow: 0 4px 20px rgba(224,120,32,0.4);
        }
        .popup-btn.invalid {
            background: linear-gradient(135deg, #dc3232 0%, #8b1a1a 100%);
            box-shadow: 0 4px 20px rgba(220,50,50,0.4);
        }
        .popup-btn.blue {
            background: linear-gradient(135deg, var(--blue-accent) 0%, #1a5f9a 100%);
            box-shadow: 0 4px 20px rgba(40,120,180,0.4);
        }
        .popup-btn:hover { filter: brightness(1.1); transform: translateY(-1px); }
        .popup-btn:disabled { opacity: 0.7; cursor: not-allowed; transform: none; filter: none; }

        .forgot-form-wrap { width: 100%; margin-bottom: 20px; }

        .forgot-form-wrap .input-wrap input {
            text-align: left;
            padding: 12px 44px 12px 16px;
        }

        .forgot-alert {
            display: none;
            padding: 9px 13px;
            border-radius: 8px;
            font-size: 0.82rem;
            margin-bottom: 16px;
            text-align: left;
            align-items: center;
            gap: 8px;
        }
        .forgot-alert.show { display: flex; }
        .forgot-alert.err  { background: rgba(220,50,50,0.12); border: 1px solid rgba(220,50,50,0.35); color: #f08080; }
        .forgot-alert.ok   { background: rgba(40,160,90,0.12); border: 1px solid rgba(40,160,90,0.35); color: #6edda0; }

        .forgot-back {
            display: inline-block;
            margin-top: 14px;
            font-size: 0.83rem;
            color: var(--text-dim);
            cursor: pointer;
            background: none;
            border: none;
            font-family: 'Inter', sans-serif;
            transition: color 0.2s;
        }
        .forgot-back:hover { color: var(--blue-accent); }

        @keyframes fadeIn  { from { opacity: 0; } to { opacity: 1; } }
        @keyframes fadeOut { from { opacity: 1; } to { opacity: 0; } }
        @keyframes popIn {
            from { opacity: 0; transform: scale(0.85) translateY(20px); }
            to   { opacity: 1; transform: scale(1) translateY(0); }
        }

        @media (max-width: 768px) {
            .left-panel { display: none; }
            .right-panel { padding: 24px 16px; }
            .form-card { padding: 32px 24px 28px; }
        }
    </style>
</head>
<body>
<div class="page-wrapper">

    <div class="left-panel">
        <img src="images/angkel.png" alt="PasadaNow tricycle driver">
    </div>

    <div class="right-panel">
        <div class="form-card">

            <div class="logo-row">
                <img src="images/logo.png" alt="PasadaNow Logo" class="logo-icon">
                <div class="logo-text">
                    <span class="pasada">Pasada</span><span class="now">Now</span>
                </div>
            </div>
            <div class="tagline">Tricycle Ride Hailing System</div>

            <h1 class="welcome">Welcome Back!</h1>
            <p class="sub-welcome">Sign in to continue your journey</p>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>

            <form method="POST" autocomplete="on" id="loginForm" novalidate>

                <div class="field-group">
                    <label for="email">Email Address</label>
                    <div class="input-wrap">
                        <input type="email" id="email" name="email"
                               placeholder="Enter your email"
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        <span class="input-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="2" y="4" width="20" height="16" rx="2"/>
                                <path d="M2 7l10 7 10-7"/>
                            </svg>
                        </span>
                    </div>
                    <div class="field-error" id="email-error">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        <span id="email-error-msg">Please enter your email address.</span>
                    </div>
                </div>

                <div class="field-group">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <input type="password" id="password" name="password"
                               placeholder="Enter your password">
                        <button type="button" class="toggle-pw" onclick="togglePassword()" aria-label="Toggle password visibility">
                            <svg id="eye-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/>
                                <path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/>
                                <line x1="1" y1="1" x2="23" y2="23"/>
                            </svg>
                        </button>
                    </div>
                    <div class="field-error" id="password-error">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        <span id="password-error-msg">Please enter your password.</span>
                    </div>
                </div>

                <div class="row-options">
                    <label class="remember-label">
                        <input type="checkbox" name="remember"> Remember me
                    </label>
                    <button type="button" class="forgot-link" onclick="openForgotPopup()">Forgot Password?</button>
                </div>

                <button type="submit" class="btn-signin">Sign In</button>
            </form>

            <div class="divider">or continue with</div>

            <div class="social-row">
                <button class="btn-social" onclick="alert('Facebook login coming soon')">
                    <svg class="fb-icon" width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z"/>
                    </svg>
                    Facebook
                </button>
                <button class="btn-social" onclick="alert('Google login coming soon')">
                    <svg class="g-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                        <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                        <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                        <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                    </svg>
                    Google
                </button>
            </div>

            <p class="register-link">
                Don't have an account? <a href="register.php">Sign Up</a>
            </p>

        </div>
    </div>
</div>

<!-- FORGOT PASSWORD MODAL -->
<div class="popup-overlay" id="forgotPopup" style="display:none;">
    <div class="popup-box forgot-box" id="forgotBox">

        <div id="forgot-step1">
            <div class="popup-icon">🔑</div>
            <div class="popup-title blue">Forgot Password?</div>
            <p class="popup-msg">No worries! Enter your registered email and we'll send you a reset link.</p>

            <div class="forgot-alert" id="forgot-alert">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span id="forgot-alert-msg"></span>
            </div>

            <div class="forgot-form-wrap">
                <div class="input-wrap">
                    <input type="email" id="forgot-email-input" placeholder="Enter your email address" style="text-align:left;">
                    <span class="input-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="2" y="4" width="20" height="16" rx="2"/>
                            <path d="M2 7l10 7 10-7"/>
                        </svg>
                    </span>
                </div>
            </div>

            <button class="popup-btn blue" id="forgot-send-btn" onclick="sendResetLink()">Send Reset Link</button>
            <br>
            <button class="forgot-back" onclick="closeForgotPopup()">← Back to Sign In</button>
        </div>

        <div id="forgot-step2" style="display:none;">
            <div class="popup-icon">📧</div>
            <div class="popup-title blue">Check Your Inbox!</div>
            <p class="popup-msg" id="forgot-success-msg">
                A password reset link has been sent.<br>
                Please check your email and follow the instructions.
            </p>
            <button class="popup-btn blue" onclick="closeForgotPopup()">OK, Got It</button>
        </div>

    </div>
</div>

<?php if (isset($_GET['error']) && $_GET['error'] === 'unauthorized'): ?>
<div class="popup-overlay" id="unauthorizedPopup">
    <div class="popup-box">
        <div class="popup-icon">🔒</div>
        <div class="popup-title">Unauthorized Access</div>
        <p class="popup-msg">
            You must be logged in to access that page.<br>
            Please sign in to continue your journey.
        </p>
        <button class="popup-btn" onclick="closePopup('unauthorizedPopup')">OK, Got It</button>
    </div>
</div>
<?php endif; ?>

<?php if (isset($_GET['error']) && $_GET['error'] === 'invalid_role'): ?>
<div class="popup-overlay" id="invalidRolePopup">
    <div class="popup-box invalid">
        <div class="popup-icon">⚠️</div>
        <div class="popup-title invalid">Invalid Role</div>
        <p class="popup-msg">
            Your account role is not recognized.<br>
            Please contact the system administrator.
        </p>
        <button class="popup-btn invalid" onclick="closePopup('invalidRolePopup')">OK, Got It</button>
    </div>
</div>
<?php endif; ?>

<script>
// ── FIX: Strip error params from URL immediately on page load ──
// This prevents the popup from re-appearing when the user refreshes the page.
(function() {
    if (window.location.search.includes('error=')) {
        window.history.replaceState({}, document.title, window.location.pathname);
    }
})();

function togglePassword() {
    const pw   = document.getElementById('password');
    const icon = document.getElementById('eye-icon');
    const isHidden = pw.type === 'password';
    pw.type = isHidden ? 'text' : 'password';
    icon.innerHTML = isHidden
        ? `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>`
        : `<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/>
           <path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/>
           <line x1="1" y1="1" x2="23" y2="23"/>`;
}

function showError(inputId, errorId, message) {
    const input = document.getElementById(inputId);
    const error = document.getElementById(errorId);
    const msg   = document.getElementById(errorId + '-msg');
    input.classList.add('error');
    input.classList.remove('success');
    msg.textContent = message;
    error.classList.add('show');
}

function clearError(inputId, errorId) {
    const input = document.getElementById(inputId);
    const error = document.getElementById(errorId);
    input.classList.remove('error');
    input.classList.add('success');
    error.classList.remove('show');
}

document.getElementById('email').addEventListener('input', function() {
    const val = this.value.trim();
    if (!val) {
        showError('email', 'email-error', 'Please enter your email address.');
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) {
        showError('email', 'email-error', 'Please enter a valid email address.');
    } else {
        clearError('email', 'email-error');
    }
});

document.getElementById('password').addEventListener('input', function() {
    const val = this.value;
    if (!val) {
        showError('password', 'password-error', 'Please enter your password.');
    } else if (val.length < 6) {
        showError('password', 'password-error', 'Password must be at least 6 characters.');
    } else {
        clearError('password', 'password-error');
    }
});

document.getElementById('loginForm').addEventListener('submit', function(e) {
    let valid = true;
    const email    = document.getElementById('email').value.trim();
    const password = document.getElementById('password').value;

    if (!email) {
        showError('email', 'email-error', 'Please enter your email address.');
        valid = false;
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        showError('email', 'email-error', 'Please enter a valid email address.');
        valid = false;
    } else {
        clearError('email', 'email-error');
    }

    if (!password) {
        showError('password', 'password-error', 'Please enter your password.');
        valid = false;
    } else if (password.length < 6) {
        showError('password', 'password-error', 'Password must be at least 6 characters.');
        valid = false;
    } else {
        clearError('password', 'password-error');
    }

    if (!valid) e.preventDefault();
});

function closePopup(id) {
    const overlay = document.getElementById(id);
    overlay.style.animation = 'fadeOut 0.3s ease forwards';
    setTimeout(() => overlay.remove(), 300);
    // Also clean the URL just in case it wasn't cleaned on load
    window.history.replaceState({}, document.title, window.location.pathname);
}

document.querySelectorAll('.popup-overlay').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) closePopup(this.id);
    });
});

function openForgotPopup() {
    const popup = document.getElementById('forgotPopup');
    document.getElementById('forgot-step1').style.display = '';
    document.getElementById('forgot-step2').style.display = 'none';
    document.getElementById('forgot-email-input').value = '';
    hideForgotAlert();
    popup.style.display = 'flex';
    const box = document.getElementById('forgotBox');
    box.style.animation = 'none';
    box.offsetHeight;
    box.style.animation = 'popIn 0.4s cubic-bezier(.22,1,.36,1)';
    setTimeout(() => document.getElementById('forgot-email-input').focus(), 100);
}

function closeForgotPopup() {
    const popup = document.getElementById('forgotPopup');
    popup.style.animation = 'fadeOut 0.3s ease forwards';
    setTimeout(() => {
        popup.style.display = 'none';
        popup.style.animation = '';
    }, 300);
}

function showForgotAlert(msg, type) {
    const el  = document.getElementById('forgot-alert');
    const txt = document.getElementById('forgot-alert-msg');
    el.className = 'forgot-alert show ' + type;
    txt.textContent = msg;
}

function hideForgotAlert() {
    document.getElementById('forgot-alert').className = 'forgot-alert';
}

document.getElementById('forgot-email-input').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') sendResetLink();
});

async function sendResetLink() {
    const emailInput = document.getElementById('forgot-email-input');
    const btn        = document.getElementById('forgot-send-btn');
    const email      = emailInput.value.trim();

    if (!email) {
        showForgotAlert('Please enter your email address.', 'err');
        emailInput.classList.add('error');
        return;
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        showForgotAlert('Please enter a valid email address.', 'err');
        emailInput.classList.add('error');
        return;
    }

    emailInput.classList.remove('error');
    hideForgotAlert();

    btn.disabled    = true;
    btn.textContent = 'Sending…';

    try {
        const formData = new FormData();
        formData.append('forgot_email', email);

        const res  = await fetch('login.php', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.success) {
            document.getElementById('forgot-success-msg').textContent = data.message;
            document.getElementById('forgot-step1').style.display = 'none';
            document.getElementById('forgot-step2').style.display = '';
        } else {
            showForgotAlert(data.message, 'err');
        }
    } catch (err) {
        showForgotAlert('Network error. Please try again.', 'err');
    } finally {
        btn.disabled    = false;
        btn.textContent = 'Send Reset Link';
    }
}

document.getElementById('forgotPopup').addEventListener('click', function(e) {
    if (e.target === this) closeForgotPopup();
});
</script>
</body>
</html>