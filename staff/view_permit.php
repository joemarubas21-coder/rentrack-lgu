<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();
require_once '../config/db.php';
require_once 'includes/auth.php'; // Siguraduhing naka-login ang staff

$id = (int)($_GET['id'] ?? 0);

if ($id > 0) {
    $stmt = $conn->prepare("SELECT permit_html FROM documents WHERE id = :id AND type = 'Permit'");
    $stmt->execute([':id' => $id]);
    $html = $stmt->fetchColumn();

    if ($html) {
        // Ipapakita ang HTML certificate nang direkta
        echo $html;
        exit;
    }
}

// Fallback kung wala sa DB
http_response_code(404);
echo "<h1>404 Not Found</h1><p>Permit not found or has been deleted.</p>";
?>