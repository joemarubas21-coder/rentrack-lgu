<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../login.php");
    exit();
}

require_once("../config/db.php");

$adminId = (int) $_SESSION['user_id'];
$successMessage = '';
$errorMessage = '';

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* =====================================================
   HELPER FUNCTIONS (PDO / PostgreSQL Compatible)
===================================================== */

function tableExists($conn, $tableName) {
    try {
        $stmt = $conn->prepare("
            SELECT EXISTS (
                SELECT 1 
                FROM information_schema.tables 
                WHERE table_schema = 'public' 
                AND table_name = :table
            )
        ");
        $stmt->execute([':table' => strtolower($tableName)]);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

function columnExists($conn, $tableName, $columnName) {
    if (!tableExists($conn, $tableName)) return false;
    try {
        $stmt = $conn->prepare("
            SELECT EXISTS (
                SELECT 1 
                FROM information_schema.columns 
                WHERE table_schema = 'public' 
                AND table_name = :table 
                AND column_name = :column
            )
        ");
        $stmt->execute([
            ':table' => strtolower($tableName),
            ':column' => strtolower($columnName)
        ]);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

// ============================================================
// AJAX NOTIFICATION HANDLER
// ============================================================

if (isset($_GET['action']) && $_GET['action'] === 'mark_notif_read') {
    if (tableExists($conn, 'notifications')) {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = :user_id");
        $stmt->execute([':user_id' => $adminId]);
    }
    echo json_encode(['status' => 'success']);
    exit;
}

// ============================================================
// HANDLE FORM SUBMISSION (UPDATE PROFILE)
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname = trim($_POST['fullname'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if ($fullname === '' || $email === '') {
        $errorMessage = 'Full name and email address are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = 'Please enter a valid email address.';
    } else {
        try {
            // Check if email is already taken by another user
            $checkStmt = $conn->prepare("SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1");
            $checkStmt->execute([
                ':email' => $email,
                ':id'    => $adminId
            ]);

            if ($checkStmt->fetch()) {
                $errorMessage = 'The email address is already in use by another account.';
            } else {
                $updateStmt = $conn->prepare("UPDATE users SET fullname = :fullname, email = :email WHERE id = :id");
                $updated = $updateStmt->execute([
                    ':fullname' => $fullname,
                    ':email'    => $email,
                    ':id'       => $adminId
                ]);

                if ($updated) {
                    $_SESSION['fullname'] = $fullname;
                    $_SESSION['email'] = $email;
                    $successMessage = 'Profile updated successfully.';
                } else {
                    $errorMessage = 'Something went wrong while updating your profile. Please try again.';
                }
            }
        } catch (PDOException $e) {
            $errorMessage = 'Database error: ' . safe_error($e);
        }
    }
}

// ============================================================
// GET USER DATA
// ============================================================

$userData = [];
if (tableExists($conn, 'users')) {
    $stmt = $conn->prepare("SELECT id, fullname, username, email, role, status, created_at FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $adminId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $userData = $row;
    }
}

$adminName = $userData['fullname'] ?? ($_SESSION['fullname'] ?? 'System Administrator');
$adminEmail = $userData['email'] ?? ($_SESSION['email'] ?? 'admin@rentrack.lgu.gov.ph');
$adminRole = $userData['role'] ?? ($_SESSION['role'] ?? 'Administrator');
$adminInitial = strtoupper(substr(trim($adminName), 0, 1));

// ============================================================
// GET NOTIFICATIONS
// ============================================================

$unreadNotifCount = 0;
$notifications = [];

if (tableExists($conn, 'notifications')) {
    $stmtCount = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = FALSE");
    $stmtCount->execute([':user_id' => $adminId]);
    $unreadNotifCount = (int) $stmtCount->fetchColumn();

    $hasTargetUrl = columnExists($conn, 'notifications', 'target_url');
    $selectFields = $hasTargetUrl ? "id, title, message, target_url, created_at" : "id, title, message, created_at";

    $stmtNotif = $conn->prepare("SELECT $selectFields FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 5");
    $stmtNotif->execute([':user_id' => $adminId]);
    while ($row = $stmtNotif->fetch(PDO::FETCH_ASSOC)) {
        if (!$hasTargetUrl) {
            $row['target_url'] = 'javascript:void(0)';
        }
        $notifications[] = $row;
    }
}

$searchQuery = trim($_GET['search'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Profile | Admin | RENTRACK LGU</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

<style>
    :root {
        --bg-body: #111318;
        --bg-card: #181c24;
        --bg-card-alt: #222834;
        --sidebar-bg: #111827;
        --topofficial-bg: #881337;
        --navbar-bg: #181c24;
        --red-accent: #e11d48;
        --red-hover: #f43f5e;
        --red-soft: #fecdd3;
        --text-main: #f8fafc;
        --text-sub: #cbd5e1;
        --text-muted: #94a3b8;
        --border-color: rgba(255, 255, 255, 0.08);
        --topofficial-height: 38px;
        --navbar-height: 64px;
        --sidebar-width: 260px;
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
        background-color: var(--bg-body);
        background-image: 
            radial-gradient(at 0% 0%, rgba(225, 29, 72, 0.08) 0px, transparent 50%),
            radial-gradient(at 100% 100%, rgba(136, 19, 55, 0.1) 0px, transparent 50%);
        background-attachment: fixed;
        font-family: 'Plus Jakarta Sans', sans-serif;
        color: var(--text-main);
        min-height: 100vh;
        overflow-x: hidden;
        padding-top: calc(var(--topofficial-height) + var(--navbar-height));
    }

    ::-webkit-scrollbar { width: 6px; }
    ::-webkit-scrollbar-track { background: var(--bg-body); }
    ::-webkit-scrollbar-thumb { background: #334155; border-radius: 20px; }

    /* TOP OFFICIAL BAR */
    .top-official-bar {
        position: fixed; top: 0; left: 0; width: 100%;
        background: var(--topofficial-bg);
        border-bottom: 1px solid rgba(255, 255, 255, 0.15);
        padding: 0 20px; display: flex; justify-content: space-between; align-items: center;
        font-size: 11px; font-weight: 600; z-index: 1002; height: var(--topofficial-height);
        color: #fff;
    }
    .topbar-left { display: flex; align-items: center; gap: 8px; color: #fff; }
    .topbar-left i { font-size: 12px; color: #fecdd3; }
    .topbar-left strong { letter-spacing: 0.3px; font-weight: 800; color: #fff; }
    .topbar-left .divider { width: 1px; height: 12px; background: rgba(255, 255, 255, 0.3); }

    .cert-badge {
        display: inline-flex; align-items: center; gap: 6px; padding: 3px 12px;
        background: rgba(255, 255, 255, 0.15); border: 1px solid rgba(255, 255, 255, 0.3);
        border-radius: 999px; color: #fff; font-weight: 700; font-size: 10px;
        text-transform: uppercase; letter-spacing: 0.5px;
    }

    /* NAVBAR */
    .rt-topbar {
        position: fixed;
        top: var(--topofficial-height); left: 0; width: 100%;
        background: var(--navbar-bg);
        border-bottom: 1px solid var(--border-color);
        padding: 0 24px;
        display: flex; align-items: center; justify-content: space-between;
        height: var(--navbar-height);
        z-index: 1001; gap: 16px;
    }

    .rt-topbar-left { display: flex; align-items: center; gap: 14px; }

    .sidebar-toggle-btn {
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid var(--border-color);
        color: var(--text-main); width: 38px; height: 38px;
        border-radius: 10px; display: flex; align-items: center; justify-content: center;
        cursor: pointer; font-size: 16px; transition: var(--transition);
    }
    .sidebar-toggle-btn:hover { background: var(--red-accent); color: #fff; border-color: var(--red-accent); }

    .rt-topbar-left .page-title {
        font-family: 'Space Grotesk', sans-serif;
        font-size: 18px; font-weight: 800; color: #fff; margin: 0;
    }
    .rt-topbar-left .page-title span { color: var(--text-sub); font-weight: 500; font-size: 13px; }

    .rt-topbar-center {
        flex: 1; display: flex; justify-content: center;
        padding: 0 16px; max-width: 500px; margin: 0 auto; position: relative;
    }

    .rt-search-container { position: relative; width: 100%; }
    .rt-search-container i {
        position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
        color: var(--text-muted); font-size: 14px;
    }
    .rt-search-container input {
        width: 100%; padding: 8px 16px 8px 38px; border-radius: 999px;
        border: 1px solid var(--border-color); background: rgba(17, 19, 24, 0.8);
        color: #fff; font-size: 13px; outline: none; transition: var(--transition);
    }
    .rt-search-container input::placeholder { color: var(--text-muted); }
    .rt-search-container input:focus {
        background: var(--bg-body); border-color: var(--red-accent);
        box-shadow: 0 0 12px rgba(225, 29, 72, 0.25);
    }

    .rt-topbar-right { display: flex; align-items: center; gap: 12px; }

    .icon-btn {
        width: 38px; height: 38px; border-radius: 10px;
        border: 1px solid var(--border-color); background: rgba(17, 19, 24, 0.8);
        color: #fff; display: flex; align-items: center; justify-content: center;
        cursor: pointer; position: relative; transition: var(--transition);
    }
    .icon-btn:hover { background: var(--bg-card-alt); border-color: var(--red-accent); }

    .notif-badge-dot {
        position: absolute; top: 6px; right: 6px; width: 9px; height: 9px;
        border-radius: 50%; background: var(--red-accent);
        box-shadow: 0 0 8px var(--red-accent);
        transition: opacity 0.3s ease;
    }

    /* PROFILE DROPDOWN */
    .rt-profile-wrapper { position: relative; }
    .rt-profile-btn {
        display: flex; align-items: center; gap: 10px; padding: 4px 14px 4px 4px;
        border-radius: 999px; border: 1px solid var(--border-color);
        background: rgba(17, 19, 24, 0.8); cursor: pointer; transition: var(--transition);
    }
    .rt-profile-btn:hover { border-color: var(--red-accent); }
    .rt-profile-btn .avatar {
        width: 32px; height: 32px; border-radius: 50%;
        background: var(--red-accent); color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-weight: 800; font-size: 13px;
    }
    .rt-profile-btn .info { display: flex; flex-direction: column; line-height: 1.1; }
    .rt-profile-btn .info .name { font-size: 12px; font-weight: 700; color: #fff; }
    .rt-profile-btn .info .role { font-size: 9px; font-weight: 700; color: var(--red-soft); text-transform: uppercase; }

    .rt-profile-dropdown {
        position: absolute; top: calc(100% + 10px); right: 0;
        width: 200px; background: var(--bg-card); border: 1px solid var(--border-color);
        border-radius: 14px; padding: 8px; box-shadow: 0 20px 40px rgba(0,0,0,0.5);
        opacity: 0; visibility: hidden; transform: translateY(-8px);
        transition: var(--transition); z-index: 100;
    }
    .rt-profile-dropdown.show { opacity: 1; visibility: visible; transform: translateY(0); }

    .rt-profile-dropdown .dropdown-item {
        display: flex; align-items: center; gap: 10px; padding: 9px 12px;
        border-radius: 8px; color: var(--text-sub); text-decoration: none;
        font-size: 13px; font-weight: 500; transition: var(--transition);
    }
    .rt-profile-dropdown .dropdown-item:hover { background: rgba(225, 29, 72, 0.15); color: #fff; }
    .rt-profile-dropdown .dropdown-item.danger { color: #f87171; }

    /* SIDEBAR */
    .wrapper { display: flex; min-height: calc(100vh - var(--topofficial-height) - var(--navbar-height)); }

    .sidebar {
        width: var(--sidebar-width); flex-shrink: 0;
        background: var(--sidebar-bg);
        border-right: 1px solid var(--border-color);
        padding: 20px 16px; position: fixed;
        top: calc(var(--topofficial-height) + var(--navbar-height));
        left: 0; height: calc(100vh - var(--topofficial-height) - var(--navbar-height));
        overflow-y: auto; display: flex; flex-direction: column; z-index: 1000;
        transition: var(--transition);
    }

    body.sidebar-collapsed .sidebar { transform: translateX(-100%); }
    body.sidebar-collapsed .main { margin-left: 0; }

    .sidebar .brand {
        font-family: 'Space Grotesk', sans-serif;
        font-size: 20px; font-weight: 800; color: #fff;
        padding-bottom: 16px; border-bottom: 1px solid var(--border-color);
        margin-bottom: 16px; display: flex; align-items: center; gap: 12px;
    }

    .animated-logo-avatar {
        position: relative; width: 44px; height: 44px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        background: #111827; padding: 3px; flex-shrink: 0;
        box-shadow: 0 0 15px rgba(225, 29, 72, 0.3);
    }
    .animated-logo-avatar::before {
        content: ''; position: absolute; inset: -2px; border-radius: 50%;
        background: conic-gradient(from 0deg, #e11d48 0deg, #f43f5e 120deg, transparent 210deg, #e11d48 360deg);
        animation: rotateGlow 3s linear infinite; z-index: 1;
    }
    .animated-logo-avatar::after {
        content: ''; position: absolute; inset: 2px; background: #111827; border-radius: 50%; z-index: 2;
    }
    .animated-logo-avatar span {
        position: relative; z-index: 3; font-weight: 800; font-size: 14px;
        color: #f43f5e; letter-spacing: -0.5px;
    }

    @keyframes rotateGlow {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    .sidebar .nav-section { flex: 1; }
    .sidebar .nav-label {
        font-size: 10px; font-weight: 700; text-transform: uppercase;
        letter-spacing: 0.08em; color: var(--text-muted); padding: 8px 12px 6px; margin-top: 6px;
    }
    .sidebar .nav-item {
        display: flex; align-items: center; gap: 12px; padding: 10px 12px;
        border-radius: 10px; color: var(--text-sub); text-decoration: none;
        font-size: 13.5px; font-weight: 600; transition: var(--transition); margin-bottom: 2px;
    }
    .sidebar .nav-item:hover { background: rgba(255, 255, 255, 0.08); color: #fff; }
    .sidebar .nav-item.active {
        background: var(--red-accent); color: #fff; box-shadow: 0 4px 12px rgba(225, 29, 72, 0.3);
    }
    .sidebar .nav-item i { width: 18px; text-align: center; }

    .sidebar .sidebar-footer {
        border-top: 1px solid var(--border-color);
        padding-top: 12px; margin-top: 8px;
    }

    /* MAIN CONTENT AREA */
    .main {
        flex: 1; margin-left: var(--sidebar-width);
        display: flex; flex-direction: column; min-width: 0; transition: var(--transition);
        justify-content: center; align-items: center;
    }
    .dashboard-shell { padding: 22px 24px 40px; flex: 1; width: 100%; max-width: 750px; margin: 0 auto; }

    /* EDIT PROFILE PAGE STYLING */
    .page-header-card {
        background: var(--bg-card); border: 1px solid var(--border-color);
        border-radius: 18px; padding: 20px 24px; margin-bottom: 20px;
        display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;
    }
    .page-header-card h2 {
        font-family: 'Space Grotesk', sans-serif; font-size: 20px; font-weight: 800; color: #fff; margin: 0;
        display: flex; align-items: center; gap: 10px;
    }
    .page-header-card h2 i { color: var(--red-hover); }
    .page-header-card p { font-size: 12px; color: var(--text-muted); margin: 4px 0 0; }

    .btn-rt-secondary {
        background: rgba(255, 255, 255, 0.05); color: #fff; border: 1px solid var(--border-color);
        padding: 8px 16px; border-radius: 10px; font-size: 12px; font-weight: 700; text-decoration: none;
        transition: var(--transition); display: inline-flex; align-items: center; gap: 8px; cursor: pointer;
    }
    .btn-rt-secondary:hover { background: rgba(255, 255, 255, 0.1); border-color: var(--red-accent); color: #fff; }

    .alert {
        display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: 12px;
        font-size: 13px; font-weight: 600; margin-bottom: 20px; border: 1px solid transparent;
    }
    .alert.success { background: rgba(52,211,153,0.15); color: #34d399; border-color: rgba(52,211,153,0.3); }
    .alert.danger { background: rgba(248,113,113,0.15); color: #f87171; border-color: rgba(248,113,113,0.3); }

    .card {
        background: var(--bg-card); border: 1px solid var(--border-color);
        border-radius: 18px; padding: 28px 32px;
    }

    .form-group { margin-bottom: 16px; }
    .form-group label {
        display: block; font-size: 11px; font-weight: 700; color: var(--text-sub); margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.3px;
    }
    .form-control {
        width: 100%; padding: 10px 14px; border: 1px solid var(--border-color);
        border-radius: 10px; background: rgba(17, 19, 24, 0.8);
        font-family: inherit; font-size: 13.5px; color: #fff;
        transition: var(--transition); outline: none;
    }
    .form-control:focus { border-color: var(--red-accent); box-shadow: 0 0 8px rgba(225, 29, 72, 0.2); background: var(--bg-body); }
    .form-control:disabled { opacity: 0.6; cursor: not-allowed; }

    .btn-rt-primary {
        background: var(--red-accent); color: #fff; border: none; padding: 10px 20px;
        border-radius: 10px; font-size: 13px; font-weight: 800; text-decoration: none;
        transition: var(--transition); display: inline-flex; align-items: center; gap: 8px; cursor: pointer; margin-top: 6px;
    }
    .btn-rt-primary:hover { background: var(--red-hover); color: #fff; }

    .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); z-index: 999; }

    @media (max-width: 992px) {
        .sidebar { transform: translateX(-100%); }
        body.sidebar-open .sidebar { transform: translateX(0); }
        body.sidebar-open .sidebar-overlay { display: block; }
        .main { margin-left: 0; }
    }
</style>
</head>
<body>

<!-- TOP OFFICIAL BAR -->
<div class="top-official-bar">
    <div class="topbar-left">
        <i class="fa-solid fa-landmark"></i>
        <strong>REPUBLIC OF THE PHILIPPINES</strong>
        <span class="divider"></span>
        <span>Local Government Unit Property &amp; Rental Compliance Portal</span>
    </div>
    <div class="topbar-right">
        <span class="cert-badge"><i class="fa-solid fa-certificate"></i> LGU CERTIFIED PORTAL V2.1</span>
    </div>
</div>

<!-- MAIN HEADER NAVBAR -->
<header class="rt-topbar">
    <div class="rt-topbar-left">
        <button class="sidebar-toggle-btn" id="sidebarToggle" title="Toggle Navigation Menu">
            <i class="fa-solid fa-bars"></i>
        </button>
        <div class="page-title">
            Edit Profile <span>&middot; Update account credentials</span>
        </div>
    </div>

    <!-- LIVE SEARCH BAR -->
    <div class="rt-topbar-center">
        <div class="rt-search-container">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" id="globalNavSearch" placeholder="Search tracking no, rental name, owner..." value="<?php echo h($searchQuery); ?>" autocomplete="off">
        </div>
    </div>

    <div class="rt-topbar-right">
        <!-- NOTIFICATIONS -->
        <div style="position:relative;">
            <button class="icon-btn" id="notifBtn" title="Notifications">
                <i class="fa-regular fa-bell"></i>
                <?php if ($unreadNotifCount > 0): ?>
                    <span class="notif-badge-dot" id="notifBadgeDot"></span>
                <?php endif; ?>
            </button>

            <div id="notifDropdown" style="display:none; position:absolute; top:46px; right:0; width:300px; background:var(--bg-card); border:1px solid var(--border-color); border-radius:14px; padding:12px; box-shadow:0 10px 30px rgba(0,0,0,0.6); z-index:999;">
                <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); padding-bottom:8px; margin-bottom:8px;">
                    <span style="font-weight:700; color:#fff; font-size:12px;">System Notifications</span>
                    <span style="font-size:10px; color:var(--red-hover); cursor:pointer;" id="clearNotifBadge">Mark all read</span>
                </div>
                <div id="notifListContainer">
                    <?php if (!empty($notifications)): ?>
                        <?php foreach($notifications as $n): ?>
                            <a href="<?php echo h($n['target_url'] ?: 'javascript:void(0)'); ?>" class="d-block text-decoration-none" style="padding:8px 6px; border-bottom:1px solid rgba(255,255,255,0.04); border-radius:6px; transition:background 0.2s;" onmouseover="this.style.background='rgba(225,29,72,0.1)'" onmouseout="this.style.background='transparent'">
                                <div style="font-size:11.5px; font-weight:700; color:#fff;"><?php echo h($n['title']); ?></div>
                                <div style="font-size:10.5px; color:var(--text-muted); margin-top:2px;"><?php echo h($n['message']); ?></div>
                                <div style="font-size:9px; color:var(--red-soft); margin-top:4px;"><?php echo date('M d, h:i A', strtotime($n['created_at'])); ?></div>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="font-size:11px; color:var(--text-muted); text-align:center; padding:14px 0;">No new notifications</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- PROFILE DROPDOWN -->
        <div class="rt-profile-wrapper">
            <div class="rt-profile-btn" id="profileToggle">
                <div class="avatar"><?php echo $adminInitial; ?></div>
                <div class="info d-none d-sm-flex">
                    <span class="name"><?php echo h($adminName); ?></span>
                    <span class="role"><?php echo h($adminRole); ?></span>
                </div>
                <i class="fa-solid fa-chevron-down" style="font-size:10px; color:var(--text-sub);"></i>
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

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- MAIN WRAPPER & SIDEBAR -->
<div class="wrapper">

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <div class="animated-logo-avatar">
                <span>RL</span>
            </div>
            <div>
                RENTRACK <span style="color:var(--red-soft);">LGU</span>
            </div>
        </div>

        <div class="nav-section">
            <div class="nav-label">Main</div>
            <a href="dashboard.php" class="nav-item"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
            <a href="rentals.php" class="nav-item"><i class="fa-solid fa-building"></i> Rentals</a>
            <a href="users.php" class="nav-item"><i class="fa-solid fa-users"></i> Users</a>

            <div class="nav-label">Operations</div>
            <a href="payments.php" class="nav-item"><i class="fa-solid fa-cash-register"></i> Payments</a>
            <a href="inspections.php" class="nav-item"><i class="fa-solid fa-clipboard-check"></i> Inspections</a>
            <a href="compliance.php" class="nav-item"><i class="fa-solid fa-file-shield"></i> Compliance</a>
            <a href="collection.php" class="nav-item"><i class="fa-solid fa-wallet"></i> Collections</a>

            <div class="nav-label">Reports &amp; System</div>
            <a href="reports.php" class="nav-item"><i class="fa-solid fa-chart-simple"></i> Reports</a>
            <a href="settings.php" class="nav-item"><i class="fa-solid fa-gear"></i> Settings</a>
        </div>

        <div class="sidebar-footer">
            <a href="javascript:void(0)" class="nav-item" id="sidebarLogoutBtn"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
        </div>
    </aside>

    <!-- MAIN CONTENT AREA -->
    <div class="main">
        <div class="dashboard-shell">

            <div class="page-header-card">
                <div>
                    <h2><i class="fa-solid fa-pen-to-square"></i> Edit Profile</h2>
                    <p>Update your personal information and contact details.</p>
                </div>
                <a href="profile.php" class="btn-rt-secondary">
                    <i class="fa-solid fa-arrow-left"></i> Back to Profile
                </a>
            </div>

            <?php if (!empty($successMessage)) { ?>
                <div class="alert success"><i class="fa-solid fa-circle-check"></i> <?php echo h($successMessage); ?></div>
            <?php } ?>

            <?php if (!empty($errorMessage)) { ?>
                <div class="alert danger"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo h($errorMessage); ?></div>
            <?php } ?>

            <div class="card">
                <form method="POST">
                    <div class="form-group">
                        <label for="fullname">Full Name</label>
                        <input type="text" id="fullname" name="fullname" class="form-control"
                               value="<?php echo h($userData['fullname'] ?? $adminName); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" class="form-control"
                               value="<?php echo h($userData['email'] ?? $adminEmail); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="username">Username (Read-Only)</label>
                        <input type="text" id="username" class="form-control"
                               value="<?php echo h($userData['username'] ?? 'N/A'); ?>" disabled>
                    </div>

                    <button type="submit" class="btn-rt-primary">
                        <i class="fa-solid fa-floppy-disk"></i> Save Changes
                    </button>
                </form>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    // ===== SIDEBAR TOGGLE =====
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', () => {
            if (window.innerWidth <= 992) {
                document.body.classList.toggle('sidebar-open');
            } else {
                document.body.classList.toggle('sidebar-collapsed');
            }
        });
    }

    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', () => {
            document.body.classList.remove('sidebar-open');
        });
    }

    // ===== NOTIFICATION BELL WITH AUTO-MARK READ =====
    const notifBtn = document.getElementById('notifBtn');
    const notifDropdown = document.getElementById('notifDropdown');
    const notifBadgeDot = document.getElementById('notifBadgeDot');

    function clearNotificationDot() {
        if (notifBadgeDot) {
            notifBadgeDot.style.opacity = '0';
            setTimeout(() => { notifBadgeDot.style.display = 'none'; }, 300);
        }
        fetch('edit_profile.php?action=mark_notif_read');
    }

    if (notifBtn) {
        notifBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            const isOpen = notifDropdown.style.display === 'block';
            notifDropdown.style.display = isOpen ? 'none' : 'block';
            if (!isOpen) clearNotificationDot();
        });
    }

    document.getElementById('clearNotifBadge')?.addEventListener('click', clearNotificationDot);

    // ===== TOPBAR GLOBAL SEARCH REDIRECT =====
    const globalNavSearch = document.getElementById('globalNavSearch');
    if (globalNavSearch) {
        globalNavSearch.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                const val = globalNavSearch.value.trim();
                if (val.length > 0) {
                    window.location.href = `rentals.php?search=${encodeURIComponent(val)}`;
                }
            }
        });
    }

    // ===== PROFILE DROPDOWN =====
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
        if (notifDropdown) notifDropdown.style.display = 'none';
    });

    // ===== LOGOUT PROMPT =====
    function confirmLogout(e) {
        e.preventDefault();
        Swal.fire({
            title: 'Logout from Admin Portal?',
            text: 'Are you sure you want to log out?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e11d48',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, Logout',
            background: '#181c24',
            color: '#fff'
        }).then((res) => {
            if (res.isConfirmed) window.location.href = '../logout.php';
        });
    }

    document.getElementById('profileLogoutBtn')?.addEventListener('click', confirmLogout);
    document.getElementById('sidebarLogoutBtn')?.addEventListener('click', confirmLogout);
</script>
</body>
</html>