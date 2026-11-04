<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== "staff") {
    header("Location: ../login.php");
    exit();
}

require_once("../config/db.php");
require_once("includes/auth.php");

/* =====================================================
   HELPER FUNCTIONS FOR PDO & POSTGRESQL & HTML BUILDER
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
                    $badgeBg = '#f3f4f6';
                    $badgeColor = '#4b5563';
                    if ($status === 'Pending') { $badgeBg = '#fef3c7'; $badgeColor = '#d97706'; }
                    elseif ($status === 'Approved') { $badgeBg = '#dcfce7'; $badgeColor = '#15803d'; }
                    elseif ($status === 'Rejected') { $badgeBg = '#fee2e2'; $badgeColor = '#b91c1c'; }

                    echo '<a href="view_rental.php?id=' . $row['id'] . '" style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 12px; color: #111827; text-decoration: none; transition: all 0.2s ease;">';
                    echo '<div style="display: flex; flex-direction: column; gap: 4px;">';
                    echo '<div style="font-weight: 600; font-size: 14px; color: #dc2626;">' . htmlspecialchars($row['tracking_no']) . ' <span style="color: #9ca3af; font-weight: 400;">•</span> ' . htmlspecialchars($row['business_name']) . '</div>';
                    echo '<div style="font-size: 12px; color: #4b5563;"><i class="fa-solid fa-user-shield" style="font-size: 11px; margin-right: 4px;"></i> Owner: ' . htmlspecialchars($row['owner_name'] ?? 'N/A') . '</div>';
                    echo '</div>';
                    echo '<span style="padding: 4px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; background: ' . $badgeBg . '; color: ' . $badgeColor . '; text-transform: uppercase;">' . htmlspecialchars($status) . '</span>';
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
}

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

// Handle Approval o Rejection Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_renewal'])) {
    $renewalId = (int)$_POST['renewal_id'];
    $rentalId = (int)($_POST['rental_id'] ?? 0);
    $action = $_POST['action_type'];
    $newExpiryDate = $_POST['new_expiry_date'] ?? null;
    
    $newStatus = ($action === 'Approved') ? 'Approved' : 'Rejected';

    try {
        $conn->beginTransaction();

        // 1. Update Renewal Status
        $updateStmt = $conn->prepare("UPDATE permit_renewals SET status = :status WHERE id = :id");
        $updateStmt->execute([':status' => $newStatus, ':id' => $renewalId]);

        if ($action === 'Approved' && !empty($newExpiryDate) && $rentalId > 0) {
            // 2. Update Rental Expiry Date
            $expiryCol = columnExists($conn, 'rentals', 'permit_expiry_date') ? 'permit_expiry_date' : (columnExists($conn, 'rentals', 'expiry_date') ? 'expiry_date' : null);
            
            if ($expiryCol) {
                $updateRentalSql = "UPDATE rentals SET {$expiryCol} = :expiry, status = 'Approved' WHERE id = :rental_id";
                $updateRentalStmt = $conn->prepare($updateRentalSql);
                $updateRentalStmt->execute([
                    ':expiry' => $newExpiryDate,
                    ':rental_id' => $rentalId
                ]);
            }

            // 3. GENERATE NEW HTML PERMIT AND SAVE TO DATABASE
            $rStmt = $conn->prepare("
                SELECT r.id, r.tracking_no, r.business_name, r.rental_name, r.user_id, u.fullname as owner_name 
                FROM rentals r 
                LEFT JOIN users u ON r.user_id = u.id 
                WHERE r.id = :rid
            ");
            $rStmt->execute([':rid' => $rentalId]);
            $rentalInfo = $rStmt->fetch(PDO::FETCH_ASSOC);

            $renStmt = $conn->prepare("SELECT document_type FROM permit_renewals WHERE id = :id");
            $renStmt->execute([':id' => $renewalId]);
            $renewalDocType = $renStmt->fetchColumn() ?: 'Business Permit';

            if ($rentalInfo && !empty($rentalInfo['user_id'])) {
                $bizName = $rentalInfo['business_name'] ?: $rentalInfo['rental_name'];
                $ownerId = (int)$rentalInfo['user_id'];
                $trackingNo = $rentalInfo['tracking_no'] ?? 'TRK-' . $rentalId;
                $folderPath = 'user_' . $ownerId;
                
                $displayPermitType = $renewalDocType;
                $permitTitle = $displayPermitType . ' - ' . $bizName;
                $storedName = 'permit_renewal_' . uniqid('', true) . '.html';
                
                $securityHash = hash('sha256', $rentalId . $bizName . time());
                $issueDate = date('F d, Y');
                $expiryDateFormatted = date('F d, Y', strtotime($newExpiryDate));

                $prefixMap = getPermitPrefixMap();
                $permitPrefix = $prefixMap[$displayPermitType] ?? 'PERMIT';
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
                $generatedPermitNo = $permitPrefix . '-' . $year . '-RE' . str_pad($seq, 3, '0', STR_PAD_LEFT);

                $permitHtml = buildPermitCertificateHtml([
                    'trackingNo'          => $trackingNo,
                    'bizName'             => $bizName,
                    'ownerName'           => $rentalInfo['owner_name'] ?? 'N/A',
                    'permitType'          => $displayPermitType,
                    'customPermitType'    => '',
                    'issueDate'           => $issueDate,
                    'expiryDateFormatted' => $expiryDateFormatted,
                    'notes'               => 'Renewed. Valid until: ' . $newExpiryDate . '.',
                    'securityHash'        => $securityHash,
                    'staffId'             => $staffId,
                    'logo'                => 'assets/images/images.jpg',
                    'isPreview'           => false,
                    'permitNo'            => $generatedPermitNo,
                ]);

                $insDocStmt = $conn->prepare("
                    INSERT INTO documents (
                        user_id, rental_id, title, type, notes, file_name, stored_name, owner_folder_path,
                        business_permit_no, permit_expiry_date, permit_status, uploaded_at, permit_html
                    )
                    VALUES (
                        :uid, :rid, :title, :type, :notes, :fname, :sname, :fpath,
                        :bpno, :pexp, :pstat, NOW(), :permit_html
                    )
                ");
                $insDocStmt->execute([
                    ':uid'         => $ownerId,
                    ':rid'         => $rentalId,
                    ':title'       => $permitTitle,
                    ':type'        => 'Permit',
                    ':notes'       => 'Renewed. Valid until: ' . $newExpiryDate . '. Official HTML Certificate.',
                    ':fname'       => $displayPermitType . '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $bizName) . '_RENEWED.html',
                    ':sname'       => $storedName,
                    ':fpath'       => $folderPath,
                    ':bpno'        => $generatedPermitNo,
                    ':pexp'        => $newExpiryDate,
                    ':pstat'       => 'Active',
                    ':permit_html' => $permitHtml
                ]);
            }
        }

        $conn->commit();
        $_SESSION['success'] = "Renewal request successfully updated and new permit generated!";
        header("Location: renewals_approval.php");
        exit();

    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $_SESSION['error'] = "Failed to update renewal request: " . safe_error($e);
    }
}

// Notifications
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

// Pending renewals count
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

// =========================================================
// KUNIN ANG RENEWAL REQUESTS JOIN SA PAYMENTS
// =========================================================
$renewals = [];
if (tableExists($conn, 'permit_renewals')) {
    $hasPayments = tableExists($conn, 'payments');
    
    if ($hasPayments) {
        $hasRenewalIdCol = columnExists($conn, 'payments', 'renewal_id');
        $hasVoidedCol = columnExists($conn, 'payments', 'is_voided');
        
        $voidFilter = $hasVoidedCol ? "AND (p2.is_voided = FALSE OR p2.is_voided IS NULL)" : "";
        
        if ($hasRenewalIdCol) {
            $query = "
                SELECT pr.*, 
                       r.business_name, r.rental_name, r.tracking_no, 
                       u.fullname as owner_name,
                       p.or_number,
                       COALESCE(p.or_number, 'PAY-' || p.id) as final_ref_no,
                       p.amount_paid, 
                       p.status::text as payment_status, 
                       p.payment_date, 
                       p.payment_method
                FROM permit_renewals pr 
                JOIN rentals r ON pr.rental_id = r.id 
                JOIN users u ON pr.user_id = u.id 
                LEFT JOIN payments p ON p.id = (
                    SELECT p2.id 
                    FROM payments p2 
                    WHERE p2.renewal_id = pr.id 
                      $voidFilter
                      AND LOWER(p2.status::text) IN ('paid', 'completed', 'success', 'verified', 'approved')
                    ORDER BY p2.payment_date DESC, p2.id DESC 
                    LIMIT 1
                )
                ORDER BY pr.created_at DESC
            ";
        } else {
            $query = "
                SELECT pr.*, 
                       r.business_name, r.rental_name, r.tracking_no, 
                       u.fullname as owner_name,
                       p.or_number,
                       COALESCE(p.or_number, 'PAY-' || p.id) as final_ref_no,
                       p.amount_paid, 
                       p.status::text as payment_status, 
                       p.payment_date, 
                       p.payment_method
                FROM permit_renewals pr 
                JOIN rentals r ON pr.rental_id = r.id 
                JOIN users u ON pr.user_id = u.id 
                LEFT JOIN payments p ON p.id = (
                    SELECT p2.id 
                    FROM payments p2 
                    WHERE p2.rental_id = pr.rental_id 
                      $voidFilter
                      AND LOWER(p2.status::text) IN ('paid', 'completed', 'success', 'verified', 'approved')
                    ORDER BY p2.payment_date DESC, p2.id DESC 
                    LIMIT 1
                )
                ORDER BY pr.created_at DESC
            ";
        }
    } else {
        $query = "
            SELECT pr.*, r.business_name, r.rental_name, r.tracking_no, u.fullname as owner_name 
            FROM permit_renewals pr 
            JOIN rentals r ON pr.rental_id = r.id 
            JOIN users u ON pr.user_id = u.id 
            ORDER BY pr.created_at DESC
        ";
    }
    
    try {
        $stmt = $conn->query($query);
        if ($stmt) {
            $renewals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        $fallbackQuery = "
            SELECT pr.*, r.business_name, r.rental_name, r.tracking_no, u.fullname as owner_name 
            FROM permit_renewals pr 
            JOIN rentals r ON pr.rental_id = r.id 
            JOIN users u ON pr.user_id = u.id 
            ORDER BY pr.created_at DESC
        ";
        $stmt = $conn->query($fallbackQuery);
        if ($stmt) {
            $renewals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#dc2626">
    <title>Permit Renewals Approval | RENTRACK LGU</title>

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

        /* STATS STRIP */
        .stats-strip { display: grid; grid-template-columns: 1fr; gap: 12px; margin-bottom: 20px; }
        @media(min-width: 576px) { .stats-strip { grid-template-columns: repeat(3, 1fr); gap: 16px; } }

        .stat-chip {
            position: relative;
            display: flex; align-items: center; gap: 14px;
            border-radius: 18px; padding: 18px 20px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid #e2e8f0;
        }
        .stat-chip:hover { transform: translateY(-3px); box-shadow: 0 8px 20px rgba(0, 0, 0, 0.06); }
        .stat-chip .stat-icon {
            width: 46px; height: 46px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; flex-shrink: 0; color: #ffffff;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .stat-chip .stat-num { font-size: 24px; font-weight: 800; color: #ffffff; line-height: 1.1; }
        .stat-chip .stat-lbl { font-size: 11px; color: rgba(255, 255, 255, 0.85); text-transform: uppercase; letter-spacing: 0.8px; margin-top: 3px; font-weight: 700; }

        .stat-chip.stat-red { background: linear-gradient(135deg, #dc2626, #991b1b); border-color: #dc2626; }
        .stat-chip.stat-red .stat-icon { background: rgba(255, 255, 255, 0.2); }
        .stat-chip.stat-blue { background: linear-gradient(135deg, #0284c7, #0369a1); border-color: #0284c7; }
        .stat-chip.stat-blue .stat-icon { background: rgba(255, 255, 255, 0.2); }
        .stat-chip.stat-green { background: linear-gradient(135deg, #059669, #065f46); border-color: #059669; }
        .stat-chip.stat-green .stat-icon { background: rgba(255, 255, 255, 0.2); }
        .stat-chip.stat-amber { background: linear-gradient(135deg, #f59e0b, #b45309); border-color: #f59e0b; }
        .stat-chip.stat-amber .stat-icon { background: rgba(255, 255, 255, 0.2); }

        /* PANEL */
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

        /* TABLE */
        .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .table-wrap::-webkit-scrollbar { height: 6px; }
        .table-wrap::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

        .table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 900px; margin-bottom: 0; }
        .table thead th {
            text-align: left;
            padding: 12px 14px;
            font-size: 10.5px; font-weight: 700;
            letter-spacing: 0.06em; text-transform: uppercase;
            color: var(--text-muted);
            background: #f1f5f9;
            border-bottom: 1px solid #e2e8f0;
            white-space: nowrap;
        }
        .table tbody td {
            padding: 16px 14px;
            border-bottom: 1px solid #f1f5f9;
            color: var(--text-secondary);
            vertical-align: middle;
            font-weight: 500;
        }
        .table tbody tr { transition: background 0.15s; }
        .table tbody tr:hover { background: #fff1f2; }
        .table tbody tr:last-child td { border-bottom: none; }

        .badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 4px 12px; border-radius: 40px;
            font-size: 10px; font-weight: 700;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .badge-warning { background: #fef3c7; color: #d97706; border: 1px solid #fde68a; }
        .badge-success { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .badge-info { background: #ccfbf1; color: #0f766e; border: 1px solid #99f6e4; }
        .badge-danger { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }

        .btn-action {
            padding: 6px 14px;
            border-radius: 8px; font-size: 12px; font-weight: 600;
            border: none; cursor: pointer;
            transition: var(--transition);
            display: inline-flex; align-items: center; gap: 6px;
            text-decoration: none;
            font-family: inherit;
        }
        .btn-approve { background: #fee2e2; color: #dc2626; border: 1px solid #fecdd3; }
        .btn-approve:hover:not(:disabled) { background: var(--primary-red); color: #fff; }
        .btn-approve:disabled { opacity: 0.5; cursor: not-allowed; background: #f3f4f6; color: #9ca3af; border-color: #e5e7eb; }
        .btn-reject { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
        .btn-reject:hover { background: #ef4444; color: #fff; }
        .btn-view { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
        .btn-view:hover { background: #0284c7; color: #fff; }

        .zoom-image-container {
            overflow: auto; max-height: 70vh; display: flex; justify-content: center; align-items: center;
            background: #f8fafc; border-radius: 12px; padding: 10px; position: relative; border: 1px solid #e2e8f0;
        }
        .preview-image-zoomable {
            max-width: 100%; transition: transform 0.3s ease, cursor 0.2s ease; cursor: zoom-in; object-fit: contain;
        }
        .preview-image-zoomable.is-zoomed { cursor: zoom-out; transform: scale(2); max-width: none; }

        .empty-state { padding: 60px 24px; text-align: center; color: var(--text-muted); }
        .empty-state i { font-size: 40px; color: var(--text-muted); display: block; margin-bottom: 12px; opacity: 0.6; }
        .empty-state h4 { font-size: 14px; font-weight: 600; color: var(--text-secondary); margin-bottom: 4px; }
        .empty-state p { font-size: 12.5px; }

        .footer {
            margin-top: 24px; padding-top: 16px;
            border-top: 1px solid #e2e8f0;
            display: flex; justify-content: space-between; align-items: center;
            font-size: 12px; color: var(--text-muted);
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
            .modal-body { max-height: 70vh; overflow-y: auto; }
        }
        .modal-header { border-bottom: 1px solid #f1f5f9 !important; padding: 16px 22px; }
        .modal-footer { border-top: 1px solid #f1f5f9 !important; }
        .modal-title { font-size: 14.5px !important; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 9px; }
        .modal-title i { color: var(--primary-red); font-size: 14px; }

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

            .panel { border-radius: 16px; }
            .panel-head { padding: 14px 16px; }
            .panel-title { font-size: 13.5px; }

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
        }

        @media (max-width: 480px) {
            .official-header { padding: 5px 8px; padding-top: calc(5px + var(--safe-top)); }
            .top-navbar { padding: 8px 10px; }
            .content { padding: 12px 10px 22px; padding-bottom: calc(22px + var(--safe-bottom)); }
            .page-header { padding: 14px 16px; border-radius: 12px; }
            .page-header h1 { font-size: 16px; }
            .page-header p { font-size: 11.5px; }
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
                                <button type="button" class="nav-link active" data-dropdown>
                                    <i class="fa-solid fa-folder-open nav-ico"></i> Rental Management
                                    <i class="fa-solid fa-chevron-down chevron"></i>
                                </button>
                                <div class="nav-dropdown">
                                    <a href="rentals.php"><i class="fa-solid fa-folder-open"></i> Rental Applications</a>
                                    <a href="add_rental.php"><i class="fa-solid fa-plus-circle"></i> Register Rental</a>
                                    <a href="documents.php"><i class="fa-solid fa-file-lines"></i> Documents</a>
                                    <a href="renewals_approval.php"><i class="fa-solid fa-file-circle-exclamation"></i> Permit Renewals</a>
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
                    <strong>Permit Renewals Approval</strong>
                </div>

                <!-- PAGE HEADER -->
                <div class="page-header">
                    <div>
                        <h1><i class="fa-solid fa-file-shield"></i> Online Permit Renewals Approval</h1>
                        <p>Review and approve the documents and permits submitted by rental owners.</p>
                    </div>
                </div>

                <!-- STATS STRIP -->
                <?php
                    $totalRenewals = count($renewals);
                    $approvedCount = 0;
                    $rejectedCount = 0;
                    $pendingCount = 0;
                    foreach ($renewals as $r) {
                        $st = strtolower($r['status'] ?? 'pending');
                        if ($st === 'approved') $approvedCount++;
                        elseif ($st === 'rejected') $rejectedCount++;
                        else $pendingCount++;
                    }
                ?>
                <div class="stats-strip">
                    <div class="stat-chip stat-red">
                        <div class="stat-icon"><i class="fa-solid fa-file-circle-exclamation"></i></div>
                        <div>
                            <div class="stat-num"><?php echo $pendingCount; ?></div>
                            <div class="stat-lbl">Pending Renewals</div>
                        </div>
                    </div>
                    <div class="stat-chip stat-green">
                        <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
                        <div>
                            <div class="stat-num"><?php echo $approvedCount; ?></div>
                            <div class="stat-lbl">Approved</div>
                        </div>
                    </div>
                    <div class="stat-chip stat-amber">
                        <div class="stat-icon"><i class="fa-solid fa-circle-xmark"></i></div>
                        <div>
                            <div class="stat-num"><?php echo $rejectedCount; ?></div>
                            <div class="stat-lbl">Rejected</div>
                        </div>
                    </div>
                </div>

                <!-- PANEL -->
                <div class="panel">
                    <div class="panel-head">
                        <div class="panel-title">
                            <i class="fa-solid fa-list"></i> Renewal Requests
                        </div>
                        <div class="panel-meta">Total: <strong><?php echo $totalRenewals; ?></strong> request<?php echo $totalRenewals === 1 ? '' : 's'; ?></div>
                    </div>

                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Owner</th>
                                    <th>Property / Business</th>
                                    <th>Document Type</th>
                                    <th>Payment Verification</th>
                                    <th>Attachment</th>
                                    <th>Status</th>
                                    <th style="text-align:center;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($renewals)): ?>
                                    <?php foreach ($renewals as $row): ?>
                                    <?php 
                                        $bizName = !empty($row['business_name']) ? $row['business_name'] : $row['rental_name']; 
                                        
                                        $finalRef = !empty($row['final_ref_no']) ? trim($row['final_ref_no']) : '';
                                        $hasPaid = ($finalRef !== '' && $finalRef !== 'N/A');

                                        if (!$hasPaid && !empty($row['payment_status'])) {
                                            $ps = strtolower(trim($row['payment_status']));
                                            if (in_array($ps, ['paid', 'completed', 'success', 'verified', 'approved'])) {
                                                $hasPaid = true;
                                                if ($finalRef === '' || $finalRef === 'N/A') {
                                                    $finalRef = !empty($row['or_number']) ? $row['or_number'] : 'PAY-' . ($row['id'] ?? '');
                                                }
                                            }
                                        }
                                        
                                        $payMethod = !empty($row['payment_method']) ? $row['payment_method'] : 'LGU Treasury';
                                        $isEWallet = (bool) preg_match('/gcash|paymaya|grabpay|e-wallet|online|bank|card|maya/i', $payMethod);
                                        
                                        $amountPaid = isset($row['amount_paid']) && $row['amount_paid'] !== null
                                            ? number_format((float)$row['amount_paid'], 2)
                                            : '0.00';
                                        $payDate = !empty($row['payment_date']) ? date('M d, Y', strtotime($row['payment_date'])) : 'N/A';

                                        // ✅ CHECK KUNG SUPABASE URL O LOCAL FILE
                                        $filePath = $row['supporting_file'];
                                        if (filter_var($filePath, FILTER_VALIDATE_URL)) {
                                            $viewPath = h($filePath); // Supabase URL
                                        } else {
                                            $viewPath = "../uploads/renewals/" . h($filePath); // Local fallback
                                        }
                                    ?>
                                    <tr>
                                        <td data-label="Date"><?php echo date('M d, Y h:i A', strtotime($row['created_at'])); ?></td>
                                        <td data-label="Owner"><strong style="color:#000000 !important;"><?php echo h($row['owner_name']); ?></strong></td>
                                        <td data-label="Property / Business">
                                            <?php echo h($bizName); ?><br>
                                            <small style="color:#64748b;">Tracking: <?php echo h($row['tracking_no']); ?></small>
                                        </td>
                                        <td data-label="Document Type"><?php echo h($row['document_type']); ?></td>
                                        
                                        <td data-label="Payment Verification">
                                            <?php if ($hasPaid): ?>
                                                <?php if ($isEWallet): ?>
                                                    <span class="badge badge-info mb-1"><i class="fa-solid fa-wallet"></i> PAID VIA E-WALLET</span><br>
                                                    <small style="font-size: 11px; color: #0f766e; font-weight: 600;">Ref No: <?php echo h($finalRef); ?></small>
                                                <?php else: ?>
                                                    <span class="badge badge-success mb-1"><i class="fa-solid fa-circle-check"></i> PAID (TREASURY)</span><br>
                                                    <small style="font-size: 11px; color: #15803d; font-weight: 600;">O.R. No: <?php echo h($finalRef); ?></small>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-sm btn-link text-decoration-none p-0 d-block mt-1 open-receipt-modal" style="font-size: 10.5px; color: #0284c7;"
                                                        data-ref="<?php echo h($finalRef); ?>"
                                                        data-amount="₱<?php echo $amountPaid; ?>"
                                                        data-date="<?php echo h($payDate); ?>"
                                                        data-method="<?php echo h($payMethod); ?>"
                                                        data-owner="<?php echo h($row['owner_name']); ?>"
                                                        data-business="<?php echo h($bizName); ?>">
                                                    <i class="fa-solid fa-receipt me-1"></i> View Receipt / Ref Info
                                                </button>
                                            <?php else: ?>
                                                <span class="badge badge-danger mb-1"><i class="fa-solid fa-circle-xmark"></i> UNPAID</span><br>
                                                <small style="font-size: 10.5px; color: #94a3b8;">No O.R. or E-Wallet Record</small>
                                            <?php endif; ?>
                                        </td>

                                        <td data-label="Attachment">
                                            <?php if (!empty($row['supporting_file'])): ?>
                                                <button type="button" class="btn-action btn-view open-view-file-modal" 
                                                        data-file="<?php echo $viewPath; ?>" 
                                                        data-title="<?php echo h($row['document_type']); ?> - <?php echo h($bizName); ?>">
                                                    <i class="fa-solid fa-eye"></i> View File
                                                </button>
                                            <?php else: ?>
                                                <span style="color:#94a3b8;">No File</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Status">
                                            <?php 
                                            $st = strtolower($row['status']);
                                            if ($st === 'approved'): ?>
                                                <span class="badge badge-success">Approved</span>
                                            <?php elseif ($st === 'rejected'): ?>
                                                <span class="badge badge-danger">Rejected</span>
                                            <?php else: ?>
                                                <span class="badge badge-warning">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Action" style="text-align:center;">
                                            <?php if ($st === 'pending'): ?>
                                                <div style="display: flex; gap: 6px; justify-content: center;">
                                                    <button type="button" class="btn-action btn-approve open-approve-modal" 
                                                            data-id="<?php echo $row['id']; ?>" 
                                                            data-rental-id="<?php echo $row['rental_id']; ?>" 
                                                            data-business="<?php echo h($bizName); ?>" 
                                                            data-expiry="<?php echo h($row['current_expiry_date']); ?>"
                                                            <?php echo !$hasPaid ? 'disabled title="Cannot Approve: Owner has NOT paid yet via Treasury or E-Wallet!"' : ''; ?>>
                                                        <i class="fa-solid fa-check"></i> Approve
                                                    </button>

                                                    <form action="" method="POST" style="display:inline;">
                                                        <input type="hidden" name="renewal_id" value="<?php echo $row['id']; ?>">
                                                        <input type="hidden" name="rental_id" value="<?php echo $row['rental_id']; ?>">
                                                        <input type="hidden" name="action_type" value="Rejected">
                                                        <button type="submit" name="action_renewal" class="btn-action btn-reject" title="Reject Renewal" onclick="return confirm('Are you sure you want to reject this renewal?');">
                                                            <i class="fa-solid fa-xmark"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            <?php else: ?>
                                                <span style="color:#94a3b8; font-size:11px;">Completed</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8">
                                            <div class="empty-state">
                                                <i class="fa-solid fa-inbox"></i>
                                                <h4>No renewal requests received</h4>
                                                <p>Renewal requests from owners will appear here.</p>
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

    <!-- ===== OFFICIAL RECEIPT & E-WALLET VERIFICATION MODAL ===== -->
    <div class="modal fade" id="receiptInfoModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border: 1.5px solid #dc2626;">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fa-solid fa-receipt"></i> Payment Verification Record
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <span class="badge badge-success px-3 py-2" style="font-size:12px;"><i class="fa-solid fa-circle-check me-1"></i> VERIFIED TRANSACTION</span>
                    </div>
                    <div class="p-3 mb-3" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px;">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-secondary small">O.R. / Ref No:</span>
                            <strong class="text-danger" id="rec_or">---</strong>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-secondary small">Amount Paid:</span>
                            <strong class="text-dark" id="rec_amount">---</strong>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-secondary small">Date Paid:</span>
                            <span class="text-dark" id="rec_date">---</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-secondary small">Payment Channel:</span>
                            <span class="text-info fw-bold" id="rec_method">---</span>
                        </div>
                        <hr style="border-color: #e2e8f0;">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-secondary small">Payor Name:</span>
                            <span class="text-dark" id="rec_owner">---</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-secondary small">Business Name:</span>
                            <span class="text-dark" id="rec_business">---</span>
                        </div>
                    </div>
                    <small class="text-muted d-block text-center" style="font-size: 11px;">
                        <i class="fa-solid fa-shield-halved me-1 text-danger"></i> Transaction verified via LGU Treasury / Online Payment Gateway.
                    </small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary w-100 fw-bold" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== FILE VIEW MODAL ===== -->
    <div class="modal fade" id="fileViewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content" style="border: 1.5px solid #dc2626; overflow: hidden;">
                <div class="modal-header d-flex justify-content-between align-items-center">
                    <h5 class="modal-title" id="fileViewModalTitle">
                        <i class="fa-solid fa-file-pdf"></i> Attachment Preview
                    </h5>
                    <div class="d-flex align-items-center gap-2">
                        <div id="imageZoomControls" class="d-none me-3">
                            <button type="button" class="btn btn-sm btn-light border me-1" id="zoomInBtn" title="Zoom In"><i class="fa-solid fa-magnifying-glass-plus"></i></button>
                            <button type="button" class="btn btn-sm btn-light border me-1" id="zoomOutBtn" title="Zoom Out"><i class="fa-solid fa-magnifying-glass-minus"></i></button>
                            <button type="button" class="btn btn-sm btn-light border" id="zoomResetBtn" title="Reset Zoom"><i class="fa-solid fa-rotate-right"></i></button>
                        </div>
                        <a id="downloadFileBtn" href="#" target="_blank" class="btn btn-sm btn-outline-danger me-2" title="Download File">
                            <i class="fa-solid fa-download"></i> Open in New Tab
                        </a>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                </div>
                <div class="modal-body p-3 text-center" style="min-height: 500px; display: flex; align-items: center; justify-content: center; background: #f8fafc;">
                    <div id="filePreviewContainer" style="width: 100%; height: 100%;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== GLOBAL APPROVAL MODAL ===== -->
    <div class="modal fade" id="globalApproveModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border: 1.5px solid #dc2626;">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fa-solid fa-calendar-check"></i> Approve Permit Renewal
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="renewal_id" id="modal_renewal_id">
                        <input type="hidden" name="rental_id" id="modal_rental_id">
                        <input type="hidden" name="action_type" value="Approved">

                        <div class="mb-3">
                            <label class="form-label text-secondary small fw-semibold">Business / Property Name:</label>
                            <div class="fw-bold text-dark" id="modal_business_name">---</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-secondary small fw-semibold">Current Expiry Date:</label>
                            <input type="text" id="modal_current_expiry" class="form-control text-dark" readonly style="font-size:13px; background-color: #f1f5f9 !important;">
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-dark small fw-bold">Set New Expiry Date:</label>
                            <input type="date" name="new_expiry_date" id="modal_new_expiry" class="form-control text-dark" value="<?php echo date('Y-m-d', strtotime('+1 year')); ?>" required style="font-size:14px;">
                            <small class="text-muted d-block mt-1" style="font-size: 11px;">Maaari mong palitan o i-edit ang petsa para sa bagong expiration duration.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-outline-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="action_renewal" class="btn btn-sm fw-bold px-3" style="background-color: #dc2626; border: none; color: #fff;">
                            <i class="fa-solid fa-check me-1"></i> Update &amp; Approve
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- SEARCH MODAL -->
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
                        <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 18px; top: 50%; transform: translateY(-50%); color: var(--primary-red); font-size: 16px;"></i>
                        <input type="text" id="modalSearchInput" class="form-control" placeholder="Search by tracking number, business name, or owner..." autocomplete="off" style="padding: 14px 16px 14px 48px; border-radius: 14px; border: 1.5px solid #e2e8f0; background: #f8fafc; font-size: 14px;">
                    </div>
                    <div id="modalSearchResults" style="max-height: 380px; overflow-y: auto;">
                        <div style="text-align: center; padding: 40px 20px; color: #64748b; font-size: 13px;">
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
                fetch('renewals_approval.php?ajax_action=read_notif')
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
           RECEIPT MODAL
        ============================================ */
        const receiptModal = new bootstrap.Modal(document.getElementById('receiptInfoModal'));
        document.querySelectorAll('.open-receipt-modal').forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById('rec_or').textContent = this.getAttribute('data-ref');
                document.getElementById('rec_amount').textContent = this.getAttribute('data-amount');
                document.getElementById('rec_date').textContent = this.getAttribute('data-date');
                document.getElementById('rec_method').textContent = this.getAttribute('data-method');
                document.getElementById('rec_owner').textContent = this.getAttribute('data-owner');
                document.getElementById('rec_business').textContent = this.getAttribute('data-business');
                receiptModal.show();
            });
        });

        /* ============================================
           FILE VIEW MODAL
        ============================================ */
        const fileViewModalElement = new bootstrap.Modal(document.getElementById('fileViewModal'));
        const filePreviewContainer = document.getElementById('filePreviewContainer');
        const fileViewModalTitle = document.getElementById('fileViewModalTitle');
        const downloadFileBtn = document.getElementById('downloadFileBtn');
        const imageZoomControls = document.getElementById('imageZoomControls');
        let currentScale = 1;

        document.querySelectorAll('.open-view-file-modal').forEach(btn => {
            btn.addEventListener('click', function() {
                const filePath = this.getAttribute('data-file');
                const titleText = this.getAttribute('data-title') || 'Attachment Preview';
                const fileExt = filePath.split('.').pop().toLowerCase();

                fileViewModalTitle.innerHTML = `<i class="fa-solid fa-file-lines me-2"></i> ${titleText}`;
                downloadFileBtn.setAttribute('href', filePath);
                currentScale = 1;

                if (['jpg', 'jpeg', 'png', 'webp', 'gif'].includes(fileExt)) {
                    imageZoomControls.classList.remove('d-none');
                    filePreviewContainer.innerHTML = `
                        <div class="zoom-image-container">
                            <img src="${filePath}" id="previewImg" class="preview-image-zoomable" alt="Supporting Attachment" title="Click to Zoom In / Zoom Out">
                        </div>
                    `;
                    const previewImg = document.getElementById('previewImg');
                    previewImg.addEventListener('click', function() {
                        this.classList.toggle('is-zoomed');
                        currentScale = this.classList.contains('is-zoomed') ? 2 : 1;
                    });
                } else if (fileExt === 'pdf') {
                    imageZoomControls.classList.add('d-none');
                    filePreviewContainer.innerHTML = `<iframe src="${filePath}" style="width:100%; height:70vh; border:none; border-radius:12px;"></iframe>`;
                } else {
                    imageZoomControls.classList.add('d-none');
                    filePreviewContainer.innerHTML = `
                        <div class="p-5 text-center text-dark">
                            <i class="fa-solid fa-file-lines display-3 text-secondary mb-3"></i>
                            <h5>Preview not supported for this file type</h5>
                            <a href="${filePath}" target="_blank" class="btn btn-danger mt-2"><i class="fa-solid fa-download me-1"></i> Download File</a>
                        </div>
                    `;
                }
                fileViewModalElement.show();
            });
        });

        document.getElementById('zoomInBtn')?.addEventListener('click', () => {
            const img = document.getElementById('previewImg');
            if (img) { currentScale += 0.3; img.style.transform = `scale(${currentScale})`; }
        });
        document.getElementById('zoomOutBtn')?.addEventListener('click', () => {
            const img = document.getElementById('previewImg');
            if (img && currentScale > 0.5) { currentScale -= 0.3; img.style.transform = `scale(${currentScale})`; }
        });
        document.getElementById('zoomResetBtn')?.addEventListener('click', () => {
            const img = document.getElementById('previewImg');
            if (img) { currentScale = 1; img.classList.remove('is-zoomed'); img.style.transform = `scale(1)`; }
        });

        /* ============================================
           APPROVE MODAL
        ============================================ */
        const approveModalElement = new bootstrap.Modal(document.getElementById('globalApproveModal'));
        document.querySelectorAll('.open-approve-modal').forEach(btn => {
            btn.addEventListener('click', function() {
                const renewalId = this.getAttribute('data-id');
                const rentalId = this.getAttribute('data-rental-id');
                const business = this.getAttribute('data-business');
                const expiry = this.getAttribute('data-expiry');

                document.getElementById('modal_renewal_id').value = renewalId;
                document.getElementById('modal_rental_id').value = rentalId;
                document.getElementById('modal_business_name').textContent = business;
                document.getElementById('modal_current_expiry').value = expiry;

                approveModalElement.show();
            });
        });

        /* ============================================
           SEARCH MODAL
        ============================================ */
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
                    modalSearchResults.innerHTML = `<div style="text-align: center; padding: 40px 20px; color: #64748b; font-size: 13px;">Type at least 2 characters to begin searching records...</div>`;
                    return;
                }
                searchDebounce = setTimeout(() => {
                    fetch('renewals_approval.php?ajax_action=search&q=' + encodeURIComponent(query))
                        .then(res => res.text())
                        .then(html => {
                            modalSearchResults.innerHTML = html.trim() !== '' ? html : `<div style="text-align: center; padding: 30px; color: #64748b; font-size: 13px;">No matching results found.</div>`;
                        });
                }, 250);
            });
        }

        /* ============================================
           LOGOUT
        ============================================ */
        function confirmLogout() {
            Swal.fire({
                title: 'Logout', text: 'Are you sure you want to end your session?', icon: 'warning',
                showCancelButton: true, confirmButtonColor: '#dc2626', cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Logout'
            }).then((res) => { if (res.isConfirmed) document.getElementById('logoutForm').submit(); });
        }
        document.getElementById('profileLogoutBtn')?.addEventListener('click', (e) => { e.preventDefault(); confirmLogout(); });
    </script>
</body>
</html>