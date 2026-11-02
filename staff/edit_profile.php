<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();

require_once '../config/db.php';
require_once 'includes/auth.php';

// ==========================
// AJAX REQUEST HANDLERS (Single File)
// ==========================
if (isset($_GET['ajax_action'])) {
    $staffId = $_SESSION['user_id'] ?? 0;

    if ($_GET['ajax_action'] === 'search') {
        $query = trim($_GET['q'] ?? '');
        if (strlen($query) >= 2) {
            $searchTerm = '%' . $query . '%';
            $sql = "
                SELECT r.id, r.tracking_no, r.business_name, r.status, u.fullname AS owner_name
                FROM rentals r
                LEFT JOIN users u ON r.user_id = u.id
                WHERE (r.tracking_no ILIKE ? OR r.business_name ILIKE ? OR u.fullname ILIKE ?)
                  AND r.is_deleted = FALSE
                LIMIT 5
            ";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($results)) {
                echo '<div style="display: flex; flex-direction: column; gap: 8px;">';
                foreach ($results as $row) {
                    $status = $row['status'] ?? 'Draft';
                    $badgeBg = '#f3f4f6';
                    $badgeColor = '#4b5563';
                    if ($status === 'Pending') { $badgeBg = '#fef3c7'; $badgeColor = '#d97706'; }
                    elseif ($status === 'Approved') { $badgeBg = '#dcfce7'; $badgeColor = '#15803d'; }
                    elseif ($status === 'Rejected') { $badgeBg = '#fee2e2'; $badgeColor = '#b91c1c'; }

                    echo '<a href="view_rental.php?id=' . $row['id'] . '" style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 12px; color: #111827; text-decoration: none; transition: all 0.2s ease;" onmouseover="this.style.background=\'#fff1f2\'; this.style.borderColor=\'#dc2626\';" onmouseout="this.style.background=\'#f9fafb\'; this.style.borderColor=\'#e5e7eb\';">';
                    echo '<div style="display: flex; flex-direction: column; gap: 4px;">';
                    echo '<div style="font-weight: 600; font-size: 14px; color: #dc2626;">' . htmlspecialchars($row['tracking_no']) . ' <span style="color: #9ca3af; font-weight: 400;">•</span> ' . htmlspecialchars($row['business_name']) . '</div>';
                    echo '<div style="font-size: 12px; color: #4b5563;"><i class="fa-solid fa-user-shield" style="font-size: 11px; margin-right: 4px;"></i> Owner: ' . htmlspecialchars($row['owner_name'] ?? 'N/A') . '</div>';
                    echo '</div>';
                    echo '<span style="padding: 4px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; background: ' . $badgeBg . '; color: ' . $badgeColor . '; text-transform: uppercase;">' . htmlspecialchars($status) . '</span>';
                    echo '</a>';
                }
                echo '</div>';
            } else {
                echo '<div style="text-align: center; padding: 30px; color: #6b7280; font-size: 13px;"><i class="fa-solid fa-folder-open" style="font-size: 24px; margin-bottom: 8px; display: block; opacity: 0.5;"></i> No matching results found.</div>';
            }
        }
        exit;
    }
}

function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
$csrfToken = generateCsrfToken();

// ==========================
// STAFF INFORMATION
// ==========================
$staffName = $_SESSION['fullname'] ?? "Staff";
$staffRole = $_SESSION['role'] ?? "Staff";
$staffId = $_SESSION['user_id'] ?? 0;
$staffInitial = strtoupper(substr(trim($staffName), 0, 1));

// ==========================
// HANDLE PROFILE & IMAGE UPDATE
// ==========================
$successMsg = '';
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        !isset($_POST['csrf_token']) ||
        !isset($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $errorMsg = 'Invalid session token. Please refresh the page and try again.';
    } else {
        $fullname      = trim($_POST['fullname'] ?? '');
        $email         = trim($_POST['email'] ?? '');
        $contact       = trim($_POST['contact'] ?? '');
        $removePicture = isset($_POST['remove_picture']) && $_POST['remove_picture'] == '1';

        if (empty($fullname) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMsg = 'Please provide a valid name and email address.';
        } else {
            $emailCheck = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
            $emailCheck->execute([$email, $staffId]);
            $emailTaken = $emailCheck->fetch(PDO::FETCH_ASSOC) ? true : false;

            if ($emailTaken) {
                $errorMsg = 'That email address is already used by another account.';
            } else {
                $profilePicturePath = null;
                $isRemovingPic = false;

                if ($removePicture) {
                    $oldPicQuery = $conn->prepare("SELECT profile_picture FROM users WHERE id = ? LIMIT 1");
                    $oldPicQuery->execute([$staffId]);
                    if ($rowPic = $oldPicQuery->fetch(PDO::FETCH_ASSOC)) {
                        if (!empty($rowPic['profile_picture']) && file_exists(__DIR__ . '/' . $rowPic['profile_picture'])) {
                            @unlink(__DIR__ . '/' . $rowPic['profile_picture']);
                        }
                    }
                    $isRemovingPic = true;
                }
                elseif (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                    $fileTmpPath = $_FILES['profile_picture']['tmp_name'];
                    $fileName = $_FILES['profile_picture']['name'];
                    $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    
                    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
                    if (in_array($fileExtension, $allowedExtensions, true) && upload_content_ok($fileTmpPath, $fileExtension)) {
                        $uploadFileDir = __DIR__ . '/uploads/profiles/';
                        if (!is_dir($uploadFileDir)) {
                            mkdir($uploadFileDir, 0755, true);
                        }

                        $newFileName = 'user_' . $staffId . '_' . bin2hex(random_bytes(6)) . '.' . $fileExtension;
                        $dest_path = $uploadFileDir . $newFileName;

                        if (move_uploaded_file($fileTmpPath, $dest_path)) {
                            $profilePicturePath = 'uploads/profiles/' . $newFileName;
                        } else {
                            $errorMsg = 'Error moving the uploaded file.';
                        }
                    } else {
                        $errorMsg = 'Invalid file type. Only JPG, JPEG, PNG, and WEBP are allowed.';
                    }
                }

                if (empty($errorMsg)) {
                    if ($isRemovingPic) {
                        $updateStmt = $conn->prepare("UPDATE users SET fullname = ?, email = ?, contact = ?, profile_picture = NULL WHERE id = ?");
                        $updateSuccess = $updateStmt->execute([$fullname, $email, $contact, $staffId]);
                    } elseif ($profilePicturePath) {
                        $updateStmt = $conn->prepare("UPDATE users SET fullname = ?, email = ?, contact = ?, profile_picture = ? WHERE id = ?");
                        $updateSuccess = $updateStmt->execute([$fullname, $email, $contact, $profilePicturePath, $staffId]);
                    } else {
                        $updateStmt = $conn->prepare("UPDATE users SET fullname = ?, email = ?, contact = ? WHERE id = ?");
                        $updateSuccess = $updateStmt->execute([$fullname, $email, $contact, $staffId]);
                    }

                    if ($updateSuccess) {
                        $successMsg = 'Profile successfully updated.';
                        $_SESSION['fullname'] = $fullname;
                        $staffName = $fullname;
                        $staffInitial = strtoupper(substr(trim($staffName), 0, 1));
                    } else {
                        $errorMsg = 'Failed to update profile. Please try again.';
                    }
                }
            }
        }
    }
}

// ==========================
// NOTIFICATION COUNT & LIST
// ==========================
$notificationCount = 0;
$notifStmt = $conn->prepare("SELECT COUNT(*) AS total FROM notifications WHERE (user_id = ? OR user_id = '0' OR user_id IS NULL) AND is_read = FALSE");
$notifStmt->execute([$staffId]);
if ($row = $notifStmt->fetch(PDO::FETCH_ASSOC)) {
    $notificationCount = (int) $row['total'];
}

$notifications = [];
$notifListStmt = $conn->prepare("
    SELECT id, title, message, created_at, is_read 
    FROM notifications 
    WHERE (user_id = ? OR user_id = '0' OR user_id IS NULL) 
    ORDER BY created_at DESC 
    LIMIT 5
");
$notifListStmt->execute([$staffId]);
$notifications = $notifListStmt->fetchAll(PDO::FETCH_ASSOC);

// ==========================
// GET STAFF INFORMATION
// ==========================
$user_id = $_SESSION['user_id'];

$userStmt = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$userStmt->execute([$user_id]);

if ($userStmt->rowCount() == 0) {
    header("Location: dashboard.php");
    exit();
}

$user = $userStmt->fetch(PDO::FETCH_ASSOC);

$pendingRenewalsCount = 0;
try {
    $checkTable = $conn->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ?");
    $checkTable->execute(['permit_renewals']);
    $tableExists = $checkTable->fetchColumn() > 0;

    if ($tableExists) {
        $pendRenewalsQuery = $conn->query("SELECT COUNT(*) AS total FROM permit_renewals WHERE status::text = 'Pending'");
        if ($pendRenewalsQuery) {
            $row = $pendRenewalsQuery->fetch(PDO::FETCH_ASSOC);
            $pendingRenewalsCount = (int) ($row['total'] ?? 0);
        }
    }
} catch (Exception $e) {
    $pendingRenewalsCount = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile | RENTRACK LGU</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            /* ✅ RED THEME — matches admin */
            --bg-primary: #f8fafc;
            --bg-card: #ffffff;
            --border-glass: #e2e8f0;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #94a3b8;
            
            --primary-red: #dc2626;
            --primary-red-dark: #991b1b;
            --primary-red-light: #fecdd3;
            --red-soft: #fee2e2;
            --accent-cyan: #0d9488;
            
            --sidebar-bg: #dc2626;
            --sidebar-text: #ffffff;
            
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px -1px rgba(0, 0, 0, 0.1);
            --radius: 20px;
            --radius-sm: 12px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --sidebar-width: 260px;
            --sidebar-collapsed: 72px;
        }

        @keyframes borderSpin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .animated-logo-wrapper {
            position: relative;
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .animated-logo-ring {
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            border-radius: 50%;
            border: 2px dashed rgba(255, 255, 255, 0.6);
            animation: borderSpin 4s linear infinite;
        }

        .animated-logo-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-red);
            font-size: 16px;
            font-weight: 800;
            z-index: 2;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
        }

        .animated-logo-text {
            color: #ffffff;
        }

        html, body {
            margin: 0 !important;
            padding: 0 !important;
            border: 0 !important;
            height: 100%;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            overflow-x: hidden;
        }

        .page-container {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            width: 100vw;
            margin: 0 !important;
            padding: 0 !important;
        }

        /* ============================================
           OFFICIAL HEADER — RED GRADIENT
        ============================================ */
        .official-header {
            width: 100%;
            display: flex; 
            align-items: center; 
            justify-content: space-between;
            background: linear-gradient(90deg, #991b1b, #dc2626 55%, #991b1b);
            border-bottom: 2px solid #7f1d1d; 
            padding: 6px 20px; 
            gap: 20px;
            position: relative; 
            z-index: 110;
            margin: 0 !important;
            box-shadow: 0 2px 8px -2px rgba(220, 38, 38, 0.45);
        }

        .official-header-left, .official-header-right-group {
            display: flex; 
            align-items: center; 
            gap: 12px; 
            color: #fecdd3; 
            font-size: 11px; 
            font-weight: 600;
        }

        .official-header-badge { 
            background: rgba(255, 255, 255, 0.15); 
            border: 1px solid rgba(255, 255, 255, 0.3); 
            padding: 2px 10px; 
            border-radius: 20px; 
            display: flex; 
            align-items: center; 
            gap: 6px; 
            color: #ffffff;
        }

        .app-body {
            display: flex;
            flex: 1;
            width: 100%;
            position: relative;
            margin: 0 !important;
            padding: 0 !important;
        }

        /* ============================================
           SIDEBAR — RED SOLID
        ============================================ */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--sidebar-bg);
            border-right: none;
            box-shadow: 4px 0 22px -8px rgba(220, 38, 38, 0.45);
            display: flex;
            flex-direction: column;
            padding: 0 12px;
            flex-shrink: 0;
            height: 100vh;
            position: sticky;
            top: 0;
            transition: width 0.3s ease, transform 0.3s ease;
            overflow: hidden;
            z-index: 100;
        }

        body.sidebar-collapsed .sidebar {
            width: var(--sidebar-collapsed);
        }

        .sidebar-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 8px 16px 8px;
            border-bottom: 1px solid rgba(255,255,255,0.2);
            margin-bottom: 16px;
        }

        .sidebar-logo .logo-text {
            white-space: nowrap;
            transition: opacity 0.2s ease;
        }
        .sidebar-logo .logo-text h2 {
            font-size: 16px;
            font-weight: 800;
            color: #ffffff;
            line-height: 1.2;
            margin: 0;
        }
        .sidebar-logo .logo-text span {
            font-size: 9px;
            color: var(--primary-red-light);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }

        body.sidebar-collapsed .sidebar-logo .logo-text {
            opacity: 0;
            width: 0;
        }
        body.sidebar-collapsed .sidebar-logo {
            justify-content: center;
            padding-top: 16px;
        }

        .sidebar-menu {
            list-style: none;
            flex: 1;
            overflow-y: auto;
            padding: 4px 0;
        }
        .sidebar-menu::-webkit-scrollbar {
            width: 3px;
        }
        .sidebar-menu::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.4);
            border-radius: 999px;
        }

        .menu-group-label {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            color: rgba(255, 255, 255, 0.6);
            padding: 12px 14px 6px 14px;
            font-weight: 700;
        }

        body.sidebar-collapsed .menu-group-label {
            opacity: 0;
            height: 0;
            padding: 0;
            margin: 0;
            overflow: hidden;
        }

        .sidebar-menu li {
            margin-bottom: 4px;
        }
        .sidebar-menu li a {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 10px 14px;
            border-radius: 12px;
            color: rgba(255, 255, 255, 0.85);
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: var(--transition);
            white-space: nowrap;
            overflow: hidden;
        }
        .sidebar-menu li a i {
            width: 20px;
            font-size: 16px;
            text-align: center;
            flex-shrink: 0;
            color: rgba(255, 255, 255, 0.7);
        }
        .sidebar-menu li a:hover {
            background: rgba(0, 0, 0, 0.15);
            color: #ffffff;
        }
        
        /* ACTIVE MENU — WHITE BG + RED TEXT */
        .sidebar-menu li.active a { 
            background: #ffffff !important; 
            color: var(--primary-red) !important; 
            font-weight: 700;
            border: none;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        .sidebar-menu li.active a i { 
            color: var(--primary-red) !important; 
            background: transparent !important;
        }
        .sidebar-menu li.active a span {
            color: var(--primary-red) !important;
        }

        .sidebar-menu li a .menu-badge {
            margin-left: auto;
            background: #fbbf24;
            color: #7c2d12;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 999px;
            flex-shrink: 0;
            box-shadow: 0 0 8px rgba(251, 191, 36, 0.75);
        }

        body.sidebar-collapsed .sidebar-menu li a span {
            opacity: 0;
            width: 0;
            overflow: hidden;
        }
        body.sidebar-collapsed .sidebar-menu li a {
            justify-content: center;
            padding: 10px;
        }
        body.sidebar-collapsed .sidebar-menu li a .menu-badge {
            display: none;
        }

        .sidebar-footer {
            padding: 12px 0 16px 0;
            border-top: 1px solid rgba(255,255,255,0.2);
            margin-top: 4px;
        }
        .sidebar-footer a {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 12px;
            border-radius: 12px;
            color: #ffffff;
            text-decoration: none;
            cursor: pointer;
            transition: var(--transition);
            background: rgba(255, 255, 255, 0.12);
            border: 1.5px solid rgba(255, 255, 255, 0.85);
        }
        .sidebar-footer a i { font-size: 18px; text-align: center; }
        .sidebar-footer a:hover { background: #ffffff; color: var(--primary-red); border-color: #ffffff; }

        body.sidebar-collapsed .sidebar-footer a span {
            opacity: 0;
            width: 0;
            overflow: hidden;
        }

        /* ============================================
           MAIN WRAPPER — MATCHES ADMIN
        ============================================ */
        .main-wrapper {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            overflow: hidden;
            margin: 0 !important;
            padding: 0 !important;
            background: #f8fafc;
        }

        .top-navbar {
            position: sticky;
            top: 0;
            z-index: 50;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 28px;
            background: #ffffff;
            border-bottom: 1px solid #e2e8f0;
            margin: 0 !important;
            flex-wrap: wrap;
            gap: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }

        .navbar-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .menu-toggle {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            color: var(--primary-red);
            cursor: pointer;
        }
        .menu-toggle:hover { background: var(--primary-red); color: #fff; border-color: var(--primary-red); }

        .page-title {
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
        }
        .page-title small {
            font-size: 12px;
            font-weight: 400;
            color: #64748b;
            margin-left: 8px;
        }

        .search-wrapper {
            flex: 1;
            display: flex;
            justify-content: center;
            padding: 0 20px;
            max-width: 700px;
            margin: 0 auto;
            min-width: 250px;
        }

        .search-container {
            position: relative;
            width: 100%;
            max-width: 500px;
        }

        .search-container i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--primary-red);
            z-index: 2;
        }

        .search-container input {
            width: 100%;
            padding: 10px 16px 10px 42px;
            border-radius: 999px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            color: #0f172a;
            font-size: 14px;
            outline: none;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }
        .search-container input::placeholder { color: #94a3b8; }
        .search-container:hover input { border-color: var(--primary-red); background: var(--red-soft); }

        .navbar-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .nav-clock {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            padding: 4px 16px;
            border-radius: 10px;
            background: var(--red-soft);
            border: 1px solid var(--primary-red-light);
        }
        .nav-clock .time {
            font-family: monospace;
            font-size: 14px;
            font-weight: 700;
            color: var(--primary-red);
        }
        .nav-clock .date {
            font-size: 10px;
            color: #64748b;
        }

        .nav-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            color: var(--primary-red);
            cursor: pointer;
            position: relative;
            text-decoration: none;
        }
        .nav-icon:hover { background: #f8fafc; }
        .nav-icon .badge {
            position: absolute;
            top: -4px;
            right: -4px;
            background: #ef4444;
            color: #fff;
            font-size: 9px;
            font-weight: 700;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #ffffff;
        }

        .notification-dropdown {
            position: absolute;
            top: calc(100% + 12px);
            right: 0;
            width: 360px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 0;
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px) scale(0.95);
            transition: all 0.25s ease;
            z-index: 60;
            overflow: hidden;
        }
        .notification-dropdown.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0) scale(1);
        }
        .notification-title {
            padding: 14px 20px;
            font-weight: 600;
            font-size: 14px;
            border-bottom: 1px solid #f1f5f9;
            color: #0f172a;
        }
        .notification-item {
            padding: 12px 20px;
            border-bottom: 1px solid #f1f5f9;
        }
        .notification-item strong { display: block; font-size: 13px; margin-bottom: 2px; color: var(--primary-red); }
        .notification-item p { font-size: 12px; color: #475569; margin: 0; }
        .notification-item small { display: block; font-size: 10px; color: #94a3b8; margin-top: 4px; }

        .profile-wrapper {
            position: relative;
        }

        .profile-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 4px 14px 4px 4px;
            border-radius: 12px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            cursor: pointer;
            text-decoration: none;
        }
        .profile-btn:hover { background: #f8fafc; }
        .profile-btn .avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: var(--primary-red);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-weight: 800;
            font-size: 13px;
            overflow: hidden;
            flex-shrink: 0;
        }
        .profile-btn .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .profile-btn .info .name {
            font-size: 12px;
            font-weight: 600;
            color: #0f172a;
        }
        .profile-btn .info .role {
            font-size: 9px;
            color: var(--primary-red);
            font-weight: 700;
            text-transform: uppercase;
        }

        .profile-dropdown {
            position: absolute;
            top: calc(100% + 12px);
            right: 0;
            width: 240px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 8px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px) scale(0.95);
            transition: all 0.25s ease;
            z-index: 60;
        }
        .profile-dropdown.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0) scale(1);
        }

        .profile-dropdown .dropdown-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border-radius: 10px;
            color: #334155;
            text-decoration: none;
            font-size: 13px;
        }
        .profile-dropdown .dropdown-item:hover {
            background: var(--red-soft);
            color: var(--primary-red);
        }
        .profile-dropdown .dropdown-divider {
            height: 1px;
            background: #f1f5f9;
            margin: 6px 8px;
        }
        .profile-dropdown .dropdown-item.danger {
            color: #dc2626;
        }

        /* ============================================
           CONTENT AREA — MATCHES ADMIN
        ============================================ */
        .content {
            flex: 1;
            padding: 28px 32px 20px;
            overflow-y: auto;
            background: #f8fafc;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #64748b;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .breadcrumb a {
            color: #64748b;
            text-decoration: none;
        }
        .breadcrumb a:hover { color: var(--primary-red); }
        .breadcrumb strong {
            color: #0f172a;
        }

        /* ============================================
           PAGE HEADER — LIGHT PINK (matches admin welcome banner)
        ============================================ */
        .page-header.glass-card { 
            background: #fff1f2; 
            border: 1px solid #fecdd3; 
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05); 
            border-radius: var(--radius);
            padding: 20px 22px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 28px;
            position: relative;
            overflow: hidden;
        }
        .page-header.glass-card::after {
            content: '';
            position: absolute;
            width: 180px; height: 180px; border-radius: 50%;
            background: rgba(225, 29, 72, 0.05);
            bottom: -60px; right: -40px; pointer-events: none;
        }
        .page-header.glass-card h1 { color: #9f1239 !important; font-size: 26px; font-weight: 700; margin: 0; position: relative; z-index: 1; }
        .page-header.glass-card h1 i { color: var(--primary-red) !important; margin-right: 10px; }
        .page-header.glass-card p { color: #334155 !important; font-size: 14px; margin-top: 4px; position: relative; z-index: 1; }
        .page-header.glass-card .date-badge { 
            color: var(--primary-red) !important; 
            background: #ffffff !important; 
            border: 1px solid #fecdd3 !important; 
            border-radius: 40px;
            padding: 8px 18px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            position: relative;
            z-index: 1;
        }
        .page-header.glass-card .date-badge i { color: var(--primary-red) !important; }

        /* PROFILE CARD */
        .profile-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: var(--radius);
            box-shadow: 0 1px 3px 0 rgba(0,0,0,0.1), 0 1px 2px -1px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-bottom: 24px;
        }

        .profile-header {
            text-align: center;
            padding: 32px 24px 24px;
            border-bottom: 1px solid #f1f5f9;
        }

        .profile-avatar {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: var(--red-soft);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 36px;
            color: var(--primary-red);
            border: 2px solid var(--primary-red-light);
            overflow: hidden;
        }
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-header h2 {
            font-size: 22px;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
        }

        .profile-header .role-badge {
            display: inline-block;
            padding: 4px 16px;
            border-radius: 40px;
            background: var(--red-soft);
            border: 1px solid var(--primary-red-light);
            color: var(--primary-red);
            font-size: 13px;
            font-weight: 600;
            margin-top: 6px;
        }

        .profile-body {
            padding: 24px;
        }

        .profile-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }
        @media (max-width: 768px) {
            .profile-grid { grid-template-columns: 1fr; }
        }

        .info-box {
            background: #f8fafc;
            border-radius: 12px;
            padding: 14px 16px;
            border: 1px solid #e2e8f0;
        }

        .info-box label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 11px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }

        .info-box label i {
            color: var(--primary-red);
            font-size: 12px;
        }

        .info-box p {
            font-size: 15px;
            font-weight: 500;
            color: #0f172a;
            padding-left: 24px;
            margin: 0;
        }

        .info-box input[type="text"],
        .info-box input[type="email"],
        .info-box input[type="file"] {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: #ffffff;
            color: #0f172a;
            font-size: 14px;
            outline: none;
            transition: all 0.2s;
        }
        .info-box input:focus {
            border-color: var(--primary-red);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }

        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 40px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-success { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .badge-danger { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
        /* ✅ Role badge — RED */
        .badge-primary { background: var(--red-soft); color: var(--primary-red); border: 1px solid var(--primary-red-light); }

        .profile-actions {
            display: flex;
            gap: 12px;
            padding-top: 16px;
            border-top: 1px solid #f1f5f9;
            flex-wrap: wrap;
        }

        /* ✅ Save button — RED */
        .btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 22px;
            border-radius: 12px;
            background: var(--primary-red);
            color: #fff;
            font-weight: 600;
            font-size: 14px;
            text-decoration: none;
            transition: var(--transition);
            border: none;
            cursor: pointer;
        }
        .btn-primary:hover {
            background: var(--primary-red-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.2);
        }

        .btn-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 12px;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            color: #334155;
            font-weight: 500;
            font-size: 14px;
            text-decoration: none;
            transition: var(--transition);
        }
        .btn-secondary:hover { background: #e2e8f0; color: #0f172a; }

        .btn-back {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 12px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            color: #64748b;
            font-weight: 500;
            font-size: 14px;
            text-decoration: none;
            transition: var(--transition);
            margin-left: auto;
        }
        .btn-back:hover { background: #f1f5f9; color: #0f172a; }

        .footer {
            margin-top: 24px;
            padding-top: 16px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            color: #64748b;
            flex-wrap: wrap;
            gap: 10px;
        }
        .footer .brand { color: var(--primary-red); font-weight: 700; }
    </style>
</head>
<body>

    <form id="logoutForm" action="../logout.php" method="POST" style="display:none;"></form>

    <div class="page-container">
        <!-- RED OFFICIAL HEADER -->
        <div class="official-header">
            <div class="official-header-left">
                <i class="fa-solid fa-landmark"></i>
                <span>REPUBLIC OF THE PHILIPPINES</span>
                <span style="opacity: 0.7;">|</span>
                <span style="font-weight: 400; opacity: 0.9;">Local Government Unit Property &amp; Rental Compliance Portal</span>
            </div>
            <div class="official-header-right-group">
                <div class="official-header-badge">
                    <i class="fa-solid fa-certificate"></i>
                    <span>LGU CERTIFIED PORTAL V2.1</span>
                </div>
                <span>City Treasurer &amp; Rentrack Online System</span>
            </div>
        </div>

        <div class="app-body">
            <!-- RED SIDEBAR -->
            <aside class="sidebar">
                <div class="sidebar-logo">
                    <div class="animated-logo-wrapper">
                        <div class="animated-logo-ring"></div>
                        <div class="animated-logo-circle">RT</div>
                    </div>
                    <div class="logo-text">
                        <h2 class="animated-logo-text">RENTRACK</h2>
                        <span>LGU Staff</span>
                    </div>
                </div>

                <ul class="sidebar-menu">
                    <li class="menu-group-label">Navigation</li>
                    <li><a href="dashboard.php"><i class="fa-solid fa-gauge-high"></i><span>Dashboard</span></a></li>

                    <li class="menu-group-label">Rental Management</li>
                    <li><a href="rentals.php"><i class="fa-solid fa-folder-open"></i><span>Rental Applications</span></a></li>
                    <li><a href="add_rental.php"><i class="fa-solid fa-plus-circle"></i><span>Register Rental</span></a></li>
                    <li><a href="documents.php"><i class="fa-solid fa-file-lines"></i><span>Documents</span></a></li>
                    <li>
                        <a href="renewals_approval.php">
                            <i class="fa-solid fa-file-circle-exclamation"></i><span>Permit Renewals</span>
                            <?php if ($pendingRenewalsCount > 0): ?>
                                <span class="menu-badge"><?php echo $pendingRenewalsCount; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li><a href="reports.php"><i class="fa-solid fa-file-invoice"></i><span>Reports &amp; Analytics</span></a></li>

                    <li class="menu-group-label">Inspection Management</li>
                    <li><a href="inspections.php"><i class="fa-solid fa-clipboard-check"></i><span>My Inspections</span></a></li>

                    <li class="menu-group-label">Financial Management</li>
                    <li><a href="collection.php"><i class="fa-solid fa-cash-register"></i><span>Collections</span></a></li>
                    <li><a href="payments.php"><i class="fa-solid fa-money-check-dollar"></i><span>Payments</span></a></li>

                    <li class="menu-group-label">Account &amp; Settings</li>
                    <li><a href="settings.php"><i class="fa-solid fa-gear"></i><span>Settings</span></a></li>
                    <li class="active"><a href="profile.php"><i class="fa-solid fa-user"></i><span>My Profile</span></a></li>
                </ul>

                <div class="sidebar-footer">
                    <a href="#" onclick="confirmLogout(); return false;" title="Logout">
                        <i class="fa-solid fa-right-from-bracket"></i><span>Logout</span>
                    </a>
                </div>
            </aside>

            <div class="main-wrapper">
                <header class="top-navbar">
                    <div class="navbar-left">
                        <button class="menu-toggle" id="menuToggle"><i class="fa-solid fa-bars"></i></button>
                        <div class="page-title">
                            Edit Profile
                            <small>Account Details</small>
                        </div>
                    </div>

                    <div class="search-wrapper">
                        <div class="search-container" data-bs-toggle="modal" data-bs-target="#searchModal" style="cursor: pointer;">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input type="text" id="globalSearchInput" placeholder="Search tracking no, business, owner..." autocomplete="off" readonly style="cursor: pointer;">
                        </div>
                    </div>

                    <div class="navbar-right">
                        <div class="nav-clock">
                            <div class="time" id="clockTime">12:00 PM</div>
                            <div class="date"><?php echo date("M d, Y"); ?></div>
                        </div>
                        
                        <div class="nav-icon-wrapper" style="position:relative;">
                            <button class="nav-icon" id="notifBtn">
                                <i class="fa-regular fa-bell"></i>
                                <?php if ($notificationCount > 0): ?>
                                    <span class="badge"><?php echo $notificationCount; ?></span>
                                <?php endif; ?>
                            </button>
                            <div id="notifDropdown" class="notification-dropdown">
                                <div class="notification-title">Recent Notifications</div>
                                <?php if (!empty($notifications)): ?>
                                    <?php foreach ($notifications as $n): ?>
                                        <div class="notification-item">
                                            <strong><?php echo htmlspecialchars($n['title'] ?? ''); ?></strong>
                                            <p><?php echo htmlspecialchars($n['message'] ?? ''); ?></p>
                                            <small><?php echo date("M d, Y h:i A", strtotime($n['created_at'] ?? 'now')); ?></small>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="notification-item" style="text-align:center;color:#64748b;padding:20px;">No notifications</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="profile-wrapper">
                            <div class="profile-btn" id="profileToggle">
                                <div class="avatar">
                                    <?php if (!empty($user['profile_picture']) && file_exists(__DIR__ . '/' . $user['profile_picture'])): ?>
                                        <img src="<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="Avatar">
                                    <?php else: ?>
                                        <?php echo $staffInitial; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="info">
                                    <div class="name"><?php echo htmlspecialchars($staffName); ?></div>
                                    <div class="role"><?php echo htmlspecialchars($staffRole); ?></div>
                                </div>
                                <i class="fa-solid fa-chevron-down chevron" style="color: #64748b; font-size: 10px;"></i>
                            </div>

                            <div class="profile-dropdown" id="profileDropdown">
                                <a href="profile.php" class="dropdown-item"><i class="fa-solid fa-user"></i> My Profile</a>
                                <a href="settings.php" class="dropdown-item"><i class="fa-solid fa-gear"></i> Settings</a>
                                <div class="dropdown-divider"></div>
                                <a href="#" class="dropdown-item danger" id="profileLogoutBtn"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
                            </div>
                        </div>
                    </div>
                </header>

                <main class="content">
                    <div class="breadcrumb">
                        <a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
                        <span><i class="fa-solid fa-angle-right"></i></span>
                        <a href="profile.php">My Profile</a>
                        <span><i class="fa-solid fa-angle-right"></i></span>
                        <strong>Edit Profile</strong>
                    </div>

                    <!-- LIGHT PINK HEADER CARD (matches admin welcome banner) -->
                    <div class="page-header glass-card">
                        <div>
                            <h1><i class="fa-solid fa-user-pen"></i> Edit Profile</h1>
                            <p>Update your personal account information and profile picture</p>
                        </div>
                        <div class="date-badge"><i class="fa-regular fa-calendar"></i> <?php echo date("F d, Y"); ?></div>
                    </div>

                    <?php if ($successMsg): ?>
                        <div style="background:#dcfce7; border:1px solid #bbf7d0; color:#15803d; padding:12px 16px; border-radius:10px; margin-bottom:18px; font-size:13.5px; font-weight:600;">
                            <i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($successMsg); ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($errorMsg): ?>
                        <div style="background:#fee2e2; border:1px solid #fecaca; color:#b91c1c; padding:12px 16px; border-radius:10px; margin-bottom:18px; font-size:13.5px; font-weight:600;">
                            <i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($errorMsg); ?>
                        </div>
                    <?php endif; ?>

                    <!-- PROFILE CARD -->
                    <div class="profile-card">
                        <div class="profile-header">
                            <div class="profile-avatar">
                                <?php if (!empty($user['profile_picture']) && file_exists(__DIR__ . '/' . $user['profile_picture'])): ?>
                                    <img src="<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="Profile Picture">
                                <?php else: ?>
                                    <i class="fa-solid fa-user-tie"></i>
                                <?php endif; ?>
                            </div>
                            <h2><?php echo htmlspecialchars($user['fullname']); ?></h2>
                            <span class="role-badge">
                                <i class="fa-solid fa-user-shield"></i> <?php echo ucfirst($user['role']); ?>
                            </span>
                        </div>

                        <div class="profile-body">
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                                <div class="profile-grid">
                                    <div class="info-box">
                                        <label><i class="fa-solid fa-image"></i> Update Profile Picture</label>
                                        <input type="file" name="profile_picture" accept="image/png, image/jpeg, image/webp">
                                        
                                        <?php if (!empty($user['profile_picture'])): ?>
                                            <div style="margin-top: 10px; display: flex; align-items: center; gap: 8px;">
                                                <input type="checkbox" id="remove_picture" name="remove_picture" value="1" style="cursor: pointer;">
                                                <label for="remove_picture" style="color: #dc2626; font-size: 12px; cursor: pointer; margin-bottom: 0; text-transform: none;">
                                                    <i class="fa-solid fa-trash-can"></i> Tanggalin ang kasalukuyang profile picture
                                                </label>
                                            </div>
                                        <?php endif; ?>

                                        <small style="color:#64748b; display:block; margin-top:4px;">Allowed formats: JPG, JPEG, PNG, WEBP</small>
                                    </div>

                                    <div class="info-box">
                                        <label><i class="fa-solid fa-user"></i> Full Name *</label>
                                        <input type="text" name="fullname" value="<?php echo htmlspecialchars($user['fullname']); ?>" required>
                                    </div>

                                    <div class="info-box">
                                        <label><i class="fa-solid fa-user-tag"></i> Username</label>
                                        <p><?php echo htmlspecialchars($user['username']); ?> <small style="color:#64748b;">(hindi na-e-edit)</small></p>
                                    </div>

                                    <div class="info-box">
                                        <label><i class="fa-solid fa-envelope"></i> Email Address *</label>
                                        <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                    </div>

                                    <div class="info-box">
                                        <label><i class="fa-solid fa-phone"></i> Contact Number</label>
                                        <input type="text" name="contact" value="<?php echo htmlspecialchars($user['contact'] ?? ''); ?>">
                                    </div>

                                    <div class="info-box">
                                        <label><i class="fa-solid fa-user-shield"></i> Account Role</label>
                                        <p><span class="badge badge-primary"><?php echo ucfirst($user['role']); ?></span></p>
                                    </div>

                                    <div class="info-box">
                                        <label><i class="fa-solid fa-circle-check"></i> Account Status</label>
                                        <p>
                                            <?php if ($user['status'] == "Active"): ?>
                                                <span class="badge badge-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger">Inactive</span>
                                            <?php endif; ?>
                                        </p>
                                    </div>

                                    <div class="info-box">
                                        <label><i class="fa-solid fa-calendar-plus"></i> Member Since</label>
                                        <p><?php echo date("F d, Y", strtotime($user['created_at'])); ?></p>
                                    </div>
                                </div>

                                <div class="profile-actions">
                                    <button type="submit" class="btn-primary">
                                        <i class="fa-solid fa-save"></i> Save Changes
                                    </button>
                                    <a href="change_password.php" class="btn-secondary">
                                        <i class="fa-solid fa-key"></i> Change Password
                                    </a>
                                    <a href="profile.php" class="btn-back">
                                        <i class="fa-solid fa-arrow-left"></i> Back to Profile
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <footer class="footer">
                        <span>&copy; <?php echo date('Y'); ?> <span class="brand">RENTRACK LGU</span></span>
                        <span>Unified Rental Registration, Tracking &amp; Compliance System</span>
                    </footer>
                </main>
            </div>
        </div>
    </div>

    <!-- SEARCH POPUP MODAL -->
    <div class="modal fade" id="searchModal" tabindex="-1" aria-labelledby="searchModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content" style="background: #ffffff; color: #0f172a; border-radius: 20px; box-shadow: 0 25px 60px rgba(0, 0, 0, 0.15); overflow: hidden;">
                <div class="modal-header border-bottom px-4 py-3">
                    <h5 class="modal-title fw-bold d-flex align-items-center gap-2" id="searchModalLabel" style="font-size: 15px; color: var(--primary-red);">
                        <i class="fa-solid fa-magnifying-glass" style="font-size: 14px;"></i> Global System Search
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div style="position: relative; margin-bottom: 20px;">
                        <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 18px; top: 50%; transform: translateY(-50%); color: var(--primary-red); font-size: 16px;"></i>
                        <input type="text" id="modalSearchInput" class="form-control" placeholder="Search by tracking number, business name, or owner..." autocomplete="off" style="width: 100%; padding: 14px 16px 14px 48px; border-radius: 14px; border: 1.5px solid #e2e8f0; background: #f8fafc; font-size: 14px; outline: none;">
                    </div>
                    <div id="modalSearchResults" style="max-height: 380px; overflow-y: auto; padding-right: 4px;">
                        <div style="text-align: center; padding: 40px 20px; color: #64748b; font-size: 13px;">
                            <i class="fa-regular fa-keyboard" style="font-size: 28px; margin-bottom: 10px; display: block; opacity: 0.6; color: var(--primary-red);"></i>
                            Type at least 2 characters to begin searching records...
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.body.classList.toggle('sidebar-collapsed');
        });

        setInterval(() => {
            const d = new Date();
            let h = d.getHours(), m = d.getMinutes(), ampm = h >= 12 ? 'PM' : 'AM';
            h = h % 12 || 12;
            document.getElementById('clockTime').textContent = `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')} ${ampm}`;
        }, 1000);

        const notifBtn = document.getElementById('notifBtn');
        const notifDropdown = document.getElementById('notifDropdown');
        
        notifBtn?.addEventListener('click', (e) => { 
            e.stopPropagation(); 
            notifDropdown.classList.toggle('show');
        });

        const profileToggle = document.getElementById('profileToggle');
        const profileDropdown = document.getElementById('profileDropdown');
        profileToggle?.addEventListener('click', (e) => { 
            e.stopPropagation(); 
            profileDropdown.classList.toggle('show'); 
        });

        window.addEventListener('click', () => {
            notifDropdown?.classList.remove('show');
            profileDropdown?.classList.remove('show');
        });

        const searchModalElement = document.getElementById('searchModal');
        const modalSearchInput = document.getElementById('modalSearchInput');
        const modalSearchResults = document.getElementById('modalSearchResults');

        if (searchModalElement) {
            searchModalElement.addEventListener('shown.bs.modal', function () {
                modalSearchInput.focus();
            });

            modalSearchInput.addEventListener('input', function() {
                let query = this.value.trim();
                if (query.length < 2) {
                    modalSearchResults.innerHTML = `
                        <div style="text-align: center; padding: 40px 20px; color: #64748b; font-size: 13px;">
                            <i class="fa-regular fa-keyboard" style="font-size: 28px; margin-bottom: 10px; display: block; opacity: 0.6; color: var(--primary-red);"></i>
                            Type at least 2 characters to begin searching records...
                        </div>
                    `;
                    return;
                }

                fetch('edit_profile.php?ajax_action=search&q=' + encodeURIComponent(query))
                    .then(res => res.text())
                    .then(html => {
                        if (html.trim() !== '') {
                            modalSearchResults.innerHTML = html;
                        } else {
                            modalSearchResults.innerHTML = `
                                <div style="text-align: center; padding: 30px; color: #64748b; font-size: 13px;">
                                    <i class="fa-solid fa-folder-open" style="font-size: 24px; margin-bottom: 8px; display: block; opacity: 0.5;"></i> No matching results found.
                                </div>
                            `;
                        }
                    });
            });
        }

        function confirmLogout() {
            Swal.fire({
                title: 'Mag-logout?', 
                text: 'Sigurado ka bang nais mong lumabas?', 
                icon: 'warning',
                showCancelButton: true, 
                confirmButtonColor: '#dc2626', 
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Oo, Logout'
            }).then((res) => { 
                if (res.isConfirmed) document.getElementById('logoutForm').submit(); 
            });
        }
        
        document.getElementById('profileLogoutBtn')?.addEventListener('click', (e) => { 
            e.preventDefault(); 
            confirmLogout(); 
        });
    </script>
</body>
</html>