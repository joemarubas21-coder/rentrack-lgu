<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

if ($_SESSION['role'] != "owner") {
    header("Location: ../login.php");
    exit();
}

require_once("../config/db.php");

if (!isset($_GET['id'])) {
    header("Location: rentals.php");
    exit();
}

$id = (int) $_GET['id'];
$userId = (int) ($_SESSION['user_id'] ?? 0);
$userName = $_SESSION['fullname'] ?? 'Owner';
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
function h($value) { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }
function formatDate($value) {
    if (empty($value) || $value === '0000-00-00') return '—';
    $ts = strtotime($value);
    return $ts ? date("M d, Y", $ts) : h($value);
}
function formatTime($time) {
    if (empty($time)) return '—';
    $timestamp = strtotime($time);
    return ($timestamp === false) ? h($time) : date('g:i A', $timestamp);
}
function statusBadge($status) {
    $s = strtolower((string)$status);
    $class = 'secondary';
    if (in_array($s, ['approved','active','compliant','paid','passed','completed'])) $class = 'active';
    elseif (in_array($s, ['pending','expired','scheduled'])) $class = 'pending';
    elseif (in_array($s, ['rejected','suspended','revoked','non-compliant','unpaid','failed'])) $class = 'rejected';
    return '<span class="status-badge ' . $class . '">' . h($status) . '</span>';
}
function getInspectionType($type) {
    if (empty($type)) return '—';
    $type = trim($type);
    $types = [
        'Initial' => 'Initial Inspection', 'Annual' => 'Annual Inspection',
        'Follow-up' => 'Follow-up Inspection', 'Complaint' => 'Complaint Inspection',
        'Re-inspection' => 'Re-inspection',
    ];
    return $types[$type] ?? ucfirst(str_replace('_', ' ', $type));
}
function getInspectionCategory($category) {
    if (empty($category)) return '—';
    $category = strtolower(trim($category));
    $categories = [
        'building' => 'Building Inspection', 'fire_safety' => 'Fire Safety & Hazard',
        'structural' => 'Structural Assessment', 'electrical' => 'Electrical Safety',
        'sanitary' => 'Sanitary Inspection', 'zoning' => 'Zoning Compliance',
        'occupancy' => 'Occupancy Clearance', 'health' => 'Health & Sanitary',
    ];
    return $categories[$category] ?? ucfirst(str_replace('_', ' ', $category));
}
function getInspectionStatusClass($status) {
    $status = strtolower(trim($status));
    switch ($status) {
        case 'passed': case 'completed': return 'active';
        case 'failed': case 'rejected': return 'rejected';
        case 'scheduled': case 'pending': return 'pending';
        default: return 'secondary';
    }
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
    } elseif (strpos($question, 'payment') !== false || strpos($question, 'paid') !== false) {
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
                $badgeBg = 'rgba(2,132,199,0.1)'; $badgeColor = '#0284c7';
                if (strtolower($status)==='pending') { $badgeBg='rgba(217,119,6,0.15)'; $badgeColor='#d97706'; }
                elseif (in_array(strtolower($status), ['approved','active'])) { $badgeBg='rgba(5,150,105,0.15)'; $badgeColor='#059669'; }
                echo '<a href="view_rental.php?id='.$row['id'].'" style="display:flex; align-items:center; justify-content:space-between; padding:12px 16px; background:#f8fafc; border:1px solid #bae6fd; border-radius:12px; color:#0f172a; text-decoration:none; gap:10px;">';
                echo '<div style="display:flex; flex-direction:column; gap:4px; min-width:0; flex:1;">';
                echo '<div style="font-weight:700; font-size:13px; color:#0284c7; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">'.htmlspecialchars($row['tracking_no'] ?? '#'.$row['id']).' <span style="color:#94a3b8;">•</span> '.htmlspecialchars($row['business_name'] ?: $row['rental_name']).'</div>';
                echo '<div style="font-size:11px; color:#64748b; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">Property: '.htmlspecialchars($row['rental_name'] ?? 'N/A').'</div>';
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

/* Notifications */
$notifications = []; $unreadNotifCount = 0;
if (tableExists($conn, 'notifications')) {
    if (columnExists($conn, 'notifications', 'is_read')) {
        try {
            $stmtNotifCount = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE (is_read = FALSE OR is_read = 0) AND user_id = :uid");
            $stmtNotifCount->execute([':uid' => $userId]);
            $unreadNotifCount = (int) $stmtNotifCount->fetchColumn();
        } catch (PDOException $e) { $unreadNotifCount = 0; }
    }
    try {
        $notifStmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = :uid ORDER BY created_at DESC LIMIT 5");
        $notifStmt->execute([':uid' => $userId]);
        $notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { $notifications = []; }
}

/* Fetch Rental */
$stmt = $conn->prepare("
    SELECT r.*, rt.type_name, u.fullname AS owner_name
    FROM rentals r
    LEFT JOIN rental_types rt ON r.rental_type_id = rt.id
    LEFT JOIN users u ON r.user_id = u.id
    WHERE r.id = :rid AND (r.is_deleted = '0' OR r.is_deleted = FALSE)
    LIMIT 1
");
$stmt->execute([':rid' => $id]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$r) {
    $_SESSION['error'] = 'Rental not found.';
    header("Location: rentals.php");
    exit();
}
if ($r['user_id'] != $userId) {
    $_SESSION['error'] = 'You are not authorized to view this rental.';
    header("Location: rentals.php");
    exit();
}

/* Fetch business permits */
$businessPermits = [];
if (tableExists($conn, 'documents')) {
    try {
        $docQuery = $conn->prepare("SELECT * FROM documents WHERE rental_id = :rid ORDER BY uploaded_at DESC");
        $docQuery->execute([':rid' => $id]);
        $businessPermits = $docQuery->fetchAll(PDO::FETCH_ASSOC);

        // 🔥 I-OVERRIDE ANG PERMIT DETAILS MULA SA DOCUMENTS TABLE
        $latestPermit = null;
        foreach ($businessPermits as $p) {
            $titleLower = strtolower($p['title'] ?? '');
            $typeLower = strtolower($p['type'] ?? '');
            if (strpos($titleLower, 'business permit') !== false || strpos($titleLower, 'mayor') !== false || strpos($typeLower, 'business') !== false) {
                $latestPermit = $p;
                break;
            }
        }
        
        if ($latestPermit) {
            if (!empty($latestPermit['business_permit_no'])) {
                $r['business_permit_no'] = $latestPermit['business_permit_no'];
            }
            if (!empty($latestPermit['permit_expiry_date'])) {
                $r['permit_expiry'] = $latestPermit['permit_expiry_date'];
            }
        }
    } catch (PDOException $e) { $businessPermits = []; }
}

// =====================================================
// 🔥 RECALCULATE COMPLIANCE STATUS (DISPLAY ONLY)
// Same logic with rentals.php
// =====================================================
date_default_timezone_set('Asia/Manila');
$rentalStatusLower = strtolower(trim($r['status'] ?? ''));
$permitExpiryStr = !empty($r['permit_expiry']) ? date('Y-m-d', strtotime($r['permit_expiry'])) : null;
$todayStr = date('Y-m-d');

if (in_array($rentalStatusLower, ['approved', 'active'])) {
    if ($permitExpiryStr && $permitExpiryStr < $todayStr) {
        $r['compliance_status'] = 'Non-Compliant';
    } else {
        $r['compliance_status'] = 'Compliant';
    }
} elseif (in_array($rentalStatusLower, ['pending', 'draft'])) {
    $r['compliance_status'] = 'Pending';
} elseif ($permitExpiryStr && $permitExpiryStr < $todayStr) {
    $r['compliance_status'] = 'Non-Compliant';
} else {
    $r['compliance_status'] = 'Pending';
}

/* Fetch inspections */
$hasInspectionCategory = columnExists($conn, 'inspections', 'inspection_category');
$hasInspectorName = columnExists($conn, 'inspections', 'inspector_name');
$hasInspectionStatus = columnExists($conn, 'inspections', 'inspection_status');
$hasStatus = columnExists($conn, 'inspections', 'status');
$inspections = [];
if (tableExists($conn, 'inspections')) {
    try {
        $inspStmt = $conn->prepare("SELECT i.* FROM inspections i WHERE i.rental_id = :rid ORDER BY i.inspection_date DESC, i.id DESC");
        $inspStmt->execute([':rid' => $id]);
        $inspections = $inspStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { $inspections = []; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#0284c7">
    <title>View Rental | RENTRACK LGU</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;700;800&display=swap" rel="stylesheet">

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        :root {
            --bg-light: #f0f9ff;
            --bg-card: #ffffff;
            --card-border: #bae6fd;
            --accent-blue: #0284c7;
            --accent-blue-dark: #0369a1;
            --accent-blue-light: #7dd3fc;
            --blue-soft: #e0f2fe;
            --text-primary: #0f172a;
            --text-secondary: #334155;
            --text-muted: #64748b;
            --text-faint: #94a3b8;
            --ok: #059669; --ok-bg: #d1fae5;
            --warn: #d97706; --warn-bg: #fef3c7;
            --danger: #ef4444; --danger-bg: #fee2e2;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --safe-top: env(safe-area-inset-top, 0px);
            --safe-bottom: env(safe-area-inset-bottom, 0px);
            --safe-left: env(safe-area-inset-left, 0px);
            --safe-right: env(safe-area-inset-right, 0px);
        }

        @keyframes spinRing { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        @keyframes counterSpin { 0% { transform: rotate(0deg); } 100% { transform: rotate(-360deg); } }

        html, body {
            margin: 0 !important; padding: 0 !important;
            height: 100%; font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            background: var(--bg-light); color: var(--text-primary);
            overflow-x: hidden; -webkit-text-size-adjust: 100%;
            -webkit-font-smoothing: antialiased;
        }
        * { scrollbar-width: thin; scrollbar-color: rgba(2, 132, 199, 0.4) transparent; }
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
            background: linear-gradient(90deg, #0c4a6e, #0369a1 55%, #0c4a6e);
            border-bottom: 2px solid #0c4a6e;
            padding: 6px 20px; padding-top: calc(6px + var(--safe-top));
            gap: 20px; position: relative; z-index: 110;
            box-shadow: 0 2px 8px -2px rgba(2, 132, 199, 0.45);
        }
        .official-header-left, .official-header-right-group {
            display: flex; align-items: center; gap: 12px;
            color: #bae6fd; font-size: 11px; font-weight: 600;
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
            border: 2px dashed rgba(2, 132, 199, 0.55);
            animation: spinRing 4s linear infinite;
        }
        .brand-logo-circle {
            width: 36px; height: 36px; border-radius: 50%;
            background: linear-gradient(135deg, #0284c7, #0c4a6e);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-weight: 800; font-size: 13px; z-index: 2;
            box-shadow: 0 4px 10px rgba(2, 132, 199, 0.35);
            animation: counterSpin 4s linear infinite;
        }
        .brand-text h2 { font-size: 14px; font-weight: 800; color: var(--accent-blue); line-height: 1.1; margin: 0; font-family: 'Space Grotesk', sans-serif; letter-spacing: -0.3px; }
        .brand-text span { font-size: 9px; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 1.3px; }

        .menu-toggle {
            display: none; width: 42px; height: 42px; border-radius: 10px;
            align-items: center; justify-content: center;
            background: #f0f9ff; border: 1px solid var(--card-border);
            color: var(--accent-blue); cursor: pointer; font-size: 16px; flex-shrink: 0;
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
            box-shadow: 0 12px 30px rgba(2, 132, 199, 0.15);
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

        /* RIGHT CONTROLS */
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
            background: #ef4444; color: #fff;
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
            box-shadow: 0 16px 36px rgba(2, 132, 199, 0.15);
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

        /* HERO */
        .hero-card {
            display: flex; justify-content: space-between; align-items: center; gap: 1rem;
            padding: 22px 26px; border-radius: 18px; color: #ffffff;
            background: linear-gradient(135deg, #0284c7, #2563eb);
            box-shadow: 0 8px 22px rgba(2, 132, 199, 0.2); margin-bottom: 18px;
            flex-wrap: wrap;
        }
        .hero-card h2 { font-family: 'Space Grotesk', sans-serif; font-size: 22px; font-weight: 800; color: #ffffff; margin: 0; }
        .hero-card p { color: #e0f2fe; font-size: 13.5px; margin-top: 4px; margin-bottom: 0; word-break: break-word; }
        .tracking-chip {
            display: inline-block; margin-top: 8px;
            background: rgba(255,255,255,0.2); color: #ffffff;
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 20px; padding: 3px 12px; font-size: 11.5px; font-weight: 700;
        }
        .hero-meta { display: flex; gap: 10px; flex-wrap: wrap; }
        .hero-card .btn-back {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 9px 18px; border-radius: 999px; border: 1px solid rgba(255,255,255,0.4);
            background: rgba(255,255,255,0.15); color: #fff; font-weight: 700; font-size: 13px;
            text-decoration: none; transition: var(--transition); white-space: nowrap;
        }
        .hero-card .btn-back:hover { background: rgba(255,255,255,0.3); color: #fff; }

        /* PANELS */
        .panel {
            background: var(--bg-card); border: 1px solid var(--card-border);
            border-radius: 18px; overflow: hidden; margin-bottom: 18px;
            box-shadow: 0 4px 15px rgba(2, 132, 199, 0.05);
        }
        .panel-header {
            padding: 16px 22px; border-bottom: 1px solid #bae6fd;
            font-family: 'Space Grotesk', sans-serif; font-size: 15px; font-weight: 700; color: #0f172a;
            display: flex; align-items: center; gap: 10px; background: #f8fafc; flex-wrap: wrap;
            justify-content: space-between;
        }
        .panel-header i { color: #0284c7; }
        .panel-body { padding: 22px; }

        /* INFO GRID */
        .info-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px 22px;
        }
        .info-item label {
            display: block; font-size: 11px; font-weight: 800; text-transform: uppercase;
            color: #64748b; letter-spacing: 0.5px; margin-bottom: 4px;
        }
        .info-item p { margin: 0; font-size: 13.5px; color: #0f172a; font-weight: 600; word-break: break-word; }
        .info-item.full { grid-column: 1 / -1; }
        .info-item p .amount { color: #0284c7; font-weight: 800; font-size: 16px; }

        /* BADGES */
        .status-badge { padding: 4px 10px; border-radius: 999px; font-size: 10.5px; font-weight: 800; text-transform: uppercase; display: inline-block; white-space: nowrap; }
        .status-badge.active { background: #d1fae5; color: #059669; }
        .status-badge.pending { background: #fef3c7; color: #d97706; }
        .status-badge.rejected { background: #ffe4e6; color: #e11d48; }
        .status-badge.secondary { background: #f1f5f9; color: #64748b; }

        /* STAFF REMARKS */
        .staff-remarks-box {
            background-color: #0369a1; border: 1px solid #0284c7; border-radius: 18px;
            padding: 22px; color: #f0f9ff; margin-bottom: 18px;
            box-shadow: 0 4px 15px rgba(3, 105, 161, 0.15);
        }
        .staff-remarks-header {
            display: flex; justify-content: space-between; align-items: center;
            border-bottom: 1px solid rgba(56, 189, 248, 0.4);
            padding-bottom: 12px; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;
        }
        .staff-remarks-title {
            font-family: 'Space Grotesk', sans-serif; font-size: 16px; font-weight: 700;
            display: flex; align-items: center; gap: 8px; color: #7dd3fc;
        }
        .staff-remarks-content {
            background-color: #0c4a6e; border: 1px solid #0284c7;
            border-radius: 12px; padding: 14px 16px;
            font-size: 14px; color: #ffffff; line-height: 1.6;
            white-space: pre-line; word-break: break-word;
        }

        /* PERMITS TABLE */
        .permit-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .permit-table th {
            text-align: left; padding: 12px 14px; color: #64748b; font-weight: 700;
            border-bottom: 1px solid #bae6fd; font-size: 11px;
            text-transform: uppercase; background: transparent;
            white-space: nowrap; letter-spacing: 0.5px;
        }
        .permit-table td {
            padding: 14px; border-bottom: 1px solid #e0f2fe; color: #334155;
            vertical-align: middle; font-weight: 500;
        }
        .permit-table tr:hover { background: #f0f9ff; }
        .permit-table td strong { color: #0284c7; }

        .btn-view-permit {
            background: #dbeafe; border: 1px solid #bfdbfe; color: #1d4ed8;
            padding: 6px 12px; border-radius: 8px; font-size: 12px; font-weight: 700;
            text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
            transition: var(--transition);
        }
        .btn-view-permit:hover { background: #bfdbfe; color: #1e40af; }

        /* INSPECTION LIST */
        .inspection-list { display: flex; flex-direction: column; gap: 12px; }
        .inspection-item {
            display: flex; gap: 16px; padding: 14px 16px;
            background: #f8fafc; border: 1px solid #bae6fd; border-radius: 14px;
            transition: var(--transition); align-items: flex-start;
        }
        .inspection-item:hover { border-color: #0284c7; background: #f0f9ff; }
        .inspection-date-box {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            background: #0284c7; color: #fff;
            border-radius: 10px; padding: 6px 10px; min-width: 58px;
            flex-shrink: 0; line-height: 1.1;
        }
        .inspection-date-box .month { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; }
        .inspection-date-box .day { font-size: 20px; font-weight: 800; font-family: 'Space Grotesk', sans-serif; }
        .inspection-date-box .year { font-size: 10px; font-weight: 600; opacity: 0.85; }
        .inspection-content { flex: 1; min-width: 0; }
        .inspection-content .title-row {
            display: flex; align-items: center; justify-content: space-between; gap: 10px;
            flex-wrap: wrap; margin-bottom: 6px;
        }
        .inspection-content .type-label {
            font-family: 'Space Grotesk', sans-serif; font-size: 14px; font-weight: 800;
            color: #0f172a; margin: 0;
        }
        .inspection-meta { display: flex; flex-wrap: wrap; gap: 10px 16px; font-size: 12px; color: #475569; }
        .inspection-meta .meta-item { display: flex; align-items: center; gap: 6px; }
        .inspection-meta .meta-item i { color: #0284c7; font-size: 11px; }
        .inspection-meta .meta-item strong { color: #0f172a; font-weight: 700; }
        .inspection-remarks {
            margin-top: 10px; padding: 10px 12px;
            background: #ffffff; border: 1px dashed #bae6fd; border-radius: 8px;
            font-size: 12px; color: #475569; line-height: 1.5; word-break: break-word;
        }
        .inspection-remarks strong { color: #0284c7; font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 4px; }

        .empty-state {
            text-align: center; padding: 30px 20px; color: #64748b; font-size: 13px;
        }
        .empty-state i { font-size: 36px; opacity: 0.4; color: #0284c7; display: block; margin-bottom: 10px; }

        .quick-link-btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 16px; border-radius: 999px; font-size: 12px;
            font-weight: 700; text-decoration: none; transition: var(--transition);
        }
        .quick-link-primary { background: #0284c7; color: #fff; }
        .quick-link-primary:hover { background: #0369a1; color: #fff; }
        .quick-link-outline { background: #f0f9ff; color: #0284c7; border: 1px solid #bae6fd; }
        .quick-link-outline:hover { background: #0284c7; color: #fff; }

        .dashboard-footer { margin-top: 20px; padding-top: 14px; border-top: 1px solid var(--card-border); display: flex; justify-content: space-between; font-size: 11.5px; color: var(--text-muted); flex-wrap: wrap; gap: 10px; }

        /* SEARCH MODAL */
        .search-modal-overlay {
            position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(3, 105, 161, 0.4); backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px); z-index: 9999;
            display: flex; align-items: flex-start; justify-content: center; padding: 10vh 16px 16px;
            opacity: 0; visibility: hidden; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .search-modal-overlay.active { opacity: 1; visibility: visible; }
        .search-modal-content {
            width: 100%; max-width: 650px; background: #ffffff;
            border: 1px solid var(--card-border); border-radius: 20px;
            box-shadow: 0 25px 50px rgba(2, 132, 199, 0.2);
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
                background: linear-gradient(90deg, #0c4a6e, #0369a1 55%, #0c4a6e);
                color: #fff; flex-shrink: 0; border-bottom: 2px solid #0c4a6e;
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
            .content { padding: 16px 14px 24px; padding-bottom: calc(24px + var(--safe-bottom)); }
            .hero-card { flex-direction: column; align-items: flex-start; gap: 12px; padding: 18px 20px; }
            .hero-card h2 { font-size: 18px; }
            .hero-card .btn-back { width: 100%; justify-content: center; }
            .panel-header { padding: 14px 16px; font-size: 13.5px; }
            .panel-body { padding: 16px; }
            .info-grid { grid-template-columns: 1fr; gap: 12px; }
            .permit-table th, .permit-table td { padding: 10px 8px; font-size: 12px; }
            .inspection-item { padding: 12px; gap: 12px; flex-direction: column; }
            .inspection-date-box { flex-direction: row; min-width: auto; padding: 4px 10px; gap: 6px; align-self: flex-start; }
            .inspection-date-box .day { font-size: 14px; }
            .dashboard-footer { flex-direction: column; text-align: center; gap: 6px; font-size: 10.5px; }
        }
        @media (max-width: 480px) {
            .content { padding: 12px 10px 22px; padding-bottom: calc(22px + var(--safe-bottom)); }
            .staff-remarks-box { padding: 16px; }
        }
        @media (max-width: 767px) {
            input, select, textarea { font-size: 16px !important; }
            .search-container input, .search-modal-input { font-size: 16px !important; }
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
                    <button class="menu-toggle" id="menuToggle"><i class="fa-solid fa-bars"></i></button>
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
                                    <button type="button" id="notifClearBtn" style="background:transparent; border:none; color:var(--accent-blue); font-size:11px; font-weight:700; cursor:pointer; padding:2px 4px;">Clear</button>
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
                                        <a href="javascript:void(0)" class="notif-drop-item" onclick="markNotificationReadAndRedirect(<?php echo (int)$notification['id']; ?>, '<?php echo htmlspecialchars($notifLink); ?>', this)" style="display:block; padding:10px 14px; border-bottom:1px solid #f1f5f9; text-decoration:none; color:var(--text-secondary); background:<?php echo $isUnread ? '#f0f9ff' : '#fff'; ?>;">
                                            <div style="display:flex; justify-content:space-between; align-items:center;">
                                                <strong style="font-size:12px; display:block; color:#0f172a;"><?php echo displayField($notification, ['title']); ?></strong>
                                                <?php if ($isUnread): ?><span class="unread-dot" style="width:8px; height:8px; background:#ef4444; border-radius:50%; display:inline-block;"></span><?php endif; ?>
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
        </div>

        <main class="content">

            <!-- HERO -->
            <div class="hero-card">
                <div>
                    <h2><i class="fa-solid fa-building me-1"></i> Rental Details</h2>
                    <p><?php echo h($r['business_name']); ?></p>
                    <span class="tracking-chip"><i class="fa-solid fa-barcode me-1"></i> <?php echo h($r['tracking_no']); ?></span>
                </div>
                <div class="hero-meta">
                    <a href="rentals.php" class="btn-back"><i class="fa-solid fa-arrow-left me-1"></i> Back</a>
                </div>
            </div>

            <!-- STAFF REMARKS -->
            <?php
                $staffRemarks = $r['staff_remarks'] ?? $r['remarks'] ?? $r['feedback'] ?? '';
                if (!empty($staffRemarks)):
            ?>
            <div class="staff-remarks-box">
                <div class="staff-remarks-header">
                    <div class="staff-remarks-title">
                        <i class="fa-solid fa-comments"></i> LGU Verification &amp; Remarks (Direct to Owner)
                    </div>
                    <div><?php echo statusBadge($r['status'] ?? 'Pending'); ?></div>
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:700; color:#bae6fd; margin-bottom:8px; text-transform:uppercase;">
                        <i class="fa-solid fa-pen-to-square me-1"></i> Remarks / Feedback from LGU Staff:
                    </label>
                    <div class="staff-remarks-content"><?php echo h($staffRemarks); ?></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- BUSINESS INFO -->
            <div class="panel">
                <div class="panel-header"><span><i class="fa-solid fa-id-card me-1"></i> Business Information</span></div>
                <div class="panel-body">
                    <div class="info-grid">
                        <div class="info-item"><label>Rental Name</label><p><?php echo h($r['rental_name']); ?></p></div>
                        <div class="info-item"><label>Business Name</label><p><?php echo h($r['business_name']); ?></p></div>
                        <div class="info-item"><label>Rental Type</label><p><?php echo h($r['type_name']); ?></p></div>
                        <div class="info-item"><label>Floor Area</label><p><?php echo h($r['floor_area']); ?> sqm</p></div>
                    </div>
                </div>
            </div>

            <!-- LOCATION & CONTACT -->
            <div class="panel">
                <div class="panel-header"><span><i class="fa-solid fa-location-dot me-1"></i> Location &amp; Contact</span></div>
                <div class="panel-body">
                    <div class="info-grid">
                        <div class="info-item full"><label>Address</label><p><?php echo h($r['business_address']); ?></p></div>
                        <div class="info-item"><label>Barangay</label><p><?php echo h($r['barangay']); ?></p></div>
                        <div class="info-item"><label>Contact Number</label><p><?php echo h($r['contact_number']); ?></p></div>
                        <div class="info-item"><label>Email</label><p><?php echo h($r['email']); ?></p></div>
                    </div>
                </div>
            </div>

            <!-- BUSINESS PERMITS -->
            <div class="panel">
                <div class="panel-header">
                    <span><i class="fa-solid fa-file-shield me-1"></i> Business Permits &amp; Certificates</span>
                    <?php if (!empty($businessPermits)): ?>
                        <span style="font-size:11px; font-weight:700; background:#e0f2fe; color:#0369a1; padding:3px 10px; border-radius:999px;">
                            <?php echo count($businessPermits); ?> <?php echo count($businessPermits) === 1 ? 'Permit' : 'Permits'; ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="panel-body">
                    <?php if (empty($businessPermits)): ?>
                        <div class="empty-state">
                            <i class="fa-solid fa-folder-open"></i>
                            <p style="margin:0;">No business permits issued yet for this rental.</p>
                            <small style="display:block; margin-top:6px; color:#94a3b8;">Once the LGU Staff issues your permit, it will appear here automatically.</small>
                        </div>
                    <?php else: ?>
                        <div style="overflow-x:auto;">
                            <table class="permit-table">
                                <thead>
                                    <tr>
                                        <th>Permit No.</th>
                                        <th>Document Title</th>
                                        <th>Type</th>
                                        <th>Date Issued</th>
                                        <th>Validity</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($businessPermits as $permit): ?>
                                        <tr>
                                            <td><strong><?php echo h($permit['business_permit_no'] ?? 'N/A'); ?></strong></td>
                                            <td><?php echo h($permit['title'] ?? 'N/A'); ?></td>
                                            <td><?php echo h($permit['type'] ?? 'N/A'); ?></td>
                                            <td><?php echo !empty($permit['uploaded_at']) ? date("M d, Y", strtotime($permit['uploaded_at'])) : 'N/A'; ?></td>
                                            <td>
                                                <?php
                                                    $expiry = $permit['permit_expiry_date'] ?? null;
                                                    if ($expiry) {
                                                        $isExpired = strtotime($expiry) < time();
                                                        $badgeClass = $isExpired ? 'rejected' : 'active';
                                                        echo '<span class="status-badge ' . $badgeClass . '">' . ($isExpired ? 'EXPIRED' : 'ACTIVE') . '</span>';
                                                        echo '<br><small style="color:#64748b; font-size:11px;">Until ' . date("M d, Y", strtotime($expiry)) . '</small>';
                                                    } else {
                                                        echo '<span class="status-badge secondary">N/A</span>';
                                                    }
                                                ?>
                                            </td>
                                            <td>
                                                <?php
                                                    $permitStatus = $permit['permit_status'] ?? 'Pending';
                                                    $statusClass = 'secondary';
                                                    if ($permitStatus === 'Approved' || $permitStatus === 'Active') $statusClass = 'active';
                                                    elseif ($permitStatus === 'Pending') $statusClass = 'pending';
                                                    elseif ($permitStatus === 'Rejected' || $permitStatus === 'Expired') $statusClass = 'rejected';
                                                ?>
                                                <span class="status-badge <?php echo $statusClass; ?>"><?php echo h($permitStatus); ?></span>
                                            </td>
                                            <td>
                                                <?php
                                                    $folderPath = $permit['owner_folder_path'] ?? '';
                                                    $storedName = $permit['stored_name'] ?? '';
                                                    $fileName = $permit['file_name'] ?? '';
                                                    $filePath = '';
                                                    if (!empty($folderPath) && !empty($storedName)) $filePath = '../' . $folderPath . '/' . $storedName;
                                                    elseif (!empty($storedName)) $filePath = '../uploads/documents/' . $storedName;
                                                    elseif (!empty($fileName)) $filePath = '../uploads/documents/' . $fileName;
                                                ?>
                                                <?php if (!empty($filePath)): ?>
                                                    <a href="<?php echo h($filePath); ?>" class="btn-view-permit" target="_blank">
                                                        <i class="fa-solid fa-eye"></i> View
                                                    </a>
                                                <?php else: ?>
                                                    <span style="color:#94a3b8; font-size:11.5px; font-style:italic;">No file</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- FINANCIAL & DATES -->
            <div class="panel">
                <div class="panel-header"><span><i class="fa-solid fa-file-lines me-1"></i> Financial &amp; Registry Dates</span></div>
                <div class="panel-body">
                    <div class="info-grid">
                        <div class="info-item"><label>Monthly Rate</label><p><span class="amount">₱<?php echo number_format((float) $r['monthly_rate'], 2); ?></span></p></div>
                        <div class="info-item"><label>Registration Date</label><p><?php echo formatDate($r['registration_date']); ?></p></div>
                    </div>
                </div>
            </div>

            <!-- STATUS -->
            <div class="panel">
                <div class="panel-header"><span><i class="fa-solid fa-clipboard-check me-1"></i> Status &amp; Compliance</span></div>
                <div class="panel-body">
                    <div class="info-grid">
                        <div class="info-item"><label>Status</label><p><?php echo statusBadge($r['status'] ?? ''); ?></p></div>
                        <div class="info-item"><label>Compliance</label><p><?php echo statusBadge($r['compliance_status'] ?? ''); ?></p></div>
                        <div class="info-item full">
                            <label>Description</label>
                            <p><?php echo nl2br(h($r['description'] ?? '—')); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- INSPECTIONS -->
            <div class="panel">
                <div class="panel-header">
                    <span><i class="fa-solid fa-clipboard-list me-1"></i> Site Inspection History</span>
                    <?php if (!empty($inspections)): ?>
                        <span style="font-size:11px; font-weight:700; background:#e0f2fe; color:#0369a1; padding:3px 10px; border-radius:999px;">
                            <?php echo count($inspections); ?> <?php echo count($inspections) === 1 ? 'Record' : 'Records'; ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="panel-body">
                    <?php if (empty($inspections)): ?>
                        <div class="empty-state">
                            <i class="fa-solid fa-clipboard"></i>
                            <p style="margin:0;">No site inspections recorded yet for this property.</p>
                            <a href="inspections.php" class="quick-link-btn quick-link-primary" style="margin-top:12px;">
                                <i class="fa-solid fa-plus"></i> Request Inspection
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="inspection-list">
                            <?php foreach ($inspections as $insp):
                                $inspDate = $insp['inspection_date'] ?? '';
                                $inspTime = $insp['inspection_time'] ?? '';
                                $inspType = getInspectionType($insp['inspection_type'] ?? '');
                                $inspCategory = $hasInspectionCategory ? getInspectionCategory($insp['inspection_category'] ?? '') : '';

                                $inspStatus = '';
                                if ($hasInspectionStatus) $inspStatus = $insp['inspection_status'] ?? 'Scheduled';
                                elseif ($hasStatus) $inspStatus = $insp['status'] ?? 'Scheduled';
                                else $inspStatus = 'Scheduled';
                                $statusClass = getInspectionStatusClass($inspStatus);

                                $inspector = 'LGU Inspector';
                                if ($hasInspectorName && !empty($insp['inspector_name'])) $inspector = $insp['inspector_name'];

                                $inspRemarks = $insp['remarks'] ?? $insp['staff_remarks'] ?? '';
                                $dateObj = !empty($inspDate) ? strtotime($inspDate) : false;
                                $month = $dateObj ? date('M', $dateObj) : '---';
                                $day   = $dateObj ? date('d', $dateObj) : '--';
                                $year  = $dateObj ? date('Y', $dateObj) : '';
                            ?>
                                <div class="inspection-item">
                                    <div class="inspection-date-box">
                                        <span class="month"><?php echo $month; ?></span>
                                        <span class="day"><?php echo $day; ?></span>
                                        <?php if ($year): ?><span class="year"><?php echo $year; ?></span><?php endif; ?>
                                    </div>
                                    <div class="inspection-content">
                                        <div class="title-row">
                                            <h4 class="type-label"><?php echo h($inspType); ?></h4>
                                            <span class="status-badge <?php echo $statusClass; ?>"><?php echo h($inspStatus); ?></span>
                                        </div>
                                        <div class="inspection-meta">
                                            <span class="meta-item">
                                                <i class="fa-regular fa-clock"></i>
                                                <span>Time:</span> <strong><?php echo formatTime($inspTime); ?></strong>
                                            </span>
                                            <?php if (!empty($inspCategory) && $inspCategory !== '—'): ?>
                                            <span class="meta-item">
                                                <i class="fa-solid fa-layer-group"></i>
                                                <span>Category:</span> <strong><?php echo h($inspCategory); ?></strong>
                                            </span>
                                            <?php endif; ?>
                                            <span class="meta-item">
                                                <i class="fa-solid fa-user-tie"></i>
                                                <span>Inspector:</span> <strong><?php echo h($inspector); ?></strong>
                                            </span>
                                        </div>
                                        <?php if (!empty($inspRemarks)): ?>
                                            <div class="inspection-remarks">
                                                <strong><i class="fa-solid fa-comment-dots me-1"></i> Remarks</strong>
                                                <?php echo nl2br(h($inspRemarks)); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div style="margin-top:16px; display:flex; gap:8px; flex-wrap:wrap;">
                            <a href="inspections.php" class="quick-link-btn quick-link-outline"><i class="fa-solid fa-list"></i> View All Inspections</a>
                            <a href="inspections.php" class="quick-link-btn quick-link-primary"><i class="fa-solid fa-plus"></i> Request New Inspection</a>
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
    <div id="aiAssistantWrapper" style="position:fixed; bottom:calc(25px + var(--safe-bottom)); right:calc(25px + var(--safe-right)); z-index:1060; font-family:'Plus Jakarta Sans', sans-serif;">
        <button id="aiToggleBtn" style="width:55px; height:55px; border-radius:50%; background:linear-gradient(135deg, #0284c7, #2563eb); border:none; color:#fff; font-size:22px; cursor:pointer; box-shadow:0 10px 25px rgba(2, 132, 199, 0.4); display:flex; align-items:center; justify-content:center;" title="Ask AI Assistant">
            <i class="fa-solid fa-robot"></i>
        </button>
        <div id="aiChatWindow" style="position:absolute; bottom:70px; right:0; width:350px; height:480px; background:#ffffff; border:1px solid var(--card-border); border-radius:18px; box-shadow:0 20px 40px rgba(2, 132, 199, 0.2); display:none; flex-direction:column; overflow:hidden;">
            <div style="background:linear-gradient(135deg, #0284c7, #0369a1); color:#fff; padding:14px 16px; display:flex; align-items:center; justify-content:space-between;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <i class="fa-solid fa-robot" style="font-size:16px; color:#7dd3fc;"></i>
                    <div>
                        <strong style="font-size:14px; display:block; font-family:'Space Grotesk', sans-serif;">RENTRACK AI</strong>
                        <span style="font-size:10px; color:#bae6fd;">Owner LGU Assistant</span>
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
        fetch('?id=<?php echo $id; ?>&ajax_action=clear_notifs')
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('notifBadgeCount')?.remove();
                    document.getElementById('notifHeaderCount')?.remove();
                    const lb = document.getElementById('notifListBody');
                    if (lb) lb.innerHTML = '<div style="padding:24px; text-align:center; color:#94a3b8; font-size:12px;"><i class="fa-solid fa-check-double" style="font-size:20px; margin-bottom:8px; display:block; color:#0284c7;"></i>You\'re all caught up!</div>';
                }
            });
    });

    function markNotificationReadAndRedirect(notifId, redirectUrl, element) {
        fetch('?id=<?php echo $id; ?>&ajax_action=mark_notif_read&id=' + notifId)
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
        modalSearchResults.innerHTML = `<div style="text-align:center; padding:40px; color:#64748b; font-size:13px;"><i class="fa-solid fa-keyboard" style="font-size:28px; margin-bottom:10px; display:block; opacity:0.5; color:var(--accent-blue);"></i>Type at least 2 characters to search records...</div>`;
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
            modalSearchResults.innerHTML = `<div style="text-align:center; padding:40px; color:#64748b; font-size:13px;"><i class="fa-solid fa-keyboard" style="font-size:28px; margin-bottom:10px; display:block; opacity:0.5; color:var(--accent-blue);"></i>Type at least 2 characters to search records...</div>`;
            return;
        }
        modalSearchResults.innerHTML = `<div style="text-align:center; padding:30px; color:#64748b;"><i class="fa-solid fa-spinner fa-spin" style="font-size:20px; margin-bottom:8px; color:var(--accent-blue);"></i><div>Searching records...</div></div>`;
        searchTimeout = setTimeout(() => {
            fetch('?id=<?php echo $id; ?>&ajax_action=search&q=' + encodeURIComponent(query))
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

        fetch('?id=<?php echo $id; ?>&ajax_action=ai_chat&msg=' + encodeURIComponent(text))
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

    /* LOGOUT */
    function confirmLogout() {
        Swal.fire({
            title: 'Logout from RENTRACK?',
            text: 'Are you sure you want to log out?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#0284c7',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, Logout',
            background: '#ffffff', color: '#0f172a'
        }).then((res) => { if (res.isConfirmed) window.location.href = '../logout.php'; });
    }
    document.getElementById('profileLogoutBtn')?.addEventListener('click', confirmLogout);
    </script>
</body>
</html>