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

/* PROFESSIONAL LABELS */
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

$VALID_TYPES = ['Initial', 'Annual', 'Follow-up', 'Complaint', 'Re-inspection'];
$VALID_CATEGORIES = ['building', 'fire_safety', 'structural', 'electrical', 'sanitary', 'zoning', 'occupancy', 'health'];

$hasInspectionCategory = columnExists($conn, 'inspections', 'inspection_category');
$hasRemarks = columnExists($conn, 'inspections', 'remarks');
$hasInspectorId = columnExists($conn, 'inspections', 'inspector_id');
$hasInspectionType = columnExists($conn, 'inspections', 'inspection_type');
$hasInspectionNo = columnExists($conn, 'inspections', 'inspection_no');

// ==========================
// AJAX HANDLERS
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
                    echo '<div style="font-weight: 600; font-size: 13px; color: #dc2626; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">' . htmlspecialchars($row['tracking_no']) . ' <span style="color: #9ca3af; font-weight: 400;">•</span> ' . htmlspecialchars($row['business_name']) . '</div>';
                    echo '<div style="font-size: 11px; color: #4b5563;"><i class="fa-solid fa-user-shield" style="font-size: 11px; margin-right: 4px;"></i> Owner: ' . htmlspecialchars($row['owner_name'] ?? 'N/A') . '</div>';
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

    if ($_GET['ajax_action'] === 'get_inspection') {
        header('Content-Type: application/json; charset=utf-8');
        $inspId = (int)($_GET['id'] ?? 0);
        
        if ($inspId > 0) {
            $fields = "i.id, i.inspection_no, i.inspection_status, i.inspection_date, i.inspection_time, i.inspector_id";
            if ($hasInspectionType) $fields .= ", i.inspection_type";
            if ($hasInspectionCategory) $fields .= ", i.inspection_category";
            if ($hasRemarks) $fields .= ", i.remarks";
            
            $sql = "SELECT $fields, r.business_name, r.rental_name, r.tracking_no, r.barangay, r.business_address,
                           u.fullname AS owner_name, u2.fullname AS inspector_name
                    FROM inspections i
                    INNER JOIN rentals r ON i.rental_id = r.id
                    LEFT JOIN users u ON r.user_id = u.id
                    LEFT JOIN users u2 ON i.inspector_id = u2.id
                    WHERE i.id = :id LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':id' => $inspId]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($data) {
                $data['type_label'] = getInspectionTypeLabel($data['inspection_type'] ?? '');
                $data['category_label'] = $hasInspectionCategory ? getInspectionCategoryLabel($data['inspection_category'] ?? '') : '';
                $data['date_formatted'] = !empty($data['inspection_date']) ? date("M d, Y", strtotime($data['inspection_date'])) : '—';
                $data['time_formatted'] = !empty($data['inspection_time']) ? date("g:i A", strtotime($data['inspection_time'])) : '—';
                echo json_encode(['success' => true, 'data' => $data]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Inspection not found']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid ID']);
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
// STAFF
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
// INSPECTORS
// ==========================
$inspectors = [];
if (tableExists($conn, 'users')) {
    $inspectorStmt = $conn->prepare("SELECT id, fullname FROM users WHERE role::text = 'inspector' AND status::text = 'Active' ORDER BY fullname ASC");
    $inspectorStmt->execute();
    $inspectors = $inspectorStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ==========================
// ASSIGN INSPECTOR
// ==========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_inspector') {
    $inspectionId = isset($_POST['inspection_id']) && $_POST['inspection_id'] !== '' ? (int) $_POST['inspection_id'] : 0;
    $rentalId = isset($_POST['rental_id']) ? (int) $_POST['rental_id'] : 0;
    $inspectorId = !empty($_POST['inspector_id']) ? (int) $_POST['inspector_id'] : null;
    $newType = isset($_POST['inspection_type']) ? trim($_POST['inspection_type']) : '';
    $newCategory = isset($_POST['inspection_category']) ? trim($_POST['inspection_category']) : '';
    $newDate = isset($_POST['inspection_date']) ? trim($_POST['inspection_date']) : '';
    $newTime = isset($_POST['inspection_time']) ? trim($_POST['inspection_time']) : '';
    $newStatus = isset($_POST['inspection_status']) ? trim($_POST['inspection_status']) : '';

    try {
        if (!empty($newType) && !in_array($newType, $GLOBALS['VALID_TYPES'], true)) $newType = '';
        if (!empty($newCategory) && !in_array($newCategory, $GLOBALS['VALID_CATEGORIES'], true)) $newCategory = '';

        $updateFields = []; $updateParams = [];

        if ($hasInspectorId) { $updateFields[] = "inspector_id = :inspector_id"; $updateParams[':inspector_id'] = $inspectorId; }
        if ($hasInspectionType && !empty($newType)) { $updateFields[] = "inspection_type = :inspection_type"; $updateParams[':inspection_type'] = $newType; }
        if ($hasInspectionCategory && !empty($newCategory)) { $updateFields[] = "inspection_category = :inspection_category"; $updateParams[':inspection_category'] = $newCategory; }
        if (!empty($newDate)) { $updateFields[] = "inspection_date = :inspection_date"; $updateParams[':inspection_date'] = $newDate; }
        if (!empty($newTime)) { $updateFields[] = "inspection_time = :inspection_time"; $updateParams[':inspection_time'] = $newTime; }
        if (!empty($newStatus)) { $updateFields[] = "inspection_status = :inspection_status"; $updateParams[':inspection_status'] = $newStatus; }

        if ($inspectionId > 0 && !empty($updateFields)) {
            $updateParams[':inspection_id'] = $inspectionId;
            $updateSql = "UPDATE inspections SET " . implode(', ', $updateFields) . " WHERE id = :inspection_id";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->execute($updateParams);
            $message = "Inspection updated successfully!";
        } else if ($rentalId > 0) {
            $checkExisting = $conn->prepare("
                SELECT id, inspection_no FROM inspections 
                WHERE rental_id = :rental_id 
                  AND (inspection_status IS NULL OR inspection_status::text IN ('Pending', 'Scheduled', 'Ongoing'))
                ORDER BY id DESC LIMIT 1
            ");
            $checkExisting->execute([':rental_id' => $rentalId]);
            $existingRow = $checkExisting->fetch(PDO::FETCH_ASSOC);
            $existingId = $existingRow['id'] ?? null;

            if ($existingId) {
                $updateParams[':inspection_id'] = $existingId;
                if ($hasInspectionNo && empty($existingRow['inspection_no'])) {
                    $newInspNo = 'INSP-' . date('Ymd') . '-' . rand(1000, 9999);
                    $updateFields[] = "inspection_no = :new_insp_no";
                    $updateParams[':new_insp_no'] = $newInspNo;
                }
                $updateSql = "UPDATE inspections SET " . implode(', ', $updateFields) . " WHERE id = :inspection_id";
                $updateStmt = $conn->prepare($updateSql);
                $updateStmt->execute($updateParams);
                $message = "Inspection updated and inspector assigned successfully!";
            } else {
                $inspectionNo = 'INSP-' . date('Ymd') . '-' . rand(1000, 9999);
                $insertFields = ['rental_id', 'inspection_status'];
                $insertValues = [':rid', ':status'];
                $insertParams = [':rid' => $rentalId, ':status' => !empty($newStatus) ? $newStatus : 'Scheduled'];

                if ($hasInspectionNo) { $insertFields[] = 'inspection_no'; $insertValues[] = ':ino'; $insertParams[':ino'] = $inspectionNo; }
                if ($hasInspectorId) { $insertFields[] = 'inspector_id'; $insertValues[] = ':iid'; $insertParams[':iid'] = $inspectorId; }
                if ($hasInspectionType && !empty($newType)) { $insertFields[] = 'inspection_type'; $insertValues[] = ':itype'; $insertParams[':itype'] = $newType; }
                if ($hasInspectionCategory && !empty($newCategory)) { $insertFields[] = 'inspection_category'; $insertValues[] = ':icat'; $insertParams[':icat'] = $newCategory; }
                if (!empty($newDate)) { $insertFields[] = 'inspection_date'; $insertValues[] = ':idate'; $insertParams[':idate'] = $newDate; } else { $insertFields[] = 'inspection_date'; $insertValues[] = 'CURRENT_DATE'; }
                if (!empty($newTime)) { $insertFields[] = 'inspection_time'; $insertValues[] = ':itime'; $insertParams[':itime'] = $newTime; }

                $insertSql = "INSERT INTO inspections (" . implode(', ', $insertFields) . ") VALUES (" . implode(', ', $insertValues) . ")";
                $insertStmt = $conn->prepare($insertSql);
                $insertStmt->execute($insertParams);
                $message = "Inspection created and inspector assigned!";
            }
        }

        $_SESSION['flash_message'] = 'success';
        $_SESSION['flash_text'] = $message ?? "Inspection updated successfully!";
    } catch (PDOException $e) {
        $_SESSION['flash_message'] = 'error';
        $_SESSION['flash_text'] = "Failed to update: " . safe_error($e);
    }
    header("Location: inspections.php");
    exit();
}

// ==========================
// SEARCH & FILTER
// ==========================
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';

$myInspections = [];
$totalInspections = 0;
$pendingCount = 0;
$completedCount = 0;

if (tableExists($conn, 'rentals')) {
    $selectFields = "i.id AS inspection_id, i.inspection_no, i.inspection_status, i.inspection_date, i.inspection_time, i.inspector_id";
    if ($hasInspectionType) $selectFields .= ", i.inspection_type";
    if ($hasInspectionCategory) $selectFields .= ", i.inspection_category";
    if ($hasRemarks) $selectFields .= ", i.remarks";
    
    $sql = "
        SELECT $selectFields,
               r.id AS rental_id, r.business_name, r.rental_name, r.tracking_no, r.barangay,
               u.fullname AS owner_name, u2.fullname AS inspector_name
        FROM rentals r
        LEFT JOIN inspections i ON r.id = i.rental_id
        LEFT JOIN users u ON r.user_id = u.id
        LEFT JOIN users u2 ON i.inspector_id = u2.id
        WHERE r.is_deleted = FALSE AND r.status::text = 'Approved'
    ";
    
    $params = [];

    if (!empty($search)) {
        $sql .= " AND (i.inspection_no ILIKE :search OR r.business_name ILIKE :search OR r.rental_name ILIKE :search OR u.fullname ILIKE :search OR u2.fullname ILIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    if (!empty($statusFilter)) {
        if (strtolower($statusFilter) === 'pending') {
            $sql .= " AND (i.inspection_status IS NULL OR i.inspection_status::text = 'Pending' OR i.inspection_status::text = 'Scheduled')";
        } else {
            $sql .= " AND i.inspection_status::text = :status";
            $params[':status'] = $statusFilter;
        }
    }

    $sql .= " ORDER BY i.inspection_date DESC NULLS LAST, r.id DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($results) {
        foreach ($results as $row) {
            $myInspections[] = $row;
            $status = strtolower($row['inspection_status'] ?? 'pending');
            $totalInspections++;
            if ($status === 'pending' || $status === 'scheduled' || empty($row['inspection_status'])) $pendingCount++;
            elseif ($status === 'passed' || $status === 'completed') $completedCount++;
        }
    }
}

// ==========================
// FINANCIAL COUNTS (for navbar badge - Payments lang)
// ==========================
$pendingRenewalsCount = 0;
if (tableExists($conn, 'permit_renewals')) {
    $pendRenewalsQuery = $conn->query("SELECT COUNT(*) FROM permit_renewals WHERE status::text = 'Pending'");
    if ($pendRenewalsQuery) $pendingRenewalsCount = (int) $pendRenewalsQuery->fetchColumn();
}

$pendingPayments = 0; $verifiedPayments = 0; $totalPayments = 0;

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
    <title>Manage Inspections | RENTRACK LGU</title>

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

        .alert {
            padding: 12px 16px; border-radius: var(--r-md);
            font-size: 13px; font-weight: 500;
            margin-bottom: 14px; display: flex; align-items: center; gap: 10px;
            border: 1px solid transparent;
        }
        .alert-success { background: var(--ok-bg); color: #065f46; border-color: var(--ok-bd); }
        .alert-success i { color: var(--ok); }
        .alert-error { background: var(--danger-bg); color: #991b1b; border-color: var(--danger-bd); }
        .alert-error i { color: var(--danger); }

        /* STATS GRID */
        .stats-grid { display: grid; grid-template-columns: 1fr; gap: 12px; margin-bottom: 20px; }
        @media (min-width: 576px) { .stats-grid { grid-template-columns: repeat(3, 1fr); gap: 16px; } }

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

        .stat-card .label {
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.5px; color: rgba(255,255,255,0.9);
            margin-bottom: 4px; display: block;
        }
        .stat-card .value { font-size: 24px; font-weight: 800; color: #fff; }
        @media (min-width: 768px) { .stat-card .value { font-size: 28px; } }
        .stat-card i { font-size: 22px; color: rgba(255,255,255,0.9); z-index: 1; }
        @media (min-width: 768px) { .stat-card i { font-size: 24px; } }

        /* FILTER CARD */
        .filter-card {
            background: #ffffff; border: 1px solid #e2e8f0;
            border-radius: var(--r-lg); padding: 14px;
            margin-bottom: 20px; box-shadow: var(--sh-sm);
        }
        @media (min-width: 768px) { .filter-card { padding: 16px 18px; margin-bottom: 24px; } }
        .filter-left { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }

        .search-box {
            flex: 1 1 100%; display: flex; align-items: center; gap: 10px;
            padding: 10px 14px; border: 1px solid #e2e8f0;
            border-radius: var(--r-lg); background: #f8fafc;
            transition: var(--transition);
        }
        @media (min-width: 576px) { .search-box { flex: 1 1 260px; } }
        .search-box:focus-within { border-color: var(--primary-red); background: #ffffff; box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1); }
        .search-box i { color: #94a3b8; font-size: 13px; }
        .search-box input {
            border: none; background: transparent; outline: none; width: 100%;
            font-family: inherit; font-size: 13.5px; color: #0f172a;
        }
        .search-box input::placeholder { color: #94a3b8; }

        .filter-left select {
            flex: 1 1 100%; padding: 10px 14px;
            border: 1px solid #e2e8f0; border-radius: var(--r-lg);
            background: #f8fafc; font-family: inherit;
            font-size: 13.5px; color: #0f172a;
            cursor: pointer; outline: none; transition: var(--transition);
        }
        @media (min-width: 576px) { .filter-left select { flex: 0 0 auto; min-width: 150px; } }
        .filter-left select:focus { border-color: var(--primary-red); background: #ffffff; box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1); }

        .btn-filter {
            display: inline-flex; align-items: center; justify-content: center;
            gap: 8px; padding: 10px 18px; font-size: 13.5px; font-weight: 600;
            border-radius: var(--r-lg); border: none; cursor: pointer;
            background: var(--primary-red); color: #fff;
            transition: var(--transition); flex: 1 1 100%;
            font-family: inherit;
        }
        @media (min-width: 576px) { .btn-filter { flex: 0 0 auto; } }
        .btn-filter:hover { background: var(--primary-red-dark); }

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

        .table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 900px; margin-bottom: 0; }
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
        .badge::before {
            content: ''; width: 5px; height: 5px;
            border-radius: 50%; background: currentColor;
        }
        .badge.completed { background: var(--ok-bg); color: #047857; }
        .badge.pending { background: var(--warn-bg); color: var(--warn); }
        .badge.failed { background: var(--danger-bg); color: var(--danger); }
        .badge.scheduled { background: #ccfbf1; color: #0f766e; }

        .inspection-type-chip {
            display: inline-block; padding: 3px 10px;
            background: var(--red-soft); color: var(--primary-red);
            border: 1px solid var(--primary-red-light);
            border-radius: 6px; font-size: 10.5px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.3px; white-space: nowrap;
        }
        .inspection-category-chip {
            display: inline-block; padding: 3px 10px;
            background: #fef3c7; color: #b45309;
            border: 1px solid #fde68a;
            border-radius: 6px; font-size: 10.5px; font-weight: 700;
            white-space: nowrap;
        }

        .assign-form { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
        .assign-form select {
            padding: 6px 10px; border-radius: 8px;
            border: 1px solid #e2e8f0; background: #f8fafc;
            color: #0f172a; font-size: 12px;
            outline: none; min-width: 130px;
        }
        .assign-form select:focus { border-color: var(--primary-red); background: #ffffff; }

        .btn-assign {
            padding: 6px 12px; border-radius: 8px;
            background: var(--primary-red); border: none; color: #fff;
            font-weight: 600; font-size: 11.5px;
            cursor: pointer; transition: var(--transition); white-space: nowrap;
        }
        .btn-assign:hover { background: var(--primary-red-dark); }

        .btn-view-detail {
            padding: 6px 12px; border-radius: 8px;
            background: var(--red-soft);
            border: 1px solid var(--primary-red-light);
            color: var(--primary-red);
            font-weight: 700; font-size: 11.5px;
            cursor: pointer; transition: var(--transition);
            display: inline-flex; align-items: center; gap: 5px;
            white-space: nowrap; font-family: inherit;
        }
        .btn-view-detail:hover { background: var(--primary-red); color: #fff; border-color: var(--primary-red); }

        .empty-state { text-align: center; padding: 40px 16px !important; color: #64748b; }
        .empty-state i { font-size: 48px; color: #94a3b8; opacity: 0.5; display: block; margin-bottom: 16px; }
        .empty-state h4 { font-size: 18px; font-weight: 700; color: #0f172a; margin: 0 0 8px 0; }
        .empty-state p { margin: 0; font-size: 14px; }

        .footer {
            margin-top: 24px; padding-top: 16px;
            border-top: 1px solid #e2e8f0;
            display: flex; justify-content: space-between; align-items: center;
            font-size: 12px; color: #64748b;
            flex-wrap: wrap; gap: 10px;
        }
        .footer .brand { color: var(--primary-red); font-weight: 700; }

        /* INSPECTION DETAIL MODAL */
        .insp-detail-modal-content {
            background: #ffffff; border-radius: 20px;
            border: 1px solid var(--primary-red-light);
            box-shadow: 0 25px 60px rgba(220, 38, 38, 0.2);
            overflow: hidden;
            max-height: calc(100vh - 40px);
            display: flex; flex-direction: column;
        }
        .insp-detail-header {
            padding: 16px 22px;
            background: linear-gradient(135deg, #dc2626, #991b1b);
            color: #fff;
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px; flex-shrink: 0;
        }
        .insp-detail-header h3 { font-size: 16px; font-weight: 800; margin: 0; color: #fff; }
        .insp-detail-header .btn-close-white {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.4);
            color: #fff; width: 32px; height: 32px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: all 0.2s ease;
        }
        .insp-detail-header .btn-close-white:hover { background: rgba(255,255,255,0.35); }
        .insp-detail-body { padding: 22px; overflow-y: auto; flex: 1; }
        .insp-detail-body::-webkit-scrollbar { width: 6px; }
        .insp-detail-body::-webkit-scrollbar-thumb { background: var(--primary-red); border-radius: 999px; }

        .insp-section { margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px dashed #e2e8f0; }
        .insp-section:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        .insp-section-title {
            font-size: 11px; font-weight: 800;
            text-transform: uppercase; color: var(--primary-red);
            letter-spacing: 0.8px; margin-bottom: 10px;
            display: flex; align-items: center; gap: 6px;
        }
        .insp-section-title i { font-size: 12px; }

        .insp-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 20px; }
        @media (max-width: 480px) { .insp-grid { grid-template-columns: 1fr; } }
        .insp-item .label {
            font-size: 10px; font-weight: 800;
            color: #94a3b8; text-transform: uppercase;
            letter-spacing: 0.5px; display: block; margin-bottom: 3px;
        }
        .insp-item .value { font-size: 13.5px; font-weight: 600; color: #0f172a; word-break: break-word; }
        .insp-item.full { grid-column: 1 / -1; }

        .insp-remarks-box {
            background: var(--red-soft);
            border: 1px solid var(--primary-red-light);
            border-left: 4px solid var(--primary-red);
            border-radius: 10px; padding: 12px 14px;
            font-size: 13px; color: #334155;
            line-height: 1.6; white-space: pre-line; word-break: break-word;
        }

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
            .date-badge { width: auto; }

            .footer { flex-direction: column; text-align: center; gap: 6px; font-size: 10.5px; }

            .modal-dialog { margin: 0; min-height: 100vh; min-height: 100dvh; align-items: stretch; }
            .modal-content { border-radius: 0 !important; min-height: 100vh; min-height: 100dvh; }
            .modal-header { padding-top: calc(1rem + var(--safe-top)); }
            .modal-footer { padding-bottom: calc(1rem + var(--safe-bottom)); }

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
            .assign-form { width: 100%; }
            .assign-form select { flex: 1; min-width: 0; }
        }

        @media (max-width: 480px) {
            .official-header { padding: 5px 8px; padding-top: calc(5px + var(--safe-top)); }
            .top-navbar { padding: 8px 10px; }
            .content { padding: 12px 10px 22px; padding-bottom: calc(22px + var(--safe-bottom)); }
            .page-header { padding: 14px 16px; border-radius: 12px; }
            .page-header h1 { font-size: 16px; }
            .page-header p { font-size: 11.5px; }
            .date-badge { padding: 5px 10px; font-size: 11px; }
            .stat-card { padding: 12px 14px; }
            .stat-card .value { font-size: 20px; }
            .stat-card i { font-size: 18px; }
            .footer { font-size: 10.5px; }
            .insp-detail-header { padding: 12px 16px; }
            .insp-detail-header h3 { font-size: 14px; }
            .insp-detail-body { padding: 16px; }
        }

        @media (max-width: 767px) {
            input, select, textarea { font-size: 16px !important; }
            .search-container input,
            .search-box input,
            .filter-left select { font-size: 16px !important; }
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
                    <strong>My Inspections</strong>
                </div>

                <!-- PAGE HEADER -->
                <div class="page-header">
                    <div>
                        <h1><i class="fa-solid fa-clipboard-check"></i> My Inspections</h1>
                        <p>Review, assign inspectors, and manage inspection requests from property owners.</p>
                    </div>
                    <div class="page-header-actions">
                        <a href="inspection_schedule.php" class="btn-schedule-view">
                            <i class="fa-solid fa-calendar-days"></i> Inspection Schedule
                        </a>
                        <div class="date-badge">
                            <i class="fa-regular fa-calendar"></i> <?php echo date("F d, Y"); ?>
                        </div>
                    </div>
                </div>

                <?php if ($flashMessage === 'success'): ?>
                    <div class="alert alert-success">
                        <i class="fa-regular fa-circle-check"></i>
                        <span><?php echo h($flashText); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($flashMessage === 'error'): ?>
                    <div class="alert alert-error">
                        <i class="fa-regular fa-circle-xmark"></i>
                        <span><?php echo h($flashText); ?></span>
                    </div>
                <?php endif; ?>

                <!-- STATS -->
                <div class="stats-grid">
                    <div class="stat-card bg-total">
                        <div>
                            <span class="label">Total Inspections</span>
                            <div class="value"><?php echo $totalInspections; ?></div>
                        </div>
                        <i class="fa-solid fa-list-check"></i>
                    </div>
                    <div class="stat-card bg-pending">
                        <div>
                            <span class="label">Pending / Scheduled</span>
                            <div class="value"><?php echo $pendingCount; ?></div>
                        </div>
                        <i class="fa-solid fa-clock"></i>
                    </div>
                    <div class="stat-card bg-completed">
                        <div>
                            <span class="label">Passed / Completed</span>
                            <div class="value"><?php echo $completedCount; ?></div>
                        </div>
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                </div>

                <!-- FILTER -->
                <div class="filter-card">
                    <form method="GET" class="filter-left">
                        <div class="search-box">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input type="text" name="search" placeholder="Search inspection, property, or owner..." value="<?php echo h($search); ?>">
                        </div>
                        <select name="status">
                            <option value="">All Status</option>
                            <option value="Scheduled" <?php echo $statusFilter === 'Scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                            <option value="Pending" <?php echo $statusFilter === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="Passed" <?php echo $statusFilter === 'Passed' ? 'selected' : ''; ?>>Passed</option>
                            <option value="Completed" <?php echo $statusFilter === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="Failed" <?php echo $statusFilter === 'Failed' ? 'selected' : ''; ?>>Failed</option>
                        </select>
                        <button type="submit" class="btn-filter"><i class="fa-solid fa-filter"></i> Filter</button>
                    </form>
                </div>

                <!-- PANEL -->
                <div class="panel">
                    <div class="panel-head">
                        <div class="panel-title">
                            <i class="fa-solid fa-list"></i> Inspection Requests from Owners
                        </div>
                        <div class="panel-meta">Total: <strong><?php echo $totalInspections; ?></strong> inspection<?php echo $totalInspections === 1 ? '' : 's'; ?></div>
                    </div>

                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Inspection No.</th>
                                    <th>Property</th>
                                    <th>Owner</th>
                                    <th>Type / Category</th>
                                    <th>Date / Time</th>
                                    <th>Status</th>
                                    <th style="min-width: 340px;">Assign Inspector / Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($myInspections)): ?>
                                    <?php foreach ($myInspections as $row): ?>
                                        <?php
                                            $inspType = $row['inspection_type'] ?? '';
                                            $inspCategory = $hasInspectionCategory ? ($row['inspection_category'] ?? '') : '';
                                            $typeLabel = getInspectionTypeLabel($inspType);
                                            $categoryLabel = getInspectionCategoryLabel($inspCategory);
                                            $status = $row['inspection_status'] ?? 'Pending';
                                            $statusClass = 'pending';
                                            if (strtolower($status) === 'passed' || strtolower($status) === 'completed') $statusClass = 'completed';
                                            elseif (strtolower($status) === 'failed') $statusClass = 'failed';
                                            elseif (strtolower($status) === 'scheduled') $statusClass = 'scheduled';
                                        ?>
                                        <tr>
                                            <td data-label="Inspection No.">
                                                <strong class="tracking"><?php echo h($row['inspection_no'] ?? 'N/A'); ?></strong>
                                            </td>
                                            <td data-label="Property">
                                                <div style="font-weight:600; color:#0f172a;"><?php echo h($row['rental_name'] ?? $row['business_name'] ?? '—'); ?></div>
                                                <div style="font-size:11px; color:#64748b; margin-top:2px;">
                                                    <i class="fa-solid fa-barcode" style="font-size:10px;"></i> <?php echo h($row['tracking_no'] ?? 'N/A'); ?>
                                                </div>
                                            </td>
                                            <td data-label="Owner"><?php echo h($row['owner_name'] ?? '—'); ?></td>
                                            <td data-label="Type / Category">
                                                <?php if (!empty($inspType)): ?>
                                                    <span class="inspection-type-chip"><?php echo h($typeLabel); ?></span>
                                                <?php else: ?>
                                                    <span style="color:#94a3b8; font-style:italic;">Not specified</span>
                                                <?php endif; ?>
                                                <?php if (!empty($inspCategory) && $categoryLabel !== '—'): ?>
                                                    <div style="margin-top:4px;">
                                                        <span class="inspection-category-chip"><?php echo h($categoryLabel); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Date / Time">
                                                <?php 
                                                    $date = !empty($row['inspection_date']) ? date("M d, Y", strtotime($row['inspection_date'])) : '—';
                                                    $time = !empty($row['inspection_time']) ? date("g:i A", strtotime($row['inspection_time'])) : '';
                                                    echo $date . ($time ? '<br><span style="font-size:11px; color:#64748b;"><i class="fa-regular fa-clock" style="font-size:10px;"></i> ' . $time . '</span>' : '');
                                                ?>
                                            </td>
                                            <td data-label="Status">
                                                <span class="badge <?php echo $statusClass; ?>">
                                                    <?php echo h($status); ?>
                                                </span>
                                            </td>
                                            <td data-label="Actions">
                                                <div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                                                    <button type="button" class="btn-view-detail" onclick="viewInspectionDetail(<?php echo (int)$row['inspection_id']; ?>)">
                                                        <i class="fa-solid fa-eye"></i> View
                                                    </button>
                                                    
                                                    <?php if (!empty($row['inspection_id'])): ?>
                                                    <button type="button" class="btn-view-detail" onclick="editInspection(<?php echo (int)$row['inspection_id']; ?>, <?php echo htmlspecialchars(json_encode($row)); ?>)" style="background:#dcfce7; color:#15803d; border-color:#bbf7d0;">
                                                        <i class="fa-solid fa-pen"></i> Edit
                                                    </button>
                                                    <?php endif; ?>
                                                    
                                                    <form method="POST" action="inspections.php" class="assign-form" style="margin-left:auto;">
                                                        <input type="hidden" name="action" value="assign_inspector">
                                                        <input type="hidden" name="inspection_id" value="<?php echo $row['inspection_id'] ?? ''; ?>">
                                                        <input type="hidden" name="rental_id" value="<?php echo $row['rental_id']; ?>">
                                                        
                                                        <select name="inspector_id">
                                                            <option value="">-- Assign --</option>
                                                            <?php foreach ($inspectors as $inspector): ?>
                                                                <option value="<?php echo $inspector['id']; ?>" <?php echo ($row['inspector_id'] == $inspector['id']) ? 'selected' : ''; ?>>
                                                                    <?php echo h($inspector['fullname']); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <button type="submit" class="btn-assign">
                                                            <i class="fa-solid fa-user-check"></i> Assign
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7">
                                            <div class="empty-state">
                                                <i class="fa-solid fa-clipboard-question"></i>
                                                <h4>No inspection records found</h4>
                                                <p>Approved rental applications and owner-requested inspections will appear here.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
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
    <div class="modal fade" id="inspectionDetailModal" tabindex="-1" aria-labelledby="inspectionDetailLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content insp-detail-modal-content">
                <div class="insp-detail-header">
                    <h3 id="inspectionDetailLabel">
                        <i class="fa-solid fa-clipboard-list"></i> Inspection Details
                    </h3>
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

    <!-- EDIT INSPECTION MODAL -->
    <div class="modal fade" id="editInspectionModal" tabindex="-1" aria-labelledby="editInspectionLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 18px; border: 1px solid var(--primary-red-light); overflow: hidden;">
                <div style="padding: 16px 22px; background: linear-gradient(135deg, #dc2626, #991b1b); color: #fff; display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="font-size: 16px; font-weight: 800; margin: 0; color: #fff;">
                        <i class="fa-solid fa-pen-to-square me-2"></i> Update Inspection
                    </h3>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="inspections.php" id="editInspectionForm">
                    <input type="hidden" name="action" value="assign_inspector">
                    <input type="hidden" name="inspection_id" id="editInspectionId">
                    <input type="hidden" name="rental_id" id="editRentalId">
                    
                    <div style="padding: 22px;">
                        <div class="mb-3">
                            <label style="font-size: 12px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">
                                Inspection Type (Purpose) <span style="color:#ef4444;">*</span>
                            </label>
                            <select name="inspection_type" id="editInspectionType" class="form-select" style="border: 1px solid var(--primary-red-light); border-radius: 10px; padding: 8px 12px; font-size: 13px; background: #f8fafc;">
                                <option value="">-- Select Type --</option>
                                <option value="Initial">Initial Inspection (First-Time Compliance)</option>
                                <option value="Annual">Annual Inspection (Yearly Renewal)</option>
                                <option value="Follow-up">Follow-up Inspection (Compliance Verification)</option>
                                <option value="Complaint">Complaint Inspection (Reported Violation)</option>
                                <option value="Re-inspection">Re-inspection (After Failed Assessment)</option>
                            </select>
                        </div>

                        <?php if ($hasInspectionCategory): ?>
                        <div class="mb-3">
                            <label style="font-size: 12px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">Inspection Category (Subject)</label>
                            <select name="inspection_category" id="editInspectionCategory" class="form-select" style="border: 1px solid var(--primary-red-light); border-radius: 10px; padding: 8px 12px; font-size: 13px; background: #f8fafc;">
                                <option value="">-- Select Category (Optional) --</option>
                                <option value="building">Building Inspection</option>
                                <option value="fire_safety">Fire Safety &amp; Hazard</option>
                                <option value="structural">Structural Assessment</option>
                                <option value="electrical">Electrical Safety</option>
                                <option value="sanitary">Sanitary Inspection</option>
                                <option value="zoning">Zoning Compliance</option>
                                <option value="occupancy">Occupancy Clearance</option>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label style="font-size: 12px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">Assign Inspector</label>
                            <select name="inspector_id" id="editInspectorId" class="form-select" style="border: 1px solid var(--primary-red-light); border-radius: 10px; padding: 8px 12px; font-size: 13px; background: #f8fafc;">
                                <option value="">-- Unassigned --</option>
                                <?php foreach ($inspectors as $inspector): ?>
                                    <option value="<?php echo $inspector['id']; ?>"><?php echo h($inspector['fullname']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;" class="mb-3">
                            <div>
                                <label style="font-size: 12px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">Date</label>
                                <input type="date" name="inspection_date" id="editInspectionDate" class="form-control" style="border: 1px solid var(--primary-red-light); border-radius: 10px; padding: 8px 12px; font-size: 13px; background: #f8fafc;">
                            </div>
                            <div>
                                <label style="font-size: 12px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">Time</label>
                                <input type="time" name="inspection_time" id="editInspectionTime" class="form-control" style="border: 1px solid var(--primary-red-light); border-radius: 10px; padding: 8px 12px; font-size: 13px; background: #f8fafc;">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label style="font-size: 12px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">Status</label>
                            <select name="inspection_status" id="editInspectionStatus" class="form-select" style="border: 1px solid var(--primary-red-light); border-radius: 10px; padding: 8px 12px; font-size: 13px; background: #f8fafc;">
                                <option value="Pending">Pending</option>
                                <option value="Scheduled">Scheduled</option>
                                <option value="Passed">Passed</option>
                                <option value="Completed">Completed</option>
                                <option value="Failed">Failed</option>
                            </select>
                        </div>
                    </div>

                    <div style="padding: 14px 22px; border-top: 1px solid #f1f5f9; display: flex; justify-content: flex-end; gap: 8px;">
                        <button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-sm" style="background:var(--primary-red); color:#fff;">
                            <i class="fa-solid fa-save me-1"></i> Save Changes
                        </button>
                    </div>
                </form>
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
                fetch('inspections.php?ajax_action=read_notif')
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
                    fetch('inspections.php?ajax_action=search&q=' + encodeURIComponent(query))
                        .then(res => res.text())
                        .then(html => {
                            if (html.trim() !== '') modalSearchResults.innerHTML = html;
                            else modalSearchResults.innerHTML = `<div style="text-align: center; padding: 30px; color: #64748b; font-size: 13px;"><i class="fa-solid fa-folder-open" style="font-size: 24px; margin-bottom: 8px; display: block; opacity: 0.5;"></i> No matching results found.</div>`;
                        });
                }, 250);
            });
        }

        /* INSPECTION DETAIL */
        function viewInspectionDetail(id) {
            const modalEl = document.getElementById('inspectionDetailModal');
            const modal = new bootstrap.Modal(modalEl);
            const body = document.getElementById('inspectionDetailBody');
            
            body.innerHTML = `<div style="text-align:center; padding:40px;"><i class="fa-solid fa-spinner fa-spin" style="font-size:28px; color:var(--primary-red);"></i><p style="margin-top:12px; color:#64748b;">Loading inspection details...</p></div>`;
            modal.show();

            fetch('inspections.php?ajax_action=get_inspection&id=' + id)
                .then(res => res.json())
                .then(json => {
                    if (!json.success) {
                        body.innerHTML = `<div style="text-align:center; padding:40px; color:#ef4444;"><i class="fa-solid fa-circle-exclamation" style="font-size:32px; margin-bottom:10px;"></i><p>${json.error || 'Failed to load'}</p></div>`;
                        return;
                    }
                    const d = json.data;
                    const statusClass = getStatusClass(d.inspection_status);
                    
                    body.innerHTML = `
                        <div class="insp-section">
                            <div class="insp-section-title"><i class="fa-solid fa-clipboard-check"></i> Inspection Summary</div>
                            <div class="insp-grid">
                                <div class="insp-item">
                                    <span class="label">Inspection No.</span>
                                    <span class="value" style="color:var(--primary-red);">${escapeHtml(d.inspection_no || 'N/A')}</span>
                                </div>
                                <div class="insp-item">
                                    <span class="label">Status</span>
                                    <span class="value"><span class="badge ${statusClass}">${escapeHtml(d.inspection_status || 'Pending')}</span></span>
                                </div>
                                <div class="insp-item">
                                    <span class="label">Inspection Type</span>
                                    <span class="value"><span class="inspection-type-chip">${escapeHtml(d.type_label)}</span></span>
                                </div>
                                ${d.category_label && d.category_label !== '—' ? `<div class="insp-item"><span class="label">Category</span><span class="value"><span class="inspection-category-chip">${escapeHtml(d.category_label)}</span></span></div>` : ''}
                                <div class="insp-item">
                                    <span class="label">Scheduled Date</span>
                                    <span class="value">${escapeHtml(d.date_formatted)}</span>
                                </div>
                                <div class="insp-item">
                                    <span class="label">Scheduled Time</span>
                                    <span class="value">${escapeHtml(d.time_formatted)}</span>
                                </div>
                                <div class="insp-item full">
                                    <span class="label">Assigned Inspector</span>
                                    <span class="value">${d.inspector_name ? '<i class="fa-solid fa-user-tie" style="color:var(--primary-red);"></i> ' + escapeHtml(d.inspector_name) : '<span style="color:#94a3b8; font-style:italic;">Not yet assigned</span>'}</span>
                                </div>
                            </div>
                        </div>

                        <div class="insp-section">
                            <div class="insp-section-title"><i class="fa-solid fa-building"></i> Property Information</div>
                            <div class="insp-grid">
                                <div class="insp-item full">
                                    <span class="label">Business / Rental Name</span>
                                    <span class="value">${escapeHtml(d.business_name || d.rental_name || '—')}</span>
                                </div>
                                <div class="insp-item">
                                    <span class="label">Tracking No.</span>
                                    <span class="value" style="color:var(--primary-red);">${escapeHtml(d.tracking_no || 'N/A')}</span>
                                </div>
                                <div class="insp-item">
                                    <span class="label">Barangay</span>
                                    <span class="value">${escapeHtml(d.barangay || '—')}</span>
                                </div>
                                <div class="insp-item full">
                                    <span class="label">Owner</span>
                                    <span class="value">${escapeHtml(d.owner_name || '—')}</span>
                                </div>
                                <div class="insp-item full">
                                    <span class="label">Address</span>
                                    <span class="value">${escapeHtml(d.business_address || '—')}</span>
                                </div>
                            </div>
                        </div>

                        ${d.remarks ? `<div class="insp-section"><div class="insp-section-title"><i class="fa-solid fa-comment-dots"></i> Remarks from Owner</div><div class="insp-remarks-box">${escapeHtml(d.remarks)}</div></div>` : ''}
                    `;
                })
                .catch(err => {
                    body.innerHTML = `<div style="text-align:center; padding:40px; color:#ef4444;"><i class="fa-solid fa-circle-exclamation" style="font-size:32px;"></i><p>Network error</p></div>`;
                });
        }

        function getStatusClass(status) {
            const s = (status || '').toLowerCase().trim();
            if (['passed', 'completed'].includes(s)) return 'completed';
            if (['failed', 'rejected'].includes(s)) return 'failed';
            if (['scheduled'].includes(s)) return 'scheduled';
            return 'pending';
        }

        function editInspection(id, rowData) {
            document.getElementById('editInspectionId').value = id || '';
            document.getElementById('editRentalId').value = rowData.rental_id || '';
            document.getElementById('editInspectionType').value = rowData.inspection_type || '';
            const catEl = document.getElementById('editInspectionCategory');
            if (catEl) catEl.value = rowData.inspection_category || '';
            document.getElementById('editInspectorId').value = rowData.inspector_id || '';
            document.getElementById('editInspectionDate').value = rowData.inspection_date || '';
            document.getElementById('editInspectionTime').value = rowData.inspection_time || '';
            document.getElementById('editInspectionStatus').value = rowData.inspection_status || 'Pending';
            
            const modal = new bootstrap.Modal(document.getElementById('editInspectionModal'));
            modal.show();
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

        function escapeHtml(str) {
            if (!str) return '';
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }
    </script>
</body>
</html>