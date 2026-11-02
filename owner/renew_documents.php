<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();
date_default_timezone_set('Asia/Manila');

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
        error_log('[renew_documents] ' . $e->getMessage());
        return 'Something went wrong. Please try again.';
    }
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

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
    $totalRentals = 0; $totalRenewals = 0; $pendingRenewals = 0;

    if (tableExists($conn, 'rentals')) {
        $stmtRentals = $conn->prepare("SELECT COUNT(*) FROM rentals WHERE user_id = :uid AND is_deleted = '0'");
        $stmtRentals->execute([':uid' => $userId]);
        $totalRentals = (int)$stmtRentals->fetchColumn();
    }
    if (tableExists($conn, 'permit_renewals')) {
        $stmtRen = $conn->prepare("SELECT COUNT(*) FROM permit_renewals WHERE user_id = :uid");
        $stmtRen->execute([':uid' => $userId]);
        $totalRenewals = (int)$stmtRen->fetchColumn();

        $stmtP = $conn->prepare("SELECT COUNT(*) FROM permit_renewals WHERE user_id = :uid AND LOWER(status::text) = 'pending'");
        $stmtP->execute([':uid' => $userId]);
        $pendingRenewals = (int)$stmtP->fetchColumn();
    }
    $reply = "Hello! I am Rentrack AI. You can ask me about your renewals, permits, rentals, or payments.";
    if (strpos($question, 'renew') !== false) {
        $reply = "You have {$totalRenewals} renewal request(s), with {$pendingRenewals} still pending verification.";
    } elseif (strpos($question, 'permit') !== false) {
        $reply = "You can submit permit renewals online here. Just select a rental, document type, and attach a supporting file.";
    } elseif (strpos($question, 'rental') !== false || strpos($question, 'total') !== false) {
        $reply = "You have {$totalRentals} registered rentals under your account.";
    } elseif (strpos($question, 'payment') !== false) {
        $reply = "Payments for renewals are verified by the LGU Treasurer. Visit the Payments page for details.";
    } elseif (strpos($question, 'hello') !== false || strpos($question, 'hi') !== false) {
        $reply = "Hello! How can I help you today?";
    } else {
        $reply = "You have {$totalRenewals} renewal requests and {$totalRentals} rentals. Need help with permit renewals?";
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

/* FORM SUBMISSION */
$flashSuccess = ''; $flashError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_renewal'])) {
    $rentalId = (int)($_POST['rental_id'] ?? 0);
    $documentType = trim($_POST['document_type'] ?? '');
    $currentExpiry = trim($_POST['current_expiry_date'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');

    $uploadedFile = '';
    if (isset($_FILES['supporting_file']) && $_FILES['supporting_file']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['supporting_file']['tmp_name'];
        $fileName = $_FILES['supporting_file']['name'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
        if (in_array($fileExtension, $allowedExtensions, true) && upload_content_ok($fileTmpPath, $fileExtension)) {
            $newFileName = 'renewal_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $fileExtension;
            $uploadFileDir = '../uploads/renewals/';
            if (!is_dir($uploadFileDir)) mkdir($uploadFileDir, 0755, true);
            $dest_path = $uploadFileDir . $newFileName;
            if (move_uploaded_file($fileTmpPath, $dest_path)) $uploadedFile = $newFileName;
            else $flashError = 'There was an error uploading the file.';
        } else {
            $flashError = 'Invalid file format. Please upload PDF or image files (JPG, PNG).';
        }
    } else {
        $flashError = 'Please attach a supporting document.';
    }

    if (empty($flashError) && $rentalId > 0 && !empty($documentType)) {
        $insertStmt = $conn->prepare("INSERT INTO permit_renewals (user_id, rental_id, document_type, current_expiry_date, supporting_file, remarks, status, created_at) VALUES (:uid, :rid, :dtype, :exp, :sfile, :rem, 'Pending', NOW())");
        if ($insertStmt && $insertStmt->execute([
            ':uid' => $userId, ':rid' => $rentalId, ':dtype' => $documentType,
            ':exp' => $currentExpiry, ':sfile' => $uploadedFile, ':rem' => $remarks
        ])) {
            try {
                $bizLookup = $conn->prepare("SELECT business_name, rental_name, tracking_no FROM rentals WHERE id = :rid LIMIT 1");
                $bizLookup->execute([':rid' => $rentalId]);
                $bizRow = $bizLookup->fetch(PDO::FETCH_ASSOC);
                $bizName = !empty($bizRow['business_name']) ? $bizRow['business_name'] : ($bizRow['rental_name'] ?? 'a property');
                $trackingNo = $bizRow['tracking_no'] ?? '';

                if (tableExists($conn, 'notifications') && columnExists($conn, 'users', 'role')) {
                    $hasLink = columnExists($conn, 'notifications', 'link');
                    $hasTargetUrl = columnExists($conn, 'notifications', 'target_url');
                    $hasIsRead = columnExists($conn, 'notifications', 'is_read');
                    $linkColumn = $hasLink ? 'link' : ($hasTargetUrl ? 'target_url' : null);

                    $adminStmt = $conn->query("SELECT id FROM users WHERE LOWER(role::text) = 'admin' OR LOWER(role::text) = 'administrator'");
                    $adminIds = $adminStmt ? $adminStmt->fetchAll(PDO::FETCH_COLUMN) : [];

                    if (!empty($adminIds)) {
                        $title = 'New Renewal Request';
                        $message = $userName . ' submitted a ' . $documentType . ' for ' . $bizName . ($trackingNo ? ' (' . $trackingNo . ')' : '') . '.';
                        $link = 'renewals_approval.php';

                        foreach ($adminIds as $adminId) {
                            $columns = ['user_id', 'title', 'message'];
                            $placeholders = [':uid', ':title', ':msg'];
                            $params = [':uid' => (int)$adminId, ':title' => $title, ':msg' => $message];
                            if ($linkColumn) { $columns[] = $linkColumn; $placeholders[] = ':link'; $params[':link'] = $link; }
                            if ($hasIsRead) { $columns[] = 'is_read'; $placeholders[] = 'FALSE'; }
                            if (columnExists($conn, 'notifications', 'created_at')) { $columns[] = 'created_at'; $placeholders[] = 'NOW()'; }
                            $sqlNotif = "INSERT INTO notifications (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
                            $conn->prepare($sqlNotif)->execute($params);
                        }
                    }
                }
            } catch (PDOException $e) { error_log('Renewal admin notification error: ' . $e->getMessage()); }

            $_SESSION['flash_success'] = 'Your renewal request has been successfully submitted for LGU verification!';
            header("Location: renew_documents.php");
            exit();
        } else {
            $flashError = 'Failed to save to database. Please try again.';
        }
    } elseif (empty($flashError)) {
        $flashError = 'Please fill out all required information.';
    }
}

if (isset($_SESSION['flash_success'])) { $flashSuccess = $_SESSION['flash_success']; unset($_SESSION['flash_success']); }

/* Expiry column */
$hasPermitExpiry = columnExists($conn, 'rentals', 'permit_expiry_date');
$hasExpiry = columnExists($conn, 'rentals', 'expiry_date');

if ($hasPermitExpiry) {
    $expirySelectSelect = "r.permit_expiry_date AS permit_expiry_date";
    $expirySelectJoin = "r.permit_expiry_date AS updated_expiry_date";
} elseif ($hasExpiry) {
    $expirySelectSelect = "r.expiry_date AS permit_expiry_date";
    $expirySelectJoin = "r.expiry_date AS updated_expiry_date";
} else {
    $expirySelectSelect = "NULL AS permit_expiry_date";
    $expirySelectJoin = "NULL AS updated_expiry_date";
}

/* Rentals */
$rentals = [];
try {
    $rStmt = $conn->prepare("SELECT r.id, r.business_name, r.rental_name, r.tracking_no, {$expirySelectSelect} FROM rentals r WHERE r.user_id = :uid AND (r.is_deleted = '0' OR r.is_deleted = FALSE)");
    $rStmt->execute([':uid' => $userId]);
    $rentals = $rStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $rStmt = $conn->prepare("SELECT r.id, r.business_name, r.rental_name, r.tracking_no, {$expirySelectSelect} FROM rentals r WHERE r.user_id = :uid AND r.is_deleted = '0'");
    $rStmt->execute([':uid' => $userId]);
    $rentals = $rStmt->fetchAll(PDO::FETCH_ASSOC);
}

/* Renewals */
$renewals = [];
$renStmt = $conn->prepare("
    SELECT pr.*, r.business_name, r.rental_name, r.tracking_no, {$expirySelectJoin}
    FROM permit_renewals pr 
    JOIN rentals r ON pr.rental_id = r.id 
    WHERE pr.user_id = :uid 
    ORDER BY pr.created_at DESC
");
if ($renStmt) { $renStmt->execute([':uid' => $userId]); $renewals = $renStmt->fetchAll(PDO::FETCH_ASSOC); }

$hasDocuments = tableExists($conn, 'documents');
$hasPermitNoCol = $hasDocuments && columnExists($conn, 'documents', 'business_permit_no');

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
    <title>Online Renewal &amp; Permits | RENTRACK LGU</title>

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

        /* PANELS */
        .panel {
            background: var(--bg-card); border: 1px solid var(--card-border);
            border-radius: 16px; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.04);
            overflow: hidden; margin-bottom: 20px;
        }
        .panel-header {
            padding: 14px 20px; background: #ffffff; border-bottom: 1px solid var(--card-border);
            font-family: 'Space Grotesk', sans-serif; font-size: 15px; font-weight: 800; color: #0f172a;
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
            flex-wrap: wrap;
        }
        .panel-header i { color: var(--accent-blue); }
        .panel-body { padding: 20px; }

        /* FORM */
        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; font-size: 12px; font-weight: 700; color: #475569; margin-bottom: 6px; }
        .form-control, .form-select {
            width: 100%; border: 1px solid var(--card-border); border-radius: 10px;
            padding: 9px 14px; font-size: 13px; outline: none; background: #f8fafc;
            font-family: inherit; transition: var(--transition);
        }
        .form-control:focus, .form-select:focus { border-color: var(--accent-blue); background: #fff; box-shadow: 0 0 10px rgba(220, 38, 38, 0.1); }

        .btn-primary-custom {
            background: var(--accent-blue); color: #fff; border: none; padding: 10px 22px; border-radius: 10px;
            font-weight: 700; font-size: 13px; cursor: pointer; transition: var(--transition);
            display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-primary-custom:hover { background: var(--accent-blue-dark); color: #fff; }

        /* TABLE */
        .table-responsive { overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; margin-bottom: 0; font-size: 13px; }
        .table th { background: #f8fafc; color: var(--accent-blue-dark); padding: 12px 16px; font-weight: 800; text-transform: uppercase; font-size: 11px; border-bottom: 1px solid var(--card-border); text-align: left; white-space: nowrap; }
        .table td { padding: 12px 16px; border-bottom: 1px solid #f1f5f9; color: #334155; vertical-align: middle; }
        .table tr:hover { background: #fef2f2; }

        .badge-custom { padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; }
        .badge-success { background: #dcfce7; color: #166534; }
        .badge-warning { background: #fef9c3; color: #854d0e; }
        .badge-danger { background: #fee2e2; color: #b91c1c; }

        .empty-state { text-align: center; padding: 40px 20px; color: #64748b; }
        .empty-state i { font-size: 32px; opacity: 0.5; display: block; margin-bottom: 8px; color: var(--accent-blue); }
        .empty-state h3 { font-size: 16px; color: #0f172a; font-weight: 700; margin: 0; }

        /* MODAL */
        .modal-content { border-radius: 18px; border: 1px solid var(--card-border); }

        .zoom-image-container {
            overflow: auto; max-height: 70vh; display: flex; justify-content: center; align-items: center;
            background: #0f172a; border-radius: 12px; padding: 10px;
        }
        .preview-image-zoomable {
            max-width: 100%; transition: transform 0.3s ease; cursor: zoom-in; object-fit: contain;
        }
        .preview-image-zoomable.is-zoomed { cursor: zoom-out; transform: scale(2); max-width: none; }

        .dashboard-footer { margin-top: 20px; padding-top: 14px; border-top: 1px solid var(--card-border); display: flex; justify-content: space-between; font-size: 11.5px; color: var(--text-muted); flex-wrap: wrap; gap: 10px; }

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
            .hero-card { flex-direction: column; align-items: flex-start; gap: 12px; padding: 18px 20px; }
            .hero-card h2 { font-size: 18px; }
            .panel-header { padding: 12px 16px; font-size: 14px; }
            .panel-body { padding: 14px; }
            .dashboard-footer { flex-direction: column; text-align: center; gap: 6px; font-size: 10.5px; }
        }
        @media (max-width: 480px) {
            .content { padding: 12px 10px 22px; padding-bottom: calc(22px + var(--safe-bottom)); }
        }
        @media (max-width: 767px) {
            input, select, textarea { font-size: 16px !important; }
            .search-container input, .search-modal-input, .form-control, .form-select { font-size: 16px !important; }
        }
    </style>
</head>
<body>

    <form id="logoutForm" action="../logout.php" method="POST" style="display:none;"></form>

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
                                    <button type="button" id="notifClearBtn" style="background:transparent; border:none; color:var(--accent-blue); font-size:11px; font-weight:700; cursor:pointer; padding:2px 4px;">Clear</button>
                                </div>
                            </div>
                            <div style="max-height:280px; overflow-y:auto;" id="notifListBody">
                                <?php if (!empty($notifications)): ?>
                                    <?php foreach ($notifications as $notification): ?>
                                        <?php
                                            $notifLink = 'renew_documents.php';
                                            if (!empty($notification['link'])) $notifLink = $notification['link'];
                                            elseif (!empty($notification['target_url'])) $notifLink = $notification['target_url'];
                                            else {
                                                $titleLower = strtolower($notification['title'] ?? '');
                                                $msgLower = strtolower($notification['message'] ?? '');
                                                if (strpos($titleLower, 'rental') !== false || strpos($msgLower, 'rental') !== false) $notifLink = 'rentals.php';
                                                elseif (strpos($titleLower, 'inspection') !== false || strpos($msgLower, 'inspection') !== false) $notifLink = 'inspections.php';
                                                elseif (strpos($titleLower, 'payment') !== false || strpos($msgLower, 'payment') !== false) $notifLink = 'payments.php';
                                                elseif (strpos($titleLower, 'renewal') !== false || strpos($msgLower, 'renewal') !== false) $notifLink = 'renew_documents.php';
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
                    <h2><i class="fa-solid fa-file-arrow-up me-2"></i> Online Permit &amp; Document Renewal</h2>
                    <p>Submit your renewal requirements online without needing to visit the municipal hall.</p>
                </div>
            </div>

            <?php if (!empty($flashSuccess)): ?>
                <script>
                    document.addEventListener("DOMContentLoaded", function() {
                        Swal.fire({ icon: 'success', title: 'Success!', text: <?php echo json_encode($flashSuccess); ?>, confirmButtonColor: '#dc2626' });
                    });
                </script>
            <?php endif; ?>

            <?php if (!empty($flashError)): ?>
                <div class="alert alert-danger" style="border-radius: 12px; font-size: 13px; margin-bottom: 20px;">
                    <i class="fa-solid fa-circle-exclamation me-1"></i> <?php echo h($flashError); ?>
                </div>
            <?php endif; ?>

            <!-- FORM PANEL -->
            <div class="panel">
                <div class="panel-header">
                    <span><i class="fa-solid fa-circle-plus"></i> File a New Renewal Request</span>
                </div>
                <div class="panel-body">
                    <form id="renewalForm" action="" method="POST" enctype="multipart/form-data">
                        <div class="row g-3">
                            <div class="col-md-6 form-group">
                                <label><i class="fa-solid fa-building me-1"></i> Select Rental Property <span class="text-danger">*</span></label>
                                <select name="rental_id" id="rentalSelect" class="form-select" required onchange="updateExpiryField(this)">
                                    <option value="">-- Choose your property --</option>
                                    <?php foreach ($rentals as $r): ?>
                                        <option value="<?php echo $r['id']; ?>" data-expiry="<?php echo h($r['permit_expiry_date'] ?? date('Y-m-d')); ?>">
                                            <?php echo h(!empty($r['business_name']) ? $r['business_name'] : $r['rental_name']); ?> (<?php echo h($r['tracking_no']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6 form-group">
                                <label><i class="fa-solid fa-file-lines me-1"></i> Document / Permit Type <span class="text-danger">*</span></label>
                                <select name="document_type" class="form-select" required>
                                    <option value="">-- Select Type --</option>
                                    <option value="Business Permit Renewal">Business Permit (Mayor's Permit)</option>
                                    <option value="Sanitary Permit Renewal">Sanitary Permit</option>
                                    <option value="Fire Safety Inspection Certificate Renewal">Fire Safety Inspection Certificate (FSIC)</option>
                                    <option value="Barangay Clearance Renewal">Barangay Clearance (for Business)</option>
                                    <option value="Health Certificates Renewal">Health Certificates (Staff/Boarding House)</option>
                                    <option value="Lease Contract Renewal">Lease Contract / Rental Agreement</option>
                                    <option value="Lease Clearance Certificate Renewal">Lease Clearance Certificate</option>
                                    <option value="Occupancy Compliance Permit Renewal">Occupancy Compliance Permit</option>
                                    <option value="Building / Zoning Clearance Renewal">Building / Zoning Clearance</option>
                                    <option value="Other Permit Renewal">Other Permit (Specify in Remarks)</option>
                                </select>
                            </div>

                            <div class="col-md-6 form-group">
                                <label><i class="fa-solid fa-calendar me-1"></i> Current Expiry Date <span class="text-danger">*</span></label>
                                <input type="date" name="current_expiry_date" id="current_expiry_date" class="form-control" required>
                            </div>

                            <div class="col-md-6 form-group">
                                <label><i class="fa-solid fa-upload me-1"></i> Upload Supporting Document (PDF / Image) <span class="text-danger">*</span></label>
                                <input type="file" name="supporting_file" class="form-control" accept=".pdf, .jpg, .jpeg, .png" required>
                            </div>

                            <div class="col-12 form-group">
                                <label><i class="fa-solid fa-note-sticky me-1"></i> Additional Remarks or Message (Optional)</label>
                                <textarea name="remarks" class="form-control" rows="2" placeholder="Enter other necessary details if needed..."></textarea>
                            </div>

                            <div class="col-12 text-end pt-2">
                                <button type="button" id="btnOpenPaymentModal" class="btn-primary-custom">
                                    <i class="fa-solid fa-paper-plane"></i> Submit Renewal Online
                                </button>
                                <button type="submit" name="submit_renewal" id="realSubmitBtn" style="display: none;"></button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- HISTORY PANEL -->
            <div class="panel">
                <div class="panel-header">
                    <span><i class="fa-solid fa-clock-rotate-left"></i> Renewal Requests History and Status</span>
                    <span class="badge" style="background:var(--blue-soft); color:var(--accent-blue-dark); font-size:11px; padding:4px 12px; border-radius:999px; font-weight:700;"><?php echo count($renewals); ?> Requests</span>
                </div>
                <div class="table-responsive">
                    <?php if (!empty($renewals)): ?>
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>Request Date</th>
                                    <th>Property / Business</th>
                                    <th>Document Type</th>
                                    <th>Permit No.</th>
                                    <th>Valid Expiry Date</th>
                                    <th>Attachment</th>
                                    <th class="text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($renewals as $row): ?>
                                <?php
                                    $st = strtolower($row['status']);
                                    $bizName = !empty($row['business_name']) ? $row['business_name'] : $row['rental_name'];

                                    if ($st === 'approved') {
                                        $displayExpiry = !empty($row['updated_expiry_date']) ? $row['updated_expiry_date'] : $row['current_expiry_date'];
                                        $expiryBadge = '<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 ms-1" style="font-size:10px;"><i class="fa-solid fa-calendar-check me-1"></i> Approved Renewal</span>';
                                    } elseif ($st === 'rejected') {
                                        $displayExpiry = $row['current_expiry_date'];
                                        $expiryBadge = '<span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 ms-1" style="font-size:10px;"><i class="fa-solid fa-circle-xmark me-1"></i> Rejected</span>';
                                    } else {
                                        $displayExpiry = $row['current_expiry_date'];
                                        $expiryBadge = '<span class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25 ms-1" style="font-size:10px;"><i class="fa-solid fa-clock me-1"></i> Pending Verification</span>';
                                    }

                                    $linkedPermitNo = null;
                                    if ($st === 'approved' && $hasDocuments && $hasPermitNoCol) {
                                        try {
                                            $doctypeKeyword = str_replace(' Renewal', '', $row['document_type']);
                                            $permitLookupStmt = $conn->prepare("
                                                SELECT business_permit_no FROM documents 
                                                WHERE user_id = :uid AND rental_id = :rid 
                                                  AND business_permit_no IS NOT NULL 
                                                  AND title ILIKE :doctype
                                                ORDER BY uploaded_at DESC LIMIT 1
                                            ");
                                            $permitLookupStmt->execute([':uid' => $userId, ':rid' => $row['rental_id'], ':doctype' => '%' . $doctypeKeyword . '%']);
                                            $linkedPermitNo = $permitLookupStmt->fetchColumn();
                                        } catch (PDOException $e) { $linkedPermitNo = null; }
                                    }
                                ?>
                                <tr>
                                    <td><?php echo date('M d, Y h:i A', strtotime($row['created_at'])); ?></td>
                                    <td>
                                        <strong><?php echo h($bizName); ?></strong><br>
                                        <small style="color:#64748b;"><?php echo h($row['tracking_no']); ?></small>
                                    </td>
                                    <td><?php echo h($row['document_type']); ?></td>
                                    <td>
                                        <?php if ($linkedPermitNo): ?>
                                            <span style="font-family:monospace; font-weight:700; color:var(--accent-blue-dark); background:var(--blue-soft); padding:3px 10px; border-radius:6px; font-size:11.5px; display:inline-block;"><?php echo h($linkedPermitNo); ?></span>
                                        <?php else: ?>
                                            <span style="color:#94a3b8; font-style:italic; font-size:11px;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong style="color:var(--accent-blue);"><?php echo h($displayExpiry); ?></strong><br>
                                        <?php echo $expiryBadge; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['supporting_file'])): ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary open-view-file-modal" style="font-size:11px; border-radius:8px; padding:4px 10px; color:var(--accent-blue); border-color:var(--card-border);"
                                                    data-file="../uploads/renewals/<?php echo h($row['supporting_file']); ?>"
                                                    data-title="<?php echo h($row['document_type']); ?> - <?php echo h($bizName); ?>">
                                                <i class="fa-solid fa-eye"></i> View File
                                            </button>
                                        <?php else: ?>
                                            <span style="color:#94a3b8;">No File</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($st === 'approved'): ?>
                                            <span class="badge-custom badge-success"><i class="fa-solid fa-check"></i> Approved &amp; Renewed</span>
                                        <?php elseif ($st === 'rejected'): ?>
                                            <span class="badge-custom badge-danger"><i class="fa-solid fa-xmark"></i> Rejected</span>
                                        <?php else: ?>
                                            <span class="badge-custom badge-warning"><i class="fa-solid fa-clock"></i> Pending Verification</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fa-solid fa-folder-open"></i>
                            <h3>No Renewal Requests Yet</h3>
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
                    Hello <?php echo htmlspecialchars($userName); ?>! Ask me about your renewals, permits, rentals, or payments.
                </div>
            </div>
            <div style="padding:10px; background:#ffffff; border-top:1px solid var(--card-border); display:flex; gap:8px;">
                <input type="text" id="aiChatInput" placeholder="Ask e.g., 'Any pending renewals?'..." style="flex:1; padding:8px 12px; border:1px solid var(--card-border); border-radius:999px; outline:none; font-size:12.5px; background:#f8fafc;">
                <button id="aiSendBtn" style="background:var(--accent-blue); border:none; color:#fff; width:36px; height:36px; border-radius:50%; cursor:pointer; display:flex; align-items:center; justify-content:center;"><i class="fa-solid fa-paper-plane" style="font-size:12px;"></i></button>
            </div>
        </div>
    </div>

    <!-- PAYMENT REMINDER MODAL -->
    <div class="modal fade" id="paymentReminderModal" tabindex="-1" aria-hidden="true" style="z-index: 1065;">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 20px; overflow: hidden; background: #ffffff;">
                <div class="modal-header border-0 text-white p-4" style="background: linear-gradient(135deg, #dc2626, #991b1b);">
                    <div class="d-flex align-items-center gap-3">
                        <div style="width: 48px; height: 48px; background: rgba(255, 255, 255, 0.2); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 22px;">
                            <i class="fa-solid fa-credit-card"></i>
                        </div>
                        <div>
                            <h5 class="modal-title fw-bold mb-0" style="font-size: 17px;">Payment Notice</h5>
                            <small style="opacity: 0.85; font-size: 12px;">LGU Online Document Renewal</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4 text-center">
                    <div class="my-2">
                        <span class="badge bg-warning bg-opacity-10 text-warning px-3 py-2 rounded-pill fw-bold border border-warning border-opacity-25" style="font-size: 13px;">
                            <i class="fa-solid fa-circle-exclamation me-1"></i> Renewal Fee Required
                        </span>
                    </div>
                    <h6 class="fw-bold text-dark mt-3 mb-2" style="font-size: 16px;">Please Pay the Required Amount</h6>
                    <p class="text-secondary mb-0" style="font-size: 13.5px; line-height: 1.6;">
                        <strong>"Please pay the amount said in order to renew your documents."</strong>
                    </p>
                    <p class="text-muted mt-2" style="font-size: 12px;">
                        Once you proceed, your renewal request will be forwarded to the Treasurer / LGU Staff for verification and processing.
                    </p>
                </div>
                <div class="modal-footer border-top-0 p-3 bg-light d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-light text-secondary border fw-semibold px-3 py-2" data-bs-dismiss="modal" style="border-radius: 10px; font-size: 13px;">Cancel</button>
                    <button type="button" id="btnConfirmProceed" class="btn btn-primary fw-bold px-4 py-2" style="border-radius: 10px; font-size: 13px; background: var(--accent-blue); border: none;">
                        <i class="fa-solid fa-check-circle me-1"></i> I Understand, Submit Renewal
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- FILE VIEW MODAL -->
    <div class="modal fade" id="fileViewModal" tabindex="-1" aria-hidden="true" style="z-index: 1060;">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content" style="background: #ffffff; border: 1.5px solid var(--accent-blue); color: #0f172a; border-radius: 20px; box-shadow: 0 25px 60px rgba(220, 38, 38, 0.25); overflow: hidden;">
                <div class="modal-header border-bottom border-light-subtle px-3 px-md-4 py-3 d-flex justify-content-between align-items-center" style="background: #f8fafc;">
                    <h5 class="modal-title fs-6 fw-bold text-primary d-flex align-items-center gap-2" id="fileViewModalTitle" style="color: var(--accent-blue) !important;">
                        <i class="fa-solid fa-file-pdf"></i> Attachment Preview
                    </h5>
                    <div class="d-flex align-items-center gap-1 gap-md-2">
                        <div id="imageZoomControls" class="d-none me-1 me-md-2">
                            <button type="button" class="btn btn-sm btn-light border-secondary-subtle" id="zoomInBtn"><i class="fa-solid fa-magnifying-glass-plus text-primary" style="color: var(--accent-blue);"></i></button>
                            <button type="button" class="btn btn-sm btn-light border-secondary-subtle" id="zoomOutBtn"><i class="fa-solid fa-magnifying-glass-minus text-primary" style="color: var(--accent-blue);"></i></button>
                            <button type="button" class="btn btn-sm btn-light border-secondary-subtle" id="zoomResetBtn"><i class="fa-solid fa-rotate-right text-primary" style="color: var(--accent-blue);"></i></button>
                        </div>
                        <a id="downloadFileBtn" href="#" target="_blank" class="btn btn-sm btn-outline-primary" title="Open in New Tab" style="color: var(--accent-blue); border-color: var(--card-border);">
                            <i class="fa-solid fa-arrow-up-right-from-square"></i> <span class="d-none d-sm-inline">New Tab</span>
                        </a>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                </div>
                <div class="modal-body p-2 p-md-3 text-center" style="min-height: 450px; display: flex; align-items: center; justify-content: center; background: #0f172a;">
                    <div id="filePreviewContainer" style="width: 100%; height: 100%;"></div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
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
        }).then((res) => {
            if (res.isConfirmed) {
                const logoutForm = document.getElementById('logoutForm');
                if (logoutForm) logoutForm.submit();
                else window.location.href = '../logout.php';
            }
        });
    }
    document.getElementById('profileLogoutBtn')?.addEventListener('click', confirmLogout);

    /* FORM LOGIC */
    function updateExpiryField(selectElement) {
        const selectedOption = selectElement.options[selectElement.selectedIndex];
        const expiryDate = selectedOption.getAttribute('data-expiry');
        const expiryInput = document.getElementById('current_expiry_date');
        if (expiryDate && expiryDate !== 'NULL') expiryInput.value = expiryDate;
    }

    document.addEventListener("DOMContentLoaded", function() {
        const renewalForm = document.getElementById('renewalForm');
        const btnOpenPaymentModal = document.getElementById('btnOpenPaymentModal');
        const realSubmitBtn = document.getElementById('realSubmitBtn');
        const paymentModalElement = new bootstrap.Modal(document.getElementById('paymentReminderModal'));
        const btnConfirmProceed = document.getElementById('btnConfirmProceed');

        if (btnOpenPaymentModal) {
            btnOpenPaymentModal.addEventListener('click', function() {
                if (renewalForm.checkValidity()) paymentModalElement.show();
                else renewalForm.reportValidity();
            });
        }
        if (btnConfirmProceed) {
            btnConfirmProceed.addEventListener('click', function() {
                paymentModalElement.hide();
                realSubmitBtn.click();
            });
        }

        const fileViewModalElement = new bootstrap.Modal(document.getElementById('fileViewModal'));
        const filePreviewContainer = document.getElementById('filePreviewContainer');
        const fileViewModalTitle = document.getElementById('fileViewModalTitle');
        const downloadFileBtn = document.getElementById('downloadFileBtn');
        const imageZoomControls = document.getElementById('imageZoomControls');
        let currentScale = 1;

        document.querySelectorAll('.open-view-file-modal').forEach(btn => {
            btn.addEventListener('click', function() {
                const filePath = this.getAttribute('data-file');
                const titleText = this.getAttribute('data-title') || 'Attachment Preview';
                const fileExt = filePath.split('.').pop().toLowerCase();

                fileViewModalTitle.innerHTML = `<i class="fa-solid fa-file-lines me-2"></i> ${titleText}`;
                downloadFileBtn.setAttribute('href', filePath);
                currentScale = 1;

                if (['jpg', 'jpeg', 'png', 'webp', 'gif'].includes(fileExt)) {
                    imageZoomControls.classList.remove('d-none');
                    filePreviewContainer.innerHTML = `<div class="zoom-image-container"><img src="${filePath}" id="previewImg" class="preview-image-zoomable" alt="Supporting Attachment"></div>`;
                    const previewImg = document.getElementById('previewImg');
                    if (previewImg) {
                        previewImg.addEventListener('click', function() {
                            this.classList.toggle('is-zoomed');
                            currentScale = this.classList.contains('is-zoomed') ? 2 : 1;
                        });
                    }
                } else if (fileExt === 'pdf') {
                    imageZoomControls.classList.add('d-none');
                    filePreviewContainer.innerHTML = `<iframe src="${filePath}" style="width:100%; height:70vh; border:none; border-radius:12px; background:#fff;"></iframe>`;
                } else {
                    imageZoomControls.classList.add('d-none');
                    filePreviewContainer.innerHTML = `<div class="p-5 text-center text-white"><i class="fa-solid fa-file-lines display-3 text-secondary mb-3"></i><h5>Preview not supported</h5><a href="${filePath}" target="_blank" class="btn btn-primary mt-2" style="background: var(--accent-blue); border: none;"><i class="fa-solid fa-download me-1"></i> Download File</a></div>`;
                }
                fileViewModalElement.show();
            });
        });

        document.getElementById('zoomInBtn')?.addEventListener('click', () => {
            const img = document.getElementById('previewImg');
            if (img) { currentScale += 0.3; img.style.transform = `scale(${currentScale})`; }
        });
        document.getElementById('zoomOutBtn')?.addEventListener('click', () => {
            const img = document.getElementById('previewImg');
            if (img && currentScale > 0.5) { currentScale -= 0.3; img.style.transform = `scale(${currentScale})`; }
        });
        document.getElementById('zoomResetBtn')?.addEventListener('click', () => {
            const img = document.getElementById('previewImg');
            if (img) { currentScale = 1; img.classList.remove('is-zoomed'); img.style.transform = `scale(1)`; }
        });
    });
    </script>
</body>
</html>