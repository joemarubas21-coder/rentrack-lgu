<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();
date_default_timezone_set('Asia/Manila');

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

function getInspectionTypeLabel($type) {
    if (empty($type)) return '—';
    $type = trim($type);
    $types = [
        'Initial'       => 'Initial Inspection',
        'Annual'        => 'Annual Inspection',
        'Follow-up'     => 'Follow-up Inspection',
        'Complaint'     => 'Complaint Inspection',
        'Re-inspection' => 'Re-inspection',
    ];
    return $types[$type] ?? ucfirst(str_replace('_', ' ', $type));
}

function getInspectionCategoryLabel($category) {
    if (empty($category)) return '—';
    $category = strtolower(trim($category));
    $categories = [
        'building'    => 'Building Inspection',
        'fire_safety' => 'Fire Safety & Hazard',
        'structural'  => 'Structural Assessment',
        'electrical'  => 'Electrical Safety',
        'sanitary'    => 'Sanitary Inspection',
        'zoning'      => 'Zoning Compliance',
        'occupancy'   => 'Occupancy Clearance',
        'health'      => 'Health & Sanitary',
    ];
    return $categories[$category] ?? ucfirst(str_replace('_', ' ', $category));
}

$hasInspectionCategory = columnExists($conn, 'inspections', 'inspection_category');
$hasRemarks = columnExists($conn, 'inspections', 'remarks');

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

                    echo '<a href="view_rental.php?id=' . $row['id'] . '" style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 12px; color: #111827; text-decoration: none; gap: 10px;">';
                    echo '<div style="display: flex; flex-direction: column; gap: 4px; min-width: 0; flex: 1;">';
                    echo '<div style="font-weight: 600; font-size: 13px; color: #dc2626; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">' . htmlspecialchars($row['tracking_no']) . ' • ' . htmlspecialchars($row['business_name']) . '</div>';
                    echo '<div style="font-size: 11px; color: #4b5563;">Owner: ' . htmlspecialchars($row['owner_name'] ?? 'N/A') . '</div>';
                    echo '</div>';
                    echo '<span style="padding: 4px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; background: ' . $badgeBg . '; color: ' . $badgeColor . '; text-transform: uppercase; flex-shrink: 0;">' . htmlspecialchars($status) . '</span>';
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
$staffId = (int) ($_SESSION['user_id'] ?? 0);
$staffInitial = strtoupper(substr(trim($staffName), 0, 1));

$profilePicture = '';
if (columnExists($conn, 'users', 'profile_picture')) {
    $userProfileQuery = $conn->prepare("SELECT profile_picture FROM users WHERE id = :id LIMIT 1");
    $userProfileQuery->execute([':id' => $staffId]);
    $profilePicture = $userProfileQuery->fetchColumn() ?: '';
}

// ==========================
// CALENDAR PARAMETERS
// ==========================
$currentMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$currentYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

if ($currentMonth < 1) $currentMonth = 1;
if ($currentMonth > 12) $currentMonth = 12;
if ($currentYear < 2000) $currentYear = 2000;
if ($currentYear > 2100) $currentYear = 2100;

$prevMonth = $currentMonth - 1; $prevYear = $currentYear;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
$nextMonth = $currentMonth + 1; $nextYear = $currentYear;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

$startDate = date('Y-m-01', strtotime("$currentYear-$currentMonth-01"));
$endDate = date('Y-m-t', strtotime("$currentYear-$currentMonth-01"));
$monthName = date('F Y', strtotime("$currentYear-$currentMonth-01"));
$today = date('Y-m-d');

// ==========================
// FETCH MONTHLY INSPECTIONS
// ==========================
$inspections = [];
$inspectionsByDate = [];

if (tableExists($conn, 'inspections') && tableExists($conn, 'rentals')) {
    $selectFields = "i.id, i.inspection_no, i.inspection_status, i.inspection_date, i.inspection_time, i.inspector_id";
    if (columnExists($conn, 'inspections', 'inspection_type')) $selectFields .= ", i.inspection_type";
    if ($hasInspectionCategory) $selectFields .= ", i.inspection_category";
    if ($hasRemarks) $selectFields .= ", i.remarks";

    $sql = "SELECT $selectFields, 
                   r.id AS rental_id, r.business_name, r.rental_name, r.tracking_no, r.barangay, 
                   u.fullname AS owner_name, u2.fullname AS inspector_name
            FROM inspections i
            INNER JOIN rentals r ON i.rental_id = r.id
            LEFT JOIN users u ON r.user_id = u.id
            LEFT JOIN users u2 ON i.inspector_id = u2.id
            WHERE r.is_deleted = FALSE
            AND i.inspection_date BETWEEN :sdate AND :edate
            ORDER BY i.inspection_date ASC, i.inspection_time ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute([':sdate' => $startDate, ':edate' => $endDate]);
    $inspections = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($inspections as $ins) {
        $date = $ins['inspection_date'] ?? '';
        if (!isset($inspectionsByDate[$date])) $inspectionsByDate[$date] = [];
        $inspectionsByDate[$date][] = $ins;
    }
}

// ==========================
// FETCH UPCOMING INSPECTIONS
// ==========================
$upcomingInspections = [];
if (tableExists($conn, 'inspections') && tableExists($conn, 'rentals')) {
    $selectFields = "i.id, i.inspection_no, i.inspection_status, i.inspection_date, i.inspection_time, i.inspector_id";
    if (columnExists($conn, 'inspections', 'inspection_type')) $selectFields .= ", i.inspection_type";
    if ($hasInspectionCategory) $selectFields .= ", i.inspection_category";

    $sql = "SELECT $selectFields, 
                   r.id AS rental_id, r.business_name, r.rental_name, r.tracking_no, r.barangay,
                   u.fullname AS owner_name, u2.fullname AS inspector_name
            FROM inspections i
            INNER JOIN rentals r ON i.rental_id = r.id
            LEFT JOIN users u ON r.user_id = u.id
            LEFT JOIN users u2 ON i.inspector_id = u2.id
            WHERE r.is_deleted = FALSE
            AND i.inspection_date >= CURRENT_DATE
            ORDER BY i.inspection_date ASC, i.inspection_time ASC
            LIMIT 15";

    try {
        $stmtUp = $conn->prepare($sql);
        $stmtUp->execute();
        $upcomingInspections = $stmtUp->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $sql = str_replace('CURRENT_DATE', 'CURDATE()', $sql);
        $stmtUp = $conn->prepare($sql);
        $stmtUp->execute();
        $upcomingInspections = $stmtUp->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ==========================
// MONTHLY STATS
// ==========================
$statsThisMonth = 0;
$statsPending = 0;
$statsCompleted = 0;
$statsFailed = 0;

foreach ($inspections as $ins) {
    $statsThisMonth++;
    $status = strtolower($ins['inspection_status'] ?? 'pending');
    if ($status === 'passed' || $status === 'completed') $statsCompleted++;
    elseif ($status === 'failed') $statsFailed++;
    else $statsPending++;
}

// ==========================
// PENDING RENEWALS COUNT
// ==========================
$pendingRenewalsCount = 0;
if (tableExists($conn, 'permit_renewals')) {
    $pendRenewalsQuery = $conn->query("SELECT COUNT(*) FROM permit_renewals WHERE status::text = 'Pending'");
    if ($pendRenewalsQuery) $pendingRenewalsCount = (int) $pendRenewalsQuery->fetchColumn();
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

// ==========================
// NOTIFICATIONS
// ==========================
$notificationCount = 0;
$notifications = [];
if (tableExists($conn, 'notifications')) {
    $notifStmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE (user_id = :sid OR user_id = 0 OR user_id IS NULL) AND is_read = FALSE");
    $notifStmt->execute([':sid' => $staffId]);
    $notificationCount = (int) $notifStmt->fetchColumn();

    $notifListStmt = $conn->prepare("SELECT id, title, message, created_at FROM notifications WHERE (user_id = :sid OR user_id = 0 OR user_id IS NULL) ORDER BY created_at DESC LIMIT 5");
    $notifListStmt->execute([':sid' => $staffId]);
    $notifications = $notifListStmt->fetchAll(PDO::FETCH_ASSOC);
}

$firstDayOfMonth = date('N', strtotime("$currentYear-$currentMonth-01"));
$daysInMonth = date('t', strtotime("$currentYear-$currentMonth-01"));

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$flashMessage = $_SESSION['flash_message'] ?? null;
$flashText = $_SESSION['flash_text'] ?? null;
unset($_SESSION['flash_message'], $_SESSION['flash_text']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover" />
    <meta name="theme-color" content="#dc2626">
    <title>Inspection Schedule | RENTRACK LGU</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

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

            --ok: #059669; --ok-bg: #ecfdf5; --ok-bd: #a7f3d0;
            --warn: #b45309; --warn-bg: #fffbeb; --warn-bd: #fde68a;
            --danger: #b91c1c; --danger-bg: #fef2f2; --danger-bd: #fecaca;
            --info: #1d4ed8; --info-bg: #eff6ff; --info-bd: #dbeafe;
            --purple: #6d28d9; --purple-bg: #f5f3ff; --purple-bd: #ddd6fe;

            --r-xs: 6px; --r-sm: 8px; --r-md: 10px; --r-lg: 12px; --r-xl: 16px;
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

        @keyframes borderSpin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        html, body {
            margin: 0 !important; padding: 0 !important; border: 0 !important;
            min-height: 100%; font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: var(--bg-primary); color: var(--text-primary);
            overflow-x: hidden; -webkit-text-size-adjust: 100%;
            -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale;
        }

        * { scrollbar-width: thin; scrollbar-color: rgba(220, 38, 38, 0.45) #f1f5f9; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 20px; }
        ::-webkit-scrollbar-thumb { background: var(--primary-red); border-radius: 20px; }

        body.drawer-open { overflow: hidden !important; touch-action: none; }

        body {
            min-height: 100vh; min-height: 100dvh;
            display: flex; flex-direction: column;
        }

        .page-container {
            display: flex; flex-direction: column; flex: 1;
            min-height: 100vh; min-height: 100dvh;
            width: 100%;
            margin: 0 !important; padding: 0 !important;
            padding-left: var(--safe-left); padding-right: var(--safe-right);
        }

        /* OFFICIAL HEADER */
        .official-header {
            width: 100%; display: flex; align-items: center; justify-content: space-between;
            background: linear-gradient(90deg, #991b1b, #dc2626 55%, #991b1b);
            border-bottom: 2px solid #7f1d1d;
            padding: 6px 20px; padding-top: calc(6px + var(--safe-top));
            gap: 20px; position: relative; z-index: 110;
            flex-shrink: 0;
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

        .main-wrapper {
            flex: 1; display: flex; flex-direction: column;
            min-height: 100vh; min-height: 100dvh;
            margin: 0 !important; padding: 0 !important;
            background: #f8fafc;
        }

        .sticky-header {
            position: sticky; top: 0;
            z-index: 2000; /* ✅ UPDATED: Mas mataas kaysa overlay (1999) */
            background: #ffffff;
        }

        .top-navbar {
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 18px; background: #ffffff;
            border-bottom: 1px solid #e2e8f0;
            margin: 0 !important; gap: 14px;
            flex-wrap: nowrap; min-height: 58px;
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
            display: none; width: 42px; height: 42px; border-radius: 10px;
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
            display: flex; align-items: center; gap: 2px; flex-wrap: nowrap;
        }

        .nav-item { position: relative; }

        .nav-link {
            display: flex; align-items: center; gap: 6px;
            padding: 10px 10px; color: #334155; text-decoration: none;
            font-size: 12.5px; font-weight: 600; border-radius: 8px;
            transition: all 0.15s ease; cursor: pointer;
            white-space: nowrap; background: transparent;
            border: none; font-family: inherit;
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
        @keyframes pulseBadge { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }

        .nav-dropdown {
            position: absolute; top: calc(100% + 6px); left: 50%;
            transform: translateX(-50%) translateY(8px);
            min-width: 250px; background: #ffffff;
            border: 1px solid #e2e8f0; border-radius: 12px;
            box-shadow: 0 12px 30px rgba(0,0,0,0.10);
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
            border-radius: 999px; border: 1px solid #e2e8f0;
            background: #f8fafc; color: #0f172a;
            font-size: 13px; outline: none;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }
        .search-container input::placeholder { color: #94a3b8; }
        .search-container:hover input { border-color: var(--primary-red); background: var(--red-soft); }

        .navbar-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .nav-clock {
            display: flex; flex-direction: column; align-items: flex-end;
            padding: 3px 14px; border-radius: 10px;
            background: var(--red-soft); border: 1px solid var(--primary-red-light);
        }
        .nav-clock .time { font-family: 'JetBrains Mono', monospace; font-size: 13px; font-weight: 700; color: var(--primary-red); }
        .nav-clock .date { font-size: 10px; color: #64748b; }

        .nav-icon {
            width: 38px; height: 38px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            background: #ffffff; border: 1px solid #e2e8f0;
            color: var(--primary-red); cursor: pointer;
            position: relative; flex-shrink: 0;
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
            color: #ffffff; font-weight: 800; font-size: 13px; overflow: hidden;
        }
        .profile-btn .avatar img { width: 100%; height: 100%; object-fit: cover; }
        .profile-btn .info .name { font-size: 12px; font-weight: 600; color: #0f172a; }
        .profile-btn .info .role { font-size: 9px; color: var(--primary-red); font-weight: 700; text-transform: uppercase; }

        .profile-dropdown, .notification-dropdown {
            position: absolute; top: calc(100% + 12px); right: 0;
            width: 240px; background: #ffffff; border: 1px solid #e2e8f0;
            border-radius: 14px; padding: 8px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
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
        .profile-dropdown .dropdown-item:hover { background: var(--red-soft); color: var(--primary-red); }
        .profile-dropdown .dropdown-item.danger { color: #dc2626; }

        .notification-title { padding: 14px 20px; font-weight: 600; font-size: 14px; border-bottom: 1px solid #f1f5f9; color: #0f172a; }
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
            display: flex; flex-direction: column;
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
            background: #ffffff; border: 1px solid #e2e8f0;
            border-radius: var(--radius); padding: 20px 22px;
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 12px; margin-bottom: 16px;
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
            color: var(--text-primary); font-size: 18px; font-weight: 700;
            letter-spacing: -0.01em; line-height: 1.3;
            display: flex; align-items: center; gap: 10px; margin-bottom: 3px;
        }
        .page-header h1 i { color: var(--primary-red); font-size: 16px; }
        .page-header p { color: var(--text-muted); font-size: 13px; }
        @media (min-width: 768px) {
            .page-header { padding: 22px 26px; margin-bottom: 20px; }
            .page-header h1 { font-size: 22px; }
            .page-header p { font-size: 14px; }
        }

        .page-header-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

        .btn-schedule-view {
            background: var(--red-soft); border: 1px solid var(--primary-red-light);
            border-radius: 999px; padding: 8px 16px;
            font-size: 12px; color: var(--primary-red);
            display: flex; align-items: center; gap: 8px;
            text-decoration: none; font-weight: 600;
            transition: var(--transition);
        }
        .btn-schedule-view:hover { background: var(--primary-red); color: #ffffff; }

        .date-badge {
            background: var(--red-soft); border: 1px solid var(--primary-red-light);
            border-radius: 999px; padding: 8px 14px;
            font-size: 12px; color: var(--primary-red);
            display: flex; align-items: center; gap: 8px;
            font-weight: 600; white-space: nowrap;
        }
        .date-badge i { color: var(--primary-red); font-size: 12px; }

        /* STATS */
        .stats-grid { display: grid; grid-template-columns: 1fr; gap: 12px; margin-bottom: 20px; }
        @media (min-width: 576px) { .stats-grid { grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; } }

        .stat-card {
            display: flex; align-items: center; justify-content: space-between;
            border-radius: var(--r-lg); padding: 16px 18px;
            transition: var(--transition); box-shadow: 0 4px 12px rgba(0,0,0,0.03);
            color: #fff; position: relative; overflow: hidden;
        }
        @media (min-width: 768px) { .stat-card { padding: 18px 20px; } }
        .stat-card::after {
            content: ''; position: absolute; top: -20px; right: -20px;
            width: 100px; height: 100px; border-radius: 50%;
            background: rgba(255,255,255,0.15); pointer-events: none;
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 20px rgba(0,0,0,0.08); }

        .stat-card.bg-total { background: linear-gradient(135deg, #dc2626, #991b1b); }
        .stat-card.bg-pending { background: linear-gradient(135deg, #d97706, #f59e0b); }
        .stat-card.bg-completed { background: linear-gradient(135deg, #2563eb, #3b82f6); }
        .stat-card.bg-failed { background: linear-gradient(135deg, #64748b, #475569); }

        .stat-card .label {
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.5px; color: rgba(255,255,255,0.9);
            margin-bottom: 4px; display: block;
        }
        .stat-card .value { font-size: 22px; font-weight: 800; color: #fff; }
        @media (min-width: 768px) { .stat-card .value { font-size: 26px; } }
        .stat-card i { font-size: 22px; color: rgba(255,255,255,0.9); z-index: 1; }
        @media (min-width: 768px) { .stat-card i { font-size: 24px; } }

        /* CALENDAR SECTION */
        .calendar-section {
            display: grid; grid-template-columns: 1fr 340px; gap: 18px; margin-bottom: 20px;
        }
        @media (max-width: 992px) { .calendar-section { grid-template-columns: 1fr; } }

        .calendar-card {
            background: #ffffff; border: 1px solid #e2e8f0; border-radius: var(--radius);
            padding: 18px; box-shadow: var(--sh-sm);
        }

        .calendar-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 16px; padding-bottom: 10px; border-bottom: 1px solid #f1f5f9;
        }
        .calendar-header .month-title { font-size: 16px; font-weight: 800; color: #0f172a; }
        .calendar-header .nav-btn {
            background: #f8fafc; border: 1px solid #e2e8f0; color: var(--primary-red);
            width: 36px; height: 36px; border-radius: 10px; cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center;
            transition: var(--transition); text-decoration: none;
        }
        .calendar-header .nav-btn:hover { background: var(--primary-red); color: #fff; border-color: var(--primary-red); }

        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
        .day-header {
            text-align: center; font-size: 11px; font-weight: 800; color: #64748b;
            text-transform: uppercase; padding: 6px 0;
        }
        .day-cell {
            aspect-ratio: 1; border: 1px solid #f1f5f9; border-radius: 10px; padding: 6px;
            background: #f8fafc; position: relative; cursor: pointer; transition: var(--transition);
            min-height: 44px;
        }
        .day-cell:hover { border-color: var(--primary-red); background: #fff1f2; }
        .day-cell .day-number { font-size: 12px; font-weight: 700; color: #334155; }
        .day-cell.today { background: var(--red-soft); border-color: var(--primary-red); }
        .day-cell.today .day-number { color: var(--primary-red); font-weight: 800; }
        .day-cell.other-month { opacity: 0.3; background: #ffffff; cursor: default; }
        .day-cell.has-inspection { background: var(--red-soft); border-color: var(--primary-red-light); }
        .day-cell .inspection-count {
            position: absolute; bottom: 4px; right: 6px; font-size: 10px; font-weight: 800;
            background: var(--primary-red); color: #fff; padding: 1px 6px; border-radius: 999px;
        }

        .upcoming-panel {
            background: #ffffff; border: 1px solid #e2e8f0; border-radius: var(--radius);
            padding: 18px; box-shadow: var(--sh-sm);
        }
        .panel-title {
            font-size: 15px; font-weight: 800; color: #0f172a;
            margin-bottom: 12px; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px;
            display: flex; align-items: center; gap: 6px;
        }
        .panel-title i { color: var(--primary-red); }
        .upcoming-list {
            display: flex; flex-direction: column; gap: 10px;
            max-height: 520px; overflow-y: auto; padding-right: 4px;
        }
        .upcoming-list::-webkit-scrollbar { width: 5px; }
        .upcoming-list::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 999px; }
        .upcoming-item {
            padding: 10px 12px; background: #f8fafc;
            border-left: 3px solid var(--primary-red);
            border-radius: 8px; font-size: 12px;
        }
        .upcoming-item .item-date { font-size: 10px; font-weight: 800; color: var(--primary-red); text-transform: uppercase; }
        .upcoming-item .item-name { font-weight: 700; color: #0f172a; margin: 2px 0; font-size: 12.5px; }
        .upcoming-item .item-detail { color: #64748b; font-size: 11px; }

        .status-badge { padding: 3px 8px; border-radius: 999px; font-size: 10px; font-weight: 800; text-transform: uppercase; display: inline-block; margin-top: 4px; }
        .status-badge.active { background: #dcfce7; color: #15803d; }
        .status-badge.pending { background: #fef3c7; color: #d97706; }
        .status-badge.rejected { background: #fee2e2; color: #b91c1c; }
        .status-badge.scheduled { background: #ccfbf1; color: #0f766e; }
        .status-badge.secondary { background: #f1f5f9; color: #64748b; }

        .footer {
            margin-top: auto; padding-top: 16px;
            border-top: 1px solid #e2e8f0;
            display: flex; flex-direction: column; gap: 6px;
            text-align: center; font-size: 11px; color: #64748b;
            flex-wrap: wrap; flex-shrink: 0;
        }
        @media (min-width: 768px) { .footer { flex-direction: row; justify-content: space-between; text-align: left; font-size: 12px; } }
        .footer .brand { color: var(--primary-red); font-weight: 700; }

        /* INSPECTION DETAIL MODAL */
        .insp-detail-modal-content {
            background: #ffffff; border-radius: 20px; border: 1px solid var(--primary-red-light);
            box-shadow: 0 25px 60px rgba(220, 38, 38, 0.2); overflow: hidden;
            max-height: calc(100vh - 40px);
            display: flex; flex-direction: column;
        }
        .insp-detail-header {
            padding: 16px 22px;
            background: linear-gradient(135deg, #dc2626, #991b1b);
            color: #fff; display: flex; align-items: center; justify-content: space-between;
            gap: 12px; flex-shrink: 0;
        }
        .insp-detail-header h3 { font-size: 16px; font-weight: 800; margin: 0; display: flex; align-items: center; gap: 10px; color: #fff; }
        .insp-detail-header .btn-close-white {
            background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.4);
            color: #fff; width: 32px; height: 32px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: all 0.2s ease;
        }
        .insp-detail-header .btn-close-white:hover { background: rgba(255,255,255,0.35); }
        .insp-detail-body { padding: 22px; overflow-y: auto; flex: 1; }
        .insp-detail-body::-webkit-scrollbar { width: 6px; }
        .insp-detail-body::-webkit-scrollbar-thumb { background: var(--primary-red); border-radius: 999px; }

        .modal-content {
            background: #ffffff !important;
            border: 1px solid #e2e8f0 !important;
            border-radius: var(--r-xl) !important;
            box-shadow: 0 24px 48px -12px rgba(15, 23, 42, 0.18) !important;
        }
        @media (max-width: 576px) {
            .modal-dialog { margin: 8px; max-width: calc(100% - 16px); }
            .modal-body { max-height: 70vh; overflow-y: auto; }
        }

        /* RESPONSIVE */
        @media (max-width: 1400px) {
            .nav-link { padding: 10px 8px; font-size: 12px; }
            .nav-link .nav-ico { font-size: 11px; }
        }

        @media (max-width: 1200px) {
            .top-navbar {
                padding: 10px 14px;
                padding-top: calc(10px + var(--safe-top));
                gap: 10px; flex-wrap: wrap; min-height: 60px;
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
                z-index: 2001; /* ✅ UPDATED: Pinakamataas para sa menu mismo */
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
                color: #fff; flex-shrink: 0;
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
                z-index: 1999; /* ✅ Nasa ilalim ng header at menu */
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
            .page-header-actions { width: 100%; justify-content: flex-start; }

            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .stat-card { padding: 12px 14px; }
            .stat-card .label { font-size: 9.5px; }
            .stat-card .value { font-size: 20px; }
            .stat-card i { font-size: 18px; }

            .calendar-card { padding: 12px; }
            .calendar-header { margin-bottom: 12px; }
            .calendar-header .month-title { font-size: 14px; }
            .calendar-header .nav-btn { width: 32px; height: 32px; }
            .calendar-grid { gap: 3px; }
            .day-header { font-size: 9.5px; padding: 4px 0; }
            .day-cell { border-radius: 8px; padding: 4px; min-height: 42px; }
            .day-cell .day-number { font-size: 11.5px; }
            .day-cell .inspection-count { font-size: 9px; padding: 1px 5px; bottom: 3px; right: 4px; }

            .upcoming-panel { padding: 14px; }
            .panel-title { font-size: 14px; }
            .upcoming-list { max-height: 400px; }
            .upcoming-item { padding: 9px 11px; }
            .upcoming-item .item-name { font-size: 12px; }
            .upcoming-item .item-detail { font-size: 10.5px; }

            .footer { flex-direction: column; text-align: center; gap: 6px; font-size: 10.5px; }

            .modal-dialog { margin: 0; min-height: 100vh; min-height: 100dvh; align-items: stretch; }
            .modal-content { border-radius: 0 !important; min-height: 100vh; min-height: 100dvh; }
            .modal-header { padding-top: calc(1rem + var(--safe-top)); }
            .modal-footer { padding-bottom: calc(1rem + var(--safe-bottom)); }
        }

        @media (max-width: 480px) {
            .official-header { padding: 5px 8px; padding-top: calc(5px + var(--safe-top)); }
            .top-navbar { padding: 8px 10px; }
            .content { padding: 12px 10px 22px; padding-bottom: calc(22px + var(--safe-bottom)); }
            .page-header { padding: 14px 16px; border-radius: 12px; }
            .page-header h1 { font-size: 16px; }
            .page-header p { font-size: 11.5px; }
            .btn-schedule-view { font-size: 10.5px; padding: 6px 10px; }
            .date-badge { padding: 5px 10px; font-size: 11px; }

            .stats-grid { gap: 8px; }
            .stat-card { padding: 10px 12px; }
            .stat-card .value { font-size: 18px; }
            .stat-card i { font-size: 16px; }

            .calendar-card { padding: 10px; }
            .calendar-grid { gap: 2px; }
            .day-header { font-size: 8.5px; padding: 3px 0; }
            .day-cell { border-radius: 6px; padding: 3px; min-height: 38px; }
            .day-cell .day-number { font-size: 10.5px; }
            .day-cell .inspection-count { font-size: 8px; padding: 0px 4px; }

            .upcoming-item .item-name { font-size: 11.5px; }
            .upcoming-item .item-detail { font-size: 10px; }

            .insp-detail-header { padding: 12px 16px; }
            .insp-detail-header h3 { font-size: 14px; }
            .insp-detail-body { padding: 16px; }
        }

        @media (max-width: 767px) {
            input, select, textarea { font-size: 16px !important; }
            .search-container input,
            .form-control { font-size: 16px !important; }
        }
    </style>
</head>
<body>

    <form id="logoutForm" action="../logout.php" method="POST" style="display:none;"></form>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="page-container">
        <!-- OFFICIAL HEADER -->
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
                                <button type="button" class="nav-link" data-dropdown>
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
                                <button type="button" class="nav-link active" data-dropdown>
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
                                            <strong><?php echo htmlspecialchars($n['title'] ?? 'Notification'); ?></strong>
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
                    <span class="sep"><i class="fa-solid fa-angle-right"></i></span>
                    <strong>Inspection Schedule</strong>
                </div>

                <!-- PAGE HEADER -->
                <div class="page-header">
                    <div>
                        <h1><i class="fa-solid fa-calendar-check"></i> Inspection Schedule</h1>
                        <p>Browse all scheduled inspections across properties on calendar view.</p>
                    </div>
                    <div class="page-header-actions">
                        <a href="inspections.php" class="btn-schedule-view">
                            <i class="fa-solid fa-list"></i> View All Inspections
                        </a>
                        <div class="date-badge">
                            <i class="fa-regular fa-calendar"></i> <?php echo date("F d, Y"); ?>
                        </div>
                    </div>
                </div>

                <!-- STATS -->
                <div class="stats-grid">
                    <div class="stat-card bg-total">
                        <div>
                            <span class="label">This Month</span>
                            <div class="value"><?php echo $statsThisMonth; ?></div>
                        </div>
                        <i class="fa-regular fa-calendar"></i>
                    </div>
                    <div class="stat-card bg-pending">
                        <div>
                            <span class="label">Pending</span>
                            <div class="value"><?php echo $statsPending; ?></div>
                        </div>
                        <i class="fa-regular fa-clock"></i>
                    </div>
                    <div class="stat-card bg-completed">
                        <div>
                            <span class="label">Completed</span>
                            <div class="value"><?php echo $statsCompleted; ?></div>
                        </div>
                        <i class="fa-regular fa-circle-check"></i>
                    </div>
                    <div class="stat-card bg-failed">
                        <div>
                            <span class="label">Failed</span>
                            <div class="value"><?php echo $statsFailed; ?></div>
                        </div>
                        <i class="fa-regular fa-circle-xmark"></i>
                    </div>
                </div>

                <!-- CALENDAR SECTION -->
                <div class="calendar-section">
                    <div class="calendar-card">
                        <div class="calendar-header">
                            <a class="nav-btn" href="?month=<?php echo $prevMonth; ?>&year=<?php echo $prevYear; ?>">
                                <i class="fa-solid fa-chevron-left"></i>
                            </a>
                            <span class="month-title"><?php echo $monthName; ?></span>
                            <a class="nav-btn" href="?month=<?php echo $nextMonth; ?>&year=<?php echo $nextYear; ?>">
                                <i class="fa-solid fa-chevron-right"></i>
                            </a>
                        </div>

                        <div class="calendar-grid">
                            <div class="day-header">Mon</div>
                            <div class="day-header">Tue</div>
                            <div class="day-header">Wed</div>
                            <div class="day-header">Thu</div>
                            <div class="day-header">Fri</div>
                            <div class="day-header">Sat</div>
                            <div class="day-header">Sun</div>

                            <?php
                            $firstDayOffset = $firstDayOfMonth - 1;
                            for ($i = 0; $i < $firstDayOffset; $i++) {
                                echo '<div class="day-cell other-month"></div>';
                            }

                            for ($day = 1; $day <= $daysInMonth; $day++) {
                                $dateStr = sprintf("%04d-%02d-%02d", $currentYear, $currentMonth, $day);
                                $isToday = ($dateStr === $today);
                                $hasInspection = isset($inspectionsByDate[$dateStr]) && !empty($inspectionsByDate[$dateStr]);
                                $inspectionCount = $hasInspection ? count($inspectionsByDate[$dateStr]) : 0;

                                $classes = 'day-cell';
                                if ($isToday) $classes .= ' today';
                                if ($hasInspection) $classes .= ' has-inspection';
                                ?>
                                <div class="<?php echo $classes; ?>" onclick="showDayDetails('<?php echo $dateStr; ?>')">
                                    <span class="day-number"><?php echo $day; ?></span>
                                    <?php if ($hasInspection): ?>
                                        <span class="inspection-count"><?php echo $inspectionCount; ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php
                            }

                            $remainingCells = (7 - (($firstDayOffset + $daysInMonth) % 7)) % 7;
                            for ($i = 0; $i < $remainingCells; $i++) {
                                echo '<div class="day-cell other-month"></div>';
                            }
                            ?>
                        </div>
                    </div>

                    <div class="upcoming-panel">
                        <div class="panel-title">
                            <i class="fa-solid fa-clock-rotate-left"></i> Upcoming Visits
                        </div>
                        <?php if (!empty($upcomingInspections)): ?>
                            <div class="upcoming-list">
                                <?php foreach ($upcomingInspections as $ins):
                                    $status = $ins['inspection_status'] ?? 'Scheduled';
                                    $statusClass = 'pending';
                                    $statusLower = strtolower($status);
                                    if (in_array($statusLower, ['passed','completed'])) $statusClass = 'active';
                                    elseif (in_array($statusLower, ['failed','rejected'])) $statusClass = 'rejected';
                                    elseif ($statusLower === 'scheduled') $statusClass = 'scheduled';

                                    $typeLabel = getInspectionTypeLabel($ins['inspection_type'] ?? '');
                                    $categoryLabel = $hasInspectionCategory ? getInspectionCategoryLabel($ins['inspection_category'] ?? '') : '';
                                    $pName = !empty($ins['business_name']) ? $ins['business_name'] : ($ins['rental_name'] ?? 'Property');
                                    $dateFormatted = !empty($ins['inspection_date']) ? date("M d, Y", strtotime($ins['inspection_date'])) : '—';
                                    $timeFormatted = !empty($ins['inspection_time']) ? date("g:i A", strtotime($ins['inspection_time'])) : '—';
                                ?>
                                    <div class="upcoming-item">
                                        <div class="item-date"><i class="fa-regular fa-calendar me-1"></i> <?php echo $dateFormatted; ?></div>
                                        <div class="item-name"><?php echo h($pName); ?></div>
                                        <div class="item-detail">
                                            <?php echo h($typeLabel); ?> 
                                            <?php if (!empty($categoryLabel) && $categoryLabel !== '—'): ?>
                                                &middot; <?php echo h($categoryLabel); ?>
                                            <?php endif; ?>
                                            &middot; <?php echo $timeFormatted; ?>
                                        </div>
                                        <span class="status-badge <?php echo $statusClass; ?>"><?php echo h($status); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div style="text-align:center; padding:20px; color:#64748b; font-size:12px;">
                                <i class="fa-regular fa-calendar-xmark" style="font-size:24px; opacity:0.5; display:block; margin-bottom:6px;"></i>
                                No upcoming visits.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <footer class="footer">
                    <span>&copy; <?php echo date('Y'); ?> <span class="brand">RENTRACK LGU</span></span>
                    <span>Unified Rental Registration, Tracking &amp; Compliance System</span>
                </footer>
            </main>
        </div>
    </div>

    <!-- SEARCH MODAL -->
    <div class="modal fade" id="searchModal" tabindex="-1" aria-labelledby="searchModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="searchModalLabel" style="font-size: 14.5px; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 9px;">
                        <i class="fa-solid fa-magnifying-glass" style="color: var(--primary-red);"></i> Global System Search
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
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

    <!-- INSPECTION DETAIL MODAL -->
    <div class="modal fade" id="inspectionDetailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content insp-detail-modal-content">
                <div class="insp-detail-header">
                    <h3><i class="fa-solid fa-clipboard-list"></i> Inspection Details</h3>
                    <button type="button" class="btn-close-white" data-bs-dismiss="modal" aria-label="Close">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
                <div class="insp-detail-body" id="inspectionDetailBody">
                    <div style="text-align:center; padding:40px;">
                        <i class="fa-solid fa-spinner fa-spin" style="font-size:28px; color:var(--primary-red);"></i>
                        <p style="margin-top:12px; color:#64748b;">Loading inspection details...</p>
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

        /* CLOCK */
        setInterval(() => {
            const d = new Date();
            let h = d.getHours(), m = d.getMinutes(), ampm = h >= 12 ? 'PM' : 'AM';
            h = h % 12 || 12;
            const clockEl = document.getElementById('clockTime');
            if (clockEl) clockEl.textContent = `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')} ${ampm}`;
        }, 1000);

        /* NOTIFICATIONS + PROFILE */
        const notifBtn = document.getElementById('notifBtn');
        const notifDropdown = document.getElementById('notifDropdown');
        const profileToggle = document.getElementById('profileToggle');
        const profileDropdown = document.getElementById('profileDropdown');

        notifBtn?.addEventListener('click', (e) => {
            e.stopPropagation();
            profileDropdown?.classList.remove('show');
            notifDropdown.classList.toggle('show');
            if (notifDropdown.classList.contains('show')) {
                fetch('inspection_schedule.php?ajax_action=read_notif')
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

        /* SEARCH MODAL */
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
                    fetch('inspection_schedule.php?ajax_action=search&q=' + encodeURIComponent(query))
                        .then(res => res.text())
                        .then(html => {
                            if (html.trim() !== '') modalSearchResults.innerHTML = html;
                            else modalSearchResults.innerHTML = `<div style="text-align: center; padding: 30px; color: #64748b; font-size: 13px;"><i class="fa-solid fa-folder-open" style="font-size: 24px; margin-bottom: 8px; display: block; opacity: 0.5;"></i> No matching results found.</div>`;
                        });
                }, 250);
            });
        }

        /* CALENDAR DATA */
        const inspectionsByDate = <?php
            $jsonData = [];
            foreach ($inspectionsByDate as $date => $items) {
                $jsonData[$date] = array_map(function($item) use ($hasInspectionCategory) {
                    return [
                        'id' => $item['id'] ?? 0,
                        'business_name' => $item['business_name'] ?? $item['rental_name'] ?? 'Property',
                        'tracking_no' => $item['tracking_no'] ?? 'N/A',
                        'barangay' => $item['barangay'] ?? '',
                        'inspection_date' => $item['inspection_date'] ?? '',
                        'inspection_time' => $item['inspection_time'] ?? '',
                        'inspection_type' => $item['inspection_type'] ?? '',
                        'inspection_category' => $hasInspectionCategory ? ($item['inspection_category'] ?? '') : '',
                        'inspection_status' => $item['inspection_status'] ?? 'Scheduled',
                        'inspector_name' => $item['inspector_name'] ?? 'Unassigned',
                        'owner_name' => $item['owner_name'] ?? '—'
                    ];
                }, $items);
            }
            echo json_encode($jsonData);
        ?>;

        function getTypeLabel(type) {
            if (!type) return '—';
            const types = {
                'Initial': 'Initial Inspection',
                'Annual': 'Annual Inspection',
                'Follow-up': 'Follow-up Inspection',
                'Complaint': 'Complaint Inspection',
                'Re-inspection': 'Re-inspection'
            };
            return types[type] || type.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
        }

        function getCategoryLabel(category) {
            if (!category) return '';
            const categories = {
                'building': 'Building Inspection',
                'fire_safety': 'Fire Safety & Hazard',
                'structural': 'Structural Assessment',
                'electrical': 'Electrical Safety',
                'sanitary': 'Sanitary Inspection',
                'zoning': 'Zoning Compliance',
                'occupancy': 'Occupancy Clearance',
                'health': 'Health & Sanitary'
            };
            return categories[category] || category.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
        }

        function getStatusClass(status) {
            const s = (status || '').toLowerCase().trim();
            if (['passed', 'completed'].includes(s)) return 'active';
            if (['failed', 'rejected'].includes(s)) return 'rejected';
            if (['scheduled'].includes(s)) return 'scheduled';
            return 'pending';
        }

        function showDayDetails(dateStr) {
            const items = inspectionsByDate[dateStr] || [];
            const modalEl = document.getElementById('inspectionDetailModal');
            const modal = new bootstrap.Modal(modalEl);
            const body = document.getElementById('inspectionDetailBody');

            const dateObj = new Date(dateStr + 'T00:00:00');
            const dateDisplay = dateObj.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });

            if (items.length === 0) {
                body.innerHTML = `
                    <div style="text-align:center; padding:30px 20px; color:#64748b;">
                        <i class="fa-regular fa-calendar-xmark" style="font-size:32px; opacity:0.5; display:block; margin-bottom:10px; color:var(--primary-red);"></i>
                        <h4 style="font-size:15px; font-weight:700; color:#0f172a; margin-bottom:6px;">No inspections scheduled</h4>
                        <p style="font-size:12.5px;">Walang naka-schedule na inspection sa <strong>${dateDisplay}</strong>.</p>
                    </div>
                `;
                modal.show();
                return;
            }

            let html = `<div style="font-size:13px; color:#64748b; margin-bottom:14px; padding-bottom:10px; border-bottom:1px dashed #e2e8f0;">
                <i class="fa-regular fa-calendar me-1" style="color:var(--primary-red);"></i> 
                <strong style="color:#0f172a;">${dateDisplay}</strong> &middot; ${items.length} ${items.length === 1 ? 'visit' : 'visits'}
            </div>`;

            items.forEach(function(item) {
                const status = item.inspection_status || 'Scheduled';
                const statusClass = getStatusClass(status);
                const typeLabel = getTypeLabel(item.inspection_type);
                const categoryLabel = item.inspection_category ? getCategoryLabel(item.inspection_category) : '';
                const timeDisplay = item.inspection_time ? item.inspection_time : 'TBA';
                const inspectorDisplay = item.inspector_name || 'Unassigned';

                html += `
                    <div style="padding:14px 16px; background:#f8fafc; border:1px solid #e2e8f0; border-left:4px solid var(--primary-red); border-radius:12px; margin-bottom:12px;">
                        <div style="display:flex; justify-content:space-between; gap:10px; align-items:flex-start; margin-bottom:8px;">
                            <div style="min-width:0; flex:1;">
                                <div style="font-weight:800; font-size:14px; color:#0f172a; margin-bottom:4px; word-break:break-word;">${escapeHtml(item.business_name)}</div>
                                <div style="font-size:11px; color:#64748b;">
                                    <i class="fa-solid fa-barcode" style="font-size:10px;"></i> #${escapeHtml(item.tracking_no)}
                                    ${item.barangay ? ' &middot; ' + escapeHtml(item.barangay) : ''}
                                </div>
                            </div>
                            <span class="status-badge ${statusClass}" style="margin-top:0;">${escapeHtml(status)}</span>
                        </div>
                        
                        <div style="display:flex; flex-wrap:wrap; gap:10px 16px; margin-bottom:8px; font-size:12px;">
                            <span style="display:flex; align-items:center; gap:5px; color:#475569;">
                                <i class="fa-regular fa-clock" style="color:var(--primary-red); font-size:11px;"></i> 
                                <strong>${escapeHtml(timeDisplay)}</strong>
                            </span>
                            <span style="display:flex; align-items:center; gap:5px; color:#475569;">
                                <i class="fa-solid fa-clipboard-check" style="color:var(--primary-red); font-size:11px;"></i> 
                                <strong>${escapeHtml(typeLabel)}</strong>
                            </span>
                            ${categoryLabel ? `
                            <span style="display:flex; align-items:center; gap:5px; color:#475569;">
                                <i class="fa-solid fa-layer-group" style="color:var(--primary-red); font-size:11px;"></i> 
                                <strong>${escapeHtml(categoryLabel)}</strong>
                            </span>` : ''}
                            <span style="display:flex; align-items:center; gap:5px; color:#475569;">
                                <i class="fa-solid fa-user-tie" style="color:var(--primary-red); font-size:11px;"></i> 
                                <strong>${escapeHtml(inspectorDisplay)}</strong>
                            </span>
                        </div>
                        
                        <div style="font-size:11px; color:#64748b;">
                            <i class="fa-solid fa-user-shield" style="font-size:10px;"></i> Owner: <strong style="color:#334155;">${escapeHtml(item.owner_name)}</strong>
                        </div>
                    </div>
                `;
            });

            body.innerHTML = html;
            modal.show();
        }

        function escapeHtml(str) {
            if (!str) return '';
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        /* LOGOUT */
        function confirmLogout() {
            Swal.fire({
                title: 'Logout', text: 'Are you sure you want to end your session?', icon: 'warning',
                showCancelButton: true, confirmButtonColor: '#dc2626', cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Logout'
            }).then((res) => { if (res.isConfirmed) document.getElementById('logoutForm').submit(); });
        }
        document.getElementById('profileLogoutBtn')?.addEventListener('click', (e) => { e.preventDefault(); confirmLogout(); });
    </script>
</body>
</html>