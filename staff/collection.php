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
                    $badgeBg = '#f1f5f9'; $badgeColor = '#64748b';
                    if ($status === 'Pending') { $badgeBg = '#fef3c7'; $badgeColor = '#d97706'; }
                    elseif ($status === 'Approved') { $badgeBg = '#dcfce7'; $badgeColor = '#15803d'; }
                    elseif ($status === 'Rejected') { $badgeBg = '#fee2e2'; $badgeColor = '#dc2626'; }

                    echo '<a href="view_rental.php?id=' . $row['id'] . '" style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; color: #0f172a; text-decoration: none; gap: 10px;">';
                    echo '<div style="display: flex; flex-direction: column; gap: 4px; min-width: 0; flex: 1;">';
                    echo '<div style="font-weight: 600; font-size: 13px; color: #dc2626; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">' . htmlspecialchars($row['tracking_no']) . ' • ' . htmlspecialchars($row['business_name']) . '</div>';
                    echo '<div style="font-size: 11px; color: #475569;"><i class="fa-solid fa-user-shield" style="font-size: 11px; margin-right: 4px;"></i> Owner: ' . htmlspecialchars($row['owner_name'] ?? 'N/A') . '</div>';
                    echo '</div>';
                    echo '<span style="padding: 4px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; background: ' . $badgeBg . '; color: ' . $badgeColor . '; text-transform: uppercase; flex-shrink: 0;">' . htmlspecialchars($status) . '</span>';
                    echo '</a>';
                }
                echo '</div>';
            } else {
                echo '<div style="text-align: center; padding: 30px; color: #64748b; font-size: 13px;"><i class="fa-solid fa-folder-open" style="font-size: 24px; margin-bottom: 8px; display: block; opacity: 0.5;"></i> No matching results found.</div>';
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
// HANDLE PAYMENT VERIFICATION
// ==========================
$verifySuccess = false;
$verifyError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'verify_payment') {
        $paymentId = (int) $_POST['payment_id'];

        if ($paymentId <= 0) {
            $verifyError = 'Invalid payment ID.';
        } else {
            $stmt = $conn->prepare("
                SELECT p.*, r.business_name, r.user_id as owner_id, u.fullname as owner_name
                FROM payments p
                INNER JOIN rentals r ON p.rental_id = r.id
                INNER JOIN users u ON r.user_id = u.id
                WHERE p.id = :id
            ");
            $stmt->execute([':id' => $paymentId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$payment) {
                $verifyError = 'Payment not found.';
            } else {
                try {
                    $updateStmt = $conn->prepare("UPDATE payments SET status = 'Verified' WHERE id = :pid");
                    if ($updateStmt->execute([':pid' => $paymentId])) {
                        $verifySuccess = true;
                        if (tableExists($conn, 'notifications')) {
                            $notifInsert = $conn->prepare("
                                INSERT INTO notifications (user_id, title, message, notification_type, related_id, is_read, status, created_at)
                                VALUES (:uid, :title, :msg, 'Payment', :rel_id, FALSE, 'Unread', CURRENT_TIMESTAMP)
                            ");
                            $notifTitle = 'Payment Verified';
                            $notifMessage = "Your payment of ₱" . number_format($payment['amount_paid'], 2) . " for '" . $payment['business_name'] . "' (OR#: " . $payment['or_number'] . ") has been verified by LGU Staff.";
                            $notifInsert->execute([
                                ':uid' => $payment['owner_id'],
                                ':title' => $notifTitle,
                                ':msg' => $notifMessage,
                                ':rel_id' => $paymentId
                            ]);
                        }
                    } else {
                        $verifyError = 'Failed to verify payment. Please try again.';
                    }
                } catch (PDOException $e) {
                    $verifyError = 'Failed to verify payment: ' . safe_error($e);
                }
            }
        }
    }

    if ($_POST['action'] === 'reject_payment') {
        $paymentId = (int) $_POST['payment_id'];
        $rejectReason = isset($_POST['reject_reason']) ? trim($_POST['reject_reason']) : 'Payment rejected by LGU Staff.';

        if ($paymentId <= 0) {
            $verifyError = 'Invalid payment ID.';
        } else {
            $stmt = $conn->prepare("
                SELECT p.*, r.business_name, r.user_id as owner_id, u.fullname as owner_name
                FROM payments p
                INNER JOIN rentals r ON p.rental_id = r.id
                INNER JOIN users u ON r.user_id = u.id
                WHERE p.id = :id
            ");
            $stmt->execute([':id' => $paymentId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$payment) {
                $verifyError = 'Payment not found.';
            } else {
                try {
                    $updateStmt = $conn->prepare("
                        UPDATE payments 
                        SET status = 'Rejected', 
                            remarks = CONCAT(COALESCE(remarks, ''), ' | Rejected: ', :reason)
                        WHERE id = :pid
                    ");
                    if ($updateStmt->execute([':reason' => $rejectReason, ':pid' => $paymentId])) {
                        $verifySuccess = true;
                        if (tableExists($conn, 'notifications')) {
                            $notifInsert = $conn->prepare("
                                INSERT INTO notifications (user_id, title, message, notification_type, related_id, is_read, status, created_at)
                                VALUES (:uid, :title, :msg, 'Payment', :rel_id, FALSE, 'Unread', CURRENT_TIMESTAMP)
                            ");
                            $notifTitle = 'Payment Rejected';
                            $notifMessage = "Your payment for '" . $payment['business_name'] . "' (OR#: " . $payment['or_number'] . ") has been rejected. Reason: " . $rejectReason;
                            $notifInsert->execute([
                                ':uid' => $payment['owner_id'],
                                ':title' => $notifTitle,
                                ':msg' => $notifMessage,
                                ':rel_id' => $paymentId
                            ]);
                        }
                    } else {
                        $verifyError = 'Failed to reject payment. Please try again.';
                    }
                } catch (PDOException $e) {
                    $verifyError = 'Failed to reject payment: ' . safe_error($e);
                }
            }
        }
    }
}

// ==========================
// SEARCH & FILTER & TABS
// ==========================
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$paymentFilter = isset($_GET['payment_status']) ? trim($_GET['payment_status']) : 'Pending';
$activeTab = isset($_GET['tab']) ? trim($_GET['tab']) : 'assessments';

$assessmentsList = [];
if (tableExists($conn, 'rentals')) {
    try {
        $sqlAssess = "
            SELECT r.id, r.tracking_no, r.business_name, r.business_address, r.status, u.fullname AS owner_name,
            COALESCE((SELECT SUM(f.amount) FROM fees f WHERE f.rental_id = r.id), 0) AS total_fees,
            COALESCE((SELECT SUM(p.amount_paid) FROM payments p WHERE p.rental_id = r.id AND p.status::text = 'Verified'), 0) AS total_paid
            FROM rentals r
            LEFT JOIN users u ON r.user_id = u.id
            WHERE r.is_deleted = FALSE
            ORDER BY r.business_name ASC
        ";
        $stmtAss = $conn->query($sqlAssess);
        if ($stmtAss) $assessmentsList = $stmtAss->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $assessmentsList = []; }
}

$pendingPayments = [];
$totalPendingPayments = 0;
$totalVerifiedPayments = 0;
$totalRejectedPayments = 0;
$totalCollectedToday = 0;

$hasRentalDeleted = columnExists($conn, 'rentals', 'is_deleted');
$rentalJoinSql = $hasRentalDeleted ? "INNER JOIN rentals r ON p.rental_id = r.id WHERE r.is_deleted = FALSE AND" : "INNER JOIN rentals r ON p.rental_id = r.id WHERE";

if (tableExists($conn, 'payments')) {
    $countVerified = $conn->query("SELECT COUNT(*) FROM payments p $rentalJoinSql p.status::text = 'Verified'");
    if ($countVerified) $totalVerifiedPayments = (int) $countVerified->fetchColumn();

    $countRejected = $conn->query("SELECT COUNT(*) FROM payments p $rentalJoinSql p.status::text = 'Rejected'");
    if ($countRejected) $totalRejectedPayments = (int) $countRejected->fetchColumn();

    $countPendingPay = $conn->query("SELECT COUNT(*) FROM payments p $rentalJoinSql p.status::text = 'Pending'");
    if ($countPendingPay) $totalPendingPayments = (int) $countPendingPay->fetchColumn();

    $todayQuery = $conn->query("
        SELECT COALESCE(SUM(p.amount_paid), 0) 
        FROM payments p
        $rentalJoinSql p.status::text = 'Verified' 
        AND DATE(p.created_at) = CURRENT_DATE
    ");
    if ($todayQuery) $totalCollectedToday = (float) $todayQuery->fetchColumn();
}

if (tableExists($conn, 'payments')) {
    $where = $hasRentalDeleted ? "r.is_deleted = FALSE" : "1=1";
    $params = [];

    if (!empty($paymentFilter) && $paymentFilter !== 'All') {
        $where .= " AND p.status::text = :pstatus";
        $params[':pstatus'] = $paymentFilter;
    }

    if (!empty($search)) {
        $where .= " AND (r.business_name ILIKE :search OR r.tracking_no ILIKE :search OR p.or_number ILIKE :search OR u.fullname ILIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    $sql = "
        SELECT p.*, r.business_name, r.tracking_no, u.fullname AS owner_name,
               u.email AS owner_email, u.id AS owner_id
        FROM payments p
        INNER JOIN rentals r ON p.rental_id = r.id
        INNER JOIN users u ON r.user_id = u.id
        WHERE $where
        ORDER BY 
            CASE WHEN p.status::text = 'Pending' THEN 0 ELSE 1 END,
            p.created_at DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $pendingPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pendingRenewalsCount = 0;
if (tableExists($conn, 'permit_renewals')) {
    $pendRenewalsQuery = $conn->query("SELECT COUNT(*) FROM permit_renewals WHERE status::text = 'Pending'");
    if ($pendRenewalsQuery) $pendingRenewalsCount = (int) $pendRenewalsQuery->fetchColumn();
}

// Financial counts for navbar
$pendingPaymentsBadge = 0; $verifiedPaymentsBadge = 0; $totalPaymentsSum = 0;
$pendingRemittances = 0; $totalRemittances = 0;

if (tableExists($conn, 'payments')) {
    $q1 = $conn->query("SELECT COUNT(*) FROM payments WHERE status = 'Pending'");
    if ($q1) $pendingPaymentsBadge = (int)$q1->fetchColumn();
    $q2 = $conn->query("SELECT COUNT(*) FROM payments WHERE status IN ('Paid', 'Verified')");
    if ($q2) $verifiedPaymentsBadge = (int)$q2->fetchColumn();
    $q3 = $conn->query("SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE status IN ('Paid', 'Verified')");
    if ($q3) $totalPaymentsSum = (float)$q3->fetchColumn();
}
if (tableExists($conn, 'remittances')) {
    $q4 = $conn->query("SELECT COUNT(*) FROM remittances WHERE status = 'Pending'");
    if ($q4) $pendingRemittances = (int)$q4->fetchColumn();
    $q5 = $conn->query("SELECT COALESCE(SUM(total_amount), 0) FROM remittances WHERE status = 'Accepted'");
    if ($q5) $totalRemittances = (float)$q5->fetchColumn();
}

$notificationCount = 0;
$notifications = [];
if (tableExists($conn, 'notifications')) {
    $notifCountStmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE (user_id = :sid OR user_id = 0 OR user_id IS NULL) AND is_read = FALSE");
    $notifCountStmt->execute([':sid' => $staffId]);
    $notificationCount = (int) $notifCountStmt->fetchColumn();

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

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function formatCurrency($amount) { return '₱' . number_format((float)$amount, 2); }

function getStatusBadge($status) {
    $status = strtolower(trim((string)$status));
    switch ($status) {
        case 'pending':
            return '<span class="badge badge-warning"><i class="fa-solid fa-clock"></i> Pending</span>';
        case 'verified':
        case 'paid':
        case 'accepted':
            return '<span class="badge badge-success"><i class="fa-solid fa-check-circle"></i> ' . ucfirst($status) . '</span>';
        case 'rejected':
            return '<span class="badge badge-danger"><i class="fa-solid fa-times-circle"></i> Rejected</span>';
        default:
            return '<span class="badge badge-secondary">' . h($status) . '</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover" />
    <meta name="theme-color" content="#dc2626" />
    <title>Collections | RENTRACK LGU</title>

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
            height: 100%; font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: var(--bg-primary); color: var(--text-primary);
            overflow-x: hidden; -webkit-text-size-adjust: 100%;
            -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale;
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
            padding-left: var(--safe-left); padding-right: var(--safe-right);
        }

        /* OFFICIAL HEADER */
        .official-header {
            width: 100%; display: flex; align-items: center; justify-content: space-between;
            background: linear-gradient(90deg, #991b1b, #dc2626 55%, #991b1b);
            border-bottom: 2px solid #7f1d1d;
            padding: 6px 20px; padding-top: calc(6px + var(--safe-top));
            gap: 20px; position: relative; z-index: 110;
            margin: 0 !important;
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
            position: relative; flex-shrink: 0; text-decoration: none;
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

        /* STATS */
        .stats-grid { display: grid; grid-template-columns: 1fr; gap: 12px; margin-bottom: 20px; }
        @media (min-width: 576px) { .stats-grid { grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 24px; } }

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

        .stat-card.bg-amber { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .stat-card.bg-green { background: linear-gradient(135deg, #16a34a, #15803d); }
        .stat-card.bg-red { background: linear-gradient(135deg, #dc2626, #991b1b); }

        .stat-card .label {
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.5px; color: rgba(255,255,255,0.9);
            margin-bottom: 4px; display: block;
        }
        .stat-card .value { font-size: 22px; font-weight: 800; color: #fff; }
        @media (min-width: 768px) { .stat-card .value { font-size: 26px; } }
        .stat-card i { font-size: 22px; color: rgba(255,255,255,0.9); z-index: 1; }
        @media (min-width: 768px) { .stat-card i { font-size: 24px; } }

        /* TABS */
        .collection-tabs {
            display: flex; gap: 8px; margin-bottom: 20px;
            border-bottom: 1px solid #e2e8f0; padding-bottom: 12px;
            overflow-x: auto; scrollbar-width: none;
        }
        .collection-tabs::-webkit-scrollbar { display: none; }
        .tab-btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 11px 16px; background: #ffffff;
            border: 1px solid #e2e8f0; border-radius: var(--r-lg);
            color: #475569; font-size: 12.5px; font-weight: 600;
            text-decoration: none; white-space: nowrap;
            transition: all 0.2s;
        }
        .tab-btn:hover { border-color: var(--primary-red); color: var(--primary-red); }
        .tab-btn.active {
            background: var(--primary-red); color: #fff;
            border-color: var(--primary-red);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.2);
        }
        .tab-btn .tab-badge {
            background: rgba(255,255,255,0.3); color: #fff;
            font-size: 10px; padding: 2px 6px; border-radius: 99px;
        }
        .tab-btn:not(.active) .tab-badge { background: var(--red-soft); color: var(--primary-red); }

        /* PANEL */
        .panel {
            background: #ffffff; border: 1px solid #e2e8f0;
            border-radius: var(--radius); box-shadow: var(--sh-sm);
            overflow: hidden; margin-bottom: 24px;
        }
        .panel-head {
            padding: 16px 22px; border-bottom: 1px solid #e2e8f0;
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 12px;
        }
        .panel-title {
            font-size: 14.5px; font-weight: 700;
            color: var(--text-primary);
            display: flex; align-items: center; gap: 9px;
        }
        .panel-title i { color: var(--primary-red); font-size: 14px; }
        .panel-meta { font-size: 12px; color: var(--text-muted); }
        .panel-meta strong { color: var(--text-primary); font-weight: 700; }

        /* TABLE */
        .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .table-wrap::-webkit-scrollbar { height: 6px; }
        .table-wrap::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

        .table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 800px; margin-bottom: 0; }
        .table thead th {
            text-align: left; padding: 12px 14px;
            font-size: 10.5px; font-weight: 700;
            letter-spacing: 0.06em; text-transform: uppercase;
            color: var(--text-muted); background: #f1f5f9;
            border-bottom: 1px solid #e2e8f0; white-space: nowrap;
        }
        .table tbody td {
            padding: 16px 14px;
            border-bottom: 1px solid #f1f5f9;
            color: var(--text-secondary);
            vertical-align: middle; font-weight: 500;
        }
        .table tbody tr { transition: background 0.15s; }
        .table tbody tr:hover { background: #fff1f2; }
        .table tbody tr:last-child td { border-bottom: none; }

        .tracking {
            font-family: 'JetBrains Mono', monospace;
            font-weight: 700; font-size: 11.5px;
            color: var(--primary-red); background: var(--red-soft);
            padding: 4px 10px; border-radius: 6px;
            display: inline-block; border: 1px solid var(--primary-red-light);
        }

        .badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 10px; border-radius: 40px;
            font-size: 10px; font-weight: 700;
            text-transform: uppercase; white-space: nowrap;
        }
        .badge-warning { background: var(--warn-bg); color: var(--warn); border: 1px solid var(--warn-bd); }
        .badge-success { background: var(--ok-bg); color: #047857; border: 1px solid var(--ok-bd); }
        .badge-danger { background: var(--danger-bg); color: var(--danger); border: 1px solid var(--danger-bd); }
        .badge-info { background: #ccfbf1; color: #0f766e; border: 1px solid #99f6e4; }
        .badge-secondary { background: #f1f5f9; color: var(--text-muted); border: 1px solid #e2e8f0; }

        .btn-verify {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 7px 14px; border-radius: 8px;
            background: var(--red-soft); color: var(--primary-red);
            border: 1px solid var(--primary-red-light);
            font-size: 12px; font-weight: 600; cursor: pointer;
            transition: var(--transition); font-family: inherit;
        }
        .btn-verify:hover { background: var(--primary-red); color: #fff; border-color: var(--primary-red); }

        .btn-reject {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 7px 14px; border-radius: 8px;
            background: var(--danger-bg); color: var(--danger);
            border: 1px solid var(--danger-bd);
            font-size: 12px; font-weight: 600; cursor: pointer;
            transition: var(--transition); font-family: inherit;
        }
        .btn-reject:hover { background: #ef4444; color: #fff; }

        .action-cell { display: flex; gap: 6px; align-items: center; justify-content: center; flex-wrap: wrap; }

        .empty-state { padding: 40px 16px !important; text-align: center; color: var(--text-muted); }
        .empty-state i { font-size: 48px; color: #cbd5e1; display: block; margin-bottom: 16px; }
        .empty-state h4 { font-size: 16px; font-weight: 700; color: var(--text-primary); margin-bottom: 6px; }
        .empty-state p { font-size: 13px; margin: 0; }

        .footer {
            margin-top: 24px; padding-top: 16px;
            border-top: 1px solid #e2e8f0;
            display: flex; justify-content: space-between; align-items: center;
            font-size: 12px; color: #64748b;
            flex-wrap: wrap; gap: 10px;
        }
        .footer .brand { color: var(--primary-red); font-weight: 700; }

        .modal-content {
            background: #ffffff !important;
            border: 1px solid #e2e8f0 !important;
            border-radius: var(--r-xl) !important;
            box-shadow: 0 24px 48px -12px rgba(15, 23, 42, 0.18) !important;
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

            .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .stat-card { padding: 12px 14px; }
            .stat-card .label { font-size: 9.5px; }
            .stat-card .value { font-size: 18px; }
            .stat-card i { font-size: 18px; }

            .panel { border-radius: 16px; }
            .panel-head { padding: 14px 16px; }
            .panel-title { font-size: 13.5px; }

            .footer { flex-direction: column; text-align: center; gap: 6px; font-size: 10.5px; }

            .modal-dialog { margin: 0; min-height: 100vh; min-height: 100dvh; align-items: stretch; }
            .modal-content { border-radius: 0 !important; min-height: 100vh; min-height: 100dvh; }

            /* Mobile card table */
            .table-wrap { overflow-x: visible; }
            .table { min-width: 100% !important; }
            .table thead { display: none; }
            .table tbody, .table tr, .table td { display: block; width: 100%; }
            .table tr {
                background: #ffffff !important;
                border: 1px solid #e2e8f0 !important;
                border-left: 3px solid var(--primary-red) !important;
                border-radius: var(--r-lg);
                margin: 0 0 12px;
                padding: 14px; box-shadow: var(--sh-xs);
            }
            .table tbody tr:hover { background: #ffffff !important; }
            .table td {
                display: flex; align-items: center; justify-content: space-between;
                text-align: right;
                padding: 8px 0 !important;
                border-bottom: 1px dashed #eef1f6 !important;
                white-space: normal; gap: 12px;
            }
            .table td:last-child {
                border-bottom: none !important;
                flex-direction: column; align-items: stretch;
                padding-top: 14px !important;
                padding-bottom: 2px !important;
            }
            .table td::before {
                content: attr(data-label);
                font-weight: 700; font-size: 10px;
                color: var(--text-muted);
                text-transform: uppercase; letter-spacing: 0.05em;
                text-align: left; flex-shrink: 0;
            }
            .table td:last-child::before { display: none; }
            .action-cell { justify-content: stretch; }
            .action-cell .btn-verify,
            .action-cell .btn-reject { flex: 1; }
        }

        @media (max-width: 480px) {
            .official-header { padding: 5px 8px; padding-top: calc(5px + var(--safe-top)); }
            .top-navbar { padding: 8px 10px; }
            .content { padding: 12px 10px 22px; padding-bottom: calc(22px + var(--safe-bottom)); }
            .page-header { padding: 14px 16px; border-radius: 12px; }
            .page-header h1 { font-size: 16px; }
            .page-header p { font-size: 11.5px; }
            .stat-card { padding: 12px 14px; }
            .stat-card .value { font-size: 18px; }
            .footer { font-size: 10.5px; }
        }

        @media (max-width: 767px) {
            input, select, textarea { font-size: 16px !important; }
            .search-container input { font-size: 16px !important; }
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
                                <button type="button" class="nav-link" data-dropdown>
                                    <i class="fa-solid fa-clipboard-check nav-ico"></i> Inspections
                                    <i class="fa-solid fa-chevron-down chevron"></i>
                                </button>
                                <div class="nav-dropdown">
                                    <a href="inspections.php"><i class="fa-solid fa-clipboard-check"></i> My Inspections</a>
                                    <a href="inspection_schedule.php"><i class="fa-solid fa-calendar-days"></i> Inspection Schedule</a>
                                </div>
                            </li>

                            <li class="nav-item has-dropdown">
                                <button type="button" class="nav-link active" data-dropdown>
                                    <i class="fa-solid fa-money-check-dollar nav-ico"></i> Financials
                                    <?php if ($pendingRemittances > 0): ?>
                                        <span class="menu-badge"><?php echo $pendingRemittances; ?></span>
                                    <?php endif; ?>
                                    <i class="fa-solid fa-chevron-down chevron"></i>
                                </button>
                                <div class="nav-dropdown">
                                    <a href="payments.php"><i class="fa-solid fa-money-check-dollar"></i> Payments</a>
                                    <a href="collection.php?tab=payments"><i class="fa-solid fa-money-bill-wave"></i> Collections</a>
                                    <a href="collection.php?tab=remittances">
                                        <i class="fa-solid fa-file-shield"></i> Remittances
                                        <?php if ($pendingRemittances > 0): ?>
                                            <span class="menu-badge"><?php echo $pendingRemittances; ?></span>
                                        <?php endif; ?>
                                    </a>
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
                                            <strong><?php echo h($n['title'] ?? 'Notification'); ?></strong>
                                            <p><?php echo h($n['message'] ?? ''); ?></p>
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
                                    <div class="name"><?php echo h($staffName); ?></div>
                                    <div class="role"><?php echo h($staffRole); ?></div>
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
                    <strong>Collections &amp; Assessments</strong>
                </div>

                <!-- PAGE HEADER -->
                <div class="page-header">
                    <div>
                        <h1><i class="fa-solid fa-cash-register"></i> Establishment Assessments &amp; Collections</h1>
                        <p>Monitor establishment fees and verify owner payments securely.</p>
                    </div>
                    <div class="date-badge">
                        <i class="fa-regular fa-calendar"></i> <?php echo date("F d, Y"); ?>
                    </div>
                </div>

                <!-- STATS -->
                <div class="stats-grid">
                    <div class="stat-card bg-amber">
                        <div>
                            <span class="label">Pending Payments</span>
                            <div class="value"><?php echo number_format($totalPendingPayments); ?></div>
                        </div>
                        <i class="fa-solid fa-clock"></i>
                    </div>
                    <div class="stat-card bg-green">
                        <div>
                            <span class="label">Verified</span>
                            <div class="value"><?php echo number_format($totalVerifiedPayments); ?></div>
                        </div>
                        <i class="fa-solid fa-check-circle"></i>
                    </div>
                    <div class="stat-card bg-red">
                        <div>
                            <span class="label">Collected Today</span>
                            <div class="value" style="font-size: 16px;">₱<?php echo number_format($totalCollectedToday, 2); ?></div>
                        </div>
                        <i class="fa-solid fa-coins"></i>
                    </div>
                </div>

                <!-- TABS -->
                <div class="collection-tabs">
                    <a href="collection.php?tab=assessments" class="tab-btn <?php echo $activeTab === 'assessments' ? 'active' : ''; ?>">
                        <i class="fa-solid fa-store"></i> Establishment Assessments
                    </a>
                    <a href="collection.php?tab=payments" class="tab-btn <?php echo $activeTab === 'payments' ? 'active' : ''; ?>">
                        <i class="fa-solid fa-wallet"></i> Owner Payments
                        <?php if ($totalPendingPayments > 0): ?>
                            <span class="tab-badge"><?php echo $totalPendingPayments; ?></span>
                        <?php endif; ?>
                    </a>
                </div>

                <?php if ($activeTab === 'assessments'): ?>
                <div class="panel">
                    <div class="panel-head">
                        <div class="panel-title">
                            <i class="fa-solid fa-file-invoice-dollar"></i> List of Establishment Assessments
                        </div>
                        <div class="panel-meta">Total: <strong><?php echo count($assessmentsList); ?></strong> establishment<?php echo count($assessmentsList) === 1 ? '' : 's'; ?></div>
                    </div>

                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Tracking No</th>
                                    <th>Business Name</th>
                                    <th>Owner</th>
                                    <th>Total Fees</th>
                                    <th>Amount Paid</th>
                                    <th>Remaining Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($assessmentsList)): ?>
                                    <?php foreach ($assessmentsList as $item):
                                        $totalFees = floatval($item['total_fees'] ?? 0);
                                        $totalPaid = floatval($item['total_paid'] ?? 0);
                                        $balance = max(0, $totalFees - $totalPaid);
                                    ?>
                                        <tr>
                                            <td data-label="Tracking No"><strong class="tracking"><?php echo h($item['tracking_no']); ?></strong></td>
                                            <td data-label="Business Name"><?php echo h($item['business_name']); ?></td>
                                            <td data-label="Owner"><?php echo h($item['owner_name'] ?? '—'); ?></td>
                                            <td data-label="Total Fees"><?php echo formatCurrency($totalFees); ?></td>
                                            <td data-label="Amount Paid"><?php echo formatCurrency($totalPaid); ?></td>
                                            <td data-label="Remaining Balance" style="font-weight: 700; color: <?php echo $balance > 0 ? '#dc2626' : '#15803d'; ?>;">
                                                <?php echo formatCurrency($balance); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="6">
                                        <div class="empty-state">
                                            <i class="fa-solid fa-store"></i>
                                            <h4>No establishments found</h4>
                                            <p>Approved rentals will appear here.</p>
                                        </div>
                                    </td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($activeTab === 'payments'): ?>
                <div class="panel">
                    <div class="panel-head">
                        <div class="panel-title">
                            <i class="fa-solid fa-list"></i> Owner Rental Payments
                        </div>
                        <div class="panel-meta">Total: <strong><?php echo count($pendingPayments); ?></strong> record<?php echo count($pendingPayments) === 1 ? '' : 's'; ?></div>
                    </div>

                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>OR Number</th>
                                    <th>Owner</th>
                                    <th>Business</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th style="text-align:center;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($pendingPayments)): ?>
                                    <?php foreach ($pendingPayments as $payment): ?>
                                        <tr>
                                            <td data-label="OR Number"><strong class="tracking"><?php echo h($payment['or_number']); ?></strong></td>
                                            <td data-label="Owner"><?php echo h($payment['owner_name']); ?></td>
                                            <td data-label="Business"><?php echo h($payment['business_name']); ?></td>
                                            <td data-label="Amount" style="font-weight:600; color:var(--primary-red);"><?php echo formatCurrency($payment['amount_paid']); ?></td>
                                            <td data-label="Status"><?php echo getStatusBadge($payment['status']); ?></td>
                                            <td data-label="Action">
                                                <?php if (strtolower($payment['status']) === 'pending'): ?>
                                                    <div class="action-cell">
                                                        <button class="btn-verify" onclick="verifyPayment(<?php echo (int)$payment['id']; ?>)"><i class="fa-solid fa-check"></i> Verify</button>
                                                        <button class="btn-reject" onclick="rejectPayment(<?php echo (int)$payment['id']; ?>)"><i class="fa-solid fa-xmark"></i> Reject</button>
                                                    </div>
                                                <?php else: ?>
                                                    <span style="color:#94a3b8; font-size:11px;">Processed</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="6">
                                        <div class="empty-state">
                                            <i class="fa-solid fa-receipt"></i>
                                            <h4>No payment records found</h4>
                                            <p>Owner payments will appear here.</p>
                                        </div>
                                    </td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

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
                fetch('collection.php?ajax_action=read_notif')
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
                    fetch('collection.php?ajax_action=search&q=' + encodeURIComponent(query))
                        .then(res => res.text())
                        .then(html => {
                            if (html.trim() !== '') modalSearchResults.innerHTML = html;
                            else modalSearchResults.innerHTML = `<div style="text-align: center; padding: 30px; color: #64748b; font-size: 13px;"><i class="fa-solid fa-folder-open" style="font-size: 24px; margin-bottom: 8px; display: block; opacity: 0.5;"></i> No matching results found.</div>`;
                        });
                }, 250);
            });
        }

        /* VERIFY / REJECT */
        function confirmLogout() {
            Swal.fire({
                title: 'Log out?',
                text: 'Are you sure you want to log out of the system?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Logout',
                cancelButtonText: 'Cancel'
            }).then((res) => {
                if (res.isConfirmed) document.getElementById('logoutForm').submit();
            });
        }
        document.getElementById('profileLogoutBtn')?.addEventListener('click', (e) => { e.preventDefault(); confirmLogout(); });

        function verifyPayment(paymentId) {
            Swal.fire({
                title: 'Verify Payment?',
                text: 'This will mark the payment as verified.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Verify',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="action" value="verify_payment">
                        <input type="hidden" name="payment_id" value="${paymentId}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        function rejectPayment(paymentId) {
            Swal.fire({
                title: 'Reject Payment?',
                input: 'textarea',
                inputLabel: 'Reason for rejection',
                inputPlaceholder: 'Enter reason...',
                inputAttributes: { 'aria-label': 'Enter rejection reason' },
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Reject',
                cancelButtonText: 'Cancel',
                inputValidator: (value) => {
                    if (!value) return 'Please provide a reason for rejection.';
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="action" value="reject_payment">
                        <input type="hidden" name="payment_id" value="${paymentId}">
                        <input type="hidden" name="reject_reason" value="${result.value.replace(/"/g, '&quot;')}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        <?php if ($verifySuccess): ?>
            Swal.fire({ title: 'Success!', text: 'Payment processed successfully.', icon: 'success', confirmButtonColor: '#dc2626', timer: 2000 });
        <?php elseif (!empty($verifyError)): ?>
            Swal.fire({ title: 'Error!', text: '<?php echo addslashes($verifyError); ?>', icon: 'error', confirmButtonColor: '#dc2626' });
        <?php endif; ?>
    </script>
</body>
</html>