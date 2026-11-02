<?php
require_once __DIR__ . '/session.php'; 

function require_role($allowed_roles) {
    app_session_start();
    
    // 1. Check kung naka-login
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        header("Location: ../login.php?error=unauthorized");
        exit();
    }
    
    // 2. Check kung ang role ay kasama sa allowed
    if (!in_array($_SESSION['role'], $allowed_roles)) {
        // I-redirect sa tamang dashboard base sa role niya
        switch ($_SESSION['role']) {
            case 'admin': header("Location: ../admin/dashboard.php"); break;
            case 'staff': header("Location: ../staff/dashboard.php"); break;
            case 'inspector': header("Location: ../inspector/dashboard.php"); break;
            case 'owner': header("Location: ../owner/dashboard.php"); break;
            default: header("Location: ../login.php"); break;
        }
        exit();
    }
}
?>