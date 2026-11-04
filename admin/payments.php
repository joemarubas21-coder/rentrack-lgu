<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../login.php");
    exit();
}

require_once("../config/db.php");

$adminId = (int) $_SESSION['user_id'];
$adminName = $_SESSION['fullname'] ?? ($_SESSION['user_name'] ?? 'Administrator');
$userRole = $_SESSION['role'] ?? ($_SESSION['user_role'] ?? 'Administrator');
$adminInitial = strtoupper(substr(trim($adminName), 0, 1));

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function formatCurrency($amount) {
    return '₱' . number_format((float)$amount, 2);
}

/* =====================================================
   HELPER FUNCTIONS — POSTGRESQL VERSION
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
   AJAX HANDLERS
===================================================== */
if (isset($_GET['action']) && $_GET['action'] === 'mark_notif_read') {
    if (tableExists($conn, 'notifications')) {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = :user_id");
        $stmt->execute([':user_id' => $adminId]);
    }
    echo json_encode(['status' => 'success']);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'global_search') {
    $q = trim($_GET['q'] ?? '');
    $results = [];
    if (strlen($q) >= 1) {
        $searchTerm = '%' . $q . '%';
        if (tableExists($conn, 'payments')) {
            $stmt = $conn->prepare("
                SELECT p.id, p.or_number, p.amount_paid, p.status::text as status, r.business_name 
                FROM payments p 
                LEFT JOIN rentals r ON p.rental_id = r.id 
                WHERE p.or_number ILIKE :q OR r.business_name ILIKE :q 
                LIMIT 5
            ");
            $stmt->execute([':q' => $searchTerm]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = [
                    'type' => 'Payment Transaction',
                    'title' => 'OR #: ' . ($row['or_number'] ?: 'N/A') . ' (' . formatCurrency($row['amount_paid']) . ')',
                    'sub' => 'Business: ' . ($row['business_name'] ?: 'N/A') . ' | Status: ' . ucfirst($row['status'] ?? ''),
                    'link' => 'payments.php?search=' . urlencode($row['or_number'] ?? '')
                ];
            }
        }
    }
    echo json_encode($results);
    exit;
}

/* =====================================================
   FILTERS
===================================================== */
$filterStatus = isset($_GET['status']) ? trim($_GET['status']) : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

/* =====================================================
   DASHBOARD METRICS
===================================================== */
$pendingPayments  = 0;
$verifiedPayments = 0;
$rejectedPayments = 0;
$totalPayments    = 0;
$totalAmount      = 0;
$pendingAmount    = 0;
$todayAmount      = 0;
$todayCount       = 0;
$weekAmount       = 0;
$monthAmount      = 0;
$verifiedTodayCount = 0;

if (tableExists($conn, 'payments')) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM payments WHERE status::text = 'Pending'");
    $stmt->execute();
    $pendingPayments = (int) $stmt->fetchColumn();

    $stmt = $conn->prepare("SELECT COUNT(*) FROM payments WHERE status::text IN ('Paid', 'Verified')");
    $stmt->execute();
    $verifiedPayments = (int) $stmt->fetchColumn();

    $stmt = $conn->prepare("SELECT COUNT(*) FROM payments WHERE status::text IN ('Rejected', 'Failed', 'Cancelled')");
    $stmt->execute();
    $rejectedPayments = (int) $stmt->fetchColumn();

    $stmt = $conn->prepare("SELECT COUNT(*) FROM payments");
    $stmt->execute();
    $totalPayments = (int) $stmt->fetchColumn();

    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE status::text IN ('Paid', 'Verified')");
    $stmt->execute();
    $totalAmount = (float) $stmt->fetchColumn();

    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE status::text = 'Pending'");
    $stmt->execute();
    $pendingAmount = (float) $stmt->fetchColumn();

    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount_paid), 0), COUNT(*) FROM payments WHERE status::text IN ('Paid', 'Verified') AND DATE(created_at) = CURRENT_DATE");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_NUM);
    $todayAmount = (float) ($row[0] ?? 0);
    $todayCount = (int) ($row[1] ?? 0);

    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE status::text IN ('Paid', 'Verified') AND DATE_TRUNC('week', created_at) = DATE_TRUNC('week', CURRENT_DATE)");
    $stmt->execute();
    $weekAmount = (float) $stmt->fetchColumn();

    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE status::text IN ('Paid', 'Verified') AND DATE_TRUNC('month', created_at) = DATE_TRUNC('month', CURRENT_DATE)");
    $stmt->execute();
    $monthAmount = (float) $stmt->fetchColumn();

    $stmt = $conn->prepare("SELECT COUNT(*) FROM payments WHERE status::text IN ('Paid', 'Verified') AND DATE(created_at) = CURRENT_DATE");
    $stmt->execute();
    $verifiedTodayCount = (int) $stmt->fetchColumn();
}

/* =====================================================
   REVENUE TREND
===================================================== */
$revenueTrend = [];
$revenueLabels = [];
$revenueValues = [];

if (tableExists($conn, 'payments')) {
    try {
        $stmt = $conn->prepare("
            SELECT DATE(created_at) as pay_date, COALESCE(SUM(amount_paid), 0) as total
            FROM payments
            WHERE status::text IN ('Paid', 'Verified') 
              AND created_at >= CURRENT_DATE - INTERVAL '30 days'
            GROUP BY DATE(created_at)
            ORDER BY pay_date ASC
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $revenueLabels[] = date('M d', strtotime($r['pay_date']));
            $revenueValues[] = (float) $r['total'];
        }
    } catch (PDOException $e) { error_log("Revenue trend error: " . $e->getMessage()); }
}

/* =====================================================
   PAYMENT CHANNEL BREAKDOWN
===================================================== */
$channelData = [];
if (tableExists($conn, 'payments')) {
    try {
        $stmt = $conn->prepare("
            SELECT COALESCE(payment_method::text, 'Unknown') as method, 
                   COUNT(*) as count, 
                   COALESCE(SUM(amount_paid), 0) as total
            FROM payments
            WHERE status::text IN ('Paid', 'Verified')
            GROUP BY payment_method
            ORDER BY total DESC
        ");
        $stmt->execute();
        $channelData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { error_log("Channel breakdown error: " . $e->getMessage()); }
}

/* =====================================================
   PAYMENT PURPOSE BREAKDOWN
===================================================== */
$purposeData = [];
if (tableExists($conn, 'payments') && columnExists($conn, 'payments', 'payment_purpose')) {
    try {
        $stmt = $conn->prepare("
            SELECT COALESCE(payment_purpose, 'Others') as purpose, 
                   COUNT(*) as count, 
                   COALESCE(SUM(amount_paid), 0) as total
            FROM payments
            WHERE status::text IN ('Paid', 'Verified')
            GROUP BY payment_purpose
            ORDER BY total DESC
            LIMIT 6
        ");
        $stmt->execute();
        $purposeData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { error_log("Purpose breakdown error: " . $e->getMessage()); }
}

/* =====================================================
   TOP PAYORS
===================================================== */
$topPayors = [];
if (tableExists($conn, 'payments') && tableExists($conn, 'rentals') && tableExists($conn, 'users')) {
    try {
        $stmt = $conn->prepare("
            SELECT u.fullname as owner_name, r.business_name, r.tracking_no,
                   COUNT(p.id) as payment_count,
                   COALESCE(SUM(p.amount_paid), 0) as total_paid
            FROM payments p
            INNER JOIN rentals r ON p.rental_id = r.id
            INNER JOIN users u ON r.user_id = u.id
            WHERE p.status::text IN ('Paid', 'Verified')
            GROUP BY u.fullname, r.business_name, r.tracking_no
            ORDER BY total_paid DESC
            LIMIT 10
        ");
        $stmt->execute();
        $topPayors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { error_log("Top payors error: " . $e->getMessage()); }
}

/* =====================================================
   PENDING >7 DAYS
===================================================== */
$pendingAging = [];
if (tableExists($conn, 'payments') && tableExists($conn, 'rentals') && tableExists($conn, 'users')) {
    try {
        $stmt = $conn->prepare("
            SELECT p.id, p.or_number, p.amount_paid, p.created_at, 
                   r.business_name, u.fullname as owner_name,
                   (CURRENT_DATE - DATE(p.created_at)) as days_pending
            FROM payments p
            INNER JOIN rentals r ON p.rental_id = r.id
            INNER JOIN users u ON r.user_id = u.id
            WHERE p.status::text = 'Pending'
              AND (CURRENT_DATE - DATE(p.created_at)) > 7
            ORDER BY days_pending DESC
            LIMIT 10
        ");
        $stmt->execute();
        $pendingAging = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { error_log("Pending aging error: " . $e->getMessage()); }
}

/* =====================================================
   HIGH-VALUE TRANSACTIONS
===================================================== */
$highValueTransactions = [];
if (tableExists($conn, 'payments') && tableExists($conn, 'rentals') && tableExists($conn, 'users')) {
    try {
        $stmt = $conn->prepare("
            SELECT p.or_number, p.amount_paid, p.created_at, p.status::text as status,
                   r.business_name, u.fullname as owner_name
            FROM payments p
            INNER JOIN rentals r ON p.rental_id = r.id
            INNER JOIN users u ON r.user_id = u.id
            WHERE p.amount_paid >= 5000
              AND p.status::text IN ('Paid', 'Verified')
            ORDER BY p.created_at DESC
            LIMIT 8
        ");
        $stmt->execute();
        $highValueTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { error_log("High value error: " . $e->getMessage()); }
}

/* =====================================================
   RECENT VERIFICATIONS
===================================================== */
$recentVerifications = [];
if (tableExists($conn, 'payments') && tableExists($conn, 'rentals') && tableExists($conn, 'users')) {
    try {
        $stmt = $conn->prepare("
            SELECT p.or_number, p.amount_paid, p.status::text as status, p.created_at,
                   r.business_name
            FROM payments p
            INNER JOIN rentals r ON p.rental_id = r.id
            WHERE p.status::text IN ('Paid', 'Verified', 'Rejected')
            ORDER BY p.created_at DESC
            LIMIT 6
        ");
        $stmt->execute();
        $recentVerifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { error_log("Recent verifications error: " . $e->getMessage()); }
}

/* =====================================================
   FILTERED PAYMENTS TABLE
===================================================== */
$whereClause = ["1=1"];
$params = [];

if ($filterStatus !== 'all' && !empty($filterStatus)) {
    $whereClause[] = "p.status::text = :status";
    $params[':status'] = $filterStatus;
}

if (!empty($search)) {
    $whereClause[] = "(r.business_name ILIKE :search OR r.tracking_no ILIKE :search OR p.or_number ILIKE :search OR u.fullname ILIKE :search)";
    $params[':search'] = "%$search%";
}

if (!empty($dateFrom)) {
    $whereClause[] = "DATE(p.created_at) >= :date_from";
    $params[':date_from'] = $dateFrom;
}

if (!empty($dateTo)) {
    $whereClause[] = "DATE(p.created_at) <= :date_to";
    $params[':date_to'] = $dateTo;
}

$whereSql = implode(' AND ', $whereClause);

$payments = [];
if (tableExists($conn, 'payments') && tableExists($conn, 'rentals') && tableExists($conn, 'users')) {
    $sql = "
        SELECT p.*, p.status::text as status,
               r.business_name, r.tracking_no, 
               u.fullname AS owner_name, u.email AS owner_email
        FROM payments p
        INNER JOIN rentals r ON p.rental_id = r.id
        INNER JOIN users u ON r.user_id = u.id
        WHERE $whereSql
        ORDER BY p.created_at DESC
        LIMIT 100
    ";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { error_log("Filtered payments error: " . $e->getMessage()); }
}

/* =====================================================
   NOTIFICATIONS
===================================================== */
$unreadCount = 0;
if (tableExists($conn, 'notifications')) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = FALSE");
    $stmt->execute([':user_id' => $adminId]);
    $unreadCount = (int) $stmt->fetchColumn();
}

$notifications = [];
if (tableExists($conn, 'notifications')) {
    $hasTargetUrl = columnExists($conn, 'notifications', 'target_url');
    $selectFields = $hasTargetUrl ? "id, title, message, target_url, created_at" : "id, title, message, created_at";
    $stmt = $conn->prepare("SELECT $selectFields FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([':user_id' => $adminId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!$hasTargetUrl) $row['target_url'] = 'javascript:void(0)';
        $notifications[] = $row;
    }
}

function getStatusBadge($status) {
    $status = strtolower(trim($status));
    switch ($status) {
        case 'pending':
            return '<span class="badge-rt warning"><i class="fa-solid fa-clock"></i> Pending</span>';
        case 'verified':
        case 'paid':
            return '<span class="badge-rt success"><i class="fa-solid fa-check-circle"></i> Verified</span>';
        case 'rejected':
        case 'failed':
        case 'cancelled':
            return '<span class="badge-rt danger"><i class="fa-solid fa-times-circle"></i> Rejected</span>';
        default:
            return '<span class="badge-rt dark">' . h(ucfirst($status)) . '</span>';
    }
}

function getPaymentMethodBadge($method) {
    $method = strtolower(trim($method));
    switch ($method) {
        case 'cash':
            return '<span class="badge-rt success"><i class="fa-solid fa-money-bill"></i> Cash</span>';
        case 'gcash':
            return '<span class="badge-rt cyan"><i class="fa-solid fa-wallet"></i> GCash</span>';
        case 'maya':
        case 'paymaya':
            return '<span class="badge-rt success"><i class="fa-solid fa-wallet"></i> Maya</span>';
        case 'bank':
        case 'bank transfer':
            return '<span class="badge-rt purple"><i class="fa-solid fa-building-columns"></i> Bank</span>';
        case 'card':
            return '<span class="badge-rt purple"><i class="fa-solid fa-credit-card"></i> Card</span>';
        case 'online':
            return '<span class="badge-rt cyan"><i class="fa-solid fa-wifi"></i> Online</span>';
        default:
            return '<span class="badge-rt dark">' . h(ucfirst($method ?: 'N/A')) . '</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#dc2626">
    <title>Payments Oversight | RENTRACK LGU Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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

        .nav-link .menu-badge {
            background: #fbbf24; color: #7c2d12; font-size: 10px; font-weight: 700;
            padding: 1px 7px; border-radius: 999px;
        }

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
            transition: var(--transition); display: inline-flex; align-items: center; gap: 8px;
        }
        .btn-rt-secondary:hover { background: var(--bg-card-alt); color: var(--text-main); border-color: var(--border-strong); }

        /* FINANCIAL SCORECARD */
        .financial-scorecard {
            background: #ffffff; border: 1px solid var(--border-color);
            border-radius: 18px; padding: 24px 28px; margin-bottom: 20px;
            display: grid; grid-template-columns: 250px 1fr; gap: 28px; align-items: center;
            position: relative; overflow: hidden;
            box-shadow: var(--shadow-soft);
        }
        .financial-scorecard::before {
            content: ''; position: absolute; top: -50%; right: -10%;
            width: 300px; height: 300px; border-radius: 50%;
            background: radial-gradient(circle, rgba(220, 38, 38, 0.06), transparent 70%);
            pointer-events: none;
        }
        .financial-score-value { text-align: center; position: relative; z-index: 1; }
        .financial-score-label {
            font-size: 11px; font-weight: 800; text-transform: uppercase;
            letter-spacing: 1px; color: var(--text-muted); margin-bottom: 8px; display: block;
        }
        .financial-score-number {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 38px; font-weight: 800; line-height: 1;
            color: #059669; margin-bottom: 6px; word-break: break-word;
        }
        .financial-score-sub { font-size: 11.5px; color: var(--text-sub); font-weight: 600; }
        .financial-breakdown { position: relative; z-index: 1; }
        .financial-breakdown-title {
            font-size: 11px; font-weight: 700; color: var(--text-muted);
            text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px;
        }
        .financial-breakdown-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
        .financial-breakdown-item {
            background: #f8fafc; border: 1px solid var(--border-color);
            border-radius: 12px; padding: 14px 16px;
        }
        .financial-breakdown-item .lbl {
            font-size: 10px; font-weight: 700; color: var(--text-muted);
            text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 6px;
        }
        .financial-breakdown-item .val {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 18px; font-weight: 800; color: var(--text-main); line-height: 1.1; word-break: break-word;
        }
        .financial-breakdown-item .val.green { color: #059669; }
        .financial-breakdown-item .val.amber { color: #d97706; }
        .financial-breakdown-item .val.cyan { color: #0891b2; }
        .financial-breakdown-item .sub { font-size: 10.5px; color: var(--text-muted); margin-top: 2px; }

        /* METRICS */
        .metrics-grid {
            display: grid; grid-template-columns: repeat(4, 1fr);
            gap: 14px; margin-bottom: 20px;
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
        .metric-icon {
            width: 46px; height: 46px; border-radius: 12px;
            background: rgba(255,255,255,0.2); display: flex;
            align-items: center; justify-content: center;
            font-size: 20px; color: #fff; flex-shrink: 0;
        }
        .metric-content { min-width: 0; }
        .metric-value {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 24px; font-weight: 800; color: #fff; line-height: 1.1; word-break: break-word;
        }
        .metric-label {
            font-size: 10.5px; font-weight: 700; color: rgba(255,255,255,0.9);
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        .metric-sub { font-size: 10px; color: rgba(255,255,255,0.75); margin-top: 2px; }

        .grid-2col { display: grid; grid-template-columns: 1.5fr 1fr; gap: 16px; margin-bottom: 20px; }
        .grid-2col-eq { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px; }
        .grid-3col { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 20px; }

        .panel-card {
            background: var(--bg-card); border: 1px solid var(--border-color);
            border-radius: 16px; overflow: hidden;
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
        .chart-container-sm { position: relative; height: 220px; }

        .payor-list { display: flex; flex-direction: column; gap: 8px; }
        .payor-item {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 12px; background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: 10px;
        }
        .payor-rank {
            width: 26px; height: 26px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 800; flex-shrink: 0;
        }
        .payor-rank.rank-1 { background: linear-gradient(135deg, #fbbf24, #d97706); color: #fff; }
        .payor-rank.rank-2 { background: linear-gradient(135deg, #94a3b8, #64748b); color: #fff; }
        .payor-rank.rank-3 { background: linear-gradient(135deg, #b45309, #78350f); color: #fff; }
        .payor-rank.rank-other { background: var(--bg-card-alt); color: var(--text-sub); }
        .payor-body { flex: 1; min-width: 0; }
        .payor-name {
            font-size: 12.5px; font-weight: 700; color: var(--text-main);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .payor-sub { font-size: 10.5px; color: var(--text-muted); }
        .payor-amount {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 14px; font-weight: 800; color: #059669; flex-shrink: 0;
        }

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
            padding: 16px; margin-bottom: 20px;
            box-shadow: var(--shadow-soft);
        }
        .filter-bar-rt form { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; width: 100%; }
        .filter-bar-rt select, .filter-bar-rt input {
            padding: 8px 14px; border-radius: 10px; border: 1px solid var(--border-color);
            background: var(--bg-card-alt); color: var(--text-main); font-size: 13px; outline: none;
        }
        .filter-bar-rt select:focus, .filter-bar-rt input:focus {
            border-color: var(--red-accent); background: #fff;
        }
        .btn-filter-rt {
            background: var(--red-accent); color: #fff; border: none; padding: 8px 18px;
            border-radius: 10px; font-size: 13px; font-weight: 800; cursor: pointer;
        }
        .btn-filter-rt:hover { background: #b91c1c; }

        /* ACTIVITY FEED */
        .activity-feed { display: flex; flex-direction: column; gap: 8px; max-height: 320px; overflow-y: auto; }
        .activity-item {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 10px 12px; background: #f8fafc;
            border: 1px solid var(--border-color); border-radius: 10px;
        }
        .activity-icon {
            width: 28px; height: 28px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 12px; flex-shrink: 0;
        }
        .activity-icon.success { background: rgba(5, 150, 105, 0.15); color: #059669; }
        .activity-icon.warning { background: rgba(217, 119, 6, 0.15); color: #d97706; }
        .activity-icon.danger { background: rgba(220, 38, 38, 0.15); color: #dc2626; }
        .activity-body { flex: 1; min-width: 0; }
        .activity-title { font-size: 12px; font-weight: 700; color: var(--text-main); word-break: break-word; }
        .activity-time { font-size: 10px; color: var(--text-muted); margin-top: 2px; }

        .footer {
            margin-top: auto; padding-top: 14px; padding-bottom: var(--safe-bottom);
            border-top: 1px solid var(--border-color);
            display: flex; justify-content: space-between;
            font-size: 11.5px; color: var(--text-muted); flex-wrap: wrap; gap: 8px;
        }

        /* RESPONSIVE */
        @media (max-width: 1400px) {
            .nav-link { padding: 8px 10px; font-size: 12px; }
            .nav-link .nav-ico { font-size: 12px; }
            .rt-topbar-center { max-width: 320px; }
        }

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
            .financial-scorecard { grid-template-columns: 1fr; gap: 18px; }
            .financial-breakdown-grid { grid-template-columns: repeat(3, 1fr); }
            .grid-2col, .grid-2col-eq, .grid-3col { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .rt-topbar-right { gap: 8px; padding-right: calc(12px + var(--safe-right)); }
            .rt-topbar-brand-text { display: none; }
            .dashboard-shell { padding: 12px; }
            .page-header-card { padding: 15px; flex-direction: column; align-items: flex-start; gap: 13px; }
            .page-header-card h2 { font-size: 17px; }
            .header-actions-group { width: 100%; display: grid; grid-template-columns: 1fr 1fr; gap: 7px; }
            .header-actions-group > * { width: 100%; justify-content: center; }

            .financial-scorecard { padding: 18px 16px; border-radius: 15px; gap: 14px; }
            .financial-score-number { font-size: 28px; }
            .financial-breakdown-grid { grid-template-columns: 1fr; gap: 10px; }

            .metrics-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .metric-card { padding: 14px 12px; gap: 10px; border-radius: 12px; }
            .metric-icon { width: 38px; height: 38px; font-size: 16px; border-radius: 10px; }
            .metric-value { font-size: 18px; }

            .grid-2col, .grid-2col-eq, .grid-3col { gap: 12px; margin-bottom: 14px; }

            .panel-head { padding: 12px 14px; }
            .panel-head h3 { font-size: 13px; }
            .panel-body { padding: 12px 14px; }
            .chart-container { height: 220px; }
            .chart-container-sm { height: 200px; }

            /* Filter bar mobile */
            .filter-bar-rt form { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
            .filter-bar-rt form > * { width: 100%; min-width: 0 !important; }
            .filter-bar-rt form > input[type="text"] { grid-column: 1 / -1; }

            /* Table → card view */
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

            .activity-item { padding: 8px 10px; }

            .footer { flex-direction: column; align-items: flex-start; font-size: 10px; }

            #notifDropdown {
                position: fixed !important; top: calc(var(--header-total-height) + 5px) !important;
                right: max(8px, var(--safe-right)) !important; left: max(8px, var(--safe-left)) !important;
                width: auto !important; max-width: none !important; max-height: min(70dvh, 430px); overflow-y: auto;
            }
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
            .financial-score-number { font-size: 24px; }
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
            .metric-value { font-size: 16px; }
            .financial-breakdown-item .val { font-size: 14px; }
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
    </style>
</head>
<body>

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
                    <button type="button" class="nav-link active" data-dropdown>
                        <i class="fa-solid fa-clipboard-check nav-ico"></i> Operations
                        <i class="fa-solid fa-chevron-down chevron"></i>
                        <?php if ($pendingPayments > 0): ?>
                            <span class="menu-badge"><?php echo $pendingPayments; ?></span>
                        <?php endif; ?>
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
            <input type="text" id="openSearchModalBtn" placeholder="Search payment transactions by OR number or business..." readonly>
        </div>
    </div>

    <div class="rt-topbar-right">
        <button class="icon-btn mobile-search-btn" id="mobileSearchBtn" title="Search">
            <i class="fa-solid fa-magnifying-glass"></i>
        </button>

        <div style="position:relative;">
            <button class="icon-btn" id="notifBtn" title="Notifications">
                <i class="fa-regular fa-bell"></i>
                <?php if ($unreadCount > 0): ?>
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
                <div class="avatar"><?php echo $adminInitial; ?></div>
                <div class="info d-none d-sm-flex">
                    <span class="name"><?php echo h($adminName); ?></span>
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
                    <h2><i class="fa-solid fa-chart-line"></i> Financial Oversight &amp; Payment Monitoring</h2>
                    <p>Monitor all payment transactions across LGU — collections, channels, and revenue analytics.</p>
                </div>
                <div class="header-actions-group">
                    <div class="header-clock-badge" id="liveDateTime"></div>
                    <a href="reports.php" class="btn-rt-secondary">
                        <i class="fa-solid fa-file-invoice"></i> Full Report
                    </a>
                </div>
            </div>

            <div class="financial-scorecard">
                <div class="financial-score-value">
                    <span class="financial-score-label">Total Collected</span>
                    <div class="financial-score-number"><?php echo formatCurrency($totalAmount); ?></div>
                    <div class="financial-score-sub">
                        <i class="fa-solid fa-arrow-trend-up" style="color:#059669;"></i>
                        <?php echo $verifiedPayments; ?> verified payments
                    </div>
                </div>
                <div class="financial-breakdown">
                    <div class="financial-breakdown-title">
                        <i class="fa-solid fa-chart-pie" style="margin-right: 6px;"></i> Collection Breakdown
                    </div>
                    <div class="financial-breakdown-grid">
                        <div class="financial-breakdown-item">
                            <span class="lbl">Today</span>
                            <div class="val green"><?php echo formatCurrency($todayAmount); ?></div>
                            <div class="sub"><?php echo $verifiedTodayCount; ?> verified today</div>
                        </div>
                        <div class="financial-breakdown-item">
                            <span class="lbl">This Week</span>
                            <div class="val cyan"><?php echo formatCurrency($weekAmount); ?></div>
                            <div class="sub">7-day collections</div>
                        </div>
                        <div class="financial-breakdown-item">
                            <span class="lbl">This Month</span>
                            <div class="val amber"><?php echo formatCurrency($monthAmount); ?></div>
                            <div class="sub"><?php echo date('F Y'); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="metrics-grid">
                <div class="metric-card emerald">
                    <div class="metric-icon"><i class="fa-solid fa-coins"></i></div>
                    <div class="metric-content">
                        <div class="metric-value" style="font-size:18px;"><?php echo formatCurrency($totalAmount); ?></div>
                        <div class="metric-label">Total Collected</div>
                        <div class="metric-sub"><?php echo $verifiedPayments; ?> payments</div>
                    </div>
                </div>

                <div class="metric-card amber">
                    <div class="metric-icon"><i class="fa-solid fa-hourglass-half"></i></div>
                    <div class="metric-content">
                        <div class="metric-value"><?php echo number_format($pendingPayments); ?></div>
                        <div class="metric-label">Pending Verification</div>
                        <div class="metric-sub"><?php echo formatCurrency($pendingAmount); ?> pending</div>
                    </div>
                </div>

                <div class="metric-card cyan">
                    <div class="metric-icon"><i class="fa-solid fa-check-circle"></i></div>
                    <div class="metric-content">
                        <div class="metric-value"><?php echo number_format($verifiedTodayCount); ?></div>
                        <div class="metric-label">Verified Today</div>
                        <div class="metric-sub"><?php echo formatCurrency($todayAmount); ?></div>
                    </div>
                </div>

                <div class="metric-card red">
                    <div class="metric-icon"><i class="fa-solid fa-times-circle"></i></div>
                    <div class="metric-content">
                        <div class="metric-value"><?php echo number_format($rejectedPayments); ?></div>
                        <div class="metric-label">Rejected / Failed</div>
                        <div class="metric-sub">Needs review</div>
                    </div>
                </div>
            </div>

            <div class="panel-card" style="margin-bottom: 20px;">
                <div class="panel-head">
                    <h3><i class="fa-solid fa-chart-column"></i> Revenue Trend (Last 30 Days)</h3>
                    <span class="badge">Daily Collections</span>
                </div>
                <div class="panel-body">
                    <div class="chart-container">
                        <canvas id="revenueTrendChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="grid-2col-eq">
                <div class="panel-card">
                    <div class="panel-head">
                        <h3><i class="fa-solid fa-credit-card"></i> Payment Channel Breakdown</h3>
                        <span class="badge">By Method</span>
                    </div>
                    <div class="panel-body">
                        <div class="chart-container-sm">
                            <canvas id="channelChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="panel-card">
                    <div class="panel-head">
                        <h3><i class="fa-solid fa-bullseye"></i> Payment Purpose Analytics</h3>
                        <span class="badge">By Purpose</span>
                    </div>
                    <div class="panel-body">
                        <div class="chart-container-sm">
                            <canvas id="purposeChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid-2col-eq">
                <div class="panel-card">
                    <div class="panel-head">
                        <h3><i class="fa-solid fa-trophy"></i> Top Payors</h3>
                        <span class="badge"><?php echo count($topPayors); ?> Total</span>
                    </div>
                    <div class="panel-body">
                        <?php if (!empty($topPayors)): ?>
                            <div class="payor-list">
                                <?php foreach ($topPayors as $idx => $p): 
                                    $rank = $idx + 1;
                                    $rankClass = $rank === 1 ? 'rank-1' : ($rank === 2 ? 'rank-2' : ($rank === 3 ? 'rank-3' : 'rank-other'));
                                ?>
                                    <div class="payor-item">
                                        <div class="payor-rank <?php echo $rankClass; ?>"><?php echo $rank; ?></div>
                                        <div class="payor-body">
                                            <div class="payor-name"><?php echo h($p['business_name']); ?></div>
                                            <div class="payor-sub">
                                                <?php echo h($p['owner_name']); ?> · <?php echo $p['payment_count']; ?> payments
                                            </div>
                                        </div>
                                        <div class="payor-amount"><?php echo formatCurrency($p['total_paid']); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div style="text-align:center; padding:30px; color:var(--text-muted); font-size:12px;">
                                <i class="fa-solid fa-trophy" style="font-size:24px; opacity:0.4; display:block; margin-bottom:8px;"></i>
                                No payment records yet.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="panel-card">
                    <div class="panel-head">
                        <h3><i class="fa-solid fa-clock-rotate-left" style="color:#d97706;"></i> Pending &gt;7 Days</h3>
                        <span class="badge" style="background:rgba(217,119,6,0.1); color:#d97706; border-color:rgba(217,119,6,0.2);"><?php echo count($pendingAging); ?> Aging</span>
                    </div>
                    <div class="panel-body">
                        <?php if (!empty($pendingAging)): ?>
                            <div style="overflow-x:auto;">
                                <table class="rt-table">
                                    <thead>
                                        <tr>
                                            <th>Business</th>
                                            <th>OR</th>
                                            <th>Amount</th>
                                            <th>Days</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pendingAging as $a): ?>
                                            <tr>
                                                <td data-label="Business"><strong style="color:var(--text-main); font-size:12px;"><?php echo h($a['business_name']); ?></strong></td>
                                                <td data-label="OR Number" style="font-family:monospace; font-size:11px;"><?php echo h($a['or_number']); ?></td>
                                                <td data-label="Amount" style="color:#dc2626; font-weight:700;"><?php echo formatCurrency($a['amount_paid']); ?></td>
                                                <td data-label="Days Pending">
                                                    <span class="badge-rt warning">
                                                        <?php echo $a['days_pending']; ?> days
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div style="text-align:center; padding:30px; color:var(--text-muted); font-size:12px;">
                                <i class="fa-solid fa-check-circle" style="font-size:24px; color:#059669; opacity:0.6; display:block; margin-bottom:8px;"></i>
                                Walang pending na lumagpas sa 7 days.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="grid-2col-eq">
                <div class="panel-card">
                    <div class="panel-head">
                        <h3><i class="fa-solid fa-fire" style="color:#dc2626;"></i> Recent High-Value Transactions</h3>
                        <span class="badge">&gt;₱5,000</span>
                    </div>
                    <div class="panel-body">
                        <?php if (!empty($highValueTransactions)): ?>
                            <div class="activity-feed">
                                <?php foreach ($highValueTransactions as $t): ?>
                                    <div class="activity-item">
                                        <div class="activity-icon success"><i class="fa-solid fa-money-bill-wave"></i></div>
                                        <div class="activity-body">
                                            <div class="activity-title">
                                                <?php echo h($t['business_name']); ?>
                                                <span style="color:#059669; margin-left:6px;"><?php echo formatCurrency($t['amount_paid']); ?></span>
                                            </div>
                                            <div class="activity-time">
                                                OR: <?php echo h($t['or_number']); ?> · <?php echo h($t['owner_name']); ?> ·
                                                <?php echo date('M d, h:i A', strtotime($t['created_at'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div style="text-align:center; padding:30px; color:var(--text-muted); font-size:12px;">
                                <i class="fa-solid fa-receipt" style="font-size:24px; opacity:0.4; display:block; margin-bottom:8px;"></i>
                                No high-value transactions yet.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="panel-card">
                    <div class="panel-head">
                        <h3><i class="fa-solid fa-list-check"></i> Recent Payment Activity</h3>
                        <a href="activity_logs.php" style="font-size: 11px; color: var(--red-accent); text-decoration: none; font-weight: 700;">
                            View All &rarr;
                        </a>
                    </div>
                    <div class="panel-body">
                        <?php if (!empty($recentVerifications)): ?>
                            <div class="activity-feed">
                                <?php foreach ($recentVerifications as $v): 
                                    $stat = strtolower($v['status']);
                                    $iconClass = 'success';
                                    $iconName = 'fa-check-circle';
                                    $statusLabel = 'Verified';
                                    if ($stat === 'pending') { $iconClass = 'warning'; $iconName = 'fa-clock'; $statusLabel = 'Pending'; }
                                    elseif ($stat === 'rejected' || $stat === 'failed' || $stat === 'cancelled') { $iconClass = 'danger'; $iconName = 'fa-times-circle'; $statusLabel = 'Rejected'; }
                                ?>
                                    <div class="activity-item">
                                        <div class="activity-icon <?php echo $iconClass; ?>">
                                            <i class="fa-solid <?php echo $iconName; ?>"></i>
                                        </div>
                                        <div class="activity-body">
                                            <div class="activity-title">
                                                <strong><?php echo h($v['business_name']); ?></strong>
                                                <span style="color:var(--text-muted); margin-left:4px;">— <?php echo $statusLabel; ?></span>
                                            </div>
                                            <div class="activity-time">
                                                OR: <?php echo h($v['or_number']); ?> · <?php echo formatCurrency($v['amount_paid']); ?> ·
                                                <?php echo date('M d, h:i A', strtotime($v['created_at'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div style="text-align:center; padding:30px; color:var(--text-muted); font-size:12px;">
                                No recent activity.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="filter-bar-rt">
                <form method="GET">
                    <select name="status">
                        <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="Pending" <?php echo $filterStatus === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="Verified" <?php echo $filterStatus === 'Verified' ? 'selected' : ''; ?>>Verified</option>
                        <option value="Paid" <?php echo $filterStatus === 'Paid' ? 'selected' : ''; ?>>Paid</option>
                        <option value="Rejected" <?php echo $filterStatus === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                    <input type="text" name="search" placeholder="Search OR, business, owner..." value="<?php echo h($search); ?>" style="flex:1; min-width:200px;">
                    <input type="date" name="date_from" value="<?php echo h($dateFrom); ?>">
                    <input type="date" name="date_to" value="<?php echo h($dateTo); ?>">
                    <button type="submit" class="btn-filter-rt"><i class="fa-solid fa-filter me-1"></i> Filter</button>
                    <?php if (!empty($search) || $filterStatus !== 'all' || !empty($dateFrom) || !empty($dateTo)): ?>
                        <a href="payments.php" class="btn-rt-secondary"><i class="fa-solid fa-times me-1"></i> Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="panel-card" style="margin-bottom: 20px;">
                <div class="panel-head">
                    <div>
                        <h3><i class="fa-solid fa-receipt"></i> All Payment Transactions</h3>
                        <p style="font-size: 11px; color: var(--text-muted); margin: 2px 0 0;">
                            <?php echo count($payments); ?> record(s) shown (max 100) · Total: <?php echo number_format($totalPayments); ?>
                        </p>
                    </div>
                </div>
                <div style="overflow-x:auto;">
                    <table class="rt-table">
                        <thead>
                            <tr>
                                <th>OR Number</th>
                                <th>Owner</th>
                                <th>Business</th>
                                <th>Amount</th>
                                <th>Date</th>
                                <th>Method</th>
                                <th>Purpose</th>
                                <th>Status</th>
                                <th>Proof</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($payments)): ?>
                                <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td data-label="OR Number"><strong style="color:var(--text-main); font-family:'IBM Plex Mono',monospace;"><?php echo h($payment['or_number'] ?? 'N/A'); ?></strong></td>
                                        <td data-label="Owner">
                                            <?php echo h($payment['owner_name'] ?? '—'); ?>
                                            <br><small style="color:var(--text-muted); font-size:10.5px;"><?php echo h($payment['owner_email'] ?? ''); ?></small>
                                        </td>
                                        <td data-label="Business">
                                            <strong><?php echo h($payment['business_name'] ?? '—'); ?></strong>
                                            <br><small style="color:var(--text-muted); font-size:10.5px;"><?php echo h($payment['tracking_no'] ?? ''); ?></small>
                                        </td>
                                        <td data-label="Amount" style="font-weight:700; color:var(--red-accent);"><?php echo formatCurrency($payment['amount_paid'] ?? 0); ?></td>
                                        <td data-label="Date"><?php echo date('M d, Y', strtotime($payment['created_at'] ?? 'now')); ?></td>
                                        <td data-label="Method"><?php echo getPaymentMethodBadge($payment['payment_method'] ?? ''); ?></td>
                                        <td data-label="Purpose"><small style="color:var(--text-sub);"><?php echo h($payment['payment_purpose'] ?? '—'); ?></small></td>
                                        <td data-label="Status"><?php echo getStatusBadge($payment['status'] ?? 'Pending'); ?></td>
                                        <td data-label="Proof">
                                            <?php
                                                /* ✅ FIXED: Proof URL Support (Supabase vs Local) */
                                                $proofRaw = (string)($payment['proof_image'] ?? '');
                                                
                                                if (filter_var($proofRaw, FILTER_VALIDATE_URL)) {
                                                    // Supabase URL
                                                    $proofUrl = $proofRaw;
                                                } elseif (preg_match('#^[A-Za-z0-9_./\-]+$#', $proofRaw) && strpos($proofRaw, '..') === false) {
                                                    // Local fallback
                                                    $proofUrl = '../' . ltrim($proofRaw, '/');
                                                } else {
                                                    $proofUrl = '';
                                                }

                                                if (!empty($proofUrl)): ?>
                                                <a href="<?php echo h($proofUrl); ?>" target="_blank" class="btn btn-sm btn-outline-danger" style="font-size:10.5px; padding:4px 10px; border-radius:8px;">
                                                    <i class="fa-solid fa-eye"></i> View
                                                </a>
                                            <?php else: ?>
                                                <span style="color:#94a3b8; font-size:11px;">No File</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-5">
                                        <i class="fa-regular fa-receipt fa-2x mb-2" style="opacity:0.4;"></i><br>
                                        No payments found matching your filters.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <footer class="footer">
                <span>&copy; <?php echo date('Y'); ?> <strong style="color:var(--red-accent);">RENTRACK LGU</strong> &middot; Admin Portal</span>
                <span>Financial Oversight &middot; Payment Monitoring System</span>
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

    /* ============ NOTIFICATION BELL ============ */
    const notifBtn = document.getElementById('notifBtn');
    const notifDropdown = document.getElementById('notifDropdown');
    const notifBadgeDot = document.getElementById('notifBadgeDot');

    function clearNotificationDot() {
        if (notifBadgeDot) {
            notifBadgeDot.style.opacity = '0';
            setTimeout(() => { notifBadgeDot.style.display = 'none'; }, 300);
        }
        fetch('payments.php?action=mark_notif_read');
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
                        <input type="text" id="swalSearchInput" placeholder="Search payment transactions by OR number or business..." autocomplete="off" style="width: 100%; padding: 12px 16px 12px 42px; background: #f8fafc; color: #0f172a; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 13px; outline: none;">
                    </div>
                    <div id="swalSearchResults" style="min-height: 120px; max-height: 280px; overflow-y: auto; text-align: center; color: #64748b; display: flex; flex-direction: column; justify-content: center; align-items: center;">
                        <i class="fa-solid fa-keyboard" style="font-size: 28px; margin-bottom: 8px; color: #cbd5e1;"></i>
                        <span style="font-size: 12px;">Type at least 2 characters to search payments...</span>
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
                        fetch(`payments.php?action=global_search&q=${encodeURIComponent(query)}`)
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

    /* ============ CHARTS ============ */
    const revLabels = <?php echo json_encode($revenueLabels); ?>;
    const revValues = <?php echo json_encode($revenueValues); ?>;

    const revCtx = document.getElementById('revenueTrendChart');
    if (revCtx && revLabels.length > 0) {
        new Chart(revCtx, {
            type: 'bar',
            data: {
                labels: revLabels,
                datasets: [{
                    label: 'Daily Collection (₱)',
                    data: revValues,
                    backgroundColor: revValues.map(v => v >= 5000 ? 'rgba(5, 150, 105, 0.85)' : (v >= 1000 ? 'rgba(8, 145, 178, 0.85)' : 'rgba(217, 119, 6, 0.85)')),
                    borderColor: revValues.map(v => v >= 5000 ? '#059669' : (v >= 1000 ? '#0891b2' : '#d97706')),
                    borderWidth: 1,
                    borderRadius: 6,
                    maxBarThickness: 40
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#ffffff',
                        borderColor: '#e2e8f0',
                        borderWidth: 1,
                        padding: 12,
                        titleColor: '#0f172a',
                        bodyColor: '#334155',
                        cornerRadius: 8,
                        callbacks: {
                            label: function(context) {
                                return '₱' + context.parsed.y.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: '#64748b', font: { size: 10 } } },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(0, 0, 0, 0.05)' },
                        ticks: {
                            color: '#64748b',
                            font: { size: 10 },
                            callback: function(value) { return '₱' + value.toLocaleString(); }
                        }
                    }
                }
            }
        });
    } else if (revCtx) {
        revCtx.parentElement.innerHTML = '<div style="display:flex; align-items:center; justify-content:center; height:100%; color:#64748b; font-size:12px;">No revenue data available</div>';
    }

    const channelLabels = <?php echo json_encode(array_column($channelData, 'method')); ?>;
    const channelValues = <?php echo json_encode(array_map('floatval', array_column($channelData, 'total'))); ?>;
    const channelColors = channelLabels.map(m => {
        const colors = {
            'cash': '#059669', 'gcash': '#0891b2', 'maya': '#d97706', 'paymaya': '#d97706',
            'bank': '#7c3aed', 'bank transfer': '#7c3aed', 'card': '#dc2626', 'online': '#2563eb'
        };
        return colors[String(m).toLowerCase()] || '#64748b';
    });

    const channelCtx = document.getElementById('channelChart');
    if (channelCtx && channelLabels.length > 0) {
        new Chart(channelCtx, {
            type: 'doughnut',
            data: {
                labels: channelLabels,
                datasets: [{
                    data: channelValues,
                    backgroundColor: channelColors,
                    borderColor: '#ffffff',
                    borderWidth: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '65%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: '#334155',
                            font: { size: 11, family: 'Plus Jakarta Sans' },
                            padding: 12,
                            usePointStyle: true,
                            pointStyle: 'circle'
                        }
                    },
                    tooltip: {
                        backgroundColor: '#ffffff',
                        borderColor: '#e2e8f0',
                        borderWidth: 1,
                        titleColor: '#0f172a',
                        bodyColor: '#334155',
                        cornerRadius: 8,
                        callbacks: {
                            label: function(context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const pct = total > 0 ? ((context.parsed / total) * 100).toFixed(1) : 0;
                                return context.label + ': ₱' + context.parsed.toLocaleString('en-US', {minimumFractionDigits: 2}) + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    } else if (channelCtx) {
        channelCtx.parentElement.innerHTML = '<div style="display:flex; align-items:center; justify-content:center; height:100%; color:#64748b; font-size:12px;">No channel data available</div>';
    }

    const purposeLabels = <?php echo json_encode(array_column($purposeData, 'purpose')); ?>;
    const purposeValues = <?php echo json_encode(array_map('floatval', array_column($purposeData, 'total'))); ?>;

    const purposeCtx = document.getElementById('purposeChart');
    if (purposeCtx && purposeLabels.length > 0) {
        new Chart(purposeCtx, {
            type: 'bar',
            data: {
                labels: purposeLabels,
                datasets: [{
                    label: 'Total Amount (₱)',
                    data: purposeValues,
                    backgroundColor: 'rgba(220, 38, 38, 0.85)',
                    borderColor: '#dc2626',
                    borderWidth: 1,
                    borderRadius: 6,
                    maxBarThickness: 40
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#ffffff',
                        borderColor: '#e2e8f0',
                        borderWidth: 1,
                        titleColor: '#0f172a',
                        bodyColor: '#334155',
                        cornerRadius: 8,
                        callbacks: {
                            label: function(context) {
                                return '₱' + context.parsed.x.toLocaleString('en-US', {minimumFractionDigits: 2});
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        grid: { color: 'rgba(0, 0, 0, 0.05)' },
                        ticks: {
                            color: '#64748b',
                            font: { size: 10 },
                            callback: function(value) { return '₱' + value.toLocaleString(); }
                        }
                    },
                    y: { grid: { display: false }, ticks: { color: '#334155', font: { size: 11 } } }
                }
            }
        });
    } else if (purposeCtx) {
        purposeCtx.parentElement.innerHTML = '<div style="display:flex; align-items:center; justify-content:center; height:100%; color:#64748b; font-size:12px;">No purpose data available</div>';
    }
</script>
</body>
</html>