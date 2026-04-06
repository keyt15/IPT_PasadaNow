<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    if (isset($_GET['action']) && $_GET['action'] === 'search') {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }
    header("Location: ../login.php?error=unauthorized");
    exit();
}

require_once '../../backend/config.php';

// ══════════════════════════════════════════════
//  AJAX HANDLERS
// ══════════════════════════════════════════════
if (isset($_GET['action'])) {

    // ── Global Search ──
    if ($_GET['action'] === 'search') {
        header('Content-Type: application/json');
        $q   = trim($_GET['q']   ?? '');
        $tab = trim($_GET['tab'] ?? 'all');
        if ($q === '') { echo json_encode(['results' => []]); exit(); }
        $like = '%' . $conn->real_escape_string($q) . '%';
        $roleWhere = $tab === 'commuter' ? "AND role='commuter'" : ($tab === 'driver' ? "AND role='driver'" : "AND role IN ('commuter','driver')");
        $sql = "SELECT * FROM users WHERE (username LIKE ? OR email LIKE ? OR phone LIKE ? OR plate_number LIKE ? OR organization LIKE ? OR license_no LIKE ? OR address LIKE ?) $roleWhere ORDER BY created_at DESC LIMIT 50";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sssssss', $like,$like,$like,$like,$like,$like,$like);
        $stmt->execute();
        $rows = []; $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) $rows[] = $row;
        $stmt->close();
        echo json_encode(['results' => $rows]); exit();
    }

    // ── Trip Records Data ──
    if ($_GET['action'] === 'trips') {
        header('Content-Type: application/json');
        $status = $_GET['status'] ?? '';
        $search = trim($_GET['search'] ?? '');
        $page   = max(1, intval($_GET['page'] ?? 1));
        $limit  = 15; $offset = ($page - 1) * $limit;
        $where  = []; $params = []; $types = '';
        if ($status && in_array($status, ['completed','pending','cancelled','active'])) { $where[] = "b.status=?"; $params[] = $status; $types .= 's'; }
        if ($search) { $like = '%'.$conn->real_escape_string($search).'%'; $where[] = "(c.username LIKE ? OR d.username LIKE ? OR b.origin LIKE ? OR b.destination LIKE ?)"; $params=array_merge($params,[$like,$like,$like,$like]); $types.='ssss'; }
        $ws = $where ? 'WHERE '.implode(' AND ',$where) : '';
        $cnt_sql = "SELECT COUNT(*) AS cnt FROM bookings b LEFT JOIN users c ON b.commuter_id=c.id LEFT JOIN users d ON b.driver_id=d.id $ws";
        $total = 0;
        if ($params) { $st=$conn->prepare($cnt_sql); $st->bind_param($types,...$params); $st->execute(); $total=$st->get_result()->fetch_assoc()['cnt']; $st->close(); }
        else { $r=$conn->query($cnt_sql); if($r) $total=$r->fetch_assoc()['cnt']; }
        $data_sql = "SELECT b.id,b.origin,b.destination,b.fare,b.status,b.created_at,c.username AS commuter_name,d.username AS driver_name FROM bookings b LEFT JOIN users c ON b.commuter_id=c.id LEFT JOIN users d ON b.driver_id=d.id $ws ORDER BY b.created_at DESC LIMIT ? OFFSET ?";
        $ap = array_merge($params,[$limit,$offset]); $at = $types.'ii';
        $st=$conn->prepare($data_sql); $st->bind_param($at,...$ap); $st->execute();
        $rows=[]; $res=$st->get_result(); while($row=$res->fetch_assoc()) $rows[]=$row; $st->close();
        $stats=['total'=>0,'completed'=>0,'pending'=>0,'cancelled'=>0,'revenue'=>0];
        $r=$conn->query("SELECT COUNT(*) AS c FROM bookings"); if($r) $stats['total']=$r->fetch_assoc()['c'];
        $r=$conn->query("SELECT COUNT(*) AS c FROM bookings WHERE status='completed'"); if($r) $stats['completed']=$r->fetch_assoc()['c'];
        $r=$conn->query("SELECT COUNT(*) AS c FROM bookings WHERE status='pending'"); if($r) $stats['pending']=$r->fetch_assoc()['c'];
        $r=$conn->query("SELECT COUNT(*) AS c FROM bookings WHERE status='cancelled'"); if($r) $stats['cancelled']=$r->fetch_assoc()['c'];
        $r=$conn->query("SELECT SUM(fare) AS t FROM bookings WHERE status='completed'"); if($r) $stats['revenue']=$r->fetch_assoc()['t']??0;
        echo json_encode(['rows'=>$rows,'total'=>$total,'pages'=>max(1,ceil($total/$limit)),'stats'=>$stats]); exit();
    }

    // ── Commuters Data ──
    if ($_GET['action'] === 'commuters') {
        header('Content-Type: application/json');
        $search = trim($_GET['search'] ?? '');
        $page   = max(1, intval($_GET['page'] ?? 1));
        $limit  = 15; $offset = ($page-1)*$limit;
        $ws = "WHERE role='commuter'"; $params=[]; $types='';
        if ($search) { $like='%'.$conn->real_escape_string($search).'%'; $ws.=" AND (username LIKE ? OR email LIKE ? OR contact_no LIKE ? OR address LIKE ?)"; $params=[$like,$like,$like,$like]; $types='ssss'; }
        $total=0;
        if ($params) { $st=$conn->prepare("SELECT COUNT(*) AS c FROM users $ws"); $st->bind_param($types,...$params); $st->execute(); $total=$st->get_result()->fetch_assoc()['c']; $st->close(); }
        else { $r=$conn->query("SELECT COUNT(*) AS c FROM users $ws"); if($r) $total=$r->fetch_assoc()['c']; }
        $ap=array_merge($params,[$limit,$offset]); $at=$types.'ii';
        $st=$conn->prepare("SELECT * FROM users $ws ORDER BY created_at DESC LIMIT ? OFFSET ?"); $st->bind_param($at,...$ap); $st->execute();
        $rows=[]; $res=$st->get_result(); while($row=$res->fetch_assoc()) $rows[]=$row; $st->close();
        $stats=['total'=>0,'today'=>0,'active'=>0];
        $r=$conn->query("SELECT COUNT(*) AS c FROM users WHERE role='commuter'"); if($r) $stats['total']=$r->fetch_assoc()['c'];
        $r=$conn->query("SELECT COUNT(*) AS c FROM users WHERE role='commuter' AND DATE(created_at)=CURDATE()"); if($r) $stats['today']=$r->fetch_assoc()['c'];
        $r=$conn->query("SELECT COUNT(DISTINCT commuter_id) AS c FROM bookings WHERE DATE(created_at)=CURDATE()"); if($r) $stats['active']=$r->fetch_assoc()['c'];
        echo json_encode(['rows'=>$rows,'total'=>$total,'pages'=>max(1,ceil($total/$limit)),'stats'=>$stats]); exit();
    }

    // ── Drivers Data ──
    if ($_GET['action'] === 'drivers') {
        header('Content-Type: application/json');
        $search = trim($_GET['search'] ?? '');
        $page   = max(1, intval($_GET['page'] ?? 1));
        $limit  = 15; $offset = ($page-1)*$limit;
        $ws = "WHERE role='driver'"; $params=[]; $types='';
        if ($search) { $like='%'.$conn->real_escape_string($search).'%'; $ws.=" AND (username LIKE ? OR email LIKE ? OR contact_no LIKE ? OR plate_number LIKE ? OR organization LIKE ? OR license_no LIKE ?)"; $params=[$like,$like,$like,$like,$like,$like]; $types='ssssss'; }
        $total=0;
        if ($params) { $st=$conn->prepare("SELECT COUNT(*) AS c FROM users $ws"); $st->bind_param($types,...$params); $st->execute(); $total=$st->get_result()->fetch_assoc()['c']; $st->close(); }
        else { $r=$conn->query("SELECT COUNT(*) AS c FROM users $ws"); if($r) $total=$r->fetch_assoc()['c']; }
        $ap=array_merge($params,[$limit,$offset]); $at=$types.'ii';
        $st=$conn->prepare("SELECT * FROM users $ws ORDER BY created_at DESC LIMIT ? OFFSET ?"); $st->bind_param($at,...$ap); $st->execute();
        $rows=[]; $res=$st->get_result(); while($row=$res->fetch_assoc()) $rows[]=$row; $st->close();
        $stats=['total'=>0,'active'=>0,'today'=>0,'revenue'=>0];
        $r=$conn->query("SELECT COUNT(*) AS c FROM users WHERE role='driver'"); if($r) $stats['total']=$r->fetch_assoc()['c'];
        $r=$conn->query("SELECT COUNT(DISTINCT driver_id) AS c FROM bookings WHERE DATE(created_at)=CURDATE() AND driver_id IS NOT NULL"); if($r) $stats['active']=$r->fetch_assoc()['c'];
        $r=$conn->query("SELECT COUNT(*) AS c FROM users WHERE role='driver' AND DATE(created_at)=CURDATE()"); if($r) $stats['today']=$r->fetch_assoc()['c'];
        $r=$conn->query("SELECT SUM(fare) AS t FROM bookings WHERE status='completed'"); if($r) $stats['revenue']=$r->fetch_assoc()['t']??0;
        echo json_encode(['rows'=>$rows,'total'=>$total,'pages'=>max(1,ceil($total/$limit)),'stats'=>$stats]); exit();
    }
}

// ══════════════════════════════════════════════
//  OVERVIEW STATS
// ══════════════════════════════════════════════
$admin_name = htmlspecialchars($_SESSION['username']);
$initials   = strtoupper(substr($admin_name, 0, 1));

$total_bookings = 0;
$r = $conn->query("SELECT COUNT(*) AS c FROM bookings");
if ($r) $total_bookings = $r->fetch_assoc()['c'];

$active_drivers = 0;
$r = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role='driver'");
if ($r) $active_drivers = $r->fetch_assoc()['c'];

$total_commuters = 0;
$r = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role='commuter'");
if ($r) $total_commuters = $r->fetch_assoc()['c'];

$daily_earnings = 0;
$r = $conn->query("SELECT SUM(fare) AS t FROM bookings WHERE DATE(created_at)=CURDATE() AND status='completed'");
if ($r) $daily_earnings = $r->fetch_assoc()['t'] ?? 0;

$recent_trips = [];
$r = $conn->query("SELECT b.id,b.origin,b.destination,b.fare,b.status,b.created_at,c.username AS commuter_name,d.username AS driver_name FROM bookings b LEFT JOIN users c ON b.commuter_id=c.id LEFT JOIN users d ON b.driver_id=d.id ORDER BY b.created_at DESC LIMIT 10");
if ($r) while ($row = $r->fetch_assoc()) $recent_trips[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PasadaNow — Admin Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0a1628; --surface: #0f1f35; --surface2: #132540; --surface3: #172c4a;
            --border: rgba(99,160,220,0.15); --border-lit: rgba(99,160,220,0.35);
            --blue: #3b8ee8; --blue-dim: rgba(59,142,232,0.12); --blue-glow: rgba(59,142,232,0.25);
            --orange: #f08228; --orange-dim: rgba(240,130,40,0.12);
            --green: #22c55e; --green-dim: rgba(34,197,94,0.12);
            --red: #ef4444; --red-dim: rgba(239,68,68,0.12);
            --yellow: #f0a500; --yellow-dim: rgba(240,165,0,0.12);
            --purple: #a855f7; --purple-dim: rgba(168,85,247,0.12);
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
        .sidebar-user-name { font-size: 0.8rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sidebar-user-role { font-size: 0.65rem; color: var(--text-dim); }
        .signout-btn { display: flex; align-items: center; gap: 8px; color: var(--red); font-size: 0.8rem; font-weight: 500; text-decoration: none; padding: 6px 8px; border-radius: 6px; transition: background 0.2s; }
        .signout-btn:hover { background: rgba(239,68,68,0.08); }

        /* ─── MAIN ─── */
        .main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
        .topbar { padding: 14px 28px; background: var(--surface); border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 16px; flex-shrink: 0; }
        .topbar-title { font-size: 1.1rem; font-weight: 700; flex-shrink: 0; }
        .topbar-center { flex: 1; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .search-wrap { display: flex; align-items: center; background: var(--surface2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 7px 14px; gap: 8px; width: 360px; position: relative; }
        .search-wrap svg { width: 14px; height: 14px; color: var(--text-dim); flex-shrink: 0; }
        .search-wrap input { border: none; background: none; outline: none; color: var(--text); font-family: inherit; font-size: 0.8rem; width: 100%; }
        .search-wrap input::placeholder { color: var(--text-dim); }
        .search-wrap:focus-within { border-color: var(--blue); }
        .search-btn { background: var(--blue); color: #fff; border: none; border-radius: var(--radius-sm); padding: 7px 16px; font-size: 0.8rem; font-weight: 600; cursor: pointer; font-family: inherit; flex-shrink: 0; }
        .search-btn:hover { opacity: 0.88; }
        .topbar-meta { display: flex; align-items: center; gap: 16px; }
        .topbar-date { font-size: 0.78rem; color: var(--text-dim); white-space: nowrap; }
        .notif-btn { position: relative; background: var(--surface2); border: 1px solid var(--border); border-radius: var(--radius-sm); width: 34px; height: 34px; display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--text-dim); }
        .notif-btn svg { width: 16px; height: 16px; }
        .notif-badge { position: absolute; top: -4px; right: -4px; background: var(--orange); color: #fff; font-size: 0.5rem; font-weight: 700; width: 14px; height: 14px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }

        /* ─── SEARCH DROPDOWN ─── */
        .search-dropdown { position: absolute; top: calc(100% + 10px); left: 0; width: 480px; background: var(--surface); border: 1px solid var(--border-lit); border-radius: var(--radius); box-shadow: 0 20px 50px rgba(0,0,0,0.6); z-index: 9999; overflow: hidden; display: none; animation: fadeUp 0.17s ease; }
        .search-dropdown.open { display: block; }
        .sd-tabs { display: flex; border-bottom: 1px solid var(--border); }
        .sd-tab { flex: 1; padding: 9px 0; text-align: center; font-size: 0.7rem; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase; color: var(--text-dim); cursor: pointer; transition: all 0.15s; border-bottom: 2px solid transparent; }
        .sd-tab.active { color: var(--blue); border-bottom-color: var(--blue); }
        .sd-body { max-height: 300px; overflow-y: auto; }
        .sd-body::-webkit-scrollbar { width: 4px; }
        .sd-body::-webkit-scrollbar-thumb { background: var(--border-lit); border-radius: 2px; }
        .sd-state { padding: 30px 20px; text-align: center; color: var(--text-dim); font-size: 0.78rem; }
        .sd-row { display: flex; align-items: center; gap: 12px; padding: 11px 16px; border-bottom: 1px solid var(--border); cursor: pointer; transition: background 0.15s; }
        .sd-row:hover { background: var(--blue-dim); }
        .sd-avatar { width: 34px; height: 34px; border-radius: 50%; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 0.72rem; font-weight: 700; color: #fff; }
        .sd-avatar.commuter { background: linear-gradient(135deg, var(--blue), #1a5fa8); }
        .sd-avatar.driver   { background: linear-gradient(135deg, var(--orange), #b85e10); }
        .sd-info { flex: 1; min-width: 0; }
        .sd-name { font-size: 0.82rem; font-weight: 600; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sd-sub  { font-size: 0.7rem; color: var(--text-dim); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        mark { background: rgba(59,142,232,0.25); color: var(--blue); border-radius: 2px; padding: 0 1px; }
        .sd-meta { text-align: right; flex-shrink: 0; }
        .role-badge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 0.62rem; font-weight: 700; text-transform: uppercase; }
        .role-badge.commuter { background: var(--blue-dim); color: var(--blue); }
        .role-badge.driver   { background: var(--orange-dim); color: var(--orange); }
        .sd-id { font-size: 0.64rem; color: var(--text-dim); margin-top: 3px; }
        .sd-footer { padding: 7px 16px; background: var(--surface2); border-top: 1px solid var(--border); font-size: 0.68rem; color: var(--text-dim); display: flex; align-items: center; justify-content: space-between; }

        /* ─── CONTENT ─── */
        .content { flex: 1; overflow-y: auto; padding: 24px 28px; }
        .content::-webkit-scrollbar { width: 4px; }
        .content::-webkit-scrollbar-thumb { background: var(--border-lit); border-radius: 4px; }
        .view-section { display: none; animation: fadeUp 0.25s ease; }
        .view-section.active { display: block; }
        @keyframes fadeUp { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.4} }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ─── STAT CARDS ─── */
        .stats-grid { display: grid; gap: 14px; margin-bottom: 20px; }
        .stats-grid-4 { grid-template-columns: repeat(4,1fr); }
        .stats-grid-3 { grid-template-columns: repeat(3,1fr); }
        .stats-grid-5 { grid-template-columns: repeat(5,1fr); }
        .stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 18px 20px; position: relative; overflow: hidden; transition: border-color 0.2s; }
        .stat-card:hover { border-color: var(--border-lit); }
        .stat-card-icon { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-bottom: 14px; }
        .stat-card-icon svg { width: 18px; height: 18px; }
        .stat-card-icon.blue   { background: var(--blue-dim);   color: var(--blue); }
        .stat-card-icon.orange { background: var(--orange-dim); color: var(--orange); }
        .stat-card-icon.green  { background: var(--green-dim);  color: var(--green); }
        .stat-card-icon.yellow { background: var(--yellow-dim); color: var(--yellow); }
        .stat-card-icon.red    { background: var(--red-dim);    color: var(--red); }
        .stat-card-icon.purple { background: var(--purple-dim); color: var(--purple); }
        .stat-card-value { font-size: 1.6rem; font-weight: 700; line-height: 1; margin-bottom: 4px; }
        .stat-card-label { font-size: 0.7rem; color: var(--text-dim); font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-card-glow { position: absolute; bottom: -20px; right: -20px; width: 80px; height: 80px; border-radius: 50%; opacity: 0.07; }
        .stat-card-glow.blue   { background: var(--blue); }
        .stat-card-glow.orange { background: var(--orange); }
        .stat-card-glow.green  { background: var(--green); }
        .stat-card-glow.yellow { background: var(--yellow); }
        .stat-card-glow.red    { background: var(--red); }
        .stat-card-glow.purple { background: var(--purple); }

        /* ─── OVERVIEW BOTTOM GRID ─── */
        .dashboard-grid { display: grid; grid-template-columns: 1.4fr 0.6fr; gap: 16px; }

        /* ─── CARDS ─── */
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
        .card-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--border); flex-wrap: wrap; gap: 10px; }
        .card-title { font-size: 0.875rem; font-weight: 600; color: var(--text); display: flex; align-items: center; gap: 8px; }
        .card-title-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--blue); box-shadow: 0 0 6px var(--blue); flex-shrink: 0; }
        .card-body { padding: 20px; }

        /* ─── TABLE ─── */
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
        thead th { padding: 10px 16px; text-align: left; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--text-dim); background: var(--surface2); position: sticky; top: 0; z-index: 1; white-space: nowrap; }
        tbody tr { border-bottom: 1px solid var(--border); transition: background 0.15s; }
        tbody tr:last-child { border-bottom: none; }
        tbody tr:hover { background: rgba(59,142,232,0.04); }
        tbody td { padding: 11px 16px; color: var(--text-dim); vertical-align: middle; }

        /* ─── STATUS BADGES ─── */
        .status-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 20px; font-size: 0.65rem; font-weight: 700; }
        .badge-completed { background: var(--green-dim);        color: var(--green); }
        .badge-pending   { background: var(--yellow-dim);       color: var(--yellow); }
        .badge-cancelled { background: rgba(239,68,68,0.1);     color: var(--red); }
        .badge-active    { background: var(--blue-dim);         color: var(--blue); }

        /* ─── FILTER BAR ─── */
        .filter-bar { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; }
        .filter-input { background: var(--surface2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 8px 12px; font-family: inherit; font-size: 0.82rem; color: var(--text); outline: none; transition: border-color 0.2s; flex: 1; min-width: 180px; }
        .filter-input::placeholder { color: var(--text-dim); }
        .filter-input:focus { border-color: var(--blue); }
        .filter-select { background: var(--surface2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 8px 12px; font-family: inherit; font-size: 0.82rem; color: var(--text); outline: none; cursor: pointer; }
        .btn { border: none; border-radius: var(--radius-sm); padding: 8px 16px; font-family: inherit; font-size: 0.8rem; font-weight: 600; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; }
        .btn-primary   { background: var(--blue); color: #fff; }
        .btn-primary:hover { opacity: 0.88; }
        .btn-ghost { background: var(--surface2); color: var(--text-dim); border: 1px solid var(--border); }
        .btn-ghost:hover { border-color: var(--border-lit); color: var(--text); }

        /* ─── PAGINATION ─── */
        .pagination { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-top: 1px solid var(--border); }
        .page-info { font-size: 0.72rem; color: var(--text-dim); }
        .page-btns { display: flex; gap: 5px; }
        .page-btn { background: var(--surface2); border: 1px solid var(--border); border-radius: 6px; padding: 4px 10px; font-size: 0.72rem; color: var(--text-dim); cursor: pointer; font-family: inherit; transition: all 0.2s; }
        .page-btn:hover, .page-btn.active { background: var(--blue); border-color: var(--blue); color: #fff; }
        .page-btn.disabled { opacity: 0.3; pointer-events: none; }

        /* ─── MISC ─── */
        .user-cell { display: flex; align-items: center; gap: 10px; }
        .mini-avatar { width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.65rem; font-weight: 700; color: #fff; flex-shrink: 0; }
        .mini-avatar.commuter { background: linear-gradient(135deg, var(--blue), #1a5fa8); }
        .mini-avatar.driver   { background: linear-gradient(135deg, var(--orange), #b85e10); }
        .plate-badge { display: inline-block; background: var(--orange-dim); border: 1px solid rgba(240,130,40,0.3); color: var(--orange); border-radius: 6px; padding: 2px 8px; font-size: 0.7rem; font-weight: 700; }
        .empty-state { text-align: center; padding: 40px; color: var(--text-dim); font-size: 0.82rem; }
        .spinner-inline { width: 18px; height: 18px; border: 2px solid var(--border-lit); border-top-color: var(--blue); border-radius: 50%; animation: spin 0.65s linear infinite; margin: 20px auto; display: block; }
        .link-btn { background: none; border: none; cursor: pointer; color: var(--blue); font-size: 0.75rem; font-family: inherit; padding: 0; }
        .link-btn:hover { opacity: 0.8; }

        /* ─── FLEET MINI CARD ─── */
        .fleet-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 18px 20px; }
        .fleet-row { display: flex; justify-content: space-between; align-items: center; font-size: 0.8rem; padding: 8px 0; border-bottom: 1px solid var(--border); }
        .fleet-row:last-child { border-bottom: none; }
        .fleet-label { color: var(--text-dim); }
        .fleet-value { font-weight: 700; }
        .form-section-title { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--text-dim); margin: 18px 0 12px; padding-bottom: 8px; border-bottom: 1px solid var(--border); }

        .search-clear { background: none; border: none; cursor: pointer; color: var(--text-dim); padding: 2px 4px; border-radius: 4px; display: none; align-items: center; transition: color 0.2s; }
        .search-clear:hover { color: var(--red); }
        .search-clear.visible { display: flex; }
    </style>
</head>
<body>

<!-- ═══════════════ SIDEBAR ═══════════════ -->
<aside class="sidebar">
    <div class="logo-wrap">
        <img src="../images/logo.png" alt="PasadaNow Logo" class="logo-icon">
        <div class="logo-text"><span>Pasada</span><span>Now</span></div>
    </div>
    <div class="nav-section-label">Admin Control</div>
    <button class="nav-btn active" id="nav-overview" onclick="switchView('overview', this)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        Overview <span class="nav-dot"></span>
    </button>
    <button class="nav-btn" id="nav-trips" onclick="switchView('trips', this)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12,6 12,12 16,14"/></svg>
        Trip Records <span class="nav-dot"></span>
    </button>
    <button class="nav-btn" id="nav-commuters" onclick="switchView('commuters', this)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
        Commuters <span class="nav-dot"></span>
    </button>
    <button class="nav-btn" id="nav-drivers" onclick="switchView('drivers', this)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <path d="M0.5 9.5 Q5 6.5 11 8 Q15 7 19.5 8.5" stroke-linecap="round" stroke-linejoin="round"/>
            <rect x="1" y="9.5" width="9" height="5.5" rx="1"/>
            <path d="M10 9 L15.5 9 L17.5 11.5 L17.5 15 L10 15 Z"/>
            <line x1="17.5" y1="9.5" x2="19.5" y2="8.5" stroke-linecap="round"/>
            <line x1="19.5" y1="8.5" x2="20.5" y2="12" stroke-linecap="round"/>
            <circle cx="4" cy="18.5" r="2.8"/>
            <circle cx="4" cy="18.5" r="1.1"/>
            <line x1="4" y1="15.7" x2="4" y2="21.3" stroke-width="0.7"/>
            <line x1="1.2" y1="18.5" x2="6.8" y2="18.5" stroke-width="0.7"/>
            <circle cx="13" cy="18.5" r="2.8"/>
            <circle cx="13" cy="18.5" r="1.1"/>
            <line x1="13" y1="15.7" x2="13" y2="21.3" stroke-width="0.7"/>
            <line x1="10.2" y1="18.5" x2="15.8" y2="18.5" stroke-width="0.7"/>
            <circle cx="20.5" cy="18.5" r="2.8"/>
            <circle cx="20.5" cy="18.5" r="1.1"/>
            <line x1="20.5" y1="15.7" x2="20.5" y2="21.3" stroke-width="0.7"/>
            <line x1="17.7" y1="18.5" x2="23.3" y2="18.5" stroke-width="0.7"/>
        </svg>
        Partner Drivers <span class="nav-dot"></span>
    </button>
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="sidebar-avatar"><?php echo $initials; ?></div>
            <div style="flex:1;min-width:0;">
                <div class="sidebar-user-name"><?php echo $admin_name; ?></div>
                <div class="sidebar-user-role">System Admin</div>
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
        <h2 class="topbar-title" id="view-title">PasadaNow Command Center</h2>
        <div class="topbar-center">
            <div class="search-wrap" id="searchWrap">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="globalSearch" placeholder="Search plate, license, name, or TODA..." autocomplete="off">
                <button class="search-clear" id="searchClear">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
                <div class="search-dropdown" id="searchDropdown">
                    <div class="sd-tabs">
                        <div class="sd-tab active" data-tab="all">All Users</div>
                        <div class="sd-tab" data-tab="commuter">Commuters</div>
                        <div class="sd-tab" data-tab="driver">Drivers</div>
                    </div>
                    <div class="sd-body" id="sdBody"><div class="sd-state">Type to search the fleet database...</div></div>
                    <div class="sd-footer" id="sdFooter" style="display:none">
                        <span id="sdCount">0 results</span><span>pasadanow · registry</span>
                    </div>
                </div>
            </div>
            <button class="search-btn" id="searchSubmit">Search</button>
        </div>
        <div class="topbar-meta">
            <span class="topbar-date" id="live-clock"></span>
            <button class="notif-btn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                <span class="notif-badge">0</span>
            </button>
        </div>
    </header>

    <div class="content">

        <!-- ─── OVERVIEW ─── -->
        <section id="view-overview" class="view-section active">
            <div class="stats-grid stats-grid-4">
                <div class="stat-card">
                    <div class="stat-card-icon blue">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M0.5 9.5 Q5 6.5 11 8 Q15 7 19.5 8.5" stroke-linecap="round" stroke-linejoin="round"/>
                            <rect x="1" y="9.5" width="9" height="5.5" rx="1"/>
                            <path d="M10 9 L15.5 9 L17.5 11.5 L17.5 15 L10 15 Z"/>
                            <circle cx="4" cy="18.5" r="2.8"/>
                            <circle cx="4" cy="18.5" r="1.1"/>
                            <circle cx="13" cy="18.5" r="2.8"/>
                            <circle cx="13" cy="18.5" r="1.1"/>
                            <circle cx="20.5" cy="18.5" r="2.8"/>
                            <circle cx="20.5" cy="18.5" r="1.1"/>
                        </svg>
                    </div>
                    <div class="stat-card-value"><?php echo number_format($total_bookings); ?></div>
                    <div class="stat-card-label">Total Bookings</div>
                    <div class="stat-card-glow blue"></div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-icon green">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
                    </div>
                    <div class="stat-card-value"><?php echo number_format($active_drivers); ?></div>
                    <div class="stat-card-label">Active Drivers</div>
                    <div class="stat-card-glow green"></div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-icon purple">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                    </div>
                    <div class="stat-card-value"><?php echo number_format($total_commuters); ?></div>
                    <div class="stat-card-label">Total Commuters</div>
                    <div class="stat-card-glow purple"></div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-icon orange">
                        <span style="font-size:1.1rem;font-weight:800;font-family:inherit;">₱</span>
                    </div>
                    <div class="stat-card-value">₱<?php echo number_format($daily_earnings, 2); ?></div>
                    <div class="stat-card-label">Daily Earnings</div>
                    <div class="stat-card-glow orange"></div>
                </div>
            </div>

            <div class="dashboard-grid">
                <div class="card">
                    <div class="card-header">
                        <div class="card-title"><div class="card-title-dot"></div>Recent Trips</div>
                        <button class="link-btn" onclick="switchView('trips', document.getElementById('nav-trips'))">View All History →</button>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Trip ID</th><th>Commuter</th><th>Driver</th><th>Origin</th><th>Destination</th><th>Fare</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php if (empty($recent_trips)): ?>
                            <tr><td colspan="7" class="empty-state">No trips found.</td></tr>
                            <?php else: foreach ($recent_trips as $t):
                                $badge = match(strtolower($t['status'])) {
                                    'completed' => 'badge-completed',
                                    'pending'   => 'badge-pending',
                                    'cancelled' => 'badge-cancelled',
                                    default     => 'badge-active'
                                };
                            ?>
                            <tr>
                                <td style="font-weight:700;color:var(--blue)">#<?php echo $t['id']; ?></td>
                                <td style="color:var(--text)"><?php echo htmlspecialchars($t['commuter_name'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($t['driver_name'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($t['origin']); ?></td>
                                <td><?php echo htmlspecialchars($t['destination']); ?></td>
                                <td style="color:var(--green);font-weight:600">₱<?php echo number_format($t['fare'], 2); ?></td>
                                <td><span class="status-badge <?php echo $badge; ?>">● <?php echo ucfirst($t['status']); ?></span></td>
                            </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="fleet-card">
                    <div class="card-title" style="margin-bottom:0;font-size:0.875rem;font-weight:600;display:flex;align-items:center;gap:8px;">
                        <div class="card-title-dot" style="background:var(--green);box-shadow:0 0 6px var(--green);"></div>Fleet Summary
                    </div>
                    <div class="form-section-title" style="margin-top:16px;">System Overview</div>
                    <div class="fleet-row"><span class="fleet-label">Total Drivers</span><span class="fleet-value"><?php echo $active_drivers; ?></span></div>
                    <div class="fleet-row"><span class="fleet-label">Total Commuters</span><span class="fleet-value"><?php echo $total_commuters; ?></span></div>
                    <div class="fleet-row"><span class="fleet-label">Total Bookings</span><span class="fleet-value"><?php echo $total_bookings; ?></span></div>
                    <div class="fleet-row"><span class="fleet-label">Daily Revenue</span><span class="fleet-value" style="color:var(--green)">₱<?php echo number_format($daily_earnings, 2); ?></span></div>
                </div>
            </div>
        </section>

        <!-- ─── TRIP RECORDS ─── -->
        <section id="view-trips" class="view-section">
            <div class="stats-grid stats-grid-5">
                <div class="stat-card"><div class="stat-card-icon blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg></div><div class="stat-card-value" id="ts-total">—</div><div class="stat-card-label">Total Trips</div><div class="stat-card-glow blue"></div></div>
                <div class="stat-card"><div class="stat-card-icon green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20,6 9,17 4,12"/></svg></div><div class="stat-card-value" id="ts-completed">—</div><div class="stat-card-label">Completed</div><div class="stat-card-glow green"></div></div>
                <div class="stat-card"><div class="stat-card-icon yellow"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12,6 12,12 16,14"/></svg></div><div class="stat-card-value" id="ts-pending">—</div><div class="stat-card-label">Pending</div><div class="stat-card-glow yellow"></div></div>
                <div class="stat-card"><div class="stat-card-icon red"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></div><div class="stat-card-value" id="ts-cancelled">—</div><div class="stat-card-label">Cancelled</div><div class="stat-card-glow red"></div></div>
                <div class="stat-card"><div class="stat-card-icon orange"><span style="font-size:1.1rem;font-weight:800;font-family:inherit;">₱</span></div><div class="stat-card-value" id="ts-revenue">—</div><div class="stat-card-label">Total Revenue</div><div class="stat-card-glow orange"></div></div>
            </div>
            <div class="filter-bar">
                <input type="text" class="filter-input" id="trip-search" placeholder="Search commuter, driver, or route…">
                <select class="filter-select" id="trip-status">
                    <option value="">All Statuses</option>
                    <option value="completed">Completed</option>
                    <option value="pending">Pending</option>
                    <option value="cancelled">Cancelled</option>
                    <option value="active">Active</option>
                </select>
                <button class="btn btn-primary" onclick="loadTrips(1)"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>Filter</button>
                <button class="btn btn-ghost" onclick="document.getElementById('trip-search').value='';document.getElementById('trip-status').value='';loadTrips(1)">Clear</button>
            </div>
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><div class="card-title-dot"></div>All Trips</div>
                    <span style="font-size:0.75rem;color:var(--text-dim)" id="trip-count">Loading…</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Trip ID</th><th>Commuter</th><th>Driver</th><th>Origin</th><th>Destination</th><th>Fare</th><th>Date</th><th>Status</th></tr></thead>
                        <tbody id="trip-tbody"><tr><td colspan="8" class="empty-state"><span class="spinner-inline"></span></td></tr></tbody>
                    </table>
                </div>
                <div class="pagination"><span class="page-info" id="trip-page-info">—</span><div class="page-btns" id="trip-page-btns"></div></div>
            </div>
        </section>

        <!-- ─── COMMUTERS ─── -->
        <section id="view-commuters" class="view-section">
            <div class="stats-grid stats-grid-3">
                <div class="stat-card"><div class="stat-card-icon blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div><div class="stat-card-value" id="cs-total">—</div><div class="stat-card-label">Total Commuters</div><div class="stat-card-glow blue"></div></div>
                <div class="stat-card"><div class="stat-card-icon green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg></div><div class="stat-card-value" id="cs-today">—</div><div class="stat-card-label">Joined Today</div><div class="stat-card-glow green"></div></div>
                <div class="stat-card"><div class="stat-card-icon orange"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22,12 18,12 15,21 9,3 6,12 2,12"/></svg></div><div class="stat-card-value" id="cs-active">—</div><div class="stat-card-label">Active Today</div><div class="stat-card-glow orange"></div></div>
            </div>
            <div class="filter-bar">
                <input type="text" class="filter-input" id="commuter-search" placeholder="Search by name, email, phone, or address…">
                <button class="btn btn-primary" onclick="loadCommuters(1)"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>Search</button>
                <button class="btn btn-ghost" onclick="document.getElementById('commuter-search').value='';loadCommuters(1)">Clear</button>
            </div>
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><div class="card-title-dot"></div>Commuter Registry</div>
                    <span style="font-size:0.75rem;color:var(--text-dim)" id="commuter-count">Loading…</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Phone</th><th>Address</th><th>Joined</th></tr></thead>
                        <tbody id="commuter-tbody"><tr><td colspan="6" class="empty-state"><span class="spinner-inline"></span></td></tr></tbody>
                    </table>
                </div>
                <div class="pagination"><span class="page-info" id="commuter-page-info">—</span><div class="page-btns" id="commuter-page-btns"></div></div>
            </div>
        </section>

        <!-- ─── PARTNER DRIVERS ─── -->
        <section id="view-drivers" class="view-section">
            <div class="stats-grid stats-grid-4">
                <div class="stat-card"><div class="stat-card-icon blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M0.5 9.5 Q5 6.5 11 8 Q15 7 19.5 8.5" stroke-linecap="round"/><rect x="1" y="9.5" width="9" height="5.5" rx="1"/><path d="M10 9 L15.5 9 L17.5 11.5 L17.5 15 L10 15 Z"/><circle cx="4" cy="18.5" r="2.8"/><circle cx="13" cy="18.5" r="2.8"/><circle cx="20.5" cy="18.5" r="2.8"/></svg></div><div class="stat-card-value" id="ds-total">—</div><div class="stat-card-label">Total Drivers</div><div class="stat-card-glow blue"></div></div>
                <div class="stat-card"><div class="stat-card-icon green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22,12 18,12 15,21 9,3 6,12 2,12"/></svg></div><div class="stat-card-value" id="ds-active">—</div><div class="stat-card-label">Active Today</div><div class="stat-card-glow green"></div></div>
                <div class="stat-card"><div class="stat-card-icon yellow"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg></div><div class="stat-card-value" id="ds-today">—</div><div class="stat-card-label">Joined Today</div><div class="stat-card-glow yellow"></div></div>
                <div class="stat-card"><div class="stat-card-icon orange"><span style="font-size:1.1rem;font-weight:800;font-family:inherit;">₱</span></div><div class="stat-card-value" id="ds-revenue">—</div><div class="stat-card-label">Total Revenue</div><div class="stat-card-glow orange"></div></div>
            </div>
            <div class="filter-bar">
                <input type="text" class="filter-input" id="driver-search" placeholder="Search by name, plate, TODA, license, or contact…">
                <button class="btn btn-primary" onclick="loadDrivers(1)"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>Search</button>
                <button class="btn btn-ghost" onclick="document.getElementById('driver-search').value='';loadDrivers(1)">Clear</button>
            </div>
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><div class="card-title-dot"></div>Driver Registry</div>
                    <span style="font-size:0.75rem;color:var(--text-dim)" id="driver-count">Loading…</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Driver</th><th>Contact</th><th>Plate No.</th><th>License No.</th><th>TODA / Org.</th><th>Status</th><th>Joined</th></tr></thead>
                        <tbody id="driver-tbody"><tr><td colspan="7" class="empty-state"><span class="spinner-inline"></span></td></tr></tbody>
                    </table>
                </div>
                <div class="pagination"><span class="page-info" id="driver-page-info">—</span><div class="page-btns" id="driver-page-btns"></div></div>
            </div>
        </section>

    </div>
</main>

<script>
/* ══ CLOCK ══════════════════════════════════════════════════ */
function updateClock() {
    const now=new Date(),days=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'],months=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    let h=now.getHours(),ampm=h>=12?'PM':'AM';h=h%12||12;
    document.getElementById('live-clock').textContent=`${days[now.getDay()]}, ${months[now.getMonth()]} ${now.getDate()}, ${h}:${String(now.getMinutes()).padStart(2,'0')}:${String(now.getSeconds()).padStart(2,'0')} ${ampm}`;
}
updateClock(); setInterval(updateClock,1000);

/* ══ VIEW SWITCHING ══════════════════════════════════════════ */
const viewTitles={overview:'PasadaNow Command Center',trips:'Trip Records',commuters:'Commuters',drivers:'Partner Drivers'};
const loaded={trips:false,commuters:false,drivers:false};
function switchView(viewId,btn){
    document.getElementById('view-title').innerText=viewTitles[viewId]||viewId;
    document.querySelectorAll('.nav-btn').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('.view-section').forEach(v=>v.classList.remove('active'));
    document.getElementById('view-'+viewId).classList.add('active');
    if(viewId==='trips'&&!loaded.trips){loaded.trips=true;loadTrips(1);}
    if(viewId==='commuters'&&!loaded.commuters){loaded.commuters=true;loadCommuters(1);}
    if(viewId==='drivers'&&!loaded.drivers){loaded.drivers=true;loadDrivers(1);}
}

/* ══ HELPERS ════════════════════════════════════════════════ */
const fmt  = n=>Number(n||0).toLocaleString();
const fmtP = n=>'₱'+Number(n||0).toLocaleString('en-PH',{minimumFractionDigits:2});
const fmtD = s=>new Date(s).toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'});
const fmtDT= s=>new Date(s).toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'});
function badgeClass(status){return{completed:'badge-completed',pending:'badge-pending',cancelled:'badge-cancelled'}[status?.toLowerCase()]||'badge-active';}
function renderPagination(btnId,infoId,cur,total,fn){
    document.getElementById(infoId).textContent=`Page ${cur} of ${total}`;
    let h=`<button class="page-btn ${cur<=1?'disabled':''}" onclick="${fn}(${cur-1})">← Prev</button>`;
    for(let p=Math.max(1,cur-2);p<=Math.min(total,cur+2);p++)h+=`<button class="page-btn ${p===cur?'active':''}" onclick="${fn}(${p})">${p}</button>`;
    h+=`<button class="page-btn ${cur>=total?'disabled':''}" onclick="${fn}(${cur+1})">Next →</button>`;
    document.getElementById(btnId).innerHTML=h;
}

/* ══ TRIPS ══════════════════════════════════════════════════ */
async function loadTrips(page){
    const search=document.getElementById('trip-search').value.trim();
    const status=document.getElementById('trip-status').value;
    document.getElementById('trip-tbody').innerHTML='<tr><td colspan="8" class="empty-state"><span class="spinner-inline"></span></td></tr>';
    const res=await fetch(`admin_dashboard.php?action=trips&page=${page}&search=${encodeURIComponent(search)}&status=${encodeURIComponent(status)}`);
    const data=await res.json();
    document.getElementById('ts-total').textContent=fmt(data.stats.total);
    document.getElementById('ts-completed').textContent=fmt(data.stats.completed);
    document.getElementById('ts-pending').textContent=fmt(data.stats.pending);
    document.getElementById('ts-cancelled').textContent=fmt(data.stats.cancelled);
    document.getElementById('ts-revenue').textContent=fmtP(data.stats.revenue);
    document.getElementById('trip-count').textContent=`${fmt(data.total)} record${data.total!=1?'s':''}`;
    document.getElementById('trip-tbody').innerHTML=!data.rows.length
        ?'<tr><td colspan="8" class="empty-state">No trips found.</td></tr>'
        :data.rows.map(t=>`<tr>
            <td style="font-weight:700;color:var(--blue)">#${t.id}</td>
            <td style="color:var(--text)">${t.commuter_name||'—'}</td>
            <td>${t.driver_name||'—'}</td>
            <td>${t.origin}</td><td>${t.destination}</td>
            <td style="color:var(--green);font-weight:600">${fmtP(t.fare)}</td>
            <td style="font-size:.72rem">${fmtDT(t.created_at)}</td>
            <td><span class="status-badge ${badgeClass(t.status)}">● ${t.status.charAt(0).toUpperCase()+t.status.slice(1)}</span></td>
          </tr>`).join('');
    renderPagination('trip-page-btns','trip-page-info',page,data.pages,'loadTrips');
}
document.getElementById('trip-search').addEventListener('keypress',e=>{if(e.key==='Enter')loadTrips(1);});

/* ══ COMMUTERS ══════════════════════════════════════════════ */
async function loadCommuters(page){
    const search=document.getElementById('commuter-search').value.trim();
    document.getElementById('commuter-tbody').innerHTML='<tr><td colspan="6" class="empty-state"><span class="spinner-inline"></span></td></tr>';
    const res=await fetch(`admin_dashboard.php?action=commuters&page=${page}&search=${encodeURIComponent(search)}`);
    const data=await res.json();
    document.getElementById('cs-total').textContent=fmt(data.stats.total);
    document.getElementById('cs-today').textContent=fmt(data.stats.today);
    document.getElementById('cs-active').textContent=fmt(data.stats.active);
    document.getElementById('commuter-count').textContent=`${fmt(data.total)} record${data.total!=1?'s':''}`;
    document.getElementById('commuter-tbody').innerHTML=!data.rows.length
        ?'<tr><td colspan="6" class="empty-state">No commuters found.</td></tr>'
        :data.rows.map(c=>`<tr>
            <td style="color:var(--text-dim);font-size:.72rem">#${c.id}</td>
            <td><div class="user-cell"><div class="mini-avatar commuter">${(c.username||'?').substring(0,2).toUpperCase()}</div><span style="color:var(--text);font-weight:500">${c.username||''}</span></div></td>
            <td>${c.email||'—'}</td>
            <td>${c.contact_no||c.phone||'—'}</td>
            <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${c.address||''}">${c.address||'—'}</td>
            <td style="font-size:.72rem">${fmtD(c.created_at)}</td>
          </tr>`).join('');
    renderPagination('commuter-page-btns','commuter-page-info',page,data.pages,'loadCommuters');
}
document.getElementById('commuter-search').addEventListener('keypress',e=>{if(e.key==='Enter')loadCommuters(1);});

/* ══ DRIVERS ════════════════════════════════════════════════ */
async function loadDrivers(page){
    const search=document.getElementById('driver-search').value.trim();
    document.getElementById('driver-tbody').innerHTML='<tr><td colspan="7" class="empty-state"><span class="spinner-inline"></span></td></tr>';
    const res=await fetch(`admin_dashboard.php?action=drivers&page=${page}&search=${encodeURIComponent(search)}`);
    const data=await res.json();
    document.getElementById('ds-total').textContent=fmt(data.stats.total);
    document.getElementById('ds-active').textContent=fmt(data.stats.active);
    document.getElementById('ds-today').textContent=fmt(data.stats.today);
    document.getElementById('ds-revenue').textContent=fmtP(data.stats.revenue);
    document.getElementById('driver-count').textContent=`${fmt(data.total)} record${data.total!=1?'s':''}`;
    document.getElementById('driver-tbody').innerHTML=!data.rows.length
        ?'<tr><td colspan="7" class="empty-state">No drivers found.</td></tr>'
        :data.rows.map(d=>`<tr>
            <td><div class="user-cell">
              <div class="mini-avatar driver">${(d.username||'?').substring(0,2).toUpperCase()}</div>
              <div>
                <div style="color:var(--text);font-weight:500">${d.username||''}</div>
                <div style="font-size:.68rem;color:var(--text-dim)">${d.email||''}</div>
              </div>
            </div></td>
            <td>${d.contact_no||d.phone||'—'}</td>
            <td>${d.plate_number ? `<span class="plate-badge">${d.plate_number}</span>` : '—'}</td>
            <td>${d.license_no||'—'}</td>
            <td>${d.organization||'—'}</td>
            <td><span class="status-badge ${d.is_available=='1'?'badge-completed':'badge-cancelled'}">● ${d.is_available=='1'?'Online':'Offline'}</span></td>
            <td style="font-size:.72rem">${fmtD(d.created_at)}</td>
          </tr>`).join('');
    renderPagination('driver-page-btns','driver-page-info',page,data.pages,'loadDrivers');
}
document.getElementById('driver-search').addEventListener('keypress',e=>{if(e.key==='Enter')loadDrivers(1);});

/* ══ GLOBAL SEARCH ══════════════════════════════════════════ */
(function(){
    const input=document.getElementById('globalSearch'),clearBtn=document.getElementById('searchClear'),submitBtn=document.getElementById('searchSubmit'),dropdown=document.getElementById('searchDropdown'),body=document.getElementById('sdBody'),footer=document.getElementById('sdFooter'),countEl=document.getElementById('sdCount'),tabs=document.querySelectorAll('.sd-tab');
    let activeTab='all';
    const escRe=s=>s.replace(/[.*+?^${}()|[\]\\]/g,'\\$&');
    const hl=(txt,q)=>q?String(txt||'').replace(new RegExp(`(${escRe(q)})`,'gi'),'<mark>$1</mark>'):(txt||'');
    const ini=n=>{const p=(n||'?').trim().split(' ');return(p[0][0]+(p[1]?p[1][0]:'')).toUpperCase();};
    async function performSearch(){
        const q=input.value.trim();if(!q)return;
        body.innerHTML='<div class="sd-state">Searching database...</div>';
        dropdown.classList.add('open');
        try{
            const res=await fetch(`admin_dashboard.php?action=search&q=${encodeURIComponent(q)}&tab=${activeTab}`);
            const data=await res.json();
            footer.style.display='flex';
            countEl.textContent=`${data.results.length} result${data.results.length!==1?'s':''}`;
            if(!data.results.length){body.innerHTML=`<div class="sd-state">No matches found for "${q}"</div>`;return;}
            body.innerHTML=data.results.map(u=>`
                <div class="sd-row">
                    <div class="sd-avatar ${u.role}">${ini(u.username)}</div>
                    <div class="sd-info">
                        <div class="sd-name">${hl(u.username,q)}</div>
                        <div class="sd-sub">${u.role==='driver'
                            ?`<b>${hl(u.plate_number,q)||'No plate'}</b> · ${hl(u.organization,q)||'—'} · License: ${hl(u.license_no,q)||'—'}`
                            :hl(u.email,q)}</div>
                        <div class="sd-sub" style="font-size:.65rem">${u.role==='driver'?`Contact: ${u.contact_no||'—'}`:`Phone: ${u.contact_no||u.phone||'—'}`}</div>
                    </div>
                    <div class="sd-meta">
                        <span class="role-badge ${u.role}">${u.role}</span>
                        <div class="sd-id">#${u.id}</div>
                    </div>
                </div>`).join('');
        }catch(e){body.innerHTML='<div class="sd-state">Error connecting to server.</div>';}
    }
    input.addEventListener('input',()=>{clearBtn.classList.toggle('visible',input.value.length>0);if(!input.value)dropdown.classList.remove('open');});
    submitBtn.addEventListener('click',performSearch);
    input.addEventListener('keypress',e=>{if(e.key==='Enter')performSearch();});
    tabs.forEach(t=>t.addEventListener('click',()=>{tabs.forEach(x=>x.classList.remove('active'));t.classList.add('active');activeTab=t.dataset.tab;if(input.value)performSearch();}));
    clearBtn.addEventListener('click',()=>{input.value='';dropdown.classList.remove('open');clearBtn.classList.remove('visible');});
    document.addEventListener('click',e=>{if(!document.getElementById('searchWrap').contains(e.target)&&e.target!==submitBtn)dropdown.classList.remove('open');});
})();
</script>
</body>
</html>