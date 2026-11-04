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

/* AJAX SEARCH HANDLER */
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'search') {
    $query = trim($_GET['q'] ?? '');
    header('Content-Type: text/html; charset=utf-8');

    if (strlen($query) >= 2) {
        $searchTerm = '%' . $query . '%';
        try {
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
                    if ($status === 'Pending') { $badgeBg = '#fffbeb'; $badgeColor = '#b45309'; }
                    elseif ($status === 'Approved') { $badgeBg = '#ecfdf5'; $badgeColor = '#047857'; }
                    elseif ($status === 'Rejected') { $badgeBg = '#fef2f2'; $badgeColor = '#b91c1c'; }

                    echo '<a href="view_rental.php?id=' . $row['id'] . '" style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: #fafbfc; border: 1px solid #e8ecf1; border-radius: 12px; color: #0f172a; text-decoration: none; gap: 10px;">';
                    echo '<div style="display: flex; flex-direction: column; gap: 4px; min-width: 0; flex: 1;">';
                    echo '<div style="font-weight: 600; font-size: 13px; color: #dc2626; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-family: \'JetBrains Mono\', monospace;">' . htmlspecialchars($row['tracking_no']) . ' <span style="color: #94a3b8; font-family: \'Inter\', sans-serif;">•</span> <span style="font-family: \'Inter\', sans-serif;">' . htmlspecialchars($row['business_name']) . '</span></div>';
                    echo '<div style="font-size: 12px; color: #64748b;"><i class="fa-solid fa-user-shield" style="font-size: 10px; margin-right: 4px;"></i> Owner: ' . htmlspecialchars($row['owner_name'] ?? 'N/A') . '</div>';
                    echo '</div>';
                    echo '<span style="padding: 4px 10px; border-radius: 999px; font-size: 10px; font-weight: 700; background: ' . $badgeBg . '; color: ' . $badgeColor . '; text-transform: uppercase; flex-shrink: 0;">' . htmlspecialchars($status) . '</span>';
                    echo '</a>';
                }
                echo '</div>';
            } else {
                echo '<div style="text-align: center; padding: 40px 20px; color: #64748b; font-size: 13px;"><i class="fa-regular fa-folder-open" style="font-size: 28px; margin-bottom: 10px; display: block; color: #94a3b8;"></i>No matching results found.</div>';
            }
        } catch (PDOException $e) {
            echo '<div style="text-align: center; padding: 40px 20px; color: #b91c1c; font-size: 13px;">Search failed. Please try again.</div>';
        }
    }
    exit;
}

if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'read_notif') {
    $staffId = (int)($_SESSION['user_id'] ?? 0);
    if ($staffId > 0) {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE (user_id = :staff_id OR user_id = 0 OR user_id IS NULL) AND is_read = FALSE");
        $stmt->execute([':staff_id' => $staffId]);
        echo 'success';
    }
    exit;
}

/* PERMIT PREFIX MAP & CONFIG */
function getPermitPrefixMap() {
    return [
        'Business Permit' => 'BP', 'Sanitary Permit' => 'SP',
        'Fire Safety Inspection Certificate' => 'FSIC', 'Barangay Clearance' => 'BC',
        'Health Certificates' => 'HC', 'Lease Contract' => 'LC',
        'Lease Clearance Certificate' => 'LCC', 'Occupancy Compliance Permit' => 'OCP',
        'Others' => 'PERMIT',
    ];
}

function getPermitTypeConfig($permitType) {
    $types = [
        'Business Permit' => [
            'label' => "Business / Mayor's Permit (Santiago)", 'certTitle' => "Certificate of Business Permit &amp; Mayor's Clearance",
            'office' => "Office of the City Mayor &bull; Business Permits &amp; Licensing Office",
            'legalBasis' => "This certification is hereby granted pursuant to the Revenue Code and applicable municipal ordinances of the City of Santiago, certifying that the establishment named below has satisfactorily complied with the requirements for the operation of a business within the city's jurisdiction.",
            'recordLabel' => 'Business / Establishment',
            'clause' => "Must be conspicuously displayed at the business establishment premises at all times. Non-transferable.",
            'sig1Role' => 'City Treasurer', 'sig1Office' => 'Collections &amp; Revenue Office', 'sig2Role' => 'City Mayor / BPLO Head',
        ],
        'Sanitary Permit' => [
            'label' => 'Sanitary Permit', 'certTitle' => 'Certificate of Sanitary Permit',
            'office' => 'City Health Office &bull; Sanitation Division',
            'legalBasis' => "This certification is hereby issued pursuant to the Sanitation Code of the Philippines and applicable municipal ordinances of the City of Santiago, confirming that the establishment named below has complied with the required sanitary standards.",
            'recordLabel' => 'Establishment / Business',
            'clause' => "Must be conspicuously displayed at the establishment. Subject to periodic sanitary inspection.",
            'sig1Role' => 'City Health Officer', 'sig1Office' => 'City Health Office', 'sig2Role' => 'City Mayor',
        ],
        'Fire Safety Inspection Certificate' => [
            'label' => 'Fire Safety Inspection Certificate (FSIC)', 'certTitle' => 'Fire Safety Inspection Certificate',
            'office' => 'Bureau of Fire Protection &bull; City Fire Marshal Office',
            'legalBasis' => "This certification is hereby issued pursuant to the Fire Code of the Philippines (RA 9514) and its Implementing Rules and Regulations, confirming that the establishment named below has passed the required fire safety inspection.",
            'recordLabel' => 'Establishment / Business',
            'clause' => 'Must be conspicuously displayed at the establishment. Valid for the period stated unless sooner revoked.',
            'sig1Role' => 'City Fire Marshal', 'sig1Office' => 'Bureau of Fire Protection', 'sig2Role' => 'City Mayor',
        ],
        'Barangay Clearance' => [
            'label' => 'Barangay Clearance (for Business)', 'certTitle' => 'Barangay Business Clearance',
            'office' => 'Barangay Hall &bull; Office of the Punong Barangay',
            'legalBasis' => "This clearance is hereby issued pursuant to the Local Government Code of 1991 (RA 7160), certifying that the business named below has no derogatory record.",
            'recordLabel' => 'Business / Establishment',
            'clause' => "This clearance is issued for the purpose of securing a Mayor's Permit. Valid for the period stated.",
            'sig1Role' => 'Barangay Secretary', 'sig1Office' => 'Barangay Hall', 'sig2Role' => 'Punong Barangay',
        ],
        'Health Certificates' => [
            'label' => 'Health Certificates (Staff/Boarding House)', 'certTitle' => 'Certificate of Health Compliance',
            'office' => 'City Health Office &bull; Environmental Health Division',
            'legalBasis' => "This certification is hereby issued pursuant to the Sanitation Code and applicable health ordinances of the City of Santiago.",
            'recordLabel' => 'Personnel / Boarding House',
            'clause' => "Issued to the named individual/establishment only. Non-transferable.",
            'sig1Role' => 'City Health Officer', 'sig1Office' => 'City Health Office', 'sig2Role' => 'City Mayor',
        ],
        'Lease Contract' => [
            'label' => 'Lease Contract / Rental Agreement', 'certTitle' => 'Certificate of Lease Contract Registration',
            'office' => 'Office of the City Assessor &bull; Zoning &amp; Rental Compliance Division',
            'legalBasis' => "This certification is hereby issued pursuant to the municipal rental registration and zoning ordinances of the City of Santiago.",
            'recordLabel' => 'Leased Property / Unit',
            'clause' => "This certification covers only the registration of the lease contract. It does not authorize business operations.",
            'sig1Role' => 'Zoning Administrator', 'sig1Office' => "City Assessor's Office", 'sig2Role' => 'City Mayor',
        ],
        'Lease Clearance Certificate' => [
            'label' => 'Lease Clearance Certificate', 'certTitle' => 'Certificate of Lease Clearance',
            'office' => 'Office of the City Assessor &bull; Zoning &amp; Rental Compliance Division',
            'legalBasis' => "This certification is hereby issued pursuant to the municipal rental registration and zoning ordinances of the City of Santiago.",
            'recordLabel' => 'Leased Property / Unit',
            'clause' => "This clearance covers only the registration and zoning compliance of the leased premises.",
            'sig1Role' => 'Zoning Administrator', 'sig1Office' => "City Assessor's Office", 'sig2Role' => 'City Mayor',
        ],
        'Occupancy Compliance Permit' => [
            'label' => 'Occupancy Compliance Permit', 'certTitle' => 'Certificate of Occupancy Compliance',
            'office' => 'Office of the City Engineer &bull; Office of the Building Official',
            'legalBasis' => "This certification is hereby issued pursuant to the National Building Code and applicable municipal ordinances of the City of Santiago.",
            'recordLabel' => 'Property / Premises',
            'clause' => "Must be conspicuously displayed at the premises. Subject to periodic re-inspection.",
            'sig1Role' => 'City Engineer / Building Official', 'sig1Office' => 'Office of the Building Official', 'sig2Role' => 'City Mayor',
        ],
        'Others' => [
            'label' => 'Other Permit / Certification', 'certTitle' => 'Certificate of Permit / Certification',
            'office' => 'Office of the City Mayor &bull; Business Permits &amp; Licensing Office',
            'legalBasis' => "This certification is hereby issued pursuant to applicable municipal ordinances of the City of Santiago.",
            'recordLabel' => 'Business / Establishment',
            'clause' => 'Must be conspicuously displayed at the establishment. Non-transferable.',
            'sig1Role' => 'City Treasurer', 'sig1Office' => 'Collections &amp; Revenue Office', 'sig2Role' => 'City Mayor / BPLO Head',
        ],
    ];
    return $types[$permitType] ?? $types['Business Permit'];
}

function buildPermitCertificateHtml(array $d) {
    $cfg = getPermitTypeConfig($d['permitType']);
    $displayPermitType = !empty($d['customPermitType']) ? $d['customPermitType'] : $cfg['label'];
    $permitTitle = $displayPermitType . ' - ' . $d['bizName'];
    $isPreview = !empty($d['isPreview']);
    $permitNo = $d['permitNo'] ?? '';

    $watermarkHtml = $isPreview ? '<div class="preview-watermark">PREVIEW ONLY<span>Not Yet Official</span></div>' : '';
    $printBtn = $isPreview
        ? '<div class="no-print preview-banner"><i class="fa-solid fa-eye" style="margin-right:8px;"></i>You are previewing this permit.</div>'
        : '<div class="no-print" style="text-align: right; margin-bottom: 20px; max-width: 820px; margin-left: auto; margin-right: auto;"><button onclick="window.print()" style="background: #b91c1c; color: #fff; border: none; padding: 10px 22px; font-size: 14px; font-weight: 700; border-radius: 8px; cursor: pointer;"><i class="fa-solid fa-print" style="margin-right:8px;"></i>Print / Save as PDF</button></div>';
    $permitNoRow = !empty($permitNo) ? '<tr><td style="font-weight:700;">Permit Number</td><td><strong style="font-family:monospace;color:#b91c1c;background:#fee2e2;padding:3px 10px;border-radius:6px;">' . htmlspecialchars($permitNo) . '</strong></td></tr>' : '';

    return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($permitTitle) . '</title>
    <style>
        body { font-family: Georgia, "Times New Roman", Times, serif; background: #f8fafc; color: #0f172a; margin: 0; padding: 20px; }
        .preview-banner { max-width: 820px; margin: 0 auto 18px auto; background: #fff7ed; border: 1px solid #fbbf24; color: #92400e; padding: 10px 18px; border-radius: 10px; font-family: "Inter", Arial, sans-serif; font-size: 13px; text-align: center; }
        .certificate-container { position: relative; border: 10px double #b91c1c; padding: 30px 20px; max-width: 820px; margin: 0 auto; background: #ffffff; box-shadow: 0 14px 44px rgba(0,0,0,0.1); overflow: hidden; }
        .certificate-container::before { content: ""; position: absolute; inset: 10px; border: 1.5px solid #ef4444; pointer-events: none; }
        .preview-watermark { position: absolute; top: 42%; left: 50%; transform: translate(-50%,-50%) rotate(-27deg); font-size: 38px; font-weight: 800; color: rgba(217,119,6,0.16); text-transform: uppercase; letter-spacing: 4px; text-align: center; line-height: 1.15; z-index: 1; white-space: nowrap; }
        .preview-watermark span { display: block; font-size: 14px; letter-spacing: 2px; margin-top: 6px; }
        .header { text-align: center; margin-bottom: 22px; padding-bottom: 18px; border-bottom: 2px solid #b91c1c; position: relative; z-index: 2; }
        .header h3 { margin: 1px 0; font-size: 11px; text-transform: uppercase; font-weight: 500; letter-spacing: 2px; color: #64748b; }
        .header h1 { margin: 6px 0 2px; font-size: 20px; text-transform: uppercase; font-weight: 800; color: #b91c1c; letter-spacing: 1.5px; }
        .header h2 { margin: 2px 0 0; font-size: 12px; text-transform: uppercase; font-weight: 600; color: #d97706; letter-spacing: 1px; }
        .cert-kicker { display: inline-block; margin-top: 16px; font-size: 10px; letter-spacing: 3px; text-transform: uppercase; color: #dc2626; border-top: 1px solid #fecaca; border-bottom: 1px solid #fecaca; padding: 4px 14px; }
        .cert-body { text-align: center; margin: 24px 0; position: relative; z-index: 2; }
        .cert-title { font-size: 16px; font-weight: 800; letter-spacing: 2px; text-transform: uppercase; color: #b91c1c; margin: 16px 0 10px; }
        .cert-body p.lead { font-size: 13px; line-height: 1.75; margin: 0 auto; max-width: 630px; color: #334155; }
        .business-box { background: #f8fafc; border: 1.5px solid #cbd5e1; padding: 16px; margin: 20px 0; border-radius: 8px; text-align: left; position: relative; }
        .business-box::before { content: "OFFICIAL RECORD"; position: absolute; top: -10px; left: 22px; background: #ffffff; padding: 0 10px; font-size: 10px; letter-spacing: 2px; color: #b91c1c; font-weight: 700; }
        .business-box table { width: 100%; font-size: 13px; border-collapse: collapse; }
        .business-box td { padding: 6px 0; border-bottom: 1px dotted #cbd5e1; display: block; width: 100%; color: #0f172a; }
        @media(min-width: 576px) { .certificate-container { padding: 46px 54px 40px; } .business-box td { display: table-cell; } .preview-watermark { font-size: 52px; } .header h1 { font-size: 24px; } }
        .business-box tr:last-child td { border-bottom: none; }
        .validity-strip { display: flex; flex-direction: column; gap: 12px; margin: 16px 0 8px; align-items: center; }
        @media(min-width: 576px) { .validity-strip { flex-direction: row; justify-content: center; gap: 48px; } }
        .validity-strip .lbl { font-size: 10px; text-transform: uppercase; letter-spacing: 1.5px; color: #64748b; }
        .validity-strip .val { font-size: 14px; font-weight: 700; color: #b91c1c; margin-top: 2px; }
        .clause { font-size: 11px; font-style: italic; color: #475569; margin-top: 10px; }
        .sig-area { display: flex; flex-direction: column; margin-top: 34px; gap: 30px; position: relative; z-index: 2; }
        @media(min-width: 576px) { .sig-area { flex-direction: row; justify-content: space-between; align-items: flex-end; } }
        .sig-block { flex: 1; text-align: center; }
        .sig-line { border-top: 1px solid #0f172a; width: 200px; margin: 30px auto 6px auto; }
        .sig-block strong { font-size: 12px; color: #0f172a; }
        .sig-block span { font-size: 10px; color: #64748b; }
        .seal-box { width: 75px; height: 75px; margin: 0 auto 4px; border: 2px dashed #dc2626; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 8px; text-align: center; color: #b91c1c; font-weight: 700; line-height: 1.3; background: #fef2f2; }
        .footer-seal { margin-top: 24px; font-size: 10px; text-align: center; color: #64748b; border-top: 1px dashed #cbd5e1; padding-top: 10px; position: relative; z-index: 2; }
        .footer-seal code { background: #f1f5f9; padding: 2px 6px; border-radius: 4px; word-break: break-all; color: #0f172a; }
        @media print { body { padding: 0; background: #fff; } .no-print { display: none; } }
    </style>
</head>
<body>
    ' . $printBtn . '
    <div class="certificate-container">
        ' . $watermarkHtml . '
        <div class="header">
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
                    <tr><td style="font-weight:700;">' . htmlspecialchars($cfg['recordLabel']) . '</td><td><span style="font-size:15px;font-weight:700;color:#b91c1c;">' . htmlspecialchars($d['bizName']) . '</span></td></tr>
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
// STAFF INFORMATION
// ==========================
$staffName = $_SESSION['fullname'] ?? "Staff";
$staffRole = $_SESSION['role'] ?? "Staff";
$staffId = (int)($_SESSION['user_id'] ?? 0);
$staffInitial = strtoupper(substr(trim($staffName), 0, 1));

$profilePicture = '';
if (tableExists($conn, 'users')) {
    $userProfileQuery = $conn->prepare("SELECT profile_picture FROM users WHERE id = :id LIMIT 1");
    $userProfileQuery->execute([':id' => $staffId]);
    $userProfileData = $userProfileQuery->fetch(PDO::FETCH_ASSOC);
    $profilePicture = $userProfileData['profile_picture'] ?? '';
}

// ==========================
// HANDLE STATUS UPDATE
// ==========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type'])) {
    $rentalId = (int)($_POST['rental_id'] ?? 0);
    $actionType = trim($_POST['action_type']);
    $staffRemarks = trim($_POST['staff_remarks'] ?? '');

    if ($rentalId > 0 && in_array($actionType, ['Approved', 'Rejected'])) {
        try {
            $ownerQuery = $conn->prepare("SELECT user_id, business_name FROM rentals WHERE id = :id LIMIT 1");
            $ownerQuery->execute([':id' => $rentalId]);
            $rentalData = $ownerQuery->fetch(PDO::FETCH_ASSOC);

            if ($rentalData) {
                $ownerUserId = (int)$rentalData['user_id'];
                $bizName = $rentalData['business_name'];

                $updateStmt = $conn->prepare("UPDATE rentals SET status = :status, staff_remarks = :remarks WHERE id = :id");
                $updateStmt->execute([':status' => $actionType, ':remarks' => $staffRemarks, ':id' => $rentalId]);

                if (tableExists($conn, 'notifications')) {
                    $notifTitle = "Rental Application " . $actionType;
                    $notifMsg = "Your rental application for \"{$bizName}\" was " . strtolower($actionType) . " by LGU Staff. Remarks: " . (!empty($staffRemarks) ? $staffRemarks : 'None');
                    $notifStmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, notification_type, related_id, is_read, status) VALUES (:uid, :title, :msg, 'Rental', :rid, FALSE, 'Unread')");
                    $notifStmt->execute([':uid' => $ownerUserId, ':title' => $notifTitle, ':msg' => $notifMsg, ':rid' => $rentalId]);
                }

                $_SESSION['success'] = "Rental application has been successfully " . strtolower($actionType) . " and feedback sent to the owner.";
                header("Location: view_rental.php?id=" . $rentalId);
                exit();
            }
        } catch (Exception $e) {
            $_SESSION['error'] = "Failed to update application status.";
        }
    }
}

// ==========================
// HANDLE PERMIT UPDATE
// ==========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_permit'])) {
    $rentalId = (int)($_POST['rental_id'] ?? 0);
    $businessPermitNo = trim($_POST['business_permit_no'] ?? '');
    $permitExpiry = trim($_POST['permit_expiry'] ?? '');
    $permitStatus = trim($_POST['permit_status'] ?? 'Active');

    if ($rentalId > 0) {
        try {
            $updatePermitStmt = $conn->prepare("UPDATE rentals SET business_permit_no = :permit_no, permit_expiry = :permit_expiry WHERE id = :id");
            $updatePermitStmt->execute([':permit_no' => $businessPermitNo, ':permit_expiry' => !empty($permitExpiry) ? $permitExpiry : null, ':id' => $rentalId]);

            if (tableExists($conn, 'documents')) {
                $infoStmt = $conn->prepare("SELECT r.business_name, r.tracking_no, r.rental_name, r.user_id, u.fullname AS owner_name FROM rentals r LEFT JOIN users u ON r.user_id = u.id WHERE r.id = :rid LIMIT 1");
                $infoStmt->execute([':rid' => $rentalId]);
                $info = $infoStmt->fetch(PDO::FETCH_ASSOC);

                $ownerId = (int)($info['user_id'] ?? 0);
                $bizName = $info['business_name'] ?? $info['rental_name'] ?? 'N/A';
                $trackingNo = $info['tracking_no'] ?? 'N/A';
                $ownerName = $info['owner_name'] ?? 'N/A';

                $prefix = explode('-', $businessPermitNo)[0];
                $typeMap = ['BP' => 'Business Permit', 'SP' => 'Sanitary Permit', 'FSIC' => 'Fire Safety Inspection Certificate', 'BC' => 'Barangay Clearance', 'HC' => 'Health Certificates', 'LC' => 'Lease Contract', 'LCC' => 'Lease Clearance Certificate', 'OCP' => 'Occupancy Compliance Permit'];
                $permitType = $typeMap[$prefix] ?? 'Business Permit';

                $ownerFolderPath = 'user_' . $ownerId;
                
                $storedName = 'permit_' . uniqid('', true) . '.html';
                $fileName = preg_replace('/[^a-zA-Z0-9]/', '_', $permitType) . '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $bizName) . '.html';

                $securityHash = hash('sha256', $rentalId . $bizName . time());
                $issueDate = date('F d, Y');
                $expiryDateFormatted = !empty($permitExpiry) ? date('F d, Y', strtotime($permitExpiry)) : 'N/A';

                $permitHtml = buildPermitCertificateHtml([
                    'trackingNo' => $trackingNo, 'bizName' => $bizName, 'ownerName' => $ownerName,
                    'permitType' => $permitType, 'customPermitType' => '',
                    'issueDate' => $issueDate, 'expiryDateFormatted' => $expiryDateFormatted,
                    'notes' => 'Issued upon verified payment approval.',
                    'securityHash' => $securityHash, 'staffId' => $staffId, 'logo' => 'images.png',
                    'isPreview' => false, 'permitNo' => $businessPermitNo,
                ]);

                // ✅ INALIS ANG file_put_contents() DAHIL SA RENDER EPHEMERAL STORAGE
                // file_put_contents($uploadDir . '/' . $storedName, $permitHtml);

                $checkDocStmt = $conn->prepare("SELECT id FROM documents WHERE rental_id = :rid AND (business_permit_no = :bpno OR type ILIKE '%permit%') LIMIT 1");
                $checkDocStmt->execute([':rid' => $rentalId, ':bpno' => $businessPermitNo]);
                $existingDoc = $checkDocStmt->fetch(PDO::FETCH_ASSOC);

                if ($existingDoc) {
                    // ✅ ISAVE ANG HTML SA DATABASE
                    $updateDocStmt = $conn->prepare("UPDATE documents SET business_permit_no = :permit_no, permit_expiry_date = :permit_expiry, permit_status = :permit_status, stored_name = :stored_name, file_name = :file_name, owner_folder_path = :folder, title = :title, type = :type, permit_html = :permit_html WHERE id = :doc_id");
                    $updateDocStmt->execute([':permit_no' => $businessPermitNo, ':permit_expiry' => !empty($permitExpiry) ? $permitExpiry : null, ':permit_status' => $permitStatus, ':stored_name' => $storedName, ':file_name' => $fileName, ':folder' => $ownerFolderPath, ':title' => $permitType . ' - ' . $bizName, ':type' => 'Permit', ':permit_html' => $permitHtml, ':doc_id' => $existingDoc['id']]);
                } else {
                    // ✅ ISAVE ANG HTML SA DATABASE
                    $insertDocStmt = $conn->prepare("INSERT INTO documents (user_id, rental_id, title, type, business_permit_no, permit_expiry_date, permit_status, owner_folder_path, stored_name, file_name, uploaded_at, permit_html) VALUES (:uid, :rid, :title, :type, :permit_no, :permit_expiry, :permit_status, :folder, :stored_name, :file_name, NOW(), :permit_html)");
                    $insertDocStmt->execute([':uid' => $ownerId, ':rid' => $rentalId, ':title' => $permitType . ' - ' . $bizName, ':type' => 'Permit', ':permit_no' => $businessPermitNo, ':permit_expiry' => !empty($permitExpiry) ? $permitExpiry : null, ':permit_status' => $permitStatus, ':folder' => $ownerFolderPath, ':stored_name' => $storedName, ':file_name' => $fileName, ':permit_html' => $permitHtml]);
                }
            }

            $_SESSION['success'] = "Business permit details have been updated and official document generated successfully!";
            header("Location: view_rental.php?id=" . $rentalId);
            exit();

        } catch (Exception $e) {
            $_SESSION['error'] = "Failed to update permit details: " . safe_error($e);
        }
    }
}

// ==========================
// AVAILABLE PERMIT NUMBERS
// ==========================
$availablePermitNumbers = [];
if (tableExists($conn, 'documents')) {
    $permitNoQuery = $conn->query("SELECT DISTINCT business_permit_no FROM documents WHERE business_permit_no IS NOT NULL AND business_permit_no != '' ORDER BY business_permit_no ASC");
    if ($permitNoQuery) {
        while ($row = $permitNoQuery->fetch(PDO::FETCH_ASSOC)) {
            $availablePermitNumbers[] = $row['business_permit_no'];
        }
    }
}

$suggestedPermitNo = 'BP-' . date('Y') . '-0001';
if (!empty($availablePermitNumbers)) {
    $lastPermit = end($availablePermitNumbers);
    if (preg_match('/BP-\d{4}-(\d+)/', $lastPermit, $matches)) {
        $nextNumber = (int)$matches[1] + 1;
        $suggestedPermitNo = 'BP-' . date('Y') . '-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    }
}

// PENDING RENEWALS
$pendingRenewalsCount = 0;
if (tableExists($conn, 'permit_renewals')) {
    $pendRenewalsQuery = $conn->query("SELECT COUNT(*) FROM permit_renewals WHERE status::text = 'Pending'");
    if ($pendRenewalsQuery) $pendingRenewalsCount = (int) $pendRenewalsQuery->fetchColumn();
}

// FINANCIAL COUNTS
$pendingPayments = 0; $verifiedPayments = 0; $totalPayments = 0;
$pendingRemittances = 0; $totalRemittances = 0;
if (tableExists($conn, 'payments')) {
    $q1 = $conn->query("SELECT COUNT(*) FROM payments WHERE status = 'Pending'");
    if ($q1) $pendingPayments = (int)$q1->fetchColumn();
    $q2 = $conn->query("SELECT COUNT(*) FROM payments WHERE status IN ('Paid', 'Verified')");
    if ($q2) $verifiedPayments = (int)$q2->fetchColumn();
    $q3 = $conn->query("SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE status IN ('Paid', 'Verified')");
    if ($q3) $totalPayments = (float)$q3->fetchColumn();
}
if (tableExists($conn, 'remittances')) {
    $q4 = $conn->query("SELECT COUNT(*) FROM remittances WHERE status = 'Pending'");
    if ($q4) $pendingRemittances = (int)$q4->fetchColumn();
    $q5 = $conn->query("SELECT COALESCE(SUM(total_amount), 0) FROM remittances WHERE status = 'Accepted'");
    if ($q5) $totalRemittances = (float)$q5->fetchColumn();
}

// NOTIFICATIONS
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

if (!isset($_GET['id'])) { header("Location: rentals.php"); exit(); }

$id = (int) $_GET['id'];

$q = $conn->prepare("SELECT r.*, rt.type_name, u.fullname AS owner_name, u.email AS owner_email, u.contact AS owner_contact
    FROM rentals r
    LEFT JOIN rental_types rt ON r.rental_type_id = rt.id
    LEFT JOIN users u ON r.user_id = u.id
    WHERE r.id = :id AND r.is_deleted = FALSE LIMIT 1");
$q->execute([':id' => $id]);
$r = $q->fetch(PDO::FETCH_ASSOC);

if (!$r) {
    $_SESSION['error'] = 'Rental not found.';
    header("Location: rentals.php");
    exit();
}

$businessPermits = [];
if (tableExists($conn, 'documents')) {
    $docQuery = $conn->prepare("SELECT * FROM documents WHERE rental_id = :rid ORDER BY uploaded_at DESC");
    $docQuery->execute([':rid' => $id]);
    $businessPermits = $docQuery->fetchAll(PDO::FETCH_ASSOC);
}

$successMessage = $_SESSION['success'] ?? null;
$errorMessage = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#dc2626">
    <title>Verify Rental Details | RENTRACK LGU</title>

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
        @keyframes pulseBadge { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }

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
            z-index: 2000; 
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
        .page-header p strong { color: var(--primary-red); font-weight: 700; }
        @media (min-width: 768px) {
            .page-header { padding: 22px 26px; margin-bottom: 20px; }
            .page-header h1 { font-size: 22px; }
            .page-header p { font-size: 14px; }
        }

        .alert {
            padding: 12px 16px; border-radius: var(--r-md);
            font-size: 13px; font-weight: 500;
            margin-bottom: 14px; display: flex; align-items: center; gap: 10px;
            border: 1px solid transparent;
        }
        .alert-success { background: var(--ok-bg); color: #065f46; border-color: var(--ok-bd); }
        .alert-success i { color: var(--ok); }
        .alert-danger { background: var(--danger-bg); color: #991b1b; border-color: var(--danger-bd); }
        .alert-danger i { color: var(--danger); }

        .btn-action {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 9px 16px; border-radius: 9px;
            font-size: 13px; font-weight: 600;
            text-decoration: none; cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.18s var(--ease); font-family: inherit;
            white-space: nowrap;
        }
        .btn-action i { font-size: 12px; }
        .btn-back { background: #ffffff; border-color: var(--border-soft); color: var(--text-body); }
        .btn-back:hover { background: var(--bg-subtle); border-color: var(--border-medium); color: var(--text-strong); }
        .btn-approve { background: var(--ok); border-color: var(--ok); color: #ffffff; }
        .btn-approve:hover { background: #047857; }
        .btn-reject { background: var(--primary-red); border-color: var(--primary-red); color: #ffffff; }
        .btn-reject:hover { background: var(--primary-red-dark); }

        .btn-view {
            background: var(--info-bg); border: 1px solid var(--info-bd);
            color: var(--info); padding: 6px 12px; border-radius: 7px;
            font-size: 11.5px; font-weight: 600;
            display: inline-flex; align-items: center; gap: 5px;
            transition: all 0.18s var(--ease); cursor: pointer; white-space: nowrap;
            font-family: inherit;
        }
        .btn-view:hover { background: #dbeafe; }

        .btn-edit {
            background: var(--warn-bg); border: 1px solid var(--warn-bd);
            color: var(--warn); padding: 7px 14px; border-radius: 8px;
            font-size: 12px; font-weight: 600;
            display: inline-flex; align-items: center; gap: 6px;
            transition: all 0.18s var(--ease); cursor: pointer; text-decoration: none;
            font-family: inherit;
        }
        .btn-edit:hover { background: #fde68a; }

        .btn-edit-red {
            background: var(--red-soft); border: 1px solid var(--primary-red-light);
            color: var(--primary-red); padding: 7px 14px; border-radius: 8px;
            font-size: 12px; font-weight: 600;
            display: inline-flex; align-items: center; gap: 6px;
            transition: all 0.18s var(--ease); cursor: pointer; text-decoration: none;
            font-family: inherit;
        }
        .btn-edit-red:hover { background: var(--primary-red); color: #fff; }

        .panel {
            background: #ffffff; border: 1px solid #e2e8f0;
            border-radius: var(--radius); box-shadow: var(--sh-sm);
            overflow: hidden; margin-bottom: 20px;
        }
        .panel-head {
            display: flex; align-items: center; justify-content: space-between;
            padding: 16px 22px; border-bottom: 1px solid #e2e8f0;
            gap: 12px; flex-wrap: wrap;
        }
        .panel-title {
            font-size: 14.5px; font-weight: 700; color: var(--text-primary);
            display: flex; align-items: center; gap: 9px;
        }
        .panel-title i { color: var(--primary-red); font-size: 14px; }
        .panel-head-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .panel-body { padding: 20px 22px; }

        .verif-card { border-color: var(--primary-red-light); background: linear-gradient(180deg, #ffffff 0%, #fffafa 100%); }

        .form-control-custom {
            width: 100%; background: #ffffff; border: 1px solid var(--border-soft);
            border-radius: var(--r-md); padding: 11px 14px;
            color: var(--text-strong); font-size: 13.5px;
            outline: none; transition: all 0.18s var(--ease); font-family: inherit;
        }
        .form-control-custom:focus { border-color: var(--primary-red); box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1); }
        select.form-control-custom {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 12px center; padding-right: 36px;
        }
        .form-label {
            display: block; font-size: 11.5px; font-weight: 700;
            color: var(--text-muted); margin-bottom: 6px;
            text-transform: uppercase; letter-spacing: 0.06em;
        }
        .form-label i { color: var(--primary-red); margin-right: 5px; }

        .info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
        .info-item {
            background: var(--bg-subtle); border: 1px solid var(--border-soft);
            border-radius: var(--r-md); padding: 12px 14px;
        }
        .info-item label {
            display: block; font-size: 10.5px; text-transform: uppercase;
            color: var(--text-muted); font-weight: 700;
            letter-spacing: 0.06em; margin-bottom: 5px;
        }
        .info-item label i { color: var(--primary-red); margin-right: 5px; font-size: 10px; }
        .info-item p { font-size: 13.5px; font-weight: 500; color: var(--text-strong); word-break: break-word; }
        .full-width { grid-column: span 2; }

        .badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 11px; border-radius: 999px;
            font-size: 10.5px; font-weight: 700;
            letter-spacing: 0.04em; text-transform: uppercase;
            white-space: nowrap;
        }
        .badge::before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: currentColor; }
        .badge-warning { background: var(--warn-bg); color: var(--warn); }
        .badge-success { background: var(--ok-bg); color: #047857; }
        .badge-danger { background: var(--danger-bg); color: var(--danger); }
        .badge-dark { background: #f1f5f9; color: #334155; }
        .badge-secondary { background: #f1f5f9; color: var(--text-muted); }

        /* PERMIT LIST */
        .permit-list { width: 100%; display: flex; flex-direction: column; }
        .permit-list-header {
            display: grid;
            grid-template-columns: 2fr 1.2fr 0.8fr 1.5fr 1.5fr 2fr 0.8fr;
            gap: 10px; padding: 12px 14px;
            background: var(--bg-subtle);
            border-bottom: 1px solid var(--border-soft);
            font-size: 10.5px; font-weight: 700;
            color: var(--text-muted); text-transform: uppercase;
            letter-spacing: 0.06em;
        }
        .permit-list-row {
            display: grid;
            grid-template-columns: 2fr 1.2fr 0.8fr 1.5fr 1.5fr 2fr 0.8fr;
            gap: 10px; padding: 14px;
            border-bottom: 1px solid #f1f5f9;
            align-items: center;
            transition: background 0.18s var(--ease);
            font-size: 13px;
        }
        .permit-list-row:hover { background: #fafbfc; }
        .permit-list-row:last-child { border-bottom: none; }
        .permit-cell { min-width: 0; word-wrap: break-word; overflow-wrap: break-word; }
        .title-value { font-weight: 700; color: var(--text-strong); }
        .permit-no-badge {
            font-family: 'JetBrains Mono', monospace;
            font-weight: 600; color: var(--primary-red);
            background: var(--red-soft); padding: 3px 10px;
            border-radius: 6px; font-size: 12px;
            display: inline-block; white-space: nowrap;
            border: 1px solid var(--primary-red-light);
        }
        .permit-date { color: var(--text-muted); font-size: 12px; }
        .permit-expiry { display: flex; flex-direction: column; gap: 4px; align-items: flex-start; }
        .permit-remarks { color: var(--text-muted); font-size: 12px; line-height: 1.4; display: block; }

        .badge-type {
            background: var(--red-soft); color: var(--primary-red);
            border: 1px solid var(--primary-red-light);
            display: inline-flex; align-items: center;
            padding: 4px 10px; border-radius: 999px;
            font-size: 10.5px; font-weight: 700;
            letter-spacing: 0.04em; text-transform: uppercase;
        }
        .badge-type::before { display: none; }

        .empty-state { text-align: center; padding: 50px 20px; color: var(--text-muted); }
        .empty-state i { font-size: 42px; margin-bottom: 12px; display: block; opacity: 0.5; color: var(--text-faint); }
        .empty-state h4 { font-size: 14px; font-weight: 600; color: var(--text-body); margin-bottom: 4px; }
        .empty-state p { font-size: 12.5px; }
        .empty-state small { font-size: 11.5px; margin-top: 6px; display: block; color: var(--text-faint); }

        .footer {
            margin-top: 20px; padding: 16px 0 4px;
            border-top: 1px solid #e2e8f0;
            display: flex; justify-content: space-between; align-items: center;
            font-size: 12px; color: var(--text-muted);
            flex-wrap: wrap; gap: 8px;
        }
        .footer .brand { color: var(--primary-red); font-weight: 700; }

        /* SEARCH MODAL */
        .search-modal-content {
            background: #ffffff !important;
            border: none !important;
            border-radius: var(--r-xl) !important;
            box-shadow: 0 24px 48px -12px rgba(15, 23, 42, 0.18) !important;
            overflow: hidden;
        }
        .search-modal-header {
            padding: 16px 22px; border-bottom: 1px solid var(--border-soft);
            display: flex; align-items: center; justify-content: space-between;
        }
        .search-modal-header h5 {
            font-size: 14.5px; font-weight: 700; color: var(--text-strong);
            display: flex; align-items: center; gap: 9px; margin: 0;
        }
        .search-modal-header h5 i { color: var(--primary-red); }
        .search-modal-body { padding: 22px; }
        .search-input-wrap { position: relative; margin-bottom: 20px; }
        .search-input-wrap i {
            position: absolute; left: 18px; top: 50%; transform: translateY(-50%);
            color: var(--text-faint); font-size: 15px;
        }
        .search-input-wrap input {
            width: 100%; padding: 14px 16px 14px 48px;
            border-radius: 12px; border: 1.5px solid var(--border-soft);
            background: var(--bg-subtle); font-size: 15px;
            outline: none; font-family: inherit;
            transition: all 0.18s var(--ease);
            color: var(--text-strong);
        }
        .search-input-wrap input:focus { border-color: var(--primary-red); background: #ffffff; box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.1); }

        /* DOC PREVIEW OVERLAY */
        .doc-preview-overlay {
            position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
            z-index: 9999; display: flex; align-items: center; justify-content: center;
            padding: 5vh 3vw; opacity: 0; visibility: hidden;
            transition: all 0.3s var(--ease);
        }
        .doc-preview-overlay.active { opacity: 1; visibility: visible; }
        .doc-preview-content {
            width: 100%; max-width: 900px; height: 88vh;
            background: #ffffff; border: 1px solid var(--border-soft);
            border-radius: var(--r-xl);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
            overflow: hidden; display: flex; flex-direction: column;
            transform: translateY(-16px) scale(0.98);
            transition: all 0.3s var(--ease);
        }
        .doc-preview-overlay.active .doc-preview-content { transform: translateY(0) scale(1); }
        .doc-preview-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 20px; border-bottom: 1px solid var(--border-soft);
            background: var(--bg-subtle); gap: 12px; flex-wrap: wrap;
        }
        .doc-preview-header strong {
            font-size: 13.5px; color: var(--text-strong);
            display: flex; align-items: center; gap: 8px;
            word-break: break-word;
        }
        .doc-preview-header strong i { color: var(--primary-red); }
        .doc-preview-header .doc-preview-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .doc-preview-close {
            background: #ffffff; border: 1px solid var(--border-soft);
            color: var(--text-muted); width: 32px; height: 32px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: all 0.2s var(--ease); flex-shrink: 0;
        }
        .doc-preview-close:hover { background: var(--danger-bg); color: var(--danger); border-color: var(--danger-bd); }
        .doc-preview-body {
            flex: 1; background: var(--bg-subtle);
            display: flex; align-items: center; justify-content: center;
            overflow: auto; padding: 20px;
        }
        .doc-preview-body iframe { width: 100%; height: 100%; border: 0; background: #fff; border-radius: 8px; }
        .doc-preview-body img { max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .doc-preview-btn-secondary {
            background: var(--red-soft); border: 1px solid var(--primary-red-light);
            color: var(--primary-red); padding: 6px 12px; border-radius: 8px;
            font-size: 12px; font-weight: 600;
            display: inline-flex; align-items: center; gap: 6px;
            text-decoration: none; cursor: pointer;
            transition: all 0.2s var(--ease); white-space: nowrap;
            font-family: inherit;
        }
        .doc-preview-btn-secondary:hover { background: var(--primary-red-tint); color: var(--primary-red-dark); }

        @media print {
            body * { visibility: hidden; }
            #docPreviewOverlay, #docPreviewOverlay * { visibility: visible; }
            #docPreviewOverlay { position: absolute; left: 0; top: 0; width: 100%; height: 100%; background: #fff; padding: 0; }
            .doc-preview-header, .no-print { display: none !important; }
            .doc-preview-content { border: none; box-shadow: none; height: 100%; max-width: 100%; }
        }

        .modal-content {
            background: #ffffff !important;
            border: none !important;
            border-radius: var(--r-xl) !important;
            box-shadow: 0 24px 48px -12px rgba(15, 23, 42, 0.18) !important;
            overflow: hidden;
        }
        .modal-header {
            border-bottom: 1px solid var(--border-soft) !important;
            padding: 16px 22px;
            background: linear-gradient(135deg, #b91c1c, #991b1b);
            color: #ffffff;
        }
        .modal-title { font-size: 15px !important; font-weight: 700; color: #ffffff; display: flex; align-items: center; gap: 9px; }
        .modal-title i { color: #ffffff; font-size: 14px; }
        .modal-body { padding: 22px; }
        .modal-footer { border-top: 1px solid var(--border-soft) !important; padding: 14px 22px; background: var(--bg-subtle); }

        /* RESPONSIVE */
        @media (max-width: 900px) { .info-grid { grid-template-columns: 1fr; } .full-width { grid-column: span 1; } }

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
            .content { padding: 16px 14px 24px; padding-bottom: calc(24px + var(--safe-bottom)); }
            .page-header { flex-direction: column; align-items: flex-start; gap: 12px; padding: 16px !important; }
            .page-header h1 { font-size: 18px; }
            .page-header p { font-size: 12.5px; }
            .panel-head { padding: 14px 16px; flex-direction: column; align-items: flex-start; }
            .panel-body { padding: 16px; }
            .panel-head-actions { width: 100%; }
            .panel-head-actions .btn-edit, .panel-head-actions .btn-edit-red { flex: 1; justify-content: center; }

            .permit-list { gap: 12px; padding: 12px; }
            .permit-list-header { display: none; }
            .permit-list-row {
                display: flex; flex-direction: column; gap: 8px;
                padding: 14px; background: #ffffff;
                border: 1px solid var(--border-soft);
                border-left: 3px solid var(--primary-red);
                border-radius: var(--r-lg);
                box-shadow: var(--sh-xs);
            }
            .permit-list-row:hover { box-shadow: var(--sh-sm); }
            .permit-cell {
                display: flex; justify-content: space-between; align-items: flex-start;
                gap: 12px; padding: 6px 0;
                border-bottom: 1px dashed #eef1f6;
                text-align: right;
            }
            .permit-cell:last-child { border-bottom: none; padding-bottom: 0; }
            .permit-cell::before {
                content: attr(data-label);
                font-size: 10.5px; font-weight: 700;
                color: var(--text-muted); text-transform: uppercase;
                letter-spacing: 0.05em; flex-shrink: 0;
                text-align: left; padding-top: 2px;
            }
            .permit-cell > * { text-align: right; flex: 1; }
            .title-value { text-align: right; }
            .permit-expiry { align-items: flex-end; }
            .permit-cell[data-label="Action"] { justify-content: flex-end; padding-top: 10px; margin-top: 4px; border-top: 1px solid #f1f5f9; border-bottom: none; }
            .permit-cell[data-label="Action"]::before { display: none; }
            .permit-cell[data-label="Action"] .btn-view { width: 100%; justify-content: center; padding: 10px 14px; font-size: 13px; }

            .footer { flex-direction: column; text-align: center; gap: 6px; font-size: 10.5px; }
            .doc-preview-content { height: 95vh; border-radius: 12px; }
            .doc-preview-header { padding: 10px 14px; flex-direction: column; align-items: flex-start; gap: 8px; }
            .doc-preview-header .doc-preview-actions { width: 100%; justify-content: space-between; }

            .modal-dialog { margin: 0; min-height: 100vh; min-height: 100dvh; align-items: stretch; }
            .modal-content { border-radius: 0 !important; min-height: 100vh; min-height: 100dvh; }
            .modal-header { padding-top: calc(1rem + var(--safe-top)); }
        }

        @media (max-width: 480px) {
            .official-header { padding: 5px 8px; padding-top: calc(5px + var(--safe-top)); }
            .top-navbar { padding: 8px 10px; }
            .page-title { font-size: 13px; }
            .content { padding: 12px 10px 22px; padding-bottom: calc(22px + var(--safe-bottom)); }
            .page-header { padding: 14px 16px; border-radius: 12px; }
            .page-header h1 { font-size: 16px; }
            .panel-body { padding: 14px; }
            .info-item { padding: 10px 12px; }
            .info-item p { font-size: 13px; }
            .btn-action { padding: 8px 12px; font-size: 12px; }
            .doc-preview-content { height: 98vh; }
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
                    <a href="rentals.php">Rental Applications</a>
                    <span class="sep"><i class="fa-solid fa-angle-right"></i></span>
                    <strong>Verify &amp; Review</strong>
                </div>

                <!-- PAGE HEADER -->
                <div class="page-header">
                    <div>
                        <h1><i class="fa-solid fa-clipboard-check"></i> Rental Application Verification</h1>
                        <p>Reviewing record for: <strong><?php echo htmlspecialchars($r['business_name'] ?? 'N/A'); ?></strong></p>
                    </div>
                    <div>
                        <a href="rentals.php" class="btn-action btn-back"><i class="fa-solid fa-arrow-left"></i> Back to Rentals</a>
                    </div>
                </div>

                <?php if ($successMessage): ?>
                    <div class="alert alert-success"><i class="fa-regular fa-circle-check"></i> <?php echo htmlspecialchars($successMessage); ?></div>
                <?php endif; ?>
                <?php if ($errorMessage): ?>
                    <div class="alert alert-danger"><i class="fa-regular fa-circle-xmark"></i> <?php echo htmlspecialchars($errorMessage); ?></div>
                <?php endif; ?>

                <!-- VERIFICATION & FEEDBACK -->
                <div class="panel verif-card">
                    <div class="panel-head">
                        <div class="panel-title">
                            <i class="fa-solid fa-comments"></i> LGU Verification &amp; Remarks (Direct to Owner)
                        </div>
                    </div>
                    <div class="panel-body">
                        <form method="POST" id="verificationForm">
                            <input type="hidden" name="rental_id" value="<?php echo $r['id']; ?>">
                            <div style="margin-bottom: 16px;">
                                <label class="form-label"><i class="fa-solid fa-pen-to-square"></i> Remarks / Feedback to Owner</label>
                                <textarea name="staff_remarks" class="form-control-custom" rows="3" placeholder="e.g., Approved and verified." style="resize:vertical;"><?php echo htmlspecialchars($r['staff_remarks'] ?? ''); ?></textarea>
                            </div>
                            <div style="display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap;">
                                <button type="button" onclick="submitVerification('Rejected')" class="btn-action btn-reject"><i class="fa-solid fa-circle-xmark"></i> Reject &amp; Send Feedback</button>
                                <button type="button" onclick="submitVerification('Approved')" class="btn-action btn-approve"><i class="fa-solid fa-circle-check"></i> Approve Application</button>
                            </div>
                            <input type="hidden" name="action_type" id="actionTypeInput">
                        </form>
                    </div>
                </div>

                <!-- BUSINESS PERMITS -->
                <div class="panel">
                    <div class="panel-head">
                        <div class="panel-title"><i class="fa-solid fa-file-shield"></i> Business Permits &amp; Certificates</div>
                        <div class="panel-head-actions">
                            <a href="documents.php?rental_id=<?php echo $r['id']; ?>" class="btn-edit-red">
                                <i class="fa-solid fa-plus-circle"></i> Issue New Permit
                            </a>
                            <button type="button" class="btn-edit" onclick="openEditModal()">
                                <i class="fa-solid fa-pen-to-square"></i> Edit Permit Details
                            </button>
                        </div>
                    </div>

                    <?php if (empty($businessPermits)): ?>
                        <div class="empty-state">
                            <i class="fa-regular fa-folder-open"></i>
                            <h4>No business permits found</h4>
                            <p>No permits or documents have been issued for this rental yet.</p>
                            <small>Use <strong>"Edit Permit Details"</strong> or <strong>"Issue New Permit"</strong> to generate one.</small>
                        </div>
                    <?php else: ?>
                        <div class="permit-list">
                            <div class="permit-list-header">
                                <div>Title</div>
                                <div>Permit No.</div>
                                <div>Type</div>
                                <div>Date Issued</div>
                                <div>Permit Expiry</div>
                                <div>Remarks</div>
                                <div>Action</div>
                            </div>
                            <?php foreach ($businessPermits as $doc): ?>
                                <div class="permit-list-row">
                                    <div class="permit-cell" data-label="Title">
                                        <span class="title-value"><?php echo htmlspecialchars($doc['title'] ?? 'Untitled'); ?></span>
                                    </div>
                                    <div class="permit-cell" data-label="Permit No.">
                                        <?php if (!empty($doc['business_permit_no'])): ?>
                                            <span class="permit-no-badge"><?php echo htmlspecialchars($doc['business_permit_no']); ?></span>
                                        <?php else: ?>
                                            <span style="color:#94a3b8; font-style: italic; font-size: 11px;">—</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="permit-cell" data-label="Type">
                                        <span class="badge-type"><?php echo htmlspecialchars($doc['type'] ?? 'Other'); ?></span>
                                    </div>
                                    <div class="permit-cell" data-label="Date Issued">
                                        <span class="permit-date"><?php echo date("M d, Y g:i A", strtotime($doc['uploaded_at'] ?? 'now')); ?></span>
                                    </div>
                                    <div class="permit-cell" data-label="Permit Expiry">
                                        <?php if (!empty($doc['permit_expiry_date'])):
                                            $isExpired = ($doc['permit_expiry_date'] < date('Y-m-d'));
                                        ?>
                                            <div class="permit-expiry">
                                                <span style="font-size: 12px; font-weight: 600; color: <?php echo $isExpired ? 'var(--danger)' : '#047857'; ?>;"><?php echo date('M d, Y', strtotime($doc['permit_expiry_date'])); ?></span>
                                                <span class="badge <?php echo $isExpired ? 'badge-danger' : 'badge-success'; ?>" style="font-size: 9px;"><?php echo $isExpired ? 'Expired' : 'Active'; ?></span>
                                            </div>
                                        <?php else: ?>
                                            <span style="color:#94a3b8; font-size:11px;">—</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="permit-cell" data-label="Remarks">
                                        <span class="permit-remarks"><?php echo htmlspecialchars($doc['notes'] ?? '—'); ?></span>
                                    </div>
                                    <div class="permit-cell" data-label="Action">
                                        <?php 
                                            $docUrl = '';
                                            // ✅ CHECK IF IT'S A PERMIT OR HAS HTML IN DB
                                            if (($doc['type'] ?? '') === 'Permit' || !empty($doc['permit_html'])) {
                                                $docUrl = 'view_permit.php?id=' . $doc['id'];
                                            } elseif (!empty($doc['stored_name'])) {
                                                $docFolder = $doc['owner_folder_path'] ?? ('user_' . ($doc['user_id'] ?? $staffId));
                                                $docUrl = '../uploads/' . $docFolder . '/' . $doc['stored_name'];
                                            }
                                        ?>
                                        <?php if (!empty($docUrl)): ?>
                                            <button type="button" class="btn-view view-doc-btn"
                                                    data-title="<?php echo htmlspecialchars($doc['title'] ?? 'Document View'); ?>"
                                                    data-url="<?php echo htmlspecialchars($docUrl); ?>">
                                                <i class="fa-regular fa-eye"></i> View
                                            </button>
                                        <?php else: ?>
                                            <span style="color:#94a3b8; font-size:11.5px; font-style:italic;">No file</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- BUSINESS PERMIT INFO -->
                <div class="panel">
                    <div class="panel-head">
                        <div class="panel-title"><i class="fa-solid fa-id-card"></i> Business Permit Information</div>
                        <div class="panel-head-actions">
                            <button type="button" class="btn-edit" onclick="openEditModal()">
                                <i class="fa-solid fa-pen-to-square"></i> Edit Permit Details
                            </button>
                        </div>
                    </div>
                    <div class="panel-body">
                        <div class="info-grid">
                            <div class="info-item">
                                <label><i class="fa-solid fa-id-card"></i> Business Permit No.</label>
                                <p style="color:var(--primary-red); font-weight:700;"><?php echo !empty($r['business_permit_no']) ? htmlspecialchars($r['business_permit_no']) : '<span style="color:#94a3b8; font-weight:400;">Not yet issued</span>'; ?></p>
                            </div>
                            <div class="info-item">
                                <label><i class="fa-solid fa-calendar-xmark"></i> Permit Expiry</label>
                                <p>
                                    <?php if (!empty($r['permit_expiry'])):
                                        $expiryDate = strtotime($r['permit_expiry']);
                                        $isExpired = $expiryDate < time();
                                        $cls = $isExpired ? 'badge-danger' : 'badge-success';
                                    ?>
                                        <span class="badge <?php echo $cls; ?>"><?php echo $isExpired ? 'Expired' : 'Active'; ?></span>
                                        <br><small style="color:#64748b; font-size: 11.5px;">Until <?php echo date("M d, Y", $expiryDate); ?></small>
                                    <?php else: ?>
                                        <span style="color:#94a3b8; font-weight:400;">Not yet issued</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- GENERAL INFO -->
                <div class="panel">
                    <div class="panel-head">
                        <div class="panel-title"><i class="fa-solid fa-info-circle"></i> General Information</div>
                    </div>
                    <div class="panel-body">
                        <div class="info-grid">
                            <div class="info-item"><label><i class="fa-solid fa-hashtag"></i> Tracking No.</label><p style="color:var(--primary-red); font-weight:700; font-family: 'JetBrains Mono', monospace;"><?php echo htmlspecialchars($r['tracking_no'] ?? 'N/A'); ?></p></div>
                            <div class="info-item"><label><i class="fa-solid fa-tag"></i> Rental Type</label><p><?php echo htmlspecialchars($r['type_name'] ?? 'N/A'); ?></p></div>
                            <div class="info-item"><label><i class="fa-solid fa-store"></i> Rental Name</label><p><?php echo htmlspecialchars($r['rental_name'] ?? 'N/A'); ?></p></div>
                            <div class="info-item"><label><i class="fa-solid fa-building"></i> Business Name</label><p><?php echo htmlspecialchars($r['business_name'] ?? 'N/A'); ?></p></div>
                            <div class="info-item full-width"><label><i class="fa-solid fa-location-dot"></i> Address</label><p><?php echo htmlspecialchars($r['business_address'] ?? 'N/A'); ?></p></div>
                            <div class="info-item"><label><i class="fa-solid fa-map-pin"></i> Barangay</label><p><?php echo htmlspecialchars($r['barangay'] ?? 'N/A'); ?></p></div>
                            <div class="info-item"><label><i class="fa-solid fa-phone"></i> Contact Number</label><p><?php echo htmlspecialchars($r['contact_number'] ?? 'N/A'); ?></p></div>
                            <div class="info-item"><label><i class="fa-solid fa-envelope"></i> Email Address</label><p><?php echo htmlspecialchars($r['email'] ?? 'N/A'); ?></p></div>
                            <div class="info-item"><label><i class="fa-solid fa-peso-sign"></i> Monthly Rate</label><p>₱ <?php echo number_format((float)($r['monthly_rate'] ?? 0), 2); ?></p></div>
                            <div class="info-item"><label><i class="fa-solid fa-ruler-combined"></i> Floor / Land Area</label><p><?php echo htmlspecialchars($r['floor_area'] ?? '0'); ?> sqm</p></div>
                            <div class="info-item"><label><i class="fa-solid fa-calendar-days"></i> Registration Date</label><p><?php echo !empty($r['registration_date']) ? date("M d, Y", strtotime($r['registration_date'])) : 'N/A'; ?></p></div>
                            <div class="info-item"><label><i class="fa-solid fa-shield-halved"></i> Application Status</label><p><?php $st = $r['status'] ?? 'Pending'; $cls = 'badge-secondary'; if ($st === 'Pending') $cls = 'badge-warning'; elseif ($st === 'Approved') $cls = 'badge-success'; elseif ($st === 'Rejected') $cls = 'badge-danger'; ?><span class="badge <?php echo $cls; ?>"><?php echo htmlspecialchars($st); ?></span></p></div>
                            <div class="info-item"><label><i class="fa-solid fa-clipboard-check"></i> Compliance Status</label><p><?php
                                $comp = $r['compliance_status'] ?? 'Pending';
                                if ($comp === 'Compliant') { $compCls = 'badge-success'; }
                                elseif ($comp === 'Non-Compliant') { $compCls = 'badge-danger'; }
                                elseif ($comp === 'Expired') { $compCls = 'badge-secondary'; }
                                else { $compCls = 'badge-warning'; }
                            ?><span class="badge <?php echo $compCls; ?>"><?php echo htmlspecialchars($comp); ?></span></p></div>
                            <div class="info-item full-width"><label><i class="fa-solid fa-align-left"></i> Description / Notes</label><p style="font-weight: 400; color: var(--text-body);"><?php echo nl2br(htmlspecialchars($r['description'] ?? 'No description provided.')); ?></p></div>
                        </div>
                    </div>
                </div>

                <!-- OWNER INFO -->
                <div class="panel">
                    <div class="panel-head">
                        <div class="panel-title"><i class="fa-solid fa-user-tie"></i> Owner Information</div>
                    </div>
                    <div class="panel-body">
                        <div class="info-grid">
                            <div class="info-item"><label><i class="fa-solid fa-user"></i> Owner Full Name</label><p><?php echo htmlspecialchars($r['owner_name'] ?? 'N/A'); ?></p></div>
                            <div class="info-item"><label><i class="fa-solid fa-envelope"></i> Owner Email</label><p><?php echo htmlspecialchars($r['owner_email'] ?? 'N/A'); ?></p></div>
                            <div class="info-item full-width"><label><i class="fa-solid fa-phone"></i> Owner Contact</label><p><?php echo htmlspecialchars($r['owner_contact'] ?? 'N/A'); ?></p></div>
                        </div>
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
            <div class="modal-content search-modal-content">
                <div class="search-modal-header">
                    <h5 id="searchModalLabel">
                        <i class="fa-solid fa-magnifying-glass"></i> Global System Search
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="search-modal-body">
                    <div class="search-input-wrap">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="modalSearchInput" placeholder="Search by tracking number, business name, or owner..." autocomplete="off">
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

    <!-- EDIT PERMIT MODAL -->
    <div class="modal fade" id="editPermitModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fa-solid fa-pen-to-square"></i> Edit Business Permit Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="editPermitForm">
                    <input type="hidden" name="update_permit" value="1">
                    <input type="hidden" name="rental_id" value="<?php echo $r['id']; ?>">
                    <div class="modal-body">
                        <div style="margin-bottom: 16px;">
                            <label class="form-label"><i class="fa-solid fa-id-card"></i> Business Permit No. <span style="color:var(--primary-red);">*</span></label>
                            <select name="business_permit_no" class="form-control-custom" required>
                                <option value="">-- Select a Permit Number --</option>
                                <?php if (!empty($availablePermitNumbers)): ?>
                                    <?php foreach ($availablePermitNumbers as $permitNo): ?>
                                        <option value="<?php echo htmlspecialchars($permitNo); ?>" <?php echo (($r['business_permit_no'] ?? '') === $permitNo) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($permitNo); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <option value="<?php echo htmlspecialchars($suggestedPermitNo); ?>">
                                    + Create New: <?php echo htmlspecialchars($suggestedPermitNo); ?>
                                </option>
                            </select>
                            <small style="color:var(--text-faint); font-size: 11px; display: block; margin-top: 6px;">Select an existing permit number or create a new one.</small>
                        </div>
                        <div style="margin-bottom: 16px;">
                            <label class="form-label"><i class="fa-solid fa-calendar-xmark"></i> Permit Expiry Date <span style="color:var(--primary-red);">*</span></label>
                            <input type="date" name="permit_expiry" class="form-control-custom" value="<?php echo !empty($r['permit_expiry']) ? date('Y-m-d', strtotime($r['permit_expiry'])) : ''; ?>" required>
                            <small style="color:var(--text-faint); font-size: 11px; display: block; margin-top: 6px;">Typically 1 year from the payment date.</small>
                        </div>
                        <div>
                            <label class="form-label"><i class="fa-solid fa-shield-halved"></i> Permit Status</label>
                            <select name="permit_status" class="form-control-custom">
                                <option value="Active" <?php echo (($r['permit_status'] ?? '') === 'Active') ? 'selected' : ''; ?>>Active</option>
                                <option value="Pending" <?php echo (($r['permit_status'] ?? '') === 'Pending') ? 'selected' : ''; ?>>Pending</option>
                                <option value="Expired" <?php echo (($r['permit_status'] ?? '') === 'Expired') ? 'selected' : ''; ?>>Expired</option>
                                <option value="Rejected" <?php echo (($r['permit_status'] ?? '') === 'Rejected') ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer" style="justify-content: flex-end; gap: 8px;">
                        <button type="button" class="btn-action btn-back" data-bs-dismiss="modal"><i class="fa-solid fa-xmark"></i> Cancel</button>
                        <button type="submit" class="btn-action btn-approve"><i class="fa-solid fa-floppy-disk"></i> Save Permit Details</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- DOCUMENT PREVIEW MODAL -->
    <div class="doc-preview-overlay" id="docPreviewOverlay" onclick="closeDocPreviewOnOutside(event)">
        <div class="doc-preview-content">
            <div class="doc-preview-header">
                <strong id="docPreviewTitle"><i class="fa-regular fa-file-lines"></i> Document Preview</strong>
                <div class="doc-preview-actions">
                    <button type="button" class="doc-preview-btn-secondary" onclick="window.print()">
                        <i class="fa-solid fa-print"></i> Print
                    </button>
                    <a href="#" id="docPreviewOpenNewTab" target="_blank" class="doc-preview-btn-secondary">
                        <i class="fa-solid fa-up-right-from-square"></i> Open in New Tab
                    </a>
                    <div class="doc-preview-close" onclick="closeDocPreview()" title="Close"><i class="fa-solid fa-xmark"></i></div>
                </div>
            </div>
            <div class="doc-preview-body" id="docPreviewBodyContainer">
                <iframe id="docPreviewFrame" src="about:blank" title="Document Preview" style="display:none;"></iframe>
                <img id="docPreviewImage" src="" alt="Document Image" style="display:none;">
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
                fetch('view_rental.php?ajax_action=read_notif')
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

        searchModalElement?.addEventListener('shown.bs.modal', function () {
            modalSearchInput.focus();
        });

        let searchDebounce;
        modalSearchInput?.addEventListener('input', function() {
            clearTimeout(searchDebounce);
            let query = this.value.trim();
            if (query.length < 2) {
                modalSearchResults.innerHTML = `<div style="text-align: center; padding: 40px 20px; color: #64748b; font-size: 13px;"><i class="fa-regular fa-keyboard" style="font-size: 28px; margin-bottom: 10px; display: block; color: #94a3b8;"></i>Type at least 2 characters to begin searching records...</div>`;
                return;
            }
            searchDebounce = setTimeout(() => {
                fetch('view_rental.php?ajax_action=search&q=' + encodeURIComponent(query))
                    .then(res => res.text())
                    .then(html => {
                        modalSearchResults.innerHTML = html.trim() !== '' ? html : `<div style="text-align: center; padding: 30px; color: #64748b; font-size: 13px;"><i class="fa-regular fa-folder-open" style="font-size: 24px; margin-bottom: 8px; display: block; opacity: 0.5;"></i> No matching results found.</div>`;
                    })
                    .catch(() => {
                        modalSearchResults.innerHTML = `<div style="text-align: center; padding: 30px; color: #b91c1c; font-size: 13px;">Search failed. Please try again.</div>`;
                    });
            }, 250);
        });

        /* EDIT PERMIT MODAL */
        const editPermitModal = new bootstrap.Modal(document.getElementById('editPermitModal'));
        function openEditModal() { editPermitModal.show(); }

        /* VERIFICATION */
        function submitVerification(action) {
            const actionText = action === 'Approved' ? 'approve' : 'reject';
            Swal.fire({
                title: `Are you sure you want to ${actionText} this application?`,
                text: `This will update the status and notify the property owner.`,
                icon: action === 'Approved' ? 'question' : 'warning',
                showCancelButton: true,
                confirmButtonColor: action === 'Approved' ? '#059669' : '#dc2626',
                cancelButtonColor: '#64748b',
                confirmButtonText: `Yes, ${actionText}`,
                background: '#ffffff', color: '#0f172a'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('actionTypeInput').value = action;
                    document.getElementById('verificationForm').submit();
                }
            });
        }

        /* LOGOUT */
        function confirmLogout() {
            Swal.fire({
                title: 'Sign Out?', text: 'Are you sure you want to log out?', icon: 'warning',
                showCancelButton: true, confirmButtonColor: '#dc2626', cancelButtonColor: '#64748b',
                confirmButtonText: 'Yes, Sign Out', background: '#ffffff', color: '#0f172a'
            }).then((res) => { if (res.isConfirmed) document.getElementById('logoutForm').submit(); });
        }
        document.getElementById('profileLogoutBtn')?.addEventListener('click', (e) => { e.preventDefault(); confirmLogout(); });

        /* DOC PREVIEW */
        const docPreviewOverlay = document.getElementById('docPreviewOverlay');
        const docPreviewFrame = document.getElementById('docPreviewFrame');
        const docPreviewImage = document.getElementById('docPreviewImage');
        const docPreviewTitle = document.getElementById('docPreviewTitle');
        const docPreviewOpenNewTab = document.getElementById('docPreviewOpenNewTab');

        function openDocPreview(title, url) {
            docPreviewTitle.innerHTML = '<i class="fa-regular fa-file-lines"></i> ' + title;
            docPreviewOpenNewTab.href = url;

            const ext = url.split('.').pop().toLowerCase().split('?')[0];
            const isHtmlPermit = url.includes('view_permit.php');

            if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
                docPreviewImage.src = url;
                docPreviewImage.style.display = 'block';
                docPreviewFrame.style.display = 'none';
                docPreviewFrame.src = 'about:blank';
            } else if (ext === 'pdf' || isHtmlPermit) {
                docPreviewFrame.src = url;
                docPreviewFrame.style.display = 'block';
                docPreviewImage.style.display = 'none';
                docPreviewImage.src = '';
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
            if (event.target === docPreviewOverlay) closeDocPreview();
        }

        document.querySelectorAll('.view-doc-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                openDocPreview(this.getAttribute('data-title'), this.getAttribute('data-url'));
            });
        });
    </script>
</body>
</html>