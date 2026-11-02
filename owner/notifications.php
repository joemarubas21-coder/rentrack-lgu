<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();

require_once '../config/db.php';
require_once 'includes/auth.php';

/* Helper Function for PostgreSQL Table Check */
function tableExists($conn, $tableName) {
    try {
        $stmt = $conn->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :t)");
        $stmt->execute([':t' => strtolower($tableName)]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) { return false; }
}

/* Helper Function for PostgreSQL Column Check */
function columnExists($conn, $tableName, $columnName) {
    try {
        $stmt = $conn->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :t AND column_name = :c)");
        $stmt->execute([':t' => strtolower($tableName), ':c' => strtolower($columnName)]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) { return false; }
}

$isAjaxGet  = isset($_GET['action']);
$isAjaxPost = ($_SERVER['REQUEST_METHOD'] === 'POST');

if ($isAjaxGet || $isAjaxPost) {

    header('Content-Type: application/json');

    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
        exit;
    }
    $user_id = (int)$_SESSION['user_id'];

    if ($isAjaxGet && $_GET['action'] === 'list') {
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 5;
        if ($limit <= 0) $limit = 5;
        if ($limit > 200) $limit = 200;

        try {
            $sql = "SELECT id, title, message, link, notification_type, is_read, status, created_at
                    FROM notifications
                    WHERE user_id = :user_id
                    ORDER BY created_at DESC
                    LIMIT :limit";

            $stmt = $conn->prepare($sql);
            $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $data = [];
            foreach ($rows as $row) {
                $data[] = [
                    'id'                => (int) $row['id'],
                    'title'             => $row['title'],
                    'message'           => $row['message'],
                    'link'              => $row['link'],
                    'notification_type' => $row['notification_type'] ?: 'General',
                    'is_read'           => (int) $row['is_read'],
                    'created_at'        => $row['created_at'],
                ];
            }
            echo json_encode(['success' => true, 'data' => $data]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Query failed: ' . safe_error($e)]);
        }
        exit;
    }

    if ($isAjaxPost) {
        $body = json_decode(file_get_contents('php://input'), true);
        $action = is_array($body) ? ($body['action'] ?? '') : '';

        if ($action === 'mark_read') {
            $id = isset($body['id']) ? (int)$body['id'] : 0;
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Missing or invalid id.']);
                exit;
            }
            try {
                $sql = "UPDATE notifications SET is_read = TRUE, status = 'Read' WHERE id = :id AND user_id = :user_id";
                $stmt = $conn->prepare($sql);
                $ok = $stmt->execute([':id' => $id, ':user_id' => $user_id]);
                echo json_encode(['success' => (bool) $ok]);
            } catch (PDOException $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Query failed: ' . safe_error($e)]);
            }
            exit;
        }

        if ($action === 'mark_all_read') {
            try {
                $sql = "UPDATE notifications SET is_read = TRUE, status = 'Read' WHERE user_id = :user_id AND LOWER(is_read::text) IN ('0','false')";
                $stmt = $conn->prepare($sql);
                $ok = $stmt->execute([':user_id' => $user_id]);
                echo json_encode(['success' => (bool) $ok]);
            } catch (PDOException $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Query failed: ' . safe_error($e)]);
            }
            exit;
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        exit;
    }
}

/* =========================================================
   CURRENT OWNER + TOPBAR NOTIFICATION DATA
   (same pattern as dashboard.php, since there is no shared
   includes/topbar.php in this project)
   ========================================================= */
$userName    = $_SESSION['fullname'] ?? 'Owner';
$userId      = (int) ($_SESSION['user_id'] ?? 0);
$userInitial = strtoupper(substr(trim($userName), 0, 1));

$unreadNotifCount = 0;
$notifications = [];
if (tableExists($conn, 'notifications')) {
    if (columnExists($conn, 'notifications', 'is_read')) {
        $stmtN = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE (LOWER(is_read::text) IN ('0', 'false')) AND user_id = :uid");
        $stmtN->execute([':uid' => $userId]);
        $unreadNotifCount = (int) $stmtN->fetchColumn();
    }
    $stmtList = $conn->prepare("SELECT * FROM notifications WHERE user_id = :uid ORDER BY created_at DESC LIMIT 5");
    $stmtList->execute([':uid' => $userId]);
    $notifications = $stmtList->fetchAll(PDO::FETCH_ASSOC);
}

function displayField($row, $possibleFields) {
    foreach ($possibleFields as $field) {
        if (isset($row[$field]) && trim((string)$row[$field]) !== '') return htmlspecialchars((string)$row[$field]);
    }
    return '—';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications | RENTRACK LGU</title>

    <!-- BOOTSTRAP & FONT AWESOME -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <!-- FONTS -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

    <style>
        /* ==============================================
           LIGHT BLUE SYSTEM THEME (same tokens as dashboard.php)
        ============================================== */
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

        * { box-sizing: border-box; margin: 0; padding: 0; }

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

        /* ===== 1. TOP OFFICIAL BAR ===== */
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

        /* ===== 2. SIDEBAR ===== */
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
        .sidebar-menu-wrapper { flex: 1; overflow-y: auto; padding-right: 4px; margin-bottom: 10px; }
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
            position: relative; width: 44px !important; height: 44px !important;
            min-width: 44px !important; min-height: 44px !important; max-width: 44px !important; max-height: 44px !important;
            border-radius: 50% !important; border: 2px dashed #38bdf8 !important;
            display: flex !important; align-items: center !important; justify-content: center !important; flex-shrink: 0 !important;
            background: rgba(255, 255, 255, 0.1); animation: spinRing 6s linear infinite;
        }
        @keyframes spinRing { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .logo-ring .rl-text { font-weight: 800; font-size: 15px; color: #38bdf8; font-family: 'Space Grotesk', sans-serif; line-height: 1; animation: counterSpin 6s linear infinite; }
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
            content: attr(data-title); position: absolute; left: calc(100% + 14px); top: 50%; transform: translateY(-50%);
            background: #0369a1; color: #bae6fd; padding: 6px 12px; border-radius: 8px; font-size: 12px; font-weight: 700;
            white-space: nowrap; border: 1px solid #38bdf8; box-shadow: 0 10px 25px rgba(0,0,0,0.4); z-index: 1050; pointer-events: none;
        }
        .sidebar-footer { margin-top: auto; padding-top: 12px; border-top: 1px solid rgba(255, 255, 255, 0.15); }
        body.sidebar-collapsed .sidebar-footer { text-align: center; }

        /* ===== 3. NAVBAR ===== */
        .navbar-custom {
            position: fixed; top: var(--topbar-height); left: var(--sidebar-width);
            width: calc(100% - var(--sidebar-width)); height: var(--navbar-height);
            background: #ffffff; border-bottom: 1px solid var(--card-border);
            padding: 0 24px; display: flex; align-items: center; justify-content: space-between;
            z-index: 1000; transition: var(--transition);
        }
        body.sidebar-collapsed .navbar-custom { left: var(--sidebar-collapsed); width: calc(100% - var(--sidebar-collapsed)); }
        .navbar-left { display: flex; align-items: center; gap: 12px; }
        .sidebar-toggle {
            background: #f0f9ff; border: 1px solid #bae6fd; color: var(--accent-blue);
            width: 38px; height: 38px; border-radius: 10px; cursor: pointer;
            display: flex; align-items: center; justify-content: center; font-size: 16px; transition: var(--transition);
        }
        .sidebar-toggle:hover { background: #0284c7; color: #fff; border-color: #0284c7; }
        .page-title-box { display: flex; align-items: center; gap: 8px; }
        .page-title { font-family: 'Space Grotesk', sans-serif; font-size: 18px; font-weight: 800; color: #0f172a; margin: 0; }
        .page-sub { font-size: 12.5px; color: #64748b; font-weight: 500; }
        .nav-actions { display: flex; align-items: center; gap: 12px; }
        .clock-pill { background: #f0f9ff; border: 1px solid #bae6fd; padding: 4px 14px; border-radius: 10px; text-align: center; }
        .clock-time { font-family: 'Space Grotesk', sans-serif; font-size: 13px; font-weight: 700; color: #0284c7; line-height: 1.1; }
        .clock-date { font-size: 10px; color: #64748b; font-weight: 600; text-transform: uppercase; }
        .icon-btn {
            background: #f0f9ff; border: 1px solid #bae6fd; color: #0369a1;
            width: 36px; height: 36px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center; cursor: pointer; transition: var(--transition);
        }
        .icon-btn:hover { background: #e0f2fe; border-color: #0284c7; }
        .user-profile {
            display: flex; align-items: center; gap: 10px; padding: 3px 12px 3px 3px;
            background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 999px; cursor: pointer; transition: var(--transition);
        }
        .user-profile:hover { border-color: #0284c7; }
        .avatar {
            width: 30px; height: 30px; border-radius: 50%; background: #0284c7; color: #fff;
            display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 12px;
        }
        .user-info { display: flex; flex-direction: column; text-align: left; }
        .user-name { font-size: 12px; font-weight: 700; color: #0f172a; line-height: 1.1; }
        .user-role { font-size: 9px; font-weight: 700; color: #0284c7; text-transform: uppercase; }
        .profile-dropdown, .notification-dropdown {
            position: absolute; top: calc(100% + 10px); right: 0;
            background: #ffffff; border: 1px solid #bae6fd; border-radius: 14px;
            box-shadow: 0 16px 36px rgba(2, 132, 199, 0.15);
            opacity: 0; visibility: hidden; transform: translateY(-8px); transition: var(--transition); z-index: 100;
        }
        .profile-dropdown.show, .notification-dropdown.show { opacity: 1; visibility: visible; transform: translateY(0); }
        .profile-dropdown { width: 190px; padding: 6px; }
        .notification-dropdown { width: 310px; overflow: hidden; }
        .dropdown-item {
            display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-radius: 8px;
            color: #334155; font-size: 13px; font-weight: 500; text-decoration: none; transition: var(--transition);
        }
        .dropdown-item:hover { background: #f0f9ff; color: #0284c7; }
        .dropdown-item.danger { color: #ef4444; }
        .dropdown-item.danger:hover { background: #fef2f2; }

        /* ===== 4. MAIN CONTENT ===== */
        .wrapper { display: flex; min-height: 100vh; padding-top: calc(var(--topbar-height) + var(--navbar-height)); }
        .main { flex: 1; padding: 22px 24px 40px; margin-left: var(--sidebar-width); transition: var(--transition); }
        body.sidebar-collapsed .main { margin-left: var(--sidebar-collapsed); }
        .dashboard-shell { display: flex; flex-direction: column; gap: 18px; }

        .dashboard-footer { margin-top: 20px; padding-top: 14px; border-top: 1px solid #bae6fd; display: flex; justify-content: space-between; font-size: 11.5px; color: #64748b; }

        @media (max-width: 992px) {
            .sidebar { transform: translateX(-100%); width: var(--sidebar-width) !important; top: var(--topbar-height) !important; height: calc(100vh - var(--topbar-height)) !important; }
            body.sidebar-open .sidebar { transform: translateX(0); }
            .navbar-custom { left: 0 !important; width: 100% !important; }
            .main { margin-left: 0 !important; padding: 16px; }
            .clock-pill { display: none; }
        }

        /* ==============================================
           NOTIFICATIONS PANEL (blue theme, matches dashboard)
        ============================================== */
        .nf-header{
            margin-bottom: 4px; display:flex; align-items:center; justify-content:space-between;
            gap:16px; flex-wrap:wrap;
        }
        .nf-header h2{
            margin:0; font-family:'Space Grotesk', sans-serif; font-size:22px; font-weight:800; color: #0f172a;
            display:flex; align-items:center; gap:12px; letter-spacing:-.3px;
        }
        .nf-header h2 i{
            color:#fff; font-size:16px; width:38px; height:38px; flex-shrink:0;
            display:flex; align-items:center; justify-content:center; border-radius:12px;
            background: linear-gradient(135deg, #0284c7, #0369a1);
            box-shadow: 0 6px 14px -4px rgba(2,132,199,.5);
        }
        .nf-header p{ margin:6px 0 0 50px; color: #64748b; font-size:12.5px; }

        .nf-actions{ display:flex; gap:10px; align-items:center; flex-shrink:0; }
        .btn-nf{
            display:inline-flex; align-items:center; gap:7px; white-space:nowrap;
            border-radius:10px; font-size:12.5px; font-weight:700; padding:9px 16px;
            cursor:pointer; transition: var(--transition); border:1px solid transparent;
        }
        .btn-nf-outline{ background:#f0f9ff; border-color:#bae6fd; color:#0369a1; }
        .btn-nf-outline:hover{ background:#e0f2fe; border-color:#0284c7; }
        .btn-nf-solid{ background: linear-gradient(135deg, #0284c7, #0369a1); color:#fff; box-shadow: 0 4px 12px -3px rgba(2,132,199,.55); }
        .btn-nf-solid:hover{ filter:brightness(1.05); }

        .nf-card{
            background: var(--bg-card); border: 1px solid var(--card-border);
            border-radius: 18px; overflow:hidden;
            box-shadow: 0 10px 28px -18px rgba(2,132,199,.35), 0 4px 15px rgba(2, 132, 199, 0.05);
        }

        .nf-tabs{
            display:flex; gap:4px; padding:12px; background:#f8fafc;
            border-bottom:1px solid var(--card-border);
            overflow-x:auto; -webkit-overflow-scrolling:touch; scrollbar-width:none;
        }
        .nf-tabs::-webkit-scrollbar{ display:none; }
        .nf-tab{
            padding:9px 16px; font-size:13px; font-weight:600; color:#64748b;
            border-radius:999px; cursor:pointer; border:1px solid transparent; background:transparent;
            display:flex; align-items:center; gap:7px; flex-shrink:0;
            transition: background 120ms ease, color 120ms ease;
        }
        .nf-tab:hover{ color:#0f172a; background:#fff; }
        .nf-tab.active{ color:#fff; background: linear-gradient(135deg, #0284c7, #0369a1); box-shadow: 0 4px 12px -4px rgba(2,132,199,.6); }
        .nf-tab .count{ background:#e2e8f0; color:#64748b; border-radius:999px; font-size:11px; padding:1px 7px; font-weight:700; min-width:18px; text-align:center; }
        .nf-tab.active .count{ background: rgba(255,255,255,.28); color:#fff; }

        .nf-list{ min-height:200px; }
        .nf-item{
            display:flex; gap:14px; align-items:flex-start;
            padding:16px 20px; border-bottom:1px solid #f1f5f9;
            cursor:pointer; position:relative; border-left:3px solid transparent;
            transition: background 120ms ease, border-color 120ms ease;
        }
        .nf-item:last-child{ border-bottom:0; }
        .nf-item:hover{ background:#f8fafc; }
        .nf-item:active{ background:#e0f2fe; }
        .nf-item.unread{ background: rgba(2, 132, 199, 0.05); border-left-color: #0284c7; }

        .nf-icon{
            flex-shrink:0; width:44px; height:44px; border-radius:13px;
            display:flex; align-items:center; justify-content:center;
            background:#e0f2fe; color:#0284c7; font-size:1.05rem;
        }
        .nf-icon.nf-icon--rental{ background:#dbeafe; color:#2563eb; }
        .nf-icon.nf-icon--inspection{ background:#fef3c7; color:#d97706; }
        .nf-icon.nf-icon--permit{ background:#d1fae5; color:#059669; }
        .nf-icon.nf-icon--reminder{ background:#fce7f3; color:#db2777; }
        .nf-item.unread .nf-icon{ box-shadow: 0 0 0 3px #fff, 0 0 0 4.5px currentColor; }

        .nf-body{ flex:1; min-width:0; }
        .nf-top-row{ display:flex; justify-content:space-between; gap:10px; align-items:baseline; }
        .nf-title{ font-weight:700; font-size:13.5px; color:#0f172a; overflow:hidden; text-overflow:ellipsis; display:-webkit-box; -webkit-line-clamp:1; -webkit-box-orient:vertical; }
        .nf-time{ font-size:11px; color:#94a3b8; white-space:nowrap; flex-shrink:0; }
        .nf-message{ color:#64748b; font-size:12.5px; margin-top:3px; line-height:1.5; overflow:hidden; text-overflow:ellipsis; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
        .nf-type-badge{ display:inline-block; margin-top:9px; font-size:10px; font-weight:700; letter-spacing:.3px; text-transform:uppercase; padding:3px 9px; border-radius:999px; background:#e0f2fe; color:#0284c7; }
        .nf-type-badge--rental{ background:#dbeafe; color:#2563eb; }
        .nf-type-badge--inspection{ background:#fef3c7; color:#d97706; }
        .nf-type-badge--permit{ background:#d1fae5; color:#059669; }
        .nf-type-badge--reminder{ background:#fce7f3; color:#db2777; }

        .nf-loading, .nf-empty, .nf-error{
            display:flex; flex-direction:column; align-items:center; justify-content:center;
            gap:14px; padding:64px 20px; color:#94a3b8; font-size:13px; text-align:center;
        }
        .nf-state-icon{ width:56px; height:56px; border-radius:999px; display:flex; align-items:center; justify-content:center; background:#f0f9ff; font-size:1.5rem; }
        .nf-loading .spinner{ width:28px; height:28px; border-radius:999px; border:3px solid #e2e8f0; border-top-color:#0284c7; animation:nf-spin 700ms linear infinite; }
        @keyframes nf-spin{ to{ transform:rotate(360deg); } }
        .nf-error .nf-state-icon i{ color:#ef4444; }

        @media (max-width: 640px){
            .nf-header{ gap:12px; }
            .nf-header h2{ font-size:19px; }
            .nf-header h2 i{ width:32px; height:32px; font-size:14px; border-radius:10px; }
            .nf-header p{ font-size:12px; margin-left:44px; }
            .nf-actions{ width:100%; }
            .btn-nf{ flex:1; justify-content:center; }

            .nf-card{ border-radius:14px; }
            .nf-tabs{ padding:10px; }
            .nf-tab{ padding:8px 13px; font-size:12.5px; }

            .nf-item{ padding:13px 14px; gap:11px; }
            .nf-icon{ width:36px; height:36px; font-size:.9rem; border-radius:10px; }
            .nf-title{ font-size:13px; }
            .nf-message{ font-size:12px; }
            .nf-top-row{ flex-wrap:wrap; }
            .nf-time{ font-size:10.5px; }
        }
        @media (max-width: 380px){
            .nf-actions{ flex-direction:column; }
            .btn-nf{ width:100%; }
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
            <div class="logo-ring"><span class="rl-text">RL</span></div>
            <div class="brand-text-wrapper"><span class="brand-text">RENTRACK <span class="brand-tag">LGU</span></span></div>
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
            <a href="payments.php" class="nav-item" data-title="Payments"><i class="fa-solid fa-cash-register"></i> <span>Payments</span></a>

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
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar"><i class="fa-solid fa-bars"></i></button>
            <div class="page-title-box">
                <h1 class="page-title">Notifications</h1>
                <span class="page-sub d-none d-sm-inline">All updates and alerts</span>
            </div>
        </div>

        <div class="nav-actions">
            <div class="clock-pill">
                <div class="clock-time" id="liveTime"><?php echo date('h:i:s A'); ?></div>
                <div class="clock-date"><?php echo date('M d, Y'); ?></div>
            </div>

            <div style="position:relative;">
                <button class="icon-btn" id="notifBtn">
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
                                    $notifLink = $notification['link'] ?: 'notifications.php';
                                    $isUnread = (!isset($notification['is_read']) || $notification['is_read'] === false || $notification['is_read'] === '0' || $notification['is_read'] === 0);
                                ?>
                                <a href="javascript:void(0)" onclick="quickMarkRead(<?php echo (int)$notification['id']; ?>, '<?php echo htmlspecialchars($notifLink); ?>')" style="display:block; padding:10px 14px; border-bottom:1px solid #f1f5f9; text-decoration:none; color:#334155; background: <?php echo $isUnread ? '#f0f9ff' : '#fff'; ?>; transition:background 0.2s;" onmouseover="this.style.background='#e0f2fe'" onmouseout="this.style.background='<?php echo $isUnread ? '#f0f9ff' : '#fff'; ?>'">
                                    <div style="display:flex; justify-content:space-between; align-items:center;">
                                        <strong style="font-size:12px; display:block; color:#0f172a;"><?php echo displayField($notification, ['title']); ?></strong>
                                        <?php if ($isUnread): ?><span style="width:8px; height:8px; background:#ef4444; border-radius:50%; display:inline-block;"></span><?php endif; ?>
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
                    <div class="avatar"><?php echo htmlspecialchars($userInitial); ?></div>
                    <div class="user-info d-none d-sm-flex">
                        <span class="user-name"><?php echo htmlspecialchars($userName); ?></span>
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

    <!-- 4. MAIN CONTENT -->
    <div class="wrapper">
        <div class="main">
            <div class="dashboard-shell">

                <div class="nf-header">
                    <div>
                        <h2><i class="fa-solid fa-bell"></i> Notifications</h2>
                        <p>All updates and alerts related to your rentals.</p>
                    </div>
                    <div class="nf-actions">
                        <button type="button" class="btn-nf btn-nf-outline" id="refreshBtn"><i class="fa-solid fa-rotate-right"></i> Refresh</button>
                        <button type="button" class="btn-nf btn-nf-solid" id="markAllBtn"><i class="fa-solid fa-check-double"></i> Mark all as read</button>
                    </div>
                </div>

                <div class="nf-card">
                    <div class="nf-tabs">
                        <button type="button" class="nf-tab active" data-filter="all"><i class="fa-solid fa-layer-group"></i> All <span class="count" id="countAll">0</span></button>
                        <button type="button" class="nf-tab" data-filter="unread"><i class="fa-solid fa-envelope"></i> Unread <span class="count" id="countUnread">0</span></button>
                        <button type="button" class="nf-tab" data-filter="read"><i class="fa-solid fa-envelope-open"></i> Read <span class="count" id="countRead">0</span></button>
                    </div>
                    <div class="nf-list" id="nfList">
                        <div class="nf-loading">
                            <div class="spinner"></div>
                            <span>Loading notifications…</span>
                        </div>
                    </div>
                </div>

            </div>

            <footer class="dashboard-footer">
                <span>&copy; <?php echo date('Y'); ?> <span style="color:#0284c7; font-weight:700;">RENTRACK LGU</span> &middot; Unified Rental System</span>
                <span>Owner Portal</span>
            </footer>
        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
(function () {
    /* ---------- Live clock ---------- */
    function updateClock(){
        const timeBox = document.getElementById('liveTime');
        if (timeBox) timeBox.textContent = new Date().toLocaleTimeString('en-US', { hour:'2-digit', minute:'2-digit', second:'2-digit' });
    }
    setInterval(updateClock, 1000);
    updateClock();

    /* ---------- Sidebar toggle ---------- */
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

    /* ---------- Profile / notif dropdowns ---------- */
    const profileToggle = document.getElementById('profileToggle');
    const profileDropdown = document.getElementById('profileDropdown');
    const notifBtn = document.getElementById('notifBtn');
    const notifDropdown = document.getElementById('notifDropdown');

    if (profileToggle) {
        profileToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            profileDropdown.classList.toggle('show');
            if (notifDropdown) notifDropdown.classList.remove('show');
        });
    }
    if (notifBtn) {
        notifBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            notifDropdown.classList.toggle('show');
            if (profileDropdown) profileDropdown.classList.remove('show');
        });
    }
    if (notifDropdown) notifDropdown.addEventListener('click', (e) => e.stopPropagation());
    document.addEventListener('click', () => {
        if (profileDropdown) profileDropdown.classList.remove('show');
        if (notifDropdown) notifDropdown.classList.remove('show');
    });

    function confirmLogout() {
        Swal.fire({
            title: 'Logout from RENTRACK?', text: 'Are you sure you want to log out?', icon: 'warning',
            showCancelButton: true, confirmButtonColor: '#0284c7', cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, Logout', background: '#ffffff', color: '#0f172a'
        }).then((res) => { if (res.isConfirmed) window.location.href = '../logout.php'; });
    }
    document.getElementById('profileLogoutBtn')?.addEventListener('click', confirmLogout);
    document.getElementById('sidebarLogoutBtn')?.addEventListener('click', confirmLogout);

    /* ---------- Notifications list ---------- */
    const listEl = document.getElementById('nfList');
    const tabs = document.querySelectorAll('.nf-tab');
    let allNotifs = [];
    let currentFilter = 'all';

    function iconForType(type){
        switch (type) {
            case 'Rental':     return 'fa-building';
            case 'Inspection': return 'fa-magnifying-glass';
            case 'Permit':     return 'fa-file-contract';
            case 'Reminder':   return 'fa-bell';
            default:           return 'fa-circle-info';
        }
    }
    function classForType(type){
        switch (type) {
            case 'Rental':     return 'rental';
            case 'Inspection': return 'inspection';
            case 'Permit':     return 'permit';
            case 'Reminder':   return 'reminder';
            default:           return '';
        }
    }
    function timeAgo(dateStr) {
        try {
            const then = new Date(dateStr).getTime();
            const diffSec = Math.floor((Date.now() - then) / 1000);
            if (diffSec < 60) return 'Just now';
            if (diffSec < 3600) return Math.floor(diffSec/60) + 'm ago';
            if (diffSec < 86400) return Math.floor(diffSec/3600) + 'h ago';
            if (diffSec < 604800) return Math.floor(diffSec/86400) + 'd ago';
            return new Date(dateStr).toLocaleDateString('en-PH', { month:'short', day:'2-digit', year:'numeric' });
        } catch (e) { return dateStr; }
    }
    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[m]);
    }

    function syncTopbarBadge(){
        const badge = document.getElementById('notifBadgeCount');
        const headerCount = document.getElementById('notifHeaderCount');
        const unread = allNotifs.filter(n => n.is_read == 0).length;
        if (badge) { if (unread > 0) { badge.textContent = unread; } else { badge.remove(); } }
        if (headerCount) { if (unread > 0) { headerCount.textContent = unread + ' New'; } else { headerCount.remove(); } }
    }

    function updateCounts(){
        document.getElementById('countAll').textContent = allNotifs.length;
        document.getElementById('countUnread').textContent = allNotifs.filter(n => n.is_read == 0).length;
        document.getElementById('countRead').textContent = allNotifs.filter(n => n.is_read == 1).length;
        syncTopbarBadge();
    }

    function render(){
        const filtered = allNotifs.filter(n => {
            if (currentFilter === 'unread') return n.is_read == 0;
            if (currentFilter === 'read') return n.is_read == 1;
            return true;
        });

        if (filtered.length === 0) {
            const isFiltered = currentFilter !== 'all';
            listEl.innerHTML = `<div class="nf-empty"><div class="nf-state-icon"><i class="fa-regular fa-bell-slash" style="color:#94a3b8;"></i></div><span>${isFiltered ? 'Wala pang notification dito.' : 'No notifications yet.'}</span></div>`;
            return;
        }

        listEl.innerHTML = '';
        filtered.forEach(n => {
            const isUnread = n.is_read == 0;
            const typeClass = classForType(n.notification_type);
            const item = document.createElement('div');
            item.className = 'nf-item' + (isUnread ? ' unread' : '');
            item.innerHTML = `
                <div class="nf-icon${typeClass ? ' nf-icon--' + typeClass : ''}"><i class="fa-solid ${iconForType(n.notification_type)}"></i></div>
                <div class="nf-body">
                    <div class="nf-top-row">
                        <span class="nf-title">${escapeHtml(n.title)}</span>
                        <span class="nf-time">${timeAgo(n.created_at)}</span>
                    </div>
                    <div class="nf-message">${escapeHtml(n.message)}</div>
                    <span class="nf-type-badge${typeClass ? ' nf-type-badge--' + typeClass : ''}">${escapeHtml(n.notification_type || 'General')}</span>
                </div>
            `;
            item.addEventListener('click', () => {
                markRead(n.id, isUnread).then(() => {
                    if (n.link) window.location.href = n.link;
                });
            });
            listEl.appendChild(item);
        });
    }

    async function loadAll(){
        listEl.innerHTML = `<div class="nf-loading"><div class="spinner"></div><span>Loading notifications…</span></div>`;
        try {
            const res = await fetch('notifications.php?action=list&limit=200', { credentials: 'same-origin' });
            const json = await res.json();
            if (!json.success) {
                listEl.innerHTML = `<div class="nf-error"><div class="nf-state-icon"><i class="fa-solid fa-triangle-exclamation"></i></div><span>${escapeHtml(json.message || 'Unable to load notifications')}</span></div>`;
                return;
            }
            allNotifs = json.data || [];
            updateCounts();
            render();
        } catch (e) {
            listEl.innerHTML = `<div class="nf-error"><div class="nf-state-icon"><i class="fa-solid fa-plug-circle-xmark"></i></div><span>Error loading notifications</span></div>`;
            console.error(e);
        }
    }

    async function markRead(id, wasUnread){
        if (!wasUnread) return true;
        try {
            const res = await fetch('notifications.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'mark_read', id })
            });
            const json = await res.json();
            if (json.success) {
                const found = allNotifs.find(x => x.id === id);
                if (found) found.is_read = 1;
                updateCounts();
                render();
            }
            return json.success;
        } catch (e) { console.error(e); return false; }
    }

    window.quickMarkRead = function(id, link){
        markRead(id, true).finally(() => { window.location.href = link; });
    };

    async function markAllRead(){
        try {
            const res = await fetch('notifications.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'mark_all_read' })
            });
            const json = await res.json();
            if (json.success) {
                allNotifs.forEach(n => n.is_read = 1);
                updateCounts();
                render();
                Swal.fire({ icon:'success', title:'All notifications marked as read', toast:true, position:'top-end', timer:2200, showConfirmButton:false });
            }
        } catch (e) { console.error(e); }
    }

    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            tabs.forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            currentFilter = tab.dataset.filter;
            render();
        });
    });

    document.getElementById('refreshBtn').addEventListener('click', loadAll);
    document.getElementById('markAllBtn').addEventListener('click', () => {
        Swal.fire({
            title: 'Mark all notifications as read?', icon: 'question', showCancelButton: true,
            confirmButtonText: 'Yes, mark all read', cancelButtonText: 'Cancel',
            confirmButtonColor: '#0284c7', cancelButtonColor: '#94a3b8', reverseButtons: true
        }).then(result => { if (result.isConfirmed) markAllRead(); });
    });

    const notifClearBtn = document.getElementById('notifClearBtn');
    if (notifClearBtn) {
        notifClearBtn.addEventListener('click', async (e) => {
            e.stopPropagation();
            await markAllRead();
            const listBody = document.getElementById('notifListBody');
            if (listBody) {
                listBody.innerHTML = '<div style="padding:24px; text-align:center; color:#94a3b8; font-size:12px;"><i class="fa-solid fa-check-double" style="font-size:20px; margin-bottom:8px; display:block; color:#0284c7;"></i>You\'re all caught up!</div>';
            }
        });
    }

    loadAll();
})();
</script>

</body>
</html>