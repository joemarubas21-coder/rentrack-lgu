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

$userId = (int) ($_SESSION['user_id'] ?? 0);
$userName = $_SESSION['fullname'] ?? 'Owner';
$userInitial = strtoupper(substr($userName, 0, 1));

/* Helpers */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function format_currency($n){ return '₱' . number_format((float)$n, 2); }
function format_date($d){ if (empty($d) || $d === '0000-00-00' || $d === null) return '—'; $dt = new DateTime($d); return $dt->format('M d, Y'); }

function status_badge_html($status){
    $s = strtolower((string)$status);
    $class = 'pending';
    if ($s === 'active' || $s === 'approved' || $s === 'completed') $class = 'active';
    elseif ($s === 'expired' || $s === 'pending') $class = 'pending';
    elseif (in_array($s, ['suspended','revoked','rejected','cancelled'])) $class = 'rejected';
    return "<span class=\"status-badge {$class}\">" . h($status) . "</span>";
}

function compliance_badge_html($c){
    $s = strtolower((string)$c);
    $class = 'pending';
    if (in_array($s, ['compliant','yes','complete'])) $class = 'active';
    elseif (in_array($s, ['non-compliant','no','action required'])) $class = 'rejected';
    return "<span class=\"status-badge {$class}\">" . h($c) . "</span>";
}

function statusClass($status) {
    $status = strtolower(trim($status));
    switch ($status) {
        case 'approved': return 'approved';
        case 'active': return 'active';
        case 'completed': return 'completed';
        case 'rejected': case 'cancelled': return 'rejected';
        default: return 'pending';
    }
}

/* Table & Column Existence Checks */
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

/* MARK NOTIFICATION AS READ AJAX HANDLER */
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'mark_notif_read') {
    header('Content-Type: application/json; charset=utf-8');
    $notifId = (int) ($_GET['id'] ?? 0);
    if ($userId > 0 && $notifId > 0 && tableExists($conn, 'notifications')) {
        try {
            $stmtUpdate = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE id = :nid AND user_id = :uid");
            $stmtUpdate->execute([':nid' => $notifId, ':uid' => $userId]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => safe_error($e)]);
        }
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

/* CLEAR / DELETE NOTIFICATIONS AJAX HANDLER */
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'clear_notifs') {
    header('Content-Type: application/json; charset=utf-8');
    if ($userId > 0 && tableExists($conn, 'notifications')) {
        try {
            $stmtDelete = $conn->prepare("DELETE FROM notifications WHERE user_id = :uid");
            $stmtDelete->execute([':uid' => $userId]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => safe_error($e)]);
        }
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

/* AI ASSISTANT AJAX HANDLER */
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'ai_chat') {
    header('Content-Type: application/json; charset=utf-8');
    $question = trim(strtolower($_GET['msg'] ?? ''));

    $totalRentals = 0;
    $activeRentals = 0;
    $totalPaid = 0;
    $withPermit = 0;

    if (tableExists($conn, 'rentals')) {
        $stmtRentals = $conn->prepare("SELECT COUNT(*) FROM rentals WHERE user_id = :uid AND is_deleted = '0'");
        $stmtRentals->execute([':uid' => $userId]);
        $totalRentals = (int)$stmtRentals->fetchColumn();

        if (columnExists($conn, 'rentals', 'status')) {
            if (columnExists($conn, 'rentals', 'compliance_status')) {
                $stmtActive = $conn->prepare("SELECT COUNT(*) FROM rentals WHERE user_id = :uid AND (LOWER(status::text) = 'active' OR (LOWER(status::text) = 'approved' AND LOWER(compliance_status::text) = 'compliant')) AND is_deleted = '0'");
            } else {
                $stmtActive = $conn->prepare("SELECT COUNT(*) FROM rentals WHERE user_id = :uid AND LOWER(status::text) = 'active' AND is_deleted = '0'");
            }
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
        $reply = "You currently have a total of {$totalRentals} registered rentals, with {$activeRentals} active properties under your account.";
    } elseif (strpos($question, 'payment') !== false || strpos($question, 'paid') !== false || strpos($question, 'magkano') !== false) {
        $reply = "Your total payments received across all properties is ₱" . number_format($totalPaid, 2) . ".";
    } elseif (strpos($question, 'permit') !== false || strpos($question, 'business') !== false) {
        $reply = "You have {$withPermit} rentals equipped with a valid business permit.";
    } elseif (strpos($question, 'inspection') !== false || strpos($question, 'schedule') !== false) {
        $reply = "You can check your upcoming visits and property checkups directly in the Inspection Schedule page.";
    } elseif (strpos($question, 'hello') !== false || strpos($question, 'hi') !== false) {
        $reply = "Hello! How can I help you manage your properties and compliance today?";
    } else {
        $reply = "As your LGU Assistant, I found that you have {$totalRentals} rentals and ₱" . number_format($totalPaid, 2) . " total payments. You can check your My Rentals or Payments page for more details.";
    }

    echo json_encode(['reply' => $reply]);
    exit;
}

/* AJAX SEARCH HANDLER */
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'search') {
    $query = trim($_GET['q'] ?? '');
    if (strlen($query) >= 2 && tableExists($conn, 'rentals')) {
        $searchTerm = '%' . $query . '%';
        $sql = "SELECT id, tracking_no, business_name, status, rental_name 
                FROM rentals 
                WHERE user_id = :uid AND is_deleted = '0' 
                  AND (tracking_no ILIKE :q1 OR business_name ILIKE :q2 OR rental_name ILIKE :q3)
                LIMIT 5";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':uid' => $userId, ':q1' => $searchTerm, ':q2' => $searchTerm, ':q3' => $searchTerm]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($rows)) {
            echo '<div style="display: flex; flex-direction: column; gap: 8px;">';
            foreach ($rows as $row) {
                $status = $row['status'] ?? 'Draft';
                $badgeBg = 'rgba(220,38,38,0.1)'; $badgeColor = '#dc2626';
                if (strtolower($status) === 'pending') { $badgeBg = 'rgba(217,119,6,0.15)'; $badgeColor = '#d97706'; }
                elseif (strtolower($status) === 'approved' || strtolower($status) === 'active') { $badgeBg = 'rgba(5,150,105,0.15)'; $badgeColor = '#059669'; }

                echo '<a href="view_rental.php?id=' . $row['id'] . '" style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; color: #0f172a; text-decoration: none;">';
                echo '<div style="display: flex; flex-direction: column; gap: 4px;">';
                echo '<div style="font-weight: 700; font-size: 14px; color: #dc2626;">' . htmlspecialchars($row['tracking_no'] ?? '#'.$row['id']) . ' <span style="color: #94a3b8;">•</span> ' . htmlspecialchars($row['business_name'] ?: $row['rental_name']) . '</div>';
                echo '<div style="font-size: 12px; color: #64748b;">Property: ' . htmlspecialchars($row['rental_name'] ?? 'N/A') . '</div>';
                echo '</div>';
                echo '<span style="padding: 4px 10px; border-radius: 20px; font-size: 10px; font-weight: 700; background: ' . $badgeBg . '; color: ' . $badgeColor . '; text-transform: uppercase;">' . htmlspecialchars($status) . '</span>';
                echo '</a>';
            }
            echo '</div>';
        } else {
            echo '<div style="text-align: center; padding: 30px; color: #64748b; font-size: 13px;"><i class="fa-solid fa-folder-open" style="font-size: 24px; margin-bottom: 8px; display: block; opacity: 0.5;"></i> No matching rental records found.</div>';
        }
    }
    exit;
}

/* Notifications */
$notifications = [];
$unreadNotifCount = 0;
if (tableExists($conn, 'notifications')) {
    if (columnExists($conn, 'notifications', 'is_read')) {
        try {
            $stmtNotifCount = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE (LOWER(is_read::text) IN ('0', 'false')) AND user_id = :uid");
            $stmtNotifCount->execute([':uid' => $userId]);
            $unreadNotifCount = (int) $stmtNotifCount->fetchColumn();
        } catch (PDOException $e) {}
    }
    $stmtNotifList = $conn->prepare("SELECT * FROM notifications WHERE user_id = :uid ORDER BY created_at DESC LIMIT 5");
    $stmtNotifList->execute([':uid' => $userId]);
    $notifications = $stmtNotifList->fetchAll(PDO::FETCH_ASSOC);
}

/* Calculate Active Rentals (Consistent with Dashboard) */
$activeRentalsCount = 0;
if (tableExists($conn, 'rentals')) {
    try {
        if (columnExists($conn, 'rentals', 'compliance_status')) {
            $stmtActive = $conn->prepare("SELECT COUNT(*) FROM rentals WHERE user_id = :uid AND (LOWER(status::text) = 'active' OR (LOWER(status::text) = 'approved' AND LOWER(compliance_status::text) = 'compliant')) AND is_deleted = '0'");
        } else {
            $stmtActive = $conn->prepare("SELECT COUNT(*) FROM rentals WHERE user_id = :uid AND LOWER(status::text) = 'active' AND is_deleted = '0'");
        }
        $stmtActive->execute([':uid' => $userId]);
        $activeRentalsCount = (int)$stmtActive->fetchColumn();
    } catch (PDOException $e) {
        $activeRentalsCount = 0; 
    }
}

/* Filters */
$q = trim($_GET['q'] ?? '');
$filter_barangay = trim($_GET['barangay'] ?? '');
$filter_type = isset($_GET['type']) && $_GET['type'] !== '' ? (int)$_GET['type'] : null;
$filter_status = trim($_GET['status'] ?? '');
$filter_compliance = trim($_GET['compliance'] ?? '');

/* Pagination */
$perPage = isset($_GET['per_page']) ? max(5, min(100, (int)$_GET['per_page'])) : 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $perPage;

/* Rental types */
$rental_types = [];
$stmtTypes = $conn->query("SELECT id, type_name FROM rental_types ORDER BY type_name");
if ($stmtTypes) $rental_types = $stmtTypes->fetchAll(PDO::FETCH_ASSOC);

/* Barangays */
$barangays = [];
$stmtBrgy = $conn->prepare("SELECT DISTINCT barangay FROM rentals WHERE is_deleted = '0' AND user_id = :uid AND barangay IS NOT NULL AND barangay <> '' ORDER BY barangay");
$stmtBrgy->execute([':uid' => $userId]);
while ($r = $stmtBrgy->fetch(PDO::FETCH_ASSOC)) $barangays[] = $r['barangay'];

/* Build query */
$where = "r.is_deleted = '0' AND r.user_id = :uid";
$params = [':uid' => $userId];

if ($q !== '') {
    $where .= " AND (r.business_name LIKE :q OR r.tracking_no LIKE :q_track)";
    $params[':q'] = '%' . $q . '%';
    $params[':q_track'] = '%' . $q . '%';
}
if ($filter_barangay !== '') {
    $where .= " AND r.barangay = :brgy";
    $params[':brgy'] = $filter_barangay;
}
if ($filter_type !== null) {
    $where .= " AND r.rental_type_id = :rtype";
    $params[':rtype'] = $filter_type;
}
if ($filter_status !== '') {
    $statusLower = strtolower($filter_status);
    
    if ($statusLower === 'active' && columnExists($conn, 'rentals', 'compliance_status')) {
        $where .= " AND (
            LOWER(r.status::text) = 'active' 
            OR (LOWER(r.status::text) = 'approved' AND LOWER(r.compliance_status::text) = 'compliant')
        )";
    } else {
        $where .= " AND LOWER(r.status::text) = :st";
        $params[':st'] = $statusLower;
    }
}
if ($filter_compliance !== '') {
    $where .= " AND LOWER(r.compliance_status::text) = :comp";
    $params[':comp'] = strtolower($filter_compliance);
}

/* Count */
$countSql = "SELECT COUNT(*) FROM rentals r LEFT JOIN rental_types rt ON r.rental_type_id = rt.id WHERE $where";
$stmtCount = $conn->prepare($countSql);
$stmtCount->execute($params);
$total = (int) $stmtCount->fetchColumn();

/* Documents permit columns */
$hasDocuments = tableExists($conn, 'documents');
$hasPermitNoCol = $hasDocuments && columnExists($conn, 'documents', 'business_permit_no');
$hasPermitExpCol = $hasDocuments && columnExists($conn, 'documents', 'permit_expiry_date');

$docsJoin = '';
$docsSelect = "NULL AS doc_permit_no, NULL AS doc_permit_expiry";
if ($hasPermitNoCol && $hasPermitExpCol) {
    $docsJoin = "LEFT JOIN LATERAL (
        SELECT d.business_permit_no, d.permit_expiry_date
        FROM documents d
        WHERE d.rental_id = r.id AND d.business_permit_no IS NOT NULL
        ORDER BY 
            CASE 
                WHEN d.title ILIKE '%Business Permit%' THEN 0
                WHEN d.title ILIKE '%Sanitary%' THEN 1
                WHEN d.title ILIKE '%Fire Safety%' THEN 2
                WHEN d.title ILIKE '%Barangay%' THEN 3
                WHEN d.title ILIKE '%Health%' THEN 4
                WHEN d.title ILIKE '%Lease%' THEN 5
                WHEN d.title ILIKE '%Occupancy%' THEN 6
                ELSE 7
            END,
            d.uploaded_at DESC
        LIMIT 1
    ) d ON TRUE";
    $docsSelect = "d.business_permit_no AS doc_permit_no, d.permit_expiry_date AS doc_permit_expiry";
}

/* Main query */
$sql = "SELECT r.*, COALESCE(rt.type_name,'—') AS type_name, $docsSelect
        FROM rentals r
        LEFT JOIN rental_types rt ON r.rental_type_id = rt.id
        $docsJoin
        WHERE $where
        ORDER BY r.created_at DESC
        LIMIT :limit OFFSET :offset";

$stmtRows = $conn->prepare($sql);
foreach ($params as $key => $val) $stmtRows->bindValue($key, $val);
$stmtRows->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmtRows->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmtRows->execute();
$rows = $stmtRows->fetchAll(PDO::FETCH_ASSOC);

// 🔥 AUTO-UPDATE COMPLIANCE STATUS BASE SA PERMIT AT RENTAL STATUS
date_default_timezone_set('Asia/Manila');
$todayStr = date('Y-m-d');

foreach ($rows as &$row) {
    $rentalStatus = strtolower(trim($row['status'] ?? ''));
    
    $permitExpiry = !empty($row['doc_permit_expiry']) 
        ? $row['doc_permit_expiry'] 
        : ($row['permit_expiry'] ?? null);
    
    $expiryStr = !empty($permitExpiry) ? date('Y-m-d', strtotime($permitExpiry)) : null;
    
    if (in_array($rentalStatus, ['approved', 'active'])) {
        if ($expiryStr && $expiryStr < $todayStr) {
            $row['compliance_status'] = 'Non-Compliant';
        } else {
            $row['compliance_status'] = 'Compliant';
        }
    } elseif (in_array($rentalStatus, ['pending', 'draft'])) {
        $row['compliance_status'] = 'Pending';
    } elseif ($expiryStr && $expiryStr < $todayStr) {
        $row['compliance_status'] = 'Non-Compliant';
    } else {
        $row['compliance_status'] = 'Pending';
    }
}
unset($row);

$totalPages = max(1, ceil($total / $perPage));
function build_query($overrides = []) {
    $qarr = array_merge($_GET, $overrides);
    return http_build_query($qarr);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#dc2626">
    <title>My Rentals | RENTRACK LGU</title>

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
            --ok: #059669; --ok-bg: #d1fae5;
            --warn: #d97706; --warn-bg: #fef3c7;
            --danger: #dc2626; --danger-bg: #fee2e2;
            --info: #2563eb; --info-bg: #dbeafe;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --safe-top: env(safe-area-inset-top, 0px);
            --safe-bottom: env(safe-area-inset-bottom, 0px);
            --safe-left: env(safe-area-inset-left, 0px);
            --safe-right: env(safe-area-inset-right, 0px);
        }

        @keyframes spinRing { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        html, body {
            margin: 0 !important; padding: 0 !important; border: 0 !important;
            height: 100%; font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            background: var(--bg-light); color: var(--text-primary);
            overflow-x: hidden; -webkit-text-size-adjust: 100%;
            -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale;
        }

        * { scrollbar-width: thin; scrollbar-color: rgba(220, 38, 38, 0.4) transparent; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-thumb { background: var(--accent-blue); border-radius: 20px; }

        body.drawer-open { overflow: hidden !important; touch-action: none; }

        .main-wrapper {
            flex: 1; display: flex; flex-direction: column;
            min-height: 100vh; min-height: 100dvh;
            margin: 0 !important; padding: 0 !important;
            background: var(--bg-light);
            padding-left: var(--safe-left); padding-right: var(--safe-right);
        }

        /* 1. TOP OFFICIAL BAR */
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
            background: rgba(255, 255, 255, 0.15); border: 1px solid rgba(255, 255, 255, 0.3);
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
                gap: 3px 8px; padding: 6px 12px; text-align: left; align-items: center;
            }
            .official-header-left, .official-header-right-group { display: contents; }
            .oh-title { grid-area: title; min-width: 0; gap: 6px; font-size: 10px; white-space: nowrap; }
            .oh-title span { overflow: hidden; text-overflow: ellipsis; }
            .official-header-badge { grid-area: badge; font-size: 9px; padding: 2px 8px; gap: 4px; white-space: nowrap; }
            .oh-sep, .oh-city, .oh-portal { display: none; }
            .oh-sub { grid-area: sub; font-size: 9px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        }
        @media (max-width: 380px) {
            .official-header { padding: 5px 8px; padding-top: calc(5px + var(--safe-top)); }
            .oh-title { font-size: 8.5px; gap: 4px; }
            .official-header-badge { font-size: 8px; padding: 2px 6px; }
            .oh-sub { font-size: 8px; }
        }

        /* 2. STICKY HEADER */
        .sticky-header { position: sticky; top: 0; z-index: 100; background: #ffffff; }

        /* 3. TOP NAVBAR */
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
            color: var(--accent-blue); cursor: pointer;
            font-size: 16px; flex-shrink: 0;
        }
        .menu-toggle:active { background: var(--accent-blue); color: #fff; border-color: var(--accent-blue); }

        /* NAV MENU */
        .main-nav-bar {
            display: flex; align-items: center;
            background: transparent; border: none; padding: 0;
            position: relative; flex-shrink: 0; z-index: 45;
        }
        .nav-menu {
            list-style: none; margin: 0; padding: 0;
            display: flex; align-items: center; gap: 2px; flex-wrap: nowrap;
        }
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
            color: #334155; text-decoration: none;
            font-size: 13px; font-weight: 500;
            transition: all 0.15s ease;
        }
        .nav-dropdown a:hover { background: var(--blue-soft); color: var(--accent-blue); }
        .nav-dropdown a i { width: 16px; color: var(--accent-blue); font-size: 14px; text-align: center; flex-shrink: 0; }

        .mobile-drawer-header { display: none; }

        /* SEARCH */
        .search-wrapper {
            flex: 1 1 auto; display: flex; justify-content: center;
            min-width: 140px; max-width: 380px;
            padding: 0 8px; margin: 0 auto;
        }
        .search-container { position: relative; width: 100%; cursor: pointer; }
        .search-container i {
            position: absolute; left: 13px; top: 50%;
            transform: translateY(-50%);
            color: var(--accent-blue); z-index: 2; font-size: 13px;
        }
        .search-container input {
            width: 100%; padding: 8px 14px 8px 38px;
            border-radius: 999px; border: 1px solid var(--card-border);
            background: #f8fafc; color: #0f172a;
            font-size: 13px; outline: none; cursor: pointer;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }
        .search-container input::placeholder { color: var(--text-faint); }

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
        .profile-btn:hover { background: var(--blue-soft); }
        .profile-btn .avatar {
            width: 32px; height: 32px; border-radius: 50%;
            background: var(--accent-blue);
            display: flex; align-items: center; justify-content: center;
            color: #ffffff; font-weight: 800; font-size: 13px; overflow: hidden;
        }
        .profile-btn .info .name { font-size: 12px; font-weight: 600; color: #0f172a; }
        .profile-btn .info .role { font-size: 9px; color: var(--accent-blue); font-weight: 700; text-transform: uppercase; }

        .profile-dropdown, .notification-dropdown {
            position: absolute; top: calc(100% + 12px); right: 0;
            width: 240px; background: #ffffff; border: 1px solid var(--card-border);
            border-radius: 14px; padding: 8px;
            box-shadow: 0 16px 36px rgba(220, 38, 38, 0.15);
            opacity: 0; visibility: hidden;
            transform: translateY(-10px) scale(0.95);
            transition: all 0.25s ease; z-index: 60;
        }
        .notification-dropdown { width: 360px; padding: 0; }
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
        .page-head { margin-bottom: 16px; }
        .page-head h1 { font-family: 'Space Grotesk', sans-serif; font-size: 22px; font-weight: 800; color: #0f172a; margin: 0; }
        .page-head p { font-size: 13px; color: #64748b; margin: 2px 0 0; }

        .hero-card {
            display: flex; justify-content: space-between; align-items: center; gap: 1rem;
            padding: 22px 26px; border-radius: 18px; color: #ffffff;
            background: linear-gradient(135deg, #dc2626, #991b1b);
            box-shadow: 0 8px 22px rgba(220, 38, 38, 0.2); margin-bottom: 18px;
        }
        .hero-card h2 { font-family: 'Space Grotesk', sans-serif; font-size: 22px; font-weight: 800; color: #ffffff; margin: 0; }
        .hero-card p { color: #fee2e2; font-size: 13.5px; margin-top: 4px; margin-bottom: 0; }
        .hero-card .btn-primary {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 22px; border-radius: 999px; border: none;
            background: #ffffff; color: #dc2626; font-weight: 800; font-size: 13px; text-decoration: none;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.1); transition: var(--transition);
        }
        .hero-card .btn-primary:hover { transform: translateY(-2px); background: #fef2f2; color: #991b1b; }

        /* ACTIVE RENTAL CARD */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
            margin-bottom: 18px;
        }
        .stat-card-green {
            background: linear-gradient(135deg, #059669, #047857);
            border-radius: 16px;
            padding: 20px 24px;
            color: #ffffff;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 6px 18px rgba(5, 150, 105, 0.25);
            position: relative;
            overflow: hidden;
        }
        .stat-card-green .stat-info h4 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #a7f3d0;
            margin-bottom: 8px;
        }
        .stat-card-green .stat-info .value {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 36px;
            font-weight: 800;
            line-height: 1;
            color: #ffffff;
        }
        .stat-card-green .stat-icon {
            width: 56px;
            height: 56px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: #ffffff;
            backdrop-filter: blur(4px);
        }

        .panel {
            background: var(--bg-card); border: 1px solid var(--card-border);
            border-radius: 18px; overflow: hidden; box-shadow: 0 4px 15px rgba(220, 38, 38, 0.05);
        }
        .panel-header {
            padding: 18px 22px; border-bottom: 1px solid var(--card-border);
            display: flex; justify-content: space-between; align-items: center;
            font-family: 'Space Grotesk', sans-serif; font-size: 16px; font-weight: 700; color: #0f172a;
            flex-wrap: wrap; gap: 12px;
        }
        .record-count { font-size: 11.5px; font-weight: 600; color: #64748b; background: #f8fafc; border: 1px solid var(--card-border); padding: 4px 10px; border-radius: 999px; }

        .filter-bar { padding: 16px 22px 20px; border-bottom: 1px solid var(--card-border); background: #f8fafc; }
        .filter-form { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 14px; }
        .filter-group { display: flex; flex-direction: column; gap: 6px; }
        .filter-group label { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; }
        .filter-group.search-group { flex: 1 1 260px; min-width: 220px; }
        .filter-group.type-group, .filter-group.barangay-group { flex: 1 1 170px; min-width: 150px; }
        .filter-group.status-group, .filter-group.compliance-group { flex: 1 1 160px; min-width: 140px; }
        .search-input-wrap { position: relative; }
        .search-input-wrap i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 12.5px; pointer-events: none; }
        .search-input-wrap input { padding-left: 34px !important; }
        .filter-form .form-control, .filter-form .form-select {
            width: 100%; padding: 9px 12px; border-radius: 10px; border: 1px solid var(--card-border);
            font-size: 12.5px; color: #0f172a; background: #fff; outline: none; transition: var(--transition);
        }
        .filter-form .form-control:focus, .filter-form .form-select:focus { border-color: #dc2626; box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.12); }
        .filter-actions { display: flex; gap: 8px; margin-left: auto; }
        .filter-form .btn-primary {
            padding: 9px 16px; border-radius: 10px; border: none; white-space: nowrap;
            background: #dc2626; color: #fff; font-weight: 700; font-size: 12.5px; cursor: pointer;
            display: inline-flex; align-items: center; gap: 6px; transition: var(--transition);
        }
        .filter-form .btn-primary:hover { background: #991b1b; }
        .filter-form .btn-outline {
            padding: 9px 14px; border-radius: 10px; border: 1px solid var(--card-border); white-space: nowrap;
            background: #fff; color: #dc2626; text-decoration: none; font-size: 12.5px; font-weight: 600;
            display: inline-flex; align-items: center; gap: 6px; transition: var(--transition);
        }
        .filter-form .btn-outline:hover { background: #fee2e2; }

        @media (max-width: 768px) {
            .filter-form { flex-direction: column; align-items: stretch; }
            .filter-group, .filter-actions { width: 100%; }
            .filter-actions { margin-left: 0; }
            .filter-actions .btn-primary, .filter-actions .btn-outline { flex: 1; justify-content: center; }
        }

        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        table th { padding: 10px 16px; text-align: left; color: #64748b; font-size: 10.5px; text-transform: uppercase; border-bottom: 1px solid #e2e8f0; }
        table td { padding: 12px 16px; border-bottom: 1px solid #f1f5f9; color: #334155; vertical-align: middle; }
        table tbody tr:hover { background: #fef2f2; }
        table td strong { color: #0f172a; }

        .status-badge { padding: 4px 10px; border-radius: 999px; font-size: 10.5px; font-weight: 800; text-transform: uppercase; display: inline-block; }
        .status-badge.active { background: #d1fae5; color: #059669; }
        .status-badge.pending { background: #fef3c7; color: #d97706; }
        .status-badge.rejected { background: #ffe4e6; color: #dc2626; }

        .btn-action {
            display: inline-flex; align-items: center; justify-content: center;
            width: 32px; height: 32px; border-radius: 8px; border: 1px solid var(--card-border);
            background: #f8fafc; color: #dc2626; text-decoration: none; transition: var(--transition);
        }
        .btn-action:hover { background: #dc2626; color: #fff; border-color: #dc2626; }

        .pagination-wrapper {
            padding: 14px 22px; border-top: 1px solid var(--card-border);
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;
        }
        .pagination-wrapper .info { font-size: 12.5px; color: #64748b; }
        .pagination { display: flex; gap: 4px; list-style: none; margin: 0; padding: 0; }
        .pagination .page-link {
            display: flex; align-items: center; justify-content: center;
            min-width: 30px; height: 30px; padding: 0 8px;
            border-radius: 8px; border: 1px solid var(--card-border);
            background: #f8fafc; color: #334155; font-size: 12px; font-weight: 600; text-decoration: none; transition: var(--transition);
        }
        .pagination .page-link:hover { background: #fee2e2; color: #dc2626; }
        .pagination .page-item.active .page-link { background: #dc2626; border-color: #dc2626; color: #fff; }
        .pagination .page-item.disabled .page-link { opacity: 0.5; pointer-events: none; }

        .empty-state { text-align: center; padding: 40px 20px; color: #64748b; }
        .empty-state i { font-size: 36px; opacity: 0.5; margin-bottom: 10px; display: block; }

        .dashboard-footer { margin-top: 20px; padding-top: 14px; border-top: 1px solid var(--card-border); display: flex; justify-content: space-between; font-size: 11.5px; color: var(--text-muted); flex-wrap: wrap; gap: 10px; }

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
            box-shadow: 0 25px 50px rgba(220, 38, 38, 0.2), 0 0 30px rgba(220, 38, 38, 0.1);
            overflow: hidden; transform: translateY(-20px) scale(0.98); transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .search-modal-overlay.active .search-modal-content { transform: translateY(0) scale(1); }
        .search-modal-header {
            display: flex; align-items: center; padding: 16px 20px;
            border-bottom: 1px solid var(--card-border); background: #f8fafc;
        }
        .search-modal-header i.search-icon-main { font-size: 16px; color: var(--accent-blue); margin-right: 14px; }
        .search-modal-input { flex: 1; background: transparent; border: none; outline: none; color: #0f172a; font-size: 15px; font-weight: 600; }
        .search-modal-input::placeholder { color: var(--text-faint); }
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
            .nav-link .nav-ico { font-size: 11px; }
        }

        @media (max-width: 1200px) {
            .top-navbar {
                padding: 10px 14px; padding-top: calc(10px + var(--safe-top));
                gap: 10px; flex-wrap: wrap; min-height: 60px;
            }
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
                color: #fff; flex-shrink: 0;
                border-bottom: 2px solid #7f1d1d;
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
                background: rgba(255,255,255,0.18);
                border: 1px solid rgba(255,255,255,0.35);
                color: #fff; cursor: pointer; font-size: 15px;
                display: flex; align-items: center; justify-content: center;
            }
            .main-nav-bar .nav-menu {
                flex-direction: column; width: 100%;
                justify-content: flex-start; gap: 4px;
                padding: 14px; padding-bottom: calc(20px + var(--safe-bottom));
            }
            .nav-item { width: 100%; }
            .nav-link {
                padding: 14px 16px; border-radius: 10px;
                justify-content: flex-start;
                font-size: 14.5px; min-height: 48px;
            }
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
                background: #f8fafc; margin-top: 4px;
                border-radius: 10px; min-width: 0;
            }
            .nav-item.open .nav-dropdown { max-height: 600px; padding: 8px 0 8px 16px; }
            .nav-dropdown a { padding: 12px 14px; min-height: 44px; font-size: 13.5px; }

            .sidebar-overlay {
                display: none; position: fixed; inset: 0;
                background: rgba(0,0,0,0.55);
                z-index: 1999;
                backdrop-filter: blur(3px);
                -webkit-backdrop-filter: blur(3px);
                opacity: 0; transition: opacity 0.3s ease;
            }
            .sidebar-overlay.active { display: block; opacity: 1; }
            .notification-dropdown { width: calc(100vw - 24px); max-width: 400px; right: -50px; }
        }

        @media (max-width: 768px) {
            .content { padding: 16px 14px 24px; padding-bottom: calc(24px + var(--safe-bottom)); }
            .hero-card { flex-direction: column; align-items: flex-start; gap: 12px; padding: 18px 20px; }
            .hero-card h2 { font-size: 18px; }
            .panel-header { padding: 14px 16px; font-size: 14px; }
            .dashboard-footer { flex-direction: column; text-align: center; gap: 6px; font-size: 10.5px; }
        }
        @media (max-width: 480px) {
            .content { padding: 12px 10px 22px; padding-bottom: calc(22px + var(--safe-bottom)); }
        }
        @media (max-width: 767px) {
            input, select, textarea { font-size: 16px !important; }
            .search-container input, .search-modal-input, .filter-form .form-control, .filter-form .form-select { font-size: 16px !important; }
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
                                <a href="rentals.php"><i class="fa-solid fa-building"></i> My Rentals</a>
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
                                        <a href="javascript:void(0)" class="notif-drop-item" onclick="markNotificationReadAndRedirect(<?php echo (int)$notification['id']; ?>, '<?php echo htmlspecialchars($notifLink); ?>', this)" style="display:block; padding:10px 14px; border-bottom:1px solid #f1f5f9; text-decoration:none; color:var(--text-secondary); background: <?php echo $isUnread ? '#fef2f2' : '#fff'; ?>;">
                                            <div style="display: flex; justify-content: space-between; align-items: center;">
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
                            <i class="fa-solid fa-chevron-down chevron" style="color: #64748b; font-size: 10px;"></i>
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
            <div class="page-head">
                <h1>My Rentals</h1>
                <p>Manage your registered rental properties and business permits.</p>
            </div>

            <div class="hero-card">
                <div>
                    <h2><i class="fa-solid fa-building me-1"></i> My Rentals</h2>
                    <p>Manage your registered rental properties and business permits.</p>
                </div>
                <div>
                    <a href="register_rental.php" class="btn-primary"><i class="fa-solid fa-plus"></i> Register Rental</a>
                </div>
            </div>

            <!-- ACTIVE RENTALS CARD -->
            <div class="stats-grid">
                <div class="stat-card-green">
                    <div class="stat-info">
                        <h4>Active Rentals</h4>
                        <div class="value"><?php echo $activeRentalsCount; ?></div>
                    </div>
                    <div class="stat-icon">
                        <i class="fa-solid fa-key"></i>
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header">
                    <span>My Rental Records</span>
                    <span class="record-count"><?php echo (int)$total; ?> total record<?php echo $total==1?'':'s'; ?></span>
                </div>

                <div class="filter-bar">
                    <form class="filter-form" method="get" action="rentals.php">
                        <div class="filter-group search-group">
                            <label for="fSearch">Search</label>
                            <div class="search-input-wrap">
                                <i class="fa-solid fa-magnifying-glass"></i>
                                <input type="search" id="fSearch" name="q" value="<?php echo h($q); ?>" class="form-control" placeholder="Tracking no. or business name">
                            </div>
                        </div>

                        <div class="filter-group barangay-group">
                            <label for="fBarangay">Barangay</label>
                            <select id="fBarangay" name="barangay" class="form-select">
                                <option value="">All Barangay</option>
                                <?php foreach($barangays as $b): ?>
                                    <option value="<?php echo h($b); ?>" <?php echo $filter_barangay===$b ? 'selected' : ''; ?>><?php echo h($b); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group type-group">
                            <label for="fType">Type</label>
                            <select id="fType" name="type" class="form-select">
                                <option value="">All Types</option>
                                <?php foreach($rental_types as $t): ?>
                                    <option value="<?php echo (int)$t['id']; ?>" <?php echo $filter_type===(int)$t['id'] ? 'selected' : ''; ?>><?php echo h($t['type_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group status-group">
                            <label for="fStatus">Status</label>
                            <select id="fStatus" name="status" class="form-select">
                                <option value="">All Status</option>
                                <option value="active" <?php echo $filter_status==='active'?'selected':''; ?>>Active</option>
                                <option value="expired" <?php echo $filter_status==='expired'?'selected':''; ?>>Expired</option>
                                <option value="suspended" <?php echo $filter_status==='suspended'?'selected':''; ?>>Suspended</option>
                                <option value="revoked" <?php echo $filter_status==='revoked'?'selected':''; ?>>Revoked</option>
                            </select>
                        </div>

                        <div class="filter-group compliance-group">
                            <label for="fCompliance">Compliance</label>
                            <select id="fCompliance" name="compliance" class="form-select">
                                <option value="">All Compliance</option>
                                <option value="compliant" <?php echo $filter_compliance==='compliant'?'selected':''; ?>>Compliant</option>
                                <option value="non-compliant" <?php echo $filter_compliance==='non-compliant'?'selected':''; ?>>Non-compliant</option>
                                <option value="needs-followup" <?php echo $filter_compliance==='needs-followup'?'selected':''; ?>>Needs Follow-up</option>
                            </select>
                        </div>

                        <div class="filter-actions">
                            <button class="btn-primary" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                            <a href="rentals.php" class="btn-outline" title="Reset filters"><i class="fa-solid fa-rotate-left"></i> Reset</a>
                        </div>
                    </form>
                </div>

                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Tracking No.</th>
                                <th>Business / Rental</th>
                                <th>Permit No.</th>
                                <th style="text-align:right;">Monthly</th>
                                <th>Permit Expiry</th>
                                <th>Status</th>
                                <th>Compliance</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(count($rows) > 0): ?>
                                <?php foreach($rows as $row): ?>
                                    <tr>
                                        <td><strong><?php echo h($row['tracking_no']); ?></strong></td>
                                        <td>
                                            <strong><?php echo h($row['business_name']); ?></strong><br>
                                            <span style="font-size:11px; color:#64748b;"><?php echo h($row['barangay'] ?? '—'); ?> &middot; <?php echo h($row['type_name'] ?? '—'); ?></span>
                                        </td>
                                        <td>
                                            <?php 
                                                $displayPermitNo = !empty($row['doc_permit_no']) 
                                                    ? $row['doc_permit_no'] 
                                                    : ($row['business_permit_no'] ?? '');
                                            ?>
                                            <?php if (!empty($displayPermitNo)): 
                                                $prefix = explode('-', $displayPermitNo)[0];
                                                $typeLabelMap = [
                                                    'BP' => 'Business Permit', 'SP' => 'Sanitary Permit', 'FSIC' => 'Fire Safety',
                                                    'BC' => 'Barangay Clearance', 'HC' => 'Health Cert', 'LC' => 'Lease Contract',
                                                    'LCC' => 'Lease Clearance', 'OCP' => 'Occupancy', 'PERMIT' => 'Permit',
                                                ];
                                                $typeLabel = $typeLabelMap[$prefix] ?? 'Permit';
                                            ?>
                                                <span style="font-family:monospace; font-weight:700; color:#991b1b; background:#fee2e2; padding:2px 8px; border-radius:5px; font-size:11.5px; display:inline-block;">
                                                    <?php echo h($displayPermitNo); ?>
                                                </span>
                                                <br><small style="font-size:9.5px; color:#64748b;"><?php echo $typeLabel; ?></small>
                                            <?php else: ?>
                                                <span style="font-style:italic; font-size:11px; color:#94a3b8;">Not Provided</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:right; font-weight:800; color:#dc2626;"><?php echo format_currency($row['monthly_rate'] ?? 0); ?></td>
                                        <td>
                                            <?php 
                                                $displayExpiry = !empty($row['doc_permit_expiry']) 
                                                    ? $row['doc_permit_expiry'] 
                                                    : ($row['permit_expiry'] ?? '');
                                            ?>
                                            <?php if (!empty($displayExpiry)): 
                                                $isExpired = ($displayExpiry < date('Y-m-d'));
                                            ?>
                                                <span style="font-weight:600; color:<?php echo $isExpired ? '#dc2626' : '#15803d'; ?>; font-size:11.5px;">
                                                    <?php echo format_date($displayExpiry); ?>
                                                </span>
                                                <br><span class="status-badge <?php echo $isExpired ? 'rejected' : 'active'; ?>" style="font-size:9px; padding:2px 8px;">
                                                    <?php echo $isExpired ? 'Expired' : 'Active'; ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="font-style:italic; font-size:11px; color:#94a3b8;">No Expiry Set</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo status_badge_html($row['status'] ?? '—'); ?></td>
                                        <td><?php echo compliance_badge_html($row['compliance_status'] ?? '—'); ?></td>
                                        <td>
                                            <div style="display:flex; gap:6px;">
                                                <a href="view_rental.php?id=<?php echo (int)$row['id']; ?>" class="btn-action" title="View"><i class="fa-solid fa-eye"></i></a>
                                                <a href="edit_rental.php?id=<?php echo (int)$row['id']; ?>" class="btn-action" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="empty-state">
                                        <i class="fa-solid fa-folder-open"></i>
                                        <p>No rentals found. Adjust filters or register a new property.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pagination-wrapper">
                    <div class="info">
                        Showing <?php echo ($total==0?0:($offset+1)); ?> to <?php echo min($offset + count($rows), $total); ?> of <?php echo $total; ?> entries
                    </div>
                    <ul class="pagination">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo build_query(['page'=>1]); ?>">First</a>
                        </li>
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo build_query(['page'=>max(1,$page-1)]); ?>">Prev</a>
                        </li>
                        <li class="page-item active"><span class="page-link"><?php echo $page; ?></span></li>
                        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo build_query(['page'=>min($totalPages,$page+1)]); ?>">Next</a>
                        </li>
                        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo build_query(['page'=>$totalPages]); ?>">Last</a>
                        </li>
                    </ul>
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
                <div style="text-align: center; padding: 40px; color: #64748b; font-size: 13px;">
                    <i class="fa-solid fa-keyboard" style="font-size: 28px; margin-bottom: 10px; display: block; opacity: 0.5; color: var(--accent-blue);"></i>
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
    <div id="aiAssistantWrapper" style="position: fixed; bottom: calc(25px + var(--safe-bottom)); right: calc(25px + var(--safe-right)); z-index: 1060; font-family: 'Plus Jakarta Sans', sans-serif;">
        <button id="aiToggleBtn" style="width: 55px; height: 55px; border-radius: 50%; background: linear-gradient(135deg, #dc2626, #991b1b); border: none; color: #fff; font-size: 22px; cursor: pointer; box-shadow: 0 10px 25px rgba(220, 38, 38, 0.4); display: flex; align-items: center; justify-content: center;" title="Ask AI Assistant">
            <i class="fa-solid fa-robot"></i>
        </button>
        <div id="aiChatWindow" style="position: absolute; bottom: 70px; right: 0; width: 350px; height: 480px; background: #ffffff; border: 1px solid var(--card-border); border-radius: 18px; box-shadow: 0 20px 40px rgba(220, 38, 38, 0.2); display: none; flex-direction: column; overflow: hidden;">
            <div style="background: linear-gradient(135deg, #dc2626, #991b1b); color: #fff; padding: 14px 16px; display: flex; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-robot" style="font-size: 16px; color: #fecdd3;"></i>
                    <div>
                        <strong style="font-size: 14px; display: block; font-family: 'Space Grotesk', sans-serif;">RENTRACK AI</strong>
                        <span style="font-size: 10px; color: #fecdd3;">Owner LGU Assistant</span>
                    </div>
                </div>
                <button id="aiCloseBtn" style="background: transparent; border: none; color: #fff; font-size: 16px; cursor: pointer;"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div id="aiChatBody" style="flex: 1; padding: 14px; overflow-y: auto; background: #f8fafc; display: flex; flex-direction: column; gap: 10px; font-size: 12.5px;">
                <div style="background: var(--blue-soft); color: var(--accent-blue-dark); padding: 10px 12px; border-radius: 12px; max-width: 85%; align-self: flex-start; border-bottom-left-radius: 2px;">
                    Hello <?php echo htmlspecialchars($userName); ?>! Ask me about your rentals, payments, permits, or inspections.
                </div>
            </div>
            <div style="padding: 10px; background: #ffffff; border-top: 1px solid var(--card-border); display: flex; gap: 8px;">
                <input type="text" id="aiChatInput" placeholder="Ask e.g., 'How many rentals do I have?'..." style="flex: 1; padding: 8px 12px; border: 1px solid var(--card-border); border-radius: 999px; outline: none; font-size: 12.5px; background: #f8fafc;">
                <button id="aiSendBtn" style="background: var(--accent-blue); border: none; color: #fff; width: 36px; height: 36px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center;"><i class="fa-solid fa-paper-plane" style="font-size: 12px;"></i></button>
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
        link.addEventListener('click', function() {
            if (window.innerWidth <= 1200) closeDrawer();
        });
    });
    window.addEventListener('resize', function() { if (window.innerWidth > 1200) closeDrawer(); });

    /* CLOCK */
    function updateClock() {
        const t = document.getElementById('liveTime');
        if (!t) return;
        const now = new Date();
        t.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
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
        modalSearchResults.innerHTML = `<div style="text-align: center; padding: 40px; color: #64748b; font-size: 13px;"><i class="fa-solid fa-keyboard" style="font-size: 28px; margin-bottom: 10px; display: block; opacity: 0.5; color: var(--accent-blue);"></i>Type at least 2 characters to search records...</div>`;
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
            modalSearchResults.innerHTML = `<div style="text-align: center; padding: 40px; color: #64748b; font-size: 13px;"><i class="fa-solid fa-keyboard" style="font-size: 28px; margin-bottom: 10px; display: block; opacity: 0.5; color: var(--accent-blue);"></i>Type at least 2 characters to search records...</div>`;
            return;
        }
        modalSearchResults.innerHTML = `<div style="text-align: center; padding: 30px; color: #64748b;"><i class="fa-solid fa-spinner fa-spin" style="font-size: 20px; margin-bottom: 8px; color: var(--accent-blue);"></i><div>Searching records...</div></div>`;
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
        aiChatBody.innerHTML += `<div style="background: var(--accent-blue); color: #fff; padding: 10px 12px; border-radius: 12px; max-width: 85%; align-self: flex-end; border-bottom-right-radius: 2px;">${escapeHtml(text)}</div>`;
        aiChatInput.value = '';
        aiChatBody.scrollTop = aiChatBody.scrollHeight;
        const loadingId = 'ai-loading-' + Date.now();
        aiChatBody.innerHTML += `<div id="${loadingId}" style="background: var(--blue-soft); color: var(--accent-blue-dark); padding: 10px 12px; border-radius: 12px; max-width: 85%; align-self: flex-start; border-bottom-left-radius: 2px;"><i class="fa-solid fa-spinner fa-spin"></i> Thinking...</div>`;
        aiChatBody.scrollTop = aiChatBody.scrollHeight;

        fetch('?ajax_action=ai_chat&msg=' + encodeURIComponent(text))
            .then(res => res.json())
            .then(data => {
                document.getElementById(loadingId).remove();
                aiChatBody.innerHTML += `<div style="background: var(--blue-soft); color: var(--accent-blue-dark); padding: 10px 12px; border-radius: 12px; max-width: 85%; align-self: flex-start; border-bottom-left-radius: 2px;">${data.reply}</div>`;
                aiChatBody.scrollTop = aiChatBody.scrollHeight;
            })
            .catch(() => {
                document.getElementById(loadingId).remove();
                aiChatBody.innerHTML += `<div style="background: var(--danger-bg); color: var(--danger); padding: 10px 12px; border-radius: 12px; max-width: 85%; align-self: flex-start; border-bottom-left-radius: 2px;">Sorry, an error occurred.</div>`;
                aiChatBody.scrollTop = aiChatBody.scrollHeight;
            });
    }
    aiSendBtn?.addEventListener('click', sendAiMessage);
    aiChatInput?.addEventListener('keypress', (e) => { if (e.key === 'Enter') sendAiMessage(); });

    function escapeHtml(text) {
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return text.replace(/[&<>"']/g, m => map[m]);
    }

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
            background: '#ffffff',
            color: '#0f172a'
        }).then((res) => { if (res.isConfirmed) window.location.href = '../logout.php'; });
    }
    document.getElementById('profileLogoutBtn')?.addEventListener('click', confirmLogout);
    </script>
</body>
</html>