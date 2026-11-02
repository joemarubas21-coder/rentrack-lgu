<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();
date_default_timezone_set('Asia/Manila');

require_once '../config/db.php';
require_once 'includes/auth.php';

/* =====================================================
   HELPER FUNCTIONS & SESSION DATA (PDO / PostgreSQL)
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
   AJAX HANDLERS (NOTIFICATION & GLOBAL SEARCH)
===================================================== */

if (isset($_GET['action']) && $_GET['action'] === 'mark_notif_read') {
    if (tableExists($conn, 'notifications')) {
        $notifId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($notifId > 0) {
            $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE id = :id AND user_id = :user_id");
            $stmt->execute([':id' => $notifId, ':user_id' => $adminUserId]);
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

        if (tableExists($conn, 'rentals')) {
            $nameCol = columnExists($conn, 'rentals', 'business_name') ? 'business_name' : 
                      (columnExists($conn, 'rentals', 'rental_name') ? 'rental_name' : 
                      (columnExists($conn, 'rentals', 'name') ? 'name' : 'id'));
            
            $ownerCol = columnExists($conn, 'rentals', 'owner_name') ? 'owner_name' : 'id';
            $trackCol = columnExists($conn, 'rentals', 'tracking_no') ? 'tracking_no' : 'id';
            
            $whereClause = [];
            if ($nameCol !== 'id') $whereClause[] = "$nameCol ILIKE :q";
            if ($ownerCol !== 'id') $whereClause[] = "$ownerCol ILIKE :q";
            if ($trackCol !== 'id') $whereClause[] = "$trackCol ILIKE :q";
            $whereClause[] = "id::text ILIKE :q";

            $whereSql = implode(' OR ', $whereClause);
            $deletedSql = columnExists($conn, 'rentals', 'is_deleted') ? "WHERE is_deleted = FALSE AND ($whereSql)" : "WHERE ($whereSql)";

            $stmt = $conn->prepare("SELECT * FROM rentals $deletedSql LIMIT 5");
            $stmt->execute([':q' => $searchTerm]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = [
                    'type' => 'Rental Property',
                    'title' => $row[$nameCol] ?? ('Rental #' . $row['id']),
                    'sub' => 'Owner: ' . ($row[$ownerCol] ?? 'N/A') . ' | Tracking: ' . ($row[$trackCol] ?? 'N/A'),
                    'link' => 'view_rental.php?id=' . $row['id']
                ];
            }
        }
    }

    echo json_encode($results);
    exit;
}

/* =====================================================
   FETCH RENTAL DETAILS
===================================================== */

if (!isset($_GET['id'])) {
    header("Location: rentals.php");
    exit();
}

$id = (int) $_GET['id'];

$stmt = $conn->prepare("
    SELECT r.*, 
           rt.type_name, 
           u.fullname AS owner_name, 
           u.email AS owner_email, 
           u.contact AS owner_contact
    FROM rentals r
    LEFT JOIN rental_types rt ON r.rental_type_id = rt.id
    LEFT JOIN users u ON r.user_id = u.id
    WHERE r.id = :id LIMIT 1
");
$stmt->execute([':id' => $id]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$r) {
    $_SESSION['error'] = 'Rental not found.';
    header("Location: rentals.php");
    exit();
}

$userName = $_SESSION['fullname'] ?? ($_SESSION['user_name'] ?? 'Admin');
$userRole = $_SESSION['role'] ?? ($_SESSION['user_role'] ?? 'Administrator');
$userInitial = strtoupper(substr($userName, 0, 1));
$searchQuery = trim($_GET['search'] ?? '');

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#dc2626">
    <title>View Rental | RENTRACK LGU</title>

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

        /* ============================================
           TOP OFFICIAL BAR
        ============================================ */
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

        /* ============================================
           MAIN NAVBAR (HORIZONTAL TOP NAV)
        ============================================ */
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

        /* ============================================
           HORIZONTAL NAV MENU
        ============================================ */
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
        .nav-dropdown a.danger { color: #dc2626; }

        .mobile-drawer-header { display: none; }

        /* ============================================
           SEARCH
        ============================================ */
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

        /* ============================================
           RIGHT SIDE ICONS
        ============================================ */
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

        /* ============================================
           LAYOUT (no sidebar on desktop)
        ============================================ */
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

        /* ============================================
           PAGE CONTENT
        ============================================ */
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

        .btn-rt-secondary {
            background: #fff; color: var(--text-main); border: 1px solid var(--border-color);
            padding: 8px 16px; border-radius: 10px; font-size: 12px; font-weight: 700; text-decoration: none;
            transition: var(--transition); display: inline-flex; align-items: center; gap: 8px;
        }
        .btn-rt-secondary:hover { background: var(--bg-card-alt); color: var(--text-main); border-color: var(--border-strong); }

        .details-card-rt {
            background: var(--bg-card); border: 1px solid var(--border-color);
            border-radius: 18px; overflow: hidden; margin-bottom: 20px;
            box-shadow: var(--shadow-soft);
        }
        .details-card-header {
            background: var(--bg-card-alt); border-bottom: 1px solid var(--border-color);
            padding: 14px 20px; font-family: 'Space Grotesk', sans-serif; font-size: 14px;
            font-weight: 800; color: var(--text-main); display: flex; align-items: center; gap: 8px;
        }
        .details-card-header i { color: var(--red-accent); }
        .details-card-body {
            padding: 24px; display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px 24px;
        }
        .info-group-rt { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
        .info-group-rt.full-width { grid-column: 1 / -1; }
        .info-group-rt label {
            font-size: 10.5px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.5px; color: var(--text-muted);
        }
        .info-group-rt p { font-size: 14px; font-weight: 600; color: var(--text-main); margin: 0; word-break: break-word; }

        .tracking-code {
            font-family: 'IBM Plex Mono', monospace; font-size: 12px; font-weight: 600;
            background: var(--bg-card-alt); padding: 3px 8px; border-radius: 6px; border: 1px solid var(--border-color);
            display: inline-block; color: var(--text-main); word-break: break-all;
        }

        .badge-rt {
            display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px;
            border-radius: 999px; font-size: 10px; font-weight: 700; text-transform: uppercase;
        }
        .badge-rt.success { background: rgba(5, 150, 105, 0.1); color: #059669; border: 1px solid rgba(5, 150, 105, 0.2); }
        .badge-rt.warning { background: rgba(217, 119, 6, 0.1); color: #d97706; border: 1px solid rgba(217, 119, 6, 0.2); }
        .badge-rt.danger { background: rgba(220, 38, 38, 0.1); color: #dc2626; border: 1px solid rgba(220, 38, 38, 0.2); }
        .badge-rt.dark { background: rgba(100, 116, 139, 0.1); color: #475569; border: 1px solid rgba(100, 116, 139, 0.2); }

        .footer {
            margin-top: auto; padding-top: 14px; border-top: 1px solid var(--border-color);
            display: flex; justify-content: space-between; font-size: 11.5px; color: var(--text-muted);
        }

        /* ============================================
           MOBILE BREAKPOINT — 1200px (drawer mode)
        ============================================ */
        @media (max-width: 1400px) {
            .nav-link { padding: 8px 10px; font-size: 12px; }
            .nav-link .nav-ico { font-size: 12px; }
            .rt-topbar-center { max-width: 320px; }
        }

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
            .details-card-body { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .rt-topbar-right { gap: 8px; padding-right: calc(12px + var(--safe-right)); }
            .rt-topbar-brand-text { display: none; }
            .dashboard-shell { padding: 12px; }
            .page-header-card { padding: 16px; flex-direction: column; align-items: flex-start; }
            .page-header-card > div:last-child { width: 100%; display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
            .page-header-card .btn-rt-secondary { flex: 1; justify-content: center; }
            .details-card-body { padding: 16px; }
            .footer { flex-direction: column; align-items: flex-start; gap: 6px; }
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
            .page-header-card > div:last-child { display: flex; gap: 8px; flex-wrap: wrap; }
            .btn-rt-secondary { flex: 1; justify-content: center; font-size: 11px; padding: 8px 12px; }

            .details-card-body { padding: 14px; gap: 12px 16px; }
            .info-group-rt p { font-size: 13px; }

            #notifDropdown {
                position: fixed !important; top: calc(var(--header-total-height) + 5px) !important;
                right: max(8px, var(--safe-right)) !important; left: max(8px, var(--safe-left)) !important;
                width: auto !important; max-width: none !important; max-height: min(70dvh, 430px); overflow-y: auto;
            }
        }

        @media (max-width: 430px) {
            .top-official-bar { font-size: 9px; }
            .topbar-left { max-width: 58%; }
            .topbar-left strong { font-size: 9px; }
            .cert-badge { font-size: 7.5px; padding: 3px 7px; }
            .rt-topbar-right { gap: 6px; }
            .mobile-search-btn, .icon-btn { width: 32px; height: 32px; }
            .page-header-card > div:last-child { grid-template-columns: 1fr; }
        }

        @media (hover: none) and (pointer: coarse) {
            .nav-link:hover, .icon-btn:hover, .rt-profile-btn:hover,
            .btn-rt-secondary:hover { transform: none; }
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

        <!-- ============ HORIZONTAL NAV MENU ============ -->
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
                    <button type="button" class="nav-link active" data-dropdown>
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
            <input type="text" id="openSearchModalBtn" placeholder="Search by tracking number, business name, or property..." readonly>
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

<div class="wrapper">
    <div class="main">
        <div class="dashboard-shell">

            <div class="page-header-card">
                <div>
                    <h2><i class="fa-solid fa-building"></i> <?php echo htmlspecialchars($r['business_name'] ?? ($r['rental_name'] ?? 'Rental Details')); ?></h2>
                    <p>Detailed view of registered rental property, compliance, and owner details.</p>
                </div>
                <div style="display:flex; gap:8px;">
                    <a href="edit_rental.php?id=<?php echo $r['id']; ?>" class="btn-rt-secondary"><i class="fa-solid fa-pen"></i> Edit Rental</a>
                    <a href="rentals.php" class="btn-rt-secondary"><i class="fa-solid fa-arrow-left"></i> Back to Rentals</a>
                </div>
            </div>

            <div class="details-card-rt">
                <div class="details-card-header">
                    <i class="fa-solid fa-file-lines"></i> Rental Property Details
                </div>
                <div class="details-card-body">
                    <div class="info-group-rt">
                        <label>Tracking Number</label>
                        <p><span class="tracking-code"><?php echo htmlspecialchars($r['tracking_no'] ?? 'N/A'); ?></span></p>
                    </div>
                    <div class="info-group-rt">
                        <label>Establishment Name</label>
                        <p><?php echo htmlspecialchars($r['rental_name'] ?? 'N/A'); ?></p>
                    </div>
                    <div class="info-group-rt">
                        <label>Business Name</label>
                        <p><?php echo htmlspecialchars($r['business_name'] ?? 'N/A'); ?></p>
                    </div>
                    <div class="info-group-rt">
                        <label>Rental Type</label>
                        <p><?php echo htmlspecialchars($r['type_name'] ?? 'Standard'); ?></p>
                    </div>
                    <div class="info-group-rt">
                        <label>Barangay Location</label>
                        <p><?php echo htmlspecialchars($r['barangay'] ?? 'N/A'); ?></p>
                    </div>
                    <div class="info-group-rt">
                        <label>Contact Number</label>
                        <p><?php echo htmlspecialchars($r['contact_number'] ?? 'N/A'); ?></p>
                    </div>
                    <div class="info-group-rt">
                        <label>Email Address</label>
                        <p><?php echo htmlspecialchars($r['email'] ?? 'N/A'); ?></p>
                    </div>
                    <div class="info-group-rt">
                        <label>Monthly Rate</label>
                        <p>₱<?php echo number_format((float)($r['monthly_rate'] ?? 0), 2); ?></p>
                    </div>
                    <div class="info-group-rt">
                        <label>Registration Date</label>
                        <p><?php echo !empty($r['registration_date']) ? date('M d, Y', strtotime($r['registration_date'])) : 'N/A'; ?></p>
                    </div>
                    <div class="info-group-rt">
                        <label>Permit Expiry</label>
                        <p><?php echo !empty($r['permit_expiry']) ? date('M d, Y', strtotime($r['permit_expiry'])) : 'N/A'; ?></p>
                    </div>
                    <div class="info-group-rt">
                        <label>Status</label>
                        <p>
                            <?php
                            $st = $r['status'] ?? 'Pending';
                            if ($st === 'Approved') echo '<span class="badge-rt success">Approved</span>';
                            elseif ($st === 'Pending') echo '<span class="badge-rt warning">Pending</span>';
                            elseif ($st === 'Rejected') echo '<span class="badge-rt danger">Rejected</span>';
                            else echo '<span class="badge-rt dark">' . htmlspecialchars($st) . '</span>';
                            ?>
                        </p>
                    </div>
                    <div class="info-group-rt">
                        <label>Compliance Status</label>
                        <p>
                            <?php
                            $comp = $r['compliance_status'] ?? 'Pending';
                            if ($comp === 'Compliant') echo '<span class="badge-rt success">Compliant</span>';
                            elseif ($comp === 'Non-Compliant') echo '<span class="badge-rt danger">Non-Compliant</span>';
                            else echo '<span class="badge-rt warning">' . htmlspecialchars($comp) . '</span>';
                            ?>
                        </p>
                    </div>
                    <div class="info-group-rt full-width">
                        <label>Business Address</label>
                        <p><?php echo htmlspecialchars($r['business_address'] ?? 'N/A'); ?></p>
                    </div>
                    <div class="info-group-rt full-width">
                        <label>Description / Additional Notes</label>
                        <p><?php echo nl2br(htmlspecialchars($r['description'] ?? 'No descriptions available.')); ?></p>
                    </div>
                </div>
            </div>

            <div class="details-card-rt">
                <div class="details-card-header">
                    <i class="fa-solid fa-user-tie"></i> Owner Information
                </div>
                <div class="details-card-body">
                    <div class="info-group-rt">
                        <label>Owner Full Name</label>
                        <p><?php echo htmlspecialchars($r['owner_name'] ?? 'N/A'); ?></p>
                    </div>
                    <div class="info-group-rt">
                        <label>Owner Email Address</label>
                        <p><?php echo htmlspecialchars($r['owner_email'] ?? 'N/A'); ?></p>
                    </div>
                    <div class="info-group-rt">
                        <label>Owner Contact</label>
                        <p><?php echo htmlspecialchars($r['owner_contact'] ?? 'N/A'); ?></p>
                    </div>
                </div>
            </div>

            <footer class="footer">
                <span>&copy; <?php echo date('Y'); ?> <strong style="color:var(--red-accent);">RENTRACK LGU</strong> &middot; Admin Portal</span>
                <span>Rental Property Compliance &amp; Operations</span>
            </footer>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    /* ============================================
       DRAWER / NAV MENU
    ============================================ */
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

    /* ============================================
       NOTIFICATION BELL
    ============================================ */
    const notifBtn = document.getElementById('notifBtn');
    const notifDropdown = document.getElementById('notifDropdown');
    const notifBadgeDot = document.getElementById('notifBadgeDot');

    function clearNotificationDot() {
        if (notifBadgeDot) {
            notifBadgeDot.style.opacity = '0';
            setTimeout(() => { notifBadgeDot.style.display = 'none'; }, 300);
        }
        fetch('view_rental.php?action=mark_notif_read&id=<?php echo $id; ?>');
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

    /* ============================================
       PROFILE DROPDOWN
    ============================================ */
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

    /* ============================================
       SEARCH MODAL
    ============================================ */
    const openSearchModalBtn = document.getElementById('openSearchModalBtn');
    document.getElementById('mobileSearchBtn')?.addEventListener('click', () => openSearchModalBtn?.click());

    if (openSearchModalBtn) {
        openSearchModalBtn.addEventListener('click', () => {
            Swal.fire({
                html: `
                    <div style="text-align: left;">
                        <div style="position: relative; margin-bottom: 15px;">
                            <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #64748b; font-size: 15px;"></i>
                            <input type="text" id="swalSearchInput" placeholder="Search by tracking number, business name, or property..." autocomplete="off" style="width: 100%; padding: 12px 16px 12px 42px; background: #f8fafc; color: #0f172a; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 13px; outline: none;">
                        </div>
                        <div id="swalSearchResults" style="min-height: 120px; max-height: 280px; overflow-y: auto; text-align: center; color: #64748b; display: flex; flex-direction: column; justify-content: center; align-items: center;">
                            <i class="fa-solid fa-keyboard" style="font-size: 28px; margin-bottom: 8px; color: #cbd5e1;"></i>
                            <span style="font-size: 12px;">Type at least 2 characters to search records...</span>
                        </div>
                    </div>
                `,
                footer: `
                    <div style="display: flex; justify-content: space-between; width: 100%; font-size: 11px; color: #64748b; padding: 0 5px;">
                        <span>Quick search across all your property records</span>
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
                                <span style="font-size: 12px;">Type at least 2 characters to search records...</span>
                            `;
                            return;
                        }

                        resultsEl.style.justifyContent = 'flex-start';
                        resultsEl.style.alignItems = 'stretch';
                        resultsEl.innerHTML = `<div style="text-align: center; padding: 20px; font-size: 12px; color: #64748b;"><i class="fa-solid fa-spinner fa-spin me-2"></i> Searching records...</div>`;

                        debounceTimer = setTimeout(() => {
                            fetch(`view_rental.php?id=<?php echo $id; ?>&action=global_search&q=${encodeURIComponent(query)}`)
                                .then(res => res.json())
                                .then(data => {
                                    resultsEl.innerHTML = '';
                                    if (data.length === 0) {
                                        resultsEl.style.justifyContent = 'center';
                                        resultsEl.style.alignItems = 'center';
                                        resultsEl.innerHTML = '<div style="padding:20px; font-size:12px; color:#64748b; text-align:center;">No matching records found</div>';
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
                                window.location.href = `rentals.php?search=${encodeURIComponent(query)}`;
                            }
                        }
                    });
                }
            });
        });
    }

    /* ============================================
       LOGOUT
    ============================================ */
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