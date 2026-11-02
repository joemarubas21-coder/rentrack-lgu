<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();
date_default_timezone_set('Asia/Manila');

require_once '../config/db.php';
require_once 'includes/auth.php';

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

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$adminUserId = (int) ($_SESSION['user_id'] ?? 1);

/* =====================================================
   AJAX HANDLERS
===================================================== */
if (isset($_GET['action']) && $_GET['action'] === 'mark_notif_read') {
    if (tableExists($conn, 'notifications')) {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = :user_id");
        $stmt->execute([':user_id' => $adminUserId]);
    }
    echo json_encode(['status' => 'success']);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'global_search') {
    $q = trim($_GET['q'] ?? '');
    $results = [];
    if (strlen($q) >= 1) {
        $searchTerm = '%' . $q . '%';
        if (tableExists($conn, 'rentals')) {
            $stmt = $conn->prepare("
                SELECT r.id, r.business_name, r.barangay, r.compliance_status, u.fullname AS owner_name 
                FROM rentals r 
                LEFT JOIN users u ON r.user_id = u.id 
                WHERE r.is_deleted = FALSE AND (r.business_name ILIKE :q OR r.barangay ILIKE :q OR u.fullname ILIKE :q) 
                LIMIT 5
            ");
            $stmt->execute([':q' => $searchTerm]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = [
                    'type' => 'Compliance Record',
                    'title' => 'Business: ' . ($row['business_name'] ?: 'N/A'),
                    'sub' => 'Owner: ' . ($row['owner_name'] ?: 'N/A') . ' | Status: ' . ucfirst($row['compliance_status'] ?? ''),
                    'link' => 'compliance.php?search=' . urlencode($row['business_name'] ?? '')
                ];
            }
        }
    }
    echo json_encode($results);
    exit;
}

/* =====================================================
   FETCH ALL COMPLIANCE DATA
===================================================== */
$search = trim($_GET['search'] ?? '');
$filterCompliance = trim($_GET['compliance'] ?? '');

$complianceRecords = [];
$stats = [
    'total' => 0, 'compliant' => 0, 'pending' => 0, 'non_compliant' => 0,
    'expired' => 0, 'expiring_30' => 0, 'expiring_60' => 0, 'expiring_90' => 0,
];

$barangayStats = [];
$monthlyTrend = [];
$nonCompliantList = [];
$expiringList = [];

if (tableExists($conn, 'rentals')) {
    $hasPermitExpiry = columnExists($conn, 'rentals', 'permit_expiry_date');
    $permitExpiryCol = $hasPermitExpiry ? 'r.permit_expiry_date' : (columnExists($conn, 'rentals', 'permit_expiry') ? 'r.permit_expiry' : 'NULL');

    $whereClause = ["r.is_deleted = FALSE"];
    $params = [];

    if (!empty($search)) {
        $whereClause[] = "(r.business_name ILIKE :search OR u.fullname ILIKE :search OR r.barangay ILIKE :search)";
        $params[':search'] = "%$search%";
    }
    if (!empty($filterCompliance)) {
        $whereClause[] = "r.compliance_status::text ILIKE :compliance";
        $params[':compliance'] = $filterCompliance;
    }

    $whereSql = implode(' AND ', $whereClause);

    $sql = "
        SELECT r.id, r.business_name, r.barangay, 
               $permitExpiryCol AS permit_expiry,
               r.compliance_status, r.status, r.created_at,
               u.fullname AS owner_name
        FROM rentals r
        LEFT JOIN users u ON r.user_id = u.id
        WHERE $whereSql
        ORDER BY r.created_at DESC
    ";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $complianceRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $complianceRecords = [];
    }

    $today = new DateTime();
    foreach ($complianceRecords as $row) {
        $stats['total']++;
        $compStatus = strtolower($row['compliance_status'] ?? 'pending');

        if ($compStatus === 'compliant') $stats['compliant']++;
        elseif ($compStatus === 'non-compliant' || $compStatus === 'non compliant') $stats['non_compliant']++;
        else $stats['pending']++;

        if (!empty($row['permit_expiry'])) {
            try {
                $expiry = new DateTime($row['permit_expiry']);
                $diff = $today->diff($expiry);
                $days = $diff->days * ($expiry < $today ? -1 : 1);

                if ($days < 0) $stats['expired']++;
                elseif ($days <= 30) $stats['expiring_30']++;
                elseif ($days <= 60) $stats['expiring_60']++;
                elseif ($days <= 90) $stats['expiring_90']++;
            } catch (Exception $e) {}
        }

        if ($compStatus === 'non-compliant' || $compStatus === 'non compliant') {
            $nonCompliantList[] = $row;
        }

        if (!empty($row['permit_expiry'])) {
            try {
                $expiry = new DateTime($row['permit_expiry']);
                $diff = $today->diff($expiry);
                $days = $diff->days * ($expiry < $today ? -1 : 1);
                if ($days >= 0 && $days <= 30) {
                    $row['days_until_expiry'] = $days;
                    $expiringList[] = $row;
                }
            } catch (Exception $e) {}
        }
    }

    // Compliance by Barangay
    $barangayGroups = [];
    foreach ($complianceRecords as $row) {
        $brgy = $row['barangay'] ?: 'Unassigned';
        if (!isset($barangayGroups[$brgy])) {
            $barangayGroups[$brgy] = ['total' => 0, 'compliant' => 0, 'non_compliant' => 0, 'pending' => 0];
        }
        $barangayGroups[$brgy]['total']++;
        $compStatus = strtolower($row['compliance_status'] ?? 'pending');
        if ($compStatus === 'compliant') $barangayGroups[$brgy]['compliant']++;
        elseif ($compStatus === 'non-compliant' || $compStatus === 'non compliant') $barangayGroups[$brgy]['non_compliant']++;
        else $barangayGroups[$brgy]['pending']++;
    }
    foreach ($barangayGroups as $brgy => $data) {
        $data['rate'] = $data['total'] > 0 ? round(($data['compliant'] / $data['total']) * 100) : 0;
        $barangayStats[] = array_merge(['name' => $brgy], $data);
    }
    usort($barangayStats, function($a, $b) { return $b['total'] - $a['total']; });
    $barangayStats = array_slice($barangayStats, 0, 8);

    // Monthly Compliance Trend
    if (columnExists($conn, 'rentals', 'created_at')) {
        try {
            $trendStmt = $conn->query("
                SELECT 
                    TO_CHAR(created_at, 'Mon YYYY') AS month_label,
                    TO_CHAR(created_at, 'YYYY-MM') AS month_key,
                    COUNT(*) AS total,
                    COUNT(CASE WHEN compliance_status::text ILIKE 'Compliant' THEN 1 END) AS compliant
                FROM rentals
                WHERE created_at >= NOW() - INTERVAL '6 months'
                  AND is_deleted = FALSE
                GROUP BY TO_CHAR(created_at, 'Mon YYYY'), TO_CHAR(created_at, 'YYYY-MM')
                ORDER BY month_key ASC
            ");
            while ($row = $trendStmt->fetch(PDO::FETCH_ASSOC)) {
                $row['rate'] = $row['total'] > 0 ? round(($row['compliant'] / $row['total']) * 100) : 0;
                $monthlyTrend[] = $row;
            }
        } catch (PDOException $e) {
            $monthlyTrend = [];
        }
    }
}

$complianceHealth = $stats['total'] > 0 ? round(($stats['compliant'] / $stats['total']) * 100) : 0;
$healthColor = 'red';
$healthLabel = 'CRITICAL';
if ($complianceHealth >= 80) { $healthColor = 'emerald'; $healthLabel = 'EXCELLENT'; }
elseif ($complianceHealth >= 60) { $healthColor = 'amber'; $healthLabel = 'NEEDS ATTENTION'; }
elseif ($complianceHealth >= 40) { $healthColor = 'orange'; $healthLabel = 'POOR'; }

/* =====================================================
   NOTIFICATIONS
===================================================== */
$notifications = [];
$unreadNotifCount = 0;
if (tableExists($conn, 'notifications')) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = FALSE");
    $stmt->execute([':user_id' => $adminUserId]);
    $unreadNotifCount = (int) $stmt->fetchColumn();

    $hasTargetUrl = columnExists($conn, 'notifications', 'target_url');
    $selectFields = $hasTargetUrl ? "id, title, message, target_url, created_at" : "id, title, message, created_at";
    $stmt = $conn->prepare("SELECT $selectFields FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([':user_id' => $adminUserId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!$hasTargetUrl) $row['target_url'] = 'javascript:void(0)';
        $notifications[] = $row;
    }
}

$userName = $_SESSION['fullname'] ?? 'Admin';
$userRole = $_SESSION['role'] ?? 'Administrator';
$userInitial = strtoupper(substr($userName, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#dc2626">
<title>Regulatory Compliance Oversight | RENTRACK LGU Admin</title>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    :root {
        --bg-body: #f8fafc;
        --bg-card: #ffffff;
        --bg-card-alt: #f1f5f9;
        --topofficial-bg: linear-gradient(90deg, #991b1b, #dc2626 55%, #991b1b);
        --navbar-bg: #ffffff;
        --red-accent: #dc2626;
        --red-hover: #ef4444;
        --red-soft: #fee2e2;
        --gold-accent: #d97706;
        --teal-accent: #0891b2;
        --emerald-accent: #059669;
        --violet-accent: #7c3aed;
        --text-main: #0f172a;
        --text-sub: #334155;
        --text-muted: #64748b;
        --border-color: #e2e8f0;
        --border-strong: #cbd5e1;
        --shadow-soft: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px -1px rgba(0, 0, 0, 0.1);
        --shadow-lift: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1);
        --safe-top: env(safe-area-inset-top, 0px);
        --safe-bottom: env(safe-area-inset-bottom, 0px);
        --safe-left: env(safe-area-inset-left, 0px);
        --safe-right: env(safe-area-inset-right, 0px);
        --topofficial-height: calc(38px + var(--safe-top));
        --navbar-height: 62px;
        --header-total-height: calc(var(--topofficial-height) + var(--navbar-height));
        --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }

    * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    html { -webkit-text-size-adjust: 100%; }

    body {
        background-color: var(--bg-body);
        font-family: 'Plus Jakarta Sans', sans-serif;
        color: var(--text-main);
        min-height: 100vh;
        min-height: 100dvh;
        overflow-x: hidden;
        padding-top: var(--header-total-height);
        -webkit-font-smoothing: antialiased;
        touch-action: manipulation;
    }

    body.drawer-open { overflow: hidden !important; touch-action: none; }

    ::-webkit-scrollbar { width: 6px; height: 6px; }
    ::-webkit-scrollbar-track { background: var(--bg-body); }
    ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 20px; }

    .swal2-backdrop-show {
        background: rgba(0, 0, 0, 0.4) !important;
        backdrop-filter: blur(4px) !important;
        -webkit-backdrop-filter: blur(4px) !important;
    }

    /* TOP OFFICIAL BAR */
    .top-official-bar {
        position: fixed; top: 0; left: 0; width: 100%;
        background: var(--topofficial-bg);
        border-bottom: 1px solid rgba(255, 255, 255, 0.15);
        padding: var(--safe-top) calc(15px + var(--safe-right)) 0 calc(15px + var(--safe-left));
        display: flex; justify-content: space-between; align-items: center;
        font-size: 11px; font-weight: 600; z-index: 1100; height: var(--topofficial-height);
        color: #fff; overflow: hidden;
    }
    .topbar-left { display: flex; align-items: center; gap: 8px; color: #fff; min-width: 0; overflow: hidden; }
    .topbar-left i { font-size: 12px; color: #fecdd3; flex-shrink: 0; }
    .topbar-left strong { font-weight: 800; white-space: nowrap; flex-shrink: 0; }
    .topbar-left .divider { width: 1px; height: 12px; background: rgba(255, 255, 255, 0.3); flex-shrink: 0; }
    .topbar-left .portal-title { color: #ffe4e6; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cert-badge {
        display: inline-flex; align-items: center; gap: 6px; padding: 3px 12px;
        background: rgba(255, 255, 255, 0.15); border: 1px solid rgba(255, 255, 255, 0.3);
        border-radius: 999px; color: #fff; font-weight: 700; font-size: 10px; flex-shrink: 0;
    }

    /* MAIN NAVBAR */
    .rt-topbar {
        position: fixed; top: var(--topofficial-height); left: 0; width: 100%;
        background: var(--navbar-bg);
        border-bottom: 1px solid var(--border-color);
        display: flex; align-items: center; justify-content: space-between;
        height: var(--navbar-height); z-index: 1050; gap: 12px;
    }

    .rt-topbar-left { display: flex; align-items: center; height: 100%; flex-shrink: 0; min-width: 0; }
    .rt-topbar-brand {
        display: flex; align-items: center; gap: 10px;
        height: 100%; padding: 0 18px 0 calc(18px + var(--safe-left));
        border-right: 1px solid var(--border-color); flex-shrink: 0;
    }
    .rt-topbar-logo {
        width: 36px; height: 36px; border-radius: 10px;
        background: linear-gradient(135deg, var(--red-accent), #991b1b);
        display: flex; align-items: center; justify-content: center;
        font-family: 'Space Grotesk', sans-serif; font-weight: 800; font-size: 13px; color: #fff;
        box-shadow: 0 0 12px rgba(220, 38, 38, 0.2); flex-shrink: 0;
    }
    .rt-topbar-brand-text strong { font-size: 13px; font-weight: 800; color: var(--text-main); display: block; line-height: 1.15; }
    .rt-topbar-brand-text span { font-size: 9px; font-weight: 700; color: var(--red-accent); text-transform: uppercase; letter-spacing: 0.06em; }

    .menu-toggle {
        display: none; width: 42px; height: 42px; border-radius: 10px;
        align-items: center; justify-content: center;
        background: var(--bg-card-alt); border: 1px solid var(--border-color);
        color: var(--red-accent); cursor: pointer; font-size: 16px;
        flex-shrink: 0; margin-left: 12px;
    }
    .menu-toggle:active { background: var(--red-accent); color: #fff; border-color: var(--red-accent); }

    /* HORIZONTAL NAV MENU */
    .main-nav-bar {
        flex: 1; display: flex; align-items: center; height: 100%;
        min-width: 0; overflow: visible;
    }
    .nav-menu {
        list-style: none; margin: 0; padding: 0;
        display: flex; align-items: center; gap: 2px;
        flex-wrap: nowrap; height: 100%;
    }
    .nav-item { position: relative; height: 100%; display: flex; align-items: center; }
    .nav-link {
        display: flex; align-items: center; gap: 7px;
        padding: 8px 12px; color: var(--text-sub); text-decoration: none;
        font-size: 12.5px; font-weight: 600; border-radius: 8px;
        transition: all 0.15s ease; cursor: pointer; white-space: nowrap;
        background: transparent; border: none; font-family: inherit; height: 40px;
    }
    .nav-link i.nav-ico { color: var(--red-accent); font-size: 13px; width: 16px; text-align: center; }
    .nav-link .chevron { font-size: 9px; margin-left: 2px; transition: transform 0.2s ease; opacity: 0.7; }
    .nav-link:hover { color: var(--red-accent); background: var(--red-soft); }
    .nav-link.active { color: var(--red-accent); background: var(--red-soft); font-weight: 700; }
    .nav-item.open > .nav-link { color: var(--red-accent); background: var(--red-soft); }
    .nav-item.open > .nav-link .chevron { transform: rotate(180deg); }

    .nav-dropdown {
        position: absolute; top: calc(100% + 4px); left: 0; min-width: 240px;
        background: #ffffff; border: 1px solid var(--border-color);
        border-radius: 12px; box-shadow: 0 12px 30px rgba(0,0,0,0.12);
        padding: 8px; opacity: 0; visibility: hidden; transform: translateY(8px);
        transition: opacity 0.18s ease, transform 0.18s ease, visibility 0.18s;
        z-index: 200;
    }
    .nav-item.open .nav-dropdown { opacity: 1; visibility: visible; transform: translateY(0); }
    .nav-item.align-right .nav-dropdown { left: auto; right: 0; }
    .nav-dropdown a {
        display: flex; align-items: center; gap: 12px; padding: 10px 12px;
        border-radius: 8px; color: var(--text-sub); text-decoration: none;
        font-size: 13px; font-weight: 500; transition: all 0.15s ease;
    }
    .nav-dropdown a:hover { background: var(--red-soft); color: var(--red-accent); }
    .nav-dropdown a i { width: 16px; color: var(--red-accent); font-size: 14px; text-align: center; flex-shrink: 0; }

    .mobile-drawer-header { display: none; }

    /* SEARCH */
    .rt-topbar-center {
        flex: 1; display: flex; justify-content: center;
        padding: 0 12px; max-width: 420px; margin: 0 auto;
        position: relative; min-width: 120px;
    }
    .rt-search-container { position: relative; width: 100%; }
    .rt-search-container i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 13px; }
    .rt-search-container input {
        width: 100%; padding: 8px 16px 8px 38px; border-radius: 999px;
        border: 1px solid var(--border-color); background: var(--bg-card-alt);
        color: var(--text-main); font-size: 13px; outline: none; cursor: pointer;
    }
    .rt-search-container input::placeholder { color: var(--text-muted); }
    .rt-search-container input:focus { background: #fff; border-color: var(--red-accent); box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1); }

    /* RIGHT ICONS */
    .rt-topbar-right { display: flex; align-items: center; gap: 10px; padding-right: calc(18px + var(--safe-right)); flex-shrink: 0; }
    .icon-btn {
        width: 38px; height: 38px; border-radius: 10px;
        border: 1px solid var(--border-color); background: var(--bg-card-alt);
        color: var(--text-main); display: flex; align-items: center; justify-content: center;
        cursor: pointer; position: relative; transition: var(--transition);
    }
    .icon-btn:hover { background: #fff; border-color: var(--red-accent); color: var(--red-accent); }
    .mobile-search-btn { display: none; }
    .notif-badge-dot {
        position: absolute; top: 6px; right: 6px; width: 9px; height: 9px;
        border-radius: 50%; background: var(--red-accent);
        box-shadow: 0 0 8px var(--red-accent);
    }
    .rt-profile-wrapper { position: relative; }
    .rt-profile-btn {
        display: flex; align-items: center; gap: 10px; padding: 4px 14px 4px 4px;
        border-radius: 999px; border: 1px solid var(--border-color);
        background: var(--bg-card-alt); cursor: pointer;
    }
    .rt-profile-btn .avatar {
        width: 32px; height: 32px; border-radius: 50%; background: var(--red-accent);
        color: #fff; display: flex; align-items: center; justify-content: center;
        font-weight: 800; font-size: 13px;
    }
    .rt-profile-btn .info .name { font-size: 12px; font-weight: 700; color: var(--text-main); }
    .rt-profile-btn .info .role { font-size: 9px; font-weight: 700; color: var(--red-accent); text-transform: uppercase; }
    .rt-profile-dropdown {
        position: absolute; top: calc(100% + 10px); right: 0; width: 200px;
        background: #fff; border: 1px solid var(--border-color);
        border-radius: 14px; padding: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        opacity: 0; visibility: hidden; transform: translateY(-8px);
        transition: var(--transition); z-index: 100;
    }
    .rt-profile-dropdown.show { opacity: 1; visibility: visible; transform: translateY(0); }
    .rt-profile-dropdown .dropdown-item {
        display: flex; align-items: center; gap: 10px; padding: 9px 12px;
        border-radius: 8px; color: var(--text-sub); text-decoration: none; font-size: 13px;
    }
    .rt-profile-dropdown .dropdown-item:hover { background: var(--bg-card-alt); color: var(--text-main); }
    .rt-profile-dropdown .dropdown-item.danger { color: #dc2626; }
    .rt-profile-dropdown .dropdown-item.danger:hover { background: #fee2e2; }

    /* LAYOUT */
    .wrapper { display: flex; min-height: calc(100vh - var(--header-total-height)); min-height: calc(100dvh - var(--header-total-height)); }
    .main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
    .sidebar-overlay {
        display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5);
        z-index: 1040; -webkit-backdrop-filter: blur(2px); backdrop-filter: blur(2px);
        opacity: 0; transition: opacity 0.3s ease;
    }
    .sidebar-overlay.active { display: block; opacity: 1; }

    .dashboard-shell { padding: 16px 20px 30px; flex: 1; display: flex; flex-direction: column; }

    /* PAGE HEADER */
    .page-header-card {
        background: #fff1f2;
        border: 1px solid #fecdd3;
        border-radius: 18px; padding: 20px 24px; margin-bottom: 20px;
        display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;
        box-shadow: var(--shadow-soft);
    }
    .page-header-card h2 {
        font-family: 'Space Grotesk', sans-serif; font-size: 22px; font-weight: 800; color: #9f1239; margin: 0;
        display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
    }
    .page-header-card h2 i { color: #e11d48; background: rgba(225, 29, 72, 0.1); padding: 8px; border-radius: 10px; font-size: 16px; }
    .page-header-card p { font-size: 12px; color: #881337; margin: 4px 0 0; font-weight: 500; }
    .header-clock-badge {
        display: flex; align-items: center; gap: 8px; padding: 8px 14px;
        background: #fff; border: 1px solid #fecdd3;
        border-radius: 10px; font-family: 'IBM Plex Mono', monospace;
        font-size: 12px; font-weight: 600; color: #9f1239;
    }
    .header-clock-badge i { color: #e11d48; }
    .header-actions-group { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .btn-rt-primary {
        background: var(--red-accent); color: #fff; border: none; padding: 9px 18px;
        border-radius: 10px; font-size: 12.5px; font-weight: 800; text-decoration: none;
        transition: var(--transition); display: inline-flex; align-items: center; gap: 8px; cursor: pointer;
        box-shadow: 0 4px 12px rgba(220, 38, 38, 0.2);
    }
    .btn-rt-primary:hover { background: #b91c1c; color: #fff; }
    .btn-rt-secondary {
        background: #fff; color: var(--text-main); border: 1px solid var(--border-color);
        padding: 9px 18px; border-radius: 10px; font-size: 12.5px; font-weight: 700; text-decoration: none;
        display: inline-flex; align-items: center; gap: 8px;
    }
    .btn-rt-secondary:hover { background: var(--bg-card-alt); border-color: var(--border-strong); color: var(--text-main); }

    /* HEALTH SCORECARD */
    .health-scorecard {
        background: #ffffff;
        border: 1px solid var(--border-color);
        border-radius: 18px; padding: 24px 28px;
        margin-bottom: 20px;
        display: grid; grid-template-columns: 200px 1fr;
        gap: 28px; align-items: center;
        position: relative; overflow: hidden;
        box-shadow: var(--shadow-soft);
    }
    .health-scorecard::before {
        content: ''; position: absolute; top: -50%; right: -10%;
        width: 300px; height: 300px; border-radius: 50%;
        background: radial-gradient(circle, rgba(220, 38, 38, 0.06), transparent 70%);
        pointer-events: none;
    }
    .health-score-value { text-align: center; position: relative; z-index: 1; }
    .health-score-number { font-family: 'Space Grotesk', sans-serif; font-size: 64px; font-weight: 800; line-height: 1; color: var(--text-main); }
    .health-score-label {
        font-size: 11px; font-weight: 800; text-transform: uppercase;
        letter-spacing: 1px; margin-top: 8px; padding: 4px 12px;
        border-radius: 999px; display: inline-block;
    }
    .health-score-label.emerald { background: rgba(5,150,105,0.1); color: #059669; border: 1px solid rgba(5,150,105,0.2); }
    .health-score-label.amber { background: rgba(217,119,6,0.1); color: #d97706; border: 1px solid rgba(217,119,6,0.2); }
    .health-score-label.orange { background: rgba(234,88,12,0.1); color: #ea580c; border: 1px solid rgba(234,88,12,0.2); }
    .health-score-label.red { background: rgba(220,38,38,0.1); color: #dc2626; border: 1px solid rgba(220,38,38,0.2); }
    .health-breakdown { position: relative; z-index: 1; }
    .health-bar-wrap {
        height: 12px; border-radius: 999px; overflow: hidden;
        background: var(--bg-card-alt); display: flex; margin-bottom: 14px;
    }
    .health-bar-segment { height: 100%; transition: width 0.8s ease; }
    .health-bar-segment.compliant { background: linear-gradient(90deg, #059669, #34d399); }
    .health-bar-segment.pending { background: linear-gradient(90deg, #d97706, #fbbf24); }
    .health-bar-segment.non-compliant { background: linear-gradient(90deg, #dc2626, #f87171); }
    .health-legend { display: flex; gap: 20px; flex-wrap: wrap; font-size: 12px; }
    .health-legend-item { display: flex; align-items: center; gap: 8px; color: var(--text-sub); }
    .health-legend-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
    .health-legend-dot.emerald { background: #34d399; }
    .health-legend-dot.amber { background: #fbbf24; }
    .health-legend-dot.red { background: #f87171; }

    /* ALERT CARDS */
    .alert-cards-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 20px; }
    .alert-card {
        border-radius: 14px; padding: 18px 20px;
        display: flex; align-items: center; gap: 16px;
        border: 1px solid; position: relative; overflow: hidden;
        transition: transform 0.2s, box-shadow 0.2s;
        text-decoration: none; cursor: pointer;
    }
    .alert-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-lift); }
    .alert-card.danger { background: #fef2f2; border-color: #fecaca; }
    .alert-card.warning { background: #fffbeb; border-color: #fde68a; }
    .alert-card.info { background: #eff6ff; border-color: #bfdbfe; }
    .alert-icon {
        width: 48px; height: 48px; border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        font-size: 22px; color: #fff; flex-shrink: 0;
    }
    .alert-card.danger .alert-icon { background: linear-gradient(135deg, #dc2626, #991b1b); }
    .alert-card.warning .alert-icon { background: linear-gradient(135deg, #ea580c, #c2410c); }
    .alert-card.info .alert-icon { background: linear-gradient(135deg, #2563eb, #1e40af); }
    .alert-content { min-width: 0; flex: 1; }
    .alert-value { font-family: 'Space Grotesk', sans-serif; font-size: 28px; font-weight: 800; color: var(--text-main); line-height: 1; }
    .alert-label { font-size: 11px; font-weight: 700; color: var(--text-sub); text-transform: uppercase; letter-spacing: 0.5px; margin-top: 4px; }
    .alert-sub { font-size: 10.5px; color: var(--text-muted); margin-top: 2px; }

    /* STATS */
    .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 20px; }
    .stat-card-rt {
        border-radius: 14px; padding: 18px 20px;
        display: flex; flex-direction: column; justify-content: space-between; min-height: 110px;
        border: 1px solid rgba(255, 255, 255, 0.15);
        transition: transform 0.2s; box-shadow: var(--shadow-soft);
    }
    .stat-card-rt:hover { transform: translateY(-2px); }
    .stat-card-rt.card-red { background: linear-gradient(135deg, #be123c, #e11d48); }
    .stat-card-rt.card-emerald { background: linear-gradient(135deg, #047857, #059669); }
    .stat-card-rt.card-amber { background: linear-gradient(135deg, #b45309, #d97706); }
    .stat-card-rt.card-violet { background: linear-gradient(135deg, #6d28d9, #7c3aed); }
    .stat-card-rt h3 { font-family: 'Space Grotesk', sans-serif; font-size: 26px; font-weight: 800; color: #fff; margin: 0; line-height: 1.1; }
    .stat-card-rt p { font-size: 10.5px; font-weight: 700; color: rgba(255, 255, 255, 0.9); text-transform: uppercase; letter-spacing: 0.5px; margin: 4px 0 0; }
    .stat-card-rt .stat-sub { font-size: 10px; color: rgba(255, 255, 255, 0.75); }

    /* GRIDS */
    .grid-2col { display: grid; grid-template-columns: 1.4fr 1fr; gap: 16px; margin-bottom: 20px; }
    .grid-3col { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 20px; }

    .panel-card {
        background: var(--bg-card); border: 1px solid var(--border-color);
        border-radius: 16px; overflow: hidden; box-shadow: var(--shadow-soft);
    }
    .panel-head {
        padding: 16px 20px; border-bottom: 1px solid var(--border-color);
        display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
    }
    .panel-head h3 {
        font-family: 'Space Grotesk', sans-serif; font-size: 14.5px; font-weight: 800;
        color: var(--text-main); margin: 0; display: flex; align-items: center; gap: 8px;
    }
    .panel-head h3 i { color: var(--red-accent); }
    .panel-head .badge {
        font-size: 10px; font-weight: 700; padding: 4px 10px;
        background: var(--bg-card-alt); color: var(--text-muted);
        border: 1px solid var(--border-color); border-radius: 999px;
    }
    .panel-body { padding: 18px 20px; }

    /* TABLES */
    .watchlist-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
    .watchlist-table thead th {
        text-align: left; padding: 10px 14px;
        color: var(--text-muted); font-size: 10px;
        text-transform: uppercase; font-weight: 700;
        border-bottom: 1px solid var(--border-color);
        background: #f8fafc;
    }
    .watchlist-table tbody td {
        padding: 12px 14px; border-bottom: 1px solid var(--border-color);
        color: var(--text-sub); vertical-align: middle;
    }
    .watchlist-table tbody tr:hover { background: #f8fafc; }

    .badge-rt {
        display: inline-flex; align-items: center; gap: 4px;
        padding: 3px 10px; border-radius: 999px;
        font-size: 10px; font-weight: 700; text-transform: uppercase;
    }
    .badge-rt.success { background: rgba(5, 150, 105, 0.1); color: #059669; border: 1px solid rgba(5, 150, 105, 0.2); }
    .badge-rt.warning { background: rgba(217, 119, 6, 0.1); color: #d97706; border: 1px solid rgba(217, 119, 6, 0.2); }
    .badge-rt.danger { background: rgba(220, 38, 38, 0.1); color: #dc2626; border: 1px solid rgba(220, 38, 38, 0.2); }

    .btn-action-sm {
        padding: 5px 12px; border-radius: 8px; font-size: 11px; font-weight: 700;
        text-decoration: none; display: inline-flex; align-items: center; gap: 5px;
        border: 1px solid; cursor: pointer; transition: all 0.2s;
    }
    .btn-action-sm.danger { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
    .btn-action-sm.danger:hover { background: #dc2626; color: #fff; border-color: #dc2626; }
    .btn-action-sm.warning { background: #fffbeb; color: #d97706; border-color: #fde68a; }
    .btn-action-sm.warning:hover { background: #ea580c; color: #fff; border-color: #ea580c; }

    /* BRGY */
    .brgy-stat-item {
        display: flex; align-items: center; gap: 12px;
        padding: 12px 14px; border-radius: 10px;
        background: #f8fafc; border: 1px solid var(--border-color); margin-bottom: 8px;
    }
    .brgy-stat-item:last-child { margin-bottom: 0; }
    .brgy-name { font-weight: 700; color: var(--text-main); font-size: 13px; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .brgy-count { font-family: 'Space Grotesk', sans-serif; font-size: 15px; font-weight: 800; color: var(--text-main); min-width: 30px; text-align: right; }
    .brgy-rate { font-family: 'Space Grotesk', sans-serif; font-size: 13px; font-weight: 800; min-width: 50px; text-align: right; }

    .chart-container { position: relative; height: 260px; }

    .activity-timeline { display: flex; flex-direction: column; gap: 10px; }
    .activity-item {
        display: flex; gap: 12px; padding: 12px 14px;
        background: #f8fafc; border: 1px solid var(--border-color); border-radius: 10px;
    }
    .activity-icon {
        width: 32px; height: 32px; border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
        font-size: 13px; flex-shrink: 0;
    }
    .activity-icon.danger { background: rgba(220, 38, 38, 0.15); color: #dc2626; }
    .activity-icon.warning { background: rgba(217, 119, 6, 0.15); color: #d97706; }
    .activity-icon.success { background: rgba(5, 150, 105, 0.15); color: #059669; }
    .activity-body { flex: 1; min-width: 0; }
    .activity-title { font-size: 12.5px; font-weight: 700; color: var(--text-main); line-height: 1.3; word-break: break-word; }
    .activity-time { font-size: 10.5px; color: var(--text-muted); margin-top: 3px; }

    .footer {
        margin-top: auto; padding-top: 14px; padding-bottom: var(--safe-bottom);
        border-top: 1px solid var(--border-color);
        display: flex; justify-content: space-between;
        font-size: 11.5px; color: var(--text-muted); flex-wrap: wrap; gap: 8px;
    }

    /* RESPONSIVE — 1400px */
    @media (max-width: 1400px) {
        .nav-link { padding: 8px 10px; font-size: 12px; }
        .nav-link .nav-ico { font-size: 12px; }
        .rt-topbar-center { max-width: 320px; }
    }

    /* MOBILE — 1200px drawer mode */
    @media (max-width: 1200px) {
        .rt-topbar-left { flex-shrink: 1; min-width: 0; }
        .menu-toggle { display: flex; }

        .main-nav-bar {
            position: fixed; top: 0; left: -100%;
            width: 85vw; max-width: 320px;
            height: 100vh; height: 100dvh;
            flex-direction: column; align-items: stretch; justify-content: flex-start;
            padding: 0; z-index: 1090; overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            background: #ffffff; box-shadow: 6px 0 25px rgba(0,0,0,0.18);
            flex: none;
        }
        .main-nav-bar.mobile-open { left: 0 !important; }

        .mobile-drawer-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 16px 18px; padding-top: calc(16px + var(--safe-top));
            background: linear-gradient(90deg, #991b1b, #dc2626 55%, #991b1b);
            color: #fff; flex-shrink: 0; border-bottom: 2px solid #7f1d1d;
        }
        .mobile-drawer-header .drawer-brand { display: flex; align-items: center; gap: 10px; }
        .mobile-drawer-header .drawer-brand-logo {
            width: 32px; height: 32px; border-radius: 50%;
            background: #ffffff; color: var(--red-accent);
            display: flex; align-items: center; justify-content: center;
            font-weight: 800; font-size: 12px;
        }
        .mobile-drawer-header .drawer-brand-text h2 { font-size: 13px; font-weight: 800; margin: 0; color: #fff; }
        .mobile-drawer-header .drawer-brand-text span { font-size: 9px; opacity: 0.85; text-transform: uppercase; letter-spacing: 1px; font-weight: 600; }
        .mobile-drawer-header .drawer-close-btn {
            width: 34px; height: 34px; border-radius: 8px;
            background: rgba(255,255,255,0.18); border: 1px solid rgba(255,255,255,0.35);
            color: #fff; cursor: pointer; font-size: 15px;
            display: flex; align-items: center; justify-content: center;
        }
        .mobile-drawer-header .drawer-close-btn:active { background: rgba(255,255,255,0.35); }

        .nav-menu {
            flex-direction: column; width: 100%; justify-content: flex-start;
            gap: 4px; padding: 14px; padding-bottom: calc(20px + var(--safe-bottom));
            height: auto;
        }
        .nav-item { width: 100%; height: auto; display: block; }
        .nav-link {
            padding: 14px 16px; border-radius: 10px;
            justify-content: flex-start; font-size: 14.5px;
            min-height: 48px; width: 100%; height: auto;
        }
        .nav-link .chevron { margin-left: auto; font-size: 11px; }
        .nav-link .nav-ico { font-size: 15px; width: 18px; }

        .nav-dropdown,
        .nav-item.open .nav-dropdown,
        .nav-item.align-right .nav-dropdown {
            position: static; left: auto; right: auto;
            transform: none; opacity: 1; visibility: visible;
            box-shadow: none; border: none;
            max-height: 0; overflow: hidden;
            transition: max-height 0.3s ease, padding 0.2s ease;
            padding: 0 0 0 16px; background: #f8fafc;
            margin-top: 4px; border-radius: 10px; min-width: 0;
        }
        .nav-item.open .nav-dropdown { max-height: 600px; padding: 8px 0 8px 16px; }
        .nav-dropdown a { padding: 12px 14px; min-height: 44px; font-size: 13.5px; }

        .rt-topbar-center {
            order: 5; flex-basis: 100%; max-width: 100%;
            margin-top: 4px; margin-bottom: 4px; padding: 0;
        }
    }

    @media (max-width: 992px) {
        .rt-topbar { flex-wrap: wrap; height: auto; padding-bottom: 6px; }
        .rt-topbar-brand { border-right: none; }
        .rt-topbar-center {
            order: 5; flex-basis: 100%;
            margin-top: 4px; margin-bottom: 4px;
        }
        body { padding-top: calc(var(--topofficial-height) + var(--navbar-height) + 50px); }
        .health-scorecard { grid-template-columns: 1fr; gap: 18px; padding: 20px; }
        .health-score-number { font-size: 52px; }
        .alert-cards-row { grid-template-columns: 1fr; }
        .stats-grid { grid-template-columns: repeat(2, 1fr); }
        .grid-2col, .grid-3col { grid-template-columns: 1fr; }
    }

    @media (max-width: 768px) {
        .rt-topbar-right { gap: 8px; padding-right: calc(12px + var(--safe-right)); }
        .rt-topbar-brand-text { display: none; }
        .dashboard-shell { padding: 12px; }
        .page-header-card { padding: 15px; flex-direction: column; align-items: flex-start; gap: 13px; }
        .page-header-card h2 { font-size: 17px; }
        .header-actions-group { width: 100%; display: grid; grid-template-columns: 1fr 1fr; gap: 7px; }
        .header-actions-group > * { width: 100%; justify-content: center; }
        .header-clock-badge { font-size: 11px; padding: 7px 10px; grid-column: 1 / -1; }

        .health-scorecard { padding: 18px 16px; border-radius: 15px; gap: 14px; }
        .health-score-number { font-size: 42px; }

        .alert-card { padding: 14px; gap: 12px; }
        .alert-icon { width: 40px; height: 40px; font-size: 18px; border-radius: 10px; }
        .alert-value { font-size: 22px; }

        .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
        .stat-card-rt { padding: 14px; border-radius: 12px; min-height: 95px; }
        .stat-card-rt h3 { font-size: 22px; }

        .panel-head { padding: 12px 14px; }
        .panel-head h3 { font-size: 13px; }
        .panel-body { padding: 12px 14px; }
        .chart-container { height: 220px; }

        /* Tables → cards */
        .panel-card > div[style*="overflow-x:auto"] { overflow: visible !important; }

        .watchlist-table,
        .watchlist-table tbody { display: block; width: 100%; }
        .watchlist-table thead { display: none; }
        .watchlist-table tbody { padding: 8px; }

        .watchlist-table tbody tr {
            display: block; width: 100%; margin: 0 0 10px; padding: 5px 0;
            background: #f8fafc; border: 1px solid var(--border-color);
            border-radius: 12px; overflow: hidden; box-shadow: var(--shadow-soft);
        }
        .watchlist-table tbody tr:last-child { margin-bottom: 0; }

        .watchlist-table tbody td {
            display: grid; grid-template-columns: minmax(90px, 34%) minmax(0, 66%);
            align-items: center; gap: 8px; width: 100%; min-width: 0;
            padding: 9px 11px; border-bottom: 1px solid var(--border-color);
            font-size: 11.5px; line-height: 1.4; overflow-wrap: anywhere; word-break: break-word;
        }
        .watchlist-table tbody td:last-child { border-bottom: 0; }
        .watchlist-table tbody td::before {
            content: attr(data-label); color: var(--text-muted);
            font-size: 9px; font-weight: 800;
            text-transform: uppercase; letter-spacing: .45px; min-width: 0;
        }
        .watchlist-table tbody td > * { min-width: 0; max-width: 100%; }
        .watchlist-table .badge-rt { justify-self: start; width: fit-content; font-size: 8.5px; padding: 4px 7px; }

        .watchlist-table tbody td:last-child {
            display: block; padding: 10px 11px 11px;
        }
        .watchlist-table tbody td:last-child::before { display: block; margin-bottom: 7px; }
        .watchlist-table tbody td:last-child .btn-action-sm {
            width: 100%; justify-content: center; padding: 8px 12px; font-size: 11px;
        }

        .activity-item { padding: 10px 12px; gap: 10px; }

        .footer { flex-direction: column; align-items: flex-start; font-size: 10px; }

        #notifDropdown {
            position: fixed !important; top: calc(var(--header-total-height) + 5px) !important;
            right: max(8px, var(--safe-right)) !important; left: max(8px, var(--safe-left)) !important;
            width: auto !important; max-width: none !important; max-height: min(70dvh, 430px); overflow-y: auto;
        }

        .swal2-popup { width: calc(100vw - 20px) !important; max-width: 600px !important; margin: 10px !important; border-radius: 16px !important; }
        #swalSearchInput { min-height: 44px !important; font-size: 16px !important; }
        #swalSearchResults { max-height: 45dvh !important; }
    }

    @media (max-width: 576px) {
        .rt-topbar-center { display: none; }
        .mobile-search-btn { display: flex; }
        .rt-topbar-brand { padding: 0 12px; }
        .rt-topbar-logo { width: 32px; height: 32px; font-size: 12px; }
        .menu-toggle { width: 38px; height: 38px; font-size: 14px; margin-left: 8px; }
        .rt-profile-btn { padding: 4px; }
        .rt-profile-btn .info { display: none !important; }
        .rt-profile-btn i { display: none; }
        .dashboard-shell { padding: 10px 10px 20px; }
        .page-header-card h2 { font-size: 16px; }
    }

    @media (max-width: 480px) {
        .stats-grid { grid-template-columns: 1fr; }
        .header-actions-group { grid-template-columns: 1fr; }
        .health-legend { flex-direction: column; gap: 8px; }
        .health-score-number { font-size: 38px; }
        .alert-value { font-size: 20px; }
    }

    @media (max-width: 430px) {
        .top-official-bar { font-size: 9px; }
        .topbar-left { max-width: 58%; }
        .topbar-left strong { font-size: 9px; }
        .cert-badge { font-size: 7.5px; padding: 3px 7px; }
        .rt-topbar-right { gap: 6px; }
        .mobile-search-btn, .icon-btn { width: 32px; height: 32px; }
    }

    @media (max-width: 380px) {
        .rt-topbar-left .page-title { display: none; }
        .topbar-left strong { max-width: 110px; }
        .health-score-number { font-size: 34px; }
        .stat-card-rt h3 { font-size: 20px; }
        .watchlist-table tbody td { grid-template-columns: minmax(76px, 36%) minmax(0, 64%); font-size: 10.5px; }
    }

    @media (hover: none) and (pointer: coarse) {
        .alert-card:hover, .stat-card-rt:hover,
        .nav-link:hover, .icon-btn:hover, .rt-profile-btn:hover,
        .btn-rt-secondary:hover, .btn-rt-primary:hover { transform: none; }
    }

    @supports (-webkit-touch-callout: none) {
        input, button, select, textarea { font-size: 16px !important; }
        .rt-search-container input { font-size: 14px !important; }
    }

    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after {
            animation-duration: .01ms !important;
            transition-duration: .01ms !important;
        }
    }
</style>
</head>
<body>

<div class="top-official-bar">
    <div class="topbar-left">
        <i class="fa-solid fa-landmark"></i>
        <strong>REPUBLIC OF THE PHILIPPINES</strong>
        <span class="divider"></span>
        <span class="portal-title">Local Government Unit Regulatory Compliance Portal</span>
    </div>
    <div>
        <span class="cert-badge"><i class="fa-solid fa-certificate"></i> LGU CERTIFIED PORTAL V2.1</span>
    </div>
</div>

<header class="rt-topbar">
    <div class="rt-topbar-left">
        <button class="menu-toggle" id="menuToggle" aria-label="Toggle Menu"><i class="fa-solid fa-bars"></i></button>

        <div class="rt-topbar-brand">
            <div class="rt-topbar-logo">RT</div>
            <div class="rt-topbar-brand-text">
                <strong>RENTRACK</strong>
                <span>LGU ADMIN</span>
            </div>
        </div>

        <!-- HORIZONTAL NAV -->
        <nav class="main-nav-bar" id="mainNavBar">
            <div class="mobile-drawer-header">
                <div class="drawer-brand">
                    <div class="drawer-brand-logo">RT</div>
                    <div class="drawer-brand-text">
                        <h2>RENTRACK</h2>
                        <span>LGU Admin</span>
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
                        <i class="fa-solid fa-building nav-ico"></i> Rentals
                        <i class="fa-solid fa-chevron-down chevron"></i>
                    </button>
                    <div class="nav-dropdown">
                        <a href="rentals.php"><i class="fa-solid fa-building"></i> All Rentals</a>
                        <a href="compliance.php"><i class="fa-solid fa-file-shield"></i> Compliance</a>
                    </div>
                </li>

                <li class="nav-item">
                    <a href="users.php" class="nav-link">
                        <i class="fa-solid fa-users nav-ico"></i> Users
                    </a>
                </li>

                <li class="nav-item has-dropdown">
                    <button type="button" class="nav-link" data-dropdown>
                        <i class="fa-solid fa-clipboard-check nav-ico"></i> Operations
                        <i class="fa-solid fa-chevron-down chevron"></i>
                    </button>
                    <div class="nav-dropdown">
                        <a href="payments.php"><i class="fa-solid fa-cash-register"></i> Payments</a>
                        <a href="inspections.php"><i class="fa-solid fa-clipboard-check"></i> Inspections</a>
                    </div>
                </li>

                <li class="nav-item has-dropdown">
                    <button type="button" class="nav-link" data-dropdown>
                        <i class="fa-solid fa-user-tie nav-ico"></i> Staff
                        <i class="fa-solid fa-chevron-down chevron"></i>
                    </button>
                    <div class="nav-dropdown">
                        <a href="staff_accounts.php"><i class="fa-solid fa-user-tie"></i> Staff Accounts</a>
                        <a href="inspector_accounts.php"><i class="fa-solid fa-user-shield"></i> Inspector Accounts</a>
                    </div>
                </li>

                <li class="nav-item has-dropdown align-right">
                    <button type="button" class="nav-link" data-dropdown>
                        <i class="fa-solid fa-chart-simple nav-ico"></i> Reports
                        <i class="fa-solid fa-chevron-down chevron"></i>
                    </button>
                    <div class="nav-dropdown">
                        <a href="reports.php"><i class="fa-solid fa-chart-simple"></i> Reports</a>
                        <a href="map_overview.php"><i class="fa-solid fa-map-location-dot"></i> GIS Mapping</a>
                        <a href="activity_logs.php"><i class="fa-solid fa-clock-rotate-left"></i> Audit Logs</a>
                        <a href="settings.php"><i class="fa-solid fa-gear"></i> Settings</a>
                    </div>
                </li>
            </ul>
        </nav>
    </div>

    <div class="rt-topbar-center">
        <div class="rt-search-container">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" id="openSearchModalBtn" placeholder="Search business, owner, or barangay..." readonly>
        </div>
    </div>

    <div class="rt-topbar-right">
        <button class="icon-btn mobile-search-btn" id="mobileSearchBtn" title="Search">
            <i class="fa-solid fa-magnifying-glass"></i>
        </button>

        <div style="position:relative;">
            <button class="icon-btn" id="notifBtn" title="Notifications">
                <i class="fa-regular fa-bell"></i>
                <?php if ($unreadNotifCount > 0): ?>
                    <span class="notif-badge-dot" id="notifBadgeDot"></span>
                <?php endif; ?>
            </button>
            <div id="notifDropdown" style="display:none; position:absolute; top:46px; right:0; width:320px; background:#fff; border:1px solid var(--border-color); border-radius:14px; padding:12px; box-shadow:0 10px 30px rgba(0,0,0,0.1); z-index:999;">
                <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); padding-bottom:8px; margin-bottom:8px;">
                    <span style="font-weight:700; color:var(--text-main); font-size:12px;">System Notifications</span>
                    <span style="font-size:10px; color:var(--red-accent); cursor:pointer;" id="clearNotifBadge">Mark all read</span>
                </div>
                <div>
                    <?php if (!empty($notifications)): ?>
                        <?php foreach($notifications as $n): ?>
                            <a href="<?php echo h($n['target_url'] ?: 'javascript:void(0)'); ?>" style="display:block; padding:9px 8px; border-bottom:1px solid rgba(0,0,0,0.05); border-radius:6px; text-decoration:none;" onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='transparent'">
                                <div style="font-size:11.5px; font-weight:700; color:var(--text-main);"><?php echo h($n['title']); ?></div>
                                <div style="font-size:10.5px; color:var(--text-muted); margin-top:2px;"><?php echo h($n['message']); ?></div>
                                <div style="font-size:9px; color:var(--red-accent); margin-top:4px;"><?php echo date('M d, h:i A', strtotime($n['created_at'])); ?></div>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="font-size:11px; color:var(--text-muted); text-align:center; padding:14px 0;">No new notifications</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="rt-profile-wrapper">
            <div class="rt-profile-btn" id="profileToggle">
                <div class="avatar"><?php echo $userInitial; ?></div>
                <div class="info d-none d-sm-flex">
                    <span class="name"><?php echo h($userName); ?></span>
                    <span class="role"><?php echo h($userRole); ?></span>
                </div>
                <i class="fa-solid fa-chevron-down" style="font-size:10px; color:var(--text-muted);"></i>
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

<div class="wrapper">
    <div class="main">
        <div class="dashboard-shell">

            <!-- PAGE HEADER -->
            <div class="page-header-card">
                <div>
                    <h2><i class="fa-solid fa-shield-halved"></i> Regulatory Compliance Oversight</h2>
                    <p>Monitor, analyze, and enforce compliance across all rental establishments in the LGU.</p>
                </div>
                <div class="header-actions-group">
                    <div class="header-clock-badge" id="liveDateTime"></div>
                    <a href="reports.php" class="btn-rt-secondary">
                        <i class="fa-solid fa-file-invoice"></i> Full Report
                    </a>
                    <a href="compliance.php" class="btn-rt-primary">
                        <i class="fa-solid fa-rotate-right"></i> Refresh
                    </a>
                </div>
            </div>

            <!-- HEALTH SCORECARD -->
            <div class="health-scorecard">
                <div class="health-score-value">
                    <div class="health-score-number"><?php echo $complianceHealth; ?>%</div>
                    <div class="health-score-label <?php echo $healthColor; ?>">
                        <?php echo $healthLabel; ?>
                    </div>
                </div>
                <div class="health-breakdown">
                    <div style="font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px;">
                        <i class="fa-solid fa-chart-pie" style="margin-right: 6px;"></i> LGU-Wide Compliance Distribution
                    </div>
                    <div class="health-bar-wrap">
                        <?php 
                        $compPct = $stats['total'] > 0 ? ($stats['compliant'] / $stats['total']) * 100 : 0;
                        $pendPct = $stats['total'] > 0 ? ($stats['pending'] / $stats['total']) * 100 : 0;
                        $ncPct = $stats['total'] > 0 ? ($stats['non_compliant'] / $stats['total']) * 100 : 0;
                        ?>
                        <div class="health-bar-segment compliant" style="width: <?php echo $compPct; ?>%;"></div>
                        <div class="health-bar-segment pending" style="width: <?php echo $pendPct; ?>%;"></div>
                        <div class="health-bar-segment non-compliant" style="width: <?php echo $ncPct; ?>%;"></div>
                    </div>
                    <div class="health-legend">
                        <div class="health-legend-item">
                            <div class="health-legend-dot emerald"></div>
                            <span><strong style="color:var(--text-main);"><?php echo $stats['compliant']; ?></strong> Compliant</span>
                        </div>
                        <div class="health-legend-item">
                            <div class="health-legend-dot amber"></div>
                            <span><strong style="color:var(--text-main);"><?php echo $stats['pending']; ?></strong> Pending</span>
                        </div>
                        <div class="health-legend-item">
                            <div class="health-legend-dot red"></div>
                            <span><strong style="color:var(--text-main);"><?php echo $stats['non_compliant']; ?></strong> Non-Compliant</span>
                        </div>
                        <div class="health-legend-item" style="margin-left: auto;">
                            <span style="color:var(--text-muted); font-size: 11px;">Total: <strong style="color:var(--text-main);"><?php echo $stats['total']; ?></strong> establishments</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ALERT CARDS -->
            <div class="alert-cards-row">
                <a href="?compliance=Non-Compliant" class="alert-card danger">
                    <div class="alert-icon"><i class="fa-solid fa-circle-exclamation"></i></div>
                    <div class="alert-content">
                        <div class="alert-value"><?php echo $stats['expired'] + $stats['non_compliant']; ?></div>
                        <div class="alert-label">Critical Attention Required</div>
                        <div class="alert-sub"><?php echo $stats['expired']; ?> expired · <?php echo $stats['non_compliant']; ?> non-compliant</div>
                    </div>
                </a>

                <a href="?compliance=Pending" class="alert-card warning">
                    <div class="alert-icon"><i class="fa-solid fa-clock"></i></div>
                    <div class="alert-content">
                        <div class="alert-value"><?php echo $stats['expiring_30']; ?></div>
                        <div class="alert-label">Expiring in 30 Days</div>
                        <div class="alert-sub">Permits requiring renewal notice</div>
                    </div>
                </a>

                <a href="reports.php" class="alert-card info">
                    <div class="alert-icon"><i class="fa-solid fa-chart-line"></i></div>
                    <div class="alert-content">
                        <div class="alert-value"><?php echo $stats['pending']; ?></div>
                        <div class="alert-label">Pending Compliance</div>
                        <div class="alert-sub">Awaiting verification or inspection</div>
                    </div>
                </a>
            </div>

            <!-- STATS GRID -->
            <div class="stats-grid">
                <div class="stat-card-rt card-emerald">
                    <div>
                        <p>Compliant</p>
                        <h3><?php echo $stats['compliant']; ?></h3>
                        <div class="stat-sub"><?php echo $complianceHealth; ?>% compliance rate</div>
                    </div>
                </div>
                <div class="stat-card-rt card-amber">
                    <div>
                        <p>Pending Review</p>
                        <h3><?php echo $stats['pending']; ?></h3>
                        <div class="stat-sub">Awaiting action</div>
                    </div>
                </div>
                <div class="stat-card-rt card-red">
                    <div>
                        <p>Non-Compliant</p>
                        <h3><?php echo $stats['non_compliant']; ?></h3>
                        <div class="stat-sub">Need enforcement</div>
                    </div>
                </div>
                <div class="stat-card-rt card-violet">
                    <div>
                        <p>Expired Permits</p>
                        <h3><?php echo $stats['expired']; ?></h3>
                        <div class="stat-sub">Require immediate action</div>
                    </div>
                </div>
            </div>

            <!-- TREND + BRGY -->
            <div class="grid-2col">
                <div class="panel-card">
                    <div class="panel-head">
                        <h3><i class="fa-solid fa-chart-column"></i> Monthly Compliance Trend</h3>
                        <span class="badge">Last 6 Months</span>
                    </div>
                    <div class="panel-body">
                        <div class="chart-container">
                            <canvas id="trendChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="panel-card">
                    <div class="panel-head">
                        <h3><i class="fa-solid fa-location-dot"></i> Compliance by Barangay</h3>
                        <span class="badge">Top <?php echo count($barangayStats); ?></span>
                    </div>
                    <div class="panel-body" style="max-height: 320px; overflow-y: auto;">
                        <?php if (!empty($barangayStats)): ?>
                            <?php foreach ($barangayStats as $brgy): 
                                $rateColor = $brgy['rate'] >= 80 ? '#059669' : ($brgy['rate'] >= 60 ? '#d97706' : '#dc2626');
                            ?> 
                                <div class="brgy-stat-item">
                                    <div class="brgy-name">
                                        <i class="fa-solid fa-map-pin" style="color:var(--red-accent); font-size:11px; margin-right:6px;"></i>
                                        <?php echo h($brgy['name']); ?>
                                    </div>
                                    <div class="brgy-count"><?php echo $brgy['total']; ?></div>
                                    <div class="brgy-rate" style="color: <?php echo $rateColor; ?>;">
                                        <?php echo $brgy['rate']; ?>%
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="text-align:center; padding:20px; color:var(--text-muted); font-size:12px;">
                                <i class="fa-solid fa-map-location-dot" style="font-size:24px; opacity:0.4; display:block; margin-bottom:8px;"></i>
                                No barangay data available.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- EXPIRING COUNTS -->
            <div class="grid-3col">
                <div class="panel-card">
                    <div class="panel-head">
                        <h3><i class="fa-solid fa-hourglass-start"></i> Expiring ≤30 Days</h3>
                    </div>
                    <div class="panel-body" style="text-align:center;">
                        <div style="font-family:'Space Grotesk',sans-serif; font-size:48px; font-weight:800; color:#d97706; line-height:1;">
                            <?php echo $stats['expiring_30']; ?>
                        </div>
                        <div style="font-size:11px; color:var(--text-muted); margin-top:6px; text-transform:uppercase; font-weight:700;">Critical — Send Notice</div>
                    </div>
                </div>
                <div class="panel-card">
                    <div class="panel-head">
                        <h3><i class="fa-solid fa-hourglass-half"></i> Expiring 31-60 Days</h3>
                    </div>
                    <div class="panel-body" style="text-align:center;">
                        <div style="font-family:'Space Grotesk',sans-serif; font-size:48px; font-weight:800; color:#ea580c; line-height:1;">
                            <?php echo $stats['expiring_60']; ?>
                        </div>
                        <div style="font-size:11px; color:var(--text-muted); margin-top:6px; text-transform:uppercase; font-weight:700;">Warning — Prepare Reminder</div>
                    </div>
                </div>
                <div class="panel-card">
                    <div class="panel-head">
                        <h3><i class="fa-solid fa-hourglass-end"></i> Expiring 61-90 Days</h3>
                    </div>
                    <div class="panel-body" style="text-align:center;">
                        <div style="font-family:'Space Grotesk',sans-serif; font-size:48px; font-weight:800; color:#2563eb; line-height:1;">
                            <?php echo $stats['expiring_90']; ?>
                        </div>
                        <div style="font-size:11px; color:var(--text-muted); margin-top:6px; text-transform:uppercase; font-weight:700;">Monitor — Early Awareness</div>
                    </div>
                </div>
            </div>

            <!-- NON-COMPLIANT WATCHLIST -->
            <div class="panel-card" style="margin-bottom: 20px;">
                <div class="panel-head">
                    <h3><i class="fa-solid fa-triangle-exclamation" style="color:#dc2626;"></i> Non-Compliant Watchlist (Enforcement)</h3>
                    <span class="badge" style="background:rgba(220,38,38,0.1); color:#dc2626; border-color:rgba(220,38,38,0.2);">
                        <?php echo count($nonCompliantList); ?> establishments
                    </span>
                </div>
                <?php if (!empty($nonCompliantList)): ?>
                    <div style="overflow-x:auto;">
                        <table class="watchlist-table">
                            <thead>
                                <tr>
                                    <th>Business Name</th>
                                    <th>Owner</th>
                                    <th>Barangay</th>
                                    <th>Permit Expiry</th>
                                    <th>Registration</th>
                                    <th style="text-align:right;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($nonCompliantList, 0, 10) as $nc): ?>
                                    <tr>
                                        <td data-label="Business Name"><strong style="color:var(--text-main);"><?php echo h($nc['business_name']); ?></strong></td>
                                        <td data-label="Owner"><?php echo h($nc['owner_name'] ?? 'N/A'); ?></td>
                                        <td data-label="Barangay"><?php echo h($nc['barangay'] ?? 'N/A'); ?></td>
                                        <td data-label="Permit Expiry">
                                            <?php if (!empty($nc['permit_expiry'])): 
                                                $isExp = $nc['permit_expiry'] < date('Y-m-d');
                                            ?>
                                                <span style="color:<?php echo $isExp ? '#dc2626' : '#d97706'; ?>; font-weight:600;">
                                                    <?php echo date('M d, Y', strtotime($nc['permit_expiry'])); ?>
                                                </span>
                                            <?php else: ?>—<?php endif; ?>
                                        </td>
                                        <td data-label="Registration">
                                            <span class="badge-rt danger">
                                                <i class="fa-solid fa-ban"></i> <?php echo h($nc['status'] ?? 'Pending'); ?>
                                            </span>
                                        </td>
                                        <td data-label="Action" style="text-align:right;">
                                            <a href="rentals.php?search=<?php echo urlencode($nc['business_name']); ?>" class="btn-action-sm danger">
                                                <i class="fa-solid fa-gavel"></i> Enforce
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div style="padding: 30px; text-align: center; color: var(--text-muted);">
                        <i class="fa-solid fa-shield-halved" style="font-size: 32px; color: #059669; margin-bottom: 10px; display: block;"></i>
                        <strong style="color: var(--text-main); font-size: 14px;">All Establishments Compliant</strong>
                        <p style="font-size: 12px; margin-top: 6px;">Walang non-compliant establishments sa ngayon. Good job!</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- EXPIRING WATCHLIST -->
            <div class="panel-card" style="margin-bottom: 20px;">
                <div class="panel-head">
                    <h3><i class="fa-solid fa-clock" style="color:#d97706;"></i> Permits Expiring Soon (30 Days)</h3>
                    <span class="badge" style="background:rgba(217,119,6,0.1); color:#d97706; border-color:rgba(217,119,6,0.2);">
                        <?php echo count($expiringList); ?> permits
                    </span>
                </div>
                <?php if (!empty($expiringList)): ?>
                    <div style="overflow-x:auto;">
                        <table class="watchlist-table">
                            <thead>
                                <tr>
                                    <th>Business Name</th>
                                    <th>Owner</th>
                                    <th>Barangay</th>
                                    <th>Expiry Date</th>
                                    <th>Days Left</th>
                                    <th style="text-align:right;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($expiringList, 0, 10) as $exp): ?>
                                    <tr>
                                        <td data-label="Business Name"><strong style="color:var(--text-main);"><?php echo h($exp['business_name']); ?></strong></td>
                                        <td data-label="Owner"><?php echo h($exp['owner_name'] ?? 'N/A'); ?></td>
                                        <td data-label="Barangay"><?php echo h($exp['barangay'] ?? 'N/A'); ?></td>
                                        <td data-label="Expiry Date" style="color:#d97706; font-weight:600;"><?php echo date('M d, Y', strtotime($exp['permit_expiry'])); ?></td>
                                        <td data-label="Days Left">
                                            <span class="badge-rt warning">
                                                <i class="fa-solid fa-hourglass-half"></i>
                                                <?php echo $exp['days_until_expiry']; ?> days
                                            </span>
                                        </td>
                                        <td data-label="Action" style="text-align:right;">
                                            <a href="rentals.php?search=<?php echo urlencode($exp['business_name']); ?>" class="btn-action-sm warning">
                                                <i class="fa-solid fa-bell"></i> Notify
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div style="padding: 30px; text-align: center; color: var(--text-muted);">
                        <i class="fa-regular fa-calendar-check" style="font-size: 32px; color: #059669; margin-bottom: 10px; display: block;"></i>
                        <strong style="color: var(--text-main); font-size: 14px;">No Expiring Permits</strong>
                        <p style="font-size: 12px; margin-top: 6px;">Walang permit na mag-e-expire sa loob ng 30 araw.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- RECENT ACTIVITY -->
            <div class="panel-card" style="margin-bottom: 20px;">
                <div class="panel-head">
                    <h3><i class="fa-solid fa-file-contract"></i> Recent Regulatory Actions</h3>
                    <a href="activity_logs.php" style="font-size: 11px; color: var(--red-accent); text-decoration: none; font-weight: 700;">
                        View All &rarr;
                    </a>
                </div>
                <div class="panel-body">
                    <div class="activity-timeline">
                        <?php
                        $recentActions = [];
                        
                        foreach (array_slice($nonCompliantList, 0, 3) as $nc) {
                            $recentActions[] = [
                                'type' => 'danger',
                                'icon' => 'fa-triangle-exclamation',
                                'title' => 'Non-compliant flagged: ' . $nc['business_name'],
                                'sub' => 'Barangay ' . ($nc['barangay'] ?? 'N/A'),
                                'time' => 'Requires enforcement action'
                            ];
                        }
                        
                        foreach (array_slice($expiringList, 0, 2) as $exp) {
                            $recentActions[] = [
                                'type' => 'warning',
                                'icon' => 'fa-clock',
                                'title' => 'Permit expiring soon: ' . $exp['business_name'],
                                'sub' => 'Expires in ' . $exp['days_until_expiry'] . ' days',
                                'time' => 'Renewal notice required'
                            ];
                        }
                        
                        if (empty($recentActions)) {
                            $recentActions[] = [
                                'type' => 'success',
                                'icon' => 'fa-shield-halved',
                                'title' => 'All establishments compliant',
                                'sub' => 'No regulatory actions needed',
                                'time' => 'System healthy'
                            ];
                        }
                        ?>
                        <?php foreach ($recentActions as $act): ?>
                            <div class="activity-item">
                                <div class="activity-icon <?php echo $act['type']; ?>">
                                    <i class="fa-solid <?php echo $act['icon']; ?>"></i>
                                </div>
                                <div class="activity-body">
                                    <div class="activity-title"><?php echo h($act['title']); ?></div>
                                    <div class="activity-time"><?php echo h($act['sub']); ?> · <?php echo h($act['time']); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <footer class="footer">
                <span>&copy; <?php echo date('Y'); ?> <strong style="color:var(--red-accent);">RENTRACK LGU</strong> &middot; Admin Portal</span>
                <span>Regulatory Oversight &middot; Compliance Enforcement System</span>
            </footer>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    /* ============ DRAWER / NAV MENU ============ */
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

    menuToggle?.addEventListener('click', openDrawer);
    sidebarOverlay?.addEventListener('click', closeDrawer);
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

    /* ============ LIVE CLOCK ============ */
    function updateLiveDateTime() {
        const now = new Date();
        const options = { 
            timeZone: 'Asia/Manila', month: 'short', day: 'numeric', year: 'numeric',
            hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true 
        };
        const el = document.getElementById('liveDateTime');
        if (el) el.innerHTML = `<i class="fa-regular fa-clock"></i> ${now.toLocaleString('en-US', options)}`;
    }
    setInterval(updateLiveDateTime, 1000);
    updateLiveDateTime();

    /* ============ NOTIFICATIONS ============ */
    const notifBtn = document.getElementById('notifBtn');
    const notifDropdown = document.getElementById('notifDropdown');
    const notifBadgeDot = document.getElementById('notifBadgeDot');

    function clearNotificationDot() {
        if (notifBadgeDot) {
            notifBadgeDot.style.opacity = '0';
            setTimeout(() => { notifBadgeDot.style.display = 'none'; }, 300);
        }
        fetch('compliance.php?action=mark_notif_read');
    }

    notifBtn?.addEventListener('click', (e) => {
        e.stopPropagation();
        const isOpen = notifDropdown.style.display === 'block';
        notifDropdown.style.display = isOpen ? 'none' : 'block';
        if (!isOpen) clearNotificationDot();
    });
    document.getElementById('clearNotifBadge')?.addEventListener('click', clearNotificationDot);

    /* ============ PROFILE ============ */
    const profileToggle = document.getElementById('profileToggle');
    const profileDropdown = document.getElementById('profileDropdown');
    profileToggle?.addEventListener('click', (e) => {
        e.stopPropagation();
        profileDropdown.classList.toggle('show');
    });
    document.addEventListener('click', () => {
        profileDropdown?.classList.remove('show');
        if (notifDropdown) notifDropdown.style.display = 'none';
    });

    /* ============ SEARCH MODAL ============ */
    const openSearchModalBtn = document.getElementById('openSearchModalBtn');
    document.getElementById('mobileSearchBtn')?.addEventListener('click', () => openSearchModalBtn?.click());

    openSearchModalBtn?.addEventListener('click', () => {
        Swal.fire({
            html: `
                <div style="text-align: left;">
                    <div style="position: relative; margin-bottom: 15px;">
                        <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #64748b; font-size: 15px;"></i>
                        <input type="text" id="swalSearchInput" placeholder="Search business, owner, or barangay..." autocomplete="off" style="width: 100%; padding: 12px 16px 12px 42px; background: #f8fafc; color: #0f172a; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 13px; outline: none;">
                    </div>
                    <div id="swalSearchResults" style="min-height: 120px; max-height: 280px; overflow-y: auto; text-align: center; color: #64748b; display: flex; flex-direction: column; justify-content: center; align-items: center;">
                        <i class="fa-solid fa-keyboard" style="font-size: 28px; margin-bottom: 8px; color: #cbd5e1;"></i>
                        <span style="font-size: 12px;">Type at least 2 characters to search records...</span>
                    </div>
                </div>
            `,
            showConfirmButton: false,
            showCloseButton: true,
            background: '#ffffff',
            color: '#0f172a',
            width: '600px',
            didOpen: () => {
                const inputEl = document.getElementById('swalSearchInput');
                const resultsEl = document.getElementById('swalSearchResults');
                inputEl.focus();
                let debounceTimer;
                inputEl.addEventListener('input', (e) => {
                    clearTimeout(debounceTimer);
                    const query = e.target.value.trim();
                    if (query.length < 2) {
                        resultsEl.style.justifyContent = 'center';
                        resultsEl.innerHTML = `<i class="fa-solid fa-keyboard" style="font-size: 28px; margin-bottom: 8px; color: #cbd5e1;"></i><span style="font-size: 12px;">Type at least 2 characters...</span>`;
                        return;
                    }
                    resultsEl.style.justifyContent = 'flex-start';
                    resultsEl.innerHTML = `<div style="text-align: center; padding: 20px; font-size: 12px;"><i class="fa-solid fa-spinner fa-spin me-2"></i> Searching...</div>`;
                    debounceTimer = setTimeout(() => {
                        fetch(`compliance.php?action=global_search&q=${encodeURIComponent(query)}`)
                            .then(res => res.json())
                            .then(data => {
                                resultsEl.innerHTML = '';
                                if (data.length === 0) {
                                    resultsEl.style.justifyContent = 'center';
                                    resultsEl.innerHTML = '<div style="padding:20px; font-size:12px; text-align:center;">No matching records found</div>';
                                } else {
                                    data.forEach(item => {
                                        resultsEl.innerHTML += `
                                            <a href="${item.link}" style="display:flex; flex-direction:column; padding:10px 12px; border-radius:8px; text-decoration:none; color:#0f172a; border-bottom:1px solid #e2e8f0; margin-bottom:6px; background: #f8fafc; text-align: left;" onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='#f8fafc'">
                                                <span style="font-size:9.5px; font-weight:700; color:#dc2626; text-transform:uppercase;">${item.type}</span>
                                                <span style="font-size:13px; font-weight:700; color:#0f172a;">${item.title}</span>
                                                <span style="font-size:11px; color:#64748b;">${item.sub}</span>
                                            </a>
                                        `;
                                    });
                                }
                            });
                    }, 250);
                });
            }
        });
    });

    /* ============ LOGOUT ============ */
    function confirmLogout(e) {
        e.preventDefault();
        Swal.fire({
            title: 'Logout from Admin Portal?',
            text: 'Are you sure you want to log out?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, Logout',
            background: '#ffffff',
            color: '#0f172a'
        }).then((res) => {
            if (res.isConfirmed) window.location.href = '../logout.php';
        });
    }
    document.getElementById('profileLogoutBtn')?.addEventListener('click', confirmLogout);

    /* ============ TREND CHART ============ */
    const trendLabels = <?php echo json_encode(array_column($monthlyTrend, 'month_label')); ?>;
    const trendRates = <?php echo json_encode(array_column($monthlyTrend, 'rate')); ?>;
    const trendTotals = <?php echo json_encode(array_column($monthlyTrend, 'total')); ?>;
    const trendCompliant = <?php echo json_encode(array_column($monthlyTrend, 'compliant')); ?>;

    const trendCtx = document.getElementById('trendChart');
    if (trendCtx && trendLabels.length > 0) {
        new Chart(trendCtx, {
            type: 'bar',
            data: {
                labels: trendLabels,
                datasets: [
                    {
                        label: 'Total Establishments',
                        data: trendTotals,
                        backgroundColor: 'rgba(220, 38, 38, 0.75)',
                        borderColor: '#dc2626',
                        borderWidth: 1, borderRadius: 6,
                        maxBarThickness: 40, barPercentage: 0.7, categoryPercentage: 0.6,
                        order: 2
                    },
                    {
                        label: 'Compliant',
                        data: trendCompliant,
                        backgroundColor: 'rgba(5, 150, 105, 0.85)',
                        borderColor: '#059669',
                        borderWidth: 1, borderRadius: 6,
                        maxBarThickness: 40, barPercentage: 0.7, categoryPercentage: 0.6,
                        order: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            color: '#334155',
                            font: { size: 11, family: 'Plus Jakarta Sans', weight: '600' },
                            padding: 14, usePointStyle: true, pointStyle: 'rectRounded'
                        }
                    },
                    tooltip: {
                        backgroundColor: '#ffffff',
                        borderColor: '#e2e8f0', borderWidth: 1,
                        padding: 12, titleColor: '#0f172a',
                        titleFont: { size: 12, weight: '700' },
                        bodyColor: '#334155', bodyFont: { size: 11 },
                        cornerRadius: 8, displayColors: true,
                        callbacks: {
                            afterBody: function(context) {
                                const dataIndex = context[0].dataIndex;
                                const rate = trendRates[dataIndex] || 0;
                                return `Compliance Rate: ${rate}%`;
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: '#64748b', font: { size: 10.5, weight: '600' } } },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(0, 0, 0, 0.05)' },
                        ticks: { color: '#64748b', font: { size: 10 }, precision: 0, stepSize: 1 }
                    }
                }
            }
        });
    } else if (trendCtx) {
        trendCtx.parentElement.innerHTML = '<div style="display:flex; align-items:center; justify-content:center; height:100%; color:#64748b; font-size:12px;">No trend data available</div>';
    }
</script>
</body>
</html>