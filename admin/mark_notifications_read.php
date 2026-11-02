<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();

require_once '../config/db.php';
require_once 'includes/auth.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit("Unauthorized");
}

$userId = (int) $_SESSION['user_id'];

// UPDATE lang ang notifications ng logged-in user na ADMIN
// Sinigurado natin na admin lang ang makakapag-mark ng read
if ($_SESSION['role'] === 'admin') {
    try {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = :uid AND is_read = FALSE");
        $stmt->execute([':uid' => $userId]);
        echo "success";
    } catch (PDOException $e) {
        error_log('mark_notifications_read: ' . $e->getMessage());
        http_response_code(500);
        echo "error";
    }
} else {
    http_response_code(403);
    echo "forbidden";
}
?>