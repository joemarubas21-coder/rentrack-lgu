<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();

require_once '../config/db.php';
require_once 'includes/auth.php';

// Must be a POST from the Add User form
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['save_user'])) {
    header("Location: users.php");
    exit();
}

verify_csrf_token();

$fullname = trim($_POST['fullname'] ?? '');
$username = trim($_POST['username'] ?? '');
$email    = trim($_POST['email'] ?? '');
$contact  = trim($_POST['contact'] ?? '');
$password = $_POST['password'] ?? '';
$confirm  = $_POST['confirm_password'] ?? '';
$role     = $_POST['role'] ?? '';
$status   = $_POST['status'] ?? 'Active';

if ($fullname === '' || $username === '' || $email === '' || $password === '' || $confirm === '' || $role === '') {
    header("Location: add_user.php?error=required");
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header("Location: add_user.php?error=email");
    exit();
}

if ($password !== $confirm) {
    header("Location: add_user.php?error=password");
    exit();
}

// Same strength rules as the owner reset-password page
if (
    strlen($password) < 8 ||
    !preg_match('/[A-Z]/', $password) ||
    !preg_match('/[a-z]/', $password) ||
    !preg_match('/[0-9]/', $password) ||
    !preg_match('/[^A-Za-z0-9]/', $password)
) {
    header("Location: add_user.php?error=password");
    exit();
}

if (!in_array($role, ['admin', 'owner', 'inspector', 'staff', 'collector'], true)) {
    header("Location: add_user.php?error=role");
    exit();
}

if (!in_array($status, ['Active', 'Inactive'], true)) {
    header("Location: add_user.php?error=status");
    exit();
}

try {
    $stmt = $conn->prepare("SELECT 1 FROM users WHERE username = :u LIMIT 1");
    $stmt->execute([':u' => $username]);
    if ($stmt->fetchColumn()) {
        header("Location: add_user.php?error=username");
        exit();
    }

    $stmt = $conn->prepare("SELECT 1 FROM users WHERE LOWER(email) = LOWER(:e) LIMIT 1");
    $stmt->execute([':e' => $email]);
    if ($stmt->fetchColumn()) {
        header("Location: add_user.php?error=email");
        exit();
    }

    $stmt = $conn->prepare(
        "INSERT INTO users (fullname, username, email, contact, password, role, status)
         VALUES (:fullname, :username, :email, :contact, :password, :role, :status)"
    );
    $stmt->execute([
        ':fullname' => $fullname,
        ':username' => $username,
        ':email'    => $email,
        ':contact'  => $contact,
        ':password' => password_hash($password, PASSWORD_DEFAULT),
        ':role'     => $role,
        ':status'   => $status,
    ]);

    header("Location: users.php?success=1");
    exit();
} catch (PDOException $e) {
    error_log('save_user: ' . $e->getMessage());
    header("Location: add_user.php?error=save");
    exit();
}
