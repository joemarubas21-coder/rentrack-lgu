<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();

require_once '../config/db.php';
require_once 'includes/auth.php';

/* =====================================================
   HELPER FUNCTIONS FOR PDO & POSTGRESQL
===================================================== */
function tableExists($conn, $tableName) {
    try {
        $stmt = $conn->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :t)");
        $stmt->execute([':t' => strtolower($tableName)]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) { return false; }
}

function columnExists($conn, $tableName, $columnName) {
    if (!tableExists($conn, $tableName)) return false;
    try {
        $stmt = $conn->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :t AND column_name = :c)");
        $stmt->execute([':t' => strtolower($tableName), ':c' => strtolower($columnName)]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) { return false; }
}

// ==========================
// AJAX REQUEST HANDLERS
// ==========================
if (isset($_GET['ajax_action'])) {
    $staffId = (int)($_SESSION['user_id'] ?? 0);

    if ($_GET['ajax_action'] === 'search') {
        $query = trim($_GET['q'] ?? '');
        if (strlen($query) >= 2) {
            $searchTerm = '%' . $query . '%';
            $sql = "
                SELECT r.id, r.tracking_no, r.business_name, r.status, u.fullname AS owner_name
                FROM rentals r
                LEFT JOIN users u ON r.user_id = u.id
                WHERE (r.tracking_no ILIKE :q OR r.business_name ILIKE :q OR u.fullname ILIKE :q)
                  AND r.is_deleted = FALSE
                LIMIT 5
            ";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':q' => $searchTerm]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($results && count($results) > 0) {
                echo '<div style="display: flex; flex-direction: column; gap: 8px;">';
                foreach ($results as $row) {
                    $status = $row['status'] ?? 'Draft';
                    $badgeBg = '#f3f4f6'; $badgeColor = '#4b5563';
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

// ==========================
// STAFF INFORMATION
// ==========================
$staffName = $_SESSION['fullname'] ?? "Staff";
$staffRole = $_SESSION['role'] ?? "Staff";
$staffId = (int)($_SESSION['user_id'] ?? 0);
$staffInitial = strtoupper(substr(trim($staffName), 0, 1));

$profilePicture = '';
if (tableExists($conn, 'users')) {
    $userProfileQuery = $conn->prepare("SELECT profile_picture FROM users WHERE id = :id LIMIT 1");
    $userProfileQuery->execute([':id' => $staffId]);
    $userProfileData = $userProfileQuery->fetch(PDO::FETCH_ASSOC);
    $profilePicture = $userProfileData['profile_picture'] ?? '';
}

$pendingRenewalsCount = 0;
if (tableExists($conn, 'permit_renewals')) {
    $pendRenewalsQuery = $conn->query("SELECT COUNT(*) FROM permit_renewals WHERE status::text = 'Pending'");
    if ($pendRenewalsQuery) {
        $pendingRenewalsCount = (int) $pendRenewalsQuery->fetchColumn();
    }
}

$notificationCount = 0;
$notifications = [];

if (tableExists($conn, 'notifications')) {
    $notifStmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE (user_id = :sid OR user_id = 0 OR user_id IS NULL) AND is_read = FALSE");
    $notifStmt->execute([':sid' => $staffId]);
    $notificationCount = (int) $notifStmt->fetchColumn();

    $notifListStmt = $conn->prepare("
        SELECT id, title, message, created_at 
        FROM notifications 
        WHERE (user_id = :sid OR user_id = 0 OR user_id IS NULL) 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $notifListStmt->execute([':sid' => $staffId]);
    $notifications = $notifListStmt->fetchAll(PDO::FETCH_ASSOC);
}

if (!isset($_GET['id']) && !isset($_POST['id'])) {
    header("Location: rentals.php");
    exit();
}

$id = (int) ($_POST['id'] ?? $_GET['id']);

function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
$csrfToken = generateCsrfToken();

// Handle update submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_POST['csrf_token']) ||
        !isset($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $_SESSION['error'] = 'Invalid session token. Please refresh the page and try again.';
        header("Location: edit_rental.php?id={$id}");
        exit();
    }

    $rental_type_id     = (int) ($_POST['rental_type_id'] ?? 0);
    $rental_name        = trim($_POST['rental_name'] ?? '');
    $business_name      = trim($_POST['business_name'] ?? '');
    $business_permit_no = trim($_POST['business_permit_no'] ?? '');
    $rpt_status         = trim($_POST['rpt_status'] ?? 'Unpaid');
    $business_address   = trim($_POST['business_address'] ?? '');
    $barangay           = trim($_POST['barangay'] ?? '');
    $contact_number     = trim($_POST['contact_number'] ?? '');
    $email              = trim($_POST['email'] ?? '');
    $monthly_rate       = (float) ($_POST['monthly_rate'] ?? 0);
    $floor_area         = (float) ($_POST['floor_area'] ?? 0);
    $registration_date  = !empty($_POST['registration_date']) ? $_POST['registration_date'] : null;
    $permit_expiry      = !empty($_POST['permit_expiry']) ? $_POST['permit_expiry'] : null;
    $status             = $_POST['status'] ?? 'Pending';
    $compliance_status  = $_POST['compliance_status'] ?? 'Pending';
    $description        = trim($_POST['description'] ?? '');

    if (empty($rental_name) || empty($business_name) || empty($business_address) || empty($barangay)) {
        $_SESSION['error'] = 'Please fill in all required fields.';
        header("Location: edit_rental.php?id={$id}");
        exit();
    }

    try {
        $sql = "UPDATE rentals SET
            rental_type_id = :rental_type_id,
            rental_name = :rental_name,
            business_name = :business_name,
            business_permit_no = :business_permit_no,
            rpt_status = :rpt_status,
            business_address = :business_address,
            barangay = :barangay,
            contact_number = :contact_number,
            email = :email,
            monthly_rate = :monthly_rate,
            floor_area = :floor_area,
            registration_date = :registration_date,
            permit_expiry = :permit_expiry,
            status = :status,
            compliance_status = :compliance_status,
            description = :description
            WHERE id = :id";

        $stmt = $conn->prepare($sql);
        $result = $stmt->execute([
            ':rental_type_id'     => $rental_type_id,
            ':rental_name'        => $rental_name,
            ':business_name'      => $business_name,
            ':business_permit_no' => $business_permit_no,
            ':rpt_status'         => $rpt_status,
            ':business_address'   => $business_address,
            ':barangay'           => $barangay,
            ':contact_number'     => $contact_number,
            ':email'              => $email,
            ':monthly_rate'       => $monthly_rate,
            ':floor_area'         => $floor_area,
            ':registration_date'  => $registration_date,
            ':permit_expiry'      => $permit_expiry,
            ':status'             => $status,
            ':compliance_status'  => $compliance_status,
            ':description'        => $description,
            ':id'                 => $id
        ]);

        if ($result) {
            $_SESSION['success'] = 'Rental updated successfully.';
            header("Location: view_rental.php?id={$id}");
            exit();
        } else {
            $_SESSION['error'] = 'Failed to update rental.';
            header("Location: edit_rental.php?id={$id}");
            exit();
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Failed to update rental: ' . safe_error($e);
        header("Location: edit_rental.php?id={$id}");
        exit();
    }
}

$q = $conn->prepare("SELECT * FROM rentals WHERE id = :id AND is_deleted = FALSE LIMIT 1");
$q->execute([':id' => $id]);
$r = $q->fetch(PDO::FETCH_ASSOC);

if (!$r) {
    $_SESSION['error'] = 'Rental not found.';
    header("Location: rentals.php");
    exit();
}

$ownerName = 'N/A';
if (!empty($r['user_id'])) {
    $ownerQuery = $conn->prepare("SELECT fullname FROM users WHERE id = :uid LIMIT 1");
    $ownerQuery->execute([':uid' => $r['user_id']]);
    $ownerRow = $ownerQuery->fetch(PDO::FETCH_ASSOC);
    if ($ownerRow) {
        $ownerName = $ownerRow['fullname'];
    }
}

$typesList = [];
if (tableExists($conn, 'rental_types')) {
    $typesStmt = $conn->query("SELECT id, type_name FROM rental_types ORDER BY type_name ASC");
    if ($typesStmt) {
        $typesList = $typesStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Rental | RENTRACK LGU</title>
    
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

        .animated-logo-text { color: #ffffff; }

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
            display: flex; align-items: center; justify-content: space-between;
            background: linear-gradient(90deg, #991b1b, #dc2626 55%, #991b1b);
            border-bottom: 2px solid #7f1d1d; padding: 6px 20px; gap: 20px;
            position: relative; z-index: 110;
            margin: 0 !important;
            box-shadow: 0 2px 8px -2px rgba(220, 38, 38, 0.45);
        }
        .official-header-left, .official-header-right-group {
            display: flex; align-items: center; gap: 12px; color: #fecdd3; font-size: 11px; font-weight: 600;
        }
        .official-header-badge { background: rgba(255, 255, 255, 0.15); border: 1px solid rgba(255, 255, 255, 0.3); padding: 2px 10px; border-radius: 20px; display: flex; align-items: center; gap: 6px; color: #ffffff; }

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
            transition: width 0.3s ease;
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
            color: #fff;
            line-height: 1.2;
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
        .sidebar-menu::-webkit-scrollbar { width: 3px; }
        .sidebar-menu::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.4); border-radius: 999px; }

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

        .sidebar-menu li { margin-bottom: 4px; }
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
            color: #fff;
        }
        
        /* ACTIVE MENU — WHITE BG + RED TEXT */
        .sidebar-menu li.active a {
            background: #ffffff !important;
            color: var(--primary-red) !important;
            border: none;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        .sidebar-menu li.active a i { color: var(--primary-red) !important; }
        .sidebar-menu li.active a span { color: var(--primary-red) !important; }

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
            gap: 12px;
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
        body.sidebar-collapsed .sidebar-footer a {
            justify-content: center;
            padding: 10px;
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
            gap: 16px;
            flex-shrink: 0;
            margin: 0 !important;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }

        .navbar-left {
            display: flex;
            align-items: center;
            gap: 16px;
            flex: 0 0 auto;
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
            font-size: 16px;
            cursor: pointer;
            transition: var(--transition);
        }
        .menu-toggle:hover {
            background: var(--primary-red);
            color: #fff;
            border-color: var(--primary-red);
        }

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
            font-size: 16px;
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
            font-family: 'Inter', sans-serif;
            outline: none;
            transition: all 0.3s ease;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }
        .search-container input::placeholder {
            color: #94a3b8;
        }
        .search-container:hover input {
            border-color: var(--primary-red);
            background: var(--red-soft);
        }

        .navbar-right {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 0 0 auto;
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
            font-size: 16px;
            cursor: pointer;
            transition: var(--transition);
            position: relative;
            text-decoration: none;
        }
        .nav-icon:hover {
            background: #f8fafc;
        }
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

        .nav-icon-wrapper { position: relative; }

        /* ===== NOTIFICATION DROPDOWN ===== */
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
            transform: translateY(-10px) scale(0.98);
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 55;
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
            transition: background 0.2s ease;
            cursor: default;
        }
        .notification-item:hover { background: #f8fafc; }
        .notification-item strong { display: block; font-size: 13px; color: var(--primary-red); margin-bottom: 2px; }
        .notification-item p { font-size: 12px; color: #475569; margin: 0; line-height: 1.4; }
        .notification-item small { font-size: 10px; color: #94a3b8; }

        /* ===== PROFILE DROPDOWN ===== */
        .profile-wrapper { position: relative; }

        .profile-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 4px 14px 4px 4px;
            border-radius: 12px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            cursor: pointer;
            transition: var(--transition);
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
        }
        .profile-btn .avatar img { width: 100%; height: 100%; object-fit: cover; }
        .profile-btn .info { text-align: left; line-height: 1.2; }
        .profile-btn .info .name { font-size: 12px; font-weight: 600; color: #0f172a; }
        .profile-btn .info .role { font-size: 9px; color: var(--primary-red); font-weight: 700; text-transform: uppercase; }
        .profile-btn .chevron { font-size: 10px; color: #64748b; transition: transform 0.3s ease; }
        .profile-btn.active .chevron { transform: rotate(180deg); }

        .profile-dropdown {
            position: absolute;
            top: calc(100% + 12px);
            right: 0;
            width: 220px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 8px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px) scale(0.95);
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
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
            transition: background 0.2s ease;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
        }
        .profile-dropdown .dropdown-item:hover {
            background: var(--red-soft);
            color: var(--primary-red);
        }
        .profile-dropdown .dropdown-item i { width: 20px; text-align: center; font-size: 15px; }
        .profile-dropdown .dropdown-divider { height: 1px; background: #f1f5f9; margin: 6px 8px; }
        .profile-dropdown .dropdown-item.danger { color: #dc2626; }

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
            transition: var(--transition);
        }
        .breadcrumb a:hover { color: var(--primary-red); }
        .breadcrumb strong { color: #0f172a; }

        /* ============================================
           PAGE HEADER — LIGHT PINK
        ============================================ */
        .page-header {
            background: #fff1f2;
            border: 1px solid #fecdd3;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            border-radius: var(--radius);
            padding: 20px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 28px;
            position: relative;
            overflow: hidden;
        }
        .page-header::after {
            content: '';
            position: absolute;
            width: 180px; height: 180px; border-radius: 50%;
            background: rgba(225, 29, 72, 0.05);
            bottom: -60px; right: -40px; pointer-events: none;
        }
        .page-header h1 {
            font-size: 26px;
            font-weight: 700;
            color: #9f1239;
            margin: 0;
            position: relative;
            z-index: 1;
        }
        .page-header h1 i { color: var(--primary-red); margin-right: 10px; }
        .page-header p {
            color: #334155;
            font-size: 14px;
            margin-top: 4px;
            position: relative;
            z-index: 1;
        }
        .page-header .date-badge {
            background: #ffffff;
            border: 1px solid #fecdd3;
            border-radius: 40px;
            padding: 8px 18px;
            font-size: 13px;
            color: var(--primary-red);
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            position: relative;
            z-index: 1;
        }
        .page-header .date-badge i { color: var(--primary-red); }

        /* ===== ALERTS ===== */
        .alert {
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
        }
        .alert.danger {
            background: #fee2e2;
            border: 1px solid #fecaca;
            color: #b91c1c;
        }
        .alert.success {
            background: #dcfce7;
            border: 1px solid #bbf7d0;
            color: #15803d;
        }

        /* ===== CARD ===== */
        .card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: var(--radius);
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px -1px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            transition: var(--transition);
            margin-bottom: 24px;
        }

        .card:hover { border-color: #cbd5e1; }

        .card-header {
            padding: 18px 24px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 16px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #0f172a;
            background: #ffffff;
        }
        .card-header i { color: var(--primary-red); }

        .card-body { padding: 24px; }

        /* ===== FORM ===== */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-grid .full-width { grid-column: 1 / -1; }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-group label {
            font-size: 13px;
            font-weight: 600;
            color: #334155;
        }
        .form-group label .required { color: #dc2626; margin-left: 2px; }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 10px 14px;
            border-radius: 10px;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            color: #0f172a;
            font-size: 14px;
            font-family: inherit;
            transition: var(--transition);
            outline: none;
            width: 100%;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--primary-red);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }

        .form-group input::placeholder,
        .form-group textarea::placeholder {
            color: #94a3b8;
        }

        .form-group input:read-only {
            background: #f8fafc;
            opacity: 0.8;
            cursor: not-allowed;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .form-group select option {
            background: #ffffff;
            color: #0f172a;
        }

        /* ===== BUTTONS ===== */
        .form-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            padding-top: 16px;
            border-top: 1px solid #f1f5f9;
            grid-column: 1 / -1;
        }

        /* ✅ Primary button — RED */
        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 24px;
            border-radius: 12px;
            background: var(--primary-red);
            color: #fff;
            font-weight: 600;
            font-size: 14px;
            border: none;
            cursor: pointer;
            transition: var(--transition);
            font-family: inherit;
        }
        .btn-primary:hover {
            background: var(--primary-red-dark);
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(220, 38, 38, 0.25);
        }
        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .btn-secondary {
            display: inline-flex;
            align-items: center;
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
        .btn-secondary:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        /* ===== FOOTER ===== */
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
            gap: 8px;
            flex-shrink: 0;
        }

        .footer .brand {
            color: var(--primary-red);
            font-weight: 700;
        }

        @media (max-width: 768px) {
            .official-header {
                flex-direction: column;
                align-items: center;
                gap: 4px;
                padding: 8px 10px;
            }
            .official-header-left, .official-header-right-group {
                font-size: 10px;
            }
            .top-navbar { padding: 10px 16px; }
            .nav-clock { display: none; }
            .profile-btn .info { display: none; }
            .profile-btn { padding: 4px; }
            .page-title small { display: none; }
            .search-wrapper { max-width: 200px; padding: 0 8px; }
            .search-container input { font-size: 13px; padding: 8px 12px 8px 36px; }
            .search-container i { font-size: 14px; left: 12px; }
            .notification-dropdown { width: calc(100vw - 32px); right: -10px; }
            .profile-dropdown { right: -20px; width: 200px; }

            .content { padding: 16px; }
            .page-header { flex-direction: column; align-items: flex-start; }
            .page-header h1 { font-size: 22px; }
            .form-grid { grid-template-columns: 1fr; }
            .form-grid .full-width { grid-column: 1; }
            .form-actions { flex-direction: column; }
            .form-actions .btn-primary, .form-actions .btn-secondary { justify-content: center; width: 100%; }
        }

        @media (max-width: 992px) {
            .sidebar {
                position: fixed;
                top: 0;
                left: -280px;
                z-index: 2000;
                transition: left 0.3s ease;
            }
            body.sidebar-mobile-open .sidebar { left: 0 !important; }
            body.sidebar-collapsed .sidebar { width: 250px; }
        }

        @media (max-width: 480px) {
            .footer { flex-direction: column; text-align: center; }
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .card { animation: fadeInUp 0.4s ease forwards; }

        .content::-webkit-scrollbar { width: 6px; }
        .content::-webkit-scrollbar-track { background: transparent; }
        .content::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 999px; }
        .content::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
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
            <aside class="sidebar" role="navigation" aria-label="Sidebar navigation">
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
                    <li class="active"><a href="rentals.php"><i class="fa-solid fa-folder-open"></i><span>Rental Applications</span></a></li>
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
                    <li><a href="profile.php"><i class="fa-solid fa-user"></i><span>My Profile</span></a></li>
                    <li><a href="settings.php"><i class="fa-solid fa-gear"></i><span>Settings</span></a></li>
                </ul>

                <div class="sidebar-footer">
                    <a href="#" onclick="confirmLogout(); return false;">
                        <i class="fa-solid fa-right-from-bracket"></i><span>Logout</span>
                    </a>
                </div>
            </aside>

            <div class="main-wrapper">
                <header class="top-navbar">
                    <div class="navbar-left">
                        <button class="menu-toggle" id="menuToggle" aria-label="Toggle menu">
                            <i class="fa-solid fa-bars"></i>
                        </button>
                        <div class="page-title">
                            Edit Rental
                            <small>Update Rental Details</small>
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
                            <div class="date" id="clockDate"><?php echo date("M d, Y"); ?></div>
                        </div>

                        <div class="nav-icon-wrapper">
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
                                            <strong><?php echo htmlspecialchars($n['title'] ?? 'Notification'); ?></strong>
                                            <p><?php echo htmlspecialchars($n['message'] ?? ''); ?></p>
                                            <small><?php echo date("M d, Y h:i A", strtotime($n['created_at'] ?? 'now')); ?></small>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="notification-item" style="text-align:center;color:#64748b;padding:20px;">
                                        <i class="fa-regular fa-bell" style="font-size:24px;display:block;margin-bottom:8px;"></i>
                                        No notifications
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="profile-wrapper">
                            <div class="profile-btn" id="profileToggle">
                                <div class="avatar">
                                    <?php if (!empty($profilePicture) && file_exists(__DIR__ . '/' . $profilePicture)): ?>
                                        <img src="<?php echo htmlspecialchars($profilePicture); ?>" alt="Avatar">
                                    <?php else: ?>
                                        <?php echo $staffInitial; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="info">
                                    <div class="name"><?php echo htmlspecialchars($staffName); ?></div>
                                    <div class="role"><?php echo htmlspecialchars($staffRole); ?></div>
                                </div>
                                <i class="fa-solid fa-chevron-down chevron"></i>
                            </div>

                            <div class="profile-dropdown" id="profileDropdown">
                                <a href="profile.php" class="dropdown-item">
                                    <i class="fa-solid fa-user"></i> My Profile
                                </a>
                                <a href="settings.php" class="dropdown-item">
                                    <i class="fa-solid fa-gear"></i> Settings
                                </a>
                                <div class="dropdown-divider"></div>
                                <a href="#" class="dropdown-item danger" id="profileLogoutBtn">
                                    <i class="fa-solid fa-right-from-bracket"></i> Logout
                                </a>
                            </div>
                        </div>
                    </div>
                </header>

                <main class="content">
                    <div class="breadcrumb">
                        <a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
                        <span><i class="fa-solid fa-angle-right"></i></span>
                        <a href="rentals.php">Rental Applications</a>
                        <span><i class="fa-solid fa-angle-right"></i></span>
                        <a href="view_rental.php?id=<?php echo $r['id']; ?>">View Rental</a>
                        <span><i class="fa-solid fa-angle-right"></i></span>
                        <strong>Edit Rental</strong>
                    </div>

                    <!-- LIGHT PINK HEADER CARD -->
                    <div class="page-header">
                        <div>
                            <h1><i class="fa-solid fa-pen"></i>Edit Rental</h1>
                            <p>Update details for this rental establishment.</p>
                        </div>
                        <div class="date-badge">
                            <i class="fa-regular fa-calendar"></i>
                            <?php echo date("F d, Y"); ?>
                        </div>
                    </div>

                    <?php if (isset($_SESSION['error'])): ?>
                        <div class="alert danger"><i class="fa-regular fa-circle-xmark"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
                    <?php endif; ?>

                    <?php if (isset($_SESSION['success'])): ?>
                        <div class="alert success"><i class="fa-regular fa-circle-check"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
                    <?php endif; ?>

                    <!-- FORM CARD -->
                    <div class="card">
                        <div class="card-header">
                            <i class="fa-solid fa-building"></i> Rental Information
                        </div>
                        <div class="card-body">

                            <form action="edit_rental.php?id=<?php echo $r['id']; ?>" method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="id" value="<?php echo $r['id']; ?>">

                                <div class="form-grid">

                                    <div class="form-group">
                                        <label>Tracking No.</label>
                                        <input type="text" value="<?php echo htmlspecialchars($r['tracking_no']); ?>" readonly>
                                    </div>

                                    <div class="form-group">
                                        <label>Rental Owner</label>
                                        <input type="text" value="<?php echo htmlspecialchars($ownerName); ?>" readonly>
                                    </div>

                                    <div class="form-group">
                                        <label>Rental Type <span class="required">*</span></label>
                                        <select name="rental_type_id" required>
                                            <option value="">-- Select Type --</option>
                                            <?php foreach($typesList as $t): ?>
                                                <option value="<?php echo $t['id']; ?>" <?php echo ($t['id'] == $r['rental_type_id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($t['type_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label>Rental Establishment Name <span class="required">*</span></label>
                                        <input type="text" name="rental_name" value="<?php echo htmlspecialchars($r['rental_name']); ?>" required>
                                    </div>

                                    <div class="form-group">
                                        <label>Business Name <span class="required">*</span></label>
                                        <input type="text" name="business_name" value="<?php echo htmlspecialchars($r['business_name']); ?>" required>
                                    </div>

                                    <div class="form-group">
                                        <label>Business Permit Number</label>
                                        <input type="text" name="business_permit_no" value="<?php echo htmlspecialchars($r['business_permit_no'] ?? ''); ?>" placeholder="e.g. BP-2026-0001">
                                    </div>

                                    <div class="form-group">
                                        <label>RPT (Amilyar) Status</label>
                                        <select name="rpt_status">
                                            <?php $rpt = $r['rpt_status'] ?? 'Unpaid'; ?>
                                            <option value="Paid" <?php echo ($rpt === 'Paid') ? 'selected' : ''; ?>>Paid</option>
                                            <option value="Unpaid" <?php echo ($rpt === 'Unpaid') ? 'selected' : ''; ?>>Unpaid</option>
                                            <option value="Pending" <?php echo ($rpt === 'Pending') ? 'selected' : ''; ?>>Pending</option>
                                        </select>
                                    </div>

                                    <div class="form-group full-width">
                                        <label>Business Address <span class="required">*</span></label>
                                        <textarea name="business_address" rows="3" required><?php echo htmlspecialchars($r['business_address']); ?></textarea>
                                    </div>

                                    <div class="form-group">
                                        <label>Barangay <span class="required">*</span></label>
                                        <input type="text" name="barangay" value="<?php echo htmlspecialchars($r['barangay']); ?>" required>
                                    </div>

                                    <div class="form-group">
                                        <label>Contact Number <span class="required">*</span></label>
                                        <input type="text" name="contact_number" value="<?php echo htmlspecialchars($r['contact_number']); ?>" required>
                                    </div>

                                    <div class="form-group">
                                        <label>Email Address</label>
                                        <input type="email" name="email" value="<?php echo htmlspecialchars($r['email']); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label>Monthly Rate (₱) <span class="required">*</span></label>
                                        <input type="number" step="0.01" name="monthly_rate" value="<?php echo htmlspecialchars($r['monthly_rate']); ?>" required>
                                    </div>

                                    <div class="form-group">
                                        <label>Floor Area (sqm)</label>
                                        <input type="number" step="0.01" name="floor_area" value="<?php echo htmlspecialchars($r['floor_area']); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label>Registration Date</label>
                                        <input type="date" name="registration_date" value="<?php echo htmlspecialchars($r['registration_date']); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label>Permit Expiry</label>
                                        <input type="date" name="permit_expiry" value="<?php echo htmlspecialchars($r['permit_expiry']); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label>Status</label>
                                        <select name="status">
                                            <option value="Pending" <?php echo ($r['status'] == 'Pending') ? 'selected' : ''; ?>>Pending</option>
                                            <option value="Approved" <?php echo ($r['status'] == 'Approved') ? 'selected' : ''; ?>>Approved</option>
                                            <option value="Rejected" <?php echo ($r['status'] == 'Rejected') ? 'selected' : ''; ?>>Rejected</option>
                                            <option value="Expired" <?php echo ($r['status'] == 'Expired') ? 'selected' : ''; ?>>Expired</option>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label>Compliance Status</label>
                                        <select name="compliance_status">
                                            <option value="Compliant" <?php echo ($r['compliance_status'] == 'Compliant') ? 'selected' : ''; ?>>Compliant</option>
                                            <option value="Pending" <?php echo ($r['compliance_status'] == 'Pending') ? 'selected' : ''; ?>>Pending</option>
                                            <option value="Expired" <?php echo ($r['compliance_status'] == 'Expired') ? 'selected' : ''; ?>>Expired</option>
                                            <option value="Non-Compliant" <?php echo ($r['compliance_status'] == 'Non-Compliant') ? 'selected' : ''; ?>>Non-Compliant</option>
                                        </select>
                                    </div>

                                    <div class="form-group full-width">
                                        <label>Description / Remarks</label>
                                        <textarea name="description" rows="4" placeholder="Enter additional remarks..."><?php echo htmlspecialchars($r['description']); ?></textarea>
                                    </div>

                                    <div class="form-actions">
                                        <button type="submit" class="btn-primary" id="saveBtn">
                                            <i class="fa-solid fa-floppy-disk"></i> Save Changes
                                        </button>
                                        <a href="view_rental.php?id=<?php echo $r['id']; ?>" class="btn-secondary">
                                            <i class="fa-solid fa-arrow-left"></i> Cancel
                                        </a>
                                    </div>

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

    <!-- SEARCH MODAL -->
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
        (function() {
            'use strict';

            const menuToggle = document.getElementById('menuToggle');
            const menuIcon = menuToggle.querySelector('i');

            menuToggle.addEventListener('click', function() {
                if (window.innerWidth <= 992) {
                    document.body.classList.toggle('sidebar-mobile-open');
                } else {
                    const collapsed = document.body.classList.toggle('sidebar-collapsed');
                    menuToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                    menuIcon.classList.toggle('fa-bars', !collapsed);
                    menuIcon.classList.toggle('fa-xmark', collapsed);
                    localStorage.setItem('sidebarCollapsed', collapsed ? '1' : '0');
                }
            });

            if (localStorage.getItem('sidebarCollapsed') === '1') {
                document.body.classList.add('sidebar-collapsed');
                menuToggle.setAttribute('aria-expanded', 'false');
                menuIcon.classList.remove('fa-bars');
                menuIcon.classList.add('fa-xmark');
            }

            const timeEl = document.getElementById('clockTime');
            function pad(n) { return String(n).padStart(2, '0'); }
            function tickClock() {
                const d = new Date();
                let h = d.getHours();
                const ampm = h >= 12 ? 'PM' : 'AM';
                h = h % 12;
                if (h === 0) h = 12;
                timeEl.textContent = pad(h) + ':' + pad(d.getMinutes()) + ' ' + ampm;
            }
            tickClock();
            setInterval(tickClock, 1000);

            const notifBtn = document.getElementById('notifBtn');
            const notifDropdown = document.getElementById('notifDropdown');
            if (notifBtn) {
                notifBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    notifDropdown.classList.toggle('show');
                });
            }
            document.addEventListener('click', function(e) {
                if (notifDropdown && !notifDropdown.contains(e.target) && e.target !== notifBtn) {
                    notifDropdown.classList.remove('show');
                }
            });

            const profileToggle = document.getElementById('profileToggle');
            const profileDropdown = document.getElementById('profileDropdown');
            if (profileToggle) {
                profileToggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    profileToggle.classList.toggle('active');
                    profileDropdown.classList.toggle('show');
                });
            }
            document.addEventListener('click', function(e) {
                if (profileDropdown && !profileDropdown.contains(e.target) && e.target !== profileToggle) {
                    profileDropdown.classList.remove('show');
                    profileToggle.classList.remove('active');
                }
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

                    fetch('edit_rental.php?ajax_action=search&q=' + encodeURIComponent(query))
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

            document.querySelector('form').addEventListener('submit', function() {
                const saveBtn = document.getElementById('saveBtn');
                saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';
                saveBtn.disabled = true;
            });

        })();

        function confirmLogout() {
            Swal.fire({
                title: 'Are you sure?',
                text: 'You will be logged out of your account.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Logout',
                cancelButtonText: 'Cancel',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('logoutForm').submit();
                }
            });
        }

        document.querySelector('.sidebar-footer a')?.addEventListener('click', function(e) {
            e.preventDefault();
            confirmLogout();
        });

        document.getElementById('profileLogoutBtn')?.addEventListener('click', function(e) {
            e.preventDefault();
            confirmLogout();
        });
    </script>

</body>
</html>