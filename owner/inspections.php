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
        error_log('[inspections] ' . $e->getMessage());
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
        $reply = "You can check your Compliance page for permit details.";
    } elseif (strpos($question, 'inspection') !== false) {
        $reply = "You can request and monitor inspections from the Site Inspections page.";
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
                echo '<a href="view_rental.php?id='.$row['id'].'" style="display:flex; align-items:center; justify-content:space-between; padding:12px 16px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; color:#0f172a; text-decoration:none; gap:10px;">';
                echo '<div style="display:flex; flex-direction:column; gap:4px; min-width:0; flex:1;">';
                echo '<div style="font-weight:700; font-size:13px; color:#dc2626; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">'.htmlspecialchars($row['tracking_no'] ?? '#'.$row['id']).' <span style="color:#94a3b8;">•</span> '.htmlspecialchars($row['business_name'] ?: $row['rental_name']).'</div>';
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

/* GET USER RENTALS */
$userRentals = [];
$stmtRentals = $conn->prepare("SELECT id, business_name, rental_name, tracking_no FROM rentals WHERE user_id = :uid AND is_deleted = '0' ORDER BY business_name ASC");
$stmtRentals->execute([':uid' => $userId]);
$userRentals = $stmtRentals->fetchAll(PDO::FETCH_ASSOC);

$hasInspectionCategory = columnExists($conn, 'inspections', 'inspection_category');

/* REQUEST SUBMISSION */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_inspection') {
    $requestError = '';
    if (empty($_POST['rental_id'])) {
        $requestError = 'Please select a rental property.';
    } elseif (empty($_POST['inspection_type'])) {
        $requestError = 'Please select an inspection type.';
    } elseif (empty($_POST['preferred_date'])) {
        $requestError = 'Please select a preferred date.';
    } else {
        $rentalId = (int) $_POST['rental_id'];
        $inspectionType = trim($_POST['inspection_type']);
        $inspectionCategory = !empty($_POST['inspection_category']) ? trim($_POST['inspection_category']) : null;
        $preferredDate = trim($_POST['preferred_date']);
        $preferredTime = !empty($_POST['preferred_time']) ? trim($_POST['preferred_time']) : null;
        $remarks = !empty($_POST['remarks']) ? trim($_POST['remarks']) : null;

        $validTypes = ['Initial', 'Annual', 'Follow-up', 'Complaint', 'Re-inspection'];
        if (!in_array($inspectionType, $validTypes, true)) {
            $requestError = 'Invalid inspection type selected.';
        }
        if (empty($requestError)) {
            $verifyStmt = $conn->prepare("SELECT id FROM rentals WHERE id = :rid AND user_id = :uid AND is_deleted = '0'");
            $verifyStmt->execute([':rid' => $rentalId, ':uid' => $userId]);
            if ($verifyStmt->rowCount() === 0) {
                $requestError = 'Invalid rental property selected.';
            } else {
                $dupCheck = $conn->prepare("
                    SELECT id, inspection_date, inspection_type 
                    FROM inspections 
                    WHERE rental_id = :rid 
                      AND inspection_date = :idate
                      AND (inspection_status IS NULL OR inspection_status::text IN ('Pending','Scheduled','Ongoing')) 
                    LIMIT 1
                ");
                $dupCheck->execute([':rid' => $rentalId, ':idate' => $preferredDate]);
                $existingDup = $dupCheck->fetch(PDO::FETCH_ASSOC);

                if ($existingDup) {
                    $existingDate = !empty($existingDup['inspection_date']) 
                        ? date('M d, Y', strtotime($existingDup['inspection_date'])) 
                        : $preferredDate;
                    $requestError = "You already have an active inspection request for this property on {$existingDate}. Please choose a different date or delete the existing request first.";
                }

                if (empty($requestError)) {
                    $defaultInspectorId = null;
                    try {
                        $inspQuery = $conn->query("SELECT id FROM users WHERE role::text = 'inspector' AND status::text = 'Active' LIMIT 1");
                        if ($inspQuery) $defaultInspectorId = $inspQuery->fetchColumn();
                    } catch (PDOException $e) { $defaultInspectorId = null; }

                    if ($hasInspectionCategory) {
                        $insertStmt = $conn->prepare("INSERT INTO inspections (rental_id, inspection_type, inspection_category, inspection_date, inspection_time, inspection_status, remarks, inspector_id, created_at) VALUES (:rid, :itype, :icat, :idate, :itime, 'Pending', :rem, :iid, NOW())");
                        $insertParams = [':rid'=>$rentalId, ':itype'=>$inspectionType, ':icat'=>$inspectionCategory, ':idate'=>$preferredDate, ':itime'=>$preferredTime, ':rem'=>$remarks, ':iid'=>$defaultInspectorId];
                    } else {
                        $insertStmt = $conn->prepare("INSERT INTO inspections (rental_id, inspection_type, inspection_date, inspection_time, inspection_status, remarks, inspector_id, created_at) VALUES (:rid, :itype, :idate, :itime, 'Pending', :rem, :iid, NOW())");
                        $insertParams = [':rid'=>$rentalId, ':itype'=>$inspectionType, ':idate'=>$preferredDate, ':itime'=>$preferredTime, ':rem'=>$remarks, ':iid'=>$defaultInspectorId];
                    }
                    if ($insertStmt->execute($insertParams)) {
                        $_SESSION['flash_message'] = 'success';
                        $_SESSION['flash_text'] = 'Your inspection request has been submitted successfully.';
                        header('Location: ' . $_SERVER['PHP_SELF']);
                        exit();
                    } else {
                        $_SESSION['flash_message'] = 'error';
                        $_SESSION['flash_text'] = 'Failed to submit inspection request.';
                        header('Location: ' . $_SERVER['PHP_SELF']);
                        exit();
                    }
                }
            }
        }
    }
    if (!empty($requestError)) {
        $_SESSION['flash_message'] = 'error';
        $_SESSION['flash_text'] = $requestError;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
}

/* DELETE INSPECTION */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_inspection') {
    $inspectionId = isset($_POST['inspection_id']) ? (int) $_POST['inspection_id'] : 0;
    if ($inspectionId > 0) {
        $verifyStmt = $conn->prepare("SELECT i.id FROM inspections i INNER JOIN rentals r ON i.rental_id = r.id WHERE i.id = :iid AND r.user_id = :uid");
        $verifyStmt->execute([':iid' => $inspectionId, ':uid' => $userId]);
        if ($verifyStmt->rowCount() > 0) {
            $deleteStmt = $conn->prepare("DELETE FROM inspections WHERE id = :iid");
            if ($deleteStmt->execute([':iid' => $inspectionId])) {
                $_SESSION['flash_message'] = 'success';
                $_SESSION['flash_text'] = 'Inspection request deleted.';
            } else {
                $_SESSION['flash_message'] = 'error';
                $_SESSION['flash_text'] = 'Failed to delete inspection.';
            }
        } else {
            $_SESSION['flash_message'] = 'error';
            $_SESSION['flash_text'] = 'You do not have permission to delete this inspection.';
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

$hasAddress = columnExists($conn, 'rentals', 'address');
$hasBarangay = columnExists($conn, 'rentals', 'barangay');
$hasBusinessName = columnExists($conn, 'rentals', 'business_name');
$hasRentalName = columnExists($conn, 'rentals', 'rental_name');
$hasTrackingNo = columnExists($conn, 'rentals', 'tracking_no');

$inspections = [];
$scheduled = 0; $completed = 0; $passed = 0; $failed = 0; $pending = 0; $total = 0; $assignedCount = 0;

$hasInspections = tableExists($conn, 'inspections');
$hasInspectionStatus = columnExists($conn, 'inspections', 'inspection_status');
$hasStatus = columnExists($conn, 'inspections', 'status');
$hasInspectorName = columnExists($conn, 'inspections', 'inspector_name');

if ($hasInspections) {
    $selectFields = "i.*";
    $selectFields .= $hasBusinessName ? ", r.business_name" : "";
    $selectFields .= $hasRentalName ? ", r.rental_name" : "";
    $selectFields .= $hasTrackingNo ? ", r.tracking_no" : "";
    $selectFields .= $hasBarangay ? ", r.barangay" : "";
    if ($hasAddress) $selectFields .= ", r.address";
    $selectFields .= ", u_inspector.fullname AS assigned_inspector_name";
    
    $query = "SELECT $selectFields FROM inspections i INNER JOIN rentals r ON i.rental_id = r.id LEFT JOIN users u_inspector ON i.inspector_id = u_inspector.id WHERE r.user_id = :uid AND r.is_deleted = '0' ORDER BY i.inspection_date DESC, i.id DESC";
    $stmtInsp = $conn->prepare($query);
    $stmtInsp->execute([':uid' => $userId]);
    $inspections = $stmtInsp->fetchAll(PDO::FETCH_ASSOC);

    foreach ($inspections as $row) {
        if ($hasInspectionStatus) $status = strtolower($row['inspection_status'] ?? '');
        elseif ($hasStatus) $status = strtolower($row['status'] ?? '');
        else $status = 'scheduled';
        $total++;
        if (!empty($row['inspector_id']) || !empty($row['assigned_inspector_name'])) $assignedCount++;
        if ($status === 'scheduled' || $status === 'pending') { $scheduled++; $pending++; }
        elseif ($status === 'passed' || $status === 'completed') { $completed++; $passed++; }
        elseif ($status === 'failed' || $status === 'rejected') { $failed++; }
    }
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
function formatTime($time) {
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
function getInspectorName($row) {
    if (!empty($row['assigned_inspector_name'])) return h($row['assigned_inspector_name']);
    if (!empty($row['inspector_name'])) return h($row['inspector_name']);
    return 'Awaiting Assignment';
}
function getPropertyName($row) {
    global $hasRentalName, $hasBusinessName;
    if ($hasRentalName && !empty($row['rental_name'])) return h($row['rental_name']);
    if ($hasBusinessName && !empty($row['business_name'])) return h($row['business_name']);
    return '—';
}
function getTrackingNo($row) {
    global $hasTrackingNo;
    return ($hasTrackingNo && !empty($row['tracking_no'])) ? h($row['tracking_no']) : 'N/A';
}

/* Filters */
$filterType = $_GET['type'] ?? 'all';
$filterStatus = $_GET['status'] ?? 'all';
$filteredInspections = $inspections;
if ($filterType !== 'all') {
    $filteredInspections = array_filter($filteredInspections, function($i) use ($filterType) {
        return strtolower($i['inspection_type'] ?? '') === $filterType;
    });
}
if ($filterStatus !== 'all') {
    $filteredInspections = array_filter($filteredInspections, function($i) use ($filterStatus, $hasInspectionStatus, $hasStatus) {
        $status = $hasInspectionStatus ? strtolower($i['inspection_status'] ?? '') : ($hasStatus ? strtolower($i['status'] ?? '') : 'scheduled');
        return $status === $filterStatus;
    });
}

$inspectionTypes = [];
foreach ($inspections as $i) {
    $type = $i['inspection_type'] ?? '';
    if (!empty($type) && !in_array($type, $inspectionTypes)) $inspectionTypes[] = $type;
}

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
    $stmtNotifList = $conn->prepare("SELECT * FROM notifications WHERE user_id = :uid ORDER BY created_at DESC LIMIT 5");
    $stmtNotifList->execute([':uid' => $userId]);
    $notifications = $stmtNotifList->fetchAll(PDO::FETCH_ASSOC);
}

$flashMessage = $_SESSION['flash_message'] ?? null;
$flashText = $_SESSION['flash_text'] ?? null;
unset($_SESSION['flash_message'], $_SESSION['flash_text']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#dc2626">
    <title>Site Inspections | RENTRACK LGU</title>

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
        .hero-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

        .btn-schedule-nav {
            background: rgba(255,255,255,0.2); color: #ffffff; border: 1px solid rgba(255,255,255,0.4);
            padding: 9px 18px; border-radius: 999px; font-weight: 700; font-size: 13px; text-decoration: none;
            display: inline-flex; align-items: center; gap: 8px; transition: var(--transition);
        }
        .btn-schedule-nav:hover { background: rgba(255,255,255,0.35); color: #ffffff; transform: translateY(-1px); }
        .btn-cta {
            background: #ffffff; color: #dc2626; border: none;
            padding: 9px 18px; border-radius: 999px; font-weight: 800; font-size: 13px;
            cursor: pointer; transition: var(--transition); box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-cta:hover { background: #fee2e2; transform: translateY(-1px); }

        /* STATS */
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 18px; }
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

        /* FILTER */
        .filter-bar {
            display: flex; align-items: center; gap: 14px; padding: 12px 18px;
            background: #ffffff; border: 1px solid var(--card-border); border-radius: 12px; margin-bottom: 18px;
            flex-wrap: wrap;
        }
        .filter-group { display: flex; align-items: center; gap: 8px; font-size: 12px; font-weight: 700; color: #475569; }
        .filter-group select {
            border: 1px solid var(--card-border); border-radius: 8px; padding: 5px 10px; font-size: 12px;
            background: #f8fafc; color: #0f172a; outline: none; max-width: 100%;
        }

        /* INSPECTION CARDS */
        .inspection-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px; margin-bottom: 20px; }
        .inspection-card {
            background: #ffffff; border: 1px solid var(--card-border); border-radius: 16px;
            padding: 18px; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.04); transition: var(--transition);
        }
        .inspection-card:hover { border-color: var(--accent-blue); transform: translateY(-2px); }
        .card-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; gap: 8px; }
        .date-badge {
            background: var(--accent-blue); color: #fff; padding: 4px 10px; border-radius: 8px;
            text-align: center; line-height: 1.1; font-weight: 800; flex-shrink: 0;
        }
        .date-badge .month { font-size: 10px; text-transform: uppercase; display: block; }
        .date-badge .day { font-size: 16px; }

        .card-title { font-family: 'Space Grotesk', sans-serif; font-size: 15px; font-weight: 800; color: #0f172a; margin: 0; word-break: break-word; }
        .card-sub { font-size: 12px; color: #64748b; margin-bottom: 12px; word-break: break-word; }

        .card-details {
            display: grid; grid-template-columns: 1fr 1fr; gap: 8px;
            padding-top: 10px; border-top: 1px solid #f1f5f9; font-size: 11.5px;
        }
        .detail-item .label { font-size: 10px; font-weight: 800; color: #94a3b8; text-transform: uppercase; display: block; }
        .detail-item .value { font-weight: 700; color: #334155; word-break: break-word; }

        .status-badge { padding: 4px 10px; border-radius: 999px; font-size: 10.5px; font-weight: 800; text-transform: uppercase; display: inline-block; white-space: nowrap; }
        .status-badge.active { background: #d1fae5; color: #059669; }
        .status-badge.pending { background: #fef3c7; color: #d97706; }
        .status-badge.rejected { background: #ffe4e6; color: #dc2626; }
        .status-badge.secondary { background: #f1f5f9; color: #64748b; }

        .delete-btn { background: none; border: none; color: var(--danger); cursor: pointer; padding: 4px; font-size: 13px; flex-shrink: 0; }
        .delete-btn:hover { color: #7f1d1d; }

        .empty-state-card {
            text-align: center; padding: 40px 20px; color: #64748b;
            background: #fff; border: 1px solid var(--card-border); border-radius: 16px;
        }
        .empty-state-card i { font-size: 32px; opacity: 0.5; color: var(--accent-blue); }
        .empty-state-card p { font-size: 13px; margin: 8px 0 0; }

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
            width: 100%; max-width: 520px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.15); border: 1px solid var(--card-border);
            max-height: calc(100vh - 32px); overflow-y: auto; margin: auto;
        }
        .modal-content::-webkit-scrollbar { width: 6px; }
        .modal-content::-webkit-scrollbar-thumb { background: var(--accent-blue-light); border-radius: 999px; }

        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid var(--card-border); padding-bottom: 10px; }
        .modal-header h3 { font-family: 'Space Grotesk', sans-serif; font-size: 17px; font-weight: 800; color: #0f172a; margin: 0; }

        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; font-size: 12px; font-weight: 700; color: #475569; margin-bottom: 4px; }
        .form-group label .required { color: var(--danger); }
        .form-control, .form-select {
            width: 100%; border: 1px solid var(--card-border); border-radius: 10px; padding: 8px 12px; font-size: 13px; outline: none; background: #f8fafc;
        }
        .form-control:focus, .form-select:focus { border-color: var(--accent-blue); background: #fff; }
        .form-hint { font-size: 10.5px; color: #94a3b8; margin-top: 3px; display: block; font-style: italic; }

        .info-box {
            display: flex; gap: 12px; padding: 12px 14px; border-radius: 12px;
            background: #fef2f2; border: 1px solid var(--card-border); margin-bottom: 16px;
        }
        .info-box i { color: var(--accent-blue); font-size: 16px; margin-top: 2px; flex-shrink: 0; }
        .info-box .info-text { font-size: 12px; color: #475569; line-height: 1.5; }
        .info-box .info-text strong { color: var(--accent-blue); }

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
                pointer-events: none;
            }
            .sidebar-overlay.active { 
                display: block; 
                opacity: 1; 
                pointer-events: auto; 
            }
            .notification-dropdown { width: calc(100vw - 24px); max-width: 400px; right: -50px; }
        }

        @media (max-width: 768px) {
            .content { padding: 16px 14px 24px; padding-bottom: calc(24px + var(--safe-bottom)); }
            .hero-card { flex-direction: column; align-items: flex-start; gap: 14px; padding: 18px 20px; }
            .hero-card h2 { font-size: 18px; }
            .hero-actions { width: 100%; }
            .btn-schedule-nav, .btn-cta { flex: 1; justify-content: center; }

            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .stat-card { padding: 12px 14px; }
            .stat-label { font-size: 9.5px; }
            .stat-number { font-size: 18px; }
            .stat-icon { width: 32px; height: 32px; font-size: 13px; }

            .filter-bar { flex-direction: column; align-items: stretch; gap: 10px; }
            .filter-group { justify-content: space-between; }
            .filter-group select { flex: 1; max-width: 65%; }

            .inspection-grid { grid-template-columns: 1fr; }
            .dashboard-footer { flex-direction: column; text-align: center; gap: 6px; font-size: 10.5px; }
        }
        @media (max-width: 480px) {
            .content { padding: 12px 10px 22px; padding-bottom: calc(22px + var(--safe-bottom)); }
            .hero-actions { flex-direction: column; }
            .btn-schedule-nav, .btn-cta { width: 100%; }
            .card-details { grid-template-columns: 1fr; }
        }
        @media (max-width: 767px) {
            input, select, textarea { font-size: 16px !important; }
            .search-container input, .search-modal-input, .form-control, .form-select { font-size: 16px !important; }
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

            <!-- FIX: OVERLAY AY INILIPAT SA LOOB NG STICKY-HEADER PARA MAGKASAMA SILA NG DRAWER SA STACKING CONTEXT -->
            <div class="sidebar-overlay" id="sidebarOverlay"></div>
        </div>

        <main class="content">

            <!-- HERO -->
            <div class="hero-card">
                <div>
                    <h2><i class="fa-solid fa-clipboard-check me-1"></i> Site Inspections</h2>
                    <p>Track compliance visits conducted by LGU &amp; BFP inspectors.</p>
                </div>
                <div class="hero-actions">
                    <a href="inspection_schedule.php" class="btn-schedule-nav">
                        <i class="fa-solid fa-calendar-check"></i> Schedule
                    </a>
                    <button onclick="openRequestModal()" class="btn-cta">
                        <i class="fa-solid fa-plus"></i> Request Inspection
                    </button>
                </div>
            </div>

            <!-- STATS -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div>
                        <span class="stat-label">Scheduled</span>
                        <div class="stat-number"><?php echo number_format($scheduled); ?></div>
                    </div>
                    <div class="stat-icon"><i class="fa-regular fa-clock"></i></div>
                </div>
                <div class="stat-card">
                    <div>
                        <span class="stat-label">Passed</span>
                        <div class="stat-number"><?php echo number_format($passed); ?></div>
                    </div>
                    <div class="stat-icon"><i class="fa-regular fa-circle-check"></i></div>
                </div>
                <div class="stat-card">
                    <div>
                        <span class="stat-label">Failed</span>
                        <div class="stat-number"><?php echo number_format($failed); ?></div>
                    </div>
                    <div class="stat-icon"><i class="fa-regular fa-circle-xmark"></i></div>
                </div>
                <div class="stat-card">
                    <div>
                        <span class="stat-label">Assigned</span>
                        <div class="stat-number"><?php echo number_format($assignedCount); ?></div>
                    </div>
                    <div class="stat-icon"><i class="fa-solid fa-user-tie"></i></div>
                </div>
            </div>

            <!-- FILTER -->
            <div class="filter-bar">
                <div class="filter-group">
                    <span>Type:</span>
                    <select onchange="window.location.href='?type='+this.value+'&status=<?php echo $filterStatus; ?>'">
                        <option value="all" <?php echo $filterType === 'all' ? 'selected' : ''; ?>>All Types</option>
                        <?php foreach ($inspectionTypes as $type): ?>
                            <option value="<?php echo h($type); ?>" <?php echo $filterType === $type ? 'selected' : ''; ?>><?php echo getInspectionType($type); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <span>Status:</span>
                    <select onchange="window.location.href='?type=<?php echo $filterType; ?>&status='+this.value">
                        <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                        <option value="scheduled" <?php echo $filterStatus === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                        <option value="passed" <?php echo $filterStatus === 'passed' ? 'selected' : ''; ?>>Passed</option>
                        <option value="failed" <?php echo $filterStatus === 'failed' ? 'selected' : ''; ?>>Failed</option>
                    </select>
                </div>
            </div>

            <!-- INSPECTIONS GRID -->
            <?php if (empty($filteredInspections)): ?>
                <div class="empty-state-card">
                    <i class="fa-solid fa-folder-open"></i>
                    <p>No site inspection records found.</p>
                </div>
            <?php else: ?>
                <div class="inspection-grid">
                    <?php foreach ($filteredInspections as $ins):
                        $status = $hasInspectionStatus ? ($ins['inspection_status'] ?? 'Scheduled') : ($ins['status'] ?? 'Scheduled');
                        $statusClass = getStatusBadgeClass($status);
                        $date = $ins['inspection_date'] ?? '';
                        $time = $ins['inspection_time'] ?? '';
                        $type = getInspectionType($ins['inspection_type'] ?? '');
                        $category = $hasInspectionCategory ? getInspectionCategory($ins['inspection_category'] ?? '') : '';
                        $inspector = getInspectorName($ins);
                        $isAssigned = !empty($ins['inspector_id']) || !empty($ins['assigned_inspector_name']);
                        $propertyName = getPropertyName($ins);
                        $tracking = getTrackingNo($ins);
                    ?>
                        <div class="inspection-card">
                            <div class="card-top">
                                <div class="date-badge">
                                    <span class="month"><?php echo !empty($date) ? date('M', strtotime($date)) : '---'; ?></span>
                                    <span class="day"><?php echo !empty($date) ? date('d', strtotime($date)) : '--'; ?></span>
                                </div>
                                <span class="status-badge <?php echo $statusClass; ?>"><?php echo h($status); ?></span>
                                <form method="POST" onsubmit="return confirmDeleteInspection(event, this);" style="display:inline;">
                                    <input type="hidden" name="action" value="delete_inspection">
                                    <input type="hidden" name="inspection_id" value="<?php echo $ins['id']; ?>">
                                    <button type="submit" class="delete-btn" title="Delete"><i class="fa-solid fa-trash-can"></i></button>
                                </form>
                            </div>

                            <h4 class="card-title"><?php echo $propertyName; ?></h4>
                            <div class="card-sub">#<?php echo $tracking; ?></div>

                            <div class="card-details">
                                <div class="detail-item">
                                    <span class="label">Type</span>
                                    <span class="value"><?php echo h($type); ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="label">Time</span>
                                    <span class="value"><?php echo formatTime($time); ?></span>
                                </div>
                                <?php if ($hasInspectionCategory && !empty($category) && $category !== '—'): ?>
                                <div class="detail-item" style="grid-column: 1 / -1;">
                                    <span class="label">Category</span>
                                    <span class="value"><?php echo h($category); ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="detail-item" style="grid-column: 1 / -1;">
                                    <span class="label">Assigned Inspector</span>
                                    <span class="value">
                                        <?php if ($isAssigned && $inspector !== 'Awaiting Assignment'): ?>
                                            <i class="fa-solid fa-user-tie" style="color: var(--accent-blue);"></i> <?php echo h($inspector); ?>
                                        <?php else: ?>
                                            <span style="color: #d97706; font-weight: 700;">
                                                <i class="fa-solid fa-clock"></i> Awaiting Assignment
                                            </span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <footer class="dashboard-footer">
                <span>&copy; <?php echo date('Y'); ?> <span style="color:var(--accent-blue); font-weight:700;">RENTRACK LGU</span> &middot; Unified Rental System</span>
                <span>Owner Portal</span>
            </footer>
        </main>
    </div>

    <!-- REQUEST MODAL -->
    <div class="modal-overlay" id="requestModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fa-solid fa-calendar-plus me-1" style="color:var(--accent-blue);"></i> Request Inspection</h3>
                <button type="button" class="btn-close" onclick="closeRequestModal()"></button>
            </div>

            <div class="info-box">
                <i class="fa-solid fa-circle-info"></i>
                <div class="info-text">
                    Please select the <strong>purpose</strong> and <strong>category</strong> of your inspection request. Our LGU inspector will review and confirm your schedule within 1–2 business days.
                </div>
            </div>

            <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>">
                <input type="hidden" name="action" value="request_inspection">
                
                <div class="form-group">
                    <label>Rental Property <span class="required">*</span></label>
                    <select name="rental_id" class="form-select" required>
                        <option value="">-- Select Property --</option>
                        <?php foreach ($userRentals as $rental): ?>
                            <option value="<?php echo (int)$rental['id']; ?>"><?php echo h($rental['business_name']); ?> (<?php echo h($rental['tracking_no']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Inspection Type (Purpose) <span class="required">*</span></label>
                    <select name="inspection_type" class="form-select" required>
                        <option value="">-- Select Inspection Type --</option>
                        <option value="Initial">Initial Inspection (First-Time Compliance)</option>
                        <option value="Annual">Annual Inspection (Yearly Renewal)</option>
                        <option value="Follow-up">Follow-up Inspection (Compliance Verification)</option>
                        <option value="Complaint">Complaint Inspection (Reported Violation)</option>
                        <option value="Re-inspection">Re-inspection (After Failed Assessment)</option>
                    </select>
                    <span class="form-hint">Layunin ng inspeksyon (purpose ng compliance visit).</span>
                </div>

                <div class="form-group">
                    <label>Inspection Category (Subject)</label>
                    <select name="inspection_category" class="form-select">
                        <option value="">-- Select Category (Optional) --</option>
                        <option value="building">Building Inspection</option>
                        <option value="fire_safety">Fire Safety &amp; Hazard</option>
                        <option value="structural">Structural Assessment</option>
                        <option value="electrical">Electrical Safety</option>
                        <option value="sanitary">Sanitary Inspection</option>
                        <option value="zoning">Zoning Compliance</option>
                        <option value="occupancy">Occupancy Clearance</option>
                    </select>
                    <span class="form-hint">Uri ng inspeksyon (subject area na i-inspect).</span>
                </div>

                <div class="form-group">
                    <label>Preferred Date <span class="required">*</span></label>
                    <input type="date" name="preferred_date" class="form-control" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" required>
                </div>

                <div class="form-group">
                    <label>Preferred Time</label>
                    <input type="time" name="preferred_time" class="form-control">
                </div>

                <div class="form-group">
                    <label>Remarks / Special Instructions</label>
                    <textarea name="remarks" class="form-control" rows="2" placeholder="Additional notes for the inspector..."></textarea>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:16px; flex-wrap: wrap;">
                    <button type="button" class="btn btn-sm btn-light" onclick="closeRequestModal()">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary" style="background:var(--accent-blue); border:none;">
                        <i class="fa-solid fa-paper-plane me-1"></i> Submit Request
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- SEARCH MODAL -->
    <div class="search-modal-overlay" id="searchModalOverlay" onclick="closeSearchModalOnOutside(event)">
        <div class="search-modal-content">
            <div class="search-modal-header">
                <i class="fa-solid fa-magnifying-glass search-icon-main"></i>
                <input type="text" class="search-modal-input" id="modalSearchInput" placeholder="Search by tracking number, business name..." autocomplete="off">
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
    <div id="aiAssistantWrapper" style="position:fixed; bottom:calc(25px + var(--safe-bottom)); right:calc(25px + var(--safe-right)); z-index:1050; font-family:'Plus Jakarta Sans', sans-serif;">
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
                    Hello <?php echo htmlspecialchars($userName); ?>! Ask me about your inspections, rentals, payments, or permits.
                </div>
            </div>
            <div style="padding:10px; background:#ffffff; border-top:1px solid var(--card-border); display:flex; gap:8px;">
                <input type="text" id="aiChatInput" placeholder="Ask e.g., 'How many inspections?'..." style="flex:1; padding:8px 12px; border:1px solid var(--card-border); border-radius:999px; outline:none; font-size:12.5px; background:#f8fafc;">
                <button id="aiSendBtn" style="background:var(--accent-blue); border:none; color:#fff; width:36px; height:36px; border-radius:50%; cursor:pointer; display:flex; align-items:center; justify-content:center;"><i class="fa-solid fa-paper-plane" style="font-size:12px;"></i></button>
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

    /* REQUEST MODAL */
    function openRequestModal() { 
        document.getElementById('requestModal').classList.add('active'); 
        document.body.style.overflow = 'hidden';
    }
    function closeRequestModal() { 
        document.getElementById('requestModal').classList.remove('active'); 
        document.body.style.overflow = '';
    }
    document.getElementById('requestModal')?.addEventListener('click', (e) => {
        if (e.target.id === 'requestModal') closeRequestModal();
    });

    /* DELETE */
    function confirmDeleteInspection(e, form) {
        e.preventDefault();
        Swal.fire({
            title: 'Delete Inspection Request?',
            text: 'Are you sure you want to delete this inspection request?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, Delete',
            background: '#ffffff',
            color: '#0f172a'
        }).then((res) => {
            if (res.isConfirmed) form.submit();
        });
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
        }).then((res) => {
            if (res.isConfirmed) window.location.href = '../logout.php';
        });
    }
    document.getElementById('profileLogoutBtn')?.addEventListener('click', confirmLogout);

    /* FLASH */
    <?php if ($flashMessage && $flashText): ?>
        Swal.fire({
            icon: '<?php echo $flashMessage; ?>',
            title: '<?php echo ucfirst($flashMessage); ?>',
            text: '<?php echo h($flashText); ?>',
            confirmButtonColor: '#dc2626'
        });
    <?php endif; ?>
    </script>
</body>
</html>