<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();
date_default_timezone_set('Asia/Manila');

require_once("../config/db.php");
require_once("includes/auth.php");

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function tableExists($conn, $tableName)
{
    try {
        $stmt = $conn->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :t)");
        $stmt->execute([':t' => strtolower($tableName)]);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

function columnExists($conn, $tableName, $columnName)
{
    try {
        $stmt = $conn->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :t AND column_name = :c)");
        $stmt->execute([':t' => strtolower($tableName), ':c' => strtolower($columnName)]);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

$adminUserId = (int) ($_SESSION['user_id'] ?? 1);

/* =====================================================
   AJAX NOTIFICATION HANDLER
===================================================== */

if (isset($_GET['action']) && $_GET['action'] === 'mark_notif_read') {
    if (tableExists($conn, 'notifications')) {
        $mk = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = :uid");
        $mk->execute([':uid' => $adminUserId]);
    }
    echo json_encode(['status' => 'success']);
    exit;
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Fetch all reschedule requests with owner and property details
$query = "
    SELECT rr.*, 
           u.fullname AS owner_name, 
           r.business_name, 
           r.rental_name, 
           i.inspection_no, 
           i.inspection_date AS original_date
    FROM reschedule_requests rr
    INNER JOIN users u ON rr.user_id = u.id
    INNER JOIN inspections i ON rr.inspection_id = i.id
    INNER JOIN rentals r ON i.rental_id = r.id
";

$params = [];
if (!empty($search)) {
    $query .= " WHERE (i.inspection_no ILIKE :s1 OR r.business_name ILIKE :s2 OR r.rental_name ILIKE :s3 OR u.fullname ILIKE :s4)";
    $like = '%' . $search . '%';
    $params = [':s1' => $like, ':s2' => $like, ':s3' => $like, ':s4' => $like];
}

$query .= " ORDER BY rr.created_at DESC";

$requests = [];
try {
    $reqStmt = $conn->prepare($query);
    $reqStmt->execute($params);
    $requests = $reqStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('reschedule_requests: ' . $e->getMessage());
}

// Notifications Data
$notifications = [];
$unreadNotifCount = 0;

if (tableExists($conn, 'notifications')) {
    try {
        $cnt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = FALSE");
        $cnt->execute([':uid' => $adminUserId]);
        $unreadNotifCount = (int) $cnt->fetchColumn();
    } catch (PDOException $e) {
        error_log('reschedule_requests notif count: ' . $e->getMessage());
    }

    $hasTargetUrl = columnExists($conn, 'notifications', 'target_url');
    $selectFields = $hasTargetUrl ? "id, title, message, target_url, created_at" : "id, title, message, created_at";

    try {
        // $selectFields is chosen from two fixed strings above (no user input)
        $nq = $conn->prepare("SELECT $selectFields FROM notifications WHERE user_id = :uid ORDER BY created_at DESC LIMIT 5");
        $nq->execute([':uid' => $adminUserId]);
        foreach ($nq->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!$hasTargetUrl) {
                $row['target_url'] = 'javascript:void(0)';
            }
            $notifications[] = $row;
        }
    } catch (PDOException $e) {
        error_log('reschedule_requests notifications: ' . $e->getMessage());
    }
}

// Get current user info for navbar
$userName = $_SESSION['fullname'] ?? ($_SESSION['user_name'] ?? 'Admin');
$userRole = $_SESSION['role'] ?? ($_SESSION['user_role'] ?? 'Administrator');
$userInitial = strtoupper(substr($userName, 0, 1));
$searchQuery = trim($_GET['search'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reschedule Requests | RENTRACK LGU</title>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

<style>
    :root {
        --bg-body: #0b0d13;
        --bg-card: #151922;
        --bg-card-alt: #1b212c;

        --sidebar-bg: #0d1017;
        --topofficial-bg: linear-gradient(90deg, #7f1d3a, #9f1239 55%, #7f1d3a);
        --navbar-bg: rgba(21, 25, 34, 0.85);

        --red-accent: #e11d48;
        --red-hover: #fb3860;
        --red-soft: #fecdd3;

        --gold-accent: #f59e0b;
        --teal-accent: #22d3ee;
        --emerald-accent: #34d399;
        --violet-accent: #a78bfa;

        --text-main: #f8fafc;
        --text-sub: #cbd5e1;
        --text-muted: #8b96a8;

        --border-color: rgba(255, 255, 255, 0.07);
        --border-strong: rgba(255, 255, 255, 0.14);

        --shadow-soft: 0 10px 30px rgba(0, 0, 0, 0.35);
        --shadow-lift: 0 18px 44px rgba(0, 0, 0, 0.45);

        --topofficial-height: 38px;
        --navbar-height: 68px;
        --sidebar-width: 264px;
        --sidebar-collapsed-width: 76px;
        --header-total-height: calc(var(--topofficial-height) + var(--navbar-height));
        --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }

    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
        background-color: var(--bg-body);
        background-image:
            radial-gradient(at 12% 8%, rgba(225, 29, 72, 0.14) 0px, transparent 45%),
            radial-gradient(at 88% 0%, rgba(34, 211, 238, 0.08) 0px, transparent 40%),
            radial-gradient(at 100% 100%, rgba(136, 19, 55, 0.14) 0px, transparent 50%),
            radial-gradient(at 0% 100%, rgba(245, 158, 11, 0.06) 0px, transparent 45%);
        background-attachment: fixed;
        font-family: 'Plus Jakarta Sans', sans-serif;
        color: var(--text-main);
        min-height: 100vh;
        overflow-x: hidden;
        padding-top: var(--header-total-height);
        -webkit-font-smoothing: antialiased;
    }

    ::-webkit-scrollbar { width: 6px; height: 6px; }
    ::-webkit-scrollbar-track { background: var(--bg-body); }
    ::-webkit-scrollbar-thumb { background: #334155; border-radius: 20px; }

    /* TOP OFFICIAL BAR */
    .top-official-bar {
        position: fixed; top: 0; left: 0; width: 100%;
        background: var(--topofficial-bg);
        border-bottom: 1px solid rgba(255, 255, 255, 0.15);
        padding: 0 20px; display: flex; justify-content: space-between; align-items: center;
        flex-wrap: nowrap; overflow: hidden;
        font-size: 11px; font-weight: 600; z-index: 1002; height: var(--topofficial-height);
        color: #fff;
    }
    .topbar-left { display: flex; align-items: center; gap: 8px; color: #fff; min-width: 0; flex-shrink: 1; overflow: hidden; }
    .topbar-left i { font-size: 12px; color: #fecdd3; flex-shrink: 0; }
    .topbar-left strong { letter-spacing: 0.3px; font-weight: 800; color: #fff; flex-shrink: 0; white-space: nowrap; }
    .topbar-left .divider { width: 1px; height: 12px; background: rgba(255, 255, 255, 0.3); flex-shrink: 0; }
    .topbar-left .portal-title { color: #ffe4e6; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }
    .topbar-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }

    .cert-badge {
        display: inline-flex; align-items: center; gap: 6px; padding: 3px 12px;
        background: rgba(255, 255, 255, 0.15); border: 1px solid rgba(255, 255, 255, 0.3);
        border-radius: 999px; color: #fff; font-weight: 700; font-size: 10px;
        text-transform: uppercase; letter-spacing: 0.5px;
    }

    /* NAVBAR */
    .rt-topbar {
        position: fixed;
        top: var(--topofficial-height); left: 0; width: 100%;
        background: var(--navbar-bg);
        backdrop-filter: blur(14px);
        -webkit-backdrop-filter: blur(14px);
        border-bottom: 1px solid var(--border-color);
        padding: 0;
        display: flex; align-items: center; justify-content: space-between;
        height: var(--navbar-height);
        z-index: 1001;
    }

    .rt-topbar-left { display: flex; align-items: center; height: 100%; }

    .rt-topbar-brand { 
        display: flex; align-items: center; gap: 10px; width: var(--sidebar-width); 
        height: 100%; padding: 0 20px; border-right: 1px solid var(--border-strong);
        flex-shrink: 0; transition: var(--transition);
    }

    body.sidebar-collapsed .rt-topbar-brand {
        width: var(--sidebar-collapsed-width); padding: 0; justify-content: center;
    }

    body.sidebar-collapsed .rt-topbar-brand .rt-topbar-brand-text { display: none !important; }

    .rt-topbar-logo {
        width: 36px; height: 36px; border-radius: 10px;
        background: linear-gradient(135deg, var(--red-accent), #9f1239);
        display: flex; align-items: center; justify-content: center;
        font-family: 'Space Grotesk', sans-serif; font-weight: 800; font-size: 13px; color: #fff;
        box-shadow: 0 0 12px rgba(225, 29, 72, 0.35); flex-shrink: 0;
    }
    .rt-topbar-brand-text { display: flex; flex-direction: column; line-height: 1.15; }
    .rt-topbar-brand-text strong { font-size: 13px; font-weight: 800; color: #fff; letter-spacing: 0.3px; }
    .rt-topbar-brand-text span { font-size: 9px; font-weight: 700; color: var(--red-soft); text-transform: uppercase; letter-spacing: 0.06em; }

    .sidebar-toggle-btn {
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid var(--border-color);
        color: var(--text-main); width: 38px; height: 38px;
        border-radius: 10px; display: flex; align-items: center; justify-content: center;
        cursor: pointer; font-size: 16px; transition: var(--transition);
        margin-left: 16px;
    }
    .sidebar-toggle-btn:hover { background: var(--red-accent); color: #fff; border-color: var(--red-accent); }

    .rt-topbar-left .page-title {
        font-family: 'Space Grotesk', sans-serif;
        font-size: 18px; font-weight: 800; color: #fff; margin: 0;
        margin-left: 14px;
    }
    .rt-topbar-left .page-title span { color: var(--text-sub); font-weight: 500; font-size: 13px; }

    .rt-topbar-center {
        flex: 1; display: flex; justify-content: center;
        padding: 0 16px; max-width: 500px; margin: 0 auto; position: relative;
    }

    .rt-search-container { position: relative; width: 100%; }
    .rt-search-container i {
        position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
        color: var(--text-muted); font-size: 14px;
    }
    .rt-search-container input {
        width: 100%; padding: 8px 16px 8px 38px; border-radius: 999px;
        border: 1px solid var(--border-color); background: rgba(17, 19, 24, 0.8);
        color: #fff; font-size: 13px; outline: none; transition: var(--transition);
    }
    .rt-search-container input::placeholder { color: var(--text-muted); }
    .rt-search-container input:focus {
        background: var(--bg-body); border-color: var(--red-accent);
        box-shadow: 0 0 12px rgba(225, 29, 72, 0.25);
    }

    .rt-topbar-right { display: flex; align-items: center; gap: 12px; padding-right: 24px; }

    .icon-btn {
        width: 38px; height: 38px; border-radius: 10px;
        border: 1px solid var(--border-color); background: rgba(17, 19, 24, 0.8);
        color: #fff; display: flex; align-items: center; justify-content: center;
        cursor: pointer; position: relative; transition: var(--transition);
    }
    .icon-btn:hover { background: var(--bg-card-alt); border-color: var(--red-accent); }

    .notif-badge-dot {
        position: absolute; top: 6px; right: 6px; width: 9px; height: 9px;
        border-radius: 50%; background: var(--red-accent);
        box-shadow: 0 0 8px var(--red-accent);
        transition: opacity 0.3s ease;
    }

    /* PROFILE DROPDOWN */
    .rt-profile-wrapper { position: relative; }
    .rt-profile-btn {
        display: flex; align-items: center; gap: 10px; padding: 4px 14px 4px 4px;
        border-radius: 999px; border: 1px solid var(--border-color);
        background: rgba(17, 19, 24, 0.8); cursor: pointer; transition: var(--transition);
    }
    .rt-profile-btn:hover { border-color: var(--red-accent); }
    .rt-profile-btn .avatar {
        width: 32px; height: 32px; border-radius: 50%;
        background: var(--red-accent); color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-weight: 800; font-size: 13px;
    }
    .rt-profile-btn .info { display: flex; flex-direction: column; line-height: 1.1; }
    .rt-profile-btn .info .name { font-size: 12px; font-weight: 700; color: #fff; }
    .rt-profile-btn .info .role { font-size: 9px; font-weight: 700; color: var(--red-soft); text-transform: uppercase; }

    .rt-profile-dropdown {
        position: absolute; top: calc(100% + 10px); right: 0;
        width: 200px; background: var(--bg-card); border: 1px solid var(--border-color);
        border-radius: 14px; padding: 8px; box-shadow: 0 20px 40px rgba(0,0,0,0.5);
        opacity: 0; visibility: hidden; transform: translateY(-8px);
        transition: var(--transition); z-index: 100;
    }
    .rt-profile-dropdown.show { opacity: 1; visibility: visible; transform: translateY(0); }

    .rt-profile-dropdown .dropdown-item {
        display: flex; align-items: center; gap: 10px; padding: 9px 12px;
        border-radius: 8px; color: var(--text-sub); text-decoration: none;
        font-size: 13px; font-weight: 500; transition: var(--transition);
    }
    .rt-profile-dropdown .dropdown-item:hover { background: rgba(225, 29, 72, 0.15); color: #fff; }
    .rt-profile-dropdown .dropdown-item.danger { color: #f87171; }

    /* =====================================================
       SIDEBAR STYLES (MATCHING DASHBOARD & ANIMATED LOGO)
    ===================================================== */
    .wrapper { display: flex; min-height: calc(100vh - var(--header-total-height)); }

    .sidebar {
        width: var(--sidebar-width); flex-shrink: 0;
        background: var(--sidebar-bg);
        border-right: 1px solid var(--border-color);
        padding: 16px 12px; margin: 0; position: fixed;
        top: var(--header-total-height);
        left: 0; height: calc(100vh - var(--header-total-height));
        overflow-y: auto; overflow-x: hidden; display: flex; flex-direction: column; z-index: 1000;
        transition: width 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .main {
        flex: 1; margin-left: var(--sidebar-width);
        display: flex; flex-direction: column; min-width: 0; transition: margin-left 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .sidebar .brand {
        display: flex; align-items: center; gap: 10px; padding: 0 8px 16px 8px;
        margin-bottom: 12px; border-bottom: 1px solid var(--border-color);
        color: #fff; font-weight: 800; font-family: 'Space Grotesk', sans-serif;
    }

    /* ANIMATED ROTATING LOGO AVATAR */
    .animated-logo-avatar {
        position: relative; width: 38px; height: 38px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        background: #111827; padding: 3px; flex-shrink: 0;
        box-shadow: 0 0 15px rgba(225, 29, 72, 0.3);
    }
    .animated-logo-avatar::before {
        content: ''; position: absolute; inset: -2px; border-radius: 50%;
        background: conic-gradient(from 0deg, #e11d48 0deg, #f43f5e 120deg, transparent 210deg, #e11d48 360deg);
        animation: rotateGlow 3s linear infinite; z-index: 1;
    }
    .animated-logo-avatar::after {
        content: ''; position: absolute; inset: 2px; background: #111827; border-radius: 50%; z-index: 2;
    }
    .animated-logo-avatar span {
        position: relative; z-index: 3; font-weight: 800; font-size: 13px;
        color: #f43f5e; letter-spacing: -0.5px;
    }

    @keyframes rotateGlow {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    .sidebar .nav-section { display: flex; flex-direction: column; gap: 4px; }
    .sidebar .nav-label {
        font-size: 10px; font-weight: 700; color: var(--text-muted);
        text-transform: uppercase; letter-spacing: 0.08em; padding: 10px 8px 4px 8px;
    }
    .sidebar .nav-item {
        display: flex; align-items: center; gap: 12px; padding: 10px 12px;
        border-radius: 10px; color: var(--text-sub); text-decoration: none;
        font-size: 13px; font-weight: 600; transition: var(--transition);
    }
    .sidebar .nav-item:hover, .sidebar .nav-item.active {
        background: rgba(225, 29, 72, 0.15); color: #fff;
    }
    .sidebar .nav-item.active { background: var(--red-accent); color: #fff; box-shadow: 0 4px 12px rgba(225, 29, 72, 0.3); }
    .sidebar .nav-item i { font-size: 15px; width: 20px; text-align: center; color: var(--red-hover); }
    .sidebar .nav-item.active i { color: #fff; }
    .sidebar-footer { margin-top: auto; padding-top: 12px; border-top: 1px solid var(--border-color); }

    /* COLLAPSED STATE */
    body.sidebar-collapsed .sidebar { width: var(--sidebar-collapsed-width); padding: 16px 6px; }
    body.sidebar-collapsed .main { margin-left: var(--sidebar-collapsed-width); }
    body.sidebar-collapsed .sidebar .brand > div:not(.animated-logo-avatar),
    body.sidebar-collapsed .sidebar .nav-label,
    body.sidebar-collapsed .sidebar .menu-text { display: none !important; }
    body.sidebar-collapsed .sidebar .brand { justify-content: center; padding-bottom: 12px; margin-bottom: 12px; border-bottom: none; }
    body.sidebar-collapsed .sidebar .nav-item {
        display: flex !important; justify-content: center !important; align-items: center !important;
        width: 100% !important; padding: 12px 0 !important; margin-bottom: 4px; border-radius: 10px; position: relative;
    }
    body.sidebar-collapsed .sidebar .nav-item i { font-size: 18px !important; width: auto !important; margin: 0 !important; }
    body.sidebar-collapsed .sidebar .nav-item:hover::after {
        content: attr(data-title); position: absolute; left: calc(100% + 12px); top: 50%;
        transform: translateY(-50%); background: #181c24; color: #fff; padding: 5px 10px;
        font-size: 11px; font-weight: 700; border-radius: 6px; white-space: nowrap;
        box-shadow: 0 5px 15px rgba(0,0,0,0.5); border: 1px solid var(--red-accent); z-index: 9999; pointer-events: none;
    }

    /* RESCHEDULE PAGE STYLING */
    .dashboard-shell { padding: 16px 20px 30px; flex: 1; }

    .page-header-card {
        background: var(--bg-card); border: 1px solid var(--border-color);
        border-radius: 18px; padding: 20px 24px; margin-bottom: 20px;
        box-shadow: var(--shadow-soft);
    }
    .page-header-card h2 {
        font-family: 'Space Grotesk', sans-serif; font-size: 20px; font-weight: 800; color: #fff; margin: 0;
        display: flex; align-items: center; gap: 10px;
    }
    .page-header-card h2 i { color: var(--red-hover); }
    .page-header-card p { font-size: 12px; color: var(--text-muted); margin: 4px 0 0; }

    .card-table-rt {
        background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 18px; overflow: hidden;
        box-shadow: var(--shadow-soft);
    }
    table.rt-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
    table.rt-table th {
        background: rgba(255,255,255,0.02); color: var(--text-muted); font-size: 10.5px;
        font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
        padding: 12px 16px; border-bottom: 1px solid var(--border-color); text-align: left;
    }
    table.rt-table td {
        padding: 12px 16px; border-bottom: 1px solid var(--border-color); vertical-align: middle; color: var(--text-main);
    }
    table.rt-table tr:hover { background: rgba(255,255,255,0.02); }

    .badge-rt {
        display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px;
        border-radius: 999px; font-size: 10px; font-weight: 700; text-transform: uppercase;
    }
    .badge-rt.success { background: rgba(52, 211, 153, 0.15); color: #34d399; border: 1px solid rgba(52, 211, 153, 0.3); }
    .badge-rt.warning { background: rgba(251, 146, 60, 0.15); color: #fb923c; border: 1px solid rgba(251, 146, 60, 0.3); }
    .badge-rt.danger { background: rgba(248, 113, 113, 0.15); color: #f87171; border: 1px solid rgba(248, 113, 113, 0.3); }

    .btn-action-rt { border: none; padding: 6px 14px; border-radius: 8px; font-weight: 700; cursor: pointer; transition: var(--transition); font-size: 11.5px; }
    .btn-approve-rt { background: rgba(52, 211, 153, 0.2); color: #34d399; border: 1px solid rgba(52, 211, 153, 0.4); }
    .btn-approve-rt:hover { background: #34d399; color: #111318; }
    .btn-reject-rt { background: rgba(248, 113, 113, 0.2); color: #f87171; border: 1px solid rgba(248, 113, 113, 0.4); margin-left: 4px; }
    .btn-reject-rt:hover { background: #f87171; color: #111318; }
    .btn-disabled-rt { background: rgba(255,255,255,0.05); color: var(--text-muted); cursor: not-allowed; border: 1px solid var(--border-color); }

    .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); z-index: 999; }

    @media (max-width: 992px) {
        .sidebar { transform: translateX(-100%); width: var(--sidebar-width) !important; }
        body.sidebar-open .sidebar { transform: translateX(0); }
        body.sidebar-open .sidebar-overlay { display: block; }
        .main { margin-left: 0 !important; }
    }
</style>
</head>
<body>

<!-- TOP OFFICIAL BAR -->
<div class="top-official-bar">
    <div class="topbar-left">
        <i class="fa-solid fa-landmark"></i>
        <strong>REPUBLIC OF THE PHILIPPINES</strong>
        <span class="divider"></span>
        <span class="portal-title">Local Government Unit Property &amp; Rental Compliance Portal</span>
    </div>
    <div class="topbar-right">
        <span class="cert-badge"><i class="fa-solid fa-certificate"></i> LGU CERTIFIED PORTAL V2.1</span>
    </div>
</div>

<!-- MAIN HEADER NAVBAR -->
<header class="rt-topbar">
    <div class="rt-topbar-left">
        <div class="rt-topbar-brand">
            <div class="rt-topbar-logo">RT</div>
            <div class="rt-topbar-brand-text">
                <strong>RENTRACK</strong>
                <span>LGU ADMIN</span>
            </div>
        </div>
        <button class="sidebar-toggle-btn" id="sidebarToggle" title="Toggle Navigation Menu">
            <i class="fa-solid fa-bars"></i>
        </button>
        <div class="page-title">
            Reschedule Requests <span>&middot; Review inspection reschedule requests</span>
        </div>
    </div>

    <!-- LIVE SEARCH BAR -->
    <div class="rt-topbar-center">
        <div class="rt-search-container">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" id="globalNavSearch" placeholder="Search tracking no, rental name, owner..." value="<?php echo h($searchQuery); ?>" autocomplete="off">
        </div>
    </div>

    <div class="rt-topbar-right">
        <!-- NOTIFICATIONS -->
        <div style="position:relative;">
            <button class="icon-btn" id="notifBtn" title="Notifications">
                <i class="fa-regular fa-bell"></i>
                <?php if ($unreadNotifCount > 0): ?>
                    <span class="notif-badge-dot" id="notifBadgeDot"></span>
                <?php endif; ?>
            </button>

            <div id="notifDropdown" style="display:none; position:absolute; top:46px; right:0; width:300px; background:var(--bg-card); border:1px solid var(--border-color); border-radius:14px; padding:12px; box-shadow:0 10px 30px rgba(0,0,0,0.6); z-index:999;">
                <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); padding-bottom:8px; margin-bottom:8px;">
                    <span style="font-weight:700; color:#fff; font-size:12px;">System Notifications</span>
                    <span style="font-size:10px; color:var(--red-hover); cursor:pointer;" id="clearNotifBadge">Mark all read</span>
                </div>
                <div id="notifListContainer">
                    <?php if (!empty($notifications)): ?>
                        <?php foreach($notifications as $n): ?>
                            <a href="<?php echo h($n['target_url'] ?: 'javascript:void(0)'); ?>" class="d-block text-decoration-none" style="padding:8px 6px; border-bottom:1px solid rgba(255,255,255,0.04); border-radius:6px; transition:background 0.2s;" onmouseover="this.style.background='rgba(225,29,72,0.1)'" onmouseout="this.style.background='transparent'">
                                <div style="font-size:11.5px; font-weight:700; color:#fff;"><?php echo h($n['title']); ?></div>
                                <div style="font-size:10.5px; color:var(--text-muted); margin-top:2px;"><?php echo h($n['message']); ?></div>
                                <div style="font-size:9px; color:var(--red-soft); margin-top:4px;"><?php echo date('M d, h:i A', strtotime($n['created_at'])); ?></div>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="font-size:11px; color:var(--text-muted); text-align:center; padding:14px 0;">No new notifications</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- PROFILE DROPDOWN -->
        <div class="rt-profile-wrapper">
            <div class="rt-profile-btn" id="profileToggle">
                <div class="avatar"><?php echo $userInitial; ?></div>
                <div class="info d-none d-sm-flex">
                    <span class="name"><?php echo h($userName); ?></span>
                    <span class="role"><?php echo h($userRole); ?></span>
                </div>
                <i class="fa-solid fa-chevron-down" style="font-size:10px; color:var(--text-sub);"></i>
            </div>

            <div class="rt-profile-dropdown" id="profileDropdown">
                <a href="profile.php" class="dropdown-item"><i class="fa-solid fa-user"></i> My Profile</a>
                <a href="settings.php" class="dropdown-item"><i class="fa-solid fa-gear"></i> Settings</a>
                <div style="height:1px; background:var(--border-color); margin:4px 0;"></div>
                <a href="javascript:void(0)" class="dropdown-item danger" id="profileLogoutBtn"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
            </div>
        </div>
    </div>
</header>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- MAIN WRAPPER & SIDEBAR -->
<div class="wrapper">

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <div class="animated-logo-avatar">
                <span>RL</span>
            </div>
            <div>
                RENTRACK <span style="color:var(--red-soft);">LGU</span>
            </div>
        </div>

        <div class="nav-section">
            <div class="nav-label">Main</div>
            <a href="dashboard.php" class="nav-item" data-title="Dashboard"><i class="fa-solid fa-gauge-high"></i> <span class="menu-text">Dashboard</span></a>
            <a href="rentals.php" class="nav-item" data-title="Rentals"><i class="fa-solid fa-building"></i> <span class="menu-text">Rentals</span></a>
            <a href="users.php" class="nav-item" data-title="Users"><i class="fa-solid fa-users"></i> <span class="menu-text">Users</span></a>

            <div class="nav-label">Operations</div>
            <a href="payments.php" class="nav-item" data-title="Payments"><i class="fa-solid fa-cash-register"></i> <span class="menu-text">Payments</span></a>
            <a href="inspections.php" class="nav-item active" data-title="Inspections"><i class="fa-solid fa-clipboard-check"></i> <span class="menu-text">Inspections</span></a>
            <a href="compliance.php" class="nav-item" data-title="Compliance"><i class="fa-solid fa-file-shield"></i> <span class="menu-text">Compliance</span></a>
            <a href="collection.php" class="nav-item" data-title="Collections"><i class="fa-solid fa-wallet"></i> <span class="menu-text">Collections</span></a>

            <div class="nav-label">Reports &amp; System</div>
            <a href="activity_logs.php" class="nav-item" data-title="Audit Logs"><i class="fa-solid fa-clock-rotate-left"></i> <span class="menu-text">Audit Logs</span></a>
            <a href="map_overview.php" class="nav-item" data-title="GIS Mapping"><i class="fa-solid fa-map-location-dot"></i> <span class="menu-text">GIS Mapping</span></a>
            <a href="reports.php" class="nav-item" data-title="Reports"><i class="fa-solid fa-chart-simple"></i> <span class="menu-text">Reports</span></a>
            <a href="settings.php" class="nav-item" data-title="Settings"><i class="fa-solid fa-gear"></i> <span class="menu-text">Settings</span></a>
        </div>

        <div class="sidebar-footer">
            <a href="javascript:void(0)" class="nav-item" id="sidebarLogoutBtn" data-title="Logout"><i class="fa-solid fa-right-from-bracket"></i> <span class="menu-text">Logout</span></a>
        </div>
    </aside>

    <!-- MAIN CONTENT AREA -->
    <div class="main">
        <div class="dashboard-shell">

            <!-- PAGE HEADER -->
            <div class="page-header-card">
                <h2><i class="fa-solid fa-calendar-days"></i> Inspection Reschedule Requests</h2>
                <p>Approve or reject owner requests to move a scheduled inspection date.</p>
            </div>

            <!-- TABLE CARD -->
            <div class="card-table-rt">
                <div class="table-responsive">
                    <table class="rt-table">
                        <thead>
                            <tr>
                                <th>Inspection #</th>
                                <th>Property</th>
                                <th>Owner</th>
                                <th>Original Date</th>
                                <th>Preferred Schedule</th>
                                <th>Reason</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($requests)): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-5">
                                        <i class="fa-regular fa-calendar-check fa-2x mb-2" style="opacity:0.4;"></i><br>
                                        No reschedule requests yet.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($requests as $req): ?>
                                    <tr>
                                        <td>
                                            <span style="font-family:'IBM Plex Mono', monospace; font-weight:700; color:var(--red-soft); background:rgba(225,29,72,0.1); padding:3px 8px; border-radius:6px; border:1px solid rgba(225,29,72,0.2);">
                                                <?php echo h($req['inspection_no'] ?? 'N/A'); ?>
                                            </span>
                                        </td>
                                        <td><strong><?php echo h($req['rental_name'] ?? $req['business_name']); ?></strong></td>
                                        <td><?php echo h($req['owner_name']); ?></td>
                                        <td><?php echo h(date('M d, Y', strtotime($req['original_date']))); ?></td>
                                        <td>
                                            <?php echo $req['preferred_date'] ? h(date('M d, Y', strtotime($req['preferred_date']))) : '—'; ?>
                                            <?php if ($req['preferred_time']): ?><br><span style="color:var(--text-muted); font-size:11.5px;"><?php echo h(date('g:i A', strtotime($req['preferred_time']))); ?></span><?php endif; ?>
                                        </td>
                                        <td style="max-width: 220px; color:var(--text-sub);"><?php echo nl2br(h($req['reason'])); ?></td>
                                        <td>
                                            <?php
                                                $allowedStatuses = ['Pending', 'Approved', 'Rejected'];
                                                $safeStatus = in_array($req['status'], $allowedStatuses, true) ? $req['status'] : 'Pending';
                                                $lowerStatus = strtolower($safeStatus);
                                                if ($lowerStatus === 'approved') {
                                                    echo '<span class="badge-rt success">Approved</span>';
                                                } elseif ($lowerStatus === 'rejected') {
                                                    echo '<span class="badge-rt danger">Rejected</span>';
                                                } else {
                                                    echo '<span class="badge-rt warning">Pending</span>';
                                                }
                                            ?>
                                        </td>
                                        <td>
                                            <?php if ($req['status'] === 'Pending'): ?>
                                                <button class="btn-action-rt btn-approve-rt" onclick="updateStatus(<?php echo (int) $req['id']; ?>, 'Approved')">Approve</button>
                                                <button class="btn-action-rt btn-reject-rt" onclick="updateStatus(<?php echo (int) $req['id']; ?>, 'Rejected')">Reject</button>
                                            <?php else: ?>
                                                <span class="btn-action-rt btn-disabled-rt">Processed</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const csrfToken = <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>;

function updateStatus(id, status) {
    Swal.fire({
        title: `Are you sure you want to ${status.toLowerCase()} this request?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: status === 'Approved' ? '#34d399' : '#e11d48',
        cancelButtonColor: '#64748b',
        confirmButtonText: `Yes, ${status}`,
        background: '#181c24',
        color: '#fff'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('update_reschedule_status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'id=' + encodeURIComponent(id) + '&status=' + encodeURIComponent(status) + '&csrf_token=' + encodeURIComponent(csrfToken)
            })
            .then(res => res.text())
            .then(data => {
                if (data.trim() === 'success') {
                    location.reload();
                } else {
                    Swal.fire({
                        title: 'Error',
                        text: 'Error updating status.',
                        icon: 'error',
                        background: '#181c24',
                        color: '#fff'
                    });
                }
            });
        }
    });
}

// ===== SIDEBAR TOGGLE & COLLAPSE =====
const sidebarToggle = document.getElementById('sidebarToggle');
const sidebarOverlay = document.getElementById('sidebarOverlay');

if (sidebarToggle) {
    sidebarToggle.addEventListener('click', () => {
        if (window.innerWidth <= 992) {
            document.body.classList.toggle('sidebar-open');
        } else {
            document.body.classList.toggle('sidebar-collapsed');
        }
    });
}

if (sidebarOverlay) {
    sidebarOverlay.addEventListener('click', () => {
        document.body.classList.remove('sidebar-open');
    });
}

// ===== NOTIFICATION BELL WITH AUTO-MARK READ =====
const notifBtn = document.getElementById('notifBtn');
const notifDropdown = document.getElementById('notifDropdown');
const notifBadgeDot = document.getElementById('notifBadgeDot');

function clearNotificationDot() {
    if (notifBadgeDot) {
        notifBadgeDot.style.opacity = '0';
        setTimeout(() => { notifBadgeDot.style.display = 'none'; }, 300);
    }
    fetch('reschedule_requests.php?action=mark_notif_read');
}

if (notifBtn) {
    notifBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const isOpen = notifDropdown.style.display === 'block';
        notifDropdown.style.display = isOpen ? 'none' : 'block';
        if (!isOpen) clearNotificationDot();
    });
}

document.getElementById('clearNotifBadge')?.addEventListener('click', clearNotificationDot);

// ===== TOPBAR GLOBAL SEARCH REDIRECT =====
const globalNavSearch = document.getElementById('globalNavSearch');
if (globalNavSearch) {
    globalNavSearch.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            const val = globalNavSearch.value.trim();
            if (val.length > 0) {
                window.location.href = `reschedule_requests.php?search=${encodeURIComponent(val)}`;
            }
        }
    });
}

// ===== PROFILE DROPDOWN =====
const profileToggle = document.getElementById('profileToggle');
const profileDropdown = document.getElementById('profileDropdown');

if (profileToggle) {
    profileToggle.addEventListener('click', (e) => {
        e.stopPropagation();
        profileDropdown.classList.toggle('show');
    });
}

document.addEventListener('click', () => {
    if (profileDropdown) profileDropdown.classList.remove('show');
    if (notifDropdown) notifDropdown.style.display = 'none';
});

// ===== LOGOUT PROMPT =====
function confirmLogout(e) {
    e.preventDefault();
    Swal.fire({
        title: 'Logout from Admin Portal?',
        text: 'Are you sure you want to log out?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e11d48',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, Logout',
        background: '#181c24',
        color: '#fff'
    }).then((res) => {
        if (res.isConfirmed) window.location.href = '../logout.php';
    });
}

document.getElementById('profileLogoutBtn')?.addEventListener('click', confirmLogout);
document.getElementById('sidebarLogoutBtn')?.addEventListener('click', confirmLogout);
</script>

</body>
</html>
