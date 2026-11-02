<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();
date_default_timezone_set('Asia/Manila');

require_once '../config/db.php';
require_once 'includes/auth.php';

/* =====================================================
   HELPER FUNCTIONS (PDO / PostgreSQL Compatible)
===================================================== */
function tableExists($conn, $tableName) {
    try {
        $stmt = $conn->prepare("
            SELECT EXISTS (
                SELECT 1 FROM information_schema.tables 
                WHERE table_schema = 'public' AND table_name = :table
            )
        ");
        $stmt->execute([':table' => strtolower($tableName)]);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $e) { return false; }
}

function columnExists($conn, $tableName, $columnName) {
    if (!tableExists($conn, $tableName)) return false;
    try {
        $stmt = $conn->prepare("
            SELECT EXISTS (
                SELECT 1 FROM information_schema.columns 
                WHERE table_schema = 'public' AND table_name = :table AND column_name = :column
            )
        ");
        $stmt->execute([':table' => strtolower($tableName), ':column' => strtolower($columnName)]);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $e) { return false; }
}

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function formatCurrency($amount) {
    return '₱' . number_format((float)$amount, 2);
}

$adminUserId = (int) ($_SESSION['user_id'] ?? 1);

/* =====================================================
   FILTERS
===================================================== */
$dateFrom      = trim($_GET['date_from'] ?? '');
$dateTo        = trim($_GET['date_to'] ?? '');
$barangayFilter = trim($_GET['barangay'] ?? '');
$statusFilter  = trim($_GET['rental_status'] ?? '');
$complianceFilter = trim($_GET['compliance'] ?? '');

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
    if (strlen($q) >= 1 && tableExists($conn, 'rentals')) {
        $searchTerm = '%' . $q . '%';
        $stmt = $conn->prepare("
            SELECT id, business_name, rental_name, tracking_no, status::text AS status
            FROM rentals 
            WHERE is_deleted = FALSE 
              AND (business_name ILIKE :q OR rental_name ILIKE :q OR tracking_no ILIKE :q)
            LIMIT 5
        ");
        $stmt->execute([':q' => $searchTerm]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = [
                'type' => 'Rental Property',
                'title' => $row['business_name'] ?: $row['rental_name'],
                'sub' => 'Tracking: ' . $row['tracking_no'] . ' | Status: ' . $row['status'],
                'link' => 'rentals.php?search=' . urlencode($row['tracking_no'] ?? '')
            ];
        }
    }
    echo json_encode($results);
    exit;
}

/* =====================================================
   SECTION 1: FINANCIAL METRICS
===================================================== */
$totalRevenue = 0;
$monthRevenue = 0;
$pendingAmount = 0;
$totalTransactions = 0;

if (tableExists($conn, 'payments')) {
    try {
        $stmt = $conn->prepare("
            SELECT 
                COALESCE(SUM(amount_paid), 0) AS total,
                COALESCE(SUM(CASE WHEN DATE_TRUNC('month', payment_date) = DATE_TRUNC('month', CURRENT_DATE) THEN amount_paid ELSE 0 END), 0) AS month_total,
                COALESCE(SUM(CASE WHEN status::text = 'Pending' THEN amount_paid ELSE 0 END), 0) AS pending_total,
                COUNT(*) AS tx_count
            FROM payments
            WHERE is_voided = FALSE
        ");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalRevenue       = (float)($row['total'] ?? 0);
        $monthRevenue       = (float)($row['month_total'] ?? 0);
        $pendingAmount      = (float)($row['pending_total'] ?? 0);
        $totalTransactions  = (int)($row['tx_count'] ?? 0);
    } catch (PDOException $e) { error_log("Financial metrics error: " . $e->getMessage()); }
}

/* =====================================================
   SECTION 2: COMPLIANCE & RENTAL METRICS
===================================================== */
$totalRentals = 0;
$approvedRentals = 0;
$pendingRentals = 0;
$nonCompliantRentals = 0;
$compliantRentals = 0;
$pendingPermits = 0;
$expiringPermits = 0;
$expiredPermits = 0;

if (tableExists($conn, 'rentals')) {
    try {
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE status::text = 'Approved') AS approved,
                COUNT(*) FILTER (WHERE status::text = 'Pending') AS pending,
                COUNT(*) FILTER (WHERE compliance_status::text = 'Non-Compliant') AS non_compliant,
                COUNT(*) FILTER (WHERE compliance_status::text = 'Compliant') AS compliant,
                COUNT(*) FILTER (WHERE permit_status = 'Pending') AS pending_permits,
                COUNT(*) FILTER (WHERE permit_expiry IS NOT NULL AND permit_expiry <= CURRENT_DATE + INTERVAL '30 days' AND permit_expiry >= CURRENT_DATE) AS expiring_permits,
                COUNT(*) FILTER (WHERE permit_expiry IS NOT NULL AND permit_expiry < CURRENT_DATE) AS expired_permits
            FROM rentals
            WHERE is_deleted = FALSE
        ");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalRentals        = (int)($row['total'] ?? 0);
        $approvedRentals     = (int)($row['approved'] ?? 0);
        $pendingRentals      = (int)($row['pending'] ?? 0);
        $nonCompliantRentals = (int)($row['non_compliant'] ?? 0);
        $compliantRentals    = (int)($row['compliant'] ?? 0);
        $pendingPermits      = (int)($row['pending_permits'] ?? 0);
        $expiringPermits     = (int)($row['expiring_permits'] ?? 0);
        $expiredPermits      = (int)($row['expired_permits'] ?? 0);
    } catch (PDOException $e) { error_log("Compliance metrics error: " . $e->getMessage()); }
}

/* =====================================================
   SECTION 3: INSPECTION METRICS
===================================================== */
$totalInspections = 0;
$pendingInspections = 0;
$scheduledInspections = 0;
$passedInspections = 0;
$failedInspections = 0;
$overdueInspections = 0;

if (tableExists($conn, 'inspections')) {
    try {
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE inspection_status ILIKE 'Pending') AS pending,
                COUNT(*) FILTER (WHERE inspection_status ILIKE 'Scheduled') AS scheduled,
                COUNT(*) FILTER (WHERE inspection_status ILIKE 'Passed' OR inspection_status ILIKE 'Completed') AS passed,
                COUNT(*) FILTER (WHERE inspection_status ILIKE 'Failed') AS failed,
                COUNT(*) FILTER (WHERE inspection_status ILIKE 'Pending' AND inspection_date < CURRENT_DATE - INTERVAL '7 days') AS overdue
            FROM inspections
        ");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalInspections     = (int)($row['total'] ?? 0);
        $pendingInspections   = (int)($row['pending'] ?? 0);
        $scheduledInspections = (int)($row['scheduled'] ?? 0);
        $passedInspections    = (int)($row['passed'] ?? 0);
        $failedInspections    = (int)($row['failed'] ?? 0);
        $overdueInspections   = (int)($row['overdue'] ?? 0);
    } catch (PDOException $e) { error_log("Inspection metrics error: " . $e->getMessage()); }
}

/* =====================================================
   SECTION 4: ACTIVITY METRICS
===================================================== */
$totalLogs = 0;
$todayLogs = 0;
$weekLogs = 0;
$roleActivity = ['admin' => 0, 'staff' => 0, 'owner' => 0, 'inspector' => 0];

if (tableExists($conn, 'activity_logs')) {
    try {
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE DATE(created_at) = CURRENT_DATE) AS today,
                COUNT(*) FILTER (WHERE created_at >= CURRENT_DATE - INTERVAL '7 days') AS week,
                COUNT(*) FILTER (WHERE role ILIKE 'admin') AS admin_count,
                COUNT(*) FILTER (WHERE role ILIKE 'staff') AS staff_count,
                COUNT(*) FILTER (WHERE role ILIKE 'owner') AS owner_count,
                COUNT(*) FILTER (WHERE role ILIKE 'inspector') AS inspector_count
            FROM activity_logs
        ");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalLogs = (int)($row['total'] ?? 0);
        $todayLogs = (int)($row['today'] ?? 0);
        $weekLogs  = (int)($row['week'] ?? 0);
        $roleActivity['admin']     = (int)($row['admin_count'] ?? 0);
        $roleActivity['staff']     = (int)($row['staff_count'] ?? 0);
        $roleActivity['owner']     = (int)($row['owner_count'] ?? 0);
        $roleActivity['inspector'] = (int)($row['inspector_count'] ?? 0);
    } catch (PDOException $e) { error_log("Activity metrics error: " . $e->getMessage()); }
}

/* =====================================================
   CHART 1: REVENUE TREND
===================================================== */
$revenueLabels = [];
$revenueValues = [];

if (tableExists($conn, 'payments')) {
    try {
        $stmt = $conn->prepare("
            SELECT 
                TO_CHAR(payment_date, 'Mon YYYY') AS month_label,
                DATE_TRUNC('month', payment_date) AS month_sort,
                COALESCE(SUM(amount_paid), 0) AS total
            FROM payments
            WHERE is_voided = FALSE
              AND payment_date >= CURRENT_DATE - INTERVAL '6 months'
            GROUP BY DATE_TRUNC('month', payment_date), TO_CHAR(payment_date, 'Mon YYYY')
            ORDER BY month_sort ASC
        ");
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $revenueLabels[] = $row['month_label'];
            $revenueValues[] = (float) $row['total'];
        }
    } catch (PDOException $e) { error_log("Revenue trend error: " . $e->getMessage()); }
}

/* =====================================================
   CHART 2: INSPECTION STATUS BREAKDOWN
===================================================== */
$inspStatusLabels = [];
$inspStatusValues = [];

if (tableExists($conn, 'inspections')) {
    try {
        $stmt = $conn->prepare("
            SELECT inspection_status AS status, COUNT(*) AS count
            FROM inspections
            GROUP BY inspection_status
            ORDER BY count DESC
        ");
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $inspStatusLabels[] = $row['status'] ?: 'Unknown';
            $inspStatusValues[] = (int) $row['count'];
        }
    } catch (PDOException $e) { error_log("Inspection status error: " . $e->getMessage()); }
}

/* =====================================================
   CHART 3: COMPLIANCE BREAKDOWN
===================================================== */
$complianceLabels = [];
$complianceValues = [];

if (tableExists($conn, 'rentals')) {
    try {
        $stmt = $conn->prepare("
            SELECT compliance_status::text AS status, COUNT(*) AS count
            FROM rentals
            WHERE is_deleted = FALSE
            GROUP BY compliance_status
            ORDER BY count DESC
        ");
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $complianceLabels[] = $row['status'] ?: 'Unknown';
            $complianceValues[] = (int) $row['count'];
        }
    } catch (PDOException $e) { error_log("Compliance breakdown error: " . $e->getMessage()); }
}

/* =====================================================
   CHART 4: PAYMENT CHANNEL BREAKDOWN
===================================================== */
$channelLabels = [];
$channelValues = [];

if (tableExists($conn, 'payments')) {
    try {
        $stmt = $conn->prepare("
            SELECT COALESCE(payment_method::text, 'Unknown') AS method, 
                   COUNT(*) AS count,
                   COALESCE(SUM(amount_paid), 0) AS total
            FROM payments
            WHERE is_voided = FALSE
            GROUP BY payment_method
            ORDER BY total DESC
        ");
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $channelLabels[] = $row['method'];
            $channelValues[] = (float) $row['total'];
        }
    } catch (PDOException $e) { error_log("Channel breakdown error: " . $e->getMessage()); }
}

/* =====================================================
   TABLE 1: PERMITS EXPIRING SOON
===================================================== */
$expiringPermitRows = [];
if (tableExists($conn, 'rentals')) {
    try {
        $stmt = $conn->prepare("
            SELECT id, business_name, rental_name, tracking_no, permit_expiry,
                   (permit_expiry - CURRENT_DATE) AS days_left
            FROM rentals
            WHERE is_deleted = FALSE
              AND permit_expiry IS NOT NULL
              AND permit_expiry >= CURRENT_DATE
              AND permit_expiry <= CURRENT_DATE + INTERVAL '30 days'
            ORDER BY permit_expiry ASC
            LIMIT 10
        ");
        $stmt->execute();
        $expiringPermitRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { error_log("Expiring permits error: " . $e->getMessage()); }
}

/* =====================================================
   TABLE 2: OVERDUE INSPECTIONS
===================================================== */
$overdueInspectionRows = [];
if (tableExists($conn, 'inspections')) {
    try {
        $stmt = $conn->prepare("
            SELECT i.id, i.inspection_no, i.inspection_date, i.inspection_status,
                   r.business_name, r.rental_name,
                   (CURRENT_DATE - DATE(i.inspection_date)) AS days_overdue
            FROM inspections i
            INNER JOIN rentals r ON i.rental_id = r.id
            WHERE r.is_deleted = FALSE
              AND i.inspection_status ILIKE 'Pending'
              AND i.inspection_date < CURRENT_DATE - INTERVAL '7 days'
            ORDER BY days_overdue DESC
            LIMIT 10
        ");
        $stmt->execute();
        $overdueInspectionRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { error_log("Overdue inspections error: " . $e->getMessage()); }
}

/* =====================================================
   TABLE 3: RECENT PAYMENTS
===================================================== */
$recentPayments = [];
if (tableExists($conn, 'payments')) {
    try {
        $stmt = $conn->prepare("
            SELECT p.id, p.or_number, p.amount_paid, p.payment_method::text AS method,
                   p.payment_date, p.status::text AS status,
                   r.business_name, r.rental_name
            FROM payments p
            LEFT JOIN rentals r ON p.rental_id = r.id
            WHERE p.is_voided = FALSE
            ORDER BY p.payment_date DESC NULLS LAST, p.id DESC
            LIMIT 5
        ");
        $stmt->execute();
        $recentPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { error_log("Recent payments error: " . $e->getMessage()); }
}

/* =====================================================
   TABLE 4: RECENT INSPECTIONS
===================================================== */
$recentInspections = [];
if (tableExists($conn, 'inspections')) {
    try {
        $stmt = $conn->prepare("
            SELECT i.id, i.inspection_no, i.inspection_date, i.inspection_status,
                   r.business_name, r.rental_name,
                   u.fullname AS inspector_name
            FROM inspections i
            LEFT JOIN rentals r ON i.rental_id = r.id
            LEFT JOIN users u ON i.inspector_id = u.id
            WHERE r.is_deleted = FALSE OR r.is_deleted IS NULL
            ORDER BY i.created_at DESC NULLS LAST
            LIMIT 5
        ");
        $stmt->execute();
        $recentInspections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { error_log("Recent inspections error: " . $e->getMessage()); }
}

/* =====================================================
   MAIN REPORT TABLE
===================================================== */
$whereClauses = ["r.is_deleted = FALSE"];
$params = [];

if ($dateFrom !== '') {
    $whereClauses[] = "r.created_at >= :date_from";
    $params[':date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $whereClauses[] = "r.created_at <= :date_to";
    $params[':date_to'] = $dateTo . ' 23:59:59';
}
if ($barangayFilter !== '') {
    $whereClauses[] = "r.barangay ILIKE :barangay";
    $params[':barangay'] = "%$barangayFilter%";
}
if ($statusFilter !== '') {
    $whereClauses[] = "r.status::text = :status";
    $params[':status'] = $statusFilter;
}
if ($complianceFilter !== '') {
    $whereClauses[] = "r.compliance_status::text = :compliance";
    $params[':compliance'] = $complianceFilter;
}

$whereSql = implode(' AND ', $whereClauses);

$rentalReport = [];
if (tableExists($conn, 'rentals')) {
    try {
        $stmt = $conn->prepare("
            SELECT 
                r.id, r.tracking_no, r.business_name, r.rental_name, r.barangay,
                r.status::text AS status, r.compliance_status::text AS compliance_status,
                r.permit_status, r.permit_expiry,
                r.monthly_rate, r.created_at,
                u.fullname AS owner_name,
                COALESCE((SELECT SUM(p.amount_paid) FROM payments p 
                          WHERE p.rental_id = r.id AND p.is_voided = FALSE), 0) AS total_paid
            FROM rentals r
            LEFT JOIN users u ON r.user_id = u.id
            WHERE $whereSql
            ORDER BY r.created_at DESC
            LIMIT 100
        ");
        $stmt->execute($params);
        $rentalReport = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { error_log("Rental report error: " . $e->getMessage()); }
}

/* =====================================================
   BARANGAYS LIST
===================================================== */
$barangayList = [];
if (tableExists($conn, 'rentals')) {
    try {
        $stmt = $conn->prepare("
            SELECT DISTINCT barangay FROM rentals 
            WHERE barangay IS NOT NULL AND barangay != '' AND is_deleted = FALSE
            ORDER BY barangay ASC
        ");
        $stmt->execute();
        $barangayList = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) { }
}

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

$userName = $_SESSION['fullname'] ?? ($_SESSION['user_name'] ?? 'Admin');
$userRole = $_SESSION['role'] ?? ($_SESSION['user_role'] ?? 'Administrator');
$userInitial = strtoupper(substr($userName, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
<meta name="theme-color" content="#dc2626">
<title>Regulatory Reports | RENTRACK LGU Admin</title>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

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

    .swal2-backdrop-show { background: rgba(0, 0, 0, 0.4) !important; backdrop-filter: blur(4px) !important; -webkit-backdrop-filter: blur(4px) !important; }

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
    .topbar-left { display: flex; align-items: center; gap: 8px; min-width: 0; overflow: hidden; }
    .topbar-left i { font-size: 12px; color: #fecdd3; flex-shrink: 0; }
    .topbar-left strong { font-weight: 800; white-space: nowrap; flex-shrink: 0; }
    .topbar-left .divider { width: 1px; height: 12px; background: rgba(255, 255, 255, 0.3); flex-shrink: 0; }
    .topbar-left .portal-title { color: #ffe4e6; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .topbar-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
    .cert-badge {
        display: inline-flex; align-items: center; gap: 6px; padding: 3px 12px;
        background: rgba(255, 255, 255, 0.15); border: 1px solid rgba(255, 255, 255, 0.3);
        border-radius: 999px; color: #fff; font-weight: 700; font-size: 10px;
    }

    /* MAIN NAVBAR */
    .rt-topbar {
        position: fixed; top: var(--topofficial-height); left: 0; width: 100%;
        background: var(--navbar-bg);
        border-bottom: 1px solid var(--border-color);
        display: flex; align-items: center; justify-content: space-between;
        height: var(--navbar-height); z-index: 1050;
        gap: 12px;
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
    .rt-topbar-brand-text span { font-size: 9px; font-weight: 700; color: var(--red-accent); text-transform: uppercase; }

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
    .btn-rt-secondary {
        background: #fff; color: var(--text-main); border: 1px solid var(--border-color);
        padding: 8px 16px; border-radius: 10px; font-size: 12px; font-weight: 700; text-decoration: none;
        transition: var(--transition); display: inline-flex; align-items: center; gap: 8px; cursor: pointer;
    }
    .btn-rt-secondary:hover { background: var(--bg-card-alt); color: var(--text-main); border-color: var(--border-strong); }

    /* METRICS GRID */
    .metrics-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 14px;
        margin-bottom: 20px;
    }
    .metric-card {
        border-radius: 14px; padding: 18px 20px;
        display: flex; align-items: center; gap: 14px;
        border: 1px solid rgba(255, 255, 255, 0.15);
        transition: transform 0.2s;
        box-shadow: var(--shadow-soft);
    }
    .metric-card:hover { transform: translateY(-2px); }
    .metric-card.emerald { background: linear-gradient(135deg, #047857, #059669); }
    .metric-card.amber { background: linear-gradient(135deg, #b45309, #d97706); }
    .metric-card.red { background: linear-gradient(135deg, #be123c, #e11d48); }
    .metric-card.cyan { background: linear-gradient(135deg, #0891b2, #155e75); }
    .metric-card.violet { background: linear-gradient(135deg, #6d28d9, #8b5cf6); }
    .metric-card.slate { background: linear-gradient(135deg, #334155, #475569); }
    .metric-icon {
        width: 46px; height: 46px; border-radius: 12px;
        background: rgba(255,255,255,0.2); display: flex;
        align-items: center; justify-content: center;
        font-size: 20px; color: #fff; flex-shrink: 0;
    }
    .metric-content { min-width: 0; }
    .metric-value {
        font-family: 'Space Grotesk', sans-serif;
        font-size: 22px; font-weight: 800; color: #fff; line-height: 1.1;
    }
    .metric-label {
        font-size: 10.5px; font-weight: 700; color: rgba(255,255,255,0.9);
        text-transform: uppercase; letter-spacing: 0.5px;
    }
    .metric-sub { font-size: 10px; color: rgba(255,255,255,0.75); margin-top: 2px; }

    /* GRID LAYOUTS */
    .grid-2col-eq { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px; }

    .panel-card {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        overflow: hidden;
        box-shadow: var(--shadow-soft);
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
    .chart-container { position: relative; height: 260px; }

    /* TABLE */
    table.rt-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
    table.rt-table th {
        background: #f8fafc; color: var(--text-muted); font-size: 10.5px;
        font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
        padding: 12px 16px; border-bottom: 1px solid var(--border-color); text-align: left;
    }
    table.rt-table td {
        padding: 12px 16px; border-bottom: 1px solid var(--border-color);
        vertical-align: middle; color: var(--text-main);
    }
    table.rt-table tr:hover { background: #f8fafc; }

    .badge-rt {
        display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px;
        border-radius: 999px; font-size: 10px; font-weight: 700; text-transform: uppercase;
    }
    .badge-rt.success { background: rgba(5, 150, 105, 0.1); color: #059669; border: 1px solid rgba(5, 150, 105, 0.2); }
    .badge-rt.warning { background: rgba(217, 119, 6, 0.1); color: #d97706; border: 1px solid rgba(217, 119, 6, 0.2); }
    .badge-rt.danger { background: rgba(220, 38, 38, 0.1); color: #dc2626; border: 1px solid rgba(220, 38, 38, 0.2); }
    .badge-rt.dark { background: rgba(100, 116, 139, 0.1); color: #475569; border: 1px solid rgba(100, 116, 139, 0.2); }
    .badge-rt.cyan { background: rgba(8, 145, 178, 0.1); color: #0891b2; border: 1px solid rgba(8, 145, 178, 0.2); }
    .badge-rt.purple { background: rgba(124, 58, 237, 0.1); color: #7c3aed; border: 1px solid rgba(124, 58, 237, 0.2); }

    /* FILTER BAR */
    .filter-bar-rt {
        background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px;
        padding: 16px; margin-bottom: 20px; box-shadow: var(--shadow-soft);
    }
    .filter-bar-rt form { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; width: 100%; }
    .filter-bar-rt select, .filter-bar-rt input {
        padding: 8px 14px; border-radius: 10px; border: 1px solid var(--border-color);
        background: var(--bg-card-alt); color: var(--text-main); font-size: 13px; outline: none;
    }
    .filter-bar-rt select:focus, .filter-bar-rt input:focus { border-color: var(--red-accent); background: #fff; }
    .btn-filter-rt {
        background: var(--red-accent); color: #fff; border: none; padding: 8px 18px;
        border-radius: 10px; font-size: 13px; font-weight: 800; cursor: pointer;
        display: inline-flex; align-items: center; gap: 6px;
    }
    .btn-filter-rt:hover { background: #b91c1c; }

    .footer {
        margin-top: auto; padding-top: 14px; padding-bottom: var(--safe-bottom);
        border-top: 1px solid var(--border-color);
        display: flex; justify-content: space-between;
        font-size: 11.5px; color: var(--text-muted); flex-wrap: wrap; gap: 8px;
    }

    /* PRINT HEADER (hidden on screen) */
    .print-title { display: none; }

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

        .metrics-grid { grid-template-columns: repeat(2, 1fr); }
    }

    @media (max-width: 992px) {
        .rt-topbar { flex-wrap: wrap; height: auto; padding-bottom: 6px; }
        .rt-topbar-brand { border-right: none; }
        .rt-topbar-center {
            order: 5; flex-basis: 100%;
            margin-top: 4px; margin-bottom: 4px;
        }
        body { padding-top: calc(var(--topofficial-height) + var(--navbar-height) + 50px); }
        .grid-2col-eq { grid-template-columns: 1fr; }
    }

    @media (max-width: 768px) {
        .rt-topbar-right { gap: 8px; padding-right: calc(12px + var(--safe-right)); }
        .rt-topbar-brand-text { display: none; }
        .dashboard-shell { padding: 12px; }
        .page-header-card { padding: 15px; flex-direction: column; align-items: flex-start; gap: 13px; }
        .page-header-card h2 { font-size: 17px; }
        .header-actions-group { width: 100%; display: grid; grid-template-columns: 1fr 1fr; gap: 7px; }
        .header-actions-group > * { width: 100%; justify-content: center; }
        .header-clock-badge { grid-column: 1 / -1; }

        .metrics-grid { grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px; }
        .metric-card { padding: 12px; gap: 10px; border-radius: 12px; }
        .metric-icon { width: 36px; height: 36px; font-size: 15px; border-radius: 9px; }
        .metric-value { font-size: 16px !important; }
        .metric-label { font-size: 9px; }
        .metric-sub { font-size: 9px; }

        .grid-2col-eq { gap: 12px; margin-bottom: 14px; }

        .panel-card { border-radius: 14px; }
        .panel-head { padding: 12px 14px; }
        .panel-head h3 { font-size: 13px; gap: 6px; }
        .panel-head .badge { font-size: 9px; padding: 3px 8px; }
        .panel-body { padding: 12px 14px; }
        .chart-container { height: 220px; }

        /* Filter Bar → Stacked */
        .filter-bar-rt { padding: 12px; }
        .filter-bar-rt form { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .filter-bar-rt form > * { width: 100%; min-width: 0 !important; }
        .filter-bar-rt select,
        .filter-bar-rt input { min-height: 40px; font-size: 12px; }
        .filter-bar-rt .btn-filter-rt,
        .filter-bar-rt .btn-rt-secondary { padding: 10px 12px; font-size: 12px; justify-content: center; grid-column: 1 / -1; }

        /* Table → Cards on Mobile */
        .panel-card > div[style*="overflow-x:auto"] { overflow: visible !important; }

        table.rt-table,
        table.rt-table tbody { display: block; width: 100%; }
        table.rt-table thead { display: none; }
        table.rt-table tbody { padding: 8px; }

        table.rt-table tbody tr {
            display: block; width: 100%; margin: 0 0 10px; padding: 5px 0;
            background: #f8fafc; border: 1px solid var(--border-color);
            border-radius: 12px; overflow: hidden; box-shadow: var(--shadow-soft);
        }
        table.rt-table tbody tr:last-child { margin-bottom: 0; }

        table.rt-table tbody td {
            display: grid; grid-template-columns: minmax(90px, 34%) minmax(0, 66%);
            align-items: center; gap: 8px; width: 100%; min-width: 0;
            padding: 9px 11px; border-bottom: 1px solid var(--border-color);
            font-size: 11.5px; line-height: 1.4; overflow-wrap: anywhere; word-break: break-word;
        }
        table.rt-table tbody td:last-child { border-bottom: 0; }
        table.rt-table tbody td::before {
            content: attr(data-label); color: var(--text-muted);
            font-size: 9px; font-weight: 800;
            text-transform: uppercase; letter-spacing: .45px; min-width: 0;
        }
        table.rt-table tbody td > * { min-width: 0; max-width: 100%; }
        table.rt-table .badge-rt { justify-self: start; width: fit-content; font-size: 8.5px; padding: 4px 7px; }

        .footer { flex-direction: column; align-items: flex-start; font-size: 10px; }

        #notifDropdown {
            position: fixed !important; top: calc(var(--header-total-height) + 5px) !important;
            right: max(8px, var(--safe-right)) !important; left: max(8px, var(--safe-left)) !important;
            width: auto !important; max-width: none !important; max-height: min(70dvh, 430px); overflow-y: auto;
        }

        .swal2-popup {
            width: calc(100vw - 20px) !important; max-width: 600px !important;
            margin: 10px !important; border-radius: 16px !important;
        }
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
        .metrics-grid { grid-template-columns: 1fr; }
        .header-actions-group { grid-template-columns: 1fr; }
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
        table.rt-table tbody td { grid-template-columns: minmax(76px, 36%) minmax(0, 64%); font-size: 10.5px; }
    }

    @media (hover: none) and (pointer: coarse) {
        .metric-card:hover, .nav-link:hover, .icon-btn:hover,
        .rt-profile-btn:hover, .btn-rt-secondary:hover { transform: none; }
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

    /* =====================================================
       PRINT STYLES — Optimized for A4 / Letter
    ===================================================== */
    @media print {
        @page { size: A4 portrait; margin: 12mm 10mm; }

        .sidebar, .rt-topbar, .top-official-bar, .header-actions-group,
        .filter-bar-rt, .sidebar-overlay, .footer, .rt-profile-wrapper,
        .rt-search-container, #notifBtn, #notifDropdown, .icon-btn,
        button, .btn-rt-secondary { display: none !important; }

        html, body {
            background: #ffffff !important;
            background-image: none !important;
            color: #000000 !important;
            padding: 0 !important; margin: 0 !important;
            font-size: 10pt !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        body { padding-top: 0 !important; }

        .wrapper { display: block !important; min-height: auto !important; }
        .main { margin-left: 0 !important; padding: 0 !important; width: 100% !important; display: block !important; }
        .dashboard-shell { padding: 0 !important; display: block !important; }

        .print-title {
            display: block !important;
            margin-bottom: 12px; padding-bottom: 10px;
            border-bottom: 2px solid #000;
        }
        .print-title h1 { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 16pt; font-weight: 800; color: #000; margin: 0 0 4px 0; }
        .print-title p { font-size: 9pt; color: #444; margin: 0; line-height: 1.4; }

        .page-header-card {
            background: #ffffff !important; background-image: none !important;
            border: 1px solid #cccccc !important; box-shadow: none !important;
            color: #000 !important; padding: 10px 14px !important; margin-bottom: 12px !important;
        }
        .page-header-card h2 { color: #000 !important; font-size: 13pt !important; margin: 0 !important; }
        .page-header-card h2 i { background: none !important; color: #333 !important; padding: 0 !important; }
        .page-header-card p { color: #333 !important; font-size: 9pt !important; }

        .metrics-grid { display: grid !important; grid-template-columns: repeat(4, 1fr) !important; gap: 6px !important; margin-bottom: 12px !important; }
        .metric-card {
            background: #f8f8f8 !important; background-image: none !important;
            border: 1px solid #cccccc !important; border-radius: 4px !important;
            padding: 8px 10px !important; box-shadow: none !important;
            break-inside: avoid !important; page-break-inside: avoid !important;
        }
        .metric-icon { background: #e5e5e5 !important; color: #333 !important; width: 28px !important; height: 28px !important; font-size: 13px !important; border-radius: 6px !important; }
        .metric-value { color: #000 !important; font-size: 11pt !important; }
        .metric-label { color: #333 !important; font-size: 7pt !important; }
        .metric-sub { color: #666 !important; font-size: 6.5pt !important; }

        .grid-2col-eq { display: grid !important; grid-template-columns: 1fr 1fr !important; gap: 8px !important; margin-bottom: 10px !important; }

        .panel-card {
            background: #ffffff !important; border: 1px solid #cccccc !important;
            border-radius: 4px !important; box-shadow: none !important;
            break-inside: avoid !important; page-break-inside: avoid !important;
            margin-bottom: 8px !important;
        }
        .panel-head { background: #f0f0f0 !important; border-bottom: 1px solid #cccccc !important; padding: 6px 10px !important; }
        .panel-head h3 { color: #000 !important; font-size: 9pt !important; }
        .panel-head h3 i { color: #333 !important; }
        .panel-head .badge { background: #e5e5e5 !important; color: #333 !important; border: 1px solid #cccccc !important; font-size: 7pt !important; padding: 2px 6px !important; }
        .panel-body { padding: 8px 10px !important; }

        .chart-container { height: 180px !important; min-height: 180px !important; break-inside: avoid !important; page-break-inside: avoid !important; }

        table.rt-table { width: 100% !important; border-collapse: collapse !important; font-size: 8pt !important; color: #000 !important; background: #ffffff !important; }
        table.rt-table th { background: #f0f0f0 !important; color: #000 !important; border: 1px solid #cccccc !important; padding: 4px 6px !important; font-size: 7pt !important; text-align: left !important; }
        table.rt-table td { background: #ffffff !important; color: #000 !important; border: 1px solid #e0e0e0 !important; padding: 4px 6px !important; font-size: 8pt !important; }
        table.rt-table tr:nth-child(even) td { background: #fafafa !important; }

        .badge-rt { background: #e5e5e5 !important; color: #000 !important; border: 1px solid #999 !important; font-size: 7pt !important; padding: 2px 6px !important; }
        .badge-rt.success { background: #d4edda !important; color: #155724 !important; border-color: #c3e6cb !important; }
        .badge-rt.warning { background: #fff3cd !important; color: #856404 !important; border-color: #ffeeba !important; }
        .badge-rt.danger { background: #f8d7da !important; color: #721c24 !important; border-color: #f5c6cb !important; }
        .badge-rt.cyan, .badge-rt.purple, .badge-rt.dark { background: #e5e5e5 !important; color: #333 !important; border-color: #999 !important; }

        .panel-card:last-of-type { break-inside: auto !important; page-break-inside: auto !important; }

        a { color: #000 !important; text-decoration: none !important; }
        * { box-shadow: none !important; text-shadow: none !important; filter: none !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    }
</style>
</head>
<body>

<!-- PRINT-ONLY HEADER -->
<div class="print-title">
    <h1>RENTRACK LGU — Regulatory &amp; Financial Report</h1>
    <p>
        Local Government Unit · Property &amp; Rental Compliance Portal<br>
        Generated on: <?php echo date('F d, Y g:i A'); ?> · By: <?php echo h($userName); ?> (<?php echo h($userRole); ?>)
    </p>
</div>

<div class="top-official-bar">
    <div class="topbar-left">
        <i class="fa-solid fa-landmark"></i>
        <strong>REPUBLIC OF THE PHILIPPINES</strong>
        <span class="divider"></span>
        <span class="portal-title">Local Government Unit Property &amp; Rental Compliance Portal</span>
    </div>
    <div class="topbar-right">
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

        <!-- HORIZONTAL NAV MENU -->
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
                    <button type="button" class="nav-link active" data-dropdown>
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
            <input type="text" id="openSearchModalBtn" placeholder="Search business, tracking no..." readonly>
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

            <div id="notifDropdown" style="display:none; position:absolute; top:46px; right:0; width:300px; background:#fff; border:1px solid var(--border-color); border-radius:14px; padding:12px; box-shadow:0 10px 30px rgba(0,0,0,0.1); z-index:999;">
                <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); padding-bottom:8px; margin-bottom:8px;">
                    <span style="font-weight:700; color:var(--text-main); font-size:12px;">System Notifications</span>
                    <span style="font-size:10px; color:var(--red-accent); cursor:pointer;" id="clearNotifBadge">Mark all read</span>
                </div>
                <div>
                    <?php if (!empty($notifications)): ?>
                        <?php foreach($notifications as $n): ?>
                            <a href="<?php echo h($n['target_url'] ?: 'javascript:void(0)'); ?>" style="display:block; padding:8px 6px; border-bottom:1px solid rgba(0,0,0,0.05); border-radius:6px; text-decoration:none;" onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='transparent'">
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

            <div class="page-header-card">
                <div>
                    <h2><i class="fa-solid fa-chart-simple"></i> Regulatory &amp; Financial Monitoring</h2>
                    <p>Comprehensive oversight ng financial collections, compliance, inspections, at system activity.</p>
                </div>
                <div class="header-actions-group">
                    <div class="header-clock-badge" id="liveDateTime"></div>
                    <button type="button" class="btn-rt-secondary" id="btnPrint">
                        <i class="fa-solid fa-print"></i> Print / PDF
                    </button>
                    <button type="button" class="btn-rt-secondary" id="btnExcel">
                        <i class="fa-solid fa-file-excel" style="color:#059669;"></i> Export Excel
                    </button>
                </div>
            </div>

            <!-- METRICS GRID (8 cards) -->
            <div class="metrics-grid">
                <div class="metric-card emerald">
                    <div class="metric-icon"><i class="fa-solid fa-coins"></i></div>
                    <div class="metric-content">
                        <div class="metric-value" style="font-size:18px;"><?php echo formatCurrency($totalRevenue); ?></div>
                        <div class="metric-label">Total Revenue</div>
                        <div class="metric-sub"><?php echo number_format($totalTransactions); ?> transactions</div>
                    </div>
                </div>

                <div class="metric-card cyan">
                    <div class="metric-icon"><i class="fa-solid fa-calendar-check"></i></div>
                    <div class="metric-content">
                        <div class="metric-value" style="font-size:18px;"><?php echo formatCurrency($monthRevenue); ?></div>
                        <div class="metric-label">This Month</div>
                        <div class="metric-sub"><?php echo date('F Y'); ?></div>
                    </div>
                </div>

                <div class="metric-card amber">
                    <div class="metric-icon"><i class="fa-solid fa-hourglass-half"></i></div>
                    <div class="metric-content">
                        <div class="metric-value" style="font-size:18px;"><?php echo formatCurrency($pendingAmount); ?></div>
                        <div class="metric-label">Pending ₱</div>
                        <div class="metric-sub">Awaiting verification</div>
                    </div>
                </div>

                <div class="metric-card red">
                    <div class="metric-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    <div class="metric-content">
                        <div class="metric-value"><?php echo number_format($overdueInspections); ?></div>
                        <div class="metric-label">Overdue Inspections</div>
                        <div class="metric-sub">&gt;7 days pending</div>
                    </div>
                </div>

                <div class="metric-card violet">
                    <div class="metric-icon"><i class="fa-solid fa-building"></i></div>
                    <div class="metric-content">
                        <div class="metric-value"><?php echo number_format($totalRentals); ?></div>
                        <div class="metric-label">Total Rentals</div>
                        <div class="metric-sub"><?php echo $approvedRentals; ?> approved · <?php echo $pendingRentals; ?> pending</div>
                    </div>
                </div>

                <div class="metric-card emerald">
                    <div class="metric-icon"><i class="fa-solid fa-check-circle"></i></div>
                    <div class="metric-content">
                        <div class="metric-value"><?php echo number_format($compliantRentals); ?></div>
                        <div class="metric-label">Compliant</div>
                        <div class="metric-sub">Compliance rate</div>
                    </div>
                </div>

                <div class="metric-card red">
                    <div class="metric-icon"><i class="fa-solid fa-times-circle"></i></div>
                    <div class="metric-content">
                        <div class="metric-value"><?php echo number_format($nonCompliantRentals); ?></div>
                        <div class="metric-label">Non-Compliant</div>
                        <div class="metric-sub">Requires action</div>
                    </div>
                </div>

                <div class="metric-card slate">
                    <div class="metric-icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
                    <div class="metric-content">
                        <div class="metric-value"><?php echo number_format($totalLogs); ?></div>
                        <div class="metric-label">Total Logs</div>
                        <div class="metric-sub"><?php echo $todayLogs; ?> today · <?php echo $weekLogs; ?> this week</div>
                    </div>
                </div>
            </div>

            <!-- CHARTS ROW 1 -->
            <div class="grid-2col-eq">
                <div class="panel-card">
                    <div class="panel-head">
                        <h3><i class="fa-solid fa-chart-line"></i> Revenue Trend (Last 6 Months)</h3>
                        <span class="badge">Monthly Collection</span>
                    </div>
                    <div class="panel-body">
                        <div class="chart-container">
                            <canvas id="revenueChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="panel-card">
                    <div class="panel-head">
                        <h3><i class="fa-solid fa-clipboard-check"></i> Inspection Status</h3>
                        <span class="badge"><?php echo $totalInspections; ?> Total</span>
                    </div>
                    <div class="panel-body">
                        <div class="chart-container">
                            <canvas id="inspectionChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- CHARTS ROW 2 -->
            <div class="grid-2col-eq">
                <div class="panel-card">
                    <div class="panel-head">
                        <h3><i class="fa-solid fa-file-shield"></i> Compliance Breakdown</h3>
                        <span class="badge">Rentals by Status</span>
                    </div>
                    <div class="panel-body">
                        <div class="chart-container">
                            <canvas id="complianceChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="panel-card">
                    <div class="panel-head">
                        <h3><i class="fa-solid fa-credit-card"></i> Payment Channel Breakdown</h3>
                        <span class="badge">By Method (₱)</span>
                    </div>
                    <div class="panel-body">
                        <div class="chart-container">
                            <canvas id="channelChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- MONITORING TABLES -->
            <div class="grid-2col-eq">
                <div class="panel-card">
                    <div class="panel-head">
                        <h3><i class="fa-solid fa-calendar-xmark" style="color:#d97706;"></i> Permits Expiring (30 Days)</h3>
                        <span class="badge" style="background:rgba(217,119,6,0.1); color:#d97706; border-color:rgba(217,119,6,0.2);"><?php echo count($expiringPermitRows); ?> Alerts</span>
                    </div>
                    <div class="panel-body" style="padding:0;">
                        <?php if (!empty($expiringPermitRows)): ?>
                            <table class="rt-table">
                                <thead>
                                    <tr>
                                        <th>Business</th>
                                        <th>Expiry</th>
                                        <th>Days Left</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($expiringPermitRows as $r): ?>
                                        <tr>
                                            <td data-label="Business"><strong style="color:var(--text-main); font-size:12px;"><?php echo h($r['business_name'] ?: $r['rental_name']); ?></strong></td>
                                            <td data-label="Expiry" style="font-family:monospace; font-size:11px;"><?php echo date('M d, Y', strtotime($r['permit_expiry'])); ?></td>
                                            <td data-label="Days Left"><span class="badge-rt warning"><?php echo $r['days_left']; ?>d</span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div style="text-align:center; padding:40px 20px; color:var(--text-muted); font-size:12px;">
                                <i class="fa-solid fa-check-circle" style="font-size:28px; color:#059669; opacity:0.6; display:block; margin-bottom:8px;"></i>
                                Walang permits na mag-e-expire sa loob ng 30 days.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="panel-card">
                    <div class="panel-head">
                        <h3><i class="fa-solid fa-triangle-exclamation" style="color:#dc2626;"></i> Overdue Inspections (&gt;7 Days)</h3>
                        <span class="badge" style="background:rgba(220,38,38,0.1); color:#dc2626; border-color:rgba(220,38,38,0.2);"><?php echo count($overdueInspectionRows); ?> Overdue</span>
                    </div>
                    <div class="panel-body" style="padding:0;">
                        <?php if (!empty($overdueInspectionRows)): ?>
                            <table class="rt-table">
                                <thead>
                                    <tr>
                                        <th>Inspection #</th>
                                        <th>Business</th>
                                        <th>Days</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($overdueInspectionRows as $r): ?>
                                        <tr>
                                            <td data-label="Inspection #" style="font-family:monospace; font-size:11px;"><?php echo h($r['inspection_no']); ?></td>
                                            <td data-label="Business"><strong style="color:var(--text-main); font-size:12px;"><?php echo h($r['business_name'] ?: $r['rental_name']); ?></strong></td>
                                            <td data-label="Days"><span class="badge-rt danger"><?php echo $r['days_overdue']; ?>d</span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div style="text-align:center; padding:40px 20px; color:var(--text-muted); font-size:12px;">
                                <i class="fa-solid fa-check-circle" style="font-size:28px; color:#059669; opacity:0.6; display:block; margin-bottom:8px;"></i>
                                Walang overdue inspections.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- RECENT ACTIVITY -->
            <div class="grid-2col-eq">
                <div class="panel-card">
                    <div class="panel-head">
                        <h3><i class="fa-solid fa-money-bill-wave" style="color:#059669;"></i> Recent Payments</h3>
                        <span class="badge">Latest Transactions</span>
                    </div>
                    <div class="panel-body" style="padding:0;">
                        <?php if (!empty($recentPayments)): ?>
                            <table class="rt-table">
                                <thead>
                                    <tr>
                                        <th>OR #</th>
                                        <th>Business</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentPayments as $r): 
                                        $st = strtolower($r['status'] ?? '');
                                        $badgeCls = $st === 'verified' || $st === 'paid' ? 'success' : ($st === 'rejected' ? 'danger' : 'warning');
                                    ?>
                                        <tr>
                                            <td data-label="OR #" style="font-family:monospace; font-size:11px;"><?php echo h($r['or_number'] ?: 'N/A'); ?></td>
                                            <td data-label="Business"><strong style="color:var(--text-main); font-size:12px;"><?php echo h($r['business_name'] ?: $r['rental_name']); ?></strong></td>
                                            <td data-label="Amount" style="color:#059669; font-weight:700;"><?php echo formatCurrency($r['amount_paid']); ?></td>
                                            <td data-label="Status"><span class="badge-rt <?php echo $badgeCls; ?>"><?php echo h($r['status']); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div style="text-align:center; padding:40px 20px; color:var(--text-muted); font-size:12px;">
                                <i class="fa-solid fa-receipt" style="font-size:24px; opacity:0.4; display:block; margin-bottom:8px;"></i>
                                Walang recent payments.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="panel-card">
                    <div class="panel-head">
                        <h3><i class="fa-solid fa-clipboard-check" style="color:#0891b2;"></i> Recent Inspections</h3>
                        <span class="badge">Latest Activities</span>
                    </div>
                    <div class="panel-body" style="padding:0;">
                        <?php if (!empty($recentInspections)): ?>
                            <table class="rt-table">
                                <thead>
                                    <tr>
                                        <th>Inspection #</th>
                                        <th>Business</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentInspections as $r): 
                                        $st = strtolower($r['inspection_status'] ?? '');
                                        $badgeCls = ($st === 'passed' || $st === 'completed') ? 'success' : ($st === 'failed' ? 'danger' : ($st === 'scheduled' ? 'purple' : 'warning'));
                                    ?>
                                        <tr>
                                            <td data-label="Inspection #" style="font-family:monospace; font-size:11px;"><?php echo h($r['inspection_no']); ?></td>
                                            <td data-label="Business"><strong style="color:var(--text-main); font-size:12px;"><?php echo h($r['business_name'] ?: $r['rental_name']); ?></strong></td>
                                            <td data-label="Status"><span class="badge-rt <?php echo $badgeCls; ?>"><?php echo h($r['inspection_status']); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div style="text-align:center; padding:40px 20px; color:var(--text-muted); font-size:12px;">
                                <i class="fa-solid fa-clipboard" style="font-size:24px; opacity:0.4; display:block; margin-bottom:8px;"></i>
                                Walang recent inspections.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- FILTER BAR -->
            <div class="filter-bar-rt">
                <form method="GET">
                    <input type="date" name="date_from" value="<?php echo h($dateFrom); ?>" placeholder="From">
                    <input type="date" name="date_to" value="<?php echo h($dateTo); ?>" placeholder="To">

                    <select name="barangay">
                        <option value="">All Barangays</option>
                        <?php foreach ($barangayList as $brgy): ?>
                            <option value="<?php echo h($brgy); ?>" <?php echo $barangayFilter === $brgy ? 'selected' : ''; ?>><?php echo h($brgy); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select name="rental_status">
                        <option value="">All Status</option>
                        <option value="Pending" <?php echo $statusFilter === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="Approved" <?php echo $statusFilter === 'Approved' ? 'selected' : ''; ?>>Approved</option>
                    </select>

                    <select name="compliance">
                        <option value="">All Compliance</option>
                        <option value="Pending" <?php echo $complianceFilter === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="Compliant" <?php echo $complianceFilter === 'Compliant' ? 'selected' : ''; ?>>Compliant</option>
                        <option value="Non-Compliant" <?php echo $complianceFilter === 'Non-Compliant' ? 'selected' : ''; ?>>Non-Compliant</option>
                    </select>

                    <button type="submit" class="btn-filter-rt"><i class="fa-solid fa-filter me-1"></i> Filter</button>

                    <?php if ($dateFrom || $dateTo || $barangayFilter || $statusFilter || $complianceFilter): ?>
                        <a href="reports.php" class="btn-rt-secondary"><i class="fa-solid fa-times me-1"></i> Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- FULL REGULATORY REPORT TABLE -->
            <div class="panel-card" style="margin-bottom: 20px;">
                <div class="panel-head">
                    <div>
                        <h3><i class="fa-solid fa-file-invoice"></i> Full Regulatory Report</h3>
                        <p style="font-size: 11px; color: var(--text-muted); margin: 2px 0 0;">
                            <?php echo count($rentalReport); ?> record(s) shown (max 100)
                        </p>
                    </div>
                </div>
                <div style="overflow-x:auto;">
                    <table class="rt-table">
                        <thead>
                            <tr>
                                <th>Tracking #</th>
                                <th>Business</th>
                                <th>Owner</th>
                                <th>Barangay</th>
                                <th>Status</th>
                                <th>Compliance</th>
                                <th>Permit</th>
                                <th>Total Paid</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($rentalReport)): ?>
                                <?php foreach ($rentalReport as $r): 
                                    $st = strtolower($r['status'] ?? '');
                                    $stCls = $st === 'approved' ? 'success' : ($st === 'pending' ? 'warning' : 'danger');
                                    
                                    $cmp = strtolower($r['compliance_status'] ?? '');
                                    $cmpCls = $cmp === 'compliant' ? 'success' : ($cmp === 'non-compliant' ? 'danger' : 'warning');
                                ?>
                                    <tr>
                                        <td data-label="Tracking #" style="font-family:'IBM Plex Mono',monospace; color:#dc2626; font-size:11px;">
                                            <?php echo h($r['tracking_no'] ?: 'N/A'); ?>
                                        </td>
                                        <td data-label="Business"><strong style="color:var(--text-main);"><?php echo h($r['business_name'] ?: $r['rental_name'] ?: '—'); ?></strong></td>
                                        <td data-label="Owner" style="font-size:11.5px;"><?php echo h($r['owner_name'] ?: '—'); ?></td>
                                        <td data-label="Barangay" style="font-size:11.5px;"><?php echo h($r['barangay'] ?: '—'); ?></td>
                                        <td data-label="Status"><span class="badge-rt <?php echo $stCls; ?>"><?php echo h($r['status']); ?></span></td>
                                        <td data-label="Compliance"><span class="badge-rt <?php echo $cmpCls; ?>"><?php echo h($r['compliance_status']); ?></span></td>
                                        <td data-label="Permit">
                                            <?php if (!empty($r['permit_expiry'])): ?>
                                                <span style="font-size:11px;"><?php echo date('M d, Y', strtotime($r['permit_expiry'])); ?></span>
                                                <br><small style="color:var(--text-muted); font-size:10px;"><?php echo h($r['permit_status'] ?: ''); ?></small>
                                            <?php else: ?>
                                                <span style="color:var(--text-muted);">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Total Paid" style="color:#059669; font-weight:700;"><?php echo formatCurrency($r['total_paid']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-5">
                                        <i class="fa-solid fa-folder-open fa-2x mb-2" style="opacity:0.4;"></i><br>
                                        No rental records found matching your filters.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <footer class="footer">
                <span>&copy; <?php echo date('Y'); ?> <strong style="color:var(--red-accent);">RENTRACK LGU</strong> &middot; Admin Portal</span>
                <span>Regulatory &amp; Financial Monitoring System</span>
            </footer>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
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
        fetch('reports.php?action=mark_notif_read');
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
                        <input type="text" id="swalSearchInput" placeholder="Search business, tracking no..." autocomplete="off" style="width: 100%; padding: 12px 16px 12px 42px; background: #f8fafc; color: #0f172a; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 13px; outline: none;">
                    </div>
                    <div id="swalSearchResults" style="min-height: 120px; max-height: 280px; overflow-y: auto; color: #64748b; display: flex; flex-direction: column; justify-content: center; align-items: center;">
                        <i class="fa-solid fa-keyboard" style="font-size: 28px; margin-bottom: 8px; color: #cbd5e1;"></i>
                        <span style="font-size: 12px;">Type at least 2 characters...</span>
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
                        fetch(`reports.php?action=global_search&q=${encodeURIComponent(query)}`)
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

    /* ============ CHART.JS DEFAULTS ============ */
    Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
    Chart.defaults.color = '#64748b';
    const tooltipStyle = {
        backgroundColor: '#ffffff',
        borderColor: '#e2e8f0',
        borderWidth: 1,
        padding: 12,
        titleColor: '#0f172a',
        bodyColor: '#334155',
        cornerRadius: 8
    };

    /* ============ CHART 1: REVENUE TREND ============ */
    const revenueLabels = <?php echo json_encode($revenueLabels); ?>;
    const revenueValues = <?php echo json_encode($revenueValues); ?>;
    const revenueCtx = document.getElementById('revenueChart');
    if (revenueCtx && revenueLabels.length > 0) {
        new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: revenueLabels,
                datasets: [{
                    label: 'Revenue (₱)',
                    data: revenueValues,
                    borderColor: '#059669',
                    backgroundColor: 'rgba(5, 150, 105, 0.15)',
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointBackgroundColor: '#059669',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { ...tooltipStyle, callbacks: { label: (ctx) => '₱' + ctx.parsed.y.toLocaleString('en-US', {minimumFractionDigits: 2}) } }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: '#64748b', font: { size: 10 } } },
                    y: { beginAtZero: true, grid: { color: 'rgba(0, 0, 0, 0.05)' }, ticks: { color: '#64748b', font: { size: 10 }, callback: (v) => '₱' + v.toLocaleString() } }
                }
            }
        });
    } else if (revenueCtx) {
        revenueCtx.parentElement.innerHTML = '<div style="display:flex; align-items:center; justify-content:center; height:100%; color:#64748b; font-size:12px;">No revenue data yet</div>';
    }

    /* ============ CHART 2: INSPECTION STATUS ============ */
    const inspStatusLabels = <?php echo json_encode($inspStatusLabels); ?>;
    const inspStatusValues = <?php echo json_encode($inspStatusValues); ?>;
    const inspStatusColors = inspStatusLabels.map(s => {
        const l = String(s).toLowerCase();
        if (l === 'passed' || l === 'completed') return '#059669';
        if (l === 'failed') return '#dc2626';
        if (l === 'scheduled') return '#7c3aed';
        return '#d97706';
    });
    const inspCtx = document.getElementById('inspectionChart');
    if (inspCtx && inspStatusLabels.length > 0) {
        new Chart(inspCtx, {
            type: 'doughnut',
            data: {
                labels: inspStatusLabels,
                datasets: [{ data: inspStatusValues, backgroundColor: inspStatusColors, borderColor: '#ffffff', borderWidth: 3 }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '65%',
                plugins: {
                    legend: { position: 'bottom', labels: { color: '#334155', font: { size: 11 }, padding: 12, usePointStyle: true, pointStyle: 'circle' } },
                    tooltip: tooltipStyle
                }
            }
        });
    } else if (inspCtx) {
        inspCtx.parentElement.innerHTML = '<div style="display:flex; align-items:center; justify-content:center; height:100%; color:#64748b; font-size:12px;">No inspection data yet</div>';
    }

    /* ============ CHART 3: COMPLIANCE BREAKDOWN ============ */
    const compLabels = <?php echo json_encode($complianceLabels); ?>;
    const compValues = <?php echo json_encode($complianceValues); ?>;
    const compColors = compLabels.map(c => {
        const l = String(c).toLowerCase();
        if (l === 'compliant') return '#059669';
        if (l === 'non-compliant') return '#dc2626';
        return '#d97706';
    });
    const compCtx = document.getElementById('complianceChart');
    if (compCtx && compLabels.length > 0) {
        new Chart(compCtx, {
            type: 'bar',
            data: {
                labels: compLabels,
                datasets: [{
                    label: 'Rentals',
                    data: compValues,
                    backgroundColor: compColors,
                    borderRadius: 8,
                    maxBarThickness: 60
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: tooltipStyle },
                scales: {
                    x: { grid: { display: false }, ticks: { color: '#334155', font: { size: 11 } } },
                    y: { beginAtZero: true, grid: { color: 'rgba(0, 0, 0, 0.05)' }, ticks: { color: '#64748b', font: { size: 10 }, stepSize: 1 } }
                }
            }
        });
    } else if (compCtx) {
        compCtx.parentElement.innerHTML = '<div style="display:flex; align-items:center; justify-content:center; height:100%; color:#64748b; font-size:12px;">No compliance data yet</div>';
    }

    /* ============ CHART 4: PAYMENT CHANNEL ============ */
    const chLabels = <?php echo json_encode($channelLabels); ?>;
    const chValues = <?php echo json_encode($channelValues); ?>;
    const chColors = chLabels.map(m => {
        const l = String(m).toLowerCase();
        if (l === 'cash') return '#059669';
        if (l === 'gcash') return '#0891b2';
        if (l === 'maya' || l === 'paymaya') return '#d97706';
        if (l === 'bank' || l === 'bank transfer') return '#7c3aed';
        return '#64748b';
    });
    const chCtx = document.getElementById('channelChart');
    if (chCtx && chLabels.length > 0) {
        new Chart(chCtx, {
            type: 'doughnut',
            data: {
                labels: chLabels,
                datasets: [{ data: chValues, backgroundColor: chColors, borderColor: '#ffffff', borderWidth: 3 }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '65%',
                plugins: {
                    legend: { position: 'bottom', labels: { color: '#334155', font: { size: 11 }, padding: 12, usePointStyle: true, pointStyle: 'circle' } },
                    tooltip: { ...tooltipStyle, callbacks: { label: (ctx) => ctx.label + ': ₱' + ctx.parsed.toLocaleString('en-US', {minimumFractionDigits: 2}) } }
                }
            }
        });
    } else if (chCtx) {
        chCtx.parentElement.innerHTML = '<div style="display:flex; align-items:center; justify-content:center; height:100%; color:#64748b; font-size:12px;">No payment data yet</div>';
    }

    /* ============ PRINT ============ */
    document.getElementById('btnPrint')?.addEventListener('click', () => window.print());

    /* ============ EXPORT EXCEL ============ */
    document.getElementById('btnExcel')?.addEventListener('click', () => {
        const wb = XLSX.utils.book_new();

        const summaryData = [
            ['RENTRACK LGU — Regulatory & Financial Report'],
            ['Generated on', new Date().toLocaleString('en-US', { timeZone: 'Asia/Manila' })],
            [],
            ['Metric', 'Value'],
            ['Total Revenue', '₱' + parseFloat(<?php echo (float)$totalRevenue; ?>).toLocaleString('en-US', {minimumFractionDigits: 2})],
            ['This Month', '₱' + parseFloat(<?php echo (float)$monthRevenue; ?>).toLocaleString('en-US', {minimumFractionDigits: 2})],
            ['Pending Amount', '₱' + parseFloat(<?php echo (float)$pendingAmount; ?>).toLocaleString('en-US', {minimumFractionDigits: 2})],
            ['Total Transactions', <?php echo (int)$totalTransactions; ?>],
            [],
            ['Total Rentals', <?php echo (int)$totalRentals; ?>],
            ['Approved Rentals', <?php echo (int)$approvedRentals; ?>],
            ['Pending Rentals', <?php echo (int)$pendingRentals; ?>],
            ['Compliant', <?php echo (int)$compliantRentals; ?>],
            ['Non-Compliant', <?php echo (int)$nonCompliantRentals; ?>],
            [],
            ['Total Inspections', <?php echo (int)$totalInspections; ?>],
            ['Passed', <?php echo (int)$passedInspections; ?>],
            ['Failed', <?php echo (int)$failedInspections; ?>],
            ['Overdue', <?php echo (int)$overdueInspections; ?>],
            [],
            ['Activity Logs', <?php echo (int)$totalLogs; ?>]
        ];
        const ws1 = XLSX.utils.aoa_to_sheet(summaryData);
        ws1['!cols'] = [{ wch: 25 }, { wch: 30 }];
        XLSX.utils.book_append_sheet(wb, ws1, 'Summary');

        const rentalRows = [['Tracking #', 'Business', 'Owner', 'Barangay', 'Status', 'Compliance', 'Permit Expiry', 'Total Paid']];
        <?php foreach ($rentalReport as $r): ?>
            rentalRows.push([
                <?php echo json_encode($r['tracking_no'] ?? ''); ?>,
                <?php echo json_encode($r['business_name'] ?? $r['rental_name'] ?? ''); ?>,
                <?php echo json_encode($r['owner_name'] ?? ''); ?>,
                <?php echo json_encode($r['barangay'] ?? ''); ?>,
                <?php echo json_encode($r['status'] ?? ''); ?>,
                <?php echo json_encode($r['compliance_status'] ?? ''); ?>,
                <?php echo json_encode($r['permit_expiry'] ?? ''); ?>,
                <?php echo (float)$r['total_paid']; ?>
            ]);
        <?php endforeach; ?>
        const ws2 = XLSX.utils.aoa_to_sheet(rentalRows);
        ws2['!cols'] = [{ wch: 15 }, { wch: 28 }, { wch: 22 }, { wch: 16 }, { wch: 12 }, { wch: 14 }, { wch: 14 }, { wch: 14 }];
        XLSX.utils.book_append_sheet(wb, ws2, 'Rentals Report');

        const payRows = [['OR #', 'Business', 'Amount', 'Method', 'Date', 'Status']];
        <?php foreach ($recentPayments as $r): ?>
            payRows.push([
                <?php echo json_encode($r['or_number'] ?? ''); ?>,
                <?php echo json_encode($r['business_name'] ?? $r['rental_name'] ?? ''); ?>,
                <?php echo (float)$r['amount_paid']; ?>,
                <?php echo json_encode($r['method'] ?? ''); ?>,
                <?php echo json_encode($r['payment_date'] ?? ''); ?>,
                <?php echo json_encode($r['status'] ?? ''); ?>
            ]);
        <?php endforeach; ?>
        const ws3 = XLSX.utils.aoa_to_sheet(payRows);
        ws3['!cols'] = [{ wch: 15 }, { wch: 28 }, { wch: 14 }, { wch: 12 }, { wch: 14 }, { wch: 12 }];
        XLSX.utils.book_append_sheet(wb, ws3, 'Recent Payments');

        const inspRows = [['Inspection #', 'Business', 'Inspector', 'Date', 'Status']];
        <?php foreach ($recentInspections as $r): ?>
            inspRows.push([
                <?php echo json_encode($r['inspection_no'] ?? ''); ?>,
                <?php echo json_encode($r['business_name'] ?? $r['rental_name'] ?? ''); ?>,
                <?php echo json_encode($r['inspector_name'] ?? ''); ?>,
                <?php echo json_encode($r['inspection_date'] ?? ''); ?>,
                <?php echo json_encode($r['inspection_status'] ?? ''); ?>
            ]);
        <?php endforeach; ?>
        const ws4 = XLSX.utils.aoa_to_sheet(inspRows);
        ws4['!cols'] = [{ wch: 16 }, { wch: 28 }, { wch: 22 }, { wch: 14 }, { wch: 14 }];
        XLSX.utils.book_append_sheet(wb, ws4, 'Recent Inspections');

        const expRows = [['Business', 'Tracking #', 'Permit Expiry', 'Days Left']];
        <?php foreach ($expiringPermitRows as $r): ?>
            expRows.push([
                <?php echo json_encode($r['business_name'] ?? $r['rental_name'] ?? ''); ?>,
                <?php echo json_encode($r['tracking_no'] ?? ''); ?>,
                <?php echo json_encode($r['permit_expiry'] ?? ''); ?>,
                <?php echo (int)$r['days_left']; ?>
            ]);
        <?php endforeach; ?>
        const ws5 = XLSX.utils.aoa_to_sheet(expRows);
        ws5['!cols'] = [{ wch: 28 }, { wch: 16 }, { wch: 14 }, { wch: 12 }];
        XLSX.utils.book_append_sheet(wb, ws5, 'Expiring Permits');

        const ovdRows = [['Inspection #', 'Business', 'Inspection Date', 'Days Overdue']];
        <?php foreach ($overdueInspectionRows as $r): ?>
            ovdRows.push([
                <?php echo json_encode($r['inspection_no'] ?? ''); ?>,
                <?php echo json_encode($r['business_name'] ?? $r['rental_name'] ?? ''); ?>,
                <?php echo json_encode($r['inspection_date'] ?? ''); ?>,
                <?php echo (int)$r['days_overdue']; ?>
            ]);
        <?php endforeach; ?>
        const ws6 = XLSX.utils.aoa_to_sheet(ovdRows);
        ws6['!cols'] = [{ wch: 16 }, { wch: 28 }, { wch: 14 }, { wch: 14 }];
        XLSX.utils.book_append_sheet(wb, ws6, 'Overdue Inspections');

        const filename = 'RENTRACK-Regulatory-Report-' + new Date().toISOString().slice(0, 10) + '.xlsx';
        XLSX.writeFile(wb, filename);
    });
</script>
</body>
</html>