<?php

require_once __DIR__ . '/../config/session.php'; app_session_start();

require_once '../config/db.php';
require_once 'includes/auth.php';

// ======================================
// ONLY ALLOW POST REQUESTS
// (Deactivation is a state-changing action,
// dapat hindi ito ma-trigger ng plain GET link)
// ======================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: users.php");
    exit();
}

// ======================================
// VERIFY CSRF TOKEN
// ======================================

verify_csrf_token();

// Check if ID exists
if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {

    header("Location: users.php");
    exit();

}

$id = (int) $_POST['id'];

// Prevent deleting yourself
if ($id == $_SESSION['user_id']) {

    header("Location: users.php?error=self");
    exit();

}

// Check if user exists
$stmt = $conn->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {

    header("Location: users.php");
    exit();

}

// Soft Delete
$stmt = $conn->prepare("UPDATE users SET status = 'Inactive' WHERE id = :id");
$stmt->execute([':id' => $id]);

header("Location: users.php?deleted=1");

exit();

?>