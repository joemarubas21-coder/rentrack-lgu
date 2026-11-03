<?php
// check_availability.php
header('Content-Type: application/json');

require_once 'config/db.php';

$type = $_GET['type'] ?? '';
$value = trim($_GET['value'] ?? '');
// Kukunin din natin ang username para ma-check kung parehong tao lang ang may-ari ng email
$current_username = trim($_GET['username'] ?? '');

if (empty($type) || empty($value)) {
    echo json_encode(['status' => 'empty']);
    exit;
}

try {
    if ($type === 'username') {
        $stmt = $conn->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(:value) LIMIT 1");
        $stmt->execute([':value' => $value]);

        if ($stmt->fetch()) {
            echo json_encode(['status' => 'taken', 'message' => '✗ Ang username na ito ay taken na.']);
        } else {
            echo json_encode(['status' => 'available', 'message' => '✓ Pwedeng gamitin ang username na ito.']);
        }
    } elseif ($type === 'email') {
        // Titingnan natin kung existing ang email
        $stmt = $conn->prepare("SELECT id, username FROM users WHERE LOWER(email) = LOWER(:value) LIMIT 1");
        $stmt->execute([':value' => $value]);
        $existing_user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing_user) {
            // KUNG EXISTING ANG EMAIL, titingnan natin kung pareho ba sa kasalukuyang username na tinatype
            // Kung pareho, ibig sabihin siya lang din ang may-ari (nag-uupdate lang), kaya i-allow natin.
            if (!empty($current_username) && strtolower($existing_user['username']) === strtolower($current_username)) {
                echo json_encode(['status' => 'available', 'message' => '✓ Valid at pwedeng gamitin ang email (update mode).']);
            } else {
                echo json_encode(['status' => 'taken', 'message' => '✗ Ang email na ito ay nakatala na sa ibang username.']);
            }
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