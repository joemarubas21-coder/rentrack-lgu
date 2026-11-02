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

// Kunin ang mga datos mula sa form
$fullname = trim($_POST['fullname'] ?? '');
$email = trim($_POST['email'] ?? '');
$contact = trim($_POST['contact_number'] ?? '');

// Simpleng validation
if (empty($fullname) || empty($email)) {
    $_SESSION['flash_message'] = 'error';
    $_SESSION['flash_text'] = 'Please fill in the required fields.';
    header("Location: settings.php");
    exit();
}

try {
    // Update sa database gamit ang PDO prepared statement
    $stmt = $conn->prepare("UPDATE users SET fullname = :fullname, email = :email, contact_number = :contact WHERE id = :id");
    
    $success = $stmt->execute([
        ':fullname' => $fullname,
        ':email'    => $email,
        ':contact'  => $contact,
        ':id'       => $userId
    ]);
    
    if ($success) {
        // Update ang session name para mag-reflect agad sa topbar
        $_SESSION['fullname'] = $fullname;
        
        $_SESSION['flash_message'] = 'success';
        $_SESSION['flash_text'] = 'Profile updated successfully!';
    } else {
        $_SESSION['flash_message'] = 'error';
        $_SESSION['flash_text'] = 'Failed to update profile. Please try again.';
    }
} catch (PDOException $e) {
    $_SESSION['flash_message'] = 'error';
    $_SESSION['flash_text'] = 'Database error.';
}

// Balik sa settings page
header("Location: settings.php");
exit();
?>