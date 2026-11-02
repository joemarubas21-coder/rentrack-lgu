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
$isWalkin = false;

try {
    // Check kung walk-in entry ito na kailangan pa munang kumpletuhin (PDO Safe)
    $checkStmt = $conn->prepare("SELECT pending_walkin FROM rentals WHERE id = :id LIMIT 1");
    $checkStmt->execute([':id' => $id]);
    $row = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($row) {
        $isWalkin = (bool) $row['pending_walkin'];
    }

    // Update rental status gamit ang PDO prepared statement
    $updateStmt = $conn->prepare("UPDATE rentals SET is_deleted = '0', pending_walkin = '0' WHERE id = :id");
    
    if ($updateStmt->execute([':id' => $id])) {
        if ($isWalkin) {
            $_SESSION['success'] = 'Walk-in establishment verified. Please complete the remaining details below (owner, rental type, address, etc.) before it becomes an official rental.';
            header("Location: edit_rental.php?id={$id}");
            exit();
        }
        $_SESSION['success'] = 'Rental restored successfully.';
    } else {
        $_SESSION['error'] = 'Failed to restore rental.';
    }
} catch (PDOException $e) {
    error_log('restore_rental: ' . $e->getMessage());
    $_SESSION['error'] = 'Database error. Please try again.';
}

header("Location: archive_rentals.php");
exit();
?>