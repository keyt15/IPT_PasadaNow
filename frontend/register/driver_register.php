<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../../backend/config.php';

$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username         = trim($_POST['username']         ?? '');
    $email            = trim($_POST['email']            ?? '');
    $password         = $_POST['password']              ?? '';
    $confirm          = $_POST['confirm_password']      ?? '';
    $contact_no       = trim($_POST['contact_no']       ?? '');
    $address          = trim($_POST['address']          ?? '');
    $plate_number     = trim($_POST['plate_number']     ?? '');
    $license_number   = trim($_POST['license_number']   ?? '');
    $toda_association = trim($_POST['toda_association']  ?? '');

    if ($password !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (!isset($_POST['terms'])) {
        $error = "You must agree to the Terms & Conditions.";
    } elseif (empty($username) || empty($email) || empty($plate_number) || empty($license_number)) {
        $error = "Please fill in all required fields.";
    } else {
        $chk = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        if (!$chk) { $error = "DB error (check): " . $conn->error; }
        else {
            $chk->bind_param('s', $email);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) $error = "An account with that email already exists.";
            $chk->close();
        }
    }

    if (!$error) {
        $chk0 = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        if ($chk0) {
            $chk0->bind_param('s', $username); $chk0->execute();
            if ($chk0->get_result()->num_rows > 0) $error = "That username is already taken.";
            $chk0->close();
        }
    }

    if (!$error) {
        $chk2 = $conn->prepare("SELECT id FROM users WHERE plate_number = ? LIMIT 1");
        if ($chk2) {
            $chk2->bind_param('s', $plate_number); $chk2->execute();
            if ($chk2->get_result()->num_rows > 0) $error = "That plate number is already registered.";
            $chk2->close();
        }
    }

    $profile_pic = null;
    if (!$error && !empty($_FILES['profile_img']['name'])) {
        $allowed_ext = ['jpg','jpeg','png','webp','gif'];
        $file_ext    = strtolower(pathinfo($_FILES['profile_img']['name'], PATHINFO_EXTENSION));
        if (!in_array($file_ext, $allowed_ext)) {
            $error = "Profile photo must be JPG, PNG, WEBP, or GIF.";
        } elseif ($_FILES['profile_img']['size'] > 3 * 1024 * 1024) {
            $error = "Profile photo must be under 3 MB.";
        } else {
            $target_dir = '../../frontend/images/profiles/';
            if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
            $new_fn = "driver_reg_" . time() . "_" . bin2hex(random_bytes(4)) . ".$file_ext";
            if (!move_uploaded_file($_FILES['profile_img']['tmp_name'], $target_dir . $new_fn))
                $error = "Failed to upload profile photo. Check folder permissions.";
            else
                $profile_pic = $new_fn;
        }
    }

    if (!$error) {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $role   = 'driver';
        $status = 'pending';
        $stmt = $conn->prepare(
            "INSERT INTO users (username, email, password, role, status, contact_no, address,
             plate_number, license_number, toda_association, profile_pic, is_available, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())"
        );
        if (!$stmt) { $error = "DB error (insert): " . $conn->error; }
        else {
            $stmt->bind_param('sssssssssss', $username, $email, $hashed, $role, $status,
                $contact_no, $address, $plate_number, $license_number, $toda_association, $profile_pic);
            if ($stmt->execute()) {
                header('Location: ../login.php');
                exit;
            } else {
                $error = "Registration failed: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driver Registration - PasadaNow</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&family=Inter:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --navy-deep:#0b1929; --navy-mid:#0f2236; --panel-bg:#111f30;
            --border:#1e4a72; --border-glow:#2878b4; --input-bg:#0d2035;
            --blue-accent:#2878b4; --orange:#e07820;
            --green:#22c55e; --green-dim:rgba(34,197,94,0.12);
            --text-main:#e8f0f8; --text-muted:#7a9bb8; --text-dim:#4a7090;
        }
        html, body { height:100%; background:var(--navy-deep); font-family:'Inter',sans-serif; color:var(--text-main); }
        .page-wrapper { display:flex; min-height:100vh; }

        /* Left panel */
        .left-panel { flex:0 0 48%; position:relative; overflow:hidden; }
        .left-panel img { width:100%; height:100%; object-fit:cover; object-position:center top; filter:brightness(.88) saturate(1.05); }
        .left-panel::after { content:''; position:absolute; inset:0; background:linear-gradient(to right,rgba(11,25,41,.10) 0%,rgba(11,25,41,.55) 100%); }

        .left-overlay { position:absolute; bottom:40px; left:36px; right:36px; z-index:2; }
        .left-overlay h2 { font-family:'Montserrat',sans-serif; font-size:1.6rem; font-weight:800; color:#fff; line-height:1.25; margin-bottom:8px; text-shadow:0 2px 12px rgba(0,0,0,.5); }
        .left-overlay h2 span { color:var(--orange); }
        .left-overlay p { font-size:.82rem; color:rgba(255,255,255,.7); line-height:1.5; text-shadow:0 1px 6px rgba(0,0,0,.4); }
        .left-badge { display:inline-flex; align-items:center; gap:6px; background:rgba(224,120,32,.35); border:1px solid rgba(224,120,32,.5); backdrop-filter:blur(6px); border-radius:20px; padding:4px 14px; font-size:.7rem; font-weight:700; color:#fff; letter-spacing:.5px; text-transform:uppercase; margin-bottom:12px; }

        /* Right panel */
        .right-panel { flex:1; display:flex; align-items:flex-start; justify-content:center; background:var(--navy-mid); padding:24px 32px; position:relative; overflow-y:auto; }
        .right-panel::before { content:''; position:absolute; top:-80px; right:-80px; width:320px; height:320px; border-radius:50%; background:radial-gradient(circle,rgba(40,120,180,.18) 0%,transparent 70%); pointer-events:none; }

        /* Form card */
        .form-card { width:100%; max-width:560px; background:var(--panel-bg); border:1px solid rgba(40,120,180,.25); border-radius:14px; padding:22px 36px 24px; box-shadow:0 0 0 1px rgba(40,120,180,.08),0 20px 60px rgba(0,0,0,.6); animation:cardIn .55s cubic-bezier(.22,1,.36,1) both; display:flex; flex-direction:column; align-items:center; text-align:center; margin:auto 0; }
        .form-card form { width:100%; }
        @keyframes cardIn { from{opacity:0;transform:translateY(28px)} to{opacity:1;transform:translateY(0)} }

        .back-link { display:flex; align-items:center; gap:6px; font-size:.75rem; color:var(--text-dim); text-decoration:none; margin-bottom:8px; align-self:flex-start; transition:color .2s; }
        .back-link:hover { color:var(--blue-accent); }
        .logo-row { display:flex; align-items:center; justify-content:center; gap:10px; margin-bottom:4px; }
        .logo-icon { width:32px; height:32px; object-fit:contain; }
        .logo-text { font-family:'Montserrat',sans-serif; font-size:1.35rem; font-weight:800; line-height:1; }
        .logo-text .pasada { color:var(--blue-accent); } .logo-text .now { color:var(--orange); }
        .tagline { font-size:.63rem; font-weight:700; letter-spacing:2.5px; text-transform:uppercase; color:var(--text-muted); margin-bottom:6px; }
        .role-badge { display:inline-flex; align-items:center; gap:6px; background:rgba(224,120,32,.12); border:1px solid rgba(224,120,32,.35); border-radius:20px; padding:3px 12px; font-size:.68rem; font-weight:700; color:var(--orange); letter-spacing:.5px; text-transform:uppercase; margin-bottom:6px; }
        .page-title { font-family:'Montserrat',sans-serif; font-size:1.2rem; font-weight:800; margin-bottom:1px; }
        .page-sub { font-size:.78rem; color:var(--text-muted); margin-bottom:10px; }

        .section-divider { width:100%; display:flex; align-items:center; gap:10px; margin:6px 0 8px; font-size:.6rem; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; color:var(--text-dim); }
        .section-divider::before,.section-divider::after { content:''; flex:1; height:1px; background:var(--border); }

        .alert { width:100%; padding:8px 12px; border-radius:8px; font-size:.8rem; margin-bottom:8px; text-align:left; }
        .alert-error { background:rgba(220,50,50,.12); border:1px solid rgba(220,50,50,.35); color:#f08080; }

        .fields-row { display:grid; grid-template-columns:1fr 1fr; gap:8px; width:100%; }
        .field-group { margin-bottom:8px; width:100%; }
        label { display:block; font-size:.75rem; font-weight:700; color:var(--text-main); margin-bottom:4px; text-align:left; }
        .input-wrap { position:relative; }
        .input-wrap input,.input-wrap select { width:100%; background:var(--input-bg); border:1.5px solid var(--border); border-radius:7px; padding:8px 12px; color:var(--text-main); font-size:.85rem; font-family:'Inter',sans-serif; outline:none; transition:border-color .2s,box-shadow .2s; appearance:none; }
        .input-wrap input::placeholder { color:var(--text-dim); }
        .input-wrap input:focus,.input-wrap select:focus { border-color:var(--border-glow); box-shadow:0 0 0 3px rgba(40,120,180,.18); }

        .avatar-upload { display:flex; align-items:center; gap:14px; padding:12px 14px; background:var(--input-bg); border:1.5px dashed var(--border); border-radius:7px; cursor:pointer; transition:border-color .2s; position:relative; text-align:left; width:100%; }
        .avatar-upload:hover { border-color:var(--border-glow); }
        .avatar-upload input[type="file"] { position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%; }
        .avatar-preview { width:44px; height:44px; border-radius:50%; background:linear-gradient(135deg,var(--orange),#8a4010); display:flex; align-items:center; justify-content:center; overflow:hidden; flex-shrink:0; border:2px solid rgba(224,120,32,.4); }
        .avatar-preview img { width:100%; height:100%; object-fit:cover; }
        .avatar-upload-info strong { font-size:.78rem; font-weight:600; display:block; margin-bottom:1px; color:var(--text-main); }
        .avatar-upload-info span { font-size:.68rem; color:var(--text-dim); }

        .toggle-pw { position:absolute; right:9px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; color:var(--text-dim); padding:0; display:flex; align-items:center; transition:color .2s; }
        .toggle-pw:hover { color:var(--text-muted); }
        .pw-strength { margin-top:4px; height:3px; border-radius:2px; background:var(--border); overflow:hidden; }
        .pw-strength-bar { height:100%; width:0; border-radius:2px; transition:width .3s,background .3s; }

        .terms-row { display:flex; align-items:center; gap:8px; margin-bottom:10px; width:100%; }
        .terms-row input[type="checkbox"] { width:15px; height:15px; accent-color:var(--blue-accent); cursor:pointer; flex-shrink:0; }
        .terms-row label { font-size:.78rem; font-weight:400; color:var(--text-muted); margin:0; cursor:pointer; text-align:left; }
        .terms-row a { color:var(--blue-accent); text-decoration:none; }

        .btn-submit { width:100%; padding:10px; background:linear-gradient(135deg,var(--orange) 0%,#b05a10 100%); border:none; border-radius:8px; color:#fff; font-family:'Montserrat',sans-serif; font-size:.9rem; font-weight:800; cursor:pointer; transition:filter .2s,transform .15s,box-shadow .2s; box-shadow:0 4px 20px rgba(224,120,32,.4); margin-bottom:10px; display:flex; align-items:center; justify-content:center; gap:8px; }
        .btn-submit:hover { filter:brightness(1.1); transform:translateY(-1px); box-shadow:0 8px 28px rgba(224,120,32,.5); }
        .btn-submit:active { transform:translateY(0); }
        .btn-submit:disabled { opacity:.6; cursor:not-allowed; }
        .spinner { width:13px; height:13px; border:2px solid rgba(255,255,255,.4); border-top-color:#fff; border-radius:50%; animation:spin .7s linear infinite; display:none; }
        @keyframes spin { to{transform:rotate(360deg)} }

        .signin-link { font-size:.8rem; color:var(--text-muted); text-align:center; width:100%; }
        .signin-link a { color:var(--blue-accent); text-decoration:none; font-weight:600; }
        .signin-link a:hover { color:var(--orange); }

        @media (max-width:768px) {
            .left-panel { display:none; }
            .right-panel { padding:20px 16px; }
            .form-card { padding:24px 18px 22px; }
            .fields-row { grid-template-columns:1fr; }
        }
    </style>
</head>
<body>
<div class="page-wrapper">

    <div class="left-panel">
        <img src="../images/angkel.png" alt="PasadaNow tricycle">
        <div class="left-overlay">
            <div class="left-badge">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13" rx="2"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                Driver
            </div>
            <h2>Drive with pride,<br>earn with <span>PasadaNow</span></h2>
            <p>Accept rides, grow your income, and serve your community — all in one app.</p>
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
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13" rx="2"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                Driver
            </div>

            <h1 class="page-title">Create Driver Account</h1>
            <p class="page-sub">Register to start accepting rides on PasadaNow!</p>

            <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" id="reg-form" autocomplete="on" novalidate>

                <div class="section-divider">Personal Info</div>

                <div class="field-group">
                    <label>Profile Photo <span style="color:var(--text-dim);font-weight:400;">(optional)</span></label>
                    <div class="avatar-upload" onclick="document.getElementById('profile_img').click()">
                        <input type="file" name="profile_img" id="profile_img" accept="image/*" onchange="previewAvatar(this)" onclick="event.stopPropagation()">
                        <div class="avatar-preview" id="avatarPreview">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="width:22px;height:22px;color:rgba(255,255,255,0.5)">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                            </svg>
                        </div>
                        <div class="avatar-upload-info">
                            <strong>Upload Profile Photo</strong>
                            <span>JPG, PNG or WEBP · Max 3 MB</span>
                        </div>
                    </div>
                </div>

                <div class="fields-row">
                    <div class="field-group">
                        <label>Full Name *</label>
                        <div class="input-wrap"><input type="text" name="username" placeholder="Juan dela Cruz" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required></div>
                    </div>
                    <div class="field-group">
                        <label>Contact Number *</label>
                        <div class="input-wrap"><input type="tel" name="contact_no" placeholder="09xx-xxx-xxxx" value="<?= htmlspecialchars($_POST['contact_no'] ?? '') ?>" required></div>
                    </div>
                </div>

                <div class="field-group">
                    <label>Email Address *</label>
                    <div class="input-wrap"><input type="email" name="email" placeholder="Enter your email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required></div>
                </div>

                <div class="field-group">
                    <label>Home Address</label>
                    <div class="input-wrap"><input type="text" name="address" placeholder="Barangay, City, Province" value="<?= htmlspecialchars($_POST['address'] ?? '') ?>"></div>
                </div>

                <div class="section-divider">Vehicle &amp; License</div>

                <div class="fields-row">
                    <div class="field-group">
                        <label>Plate Number *</label>
                        <div class="input-wrap"><input type="text" name="plate_number" id="plate_number" placeholder="e.g. ABC 1234" value="<?= htmlspecialchars($_POST['plate_number'] ?? '') ?>" style="text-transform:uppercase" required></div>
                    </div>
                    <div class="field-group">
                        <label>Driver's License No. *</label>
                        <div class="input-wrap"><input type="text" name="license_number" placeholder="e.g. N01-23-456789" value="<?= htmlspecialchars($_POST['license_number'] ?? '') ?>" required></div>
                    </div>
                </div>

                <div class="field-group">
                    <label>Branch / TODA / Party</label>
                    <div class="input-wrap"><input type="text" name="toda_association" placeholder="e.g. Center TODA, Session Road Terminal" value="<?= htmlspecialchars($_POST['toda_association'] ?? '') ?>"></div>
                </div>

                <div class="section-divider">Account Security</div>

                <div class="fields-row">
                    <div class="field-group">
                        <label>Password *</label>
                        <div class="input-wrap">
                            <input type="password" id="pw1" name="password" placeholder="Password" oninput="checkStrength(this.value)" required>
                            <button type="button" class="toggle-pw" onclick="togglePw('pw1','e1')">
                                <svg id="e1" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                            </button>
                        </div>
                        <div class="pw-strength"><div class="pw-strength-bar" id="pw-bar"></div></div>
                    </div>
                    <div class="field-group">
                        <label>Confirm Password *</label>
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

                <button type="submit" class="btn-submit" id="submit-btn">
                    <span class="spinner" id="btn-spinner"></span>
                    <span id="btn-label">Create Driver Account</span>
                </button>
            </form>

            <p class="signin-link">Already have an account? <a href="../login.php">Sign in</a></p>

        </div>
    </div>
</div>

<script>
function togglePw(f,i){const el=document.getElementById(f),ic=document.getElementById(i);const s=el.type==='password';el.type=s?'text':'password';ic.innerHTML=s?`<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>`:`<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>`;}
function checkStrength(val){const bar=document.getElementById('pw-bar');let s=0;if(val.length>=6)s++;if(val.length>=10)s++;if(/[A-Z]/.test(val))s++;if(/[0-9]/.test(val))s++;if(/[^A-Za-z0-9]/.test(val))s++;bar.style.width=(s/5*100)+'%';bar.style.background=s<=1?'#dc3232':s<=3?'#e07820':'#22c55e';}
function previewAvatar(input){if(!input.files||!input.files[0])return;const r=new FileReader();r.onload=e=>{document.getElementById('avatarPreview').innerHTML=`<img src="${e.target.result}" alt="">`;};r.readAsDataURL(input.files[0]);}
document.getElementById('reg-form')?.addEventListener('submit',function(e){const pw=document.getElementById('pw1').value,cpw=document.getElementById('pw2').value;if(pw!==cpw){e.preventDefault();alert('Passwords do not match.');return;}document.getElementById('btn-spinner').style.display='block';document.getElementById('btn-label').textContent='Registering...';document.getElementById('submit-btn').disabled=true;});
document.getElementById('plate_number')?.addEventListener('input',function(){this.value=this.value.toUpperCase();});
</script>
</body>
</html>