<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();

require_once '../config/db.php';
require_once 'includes/auth.php';

/* =====================================================
   HELPER FUNCTIONS (PDO / PostgreSQL Compatible)
===================================================== */

function tableExists($conn, $tableName)
{
    try {
        $stmt = $conn->prepare("
            SELECT EXISTS (
                SELECT 1 
                FROM information_schema.tables 
                WHERE table_schema = 'public' 
                AND table_name = :table
            )
        ");
        $stmt->execute([':table' => strtolower($tableName)]);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

function columnExists($conn, $tableName, $columnName)
{
    if (!tableExists($conn, $tableName)) {
        return false;
    }
    try {
        $stmt = $conn->prepare("
            SELECT EXISTS (
                SELECT 1 
                FROM information_schema.columns 
                WHERE table_schema = 'public' 
                AND table_name = :table 
                AND column_name = :column
            )
        ");
        $stmt->execute([
            ':table' => strtolower($tableName),
            ':column' => strtolower($columnName)
        ]);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

/* =====================================================
   AJAX HANDLERS (NOTIFICATION & SEARCH)
===================================================== */

$adminUserId = (int) ($_SESSION['user_id'] ?? 1);

// 1. Mark notifications as read
if (isset($_GET['action']) && $_GET['action'] === 'mark_notif_read') {
    if (tableExists($conn, 'notifications')) {
        $notifId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($notifId > 0) {
            $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE id = :notif_id AND user_id = :user_id");
            $stmt->execute([':notif_id' => $notifId, ':user_id' => $adminUserId]);
        } else {
            $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = :user_id");
            $stmt->execute([':user_id' => $adminUserId]);
        }
    }
    echo json_encode(['status' => 'success']);
    exit;
}

// 2. Ajax Global Search Query
if (isset($_GET['action']) && $_GET['action'] === 'global_search') {
    $q = trim($_GET['q'] ?? '');
    $results = [];

    if (strlen($q) >= 1) {
        $searchTerm = '%' . $q . '%';

        if (tableExists($conn, 'rentals')) {
            $nameCol = columnExists($conn, 'rentals', 'business_name') ? 'business_name' : 
                      (columnExists($conn, 'rentals', 'rental_name') ? 'rental_name' : 
                      (columnExists($conn, 'rentals', 'name') ? 'name' : 'id'));
            
            $ownerCol = columnExists($conn, 'rentals', 'owner_name') ? 'owner_name' : 'id';
            $trackCol = columnExists($conn, 'rentals', 'tracking_no') ? 'tracking_no' : 'id';
            
            $whereClause = [];
            $params = [];

            if ($nameCol !== 'id') { $whereClause[] = "{$nameCol} ILIKE :q"; }
            if ($ownerCol !== 'id') { $whereClause[] = "{$ownerCol} ILIKE :q"; }
            if ($trackCol !== 'id') { $whereClause[] = "{$trackCol} ILIKE :q"; }
            $whereClause[] = "CAST(id AS TEXT) ILIKE :q";
            $params[':q'] = $searchTerm;

            $whereSql = implode(' OR ', $whereClause);
            $deletedSql = columnExists($conn, 'rentals', 'is_deleted') ? "WHERE is_deleted = FALSE AND ($whereSql)" : "WHERE ($whereSql)";

            $stmt = $conn->prepare("SELECT * FROM rentals $deletedSql LIMIT 5");
            $stmt->execute($params);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $titleVal = $row[$nameCol] ?? ('Rental #' . $row['id']);
                $trackVal = $row[$trackCol] ?? 'N/A';
                $ownerVal = $row[$ownerCol] ?? 'N/A';

                $results[] = [
                    'type' => 'Rental Property',
                    'title' => $titleVal,
                    'sub' => 'Owner: ' . $ownerVal . ' | Tracking: ' . $trackVal,
                    'link' => 'rentals.php?search=' . urlencode($q)
                ];
            }
        }

        if (tableExists($conn, 'users')) {
            $uNameCol = columnExists($conn, 'users', 'fullname') ? 'fullname' : 'username';
            $stmt = $conn->prepare("
                SELECT * FROM users 
                WHERE {$uNameCol} ILIKE :q OR email ILIKE :q
                LIMIT 4
            ");
            $stmt->execute([':q' => $searchTerm]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = [
                    'type' => 'User (' . ucfirst($row['role'] ?? 'Member') . ')',
                    'title' => $row[$uNameCol] ?? $row['username'],
                    'sub' => $row['email'] ?? '',
                    'link' => 'users.php?search=' . urlencode($q)
                ];
            }
        }
    }

    echo json_encode($results);
    exit;
}

/* =====================================================
   DEFAULT VALUES
===================================================== */

$totalRentals    = 0;
$totalOwners     = 0;

$pending  = 0;
$approved = 0;
$expired  = 0;

$inspectionToday = 0;
$pendingReschedules = 0;

$totalAmountCollected = 0;

$pendingRemittances = 0;
$acceptedRemittances = 0;
$rejectedRemittances = 0;
$totalAcceptedRemittances = 0;
$totalRemittanceAmount = 0;

$paymentsTotal = 0;
$paymentsPending = 0;
$paymentsVerified = 0;
$paymentsRejected = 0;
$paymentsTotalAmount = 0;

$inspectionsTotal = 0;
$inspectionsScheduled = 0;
$inspectionsOngoing = 0;
$inspectionsCompleted = 0;
$inspectionsFailed = 0;

$renewalsTotal = 0;
$renewalsPending = 0;
$renewalsApproved = 0;
$renewalsRejected = 0;

$renewalTypeLabels = [];
$renewalTypeValues = [];

$complianceCompliant = 0;
$compliancePending = 0;
$complianceNonCompliant = 0;

$topStaff = [];
$topInspectors = [];
$totalStaff = 0;
$totalInspectors = 0;

$recentActivity = [];

/* =====================================================
   RENTALS
===================================================== */

if (tableExists($conn, 'rentals')) {
    $hasDeleted = columnExists($conn, 'rentals', 'is_deleted');
    $delWhere = $hasDeleted ? " WHERE is_deleted = FALSE" : "";
    $delAnd = $hasDeleted ? " AND is_deleted = FALSE" : "";

    $totalRentals = (int) $conn->query("SELECT COUNT(*) FROM rentals $delWhere")->fetchColumn();
    $pending = (int) $conn->query("SELECT COUNT(*) FROM rentals WHERE status::text ILIKE 'Pending' $delAnd")->fetchColumn();
    $approved = (int) $conn->query("SELECT COUNT(*) FROM rentals WHERE status::text ILIKE 'Approved' $delAnd")->fetchColumn();

    if (columnExists($conn, 'rentals', 'permit_expiry')) {
        $expired = (int) $conn->query("SELECT COUNT(*) FROM rentals WHERE permit_expiry < CURRENT_DATE $delAnd")->fetchColumn();
    }

    if (columnExists($conn, 'rentals', 'compliance_status')) {
        $complianceCompliant = (int) $conn->query("SELECT COUNT(*) FROM rentals WHERE compliance_status::text ILIKE 'Compliant' $delAnd")->fetchColumn();
        $compliancePending = (int) $conn->query("SELECT COUNT(*) FROM rentals WHERE compliance_status::text ILIKE 'Pending' $delAnd")->fetchColumn();
        $complianceNonCompliant = (int) $conn->query("SELECT COUNT(*) FROM rentals WHERE (compliance_status::text ILIKE 'Non-Compliant' OR compliance_status::text ILIKE 'Action Required') $delAnd")->fetchColumn();
    }
}

/* =====================================================
   USERS
===================================================== */

if (tableExists($conn, 'users')) {
    $totalOwners = (int) $conn->query("SELECT COUNT(*) FROM users WHERE role::text ILIKE 'owner'")->fetchColumn();
    $totalStaff = (int) $conn->query("SELECT COUNT(*) FROM users WHERE role::text ILIKE 'staff'")->fetchColumn();
    $totalInspectors = (int) $conn->query("SELECT COUNT(*) FROM users WHERE role::text ILIKE 'inspector'")->fetchColumn();
}

/* =====================================================
   TODAY'S INSPECTIONS
===================================================== */

if (tableExists($conn, 'inspections') && columnExists($conn, 'inspections', 'inspection_date')) {
    // I-check kung may is_deleted column ang users at inspections
    $hasUserDeleted = columnExists($conn, 'users', 'is_deleted');
    $userFilter = $hasUserDeleted ? " AND u.is_deleted = FALSE" : "";
    
    try {
        $stmt = $conn->query("
            SELECT COUNT(*) 
            FROM inspections i
            INNER JOIN users u ON i.inspector_id = u.id
            WHERE i.inspection_date = CURRENT_DATE $userFilter
        ");
        $inspectionToday = (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        // Fallback kung walang inspector_id
        $inspectionToday = (int) $conn->query("SELECT COUNT(*) FROM inspections WHERE inspection_date = CURRENT_DATE")->fetchColumn();
    }
}

/* =====================================================
   REMITTANCE STATS
===================================================== */

if (tableExists($conn, 'remittances')) {
    $pendingRemittances = (int) $conn->query("SELECT COUNT(*) FROM remittances WHERE status::text ILIKE 'Pending'")->fetchColumn();
    $acceptedRemittances = (int) $conn->query("SELECT COUNT(*) FROM remittances WHERE status::text ILIKE 'Accepted'")->fetchColumn();
    $rejectedRemittances = (int) $conn->query("SELECT COUNT(*) FROM remittances WHERE status::text ILIKE 'Rejected'")->fetchColumn();

    $rowRemit = $conn->query("SELECT COUNT(*), COALESCE(SUM(total_amount),0) FROM remittances WHERE status::text ILIKE 'Accepted'")->fetch(PDO::FETCH_NUM);
    if ($rowRemit) {
        $totalAcceptedRemittances = (int) $rowRemit[0];
        $totalRemittanceAmount = (float) $rowRemit[1];
    }
}

$remitMonthlyLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'];
$remitMonthlyValues = [0, 0, 0, 0, 0, 0];

if (tableExists($conn, 'remittances') && columnExists($conn, 'remittances', 'remittance_date')) {
    $remitMonthQuery = $conn->query(
        "SELECT TO_CHAR(remittance_date, 'Mon') AS month_name, SUM(total_amount) AS total
         FROM remittances
         WHERE status::text ILIKE 'Accepted' AND remittance_date IS NOT NULL
         GROUP BY TO_CHAR(remittance_date, 'YYYY-MM'), TO_CHAR(remittance_date, 'Mon')
         ORDER BY TO_CHAR(remittance_date, 'YYYY-MM') ASC
         LIMIT 6"
    );

    if ($remitMonthQuery && $remitMonthQuery->rowCount() > 0) {
        $rLabels = []; $rValues = [];
        while ($row = $remitMonthQuery->fetch(PDO::FETCH_ASSOC)) {
            $rLabels[] = $row['month_name'];
            $rValues[] = (float) $row['total'];
        }
        $remitMonthlyLabels = $rLabels;
        $remitMonthlyValues = $rValues;
    }
}

/* =====================================================
   PAYMENTS OVERVIEW
===================================================== */

if (tableExists($conn, 'payments')) {
    $paymentsTotal = (int) $conn->query("SELECT COUNT(*) FROM payments")->fetchColumn();
    $paymentsPending = (int) $conn->query("SELECT COUNT(*) FROM payments WHERE status::text ILIKE 'Pending'")->fetchColumn();
    $paymentsVerified = (int) $conn->query("SELECT COUNT(*) FROM payments WHERE (status::text ILIKE 'Verified' OR status::text ILIKE 'Paid' OR status::text ILIKE 'Approved')")->fetchColumn();
    $paymentsRejected = (int) $conn->query("SELECT COUNT(*) FROM payments WHERE (status::text ILIKE 'Rejected' OR status::text ILIKE 'Cancelled')")->fetchColumn();

    $rowPay = $conn->query("SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE status::text ILIKE 'Verified' OR status::text ILIKE 'Paid' OR status::text ILIKE 'Approved'")->fetch(PDO::FETCH_NUM);
    if ($rowPay) {
        $paymentsTotalAmount = (float) $rowPay[0];
    }
}

/* =====================================================
   INSPECTIONS OVERVIEW (UPDATED WITH JOIN)
===================================================== */

if (tableExists($conn, 'inspections')) {
    $hasUserDeleted = columnExists($conn, 'users', 'is_deleted');
    $userFilter = $hasUserDeleted ? " AND u.is_deleted = FALSE" : "";
    
    try {
        // Base query para sa lahat ng counts
        $baseQuery = "
            FROM inspections i
            INNER JOIN users u ON i.inspector_id = u.id
            INNER JOIN rentals r ON i.rental_id = r.id
            INNER JOIN users o ON r.user_id = o.id
            WHERE 1=1 $userFilter " . ($hasUserDeleted ? " AND o.is_deleted = FALSE" : "");
        
        $inspectionsTotal = (int) $conn->query("SELECT COUNT(*) $baseQuery")->fetchColumn();
        $inspectionsScheduled = (int) $conn->query("SELECT COUNT(*) $baseQuery AND i.inspection_status::text ILIKE 'Scheduled'")->fetchColumn();
        $inspectionsOngoing = (int) $conn->query("SELECT COUNT(*) $baseQuery AND i.inspection_status::text ILIKE 'Ongoing'")->fetchColumn();
        $inspectionsCompleted = (int) $conn->query("SELECT COUNT(*) $baseQuery AND (i.inspection_status::text ILIKE 'Completed' OR i.inspection_status::text ILIKE 'Passed')")->fetchColumn();
        $inspectionsFailed = (int) $conn->query("SELECT COUNT(*) $baseQuery AND (i.inspection_status::text ILIKE 'Failed' OR i.inspection_status::text ILIKE 'Cancelled')")->fetchColumn();
    } catch (PDOException $e) {
        // Fallback kung may mali sa table structure
        $inspectionsTotal = (int) $conn->query("SELECT COUNT(*) FROM inspections")->fetchColumn();
        $inspectionsScheduled = (int) $conn->query("SELECT COUNT(*) FROM inspections WHERE inspection_status::text ILIKE 'Scheduled'")->fetchColumn();
        $inspectionsOngoing = (int) $conn->query("SELECT COUNT(*) FROM inspections WHERE inspection_status::text ILIKE 'Ongoing'")->fetchColumn();
        $inspectionsCompleted = (int) $conn->query("SELECT COUNT(*) FROM inspections WHERE (inspection_status::text ILIKE 'Completed' OR inspection_status::text ILIKE 'Passed')")->fetchColumn();
        $inspectionsFailed = (int) $conn->query("SELECT COUNT(*) FROM inspections WHERE (inspection_status::text ILIKE 'Failed' OR inspection_status::text ILIKE 'Cancelled')")->fetchColumn();
    }
}

/* =====================================================
   RENEWALS OVERVIEW (UPDATED WITH JOIN)
===================================================== */

if (tableExists($conn, 'permit_renewals')) {
    $hasUserDeleted = columnExists($conn, 'users', 'is_deleted');
    $userFilter = $hasUserDeleted ? " AND u.is_deleted = FALSE" : "";
    
    try {
        // Base query para sa renewals
        $baseRenewalQuery = "
            FROM permit_renewals p
            INNER JOIN users u ON p.user_id = u.id
            WHERE 1=1 $userFilter
        ";

        $renewalsTotal = (int) $conn->query("SELECT COUNT(*) $baseRenewalQuery")->fetchColumn();
        $renewalsPending = (int) $conn->query("SELECT COUNT(*) $baseRenewalQuery AND p.status::text ILIKE 'Pending'")->fetchColumn();
        $renewalsApproved = (int) $conn->query("SELECT COUNT(*) $baseRenewalQuery AND p.status::text ILIKE 'Approved'")->fetchColumn();
        $renewalsRejected = (int) $conn->query("SELECT COUNT(*) $baseRenewalQuery AND p.status::text ILIKE 'Rejected'")->fetchColumn();

        // Renewals by type chart
        if (columnExists($conn, 'permit_renewals', 'document_type')) {
            $typeQuery = $conn->query("
                SELECT p.document_type, COUNT(*) AS cnt 
                $baseRenewalQuery
                AND p.document_type IS NOT NULL AND p.document_type != ''
                GROUP BY p.document_type 
                ORDER BY cnt DESC 
                LIMIT 6
            ");
            if ($typeQuery) {
                while ($row = $typeQuery->fetch(PDO::FETCH_ASSOC)) {
                    $label = str_replace(' Renewal', '', $row['document_type']);
                    if (strlen($label) > 22) {
                        $label = substr($label, 0, 20) . '...';
                    }
                    $renewalTypeLabels[] = $label;
                    $renewalTypeValues[] = (int) $row['cnt'];
                }
            }
        }
    } catch (PDOException $e) {
        // Fallback
        $renewalsTotal = (int) $conn->query("SELECT COUNT(*) FROM permit_renewals")->fetchColumn();
        $renewalsPending = (int) $conn->query("SELECT COUNT(*) FROM permit_renewals WHERE status::text ILIKE 'Pending'")->fetchColumn();
        $renewalsApproved = (int) $conn->query("SELECT COUNT(*) FROM permit_renewals WHERE status::text ILIKE 'Approved'")->fetchColumn();
        $renewalsRejected = (int) $conn->query("SELECT COUNT(*) FROM permit_renewals WHERE status::text ILIKE 'Rejected'")->fetchColumn();
    }
}

/* =====================================================
   TOP STAFF PERFORMANCE
===================================================== */

if (tableExists($conn, 'payments') && tableExists($conn, 'users')) {
    try {
        $staffQuery = $conn->query("
            SELECT u.id, u.fullname, u.email, COUNT(p.id) AS verified_count
            FROM users u
            LEFT JOIN payments p ON p.verified_by = u.id AND (p.status::text ILIKE 'Verified' OR p.status::text ILIKE 'Paid' OR p.status::text ILIKE 'Approved')
            WHERE u.role::text ILIKE 'staff'
            GROUP BY u.id, u.fullname, u.email
            ORDER BY verified_count DESC
            LIMIT 5
        ");
        if ($staffQuery) {
            $topStaff = $staffQuery->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        try {
            $staffQuery = $conn->query("
                SELECT u.id, u.fullname, u.email, 0 AS verified_count
                FROM users u
                WHERE u.role::text ILIKE 'staff'
                LIMIT 5
            ");
            if ($staffQuery) {
                $topStaff = $staffQuery->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (PDOException $e2) {}
    }
}

/* =====================================================
   TOP INSPECTOR PERFORMANCE
===================================================== */

if (tableExists($conn, 'inspections') && tableExists($conn, 'users')) {
    try {
        $inspectorQuery = $conn->query("
            SELECT u.id, u.fullname, u.email, COUNT(i.id) AS completed_count
            FROM users u
            LEFT JOIN inspections i ON i.inspector_id = u.id AND (i.inspection_status::text ILIKE 'Completed' OR i.inspection_status::text ILIKE 'Passed')
            WHERE u.role::text ILIKE 'inspector'
            GROUP BY u.id, u.fullname, u.email
            ORDER BY completed_count DESC
            LIMIT 5
        ");
        if ($inspectorQuery) {
            $topInspectors = $inspectorQuery->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {}
}

/* =====================================================
   RECENT ACTIVITY FEED (UPDATED WITH JOIN)
===================================================== */

if (tableExists($conn, 'payments')) {
    try {
        $actQuery = $conn->query("
            SELECT 'payment' AS activity_type, 
                   CONCAT('Payment #', id, ' was ', status::text) AS description,
                   created_at
            FROM payments
            WHERE status::text ILIKE 'Verified' OR status::text ILIKE 'Pending'
            ORDER BY created_at DESC
            LIMIT 3
        ");
        if ($actQuery) {
            while ($row = $actQuery->fetch(PDO::FETCH_ASSOC)) {
                $recentActivity[] = $row;
            }
        }
    } catch (PDOException $e) {}
}

if (tableExists($conn, 'inspections')) {
    $hasUserDeleted = columnExists($conn, 'users', 'is_deleted');
    $userFilter = $hasUserDeleted ? " AND u.is_deleted = FALSE" : "";
    
    try {
        $actQuery = $conn->query("
            SELECT 'inspection' AS activity_type,
                   CONCAT('Inspection #', i.id, ' is ', i.inspection_status::text) AS description,
                   i.created_at
            FROM inspections i
            INNER JOIN users u ON i.inspector_id = u.id
            WHERE i.created_at IS NOT NULL $userFilter
            ORDER BY i.created_at DESC
            LIMIT 3
        ");
        if ($actQuery) {
            while ($row = $actQuery->fetch(PDO::FETCH_ASSOC)) {
                $recentActivity[] = $row;
            }
        }
    } catch (PDOException $e) {}
}

if (tableExists($conn, 'permit_renewals')) {
    $hasUserDeleted = columnExists($conn, 'users', 'is_deleted');
    $userFilter = $hasUserDeleted ? " AND u.is_deleted = FALSE" : "";
    
    try {
        $actQuery = $conn->query("
            SELECT 'renewal' AS activity_type,
                   CONCAT('Renewal #', p.id, ' was ', p.status::text) AS description,
                   p.created_at
            FROM permit_renewals p
            INNER JOIN users u ON p.user_id = u.id
            WHERE p.created_at IS NOT NULL $userFilter
            ORDER BY p.created_at DESC
            LIMIT 3
        ");
        if ($actQuery) {
            while ($row = $actQuery->fetch(PDO::FETCH_ASSOC)) {
                $recentActivity[] = $row;
            }
        }
    } catch (PDOException $e) {}
}

usort($recentActivity, function($a, $b) {
    return strtotime($b['created_at'] ?? 'now') - strtotime($a['created_at'] ?? 'now');
});
$recentActivity = array_slice($recentActivity, 0, 8);

/* =====================================================
   MONTHLY CHART METRICS
===================================================== */

$monthlyLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'];
$monthlyValues = [0, 0, 0, 0, 0, 0];
$monthlyApproved = [0, 0, 0, 0, 0, 0];

if (tableExists($conn, 'rentals') && columnExists($conn, 'rentals', 'created_at')) {
    $hasDeleted = columnExists($conn, 'rentals', 'is_deleted');
    $delWhereMonthly = $hasDeleted ? "WHERE created_at IS NOT NULL AND is_deleted = FALSE" : "WHERE created_at IS NOT NULL";

    $monthlyQuery = $conn->query(
        "SELECT TO_CHAR(created_at, 'Mon') AS month_name, COUNT(*) AS total
         FROM rentals
         $delWhereMonthly
         GROUP BY TO_CHAR(created_at, 'YYYY-MM'), TO_CHAR(created_at, 'Mon')
         ORDER BY TO_CHAR(created_at, 'YYYY-MM') ASC
         LIMIT 6"
    );

    if ($monthlyQuery && $monthlyQuery->rowCount() > 0) {
        $mLabels = []; $mValues = [];
        while ($row = $monthlyQuery->fetch(PDO::FETCH_ASSOC)) {
            $mLabels[] = $row['month_name'];
            $mValues[] = (int) $row['total'];
        }
        $monthlyLabels = $mLabels;
        $monthlyValues = $mValues;
    }
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
    $hasLink = columnExists($conn, 'notifications', 'link');
    $linkCol = $hasTargetUrl ? 'target_url' : ($hasLink ? 'link' : null);

    $selectFields = $linkCol ? "id, title, message, $linkCol AS target_url, created_at, is_read" : "id, title, message, created_at, is_read";

    $stmt = $conn->prepare("SELECT $selectFields FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 6");
    $stmt->execute([':user_id' => $adminUserId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (empty($row['target_url']) || $row['target_url'] === 'javascript:void(0)') {
            $msgLower = strtolower($row['title'] . ' ' . $row['message']);
            if (strpos($msgLower, 'renewal') !== false || strpos($msgLower, 'permit') !== false) {
                $row['target_url'] = 'renewals_approval.php';
            } elseif (strpos($msgLower, 'inspection') !== false || strpos($msgLower, 'reschedule') !== false) {
                $row['target_url'] = 'inspections.php';
            } elseif (strpos($msgLower, 'rental') !== false || strpos($msgLower, 'property') !== false) {
                $row['target_url'] = 'rentals.php';
            } else {
                $row['target_url'] = 'activity_logs.php';
            }
        }
        $notifications[] = $row;
    }
}

/* =====================================================
   DERIVED METRICS
===================================================== */

$approvalRate = $totalRentals > 0 ? round(($approved / $totalRentals) * 100) : 0;
$userName = $_SESSION['fullname'] ?? 'Admin';
$userRole = $_SESSION['role'] ?? 'Administrator';
$userInitial = strtoupper(substr($userName, 0, 1));

function formatCurrency($amount) {
    return '₱' . number_format((float)$amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#dc2626">
    <title>Admin Dashboard | RENTRACK LGU</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;700;800&display=swap" rel="stylesheet">

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
            -moz-osx-font-smoothing: grayscale;
            touch-action: manipulation;
        }

        body.drawer-open { overflow: hidden !important; touch-action: none; }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: var(--bg-body); }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 20px; }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .rt-panel, .rt-chart-card, .rt-gradient-card { animation: fadeUp 0.5s cubic-bezier(0.4,0,0.2,1) both; }

        .swal2-backdrop-show {
            background: rgba(0, 0, 0, 0.4) !important;
            backdrop-filter: blur(4px) !important;
            -webkit-backdrop-filter: blur(4px) !important;
        }

        /* ============================================
           TOP OFFICIAL BAR
        ============================================ */
        .top-official-bar {
            position: fixed; top: 0; left: 0; width: 100%;
            background: var(--topofficial-bg);
            border-bottom: 1px solid rgba(255, 255, 255, 0.15);
            padding: var(--safe-top) calc(15px + var(--safe-right)) 0 calc(15px + var(--safe-left));
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: nowrap; overflow: hidden;
            font-size: 11px; font-weight: 600; z-index: 1100; height: var(--topofficial-height);
            color: #fff;
        }
        .topbar-left { display: flex; align-items: center; gap: 8px; color: #fff; min-width: 0; flex-shrink: 1; overflow: hidden; }
        .topbar-left i { font-size: 12px; color: #fecdd3; flex-shrink: 0; }
        .topbar-left strong { letter-spacing: 0.3px; font-weight: 800; color: #fff; flex-shrink: 0; white-space: nowrap; }
        .topbar-left .divider { width: 1px; height: 12px; background: rgba(255, 255, 255, 0.3); flex-shrink: 0; }
        .topbar-left .portal-title { color: #ffe4e6; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }
        .topbar-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }

        .cert-badge {
            display: inline-flex; align-items: center; gap: 6px; padding: 3px 12px;
            background: rgba(255, 255, 255, 0.15); border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 999px; color: #fff; font-weight: 700; font-size: 10px;
            text-transform: uppercase; letter-spacing: 0.5px;
        }

        /* ============================================
           MAIN NAVBAR (HORIZONTAL TOP NAV)
        ============================================ */
        .rt-topbar {
            position: fixed;
            top: var(--topofficial-height); left: 0; width: 100%;
            background: var(--navbar-bg);
            border-bottom: 1px solid var(--border-color);
            padding: 0;
            display: flex; align-items: center; justify-content: space-between;
            height: var(--navbar-height);
            z-index: 1050;
            gap: 12px;
        }

        .rt-topbar-left { display: flex; align-items: center; height: 100%; flex-shrink: 0; }

        .rt-topbar-brand { 
            display: flex; align-items: center; gap: 10px;
            height: 100%; padding: 0 18px 0 calc(18px + var(--safe-left));
            border-right: 1px solid var(--border-color);
            flex-shrink: 0;
        }

        .rt-topbar-logo {
            width: 36px; height: 36px; border-radius: 10px;
            background: linear-gradient(135deg, var(--red-accent), #991b1b);
            display: flex; align-items: center; justify-content: center;
            font-family: 'Space Grotesk', sans-serif; font-weight: 800; font-size: 13px; color: #fff;
            box-shadow: 0 0 12px rgba(220, 38, 38, 0.2); flex-shrink: 0;
        }
        .rt-topbar-brand-text { display: flex; flex-direction: column; line-height: 1.15; }
        .rt-topbar-brand-text strong { font-size: 13px; font-weight: 800; color: var(--text-main); letter-spacing: 0.3px; }
        .rt-topbar-brand-text span { font-size: 9px; font-weight: 700; color: var(--red-accent); text-transform: uppercase; letter-spacing: 0.06em; }

        /* Hamburger (mobile only) */
        .menu-toggle {
            display: none;
            width: 42px; height: 42px; border-radius: 10px;
            align-items: center; justify-content: center;
            background: var(--bg-card-alt); border: 1px solid var(--border-color);
            color: var(--red-accent); cursor: pointer; font-size: 16px;
            flex-shrink: 0; margin-left: 12px;
        }
        .menu-toggle:active { background: var(--red-accent); color: #fff; border-color: var(--red-accent); }

        /* ============================================
           HORIZONTAL NAV MENU
        ============================================ */
        .main-nav-bar {
            flex: 1;
            display: flex;
            align-items: center;
            height: 100%;
            min-width: 0;
            overflow: visible;
        }

        .nav-menu {
            list-style: none; margin: 0; padding: 0;
            display: flex; align-items: center; gap: 2px;
            flex-wrap: nowrap; height: 100%;
        }

        .nav-item { position: relative; height: 100%; display: flex; align-items: center; }

        .nav-link {
            display: flex; align-items: center; gap: 7px;
            padding: 8px 12px;
            color: var(--text-sub);
            text-decoration: none;
            font-size: 12.5px; font-weight: 600;
            border-radius: 8px;
            transition: all 0.15s ease;
            cursor: pointer; white-space: nowrap;
            background: transparent; border: none; font-family: inherit;
            height: 40px;
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
            box-shadow: 0 0 8px rgba(251, 191, 36, 0.55);
        }

        /* Dropdown */
        .nav-dropdown {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            min-width: 240px;
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            box-shadow: 0 12px 30px rgba(0,0,0,0.12);
            padding: 8px;
            opacity: 0; visibility: hidden;
            transform: translateY(8px);
            transition: opacity 0.18s ease, transform 0.18s ease, visibility 0.18s;
            z-index: 200;
        }
        .nav-item.open .nav-dropdown {
            opacity: 1; visibility: visible; transform: translateY(0);
        }
        .nav-item.align-right .nav-dropdown { left: auto; right: 0; }

        .nav-dropdown a {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 12px;
            border-radius: 8px;
            color: var(--text-sub);
            text-decoration: none;
            font-size: 13px; font-weight: 500;
            transition: all 0.15s ease;
        }
        .nav-dropdown a:hover { background: var(--red-soft); color: var(--red-accent); }
        .nav-dropdown a i { width: 16px; color: var(--red-accent); font-size: 14px; text-align: center; flex-shrink: 0; }
        .nav-dropdown a.danger { color: #dc2626; }

        /* Mobile drawer header (hidden on desktop) */
        .mobile-drawer-header { display: none; }

        /* ============================================
           SEARCH
        ============================================ */
        .rt-topbar-center {
            flex: 1;
            display: flex;
            justify-content: center;
            padding: 0 12px;
            max-width: 420px;
            margin: 0 auto;
            position: relative;
            min-width: 120px;
        }

        .rt-search-container { position: relative; width: 100%; }
        .rt-search-container i {
            position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
            color: var(--text-muted); font-size: 13px;
        }
        .rt-search-container input {
            width: 100%; padding: 8px 16px 8px 38px; border-radius: 999px;
            border: 1px solid var(--border-color); background: var(--bg-card-alt);
            color: var(--text-main); font-size: 13px; outline: none; transition: var(--transition);
            cursor: pointer;
        }
        .rt-search-container input::placeholder { color: var(--text-muted); }
        .rt-search-container input:focus {
            background: #fff; border-color: var(--red-accent);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }

        /* ============================================
           RIGHT SIDE ICONS
        ============================================ */
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
            transition: opacity 0.3s ease;
        }

        .rt-profile-wrapper { position: relative; }
        .rt-profile-btn {
            display: flex; align-items: center; gap: 10px; padding: 4px 14px 4px 4px;
            border-radius: 999px; border: 1px solid var(--border-color);
            background: var(--bg-card-alt); cursor: pointer; transition: var(--transition);
        }
        .rt-profile-btn:hover { border-color: var(--red-accent); background: #fff; }
        .rt-profile-btn .avatar {
            width: 32px; height: 32px; border-radius: 50%;
            background: var(--red-accent); color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-weight: 800; font-size: 13px;
        }
        .rt-profile-btn .info { display: flex; flex-direction: column; line-height: 1.1; }
        .rt-profile-btn .info .name { font-size: 12px; font-weight: 700; color: var(--text-main); }
        .rt-profile-btn .info .role { font-size: 9px; font-weight: 700; color: var(--red-accent); text-transform: uppercase; }

        .rt-profile-dropdown {
            position: absolute; top: calc(100% + 10px); right: 0;
            width: 200px; background: #fff; border: 1px solid var(--border-color);
            border-radius: 14px; padding: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            opacity: 0; visibility: hidden; transform: translateY(-8px);
            transition: var(--transition); z-index: 100;
        }
        .rt-profile-dropdown.show { opacity: 1; visibility: visible; transform: translateY(0); }

        .rt-profile-dropdown .dropdown-item {
            display: flex; align-items: center; gap: 10px; padding: 9px 12px;
            border-radius: 8px; color: var(--text-sub); text-decoration: none;
            font-size: 13px; font-weight: 500; transition: var(--transition);
        }
        .rt-profile-dropdown .dropdown-item:hover { background: var(--bg-card-alt); color: var(--text-main); }
        .rt-profile-dropdown .dropdown-item.danger { color: #dc2626; }
        .rt-profile-dropdown .dropdown-item.danger:hover { background: #fee2e2; }

        /* ============================================
           LAYOUT (no sidebar on desktop)
        ============================================ */
        .wrapper { display: flex; min-height: calc(100vh - var(--header-total-height)); min-height: calc(100dvh - var(--header-total-height)); }

        .main {
            flex: 1;
            display: flex; flex-direction: column; min-width: 0;
        }

        /* ============================================
           OVERLAY (mobile drawer)
        ============================================ */
        .sidebar-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.5); z-index: 1040;
            -webkit-backdrop-filter: blur(2px); backdrop-filter: blur(2px);
            opacity: 0; transition: opacity 0.3s ease;
        }
        .sidebar-overlay.active { display: block; opacity: 1; }

        /* ============================================
           DASHBOARD CONTENT
        ============================================ */
        .dashboard-shell { padding: 16px 20px 30px; flex: 1; }

        .rt-panel {
            background: var(--bg-card); border: 1px solid var(--border-color);
            border-radius: 16px; padding: 20px;
            box-shadow: var(--shadow-soft);
        }

        .rt-panel-head {
            display: flex; align-items: flex-end; justify-content: space-between;
            flex-wrap: wrap; gap: 12px; margin-bottom: 16px;
        }

        .rt-title {
            font-family: 'Space Grotesk', sans-serif;
            color: var(--text-main); font-size: 20px; font-weight: 800; margin-bottom: 0;
            letter-spacing: -0.2px;
        }

        .rt-live-pill {
            display: inline-flex; align-items: center; gap: 7px;
            background: rgba(5, 150, 105, 0.1); border: 1px solid rgba(5, 150, 105, 0.2);
            color: var(--emerald-accent); font-size: 11px; font-weight: 700;
            padding: 6px 12px; border-radius: 999px;
        }
        .rt-live-pill .dot {
            width: 7px; height: 7px; border-radius: 50%; background: var(--emerald-accent);
            box-shadow: 0 0 8px var(--emerald-accent);
            animation: pulseDot 1.8s ease-in-out infinite;
        }
        @keyframes pulseDot { 0%,100% { opacity: 1; } 50% { opacity: 0.35; } }

        /* WELCOME BANNER */
        .welcome-banner {
            background: #fff1f2;
            border-radius: 14px; padding: 22px 26px; color: var(--text-main);
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 18px; box-shadow: var(--shadow-soft);
            position: relative; overflow: hidden; border: 1px solid #fecdd3;
        }
        .welcome-banner::after {
            content: ''; position: absolute; width: 180px; height: 180px; border-radius: 50%;
            background: rgba(225, 29, 72, 0.05); bottom: -60px; right: -40px; pointer-events: none;
        }
        .welcome-content { display: flex; flex-direction: column; gap: 4px; z-index: 1; width: 100%; min-width: 0; }
        .welcome-portal-badge {
            display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px;
            background: rgba(225, 29, 72, 0.1); border: 1px solid rgba(225, 29, 72, 0.2);
            border-radius: 999px; color: var(--red-accent); font-weight: 700; font-size: 10px;
            text-transform: uppercase; letter-spacing: 0.5px; width: fit-content; margin-bottom: 6px;
        }
        .welcome-title {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 24px; font-weight: 800; color: #9f1239; margin: 0;
            display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
        }
        .welcome-sub {
            font-size: 12px; color: var(--text-sub); font-weight: 500;
            cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
            width: fit-content; padding: 2px 8px; border-radius: 6px;
            background: rgba(225, 29, 72, 0.05); border: 1px dashed rgba(225, 29, 72, 0.3);
            transition: var(--transition); max-width: 100%; word-break: break-word;
        }
        .welcome-sub:hover { background: rgba(225, 29, 72, 0.15); border-color: var(--red-accent); color: var(--red-accent); }

        .welcome-stats-pill {
            background: #fff; border: 1px solid #fecdd3;
            padding: 12px 18px; border-radius: 12px; display: flex; flex-direction: column; gap: 6px;
            min-width: 170px; z-index: 1; flex-shrink: 0; box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }
        .welcome-stats-pill .top { display: flex; justify-content: space-between; font-size: 11px; font-weight: 700; color: #9f1239; gap: 12px; }
        .welcome-progress-track { width: 100%; height: 6px; background: #fecdd3; border-radius: 999px; overflow: hidden; }
        .welcome-progress-bar { height: 100%; background: var(--red-accent); border-radius: 999px; transition: width 0.6s ease; }

        @keyframes waveAnim {
            0% { transform: rotate(0deg); }
            20% { transform: rotate(14deg); }
            40% { transform: rotate(-8deg); }
            60% { transform: rotate(14deg); }
            80% { transform: rotate(-4deg); }
            100% { transform: rotate(0deg); }
        }
        .waving-hand { display: inline-block; animation: waveAnim 2s infinite; transform-origin: 70% 70%; }

        .rt-metrics-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 14px;
        }

        .rt-gradient-card {
            border-radius: 12px; padding: 14px 16px;
            display: flex; align-items: center; justify-content: space-between;
            text-decoration: none; color: #fff; box-shadow: var(--shadow-soft);
            transition: var(--transition); position: relative; overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.15);
        }
        .rt-gradient-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-lift); color: #fff; }
        .rt-gradient-card::after {
            content: ''; position: absolute; width: 100px; height: 100px; border-radius: 50%;
            background: rgba(255, 255, 255, 0.1); bottom: -30px; right: -30px; pointer-events: none;
        }

        .rt-gradient-card.c-red { background: linear-gradient(135deg, #e11d48, #9f1239); }
        .rt-gradient-card.c-amber { background: linear-gradient(135deg, #d97706, #b45309); }
        .rt-gradient-card.c-blue { background: linear-gradient(135deg, #2563eb, #1d4ed8); }
        .rt-gradient-card.c-purple { background: linear-gradient(135deg, #7c3aed, #5b21b6); }
        .rt-gradient-card.c-orange { background: linear-gradient(135deg, #ea580c, #c2410c); }
        .rt-gradient-card.c-pink { background: linear-gradient(135deg, #db2777, #9d174d); }
        .rt-gradient-card.c-cyan { background: linear-gradient(135deg, #0891b2, #155e75); }
        .rt-gradient-card.c-emerald { background: linear-gradient(135deg, #059669, #047857); }
        .rt-gradient-card.c-violet { background: linear-gradient(135deg, #6d28d9, #4c1d95); }

        .rt-stat-content { display: flex; flex-direction: column; gap: 2px; min-width: 0; position: relative; z-index: 1; }
        .rt-stat-title { font-size: 10px; font-weight: 700; color: rgba(255, 255, 255, 0.85); text-transform: uppercase; letter-spacing: 0.05em; }
        .rt-stat-value { font-family: 'Space Grotesk', sans-serif; font-size: 20px; font-weight: 800; color: #fff; line-height: 1.2; word-break: break-word; }
        .rt-stat-sub { font-size: 10.5px; color: rgba(255, 255, 255, 0.8); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .rt-stat-icon {
            width: 36px; height: 36px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center; font-size: 14px;
            background: rgba(255, 255, 255, 0.2); color: #fff; flex-shrink: 0; position: relative; z-index: 1;
        }

        .rt-chart-card {
            background: var(--bg-card); border: 1px solid var(--border-color);
            border-radius: 14px; padding: 18px; transition: var(--transition);
            overflow: hidden; margin-bottom: 14px; box-shadow: var(--shadow-soft);
        }
        .rt-chart-card:hover { border-color: var(--border-strong); }
        .rt-chart-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; gap: 8px; flex-wrap: wrap; }
        .rt-chart-head h4 { font-family: 'Space Grotesk', sans-serif; color: var(--text-main); font-size: 14px; font-weight: 800; margin: 0; }
        .rt-chart-badge {
            font-size: 10px; font-weight: 700; padding: 4px 10px; border-radius: 999px;
            background: var(--bg-card-alt); color: var(--text-muted); border: 1px solid var(--border-color);
        }

        .rt-grid { display: grid; gap: 14px; }
        .rt-grid-2col { grid-template-columns: 1.2fr 1fr; }
        .rt-grid-3col { grid-template-columns: 1fr 1fr 1fr; }
        @media (max-width: 992px) { .rt-grid-3col { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 700px) { .rt-grid-2col, .rt-grid-3col { grid-template-columns: 1fr; } }

        .ops-overview-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 14px;
        }
        @media (max-width: 992px) { .ops-overview-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 480px) { .ops-overview-grid { grid-template-columns: 1fr; } }

        .ops-widget {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 14px 16px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            transition: var(--transition);
            box-shadow: var(--shadow-soft);
        }
        .ops-widget:hover { border-color: var(--border-strong); transform: translateY(-2px); }
        .ops-widget .ops-head {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .ops-widget .ops-head i {
            width: 22px; height: 22px;
            border-radius: 6px;
            display: flex; align-items: center; justify-content: center;
            font-size: 11px;
            flex-shrink: 0;
        }
        .ops-widget .ops-head .icon-payment { background: rgba(225, 29, 72, 0.15); color: #e11d48; }
        .ops-widget .ops-head .icon-inspection { background: rgba(234, 88, 12, 0.15); color: #ea580c; }
        .ops-widget .ops-head .icon-renewal { background: rgba(124, 58, 237, 0.15); color: #7c3aed; }
        .ops-widget .ops-head .icon-compliance { background: rgba(5, 150, 105, 0.15); color: #059669; }

        .ops-widget .ops-body { display: flex; flex-direction: column; gap: 6px; }
        .ops-widget .ops-main {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 22px; font-weight: 800; color: var(--text-main); line-height: 1.1;
        }
        .ops-widget .ops-sub { font-size: 11px; color: var(--text-sub); }

        .ops-stat-row {
            display: flex; gap: 12px; flex-wrap: wrap;
            margin-top: 4px;
            padding-top: 8px;
            border-top: 1px solid var(--border-color);
        }
        .ops-stat-row .stat-item { display: flex; flex-direction: column; gap: 1px; min-width: 60px; }
        .ops-stat-row .stat-item .num {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 14px; font-weight: 800; color: var(--text-main);
        }
        .ops-stat-row .stat-item .lbl { font-size: 9px; color: var(--text-muted); text-transform: uppercase; font-weight: 700; }
        .ops-stat-row .stat-item.pending .num { color: #d97706; }
        .ops-stat-row .stat-item.completed .num { color: #059669; }
        .ops-stat-row .stat-item.failed .num { color: #dc2626; }
        .ops-stat-row .stat-item.verified .num { color: #059669; }
        .ops-stat-row .stat-item.rejected .num { color: #dc2626; }
        .ops-stat-row .stat-item.approved .num { color: #059669; }
        .ops-stat-row .stat-item.ongoing .num { color: #2563eb; }

        .activity-feed {
            display: flex; flex-direction: column; gap: 8px;
            max-height: 320px; overflow-y: auto; padding-right: 4px;
        }
        .activity-feed::-webkit-scrollbar { width: 4px; }
        .activity-feed::-webkit-scrollbar-thumb { background: var(--border-strong); border-radius: 999px; }
        .activity-item {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 10px 12px;
            background: var(--bg-card-alt);
            border: 1px solid var(--border-color);
            border-radius: 10px; transition: var(--transition);
        }
        .activity-item:hover { border-color: var(--border-strong); background: #fff; }
        .activity-item .act-icon {
            width: 28px; height: 28px;
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 12px; flex-shrink: 0;
        }
        .activity-item .act-icon.payment { background: rgba(225, 29, 72, 0.15); color: #e11d48; }
        .activity-item .act-icon.inspection { background: rgba(234, 88, 12, 0.15); color: #ea580c; }
        .activity-item .act-icon.renewal { background: rgba(124, 58, 237, 0.15); color: #7c3aed; }
        .activity-item .act-body { flex: 1; min-width: 0; }
        .activity-item .act-desc { font-size: 12px; font-weight: 600; color: var(--text-main); line-height: 1.4; word-break: break-word; }
        .activity-item .act-time { font-size: 10px; color: var(--text-muted); margin-top: 2px; }

        .performer-list { display: flex; flex-direction: column; gap: 8px; }
        .performer-item {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px;
            background: var(--bg-card-alt);
            border: 1px solid var(--border-color);
            border-radius: 10px; transition: var(--transition);
        }
        .performer-item:hover { border-color: var(--border-strong); background: #fff; }
        .performer-item .perf-rank {
            width: 24px; height: 24px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 10px; font-weight: 800; flex-shrink: 0;
        }
        .performer-item .perf-rank.rank-1 { background: linear-gradient(135deg, #fbbf24, #d97706); color: #fff; }
        .performer-item .perf-rank.rank-2 { background: linear-gradient(135deg, #94a3b8, #64748b); color: #fff; }
        .performer-item .perf-rank.rank-3 { background: linear-gradient(135deg, #b45309, #78350f); color: #fff; }
        .performer-item .perf-rank.rank-other { background: var(--border-color); color: var(--text-sub); }
        .performer-item .perf-body { flex: 1; min-width: 0; }
        .performer-item .perf-name { font-size: 12.5px; font-weight: 700; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .performer-item .perf-email { font-size: 10px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .performer-item .perf-score { font-family: 'Space Grotesk', sans-serif; font-size: 16px; font-weight: 800; color: #059669; flex-shrink: 0; }

        .payment-stats-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
        .payment-stat-item {
            background: var(--bg-card-alt); border: 1px solid var(--border-color);
            border-radius: 10px; padding: 12px; text-align: center; transition: var(--transition);
        }
        .payment-stat-item:hover { border-color: var(--border-strong); background: #fff; }
        .payment-stat-item .number { font-family: 'Space Grotesk', sans-serif; font-size: 18px; font-weight: 800; color: var(--text-main); word-break: break-word; }
        .payment-stat-item .label { font-size: 10px; color: var(--text-muted); margin-top: 3px; font-weight: 600; text-transform: uppercase; }

        .quick-actions-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
        .quick-action-btn {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 6px; padding: 14px 10px; background: var(--bg-card-alt);
            border: 1px solid var(--border-color); border-radius: 10px;
            text-decoration: none; color: var(--text-main); transition: var(--transition);
        }
        .quick-action-btn:hover { background: var(--red-soft); transform: translateY(-2px); border-color: var(--red-accent); color: var(--red-accent); }
        .quick-action-btn i { font-size: 18px; color: var(--red-accent); }
        .quick-action-btn span { font-size: 11px; font-weight: 700; text-align: center; }

        .footer {
            margin-top: 20px; padding: 16px 0 calc(16px + var(--safe-bottom)) 0;
            border-top: 1px solid var(--border-color); display: flex;
            justify-content: space-between; align-items: center; font-size: 11.5px;
            color: var(--text-muted); flex-wrap: wrap; gap: 8px; text-align: center;
        }

        #scrollTopBtn {
            position: fixed; bottom: calc(25px + var(--safe-bottom)); right: 25px;
            width: 46px; height: 46px; background: linear-gradient(135deg, #dc2626, #991b1b);
            color: #fff; border: 1px solid rgba(255, 255, 255, 0.2); border-radius: 50%;
            display: flex; align-items: center; justify-content: center; font-size: 18px;
            cursor: pointer; box-shadow: 0 10px 25px rgba(220, 38, 38, 0.4);
            z-index: 9999; opacity: 0; visibility: hidden; transform: translateY(15px);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        #scrollTopBtn.show { opacity: 1; visibility: visible; transform: translateY(0); }
        #scrollTopBtn:hover { background: linear-gradient(135deg, #ef4444, #dc2626); transform: translateY(-3px); }

        /* ============================================
           RESPONSIVE BREAKPOINTS
        ============================================ */

        /* DESKTOP 1400px — compress nav */
        @media (max-width: 1400px) {
            .nav-link { padding: 8px 10px; font-size: 12px; }
            .nav-link .nav-ico { font-size: 12px; }
            .rt-topbar-center { max-width: 320px; }
        }

        /* MOBILE — 1200px BREAKPOINT (drawer mode) */
        @media (max-width: 1200px) {
            .rt-topbar-left { flex-shrink: 1; min-width: 0; }
            .menu-toggle { display: flex; }

            /* Drawer nav */
            .main-nav-bar {
                position: fixed;
                top: 0;
                left: -100%;
                width: 85vw;
                max-width: 320px;
                height: 100vh;
                height: 100dvh;
                flex-direction: column;
                align-items: stretch;
                justify-content: flex-start;
                padding: 0;
                z-index: 1090;
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
                transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                background: #ffffff;
                box-shadow: 6px 0 25px rgba(0,0,0,0.18);
                flex: none;
            }
            .main-nav-bar.mobile-open { left: 0 !important; }

            /* Drawer header */
            .mobile-drawer-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
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

            /* Nav menu stacked */
            .nav-menu {
                flex-direction: column;
                width: 100%;
                justify-content: flex-start;
                gap: 4px;
                padding: 14px;
                padding-bottom: calc(20px + var(--safe-bottom));
                height: auto;
            }
            .nav-item { width: 100%; height: auto; display: block; }
            .nav-link {
                padding: 14px 16px;
                border-radius: 10px;
                justify-content: flex-start;
                font-size: 14.5px;
                min-height: 48px;
                width: 100%;
                height: auto;
            }
            .nav-link .chevron { margin-left: auto; font-size: 11px; }
            .nav-link .nav-ico { font-size: 15px; width: 18px; }

            /* Mobile dropdown (accordion) */
            .nav-dropdown,
            .nav-item.open .nav-dropdown,
            .nav-item.align-right .nav-dropdown {
                position: static;
                left: auto; right: auto;
                transform: none;
                opacity: 1; visibility: visible;
                box-shadow: none;
                border: none;
                max-height: 0;
                overflow: hidden;
                transition: max-height 0.3s ease, padding 0.2s ease;
                padding: 0 0 0 16px;
                background: #f8fafc;
                margin-top: 4px;
                border-radius: 10px;
                min-width: 0;
            }
            .nav-item.open .nav-dropdown { max-height: 600px; padding: 8px 0 8px 16px; }
            .nav-dropdown a { padding: 12px 14px; min-height: 44px; font-size: 13.5px; }

            /* Search full width second row */
            .rt-topbar-center {
                order: 5;
                flex-basis: 100%;
                max-width: 100%;
                margin-top: 4px;
                margin-bottom: 4px;
                padding: 0;
            }

            /* Notification dropdown reposition */
            .notif-dropdown-el { width: calc(100vw - 24px) !important; max-width: 400px; right: -50px !important; }
        }

        /* 992px */
        @media (max-width: 992px) {
            .rt-topbar { flex-wrap: wrap; height: auto; padding-bottom: 6px; }
            .rt-topbar-brand { border-right: none; }
            .rt-topbar-center {
                order: 5;
                flex-basis: 100%;
                margin-top: 4px;
                margin-bottom: 4px;
            }
            body { padding-top: calc(var(--topofficial-height) + var(--navbar-height) + 50px); }
            .welcome-banner { flex-direction: column; align-items: flex-start; gap: 14px; }
            .welcome-stats-pill { width: 100%; }
        }

        @media (max-width: 768px) {
            .rt-topbar-right { gap: 8px; padding-right: calc(12px + var(--safe-right)); }
            .rt-metrics-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .rt-gradient-card { padding: 12px 14px; }
            .rt-stat-value { font-size: 18px; }
            .rt-stat-icon { width: 32px; height: 32px; font-size: 13px; }
            .ops-overview-grid { grid-template-columns: 1fr 1fr; }
            .dashboard-shell { padding: 14px 14px 24px; }
            .rt-panel { padding: 16px; }
            .welcome-title { font-size: 20px; }
            .rt-title { font-size: 18px; }
            .rt-topbar-brand-text { display: none; }
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
            .rt-panel { padding: 12px; border-radius: 12px; }
            .rt-metrics-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .rt-gradient-card { padding: 10px 12px; border-radius: 10px; }
            .rt-stat-title { font-size: 9px; }
            .rt-stat-value { font-size: 16px; }
            .rt-stat-sub { font-size: 9px; }
            .rt-stat-icon { width: 28px; height: 28px; font-size: 11px; border-radius: 8px; }
            .payment-stats-row, .quick-actions-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .welcome-banner { padding: 16px 14px; border-radius: 12px; }
            .welcome-title { font-size: 17px; gap: 6px; }
            .welcome-portal-badge { font-size: 9px; padding: 2px 8px; }
            .welcome-sub { font-size: 10.5px; }
            .welcome-stats-pill { padding: 10px 14px; min-width: auto; }
            .welcome-stats-pill .top { font-size: 10px; }
            .rt-chart-card { padding: 12px; border-radius: 12px; }
            .rt-chart-head h4 { font-size: 12.5px; }
            .rt-chart-badge { font-size: 9px; padding: 3px 8px; }
            .ops-overview-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .ops-widget { padding: 10px 12px; }
            .ops-widget .ops-main { font-size: 18px; }
            .ops-widget .ops-sub { font-size: 10px; }
            .ops-widget .ops-head { font-size: 10px; }
            .ops-stat-row { gap: 8px; }
            .ops-stat-row .stat-item .num { font-size: 12px; }
            .ops-stat-row .stat-item .lbl { font-size: 8px; }
            .performer-item { padding: 8px 10px; }
            .performer-item .perf-name { font-size: 11.5px; }
            .performer-item .perf-score { font-size: 14px; }
            .activity-item { padding: 8px 10px; }
            .activity-item .act-desc { font-size: 11px; }
            .activity-item .act-time { font-size: 9px; }
            .quick-action-btn { padding: 12px 8px; }
            .quick-action-btn i { font-size: 16px; }
            .quick-action-btn span { font-size: 10px; }
            .footer { font-size: 10px; flex-direction: column; text-align: center; }
            #scrollTopBtn { width: 40px; height: 40px; font-size: 15px; bottom: calc(18px + var(--safe-bottom)); right: 18px; }
            .rt-title { font-size: 16px; }
            .rt-panel-head { margin-bottom: 12px; }
            .rt-live-pill { font-size: 10px; padding: 5px 10px; }
        }

        @media (max-width: 380px) {
            .rt-metrics-grid { grid-template-columns: 1fr; }
            .ops-overview-grid { grid-template-columns: 1fr; }
            .payment-stats-row, .quick-actions-grid { grid-template-columns: 1fr; }
            .welcome-title { font-size: 15px; }
            .rt-stat-value { font-size: 18px; }
            .ops-widget .ops-main { font-size: 20px; }
            .dashboard-shell { padding: 8px; }
            .rt-panel { padding: 10px; }
            .rt-chart-card { padding: 10px; }
        }

        @supports (-webkit-touch-callout: none) {
            input, button, select, textarea { font-size: 16px !important; }
            .rt-search-container input { font-size: 14px !important; }
        }

        @media print {
            .top-official-bar, .rt-topbar, .sidebar-overlay, #scrollTopBtn, .footer { display: none !important; }
            body { padding-top: 0; background: #fff; color: #000; }
            .rt-panel, .rt-chart-card, .rt-gradient-card { box-shadow: none; border: 1px solid #ccc; }
        }
    </style>
</head>
<body>

<!-- ============ TOP OFFICIAL BAR ============ -->
<div class="top-official-bar">
    <div class="topbar-left">
        <i class="fa-solid fa-landmark"></i>
        <strong>REPUBLIC OF THE PHILIPPINES</strong>
        <span class="divider"></span>
        <span class="portal-title">LGU Rental Portal</span>
    </div>
    <div class="topbar-right">
        <span class="cert-badge"><i class="fa-solid fa-certificate"></i> V2.1</span>
        <span class="d-none d-lg-inline" style="font-size:11px; color:#fecdd3;">City Treasurer &amp; Building Official</span>
    </div>
</div>

<!-- ============ MAIN NAVBAR (HORIZONTAL TOP NAV) ============ -->
<header class="rt-topbar">
    <div class="rt-topbar-left">
        <!-- Hamburger (mobile only) -->
        <button class="menu-toggle" id="menuToggle" aria-label="Toggle Menu"><i class="fa-solid fa-bars"></i></button>

        <div class="rt-topbar-brand">
            <div class="rt-topbar-logo">RT</div>
            <div class="rt-topbar-brand-text">
                <strong>RENTRACK</strong>
                <span>LGU ADMIN</span>
            </div>
        </div>

        <!-- ============ HORIZONTAL NAV MENU ============ -->
        <nav class="main-nav-bar" id="mainNavBar">
            <!-- Mobile-only drawer header -->
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
                <!-- Dashboard (standalone) -->
                <li class="nav-item">
                    <a href="dashboard.php" class="nav-link active">
                        <i class="fa-solid fa-gauge-high nav-ico"></i> Dashboard
                    </a>
                </li>

                <!-- Rentals (dropdown) -->
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

                <!-- Users (standalone) -->
                <li class="nav-item">
                    <a href="users.php" class="nav-link">
                        <i class="fa-solid fa-users nav-ico"></i> Users
                    </a>
                </li>

                <!-- Operations (dropdown) -->
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

                <!-- Staff Management (dropdown) -->
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

                <!-- Reports & System (dropdown, align right) -->
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

    <!-- Search -->
    <div class="rt-topbar-center">
        <div class="rt-search-container">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" id="openSearchModalBtn" placeholder="Search tracking, business, property..." readonly>
        </div>
    </div>

    <!-- Right icons -->
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

            <div id="notifDropdown" class="notif-dropdown-el" style="display:none; position:absolute; top:46px; right:0; width:340px; max-width:calc(100vw - 20px); background:#fff; border:1px solid var(--border-color); border-radius:14px; padding:12px; box-shadow:0 10px 30px rgba(0,0,0,0.1); z-index:999;">
                <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); padding-bottom:8px; margin-bottom:8px;">
                    <span style="font-weight:700; color:var(--text-main); font-size:12px;">System Notifications</span>
                    <span style="font-size:10px; color:var(--red-accent); cursor:pointer;" id="clearNotifBadge">Mark all read</span>
                </div>
                <div id="notifListContainer" style="max-height: 350px; overflow-y: auto;">
                    <?php if (!empty($notifications)): ?>
                        <?php foreach($notifications as $n): ?>
                            <a href="<?php echo htmlspecialchars($n['target_url']); ?>" 
                               class="notif-item d-block text-decoration-none" 
                               data-id="<?php echo $n['id']; ?>"
                               style="padding:9px 8px; border-bottom:1px solid rgba(0,0,0,0.05); border-radius:6px; transition:background 0.2s;" 
                               onmouseover="this.style.background='var(--red-soft)'" 
                               onmouseout="this.style.background='transparent'">
                                <div style="font-size:11.5px; font-weight:700; color:var(--text-main);"><?php echo htmlspecialchars($n['title']); ?></div>
                                <div style="font-size:10.5px; color:var(--text-muted); margin-top:2px; line-height:1.3;"><?php echo htmlspecialchars($n['message']); ?></div>
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
                    <span class="name"><?php echo htmlspecialchars($userName); ?></span>
                    <span class="role"><?php echo htmlspecialchars($userRole); ?></span>
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

<!-- Overlay (mobile drawer) -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ============ MAIN CONTENT ============ -->
<div class="wrapper">
    <div class="main">
        <div class="dashboard-shell">
            <div class="rt-panel">

                <div class="welcome-banner">
                    <div class="welcome-content">
                        <span class="welcome-portal-badge"><i class="fa-solid fa-shield-halved"></i> LGU ADMIN PORTAL</span>
                        <h2 class="welcome-title" id="dynamicGreeting">
                            Welcome back, <?php echo htmlspecialchars($userName); ?>! <span class="waving-hand">👋</span>
                        </h2>
                        <span class="welcome-sub" id="editableBannerClock" title="Click to edit date and time">
                            <i class="fa-regular fa-calendar me-1"></i> <span id="bannerDateString"><?php echo date('l, F d, Y · h:i A'); ?></span> &middot; <span style="color:var(--red-accent); text-decoration:underline;">Click to edit</span>
                        </span>
                    </div>
                    <div class="welcome-stats-pill">
                        <div class="top">
                            <span>Overall Approval</span>
                            <span><strong><?php echo $approvalRate; ?>%</strong></span>
                        </div>
                        <div class="welcome-progress-track">
                            <div class="welcome-progress-bar" style="width: <?php echo $approvalRate; ?>%;"></div>
                        </div>
                    </div>
                </div>

                <div class="rt-panel-head">
                    <div>
                        <h2 class="rt-title">System Operations Overview</h2>
                    </div>
                    <span class="rt-live-pill"><span class="dot"></span> Live data &middot; auto-synced</span>
                </div>

                <!-- OPERATIONS OVERVIEW WIDGETS -->
                <div class="ops-overview-grid">
                    <a href="payments.php" class="ops-widget" style="text-decoration:none;">
                        <div class="ops-head">
                            <i class="fa-solid fa-cash-register icon-payment"></i>
                            <span>Payments</span>
                        </div>
                        <div class="ops-body">
                            <div class="ops-main"><?php echo number_format($paymentsTotal); ?></div>
                            <div class="ops-sub">Total Transactions</div>
                            <div class="ops-stat-row">
                                <div class="stat-item pending">
                                    <span class="num"><?php echo number_format($paymentsPending); ?></span>
                                    <span class="lbl">Pending</span>
                                </div>
                                <div class="stat-item verified">
                                    <span class="num"><?php echo number_format($paymentsVerified); ?></span>
                                    <span class="lbl">Verified</span>
                                </div>
                                <div class="stat-item rejected">
                                    <span class="num"><?php echo number_format($paymentsRejected); ?></span>
                                    <span class="lbl">Rejected</span>
                                </div>
                            </div>
                        </div>
                    </a>

                    <a href="inspections.php" class="ops-widget" style="text-decoration:none;">
                        <div class="ops-head">
                            <i class="fa-solid fa-clipboard-check icon-inspection"></i>
                            <span>Inspections</span>
                        </div>
                        <div class="ops-body">
                            <div class="ops-main"><?php echo number_format($inspectionsTotal); ?></div>
                            <div class="ops-sub">Total Inspections</div>
                            <div class="ops-stat-row">
                                <div class="stat-item pending">
                                    <span class="num"><?php echo number_format($inspectionsScheduled); ?></span>
                                    <span class="lbl">Scheduled</span>
                                </div>
                                <div class="stat-item ongoing">
                                    <span class="num"><?php echo number_format($inspectionsOngoing); ?></span>
                                    <span class="lbl">Ongoing</span>
                                </div>
                                <div class="stat-item completed">
                                    <span class="num"><?php echo number_format($inspectionsCompleted); ?></span>
                                    <span class="lbl">Completed</span>
                                </div>
                            </div>
                        </div>
                    </a>

                    <a href="renewals_approval.php" class="ops-widget" style="text-decoration:none;">
                        <div class="ops-head">
                            <i class="fa-solid fa-file-circle-exclamation icon-renewal"></i>
                            <span>Permit Renewals</span>
                        </div>
                        <div class="ops-body">
                            <div class="ops-main"><?php echo number_format($renewalsTotal); ?></div>
                            <div class="ops-sub">Total Requests</div>
                            <div class="ops-stat-row">
                                <div class="stat-item pending">
                                    <span class="num"><?php echo number_format($renewalsPending); ?></span>
                                    <span class="lbl">Pending</span>
                                </div>
                                <div class="stat-item approved">
                                    <span class="num"><?php echo number_format($renewalsApproved); ?></span>
                                    <span class="lbl">Approved</span>
                                </div>
                                <div class="stat-item rejected">
                                    <span class="num"><?php echo number_format($renewalsRejected); ?></span>
                                    <span class="lbl">Rejected</span>
                                </div>
                            </div>
                        </div>
                    </a>

                    <a href="compliance.php" class="ops-widget" style="text-decoration:none;">
                        <div class="ops-head">
                            <i class="fa-solid fa-file-shield icon-compliance"></i>
                            <span>Compliance</span>
                        </div>
                        <div class="ops-body">
                            <div class="ops-main"><?php echo number_format($totalRentals); ?></div>
                            <div class="ops-sub">Total Establishments</div>
                            <div class="ops-stat-row">
                                <div class="stat-item completed">
                                    <span class="num"><?php echo number_format($complianceCompliant); ?></span>
                                    <span class="lbl">Compliant</span>
                                </div>
                                <div class="stat-item pending">
                                    <span class="num"><?php echo number_format($compliancePending); ?></span>
                                    <span class="lbl">Pending</span>
                                </div>
                                <div class="stat-item failed">
                                    <span class="num"><?php echo number_format($complianceNonCompliant); ?></span>
                                    <span class="lbl">Action Req.</span>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>

                <!-- TOP PERFORMERS ROW -->
                <div class="rt-grid rt-grid-2col mt-3">
                    <div class="rt-chart-card mb-0">
                        <div class="rt-chart-head">
                            <h4><i class="fa-solid fa-user-tie me-2" style="color:#0891b2;"></i> Top Staff Performance</h4>
                            <span class="rt-chart-badge"><?php echo $totalStaff; ?> Total Staff</span>
                        </div>
                        <div class="performer-list">
                            <?php if (!empty($topStaff)): ?>
                                <?php foreach ($topStaff as $idx => $staff): 
                                    $rank = $idx + 1;
                                    $rankClass = $rank === 1 ? 'rank-1' : ($rank === 2 ? 'rank-2' : ($rank === 3 ? 'rank-3' : 'rank-other'));
                                ?>
                                    <div class="performer-item">
                                        <div class="perf-rank <?php echo $rankClass; ?>"><?php echo $rank; ?></div>
                                        <div class="perf-body">
                                            <div class="perf-name"><?php echo htmlspecialchars($staff['fullname'] ?? 'N/A'); ?></div>
                                            <div class="perf-email"><?php echo htmlspecialchars($staff['email'] ?? ''); ?></div>
                                        </div>
                                        <div class="perf-score"><?php echo number_format((int)($staff['verified_count'] ?? 0)); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="text-align:center; padding:20px; color:var(--text-muted); font-size:12px;">
                                    <i class="fa-solid fa-user-tie" style="font-size:24px; opacity:0.4; display:block; margin-bottom:6px;"></i>
                                    No staff accounts yet.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="rt-chart-card mb-0">
                        <div class="rt-chart-head">
                            <h4><i class="fa-solid fa-user-shield me-2" style="color:#db2777;"></i> Top Inspector Performance</h4>
                            <span class="rt-chart-badge"><?php echo $totalInspectors; ?> Total Inspectors</span>
                        </div>
                        <div class="performer-list">
                            <?php if (!empty($topInspectors)): ?>
                                <?php foreach ($topInspectors as $idx => $inspector): 
                                    $rank = $idx + 1;
                                    $rankClass = $rank === 1 ? 'rank-1' : ($rank === 2 ? 'rank-2' : ($rank === 3 ? 'rank-3' : 'rank-other'));
                                ?>
                                    <div class="performer-item">
                                        <div class="perf-rank <?php echo $rankClass; ?>"><?php echo $rank; ?></div>
                                        <div class="perf-body">
                                            <div class="perf-name"><?php echo htmlspecialchars($inspector['fullname'] ?? 'N/A'); ?></div>
                                            <div class="perf-email"><?php echo htmlspecialchars($inspector['email'] ?? ''); ?></div>
                                        </div>
                                        <div class="perf-score"><?php echo number_format((int)($inspector['completed_count'] ?? 0)); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="text-align:center; padding:20px; color:var(--text-muted); font-size:12px;">
                                    <i class="fa-solid fa-user-shield" style="font-size:24px; opacity:0.4; display:block; margin-bottom:6px;"></i>
                                    No inspector accounts yet.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- RECENT ACTIVITY FEED -->
                <div class="rt-chart-card mt-3">
                    <div class="rt-chart-head">
                        <h4><i class="fa-solid fa-clock-rotate-left me-2" style="color:var(--red-accent);"></i> Recent System Activity</h4>
                        <a href="activity_logs.php" style="color:var(--red-accent); font-size:11px; text-decoration:none; font-weight:700;">View All &rarr;</a>
                    </div>
                    <div class="activity-feed">
                        <?php if (!empty($recentActivity)): ?>
                            <?php foreach ($recentActivity as $act): 
                                $actType = $act['activity_type'] ?? 'payment';
                                $actIcon = 'fa-cash-register';
                                if ($actType === 'inspection') $actIcon = 'fa-clipboard-check';
                                elseif ($actType === 'renewal') $actIcon = 'fa-file-circle-exclamation';
                            ?>
                                <div class="activity-item">
                                    <div class="act-icon <?php echo $actType; ?>">
                                        <i class="fa-solid <?php echo $actIcon; ?>"></i>
                                    </div>
                                    <div class="act-body">
                                        <div class="act-desc"><?php echo htmlspecialchars($act['description'] ?? ''); ?></div>
                                        <div class="act-time">
                                            <i class="fa-regular fa-clock me-1"></i>
                                            <?php echo !empty($act['created_at']) ? date('M d, Y h:i A', strtotime($act['created_at'])) : 'N/A'; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="text-align:center; padding:20px; color:var(--text-muted); font-size:12px;">
                                <i class="fa-solid fa-clock-rotate-left" style="font-size:24px; opacity:0.4; display:block; margin-bottom:6px;"></i>
                                No recent activity.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- METRICS GRID -->
                <div class="rt-panel-head mt-4">
                    <div>
                        <h2 class="rt-title">Rental Registration &amp; Compliance Overview</h2>
                    </div>
                </div>

                <div class="rt-metrics-grid">
                    <a href="rentals.php" class="rt-gradient-card c-red">
                        <div class="rt-stat-content">
                            <span class="rt-stat-title">Total Rentals</span>
                            <div class="rt-stat-value"><?php echo number_format($totalRentals); ?></div>
                            <span class="rt-stat-sub"><?php echo number_format($pending); ?> pending &middot; <?php echo number_format($expired); ?> exp</span>
                        </div>
                        <div class="rt-stat-icon"><i class="fa-solid fa-building"></i></div>
                    </a>

                    <a href="collection.php" class="rt-gradient-card c-amber">
                        <div class="rt-stat-content">
                            <span class="rt-stat-title">Remittances</span>
                            <div class="rt-stat-value" style="font-size: 16px;"><?php echo formatCurrency($totalRemittanceAmount); ?></div>
                            <span class="rt-stat-sub"><?php echo number_format($totalAcceptedRemittances); ?> batches</span>
                        </div>
                        <div class="rt-stat-icon"><i class="fa-solid fa-wallet"></i></div>
                    </a>

                    <a href="rentals.php?status=Pending" class="rt-gradient-card c-blue">
                        <div class="rt-stat-content">
                            <span class="rt-stat-title">Pending Approval</span>
                            <div class="rt-stat-value"><?php echo number_format($pending); ?></div>
                            <span class="rt-stat-sub">Rental requests</span>
                        </div>
                        <div class="rt-stat-icon"><i class="fa-solid fa-clock"></i></div>
                    </a>

                    <a href="collection.php?status=Pending" class="rt-gradient-card c-purple">
                        <div class="rt-stat-content">
                            <span class="rt-stat-title">Pending Remit</span>
                            <div class="rt-stat-value"><?php echo number_format($pendingRemittances); ?></div>
                            <span class="rt-stat-sub">Collections</span>
                        </div>
                        <div class="rt-stat-icon"><i class="fa-solid fa-file-shield"></i></div>
                    </a>

                    <a href="inspections.php" class="rt-gradient-card c-orange">
                        <div class="rt-stat-content">
                            <span class="rt-stat-title">Inspections Today</span>
                            <div class="rt-stat-value"><?php echo number_format($inspectionToday); ?></div>
                            <span class="rt-stat-sub">Scheduled visit</span>
                        </div>
                        <div class="rt-stat-icon"><i class="fa-solid fa-clipboard-check"></i></div>
                    </a>

                    <a href="reports.php" class="rt-gradient-card c-pink">
                        <div class="rt-stat-content">
                            <span class="rt-stat-title">Approval Rate</span>
                            <div class="rt-stat-value"><?php echo $approvalRate; ?>%</div>
                            <span class="rt-stat-sub">Permits approved</span>
                        </div>
                        <div class="rt-stat-icon"><i class="fa-solid fa-percent"></i></div>
                    </a>

                    <a href="users.php?role=owner" class="rt-gradient-card c-cyan">
                        <div class="rt-stat-content">
                            <span class="rt-stat-title">Owners</span>
                            <div class="rt-stat-value"><?php echo number_format($totalOwners); ?></div>
                            <span class="rt-stat-sub">Registered accounts</span>
                        </div>
                        <div class="rt-stat-icon"><i class="fa-solid fa-building-user"></i></div>
                    </a>

                    <a href="reports.php" class="rt-gradient-card c-emerald">
                        <div class="rt-stat-content">
                            <span class="rt-stat-title">Compliance</span>
                            <div class="rt-stat-value"><?php echo $approvalRate; ?>%</div>
                            <span class="rt-stat-sub">Overall rate</span>
                        </div>
                        <div class="rt-stat-icon"><i class="fa-solid fa-shield-halved"></i></div>
                    </a>
                </div>

                <div class="rt-chart-card">
                    <div class="rt-chart-head">
                        <h4><i class="fa-solid fa-chart-column me-2" style="color:var(--red-accent);"></i> Monthly Rental Registration Trends</h4>
                        <span class="rt-chart-badge">Registered vs Approved Properties</span>
                    </div>
                    <div style="height:260px; position:relative;">
                        <canvas id="registrationBarChart"></canvas>
                    </div>
                </div>

                <div class="rt-grid rt-grid-2col mt-3">
                    <div class="rt-chart-card mb-0">
                        <div class="rt-chart-head">
                            <h4><i class="fa-solid fa-users-gear me-2" style="color:var(--teal-accent);"></i> User Distribution</h4>
                            <span class="rt-chart-badge">Registered System Users</span>
                        </div>
                        <div style="height:220px; position:relative;">
                            <canvas id="staffDistributionChart"></canvas>
                        </div>
                    </div>
                    <div class="rt-chart-card mb-0">
                        <div class="rt-chart-head">
                            <h4><i class="fa-solid fa-file-circle-exclamation me-2" style="color:var(--violet-accent);"></i> Renewals by Document Type</h4>
                            <span class="rt-chart-badge">Top Requested Permits</span>
                        </div>
                        <div style="height:220px; position:relative;">
                            <canvas id="renewalsByTypeChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="rt-grid rt-grid-3col mt-3">
                    <div class="rt-chart-card mb-0">
                        <div class="rt-chart-head"><h4>Rental Status Breakdown</h4></div>
                        <div style="height:170px; position:relative;">
                            <canvas id="rentalStatusChart"></canvas>
                        </div>
                    </div>

                    <div class="rt-chart-card mb-0">
                        <div class="rt-chart-head"><h4>Remittance Status</h4></div>
                        <div style="height:170px; position:relative;">
                            <canvas id="remittanceStatusChart"></canvas>
                        </div>
                    </div>

                    <div class="rt-chart-card mb-0">
                        <div class="rt-chart-head"><h4>Monthly Remittances (₱)</h4></div>
                        <div style="height:170px; position:relative;">
                            <canvas id="remittanceTrendChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="rt-chart-card mt-3">
                    <div class="rt-chart-head">
                        <h4><i class="fa-solid fa-bolt me-2" style="color:var(--red-accent);"></i> Quick Actions</h4>
                    </div>
                    <div class="quick-actions-grid">
                        <a href="rentals.php" class="quick-action-btn">
                            <i class="fa-solid fa-building"></i>
                            <span>Manage Rentals</span>
                        </a>
                        <a href="payments.php" class="quick-action-btn">
                            <i class="fa-solid fa-cash-register"></i>
                            <span>View Payments</span>
                        </a>
                        <a href="inspections.php" class="quick-action-btn">
                            <i class="fa-solid fa-clipboard-check"></i>
                            <span>Inspections</span>
                        </a>
                        <a href="reports.php" class="quick-action-btn">
                            <i class="fa-solid fa-chart-simple"></i>
                            <span>Generate Reports</span>
                        </a>
                    </div>
                </div>

            </div>

            <footer class="footer">
                <span>&copy; <?php echo date('Y'); ?> <strong style="color:var(--red-accent);">RENTRACK LGU</strong> &middot; Admin Portal</span>
                <span>System Management &amp; Analytics</span>
            </footer>
        </div>
    </div>
</div>

<button id="scrollTopBtn" title="Scroll to top">
    <i class="fa-solid fa-arrow-up"></i>
</button>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
    /* ============================================
       DRAWER / NAV MENU
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

    menuToggle?.addEventListener('click', openDrawer);
    sidebarOverlay?.addEventListener('click', closeDrawer);
    drawerCloseBtn?.addEventListener('click', closeDrawer);

    // ESC key closes drawer
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeDrawer();
            document.querySelectorAll('.nav-item.has-dropdown.open').forEach(function(item) {
                item.classList.remove('open');
            });
        }
    });

    // Dropdown toggle
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

    // Close dropdowns on outside click
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.nav-item.has-dropdown')) {
            document.querySelectorAll('.nav-item.has-dropdown.open').forEach(function(item) {
                item.classList.remove('open');
            });
        }
    });

    // Close drawer when nav link is clicked (mobile)
    mainNavBar.querySelectorAll('.nav-dropdown a, .nav-link:not([data-dropdown])').forEach(function(link) {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 1200) closeDrawer();
        });
    });

    // Close drawer on resize to desktop
    window.addEventListener('resize', function() {
        if (window.innerWidth > 1200) closeDrawer();
    });

    /* ============================================
       SCROLL TO TOP
    ============================================ */
    const scrollTopBtn = document.getElementById('scrollTopBtn');
    window.addEventListener('scroll', () => {
        if (window.scrollY > 300) scrollTopBtn.classList.add('show');
        else scrollTopBtn.classList.remove('show');
    });
    scrollTopBtn.addEventListener('click', () => {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    /* ============================================
       GREETING & CLOCK
    ============================================ */
    let customDate = null;
    let customTime = null;

    function updateTimeBasedGreeting() {
        const now = customDate ? new Date(customDate + 'T' + (customTime || '00:00:00')) : new Date();
        const hours = now.getHours();
        const greetingEl = document.getElementById('dynamicGreeting');
        const adminName = "<?php echo htmlspecialchars($userName); ?>";
        
        let timeGreeting = "Good day";
        if (hours >= 3 && hours < 12) timeGreeting = "Good morning";
        else if (hours >= 12 && hours < 18) timeGreeting = "Good afternoon";
        else timeGreeting = "Good evening";

        if (greetingEl) {
            greetingEl.innerHTML = `${timeGreeting}, ${adminName}! <span class="waving-hand">👋</span>`;
        }
    }
    updateTimeBasedGreeting();

    function rtUpdateClock() {
        const bannerDateEl = document.getElementById('bannerDateString');
        let now = (customDate && customTime) ? new Date(customDate + 'T' + customTime) : new Date();

        if (bannerDateEl) {
            const formattedDateStr = now.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
            const formattedTimeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });
            bannerDateEl.textContent = `${formattedDateStr} · ${formattedTimeStr}`;
        }
    }
    rtUpdateClock();
    setInterval(() => { if (!customDate && !customTime) rtUpdateClock(); }, 1000);

    const editableBannerClock = document.getElementById('editableBannerClock');
    if (editableBannerClock) {
        editableBannerClock.addEventListener('click', () => {
            const currentT = new Date();
            const defaultDate = customDate || currentT.toISOString().split('T')[0];
            const defaultTime = customTime || currentT.toTimeString().split(' ')[0].substring(0, 5);

            Swal.fire({
                title: 'Edit Banner Clock & Date',
                html: `
                    <div style="text-align: left; display: flex; flex-direction: column; gap: 12px; padding: 10px 0;">
                        <div>
                            <label style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 4px; display: block;">Select Date:</label>
                            <input type="date" id="swalEditDate" value="${defaultDate}" style="width: 100%; padding: 10px; background: #f8fafc; color: #0f172a; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none;">
                        </div>
                        <div>
                            <label style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 4px; display: block;">Select Time:</label>
                            <input type="time" id="swalEditTime" value="${defaultTime}" style="width: 100%; padding: 10px; background: #f8fafc; color: #0f172a; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none;">
                        </div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Apply Changes',
                cancelButtonText: 'Reset to Live',
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#64748b',
                background: '#ffffff',
                color: '#0f172a',
                preConfirm: () => {
                    const d = document.getElementById('swalEditDate').value;
                    const t = document.getElementById('swalEditTime').value;
                    if (!d || !t) Swal.showValidationMessage('Please select both date and time');
                    return { date: d, time: t };
                }
            }).then((res) => {
                if (res.isConfirmed) {
                    customDate = res.value.date;
                    customTime = res.value.time + ':00';
                    rtUpdateClock();
                    updateTimeBasedGreeting();
                } else if (res.dismiss === Swal.DismissReason.cancel) {
                    customDate = null;
                    customTime = null;
                    rtUpdateClock();
                    updateTimeBasedGreeting();
                }
            });
        });
    }

    /* ============================================
       NOTIFICATIONS
    ============================================ */
    const notifBtn = document.getElementById('notifBtn');
    const notifDropdown = document.getElementById('notifDropdown');
    const notifBadgeDot = document.getElementById('notifBadgeDot');

    function clearNotificationDot() {
        if (notifBadgeDot) {
            notifBadgeDot.style.opacity = '0';
            setTimeout(() => { notifBadgeDot.style.display = 'none'; }, 300);
        }
        fetch('dashboard.php?action=mark_notif_read');
    }

    if (notifBtn) {
        notifBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            const isOpen = notifDropdown.style.display === 'block';
            notifDropdown.style.display = isOpen ? 'none' : 'block';
            if (!isOpen) clearNotificationDot();
        });
    }

    document.getElementById('clearNotifBadge')?.addEventListener('click', clearNotificationDot);

    document.querySelectorAll('.notif-item').forEach(item => {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            const targetUrl = this.getAttribute('href');
            const notifId = this.getAttribute('data-id');

            if (notifId && notifId > 0) {
                fetch(`dashboard.php?action=mark_notif_read&id=${notifId}`).finally(() => {
                    if (targetUrl) window.location.href = targetUrl;
                });
            } else if (targetUrl) {
                window.location.href = targetUrl;
            }
        });
    });

    /* ============================================
       SEARCH MODAL
    ============================================ */
    const openSearchModalBtn = document.getElementById('openSearchModalBtn');
    document.getElementById('mobileSearchBtn')?.addEventListener('click', () => openSearchModalBtn?.click());

    if (openSearchModalBtn) {
        openSearchModalBtn.addEventListener('click', () => {
            Swal.fire({
                html: `
                    <div style="text-align: left;">
                        <div style="position: relative; margin-bottom: 15px;">
                            <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #64748b; font-size: 15px;"></i>
                            <input type="text" id="swalSearchInput" placeholder="Search by tracking number, business name, or property..." autocomplete="off" style="width: 100%; padding: 12px 16px 12px 42px; background: #f8fafc; color: #0f172a; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 13px; outline: none;">
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
                            resultsEl.innerHTML = `<i class="fa-solid fa-keyboard" style="font-size: 28px; margin-bottom: 8px; color: #cbd5e1;"></i><span style="font-size: 12px;">Type at least 2 characters to search records...</span>`;
                            return;
                        }

                        resultsEl.innerHTML = `<div style="text-align: center; padding: 20px; font-size: 12px; color: #64748b;"><i class="fa-solid fa-spinner fa-spin me-2"></i> Searching records...</div>`;

                        debounceTimer = setTimeout(() => {
                            fetch(`dashboard.php?action=global_search&q=${encodeURIComponent(query)}`)
                                .then(res => res.json())
                                .then(data => {
                                    resultsEl.innerHTML = '';
                                    if (data.length === 0) {
                                        resultsEl.innerHTML = '<div style="padding:20px; font-size:12px; color:#64748b; text-align:center;">No matching records found</div>';
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
    }

    /* ============================================
       PROFILE DROPDOWN
    ============================================ */
    const profileToggle = document.getElementById('profileToggle');
    const profileDropdown = document.getElementById('profileDropdown');

    if (profileToggle) {
        profileToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            profileDropdown.classList.toggle('show');
        });
    }

    document.addEventListener('click', () => {
        if (profileDropdown) profileDropdown.classList.remove('show');
        if (notifDropdown) notifDropdown.style.display = 'none';
    });

    /* ============================================
       LOGOUT
    ============================================ */
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

    /* ============================================
       CHARTS
    ============================================ */
    Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
    Chart.defaults.color = '#475569';

    const monthlyLabels = <?php echo json_encode($monthlyLabels); ?>;
    const monthlyValues = <?php echo json_encode($monthlyValues); ?>;
    const monthlyApproved = <?php echo json_encode($monthlyApproved); ?>;
    const remitMonthlyLabels = <?php echo json_encode($remitMonthlyLabels); ?>;
    const remitMonthlyValues = <?php echo json_encode($remitMonthlyValues); ?>;
    const renewalTypeLabels = <?php echo json_encode($renewalTypeLabels); ?>;
    const renewalTypeValues = <?php echo json_encode($renewalTypeValues); ?>;

    const regBarCtx = document.getElementById('registrationBarChart');
    if (regBarCtx) {
        new Chart(regBarCtx, {
            type: 'bar',
            data: {
                labels: monthlyLabels,
                datasets: [
                    { label: 'Registered Rentals', data: monthlyValues, backgroundColor: '#dc2626', borderRadius: 6, maxBarThickness: 35, barPercentage: 0.6 },
                    { label: 'Approved Permits', data: monthlyApproved, backgroundColor: '#f87171', borderRadius: 6, maxBarThickness: 35, barPercentage: 0.6 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { labels: { color: '#1e293b' } } },
                scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: { color: 'rgba(0, 0, 0, 0.05)' } } }
            }
        });
    }

    const statusCtx = document.getElementById('rentalStatusChart');
    if (statusCtx) {
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Pending', 'Approved', 'Expired'],
                datasets: [{ data: [<?php echo $pending; ?>, <?php echo $approved; ?>, <?php echo $expired; ?>], backgroundColor: ['#fbbf24', '#dc2626', '#991b1b'], borderWidth: 0 }]
            },
            options: { responsive: true, maintainAspectRatio: false, cutout: '70%', plugins: { legend: { position: 'bottom', labels: { color: '#1e293b', font: { size: 10 } } } } }
        });
    }

    const remitStatusCtx = document.getElementById('remittanceStatusChart');
    if (remitStatusCtx) {
        new Chart(remitStatusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Pending', 'Accepted', 'Rejected'],
                datasets: [{ data: [<?php echo $pendingRemittances; ?>, <?php echo $acceptedRemittances; ?>, <?php echo $rejectedRemittances; ?>], backgroundColor: ['#fbbf24', '#059669', '#dc2626'], borderWidth: 0 }]
            },
            options: { responsive: true, maintainAspectRatio: false, cutout: '70%', plugins: { legend: { position: 'bottom', labels: { color: '#1e293b', font: { size: 10 } } } } }
        });
    }

    const staffDistCtx = document.getElementById('staffDistributionChart');
    if (staffDistCtx) {
        new Chart(staffDistCtx, {
            type: 'bar',
            data: {
                labels: ['Owners', 'Staff', 'Inspectors'],
                datasets: [{ label: 'Registered Users', data: [<?php echo $totalOwners; ?>, <?php echo $totalStaff; ?>, <?php echo $totalInspectors; ?>], backgroundColor: ['#0891b2', '#dc2626', '#db2777'], borderRadius: 8, maxBarThickness: 35 }]
            },
            options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, grid: { color: 'rgba(0, 0, 0, 0.05)' } }, y: { grid: { display: false } } } }
        });
    }

    const renewalsByTypeCtx = document.getElementById('renewalsByTypeChart');
    if (renewalsByTypeCtx) {
        const typeColors = ['#7c3aed', '#dc2626', '#0891b2', '#d97706', '#059669', '#db2777'];
        
        new Chart(renewalsByTypeCtx, {
            type: 'bar',
            data: {
                labels: renewalTypeLabels,
                datasets: [{
                    label: 'Requests',
                    data: renewalTypeValues,
                    backgroundColor: typeColors.slice(0, renewalTypeLabels.length),
                    borderRadius: 8,
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
                        titleColor: '#0f172a',
                        bodyColor: '#334155',
                        borderColor: '#7c3aed',
                        borderWidth: 1,
                        padding: 10,
                        displayColors: false,
                        callbacks: {
                            label: function(context) {
                                return context.parsed.x + ' request(s)';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        grid: { color: 'rgba(0, 0, 0, 0.05)' },
                        ticks: { color: '#475569', font: { size: 10 }, precision: 0 }
                    },
                    y: {
                        grid: { display: false },
                        ticks: { color: '#475569', font: { size: 10 } }
                    }
                }
            }
        });
    }

    const remitTrendCtx = document.getElementById('remittanceTrendChart');
    if (remitTrendCtx) {
        new Chart(remitTrendCtx, {
            type: 'bar',
            data: {
                labels: remitMonthlyLabels,
                datasets: [{ label: 'Remitted Amount (₱)', data: remitMonthlyValues, backgroundColor: '#0891b2', borderRadius: 6, maxBarThickness: 35 }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: { color: 'rgba(0, 0, 0, 0.05)' } } } }
        });
    }
</script>

</body>
</html>