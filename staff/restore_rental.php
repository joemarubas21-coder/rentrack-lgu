<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();
require_once '../config/db.php';
require_once 'includes/auth.php';

$staffId = $_SESSION['user_id'] ?? 0;

// ==========================
// CHECK IF ID EXISTS
// ==========================
if (!isset($_GET['id'])) {
    $_SESSION['error'] = 'No rental ID provided.';
    header("Location: archived.php");
    exit();
}

$id = (int) $_GET['id'];

// ==========================
// CSRF CHECK
// ==========================
if (
    !isset($_GET['csrf_token']) ||
    !isset($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])
) {
    $_SESSION['error'] = 'Invalid or expired request. Please try again.';
    header("Location: archived.php");
    exit();
}

try {
    // ==========================
    // CHECK IF COLUMNS / TABLES EXIST (PostgreSQL-compatible)
    // ==========================

    // Check if rentals.is_archived exists
    $hasArchived = false;
    $colStmt = $conn->prepare("
        SELECT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = 'rentals'
              AND column_name = 'is_archived'
        )
    ");
    $colStmt->execute();
    $hasArchived = (bool) $colStmt->fetchColumn();

    // Check if activity_logs table exists
    $hasActivityTable = false;
    $tableStmt = $conn->prepare("
        SELECT EXISTS (
            SELECT 1 FROM information_schema.tables
            WHERE table_schema = 'public'
              AND table_name = 'activity_logs'
        )
    ");
    $tableStmt->execute();
    $hasActivityTable = (bool) $tableStmt->fetchColumn();

    // Check activity_logs columns
    $hasActionColumn      = false;
    $hasDescriptionColumn = false;
    $hasUserIdColumn      = false;
    $hasCreatedAtColumn   = false;

    if ($hasActivityTable) {
        $actCols = $conn->prepare("
            SELECT column_name
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = 'activity_logs'
        ");
        $actCols->execute();
        while ($col = $actCols->fetch(PDO::FETCH_ASSOC)) {
            $name = strtolower($col['column_name']);
            if ($name === 'action')      $hasActionColumn      = true;
            if ($name === 'description') $hasDescriptionColumn = true;
            if ($name === 'user_id')     $hasUserIdColumn      = true;
            if ($name === 'created_at')  $hasCreatedAtColumn   = true;
        }
    }

    // ==========================
    // CHECK IF RENTAL EXISTS AND IS ARCHIVED
    // ==========================
    $whereClause = "is_deleted = FALSE";
    if ($hasArchived) {
        $whereClause .= " AND is_archived = TRUE";
    }

    $checkStmt = $conn->prepare("
        SELECT id, business_name, tracking_no
        FROM rentals
        WHERE id = :id AND " . $whereClause . "
        LIMIT 1
    ");
    $checkStmt->execute([':id' => $id]);
    $rental = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$rental) {
        $_SESSION['error'] = 'Rental not found or not archived.';
        header("Location: archived.php");
        exit();
    }

    // ==========================
    // RESTORE THE RENTAL
    // ==========================
    $updateStmt = $conn->prepare("
        UPDATE rentals
        SET is_archived = FALSE,
            archived_at = NULL,
            archived_by = NULL
        WHERE id = :id
    ");
    $updated = $updateStmt->execute([':id' => $id]);

    if ($updated) {
        // ==========================
        // LOG THE ACTIVITY
        // ==========================
        if ($hasActivityTable) {
            $logMsg = "Restored rental: {$rental['business_name']} (Tracking: {$rental['tracking_no']})";

            $insertFields       = [];
            $insertPlaceholders = [];
            $params             = [];

            if ($hasUserIdColumn) {
                $insertFields[]       = "user_id";
                $insertPlaceholders[] = ":user_id";
                $params[':user_id']   = $staffId;
            }

            if ($hasActionColumn) {
                $insertFields[]       = "action";
                $insertPlaceholders[] = ":action";
                $params[':action']    = 'Restore Rental';
            }

            if ($hasDescriptionColumn) {
                $insertFields[]          = "description";
                $insertPlaceholders[]    = ":description";
                $params[':description']  = $logMsg;
            }

            if ($hasCreatedAtColumn) {
                $insertFields[]       = "created_at";
                $insertPlaceholders[] = "NOW()";
            }

            if (!empty($insertFields)) {
                $logSql = "INSERT INTO activity_logs (" . implode(", ", $insertFields) . ")
                           VALUES (" . implode(", ", $insertPlaceholders) . ")";
                $logStmt = $conn->prepare($logSql);
                $logStmt->execute($params);
            }
        }

        $_SESSION['success'] = 'Rental application restored successfully.';
    } else {
        $_SESSION['error'] = 'Failed to restore rental. Please try again.';
    }

} catch (PDOException $e) {
    $_SESSION['error'] = 'Database error: ' . safe_error($e);
}

header("Location: archived.php");
exit();
?>