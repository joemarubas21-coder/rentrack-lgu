<?php
include "includes/auth.php"; 
include "../config/db.php"; 
include "includes/inspector_features.php"; 
date_default_timezone_set('Asia/Manila');

$inspectorId = (int) ($_SESSION['user_id'] ?? 0); 
$id = (int) ($_GET['id'] ?? $_POST['inspection_id'] ?? 0); 
$inspection = inspector_get_owned_inspection($conn, $id, $inspectorId); 

if (!$inspection) { 
    http_response_code(404); 
    exit('Inspection not found or not assigned to you.'); 
}

$fullname  = htmlspecialchars($_SESSION['fullname'] ?? 'Inspector', ENT_QUOTES, 'UTF-8');
$staffRole = htmlspecialchars($_SESSION['role'] ?? 'Inspector', ENT_QUOTES, 'UTF-8');
$userInitial = strtoupper(substr($fullname, 0, 1));

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

$hasInspectionType = columnExists($conn, 'inspections', 'inspection_type');
$hasInspectionCategory = columnExists($conn, 'inspections', 'inspection_category');
$hasRemarks = columnExists($conn, 'inspections', 'remarks');

$extraInfo = [];
if (tableExists($conn, 'inspections')) {
    $selectFields = "i.id, i.inspection_no, i.inspection_type";
    if ($hasInspectionCategory) $selectFields .= ", i.inspection_category";
    if ($hasRemarks) $selectFields .= ", i.remarks";
    
    try {
        $stmtExtra = $conn->prepare("SELECT $selectFields FROM inspections i WHERE i.id = :id LIMIT 1");
        $stmtExtra->execute([':id' => $id]);
        $extraInfo = $stmtExtra->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        $extraInfo = [];
    }
}

$successMsg = ''; 
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && inspector_check_csrf($_POST['csrf'] ?? '')) {
    $permitDisplayed = in_array($_POST['permit_displayed'] ?? '', ['Yes', 'No', 'N/A'], true) ? $_POST['permit_displayed'] : 'N/A';
    $permitMatches = in_array($_POST['permit_matches'] ?? '', ['Yes', 'No', 'N/A'], true) ? $_POST['permit_matches'] : 'N/A';
    $allowed = ['Good', 'Fair', 'Poor'];
    $fields = ['structural_condition', 'electrical_condition', 'plumbing_condition', 'sanitation_condition', 'fire_safety', 'cleanliness', 'overall_condition'];
    
    $vals = []; 
    foreach ($fields as $f) {
        $v = $_POST[$f] ?? 'Good';
        $vals[$f] = in_array($v, $allowed, true) ? $v : 'Good';
    }
    
    $result = in_array($_POST['result'] ?? '', ['Passed', 'For Review', 'Failed'], true) ? $_POST['result'] : 'For Review';
    $generalFindings = trim($_POST['general_findings'] ?? ''); 
    $generalRecommendation = trim($_POST['general_recommendation'] ?? '');
    
    $weights = [
        'structural_condition' => 20, 
        'electrical_condition' => 15, 
        'plumbing_condition' => 10, 
        'sanitation_condition' => 10, 
        'fire_safety' => 15, 
        'cleanliness' => 10, 
        'overall_condition' => 20
    ];
    
    $score = 0; 
    foreach ($weights as $f => $w) {
        $score += $vals[$f] === 'Good' ? $w : ($vals[$f] === 'Fair' ? $w * 0.65 : 0);
    }
    
    if ($permitDisplayed === 'Yes') $score += 3; 
    if ($permitMatches === 'Yes') $score += 2; 
    $score = min(100, round($score, 2));
    
    $status = $result === 'Passed' ? 'Passed' : ($result === 'Failed' ? 'Failed' : 'Completed');

    try {
        $checkStmt = $conn->prepare("SELECT id FROM inspector_checklists WHERE inspection_id = :id LIMIT 1");
        $checkStmt->execute([':id' => $id]);
        $exists = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($exists) {
            $updateStmt = $conn->prepare("
                UPDATE inspector_checklists SET 
                    permit_displayed = :permit_displayed, 
                    permit_matches = :permit_matches, 
                    structural_condition = :structural_condition, 
                    electrical_condition = :electrical_condition, 
                    plumbing_condition = :plumbing_condition, 
                    sanitation_condition = :sanitation_condition, 
                    fire_safety = :fire_safety, 
                    cleanliness = :cleanliness, 
                    overall_condition = :overall_condition, 
                    compliance_score = :compliance_score, 
                    result = :result, 
                    general_findings = :general_findings, 
                    general_recommendation = :general_recommendation 
                WHERE inspection_id = :id
            ");
            $ok = $updateStmt->execute([
                ':permit_displayed'      => $permitDisplayed,
                ':permit_matches'        => $permitMatches,
                ':structural_condition'  => $vals['structural_condition'],
                ':electrical_condition'  => $vals['electrical_condition'],
                ':plumbing_condition'    => $vals['plumbing_condition'],
                ':sanitation_condition'  => $vals['sanitation_condition'],
                ':fire_safety'           => $vals['fire_safety'],
                ':cleanliness'           => $vals['cleanliness'],
                ':overall_condition'     => $vals['overall_condition'],
                ':compliance_score'      => $score,
                ':result'                => $result,
                ':general_findings'      => $generalFindings,
                ':general_recommendation'=> $generalRecommendation,
                ':id'                    => $id
            ]);
        } else {
            $insertStmt = $conn->prepare("
                INSERT INTO inspector_checklists (
                    inspection_id, permit_displayed, permit_matches, structural_condition, 
                    electrical_condition, plumbing_condition, sanitation_condition, fire_safety, 
                    cleanliness, overall_condition, compliance_score, result, general_findings, general_recommendation
                ) VALUES (
                    :id, :permit_displayed, :permit_matches, :structural_condition, 
                    :electrical_condition, :plumbing_condition, :sanitation_condition, :fire_safety, 
                    :cleanliness, :overall_condition, :compliance_score, :result, :general_findings, :general_recommendation
                )
            ");
            $ok = $insertStmt->execute([
                ':id'                    => $id,
                ':permit_displayed'      => $permitDisplayed,
                ':permit_matches'        => $permitMatches,
                ':structural_condition'  => $vals['structural_condition'],
                ':electrical_condition'  => $vals['electrical_condition'],
                ':plumbing_condition'    => $vals['plumbing_condition'],
                ':sanitation_condition'  => $vals['sanitation_condition'],
                ':fire_safety'           => $vals['fire_safety'],
                ':cleanliness'           => $vals['cleanliness'],
                ':overall_condition'     => $vals['overall_condition'],
                ':compliance_score'      => $score,
                ':result'                => $result,
                ':general_findings'      => $generalFindings,
                ':general_recommendation'=> $generalRecommendation
            ]);
        }

        $updateInspStmt = $conn->prepare("UPDATE inspections SET inspection_status = :status, inspector_notes = :notes WHERE id = :id AND inspector_id = :inspector_id");
        $updateInspStmt->execute([
            ':status'       => $status,
            ':notes'        => $generalFindings,
            ':id'           => $id,
            ':inspector_id' => $inspectorId
        ]);

        // I-SYNC ANG COMPLIANCE STATUS SA RENTALS
        $getRentalStmt = $conn->prepare("SELECT rental_id FROM inspections WHERE id = :id LIMIT 1");
        $getRentalStmt->execute([':id' => $id]);
        $rentalData = $getRentalStmt->fetch(PDO::FETCH_ASSOC);
        $rentalId = (int)($rentalData['rental_id'] ?? 0);

        if ($rentalId > 0) {
            if ($status === 'Passed') {
                $compStatus = 'Compliant';
            } elseif ($status === 'Failed') {
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

        if ($ok) {
            $successMsg = 'Inspection checklist saved successfully.';
        } else {
            $errorMsg = 'Unable to save checklist.';
        }
    } catch (PDOException $e) {
        $errorMsg = 'Database error: ' . safe_error($e);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errorMsg = 'Security token expired. Please refresh and try again.';
}

$check = null;
try {
    $stmt = $conn->prepare("SELECT * FROM inspector_checklists WHERE inspection_id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $check = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $conn->prepare("SELECT * FROM inspector_findings WHERE inspection_id = :id ORDER BY created_at DESC");
    $stmt->execute([':id' => $id]);
    $findings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $conn->prepare("SELECT * FROM inspector_maintenance WHERE inspection_id = :id ORDER BY created_at DESC");
    $stmt->execute([':id' => $id]);
    $maintenance = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $conn->prepare("SELECT COUNT(*) c FROM inspector_evidence WHERE inspection_id = :id");
    $stmt->execute([':id' => $id]);
    $rowEv = $stmt->fetch(PDO::FETCH_ASSOC);
    $evidenceCount = (int) ($rowEv['c'] ?? 0);
} catch (PDOException $e) {
    $findings = [];
    $maintenance = [];
    $evidenceCount = 0;
}

$val = function($f, $default = 'Good') use ($check) {
    return $check[$f] ?? $default;
};

$typeLabel = $hasInspectionType ? getInspectionTypeLabel($extraInfo['inspection_type'] ?? '') : '';
$categoryLabel = $hasInspectionCategory ? getInspectionCategoryLabel($extraInfo['inspection_category'] ?? '') : '';
$ownerRemarks = $hasRemarks ? ($extraInfo['remarks'] ?? '') : '';
$inspectionNo = $extraInfo['inspection_no'] ?? '';

$unreadInspectionsCount = 0;
try {
    $chkCol = $conn->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'inspections' AND column_name = 'is_read')");
    $chkCol->execute();
    if ($chkCol->fetchColumn()) {
        $stmtUnread = $conn->prepare("
            SELECT COUNT(*) FROM inspections i
            INNER JOIN rentals r ON i.rental_id = r.id
            WHERE i.inspector_id = :iid AND (r.is_deleted = FALSE OR r.is_deleted IS NULL)
              AND (i.is_read = FALSE OR i.is_read IS NULL)
        ");
        $stmtUnread->execute([':iid' => $inspectorId]);
        $unreadInspectionsCount = (int) $stmtUnread->fetchColumn();
    }
} catch (PDOException $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#ffffff">
<title>Inspection Form | RENTRACK LGU</title>

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
    .rt-panel { animation: fadeUp 0.5s cubic-bezier(0.4,0,0.2,1) both; }

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
        display: inline-flex; align-items: center; gap: 8px;
        padding: 8px 14px; border-radius: 10px;
        color: var(--text-sub); font-size: 13px; font-weight: 600;
        text-decoration: none; transition: var(--transition);
        background: transparent; border: none; font-family: inherit;
        cursor: pointer; white-space: nowrap; height: 42px;
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
        position: absolute; top: calc(100% + 4px); left: 0;
        min-width: 250px; background: #fff;
        border: 1px solid var(--border-color); border-radius: 12px;
        padding: 8px; box-shadow: 0 12px 30px rgba(0, 0, 0, 0.12);
        opacity: 0; visibility: hidden; transform: translateY(6px);
        transition: opacity 0.2s ease, transform 0.2s ease, visibility 0.2s;
        z-index: 999;
    }
    .rt-nav-group:hover > .rt-nav-dropdown,
    .rt-nav-group.open > .rt-nav-dropdown {
        opacity: 1; visibility: visible; transform: translateY(0);
    }

    .rt-nav-dropdown a {
        display: flex; align-items: center; gap: 10px;
        padding: 9px 12px; border-radius: 8px;
        text-decoration: none; color: var(--text-sub);
        font-size: 13px; font-weight: 500;
        transition: var(--transition);
    }
    .rt-nav-dropdown a i { width: 18px; text-align: center; font-size: 13px; color: var(--red-accent); flex-shrink: 0; }
    .rt-nav-dropdown a:hover { background: var(--red-soft); color: var(--red-accent); }
    .rt-nav-dropdown a.active { background: var(--red-soft); color: var(--red-accent); font-weight: 700; }

    .rt-dd-badge {
        margin-left: auto; background: #fbbf24; color: #7c2d12;
        font-size: 10px; font-weight: 800; padding: 2px 7px;
        border-radius: 999px; min-width: 20px; text-align: center;
        box-shadow: 0 0 8px rgba(251, 191, 36, 0.6);
    }

    .rt-search-trigger {
        position: relative; width: 260px; max-width: 100%; flex-shrink: 0;
        display: flex; align-items: center; gap: 10px;
        padding: 9px 14px 9px 38px; border-radius: 999px;
        border: 1px solid var(--border-color); background: var(--bg-card-alt);
        color: var(--text-muted); font-size: 13px;
        cursor: pointer; transition: var(--transition);
        white-space: nowrap; overflow: hidden; height: 42px;
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
        display: flex; align-items: flex-start; justify-content: center;
        padding: 80px 16px 16px;
        opacity: 0; visibility: hidden;
        transition: opacity 0.25s ease, visibility 0.25s ease;
    }
    .search-modal-overlay.active { opacity: 1; visibility: visible; }

    .search-modal-card {
        width: 100%; max-width: 640px; background: #fff;
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

    /* ============ CONTENT ============ */
    .wrapper { display: flex; min-height: calc(100vh - var(--header-total-height)); }
    .main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
    .dashboard-shell { padding: 16px 20px 30px; flex: 1; }

    .rt-title {
        font-family: 'Space Grotesk', sans-serif;
        color: var(--text-main); font-size: 20px; font-weight: 800; margin-bottom: 0;
        letter-spacing: -0.2px;
        display: flex; align-items: center; gap: 10px;
    }
    .rt-title i { color: var(--red-accent); }
    .rt-page-sub { color: var(--text-muted); font-size: 12px; margin-top: 2px; }

    .rt-card {
        background: var(--bg-card); border: 1px solid var(--border-color);
        border-radius: 16px; box-shadow: var(--shadow-soft);
        overflow: hidden; margin-bottom: 16px;
    }
    .rt-card-header {
        background: var(--bg-card-alt); border-bottom: 1px solid var(--border-color);
        padding: 14px 20px; font-weight: 800; color: var(--text-main); font-size: 13.5px;
        display: flex; align-items: center; gap: 8px;
    }
    .rt-card-header i { color: var(--red-accent); }
    .rt-card-body { padding: 20px; }
    @media (max-width: 576px) { .rt-card-body { padding: 14px; } }

    /* Chips */
    .inspection-type-chip {
        display: inline-block; padding: 4px 12px;
        background: var(--red-soft); color: var(--red-accent);
        border: 1px solid #fecdd3; border-radius: 8px;
        font-size: 11px; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.3px; white-space: nowrap;
    }
    .inspection-category-chip {
        display: inline-block; padding: 4px 12px;
        background: rgba(59, 130, 246, 0.12); color: #1d4ed8;
        border: 1px solid rgba(59, 130, 246, 0.3); border-radius: 8px;
        font-size: 11px; font-weight: 700; white-space: nowrap;
    }
    .owner-remarks-box {
        background: var(--bg-card-alt); border-left: 4px solid var(--red-accent);
        border-radius: 10px; padding: 12px 14px;
        font-size: 13px; color: var(--text-sub); line-height: 1.6;
        word-break: break-word;
    }
    .owner-remarks-box strong {
        color: var(--red-accent); font-size: 11px;
        text-transform: uppercase; letter-spacing: 0.5px;
        display: block; margin-bottom: 4px;
    }

    /* Check grid */
    .check-grid { display: grid; grid-template-columns: repeat(2,1fr); gap: 15px; }
    @media(max-width:768px){ .check-grid{ grid-template-columns:1fr; } }
    .check-item {
        padding: 14px; border: 1px solid var(--border-color);
        border-radius: 14px; background: var(--bg-card-alt);
    }
    .check-item label {
        font-weight: 700; font-size: 12.5px; margin-bottom: 8px;
        color: var(--red-accent); display: block;
    }

    /* Score box */
    .score-box {
        border-radius: 18px;
        background: linear-gradient(135deg, var(--red-accent), #991b1b);
        color: #fff; padding: 22px;
        box-shadow: 0 10px 30px -10px rgba(220, 38, 38, 0.5);
    }
    .score-number { font-size: 42px; font-weight: 800; font-family: 'Space Grotesk', sans-serif; }
    .score-box .progress { background: rgba(255,255,255,0.2); }

    /* Sticky actions */
    .sticky-actions {
        position: sticky; bottom: 15px; z-index: 5;
        background: var(--bg-card); border: 1px solid var(--border-color);
        border-radius: 16px; padding: 14px;
        box-shadow: 0 -4px 20px rgba(0,0,0,0.08);
        display: flex; justify-content: space-between; align-items: center;
        flex-wrap: wrap; gap: 8px;
    }

    /* Form controls */
    .form-label-custom {
        color: var(--red-accent); font-weight: 700; font-size: 11.5px;
        text-transform: uppercase; letter-spacing: 0.3px;
        margin-bottom: 5px; display: block;
    }
    .form-control-custom, .form-select-custom {
        background: var(--bg-card-alt); border: 1px solid var(--border-color);
        color: var(--text-main); border-radius: 10px;
        padding: 10px 14px; font-size: 13.5px;
        width: 100%; transition: var(--transition);
        font-family: inherit;
    }
    .form-control-custom:focus, .form-select-custom:focus {
        background: #fff; border-color: var(--red-accent);
        box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        outline: none;
    }
    .form-control-custom::placeholder { color: var(--text-muted); }

    /* Buttons */
    .btn-primary-action {
        background: var(--red-accent); border: 1px solid var(--red-accent);
        color: #fff; font-weight: 700; padding: 10px 22px;
        border-radius: 10px; font-size: 13px;
        transition: var(--transition);
        display: inline-flex; align-items: center; gap: 7px;
        box-shadow: 0 4px 12px rgba(220, 38, 38, 0.2);
        cursor: pointer; text-decoration: none;
    }
    .btn-primary-action:hover {
        background: #991b1b; border-color: #991b1b;
        color: #fff; transform: translateY(-1px);
    }

    .btn-secondary-action {
        background: #fff; border: 1px solid var(--border-color);
        color: var(--text-main); font-weight: 700; padding: 10px 18px;
        border-radius: 10px; font-size: 13px;
        transition: var(--transition);
        display: inline-flex; align-items: center; gap: 7px;
        cursor: pointer; text-decoration: none;
    }
    .btn-secondary-action:hover {
        background: var(--bg-card-alt);
        border-color: var(--border-strong);
        color: var(--red-accent);
    }

    .btn-outline-primary-custom {
        background: rgba(59, 130, 246, 0.08);
        border: 1px solid rgba(59, 130, 246, 0.4);
        color: #1d4ed8; font-weight: 700; padding: 10px 18px;
        border-radius: 10px; font-size: 13px;
        transition: var(--transition);
        display: inline-flex; align-items: center; gap: 7px;
        cursor: pointer; text-decoration: none;
    }
    .btn-outline-primary-custom:hover {
        background: rgba(59, 130, 246, 0.18);
        color: #1e40af;
    }

    .btn-outline-warning-custom {
        background: rgba(245, 158, 11, 0.08);
        border: 1px solid rgba(245, 158, 11, 0.4);
        color: #b45309; font-weight: 700; padding: 10px 18px;
        border-radius: 10px; font-size: 13px;
        transition: var(--transition);
        display: inline-flex; align-items: center; gap: 7px;
        cursor: pointer; text-decoration: none;
        justify-content: center;
    }
    .btn-outline-warning-custom:hover {
        background: rgba(245, 158, 11, 0.18);
        color: #92400e;
    }

    /* Badges */
    .badge-custom {
        display: inline-flex; align-items: center; gap: 4px;
        padding: 4px 10px; border-radius: 6px;
        font-size: 10.5px; font-weight: 700;
    }
    .badge-critical { background: rgba(220, 38, 38, 0.12); color: #b91c1c; border: 1px solid rgba(220, 38, 38, 0.3); }
    .badge-major { background: rgba(245, 158, 11, 0.14); color: #b45309; border: 1px solid rgba(245, 158, 11, 0.3); }
    .badge-minor { background: rgba(100, 116, 139, 0.14); color: #475569; border: 1px solid rgba(100, 116, 139, 0.3); }

    /* Alerts */
    .alert-custom {
        padding: 12px 16px; border-radius: 12px;
        font-size: 13px; margin-bottom: 14px;
        display: flex; align-items: center; gap: 8px;
    }
    .alert-success-custom { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
    .alert-danger-custom { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }

    /* Footer */
    .footer {
        margin-top: 20px; padding: 16px 0 calc(16px + var(--safe-bottom)) 0;
        border-top: 1px solid var(--border-color); display: flex;
        justify-content: space-between; align-items: center; font-size: 11.5px;
        color: var(--text-muted); flex-wrap: wrap; gap: 8px; text-align: center;
    }
    .footer strong { color: var(--red-accent); }

    /* Modal */
    .modal-content {
        background: #ffffff !important; border: 1px solid var(--border-color) !important;
        color: var(--text-main) !important; border-radius: 16px !important;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35);
    }
    .modal-header { border-bottom: 1px solid var(--border-color) !important; padding: 14px 18px; }
    .modal-footer { border-top: 1px solid var(--border-color) !important; padding: 12px 18px; }
    .modal-body { padding: 16px; }
    .modal-title { color: var(--red-accent) !important; }

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
        .rt-title { font-size: 16px; }
        .footer { font-size: 10px; flex-direction: column; text-align: center; }
        .search-modal-overlay { padding: 60px 10px 10px; }
        .search-modal-card { border-radius: 14px; }
        .search-modal-header { padding: 12px 14px; gap: 10px; }
        .search-modal-header .sm-icon { width: 34px; height: 34px; font-size: 13px; }
        .search-modal-header input { font-size: 15px; }
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
                        <?php if ($unreadInspectionsCount > 0): ?>
                            <span class="rt-dd-badge"><?php echo $unreadInspectionsCount; ?></span>
                        <?php endif; ?>
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
            <span class="search-placeholder">Search inspections, properties...</span>
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
                    <span class="role"><?php echo $staffRole; ?></span>
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
                <div class="sm-state-title">Quick Search</div>
                <div class="sm-state-sub">Type a keyword and press Enter to search across inspections and properties.</div>
                <div class="sm-tips">
                    <span class="sm-tip"><i class="fa-solid fa-clipboard-check"></i> Inspections</span>
                    <span class="sm-tip"><i class="fa-solid fa-building"></i> Properties</span>
                    <span class="sm-tip"><i class="fa-solid fa-user"></i> Owners</span>
                </div>
            </div>
        </div>

        <div class="search-modal-footer">
            <span class="sm-hint"><kbd>Enter</kbd> Search</span>
            <span class="sm-hint"><kbd>ESC</kbd> Close</span>
        </div>
    </div>
</div>

<div class="wrapper">
    <div class="main">
        <div class="dashboard-shell">

            <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
                <div>
                    <h2 class="rt-title"><i class="fa-solid fa-clipboard-check"></i> Inspection Form</h2>
                    <p class="rt-page-sub">Complete the property condition checklist and compliance assessment.</p>
                </div>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <a class="btn-secondary-action" href="inspection_evidence.php?id=<?php echo $id; ?>">
                        <i class="fa-solid fa-camera"></i> Evidence (<?php echo $evidenceCount; ?>)
                    </a>
                    <a class="btn-outline-primary-custom" href="print_report.php?id=<?php echo $id; ?>" target="_blank">
                        <i class="fa-solid fa-print"></i> Print Report
                    </a>
                </div>
            </div>

            <?php if($successMsg): ?>
                <div class="alert-custom alert-success-custom">
                    <i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($successMsg); ?>
                </div>
            <?php endif; ?>
            <?php if($errorMsg): ?>
                <div class="alert-custom alert-danger-custom">
                    <i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($errorMsg); ?>
                </div>
            <?php endif; ?>

            <div class="row g-4">
                <div class="col-xl-8">
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?php echo inspector_csrf(); ?>">
                        <input type="hidden" name="inspection_id" value="<?php echo $id; ?>">

                        <!-- INSPECTION DETAILS -->
                        <?php if (!empty($inspectionNo) || !empty($typeLabel) || !empty($categoryLabel) || !empty($ownerRemarks)): ?>
                        <div class="rt-card">
                            <div class="rt-card-header">
                                <i class="fa-solid fa-info-circle"></i> Inspection Request Details
                            </div>
                            <div class="rt-card-body">
                                <div class="row g-3 mb-3">
                                    <?php if (!empty($inspectionNo)): ?>
                                    <div class="col-md-6">
                                        <div class="form-label-custom">Inspection No.</div>
                                        <div style="font-weight:800; color:var(--red-accent); font-family:'Space Grotesk',sans-serif; font-size:14px;">
                                            <?php echo inspector_e($inspectionNo); ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($typeLabel) && $typeLabel !== '—'): ?>
                                    <div class="col-md-6">
                                        <div class="form-label-custom">Inspection Type (Purpose)</div>
                                        <span class="inspection-type-chip"><?php echo inspector_e($typeLabel); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($categoryLabel) && $categoryLabel !== '—'): ?>
                                    <div class="col-md-12">
                                        <div class="form-label-custom">Inspection Category (Subject)</div>
                                        <span class="inspection-category-chip"><?php echo inspector_e($categoryLabel); ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($ownerRemarks)): ?>
                                <div class="owner-remarks-box">
                                    <strong><i class="fa-solid fa-comment-dots me-1"></i> Owner Remarks / Request</strong>
                                    <?php echo nl2br(inspector_e($ownerRemarks)); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- PROPERTY INFO -->
                        <div class="rt-card">
                            <div class="rt-card-header">
                                <i class="fa-solid fa-building"></i> Property Information
                            </div>
                            <div class="rt-card-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <div class="form-label-custom">Property</div>
                                        <div style="font-weight:700; color:var(--text-main);">
                                            <?php echo inspector_e($inspection['rental_name']??$inspection['business_name']??'N/A'); ?>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-label-custom">Tracking No.</div>
                                        <div style="font-weight:700; color:var(--red-accent);">
                                            <?php echo inspector_e($inspection['tracking_no']??'—'); ?>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-label-custom">Barangay</div>
                                        <div style="font-weight:600;"><?php echo inspector_e($inspection['barangay']??'—'); ?></div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-label-custom">Owner/Tenant</div>
                                        <div style="font-weight:600;"><?php echo inspector_e($inspection['owner_name']??'—'); ?></div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-label-custom">Inspection Date</div>
                                        <div style="font-weight:600;">
                                            <?php echo !empty($inspection['inspection_date'])?date('M d, Y',strtotime($inspection['inspection_date'])):'TBD'; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- CHECKLIST -->
                        <div class="rt-card">
                            <div class="rt-card-header">
                                <i class="fa-solid fa-list-check"></i> Property Condition Checklist
                            </div>
                            <div class="rt-card-body">
                                <div class="row g-3 mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label-custom">Permit Displayed?</label>
                                        <select name="permit_displayed" class="form-select-custom">
                                            <option <?php echo ($check['permit_displayed']??'')==='Yes'?'selected':''; ?>>Yes</option>
                                            <option <?php echo ($check['permit_displayed']??'')==='No'?'selected':''; ?>>No</option>
                                            <option <?php echo ($check['permit_displayed']??'')==='N/A'?'selected':''; ?>>N/A</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label-custom">Permit Matches Property?</label>
                                        <select name="permit_matches" class="form-select-custom">
                                            <option <?php echo ($check['permit_matches']??'')==='Yes'?'selected':''; ?>>Yes</option>
                                            <option <?php echo ($check['permit_matches']??'')==='No'?'selected':''; ?>>No</option>
                                            <option <?php echo ($check['permit_matches']??'')==='N/A'?'selected':''; ?>>N/A</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="check-grid">
                                    <?php foreach(['structural_condition'=>'Structural Condition','electrical_condition'=>'Electrical / Wiring','plumbing_condition'=>'Plumbing','sanitation_condition'=>'Sanitation','fire_safety'=>'Fire Safety','cleanliness'=>'Cleanliness','overall_condition'=>'Overall Property Condition'] as $f=>$label): ?>
                                    <div class="check-item">
                                        <label><?php echo $label; ?></label>
                                        <select name="<?php echo $f; ?>" class="form-select-custom">
                                            <option <?php echo $val($f)==='Good'?'selected':''; ?>>Good</option>
                                            <option <?php echo $val($f)==='Fair'?'selected':''; ?>>Fair</option>
                                            <option <?php echo $val($f)==='Poor'?'selected':''; ?>>Poor</option>
                                        </select>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- FINDINGS -->
                        <div class="rt-card">
                            <div class="rt-card-header">
                                <i class="fa-solid fa-triangle-exclamation"></i> Findings &amp; Violations
                            </div>
                            <div class="rt-card-body">
                                <p style="color:var(--text-muted); font-size:12px; margin-bottom:12px;">
                                    Record the major observations here. You can add detailed findings after saving.
                                </p>
                                <label class="form-label-custom">General Findings</label>
                                <textarea name="general_findings" class="form-control-custom" rows="4" style="margin-bottom:16px;" placeholder="Describe violations, damages, hazards, missing requirements, or other findings..."><?php echo inspector_e($check['general_findings']??''); ?></textarea>
                                <label class="form-label-custom">Recommended Action</label>
                                <textarea name="general_recommendation" class="form-control-custom" rows="3" placeholder="What should the tenant/property administrator do next?"><?php echo inspector_e($check['general_recommendation']??''); ?></textarea>
                            </div>
                        </div>

                        <!-- RESULT -->
                        <div class="rt-card">
                            <div class="rt-card-header">
                                <i class="fa-solid fa-flag-checkered"></i> Inspection Result
                            </div>
                            <div class="rt-card-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label-custom">Result</label>
                                        <select name="result" class="form-select-custom">
                                            <option <?php echo ($check['result']??'For Review')==='Passed'?'selected':''; ?>>Passed</option>
                                            <option <?php echo ($check['result']??'For Review')==='For Review'?'selected':''; ?>>For Review</option>
                                            <option <?php echo ($check['result']??'For Review')==='Failed'?'selected':''; ?>>Failed</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label-custom">Current Inspection Status</label>
                                        <select class="form-select-custom" disabled>
                                            <option><?php echo inspector_e($inspection['inspection_status']??'Scheduled'); ?></option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="sticky-actions">
                            <span style="color:var(--text-muted); font-size:11.5px;">
                                <i class="fa-solid fa-shield-halved me-1"></i> Changes are recorded under your inspector account.
                            </span>
                            <button class="btn-primary-action" type="submit">
                                <i class="fa-solid fa-floppy-disk"></i> Save Inspection
                            </button>
                        </div>
                    </form>
                </div>

                <div class="col-xl-4">
                    <!-- SCORE BOX -->
                    <div class="score-box mb-4">
                        <div style="font-size:11px; text-transform:uppercase; font-weight:800; letter-spacing:0.5px; opacity:0.85;">
                            Inspection Compliance Score
                        </div>
                        <div class="score-number"><?php echo inspector_e($check['compliance_score']??0); ?>%</div>
                        <div class="progress" style="height:8px;">
                            <div class="progress-bar bg-light" style="width:<?php echo (float)($check['compliance_score']??0); ?>%"></div>
                        </div>
                        <div style="font-size:11px; margin-top:10px; opacity:0.9;">
                            Based on the property condition checklist.
                        </div>
                    </div>

                    <!-- FINDINGS CARD -->
                    <div class="rt-card">
                        <div class="rt-card-header">
                            <i class="fa-solid fa-triangle-exclamation"></i> Detailed Findings
                        </div>
                        <div class="rt-card-body">
                            <?php if(!$findings): ?>
                                <p style="color:var(--text-muted); font-size:12px; margin-bottom:0;">
                                    No detailed findings yet. Use the Findings page after saving the checklist.
                                </p>
                            <?php else: foreach($findings as $f):
                                $sev = $f['severity'] ?? 'Minor';
                                $sevClass = $sev === 'Critical' ? 'badge-critical' : ($sev === 'Major' ? 'badge-major' : 'badge-minor');
                            ?>
                                <div style="border:1px solid var(--border-color); border-radius:10px; padding:12px; margin-bottom:10px; background:var(--bg-card-alt);">
                                    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:8px;">
                                        <strong style="font-size:12.5px; color:var(--text-main);"><?php echo inspector_e($f['finding_type']); ?></strong>
                                        <span class="badge-custom <?php echo $sevClass; ?>"><?php echo inspector_e($sev); ?></span>
                                    </div>
                                    <p style="font-size:12px; margin:8px 0 4px 0; color:var(--text-sub);">
                                        <?php echo nl2br(inspector_e($f['description'])); ?>
                                    </p>
                                    <small style="color:var(--text-muted); font-size:10.5px;"><?php echo inspector_e($f['status']); ?></small>
                                </div>
                            <?php endforeach; endif; ?>
                            <a href="findings.php?id=<?php echo $id; ?>" class="btn-outline-warning-custom" style="width:100%; margin-top:8px;">
                                <i class="fa-solid fa-list"></i> Manage Findings
                            </a>
                        </div>
                    </div>

                    <!-- MAINTENANCE CARD -->
                    <div class="rt-card">
                        <div class="rt-card-header">
                            <i class="fa-solid fa-tools"></i> Maintenance Recommendations
                        </div>
                        <div class="rt-card-body">
                            <?php if(!$maintenance): ?>
                                <p style="color:var(--text-muted); font-size:12px;">No maintenance recommendation yet.</p>
                            <?php else: foreach(array_slice($maintenance,0,3) as $m): ?>
                                <div style="border:1px solid var(--border-color); border-radius:10px; padding:10px; margin-bottom:8px; background:var(--bg-card-alt);">
                                    <strong style="font-size:11.5px; color:var(--red-accent); text-transform:uppercase;">
                                        <?php echo inspector_e($m['priority']); ?>
                                    </strong>
                                    <div style="font-size:12px; color:var(--text-sub); margin-top:4px;">
                                        <?php echo inspector_e($m['recommendation']); ?>
                                    </div>
                                </div>
                            <?php endforeach; endif; ?>
                            <a href="maintenance.php?id=<?php echo $id; ?>" class="btn-outline-primary-custom" style="width:100%; margin-top:8px;">
                                <i class="fa-solid fa-tools"></i> Manage Maintenance
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <footer class="footer">
                <span>&copy; <?php echo date('Y'); ?> <strong>RENTRACK LGU</strong> &middot; Inspector Portal</span>
                <span>Unified Rental Registration &amp; Compliance System</span>
            </footer>
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

    /* On inspection form, search redirects to inspections page */
    searchInput?.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            const q = searchInput.value.trim();
            if (q.length >= 2) {
                window.location.href = 'inspections.php?search=' + encodeURIComponent(q);
            }
        }
    });

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