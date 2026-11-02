<?php
include "../config/db.php";

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../config/session.php'; app_session_start();
}

date_default_timezone_set("Asia/Manila");

$inspectorId = (int) ($_SESSION['user_id'] ?? 0);
$fullname = htmlspecialchars($_SESSION['fullname'] ?? 'Inspector', ENT_QUOTES, 'UTF-8');
$staffRole = $_SESSION['role'] ?? "Inspector";

/* Helper Function for PostgreSQL Table Check */
function tableExists($conn, $tableName) {
    try {
        $stmt = $conn->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :t)");
        $stmt->execute([':t' => strtolower($tableName)]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) { return false; }
}

$searchQuery = trim($_GET['q'] ?? '');
$statusFilterParam = trim($_GET['status'] ?? '');

// Helper function for permit status if functions.php is missing
function permitStatusLocal($d) {
    if (empty($d)) return ['label' => 'No Record', 'class' => 'secondary', 'sort' => 3];
    $today = time();
    $expiry = strtotime($d);
    $days = (int) floor(($expiry - $today) / 86400);
    if ($days < 0) return ['label' => 'Expired', 'class' => 'danger', 'sort' => 1];
    if ($days <= 30) return ['label' => "Expires in {$days}d", 'class' => 'warning', 'sort' => 2];
    return ['label' => 'Valid', 'class' => 'success', 'sort' => 4];
}

if (!function_exists('statusBadge')) {
    function statusBadge($status) {
        switch (strtolower(trim($status))) {
            case 'completed':
                return '<span class="status-badge passed"><i class="fa-regular fa-circle-check me-1"></i>Completed</span>';
            case 'passed':
                return '<span class="status-badge passed"><i class="fa-regular fa-circle-check me-1"></i>Passed</span>';
            case 'ongoing':
                return '<span class="status-badge scheduled"><i class="fa-regular fa-hourglass-half me-1"></i>Ongoing</span>';
            case 'scheduled':
                return '<span class="status-badge scheduled"><i class="fa-regular fa-clock me-1"></i>Scheduled</span>';
            case 'failed':
            case 'cancelled':
                return '<span class="status-badge failed"><i class="fa-regular fa-circle-xmark me-1"></i>' . ucfirst($status) . '</span>';
            default:
                $cleanName = htmlspecialchars($status !== '' ? $status : 'Pending', ENT_QUOTES, 'UTF-8');
                return '<span class="status-badge pending">' . $cleanName . '</span>';
        }
    }
}

$rows = [];

if (tableExists($conn, 'inspections') && tableExists($conn, 'rentals')) {
    // Idinagdag ang r.is_deleted filter upang mawala kapag binura sa Staff Portal
    $stmt = $conn->prepare("
        SELECT DISTINCT
               r.id AS rental_id,
               r.rental_name,
               r.business_name,
               r.tracking_no,
               r.barangay,
               r.permit_expiry,
               i.inspection_status,
               u.fullname AS owner_name
        FROM inspections i
        INNER JOIN rentals r ON i.rental_id = r.id
        LEFT JOIN users u ON r.user_id = u.id
        WHERE i.inspector_id = :iid
          AND (r.is_deleted = FALSE OR r.is_deleted IS NULL)
        ORDER BY r.permit_expiry ASC
    ");
    $stmt->execute([':iid' => $inspectorId]);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $status = permitStatusLocal($row['permit_expiry'] ?? null);
        $row['permit_status'] = $status;
        $rows[] = $row;
    }
}

$totalExpired = count(array_filter($rows, fn($r) => $r['permit_status']['label'] === 'Expired'));
$totalExpiring = count(array_filter($rows, fn($r) => str_starts_with($r['permit_status']['label'], 'Expires')));
$totalValid = count(array_filter($rows, fn($r) => $r['permit_status']['label'] === 'Valid'));

if ($statusFilterParam !== '' && $statusFilterParam !== 'all') {
    $rows = array_filter($rows, fn($r) => $r['permit_status']['label'] === $statusFilterParam
        || ($statusFilterParam === 'Expiring' && str_starts_with($r['permit_status']['label'], 'Expires')));
    $rows = array_values($rows);
}

usort($rows, function ($a, $b) {
    return $a['permit_status']['sort'] <=> $b['permit_status']['sort'];
});

$totalAssigned = 0;
if (tableExists($conn, 'inspections')) {
    $totalStmt = $conn->prepare("SELECT COUNT(*) FROM inspections WHERE inspector_id = :iid");
    $totalStmt->execute([':iid' => $inspectorId]);
    $totalAssigned = (int) $totalStmt->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RENTRACK · Permits Reference</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-orange: #ff7a00;
            --primary-orange-dark: #d95300;
            --primary-orange-light: #ffb067;
            
            --sidebar-bg: rgba(20, 11, 7, 0.9);
            --topbar-gov-bg: #140b07;
            --sidebar-hover: rgba(255, 122, 0, 0.15);
            
            --card-bg: rgba(31, 20, 15, 0.8); 
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

        /* ORANGE GOV TOP BAR */
        .gov-top-bar {
            background: var(--topbar-gov-bg);
            color: #ffb067;
            padding: 8px 24px;
            font-size: 11.5px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--border-light);
            z-index: 100;
        }
        .gov-top-bar .gov-left { display: flex; align-items: center; gap: 10px; }
        .gov-top-bar .gov-right { display: flex; align-items: center; gap: 15px; color: #f3f4f6; }
        .gov-badge { background: rgba(255, 122, 0, 0.25); color: #ffb067; padding: 3px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; border: 1px solid rgba(255, 122, 0, 0.5); }

        @keyframes spinSlow {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .brand-icon-wrapper {
            position: relative;
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .brand-spinner {
            position: absolute;
            width: 100%;
            height: 100%;
            border: 2px dashed var(--primary-orange);
            border-radius: 50%;
            animation: spinSlow 8s linear infinite;
        }
        .brand-icon {
            width: 34px;
            height: 34px;
            background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-light));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            color: #fff;
            box-shadow: 0 0 15px rgba(255, 122, 0, 0.6);
            z-index: 2;
        }

        .wrapper { display: flex; width: 100%; min-height: calc(100vh - 37px); align-items: stretch; }
        .main { flex: 1; min-width: 0; background: transparent; display: flex; flex-direction: column; transition: all 0.3s ease; }

        /* SIDEBAR */
        .sidebar {
            width: 280px; background: var(--sidebar-bg); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
            color: #fff; min-height: 100vh; flex-shrink: 0; border-right: 1px solid var(--border-light);
            display: flex; flex-direction: column; position: sticky; top: 0; height: 100vh; overflow-y: auto; transition: all 0.3s ease; z-index: 40;
        }
        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255, 122, 0, 0.4); border-radius: 10px; }

        .sidebar-brand { padding: 22px 20px; display: flex; align-items: center; gap: 14px; border-bottom: 1px solid var(--border-light); }
        .brand-text .main-title { font-size: 16px; font-weight: 800; color: #fff; line-height: 1.1; letter-spacing: 0.5px; }
        .brand-text .sub-title { font-size: 9.5px; font-weight: 600; color: var(--primary-orange-light); text-transform: uppercase; letter-spacing: 1px; }

        .sidebar-category { font-size: 10px; text-transform: uppercase; letter-spacing: 1.2px; color: rgba(255, 255, 255, 0.6); padding: 18px 20px 6px 20px; font-weight: 700; }
        .sidebar-nav { list-style: none; padding: 0 12px; margin: 0; }
        .sidebar-nav li { margin-bottom: 2px; }
        .sidebar-nav a {
            display: flex; align-items: center; gap: 14px; padding: 10px 14px; color: #f3f4f6;
            text-decoration: none; border-radius: 10px; font-size: 13.5px; font-weight: 500; transition: all 0.2s ease; position: relative;
        }
        .sidebar-nav a i { font-size: 15px; width: 20px; text-align: center; color: var(--primary-orange-light); transition: all 0.2s ease; }
        .sidebar-nav a:hover { background: var(--sidebar-hover); color: #fff; }
        .sidebar-nav a.active { background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-dark)); color: #fff; box-shadow: 0 4px 15px rgba(255, 122, 0, 0.4); }
        .sidebar-nav a.active i { color: #fff; }
        .sidebar-nav a .badge-nav { margin-left: auto; background: rgba(255, 255, 255, 0.2); color: #fff; font-size: 10px; padding: 2px 8px; border-radius: 20px; font-weight: 600; }

        .sidebar-footer { padding: 16px; border-top: 1px solid var(--border-light); margin-top: auto; display: flex; justify-content: center; }
        .sidebar-logout-btn {
            width: 52px; height: 52px; background: rgba(255, 122, 0, 0.08); border: 1.5px solid var(--primary-orange);
            color: var(--primary-orange-light); border-radius: 50%; display: flex; align-items: center; justify-content: center;
            text-decoration: none; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(255, 122, 0, 0.25);
        }
        .sidebar-logout-btn i { font-size: 20px; }
        .sidebar-logout-btn:hover { background: var(--primary-orange); color: #fff; transform: scale(1.08); box-shadow: 0 6px 20px rgba(255, 122, 0, 0.5); }

        /* TOP NAVBAR */
        .top-navbar {
            background: rgba(20, 11, 7, 0.85); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
            padding: 12px 28px; border-bottom: 1px solid var(--border-light);
            display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; position: sticky; top: 0; z-index: 30; color: #fff;
        }
        .navbar-title-wrap { display: flex; align-items: center; gap: 14px; }
        .navbar-title-wrap h4 { font-size: 17px; font-weight: 700; margin: 0; color: #fff; display: flex; align-items: center; gap: 10px; }
        .navbar-title-wrap h4 span { font-size: 12.5px; font-weight: 400; color: #d1d5db; }

        .hamburger-btn {
            background: rgba(255, 122, 0, 0.15); border: 1px solid var(--border-light); color: var(--primary-orange-light); width: 38px; height: 38px;
            border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 16px; cursor: pointer; transition: 0.2s;
        }
        .hamburger-btn:hover { background: var(--primary-orange); color: #fff; border-color: var(--primary-orange); }

        /* SEARCH TRIGGER & POPUP */
        .search-trigger-box {
            position: relative; flex: 1; max-width: 300px; background: rgba(255, 122, 0, 0.08); border: 1px solid var(--border-light);
            padding: 8px 16px 8px 36px; border-radius: 10px; font-size: 13px; color: #f3f4f6;
            cursor: pointer; transition: 0.2s; user-select: none; display: flex; align-items: center; font-weight: 500;
        }
        .search-trigger-box:hover { border-color: var(--primary-orange); color: #fff; background: rgba(255, 122, 0, 0.15); }
        .search-trigger-box i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--primary-orange-light); font-size: 13px; }

        .search-popup-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(10, 5, 3, 0.85);
            backdrop-filter: blur(8px); z-index: 9999; display: flex; align-items: flex-start; justify-content: center;
            padding-top: 100px; opacity: 0; pointer-events: none; transition: opacity 0.3s ease;
        }
        .search-popup-overlay.active { opacity: 1; pointer-events: auto; }
        .search-popup-card {
            background: #251610; border: 1px solid rgba(255, 122, 0, 0.5); width: 100%; max-width: 580px;
            border-radius: 14px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.8); padding: 20px; transform: translateY(-15px);
            transition: transform 0.3s ease;
        }
        .search-popup-overlay.active .search-popup-card { transform: translateY(0); }
        .search-popup-input-wrap { position: relative; width: 100%; }
        .search-popup-input-wrap input {
            width: 100%; background: #140b07; border: 1.5px solid var(--primary-orange); padding: 12px 18px 12px 44px;
            border-radius: 10px; font-size: 15px; color: #fff; outline: none; box-shadow: 0 0 20px rgba(255,122,0,0.3);
        }
        .search-popup-input-wrap i { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--primary-orange-light); font-size: 16px; }
        .search-popup-footer { display: flex; justify-content: space-between; align-items: center; margin-top: 12px; font-size: 11.5px; color: #d1d5db; }
        .search-popup-footer kbd { background: rgba(255, 122, 0, 0.25); color: #ffb067; padding: 2px 6px; border-radius: 4px; border: 1px solid rgba(255, 122, 0, 0.4); font-weight: 600; }

        /* LIVE CLOCK */
        .live-clock-box {
            background: rgba(255, 122, 0, 0.1); border: 1px solid var(--border-light); padding: 5px 12px; border-radius: 8px;
            text-align: center; color: var(--primary-orange-light); font-family: monospace; font-weight: 700; font-size: 12.5px;
        }
        .live-clock-box .date-sub { font-size: 9px; color: #d1d5db; font-family: 'Inter', sans-serif; font-weight: 500; }

        /* STATUS BADGES FROM INSPECTIONS */
        .status-badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        .status-badge.passed { background: rgba(16, 185, 129, 0.2); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.4); }
        .status-badge.scheduled { background: rgba(255, 122, 0, 0.2); color: #ffb067; border: 1px solid rgba(255, 122, 0, 0.4); }
        .status-badge.failed { background: rgba(239, 68, 68, 0.2); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.4); }
        .status-badge.pending { background: rgba(245, 158, 11, 0.2); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.4); }

        /* CONTENT SPECIFICS */
        .content { flex: 1; padding: 28px 32px 20px; }
        .permit-summary { display: flex; gap: 14px; flex-wrap: wrap; margin-bottom: 20px; }
        .permit-summary .chip {
            flex: 1; min-width: 160px; background: var(--card-bg); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid var(--border-light); border-radius: 16px; padding: 18px 20px;
            box-shadow: var(--card-shadow); display: flex; align-items: center; gap: 14px; cursor: pointer; text-decoration: none; transition: .2s; color: #fff;
        }
        .permit-summary .chip:hover { transform: translateY(-3px); border-color: var(--primary-orange); }
        .permit-summary .chip.active-chip { outline: 2px solid var(--primary-orange); }
        .permit-summary .chip i { font-size: 22px; }
        .permit-summary .chip.danger i { color: #f87171; }
        .permit-summary .chip.warning i { color: #fbbf24; }
        .permit-summary .chip.success i { color: #34d399; }
        .permit-summary .chip h4 { margin: 0; font-size: 24px; font-weight: 800; color: #fff; }
        .permit-summary .chip small { color: var(--text-light); font-weight: 700; text-transform: uppercase; font-size: 11px; letter-spacing: 0.03em; }
        
        .readonly-note { font-size: 13px; color: var(--text-light); margin-bottom: 16px; display: flex; align-items: center; gap: 8px; font-weight: 500; }
        .filter-bar { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 16px; }
        .filter-bar input[type="text"] { max-width: 280px; background: #140b07; border: 1px solid var(--border-light); color: #fff; border-radius: 10px; padding: 8px 14px; font-size: 13.5px; }
        .filter-bar input[type="text"]::placeholder { color: #9ca3af; }

        .card { background: var(--card-bg); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid var(--border-light); border-radius: 16px; box-shadow: var(--card-shadow); color: #fff; }
        .card-header { background: transparent; border-bottom: 1px solid var(--border-light); padding: 18px 24px; font-weight: 700; color: #fff; }
        
        .table-responsive { background: transparent !important; }
        .table { color: #ffffff !important; margin-bottom: 0; background-color: transparent !important; }
        .table > :not(caption) > * > * { background-color: transparent !important; color: #ffffff !important; box-shadow: none !important; }
        
        .table tbody tr { background-color: rgba(25, 14, 9, 0.9) !important; color: #ffffff !important; border-bottom: 1px solid var(--border-light) !important; }
        .table.table-hover tbody tr:hover { background-color: rgba(255, 122, 0, 0.15) !important; color: #ffffff !important; }
        
        .table-light { background: rgba(255, 122, 0, 0.04) !important; color: #ffffff !important; border-bottom: 2px solid var(--border-light) !important; }
        .table-light th { background: transparent !important; color: #ffffff !important; font-size: 11px; text-transform: uppercase; font-weight: 700; padding: 12px 20px; }
        
        .table td, .table th { border-color: var(--border-light) !important; color: #ffffff !important; padding: 13px 20px; vertical-align: middle; font-weight: 500; font-size: 13.5px; }
        .table td small { color: #ffb067 !important; }

        /* FOOTER */
        .footer-custom {
            background: rgba(20, 11, 7, 0.7); backdrop-filter: blur(10px); border-top: 1px solid var(--border-light); padding: 14px 32px;
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; font-size: 11.5px; color: #d1d5db; margin-top: auto; font-weight: 500;
        }
        .footer-custom .brand { color: var(--primary-orange); font-weight: 800; text-shadow: 0 0 8px rgba(255,122,0,0.4); }

        body.sidebar-collapsed .sidebar { width: 80px !important; }
        body.sidebar-collapsed .sidebar .sidebar-brand .brand-text,
        body.sidebar-collapsed .sidebar .sidebar-category,
        body.sidebar-collapsed .sidebar .sidebar-nav a span,
        body.sidebar-collapsed .sidebar .sidebar-nav a .badge-nav { display: none !important; }
        body.sidebar-collapsed .sidebar .sidebar-brand { justify-content: center; padding: 22px 10px; }
        body.sidebar-collapsed .sidebar .sidebar-nav a { justify-content: center; padding: 12px; }
        body.sidebar-collapsed .sidebar .sidebar-nav a i { width: auto; font-size: 18px; }
    </style>
</head>
<body>

    <!-- ORANGE GOV TOP BAR -->
    <div class="gov-top-bar">
        <div class="gov-left">
            <i class="fa-solid fa-landmark text-warning"></i>
            <span>REPUBLIC OF THE PHILIPPINES</span>
            <span class="text-secondary">|</span>
            <span class="text-light">Local Government Unit Property &amp; Rental Compliance Portal</span>
        </div>
        <div class="gov-right">
            <span class="gov-badge"><i class="fa-solid fa-shield-halved me-1"></i> LGU CERTIFIED PORTAL V2.1</span>
            <span>City Treasurer &amp; Rentrack Online System</span>
        </div>
    </div>

<div class="wrapper">
    <!-- SIDEBAR -->
    <nav class="sidebar" id="sidebar">
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

        <div class="sidebar-category">Main Navigation</div>
        <ul class="sidebar-nav">
            <li><a href="dashboard.php"><i class="fa-solid fa-gauge-high"></i> <span>Dashboard</span></a></li>
            <li><a href="inspections.php"><i class="fa-solid fa-clipboard-check"></i> <span>Inspections</span> <span class="badge-nav"><?php echo $totalAssigned; ?></span></a></li>
            <li><a href="calendar.php"><i class="fa-regular fa-calendar"></i> <span>Calendar</span></a></li>
            <li><a href="reports.php"><i class="fa-solid fa-file-lines"></i> <span>Reports</span></a></li>
        </ul>

        <div class="sidebar-category">Field Ops &amp; Records</div>
        <ul class="sidebar-nav">
            <li><a href="properties.php"><i class="fa-solid fa-building"></i> <span>Properties</span></a></li>
            <li><a href="permits.php" class="active"><i class="fa-solid fa-id-card"></i> <span>Permits</span></a></li>
            <li><a href="compliance.php"><i class="fa-solid fa-shield-halved"></i> <span>Compliance</span></a></li>
            <li><a href="findings.php"><i class="fa-solid fa-triangle-exclamation"></i> <span>Findings</span></a></li>
            <li><a href="analytics.php"><i class="fa-solid fa-chart-pie"></i> <span>Analytics</span></a></li>
        </ul>

        <div class="sidebar-category">Account</div>
        <ul class="sidebar-nav">
            <li><a href="profile.php"><i class="fa-regular fa-user"></i> <span>Profile</span></a></li>
            <li><a href="settings.php"><i class="fa-solid fa-gear"></i> <span>Settings</span></a></li>
        </ul>

        <div class="sidebar-footer">
            <a href="#" class="sidebar-logout-btn" data-bs-toggle="modal" data-bs-target="#logoutModal" title="Logout System">
                <i class="fa-solid fa-right-from-bracket"></i>
            </a>
        </div>
    </nav>

    <!-- MAIN CONTENT -->
    <div class="main">
        <!-- TOP NAVBAR -->
        <header class="top-navbar">
            <div class="navbar-title-wrap">
                <button class="hamburger-btn" id="sidebarToggle" title="Toggle Sidebar">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <h4>Permits Reference <span>Establishments Status Overview</span></h4>
            </div>

            <!-- SEARCH TRIGGER BOX -->
            <div class="search-trigger-box" id="openSearchPopup">
                <i class="fa-solid fa-search"></i>
                <span>Search records...</span>
            </div>

            <div class="navbar-right d-flex align-items-center gap-3">
                <div class="live-clock-box">
                    <span id="liveTime">00:00:00 PM</span>
                    <div class="date-sub" id="liveDate">Aug 30, 2026</div>
                </div>

                <div class="dropdown">
                    <button class="btn btn-dark border-secondary position-relative rounded-circle p-2 text-white" type="button" data-bs-toggle="dropdown" style="width:38px;height:38px;background:rgba(255,122,0,0.1) !important;border-color:var(--border-light) !important;">
                        <i class="fa-regular fa-bell" style="font-size:14px;"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 9px;">3</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 py-2" style="width: 270px; background:#251610; border: 1px solid var(--border-light)!important;">
                        <li><h6 class="dropdown-header text-uppercase text-warning fw-bold" style="font-size: 10px;">Notifications</h6></li>
                        <li><a class="dropdown-item py-2 small text-light" href="inspections.php">New inspection assigned<br><span class="text-muted" style="font-size: 10px;">10 mins ago</span></a></li>
                        <li><a class="dropdown-item py-2 small text-light" href="compliance.php">Compliance warning for Sector 7<br><span class="text-muted" style="font-size: 10px;">1 hour ago</span></a></li>
                    </ul>
                </div>

                <div class="dropdown">
                    <button class="btn btn-dark d-flex align-items-center gap-2 dropdown-toggle text-white rounded-pill px-3 py-1" type="button" data-bs-toggle="dropdown" style="background:rgba(255,122,0,0.1) !important;border-color:var(--border-light) !important;">
                        <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($fullname); ?>&background=ff7a00&color=fff" class="rounded-circle" width="28" height="28">
                        <div class="text-start lh-1">
                            <span class="fw-semibold small d-block"><?php echo $fullname; ?></span>
                            <span class="text-uppercase" style="font-size: 9px; font-weight: 700; color: #ffb067;"><?php echo $staffRole; ?></span>
                        </div>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 py-2" style="min-width:200px;border-radius:12px; background:#251610; border: 1px solid var(--border-light)!important;">
                        <li><a class="dropdown-item py-2 text-light small" href="profile.php"><i class="fa-solid fa-user me-2 text-secondary"></i> Profile</a></li>
                        <li><a class="dropdown-item py-2 text-light small" href="settings.php"><i class="fa-solid fa-gear me-2 text-secondary"></i> Settings</a></li>
                        <li><hr class="dropdown-divider border-secondary opacity-25"></li>
                        <li><a class="dropdown-item py-2 text-danger small" href="#" data-bs-toggle="modal" data-bs-target="#logoutModal"><i class="fa-solid fa-right-from-bracket me-2"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </header>

        <!-- SEARCH POPUP MODAL -->
        <div class="search-popup-overlay" id="searchPopupOverlay">
            <div class="search-popup-card">
                <form action="permits.php" method="GET">
                    <div class="search-popup-input-wrap">
                        <i class="fa-solid fa-search"></i>
                        <input type="text" name="q" id="popupSearchInput" placeholder="Type to search permits, owners..." autocomplete="off">
                    </div>
                </form>
                <div class="search-popup-footer">
                    <span>Press <kbd>Enter</kbd> to search records</span>
                    <span>Press <kbd>ESC</kbd> to close</span>
                </div>
            </div>
        </div>

        <div class="content">
            <p class="readonly-note"><i class="fa-solid fa-circle-info text-warning"></i> Reference view only — permit issuance and renewal are managed by the LGU Admin.</p>

            <div class="permit-summary">
                <a href="?status=Expired" class="chip danger <?php echo $statusFilterParam === 'Expired' ? 'active-chip' : ''; ?>">
                    <i class="fa-solid fa-circle-xmark"></i>
                    <div><h4><?php echo $totalExpired; ?></h4><small>Expired</small></div>
                </a>
                <a href="?status=Expiring" class="chip warning <?php echo $statusFilterParam === 'Expiring' ? 'active-chip' : ''; ?>">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div><h4><?php echo $totalExpiring; ?></h4><small>Expiring Soon</small></div>
                </a>
                <a href="?status=Valid" class="chip success <?php echo $statusFilterParam === 'Valid' ? 'active-chip' : ''; ?>">
                    <i class="fa-solid fa-circle-check"></i>
                    <div><h4><?php echo $totalValid; ?></h4><small>Valid</small></div>
                </a>
                <?php if ($statusFilterParam !== '') { ?>
                    <a href="permits.php" class="btn btn-sm btn-outline-secondary align-self-center text-white border-secondary">Clear filter</a>
                <?php } ?>
            </div>

            <form method="GET" class="filter-bar">
                <?php if ($statusFilterParam !== '') { ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilterParam, ENT_QUOTES, 'UTF-8'); ?>"><?php } ?>
                <input type="text" name="q" id="localSearch" class="form-control" placeholder="Search property, owner, barangay..." value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>">
            </form>

            <div class="card mb-4 animate-in">
                <div class="card-header border-bottom border-secondary d-flex align-items-center" style="border-color:var(--border-light) !important;">
                    <i class="fa-solid fa-list me-2" style="color:var(--primary-orange-light)"></i>
                    <span class="text-white">Assigned Establishments</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($rows)) { ?>
                    <div class="text-center text-light py-5" style="color: #d1d5db !important;">
                        <i class="fa-solid fa-id-card fa-3x mb-3 text-warning" style="opacity:0.6;"></i>
                        <p class="fs-6 fw-bold text-white">No assigned establishments yet.</p>
                    </div>
                    <?php } else { ?>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Property</th>
                                    <th>Owner</th>
                                    <th>Barangay</th>
                                    <th>Tracking No.</th>
                                    <th>Permit Expiry</th>
                                    <th>Permit Status</th>
                                    <th>Inspection Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($rows as$row) {
                                $rowSearchBlob = strtolower(($row['rental_name'] ?? '') . ' ' . ($row['business_name'] ?? '') . ' ' . ($row['owner_name'] ?? '') . ' ' . ($row['barangay'] ?? '') . ' ' . ($row['tracking_no'] ?? ''));
                            ?>
                            <tr data-search="<?php echo htmlspecialchars($rowSearchBlob, ENT_QUOTES, 'UTF-8'); ?>">
                                <td><strong class="text-white"><?php echo htmlspecialchars($row['rental_name'] ?? $row['business_name'] ?? 'N/A'); ?></strong></td>
                                <td><?php echo htmlspecialchars($row['owner_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($row['barangay'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($row['tracking_no'] ?? 'N/A'); ?></td>
                                <td><?php echo !empty($row['permit_expiry']) ? date('M d, Y', strtotime($row['permit_expiry'])) : '—'; ?></td>
                                <td><span class="badge bg-<?php echo $row['permit_status']['class']; ?>" style="font-size:10.5px;"><?php echo htmlspecialchars($row['permit_status']['label']); ?></span></td>
                                <td><?php echo statusBadge($row['inspection_status'] ?? ''); ?></td>
                            </tr>
                            <?php } ?>
                            </tbody>
                        </table>
                    </div>
                    <?php } ?>
                </div>
            </div>
        </div>

        <!-- FOOTER -->
        <footer class="footer-custom">
            <span>&copy; <?php echo date('Y'); ?> <span class="brand">RENTRACK LGU</span></span>
            <span>Unified Rental Registration, Tracking &amp; Compliance System</span>
        </footer>
    </div>
</div>

<!-- LOGOUT MODAL -->
<div class="modal fade" id="logoutModal" tabindex="-1" aria-labelledby="logoutModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: #251610; border: 1px solid var(--border-light); color: #fff; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.8);">
            <div class="modal-header border-bottom border-secondary border-opacity-25">
                <h5 class="modal-title fw-bold" id="logoutModalLabel" style="font-size: 16px; color: var(--primary-orange-light);">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i> Logout Confirmation
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4 text-center">
                <div class="mb-3">
                    <div style="width: 60px; height: 60px; margin: 0 auto; background: rgba(255, 122, 0, 0.15); border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 1.5px solid var(--primary-orange);">
                        <i class="fa-solid fa-right-from-bracket" style="font-size: 24px; color: var(--primary-orange-light);"></i>
                    </div>
                </div>
                <h5 class="fw-bold mb-2 text-white">Are you sure you want to log out?</h5>
                <p class="text-light small mb-0 opacity-75">Your active session will be terminated and you will need to log in again.</p>
            </div>
            <div class="modal-footer border-top border-secondary border-opacity-25 justify-content-center">
                <button type="button" class="btn btn-dark border-secondary px-4 py-2" data-bs-dismiss="modal" style="font-size: 13px; font-weight: 600;">Cancel</button>
                <a href="includes/auth.php?logout=true" class="btn btn-danger px-4 py-2" style="font-size: 13px; font-weight: 600; background: #dc3527; border: none;">Yes, Logout</a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
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
        
        document.getElementById('liveTime').textContent = `${hours}:${minutes}:${seconds} ${ampm}`;
        const options = { month: 'short', day: 'numeric', year: 'numeric' };
        document.getElementById('liveDate').textContent = now.toLocaleDateString('en-US', options);
    }
    setInterval(updateClock, 1000);
    updateClock();

    document.getElementById('sidebarToggle')?.addEventListener('click', function() {
        document.body.classList.toggle('sidebar-collapsed');
    });

    const searchTrigger = document.getElementById('openSearchPopup');
    const searchOverlay = document.getElementById('searchPopupOverlay');
    const popupSearchInput = document.getElementById('popupSearchInput');

    searchTrigger.addEventListener('click', function() {
        searchOverlay.classList.add('active');
        setTimeout(() => popupSearchInput.focus(), 100);
    });

    searchOverlay.addEventListener('click', function(e) {
        if (e.target === searchOverlay) {
            searchOverlay.classList.remove('active');
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            searchOverlay.classList.remove('active');
        }
        if ((e.key === '/' || (e.ctrlKey && e.key === 'k')) && document.activeElement !== popupSearchInput) {
            e.preventDefault();
            searchOverlay.classList.add('active');
            setTimeout(() => popupSearchInput.focus(), 100);
        }
    });

    const localSearchInput = document.getElementById('localSearch');
    function applyLocalSearch() {
        const kw = (localSearchInput.value || '').toLowerCase().trim();
        document.querySelectorAll('table tbody tr[data-search]').forEach(function (row) {
            row.style.display = row.dataset.search.includes(kw) ? '' : 'none';
        });
    }
    if (localSearchInput) {
        localSearchInput.addEventListener('keyup', applyLocalSearch);
        applyLocalSearch();
    }
</script>
</body>
</html>