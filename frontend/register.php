<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - PasadaNow</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&family=Inter:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --navy-deep:   #0b1929;
            --navy-mid:    #0f2236;
            --panel-bg:    #111f30;
            --border:      #1e4a72;
            --blue-accent: #2878b4;
            --orange:      #e07820;
            --text-main:   #e8f0f8;
            --text-muted:  #7a9bb8;
            --text-dim:    #4a7090;
        }
        html, body { height: 100%; overflow: hidden; background: var(--navy-deep); font-family: 'Inter', sans-serif; color: var(--text-main); }
        .page-wrapper { display: flex; height: 100vh; overflow: hidden; }
        .left-panel { flex: 0 0 48%; position: relative; overflow: hidden; }
        .left-panel img { width: 100%; height: 100%; object-fit: cover; object-position: center top; filter: brightness(0.88) saturate(1.05); }
        .left-panel::after { content: ''; position: absolute; inset: 0; background: linear-gradient(to right, rgba(11,25,41,0.10) 0%, rgba(11,25,41,0.55) 100%); }
        .right-panel { flex: 1; display: flex; align-items: center; justify-content: center; background: var(--navy-mid); padding: 40px 32px; position: relative; overflow: hidden; }
        .right-panel::before { content: ''; position: absolute; top: -80px; right: -80px; width: 320px; height: 320px; border-radius: 50%; background: radial-gradient(circle, rgba(40,120,180,0.18) 0%, transparent 70%); pointer-events: none; }
        .selector-card { width: 100%; max-width: 460px; background: var(--panel-bg); border: 1px solid var(--border); border-radius: 16px; padding: 40px 44px 36px; box-shadow: 0 0 0 1px rgba(40,120,180,0.12), 0 24px 64px rgba(0,0,0,0.5), inset 0 1px 0 rgba(255,255,255,0.04); animation: cardIn 0.55s cubic-bezier(.22,1,.36,1) both; display: flex; flex-direction: column; align-items: center; text-align: center; }
        @keyframes cardIn { from { opacity: 0; transform: translateY(24px); } to { opacity: 1; transform: translateY(0); } }
        .logo-row { display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 4px; }
        .logo-icon { width: 42px; height: 42px; object-fit: contain; }
        .logo-text { font-family: 'Montserrat', sans-serif; font-size: 1.85rem; font-weight: 800; line-height: 1; }
        .logo-text .pasada { color: var(--blue-accent); }
        .logo-text .now    { color: var(--orange); }
        .tagline { font-size: 0.68rem; font-weight: 700; letter-spacing: 2.5px; text-transform: uppercase; color: var(--text-muted); margin-bottom: 28px; }
        .page-title { font-family: 'Montserrat', sans-serif; font-size: 1.5rem; font-weight: 800; margin-bottom: 6px; }
        .page-sub { font-size: 0.84rem; color: var(--text-muted); margin-bottom: 32px; }
        .roles-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; width: 100%; margin-bottom: 28px; }
        .role-card { display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 22px 12px; background: var(--navy-mid); border: 2px solid var(--border); border-radius: 12px; cursor: pointer; text-decoration: none; color: var(--text-muted); transition: all 0.22s; position: relative; overflow: hidden; }
        .role-card::before { content: ''; position: absolute; inset: 0; opacity: 0; transition: opacity 0.22s; }
        .role-card.commuter::before { background: radial-gradient(circle at 50% 0%, rgba(40,120,180,0.2), transparent 70%); }
        .role-card.driver::before   { background: radial-gradient(circle at 50% 0%, rgba(224,120,32,0.2),  transparent 70%); }
        .role-card.admin::before    { background: radial-gradient(circle at 50% 0%, rgba(40,199,111,0.18), transparent 70%); }
        .role-card:hover { color: var(--text-main); transform: translateY(-4px); }
        .role-card:hover::before { opacity: 1; }
        .role-card.commuter:hover { border-color: var(--blue-accent); box-shadow: 0 8px 24px rgba(40,120,180,0.25); }
        .role-card.driver:hover   { border-color: var(--orange);      box-shadow: 0 8px 24px rgba(224,120,32,0.25); }
        .role-card.admin:hover    { border-color: #28c76f;             box-shadow: 0 8px 24px rgba(40,199,111,0.2); }
        .role-icon { width: 52px; height: 52px; border-radius: 14px; display: flex; align-items: center; justify-content: center; position: relative; z-index: 1; }
        .role-card.commuter .role-icon { background: rgba(40,120,180,0.15); color: var(--blue-accent); }
        .role-card.driver   .role-icon { background: rgba(224,120,32,0.15); color: var(--orange); }
        .role-card.admin    .role-icon { background: rgba(40,199,111,0.15); color: #28c76f; }
        .role-name { font-family: 'Montserrat', sans-serif; font-size: 0.85rem; font-weight: 700; letter-spacing: 0.3px; position: relative; z-index: 1; }
        .role-desc { font-size: 0.69rem; color: var(--text-dim); line-height: 1.45; text-align: center; position: relative; z-index: 1; }
        .signin-link { font-size: 0.84rem; color: var(--text-muted); }
        .signin-link a { color: var(--blue-accent); text-decoration: none; font-weight: 600; transition: color 0.2s; }
        .signin-link a:hover { color: var(--orange); }
        @media (max-width: 768px) { .left-panel { display: none; } .right-panel { padding: 24px 16px; } .selector-card { padding: 28px 20px 24px; } .roles-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="page-wrapper">
    <div class="left-panel">
        <img src="images/angkel.png" alt="PasadaNow">
        <img src="images/trike.png" alt="PasadaNow">
    </div>
    <div class="right-panel">
        <div class="selector-card">
            <div class="logo-row">
                <img src="images/logo.png" alt="PasadaNow Logo" class="logo-icon">
                <div class="logo-text">
                    <span class="pasada">Pasada</span><span class="now">Now</span>
                </div>
            </div>
            <div class="tagline">Tricycle Ride Hailing System</div>
            <h1 class="page-title">Create Account</h1>
            <p class="page-sub">Who are you registering as?</p>
            <div class="roles-grid">
                <a href="register/commuter_register.php" class="role-card commuter">
                    <div class="role-icon">
                        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                    </div>
                    <div class="role-name">Commuter</div>
                    <div class="role-desc">Book tricycle rides around the city</div>
                </a>
                <a href="register/driver_register.php" class="role-card driver">
                    <div class="role-icon">
                        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="4.5" cy="17.5" r="2.5"/>
                            <circle cx="14.5" cy="17.5" r="2.5"/>
                            <circle cx="20.5" cy="17.5" r="1.5"/>
                            <path d="M7 17.5h5"/>
                            <path d="M4.5 15V10l2-5h6l2 4 3 1v5.5"/>
                            <path d="M17 10h3l1 2"/>
                        </svg>
                    </div>
                    <div class="role-name">Driver</div>
                    <div class="role-desc">Register your tricycle &amp; earn</div>
                </a>
                <a href="register/admin_register.php" class="role-card admin">
                    <div class="role-icon">
                        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                    </div>
                    <div class="role-name">Admin</div>
                    <div class="role-desc">Manage the PasadaNow system</div>
                </a>
            </div>
            <p class="signin-link">Already have an account? <a href="login.php">Sign in</a></p>
        </div>
    </div>
</div>
</body>
</html>