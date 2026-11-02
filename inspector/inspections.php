<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

include "../config/db.php";

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../config/session.php'; app_session_start();
}

date_default_timezone_set("Asia/Manila");

$inspectorId = (int) ($_SESSION['user_id'] ?? 0);
$fullname = htmlspecialchars($_SESSION['fullname'] ?? 'Inspector', ENT_QUOTES, 'UTF-8');
$staffRole = $_SESSION['role'] ?? "Inspector";
$userInitial = strtoupper(substr($fullname, 0, 1));

$successMsg = "";
$errorMsg = "";

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
   ✅ MARK ALL INSPECTIONS AS READ
===================================================== */
if ($inspectorId > 0 && tableExists($conn, 'inspections') && columnExists($conn, 'inspections', 'is_read')) {
    try {
        $markReadStmt = $conn->prepare("
            UPDATE inspections 
            SET is_read = TRUE 
            WHERE inspector_id = :iid 
              AND (is_read = FALSE OR is_read IS NULL)
        ");
        $markReadStmt->execute([':iid' => $inspectorId]);
    } catch (PDOException $e) {}
}

/* =====================================================
   PROFESSIONAL LABELS
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

function getPermitTypeLabelFromPrefix($prefix) {
    $map = [
        'BP' => 'Business Permit', 'SP' => 'Sanitary Permit', 'FSIC' => 'Fire Safety',
        'BC' => 'Barangay Clearance', 'HC' => 'Health Cert', 'LC' => 'Lease Contract',
        'LCC' => 'Lease Clearance', 'OCP' => 'Occupancy', 'PERMIT' => 'Permit',
    ];
    return $map[$prefix] ?? 'Permit';
}

$hasInspectionCategory = columnExists($conn, 'inspections', 'inspection_category');
$hasRemarks = columnExists($conn, 'inspections', 'remarks');
$hasInspectionType = columnExists($conn, 'inspections', 'inspection_type');

$hasDocuments = tableExists($conn, 'documents');
$hasPermitNoCol = $hasDocuments && columnExists($conn, 'documents', 'business_permit_no');
$hasPermitExpiryCol = $hasDocuments && columnExists($conn, 'documents', 'permit_expiry_date');

if (!function_exists('csrfField')) {
    function csrfField() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        echo '<input type="hidden" name="csrf_token" value="' . $_SESSION['csrf_token'] . '">';
    }
}
if (!function_exists('csrfCheck')) {
    function csrfCheck() {
        if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            die('Invalid CSRF token.');
        }
    }
}

if (!function_exists('permitStatus')) {
    function permitStatus($expiryDate) {
        if (empty($expiryDate)) return ['label' => 'No Permit', 'class' => 'secondary'];
        $today = time();
        $expiry = strtotime($expiryDate);
        if ($expiry < $today) {
            return ['label' => 'Expired', 'class' => 'danger'];
        } elseif (($expiry - $today) < (30 * 86400)) {
            return ['label' => 'Expiring Soon', 'class' => 'warning'];
        }
        return ['label' => 'Active', 'class' => 'success'];
    }
}

if (!function_exists('statusBadge')) {
    function statusBadge($status) {
        switch (strtolower(trim($status))) {
            case 'completed':
            case 'passed':
                return '<span class="status-badge passed"><i class="fa-regular fa-circle-check me-1"></i>' . ucfirst(strtolower($status)) . '</span>';
            case 'ongoing':
                return '<span class="status-badge scheduled"><i class="fa-regular fa-hourglass-half me-1"></i>Ongoing</span>';
            case 'scheduled':
                return '<span class="status-badge scheduled"><i class="fa-regular fa-clock me-1"></i>Scheduled</span>';
            case 'failed':
            case 'cancelled':
                return '<span class="status-badge failed"><i class="fa-regular fa-circle-xmark me-1"></i>' . ucfirst(strtolower($status)) . '</span>';
            default:
                $cleanName = htmlspecialchars($status !== '' ? $status : 'Pending', ENT_QUOTES, 'UTF-8');
                return '<span class="status-badge pending">' . $cleanName . '</span>';
        }
    }
}

/* =====================================================
   UPDATE STATUS HANDLER
===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    csrfCheck();

    $inspectionId = (int) ($_POST['inspection_id'] ?? 0);
    $newStatus = trim($_POST['inspection_status'] ?? '');
    $notes = trim($_POST['inspector_notes'] ?? '');

    $allowedYesNo = ['Yes', 'No', 'N/A'];
    $permitDisplayed = in_array($_POST['permit_displayed'] ?? '', $allowedYesNo, true) ? $_POST['permit_displayed'] : 'N/A';
    $permitMatches   = in_array($_POST['permit_matches'] ?? '', $allowedYesNo, true) ? $_POST['permit_matches'] : 'N/A';

    $allowedStatus = ['Scheduled', 'Ongoing', 'Completed', 'Cancelled', 'Passed', 'Failed'];

    $ownStmt = $conn->prepare("SELECT id FROM inspections WHERE id = :id AND inspector_id = :iid LIMIT 1");
    $ownStmt->execute([':id' => $inspectionId, ':iid' => $inspectorId]);
    $isOwned = (bool)$ownStmt->fetch(PDO::FETCH_ASSOC);

    if (!$isOwned) {
        $errorMsg = "This inspection was not found under your account.";
    } elseif (!in_array($newStatus, $allowedStatus) || empty($newStatus)) {
        $errorMsg = "Please select a valid status.";
    } else {
        $signaturePath = null;
        $signatureData = $_POST['signature_data'] ?? '';
        if (!empty($signatureData) && str_starts_with($signatureData, 'data:image/png;base64,')) {
            $base64 = substr($signatureData, strlen('data:image/png;base64,'));
            $decoded = base64_decode($base64, true);
            if ($decoded !== false && strlen($decoded) > 0 && strlen($decoded) < 2 * 1024 * 1024) {
                $sigDir = __DIR__ . '/uploads/signatures';
                if (!is_dir($sigDir)) {
                    @mkdir($sigDir, 0755, true);
                }
                $sigName = 'sig_' . $inspectionId . '_' . time() . '.png';
                if (file_put_contents($sigDir . '/' . $sigName, $decoded) !== false) {
                    $signaturePath = 'uploads/signatures/' . $sigName;
                }
            }
        }

        if ($signaturePath !== null) {
            $sql = "UPDATE inspections
                    SET inspection_status = :status,
                        inspector_notes = :notes,
                        permit_displayed = :pd,
                        permit_matches = :pm,
                        inspector_signature = :sig
                    WHERE id = :id AND inspector_id = :iid";
            $updateStmt = $conn->prepare($sql);
            $updateOk = $updateStmt->execute([
                ':status' => $newStatus,
                ':notes' => $notes,
                ':pd' => $permitDisplayed,
                ':pm' => $permitMatches,
                ':sig' => $signaturePath,
                ':id' => $inspectionId,
                ':iid' => $inspectorId
            ]);
        } else {
            $sql = "UPDATE inspections
                    SET inspection_status = :status,
                        inspector_notes = :notes,
                        permit_displayed = :pd,
                        permit_matches = :pm
                    WHERE id = :id AND inspector_id = :iid";
            $updateStmt = $conn->prepare($sql);
            $updateOk = $updateStmt->execute([
                ':status' => $newStatus,
                ':notes' => $notes,
                ':pd' => $permitDisplayed,
                ':pm' => $permitMatches,
                ':id' => $inspectionId,
                ':iid' => $inspectorId
            ]);
        }

        if (!$updateOk) {
            $errorMsg = "Failed to update. Please try again.";
        } else {
            $getRentalStmt = $conn->prepare("SELECT rental_id FROM inspections WHERE id = :id LIMIT 1");
            $getRentalStmt->execute([':id' => $inspectionId]);
            $rentalData = $getRentalStmt->fetch(PDO::FETCH_ASSOC);
            $rentalId = (int)($rentalData['rental_id'] ?? 0);

            if ($rentalId > 0) {
                if ($newStatus === 'Passed' || $newStatus === 'Completed') {
                    $compStatus = 'Compliant';
                } elseif ($newStatus === 'Failed' || $newStatus === 'Cancelled') {
                    $compStatus = 'Non-Compliant';
                } else {
                    $compStatus = 'Pending';
                }

                $updateCompliance = $conn->prepare("UPDATE rentals SET compliance_status = :comp WHERE id = :rid");
                $updateCompliance->execute([
                    ':comp' => $compStatus,
                    ':rid' => $rentalId
                ]);
            }

            if (!empty($_FILES['evidence_photos']) && is_array($_FILES['evidence_photos']['tmp_name'])) {
                $uploadDir = __DIR__ . '/uploads/inspection_' . $inspectionId;
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0755, true);
                }

                $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
                $allowedMime = [
                    'image/jpeg' => ['jpg', 'jpeg'],
                    'image/png'  => ['png'],
                    'image/webp' => ['webp'],
                ];
                $maxSize = 5 * 1024 * 1024;
                $finfo = new finfo(FILEINFO_MIME_TYPE);

                $fileCount = count($_FILES['evidence_photos']['tmp_name']);
                for ($i = 0; $i < $fileCount; $i++) {
                    if ($_FILES['evidence_photos']['error'][$i] !== UPLOAD_ERR_OK) continue;

                    $tmpName = $_FILES['evidence_photos']['tmp_name'][$i];
                    $origName = basename($_FILES['evidence_photos']['name'][$i]);
                    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                    $size = $_FILES['evidence_photos']['size'][$i];

                    if (!in_array($ext, $allowedExt, true) || $size > $maxSize || $size <= 0) continue;

                    $detectedMime = @$finfo->file($tmpName);
                    $imageInfo = @getimagesize($tmpName);
                    if ($imageInfo === false || !isset($allowedMime[$detectedMime]) || !in_array($ext, $allowedMime[$detectedMime], true)) continue;

                    $storedName = 'photo_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    $destPath = $uploadDir . '/' . $storedName;

                    if (move_uploaded_file($tmpName, $destPath)) {
                        @chmod($destPath, 0644);
                        $relativePath = 'uploads/inspection_' . $inspectionId . '/' . $storedName;
                        if (tableExists($conn, 'inspection_photos')) {
                            $insertPhoto = $conn->prepare("INSERT INTO inspection_photos (inspection_id, photo_path, original_name) VALUES (:iid, :ppath, :oname)");
                            $insertPhoto->execute([
                                ':iid' => $inspectionId,
                                ':ppath' => $relativePath,
                                ':oname' => $origName
                            ]);
                        }
                    }
                }
            }

            header("Location: inspections.php?msg=success");
            exit();
        }
    }
}

if (isset($_GET['msg']) && $_GET['msg'] === 'success') {
    $successMsg = "Inspection updated successfully.";
}

$searchQuery = trim($_GET['search'] ?? $_GET['q'] ?? '');
$statusFilterParam = trim($_GET['status'] ?? '');

/* =====================================================
   FETCH INSPECTIONS
===================================================== */
$inspections = [];
if (tableExists($conn, 'inspections') && tableExists($conn, 'rentals')) {
    $selectFields = "i.id, i.rental_id, i.inspector_id, 
                     COALESCE(i.inspection_no, CONCAT('INSP-', TO_CHAR(r.created_at, 'YYYYMMDD'), '-', r.id)) AS inspection_no, 
                     i.inspection_status, i.inspection_date, i.inspection_time, i.inspector_notes,
                     i.permit_displayed, i.permit_matches, i.inspector_signature, i.created_at";
    
    if ($hasInspectionType) $selectFields .= ", i.inspection_type";
    if ($hasInspectionCategory) $selectFields .= ", i.inspection_category";
    if ($hasRemarks) $selectFields .= ", i.remarks";
    
    $selectFields .= ", r.rental_name, r.business_name, r.tracking_no, r.barangay, r.permit_expiry, r.business_address";
    
    $permitJoin = '';
    $permitSelect = "NULL AS doc_permit_no, NULL AS doc_permit_expiry";
    if ($hasPermitNoCol) {
        $permitJoin = "LEFT JOIN LATERAL (
            SELECT d.business_permit_no, d.permit_expiry_date
            FROM documents d
            WHERE d.rental_id = r.id
              AND d.business_permit_no IS NOT NULL
            ORDER BY 
                CASE 
                    WHEN d.title ILIKE '%Business Permit%' THEN 0
                    WHEN d.title ILIKE '%Sanitary%' THEN 1
                    WHEN d.title ILIKE '%Fire Safety%' THEN 2
                    ELSE 3
                END,
                d.uploaded_at DESC
            LIMIT 1
        ) d ON TRUE";
        $permitSelect = "d.business_permit_no AS doc_permit_no, " . ($hasPermitExpiryCol ? "d.permit_expiry_date" : "NULL") . " AS doc_permit_expiry";
    }
    
    $sql = "SELECT $selectFields, u.fullname AS owner_name, $permitSelect
            FROM inspections i
            INNER JOIN rentals r ON i.rental_id = r.id
            LEFT JOIN users u ON r.user_id = u.id
            $permitJoin
            WHERE i.inspector_id = :iid 
              AND (r.is_deleted = FALSE OR r.is_deleted IS NULL)
    ";
    $params = [':iid' => $inspectorId];

    if ($statusFilterParam !== '' && $statusFilterParam !== 'all') {
        $sql .= " AND i.inspection_status::text = :status";
        $params[':status'] = $statusFilterParam;
    }
    $sql .= " ORDER BY i.inspection_date DESC NULLS LAST";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $inspections = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$photoCounts = [];
if (tableExists($conn, 'inspection_photos')) {
    $photoStmt = $conn->query("
        SELECT inspection_id, COUNT(*) AS total
        FROM inspection_photos
        GROUP BY inspection_id
    ");
    if ($photoStmt) {
        while ($row = $photoStmt->fetch(PDO::FETCH_ASSOC)) {
            $photoCounts[(int) $row['inspection_id']] = (int) $row['total'];
        }
    }
}

/* =====================================================
   CALENDAR DATA
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

$calendarInspections = [];
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
    $calendarInspections = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($calendarInspections as $ins) {
        $date = $ins['inspection_date'] ?? '';
        if (!isset($inspectionsByDate[$date])) $inspectionsByDate[$date] = [];
        $inspectionsByDate[$date][] = $ins;
    }
}

$statsThisMonth = 0;
$statsPending = 0;
$statsCompleted = 0;
$statsFailed = 0;
foreach ($calendarInspections as $ins) {
    $statsThisMonth++;
    $status = strtolower($ins['inspection_status'] ?? 'pending');
    if ($status === 'passed' || $status === 'completed') $statsCompleted++;
    elseif ($status === 'failed' || $status === 'cancelled') $statsFailed++;
    else $statsPending++;
}

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
            LIMIT 10";
    
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

$firstDayOfMonth = date('N', strtotime("$currentYear-$currentMonth-01"));
$daysInMonth = date('t', strtotime("$currentYear-$currentMonth-01"));

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#ffffff">
<title>Inspections | RENTRACK LGU</title>

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
        --navbar-height: 72px;
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

    ::-webkit-scrollbar { width: 6px; height: 6px; }
    ::-webkit-scrollbar-track { background: var(--bg-body); }
    ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 20px; }

    @keyframes fadeUp {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .rt-panel, .rt-chart-card { animation: fadeUp 0.5s cubic-bezier(0.4,0,0.2,1) both; }

    /* ============ TOP OFFICIAL BAR ============ */
    .top-official-bar {
        position: fixed; top: 0; left: 0; width: 100%;
        background: var(--topofficial-bg);
        border-bottom: 1px solid rgba(255, 255, 255, 0.15);
        padding: var(--safe-top) calc(15px + var(--safe-right)) 0 calc(15px + var(--safe-left));
        display: flex; justify-content: space-between; align-items: center;
        flex-wrap: nowrap; overflow: hidden;
        font-size: 11px; font-weight: 600; z-index: 1002;
        height: var(--topofficial-height);
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

    /* ============ MERGED HEADER ============ */
    .rt-topbar {
        position: fixed;
        top: var(--topofficial-height); left: 0; width: 100%;
        background: var(--navbar-bg);
        border-bottom: 1px solid var(--border-color);
        display: flex; align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 0 calc(20px + var(--safe-right)) 0 calc(20px + var(--safe-left));
        height: var(--navbar-height);
        z-index: 1001;
    }

    .rt-topbar-brand {
        display: flex; align-items: center; gap: 10px;
        padding-right: 16px;
        border-right: 1px solid var(--border-color);
        flex-shrink: 0;
        height: 44px;
    }
    .rt-topbar-logo {
        width: 38px; height: 38px; border-radius: 10px;
        background: linear-gradient(135deg, var(--red-accent), #991b1b);
        display: flex; align-items: center; justify-content: center;
        font-family: 'Space Grotesk', sans-serif; font-weight: 800; font-size: 13px; color: #fff;
        box-shadow: 0 4px 12px rgba(220, 38, 38, 0.25); flex-shrink: 0;
    }
    .rt-topbar-brand-text { display: flex; flex-direction: column; line-height: 1.15; }
    .rt-topbar-brand-text strong { font-size: 13px; font-weight: 800; color: var(--text-main); letter-spacing: 0.3px; }
    .rt-topbar-brand-text span { font-size: 9px; font-weight: 700; color: var(--red-accent); text-transform: uppercase; letter-spacing: 0.06em; }

    /* Center */
    .rt-topbar-center {
        flex: 1 1 auto;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 20px;
        min-width: 0;
        height: 100%;
    }

    .rt-main-nav {
        display: flex; align-items: center; gap: 4px;
        height: 100%;
        flex-shrink: 0;
    }

    .rt-nav-link {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 14px;
        border-radius: 10px;
        color: var(--text-sub);
        font-size: 13px;
        font-weight: 600;
        text-decoration: none;
        transition: var(--transition);
        background: transparent;
        border: none;
        font-family: inherit;
        cursor: pointer;
        white-space: nowrap;
        height: 42px;
        position: relative;
    }
    .rt-nav-link i { font-size: 13px; color: var(--text-muted); transition: var(--transition); }
    .rt-nav-link:hover { background: var(--red-soft); color: var(--red-accent); }
    .rt-nav-link:hover i { color: var(--red-accent); }
    .rt-nav-link.active { background: var(--red-accent); color: #fff; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3); }
    .rt-nav-link.active i { color: #fff; }

    .rt-nav-group { position: relative; display: flex; align-items: center; height: 100%; }
    .rt-nav-link .rt-caret { font-size: 9px; margin-left: 2px; transition: transform 0.25s ease; }
    .rt-nav-group:hover > .rt-nav-link .rt-caret,
    .rt-nav-group.open > .rt-nav-link .rt-caret { transform: rotate(180deg); }
    .rt-nav-group > .rt-nav-link.has-active { background: var(--red-soft); color: var(--red-accent); }
    .rt-nav-group > .rt-nav-link.has-active i { color: var(--red-accent); }

    .rt-nav-dropdown {
        position: absolute;
        top: calc(100% + 4px);
        left: 0;
        min-width: 250px;
        background: #fff;
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 8px;
        box-shadow: 0 12px 30px rgba(0, 0, 0, 0.12);
        opacity: 0;
        visibility: hidden;
        transform: translateY(6px);
        transition: opacity 0.2s ease, transform 0.2s ease, visibility 0.2s;
        z-index: 999;
    }
    .rt-nav-group:hover > .rt-nav-dropdown,
    .rt-nav-group.open > .rt-nav-dropdown {
        opacity: 1;
        visibility: visible;
        transform: translateY(0);
    }

    .rt-nav-dropdown a {
        display: flex; align-items: center; gap: 10px;
        padding: 9px 12px;
        border-radius: 8px;
        text-decoration: none;
        color: var(--text-sub);
        font-size: 13px;
        font-weight: 500;
        transition: var(--transition);
    }
    .rt-nav-dropdown a i { width: 18px; text-align: center; font-size: 13px; color: var(--red-accent); flex-shrink: 0; }
    .rt-nav-dropdown a:hover { background: var(--red-soft); color: var(--red-accent); }
    .rt-nav-dropdown a.active { background: var(--red-soft); color: var(--red-accent); font-weight: 700; }

    .rt-dd-badge {
        margin-left: auto;
        background: #fbbf24; color: #7c2d12;
        font-size: 10px; font-weight: 800;
        padding: 2px 7px;
        border-radius: 999px;
        min-width: 20px;
        text-align: center;
        box-shadow: 0 0 8px rgba(251, 191, 36, 0.6);
    }

    /* Search Trigger */
    .rt-search-trigger {
        position: relative;
        width: 260px;
        max-width: 100%;
        flex-shrink: 0;
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 9px 14px 9px 38px;
        border-radius: 999px;
        border: 1px solid var(--border-color);
        background: var(--bg-card-alt);
        color: var(--text-muted);
        font-size: 13px;
        cursor: pointer;
        transition: var(--transition);
        white-space: nowrap;
        overflow: hidden;
        height: 42px;
    }
    .rt-search-trigger i.fa-magnifying-glass {
        position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
        color: var(--text-muted); font-size: 13px;
    }
    .rt-search-trigger:hover { background: #fff; border-color: var(--red-accent); color: var(--red-accent); }
    .rt-search-trigger:hover i.fa-magnifying-glass { color: var(--red-accent); }
    .rt-search-trigger .search-placeholder { flex: 1; text-overflow: ellipsis; overflow: hidden; }
    .rt-search-trigger .kbd-hint {
        background: #fff; border: 1px solid var(--border-color); color: var(--text-muted);
        font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 5px;
        letter-spacing: 0.5px; flex-shrink: 0;
    }
    .rt-search-trigger:hover .kbd-hint { border-color: var(--red-accent); color: var(--red-accent); }

    /* Right side */
    .rt-topbar-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }

    .icon-btn {
        width: 40px; height: 40px; border-radius: 10px;
        border: 1px solid var(--border-color); background: var(--bg-card-alt);
        color: var(--text-main); display: flex; align-items: center; justify-content: center;
        cursor: pointer; position: relative; transition: var(--transition);
        flex-shrink: 0;
    }
    .icon-btn:hover { background: #fff; border-color: var(--red-accent); color: var(--red-accent); }
    .mobile-search-btn { display: none; }
    .mobile-menu-btn { display: none; }

    .notif-badge-dot {
        position: absolute; top: 6px; right: 6px; width: 9px; height: 9px;
        border-radius: 50%; background: var(--red-accent);
        box-shadow: 0 0 8px var(--red-accent);
    }

    .rt-profile-wrapper { position: relative; flex-shrink: 0; }
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

    /* ============ SEARCH MODAL ============ */
    .search-modal-overlay {
        position: fixed; inset: 0;
        background: rgba(15, 23, 42, 0.55);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        z-index: 10000;
        display: flex;
        align-items: flex-start;
        justify-content: center;
        padding: 80px 16px 16px;
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.25s ease, visibility 0.25s ease;
    }
    .search-modal-overlay.active { opacity: 1; visibility: visible; }

    .search-modal-card {
        width: 100%;
        max-width: 640px;
        background: #fff;
        border-radius: 18px;
        box-shadow: 0 30px 60px -12px rgba(0, 0, 0, 0.4), 0 0 0 1px rgba(0, 0, 0, 0.05);
        overflow: hidden;
        transform: translateY(-20px) scale(0.96);
        transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .search-modal-overlay.active .search-modal-card { transform: translateY(0) scale(1); }

    .search-modal-header {
        display: flex; align-items: center; gap: 12px;
        padding: 16px 20px;
        border-bottom: 1px solid var(--border-color);
    }
    .search-modal-header .sm-icon {
        width: 40px; height: 40px; border-radius: 12px;
        background: linear-gradient(135deg, var(--red-accent), #991b1b);
        color: #fff; display: flex; align-items: center; justify-content: center;
        font-size: 15px; flex-shrink: 0;
        box-shadow: 0 6px 16px rgba(220, 38, 38, 0.25);
    }
    .search-modal-header .sm-input-wrap { flex: 1; position: relative; }
    .search-modal-header input {
        width: 100%; padding: 10px 0; border: none; outline: none;
        font-size: 16px; font-family: inherit; color: var(--text-main);
        background: transparent; font-weight: 500;
    }
    .search-modal-header input::placeholder { color: var(--text-muted); font-weight: 400; }
    .search-modal-header .sm-esc {
        background: var(--bg-card-alt); border: 1px solid var(--border-color);
        color: var(--text-muted); font-size: 10.5px; font-weight: 800;
        padding: 5px 9px; border-radius: 6px; cursor: pointer;
        letter-spacing: 0.5px; flex-shrink: 0; transition: var(--transition);
        font-family: inherit;
    }
    .search-modal-header .sm-esc:hover {
        background: var(--red-soft); border-color: var(--red-accent); color: var(--red-accent);
    }

    .search-modal-body { max-height: 420px; overflow-y: auto; padding: 8px; }
    .search-modal-body::-webkit-scrollbar { width: 6px; }
    .search-modal-body::-webkit-scrollbar-thumb { background: var(--border-strong); border-radius: 999px; }

    .sm-state {
        display: flex; flex-direction: column; align-items: center;
        justify-content: center; padding: 40px 20px; text-align: center;
        color: var(--text-muted); gap: 8px;
    }
    .sm-state .sm-state-icon {
        width: 56px; height: 56px; border-radius: 50%;
        background: var(--bg-card-alt);
        display: flex; align-items: center; justify-content: center;
        font-size: 22px; color: var(--red-accent); margin-bottom: 4px;
    }
    .sm-state .sm-state-title { font-size: 14px; font-weight: 700; color: var(--text-main); }
    .sm-state .sm-state-sub { font-size: 12px; color: var(--text-muted); max-width: 320px; line-height: 1.5; }

    .sm-tips { display: flex; flex-wrap: wrap; gap: 6px; justify-content: center; margin-top: 10px; }
    .sm-tips .sm-tip {
        background: var(--bg-card-alt); border: 1px solid var(--border-color);
        color: var(--text-sub); font-size: 11px; font-weight: 600;
        padding: 4px 10px; border-radius: 999px;
    }
    .sm-tips .sm-tip i { color: var(--red-accent); font-size: 10px; margin-right: 3px; }

    .sm-result-group-label {
        font-size: 9.5px; font-weight: 800; text-transform: uppercase;
        letter-spacing: 0.08em; color: var(--text-muted); padding: 10px 14px 4px;
    }
    .sm-result-item {
        display: flex; align-items: center; gap: 12px;
        padding: 11px 14px; border-radius: 10px;
        text-decoration: none; color: var(--text-main);
        transition: var(--transition); border: 1px solid transparent;
    }
    .sm-result-item:hover { background: var(--red-soft); border-color: #fecdd3; }
    .sm-result-item .sm-result-icon {
        width: 38px; height: 38px; border-radius: 10px;
        background: var(--bg-card-alt); color: var(--red-accent);
        display: flex; align-items: center; justify-content: center;
        font-size: 14px; flex-shrink: 0; transition: var(--transition);
    }
    .sm-result-item:hover .sm-result-icon { background: var(--red-accent); color: #fff; }
    .sm-result-item .sm-result-body { flex: 1; min-width: 0; }
    .sm-result-item .sm-result-type {
        font-size: 9px; font-weight: 800; color: var(--red-accent);
        text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 1px;
    }
    .sm-result-item .sm-result-title {
        font-size: 13px; font-weight: 700; color: var(--text-main);
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .sm-result-item .sm-result-sub {
        font-size: 11px; color: var(--text-muted);
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .sm-result-item .sm-result-arrow {
        color: var(--text-muted); font-size: 12px; flex-shrink: 0;
        transition: var(--transition);
    }
    .sm-result-item:hover .sm-result-arrow { color: var(--red-accent); transform: translateX(3px); }

    .search-modal-footer {
        display: flex; align-items: center; justify-content: space-between;
        gap: 8px; padding: 10px 16px;
        background: var(--bg-card-alt); border-top: 1px solid var(--border-color);
        font-size: 11px; color: var(--text-muted); flex-wrap: wrap;
    }
    .search-modal-footer .sm-hint { display: inline-flex; align-items: center; gap: 6px; }
    .search-modal-footer kbd {
        background: #fff; border: 1px solid var(--border-color);
        color: var(--text-sub); font-size: 10px; font-weight: 700;
        padding: 2px 6px; border-radius: 4px;
        font-family: 'Space Grotesk', sans-serif; letter-spacing: 0.5px;
    }

    @keyframes smSpin { to { transform: rotate(360deg); } }
    .sm-spinner {
        display: inline-block; width: 20px; height: 20px;
        border: 2.5px solid var(--border-color);
        border-top-color: var(--red-accent);
        border-radius: 50%; animation: smSpin 0.7s linear infinite;
    }

    /* ============ CONTENT ============ */
    .wrapper { display: flex; min-height: calc(100vh - var(--header-total-height)); }
    .main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
    .dashboard-shell { padding: 16px 20px 30px; flex: 1; }

    .rt-panel {
        background: var(--bg-card); border: 1px solid var(--border-color);
        border-radius: 16px; padding: 20px;
        box-shadow: var(--shadow-soft);
    }

    .rt-title {
        font-family: 'Space Grotesk', sans-serif;
        color: var(--text-main); font-size: 20px; font-weight: 800; margin-bottom: 0;
        letter-spacing: -0.2px;
    }

    .rt-page-sub { color: var(--text-muted); font-size: 12px; margin-top: 2px; }

    .permit-box { background: var(--red-soft); border: 1px solid #fecdd3; border-radius: 10px; padding: 10px 12px; margin-bottom: 14px; font-size: 12px; color: var(--red-accent); font-weight: 500; }
    .permit-box strong { color: #0f172a; }

    .view-tabs {
        display: flex; gap: 8px; margin-bottom: 16px;
        background: #ffffff; border: 1px solid var(--border-color);
        border-radius: 12px; padding: 6px; flex-wrap: wrap;
    }
    .view-tab {
        flex: 1; min-width: 120px; padding: 10px 16px;
        border-radius: 8px; border: none; background: transparent;
        color: var(--text-muted); font-size: 13px; font-weight: 700;
        cursor: pointer; transition: all 0.2s ease;
        display: flex; align-items: center; justify-content: center; gap: 8px;
    }
    .view-tab:hover { background: var(--red-soft); color: var(--red-accent); }
    .view-tab.active {
        background: var(--red-accent); color: #fff;
        box-shadow: 0 4px 12px rgba(220, 38, 38, 0.4);
    }

    .inspection-type-chip {
        display: inline-block; padding: 3px 10px;
        background: var(--red-soft); color: var(--red-accent);
        border: 1px solid #fecdd3; border-radius: 6px;
        font-size: 10.5px; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.3px; white-space: nowrap;
    }
    .inspection-category-chip {
        display: inline-block; padding: 3px 10px;
        background: rgba(59, 130, 246, 0.1); color: #1d4ed8;
        border: 1px solid rgba(59, 130, 246, 0.3);
        border-radius: 6px; font-size: 10.5px; font-weight: 700; white-space: nowrap;
    }
    .owner-remarks-box {
        background: #f8fafc; border-left: 3px solid var(--red-accent);
        border-radius: 8px; padding: 10px 12px;
        font-size: 12px; color: var(--text-sub); line-height: 1.5;
        word-break: break-word; margin-top: 6px;
    }
    .owner-remarks-box strong {
        color: var(--red-accent); font-size: 10.5px;
        text-transform: uppercase; letter-spacing: 0.5px;
        display: block; margin-bottom: 3px;
    }

    .btn-start-inspection {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 7px 14px; background: var(--red-accent); color: #fff;
        border: none; border-radius: 8px; font-size: 12px; font-weight: 700;
        text-decoration: none; cursor: pointer; transition: all 0.2s ease;
        box-shadow: 0 4px 12px rgba(220, 38, 38, 0.35); white-space: nowrap;
    }
    .btn-start-inspection:hover {
        background: #991b1b; color: #fff; transform: translateY(-1px);
        box-shadow: 0 6px 16px rgba(220, 38, 38, 0.5);
    }
    .btn-continue-inspection {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 7px 14px;
        background: rgba(59, 130, 246, 0.1); color: #1d4ed8;
        border: 1px solid rgba(59, 130, 246, 0.4); border-radius: 8px;
        font-size: 12px; font-weight: 700; text-decoration: none;
        cursor: pointer; transition: all 0.2s ease; white-space: nowrap;
    }
    .btn-continue-inspection:hover {
        background: rgba(59, 130, 246, 0.2); color: #1e40af; transform: translateY(-1px);
    }
    .btn-view-report {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 7px 14px;
        background: rgba(16, 185, 129, 0.1); color: #047857;
        border: 1px solid rgba(16, 185, 129, 0.35); border-radius: 8px;
        font-size: 12px; font-weight: 700; text-decoration: none;
        cursor: pointer; transition: all 0.2s ease; white-space: nowrap;
    }
    .btn-view-report:hover {
        background: rgba(16, 185, 129, 0.2); color: #065f46; transform: translateY(-1px);
    }

    .rt-table-wrap { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .db-table { width: 100%; border-collapse: collapse; font-size: 13.5px; color: var(--text-main); background-color: transparent !important; }
    .db-table thead th { font-size: 11px; text-transform: uppercase; color: var(--text-muted) !important; border-bottom: 1px solid var(--border-color); padding: 12px 20px; font-weight: 700; background: var(--bg-card-alt) !important; white-space: nowrap; }
    .db-table tbody td { padding: 13px 20px !important; font-size: 13.5px; border-bottom: 1px solid var(--border-color) !important; color: var(--text-main) !important; vertical-align: middle; font-weight: 500; }
    .db-table tbody tr:hover { background: var(--red-soft) !important; }

    .desktop-table-view { display: none; }
    @media (min-width: 992px) { .desktop-table-view { display: block; width: 100%; overflow-x: auto; } }

    .mobile-card-view { display: block; }
    @media (min-width: 992px) { .mobile-card-view { display: none; } }
    .inspection-mobile-card {
        background: #ffffff; border: 1px solid var(--border-color);
        border-radius: 12px; padding: 14px; margin-bottom: 12px;
        box-shadow: var(--shadow-soft);
    }
    .inspection-card-header {
        display: flex; justify-content: space-between; align-items: flex-start;
        margin-bottom: 10px; border-bottom: 1px solid var(--border-color);
        padding-bottom: 8px; gap: 8px;
    }
    .inspection-card-body {
        display: grid; grid-template-columns: repeat(2, 1fr);
        gap: 8px; font-size: 12px; margin-bottom: 12px;
    }
    .inspection-card-field .label {
        color: var(--red-accent); font-size: 10px;
        text-transform: uppercase; font-weight: 700; display: block;
    }
    .inspection-card-field .value { color: var(--text-main); font-weight: 500; word-break: break-word; }

    .status-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
    .status-badge.passed { background: rgba(16, 185, 129, 0.12); color: #047857; border: 1px solid rgba(16, 185, 129, 0.35); }
    .status-badge.scheduled { background: var(--red-soft); color: var(--red-accent); border: 1px solid #fecdd3; }
    .status-badge.failed { background: rgba(239, 68, 68, 0.12); color: #b91c1c; border: 1px solid rgba(239, 68, 68, 0.35); }
    .status-badge.pending { background: rgba(245, 158, 11, 0.14); color: #b45309; border: 1px solid rgba(245, 158, 11, 0.35); }

    .stats-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 16px; }
    @media (min-width: 768px) { .stats-grid { grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 20px; } }
    .stat-card {
        display: flex; align-items: center; justify-content: space-between;
        border-radius: 12px; padding: 12px 14px; color: #fff;
        position: relative; overflow: hidden;
    }
    @media (min-width: 768px) { .stat-card { padding: 16px 18px; } }
    .stat-card::after {
        content: ''; position: absolute; top: -20px; right: -20px;
        width: 80px; height: 80px; border-radius: 50%;
        background: rgba(255,255,255,0.1); pointer-events: none;
    }
    .stat-card.bg-total { background: linear-gradient(135deg, var(--red-accent), #991b1b); }
    .stat-card.bg-pending { background: linear-gradient(135deg, #d97706, #f59e0b); }
    .stat-card.bg-completed { background: linear-gradient(135deg, #059669, #10b981); }
    .stat-card.bg-failed { background: linear-gradient(135deg, #991b1b, #dc2626); }
    .stat-card .label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: rgba(255,255,255,0.9); margin-bottom: 4px; display: block; }
    @media (min-width: 768px) { .stat-card .label { font-size: 11px; } }
    .stat-card .value { font-size: 20px; font-weight: 800; color: #fff; }
    @media (min-width: 768px) { .stat-card .value { font-size: 26px; } }
    .stat-card i { font-size: 20px; color: rgba(255,255,255,0.9); z-index: 1; }
    @media (min-width: 768px) { .stat-card i { font-size: 24px; } }

    .calendar-section { display: grid; grid-template-columns: 1fr; gap: 16px; margin-bottom: 16px; }
    @media (min-width: 992px) { .calendar-section { grid-template-columns: 1fr 340px; } }

    .calendar-card {
        background: #ffffff; border: 1px solid var(--border-color); border-radius: 16px;
        padding: 16px; box-shadow: var(--shadow-soft);
    }
    @media (min-width: 768px) { .calendar-card { padding: 20px; } }

    .calendar-header {
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px solid var(--border-color);
    }
    .calendar-header .month-title { font-size: 15px; font-weight: 800; color: var(--text-main); }
    @media (min-width: 768px) { .calendar-header .month-title { font-size: 17px; } }
    .calendar-header .nav-btn {
        background: var(--red-soft); border: 1px solid #fecdd3; color: var(--red-accent);
        width: 34px; height: 34px; border-radius: 10px; cursor: pointer;
        display: inline-flex; align-items: center; justify-content: center;
        transition: all 0.2s ease; text-decoration: none;
    }
    .calendar-header .nav-btn:hover { background: var(--red-accent); color: #fff; border-color: var(--red-accent); }

    .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 3px; }
    @media (min-width: 768px) { .calendar-grid { gap: 5px; } }
    .day-header { text-align: center; font-size: 9.5px; font-weight: 800; color: var(--red-accent); text-transform: uppercase; padding: 5px 0; }
    @media (min-width: 768px) { .day-header { font-size: 11px; padding: 6px 0; } }
    .day-cell {
        aspect-ratio: 1; border: 1px solid var(--border-color); border-radius: 8px; padding: 5px;
        background: #f8fafc; position: relative; cursor: pointer; transition: all 0.2s ease; min-height: 38px;
    }
    @media (min-width: 768px) { .day-cell { border-radius: 10px; padding: 6px; min-height: 44px; } }
    .day-cell:hover { border-color: var(--red-accent); background: var(--red-soft); }
    .day-cell .day-number { font-size: 11px; font-weight: 700; color: var(--text-sub); }
    @media (min-width: 768px) { .day-cell .day-number { font-size: 12.5px; } }
    .day-cell.today { background: var(--red-soft); border-color: var(--red-accent); }
    .day-cell.today .day-number { color: var(--red-accent); font-weight: 800; }
    .day-cell.other-month { opacity: 0.3; background: #f1f5f9; cursor: default; }
    .day-cell.has-inspection { background: linear-gradient(135deg, var(--red-accent), #991b1b); border-color: var(--red-accent); }
    .day-cell.has-inspection .day-number { color: #fff; }
    .day-cell .inspection-count {
        position: absolute; bottom: 3px; right: 4px; font-size: 9px; font-weight: 800;
        background: #ffffff; color: var(--red-accent); padding: 1px 5px; border-radius: 999px;
    }
    @media (min-width: 768px) { .day-cell .inspection-count { font-size: 10px; padding: 1px 6px; bottom: 4px; right: 6px; } }

    .upcoming-panel {
        background: #ffffff; border: 1px solid var(--border-color); border-radius: 16px;
        padding: 16px; box-shadow: var(--shadow-soft);
    }
    @media (min-width: 768px) { .upcoming-panel { padding: 20px; } }
    .panel-title {
        font-size: 14px; font-weight: 800; color: var(--text-main);
        margin-bottom: 12px; border-bottom: 1px solid var(--border-color);
        padding-bottom: 8px; display: flex; align-items: center; gap: 6px;
    }
    .panel-title i { color: var(--red-accent); }
    .upcoming-list { display: flex; flex-direction: column; gap: 10px; max-height: 480px; overflow-y: auto; padding-right: 4px; }
    .upcoming-list::-webkit-scrollbar { width: 5px; }
    .upcoming-list::-webkit-scrollbar-thumb { background: rgba(220, 38, 38, 0.4); border-radius: 999px; }
    .upcoming-item { padding: 10px 12px; background: #f8fafc; border-left: 3px solid var(--red-accent); border-radius: 8px; font-size: 12px; }
    .upcoming-item .item-date { font-size: 10px; font-weight: 800; color: var(--red-accent); text-transform: uppercase; }
    .upcoming-item .item-name { font-weight: 700; color: var(--text-main); margin: 2px 0; font-size: 12.5px; }
    .upcoming-item .item-detail { color: var(--text-muted); font-size: 11px; }

    .filter-bar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 14px; }
    .filter-bar input[type="text"], .filter-bar select {
        width: 100%; background: #f8fafc; border: 1px solid var(--border-color);
        color: var(--text-main); border-radius: 10px; padding: 9px 12px; font-size: 13.5px;
    }
    @media (min-width: 768px) {
        .filter-bar input[type="text"] { flex: 1; max-width: 280px; }
        .filter-bar select { flex: 1; max-width: 200px; }
    }

    .insp-detail-section { margin-bottom: 18px; padding-bottom: 14px; border-bottom: 1px dashed var(--border-color); }
    .insp-detail-section:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
    .insp-detail-section-title { font-size: 11px; font-weight: 800; text-transform: uppercase; color: var(--red-accent); letter-spacing: 0.8px; margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }
    .insp-detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 18px; }
    @media (max-width: 480px) { .insp-detail-grid { grid-template-columns: 1fr; } }
    .insp-detail-item .label { font-size: 10px; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 3px; }
    .insp-detail-item .value { font-size: 13px; font-weight: 600; color: var(--text-main); word-break: break-word; }
    .insp-detail-item.full { grid-column: 1 / -1; }

    .permit-number-box {
        font-family: 'Courier New', monospace; font-weight: 700;
        color: var(--red-accent); background: var(--red-soft);
        padding: 4px 12px; border-radius: 6px; display: inline-block;
        font-size: 13px; letter-spacing: 0.5px; border: 1px solid #fecdd3;
    }

    .modal-content {
        background: #ffffff !important; border: 1px solid var(--border-color) !important;
        color: var(--text-main) !important; border-radius: 16px !important;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35);
    }
    .modal-header { border-bottom: 1px solid var(--border-color) !important; padding: 14px 18px; }
    .modal-footer { border-top: 1px solid var(--border-color) !important; padding: 12px 18px; }
    .modal-body { padding: 16px; }
    .modal-title { color: var(--red-accent) !important; }

    /* Footer */
    .footer {
        margin-top: 20px; padding: 16px 0 calc(16px + var(--safe-bottom)) 0;
        border-top: 1px solid var(--border-color); display: flex;
        justify-content: space-between; align-items: center; font-size: 11.5px;
        color: var(--text-muted); flex-wrap: wrap; gap: 8px; text-align: center;
    }
    .footer strong { color: var(--red-accent); }

    /* Alerts */
    .alert-success-custom {
        background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0;
        padding: 12px 16px; border-radius: 12px; font-size: 13px; margin-bottom: 14px;
    }
    .alert-danger-custom {
        background: #fef2f2; color: #dc2626; border: 1px solid #fecaca;
        padding: 12px 16px; border-radius: 12px; font-size: 13px; margin-bottom: 14px;
    }

    /* ============ RESPONSIVE ============ */
    @media (max-width: 1400px) { .rt-search-trigger { width: 220px; } }
    @media (max-width: 1200px) {
        .rt-topbar-center { gap: 12px; }
        .rt-search-trigger { width: 180px; }
        .rt-search-trigger .kbd-hint { display: none; }
        .rt-nav-link { padding: 8px 10px; font-size: 12.5px; }
    }
    @media (max-width: 992px) {
        .mobile-menu-btn { display: flex; }
        .rt-topbar-center { display: contents; }
        .rt-main-nav {
            display: none; position: fixed;
            top: var(--header-total-height); left: 0; width: 100%;
            height: auto; max-height: calc(100vh - var(--header-total-height));
            background: var(--navbar-bg); border-bottom: 1px solid var(--border-color);
            flex-direction: column; align-items: stretch;
            padding: 10px 16px 14px; gap: 4px; overflow-y: auto;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15); z-index: 1001;
        }
        body.mobile-nav-open .rt-main-nav { display: flex; }
        .rt-nav-link { width: 100%; justify-content: flex-start; padding: 12px 14px; height: auto; font-size: 13.5px; border-radius: 10px; }
        .rt-nav-link.active { box-shadow: none; }
        .rt-nav-group { flex-direction: column; align-items: stretch; width: 100%; height: auto; }
        .rt-nav-group > .rt-nav-link { justify-content: space-between; }
        .rt-nav-dropdown {
            position: static; opacity: 1; visibility: visible; transform: none;
            box-shadow: none; background: var(--bg-card-alt);
            margin: 4px 0 6px 0; border: 1px solid var(--border-color);
            padding: 6px; display: none; border-radius: 10px;
        }
        .rt-nav-group.open > .rt-nav-dropdown { display: block; }
        .rt-nav-group:hover > .rt-nav-dropdown { display: none; }
        .rt-nav-group.open:hover > .rt-nav-dropdown { display: block; }
        .rt-search-trigger { display: none; }
        .mobile-search-btn { display: flex; }
        .rt-topbar-brand { border-right: none; padding-right: 0; }
    }
    @media (max-width: 768px) {
        .rt-topbar-brand-text strong { font-size: 12px; }
        .rt-topbar-right { gap: 8px; }
        .dashboard-shell { padding: 14px 14px 24px; }
        .rt-panel { padding: 16px; }
        .rt-title { font-size: 18px; }
    }
    @media (max-width: 576px) {
        .rt-topbar { padding: 0 calc(10px + var(--safe-right)) 0 calc(10px + var(--safe-left)); gap: 6px; }
        .rt-topbar-brand { padding-right: 0; gap: 8px; height: 40px; }
        .rt-topbar-brand-text { display: none; }
        .rt-topbar-logo { width: 32px; height: 32px; font-size: 12px; }
        .rt-profile-btn { padding: 4px; }
        .rt-profile-btn .info { display: none !important; }
        .rt-profile-btn i { display: none; }
        .dashboard-shell { padding: 10px 10px 20px; }
        .rt-panel { padding: 12px; border-radius: 12px; }
        .rt-title { font-size: 16px; }
        .footer { font-size: 10px; flex-direction: column; text-align: center; }
        .search-modal-overlay { padding: 60px 10px 10px; }
        .search-modal-card { border-radius: 14px; }
        .search-modal-header { padding: 12px 14px; gap: 10px; }
        .search-modal-header .sm-icon { width: 34px; height: 34px; font-size: 13px; }
        .search-modal-header input { font-size: 15px; }
        .search-modal-body { max-height: 60vh; }
    }
    @supports (-webkit-touch-callout: none) {
        input, button, select, textarea { font-size: 16px !important; }
        .search-modal-header input { font-size: 16px !important; }
    }
</style>
</head>
<body>

<!-- TOP OFFICIAL BAR -->
<div class="top-official-bar">
    <div class="topbar-left">
        <i class="fa-solid fa-landmark"></i>
        <strong>REPUBLIC OF THE PHILIPPINES</strong>
        <span class="divider"></span>
        <span class="portal-title">LGU Rental Portal</span>
    </div>
    <div class="topbar-right">
        <span class="cert-badge"><i class="fa-solid fa-certificate"></i> V2.1</span>
        <span class="d-none d-lg-inline" style="font-size:11px; color:#fecdd3;">Inspector Field Operations</span>
    </div>
</div>

<!-- MERGED HEADER -->
<header class="rt-topbar">

    <button class="icon-btn mobile-menu-btn" id="mobileMenuBtn" title="Menu">
        <i class="fa-solid fa-bars"></i>
    </button>

    <div class="rt-topbar-brand">
        <div class="rt-topbar-logo">RT</div>
        <div class="rt-topbar-brand-text">
            <strong>RENTRACK</strong>
            <span>INSPECTOR</span>
        </div>
    </div>

    <div class="rt-topbar-center">
        <nav class="rt-main-nav" id="rtMainNav">
            <a href="dashboard.php" class="rt-nav-link">
                <i class="fa-solid fa-gauge-high"></i> <span>Dashboard</span>
            </a>

            <!-- FIELD OPS -->
            <div class="rt-nav-group">
                <button type="button" class="rt-nav-link rt-nav-btn has-active">
                    <i class="fa-solid fa-clipboard-check"></i> <span>Field Ops</span>
                    <i class="fa-solid fa-chevron-down rt-caret"></i>
                </button>
                <div class="rt-nav-dropdown">
                    <a href="inspections.php" class="active">
                        <i class="fa-solid fa-clipboard-check"></i> Inspections
                    </a>
                    <a href="calendar.php"><i class="fa-regular fa-calendar"></i> Calendar</a>
                    <a href="findings.php"><i class="fa-solid fa-triangle-exclamation"></i> Findings</a>
                </div>
            </div>

            <!-- REPORTS -->
            <div class="rt-nav-group">
                <button type="button" class="rt-nav-link rt-nav-btn">
                    <i class="fa-solid fa-file-lines"></i> <span>Reports</span>
                    <i class="fa-solid fa-chevron-down rt-caret"></i>
                </button>
                <div class="rt-nav-dropdown">
                    <a href="properties.php"><i class="fa-solid fa-building"></i> Properties</a>
                    <a href="reports.php"><i class="fa-solid fa-file-lines"></i> Reports</a>
                </div>
            </div>

            <!-- ACCOUNT -->
            <div class="rt-nav-group">
                <button type="button" class="rt-nav-link rt-nav-btn">
                    <i class="fa-solid fa-user"></i> <span>Account</span>
                    <i class="fa-solid fa-chevron-down rt-caret"></i>
                </button>
                <div class="rt-nav-dropdown">
                    <a href="profile.php"><i class="fa-solid fa-user"></i> Profile</a>
                    <a href="settings.php"><i class="fa-solid fa-gear"></i> Settings</a>
                </div>
            </div>
        </nav>

        <div class="rt-search-trigger" id="openSearchBtn" role="button" tabindex="0">
            <i class="fa-solid fa-magnifying-glass"></i>
            <span class="search-placeholder">Search properties, owners...</span>
            <span class="kbd-hint">Ctrl K</span>
        </div>
    </div>

    <div class="rt-topbar-right">
        <button class="icon-btn mobile-search-btn" id="mobileSearchBtn" title="Search">
            <i class="fa-solid fa-magnifying-glass"></i>
        </button>

        <div style="position:relative;">
            <button class="icon-btn" id="notifBtn" title="Notifications">
                <i class="fa-regular fa-bell"></i>
            </button>
            <div id="notifDropdown" style="display:none; position:absolute; top:48px; right:0; width:300px; max-width:calc(100vw - 20px); background:#fff; border:1px solid var(--border-color); border-radius:14px; padding:12px; box-shadow:0 10px 30px rgba(0,0,0,0.1); z-index:999;">
                <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); padding-bottom:8px; margin-bottom:8px;">
                    <span style="font-weight:700; color:var(--text-main); font-size:12px;">Notifications</span>
                </div>
                <div style="font-size:11px; color:var(--text-muted); text-align:center; padding:14px 0;">
                    <i class="fa-regular fa-circle-check me-1"></i> You're all caught up.
                </div>
            </div>
        </div>

        <div class="rt-profile-wrapper">
            <div class="rt-profile-btn" id="profileToggle">
                <div class="avatar"><?php echo $userInitial; ?></div>
                <div class="info d-none d-sm-flex">
                    <span class="name"><?php echo $fullname; ?></span>
                    <span class="role"><?php echo htmlspecialchars($staffRole, ENT_QUOTES, 'UTF-8'); ?></span>
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

<!-- SEARCH MODAL -->
<div class="search-modal-overlay" id="searchModalOverlay">
    <div class="search-modal-card" role="dialog" aria-modal="true">
        <div class="search-modal-header">
            <div class="sm-icon"><i class="fa-solid fa-magnifying-glass"></i></div>
            <div class="sm-input-wrap">
                <input type="text" id="searchModalInput" placeholder="Search inspections, properties, owners..." autocomplete="off" spellcheck="false">
            </div>
            <button type="button" class="sm-esc" id="searchModalClose">ESC</button>
        </div>

        <div class="search-modal-body" id="searchModalBody">
            <div class="sm-state">
                <div class="sm-state-icon"><i class="fa-solid fa-magnifying-glass"></i></div>
                <div class="sm-state-title">Search Inspections</div>
                <div class="sm-state-sub">Filter your assigned inspections by property name, owner, tracking number, or barangay.</div>
                <div class="sm-tips">
                    <span class="sm-tip"><i class="fa-solid fa-building"></i> Property name</span>
                    <span class="sm-tip"><i class="fa-solid fa-user"></i> Owner name</span>
                    <span class="sm-tip"><i class="fa-solid fa-hashtag"></i> Tracking number</span>
                </div>
            </div>
        </div>

        <div class="search-modal-footer">
            <span class="sm-hint"><kbd>Enter</kbd> Open first result</span>
            <span class="sm-hint"><kbd>ESC</kbd> Close</span>
        </div>
    </div>
</div>

<div class="wrapper">
    <div class="main">
        <div class="dashboard-shell">
            <div class="rt-panel">

                <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
                    <div>
                        <h2 class="rt-title">Inspections</h2>
                        <p class="rt-page-sub">View and manage your assigned property inspections.</p>
                    </div>
                    <span class="rt-live-pill" style="display:inline-flex; align-items:center; gap:7px; background:rgba(5,150,105,0.1); border:1px solid rgba(5,150,105,0.2); color:var(--emerald-accent); font-size:11px; font-weight:700; padding:6px 12px; border-radius:999px;">
                        <span style="width:7px; height:7px; border-radius:50%; background:var(--emerald-accent); box-shadow:0 0 8px var(--emerald-accent);"></span>
                        <?php echo count($inspections); ?> total records
                    </span>
                </div>

                <?php if ($successMsg !== '') { ?>
                    <div class="alert-success-custom"><i class="fa-solid fa-circle-check me-1"></i> <?php echo htmlspecialchars($successMsg); ?></div>
                <?php } ?>
                <?php if ($errorMsg !== '') { ?>
                    <div class="alert-danger-custom"><i class="fa-solid fa-circle-exclamation me-1"></i> <?php echo htmlspecialchars($errorMsg); ?></div>
                <?php } ?>

                <!-- VIEW TABS -->
                <div class="view-tabs">
                    <button type="button" class="view-tab active" id="tabListView" onclick="switchView('list')">
                        <i class="fa-solid fa-list"></i> List View
                    </button>
                    <button type="button" class="view-tab" id="tabCalendarView" onclick="switchView('calendar')">
                        <i class="fa-regular fa-calendar-check"></i> Calendar View
                    </button>
                </div>

                <!-- VIEW 1: LIST VIEW -->
                <div id="listViewContainer">
                    <div style="background:#fff; border:1px solid var(--border-color); border-radius:16px; overflow:hidden;">
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; padding:14px 18px; border-bottom:1px solid var(--border-color);">
                            <h5 style="font-weight:800; margin:0; font-size:14.5px; color:var(--text-main);">
                                <i class="fa-solid fa-clipboard-list me-2" style="color:var(--red-accent);"></i> My Assigned Inspections
                            </h5>
                            <a href="inspections.php" class="small text-decoration-none fw-bold" style="color:var(--red-accent); font-size:12px;">
                                <i class="fa-solid fa-rotate me-1"></i> Reset filters
                            </a>
                        </div>

                        <div style="padding:16px;">
                            <form method="GET" class="filter-bar">
                                <input type="text" name="q" id="localSearch" placeholder="Search property, owner..." value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>">
                                <select name="status" onchange="this.form.submit()">
                                    <option value="all" <?php echo $statusFilterParam === '' || $statusFilterParam === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                    <?php foreach (['Scheduled', 'Ongoing', 'Completed', 'Passed', 'Failed', 'Cancelled'] as $s) { ?>
                                        <option value="<?php echo $s; ?>" <?php echo $statusFilterParam === $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
                                    <?php } ?>
                                </select>
                                <button type="submit" class="btn fw-semibold px-3" style="background:var(--red-accent); color:#fff; border:none; font-size:13px; border-radius:10px;">
                                    <i class="fa-solid fa-filter me-1"></i> Filter
                                </button>
                            </form>

                            <?php if (empty($inspections)) { ?>
                                <div style="text-align:center; padding:40px 20px;">
                                    <i class="fa-solid fa-calendar-xmark fa-3x mb-3" style="color:var(--red-accent); opacity:0.6;"></i>
                                    <p style="font-size:14px; font-weight:700; color:var(--text-main);">No inspections assigned to you yet.</p>
                                </div>
                            <?php } else { ?>

                                <!-- DESKTOP TABLE -->
                                <div class="desktop-table-view">
                                    <table class="db-table" id="inspectionsTableDesktop">
                                        <thead>
                                            <tr>
                                                <th>Property</th>
                                                <th>Permit No.</th>
                                                <th>Owner</th>
                                                <th>Barangay</th>
                                                <th>Date / Time</th>
                                                <th>Type</th>
                                                <th>Status</th>
                                                <th style="min-width: 260px;">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($inspections as $row) {
                                                $currentStatus = trim($row['inspection_status'] ?? '');
                                                if ($currentStatus === '') $currentStatus = 'Pending';
                                                $statusKey = strtolower($currentStatus);
                                                $displayDate = !empty($row['inspection_date']) ? date('M d, Y', strtotime($row['inspection_date'])) : 'TBD';
                                                $displayTime = !empty($row['inspection_time']) ? date('g:i A', strtotime($row['inspection_time'])) : '';

                                                $typeLabel = $hasInspectionType ? getInspectionTypeLabel($row['inspection_type'] ?? '') : '';
                                                $categoryLabel = $hasInspectionCategory ? getInspectionCategoryLabel($row['inspection_category'] ?? '') : '';
                                                $remarks = $hasRemarks ? ($row['remarks'] ?? '') : '';
                                                $photoCount = $photoCounts[(int) $row['id']] ?? 0;
                                                $rowSearchBlob = strtolower(($row['rental_name'] ?? '') . ' ' . ($row['business_name'] ?? '') . ' ' . ($row['owner_name'] ?? '') . ' ' . ($row['barangay'] ?? '') . ' ' . ($row['tracking_no'] ?? ''));
                                                $displayPermitNo = !empty($row['doc_permit_no']) ? $row['doc_permit_no'] : null;
                                            ?>
                                            <tr data-search="<?php echo htmlspecialchars($rowSearchBlob, ENT_QUOTES, 'UTF-8'); ?>">
                                                <td>
                                                    <strong style="color:var(--text-main);"><?php echo htmlspecialchars($row['rental_name'] ?? $row['business_name'] ?? 'N/A'); ?></strong><br>
                                                    <small style="color:var(--red-accent);"><?php echo htmlspecialchars($row['tracking_no'] ?? ''); ?></small>
                                                </td>
                                                <td>
                                                    <?php if ($displayPermitNo):
                                                        $prefix = explode('-', $displayPermitNo)[0];
                                                        $permitTypeLabel = getPermitTypeLabelFromPrefix($prefix);
                                                    ?>
                                                        <span class="permit-number-box" style="font-size:11.5px; padding:3px 10px;"><?php echo h($displayPermitNo); ?></span>
                                                        <br><small style="font-size:9.5px; color:var(--text-muted);"><?php echo $permitTypeLabel; ?></small>
                                                    <?php else: ?>
                                                        <span style="color:#94a3b8; font-style:italic; font-size:11px;">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($row['owner_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($row['barangay'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <?php echo $displayDate; ?>
                                                    <?php if ($displayTime): ?>
                                                        <br><small style="color:var(--red-accent);"><i class="fa-regular fa-clock"></i> <?php echo $displayTime; ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($typeLabel) && $typeLabel !== '—'): ?>
                                                        <span class="inspection-type-chip"><?php echo h($typeLabel); ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo statusBadge($currentStatus); ?>
                                                    <?php if ($photoCount > 0): ?>
                                                        <br><small style="color:var(--red-accent);"><i class="fa-solid fa-camera"></i> <?php echo $photoCount; ?> photo<?php echo $photoCount > 1 ? 's' : ''; ?></small>
                                                    <?php endif; ?>
                                                    <?php if (!empty($row['inspector_signature'])): ?>
                                                        <br><small style="color:var(--red-accent);"><i class="fa-solid fa-signature"></i> Signed</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                                        <?php if ($statusKey === 'scheduled' || $statusKey === 'pending' || $statusKey === '') { ?>
                                                            <a href="inspection_form.php?id=<?php echo (int)$row['id']; ?>" class="btn-start-inspection"><i class="fa-solid fa-play"></i> Start</a>
                                                        <?php } elseif ($statusKey === 'ongoing') { ?>
                                                            <a href="inspection_form.php?id=<?php echo (int)$row['id']; ?>" class="btn-continue-inspection"><i class="fa-solid fa-forward"></i> Continue</a>
                                                        <?php } else { ?>
                                                            <a href="print_report.php?id=<?php echo (int)$row['id']; ?>" target="_blank" class="btn-view-report"><i class="fa-solid fa-file-lines"></i> Report</a>
                                                        <?php } ?>

                                                        <button class="btn btn-sm fw-semibold px-2 py-1"
                                                                style="border:1px solid rgba(59,130,246,0.4); color:#1d4ed8; background:rgba(59,130,246,0.08); font-size:12px;"
                                                                onclick='viewInspectionDetail(<?php echo json_encode([
                                                                    "id" => $row["id"],
                                                                    "inspection_no" => $row["inspection_no"] ?? "",
                                                                    "rental_name" => $row["rental_name"] ?? "",
                                                                    "business_name" => $row["business_name"] ?? "",
                                                                    "tracking_no" => $row["tracking_no"] ?? "",
                                                                    "barangay" => $row["barangay"] ?? "",
                                                                    "business_address" => $row["business_address"] ?? "",
                                                                    "owner_name" => $row["owner_name"] ?? "",
                                                                    "inspection_date" => $row["inspection_date"] ?? "",
                                                                    "inspection_time" => $row["inspection_time"] ?? "",
                                                                    "inspection_status" => $currentStatus,
                                                                    "type_label" => $typeLabel,
                                                                    "category_label" => $categoryLabel,
                                                                    "remarks" => $remarks,
                                                                    "inspector_notes" => $row["inspector_notes"] ?? "",
                                                                    "permit_displayed" => $row["permit_displayed"] ?? "N/A",
                                                                    "permit_matches" => $row["permit_matches"] ?? "N/A",
                                                                    "doc_permit_no" => $row["doc_permit_no"] ?? "",
                                                                    "doc_permit_expiry" => $row["doc_permit_expiry"] ?? "",
                                                                ], ENT_QUOTES); ?>)'>
                                                            <i class="fa-solid fa-eye me-1"></i> View
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php } ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- MOBILE CARDS -->
                                <div class="mobile-card-view" id="inspectionsContainerMobile">
                                    <?php foreach ($inspections as $row) {
                                        $currentStatus = trim($row['inspection_status'] ?? '');
                                        if ($currentStatus === '') $currentStatus = 'Pending';
                                        $statusKey = strtolower($currentStatus);
                                        $displayDate = !empty($row['inspection_date']) ? date('M d, Y', strtotime($row['inspection_date'])) : 'TBD';
                                        $displayTime = !empty($row['inspection_time']) ? date('g:i A', strtotime($row['inspection_time'])) : '';

                                        $typeLabel = $hasInspectionType ? getInspectionTypeLabel($row['inspection_type'] ?? '') : '';
                                        $categoryLabel = $hasInspectionCategory ? getInspectionCategoryLabel($row['inspection_category'] ?? '') : '';
                                        $remarks = $hasRemarks ? ($row['remarks'] ?? '') : '';
                                        $photoCount = $photoCounts[(int) $row['id']] ?? 0;
                                        $rowSearchBlob = strtolower(($row['rental_name'] ?? '') . ' ' . ($row['business_name'] ?? '') . ' ' . ($row['owner_name'] ?? '') . ' ' . ($row['barangay'] ?? '') . ' ' . ($row['tracking_no'] ?? ''));
                                        $displayPermitNoMobile = !empty($row['doc_permit_no']) ? $row['doc_permit_no'] : null;
                                    ?>
                                    <div class="inspection-mobile-card" data-search="<?php echo htmlspecialchars($rowSearchBlob, ENT_QUOTES, 'UTF-8'); ?>">
                                        <div class="inspection-card-header">
                                            <div style="min-width:0; flex:1;">
                                                <strong style="color:var(--text-main); font-size:13.5px; word-break:break-word;"><?php echo htmlspecialchars($row['rental_name'] ?? $row['business_name'] ?? 'N/A'); ?></strong><br>
                                                <small style="color:var(--red-accent); font-size:11px;"><?php echo htmlspecialchars($row['tracking_no'] ?? ''); ?></small>
                                            </div>
                                            <div style="flex-shrink:0;"><?php echo statusBadge($currentStatus); ?></div>
                                        </div>

                                        <?php if ($displayPermitNoMobile): ?>
                                            <div style="margin-bottom:8px;">
                                                <span class="permit-number-box" style="font-size:11.5px; padding:3px 10px;">
                                                    <i class="fa-solid fa-id-card" style="font-size:10px; margin-right:4px;"></i>
                                                    <?php echo h($displayPermitNoMobile); ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($typeLabel) && $typeLabel !== '—'): ?>
                                            <div style="margin-bottom:8px;">
                                                <span class="inspection-type-chip"><?php echo h($typeLabel); ?></span>
                                                <?php if (!empty($categoryLabel) && $categoryLabel !== '—'): ?>
                                                    <span class="inspection-category-chip" style="margin-left:4px;"><?php echo h($categoryLabel); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>

                                        <div class="inspection-card-body">
                                            <div class="inspection-card-field">
                                                <span class="label">Owner</span>
                                                <span class="value"><?php echo htmlspecialchars($row['owner_name'] ?? 'N/A'); ?></span>
                                            </div>
                                            <div class="inspection-card-field">
                                                <span class="label">Barangay</span>
                                                <span class="value"><?php echo htmlspecialchars($row['barangay'] ?? 'N/A'); ?></span>
                                            </div>
                                            <div class="inspection-card-field">
                                                <span class="label">Date</span>
                                                <span class="value"><?php echo $displayDate; ?></span>
                                            </div>
                                            <div class="inspection-card-field">
                                                <span class="label">Time</span>
                                                <span class="value"><?php echo $displayTime ?: '—'; ?></span>
                                            </div>
                                            <div class="inspection-card-field">
                                                <span class="label">Attachments</span>
                                                <span class="value">
                                                    <?php if ($photoCount > 0): ?>
                                                        <small style="color:var(--red-accent);"><i class="fa-solid fa-camera"></i> <?php echo $photoCount; ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">None</span>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                            <div class="inspection-card-field">
                                                <span class="label">Signature</span>
                                                <span class="value">
                                                    <?php if (!empty($row['inspector_signature'])): ?>
                                                        <i class="fa-solid fa-signature" style="color:var(--red-accent);"></i> Signed
                                                    <?php else: ?>
                                                        <span class="text-muted">Not signed</span>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                        </div>

                                        <?php if (!empty($remarks)): ?>
                                            <div class="owner-remarks-box">
                                                <strong><i class="fa-solid fa-comment-dots me-1"></i> Owner Remarks</strong>
                                                <?php echo nl2br(h($remarks)); ?>
                                            </div>
                                        <?php endif; ?>

                                        <div class="d-flex gap-2 pt-3 border-top mt-3" style="flex-wrap:wrap; border-color:var(--border-color) !important;">
                                            <?php if ($statusKey === 'scheduled' || $statusKey === 'pending' || $statusKey === '') { ?>
                                                <a href="inspection_form.php?id=<?php echo (int)$row['id']; ?>" class="btn-start-inspection" style="flex:1; justify-content:center;">
                                                    <i class="fa-solid fa-play"></i> Start Inspection
                                                </a>
                                            <?php } elseif ($statusKey === 'ongoing') { ?>
                                                <a href="inspection_form.php?id=<?php echo (int)$row['id']; ?>" class="btn-continue-inspection" style="flex:1; justify-content:center;">
                                                    <i class="fa-solid fa-forward"></i> Continue
                                                </a>
                                            <?php } else { ?>
                                                <a href="print_report.php?id=<?php echo (int)$row['id']; ?>" target="_blank" class="btn-view-report" style="flex:1; justify-content:center;">
                                                    <i class="fa-solid fa-file-lines"></i> View Report
                                                </a>
                                            <?php } ?>

                                            <button class="btn btn-sm fw-semibold px-3 py-1"
                                                    style="border:1px solid rgba(59,130,246,0.4); color:#1d4ed8; background:rgba(59,130,246,0.08); font-size:12px;"
                                                    onclick='viewInspectionDetail(<?php echo json_encode([
                                                        "id" => $row["id"],
                                                        "inspection_no" => $row["inspection_no"] ?? "",
                                                        "rental_name" => $row["rental_name"] ?? "",
                                                        "business_name" => $row["business_name"] ?? "",
                                                        "tracking_no" => $row["tracking_no"] ?? "",
                                                        "barangay" => $row["barangay"] ?? "",
                                                        "business_address" => $row["business_address"] ?? "",
                                                        "owner_name" => $row["owner_name"] ?? "",
                                                        "inspection_date" => $row["inspection_date"] ?? "",
                                                        "inspection_time" => $row["inspection_time"] ?? "",
                                                        "inspection_status" => $currentStatus,
                                                        "type_label" => $typeLabel,
                                                        "category_label" => $categoryLabel,
                                                        "remarks" => $remarks,
                                                        "inspector_notes" => $row["inspector_notes"] ?? "",
                                                        "permit_displayed" => $row["permit_displayed"] ?? "N/A",
                                                        "permit_matches" => $row["permit_matches"] ?? "N/A",
                                                        "doc_permit_no" => $row["doc_permit_no"] ?? "",
                                                        "doc_permit_expiry" => $row["doc_permit_expiry"] ?? "",
                                                    ], ENT_QUOTES); ?>)'>
                                                <i class="fa-solid fa-eye me-1"></i> View
                                            </button>
                                        </div>
                                    </div>
                                    <?php } ?>
                                </div>

                            <?php } ?>
                        </div>
                    </div>
                </div>

                <!-- VIEW 2: CALENDAR VIEW -->
                <div id="calendarViewContainer" style="display:none;">

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
                            <div class="panel-title"><i class="fa-solid fa-clock-rotate-left"></i> Upcoming Visits</div>
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
                                <div style="text-align:center; padding:20px; color:var(--text-muted); font-size:12px;">
                                    <i class="fa-regular fa-calendar-xmark" style="font-size:24px; opacity:0.5; display:block; margin-bottom:6px;"></i>
                                    No upcoming visits.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div>

            <footer class="footer">
                <span>&copy; <?php echo date('Y'); ?> <strong>RENTRACK LGU</strong> &middot; Inspector Portal</span>
                <span>Field Operations &amp; Compliance System</span>
            </footer>
        </div>
    </div>
</div>

<!-- VIEW DETAIL MODAL -->
<div class="modal fade" id="viewDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold fs-6"><i class="fa-solid fa-clipboard-list me-2"></i> Inspection Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewDetailBody">
                <div class="text-center py-4">
                    <i class="fa-solid fa-spinner fa-spin fa-2x" style="color:var(--red-accent);"></i>
                    <p class="mt-2 small">Loading details...</p>
                </div>
            </div>
            <div class="modal-footer" id="viewDetailFooter">
                <button type="button" class="btn btn-secondary fw-semibold btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- CALENDAR DAY DETAIL MODAL -->
<div class="modal fade" id="calendarDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold fs-6"><i class="fa-solid fa-calendar-day me-2"></i> Day Inspections</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="calendarDetailBody">
                <div class="text-center py-4">
                    <i class="fa-solid fa-spinner fa-spin fa-2x" style="color:var(--red-accent);"></i>
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
                <h5 class="modal-title fw-bold" style="font-size:15px;"><i class="fa-solid fa-triangle-exclamation me-2"></i> Logout Confirmation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-4 text-center">
                <p class="small mb-0" style="color:#475569;">Are you sure you want to log out from your session?</p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn px-4 py-2" data-bs-dismiss="modal" style="font-size:12px; background:#f1f5f9; border:1px solid #e2e8f0; color:#0f172a;">Cancel</button>
                <a href="includes/auth.php?logout=true" class="btn px-4 py-2" style="font-size:12px; background:#dc2626; border:none; color:#fff;">Yes, Logout</a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    /* ============ MOBILE MENU ============ */
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    if (mobileMenuBtn) {
        mobileMenuBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            document.body.classList.toggle('mobile-nav-open');
        });
    }

    /* ============ NAV DROPDOWN TOGGLE ============ */
    document.querySelectorAll('.rt-nav-group > .rt-nav-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const group = btn.parentElement;
            document.querySelectorAll('.rt-nav-group').forEach(g => {
                if (g !== group) g.classList.remove('open');
            });
            group.classList.toggle('open');
        });
    });

    document.addEventListener('click', (e) => {
        const nav = document.getElementById('rtMainNav');
        if (!nav || !nav.contains(e.target)) {
            document.querySelectorAll('.rt-nav-group').forEach(g => g.classList.remove('open'));
            if (!e.target.closest('#mobileMenuBtn')) {
                document.body.classList.remove('mobile-nav-open');
            }
        }
        if (profileDropdown) profileDropdown.classList.remove('show');
        if (notifDropdown) notifDropdown.style.display = 'none';
    });

    document.querySelectorAll('.rt-nav-dropdown a, .rt-nav-link:not(.rt-nav-btn)').forEach(link => {
        link.addEventListener('click', () => {
            document.body.classList.remove('mobile-nav-open');
        });
    });

    /* ============ NOTIFICATIONS ============ */
    const notifBtn = document.getElementById('notifBtn');
    const notifDropdown = document.getElementById('notifDropdown');

    if (notifBtn) {
        notifBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            const isOpen = notifDropdown.style.display === 'block';
            notifDropdown.style.display = isOpen ? 'none' : 'block';
        });
    }

    /* ============ PROFILE DROPDOWN ============ */
    const profileToggle = document.getElementById('profileToggle');
    const profileDropdown = document.getElementById('profileDropdown');

    if (profileToggle) {
        profileToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            profileDropdown.classList.toggle('show');
        });
    }

    /* ============ LOGOUT ============ */
    document.getElementById('profileLogoutBtn')?.addEventListener('click', function(e) {
        e.preventDefault();
        new bootstrap.Modal(document.getElementById('logoutModal')).show();
    });

    /* ============ SEARCH MODAL ============ */
    const searchOverlay = document.getElementById('searchModalOverlay');
    const searchInput = document.getElementById('searchModalInput');
    const searchBody = document.getElementById('searchModalBody');
    const openSearchBtn = document.getElementById('openSearchBtn');
    const searchModalCloseBtn = document.getElementById('searchModalClose');

    function openSearchModal() {
        searchOverlay.classList.add('active');
        document.body.style.overflow = 'hidden';
        setTimeout(() => searchInput.focus(), 120);
    }
    function closeSearchModal() {
        searchOverlay.classList.remove('active');
        document.body.style.overflow = '';
        searchInput.value = '';
        resetSearchBody();
    }
    function resetSearchBody() {
        searchBody.innerHTML = `
            <div class="sm-state">
                <div class="sm-state-icon"><i class="fa-solid fa-magnifying-glass"></i></div>
                <div class="sm-state-title">Search Inspections</div>
                <div class="sm-state-sub">Filter your assigned inspections by property name, owner, tracking number, or barangay.</div>
                <div class="sm-tips">
                    <span class="sm-tip"><i class="fa-solid fa-building"></i> Property name</span>
                    <span class="sm-tip"><i class="fa-solid fa-user"></i> Owner name</span>
                    <span class="sm-tip"><i class="fa-solid fa-hashtag"></i> Tracking number</span>
                </div>
            </div>
        `;
    }

    openSearchBtn?.addEventListener('click', openSearchModal);
    openSearchBtn?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openSearchModal(); }
    });
    document.getElementById('mobileSearchBtn')?.addEventListener('click', openSearchModal);
    searchModalCloseBtn?.addEventListener('click', closeSearchModal);
    searchOverlay?.addEventListener('click', (e) => {
        if (e.target === searchOverlay) closeSearchModal();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && searchOverlay.classList.contains('active')) {
            closeSearchModal();
            return;
        }
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
            e.preventDefault();
            openSearchModal();
        }
    });

    /* Live filter using existing data-search attributes */
    searchInput?.addEventListener('input', (e) => {
        const q = e.target.value.trim().toLowerCase();

        if (q.length < 2) {
            resetSearchBody();
            return;
        }

        const matches = [];
        document.querySelectorAll('tr[data-search], .inspection-mobile-card[data-search]').forEach(el => {
            if (el.dataset.search.includes(q)) {
                const isMobile = el.classList.contains('inspection-mobile-card');
                if (isMobile) {
                    const name = el.querySelector('strong')?.textContent?.trim() || '';
                    const track = el.querySelector('small')?.textContent?.trim() || '';
                    matches.push({
                        title: name || track || 'Inspection',
                        sub: track ? 'Tracking: ' + track : '',
                        href: '#',
                        icon: 'fa-clipboard-check',
                        type: 'Inspection',
                        el: el
                    });
                }
            }
        });

        // Deduplicate by title (mobile cards preferred)
        const seen = new Set();
        const unique = [];
        matches.forEach(m => {
            const key = m.title + '|' + m.sub;
            if (!seen.has(key)) {
                seen.add(key);
                unique.push(m);
            }
        });

        searchBody.innerHTML = '';
        if (unique.length === 0) {
            searchBody.innerHTML = `
                <div class="sm-state">
                    <div class="sm-state-icon"><i class="fa-solid fa-magnifying-glass"></i></div>
                    <div class="sm-state-title">No results found</div>
                    <div class="sm-state-sub">No inspections matched "<strong>${escapeHtml(q)}</strong>". Try a different keyword.</div>
                </div>
            `;
            return;
        }

        const label = document.createElement('div');
        label.className = 'sm-result-group-label';
        label.textContent = 'Inspections · ' + unique.length;
        searchBody.appendChild(label);

        unique.slice(0, 8).forEach(item => {
            const a = document.createElement('a');
            a.href = '#';
            a.className = 'sm-result-item';
            a.innerHTML = `
                <div class="sm-result-icon"><i class="fa-solid ${item.icon}"></i></div>
                <div class="sm-result-body">
                    <div class="sm-result-type">${escapeHtml(item.type)}</div>
                    <div class="sm-result-title">${escapeHtml(item.title)}</div>
                    <div class="sm-result-sub">${escapeHtml(item.sub)}</div>
                </div>
                <i class="fa-solid fa-arrow-right sm-result-arrow"></i>
            `;
            a.addEventListener('click', (ev) => {
                ev.preventDefault();
                closeSearchModal();
                item.el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                item.el.style.transition = 'background 0.3s ease';
                item.el.style.background = 'var(--red-soft)';
                setTimeout(() => { item.el.style.background = ''; }, 2000);
            });
            searchBody.appendChild(a);
        });
    });

    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    /* ============ VIEW SWITCHER ============ */
    function switchView(view) {
        const listContainer = document.getElementById('listViewContainer');
        const calendarContainer = document.getElementById('calendarViewContainer');
        const tabList = document.getElementById('tabListView');
        const tabCalendar = document.getElementById('tabCalendarView');

        if (view === 'list') {
            listContainer.style.display = 'block';
            calendarContainer.style.display = 'none';
            tabList.classList.add('active');
            tabCalendar.classList.remove('active');
            localStorage.setItem('inspectorView', 'list');
        } else {
            listContainer.style.display = 'none';
            calendarContainer.style.display = 'block';
            tabList.classList.remove('active');
            tabCalendar.classList.add('active');
            localStorage.setItem('inspectorView', 'calendar');
        }
    }

    window.addEventListener('DOMContentLoaded', function() {
        const savedView = localStorage.getItem('inspectorView') || 'list';
        switchView(savedView);
    });

    /* ============ CALENDAR DATA ============ */
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
        const modalEl = document.getElementById('calendarDetailModal');
        const modal = new bootstrap.Modal(modalEl);
        const body = document.getElementById('calendarDetailBody');

        const dateObj = new Date(dateStr + 'T00:00:00');
        const dateDisplay = dateObj.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });

        if (items.length === 0) {
            body.innerHTML = `
                <div style="text-align:center; padding:30px 20px; color:var(--text-muted);">
                    <i class="fa-regular fa-calendar-xmark" style="font-size:32px; opacity:0.5; display:block; margin-bottom:10px; color:var(--red-accent);"></i>
                    <h4 style="font-size:15px; font-weight:700; color:var(--text-main); margin-bottom:6px;">No inspections scheduled</h4>
                    <p style="font-size:12.5px;">No inspection scheduled on <strong>${dateDisplay}</strong>.</p>
                </div>
            `;
            modal.show();
            return;
        }

        let html = `<div style="font-size:13px; color:var(--text-muted); margin-bottom:14px; padding-bottom:10px; border-bottom:1px dashed var(--border-color);">
            <i class="fa-regular fa-calendar me-1" style="color:var(--red-accent);"></i>
            <strong style="color:var(--text-main);">${dateDisplay}</strong> &middot; ${items.length} ${items.length === 1 ? 'visit' : 'visits'}
        </div>`;

        items.forEach(function(item) {
            const status = item.inspection_status || 'Scheduled';
            const statusClass = getStatusClass(status);
            const typeLabel = getTypeLabel(item.inspection_type);
            const categoryLabel = item.inspection_category ? getCategoryLabel(item.inspection_category) : '';
            const timeDisplay = item.inspection_time || 'TBA';

            let actionBtn = '';
            const statusLower = (status || '').toLowerCase();
            if (statusLower === 'scheduled' || statusLower === 'pending' || statusLower === '') {
                actionBtn = `<a href="inspection_form.php?id=${item.id}" class="btn-start-inspection" style="margin-top:8px;"><i class="fa-solid fa-play"></i> Start Inspection</a>`;
            } else if (statusLower === 'ongoing') {
                actionBtn = `<a href="inspection_form.php?id=${item.id}" class="btn-continue-inspection" style="margin-top:8px;"><i class="fa-solid fa-forward"></i> Continue</a>`;
            } else {
                actionBtn = `<a href="print_report.php?id=${item.id}" target="_blank" class="btn-view-report" style="margin-top:8px;"><i class="fa-solid fa-file-lines"></i> View Report</a>`;
            }

            html += `
                <div style="padding:14px 16px; background:#f8fafc; border:1px solid var(--border-color); border-left:4px solid var(--red-accent); border-radius:12px; margin-bottom:12px;">
                    <div style="display:flex; justify-content:space-between; gap:10px; align-items:flex-start; margin-bottom:8px;">
                        <div style="min-width:0; flex:1;">
                            <div style="font-weight:800; font-size:14px; color:var(--text-main); margin-bottom:4px; word-break:break-word;">${escapeHtml(item.business_name)}</div>
                            <div style="font-size:11px; color:var(--text-muted);">
                                <i class="fa-solid fa-barcode" style="font-size:10px;"></i> #${escapeHtml(item.tracking_no)}
                                ${item.barangay ? ' &middot; ' + escapeHtml(item.barangay) : ''}
                            </div>
                        </div>
                        <span class="status-badge ${statusClass}" style="margin-top:0;">${escapeHtml(status)}</span>
                    </div>

                    <div style="display:flex; flex-wrap:wrap; gap:10px 16px; margin-bottom:8px; font-size:12px;">
                        <span style="display:flex; align-items:center; gap:5px; color:var(--text-muted);">
                            <i class="fa-regular fa-clock" style="color:var(--red-accent); font-size:11px;"></i>
                            <strong style="color:var(--text-main);">${escapeHtml(timeDisplay)}</strong>
                        </span>
                        ${typeLabel && typeLabel !== '—' ? `
                        <span style="display:flex; align-items:center; gap:5px; color:var(--text-muted);">
                            <i class="fa-solid fa-clipboard-check" style="color:var(--red-accent); font-size:11px;"></i>
                            <strong style="color:var(--text-main);">${escapeHtml(typeLabel)}</strong>
                        </span>` : ''}
                        ${categoryLabel ? `
                        <span style="display:flex; align-items:center; gap:5px; color:var(--text-muted);">
                            <i class="fa-solid fa-layer-group" style="color:var(--red-accent); font-size:11px;"></i>
                            <strong style="color:var(--text-main);">${escapeHtml(categoryLabel)}</strong>
                        </span>` : ''}
                    </div>

                    <div style="font-size:11px; color:var(--text-muted);">
                        <i class="fa-solid fa-user-shield" style="font-size:10px;"></i> Owner: <strong style="color:var(--text-main);">${escapeHtml(item.owner_name)}</strong>
                    </div>

                    <div style="text-align:right;">${actionBtn}</div>
                </div>
            `;
        });

        body.innerHTML = html;
        modal.show();
    }

    function viewInspectionDetail(data) {
        const modalEl = document.getElementById('viewDetailModal');
        const modal = new bootstrap.Modal(modalEl);
        const body = document.getElementById('viewDetailBody');
        const footer = document.getElementById('viewDetailFooter');

        const dateDisplay = data.inspection_date ? new Date(data.inspection_date + 'T00:00:00').toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }) : '—';
        const timeDisplay = data.inspection_time ? (() => {
            const [h, m] = data.inspection_time.split(':');
            const hour = parseInt(h);
            const ampm = hour >= 12 ? 'PM' : 'AM';
            const h12 = hour % 12 || 12;
            return `${h12}:${m} ${ampm}`;
        })() : '—';

        const statusClass = (() => {
            const s = (data.inspection_status || '').toLowerCase().trim();
            if (['passed','completed'].includes(s)) return 'passed';
            if (['failed','cancelled'].includes(s)) return 'failed';
            if (['scheduled','ongoing'].includes(s)) return 'scheduled';
            return 'pending';
        })();

        const statusLower = (data.inspection_status || '').toLowerCase().trim();
        let footerBtn = '';
        if (statusLower === 'scheduled' || statusLower === 'pending' || statusLower === '') {
            footerBtn = `<a href="inspection_form.php?id=${data.id}" class="btn-start-inspection"><i class="fa-solid fa-play"></i> Start Inspection</a>`;
        } else if (statusLower === 'ongoing') {
            footerBtn = `<a href="inspection_form.php?id=${data.id}" class="btn-continue-inspection"><i class="fa-solid fa-forward"></i> Continue Inspection</a>`;
        } else {
            footerBtn = `<a href="print_report.php?id=${data.id}" target="_blank" class="btn-view-report"><i class="fa-solid fa-file-lines"></i> View Report</a>`;
        }

        body.innerHTML = `
            <div class="insp-detail-section">
                <div class="insp-detail-section-title"><i class="fa-solid fa-clipboard-check"></i> Inspection Summary</div>
                <div class="insp-detail-grid">
                    <div class="insp-detail-item">
                        <span class="label">Inspection No.</span>
                        <span class="value" style="color:var(--red-accent);">${escapeHtml(data.inspection_no || 'N/A')}</span>
                    </div>
                    <div class="insp-detail-item">
                        <span class="label">Status</span>
                        <span class="value"><span class="status-badge ${statusClass}">${escapeHtml(data.inspection_status || 'Pending')}</span></span>
                    </div>
                    <div class="insp-detail-item">
                        <span class="label">Type</span>
                        <span class="value">${data.type_label && data.type_label !== '—' ? '<span class="inspection-type-chip">' + escapeHtml(data.type_label) + '</span>' : '<span class="text-muted">—</span>'}</span>
                    </div>
                    ${data.category_label && data.category_label !== '—' ? `
                    <div class="insp-detail-item">
                        <span class="label">Category</span>
                        <span class="value"><span class="inspection-category-chip">${escapeHtml(data.category_label)}</span></span>
                    </div>` : ''}
                    <div class="insp-detail-item">
                        <span class="label">Date</span>
                        <span class="value">${dateDisplay}</span>
                    </div>
                    <div class="insp-detail-item">
                        <span class="label">Time</span>
                        <span class="value">${timeDisplay}</span>
                    </div>
                </div>
            </div>

            <div class="insp-detail-section">
                <div class="insp-detail-section-title"><i class="fa-solid fa-building"></i> Property Information</div>
                <div class="insp-detail-grid">
                    <div class="insp-detail-item full">
                        <span class="label">Business / Rental Name</span>
                        <span class="value">${escapeHtml(data.business_name || data.rental_name || '—')}</span>
                    </div>
                    <div class="insp-detail-item">
                        <span class="label">Tracking No.</span>
                        <span class="value" style="color:var(--red-accent);">${escapeHtml(data.tracking_no || 'N/A')}</span>
                    </div>
                    <div class="insp-detail-item">
                        <span class="label">Barangay</span>
                        <span class="value">${escapeHtml(data.barangay || '—')}</span>
                    </div>
                    ${data.doc_permit_no ? `
                    <div class="insp-detail-item full">
                        <span class="label">Permit Number</span>
                        <span class="value">
                            <span class="permit-number-box">
                                <i class="fa-solid fa-id-card" style="font-size:11px; margin-right:6px;"></i>
                                ${escapeHtml(data.doc_permit_no)}
                            </span>
                            ${data.doc_permit_expiry ? `
                                <br><small style="font-size:11px; color:var(--text-muted); margin-top:6px; display:inline-block;">
                                    <i class="fa-regular fa-calendar me-1"></i> Valid until: ${new Date(data.doc_permit_expiry + 'T00:00:00').toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'})}
                                </small>
                            ` : ''}
                        </span>
                    </div>` : ''}
                    <div class="insp-detail-item full">
                        <span class="label">Owner</span>
                        <span class="value">${escapeHtml(data.owner_name || '—')}</span>
                    </div>
                    ${data.business_address ? `
                    <div class="insp-detail-item full">
                        <span class="label">Address</span>
                        <span class="value">${escapeHtml(data.business_address)}</span>
                    </div>` : ''}
                </div>
            </div>

            ${data.remarks ? `
            <div class="insp-detail-section">
                <div class="insp-detail-section-title"><i class="fa-solid fa-comment-dots"></i> Owner Remarks / Request</div>
                <div class="owner-remarks-box">${escapeHtml(data.remarks).replace(/\n/g, '<br>')}</div>
            </div>` : ''}

            ${data.inspector_notes ? `
            <div class="insp-detail-section">
                <div class="insp-detail-section-title"><i class="fa-solid fa-pen-to-square"></i> Inspector Notes</div>
                <div class="owner-remarks-box" style="border-left-color:#1d4ed8;">${escapeHtml(data.inspector_notes).replace(/\n/g, '<br>')}</div>
            </div>` : ''}
        `;

        footer.innerHTML = `
            <button type="button" class="btn btn-secondary fw-semibold btn-sm" data-bs-dismiss="modal">Close</button>
            ${footerBtn}
        `;

        modal.show();
    }

    /* ============ LOCAL SEARCH (table/cards) ============ */
    const localSearchInput = document.getElementById('localSearch');
    function applyLocalSearch() {
        const kw = (localSearchInput.value || '').toLowerCase().trim();
        document.querySelectorAll('tr[data-search], .inspection-mobile-card[data-search]').forEach(function (el) {
            el.style.display = el.dataset.search.includes(kw) ? '' : 'none';
        });
    }
    if (localSearchInput) {
        localSearchInput.addEventListener('keyup', applyLocalSearch);
        applyLocalSearch();
    }

    /* ============ RESIZE HANDLER ============ */
    window.addEventListener('resize', () => {
        if (window.innerWidth > 992) {
            document.body.classList.remove('mobile-nav-open');
            document.querySelectorAll('.rt-nav-group').forEach(g => g.classList.remove('open'));
        }
    });
</script>
</body>
</html>