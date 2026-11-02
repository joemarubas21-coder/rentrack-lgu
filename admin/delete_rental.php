<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();
require_once "includes/auth.php";
require_once "../config/db.php";

verify_csrf_get();

if (!isset($_GET['id'])) {
    header("Location: rentals.php");
    exit();
}

$id = (int) $_GET['id'];

// Soft delete - keeps the record but hides it from active listings
try {
    $stmt = $conn->prepare("UPDATE rentals SET is_deleted = '1' WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $_SESSION['success'] = 'Rental record archived.';
} catch (PDOException $e) {
    error_log('delete_rental: ' . $e->getMessage());
    $_SESSION['error'] = 'Failed to archive rental. Please try again.';
}

header("Location: rentals.php");
exit();
