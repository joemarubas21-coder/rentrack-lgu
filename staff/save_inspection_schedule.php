<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();
date_default_timezone_set('Asia/Manila');
require_once '../config/db.php';
require_once 'includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { 
    header('Location: inspection_schedule.php'); 
    exit(); 
}

if (empty($_SESSION['staff_csrf']) || !hash_equals($_SESSION['staff_csrf'], $_POST['csrf_token'] ?? '')) { 
    $_SESSION['error'] = 'Invalid request. Please refresh and try again.'; 
    header('Location: inspection_schedule.php'); 
    exit(); 
}

$rentalId = (int) ($_POST['rental_id'] ?? 0);
$inspectorId = (int) ($_POST['inspector_id'] ?? 0);
$date = trim($_POST['scheduled_date'] ?? '');
$time = trim($_POST['scheduled_time'] ?? '');
$type = trim($_POST['inspection_type'] ?? 'Initial Inspection');
$notes = trim($_POST['staff_notes'] ?? '');

if (!$rentalId || !$inspectorId || !$date || !$time) { 
    $_SESSION['error'] = 'Please complete the rental, Inspector, date, and time.'; 
    header('Location: inspection_schedule.php'); 
    exit(); 
}

if (strtotime($date . ' ' . $time) < time()) { 
    $_SESSION['error'] = 'Inspection date and time cannot be in the past.'; 
    header('Location: inspection_schedule.php'); 
    exit(); 
}

try {
    // Only approved rentals can be scheduled (PDO Safe)
    $stmt = $conn->prepare("SELECT r.id, r.user_id, r.business_name, r.rental_name, r.tracking_no FROM rentals r WHERE r.id = :rental_id AND r.status = 'Approved' AND (r.is_deleted = '0' OR r.is_deleted IS NULL) LIMIT 1");
    $stmt->execute([':rental_id' => $rentalId]);
    $rental = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rental) { 
        $_SESSION['error'] = 'Only an approved rental can be scheduled for inspection.'; 
        header('Location: inspection_schedule.php'); 
        exit(); 
    }

    // Inspector must be an active Inspector account (PDO Safe)[cite: 20]
    $stmt = $conn->prepare("SELECT id, fullname FROM users WHERE id = :inspector_id AND role = 'inspector' AND status = 'Active' AND is_deleted = '0' LIMIT 1");
    $stmt->execute([':inspector_id' => $inspectorId]);
    $inspector = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$inspector) { 
        $_SESSION['error'] = 'Selected Inspector is not active or does not exist.'; 
        header('Location: inspection_schedule.php'); 
        exit(); 
    }

    // Prevent duplicate active inspections for the same rental (PDO Safe)[cite: 20]
    $stmt = $conn->prepare("SELECT id FROM inspections WHERE rental_id = :rental_id AND inspection_status IN ('Scheduled', 'Ongoing') LIMIT 1");
    $stmt->execute([':rental_id' => $rentalId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) { 
        $_SESSION['error'] = 'This rental already has an active inspection schedule.'; 
        header('Location: inspection_schedule.php'); 
        exit(); 
    }

    // Helper function to check if a column exists in PDO[cite: 20]
    function colExistsPDO($conn, $table, $col) {
        try {
            $stmt = $conn->prepare("SHOW COLUMNS FROM `$table` LIKE :col");
            $stmt->execute([':col' => $col]);
            return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return false;
        }
    }

    $cols = [];
    $vals = [];
    $params = [];

    $add = function($col, $value) use (&$cols, &$vals, &$params, $conn) {
        if (colExistsPDO($conn, 'inspections', $col)) {
            $cols[] = "`$col`";
            $vals[] = ":" . $col;
            $params[":" . $col] = $value;
        }
    };

    $add('rental_id', $rentalId);
    $add('inspector_id', $inspectorId);
    $add('inspection_date', $date);
    $add('scheduled_date', $date);
    $add('scheduled_time', $time);
    $add('inspection_type', $type);
    $add('inspection_status', 'Scheduled');
    $add('inspector_notes', $notes);
    $add('staff_notes', $notes);
    $add('notes', $notes);
    $add('remarks', $notes);
    $add('inspector_name', $inspector['fullname']);
    $add('rental_name', $rental['rental_name'] ?? $rental['business_name']);
    $add('business_name', $rental['business_name']);
    $add('inspection_no', 'INS-' . date('YmdHis') . '-' . str_pad((string)$rentalId, 4, '0', STR_PAD_LEFT));

    if (colExistsPDO($conn, 'inspections', 'created_at')) {
        $cols[] = '`created_at`';
        $vals[] = 'NOW()';
    }

    if (empty($cols)) {
        $_SESSION['error'] = 'The inspections table does not have the required fields.';
        header('Location: inspection_schedule.php');
        exit();
    }

    $sql = 'INSERT INTO inspections (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')';
    $stmt = $conn->prepare($sql);
    
    if (!$stmt->execute($params)) {
        $_SESSION['error'] = 'Unable to save inspection schedule.';
        header('Location: inspection_schedule.php');
        exit();
    }

    $inspectionId = $conn->lastInsertId();

    // Notify Inspector[cite: 20]
    $ownerMsg = "Inspection scheduled for {$rental['business_name']} on " . date('M d, Y', strtotime($date)) . " at " . date('h:i A', strtotime($time)) . ".";
    $title = 'New Inspection Assignment';
    $notifStmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, notification_type, related_id, is_read, status, created_at) VALUES (:uid, :title, :msg, :type, :rid, 0, 'Unread', NOW())");
    $notifStmt->execute([
        ':uid'   => $inspectorId,
        ':title' => $title,
        ':msg'   => $ownerMsg,
        ':type'  => 'Inspection',
        ':rid'   => $inspectionId
    ]);

    // Notify Owner[cite: 20]
    if (!empty($rental['user_id'])) {
        $title2 = 'Inspection Scheduled';
        $msg2 = "Your rental \"{$rental['business_name']}\" has been scheduled for inspection on " . date('M d, Y', strtotime($date)) . " at " . date('h:i A', strtotime($time)) . ". Assigned Inspector: {$inspector['fullname']}.";
        $ownerNotifStmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, notification_type, related_id, is_read, status, created_at) VALUES (:uid, :title, :msg, :type, :rid, 0, 'Unread', NOW())");
        $ownerNotifStmt->execute([
            ':uid'   => $rental['user_id'],
            ':title' => $title2,
            ':msg'   => $msg2,
            ':type'  => 'Inspection',
            ':rid'   => $inspectionId
        ]);
    }

    $_SESSION['success'] = 'Inspection scheduled successfully and the Inspector has been notified.';
    header('Location: inspection_schedule.php');
    exit();

} catch (PDOException $e) {
    $_SESSION['error'] = 'Database error: ' . safe_error($e);
    header('Location: inspection_schedule.php');
    exit();
}
?>