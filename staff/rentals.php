<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();

require_once '../config/db.php';
require_once 'includes/auth.php';

// ==========================
// MARK RENTAL NOTIFICATIONS AS READ
// ==========================
if (isset($_GET['read_rentals']) && $_GET['read_rentals'] == '1') {
    try {
        $staffId = (int)($_SESSION['user_id'] ?? 0);
        $_SESSION['rentals_last_read'] = date('Y-m-d H:i:s');
        $markReadStmt = $conn->prepare("
            UPDATE notifications 
            SET is_read = TRUE 
            WHERE user_id = :staff_id 
              AND notification_type = 'Rental' 
              AND is_read = FALSE
        ");
        $markReadStmt->execute([':staff_id' => $staffId]);
    } catch (PDOException $e) {
        error_log("Mark Read Error: " . $e->getMessage());
    }
}

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
// 🚨 REJECT RENTAL LOGIC (POST)
// ==========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject_rental') {
    $rentalId = (int)($_POST['rental_id'] ?? 0);
    $reason   = trim($_POST['rejection_reason'] ?? '');
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        $_SESSION['error'] = 'Invalid security token. Please try again.';
        header("Location: rentals.php");
        exit();
    }

    if ($rentalId > 0 && !empty($reason)) {
        try {
            // Check if rejection_reason column exists
            $hasReasonCol = columnExists($conn, 'rentals', 'rejection_reason');
            
            if ($hasReasonCol) {
                $stmt = $conn->prepare("UPDATE rentals SET status = 'Rejected', rejection_reason = :reason WHERE id = :id AND is_deleted = FALSE");
                $stmt->execute([':reason' => $reason, ':id' => $rentalId]);
            } else {
                $stmt = $conn->prepare("UPDATE rentals SET status = 'Rejected' WHERE id = :id AND is_deleted = FALSE");
                $stmt->execute([':id' => $rentalId]);
            }

            // ✅ LOG THE ACTIVITY (Fixed to match your database schema)
            if (tableExists($conn, 'activity_logs')) {
                $staffRole = $_SESSION['role'] ?? 'Staff';
                $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $logMsg = "Rejected rental ID #{$rentalId}. Reason: {$reason}";

                $logStmt = $conn->prepare("
                    INSERT INTO activity_logs (user_id, activity, role, ip_address, created_at) 
                    VALUES (:user_id, :activity, :role, :ip_address, NOW())
                ");
                $logStmt->execute([
                    ':user_id'    => (int)$_SESSION['user_id'],
                    ':activity'   => $logMsg,
                    ':role'       => $staffRole,
                    ':ip_address' => $ipAddress
                ]);
            }

            $_SESSION['success'] = "Rental application rejected successfully.";
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Database error: ' . safe_error($e);
        }
    } else {
        $_SESSION['error'] = 'Please provide a valid reason for rejection.';
    }

    header("Location: rentals.php");
    exit();
}

// ==========================
// AJAX REQUEST HANDLERS
// ==========================
if (isset($_GET['ajax_action'])) {
    $staffId = (int)($_SESSION['user_id'] ?? 0);

    $targetQuery = $conn->prepare("SELECT id FROM users WHERE role IN ('inspector', 'admin') AND id != :staff_id LIMIT 1");
    $targetQuery->execute([':staff_id' => $staffId]);
    $receiverId = (int)($targetQuery->fetchColumn() ?: 1);

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

                    echo '<a href="view_rental.php?id=' . $row['id'] . '" style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 12px; color: #111827; text-decoration: none; transition: all 0.2s ease;" onmouseover="this.style.background=\'#f3f4f6\'; this.style.borderColor=\'#dc2626\';" onmouseout="this.style.background=\'#f9fafb\'; this.style.borderColor=\'#e5e7eb\';">';
                    echo '<div style="display: flex; flex-direction: column; gap: 4px; min-width: 0;">';
                    echo '<div style="font-weight: 600; font-size: 14px; color: #dc2626; word-break: break-word;">' . htmlspecialchars($row['tracking_no']) . ' <span style="color: #9ca3af; font-weight: 400;">•</span> ' . htmlspecialchars($row['business_name']) . '</div>';
                    echo '<div style="font-size: 12px; color: #4b5563;"><i class="fa-solid fa-user-shield" style="font-size: 11px; margin-right: 4px;"></i> Owner: ' . htmlspecialchars($row['owner_name'] ?? 'N/A') . '</div>';
                    echo '</div>';
                    echo '<span style="padding: 4px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; background: ' . $badgeBg . '; color: ' . $badgeColor . '; text-transform: uppercase; flex-shrink: 0; margin-left: 8px;">' . htmlspecialchars($status) . '</span>';
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

    if ($_GET['ajax_action'] === 'check_new_notif') {
        header('Content-Type: application/json');
        $lastId = (int)($_GET['last_id'] ?? 0);
        $isInit = isset($_GET['init']);

        try {
            if ($isInit) {
                $stmt = $conn->prepare("SELECT COALESCE(MAX(id), 0) FROM notifications WHERE (user_id = :sid OR user_id = 0 OR user_id IS NULL)");
                $stmt->execute([':sid' => $staffId]);
                $latestId = (int)$stmt->fetchColumn();

                $cntStmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE (user_id = :sid OR user_id = 0 OR user_id IS NULL) AND is_read = FALSE");
                $cntStmt->execute([':sid' => $staffId]);
                $unread = (int)$cntStmt->fetchColumn();

                echo json_encode(['latest_id' => $latestId, 'unread_count' => $unread]);
                exit;
            }

            $stmt = $conn->prepare("SELECT id, title, message, created_at FROM notifications WHERE (user_id = :sid OR user_id = 0 OR user_id IS NULL) AND id > :last_id ORDER BY id ASC LIMIT 10");
            $stmt->execute([':sid' => $staffId, ':last_id' => $lastId]);
            $newNotifs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $cntStmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE (user_id = :sid OR user_id = 0 OR user_id IS NULL) AND is_read = FALSE");
            $cntStmt->execute([':sid' => $staffId]);
            $unread = (int)$cntStmt->fetchColumn();

            echo json_encode(['notifications' => $newNotifs, 'unread_count' => $unread]);
        } catch (PDOException $e) {
            error_log("Check Notif Error: " . $e->getMessage());
            echo json_encode(['notifications' => [], 'unread_count' => 0, 'error' => true]);
        }
        exit;
    }

    if ($_GET['ajax_action'] === 'mark_rentals_read') {
        header('Content-Type: application/json');
        if (isset($_SESSION['user_id'])) {
            $_SESSION['rentals_last_read'] = date('Y-m-d H:i:s');
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false]);
        }
        exit;
    }
}

function renderPermitBadge($expiryDate) {
    if (empty($expiryDate)) {
        return '<span class="permit-tag pt-none"><i class="fa-solid fa-circle-minus"></i> No permit</span>';
    }
    $today = time();
    $expiry = strtotime($expiryDate);
    $formattedDate = date('M d, Y', $expiry);
    if ($expiry < $today) {
        return '<span class="permit-tag pt-expired"><i class="fa-solid fa-triangle-exclamation"></i> Expired · ' . $formattedDate . '</span>';
    } elseif (($expiry - $today) < (30 * 86400)) {
        return '<span class="permit-tag pt-soon"><i class="fa-solid fa-clock"></i> Expiring · ' . $formattedDate . '</span>';
    }
    return '<span class="permit-tag pt-ok"><i class="fa-solid fa-circle-check"></i> Valid until ' . $formattedDate . '</span>';
}

// ==========================
// STAFF INFORMATION
// ==========================
$staffName    = $_SESSION['fullname'] ?? "Staff";
$staffRole    = $_SESSION['role'] ?? "Staff";
$staffId      = (int)($_SESSION['user_id'] ?? 0);
$staffInitial = strtoupper(substr(trim($staffName), 0, 1));

$profilePicture = '';
if (columnExists($conn, 'users', 'profile_picture')) {
    $userProfileQuery = $conn->prepare("SELECT profile_picture FROM users WHERE id = :id LIMIT 1");
    $userProfileQuery->execute([':id' => $staffId]);
    $profilePicture = $userProfileQuery->fetchColumn() ?: '';
}

function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
$csrfToken = generateCsrfToken();

// Pending renewals
$pendingRenewalsCount = 0;
if (tableExists($conn, 'permit_renewals')) {
    $pendRenewalsQuery = $conn->query("SELECT COUNT(*) FROM permit_renewals WHERE status = 'Pending'");
    if ($pendRenewalsQuery) $pendingRenewalsCount = (int)$pendRenewalsQuery->fetchColumn();
}

// 🔴 BAGONG LOGIC: Bilangin ang Unread Notifications para sa 'Rental' para sa RED BADGE
$newRegistrationsCount = 0;
if (tableExists($conn, 'notifications')) {
    try {
        $notifCountQuery = $conn->prepare("
            SELECT COUNT(*) FROM notifications 
            WHERE user_id = :staff_id 
              AND notification_type = 'Rental' 
              AND is_read = FALSE
        ");
        $notifCountQuery->execute([':staff_id' => $staffId]);
        $newRegistrationsCount = (int)$notifCountQuery->fetchColumn();
    } catch (PDOException $e) {
        error_log("New Registrations Count Error: " . $e->getMessage());
    }
}

// Financial counts
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

// Filters
$search       = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$typeFilter   = trim($_GET['type'] ?? '');

$hasRentalDeleted  = columnExists($conn, 'rentals', 'is_deleted');
$hasRentalArchived = columnExists($conn, 'rentals', 'is_archived');
$hasTypeDeleted    = columnExists($conn, 'rental_types', 'is_deleted');

$phoneCol = 'NULL';
if (columnExists($conn, 'users', 'phone'))               $phoneCol = 'u.phone';
elseif (columnExists($conn, 'users', 'contact_number'))  $phoneCol = 'u.contact_number';
elseif (columnExists($conn, 'users', 'contact_no'))      $phoneCol = 'u.contact_no';
$emailCol = columnExists($conn, 'users', 'email') ? 'u.email' : "''";

$rateCol = '0';
if (columnExists($conn, 'rentals', 'monthly_rate'))      $rateCol = 'r.monthly_rate';
elseif (columnExists($conn, 'rentals', 'rate'))          $rateCol = 'r.rate';
elseif (columnExists($conn, 'rentals', 'price'))         $rateCol = 'r.price';

$complianceCol = "'N/A'";
if (columnExists($conn, 'rentals', 'compliance_status')) $complianceCol = 'r.compliance_status';
elseif (columnExists($conn, 'rentals', 'compliance'))    $complianceCol = 'r.compliance';

// Build query
$whereConditions = [];
$params = [];

if ($hasRentalDeleted)  $whereConditions[] = "r.is_deleted = FALSE";
if ($hasRentalArchived) $whereConditions[] = "(r.is_archived = FALSE OR r.is_archived IS NULL)";

if (!empty($search)) {
    $whereConditions[] = "(r.tracking_no ILIKE :search OR u.fullname ILIKE :search OR r.business_name ILIKE :search OR r.business_permit_no ILIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

// ✅ TINANGGAL ANG 'Expired' SA ALLOWED STATUSES
$allowedStatuses = ['Pending', 'Approved', 'Rejected'];
if (!empty($statusFilter) && in_array($statusFilter, $allowedStatuses)) {
    $whereConditions[] = "r.status = :statusFilter";
    $params[':statusFilter'] = $statusFilter;
}

if (!empty($typeFilter)) {
    $whereConditions[] = "r.rental_type_id = :typeFilter";
    $params[':typeFilter'] = (int)$typeFilter;
}

$whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

// ==========================
// PAGINATION SETUP
// ==========================
$perPage     = 10;
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$totalRows   = 0;
$totalPages  = 1;

try {
    $countStmt = $conn->prepare("
        SELECT COUNT(DISTINCT r.id)
        FROM rentals r
        LEFT JOIN users u ON r.user_id = u.id
        $whereClause
    ");
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Rentals Count Error: " . $e->getMessage());
    $totalRows = 0;
}

$totalPages  = max(1, (int)ceil($totalRows / $perPage));
if ($currentPage > $totalPages) $currentPage = $totalPages;
$offset      = ($currentPage - 1) * $perPage;
$showingFrom = $totalRows > 0 ? ($offset + 1) : 0;
$showingTo   = min($offset + $perPage, $totalRows);

function buildPageUrl($pageNum) {
    $q = $_GET;
    $q['page'] = $pageNum;
    return 'rentals.php?' . http_build_query($q);
}

$rentalsList = [];
try {
    $queryStmt = $conn->prepare("
        SELECT r.*, u.fullname AS owner_name, $emailCol AS email, $phoneCol AS owner_phone,
               $rateCol AS monthly_rate, $complianceCol AS compliance_status,
               rt.type_name, i.scheduled_date AS next_inspection
        FROM rentals r
        LEFT JOIN users u ON r.user_id = u.id
        LEFT JOIN rental_types rt ON r.rental_type_id = rt.id
        LEFT JOIN inspections i ON r.id = i.rental_id AND i.inspection_status = 'Scheduled'
        $whereClause
        ORDER BY r.created_at DESC
        LIMIT $perPage OFFSET $offset
    ");
    $queryStmt->execute($params);
    $rentalsList = $queryStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Rentals Query Error: " . $e->getMessage());
    $_SESSION['error'] = "Could not filter by status '$statusFilter'.";
    $rentalsList = [];
}

// Rental types
$rentalTypes = [];
if (tableExists($conn, 'rental_types')) {
    $typeWhere = $hasTypeDeleted ? "WHERE is_deleted = FALSE" : "";
    $typeQuery = $conn->query("SELECT id, type_name FROM rental_types $typeWhere ORDER BY type_name");
    if ($typeQuery) $rentalTypes = $typeQuery->fetchAll(PDO::FETCH_ASSOC);
}

// Counts
// ✅ TINANGGAL ANG EXPIRED SA STATUS COUNTS
$statusCounts = ['Total' => 0, 'Pending' => 0, 'Approved' => 0, 'Rejected' => 0];
if (tableExists($conn, 'rentals')) {
    try {
        $countWhere = $hasRentalDeleted ? "WHERE is_deleted = FALSE" : "WHERE 1=1";
        $countWhere .= $hasRentalArchived ? " AND (is_archived = FALSE OR is_archived IS NULL)" : "";
        $countQuery = $conn->query("SELECT status, COUNT(*) AS count FROM rentals $countWhere GROUP BY status");
        if ($countQuery) {
            while ($row = $countQuery->fetch(PDO::FETCH_ASSOC)) {
                if (isset($statusCounts[$row['status']])) $statusCounts[$row['status']] = (int)$row['count'];
                $statusCounts['Total'] += (int)$row['count'];
            }
        }
    } catch (PDOException $e) { error_log("Status Count Error: " . $e->getMessage()); }
}

$archivedCount = 0;
if ($hasRentalArchived && $hasRentalDeleted) {
    $archivedQuery = $conn->query("SELECT COUNT(*) FROM rentals WHERE is_deleted = FALSE AND is_archived = TRUE");
    if ($archivedQuery) $archivedCount = (int)$archivedQuery->fetchColumn();
}

// Notifications (para sa Bell Dropdown)
$notificationCount = 0;
if (tableExists($conn, 'notifications')) {
    $notifStmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE (user_id = :sid OR user_id = 0 OR user_id IS NULL) AND is_read = FALSE");
    $notifStmt->execute([':sid' => $staffId]);
    $notificationCount = (int)$notifStmt->fetchColumn();
}

$notifications = [];
if (tableExists($conn, 'notifications')) {
    $notifListStmt = $conn->prepare("
        SELECT id, title, message, created_at FROM notifications
        WHERE (user_id = :sid OR user_id = 0 OR user_id IS NULL)
        ORDER BY created_at DESC LIMIT 5
    ");
    $notifListStmt->execute([':sid' => $staffId]);
    $notifications = $notifListStmt->fetchAll(PDO::FETCH_ASSOC);
}

$successMessage = $_SESSION['success'] ?? null;
$errorMessage   = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#dc2626">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>Rental Applications | RENTRACK LGU</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
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

        @keyframes slideInToast {
            from { transform: translateX(120%); opacity: 0; }
            to   { transform: translateX(0);    opacity: 1; }
        }
        @keyframes toastProgress {
            from { width: 100%; }
            to   { width: 0%; }
        }
        @keyframes bellShake {
            0%, 100% { transform: rotate(0); }
            20% { transform: rotate(15deg); }
            40% { transform: rotate(-15deg); }
            60% { transform: rotate(10deg); }
            80% { transform: rotate(-10deg); }
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

        body.drawer-open { overflow: hidden !important; touch-action: none; }

        .page-container {
            display: flex; flex-direction: column;
            min-height: 100vh; min-height: 100dvh;
            width: 100%;
            margin: 0 !important; padding: 0 !important;
            padding-left: var(--safe-left);
            padding-right: var(--safe-right);
        }

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
        .nav-icon.has-new {
            animation: bellShake 1s ease-in-out;
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
        .date-badge {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 8px 14px;
            background: var(--red-soft);
            border: 1px solid var(--primary-red-light);
            border-radius: 999px;
            color: var(--primary-red);
            font-size: 12px; font-weight: 600;
            white-space: nowrap;
        }
        .date-badge i { color: var(--primary-red); font-size: 12px; }

        .alert {
            padding: 12px 16px; border-radius: var(--r-md);
            font-size: 13px; font-weight: 500;
            margin-bottom: 14px;
            display: flex; align-items: center; gap: 10px;
            border: 1px solid transparent;
        }
        .alert-success { background: var(--ok-bg); color: #065f46; border-color: var(--ok-bd); }
        .alert-success i { color: var(--ok); }
        .alert-danger { background: var(--danger-bg); color: #991b1b; border-color: var(--danger-bd); }
        .alert-danger i { color: var(--danger); }

        .filter-bar {
            display: flex; align-items: center; gap: 6px;
            padding: 6px; background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            margin-bottom: 16px;
            box-shadow: var(--sh-xs);
            overflow-x: auto;
            scrollbar-width: none;
            -ms-overflow-style: none;
            -webkit-overflow-scrolling: touch;
        }
        .filter-bar::-webkit-scrollbar { display: none; }

        .filter-tab {
            --tint-bg: #f8fafc;
            --tint-border: #e8ecf1;
            --tint-text: #475569;
            --tint-num-bg: #eef2f7;
            --solid: #0f172a;
            display: inline-flex; align-items: center; gap: 8px;
            padding: 8px 14px; border-radius: 9px;
            background: var(--tint-bg);
            border: 1px solid var(--tint-border);
            color: var(--tint-text);
            text-decoration: none;
            font-size: 12.5px; font-weight: 600;
            white-space: nowrap;
            transition: all 0.2s var(--ease);
            flex-shrink: 0;
        }
        .filter-tab i { font-size: 11px; color: var(--tint-text); transition: color 0.18s; }
        .filter-tab .num {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 20px; height: 18px; padding: 0 6px;
            border-radius: 9px;
            background: var(--tint-num-bg);
            font-size: 10.5px; font-weight: 700;
            color: var(--tint-text);
        }
        .filter-tab:hover { transform: translateY(-1px); box-shadow: 0 4px 10px -2px var(--tint-glow, rgba(15, 23, 42, 0.08)); }
        .filter-tab.active {
            background: var(--solid);
            border-color: var(--solid);
            color: #ffffff;
            box-shadow: 0 3px 10px -2px var(--tint-glow, rgba(15, 23, 42, 0.25));
        }
        .filter-tab.active i { color: #ffffff; }
        .filter-tab.active .num { background: rgba(255, 255, 255, 0.22); color: #ffffff; }

        .filter-tab.t-all { --tint-bg: #fef2f2; --tint-border: #fecaca; --tint-text: #b91c1c; --tint-num-bg: #fee2e2; --tint-glow: rgba(220, 38, 38, 0.28); --solid: #dc2626; }
        .filter-tab.t-pending { --tint-bg: #fffbeb; --tint-border: #fde68a; --tint-text: #b45309; --tint-num-bg: #fef3c7; --tint-glow: rgba(217, 119, 6, 0.28); --solid: #d97706; }
        .filter-tab.t-approved { --tint-bg: #ecfdf5; --tint-border: #a7f3d0; --tint-text: #047857; --tint-num-bg: #d1fae5; --tint-glow: rgba(5, 150, 105, 0.28); --solid: #059669; }
        .filter-tab.t-rejected { --tint-bg: #fff1f2; --tint-border: #fecdd3; --tint-text: #be123c; --tint-num-bg: #ffe4e6; --tint-glow: rgba(225, 29, 72, 0.28); --solid: #e11d48; }
        .filter-tab.t-archived { --tint-bg: #f5f3ff; --tint-border: #ddd6fe; --tint-text: #6d28d9; --tint-num-bg: #ede9fe; --tint-glow: rgba(124, 58, 237, 0.28); --solid: #7c3aed; }

        .panel {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: var(--radius);
            box-shadow: var(--sh-sm);
            overflow: hidden;
        }

        .panel-head {
            display: flex; align-items: center; justify-content: space-between;
            padding: 16px 22px;
            border-bottom: 1px solid #e2e8f0;
            gap: 12px; flex-wrap: wrap;
        }
        .panel-title {
            font-size: 14.5px; font-weight: 700;
            color: var(--text-primary);
            display: flex; align-items: center; gap: 9px;
        }
        .panel-title i { color: var(--primary-red); font-size: 14px; }
        .panel-meta { font-size: 12px; color: var(--text-muted); }
        .panel-meta strong { color: var(--text-primary); font-weight: 700; }

        .btn-primary {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 9px 16px;
            background: var(--primary-red);
            color: #fff; border: none;
            border-radius: 9px;
            font-size: 13px; font-weight: 600;
            text-decoration: none; cursor: pointer;
            transition: all 0.18s var(--ease);
            font-family: inherit;
            box-shadow: 0 1px 2px rgba(220, 38, 38, 0.25);
            white-space: nowrap;
        }
        .btn-primary:hover { background: var(--primary-red-dark); color: #fff; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(220, 38, 38, 0.28); }
        .btn-primary:active { transform: translateY(0); }

        .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .table-wrap::-webkit-scrollbar { height: 6px; }
        .table-wrap::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

        .table {
            width: 100%; border-collapse: collapse;
            font-size: 13px; min-width: 900px; margin-bottom: 0;
        }
        .table thead th {
            text-align: left;
            padding: 12px 22px;
            font-size: 10.5px; font-weight: 700;
            letter-spacing: 0.06em; text-transform: uppercase;
            color: var(--text-muted);
            background: #f1f5f9;
            border-bottom: 1px solid #e2e8f0;
            white-space: nowrap;
        }
        .table tbody td {
            padding: 16px 22px;
            border-bottom: 1px solid #f1f5f9;
            color: var(--text-secondary);
            vertical-align: middle;
            font-weight: 500;
        }
        .table tbody tr { transition: background 0.15s; }
        .table tbody tr:hover { background: #fafbfc; cursor: pointer; }
        .table tbody tr:last-child td { border-bottom: none; }

        .tracking-no {
            font-family: 'JetBrains Mono', monospace;
            font-size: 12.5px; font-weight: 600;
            color: var(--primary-red);
            letter-spacing: -0.01em;
        }

        .biz-name {
            font-weight: 600; color: var(--text-primary);
            font-size: 13px; display: block; margin-bottom: 5px;
        }

        .permit-tag {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 11px; font-weight: 600;
            padding: 3px 9px; border-radius: 999px;
            white-space: nowrap;
        }
        .permit-tag i { font-size: 9.5px; }
        .pt-ok      { background: var(--ok-bg); color: #047857; }
        .pt-soon    { background: var(--warn-bg); color: var(--warn); }
        .pt-expired { background: var(--danger-bg); color: var(--danger); }
        .pt-none    { background: #f1f5f9; color: var(--text-muted); }

        .badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 10px; border-radius: 999px;
            font-size: 10.5px; font-weight: 700;
            letter-spacing: 0.04em; text-transform: uppercase;
            white-space: nowrap;
        }
        .badge::before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: currentColor; }
        .badge-warning   { background: var(--warn-bg); color: var(--warn); }
        .badge-success   { background: var(--ok-bg); color: #047857; }
        .badge-danger    { background: var(--danger-bg); color: var(--danger); }
        .badge-dark      { background: #f1f5f9; color: #334155; }
        .badge-secondary { background: #f1f5f9; color: var(--text-muted); }

        .inspection-date { color: var(--info); font-weight: 600; font-size: 12.5px; }
        .inspection-date.empty { color: var(--text-muted); font-weight: 400; }

        .action-buttons { display: flex; gap: 6px; align-items: center; justify-content: flex-end; flex-wrap: wrap; }
        .btn-action {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 6px 10px; border-radius: 7px;
            font-size: 11.5px; font-weight: 600;
            text-decoration: none; cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.15s var(--ease);
            white-space: nowrap;
            font-family: inherit;
            background: transparent;
        }
        .btn-action i { font-size: 11px; }
        .btn-action:active { transform: scale(0.97); }
        .btn-view     { background: var(--info-bg); color: var(--info); border-color: var(--info-bd); }
        .btn-view:hover { background: #dbeafe; }
        .btn-schedule { background: var(--purple-bg); color: var(--purple); border-color: var(--purple-bd); }
        .btn-schedule:hover { background: #ede9fe; }
        .btn-verify   { background: var(--ok-bg); color: #047857; border-color: var(--ok-bd); }
        .btn-verify:hover { background: #d1fae5; }
        .btn-archive  { background: #f8fafc; color: var(--text-muted); border-color: #e2e8f0; }
        .btn-archive:hover { background: #f1f5f9; color: var(--text-secondary); }
        .btn-reject { background: var(--danger-bg); color: var(--danger); border-color: var(--danger-bd); }
        .btn-reject:hover { background: #fee2e2; color: #991b1b; }

        .empty-state { padding: 60px 24px; text-align: center; color: var(--text-muted); }
        .empty-state i { font-size: 40px; color: var(--text-muted); display: block; margin-bottom: 12px; opacity: 0.6; }
        .empty-state h4 { font-size: 14px; font-weight: 600; color: var(--text-secondary); margin-bottom: 4px; }
        .empty-state p { font-size: 12.5px; }

        .pagination-wrap {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 14px 22px;
            border-top: 1px solid #e2e8f0;
            background: #fafbfc;
            flex-wrap: wrap;
        }
        .pagination-info {
            font-size: 12.5px;
            color: var(--text-muted);
            font-weight: 500;
        }
        .pagination-info strong { color: var(--text-primary); font-weight: 700; }

        .pagination {
            display: flex;
            align-items: center;
            gap: 4px;
            flex-wrap: wrap;
        }
        .page-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 34px;
            height: 34px;
            padding: 0 10px;
            border-radius: 8px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            color: var(--text-secondary);
            font-size: 12.5px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.15s var(--ease);
            font-family: inherit;
        }
        .page-btn:hover:not(.disabled):not(.active) {
            border-color: var(--primary-red);
            color: var(--primary-red);
            background: var(--red-soft);
            transform: translateY(-1px);
        }
        .page-btn.active {
            background: var(--primary-red);
            border-color: var(--primary-red);
            color: #ffffff;
            box-shadow: 0 3px 10px -2px rgba(220, 38, 38, 0.35);
        }
        .page-btn.disabled {
            opacity: 0.4;
            cursor: not-allowed;
            background: #f8fafc;
        }
        .page-ellipsis {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 34px;
            height: 34px;
            color: var(--text-muted);
            font-weight: 700;
            font-size: 12.5px;
        }

        .footer {
            margin-top: 24px; padding-top: 16px;
            border-top: 1px solid #e2e8f0;
            display: flex; justify-content: space-between; align-items: center;
            font-size: 12px; color: var(--text-muted);
            flex-wrap: wrap; gap: 10px;
        }
        .footer .brand { color: var(--primary-red); font-weight: 700; }

        .modal-content {
            background: #ffffff !important;
            border: 1px solid #e2e8f0 !important;
            border-radius: var(--r-xl) !important;
            box-shadow: 0 24px 48px -12px rgba(15, 23, 42, 0.18) !important;
            overflow: hidden;
        }
        @media (max-width: 576px) {
            .modal-dialog { margin: 8px; max-width: calc(100% - 16px); }
            .modal-body { max-height: 70vh; overflow-y: auto; }
        }
        .modal-header { border-bottom: 1px solid #f1f5f9 !important; padding: 16px 22px; }
        .modal-footer { border-top: 1px solid #f1f5f9 !important; }
        .modal-title { font-size: 14.5px !important; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 9px; }
        .modal-title i { color: var(--primary-red); font-size: 14px; }
        .modal-body { padding: 22px; }
        @media (min-width: 768px) { .modal-title { font-size: 15.5px !important; } }

        #modalSearchResults a { font-size: 12.5px !important; padding: 10px 12px !important; }
        @media (min-width: 576px) { #modalSearchResults a { font-size: 13px !important; padding: 12px 16px !important; } }

        #toastContainer {
            position: fixed; top: 80px; right: 20px;
            z-index: 99999;
            display: flex; flex-direction: column; gap: 10px;
            max-width: 380px;
            pointer-events: none;
        }
        #toastContainer > * { pointer-events: auto; }

        .toast-notif {
            background: #ffffff;
            border-left: 4px solid var(--primary-red);
            border-radius: 12px;
            box-shadow: 0 10px 30px -8px rgba(15, 23, 42, 0.25);
            padding: 14px 18px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            animation: slideInToast 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }
        .toast-notif::after {
            content: '';
            position: absolute;
            bottom: 0; left: 0;
            height: 3px; width: 100%;
            background: linear-gradient(90deg, #dc2626, #f59e0b);
            animation: toastProgress 6s linear forwards;
        }
        .toast-notif .toast-icon {
            width: 36px; height: 36px; border-radius: 50%;
            background: #fee2e2; color: #dc2626;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; flex-shrink: 0;
            animation: bellShake 1s ease-in-out;
        }
        .toast-notif .toast-body { flex: 1; min-width: 0; }
        .toast-notif .toast-title {
            font-size: 13px; font-weight: 700; color: #0f172a;
            margin-bottom: 4px; display: flex; align-items: center; gap: 6px;
        }
        .toast-notif .toast-msg {
            font-size: 12px; color: #475569; line-height: 1.5;
            word-break: break-word;
            white-space: pre-line;
        }
        .toast-notif .toast-close {
            background: none; border: none; color: #94a3b8;
            cursor: pointer; font-size: 14px; padding: 0 4px;
            flex-shrink: 0;
        }
        .toast-notif .toast-close:hover { color: var(--primary-red); }

        @media (max-width: 480px) {
            #toastContainer {
                top: 70px !important;
                right: 10px !important;
                left: 10px !important;
                max-width: none !important;
            }
        }

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
            .date-badge { width: 100%; justify-content: center; font-size: 12px; }

            .panel { border-radius: 16px; }
            .panel-head { padding: 14px 16px; }
            .panel-title { font-size: 13.5px; }

            .pagination-wrap {
                flex-direction: column;
                align-items: stretch;
                padding: 12px 14px;
                gap: 10px;
            }
            .pagination-info { text-align: center; }
            .pagination { justify-content: center; }

            .footer { flex-direction: column; text-align: center; gap: 6px; font-size: 10.5px; }

            .modal-dialog { margin: 0; min-height: 100vh; min-height: 100dvh; align-items: stretch; }
            .modal-content { border-radius: 0 !important; min-height: 100vh; min-height: 100dvh; }
            .modal-header { padding-top: calc(1rem + var(--safe-top)); }
            .modal-footer { padding-bottom: calc(1rem + var(--safe-bottom)); }
        }

        @media (max-width: 767px) {
            .table-wrap { overflow: visible; }
            .table { min-width: 100% !important; }
            .table thead { display: none; }
            .table tbody, .table tr, .table td { display: block; width: 100%; }
            .table tr {
                background: #ffffff !important;
                border: 1px solid #e2e8f0 !important;
                border-left: 3px solid var(--primary-red) !important;
                border-radius: var(--r-lg);
                margin: 0 12px 12px;
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
            .action-buttons {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 8px; width: 100%;
                justify-content: stretch;
            }
            .action-buttons .btn-archive { grid-column: span 2; }
            .action-buttons .btn-action { justify-content: center; padding: 9px 8px; font-size: 12px; }
            .mobile-text-right { text-align: right; }
            .biz-name { text-align: right; }
        }
        @media (min-width: 768px) { .mobile-text-right { text-align: left; } }

        @media (max-width: 480px) {
            .official-header { padding: 5px 8px; padding-top: calc(5px + var(--safe-top)); }
            .top-navbar { padding: 8px 10px; }
            .content { padding: 12px 10px 22px; padding-bottom: calc(22px + var(--safe-bottom)); }
            .page-header { padding: 14px 16px; border-radius: 12px; }
            .page-header h1 { font-size: 16px; }
            .page-header p { font-size: 11.5px; }
            .date-badge { padding: 5px 10px; font-size: 11px; }
            .filter-bar { padding: 5px; border-radius: 12px; gap: 5px; }
            .filter-tab { padding: 7px 11px; font-size: 11.5px; gap: 6px; }
            .filter-tab .num { font-size: 10px; min-width: 18px; height: 16px; }
            .panel-head { padding: 12px 14px; }
            .panel-title { font-size: 13px; }
            .btn-primary { padding: 8px 12px; font-size: 12px; }
            .modal-title { font-size: 13.5px !important; }
            .modal-body { padding: 16px !important; }
            .footer { font-size: 10.5px; }
            .page-btn { min-width: 32px; height: 32px; padding: 0 8px; font-size: 12px; }
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

    <div id="toastContainer" aria-live="polite" aria-atomic="true"></div>

    <audio id="notifSound" preload="auto" style="display:none;"></audio>

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
                                    <!-- 🔴 RED NOTIFICATION BADGE DITO NA SA RENTAL APPLICATIONS -->
                                    <a href="rentals.php?read_rentals=1" style="display: flex; align-items: center; justify-content: space-between;">
                                        <span style="display: flex; align-items: center; gap: 12px;">
                                            <i class="fa-solid fa-folder-open"></i> Rental Applications
                                        </span>
                                        <?php if ($newRegistrationsCount > 0): ?>
                                            <span class="menu-badge" style="background: #ef4444; color: #fff; box-shadow: 0 0 8px rgba(239, 68, 68, 0.6); margin-left: auto;"><?php echo $newRegistrationsCount; ?></span>
                                        <?php endif; ?>
                                    </a>
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
                                <div id="notifListBody">
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
                    <strong>Rental Applications</strong>
                </div>

                <div class="page-header">
                    <div>
                        <h1><i class="fa-solid fa-folder-open"></i> Rental Applications</h1>
                        <p>Review and verify rental application submissions.</p>
                    </div>
                    <div class="date-badge"><i class="fa-regular fa-calendar"></i> <?php echo date("F d, Y"); ?></div>
                </div>

                <?php if ($successMessage): ?>
                    <div class="alert alert-success"><i class="fa-regular fa-circle-check"></i> <?php echo htmlspecialchars($successMessage); ?></div>
                <?php endif; ?>

                <?php if ($errorMessage): ?>
                    <div class="alert alert-danger"><i class="fa-regular fa-circle-xmark"></i> <?php echo htmlspecialchars($errorMessage); ?></div>
                <?php endif; ?>

                <div class="filter-bar">
                    <a href="rentals.php" class="filter-tab t-all <?php echo empty($statusFilter) ? 'active' : ''; ?>">
                        <i class="fa-solid fa-layer-group"></i> All <span class="num"><?php echo $statusCounts['Total']; ?></span>
                    </a>
                    <a href="rentals.php?status=Pending" class="filter-tab t-pending <?php echo $statusFilter === 'Pending' ? 'active' : ''; ?>">
                        <i class="fa-solid fa-hourglass-half"></i> Pending <span class="num"><?php echo $statusCounts['Pending']; ?></span>
                    </a>
                    <a href="rentals.php?status=Approved" class="filter-tab t-approved <?php echo $statusFilter === 'Approved' ? 'active' : ''; ?>">
                        <i class="fa-solid fa-circle-check"></i> Approved <span class="num"><?php echo $statusCounts['Approved']; ?></span>
                    </a>
                    <a href="rentals.php?status=Rejected" class="filter-tab t-rejected <?php echo $statusFilter === 'Rejected' ? 'active' : ''; ?>">
                        <i class="fa-solid fa-circle-xmark"></i> Rejected <span class="num"><?php echo $statusCounts['Rejected']; ?></span>
                    </a>
                    <!-- ✅ TINANGGAL NA ANG EXPIRED FILTER TAB -->
                    <a href="archived.php" class="filter-tab t-archived">
                        <i class="fa-solid fa-box-archive"></i> Archived <span class="num"><?php echo $archivedCount; ?></span>
                    </a>
                </div>

                <div class="panel">
                    <div class="panel-head">
                        <div class="panel-title">
                            <i class="fa-solid fa-list"></i> Application List
                        </div>
                        <div style="display:flex; align-items:center; gap:14px; flex-wrap:wrap;">
                            <span class="panel-meta">Total: <strong><?php echo $totalRows; ?></strong> application<?php echo $totalRows === 1 ? '' : 's'; ?></span>
                            <a href="add_rental.php" class="btn-primary"><i class="fa-solid fa-plus"></i> Register Rental</a>
                        </div>
                    </div>

                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Tracking No.</th>
                                    <th>Owner</th>
                                    <th>Business / Permit</th>
                                    <th>Status</th>
                                    <th>Next Inspection</th>
                                    <th style="text-align:right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($rentalsList)): ?>
                                    <?php foreach ($rentalsList as $row): ?>
                                        <?php
                                            $statusClass = 'badge-secondary';
                                            if ($row['status'] === 'Pending') $statusClass = 'badge-warning';
                                            elseif ($row['status'] === 'Approved') $statusClass = 'badge-success';
                                            elseif ($row['status'] === 'Rejected') $statusClass = 'badge-danger';

                                            $permitExpiry = $row['permit_expiry'] ?? '';
                                            $createdAt = !empty($row['created_at']) ? date("M d, Y", strtotime($row['created_at'])) : 'N/A';
                                            $monthlyRate = !empty($row['monthly_rate']) ? '₱ ' . number_format((float)$row['monthly_rate'], 2) : 'N/A';
                                            $compliance = $row['compliance_status'] ?? 'N/A';
                                        ?>
                                        <tr>
                                            <td data-label="Tracking No."><span class="tracking-no"><?php echo htmlspecialchars($row['tracking_no']); ?></span></td>
                                            <td data-label="Owner"><?php echo htmlspecialchars($row['owner_name'] ?? 'N/A'); ?></td>
                                            <td data-label="Business / Permit">
                                                <div class="mobile-text-right">
                                                    <span class="biz-name"><?php echo htmlspecialchars($row['business_name']); ?></span>
                                                    <?php echo renderPermitBadge($permitExpiry); ?>
                                                </div>
                                            </td>
                                            <td data-label="Status"><span class="badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
                                            <td data-label="Next Inspection">
                                                <?php if (!empty($row['next_inspection'])): ?>
                                                    <span class="inspection-date"><i class="fa-regular fa-calendar-check" style="margin-right:5px;"></i><?php echo date("M d, Y", strtotime($row['next_inspection'])); ?></span>
                                                <?php else: ?>
                                                    <span class="inspection-date empty">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Actions">
                                                <div class="action-buttons">
                                                    <button type="button" class="btn-action btn-view"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#viewRentalModal"
                                                            data-tracking="<?php echo htmlspecialchars($row['tracking_no'] ?? ''); ?>"
                                                            data-owner="<?php echo htmlspecialchars($row['owner_name'] ?? 'N/A'); ?>"
                                                            data-email="<?php echo htmlspecialchars($row['email'] ?? 'N/A'); ?>"
                                                            data-phone="<?php echo htmlspecialchars($row['owner_phone'] ?? 'N/A'); ?>"
                                                            data-business="<?php echo htmlspecialchars($row['business_name'] ?? ''); ?>"
                                                            data-permitno="<?php echo htmlspecialchars($row['business_permit_no'] ?? 'N/A'); ?>"
                                                            data-barangay="<?php echo htmlspecialchars($row['barangay'] ?? 'N/A'); ?>"
                                                            data-address="<?php echo htmlspecialchars($row['complete_address'] ?? ($row['business_address'] ?? 'N/A')); ?>"
                                                            data-rate="<?php echo htmlspecialchars($monthlyRate); ?>"
                                                            data-regdate="<?php echo htmlspecialchars($createdAt); ?>"
                                                            data-compliance="<?php echo htmlspecialchars($compliance); ?>"
                                                            data-permit="<?php echo htmlspecialchars($permitExpiry ?: 'None'); ?>"
                                                            data-status="<?php echo htmlspecialchars($row['status'] ?? 'PENDING'); ?>"
                                                            title="View Details">
                                                        <i class="fa-regular fa-eye"></i> View
                                                    </button>

                                                    <?php if (($row['status'] ?? '') === 'Approved' && empty($row['next_inspection'])): ?>
                                                        <a href="inspection_schedule.php?rental_id=<?php echo (int)$row['id']; ?>" class="btn-action btn-schedule" title="Schedule Inspection">
                                                            <i class="fa-regular fa-calendar-plus"></i> Schedule
                                                        </a>
                                                    <?php endif; ?>

                                                    <a href="view_rental.php?id=<?php echo $row['id']; ?>" class="btn-action btn-verify" title="Verify & Review Application">
                                                        <i class="fa-solid fa-clipboard-check"></i> Verify
                                                    </a>

                                                    <!-- 🚨 REJECT BUTTON DITO -->
                                                    <?php if ($row['status'] !== 'Rejected'): ?>
                                                    <button type="button" class="btn-action btn-reject js-reject-rental"
                                                            data-id="<?php echo $row['id']; ?>"
                                                            data-tracking="<?php echo htmlspecialchars($row['tracking_no']); ?>"
                                                            title="Reject Application">
                                                        <i class="fa-solid fa-ban"></i> Reject
                                                    </button>
                                                    <?php endif; ?>

                                                    <a href="javascript:void(0)" class="btn-action btn-archive js-confirm-archive" data-id="<?php echo $row['id']; ?>" data-tracking="<?php echo htmlspecialchars($row['tracking_no']); ?>" title="Archive Rental">
                                                        <i class="fa-solid fa-box-archive"></i> Archive
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6">
                                            <div class="empty-state">
                                                <i class="fa-regular fa-folder-open"></i>
                                                <h4>No rental applications found</h4>
                                                <p>Try adjusting your filters or register a new rental.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalRows > 0): ?>
                    <div class="pagination-wrap">
                        <div class="pagination-info">
                            Showing <strong><?php echo $showingFrom; ?></strong>
                            to <strong><?php echo $showingTo; ?></strong>
                            of <strong><?php echo $totalRows; ?></strong>
                            <?php echo $totalRows === 1 ? 'entry' : 'entries'; ?>
                        </div>

                        <?php if ($totalPages > 1): ?>
                        <nav class="pagination" aria-label="Pagination">
                            <?php if ($currentPage > 1): ?>
                                <a href="<?php echo htmlspecialchars(buildPageUrl($currentPage - 1)); ?>" class="page-btn" aria-label="Previous">
                                    <i class="fa-solid fa-chevron-left"></i>
                                </a>
                            <?php else: ?>
                                <span class="page-btn disabled"><i class="fa-solid fa-chevron-left"></i></span>
                            <?php endif; ?>

                            <?php
                            $range = 2;
                            $pages = [];
                            for ($i = 1; $i <= $totalPages; $i++) {
                                if ($i == 1 || $i == $totalPages || ($i >= $currentPage - $range && $i <= $currentPage + $range)) {
                                    $pages[] = $i;
                                }
                            }
                            $prevShown = 0;
                            foreach ($pages as $p):
                                if ($prevShown && $p - $prevShown > 1):
                            ?>
                                <span class="page-ellipsis">…</span>
                            <?php
                                endif;
                                $prevShown = $p;
                            ?>
                                <a href="<?php echo htmlspecialchars(buildPageUrl($p)); ?>"
                                   class="page-btn <?php echo $p === $currentPage ? 'active' : ''; ?>">
                                    <?php echo $p; ?>
                                </a>
                            <?php endforeach; ?>

                            <?php if ($currentPage < $totalPages): ?>
                                <a href="<?php echo htmlspecialchars(buildPageUrl($currentPage + 1)); ?>" class="page-btn" aria-label="Next">
                                    <i class="fa-solid fa-chevron-right"></i>
                                </a>
                            <?php else: ?>
                                <span class="page-btn disabled"><i class="fa-solid fa-chevron-right"></i></span>
                            <?php endif; ?>
                        </nav>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

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
                    <h5 class="modal-title" id="searchModalLabel">
                        <i class="fa-solid fa-magnifying-glass"></i> Global System Search
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div style="position: relative; margin-bottom: 20px;">
                        <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 18px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 15px;"></i>
                        <input type="text" id="modalSearchInput" class="form-control" placeholder="Search by tracking number, business name, or owner..." autocomplete="off" style="width: 100%; padding: 14px 16px 14px 48px; border-radius: 12px; border: 1.5px solid #e2e8f0; background: #f8fafc; font-size: 15px; outline: none; font-family: inherit;">
                    </div>
                    <div id="modalSearchResults" style="max-height: 380px; overflow-y: auto;">
                        <div style="text-align: center; padding: 40px 20px; color: #64748b; font-size: 13px;">
                            <i class="fa-regular fa-keyboard" style="font-size: 28px; margin-bottom: 10px; display: block; color: #94a3b8;"></i>
                            Type at least 2 characters to begin searching records...
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- VIEW RENTAL MODAL -->
    <div class="modal fade" id="viewRentalModal" tabindex="-1" aria-labelledby="viewRentalModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewRentalModalLabel">
                        <i class="fa-solid fa-file-lines"></i> Rental &amp; Owner Details
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 g-md-4">
                        <div class="col-12 col-md-6">
                            <label style="font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; display: block; margin-bottom: 4px;">Tracking Number</label>
                            <span id="modalTracking" class="fw-bold" style="font-size: 14px; color: var(--primary-red); word-break: break-all; font-family: 'JetBrains Mono', monospace;">—</span>
                        </div>
                        <div class="col-12 col-md-6">
                            <label style="font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; display: block; margin-bottom: 4px;">Owner Name</label>
                            <span id="modalOwner" style="font-size: 14px; font-weight: 600; word-break: break-word;">—</span>
                        </div>
                        <div class="col-12 col-md-6">
                            <label style="font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; display: block; margin-bottom: 4px;">Owner Contact / Email</label>
                            <span id="modalContact" style="font-size: 13px; word-break: break-word;">—</span>
                        </div>
                        <div class="col-12 col-md-6">
                            <label style="font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; display: block; margin-bottom: 4px;">Business / Rental Name</label>
                            <span id="modalBusiness" style="font-size: 14px; font-weight: 600; word-break: break-word;">—</span>
                        </div>
                        <div class="col-12 col-md-6">
                            <label style="font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; display: block; margin-bottom: 4px;">Business Permit No.</label>
                            <span id="modalPermitNo" style="font-size: 13px; word-break: break-word;">—</span>
                        </div>
                        <div class="col-12 col-md-6">
                            <label style="font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; display: block; margin-bottom: 4px;">Barangay Location</label>
                            <span id="modalBarangay" style="font-size: 13px; word-break: break-word;">—</span>
                        </div>
                        <div class="col-12">
                            <label style="font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; display: block; margin-bottom: 4px;">Complete Address</label>
                            <span id="modalAddress" style="font-size: 13px; word-break: break-word;">—</span>
                        </div>
                        <div class="col-6 col-md-4">
                            <label style="font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; display: block; margin-bottom: 4px;">Monthly Rate</label>
                            <span id="modalRate" style="font-size: 14px; font-weight: 700; color: var(--ok);">—</span>
                        </div>
                        <div class="col-6 col-md-4">
                            <label style="font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; display: block; margin-bottom: 4px;">Registration Date</label>
                            <span id="modalRegDate" style="font-size: 13px;">—</span>
                        </div>
                        <div class="col-12 col-md-4">
                            <label style="font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; display: block; margin-bottom: 4px;">Compliance Status</label>
                            <span id="modalCompliance" style="font-size: 13px; word-break: break-word;">—</span>
                        </div>
                        <div class="col-6 col-md-6">
                            <label style="font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; display: block; margin-bottom: 4px;">Permit Expiry</label>
                            <span id="modalPermit" style="font-size: 13px;">—</span>
                        </div>
                        <div class="col-6 col-md-6">
                            <label style="font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; display: block; margin-bottom: 4px;">Application Status</label>
                            <div class="mt-1"><span id="modalStatus" class="badge badge-warning" style="font-size: 11px;">—</span></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="justify-content: center;">
                    <button type="button" class="btn btn-light border px-4 py-2" data-bs-dismiss="modal" style="font-size: 13px; font-weight: 600;">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        window.csrfToken = <?php echo json_encode($csrfToken); ?>;
        window.staffId = <?php echo (int)$staffId; ?>;

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

        setInterval(() => {
            const d = new Date();
            let h = d.getHours(), m = d.getMinutes(), ampm = h >= 12 ? 'PM' : 'AM';
            h = h % 12 || 12;
            const clockEl = document.getElementById('clockTime');
            if (clockEl) clockEl.textContent = `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')} ${ampm}`;
        }, 1000);

        const notifBtn = document.getElementById('notifBtn');
        const notifDropdown = document.getElementById('notifDropdown');
        const profileToggle = document.getElementById('profileToggle');
        const profileDropdown = document.getElementById('profileDropdown');

        notifBtn?.addEventListener('click', (e) => {
            e.stopPropagation();
            profileDropdown?.classList.remove('show');
            notifDropdown.classList.toggle('show');
            if (notifDropdown.classList.contains('show')) {
                fetch('rentals.php?ajax_action=read_notif')
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

        const searchModalElement = document.getElementById('searchModal');
        const modalSearchInput = document.getElementById('modalSearchInput');
        const modalSearchResults = document.getElementById('modalSearchResults');

        searchModalElement.addEventListener('shown.bs.modal', function () {
            modalSearchInput.focus();
        });

        let searchDebounce;
        modalSearchInput.addEventListener('input', function() {
            clearTimeout(searchDebounce);
            let query = this.value.trim();
            if (query.length < 2) {
                modalSearchResults.innerHTML = `<div style="text-align: center; padding: 40px 20px; color: #64748b; font-size: 13px;"><i class="fa-regular fa-keyboard" style="font-size: 28px; margin-bottom: 10px; display: block; color: #94a3b8;"></i>Type at least 2 characters to begin searching records...</div>`;
                return;
            }
            searchDebounce = setTimeout(() => {
                fetch('rentals.php?ajax_action=search&q=' + encodeURIComponent(query))
                    .then(res => res.text())
                    .then(html => {
                        modalSearchResults.innerHTML = html.trim() !== '' ? html : `<div style="text-align: center; padding: 30px; color: #64748b; font-size: 13px;"><i class="fa-solid fa-folder-open" style="font-size: 24px; margin-bottom: 8px; display: block; opacity: 0.5;"></i> No matching results found.</div>`;
                    });
            }, 250);
        });

        const viewRentalModal = document.getElementById('viewRentalModal');
        viewRentalModal?.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            document.getElementById('modalTracking').textContent = button.getAttribute('data-tracking') || '—';
            document.getElementById('modalOwner').textContent = button.getAttribute('data-owner') || '—';
            document.getElementById('modalContact').textContent = (button.getAttribute('data-phone') || 'N/A') + ' / ' + (button.getAttribute('data-email') || 'N/A');
            document.getElementById('modalBusiness').textContent = button.getAttribute('data-business') || '—';
            document.getElementById('modalPermitNo').textContent = button.getAttribute('data-permitno') || 'N/A';
            document.getElementById('modalBarangay').textContent = button.getAttribute('data-barangay') || '—';
            document.getElementById('modalAddress').textContent = button.getAttribute('data-address') || 'N/A';
            document.getElementById('modalRate').textContent = button.getAttribute('data-rate') || 'N/A';
            document.getElementById('modalRegDate').textContent = button.getAttribute('data-regdate') || 'N/A';
            document.getElementById('modalCompliance').textContent = button.getAttribute('data-compliance') || 'N/A';
            document.getElementById('modalPermit').textContent = button.getAttribute('data-permit') || 'None';
            document.getElementById('modalStatus').textContent = button.getAttribute('data-status') || '—';
        });

        function confirmLogout() {
            Swal.fire({
                title: 'Logout', text: 'Are you sure you want to end your session?', icon: 'warning',
                showCancelButton: true, confirmButtonColor: '#dc2626', cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Logout'
            }).then((res) => { if (res.isConfirmed) document.getElementById('logoutForm').submit(); });
        }
        document.getElementById('profileLogoutBtn')?.addEventListener('click', (e) => { e.preventDefault(); confirmLogout(); });

        document.querySelectorAll('.js-confirm-archive').forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const id = this.getAttribute('data-id');
                const tracking = this.getAttribute('data-tracking') || '';
                Swal.fire({
                    title: 'Archive Record',
                    text: `Are you sure you want to archive rental tracking: ${tracking}?`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc2626',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Yes, Archive'
                }).then(function(result) {
                    if (result.isConfirmed) {
                        window.location.href = 'delete_rental.php?id=' + id + '&csrf_token=' + encodeURIComponent(window.csrfToken);
                    }
                });
            });
        });

        // 🚨 REJECT RENTAL JS LOGIC
        document.querySelectorAll('.js-reject-rental').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const rentalId = this.getAttribute('data-id');
                const tracking = this.getAttribute('data-tracking') || '';

                Swal.fire({
                    title: 'Reject Application?',
                    html: `
                        <div style="text-align:left; font-size:13px; margin-bottom:10px; color:#475569;">
                            You are about to reject tracking: <strong style="color:#dc2626;">${tracking}</strong><br>
                            Please state the reason for rejection:
                        </div>
                    `,
                    input: 'textarea',
                    inputPlaceholder: 'E.g., Incomplete requirements, invalid permit, etc.',
                    inputAttributes: {
                        'aria-label': 'Reason for rejection',
                        'style': 'font-size: 13px; font-family: inherit; border-radius: 8px; min-height: 90px;'
                    },
                    showCancelButton: true,
                    confirmButtonColor: '#dc2626',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Yes, Reject',
                    cancelButtonText: 'Cancel',
                    preConfirm: (reason) => {
                        if (!reason || reason.trim() === '') {
                            Swal.showValidationMessage('Please enter a valid reason for rejection.');
                            return false;
                        }
                        return reason.trim();
                    }
                }).then((result) => {
                    if (result.isConfirmed && result.value) {
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = 'rentals.php';

                        const actionInput = document.createElement('input');
                        actionInput.type = 'hidden';
                        actionInput.name = 'action';
                        actionInput.value = 'reject_rental';
                        form.appendChild(actionInput);

                        const idInput = document.createElement('input');
                        idInput.type = 'hidden';
                        idInput.name = 'rental_id';
                        idInput.value = rentalId;
                        form.appendChild(idInput);

                        const reasonInput = document.createElement('input');
                        reasonInput.type = 'hidden';
                        reasonInput.name = 'rejection_reason';
                        reasonInput.value = result.value;
                        form.appendChild(reasonInput);

                        const csrfInput = document.createElement('input');
                        csrfInput.type = 'hidden';
                        csrfInput.name = 'csrf_token';
                        csrfInput.value = window.csrfToken;
                        form.appendChild(csrfInput);

                        document.body.appendChild(form);
                        form.submit();
                    }
                });
            });
        });

        // 🔔 REAL-TIME NOTIFICATION POLLING SYSTEM
        (function() {
            let lastNotifId = 0;
            let isInitialized = false;
            const POLL_INTERVAL = 15000;

            function playNotifSound() {
                try {
                    const AudioCtx = window.AudioContext || window.webkitAudioContext;
                    if (!AudioCtx) return;
                    const ctx = new AudioCtx();

                    const now = ctx.currentTime;
                    [523.25, 659.25].forEach((freq, i) => {
                        const osc = ctx.createOscillator();
                        const gain = ctx.createGain();
                        osc.type = 'sine';
                        osc.frequency.setValueAtTime(freq, now + i * 0.15);
                        gain.gain.setValueAtTime(0.0001, now + i * 0.15);
                        gain.gain.exponentialRampToValueAtTime(0.15, now + i * 0.15 + 0.02);
                        gain.gain.exponentialRampToValueAtTime(0.0001, now + i * 0.15 + 0.25);
                        osc.connect(gain);
                        gain.connect(ctx.destination);
                        osc.start(now + i * 0.15);
                        osc.stop(now + i * 0.15 + 0.3);
                    });

                    setTimeout(() => { try { ctx.close(); } catch(e){} }, 1500);
                } catch (e) {
                    console.warn('Audio play failed:', e);
                }
            }

            function showToast(title, message) {
                const container = document.getElementById('toastContainer');
                if (!container) return;

                const toast = document.createElement('div');
                toast.className = 'toast-notif';
                toast.innerHTML = `
                    <div class="toast-icon"><i class="fa-solid fa-bell"></i></div>
                    <div class="toast-body">
                        <div class="toast-title">${escapeHtml(title || 'New Notification')}</div>
                        <div class="toast-msg">${escapeHtml(message || '')}</div>
                    </div>
                    <button class="toast-close" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
                `;

                toast.querySelector('.toast-close').addEventListener('click', (e) => {
                    e.stopPropagation();
                    removeToast(toast);
                });

                toast.addEventListener('click', () => {
                    window.location.href = 'rentals.php?read_rentals=1';
                });

                container.appendChild(toast);

                setTimeout(() => removeToast(toast), 6000);
            }

            function removeToast(toast) {
                if (!toast || !toast.parentElement) return;
                toast.style.transition = 'all 0.3s ease';
                toast.style.transform = 'translateX(120%)';
                toast.style.opacity = '0';
                setTimeout(() => toast.remove(), 300);
            }

            function escapeHtml(text) {
                if (!text) return '';
                const div = document.createElement('div');
                div.textContent = String(text);
                return div.innerHTML;
            }

            function updateNotifBadge(count) {
                const btn = document.getElementById('notifBtn');
                if (!btn) return;
                let badge = btn.querySelector('.badge');
                if (count > 0) {
                    if (!badge) {
                        badge = document.createElement('span');
                        badge.className = 'badge';
                        btn.appendChild(badge);
                    }
                    badge.textContent = count;
                    badge.style.display = 'flex';
                    btn.classList.remove('has-new');
                    void btn.offsetWidth;
                    btn.classList.add('has-new');
                    setTimeout(() => btn.classList.remove('has-new'), 1200);
                } else if (badge) {
                    badge.style.display = 'none';
                }
            }

            function pollNewNotifications() {
                fetch('rentals.php?ajax_action=check_new_notif&last_id=' + lastNotifId + '&t=' + Date.now(), {
                    cache: 'no-store'
                })
                .then(res => res.json())
                .then(data => {
                    if (!data) return;

                    if (Array.isArray(data.notifications) && data.notifications.length > 0) {
                        data.notifications.forEach(n => {
                            showToast(n.title || 'New Notification', n.message || '');
                            if (parseInt(n.id) > lastNotifId) lastNotifId = parseInt(n.id);
                        });
                        playNotifSound();
                    }

                    if (typeof data.unread_count !== 'undefined') {
                        updateNotifBadge(data.unread_count);
                    }
                })
                .catch(err => {
                    console.warn('Notif poll error:', err.message);
                });
            }

            function initPolling() {
                fetch('rentals.php?ajax_action=check_new_notif&init=1&last_id=0&t=' + Date.now(), {
                    cache: 'no-store'
                })
                .then(res => res.json())
                .then(data => {
                    if (data && data.latest_id) {
                        lastNotifId = parseInt(data.latest_id) || 0;
                    }
                    if (data && typeof data.unread_count !== 'undefined') {
                        updateNotifBadge(data.unread_count);
                    }
                    isInitialized = true;

                    setInterval(pollNewNotifications, POLL_INTERVAL);
                })
                .catch(err => {
                    console.warn('Notif init error:', err.message);
                    isInitialized = true;
                    setInterval(pollNewNotifications, POLL_INTERVAL);
                });
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initPolling);
            } else {
                initPolling();
            }

            document.addEventListener('visibilitychange', function() {
                if (!document.hidden && isInitialized) {
                    pollNewNotifications();
                }
            });
        })();
    </script>
</body>
</html>