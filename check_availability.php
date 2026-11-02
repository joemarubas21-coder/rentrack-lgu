<?php
// check_availability.php
header('Content-Type: application/json');

require_once 'config/db.php';

$type = $_GET['type'] ?? '';
$value = trim($_GET['value'] ?? '');

if (empty($type) || empty($value)) {
    echo json_encode(['status' => 'empty']);
    exit;
}

try {
    if ($type === 'username') {
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = :value LIMIT 1");
        $stmt->execute([':value' => $value]);

        if ($stmt->fetch()) {
            echo json_encode(['status' => 'taken', 'message' => '✗ Ang username na ito ay taken na.']);
        } else {
            echo json_encode(['status' => 'available', 'message' => '✓ Pwedeng gamitin ang username na ito.']);
        }
    } elseif ($type === 'email') {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = :value LIMIT 1");
        $stmt->execute([':value' => $value]);

        if ($stmt->fetch()) {
            echo json_encode(['status' => 'taken', 'message' => '✗ Ang email na ito ay nakatala na.']);
        } else {
            echo json_encode(['status' => 'available', 'message' => '✓ Valid at pwedeng gamitin ang email.']);
        }
    } else {
        echo json_encode(['status' => 'invalid']);
    }
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
}
?>