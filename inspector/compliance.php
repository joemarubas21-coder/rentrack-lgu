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

function esc($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function getPermitStatus($expiry)
{
    if (empty($expiry)) {
        return ['label' => 'No Record', 'class' => 'secondary'];
    }
    $today = strtotime(date('Y-m-d'));
    $date  = strtotime($expiry);
    $days = floor(($date - $today) / 86400);

    if ($days < 0) {
        return ['label' => 'Expired', 'class' => 'danger'];
    }
    if ($days <= 30) {
        return ['label' => 'Expires Soon', 'class' => 'warning'];
    }
    return ['label' => 'Valid', 'class' => 'success'];
}

function getComplianceStatus($score)
{
    if ($score >= 85) {
        return ['label' => 'Compliant', 'class' => 'success', 'icon' => 'fa-circle-check'];
    } elseif ($score >= 60) {
        return ['label' => 'For Review', 'class' => 'warning', 'icon' => 'fa-clock'];
    } else {
        return ['label' => 'Non-Compliant', 'class' => 'danger', 'icon' => 'fa-circle-xmark'];
    }
}

// Handle Export CSV action if requested
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=compliance_report_' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Property Name', 'Business Name', 'Tracking No.', 'Barangay', 'Permit Status', 'Inspection Status', 'Open Findings', 'Compliance Score', 'Status']);
    
    if (tableExists($conn, 'rentals') && tableExists($conn, 'inspections')) {
        $exportSql = "
            SELECT DISTINCT
                r.id, r.rental_name, r.business_name, r.tracking_no, r.barangay, r.permit_expiry
            FROM rentals r
            INNER JOIN inspections i ON i.rental_id = r.id
            WHERE i.inspector_id = :iid
              AND (r.is_deleted = FALSE OR r.is_deleted IS NULL)
            ORDER BY r.rental_name ASC
        ";
        $stmtExp = $conn->prepare($exportSql);
        $stmtExp->execute([':iid' => $inspectorId]);
        
        while ($rowExp = $stmtExp->fetch(PDO::FETCH_ASSOC)) {
            $rId = (int)$rowExp['id'];
            $insStmt = $conn->prepare("SELECT inspection_status FROM inspections WHERE rental_id = :rid AND inspector_id = :iid ORDER BY inspection_date DESC LIMIT 1");
            $insStmt->execute([':rid' => $rId, ':iid' => $inspectorId]);
            $insData = $insStmt->fetch(PDO::FETCH_ASSOC);
            $statusLabel = $insData['inspection_status'] ?? 'No Inspection';
            
            fputcsv($output, [
                $rowExp['rental_name'],
                $rowExp['business_name'],
                $rowExp['tracking_no'],
                $rowExp['barangay'],
                getPermitStatus($rowExp['permit_expiry'])['label'],
                $statusLabel,
                'N/A',
                'N/A',
                'Evaluated'
            ]);
        }
    }
    fclose($output);
    exit;
}

$properties = [];

if (tableExists($conn, 'rentals') && tableExists($conn, 'inspections')) {
    $sql = "
        SELECT DISTINCT
            r.id, r.rental_name, r.business_name, r.tracking_no, r.barangay, r.permit_expiry
        FROM rentals r
        INNER JOIN inspections i ON i.rental_id = r.id
        WHERE i.inspector_id = :iid
          AND (r.is_deleted = FALSE OR r.is_deleted IS NULL)
        ORDER BY r.rental_name ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':iid' => $inspectorId]);
    $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$totalProperties = count($properties);
$totalCompliant = 0;
$totalReview = 0;
$totalNonCompliant = 0;
$totalScore = 0;

foreach ($properties as $key => $property) {
    $rentalId = (int)$property['id'];
    $latestInspection = null;

    if (tableExists($conn, 'inspections')) {
        $hasChecklists = tableExists($conn, 'inspector_checklists');
        
        if ($hasChecklists) {
            $sqlInspection = "
                SELECT i.id, i.inspection_status, i.inspection_date, c.compliance_score, c.result
                FROM inspections i
                LEFT JOIN inspector_checklists c ON c.inspection_id = i.id
                WHERE i.rental_id = :rid AND i.inspector_id = :iid
                ORDER BY i.inspection_date DESC, i.id DESC
                LIMIT 1
            ";
        } else {
            $sqlInspection = "
                SELECT i.id, i.inspection_status, i.inspection_date, NULL as compliance_score, NULL as result
                FROM inspections i
                WHERE i.rental_id = :rid AND i.inspector_id = :iid
                ORDER BY i.inspection_date DESC, i.id DESC
                LIMIT 1
            ";
        }

        $stmtInspection = $conn->prepare($sqlInspection);
        $stmtInspection->execute([':rid' => $rentalId, ':iid' => $inspectorId]);
        $latestInspection = $stmtInspection->fetch(PDO::FETCH_ASSOC);
    }

    $openFindings = 0;
    if (tableExists($conn, 'inspector_findings') && tableExists($conn, 'inspections')) {
        $sqlFindings = "
            SELECT COUNT(*) AS total
            FROM inspector_findings f
            INNER JOIN inspections i ON i.id = f.inspection_id
            WHERE i.rental_id = :rid AND i.inspector_id = :iid AND f.status <> 'Resolved'
        ";
        $stmtFindings = $conn->prepare($sqlFindings);
        $stmtFindings->execute([':rid' => $rentalId, ':iid' => $inspectorId]);
        $openFindings = (int) $stmtFindings->fetchColumn();
    }

    $score = 0;
    $permitStatus = getPermitStatus($property['permit_expiry'] ?? null);

    if ($permitStatus['label'] === 'Valid') {
        $score += 25;
    } elseif ($permitStatus['label'] === 'Expires Soon') {
        $score += 15;
    }

    if ($latestInspection) {
        $inspectionStatus = strtolower(trim($latestInspection['inspection_status'] ?? ''));
        if ($inspectionStatus === 'completed' || $inspectionStatus === 'passed') {
            $score += 25;
        } elseif ($inspectionStatus === 'ongoing') {
            $score += 15;
        } else {
            $score += 10;
        }
    }

    if ($latestInspection && isset($latestInspection['compliance_score'])) {
        $checklistScore = (float)$latestInspection['compliance_score'];
        $score += ($checklistScore * 0.40);
    }

    if ($openFindings === 0) {
        $score += 10;
    } elseif ($openFindings <= 2) {
        $score += 5;
    }

    $score = round(min(100, max(0, $score)));
    $compliance = getComplianceStatus($score);

    if ($compliance['label'] === 'Compliant') {
        $totalCompliant++;
    } elseif ($compliance['label'] === 'For Review') {
        $totalReview++;
    } else {
        $totalNonCompliant++;
    }

    $totalScore += $score;

    $properties[$key]['score'] = $score;
    $properties[$key]['compliance'] = $compliance;
    $properties[$key]['permit_status'] = $permitStatus;
    $properties[$key]['latest_inspection'] = $latestInspection;
    $properties[$key]['open_findings'] = $openFindings;
}

$averageScore = 0;
if ($totalProperties > 0) {
    $averageScore = round($totalScore / $totalProperties);
}

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
    <title>RENTRACK · Property Compliance</title>
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

        .live-clock-box {
            background: rgba(255, 122, 0, 0.1); border: 1px solid var(--border-light); padding: 5px 12px; border-radius: 8px;
            text-align: center; color: var(--primary-orange-light); font-family: monospace; font-weight: 700; font-size: 12.5px;
        }
        .live-clock-box .date-sub { font-size: 9px; color: #d1d5db; font-family: 'Inter', sans-serif; font-weight: 500; }

        .content { flex: 1; padding: 28px 32px 20px; }
        .compliance-hero {
            background: linear-gradient(135deg, var(--primary-orange), var(--primary-orange-dark));
            color: #ffffff; border-radius: 16px; padding: 24px; margin-bottom: 20px; box-shadow: 0 10px 30px rgba(255,122,0,.20);
        }
        .score-circle {
            width: 95px; height: 95px; border-radius: 50%; background: #ffffff; color: var(--primary-orange);
            display: flex; flex-direction: column; justify-content: center; align-items: center; font-weight: 700; box-shadow: 0 8px 20px rgba(0,0,0,.15);
        }
        .score-circle .number { font-size: 26px; line-height: 1; font-weight: 800; }
        .score-circle small { color: #6b7280; font-size: 10px; font-weight: 700; }

        .summary-card {
            border: 1px solid var(--border-light); border-radius: 16px; box-shadow: var(--card-shadow); transition: .2s ease; color: #fff; cursor: pointer;
        }
        .summary-card:hover { transform: translateY(-3px); }
        
        .card-assigned { background: linear-gradient(135deg, #1d4ed8, #3b82f6); border: 1px solid rgba(59, 130, 246, 0.5); }
        .card-compliant { background: linear-gradient(135deg, #0d7a57, #10b981); border: 1px solid rgba(16, 185, 129, 0.5); }
        .card-review { background: linear-gradient(135deg, #b45309, #f59e0b); border: 1px solid rgba(245, 158, 11, 0.5); }
        .card-noncompliant { background: linear-gradient(135deg, #b91c1c, #ef4444); border: 1px solid rgba(239, 68, 68, 0.5); }

        .summary-icon {
            width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 18px;
            background: rgba(255, 255, 255, 0.2); color: #fff; backdrop-filter: blur(5px);
        }

        .property-card { background: var(--card-bg); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid var(--border-light); border-radius: 16px; box-shadow: var(--card-shadow); color: #fff; }
        .property-name { font-weight: 700; color: #fff; }
        .property-meta { font-size: 12px; color: var(--text-light); }

        .score-number { font-weight: 700; font-size: 14.5px; color: #fff; }
        .progress { border-radius: 20px; background: rgba(255, 122, 0, 0.15); }
        .progress-bar { border-radius: 20px; }

        .table-responsive { background: transparent !important; }
        .table { color: #ffffff !important; margin-bottom: 0; background-color: transparent !important; }
        .table > :not(caption) > * > * { background-color: transparent !important; color: #ffffff !important; box-shadow: none !important; padding: 13px 20px; border-bottom: 1px solid var(--border-light); font-weight: 500; font-size: 13.5px; }
        
        .table tbody tr { background-color: rgba(25, 14, 9, 0.9) !important; color: #ffffff !important; border-bottom: 1px solid var(--border-light) !important; }
        .table.table-hover tbody tr:hover { background-color: rgba(255, 122, 0, 0.15) !important; color: #ffffff !important; }
        
        .table thead th { font-size: 11px; text-transform: uppercase; color: #ffffff !important; background: rgba(255, 122, 0, 0.04) !important; font-weight: 700; border-bottom: 2px solid var(--border-light); padding: 12px 20px; }

        .badge { padding: 5px 10px; border-radius: 6px; font-size: 10.5px; }
        .empty-state { padding: 50px 20px; text-align: center; color: var(--text-light); }
        .empty-state i { font-size: 40px; margin-bottom: 12px; color: var(--primary-orange-light); }
        .inspection-date { font-size: 11.5px; color: var(--text-light); }

        /* FILTER BUTTONS BAR */
        .filter-btn-group { display: flex; gap: 8px; flex-wrap: wrap; }
        .filter-chip {
            background: rgba(255, 122, 0, 0.1); border: 1px solid var(--border-light); color: var(--text-light);
            padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; cursor: pointer; transition: 0.2s; text-decoration: none;
        }
        .filter-chip:hover, .filter-chip.active { background: var(--primary-orange); color: #fff; border-color: var(--primary-orange); }

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
            <li><a href="compliance.php" class="active"><i class="fa-solid fa-shield-halved"></i> <span>Compliance</span></a></li>
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
        <header class="top-navbar">
            <div class="navbar-title-wrap">
                <button class="hamburger-btn" id="sidebarToggle" title="Toggle Sidebar">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <h4>Compliance Score <span>Monitoring Portal</span></h4>
            </div>

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
                <form action="compliance.php" method="GET">
                    <div class="search-popup-input-wrap">
                        <i class="fa-solid fa-search"></i>
                        <input type="text" name="search" id="popupSearchInput" placeholder="Type to search compliance properties..." autocomplete="off">
                    </div>
                </form>
                <div class="search-popup-footer">
                    <span>Press <kbd>Enter</kbd> to search records</span>
                    <span>Press <kbd>ESC</kbd> to close</span>
                </div>
            </div>
        </div>

        <div class="content">
            <!-- HERO -->
            <div class="compliance-hero animate-in">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-4">
                    <div>
                        <div class="small text-uppercase fw-semibold mb-2 text-warning" style="letter-spacing:1px;">Inspector Portal</div>
                        <h2 class="text-white fw-bold"><i class="fa-solid fa-shield-halved me-2"></i> Property Compliance</h2>
                        <p class="text-light mb-0" style="color:#d1d5db !important;">Monitor the compliance status of LGU rental properties assigned to your inspections.</p>
                    </div>
                    <div class="score-circle">
                        <span class="number"><?php echo $averageScore; ?>%</span>
                        <small>Average Score</small>
                    </div>
                </div>
            </div>

            <!-- SUMMARY CARDS -->
            <div class="row g-3 mb-4 animate-in">
                <div class="col-xl-3 col-md-6">
                    <div class="card summary-card card-assigned" onclick="filterTable('All')">
                        <div class="card-body py-3 px-4">
                            <div class="d-flex align-items-center gap-3">
                                <div class="summary-icon"><i class="fa-solid fa-building"></i></div>
                                <div>
                                    <div class="text-white-50 small fw-semibold text-uppercase">Assigned Properties</div>
                                    <div class="fs-3 fw-bold text-white"><?php echo $totalProperties; ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card summary-card card-compliant" onclick="filterTable('Compliant')">
                        <div class="card-body py-3 px-4">
                            <div class="d-flex align-items-center gap-3">
                                <div class="summary-icon"><i class="fa-solid fa-circle-check"></i></div>
                                <div>
                                    <div class="text-white-50 small fw-semibold text-uppercase">Compliant</div>
                                    <div class="fs-3 fw-bold text-white"><?php echo $totalCompliant; ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card summary-card card-review" onclick="filterTable('For Review')">
                        <div class="card-body py-3 px-4">
                            <div class="d-flex align-items-center gap-3">
                                <div class="summary-icon"><i class="fa-solid fa-clock"></i></div>
                                <div>
                                    <div class="text-white-50 small fw-semibold text-uppercase">For Review</div>
                                    <div class="fs-3 fw-bold text-white"><?php echo $totalReview; ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card summary-card card-noncompliant" onclick="filterTable('Non-Compliant')">
                        <div class="card-body py-3 px-4">
                            <div class="d-flex align-items-center gap-3">
                                <div class="summary-icon"><i class="fa-solid fa-circle-xmark"></i></div>
                                <div>
                                    <div class="text-white-50 small fw-semibold text-uppercase">Non-Compliant</div>
                                    <div class="fs-3 fw-bold text-white"><?php echo $totalNonCompliant; ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TABLE CONTAINER WITH FILTERS AND EXPORT -->
            <div class="card property-card mb-4 animate-in">
                <div class="card-header border-bottom border-secondary d-flex justify-content-between align-items-center flex-wrap gap-3 py-3 px-4" style="border-color:var(--border-light) !important;">
                    <div>
                        <h5 class="mb-1 fw-bold text-white"><i class="fa-solid fa-list-check me-2" style="color:var(--primary-orange-light);"></i> Property Compliance Directory</h5>
                        <small class="text-light" style="color:#d1d5db !important;">Filter or export compliance items effortlessly.</small>
                    </div>
                    
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <div class="filter-btn-group">
                            <button class="filter-chip active" onclick="filterTable('All')" id="chip-all">All</button>
                            <button class="filter-chip" onclick="filterTable('Compliant')" id="chip-compliant">Compliant</button>
                            <button class="filter-chip" onclick="filterTable('For Review')" id="chip-review">For Review</button>
                            <button class="filter-chip" onclick="filterTable('Non-Compliant')" id="chip-noncompliant">Non-Compliant</button>
                        </div>
                        <a href="compliance.php?export=csv" class="btn btn-sm btn-warning fw-semibold px-3 text-dark" style="background:var(--primary-orange-light); border:none;">
                            <i class="fa-solid fa-file-excel me-1"></i> Export CSV
                        </a>
                    </div>
                </div>
                
                <div class="card-body p-0">
                    <?php if (empty($properties)): ?>
                        <div class="empty-state">
                            <i class="fa-solid fa-building-circle-exclamation fa-3x mb-3 text-warning" style="opacity:0.6;"></i>
                            <h5 class="text-white fw-bold">No Assigned Properties</h5>
                            <p class="mb-0 text-light" style="color:#d1d5db !important;">There are currently no LGU rental properties assigned to your inspections.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table align-middle mb-0" id="complianceTable">
                                <thead>
                                    <tr>
                                        <th>Property</th>
                                        <th>Barangay</th>
                                        <th>Permit</th>
                                        <th>Latest Inspection</th>
                                        <th>Findings</th>
                                        <th style="min-width:160px;">Compliance Score</th>
                                        <th>Status</th>
                                        <th class="text-end">Quick Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($properties as $property):
                                    $propertyName = !empty($property['rental_name']) ? $property['rental_name'] : ($property['business_name'] ?? 'Rental Property');
                                    $latest = $property['latest_inspection'];
                                    $permit = $property['permit_status'];
                                    $compliance = $property['compliance'];
                                    $score = $property['score'];
                                    $rentalId = $property['id'];
                                ?>
                                    <tr data-status="<?php echo esc($compliance['label']); ?>">
                                        <td>
                                            <div class="property-name"><?php echo esc($propertyName); ?></div>
                                            <?php if (!empty($property['business_name'])): ?>
                                                <div class="property-meta"><?php echo esc($property['business_name']); ?></div>
                                            <?php endif; ?>
                                            <div class="property-meta">Tracking No.: <?php echo esc($property['tracking_no'] ?? '—'); ?></div>
                                        </td>
                                        <td><?php echo esc($property['barangay'] ?? '—'); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $permit['class']; ?>"><?php echo esc($permit['label']); ?></span>
                                            <?php if (!empty($property['permit_expiry'])): ?>
                                                <div class="inspection-date mt-1"><?php echo date('M d, Y', strtotime($property['permit_expiry'])); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($latest): ?>
                                                <span class="badge bg-<?php echo (in_array(strtolower($latest['inspection_status'] ?? ''), ['completed', 'passed'])) ? 'success' : 'secondary'; ?>">
                                                    <?php echo esc($latest['inspection_status'] ?? 'Pending'); ?>
                                                </span>
                                                <?php if (!empty($latest['inspection_date'])): ?>
                                                    <div class="inspection-date mt-1"><?php echo date('M d, Y', strtotime($latest['inspection_date'])); ?></div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">No Inspection</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($property['open_findings'] > 0): ?>
                                                <span class="badge bg-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i> <?php echo $property['open_findings']; ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-success"><i class="fa-solid fa-circle-check me-1"></i> None</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex justify-content-between mb-1">
                                                <span class="score-number"><?php echo $score; ?>%</span>
                                                <small class="text-light" style="color:#d1d5db !important;">Score</small>
                                            </div>
                                            <div class="progress" style="height:8px;">
                                                <div class="progress-bar bg-<?php echo $compliance['class']; ?>" role="progressbar" style="width: <?php echo $score; ?>%;"></div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $compliance['class']; ?>">
                                                <i class="fa-solid <?php echo $compliance['icon']; ?> me-1"></i>
                                                <?php echo esc($compliance['label']); ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <a href="inspections.php?rental_id=<?php echo $rentalId; ?>&action=reinspect" class="btn btn-sm btn-outline-warning text-white py-1 px-2" style="font-size:11px; border-color:var(--border-light);" title="Schedule Re-Inspection">
                                                <i class="fa-solid fa-calendar-plus me-1"></i> Re-inspect
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- LEGEND -->
            <div class="row g-3 mt-3 animate-in">
                <div class="col-md-4">
                    <div class="card property-card border-0">
                        <div class="card-body py-3">
                            <div class="d-flex align-items-center gap-3">
                                <span class="badge bg-success fs-6"><i class="fa-solid fa-circle-check"></i></span>
                                <div>
                                    <strong class="text-white">Compliant</strong>
                                    <div class="small text-light" style="color:#d1d5db !important;">Score of 85% and above</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card property-card border-0">
                        <div class="card-body py-3">
                            <div class="d-flex align-items-center gap-3">
                                <span class="badge bg-warning fs-6"><i class="fa-solid fa-clock"></i></span>
                                <div>
                                    <strong class="text-white">For Review</strong>
                                    <div class="small text-light" style="color:#d1d5db !important;">Score between 60% and 84%</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card property-card border-0">
                        <div class="card-body py-3">
                            <div class="d-flex align-items-center gap-3">
                                <span class="badge bg-danger fs-6"><i class="fa-solid fa-circle-xmark"></i></span>
                                <div>
                                    <strong class="text-white">Non-Compliant</strong>
                                    <div class="small text-light" style="color:#d1d5db !important;">Score below 60%</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

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

    function filterTable(status) {
        const rows = document.querySelectorAll('#complianceTable tbody tr');
        const chips = document.querySelectorAll('.filter-chip');
        
        chips.forEach(chip => chip.classList.remove('active'));
        if (status === 'All') document.getElementById('chip-all').classList.add('active');
        if (status === 'Compliant') document.getElementById('chip-compliant').classList.add('active');
        if (status === 'For Review') document.getElementById('chip-review').classList.add('active');
        if (status === 'Non-Compliant') document.getElementById('chip-noncompliant').classList.add('active');

        rows.forEach(row => {
            const rowStatus = row.getAttribute('data-status');
            if (status === 'All' || rowStatus === status) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }
</script>
</body>
</html>