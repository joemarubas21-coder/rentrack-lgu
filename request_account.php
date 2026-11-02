<?php
header('Content-Type: application/json');

require_once 'config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit();
}

$fullname       = trim($_POST['fullname'] ?? '');
$email          = trim($_POST['email'] ?? '');
$employee_id    = trim($_POST['employee_id'] ?? '');
$requested_role = trim($_POST['requested_role'] ?? '');

if (empty($fullname) || empty($email) || empty($employee_id) || empty($requested_role)) {
    echo json_encode(['status' => 'error', 'message' => 'Please fill in all required fields.']);
    exit();
}

try {
    // 1. Insert request to account_requests table
    $stmt = $conn->prepare("
        INSERT INTO account_requests (fullname, email, employee_id, requested_role, status)
        VALUES (:fullname, :email, :employee_id, :requested_role, 'Pending')
    ");

    $stmt->execute([
        ':fullname'       => $fullname,
        ':email'          => $email,
        ':employee_id'    => $employee_id,
        ':requested_role' => $requested_role
    ]);

    // 2. Fetch Admin User IDs to send notification
    $adminStmt = $conn->prepare("SELECT id FROM users WHERE role::text ILIKE 'admin'");
    $adminStmt->execute();
    $adminIds = $adminStmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($adminIds)) {
        // Check if target_url column exists in notifications table
        $colCheck = $conn->prepare("
            SELECT EXISTS (
                SELECT 1 
                FROM information_schema.columns 
                WHERE table_schema = 'public' 
                AND table_name = 'notifications' 
                AND column_name = 'target_url'
            )
        ");
        $colCheck->execute();
        $hasTargetUrl = (bool) $colCheck->fetchColumn();

        $title   = "New Account Request";
        $message = "{$fullname} ({$employee_id}) requested an account for role: " . ucfirst($requested_role);
        $url     = "account_requests.php";

        if ($hasTargetUrl) {
            $notifStmt = $conn->prepare("
                INSERT INTO notifications (user_id, title, message, target_url)
                VALUES (:user_id, :title, :message, :target_url)
            ");
            foreach ($adminIds as $adminId) {
                $notifStmt->execute([
                    ':user_id'    => $adminId,
                    ':title'      => $title,
                    ':message'    => $message,
                    ':target_url' => $url
                ]);
            }
        } else {
            $notifStmt = $conn->prepare("
                INSERT INTO notifications (user_id, title, message)
                VALUES (:user_id, :title, :message)
            ");
            foreach ($adminIds as $adminId) {
                $notifStmt->execute([
                    ':user_id' => $adminId,
                    ':title'   => $title,
                    ':message' => $message
                ]);
            }
        }
    }

    echo json_encode(['status' => 'success', 'message' => 'Request submitted successfully!']);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . safe_error($e)]);
}