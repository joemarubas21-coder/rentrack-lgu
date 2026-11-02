<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();

require_once '../config/db.php';
require_once 'includes/auth.php';

/* =====================================================
   HELPER FUNCTIONS (PDO / PostgreSQL Compatible)
===================================================== */

function tableExists($conn, $tableName)
{
    try {
        $stmt = $conn->prepare("
            SELECT EXISTS (
                SELECT 1 
                FROM information_schema.tables 
                WHERE table_schema = 'public' 
                AND table_name = :table
            )
        ");
        $stmt->execute([':table' => strtolower($tableName)]);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

function columnExists($conn, $tableName, $columnName)
{
    if (!tableExists($conn, $tableName)) {
        return false;
    }
    try {
        $stmt = $conn->prepare("
            SELECT EXISTS (
                SELECT 1 
                FROM information_schema.columns 
                WHERE table_schema = 'public' 
                AND table_name = :table 
                AND column_name = :column
            )
        ");
        $stmt->execute([
            ':table' => strtolower($tableName),
            ':column' => strtolower($columnName)
        ]);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

$adminUserId = (int) ($_SESSION['user_id'] ?? 1);

/* =====================================================
   AJAX HANDLERS
===================================================== */

if (isset($_GET['action']) && $_GET['action'] === 'mark_notif_read') {
    if (tableExists($conn, 'notifications')) {
        $notifId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($notifId > 0) {
            $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE id = :notif_id AND user_id = :user_id");
            $stmt->execute([':notif_id' => $notifId, ':user_id' => $adminUserId]);
        } else {
            $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = :user_id");
            $stmt->execute([':user_id' => $adminUserId]);
        }
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
                SELECT id, fullname, username, email, role 
                FROM users 
                WHERE fullname ILIKE :q OR username ILIKE :q OR email ILIKE :q 
                LIMIT 5
            ");
            $stmt->execute([':q' => $searchTerm]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = [
                    'type' => 'System User',
                    'title' => ($row['fullname'] ?? '') . ' (' . ($row['username'] ?? '') . ')',
                    'sub' => 'Role: ' . ucfirst($row['role'] ?? '') . ' | Email: ' . ($row['email'] ?? ''),
                    'link' => 'view_user.php?id=' . $row['id']
                ];
            }
        }
    }

    echo json_encode($results);
    exit;
}

/* =====================================================
   DELETE ALL USERS
===================================================== */
$deleteMessage = "";
$deleteMsgType = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_all_users'])) {
    try {
        $currentId = (int)($_SESSION['user_id'] ?? 0);
        if ($currentId > 0) {
            $stmt = $conn->prepare("DELETE FROM users WHERE id != :current_id");
            $stmt->execute([':current_id' => $currentId]);
        } else {
            $conn->exec("DELETE FROM users");
        }
        $deleteMessage = "All users have been permanently deleted (excluding your own admin account).";
        $deleteMsgType = "success";
    } catch (PDOException $e) {
        $deleteMessage = "Error deleting users: " . safe_error($e);
        $deleteMsgType = "danger";
    }
}

/* =====================================================
   DELETE SINGLE USER
===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user_id'])) {
    $delId = (int)$_POST['delete_user_id'];
    $currentId = (int)($_SESSION['user_id'] ?? 0);

    if ($delId === $currentId) {
        $deleteMessage = "You cannot delete your own account while logged in.";
        $deleteMsgType = "danger";
    } elseif ($delId > 0) {
        try {
            $stmt = $conn->prepare("DELETE FROM users WHERE id = :id");
            $stmt->execute([':id' => $delId]);
            $deleteMessage = "User #{$delId} has been permanently deleted.";
            $deleteMsgType = "success";
        } catch (PDOException $e) {
            $deleteMessage = "Error deleting user: " . safe_error($e);
            $deleteMsgType = "danger";
        }
    }
}

/* =====================================================
   USER COUNTS
===================================================== */

$totalUsers    = ['total' => 0];
$activeUsers   = ['total' => 0];
$inactiveUsers = ['total' => 0];

if (tableExists($conn, 'users')) {
    $totalUsers['total']    = (int) $conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $activeUsers['total']   = (int) $conn->query("SELECT COUNT(*) FROM users WHERE status::text ILIKE 'Active'")->fetchColumn();
    $inactiveUsers['total'] = (int) $conn->query("SELECT COUNT(*) FROM users WHERE status::text ILIKE 'Inactive'")->fetchColumn();
}

/* PENDING ACCOUNT REQUESTS */
$pendingRequestsCount = 0;
if (tableExists($conn, 'account_requests')) {
    $pendingRequestsCount = (int) $conn->query("SELECT COUNT(*) FROM account_requests WHERE status::text ILIKE 'Pending'")->fetchColumn();
}

/* ROLE COUNTS */
$totalAdmin     = ['total' => 0];
$totalInspector = ['total' => 0];
$totalStaff     = ['total' => 0];
$totalOwner     = ['total' => 0];

if (tableExists($conn, 'users')) {
    $totalAdmin['total']     = (int) $conn->query("SELECT COUNT(*) FROM users WHERE role::text ILIKE 'admin'")->fetchColumn();
    $totalInspector['total'] = (int) $conn->query("SELECT COUNT(*) FROM users WHERE role::text ILIKE 'inspector'")->fetchColumn();
    $totalStaff['total']     = (int) $conn->query("SELECT COUNT(*) FROM users WHERE role::text ILIKE 'staff'")->fetchColumn();
    $totalOwner['total']     = (int) $conn->query("SELECT COUNT(*) FROM users WHERE role::text ILIKE 'owner'")->fetchColumn();
}

/* USER LIST */
$usersList = [];
if (tableExists($conn, 'users')) {
    $stmt = $conn->query("SELECT * FROM users ORDER BY id DESC");
    if ($stmt) {
        $usersList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$userName = $_SESSION['fullname'] ?? ($_SESSION['user_name'] ?? 'Admin');
$userRole = $_SESSION['role'] ?? ($_SESSION['user_role'] ?? 'Administrator');
$userInitial = strtoupper(substr($userName, 0, 1));

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
        if (!$hasTargetUrl) {
            $row['target_url'] = 'javascript:void(0)';
        }
        $notifications[] = $row;
    }
}

$searchQuery = trim($_GET['search'] ?? '');
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
<meta name="theme-color" content="#dc2626">
<title>User Management | RENTRACK LGU</title>

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
        flex-wrap: nowrap; overflow: hidden;
        font-size: 11px; font-weight: 600; z-index: 1100; height: var(--topofficial-height);
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

    /* MAIN NAVBAR */
    .rt-topbar {
        position: fixed;
        top: var(--topofficial-height); left: 0; width: 100%;
        background: var(--navbar-bg);
        border-bottom: 1px solid var(--border-color);
        padding: 0;
        display: flex; align-items: center; justify-content: space-between;
        height: var(--navbar-height);
        z-index: 1050;
        gap: 12px;
    }

    .rt-topbar-left { display: flex; align-items: center; height: 100%; flex-shrink: 0; min-width: 0; }

    .rt-topbar-brand { 
        display: flex; align-items: center; gap: 10px;
        height: 100%; padding: 0 18px 0 calc(18px + var(--safe-left));
        border-right: 1px solid var(--border-color);
        flex-shrink: 0;
    }

    .rt-topbar-logo {
        width: 36px; height: 36px; border-radius: 10px;
        background: linear-gradient(135deg, var(--red-accent), #991b1b);
        display: flex; align-items: center; justify-content: center;
        font-family: 'Space Grotesk', sans-serif; font-weight: 800; font-size: 13px; color: #fff;
        box-shadow: 0 0 12px rgba(220, 38, 38, 0.2); flex-shrink: 0;
    }
    .rt-topbar-brand-text { display: flex; flex-direction: column; line-height: 1.15; }
    .rt-topbar-brand-text strong { font-size: 13px; font-weight: 800; color: var(--text-main); letter-spacing: 0.3px; }
    .rt-topbar-brand-text span { font-size: 9px; font-weight: 700; color: var(--red-accent); text-transform: uppercase; letter-spacing: 0.06em; }

    .menu-toggle {
        display: none;
        width: 42px; height: 42px; border-radius: 10px;
        align-items: center; justify-content: center;
        background: var(--bg-card-alt); border: 1px solid var(--border-color);
        color: var(--red-accent); cursor: pointer; font-size: 16px;
        flex-shrink: 0; margin-left: 12px;
    }
    .menu-toggle:active { background: var(--red-accent); color: #fff; border-color: var(--red-accent); }

    /* HORIZONTAL NAV MENU */
    .main-nav-bar {
        flex: 1;
        display: flex;
        align-items: center;
        height: 100%;
        min-width: 0;
        overflow: visible;
    }

    .nav-menu {
        list-style: none; margin: 0; padding: 0;
        display: flex; align-items: center; gap: 2px;
        flex-wrap: nowrap; height: 100%;
    }

    .nav-item { position: relative; height: 100%; display: flex; align-items: center; }

    .nav-link {
        display: flex; align-items: center; gap: 7px;
        padding: 8px 12px;
        color: var(--text-sub);
        text-decoration: none;
        font-size: 12.5px; font-weight: 600;
        border-radius: 8px;
        transition: all 0.15s ease;
        cursor: pointer; white-space: nowrap;
        background: transparent; border: none; font-family: inherit;
        height: 40px;
    }

    .nav-link i.nav-ico { color: var(--red-accent); font-size: 13px; width: 16px; text-align: center; }
    .nav-link .chevron { font-size: 9px; margin-left: 2px; transition: transform 0.2s ease; opacity: 0.7; }
    .nav-link:hover { color: var(--red-accent); background: var(--red-soft); }
    .nav-link.active { color: var(--red-accent); background: var(--red-soft); font-weight: 700; }
    .nav-item.open > .nav-link { color: var(--red-accent); background: var(--red-soft); }
    .nav-item.open > .nav-link .chevron { transform: rotate(180deg); }

    .nav-dropdown {
        position: absolute;
        top: calc(100% + 4px);
        left: 0;
        min-width: 240px;
        background: #ffffff;
        border: 1px solid var(--border-color);
        border-radius: 12px;
        box-shadow: 0 12px 30px rgba(0,0,0,0.12);
        padding: 8px;
        opacity: 0; visibility: hidden;
        transform: translateY(8px);
        transition: opacity 0.18s ease, transform 0.18s ease, visibility 0.18s;
        z-index: 200;
    }
    .nav-item.open .nav-dropdown {
        opacity: 1; visibility: visible; transform: translateY(0);
    }
    .nav-item.align-right .nav-dropdown { left: auto; right: 0; }

    .nav-dropdown a {
        display: flex; align-items: center; gap: 12px;
        padding: 10px 12px;
        border-radius: 8px;
        color: var(--text-sub);
        text-decoration: none;
        font-size: 13px; font-weight: 500;
        transition: all 0.15s ease;
    }
    .nav-dropdown a:hover { background: var(--red-soft); color: var(--red-accent); }
    .nav-dropdown a i { width: 16px; color: var(--red-accent); font-size: 14px; text-align: center; flex-shrink: 0; }

    .mobile-drawer-header { display: none; }

    /* SEARCH */
    .rt-topbar-center {
        flex: 1;
        display: flex;
        justify-content: center;
        padding: 0 12px;
        max-width: 420px;
        margin: 0 auto;
        position: relative;
        min-width: 120px;
    }

    .rt-search-container { position: relative; width: 100%; }
    .rt-search-container i {
        position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
        color: var(--text-muted); font-size: 13px;
    }
    .rt-search-container input {
        width: 100%; padding: 8px 16px 8px 38px; border-radius: 999px;
        border: 1px solid var(--border-color); background: var(--bg-card-alt);
        color: var(--text-main); font-size: 13px; outline: none; transition: var(--transition);
        cursor: pointer;
    }
    .rt-search-container input::placeholder { color: var(--text-muted); }
    .rt-search-container input:focus {
        background: #fff; border-color: var(--red-accent);
        box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
    }

    /* RIGHT SIDE ICONS */
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
        transition: opacity 0.3s ease;
    }

    .rt-profile-wrapper { position: relative; }
    .rt-profile-btn {
        display: flex; align-items: center; gap: 10px; padding: 4px 14px 4px 4px;
        border-radius: 999px; border: 1px solid var(--border-color);
        background: var(--bg-card-alt); cursor: pointer; transition: var(--transition);
    }
    .rt-profile-btn:hover { border-color: var(--red-accent); background: #fff; }
    .rt-profile-btn .avatar {
        width: 32px; height: 32px; border-radius: 50%;
        background: var(--red-accent); color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-weight: 800; font-size: 13px;
    }
    .rt-profile-btn .info { display: flex; flex-direction: column; line-height: 1.1; }
    .rt-profile-btn .info .name { font-size: 12px; font-weight: 700; color: var(--text-main); }
    .rt-profile-btn .info .role { font-size: 9px; font-weight: 700; color: var(--red-accent); text-transform: uppercase; }

    .rt-profile-dropdown {
        position: absolute; top: calc(100% + 10px); right: 0;
        width: 200px; background: #fff; border: 1px solid var(--border-color);
        border-radius: 14px; padding: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        opacity: 0; visibility: hidden; transform: translateY(-8px);
        transition: var(--transition); z-index: 100;
    }
    .rt-profile-dropdown.show { opacity: 1; visibility: visible; transform: translateY(0); }

    .rt-profile-dropdown .dropdown-item {
        display: flex; align-items: center; gap: 10px; padding: 9px 12px;
        border-radius: 8px; color: var(--text-sub); text-decoration: none;
        font-size: 13px; font-weight: 500; transition: var(--transition);
    }
    .rt-profile-dropdown .dropdown-item:hover { background: var(--bg-card-alt); color: var(--text-main); }
    .rt-profile-dropdown .dropdown-item.danger { color: #dc2626; }
    .rt-profile-dropdown .dropdown-item.danger:hover { background: #fee2e2; }

    /* LAYOUT */
    .wrapper { display: flex; min-height: calc(100vh - var(--header-total-height)); min-height: calc(100dvh - var(--header-total-height)); }

    .main {
        flex: 1;
        display: flex; flex-direction: column; min-width: 0;
    }

    .sidebar-overlay {
        display: none; position: fixed; inset: 0;
        background: rgba(0,0,0,0.5); z-index: 1040;
        -webkit-backdrop-filter: blur(2px); backdrop-filter: blur(2px);
        opacity: 0; transition: opacity 0.3s ease;
    }
    .sidebar-overlay.active { display: block; opacity: 1; }

    /* PAGE CONTENT */
    .dashboard-shell { padding: 16px 20px 30px; flex: 1; display: flex; flex-direction: column; }

    .page-header-card {
        background: #fff1f2;
        border: 1px solid #fecdd3;
        border-radius: 18px; padding: 20px 24px; margin-bottom: 20px;
        display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;
        box-shadow: var(--shadow-soft);
    }
    .page-header-card h2 {
        font-family: 'Space Grotesk', sans-serif; font-size: 20px; font-weight: 800; color: #9f1239; margin: 0;
        display: flex; align-items: center; gap: 10px;
    }
    .page-header-card h2 i { color: #e11d48; background: rgba(225, 29, 72, 0.1); padding: 8px; border-radius: 10px; font-size: 16px; flex-shrink: 0; }
    .page-header-card p { font-size: 12px; color: #881337; margin: 4px 0 0; font-weight: 500; }

    .btn-rt-primary {
        background: var(--red-accent); color: #fff; border: 1px solid var(--red-accent);
        padding: 8px 16px; border-radius: 10px; font-size: 12px; font-weight: 700; text-decoration: none;
        transition: var(--transition); display: inline-flex; align-items: center; gap: 8px; cursor: pointer;
    }
    .btn-rt-primary:hover { background: #b91c1c; color: #fff; border-color: #b91c1c; }

    /* STATS GRID */
    .stats-grid {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 20px;
    }

    .stat-card-rt {
        border-radius: 16px; padding: 20px; position: relative; overflow: hidden;
        box-shadow: var(--shadow-soft);
        display: flex; flex-direction: column; justify-content: space-between; min-height: 115px;
        border: 1px solid rgba(255, 255, 255, 0.15);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .stat-card-rt:hover { transform: translateY(-3px); box-shadow: var(--shadow-lift); }
    .stat-card-rt h3 { font-family: 'Space Grotesk', sans-serif; font-size: 26px; font-weight: 800; color: #fff; margin: 0 0 2px 0; }
    .stat-card-rt p { font-size: 11px; font-weight: 700; color: rgba(255, 255, 255, 0.9); text-transform: uppercase; letter-spacing: 0.5px; margin: 0; }
    .stat-card-rt .card-icon-float {
        position: absolute; right: 15px; top: 50%; transform: translateY(-50%);
        width: 45px; height: 45px; border-radius: 12px; background: rgba(255, 255, 255, 0.2);
        display: flex; align-items: center; justify-content: center; font-size: 20px; color: #fff;
    }

    .stat-card-rt.card-red { background: linear-gradient(135deg, #be123c, #e11d48); }
    .stat-card-rt.card-emerald { background: linear-gradient(135deg, #047857, #059669); }
    .stat-card-rt.card-rose { background: linear-gradient(135deg, #9f1239, #be123c); }
    .stat-card-rt.card-amber { background: linear-gradient(135deg, #b45309, #d97706); }

    /* TABLE */
    .table-card-rt {
        background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 18px; overflow: hidden;
        box-shadow: var(--shadow-soft);
    }
    .table-header-rt {
        padding: 16px 20px; border-bottom: 1px solid var(--border-color);
        display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
    }
    .table-header-rt h3 {
        font-family: 'Space Grotesk', sans-serif; font-size: 15px; font-weight: 800; color: var(--text-main); margin: 0;
        display: flex; align-items: center; gap: 8px;
    }
    .table-header-rt h3 i { color: var(--red-accent); }

    .form-control-rt {
        padding: 8px 14px; border-radius: 10px; border: 1px solid var(--border-color);
        background: var(--bg-card-alt); color: var(--text-main); font-size: 13px; outline: none; transition: var(--transition);
    }
    .form-control-rt:focus { border-color: var(--red-accent); background: #fff; box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1); }

    .table-responsive { -webkit-overflow-scrolling: touch; }

    table.rt-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
    table.rt-table th {
        background: #f8fafc; color: var(--text-muted); font-size: 10.5px;
        font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
        padding: 12px 16px; border-bottom: 1px solid var(--border-color); text-align: left;
    }
    table.rt-table td {
        padding: 12px 16px; border-bottom: 1px solid var(--border-color); vertical-align: middle; color: var(--text-main);
    }
    table.rt-table tr:hover { background: #f8fafc; }

    .badge-rt {
        display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px;
        border-radius: 999px; font-size: 10px; font-weight: 700; text-transform: uppercase;
    }
    .badge-rt.success { background: rgba(5, 150, 105, 0.1); color: #059669; border: 1px solid rgba(5, 150, 105, 0.2); }
    .badge-rt.danger { background: rgba(220, 38, 38, 0.1); color: #dc2626; border: 1px solid rgba(220, 38, 38, 0.2); }
    .badge-rt.dark { background: rgba(100, 116, 139, 0.1); color: #475569; border: 1px solid rgba(100, 116, 139, 0.2); }
    .badge-rt.admin-red { background: rgba(220, 38, 38, 0.1); color: #dc2626; border: 1px solid rgba(220, 38, 38, 0.2); }
    .badge-rt.owner-blue { background: rgba(37, 99, 235, 0.1); color: #2563eb; border: 1px solid rgba(37, 99, 235, 0.2); }
    .badge-rt.inspector-orange { background: rgba(234, 88, 12, 0.1); color: #ea580c; border: 1px solid rgba(234, 88, 12, 0.2); }
    .badge-rt.staff-green { background: rgba(22, 163, 74, 0.1); color: #16a34a; border: 1px solid rgba(22, 163, 74, 0.2); }

    .action-btns { display: flex; gap: 4px; align-items: center; }
    .btn-icon-rt {
        width: 30px; height: 30px; border-radius: 8px; border: 1px solid var(--border-color);
        background: var(--bg-card-alt); color: var(--text-sub); display: inline-flex;
        align-items: center; justify-content: center; text-decoration: none; font-size: 12px;
        transition: var(--transition); cursor: pointer;
    }
    .btn-icon-rt:hover { background: var(--red-accent); color: #fff; border-color: var(--red-accent); }
    .btn-icon-rt.danger { color: #dc2626; border-color: rgba(220, 38, 38, 0.3); background: #fef2f2; }
    .btn-icon-rt.danger:hover { background: #dc2626; color: #fff; border-color: #dc2626; }

    .btn-delete-all {
        background: #fef2f2;
        border: 1px solid rgba(220, 38, 38, 0.3);
        color: #dc2626;
        padding: 7px 14px;
        border-radius: 10px;
        font-size: 11.5px; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.5px;
        cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
        transition: var(--transition);
    }
    .btn-delete-all:hover {
        background: #dc2626; color: #fff; border-color: #dc2626;
        box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
    }

    .footer {
        margin-top: auto; padding-top: 14px; border-top: 1px solid var(--border-color);
        display: flex; justify-content: space-between; font-size: 11.5px; color: var(--text-muted);
    }

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
            position: fixed;
            top: 0;
            left: -100%;
            width: 85vw;
            max-width: 320px;
            height: 100vh;
            height: 100dvh;
            flex-direction: column;
            align-items: stretch;
            justify-content: flex-start;
            padding: 0;
            z-index: 1090;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            background: #ffffff;
            box-shadow: 6px 0 25px rgba(0,0,0,0.18);
            flex: none;
        }
        .main-nav-bar.mobile-open { left: 0 !important; }

        .mobile-drawer-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 18px;
            padding-top: calc(16px + var(--safe-top));
            background: linear-gradient(90deg, #991b1b, #dc2626 55%, #991b1b);
            color: #fff;
            flex-shrink: 0;
            border-bottom: 2px solid #7f1d1d;
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
            flex-direction: column;
            width: 100%;
            justify-content: flex-start;
            gap: 4px;
            padding: 14px;
            padding-bottom: calc(20px + var(--safe-bottom));
            height: auto;
        }
        .nav-item { width: 100%; height: auto; display: block; }
        .nav-link {
            padding: 14px 16px;
            border-radius: 10px;
            justify-content: flex-start;
            font-size: 14.5px;
            min-height: 48px;
            width: 100%;
            height: auto;
        }
        .nav-link .chevron { margin-left: auto; font-size: 11px; }
        .nav-link .nav-ico { font-size: 15px; width: 18px; }

        .nav-dropdown,
        .nav-item.open .nav-dropdown,
        .nav-item.align-right .nav-dropdown {
            position: static;
            left: auto; right: auto;
            transform: none;
            opacity: 1; visibility: visible;
            box-shadow: none;
            border: none;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease, padding 0.2s ease;
            padding: 0 0 0 16px;
            background: #f8fafc;
            margin-top: 4px;
            border-radius: 10px;
            min-width: 0;
        }
        .nav-item.open .nav-dropdown { max-height: 600px; padding: 8px 0 8px 16px; }
        .nav-dropdown a { padding: 12px 14px; min-height: 44px; font-size: 13.5px; }

        .rt-topbar-center {
            order: 5;
            flex-basis: 100%;
            max-width: 100%;
            margin-top: 4px;
            margin-bottom: 4px;
            padding: 0;
        }
    }

    @media (max-width: 992px) {
        .rt-topbar { flex-wrap: wrap; height: auto; padding-bottom: 6px; }
        .rt-topbar-brand { border-right: none; }
        .rt-topbar-center {
            order: 5;
            flex-basis: 100%;
            margin-top: 4px;
            margin-bottom: 4px;
        }
        body { padding-top: calc(var(--topofficial-height) + var(--navbar-height) + 50px); }
    }

    @media (max-width: 768px) {
        .rt-topbar-right { gap: 8px; padding-right: calc(12px + var(--safe-right)); }
        .rt-topbar-brand-text { display: none; }
        .dashboard-shell { padding: 12px; }
        .page-header-card { padding: 16px; flex-direction: column; align-items: flex-start; }
        .page-header-card > div:last-child { width: 100%; display: flex; gap: 8px; flex-wrap: wrap; }
        .page-header-card .btn-rt-primary { flex: 1; justify-content: center; }
        .footer { flex-direction: column; align-items: flex-start; gap: 6px; }

        .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
        .stat-card-rt { padding: 14px; min-height: 95px; border-radius: 12px; }
        .stat-card-rt h3 { font-size: 22px; }
        .stat-card-rt .card-icon-float { width: 36px; height: 36px; font-size: 16px; right: 10px; }

        .table-header-rt { padding: 12px 14px; flex-direction: column; align-items: stretch; }
        .table-header-rt h3 { font-size: 14px; }
        .table-header-rt > div { width: 100%; }
        .form-control-rt { width: 100%; max-width: 100% !important; }
        .btn-delete-all { width: 100%; justify-content: center; }

        /* Table → card view */
        .table-responsive { overflow: visible !important; }

        table.rt-table,
        table.rt-table tbody { display: block; width: 100%; }
        table.rt-table thead { display: none; }
        table.rt-table tbody { padding: 8px; }

        table.rt-table tbody tr {
            display: block; width: 100%; margin: 0 0 10px; padding: 5px 0;
            background: #f8fafc;
            border: 1px solid var(--border-color); border-radius: 12px; overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,.05);
        }
        table.rt-table tbody tr:last-child { margin-bottom: 0; }
        table.rt-table tbody tr:hover { background: #f1f5f9; }

        table.rt-table tbody td {
            display: grid; grid-template-columns: minmax(86px, 34%) minmax(0, 66%);
            align-items: center; gap: 8px; width: 100%; min-width: 0; padding: 9px 11px;
            border-bottom: 1px solid var(--border-color);
            font-size: 11.5px; line-height: 1.4; overflow-wrap: anywhere; word-break: break-word;
        }
        table.rt-table tbody td:last-child { border-bottom: 0; }
        table.rt-table tbody td::before {
            content: attr(data-label); color: var(--text-muted); font-size: 9px;
            font-weight: 800; text-transform: uppercase; letter-spacing: .45px; min-width: 0;
        }
        table.rt-table tbody td > * { min-width: 0; max-width: 100%; }
        table.rt-table .badge-rt {
            justify-self: start; width: fit-content; max-width: 100%; font-size: 8.5px; padding: 4px 7px;
        }

        table.rt-table tbody td:last-child {
            display: block; padding: 10px 11px 11px; background: #ffffff;
        }
        table.rt-table tbody td:last-child::before { display: block; margin-bottom: 7px; }

        .action-btns {
            display: grid; grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 6px; width: 100%;
        }
        .btn-icon-rt {
            width: 100%; min-height: 34px; height: 34px; border-radius: 8px; font-size: 12px;
        }

        #notifDropdown {
            position: fixed !important; top: calc(var(--header-total-height) + 5px) !important;
            right: max(8px, var(--safe-right)) !important; left: max(8px, var(--safe-left)) !important;
            width: auto !important; max-width: none !important; max-height: min(70dvh, 430px); overflow-y: auto;
        }
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

        .page-header-card h2 { font-size: 17px; }

        .stats-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
        .stat-card-rt { padding: 12px; min-height: 85px; }
        .stat-card-rt h3 { font-size: 20px; }
        .stat-card-rt p { font-size: 9.5px; }
        .stat-card-rt .card-icon-float { width: 32px; height: 32px; font-size: 14px; right: 8px; }
    }

    @media (max-width: 480px) {
        .stats-grid { grid-template-columns: 1fr; }
        .action-btns { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }

    @media (max-width: 430px) {
        .top-official-bar { font-size: 9px; }
        .topbar-left { max-width: 58%; }
        .topbar-left strong { font-size: 9px; }
        .cert-badge { font-size: 7.5px; padding: 3px 7px; }
        .rt-topbar-right { gap: 6px; }
        .mobile-search-btn, .icon-btn { width: 32px; height: 32px; }
    }

    @media (hover: none) and (pointer: coarse) {
        .nav-link:hover, .icon-btn:hover, .rt-profile-btn:hover,
        .btn-rt-primary:hover, .btn-icon-rt:hover { transform: none; }
    }

    @supports (-webkit-touch-callout: none) {
        input, button, select, textarea { font-size: 16px !important; }
        .rt-search-container input { font-size: 14px !important; }
    }

    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after {
            scroll-behavior: auto !important; animation-duration: .01ms !important;
            animation-iteration-count: 1 !important; transition-duration: .01ms !important;
        }
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

<!-- MAIN NAVBAR -->
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
                    <a href="users.php" class="nav-link active">
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
                    <button type="button" class="nav-link" data-dropdown>
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
            <input type="text" id="openSearchModalBtn" placeholder="Search system users by name, username, or email..." readonly>
        </div>
    </div>

    <div class="rt-topbar-right">
        <button class="icon-btn mobile-search-btn" id="mobileSearchBtn" title="Search">
            <i class="fa-solid fa-magnifying-glass"></i>
        </button>

        <div style="position:relative;">
            <button class="icon-btn" id="notifBtn" title="Notifications">
                <i class="fa-regular fa-bell"></i>
                <?php if ($unreadNotifCount > 0 || $pendingRequestsCount > 0): ?>
                    <span class="notif-badge-dot" id="notifBadgeDot"></span>
                <?php endif; ?>
            </button>

            <div id="notifDropdown" style="display:none; position:absolute; top:46px; right:0; width:300px; background:#fff; border:1px solid var(--border-color); border-radius:14px; padding:12px; box-shadow:0 10px 30px rgba(0,0,0,0.1); z-index:999;">
                <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); padding-bottom:8px; margin-bottom:8px;">
                    <span style="font-weight:700; color:var(--text-main); font-size:12px;">System Notifications</span>
                    <span style="font-size:10px; color:var(--red-accent); cursor:pointer;" id="clearNotifBadge">Mark all read</span>
                </div>
                <div id="notifListContainer">
                    <?php if ($pendingRequestsCount > 0): ?>
                        <a href="account_requests.php" class="d-block text-decoration-none" style="padding:8px 6px; border-bottom:1px solid rgba(0,0,0,0.05); border-radius:6px; background: #fef3c7; transition:background 0.2s;">
                            <div style="font-size:11.5px; font-weight:700; color:#b45309;"><i class="fa-solid fa-user-clock me-1"></i> Pending Requests</div>
                            <div style="font-size:10.5px; color:var(--text-sub); margin-top:2px;">You have <?php echo $pendingRequestsCount; ?> pending account request(s) to review.</div>
                        </a>
                    <?php endif; ?>

                    <?php if (!empty($notifications)): ?>
                        <?php foreach($notifications as $n): ?>
                            <a href="<?php echo htmlspecialchars($n['target_url'] ?: 'javascript:void(0)'); ?>" class="d-block text-decoration-none" style="padding:8px 6px; border-bottom:1px solid rgba(0,0,0,0.05); border-radius:6px; transition:background 0.2s;" onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='transparent'">
                                <div style="font-size:11.5px; font-weight:700; color:var(--text-main);"><?php echo htmlspecialchars($n['title']); ?></div>
                                <div style="font-size:10.5px; color:var(--text-muted); margin-top:2px;"><?php echo htmlspecialchars($n['message']); ?></div>
                                <div style="font-size:9px; color:var(--red-accent); margin-top:4px;"><?php echo date('M d, h:i A', strtotime($n['created_at'])); ?></div>
                            </a>
                        <?php endforeach; ?>
                    <?php elseif ($pendingRequestsCount === 0): ?>
                        <div style="font-size:11px; color:var(--text-muted); text-align:center; padding:14px 0;">No new notifications</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="rt-profile-wrapper">
            <div class="rt-profile-btn" id="profileToggle">
                <div class="avatar"><?php echo $userInitial; ?></div>
                <div class="info d-none d-sm-flex">
                    <span class="name"><?php echo htmlspecialchars($userName); ?></span>
                    <span class="role"><?php echo htmlspecialchars($userRole); ?></span>
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

<!-- MAIN CONTENT -->
<div class="wrapper">
    <div class="main">
        <div class="dashboard-shell">

            <div class="page-header-card">
                <div>
                    <h2><i class="fa-solid fa-users"></i> User Management</h2>
                    <p>Manage Administrator, Owner, Inspector, and Staff Accounts</p>
                </div>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <?php if ($pendingRequestsCount > 0): ?>
                        <a href="account_requests.php" class="btn-rt-primary" style="background: var(--gold-accent); border-color: #f59e0b;">
                            <i class="fa-solid fa-user-clock fa-bounce"></i> Review Requests (<?php echo $pendingRequestsCount; ?>)
                        </a>
                    <?php endif; ?>
                    <a href="add_user.php" class="btn-rt-primary">
                        <i class="fa-solid fa-user-plus"></i> Add New User
                    </a>
                </div>
            </div>

            <?php if (!empty($deleteMessage)): ?>
                <div class="alert alert-<?php echo $deleteMsgType; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($deleteMessage); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card-rt card-amber">
                    <div>
                        <h3><?php echo $pendingRequestsCount; ?></h3>
                        <p>Pending Requests</p>
                    </div>
                    <div class="card-icon-float"><i class="fa-solid fa-user-clock"></i></div>
                </div>

                <div class="stat-card-rt card-red">
                    <div>
                        <h3><?php echo $totalUsers['total']; ?></h3>
                        <p>Total Users</p>
                    </div>
                    <div class="card-icon-float"><i class="fa-solid fa-users"></i></div>
                </div>

                <div class="stat-card-rt card-emerald">
                    <div>
                        <h3><?php echo $activeUsers['total']; ?></h3>
                        <p>Active Users</p>
                    </div>
                    <div class="card-icon-float"><i class="fa-solid fa-user-check"></i></div>
                </div>

                <div class="stat-card-rt card-rose">
                    <div>
                        <h3><?php echo $inactiveUsers['total']; ?></h3>
                        <p>Inactive Users</p>
                    </div>
                    <div class="card-icon-float"><i class="fa-solid fa-user-slash"></i></div>
                </div>

                <div class="stat-card-rt card-red" style="background: linear-gradient(135deg, #9f1239, #e11d48);">
                    <div>
                        <h3><?php echo $totalAdmin['total']; ?></h3>
                        <p>Administrators</p>
                    </div>
                    <div class="card-icon-float"><i class="fa-solid fa-user-shield"></i></div>
                </div>

                <div class="stat-card-rt" style="background: linear-gradient(135deg, #1d4ed8, #3b82f6);">
                    <div>
                        <h3><?php echo $totalOwner['total']; ?></h3>
                        <p>Owners</p>
                    </div>
                    <div class="card-icon-float"><i class="fa-solid fa-building-user"></i></div>
                </div>

                <div class="stat-card-rt" style="background: linear-gradient(135deg, #c2410c, #f97316);">
                    <div>
                        <h3><?php echo $totalInspector['total']; ?></h3>
                        <p>Inspectors</p>
                    </div>
                    <div class="card-icon-float"><i class="fa-solid fa-user-secret"></i></div>
                </div>

                <div class="stat-card-rt" style="background: linear-gradient(135deg, #15803d, #22c55e);">
                    <div>
                        <h3><?php echo $totalStaff['total']; ?></h3>
                        <p>Staff</p>
                    </div>
                    <div class="card-icon-float"><i class="fa-solid fa-user-gear"></i></div>
                </div>
            </div>

            <div class="table-card-rt">
                <div class="table-header-rt">
                    <h3><i class="fa-solid fa-table"></i> Registered Users</h3>
                    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                        <input type="text" id="searchUser" class="form-control-rt" placeholder="Search user..." style="max-width: 240px;" value="<?php echo htmlspecialchars($searchQuery); ?>">

                        <?php if (!empty($usersList)): ?>
                            <form method="POST" id="deleteAllForm" style="display:inline;">
                                <input type="hidden" name="delete_all_users" value="1">
                                <button type="button" class="btn-delete-all" onclick="confirmDeleteAll()">
                                    <i class="fa-solid fa-trash-can"></i> Delete All
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="rt-table" id="userTable">
                        <thead>
                            <tr>
                                <th style="width: 60px;">ID</th>
                                <th>Full Name</th>
                                <th>Username</th>
                                <th>Email</th>
                                <th style="width: 130px;">Role</th>
                                <th style="width: 120px;">Status</th>
                                <th style="width: 180px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!empty($usersList)) { foreach ($usersList as $row) { ?>
                            <tr>
                                <td data-label="ID"><?php echo $row['id']; ?></td>
                                <td data-label="Full Name"><strong style="color: var(--text-main);"><?php echo htmlspecialchars($row['fullname'] ?? ''); ?></strong></td>
                                <td data-label="Username"><?php echo htmlspecialchars($row['username'] ?? ''); ?></td>
                                <td data-label="Email"><?php echo htmlspecialchars($row['email'] ?? ''); ?></td>
                                <td data-label="Role">
                                <?php
                                $rRole = strtolower($row['role'] ?? '');
                                switch ($rRole) {
                                    case "admin": echo "<span class='badge-rt admin-red'><i class='fa-solid fa-user-shield'></i> Admin</span>"; break;
                                    case "owner": echo "<span class='badge-rt owner-blue'><i class='fa-solid fa-building-user'></i> Owner</span>"; break;
                                    case "inspector": echo "<span class='badge-rt inspector-orange'><i class='fa-solid fa-user-secret'></i> Inspector</span>"; break;
                                    case "staff": echo "<span class='badge-rt staff-green'><i class='fa-solid fa-user-gear'></i> Staff</span>"; break;
                                    default: echo htmlspecialchars(ucfirst($row['role'] ?? ''));
                                }
                                ?>
                                </td>
                                <td data-label="Status">
                                <?php if (strcasecmp($row['status'] ?? '', 'Active') === 0) { ?>
                                    <span class="badge-rt success"><i class="fa-solid fa-circle-check"></i> Active</span>
                                <?php } else { ?>
                                    <span class="badge-rt danger"><i class="fa-solid fa-circle-xmark"></i> Inactive</span>
                                <?php } ?>
                                </td>
                                <td data-label="Actions">
                                    <div class="action-btns">
                                        <a href="view_user.php?id=<?php echo $row['id']; ?>" class="btn-icon-rt" title="View"><i class="fa-solid fa-eye"></i></a>
                                        <a href="edit_user.php?id=<?php echo $row['id']; ?>" class="btn-icon-rt" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                        <a href="reset_password.php?id=<?php echo $row['id']; ?>" class="btn-icon-rt" title="Reset Password"><i class="fa-solid fa-key"></i></a>

                                        <?php if ((int)$row['id'] !== (int)$_SESSION['user_id']) { ?>
                                            <form method="POST" style="display:inline" id="deleteForm_<?php echo $row['id']; ?>">
                                                <input type="hidden" name="delete_user_id" value="<?php echo (int) $row['id']; ?>">
                                                <button type="button" class="btn-icon-rt danger" title="Delete Permanently" onclick="confirmDelete(<?php echo $row['id']; ?>)">
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>
                                            </form>
                                        <?php } else { ?>
                                            <button class="btn-icon-rt" title="You cannot delete your own account" disabled style="opacity:0.4; cursor:not-allowed;">
                                                <i class="fa-solid fa-lock"></i>
                                            </button>
                                        <?php } ?>
                                    </div>
                                </td>
                            </tr>
                        <?php } } else { ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-5">
                                    <i class="fa-solid fa-users-slash fa-2x mb-2"></i><br>
                                    No registered users found.
                                </td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <footer class="footer">
                <span>&copy; <?php echo date('Y'); ?> <strong style="color:var(--red-accent);">RENTRACK LGU</strong> &middot; Admin Portal</span>
                <span>User Access &amp; System Operations</span>
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

    /* ============ NOTIFICATION BELL ============ */
    const notifBtn = document.getElementById('notifBtn');
    const notifDropdown = document.getElementById('notifDropdown');
    const notifBadgeDot = document.getElementById('notifBadgeDot');

    function clearNotificationDot() {
        if (notifBadgeDot) {
            notifBadgeDot.style.opacity = '0';
            setTimeout(() => { notifBadgeDot.style.display = 'none'; }, 300);
        }
        fetch('users.php?action=mark_notif_read');
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

    /* ============ PROFILE DROPDOWN ============ */
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

    /* ============ SEARCH POPUP MODAL ============ */
    const openSearchModalBtn = document.getElementById('openSearchModalBtn');
    document.getElementById('mobileSearchBtn')?.addEventListener('click', () => openSearchModalBtn?.click());

    if (openSearchModalBtn) {
        openSearchModalBtn.addEventListener('click', () => {
            Swal.fire({
                html: `
                    <div style="text-align: left;">
                        <div style="position: relative; margin-bottom: 15px;">
                            <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #64748b; font-size: 15px;"></i>
                            <input type="text" id="swalSearchInput" placeholder="Search system users by name, username, or email..." autocomplete="off" style="width: 100%; padding: 12px 16px 12px 42px; background: #f8fafc; color: #0f172a; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 13px; outline: none;">
                        </div>
                        <div id="swalSearchResults" style="min-height: 120px; max-height: 280px; overflow-y: auto; text-align: center; color: #64748b; display: flex; flex-direction: column; justify-content: center; align-items: center;">
                            <i class="fa-solid fa-keyboard" style="font-size: 28px; margin-bottom: 8px; color: #cbd5e1;"></i>
                            <span style="font-size: 12px;">Type at least 2 characters to search users...</span>
                        </div>
                    </div>
                `,
                footer: `
                    <div style="display: flex; justify-content: space-between; width: 100%; font-size: 11px; color: #64748b; padding: 0 5px;">
                        <span>Quick search across all system users</span>
                        <span>Press <kbd style="background:#e2e8f0; color:#0f172a; padding:2px 5px; border-radius:4px; border:1px solid #cbd5e1;">ESC</kbd> to close</span>
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
                            resultsEl.style.alignItems = 'center';
                            resultsEl.innerHTML = `
                                <i class="fa-solid fa-keyboard" style="font-size: 28px; margin-bottom: 8px; color: #cbd5e1;"></i>
                                <span style="font-size: 12px;">Type at least 2 characters to search users...</span>
                            `;
                            return;
                        }

                        resultsEl.style.justifyContent = 'flex-start';
                        resultsEl.style.alignItems = 'stretch';
                        resultsEl.innerHTML = `<div style="text-align: center; padding: 20px; font-size: 12px; color: #64748b;"><i class="fa-solid fa-spinner fa-spin me-2"></i> Searching users...</div>`;

                        debounceTimer = setTimeout(() => {
                            fetch(`users.php?action=global_search&q=${encodeURIComponent(query)}`)
                                .then(res => res.json())
                                .then(data => {
                                    resultsEl.innerHTML = '';
                                    if (data.length === 0) {
                                        resultsEl.style.justifyContent = 'center';
                                        resultsEl.style.alignItems = 'center';
                                        resultsEl.innerHTML = '<div style="padding:20px; font-size:12px; color:#64748b; text-align:center;">No matching users found</div>';
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

                    inputEl.addEventListener('keydown', (e) => {
                        if (e.key === 'Enter') {
                            const query = inputEl.value.trim();
                            if (query.length >= 2) {
                                window.location.href = `users.php?search=${encodeURIComponent(query)}`;
                            }
                        }
                    });
                }
            });
        });
    }

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

    /* ============ DELETE USER (PERMANENT) ============ */
    function confirmDelete(userId) {
        Swal.fire({
            title: '⚠️ Permanently Delete User?',
            html: `
                <div style="text-align:center; font-size:13px; color:#334155;">
                    This will <strong style="color:#dc2626;">PERMANENTLY REMOVE</strong> this user from the database.<br>
                    <span style="font-size:11px; color:#64748b;">This action CANNOT be undone!</span>
                </div>
            `,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            confirmButtonText: '<i class="fa-solid fa-trash"></i> Yes, Delete Permanently',
            cancelButtonText: 'Cancel',
            background: '#ffffff',
            color: '#0f172a'
        }).then((res) => {
            if (res.isConfirmed) {
                document.getElementById('deleteForm_' + userId).submit();
            }
        });
    }

    /* ============ DELETE ALL USERS ============ */
    function confirmDeleteAll() {
        Swal.fire({
            title: '🚨 DELETE ALL USERS?',
            html: `
                <div style="text-align:center; font-size:13px; color:#334155; line-height:1.6;">
                    This will <strong style="color:#dc2626;">PERMANENTLY DELETE ALL USERS</strong> from the database<br>
                    <span style="font-size:11px; color:#d97706;">(except your own admin account).</span><br><br>
                    <span style="font-size:11px; color:#64748b;">⚠️ This action CANNOT be undone!</span>
                </div>
            `,
            icon: 'error',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            confirmButtonText: '<i class="fa-solid fa-trash-can"></i> Yes, Delete ALL Users',
            cancelButtonText: 'Cancel',
            background: '#ffffff',
            color: '#0f172a'
        }).then((res) => {
            if (res.isConfirmed) {
                document.getElementById('deleteAllForm').submit();
            }
        });
    }

    /* ============ TABLE SEARCH FILTER ============ */
    const searchUser = document.getElementById("searchUser");
    if (searchUser) {
        function triggerTableSearch() {
            let value = searchUser.value.toLowerCase();
            let rows = document.querySelectorAll("#userTable tbody tr");
            rows.forEach(function (row) {
                row.style.display = row.innerText.toLowerCase().includes(value) ? "" : "none";
            });
        }
        searchUser.addEventListener("keyup", triggerTableSearch);
        if (searchUser.value.trim() !== '') {
            triggerTableSearch();
        }
    }
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>