<?php
require_once __DIR__ . '/../config/session.php'; app_session_start();

require_once '../config/db.php';
require_once 'includes/auth.php';

// ==========================
// STAFF INFORMATION
// ==========================

$staffName = $_SESSION['fullname'] ?? "Staff";
$staffId = $_SESSION['user_id'] ?? 0;

// ==========================
// CHECK IF ID EXISTS
// ==========================

if(!isset($_GET['id'])){
    $_SESSION['error'] = 'No rental ID provided.';
    header("Location: rentals.php");
    exit();
}

$id = (int) $_GET['id'];

if (
    !isset($_GET['csrf_token']) ||
    !isset($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])
) {
    $_SESSION['error'] = 'Invalid or expired request. Please try again.';
    header("Location: rentals.php");
    exit();
}

// ==========================
// CHECK IF COLUMNS EXIST
// ==========================

$hasArchived = false;
$hasActivityTable = false;
$hasActionColumn = false;
$hasDescriptionColumn = false;
$hasUserIdColumn = false;
$hasCreatedAtColumn = false;

try {
    // Check if is_archived column exists in rentals table
    $stmt = $conn->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = 'rentals' AND column_name = 'is_archived'");
    $stmt->execute();
    $hasArchived = $stmt->rowCount() > 0;

    // Check if activity_logs table exists
    $stmtTable = $conn->prepare("SELECT table_name FROM information_schema.tables WHERE table_name = 'activity_logs'");
    $stmtTable->execute();
    $hasActivityTable = $stmtTable->rowCount() > 0;

    // Check columns in activity_logs if table exists
    if ($hasActivityTable) {
        $stmtCols = $conn->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = 'activity_logs'");
        $stmtCols->execute();
        $columns = $stmtCols->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($columns as $col) {
            $colName = $col['column_name'] ?? $col['COLUMN_NAME'] ?? '';
            if ($colName === 'action') $hasActionColumn = true;
            if ($colName === 'description') $hasDescriptionColumn = true;
            if ($colName === 'user_id') $hasUserIdColumn = true;
            if ($colName === 'created_at') $hasCreatedAtColumn = true;
        }
    }
} catch (PDOException $e) {
    // Fallback kung sakaling MySQL ang gamit (gamitin ang DESCRIBE)
    try {
        $checkArchived = $conn->query("DESCRIBE rentals");
        while ($row = $checkArchived->fetch(PDO::FETCH_ASSOC)) {
            $field = $row['Field'] ?? $row['field'] ?? '';
            if ($field === 'is_archived') $hasArchived = true;
        }
    } catch (Exception $ex) {}
    
    try {
        $checkActivityTable = $conn->query("DESCRIBE activity_logs");
        if ($checkActivityTable) {
            $hasActivityTable = true;
            while ($col = $checkActivityTable->fetch(PDO::FETCH_ASSOC)) {
                $colName = $col['Field'] ?? $col['field'] ?? '';
                if ($colName === 'action') $hasActionColumn = true;
                if ($colName === 'description') $hasDescriptionColumn = true;
                if ($colName === 'user_id') $hasUserIdColumn = true;
                if ($colName === 'created_at') $hasCreatedAtColumn = true;
            }
        }
    } catch (Exception $ex) {}
}

// ==========================
// CHECK IF RENTAL EXISTS
// ==========================

$whereClause = "is_deleted = '0'";
if ($hasArchived) {
    $whereClause .= " AND is_archived = '0'";
}

$stmtCheck = $conn->prepare("SELECT id, business_name, tracking_no FROM rentals WHERE id = ? AND $whereClause LIMIT 1");
$stmtCheck->execute([$id]);

if($stmtCheck->rowCount() == 0){
    $_SESSION['error'] = 'Rental not found or already archived.';
    header("Location: rentals.php");
    exit();
}

$rental = $stmtCheck->fetch(PDO::FETCH_ASSOC);

// ==========================
// ARCHIVE THE RENTAL (Soft Delete)
// ==========================

// Build update query dynamically based on available columns
$updateFields = [];
if ($hasArchived) {
    $updateFields[] = "is_archived = '1'";
}
$updateFields[] = "archived_at = NOW()";
$updateFields[] = "archived_by = ?";

$updateSql = "UPDATE rentals SET " . implode(", ", $updateFields) . " WHERE id = ?";
$stmtUpdate = $conn->prepare($updateSql);
$update = $stmtUpdate->execute([$staffId, $id]);

if($update){
    // ==========================
    // LOG THE ACTIVITY (if table exists)
    // ==========================
    if ($hasActivityTable) {
        $logMsg = "Archived rental: {$rental['business_name']} (Tracking: {$rental['tracking_no']})";
        
        // Build insert query based on available columns
        $insertFields = [];
        $insertPlaceholders = [];
        $insertValues = [];
        
        if ($hasUserIdColumn) {
            $insertFields[] = "user_id";
            $insertPlaceholders[] = "?";
            $insertValues[] = $staffId;
        }
        
        if ($hasActionColumn) {
            $insertFields[] = "action";
            $insertPlaceholders[] = "?";
            $insertValues[] = 'Archive Rental';
        }
        
        if ($hasDescriptionColumn) {
            $insertFields[] = "description";
            $insertPlaceholders[] = "?";
            $insertValues[] = $logMsg;
        }
        
        if ($hasCreatedAtColumn) {
            $insertFields[] = "created_at";
            $insertPlaceholders[] = "NOW()";
        }
        
        if (!empty($insertFields)) {
            $insertSql = "INSERT INTO activity_logs (" . implode(", ", $insertFields) . ") VALUES (" . implode(", ", $insertPlaceholders) . ")";
            $stmtLog = $conn->prepare($insertSql);
            $stmtLog->execute($insertValues);
        }
    }
    
    $_SESSION['success'] = 'Rental application archived successfully.';
} else {
    $_SESSION['error'] = 'Failed to archive rental. Please try again.';
}

header("Location: rentals.php");
exit();
?>