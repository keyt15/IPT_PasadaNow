<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'driver') {
    header("Location: ../login.php?error=unauthorized");
    exit();
}
require_once '../../backend/config.php';

$user_id      = $_SESSION['user_id'];
$current_page = $_GET['page'] ?? 'dashboard';
$message      = "";

/* ── Fetch driver profile ── */
$stmt = $conn->prepare(
    "SELECT username, profile_pic, is_available, contact_no, address,
            plate_number, license_no, organization
     FROM users WHERE id = ?"
);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$driver = $stmt->get_result()->fetch_assoc();
$stmt->close();

$is_online   = (bool)($driver['is_available'] ?? false);
$profile_pic = !empty($driver['profile_pic']) ? "../images/profiles/" . $driver['profile_pic'] : null;
$username    = htmlspecialchars($driver['username'] ?? 'Driver');
$initials    = strtoupper(substr($username, 0, 2));

/* ── Handle profile update ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $uname    = $conn->real_escape_string($_POST['username']);
    $cont     = $conn->real_escape_string($_POST['contact_no']);
    $addr     = $conn->real_escape_string($_POST['address']);
    $plate    = $conn->real_escape_string($_POST['plate_number']);
    $lic      = $conn->real_escape_string($_POST['license_no']);
    $org      = $conn->real_escape_string($_POST['organization']);
    $new_pass = $_POST['new_password'];

    if (!empty($_FILES['profile_img']['name'])) {
        $target_dir = "../images/profiles/";
        $file_ext   = pathinfo($_FILES["profile_img"]["name"], PATHINFO_EXTENSION);
        $new_fn     = "driver_{$user_id}_" . time() . ".$file_ext";
        if (move_uploaded_file($_FILES["profile_img"]["tmp_name"], $target_dir . $new_fn))
            $conn->query("UPDATE users SET profile_pic='$new_fn' WHERE id=$user_id");
    }

    $pass_sql = "";
    if (!empty($new_pass)) {
        $hashed  = password_hash($new_pass, PASSWORD_DEFAULT);
        $hashed  = $conn->real_escape_string($hashed);
        $pass_sql = ", password='$hashed'";
    }

    $conn->query(
        "UPDATE users SET username='$uname', contact_no='$cont', address='$addr',
         plate_number='$plate', license_no='$lic', organization='$org' $pass_sql
         WHERE id=$user_id"
    );
    $message = "Profile updated successfully!";
    header("Refresh:1;url=?page=profile");
}

/* ── AJAX: Toggle availability ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'toggle_queue') {
    header('Content-Type: application/json');
    $new_status = ($_POST['status'] === 'join') ? 1 : 0;
    $stmt = $conn->prepare("UPDATE users SET is_available=? WHERE id=?");
    $stmt->bind_param('ii', $new_status, $user_id);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => $ok, 'is_available' => $new_status]);
    exit();
}

/* ── AJAX: Check for a pending ride request assigned to this driver ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'check_request') {
    header('Content-Type: application/json');
    $stmt = $conn->prepare(
        "SELECT b.id, b.origin, b.destination, b.fare,
                u.username AS commuter_name, u.contact_no AS commuter_phone
         FROM bookings b
         JOIN users u ON b.commuter_id = u.id
         WHERE b.driver_id = ? AND b.status = 'pending'
         ORDER BY b.created_at ASC
         LIMIT 1"
    );
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    echo json_encode($row);
    exit();
}

/* ── AJAX: Accept / Decline / Start / Complete ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'update_status') {
    header('Content-Type: application/json');
    $status  = $_POST['status']  ?? '';
    $trip_id = intval($_POST['trip_id'] ?? 0);

    $db_status = match($status) {
        'accepted'  => 'accepted',
        'declined'  => 'cancelled',
        'ongoing'   => 'ongoing',
        'completed' => 'completed',
        default     => 'cancelled'
    };

    $stmt = $conn->prepare("UPDATE bookings SET status=? WHERE id=? AND driver_id=?");
    $stmt->bind_param('sii', $db_status, $trip_id, $user_id);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
        if ($db_status === 'accepted') {
            /* Driver is now busy */
            $conn->query("UPDATE users SET is_available=0 WHERE id=$user_id");
        } elseif ($db_status === 'ongoing') {
            /* Trip started — driver stays unavailable */
            $conn->query("UPDATE users SET is_available=0 WHERE id=$user_id");
        } elseif ($db_status === 'completed' || $db_status === 'cancelled') {
            /* Trip ended — restore driver to available */
            $conn->query("UPDATE users SET is_available=1 WHERE id=$user_id");
        }
    }

    echo json_encode(['success' => $ok, 'new_status' => $db_status]);
    exit();
}

/* ── AJAX: Notification count ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'notif_count') {
    header('Content-Type: application/json');
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM bookings WHERE driver_id=? AND status='pending'");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    echo json_encode(['count' => $count]);
    exit();
}

/* ── AJAX: Notification list ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'notif_list') {
    header('Content-Type: application/json');
    $stmt = $conn->prepare(
        "SELECT b.id, b.origin, b.destination, b.fare, b.created_at,
                u.username AS commuter_name
         FROM bookings b
         JOIN users u ON b.commuter_id = u.id
         WHERE b.driver_id=? AND b.status='pending'
         ORDER BY b.created_at DESC LIMIT 10"
    );
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $rows = [];
    $res  = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    echo json_encode(['notifs' => $rows]);
    exit();
}

/* ── AJAX: Session ping ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'session_ping') {
    header('Content-Type: application/json');
    echo json_encode(['alive' => true, 'role' => $_SESSION['role']]);
    exit();
}

/* ── Stats ── */
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(fare),0) AS total
     FROM bookings WHERE driver_id=? AND status='completed'"
);
$stmt->bind_param('i', $user_id); $stmt->execute();
$all_time = $stmt->get_result()->fetch_assoc(); $stmt->close();

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(fare),0) AS total
     FROM bookings WHERE driver_id=? AND status='completed' AND DATE(created_at)=CURDATE()"
);
$stmt->bind_param('i', $user_id); $stmt->execute();
$today = $stmt->get_result()->fetch_assoc(); $stmt->close();

/* ── History ── */
$history_stmt = $conn->prepare(
    "SELECT b.*, u.username AS commuter FROM bookings b
     JOIN users u ON b.commuter_id = u.id
     WHERE b.driver_id=? ORDER BY b.created_at DESC"
);
$history_stmt->bind_param('i', $user_id);
$history_stmt->execute();
$history_result = $history_stmt->get_result();

/* ── Check for an already-accepted OR ongoing active trip ── */
$stmt = $conn->prepare(
    "SELECT b.id, b.origin, b.destination, b.fare, b.status,
            u.username AS commuter_name, u.contact_no AS commuter_phone
     FROM bookings b JOIN users u ON b.commuter_id = u.id
     WHERE b.driver_id=? AND b.status IN ('accepted','ongoing')
     ORDER BY b.created_at DESC LIMIT 1"
);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$active_trip = $stmt->get_result()->fetch_assoc();
$stmt->close();

$ajax_base = strtok($_SERVER['REQUEST_URI'], '?');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PasadaNow — Driver Portal</title>
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
        .nav-btn { display: flex; align-items: center; gap: 10px; padding: 10px 16px; margin: 2px 10px; border-radius: var(--radius-sm); color: var(--text-dim); font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; background: none; width: calc(100% - 20px); text-align: left; font-family: inherit; transition: all 0.2s; text-decoration: none; }
        .nav-btn svg { width: 16px; height: 16px; flex-shrink: 0; opacity: 0.7; }
        .nav-btn.active { background: var(--blue-dim); color: var(--blue); font-weight: 600; border: 1px solid var(--border-lit); }
        .nav-btn.active svg { opacity: 1; }
        .nav-btn:hover:not(.active) { background: rgba(255,255,255,0.04); color: var(--text); }
        .nav-btn .nav-dot { width: 5px; height: 5px; border-radius: 50%; background: var(--blue); margin-left: auto; display: none; }
        .nav-btn.active .nav-dot { display: block; }
        .nav-btn.danger { color: var(--red); }
        .nav-btn.danger:hover { background: var(--red-dim); }
        .nav-btn.danger svg { opacity: 1; }
        .sidebar-footer { margin-top: auto; padding: 16px; border-top: 1px solid var(--border); }
        .sidebar-user { display: flex; align-items: center; gap: 10px; padding: 8px; border-radius: var(--radius-sm); background: var(--surface2); margin-bottom: 10px; }
        .sidebar-avatar { width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, var(--orange), #a85010); display: flex; align-items: center; justify-content: center; font-size: 0.7rem; font-weight: 700; color: #fff; overflow: hidden; flex-shrink: 0; }
        .sidebar-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .sidebar-user-name { font-size: 0.8rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sidebar-user-role { font-size: 0.65rem; color: var(--text-dim); }
        .queue-indicator { display: flex; align-items: center; gap: 6px; padding: 6px 10px; border-radius: 20px; font-size: 0.68rem; font-weight: 600; margin-top: 8px; transition: all 0.4s ease; }
        .queue-indicator.online  { background: var(--green-dim); color: var(--green); border: 1px solid rgba(34,197,94,0.2); }
        .queue-indicator.offline { background: rgba(255,255,255,0.05); color: var(--text-dim); border: 1px solid var(--border); }
        .queue-dot { width: 6px; height: 6px; border-radius: 50%; transition: all 0.3s; flex-shrink: 0; }
        .queue-dot.online  { background: var(--green); box-shadow: 0 0 6px var(--green); animation: pulse 2s infinite; }
        .queue-dot.offline { background: var(--text-dim); animation: none; }

        /* ─── ANIMATIONS ─── */
        @keyframes pulse        { 0%,100%{opacity:1} 50%{opacity:0.4} }
        @keyframes spin         { to { transform: rotate(360deg); } }
        @keyframes fadeUp       { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:translateY(0)} }
        @keyframes slideUp      { from{transform:translateX(-50%) translateY(24px);opacity:0} to{transform:translateX(-50%) translateY(0);opacity:1} }
        @keyframes slideDown    { from{opacity:0;transform:translateY(-20px)} to{opacity:1;transform:translateY(0)} }
        @keyframes ringPulse    { 0%{box-shadow:0 0 0 0 rgba(240,130,40,0.7)} 70%{box-shadow:0 0 0 18px rgba(240,130,40,0)} 100%{box-shadow:0 0 0 0 rgba(240,130,40,0)} }
        @keyframes completePulse{ 0%{box-shadow:0 0 0 0 rgba(168,85,247,0.7)} 70%{box-shadow:0 0 0 18px rgba(168,85,247,0)} 100%{box-shadow:0 0 0 0 rgba(168,85,247,0)} }
        @keyframes bumpScale    { 0%{transform:scale(1)} 50%{transform:scale(1.5)} 100%{transform:scale(1)} }
        @keyframes bounceIn     { 0%{transform:scale(0.7);opacity:0} 60%{transform:scale(1.08)} 100%{transform:scale(1);opacity:1} }
        @keyframes roadAnim     { 0%{background-position:0 0} 100%{background-position:60px 0} }

        /* ─── MAIN ─── */
        .main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
        .topbar { padding: 14px 28px; background: var(--surface); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-shrink: 0; }
        .topbar-title { font-size: 1.1rem; font-weight: 700; white-space: nowrap; }
        .search-wrap { display: flex; align-items: center; background: var(--surface2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 7px 14px; gap: 8px; flex: 1; max-width: 300px; }
        .search-wrap svg { width: 14px; height: 14px; color: var(--text-dim); flex-shrink: 0; }
        .search-wrap input { border: none; background: none; outline: none; color: var(--text); font-family: inherit; font-size: 0.8rem; width: 100%; }
        .search-wrap input::placeholder { color: var(--text-dim); }
        .search-btn { background: var(--blue); color: #fff; border: none; border-radius: var(--radius-sm); padding: 7px 16px; font-size: 0.8rem; font-weight: 600; cursor: pointer; font-family: inherit; }
        .topbar-meta { display: flex; align-items: center; gap: 12px; }
        .topbar-date { font-size: 0.78rem; color: var(--text-dim); white-space: nowrap; }

        /* ─── NOTIFICATION BELL ─── */
        .notif-wrap { position: relative; }
        .notif-btn { position: relative; background: var(--surface2); border: 1px solid var(--border); border-radius: var(--radius-sm); width: 34px; height: 34px; display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--text-dim); transition: all 0.2s; }
        .notif-btn:hover { border-color: var(--border-lit); color: var(--text); }
        .notif-btn svg { width: 16px; height: 16px; }
        .notif-badge { position: absolute; top: -4px; right: -4px; background: var(--orange); color: #fff; font-size: 0.5rem; font-weight: 700; min-width: 16px; height: 16px; padding: 0 3px; border-radius: 8px; display: none; align-items: center; justify-content: center; }
        .notif-badge.show { display: flex; }
        .notif-badge.bump { animation: bumpScale 0.3s ease; }
        .notif-dropdown { position: absolute; top: calc(100% + 10px); right: 0; width: 340px; background: var(--surface); border: 1px solid var(--border-lit); border-radius: var(--radius); box-shadow: 0 20px 50px rgba(0,0,0,0.6); z-index: 9999; display: none; animation: fadeUp 0.18s ease; }
        .notif-dropdown.open { display: block; }
        .notif-header { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid var(--border); }
        .notif-header-title { font-size: 0.8rem; font-weight: 700; }
        .notif-clear-btn { font-size: 0.68rem; color: var(--blue); background: none; border: none; cursor: pointer; font-family: inherit; }
        .notif-list { max-height: 320px; overflow-y: auto; }
        .notif-list::-webkit-scrollbar { width: 3px; }
        .notif-list::-webkit-scrollbar-thumb { background: var(--border); border-radius: 2px; }
        .notif-item { display: flex; align-items: flex-start; gap: 10px; padding: 11px 16px; border-bottom: 1px solid rgba(99,160,220,0.08); cursor: pointer; transition: background 0.15s; }
        .notif-item:last-child { border-bottom: none; }
        .notif-item:hover { background: var(--blue-dim); }
        .notif-dot-icon { width: 8px; height: 8px; border-radius: 50%; background: var(--orange); flex-shrink: 0; margin-top: 4px; box-shadow: 0 0 6px var(--orange); }
        .notif-item-body { flex: 1; min-width: 0; }
        .notif-item-title { font-size: 0.78rem; font-weight: 600; }
        .notif-item-sub   { font-size: 0.68rem; color: var(--text-dim); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .notif-item-fare  { font-size: 0.72rem; font-weight: 700; color: var(--green); margin-top: 2px; }
        .notif-item-time  { font-size: 0.62rem; color: var(--text-dim); margin-top: 2px; }
        .notif-empty { padding: 28px 16px; text-align: center; color: var(--text-dim); font-size: 0.78rem; }

        /* ─── CONTENT ─── */
        .content { flex: 1; overflow-y: auto; padding: 24px 28px; }
        .content::-webkit-scrollbar { width: 4px; }
        .content::-webkit-scrollbar-thumb { background: var(--border-lit); border-radius: 4px; }

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
        .stat-card-value { font-size: 1.5rem; font-weight: 700; line-height: 1; margin-bottom: 4px; }
        .stat-card-label { font-size: 0.68rem; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.5px; font-weight: 500; }
        .stat-glow { position: absolute; bottom: -20px; right: -20px; width: 80px; height: 80px; border-radius: 50%; opacity: 0.07; }
        .stat-glow.blue   { background: var(--blue); }
        .stat-glow.orange { background: var(--orange); }
        .stat-glow.green  { background: var(--green); }

        /* ─── CARD ─── */
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px; margin-bottom: 16px; }
        .card-title { font-size: 0.875rem; font-weight: 600; display: flex; align-items: center; gap: 8px; margin-bottom: 16px; }
        .card-title-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--blue); box-shadow: 0 0 6px var(--blue); flex-shrink: 0; }

        /* ─── MAP ─── */
        .map-wrap { position: relative; border-radius: var(--radius); overflow: hidden; border: 1px solid var(--border); margin-bottom: 16px; }
        #map { height: 360px; width: 100%; }

        /* ─── STATUS BAR ─── */
        .status-bar { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); margin-bottom: 16px; transition: border-color 0.4s, background 0.4s; }
        .status-bar.is-online { border-color: rgba(34,197,94,0.3); background: linear-gradient(135deg, var(--surface), rgba(34,197,94,0.04)); }
        .status-bar-left { display: flex; align-items: center; gap: 12px; }
        .status-big-dot { width: 10px; height: 10px; border-radius: 50%; transition: all 0.3s; flex-shrink: 0; }
        .status-big-dot.online  { background: var(--green); box-shadow: 0 0 10px var(--green); animation: pulse 2s infinite; }
        .status-big-dot.offline { background: var(--text-dim); animation: none; }
        .status-label { font-size: 0.9rem; font-weight: 600; }
        .status-sub   { font-size: 0.72rem; color: var(--text-dim); margin-top: 1px; }

        /* ─── TOGGLE BUTTON ─── */
        .toggle-btn { padding: 10px 26px; border-radius: var(--radius-sm); border: none; font-family: inherit; font-size: 0.85rem; font-weight: 700; cursor: pointer; transition: all 0.25s; display: flex; align-items: center; gap: 7px; }
        .toggle-btn.go-online  { background: var(--green); color: #fff; box-shadow: 0 4px 16px rgba(34,197,94,0.35); }
        .toggle-btn.go-offline { background: var(--red-dim); color: var(--red); border: 1px solid rgba(239,68,68,0.3); }
        .toggle-btn:disabled   { opacity: 0.6; cursor: not-allowed; }
        .toggle-spinner { width: 12px; height: 12px; border: 2px solid currentColor; border-top-color: transparent; border-radius: 50%; animation: spin 0.7s linear infinite; display: none; flex-shrink: 0; }
        .toggle-btn.loading .toggle-spinner { display: inline-block; }
        .toggle-btn.loading .toggle-label  { opacity: 0.7; }

        /* ─── RIDE REQUEST OVERLAY ─── */
        .ride-overlay {
            position: absolute; bottom: 16px; left: 50%;
            transform: translateX(-50%);
            width: 92%; max-width: 420px;
            background: var(--surface2);
            border: 2px solid var(--orange);
            border-radius: var(--radius);
            padding: 20px; z-index: 1000;
            display: none;
            box-shadow: 0 12px 48px rgba(0,0,0,0.7);
        }
        .ride-overlay.visible { display: block; animation: slideUp 0.35s ease, ringPulse 1.5s 3; }
        .ride-overlay-header { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; }
        .ride-alert-icon { width: 34px; height: 34px; background: var(--orange-dim); border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .ride-alert-icon svg { width: 16px; height: 16px; color: var(--orange); }
        .ride-title    { font-size: 0.95rem; font-weight: 700; color: var(--orange); }
        .ride-subtitle { font-size: 0.68rem; color: var(--text-dim); margin-top: 1px; }
        .ride-info-row { display: flex; gap: 10px; margin-bottom: 12px; }
        .ride-info-block { flex: 1; background: var(--surface); border-radius: var(--radius-sm); padding: 10px 12px; min-width: 0; }
        .ride-info-label { font-size: 0.58rem; color: var(--text-dim); text-transform: uppercase; letter-spacing: 1px; font-weight: 700; margin-bottom: 3px; }
        .ride-info-value { font-size: 0.82rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .ride-fare-big { font-size: 1.5rem; font-weight: 800; color: var(--green); }
        .ride-timer { font-size: 0.72rem; color: var(--text-dim); margin-bottom: 12px; display: flex; align-items: center; gap: 5px; }
        .ride-timer-count { font-size: 1rem; font-weight: 700; color: var(--orange); min-width: 24px; }
        .ride-actions { display: flex; gap: 8px; }
        .btn-accept  { flex: 1; padding: 11px; background: var(--green); color: #fff; border: none; border-radius: var(--radius-sm); font-family: inherit; font-size: 0.875rem; font-weight: 700; cursor: pointer; transition: opacity 0.2s; }
        .btn-accept:hover { opacity: 0.88; }
        .btn-decline { padding: 11px 16px; background: var(--red-dim); color: var(--red); border: 1px solid rgba(239,68,68,0.25); border-radius: var(--radius-sm); font-family: inherit; font-size: 0.875rem; font-weight: 700; cursor: pointer; }

        /* ─── ACTIVE TRIP BANNER ─── */
        .active-trip-banner { display: none; border: 1px solid; border-radius: var(--radius); padding: 14px 18px; margin-bottom: 16px; animation: fadeUp 0.3s ease; transition: background 0.4s, border-color 0.4s; }
        .active-trip-banner.visible { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        /* accepted state (green) */
        .active-trip-banner.state-accepted { background: var(--green-dim); border-color: rgba(34,197,94,0.3); }
        /* ongoing state (yellow) */
        .active-trip-banner.state-ongoing  { background: var(--yellow-dim); border-color: rgba(234,179,8,0.3); }
        .abt-left   { display: flex; align-items: center; gap: 10px; }
        .abt-dot    { width: 10px; height: 10px; border-radius: 50%; box-shadow: 0 0 8px currentColor; animation: pulse 1.5s infinite; flex-shrink: 0; transition: background 0.4s; }
        .abt-dot.accepted { background: var(--green); color: var(--green); }
        .abt-dot.ongoing  { background: var(--yellow); color: var(--yellow); }
        .abt-label  { font-size: 0.85rem; font-weight: 700; transition: color 0.3s; }
        .abt-label.accepted { color: var(--green); }
        .abt-label.ongoing  { color: var(--yellow); }
        .abt-detail { font-size: 0.72rem; color: var(--text-dim); margin-top: 2px; }

        /* Road bar shown during ongoing */
        .abt-road-bar {
            height: 4px; border-radius: 2px; margin-top: 6px; width: 200px;
            background: repeating-linear-gradient(90deg, var(--yellow) 0, var(--yellow) 18px, transparent 18px, transparent 36px);
            background-size: 54px 100%;
            animation: roadAnim 0.7s linear infinite;
            display: none;
        }

        .btn-start-trip { padding: 10px 18px; background: var(--yellow); color: #000; border: none; border-radius: var(--radius-sm); font-family: inherit; font-size: 0.82rem; font-weight: 700; cursor: pointer; white-space: nowrap; transition: opacity 0.2s; display: none; }
        .btn-start-trip:hover { opacity: 0.88; }
        .btn-complete { padding: 10px 20px; background: var(--green); color: #fff; border: none; border-radius: var(--radius-sm); font-family: inherit; font-size: 0.85rem; font-weight: 700; cursor: pointer; white-space: nowrap; transition: opacity 0.2s; }
        .btn-complete:hover { opacity: 0.88; }
        .btn-complete:disabled { opacity: 0.5; cursor: not-allowed; }

        /* ─── TRIP DONE BANNER ─── */
        .trip-done-banner { display: none; background: var(--purple-dim); border: 1px solid rgba(168,85,247,0.3); border-radius: var(--radius); padding: 14px 18px; margin-bottom: 16px; animation: fadeUp 0.3s ease; }
        .trip-done-banner.visible { display: flex; align-items: center; gap: 12px; }
        .tdb-icon { width: 38px; height: 38px; border-radius: 50%; background: var(--purple-dim); border: 2px solid rgba(168,85,247,0.4); display: flex; align-items: center; justify-content: center; flex-shrink: 0; animation: bounceIn 0.5s ease; }
        .tdb-icon svg { width: 18px; height: 18px; color: var(--purple); }
        .tdb-label  { font-size: 0.85rem; font-weight: 700; color: var(--purple); }
        .tdb-detail { font-size: 0.72rem; color: var(--text-dim); margin-top: 2px; }

        /* ─── TABLES ─── */
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table thead tr { border-bottom: 1px solid var(--border); }
        .data-table th { padding: 10px 12px; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--text-dim); text-align: left; }
        .data-table td { padding: 11px 12px; font-size: 0.8rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
        .data-table tr:last-child td { border-bottom: none; }
        .data-table tr:hover td { background: rgba(59,142,232,0.03); }
        .status-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 20px; font-size: 0.65rem; font-weight: 700; }
        .badge-completed { background: var(--green-dim);  color: var(--green); }
        .badge-pending   { background: var(--orange-dim); color: var(--orange); }
        .badge-cancelled { background: var(--red-dim);    color: var(--red); }
        .badge-accepted  { background: var(--blue-dim);   color: var(--blue); }
        .badge-ongoing   { background: var(--yellow-dim); color: var(--yellow); }
        .filter-bar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; gap: 12px; }
        .filter-search { display: flex; align-items: center; background: var(--surface2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 7px 12px; gap: 7px; flex: 1; }
        .filter-search svg { width: 13px; height: 13px; color: var(--text-dim); flex-shrink: 0; }
        .filter-search input { border: none; background: none; outline: none; color: var(--text); font-family: inherit; font-size: 0.8rem; width: 100%; }
        .filter-search input::placeholder { color: var(--text-dim); }

        /* ─── FARE MATRIX ─── */
        .fare-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-bottom: 16px; }
        .fare-tile { background: var(--surface2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 16px; text-align: center; }
        .fare-tile-value { font-size: 1.4rem; font-weight: 700; color: var(--orange); margin-bottom: 4px; }
        .fare-tile-label { font-size: 0.68rem; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.5px; }

        /* ─── DASH GRID ─── */
        .dash-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

        /* ─── PROFILE ─── */
        .profile-pic-row { display: flex; align-items: center; gap: 20px; padding: 20px; background: var(--surface2); border: 1px solid var(--border); border-radius: var(--radius); margin-bottom: 16px; }
        .avatar-lg { width: 72px; height: 72px; border-radius: 50%; background: linear-gradient(135deg, var(--orange), #8a4010); display: flex; align-items: center; justify-content: center; font-size: 1.4rem; font-weight: 800; color: #fff; border: 2px solid rgba(240,130,40,0.4); overflow: hidden; flex-shrink: 0; }
        .avatar-lg img { width: 100%; height: 100%; object-fit: cover; }
        .avatar-info h4 { font-size: 0.9rem; font-weight: 600; margin-bottom: 3px; }
        .avatar-info p  { font-size: 0.72rem; color: var(--text-dim); margin-bottom: 10px; }
        .file-input-wrapper { position: relative; display: inline-block; }
        .file-input-wrapper input[type="file"] { opacity: 0; position: absolute; left: 0; top: 0; width: 100%; height: 100%; cursor: pointer; }
        .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .field-group { margin-bottom: 0; }
        .field-label { display: block; font-size: 0.65rem; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: var(--text-dim); margin-bottom: 5px; }
        .field { width: 100%; padding: 10px 12px; background: var(--surface2); border: 1px solid var(--border); border-radius: var(--radius-sm); color: var(--text); outline: none; font-family: inherit; font-size: 0.875rem; transition: border-color 0.2s; }
        .field:focus { border-color: var(--blue); box-shadow: 0 0 0 3px var(--blue-glow); }
        .form-section-title { font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--text-dim); padding: 16px 0 10px; border-bottom: 1px solid var(--border); margin-bottom: 12px; }
        .btn { padding: 10px 24px; border: none; border-radius: var(--radius-sm); font-family: inherit; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; }
        .btn-primary   { background: var(--blue); color: #fff; } .btn-primary:hover   { opacity: 0.88; }
        .btn-secondary { background: var(--surface2); color: var(--text); border: 1px solid var(--border); } .btn-secondary:hover { border-color: var(--border-lit); }
        .alert-success { display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: var(--green-dim); border: 1px solid rgba(34,197,94,0.2); border-radius: var(--radius-sm); color: var(--green); font-size: 0.8rem; font-weight: 600; margin-bottom: 16px; }

        /* ─── TOAST ─── */
        .toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%) translateY(80px); padding: 10px 20px; border-radius: 24px; font-size: 0.82rem; font-weight: 600; z-index: 9999; transition: transform 0.35s cubic-bezier(.22,1,.36,1), opacity 0.3s; opacity: 0; pointer-events: none; white-space: nowrap; }
        .toast.show { transform: translateX(-50%) translateY(0); opacity: 1; }
        .toast.green  { background: var(--green);  color: #fff; box-shadow: 0 6px 24px rgba(34,197,94,0.4); }
        .toast.red    { background: #ef4444;        color: #fff; box-shadow: 0 6px 24px rgba(239,68,68,0.4); }
        .toast.blue   { background: var(--blue);   color: #fff; box-shadow: 0 6px 24px rgba(59,142,232,0.4); }
        .toast.orange { background: var(--orange); color: #fff; box-shadow: 0 6px 24px rgba(240,130,40,0.4); }
        .toast.purple { background: var(--purple); color: #fff; box-shadow: 0 6px 24px rgba(168,85,247,0.4); }
        .toast.yellow { background: var(--yellow); color: #000; box-shadow: 0 6px 24px rgba(234,179,8,0.4); }
    </style>
</head>
<body>

<!-- ═══════════════ SIDEBAR ═══════════════ -->
<aside class="sidebar">
    <div class="logo-wrap">
        <img src="../images/logo.png" alt="PasadaNow" class="logo-icon">
        <div class="logo-text"><span>Pasada</span><span>Now</span></div>
    </div>
    <div class="nav-section-label">Driver</div>
    <a href="?page=dashboard"   class="nav-btn <?= $current_page==='dashboard'  ?'active':'' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        Dashboard <span class="nav-dot"></span>
    </a>
    <a href="?page=earnings"    class="nav-btn <?= $current_page==='earnings'   ?'active':'' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        Earnings &amp; History <span class="nav-dot"></span>
    </a>
    <a href="?page=fare_matrix" class="nav-btn <?= $current_page==='fare_matrix'?'active':'' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14,2 14,8 20,8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
        Fare Matrix <span class="nav-dot"></span>
    </a>
    <a href="?page=profile"     class="nav-btn <?= $current_page==='profile'    ?'active':'' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        My Profile <span class="nav-dot"></span>
    </a>
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="sidebar-avatar">
                <?= $profile_pic ? "<img src='$profile_pic' alt=''>" : $initials ?>
            </div>
            <div style="flex:1;min-width:0;">
                <div class="sidebar-user-name"><?= $username ?></div>
                <div class="sidebar-user-role"><?= htmlspecialchars($driver['plate_number'] ?? 'Driver') ?></div>
            </div>
        </div>
        <div class="queue-indicator <?= $is_online ? 'online' : 'offline' ?>" id="sidebar-status">
            <div class="queue-dot <?= $is_online ? 'online' : 'offline' ?>" id="sidebar-dot"></div>
            <span id="sidebar-status-text"><?= $is_online ? 'Online — Accepting Rides' : 'Offline' ?></span>
        </div>
        <div style="margin-top:10px;">
            <a href="logout.php" class="nav-btn danger" style="margin:0;width:100%;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16,17 21,12 16,7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Sign Out
            </a>
        </div>
    </div>
</aside>

<!-- ═══════════════ MAIN ═══════════════ -->
<main class="main">
    <header class="topbar">
        <h2 class="topbar-title">
            <?php
            $titles = [
                'dashboard'   => 'PasadaNow Driver Portal',
                'earnings'    => 'Earnings & History',
                'fare_matrix' => 'Fare Matrix',
                'profile'     => 'Profile Settings'
            ];
            echo $titles[$current_page] ?? 'Driver Portal';
            ?>
        </h2>
        <div class="search-wrap">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" id="driver-search-input" placeholder="Search trips, commuters...">
        </div>
        <button class="search-btn" onclick="doDriverSearch()">Search</button>
        <div class="topbar-meta">
            <span class="topbar-date" id="live-clock"></span>
            <div class="notif-wrap">
                <button class="notif-btn" id="notif-btn" onclick="toggleNotifDropdown()" title="Ride Requests">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                    <span class="notif-badge" id="notif-count">0</span>
                </button>
                <div class="notif-dropdown" id="notif-dropdown">
                    <div class="notif-header">
                        <span class="notif-header-title">Pending Ride Requests</span>
                        <button class="notif-clear-btn" onclick="closeNotifDropdown()">Close</button>
                    </div>
                    <div class="notif-list" id="notif-list">
                        <div class="notif-empty">No pending requests</div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div class="content">

    <!-- ══════════ DASHBOARD ══════════ -->
    <?php if ($current_page === 'dashboard'): ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-card-icon green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
                <div class="stat-card-value">₱<?= number_format($all_time['total'], 2) ?></div>
                <div class="stat-card-label">Total Earned</div>
                <div class="stat-glow green"></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13" rx="2"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg></div>
                <div class="stat-card-value"><?= $all_time['cnt'] ?></div>
                <div class="stat-card-label">Total Trips</div>
                <div class="stat-glow blue"></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon orange"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20V10"/><path d="M18 20V4"/><path d="M6 20v-4"/></svg></div>
                <div class="stat-card-value">₱<?= number_format($today['total'], 2) ?></div>
                <div class="stat-card-label">Today's Earnings</div>
                <div class="stat-glow orange"></div>
            </div>
            <div class="stat-card" id="status-stat-card">
                <div class="stat-card-icon purple"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12,6 12,12 16,14"/></svg></div>
                <div class="stat-card-value" style="font-size:1rem;" id="stat-status-val">
                    <?= $is_online
                        ? '<span style="color:var(--green)">ONLINE</span>'
                        : '<span style="color:var(--text-dim)">OFFLINE</span>' ?>
                </div>
                <div class="stat-card-label">Current Status</div>
            </div>
        </div>

        <!-- Status toggle bar -->
        <div class="status-bar <?= $is_online ? 'is-online' : '' ?>" id="status-bar">
            <div class="status-bar-left">
                <div class="status-big-dot <?= $is_online ? 'online' : 'offline' ?>" id="status-dot"></div>
                <div>
                    <div class="status-label" id="status-label">
                        <?= $is_online ? 'System Online — Accepting Requests' : 'System Offline' ?>
                    </div>
                    <div class="status-sub" id="status-sub">
                        <?= $is_online ? 'Waiting for passenger booking requests...' : 'Toggle online to start receiving rides.' ?>
                    </div>
                </div>
            </div>
            <button onclick="toggleQueue()" class="toggle-btn <?= $is_online ? 'go-offline' : 'go-online' ?>" id="toggle-btn">
                <span class="toggle-spinner"></span>
                <span class="toggle-label"><?= $is_online ? 'Go Offline' : 'Go Online' ?></span>
            </button>
        </div>

        <!-- Active trip banner (changes style per phase) -->
        <div class="active-trip-banner <?= $active_trip ? 'visible state-' . $active_trip['status'] : '' ?>" id="active-trip-banner">
            <div class="abt-left">
                <div class="abt-dot <?= $active_trip ? $active_trip['status'] : 'accepted' ?>" id="abt-dot"></div>
                <div>
                    <div class="abt-label <?= $active_trip ? $active_trip['status'] : 'accepted' ?>" id="abt-label">
                        <?= $active_trip
                            ? ($active_trip['status'] === 'ongoing' ? 'Trip Ongoing — En Route' : 'Active Trip — Picking Up Commuter')
                            : 'Active Trip in Progress' ?>
                    </div>
                    <div class="abt-detail" id="abt-detail">
                        <?php if ($active_trip): ?>
                            <b><?= htmlspecialchars($active_trip['commuter_name']) ?></b> ·
                            <?= htmlspecialchars($active_trip['origin']) ?> →
                            <?= htmlspecialchars($active_trip['destination']) ?> ·
                            <b style="color:var(--green)">₱<?= number_format($active_trip['fare'], 2) ?></b>
                            <?php if (!empty($active_trip['commuter_phone'])): ?>
                                · <span style="color:var(--text-dim)"><?= htmlspecialchars($active_trip['commuter_phone']) ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="abt-road-bar" id="abt-road-bar" <?= ($active_trip && $active_trip['status'] === 'ongoing') ? 'style="display:block;"' : '' ?>></div>
                </div>
            </div>
            <div style="display:flex;gap:8px;flex-shrink:0;">
                <!-- Start Trip button — shown while status=accepted -->
                <button class="btn-start-trip" id="btn-start-trip"
                    style="<?= ($active_trip && $active_trip['status'] === 'accepted') ? 'display:inline-block;' : '' ?>"
                    onclick="startTrip()">
                    🚗 Start Trip
                </button>
                <!-- Complete button — shown while status=ongoing -->
                <button class="btn-complete" id="btn-complete"
                    style="<?= ($active_trip && $active_trip['status'] === 'ongoing') ? '' : 'display:none;' ?>"
                    onclick="completeTrip()">
                    Mark as Completed ✓
                </button>
            </div>
        </div>

        <!-- Trip done banner -->
        <div class="trip-done-banner" id="trip-done-banner">
            <div class="tdb-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20,6 9,17 4,12"/></svg>
            </div>
            <div>
                <div class="tdb-label">Trip Completed Successfully!</div>
                <div class="tdb-detail" id="tdb-detail">You are back online and ready for new rides.</div>
            </div>
        </div>

        <!-- Map -->
        <div class="map-wrap">
            <div id="map"></div>
            <!-- RIDE REQUEST POPUP -->
            <div id="rideCard" class="ride-overlay">
                <div class="ride-overlay-header">
                    <div class="ride-alert-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                    </div>
                    <div>
                        <div class="ride-title">New Ride Request!</div>
                        <div class="ride-subtitle" id="req-commuter">From a commuter</div>
                    </div>
                </div>
                <div class="ride-info-row">
                    <div class="ride-info-block"><div class="ride-info-label">From</div><div class="ride-info-value" id="req-origin">—</div></div>
                    <div class="ride-info-block"><div class="ride-info-label">To</div><div class="ride-info-value" id="req-dest">—</div></div>
                    <div class="ride-info-block" style="text-align:center;"><div class="ride-info-label">Fare</div><div class="ride-fare-big" id="req-fare">—</div></div>
                </div>
                <div class="ride-timer">
                    Auto-decline in <span class="ride-timer-count" id="req-timer">30</span>s
                </div>
                <div class="ride-actions">
                    <button class="btn-accept"  onclick="respondToRide('accepted')">✓ Accept Ride</button>
                    <button class="btn-decline" onclick="respondToRide('declined')">✕ Decline</button>
                </div>
            </div>
        </div>

        <div class="dash-grid">
            <div class="card">
                <div class="card-title">
                    <div class="card-title-dot" style="background:var(--orange);box-shadow:0 0 6px var(--orange);"></div>
                    Vehicle Info
                </div>
                <table class="data-table">
                    <tr><td style="color:var(--text-dim);width:130px;">Plate Number</td><td><b><?= htmlspecialchars($driver['plate_number'] ?? '—') ?></b></td></tr>
                    <tr><td style="color:var(--text-dim);">License No.</td><td><?= htmlspecialchars($driver['license_no'] ?? '—') ?></td></tr>
                    <tr><td style="color:var(--text-dim);">Organization</td><td><?= htmlspecialchars($driver['organization'] ?? '—') ?></td></tr>
                    <tr><td style="color:var(--text-dim);border-bottom:none;">Contact</td><td style="border-bottom:none;"><?= htmlspecialchars($driver['contact_no'] ?? '—') ?></td></tr>
                </table>
            </div>
            <div class="card">
                <div class="card-title">
                    <div class="card-title-dot" style="background:var(--green);box-shadow:0 0 6px var(--green);"></div>
                    Today's Summary
                </div>
                <table class="data-table">
                    <tr><td style="color:var(--text-dim);width:130px;">Trips Today</td><td><b><?= $today['cnt'] ?></b></td></tr>
                    <tr><td style="color:var(--text-dim);">Earned Today</td><td style="color:var(--green);font-weight:700;">₱<?= number_format($today['total'], 2) ?></td></tr>
                    <tr><td style="color:var(--text-dim);">Avg. Fare</td><td>₱<?= $all_time['cnt'] > 0 ? number_format($all_time['total'] / $all_time['cnt'], 2) : '0.00' ?></td></tr>
                    <tr><td style="color:var(--text-dim);border-bottom:none;">All-time</td><td style="border-bottom:none;color:var(--green);font-weight:700;">₱<?= number_format($all_time['total'], 2) ?></td></tr>
                </table>
            </div>
        </div>

    <!-- ══════════ EARNINGS ══════════ -->
    <?php elseif ($current_page === 'earnings'): ?>

        <div class="stats-grid">
            <div class="stat-card"><div class="stat-card-icon green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div><div class="stat-card-value">₱<?= number_format($all_time['total'],2) ?></div><div class="stat-card-label">Total Earned</div><div class="stat-glow green"></div></div>
            <div class="stat-card"><div class="stat-card-icon blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13" rx="2"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg></div><div class="stat-card-value"><?= $all_time['cnt'] ?></div><div class="stat-card-label">Completed Trips</div><div class="stat-glow blue"></div></div>
            <div class="stat-card"><div class="stat-card-icon orange"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20V10"/><path d="M18 20V4"/><path d="M6 20v-4"/></svg></div><div class="stat-card-value">₱<?= number_format($today['total'],2) ?></div><div class="stat-card-label">Today's Earnings</div><div class="stat-glow orange"></div></div>
            <div class="stat-card"><div class="stat-card-icon purple"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22,12 18,12 15,21 9,3 6,12 2,12"/></svg></div><div class="stat-card-value">₱<?= $all_time['cnt']>0?number_format($all_time['total']/$all_time['cnt'],2):'0.00' ?></div><div class="stat-card-label">Avg. Fare / Trip</div></div>
        </div>
        <div class="card">
            <div class="filter-bar">
                <div class="filter-search">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" placeholder="Filter trips..." oninput="filterEarnings(this.value)">
                </div>
                <div class="card-title" style="margin-bottom:0;"><div class="card-title-dot"></div>Trip History</div>
            </div>
            <table class="data-table">
                <thead><tr><th>Date</th><th>Commuter</th><th>Origin</th><th>Destination</th><th>Fare</th><th>Status</th></tr></thead>
                <tbody id="earnings-tbody">
                <?php while ($row = $history_result->fetch_assoc()):
                    $badge = match($row['status']) {
                        'completed' => 'badge-completed',
                        'pending'   => 'badge-pending',
                        'accepted'  => 'badge-accepted',
                        'ongoing'   => 'badge-ongoing',
                        default     => 'badge-cancelled'
                    };
                ?>
                <tr>
                    <td style="color:var(--text-dim)"><?= date('M d, g:i A', strtotime($row['created_at'])) ?></td>
                    <td><?= htmlspecialchars($row['commuter']) ?></td>
                    <td><?= htmlspecialchars($row['origin']) ?></td>
                    <td><?= htmlspecialchars($row['destination']) ?></td>
                    <td style="font-weight:700">₱<?= number_format($row['fare'], 2) ?></td>
                    <td><span class="status-badge <?= $badge ?>">● <?= ucfirst($row['status']) ?></span></td>
                </tr>
                <?php endwhile; ?>
                <?php if ($all_time['cnt'] == 0): ?>
                <tr><td colspan="6" style="text-align:center;color:var(--text-dim);padding:36px;">No trip records yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

    <!-- ══════════ FARE MATRIX ══════════ -->
    <?php elseif ($current_page === 'fare_matrix'): ?>

        <div class="fare-grid">
            <div class="fare-tile"><div class="fare-tile-value">₱15.00</div><div class="fare-tile-label">Base Fare (First 4 km)</div></div>
            <div class="fare-tile"><div class="fare-tile-value">+₱2.00</div><div class="fare-tile-label">Per Succeeding km</div></div>
            <div class="fare-tile"><div class="fare-tile-value">4.0 km</div><div class="fare-tile-label">Minimum Distance</div></div>
        </div>
        <div class="card">
            <div class="card-title">
                <div class="card-title-dot" style="background:var(--orange);box-shadow:0 0 6px var(--orange);"></div>
                Official Fare Schedule (LTFRB)
            </div>
            <table class="data-table">
                <thead><tr><th>Distance</th><th>Base Fare</th><th>Additional</th><th>Total Fare</th></tr></thead>
                <tbody>
                    <tr><td>Up to 4.0 km</td><td style="color:var(--green);font-weight:700;">₱15.00</td><td>—</td><td>₱15.00</td></tr>
                    <tr><td>5 km</td><td>₱15.00</td><td>+₱2.00</td><td>₱17.00</td></tr>
                    <tr><td>8 km</td><td>₱15.00</td><td>+₱8.00</td><td>₱23.00</td></tr>
                    <tr><td>10 km</td><td>₱15.00</td><td>+₱12.00</td><td>₱27.00</td></tr>
                    <tr><td>15 km</td><td>₱15.00</td><td>+₱22.00</td><td style="color:var(--orange);font-weight:700;">₱37.00</td></tr>
                    <tr><td>20 km</td><td>₱15.00</td><td>+₱32.00</td><td style="color:var(--orange);font-weight:700;">₱47.00</td></tr>
                </tbody>
            </table>
            <p style="font-size:0.72rem;color:var(--text-dim);margin-top:14px;">* Formula: ₱15.00 base + (distance − 4) × ₱2.00 per km. Based on official LTFRB guidelines.</p>
        </div>

    <!-- ══════════ PROFILE ══════════ -->
    <?php elseif ($current_page === 'profile'): ?>

        <div style="max-width:700px;">
            <?php if ($message): ?>
            <div class="alert-success">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;flex-shrink:0;"><polyline points="20,6 9,17 4,12"/></svg>
                <?= htmlspecialchars($message) ?>
            </div>
            <?php endif; ?>
            <form method="POST" enctype="multipart/form-data">
                <div class="profile-pic-row">
                    <div class="avatar-lg" id="imagePreview">
                        <?= $profile_pic ? "<img src='$profile_pic' alt=''>" : $initials ?>
                    </div>
                    <div class="avatar-info">
                        <h4><?= $username ?></h4>
                        <p>Partner Driver · <?= htmlspecialchars($driver['plate_number'] ?? 'No plate set') ?></p>
                        <div class="file-input-wrapper">
                            <button type="button" class="btn btn-secondary" style="padding:7px 14px;font-size:0.8rem;">Change Photo</button>
                            <input type="file" name="profile_img" id="profilePicInput" accept="image/*">
                        </div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-title"><div class="card-title-dot"></div>Personal Information</div>
                    <div class="form-grid-2">
                        <div class="field-group">
                            <label class="field-label">Complete Name</label>
                            <input type="text" class="field" name="username" value="<?= htmlspecialchars($driver['username']) ?>" required>
                        </div>
                        <div class="field-group">
                            <label class="field-label">Contact No.</label>
                            <input type="text" class="field" name="contact_no" value="<?= htmlspecialchars($driver['contact_no'] ?? '') ?>">
                        </div>
                    </div>
                    <div style="margin-top:12px;">
                        <label class="field-label">Home Address</label>
                        <input type="text" class="field" name="address" value="<?= htmlspecialchars($driver['address'] ?? '') ?>">
                    </div>
                    <div class="form-section-title">Vehicle &amp; License</div>
                    <div class="form-grid-2">
                        <div class="field-group">
                            <label class="field-label">Branch / TODA / Party</label>
                            <input type="text" class="field" name="organization" value="<?= htmlspecialchars($driver['organization'] ?? '') ?>" placeholder="e.g. Center TODA">
                        </div>
                        <div class="field-group">
                            <label class="field-label">Plate Number</label>
                            <input type="text" class="field" name="plate_number" value="<?= htmlspecialchars($driver['plate_number'] ?? '') ?>">
                        </div>
                        <div class="field-group">
                            <label class="field-label">Driver's License No.</label>
                            <input type="text" class="field" name="license_no" value="<?= htmlspecialchars($driver['license_no'] ?? '') ?>">
                        </div>
                        <div class="field-group">
                            <label class="field-label">New Password <span style="color:var(--text-dim);font-weight:400;">(leave blank to keep)</span></label>
                            <input type="password" class="field" name="new_password" placeholder="••••••••">
                        </div>
                    </div>
                    <div style="display:flex;gap:10px;margin-top:20px;">
                        <button type="submit" name="update_profile" class="btn btn-primary">Save Changes</button>
                        <a href="?page=profile" class="btn btn-secondary">Cancel</a>
                    </div>
                </div>
            </form>
        </div>

    <?php endif; ?>
    </div><!-- /.content -->
</main>

<div class="toast" id="toast"></div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const AJAX_BASE = '<?= htmlspecialchars($ajax_base) ?>';
function ajaxUrl(endpoint) { return AJAX_BASE + '?ajax=' + endpoint; }

/* ══ UTILS ══ */
function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function showToast(msg, type = 'blue') {
    const t = document.getElementById('toast');
    t.textContent = msg; t.className = 'toast ' + type; t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3500);
}

/* ══ SESSION KEEPALIVE ══ */
setInterval(() => fetch(ajaxUrl('session_ping')), 4 * 60 * 1000);

/* ══ LIVE CLOCK ══ */
(function tick() {
    const n = new Date();
    const days   = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    let h = n.getHours(), am = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    document.getElementById('live-clock').textContent =
        `${days[n.getDay()]}, ${months[n.getMonth()]} ${n.getDate()}, ${h}:${String(n.getMinutes()).padStart(2,'0')} ${am}`;
    setTimeout(tick, 60000);
})();

/* ══ NOTIFICATION BELL ══ */
let lastNotifCount = 0;
async function refreshNotifCount() {
    try {
        const res  = await fetch(ajaxUrl('notif_count'));
        const data = await res.json();
        const cnt  = data.count || 0;
        const el   = document.getElementById('notif-count');
        el.textContent = cnt;
        if (cnt > 0) {
            el.classList.add('show');
            if (cnt > lastNotifCount) { el.classList.remove('bump'); void el.offsetWidth; el.classList.add('bump'); }
        } else { el.classList.remove('show'); }
        lastNotifCount = cnt;
    } catch(e) {}
}
async function loadNotifList() {
    try {
        const res  = await fetch(ajaxUrl('notif_list'));
        const data = await res.json();
        const list = document.getElementById('notif-list');
        if (!data.notifs || !data.notifs.length) {
            list.innerHTML = '<div class="notif-empty">No pending ride requests</div>'; return;
        }
        list.innerHTML = data.notifs.map(n => `
            <div class="notif-item">
                <div class="notif-dot-icon"></div>
                <div class="notif-item-body">
                    <div class="notif-item-title">Ride from ${escHtml(n.commuter_name)}</div>
                    <div class="notif-item-sub">${escHtml(n.origin)} → ${escHtml(n.destination)}</div>
                    <div class="notif-item-fare">₱${parseFloat(n.fare).toFixed(2)}</div>
                    <div class="notif-item-time">${new Date(n.created_at).toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit'})}</div>
                </div>
            </div>`).join('');
    } catch(e) {}
}
function toggleNotifDropdown() {
    const dd = document.getElementById('notif-dropdown');
    const open = dd.classList.toggle('open');
    if (open) loadNotifList();
}
function closeNotifDropdown() { document.getElementById('notif-dropdown').classList.remove('open'); }
document.addEventListener('click', e => {
    const wrap = document.querySelector('.notif-wrap');
    if (wrap && !wrap.contains(e.target))
        document.getElementById('notif-dropdown').classList.remove('open');
});

/* ══ SEARCH ══ */
function doDriverSearch() {
    const q = (document.getElementById('driver-search-input').value || '').toLowerCase().trim();
    if (!q) return;
    document.querySelectorAll('#earnings-tbody tr').forEach(r => {
        r.style.display = r.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}
document.getElementById('driver-search-input')?.addEventListener('keypress', e => {
    if (e.key === 'Enter') doDriverSearch();
});
function filterEarnings(q) {
    q = q.toLowerCase();
    document.querySelectorAll('#earnings-tbody tr').forEach(r => {
        r.style.display = r.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}

/* ══ TOGGLE ONLINE / OFFLINE ══ */
let isOnline = <?= $is_online ? 'true' : 'false' ?>;

async function toggleQueue() {
    const btn = document.getElementById('toggle-btn');
    btn.classList.add('loading'); btn.disabled = true;
    try {
        const fd = new FormData();
        fd.append('action', 'toggle_queue');
        fd.append('status', isOnline ? 'leave' : 'join');
        const res  = await fetch(ajaxUrl('toggle_queue'), { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            isOnline = data.is_available === 1;
            applyOnlineState(isOnline);
            showToast(isOnline ? '✓ You are now Online — Accepting rides' : 'You are now Offline', isOnline ? 'green' : 'red');
            if (isOnline) startPolling(); else stopPolling();
        } else { showToast('Failed to update status.', 'red'); }
    } catch(e) { showToast('Network error. Please retry.', 'red'); }
    finally { btn.classList.remove('loading'); btn.disabled = false; }
}

function applyOnlineState(online) {
    const updates = {
        'toggle-btn':          el => { el.className = 'toggle-btn ' + (online ? 'go-offline' : 'go-online'); el.querySelector('.toggle-label').textContent = online ? 'Go Offline' : 'Go Online'; },
        'status-bar':          el => { el.className = 'status-bar' + (online ? ' is-online' : ''); },
        'status-dot':          el => { el.className = 'status-big-dot ' + (online ? 'online' : 'offline'); },
        'status-label':        el => { el.textContent = online ? 'System Online — Accepting Requests' : 'System Offline'; },
        'status-sub':          el => { el.textContent = online ? 'Waiting for passenger booking requests...' : 'Toggle online to start receiving rides.'; },
        'stat-status-val':     el => { el.innerHTML  = online ? '<span style="color:var(--green)">ONLINE</span>' : '<span style="color:var(--text-dim)">OFFLINE</span>'; },
        'sidebar-status':      el => { el.className  = 'queue-indicator ' + (online ? 'online' : 'offline'); },
        'sidebar-dot':         el => { el.className  = 'queue-dot ' + (online ? 'online' : 'offline'); },
        'sidebar-status-text': el => { el.textContent = online ? 'Online — Accepting Rides' : 'Offline'; },
    };
    Object.entries(updates).forEach(([id, fn]) => { const el = document.getElementById(id); if (el) fn(el); });
}

/* ══ MAP (dashboard only) ══ */
<?php if ($current_page === 'dashboard'): ?>

const map = L.map('map', { zoomControl: true }).setView([16.6159, 120.3209], 15);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors', maxZoom: 19
}).addTo(map);
const youIcon = L.divIcon({
    className: '',
    html: `<div style="width:16px;height:16px;background:#f08228;border-radius:50%;border:3px solid white;box-shadow:0 0 0 3px rgba(240,130,40,0.4),0 2px 8px rgba(0,0,0,0.5);"></div>`,
    iconSize: [16, 16], iconAnchor: [8, 8]
});
let driverMarker = null, accuracyCircle = null;
navigator.geolocation?.watchPosition(
    ({ coords: { latitude, longitude, accuracy } }) => {
        const latlng = [latitude, longitude];
        if (driverMarker) driverMarker.setLatLng(latlng);
        else {
            driverMarker = L.marker(latlng, { icon: youIcon }).addTo(map)
                .bindPopup('<b>Your Location</b>').openPopup();
            map.setView(latlng, 16);
        }
        if (accuracyCircle) accuracyCircle.setLatLng(latlng).setRadius(accuracy);
        else accuracyCircle = L.circle(latlng, { radius: accuracy, color:'#3b8ee8', fillColor:'#3b8ee8', fillOpacity:0.08, weight:1 }).addTo(map);
    },
    () => {
        if (!driverMarker)
            driverMarker = L.marker([16.6159, 120.3209], { icon: youIcon }).addTo(map)
                .bindPopup('<b>Your Location</b> (GPS unavailable)').openPopup();
    },
    { enableHighAccuracy: true, maximumAge: 5000, timeout: 10000 }
);

/* ── Polling ── */
let pollInterval  = null;
let timerInterval = null;
let timerSeconds  = 30;
let currentRide   = null;
let activeTripId  = <?= $active_trip ? $active_trip['id'] : 'null' ?>;
let activeTripStatus = '<?= $active_trip ? $active_trip['status'] : '' ?>';

function startPolling() {
    if (pollInterval) clearInterval(pollInterval);
    pollInterval = setInterval(checkForRequest, 3000);
    checkForRequest();
}
function stopPolling() {
    if (pollInterval) { clearInterval(pollInterval); pollInterval = null; }
}

async function checkForRequest() {
    if (!isOnline || activeTripId) return;
    if (document.getElementById('rideCard').classList.contains('visible')) return;
    try {
        const res  = await fetch(ajaxUrl('check_request'));
        const data = await res.json();
        if (data && typeof data === 'object' && data.id) showRideCard(data);
    } catch(e) {}
}

function showRideCard(data) {
    if (currentRide && currentRide.id === data.id) return;
    currentRide = data;
    document.getElementById('req-commuter').textContent = `From: ${escHtml(data.commuter_name)}`;
    document.getElementById('req-origin').textContent   = data.origin;
    document.getElementById('req-dest').textContent     = data.destination;
    document.getElementById('req-fare').textContent     = '₱' + parseFloat(data.fare).toFixed(2);
    const card = document.getElementById('rideCard');
    card.style.display = 'block'; card.classList.add('visible');
    refreshNotifCount();
    timerSeconds = 30;
    document.getElementById('req-timer').textContent = timerSeconds;
    if (timerInterval) { clearInterval(timerInterval); timerInterval = null; }
    timerInterval = setInterval(() => {
        timerSeconds--;
        const el = document.getElementById('req-timer');
        if (el) el.textContent = timerSeconds;
        if (timerSeconds <= 0) {
            clearInterval(timerInterval); timerInterval = null;
            if (currentRide) respondToRide('declined');
        }
    }, 1000);
    showToast('🔔 New ride request!', 'orange');
}

function hideRideCard() {
    const card = document.getElementById('rideCard');
    card.style.display = 'none'; card.classList.remove('visible');
    if (timerInterval) { clearInterval(timerInterval); timerInterval = null; }
    timerSeconds = 0; currentRide = null;
    document.querySelectorAll('.btn-accept, .btn-decline').forEach(b => b.disabled = false);
}

async function respondToRide(action) {
    if (!currentRide || !currentRide.id) return;
    if (timerInterval) { clearInterval(timerInterval); timerInterval = null; }
    const rideSnapshot = { ...currentRide };
    document.querySelectorAll('.btn-accept, .btn-decline').forEach(b => b.disabled = true);
    try {
        const fd = new FormData();
        fd.append('status',  action);
        fd.append('trip_id', rideSnapshot.id);
        const res  = await fetch(ajaxUrl('update_status'), { method: 'POST', body: fd });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();
        if (data.success) {
            hideRideCard();
            refreshNotifCount();
            if (action === 'accepted') {
                activeTripId     = rideSnapshot.id;
                activeTripStatus = 'accepted';
                showActiveTripBanner(rideSnapshot, 'accepted');
                stopPolling();
                applyOnlineState(false);
                const toggleBtn = document.getElementById('toggle-btn');
                if (toggleBtn) toggleBtn.style.display = 'none';
                showToast('✓ Ride accepted! Go pick up the commuter.', 'green');
            } else {
                showToast('Ride declined.', 'red');
            }
        } else {
            showToast('Failed to update. Please retry.', 'red');
            document.querySelectorAll('.btn-accept, .btn-decline').forEach(b => b.disabled = false);
        }
    } catch(e) {
        showToast('Error: ' + e.message, 'red');
        document.querySelectorAll('.btn-accept, .btn-decline').forEach(b => b.disabled = false);
    }
}

/* ── Update active trip banner for accepted vs ongoing states ── */
function showActiveTripBanner(data, tripStatus) {
    const banner = document.getElementById('active-trip-banner');
    banner.className = `active-trip-banner visible state-${tripStatus}`;

    const dot   = document.getElementById('abt-dot');
    const label = document.getElementById('abt-label');
    dot.className   = `abt-dot ${tripStatus}`;
    label.className = `abt-label ${tripStatus}`;
    label.textContent = tripStatus === 'ongoing'
        ? 'Trip Ongoing — En Route'
        : 'Active Trip — Picking Up Commuter';

    const phone = data.commuter_phone ? ` · <span style="color:var(--text-dim)">${escHtml(data.commuter_phone)}</span>` : '';
    document.getElementById('abt-detail').innerHTML =
        `<b>${escHtml(data.commuter_name)}</b> · ${escHtml(data.origin)} → ${escHtml(data.destination)} · <b style="color:var(--green)">₱${parseFloat(data.fare).toFixed(2)}</b>${phone}`;

    /* Road bar: only during ongoing */
    document.getElementById('abt-road-bar').style.display = tripStatus === 'ongoing' ? 'block' : 'none';

    /* Buttons */
    const startBtn    = document.getElementById('btn-start-trip');
    const completeBtn = document.getElementById('btn-complete');
    if (tripStatus === 'accepted') {
        startBtn.style.display    = 'inline-block';
        completeBtn.style.display = 'none';
    } else {
        startBtn.style.display    = 'none';
        completeBtn.style.display = 'inline-block';
        completeBtn.disabled      = false;
        completeBtn.textContent   = 'Mark as Completed ✓';
    }
}

/* ── Start Trip — changes status to 'ongoing' ── */
async function startTrip() {
    if (!activeTripId) return;
    if (!confirm('Start the trip now?')) return;

    const startBtn = document.getElementById('btn-start-trip');
    startBtn.disabled    = true;
    startBtn.textContent = 'Starting...';

    const fd = new FormData();
    fd.append('status',  'ongoing');
    fd.append('trip_id', activeTripId);

    try {
        const res  = await fetch(ajaxUrl('update_status'), { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            activeTripStatus = 'ongoing';
            /* Update banner to ongoing state */
            const currentDetail = {
                commuter_name  : document.getElementById('abt-detail').querySelector('b')?.textContent || '—',
                commuter_phone : '',
                origin         : '—',
                destination    : '—',
                fare           : 0
            };
            /* Re-read from abt-detail best effort */
            showActiveTripBanner({
                commuter_name  : document.getElementById('abt-detail').innerHTML.match(/<b>(.*?)<\/b>/)?.[1] || '—',
                commuter_phone : '',
                origin         : '—',
                destination    : '—',
                fare           : document.getElementById('abt-detail').innerHTML.match(/₱([\d.]+)/)?.[1] || 0
            }, 'ongoing');
            showToast('🚗 Trip started! En route to destination.', 'yellow');
        } else {
            showToast('Failed to start trip. Try again.', 'red');
            startBtn.disabled    = false;
            startBtn.textContent = '🚗 Start Trip';
        }
    } catch(e) {
        showToast('Network error.', 'red');
        startBtn.disabled    = false;
        startBtn.textContent = '🚗 Start Trip';
    }
}

/* ── Complete Trip ── */
async function completeTrip() {
    if (!activeTripId) return;
    if (!confirm('Mark this trip as completed?')) return;

    const completeBtn = document.getElementById('btn-complete');
    if (completeBtn) { completeBtn.disabled = true; completeBtn.textContent = 'Completing...'; }

    const fd = new FormData();
    fd.append('status',  'completed');
    fd.append('trip_id', activeTripId);

    try {
        const res  = await fetch(ajaxUrl('update_status'), { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            /* Hide active trip banner */
            document.getElementById('active-trip-banner').classList.remove('visible');

            /* Show done banner */
            document.getElementById('trip-done-banner').classList.add('visible');

            /* Reset state */
            activeTripId     = null;
            activeTripStatus = '';

            /* Restore driver online */
            isOnline = true;
            applyOnlineState(true);
            const toggleBtn = document.getElementById('toggle-btn');
            if (toggleBtn) { toggleBtn.style.display = ''; }

            showToast('✓ Trip completed! Back online now.', 'purple');

            /* Resume polling */
            startPolling();

            /* Auto-hide done banner after 8s */
            setTimeout(() => {
                document.getElementById('trip-done-banner').classList.remove('visible');
            }, 8000);

        } else {
            showToast('Failed to complete trip. Try again.', 'red');
            if (completeBtn) { completeBtn.disabled = false; completeBtn.textContent = 'Mark as Completed ✓'; }
        }
    } catch(e) {
        showToast('Network error.', 'red');
        if (completeBtn) { completeBtn.disabled = false; completeBtn.textContent = 'Mark as Completed ✓'; }
    }
}

/* ── Init polling ── */
if (isOnline && !activeTripId) startPolling();

setInterval(refreshNotifCount, 5000);
refreshNotifCount();

<?php else: ?>
setInterval(refreshNotifCount, 8000);
refreshNotifCount();
<?php endif; ?>

/* ══ PROFILE PIC PREVIEW ══ */
const picInput = document.getElementById('profilePicInput');
if (picInput) {
    picInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = () => {
            document.getElementById('imagePreview').innerHTML =
                `<img src="${reader.result}" alt="" style="width:100%;height:100%;object-fit:cover;">`;
        };
        reader.readAsDataURL(file);
    });
}
</script>
</body>
</html>