<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();
require_once "includes/auth.php";
require_once "../config/db.php";

verify_csrf_get();

if (!isset($_GET['id'])) {
    header("Location: archive_rentals.php");
    exit();
}

$id = (int) $_GET['id'];

try {
    // I-delete muna ang related inspections para maiwasan ang orphaned records gamit ang PDO prepared statement
    $delInsStmt = $conn->prepare("DELETE FROM inspections WHERE rental_id = :id");
    $delInsStmt->execute([':id' => $id]);

    // Permanent delete ng rental gamit ang PDO[cite: 19]
    $delRentalStmt = $conn->prepare("DELETE FROM rentals WHERE id = :id AND is_deleted = '1'");
    
    if ($delRentalStmt->execute([':id' => $id])) {
        $_SESSION['success'] = 'Rental permanently deleted.';
    } else {
        $_SESSION['error'] = 'Failed to delete rental.';
    }
} catch (PDOException $e) {
    error_log('permanent_delete_rental: ' . $e->getMessage());
    $_SESSION['error'] = 'Database error. Please try again.';
}

header("Location: archive_rentals.php");
exit();
?>