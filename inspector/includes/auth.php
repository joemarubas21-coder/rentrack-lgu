<?php

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../../config/session.php'; app_session_start();
}

// Check if logout is triggered via URL parameter
if (isset($_GET['logout']) && $_GET['logout'] == 'true') {
    // Clear all session variables
    $_SESSION = array();

    // Destroy the session cookie if it exists
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }

    // Destroy the session
    session_destroy();

    // Direktang puntahan ang choose_portal.php sa rentalsystem folder
    header("Location: ../../choose_portal.php"); 
    exit();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../choose_portal.php");
    exit();
}

if ($_SESSION['role'] != "inspector") {
    header("Location: ../../choose_portal.php");
    exit();
}

/* =========================================================
   CSRF PROTECTION HELPERS
   ========================================================= */

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Echoes a hidden CSRF input field. Include inside every <form> that POSTs.
 */
function csrfField()
{
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Validates the CSRF token coming from a POST request.
 * Call this at the top of every POST handler before touching the database.
 */
function csrfCheck()
{
    $token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        die('Invalid or expired form submission (CSRF check failed). Please go back, refresh the page, and try again.');
    }
}
?>