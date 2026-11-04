<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();
date_default_timezone_set('Asia/Manila');
require_once("../config/db.php");
require_once("includes/auth.php");

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
if ($userId === 0) {
    header('Location: ../login.php');
    exit;
}

$userName = isset($_SESSION['fullname']) ? htmlspecialchars(trim($_SESSION['fullname']), ENT_QUOTES, 'UTF-8') : 'Admin';
$userInitial = strtoupper(substr(trim($userName), 0, 1));

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function columnExists($conn, $tableName, $columnName) {
    try {
        $stmt = $conn->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :t AND column_name = :c)");
        $stmt->execute([':t' => strtolower($tableName), ':c' => strtolower($columnName)]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) { return false; }
}

$hasUserDeleted = columnExists($conn, 'users', 'is_deleted');

// ============ APPROVE / REJECT HANDLER ============
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $action = $_GET['action'];
    
    if ($action === 'approve' || $action === 'reject') {
        $newStatus = ($action === 'approve') ? 'Approved' : 'Rejected';
        
        try {
            $upd = $conn->prepare("UPDATE permit_renewals SET status = :st WHERE id = :id");
            $upd->execute([':st' => $newStatus, ':id' => $id]);
            
            if ($action === 'approve') {
                $getRen = $conn->prepare("SELECT rental_id, current_expiry_date, document_type, user_id FROM permit_renewals WHERE id = :id");
                $getRen->execute([':id' => $id]);
                $ren = $getRen->fetch(PDO::FETCH_ASSOC);
                
                if ($ren) {
                    $newExpiry = date('Y-m-d', strtotime($ren['current_expiry_date'] . ' +1 year'));
                    
                    try {
                        $updRental = $conn->prepare("UPDATE rentals SET permit_expiry_date = :exp WHERE id = :rid");
                        $updRental->execute([':exp' => $newExpiry, ':rid' => $ren['rental_id']]);
                    } catch (PDOException $e) {
                        try {
                            $updRental = $conn->prepare("UPDATE rentals SET expiry_date = :exp WHERE id = :rid");
                            $updRental->execute([':exp' => $newExpiry, ':rid' => $ren['rental_id']]);
                        } catch (PDOException $e2) {}
                    }
                    
                    try {
                        $notify = $conn->prepare("
                            INSERT INTO notifications (user_id, title, message, link, is_read, created_at) 
                            VALUES (:uid, 'Renewal Approved ✅', :msg, 'renew_documents.php', FALSE, NOW())
                        ");
                        $notify->execute([
                            ':uid' => $ren['user_id'],
                            ':msg' => 'Your ' . $ren['document_type'] . ' renewal has been APPROVED. New expiry: ' . $newExpiry
                        ]);
                    } catch (PDOException $e) {}
                }
            } else {
                try {
                    $getRen = $conn->prepare("SELECT user_id, document_type FROM permit_renewals WHERE id = :id");
                    $getRen->execute([':id' => $id]);
                    $ren = $getRen->fetch(PDO::FETCH_ASSOC);
                    
                    if ($ren) {
                        $notify = $conn->prepare("
                            INSERT INTO notifications (user_id, title, message, link, is_read, created_at) 
                            VALUES (:uid, 'Renewal Rejected ❌', :msg, 'renew_documents.php', FALSE, NOW())
                        ");
                        $notify->execute([
                            ':uid' => $ren['user_id'],
                            ':msg' => 'Your ' . $ren['document_type'] . ' renewal was rejected. Please review and resubmit.'
                        ]);
                    }
                } catch (PDOException $e) {}
            }
            
            $_SESSION['flash_msg'] = ($action === 'approve') ? 'Renewal approved successfully!' : 'Renewal rejected.';
            $_SESSION['flash_type'] = ($action === 'approve') ? 'success' : 'warning';
            header("Location: renewals_approval.php");
            exit;
        } catch (PDOException $e) {
            $error = "Database error: " . safe_error($e);
        }
    }
}

// ============ STATS ============
$stats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
try {
    $baseStatQuery = "
        FROM permit_renewals pr
        INNER JOIN users u ON pr.user_id = u.id
        WHERE 1=1 " . ($hasUserDeleted ? " AND u.is_deleted = FALSE" : "");
    
    $stats['total']    = (int)$conn->query("SELECT COUNT(*) $baseStatQuery")->fetchColumn();
    $stats['pending']  = (int)$conn->query("SELECT COUNT(*) $baseStatQuery AND pr.status='Pending'")->fetchColumn();
    $stats['approved'] = (int)$conn->query("SELECT COUNT(*) $baseStatQuery AND pr.status='Approved'")->fetchColumn();
    $stats['rejected'] = (int)$conn->query("SELECT COUNT(*) $baseStatQuery AND pr.status='Rejected'")->fetchColumn();
} catch (PDOException $e) {
    $stats['total']    = (int)$conn->query("SELECT COUNT(*) FROM permit_renewals")->fetchColumn();
    $stats['pending']  = (int)$conn->query("SELECT COUNT(*) FROM permit_renewals WHERE status='Pending'")->fetchColumn();
    $stats['approved'] = (int)$conn->query("SELECT COUNT(*) FROM permit_renewals WHERE status='Approved'")->fetchColumn();
    $stats['rejected'] = (int)$conn->query("SELECT COUNT(*) FROM permit_renewals WHERE status='Rejected'")->fetchColumn();
}

// ============ SEARCH + FETCH ============
$search = trim($_GET['search'] ?? '');

$sql = "SELECT pr.*, 
               u.fullname AS owner_name, 
               u.email AS owner_email,
               r.business_name, 
               r.rental_name, 
               r.tracking_no
        FROM permit_renewals pr 
        INNER JOIN users u ON pr.user_id = u.id 
        LEFT JOIN rentals r ON pr.rental_id = r.id
        WHERE 1=1 " . ($hasUserDeleted ? " AND u.is_deleted = FALSE" : "");

$params = [];
if (!empty($search)) {
    $sql .= " AND (u.fullname ILIKE :s OR r.business_name ILIKE :s OR r.tracking_no ILIKE :s OR pr.document_type ILIKE :s)";
    $params[':s'] = '%' . $search . '%';
}
$sql .= " ORDER BY pr.created_at DESC";

try {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $renewals = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $sql = str_replace('ILIKE', 'LIKE', $sql);
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $renewals = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$flashMsg = $_SESSION['flash_msg'] ?? '';
$flashType = $_SESSION['flash_type'] ?? 'success';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#dc2626">
    <title>Renewal Monitoring | Admin - RENTRACK LGU</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

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
            -moz-osx-font-smoothing: grayscale;
            touch-action: manipulation;
        }

        body.drawer-open { overflow: hidden !important; touch-action: none; }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: var(--bg-body); }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 20px; }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .rt-panel, .rt-chart-card { animation: fadeUp 0.5s cubic-bezier(0.4,0,0.2,1) both; }

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

        .rt-topbar-left { display: flex; align-items: center; height: 100%; flex-shrink: 0; }

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

        /* Hamburger (mobile only) */
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

        .nav-link .menu-badge {
            background: #fbbf24; color: #7c2d12; font-size: 10px; font-weight: 700;
            padding: 1px 7px; border-radius: 999px;
            box-shadow: 0 0 8px rgba(251, 191, 36, 0.55);
        }

        /* Dropdown */
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

        /* Mobile drawer header (hidden on desktop) */
        .mobile-drawer-header { display: none; }

        /* ============================================
           SEARCH & RIGHT ICONS
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
           OVERLAY
        ============================================ */
        .sidebar-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.5); z-index: 1040;
            -webkit-backdrop-filter: blur(2px); backdrop-filter: blur(2px);
            opacity: 0; transition: opacity 0.3s ease;
        }
        .sidebar-overlay.active { display: block; opacity: 1; }

        /* ============================================
           MAIN CONTENT & PANEL
        ============================================ */
        .wrapper { display: flex; min-height: calc(100vh - var(--header-total-height)); min-height: calc(100dvh - var(--header-total-height)); }
        .main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
        .dashboard-shell { padding: 16px 20px 30px; flex: 1; }

        .rt-panel {
            background: var(--bg-card); border: 1px solid var(--border-color);
            border-radius: 16px; padding: 20px;
            box-shadow: var(--shadow-soft);
        }
        .rt-panel-head {
            display: flex; align-items: flex-end; justify-content: space-between;
            flex-wrap: wrap; gap: 12px; margin-bottom: 16px;
        }
        .rt-title {
            font-family: 'Space Grotesk', sans-serif;
            color: var(--text-main); font-size: 20px; font-weight: 800; margin-bottom: 0;
            letter-spacing: -0.2px;
        }
        .rt-live-pill {
            display: inline-flex; align-items: center; gap: 7px;
            background: rgba(5, 150, 105, 0.1); border: 1px solid rgba(5, 150, 105, 0.2);
            color: var(--emerald-accent); font-size: 11px; font-weight: 700;
            padding: 6px 12px; border-radius: 999px;
        }
        .rt-live-pill .dot {
            width: 7px; height: 7px; border-radius: 50%; background: var(--emerald-accent);
            box-shadow: 0 0 8px var(--emerald-accent);
            animation: pulseDot 1.8s ease-in-out infinite;
        }
        @keyframes pulseDot { 0%,100% { opacity: 1; } 50% { opacity: 0.35; } }

        /* WELCOME BANNER */
        .welcome-banner {
            background: #fff1f2;
            border-radius: 14px; padding: 22px 26px; color: var(--text-main);
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 18px; box-shadow: var(--shadow-soft);
            position: relative; overflow: hidden; border: 1px solid #fecdd3;
        }
        .welcome-banner::after {
            content: ''; position: absolute; width: 180px; height: 180px; border-radius: 50%;
            background: rgba(225, 29, 72, 0.05); bottom: -60px; right: -40px; pointer-events: none;
        }
        .welcome-content { display: flex; flex-direction: column; gap: 4px; z-index: 1; width: 100%; min-width: 0; }
        .welcome-portal-badge {
            display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px;
            background: rgba(225, 29, 72, 0.1); border: 1px solid rgba(225, 29, 72, 0.2);
            border-radius: 999px; color: var(--red-accent); font-weight: 700; font-size: 10px;
            text-transform: uppercase; letter-spacing: 0.5px; width: fit-content; margin-bottom: 6px;
        }
        .welcome-title {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 24px; font-weight: 800; color: #9f1239; margin: 0;
            display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
        }

        /* STATS GRID */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 22px;
        }
        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 16px 18px;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: var(--shadow-soft);
            transition: var(--transition);
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lift);
            border-color: var(--border-strong);
        }
        .stat-icon {
            width: 48px; height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: #fff;
            flex-shrink: 0;
            position: relative;
            overflow: hidden;
        }
        .stat-icon::after {
            content: '';
            position: absolute;
            width: 60px; height: 60px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            bottom: -20px; right: -20px;
        }
        .stat-icon.total    { background: linear-gradient(135deg, #0284c7, #0369a1); }
        .stat-icon.pending  { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .stat-icon.approved { background: linear-gradient(135deg, #10b981, #059669); }
        .stat-icon.rejected { background: linear-gradient(135deg, #e11d48, #9f1239); }

        .stat-info h3 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 26px;
            font-weight: 800;
            margin: 0;
            color: var(--text-main);
            line-height: 1.1;
        }
        .stat-info p {
            font-size: 11px;
            color: var(--text-muted);
            margin: 2px 0 0;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        /* SEARCH BAR */
        .search-form {
            display: flex;
            gap: 8px;
            flex: 1;
            max-width: 400px;
        }
        .search-form input {
            flex: 1;
            padding: 9px 14px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            font-size: 13px;
            outline: none;
            background: var(--bg-card-alt);
            color: var(--text-main);
            transition: var(--transition);
        }
        .search-form input::placeholder { color: var(--text-muted); }
        .search-form input:focus {
            border-color: var(--red-accent);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }
        .search-form button {
            background: var(--red-accent);
            color: #fff;
            border: none;
            padding: 9px 16px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }
        .search-form button:hover { background: var(--red-hover); }

        /* TABLE */
        .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        table {
            width: 100%; border-collapse: collapse; font-size: 13px; min-width: 950px;
        }
        thead th {
            background: var(--bg-card-alt); color: var(--text-muted);
            padding: 12px 14px; font-weight: 700; text-transform: uppercase;
            font-size: 10.5px; text-align: left;
            border-bottom: 1px solid var(--border-color); letter-spacing: 0.05em; white-space: nowrap;
        }
        tbody td {
            padding: 14px; border-bottom: 1px solid var(--border-color);
            color: var(--text-sub); vertical-align: middle;
        }
        tbody tr { transition: var(--transition); }
        tbody tr:hover { background: #f8fafc; }

        .badge-status {
            padding: 4px 12px; border-radius: 999px; font-size: 10.5px; font-weight: 700;
            display: inline-flex; align-items: center; gap: 4px;
            text-transform: uppercase; letter-spacing: 0.03em; white-space: nowrap;
        }
        .badge-pending  { background: #fef3c7; color: #d97706; border: 1px solid #fde68a; }
        .badge-approved { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .badge-rejected { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }

        .btn-action {
            padding: 7px 12px; border-radius: 8px; font-size: 11px; font-weight: 700;
            text-decoration: none; display: inline-flex; align-items: center; gap: 5px;
            border: 1px solid transparent; cursor: pointer; transition: var(--transition); white-space: nowrap;
        }
        .btn-approve { background: #dcfce7; color: #15803d; border-color: #bbf7d0; }
        .btn-approve:hover { background: #10b981; color: #fff; border-color: #10b981; transform: translateY(-1px); }
        .btn-reject { background: #fee2e2; color: #b91c1c; border-color: #fecaca; }
        .btn-reject:hover { background: #e11d48; color: #fff; border-color: #e11d48; transform: translateY(-1px); }
        .btn-view { background: #e0f2fe; color: #0284c7; border-color: #bae6fd; }
        .btn-view:hover { background: #0891b2; color: #fff; border-color: #0891b2; transform: translateY(-1px); }

        .action-cell { display: flex; gap: 6px; flex-wrap: wrap; }

        .empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
        .empty-state i { font-size: 50px; color: #cbd5e1; margin-bottom: 12px; display: block; }
        .empty-state h3 { font-size: 16px; font-weight: 700; color: var(--text-main); }
        .empty-state p { font-size: 13px; margin-top: 6px; }

        /* FOOTER */
        .footer {
            margin-top: 20px; padding: 16px 0 calc(16px + var(--safe-bottom)) 0;
            border-top: 1px solid var(--border-color); display: flex;
            justify-content: space-between; align-items: center; font-size: 11.5px;
            color: var(--text-muted); flex-wrap: wrap; gap: 8px; text-align: center;
        }

        /* RESPONSIVE */
        @media (max-width: 1400px) {
            .nav-link { padding: 8px 10px; font-size: 12px; }
            .nav-link .nav-ico { font-size: 12px; }
            .rt-topbar-center { max-width: 320px; }
        }

        @media (max-width: 1200px) {
            .rt-topbar-left { flex-shrink: 1; min-width: 0; }
            .menu-toggle { display: flex; }
            .main-nav-bar {
                position: fixed; top: 0; left: -100%; width: 85vw; max-width: 320px;
                height: 100vh; height: 100dvh; flex-direction: column;
                align-items: stretch; justify-content: flex-start; padding: 0;
                z-index: 1090; overflow-y: auto; -webkit-overflow-scrolling: touch;
                transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                background: #ffffff; box-shadow: 6px 0 25px rgba(0,0,0,0.18); flex: none;
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
                flex-direction: column; width: 100%; justify-content: flex-start; gap: 4px;
                padding: 14px; padding-bottom: calc(20px + var(--safe-bottom)); height: auto;
            }
            .nav-item { width: 100%; height: auto; display: block; }
            .nav-link {
                padding: 14px 16px; border-radius: 10px; justify-content: flex-start;
                font-size: 14.5px; min-height: 48px; width: 100%; height: auto;
            }
            .nav-link .chevron { margin-left: auto; font-size: 11px; }
            .nav-link .nav-ico { font-size: 15px; width: 18px; }
            .nav-dropdown, .nav-item.open .nav-dropdown, .nav-item.align-right .nav-dropdown {
                position: static; left: auto; right: auto; transform: none; opacity: 1; visibility: visible;
                box-shadow: none; border: none; max-height: 0; overflow: hidden;
                transition: max-height 0.3s ease, padding 0.2s ease; padding: 0 0 0 16px;
                background: #f8fafc; margin-top: 4px; border-radius: 10px; min-width: 0;
            }
            .nav-item.open .nav-dropdown { max-height: 600px; padding: 8px 0 8px 16px; }
            .nav-dropdown a { padding: 12px 14px; min-height: 44px; font-size: 13.5px; }
            .rt-topbar-center { order: 5; flex-basis: 100%; max-width: 100%; margin-top: 4px; margin-bottom: 4px; padding: 0; }
            .notif-dropdown-el { width: calc(100vw - 24px) !important; max-width: 400px; right: -50px !important; }
        }

        @media (max-width: 992px) {
            .rt-topbar { flex-wrap: wrap; height: auto; padding-bottom: 6px; }
            .rt-topbar-brand { border-right: none; }
            .rt-topbar-center { order: 5; flex-basis: 100%; margin-top: 4px; margin-bottom: 4px; }
            body { padding-top: calc(var(--topofficial-height) + var(--navbar-height) + 50px); }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 768px) {
            .rt-topbar-right { gap: 8px; padding-right: calc(12px + var(--safe-right)); }
            .dashboard-shell { padding: 14px 14px 24px; }
            .rt-panel { padding: 16px; }
            .rt-title { font-size: 18px; }
            .rt-topbar-brand-text { display: none; }
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
            .rt-panel { padding: 12px; border-radius: 12px; }
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 14px; }
            .stat-card { padding: 12px; gap: 10px; border-radius: 12px; }
            .stat-icon { width: 38px; height: 38px; font-size: 15px; border-radius: 10px; }
            .stat-info h3 { font-size: 20px; }
            .stat-info p { font-size: 9.5px; }
            .welcome-banner { padding: 16px 14px; border-radius: 12px; flex-direction: column; align-items: flex-start; gap: 14px; }
            .welcome-title { font-size: 17px; gap: 6px; }
            .welcome-portal-badge { font-size: 9px; padding: 2px 8px; }
            .search-form { max-width: 100%; }
            .search-form input { font-size: 12px; padding: 8px 12px; }
            .search-form button { padding: 8px 12px; font-size: 12px; }
            table { min-width: 800px; }
            thead th { padding: 10px 12px; font-size: 10px; }
            tbody td { padding: 11px 12px; font-size: 12px; }
            .btn-action { padding: 6px 10px; font-size: 10.5px; }
            .badge-status { font-size: 10px; padding: 3px 9px; }
            .footer { font-size: 10px; flex-direction: column; text-align: center; }
        }

        @media (max-width: 400px) {
            .stats-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<!-- ============ TOP OFFICIAL BAR ============ -->
<div class="top-official-bar">
    <div class="topbar-left">
        <i class="fa-solid fa-landmark"></i>
        <strong>REPUBLIC OF THE PHILIPPINES</strong>
        <span class="divider"></span>
        <span class="portal-title">LGU Rental Portal</span>
    </div>
    <div class="topbar-right">
        <span class="cert-badge"><i class="fa-solid fa-certificate"></i> V2.1</span>
        <span class="d-none d-lg-inline" style="font-size:11px; color:#fecdd3;">City Treasurer &amp; Building Official</span>
    </div>
</div>

<!-- ============ MAIN NAVBAR (HORIZONTAL TOP NAV) ============ -->
<header class="rt-topbar">
    <div class="rt-topbar-left">
        <!-- Hamburger (mobile only) -->
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
            <!-- Mobile-only drawer header -->
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
                <!-- Dashboard -->
                <li class="nav-item">
                    <a href="dashboard.php" class="nav-link">
                        <i class="fa-solid fa-gauge-high nav-ico"></i> Dashboard
                    </a>
                </li>

                <!-- Rentals -->
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

                <!-- Users -->
                <li class="nav-item">
                    <a href="users.php" class="nav-link">
                        <i class="fa-solid fa-users nav-ico"></i> Users
                    </a>
                </li>

                <!-- Operations -->
                <li class="nav-item has-dropdown">
                    <button type="button" class="nav-link" data-dropdown>
                        <i class="fa-solid fa-clipboard-check nav-ico"></i> Operations
                        <i class="fa-solid fa-chevron-down chevron"></i>
                    </button>
                    <div class="nav-dropdown">
                        <a href="payments.php"><i class="fa-solid fa-cash-register"></i> Payments</a>
                        <a href="inspections.php"><i class="fa-solid fa-clipboard-check"></i> Inspections</a>
                        <!-- Active item -->
                        <a href="renewals_approval.php" style="background: var(--red-soft); color: var(--red-accent); font-weight: 700;"><i class="fa-solid fa-file-circle-exclamation"></i> Permit Renewals</a>
                    </div>
                </li>

                <!-- Staff Management -->
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

                <!-- Reports & System -->
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

    <!-- Search -->
    <div class="rt-topbar-center">
        <div class="rt-search-container" onclick="alert('Search feature is on the Dashboard.')">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" placeholder="Search tracking, business, property..." readonly style="cursor: pointer;">
        </div>
    </div>

    <!-- Right icons -->
    <div class="rt-topbar-right">
        <button class="icon-btn mobile-search-btn" title="Search" onclick="alert('Search feature is on the Dashboard.')">
            <i class="fa-solid fa-magnifying-glass"></i>
        </button>

        <div style="position:relative;">
            <button class="icon-btn" title="Notifications">
                <i class="fa-regular fa-bell"></i>
            </button>
        </div>

        <div class="rt-profile-wrapper">
            <div class="rt-profile-btn" id="profileToggle">
                <div class="avatar"><?php echo $userInitial; ?></div>
                <div class="info d-none d-sm-flex">
                    <span class="name"><?php echo h($userName); ?></span>
                    <span class="role">Admin</span>
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

<!-- Overlay (mobile drawer) -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ============ MAIN CONTENT ============ -->
<div class="wrapper">
    <div class="main">
        <div class="dashboard-shell">
            <div class="rt-panel">

                <!-- WELCOME BANNER -->
                <div class="welcome-banner">
                    <div class="welcome-content">
                        <span class="welcome-portal-badge"><i class="fa-solid fa-file-circle-check"></i> RENEWAL MONITORING</span>
                        <h2 class="welcome-title">
                            Manage Document Renewals <i class="fa-solid fa-stamp" style="font-size:20px; color:var(--red-accent);"></i>
                        </h2>
                    </div>
                </div>

                <div class="rt-panel-head">
                    <div>
                        <h2 class="rt-title">Renewal Requests Overview</h2>
                    </div>
                    <span class="rt-live-pill"><span class="dot"></span> Live data</span>
                </div>

                <!-- STATS GRID -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon total"><i class="fa-solid fa-layer-group"></i></div>
                        <div class="stat-info"><h3><?php echo $stats['total']; ?></h3><p>Total Requests</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon pending"><i class="fa-solid fa-clock"></i></div>
                        <div class="stat-info"><h3><?php echo $stats['pending']; ?></h3><p>Pending</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon approved"><i class="fa-solid fa-circle-check"></i></div>
                        <div class="stat-info"><h3><?php echo $stats['approved']; ?></h3><p>Approved</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon rejected"><i class="fa-solid fa-circle-xmark"></i></div>
                        <div class="stat-info"><h3><?php echo $stats['rejected']; ?></h3><p>Rejected</p></div>
                    </div>
                </div>

                <!-- TABLE PANEL -->
                <div class="rt-panel" style="padding:0; overflow:hidden;">
                    <div class="rt-panel-head" style="padding:16px 22px; margin-bottom:0; border-bottom:1px solid var(--border-color); background:#fff;">
                        <h2 class="rt-title" style="font-size:16px;"><i class="fa-solid fa-list-check me-2" style="color:var(--red-accent);"></i> All Renewal Requests</h2>
                        <form method="GET" class="search-form">
                            <input type="text" name="search" placeholder="Search name, business, tracking..." value="<?php echo h($search); ?>">
                            <button type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                        </form>
                    </div>

                    <div class="table-responsive">
                        <?php if (!empty($renewals)): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Owner</th>
                                    <th>Property / Business</th>
                                    <th>Document Type</th>
                                    <th>Current Expiry</th>
                                    <th>File</th>
                                    <th>Submitted</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($renewals as $i => $row): 
                                    $st = strtolower($row['status'] ?? 'pending');
                                    $badgeClass = 'badge-pending';
                                    if ($st === 'approved') $badgeClass = 'badge-approved';
                                    elseif ($st === 'rejected') $badgeClass = 'badge-rejected';
                                    
                                    $bizName = !empty($row['business_name']) ? $row['business_name'] : ($row['rental_name'] ?? 'N/A');

                                    // ✅ FIX: Tukuyin kung Supabase URL o local file
                                    $filePath = $row['supporting_file'] ?? '';
                                    if (!empty($filePath)) {
                                        if (filter_var($filePath, FILTER_VALIDATE_URL)) {
                                            $viewUrl = h($filePath); // Supabase
                                        } else {
                                            $viewUrl = "../uploads/renewals/" . h($filePath); // Local
                                        }
                                    }
                                ?>
                                <tr>
                                    <td><?php echo $i + 1; ?></td>
                                    <td>
                                        <strong style="color:var(--text-main);"><?php echo h($row['owner_name'] ?? 'Unknown'); ?></strong>
                                        <?php if (!empty($row['owner_email'])): ?>
                                            <br><small style="color:var(--text-muted); font-size:10.5px;"><?php echo h($row['owner_email']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong style="color:var(--text-main);"><?php echo h($bizName); ?></strong>
                                        <?php if (!empty($row['tracking_no'])): ?>
                                            <br><small style="color:var(--text-muted); font-size:10.5px;"><?php echo h($row['tracking_no']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo h($row['document_type']); ?></td>
                                    <td><?php echo !empty($row['current_expiry_date']) ? date('M d, Y', strtotime($row['current_expiry_date'])) : '—'; ?></td>
                                    <td>
                                        <?php if (!empty($filePath)): ?>
                                            <a href="<?php echo $viewUrl; ?>" target="_blank" rel="noopener noreferrer" class="btn-action btn-view">
                                                <i class="fa-solid fa-eye"></i> View
                                            </a>
                                        <?php else: ?>
                                            <span style="color:var(--text-muted); font-style:italic; font-size:11px;">No file</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo !empty($row['created_at']) ? date('M d, Y', strtotime($row['created_at'])) : '—'; ?>
                                        <br><small style="color:var(--text-muted); font-size:10.5px;"><?php echo !empty($row['created_at']) ? date('h:i A', strtotime($row['created_at'])) : ''; ?></small>
                                    </td>
                                    <td>
                                        <span class="badge-status <?php echo $badgeClass; ?>">
                                            <?php if ($st === 'approved'): ?><i class="fa-solid fa-check"></i>
                                            <?php elseif ($st === 'rejected'): ?><i class="fa-solid fa-xmark"></i>
                                            <?php else: ?><i class="fa-solid fa-clock"></i><?php endif; ?>
                                            <?php echo h($row['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($st === 'pending'): ?>
                                            <div class="action-cell">
                                                <a href="?action=approve&id=<?php echo $row['id']; ?>" class="btn-action btn-approve approve-btn">
                                                    <i class="fa-solid fa-check"></i> Approve
                                                </a>
                                                <a href="?action=reject&id=<?php echo $row['id']; ?>" class="btn-action btn-reject reject-btn">
                                                    <i class="fa-solid fa-xmark"></i> Reject
                                                </a>
                                            </div>
                                        <?php else: ?>
                                            <span style="color:var(--text-muted); font-size:11px; font-style:italic;">
                                                <?php echo ($st === 'approved') ? 'Processed ✓' : 'Closed'; ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fa-solid fa-folder-open"></i>
                                <h3>No Renewal Requests Found</h3>
                                <p><?php echo !empty($search) ? 'Walang tugmang resulta sa iyong search.' : 'Wala pang nag-submit ng renewal request.'; ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

            <!-- FOOTER -->
            <footer class="footer">
                <span>&copy; <?php echo date('Y'); ?> <strong style="color:var(--red-accent);">RENTRACK LGU</strong> &middot; Admin Portal</span>
                <span>System Management &amp; Analytics</span>
            </footer>
        </div>
    </div>
</div>

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
    });

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

    /* ============================================
       TABLE ACTIONS
    ============================================ */
    document.querySelectorAll('.approve-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const url = this.getAttribute('href');
            Swal.fire({
                title: 'Approve this renewal?',
                text: 'The owner will be notified and the rental expiry will be extended.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#10b981',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Yes, Approve',
                cancelButtonText: 'Cancel',
                background: '#ffffff',
                color: '#0f172a'
            }).then(r => { if (r.isConfirmed) window.location.href = url; });
        });
    });

    document.querySelectorAll('.reject-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const url = this.getAttribute('href');
            Swal.fire({
                title: 'Reject this renewal?',
                text: 'The owner will be notified about the rejection.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#e11d48',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Yes, Reject',
                cancelButtonText: 'Cancel',
                background: '#ffffff',
                color: '#0f172a'
            }).then(r => { if (r.isConfirmed) window.location.href = url; });
        });
    });

    <?php if (!empty($flashMsg)): ?>
    Swal.fire({
        icon: '<?php echo $flashType; ?>',
        title: '<?php echo $flashType === "success" ? "Success!" : "Notice"; ?>',
        text: <?php echo json_encode($flashMsg); ?>,
        confirmButtonColor: '#e11d48',
        background: '#ffffff',
        color: '#0f172a',
        timer: 2500,
        timerProgressBar: true
    });
    <?php endif; ?>
</script>

</body>
</html>