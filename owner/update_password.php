<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

if ($_SESSION['role'] != "owner") {
    header("Location: ../login.php");
    exit();
}

require_once("../config/db.php");

$userId = (int) ($_SESSION['user_id'] ?? 0);

// Kunin ang mga input
$current = $_POST['current_password'] ?? '';
$new = $_POST['new_password'] ?? '';
$confirm = $_POST['confirm_password'] ?? '';

// Simpleng validation
if (empty($current) || empty($new) || empty($confirm)) {
    $_SESSION['flash_message'] = 'error';
    $_SESSION['flash_text'] = 'All password fields are required.';
    header("Location: settings.php");
    exit();
}

if ($new !== $confirm) {
    $_SESSION['flash_message'] = 'error';
    $_SESSION['flash_text'] = 'New password and confirmation do not match.';
    header("Location: settings.php");
    exit();
}

if (strlen($new) < 6) {
    $_SESSION['flash_message'] = 'error';
    $_SESSION['flash_text'] = 'Password must be at least 6 characters long.';
    header("Location: settings.php");
    exit();
}

try {
    // Kunin ang kasalukuyang password hash mula sa database gamit ang PDO
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($current, $user['password'])) {
        $_SESSION['flash_message'] = 'error';
        $_SESSION['flash_text'] = 'Current password is incorrect.';
        header("Location: settings.php");
        exit();
    }

    // I-hash ang bagong password at i-save gamit ang PDO
    $newHash = password_hash($new, PASSWORD_DEFAULT);
    $updateStmt = $conn->prepare("UPDATE users SET password = :pwd WHERE id = :id");
    
    if ($updateStmt->execute([':pwd' => $newHash, ':id' => $userId])) {
        $_SESSION['flash_message'] = 'success';
        $_SESSION['flash_text'] = 'Password changed successfully!';
    } else {
        $_SESSION['flash_message'] = 'error';
        $_SESSION['flash_text'] = 'Failed to update password.';
    }
} catch (PDOException $e) {
    $_SESSION['flash_message'] = 'error';
    $_SESSION['flash_text'] = 'Database error.';
}

// Balik sa settings page
header("Location: settings.php");
exit();
?>