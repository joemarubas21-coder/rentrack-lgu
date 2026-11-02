<?php
include "../config/db.php";

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../config/session.php'; app_session_start();
}

date_default_timezone_set("Asia/Manila");

$inspectorId = (int)($_SESSION['user_id'] ?? 0);
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

$counts = ['total' => 0, 'scheduled' => 0, 'ongoing' => 0, 'completed' => 0, 'passed' => 0, 'failed' => 0, 'cancelled' => 0];

if (tableExists($conn, 'inspections') && tableExists($conn, 'rentals')) {
    $stmtCount = $conn->prepare("
        SELECT i.inspection_status, COUNT(*) AS c 
        FROM inspections i 
        INNER JOIN rentals r ON i.rental_id = r.id 
        WHERE i.inspector_id = :iid 
          AND (r.is_deleted = FALSE OR r.is_deleted IS NULL)
        GROUP BY i.inspection_status
    ");
    $stmtCount->execute([':iid' => $inspectorId]);
    while ($r = $stmtCount->fetch(PDO::FETCH_ASSOC)) {
        $k = strtolower(trim($r['inspection_status']));
        $cnt = (int)$r['c'];
        $counts['total'] += $cnt;
        if (isset($counts[$k])) {
            $counts[$k] += $cnt;
        }
    }
}

$avg = 0;
if (tableExists($conn, 'inspector_checklists') && tableExists($conn, 'inspections') && tableExists($conn, 'rentals')) {
    $stmtAvg = $conn->prepare("
        SELECT AVG(c.compliance_score) AS avg_score 
        FROM inspector_checklists c 
        INNER JOIN inspections i ON i.id = c.inspection_id 
        INNER JOIN rentals r ON i.rental_id = r.id
        WHERE i.inspector_id = :iid
          AND (r.is_deleted = FALSE OR r.is_deleted IS NULL)
    ");
    $stmtAvg->execute([':iid' => $inspectorId]);
    $resAvg = $stmtAvg->fetch(PDO::FETCH_ASSOC);
    if ($resAvg && isset($resAvg['avg_score'])) {
        $avg = round((float)$resAvg['avg_score']);
    }
}

$monthly = array_fill(1, 12, 0);
if (tableExists($conn, 'inspections') && tableExists($conn, 'rentals')) {
    $stmtMonthly = $conn->prepare("
        SELECT EXTRACT(MONTH FROM i.inspection_date) AS m, COUNT(*) AS c 
        FROM inspections i
        INNER JOIN rentals r ON i.rental_id = r.id
        WHERE i.inspector_id = :iid 
          AND EXTRACT(YEAR FROM i.inspection_date) = EXTRACT(YEAR FROM CURRENT_DATE) 
          AND (r.is_deleted = FALSE OR r.is_deleted IS NULL)
        GROUP BY EXTRACT(MONTH FROM i.inspection_date)
    ");
    $stmtMonthly->execute([':iid' => $inspectorId]);
    while ($r = $stmtMonthly->fetch(PDO::FETCH_ASSOC)) {
        $mKey = (int)$r['m'];
        if ($mKey >= 1 && $mKey <= 12) {
            $monthly[$mKey] = (int)$r['c'];
        }
    }
}

$totalAssigned = 0;
if (tableExists($conn, 'inspections') && tableExists($conn, 'rentals')) {
    $totalStmt = $conn->prepare("
        SELECT COUNT(*) FROM inspections i
        INNER JOIN rentals r ON i.rental_id = r.id
        WHERE i.inspector_id = :iid
          AND (r.is_deleted = FALSE OR r.is_deleted IS NULL)
    ");
    $totalStmt->execute([':iid' => $inspectorId]);
    $totalAssigned = (int) $totalStmt->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RENTRACK · Inspection Analytics</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-orange: #ff7a00;
            --primary-orange-dark: #e06d00;
            --primary-orange-light: #ff9f43;
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

        /* ANALYTICS SPECIFICS & VIBRANT FULL COLOR CARDS */
        .content { flex: 1; padding: 28px 32px 20px; }
        .summary-card {
            border: none !important; border-radius: 18px; box-shadow: 0 10px 25px rgba(0,0,0,0.4); transition: .2s ease; color: #fff;
        }
        .summary-card:hover { transform: translateY(-4px); box-shadow: 0 15px 30px rgba(0,0,0,0.6); }

        .card-total { background: linear-gradient(135deg, #2563eb, #1d4ed8) !important; }
        .card-passed { background: linear-gradient(135deg, #059669, #047857) !important; }
        .card-failed { background: linear-gradient(135deg, #dc2626, #b91c1c) !important; }
        .card-compliance { background: linear-gradient(135deg, #d97706, #b45309) !important; }

        .summary-icon {
            width: 52px; height: 52px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 20px;
            background: rgba(255, 255, 255, 0.25); color: #fff; backdrop-filter: blur(4px);
        }

        .card { background: var(--card-bg); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid var(--border-light); border-radius: 16px; box-shadow: var(--card-shadow); color: #fff; }
        .card-header { background: transparent; border-bottom: 1px solid var(--border-light); padding: 18px 24px; font-weight: 700; color: #fff; }

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
            <li><a href="permits.php"><i class="fa-solid fa-id-card"></i> <span>Permits</span></a></li>
            <li><a href="compliance.php"><i class="fa-solid fa-shield-halved"></i> <span>Compliance</span></a></li>
            <li><a href="findings.php"><i class="fa-solid fa-triangle-exclamation"></i> <span>Findings</span></a></li>
            <li><a href="analytics.php" class="active"><i class="fa-solid fa-chart-pie"></i> <span>Analytics</span></a></li>
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
                <h4>Inspection Analytics <span>Monitor Results &amp; Trends</span></h4>
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
                <form action="analytics.php" method="GET">
                    <div class="search-popup-input-wrap">
                        <i class="fa-solid fa-search"></i>
                        <input type="text" name="search" id="popupSearchInput" placeholder="Type to search analytics..." autocomplete="off">
                    </div>
                </form>
                <div class="search-popup-footer">
                    <span>Press <kbd>Enter</kbd> to search records</span>
                    <span>Press <kbd>ESC</kbd> to close</span>
                </div>
            </div>
        </div>

        <div class="content">
            <div class="page-title mb-4">
                <h2><i class="fa-solid fa-chart-column" style="color:#ff7a00"></i> Inspection Analytics</h2>
                <p class="text-light" style="color:#d1d5db !important;">Monitor inspection results, workload, and compliance trends.</p>
            </div>

            <!-- VIBRANT FULL COLOR SUMMARY CARDS -->
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-xl-3">
                    <div class="card summary-card card-total">
                        <div class="card-body d-flex justify-content-between align-items-center py-4">
                            <div>
                                <small class="text-white-50 text-uppercase fw-semibold" style="letter-spacing:0.5px;">Total Inspections</small>
                                <div class="fs-2 fw-bold mt-1 text-white"><?php echo $counts['total']; ?></div>
                            </div>
                            <div class="summary-icon"><i class="fa-solid fa-clipboard-list"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="card summary-card card-passed">
                        <div class="card-body d-flex justify-content-between align-items-center py-4">
                            <div>
                                <small class="text-white-50 text-uppercase fw-semibold" style="letter-spacing:0.5px;">Passed</small>
                                <div class="fs-2 fw-bold mt-1 text-white"><?php echo $counts['passed']; ?></div>
                            </div>
                            <div class="summary-icon"><i class="fa-solid fa-circle-check"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="card summary-card card-failed">
                        <div class="card-body d-flex justify-content-between align-items-center py-4">
                            <div>
                                <small class="text-white-50 text-uppercase fw-semibold" style="letter-spacing:0.5px;">Failed</small>
                                <div class="fs-2 fw-bold mt-1 text-white"><?php echo $counts['failed']; ?></div>
                            </div>
                            <div class="summary-icon"><i class="fa-solid fa-circle-xmark"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="card summary-card card-compliance">
                        <div class="card-body d-flex justify-content-between align-items-center py-4">
                            <div>
                                <small class="text-white-50 text-uppercase fw-semibold" style="letter-spacing:0.5px;">Avg. Compliance</small>
                                <div class="fs-2 fw-bold mt-1 text-white"><?php echo $avg; ?>%</div>
                            </div>
                            <div class="summary-icon"><i class="fa-solid fa-shield-halved"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- CHARTS -->
            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-header fw-bold text-white border-bottom border-secondary" style="border-color:var(--border-light) !important;">Monthly Inspection Trend</div>
                        <div class="card-body">
                            <canvas id="monthlyChart" height="120"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-header fw-bold text-white border-bottom border-secondary" style="border-color:var(--border-light) !important;">Inspection Results</div>
                        <div class="card-body">
                            <canvas id="statusChart" height="210"></canvas>
                        </div>
                    </div>
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
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

    // Charts setup
    new Chart(document.getElementById('monthlyChart'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_map(fn($m)=>date('M', mktime(0, 0, 0, $m, 1)), array_keys($monthly))); ?>,
            datasets: [{
                label: 'Inspections',
                data: <?php echo json_encode(array_values($monthly)); ?>,
                backgroundColor: '#ff7a00',
                borderColor: '#ff9f43',
                borderWidth: 1,
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, ticks: { precision: 0, color: '#9ca3af' }, grid: { color: 'rgba(255,122,0,0.1)' } },
                x: { ticks: { color: '#9ca3af' }, grid: { display: false } }
            }
        }
    });

    new Chart(document.getElementById('statusChart'), {
        type: 'doughnut',
        data: {
            labels: ['Scheduled', 'Ongoing', 'Completed', 'Passed', 'Failed', 'Cancelled'],
            datasets: [{
                data: [
                    <?php echo $counts['scheduled']; ?>,
                    <?php echo $counts['ongoing']; ?>,
                    <?php echo $counts['completed']; ?>,
                    <?php echo $counts['passed']; ?>,
                    <?php echo $counts['failed']; ?>,
                    <?php echo $counts['cancelled']; ?>
                ],
                backgroundColor: ['#3b82f6', '#f59e0b', '#8b5cf6', '#10b981', '#ef4444', '#6b7280'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'bottom', labels: { color: '#f3f4f6', boxWidth: 12, font: { size: 11 } } }
            }
        }
    });
</script>
</body>
</html>