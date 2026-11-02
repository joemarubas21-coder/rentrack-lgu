<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();
date_default_timezone_set('Asia/Manila');

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

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$adminUserId = (int) ($_SESSION['user_id'] ?? 1);

/* =====================================================
   AJAX NOTIFICATION HANDLER
===================================================== */

if (isset($_GET['action']) && $_GET['action'] === 'mark_notif_read') {
    if (tableExists($conn, 'notifications')) {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = :user_id");
        $stmt->execute([':user_id' => $adminUserId]);
    }
    echo json_encode(['status' => 'success']);
    exit;
}

// Check User ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: users.php");
    exit();
}

$id = (int)$_GET['id'];

// Get User
$stmt = $conn->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header("Location: users.php");
    exit();
}

$error = "";

// RESET PASSWORD
if (isset($_POST['reset_password'])) {
    if (function_exists('verify_csrf_token')) {
        verify_csrf_token();
    }

    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if ($password !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } else {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmtUpdate = $conn->prepare("UPDATE users SET password = :password WHERE id = :id");
            $stmtUpdate->execute([
                ':password' => $hash,
                ':id'       => $id
            ]);

            header("Location: users.php?reset=1");
            exit();
        } catch (PDOException $e) {
            $error = "Failed to update password: " . safe_error($e);
        }
    }
}

// User Profile Data
$userName = $_SESSION['fullname'] ?? ($_SESSION['user_name'] ?? 'Admin');
$userRole = $_SESSION['role'] ?? ($_SESSION['user_role'] ?? 'Administrator');
$userInitial = strtoupper(substr($userName, 0, 1));

// Notifications Data
$notifications = [];
$unreadNotifCount = 0;

if (tableExists($conn, 'notifications')) {
    $stmtNotif = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = FALSE");
    $stmtNotif->execute([':user_id' => $adminUserId]);
    $unreadNotifCount = (int) $stmtNotif->fetchColumn();

    $hasTargetUrl = columnExists($conn, 'notifications', 'target_url');
    $selectFields = $hasTargetUrl ? "id, title, message, target_url, created_at" : "id, title, message, created_at";

    $stmtNotifList = $conn->prepare("SELECT $selectFields FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 5");
    $stmtNotifList->execute([':user_id' => $adminUserId]);
    while ($row = $stmtNotifList->fetch(PDO::FETCH_ASSOC)) {
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | RENTRACK LGU</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

    <style>
        :root {
            /* Light Theme Variables */
            --bg-body: #f8fafc;
            --bg-card: #ffffff;
            --bg-card-alt: #f1f5f9;
            --sidebar-bg: #dc2626;
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
            --navbar-height: 68px;
            --sidebar-width: 264px;
            --sidebar-collapsed-width: 76px;
            --header-total-height: calc(var(--topofficial-height) + var(--navbar-height));
            --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            background-color: var(--bg-body);
            background-image: none;
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: var(--text-main);
            min-height: 100vh;
            min-height: 100dvh;
            overflow-x: hidden;
            padding-top: var(--header-total-height);
            -webkit-font-smoothing: antialiased;
        }

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
            padding: var(--safe-top) calc(20px + var(--safe-right)) 0 calc(20px + var(--safe-left));
            display: flex; justify-content: space-between; align-items: center;
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
            border-bottom: 1px solid var(--border-color);
            padding: 0;
            display: flex; align-items: center; justify-content: space-between;
            height: var(--navbar-height);
            z-index: 1001;
        }

        .rt-topbar-left { display: flex; align-items: center; height: 100%; }

        .rt-topbar-brand { 
            display: flex; align-items: center; gap: 10px; width: var(--sidebar-width); 
            height: 100%; padding: 0 20px 0 calc(20px + var(--safe-left)); border-right: 1px solid var(--border-color);
            flex-shrink: 0; transition: var(--transition);
        }

        body.sidebar-collapsed .rt-topbar-brand {
            width: var(--sidebar-collapsed-width); padding: 0; justify-content: center;
        }

        body.sidebar-collapsed .rt-topbar-brand .rt-topbar-brand-text { display: none !important; }

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

        .sidebar-toggle-btn {
            background: var(--bg-card-alt);
            border: 1px solid var(--border-color);
            color: var(--text-main); width: 38px; height: 38px;
            border-radius: 10px; display: flex; align-items: center; justify-content: center;
            cursor: pointer; font-size: 16px; transition: var(--transition);
            margin-left: 16px;
        }
        .sidebar-toggle-btn:hover { background: var(--red-accent); color: #fff; border-color: var(--red-accent); }

        .rt-topbar-left .page-title {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 18px; font-weight: 800; color: var(--text-main); margin: 0;
            margin-left: 14px;
        }
        .rt-topbar-left .page-title span { color: var(--text-muted); font-weight: 500; font-size: 13px; }

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
            border: 1px solid var(--border-color); background: var(--bg-card-alt);
            color: var(--text-main); font-size: 13px; outline: none; transition: var(--transition);
        }
        .rt-search-container input::placeholder { color: var(--text-muted); }
        .rt-search-container input:focus {
            background: #fff; border-color: var(--red-accent);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }

        .rt-topbar-right { display: flex; align-items: center; gap: 12px; padding-right: calc(24px + var(--safe-right)); }

        .icon-btn {
            width: 38px; height: 38px; border-radius: 10px;
            border: 1px solid var(--border-color); background: var(--bg-card-alt);
            color: var(--text-main); display: flex; align-items: center; justify-content: center;
            cursor: pointer; position: relative; transition: var(--transition);
        }
        .icon-btn:hover { background: #fff; border-color: var(--red-accent); color: var(--red-accent); }

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

        /* SIDEBAR (RED THEME) */
        .wrapper { display: flex; min-height: calc(100vh - var(--header-total-height)); min-height: calc(100dvh - var(--header-total-height)); }

        .sidebar {
            width: var(--sidebar-width); flex-shrink: 0;
            background: var(--sidebar-bg);
            border-right: none;
            padding: 16px 12px 16px calc(12px + var(--safe-left)); margin: 0; position: fixed;
            top: var(--header-total-height);
            left: 0; height: calc(100vh - var(--header-total-height));
            height: calc(100dvh - var(--header-total-height));
            overflow-y: auto; overflow-x: hidden; display: flex; flex-direction: column; z-index: 1000;
            transition: width 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .main {
            flex: 1; margin-left: var(--sidebar-width);
            display: flex; flex-direction: column; min-width: 0; transition: margin-left 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .sidebar .brand {
            display: flex; align-items: center; gap: 10px; padding: 0 8px 16px 8px;
            margin-bottom: 12px; border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            color: #fff; font-weight: 800; font-family: 'Space Grotesk', sans-serif;
        }

        .animated-logo-avatar {
            position: relative; width: 38px; height: 38px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            background: #111827; padding: 3px; flex-shrink: 0;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.2);
        }
        .animated-logo-avatar::before {
            content: ''; position: absolute; inset: -2px; border-radius: 50%;
            background: conic-gradient(from 0deg, #fff 0deg, #fecdd3 120deg, transparent 210deg, #fff 360deg);
            animation: rotateGlow 3s linear infinite; z-index: 1;
        }
        .animated-logo-avatar::after {
            content: ''; position: absolute; inset: 2px; background: #111827; border-radius: 50%; z-index: 2;
        }
        .animated-logo-avatar span {
            position: relative; z-index: 3; font-weight: 800; font-size: 13px;
            color: #fff; letter-spacing: -0.5px;
        }

        @keyframes rotateGlow {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .sidebar .nav-section { display: flex; flex-direction: column; gap: 4px; }
        .sidebar .nav-label {
            font-size: 10px; font-weight: 700; color: rgba(255, 255, 255, 0.6);
            text-transform: uppercase; letter-spacing: 0.08em; padding: 10px 8px 4px 8px;
        }
        .sidebar .nav-item {
            display: flex; align-items: center; gap: 12px; padding: 10px 12px;
            border-radius: 10px; color: rgba(255, 255, 255, 0.85); text-decoration: none;
            font-size: 13px; font-weight: 600; transition: var(--transition);
        }
        .sidebar .nav-item:hover { background: rgba(0, 0, 0, 0.15); color: #fff; }
        .sidebar .nav-item.active { background: #fff; color: var(--red-accent); box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15); }
        .sidebar .nav-item i { font-size: 15px; width: 20px; text-align: center; color: rgba(255, 255, 255, 0.7); }
        .sidebar .nav-item.active i { color: var(--red-accent); }
        .sidebar-footer { margin-top: auto; padding-top: 12px; padding-bottom: var(--safe-bottom); border-top: 1px solid rgba(255, 255, 255, 0.2); }

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
            transform: translateY(-50%); background: #991b1b; color: #fff; padding: 5px 10px;
            font-size: 11px; font-weight: 700; border-radius: 6px; white-space: nowrap;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2); border: 1px solid #dc2626; z-index: 9999; pointer-events: none;
        }

        /* MAIN CONTENT AREA */
        .dashboard-shell { padding: 16px 20px 30px; flex: 1; max-width: 900px; }

        /* RESET PASSWORD PAGE (LIGHT THEME) */
        .page-header-card {
            background: #fff1f2;
            border: 1px solid #fecdd3;
            border-radius: 18px; padding: 20px 24px; margin-bottom: 20px;
            box-shadow: var(--shadow-soft);
        }
        .page-header-card h2 {
            font-family: 'Space Grotesk', sans-serif; font-size: 20px; font-weight: 800; color: #9f1239; margin: 0;
            display: flex; align-items: center; gap: 10px;
        }
        .page-header-card h2 i { color: #e11d48; background: rgba(225, 29, 72, 0.1); padding: 8px; border-radius: 10px; font-size: 16px; }
        .page-header-card p { font-size: 12px; color: #881337; margin: 4px 0 0; font-weight: 500; }

        .alert-danger-rt {
            background: #fef2f2; border: 1px solid rgba(220, 38, 38, 0.3);
            color: #dc2626; padding: 12px 16px; border-radius: 12px; margin-bottom: 20px;
            font-size: 13px; display: flex; align-items: center; gap: 8px; font-weight: 600;
            box-shadow: var(--shadow-soft);
        }

        .card-rt {
            background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 18px; overflow: hidden;
            box-shadow: var(--shadow-soft);
        }
        .card-header-rt {
            background: var(--bg-card-alt); border-bottom: 1px solid var(--border-color);
            padding: 16px 24px; font-family: 'Space Grotesk', sans-serif; font-size: 14px; font-weight: 800;
            color: var(--red-accent); text-transform: uppercase; letter-spacing: 0.5px;
            display: flex; align-items: center; gap: 8px;
        }
        .card-body-rt { padding: 28px; }

        .form-label-rt { font-size: 12px; font-weight: 700; color: var(--text-sub); margin-bottom: 6px; display: block; }
        .form-control-rt {
            width: 100%; padding: 10px 14px; border-radius: 10px;
            border: 1px solid var(--border-color); background: #f8fafc;
            color: var(--text-main); font-size: 13px; outline: none; transition: var(--transition);
        }
        .form-control-rt:focus {
            border-color: var(--red-accent); box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
            background: #fff;
        }
        .form-control-rt[readonly] { background: #f1f5f9; color: var(--text-muted); cursor: not-allowed; }

        .btn-rt-submit {
            background: var(--red-accent); color: #fff; border: none; padding: 10px 24px;
            border-radius: 10px; font-size: 13px; font-weight: 800; transition: var(--transition); cursor: pointer;
            display: inline-flex; align-items: center; gap: 8px; text-decoration: none;
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.2);
        }
        .btn-rt-submit:hover { background: #b91c1c; color: #fff; transform: translateY(-1px); }

        .btn-rt-secondary {
            background: #fff; color: var(--text-main); border: 1px solid var(--border-color);
            padding: 10px 24px; border-radius: 10px; font-size: 13px; font-weight: 700; text-decoration: none;
            transition: var(--transition); display: inline-flex; align-items: center; gap: 8px;
        }
        .btn-rt-secondary:hover { background: var(--bg-card-alt); color: var(--text-main); border-color: var(--border-strong); }

        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 999; }

        @media (max-width: 992px) {
            .sidebar { transform: translateX(-100%); width: var(--sidebar-width) !important; }
            body.sidebar-open .sidebar { transform: translateX(0); }
            body.sidebar-open .sidebar-overlay { display: block; }
            .main { margin-left: 0 !important; }
            .rt-topbar-brand { width: auto !important; border-right: none; }
        }

        @media (max-width: 768px) {
            .rt-topbar-left .page-title span { display: none; }
            .rt-topbar-center { display: none; }
            .dashboard-shell { padding: 12px; }
            .page-header-card { padding: 16px; }
            .card-body-rt { padding: 20px 16px; }
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
            Reset Password <span>&middot; Reset account password</span>
        </div>
    </div>

    <!-- LIVE SEARCH BAR -->
    <div class="rt-topbar-center">
        <div class="rt-search-container">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" id="globalNavSearch" placeholder="Search tracking no, rental name, owner..." value="<?php echo htmlspecialchars($searchQuery); ?>" autocomplete="off">
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

            <div id="notifDropdown" style="display:none; position:absolute; top:46px; right:0; width:300px; background:#fff; border:1px solid var(--border-color); border-radius:14px; padding:12px; box-shadow:0 10px 30px rgba(0,0,0,0.1); z-index:999;">
                <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); padding-bottom:8px; margin-bottom:8px;">
                    <span style="font-weight:700; color:var(--text-main); font-size:12px;">System Notifications</span>
                    <span style="font-size:10px; color:var(--red-accent); cursor:pointer;" id="clearNotifBadge">Mark all read</span>
                </div>
                <div id="notifListContainer">
                    <?php if (!empty($notifications)): ?>
                        <?php foreach($notifications as $n): ?>
                            <a href="<?php echo htmlspecialchars($n['target_url'] ?: 'javascript:void(0)'); ?>" class="d-block text-decoration-none" style="padding:8px 6px; border-bottom:1px solid rgba(0,0,0,0.05); border-radius:6px; transition:background 0.2s;" onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='transparent'">
                                <div style="font-size:11.5px; font-weight:700; color:var(--text-main);"><?php echo htmlspecialchars($n['title']); ?></div>
                                <div style="font-size:10.5px; color:var(--text-muted); margin-top:2px;"><?php echo htmlspecialchars($n['message']); ?></div>
                                <div style="font-size:9px; color:var(--red-accent); margin-top:4px;"><?php echo date('M d, h:i A', strtotime($n['created_at'])); ?></div>
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

<!-- MAIN WRAPPER & SIDEBAR -->
<div class="wrapper">

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <div class="animated-logo-avatar">
                <span>RL</span>
            </div>
            <div>
                RENTRACK <span style="color: #fecdd3;">LGU</span>
            </div>
        </div>

        <div class="nav-section">
            <div class="nav-label">Main</div>
            <a href="dashboard.php" class="nav-item" data-title="Dashboard"><i class="fa-solid fa-gauge-high"></i> <span class="menu-text">Dashboard</span></a>
            <a href="rentals.php" class="nav-item" data-title="Rentals"><i class="fa-solid fa-building"></i> <span class="menu-text">Rentals</span></a>
            <a href="users.php" class="nav-item active" data-title="Users"><i class="fa-solid fa-users"></i> <span class="menu-text">Users</span></a>

            <div class="nav-label">Operations</div>
            <a href="payments.php" class="nav-item" data-title="Payments"><i class="fa-solid fa-cash-register"></i> <span class="menu-text">Payments</span></a>
            <a href="inspections.php" class="nav-item" data-title="Inspections"><i class="fa-solid fa-clipboard-check"></i> <span class="menu-text">Inspections</span></a>
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
                <h2><i class="fa-solid fa-key"></i> Reset Password</h2>
                <p>Reset account password for system user</p>
            </div>

            <?php if ($error !== ""): ?>
                <div class="alert-danger-rt">
                    <i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="card-rt">
                <div class="card-header-rt">
                    <i class="fa-solid fa-address-card"></i> Account Information
                </div>
                <div class="card-body-rt">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">

                        <div class="mb-3">
                            <label class="form-label-rt"><i class="fa-solid fa-user me-1"></i> Full Name</label>
                            <input type="text" class="form-control-rt" value="<?php echo htmlspecialchars($user['fullname']); ?>" readonly>
                        </div>

                        <div class="mb-3">
                            <label class="form-label-rt"><i class="fa-solid fa-user-tag me-1"></i> Username</label>
                            <input type="text" class="form-control-rt" value="<?php echo htmlspecialchars($user['username']); ?>" readonly>
                        </div>

                        <div class="mb-3">
                            <label class="form-label-rt"><i class="fa-solid fa-lock me-1"></i> New Password</label>
                            <input type="password" name="password" class="form-control-rt" placeholder="Enter new password (min. 6 characters)" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label-rt"><i class="fa-solid fa-lock me-1"></i> Confirm Password</label>
                            <input type="password" name="confirm_password" class="form-control-rt" placeholder="Re-enter new password" required>
                        </div>

                        <div class="mt-4 d-flex gap-2 flex-wrap">
                            <button type="submit" name="reset_password" class="btn-rt-submit">
                                <i class="fa-solid fa-key"></i> Reset Password
                            </button>
                            <a href="users.php" class="btn-rt-secondary">
                                <i class="fa-solid fa-arrow-left"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    // ===== SIDEBAR TOGGLE =====
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
        fetch('reset_password.php?action=mark_notif_read');
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
                    window.location.href = `users.php?search=${encodeURIComponent(val)}`;
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
    document.getElementById('sidebarLogoutBtn')?.addEventListener('click', confirmLogout);
</script>
</body>
</html>