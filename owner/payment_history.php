<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();
date_default_timezone_set('Asia/Manila');

// Security: Regenerate session ID periodically
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

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function formatCurrency($amount) {
    return '₱' . number_format((float)$amount, 2);
}

// Helper functions for notifications compatibility & AJAX Search (PDO Safe)
function tableExists($conn, $tableName) {
    try {
        $stmt = $conn->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :t)");
        $stmt->execute([':t' => strtolower($tableName)]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) { 
        try {
            $stmt = $conn->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$tableName]);
            return (bool)$stmt->fetchColumn();
        } catch (PDOException $ex) {
            return false; 
        }
    }
}

function columnExists($conn, $tableName, $columnName) {
    try {
        $stmt = $conn->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :t AND column_name = :c)");
        $stmt->execute([':t' => strtolower($tableName), ':c' => strtolower($columnName)]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        try {
            $stmt = $conn->prepare("SHOW COLUMNS FROM `$tableName` LIKE ?");
            $stmt->execute([$columnName]);
            return (bool)$stmt->fetchColumn();
        } catch (PDOException $ex) {
            return false;
        }
    }
}

function displayField($row, $possibleFields) {
    foreach ($possibleFields as $field) {
        if (isset($row[$field]) && trim((string)$row[$field]) !== '') return htmlspecialchars((string)$row[$field]);
    }
    return '—';
}

/* =========================================================
   MARK NOTIFICATION AS READ AJAX HANDLER
   ========================================================= */
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

/* =========================================================
   CLEAR / DELETE NOTIFICATIONS AJAX HANDLER
   ========================================================= */
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

/* =========================================================
   AJAX SEARCH HANDLER FOR THE TOP SEARCH BAR MODAL (PDO)
   ========================================================= */
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'search') {
    $query = trim($_GET['q'] ?? '');
    if (strlen($query) >= 2) {
        $searchTerm = '%' . $query . '%';
        $sql = "
            SELECT id, tracking_no, business_name, status, rental_name 
            FROM rentals 
            WHERE user_id = :uid 
              AND (is_deleted = '0' OR is_deleted = FALSE) 
              AND (tracking_no ILIKE :q1 OR business_name ILIKE :q2 OR rental_name ILIKE :q3)
            LIMIT 5
        ";
        try {
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':uid' => $userId,
                ':q1' => $searchTerm,
                ':q2' => $searchTerm,
                ':q3' => $searchTerm
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $sql = "
                SELECT id, tracking_no, business_name, status, rental_name 
                FROM rentals 
                WHERE user_id = :uid 
                  AND is_deleted = '0' 
                  AND (tracking_no LIKE :q1 OR business_name LIKE :q2 OR rental_name LIKE :q3)
                LIMIT 5
            ";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':uid' => $userId,
                ':q1' => $searchTerm,
                ':q2' => $searchTerm,
                ':q3' => $searchTerm
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if (!empty($rows)) {
            echo '<div style="display: flex; flex-direction: column; gap: 8px;">';
            foreach ($rows as $row) {
                $status = $row['status'] ?? 'Draft';
                $badgeBg = 'rgba(2,132,199,0.1)';
                $badgeColor = '#0284c7';
                if (strtolower($status) === 'pending') { $badgeBg = 'rgba(217,119,6,0.15)'; $badgeColor = '#d97706'; }
                elseif (strtolower($status) === 'approved' || strtolower($status) === 'active') { $badgeBg = 'rgba(5,150,105,0.15)'; $badgeColor = '#059669'; }

                echo '<a href="view_rental.php?id=' . $row['id'] . '" style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: #f8fafc; border: 1px solid #bae6fd; border-radius: 12px; color: #0f172a; text-decoration: none; transition: all 0.2s ease;" onmouseover="this.style.background=\'#e0f2fe\';" onmouseout="this.style.background=\'#f8fafc\';">';
                echo '<div style="display: flex; flex-direction: column; gap: 4px;">';
                echo '<div style="font-weight: 700; font-size: 14px; color: #0284c7;">' . htmlspecialchars($row['tracking_no'] ?? '#'.$row['id']) . ' <span style="color: #94a3b8; font-weight: 400;">•</span> ' . htmlspecialchars($row['business_name'] ?: $row['rental_name']) . '</div>';
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

// Get all payments for this user (PDO Safe)
$payments = [];
try {
    $stmt = $conn->prepare("
        SELECT p.*, r.business_name, r.rental_name, r.tracking_no, r.barangay 
        FROM payments p 
        LEFT JOIN rentals r ON p.rental_id = r.id 
        WHERE r.user_id = :uid 
        ORDER BY p.payment_date DESC, p.id DESC
    ");
    $stmt->execute([':uid' => $userId]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $payments = [];
}

// Get summary
$totalPayments = count($payments);
$totalAmount = 0;
$pendingAmount = 0;
$paidAmount = 0;

foreach ($payments as $p) {
    $amount = (float)($p['amount_paid'] ?? 0);
    $totalAmount += $amount;
    $status = strtolower(trim($p['status'] ?? ''));
    if ($status === 'paid' || $status === 'verified' || $status === 'completed') {
        $paidAmount += $amount;
    } elseif ($status === 'pending') {
        $pendingAmount += $amount;
    }
}

// Notifications (PDO Safe)
$unreadNotifCount = 0;
$notifications = [];
if (tableExists($conn, 'notifications')) {
    if (columnExists($conn, 'notifications', 'is_read')) {
        try {
            $stmtNotifCount = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE (is_read = FALSE OR is_read = 0) AND user_id = :uid");
            $stmtNotifCount->execute([':uid' => $userId]);
            $unreadNotifCount = (int) $stmtNotifCount->fetchColumn();
        } catch (PDOException $e) {
            $unreadNotifCount = 0;
        }
    }
    try {
        $notifStmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = :uid ORDER BY created_at DESC LIMIT 5");
        $notifStmt->execute([':uid' => $userId]);
        $notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $notifications = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment History | RENTRACK LGU</title>
    
    <!-- BOOTSTRAP, FONT AWESOME & FONTS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        :root {
            --bg-light: #f0f9ff;
            --bg-card: #ffffff;
            --card-border: #bae6fd;
            --accent-blue: #0284c7;
            --topbar-bg: #0369a1;
            --sidebar-width: 260px;
            --sidebar-collapsed: 72px;
            --topbar-height: 42px;
            --navbar-height: 64px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            background-color: var(--bg-light);
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: #0f172a;
            min-height: 100vh;
            overflow-x: hidden;
        }

        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: #e0f2fe; }
        ::-webkit-scrollbar-thumb { background: #38bdf8; border-radius: 20px; }

        .top-official-bar {
            position: fixed; top: 0; left: 0; width: 100vw;
            background: var(--topbar-bg);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            padding: 0 20px; display: flex; justify-content: space-between; align-items: center;
            font-size: 11px; font-weight: 600; z-index: 1050; height: var(--topbar-height);
            color: #fff; box-sizing: border-box;
        }

        .topbar-left { display: flex; align-items: center; gap: 8px; color: #fff; }
        .topbar-left i { font-size: 12px; color: #7dd3fc; }
        .topbar-left strong { letter-spacing: 0.3px; font-weight: 800; color: #fff; }
        .topbar-left .divider { width: 1px; height: 12px; background: rgba(255, 255, 255, 0.3); }
        .topbar-left .portal-title { color: #e0f2fe; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .topbar-right { display: flex; align-items: center; gap: 12px; }

        .cert-badge {
            display: inline-flex; align-items: center; gap: 6px; padding: 3px 12px;
            background: rgba(255, 255, 255, 0.15); border: 1px solid rgba(255, 255, 255, 0.4);
            border-radius: 999px; color: #fff; font-weight: 700; font-size: 10px;
            text-transform: uppercase; letter-spacing: 0.5px;
        }

        .topbar-subtext { color: #bae6fd; font-size: 11px; font-weight: 500; }

        .sidebar {
            position: fixed; top: var(--topbar-height); left: 0;
            width: var(--sidebar-width); height: calc(100vh - var(--topbar-height));
            background: #0c4a6e;
            border-right: 1px solid #0284c7;
            padding: 16px 16px 20px;
            display: flex; flex-direction: column; z-index: 1002;
            transition: width 0.3s ease;
            overflow: hidden;
        }

        body.sidebar-collapsed .sidebar { width: var(--sidebar-collapsed); }

        .sidebar-menu-wrapper {
            flex: 1;
            overflow-y: auto;
            padding-right: 4px;
            margin-bottom: 10px;
        }
        .sidebar-menu-wrapper::-webkit-scrollbar { width: 4px; }
        .sidebar-menu-wrapper::-webkit-scrollbar-track { background: transparent; }
        .sidebar-menu-wrapper::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.2); border-radius: 10px; }

        .brand-lockup {
            display: flex; align-items: center; gap: 12px;
            padding-bottom: 16px; margin-bottom: 16px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.15);
            text-decoration: none; color: #fff;
            white-space: nowrap; overflow: hidden;
        }
        body.sidebar-collapsed .brand-lockup { justify-content: center; padding-top: 4px; }

        .logo-ring {
            position: relative; 
            width: 44px !important; 
            height: 44px !important; 
            min-width: 44px !important;
            min-height: 44px !important;
            max-width: 44px !important;
            max-height: 44px !important;
            border-radius: 50% !important;
            border: 2px dashed #38bdf8 !important;
            display: flex !important; 
            align-items: center !important; 
            justify-content: center !important; 
            flex-shrink: 0 !important;
            background: rgba(255, 255, 255, 0.1);
            animation: spinRing 6s linear infinite;
        }
        @keyframes spinRing { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        .logo-ring .rl-text { 
            font-weight: 800; 
            font-size: 15px; 
            color: #38bdf8; 
            font-family: 'Space Grotesk', sans-serif; 
            line-height: 1; 
            animation: counterSpin 6s linear infinite;
        }
        @keyframes counterSpin { 0% { transform: rotate(0deg); } 100% { transform: rotate(-360deg); } }

        .brand-text-wrapper { transition: opacity 0.2s ease; white-space: nowrap; }
        body.sidebar-collapsed .brand-text-wrapper { opacity: 0; width: 0; overflow: hidden; display: none; }

        .brand-text { font-family: 'Space Grotesk', sans-serif; font-size: 18px; font-weight: 800; letter-spacing: -0.3px; color: #fff; }
        .brand-tag { font-size: 11px; color: #38bdf8; font-weight: 700; margin-left: 4px; }

        .nav-label { font-size: 10px; font-weight: 700; text-transform: uppercase; color: #7dd3fc; margin: 14px 0 6px 8px; letter-spacing: 1px; transition: opacity 0.2s; }
        body.sidebar-collapsed .nav-label { opacity: 0; height: 0; padding: 0; margin: 0; overflow: hidden; }

        .nav-item {
            display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 10px;
            color: #e0f2fe; text-decoration: none; font-size: 13px; font-weight: 600; transition: var(--transition); margin-bottom: 2px;
            white-space: nowrap; overflow: hidden; position: relative;
        }
        .nav-item i { width: 18px; text-align: center; font-size: 14px; flex-shrink: 0; }
        .nav-item:hover { background: rgba(255, 255, 255, 0.12); color: #fff; }
        .nav-item.active { background: #0284c7; color: #fff; box-shadow: 0 4px 12px rgba(2, 132, 199, 0.3); }

        body.sidebar-collapsed .nav-item span { opacity: 0; width: 0; overflow: hidden; display: none; }
        body.sidebar-collapsed .nav-item { justify-content: center; padding: 10px; }

        body.sidebar-collapsed .sidebar .nav-item:hover::after {
            content: attr(data-title);
            position: absolute;
            left: calc(100% + 14px);
            top: 50%;
            transform: translateY(-50%);
            background: #0369a1;
            color: #bae6fd;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
            border: 1px solid #38bdf8;
            box-shadow: 0 10px 25px rgba(0,0,0,0.4);
            z-index: 1050;
            pointer-events: none;
        }

        .sidebar-footer { margin-top: auto; padding-top: 12px; border-top: 1px solid rgba(255, 255, 255, 0.15); }
        body.sidebar-collapsed .sidebar-footer { text-align: center; }

        .navbar-custom {
            position: fixed; top: var(--topbar-height); left: var(--sidebar-width);
            width: calc(100% - var(--sidebar-width)); height: var(--navbar-height);
            background: #ffffff;
            border-bottom: 1px solid var(--card-border);
            padding: 0 24px; display: flex; align-items: center; justify-content: space-between;
            z-index: 1000; transition: var(--transition);
        }
        body.sidebar-collapsed .navbar-custom { left: var(--sidebar-collapsed); width: calc(100% - var(--sidebar-collapsed)); }

        .navbar-left { display: flex; align-items: center; gap: 12px; }

        .sidebar-toggle {
            background: #f0f9ff; border: 1px solid #bae6fd;
            color: var(--accent-blue); width: 38px; height: 38px; border-radius: 10px; cursor: pointer;
            display: flex; align-items: center; justify-content: center; font-size: 16px;
            transition: var(--transition);
        }
        .sidebar-toggle:hover { background: #0284c7; color: #fff; border-color: #0284c7; }

        .page-title-box { display: flex; align-items: center; gap: 8px; }
        .page-title { font-family: 'Space Grotesk', sans-serif; font-size: 18px; font-weight: 800; color: #0f172a; margin: 0; }
        .page-sub { font-size: 12.5px; color: #64748b; font-weight: 500; }

        .search-wrapper { flex: 1; max-width: 420px; margin: 0 20px; }
        .search-container { position: relative; width: 100%; cursor: pointer; }
        .search-container i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #64748b; font-size: 14px; }
        .search-container input {
            width: 100%; padding: 7px 40px 7px 38px; border-radius: 999px;
            border: 1px solid #bae6fd; background: #f8fafc;
            color: #0f172a; font-size: 13px; outline: none; cursor: pointer; transition: var(--transition);
        }
        .search-container input::placeholder { color: #94a3b8; }
        .search-shortcut-badge {
            position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
            font-size: 9.5px; background: #e0f2fe; padding: 2px 6px; border-radius: 6px; color: #0369a1; font-weight: 700; border: 1px solid #bae6fd;
        }

        .search-modal-overlay {
            position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(3, 105, 161, 0.4); backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px); z-index: 9999;
            display: flex; align-items: flex-start; justify-content: center; padding-top: 10vh;
            opacity: 0; visibility: hidden; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .search-modal-overlay.active { opacity: 1; visibility: visible; }

        .search-modal-content {
            width: 90%; max-width: 650px; background: #ffffff;
            border: 1px solid #bae6fd; border-radius: 20px;
            box-shadow: 0 25px 50px rgba(2, 132, 199, 0.2), 0 0 30px rgba(2, 132, 199, 0.1);
            overflow: hidden; transform: translateY(-20px) scale(0.98); transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .search-modal-overlay.active .search-modal-content { transform: translateY(0) scale(1); }

        .search-modal-header {
            display: flex; align-items: center; padding: 16px 20px;
            border-bottom: 1px solid #e2e8f0; background: #f8fafc; position: relative;
        }
        .search-modal-header i.search-icon-main { font-size: 16px; color: #0284c7; margin-right: 14px; }
        .search-modal-input {
            flex: 1; background: transparent; border: none; outline: none; color: #0f172a; font-size: 15px; font-weight: 600;
        }
        .search-modal-input::placeholder { color: #94a3b8; }
        .search-modal-close {
            background: #f1f5f9; border: 1px solid #cbd5e1; color: #64748b;
            width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: all 0.2s ease;
        }
        .search-modal-close:hover { background: #fee2e2; color: #ef4444; border-color: #fca5a5; }

        .search-modal-body { padding: 20px; max-height: 380px; overflow-y: auto; }
        .search-modal-body::-webkit-scrollbar { width: 6px; }
        .search-modal-body::-webkit-scrollbar-thumb { background: #38bdf8; border-radius: 999px; }

        .search-shortcut-hint {
            display: flex; align-items: center; justify-content: space-between; padding: 12px 20px;
            background: #f8fafc; border-top: 1px solid #e2e8f0; font-size: 11px; color: #64748b;
        }
        .search-shortcut-hint kbd {
            background: #e0f2fe; border: 1px solid #bae6fd; padding: 2px 6px; border-radius: 4px; color: #0369a1; font-family: inherit; font-weight: 700;
        }

        .nav-actions { display: flex; align-items: center; gap: 12px; }

        .clock-pill {
            background: #f0f9ff; border: 1px solid #bae6fd;
            padding: 4px 14px; border-radius: 10px; text-align: center;
        }
        .clock-time { font-family: 'Space Grotesk', sans-serif; font-size: 13px; font-weight: 700; color: #0284c7; line-height: 1.1; }
        .clock-date { font-size: 10px; color: #64748b; font-weight: 600; text-transform: uppercase; }

        .icon-btn {
            background: #f0f9ff; border: 1px solid #bae6fd;
            color: #0369a1; width: 36px; height: 36px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center; cursor: pointer; transition: var(--transition);
        }
        .icon-btn:hover { background: #e0f2fe; border-color: #0284c7; }

        .user-profile {
            display: flex; align-items: center; gap: 10px; padding: 3px 12px 3px 3px;
            background: #f0f9ff; border: 1px solid #bae6fd;
            border-radius: 999px; cursor: pointer; transition: var(--transition);
        }
        .user-profile:hover { border-color: #0284c7; }
        .avatar {
            width: 30px; height: 30px; border-radius: 50%;
            background: #0284c7; color: #fff;
            display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 12px;
        }
        .user-info { display: flex; flex-direction: column; text-align: left; }
        .user-name { font-size: 12px; font-weight: 700; color: #0f172a; line-height: 1.1; }
        .user-role { font-size: 9px; font-weight: 700; color: #0284c7; text-transform: uppercase; }

        .profile-dropdown, .notification-dropdown {
            position: absolute; top: calc(100% + 10px); right: 0;
            background: #ffffff; border: 1px solid #bae6fd;
            border-radius: 14px; box-shadow: 0 16px 36px rgba(2, 132, 199, 0.15);
            opacity: 0; visibility: hidden; transform: translateY(-8px); transition: var(--transition); z-index: 100;
        }
        .profile-dropdown.show, .notification-dropdown.show { opacity: 1; visibility: visible; transform: translateY(0); }
        .profile-dropdown { width: 190px; padding: 6px; }
        .notification-dropdown { width: 310px; overflow: hidden; }

        .dropdown-item {
            display: flex; align-items: center; gap: 10px; padding: 8px 12px;
            border-radius: 8px; color: #334155; font-size: 13px; font-weight: 500; text-decoration: none; transition: var(--transition);
        }
        .dropdown-item:hover { background: #f0f9ff; color: #0284c7; }
        .dropdown-item.danger { color: #ef4444; }
        .dropdown-item.danger:hover { background: #fef2f2; }

        .wrapper { display: flex; min-height: 100vh; padding-top: calc(var(--topbar-height) + var(--navbar-height)); }
        .main { flex: 1; padding: 22px 24px 40px; margin-left: var(--sidebar-width); transition: var(--transition); }
        body.sidebar-collapsed .main { margin-left: var(--sidebar-collapsed); }

        .hero-card {
            display: flex; justify-content: space-between; align-items: center; gap: 1rem;
            padding: 22px 26px; border-radius: 18px; color: #ffffff;
            background: linear-gradient(135deg, #0284c7, #2563eb);
            box-shadow: 0 8px 22px rgba(2, 132, 199, 0.2); margin-bottom: 18px;
        }
        .hero-card h2 { font-family: 'Space Grotesk', sans-serif; font-size: 22px; font-weight: 800; color: #ffffff; margin: 0; }
        .hero-card p { color: #e0f2fe; font-size: 13.5px; margin-top: 4px; margin-bottom: 0; }

        .stats-grid {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 18px;
        }
        .stat-card {
            background: #ffffff; border: 1px solid #bae6fd; border-radius: 14px;
            padding: 16px 20px; display: flex; justify-content: space-between; align-items: center;
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.03);
        }
        .stat-label { font-size: 11px; font-weight: 800; text-transform: uppercase; color: #64748b; }
        .stat-number { font-family: 'Space Grotesk', sans-serif; font-size: 22px; font-weight: 800; color: #0f172a; }
        .stat-icon {
            width: 38px; height: 38px; border-radius: 10px; background: #f0f9ff; color: #0284c7;
            display: flex; align-items: center; justify-content: center; font-size: 16px;
        }
        .stat-icon.green { background: #d1fae5; color: #059669; }
        .stat-icon.amber { background: #fef3c7; color: #d97706; }

        .panel {
            background: #ffffff; border: 1px solid #bae6fd; border-radius: 16px;
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.04); overflow: hidden; margin-bottom: 20px;
        }
        .panel-header {
            padding: 14px 20px; background: #ffffff; border-bottom: 1px solid #bae6fd;
            font-family: 'Space Grotesk', sans-serif; font-size: 15px; font-weight: 800; color: #0f172a;
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
        }
        .panel-header i { color: #0284c7; }

        .table-custom { width: 100%; border-collapse: collapse; font-size: 13px; }
        .table-custom th { background: #f0f9ff; color: #0369a1; padding: 12px 16px; font-weight: 800; text-transform: uppercase; font-size: 11px; border-bottom: 1px solid #bae6fd; text-align: left; }
        .table-custom td { padding: 12px 16px; border-bottom: 1px solid #f1f5f9; color: #334155; vertical-align: middle; }
        .table-custom tr:hover { background: #f8fafc; }

        .status-badge { padding: 4px 10px; border-radius: 999px; font-size: 10.5px; font-weight: 800; text-transform: uppercase; display: inline-block; }
        .status-badge.active { background: #d1fae5; color: #059669; }
        .status-badge.pending { background: #fef3c7; color: #d97706; }
        .status-badge.rejected { background: #ffe4e6; color: #e11d48; }

        .btn-primary-custom {
            background: #0284c7; color: #fff; border: none; padding: 8px 16px; border-radius: 10px;
            font-weight: 700; font-size: 13px; cursor: pointer; text-decoration: none; transition: var(--transition); display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-primary-custom:hover { background: #0369a1; color: #fff; }

        .btn-secondary-custom {
            background: #f0f9ff; border: 1px solid #bae6fd; color: #0284c7; padding: 5px 12px;
            border-radius: 8px; font-size: 12px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;
        }
        .btn-secondary-custom:hover { background: #0284c7; color: #fff; border-color: #0284c7; }

        .footer { margin-top: 20px; padding-top: 14px; border-top: 1px solid #bae6fd; display: flex; justify-content: space-between; font-size: 11.5px; color: #64748b; }

        @media (max-width: 992px) {
            .sidebar { transform: translateX(-100%); width: var(--sidebar-width) !important; top: var(--topbar-height) !important; height: calc(100vh - var(--topbar-height)) !important; }
            body.sidebar-open .sidebar { transform: translateX(0); }
            .navbar-custom { left: 0 !important; width: 100% !important; }
            .main { margin-left: 0 !important; padding: 16px; }
            .stats-grid { grid-template-columns: repeat(1, 1fr); }
            .clock-pill { display: none; }
        }
    </style>
</head>
<body>

    <!-- 1. TOP OFFICIAL BAR -->
    <div class="top-official-bar">
        <div class="topbar-left">
            <i class="fa-solid fa-landmark"></i>
            <strong>REPUBLIC OF THE PHILIPPINES</strong>
            <span class="divider"></span>
            <span class="portal-title">Local Government Unit Property &amp; Rental Compliance Portal</span>
        </div>
        <div class="topbar-right">
            <span class="cert-badge"><i class="fa-solid fa-certificate"></i> LGU CERTIFIED PORTAL V2.1</span>
            <span class="topbar-subtext d-none d-md-inline">City Treasurer &amp; Building Official Online System</span>
        </div>
    </div>

    <!-- 2. SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <a href="dashboard.php" class="brand-lockup">
            <div class="logo-ring">
                <span class="rl-text">RL</span>
            </div>
            <div class="brand-text-wrapper">
                <span class="brand-text">RENTRACK <span class="brand-tag">LGU</span></span>
            </div>
        </a>

        <div class="sidebar-menu-wrapper">
            <div class="nav-label">Main</div>
            <a href="dashboard.php" class="nav-item" data-title="Dashboard"><i class="fa-solid fa-gauge-high"></i> <span>Dashboard</span></a>

            <div class="nav-label">Rentals</div>
            <a href="rentals.php" class="nav-item" data-title="My Rentals"><i class="fa-solid fa-building"></i> <span>My Rentals</span></a>
            <a href="rental_requests.php" class="nav-item" data-title="Rental Requests"><i class="fa-solid fa-file-circle-check"></i> <span>Rental Requests</span></a>

            <div class="nav-label">Operations</div>
            <a href="schedule.php" class="nav-item" data-title="Rental Schedule"><i class="fa-solid fa-calendar-days"></i> <span>Rental Schedule</span></a>
            <a href="inspections.php" class="nav-item" data-title="Inspections"><i class="fa-solid fa-clipboard-check"></i> <span>Inspections</span></a>
            <a href="inspection_schedule.php" class="nav-item" data-title="Inspection Schedule"><i class="fa-solid fa-calendar-check"></i> <span>Inspection Schedule</span></a>

            <div class="nav-label">Documents &amp; Compliance</div>
            <a href="documents.php" class="nav-item" data-title="Documents"><i class="fa-solid fa-file-lines"></i> <span>Documents</span></a>
            <a href="renew_documents.php" class="nav-item" data-title="Online Renewal"><i class="fa-solid fa-file-arrow-up"></i> <span>Online Renewal</span></a>
            <a href="compliance.php" class="nav-item" data-title="Compliance"><i class="fa-solid fa-file-shield"></i> <span>Compliance</span></a>

            <div class="nav-label">Financial</div>
            <a href="payments.php" class="nav-item active" data-title="Payments"><i class="fa-solid fa-cash-register"></i> <span>Payments</span></a>

            <div class="nav-label">Reports &amp; Settings</div>
            <a href="reports.php" class="nav-item" data-title="Reports"><i class="fa-solid fa-chart-simple"></i> <span>Reports</span></a>
            <a href="settings.php" class="nav-item" data-title="Settings"><i class="fa-solid fa-gear"></i> <span>Settings</span></a>
        </div>

        <div class="sidebar-footer">
            <a href="javascript:void(0)" class="nav-item text-danger" id="sidebarLogoutBtn" data-title="Logout"><i class="fa-solid fa-right-from-bracket"></i> <span>Logout</span></a>
        </div>
    </aside>

    <!-- 3. NAVBAR -->
    <header class="navbar-custom">
        <div class="navbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Navigation"><i class="fa-solid fa-bars"></i></button>
            <div class="page-title-box">
                <h1 class="page-title">Payment History</h1>
                <span class="page-sub d-none d-sm-inline">&middot; Complete Transaction Ledger</span>
            </div>
        </div>

        <div class="search-wrapper d-none d-md-block">
            <div class="search-container" onclick="openSearchModal()">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" placeholder="Search tracking no, business name, barangay..." readonly>
                <span class="search-shortcut-badge">Ctrl K</span>
            </div>
        </div>

        <div class="nav-actions">
            <div class="clock-pill">
                <div class="clock-time" id="liveTime"><?php echo date('h:i:s A'); ?></div>
                <div class="clock-date"><?php echo date('M d, Y'); ?></div>
            </div>

            <div style="position:relative;">
                <button class="icon-btn" id="notifBtn" title="Notifications">
                    <i class="fa-regular fa-bell"></i>
                    <?php if ($unreadNotifCount > 0): ?>
                        <span id="notifBadgeCount" style="position:absolute; top:2px; right:2px; background:#ef4444; color:#fff; font-size:9px; font-weight:700; width:16px; height:16px; border-radius:50%; display:flex; align-items:center; justify-content:center;"><?php echo $unreadNotifCount; ?></span>
                    <?php endif; ?>
                </button>
                <div id="notifDropdown" class="notification-dropdown">
                    <div style="padding:12px 14px; font-weight:700; border-bottom:1px solid #bae6fd; font-size:13px; color:#0f172a; display:flex; justify-content:space-between; align-items:center; gap:8px;">
                        <span>Notifications</span>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <?php if ($unreadNotifCount > 0): ?>
                                <span id="notifHeaderCount" style="font-size:10px; background:#e0f2fe; color:#0369a1; padding:2px 8px; border-radius:10px;"><?php echo $unreadNotifCount; ?> New</span>
                            <?php endif; ?>
                            <button type="button" id="notifClearBtn" style="background:transparent; border:none; color:#0284c7; font-size:11px; font-weight:700; cursor:pointer; padding:2px 4px;">Clear</button>
                        </div>
                    </div>
                    <div style="max-height:280px; overflow-y:auto;" id="notifListBody">
                        <?php if (!empty($notifications)): ?>
                            <?php foreach ($notifications as $notification): ?>
                                <?php 
                                    $notifLink = 'payments.php';
                                    if (!empty($notification['link'])) {
                                        $notifLink = $notification['link'];
                                    } else {
                                        $titleLower = strtolower($notification['title'] ?? '');
                                        $msgLower = strtolower($notification['message'] ?? '');
                                        
                                        if (strpos($titleLower, 'rental') !== false || strpos($msgLower, 'rental') !== false) {
                                            $notifLink = 'rentals.php';
                                        } elseif (strpos($titleLower, 'inspection') !== false || strpos($msgLower, 'inspection') !== false) {
                                            $notifLink = 'inspections.php';
                                        } elseif (strpos($titleLower, 'payment') !== false || strpos($msgLower, 'payment') !== false) {
                                            $notifLink = 'payments.php';
                                        } elseif (strpos($titleLower, 'compliance') !== false || strpos($msgLower, 'permit') !== false) {
                                            $notifLink = 'compliance.php';
                                        }
                                    }
                                    $isUnread = (!isset($notification['is_read']) || $notification['is_read'] === false || $notification['is_read'] === '0' || $notification['is_read'] === 0);
                                ?>
                                <a href="javascript:void(0)" class="notif-drop-item" onclick="markNotificationReadAndRedirect(<?php echo (int)$notification['id']; ?>, '<?php echo htmlspecialchars($notifLink); ?>', this)" style="display:block; padding:10px 14px; border-bottom:1px solid #f1f5f9; text-decoration:none; color:#334155; background: <?php echo $isUnread ? '#f0f9ff' : '#fff'; ?>; transition:background 0.2s;" onmouseover="this.style.background='#e0f2fe'" onmouseout="this.style.background='<?php echo $isUnread ? '#f0f9ff' : '#fff'; ?>'">
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <strong style="font-size:12px; display:block; color:#0f172a;"><?php echo displayField($notification, ['title']); ?></strong>
                                        <?php if ($isUnread): ?>
                                            <span class="unread-dot" style="width:8px; height:8px; background:#ef4444; border-radius:50%; display:inline-block;"></span>
                                        <?php endif; ?>
                                    </div>
                                    <span style="font-size:11px; color:#64748b; display:block; margin-top:2px;"><?php echo displayField($notification, ['message','description']); ?></span>
                                    <span style="font-size:9.5px; color:#94a3b8; display:block; margin-top:4px;"><?php echo !empty($notification['created_at']) ? date('M d, Y h:i A', strtotime($notification['created_at'])) : ''; ?></span>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="padding:20px; text-align:center; color:#94a3b8; font-size:12px;">No notifications found</div>
                        <?php endif; ?>
                    </div>
                    <div style="padding:8px; text-align:center; border-top:1px solid #bae6fd; background:#f8fafc;">
                        <a href="notifications.php" style="font-size:11px; font-weight:700; color:#0284c7; text-decoration:none;">View All Notifications</a>
                    </div>
                </div>
            </div>

            <div style="position:relative;">
                <div class="user-profile" id="profileToggle">
                    <div class="avatar"><?php echo h($userInitial); ?></div>
                    <div class="user-info d-none d-sm-flex">
                        <span class="user-name"><?php echo h($userName); ?></span>
                        <span class="user-role">OWNER</span>
                    </div>
                    <i class="fa-solid fa-chevron-down" style="font-size:10px; color:#64748b; margin-left:2px;"></i>
                </div>

                <div class="profile-dropdown" id="profileDropdown">
                    <a href="profile.php" class="dropdown-item"><i class="fa-solid fa-user"></i> Profile</a>
                    <a href="settings.php" class="dropdown-item"><i class="fa-solid fa-gear"></i> Settings</a>
                    <div style="height:1px; background:#bae6fd; margin:4px 0;"></div>
                    <a href="javascript:void(0)" class="dropdown-item danger" id="profileLogoutBtn"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
                </div>
            </div>
        </div>
    </header>

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
                    <i class="fa-solid fa-keyboard" style="font-size: 28px; margin-bottom: 10px; display: block; opacity: 0.5; color: #0284c7;"></i>
                    Type at least 2 characters to search records...
                </div>
            </div>
            <div class="search-shortcut-hint">
                <span>Quick search across all your property records</span>
                <span>Press <kbd>ESC</kbd> to close</span>
            </div>
        </div>
    </div>

    <!-- 4. MAIN CONTENT AREA -->
    <div class="wrapper">
        <div class="main">

            <!-- Hero Card -->
            <div class="hero-card">
                <div>
                    <h2><i class="fa-solid fa-receipt me-1"></i> Payment History</h2>
                    <p>Complete list of all payment transactions and official receipts for your rental properties.</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="payments.php" class="btn-secondary-custom" style="background:#ffffff; color:#0284c7; border:none;">
                        <i class="fa-solid fa-arrow-left me-1"></i> Back to Payments
                    </a>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div>
                        <span class="stat-label">Total Transactions</span>
                        <div class="stat-number"><?php echo number_format($totalPayments); ?></div>
                    </div>
                    <div class="stat-icon"><i class="fa-solid fa-receipt"></i></div>
                </div>

                <div class="stat-card">
                    <div>
                        <span class="stat-label">Total Paid Amount</span>
                        <div class="stat-number" style="color:#059669;"><?php echo formatCurrency($paidAmount); ?></div>
                    </div>
                    <div class="stat-icon green"><i class="fa-solid fa-chart-line"></i></div>
                </div>

                <div class="stat-card">
                    <div>
                        <span class="stat-label">Pending Dues</span>
                        <div class="stat-number" style="color:#d97706;"><?php echo formatCurrency($pendingAmount); ?></div>
                    </div>
                    <div class="stat-icon amber"><i class="fa-solid fa-circle-exclamation"></i></div>
                </div>
            </div>

            <!-- Transactions Panel -->
            <div class="panel">
                <div class="panel-header">
                    <span><i class="fa-solid fa-list"></i> All Transaction Records</span>
                    <span class="badge bg-light text-primary border" style="font-size:11px;"><?php echo count($payments); ?> Records</span>
                </div>
                <div class="table-responsive">
                    <?php if (!empty($payments)): ?>
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>OR Number</th>
                                <th>Rental Property</th>
                                <th>Amount Paid</th>
                                <th>Payment Date</th>
                                <th>Method</th>
                                <th>Location</th>
                                <th class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $p): ?>
                            <tr>
                                <td><strong style="color:#0f172a; font-family:monospace;">#<?php echo h($p['or_number'] ?? ($p['reference_no'] ?? 'N/A')); ?></strong></td>
                                <td>
                                    <strong style="color:#0f172a;"><?php echo h(!empty($p['business_name']) ? $p['business_name'] : $p['rental_name']); ?></strong><br>
                                    <span style="font-size:11px; color:#64748b;"><?php echo h($p['tracking_no'] ?? ''); ?></span>
                                </td>
                                <td><strong style="color:#0284c7;"><?php echo formatCurrency($p['amount_paid'] ?? 0); ?></strong></td>
                                <td><?php echo date('M d, Y', strtotime($p['payment_date'] ?? 'now')); ?></td>
                                <td><?php echo h($p['payment_method'] ?? '—'); ?></td>
                                <td><?php echo h($p['collection_location'] ?? 'Municipal Office'); ?></td>
                                <td class="text-center">
                                    <?php 
                                    $status = strtolower($p['status'] ?? 'pending');
                                    if ($status === 'paid' || $status === 'verified' || $status === 'completed'): ?>
                                        <span class="status-badge active"><i class="fa-solid fa-check me-1"></i> Verified</span>
                                    <?php elseif ($status === 'pending'): ?>
                                        <span class="status-badge pending"><i class="fa-solid fa-clock me-1"></i> Pending</span>
                                    <?php else: ?>
                                        <span class="status-badge rejected"><i class="fa-solid fa-ban me-1"></i> <?php echo h($p['status']); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div style="text-align:center; padding:40px; color:#64748b;">
                        <i class="fa-solid fa-receipt" style="font-size:32px; opacity:0.5; display:block; margin-bottom:8px;"></i>
                        <h3 style="font-size:16px; color:#0f172a; font-weight:700;">No Payment Transactions Found</h3>
                        <p style="font-size:13px; margin-bottom:12px;">Wala pang naitatalang transaksyon sa kasaysayan.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Footer -->
            <footer class="footer">
                <span>&copy; <?php echo date('Y'); ?> <span style="color:#0284c7; font-weight:700;">RENTRACK LGU</span> &middot; Unified Rental System</span>
                <span>Owner Portal</span>
            </footer>

        </div>
    </div>

    <!-- SCRIPTS -->
    <script>
        function updateClock() {
            const timeBox = document.getElementById('liveTime');
            if (!timeBox) return;
            const now = new Date();
            timeBox.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
        setInterval(updateClock, 1000);
        updateClock();

        const sidebarToggle = document.getElementById('sidebarToggle');
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', () => {
                if (window.innerWidth <= 992) {
                    document.body.classList.toggle('sidebar-open');
                } else {
                    document.body.classList.toggle('sidebar-collapsed');
                }
            });
        }

        /* =========================================================
           CLEAR ALL NOTIFICATIONS JAVASCRIPT HANDLER
           ========================================================= */
        const notifClearBtn = document.getElementById('notifClearBtn');
        if (notifClearBtn) {
            notifClearBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                fetch('?ajax_action=clear_notifs')
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            const badge = document.getElementById('notifBadgeCount');
                            const headerCount = document.getElementById('notifHeaderCount');
                            if (badge) badge.remove();
                            if (headerCount) headerCount.remove();

                            const listBody = document.getElementById('notifListBody');
                            if (listBody) {
                                listBody.innerHTML = '<div style="padding:24px; text-align:center; color:#94a3b8; font-size:12px;"><i class="fa-solid fa-check-double" style="font-size:20px; margin-bottom:8px; display:block; color:#0284c7;"></i>You\'re all caught up!</div>';
                            }
                        }
                    })
                    .catch(err => console.error(err));
            });
        }

        /* =========================================================
           MARK NOTIFICATION AS READ JAVASCRIPT HANDLER
           ========================================================= */
        function markNotificationReadAndRedirect(notifId, redirectUrl, element) {
            fetch('?ajax_action=mark_notif_read&id=' + notifId)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        const dot = element.querySelector('.unread-dot');
                        if (dot) dot.remove();
                        element.style.background = '#fff';
                        
                        const badge = document.getElementById('notifBadgeCount');
                        const headerCount = document.getElementById('notifHeaderCount');
                        if (badge) {
                            let currentCount = parseInt(badge.textContent) - 1;
                            if (currentCount > 0) {
                                badge.textContent = currentCount;
                                if (headerCount) headerCount.textContent = currentCount + ' New';
                            } else {
                                badge.remove();
                                if (headerCount) headerCount.remove();
                            }
                        }
                    }
                    window.location.href = redirectUrl;
                })
                .catch(() => {
                    window.location.href = redirectUrl;
                });
        }

        // Search Modal Functions
        const searchModalOverlay = document.getElementById('searchModalOverlay');
        const modalSearchInput = document.getElementById('modalSearchInput');
        const modalSearchResults = document.getElementById('modalSearchResults');

        function openSearchModal() {
            searchModalOverlay.classList.add('active');
            setTimeout(() => modalSearchInput.focus(), 100);
        }

        function closeSearchModal() {
            searchModalOverlay.classList.remove('active');
            modalSearchInput.value = '';
            modalSearchResults.innerHTML = `
                <div style="text-align: center; padding: 40px; color: #64748b; font-size: 13px;">
                    <i class="fa-solid fa-keyboard" style="font-size: 28px; margin-bottom: 10px; display: block; opacity: 0.5; color: #0284c7;"></i>
                    Type at least 2 characters to search records...
                </div>
            `;
        }

        function closeSearchModalOnOutside(event) {
            if (event.target === searchModalOverlay) closeSearchModal();
        }

        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                openSearchModal();
            }
            if (e.key === 'Escape') closeSearchModal();
        });

        let searchTimeout;
        if (modalSearchInput) {
            modalSearchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                let query = this.value.trim();
                if (query.length < 2) {
                    modalSearchResults.innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #64748b; font-size: 13px;">
                            <i class="fa-solid fa-keyboard" style="font-size: 28px; margin-bottom: 10px; display: block; opacity: 0.5; color: #0284c7;"></i>
                            Type at least 2 characters to search records...
                        </div>
                    `;
                    return;
                }

                modalSearchResults.innerHTML = '<div style="text-align: center; padding: 30px; color: #64748b;"><i class="fa-solid fa-spinner fa-spin" style="font-size: 20px; margin-bottom: 8px; color: #0284c7;"></i><div>Searching records...</div></div>';

                searchTimeout = setTimeout(() => {
                    fetch('?ajax_action=search&q=' + encodeURIComponent(query))
                        .then(res => res.text())
                        .then(html => { modalSearchResults.innerHTML = html; });
                }, 300);
            });
        }

        // Profile and Notification Dropdowns
        const profileToggle = document.getElementById('profileToggle');
        const profileDropdown = document.getElementById('profileDropdown');
        if (profileToggle) {
            profileToggle.addEventListener('click', (e) => {
                e.stopPropagation();
                profileDropdown.classList.toggle('show');
                if (notifDropdown) notifDropdown.classList.remove('show');
            });
        }

        const notifBtn = document.getElementById('notifBtn');
        const notifDropdown = document.getElementById('notifDropdown');
        if (notifBtn) {
            notifBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                notifDropdown.classList.toggle('show');
                if (profileDropdown) profileDropdown.classList.remove('show');
            });
        }

        if (notifDropdown) {
            notifDropdown.addEventListener('click', (e) => {
                e.stopPropagation();
            });
        }

        document.addEventListener('click', () => {
            if (profileDropdown) profileDropdown.classList.remove('show');
            if (notifDropdown) notifDropdown.classList.remove('show');
        });

        function confirmLogout() {
            Swal.fire({
                title: 'Logout from RENTRACK?',
                text: 'Are you sure you want to log out?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#0284c7',
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