<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'commuter') {
    header("Location: ../login.php?error=unauthorized");
    exit();
}
require_once '../../backend/config.php';

$user_id = $_SESSION['user_id'];

/* ── AJAX: Update profile ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_update_profile'])) {
    header('Content-Type: application/json');

    $fullname = trim($conn->real_escape_string($_POST['fullname'] ?? ''));
    $email    = trim($conn->real_escape_string($_POST['email']    ?? ''));
    $phone    = trim($conn->real_escape_string($_POST['contact']  ?? ''));
    $address  = trim($conn->real_escape_string($_POST['address']  ?? ''));
    $new_pass = $_POST['new_password'] ?? '';

    $pass_sql = '';
    if (!empty($new_pass)) {
        $hashed  = $conn->real_escape_string(password_hash($new_pass, PASSWORD_DEFAULT));
        $pass_sql = ", password='$hashed'";
    }

    $pic_sql = '';
    if (!empty($_FILES['profile_pic']['name'])) {
        $target_dir = "../images/profiles/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
        $ext     = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif','webp'];
        if (in_array($ext, $allowed)) {
            $new_fn = "commuter_{$user_id}_" . time() . ".$ext";
            if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $target_dir . $new_fn)) {
                $pic_sql = ", profile_pic='$new_fn'";
            }
        }
    }

    $ok = $conn->query(
        "UPDATE users SET username='$fullname', email='$email', phone='$phone',
         address='$address' $pass_sql $pic_sql WHERE id=$user_id"
    );

    if ($ok) {
        $s = $conn->prepare("SELECT username, email, phone, address, profile_pic FROM users WHERE id=?");
        $s->bind_param('i', $user_id);
        $s->execute();
        $updated = $s->get_result()->fetch_assoc();
        $s->close();
        echo json_encode([
            'success'     => true,
            'username'    => $updated['username'],
            'email'       => $updated['email'],
            'phone'       => $updated['phone'],
            'address'     => $updated['address'],
            'profile_pic' => $updated['profile_pic']
                ? '../images/profiles/' . $updated['profile_pic']
                : null
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => $conn->error]);
    }
    exit();
}

/* ── AJAX: Poll booking status ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'poll_booking') {
    header('Content-Type: application/json');

    $booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;

    if ($booking_id > 0) {
        /*
         * FIX: Poll a specific booking by ID only.
         * This prevents stale completed bookings from ever being returned.
         */
        $stmt = $conn->prepare(
            "SELECT b.id, b.status, b.fare, b.origin, b.destination,
                    u.username AS driver_name, u.plate_number, u.contact_no
             FROM bookings b
             JOIN users u ON b.driver_id = u.id
             WHERE b.commuter_id = ? AND b.id = ?"
        );
        $stmt->bind_param('ii', $user_id, $booking_id);
    } else {
        /*
         * FIX: Fallback ONLY returns active bookings — never 'completed' or 'cancelled'.
         * This prevents old finished trips from showing up as the "current" booking.
         */
        $stmt = $conn->prepare(
            "SELECT b.id, b.status, b.fare, b.origin, b.destination,
                    u.username AS driver_name, u.plate_number, u.contact_no
             FROM bookings b
             JOIN users u ON b.driver_id = u.id
             WHERE b.commuter_id = ? AND b.status IN ('pending','accepted','ongoing')
             ORDER BY b.created_at DESC LIMIT 1"
        );
        $stmt->bind_param('i', $user_id);
    }
    $stmt->execute();
    echo json_encode($stmt->get_result()->fetch_assoc());
    exit();
}

/* ── AJAX: Cancel booking ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'cancel_booking') {
    header('Content-Type: application/json');
    $stmt = $conn->prepare(
        "UPDATE bookings SET status='cancelled'
         WHERE commuter_id = ? AND status IN ('pending','accepted')"
    );
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    echo json_encode(['success' => true]);
    exit();
}

/* ── AJAX: Submit booking ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_book'])) {
    header('Content-Type: application/json');
    $origin      = trim($_POST['origin']);
    $destination = trim($_POST['destination']);
    $fare        = floatval($_POST['fare']);
    $driver_id   = intval($_POST['driver_id']);

    if (!$origin || !$destination || !$fare || !$driver_id) {
        echo json_encode(['success' => false, 'message' => 'Missing fields.']);
        exit();
    }

    $check = $conn->prepare(
        "SELECT COUNT(*) AS c FROM bookings
         WHERE driver_id = ? AND status IN ('pending','accepted','ongoing')"
    );
    $check->bind_param('i', $driver_id);
    $check->execute();
    $busy = (int)$check->get_result()->fetch_assoc()['c'];
    if ($busy > 0) {
        echo json_encode(['success' => false, 'message' => 'That driver just became unavailable. Please choose another.']);
        exit();
    }

    /*
     * FIX: Cancel both 'pending' AND 'accepted' old bookings before creating a new one.
     * Previously only 'pending' was cancelled, leaving lingering accepted bookings
     * that could confuse the status poller.
     */
    $cancel = $conn->prepare("UPDATE bookings SET status='cancelled' WHERE commuter_id=? AND status IN ('pending','accepted')");
    $cancel->bind_param('i', $user_id);
    $cancel->execute();

    $stmt = $conn->prepare(
        "INSERT INTO bookings (commuter_id, driver_id, origin, destination, fare, status, created_at)
         VALUES (?, ?, ?, ?, ?, 'pending', NOW())"
    );
    $stmt->bind_param('iissd', $user_id, $driver_id, $origin, $destination, $fare);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'booking_id' => $conn->insert_id]);
    } else {
        echo json_encode(['success' => false, 'message' => 'DB error: ' . $conn->error]);
    }
    exit();
}

/* ── AJAX: Fetch online drivers ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_drivers') {
    header('Content-Type: application/json');
    $result = $conn->query(
        "SELECT u.id, u.username, u.plate_number, u.contact_no
         FROM users u
         WHERE u.role = 'driver'
           AND u.is_available = 1
           AND u.id NOT IN (
               SELECT driver_id FROM bookings
               WHERE status IN ('pending','accepted','ongoing')
           )
         LIMIT 10"
    );
    $drivers = [];
    while ($row = $result->fetch_assoc()) $drivers[] = $row;
    echo json_encode($drivers);
    exit();
}

/* ── AJAX: Driver online count ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'driver_count') {
    header('Content-Type: application/json');
    $result = $conn->query(
        "SELECT COUNT(*) AS cnt FROM users
         WHERE role='driver' AND is_available=1
         AND id NOT IN (
             SELECT driver_id FROM bookings
             WHERE status IN ('pending','accepted','ongoing')
         )"
    );
    $cnt = (int)($result->fetch_assoc()['cnt'] ?? 0);
    echo json_encode(['count' => $cnt]);
    exit();
}

/* ── AJAX: Check cancelled ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'check_cancelled') {
    header('Content-Type: application/json');
    $stmt = $conn->prepare(
        "SELECT id FROM bookings
         WHERE commuter_id = ? AND status = 'cancelled'
         ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    echo json_encode(['cancelled' => !empty($row)]);
    exit();
}

/* ── Profile & stats ── */
$stmt = $conn->prepare("SELECT username, email, phone, address, profile_pic FROM users WHERE id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$username    = htmlspecialchars($user['username'] ?? 'Commuter');
$email       = htmlspecialchars($user['email']    ?? '');
$phone       = htmlspecialchars($user['phone']    ?? '');
$address     = htmlspecialchars($user['address']  ?? '');
$profile_pic = $user['profile_pic'];
$pic_url     = (!empty($profile_pic)) ? "../images/profiles/" . $profile_pic : null;
$initials    = strtoupper(substr($username, 0, 1));

$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM bookings WHERE commuter_id = ?");
$stmt->bind_param('i', $user_id); $stmt->execute();
$total_rides = $stmt->get_result()->fetch_assoc()['cnt']; $stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM users WHERE role='driver' AND is_available=1");
$stmt->execute();
$online_drivers = $stmt->get_result()->fetch_assoc()['cnt']; $stmt->close();

$stmt = $conn->prepare("SELECT fare FROM bookings WHERE commuter_id=? AND status='completed' ORDER BY created_at DESC LIMIT 1");
$stmt->bind_param('i', $user_id); $stmt->execute();
$last_fare_row = $stmt->get_result()->fetch_assoc(); $stmt->close();
$last_fare = $last_fare_row ? '₱' . number_format($last_fare_row['fare'], 2) : '—';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PasadaNow — Commuter Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        :root {
            --bg: #0a1628; --surface: #0f1f35; --surface2: #132540; --surface3: #172c4a;
            --border: rgba(99,160,220,0.15); --border-lit: rgba(99,160,220,0.35);
            --blue: #3b8ee8; --blue-dim: rgba(59,142,232,0.12); --blue-glow: rgba(59,142,232,0.25);
            --orange: #f08228; --orange-dim: rgba(240,130,40,0.12);
            --green: #22c55e; --green-dim: rgba(34,197,94,0.12);
            --red: #ef4444; --red-dim: rgba(239,68,68,0.12);
            --purple: #a855f7; --purple-dim: rgba(168,85,247,0.12);
            --yellow: #eab308; --yellow-dim: rgba(234,179,8,0.12);
            --text: #cce0f5; --text-dim: #6a9cbf;
            --sidebar-w: 230px; --radius: 12px; --radius-sm: 8px;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Outfit', sans-serif; background: var(--bg); color: var(--text); display: flex; height: 100vh; overflow: hidden; }

        /* ─── SIDEBAR ─── */
        .sidebar { width: var(--sidebar-w); background: var(--surface); border-right: 1px solid var(--border); display: flex; flex-direction: column; flex-shrink: 0; }
        .logo-wrap { padding: 22px 20px 18px; display: flex; align-items: center; gap: 10px; border-bottom: 1px solid var(--border); }
        .logo-icon { width: 36px; height: 36px; flex-shrink: 0; object-fit: contain; }
        .logo-text { font-size: 1.25rem; font-weight: 800; line-height: 1; }
        .logo-text span:first-child { color: var(--blue); } .logo-text span:last-child { color: var(--orange); }
        .nav-section-label { padding: 18px 20px 6px; font-size: 0.6rem; font-weight: 700; color: var(--text-dim); text-transform: uppercase; letter-spacing: 1.5px; }
        .nav-btn { display: flex; align-items: center; gap: 10px; padding: 10px 16px; margin: 2px 10px; border-radius: var(--radius-sm); color: var(--text-dim); font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; background: none; width: calc(100% - 20px); text-align: left; font-family: inherit; transition: all 0.2s; position: relative; }
        .nav-btn svg { width: 16px; height: 16px; flex-shrink: 0; opacity: 0.7; }
        .nav-btn.active { background: var(--blue-dim); color: var(--blue); font-weight: 600; border: 1px solid var(--border-lit); }
        .nav-btn.active svg { opacity: 1; }
        .nav-btn:hover:not(.active) { background: rgba(255,255,255,0.04); color: var(--text); }
        .nav-btn .nav-dot { width: 3px; height: 3px; border-radius: 50%; background: var(--blue); margin-left: auto; display: none; }
        .nav-btn.active .nav-dot { display: block; }
        .sidebar-footer { margin-top: auto; padding: 16px; border-top: 1px solid var(--border); }
        .sidebar-user { display: flex; align-items: center; gap: 10px; padding: 8px; border-radius: var(--radius-sm); background: var(--surface2); margin-bottom: 10px; }
        .sidebar-avatar { width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, var(--blue), #1a5fa8); display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 700; color: #fff; overflow: hidden; flex-shrink: 0; }
        .sidebar-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .sidebar-user-name { font-size: 0.8rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sidebar-user-role { font-size: 0.65rem; color: var(--text-dim); }
        .signout-btn { display: flex; align-items: center; gap: 8px; color: var(--red); font-size: 0.8rem; font-weight: 500; text-decoration: none; padding: 6px 8px; border-radius: 6px; transition: background 0.2s; }
        .signout-btn:hover { background: rgba(239,68,68,0.08); }

        /* ─── MAIN ─── */
        .main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
        .topbar { padding: 14px 28px; background: var(--surface); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-shrink: 0; }
        .topbar-title { font-size: 1.1rem; font-weight: 700; }
        .search-wrap { display: flex; align-items: center; background: var(--surface2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 7px 14px; gap: 8px; flex: 1; max-width: 320px; }
        .search-wrap svg { width: 14px; height: 14px; color: var(--text-dim); flex-shrink: 0; }
        .search-wrap input { border: none; background: none; outline: none; color: var(--text); font-family: inherit; font-size: 0.8rem; width: 100%; }
        .search-wrap input::placeholder { color: var(--text-dim); }
        .search-btn { background: var(--blue); color: #fff; border: none; border-radius: var(--radius-sm); padding: 7px 16px; font-size: 0.8rem; font-weight: 600; cursor: pointer; font-family: inherit; }
        .topbar-meta { display: flex; align-items: center; gap: 16px; }
        .topbar-date { font-size: 0.78rem; color: var(--text-dim); white-space: nowrap; }
        .notif-btn { position: relative; background: var(--surface2); border: 1px solid var(--border); border-radius: var(--radius-sm); width: 34px; height: 34px; display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--text-dim); }
        .notif-btn svg { width: 16px; height: 16px; }
        .notif-badge { position: absolute; top: -4px; right: -4px; background: var(--orange); color: #fff; font-size: 0.5rem; font-weight: 700; width: 14px; height: 14px; border-radius: 50%; display: none; align-items: center; justify-content: center; }
        .notif-badge.show { display: flex; }
        .content { flex: 1; overflow-y: auto; padding: 24px 28px; }
        .content::-webkit-scrollbar { width: 4px; }
        .content::-webkit-scrollbar-thumb { background: var(--border-lit); border-radius: 4px; }
        .view-section { display: none; animation: fadeUp 0.25s ease; }
        .view-section.active { display: block; }

        @keyframes fadeUp      { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:translateY(0); } }
        @keyframes pulse       { 0%,100%{opacity:1} 50%{opacity:0.4} }
        @keyframes spin        { to { transform: rotate(360deg); } }
        @keyframes slideDown   { from { opacity:0; transform:translateY(-20px); } to { opacity:1; transform:translateY(0); } }
        @keyframes slideUp     { from { opacity:0; transform:translateY(20px); }  to { opacity:1; transform:translateY(0); } }
        @keyframes bounceIn    { 0%{transform:scale(0.7);opacity:0} 60%{transform:scale(1.08)} 100%{transform:scale(1);opacity:1} }
        @keyframes ringPulse   { 0%{box-shadow:0 0 0 0 rgba(34,197,94,0.6)} 70%{box-shadow:0 0 0 18px rgba(34,197,94,0)} 100%{box-shadow:0 0 0 0 rgba(34,197,94,0)} }
        @keyframes ringPulseRed{ 0%{box-shadow:0 0 0 0 rgba(239,68,68,0.6)}  70%{box-shadow:0 0 0 18px rgba(239,68,68,0)}  100%{box-shadow:0 0 0 0 rgba(239,68,68,0)} }
        @keyframes completePulse { 0%{box-shadow:0 0 0 0 rgba(168,85,247,0.6)} 70%{box-shadow:0 0 0 18px rgba(168,85,247,0)} 100%{box-shadow:0 0 0 0 rgba(168,85,247,0)} }
        @keyframes ongoingPulse { 0%{box-shadow:0 0 0 0 rgba(234,179,8,0.6)} 70%{box-shadow:0 0 0 18px rgba(234,179,8,0)} 100%{box-shadow:0 0 0 0 rgba(234,179,8,0)} }
        @keyframes roadAnim { 0%{background-position:0 0} 100%{background-position:60px 0} }

        /* ─── DRIVER ONLINE POPUP ─── */
        .driver-online-popup {
            position: fixed; top: 80px; right: 24px; width: 320px;
            background: var(--surface); border: 1.5px solid rgba(34,197,94,0.5);
            border-radius: var(--radius); padding: 16px 18px; z-index: 99999;
            display: none;
            box-shadow: 0 8px 40px rgba(0,0,0,0.6), 0 0 0 1px rgba(34,197,94,0.1);
            animation: slideDown 0.4s cubic-bezier(.22,1,.36,1), ringPulse 1.2s 2;
        }
        .driver-online-popup.show { display: block; }

        /* ─── DRIVER OFFLINE POPUP ─── */
        .driver-offline-popup {
            position: fixed; bottom: 80px; right: 24px; width: 320px;
            background: var(--surface); border: 1.5px solid rgba(239,68,68,0.5);
            border-radius: var(--radius); padding: 16px 18px; z-index: 99998;
            display: none;
            box-shadow: 0 8px 40px rgba(0,0,0,0.6), 0 0 0 1px rgba(239,68,68,0.1);
            animation: slideUp 0.4s cubic-bezier(.22,1,.36,1), ringPulseRed 1.2s 2;
        }
        .driver-offline-popup.show { display: block; }

        .dop-header { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
        .dop-icon { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; animation: bounceIn 0.5s ease; }
        .dop-icon.green  { background: var(--green-dim);  border: 2px solid rgba(34,197,94,0.4); }
        .dop-icon.red    { background: var(--red-dim);    border: 2px solid rgba(239,68,68,0.4); }
        .dop-icon.purple { background: var(--purple-dim); border: 2px solid rgba(168,85,247,0.4); }
        .dop-icon svg { width: 18px; height: 18px; }
        .dop-icon.green svg  { color: var(--green); }
        .dop-icon.red svg    { color: var(--red); }
        .dop-icon.purple svg { color: var(--purple); }
        .dop-title.green  { color: var(--green);  font-size: 0.9rem; font-weight: 700; }
        .dop-title.red    { color: var(--red);    font-size: 0.9rem; font-weight: 700; }
        .dop-title.purple { color: var(--purple); font-size: 0.9rem; font-weight: 700; }
        .dop-sub   { font-size: 0.7rem; color: var(--text-dim); margin-top: 1px; }
        .dop-count { font-size: 0.82rem; color: var(--text); margin-bottom: 12px; line-height: 1.5; }
        .dop-count b.green  { color: var(--green); }
        .dop-count b.red    { color: var(--red); }
        .dop-count b.purple { color: var(--purple); }
        .dop-actions { display: flex; gap: 8px; }
        .dop-btn-book    { flex: 1; padding: 9px; background: var(--green); color: #fff; border: none; border-radius: var(--radius-sm); font-family: inherit; font-size: 0.8rem; font-weight: 700; cursor: pointer; transition: opacity 0.2s; }
        .dop-btn-book:hover { opacity: 0.88; }
        .dop-btn-dismiss { padding: 9px 14px; background: var(--surface2); color: var(--text-dim); border: 1px solid var(--border); border-radius: var(--radius-sm); font-family: inherit; font-size: 0.8rem; cursor: pointer; }
        .dop-btn-dismiss.red    { color: var(--red);    border-color: rgba(239,68,68,0.3); }
        .dop-btn-dismiss.purple { color: var(--purple); border-color: rgba(168,85,247,0.3); }
        .dop-progress { height: 3px; background: var(--border); border-radius: 2px; margin-top: 12px; overflow: hidden; }
        .dop-progress-bar         { height: 100%; background: var(--green);  border-radius: 2px; width: 100%; transition: width linear; }
        .dop-progress-bar-offline { height: 100%; background: var(--red);    border-radius: 2px; width: 100%; transition: width linear; }
        .dop-progress-bar-complete{ height: 100%; background: var(--purple); border-radius: 2px; width: 100%; transition: width linear; }

        /* ─── TRIP COMPLETED POPUP ─── */
        .trip-complete-popup {
            position: fixed; top: 80px; right: 24px; width: 340px;
            background: var(--surface); border: 1.5px solid rgba(168,85,247,0.5);
            border-radius: var(--radius); padding: 18px 20px; z-index: 99997;
            display: none;
            box-shadow: 0 8px 40px rgba(0,0,0,0.6), 0 0 0 1px rgba(168,85,247,0.1);
            animation: slideDown 0.4s cubic-bezier(.22,1,.36,1), completePulse 1.2s 2;
        }
        .trip-complete-popup.show { display: block; }
        .tcp-route { font-size: 0.78rem; color: var(--text-dim); margin-bottom: 4px; }
        .tcp-fare  { font-size: 1.6rem; font-weight: 800; color: var(--purple); margin-bottom: 12px; line-height: 1; }

        /* ─── STAT CARDS ─── */
        .stats-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 14px; margin-bottom: 20px; }
        .stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 18px 20px; position: relative; overflow: hidden; transition: border-color 0.2s; }
        .stat-card:hover { border-color: var(--border-lit); }
        .stat-card-icon { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-bottom: 14px; }
        .stat-card-icon svg { width: 18px; height: 18px; }
        .stat-card-icon.blue   { background: var(--blue-dim);   color: var(--blue); }
        .stat-card-icon.orange { background: var(--orange-dim); color: var(--orange); }
        .stat-card-icon.green  { background: var(--green-dim);  color: var(--green); }
        .stat-card-icon.purple { background: var(--purple-dim); color: var(--purple); }
        .stat-card-value { font-size: 1.6rem; font-weight: 700; line-height: 1; margin-bottom: 4px; }
        .stat-card-label { font-size: 0.7rem; color: var(--text-dim); font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-card-glow { position: absolute; bottom: -20px; right: -20px; width: 80px; height: 80px; border-radius: 50%; opacity: 0.07; }
        .stat-card-glow.blue   { background: var(--blue); }
        .stat-card-glow.orange { background: var(--orange); }
        .stat-card-glow.green  { background: var(--green); }
        .stat-card-glow.purple { background: var(--purple); }

        /* ─── CARDS ─── */
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px; }
        .card-title { font-size: 0.875rem; font-weight: 600; color: var(--text); margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        .card-title-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--blue); box-shadow: 0 0 6px var(--blue); }

        #map { height: 280px; width: 100%; border-radius: var(--radius); border: 1px solid var(--border); margin-bottom: 16px; }
        .dashboard-grid { display: grid; grid-template-columns: 1.3fr 0.7fr; gap: 16px; }

        .field-label { display: block; font-size: 0.65rem; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: var(--text-dim); margin-bottom: 5px; }
        .field { width: 100%; padding: 10px 12px; background: var(--surface2); border: 1px solid var(--border); border-radius: var(--radius-sm); color: var(--text); outline: none; font-family: inherit; font-size: 0.875rem; transition: border-color 0.2s; }
        .field:focus { border-color: var(--blue); box-shadow: 0 0 0 3px var(--blue-glow); }
        .field-group { margin-bottom: 12px; }
        select.field { cursor: pointer; }

        .fare-preview { display: none; padding: 12px 14px; background: var(--green-dim); border: 1px solid rgba(34,197,94,0.2); border-radius: var(--radius-sm); margin-bottom: 12px; }
        .fare-label { font-size: 0.6rem; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: var(--green); margin-bottom: 2px; }
        .fare-value { font-size: 1.4rem; font-weight: 700; color: var(--green); }

        .btn { width: 100%; padding: 10px; border: none; border-radius: var(--radius-sm); font-family: inherit; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn-primary   { background: var(--blue); color: #fff; }
        .btn-primary:hover { opacity: 0.88; }
        .btn-danger    { background: var(--red-dim); color: var(--red); border: 1px solid rgba(239,68,68,0.2); }
        .btn-danger:hover { background: rgba(239,68,68,0.2); }
        .btn-secondary { background: var(--surface2); color: var(--text); border: 1px solid var(--border); }
        .btn-secondary:hover { border-color: var(--border-lit); }
        .btn:disabled  { opacity: 0.5; cursor: not-allowed; }

        /* ─── DRIVER LIST ─── */
        .driver-item { display: flex; align-items: center; gap: 12px; padding: 10px 8px; border-bottom: 1px solid var(--border); cursor: pointer; transition: background 0.15s; border-radius: 6px; }
        .driver-item:last-child { border-bottom: none; }
        .driver-item:hover { background: var(--blue-dim); }
        .driver-item.selected { background: var(--blue-dim); border: 1px solid var(--border-lit); border-radius: 8px; }
        .driver-item.new-driver { animation: bounceIn 0.4s ease; border-left: 3px solid var(--green); }
        .driver-avatar { width: 34px; height: 34px; border-radius: 50%; background: linear-gradient(135deg, #1a5fa8, var(--blue)); display: flex; align-items: center; justify-content: center; font-size: 0.75rem; font-weight: 700; color: #fff; flex-shrink: 0; }
        .driver-name { font-size: 0.8rem; font-weight: 600; }
        .driver-dist { font-size: 0.65rem; color: var(--text-dim); margin-top: 1px; }
        .status-pill { margin-left: auto; display: flex; align-items: center; gap: 5px; font-size: 0.65rem; font-weight: 600; color: var(--green); background: var(--green-dim); padding: 3px 8px; border-radius: 20px; }
        .status-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--green); box-shadow: 0 0 6px var(--green); animation: pulse 2s infinite; }
        .no-drivers { text-align: center; padding: 24px 0; color: var(--text-dim); font-size: 0.8rem; }

        /* ─── BOOKING STATUS PANEL ─── */
        .booking-status-panel { display: none; padding: 16px; border-radius: var(--radius-sm); margin-bottom: 12px; border: 1px solid; animation: fadeUp 0.3s ease; }
        .booking-status-panel.pending   { background: var(--orange-dim); border-color: rgba(240,130,40,0.3); }
        .booking-status-panel.accepted  { background: var(--green-dim);  border-color: rgba(34,197,94,0.3); }
        .booking-status-panel.ongoing   { background: var(--yellow-dim); border-color: rgba(234,179,8,0.3); }
        .booking-status-panel.completed { background: var(--purple-dim); border-color: rgba(168,85,247,0.3); }
        .booking-status-panel.cancelled { background: var(--red-dim);    border-color: rgba(239,68,68,0.2); }

        .booking-status-title { font-size: 0.82rem; font-weight: 700; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
        .booking-status-title.pending   { color: var(--orange); }
        .booking-status-title.accepted  { color: var(--green); }
        .booking-status-title.ongoing   { color: var(--yellow); }
        .booking-status-title.completed { color: var(--purple); }
        .booking-status-title.declined  { color: var(--red); }

        .booking-status-detail { font-size: 0.78rem; color: var(--text-dim); line-height: 1.8; }
        .driver-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 10px; }
        .driver-info-cell { background: rgba(34,197,94,0.08); border: 1px solid rgba(34,197,94,0.15); border-radius: var(--radius-sm); padding: 8px 10px; }
        .driver-info-cell-label { font-size: 0.58rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--green); margin-bottom: 2px; }
        .driver-info-cell-value { font-size: 0.82rem; font-weight: 600; color: var(--text); }

        /* ─── ONGOING TRIP TRACKER ─── */
        .ongoing-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 10px; }
        .ongoing-info-cell { background: rgba(234,179,8,0.08); border: 1px solid rgba(234,179,8,0.2); border-radius: var(--radius-sm); padding: 8px 10px; }
        .ongoing-info-cell-label { font-size: 0.58rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--yellow); margin-bottom: 2px; }
        .ongoing-info-cell-value { font-size: 0.82rem; font-weight: 600; color: var(--text); }

        /* ─── Animated road bar for ongoing ─── */
        .ongoing-road-bar {
            height: 6px; border-radius: 3px; margin-top: 12px; overflow: hidden;
            background: repeating-linear-gradient(90deg, var(--yellow) 0, var(--yellow) 20px, transparent 20px, transparent 40px);
            background-size: 60px 100%;
            animation: roadAnim 0.8s linear infinite;
            opacity: 0.7;
        }

        /* Completed trip info cells (purple tint) */
        .completed-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 10px; }
        .completed-info-cell { background: rgba(168,85,247,0.08); border: 1px solid rgba(168,85,247,0.2); border-radius: var(--radius-sm); padding: 8px 10px; }
        .completed-info-cell-label { font-size: 0.58rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--purple); margin-bottom: 2px; }
        .completed-info-cell-value { font-size: 0.82rem; font-weight: 600; color: var(--text); }

        .spinner { width: 12px; height: 12px; border: 2px solid currentColor; border-top-color: transparent; border-radius: 50%; animation: spin 0.8s linear infinite; display: inline-block; flex-shrink: 0; }

        /* ─── HISTORY ─── */
        .history-table { width: 100%; border-collapse: collapse; }
        .history-table thead tr { border-bottom: 1px solid var(--border); }
        .history-table th { padding: 10px 12px; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--text-dim); text-align: left; }
        .history-table td { padding: 11px 12px; font-size: 0.8rem; border-bottom: 1px solid var(--border); }
        .history-table tr:last-child td { border-bottom: none; }
        .history-table tr:hover td { background: rgba(59,142,232,0.03); }
        .status-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 20px; font-size: 0.65rem; font-weight: 700; }
        .badge-completed { background: var(--green-dim);        color: var(--green); }
        .badge-pending   { background: var(--orange-dim);       color: var(--orange); }
        .badge-cancelled { background: rgba(239,68,68,0.1);     color: var(--red); }
        .badge-accepted  { background: var(--blue-dim);         color: var(--blue); }
        .badge-ongoing   { background: var(--yellow-dim);       color: var(--yellow); }
        .filter-bar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; gap: 12px; }
        .filter-search { display: flex; align-items: center; background: var(--surface2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 7px 12px; gap: 7px; flex: 1; }
        .filter-search svg { width: 13px; height: 13px; color: var(--text-dim); }
        .filter-search input { border: none; background: none; outline: none; color: var(--text); font-family: inherit; font-size: 0.8rem; width: 100%; }
        .filter-search input::placeholder { color: var(--text-dim); }
        .section-title-tag { font-size: 0.78rem; font-weight: 600; color: var(--blue); text-decoration: none; white-space: nowrap; padding: 6px 12px; border: 1px solid var(--border-lit); border-radius: var(--radius-sm); }

        /* ─── PROFILE ─── */
        .profile-wrap { max-width: 700px; }
        .profile-pic-row { display: flex; align-items: center; gap: 20px; padding: 20px; background: var(--surface2); border-radius: var(--radius); border: 1px solid var(--border); margin-bottom: 20px; }
        .avatar-lg { width: 72px; height: 72px; border-radius: 50%; background: linear-gradient(135deg, var(--blue), #1a5fa8); display: flex; align-items: center; justify-content: center; font-size: 1.8rem; font-weight: 800; color: #fff; border: 2px solid var(--border-lit); overflow: hidden; flex-shrink: 0; }
        .avatar-lg img { width: 100%; height: 100%; object-fit: cover; }
        .avatar-info h4 { font-size: 0.9rem; font-weight: 600; margin-bottom: 4px; }
        .avatar-info p  { font-size: 0.72rem; color: var(--text-dim); margin-bottom: 10px; }
        .file-input-wrapper { position: relative; display: inline-block; }
        .file-input-wrapper input[type="file"] { opacity: 0; position: absolute; left: 0; top: 0; width: 100%; height: 100%; cursor: pointer; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .form-section-title { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--text-dim); margin: 20px 0 12px; padding-bottom: 8px; border-bottom: 1px solid var(--border); }
        .fleet-item { display: flex; align-items: center; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid var(--border); font-size: 0.8rem; }
        .fleet-item:last-child { border-bottom: none; }
        .fleet-count { font-weight: 700; }
        textarea.field { resize: vertical; min-height: 80px; }
        .profile-alert { display: none; align-items: center; gap: 8px; padding: 10px 14px; border-radius: var(--radius-sm); font-size: 0.8rem; font-weight: 600; margin-bottom: 14px; }
        .profile-alert.success { background: var(--green-dim); border: 1px solid rgba(34,197,94,0.2); color: var(--green); display: flex; }
        .profile-alert.error   { background: var(--red-dim);   border: 1px solid rgba(239,68,68,0.2);  color: var(--red);   display: flex; }

        /* ─── TOAST ─── */
        .toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%) translateY(80px); padding: 10px 20px; border-radius: 24px; font-size: 0.82rem; font-weight: 600; z-index: 9999; transition: transform 0.35s cubic-bezier(.22,1,.36,1), opacity 0.3s; opacity: 0; pointer-events: none; white-space: nowrap; }
        .toast.show { transform: translateX(-50%) translateY(0); opacity: 1; }
        .toast.green  { background: var(--green);  color: #fff; box-shadow: 0 6px 24px rgba(34,197,94,0.4); }
        .toast.red    { background: var(--red);    color: #fff; box-shadow: 0 6px 24px rgba(239,68,68,0.4); }
        .toast.blue   { background: var(--blue);   color: #fff; box-shadow: 0 6px 24px rgba(59,142,232,0.4); }
        .toast.orange { background: var(--orange); color: #fff; box-shadow: 0 6px 24px rgba(240,130,40,0.4); }
        .toast.purple { background: var(--purple); color: #fff; box-shadow: 0 6px 24px rgba(168,85,247,0.4); }
        .toast.yellow { background: var(--yellow); color: #000; box-shadow: 0 6px 24px rgba(234,179,8,0.4); }
    </style>
</head>
<body>

<!-- ══════════ DRIVER ONLINE POPUP ══════════ -->
<div class="driver-online-popup" id="driver-online-popup">
    <div class="dop-header">
        <div class="dop-icon green">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="1" y="3" width="15" height="13" rx="2"/>
                <path d="M16 8h4l3 3v5h-7V8z"/>
                <circle cx="5.5" cy="18.5" r="2.5"/>
                <circle cx="18.5" cy="18.5" r="2.5"/>
            </svg>
        </div>
        <div>
            <div class="dop-title green">Driver Now Available!</div>
            <div class="dop-sub">A driver just came online near you</div>
        </div>
    </div>
    <div class="dop-count">
        <b class="green" id="dop-driver-count">1</b> driver(s) available and ready to accept rides.
    </div>
    <div class="dop-actions">
        <button class="dop-btn-book" onclick="dismissPopupAndBook()">Book Now →</button>
        <button class="dop-btn-dismiss" onclick="dismissDriverOnlinePopup()">Dismiss</button>
    </div>
    <div class="dop-progress"><div class="dop-progress-bar" id="dop-bar"></div></div>
</div>

<!-- ══════════ DRIVER OFFLINE POPUP ══════════ -->
<div class="driver-offline-popup" id="driver-offline-popup">
    <div class="dop-header">
        <div class="dop-icon red">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="1" y="3" width="15" height="13" rx="2"/>
                <path d="M16 8h4l3 3v5h-7V8z"/>
                <circle cx="5.5" cy="18.5" r="2.5"/>
                <circle cx="18.5" cy="18.5" r="2.5"/>
                <line x1="3" y1="3" x2="21" y2="21" stroke-width="2.5"/>
            </svg>
        </div>
        <div>
            <div class="dop-title red">Driver Went Offline</div>
            <div class="dop-sub" id="offline-popup-sub">A driver just went offline</div>
        </div>
    </div>
    <div class="dop-count">
        <b class="red" id="dop-offline-remaining">0</b> driver(s) still available.
    </div>
    <div class="dop-actions">
        <button class="dop-btn-dismiss red" onclick="dismissDriverOfflinePopup()">OK, Got It</button>
    </div>
    <div class="dop-progress"><div class="dop-progress-bar-offline" id="dop-bar-offline"></div></div>
</div>

<!-- ══════════ TRIP COMPLETED POPUP ══════════ -->
<div class="trip-complete-popup" id="trip-complete-popup">
    <div class="dop-header">
        <div class="dop-icon purple">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="20,6 9,17 4,12"/>
            </svg>
        </div>
        <div>
            <div class="dop-title purple">Trip Completed!</div>
            <div class="dop-sub">Your ride has been finished</div>
        </div>
    </div>
    <div class="tcp-route" id="tcp-route">—</div>
    <div class="tcp-fare" id="tcp-fare">₱0.00</div>
    <div class="dop-count" style="margin-bottom:0;">
        Thank you for riding with <b class="purple">PasadaNow</b>! 🎉
    </div>
    <div class="dop-actions" style="margin-top:12px;">
        <button class="dop-btn-dismiss purple" onclick="dismissCompletePopup()">Done</button>
    </div>
    <div class="dop-progress"><div class="dop-progress-bar-complete" id="dop-bar-complete"></div></div>
</div>

<!-- ═══════════════ SIDEBAR ═══════════════ -->
<aside class="sidebar">
    <div class="logo-wrap">
        <img src="../images/logo.png" alt="PasadaNow Logo" class="logo-icon">
        <div class="logo-text"><span>Pasada</span><span>Now</span></div>
    </div>
    <div class="nav-section-label">Commuter</div>
    <button class="nav-btn active" onclick="switchView('dashboard', this)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        Overview <span class="nav-dot"></span>
    </button>
    <button class="nav-btn" onclick="switchView('history', this)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12,6 12,12 16,14"/></svg>
        Trip Records <span class="nav-dot"></span>
    </button>
    <button class="nav-btn" onclick="switchView('profile', this)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Profile Settings <span class="nav-dot"></span>
    </button>
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="sidebar-avatar" id="sidebar-avatar-wrap">
                <?php echo $pic_url ? "<img src='$pic_url' alt=''>" : $initials; ?>
            </div>
            <div style="flex:1;min-width:0;">
                <div class="sidebar-user-name" id="sidebar-username"><?php echo $username; ?></div>
                <div class="sidebar-user-role">Commuter</div>
            </div>
        </div>
        <a href="logout.php" class="signout-btn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16,17 21,12 16,7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Sign Out
        </a>
    </div>
</aside>

<!-- ═══════════════ MAIN ═══════════════ -->
<main class="main">
    <header class="topbar">
        <h2 class="topbar-title" id="view-title">PasadaNow Commuter Portal</h2>
        <div class="search-wrap">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" placeholder="Search routes, drivers...">
        </div>
        <button class="search-btn">Search</button>
        <div class="topbar-meta">
            <span class="topbar-date" id="live-clock"></span>
            <button class="notif-btn" id="notif-bell">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                <span class="notif-badge" id="notif-badge">0</span>
            </button>
        </div>
    </header>

    <div class="content">

        <!-- ─── DASHBOARD ─── -->
        <section id="view-dashboard" class="view-section active">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card-icon blue">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M0.5 9.5 Q5 6.5 11 8 Q15 7 19.5 8.5" stroke-linecap="round"/>
                            <rect x="1" y="9.5" width="9" height="5.5" rx="1"/>
                            <path d="M10 9 L15.5 9 L17.5 11.5 L17.5 15 L10 15 Z"/>
                            <circle cx="4" cy="18.5" r="2.8"/><circle cx="4" cy="18.5" r="1.1"/>
                            <circle cx="13" cy="18.5" r="2.8"/><circle cx="13" cy="18.5" r="1.1"/>
                        </svg>
                    </div>
                    <div class="stat-card-value"><?php echo $total_rides; ?></div>
                    <div class="stat-card-label">Total Bookings</div>
                    <div class="stat-card-glow blue"></div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-icon green">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                    <div class="stat-card-value" id="stat-online-drivers"><?php echo $online_drivers; ?></div>
                    <div class="stat-card-label">Online Drivers</div>
                    <div class="stat-card-glow green"></div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-icon orange">
                        <span style="font-size:1.1rem;font-weight:800;font-family:inherit;">₱</span>
                    </div>
                    <div class="stat-card-value"><?php echo $last_fare; ?></div>
                    <div class="stat-card-label">Last Fare</div>
                    <div class="stat-card-glow orange"></div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-icon purple">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12,6 12,12 16,14"/></svg>
                    </div>
                    <div class="stat-card-value" style="font-size:1rem;">COMMUTER</div>
                    <div class="stat-card-label">Account Type</div>
                    <div class="stat-card-glow purple"></div>
                </div>
            </div>

            <div id="map"></div>

            <div class="dashboard-grid">
                <div class="card">
                    <div class="card-title"><div class="card-title-dot"></div>Book a Ride</div>

                    <!-- ══ BOOKING STATUS PANEL ══ -->
                    <div id="booking-status-panel" class="booking-status-panel">
                        <div id="bsp-title" class="booking-status-title pending">
                            <span class="spinner"></span> Waiting for driver response...
                        </div>
                        <div id="bsp-detail" class="booking-status-detail"></div>

                        <!-- Driver info (shown after accepted) -->
                        <div id="driver-info-grid" class="driver-info-grid" style="display:none;">
                            <div class="driver-info-cell"><div class="driver-info-cell-label">Driver</div><div class="driver-info-cell-value" id="di-name">—</div></div>
                            <div class="driver-info-cell"><div class="driver-info-cell-label">Plate No.</div><div class="driver-info-cell-value" id="di-plate">—</div></div>
                            <div class="driver-info-cell"><div class="driver-info-cell-label">Contact</div><div class="driver-info-cell-value" id="di-contact">—</div></div>
                            <div class="driver-info-cell"><div class="driver-info-cell-label">Fare</div><div class="driver-info-cell-value" id="di-fare">—</div></div>
                        </div>

                        <!-- Ongoing trip info (shown during ride) -->
                        <div id="ongoing-info-grid" class="ongoing-info-grid" style="display:none;">
                            <div class="ongoing-info-cell"><div class="ongoing-info-cell-label">Driver</div><div class="ongoing-info-cell-value" id="oi-name">—</div></div>
                            <div class="ongoing-info-cell"><div class="ongoing-info-cell-label">Plate No.</div><div class="ongoing-info-cell-value" id="oi-plate">—</div></div>
                            <div class="ongoing-info-cell"><div class="ongoing-info-cell-label">Contact</div><div class="ongoing-info-cell-value" id="oi-contact">—</div></div>
                            <div class="ongoing-info-cell"><div class="ongoing-info-cell-label">Fare</div><div class="ongoing-info-cell-value" id="oi-fare">—</div></div>
                        </div>
                        <!-- Animated road bar shown during ongoing -->
                        <div id="ongoing-road-bar" class="ongoing-road-bar" style="display:none;"></div>

                        <!-- Completed trip summary -->
                        <div id="completed-info-grid" class="completed-info-grid" style="display:none;">
                            <div class="completed-info-cell"><div class="completed-info-cell-label">Driver</div><div class="completed-info-cell-value" id="ci-name">—</div></div>
                            <div class="completed-info-cell"><div class="completed-info-cell-label">Plate No.</div><div class="completed-info-cell-value" id="ci-plate">—</div></div>
                            <div class="completed-info-cell"><div class="completed-info-cell-label">Fare Paid</div><div class="completed-info-cell-value" id="ci-fare">—</div></div>
                            <div class="completed-info-cell"><div class="completed-info-cell-label">Route</div><div class="completed-info-cell-value" id="ci-route">—</div></div>
                        </div>

                        <button id="bsp-cancel-btn" class="btn btn-danger" style="margin-top:10px;width:auto;padding:7px 16px;font-size:0.78rem;" onclick="cancelBooking()">Cancel Booking</button>
                        <button id="bsp-book-new-btn" class="btn btn-primary" style="margin-top:10px;width:auto;padding:7px 16px;font-size:0.78rem;display:none;" onclick="resetBookingForm()">Book Another Ride →</button>
                    </div>

                    <!-- ══ BOOKING FORM ══ -->
                    <div id="booking-form-wrap">
                        <div class="field-group">
                            <label class="field-label">Pickup Point</label>
                            <input type="text" id="origin-input" class="field" placeholder="Your current location...">
                        </div>
                        <div class="field-group">
                            <label class="field-label">Destination</label>
                            <input type="text" id="dest-input" class="field" placeholder="Enter destination...">
                        </div>
                        <div class="field-group">
                            <label class="field-label">Select Driver</label>
                            <select id="driver-select" class="field">
                                <option value="">— Choose an online driver —</option>
                            </select>
                        </div>
                        <div id="fare-preview" class="fare-preview">
                            <div class="fare-label">Estimated Fare</div>
                            <div class="fare-value" id="est-price">₱0.00</div>
                            <div style="font-size:0.65rem;color:var(--green);margin-top:2px;" id="fare-note"></div>
                        </div>
                        <button type="button" id="book-btn" class="btn btn-primary" onclick="submitBooking()">Find a Driver →</button>
                    </div>
                </div>

                <div class="card">
                    <div class="card-title">
                        <div class="card-title-dot" style="background:var(--green);box-shadow:0 0 6px var(--green);"></div>
                        Nearest Drivers
                    </div>
                    <div id="driver-list"><div class="no-drivers">Loading drivers...</div></div>
                    <div class="form-section-title" style="margin-top:16px;">Fleet Summary</div>
                    <div class="fleet-item"><span>Online Drivers</span><span class="fleet-count" id="fleet-online"><?php echo $online_drivers; ?></span></div>
                    <div class="fleet-item"><span>Your Total Bookings</span><span class="fleet-count"><?php echo $total_rides; ?></span></div>
                    <div class="fleet-item" style="border-bottom:none;"><span>Last Fare Paid</span><span class="fleet-count" style="color:var(--green)"><?php echo $last_fare; ?></span></div>
                </div>
            </div>
        </section>

        <!-- ─── HISTORY ─── -->
        <section id="view-history" class="view-section">
            <div class="card">
                <div class="filter-bar">
                    <div class="filter-search">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="text" id="history-search" placeholder="Filter trips..." oninput="filterHistory()">
                    </div>
                    <span class="section-title-tag">Trip Records</span>
                </div>
                <table class="history-table">
                    <thead><tr><th>Trip ID</th><th>Date</th><th>Route</th><th>Driver</th><th>Fare</th><th>Status</th></tr></thead>
                    <tbody id="history-tbody">
                        <?php
                        $hstmt = $conn->prepare(
                            "SELECT b.id, b.created_at, b.origin, b.destination, b.fare, b.status, u.username AS driver
                             FROM bookings b JOIN users u ON b.driver_id = u.id
                             WHERE b.commuter_id = ? ORDER BY b.created_at DESC"
                        );
                        $hstmt->bind_param('i', $user_id); $hstmt->execute();
                        $hresult = $hstmt->get_result();
                        if ($hresult->num_rows === 0): ?>
                        <tr><td colspan="6" style="text-align:center;color:var(--text-dim);padding:30px;font-size:0.8rem;">No trip records yet.</td></tr>
                        <?php else: while($row = $hresult->fetch_assoc()):
                            $badge = match($row['status'] ?? '') {
                                'completed' => 'badge-completed',
                                'pending'   => 'badge-pending',
                                'accepted'  => 'badge-accepted',
                                'ongoing'   => 'badge-ongoing',
                                default     => 'badge-cancelled'
                            };
                        ?>
                        <tr>
                            <td style="color:var(--text-dim)">#TRP-<?php echo str_pad($row['id'],3,'0',STR_PAD_LEFT); ?></td>
                            <td><?php echo date('M d, Y g:i A', strtotime($row['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($row['origin'].' → '.$row['destination']); ?></td>
                            <td><?php echo htmlspecialchars($row['driver']); ?></td>
                            <td style="font-weight:600">₱<?php echo number_format($row['fare'],2); ?></td>
                            <td><span class="status-badge <?php echo $badge; ?>">● <?php echo ucfirst($row['status'] ?? 'unknown'); ?></span></td>
                        </tr>
                        <?php endwhile; endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ─── PROFILE ─── -->
        <section id="view-profile" class="view-section">
            <div class="profile-wrap">
                <div id="profile-alert" class="profile-alert">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px;height:15px;flex-shrink:0;"><polyline points="20,6 9,17 4,12"/></svg>
                    <span id="profile-alert-msg"></span>
                </div>
                <form id="profileForm" enctype="multipart/form-data">
                    <div class="profile-pic-row">
                        <div class="avatar-lg" id="imagePreview">
                            <?php echo $pic_url ? "<img src='$pic_url' alt=''>" : $initials; ?>
                        </div>
                        <div class="avatar-info">
                            <h4 id="profile-name-display"><?php echo $username; ?></h4>
                            <p id="profile-email-display">Commuter Account · <?php echo $email; ?></p>
                            <div class="file-input-wrapper">
                                <button type="button" class="btn btn-secondary" style="width:auto;padding:7px 14px;font-size:0.8rem;">Change Photo</button>
                                <input type="file" name="profile_pic" id="profilePicInput" accept="image/*">
                            </div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-title"><div class="card-title-dot"></div>Personal Information</div>
                        <div class="form-grid">
                            <div class="field-group">
                                <label class="field-label">Full Name</label>
                                <input type="text" class="field" id="field-fullname" value="<?php echo $username; ?>" name="fullname">
                            </div>
                            <div class="field-group">
                                <label class="field-label">Contact Number</label>
                                <input type="text" class="field" id="field-contact" value="<?php echo $phone; ?>" name="contact">
                            </div>
                        </div>
                        <div class="field-group">
                            <label class="field-label">Email Address</label>
                            <input type="email" class="field" id="field-email" value="<?php echo $email; ?>" name="email">
                        </div>
                        <div class="field-group">
                            <label class="field-label">Home / Saved Address</label>
                            <textarea class="field" id="field-address" name="address" rows="3"><?php echo $address; ?></textarea>
                        </div>
                        <div class="field-group">
                            <label class="field-label">New Password <span style="color:var(--text-dim);font-weight:400;">(leave blank to keep current)</span></label>
                            <input type="password" class="field" id="field-password" name="new_password" placeholder="••••••••">
                        </div>
                        <div style="display:flex;gap:10px;margin-top:8px;">
                            <button type="submit" id="profile-save-btn" class="btn btn-primary" style="width:auto;padding:10px 24px;">Save Changes</button>
                            <button type="button" class="btn btn-secondary" style="width:auto;padding:10px 24px;" onclick="resetProfileForm()">Cancel</button>
                        </div>
                    </div>
                </form>
            </div>
        </section>

    </div><!-- /.content -->
</main>

<div class="toast" id="toast"></div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
/* ══ UTILS ══ */
function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function showToast(msg, type = 'blue') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className   = 'toast ' + type;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3500);
}

/* ══ CLOCK ══ */
function updateClock() {
    const now    = new Date();
    const days   = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    let h = now.getHours();
    const mins = String(now.getMinutes()).padStart(2,'0');
    const secs = String(now.getSeconds()).padStart(2,'0');
    const ap   = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    document.getElementById('live-clock').textContent =
        `${days[now.getDay()]}, ${months[now.getMonth()]} ${now.getDate()}, ${h}:${mins}:${secs} ${ap}`;
}
updateClock(); setInterval(updateClock, 1000);

/* ══ NAV ══ */
function switchView(viewId, btn) {
    const titles = { dashboard:'PasadaNow Commuter Portal', history:'Trip Records', profile:'Profile Settings' };
    document.getElementById('view-title').innerText = titles[viewId] || viewId;
    document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('.view-section').forEach(v => v.classList.remove('active'));
    document.getElementById('view-' + viewId).classList.add('active');
    if (viewId === 'dashboard') setTimeout(() => map.invalidateSize(), 50);
}

/* ══ MAP ══ */
const map = L.map('map', { zoomControl: true }).setView([16.6159, 120.3209], 15);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors', maxZoom: 19
}).addTo(map);
const youIcon = L.divIcon({
    className: '',
    html: `<div style="width:16px;height:16px;background:#f08228;border-radius:50%;border:3px solid white;box-shadow:0 0 0 3px rgba(240,130,40,0.4),0 2px 8px rgba(0,0,0,0.5);"></div>`,
    iconSize:[16,16], iconAnchor:[8,8]
});
let myMarker = null, accuracyCircle = null;
function updateMyPosition({ coords: { latitude, longitude, accuracy } }) {
    const ll = [latitude, longitude];
    if (myMarker) { myMarker.setLatLng(ll); }
    else { myMarker = L.marker(ll, { icon: youIcon }).addTo(map).bindPopup('<b>Your Location</b>').openPopup(); map.setView(ll, 16); }
    if (accuracyCircle) accuracyCircle.setLatLng(ll).setRadius(accuracy);
    else accuracyCircle = L.circle(ll, { radius: accuracy, color:'#3b8ee8', fillColor:'#3b8ee8', fillOpacity:0.08, weight:1 }).addTo(map);
}
if ('geolocation' in navigator)
    navigator.geolocation.watchPosition(updateMyPosition, () => {
        if (!myMarker) myMarker = L.marker([16.6159, 120.3209], { icon: youIcon }).addTo(map).bindPopup('<b>Your Location</b> (GPS unavailable)').openPopup();
    }, { enableHighAccuracy: true, maximumAge: 5000, timeout: 10000 });

/* ══════════════════════════════════════════════════
   DRIVER STATUS POLLING
══════════════════════════════════════════════════ */
let lastDriverCount  = <?= (int)$online_drivers ?>;
let onlinePopupTimer = null;
let offlinePopupTimer= null;
let completePopupTimer = null;
const POPUP_DURATION = 10000;

function showDriverOnlinePopup(count) {
    const popup = document.getElementById('driver-online-popup');
    document.getElementById('dop-driver-count').textContent = count;
    popup.classList.add('show');
    const bar = document.getElementById('dop-bar');
    bar.style.transition = 'none'; bar.style.width = '100%';
    setTimeout(() => { bar.style.transition = `width ${POPUP_DURATION}ms linear`; bar.style.width = '0%'; }, 30);
    const badge = document.getElementById('notif-badge');
    badge.textContent = count; badge.classList.add('show');
    if (onlinePopupTimer) clearTimeout(onlinePopupTimer);
    onlinePopupTimer = setTimeout(dismissDriverOnlinePopup, POPUP_DURATION);
}
function dismissDriverOnlinePopup() {
    document.getElementById('driver-online-popup').classList.remove('show');
    if (onlinePopupTimer) { clearTimeout(onlinePopupTimer); onlinePopupTimer = null; }
}
function dismissPopupAndBook() {
    dismissDriverOnlinePopup();
    document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('active'));
    document.querySelector('.nav-btn').classList.add('active');
    document.querySelectorAll('.view-section').forEach(v => v.classList.remove('active'));
    document.getElementById('view-dashboard').classList.add('active');
    document.getElementById('origin-input').focus();
    showToast('Select a driver and enter your destination!', 'green');
}

function showDriverOfflinePopup(remaining) {
    const popup = document.getElementById('driver-offline-popup');
    document.getElementById('dop-offline-remaining').textContent = remaining;
    document.getElementById('offline-popup-sub').textContent =
        remaining === 0 ? 'No drivers available right now' : 'Some drivers are still online';
    popup.classList.add('show');
    const bar = document.getElementById('dop-bar-offline');
    bar.style.transition = 'none'; bar.style.width = '100%';
    setTimeout(() => { bar.style.transition = `width ${POPUP_DURATION}ms linear`; bar.style.width = '0%'; }, 30);
    if (offlinePopupTimer) clearTimeout(offlinePopupTimer);
    offlinePopupTimer = setTimeout(dismissDriverOfflinePopup, POPUP_DURATION);
}
function dismissDriverOfflinePopup() {
    document.getElementById('driver-offline-popup').classList.remove('show');
    if (offlinePopupTimer) { clearTimeout(offlinePopupTimer); offlinePopupTimer = null; }
}

function showTripCompletePopup(data) {
    const popup = document.getElementById('trip-complete-popup');
    document.getElementById('tcp-route').textContent  = `${data.origin} → ${data.destination}`;
    document.getElementById('tcp-fare').textContent   = '₱' + parseFloat(data.fare).toFixed(2);
    popup.classList.add('show');
    const bar = document.getElementById('dop-bar-complete');
    bar.style.transition = 'none'; bar.style.width = '100%';
    setTimeout(() => { bar.style.transition = `width ${POPUP_DURATION}ms linear`; bar.style.width = '0%'; }, 30);
    if (completePopupTimer) clearTimeout(completePopupTimer);
    completePopupTimer = setTimeout(dismissCompletePopup, POPUP_DURATION);
}
function dismissCompletePopup() {
    document.getElementById('trip-complete-popup').classList.remove('show');
    if (completePopupTimer) { clearTimeout(completePopupTimer); completePopupTimer = null; }
}

/* ── Poll driver count every 5s ── */
async function checkDriverCount() {
    try {
        const res  = await fetch('?ajax=driver_count');
        const data = await res.json();
        const cnt  = data.count || 0;
        document.getElementById('stat-online-drivers').textContent = cnt;
        document.getElementById('fleet-online').textContent        = cnt;
        const badge = document.getElementById('notif-badge');
        if (cnt > 0) { badge.textContent = cnt; badge.classList.add('show'); }
        else { badge.classList.remove('show'); }
        if (cnt > lastDriverCount) {
            showDriverOnlinePopup(cnt);
            showToast(`🚗 ${cnt - lastDriverCount} driver(s) just came online!`, 'green');
            loadDrivers();
        }
        if (cnt < lastDriverCount) {
            showDriverOfflinePopup(cnt);
            showToast(`⚠️ A driver went offline. ${cnt} driver(s) remaining.`, 'orange');
            loadDrivers();
        }
        lastDriverCount = cnt;
    } catch(e) {}
}
setInterval(checkDriverCount, 5000);
checkDriverCount();

/* ══ DRIVERS ══ */
let selectedDriverId = null, driversData = [], activeBookingId = null, pollInterval = null;
let prevDriverIds    = new Set();

/*
 * FIX: lastKnownStatus tracks the last status we rendered so we
 * never re-render the same state, and never jump to 'completed'
 * from a stale previous booking.
 */
let lastKnownStatus = null;

async function loadDrivers() {
    try { const res = await fetch('?ajax=get_drivers'); driversData = await res.json(); }
    catch(e) { return; }
    const list = document.getElementById('driver-list');
    const sel  = document.getElementById('driver-select');
    const newIds = new Set(driversData.map(d => d.id));
    sel.innerHTML = '<option value="">— Choose an online driver —</option>';
    if (!driversData.length) {
        list.innerHTML = '<div class="no-drivers">No drivers online right now.</div>';
        prevDriverIds = newIds;
        return;
    }
    list.innerHTML = '';
    driversData.forEach(d => {
        const initials  = d.username.substring(0,2).toUpperCase();
        const isNew     = !prevDriverIds.has(d.id) && prevDriverIds.size > 0;
        const item      = document.createElement('div');
        item.className  = 'driver-item' + (isNew ? ' new-driver' : '');
        item.dataset.id = d.id;
        item.innerHTML  = `
            <div class="driver-avatar">${initials}</div>
            <div>
                <div class="driver-name">${escHtml(d.username)}</div>
                <div class="driver-dist">${escHtml(d.plate_number)||'No plate'} · Tricycle${isNew ? ' <span style="color:var(--green);font-size:0.6rem;font-weight:700;">● NEW</span>' : ''}</div>
            </div>
            <div class="status-pill"><div class="status-dot"></div>Online</div>`;
        item.addEventListener('click', () => selectDriver(d.id, item));
        list.appendChild(item);
        const opt = document.createElement('option');
        opt.value = d.id; opt.textContent = `${d.username} (${d.plate_number||'No plate'})`;
        sel.appendChild(opt);
    });
    prevDriverIds = newIds;
}

function selectDriver(id, el) {
    selectedDriverId = id;
    document.querySelectorAll('.driver-item').forEach(i => i.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('driver-select').value = id;
    computeFare();
}

function computeFare() {
    const dest = document.getElementById('dest-input').value.trim();
    const fp   = document.getElementById('fare-preview');
    if (dest.length > 3) {
        const dist = 4 + Math.random() * 21;
        const fare = 15 + Math.max(0, dist - 4) * 2;
        document.getElementById('est-price').textContent = '₱' + fare.toFixed(2);
        document.getElementById('fare-note').textContent = `~${dist.toFixed(1)} km · ₱15.00 base + ₱2.00/km`;
        fp.style.display = 'block';
    } else { fp.style.display = 'none'; }
}
document.getElementById('dest-input').addEventListener('input', computeFare);
document.getElementById('driver-select').addEventListener('change', function() {
    selectedDriverId = this.value ? parseInt(this.value) : null;
    document.querySelectorAll('.driver-item').forEach(i => {
        i.classList.toggle('selected', parseInt(i.dataset.id) === selectedDriverId);
    });
});

async function submitBooking() {
    const origin = document.getElementById('origin-input').value.trim();
    const dest   = document.getElementById('dest-input').value.trim();
    const drvId  = document.getElementById('driver-select').value || selectedDriverId;
    const fare   = parseFloat(document.getElementById('est-price').textContent.replace('₱','')) || 0;
    if (!origin)  { alert('Please enter your pickup point.'); return; }
    if (!dest)    { alert('Please enter a destination.'); return; }
    if (!drvId)   { alert('Please select a driver.'); return; }
    if (fare <= 0){ alert('Please enter a destination to calculate fare.'); return; }
    const btn = document.getElementById('book-btn');
    btn.disabled = true; btn.textContent = 'Sending Request...';
    const fd = new FormData();
    fd.append('ajax_book','1'); fd.append('origin',origin); fd.append('destination',dest);
    fd.append('driver_id',drvId); fd.append('fare',fare.toFixed(2));
    try {
        const res  = await fetch(window.location.href, { method:'POST', body:fd });
        const data = await res.json();
        if (data.success) {
            activeBookingId = data.booking_id;
            lastKnownStatus = 'pending'; /* Reset status tracker for new booking */
            showPendingStatus(origin, dest, fare);
            /*
             * FIX: Small delay before first poll to avoid a race condition where
             * pollBooking fires before activeBookingId is fully committed, causing
             * it to fall through to a previous completed booking.
             */
            setTimeout(startPolling, 500);
            loadDrivers();
        }
        else { alert(data.message||'Booking failed.'); btn.disabled=false; btn.textContent='Find a Driver →'; loadDrivers(); }
    } catch(e) { alert('Network error.'); btn.disabled=false; btn.textContent='Find a Driver →'; }
}

/* ══ STATUS PANEL STATES ══ */

function showPendingStatus(origin, dest, fare) {
    lastKnownStatus = 'pending';
    document.getElementById('booking-status-panel').className = 'booking-status-panel pending';
    document.getElementById('booking-status-panel').style.display = 'block';
    document.getElementById('booking-form-wrap').style.display    = 'none';
    document.getElementById('bsp-title').className  = 'booking-status-title pending';
    document.getElementById('bsp-title').innerHTML  = '<span class="spinner"></span> Waiting for driver response...';
    document.getElementById('bsp-detail').innerHTML = `<b>From:</b> ${escHtml(origin)}<br><b>To:</b> ${escHtml(dest)}<br><b>Fare:</b> ₱${parseFloat(fare).toFixed(2)}`;
    document.getElementById('driver-info-grid').style.display    = 'none';
    document.getElementById('ongoing-info-grid').style.display   = 'none';
    document.getElementById('ongoing-road-bar').style.display    = 'none';
    document.getElementById('completed-info-grid').style.display = 'none';
    document.getElementById('bsp-cancel-btn').style.display      = 'inline-block';
    document.getElementById('bsp-book-new-btn').style.display    = 'none';
}

function showAcceptedStatus(data) {
    document.getElementById('booking-status-panel').className = 'booking-status-panel accepted';
    document.getElementById('bsp-title').className  = 'booking-status-title accepted';
    document.getElementById('bsp-title').innerHTML  = '✓ Driver accepted! On the way to you.';
    document.getElementById('bsp-detail').innerHTML = `<b>Route:</b> ${escHtml(data.origin)} → ${escHtml(data.destination)}`;
    document.getElementById('di-name').textContent    = data.driver_name  || '—';
    document.getElementById('di-plate').textContent   = data.plate_number || '—';
    document.getElementById('di-contact').textContent = data.contact_no   || '—';
    document.getElementById('di-fare').textContent    = '₱' + parseFloat(data.fare).toFixed(2);
    document.getElementById('driver-info-grid').style.display    = 'grid';
    document.getElementById('ongoing-info-grid').style.display   = 'none';
    document.getElementById('ongoing-road-bar').style.display    = 'none';
    document.getElementById('completed-info-grid').style.display = 'none';
    document.getElementById('bsp-cancel-btn').style.display      = 'none';
    document.getElementById('bsp-book-new-btn').style.display    = 'none';
    showToast('✓ Driver is on the way!', 'green');
}

function showOngoingStatus(data) {
    document.getElementById('booking-status-panel').className = 'booking-status-panel ongoing';
    document.getElementById('bsp-title').className  = 'booking-status-title ongoing';
    document.getElementById('bsp-title').innerHTML  = '🚗 Trip Ongoing — You\'re on your way!';
    document.getElementById('bsp-detail').innerHTML = `<b>Route:</b> ${escHtml(data.origin)} → ${escHtml(data.destination)}`;
    document.getElementById('oi-name').textContent    = data.driver_name  || '—';
    document.getElementById('oi-plate').textContent   = data.plate_number || '—';
    document.getElementById('oi-contact').textContent = data.contact_no   || '—';
    document.getElementById('oi-fare').textContent    = '₱' + parseFloat(data.fare).toFixed(2);
    document.getElementById('driver-info-grid').style.display    = 'none';
    document.getElementById('ongoing-info-grid').style.display   = 'grid';
    document.getElementById('ongoing-road-bar').style.display    = 'block';
    document.getElementById('completed-info-grid').style.display = 'none';
    document.getElementById('bsp-cancel-btn').style.display      = 'none';
    document.getElementById('bsp-book-new-btn').style.display    = 'none';
    showToast('🚗 Trip is now ongoing!', 'yellow');
}

function showCompletedStatus(data) {
    document.getElementById('booking-status-panel').className = 'booking-status-panel completed';
    document.getElementById('bsp-title').className  = 'booking-status-title completed';
    document.getElementById('bsp-title').innerHTML  = '🎉 Trip Completed! Thank you for riding.';
    document.getElementById('bsp-detail').innerHTML = `Your trip has been completed successfully.`;
    document.getElementById('ci-name').textContent  = data.driver_name  || '—';
    document.getElementById('ci-plate').textContent = data.plate_number || '—';
    document.getElementById('ci-fare').textContent  = '₱' + parseFloat(data.fare).toFixed(2);
    document.getElementById('ci-route').textContent = `${data.origin} → ${data.destination}`;
    document.getElementById('driver-info-grid').style.display    = 'none';
    document.getElementById('ongoing-info-grid').style.display   = 'none';
    document.getElementById('ongoing-road-bar').style.display    = 'none';
    document.getElementById('completed-info-grid').style.display = 'grid';
    document.getElementById('bsp-cancel-btn').style.display      = 'none';
    document.getElementById('bsp-book-new-btn').style.display    = 'inline-block';
    showTripCompletePopup(data);
    showToast('🎉 Trip completed! Safe travels!', 'purple');
}

function showDeclinedStatus() {
    document.getElementById('booking-status-panel').className = 'booking-status-panel cancelled';
    document.getElementById('bsp-title').className  = 'booking-status-title declined';
    document.getElementById('bsp-title').innerHTML  = '✕ Booking was cancelled.';
    document.getElementById('bsp-detail').innerHTML = 'Your booking was cancelled. Please choose a different driver.';
    document.getElementById('driver-info-grid').style.display    = 'none';
    document.getElementById('ongoing-info-grid').style.display   = 'none';
    document.getElementById('ongoing-road-bar').style.display    = 'none';
    document.getElementById('completed-info-grid').style.display = 'none';
    document.getElementById('bsp-cancel-btn').style.display      = 'none';
    document.getElementById('bsp-book-new-btn').style.display    = 'none';
    showToast('Driver cancelled. Please try another.', 'red');
    setTimeout(resetBookingForm, 4000);
}

function resetBookingForm() {
    lastKnownStatus = null; /* Reset status tracker on form reset */
    document.getElementById('booking-status-panel').style.display = 'none';
    document.getElementById('booking-form-wrap').style.display    = 'block';
    document.getElementById('book-btn').disabled    = false;
    document.getElementById('book-btn').textContent = 'Find a Driver →';
    document.getElementById('origin-input').value   = '';
    document.getElementById('dest-input').value     = '';
    document.getElementById('fare-preview').style.display = 'none';
    document.getElementById('driver-select').value  = '';
    document.querySelectorAll('.driver-item').forEach(i => i.classList.remove('selected'));
    selectedDriverId = null;
    activeBookingId  = null;
    if (pollInterval) { clearInterval(pollInterval); pollInterval = null; }
    loadDrivers();
}

function startPolling() {
    if (pollInterval) clearInterval(pollInterval);
    pollInterval = setInterval(pollBooking, 3000);
}

/*
 * ══════════════════════════════════════════════════════════
 * FIXED pollBooking
 *
 * Key fixes applied:
 *   1. Guard: never polls without a valid activeBookingId.
 *   2. Always passes booking_id in the query so the server
 *      fetches ONLY the current booking — never a stale one.
 *   3. Verifies the returned booking ID matches activeBookingId
 *      before processing, preventing stale-data UI flashes.
 *   4. Uses lastKnownStatus to skip no-op re-renders.
 *   5. Proper null checks on the response before reading status.
 * ══════════════════════════════════════════════════════════
 */
async function pollBooking() {
    /* GUARD: Never poll without a specific booking ID */
    if (!activeBookingId) {
        if (pollInterval) { clearInterval(pollInterval); pollInterval = null; }
        return;
    }

    try {
        const res  = await fetch(`?ajax=poll_booking&booking_id=${activeBookingId}`);
        const data = await res.json();

        /* No record returned — booking vanished */
        if (!data || !data.id) {
            clearInterval(pollInterval); pollInterval = null;
            showToast('Booking not found.', 'orange');
            resetBookingForm();
            return;
        }

        /*
         * SAFETY CHECK: Confirm the returned record is the booking
         * we actually submitted. Guards against edge-case mismatches.
         */
        if (parseInt(data.id) !== parseInt(activeBookingId)) {
            console.warn('[PasadaNow] Booking ID mismatch — skipping stale data', data.id, activeBookingId);
            return;
        }

        /* Only update the UI when the status actually changes */
        if (data.status === lastKnownStatus) return;
        lastKnownStatus = data.status;

        if (data.status === 'completed') {
            /* Trip is done — stop polling and show completion screen */
            clearInterval(pollInterval); pollInterval = null;
            activeBookingId = null;
            showCompletedStatus(data);

        } else if (data.status === 'ongoing') {
            /* Driver started the trip — show animated trip tracker */
            showOngoingStatus(data);
            /* Keep polling to catch 'completed' */

        } else if (data.status === 'accepted') {
            /* Driver accepted and is coming to pick up commuter */
            showAcceptedStatus(data);
            /* Keep polling to catch 'ongoing' then 'completed' */

        } else if (data.status === 'cancelled') {
            /* Booking was cancelled (by driver or system) */
            clearInterval(pollInterval); pollInterval = null;
            activeBookingId = null;
            showDeclinedStatus();
        }
        /* status === 'pending': no UI change needed, keep polling silently */

    } catch(e) {
        console.error('[PasadaNow] pollBooking error:', e);
    }
}

async function cancelBooking() {
    if (!confirm('Cancel your current booking?')) return;
    await fetch('?ajax=cancel_booking');
    if (pollInterval) { clearInterval(pollInterval); pollInterval = null; }
    lastKnownStatus = null;
    resetBookingForm();
    showToast('Booking cancelled.', 'red');
}

/* ══ HISTORY FILTER ══ */
function filterHistory() {
    const q = document.getElementById('history-search').value.toLowerCase();
    document.querySelectorAll('#history-tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}

/* ══ PROFILE ══ */
document.getElementById('profilePicInput').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = evt => {
        document.getElementById('imagePreview').innerHTML =
            `<img src="${evt.target.result}" alt="" style="width:100%;height:100%;object-fit:cover;">`;
    };
    reader.readAsDataURL(file);
});

let _origValues = {};
function cacheProfileValues() {
    _origValues = {
        fullname : document.getElementById('field-fullname').value,
        contact  : document.getElementById('field-contact').value,
        email    : document.getElementById('field-email').value,
        address  : document.getElementById('field-address').value,
    };
}
cacheProfileValues();

function resetProfileForm() {
    document.getElementById('field-fullname').value = _origValues.fullname;
    document.getElementById('field-contact').value  = _origValues.contact;
    document.getElementById('field-email').value    = _origValues.email;
    document.getElementById('field-address').value  = _origValues.address;
    document.getElementById('field-password').value = '';
    document.getElementById('profile-alert').className = 'profile-alert';
}

function showProfileAlert(msg, type) {
    const el = document.getElementById('profile-alert');
    document.getElementById('profile-alert-msg').textContent = msg;
    el.className = 'profile-alert ' + type;
    el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    setTimeout(() => { el.className = 'profile-alert'; }, 5000);
}

document.getElementById('profileForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('profile-save-btn');
    btn.disabled    = true;
    btn.textContent = 'Saving...';
    const fd = new FormData(this);
    fd.append('ajax_update_profile', '1');
    try {
        const res  = await fetch(window.location.href, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            document.getElementById('sidebar-username').textContent = data.username;
            const sidebarAvatar = document.getElementById('sidebar-avatar-wrap');
            if (data.profile_pic) {
                sidebarAvatar.innerHTML = `<img src="${data.profile_pic}" alt="" style="width:100%;height:100%;object-fit:cover;">`;
            } else {
                sidebarAvatar.textContent = data.username.charAt(0).toUpperCase();
            }
            document.getElementById('profile-name-display').textContent  = data.username;
            document.getElementById('profile-email-display').textContent = 'Commuter Account · ' + data.email;
            if (data.profile_pic) {
                document.getElementById('imagePreview').innerHTML =
                    `<img src="${data.profile_pic}?t=${Date.now()}" alt="" style="width:100%;height:100%;object-fit:cover;">`;
            }
            cacheProfileValues();
            document.getElementById('field-password').value = '';
            showProfileAlert('✓ Profile updated successfully!', 'success');
            showToast('✓ Profile saved!', 'green');
        } else {
            showProfileAlert('Error: ' + (data.message || 'Could not save profile.'), 'error');
            showToast('Failed to save profile.', 'red');
        }
    } catch(err) {
        showProfileAlert('Network error. Please try again.', 'error');
        showToast('Network error.', 'red');
    } finally {
        btn.disabled    = false;
        btn.textContent = 'Save Changes';
    }
});

/* ══ INIT ══ */
loadDrivers();
setInterval(loadDrivers, 15000);
</script>
</body>
</html>