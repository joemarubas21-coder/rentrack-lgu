<?php
// ======================================
// ADMIN AUTHENTICATION
// ======================================

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../../config/session.php'; app_session_start();
}

// Check kung naka-login
if (!isset($_SESSION['user_id'])) {

    header("Location: ../login.php");
    exit();

}

// Check kung Admin ang role
if ($_SESSION['role'] != "admin") {

    header("Location: ../login.php");
    exit();

}


// ======================================
// CSRF TOKEN
// ======================================

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * I-verify ang CSRF token na kasama sa POST request.
 * Kung wala o mali ang token, i-stop agad ang request.
 */
function verify_csrf_token() {

    if (
        !isset($_POST['csrf_token']) ||
        !isset($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {

        http_response_code(403);
        die('Invalid request. Paki-refresh ang page at subukan ulit.');

    }

}

/**
 * CSRF check for link-based (GET) admin actions. The link must carry ?csrf_token=...
 */
function verify_csrf_get() {
    if (
        !isset($_GET['csrf_token']) ||
        !isset($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], (string) $_GET['csrf_token'])
    ) {
        http_response_code(403);
        die('Invalid request. Paki-refresh ang page at subukan ulit.');
    }
}
?>
