<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();
require_once '../config/db.php';
require_once 'includes/auth.php';

header('Content-Type: text/plain; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id'], $_POST['status'])) {
    http_response_code(405);
    echo 'error';
    exit;
}

verify_csrf_token();

$inspectionId = (int) $_POST['id'];
$newStatus    = trim((string) $_POST['status']);

// Only allow known statuses
$allowed = ['Pending', 'Scheduled', 'In Progress', 'Completed', 'Cancelled', 'Rescheduled'];
if ($inspectionId <= 0 || !in_array($newStatus, $allowed, true)) {
    http_response_code(400);
    echo 'error';
    exit;
}

try {
    $stmt = $conn->prepare("UPDATE inspections SET inspection_status = :status WHERE id = :id");
    $stmt->execute([':status' => $newStatus, ':id' => $inspectionId]);
    echo $stmt->rowCount() > 0 ? 'success' : 'error';
} catch (PDOException $e) {
    error_log('update_inspection_status: ' . $e->getMessage());
    echo 'error';
}
