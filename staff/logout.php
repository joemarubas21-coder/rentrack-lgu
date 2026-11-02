<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();

// Unset all session variables
$_SESSION = [];

// Destroy session
session_destroy();

// Redirect to login page
header("Location: ../login.php");
exit();
?>
