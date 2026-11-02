<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();
date_default_timezone_set('Asia/Manila');

require_once '../config/db.php';
require_once 'includes/auth.php';

/* =====================================================
   HELPER FUNCTIONS
===================================================== */
function tableExists($conn, $tableName) {
    try {
        $stmt = $conn->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :t)");
        $stmt->execute([':t' => strtolower($tableName)]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) { return false; }
}

function columnExists($conn, $tableName, $columnName) {
    if (!tableExists($conn, $tableName)) return false;
    try {
        $stmt = $conn->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :t AND column_name = :c)");
        $stmt->execute([':t' => strtolower($tableName), ':c' => strtolower($columnName)]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) { return false; }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* =====================================================
   ADMIN INFO
===================================================== */
$adminUserId = (int) ($_SESSION['user_id'] ?? 1);
$userName = $_SESSION['fullname'] ?? 'Admin';
$userRole = $_SESSION['role'] ?? 'Administrator';
$userInitial = strtoupper(substr($userName, 0, 1));

/* =====================================================
   AJAX HANDLERS
===================================================== */
if (isset($_GET['action']) && $_GET['action'] === 'mark_notif_read') {
    if (tableExists($conn, 'notifications')) {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = :user_id");
        $stmt->execute([':user_id' => $adminUserId]);
    }
    echo json_encode(['status' => 'success']);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'global_search') {
    $q = trim($_GET['q'] ?? '');
    $results = [];
    if (strlen($q) >= 1) {
        $searchTerm = '%' . $q . '%';
        if (tableExists($conn, 'users')) {
            $stmt = $conn->prepare("
                SELECT id, fullname, email, role::text AS role 
                FROM users 
                WHERE (fullname ILIKE :q OR email ILIKE :q)
                LIMIT 5
            ");
            $stmt->execute([':q' => $searchTerm]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = [
                    'type' => 'User',
                    'title' => $row['fullname'] ?: 'N/A',
                    'sub' => 'Email: ' . ($row['email'] ?: 'N/A') . ' | Role: ' . ucfirst($row['role'] ?? ''),
                    'link' => 'users.php?search=' . urlencode($row['fullname'] ?? '')
                ];
            }
        }
    }
    echo json_encode($results);
    exit;
}

/* =====================================================
   FILTERS & SEARCH
===================================================== */
$searchQuery = trim($_GET['q'] ?? '');
$performanceFilter = trim($_GET['perf'] ?? 'all');

/* =====================================================
   FETCH INSPECTORS
===================================================== */
$inspectorList = [];
if (tableExists($conn, 'users')) {
    $where = "(u.role::text ILIKE 'inspector' OR u.role::text ILIKE 'inspector_staff')";
    $params = [];

    if ($searchQuery !== '') {
        $where .= " AND (u.fullname ILIKE :q OR u.email ILIKE :q)";
        $params[':q'] = '%' . $searchQuery . '%';
    }

    try {
        $sql = "
            SELECT 
                u.id, u.fullname, u.email, u.created_at,
                COUNT(i.id) AS total_inspections,
                COUNT(CASE WHEN i.inspection_status::text ILIKE 'Completed' OR i.inspection_status::text ILIKE 'Passed' THEN 1 END) AS completed_inspections,
                COUNT(CASE WHEN i.inspection_status::text ILIKE 'Failed' OR i.inspection_status::text ILIKE 'Cancelled' THEN 1 END) AS failed_inspections,
                COUNT(CASE WHEN i.inspection_status::text ILIKE 'Scheduled' OR i.inspection_status::text ILIKE 'Ongoing' THEN 1 END) AS pending_inspections,
                MAX(i.inspection_date) AS last_inspection_date
            FROM users u
            LEFT JOIN inspections i ON i.inspector_id = u.id
            WHERE $where
            GROUP BY u.id, u.fullname, u.email, u.created_at
            ORDER BY completed_inspections DESC, u.created_at DESC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $inspectorList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        try {
            $sql = "
                SELECT 
                    u.id, u.fullname, u.email, u.created_at,
                    0 AS total_inspections, 0 AS completed_inspections,
                    0 AS failed_inspections, 0 AS pending_inspections,
                    NULL AS last_inspection_date
                FROM users u
                WHERE $where
                ORDER BY u.created_at DESC
            ";
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $inspectorList = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $ex) {
            $inspectorList = [];
        }
    }
}

/* =====================================================
   FILTER BY PERFORMANCE
===================================================== */
if ($performanceFilter !== 'all' && !empty($inspectorList)) {
    $inspectorList = array_filter($inspectorList, function($ins) use ($performanceFilter) {
        $completed = (int)($ins['completed_inspections'] ?? 0);
        $failed = (int)($ins['failed_inspections'] ?? 0);
        $total = (int)($ins['total_inspections'] ?? 0);

        switch ($performanceFilter) {
            case 'top': return $completed >= 5;
            case 'active': return $total > 0 && $completed > 0;
            case 'inactive': return $total === 0;
            case 'needs_attention': return $failed > 0 && $failed >= $completed;
            default: return true;
        }
    });
    $inspectorList = array_values($inspectorList);
}

/* =====================================================
   COMPUTE STATS
===================================================== */
$totalInspectors = count($inspectorList);
$totalCompleted = 0;
$totalFailed = 0;
$totalInspections = 0;
$activeInspectors = 0;
$topPerformer = null;
$topCompleted = 0;

foreach ($inspectorList as $ins) {
    $completed = (int)($ins['completed_inspections'] ?? 0);
    $failed = (int)($ins['failed_inspections'] ?? 0);
    $total = (int)($ins['total_inspections'] ?? 0);

    $totalCompleted += $completed;
    $totalFailed += $failed;
    $totalInspections += $total;

    if ($completed > 0) $activeInspectors++;
    if ($completed > $topCompleted) {
        $topCompleted = $completed;
        $topPerformer = $ins['fullname'] ?? null;
    }
}

$overallSuccessRate = ($totalCompleted + $totalFailed) > 0 
    ? round(($totalCompleted / ($totalCompleted + $totalFailed)) * 100) 
    : 0;

/* =====================================================
   NOTIFICATIONS
===================================================== */
$notifications = [];
$unreadNotifCount = 0;
if (tableExists($conn, 'notifications')) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = FALSE");
    $stmt->execute([':user_id' => $adminUserId]);
    $unreadNotifCount = (int) $stmt->fetchColumn();

    $hasTargetUrl = columnExists($conn, 'notifications', 'target_url');
    $selectFields = $hasTargetUrl ? "id, title, message, target_url, created_at" : "id, title, message, created_at";
    $stmt = $conn->prepare("SELECT $selectFields FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([':user_id' => $adminUserId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!$hasTargetUrl) $row['target_url'] = 'javascript:void(0)';
        $notifications[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
<meta name="theme-color" content="#dc2626">
<title>Inspector Accounts | RENTRACK LGU Admin</title>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

<style>
    :root {
        --bg-body: #f8fafc;
        --bg-card: #ffffff;
        --bg-card-alt: #f1f5f9;
        --topofficial-bg: linear-gradient(90deg, #991b1b, #dc2626 55%, #991b1b);
        --navbar-bg: #ffffff;
        --red-accent: #dc2626;
        --red-hover: #ef4444;
        --red-soft: #fee2e2;
        --pink-accent: #db2777;
        --gold-accent: #d97706;
        --teal-accent: #0891b2;
        --emerald-accent: #059669;
        --violet-accent: #7c3aed;
        --text-main: #0f172a;
        --text-sub: #334155;
        --text-muted: #64748b;
        --border-color: #e2e8f0;
        --border-strong: #cbd5e1;
        --shadow-soft: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px -1px rgba(0, 0, 0, 0.1);
        --shadow-lift: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1);
        --safe-top: env(safe-area-inset-top, 0px);
        --safe-bottom: env(safe-area-inset-bottom, 0px);
        --safe-left: env(safe-area-inset-left, 0px);
        --safe-right: env(safe-area-inset-right, 0px);
        --topofficial-height: calc(38px + var(--safe-top));
        --navbar-height: 62px;
        --header-total-height: calc(var(--topofficial-height) + var(--navbar-height));
        --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }

    * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    html { -webkit-text-size-adjust: 100%; }

    body {
        background-color: var(--bg-body);
        font-family: 'Plus Jakarta Sans', sans-serif;
        color: var(--text-main);
        min-height: 100vh;
        min-height: 100dvh;
        overflow-x: hidden;
        padding-top: var(--header-total-height);
        -webkit-font-smoothing: antialiased;
        touch-action: manipulation;
    }

    body.drawer-open { overflow: hidden !important; touch-action: none; }

    ::-webkit-scrollbar { width: 6px; height: 6px; }
    ::-webkit-scrollbar-track { background: var(--bg-body); }
    ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 20px; }

    .swal2-backdrop-show {
        background: rgba(0, 0, 0, 0.4) !important;
        backdrop-filter: blur(4px) !important;
        -webkit-backdrop-filter: blur(4px) !important;
    }

    /* TOP OFFICIAL BAR */
    .top-official-bar {
        position: fixed; top: 0; left: 0; width: 100%;
        background: var(--topofficial-bg);
        border-bottom: 1px solid rgba(255, 255, 255, 0.15);
        padding: var(--safe-top) calc(15px + var(--safe-right)) 0 calc(15px + var(--safe-left));
        display: flex; justify-content: space-between; align-items: center;
        font-size: 11px; font-weight: 600; z-index: 1100; height: var(--topofficial-height);
        color: #fff; overflow: hidden;
    }
    .topbar-left { display: flex; align-items: center; gap: 8px; min-width: 0; overflow: hidden; }
    .topbar-left i { font-size: 12px; color: #fecdd3; flex-shrink: 0; }
    .topbar-left strong { font-weight: 800; white-space: nowrap; flex-shrink: 0; }
    .topbar-left .divider { width: 1px; height: 12px; background: rgba(255, 255, 255, 0.3); flex-shrink: 0; }
    .topbar-left .portal-title { color: #ffe4e6; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .topbar-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
    .cert-badge {
        display: inline-flex; align-items: center; gap: 6px; padding: 3px 12px;
        background: rgba(255, 255, 255, 0.15); border: 1px solid rgba(255, 255, 255, 0.3);
        border-radius: 999px; color: #fff; font-weight: 700; font-size: 10px;
    }

    /* MAIN NAVBAR */
    .rt-topbar {
        position: fixed; top: var(--topofficial-height); left: 0; width: 100%;
        background: var(--navbar-bg);
        border-bottom: 1px solid var(--border-color);
        display: flex; align-items: center; justify-content: space-between;
        height: var(--navbar-height); z-index: 1050;
        gap: 12px;
    }
    .rt-topbar-left { display: flex; align-items: center; height: 100%; flex-shrink: 0; min-width: 0; }
    .rt-topbar-brand {
        display: flex; align-items: center; gap: 10px;
        height: 100%; padding: 0 18px 0 calc(18px + var(--safe-left));
        border-right: 1px solid var(--border-color); flex-shrink: 0;
    }
    .rt-topbar-logo {
        width: 36px; height: 36px; border-radius: 10px;
        background: linear-gradient(135deg, var(--red-accent), #991b1b);
        display: flex; align-items: center; justify-content: center;
        font-family: 'Space Grotesk', sans-serif; font-weight: 800; font-size: 13px; color: #fff;
        box-shadow: 0 0 12px rgba(220, 38, 38, 0.2); flex-shrink: 0;
    }
    .rt-topbar-brand-text strong { font-size: 13px; font-weight: 800; color: var(--text-main); display: block; line-height: 1.15; }
    .rt-topbar-brand-text span { font-size: 9px; font-weight: 700; color: var(--red-accent); text-transform: uppercase; }

    .menu-toggle {
        display: none; width: 42px; height: 42px; border-radius: 10px;
        align-items: center; justify-content: center;
        background: var(--bg-card-alt); border: 1px solid var(--border-color);
        color: var(--red-accent); cursor: pointer; font-size: 16px;
        flex-shrink: 0; margin-left: 12px;
    }
    .menu-toggle:active { background: var(--red-accent); color: #fff; border-color: var(--red-accent); }

    /* HORIZONTAL NAV MENU */
    .main-nav-bar {
        flex: 1; display: flex; align-items: center; height: 100%;
        min-width: 0; overflow: visible;
    }
    .nav-menu {
        list-style: none; margin: 0; padding: 0;
        display: flex; align-items: center; gap: 2px;
        flex-wrap: nowrap; height: 100%;
    }
    .nav-item { position: relative; height: 100%; display: flex; align-items: center; }
    .nav-link {
        display: flex; align-items: center; gap: 7px;
        padding: 8px 12px; color: var(--text-sub); text-decoration: none;
        font-size: 12.5px; font-weight: 600; border-radius: 8px;
        transition: all 0.15s ease; cursor: pointer; white-space: nowrap;
        background: transparent; border: none; font-family: inherit; height: 40px;
    }
    .nav-link i.nav-ico { color: var(--red-accent); font-size: 13px; width: 16px; text-align: center; }
    .nav-link .chevron { font-size: 9px; margin-left: 2px; transition: transform 0.2s ease; opacity: 0.7; }
    .nav-link:hover { color: var(--red-accent); background: var(--red-soft); }
    .nav-link.active { color: var(--red-accent); background: var(--red-soft); font-weight: 700; }
    .nav-item.open > .nav-link { color: var(--red-accent); background: var(--red-soft); }
    .nav-item.open > .nav-link .chevron { transform: rotate(180deg); }

    .nav-dropdown {
        position: absolute; top: calc(100% + 4px); left: 0; min-width: 240px;
        background: #ffffff; border: 1px solid var(--border-color);
        border-radius: 12px; box-shadow: 0 12px 30px rgba(0,0,0,0.12);
        padding: 8px; opacity: 0; visibility: hidden; transform: translateY(8px);
        transition: opacity 0.18s ease, transform 0.18s ease, visibility 0.18s;
        z-index: 200;
    }
    .nav-item.open .nav-dropdown { opacity: 1; visibility: visible; transform: translateY(0); }
    .nav-item.align-right .nav-dropdown { left: auto; right: 0; }
    .nav-dropdown a {
        display: flex; align-items: center; gap: 12px; padding: 10px 12px;
        border-radius: 8px; color: var(--text-sub); text-decoration: none;
        font-size: 13px; font-weight: 500; transition: all 0.15s ease;
    }
    .nav-dropdown a:hover { background: var(--red-soft); color: var(--red-accent); }
    .nav-dropdown a i { width: 16px; color: var(--red-accent); font-size: 14px; text-align: center; flex-shrink: 0; }

    .mobile-drawer-header { display: none; }

    /* SEARCH */
    .rt-topbar-center {
        flex: 1; display: flex; justify-content: center;
        padding: 0 12px; max-width: 420px; margin: 0 auto;
        position: relative; min-width: 120px;
    }
    .rt-search-container { position: relative; width: 100%; }
    .rt-search-container i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 13px; }
    .rt-search-container input {
        width: 100%; padding: 8px 16px 8px 38px; border-radius: 999px;
        border: 1px solid var(--border-color); background: var(--bg-card-alt);
        color: var(--text-main); font-size: 13px; outline: none; cursor: pointer;
    }
    .rt-search-container input::placeholder { color: var(--text-muted); }
    .rt-search-container input:focus { background: #fff; border-color: var(--red-accent); box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1); }

    /* RIGHT ICONS */
    .rt-topbar-right { display: flex; align-items: center; gap: 10px; padding-right: calc(18px + var(--safe-right)); flex-shrink: 0; }
    .icon-btn {
        width: 38px; height: 38px; border-radius: 10px;
        border: 1px solid var(--border-color); background: var(--bg-card-alt);
        color: var(--text-main); display: flex; align-items: center; justify-content: center;
        cursor: pointer; position: relative; transition: var(--transition);
    }
    .icon-btn:hover { background: #fff; border-color: var(--red-accent); color: var(--red-accent); }
    .mobile-search-btn { display: none; }
    .notif-badge-dot {
        position: absolute; top: 6px; right: 6px; width: 9px; height: 9px;
        border-radius: 50%; background: var(--red-accent);
        box-shadow: 0 0 8px var(--red-accent);
    }
    .rt-profile-wrapper { position: relative; }
    .rt-profile-btn {
        display: flex; align-items: center; gap: 10px; padding: 4px 14px 4px 4px;
        border-radius: 999px; border: 1px solid var(--border-color);
        background: var(--bg-card-alt); cursor: pointer;
    }
    .rt-profile-btn .avatar {
        width: 32px; height: 32px; border-radius: 50%; background: var(--red-accent);
        color: #fff; display: flex; align-items: center; justify-content: center;
        font-weight: 800; font-size: 13px;
    }
    .rt-profile-btn .info .name { font-size: 12px; font-weight: 700; color: var(--text-main); }
    .rt-profile-btn .info .role { font-size: 9px; font-weight: 700; color: var(--red-accent); text-transform: uppercase; }
    .rt-profile-dropdown {
        position: absolute; top: calc(100% + 10px); right: 0; width: 200px;
        background: #fff; border: 1px solid var(--border-color);
        border-radius: 14px; padding: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        opacity: 0; visibility: hidden; transform: translateY(-8px);
        transition: var(--transition); z-index: 100;
    }
    .rt-profile-dropdown.show { opacity: 1; visibility: visible; transform: translateY(0); }
    .rt-profile-dropdown .dropdown-item {
        display: flex; align-items: center; gap: 10px; padding: 9px 12px;
        border-radius: 8px; color: var(--text-sub); text-decoration: none; font-size: 13px;
    }
    .rt-profile-dropdown .dropdown-item:hover { background: var(--bg-card-alt); color: var(--text-main); }
    .rt-profile-dropdown .dropdown-item.danger { color: #dc2626; }
    .rt-profile-dropdown .dropdown-item.danger:hover { background: #fee2e2; }

    /* LAYOUT */
    .wrapper { display: flex; min-height: calc(100vh - var(--header-total-height)); min-height: calc(100dvh - var(--header-total-height)); }
    .main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
    .sidebar-overlay {
        display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5);
        z-index: 1040; -webkit-backdrop-filter: blur(2px); backdrop-filter: blur(2px);
        opacity: 0; transition: opacity 0.3s ease;
    }
    .sidebar-overlay.active { display: block; opacity: 1; }

    .dashboard-shell { padding: 16px 20px 30px; flex: 1; display: flex; flex-direction: column; }

    /* PAGE HEADER */
    .page-header-card {
        background: #fff1f2;
        border: 1px solid #fecdd3;
        border-radius: 18px; padding: 20px 24px; margin-bottom: 20px;
        display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;
        box-shadow: var(--shadow-soft);
    }
    .page-header-card h2 {
        font-family: 'Space Grotesk', sans-serif; font-size: 22px; font-weight: 800; color: #9f1239; margin: 0;
        display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
    }
    .page-header-card h2 i { color: #e11d48; background: rgba(225, 29, 72, 0.1); padding: 8px; border-radius: 10px; font-size: 16px; }
    .page-header-card p { font-size: 12px; color: #881337; margin: 4px 0 0; font-weight: 500; }
    .header-clock-badge {
        display: flex; align-items: center; gap: 8px; padding: 8px 14px;
        background: #fff; border: 1px solid #fecdd3;
        border-radius: 10px; font-family: 'IBM Plex Mono', monospace;
        font-size: 12px; font-weight: 600; color: #9f1239;
    }
    .header-clock-badge i { color: #e11d48; }
    .header-actions-group { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .btn-rt-secondary {
        background: #fff; color: var(--text-main); border: 1px solid var(--border-color);
        padding: 8px 16px; border-radius: 10px; font-size: 12px; font-weight: 700; text-decoration: none;
        transition: var(--transition); display: inline-flex; align-items: center; gap: 8px;
    }
    .btn-rt-secondary:hover { background: var(--bg-card-alt); color: var(--text-main); border-color: var(--border-strong); }

    /* STATS */
    .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 20px; }
    .stat-card {
        background: var(--bg-card); border: 1px solid var(--border-color);
        border-radius: 14px; padding: 18px;
        display: flex; align-items: center; gap: 14px;
        transition: all 0.25s; box-shadow: var(--shadow-soft);
    }
    .stat-card:hover { transform: translateY(-2px); border-color: var(--border-strong); box-shadow: var(--shadow-lift); }
    .stat-icon {
        width: 46px; height: 46px; border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        font-size: 18px; color: #fff; flex-shrink: 0;
    }
    .stat-icon.pink { background: linear-gradient(135deg, #db2777, #9d174d); }
    .stat-icon.emerald { background: linear-gradient(135deg, #059669, #047857); }
    .stat-icon.red { background: linear-gradient(135deg, #dc2626, #991b1b); }
    .stat-icon.cyan { background: linear-gradient(135deg, #0891b2, #155e75); }
    .stat-content { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
    .stat-value {
        font-family: 'Space Grotesk', sans-serif;
        font-size: 24px; font-weight: 800; color: var(--text-main); line-height: 1.1;
    }
    .stat-label {
        font-size: 10.5px; color: var(--text-muted);
        text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px;
    }
    .stat-sub { font-size: 11px; color: var(--text-sub); margin-top: 2px; }

    /* FILTER BAR */
    .filter-bar {
        background: var(--bg-card); border: 1px solid var(--border-color);
        border-radius: 14px; padding: 16px; margin-bottom: 20px;
        box-shadow: var(--shadow-soft);
    }
    .filter-form { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
    .filter-group { flex: 1; min-width: 200px; display: flex; flex-direction: column; gap: 6px; }
    .filter-group label {
        font-size: 10px; font-weight: 700; color: var(--text-muted);
        text-transform: uppercase; letter-spacing: 0.5px;
    }
    .filter-group input, .filter-group select {
        width: 100%; padding: 10px 14px;
        background: var(--bg-card-alt); color: var(--text-main);
        border: 1px solid var(--border-color);
        border-radius: 10px; font-size: 13px;
        outline: none; transition: all 0.2s;
    }
    .filter-group input:focus, .filter-group select:focus {
        border-color: var(--red-accent); background: #fff;
        box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
    }
    .filter-group select option { background: #fff; color: var(--text-main); }
    .btn-filter {
        padding: 10px 20px; background: var(--red-accent); color: #fff; border: none;
        border-radius: 10px; font-size: 13px; font-weight: 700; cursor: pointer;
        display: inline-flex; align-items: center; gap: 8px;
        transition: all 0.2s; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.2);
    }
    .btn-filter:hover { transform: translateY(-1px); background: #b91c1c; box-shadow: 0 6px 16px rgba(220, 38, 38, 0.3); }
    .btn-clear {
        padding: 10px 18px; background: #fff; border: 1px solid var(--border-color);
        color: var(--text-sub); text-decoration: none; border-radius: 10px;
        font-size: 13px; font-weight: 600;
        display: inline-flex; align-items: center; gap: 6px;
    }
    .btn-clear:hover { background: var(--bg-card-alt); color: var(--text-main); border-color: var(--border-strong); }

    /* PANEL */
    .panel {
        background: var(--bg-card); border: 1px solid var(--border-color);
        border-radius: 14px; overflow: hidden;
        box-shadow: var(--shadow-soft); margin-bottom: 20px;
    }
    .panel-head {
        padding: 18px 22px; border-bottom: 1px solid var(--border-color);
        display: flex; justify-content: space-between; align-items: center;
        flex-wrap: wrap; gap: 10px;
    }
    .panel-head h3 {
        font-family: 'Space Grotesk', sans-serif;
        font-size: 16px; font-weight: 800; color: var(--text-main); margin: 0;
        display: flex; align-items: center; gap: 10px;
    }
    .panel-head h3 i { color: var(--red-accent); }
    .panel-head .count {
        font-size: 11px; color: var(--text-muted);
        background: var(--bg-card-alt); padding: 4px 12px;
        border-radius: 999px; font-weight: 700;
        border: 1px solid var(--border-color);
    }

    /* TABLE */
    .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .insp-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .insp-table thead th {
        text-align: left; padding: 14px 20px;
        color: var(--text-muted); font-size: 10.5px;
        text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px;
        border-bottom: 1px solid var(--border-color);
        background: #f8fafc; white-space: nowrap;
    }
    .insp-table tbody td {
        padding: 16px 20px; border-bottom: 1px solid var(--border-color);
        color: var(--text-sub); vertical-align: middle;
    }
    .insp-table tbody tr { transition: background 0.2s; }
    .insp-table tbody tr:hover { background: #f8fafc; }
    .insp-table tbody tr:last-child td { border-bottom: none; }

    .inspector-cell { display: flex; align-items: center; gap: 12px; min-width: 0; }
    .avatar-initial {
        width: 40px; height: 40px; border-radius: 50%;
        background: linear-gradient(135deg, var(--red-accent), #991b1b);
        color: #fff; display: flex; align-items: center; justify-content: center;
        font-weight: 800; font-size: 15px; flex-shrink: 0;
        box-shadow: 0 3px 10px rgba(220, 38, 38, 0.2);
    }
    .inspector-info { min-width: 0; }
    .inspector-name {
        font-weight: 700; color: var(--text-main); font-size: 13.5px;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .inspector-email {
        font-size: 11px; color: var(--text-muted);
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }

    .badge {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 4px 10px; border-radius: 999px;
        font-size: 11px; font-weight: 700; white-space: nowrap;
    }
    .badge-success { background: rgba(5, 150, 105, 0.1); color: #059669; border: 1px solid rgba(5, 150, 105, 0.2); }
    .badge-danger { background: rgba(220, 38, 38, 0.1); color: #dc2626; border: 1px solid rgba(220, 38, 38, 0.2); }
    .badge-warning { background: rgba(217, 119, 6, 0.1); color: #d97706; border: 1px solid rgba(217, 119, 6, 0.2); }
    .badge-secondary { background: rgba(100, 116, 139, 0.1); color: #475569; border: 1px solid rgba(100, 116, 139, 0.2); }

    .metric-value {
        font-family: 'Space Grotesk', sans-serif;
        font-size: 18px; font-weight: 800; color: var(--text-main); line-height: 1;
    }
    .metric-label {
        font-size: 10px; color: var(--text-muted);
        text-transform: uppercase; font-weight: 600; margin-top: 2px;
    }

    .progress-bar-wrap {
        width: 100px; height: 6px; background: var(--bg-card-alt);
        border-radius: 999px; overflow: hidden; margin-top: 6px;
    }
    .progress-bar-fill { height: 100%; border-radius: 999px; transition: width 0.5s ease; }
    .progress-bar-fill.high { background: linear-gradient(90deg, #059669, #34d399); }
    .progress-bar-fill.mid { background: linear-gradient(90deg, #d97706, #fbbf24); }
    .progress-bar-fill.low { background: linear-gradient(90deg, #dc2626, #f87171); }

    .empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
    .empty-state i {
        font-size: 48px; opacity: 0.3; display: block;
        margin-bottom: 14px; color: var(--red-accent);
    }
    .empty-state h4 { font-size: 16px; color: var(--text-main); font-weight: 700; margin-bottom: 6px; }
    .empty-state p { font-size: 13px; margin: 0; }
    .empty-state a { color: var(--red-accent); font-weight: 600; text-decoration: none; }

    .footer {
        margin-top: auto; padding-top: 14px; padding-bottom: var(--safe-bottom);
        border-top: 1px solid var(--border-color);
        display: flex; justify-content: space-between;
        font-size: 11.5px; color: var(--text-muted); flex-wrap: wrap; gap: 8px;
    }
    .footer .brand { color: var(--red-accent); font-weight: 800; }

    /* RESPONSIVE — 1400px */
    @media (max-width: 1400px) {
        .nav-link { padding: 8px 10px; font-size: 12px; }
        .nav-link .nav-ico { font-size: 12px; }
        .rt-topbar-center { max-width: 320px; }
    }

    /* MOBILE — 1200px drawer mode */
    @media (max-width: 1200px) {
        .rt-topbar-left { flex-shrink: 1; min-width: 0; }
        .menu-toggle { display: flex; }

        .main-nav-bar {
            position: fixed; top: 0; left: -100%;
            width: 85vw; max-width: 320px;
            height: 100vh; height: 100dvh;
            flex-direction: column; align-items: stretch; justify-content: flex-start;
            padding: 0; z-index: 1090; overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            background: #ffffff; box-shadow: 6px 0 25px rgba(0,0,0,0.18);
            flex: none;
        }
        .main-nav-bar.mobile-open { left: 0 !important; }

        .mobile-drawer-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 16px 18px; padding-top: calc(16px + var(--safe-top));
            background: linear-gradient(90deg, #991b1b, #dc2626 55%, #991b1b);
            color: #fff; flex-shrink: 0; border-bottom: 2px solid #7f1d1d;
        }
        .mobile-drawer-header .drawer-brand { display: flex; align-items: center; gap: 10px; }
        .mobile-drawer-header .drawer-brand-logo {
            width: 32px; height: 32px; border-radius: 50%;
            background: #ffffff; color: var(--red-accent);
            display: flex; align-items: center; justify-content: center;
            font-weight: 800; font-size: 12px;
        }
        .mobile-drawer-header .drawer-brand-text h2 { font-size: 13px; font-weight: 800; margin: 0; color: #fff; }
        .mobile-drawer-header .drawer-brand-text span { font-size: 9px; opacity: 0.85; text-transform: uppercase; letter-spacing: 1px; font-weight: 600; }
        .mobile-drawer-header .drawer-close-btn {
            width: 34px; height: 34px; border-radius: 8px;
            background: rgba(255,255,255,0.18); border: 1px solid rgba(255,255,255,0.35);
            color: #fff; cursor: pointer; font-size: 15px;
            display: flex; align-items: center; justify-content: center;
        }
        .mobile-drawer-header .drawer-close-btn:active { background: rgba(255,255,255,0.35); }

        .nav-menu {
            flex-direction: column; width: 100%; justify-content: flex-start;
            gap: 4px; padding: 14px; padding-bottom: calc(20px + var(--safe-bottom));
            height: auto;
        }
        .nav-item { width: 100%; height: auto; display: block; }
        .nav-link {
            padding: 14px 16px; border-radius: 10px;
            justify-content: flex-start; font-size: 14.5px;
            min-height: 48px; width: 100%; height: auto;
        }
        .nav-link .chevron { margin-left: auto; font-size: 11px; }
        .nav-link .nav-ico { font-size: 15px; width: 18px; }

        .nav-dropdown,
        .nav-item.open .nav-dropdown,
        .nav-item.align-right .nav-dropdown {
            position: static; left: auto; right: auto;
            transform: none; opacity: 1; visibility: visible;
            box-shadow: none; border: none;
            max-height: 0; overflow: hidden;
            transition: max-height 0.3s ease, padding 0.2s ease;
            padding: 0 0 0 16px; background: #f8fafc;
            margin-top: 4px; border-radius: 10px; min-width: 0;
        }
        .nav-item.open .nav-dropdown { max-height: 600px; padding: 8px 0 8px 16px; }
        .nav-dropdown a { padding: 12px 14px; min-height: 44px; font-size: 13.5px; }

        .rt-topbar-center {
            order: 5; flex-basis: 100%; max-width: 100%;
            margin-top: 4px; margin-bottom: 4px; padding: 0;
        }
    }

    @media (max-width: 992px) {
        .rt-topbar { flex-wrap: wrap; height: auto; padding-bottom: 6px; }
        .rt-topbar-brand { border-right: none; }
        .rt-topbar-center {
            order: 5; flex-basis: 100%;
            margin-top: 4px; margin-bottom: 4px;
        }
        body { padding-top: calc(var(--topofficial-height) + var(--navbar-height) + 50px); }
        .stats-grid { grid-template-columns: repeat(2, 1fr); }
    }

    @media (max-width: 768px) {
        .rt-topbar-right { gap: 8px; padding-right: calc(12px + var(--safe-right)); }
        .rt-topbar-brand-text { display: none; }
        .dashboard-shell { padding: 12px; }
        .page-header-card { padding: 15px; flex-direction: column; align-items: flex-start; gap: 13px; }
        .page-header-card h2 { font-size: 17px; }
        .header-actions-group { width: 100%; display: grid; grid-template-columns: 1fr 1fr; gap: 7px; }
        .header-actions-group > * { width: 100%; justify-content: center; }
        .header-clock-badge { grid-column: 1 / -1; }

        .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px; }
        .stat-card { padding: 14px; border-radius: 12px; gap: 12px; }
        .stat-icon { width: 42px; height: 42px; font-size: 17px; border-radius: 10px; }
        .stat-value { font-size: 20px; }

        .filter-bar { padding: 12px; margin-bottom: 14px; border-radius: 12px; }
        .filter-form { display: grid; grid-template-columns: 1fr; gap: 8px; }
        .filter-form > .filter-group { min-width: 0; width: 100%; }
        .filter-form > .btn-filter,
        .filter-form > .btn-clear { width: 100%; justify-content: center; }

        .panel { border-radius: 12px; }
        .panel-head { padding: 14px; }
        .panel-head h3 { font-size: 13px; }
        .panel-head .count { font-size: 9.5px; padding: 3px 8px; }

        /* Table → Cards on Mobile */
        .table-wrap { overflow: visible !important; }
        .insp-table,
        .insp-table tbody { display: block; width: 100%; }
        .insp-table thead { display: none; }
        .insp-table tbody { padding: 8px; }

        .insp-table tbody tr {
            display: block; width: 100%; margin: 0 0 10px; padding: 5px 0;
            background: #f8fafc; border: 1px solid var(--border-color);
            border-radius: 12px; overflow: hidden; box-shadow: var(--shadow-soft);
        }
        .insp-table tbody tr:last-child { margin-bottom: 0; }

        .insp-table tbody td {
            display: grid; grid-template-columns: minmax(90px, 34%) minmax(0, 66%);
            align-items: center; gap: 8px; width: 100%; min-width: 0;
            padding: 9px 11px; border-bottom: 1px solid var(--border-color);
            font-size: 11.5px; line-height: 1.4; overflow-wrap: anywhere; word-break: break-word;
        }
        .insp-table tbody td:last-child { border-bottom: 0; }
        .insp-table tbody td::before {
            content: attr(data-label); color: var(--text-muted);
            font-size: 9px; font-weight: 800;
            text-transform: uppercase; letter-spacing: .45px; min-width: 0;
        }
        .insp-table tbody td > * { min-width: 0; max-width: 100%; }
        .insp-table .badge { justify-self: start; width: fit-content; font-size: 9.5px; padding: 3px 8px; }
        .inspector-cell { width: 100%; }

        .progress-bar-wrap { width: 80px; }

        .empty-state { padding: 40px 16px; }
        .empty-state i { font-size: 38px; }
        .empty-state h4 { font-size: 14px; }

        .footer { flex-direction: column; align-items: flex-start; font-size: 10px; }

        #notifDropdown {
            position: fixed !important; top: calc(var(--header-total-height) + 5px) !important;
            right: max(8px, var(--safe-right)) !important; left: max(8px, var(--safe-left)) !important;
            width: auto !important; max-width: none !important; max-height: min(70dvh, 430px); overflow-y: auto;
        }

        .swal2-popup {
            width: calc(100vw - 20px) !important; max-width: 600px !important;
            margin: 10px !important; border-radius: 16px !important;
        }
        #swalSearchInput { min-height: 44px !important; font-size: 16px !important; }
        #swalSearchResults { max-height: 45dvh !important; }
    }

    @media (max-width: 576px) {
        .rt-topbar-center { display: none; }
        .mobile-search-btn { display: flex; }
        .rt-topbar-brand { padding: 0 12px; }
        .rt-topbar-logo { width: 32px; height: 32px; font-size: 12px; }
        .menu-toggle { width: 38px; height: 38px; font-size: 14px; margin-left: 8px; }
        .rt-profile-btn { padding: 4px; }
        .rt-profile-btn .info { display: none !important; }
        .rt-profile-btn i { display: none; }
        .dashboard-shell { padding: 10px 10px 20px; }
        .page-header-card h2 { font-size: 16px; }
    }

    @media (max-width: 480px) {
        .stats-grid { grid-template-columns: 1fr; }
        .header-actions-group { grid-template-columns: 1fr; }
        .stat-value { font-size: 22px; }
    }

    @media (max-width: 430px) {
        .top-official-bar { font-size: 9px; }
        .topbar-left { max-width: 58%; }
        .topbar-left strong { font-size: 9px; }
        .cert-badge { font-size: 7.5px; padding: 3px 7px; }
        .rt-topbar-right { gap: 6px; }
        .mobile-search-btn, .icon-btn { width: 32px; height: 32px; }
    }

    @media (max-width: 380px) {
        .rt-topbar-left .page-title { display: none; }
        .topbar-left strong { max-width: 110px; }
        .insp-table tbody td { grid-template-columns: minmax(76px, 36%) minmax(0, 64%); font-size: 10.5px; }
        .avatar-initial { width: 34px; height: 34px; font-size: 13px; }
    }

    @media (hover: none) and (pointer: coarse) {
        .stat-card:hover, .nav-link:hover, .icon-btn:hover,
        .rt-profile-btn:hover, .btn-rt-secondary:hover, .btn-filter:hover { transform: none; }
    }

    @supports (-webkit-touch-callout: none) {
        input, button, select, textarea { font-size: 16px !important; }
        .rt-search-container input { font-size: 14px !important; }
    }

    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after {
            animation-duration: .01ms !important;
            transition-duration: .01ms !important;
        }
    }
</style>
</head>
<body>

<div class="top-official-bar">
    <div class="topbar-left">
        <i class="fa-solid fa-landmark"></i>
        <strong>REPUBLIC OF THE PHILIPPINES</strong>
        <span class="divider"></span>
        <span class="portal-title">Local Government Unit Regulatory Compliance Portal</span>
    </div>
    <div class="topbar-right">
        <span class="cert-badge"><i class="fa-solid fa-certificate"></i> LGU CERTIFIED PORTAL V2.1</span>
    </div>
</div>

<header class="rt-topbar">
    <div class="rt-topbar-left">
        <button class="menu-toggle" id="menuToggle" aria-label="Toggle Menu"><i class="fa-solid fa-bars"></i></button>

        <div class="rt-topbar-brand">
            <div class="rt-topbar-logo">RT</div>
            <div class="rt-topbar-brand-text">
                <strong>RENTRACK</strong>
                <span>LGU ADMIN</span>
            </div>
        </div>

        <!-- HORIZONTAL NAV MENU -->
        <nav class="main-nav-bar" id="mainNavBar">
            <div class="mobile-drawer-header">
                <div class="drawer-brand">
                    <div class="drawer-brand-logo">RT</div>
                    <div class="drawer-brand-text">
                        <h2>RENTRACK</h2>
                        <span>LGU Admin</span>
                    </div>
                </div>
                <button type="button" class="drawer-close-btn" id="drawerCloseBtn" aria-label="Close menu">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="dashboard.php" class="nav-link">
                        <i class="fa-solid fa-gauge-high nav-ico"></i> Dashboard
                    </a>
                </li>

                <li class="nav-item has-dropdown">
                    <button type="button" class="nav-link" data-dropdown>
                        <i class="fa-solid fa-building nav-ico"></i> Rentals
                        <i class="fa-solid fa-chevron-down chevron"></i>
                    </button>
                    <div class="nav-dropdown">
                        <a href="rentals.php"><i class="fa-solid fa-building"></i> All Rentals</a>
                        <a href="compliance.php"><i class="fa-solid fa-file-shield"></i> Compliance</a>
                    </div>
                </li>

                <li class="nav-item">
                    <a href="users.php" class="nav-link">
                        <i class="fa-solid fa-users nav-ico"></i> Users
                    </a>
                </li>

                <li class="nav-item has-dropdown">
                    <button type="button" class="nav-link" data-dropdown>
                        <i class="fa-solid fa-clipboard-check nav-ico"></i> Operations
                        <i class="fa-solid fa-chevron-down chevron"></i>
                    </button>
                    <div class="nav-dropdown">
                        <a href="payments.php"><i class="fa-solid fa-cash-register"></i> Payments</a>
                        <a href="inspections.php"><i class="fa-solid fa-clipboard-check"></i> Inspections</a>
                    </div>
                </li>

                <li class="nav-item has-dropdown">
                    <button type="button" class="nav-link active" data-dropdown>
                        <i class="fa-solid fa-user-tie nav-ico"></i> Staff
                        <i class="fa-solid fa-chevron-down chevron"></i>
                    </button>
                    <div class="nav-dropdown">
                        <a href="staff_accounts.php"><i class="fa-solid fa-user-tie"></i> Staff Accounts</a>
                        <a href="inspector_accounts.php"><i class="fa-solid fa-user-shield"></i> Inspector Accounts</a>
                    </div>
                </li>

                <li class="nav-item has-dropdown align-right">
                    <button type="button" class="nav-link" data-dropdown>
                        <i class="fa-solid fa-chart-simple nav-ico"></i> Reports
                        <i class="fa-solid fa-chevron-down chevron"></i>
                    </button>
                    <div class="nav-dropdown">
                        <a href="reports.php"><i class="fa-solid fa-chart-simple"></i> Reports</a>
                        <a href="map_overview.php"><i class="fa-solid fa-map-location-dot"></i> GIS Mapping</a>
                        <a href="activity_logs.php"><i class="fa-solid fa-clock-rotate-left"></i> Audit Logs</a>
                        <a href="settings.php"><i class="fa-solid fa-gear"></i> Settings</a>
                    </div>
                </li>
            </ul>
        </nav>
    </div>

    <div class="rt-topbar-center">
        <div class="rt-search-container">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" id="openSearchModalBtn" placeholder="Search inspector by name or email..." readonly>
        </div>
    </div>

    <div class="rt-topbar-right">
        <button class="icon-btn mobile-search-btn" id="mobileSearchBtn" title="Search">
            <i class="fa-solid fa-magnifying-glass"></i>
        </button>

        <div style="position:relative;">
            <button class="icon-btn" id="notifBtn" title="Notifications">
                <i class="fa-regular fa-bell"></i>
                <?php if ($unreadNotifCount > 0): ?>
                    <span class="notif-badge-dot" id="notifBadgeDot"></span>
                <?php endif; ?>
            </button>
            <div id="notifDropdown" style="display:none; position:absolute; top:46px; right:0; width:320px; background:#fff; border:1px solid var(--border-color); border-radius:14px; padding:12px; box-shadow:0 10px 30px rgba(0,0,0,0.1); z-index:999;">
                <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); padding-bottom:8px; margin-bottom:8px;">
                    <span style="font-weight:700; color:var(--text-main); font-size:12px;">System Notifications</span>
                    <span style="font-size:10px; color:var(--red-accent); cursor:pointer;" id="clearNotifBadge">Mark all read</span>
                </div>
                <div>
                    <?php if (!empty($notifications)): ?>
                        <?php foreach($notifications as $n): ?>
                            <a href="<?php echo h($n['target_url'] ?: 'javascript:void(0)'); ?>" style="display:block; padding:9px 8px; border-bottom:1px solid rgba(0,0,0,0.05); border-radius:6px; text-decoration:none;" onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='transparent'">
                                <div style="font-size:11.5px; font-weight:700; color:var(--text-main);"><?php echo h($n['title']); ?></div>
                                <div style="font-size:10.5px; color:var(--text-muted); margin-top:2px;"><?php echo h($n['message']); ?></div>
                                <div style="font-size:9px; color:var(--red-accent); margin-top:4px;"><?php echo date('M d, h:i A', strtotime($n['created_at'])); ?></div>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="font-size:11px; color:var(--text-muted); text-align:center; padding:14px 0;">No new notifications</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="rt-profile-wrapper">
            <div class="rt-profile-btn" id="profileToggle">
                <div class="avatar"><?php echo $userInitial; ?></div>
                <div class="info d-none d-sm-flex">
                    <span class="name"><?php echo h($userName); ?></span>
                    <span class="role"><?php echo h($userRole); ?></span>
                </div>
                <i class="fa-solid fa-chevron-down" style="font-size:10px; color:var(--text-muted);"></i>
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

<div class="wrapper">
    <div class="main">
        <div class="dashboard-shell">

            <div class="page-header-card">
                <div>
                    <h2><i class="fa-solid fa-user-shield"></i> Inspector Accounts</h2>
                    <p>Manage LGU inspector accounts and monitor their field performance.</p>
                </div>
                <div class="header-actions-group">
                    <div class="header-clock-badge" id="liveDateTime"></div>
                    <a href="dashboard.php" class="btn-rt-secondary">
                        <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon pink"><i class="fa-solid fa-user-shield"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format($totalInspectors); ?></div>
                        <div class="stat-label">Total Inspectors</div>
                        <div class="stat-sub"><?php echo number_format($activeInspectors); ?> active</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon emerald"><i class="fa-solid fa-check-circle"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format($totalCompleted); ?></div>
                        <div class="stat-label">Completed</div>
                        <div class="stat-sub">Successful inspections</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon red"><i class="fa-solid fa-times-circle"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format($totalFailed); ?></div>
                        <div class="stat-label">Failed / Cancelled</div>
                        <div class="stat-sub">Needs attention</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon cyan"><i class="fa-solid fa-percent"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $overallSuccessRate; ?>%</div>
                        <div class="stat-label">Overall Success</div>
                        <div class="stat-sub">Completion rate</div>
                    </div>
                </div>
            </div>

            <div class="filter-bar">
                <form method="GET" class="filter-form">
                    <div class="filter-group">
                        <label>Search Inspector</label>
                        <input type="text" name="q" placeholder="Search by name or email..." value="<?php echo h($searchQuery); ?>">
                    </div>

                    <div class="filter-group">
                        <label>Performance Filter</label>
                        <select name="perf">
                            <option value="all" <?php echo $performanceFilter === 'all' ? 'selected' : ''; ?>>All Inspectors</option>
                            <option value="top" <?php echo $performanceFilter === 'top' ? 'selected' : ''; ?>>Top Performers (5+)</option>
                            <option value="active" <?php echo $performanceFilter === 'active' ? 'selected' : ''; ?>>Active (with inspections)</option>
                            <option value="inactive" <?php echo $performanceFilter === 'inactive' ? 'selected' : ''; ?>>Inactive (no inspections)</option>
                            <option value="needs_attention" <?php echo $performanceFilter === 'needs_attention' ? 'selected' : ''; ?>>Needs Attention</option>
                        </select>
                    </div>

                    <button type="submit" class="btn-filter">
                        <i class="fa-solid fa-search"></i> Apply Filter
                    </button>

                    <?php if ($searchQuery !== '' || $performanceFilter !== 'all'): ?>
                        <a href="inspector_accounts.php" class="btn-clear">
                            <i class="fa-solid fa-rotate-left"></i> Clear
                        </a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <h3><i class="fa-solid fa-list"></i> Inspector List</h3>
                    <span class="count">
                        <i class="fa-solid fa-database"></i> <?php echo number_format($totalInspectors); ?> record<?php echo $totalInspectors === 1 ? '' : 's'; ?>
                    </span>
                </div>

                <div class="table-wrap">
                    <?php if (!empty($inspectorList)): ?>
                        <table class="insp-table">
                            <thead>
                                <tr>
                                    <th>Inspector</th>
                                    <th>Total</th>
                                    <th>Completed</th>
                                    <th>Failed</th>
                                    <th>Pending</th>
                                    <th>Success Rate</th>
                                    <th>Last Inspection</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($inspectorList as $ins): 
                                    $total = (int)($ins['total_inspections'] ?? 0);
                                    $completed = (int)($ins['completed_inspections'] ?? 0);
                                    $failed = (int)($ins['failed_inspections'] ?? 0);
                                    $pending = (int)($ins['pending_inspections'] ?? 0);
                                    $lastInsp = $ins['last_inspection_date'] ?? null;

                                    $successRate = ($completed + $failed) > 0 
                                        ? round(($completed / ($completed + $failed)) * 100) 
                                        : 0;

                                    $rateClass = 'low';
                                    if ($successRate >= 80) $rateClass = 'high';
                                    elseif ($successRate >= 50) $rateClass = 'mid';
                                ?>
                                    <tr>
                                        <td data-label="Inspector">
                                            <div class="inspector-cell">
                                                <div class="avatar-initial">
                                                    <?php echo strtoupper(substr($ins['fullname'] ?? 'I', 0, 1)); ?>
                                                </div>
                                                <div class="inspector-info">
                                                    <div class="inspector-name"><?php echo h($ins['fullname'] ?? 'N/A'); ?></div>
                                                    <div class="inspector-email">
                                                        <i class="fa-regular fa-envelope" style="font-size:9px;"></i>
                                                        <?php echo h($ins['email'] ?? 'No email'); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td data-label="Total">
                                            <div class="metric-value"><?php echo number_format($total); ?></div>
                                            <div class="metric-label">Total</div>
                                        </td>
                                        <td data-label="Completed">
                                            <span class="badge badge-success">
                                                <i class="fa-solid fa-check"></i> <?php echo number_format($completed); ?>
                                            </span>
                                        </td>
                                        <td data-label="Failed">
                                            <span class="badge badge-danger">
                                                <i class="fa-solid fa-times"></i> <?php echo number_format($failed); ?>
                                            </span>
                                        </td>
                                        <td data-label="Pending">
                                            <span class="badge badge-warning">
                                                <i class="fa-solid fa-clock"></i> <?php echo number_format($pending); ?>
                                            </span>
                                        </td>
                                        <td data-label="Success Rate">
                                            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                                                <div>
                                                    <div class="metric-value" style="font-size:15px; color: <?php echo $rateClass === 'high' ? '#059669' : ($rateClass === 'mid' ? '#d97706' : '#dc2626'); ?>;">
                                                        <?php echo $successRate; ?>%
                                                    </div>
                                                </div>
                                                <div class="progress-bar-wrap">
                                                    <div class="progress-bar-fill <?php echo $rateClass; ?>" style="width: <?php echo $successRate; ?>%;"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td data-label="Last Inspection">
                                            <?php if ($lastInsp): ?>
                                                <span style="color:var(--text-sub); font-size:12px;">
                                                    <i class="fa-regular fa-calendar"></i>
                                                    <?php echo date('M d, Y', strtotime($lastInsp)); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-secondary">No activity</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fa-solid fa-user-shield"></i>
                            <h4>No Inspector Accounts Found</h4>
                            <p>
                                <?php if ($searchQuery !== '' || $performanceFilter !== 'all'): ?>
                                    Walang tumutugma sa iyong filter. Subukan ang ibang search terms o <a href="inspector_accounts.php">i-clear ang filter</a>.
                                <?php else: ?>
                                    Wala pang inspector accounts sa system. Mag-register ng inspector accounts para makita sila dito.
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <footer class="footer">
                <span>&copy; <?php echo date('Y'); ?> <span class="brand">RENTRACK LGU</span> &middot; Admin Portal</span>
                <span>Inspector Management System</span>
            </footer>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    /* ============ DRAWER / NAV MENU ============ */
    const mainNavBar = document.getElementById('mainNavBar');
    const menuToggle = document.getElementById('menuToggle');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const drawerCloseBtn = document.getElementById('drawerCloseBtn');

    function openDrawer() {
        mainNavBar.classList.add('mobile-open');
        sidebarOverlay.classList.add('active');
        document.body.classList.add('drawer-open');
    }
    function closeDrawer() {
        mainNavBar.classList.remove('mobile-open');
        sidebarOverlay.classList.remove('active');
        document.body.classList.remove('drawer-open');
    }

    menuToggle?.addEventListener('click', openDrawer);
    sidebarOverlay?.addEventListener('click', closeDrawer);
    drawerCloseBtn?.addEventListener('click', closeDrawer);

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeDrawer();
            document.querySelectorAll('.nav-item.has-dropdown.open').forEach(function(item) {
                item.classList.remove('open');
            });
        }
    });

    document.querySelectorAll('.nav-item.has-dropdown > .nav-link').forEach(function(trigger) {
        trigger.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();

            const parent = trigger.parentElement;
            const wasOpen = parent.classList.contains('open');

            document.querySelectorAll('.nav-item.has-dropdown.open').forEach(function(item) {
                if (item !== parent) item.classList.remove('open');
            });

            parent.classList.toggle('open', !wasOpen);
        });
    });

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.nav-item.has-dropdown')) {
            document.querySelectorAll('.nav-item.has-dropdown.open').forEach(function(item) {
                item.classList.remove('open');
            });
        }
    });

    mainNavBar.querySelectorAll('.nav-dropdown a, .nav-link:not([data-dropdown])').forEach(function(link) {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 1200) closeDrawer();
        });
    });

    window.addEventListener('resize', function() {
        if (window.innerWidth > 1200) closeDrawer();
    });

    /* ============ LIVE CLOCK ============ */
    function updateLiveDateTime() {
        const now = new Date();
        const options = { 
            timeZone: 'Asia/Manila', month: 'short', day: 'numeric', year: 'numeric',
            hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true 
        };
        const el = document.getElementById('liveDateTime');
        if (el) el.innerHTML = `<i class="fa-regular fa-clock"></i> ${now.toLocaleString('en-US', options)}`;
    }
    setInterval(updateLiveDateTime, 1000);
    updateLiveDateTime();

    /* ============ NOTIFICATIONS ============ */
    const notifBtn = document.getElementById('notifBtn');
    const notifDropdown = document.getElementById('notifDropdown');
    const notifBadgeDot = document.getElementById('notifBadgeDot');

    function clearNotificationDot() {
        if (notifBadgeDot) {
            notifBadgeDot.style.opacity = '0';
            setTimeout(() => { notifBadgeDot.style.display = 'none'; }, 300);
        }
        fetch('inspector_accounts.php?action=mark_notif_read');
    }

    notifBtn?.addEventListener('click', (e) => {
        e.stopPropagation();
        const isOpen = notifDropdown.style.display === 'block';
        notifDropdown.style.display = isOpen ? 'none' : 'block';
        if (!isOpen) clearNotificationDot();
    });
    document.getElementById('clearNotifBadge')?.addEventListener('click', clearNotificationDot);

    /* ============ PROFILE ============ */
    const profileToggle = document.getElementById('profileToggle');
    const profileDropdown = document.getElementById('profileDropdown');
    profileToggle?.addEventListener('click', (e) => {
        e.stopPropagation();
        profileDropdown.classList.toggle('show');
    });
    document.addEventListener('click', () => {
        profileDropdown?.classList.remove('show');
        if (notifDropdown) notifDropdown.style.display = 'none';
    });

    /* ============ SEARCH MODAL ============ */
    const openSearchModalBtn = document.getElementById('openSearchModalBtn');
    document.getElementById('mobileSearchBtn')?.addEventListener('click', () => openSearchModalBtn?.click());

    openSearchModalBtn?.addEventListener('click', () => {
        Swal.fire({
            html: `
                <div style="text-align: left;">
                    <div style="position: relative; margin-bottom: 15px;">
                        <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #64748b; font-size: 15px;"></i>
                        <input type="text" id="swalSearchInput" placeholder="Search inspector by name or email..." autocomplete="off" style="width: 100%; padding: 12px 16px 12px 42px; background: #f8fafc; color: #0f172a; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 13px; outline: none;">
                    </div>
                    <div id="swalSearchResults" style="min-height: 120px; max-height: 280px; overflow-y: auto; text-align: center; color: #64748b; display: flex; flex-direction: column; justify-content: center; align-items: center;">
                        <i class="fa-solid fa-keyboard" style="font-size: 28px; margin-bottom: 8px; color: #cbd5e1;"></i>
                        <span style="font-size: 12px;">Type at least 2 characters to search records...</span>
                    </div>
                </div>
            `,
            showConfirmButton: false,
            showCloseButton: true,
            background: '#ffffff',
            color: '#0f172a',
            width: '600px',
            didOpen: () => {
                const inputEl = document.getElementById('swalSearchInput');
                const resultsEl = document.getElementById('swalSearchResults');
                inputEl.focus();
                let debounceTimer;
                inputEl.addEventListener('input', (e) => {
                    clearTimeout(debounceTimer);
                    const query = e.target.value.trim();
                    if (query.length < 2) {
                        resultsEl.style.justifyContent = 'center';
                        resultsEl.innerHTML = `<i class="fa-solid fa-keyboard" style="font-size: 28px; margin-bottom: 8px; color: #cbd5e1;"></i><span style="font-size: 12px;">Type at least 2 characters...</span>`;
                        return;
                    }
                    resultsEl.style.justifyContent = 'flex-start';
                    resultsEl.innerHTML = `<div style="text-align: center; padding: 20px; font-size: 12px;"><i class="fa-solid fa-spinner fa-spin me-2"></i> Searching...</div>`;
                    debounceTimer = setTimeout(() => {
                        fetch(`inspector_accounts.php?action=global_search&q=${encodeURIComponent(query)}`)
                            .then(res => res.json())
                            .then(data => {
                                resultsEl.innerHTML = '';
                                if (data.length === 0) {
                                    resultsEl.style.justifyContent = 'center';
                                    resultsEl.innerHTML = '<div style="padding:20px; font-size:12px; text-align:center;">No matching records found</div>';
                                } else {
                                    data.forEach(item => {
                                        resultsEl.innerHTML += `
                                            <a href="${item.link}" style="display:flex; flex-direction:column; padding:10px 12px; border-radius:8px; text-decoration:none; color:#0f172a; border-bottom:1px solid #e2e8f0; margin-bottom:6px; background: #f8fafc; text-align: left;" onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='#f8fafc'">
                                                <span style="font-size:9.5px; font-weight:700; color:#dc2626; text-transform:uppercase;">${item.type}</span>
                                                <span style="font-size:13px; font-weight:700; color:#0f172a;">${item.title}</span>
                                                <span style="font-size:11px; color:#64748b;">${item.sub}</span>
                                            </a>
                                        `;
                                    });
                                }
                            });
                    }, 250);
                });
            }
        });
    });

    /* ============ LOGOUT ============ */
    function confirmLogout(e) {
        e.preventDefault();
        Swal.fire({
            title: 'Logout from Admin Portal?',
            text: 'Are you sure you want to log out?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, Logout',
            background: '#ffffff',
            color: '#0f172a'
        }).then((res) => {
            if (res.isConfirmed) window.location.href = '../logout.php';
        });
    }
    document.getElementById('profileLogoutBtn')?.addEventListener('click', confirmLogout);
</script>
</body>
</html>