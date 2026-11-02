<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

require_once("../config/db.php");

$userId = (int) $_SESSION['user_id'];
$userName = $_SESSION['fullname'] ?? 'User';
$userRole = $_SESSION['role'] ?? 'User';
$userInitial = strtoupper(substr(trim($userName), 0, 1));

// Determine which dashboard to go back to
$dashboardLink = '../owner/dashboard.php';
if ($userRole === 'lgu_staff') {
    $dashboardLink = '../staff/dashboard.php';
} elseif ($userRole === 'admin') {
    $dashboardLink = '../admin/dashboard.php';
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function timeAgo($datetime) {
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;
    
    if ($diff < 60) {
        return $diff . ' sec ago';
    } elseif ($diff < 3600) {
        return floor($diff / 60) . ' min ago';
    } elseif ($diff < 86400) {
        return floor($diff / 3600) . ' hr ago';
    } elseif ($diff < 604800) {
        return floor($diff / 86400) . ' day ago';
    } elseif ($diff < 2592000) {
        return floor($diff / 604800) . ' week ago';
    } else {
        return date('M d, Y', $timestamp);
    }
}

// ============================================================
// MARK ALL AS READ
// ============================================================

if (isset($_POST['action'])) {
    verify_csrf_token();
}

if (isset($_POST['action']) && $_POST['action'] === 'mark_all_read') {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = :uid AND is_read = FALSE");
    $stmt->execute([':uid' => $userId]);
    header("Location: notifications.php");
    exit();
}

// ============================================================
// MARK SINGLE AS READ
// ============================================================

if (isset($_POST['action']) && $_POST['action'] === 'mark_read' && isset($_POST['notification_id'])) {
    $notifId = (int) $_POST['notification_id'];
    $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE id = :id AND user_id = :uid");
    $stmt->execute([':id' => $notifId, ':uid' => $userId]);
    header("Location: notifications.php");
    exit();
}

// ============================================================
// DELETE NOTIFICATION
// ============================================================

if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['notification_id'])) {
    $notifId = (int) $_POST['notification_id'];
    $stmt = $conn->prepare("DELETE FROM notifications WHERE id = :id AND user_id = :uid");
    $stmt->execute([':id' => $notifId, ':uid' => $userId]);
    header("Location: notifications.php");
    exit();
}

// ============================================================
// GET NOTIFICATIONS
// ============================================================

$filter = isset($_GET['filter']) ? trim($_GET['filter']) : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$where = "user_id = :uid";
$params = [':uid' => $userId];

if ($filter === 'unread') {
    $where .= " AND is_read = FALSE";
} elseif ($filter === 'read') {
    $where .= " AND is_read = TRUE";
}

if (!empty($search)) {
    $where .= " AND (title ILIKE :s1 OR message ILIKE :s2)";
    $like = "%$search%";
    $params[':s1'] = $like;
    $params[':s2'] = $like;
}

$notifications = [];
$totalNotifications = 0;
$unreadCount = 0;
$perPage = 15;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $perPage;

try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE $where");
    $stmt->execute($params);
    $totalNotifications = (int) $stmt->fetchColumn();

    $stmt = $conn->prepare("SELECT * FROM notifications WHERE $where ORDER BY created_at DESC LIMIT :lim OFFSET :off");
    foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = FALSE");
    $stmt->execute([':uid' => $userId]);
    $unreadCount = (int) $stmt->fetchColumn();
} catch (PDOException $e) {
    error_log('admin/notifications: ' . $e->getMessage());
}

$totalPages = max(1, (int) ceil($totalNotifications / $perPage));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications | RENTRACK LGU</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* ==========================================================
           DARK THEME - MATCHING DASHBOARD
           ========================================================== */

        :root {
            --bg-dark: #09090b;
            --bg-card: rgba(255, 255, 255, 0.04);
            --bg-card-hover: rgba(255, 255, 255, 0.08);
            --border-subtle: rgba(255, 255, 255, 0.06);
            --border-light: rgba(255, 255, 255, 0.12);
            --text-main: #f8fafc;
            --text-secondary: #9aa3b2;
            --text-muted: #94a3b8;
            --primary-glow: #6366f1;
            --purple: #8b5cf6;
            --purple-glow: rgba(139, 92, 246, 0.25);
            --cyan: #22d3ee;
            --green: #4ade80;
            --orange: #fb923c;
            --red: #ef4444;
            --rt-radius: 16px;
            --rt-radius-sm: 10px;
            --rt-transition: 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --rt-sidebar-width: 260px;
            --topbar-bg: #0f0f12;
            --topbar-height: 44px;
            --navbar-height: 76px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: var(--bg-dark);
            color: var(--text-main);
            min-height: 100vh;
            overflow-x: hidden;
            position: relative;
            background-image: 
                radial-gradient(ellipse at 15% 20%, rgba(99, 102, 241, 0.06) 0%, transparent 50%),
                radial-gradient(ellipse at 85% 80%, rgba(236, 72, 153, 0.04) 0%, transparent 50%);
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            z-index: -1;
            background-image:
                linear-gradient(rgba(255,255,255,0.02) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.02) 1px, transparent 1px);
            background-size: 50px 50px;
            -webkit-mask-image: radial-gradient(ellipse 80% 60% at 50% 50%, #000 30%, transparent 80%);
            mask-image: radial-gradient(ellipse 80% 60% at 50% 50%, #000 30%, transparent 80%);
        }

        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: rgba(255,255,255,0.02); }
        ::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.15); border-radius: 20px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.25); }

        /* ===== TOP OFFICIAL BAR ===== */
        .top-official-bar {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            background: var(--topbar-bg);
            border-bottom: 1px solid rgba(255,255,255,0.06);
            padding: 8px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            font-size: 11px;
            font-weight: 500;
            z-index: 1001;
            height: var(--topbar-height);
            box-sizing: border-box;
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #cbd5e1;
        }
        .topbar-left i { color: #4ade80; font-size: 14px; }
        .topbar-left .divider { width: 1px; height: 14px; background: rgba(255,255,255,0.1); }
        .topbar-left .sub-text { color: #94a3b8; }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .cert-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 14px;
            background: rgba(74, 222, 128, 0.1);
            border: 1px solid rgba(74, 222, 128, 0.3);
            border-radius: 999px;
            color: #4ade80;
            font-weight: 600;
            font-size: 10px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        .cert-badge i { font-size: 12px; }
        .topbar-right .official-text { color: #94a3b8; }

        .wrapper { display: flex; min-height: 100vh; position: relative; z-index: 1; flex-direction: column; padding-top: calc(var(--topbar-height) + var(--navbar-height)); }
        .main-wrapper { display: flex; flex: 1; }

        /* ===== SIDEBAR ===== */
        .sidebar {
            width: var(--rt-sidebar-width);
            background: rgba(9, 9, 11, 0.92);
            backdrop-filter: blur(24px);
            border-right: 1px solid rgba(255,255,255,0.06);
            padding: 20px 16px;
            display: flex;
            flex-direction: column;
            position: fixed;
            top: calc(var(--topbar-height) + var(--navbar-height));
            left: 0;
            height: calc(100vh - var(--topbar-height) - var(--navbar-height));
            z-index: 999;
            transition: transform var(--rt-transition);
            overflow-y: auto;
        }
        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-track { background: transparent; }
        .sidebar::-webkit-scrollbar-thumb { background: var(--border-light); border-radius: 10px; }

        .brand {
            display: flex; align-items: center; gap: 12px;
            padding-bottom: 20px; margin-bottom: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }
        .brand .logo-icon {
            width: 40px; height: 40px;
            display: flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            border-radius: 12px; color: white; font-size: 18px;
        }
        .brand span { font-size: 18px; font-weight: 800; letter-spacing: -0.5px; }
        .brand span span { color: #6366f1; }

        .nav-section { margin-bottom: 18px; }
        .nav-label {
            font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 1.2px;
            color: #64748b; margin-bottom: 10px; padding-left: 12px;
        }

        .nav-item {
            display: flex; align-items: center; gap: 14px;
            padding: 10px 14px; border-radius: 12px;
            color: #94a3b8; text-decoration: none;
            transition: all 0.2s ease; margin-bottom: 2px; position: relative;
            font-size: 14px; font-weight: 500;
        }
        .nav-item i { width: 20px; text-align: center; font-size: 16px; }
        .nav-item:hover { background: rgba(255,255,255,0.06); color: var(--text-main); }
        .nav-item.active {
            background: rgba(99, 102, 241, 0.15);
            color: var(--text-main);
            box-shadow: inset 3px 0 0 #6366f1;
        }

        .badge-count {
            margin-left: auto;
            background: rgba(244, 114, 182, 0.2);
            color: #f472b6;
            padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 700;
        }

        .sidebar-footer { 
            margin-top: auto; 
            padding-top: 16px; 
            border-top: 1px solid rgba(255,255,255,0.06);
        }
        .sidebar-footer .nav-item {
            color: #94a3b8;
        }
        .sidebar-footer .nav-item:hover {
            color: #ef4444;
            background: rgba(239, 68, 68, 0.1);
        }
        .sidebar-footer .nav-item i {
            color: #94a3b8;
        }
        .sidebar-footer .nav-item:hover i {
            color: #ef4444;
        }

        .sidebar-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0, 0, 0, 0.6); z-index: 998;
        }
        .sidebar-overlay.active { display: block; }

        .main { flex: 1; margin-left: var(--rt-sidebar-width); padding: 0 32px 32px 32px; min-height: 100vh; position: relative; z-index: 1; }

        /* ===== NAVBAR ===== */
        .navbar {
            position: fixed;
            top: var(--topbar-height);
            left: 0;
            width: 100%;
            background: rgba(9, 9, 11, 0.85);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(255,255,255,0.06);
            padding: 0 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: var(--navbar-height);
            z-index: 1000;
            box-sizing: border-box;
        }
        .navbar .left { display: flex; align-items: center; gap: 16px; flex: 0 0 auto; }
        .navbar .left .menu-toggle {
            display: none; background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06); color: var(--text-main);
            width: 44px; height: 44px; border-radius: var(--rt-radius-sm);
            font-size: 20px; cursor: pointer; transition: all var(--rt-transition);
            align-items: center; justify-content: center;
        }
        .navbar .left .menu-toggle:hover { background: rgba(255,255,255,0.08); }
        .navbar .left h2 { font-size: 18px; font-weight: 700; color: var(--text-main); }
        .navbar .left h2 span { color: var(--text-muted); font-weight: 400; font-size: 14px; }

        .navbar .right { display: flex; align-items: center; gap: 12px; flex: 0 0 auto; }
        .navbar .right .icon-btn {
            width: 44px; height: 44px; border-radius: 12px;
            border: 1px solid rgba(255,255,255,0.06); background: rgba(255,255,255,0.04);
            color: var(--text-muted); display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: all var(--rt-transition);
        }
        .navbar .right .icon-btn:hover { background: rgba(255,255,255,0.08); color: var(--text-main); border-color: rgba(255,255,255,0.12); }

        .user-profile-wrapper { position: relative; }
        .user-profile {
            display: flex; align-items: center; gap: 12px;
            padding: 4px 16px 4px 4px; border-radius: 999px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
            color: var(--text-main); cursor: pointer; transition: all 0.2s ease;
        }
        .user-profile:hover { background: rgba(255,255,255,0.08); border-color: rgba(255,255,255,0.12); }
        .user-profile.active { background: rgba(255,255,255,0.08); border-color: #6366f1; }

        .user-profile .avatar {
            width: 36px; height: 36px; border-radius: 50%;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-weight: 700; font-size: 14px; flex-shrink: 0;
        }
        .user-profile .info { display: flex; flex-direction: column; line-height: 1.2; text-align: left; }
        .user-profile .info .name { font-size: 14px; font-weight: 600; }
        .user-profile .info .role { font-size: 10px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
        .user-profile .chevron { color: var(--text-muted); font-size: 12px; transition: transform 0.3s ease; }
        .user-profile.active .chevron { transform: rotate(180deg); }

        .profile-dropdown {
            position: absolute;
            top: calc(100% + 14px);
            right: 0;
            width: 220px;
            background: rgba(9, 9, 11, 0.96);
            backdrop-filter: blur(24px);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 16px;
            padding: 8px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.6);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px) scale(0.95);
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 60;
            overflow: hidden;
        }
        .profile-dropdown.show { opacity: 1; visibility: visible; transform: translateY(0) scale(1); }

        .dropdown-item {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 14px; border-radius: 12px;
            color: var(--text-main); text-decoration: none;
            transition: background 0.2s ease; cursor: pointer; font-size: 14px; font-weight: 500;
        }
        .dropdown-item:hover { background: rgba(255,255,255,0.06); }
        .dropdown-item i { width: 20px; text-align: center; color: var(--text-muted); font-size: 16px; }
        .dropdown-item.danger { color: #ef4444; }
        .dropdown-item.danger i { color: #ef4444; }
        .dropdown-item.danger:hover { background: rgba(239, 68, 68, 0.1); }

        .dropdown-divider { height: 1px; background: rgba(255,255,255,0.06); margin: 6px 8px; }

        /* ==========================================================
           NOTIFICATIONS PAGE SPECIFIC
           ========================================================== */

        .content { flex: 1; display: flex; flex-direction: column; }

        .page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .page-header h1 {
            font-size: 24px;
            font-weight: 800;
            margin: 0 0 4px 0;
            color: var(--text-main);
        }
        .page-header h1 i { color: #6366f1; }
        .page-header p {
            margin: 0;
            color: var(--text-secondary);
            font-size: 14px;
        }

        .notification-stats {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        .notification-stats .stat {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: var(--bg-card);
            border: 1px solid var(--border-subtle);
            border-radius: var(--rt-radius-sm);
            font-size: 13px;
        }
        .notification-stats .stat strong {
            font-size: 18px;
            color: var(--text-main);
        }
        .notification-stats .stat.unread { border-color: rgba(99, 102, 241, 0.3); }

        .filter-bar {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 24px;
            padding: 12px 16px;
            background: var(--bg-card);
            border: 1px solid var(--border-subtle);
            border-radius: var(--rt-radius-sm);
        }
        .filter-bar a {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-muted);
            text-decoration: none;
            transition: all var(--rt-transition);
        }
        .filter-bar a:hover { background: rgba(255,255,255,0.06); color: var(--text-main); }
        .filter-bar a.active {
            background: rgba(99, 102, 241, 0.15);
            color: #6366f1;
        }
        .filter-bar .search-box {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border: 1px solid var(--border-subtle);
            border-radius: var(--rt-radius-sm);
            background: rgba(255,255,255,0.02);
        }
        .filter-bar .search-box input {
            border: none;
            background: transparent;
            color: var(--text-main);
            font-size: 13px;
            font-family: 'Inter', sans-serif;
            outline: none;
            width: 150px;
        }
        .filter-bar .search-box input::placeholder { color: var(--text-muted); }
        .filter-bar .search-box button {
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
        }

        .notification-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 24px;
        }

        .notification-item {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            padding: 16px 20px;
            background: var(--bg-card);
            border: 1px solid var(--border-subtle);
            border-radius: var(--rt-radius-sm);
            transition: all var(--rt-transition);
            position: relative;
        }
        .notification-item:hover {
            border-color: var(--border-light);
        }
        .notification-item.unread {
            border-left: 3px solid #6366f1;
            background: rgba(99, 102, 241, 0.04);
        }

        .notification-item .icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 16px;
        }
        .notification-item .icon.payment { background: rgba(74, 222, 128, 0.15); color: #4ade80; }
        .notification-item .icon.rental { background: rgba(99, 102, 241, 0.15); color: #6366f1; }
        .notification-item .icon.inspection { background: rgba(251, 146, 60, 0.15); color: #fb923c; }
        .notification-item .icon.system { background: rgba(34, 211, 238, 0.15); color: #22d3ee; }
        .notification-item .icon.default { background: rgba(255,255,255,0.05); color: var(--text-muted); }

        .notification-item .content {
            flex: 1;
            padding: 0;
            margin: 0;
        }
        .notification-item .content .title {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 4px;
        }
        .notification-item .content .message {
            font-size: 13px;
            color: var(--text-secondary);
            line-height: 1.5;
        }
        .notification-item .content .time {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 4px;
            display: block;
        }

        .notification-item .actions {
            display: flex;
            gap: 6px;
            flex-shrink: 0;
        }
        .notification-item .actions button {
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 6px;
            transition: all var(--rt-transition);
            font-size: 13px;
        }
        .notification-item .actions button:hover { background: rgba(255,255,255,0.06); }
        .notification-item .actions button.mark-read:hover { color: #4ade80; }
        .notification-item .actions button.delete:hover { color: #ef4444; background: rgba(239, 68, 68, 0.1); }

        .notification-item .unread-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #6366f1;
            flex-shrink: 0;
            margin-top: 6px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }
        .empty-state i {
            font-size: 48px;
            opacity: 0.3;
            margin-bottom: 16px;
            display: block;
        }
        .empty-state h3 {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-main);
            margin: 0 0 8px 0;
        }
        .empty-state p {
            font-size: 14px;
            max-width: 400px;
            margin: 0 auto;
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 4px;
            margin-top: 8px;
        }
        .pagination a {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            height: 36px;
            padding: 0 12px;
            border-radius: var(--rt-radius-sm);
            border: 1px solid var(--border-subtle);
            color: var(--text-muted);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all var(--rt-transition);
        }
        .pagination a:hover { background: rgba(255,255,255,0.06); border-color: var(--border-light); color: var(--text-main); }
        .pagination a.active { background: #6366f1; border-color: #6366f1; color: #fff; }
        .pagination a.disabled { opacity: 0.4; cursor: not-allowed; pointer-events: none; }

        .btn-cta {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 20px; border-radius: var(--rt-radius-sm); border: none;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: #fff; font-weight: 700; font-size: 13px;
            text-decoration: none; transition: all var(--rt-transition);
            cursor: pointer;
            box-shadow: 0 8px 24px var(--purple-glow);
        }
        .btn-cta:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 32px rgba(99, 102, 241, 0.4);
            color: #fff;
        }
        .btn-cta-sm { padding: 8px 16px; font-size: 12px; }

        .btn-outline {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 20px; border-radius: var(--rt-radius-sm);
            border: 1px solid var(--border-subtle); background: var(--bg-card);
            color: var(--text-secondary); font-weight: 500; font-size: 13px;
            text-decoration: none; transition: all var(--rt-transition);
            cursor: pointer;
        }
        .btn-outline:hover {
            background: var(--bg-card-hover);
            border-color: var(--border-light);
            color: var(--text-main);
        }

        .footer {
            margin-top: auto;
            padding-top: 20px;
            border-top: 1px solid rgba(255,255,255,0.06);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            color: var(--text-muted);
            flex-wrap: wrap;
            gap: 8px;
        }
        .footer .brand { font-weight: 700; color: #6366f1; }

        @media (max-width: 992px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .sidebar-overlay.active { display: block; }
            .main { margin-left: 0; padding: 0 20px 24px 20px; }
            .navbar .left .menu-toggle { display: flex; }
            .navbar { padding: 0 16px !important; }
            .top-official-bar { padding-left: 16px !important; padding-right: 16px !important; }
            .top-official-bar .topbar-right .official-text { display: none; }
            .filter-bar .search-box input { width: 100px; }
        }

        @media (max-width: 768px) {
            .content { padding: 0; }
            .navbar .left h2 span { display: none; }
            .page-header { flex-direction: column; align-items: stretch; }
            .notification-item { flex-wrap: wrap; }
            .notification-item .actions { margin-left: auto; }
            .filter-bar { flex-wrap: wrap; }
            .filter-bar .search-box { margin-left: 0; width: 100%; }
            .filter-bar .search-box input { width: 100%; }
            .footer { flex-direction: column; text-align: center; }
            .topbar-left .sub-text { display: none; }
            .user-profile .info, .user-profile .chevron { display: none; }
            .profile-dropdown { right: -20px; width: 200px; }
        }

        @media (max-width: 480px) {
            .top-official-bar { font-size: 10px; padding: 6px 12px !important; }
            .cert-badge { font-size: 9px; padding: 2px 10px; }
            .notification-item { padding: 12px 14px; }
            .notification-item .content .title { font-size: 13px; }
        }
    </style>
</head>
<body>

<!-- ===== TOP OFFICIAL BAR ===== -->
<div class="top-official-bar">
    <div class="topbar-left">
        <i class="fa-solid fa-circle-check"></i>
        <strong>REPUBLIC OF THE PHILIPPINES</strong>
        <span class="divider"></span>
        <span class="sub-text">Local Government Unit Property & Rental Compliance Portal</span>
    </div>
    <div class="topbar-right">
        <span class="cert-badge">
            <i class="fa-solid fa-circle-check"></i> LGU Certified Portal v2.1
        </span>
        <span class="official-text">City Treasurer & Building Official Online System</span>
    </div>
</div>

<!-- ===== MOBILE OVERLAY ===== -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="wrapper">
    <div class="main-wrapper">

        <!-- ===== SIDEBAR ===== -->
        <aside class="sidebar" id="sidebar">
            <div class="brand">
                <span class="logo-icon"><i class="fa-solid fa-building-columns"></i></span>
                RENTRACK <span>LGU</span>
            </div>

            <div class="nav-section">
                <div class="nav-label">Main</div>
                <a href="<?php echo $dashboardLink; ?>" class="nav-item">
                    <i class="fa-solid fa-gauge-high"></i>
                    <span>Dashboard</span>
                </a>
                <?php if ($userRole === 'owner'): ?>
                <a href="../owner/rentals.php" class="nav-item">
                    <i class="fa-solid fa-building"></i>
                    <span>My Rentals</span>
                </a>
                <?php endif; ?>
                <?php if ($userRole === 'lgu_staff'): ?>
                <a href="../staff/rentals.php" class="nav-item">
                    <i class="fa-solid fa-folder-open"></i>
                    <span>Rental Applications</span>
                </a>
                <a href="../staff/inspections.php" class="nav-item">
                    <i class="fa-solid fa-clipboard-check"></i>
                    <span>My Inspections</span>
                </a>
                <a href="../staff/collection.php" class="nav-item">
                    <i class="fa-solid fa-cash-register"></i>
                    <span>Collections</span>
                </a>
                <?php endif; ?>
                <a href="notifications.php" class="nav-item active">
                    <i class="fa-regular fa-bell"></i>
                    <span>Notifications</span>
                    <?php if ($unreadCount > 0): ?>
                        <span class="badge-count"><?php echo $unreadCount; ?></span>
                    <?php endif; ?>
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-label">Settings</div>
                <a href="../profile.php" class="nav-item">
                    <i class="fa-solid fa-user"></i>
                    <span>My Profile</span>
                </a>
            </div>

            <div class="sidebar-footer">
                <a href="javascript:void(0)" class="nav-item" id="sidebarLogoutBtn">
                    <i class="fa-solid fa-right-from-bracket"></i>
                    <span>Logout</span>
                </a>
            </div>
        </aside>

        <!-- ===== MAIN ===== -->
        <div class="main">

            <!-- ===== NAVBAR ===== -->
            <nav class="navbar">
                <div class="left">
                    <button class="menu-toggle" id="menuToggle">
                        <i class="fa-solid fa-bars"></i>
                    </button>
                    <h2>Notifications <span>· Stay updated</span></h2>
                </div>

                <div class="right">
                    <div class="user-profile-wrapper">
                        <div class="user-profile" id="profileToggle">
                            <div class="avatar"><?php echo h($userInitial); ?></div>
                            <div class="info">
                                <span class="name"><?php echo h($userName); ?></span>
                                <span class="role"><?php echo strtoupper(h($userRole)); ?></span>
                            </div>
                            <i class="fa-solid fa-chevron-down chevron"></i>
                        </div>
                        <div class="profile-dropdown" id="profileDropdown">
                            <a href="../profile.php" class="dropdown-item">
                                <i class="fa-solid fa-user"></i> My Profile
                            </a>
                            <a href="../settings.php" class="dropdown-item">
                                <i class="fa-solid fa-gear"></i> Settings
                            </a>
                            <div class="dropdown-divider"></div>
                            <a href="javascript:void(0)" class="dropdown-item danger" id="profileLogoutBtn">
                                <i class="fa-solid fa-right-from-bracket"></i> Logout
                            </a>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- ===== CONTENT ===== -->
            <div class="content">

                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1><i class="fa-regular fa-bell" aria-hidden="true"></i> Notifications</h1>
                        <p>Stay updated with your latest activities and alerts.</p>
                    </div>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;">
                        <?php if ($unreadCount > 0): ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="mark_all_read">
                            <button type="submit" class="btn-cta btn-cta-sm">
                                <i class="fa-solid fa-check-double"></i> Mark All as Read
                            </button>
                        </form>
                        <?php endif; ?>
                        <a href="<?php echo $dashboardLink; ?>" class="btn-outline btn-cta-sm">
                            <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
                        </a>
                    </div>
                </div>

                <!-- Stats -->
                <div class="notification-stats">
                    <div class="stat">
                        <i class="fa-regular fa-bell"></i>
                        <strong><?php echo number_format($totalNotifications); ?></strong> Total
                    </div>
                    <div class="stat unread">
                        <i class="fa-solid fa-circle" style="color:#6366f1;font-size:10px;"></i>
                        <strong><?php echo number_format($unreadCount); ?></strong> Unread
                    </div>
                    <div class="stat">
                        <i class="fa-regular fa-circle-check"></i>
                        <strong><?php echo number_format($totalNotifications - $unreadCount); ?></strong> Read
                    </div>
                </div>

                <!-- Filter Bar -->
                <div class="filter-bar">
                    <a href="notifications.php?filter=all" class="<?php echo $filter === 'all' ? 'active' : ''; ?>">All</a>
                    <a href="notifications.php?filter=unread" class="<?php echo $filter === 'unread' ? 'active' : ''; ?>">Unread</a>
                    <a href="notifications.php?filter=read" class="<?php echo $filter === 'read' ? 'active' : ''; ?>">Read</a>
                    <div class="search-box">
                        <i class="fa-solid fa-search"></i>
                        <input type="text" id="searchInput" placeholder="Search notifications..." value="<?php echo h($search); ?>">
                        <button onclick="searchNotifications()"><i class="fa-solid fa-arrow-right"></i></button>
                    </div>
                </div>

                <!-- Notification List -->
                <div class="notification-list">
                    <?php if (!empty($notifications)): ?>
                        <?php foreach ($notifications as $notif): 
                            $isUnread = (int)$notif['is_read'] === 0;
                            $type = $notif['notification_type'] ?? 'default';
                            $iconClass = 'default';
                            $iconName = 'fa-bell';
                            
                            if ($type === 'payment') {
                                $iconClass = 'payment';
                                $iconName = 'fa-money-bill-wave';
                            } elseif ($type === 'rental' || $type === 'application') {
                                $iconClass = 'rental';
                                $iconName = 'fa-building';
                            } elseif ($type === 'inspection') {
                                $iconClass = 'inspection';
                                $iconName = 'fa-clipboard-check';
                            } elseif ($type === 'system') {
                                $iconClass = 'system';
                                $iconName = 'fa-gear';
                            } else {
                                $iconClass = 'default';
                                $iconName = 'fa-bell';
                            }
                        ?>
                            <div class="notification-item <?php echo $isUnread ? 'unread' : ''; ?>">
                                <?php if ($isUnread): ?>
                                    <span class="unread-dot"></span>
                                <?php endif; ?>
                                
                                <div class="icon <?php echo $iconClass; ?>">
                                    <i class="fa-regular <?php echo $iconName; ?>"></i>
                                </div>
                                
                                <div class="content">
                                    <div class="title"><?php echo h($notif['title'] ?? 'Notification'); ?></div>
                                    <div class="message"><?php echo h($notif['message'] ?? ''); ?></div>
                                    <span class="time">
                                        <i class="fa-regular fa-clock"></i> 
                                        <?php echo timeAgo($notif['created_at'] ?? date('Y-m-d H:i:s')); ?>
                                    </span>
                                </div>
                                
                                <div class="actions">
                                    <?php if ($isUnread): ?>
                                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="action" value="mark_read">
                                            <input type="hidden" name="notification_id" value="<?php echo (int)$notif['id']; ?>">
                                            <button type="submit" class="mark-read" title="Mark as read">
                                                <i class="fa-regular fa-circle-check"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="notification_id" value="<?php echo (int)$notif['id']; ?>">
                                        <button type="submit" class="delete" title="Delete" onclick="return confirmDelete()">
                                            <i class="fa-regular fa-trash-can"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fa-regular fa-bell-slash"></i>
                            <h3>No Notifications</h3>
                            <p>You're all caught up! No notifications to display.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" class="<?php echo $page <= 1 ? 'disabled' : ''; ?>">First</a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)])); ?>" class="<?php echo $page <= 1 ? 'disabled' : ''; ?>">Prev</a>
                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="<?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => min($totalPages, $page + 1)])); ?>" class="<?php echo $page >= $totalPages ? 'disabled' : ''; ?>">Next</a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $totalPages])); ?>" class="<?php echo $page >= $totalPages ? 'disabled' : ''; ?>">Last</a>
                </div>
                <?php endif; ?>

                <!-- Footer -->
                <footer class="footer">
                    <span>&copy; <?php echo date('Y'); ?> <span class="brand">RENTRACK LGU</span></span>
                    <span>Unified Rental Registration &amp; Compliance System</span>
                </footer>

            </div>
        </div>
    </div>
</div>

<!-- ===== SCRIPTS ===== -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // ===== SIDEBAR TOGGLE =====
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const toggleBtn = document.getElementById('menuToggle');

        function toggleSidebar() {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('active');
            document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
        }

        if (toggleBtn) { toggleBtn.addEventListener('click', toggleSidebar); }
        if (overlay) { overlay.addEventListener('click', toggleSidebar); }
        window.addEventListener('resize', function() {
            if (window.innerWidth > 992 && sidebar.classList.contains('open')) {
                sidebar.classList.remove('open');
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        });

        // ===== PROFILE DROPDOWN =====
        const profileToggle = document.getElementById('profileToggle');
        const profileDropdown = document.getElementById('profileDropdown');
        if (profileToggle) {
            profileToggle.addEventListener('click', function(e) {
                e.stopPropagation();
                profileToggle.classList.toggle('active');
                profileDropdown.classList.toggle('show');
            });
        }
        document.addEventListener('click', function(e) {
            if (profileDropdown && !profileDropdown.contains(e.target) && e.target !== profileToggle) {
                profileDropdown.classList.remove('show');
                profileToggle.classList.remove('active');
            }
        });

        // ===== SEARCH =====
        document.getElementById('searchInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                searchNotifications();
            }
        });
    });

    window.searchNotifications = function() {
        const search = document.getElementById('searchInput').value.trim();
        const currentUrl = new URL(window.location.href);
        if (search) {
            currentUrl.searchParams.set('search', search);
        } else {
            currentUrl.searchParams.delete('search');
        }
        currentUrl.searchParams.set('page', 1);
        window.location.href = currentUrl.toString();
    };

    window.confirmDelete = function() {
        return Swal.fire({
            title: 'Delete Notification?',
            text: 'This action cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#6b7394',
            confirmButtonText: 'Yes, Delete',
            cancelButtonText: 'Cancel',
            background: '#09090b',
            color: '#f8fafc'
        }).then((result) => {
            return result.isConfirmed;
        });
    };

    // ===== LOGOUT =====
    function confirmLogout() {
        Swal.fire({
            title: 'Logout from RENTRACK?',
            text: 'You will be logged out of the portal.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#6366f1',
            cancelButtonColor: '#6b7394',
            confirmButtonText: 'Yes, Logout',
            cancelButtonText: 'Cancel',
            reverseButtons: true,
            background: '#09090b',
            color: '#f8fafc'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = '../logout.php';
            }
        });
    }

    document.getElementById('sidebarLogoutBtn')?.addEventListener('click', function(e) {
        e.preventDefault();
        confirmLogout();
    });
    document.getElementById('profileLogoutBtn')?.addEventListener('click', function(e) {
        e.preventDefault();
        confirmLogout();
    });
</script>

</body>
</html>
