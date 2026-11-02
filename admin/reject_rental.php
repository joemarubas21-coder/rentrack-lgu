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

try {
    // Update rental status gamit ang PDO prepared statement
    $stmt = $conn->prepare("UPDATE rentals SET status = 'Rejected' WHERE id = :id");
    
    if ($stmt->execute([':id' => $id])) {
        $_SESSION['success'] = 'Rental rejected.';
    } else {
        $_SESSION['error'] = 'Failed to reject rental.';
    }
} catch (PDOException $e) {
    error_log('reject_rental: ' . $e->getMessage());
    $_SESSION['error'] = 'Database error. Please try again.';
}

header("Location: rentals.php");
exit();
?>