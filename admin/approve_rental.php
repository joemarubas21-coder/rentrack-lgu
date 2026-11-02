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
    // Check kung existing ang rental gamit ang PDO prepared statement
    $checkStmt = $conn->prepare("SELECT r.id, r.user_id, r.business_name, r.tracking_no FROM rentals r WHERE r.id = :id LIMIT 1");
    $checkStmt->execute([':id' => $id]);
    $r = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$r) {
        $_SESSION['error'] = 'Rental not found.';
        header('Location: rentals.php');
        exit();
    }

    // Update rental status gamit ang PDO[cite: 19]
    $updateStmt = $conn->prepare("UPDATE rentals SET status = 'Approved' WHERE id = :id AND status = 'Pending'");
    $updateStmt->execute([':id' => $id]);

    if ($updateStmt->rowCount() > 0) {
        $_SESSION['success'] = 'Rental approved successfully. It is now ready for Staff to schedule an inspection.';

        $title = 'Rental Approved';
        $msg = "Your rental \"{$r['business_name']}\" ({$r['tracking_no']}) has been approved. Staff will now arrange the inspection schedule.";
        $type = 'Rental';

        // Notify Owner gamit ang PDO[cite: 19]
        $notifStmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, notification_type, related_id, is_read, status, created_at) VALUES (:uid, :title, :msg, :type, :rid, 0, 'Unread', NOW())");
        $notifStmt->execute([
            ':uid'   => $r['user_id'],
            ':title' => $title,
            ':msg'   => $msg,
            ':type'  => $type,
            ':rid'   => $id
        ]);

        // Kunin ang mga staff users gamit ang PDO[cite: 19]
        $staffStmt = $conn->prepare("SELECT id FROM users WHERE role = 'staff' AND status = 'Active' AND (is_deleted = '0' OR is_deleted = FALSE)");
        $staffStmt->execute();
        $staffUsers = $staffStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($staffUsers as $st) {
            $t = 'Approved Rental - Schedule Inspection';
            $m = "Approved rental {$r['tracking_no']} - {$r['business_name']} is ready for inspection scheduling.";
            $insType = 'Inspection';

            $staffNotifStmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, notification_type, related_id, is_read, status, created_at) VALUES (:uid, :title, :msg, :type, :rid, 0, 'Unread', NOW())");
            $staffNotifStmt->execute([
                ':uid'   => $st['id'],
                ':title' => $t,
                ':msg'   => $m,
                ':type'  => $insType,
                ':rid'   => $id
            ]);
        }
    } else {
        $_SESSION['error'] = 'Rental could not be approved. It may already be approved or no longer be pending.';
    }
} catch (PDOException $e) {
    error_log('approve_rental: ' . $e->getMessage());
    $_SESSION['error'] = 'Database error. Please try again.';
}

header("Location: rentals.php");
exit();
?>