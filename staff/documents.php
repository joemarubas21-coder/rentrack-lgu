﻿<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();

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

/* PERMIT PREFIX MAP */
function getPermitPrefixMap() {
    return [
        'Business Permit'                    => 'BP',
        'Sanitary Permit'                    => 'SP',
        'Fire Safety Inspection Certificate' => 'FSIC',
        'Barangay Clearance'                 => 'BC',
        'Health Certificates'                => 'HC',
        'Lease Contract'                     => 'LC',
        'Lease Clearance Certificate'        => 'LCC',
        'Occupancy Compliance Permit'        => 'OCP',
        'Others'                             => 'PERMIT',
    ];
}

function getPermitTypeLabelFromPrefix($prefix) {
    $map = [
        'BP' => 'Business Permit', 'SP' => 'Sanitary Permit', 'FSIC' => 'Fire Safety',
        'BC' => 'Barangay Clearance', 'HC' => 'Health Cert', 'LC' => 'Lease Contract',
        'LCC' => 'Lease Clearance', 'OCP' => 'Occupancy', 'PERMIT' => 'Permit',
    ];
    return $map[$prefix] ?? 'Permit';
}

/* PERMIT TYPE CONFIGURATION */
function getPermitTypeConfig($permitType) {
    $types = [
        'Business Permit' => [
            'label'       => "Business / Mayor's Permit (Santiago)",
            'certTitle'   => "Certificate of Business Permit &amp; Mayor's Clearance",
            'office'      => "Office of the City Mayor &bull; Business Permits &amp; Licensing Office",
            'legalBasis'  => "This certification is hereby granted pursuant to the Revenue Code and applicable municipal ordinances of the City of Santiago, certifying that the establishment named below has satisfactorily complied with the requirements for the operation of a business within the city's jurisdiction.",
            'recordLabel' => 'Business / Establishment',
            'clause'      => "Must be conspicuously displayed at the business establishment premises at all times. Non-transferable. Subject to inspection and revocation for cause.",
            'sig1Role'    => 'City Treasurer',
            'sig1Office'  => 'Collections &amp; Revenue Office',
            'sig2Role'    => 'City Mayor / BPLO Head',
        ],
        'Sanitary Permit' => [
            'label'       => 'Sanitary Permit',
            'certTitle'   => 'Certificate of Sanitary Permit',
            'office'      => 'City Health Office &bull; Sanitation Division',
            'legalBasis'  => "This certification is hereby issued pursuant to the Sanitation Code of the Philippines and applicable municipal ordinances of the City of Santiago, confirming that the establishment named below has complied with the required sanitary standards and health regulations for its operations.",
            'recordLabel' => 'Establishment / Business',
            'clause'      => "Must be conspicuously displayed at the establishment. Subject to periodic sanitary inspection. Non-transferable. Revocable upon violation of the Sanitation Code.",
            'sig1Role'    => 'City Health Officer',
            'sig1Office'  => 'City Health Office',
            'sig2Role'    => 'City Mayor',
        ],
        'Fire Safety Inspection Certificate' => [
            'label'       => 'Fire Safety Inspection Certificate (FSIC)',
            'certTitle'   => 'Fire Safety Inspection Certificate',
            'office'      => 'Bureau of Fire Protection &bull; City Fire Marshal Office',
            'legalBasis'  => "This certification is hereby issued pursuant to the Fire Code of the Philippines (RA 9514) and its Implementing Rules and Regulations, confirming that the establishment named below has passed the required fire safety inspection and complies with fire safety standards.",
            'recordLabel' => 'Establishment / Business',
            'clause'      => "Must be conspicuously displayed at the establishment. Valid for the period stated unless sooner revoked. Subject to re-inspection. Non-transferable.",
            'sig1Role'    => 'City Fire Marshal',
            'sig1Office'  => 'Bureau of Fire Protection',
            'sig2Role'    => 'City Mayor',
        ],
        'Barangay Clearance' => [
            'label'       => 'Barangay Clearance (for Business)',
            'certTitle'   => 'Barangay Business Clearance',
            'office'      => 'Barangay Hall &bull; Office of the Punong Barangay',
            'legalBasis'  => "This clearance is hereby issued pursuant to the Local Government Code of 1991 (RA 7160) and applicable barangay ordinances, certifying that the business named below has no derogatory record and is cleared to operate within the territorial jurisdiction of the Barangay.",
            'recordLabel' => 'Business / Establishment',
            'clause'      => "This clearance is issued for the purpose of securing a Mayor's Permit. Valid for the period stated. Non-transferable. Revocable for cause.",
            'sig1Role'    => 'Barangay Secretary',
            'sig1Office'  => 'Barangay Hall',
            'sig2Role'    => 'Punong Barangay',
        ],
        'Health Certificates' => [
            'label'       => 'Health Certificates (Staff/Boarding House)',
            'certTitle'   => 'Certificate of Health Compliance',
            'office'      => 'City Health Office &bull; Environmental Health Division',
            'legalBasis'  => "This certification is hereby issued pursuant to the Sanitation Code and applicable health ordinances of the City of Santiago, confirming that the personnel/staff or boarding house named below has complied with the required health and sanitation standards.",
            'recordLabel' => 'Personnel / Boarding House',
            'clause'      => "Issued to the named individual/establishment only. Non-transferable. Subject to revocation for violation of health and sanitation laws.",
            'sig1Role'    => 'City Health Officer',
            'sig1Office'  => 'City Health Office',
            'sig2Role'    => 'City Mayor',
        ],
        'Lease Contract' => [
            'label'       => 'Lease Contract / Rental Agreement',
            'certTitle'   => 'Certificate of Lease Contract Registration',
            'office'      => 'Office of the City Assessor &bull; Zoning &amp; Rental Compliance Division',
            'legalBasis'  => "This certification is hereby issued pursuant to the municipal rental registration and zoning ordinances of the City of Santiago, confirming that the lease or rental agreement for the property named below has been registered with, and reviewed by, this Office for compliance.",
            'recordLabel' => 'Leased Property / Unit',
            'clause'      => "This certification covers only the registration of the lease contract. It does not authorize business operations and does not substitute for a separate business permit. Non-transferable.",
            'sig1Role'    => 'Zoning Administrator',
            'sig1Office'  => "City Assessor's Office",
            'sig2Role'    => 'City Mayor',
        ],
        'Lease Clearance Certificate' => [
            'label'       => 'Lease Clearance Certificate',
            'certTitle'   => 'Certificate of Lease Clearance',
            'office'      => 'Office of the City Assessor &bull; Zoning &amp; Rental Compliance Division',
            'legalBasis'  => "This certification is hereby issued pursuant to the municipal rental registration and zoning ordinances of the City of Santiago, confirming that the lease or rental arrangement for the property named below has been registered with, and reviewed by, this Office for compliance with the applicable zoning classification and rental documentation requirements.",
            'recordLabel' => 'Leased Property / Unit',
            'clause'      => "This clearance covers only the registration and zoning compliance of the leased premises. It does not, by itself, authorize business operations and does not substitute for a separate business permit where required. Non-transferable.",
            'sig1Role'    => 'Zoning Administrator',
            'sig1Office'  => "City Assessor's Office",
            'sig2Role'    => 'City Mayor',
        ],
        'Occupancy Compliance Permit' => [
            'label'       => 'Occupancy Compliance Permit',
            'certTitle'   => 'Certificate of Occupancy Compliance',
            'office'      => 'Office of the City Engineer &bull; Office of the Building Official',
            'legalBasis'  => "This certification is hereby issued pursuant to the National Building Code and applicable municipal ordinances of the City of Santiago, confirming that the premises named below has undergone the required inspection and has satisfactorily complied with structural, safety, and occupancy standards for its declared use.",
            'recordLabel' => 'Property / Premises',
            'clause'      => "Must be conspicuously displayed at the premises. Subject to periodic re-inspection. Any structural alteration, change of use, or change of occupancy at the premises requires re-certification.",
            'sig1Role'    => 'City Engineer / Building Official',
            'sig1Office'  => 'Office of the Building Official',
            'sig2Role'    => 'City Mayor',
        ],
        'Others' => [
            'label'       => 'Other Permit / Certification',
            'certTitle'   => 'Certificate of Permit / Certification',
            'office'      => 'Office of the City Mayor &bull; Business Permits &amp; Licensing Office',
            'legalBasis'  => "This certification is hereby issued pursuant to applicable municipal ordinances of the City of Santiago, confirming that the establishment named below has satisfactorily complied with the requirements for the issuance of this permit or certification.",
            'recordLabel' => 'Business / Establishment',
            'clause'      => "Must be conspicuously displayed at the establishment. Non-transferable. Subject to inspection and revocation for cause.",
            'sig1Role'    => 'City Treasurer',
            'sig1Office'  => 'Collections &amp; Revenue Office',
            'sig2Role'    => 'City Mayor / BPLO Head',
        ],
    ];
    return $types[$permitType] ?? $types['Business Permit'];
}

/* REUSABLE OFFICIAL PERMIT CERTIFICATE BUILDER */
function buildPermitCertificateHtml(array $d) {
    $cfg = getPermitTypeConfig($d['permitType']);
    $displayPermitType = !empty($d['customPermitType']) ? $d['customPermitType'] : $cfg['label'];
    $permitTitle = $displayPermitType . ' - ' . $d['bizName'];
    $isPreview = !empty($d['isPreview']);
    $permitNo = $d['permitNo'] ?? '';

    $logoFile = __DIR__ . '/assets/images/images.jpg';
    $logoSrc = '';
    if (is_file($logoFile)) {
        $logoSrc = 'data:image/jpeg;base64,' . base64_encode(file_get_contents($logoFile));
    }

    $watermarkHtml = $isPreview
        ? '<div class="preview-watermark">PREVIEW ONLY<span>Not Yet Official &mdash; Not Issued</span></div>'
        : '';

    $printBtn = $isPreview
        ? '<div class="no-print preview-banner"><i class="fa-solid fa-eye" style="margin-right:8px;"></i> You are previewing this permit before it is issued. Nothing has been saved or sent to the owner yet.</div>'
        : '<div class="no-print" style="text-align: right; margin-bottom: 20px; max-width: 820px; margin-left: auto; margin-right: auto;"><button onclick="window.print()" style="background: #dc2626; color: #fff; border: none; padding: 10px 22px; font-size: 14px; font-weight: 700; border-radius: 8px; cursor: pointer;"><i class="fa-solid fa-print" style="margin-right:8px;"></i>Print / Save as PDF</button></div>';

    $permitNoRow = !empty($permitNo)
        ? '<tr><td style="font-weight:700;">Permit Number</td><td><strong style="font-family:monospace;color:#dc2626;background:#fee2e2;padding:3px 10px;border-radius:6px;">' . htmlspecialchars($permitNo) . '</strong></td></tr>'
        : '';

    return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($permitTitle) . '</title>
    <style>
        body { font-family: Georgia, "Times New Roman", Times, serif; background: #f8fafc; color: #0f172a; margin: 0; padding: 20px; }
        .preview-banner { max-width: 820px; margin: 0 auto 18px auto; background: #fff7ed; border: 1px solid #fbbf24; color: #92400e; padding: 10px 18px; border-radius: 10px; font-family: "Inter", Arial, sans-serif; font-size: 13px; text-align: center; }
        .certificate-container { position: relative; border: 10px double #dc2626; padding: 30px 20px; max-width: 820px; margin: 0 auto; background: #ffffff; box-shadow: 0 14px 44px rgba(0,0,0,0.1); overflow: hidden; }
        .certificate-container::before { content: ""; position: absolute; inset: 10px; border: 1.5px solid #f87171; pointer-events: none; }
        .preview-watermark { position: absolute; top: 42%; left: 50%; transform: translate(-50%,-50%) rotate(-27deg); font-size: 38px; font-weight: 800; color: rgba(217,119,6,0.16); text-transform: uppercase; letter-spacing: 4px; text-align: center; line-height: 1.15; z-index: 1; white-space: nowrap; }
        .preview-watermark span { display: block; font-size: 14px; letter-spacing: 2px; margin-top: 6px; }
        .header { text-align: center; margin-bottom: 22px; padding-bottom: 18px; border-bottom: 2px solid #dc2626; position: relative; z-index: 2; }
        .header img { width: 85px; height: 85px; object-fit: contain; margin-bottom: 8px; }
        .header h3 { margin: 1px 0; font-size: 11px; text-transform: uppercase; font-weight: 500; letter-spacing: 2px; color: #64748b; }
        .header h1 { margin: 6px 0 2px; font-size: 20px; text-transform: uppercase; font-weight: 800; color: #dc2626; letter-spacing: 1.5px; }
        .header h2 { margin: 2px 0 0; font-size: 12px; text-transform: uppercase; font-weight: 600; color: #d97706; letter-spacing: 1px; }
        .cert-kicker { display: inline-block; margin-top: 16px; font-size: 10px; letter-spacing: 3px; text-transform: uppercase; color: #ef4444; border-top: 1px solid #fecaca; border-bottom: 1px solid #fecaca; padding: 4px 14px; }
        .cert-body { text-align: center; margin: 24px 0; position: relative; z-index: 2; }
        .cert-title { font-size: 16px; font-weight: 800; letter-spacing: 2px; text-transform: uppercase; color: #dc2626; margin: 16px 0 10px; }
        .cert-body p.lead { font-size: 13px; line-height: 1.75; margin: 0 auto; max-width: 630px; color: #334155; }
        .business-box { background: #f8fafc; border: 1.5px solid #cbd5e1; padding: 16px; margin: 20px 0; border-radius: 8px; text-align: left; position: relative; }
        .business-box::before { content: "OFFICIAL RECORD"; position: absolute; top: -10px; left: 22px; background: #ffffff; padding: 0 10px; font-size: 10px; letter-spacing: 2px; color: #dc2626; font-weight: 700; }
        .business-box table { width: 100%; font-size: 13px; border-collapse: collapse; }
        .business-box td { padding: 6px 0; border-bottom: 1px dotted #cbd5e1; display: block; width: 100%; color: #0f172a; }
        @media(min-width: 576px) {
            .certificate-container { padding: 46px 54px 40px; }
            .business-box td { display: table-cell; }
            .preview-watermark { font-size: 52px; }
            .header h1 { font-size: 24px; }
        }
        .business-box tr:last-child td { border-bottom: none; }
        .validity-strip { display: flex; flex-direction: column; gap: 12px; margin: 16px 0 8px; align-items: center; }
        @media(min-width: 576px) { .validity-strip { flex-direction: row; justify-content: center; gap: 48px; } }
        .validity-strip .lbl { font-size: 10px; text-transform: uppercase; letter-spacing: 1.5px; color: #64748b; }
        .validity-strip .val { font-size: 14px; font-weight: 700; color: #dc2626; margin-top: 2px; }
        .clause { font-size: 11px; font-style: italic; color: #475569; margin-top: 10px; }
        .sig-area { display: flex; flex-direction: column; margin-top: 34px; gap: 30px; position: relative; z-index: 2; }
        @media(min-width: 576px) { .sig-area { flex-direction: row; justify-content: space-between; align-items: flex-end; } }
        .sig-block { flex: 1; text-align: center; }
        .sig-line { border-top: 1px solid #0f172a; width: 200px; margin: 30px auto 6px auto; }
        .sig-block strong { font-size: 12px; color: #0f172a; }
        .sig-block span { font-size: 10px; color: #64748b; }
        .seal-box { width: 75px; height: 75px; margin: 0 auto 4px; border: 2px dashed #dc2626; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 8px; text-align: center; color: #dc2626; font-weight: 700; letter-spacing: 0.5px; line-height: 1.3; background: #fef2f2; }
        .qr-box { display: none; }
        @media(min-width: 768px) { .qr-box { position: absolute; bottom: 44px; right: 50px; width: 66px; height: 66px; border: 1px solid #cbd5e1; display: flex; align-items: center; justify-content: center; font-size: 7.5px; text-align: center; color: #64748b; background: repeating-linear-gradient(45deg,#f1f5f9,#f1f5f9 3px,#fff 3px,#fff 6px); z-index: 2; } }
        .footer-seal { margin-top: 24px; font-size: 10px; text-align: center; color: #64748b; border-top: 1px dashed #cbd5e1; padding-top: 10px; position: relative; z-index: 2; }
        .footer-seal code { background: #f1f5f9; padding: 2px 6px; border-radius: 4px; word-break: break-all; color: #0f172a; }
        @media print { body { padding: 0; background: #fff; } .no-print { display: none; } }
    </style>
</head>
<body>
    ' . $printBtn . '
    <div class="certificate-container">
        ' . $watermarkHtml . '
        <div class="qr-box">SCAN&nbsp;TO<br>VERIFY</div>
        <div class="header">
            <img src="' . $logoSrc . '" alt="City of Santiago Seal">
            <h3>Republic of the Philippines</h3>
            <h3>Province of Isabela</h3>
            <h1>City of Santiago</h1>
            <h2>' . $cfg['office'] . '</h2>
            <div class="cert-kicker">Official Municipal Document</div>
        </div>

        <div class="cert-body">
            <p class="cert-title">' . $cfg['certTitle'] . '</p>
            <p class="lead">' . $cfg['legalBasis'] . '</p>

            <div class="business-box">
                <table>
                    <tr><td style="width:38%;font-weight:700;">Tracking Number</td><td><strong>' . htmlspecialchars($d['trackingNo']) . '</strong></td></tr>
                    <tr><td style="font-weight:700;">' . htmlspecialchars($cfg['recordLabel']) . '</td><td><span style="font-size:15px;font-weight:700;color:#dc2626;">' . htmlspecialchars($d['bizName']) . '</span></td></tr>
                    <tr><td style="font-weight:700;">Registered Owner</td><td>' . htmlspecialchars($d['ownerName'] ?: 'N/A') . '</td></tr>
                    <tr><td style="font-weight:700;">Permit Classification</td><td>' . htmlspecialchars($displayPermitType) . '</td></tr>
                    ' . $permitNoRow . '
                    <tr><td style="font-weight:700;">Official Remarks</td><td>' . htmlspecialchars($d['notes']) . '</td></tr>
                </table>
            </div>

            <div class="validity-strip">
                <div><div class="lbl">Date Issued</div><div class="val">' . htmlspecialchars($d['issueDate']) . '</div></div>
                <div><div class="lbl">Valid Until</div><div class="val" style="color:#d97706;">' . htmlspecialchars($d['expiryDateFormatted']) . '</div></div>
            </div>

            <p class="clause">&ldquo;' . $cfg['clause'] . '&rdquo;</p>
        </div>

        <div class="sig-area">
            <div class="sig-block">
                <div class="sig-line"></div>
                <strong>' . htmlspecialchars($cfg['sig1Role']) . '</strong><br><span>' . $cfg['sig1Office'] . '</span>
            </div>
            <div class="sig-block">
                <div class="seal-box">OFFICIAL<br>DRY SEAL</div>
                <div class="sig-line" style="margin-top:0;"></div>
                <strong>' . htmlspecialchars($cfg['sig2Role']) . '</strong><br><span>City of Santiago, Philippines</span>
            </div>
        </div>

        <div class="footer-seal">
            <p>Security Verification Hash: <code>' . htmlspecialchars($d['securityHash']) . '</code></p>
            <p>' . ($isPreview ? 'Preview generated' : 'Verified and generated securely') . ' via RENTRACK LGU Official Municipal System &bull; Issued by Staff ID: ' . (int)$d['staffId'] . '</p>
        </div>
    </div>
</body>
</html>';
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
                    $badgeBg = '#f1f5f9';
                    $badgeColor = '#64748b';
                    if ($status === 'Pending') { $badgeBg = '#fef3c7'; $badgeColor = '#d97706'; }
                    elseif ($status === 'Approved') { $badgeBg = '#dcfce7'; $badgeColor = '#15803d'; }
                    elseif ($status === 'Rejected') { $badgeBg = '#fee2e2'; $badgeColor = '#dc2626'; }

                    echo '<a href="view_rental.php?id=' . $row['id'] . '" style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; color: #0f172a; text-decoration: none; transition: all 0.2s ease;">';
                    echo '<div style="display: flex; flex-direction: column; gap: 4px;">';
                    echo '<div style="font-weight: 600; font-size: 14px; color: #dc2626;">' . htmlspecialchars($row['tracking_no']) . ' <span style="color: #94a3b8; font-weight: 400;">&bull;</span> ' . htmlspecialchars($row['business_name']) . '</div>';
                    echo '<div style="font-size: 12px; color: #475569;"><i class="fa-solid fa-user-shield" style="font-size: 11px; margin-right: 4px;"></i> Owner: ' . htmlspecialchars($row['owner_name'] ?? 'N/A') . '</div>';
                    echo '</div>';
                    echo '<span style="padding: 4px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; background: ' . $badgeBg . '; color: ' . $badgeColor . '; text-transform: uppercase;">' . htmlspecialchars($status) . '</span>';
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

    if ($_GET['ajax_action'] === 'preview_permit') {
        header('Content-Type: text/html; charset=UTF-8');

        $rentalId   = (int)($_GET['rental_id'] ?? 0);
        $permitType = trim($_GET['permit_type'] ?? 'Business Permit');
        $customPermitType = trim($_GET['custom_permit_type'] ?? '');
        $validUntil = trim($_GET['valid_until'] ?? date('Y-m-d', strtotime('+1 year')));
        $notes      = trim($_GET['permit_notes'] ?? 'Issued upon verified payment approval.');

        if ($rentalId <= 0) {
            echo '<div style="padding:60px 30px;font-family:Arial,sans-serif;text-align:center;color:#b91c1c;">Please select a verified rental property before previewing the permit.</div>';
            exit;
        }

        $rStmt = $conn->prepare("
            SELECT r.id, r.tracking_no, r.business_name, r.rental_name, r.user_id, u.fullname as owner_name
            FROM rentals r
            LEFT JOIN users u ON r.user_id = u.id
            WHERE r.id = :rid
        ");
        $rStmt->execute([':rid' => $rentalId]);
        $rentalInfo = $rStmt->fetch(PDO::FETCH_ASSOC);

        if (!$rentalInfo) {
            echo '<div style="padding:60px 30px;font-family:Arial,sans-serif;text-align:center;color:#b91c1c;">Selected rental property was not found.</div>';
            exit;
        }

        $bizName     = $rentalInfo['business_name'] ?: $rentalInfo['rental_name'];
        $trackingNo  = $rentalInfo['tracking_no'] ?? ('TRK-' . $rentalId);
        $previewHash = hash('sha256', $rentalId . $bizName . 'preview-not-final');

        $prefixMap = getPermitPrefixMap();
        $previewPrefix = $prefixMap[$permitType] ?? 'PERMIT';
        $previewPermitNo = $previewPrefix . '-' . date('Y') . '-XXXX (PREVIEW)';

        echo buildPermitCertificateHtml([
            'trackingNo'          => $trackingNo,
            'bizName'             => $bizName,
            'ownerName'           => $rentalInfo['owner_name'] ?? 'N/A',
            'permitType'          => $permitType,
            'customPermitType'    => $customPermitType,
            'issueDate'           => date('F d, Y'),
            'expiryDateFormatted' => date('F d, Y', strtotime($validUntil)),
            'notes'               => $notes,
            'securityHash'        => $previewHash,
            'staffId'             => $staffId,
            'logo'                => 'assets/images/images.jpg',
            'isPreview'           => true,
            'permitNo'            => $previewPermitNo,
        ]);
        exit;
    }
}

// ==========================
// STAFF INFORMATION
// ==========================
$staffName = $_SESSION['fullname'] ?? "Staff";
$staffRole = $_SESSION['role'] ?? "Staff";
$staffId = (int)($_SESSION['user_id'] ?? 0);
$staffInitial = strtoupper(substr(trim($staffName), 0, 1));

$profilePicture = '';
if (columnExists($conn, 'users', 'profile_picture')) {
    $userProfileQuery = $conn->prepare("SELECT profile_picture FROM users WHERE id = :id LIMIT 1");
    $userProfileQuery->execute([':id' => $staffId]);
    $profilePicture = $userProfileQuery->fetchColumn() ?: '';
}

$pendingRenewalsCount = 0;
if (tableExists($conn, 'permit_renewals')) {
    $pendRenewalsQuery = $conn->query("SELECT COUNT(*) FROM permit_renewals WHERE status = 'Pending'");
    if ($pendRenewalsQuery) {
        $pendingRenewalsCount = (int)$pendRenewalsQuery->fetchColumn();
    }
}

// Financial counts (Payments lang - inalis na ang Remittances)
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

$notificationCount = 0;
if (tableExists($conn, 'notifications')) {
    $notifStmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE (user_id = :sid OR user_id = 0 OR user_id IS NULL) AND is_read = FALSE");
    $notifStmt->execute([':sid' => $staffId]);
    $notificationCount = (int)$notifStmt->fetchColumn();
}

$notifications = [];
if (tableExists($conn, 'notifications')) {
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

$successMessage = '';
$errorMessage = '';

function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
$csrfToken = generateCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (
        !isset($_POST['csrf_token']) ||
        !isset($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $_SESSION['error_message'] = 'Invalid session token. Please refresh the page and try again.';
        header('Location: documents.php');
        exit;
    } else {
        if ($_POST['action'] === 'delete_document') {
            $docId = (int)($_POST['doc_id'] ?? 0);
            if ($docId > 0) {
                try {
                    $stmt = $conn->prepare("SELECT * FROM documents WHERE id = :id");
                    $stmt->execute([':id' => $docId]);
                    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($doc) {
                        $filePath = dirname(__DIR__) . '/uploads/' . $doc['owner_folder_path'] . '/' . $doc['stored_name'];
                        if (file_exists($filePath)) {
                            @unlink($filePath);
                        }

                        $delStmt = $conn->prepare("DELETE FROM documents WHERE id = :id");
                        $delStmt->execute([':id' => $docId]);

                        $_SESSION['success_message'] = 'Document or permit deleted successfully from Supabase database.';
                    } else {
                        $_SESSION['error_message'] = 'Document record not found in database.';
                    }
                } catch (Exception $e) {
                    $_SESSION['error_message'] = 'Error deleting record: ' . safe_error($e);
                }
            }
            header('Location: documents.php');
            exit;
        }

        elseif ($_POST['action'] === 'delete_all_documents') {
            try {
                $stmt = $conn->query("SELECT * FROM documents");
                $allDocs = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($allDocs as $doc) {
                    $filePath = dirname(__DIR__) . '/uploads/' . $doc['owner_folder_path'] . '/' . $doc['stored_name'];
                    if (file_exists($filePath)) {
                        @unlink($filePath);
                    }
                }

                $conn->query("DELETE FROM documents");
                $_SESSION['success_message'] = 'All documents and permits have been cleared from Supabase.';
            } catch (Exception $e) {
                $_SESSION['error_message'] = 'Error clearing documents: ' . safe_error($e);
            }
            header('Location: documents.php');
            exit;
        }

        elseif ($_POST['action'] === 'issue_permit') {
            $rentalId = (int)($_POST['rental_id'] ?? 0);
            $permitType = trim($_POST['permit_type'] ?? 'Business Permit');
            $customPermitType = trim($_POST['custom_permit_type'] ?? '');
            $validUntil = trim($_POST['valid_until'] ?? date('Y-m-d', strtotime('+1 year')));
            $notes = trim($_POST['permit_notes'] ?? 'Issued upon verified payment approval.');

            if ($rentalId <= 0) {
                $_SESSION['error_message'] = 'Please select a valid rental property.';
                header('Location: documents.php');
                exit;
            }

            try {
                $rStmt = $conn->prepare("
                    SELECT r.id, r.tracking_no, r.business_name, r.rental_name, r.user_id, u.fullname as owner_name 
                    FROM rentals r 
                    LEFT JOIN users u ON r.user_id = u.id 
                    WHERE r.id = :rid
                ");
                $rStmt->execute([':rid' => $rentalId]);
                $rentalInfo = $rStmt->fetch(PDO::FETCH_ASSOC);

                if (!$rentalInfo || empty($rentalInfo['user_id'])) {
                    $_SESSION['error_message'] = 'Selected rental property or owner account was not found.';
                    header('Location: documents.php');
                    exit;
                }

                $bizName = $rentalInfo['business_name'] ?: $rentalInfo['rental_name'];
                $ownerId = (int)$rentalInfo['user_id'];
                $trackingNo = $rentalInfo['tracking_no'] ?? 'TRK-' . $rentalId;
                $folderPath = 'user_' . $ownerId;

                $displayPermitType = ($permitType === 'Others' && !empty($customPermitType)) ? $customPermitType : $permitType;

                $rootUploadDir = dirname(__DIR__) . '/uploads';
                $ownerUploadDir = $rootUploadDir . '/' . $folderPath;
                if (!is_dir($ownerUploadDir)) {
                    @mkdir($ownerUploadDir, 0755, true);
                }

                $permitTitle = $displayPermitType . ' - ' . $bizName;
                $storedName = 'permit_' . uniqid('', true) . '.html';
                $targetPath = $ownerUploadDir . '/' . $storedName;

                $securityHash = hash('sha256', $rentalId . $bizName . time());
                $issueDate = date('F d, Y');
                $expiryDateFormatted = date('F d, Y', strtotime($validUntil));

                $prefixMap = getPermitPrefixMap();
                $permitPrefix = $prefixMap[$permitType] ?? 'PERMIT';
                $year = date('Y');

                $permitSeqStmt = $conn->prepare("
                    SELECT COUNT(*) + 1 
                    FROM documents 
                    WHERE business_permit_no LIKE :prefix
                      AND EXTRACT(YEAR FROM uploaded_at) = :yr
                ");
                $permitSeqStmt->execute([
                    ':prefix' => $permitPrefix . '-' . $year . '-%',
                    ':yr'     => $year
                ]);
                $seq = (int)$permitSeqStmt->fetchColumn();
                $generatedPermitNo = $permitPrefix . '-' . $year . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);

                $permitHtml = buildPermitCertificateHtml([
                    'trackingNo'          => $trackingNo,
                    'bizName'             => $bizName,
                    'ownerName'           => $rentalInfo['owner_name'] ?? 'N/A',
                    'permitType'          => $permitType,
                    'customPermitType'    => $customPermitType,
                    'issueDate'           => $issueDate,
                    'expiryDateFormatted' => $expiryDateFormatted,
                    'notes'               => $notes,
                    'securityHash'        => $securityHash,
                    'staffId'             => $staffId,
                    'logo'                => 'assets/images/images.jpg',
                    'isPreview'           => false,
                    'permitNo'            => $generatedPermitNo,
                ]);

                file_put_contents($targetPath, $permitHtml);

                $insStmt = $conn->prepare("
                    INSERT INTO documents (
                        user_id, rental_id, title, type, notes, file_name, stored_name, owner_folder_path,
                        business_permit_no, permit_expiry_date, permit_status, uploaded_at
                    )
                    VALUES (
                        :uid, :rid, :title, :type, :notes, :fname, :sname, :fpath,
                        :bpno, :pexp, :pstat, NOW()
                    )
                ");
                $insStmt->execute([
                    ':uid'   => $ownerId,
                    ':rid'   => $rentalId,
                    ':title' => $permitTitle,
                    ':type'  => 'Permit',
                    ':notes' => 'Valid until: ' . $validUntil . '. Official HTML Certificate.',
                    ':fname' => $displayPermitType . '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $bizName) . '.html',
                    ':sname' => $storedName,
                    ':fpath' => $folderPath,
                    ':bpno'  => $generatedPermitNo,
                    ':pexp'  => $validUntil,
                    ':pstat' => 'Active'
                ]);

                if (tableExists($conn, 'notifications') && $ownerId) {
                    try {
                        $notifIns = $conn->prepare("
                            INSERT INTO notifications (user_id, title, message, notification_type, is_read, status, created_at)
                            VALUES (:uid, :title, :msg, 'Permit', 0, 'Unread', NOW())
                        ");
                        $notifIns->execute([
                            ':uid' => $ownerId,
                            ':title' => 'Official ' . $displayPermitType . ' Issued',
                            ':msg' => "Your official " . $displayPermitType . " certificate for '" . $bizName . "' (Permit No: " . $generatedPermitNo . ") has been successfully issued."
                        ]);
                    } catch (Exception $ex) {}
                }

                $_SESSION['success_message'] = 'Official ' . $displayPermitType . ' certificate (Permit No: ' . $generatedPermitNo . ') successfully generated and saved!';
                header('Location: documents.php');
                exit;

            } catch (Exception $e) {
                $_SESSION['error_message'] = 'Database Error: ' . safe_error($e);
                header('Location: documents.php');
                exit;
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['document_file'])) {
    if (
        !isset($_POST['csrf_token']) ||
        !isset($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $_SESSION['error_message'] = 'Invalid session token. Please refresh the page and try again.';
        header('Location: documents.php');
        exit;
    } else {
        $uploadRentalId = (int)($_POST['upload_rental_id'] ?? 0);
        $title = trim($_POST['document_title'] ?? '');
        $documentType = trim($_POST['document_type'] ?? 'Other');
        $notes = trim($_POST['notes'] ?? '');

        if ($uploadRentalId <= 0) {
            $_SESSION['error_message'] = 'Please select a verified rental property for this document upload.';
            header('Location: documents.php');
            exit;
        } elseif (!isset($_FILES['document_file']['error']) || $_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['error_message'] = 'Please choose a valid file to upload.';
            header('Location: documents.php');
            exit;
        } else {
            $ownerQuery = $conn->prepare("SELECT user_id FROM rentals WHERE id = :rid");
            $ownerQuery->execute([':rid' => $uploadRentalId]);
            $targetOwnerId = (int)$ownerQuery->fetchColumn();

            if ($targetOwnerId <= 0) {
                $_SESSION['error_message'] = 'Associated owner account for this rental was not found.';
                header('Location: documents.php');
                exit;
            } else {
                $tmpName = $_FILES['document_file']['tmp_name'];
                $originalName = basename($_FILES['document_file']['name']);
                $size = (int)$_FILES['document_file']['size'];
                $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                $allowedExt = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'xlsx', 'zip', 'txt'];

                if ($size <= 0 || $size > 5 * 1024 * 1024) {
                    $_SESSION['error_message'] = 'File size must be between 1KB and 5MB.';
                    header('Location: documents.php');
                    exit;
                } elseif (!in_array($ext, $allowedExt, true) || !upload_content_ok($tmpName, $ext)) {
                    $_SESSION['error_message'] = 'Unsupported file type. Allowed: PDF, DOC, DOCX, JPG, PNG, XLSX, ZIP, TXT.';
                    header('Location: documents.php');
                    exit;
                } else {
                    $folderPath = 'user_' . $targetOwnerId;
                    $uploadDir = dirname(__DIR__) . '/uploads/' . $folderPath;
                    if (!is_dir($uploadDir)) {
                        @mkdir($uploadDir, 0755, true);
                    }

                    $storedName = 'doc_' . uniqid('', true) . '.' . $ext;
                    $targetPath = $uploadDir . '/' . $storedName;

                    if (move_uploaded_file($tmpName, $targetPath)) {
                        $docTitle = ($title !== '') ? $title : pathinfo($originalName, PATHINFO_FILENAME);
                        $docType = ($documentType !== '') ? $documentType : 'Other';

                        $permitPrefixMap = [
                            'Business Permit' => 'BP', 'Sanitary' => 'SP', 'Fire Safety' => 'FSIC',
                            'Barangay' => 'BC', 'Health' => 'HC', 'Lease Contract' => 'LC',
                            'Lease Clearance' => 'LCC', 'Occupancy' => 'OCP',
                        ];

                        $prefix = 'PERMIT';
                        foreach ($permitPrefixMap as $keyword => $p) {
                            if (stripos($docTitle, $keyword) !== false || stripos($docType, $keyword) !== false) {
                                $prefix = $p;
                                break;
                            }
                        }

                        $businessPermitNo = null;
                        $permitExpiryDate = null;
                        $permitStatus = null;

                        $isPermitDoc = (
                            stripos($docType, 'permit') !== false 
                            || stripos($docTitle, 'permit') !== false
                            || stripos($docTitle, 'clearance') !== false
                            || stripos($docTitle, 'certificate') !== false
                            || stripos($docTitle, 'contract') !== false
                        );

                        if ($isPermitDoc) {
                            $year = date('Y');
                            $permitSeqStmt = $conn->prepare("
                                SELECT COUNT(*) + 1 FROM documents 
                                WHERE business_permit_no LIKE :prefix
                                  AND EXTRACT(YEAR FROM uploaded_at) = :yr
                            ");
                            $permitSeqStmt->execute([':prefix' => $prefix . '-' . $year . '-%', ':yr' => $year]);
                            $seq = (int)$permitSeqStmt->fetchColumn();

                            $businessPermitNo = $prefix . '-' . $year . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
                            $permitExpiryDate = date('Y-m-d', strtotime('+1 year'));
                            $permitStatus = 'Active';
                        }

                        $insStmt = $conn->prepare("
                            INSERT INTO documents (
                                user_id, rental_id, title, type, notes, file_name, stored_name, owner_folder_path,
                                business_permit_no, permit_expiry_date, permit_status, uploaded_at
                            )
                            VALUES (
                                :uid, :rid, :title, :type, :notes, :fname, :sname, :fpath,
                                :bpno, :pexp, :pstat, NOW()
                            )
                        ");
                        $insStmt->execute([
                            ':uid'   => $targetOwnerId,
                            ':rid'   => $uploadRentalId,
                            ':title' => $docTitle,
                            ':type'  => $docType,
                            ':notes' => $notes,
                            ':fname' => $originalName,
                            ':sname' => $storedName,
                            ':fpath' => $folderPath,
                            ':bpno'  => $businessPermitNo,
                            ':pexp'  => $permitExpiryDate,
                            ':pstat' => $permitStatus
                        ]);

                        $_SESSION['success_message'] = 'Document uploaded successfully' . ($businessPermitNo ? ' (Permit No: ' . $businessPermitNo . ')' : '') . ' and saved to Owner Vault.';
                        header('Location: documents.php');
                        exit;
                    } else {
                        $_SESSION['error_message'] = 'Failed to save the uploaded file.';
                        header('Location: documents.php');
                        exit;
                    }
                }
            }
        }
    }
}

if (isset($_SESSION['success_message'])) {
    $successMessage = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $errorMessage = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// ✅ UPDATED: Status filter para gumana ang ?status=Expired mula sa dashboard
$statusFilter = trim($_GET['status'] ?? '');

$documents = [];
try {
    if ($statusFilter === 'Expired') {
        // Expired permits lang (permit_expiry_date < today)
        $docStmt = $conn->query("
            SELECT * FROM documents 
            WHERE permit_expiry_date IS NOT NULL 
              AND permit_expiry_date < CURRENT_DATE
            ORDER BY permit_expiry_date ASC
        ");
    } elseif ($statusFilter === 'Expiring') {
        // Malapit nang ma-expire (within 30 days)
        $docStmt = $conn->query("
            SELECT * FROM documents 
            WHERE permit_expiry_date IS NOT NULL 
              AND permit_expiry_date >= CURRENT_DATE
              AND permit_expiry_date <= CURRENT_DATE + INTERVAL '30 days'
            ORDER BY permit_expiry_date ASC
        ");
    } else {
        // Default: lahat ng documents
        $docStmt = $conn->query("SELECT * FROM documents ORDER BY uploaded_at DESC");
    }
    $documents = $docStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Documents fetch error: " . $e->getMessage());
    $documents = [];
}

$verifiedRentals = [];
try {
    $vrStmt = $conn->query("
        SELECT DISTINCT r.id, r.tracking_no, r.business_name, r.rental_name, u.fullname as owner_name 
        FROM rentals r
        JOIN payments p ON p.rental_id = r.id
        LEFT JOIN users u ON r.user_id = u.id
        WHERE p.status IN ('Verified', 'Paid', 'Approved') AND r.is_deleted = FALSE
        ORDER BY r.business_name ASC
    ");
    $verifiedRentals = $vrStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    try {
        $vrStmt = $conn->query("SELECT id, tracking_no, business_name, rental_name FROM rentals WHERE is_deleted = FALSE LIMIT 20");
        $verifiedRentals = $vrStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $ex) {}
}

$totalDocumentsCount = count($documents);
$issuedPermitsCount = 0;
foreach ($documents as $doc) {
    if (($doc['type'] ?? '') === 'Permit') {
        $issuedPermitsCount++;
    }
}
$verifiedRentalsCount = count($verifiedRentals);
$activeTabOnLoad = ($successMessage !== '') ? 'tab-records' : 'tab-issue';

// ✅ UPDATED: Kung naka-filter, force open ang Records tab
if (!empty($statusFilter)) {
    $activeTabOnLoad = 'tab-records';
}

// Calculate Expiring/Expired Stats for Staff Alert Banner
$expiredDocsCount = 0;
$expiringDocsCount = 0;

foreach ($documents as &$doc) {
    $doc['isExpired'] = false;
    $doc['daysLeft'] = null;
    
    if (!empty($doc['permit_expiry_date'])) {
        $today = new DateTime(date('Y-m-d'));
        $expiry = new DateTime(date('Y-m-d', strtotime($doc['permit_expiry_date'])));
        $interval = $today->diff($expiry);
        $days = (int)$interval->format('%r%a');
        $doc['daysLeft'] = $days;

        if ($days < 0) {
            $doc['isExpired'] = true;
            $expiredDocsCount++;
        } elseif ($days <= 30) {
            $expiringDocsCount++;
        }
    }
}
unset($doc);

$permitTypeOptions = [
    'Business Permit'                     => "Business / Mayor's Permit (Santiago)",
    'Sanitary Permit'                     => 'Sanitary Permit',
    'Fire Safety Inspection Certificate'  => 'Fire Safety Inspection Certificate (FSIC)',
    'Barangay Clearance'                  => 'Barangay Clearance (for Business)',
    'Health Certificates'                 => 'Health Certificates (Staff/Boarding House)',
    'Lease Contract'                      => 'Lease Contract / Rental Agreement',
    'Lease Clearance Certificate'         => 'Lease Clearance Certificate',
    'Occupancy Compliance Permit'         => 'Occupancy Compliance Permit',
    'Others'                              => 'Others (Specify Custom Permit Type)',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#dc2626">
    <title>Documents &amp; Permits | RENTRACK LGU</title>

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
            --accent-cyan: #0d9488;

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
            padding-left: var(--safe-left);
            padding-right: var(--safe-right);
        }

        /* OFFICIAL HEADER */
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
            flex-shrink: 0; text-decoration: none;
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

        /* ============================================
           CONTENT
        ============================================ */
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
        .alert.success { background: var(--ok-bg); color: #065f46; border-color: var(--ok-bd); }
        .alert.success i { color: var(--ok); }
        .alert.danger { background: var(--danger-bg); color: #991b1b; border-color: var(--danger-bd); }
        .alert.danger i { color: var(--danger); }

        /* Alert Banners - Minimalist */
        .alert-banner { padding: 16px 20px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: flex-start; gap: 12px; border: 1px solid #e2e8f0; background: #ffffff; color: #334155; }
        .alert-banner i { font-size: 20px; margin-top: 2px; flex-shrink: 0; color: #64748b; }
        .alert-banner h4 { margin: 0 0 4px 0; font-size: 14px; font-weight: 700; color: #0f172a; }
        .alert-banner p { margin: 0; font-size: 12.5px; line-height: 1.5; color: #475569; }

        /* ✅ UPDATED: STATS STRIP - 4 columns on desktop */
        .stats-strip { display: grid; grid-template-columns: 1fr; gap: 12px; margin-bottom: 20px; }
        @media(min-width: 576px) { .stats-strip { grid-template-columns: repeat(2, 1fr); gap: 12px; } }
        @media(min-width: 992px) { .stats-strip { grid-template-columns: repeat(4, 1fr); gap: 16px; } }

        .stat-chip {
            position: relative;
            display: flex; align-items: center; gap: 14px;
            border-radius: 18px; padding: 18px 20px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid #e2e8f0;
            background: #ffffff;
        }
        .stat-chip:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .stat-chip .stat-icon {
            width: 46px; height: 46px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; flex-shrink: 0; color: #64748b;
            background: #f1f5f9;
        }
        .stat-chip .stat-num { font-size: 24px; font-weight: 800; color: #0f172a; line-height: 1.1; }
        .stat-chip .stat-lbl { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 0.8px; margin-top: 3px; font-weight: 700; }

        /* TABS */
        .tabs-wrapper {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: var(--radius);
            box-shadow: var(--sh-sm);
            overflow: hidden;
            width: 100%;
        }
        .tabs-nav {
            display: flex;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            background: #1e293b;
            scrollbar-width: none;
        }
        .tabs-nav::-webkit-scrollbar { display: none; }

        .tab-btn {
            flex: 0 0 auto;
            min-width: 130px;
            display: flex; align-items: center; justify-content: center; gap: 7px;
            padding: 14px 16px;
            background: transparent; border: none;
            color: #ffffff !important;
            font-size: 12.5px; font-weight: 700;
            cursor: pointer;
            border-bottom: 4px solid transparent;
            transition: all .2s ease;
            white-space: nowrap;
            text-shadow: 0 1px 2px rgba(0,0,0,0.25);
        }
        @media(min-width: 768px) { .tab-btn { flex: 1; min-width: 170px; padding: 16px 18px; font-size: 13.5px; } }
        .tab-btn i { color: #ffffff !important; }
        .tab-btn .tab-count {
            background: rgba(255,255,255,0.3);
            color: #ffffff;
            padding: 1px 7px; border-radius: 20px;
            font-size: 10.5px; font-weight: 700;
        }
        .tab-btn[data-tab="tab-issue"]   { background: linear-gradient(135deg, #f43f5e, #be123c); }
        .tab-btn[data-tab="tab-upload"]  { background: linear-gradient(135deg, #fbbf24, #d97706); }
        .tab-btn[data-tab="tab-records"] { background: linear-gradient(135deg, #2dd4bf, #0d9488); }
        .tab-btn:hover { filter: brightness(1.08); color: #ffffff; }
        .tab-btn:not(.active) { filter: saturate(.85) brightness(.92); }
        .tab-btn:not(.active):hover { filter: brightness(1.08); }
        .tab-btn.active {
            color: #ffffff !important;
            border-bottom-color: #ffffff;
            box-shadow: inset 0 -3px 12px rgba(0,0,0,0.18);
        }
        .tab-btn.active .tab-count { background: #ffffff; color: #0f172a; }
        .tab-btn[data-tab="tab-issue"].active   { background: linear-gradient(135deg, #dc2626, #991b1b); }
        .tab-btn[data-tab="tab-upload"].active  { background: linear-gradient(135deg, #f59e0b, #b45309); }
        .tab-btn[data-tab="tab-records"].active { background: linear-gradient(135deg, #14b8a6, #0f766e); }

        .tab-panel { display: none; padding: 16px; background: #ffffff; }
        @media(min-width: 768px) { .tab-panel { padding: 24px 28px; } }
        .tab-panel.active { display: block; animation: fadeInTab .25s ease; }
        @keyframes fadeInTab { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }
        .tab-intro { font-size: 12.5px; color: #475569; margin-bottom: 16px; line-height: 1.5; }
        .tab-intro strong { color: #0f172a; }

        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-size: 12.5px; font-weight: 500; color: #475569; margin-bottom: 5px; }
        .form-group label .required { color: #dc2626; margin-left: 2px; }
        .form-control, .form-select {
            width: 100%; padding: 9px 12px; border-radius: 10px;
            background: #ffffff; border: 1px solid #e2e8f0;
            color: #0f172a; font-size: 13.5px; outline: none;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-red);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }
        .form-select option { background: #ffffff; color: #0f172a; }

        .btn-primary {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            width: 100%; padding: 10px 20px; border-radius: 10px;
            background: var(--primary-red); color: #fff;
            font-weight: 600; font-size: 13.5px;
            border: none; cursor: pointer; transition: all 0.3s;
            font-family: inherit;
        }
        @media(min-width: 576px) { .btn-primary { width: auto; } }
        .btn-primary:hover { background: #991b1b; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(220, 38, 38, 0.2); }
        .btn-primary:disabled { opacity: 0.7; cursor: wait; }

        .btn-secondary {
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            padding: 6px 12px; border-radius: 8px;
            background: #f8fafc; border: 1px solid #e2e8f0;
            color: #334155; font-size: 11.5px; font-weight: 500;
            text-decoration: none; cursor: pointer;
            font-family: inherit;
        }
        .btn-secondary:hover { background: #f1f5f9; color: #0f172a; border-color: #cbd5e1; }

        /* TABLE - Normal Text */
        .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .table { width: 100%; border-collapse: collapse; font-size: 13px; background: transparent !important; white-space: nowrap; }
        .table thead th {
            text-align: left; padding: 10px 10px;
            color: #64748b !important; background: #f8fafc !important;
            font-weight: 600; border-bottom: 1px solid #e2e8f0;
            font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;
        }
        .table tbody td {
            padding: 12px 10px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            color: #0f172a !important;
            background: #ffffff !important;
        }
        .table tbody tr { background: #ffffff !important; }
        .table tbody tr:hover { background: #f8fafc !important; }

        /* Remove dynamic row highlights for normal view */
        .table tbody tr.row-expired { background-color: #ffffff !important; }
        .table tbody tr.row-expiring { background-color: #ffffff !important; }
        .table tbody tr.row-expired:hover { background-color: #f8fafc !important; }
        .table tbody tr.row-expiring:hover { background-color: #f8fafc !important; }

        /* Normal Badges */
        .table .badge, .badge {
            background: #f1f5f9 !important;
            color: #334155 !important;
            border: 1px solid #e2e8f0 !important;
            padding: 3px 10px; border-radius: 30px;
            font-size: 10px; font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }

        .empty-state { text-align: center; padding: 40px 15px; color: #64748b; }
        .empty-state i { font-size: 40px; display: block; margin-bottom: 12px; opacity: 0.3; }

        /* Mobile card table */
        @media (max-width: 767px) {
            .table-responsive { overflow-x: visible; }
            .table.records-table { white-space: normal; display: block; width: 100%; }
            .records-table thead { display: none; }
            .records-table tbody { display: block; }
            .records-table tbody tr {
                display: block;
                background: #ffffff !important;
                border: 1px solid #e2e8f0;
                border-left: 3px solid #cbd5e1;
                border-radius: 12px;
                padding: 6px 14px 10px;
                margin-bottom: 14px;
                box-shadow: 0 1px 3px rgba(15, 23, 42, 0.05);
            }
            .records-table tbody tr:hover { background: #ffffff !important; }
            .records-table tbody tr.row-expired { border-left-color: #cbd5e1; background: #ffffff !important; }
            .records-table tbody tr.row-expiring { border-left-color: #cbd5e1; background: #ffffff !important; }
            
            .records-table tbody td {
                display: block;
                position: relative;
                padding: 9px 0 9px 42% !important;
                min-height: 36px;
                border-bottom: 1px dashed #e2e8f0;
                text-align: right;
                word-break: break-word;
                overflow-wrap: anywhere;
            }
            .records-table tbody td::before {
                content: attr(data-label);
                position: absolute;
                left: 0; top: 11px;
                width: 38%;
                text-align: left;
                font-size: 10.5px; font-weight: 700;
                color: #64748b;
                text-transform: uppercase;
                letter-spacing: .5px;
            }
            .records-table tbody td:first-child {
                display: block;
                text-align: left;
                font-size: 14.5px;
                padding: 10px 0 8px 0 !important;
            }
            .records-table tbody td:first-child::before { display: none; }
            .records-table tbody td:last-child { border-bottom: none; padding-bottom: 2px !important; }
            .records-table tbody td:last-child > div { justify-content: flex-end; }
        }

        /* FOOTER */
        .footer {
            margin-top: 24px; padding-top: 16px;
            border-top: 1px solid #e2e8f0;
            display: flex; justify-content: space-between; align-items: center;
            font-size: 12px; color: #64748b;
            flex-wrap: wrap; gap: 10px;
        }
        .footer .brand { color: var(--primary-red); font-weight: 700; }

        .modal-content {
            border-radius: var(--r-xl) !important;
            border: 1px solid #e2e8f0 !important;
            background: #ffffff !important;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
        }
        @media (max-width: 576px) {
            .modal-dialog { margin: 8px; max-width: calc(100% - 16px); }
        }
        .modal-header { border-bottom: 1px solid #f1f5f9 !important; padding: 16px 22px; }
        .modal-footer { border-top: 1px solid #f1f5f9 !important; }
        .modal-title { font-size: 14.5px !important; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 9px; }
        .modal-title i { color: var(--primary-red); font-size: 14px; }

        /* DOC PREVIEW OVERLAY */
        .doc-preview-overlay {
            position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px); z-index: 9999;
            display: flex; align-items: center; justify-content: center;
            padding: 5vh 3vw;
            opacity: 0; visibility: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .doc-preview-overlay.active { opacity: 1; visibility: visible; }
        .doc-preview-content {
            width: 100%; max-width: 900px; height: 88vh;
            background: #ffffff; border: 1px solid #e2e8f0;
            border-radius: 20px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            display: flex; flex-direction: column;
            transform: translateY(-16px) scale(0.98);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .doc-preview-overlay.active .doc-preview-content { transform: translateY(0) scale(1); }
        .doc-preview-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 20px;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc; gap: 12px;
            flex-wrap: wrap;
        }
        .doc-preview-header strong { font-size: 14px; color: #0f172a; }
        .doc-preview-header .doc-preview-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .doc-preview-close {
            background: #f1f5f9; border: 1px solid #cbd5e1;
            color: #64748b;
            width: 30px; height: 30px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .doc-preview-close:hover { background: #fee2e2; color: #ef4444; border-color: #fca5a5; }
        .doc-preview-body {
            flex: 1; background: #f8fafc;
            display: flex; align-items: center; justify-content: center;
            overflow: auto; padding: 20px;
        }
        .doc-preview-body iframe { width: 100%; height: 100%; border: 0; background: #fff; }
        .doc-preview-body img { max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }

        @media print {
            body * { visibility: hidden; }
            #docPreviewOverlay, #docPreviewOverlay * { visibility: visible; }
            #docPreviewOverlay { position: absolute; left: 0; top: 0; width: 100%; height: 100%; background: #fff; padding: 0; }
            .doc-preview-header, .no-print { display: none !important; }
            .doc-preview-content { border: none; box-shadow: none; height: 100%; max-width: 100%; }
        }

        /* ============================================
           RESPONSIVE
        ============================================ */
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

            .tabs-wrapper { border-radius: 16px; }
            .tab-panel { padding: 16px; }

            .footer { flex-direction: column; text-align: center; gap: 6px; font-size: 10.5px; }

            .modal-dialog { margin: 0; min-height: 100vh; min-height: 100dvh; align-items: stretch; }
            .modal-content { border-radius: 0 !important; min-height: 100vh; min-height: 100dvh; }
            .modal-header { padding-top: calc(1rem + var(--safe-top)); }
            .modal-footer { padding-bottom: calc(1rem + var(--safe-bottom)); }
        }

        @media (max-width: 480px) {
            .official-header { padding: 5px 8px; padding-top: calc(5px + var(--safe-top)); }
            .top-navbar { padding: 8px 10px; }
            .content { padding: 12px 10px 22px; padding-bottom: calc(22px + var(--safe-bottom)); }
            .page-header { padding: 14px 16px; border-radius: 12px; }
            .page-header h1 { font-size: 16px; }
            .page-header p { font-size: 11.5px; }
            .date-badge { padding: 5px 10px; font-size: 11px; }
            .footer { font-size: 10.5px; }
            .doc-preview-header { padding: 10px 12px; gap: 8px; }
        }

        @media (max-width: 767px) {
            input, select, textarea { font-size: 16px !important; }
            .search-container input,
            .form-control,
            .form-select { font-size: 16px !important; }
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
                                <button type="button" class="nav-link active" data-dropdown>
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
                            <input type="text" id="globalSearchInput" placeholder="Search tracking no, business, owner..." readonly style="cursor: pointer;">
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
                    <strong>Documents &amp; Permits</strong>
                </div>

                <div class="page-header">
                    <div>
                        <h1><i class="fa-solid fa-file-lines"></i> Documents &amp; Permits</h1>
                        <p>Issue official business permits, lease clearances, and occupancy compliance certificates, and manage supporting files.</p>
                    </div>
                    <div class="date-badge"><i class="fa-regular fa-calendar"></i> <?php echo date("F d, Y"); ?></div>
                </div>

                <?php if ($successMessage !== ''): ?>
                    <div class="alert success"><i class="fa-regular fa-circle-check"></i> <?php echo htmlspecialchars($successMessage); ?></div>
                <?php endif; ?>
                <?php if ($errorMessage !== ''): ?>
                    <div class="alert danger"><i class="fa-regular fa-circle-xmark"></i> <?php echo htmlspecialchars($errorMessage); ?></div>
                <?php endif; ?>

                <!-- ALERT BANNERS (STAFF SIDE) - Minimalist Style -->
                <?php if ($expiredDocsCount > 0): ?>
                    <div class="alert-banner">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <div>
                            <h4>Mayroong <?php echo $expiredDocsCount; ?> Expired na Permit sa System</h4>
                            <p>Ang mga sumusunod na permit ay lampas na sa validity date. Inirerekomenda na i-remind ang mga owners na mag-renew para maiwasan ang multa at abala.</p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($expiringDocsCount > 0): ?>
                    <div class="alert-banner">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <div>
                            <h4>Mayroong <?php echo $expiringDocsCount; ?> Permit na malapit nang ma-expire</h4>
                            <p>Ang mga permit na ito ay ma-expire sa loob ng 30 araw. Maaaring mag-send ng notification sa owners para paalalahanan silang mag-renew.</p>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- ✅ UPDATED: STATS STRIP - May clickable Expired chip -->
                <div class="stats-strip">
                    <div class="stat-chip">
                        <div class="stat-icon"><i class="fa-solid fa-file-shield"></i></div>
                        <div>
                            <div class="stat-num"><?php echo $issuedPermitsCount; ?></div>
                            <div class="stat-lbl">Permits Issued</div>
                        </div>
                    </div>
                    <div class="stat-chip">
                        <div class="stat-icon"><i class="fa-solid fa-folder-open"></i></div>
                        <div>
                            <div class="stat-num"><?php echo $totalDocumentsCount; ?></div>
                            <div class="stat-lbl">Total Documents</div>
                        </div>
                    </div>
                    <div class="stat-chip">
                        <div class="stat-icon"><i class="fa-solid fa-building-shield"></i></div>
                        <div>
                            <div class="stat-num"><?php echo $verifiedRentalsCount; ?></div>
                            <div class="stat-lbl">Verified Rentals</div>
                        </div>
                    </div>
                    <!-- ✅ BAGO: Clickable expired stat -->
                    <a href="documents.php?status=Expired" class="stat-chip" style="text-decoration: none; <?php echo $expiredDocsCount > 0 ? 'border-color: #fecaca; background: #fef2f2;' : ''; ?>">
                        <div class="stat-icon" style="<?php echo $expiredDocsCount > 0 ? 'background: #fee2e2; color: #dc2626;' : ''; ?>">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                        </div>
                        <div>
                            <div class="stat-num" style="<?php echo $expiredDocsCount > 0 ? 'color: #dc2626;' : ''; ?>"><?php echo $expiredDocsCount; ?></div>
                            <div class="stat-lbl">Expired Permits</div>
                        </div>
                    </a>
                </div>

                <div class="tabs-wrapper">
                    <div class="tabs-nav" role="tablist">
                        <button type="button" class="tab-btn<?php echo $activeTabOnLoad === 'tab-issue' ? ' active' : ''; ?>" data-tab="tab-issue">
                            <i class="fa-solid fa-stamp"></i> Issue Permit
                        </button>
                        <button type="button" class="tab-btn" data-tab="tab-upload">
                            <i class="fa-solid fa-upload"></i> Upload
                        </button>
                        <button type="button" class="tab-btn<?php echo $activeTabOnLoad === 'tab-records' ? ' active' : ''; ?>" data-tab="tab-records">
                            <i class="fa-solid fa-list"></i> Records
                            <span class="tab-count"><?php echo $totalDocumentsCount; ?></span>
                        </button>
                    </div>

                    <div class="tab-panel<?php echo $activeTabOnLoad === 'tab-issue' ? ' active' : ''; ?>" id="tab-issue">
                        <p class="tab-intro">
                            Select a verified rental and permit classification, then <strong>preview the exact certificate</strong> before finalizing.
                        </p>

                        <form action="documents.php" method="POST" id="issuePermitForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <input type="hidden" name="action" value="issue_permit">

                            <div class="row">
                                <div class="col-12 col-md-6 form-group">
                                    <label for="rental_id">Verified Rental / Business <span class="required">*</span></label>
                                    <select id="rental_id" name="rental_id" class="form-select" required>
                                        <option value="">-- Select Verified Rental --</option>
                                        <?php if (!empty($verifiedRentals)): ?>
                                            <?php foreach ($verifiedRentals as $vr): ?>
                                                <option value="<?php echo (int)$vr['id']; ?>">
                                                    <?php echo htmlspecialchars(($vr['business_name'] ?: $vr['rental_name']) . ' (' . $vr['tracking_no'] . ')'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <option value="" disabled>No verified rentals found with payments yet</option>
                                        <?php endif; ?>
                                    </select>
                                </div>

                                <div class="col-12 col-md-6 form-group">
                                    <label for="permit_type">Permit Classification <span class="required">*</span></label>
                                    <select id="permit_type" name="permit_type" class="form-select" required>
                                        <?php foreach ($permitTypeOptions as $typeKey => $typeLabel): ?>
                                            <option value="<?php echo htmlspecialchars($typeKey); ?>"><?php echo htmlspecialchars($typeLabel); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small id="permitTypeHint" style="color:#64748b;font-size:11px;margin-top:4px;display:block;"></small>
                                </div>
                            </div>

                            <div class="row" id="customPermitRow" style="display: none;">
                                <div class="col-12 form-group">
                                    <label for="custom_permit_type">Specify Custom Permit Type <span class="required">*</span></label>
                                    <input type="text" id="custom_permit_type" name="custom_permit_type" class="form-control" placeholder="e.g. Temporary Road Closure Permit, Special Event Permit, etc.">
                                    <small style="color:#64748b;font-size:11px;margin-top:4px;display:block;">Ilagay ang eksaktong pangalan ng permit na gusto mong i-issue.</small>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-12 col-md-6 form-group">
                                    <label for="valid_until">Validity Expiration Date <span class="required">*</span></label>
                                    <input type="date" id="valid_until" name="valid_until" class="form-control" value="<?php echo date('Y-m-d', strtotime('+1 year')); ?>" required>
                                </div>

                                <div class="col-12 col-md-6 form-group">
                                    <label for="permit_notes">Official Remarks / Conditions</label>
                                    <input type="text" id="permit_notes" name="permit_notes" class="form-control" placeholder="e.g., Verified full payment">
                                </div>
                            </div>

                            <div class="form-group" style="margin-bottom:0; margin-top: 10px;">
                                <button type="button" id="previewPermitBtn" class="btn-primary">
                                    <i class="fa-solid fa-eye"></i> Preview Permit Before Issuing
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="tab-panel" id="tab-upload">
                        <p class="tab-intro">Attach a supporting file to this rental's record &mdash; clearances, tax receipts, or inspection reports (direktang mapupunta sa Owner vault).</p>

                        <form action="documents.php" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                            <div class="row">
                                <div class="col-12 form-group">
                                    <label for="upload_rental_id">Target Verified Rental / Owner <span class="required">*</span></label>
                                    <select id="upload_rental_id" name="upload_rental_id" class="form-select" required>
                                        <option value="">-- Select Rental / Owner --</option>
                                        <?php if (!empty($verifiedRentals)): ?>
                                            <?php foreach ($verifiedRentals as $vr): ?>
                                                <option value="<?php echo (int)$vr['id']; ?>">
                                                    <?php echo htmlspecialchars(($vr['business_name'] ?: $vr['rental_name']) . ' - Owner: ' . ($vr['owner_name'] ?? 'N/A') . ' (' . $vr['tracking_no'] . ')'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <option value="" disabled>No verified rentals found</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-12 col-md-6 form-group">
                                    <label for="document_title">Document Title <span class="required">*</span></label>
                                    <input type="text" id="document_title" name="document_title" class="form-control" placeholder="e.g. Barangay Clearance" required>
                                </div>
                                <div class="col-12 col-md-6 form-group">
                                    <label for="document_type">Document Type</label>
                                    <select id="document_type" name="document_type" class="form-select">
                                        <option value="Permit">Permit</option>
                                        <option value="Tax">Tax</option>
                                        <option value="Inspection">Inspection</option>
                                        <option value="Contract">Contract</option>
                                        <option value="Other" selected>Other</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="notes">Notes</label>
                                <textarea id="notes" name="notes" class="form-control" placeholder="Short notes..." rows="2"></textarea>
                            </div>
                            <div class="form-group">
                                <label for="document_file">Choose File <span class="required">*</span></label>
                                <input type="file" id="document_file" name="document_file" class="form-control" required>
                                <small style="color:#64748b;font-size:11px;margin-top:4px;display:block;">Max: 5MB. Allowed: PDF, DOC, JPG, PNG, HTML</small>
                            </div>
                            <button type="submit" id="uploadBtn" class="btn-primary"><i class="fa-solid fa-upload"></i> Upload Document to Owner</button>
                        </form>
                    </div>

                    <div class="tab-panel<?php echo $activeTabOnLoad === 'tab-records' ? ' active' : ''; ?>" id="tab-records">
                        <!-- ✅ UPDATED: Filter indicator banner -->
                        <?php if (!empty($statusFilter)): ?>
                            <div style="padding: 12px 16px; background: <?php echo $statusFilter === 'Expired' ? '#fef2f2' : '#fffbeb'; ?>; border: 1px solid <?php echo $statusFilter === 'Expired' ? '#fecaca' : '#fde68a'; ?>; border-radius: 10px; margin-bottom: 14px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                                <span style="font-size: 13px; color: <?php echo $statusFilter === 'Expired' ? '#991b1b' : '#92400e'; ?>; font-weight: 600;">
                                    <i class="fa-solid fa-filter"></i> 
                                    Showing <strong><?php echo htmlspecialchars($statusFilter); ?></strong> permits only 
                                    (<?php echo count($documents); ?> record<?php echo count($documents) == 1 ? '' : 's'; ?>)
                                </span>
                                <a href="documents.php" style="font-size: 12px; color: #dc2626; font-weight: 700; text-decoration: none; padding: 5px 12px; background: #ffffff; border: 1px solid #fecaca; border-radius: 6px; display: inline-flex; align-items: center; gap: 5px;">
                                    <i class="fa-solid fa-xmark"></i> Clear Filter
                                </a>
                            </div>
                        <?php endif; ?>

                        <?php if (empty($documents)): ?>
                            <div class="empty-state">
                                <i class="fa-solid fa-folder-open"></i>
                                <p><?php echo !empty($statusFilter) ? 'No ' . htmlspecialchars(strtolower($statusFilter)) . ' permits found.' : 'No documents or official permits issued yet.'; ?></p>
                            </div>
                        <?php else: ?>
                            <div style="display: flex; justify-content: flex-end; margin-bottom: 14px;">
                                <button type="button" class="btn-secondary" style="background: #f1f5f9; color: #64748b; border-color: #e2e8f0; width: 36px; height: 36px; padding: 0; justify-content: center; border-radius: 8px;" title="Delete All" onclick="confirmDeleteAllDocuments()">
                                    <i class="fa-solid fa-trash-can" style="font-size: 13px;"></i>
                                </button>
                            </div>
                            <div class="table-responsive">
                                <table class="table records-table">
                                    <thead>
                                        <tr>
                                            <th>Title</th>
                                            <th>Permit No.</th>
                                            <th>Type</th>
                                            <th>Date Issued</th>
                                            <th>Permit Expiry</th>
                                            <th>Remarks / Details</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($documents as $doc): 
                                            // Dynamic color coding for Expiry Status
                                            $expiryStatusColor = '';
                                            $expiryStatusLabel = '';
                                            $daysLeftText = '';
                                            $daysLeftColor = '';

                                            if (!empty($doc['permit_expiry_date'])) {
                                                if ($doc['isExpired']) {
                                                    $expiryStatusLabel = 'Expired';
                                                    $expiryStatusColor = '#dc2626'; // Red
                                                    $daysLeftText = 'Expired ' . abs($doc['daysLeft']) . ' day(s) ago';
                                                    $daysLeftColor = '#dc2626'; 
                                                } elseif ($doc['daysLeft'] <= 30) {
                                                    $expiryStatusLabel = 'Expiring Soon';
                                                    $expiryStatusColor = '#d97706'; // Amber
                                                    $daysLeftText = $doc['daysLeft'] . ' day(s) left';
                                                    $daysLeftColor = '#d97706';
                                                } else {
                                                    $expiryStatusLabel = 'Active';
                                                    $expiryStatusColor = '#15803d'; // Green
                                                    $daysLeftText = $doc['daysLeft'] . ' days left';
                                                    $daysLeftColor = '#15803d';
                                                }
                                            }
                                        ?>
                                            <tr>
                                                <td data-label="Title"><strong><?php echo htmlspecialchars($doc['title'] ?? 'Untitled'); ?></strong></td>
                                                <td data-label="Permit No.">
                                                    <?php if (!empty($doc['business_permit_no'])): 
                                                        $prefix = explode('-', $doc['business_permit_no'])[0];
                                                        $typeLabel = getPermitTypeLabelFromPrefix($prefix);
                                                    ?>
                                                        <span style="font-family: 'JetBrains Mono', monospace; font-weight: 600; color: #0f172a; font-size: 12px; display: inline-block;">
                                                            <?php echo htmlspecialchars($doc['business_permit_no']); ?>
                                                        </span>
                                                        <br><small style="font-size: 10px; color: #64748b;"><?php echo $typeLabel; ?></small>
                                                    <?php else: ?>
                                                        <span style="color:#94a3b8; font-style: italic; font-size: 11px;">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="Type">
                                                    <span class="badge">
                                                        <?php echo htmlspecialchars($doc['type'] ?? 'Other'); ?>
                                                    </span>
                                                </td>
                                                <td data-label="Date Issued" style="color:#64748b;font-size:12px;">
                                                    <?php echo date("M d, Y g:i A", strtotime($doc['uploaded_at'] ?? 'now')); ?>
                                                </td>
                                                <td data-label="Permit Expiry">
                                                    <?php if (!empty($doc['permit_expiry_date'])): ?>
                                                        <span style="font-size: 12px; font-weight: 500; color: #0f172a;">
                                                            <?php echo date('M d, Y', strtotime($doc['permit_expiry_date'])); ?>
                                                        </span>
                                                        <br>
                                                        <!-- Dynamic Badge Color -->
                                                        <span class="badge" style="color: <?php echo $expiryStatusColor; ?> !important; border-color: <?php echo $expiryStatusColor; ?> !important;">
                                                            <?php echo $expiryStatusLabel; ?>
                                                        </span>
                                                        <?php if ($daysLeftText): ?>
                                                            <!-- Dynamic Days Left Text Color -->
                                                            <br><small style="font-size: 10px; font-weight: 600; color: <?php echo $daysLeftColor; ?>;"><?php echo $daysLeftText; ?></small>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span style="color:#94a3b8; font-size:11px;">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="Remarks" style="color:#64748b;font-size:12px;">
                                                    <?php echo htmlspecialchars($doc['notes'] ?? '—'); ?>
                                                </td>
                                                <td data-label="Action">
                                                    <?php if (!empty($doc['stored_name'])): ?>
                                                        <?php 
                                                            $folderPath = $doc['owner_folder_path'] ?? ('user_' . $staffId); 
                                                            $docUrl = '../uploads/' . $folderPath . '/' . $doc['stored_name'];
                                                        ?>
                                                        <div style="display: flex; gap: 6px; align-items: center;">
                                                            <button type="button" class="btn-secondary view-doc-btn" style="width: 32px; height: 32px; padding: 0; justify-content: center; border-radius: 6px; background: #f1f5f9; border-color: #e2e8f0; color: #64748b; cursor: pointer;" title="View"
                                                                    data-title="<?php echo htmlspecialchars($doc['title'] ?? 'Document View'); ?>"
                                                                    data-url="<?php echo htmlspecialchars($docUrl); ?>">
                                                                <i class="fa-solid fa-eye" style="font-size: 12px;"></i>
                                                            </button>
                                                            <button type="button" class="btn-secondary" style="background: #f1f5f9; color: #64748b; border-color: #e2e8f0; width: 32px; height: 32px; padding: 0; justify-content: center; border-radius: 6px;" title="Delete" onclick="confirmDeleteDocument('<?php echo (int)$doc['id']; ?>')">
                                                                <i class="fa-solid fa-trash" style="font-size: 12px;"></i>
                                                            </button>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <footer class="footer">
                    <span>&copy; <?php echo date('Y'); ?> <span class="brand">RENTRACK LGU</span></span>
                    <span>Unified Rental Registration, Tracking &amp; Compliance System</span>
                </footer>
            </main>
        </div>
    </div>

    <form id="deleteDocForm" action="documents.php" method="POST" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
        <input type="hidden" name="action" value="delete_document">
        <input type="hidden" name="doc_id" id="delDocId">
    </form>

    <form id="deleteAllDocsForm" action="documents.php" method="POST" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
        <input type="hidden" name="action" value="delete_all_documents">
    </form>

    <div class="modal fade" id="permitPreviewModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">
                        <i class="fa-solid fa-eye"></i> Permit Preview
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0" style="background:#f1f5f9;">
                    <div id="permitPreviewLoading" style="padding: 60px 20px; text-align:center; color: #64748b;">
                        <i class="fa-solid fa-spinner fa-spin" style="font-size: 24px;"></i>
                        <p style="margin-top:10px; font-size: 12px;">Generating preview&hellip;</p>
                    </div>
                    <iframe id="permitPreviewFrame" style="width:100%; height: 65vh; border: 0; display: none; background:#ffffff;"></iframe>
                </div>
                <div class="modal-footer flex-wrap" style="gap:8px;">
                    <button type="button" class="btn-secondary" data-bs-dismiss="modal" style="flex:1;">
                        <i class="fa-solid fa-pen"></i> Edit
                    </button>
                    <button type="button" id="confirmIssuePermitBtn" class="btn-primary" style="flex:2; margin:0;">
                        <i class="fa-solid fa-paper-plane"></i> Confirm &amp; Send
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="doc-preview-overlay" id="docPreviewOverlay" onclick="closeDocPreviewOnOutside(event)">
        <div class="doc-preview-content">
            <div class="doc-preview-header">
                <strong id="docPreviewTitle"><i class="fa-solid fa-file-lines" style="color:#dc2626; margin-right:6px;"></i>Document Preview</strong>
                <div class="doc-preview-actions">
                    <button type="button" class="btn-secondary" onclick="window.print()" style="background:#fee2e2; border-color:#fecdd3; color:#dc2626;">
                        <i class="fa-solid fa-print"></i> Print
                    </button>
                    <a href="#" id="docPreviewOpenNewTab" target="_blank" class="btn-secondary" style="background:#f1f5f9;">
                        <i class="fa-solid fa-up-right-from-square"></i> Open
                    </a>
                    <div class="doc-preview-close" onclick="closeDocPreview()">
                        <i class="fa-solid fa-xmark"></i>
                    </div>
                </div>
            </div>
            <div class="doc-preview-body" id="docPreviewBodyContainer">
                <iframe id="docPreviewFrame" src="about:blank" title="Document Preview" style="display:none;"></iframe>
                <img id="docPreviewImage" src="" alt="Document Image" style="display:none;">
            </div>
        </div>
    </div>

    <div class="modal fade" id="searchModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fa-solid fa-magnifying-glass"></i> Global System Search
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div style="position: relative; margin-bottom: 20px;">
                        <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 18px; top: 50%; transform: translateY(-50%); color: var(--primary-red); font-size: 15px;"></i>
                        <input type="text" id="modalSearchInput" class="form-control" placeholder="Search by tracking number, business name, or owner..." autocomplete="off" style="padding: 14px 16px 14px 48px; border-radius: 12px; border: 1.5px solid #e2e8f0; background: #f8fafc; font-size: 15px;">
                    </div>
                    <div id="modalSearchResults" style="max-height: 380px; overflow-y: auto;">
                        <div style="text-align: center; padding: 40px 20px; color: #64748b; font-size: 13px;">
                            <i class="fa-regular fa-keyboard" style="font-size: 28px; margin-bottom: 10px; display: block; color: var(--primary-red);"></i>
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
           NAV MENU — INLINE DESKTOP / SLIDE-IN MOBILE
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
                closeDocPreview();
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

        /* ============================================
           CLOCK
        ============================================ */
        setInterval(() => {
            const d = new Date();
            let h = d.getHours(), m = d.getMinutes(), ampm = h >= 12 ? 'PM' : 'AM';
            h = h % 12 || 12;
            const clockEl = document.getElementById('clockTime');
            if (clockEl) clockEl.textContent = `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')} ${ampm}`;
        }, 1000);

        /* ============================================
           NOTIFICATIONS + PROFILE
        ============================================ */
        const notifBtn = document.getElementById('notifBtn');
        const notifDropdown = document.getElementById('notifDropdown');
        const profileToggle = document.getElementById('profileToggle');
        const profileDropdown = document.getElementById('profileDropdown');

        notifBtn?.addEventListener('click', (e) => {
            e.stopPropagation();
            profileDropdown?.classList.remove('show');
            notifDropdown.classList.toggle('show');
            if (notifDropdown.classList.contains('show')) {
                fetch('documents.php?ajax_action=read_notif')
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

        /* ============================================
           SEARCH MODAL
        ============================================ */
        const searchModal = document.getElementById('searchModal');
        const modalSearchInput = document.getElementById('modalSearchInput');
        const modalSearchResults = document.getElementById('modalSearchResults');

        searchModal?.addEventListener('shown.bs.modal', () => modalSearchInput.focus());
        let searchDebounce;
        modalSearchInput?.addEventListener('input', function() {
            clearTimeout(searchDebounce);
            let q = this.value.trim();
            if (q.length < 2) {
                modalSearchResults.innerHTML = '<div style="text-align: center; padding: 20px; color: #64748b; font-size: 12px;">Type at least 2 characters...</div>';
                return;
            }
            searchDebounce = setTimeout(() => {
                fetch('documents.php?ajax_action=search&q=' + encodeURIComponent(q))
                    .then(res => res.text())
                    .then(html => modalSearchResults.innerHTML = html);
            }, 250);
        });

        /* ============================================
           TABS
        ============================================ */
        const tabButtons = document.querySelectorAll('.tab-btn');
        const tabPanels = document.querySelectorAll('.tab-panel');
        tabButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                const target = this.getAttribute('data-tab');
                tabButtons.forEach(b => b.classList.remove('active'));
                tabPanels.forEach(p => p.classList.remove('active'));
                this.classList.add('active');
                document.getElementById(target)?.classList.add('active');
            });
        });

        /* ============================================
           PERMIT TYPE HINT + CUSTOM
        ============================================ */
        const permitTypeSelect = document.getElementById('permit_type');
        const permitTypeHint = document.getElementById('permitTypeHint');
        const customPermitRow = document.getElementById('customPermitRow');
        const customPermitInput = document.getElementById('custom_permit_type');
        const permitTypeDescriptions = {
            'Business Permit': 'Certifies the establishment is authorized to operate as a business within Santiago.',
            'Sanitary Permit': 'Certifies the establishment complies with sanitary and health regulations.',
            'Fire Safety Inspection Certificate': 'Certifies the establishment passed fire safety inspection standards.',
            'Barangay Clearance': 'Certifies the business has no derogatory record and is cleared by the Barangay.',
            'Health Certificates': 'Certifies personnel or boarding house complies with health and sanitation standards.',
            'Lease Contract': 'Certifies the lease or rental agreement has been registered with the City Assessor.',
            'Lease Clearance Certificate': 'Certifies the lease/rental has been registered and cleared for zoning compliance.',
            'Occupancy Compliance Permit': 'Certifies the premises passed inspection for structural, safety, and occupancy standards.',
            'Others': 'Specify a custom permit type. This will be reflected on the certificate.'
        };
        function updatePermitTypeHint() {
            if (!permitTypeSelect || !permitTypeHint) return;
            const val = permitTypeSelect.value;
            permitTypeHint.textContent = permitTypeDescriptions[val] || '';
            if (val === 'Others') {
                customPermitRow.style.display = 'flex';
                customPermitInput.setAttribute('required', 'required');
            } else {
                customPermitRow.style.display = 'none';
                customPermitInput.removeAttribute('required');
                customPermitInput.value = '';
            }
        }
        permitTypeSelect?.addEventListener('change', updatePermitTypeHint);
        updatePermitTypeHint();

        /* ============================================
           ISSUE PERMIT PREVIEW
        ============================================ */
        const issuePermitForm = document.getElementById('issuePermitForm');
        const previewPermitBtn = document.getElementById('previewPermitBtn');
        const permitPreviewModalEl = document.getElementById('permitPreviewModal');
        const permitPreviewFrame = document.getElementById('permitPreviewFrame');
        const permitPreviewLoading = document.getElementById('permitPreviewLoading');
        const confirmIssuePermitBtn = document.getElementById('confirmIssuePermitBtn');
        let permitPreviewModal = null;
        if (permitPreviewModalEl && window.bootstrap) {
            permitPreviewModal = new bootstrap.Modal(permitPreviewModalEl);
        }

        previewPermitBtn?.addEventListener('click', function() {
            if (!issuePermitForm.checkValidity()) {
                issuePermitForm.reportValidity();
                return;
            }

            const params = new URLSearchParams({
                ajax_action: 'preview_permit',
                rental_id: document.getElementById('rental_id').value,
                permit_type: document.getElementById('permit_type').value,
                custom_permit_type: document.getElementById('custom_permit_type').value,
                valid_until: document.getElementById('valid_until').value,
                permit_notes: document.getElementById('permit_notes').value
            });

            permitPreviewFrame.style.display = 'none';
            permitPreviewLoading.style.display = 'block';
            confirmIssuePermitBtn.disabled = false;

            permitPreviewModal?.show();
            permitPreviewFrame.src = 'documents.php?' + params.toString();

            permitPreviewFrame.onload = function() {
                permitPreviewLoading.style.display = 'none';
                permitPreviewFrame.style.display = 'block';
            };
        });

        confirmIssuePermitBtn?.addEventListener('click', function() {
            confirmIssuePermitBtn.disabled = true;
            confirmIssuePermitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Issuing&hellip;';
            issuePermitForm.submit();
        });

        /* ============================================
           DOCUMENT PREVIEW
        ============================================ */
        const docPreviewOverlay = document.getElementById('docPreviewOverlay');
        const docPreviewFrame = document.getElementById('docPreviewFrame');
        const docPreviewImage = document.getElementById('docPreviewImage');
        const docPreviewTitle = document.getElementById('docPreviewTitle');
        const docPreviewOpenNewTab = document.getElementById('docPreviewOpenNewTab');

        function openDocPreview(title, url) {
            docPreviewTitle.innerHTML = '<i class="fa-solid fa-file-lines" style="color:#dc2626; margin-right:6px;"></i>' + title;
            docPreviewOpenNewTab.href = url;
            const ext = url.split('.').pop().toLowerCase().split('?')[0];
            if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
                docPreviewImage.src = url;
                docPreviewImage.style.display = 'block';
                docPreviewFrame.style.display = 'none';
                docPreviewFrame.src = 'about:blank';
            } else {
                docPreviewFrame.src = url;
                docPreviewFrame.style.display = 'block';
                docPreviewImage.style.display = 'none';
                docPreviewImage.src = '';
            }
            docPreviewOverlay.classList.add('active');
        }

        function closeDocPreview() {
            docPreviewOverlay.classList.remove('active');
            docPreviewFrame.src = 'about:blank';
            docPreviewImage.src = '';
        }

        function closeDocPreviewOnOutside(event) {
            if (event.target === docPreviewOverlay) {
                closeDocPreview();
            }
        }

        document.querySelectorAll('.view-doc-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                openDocPreview(this.getAttribute('data-title'), this.getAttribute('data-url'));
            });
        });

        /* ============================================
           LOGOUT + DELETE CONFIRMATIONS
        ============================================ */
        function confirmLogout() {
            Swal.fire({
                title: 'Logout',
                text: 'Are you sure you want to end your session?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Logout'
            }).then((res) => { if (res.isConfirmed) document.getElementById('logoutForm').submit(); });
        }
        document.getElementById('profileLogoutBtn')?.addEventListener('click', (e) => { e.preventDefault(); confirmLogout(); });

        function confirmDeleteDocument(docId) {
            Swal.fire({
                title: 'Are you sure?',
                text: 'This document or permit record will be permanently deleted from database.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Delete'
            }).then((res) => {
                if (res.isConfirmed) {
                    document.getElementById('delDocId').value = docId;
                    document.getElementById('deleteDocForm').submit();
                }
            });
        }

        function confirmDeleteAllDocuments() {
            Swal.fire({
                title: 'Are you sure?',
                text: 'All documents and permit records will be permanently deleted from database.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Delete All'
            }).then((res) => {
                if (res.isConfirmed) {
                    document.getElementById('deleteAllDocsForm').submit();
                }
            });
        }
    </script>
</body>
</html>