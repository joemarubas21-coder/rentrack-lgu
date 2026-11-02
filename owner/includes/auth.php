<?php

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../../config/session.php'; app_session_start();
}

if (!isset($_SESSION['user_id'])) {

    header("Location: ../login.php");
    exit();

}

if ($_SESSION['role'] != "owner") {

    header("Location: ../login.php");
    exit();

}
?>