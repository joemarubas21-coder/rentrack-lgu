<?php

// ======================================
// STAFF AUTHENTICATION
// RENTRACK LGU
// ======================================

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../../config/session.php'; app_session_start();
}

// Check kung naka-login
if (!isset($_SESSION['user_id'])) {

    header("Location: ../login.php");
    exit();

}

// Check kung may role
if (!isset($_SESSION['role'])) {

    session_destroy();

    header("Location: ../login.php");
    exit();

}

// Staff lang ang pwedeng pumasok
if ($_SESSION['role'] != "staff") {

    switch ($_SESSION['role']) {

        case "admin":
            header("Location: ../admin/dashboard.php");
            break;

        case "owner":
            header("Location: ../owner/dashboard.php");
            break;

        case "inspector":
            header("Location: ../inspector/dashboard.php");
            break;

        default:
            session_destroy();
            header("Location: ../login.php");
            break;
    }

    exit();

}

// Check kung inactive ang account
if (isset($_SESSION['status'])) {

    if ($_SESSION['status'] != "Active") {

        session_destroy();

        header("Location: ../login.php?inactive=1");
        exit();

    }

}
