<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();
require_once "includes/auth.php";
require_once "../config/db.php";

if (!isset($_GET['id'])) {
    die("No rental ID specified.");
}

$id = (int) $_GET['id'];

try {
    // Kunin ang rental at user details gamit ang PDO prepared statement[cite: 19]
    $stmt = $conn->prepare("
        SELECT r.*, rt.type_name, u.fullname AS owner_name, u.email AS owner_email, u.contact AS owner_contact
        FROM rentals r
        LEFT JOIN rental_types rt ON r.rental_type_id = rt.id
        LEFT JOIN users u ON r.user_id = u.id
        WHERE r.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$r) {
        die("Rental record not found.");
    }
} catch (PDOException $e) {
    die("Database error: " . safe_error($e));
}

$permitNo = 'LGU-' . date('Y') . '-' . str_pad($r['id'], 5, '0', STR_PAD_LEFT);
$issueDate = date('F d, Y');
$expiryDate = !empty($r['permit_expiry']) ? date('F d, Y', strtotime($r['permit_expiry'])) : 'N/A';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Business Permit - <?php echo htmlspecialchars($r['business_name'] ?? ''); ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
    * { box-sizing: border-box; }
    body {
        font-family: 'Georgia', 'Times New Roman', serif;
        background: #eef2f6;
        margin: 0;
        padding: 30px;
    }
    .toolbar {
        max-width: 800px;
        margin: 0 auto 20px;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
    }
    .toolbar button, .toolbar a {
        padding: 10px 18px;
        border-radius: 8px;
        border: none;
        cursor: pointer;
        font-weight: 600;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .btn-print { background: #0d3b66; color: #fff; }
    .btn-close { background: #e7edf5; color: #0d3b66; }

    .permit {
        max-width: 800px;
        margin: 0 auto;
        background: #fff;
        border: 3px double #0d3b66;
        padding: 50px 60px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    }
    .permit-header {
        text-align: center;
        border-bottom: 2px solid #0d3b66;
        padding-bottom: 20px;
        margin-bottom: 30px;
    }
    .permit-header .logo-line {
        font-size: 14px;
        letter-spacing: 2px;
        color: #666;
        text-transform: uppercase;
    }
    .permit-header h1 {
        margin: 8px 0;
        color: #0d3b66;
        font-size: 28px;
        letter-spacing: 1px;
    }
    .permit-header .subtitle {
        color: #555;
        font-size: 14px;
    }
    .permit-title {
        text-align: center;
        margin: 30px 0;
    }
    .permit-title h2 {
        font-size: 22px;
        color: #333;
        text-transform: uppercase;
        letter-spacing: 2px;
        margin: 0;
    }
    .permit-no {
        text-align: center;
        color: #b8860b;
        font-weight: bold;
        margin-bottom: 30px;
        font-size: 15px;
    }
    .permit-body p.lead {
        text-align: center;
        font-size: 15px;
        line-height: 1.8;
        color: #333;
        margin-bottom: 30px;
    }
    .business-name {
        text-align: center;
        font-size: 24px;
        font-weight: bold;
        color: #0d3b66;
        margin: 20px 0;
        border-bottom: 1px solid #ccc;
        padding-bottom: 20px;
    }
    .details-table {
        width: 100%;
        border-collapse: collapse;
        margin: 30px 0;
    }
    .details-table td {
        padding: 8px 4px;
        font-size: 14px;
        vertical-align: top;
    }
    .details-table td.label {
        width: 200px;
        color: #666;
        font-weight: 600;
    }
    .details-table td.value {
        color: #111;
    }
    .status-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: bold;
    }
    .status-approved { background: #d1fae5; color: #065f46; }
    .status-pending { background: #fef3c7; color: #92400e; }
    .status-rejected { background: #fee2e2; color: #991b1b; }

    .signatures {
        display: flex;
        justify-content: space-between;
        margin-top: 70px;
    }
    .sig-block {
        text-align: center;
        width: 45%;
    }
    .sig-line {
        border-top: 1px solid #333;
        margin-bottom: 6px;
        padding-top: 6px;
    }
    .sig-title {
        font-size: 12px;
        color: #666;
    }
    .footer-note {
        margin-top: 40px;
        font-size: 11px;
        color: #999;
        text-align: center;
        border-top: 1px solid #eee;
        padding-top: 14px;
    }

    @media print {
        body { background: #fff; padding: 0; }
        .toolbar { display: none; }
        .permit { box-shadow: none; border: 2px solid #0d3b66; max-width: 100%; }
    }
</style>
</head>
<body>

<div class="toolbar">
    <button class="btn-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    <a href="rentals.php" class="btn-close"><i class="fa-solid fa-xmark"></i> Close</a>
</div>

<div class="permit">

    <div class="permit-header">
        <div class="logo-line">Republic of the Philippines</div>
        <h1>RENTRACK LGU</h1>
        <div class="subtitle">Unified Rental Registration, Tracking and Compliance System</div>
    </div>

    <div class="permit-title">
        <h2>Business / Rental Permit</h2>
    </div>

    <div class="permit-no">Permit No.: <?php echo $permitNo; ?></div>

    <div class="permit-body">
        <p class="lead">
            This is to certify that the establishment named below has been registered
            and is hereby granted permission to operate within the jurisdiction of this
            Local Government Unit, subject to existing rules and regulations.
        </p>

        <div class="business-name">
            <?php echo htmlspecialchars($r['business_name'] ?: $r['rental_name'] ?: 'N/A'); ?>
        </div>

        <table class="details-table">
            <tr>
                <td class="label">Tracking No.</td>
                <td class="value"><?php echo htmlspecialchars($r['tracking_no'] ?? 'N/A'); ?></td>
                <td class="label">Rental Type</td>
                <td class="value"><?php echo htmlspecialchars($r['type_name'] ?? 'N/A'); ?></td>
            </tr>
            <tr>
                <td class="label">Owner / Proprietor</td>
                <td class="value"><?php echo htmlspecialchars($r['owner_name'] ?? 'N/A'); ?></td>
                <td class="label">Contact No.</td>
                <td class="value"><?php echo htmlspecialchars($r['owner_contact'] ?? 'N/A'); ?></td>
            </tr>
            <tr>
                <td class="label">Business Address</td>
                <td class="value" colspan="3"><?php echo htmlspecialchars($r['business_address'] ?? 'N/A'); ?>, Brgy. <?php echo htmlspecialchars($r['barangay'] ?? 'N/A'); ?></td>
            </tr>
            <tr>
                <td class="label">Floor Area</td>
                <td class="value"><?php echo !empty($r['floor_area']) ? htmlspecialchars($r['floor_area']) . ' sqm' : 'N/A'; ?></td>
                <td class="label">Monthly Rate</td>
                <td class="value">&#8369;<?php echo number_format((float)($r['monthly_rate'] ?? 0), 2); ?></td>
            </tr>
            <tr>
                <td class="label">Date Issued</td>
                <td class="value"><?php echo $issueDate; ?></td>
                <td class="label">Valid Until</td>
                <td class="value"><?php echo $expiryDate; ?></td>
            </tr>
            <tr>
                <td class="label">Permit Status</td>
                <td class="value" colspan="3">
                    <?php
                    $statusClass = 'status-pending';
                    if ($r['status'] === 'Approved') $statusClass = 'status-approved';
                    elseif ($r['status'] === 'Rejected') $statusClass = 'status-rejected';
                    ?>
                    <span class="status-badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($r['status'] ?? 'Pending'); ?></span>
                </td>
            </tr>
        </table>

        <div class="signatures">
            <div class="sig-block">
                <div class="sig-line">&nbsp;</div>
                <div class="sig-title">Business Owner Signature</div>
            </div>
            <div class="sig-block">
                <div class="sig-line">&nbsp;</div>
                <div class="sig-title">Authorized LGU Officer</div>
            </div>
        </div>

        <div class="footer-note">
            This permit is system-generated by RENTRACK LGU and is valid only when accompanied by
            official LGU seal and signature. Permit No. <?php echo $permitNo; ?> — Printed on <?php echo date('F d, Y h:i A'); ?>.
        </div>
    </div>
</div>

</body>
</html>