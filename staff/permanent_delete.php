<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();
require_once '../config/db.php';
require_once 'includes/auth.php';

$staffId = $_SESSION['user_id'] ?? 0;

// ==========================
// CHECK IF ID EXISTS
// ==========================

if (!isset($_GET['id'])) {
    $_SESSION['error'] = 'No rental ID provided.';
    header("Location: archived.php");
    exit();
}

$id = (int) $_GET['id'];

if (
    !isset($_GET['csrf_token']) ||
    !isset($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])
) {
    $_SESSION['error'] = 'Invalid or expired request. Please try again.';
    header("Location: archived.php");
    exit();
}

try {
    // ==========================
    // CHECK IF RENTAL EXISTS
    // ==========================

    $checkStmt = $conn->prepare("SELECT id, business_name, tracking_no FROM rentals WHERE id = :id LIMIT 1");
    $checkStmt->execute([':id' => $id]);
    $rental = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$rental) {
        $_SESSION['error'] = 'Rental not found or already deleted.';
        header("Location: archived.php");
        exit();
    }

    // ==========================
    // PERMANENTLY DELETE (Set is_deleted = TRUE for PostgreSQL)
    // ==========================

    $deleteStmt = $conn->prepare("UPDATE rentals SET is_deleted = TRUE, deleted_at = NOW(), deleted_by = :staff_id WHERE id = :id");
    $deleted = $deleteStmt->execute([
        ':staff_id' => $staffId,
        ':id'       => $id
    ]);

    if ($deleted) {
        // ==========================
        // LOG THE ACTIVITY (Optional check)
        // ==========================
        try {
            $tableStmt = $conn->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'activity_logs')");
            $tableStmt->execute();
            
            if ($tableStmt->fetchColumn()) {
                $logMsg = "Permanently deleted rental: {$rental['business_name']} (Tracking: {$rental['tracking_no']})";
                
                $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, description, created_at) VALUES (:user_id, :action, :description, NOW())");
                $logStmt->execute([
                    ':user_id'     => $staffId,
                    ':action'      => 'Permanent Delete Rental',
                    ':description' => $logMsg
                ]);
            }
        } catch (Exception $ex) {
            // Huwag pansinin kung may kulang na columns sa activity logs para tuloy-tuloy pa rin ang delete
        }
        
        $_SESSION['success'] = 'Rental permanently deleted.';
    } else {
        $_SESSION['error'] = 'Failed to delete rental. Please try again.';
    }

} catch (PDOException $e) {
    $_SESSION['error'] = 'Database error: ' . safe_error($e);
}

header("Location: archived.php");
exit();
?>