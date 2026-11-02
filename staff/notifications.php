<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();

require_once '../config/db.php';
require_once 'includes/auth.php';

// ==========================
// STAFF INFORMATION
// ==========================

$staffName = $_SESSION['fullname'] ?? "Staff";
$staffRole = $_SESSION['role'] ?? "Staff";
$staffId = $_SESSION['user_id'] ?? 0;
$staffInitial = strtoupper(substr(trim($staffName), 0, 1));

// ==========================
// NOTIFICATION COUNT
// ==========================

$notificationCount = 0;
try {
    $notifQuery = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE (user_id = :sid OR user_id = 0 OR user_id IS NULL) AND is_read = FALSE");
    $notifQuery->execute([':sid' => $staffId]);
    $notificationCount = (int) $notifQuery->fetchColumn();
} catch (PDOException $e) {
    error_log('staff/notifications count: ' . $e->getMessage());
}

function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
$csrfToken = generateCsrfToken();

// ==========================
// PROCESS ACTIONS
// ==========================

if (isset($_GET['action'])) {

    $tokenValid = isset($_GET['csrf_token']) && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $_GET['csrf_token']);

    if (!$tokenValid) {
        $_SESSION['error'] = 'Invalid or expired request. Please try again.';
        header('Location: notifications.php');
        exit();
    }

    if ($_GET['action'] === 'mark_all') {
        $mk = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE (user_id = :sid OR user_id = 0 OR user_id IS NULL) AND is_read = FALSE");
        $mk->execute([':sid' => $staffId]);
        $_SESSION['success'] = 'All notifications marked as read.';
        header('Location: notifications.php');
        exit();
    }

    if ($_GET['action'] === 'mark_read' && isset($_GET['id'])) {
        $notificationId = (int) $_GET['id'];
        $mk = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE id = :id AND (user_id = :sid OR user_id = 0 OR user_id IS NULL)");
        $mk->execute([':id' => $notificationId, ':sid' => $staffId]);
        $_SESSION['success'] = 'Notification marked as read.';
        header('Location: notifications.php');
        exit();
    }
}

// ==========================
// GET NOTIFICATIONS
// ==========================

$notifications = [];
$unreadCount = 0;
$notifDropdown = [];
$pendingRenewalsCount = 0;
try {
    $q = $conn->prepare("SELECT * FROM notifications WHERE (user_id = :sid OR user_id = 0 OR user_id IS NULL) ORDER BY created_at DESC");
    $q->execute([':sid' => $staffId]);
    $notifications = $q->fetchAll(PDO::FETCH_ASSOC);

    foreach ($notifications as $n) {
        if (!$n['is_read']) { $unreadCount++; }
    }

    // Notifications for dropdown (latest 5)
    foreach (array_slice($notifications, 0, 5) as $n) {
        $notifDropdown[] = ['id' => $n['id'], 'title' => $n['title'], 'message' => $n['message'], 'created_at' => $n['created_at']];
    }

    // Pending renewals count (sidebar badge)
    $chk = $conn->query("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'permit_renewals')");
    if ($chk && $chk->fetchColumn()) {
        $pendingRenewalsCount = (int) $conn->query("SELECT COUNT(*) FROM permit_renewals WHERE status = 'Pending'")->fetchColumn();
    }
} catch (PDOException $e) {
    error_log('staff/notifications load: ' . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications | RENTRACK LGU</title>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <!-- Google Font: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        /* ===== RESET & ROOT ===== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --bg-primary: #0a1a12;
            --bg-card: rgba(255, 255, 255, 0.04);
            --border-glass: rgba(255, 255, 255, 0.06);
            --text-primary: #f0fdf4;
            --text-secondary: rgba(255, 255, 255, 0.65);
            --text-muted: rgba(255, 255, 255, 0.35);
            
            --accent-green-1: #22c55e;
            --accent-green-2: #16a34a;
            --accent-green-light: #86efac;
            --accent-cyan: #2dd4bf;
            
            --shadow: 0 20px 60px rgba(0, 0, 0, 0.6);
            --radius: 20px;
            --radius-sm: 12px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --sidebar-width: 260px;
            --sidebar-collapsed: 72px;
        }

        /* ANIMATED LOGO STYLES */
        @keyframes borderSpin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @keyframes textShimmer {
            0% { color: #ffffff; text-shadow: 0 0 2px rgba(34,197,94,0.2); }
            50% { color: #86efac; text-shadow: 0 0 10px rgba(34,197,94,0.8); }
            100% { color: #ffffff; text-shadow: 0 0 2px rgba(34,197,94,0.2); }
        }

        .animated-logo-wrapper {
            position: relative;
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .animated-logo-ring {
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            border-radius: 50%;
            border: 2px dashed transparent;
            border-top-color: var(--accent-green-1);
            border-bottom-color: var(--accent-cyan);
            animation: borderSpin 3s linear infinite;
        }

        .animated-logo-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent-green-1), var(--accent-green-2));
            display: flex;
            align-items: center;
            justify-content: center;
            color: #0a1a12;
            font-size: 16px;
            font-weight: 800;
            z-index: 2;
            box-shadow: 0 0 12px rgba(34, 197, 94, 0.5);
        }

        .animated-logo-text {
            animation: textShimmer 3s infinite ease-in-out;
        }

        html, body {
            height: 100%;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
        }

        body {
            background-image: 
                radial-gradient(ellipse at 10% 20%, rgba(34, 197, 94, 0.08) 0%, transparent 50%),
                radial-gradient(ellipse at 90% 80%, rgba(45, 212, 191, 0.06) 0%, transparent 50%);
            display: flex;
            min-height: 100vh;
        }

        /* ===== SIDEBAR ===== */
        .sidebar {
            width: var(--sidebar-width);
            background: rgba(10, 20, 16, 0.92);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-right: 1px solid var(--border-glass);
            display: flex;
            flex-direction: column;
            padding: 0 12px;
            flex-shrink: 0;
            height: 100vh;
            position: sticky;
            top: 0;
            transition: width 0.3s ease;
            overflow: hidden;
            z-index: 100;
        }

        body.sidebar-collapsed .sidebar {
            width: var(--sidebar-collapsed);
        }

        .sidebar-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 8px 16px 8px;
            border-bottom: 1px solid var(--border-glass);
            margin-bottom: 16px;
        }

        .sidebar-logo .logo-text {
            white-space: nowrap;
            transition: opacity 0.2s ease;
        }
        .sidebar-logo .logo-text h2 {
            font-size: 16px;
            font-weight: 800;
            color: #fff;
            line-height: 1.2;
        }
        .sidebar-logo .logo-text span {
            font-size: 9px;
            color: var(--accent-green-light);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }

        body.sidebar-collapsed .sidebar-logo .logo-text {
            opacity: 0;
            width: 0;
        }
        body.sidebar-collapsed .sidebar-logo {
            justify-content: center;
            padding-top: 12px;
        }

        /* ===== SIDEBAR MENU - WITH GROUP LABELS ===== */
        .sidebar-menu {
            list-style: none;
            flex: 1;
            overflow-y: auto;
            padding: 4px 0;
        }
        .sidebar-menu::-webkit-scrollbar {
            width: 3px;
        }
        .sidebar-menu::-webkit-scrollbar-thumb {
            background: rgba(34, 197, 94, 0.2);
            border-radius: 999px;
        }

        .menu-group-label {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            color: var(--text-muted);
            padding: 12px 14px 6px 14px;
            font-weight: 700;
            opacity: 0.7;
            transition: opacity 0.2s ease;
        }

        body.sidebar-collapsed .menu-group-label {
            opacity: 0;
            height: 0;
            padding: 0;
            margin: 0;
            overflow: hidden;
        }

        .sidebar-menu li {
            margin-bottom: 2px;
        }
        .sidebar-menu li a {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 10px 14px;
            border-radius: 12px;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: var(--transition);
            white-space: nowrap;
            overflow: hidden;
        }
        .sidebar-menu li a i {
            width: 20px;
            font-size: 16px;
            text-align: center;
            flex-shrink: 0;
        }
        .sidebar-menu li a:hover {
            background: rgba(255, 255, 255, 0.06);
            color: #fff;
        }
        .sidebar-menu li.active a {
            background: rgba(34, 197, 94, 0.12);
            color: var(--accent-green-light);
            border: 1px solid rgba(34, 197, 94, 0.15);
        }
        .sidebar-menu li.active a i {
            color: var(--accent-green-1);
        }

        .sidebar-menu li a .menu-badge {
            margin-left: auto;
            background: #ef4444;
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 999px;
            flex-shrink: 0;
        }

        body.sidebar-collapsed .sidebar-menu li a span {
            opacity: 0;
            width: 0;
            overflow: hidden;
        }
        body.sidebar-collapsed .sidebar-menu li a {
            justify-content: center;
            padding: 10px;
        }
        body.sidebar-collapsed .sidebar-menu li a .menu-badge {
            display: none;
        }

        .sidebar-footer {
            padding: 12px 0 16px 0;
            border-top: 1px solid var(--border-glass);
            margin-top: 4px;
        }
        .sidebar-footer a {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 10px 14px;
            border-radius: 12px;
            color: #f87171;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: var(--transition);
            white-space: nowrap;
            overflow: hidden;
            cursor: pointer;
        }
        .sidebar-footer a:hover {
            background: rgba(248, 113, 113, 0.1);
        }
        .sidebar-footer a i {
            width: 20px;
            text-align: center;
            flex-shrink: 0;
        }

        body.sidebar-collapsed .sidebar-footer a span {
            opacity: 0;
            width: 0;
            overflow: hidden;
        }
        body.sidebar-collapsed .sidebar-footer a {
            justify-content: center;
            padding: 10px;
        }

        /* ===== MAIN CONTENT ===== */
        .main-wrapper {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            overflow: hidden;
        }

        /* ===== OFFICIAL GREEN HEADER (TOP) ===== */
        .official-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #052e16; 
            border-bottom: 2px solid var(--accent-green-1);
            padding: 6px 20px;
            gap: 20px;
        }

        .official-header-left, .official-header-right {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #4ade80; 
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .official-header-left i, .official-header-right i {
            color: var(--accent-green-1); 
            font-size: 14px;
        }

        .official-header-right {
            background: rgba(34, 197, 94, 0.15);
            border: 1px solid rgba(34, 197, 94, 0.3);
            padding: 3px 12px;
            border-radius: 20px;
        }

        /* ===== NAVBAR / HEADER ===== */
        .top-navbar {
            position: sticky;
            top: 0;
            z-index: 50;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 28px;
            background: rgba(10, 20, 16, 0.92);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border-glass);
            gap: 16px;
            flex-shrink: 0;
        }

        .navbar-left {
            display: flex;
            align-items: center;
            gap: 16px;
            flex: 0 0 auto;
        }

        .menu-toggle {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--border-glass);
            color: var(--accent-green-light);
            font-size: 16px;
            cursor: pointer;
            transition: var(--transition);
        }
        .menu-toggle:hover {
            background: rgba(34, 197, 94, 0.12);
        }

        .page-title {
            font-size: 18px;
            font-weight: 700;
            color: #fff;
        }
        .page-title small {
            font-size: 12px;
            font-weight: 400;
            color: var(--text-secondary);
            margin-left: 8px;
        }

        .search-wrapper {
            flex: 1;
            display: flex;
            justify-content: center;
            padding: 0 20px;
            max-width: 700px;
            margin: 0 auto;
        }

        .search-container {
            position: relative;
            width: 100%;
            max-width: 500px;
        }

        .search-container i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 16px;
        }

        .search-container input {
            width: 100%;
            padding: 10px 16px 10px 42px;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,0.08);
            background: rgba(255,255,255,0.04);
            color: var(--text-primary);
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            outline: none;
            transition: all 0.3s ease;
        }
        .search-container input::placeholder {
            color: var(--text-muted);
        }
        .search-container input:focus {
            border-color: var(--accent-green-1);
            background: rgba(255,255,255,0.06);
            box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.15);
        }

        .navbar-right {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 0 0 auto;
        }

        .nav-clock {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            padding: 4px 16px;
            border-radius: 10px;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(34, 197, 94, 0.08);
        }
        .nav-clock .time {
            font-family: monospace;
            font-size: 14px;
            font-weight: 700;
            color: var(--accent-green-1);
        }
        .nav-clock .date {
            font-size: 10px;
            color: var(--accent-green-light);
            opacity: 0.7;
        }

        .nav-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--border-glass);
            color: var(--text-secondary);
            font-size: 16px;
            cursor: pointer;
            transition: var(--transition);
            position: relative;
            text-decoration: none;
        }
        .nav-icon:hover {
            background: rgba(255, 255, 255, 0.08);
            color: #fff;
        }
        .nav-icon .badge {
            position: absolute;
            top: -4px;
            right: -4px;
            background: #ef4444;
            color: #fff;
            font-size: 9px;
            font-weight: 700;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #0a1a12;
        }

        .nav-icon-wrapper {
            position: relative;
        }

        /* ===== NOTIFICATION DROPDOWN ===== */
        .notification-dropdown {
            position: absolute;
            top: calc(100% + 12px);
            right: 0;
            width: 360px;
            background: rgba(10, 20, 16, 0.96);
            backdrop-filter: blur(24px);
            border: 1px solid var(--border-glass);
            border-radius: 14px;
            padding: 0;
            box-shadow: 0 20px 50px rgba(0,0,0,0.6);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px) scale(0.98);
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 55;
            overflow: hidden;
        }
        .notification-dropdown.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0) scale(1);
        }
        .notification-title {
            padding: 14px 20px;
            font-weight: 600;
            font-size: 14px;
            border-bottom: 1px solid var(--border-glass);
            color: var(--text-primary);
        }
        .notification-item {
            padding: 12px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.04);
            transition: background 0.2s ease;
            cursor: default;
        }
        .notification-item:hover { background: rgba(255,255,255,0.04); }
        .notification-item strong { display: block; font-size: 13px; color: var(--text-primary); margin-bottom: 2px; }
        .notification-item p { font-size: 12px; color: var(--text-secondary); margin: 0; line-height: 1.4; }
        .notification-item small { font-size: 10px; color: var(--text-muted); }

        /* ===== PROFILE DROPDOWN ===== */
        .profile-wrapper {
            position: relative;
        }

        .profile-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 4px 14px 4px 4px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--border-glass);
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
        }
        .profile-btn:hover {
            background: rgba(255, 255, 255, 0.08);
        }
        .profile-btn .avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent-green-1), var(--accent-green-2));
            display: flex;
            align-items: center;
            justify-content: center;
            color: #0a1a12;
            font-weight: 800;
            font-size: 13px;
        }
        .profile-btn .info {
            text-align: left;
            line-height: 1.2;
        }
        .profile-btn .info .name {
            font-size: 12px;
            font-weight: 600;
            color: #fff;
        }
        .profile-btn .info .role {
            font-size: 9px;
            color: var(--accent-green-light);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .profile-btn .chevron {
            font-size: 10px;
            color: var(--text-secondary);
            transition: transform 0.3s ease;
        }
        .profile-btn.active .chevron {
            transform: rotate(180deg);
        }

        .profile-dropdown {
            position: absolute;
            top: calc(100% + 12px);
            right: 0;
            width: 220px;
            background: rgba(10, 20, 16, 0.96);
            backdrop-filter: blur(24px);
            border: 1px solid var(--border-glass);
            border-radius: 14px;
            padding: 8px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.6);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px) scale(0.95);
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 60;
        }
        .profile-dropdown.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0) scale(1);
        }

        .profile-dropdown .dropdown-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border-radius: 10px;
            color: var(--text-secondary);
            text-decoration: none;
            transition: background 0.2s ease;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
        }
        .profile-dropdown .dropdown-item:hover {
            background: rgba(255,255,255,0.06);
            color: var(--text-primary);
        }
        .profile-dropdown .dropdown-item i {
            width: 20px;
            text-align: center;
            font-size: 15px;
        }
        .profile-dropdown .dropdown-divider {
            height: 1px;
            background: var(--border-glass);
            margin: 6px 8px;
        }
        .profile-dropdown .dropdown-item.danger {
            color: #f87171;
        }
        .profile-dropdown .dropdown-item.danger:hover {
            background: rgba(248, 113, 113, 0.1);
        }

        /* ===== RESPONSIVE FOR GREEN HEADER ===== */
        @media (max-width: 1200px) {
            .official-header {
                padding: 6px 16px;
            }
        }
        
        @media (max-width: 768px) {
            .official-header {
                flex-direction: column;
                align-items: center;
                gap: 4px;
                padding: 8px 10px;
            }
            .official-header-left, .official-header-right {
                font-size: 10px;
            }
            .top-navbar { padding: 10px 16px; }
            .nav-clock { display: none; }
            .profile-btn .info { display: none; }
            .profile-btn { padding: 4px; }
            .page-title small { display: none; }
            .search-wrapper { max-width: 200px; padding: 0 8px; }
            .search-container input { font-size: 13px; padding: 8px 12px 8px 36px; }
            .search-container i { font-size: 14px; left: 12px; }
            .notification-dropdown { width: calc(100vw - 32px); right: -10px; }
            .profile-dropdown { right: -20px; width: 200px; }
        }

        /* ===== CONTENT ===== */
        .content {
            flex: 1;
            padding: 28px 32px 20px;
            overflow-y: auto;
        }

        /* ===== BREADCRUMB ===== */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: var(--text-secondary);
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .breadcrumb a {
            color: var(--text-secondary);
            text-decoration: none;
            transition: var(--transition);
        }
        .breadcrumb a:hover {
            color: var(--accent-green-light);
        }
        .breadcrumb strong {
            color: var(--text-primary);
        }

        /* ===== PAGE HEADER ===== */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 28px;
        }
        .page-header h1 {
            font-size: 26px;
            font-weight: 700;
        }
        .page-header h1 i {
            color: var(--accent-green-1);
            margin-right: 10px;
        }
        .page-header p {
            color: var(--text-secondary);
            font-size: 14px;
            margin-top: 4px;
        }
        .page-header .date-badge {
            background: var(--bg-card);
            border: 1px solid var(--border-glass);
            border-radius: 40px;
            padding: 8px 18px;
            font-size: 13px;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .page-header .date-badge i {
            color: var(--accent-green-1);
        }

        /* ===== ALERTS ===== */
        .alert {
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
        }
        .alert.success {
            background: rgba(74, 222, 128, 0.12);
            border: 1px solid rgba(74, 222, 128, 0.2);
            color: #4ade80;
        }
        .alert.success::before {
            content: '\f00c';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
        }
        .alert.danger {
            background: rgba(248, 113, 113, 0.12);
            border: 1px solid rgba(248, 113, 113, 0.2);
            color: #f87171;
        }
        .alert.danger::before {
            content: '\f071';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
        }

        /* ===== CARD ===== */
        .card {
            background: var(--bg-card);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--border-glass);
            border-radius: var(--radius);
            box-shadow: 0 8px 28px rgba(0,0,0,0.2);
            overflow: hidden;
            transition: var(--transition);
            margin-bottom: 24px;
        }

        .card:hover {
            border-color: rgba(255,255,255,0.1);
        }

        .card-header {
            padding: 18px 24px;
            border-bottom: 1px solid var(--border-glass);
            font-size: 16px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .card-header i {
            color: var(--accent-green-1);
        }

        .card-body {
            padding: 24px;
        }

        /* ===== NOTIFICATIONS HEADER ===== */
        .notifications-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 18px;
        }
        .notifications-header h3 {
            font-size: 16px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .notifications-header h3 .unread-badge {
            background: #ef4444;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 10px;
            border-radius: 999px;
        }

        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 18px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--accent-green-1), var(--accent-green-2));
            color: #0a1a12;
            font-weight: 600;
            font-size: 13px;
            border: none;
            cursor: pointer;
            transition: var(--transition);
            font-family: inherit;
            text-decoration: none;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(34, 197, 94, 0.3);
        }

        /* ===== TABLE ===== */
        .table-responsive {
            overflow-x: auto;
            margin: 0 -4px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .table thead th {
            text-align: left;
            padding: 12px 8px 12px 0;
            color: var(--text-secondary);
            font-weight: 500;
            border-bottom: 1px solid rgba(255,255,255,0.06);
            letter-spacing: 0.3px;
            font-size: 11px;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .table tbody td {
            padding: 14px 8px 14px 0;
            border-bottom: 1px solid rgba(255,255,255,0.03);
            vertical-align: middle;
        }

        .table tbody tr {
            transition: var(--transition);
        }

        .table tbody tr:hover {
            background: rgba(255,255,255,0.03);
        }

        .table tbody tr:last-child td {
            border-bottom: none;
        }

        .table tbody tr.unread {
            border-left: 3px solid var(--accent-green-1);
        }

        .table .notification-message {
            max-width: 300px;
            color: var(--text-secondary);
            font-size: 13px;
            line-height: 1.5;
        }

        .badge {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 40px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .badge-unread {
            background: rgba(251, 191, 36, 0.18);
            color: #fbbf24;
        }
        .badge-read {
            background: rgba(255, 255, 255, 0.04);
            color: var(--text-secondary);
        }

        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--border-glass);
            color: var(--text-secondary);
            font-size: 12px;
            text-decoration: none;
            transition: var(--transition);
            cursor: pointer;
        }
        .btn-action:hover {
            background: rgba(255, 255, 255, 0.08);
            color: #fff;
        }

        /* ===== EMPTY STATE ===== */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: var(--text-secondary);
        }
        .empty-state i {
            font-size: 48px;
            display: block;
            margin-bottom: 16px;
            opacity: 0.3;
        }
        .empty-state p {
            font-size: 16px;
            font-weight: 500;
        }
        .empty-state small {
            font-size: 13px;
            color: var(--text-muted);
        }

        /* ===== FOOTER ===== */
        .footer {
            margin-top: 24px;
            padding-top: 16px;
            border-top: 1px solid var(--border-glass);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            color: var(--text-secondary);
            flex-wrap: wrap;
            gap: 8px;
            flex-shrink: 0;
        }

        .footer .brand {
            background: linear-gradient(135deg, var(--accent-green-1), var(--accent-cyan));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-weight: 700;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 992px) {
            .table .notification-message {
                max-width: 200px;
            }
        }

        @media (max-width: 768px) {
            .content {
                padding: 16px;
            }
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .page-header h1 {
                font-size: 22px;
            }
            .notifications-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .table {
                font-size: 12px;
            }
            .table .notification-message {
                max-width: 120px;
            }
            .sidebar {
                width: var(--sidebar-collapsed);
            }
            .sidebar .sidebar-logo .logo-text,
            .sidebar .sidebar-menu li a span,
            .sidebar .sidebar-footer a span {
                opacity: 0;
                width: 0;
                overflow: hidden;
            }
            .sidebar .sidebar-logo { justify-content: center; }
            .sidebar .sidebar-menu li a { justify-content: center; padding: 10px; }
            .sidebar .sidebar-footer a { justify-content: center; padding: 10px; }
            body:not(.sidebar-collapsed) .sidebar { width: var(--sidebar-collapsed); }
            .search-wrapper { display: none; }
        }

        @media (max-width: 480px) {
            .footer {
                flex-direction: column;
                text-align: center;
            }
            .table thead th {
                font-size: 9px;
            }
            .table tbody td {
                padding: 10px 4px 10px 0;
                font-size: 11px;
            }
            .badge {
                font-size: 9px;
                padding: 2px 8px;
            }
            .btn-action {
                font-size: 10px;
                padding: 4px 8px;
            }
        }

        /* ===== ANIMATIONS ===== */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(16px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .card {
            animation: fadeInUp 0.4s ease forwards;
        }

        .breadcrumb {
            animation: fadeInUp 0.3s ease forwards;
        }

        .content::-webkit-scrollbar {
            width: 6px;
        }
        .content::-webkit-scrollbar-track {
            background: transparent;
        }
        .content::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.1);
            border-radius: 999px;
        }
        .content::-webkit-scrollbar-thumb:hover {
            background: rgba(255,255,255,0.2);
        }
    </style>
</head>
<body>

    <!-- ===== HIDDEN LOGOUT FORM ===== -->
    <form id="logoutForm" action="../logout.php" method="POST" style="display:none;"></form>

    <div class="app-body" style="display:flex; width:100%; min-height:100vh;">
        <!-- ===== SIDEBAR ===== -->
        <aside class="sidebar" role="navigation" aria-label="Sidebar navigation">
            <div class="sidebar-logo">
                <div class="animated-logo-wrapper">
                    <div class="animated-logo-ring"></div>
                    <div class="animated-logo-circle">RT</div>
                </div>
                <div class="logo-text">
                    <h2 class="animated-logo-text">RENTRACK</h2>
                    <span>LGU Staff</span>
                </div>
            </div>

            <!-- ===== SIDEBAR MENU WITH GROUP LABELS ===== -->
            <ul class="sidebar-menu">
                <!-- DASHBOARD -->
                <li class="menu-group-label">Navigation</li>
                <li><a href="dashboard.php"><i class="fa-solid fa-gauge-high"></i><span>Dashboard</span></a></li>

                <!-- RENTAL MANAGEMENT -->
                <li class="menu-group-label">Rental Management</li>
                <li><a href="rentals.php"><i class="fa-solid fa-folder-open"></i><span>Rental Applications</span></a></li>
                <li><a href="add_rental.php"><i class="fa-solid fa-plus-circle"></i><span>Register Rental</span></a></li>
                <li><a href="documents.php"><i class="fa-solid fa-file-lines"></i><span>Documents</span></a></li>
                <li>
                    <a href="renewals_approval.php">
                        <i class="fa-solid fa-file-circle-exclamation"></i><span>Permit Renewals</span>
                        <?php if ($pendingRenewalsCount > 0): ?>
                            <span class="menu-badge"><?php echo $pendingRenewalsCount; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li><a href="reports.php"><i class="fa-solid fa-file-invoice"></i><span>Reports &amp; Analytics</span></a></li>

                <!-- INSPECTION MANAGEMENT -->
                <li class="menu-group-label">Inspection Management</li>
                <li><a href="inspections.php"><i class="fa-solid fa-clipboard-check"></i><span>My Inspections</span></a></li>

                <!-- FINANCIAL MANAGEMENT -->
                <li class="menu-group-label">Financial Management</li>
                <li><a href="collection.php"><i class="fa-solid fa-cash-register"></i><span>Collections</span></a></li>
                <li><a href="payments.php"><i class="fa-solid fa-money-check-dollar"></i><span>Payments</span></a></li>

                <!-- ACCOUNT & SETTINGS -->
                <li class="menu-group-label">Account &amp; Settings</li>
                <li class="active"><a href="notifications.php"><i class="fa-solid fa-bell"></i><span>Notifications</span>
                    <?php if ($notificationCount > 0): ?>
                        <span class="menu-badge"><?php echo $notificationCount; ?></span>
                    <?php endif; ?>
                </a></li>
                <li><a href="profile.php"><i class="fa-solid fa-user"></i><span>My Profile</span></a></li>
            </ul>

            <div class="sidebar-footer">
                <a href="#" onclick="confirmLogout(); return false;">
                    <i class="fa-solid fa-right-from-bracket"></i><span>Logout</span>
                </a>
            </div>
        </aside>

        <!-- ===== MAIN WRAPPER ===== -->
        <div class="main-wrapper">

            <!-- ===== OFFICIAL GREEN HEADER ===== -->
            <div class="official-header">
                <div class="official-header-left">
                    <i class="fa-solid fa-landmark"></i>
                    <span>REPUBLIC OF THE PHILIPPINES</span>
                    <span style="opacity: 0.7;">|</span>
                    <span style="font-weight: 400; opacity: 0.8;">Local Government Unit Property &amp; Rental Compliance Portal</span>
                </div>
                <div class="official-header-right">
                    <i class="fa-solid fa-certificate"></i>
                    <span>LGU CERTIFIED PORTAL V2.1</span>
                    <span style="opacity: 0.7;">|</span>
                    <span style="font-weight: 400; opacity: 0.8;">City Treasurer &amp; Building Official Online System</span>
                </div>
            </div>

            <!-- ===== NAVBAR / HEADER ===== -->
            <header class="top-navbar">
                <div class="navbar-left">
                    <button class="menu-toggle" id="menuToggle" aria-label="Toggle menu">
                        <i class="fa-solid fa-bars"></i>
                    </button>
                    <div class="page-title">
                        Notifications
                        <small>Stay Updated</small>
                    </div>
                </div>

                <!-- ===== SEARCH BAR ===== -->
                <div class="search-wrapper">
                    <div class="search-container">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="globalSearchInput" placeholder="Search notifications..." autocomplete="off">
                    </div>
                </div>

                <div class="navbar-right">
                    <div class="nav-clock">
                        <div class="time" id="clockTime">12:00 PM</div>
                        <div class="date" id="clockDate"><?php echo date("M d, Y"); ?></div>
                    </div>

                    <!-- ===== NOTIFICATION DROPDOWN ===== -->
                    <div class="nav-icon-wrapper">
                        <button class="nav-icon" id="notifBtn">
                            <i class="fa-regular fa-bell"></i>
                            <?php if ($notificationCount > 0): ?>
                                <span class="badge"><?php echo $notificationCount; ?></span>
                            <?php endif; ?>
                        </button>
                        <div id="notifDropdown" class="notification-dropdown">
                            <div class="notification-title">Recent Notifications</div>
                            <?php if (!empty($notifDropdown)): ?>
                                <?php foreach ($notifDropdown as $n): ?>
                                    <div class="notification-item">
                                        <strong><?php echo htmlspecialchars($n['title'] ?? 'Notification'); ?></strong>
                                        <p><?php echo htmlspecialchars($n['message'] ?? ''); ?></p>
                                        <small><?php echo date("M d, Y h:i A", strtotime($n['created_at'] ?? 'now')); ?></small>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="notification-item" style="text-align:center;color:var(--text-secondary);padding:20px;">
                                    <i class="fa-regular fa-bell" style="font-size:24px;display:block;margin-bottom:8px;"></i>
                                    No notifications
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- ===== PROFILE DROPDOWN ===== -->
                    <div class="profile-wrapper">
                        <div class="profile-btn" id="profileToggle">
                            <div class="avatar"><?php echo $staffInitial; ?></div>
                            <div class="info">
                                <div class="name"><?php echo htmlspecialchars($staffName); ?></div>
                                <div class="role"><?php echo htmlspecialchars($staffRole); ?></div>
                            </div>
                            <i class="fa-solid fa-chevron-down chevron"></i>
                        </div>

                        <div class="profile-dropdown" id="profileDropdown">
                            <a href="profile.php" class="dropdown-item">
                                <i class="fa-solid fa-user"></i> My Profile
                            </a>
                            <a href="settings.php" class="dropdown-item">
                                <i class="fa-solid fa-gear"></i> Settings
                            </a>
                            <div class="dropdown-divider"></div>
                            <a href="#" class="dropdown-item danger" id="profileLogoutBtn">
                                <i class="fa-solid fa-right-from-bracket"></i> Logout
                            </a>
                        </div>
                    </div>
                </div>
            </header>

            <!-- ===== CONTENT ===== -->
            <main class="content">

                <!-- ===== BREADCRUMB ===== -->
                <div class="breadcrumb">
                    <a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
                    <span><i class="fa-solid fa-angle-right"></i></span>
                    <strong>Notifications</strong>
                </div>

                <!-- ===== PAGE HEADER ===== -->
                <div class="page-header">
                    <div>
                        <h1><i class="fa-solid fa-bell"></i>Notifications</h1>
                        <p>View your recent messages and alerts.</p>
                    </div>
                    <div class="date-badge">
                        <i class="fa-regular fa-calendar"></i>
                        <?php echo date("F d, Y"); ?>
                    </div>
                </div>

                <!-- ===== NOTIFICATIONS CARD ===== -->
                <div class="card">
                    <div class="card-header">
                        <i class="fa-solid fa-bell"></i> Notification Center
                    </div>
                    <div class="card-body">
                        
                        <?php if (isset($_SESSION['success'])): ?>
                            <div class="alert success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
                        <?php endif; ?>
                        
                        <?php if (isset($_SESSION['error'])): ?>
                            <div class="alert danger"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
                        <?php endif; ?>

                        <div class="notifications-header">
                            <h3>
                                Recent Notifications
                                <?php if ($unreadCount > 0): ?>
                                    <span class="unread-badge"><?php echo $unreadCount; ?> unread</span>
                                <?php endif; ?>
                            </h3>
                            <?php if ($unreadCount > 0): ?>
                                <a href="notifications.php?action=mark_all&csrf_token=<?php echo urlencode($csrfToken); ?>" class="btn-primary">
                                    <i class="fa-solid fa-check"></i> Mark all read
                                </a>
                            <?php endif; ?>
                        </div>

                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Title</th>
                                        <th>Message</th>
                                        <th>Date</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($notifications)): ?>
                                        <?php foreach ($notifications as $note): ?>
                                            <?php 
                                                $statusClass = $note['is_read'] == '0' ? 'badge-unread' : 'badge-read';
                                                $rowClass = $note['is_read'] == '0' ? 'unread' : '';
                                                $actionLink = '';
                                                if ($note['is_read'] == '0') {
                                                    $actionLink = '<a href="notifications.php?action=mark_read&id=' . (int) $note['id'] . '&csrf_token=' . urlencode($csrfToken) . '" class="btn-action"><i class="fa-regular fa-circle-check"></i> Mark read</a>';
                                                } else {
                                                    $actionLink = '<span style="color:var(--text-muted);font-size:12px;">—</span>';
                                                }
                                            ?>
                                            <tr class="<?php echo $rowClass; ?>">
                                                <td>
                                                    <strong style="color:var(--text-primary);">
                                                        <?php echo htmlspecialchars($note['title']); ?>
                                                    </strong>
                                                </td>
                                                <td>
                                                    <div class="notification-message">
                                                        <?php echo nl2br(htmlspecialchars($note['message'])); ?>
                                                    </div>
                                                </td>
                                                <td style="color:var(--text-secondary);font-size:13px;white-space:nowrap;">
                                                    <?php echo date('M d, Y \a\t g:i A', strtotime($note['created_at'])); ?>
                                                </td>
                                                <td>
                                                    <span class="badge <?php echo $statusClass; ?>">
                                                        <?php echo $note['is_read'] == '0' ? 'Unread' : 'Read'; ?>
                                                    </span>
                                                </td>
                                                <td class="notification-actions">
                                                    <?php echo $actionLink; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="empty-state">
                                                <i class="fa-solid fa-bell-slash"></i>
                                                <p>You have no notifications at the moment.</p>
                                                <small>Check back later for updates.</small>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ===== FOOTER ===== -->
                <footer class="footer">
                    <span>&copy; <?php echo date('Y'); ?> <span class="brand">RENTRACK LGU</span></span>
                    <span>Unified Rental Registration, Tracking &amp; Compliance System</span>
                </footer>

            </main>
        </div>
    </div>

    <script>
        (function() {
            'use strict';

            // ===== SIDEBAR TOGGLE =====
            const menuToggle = document.getElementById('menuToggle');
            const menuIcon = menuToggle.querySelector('i');

            menuToggle.addEventListener('click', function() {
                const collapsed = document.body.classList.toggle('sidebar-collapsed');
                menuToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                menuIcon.classList.toggle('fa-bars', !collapsed);
                menuIcon.classList.toggle('fa-xmark', collapsed);
                localStorage.setItem('sidebarCollapsed', collapsed ? '1' : '0');
            });

            if (localStorage.getItem('sidebarCollapsed') === '1') {
                document.body.classList.add('sidebar-collapsed');
                menuToggle.setAttribute('aria-expanded', 'false');
                menuIcon.classList.remove('fa-bars');
                menuIcon.classList.add('fa-xmark');
            }

            // ===== LIVE CLOCK =====
            const timeEl = document.getElementById('clockTime');

            function pad(n) { return String(n).padStart(2, '0'); }

            function tickClock() {
                const d = new Date();
                let h = d.getHours();
                const ampm = h >= 12 ? 'PM' : 'AM';
                h = h % 12;
                if (h === 0) h = 12;
                timeEl.textContent = pad(h) + ':' + pad(d.getMinutes()) + ' ' + ampm;
            }

            tickClock();
            setInterval(tickClock, 1000);

            // ===== NOTIFICATION DROPDOWN =====
            const notifBtn = document.getElementById('notifBtn');
            const notifDropdown = document.getElementById('notifDropdown');

            if (notifBtn) {
                notifBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    notifDropdown.classList.toggle('show');
                });
            }

            document.addEventListener('click', function(e) {
                if (notifDropdown && !notifDropdown.contains(e.target) && e.target !== notifBtn) {
                    notifDropdown.classList.remove('show');
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

            // ===== GLOBAL SEARCH =====
            const searchInput = document.getElementById('globalSearchInput');
            if (searchInput) {
                searchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter' && searchInput.value.trim() !== '') {
                        window.location.href = 'notifications.php?search=' + encodeURIComponent(searchInput.value.trim());
                    }
                });
            }

        })();

        // ===== FUNCTIONAL LOGOUT WITH SWEETALERT2 =====
        function confirmLogout() {
            Swal.fire({
                title: 'Are you sure?',
                text: 'You will be logged out of your account.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#22c55e',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Logout',
                cancelButtonText: 'Cancel',
                reverseButtons: true,
                background: '#0a1a12',
                color: '#f0fdf4'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('logoutForm').submit();
                }
            });
        }

        // Logout from sidebar
        document.querySelector('.sidebar-footer a')?.addEventListener('click', function(e) {
            e.preventDefault();
            confirmLogout();
        });

        // Logout from profile dropdown
        document.getElementById('profileLogoutBtn')?.addEventListener('click', function(e) {
            e.preventDefault();
            confirmLogout();
        });
    </script>

</body>
</html>
