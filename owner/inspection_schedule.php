<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();

if (!isset($_SESSION['regenerated'])) {
    session_regenerate_id(true);
    $_SESSION['regenerated'] = time();
}

require_once("../config/db.php");
require_once("includes/auth.php");

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

if ($userId === 0) {
    header('Location: login.php');
    exit;
}

$userName = isset($_SESSION['fullname']) && !empty(trim($_SESSION['fullname']))
    ? htmlspecialchars(trim($_SESSION['fullname']), ENT_QUOTES, 'UTF-8')
    : 'Owner';
$userInitial = strtoupper(substr(trim($userName), 0, 1));

/* Fallback kung wala pang safe_error() sa config/auth */
if (!function_exists('safe_error')) {
    function safe_error($e) {
        error_log('[inspection_schedule] ' . $e->getMessage());
        return 'Something went wrong. Please try again.';
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

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
function getStatusBadgeClass($status) {
    $status = strtolower(trim($status));
    switch ($status) {
        case 'passed': case 'completed': return 'active';
        case 'failed': case 'rejected': return 'rejected';
        case 'scheduled': case 'pending': return 'pending';
        default: return 'secondary';
    }
}
function formatDateDisplay($date) {
    if (empty($date)) return '—';
    $timestamp = strtotime($date);
    return ($timestamp === false) ? '—' : date('M d, Y', $timestamp);
}
function formatTimeDisplay($time) {
    if (empty($time)) return '—';
    $timestamp = strtotime($time);
    return ($timestamp === false) ? h($time) : date('g:i A', $timestamp);
}
function getInspectionType($type) {
    if (empty($type)) return '—';
    $type = trim($type);
    $types = ['Initial'=>'Initial Inspection','Annual'=>'Annual Inspection','Follow-up'=>'Follow-up Inspection','Complaint'=>'Complaint Inspection','Re-inspection'=>'Re-inspection'];
    return $types[$type] ?? ucfirst(str_replace('_', ' ', $type));
}
function getInspectionCategory($category) {
    if (empty($category)) return '—';
    $category = strtolower(trim($category));
    $categories = ['building'=>'Building Inspection','fire_safety'=>'Fire Safety & Hazard','structural'=>'Structural Assessment','electrical'=>'Electrical Safety','sanitary'=>'Sanitary Inspection','zoning'=>'Zoning Compliance','occupancy'=>'Occupancy Clearance','health'=>'Health & Sanitary'];
    return $categories[$category] ?? ucfirst(str_replace('_', ' ', $category));
}

/* MARK NOTIF */
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
    $totalRentals = 0; $activeRentals = 0; $totalPaid = 0;

    if (tableExists($conn, 'rentals')) {
        $stmtRentals = $conn->prepare("SELECT COUNT(*) FROM rentals WHERE user_id = :uid AND is_deleted = '0'");
        $stmtRentals->execute([':uid' => $userId]);
        $totalRentals = (int)$stmtRentals->fetchColumn();
        if (columnExists($conn, 'rentals', 'status')) {
            $stmtActive = $conn->prepare("SELECT COUNT(*) FROM rentals WHERE user_id = :uid AND LOWER(status::text) = 'active' AND is_deleted = '0'");
            $stmtActive->execute([':uid' => $userId]);
            $activeRentals = (int)$stmtActive->fetchColumn();
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
    } elseif (strpos($question, 'payment') !== false || strpos($question, 'paid') !== false) {
        $reply = "Your total payments received is ₱" . number_format($totalPaid, 2) . ".";
    } elseif (strpos($question, 'inspection') !== false || strpos($question, 'schedule') !== false) {
        $reply = "You can view your inspection calendar and upcoming visits on this page.";
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
                echo '<a href="view_rental.php?id='.$row['id'].'" style="display:flex; align-items:center; justify-content:space-between; padding:12px 16px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; color:#0f172a; text-decoration:none; gap:10px; flex-wrap:wrap;">';
                echo '<div style="display:flex; flex-direction:column; gap:4px; min-width:0; flex:1;">';
                echo '<div style="font-weight:700; font-size:13px; color:#dc2626; word-break:break-word;">'.htmlspecialchars($row['tracking_no'] ?? '#'.$row['id']).' <span style="color:#94a3b8;">•</span> '.htmlspecialchars($row['business_name'] ?: $row['rental_name']).'</div>';
                echo '<div style="font-size:11px; color:#64748b;">Property: '.htmlspecialchars($row['rental_name'] ?? 'N/A').'</div>';
                echo '</div>';
                echo '<span style="padding:4px 10px; border-radius:20px; font-size:10px; font-weight:700; background:'.$badgeBg.'; color:'.$badgeColor.'; text-transform:uppercase; flex-shrink:0;">'.htmlspecialchars($status).'</span>';
                echo '</a>';
            }
            echo '</div>';
        } else {
            echo '<div style="text-align:center; padding:30px; color:#64748b; font-size:13px;"><i class="fa-solid fa-folder-open" style="font-size:24px; margin-bottom:8px; display:block; opacity:0.5;"></i> No matching rental records found.</div>';
        }
    }
    exit;
}

$hasInspectionCategory = columnExists($conn, 'inspections', 'inspection_category');
$hasInspectorName = columnExists($conn, 'inspections', 'inspector_name');

/* Calendar */
$currentMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$currentYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
if ($currentMonth < 1) $currentMonth = 1;
if ($currentMonth > 12) $currentMonth = 12;
if ($currentYear < 2000) $currentYear = 2000;
if ($currentYear > 2100) $currentYear = 2100;

$prevMonth = $currentMonth - 1; $prevYear = $currentYear;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
$nextMonth = $currentMonth + 1; $nextYear = $currentYear;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

$startDate = date('Y-m-01', strtotime("$currentYear-$currentMonth-01"));
$endDate = date('Y-m-t', strtotime("$currentYear-$currentMonth-01"));

$inspections = [];
$query = "SELECT i.*, r.business_name, r.rental_name, r.tracking_no, r.barangay 
          FROM inspections i INNER JOIN rentals r ON i.rental_id = r.id 
          WHERE r.user_id = :uid AND r.is_deleted = '0'
          AND i.inspection_date BETWEEN :sdate AND :edate
          ORDER BY i.inspection_date ASC, i.inspection_time ASC";
$stmt = $conn->prepare($query);
$stmt->execute([':uid' => $userId, ':sdate' => $startDate, ':edate' => $endDate]);
$inspections = $stmt->fetchAll(PDO::FETCH_ASSOC);

$inspectionsByDate = [];
foreach ($inspections as $ins) {
    $date = $ins['inspection_date'] ?? '';
    if (!isset($inspectionsByDate[$date])) $inspectionsByDate[$date] = [];
    $inspectionsByDate[$date][] = $ins;
}

$upcomingInspections = [];
$stmtUp = $conn->prepare("SELECT i.*, r.business_name, r.rental_name, r.tracking_no, r.barangay 
    FROM inspections i INNER JOIN rentals r ON i.rental_id = r.id 
    WHERE r.user_id = :uid AND r.is_deleted = '0'
    AND i.inspection_date >= CURRENT_DATE
    ORDER BY i.inspection_date ASC, i.inspection_time ASC LIMIT 10");
$stmtUp->execute([':uid' => $userId]);
$upcomingInspections = $stmtUp->fetchAll(PDO::FETCH_ASSOC);

$todayInspections = 0;
$stmtToday = $conn->prepare("SELECT COUNT(*) FROM inspections i INNER JOIN rentals r ON i.rental_id = r.id WHERE r.user_id = :uid AND r.is_deleted = '0' AND i.inspection_date = CURRENT_DATE");
$stmtToday->execute([':uid' => $userId]);
$todayInspections = (int)$stmtToday->fetchColumn();

$firstDayOfMonth = date('N', strtotime("$currentYear-$currentMonth-01"));
$daysInMonth = date('t', strtotime("$currentYear-$currentMonth-01"));
$monthName = date('F Y', strtotime("$currentYear-$currentMonth-01"));
$today = date('Y-m-d');

/* Notifications */
$unreadNotifCount = 0; $notifications = [];
if (tableExists($conn, 'notifications')) {
    if (columnExists($conn, 'notifications', 'is_read')) {
        try {
            $stmtNotifCount = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE (is_read = FALSE OR is_read = 0) AND user_id = :uid");
            $stmtNotifCount->execute([':uid' => $userId]);
            $unreadNotifCount = (int) $stmtNotifCount->fetchColumn();
        } catch (PDOException $e) { $unreadNotifCount = 0; }
    }
    $notifStmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = :uid ORDER BY created_at DESC LIMIT 5");
    $notifStmt->execute([':uid' => $userId]);
    $notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#dc2626">
    <title>Inspection Schedule | RENTRACK LGU</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;700;800&display=swap" rel="stylesheet">

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        :root {
            --bg-light: #f8fafc; --bg-card: #ffffff; --card-border: #e2e8f0;
            --accent-blue: #dc2626; --accent-blue-dark: #991b1b; --accent-blue-light: #f87171;
            --blue-soft: #fee2e2;
            --text-primary: #0f172a; --text-secondary: #334155;
            --text-muted: #64748b; --text-faint: #94a3b8;
            --ok: #059669; --ok-bg: #d1fae5;
            --warn: #d97706; --warn-bg: #fef3c7;
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

        /* FIX: Tinanggal ang touch-action: none para gumana ang clicks sa iOS */
        body.drawer-open { overflow: hidden !important; }
        body.drawer-open #aiAssistantWrapper { display: none !important; }

        .main-wrapper {
            display: flex; flex-direction: column;
            min-height: 100vh; min-height: 100dvh;
            background: var(--bg-light);
            padding-left: var(--safe-left); padding-right: var(--safe-right);
        }

        /* OFFICIAL HEADER */
        .official-header {
            width: 100%; display: flex; align-items: center; justify-content: space-between;
            background: linear-gradient(90deg, #991b1b, #dc2626 55%, #991b1b);
            border-bottom: 2px solid #7f1d1d;
            padding: 6px 20px; padding-top: calc(6px + var(--safe-top));
            gap: 20px; position: relative; z-index: 110;
            box-shadow: 0 2px 8px -2px rgba(220, 38, 38, 0.45);
        }
        .official-header-left, .official-header-right-group { display: flex; align-items: center; gap: 12px; color: #fecdd3; font-size: 11px; font-weight: 600; }
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

        /* STICKY HEADER - FIX: Z-Index itinaas sa 1000 */
        .sticky-header { position: sticky; top: 0; z-index: 1000; background: #ffffff; }
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
        .brand-text h2 { font-size: 14px; font-weight: 800; color: var(--accent-blue); line-height: 1.1; margin: 0; font-family: 'Space Grotesk', sans-serif; letter-spacing: -0.3px; }
        .brand-text span { font-size: 9px; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 1.3px; }

        .menu-toggle {
            display: none; width: 42px; height: 42px; border-radius: 10px;
            align-items: center; justify-content: center;
            background: #f8fafc; border: 1px solid var(--card-border);
            color: var(--accent-blue); cursor: pointer; font-size: 16px; flex-shrink: 0;
        }

        /* NAV */
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
        .nav-link i.nav-ico { color: var(--accent-blue); font-size: 12px; width: 14px; text-align: center; }
        .nav-link .chevron { font-size: 8px; margin-left: 1px; transition: transform 0.2s ease; opacity: 0.7; }
        .nav-link:hover { color: var(--accent-blue); background: var(--blue-soft); }
        .nav-link.active { color: var(--accent-blue); background: var(--blue-soft); font-weight: 700; }
        .nav-item.open > .nav-link { color: var(--accent-blue); background: var(--blue-soft); }
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
        .nav-dropdown a:hover { background: var(--blue-soft); color: var(--accent-blue); }
        .nav-dropdown a i { width: 16px; color: var(--accent-blue); font-size: 14px; text-align: center; flex-shrink: 0; }

        .mobile-drawer-header { display: none; }

        /* SEARCH */
        .search-wrapper {
            flex: 1 1 auto; display: flex; justify-content: center;
            min-width: 140px; max-width: 380px; padding: 0 8px; margin: 0 auto;
        }
        .search-container { position: relative; width: 100%; cursor: pointer; }
        .search-container i { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: var(--accent-blue); z-index: 2; font-size: 13px; }
        .search-container input {
            width: 100%; padding: 8px 14px 8px 38px;
            border-radius: 999px; border: 1px solid var(--card-border);
            background: #f8fafc; color: #0f172a; font-size: 13px; outline: none; cursor: pointer;
        }

        /* RIGHT */
        .navbar-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .nav-clock {
            display: flex; flex-direction: column; align-items: flex-end;
            padding: 3px 14px; border-radius: 10px;
            background: var(--blue-soft); border: 1px solid var(--card-border);
        }
        .nav-clock .time { font-family: 'Space Grotesk', monospace; font-size: 13px; font-weight: 700; color: var(--accent-blue); }
        .nav-clock .date { font-size: 10px; color: #64748b; font-weight: 600; text-transform: uppercase; }

        .nav-icon {
            width: 38px; height: 38px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            background: #ffffff; border: 1px solid var(--card-border);
            color: var(--accent-blue); cursor: pointer;
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
            background: var(--accent-blue);
            display: flex; align-items: center; justify-content: center;
            color: #ffffff; font-weight: 800; font-size: 13px;
        }
        .profile-btn .info .name { font-size: 12px; font-weight: 600; color: #0f172a; }
        .profile-btn .info .role { font-size: 9px; color: var(--accent-blue); font-weight: 700; text-transform: uppercase; }

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
        .notification-dropdown { width: 360px; max-width: calc(100vw - 24px); padding: 0; }
        .profile-dropdown.show, .notification-dropdown.show { opacity: 1; visibility: visible; transform: translateY(0) scale(1); }
        .profile-dropdown .dropdown-item {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 14px; border-radius: 10px;
            color: #334155; text-decoration: none; font-size: 13px;
        }
        .profile-dropdown .dropdown-item:hover { background: var(--blue-soft); color: var(--accent-blue); }
        .profile-dropdown .dropdown-item.danger { color: var(--danger); }
        .profile-dropdown .dropdown-item.danger:hover { background: var(--danger-bg); }

        .notification-title { padding: 14px 20px; font-weight: 700; font-size: 14px; border-bottom: 1px solid var(--blue-soft); color: #0f172a; }

        /* CONTENT */
        .content { flex: 1; padding: 22px 24px 40px; padding-bottom: calc(40px + var(--safe-bottom)); background: var(--bg-light); }

        .hero-card {
            display: flex; justify-content: space-between; align-items: center; gap: 1rem;
            padding: 22px 26px; border-radius: 18px; color: #ffffff;
            background: linear-gradient(135deg, #dc2626, #991b1b);
            box-shadow: 0 8px 22px rgba(220, 38, 38, 0.2); margin-bottom: 18px;
            flex-wrap: wrap;
        }
        .hero-card h2 { font-family: 'Space Grotesk', sans-serif; font-size: 22px; font-weight: 800; color: #ffffff; margin: 0; }
        .hero-card p { color: #fee2e2; font-size: 13.5px; margin-top: 4px; margin-bottom: 0; }
        .btn-cta {
            background: #ffffff; color: #dc2626; border: none;
            padding: 9px 18px; border-radius: 999px; font-weight: 800; font-size: 13px;
            cursor: pointer; transition: var(--transition); box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
            white-space: nowrap;
        }
        .btn-cta:hover { background: #fee2e2; color: #dc2626; transform: translateY(-1px); }

        /* STATS */
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 18px; }
        .stat-card {
            background: #ffffff; border: 1px solid var(--card-border); border-radius: 14px;
            padding: 16px 20px; display: flex; justify-content: space-between; align-items: center;
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.03);
        }
        .stat-label { font-size: 11px; font-weight: 800; text-transform: uppercase; color: #64748b; }
        .stat-number { font-family: 'Space Grotesk', sans-serif; font-size: 22px; font-weight: 800; color: #0f172a; }
        .stat-icon {
            width: 38px; height: 38px; border-radius: 10px; background: #f8fafc; color: var(--accent-blue);
            display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0;
        }

        /* CALENDAR LAYOUT */
        .calendar-section { display: grid; grid-template-columns: 1fr 340px; gap: 18px; margin-bottom: 20px; }
        .calendar-card {
            background: #ffffff; border: 1px solid var(--card-border); border-radius: 16px;
            padding: 18px; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.04);
        }
        .calendar-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 16px; padding-bottom: 10px; border-bottom: 1px solid var(--card-border);
        }
        .calendar-header .month-title { font-family: 'Space Grotesk', sans-serif; font-size: 16px; font-weight: 800; color: #0f172a; }
        .calendar-header .nav-btn {
            background: #f8fafc; border: 1px solid var(--card-border); color: var(--accent-blue);
            width: 36px; height: 36px; border-radius: 8px; cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center; transition: var(--transition);
            text-decoration: none;
        }
        .calendar-header .nav-btn:hover { background: var(--accent-blue); color: #fff; }

        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
        .day-header { text-align: center; font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; padding: 6px 0; }
        .day-cell {
            aspect-ratio: 1; border: 1px solid #e2e8f0; border-radius: 10px; padding: 6px;
            background: #f8fafc; position: relative; cursor: pointer; transition: var(--transition);
            min-height: 44px;
        }
        .day-cell:hover { border-color: var(--accent-blue); background: #fef2f2; }
        .day-cell .day-number { font-size: 12px; font-weight: 700; color: #334155; }
        .day-cell.today { background: #fee2e2; border-color: var(--accent-blue); }
        .day-cell.today .day-number { color: var(--accent-blue); font-weight: 800; }
        .day-cell.other-month { opacity: 0.3; background: #ffffff; cursor: default; }
        .day-cell.has-inspection { background: #fee2e2; border-color: var(--accent-blue-light); }
        .day-cell .inspection-count {
            position: absolute; bottom: 4px; right: 6px; font-size: 10px; font-weight: 800;
            background: var(--accent-blue); color: #fff; padding: 1px 6px; border-radius: 999px;
        }

        /* UPCOMING */
        .upcoming-panel {
            background: #ffffff; border: 1px solid var(--card-border); border-radius: 16px;
            padding: 18px; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.04);
        }
        .panel-title { font-family: 'Space Grotesk', sans-serif; font-size: 15px; font-weight: 800; color: #0f172a; margin-bottom: 12px; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px; }
        .upcoming-list { display: flex; flex-direction: column; gap: 10px; max-height: 520px; overflow-y: auto; padding-right: 4px; }
        .upcoming-list::-webkit-scrollbar { width: 5px; }
        .upcoming-list::-webkit-scrollbar-thumb { background: var(--accent-blue-light); border-radius: 999px; }
        .upcoming-item {
            padding: 10px 12px; background: #f8fafc; border-left: 3px solid var(--accent-blue);
            border-radius: 8px; font-size: 12px;
        }
        .upcoming-item .item-date { font-size: 10px; font-weight: 800; color: var(--accent-blue); text-transform: uppercase; }
        .upcoming-item .item-name { font-weight: 700; color: #0f172a; margin: 2px 0; font-size: 12.5px; }
        .upcoming-item .item-detail { color: #64748b; font-size: 11px; }

        .status-badge { padding: 3px 8px; border-radius: 999px; font-size: 10px; font-weight: 800; text-transform: uppercase; display: inline-block; margin-top: 4px; }
        .status-badge.active { background: #d1fae5; color: #059669; }
        .status-badge.pending { background: #fef3c7; color: #d97706; }
        .status-badge.rejected { background: #ffe4e6; color: #dc2626; }
        .status-badge.secondary { background: #f1f5f9; color: #64748b; }

        .dashboard-footer { margin-top: 20px; padding-top: 14px; border-top: 1px solid var(--card-border); display: flex; justify-content: space-between; font-size: 11.5px; color: var(--text-muted); flex-wrap: wrap; gap: 10px; }

        /* MODAL */
        .modal-overlay {
            display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6);
            z-index: 2000; align-items: center; justify-content: center; backdrop-filter: blur(4px);
            padding: 16px; overflow-y: auto;
        }
        .modal-overlay.active { display: flex; }
        .modal-content {
            background: #ffffff; border-radius: 18px; padding: 22px; 
            width: 100%; max-width: 480px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.15); border: 1px solid var(--card-border);
            max-height: calc(100vh - 32px); overflow-y: auto; margin: auto;
        }
        .modal-content::-webkit-scrollbar { width: 6px; }
        .modal-content::-webkit-scrollbar-thumb { background: var(--accent-blue-light); border-radius: 999px; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; border-bottom: 1px solid var(--card-border); padding-bottom: 8px; }
        .modal-header h3 { font-family: 'Space Grotesk', sans-serif; font-size: 16px; font-weight: 800; color: #0f172a; margin: 0; }

        .ins-item { padding: 10px; background: #f8fafc; border-radius: 10px; border: 1px solid var(--card-border); margin-bottom: 8px; }
        .ins-item .ins-name { font-weight: 700; font-size: 13px; color: #0f172a; }
        .ins-item .ins-time { font-size: 11px; color: #64748b; }

        /* SEARCH MODAL */
        .search-modal-overlay {
            position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(153, 27, 27, 0.4); backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px); z-index: 9999;
            display: flex; align-items: flex-start; justify-content: center; padding: 10vh 16px 16px;
            opacity: 0; visibility: hidden; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .search-modal-overlay.active { opacity: 1; visibility: visible; }
        .search-modal-content {
            width: 100%; max-width: 650px; background: #ffffff;
            border: 1px solid var(--card-border); border-radius: 20px;
            box-shadow: 0 25px 50px rgba(220, 38, 38, 0.2);
            overflow: hidden; transform: translateY(-20px) scale(0.98); transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            max-height: 85vh; display: flex; flex-direction: column;
        }
        .search-modal-overlay.active .search-modal-content { transform: translateY(0) scale(1); }
        .search-modal-header {
            display: flex; align-items: center; padding: 14px 18px;
            border-bottom: 1px solid var(--card-border); background: #f8fafc; flex-shrink: 0;
        }
        .search-modal-header i.search-icon-main { font-size: 16px; color: var(--accent-blue); margin-right: 14px; }
        .search-modal-input { flex: 1; background: transparent; border: none; outline: none; color: #0f172a; font-size: 15px; font-weight: 600; }
        .search-modal-close {
            background: #f1f5f9; border: 1px solid #cbd5e1; color: #64748b;
            width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: all 0.2s ease; flex-shrink: 0;
        }
        .search-modal-close:hover { background: var(--danger-bg); color: var(--danger); border-color: #fca5a5; }
        .search-modal-body { padding: 16px; max-height: 380px; overflow-y: auto; flex: 1; }
        .search-shortcut-hint {
            display: flex; align-items: center; justify-content: space-between; padding: 12px 18px;
            background: #f8fafc; border-top: 1px solid var(--card-border); font-size: 11px; color: var(--text-muted); flex-shrink: 0;
        }
        .search-shortcut-hint kbd {
            background: var(--blue-soft); border: 1px solid var(--card-border); padding: 2px 6px; border-radius: 4px; color: var(--accent-blue-dark); font-family: inherit; font-weight: 700;
        }

        /* RESPONSIVE */
        @media (max-width: 1400px) { .nav-link { padding: 10px 8px; font-size: 12px; } }
        
        @media (max-width: 1200px) {
            .top-navbar { padding: 10px 14px; padding-top: calc(10px + var(--safe-top)); gap: 10px; flex-wrap: wrap; min-height: 60px; }
            .nav-clock { display: none; }
            .profile-btn .info { display: none; }
            .profile-btn { padding: 3px; gap: 0; }
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
                background: #ffffff; color: var(--accent-blue);
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

            /* FIX: Mobile Dropdown CSS Specificity */
            .nav-dropdown {
                position: static !important; left: auto !important; right: auto !important;
                transform: none !important; opacity: 1 !important; visibility: visible !important;
                box-shadow: none !important; border: none !important;
                max-height: 0; overflow: hidden;
                transition: max-height 0.3s ease, padding 0.2s ease;
                padding: 0 0 0 16px;
                background: #f8fafc; margin-top: 4px; border-radius: 10px; min-width: 0;
                width: 100% !important;
            }
            .nav-item.open > .nav-dropdown,
            .nav-item.align-right.open > .nav-dropdown {
                max-height: 1000px !important;
                padding: 8px 0 8px 16px !important;
            }
            .nav-dropdown a { padding: 12px 14px; min-height: 44px; font-size: 13.5px; display: flex; align-items: center; }

            .sidebar-overlay {
                display: none; position: fixed; inset: 0;
                background: rgba(0,0,0,0.55);
                z-index: 1999;
                backdrop-filter: blur(3px); -webkit-backdrop-filter: blur(3px);
                opacity: 0; transition: opacity 0.3s ease;
                pointer-events: none;
            }
            .sidebar-overlay.active { 
                display: block; 
                opacity: 1; 
                pointer-events: auto; 
            }

            /* Notification dropdown mobile-friendly */
            .notification-dropdown {
                position: fixed;
                top: 70px;
                left: 12px;
                right: 12px;
                width: auto !important;
                max-width: none;
                transform: translateY(-10px) scale(0.98);
            }
            .notification-dropdown.show { transform: translateY(0) scale(1); }

            .calendar-section { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 992px) {
            .search-modal-overlay { padding-top: 8vh; }
        }

        @media (max-width: 768px) {
            .content { padding: 16px 14px 24px; padding-bottom: calc(24px + var(--safe-bottom)); }
            .hero-card { flex-direction: column; align-items: flex-start; gap: 14px; padding: 18px 20px; }
            .hero-card h2 { font-size: 18px; }
            .btn-cta { width: 100%; justify-content: center; }

            .stats-grid { gap: 10px; margin-bottom: 14px; }
            .stat-card { padding: 12px 14px; }
            .stat-label { font-size: 9.5px; }
            .stat-number { font-size: 18px; }
            .stat-icon { width: 32px; height: 32px; font-size: 13px; }

            .calendar-card { padding: 12px; }
            .calendar-header .month-title { font-size: 14px; }
            .calendar-header .nav-btn { width: 32px; height: 32px; }
            .calendar-grid { gap: 3px; }
            .day-header { font-size: 9.5px; padding: 4px 0; }
            .day-cell { border-radius: 8px; padding: 4px; min-height: 42px; }
            .day-cell .day-number { font-size: 11.5px; }
            .day-cell .inspection-count { font-size: 9px; padding: 1px 5px; bottom: 3px; right: 4px; }

            .upcoming-panel { padding: 14px; }
            .panel-title { font-size: 14px; }
            .upcoming-list { max-height: 400px; }

            .dashboard-footer { flex-direction: column; text-align: center; gap: 6px; font-size: 10.5px; }

            /* AI CHAT responsive */
            #aiAssistantWrapper {
                bottom: calc(18px + var(--safe-bottom));
                right: calc(18px + var(--safe-right));
            }
            #aiToggleBtn { width: 52px; height: 52px; font-size: 20px; }
            #aiChatWindow {
                width: calc(100vw - 24px) !important;
                max-width: 420px;
                height: calc(100vh - 140px) !important;
                max-height: 560px;
                bottom: 66px;
                right: -8px;
                border-radius: 16px;
            }

            /* Search modal */
            .search-modal-overlay { padding: 6vh 12px 12px; }
            .search-modal-content { border-radius: 16px; }
            .search-modal-header { padding: 14px 16px; }
            .search-modal-body { padding: 14px; max-height: 55vh; }
            .search-shortcut-hint { font-size: 10.5px; padding: 10px 14px; }
        }

        @media (max-width: 480px) {
            .content { padding: 12px 10px 22px; padding-bottom: calc(22px + var(--safe-bottom)); }
            .calendar-grid { gap: 2px; }
            .day-header { font-size: 8.5px; }
            .day-cell { border-radius: 6px; padding: 3px; min-height: 38px; }
            .day-cell .day-number { font-size: 10.5px; }
            .day-cell .inspection-count { font-size: 8px; padding: 0 4px; }
            .profile-dropdown { width: calc(100vw - 24px); max-width: 280px; right: -8px; }
            #aiChatWindow { height: calc(100vh - 130px) !important; max-height: 500px; }
        }

        @media (max-width: 767px) {
            input, select, textarea { font-size: 16px !important; }
            .search-container input, .search-modal-input { font-size: 16px !important; }
            #aiChatInput { font-size: 16px !important; }
        }

        /* Touch-friendly */
        @media (hover: none) and (pointer: coarse) {
            .nav-link, .nav-dropdown a, .btn-action, .profile-dropdown .dropdown-item, .notif-drop-item {
                -webkit-tap-highlight-color: rgba(220, 38, 38, 0.15);
            }
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
        <div class="sticky-header">
            <header class="top-navbar">
                <div class="navbar-left">
                    <button class="menu-toggle" id="menuToggle" aria-label="Open menu"><i class="fa-solid fa-bars"></i></button>
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

                <nav class="main-nav-bar" id="mainNavBar">
                    <div class="mobile-drawer-header">
                        <div class="drawer-brand">
                            <div class="drawer-brand-logo">RT</div>
                            <div class="drawer-brand-text">
                                <h2>RENTRACK</h2>
                                <span>Owner Portal</span>
                            </div>
                        </div>
                        <button type="button" class="drawer-close-btn" id="drawerCloseBtn" aria-label="Close menu"><i class="fa-solid fa-xmark"></i></button>
                    </div>

                    <ul class="nav-menu">
                        <li class="nav-item">
                            <a href="dashboard.php" class="nav-link"><i class="fa-solid fa-gauge-high nav-ico"></i> Dashboard</a>
                        </li>

                        <li class="nav-item has-dropdown">
                            <button type="button" class="nav-link" data-dropdown>
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
                            <button type="button" class="nav-link active" data-dropdown>
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
                                    <button type="button" id="notifClearBtn" style="background:transparent; border:none; color:var(--accent-blue); font-size:11px; font-weight:700; cursor:pointer; padding:6px 8px;">Clear</button>
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
                                        <a href="javascript:void(0)" class="notif-drop-item" onclick="markNotificationReadAndRedirect(<?php echo (int)$notification['id']; ?>, '<?php echo htmlspecialchars($notifLink); ?>', this)" style="display:block; padding:12px 16px; border-bottom:1px solid #f1f5f9; text-decoration:none; color:var(--text-secondary); background:<?php echo $isUnread ? '#fef2f2' : '#fff'; ?>;">
                                            <div style="display:flex; justify-content:space-between; align-items:center; gap:8px;">
                                                <strong style="font-size:12px; display:block; color:#0f172a;"><?php echo displayField($notification, ['title']); ?></strong>
                                                <?php if ($isUnread): ?><span class="unread-dot" style="width:8px; height:8px; background:var(--accent-blue); border-radius:50%; display:inline-block; flex-shrink:0;"></span><?php endif; ?>
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
                                <a href="notifications.php" style="font-size:11px; font-weight:700; color:var(--accent-blue); text-decoration:none;">View All Notifications</a>
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

            <div class="sidebar-overlay" id="sidebarOverlay"></div>
        </div>

        <main class="content">
            <!-- HERO -->
            <div class="hero-card">
                <div>
                    <h2><i class="fa-solid fa-calendar-check me-1"></i> Inspection Schedule</h2>
                    <p>Browse upcoming property and equipment site visits on calendar view.</p>
                </div>
                <div>
                    <a href="inspections.php" class="btn-cta"><i class="fa-solid fa-plus me-1"></i> Request Visit</a>
                </div>
            </div>

            <!-- STATS -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div>
                        <span class="stat-label">This Month</span>
                        <div class="stat-number"><?php echo number_format(count($inspections)); ?></div>
                    </div>
                    <div class="stat-icon"><i class="fa-regular fa-calendar"></i></div>
                </div>
                <div class="stat-card">
                    <div>
                        <span class="stat-label">Upcoming</span>
                        <div class="stat-number"><?php echo number_format(count($upcomingInspections)); ?></div>
                    </div>
                    <div class="stat-icon"><i class="fa-regular fa-clock"></i></div>
                </div>
                <div class="stat-card">
                    <div>
                        <span class="stat-label">Visits Today</span>
                        <div class="stat-number"><?php echo number_format($todayInspections); ?></div>
                    </div>
                    <div class="stat-icon"><i class="fa-regular fa-circle-check"></i></div>
                </div>
            </div>

            <!-- CALENDAR + UPCOMING -->
            <div class="calendar-section">
                <div class="calendar-card">
                    <div class="calendar-header">
                        <a href="?month=<?php echo $prevMonth; ?>&year=<?php echo $prevYear; ?>" class="nav-btn">
                            <i class="fa-solid fa-chevron-left"></i>
                        </a>
                        <span class="month-title"><?php echo $monthName; ?></span>
                        <a href="?month=<?php echo $nextMonth; ?>&year=<?php echo $nextYear; ?>" class="nav-btn">
                            <i class="fa-solid fa-chevron-right"></i>
                        </a>
                    </div>

                    <div class="calendar-grid">
                        <div class="day-header">Mon</div>
                        <div class="day-header">Tue</div>
                        <div class="day-header">Wed</div>
                        <div class="day-header">Thu</div>
                        <div class="day-header">Fri</div>
                        <div class="day-header">Sat</div>
                        <div class="day-header">Sun</div>

                        <?php
                        $firstDayOffset = $firstDayOfMonth - 1;
                        for ($i = 0; $i < $firstDayOffset; $i++) echo '<div class="day-cell other-month"></div>';

                        for ($day = 1; $day <= $daysInMonth; $day++) {
                            $dateStr = sprintf("%04d-%02d-%02d", $currentYear, $currentMonth, $day);
                            $isToday = ($dateStr === $today);
                            $hasInspection = isset($inspectionsByDate[$dateStr]) && !empty($inspectionsByDate[$dateStr]);
                            $inspectionCount = $hasInspection ? count($inspectionsByDate[$dateStr]) : 0;
                            $classes = 'day-cell';
                            if ($isToday) $classes .= ' today';
                            if ($hasInspection) $classes .= ' has-inspection';
                            ?>
                            <div class="<?php echo $classes; ?>" onclick="showDayDetails('<?php echo $dateStr; ?>')">
                                <span class="day-number"><?php echo $day; ?></span>
                                <?php if ($hasInspection): ?>
                                    <span class="inspection-count"><?php echo $inspectionCount; ?></span>
                                <?php endif; ?>
                            </div>
                            <?php
                        }

                        $remainingCells = (7 - (($firstDayOffset + $daysInMonth) % 7)) % 7;
                        for ($i = 0; $i < $remainingCells; $i++) echo '<div class="day-cell other-month"></div>';
                        ?>
                    </div>
                </div>

                <!-- UPCOMING -->
                <div class="upcoming-panel">
                    <div class="panel-title"><i class="fa-solid fa-clock-rotate-left me-1" style="color:var(--accent-blue);"></i> Upcoming Visits</div>
                    <?php if (!empty($upcomingInspections)): ?>
                        <div class="upcoming-list">
                            <?php foreach ($upcomingInspections as $ins):
                                $status = $ins['inspection_status'] ?? $ins['status'] ?? 'Scheduled';
                                $statusClass = getStatusBadgeClass($status);
                                $typeLabel = getInspectionType($ins['inspection_type'] ?? '');
                                $categoryLabel = $hasInspectionCategory ? getInspectionCategory($ins['inspection_category'] ?? '') : '';
                                $pName = !empty($ins['business_name']) ? $ins['business_name'] : ($ins['rental_name'] ?? 'Property');
                            ?>
                                <div class="upcoming-item">
                                    <div class="item-date"><i class="fa-regular fa-calendar me-1"></i> <?php echo formatDateDisplay($ins['inspection_date'] ?? ''); ?></div>
                                    <div class="item-name"><?php echo h($pName); ?></div>
                                    <div class="item-detail">
                                        <?php echo h($typeLabel); ?>
                                        <?php if (!empty($categoryLabel) && $categoryLabel !== '—'): ?>
                                            &middot; <?php echo h($categoryLabel); ?>
                                        <?php endif; ?>
                                        &middot; <?php echo formatTimeDisplay($ins['inspection_time'] ?? ''); ?>
                                    </div>
                                    <span class="status-badge <?php echo $statusClass; ?>"><?php echo h($status); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div style="text-align:center; padding:20px; color:#64748b; font-size:12px;">
                            <i class="fa-regular fa-calendar-xmark" style="font-size:24px; opacity:0.5; display:block; margin-bottom:6px;"></i>
                            No upcoming visits.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <footer class="dashboard-footer">
                <span>&copy; <?php echo date('Y'); ?> <span style="color:var(--accent-blue); font-weight:700;">RENTRACK LGU</span> &middot; Unified Rental System</span>
                <span>Owner Portal</span>
            </footer>
        </main>
    </div>

    <!-- DAY DETAILS MODAL -->
    <div class="modal-overlay" id="inspectionModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Visits on <span id="modalDate" style="color:var(--accent-blue);"></span></h3>
                <button type="button" class="btn-close" onclick="closeModal()"></button>
            </div>
            <div class="modal-body" id="modalInspectionList"></div>
        </div>
    </div>

    <!-- SEARCH MODAL -->
    <div class="search-modal-overlay" id="searchModalOverlay" onclick="closeSearchModalOnOutside(event)">
        <div class="search-modal-content">
            <div class="search-modal-header">
                <i class="fa-solid fa-magnifying-glass search-icon-main"></i>
                <input type="text" class="search-modal-input" id="modalSearchInput" placeholder="Search records..." autocomplete="off">
                <div class="search-modal-close" onclick="closeSearchModal()"><i class="fa-solid fa-xmark"></i></div>
            </div>
            <div class="search-modal-body" id="modalSearchResults">
                <div style="text-align:center; padding:40px 12px; color:#64748b; font-size:13px;">
                    <i class="fa-solid fa-keyboard" style="font-size:28px; margin-bottom:10px; display:block; opacity:0.5; color:var(--accent-blue);"></i>
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
    <div id="aiAssistantWrapper">
        <button id="aiToggleBtn" title="Ask AI Assistant" aria-label="Ask AI Assistant">
            <i class="fa-solid fa-robot"></i>
        </button>
        <div id="aiChatWindow">
            <div style="background:linear-gradient(135deg, #dc2626, #991b1b); color:#fff; padding:14px 16px; display:flex; align-items:center; justify-content:space-between; flex-shrink:0;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <i class="fa-solid fa-robot" style="font-size:16px; color:#fecdd3;"></i>
                    <div>
                        <strong style="font-size:14px; display:block; font-family:'Space Grotesk', sans-serif;">RENTRACK AI</strong>
                        <span style="font-size:10px; color:#fecdd3;">Owner LGU Assistant</span>
                    </div>
                </div>
                <button id="aiCloseBtn" style="background:transparent; border:none; color:#fff; font-size:16px; cursor:pointer; padding:4px;" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div id="aiChatBody" style="flex:1; padding:14px; overflow-y:auto; background:#f8fafc; display:flex; flex-direction:column; gap:10px; font-size:12.5px;">
                <div style="background:var(--blue-soft); color:var(--accent-blue-dark); padding:10px 12px; border-radius:12px; max-width:85%; align-self:flex-start; border-bottom-left-radius:2px; word-wrap:break-word;">
                    Hello <?php echo htmlspecialchars($userName); ?>! Ask me about your inspections, rentals, payments, or permits.
                </div>
            </div>
            <div style="padding:10px; background:#ffffff; border-top:1px solid var(--card-border); display:flex; gap:8px; flex-shrink:0;">
                <input type="text" id="aiChatInput" placeholder="Ask e.g., 'Any inspections today?'..." style="flex:1; min-width:0; padding:8px 12px; border:1px solid var(--card-border); border-radius:999px; outline:none; font-size:12.5px; background:#f8fafc;">
                <button id="aiSendBtn" style="background:var(--accent-blue); border:none; color:#fff; width:36px; height:36px; border-radius:50%; cursor:pointer; display:flex; align-items:center; justify-content:center; flex-shrink:0;" aria-label="Send"><i class="fa-solid fa-paper-plane" style="font-size:12px;"></i></button>
            </div>
        </div>
    </div>

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
            closeModal();
            closeSearchModal();
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
                    if (lb) lb.innerHTML = '<div style="padding:24px; text-align:center; color:#94a3b8; font-size:12px;"><i class="fa-solid fa-check-double" style="font-size:20px; margin-bottom:8px; display:block; color:var(--accent-blue);"></i>You\'re all caught up!</div>';
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
        modalSearchResults.innerHTML = `<div style="text-align:center; padding:40px 12px; color:#64748b; font-size:13px;"><i class="fa-solid fa-keyboard" style="font-size:28px; margin-bottom:10px; display:block; opacity:0.5; color:var(--accent-blue);"></i>Type at least 2 characters to search records...</div>`;
    }
    function closeSearchModalOnOutside(event) { if (event.target === searchModalOverlay) closeSearchModal(); }

    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') { e.preventDefault(); openSearchModal(); }
    });

    let searchTimeout;
    modalSearchInput?.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        let query = this.value.trim();
        if (query.length < 2) {
            modalSearchResults.innerHTML = `<div style="text-align:center; padding:40px 12px; color:#64748b; font-size:13px;"><i class="fa-solid fa-keyboard" style="font-size:28px; margin-bottom:10px; display:block; opacity:0.5; color:var(--accent-blue);"></i>Type at least 2 characters to search records...</div>`;
            return;
        }
        modalSearchResults.innerHTML = `<div style="text-align:center; padding:30px; color:#64748b;"><i class="fa-solid fa-spinner fa-spin" style="font-size:20px; margin-bottom:8px; color:var(--accent-blue);"></i><div>Searching records...</div></div>`;
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
        if (!isVisible) setTimeout(() => aiChatInput.focus(), 150);
    });
    aiCloseBtn?.addEventListener('click', () => { aiChatWindow.style.display = 'none'; });

    function sendAiMessage() {
        const text = aiChatInput.value.trim();
        if (!text) return;
        aiChatBody.innerHTML += `<div style="background:var(--accent-blue); color:#fff; padding:10px 12px; border-radius:12px; max-width:85%; align-self:flex-end; border-bottom-right-radius:2px; word-wrap:break-word;">${escapeHtml(text)}</div>`;
        aiChatInput.value = '';
        aiChatBody.scrollTop = aiChatBody.scrollHeight;
        const loadingId = 'ai-loading-' + Date.now();
        aiChatBody.innerHTML += `<div id="${loadingId}" style="background:var(--blue-soft); color:var(--accent-blue-dark); padding:10px 12px; border-radius:12px; max-width:85%; align-self:flex-start; border-bottom-left-radius:2px;"><i class="fa-solid fa-spinner fa-spin"></i> Thinking...</div>`;
        aiChatBody.scrollTop = aiChatBody.scrollHeight;

        fetch('?ajax_action=ai_chat&msg=' + encodeURIComponent(text))
            .then(res => res.json())
            .then(data => {
                document.getElementById(loadingId).remove();
                aiChatBody.innerHTML += `<div style="background:var(--blue-soft); color:var(--accent-blue-dark); padding:10px 12px; border-radius:12px; max-width:85%; align-self:flex-start; border-bottom-left-radius:2px; word-wrap:break-word;">${data.reply}</div>`;
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

    /* INSPECTION DATA */
    const inspectionsByDate = <?php
        $jsonData = [];
        foreach ($inspectionsByDate as $date => $items) {
            $jsonData[$date] = array_map(function($item) use ($hasInspectionCategory) {
                return [
                    'business_name' => $item['business_name'] ?? $item['rental_name'] ?? 'Property',
                    'tracking_no' => $item['tracking_no'] ?? 'N/A',
                    'barangay' => $item['barangay'] ?? '',
                    'inspection_date' => $item['inspection_date'] ?? '',
                    'inspection_time' => $item['inspection_time'] ?? '',
                    'inspection_type' => $item['inspection_type'] ?? '',
                    'inspection_category' => $hasInspectionCategory ? ($item['inspection_category'] ?? '') : '',
                    'inspection_status' => $item['inspection_status'] ?? $item['status'] ?? 'Scheduled',
                    'inspector_name' => $item['inspector_name'] ?? 'LGU Inspector'
                ];
            }, $items);
        }
        echo json_encode($jsonData);
    ?>;

    function getTypeLabel(type) {
        if (!type) return '—';
        const types = { 'Initial':'Initial Inspection','Annual':'Annual Inspection','Follow-up':'Follow-up Inspection','Complaint':'Complaint Inspection','Re-inspection':'Re-inspection' };
        return types[type] || type.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
    }
    function getCategoryLabel(category) {
        if (!category) return '';
        const categories = { 'building':'Building Inspection','fire_safety':'Fire Safety & Hazard','structural':'Structural Assessment','electrical':'Electrical Safety','sanitary':'Sanitary Inspection','zoning':'Zoning Compliance','occupancy':'Occupancy Clearance','health':'Health & Sanitary' };
        return categories[category] || category.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
    }
    function getStatusClass(status) {
        const s = (status || '').toLowerCase().trim();
        if (['passed', 'completed'].includes(s)) return 'active';
        if (['failed', 'rejected'].includes(s)) return 'rejected';
        if (['scheduled', 'pending'].includes(s)) return 'pending';
        return 'secondary';
    }

    function showDayDetails(dateStr) {
        const items = inspectionsByDate[dateStr] || [];
        const modal = document.getElementById('inspectionModal');
        const dateDisplay = document.getElementById('modalDate');
        const list = document.getElementById('modalInspectionList');

        const dateObj = new Date(dateStr + 'T00:00:00');
        dateDisplay.textContent = dateObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });

        if (items.length === 0) {
            list.innerHTML = `<div style="text-align:center; padding:20px; color:#64748b; font-size:12px;"><i class="fa-regular fa-calendar-xmark" style="font-size:24px; opacity:0.5; display:block; margin-bottom:6px;"></i>No inspections on this date.</div>`;
        } else {
            let html = '';
            items.forEach(function(item) {
                const status = item.inspection_status || 'Scheduled';
                const statusClass = getStatusClass(status);
                const typeLabel = getTypeLabel(item.inspection_type);
                const categoryLabel = item.inspection_category ? getCategoryLabel(item.inspection_category) : '';
                const timeDisplay = item.inspection_time ? item.inspection_time : 'TBA';
                html += `
                    <div class="ins-item">
                        <div style="display:flex; justify-content:space-between; gap:8px; align-items:flex-start;">
                            <span class="ins-name">${escapeHtml(item.business_name)}</span>
                            <span style="font-size:11px; font-weight:700; color:#64748b; white-space:nowrap;">#${escapeHtml(item.tracking_no)}</span>
                        </div>
                        <div class="ins-time mt-1"><i class="fa-regular fa-clock me-1"></i> ${escapeHtml(timeDisplay)} &middot; ${escapeHtml(typeLabel)}</div>
                        ${categoryLabel ? `<div class="ins-time"><i class="fa-solid fa-layer-group me-1"></i> ${escapeHtml(categoryLabel)}</div>` : ''}
                        <div style="margin-top:4px;"><span class="status-badge ${statusClass}">${escapeHtml(status)}</span></div>
                    </div>
                `;
            });
            list.innerHTML = html;
        }

        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        document.getElementById('inspectionModal').classList.remove('active');
        document.body.style.overflow = '';
    }

    document.getElementById('inspectionModal')?.addEventListener('click', (e) => {
        if (e.target.id === 'inspectionModal') closeModal();
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