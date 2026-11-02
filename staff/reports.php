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

                    echo '<a href="view_rental.php?id=' . $row['id'] . '" style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 12px; color: #111827; text-decoration: none;">';
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

    if ($_GET['ajax_action'] === 'read_notif') {
        if ($staffId > 0) {
            $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE (user_id = :staff_id OR user_id = 0 OR user_id IS NULL) AND is_read = FALSE");
            $stmt->execute([':staff_id' => $staffId]);
            echo 'success';
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
if (columnExists($conn, 'users', 'profile_picture')) {
    $userProfileQuery = $conn->prepare("SELECT profile_picture FROM users WHERE id = :id LIMIT 1");
    $userProfileQuery->execute([':id' => $staffId]);
    $profilePicture = $userProfileQuery->fetchColumn() ?: '';
}

$pendingRenewalsCount = 0;
if (tableExists($conn, 'permit_renewals')) {
    $pendRenewalsQuery = $conn->query("SELECT COUNT(*) FROM permit_renewals WHERE status::text = 'Pending'");
    if ($pendRenewalsQuery) {
        $pendingRenewalsCount = (int)$pendRenewalsQuery->fetchColumn();
    }
}

// Financial counts (Payments lang - inalis na ang Remittances)
$pendingPayments  = 0;
$verifiedPayments = 0;
$totalPayments    = 0;

if (tableExists($conn, 'payments')) {
    $q1 = $conn->query("SELECT COUNT(*) FROM payments WHERE status = 'Pending'");
    if ($q1) $pendingPayments = (int)$q1->fetchColumn();
    $q2 = $conn->query("SELECT COUNT(*) FROM payments WHERE status IN ('Paid', 'Verified')");
    if ($q2) $verifiedPayments = (int)$q2->fetchColumn();
    $q3 = $conn->query("SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE status IN ('Paid', 'Verified')");
    if ($q3) $totalPayments = (float)$q3->fetchColumn();
}

$notificationCount = 0;
if (tableExists($conn, 'notifications')) {
    $notifCountStmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE (user_id = :sid OR user_id = 0 OR user_id IS NULL) AND is_read = FALSE");
    $notifCountStmt->execute([':sid' => $staffId]);
    $notificationCount = (int)$notifCountStmt->fetchColumn();
}

$notifications = [];
if (tableExists($conn, 'notifications')) {
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

// ==========================
// REPORT FILTERS & DATA
// ==========================
$allowedStatuses = ['all', 'Pending', 'Approved', 'Rejected'];

$startDateRaw = $_GET['start_date'] ?? date('Y-01-01');
$endDateRaw = $_GET['end_date'] ?? date('Y-m-d');
$reportTypeRaw = $_GET['report_type'] ?? 'all';

$startDate = (DateTime::createFromFormat('Y-m-d', $startDateRaw) !== false) ? $startDateRaw : date('Y-01-01');
$endDate = (DateTime::createFromFormat('Y-m-d', $endDateRaw) !== false) ? $endDateRaw : date('Y-m-d');
$reportType = in_array($reportTypeRaw, $allowedStatuses, true) ? $reportTypeRaw : 'all';

$sDateTime = $startDate . ' 00:00:00';
$eDateTime = $endDate . ' 23:59:59';

$reportSql = "
    SELECT r.*, u.fullname AS owner_name, u.email, rt.type_name
    FROM rentals r
    LEFT JOIN users u ON r.user_id = u.id
    LEFT JOIN rental_types rt ON r.rental_type_id = rt.id
    WHERE r.created_at BETWEEN :sdate AND :edate
      AND r.is_deleted = FALSE
";
if ($reportType !== 'all') {
    $reportSql .= " AND r.status::text = :status";
}
$reportSql .= " ORDER BY r.created_at DESC";

$reportRows = [];
if (tableExists($conn, 'rentals')) {
    $reportStmt = $conn->prepare($reportSql);
    $params = [':sdate' => $sDateTime, ':edate' => $eDateTime];
    if ($reportType !== 'all') {
        $params[':status'] = $reportType;
    }
    $reportStmt->execute($params);
    $reportRows = $reportStmt->fetchAll(PDO::FETCH_ASSOC);
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=rentrack_reports_' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, ['Tracking No.', 'Owner Name', 'Business Name', 'Rental Type', 'Status', 'RPT Status', 'Date Created']);
    foreach ($reportRows as $row) {
        fputcsv($output, [
            $row['tracking_no'],
            $row['owner_name'] ?? 'N/A',
            $row['business_name'],
            $row['type_name'] ?? 'N/A',
            $row['status'],
            $row['rpt_status'] ?? 'Unpaid',
            $row['created_at']
        ]);
    }
    fclose($output);
    exit;
}

$totalAppsInRange = 0;
$approvedInRange = 0;
$pendingInRange = 0;
$rejectedInRange = 0;

if (tableExists($conn, 'rentals')) {
    $summaryStmt = $conn->prepare("
        SELECT status::text as status, COUNT(*) as count 
        FROM rentals 
        WHERE created_at BETWEEN :sdate AND :edate 
          AND is_deleted = FALSE
        GROUP BY status::text
    ");
    $summaryStmt->execute([':sdate' => $sDateTime, ':edate' => $eDateTime]);
    $summaryResults = $summaryStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($summaryResults as $row) {
        $cnt = (int)$row['count'];
        $totalAppsInRange += $cnt;
        if ($row['status'] === 'Approved') $approvedInRange = $cnt;
        if ($row['status'] === 'Pending') $pendingInRange = $cnt;
        if ($row['status'] === 'Rejected') $rejectedInRange = $cnt;
    }
}

$monthlyCollections = [0,0,0,0,0,0,0,0,0,0,0,0];
$monthlyLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

if (tableExists($conn, 'payments')) {
    $mQuery = $conn->query("
        SELECT EXTRACT(MONTH FROM payment_date) as m, SUM(amount_paid) as total 
        FROM payments 
        WHERE status::text IN ('Paid', 'Verified', 'Accepted', 'Completed', 'Success') 
          AND EXTRACT(YEAR FROM payment_date) = EXTRACT(YEAR FROM CURRENT_DATE) 
        GROUP BY EXTRACT(MONTH FROM payment_date)
    ");
    if ($mQuery) {
        while ($mrow = $mQuery->fetch(PDO::FETCH_ASSOC)) {
            $idx = (int)$mrow['m'] - 1;
            if (isset($monthlyCollections[$idx])) {
                $monthlyCollections[$idx] += (float)$mrow['total'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#dc2626">
    <title>Reports &amp; Analytics | RENTRACK LGU</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        :root {
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

            --ok: #059669;
            --ok-bg: #ecfdf5;
            --ok-bd: #a7f3d0;
            --warn: #b45309;
            --warn-bg: #fffbeb;
            --warn-bd: #fde68a;
            --danger: #b91c1c;
            --danger-bg: #fef2f2;
            --danger-bd: #fecaca;
            --info: #1d4ed8;
            --info-bg: #eff6ff;
            --info-bd: #dbeafe;
            --purple: #6d28d9;
            --purple-bg: #f5f3ff;
            --purple-bd: #ddd6fe;

            --r-xs: 6px;
            --r-sm: 8px;
            --r-md: 10px;
            --r-lg: 12px;
            --r-xl: 16px;
            --radius: 20px;

            --sh-xs: 0 1px 2px rgba(15, 23, 42, 0.04);
            --sh-sm: 0 1px 3px rgba(15, 23, 42, 0.06), 0 1px 2px rgba(15, 23, 42, 0.03);
            --sh-md: 0 4px 10px -2px rgba(15, 23, 42, 0.06), 0 2px 4px -2px rgba(15, 23, 42, 0.04);
            --sh-lg: 0 12px 24px -8px rgba(15, 23, 42, 0.10), 0 4px 8px -4px rgba(15, 23, 42, 0.05);

            --ease: cubic-bezier(0.4, 0, 0.2, 1);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);

            --safe-top: env(safe-area-inset-top, 0px);
            --safe-bottom: env(safe-area-inset-bottom, 0px);
            --safe-left: env(safe-area-inset-left, 0px);
            --safe-right: env(safe-area-inset-right, 0px);
        }

        @keyframes borderSpin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        html, body {
            margin: 0 !important; padding: 0 !important; border: 0 !important;
            height: 100%;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            overflow-x: hidden;
            -webkit-text-size-adjust: 100%;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        * { scrollbar-width: thin; scrollbar-color: rgba(220, 38, 38, 0.45) #f1f5f9; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 20px; }
        ::-webkit-scrollbar-thumb { background: var(--primary-red); border-radius: 20px; }

        body.drawer-open { overflow: hidden !important; touch-action: none; }

        .page-container {
            display: flex; flex-direction: column;
            min-height: 100vh; min-height: 100dvh;
            width: 100%;
            margin: 0 !important; padding: 0 !important;
            padding-left: var(--safe-left);
            padding-right: var(--safe-right);
        }

        /* OFFICIAL HEADER */
        .official-header {
            width: 100%;
            display: flex; align-items: center; justify-content: space-between;
            background: linear-gradient(90deg, #991b1b, #dc2626 55%, #991b1b);
            border-bottom: 2px solid #7f1d1d;
            padding: 6px 20px;
            padding-top: calc(6px + var(--safe-top));
            gap: 20px;
            position: relative; z-index: 110;
            margin: 0 !important;
            box-shadow: 0 2px 8px -2px rgba(220, 38, 38, 0.45);
        }
        .official-header-left, .official-header-right-group {
            display: flex; align-items: center; gap: 12px;
            color: #fecdd3; font-size: 11px; font-weight: 600;
        }
        .official-header-badge {
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.3);
            padding: 2px 10px; border-radius: 20px;
            display: flex; align-items: center; gap: 6px;
            color: #ffffff;
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

        .main-wrapper {
            flex: 1;
            display: flex; flex-direction: column;
            min-height: 100vh; min-height: 100dvh;
            margin: 0 !important; padding: 0 !important;
            background: #f8fafc;
        }

        .sticky-header {
            position: sticky; top: 0;
            z-index: 2000;
            background: #ffffff;
        }

        .top-navbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 18px;
            background: #ffffff;
            border-bottom: 1px solid #e2e8f0;
            margin: 0 !important;
            gap: 14px;
            flex-wrap: nowrap;
            min-height: 58px;
        }

        .navbar-left { display: flex; align-items: center; gap: 14px; flex-shrink: 0; }

        .nav-brand { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .brand-logo { position: relative; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .brand-logo-ring {
            position: absolute; inset: -2px; border-radius: 50%;
            border: 2px dashed rgba(220, 38, 38, 0.55);
            animation: borderSpin 4s linear infinite;
        }
        .brand-logo-circle {
            width: 36px; height: 36px; border-radius: 50%;
            background: linear-gradient(135deg, #dc2626, #991b1b);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-weight: 800; font-size: 13px; z-index: 2;
            box-shadow: 0 4px 10px rgba(220, 38, 38, 0.35);
        }
        .brand-text h2 { font-size: 14px; font-weight: 800; color: var(--primary-red); line-height: 1.1; margin: 0; }
        .brand-text span { font-size: 9px; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 1.3px; }

        .menu-toggle {
            display: none;
            width: 42px; height: 42px; border-radius: 10px;
            align-items: center; justify-content: center;
            background: #f8fafc; border: 1px solid #e2e8f0;
            color: var(--primary-red); cursor: pointer;
            font-size: 16px; flex-shrink: 0;
        }
        .menu-toggle:active { background: var(--primary-red); color: #fff; border-color: var(--primary-red); }

        .main-nav-bar {
            display: flex; align-items: center;
            background: transparent; border: none; padding: 0;
            position: relative; flex-shrink: 0; z-index: 45;
        }

        .nav-menu {
            list-style: none; margin: 0; padding: 0;
            display: flex; align-items: center;
            gap: 2px; flex-wrap: nowrap;
        }

        .nav-item { position: relative; }

        .nav-link {
            display: flex; align-items: center; gap: 6px;
            padding: 10px 10px;
            color: #334155; text-decoration: none;
            font-size: 12.5px; font-weight: 600;
            border-radius: 8px;
            transition: all 0.15s ease;
            cursor: pointer; white-space: nowrap;
            background: transparent; border: none;
            font-family: inherit;
        }

        .nav-link i.nav-ico { color: var(--primary-red); font-size: 12px; width: 14px; text-align: center; }
        .nav-link .chevron { font-size: 8px; margin-left: 1px; transition: transform 0.2s ease; opacity: 0.7; }
        .nav-link:hover { color: var(--primary-red); background: #fff1f2; }
        .nav-link.active { color: var(--primary-red); background: #fff1f2; font-weight: 700; }
        .nav-item.open > .nav-link { color: var(--primary-red); background: #fff1f2; }
        .nav-item.open > .nav-link .chevron { transform: rotate(180deg); }

        .nav-link .menu-badge {
            background: #fbbf24; color: #7c2d12; font-size: 10px; font-weight: 700;
            padding: 1px 6px; border-radius: 999px;
            box-shadow: 0 0 8px rgba(251, 191, 36, 0.55);
            animation: pulseBadge 2s ease-in-out infinite;
        }
        @keyframes pulseBadge {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        .nav-dropdown {
            position: absolute; top: calc(100% + 6px); left: 50%;
            transform: translateX(-50%) translateY(8px);
            min-width: 250px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 12px 30px rgba(0,0,0,0.10);
            padding: 8px;
            opacity: 0; visibility: hidden;
            transition: opacity 0.18s ease, transform 0.18s ease, visibility 0.18s;
            z-index: 200;
        }
        .nav-item.open .nav-dropdown {
            opacity: 1; visibility: visible;
            transform: translateX(-50%) translateY(0);
        }
        .nav-item.align-right .nav-dropdown {
            left: auto; right: 0;
            transform: translateX(0) translateY(8px);
        }
        .nav-item.align-right.open .nav-dropdown {
            transform: translateX(0) translateY(0);
        }

        .nav-dropdown a {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 12px; border-radius: 8px;
            color: #334155; text-decoration: none;
            font-size: 13px; font-weight: 500;
            transition: all 0.15s ease;
        }
        .nav-dropdown a:hover { background: #fff1f2; color: var(--primary-red); }
        .nav-dropdown a i { width: 16px; color: var(--primary-red); font-size: 14px; text-align: center; flex-shrink: 0; }
        .nav-dropdown a .menu-badge {
            margin-left: auto; background: #fbbf24; color: #7c2d12;
            font-size: 10px; font-weight: 700;
            padding: 1px 8px; border-radius: 999px;
        }

        .mobile-drawer-header { display: none; }

        .search-wrapper {
            flex: 1 1 auto; display: flex; justify-content: center;
            min-width: 140px; max-width: 380px;
            padding: 0 8px; margin: 0 auto;
        }
        .search-container { position: relative; width: 100%; }
        .search-container i {
            position: absolute; left: 13px; top: 50%;
            transform: translateY(-50%);
            color: var(--primary-red); z-index: 2; font-size: 13px;
        }
        .search-container input {
            width: 100%; padding: 8px 14px 8px 38px;
            border-radius: 999px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            color: #0f172a; font-size: 13px; outline: none;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }
        .search-container input::placeholder { color: #94a3b8; }
        .search-container:hover input { border-color: var(--primary-red); background: var(--red-soft); }

        .navbar-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .nav-clock {
            display: flex; flex-direction: column; align-items: flex-end;
            padding: 3px 14px; border-radius: 10px;
            background: var(--red-soft);
            border: 1px solid var(--primary-red-light);
        }
        .nav-clock .time { font-family: 'JetBrains Mono', monospace; font-size: 13px; font-weight: 700; color: var(--primary-red); }
        .nav-clock .date { font-size: 10px; color: #64748b; }

        .nav-icon {
            width: 38px; height: 38px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            background: #ffffff; border: 1px solid #e2e8f0;
            color: var(--primary-red); cursor: pointer; position: relative;
            flex-shrink: 0;
        }
        .nav-icon:active { background: #f8fafc; }
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
            border: 1px solid #e2e8f0; cursor: pointer;
        }
        .profile-btn:hover { background: #f8fafc; }
        .profile-btn .avatar {
            width: 32px; height: 32px; border-radius: 50%;
            background: var(--primary-red);
            display: flex; align-items: center; justify-content: center;
            color: #ffffff; font-weight: 800; font-size: 13px;
            overflow: hidden;
        }
        .profile-btn .avatar img { width: 100%; height: 100%; object-fit: cover; }
        .profile-btn .info .name { font-size: 12px; font-weight: 600; color: #0f172a; }
        .profile-btn .info .role { font-size: 9px; color: var(--primary-red); font-weight: 700; text-transform: uppercase; }

        .profile-dropdown, .notification-dropdown {
            position: absolute; top: calc(100% + 12px); right: 0;
            width: 240px;
            background: #ffffff; border: 1px solid #e2e8f0;
            border-radius: 14px; padding: 8px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
            opacity: 0; visibility: hidden;
            transform: translateY(-10px) scale(0.95);
            transition: all 0.25s ease; z-index: 60;
        }
        .notification-dropdown { width: 360px; padding: 0; }
        .profile-dropdown.show, .notification-dropdown.show {
            opacity: 1; visibility: visible;
            transform: translateY(0) scale(1);
        }
        .profile-dropdown .dropdown-item {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 14px; border-radius: 10px;
            color: #334155; text-decoration: none; font-size: 13px;
        }
        .profile-dropdown .dropdown-item:hover { background: var(--red-soft); color: var(--primary-red); }
        .profile-dropdown .dropdown-item.danger { color: #dc2626; }

        .notification-title {
            padding: 14px 20px; font-weight: 600; font-size: 14px;
            border-bottom: 1px solid #f1f5f9; color: #0f172a;
        }
        .notification-item { padding: 12px 20px; border-bottom: 1px solid #f1f5f9; }
        .notification-item strong { display: block; font-size: 13px; margin-bottom: 2px; color: var(--primary-red); }
        .notification-item p { font-size: 12px; color: #475569; margin: 0; }
        .notification-item small { display: block; font-size: 10px; color: #94a3b8; margin-top: 4px; }

        /* CONTENT */
        .content {
            flex: 1; padding: 24px 28px 20px;
            padding-bottom: calc(20px + var(--safe-bottom));
            overflow-y: auto; background: #f8fafc;
            -webkit-overflow-scrolling: touch;
        }

        .breadcrumb {
            display: flex; align-items: center; gap: 8px;
            font-size: 12px; color: var(--text-muted);
            margin-bottom: 14px; flex-wrap: wrap;
        }
        .breadcrumb a { color: var(--text-muted); text-decoration: none; transition: color 0.15s; }
        .breadcrumb a:hover { color: var(--primary-red); }
        .breadcrumb strong { color: var(--text-primary); font-weight: 600; }
        .breadcrumb .sep { color: var(--text-muted); font-size: 10px; }

        .page-header {
            position: relative; overflow: hidden;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: var(--radius);
            padding: 20px 22px;
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 12px;
            margin-bottom: 16px;
            box-shadow: var(--sh-sm);
        }
        .page-header::before {
            content: ''; position: absolute; top: 0; left: 0; bottom: 0; width: 4px;
            background: linear-gradient(180deg, #ef4444, #b91c1c);
        }
        .page-header::after {
            content: ''; position: absolute; right: -50px; top: -50px;
            width: 180px; height: 180px; border-radius: 50%;
            background: radial-gradient(circle, rgba(220, 38, 38, 0.05), transparent 70%);
            pointer-events: none;
        }
        .page-header > * { position: relative; z-index: 1; }
        .page-header h1 {
            color: var(--text-primary);
            font-size: 18px; font-weight: 700;
            letter-spacing: -0.01em; line-height: 1.3;
            display: flex; align-items: center; gap: 10px;
            margin-bottom: 3px;
        }
        .page-header h1 i { color: var(--primary-red); font-size: 16px; }
        .page-header p { color: var(--text-muted); font-size: 13px; }
        @media (min-width: 768px) {
            .page-header { padding: 22px 26px; margin-bottom: 20px; }
            .page-header h1 { font-size: 22px; }
            .page-header p { font-size: 14px; }
        }

        /* FILTER CARD */
        .filter-card {
            background: #ffffff; border: 1px solid #e2e8f0; border-radius: var(--radius);
            padding: 16px; margin-bottom: 20px;
            box-shadow: var(--sh-sm);
        }
        @media(min-width: 768px) { .filter-card { padding: 20px; margin-bottom: 24px; } }
        .filter-form { display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end; }
        .filter-group { display: flex; flex-direction: column; gap: 6px; flex: 1 1 100%; min-width: 180px; }
        @media(min-width: 576px) { .filter-group { flex: 1 1 calc(50% - 14px); } }
        @media(min-width: 992px) { .filter-group { flex: 1; } }
        .filter-group label { font-size: 12px; font-weight: 600; color: #475569; }
        .filter-group input, .filter-group select {
            padding: 10px 14px; border-radius: 10px; background: #f8fafc;
            border: 1px solid #e2e8f0; color: #0f172a; font-size: 13px; outline: none; font-family: inherit; width: 100%;
        }
        .filter-group input:focus, .filter-group select:focus { border-color: var(--primary-red); box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1); }
        .filter-actions { display: flex; gap: 8px; flex: 1 1 100%; }
        @media(min-width: 992px) { .filter-actions { flex: 0 0 auto; } }

        .btn-filter {
            padding: 10px 18px; border-radius: 10px; background: var(--primary-red); color: #ffffff;
            font-weight: 700; border: none; cursor: pointer; display: flex; align-items: center; gap: 6px; font-size: 13px;
            flex: 1; justify-content: center; font-family: inherit;
        }
        @media(min-width: 992px) { .btn-filter { flex: 0 0 auto; } }
        .btn-filter:hover { background: var(--primary-red-dark); }

        .btn-print {
            padding: 10px 18px; border-radius: 10px; background: #0284c7; color: #fff; font-weight: 600; border: none; cursor: pointer;
            display: flex; align-items: center; gap: 6px; font-size: 13px; text-decoration: none; justify-content: center; font-family: inherit;
        }
        .btn-print:hover { background: #0369a1; color: #fff; }

        .btn-export {
            padding: 10px 18px; border-radius: 10px; background: var(--primary-red); color: #fff; font-weight: 600; border: none; cursor: pointer;
            display: flex; align-items: center; gap: 6px; font-size: 13px; text-decoration: none; justify-content: center; font-family: inherit;
        }
        .btn-export:hover { background: var(--primary-red-dark); color: #fff; }

        /* STAT GRID */
        .stat-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px; }
        @media(min-width: 768px) { .stat-grid { grid-template-columns: repeat(4, 1fr); gap: 16px; } }
        .stat-card {
            background: #ffffff; border: 1px solid #e2e8f0; border-radius: var(--radius-sm);
            padding: 14px 16px; display: flex; align-items: center; gap: 12px;
            box-shadow: var(--sh-sm);
        }
        @media(min-width: 768px) { .stat-card { padding: 18px 20px; gap: 14px; } }
        .stat-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
        @media(min-width: 768px) { .stat-icon { width: 44px; height: 44px; border-radius: 12px; font-size: 18px; } }

        .stat-icon-total { background: var(--red-soft); color: var(--primary-red); }
        .stat-icon-approved { background: #dcfce7; color: #15803d; }
        .stat-icon-pending { background: #fef3c7; color: #d97706; }
        .stat-icon-rejected { background: #fee2e2; color: #b91c1c; }

        .stat-value { font-size: 20px; font-weight: 800; color: #0f172a; line-height: 1.1; }
        @media(min-width: 768px) { .stat-value { font-size: 24px; } }
        .stat-label { font-size: 11px; color: #64748b; margin-top: 2px; }
        @media(min-width: 768px) { .stat-label { font-size: 12px; } }

        /* ANALYTICS GRID */
        .analytics-grid { display: grid; grid-template-columns: 1fr; gap: 20px; margin-bottom: 20px; }
        @media(min-width: 900px) { .analytics-grid { grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; } }
        .glass-card {
            background: #ffffff; border: 1px solid #e2e8f0; border-radius: var(--radius); padding: 16px;
            box-shadow: var(--sh-sm);
        }
        @media(min-width: 768px) { .glass-card { padding: 20px; } }
        .glass-card .card-header { font-weight: 600; font-size: 14px; margin-bottom: 16px; color: #0f172a; display: flex; align-items: center; gap: 8px; }
        @media(min-width: 768px) { .glass-card .card-header { font-size: 15px; } }
        .glass-card .card-header i { color: var(--primary-red); }
        .chart-container { position: relative; height: 240px; width: 100%; }
        @media(min-width: 768px) { .chart-container { height: 280px; } }

        /* TABLE CARD */
        .table-card {
            background: #ffffff; border: 1px solid #e2e8f0; border-radius: var(--radius); padding: 16px; margin-bottom: 20px;
            box-shadow: var(--sh-sm);
        }
        @media(min-width: 768px) { .table-card { padding: 20px; margin-bottom: 24px; } }
        .table-wrap { overflow-x: auto; margin-top: 12px; -webkit-overflow-scrolling: touch; }
        .table { width: 100%; border-collapse: collapse; font-size: 13px; white-space: nowrap; margin-bottom: 0; }
        .table > :not(caption) > * > * { background-color: transparent !important; color: #0f172a !important; box-shadow: none !important; }
        .table th { text-align: left; padding: 12px 10px; color: var(--primary-red) !important; font-weight: 600; border-bottom: 2px solid #fecdd3 !important; font-size: 11px; text-transform: uppercase; background: #f8fafc !important; }
        .table td { padding: 14px 10px !important; border-bottom: 1px solid #f1f5f9 !important; color: #334155 !important; vertical-align: middle; font-weight: 500; }
        .table tr:hover { background: #fff1f2 !important; }

        .badge { display: inline-block; padding: 4px 12px; border-radius: 40px; font-size: 10px; font-weight: 600; text-transform: uppercase; }
        .badge-warning { background: #fef3c7; color: #d97706; border: 1px solid #fde68a; }
        .badge-success { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .badge-danger { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
        .badge-secondary { background: #f3f4f6; color: #4b5563; border: 1px solid #e5e7eb; }

        .footer { margin-top: 20px; padding-top: 16px; border-top: 1px solid #e2e8f0; display: flex; flex-direction: column; gap: 6px; text-align: center; font-size: 11px; color: #64748b; }
        @media(min-width: 768px) { .footer { flex-direction: row; justify-content: space-between; text-align: left; font-size: 12px; } }
        .footer .brand { color: var(--primary-red); font-weight: 700; }

        .print-letterhead { display: none; }
        .print-signature-block { display: none; }

        /* ============================================
           RESPONSIVE
        ============================================ */
        @media (max-width: 1400px) {
            .nav-link { padding: 10px 8px; font-size: 12px; }
            .nav-link .nav-ico { font-size: 11px; }
        }

        @media (max-width: 1200px) {
            .top-navbar {
                padding: 10px 14px;
                padding-top: calc(10px + var(--safe-top));
                gap: 10px;
                flex-wrap: wrap;
                min-height: 60px;
            }
            .nav-clock { display: none; }
            .profile-btn .info { display: none; }
            .profile-btn { padding: 3px; }
            .profile-btn .chevron { display: none; }
            .menu-toggle { display: flex; }

            .search-wrapper {
                order: 5; width: 100%;
                padding: 0; margin-top: 4px; margin-bottom: 4px;
                max-width: 100%; flex-basis: 100%;
            }
            .search-container { max-width: 100%; }

            .main-nav-bar {
                position: fixed; top: 0; left: -100%;
                width: 85vw; max-width: 320px;
                height: 100vh; height: 100dvh;
                flex-direction: column;
                align-items: stretch; justify-content: flex-start;
                padding: 0;
                z-index: 2001;
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
                transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                background: #ffffff;
                box-shadow: 6px 0 25px rgba(0,0,0,0.18);
            }
            .main-nav-bar.mobile-open { left: 0 !important; }

            .mobile-drawer-header {
                display: flex; align-items: center; justify-content: space-between;
                padding: 16px 18px;
                padding-top: calc(16px + var(--safe-top));
                background: linear-gradient(90deg, #991b1b, #dc2626 55%, #991b1b);
                color: #fff;
                flex-shrink: 0;
                border-bottom: 2px solid #7f1d1d;
            }
            .mobile-drawer-header .drawer-brand { display: flex; align-items: center; gap: 10px; }
            .mobile-drawer-header .drawer-brand-logo {
                width: 32px; height: 32px; border-radius: 50%;
                background: #ffffff; color: var(--primary-red);
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
            .mobile-drawer-header .drawer-close-btn:active { background: rgba(255,255,255,0.35); }

            .main-nav-bar .nav-menu {
                flex-direction: column; width: 100%;
                justify-content: flex-start; gap: 4px;
                padding: 14px;
                padding-bottom: calc(20px + var(--safe-bottom));
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
            .profile-dropdown { width: 220px; }
        }

        @media (max-width: 768px) {
            .content {
                padding: 16px 14px;
                padding-bottom: calc(24px + var(--safe-bottom));
            }
            .page-header {
                flex-direction: column; align-items: flex-start;
                gap: 10px; margin-bottom: 16px;
                padding: 16px !important;
            }
            .page-header h1 { font-size: 18px; }
            .page-header p { font-size: 12.5px; }
            .footer { flex-direction: column; text-align: center; gap: 6px; font-size: 10.5px; }

            /* Mobile card table */
            .table-wrap { overflow-x: visible; }
            .table { min-width: 100% !important; white-space: normal; }
            .table thead { display: none; }
            .table tbody, .table tr, .table td { display: block; width: 100%; }
            .table tr {
                background: #ffffff !important;
                border: 1px solid #e2e8f0 !important;
                border-left: 3px solid var(--primary-red) !important;
                border-radius: var(--r-lg);
                margin: 0 0 12px;
                padding: 14px;
                box-shadow: var(--sh-xs);
            }
            .table tbody tr:hover { background: #ffffff !important; }
            .table td {
                display: flex; align-items: center; justify-content: space-between;
                text-align: right;
                padding: 8px 0 !important;
                border-bottom: 1px dashed #eef1f6 !important;
                white-space: normal;
                gap: 12px;
            }
            .table td:last-child { border-bottom: none !important; }
            .table td::before {
                content: attr(data-label);
                font-weight: 700; font-size: 10px;
                color: var(--text-muted);
                text-transform: uppercase; letter-spacing: 0.05em;
                text-align: left; flex-shrink: 0;
            }
        }

        @media (max-width: 480px) {
            .official-header { padding: 5px 8px; padding-top: calc(5px + var(--safe-top)); }
            .top-navbar { padding: 8px 10px; }
            .content { padding: 12px 10px 22px; padding-bottom: calc(22px + var(--safe-bottom)); }
            .page-header { padding: 14px 16px; border-radius: 12px; }
            .page-header h1 { font-size: 16px; }
            .page-header p { font-size: 11.5px; }
            .footer { font-size: 10.5px; }
        }

        @media (max-width: 767px) {
            input, select, textarea { font-size: 16px !important; }
            .search-container input { font-size: 16px !important; }
        }

        /* PRINT */
        @media print {
            @page { margin: 1.5cm; }
            body { background: #fff !important; color: #000 !important; }
            .official-header, .top-navbar, .filter-card, .btn-print, .btn-export, .footer, .menu-toggle, .breadcrumb, .sidebar-overlay { display: none !important; }
            .main-wrapper { margin: 0 !important; padding: 0 !important; width: 100% !important; overflow: visible !important; }
            .content { padding: 0 !important; }
            .glass-card, .table-card, .stat-card { background: #fff !important; border: 1px solid #ccc !important; box-shadow: none !important; color: #000 !important; }
            .table th { color: #000 !important; border-bottom: 2px solid #000 !important; }
            .table td { color: #000 !important; border-bottom: 1px solid #ddd !important; }
            .page-header { display: none !important; }
            .analytics-grid { display: none !important; }

            .print-letterhead {
                display: block !important; text-align: center; padding-bottom: 16px; margin-bottom: 20px;
                border-bottom: 3px double #000; color: #000 !important;
            }
            .print-letterhead-title { font-size: 22px; font-weight: 800; letter-spacing: 1px; }
            .print-letterhead-sub { font-size: 12px; margin-top: 2px; }
            .print-letterhead-report { font-size: 15px; font-weight: 700; margin-top: 10px; text-decoration: underline; }
            .print-letterhead-meta { display: flex; justify-content: center; gap: 24px; font-size: 11px; margin-top: 8px; flex-wrap: wrap; }

            .stat-grid { grid-template-columns: repeat(4, 1fr) !important; margin-bottom: 24px !important; }
            .stat-label, .stat-value { color: #000 !important; }
            .stat-icon { display: none !important; }
            .stat-card { padding: 10px 14px !important; }

            .table-card { page-break-inside: auto; }
            table { page-break-inside: auto; width: 100%; border-collapse: collapse; }
            thead { display: table-header-group; }
            tr { page-break-inside: avoid; page-break-after: auto; }
            .table td, .table th { font-size: 11px; padding: 8px 6px; }
            .badge { border: 1px solid #999 !important; background: #fff !important; color: #000 !important; }

            .print-signature-block {
                display: flex !important; justify-content: space-between; margin-top: 60px; padding-top: 0;
            }
            .print-signature-block .sig-line { width: 220px; text-align: center; }
            .print-signature-block .sig-line .line { border-top: 1px solid #000; margin-bottom: 4px; padding-top: 4px; }
            .print-signature-block .sig-line .role { font-size: 11px; }
        }
    </style>
</head>
<body>

    <form id="logoutForm" action="../logout.php" method="POST" style="display:none;"></form>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="page-container">
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
                        <button class="menu-toggle" id="menuToggle" aria-label="Toggle Menu"><i class="fa-solid fa-bars"></i></button>
                        <div class="nav-brand">
                            <div class="brand-logo">
                                <div class="brand-logo-ring"></div>
                                <div class="brand-logo-circle">RT</div>
                            </div>
                            <div class="brand-text">
                                <h2>RENTRACK</h2>
                                <span>LGU Staff</span>
                            </div>
                        </div>
                    </div>

                    <nav class="main-nav-bar" id="mainNavBar">
                        <div class="mobile-drawer-header">
                            <div class="drawer-brand">
                                <div class="drawer-brand-logo">RT</div>
                                <div class="drawer-brand-text">
                                    <h2>RENTRACK</h2>
                                    <span>LGU Staff</span>
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
                                    <i class="fa-solid fa-folder-open nav-ico"></i> Rental Management
                                    <i class="fa-solid fa-chevron-down chevron"></i>
                                </button>
                                <div class="nav-dropdown">
                                    <a href="rentals.php"><i class="fa-solid fa-folder-open"></i> Rental Applications</a>
                                    <a href="add_rental.php"><i class="fa-solid fa-plus-circle"></i> Register Rental</a>
                                    <a href="documents.php"><i class="fa-solid fa-file-lines"></i> Documents</a>
                                    <a href="renewals_approval.php">
                                        <i class="fa-solid fa-file-circle-exclamation"></i> Permit Renewals
                                        <?php if ($pendingRenewalsCount > 0): ?>
                                            <span class="menu-badge"><?php echo $pendingRenewalsCount; ?></span>
                                        <?php endif; ?>
                                    </a>
                                    <a href="reports.php"><i class="fa-solid fa-file-invoice"></i> Reports &amp; Analytics</a>
                                </div>
                            </li>

                            <li class="nav-item has-dropdown">
                                <button type="button" class="nav-link" data-dropdown>
                                    <i class="fa-solid fa-clipboard-check nav-ico"></i> Inspections
                                    <i class="fa-solid fa-chevron-down chevron"></i>
                                </button>
                                <div class="nav-dropdown">
                                    <a href="inspections.php"><i class="fa-solid fa-clipboard-check"></i> My Inspections</a>
                                    <a href="inspection_schedule.php"><i class="fa-solid fa-calendar-days"></i> Inspection Schedule</a>
                                </div>
                            </li>

                            <!-- ✅ Inalis na ang Collections at Remittances dito -->
                            <li class="nav-item has-dropdown">
                                <button type="button" class="nav-link" data-dropdown>
                                    <i class="fa-solid fa-money-check-dollar nav-ico"></i> Financials
                                    <i class="fa-solid fa-chevron-down chevron"></i>
                                </button>
                                <div class="nav-dropdown">
                                    <a href="payments.php"><i class="fa-solid fa-money-check-dollar"></i> Payments</a>
                                </div>
                            </li>
                        </ul>
                    </nav>

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
                            <button class="nav-icon" id="notifBtn" aria-label="Notifications">
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
                                <i class="fa-solid fa-chevron-down chevron" style="color: #64748b;"></i>
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
            </div>

            <main class="content">
                <div class="breadcrumb">
                    <a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
                    <span class="sep">/</span>
                    <strong>Reports &amp; Analytics</strong>
                </div>

                <div class="print-letterhead">
                    <div class="print-letterhead-title">RENTRACK LGU</div>
                    <div class="print-letterhead-sub">Unified Rental Registration, Tracking &amp; Compliance System</div>
                    <div class="print-letterhead-report">Transaction &amp; Rental Report</div>
                    <div class="print-letterhead-meta">
                        <span>Period Covered: <strong><?php echo date('M d, Y', strtotime($startDate)); ?> &ndash; <?php echo date('M d, Y', strtotime($endDate)); ?></strong></span>
                        <span>Status Filter: <strong><?php echo htmlspecialchars($reportType === 'all' ? 'All Status' : $reportType); ?></strong></span>
                        <span>Generated: <strong><?php echo date('M d, Y h:i A'); ?></strong> by <strong><?php echo htmlspecialchars($staffName); ?></strong></span>
                    </div>
                </div>

                <div class="page-header">
                    <div>
                        <h1><i class="fa-solid fa-file-invoice"></i> Reports &amp; Analytics</h1>
                        <p>Generate transaction records, view compliance status metrics, and export data for transparency.</p>
                    </div>
                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        <button onclick="window.print();" class="btn-print"><i class="fa-solid fa-print"></i> Print Report</button>
                        <a href="reports.php?start_date=<?php echo urlencode($startDate); ?>&end_date=<?php echo urlencode($endDate); ?>&report_type=<?php echo urlencode($reportType); ?>&export=csv" class="btn-export"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
                    </div>
                </div>

                <div class="filter-card">
                    <form method="GET" action="reports.php" class="filter-form">
                        <div class="filter-group">
                            <label for="start_date">Start Date</label>
                            <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>">
                        </div>
                        <div class="filter-group">
                            <label for="end_date">End Date</label>
                            <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>">
                        </div>
                        <div class="filter-group">
                            <label for="report_type">Application Status</label>
                            <select id="report_type" name="report_type">
                                <option value="all" <?php echo $reportType === 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="Pending" <?php echo $reportType === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="Approved" <?php echo $reportType === 'Approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="Rejected" <?php echo $reportType === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                        </div>
                        <div class="filter-actions">
                            <button type="submit" class="btn-filter"><i class="fa-solid fa-filter"></i> Apply Filter</button>
                        </div>
                    </form>
                </div>

                <div class="stat-grid">
                    <div class="stat-card">
                        <div class="stat-icon stat-icon-total"><i class="fa-solid fa-layer-group"></i></div>
                        <div class="stat-info">
                            <div class="stat-value"><?php echo $totalAppsInRange; ?></div>
                            <div class="stat-label">Total Applications</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon stat-icon-approved"><i class="fa-solid fa-circle-check"></i></div>
                        <div class="stat-info">
                            <div class="stat-value"><?php echo $approvedInRange; ?></div>
                            <div class="stat-label">Approved</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon stat-icon-pending"><i class="fa-solid fa-clock"></i></div>
                        <div class="stat-info">
                            <div class="stat-value"><?php echo $pendingInRange; ?></div>
                            <div class="stat-label">Pending</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon stat-icon-rejected"><i class="fa-solid fa-circle-xmark"></i></div>
                        <div class="stat-info">
                            <div class="stat-value"><?php echo $rejectedInRange; ?></div>
                            <div class="stat-label">Rejected</div>
                        </div>
                    </div>
                </div>

                <div class="analytics-grid">
                    <div class="glass-card">
                        <div class="card-header"><i class="fa-solid fa-chart-column"></i> Application Status Distribution (Filtered)</div>
                        <div class="chart-container"><canvas id="statusDistChart"></canvas></div>
                    </div>
                    <div class="glass-card">
                        <div class="card-header"><i class="fa-solid fa-chart-line"></i> Monthly Revenue &amp; Collection Trend (<?php echo date('Y'); ?>)</div>
                        <div class="chart-container"><canvas id="revenueTrendChart"></canvas></div>
                    </div>
                </div>

                <div class="table-card">
                    <div class="card-header" style="font-weight:600; font-size:15px; color:#0f172a; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                        <span><i class="fa-solid fa-table-list" style="color:var(--primary-red);"></i> Transaction &amp; Rental Records Log</span>
                        <span style="font-size:12px; color:#64748b;">Total Records Found: <strong><?php echo count($reportRows); ?></strong></span>
                    </div>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Tracking No.</th>
                                    <th>Owner Name</th>
                                    <th>Business Name</th>
                                    <th>Rental Type</th>
                                    <th>Status</th>
                                    <th>RPT Status</th>
                                    <th>Date Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($reportRows)): ?>
                                    <?php foreach ($reportRows as $row): ?>
                                        <?php
                                            $st = $row['status'] ?? 'Draft';
                                            $badgeClass = 'badge-secondary';
                                            if ($st === 'Pending') $badgeClass = 'badge-warning';
                                            elseif ($st === 'Approved') $badgeClass = 'badge-success';
                                            elseif ($st === 'Rejected') $badgeClass = 'badge-danger';
                                        ?>
                                        <tr>
                                            <td data-label="Tracking No."><strong style="color:var(--primary-red);"><?php echo htmlspecialchars($row['tracking_no']); ?></strong></td>
                                            <td data-label="Owner Name"><?php echo htmlspecialchars($row['owner_name'] ?? 'N/A'); ?></td>
                                            <td data-label="Business Name"><?php echo htmlspecialchars($row['business_name']); ?></td>
                                            <td data-label="Rental Type"><?php echo htmlspecialchars($row['type_name'] ?? 'General'); ?></td>
                                            <td data-label="Status"><span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($st); ?></span></td>
                                            <td data-label="RPT Status">
                                                <?php if (($row['rpt_status'] ?? '') === 'Paid'): ?>
                                                    <span class="badge badge-success">Paid</span>
                                                <?php else: ?>
                                                    <span class="badge badge-warning">Unpaid</span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Date Created" style="color:#64748b;"><?php echo date("M d, Y h:i A", strtotime($row['created_at'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="7" style="text-align:center; padding:30px; color:#64748b;">No transaction records found for the selected range or filter.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="print-signature-block">
                    <div class="sig-line">
                        <div class="line"><?php echo htmlspecialchars($staffName); ?></div>
                        <div class="role"><?php echo htmlspecialchars($staffRole); ?><br>Prepared by</div>
                    </div>
                    <div class="sig-line">
                        <div class="line">&nbsp;</div>
                        <div class="role">Noted by</div>
                    </div>
                </div>

                <footer class="footer">
                    <span>&copy; <?php echo date('Y'); ?> <span class="brand">RENTRACK LGU</span></span>
                    <span>Unified Rental Registration, Tracking &amp; Compliance System</span>
                </footer>
            </main>
        </div>
    </div>

    <div class="modal fade" id="searchModal" tabindex="-1" aria-labelledby="searchModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="searchModalLabel">
                        <i class="fa-solid fa-magnifying-glass"></i> Global System Search
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div style="position: relative; margin-bottom: 20px;">
                        <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 18px; top: 50%; transform: translateY(-50%); color: var(--primary-red); font-size: 16px;"></i>
                        <input type="text" id="modalSearchInput" class="form-control" placeholder="Search by tracking number, business name, or owner..." autocomplete="off" style="padding: 14px 16px 14px 48px; border-radius: 14px; border: 1.5px solid #e2e8f0; background: #f8fafc; font-size: 14px; outline: none;">
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
        /* ============================================
           NAV MENU
        ============================================ */
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

        menuToggle.addEventListener('click', openDrawer);
        sidebarOverlay.addEventListener('click', closeDrawer);
        drawerCloseBtn?.addEventListener('click', closeDrawer);

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeDrawer();
                document.querySelectorAll('.nav-item.has-dropdown.open').forEach(function(item) {
                    item.classList.remove('open');
                });
            }
        });

        document.querySelectorAll('.nav-item.has-dropdown > .nav-link').forEach(function(trigger) {
            trigger.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const parent = trigger.parentElement;
                const wasOpen = parent.classList.contains('open');
                document.querySelectorAll('.nav-item.has-dropdown.open').forEach(function(item) {
                    if (item !== parent) item.classList.remove('open');
                });
                parent.classList.toggle('open', !wasOpen);
            });
        });

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.nav-item.has-dropdown')) {
                document.querySelectorAll('.nav-item.has-dropdown.open').forEach(function(item) {
                    item.classList.remove('open');
                });
            }
        });

        mainNavBar.querySelectorAll('.nav-dropdown a, .nav-link:not([data-dropdown])').forEach(function(link) {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 1200) closeDrawer();
            });
        });

        window.addEventListener('resize', function() {
            if (window.innerWidth > 1200) closeDrawer();
        });

        /* ============================================
           CLOCK
        ============================================ */
        setInterval(() => {
            const d = new Date();
            let h = d.getHours(), m = d.getMinutes(), ampm = h >= 12 ? 'PM' : 'AM';
            h = h % 12 || 12;
            const clockEl = document.getElementById('clockTime');
            if (clockEl) clockEl.textContent = `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')} ${ampm}`;
        }, 1000);

        /* ============================================
           NOTIFICATIONS + PROFILE
        ============================================ */
        const notifBtn = document.getElementById('notifBtn');
        const notifDropdown = document.getElementById('notifDropdown');
        const profileToggle = document.getElementById('profileToggle');
        const profileDropdown = document.getElementById('profileDropdown');

        notifBtn?.addEventListener('click', (e) => {
            e.stopPropagation();
            profileDropdown?.classList.remove('show');
            notifDropdown.classList.toggle('show');
            if (notifDropdown.classList.contains('show')) {
                fetch('reports.php?ajax_action=read_notif')
                    .then(r => r.text())
                    .then(data => {
                        if (data === 'success') {
                            const badge = notifBtn.querySelector('.badge');
                            if (badge) badge.style.display = 'none';
                        }
                    });
            }
        });

        profileToggle?.addEventListener('click', (e) => {
            e.stopPropagation();
            notifDropdown?.classList.remove('show');
            profileDropdown.classList.toggle('show');
        });

        window.addEventListener('click', () => {
            notifDropdown?.classList.remove('show');
            profileDropdown?.classList.remove('show');
        });

        /* ============================================
           SEARCH MODAL
        ============================================ */
        const searchModalElement = document.getElementById('searchModal');
        const modalSearchInput = document.getElementById('modalSearchInput');
        const modalSearchResults = document.getElementById('modalSearchResults');

        if (searchModalElement) {
            searchModalElement.addEventListener('shown.bs.modal', function () {
                modalSearchInput.focus();
            });

            let searchDebounce;
            modalSearchInput.addEventListener('input', function() {
                clearTimeout(searchDebounce);
                let query = this.value.trim();
                if (query.length < 2) {
                    modalSearchResults.innerHTML = `<div style="text-align: center; padding: 40px 20px; color: #64748b; font-size: 13px;"><i class="fa-regular fa-keyboard" style="font-size: 28px; margin-bottom: 10px; display: block; opacity: 0.6; color: var(--primary-red);"></i>Type at least 2 characters to begin searching records...</div>`;
                    return;
                }
                searchDebounce = setTimeout(() => {
                    fetch('reports.php?ajax_action=search&q=' + encodeURIComponent(query))
                        .then(res => res.text())
                        .then(html => {
                            if (html.trim() !== '') modalSearchResults.innerHTML = html;
                            else modalSearchResults.innerHTML = `<div style="text-align: center; padding: 30px; color: #64748b; font-size: 13px;"><i class="fa-solid fa-folder-open" style="font-size: 24px; margin-bottom: 8px; display: block; opacity: 0.5;"></i> No matching results found.</div>`;
                        });
                }, 250);
            });
        }

        /* ============================================
           LOGOUT
        ============================================ */
        function confirmLogout() {
            Swal.fire({
                title: 'Logout', text: 'Are you sure you want to end your session?', icon: 'warning',
                showCancelButton: true, confirmButtonColor: '#dc2626', cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Logout'
            }).then((res) => { if (res.isConfirmed) document.getElementById('logoutForm').submit(); });
        }
        document.getElementById('profileLogoutBtn')?.addEventListener('click', (e) => { e.preventDefault(); confirmLogout(); });

        /* ============================================
           CHARTS
        ============================================ */
        document.addEventListener('DOMContentLoaded', function() {
            const ctxDist = document.getElementById('statusDistChart').getContext('2d');
            new Chart(ctxDist, {
                type: 'bar',
                data: {
                    labels: ['Pending', 'Approved', 'Rejected'],
                    datasets: [{
                        label: 'Applications',
                        data: [<?php echo $pendingInRange; ?>, <?php echo $approvedInRange; ?>, <?php echo $rejectedInRange; ?>],
                        backgroundColor: ['#f59e0b', '#16a34a', '#ef4444'],
                        borderRadius: 8
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false }, ticks: { color: '#64748b' } }, y: { beginAtZero: true, grid: { color: 'rgba(0, 0, 0, 0.05)' }, ticks: { color: '#64748b', stepSize: 1 } } } }
            });

            const ctxTrend = document.getElementById('revenueTrendChart').getContext('2d');
            new Chart(ctxTrend, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($monthlyLabels); ?>,
                    datasets: [{
                        label: 'Revenue (PHP)',
                        data: <?php echo json_encode($monthlyCollections); ?>,
                        fill: true, backgroundColor: 'rgba(220, 38, 38, 0.08)', borderColor: '#dc2626', borderWidth: 3, tension: 0.4, pointRadius: 4
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false }, ticks: { color: '#64748b' } }, y: { beginAtZero: true, grid: { color: 'rgba(0, 0, 0, 0.05)' }, ticks: { color: '#64748b' } } } }
            });
        });
    </script>
</body>
</html>