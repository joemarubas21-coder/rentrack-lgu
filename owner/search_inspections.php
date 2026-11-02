<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();
include '../config/db.php'; // <--- Siguraduhing tama ang path papunta sa PDO connection mo!

date_default_timezone_set("Asia/Manila");

// Check login
if (!isset($_SESSION['user_id'])) {
    exit(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

// Check role - dapat owner lang ang makaka-access dito
if (!isset($_SESSION['role']) || $_SESSION['role'] != "owner") {
    exit(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

$user_id = (int) $_SESSION['user_id'];
$search_term = trim($_GET['search'] ?? '');

$results = [];

if (strlen($search_term) >= 2) {
    $searchTermLike = '%' . $search_term . '%';

    // PDO Safe Query na may Prepared Statement para ligtas sa SQL Injection
    $query = "
        SELECT 
            i.id,
            i.inspection_no,
            i.inspection_status,
            i.inspection_result,
            i.inspection_date,
            r.rental_name,
            r.business_name
        FROM inspections i
        INNER JOIN rentals r ON i.rental_id = r.id
        WHERE 
            (i.inspection_no LIKE :s1 OR
             i.inspection_status LIKE :s2 OR
             i.inspection_result LIKE :s3 OR
             r.rental_name LIKE :s4 OR
             r.business_name LIKE :s5)
            AND r.user_id = :uid
            AND (r.is_deleted = '0' OR r.is_deleted = FALSE)
        ORDER BY i.inspection_date DESC
        LIMIT 10
    ";

    try {
        $stmt = $conn->prepare($query);
        $stmt->execute([
            ':s1'  => $searchTermLike,
            ':s2'  => $searchTermLike,
            ':s3'  => $searchTermLike,
            ':s4'  => $searchTermLike,
            ':s5'  => $searchTermLike,
            ':uid' => $user_id
        ]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $results = [];
    }
}

// Ibalik bilang JSON
echo json_encode([
    'success' => true,
    'results' => $results
]);
exit();
?>