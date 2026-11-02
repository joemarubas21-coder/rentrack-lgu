<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();
require_once("../config/db.php");
require_once("includes/auth.php");

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
if ($userId === 0) { die('Unauthorized'); }

// ============================================================
// INCLUDE FPDF (HANAPIN NATIN SA LOOB NG fpdf FOLDER)
// ============================================================
require_once('fpdf/fpdf.php');

// ============================================================
// GET DATA PARA SA PDF
// ============================================================
$inspectionId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$exportType = isset($_GET['type']) ? $_GET['type'] : 'all';

$inspections = [];

if ($exportType === 'single' && $inspectionId > 0) {
    $query = "
        SELECT i.*, r.business_name, r.rental_name, r.tracking_no
        FROM inspections i
        INNER JOIN rentals r ON i.rental_id = r.id
        WHERE i.id = ? AND r.user_id = ?
        ORDER BY i.inspection_date DESC
        LIMIT 1
    ";
    $stmt = $conn->prepare(str_replace(['i.id = ?', 'r.user_id = ?'], ['i.id = :iid', 'r.user_id = :uid'], $query));
    $stmt->execute([':iid' => $inspectionId, ':uid' => $userId]);
    $inspections = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $query = "
        SELECT i.*, r.business_name, r.rental_name, r.tracking_no
        FROM inspections i
        INNER JOIN rentals r ON i.rental_id = r.id
        WHERE r.user_id = ? AND r.is_deleted = '0'
        ORDER BY i.inspection_date DESC
    ";
    $stmt = $conn->prepare(str_replace('r.user_id = ?', 'r.user_id = :uid', $query));
    $stmt->execute([':uid' => $userId]);
    $inspections = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (empty($inspections)) { die('No inspections found to export.'); }

// ============================================================
// GENERATE PDF USING FPDF
// ============================================================

class PDF extends FPDF
{
    // Header ng PDF
    function Header()
    {
        // Gamitin natin ang standard na Arial
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 10, 'RENTRACK LGU - Official Inspection Report', 0, 1, 'C');
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, 'Generated on: ' . date('F d, Y h:i A'), 0, 1, 'C');
        $this->Ln(5);
    }
}

$pdf = new PDF();
$pdf->AddPage();

foreach ($inspections as $ins) {
    $status = $ins['inspection_status'] ?? 'Scheduled';
    $date = !empty($ins['inspection_date']) ? date('F d, Y', strtotime($ins['inspection_date'])) : '—';
    $time = !empty($ins['inspection_time']) ? date('g:i A', strtotime($ins['inspection_time'])) : '—';
    $type = ucfirst(str_replace('_', ' ', $ins['inspection_type'] ?? 'Building Inspection'));

    // Gray background for header
    $pdf->SetFillColor(240, 240, 240);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 8, 'Inspection #' . ($ins['inspection_no'] ?? 'N/A'), 0, 1, 'C', true);
    $pdf->Ln(2);

    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(35, 7, 'Property:', 0, 0);
    $pdf->Cell(0, 7, $ins['rental_name'] ?? $ins['business_name'] ?? '—', 0, 1);

    $pdf->Cell(35, 7, 'Tracking No:', 0, 0);
    $pdf->Cell(0, 7, $ins['tracking_no'] ?? 'N/A', 0, 1);

    $pdf->Cell(35, 7, 'Date & Time:', 0, 0);
    $pdf->Cell(0, 7, $date . ' at ' . $time, 0, 1);

    $pdf->Cell(35, 7, 'Status:', 0, 0);
    $pdf->Cell(0, 7, $status, 0, 1);

    $pdf->Cell(35, 7, 'Type:', 0, 0);
    $pdf->Cell(0, 7, $type, 0, 1);

    $pdf->Cell(35, 7, 'Remarks:', 0, 0);
    $pdf->MultiCell(0, 7, $ins['remarks'] ?? '—', 0, 1);

    $pdf->Ln(8);
    $pdf->Line($pdf->GetX(), $pdf->GetY(), $pdf->GetPageWidth() - 10, $pdf->GetY());
    $pdf->Ln(8);
}

$pdf->Output('D', 'Inspections_Report_' . date('Ymd_His') . '.pdf');
exit();
?>