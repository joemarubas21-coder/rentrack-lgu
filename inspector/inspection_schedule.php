<?php
include "../config/db.php";

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../config/session.php'; app_session_start();
}

date_default_timezone_set("Asia/Manila");

$inspectorId = (int) ($_SESSION['user_id'] ?? 0);
$fullname = htmlspecialchars($_SESSION['fullname'] ?? 'Inspector', ENT_QUOTES, 'UTF-8');
$staffRole = $_SESSION['role'] ?? "Inspector";

/* =====================================================
   HELPER FUNCTIONS
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

/* =====================================================
   PROFESSIONAL LABELS (TUGMA SA SYSTEM)
===================================================== */
function getInspectionTypeLabel($type) {
    if (empty($type)) return '—';
    $type = trim($type);
    $types = [
        'Initial'         => 'Initial Inspection',
        'Annual'          => 'Annual Inspection',
        'Follow-up'       => 'Follow-up Inspection',
        'Complaint'       => 'Complaint Inspection',
        'Re-inspection'   => 'Re-inspection',
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

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$hasInspectionCategory = columnExists($conn, 'inspections', 'inspection_category');
$hasInspectionType = columnExists($conn, 'inspections', 'inspection_type');

/* =====================================================
   CALENDAR PARAMETERS
===================================================== */
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

/* =====================================================
   FETCH MONTHLY INSPECTIONS (Only for this inspector)
===================================================== */
$inspections = [];
$inspectionsByDate = [];

if (tableExists($conn, 'inspections') && tableExists($conn, 'rentals')) {
    $selectFields = "i.id, i.inspection_no, i.inspection_status, i.inspection_date, i.inspection_time, i.inspector_id";
    if ($hasInspectionType) $selectFields .= ", i.inspection_type";
    if ($hasInspectionCategory) $selectFields .= ", i.inspection_category";
    
    $sql = "SELECT $selectFields, 
                   r.id AS rental_id, r.business_name, r.rental_name, r.tracking_no, r.barangay, 
                   u.fullname AS owner_name
            FROM inspections i
            INNER JOIN rentals r ON i.rental_id = r.id
            LEFT JOIN users u ON r.user_id = u.id
            WHERE i.inspector_id = :iid
              AND (r.is_deleted = FALSE OR r.is_deleted IS NULL)
              AND i.inspection_date BETWEEN :sdate AND :edate
            ORDER BY i.inspection_date ASC, i.inspection_time ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([':iid' => $inspectorId, ':sdate' => $startDate, ':edate' => $endDate]);
    $inspections = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($inspections as $ins) {
        $date = $ins['inspection_date'] ?? '';
        if (!isset($inspectionsByDate[$date])) $inspectionsByDate[$date] = [];
        $inspectionsByDate[$date][] = $ins;
    }
}

/* =====================================================
   FETCH UPCOMING INSPECTIONS
===================================================== */
$upcomingInspections = [];
if (tableExists($conn, 'inspections') && tableExists($conn, 'rentals')) {
    $selectFields = "i.id, i.inspection_no, i.inspection_status, i.inspection_date, i.inspection_time, i.inspector_id";
    if ($hasInspectionType) $selectFields .= ", i.inspection_type";
    if ($hasInspectionCategory) $selectFields .= ", i.inspection_category";
    
    $sql = "SELECT $selectFields, 
                   r.id AS rental_id, r.business_name, r.rental_name, r.tracking_no, r.barangay,
                   u.fullname AS owner_name
            FROM inspections i
            INNER JOIN rentals r ON i.rental_id = r.id
            LEFT JOIN users u ON r.user_id = u.id
            WHERE i.inspector_id = :iid
              AND (r.is_deleted = FALSE OR r.is_deleted IS NULL)
              AND i.inspection_date >= CURRENT_DATE
            ORDER BY i.inspection_date ASC, i.inspection_time ASC
            LIMIT 15";
    
    try {
        $stmtUp = $conn->prepare($sql);
        $stmtUp->execute([':iid' => $inspectorId]);
        $upcomingInspections = $stmtUp->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $sql = str_replace('CURRENT_DATE', 'CURDATE()', $sql);
        $stmtUp = $conn->prepare($sql);
        $stmtUp->execute([':iid' => $inspectorId]);
        $upcomingInspections = $stmtUp->fetchAll(PDO::FETCH_ASSOC);
    }
}

/* =====================================================
   MONTHLY STATS
===================================================== */
$statsThisMonth = 0;
$statsPending = 0;
$statsCompleted = 0;
$statsFailed = 0;

foreach ($inspections as $ins) {
    $statsThisMonth++;
    $status = strtolower($ins['inspection_status'] ?? 'pending');
    if ($status === 'passed' || $status === 'completed') $statsCompleted++;
    elseif ($status === 'failed' || $status === 'cancelled') $statsFailed++;
    else $statsPending++;
}

$firstDayOfMonth = date('N', strtotime("$currentYear-$currentMonth-01"));
$daysInMonth = date('t', strtotime("$currentYear-$currentMonth-01"));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<title>RENTRACK · Inspection Schedule</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    :root {
        --primary-orange: #ff7a00;
        --primary-orange-dark: #d95300;
        --primary-orange-light: #ffb067;
        
        --sidebar-bg: rgba(20, 11, 7, 0.95);
        --topbar-gov-bg: #140b07;
        --sidebar-hover: rgba(255, 122, 0, 0.15);
        
        --card-bg: rgba(31, 20, 15, 0.85); 
        --card-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.5);
        
        --border-light: rgba(255, 122, 0, 0.25);
        --text-dark: #ffffff;
        --text-gray: #e5e7eb;
        --text-light: #d1d5db;
    }

    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        background-color: #0e0704;
        background-image: 
            radial-gradient(circle at 15% 15%, rgba(255, 122, 0, 0.15) 0%, transparent 45%),
            radial-gradient(circle at 85% 85%, rgba(224, 109, 0, 0.1) 0%, transparent 45%),
            radial-gradient(circle at 50% 50%, rgba(255, 159, 67, 0.06) 0%, transparent 60%);
        background-attachment: fixed;
        color: var(--text-dark);
        overflow-x: hidden;
    }

    .gov-top-bar {
        background: var(--topbar-gov-bg);
        color: #ffb067;
        padding: 5px 10px;
        font-size: 9px;
        font-weight: 600;
        display: flex;
        align-items: center;
        justify-content: space-between;
        border-bottom: 1px solid var(--border-light);
        z-index: 100;
        flex-wrap: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        gap: 6px;
    }
    @media (min-width: 768px) {
        .gov-top-bar { padding: 8px 24px; font-size: 11.5px; white-space: normal; flex-wrap: wrap; }
    }
    .gov-top-bar .gov-left { display: flex; align-items: center; gap: 4px; overflow: hidden; text-overflow: ellipsis; }
    @media (min-width: 768px) { .gov-top-bar .gov-left { gap: 10px; } }
    .gov-top-bar .gov-right { display: flex; align-items: center; gap: 6px; color: #f3f4f6; flex-shrink: 0; }
    .gov-badge { background: rgba(255, 122, 0, 0.25); color: #ffb067; padding: 2px 5px; border-radius: 4px; font-size: 8.5px; font-weight: 700; border: 1px solid rgba(255, 122, 0, 0.5); }
    @media (min-width: 768px) { .gov-badge { padding: 3px 10px; font-size: 11px; } }

    @keyframes spinSlow { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    .brand-icon-wrapper { position: relative; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; }
    @media (min-width: 768px) { .brand-icon-wrapper { width: 44px; height: 44px; } }
    .brand-spinner { position: absolute; width: 100%; height: 100%; border: 2px dashed var(--primary-orange); border-radius: 50%; animation: spinSlow 8s linear infinite; }
    .brand-icon { width: 28px; height: 28px; background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-light)); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; color: #fff; box-shadow: 0 0 15px rgba(255, 122, 0, 0.6); z-index: 2; }
    @media (min-width: 768px) { .brand-icon { width: 34px; height: 34px; font-size: 15px; } }

    .wrapper { display: flex; width: 100%; min-height: calc(100vh - 37px); align-items: stretch; position: relative; }
    .main { flex: 1; min-width: 0; background: transparent; display: flex; flex-direction: column; transition: all 0.3s ease; }

    /* ============================================
       SIDEBAR - SCROLLABLE, FULL HEIGHT
    ============================================ */
    .sidebar {
        width: 280px;
        background: var(--sidebar-bg);
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
        color: #fff;
        flex-shrink: 0;
        border-right: 1px solid var(--border-light);
        display: flex;
        flex-direction: column;
        position: fixed;
        top: 0;
        left: 0;
        height: 100vh;
        transition: transform 0.3s ease, width 0.3s ease;
        z-index: 1050;
        transform: translateX(-100%);
    }
    @media (min-width: 992px) {
        .sidebar {
            position: relative;
            transform: translateX(0);
            height: auto;
            min-height: 100%;
            align-self: stretch;
        }
    }
    .sidebar.mobile-show { transform: translateX(0); }
    
    .sidebar-inner {
        display: flex;
        flex-direction: column;
        flex: 1;
        min-height: 100%;
        width: 100%;
        padding: 0;
    }

    .sidebar-brand { padding: 18px 16px; display: flex; align-items: center; gap: 10px; border-bottom: 1px solid var(--border-light); flex-shrink: 0; }
    @media (min-width: 768px) { .sidebar-brand { padding: 22px 20px; gap: 14px; } }
    .brand-text .main-title { font-size: 14px; font-weight: 800; color: #fff; line-height: 1.1; letter-spacing: 0.5px; }
    @media (min-width: 768px) { .brand-text .main-title { font-size: 16px; } }
    .brand-text .sub-title { font-size: 8.5px; font-weight: 600; color: var(--primary-orange-light); text-transform: uppercase; letter-spacing: 1px; }
    @media (min-width: 768px) { .brand-text .sub-title { font-size: 9.5px; } }

    /* Scrollable menu */
    .sidebar-menu-wrapper {
        flex: 1;
        overflow-y: auto;
        overflow-x: hidden;
        min-height: 0;
        scrollbar-width: thin;
        scrollbar-color: rgba(255, 122, 0, 0.4) transparent;
    }
    .sidebar-menu-wrapper::-webkit-scrollbar { width: 6px; }
    .sidebar-menu-wrapper::-webkit-scrollbar-track { background: rgba(255, 122, 0, 0.05); border-radius: 999px; }
    .sidebar-menu-wrapper::-webkit-scrollbar-thumb { background: rgba(255, 122, 0, 0.4); border-radius: 999px; }
    .sidebar-menu-wrapper::-webkit-scrollbar-thumb:hover { background: rgba(255, 122, 0, 0.6); }
    .sidebar-menu-wrapper::-webkit-scrollbar-button { display: none; }

    .sidebar-category { font-size: 9.5px; text-transform: uppercase; letter-spacing: 1.2px; color: rgba(255, 255, 255, 0.6); padding: 14px 16px 4px 16px; font-weight: 700; }
    @media (min-width: 768px) { .sidebar-category { font-size: 10px; padding: 18px 20px 6px 20px; } }
    
    .sidebar-nav { list-style: none; padding: 0 10px; margin: 0; }
    .sidebar-nav li { margin-bottom: 2px; }
    .sidebar-nav a {
        display: flex; align-items: center; gap: 12px; padding: 10px 12px; color: #f3f4f6;
        text-decoration: none; border-radius: 10px; font-size: 13px; font-weight: 500; transition: all 0.2s ease;
    }
    @media (min-width: 768px) { .sidebar-nav a { padding: 10px 14px; font-size: 13.5px; gap: 14px; } }
    .sidebar-nav a i { font-size: 14px; width: 20px; text-align: center; color: var(--primary-orange-light); }
    .sidebar-nav a:hover { background: var(--sidebar-hover); color: #fff; }
    .sidebar-nav a.active { background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-dark)); color: #fff; box-shadow: 0 4px 15px rgba(255, 122, 0, 0.4); }
    .sidebar-nav a.active i { color: #fff; }

    .sidebar-footer { padding: 14px; border-top: 1px solid var(--border-light); margin-top: auto; display: flex; justify-content: center; flex-shrink: 0; }
    .sidebar-logout-btn {
        width: 44px; height: 44px; background: rgba(255, 122, 0, 0.08); border: 1.5px solid var(--primary-orange);
        color: var(--primary-orange-light); border-radius: 50%; display: flex; align-items: center; justify-content: center;
        text-decoration: none; transition: all 0.3s ease;
    }
    @media (min-width: 768px) { .sidebar-logout-btn { width: 52px; height: 52px; } }
    .sidebar-logout-btn:hover { background: var(--primary-orange); color: #fff; }

    .top-navbar {
        background: rgba(20, 11, 7, 0.95);
        backdrop-filter: blur(12px);
        padding: 8px 12px; 
        border-bottom: 1px solid var(--border-light);
        display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;
        position: sticky; top: 0; z-index: 30; color: #fff;
    }
    @media (min-width: 768px) { .top-navbar { padding: 12px 28px; gap: 12px; } }
    
    .navbar-title-wrap { display: flex; align-items: center; gap: 8px; min-width: 0; }
    .navbar-title-wrap h4 { font-size: 13.5px; font-weight: 700; margin: 0; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: flex; align-items: center; gap: 6px; }
    @media (min-width: 768px) { .navbar-title-wrap h4 { font-size: 17px; gap: 10px; } }
    .navbar-title-wrap h4 span { font-size: 11px; font-weight: 400; color: #d1d5db; display: none; }
    @media (min-width: 992px) { .navbar-title-wrap h4 span { display: inline; font-size: 12.5px; } }

    .hamburger-btn {
        background: rgba(255, 122, 0, 0.15); border: 1px solid var(--border-light); color: var(--primary-orange-light); width: 34px; height: 34px;
        border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 14px; cursor: pointer; flex-shrink: 0;
    }
    @media (min-width: 768px) { .hamburger-btn { width: 38px; height: 38px; font-size: 16px; border-radius: 9px; } }

    .live-clock-box { display: none; }
    @media (min-width: 992px) {
        .live-clock-box {
            display: block; background: rgba(255, 122, 0, 0.1); border: 1px solid var(--border-light); padding: 5px 12px; border-radius: 8px;
            text-align: center; color: var(--primary-orange-light); font-family: monospace; font-weight: 700; font-size: 12.5px;
        }
        .live-clock-box .date-sub { font-size: 9px; color: #d1d5db; font-family: 'Inter', sans-serif; font-weight: 500; }
    }

    .content { flex: 1; padding: 12px; }
    @media (min-width: 768px) { .content { padding: 28px 32px 20px; } }

    /* STATS CARDS */
    .stats-grid {
        display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 16px;
    }
    @media (min-width: 768px) { .stats-grid { grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 20px; } }
    .stat-card {
        display: flex; align-items: center; justify-content: space-between;
        border-radius: 12px; padding: 12px 14px;
        color: #fff; position: relative; overflow: hidden;
    }
    @media (min-width: 768px) { .stat-card { padding: 16px 18px; } }
    .stat-card::after {
        content: ''; position: absolute; top: -20px; right: -20px;
        width: 80px; height: 80px; border-radius: 50%;
        background: rgba(255,255,255,0.1); pointer-events: none;
    }
    .stat-card.bg-total { background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-dark)); }
    .stat-card.bg-pending { background: linear-gradient(135deg, #d97706, #f59e0b); }
    .stat-card.bg-completed { background: linear-gradient(135deg, #059669, #10b981); }
    .stat-card.bg-failed { background: linear-gradient(135deg, #dc2626, #ef4444); }
    .stat-card .label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: rgba(255,255,255,0.9); margin-bottom: 4px; display: block; }
    @media (min-width: 768px) { .stat-card .label { font-size: 11px; } }
    .stat-card .value { font-size: 20px; font-weight: 800; color: #fff; }
    @media (min-width: 768px) { .stat-card .value { font-size: 26px; } }
    .stat-card i { font-size: 20px; color: rgba(255,255,255,0.9); z-index: 1; }
    @media (min-width: 768px) { .stat-card i { font-size: 24px; } }

    /* CALENDAR */
    .calendar-section {
        display: grid; grid-template-columns: 1fr; gap: 16px; margin-bottom: 16px;
    }
    @media (min-width: 992px) { .calendar-section { grid-template-columns: 1fr 340px; } }

    .calendar-card {
        background: rgba(31, 20, 15, 0.85); border: 1px solid var(--border-light); border-radius: 16px;
        padding: 16px;
    }
    @media (min-width: 768px) { .calendar-card { padding: 20px; } }

    .calendar-header {
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px solid var(--border-light);
    }
    .calendar-header .month-title { font-size: 15px; font-weight: 800; color: #fff; }
    @media (min-width: 768px) { .calendar-header .month-title { font-size: 17px; } }
    .calendar-header .nav-btn {
        background: rgba(255, 122, 0, 0.1); border: 1px solid var(--border-light); color: var(--primary-orange-light);
        width: 34px; height: 34px; border-radius: 10px; cursor: pointer;
        display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s ease;
        text-decoration: none;
    }
    .calendar-header .nav-btn:hover { background: var(--primary-orange); color: #fff; border-color: var(--primary-orange); }

    .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 3px; }
    @media (min-width: 768px) { .calendar-grid { gap: 5px; } }
    .day-header {
        text-align: center; font-size: 9.5px; font-weight: 800; color: #ffb067;
        text-transform: uppercase; padding: 5px 0;
    }
    @media (min-width: 768px) { .day-header { font-size: 11px; padding: 6px 0; } }
    .day-cell {
        aspect-ratio: 1; border: 1px solid rgba(255, 122, 0, 0.1); border-radius: 8px; padding: 5px;
        background: rgba(20, 11, 7, 0.6); position: relative; cursor: pointer; transition: all 0.2s ease;
        min-height: 38px;
    }
    @media (min-width: 768px) { .day-cell { border-radius: 10px; padding: 6px; min-height: 44px; } }
    .day-cell:hover { border-color: var(--primary-orange); background: rgba(255, 122, 0, 0.1); }
    .day-cell .day-number { font-size: 11px; font-weight: 700; color: #d1d5db; }
    @media (min-width: 768px) { .day-cell .day-number { font-size: 12.5px; } }
    .day-cell.today { background: rgba(255, 122, 0, 0.2); border-color: var(--primary-orange); }
    .day-cell.today .day-number { color: #fff; font-weight: 800; }
    .day-cell.other-month { opacity: 0.2; background: rgba(0, 0, 0, 0.3); cursor: default; }
    .day-cell.has-inspection { background: linear-gradient(135deg, rgba(255, 122, 0, 0.3), rgba(255, 122, 0, 0.15)); border-color: #ffb067; }
    .day-cell.has-inspection .day-number { color: #fff; }
    .day-cell .inspection-count {
        position: absolute; bottom: 3px; right: 4px; font-size: 9px; font-weight: 800;
        background: var(--primary-orange); color: #fff; padding: 1px 5px; border-radius: 999px;
    }
    @media (min-width: 768px) { .day-cell .inspection-count { font-size: 10px; padding: 1px 6px; bottom: 4px; right: 6px; } }

    /* UPCOMING PANEL */
    .upcoming-panel {
        background: rgba(31, 20, 15, 0.85); border: 1px solid var(--border-light); border-radius: 16px;
        padding: 16px;
    }
    @media (min-width: 768px) { .upcoming-panel { padding: 20px; } }
    .panel-title {
        font-size: 14px; font-weight: 800; color: #fff;
        margin-bottom: 12px; border-bottom: 1px solid var(--border-light); padding-bottom: 8px;
        display: flex; align-items: center; gap: 6px;
    }
    .panel-title i { color: var(--primary-orange-light); }
    .upcoming-list {
        display: flex; flex-direction: column; gap: 10px;
        max-height: 480px; overflow-y: auto; padding-right: 4px;
    }
    .upcoming-list::-webkit-scrollbar { width: 5px; }
    .upcoming-list::-webkit-scrollbar-thumb { background: rgba(255, 122, 0, 0.4); border-radius: 999px; }
    .upcoming-item {
        padding: 10px 12px; background: rgba(20, 11, 7, 0.6);
        border-left: 3px solid var(--primary-orange);
        border-radius: 8px; font-size: 12px;
    }
    .upcoming-item .item-date { font-size: 10px; font-weight: 800; color: #ffb067; text-transform: uppercase; }
    .upcoming-item .item-name { font-weight: 700; color: #fff; margin: 2px 0; font-size: 12.5px; }
    .upcoming-item .item-detail { color: #d1d5db; font-size: 11px; }

    /* STATUS BADGES */
    .status-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
    .status-badge.passed { background: rgba(16, 185, 129, 0.2); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.4); }
    .status-badge.scheduled { background: rgba(255, 122, 0, 0.2); color: #ffb067; border: 1px solid rgba(255, 122, 0, 0.4); }
    .status-badge.failed { background: rgba(239, 68, 68, 0.2); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.4); }
    .status-badge.pending { background: rgba(245, 158, 11, 0.2); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.4); }

    .inspection-type-chip {
        display: inline-block; padding: 2px 8px;
        background: rgba(255, 122, 0, 0.15); color: #ffb067;
        border: 1px solid rgba(255, 122, 0, 0.4);
        border-radius: 6px; font-size: 9.5px; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.3px; white-space: nowrap;
    }
    .inspection-category-chip {
        display: inline-block; padding: 2px 8px;
        background: rgba(59, 130, 246, 0.15); color: #93c5fd;
        border: 1px solid rgba(59, 130, 246, 0.4);
        border-radius: 6px; font-size: 9.5px; font-weight: 700;
        white-space: nowrap;
    }

    .footer-custom {
        background: rgba(20, 11, 7, 0.7); 
        backdrop-filter: blur(10px); 
        border-top: 1px solid var(--border-light); 
        padding: 12px 16px;
        display: flex; 
        flex-direction: column; 
        justify-content: center; 
        align-items: center; 
        gap: 4px; 
        font-size: 11px; 
        color: #d1d5db; 
        margin-top: auto; 
        font-weight: 500; 
        text-align: center;
    }
    @media (min-width: 768px) { 
        .footer-custom { 
            flex-direction: row; 
            justify-content: space-between; 
            padding: 14px 32px; 
            text-align: left; 
            font-size: 11.5px; 
        } 
    }
    .footer-custom .brand { color: var(--primary-orange); font-weight: 800; text-shadow: 0 0 8px rgba(255,122,0,0.4); }

    /* MODAL */
    .modal-content { background: #251610; color: #fff; border: 1px solid rgba(255, 122, 0, 0.4); border-radius: 16px; box-shadow: 0 25px 50px rgba(0,0,0,0.8); }
    .modal-header { border-bottom: 1px solid var(--border-light); padding: 14px 18px; }
    .modal-footer { border-top: 1px solid var(--border-light); padding: 12px 18px; }
    .modal-body { padding: 16px; }

    .insp-detail-section { margin-bottom: 18px; padding-bottom: 14px; border-bottom: 1px dashed rgba(255, 122, 0, 0.25); }
    .insp-detail-section:last-child { border-bottom: none; }
    .insp-detail-section-title { font-size: 11px; font-weight: 800; text-transform: uppercase; color: #ffb067; letter-spacing: 0.8px; margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }
    .insp-detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 18px; }
    @media (max-width: 480px) { .insp-detail-grid { grid-template-columns: 1fr; } }
    .insp-detail-item .label { font-size: 10px; font-weight: 800; color: #d1d5db; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 3px; opacity: 0.8; }
    .insp-detail-item .value { font-size: 13px; font-weight: 600; color: #fff; word-break: break-word; }
    .insp-detail-item.full { grid-column: 1 / -1; }
</style>
</head>
<body>

<div class="gov-top-bar">
    <div class="gov-left text-truncate">
        <i class="fa-solid fa-landmark text-warning"></i>
        <span class="text-truncate">REP. OF THE PHILIPPINES <span class="d-none d-sm-inline">| LGU Compliance Portal</span></span>
    </div>
    <div class="gov-right">
        <span class="gov-badge"><i class="fa-solid fa-shield-halved me-1"></i> LGU V2.1</span>
    </div>
</div>

<div class="wrapper">
    <nav class="sidebar" id="sidebar">
        <div class="sidebar-inner">
            <div class="sidebar-brand">
                <div class="brand-icon-wrapper">
                    <div class="brand-spinner"></div>
                    <div class="brand-icon"><i class="fa-solid fa-shield-halved"></i></div>
                </div>
                <div class="brand-text">
                    <div class="main-title">RENTRACK</div>
                    <div class="sub-title">INSPECTOR</div>
                </div>
            </div>

            <div class="sidebar-menu-wrapper">
                <div class="sidebar-category">Main Navigation</div>
                <ul class="sidebar-nav">
                    <li><a href="dashboard.php"><i class="fa-solid fa-gauge-high"></i> <span>Dashboard</span></a></li>
                    <li><a href="inspections.php"><i class="fa-solid fa-clipboard-check"></i> <span>Inspections</span></a></li>
                    <li><a href="inspection_schedule.php" class="active"><i class="fa-regular fa-calendar-check"></i> <span>Inspection Schedule</span></a></li>
                    <li><a href="calendar.php"><i class="fa-regular fa-calendar"></i> <span>Calendar</span></a></li>
                    <li><a href="reports.php"><i class="fa-solid fa-file-lines"></i> <span>Reports</span></a></li>
                </ul>

                <div class="sidebar-category">Field Ops &amp; Records</div>
                <ul class="sidebar-nav">
                    <li><a href="properties.php"><i class="fa-solid fa-building"></i> <span>Properties</span></a></li>
                    <li><a href="findings.php"><i class="fa-solid fa-triangle-exclamation"></i> <span>Findings</span></a></li>
                </ul>

                <div class="sidebar-category">Account</div>
                <ul class="sidebar-nav">
                    <li><a href="profile.php"><i class="fa-regular fa-user"></i> <span>Profile</span></a></li>
                </ul>
            </div>

            <div class="sidebar-footer">
                <a href="#" class="sidebar-logout-btn" data-bs-toggle="modal" data-bs-target="#logoutModal" title="Logout System">
                    <i class="fa-solid fa-right-from-bracket"></i>
                </a>
            </div>
        </div>
    </nav>

    <div class="main">
        <header class="top-navbar">
            <div class="navbar-title-wrap">
                <button class="hamburger-btn" id="sidebarToggle" title="Toggle Sidebar">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <h4>Inspection Schedule <span>Calendar View</span></h4>
            </div>

            <div class="navbar-right d-flex align-items-center gap-2">
                <div class="live-clock-box">
                    <span id="liveTime">00:00:00 PM</span>
                    <div class="date-sub" id="liveDate">Aug 30, 2026</div>
                </div>

                <div class="dropdown">
                    <button class="btn btn-dark d-flex align-items-center gap-2 dropdown-toggle text-white rounded-pill px-2 py-1" type="button" data-bs-toggle="dropdown" style="background:rgba(255,122,0,0.1) !important;border-color:var(--border-light) !important;">
                        <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($fullname); ?>&background=ff7a00&color=fff" class="rounded-circle" width="26" height="26">
                        <div class="text-start lh-1 d-none d-sm-block">
                            <span class="fw-semibold small d-block"><?php echo $fullname; ?></span>
                            <span class="text-uppercase" style="font-size: 8.5px; font-weight: 700; color: #ffb067;"><?php echo $staffRole; ?></span>
                        </div>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 py-2" style="min-width:180px; background:#251610; border: 1px solid var(--border-light)!important;">
                        <li><a class="dropdown-item py-2 text-light small" href="profile.php"><i class="fa-solid fa-user me-2 text-secondary"></i> Profile</a></li>
                        <li><hr class="dropdown-divider border-secondary opacity-25"></li>
                        <li><a class="dropdown-item py-2 text-danger small" href="#" data-bs-toggle="modal" data-bs-target="#logoutModal"><i class="fa-solid fa-right-from-bracket me-2"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </header>

        <div class="content">

            <div class="page-title mb-3">
                <h2 class="text-white fw-bold fs-4">Inspection Schedule</h2>
                <p class="text-light small" style="color: #d1d5db !important;">Calendar view of your assigned inspections.</p>
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

            <!-- CALENDAR + UPCOMING -->
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
                                if (in_array($statusLower, ['passed','completed'])) $statusClass = 'passed';
                                elseif (in_array($statusLower, ['failed','cancelled'])) $statusClass = 'failed';
                                elseif (in_array($statusLower, ['scheduled','ongoing'])) $statusClass = 'scheduled';
                                
                                $typeLabel = $hasInspectionType ? getInspectionTypeLabel($ins['inspection_type'] ?? '') : '';
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
                        <div style="text-align:center; padding:20px; color:#d1d5db; font-size:12px;">
                            <i class="fa-regular fa-calendar-xmark" style="font-size:24px; opacity:0.5; display:block; margin-bottom:6px;"></i>
                            No upcoming visits.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <footer class="footer-custom">
            <span>&copy; <?php echo date('Y'); ?> <span class="brand">RENTRACK LGU</span></span>
            <span>Unified Rental Registration &amp; Compliance System</span>
        </footer>
    </div>
</div>

<!-- INSPECTION DETAIL MODAL -->
<div class="modal fade" id="inspectionDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-white fw-bold fs-6">
                    <i class="fa-solid fa-clipboard-list me-2" style="color:var(--primary-orange-light);"></i> Inspection Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="inspectionDetailBody">
                <div class="text-center py-4">
                    <i class="fa-solid fa-spinner fa-spin fa-2x" style="color:var(--primary-orange);"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- LOGOUT MODAL -->
<div class="modal fade" id="logoutModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" style="color:var(--primary-orange-light); font-size: 15px;">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i> Logout Confirmation
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-4 text-center">
                <p class="text-light small mb-0">Are you sure you want to log out from your session?</p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-dark border-secondary px-4 py-2" data-bs-dismiss="modal" style="font-size: 12px;">Cancel</button>
                <a href="includes/auth.php?logout=true" class="btn btn-danger px-4 py-2" style="font-size: 12px; background: #dc3527; border: none;">Yes, Logout</a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Clock
    function updateClock() {
        const now = new Date();
        let hours = now.getHours();
        let minutes = now.getMinutes();
        let seconds = now.getSeconds();
        let ampm = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12;
        hours = hours ? hours : 12;
        minutes = minutes < 10 ? '0' + minutes : minutes;
        seconds = seconds < 10 ? '0' + seconds : seconds;
        
        const timeEl = document.getElementById('liveTime');
        const dateEl = document.getElementById('liveDate');
        if (timeEl) timeEl.textContent = `${hours}:${minutes}:${seconds} ${ampm}`;
        if (dateEl) dateEl.textContent = now.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }
    setInterval(updateClock, 1000);
    updateClock();

    // Sidebar toggle
    const sidebar = document.getElementById('sidebar');
    document.getElementById('sidebarToggle')?.addEventListener('click', function() {
        sidebar.classList.toggle('mobile-show');
    });

    document.addEventListener('click', function(e) {
        if (window.innerWidth < 992) {
            const toggleBtn = document.getElementById('sidebarToggle');
            if (!sidebar.contains(e.target) && toggleBtn && !toggleBtn.contains(e.target) && sidebar.classList.contains('mobile-show')) {
                sidebar.classList.remove('mobile-show');
            }
        }
    });

    // Inspection data for modal
    const inspectionsByDate = <?php 
        $jsonData = [];
        foreach ($inspectionsByDate as $date => $items) {
            $jsonData[$date] = array_map(function($item) use ($hasInspectionCategory, $hasInspectionType) {
                return [
                    'id' => $item['id'] ?? 0,
                    'inspection_no' => $item['inspection_no'] ?? '',
                    'business_name' => $item['business_name'] ?? $item['rental_name'] ?? 'Property',
                    'tracking_no' => $item['tracking_no'] ?? 'N/A',
                    'barangay' => $item['barangay'] ?? '',
                    'owner_name' => $item['owner_name'] ?? '—',
                    'inspection_date' => $item['inspection_date'] ?? '',
                    'inspection_time' => $item['inspection_time'] ?? '',
                    'inspection_type' => $hasInspectionType ? ($item['inspection_type'] ?? '') : '',
                    'inspection_category' => $hasInspectionCategory ? ($item['inspection_category'] ?? '') : '',
                    'inspection_status' => $item['inspection_status'] ?? 'Scheduled'
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
        if (['passed', 'completed'].includes(s)) return 'passed';
        if (['failed', 'cancelled'].includes(s)) return 'failed';
        if (['scheduled', 'ongoing'].includes(s)) return 'scheduled';
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
                <div style="text-align:center; padding:30px 20px; color:#d1d5db;">
                    <i class="fa-regular fa-calendar-xmark" style="font-size:32px; opacity:0.5; display:block; margin-bottom:10px; color:var(--primary-orange-light);"></i>
                    <h4 style="font-size:15px; font-weight:700; color:#fff; margin-bottom:6px;">No inspections scheduled</h4>
                    <p style="font-size:12.5px;">Walang naka-schedule na inspection sa <strong>${dateDisplay}</strong>.</p>
                </div>
            `;
            modal.show();
            return;
        }

        let html = `<div style="font-size:13px; color:#d1d5db; margin-bottom:14px; padding-bottom:10px; border-bottom:1px dashed rgba(255,122,0,0.25);">
            <i class="fa-regular fa-calendar me-1" style="color:var(--primary-orange-light);"></i> 
            <strong style="color:#fff;">${dateDisplay}</strong> &middot; ${items.length} ${items.length === 1 ? 'visit' : 'visits'}
        </div>`;

        items.forEach(function(item) {
            const status = item.inspection_status || 'Scheduled';
            const statusClass = getStatusClass(status);
            const typeLabel = getTypeLabel(item.inspection_type);
            const categoryLabel = item.inspection_category ? getCategoryLabel(item.inspection_category) : '';
            const timeDisplay = item.inspection_time || 'TBA';

            html += `
                <div style="padding:14px 16px; background:rgba(20,11,7,0.6); border:1px solid rgba(255,122,0,0.25); border-left:4px solid var(--primary-orange); border-radius:12px; margin-bottom:12px;">
                    <div style="display:flex; justify-content:space-between; gap:10px; align-items:flex-start; margin-bottom:8px;">
                        <div style="min-width:0; flex:1;">
                            <div style="font-weight:800; font-size:14px; color:#fff; margin-bottom:4px; word-break:break-word;">${escapeHtml(item.business_name)}</div>
                            <div style="font-size:11px; color:#d1d5db;">
                                <i class="fa-solid fa-barcode" style="font-size:10px;"></i> #${escapeHtml(item.tracking_no)}
                                ${item.barangay ? ' &middot; ' + escapeHtml(item.barangay) : ''}
                            </div>
                        </div>
                        <span class="status-badge ${statusClass}" style="margin-top:0;">${escapeHtml(status)}</span>
                    </div>
                    
                    <div style="display:flex; flex-wrap:wrap; gap:10px 16px; margin-bottom:8px; font-size:12px;">
                        <span style="display:flex; align-items:center; gap:5px; color:#d1d5db;">
                            <i class="fa-regular fa-clock" style="color:var(--primary-orange-light); font-size:11px;"></i> 
                            <strong style="color:#fff;">${escapeHtml(timeDisplay)}</strong>
                        </span>
                        ${typeLabel && typeLabel !== '—' ? `
                        <span style="display:flex; align-items:center; gap:5px; color:#d1d5db;">
                            <i class="fa-solid fa-clipboard-check" style="color:var(--primary-orange-light); font-size:11px;"></i> 
                            <strong style="color:#fff;">${escapeHtml(typeLabel)}</strong>
                        </span>` : ''}
                        ${categoryLabel ? `
                        <span style="display:flex; align-items:center; gap:5px; color:#d1d5db;">
                            <i class="fa-solid fa-layer-group" style="color:var(--primary-orange-light); font-size:11px;"></i> 
                            <strong style="color:#fff;">${escapeHtml(categoryLabel)}</strong>
                        </span>` : ''}
                    </div>
                    
                    <div style="font-size:11px; color:#d1d5db;">
                        <i class="fa-solid fa-user-shield" style="font-size:10px;"></i> Owner: <strong style="color:#fff;">${escapeHtml(item.owner_name)}</strong>
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
</script>
</body>
</html>