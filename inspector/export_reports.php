<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();
require_once "includes/auth.php";
require_once "../config/db.php";

$inspectorId = (int) ($_SESSION['user_id'] ?? 0);
$resultFilter = trim($_GET['result'] ?? '');

$sql = "
    SELECT i.inspection_no, i.inspection_date, i.inspection_type, i.inspection_status,
           i.permit_displayed, i.permit_matches, i.inspector_notes,
           r.rental_name, r.business_name, r.tracking_no, r.barangay,
           u.fullname AS owner_name
    FROM inspections i
    INNER JOIN rentals r ON i.rental_id = r.id
    LEFT JOIN users u ON r.user_id = u.id
    WHERE i.inspector_id = :inspector_id
    AND (i.inspection_status = 'Passed' OR i.inspection_status = 'Failed')
";
$params = [':inspector_id' => $inspectorId];

if ($resultFilter === 'Passed' || $resultFilter === 'Failed') {
    $sql .= " AND i.inspection_status = :result_filter";
    $params[':result_filter'] = $resultFilter;
}
$sql .= " ORDER BY i.inspection_date DESC";

try {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $filename = 'inspection_reports_' . date('Y-m-d') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM so Excel renders special characters correctly
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, [
        'Inspection No', 'Property', 'Owner', 'Barangay', 'Tracking No.',
        'Date', 'Type', 'Result', 'Permit Displayed', 'Permit Matches', 'Notes',
    ]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $row['inspection_no'] ?? '',
            $row['rental_name'] ?? $row['business_name'] ?? '',
            $row['owner_name'] ?? '',
            $row['barangay'] ?? '',
            $row['tracking_no'] ?? '',
            !empty($row['inspection_date']) ? date('Y-m-d', strtotime($row['inspection_date'])) : '',
            $row['inspection_type'] ?? '',
            $row['inspection_status'] ?? '',
            $row['permit_displayed'] ?? 'N/A',
            $row['permit_matches'] ?? 'N/A',
            $row['inspector_notes'] ?? '',
        ]);
    }

    fclose($out);
} catch (PDOException $e) {
    // Handle error kung kinakailangan
    error_log("CSV Export Error: " . $e->getMessage());
}

exit();
?>