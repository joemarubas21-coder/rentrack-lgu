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
        error_log('[documents] ' . $e->getMessage());
        return 'Something went wrong. Please try again.';
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

define('LGU_LOGO_URL', 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/rentalsystem/owner/assets/images/images.jpg');

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
    $totalDocs = 0; $totalPermits = 0;

    if (tableExists($conn, 'documents')) {
        $stmtDocs = $conn->prepare("SELECT COUNT(*) FROM documents WHERE user_id = :uid");
        $stmtDocs->execute([':uid' => $userId]);
        $totalDocs = (int)$stmtDocs->fetchColumn();

        $stmtPermits = $conn->prepare("SELECT COUNT(*) FROM documents WHERE user_id = :uid AND (type = 'Permit' OR title ILIKE '%permit%')");
        $stmtPermits->execute([':uid' => $userId]);
        $totalPermits = (int)$stmtPermits->fetchColumn();
    }
    $reply = "Hello! I am Rentrack AI. You can ask me about your documents, permits, renewals, or compliance.";
    if (strpos($question, 'document') !== false || strpos($question, 'doc') !== false) {
        $reply = "You have {$totalDocs} uploaded document(s), including {$totalPermits} approved permit(s).";
    } elseif (strpos($question, 'permit') !== false) {
        $reply = "You have {$totalPermits} approved LGU permit(s) issued by staff. Check the Approved Permits table.";
    } elseif (strpos($question, 'upload') !== false) {
        $reply = "You can upload new documents from the 'Upload New File' form on this page. Max 5MB per file.";
    } elseif (strpos($question, 'renew') !== false) {
        $reply = "For renewals, go to the Online Renewal page to submit a request.";
    } elseif (strpos($question, 'hello') !== false || strpos($question, 'hi') !== false) {
        $reply = "Hello! How can I help you today?";
    } else {
        $reply = "You have {$totalDocs} document(s) and {$totalPermits} permit(s) in your vault.";
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

function getPermitBadgeClass($classification) {
    $c = strtolower($classification);
    if (strpos($c, 'lease') !== false) return 'lease';
    if (strpos($c, 'occupancy') !== false) return 'occ';
    return 'biz';
}
function getPermitTypeLabelFromPrefix($prefix) {
    $map = ['BP'=>'Business Permit','SP'=>'Sanitary Permit','FSIC'=>'Fire Safety','BC'=>'Barangay Clearance','HC'=>'Health Cert','LC'=>'Lease Contract','LCC'=>'Lease Clearance','OCP'=>'Occupancy','PERMIT'=>'Permit'];
    return $map[$prefix] ?? 'Permit';
}

/* FILE SETUP */
$rootUploadDir = dirname(__DIR__) . '/uploads';
if (!is_dir($rootUploadDir)) @mkdir($rootUploadDir, 0755, true);
$uploadDir = $rootUploadDir . '/user_' . $userId;
$successMessage = ''; $errorMessage = ''; $documents = []; $approvedPermits = [];
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

/* FETCH DB DOCS */
$dbDocuments = [];
if (tableExists($conn, 'documents')) {
    try {
        $dbStmt = $conn->prepare("SELECT * FROM documents WHERE user_id = :uid ORDER BY uploaded_at DESC");
        $dbStmt->execute([':uid' => $userId]);
        $dbDocuments = $dbStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

/* SEGREGATE */
$seenPermits = []; $seenDocs = [];
foreach ($dbDocuments as $d) {
    $isPermit = isset($d['type']) && (strcasecmp($d['type'], 'Permit') === 0 || strpos(strtolower($d['title'] ?? ''), 'permit') !== false);
    if ($isPermit) {
        $uniqueKey = $d['title'] . '_' . ($d['stored_name'] ?? '');
        if (!isset($seenPermits[$uniqueKey])) { $seenPermits[$uniqueKey] = true; $approvedPermits[] = $d; }
    } else {
        $docKey = ($d['title'] ?? '') . '_' . ($d['file_name'] ?? '');
        if (!isset($seenDocs[$docKey])) { $seenDocs[$docKey] = true; $documents[] = $d; }
    }
}

/* UPDATE VALIDITY CALCULATION */
$expiringCount = 0;
$expiredCount = 0;

foreach ($approvedPermits as &$permit) {
    $titleParts = explode(' - ', $permit['title'] ?? '', 2);
    $permit['classification'] = trim($titleParts[0] ?? 'Permit');
    $permit['badgeClass'] = getPermitBadgeClass($permit['classification']);
    $permit['permitNumber'] = !empty($permit['business_permit_no']) ? $permit['business_permit_no'] : null;
    $permit['typeLabel'] = null;
    if ($permit['permitNumber']) {
        $prefix = explode('-', $permit['permitNumber'])[0];
        $permit['typeLabel'] = getPermitTypeLabelFromPrefix($prefix);
    }
    $permit['validUntil'] = null;
    if (!empty($permit['permit_expiry_date'])) $permit['validUntil'] = $permit['permit_expiry_date'];
    elseif (!empty($permit['notes']) && preg_match('/Valid until:\s*([0-9]{4}-[0-9]{2}-[0-9]{2})/i', $permit['notes'], $m)) $permit['validUntil'] = $m[1];

    $permit['validityStatus'] = 'unknown';
    $permit['daysLeft'] = null;
    $permit['daysText'] = '';

    if (!empty($permit['validUntil'])) {
        $today = new DateTime(date('Y-m-d'));
        $expiry = new DateTime(date('Y-m-d', strtotime($permit['validUntil'])));
        $interval = $today->diff($expiry);
        $days = (int)$interval->format('%r%a');
        $permit['daysLeft'] = $days;
        
        if ($days < 0) {
            $permit['validityStatus'] = 'expired';
            $permit['daysText'] = 'Expired ' . abs($days) . ' day' . (abs($days)==1?'':'s') . ' ago';
            $expiredCount++;
        } elseif ($days <= 30) {
            $permit['validityStatus'] = 'expiring';
            $permit['daysText'] = $days . ' day' . ($days==1?'':'s') . ' left';
            $expiringCount++;
        } else {
            $permit['validityStatus'] = 'valid';
            $permit['daysText'] = $days . ' days left';
        }
    }
}
unset($permit);

$totalPermitsCount = count($approvedPermits);
$totalDocumentsCount = count($documents);

/* AUTO-GENERATE EXPIRY NOTIFICATIONS */
if (tableExists($conn, 'notifications') && columnExists($conn, 'notifications', 'is_read')) {
    foreach ($approvedPermits as $p) {
        if (!empty($p['validUntil']) && in_array($p['validityStatus'], ['expired', 'expiring'])) {
            $notifTitle = ($p['validityStatus'] === 'expired') ? 'Permit Expired' : 'Permit Expiring Soon';
            $notifMsg = ($p['validityStatus'] === 'expired') 
                ? 'Your permit "' . $p['title'] . '" expired on ' . date('M d, Y', strtotime($p['validUntil'])) . '. Please renew immediately to avoid penalties.'
                : 'Your permit "' . $p['title'] . '" will expire in ' . abs($p['daysLeft']) . ' day(s) on ' . date('M d, Y', strtotime($p['validUntil'])) . '. Please renew soon.';
            
            $checkStmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND title = :title AND message = :msg");
            $checkStmt->execute([':uid' => $userId, ':title' => $notifTitle, ':msg' => $notifMsg]);
            
            if ($checkStmt->fetchColumn() == 0) {
                $insNotif = $conn->prepare("INSERT INTO notifications (user_id, title, message, is_read, created_at, link) VALUES (:uid, :title, :msg, FALSE, NOW(), 'documents.php')");
                $insNotif->execute([':uid' => $userId, ':title' => $notifTitle, ':msg' => $notifMsg]);
            }
        }
    }
}

/* UPLOAD HANDLER */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['document_file'])) {
    $title = trim($_POST['document_title'] ?? '');
    $type  = trim($_POST['document_type'] ?? 'Permit');
    $notes = trim($_POST['notes'] ?? '');

    if ($_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
        $orig = basename($_FILES['document_file']['name']);
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $allowed = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'txt'];

        if ($_FILES['document_file']['size'] > 5 * 1024 * 1024) {
            $errorMessage = 'File exceeds maximum 5MB size limit.';
        } elseif (!in_array($ext, $allowed, true) || !upload_content_ok($_FILES['document_file']['tmp_name'], $ext)) {
            $errorMessage = 'Invalid format. Only PDF, DOC, DOCX, JPG, PNG, and TXT files are supported.';
        } else {
            $storedName = 'doc_' . bin2hex(random_bytes(12)) . '.' . $ext;
            if (move_uploaded_file($_FILES['document_file']['tmp_name'], $uploadDir . '/' . $storedName)) {
                if (tableExists($conn, 'documents')) {
                    try {
                        $insDb = $conn->prepare("INSERT INTO documents (user_id, title, type, notes, file_name, stored_name, owner_folder_path, uploaded_at) VALUES (:uid, :title, :type, :notes, :fname, :sname, :fpath, NOW())");
                        $insDb->execute([':uid'=>$userId, ':title'=>$title ?: pathinfo($orig, PATHINFO_FILENAME), ':type'=>$type, ':notes'=>$notes, ':fname'=>$orig, ':sname'=>$storedName, ':fpath'=>'user_' . $userId]);
                    } catch (Exception $ex) {}
                }
                $_SESSION['flash_message'] = 'success';
                $_SESSION['flash_text'] = 'Document securely uploaded to your vault.';
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit();
            } else {
                $errorMessage = 'Failed to move uploaded file.';
            }
        }
    } else {
        $errorMessage = 'Please choose a file to upload.';
    }
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
    $notifStmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = :uid ORDER BY created_at DESC LIMIT 10");
    $notifStmt->execute([':uid' => $userId]);
    $notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);
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
    <title>My Documents &amp; Permits | RENTRACK LGU</title>

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

        html, body { margin: 0 !important; padding: 0 !important; height: 100%; font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif; background: var(--bg-light); color: var(--text-primary); overflow-x: hidden; -webkit-text-size-adjust: 100%; -webkit-font-smoothing: antialiased; }
        * { scrollbar-width: thin; scrollbar-color: rgba(220, 38, 38, 0.4) transparent; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-thumb { background: var(--accent-blue); border-radius: 20px; }

        body.drawer-open { overflow: hidden !important; }
        body.drawer-open #aiAssistantWrapper { display: none !important; }

        .main-wrapper { display: flex; flex-direction: column; min-height: 100vh; min-height: 100dvh; background: var(--bg-light); padding-left: var(--safe-left); padding-right: var(--safe-right); }

        /* OFFICIAL HEADER */
        .official-header { width: 100%; display: flex; align-items: center; justify-content: space-between; background: linear-gradient(90deg, #991b1b, #dc2626 55%, #991b1b); border-bottom: 2px solid #7f1d1d; padding: 6px 20px; padding-top: calc(6px + var(--safe-top)); gap: 20px; position: relative; z-index: 110; box-shadow: 0 2px 8px -2px rgba(220, 38, 38, 0.45); }
        .official-header-left, .official-header-right-group { display: flex; align-items: center; gap: 12px; color: #fecdd3; font-size: 11px; font-weight: 600; }
        .official-header-badge { background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.3); padding: 2px 10px; border-radius: 20px; display: flex; align-items: center; gap: 6px; color: #ffffff; }
        .oh-title { display: flex; align-items: center; gap: 8px; }
        .oh-sub { font-weight: 400; opacity: 0.9; }

        @media (max-width: 992px) { .oh-city { display: none; } }
        @media (max-width: 768px) {
            .official-header { display: grid; grid-template-columns: minmax(0, 1fr) auto; grid-template-areas: "title badge" "sub sub"; gap: 3px 8px; padding: 6px 12px; align-items: center; }
            .official-header-left, .official-header-right-group { display: contents; }
            .oh-title { grid-area: title; min-width: 0; gap: 6px; font-size: 10px; white-space: nowrap; }
            .oh-title span { overflow: hidden; text-overflow: ellipsis; }
            .official-header-badge { grid-area: badge; font-size: 9px; padding: 2px 8px; gap: 4px; white-space: nowrap; }
            .oh-sep, .oh-city, .oh-portal { display: none; }
            .oh-sub { grid-area: sub; font-size: 9px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        }

        .sticky-header { position: sticky; top: 0; z-index: 1000; background: #ffffff; }
        .top-navbar { display: flex; align-items: center; justify-content: space-between; padding: 0 18px; background: #ffffff; border-bottom: 1px solid var(--card-border); gap: 14px; flex-wrap: nowrap; min-height: 62px; }
        .navbar-left { display: flex; align-items: center; gap: 14px; flex-shrink: 0; }
        .nav-brand { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .brand-logo { position: relative; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .brand-logo-ring { position: absolute; inset: -2px; border-radius: 50%; border: 2px dashed rgba(220, 38, 38, 0.55); animation: spinRing 4s linear infinite; }
        .brand-logo-circle { width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, #dc2626, #991b1b); display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 800; font-size: 13px; z-index: 2; box-shadow: 0 4px 10px rgba(220, 38, 38, 0.35); }
        .brand-text h2 { font-size: 14px; font-weight: 800; color: var(--accent-blue); line-height: 1.1; margin: 0; font-family: 'Space Grotesk', sans-serif; letter-spacing: -0.3px; }
        .brand-text span { font-size: 9px; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 1.3px; }

        .menu-toggle { display: none; width: 42px; height: 42px; border-radius: 10px; align-items: center; justify-content: center; background: #f8fafc; border: 1px solid var(--card-border); color: var(--accent-blue); cursor: pointer; font-size: 16px; flex-shrink: 0; }

        /* NAV */
        .main-nav-bar { display: flex; align-items: center; background: transparent; border: none; padding: 0; position: relative; flex-shrink: 0; z-index: 45; }
        .nav-menu { list-style: none; margin: 0; padding: 0; display: flex; align-items: center; gap: 2px; flex-wrap: nowrap; }
        .nav-item { position: relative; }
        .nav-link { display: flex; align-items: center; gap: 6px; padding: 10px 12px; color: #334155; text-decoration: none; font-size: 12.5px; font-weight: 600; border-radius: 8px; transition: all 0.15s ease; cursor: pointer; white-space: nowrap; background: transparent; border: none; font-family: inherit; }
        .nav-link i.nav-ico { color: var(--accent-blue); font-size: 12px; width: 14px; text-align: center; }
        .nav-link .chevron { font-size: 8px; margin-left: 1px; transition: transform 0.2s ease; opacity: 0.7; }
        .nav-link:hover { color: var(--accent-blue); background: var(--blue-soft); }
        .nav-link.active { color: var(--accent-blue); background: var(--blue-soft); font-weight: 700; }
        .nav-item.open > .nav-link { color: var(--accent-blue); background: var(--blue-soft); }
        .nav-item.open > .nav-link .chevron { transform: rotate(180deg); }

        .nav-dropdown { position: absolute; top: calc(100% + 6px); left: 50%; transform: translateX(-50%) translateY(8px); min-width: 250px; background: #ffffff; border: 1px solid var(--card-border); border-radius: 12px; box-shadow: 0 12px 30px rgba(220, 38, 38, 0.15); padding: 8px; opacity: 0; visibility: hidden; transition: opacity 0.18s ease, transform 0.18s ease, visibility 0.18s; z-index: 200; }
        .nav-item.open .nav-dropdown { opacity: 1; visibility: visible; transform: translateX(-50%) translateY(0); }
        .nav-item.align-right .nav-dropdown { left: auto; right: 0; transform: translateX(0) translateY(8px); }
        .nav-item.align-right.open .nav-dropdown { transform: translateX(0) translateY(0); }
        .nav-dropdown a { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 8px; color: #334155; text-decoration: none; font-size: 13px; font-weight: 500; }
        .nav-dropdown a:hover { background: var(--blue-soft); color: var(--accent-blue); }
        .nav-dropdown a i { width: 16px; color: var(--accent-blue); font-size: 14px; text-align: center; flex-shrink: 0; }

        .mobile-drawer-header { display: none; }

        /* SEARCH */
        .search-wrapper { flex: 1 1 auto; display: flex; justify-content: center; min-width: 140px; max-width: 380px; padding: 0 8px; margin: 0 auto; }
        .search-container { position: relative; width: 100%; cursor: pointer; }
        .search-container i { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: var(--accent-blue); z-index: 2; font-size: 13px; }
        .search-container input { width: 100%; padding: 8px 14px 8px 38px; border-radius: 999px; border: 1px solid var(--card-border); background: #f8fafc; color: #0f172a; font-size: 13px; outline: none; cursor: pointer; }

        /* RIGHT */
        .navbar-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .nav-clock { display: flex; flex-direction: column; align-items: flex-end; padding: 3px 14px; border-radius: 10px; background: var(--blue-soft); border: 1px solid var(--card-border); }
        .nav-clock .time { font-family: 'Space Grotesk', monospace; font-size: 13px; font-weight: 700; color: var(--accent-blue); }
        .nav-clock .date { font-size: 10px; color: #64748b; font-weight: 600; text-transform: uppercase; }

        .nav-icon { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: #ffffff; border: 1px solid var(--card-border); color: var(--accent-blue); cursor: pointer; position: relative; flex-shrink: 0; text-decoration: none; }
        .nav-icon .badge { position: absolute; top: -4px; right: -4px; background: var(--accent-blue); color: #fff; font-size: 9px; font-weight: 700; min-width: 18px; height: 18px; padding: 0 4px; border-radius: 9px; display: flex; align-items: center; justify-content: center; border: 2px solid #ffffff; }

        .profile-wrapper { position: relative; }
        .profile-btn { display: flex; align-items: center; gap: 10px; padding: 3px 12px 3px 3px; border-radius: 12px; background: #ffffff; border: 1px solid var(--card-border); cursor: pointer; }
        .profile-btn .avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--accent-blue); display: flex; align-items: center; justify-content: center; color: #ffffff; font-weight: 800; font-size: 13px; }
        .profile-btn .info .name { font-size: 12px; font-weight: 600; color: #0f172a; }
        .profile-btn .info .role { font-size: 9px; color: var(--accent-blue); font-weight: 700; text-transform: uppercase; }

        .profile-dropdown, .notification-dropdown { position: absolute; top: calc(100% + 12px); right: 0; background: #ffffff; border: 1px solid var(--card-border); border-radius: 14px; padding: 8px; box-shadow: 0 16px 36px rgba(220, 38, 38, 0.15); opacity: 0; visibility: hidden; transform: translateY(-10px) scale(0.95); transition: all 0.25s ease; z-index: 60; }
        .profile-dropdown { width: 240px; }
        .notification-dropdown { width: 360px; max-width: calc(100vw - 24px); padding: 0; }
        .profile-dropdown.show, .notification-dropdown.show { opacity: 1; visibility: visible; transform: translateY(0) scale(1); }
        .profile-dropdown .dropdown-item { display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-radius: 10px; color: #334155; text-decoration: none; font-size: 13px; }
        .profile-dropdown .dropdown-item:hover { background: var(--blue-soft); color: var(--accent-blue); }
        .profile-dropdown .dropdown-item.danger { color: var(--danger); }
        .profile-dropdown .dropdown-item.danger:hover { background: var(--danger-bg); }

        .notification-title { padding: 14px 20px; font-weight: 700; font-size: 14px; border-bottom: 1px solid var(--blue-soft); color: #0f172a; }

        /* CONTENT */
        .content { flex: 1; padding: 22px 24px 40px; padding-bottom: calc(40px + var(--safe-bottom)); background: var(--bg-light); }

        .hero-card { display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: 22px 26px; border-radius: 18px; color: #ffffff; background: linear-gradient(135deg, #dc2626, #991b1b); box-shadow: 0 8px 22px rgba(220, 38, 38, 0.2); margin-bottom: 18px; flex-wrap: wrap; }
        .hero-card h2 { font-family: 'Space Grotesk', sans-serif; font-size: 22px; font-weight: 800; color: #ffffff; margin: 0; }
        .hero-card p { color: #fee2e2; font-size: 13.5px; margin-top: 4px; margin-bottom: 0; }

        /* PANELS */
        .panel { background: var(--bg-card); border: 1px solid var(--card-border); border-radius: 16px; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.04); overflow: hidden; margin-bottom: 20px; }
        .panel-header { padding: 14px 20px; background: #ffffff; border-bottom: 1px solid var(--card-border); font-family: 'Space Grotesk', sans-serif; font-size: 15px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; justify-content: space-between; }
        .panel-header i { color: var(--accent-blue); }
        .panel-body { padding: 20px; }

        .form-group label { display: block; font-size: 12px; font-weight: 700; color: #475569; margin-bottom: 4px; }
        .form-control, .form-select { width: 100%; border: 1px solid var(--card-border); border-radius: 10px; padding: 8px 12px; font-size: 13px; outline: none; background: #f8fafc; font-family: inherit; }
        .form-control:focus, .form-select:focus { border-color: var(--accent-blue); background: #fff; box-shadow: 0 0 10px rgba(220, 38, 38, 0.1); }

        .btn-primary-custom { background: var(--accent-blue); color: #fff; border: none; padding: 10px 18px; border-radius: 10px; font-weight: 700; font-size: 13px; width: 100%; cursor: pointer; transition: var(--transition); display: inline-flex; align-items: center; justify-content: center; gap: 6px; }
        .btn-primary-custom:hover { background: var(--accent-blue-dark); color: #fff; }

        .btn-secondary-custom { background: #f8fafc; border: 1px solid var(--card-border); color: var(--accent-blue); padding: 5px 12px; border-radius: 8px; font-size: 12px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; cursor: pointer; transition: var(--transition); white-space: nowrap; }
        .btn-secondary-custom:hover { background: var(--accent-blue); color: #fff; border-color: var(--accent-blue); }

        .table-responsive { overflow-x: auto; }
        .table-custom { width: 100%; border-collapse: collapse; font-size: 13px; }
        .table-custom th { background: #f8fafc; color: var(--accent-blue-dark); padding: 12px 16px; font-weight: 800; text-transform: uppercase; font-size: 11px; border-bottom: 1px solid var(--card-border); text-align: left; white-space: nowrap; }
        .table-custom td { padding: 12px 16px; border-bottom: 1px solid #f1f5f9; color: #334155; vertical-align: middle; }
        .table-custom tr:hover { background: #fef2f2; }

        .table-custom tr.row-expired { background-color: #fef2f2; }
        .table-custom tr.row-expiring { background-color: #fffbeb; }
        .table-custom tr.row-expired:hover { background-color: #fee2e2; }
        .table-custom tr.row-expiring:hover { background-color: #fef3c7; }

        .status-badge { padding: 4px 10px; border-radius: 999px; font-size: 10.5px; font-weight: 800; text-transform: uppercase; display: inline-block; white-space: nowrap; }
        .status-badge.active, .status-badge.valid { background: #d1fae5; color: #059669; }
        .status-badge.permit { background: var(--blue-soft); color: var(--accent-blue); }
        .status-badge.biz { background: #dcfce7; color: #000000; }
        .status-badge.lease { background: #dbeafe; color: #000000; }
        .status-badge.occ { background: #f3e8ff; color: #000000; }
        .status-badge.expiring { background: #fef3c7; color: #b45309; }
        .status-badge.expired { background: #fee2e2; color: #b91c1c; }
        .status-badge.unknown { background: #f1f5f9; color: #64748b; }

        .days-left-text { display: block; font-size: 10.5px; font-weight: 700; margin-top: 4px; }
        .days-left-text.text-danger { color: var(--danger); }
        .days-left-text.text-warning { color: #d97706; }
        .days-left-text.text-success { color: #059669; }

        .alert-banner { padding: 16px 20px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: flex-start; gap: 12px; border: 1px solid transparent; }
        .alert-banner.banner-danger { background-color: #fef2f2; border-color: #fecaca; color: #991b1b; }
        .alert-banner.banner-warning { background-color: #fffbeb; border-color: #fde68a; color: #92400e; }
        .alert-banner i { font-size: 20px; margin-top: 2px; flex-shrink: 0; }
        .alert-banner h4 { margin: 0 0 4px 0; font-size: 14px; font-weight: 800; }
        .alert-banner p { margin: 0; font-size: 12.5px; line-height: 1.5; }

        .stats-row { display: flex; gap: 16px; margin-bottom: 18px; flex-wrap: wrap; }
        .stat-box { flex: 1; min-width: 190px; display: flex; align-items: center; gap: 14px; background: #ffffff; border: 1px solid var(--card-border); border-radius: 16px; padding: 16px 20px; box-shadow: 0 4px 12px rgba(220,38,38,0.04); }
        .stat-box .stat-icon { width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 17px; color: #fff; flex-shrink: 0; }
        .stat-box.stat-biz .stat-icon { background: linear-gradient(135deg, #22c55e, #15803d); }
        .stat-box.stat-doc .stat-icon { background: linear-gradient(135deg, #dc2626, #991b1b); }
        .stat-box .stat-num { font-size: 21px; font-weight: 800; color: #0f172a; line-height: 1.1; }
        .stat-box .stat-lbl { font-size: 10.5px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 2px; font-weight: 700; }

        .empty-state { text-align: center; padding: 30px 20px; color: #64748b; }
        .empty-state i { font-size: 28px; opacity: 0.5; display: block; margin-bottom: 6px; color: var(--accent-blue); }

        .dashboard-footer { margin-top: 20px; padding-top: 14px; border-top: 1px solid var(--card-border); display: flex; justify-content: space-between; font-size: 11.5px; color: var(--text-muted); flex-wrap: wrap; gap: 10px; }

        /* PERMIT PREVIEW */
        .permit-preview-overlay { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(153, 27, 27, 0.45); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 5vh 3vw; opacity: 0; visibility: hidden; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .permit-preview-overlay.active { opacity: 1; visibility: visible; }
        .permit-preview-content { width: 100%; max-width: 900px; height: 88vh; background: #ffffff; border: 1px solid var(--card-border); border-radius: 20px; box-shadow: 0 25px 50px rgba(220, 38, 38, 0.25); overflow: hidden; display: flex; flex-direction: column; transform: translateY(-16px) scale(0.98); transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .permit-preview-overlay.active .permit-preview-content { transform: translateY(0) scale(1); }
        .permit-preview-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid var(--card-border); background: #f8fafc; gap: 12px; flex-wrap: wrap; }
        .permit-preview-header strong { font-size: 14px; color: #0f172a; }
        .permit-preview-header .permit-preview-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .permit-preview-close { background: #f1f5f9; border: 1px solid #cbd5e1; color: #64748b; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s ease; }
        .permit-preview-close:hover { background: var(--danger-bg); color: var(--danger); border-color: #fca5a5; }
        .permit-preview-body { flex: 1; background: #f8fafc; display: flex; align-items: center; justify-content: center; overflow: auto; padding: 20px; }
        .permit-preview-body iframe { width: 100%; height: 100%; border: 0; background: #fff; }
        .permit-preview-body img { max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }

        /* SEARCH MODAL */
        .search-modal-overlay { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(153, 27, 27, 0.4); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); z-index: 9999; display: flex; align-items: flex-start; justify-content: center; padding: 10vh 16px 16px; opacity: 0; visibility: hidden; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .search-modal-overlay.active { opacity: 1; visibility: visible; }
        .search-modal-content { width: 100%; max-width: 650px; background: #ffffff; border: 1px solid var(--card-border); border-radius: 20px; box-shadow: 0 25px 50px rgba(220, 38, 38, 0.2); overflow: hidden; transform: translateY(-20px) scale(0.98); transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); max-height: 85vh; display: flex; flex-direction: column; }
        .search-modal-overlay.active .search-modal-content { transform: translateY(0) scale(1); }
        .search-modal-header { display: flex; align-items: center; padding: 14px 18px; border-bottom: 1px solid var(--card-border); background: #f8fafc; flex-shrink: 0; }
        .search-modal-header i.search-icon-main { font-size: 16px; color: var(--accent-blue); margin-right: 14px; }
        .search-modal-input { flex: 1; background: transparent; border: none; outline: none; color: #0f172a; font-size: 15px; font-weight: 600; }
        .search-modal-close { background: #f1f5f9; border: 1px solid #cbd5e1; color: #64748b; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s ease; flex-shrink: 0; }
        .search-modal-close:hover { background: var(--danger-bg); color: var(--danger); border-color: #fca5a5; }
        .search-modal-body { padding: 16px; max-height: 380px; overflow-y: auto; flex: 1; }
        .search-shortcut-hint { display: flex; align-items: center; justify-content: space-between; padding: 12px 18px; background: #f8fafc; border-top: 1px solid var(--card-border); font-size: 11px; color: var(--text-muted); flex-shrink: 0; }
        .search-shortcut-hint kbd { background: var(--blue-soft); border: 1px solid var(--card-border); padding: 2px 6px; border-radius: 4px; color: var(--accent-blue-dark); font-family: inherit; font-weight: 700; }

        /* AI ASSISTANT */
        #aiAssistantWrapper { position: fixed; bottom: calc(25px + var(--safe-bottom)); right: calc(25px + var(--safe-right)); z-index: 1050; font-family: 'Plus Jakarta Sans', sans-serif; }
        #aiToggleBtn { width: 55px; height: 55px; border-radius: 50%; background: linear-gradient(135deg, #dc2626, #991b1b); border: none; color: #fff; font-size: 22px; cursor: pointer; box-shadow: 0 10px 25px rgba(220, 38, 38, 0.4); display: flex; align-items: center; justify-content: center; }
        #aiChatWindow { position: absolute; bottom: 70px; right: 0; width: 350px; height: 480px; background: #ffffff; border: 1px solid var(--card-border); border-radius: 18px; box-shadow: 0 20px 40px rgba(220, 38, 38, 0.2); display: none; flex-direction: column; overflow: hidden; }

        /* RESPONSIVE */
        @media (max-width: 1400px) { .nav-link { padding: 10px 8px; font-size: 12px; } }
        
        @media (max-width: 1200px) {
            .top-navbar { padding: 10px 14px; padding-top: calc(10px + var(--safe-top)); gap: 10px; flex-wrap: wrap; min-height: 60px; }
            .nav-clock { display: none; }
            .profile-btn .info { display: none; }
            .profile-btn { padding: 3px; gap: 0; }
            .menu-toggle { display: flex; }
            .search-wrapper { order: 5; width: 100%; padding: 0; margin: 4px 0; max-width: 100%; flex-basis: 100%; }

            .main-nav-bar { position: fixed; top: 0; left: -100%; width: 85vw; max-width: 320px; height: 100vh; height: 100dvh; flex-direction: column; align-items: stretch; justify-content: flex-start; padding: 0; z-index: 2000; overflow-y: auto; -webkit-overflow-scrolling: touch; transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1); background: #ffffff; box-shadow: 6px 0 25px rgba(0,0,0,0.18); }
            .main-nav-bar.mobile-open { left: 0 !important; }

            .mobile-drawer-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 18px; padding-top: calc(16px + var(--safe-top)); background: linear-gradient(90deg, #991b1b, #dc2626 55%, #991b1b); color: #fff; flex-shrink: 0; border-bottom: 2px solid #7f1d1d; }
            .mobile-drawer-header .drawer-brand { display: flex; align-items: center; gap: 10px; }
            .mobile-drawer-header .drawer-brand-logo { width: 32px; height: 32px; border-radius: 50%; background: #ffffff; color: var(--accent-blue); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 12px; }
            .mobile-drawer-header .drawer-brand-text h2 { font-size: 13px; font-weight: 800; margin: 0; color: #fff; }
            .mobile-drawer-header .drawer-brand-text span { font-size: 9px; opacity: 0.85; text-transform: uppercase; letter-spacing: 1px; font-weight: 600; }
            .mobile-drawer-header .drawer-close-btn { width: 34px; height: 34px; border-radius: 8px; background: rgba(255,255,255,0.18); border: 1px solid rgba(255,255,255,0.35); color: #fff; cursor: pointer; font-size: 15px; display: flex; align-items: center; justify-content: center; }
            .main-nav-bar .nav-menu { flex-direction: column; width: 100%; justify-content: flex-start; gap: 4px; padding: 14px; padding-bottom: calc(20px + var(--safe-bottom)); }
            .nav-item { width: 100%; }
            .nav-link { padding: 14px 16px; border-radius: 10px; justify-content: flex-start; font-size: 14.5px; min-height: 48px; }
            .nav-link .chevron { margin-left: auto; font-size: 11px; }
            .nav-link .nav-ico { font-size: 15px; width: 18px; }

            .nav-dropdown { position: static !important; left: auto !important; right: auto !important; transform: none !important; opacity: 1 !important; visibility: visible !important; box-shadow: none !important; border: none !important; max-height: 0; overflow: hidden; transition: max-height 0.3s ease, padding 0.2s ease; padding: 0 0 0 16px; background: #f8fafc; margin-top: 4px; border-radius: 10px; min-width: 0; width: 100% !important; }
            .nav-item.open > .nav-dropdown,
            .nav-item.align-right.open > .nav-dropdown { max-height: 1000px !important; padding: 8px 0 8px 16px !important; }
            .nav-dropdown a { padding: 12px 14px; min-height: 44px; font-size: 13.5px; display: flex; align-items: center; }

            .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.55); z-index: 1999; backdrop-filter: blur(3px); -webkit-backdrop-filter: blur(3px); opacity: 0; transition: opacity 0.3s ease; pointer-events: none; }
            .sidebar-overlay.active { display: block; opacity: 1; pointer-events: auto; }

            .notification-dropdown { position: fixed; top: 70px; left: 12px; right: 12px; width: auto !important; max-width: none; transform: translateY(-10px) scale(0.98); }
            .notification-dropdown.show { transform: translateY(0) scale(1); }
        }

        @media (max-width: 992px) {
            .search-modal-overlay { padding-top: 8vh; }
        }

        @media (max-width: 768px) {
            .content { padding: 16px 14px 24px; padding-bottom: calc(24px + var(--safe-bottom)); }
            .hero-card { flex-direction: column; align-items: flex-start; gap: 12px; padding: 18px 20px; }
            .hero-card h2 { font-size: 18px; }
            .panel-header { padding: 12px 16px; font-size: 14px; flex-wrap: wrap; gap: 8px; }
            .panel-body { padding: 14px; }
            .dashboard-footer { flex-direction: column; text-align: center; gap: 6px; font-size: 10.5px; }

            .table-responsive { overflow: visible; }
            .table-custom { display: block; }
            .table-custom thead { display: none; }
            .table-custom tbody { display: block; padding: 12px; }
            .table-custom tr {
                display: block;
                background: #ffffff;
                border: 1px solid var(--card-border);
                border-radius: 14px;
                margin-bottom: 12px;
                padding: 14px;
                box-shadow: 0 2px 8px rgba(220, 38, 38, 0.04);
            }
            .table-custom tr:last-child { margin-bottom: 0; }
            .table-custom tbody tr:hover { background: #ffffff; }
            .table-custom td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 12px;
                padding: 8px 0;
                border-bottom: 1px dashed #f1f5f9;
                font-size: 13px;
                text-align: right;
            }
            .table-custom td:first-child { padding-top: 0; }
            .table-custom td:last-child {
                border-bottom: none;
                padding-bottom: 0;
                margin-top: 8px;
                padding-top: 12px;
                border-top: 1px solid #f1f5f9;
                justify-content: center;
            }
            .table-custom td::before {
                content: attr(data-label);
                font-weight: 700;
                font-size: 10.5px;
                text-transform: uppercase;
                letter-spacing: 0.4px;
                color: #64748b;
                flex-shrink: 0;
                text-align: left;
            }
            .table-custom td > * { text-align: right; }
            .table-custom td strong { font-size: 13.5px; word-break: break-word; }
            
            .table-custom td:last-child .btn-secondary-custom { width: 100%; padding: 10px 14px; font-size: 13px; min-height: 44px; justify-content: center; }
            
            .empty-state { padding: 30px 16px; font-size: 12.5px; }
            .empty-state i { font-size: 30px; }

            #aiAssistantWrapper { bottom: calc(18px + var(--safe-bottom)); right: calc(18px + var(--safe-right)); }
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

            .permit-preview-overlay { padding: 0; align-items: flex-end; }
            .permit-preview-content { height: 92vh; border-radius: 20px 20px 0 0; max-width: 100%; }
            .permit-preview-header { padding: 12px 16px; }
            .permit-preview-header strong { font-size: 13px; max-width: 100%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .permit-preview-header .permit-preview-actions { width: 100%; justify-content: space-between; margin-top: 8px; }
            .permit-preview-header .btn-secondary-custom { flex: 1; justify-content: center; }
            .permit-preview-body { padding: 10px; }

            .search-modal-overlay { padding: 6vh 12px 12px; }
            .search-modal-content { border-radius: 16px; }
            .search-modal-header { padding: 14px 16px; }
            .search-modal-body { padding: 14px; max-height: 55vh; }
            .search-shortcut-hint { font-size: 10.5px; padding: 10px 14px; }
        }

        @media (max-width: 480px) {
            .content { padding: 12px 10px 22px; padding-bottom: calc(22px + var(--safe-bottom)); }
            .profile-dropdown { width: calc(100vw - 24px); max-width: 280px; right: -8px; }
            #aiChatWindow { height: calc(100vh - 130px) !important; max-height: 500px; }
            .stats-row { flex-direction: column; gap: 10px; }
            .stat-box { min-width: 100%; }
            .table-custom tbody { padding: 10px; }
            .table-custom tr { padding: 12px; }
            .table-custom td { font-size: 12.5px; padding: 7px 0; }
            .table-custom td::before { font-size: 10px; }
        }

        @media (max-width: 767px) {
            input, select, textarea { font-size: 16px !important; }
            .search-container input, .search-modal-input, .form-control, .form-select { font-size: 16px !important; }
            #aiChatInput { font-size: 16px !important; }
        }

        @media (hover: none) and (pointer: coarse) {
            .nav-link, .nav-dropdown a, .btn-secondary-custom, .profile-dropdown .dropdown-item, .notif-drop-item {
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
                            <button type="button" class="nav-link active" data-dropdown>
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
                                            $notifLink = 'documents.php';
                                            if (!empty($notification['link'])) $notifLink = $notification['link'];
                                            else {
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
                    <h2><i class="fa-solid fa-file-arrow-up me-1"></i> Document Repository</h2>
                    <p>Securely store and manage approved LGU Permits, Barangay Clearances, Tax Declarations, and Certificates.</p>
                </div>
            </div>

            <!-- STATS ROW -->
            <div class="stats-row">
                <div class="stat-box stat-biz">
                    <div class="stat-icon"><i class="fa-solid fa-file-shield"></i></div>
                    <div>
                        <div class="stat-num"><?php echo $totalPermitsCount; ?></div>
                        <div class="stat-lbl">Permits Received</div>
                    </div>
                </div>
                <div class="stat-box stat-doc">
                    <div class="stat-icon"><i class="fa-solid fa-folder-open"></i></div>
                    <div>
                        <div class="stat-num"><?php echo $totalDocumentsCount; ?></div>
                        <div class="stat-lbl">Documents Uploaded</div>
                    </div>
                </div>
            </div>

            <!-- ALERT BANNERS -->
            <?php if ($expiredCount > 0): ?>
                <div class="alert-banner banner-danger">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <div>
                        <h4>You have <?php echo $expiredCount; ?> Expired Permit(s)!</h4>
                        <p>Your document has already passed its validity date. Please check the list below and submit a renewal using the Online Renewal page to avoid penalties.</p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($expiringCount > 0): ?>
                <div class="alert-banner banner-warning">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div>
                        <h4>You have <?php echo $expiringCount; ?> Permit(s) nearing expiration.</h4>
                        <p>The following permits will expire within 30 days. You are advised to renew before they fully expire.</p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage): ?>
                <div class="alert alert-danger" style="border-radius:12px; font-size:13px;"><?php echo h($errorMessage); ?></div>
            <?php endif; ?>

            <!-- APPROVED PERMITS -->
            <div class="panel">
                <div class="panel-header" style="background: #fee2e2; color: #991b1b;">
                    <span><i class="fa-solid fa-certificate"></i> Approved LGU Permits &amp; Certificates (Issued by Staff)</span>
                    <span style="font-size:11px; font-weight:700; background:#fff; color:#991b1b; padding:4px 12px; border-radius:999px;"><?php echo $totalPermitsCount; ?> Permit<?php echo $totalPermitsCount==1?'':'s'; ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>Permit No.</th>
                                <th>Permit / Certificate Title</th>
                                <th>Classification</th>
                                <th>Date Issued</th>
                                <th>Validity</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($approvedPermits)): ?>
                                <tr>
                                    <td colspan="6" class="empty-state">
                                        <i class="fa-solid fa-file-circle-check"></i>
                                        <p style="margin:0;">No approved LGU permits issued yet. Please wait for LGU Staff verification &amp; approval.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($approvedPermits as $permit):
                                    $fPath = !empty($permit['owner_folder_path']) ? $permit['owner_folder_path'] : ('user_' . $userId);
                                    
                                    // ✅ UPDATE: Kung Permit, gamitin ang view_permit.php. Kung file upload, sa uploads folder.
                                    if (($permit['type'] ?? '') === 'Permit' || strpos(strtolower($permit['title'] ?? ''), 'permit') !== false) {
                                        $permitUrl = 'view_permit.php?id=' . $permit['id'];
                                    } else {
                                        $permitUrl = '../uploads/' . $fPath . '/' . $permit['stored_name'];
                                    }
                                    
                                    $validityLabels = ['valid' => 'Active', 'expiring' => 'Expiring Soon', 'expired' => 'Expired', 'unknown' => 'N/A'];
                                    
                                    $rowClass = '';
                                    if ($permit['validityStatus'] === 'expired') $rowClass = 'row-expired';
                                    elseif ($permit['validityStatus'] === 'expiring') $rowClass = 'row-expiring';
                                ?>
                                    <tr class="<?php echo $rowClass; ?>">
                                        <td data-label="Permit No.">
                                            <?php if (!empty($permit['permitNumber'])): ?>
                                                <span style="font-family:monospace; font-weight:700; color:#991b1b; background:#fee2e2; padding:3px 10px; border-radius:6px; font-size:12px; display:inline-block;"><?php echo h($permit['permitNumber']); ?></span>
                                                <?php if (!empty($permit['typeLabel'])): ?><br><small style="font-size:10px; color:#64748b;"><?php echo h($permit['typeLabel']); ?></small><?php endif; ?>
                                            <?php else: ?>
                                                <span style="color:#94a3b8; font-style:italic; font-size:11px;">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Title">
                                            <strong style="color:#0f172a;"><?php echo h($permit['title']); ?></strong><br>
                                            <span style="font-size:11px; color:#64748b;"><?php echo h($permit['file_name']); ?></span>
                                        </td>
                                        <td data-label="Classification"><span class="status-badge <?php echo h($permit['badgeClass']); ?>"><?php echo h($permit['classification']); ?></span></td>
                                        <td data-label="Date Issued"><?php echo date('M d, Y h:i A', strtotime($permit['uploaded_at'])); ?></td>
                                        <td data-label="Validity">
                                            <div>
                                                <span class="status-badge <?php echo h($permit['validityStatus']); ?>">
                                                    <?php echo h($validityLabels[$permit['validityStatus']]); ?>
                                                </span>
                                                
                                                <?php if ($permit['validUntil']): ?>
                                                    <div style="font-size:11px; color:#64748b; margin-top:3px;">Until <?php echo date('M d, Y', strtotime($permit['validUntil'])); ?></div>
                                                    
                                                    <?php if ($permit['validityStatus'] === 'expired'): ?>
                                                        <span class="days-left-text text-danger"><i class="fa-solid fa-circle-xmark"></i> <?php echo h($permit['daysText']); ?></span>
                                                    <?php elseif ($permit['validityStatus'] === 'expiring'): ?>
                                                        <span class="days-left-text text-warning"><i class="fa-solid fa-clock"></i> <?php echo h($permit['daysText']); ?></span>
                                                    <?php elseif ($permit['validityStatus'] === 'valid'): ?>
                                                        <span class="days-left-text text-success"><i class="fa-solid fa-circle-check"></i> <?php echo h($permit['daysText']); ?></span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <button type="button" class="btn-secondary-custom view-permit-btn"
                                                    data-title="<?php echo h($permit['title']); ?>"
                                                    data-url="<?php echo h($permitUrl); ?>">
                                                <i class="fa-solid fa-eye"></i> View Permit
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- UPLOAD + VAULT -->
            <div class="row g-3">
                <div class="col-lg-4">
                    <div class="panel">
                        <div class="panel-header"><span><i class="fa-solid fa-cloud-arrow-up"></i> Upload New File</span></div>
                        <div class="panel-body">
                            <form method="POST" enctype="multipart/form-data">
                                <div class="mb-3 form-group">
                                    <label>Document Title *</label>
                                    <input type="text" name="document_title" class="form-control" required placeholder="e.g. Barangay Clearance 2026">
                                </div>
                                <div class="mb-3 form-group">
                                    <label>Category *</label>
                                    <select name="document_type" class="form-select">
                                        <option value="Mayor Permit">Mayor's Permit Support</option>
                                        <option value="Tax Declaration">Tax Declaration</option>
                                        <option value="Fire Safety">BFP Fire Safety Certificate</option>
                                        <option value="Sanitary Clearance">Sanitary &amp; Health Permit</option>
                                        <option value="Blueprint / Plan">Building Floor Plan</option>
                                        <option value="Other">Other Supporting Document</option>
                                    </select>
                                </div>
                                <div class="mb-3 form-group">
                                    <label>Select File (Max 5MB) *</label>
                                    <input type="file" name="document_file" class="form-control" required>
                                </div>
                                <button type="submit" class="btn-primary-custom"><i class="fa-solid fa-upload"></i> Upload to Vault</button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-8">
                    <div class="panel">
                        <div class="panel-header">
                            <span><i class="fa-solid fa-folder-open"></i> Uploaded Document Vault</span>
                            <span style="font-size:11px; font-weight:700; background:var(--blue-soft); color:var(--accent-blue); padding:4px 12px; border-radius:999px;"><?php echo count($documents); ?> File<?php echo count($documents)==1?'':'s'; ?></span>
                        </div>
                        <div class="table-responsive">
                            <table class="table-custom">
                                <thead>
                                    <tr>
                                        <th>File Name</th>
                                        <th>Category</th>
                                        <th>Uploaded Date</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($documents)): ?>
                                        <tr>
                                            <td colspan="4" class="empty-state">
                                                <i class="fa-solid fa-folder-open"></i>
                                                <p style="margin:0;">No documents uploaded yet.</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($documents as $doc):
                                            $fPath = !empty($doc['owner_folder_path']) ? $doc['owner_folder_path'] : ('user_' . $userId);
                                            $docUrl = '../uploads/' . $fPath . '/' . $doc['stored_name'];
                                        ?>
                                            <tr>
                                                <td data-label="File Name">
                                                    <strong style="color:#0f172a;"><?php echo h($doc['title']); ?></strong><br>
                                                    <span style="font-size:11px; color:#64748b;"><?php echo h($doc['file_name']); ?></span>
                                                </td>
                                                <td data-label="Category"><span class="status-badge active"><?php echo h($doc['type']); ?></span></td>
                                                <td data-label="Uploaded Date"><?php echo date('M d, Y', strtotime($doc['uploaded_at'])); ?></td>
                                                <td>
                                                    <button type="button" class="btn-secondary-custom view-permit-btn"
                                                            data-title="<?php echo h($doc['title']); ?>"
                                                            data-url="<?php echo h($docUrl); ?>">
                                                        <i class="fa-solid fa-eye"></i> View
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
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
                    Hello <?php echo htmlspecialchars($userName); ?>! Ask me about your documents, permits, renewals, or compliance.
                </div>
            </div>
            <div style="padding:10px; background:#ffffff; border-top:1px solid var(--card-border); display:flex; gap:8px; flex-shrink:0;">
                <input type="text" id="aiChatInput" placeholder="Ask e.g., 'How many permits do I have?'..." style="flex:1; min-width:0; padding:8px 12px; border:1px solid var(--card-border); border-radius:999px; outline:none; font-size:12.5px; background:#f8fafc;">
                <button id="aiSendBtn" style="background:var(--accent-blue); border:none; color:#fff; width:36px; height:36px; border-radius:50%; cursor:pointer; display:flex; align-items:center; justify-content:center; flex-shrink:0;" aria-label="Send"><i class="fa-solid fa-paper-plane" style="font-size:12px;"></i></button>
            </div>
        </div>
    </div>

    <!-- PERMIT PREVIEW OVERLAY -->
    <div class="permit-preview-overlay" id="permitPreviewOverlay" onclick="closePermitPreviewOnOutside(event)">
        <div class="permit-preview-content">
            <div class="permit-preview-header">
                <strong id="permitPreviewTitle"><i class="fa-solid fa-certificate" style="color:var(--accent-blue); margin-right:6px;"></i>Document Preview</strong>
                <div class="permit-preview-actions">
                    <button type="button" class="btn-secondary-custom" onclick="window.print()" style="background:var(--blue-soft); border-color:var(--accent-blue-light);">
                        <i class="fa-solid fa-print"></i> Print
                    </button>
                    <a href="#" id="permitPreviewOpenNewTab" target="_blank" class="btn-secondary-custom">
                        <i class="fa-solid fa-up-right-from-square"></i> New Tab
                    </a>
                    <div class="permit-preview-close" onclick="closePermitPreview()"><i class="fa-solid fa-xmark"></i></div>
                </div>
            </div>
            <div class="permit-preview-body" id="permitPreviewBodyContainer">
                <iframe id="permitPreviewFrame" src="about:blank" title="Document Preview" style="display:none;"></iframe>
                <img id="permitPreviewImage" src="" alt="Document Image" style="display:none;">
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
            closeSearchModal();
            closePermitPreview();
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

    /* PERMIT PREVIEW */
    const permitPreviewOverlay = document.getElementById('permitPreviewOverlay');
    const permitPreviewFrame = document.getElementById('permitPreviewFrame');
    const permitPreviewImage = document.getElementById('permitPreviewImage');
    const permitPreviewTitle = document.getElementById('permitPreviewTitle');
    const permitPreviewOpenNewTab = document.getElementById('permitPreviewOpenNewTab');

    function openPermitPreview(title, url) {
        permitPreviewTitle.innerHTML = '<i class="fa-solid fa-certificate" style="color:var(--accent-blue); margin-right:6px;"></i>' + title;
        permitPreviewOpenNewTab.href = url;

        const ext = url.split('.').pop().toLowerCase().split('?')[0];
        if (['jpg','jpeg','png','gif','webp'].includes(ext)) {
            permitPreviewImage.src = url;
            permitPreviewImage.style.display = 'block';
            permitPreviewFrame.style.display = 'none';
            permitPreviewFrame.src = 'about:blank';
        } else {
            permitPreviewFrame.src = url;
            permitPreviewFrame.style.display = 'block';
            permitPreviewImage.style.display = 'none';
            permitPreviewImage.src = '';
        }
        permitPreviewOverlay.classList.add('active');
    }

    function closePermitPreview() {
        permitPreviewOverlay.classList.remove('active');
        permitPreviewFrame.src = 'about:blank';
        permitPreviewImage.src = '';
    }

    function closePermitPreviewOnOutside(event) {
        if (event.target === permitPreviewOverlay) closePermitPreview();
    }

    document.querySelectorAll('.view-permit-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            openPermitPreview(this.getAttribute('data-title'), this.getAttribute('data-url'));
        });
    });

    /* FLASH MESSAGE */
    <?php if ($flashMessage && $flashText): ?>
        Swal.fire({
            icon: '<?php echo $flashMessage; ?>',
            title: '<?php echo $flashMessage === "success" ? "Success!" : "Notice"; ?>',
            text: '<?php echo h($flashText); ?>',
            confirmButtonColor: '#dc2626'
        });
    <?php endif; ?>

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