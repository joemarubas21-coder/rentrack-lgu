<?php
// chat_backend.php
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../config/session.php'; app_session_start();
}

// Set response header to JSON
header('Content-Type: application/json; charset=utf-8');

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$action = $_GET['action'] ?? '';

/* Helper Function for PostgreSQL Table Check */
function tableExists($conn, $tableName) {
    try {
        $stmt = $conn->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :t)");
        $stmt->execute([':t' => strtolower($tableName)]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) { return false; }
}

if ($action === 'send') {
    $receiverId = (int)($_POST['receiver_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    
    if ($currentUserId > 0 && $receiverId > 0 && !empty($message)) {
        if (tableExists($conn, 'chat_messages')) {
            try {
                $sql = "INSERT INTO chat_messages (sender_id, receiver_id, message) VALUES (:sender_id, :receiver_id, :message)";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':sender_id' => $currentUserId,
                    ':receiver_id' => $receiverId,
                    ':message' => $message
                ]);
                echo json_encode(['status' => 'success']);
            } catch (PDOException $e) {
                echo json_encode(['status' => 'error', 'msg' => safe_error($e)]);
            }
        } else {
            echo json_encode(['status' => 'error', 'msg' => 'Table chat_messages does not exist.']);
        }
    } else {
        echo json_encode(['status' => 'error', 'msg' => 'Invalid parameters.']);
    }
    exit;
}

if ($action === 'fetch') {
    $chatPartnerId = (int)($_GET['partner_id'] ?? 0);
    if ($currentUserId > 0 && $chatPartnerId > 0) {
        $messages = [];
        if (tableExists($conn, 'chat_messages')) {
            $sql = "SELECT * FROM chat_messages 
                    WHERE (sender_id = :uid1 AND receiver_id = :pid1) 
                       OR (sender_id = :pid2 AND receiver_id = :uid2) 
                    ORDER BY created_at ASC";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':uid1' => $currentUserId,
                ':pid1' => $chatPartnerId,
                ':pid2' => $chatPartnerId,
                ':uid2' => $currentUserId
            ]);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $messages[] = [
                    'is_outgoing' => ((int)$row['sender_id'] === $currentUserId),
                    'message' => $row['message'],
                    'time' => date('h:i A', strtotime($row['created_at']))
                ];
            }
        }
        echo json_encode(['status' => 'success', 'messages' => $messages]);
    } else {
        echo json_encode(['status' => 'error', 'msg' => 'Invalid parameters.']);
    }
    exit;
}
?>