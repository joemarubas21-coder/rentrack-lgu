<?php
// Handles Approve / Reject for reschedule requests (called via fetch from reschedule_requests.php)
require_once __DIR__ . '/../config/session.php'; app_session_start();
require_once '../config/db.php';
require_once 'includes/auth.php';

header('Content-Type: text/plain; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'error';
    exit;
}

verify_csrf_token();

$id     = (int) ($_POST['id'] ?? 0);
$status = (string) ($_POST['status'] ?? '');

if ($id <= 0 || !in_array($status, ['Approved', 'Rejected'], true)) {
    http_response_code(400);
    echo 'error';
    exit;
}

try {
    $stmt = $conn->prepare("UPDATE reschedule_requests SET status = :status WHERE id = :id AND status = 'Pending'");
    $stmt->execute([':status' => $status, ':id' => $id]);
    echo $stmt->rowCount() > 0 ? 'success' : 'error';
} catch (PDOException $e) {
    error_log('update_reschedule_status: ' . $e->getMessage());
    echo 'error';
}
