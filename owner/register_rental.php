<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();

if (!isset($_SESSION['regenerated'])) {
    session_regenerate_id(true);
    $_SESSION['regenerated'] = time();
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== "owner") {
    header("Location: ../login.php");
    exit();
}

require_once("../config/db.php");

$userId = (int) ($_SESSION['user_id'] ?? 0);
$userName = isset($_SESSION['fullname']) && !empty(trim($_SESSION['fullname']))
    ? htmlspecialchars(trim($_SESSION['fullname']), ENT_QUOTES, 'UTF-8')
    : 'Owner';
$userInitial = strtoupper(substr(trim($userName), 0, 1));

function tableExists($conn, $tableName) {
    try {
        $stmt = $conn->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :t)");
        $stmt->execute([':t' => strtolower($tableName)]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) { return false; }
}
function columnExists($conn, $tableName, $columnName) {
    try {
        $stmt = $conn->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :t AND column_name = :c)");
        $stmt->execute([':t' => strtolower($tableName), ':c' => strtolower($columnName)]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) { return false; }
}
function displayField($row, $possibleFields) {
    foreach ($possibleFields as $field) {
        if (isset($row[$field]) && trim((string)$row[$field]) !== '') return htmlspecialchars((string)$row[$field]);
    }
    return '—';
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* MARK NOTIF AS READ */
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'mark_notif_read') {
    header('Content-Type: application/json; charset=utf-8');
    $notifId = (int) ($_GET['id'] ?? 0);
    if ($userId > 0 && $notifId > 0 && tableExists($conn, 'notifications')) {
        try {
            $stmtUpdate = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE id = :nid AND user_id = :uid");
            $stmtUpdate->execute([':nid' => $notifId, ':uid' => $userId]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) { echo json_encode(['success' => false, 'error' => safe_error($e)]); }
    } else { echo json_encode(['success' => false]); }
    exit;
}

/* CLEAR NOTIFS */
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'clear_notifs') {
    header('Content-Type: application/json; charset=utf-8');
    if ($userId > 0 && tableExists($conn, 'notifications')) {
        try {
            $stmtDelete = $conn->prepare("DELETE FROM notifications WHERE user_id = :uid");
            $stmtDelete->execute([':uid' => $userId]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) { echo json_encode(['success' => false, 'error' => safe_error($e)]); }
    } else { echo json_encode(['success' => false]); }
    exit;
}

/* AI ASSISTANT */
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'ai_chat') {
    header('Content-Type: application/json; charset=utf-8');
    $question = trim(strtolower($_GET['msg'] ?? ''));
    $totalRentals = 0; $activeRentals = 0; $totalPaid = 0; $withPermit = 0;

    if (tableExists($conn, 'rentals')) {
        $stmtRentals = $conn->prepare("SELECT COUNT(*) FROM rentals WHERE user_id = :uid AND is_deleted = '0'");
        $stmtRentals->execute([':uid' => $userId]);
        $totalRentals = (int)$stmtRentals->fetchColumn();
        if (columnExists($conn, 'rentals', 'status')) {
            $stmtActive = $conn->prepare("SELECT COUNT(*) FROM rentals WHERE user_id = :uid AND LOWER(status::text) = 'active' AND is_deleted = '0'");
            $stmtActive->execute([':uid' => $userId]);
            $activeRentals = (int)$stmtActive->fetchColumn();
        }
        if (columnExists($conn, 'rentals', 'business_permit_no')) {
            $stmtPermit = $conn->prepare("SELECT COUNT(*) FROM rentals WHERE user_id = :uid AND business_permit_no IS NOT NULL AND business_permit_no != '' AND is_deleted = '0'");
            $stmtPermit->execute([':uid' => $userId]);
            $withPermit = (int)$stmtPermit->fetchColumn();
        }
    }
    if (tableExists($conn, 'payments') && tableExists($conn, 'rentals')) {
        $stmtPayments = $conn->prepare("SELECT COALESCE(SUM(p.amount_paid), 0) FROM payments p INNER JOIN rentals r ON p.rental_id = r.id WHERE r.user_id = :uid AND r.is_deleted = '0'");
        $stmtPayments->execute([':uid' => $userId]);
        $totalPaid = (float)$stmtPayments->fetchColumn();
    }

    $reply = "Hello! I am Rentrack AI. You can ask me about your rentals, payments, permits, or inspections.";
    if (strpos($question, 'how many') !== false || strpos($question, 'total') !== false || strpos($question, 'rental') !== false) {
        $reply = "You currently have {$totalRentals} registered rentals, with {$activeRentals} active properties.";
    } elseif (strpos($question, 'payment') !== false || strpos($question, 'paid') !== false || strpos($question, 'magkano') !== false) {
        $reply = "Your total payments received is ₱" . number_format($totalPaid, 2) . ".";
    } elseif (strpos($question, 'permit') !== false || strpos($question, 'business') !== false) {
        $reply = "You have {$withPermit} rentals with a valid business permit.";
    } elseif (strpos($question, 'inspection') !== false) {
        $reply = "Check your Inspection Schedule page for upcoming visits.";
    } elseif (strpos($question, 'hello') !== false || strpos($question, 'hi') !== false) {
        $reply = "Hello! How can I help you today?";
    } else {
        $reply = "You have {$totalRentals} rentals and ₱" . number_format($totalPaid, 2) . " total payments.";
    }
    echo json_encode(['reply' => $reply]);
    exit;
}

/* AJAX SEARCH */
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'search') {
    $query = trim($_GET['q'] ?? '');
    if (strlen($query) >= 2 && tableExists($conn, 'rentals')) {
        $searchTerm = '%' . $query . '%';
        $sql = "SELECT id, tracking_no, business_name, status, rental_name FROM rentals 
                WHERE user_id = :uid AND is_deleted = '0' 
                  AND (tracking_no ILIKE :q1 OR business_name ILIKE :q2 OR rental_name ILIKE :q3) LIMIT 5";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':uid'=>$userId, ':q1'=>$searchTerm, ':q2'=>$searchTerm, ':q3'=>$searchTerm]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($rows)) {
            echo '<div style="display:flex; flex-direction:column; gap:8px;">';
            foreach ($rows as $row) {
                $status = $row['status'] ?? 'Draft';
                $badgeBg = 'rgba(220,38,38,0.1)'; $badgeColor = '#dc2626';
                if (strtolower($status)==='pending') { $badgeBg='rgba(217,119,6,0.15)'; $badgeColor='#d97706'; }
                elseif (in_array(strtolower($status), ['approved','active'])) { $badgeBg='rgba(5,150,105,0.15)'; $badgeColor='#059669'; }
                echo '<a href="view_rental.php?id='.$row['id'].'" style="display:flex; align-items:center; justify-content:space-between; padding:12px 16px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; color:#0f172a; text-decoration:none;">';
                echo '<div style="display:flex; flex-direction:column; gap:4px;">';
                echo '<div style="font-weight:700; font-size:14px; color:#dc2626;">'.htmlspecialchars($row['tracking_no'] ?? '#'.$row['id']).' <span style="color:#94a3b8;">•</span> '.htmlspecialchars($row['business_name'] ?: $row['rental_name']).'</div>';
                echo '<div style="font-size:12px; color:#64748b;">Property: '.htmlspecialchars($row['rental_name'] ?? 'N/A').'</div>';
                echo '</div>';
                echo '<span style="padding:4px 10px; border-radius:20px; font-size:10px; font-weight:700; background:'.$badgeBg.'; color:'.$badgeColor.'; text-transform:uppercase;">'.htmlspecialchars($status).'</span>';
                echo '</a>';
            }
            echo '</div>';
        } else {
            echo '<div style="text-align:center; padding:30px; color:#64748b; font-size:13px;"><i class="fa-solid fa-folder-open" style="font-size:24px; margin-bottom:8px; display:block; opacity:0.5;"></i> No matching rental records found.</div>';
        }
    }
    exit;
}

/* Rental types */
$types = [];
try {
    $typesStmt = $conn->query("SELECT id, type_name FROM rental_types ORDER BY type_name ASC");
    $types = $typesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $types = []; }

$flashSuccess = $_SESSION['success'] ?? null;
$flashError   = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

/* Notifications */
$unreadNotifCount = 0; $notifications = [];
if (tableExists($conn, 'notifications')) {
    if (columnExists($conn, 'notifications', 'is_read')) {
        try {
            $cntStmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE (is_read = 0 OR is_read = FALSE) AND user_id = :uid");
            $cntStmt->execute([':uid' => $userId]);
            $unreadNotifCount = (int) $cntStmt->fetchColumn();
        } catch (PDOException $e) { $unreadNotifCount = 0; }
    }
    try {
        $stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = :uid ORDER BY created_at DESC LIMIT 5");
        $stmt->execute([':uid' => $userId]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { $notifications = []; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#dc2626">
    <title>Register Rental | RENTRACK LGU</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;700;800&display=swap" rel="stylesheet">

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        :root {
            --bg-light: #f8fafc;
            --bg-card: #ffffff;
            --card-border: #e2e8f0;
            --accent-blue: #dc2626;
            --accent-blue-dark: #991b1b;
            --accent-blue-light: #f87171;
            --blue-soft: #fee2e2;
            --text-primary: #0f172a;
            --text-secondary: #334155;
            --text-muted: #64748b;
            --text-faint: #94a3b8;
            --ok: #10b981; --ok-bg: #d1fae5;
            --warn: #f59e0b; --warn-bg: #fef3c7;
            --danger: #dc2626; --danger-bg: #fee2e2;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --safe-top: env(safe-area-inset-top, 0px);
            --safe-bottom: env(safe-area-inset-bottom, 0px);
            --safe-left: env(safe-area-inset-left, 0px);
            --safe-right: env(safe-area-inset-right, 0px);
        }

        @keyframes spinRing { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        html, body {
            margin: 0 !important; padding: 0 !important;
            height: 100%; font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            background: var(--bg-light); color: var(--text-primary);
            overflow-x: hidden; -webkit-text-size-adjust: 100%;
            -webkit-font-smoothing: antialiased;
        }
        * { scrollbar-width: thin; scrollbar-color: rgba(220, 38, 38, 0.4) transparent; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-thumb { background: var(--accent-blue); border-radius: 20px; }

        body.drawer-open { overflow: hidden !important; touch-action: none; }

        .main-wrapper {
            display: flex; flex-direction: column;
            min-height: 100vh; min-height: 100dvh;
            background: var(--bg-light);
            padding-left: var(--safe-left); padding-right: var(--safe-right);
        }

        /* TOP OFFICIAL BAR */
        .official-header {
            width: 100%; display: flex; align-items: center; justify-content: space-between;
            background: linear-gradient(90deg, #991b1b, #dc2626 55%, #991b1b);
            border-bottom: 2px solid #7f1d1d;
            padding: 6px 20px; padding-top: calc(6px + var(--safe-top));
            gap: 20px; position: relative; z-index: 110;
            box-shadow: 0 2px 8px -2px rgba(220, 38, 38, 0.45);
        }
        .official-header-left, .official-header-right-group {
            display: flex; align-items: center; gap: 12px;
            color: #fecdd3; font-size: 11px; font-weight: 600;
        }
        .official-header-badge {
            background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.3);
            padding: 2px 10px; border-radius: 20px;
            display: flex; align-items: center; gap: 6px; color: #ffffff;
        }
        .oh-title { display: flex; align-items: center; gap: 8px; }
        .oh-sub { font-weight: 400; opacity: 0.9; }

        @media (max-width: 992px) { .oh-city { display: none; } }
        @media (max-width: 768px) {
            .official-header {
                display: grid; grid-template-columns: minmax(0, 1fr) auto;
                grid-template-areas: "title badge" "sub sub";
                gap: 3px 8px; padding: 6px 12px; align-items: center;
            }
            .official-header-left, .official-header-right-group { display: contents; }
            .oh-title { grid-area: title; min-width: 0; gap: 6px; font-size: 10px; white-space: nowrap; }
            .oh-title span { overflow: hidden; text-overflow: ellipsis; }
            .official-header-badge { grid-area: badge; font-size: 9px; padding: 2px 8px; gap: 4px; white-space: nowrap; }
            .oh-sep, .oh-city, .oh-portal { display: none; }
            .oh-sub { grid-area: sub; font-size: 9px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        }

        /* STICKY HEADER */
        .sticky-header { position: sticky; top: 0; z-index: 100; background: #ffffff; }
        .top-navbar {
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 18px; background: #ffffff;
            border-bottom: 1px solid var(--card-border);
            gap: 14px; flex-wrap: nowrap; min-height: 62px;
        }
        .navbar-left { display: flex; align-items: center; gap: 14px; flex-shrink: 0; }
        .nav-brand { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .brand-logo { position: relative; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .brand-logo-ring {
            position: absolute; inset: -2px; border-radius: 50%;
            border: 2px dashed rgba(220, 38, 38, 0.55);
            animation: spinRing 4s linear infinite;
        }
        .brand-logo-circle {
            width: 36px; height: 36px; border-radius: 50%;
            background: linear-gradient(135deg, #dc2626, #991b1b);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-weight: 800; font-size: 13px; z-index: 2;
            box-shadow: 0 4px 10px rgba(220, 38, 38, 0.35);
        }
        .brand-text h2 { font-size: 14px; font-weight: 800; color: var(--accent-blue-dark); line-height: 1.1; margin: 0; font-family: 'Space Grotesk', sans-serif; letter-spacing: -0.3px; }
        .brand-text span { font-size: 9px; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 1.3px; }

        .menu-toggle {
            display: none; width: 42px; height: 42px; border-radius: 10px;
            align-items: center; justify-content: center;
            background: #f8fafc; border: 1px solid var(--card-border);
            color: var(--accent-blue-dark); cursor: pointer; font-size: 16px; flex-shrink: 0;
        }

        /* NAV MENU */
        .main-nav-bar {
            display: flex; align-items: center;
            background: transparent; border: none; padding: 0;
            position: relative; flex-shrink: 0; z-index: 45;
        }
        .nav-menu { list-style: none; margin: 0; padding: 0; display: flex; align-items: center; gap: 2px; flex-wrap: nowrap; }
        .nav-item { position: relative; }
        .nav-link {
            display: flex; align-items: center; gap: 6px;
            padding: 10px 12px; color: #334155; text-decoration: none;
            font-size: 12.5px; font-weight: 600; border-radius: 8px;
            transition: all 0.15s ease; cursor: pointer;
            white-space: nowrap; background: transparent;
            border: none; font-family: inherit;
        }
        .nav-link i.nav-ico { color: var(--accent-blue-dark); font-size: 12px; width: 14px; text-align: center; }
        .nav-link .chevron { font-size: 8px; margin-left: 1px; transition: transform 0.2s ease; opacity: 0.7; }
        .nav-link:hover { color: var(--accent-blue-dark); background: var(--blue-soft); }
        .nav-link.active { color: var(--accent-blue-dark); background: var(--blue-soft); font-weight: 700; }
        .nav-item.open > .nav-link { color: var(--accent-blue-dark); background: var(--blue-soft); }
        .nav-item.open > .nav-link .chevron { transform: rotate(180deg); }

        .nav-dropdown {
            position: absolute; top: calc(100% + 6px); left: 50%;
            transform: translateX(-50%) translateY(8px);
            min-width: 250px; background: #ffffff;
            border: 1px solid var(--card-border); border-radius: 12px;
            box-shadow: 0 12px 30px rgba(220, 38, 38, 0.15);
            padding: 8px; opacity: 0; visibility: hidden;
            transition: opacity 0.18s ease, transform 0.18s ease, visibility 0.18s;
            z-index: 200;
        }
        .nav-item.open .nav-dropdown { opacity: 1; visibility: visible; transform: translateX(-50%) translateY(0); }
        .nav-item.align-right .nav-dropdown { left: auto; right: 0; transform: translateX(0) translateY(8px); }
        .nav-item.align-right.open .nav-dropdown { transform: translateX(0) translateY(0); }
        .nav-dropdown a {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 12px; border-radius: 8px;
            color: #334155; text-decoration: none; font-size: 13px; font-weight: 500;
        }
        .nav-dropdown a:hover { background: var(--blue-soft); color: var(--accent-blue-dark); }
        .nav-dropdown a i { width: 16px; color: var(--accent-blue-dark); font-size: 14px; text-align: center; flex-shrink: 0; }

        .mobile-drawer-header { display: none; }

        /* SEARCH */
        .search-wrapper {
            flex: 1 1 auto; display: flex; justify-content: center;
            min-width: 140px; max-width: 380px; padding: 0 8px; margin: 0 auto;
        }
        .search-container { position: relative; width: 100%; cursor: pointer; }
        .search-container i { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: var(--accent-blue-dark); z-index: 2; font-size: 13px; }
        .search-container input {
            width: 100%; padding: 8px 14px 8px 38px;
            border-radius: 999px; border: 1px solid var(--card-border);
            background: #f8fafc; color: #0f172a; font-size: 13px; outline: none; cursor: pointer;
        }

        /* RIGHT CONTROLS */
        .navbar-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .nav-clock {
            display: flex; flex-direction: column; align-items: flex-end;
            padding: 3px 14px; border-radius: 10px;
            background: var(--blue-soft); border: 1px solid var(--card-border);
        }
        .nav-clock .time { font-family: 'Space Grotesk', monospace; font-size: 13px; font-weight: 700; color: var(--accent-blue-dark); }
        .nav-clock .date { font-size: 10px; color: #64748b; font-weight: 600; text-transform: uppercase; }

        .nav-icon {
            width: 38px; height: 38px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            background: #ffffff; border: 1px solid var(--card-border);
            color: var(--accent-blue-dark); cursor: pointer;
            position: relative; flex-shrink: 0; text-decoration: none;
        }
        .nav-icon .badge {
            position: absolute; top: -4px; right: -4px;
            background: var(--accent-blue); color: #fff;
            font-size: 9px; font-weight: 700;
            min-width: 18px; height: 18px; padding: 0 4px;
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            border: 2px solid #ffffff;
        }

        .profile-wrapper { position: relative; }
        .profile-btn {
            display: flex; align-items: center; gap: 10px;
            padding: 3px 12px 3px 3px;
            border-radius: 12px; background: #ffffff;
            border: 1px solid var(--card-border); cursor: pointer;
        }
        .profile-btn .avatar {
            width: 32px; height: 32px; border-radius: 50%;
            background: var(--accent-blue-dark);
            display: flex; align-items: center; justify-content: center;
            color: #ffffff; font-weight: 800; font-size: 13px;
        }
        .profile-btn .info .name { font-size: 12px; font-weight: 600; color: #0f172a; }
        .profile-btn .info .role { font-size: 9px; color: var(--accent-blue-dark); font-weight: 700; text-transform: uppercase; }

        .profile-dropdown, .notification-dropdown {
            position: absolute; top: calc(100% + 12px); right: 0;
            background: #ffffff; border: 1px solid var(--card-border);
            border-radius: 14px; padding: 8px;
            box-shadow: 0 16px 36px rgba(220, 38, 38, 0.15);
            opacity: 0; visibility: hidden;
            transform: translateY(-10px) scale(0.95);
            transition: all 0.25s ease; z-index: 60;
        }
        .profile-dropdown { width: 240px; }
        .notification-dropdown { width: 360px; padding: 0; }
        .profile-dropdown.show, .notification-dropdown.show { opacity: 1; visibility: visible; transform: translateY(0) scale(1); }
        .profile-dropdown .dropdown-item {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 14px; border-radius: 10px;
            color: #334155; text-decoration: none; font-size: 13px;
        }
        .profile-dropdown .dropdown-item:hover { background: var(--blue-soft); color: var(--accent-blue-dark); }
        .profile-dropdown .dropdown-item.danger { color: var(--danger); }
        .profile-dropdown .dropdown-item.danger:hover { background: var(--danger-bg); }

        .notification-title { padding: 14px 20px; font-weight: 700; font-size: 14px; border-bottom: 1px solid var(--blue-soft); color: #0f172a; }

        /* CONTENT */
        .content { flex: 1; padding: 30px 24px 40px; padding-bottom: calc(40px + var(--safe-bottom)); background: var(--bg-light); }

        .page-header {
            display: flex; justify-content: space-between; align-items: flex-end;
            margin-bottom: 24px; gap: 16px; flex-wrap: wrap;
        }
        .page-header h1 {
            font-family: 'Space Grotesk', sans-serif; font-size: 28px; font-weight: 800;
            color: var(--text-primary); margin: 0; letter-spacing: -0.5px;
            display: flex; align-items: center; gap: 12px;
        }
        .page-header h1 i { color: var(--accent-blue-dark); }
        .page-header p { color: var(--text-muted); font-size: 14px; margin-top: 6px; margin-bottom: 0; }
        .btn-back {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 20px; border-radius: 10px; border: 1px solid var(--card-border);
            background: #ffffff; color: var(--text-secondary); font-weight: 600; font-size: 13px; text-decoration: none;
            transition: var(--transition); box-shadow: 0 2px 6px rgba(0,0,0,0.02);
        }
        .btn-back:hover { background: var(--blue-soft); color: var(--accent-blue-dark); border-color: var(--accent-blue-light); transform: translateY(-1px); }

        /* STEPPER */
        .stepper-container {
            display: flex; justify-content: space-between; align-items: center;
            background: #ffffff; border: 1px solid var(--card-border);
            border-radius: 16px; padding: 16px 24px; margin-bottom: 24px;
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.03);
            overflow-x: auto;
        }
        .step-item { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .step-icon {
            width: 32px; height: 32px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 13px; font-weight: 700;
            background: var(--blue-soft); color: var(--accent-blue-dark);
            border: 2px solid transparent; transition: var(--transition);
        }
        .step-item.active .step-icon { background: var(--accent-blue); color: #fff; border-color: var(--accent-blue-light); box-shadow: 0 0 0 4px rgba(220,38,38,0.15); }
        .step-item.completed .step-icon { background: var(--ok); color: #fff; }
        .step-text { display: flex; flex-direction: column; }
        .step-title { font-size: 13px; font-weight: 700; color: var(--text-primary); }
        .step-desc { font-size: 11px; color: var(--text-muted); }
        .step-line { flex: 1; height: 2px; background: var(--card-border); margin: 0 16px; min-width: 30px; }
        .step-line.completed { background: var(--ok); }
        @media (max-width: 768px) {
            .stepper-container { padding: 12px; gap: 8px; justify-content: flex-start; }
            .step-item { flex-direction: column; text-align: center; gap: 6px; }
            .step-text { align-items: center; }
            .step-line { min-width: 20px; margin: 0 8px; margin-top: 15px; }
        }

        /* FORM PANEL */
        .panel {
            background: var(--bg-card); border: 1px solid var(--card-border);
            border-radius: 20px; overflow: hidden;
            box-shadow: 0 10px 30px -5px rgba(220, 38, 38, 0.08); margin-bottom: 20px;
        }
        .panel-header {
            padding: 20px 24px; background: #ffffff; border-bottom: 1px solid var(--card-border);
            font-family: 'Space Grotesk', sans-serif; font-size: 16px; font-weight: 800; color: #0f172a;
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
        }
        .panel-header i { color: var(--accent-blue-dark); margin-right: 8px; }
        .panel-body { padding: 30px 24px; }

        .section-divider { margin: 32px 0 20px 0; padding-bottom: 10px; border-bottom: 2px solid var(--blue-soft); display: flex; align-items: center; gap: 10px; }
        .section-divider:first-child { margin-top: 0; }
        .section-divider .title {
            font-family: 'Space Grotesk', sans-serif; font-size: 15px; font-weight: 700; color: var(--accent-blue-dark);
            display: flex; align-items: center; gap: 8px;
        }
        .section-divider .title i { background: var(--blue-soft); padding: 8px; border-radius: 8px; font-size: 14px; }

        /* FLOATING LABEL FORM */
        .form-group { position: relative; margin-bottom: 20px; }
        .form-group label { display: block; font-size: 12px; font-weight: 700; color: #475569; margin-bottom: 6px; }
        .form-group label .req { color: var(--danger); }
        .form-control, .form-select {
            width: 100%; border: 1.5px solid var(--card-border); border-radius: 10px;
            padding: 12px 16px; font-size: 14px; outline: none; background: #f8fafc;
            transition: var(--transition); color: var(--text-primary);
        }
        .form-control:focus, .form-select:focus { border-color: var(--accent-blue); background: #fff; box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.1); }
        .form-control::placeholder { color: var(--text-faint); font-weight: 400; }
        .form-text { font-size: 11.5px; color: var(--text-muted); margin-top: 4px; display: block; }

        .btn-primary-custom {
            background: linear-gradient(135deg, var(--accent-blue), var(--accent-blue-dark));
            color: #fff; border: none; padding: 14px 24px; border-radius: 12px;
            font-weight: 700; font-size: 14px; cursor: pointer; transition: var(--transition);
            display: inline-flex; align-items: center; gap: 8px; justify-content: center; text-decoration: none;
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
        }
        .btn-primary-custom:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(220, 38, 38, 0.4); color: #fff; }
        .btn-secondary-custom {
            background: #ffffff; border: 1.5px solid var(--card-border); color: var(--text-secondary); padding: 14px 24px;
            border-radius: 12px; font-size: 14px; font-weight: 700; text-decoration: none;
            display: inline-flex; align-items: center; gap: 8px; justify-content: center; transition: var(--transition);
        }
        .btn-secondary-custom:hover { background: var(--blue-soft); color: var(--accent-blue-dark); border-color: var(--accent-blue-light); }

        .dashboard-footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid var(--card-border); display: flex; justify-content: space-between; font-size: 12px; color: var(--text-muted); flex-wrap: wrap; gap: 10px; }

        /* SEARCH MODAL */
        .search-modal-overlay {
            position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(153, 27, 27, 0.4); backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px); z-index: 9999;
            display: flex; align-items: flex-start; justify-content: center; padding-top: 10vh;
            opacity: 0; visibility: hidden; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .search-modal-overlay.active { opacity: 1; visibility: visible; }
        .search-modal-content {
            width: 90%; max-width: 650px; background: #ffffff;
            border: 1px solid var(--card-border); border-radius: 20px;
            box-shadow: 0 25px 50px rgba(220, 38, 38, 0.2);
            overflow: hidden; transform: translateY(-20px) scale(0.98); transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .search-modal-overlay.active .search-modal-content { transform: translateY(0) scale(1); }
        .search-modal-header {
            display: flex; align-items: center; padding: 16px 20px;
            border-bottom: 1px solid var(--card-border); background: #f8fafc;
        }
        .search-modal-header i.search-icon-main { font-size: 16px; color: var(--accent-blue-dark); margin-right: 14px; }
        .search-modal-input { flex: 1; background: transparent; border: none; outline: none; color: #0f172a; font-size: 15px; font-weight: 600; }
        .search-modal-close {
            background: #f1f5f9; border: 1px solid #cbd5e1; color: #64748b;
            width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: all 0.2s ease;
        }
        .search-modal-close:hover { background: var(--danger-bg); color: var(--danger); border-color: #fca5a5; }
        .search-modal-body { padding: 20px; max-height: 380px; overflow-y: auto; }
        .search-shortcut-hint {
            display: flex; align-items: center; justify-content: space-between; padding: 12px 20px;
            background: #f8fafc; border-top: 1px solid var(--card-border); font-size: 11px; color: var(--text-muted);
        }
        .search-shortcut-hint kbd {
            background: var(--blue-soft); border: 1px solid var(--card-border); padding: 2px 6px; border-radius: 4px; color: var(--accent-blue-dark); font-family: inherit; font-weight: 700;
        }

        /* RESPONSIVE */
        @media (max-width: 1400px) {
            .nav-link { padding: 10px 8px; font-size: 12px; }
        }
        @media (max-width: 1200px) {
            .top-navbar { padding: 10px 14px; padding-top: calc(10px + var(--safe-top)); gap: 10px; flex-wrap: wrap; min-height: 60px; }
            .nav-clock { display: none; }
            .profile-btn .info { display: none; }
            .profile-btn { padding: 3px; }
            .menu-toggle { display: flex; }
            .search-wrapper { order: 5; width: 100%; padding: 0; margin: 4px 0; max-width: 100%; flex-basis: 100%; }

            .main-nav-bar {
                position: fixed; top: 0; left: -100%;
                width: 85vw; max-width: 320px;
                height: 100vh; height: 100dvh;
                flex-direction: column; align-items: stretch;
                justify-content: flex-start; padding: 0; z-index: 2000;
                overflow-y: auto; -webkit-overflow-scrolling: touch;
                transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                background: #ffffff;
                box-shadow: 6px 0 25px rgba(0,0,0,0.18);
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
                background: #ffffff; color: var(--accent-blue-dark);
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
            .main-nav-bar .nav-menu {
                flex-direction: column; width: 100%;
                justify-content: flex-start; gap: 4px;
                padding: 14px; padding-bottom: calc(20px + var(--safe-bottom));
            }
            .nav-item { width: 100%; }
            .nav-link { padding: 14px 16px; border-radius: 10px; justify-content: flex-start; font-size: 14.5px; min-height: 48px; }
            .nav-link .chevron { margin-left: auto; font-size: 11px; }
            .nav-link .nav-ico { font-size: 15px; width: 18px; }

            .nav-dropdown,
            .nav-item.open .nav-dropdown,
            .nav-item.align-right .nav-dropdown,
            .nav-item.align-right.open .nav-dropdown {
                position: static; left: auto; right: auto;
                transform: none; opacity: 1; visibility: visible;
                box-shadow: none; border: none;
                max-height: 0; overflow: hidden;
                transition: max-height 0.3s ease, padding 0.2s ease;
                padding: 0 0 0 16px;
                background: #f8fafc; margin-top: 4px; border-radius: 10px; min-width: 0;
            }
            .nav-item.open .nav-dropdown { max-height: 600px; padding: 8px 0 8px 16px; }
            .nav-dropdown a { padding: 12px 14px; min-height: 44px; font-size: 13.5px; }

            .sidebar-overlay {
                display: none; position: fixed; inset: 0;
                background: rgba(0,0,0,0.55);
                z-index: 1999;
                backdrop-filter: blur(3px); -webkit-backdrop-filter: blur(3px);
                opacity: 0; transition: opacity 0.3s ease;
            }
            .sidebar-overlay.active { display: block; opacity: 1; }
            .notification-dropdown { width: calc(100vw - 24px); max-width: 400px; right: -50px; }
        }

        @media (max-width: 768px) {
            .content { padding: 20px 16px 24px; padding-bottom: calc(24px + var(--safe-bottom)); }
            .page-header { flex-direction: column; align-items: flex-start; gap: 12px; }
            .page-header h1 { font-size: 22px; }
            .panel-body { padding: 20px 16px; }
            .dashboard-footer { flex-direction: column; text-align: center; gap: 6px; font-size: 11px; }
            .section-divider { margin: 24px 0 16px 0; }
        }
        @media (max-width: 480px) {
            .content { padding: 16px 12px 20px; padding-bottom: calc(20px + var(--safe-bottom)); }
        }
        @media (max-width: 767px) {
            input, select, textarea { font-size: 16px !important; }
        }
    </style>
</head>
<body>

    <!-- TOP OFFICIAL BAR -->
    <div class="official-header">
        <div class="official-header-left">
            <div class="oh-title">
                <i class="fa-solid fa-landmark"></i>
                <span>REPUBLIC OF THE PHILIPPINES</span>
            </div>
            <span class="oh-sep" style="opacity: 0.7;">|</span>
            <span class="oh-sub">Local Government Unit Property &amp; Rental Compliance Portal</span>
        </div>
        <div class="official-header-right-group">
            <div class="official-header-badge">
                <i class="fa-solid fa-certificate"></i>
                <span>LGU CERTIFIED <span class="oh-portal">PORTAL </span>V2.1</span>
            </div>
            <span class="oh-city">City Treasurer &amp; Rentrack Online System</span>
        </div>
    </div>

    <div class="main-wrapper">
        <!-- STICKY HEADER -->
        <div class="sticky-header">
            <header class="top-navbar">
                <div class="navbar-left">
                    <button class="menu-toggle" id="menuToggle" aria-label="Toggle Menu"><i class="fa-solid fa-bars"></i></button>
                    <div class="nav-brand">
                        <div class="brand-logo">
                            <div class="brand-logo-ring"></div>
                            <div class="brand-logo-circle">RT</div>
                        </div>
                        <div class="brand-text">
                            <h2>RENTRACK</h2>
                            <span>Owner Portal</span>
                        </div>
                    </div>
                </div>

                <!-- NAV MENU -->
                <nav class="main-nav-bar" id="mainNavBar">
                    <div class="mobile-drawer-header">
                        <div class="drawer-brand">
                            <div class="drawer-brand-logo">RT</div>
                            <div class="drawer-brand-text">
                                <h2>RENTRACK</h2>
                                <span>Owner Portal</span>
                            </div>
                        </div>
                        <button type="button" class="drawer-close-btn" id="drawerCloseBtn"><i class="fa-solid fa-xmark"></i></button>
                    </div>

                    <ul class="nav-menu">
                        <li class="nav-item">
                            <a href="dashboard.php" class="nav-link"><i class="fa-solid fa-gauge-high nav-ico"></i> Dashboard</a>
                        </li>

                        <li class="nav-item has-dropdown">
                            <button type="button" class="nav-link active" data-dropdown>
                                <i class="fa-solid fa-building nav-ico"></i> Rentals
                                <i class="fa-solid fa-chevron-down chevron"></i>
                            </button>
                            <div class="nav-dropdown">
                                <a href="rentals.php"><i class="fa-solid fa-building"></i> My Rentals</a>
                                <a href="register_rental.php"><i class="fa-solid fa-circle-plus"></i> Register Rental</a>
                                <a href="rental_requests.php"><i class="fa-solid fa-file-circle-check"></i> Rental Requests</a>
                                <a href="schedule.php"><i class="fa-solid fa-calendar-days"></i> Rental Schedule</a>
                            </div>
                        </li>

                        <li class="nav-item has-dropdown">
                            <button type="button" class="nav-link" data-dropdown>
                                <i class="fa-solid fa-clipboard-check nav-ico"></i> Operations
                                <i class="fa-solid fa-chevron-down chevron"></i>
                            </button>
                            <div class="nav-dropdown">
                                <a href="inspections.php"><i class="fa-solid fa-clipboard-check"></i> Inspections</a>
                                <a href="inspection_schedule.php"><i class="fa-solid fa-calendar-check"></i> Inspection Schedule</a>
                                <a href="maintenance.php"><i class="fa-solid fa-screwdriver-wrench"></i> Maintenance</a>
                            </div>
                        </li>

                        <li class="nav-item has-dropdown">
                            <button type="button" class="nav-link" data-dropdown>
                                <i class="fa-solid fa-file-lines nav-ico"></i> Documents
                                <i class="fa-solid fa-chevron-down chevron"></i>
                            </button>
                            <div class="nav-dropdown">
                                <a href="documents.php"><i class="fa-solid fa-file-lines"></i> Documents</a>
                                <a href="renew_documents.php"><i class="fa-solid fa-file-arrow-up"></i> Online Renewal</a>
                                <a href="compliance.php"><i class="fa-solid fa-file-shield"></i> Compliance</a>
                            </div>
                        </li>

                        <li class="nav-item has-dropdown align-right">
                            <button type="button" class="nav-link" data-dropdown>
                                <i class="fa-solid fa-money-check-dollar nav-ico"></i> Financials
                                <i class="fa-solid fa-chevron-down chevron"></i>
                            </button>
                            <div class="nav-dropdown">
                                <a href="payments.php"><i class="fa-solid fa-cash-register"></i> Payments</a>
                                <a href="reports.php"><i class="fa-solid fa-chart-simple"></i> Reports</a>
                            </div>
                        </li>
                    </ul>
                </nav>

                <div class="search-wrapper">
                    <div class="search-container" onclick="openSearchModal()">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" placeholder="Search tracking no, business name..." readonly style="cursor: pointer;">
                    </div>
                </div>

                <div class="navbar-right">
                    <div class="nav-clock">
                        <div class="time" id="liveTime"><?php echo date('h:i:s A'); ?></div>
                        <div class="date"><?php echo date('M d, Y'); ?></div>
                    </div>

                    <div class="nav-icon-wrapper" style="position:relative;">
                        <button class="nav-icon" id="notifBtn" aria-label="Notifications">
                            <i class="fa-regular fa-bell"></i>
                            <?php if ($unreadNotifCount > 0): ?>
                                <span class="badge" id="notifBadgeCount"><?php echo $unreadNotifCount; ?></span>
                            <?php endif; ?>
                        </button>
                        <div id="notifDropdown" class="notification-dropdown">
                            <div class="notification-title" style="display:flex; justify-content:space-between; align-items:center; gap:8px;">
                                <span>Notifications</span>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <?php if ($unreadNotifCount > 0): ?>
                                        <span id="notifHeaderCount" style="font-size:10px; background:var(--blue-soft); color:var(--accent-blue-dark); padding:2px 8px; border-radius:10px;"><?php echo $unreadNotifCount; ?> New</span>
                                    <?php endif; ?>
                                    <button type="button" id="notifClearBtn" style="background:transparent; border:none; color:var(--accent-blue-dark); font-size:11px; font-weight:700; cursor:pointer; padding:2px 4px;">Clear</button>
                                </div>
                            </div>
                            <div style="max-height:280px; overflow-y:auto;" id="notifListBody">
                                <?php if (!empty($notifications)): ?>
                                    <?php foreach ($notifications as $notification): ?>
                                        <?php
                                            $notifLink = 'rentals.php';
                                            if (!empty($notification['link'])) {
                                                $notifLink = $notification['link'];
                                            } else {
                                                $titleLower = strtolower($notification['title'] ?? '');
                                                $msgLower = strtolower($notification['message'] ?? '');
                                                if (strpos($titleLower, 'rental') !== false || strpos($msgLower, 'rental') !== false) $notifLink = 'rentals.php';
                                                elseif (strpos($titleLower, 'inspection') !== false || strpos($msgLower, 'inspection') !== false) $notifLink = 'inspections.php';
                                                elseif (strpos($titleLower, 'payment') !== false || strpos($msgLower, 'payment') !== false) $notifLink = 'payments.php';
                                                elseif (strpos($titleLower, 'compliance') !== false || strpos($msgLower, 'permit') !== false) $notifLink = 'compliance.php';
                                            }
                                            $isUnread = (!isset($notification['is_read']) || $notification['is_read'] === false || $notification['is_read'] === '0' || $notification['is_read'] === 0);
                                        ?>
                                        <a href="javascript:void(0)" class="notif-drop-item" onclick="markNotificationReadAndRedirect(<?php echo (int)$notification['id']; ?>, '<?php echo htmlspecialchars($notifLink); ?>', this)" style="display:block; padding:10px 14px; border-bottom:1px solid #f1f5f9; text-decoration:none; color:var(--text-secondary); background:<?php echo $isUnread ? '#fef2f2' : '#fff'; ?>;">
                                            <div style="display:flex; justify-content:space-between; align-items:center;">
                                                <strong style="font-size:12px; display:block; color:#0f172a;"><?php echo displayField($notification, ['title']); ?></strong>
                                                <?php if ($isUnread): ?><span class="unread-dot" style="width:8px; height:8px; background:var(--accent-blue); border-radius:50%; display:inline-block;"></span><?php endif; ?>
                                            </div>
                                            <span style="font-size:11px; color:#64748b; display:block; margin-top:2px;"><?php echo displayField($notification, ['message','description']); ?></span>
                                            <span style="font-size:9.5px; color:#94a3b8; display:block; margin-top:4px;"><?php echo !empty($notification['created_at']) ? date('M d, Y h:i A', strtotime($notification['created_at'])) : ''; ?></span>
                                        </a>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div style="padding:20px; text-align:center; color:#94a3b8; font-size:12px;">No notifications found</div>
                                <?php endif; ?>
                            </div>
                            <div style="padding:8px; text-align:center; border-top:1px solid var(--card-border); background:#f8fafc;">
                                <a href="notifications.php" style="font-size:11px; font-weight:700; color:var(--accent-blue-dark); text-decoration:none;">View All Notifications</a>
                            </div>
                        </div>
                    </div>

                    <div class="profile-wrapper">
                        <div class="profile-btn" id="profileToggle">
                            <div class="avatar"><?php echo htmlspecialchars($userInitial); ?></div>
                            <div class="info">
                                <div class="name"><?php echo htmlspecialchars($userName); ?></div>
                                <div class="role">OWNER</div>
                            </div>
                            <i class="fa-solid fa-chevron-down chevron" style="color:#64748b; font-size:10px;"></i>
                        </div>

                        <div class="profile-dropdown" id="profileDropdown">
                            <a href="profile.php" class="dropdown-item"><i class="fa-solid fa-user"></i> My Profile</a>
                            <a href="settings.php" class="dropdown-item"><i class="fa-solid fa-gear"></i> Settings</a>
                            <div style="height:1px; background:var(--blue-soft); margin:4px 0;"></div>
                            <a href="javascript:void(0)" class="dropdown-item danger" id="profileLogoutBtn"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
                        </div>
                    </div>
                </div>
            </header>
        </div>

        <main class="content">
            <!-- PAGE HEADER -->
            <div class="page-header">
                <div>
                    <h1><i class="fa-solid fa-circle-plus"></i> Register Rental</h1>
                    <p>Create a new rental record for LGU registration and compliance tracking.</p>
                </div>
                <div>
                    <a href="rentals.php" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Back to Rentals</a>
                </div>
            </div>

            <?php if ($flashSuccess): ?>
                <script>
                    document.addEventListener("DOMContentLoaded", function() {
                        Swal.fire({ icon: 'success', title: <?php echo json_encode($flashSuccess); ?>, timer: 3000, showConfirmButton: false, toast: true, position: 'top-end' });
                    });
                </script>
            <?php endif; ?>
            <?php if ($flashError): ?>
                <script>
                    document.addEventListener("DOMContentLoaded", function() {
                        Swal.fire({ icon: 'error', title: <?php echo json_encode($flashError); ?>, timer: 4000, showConfirmButton: false, toast: true, position: 'top-end' });
                    });
                </script>
            <?php endif; ?>

            <!-- STEPPER -->
            <div class="stepper-container">
                <div class="step-item active">
                    <div class="step-icon">1</div>
                    <div class="step-text">
                        <span class="step-title">Property Details</span>
                        <span class="step-desc">Business & Location</span>
                    </div>
                </div>
                <div class="step-line"></div>
                <div class="step-item">
                    <div class="step-icon">2</div>
                    <div class="step-text">
                        <span class="step-title">Compliance</span>
                        <span class="step-desc">Permits & Financials</span>
                    </div>
                </div>
                <div class="step-line"></div>
                <div class="step-item">
                    <div class="step-icon">3</div>
                    <div class="step-text">
                        <span class="step-title">Review & Submit</span>
                        <span class="step-desc">Finalize Registration</span>
                    </div>
                </div>
            </div>

            <!-- FORM PANEL -->
            <div class="panel">
                <div class="panel-header">
                    <span><i class="fa-solid fa-file-circle-plus"></i> Rental Registration Form</span>
                </div>
                <div class="panel-body">
                    <form action="save_rental.php" method="POST" id="registerRentalForm" novalidate>
                        <input type="hidden" name="id" value="0">

                        <div class="section-divider"><div class="title"><i class="fa-solid fa-building"></i> Business &amp; Property Information</div></div>

                        <div class="row g-4">
                            <div class="col-md-6 form-group">
                                <label><i class="fa-solid fa-layer-group me-1"></i> Rental Type <span class="req">*</span></label>
                                <select name="rental_type_id" class="form-select" required>
                                    <option value="" disabled selected>-- Select Type --</option>
                                    <?php foreach ($types as $t): ?>
                                        <option value="<?php echo (int)$t['id']; ?>"><?php echo h($t['type_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6 form-group">
                                <label><i class="fa-solid fa-store me-1"></i> Rental Establishment / Asset Name <span class="req">*</span></label>
                                <input type="text" name="rental_name" class="form-control" placeholder="e.g. Sunrise Apartments / Equipment Rental" required>
                            </div>

                            <div class="col-md-6 form-group">
                                <label><i class="fa-solid fa-briefcase me-1"></i> Business Name <span class="req">*</span></label>
                                <input type="text" name="business_name" class="form-control" placeholder="e.g. Dela Cruz Trading" required>
                            </div>

                            <div class="col-md-6 form-group">
                                <label><i class="fa-solid fa-map-pin me-1"></i> Barangay <span class="req">*</span></label>
                                <input type="text" name="barangay" class="form-control" placeholder="e.g. Poblacion" required>
                            </div>

                            <div class="col-12 form-group">
                                <label><i class="fa-solid fa-location-dot me-1"></i> Business Address <span class="req">*</span></label>
                                <textarea name="business_address" class="form-control" rows="2" placeholder="Complete street address or location" required></textarea>
                            </div>
                        </div>

                        <div class="section-divider"><div class="title"><i class="fa-solid fa-address-book"></i> Contact Information</div></div>

                        <div class="row g-4">
                            <div class="col-md-6 form-group">
                                <label><i class="fa-solid fa-phone me-1"></i> Contact Number <span class="req">*</span></label>
                                <input type="tel" name="contact_number" class="form-control" placeholder="09XXXXXXXXX" pattern="[0-9+\-\s]{7,15}" required>
                            </div>

                            <div class="col-md-6 form-group">
                                <label><i class="fa-solid fa-envelope me-1"></i> Email Address</label>
                                <input type="email" name="email" class="form-control" placeholder="owner@example.com">
                                <span class="form-text">Optional — used for automated permit renewal notifications.</span>
                            </div>
                        </div>

                        <div class="section-divider"><div class="title"><i class="fa-solid fa-file-shield"></i> LGU Compliance &amp; Permits</div></div>

                        <div class="row g-4">
                            <div class="col-md-6 form-group">
                                <label><i class="fa-solid fa-file-invoice me-1"></i> Business Permit No.</label>
                                <input type="text" name="business_permit_no" class="form-control" placeholder="e.g. BP-2026-0001 (Leave blank if new)">
                            </div>
                        </div>

                        <div class="section-divider"><div class="title"><i class="fa-solid fa-coins"></i> Financial &amp; Physical Specifications</div></div>

                        <div class="row g-4">
                            <div class="col-md-6 form-group">
                                <label><i class="fa-solid fa-peso-sign me-1"></i> Monthly Rate / Rental Fee (₱) <span class="req">*</span></label>
                                <input type="number" step="0.01" min="0" name="monthly_rate" class="form-control" placeholder="0.00" required>
                                <span class="form-text">Estimated or actual monthly rate.</span>
                            </div>

                            <div class="col-md-6 form-group">
                                <label><i class="fa-solid fa-ruler-combined me-1"></i> Floor / Land Area (sqm)</label>
                                <input type="number" step="0.01" min="0" name="floor_area" class="form-control" placeholder="0.00">
                                <span class="form-text">Optional for items/equipment.</span>
                            </div>
                        </div>

                        <div class="section-divider"><div class="title"><i class="fa-solid fa-calendar-days"></i> Registration &amp; Permit Expiry</div></div>

                        <div class="row g-4">
                            <div class="col-md-6 form-group">
                                <label><i class="fa-solid fa-calendar-check me-1"></i> Registration Date <span class="req">*</span></label>
                                <input type="date" name="registration_date" id="registrationDate" class="form-control" required>
                            </div>

                            <div class="col-md-6 form-group">
                                <label><i class="fa-solid fa-calendar-xmark me-1"></i> Mayor's Permit Expiry</label>
                                <input type="date" name="permit_expiry" id="permitExpiry" class="form-control">
                                <span class="form-text">Optional for new applications.</span>
                            </div>
                        </div>

                        <div class="section-divider"><div class="title"><i class="fa-solid fa-note-sticky"></i> Remarks</div></div>

                        <div class="row g-4">
                            <div class="col-12 form-group">
                                <label><i class="fa-solid fa-align-left me-1"></i> Description</label>
                                <textarea name="description" class="form-control" rows="3" placeholder="Additional details (optional)"></textarea>
                            </div>

                            <div class="col-12 d-flex gap-3 pt-3">
                                <a href="rentals.php" class="btn-secondary-custom flex-grow-1">Cancel</a>
                                <button type="submit" id="submitRentalBtn" class="btn-primary-custom flex-grow-1">
                                    <i class="fa-solid fa-check"></i> Submit Rental Registration
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <footer class="dashboard-footer">
                <span>&copy; <?php echo date('Y'); ?> <span style="color:var(--accent-blue-dark); font-weight:700;">RENTRACK LGU</span> &middot; Unified Rental System</span>
                <span>Owner Portal</span>
            </footer>
        </main>
    </div>

    <!-- SEARCH MODAL -->
    <div class="search-modal-overlay" id="searchModalOverlay" onclick="closeSearchModalOnOutside(event)">
        <div class="search-modal-content">
            <div class="search-modal-header">
                <i class="fa-solid fa-magnifying-glass search-icon-main"></i>
                <input type="text" class="search-modal-input" id="modalSearchInput" placeholder="Search by tracking number, business name, or property..." autocomplete="off">
                <div class="search-modal-close" onclick="closeSearchModal()"><i class="fa-solid fa-xmark"></i></div>
            </div>
            <div class="search-modal-body" id="modalSearchResults">
                <div style="text-align:center; padding:40px; color:#64748b; font-size:13px;">
                    <i class="fa-solid fa-keyboard" style="font-size:28px; margin-bottom:10px; display:block; opacity:0.5; color:var(--accent-blue-dark);"></i>
                    Type at least 2 characters to search records...
                </div>
            </div>
            <div class="search-shortcut-hint">
                <span>Quick search across all your property records</span>
                <span>Press <kbd>ESC</kbd> to close</span>
            </div>
        </div>
    </div>

    <!-- AI ASSISTANT -->
    <div id="aiAssistantWrapper" style="position:fixed; bottom:calc(25px + var(--safe-bottom)); right:calc(25px + var(--safe-right)); z-index:1060; font-family:'Plus Jakarta Sans', sans-serif;">
        <button id="aiToggleBtn" style="width:55px; height:55px; border-radius:50%; background:linear-gradient(135deg, #dc2626, #991b1b); border:none; color:#fff; font-size:22px; cursor:pointer; box-shadow:0 10px 25px rgba(220, 38, 38, 0.4); display:flex; align-items:center; justify-content:center;" title="Ask AI Assistant">
            <i class="fa-solid fa-robot"></i>
        </button>
        <div id="aiChatWindow" style="position:absolute; bottom:70px; right:0; width:350px; height:480px; background:#ffffff; border:1px solid var(--card-border); border-radius:18px; box-shadow:0 20px 40px rgba(220, 38, 38, 0.2); display:none; flex-direction:column; overflow:hidden;">
            <div style="background:linear-gradient(135deg, #dc2626, #991b1b); color:#fff; padding:14px 16px; display:flex; align-items:center; justify-content:space-between;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <i class="fa-solid fa-robot" style="font-size:16px; color:#fecdd3;"></i>
                    <div>
                        <strong style="font-size:14px; display:block; font-family:'Space Grotesk', sans-serif;">RENTRACK AI</strong>
                        <span style="font-size:10px; color:#fecdd3;">Owner LGU Assistant</span>
                    </div>
                </div>
                <button id="aiCloseBtn" style="background:transparent; border:none; color:#fff; font-size:16px; cursor:pointer;"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div id="aiChatBody" style="flex:1; padding:14px; overflow-y:auto; background:#f8fafc; display:flex; flex-direction:column; gap:10px; font-size:12.5px;">
                <div style="background:var(--blue-soft); color:var(--accent-blue-dark); padding:10px 12px; border-radius:12px; max-width:85%; align-self:flex-start; border-bottom-left-radius:2px;">
                    Hello <?php echo htmlspecialchars($userName); ?>! Ask me about your rentals, payments, permits, or inspections.
                </div>
            </div>
            <div style="padding:10px; background:#ffffff; border-top:1px solid var(--card-border); display:flex; gap:8px;">
                <input type="text" id="aiChatInput" placeholder="Ask e.g., 'How many rentals do I have?'..." style="flex:1; padding:8px 12px; border:1px solid var(--card-border); border-radius:999px; outline:none; font-size:12.5px; background:#f8fafc;">
                <button id="aiSendBtn" style="background:var(--accent-blue); border:none; color:#fff; width:36px; height:36px; border-radius:50%; cursor:pointer; display:flex; align-items:center; justify-content:center;"><i class="fa-solid fa-paper-plane" style="font-size:12px;"></i></button>
            </div>
        </div>
    </div>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
    /* NAV MENU */
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
            document.querySelectorAll('.nav-item.has-dropdown.open').forEach(i => i.classList.remove('open'));
        }
    });

    document.querySelectorAll('.nav-item.has-dropdown > .nav-link').forEach(function(trigger) {
        trigger.addEventListener('click', function(e) {
            e.preventDefault(); e.stopPropagation();
            const parent = trigger.parentElement;
            const wasOpen = parent.classList.contains('open');
            document.querySelectorAll('.nav-item.has-dropdown.open').forEach(i => { if (i !== parent) i.classList.remove('open'); });
            parent.classList.toggle('open', !wasOpen);
        });
    });

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.nav-item.has-dropdown')) {
            document.querySelectorAll('.nav-item.has-dropdown.open').forEach(i => i.classList.remove('open'));
        }
    });

    mainNavBar.querySelectorAll('.nav-dropdown a, .nav-link:not([data-dropdown])').forEach(function(link) {
        link.addEventListener('click', function() { if (window.innerWidth <= 1200) closeDrawer(); });
    });
    window.addEventListener('resize', function() { if (window.innerWidth > 1200) closeDrawer(); });

    /* CLOCK */
    function updateClock() {
        const t = document.getElementById('liveTime');
        if (!t) return;
        t.textContent = new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }
    setInterval(updateClock, 1000);
    updateClock();

    /* NOTIFICATIONS */
    document.getElementById('notifClearBtn')?.addEventListener('click', (e) => {
        e.stopPropagation();
        fetch('?ajax_action=clear_notifs')
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('notifBadgeCount')?.remove();
                    document.getElementById('notifHeaderCount')?.remove();
                    const lb = document.getElementById('notifListBody');
                    if (lb) lb.innerHTML = '<div style="padding:24px; text-align:center; color:#94a3b8; font-size:12px;"><i class="fa-solid fa-check-double" style="font-size:20px; margin-bottom:8px; display:block; color:var(--accent-blue-dark);"></i>You\'re all caught up!</div>';
                }
            });
    });

    function markNotificationReadAndRedirect(notifId, redirectUrl, element) {
        fetch('?ajax_action=mark_notif_read&id=' + notifId)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    element.querySelector('.unread-dot')?.remove();
                    element.style.background = '#fff';
                    const badge = document.getElementById('notifBadgeCount');
                    const headerCount = document.getElementById('notifHeaderCount');
                    if (badge) {
                        let c = parseInt(badge.textContent) - 1;
                        if (c > 0) { badge.textContent = c; if (headerCount) headerCount.textContent = c + ' New'; }
                        else { badge.remove(); headerCount?.remove(); }
                    }
                }
                window.location.href = redirectUrl;
            })
            .catch(() => { window.location.href = redirectUrl; });
    }

    /* DROPDOWNS */
    const notifBtn = document.getElementById('notifBtn');
    const notifDropdown = document.getElementById('notifDropdown');
    const profileToggle = document.getElementById('profileToggle');
    const profileDropdown = document.getElementById('profileDropdown');

    notifBtn?.addEventListener('click', (e) => { e.stopPropagation(); profileDropdown?.classList.remove('show'); notifDropdown.classList.toggle('show'); });
    profileToggle?.addEventListener('click', (e) => { e.stopPropagation(); notifDropdown?.classList.remove('show'); profileDropdown.classList.toggle('show'); });
    if (notifDropdown) notifDropdown.addEventListener('click', (e) => e.stopPropagation());
    document.addEventListener('click', () => { profileDropdown?.classList.remove('show'); notifDropdown?.classList.remove('show'); });

    /* SEARCH MODAL */
    const searchModalOverlay = document.getElementById('searchModalOverlay');
    const modalSearchInput = document.getElementById('modalSearchInput');
    const modalSearchResults = document.getElementById('modalSearchResults');

    function openSearchModal() { searchModalOverlay.classList.add('active'); setTimeout(() => modalSearchInput.focus(), 100); }
    function closeSearchModal() {
        searchModalOverlay.classList.remove('active');
        modalSearchInput.value = '';
        modalSearchResults.innerHTML = `<div style="text-align:center; padding:40px; color:#64748b; font-size:13px;"><i class="fa-solid fa-keyboard" style="font-size:28px; margin-bottom:10px; display:block; opacity:0.5; color:var(--accent-blue-dark);"></i>Type at least 2 characters to search records...</div>`;
    }
    function closeSearchModalOnOutside(event) { if (event.target === searchModalOverlay) closeSearchModal(); }

    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') { e.preventDefault(); openSearchModal(); }
        if (e.key === 'Escape') closeSearchModal();
    });

    let searchTimeout;
    modalSearchInput?.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        let query = this.value.trim();
        if (query.length < 2) {
            modalSearchResults.innerHTML = `<div style="text-align:center; padding:40px; color:#64748b; font-size:13px;"><i class="fa-solid fa-keyboard" style="font-size:28px; margin-bottom:10px; display:block; opacity:0.5; color:var(--accent-blue-dark);"></i>Type at least 2 characters to search records...</div>`;
            return;
        }
        modalSearchResults.innerHTML = `<div style="text-align:center; padding:30px; color:#64748b;"><i class="fa-solid fa-spinner fa-spin" style="font-size:20px; margin-bottom:8px; color:var(--accent-blue-dark);"></i><div>Searching records...</div></div>`;
        searchTimeout = setTimeout(() => {
            fetch('?ajax_action=search&q=' + encodeURIComponent(query))
                .then(res => res.text())
                .then(html => { modalSearchResults.innerHTML = html; });
        }, 300);
    });

    /* AI ASSISTANT */
    const aiToggleBtn = document.getElementById('aiToggleBtn');
    const aiCloseBtn = document.getElementById('aiCloseBtn');
    const aiChatWindow = document.getElementById('aiChatWindow');
    const aiSendBtn = document.getElementById('aiSendBtn');
    const aiChatInput = document.getElementById('aiChatInput');
    const aiChatBody = document.getElementById('aiChatBody');

    aiToggleBtn?.addEventListener('click', () => {
        const isVisible = aiChatWindow.style.display === 'flex';
        aiChatWindow.style.display = isVisible ? 'none' : 'flex';
        if (!isVisible) aiChatInput.focus();
    });
    aiCloseBtn?.addEventListener('click', () => { aiChatWindow.style.display = 'none'; });

    function sendAiMessage() {
        const text = aiChatInput.value.trim();
        if (!text) return;
        aiChatBody.innerHTML += `<div style="background:var(--accent-blue); color:#fff; padding:10px 12px; border-radius:12px; max-width:85%; align-self:flex-end; border-bottom-right-radius:2px;">${escapeHtml(text)}</div>`;
        aiChatInput.value = '';
        aiChatBody.scrollTop = aiChatBody.scrollHeight;
        const loadingId = 'ai-loading-' + Date.now();
        aiChatBody.innerHTML += `<div id="${loadingId}" style="background:var(--blue-soft); color:var(--accent-blue-dark); padding:10px 12px; border-radius:12px; max-width:85%; align-self:flex-start; border-bottom-left-radius:2px;"><i class="fa-solid fa-spinner fa-spin"></i> Thinking...</div>`;
        aiChatBody.scrollTop = aiChatBody.scrollHeight;

        fetch('?ajax_action=ai_chat&msg=' + encodeURIComponent(text))
            .then(res => res.json())
            .then(data => {
                document.getElementById(loadingId).remove();
                aiChatBody.innerHTML += `<div style="background:var(--blue-soft); color:var(--accent-blue-dark); padding:10px 12px; border-radius:12px; max-width:85%; align-self:flex-start; border-bottom-left-radius:2px;">${data.reply}</div>`;
                aiChatBody.scrollTop = aiChatBody.scrollHeight;
            })
            .catch(() => {
                document.getElementById(loadingId).remove();
                aiChatBody.innerHTML += `<div style="background:var(--danger-bg); color:var(--danger); padding:10px 12px; border-radius:12px; max-width:85%; align-self:flex-start; border-bottom-left-radius:2px;">Sorry, an error occurred.</div>`;
                aiChatBody.scrollTop = aiChatBody.scrollHeight;
            });
    }
    aiSendBtn?.addEventListener('click', sendAiMessage);
    aiChatInput?.addEventListener('keypress', (e) => { if (e.key === 'Enter') sendAiMessage(); });

    function escapeHtml(text) {
        const map = { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;' };
        return text.replace(/[&<>"']/g, m => map[m]);
    }

    /* DATE PICKER DEFAULT */
    (function () {
        const regDate = document.getElementById('registrationDate');
        if (regDate) {
            const today = new Date().toISOString().split('T')[0];
            regDate.value = today;
            regDate.min = today;
        }
    })();

    /* FORM SUBMIT - NO PAYMENT PROMPT */
    document.getElementById('registerRentalForm')?.addEventListener('submit', function (e) {
        const form = this;
        if (!form.checkValidity()) {
            e.preventDefault();
            form.reportValidity();
            Swal.fire({ 
                icon: 'warning', 
                title: 'Incomplete Form', 
                text: 'Please complete all required fields.', 
                confirmButtonColor: '#dc2626' 
            });
            return;
        }
        
        e.preventDefault();
        Swal.fire({
            title: 'Submitting Registration...',
            html: 'Please wait while we save your rental property.',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
        
        form.submit();
    });

    /* LOGOUT */
    function confirmLogout() {
        Swal.fire({
            title: 'Logout from RENTRACK?',
            text: 'Are you sure you want to log out?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, Logout',
            background: '#ffffff', color: '#0f172a'
        }).then((res) => { if (res.isConfirmed) window.location.href = '../logout.php'; });
    }
    document.getElementById('profileLogoutBtn')?.addEventListener('click', confirmLogout);
    </script>
</body>
</html>