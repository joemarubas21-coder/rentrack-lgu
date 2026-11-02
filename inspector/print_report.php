<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();
include "includes/auth.php";
include "../config/db.php";

date_default_timezone_set("Asia/Manila");

$inspectorId = (int) ($_SESSION['user_id'] ?? 0);
$inspectionId = (int) ($_GET['id'] ?? 0);

try {
    // Only allow the inspector to print their OWN inspections
    $stmt = $conn->prepare("
        SELECT i.*, 
               r.rental_name, 
               r.business_name, 
               r.tracking_no, 
               r.barangay, 
               r.permit_expiry,
               r.business_address,
               u.fullname AS owner_name
        FROM inspections i
        INNER JOIN rentals r ON i.rental_id = r.id
        LEFT JOIN users u ON r.user_id = u.id
        WHERE i.id = :inspection_id AND i.inspector_id = :inspector_id
        LIMIT 1
    ");
    $stmt->execute([
        ':inspection_id' => $inspectionId,
        ':inspector_id'  => $inspectorId
    ]);
    $inspection = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$inspection) {
        http_response_code(404);
        die('Report not found or not assigned to your account.');
    }

    // Photo evidence
    $photos = [];
    $photoStmt = $conn->prepare("SELECT photo_path, original_name FROM inspection_photos WHERE inspection_id = :inspection_id");
    $photoStmt->execute([':inspection_id' => $inspectionId]);
    $photos = $photoStmt->fetchAll(PDO::FETCH_ASSOC);

    // Checklist data (if exists)
    $checklist = null;
    try {
        $checkStmt = $conn->prepare("SELECT * FROM inspector_checklists WHERE inspection_id = :inspection_id LIMIT 1");
        $checkStmt->execute([':inspection_id' => $inspectionId]);
        $checklist = $checkStmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $checklist = null;
    }

} catch (PDOException $e) {
    http_response_code(500);
    die('Database error: ' . safe_error($e));
}

$inspectorName = htmlspecialchars($_SESSION['fullname'] ?? 'Inspector', ENT_QUOTES, 'UTF-8');
$status = $inspection['inspection_status'] ?? 'Pending';
$reportNo = 'INSP-' . str_pad($inspectionId, 6, '0', STR_PAD_LEFT);
$generatedDate = date('F j, Y g:i A');

// Status badge config
$statusConfig = [
    'Passed'    => ['color' => '#15803d', 'bg' => '#dcfce7', 'border' => '#86efac', 'label' => 'PASSED'],
    'Failed'    => ['color' => '#b91c1c', 'bg' => '#fee2e2', 'border' => '#fca5a5', 'label' => 'FAILED'],
    'Completed' => ['color' => '#d97706', 'bg' => '#fef3c7', 'border' => '#fcd34d', 'label' => 'FOR REVIEW'],
    'Pending'   => ['color' => '#d97706', 'bg' => '#fef3c7', 'border' => '#fcd34d', 'label' => 'PENDING'],
];
$sc = $statusConfig[$status] ?? $statusConfig['Pending'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Inspection Report <?php echo htmlspecialchars($reportNo, ENT_QUOTES, 'UTF-8'); ?></title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
        font-family: 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;
        background: #e5e7eb;
        color: #1f2937;
        padding: 30px 15px;
        font-size: 12px;
        line-height: 1.5;
    }

    .page {
        max-width: 850px;
        margin: 0 auto;
        background: #ffffff;
        padding: 40px 50px 50px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        position: relative;
        border-top: 6px solid #15803d;
    }

    /* Watermark */
    .watermark {
        position: absolute;
        top: 45%;
        left: 50%;
        transform: translate(-50%, -50%) rotate(-30deg);
        font-size: 80px;
        font-weight: 900;
        color: rgba(21, 128, 61, 0.05);
        letter-spacing: 10px;
        text-transform: uppercase;
        pointer-events: none;
        white-space: nowrap;
        z-index: 0;
    }

    /* Header */
    .doc-header {
        display: flex;
        align-items: center;
        gap: 20px;
        padding-bottom: 18px;
        border-bottom: 3px double #15803d;
        margin-bottom: 22px;
        position: relative;
        z-index: 1;
    }

    .seal {
        width: 95px;
        height: 95px;
        border-radius: 50%;
        border: 3px double #15803d;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        background: #f0fdf4;
        text-align: center;
        line-height: 1.1;
        overflow: hidden;
    }

    /* City Seal image */
    .seal.seal-image {
        background: #ffffff;
        padding: 2px;
        border: none;
    }

    .seal.seal-image img {
        width: 100%;
        height: 100%;
        object-fit: contain;
        display: block;
    }

    .seal .seal-icon {
        font-size: 22px;
        color: #15803d;
        margin-bottom: 2px;
    }

    .seal .seal-text {
        font-size: 7px;
        font-weight: 800;
        color: #15803d;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .header-text {
        flex: 1;
        text-align: center;
    }

    .header-text .country {
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 3px;
        text-transform: uppercase;
        color: #64748b;
    }

    .header-text .city {
        font-size: 22px;
        font-weight: 800;
        text-transform: uppercase;
        color: #15803d;
        letter-spacing: 2px;
        margin: 2px 0;
    }

    .header-text .office {
        font-size: 10px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 1.5px;
        color: #d97706;
    }

    /* Title bar */
    .doc-title-bar {
        text-align: center;
        background: linear-gradient(135deg, #f0fdf4, #dcfce7);
        border: 1px solid #86efac;
        border-radius: 8px;
        padding: 12px;
        margin-bottom: 20px;
        position: relative;
        z-index: 1;
    }

    .doc-title-bar h1 {
        font-size: 16px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 3px;
        color: #15803d;
        margin-bottom: 2px;
    }

    .doc-title-bar p {
        font-size: 10px;
        color: #64748b;
        letter-spacing: 1px;
        text-transform: uppercase;
        font-weight: 600;
    }

    /* Report meta */
    .report-meta {
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        padding: 10px 16px;
        margin-bottom: 20px;
        position: relative;
        z-index: 1;
        flex-wrap: wrap;
        gap: 10px;
    }

    .report-meta .meta-item {
        display: flex;
        flex-direction: column;
    }

    .report-meta .meta-item .label {
        font-size: 8.5px;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: #64748b;
        font-weight: 700;
    }

    .report-meta .meta-item .value {
        font-size: 12px;
        font-weight: 700;
        color: #0f172a;
        font-family: 'Courier New', monospace;
    }

    .status-badge {
        display: inline-block;
        padding: 6px 20px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 800;
        letter-spacing: 1.5px;
        text-transform: uppercase;
        border: 2px solid;
    }

    /* Section */
    .section {
        margin-bottom: 18px;
        position: relative;
        z-index: 1;
        page-break-inside: avoid;
    }

    .section-title {
        font-size: 11px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 1.5px;
        color: #15803d;
        border-bottom: 2px solid #bbf7d0;
        padding-bottom: 5px;
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .section-title::before {
        content: '';
        display: inline-block;
        width: 4px;
        height: 14px;
        background: #15803d;
        border-radius: 2px;
    }

    /* Info grid */
    .info-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        overflow: hidden;
    }

    .info-grid .info-item {
        padding: 9px 14px;
        border-bottom: 1px solid #e2e8f0;
        border-right: 1px solid #e2e8f0;
    }

    .info-grid .info-item:nth-child(2n) {
        border-right: none;
    }

    .info-grid .info-item.full {
        grid-column: 1 / -1;
        border-right: none;
    }

    .info-grid .info-item .label {
        font-size: 9px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #64748b;
        font-weight: 700;
        display: block;
        margin-bottom: 2px;
    }

    .info-grid .info-item .value {
        font-size: 12.5px;
        font-weight: 600;
        color: #0f172a;
        word-break: break-word;
    }

    /* Checklist table */
    .checklist-table {
        width: 100%;
        border-collapse: collapse;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        overflow: hidden;
        font-size: 11.5px;
    }

    .checklist-table th {
        background: #f0fdf4;
        color: #15803d;
        font-weight: 800;
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 8px 12px;
        text-align: left;
        border-bottom: 2px solid #bbf7d0;
    }

    .checklist-table td {
        padding: 7px 12px;
        border-bottom: 1px solid #f1f5f9;
        color: #1f2937;
    }

    .checklist-table tr:last-child td {
        border-bottom: none;
    }

    .checklist-table .rating {
        font-weight: 700;
        text-align: center;
        border-radius: 4px;
        padding: 2px 10px;
        font-size: 10.5px;
        display: inline-block;
    }

    .rating-good { background: #dcfce7; color: #15803d; }
    .rating-fair { background: #fef3c7; color: #d97706; }
    .rating-poor { background: #fee2e2; color: #b91c1c; }

    /* Notes box */
    .notes-box {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-left: 4px solid #15803d;
        border-radius: 6px;
        padding: 12px 14px;
        font-size: 12px;
        color: #334155;
        line-height: 1.6;
        white-space: pre-wrap;
        min-height: 50px;
    }

    .notes-box.empty {
        color: #94a3b8;
        font-style: italic;
    }

    /* Photo grid */
    .photo-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 10px;
    }

    .photo-grid .photo-item {
        position: relative;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        overflow: hidden;
        background: #f8fafc;
    }

    .photo-grid .photo-item img {
        width: 100%;
        height: 120px;
        object-fit: cover;
        display: block;
    }

    .photo-grid .photo-item .photo-label {
        padding: 4px 8px;
        font-size: 9px;
        color: #64748b;
        background: #f8fafc;
        border-top: 1px solid #e2e8f0;
        text-align: center;
        font-weight: 600;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* Signature area */
    .signature-area {
        display: flex;
        justify-content: space-between;
        gap: 40px;
        margin-top: 40px;
        padding-top: 10px;
        position: relative;
        z-index: 1;
        page-break-inside: avoid;
    }

    .sig-block {
        flex: 1;
        text-align: center;
    }

    .sig-block .sig-img {
        max-width: 200px;
        max-height: 60px;
        margin: 0 auto 4px;
        display: block;
    }

    .sig-block .sig-line {
        border-top: 1px solid #1f2937;
        margin: 30px auto 4px;
        width: 220px;
    }

    .sig-block .sig-name {
        font-size: 12px;
        font-weight: 700;
        color: #0f172a;
    }

    .sig-block .sig-role {
        font-size: 10px;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 600;
    }

    /* Footer */
    .doc-footer {
        margin-top: 30px;
        padding-top: 12px;
        border-top: 1px dashed #cbd5e1;
        text-align: center;
        font-size: 9px;
        color: #94a3b8;
        position: relative;
        z-index: 1;
    }

    .doc-footer .hash {
        font-family: 'Courier New', monospace;
        background: #f1f5f9;
        padding: 1px 6px;
        border-radius: 3px;
        color: #475569;
        word-break: break-all;
    }

    /* Toolbar */
    .print-toolbar {
        max-width: 850px;
        margin: 0 auto 20px;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        flex-wrap: wrap;
    }

    .btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 9px 18px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 700;
        text-decoration: none;
        border: none;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .btn-print {
        background: #15803d;
        color: #fff;
    }

    .btn-print:hover {
        background: #166534;
        color: #fff;
    }

    .btn-back {
        background: #fff;
        color: #475569;
        border: 1px solid #cbd5e1;
    }

    .btn-back:hover {
        background: #f1f5f9;
        color: #1f2937;
    }

    /* Print styles */
    @media print {
        body {
            background: #ffffff;
            padding: 0;
            font-size: 11px;
        }
        .page {
            box-shadow: none;
            max-width: 100%;
            padding: 20px 30px;
            border-top-width: 4px;
        }
        .print-toolbar { display: none !important; }
        .watermark { font-size: 70px; }
        .section, .signature-area, .checklist-table, .info-grid { page-break-inside: avoid; }
        .photo-grid .photo-item img { height: 100px; }
        @page {
            margin: 12mm;
            size: A4;
        }
    }

    /* Responsive */
    @media (max-width: 640px) {
        .page { padding: 24px 18px 30px; }
        .doc-header { flex-direction: column; text-align: center; }
        .info-grid { grid-template-columns: 1fr; }
        .info-grid .info-item { border-right: none !important; }
        .photo-grid { grid-template-columns: repeat(2, 1fr); }
        .signature-area { flex-direction: column; gap: 30px; }
        .watermark { font-size: 45px; }
    }
</style>
</head>
<body>

<!-- Print Toolbar -->
<div class="print-toolbar">
    <a href="reports.php" class="btn btn-back">← Back to Reports</a>
    <button class="btn btn-print" onclick="window.print()">🖨️ Print / Save as PDF</button>
</div>

<!-- Report Document -->
<div class="page">

    <div class="watermark">RENTRACK LGU</div>

    <!-- HEADER -->
    <div class="doc-header">
        <!-- LEFT: City Seal Image -->
        <div class="seal seal-image">
            <img src="assets/images/images.jpg" alt="Santiago City Seal">
        </div>
        <div class="header-text">
            <div class="country">Republic of the Philippines</div>
            <div class="country">Province of Isabela</div>
            <div class="city">City of Santiago</div>
            <div class="office">Office of the City Mayor · Business Permits &amp; Licensing</div>
        </div>
        <!-- RIGHT: LGU Certified Badge -->
        <div class="seal">
            <div class="seal-icon">✅</div>
            <div class="seal-text">LGU<br>CERTIFIED</div>
        </div>
    </div>

    <!-- TITLE -->
    <div class="doc-title-bar">
        <h1>Property Inspection Report</h1>
        <p>Unified Rental Registration, Tracking and Compliance System</p>
    </div>

    <!-- REPORT META -->
    <div class="report-meta">
        <div class="meta-item">
            <span class="label">Report No.</span>
            <span class="value"><?php echo htmlspecialchars($reportNo, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <div class="meta-item">
            <span class="label">Date Generated</span>
            <span class="value"><?php echo htmlspecialchars($generatedDate, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <div class="meta-item">
            <span class="label">Inspection Result</span>
            <span class="status-badge" style="color:<?php echo $sc['color']; ?>; background:<?php echo $sc['bg']; ?>; border-color:<?php echo $sc['border']; ?>;">
                <?php echo htmlspecialchars($sc['label'], ENT_QUOTES, 'UTF-8'); ?>
            </span>
        </div>
    </div>

    <!-- SECTION 1: PROPERTY INFORMATION -->
    <div class="section">
        <div class="section-title">Property Information</div>
        <div class="info-grid">
            <div class="info-item">
                <span class="label">Property / Establishment</span>
                <span class="value"><?php echo htmlspecialchars($inspection['rental_name'] ?? $inspection['business_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <div class="info-item">
                <span class="label">Registered Owner</span>
                <span class="value"><?php echo htmlspecialchars($inspection['owner_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <div class="info-item">
                <span class="label">Barangay</span>
                <span class="value"><?php echo htmlspecialchars($inspection['barangay'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <div class="info-item">
                <span class="label">Tracking No.</span>
                <span class="value"><?php echo htmlspecialchars($inspection['tracking_no'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <?php if (!empty($inspection['business_address'])): ?>
            <div class="info-item full">
                <span class="label">Business Address</span>
                <span class="value"><?php echo htmlspecialchars($inspection['business_address'], ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- SECTION 2: INSPECTION DETAILS -->
    <div class="section">
        <div class="section-title">Inspection Details</div>
        <div class="info-grid">
            <div class="info-item">
                <span class="label">Inspection Date</span>
                <span class="value"><?php echo !empty($inspection['inspection_date']) ? date('F j, Y', strtotime($inspection['inspection_date'])) : 'N/A'; ?></span>
            </div>
            <div class="info-item">
                <span class="label">Inspection Time</span>
                <span class="value"><?php echo !empty($inspection['inspection_time']) ? date('g:i A', strtotime($inspection['inspection_time'])) : 'N/A'; ?></span>
            </div>
            <div class="info-item">
                <span class="label">Inspection Type</span>
                <span class="value"><?php echo htmlspecialchars($inspection['inspection_type'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <div class="info-item">
                <span class="label">Inspection Category</span>
                <span class="value"><?php echo htmlspecialchars($inspection['inspection_category'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <div class="info-item">
                <span class="label">Permit Displayed On-Site</span>
                <span class="value"><?php echo htmlspecialchars($inspection['permit_displayed'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <div class="info-item">
                <span class="label">Permit Matches System Record</span>
                <span class="value"><?php echo htmlspecialchars($inspection['permit_matches'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <div class="info-item">
                <span class="label">Permit Expiry on File</span>
                <span class="value"><?php echo !empty($inspection['permit_expiry']) ? date('F j, Y', strtotime($inspection['permit_expiry'])) : 'No record'; ?></span>
            </div>
            <div class="info-item">
                <span class="label">Inspecting Officer</span>
                <span class="value"><?php echo $inspectorName; ?></span>
            </div>
        </div>
    </div>

    <!-- SECTION 3: CHECKLIST (if available) -->
    <?php if ($checklist): ?>
    <div class="section">
        <div class="section-title">Property Condition Checklist</div>
        <table class="checklist-table">
            <thead>
                <tr>
                    <th style="width: 55%;">Assessment Area</th>
                    <th style="width: 20%; text-align: center;">Rating</th>
                    <th style="width: 25%;">Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $checklistItems = [
                    'structural_condition' => 'Structural Condition',
                    'electrical_condition' => 'Electrical / Wiring',
                    'plumbing_condition'   => 'Plumbing',
                    'sanitation_condition' => 'Sanitation',
                    'fire_safety'          => 'Fire Safety',
                    'cleanliness'          => 'Cleanliness',
                    'overall_condition'    => 'Overall Property Condition',
                ];
                foreach ($checklistItems as $key => $label):
                    $rating = $checklist[$key] ?? 'Good';
                    $ratingClass = strtolower($rating) === 'good' ? 'rating-good' : (strtolower($rating) === 'fair' ? 'rating-fair' : 'rating-poor');
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td style="text-align: center;">
                        <span class="rating <?php echo $ratingClass; ?>"><?php echo htmlspecialchars($rating, ENT_QUOTES, 'UTF-8'); ?></span>
                    </td>
                    <td style="color: #94a3b8; font-size: 10.5px;">—</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!empty($checklist['compliance_score'])): ?>
        <div style="margin-top: 10px; text-align: right; font-size: 12px;">
            <strong>Compliance Score:</strong>
            <span style="color: #15803d; font-weight: 800; font-size: 14px;"><?php echo number_format((float)$checklist['compliance_score'], 2); ?>%</span>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- SECTION 4: FINDINGS -->
    <div class="section">
        <div class="section-title">Findings &amp; Observations</div>
        <?php if (!empty($inspection['inspector_notes'])): ?>
            <div class="notes-box"><?php echo htmlspecialchars($inspection['inspector_notes'], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php else: ?>
            <div class="notes-box empty">No findings recorded for this inspection.</div>
        <?php endif; ?>
    </div>

    <!-- SECTION 5: RECOMMENDATIONS -->
    <?php if ($checklist && !empty($checklist['general_recommendation'])): ?>
    <div class="section">
        <div class="section-title">Recommendations</div>
        <div class="notes-box"><?php echo htmlspecialchars($checklist['general_recommendation'], ENT_QUOTES, 'UTF-8'); ?></div>
    </div>
    <?php endif; ?>

    <!-- SECTION 6: PHOTO EVIDENCE -->
    <?php if (!empty($photos)): ?>
    <div class="section">
        <div class="section-title">Photo Evidence (<?php echo count($photos); ?>)</div>
        <div class="photo-grid">
            <?php foreach ($photos as $p): ?>
                <div class="photo-item">
                    <img src="<?php echo htmlspecialchars($p['photo_path'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($p['original_name'], ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="photo-label"><?php echo htmlspecialchars($p['original_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- SIGNATURES -->
    <div class="signature-area">
        <div class="sig-block">
            <?php if (!empty($inspection['inspector_signature'])): ?>
                <img class="sig-img" src="<?php echo htmlspecialchars($inspection['inspector_signature'], ENT_QUOTES, 'UTF-8'); ?>" alt="Inspector signature">
            <?php else: ?>
                <div style="height: 60px;"></div>
            <?php endif; ?>
            <div class="sig-line"></div>
            <div class="sig-name"><?php echo $inspectorName; ?></div>
            <div class="sig-role">Inspecting Officer</div>
        </div>
        <div class="sig-block">
            <div style="height: 60px;"></div>
            <div class="sig-line"></div>
            <div class="sig-name">City Building Official</div>
            <div class="sig-role">Approving Authority</div>
        </div>
    </div>

    <!-- FOOTER -->
    <div class="doc-footer">
        <p>This report is generated by the RENTRACK LGU System and is valid without signature for verification purposes only.</p>
        <p style="margin-top: 4px;">
            Document Hash: <span class="hash"><?php echo htmlspecialchars(hash('sha256', $reportNo . $inspectionId . $generatedDate), ENT_QUOTES, 'UTF-8'); ?></span>
        </p>
        <p style="margin-top: 4px;">Generated on <?php echo htmlspecialchars($generatedDate, ENT_QUOTES, 'UTF-8'); ?> · City of Santiago, Isabela</p>
    </div>

</div>

</body>
</html>